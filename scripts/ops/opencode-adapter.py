#!/usr/bin/env python3
"""Casa Viva Control OS OpenCode Adapter v1 (Fase D: adaptador real de CLI).

Adaptador de invocación del editor de IA vía la interfaz CLI documentada
de OpenCode, exclusivamente con la línea:

    opencode run --pure -m opencode/glm-5.3-flash-free --format json

Superficie estricta:
- Comando construido por lista blanca: sin shell, sin `--auto`, sin
  argumentos provenientes del modelo o del paquete; el prompt es un
  único elemento de argv.
- Entorno del hijo por lista de conservación: nunca hereda claves de
  servicio, tokens ni credenciales (el padre nunca consulta rutas de
  configuración del sistema ni datos externos).
- Límites: timeout con muerte del grupo de procesos (SIGKILL al grupo),
  RLIMIT_FSIZE sobre la salida, RLIMIT_CPU, RLIMIT_AS, RLIMIT_NPROC y
  RLIMIT_CORE=0 en el hijo.
- Validación estricta de la salida: JSON Lines, cada línea un objeto con
  `type` string; el texto de respuesta se extrae solo de eventos
  `text` con `part.text` o `text` string. Cualquier desviación es un
  error de protocolo.

ESTADO DE LA INTEGRACIÓN (leer antes de usar):
- `ISOLATION_GUARANTEED = False`: `--pure` solo desactiva plugins
  externos; NO aísla archivos, comandos ni red. Hasta implementar
  restricciones efectivas de permisos (usuario dedicado sin escritura
  fuera del workspace) y un entorno aislado (sandbox sin red, p. ej.
  nsjail/bubblewrap), este adaptador se niega a invocar el binario real
  (AdapterBlocked). No se invoque por fuera de esta vía.
- La integración está desactivada por defecto: exige
  CV_OPENCODE_MODEL_ENABLED=1 y CV_OPENCODE_SANDBOX_ATTESTED=1, y aun
  así el binario real sigue bloqueado por la invariante anterior.
- El ejecutor de Fase B (`opencode-executor.py`) NO usa este adaptador:
  el camino determinista (operaciones estructuradas + smoke) y la
  aprobación humana se conservan intactos.

Las pruebas usan un simulador de CLI; ninguna prueba invoca el modelo
real. La calibración del vocabulario de eventos (`type`) se hará en la
primera invocación real supervisada, nunca en producción.
"""
from __future__ import annotations

import json
import os
import re
import signal
import subprocess
import sys
import tempfile
from pathlib import Path

ADAPTER_VERSION = "opencode-adapter-v1"
DEFAULT_MODEL = "opencode/glm-5.3-flash-free"
ALLOWED_MODELS = {DEFAULT_MODEL}
MODEL_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$")
BINARY_ENV = "CV_OPENCODE_BIN"
ENABLE_ENV = "CV_OPENCODE_MODEL_ENABLED"
SANDBOX_ENV = "CV_OPENCODE_SANDBOX_ATTESTED"

# Aislamiento NO garantizado: --pure no aísla archivos, comandos ni red.
ISOLATION_GUARANTEED = False
BLOCK_REASON = (
    "bloqueado: --pure no aísla archivos, comandos ni red; se requiere "
    "sandbox efectivo (sin red, escritura limitada al workspace) antes de "
    "la primera invocación real del modelo"
)

MAX_PROMPT = 200_000
MAX_OUTPUT_LIMIT = 10_000_000
DEFAULT_MAX_OUTPUT = 1_000_000
DEFAULT_TIMEOUT = 600
MIN_TIMEOUT = 5
MAX_TIMEOUT = 900
MAX_TYPE_LEN = 64
MAX_STDERR_TAIL = 4000

CHILD_CPU_GRACE = 30
CHILD_RLIMIT_AS = 2 * 1024**3
CHILD_RLIMIT_NPROC = 512

# Entorno conservado en el hijo: lista blanca, nunca herencia completa.
KEEP_ENV = (
    "PATH", "HOME", "USER", "LOGNAME", "SHELL",
    "LANG", "LC_ALL", "LC_CTYPE", "TMPDIR", "TERM",
)

EVENT_TEXT_TYPE = "text"
MAX_EVENTS = 10_000


class AdapterError(Exception):
    """Base de errores del adaptador; `.code` es el código de salida CLI."""
    code = 1

    def __init__(self, message: str):
        super().__init__(message[:MAX_STDERR_TAIL])
        self.message = message[:MAX_STDERR_TAIL]


class AdapterUsage(AdapterError):
    code = 2


class AdapterDisabled(AdapterError):
    code = 3


class AdapterBlocked(AdapterError):
    code = 4


class AdapterTimeout(AdapterError):
    code = 5


class AdapterOutputLimit(AdapterError):
    code = 6


class AdapterProtocol(AdapterError):
    code = 7


class AdapterCLI(AdapterError):
    code = 8


def enabled() -> bool:
    """Integración desactivada por defecto: exige los dos interruptores."""
    return os.environ.get(ENABLE_ENV) == "1" and os.environ.get(SANDBOX_ENV) == "1"


def default_binary() -> str:
    return os.environ.get(BINARY_ENV, "opencode")


def validate_prompt(prompt) -> str:
    if not isinstance(prompt, str) or not prompt.strip():
        raise AdapterUsage("prompt ausente o vacío")
    if "\x00" in prompt:
        raise AdapterUsage("prompt con NUL no permitido")
    if len(prompt) > MAX_PROMPT:
        raise AdapterUsage(f"prompt demasiado grande (> {MAX_PROMPT})")
    return prompt


def validate_model(model) -> str:
    if not isinstance(model, str) or not MODEL_RE.match(model) or model not in ALLOWED_MODELS:
        raise AdapterUsage(f"modelo no habilitado: {str(model)[:80]!r}")
    return model


def build_command(prompt: str, model: str, binary: str) -> list[str]:
    """Comando exacto de la interfaz documentada; nunca shell, nunca --auto."""
    validate_prompt(prompt)
    validate_model(model)
    if not isinstance(binary, str) or not binary or "\x00" in binary or len(binary) > 4096:
        raise AdapterUsage("binario inválido")
    return [binary, "run", "--pure", "-m", model, "--format", "json", prompt]


def child_environment() -> dict[str, str]:
    """Entorno por lista de conservación: nunca hereda secretos."""
    env = {key: os.environ[key] for key in KEEP_ENV if key in os.environ}
    if not env.get("PATH"):
        env["PATH"] = "/usr/bin:/bin"
    return env


def _child_limits(timeout: int, max_output: int):
    """Límites de recursos del hijo (preexec). Solo se aplica en el hijo."""

    def apply() -> None:
        import resource

        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_FSIZE, (max_output, max_output))
        resource.setrlimit(resource.RLIMIT_CPU, (timeout + CHILD_CPU_GRACE, timeout + CHILD_CPU_GRACE))
        resource.setrlimit(resource.RLIMIT_AS, (CHILD_RLIMIT_AS, CHILD_RLIMIT_AS))
        resource.setrlimit(resource.RLIMIT_NPROC, (CHILD_RLIMIT_NPROC, CHILD_RLIMIT_NPROC))

    return apply


def _validate_event(event, index: int) -> dict:
    if not isinstance(event, dict):
        raise AdapterProtocol(f"línea {index + 1}: evento no es un objeto")
    kind = event.get("type")
    if not isinstance(kind, str) or not kind or len(kind) > MAX_TYPE_LEN:
        raise AdapterProtocol(f"línea {index + 1}: evento sin type válido")
    if kind == EVENT_TEXT_TYPE:
        part = event.get("part")
        text = part.get("text") if isinstance(part, dict) else None
        if not isinstance(text, str):
            text = event.get("text")
        if not isinstance(text, str):
            raise AdapterProtocol(f"línea {index + 1}: evento text sin texto")
    return event


def parse_output(raw: bytes) -> dict:
    """Validación estricta: JSON Lines, objetos con `type`, texto extraído."""
    try:
        text_out = raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise AdapterProtocol(f"salida no es UTF-8: {exc}")
    events = []
    texts = []
    for index, line in enumerate(text_out.splitlines()):
        line = line.strip()
        if not line:
            continue
        try:
            event = json.loads(line)
        except ValueError as exc:
            raise AdapterProtocol(f"línea {index + 1}: JSON inválido: {exc}")
        _validate_event(event, index)
        events.append(event)
        if event["type"] == EVENT_TEXT_TYPE:
            part = event.get("part")
            value = part.get("text") if isinstance(part, dict) else None
            if not isinstance(value, str):
                value = event.get("text")
            texts.append(value)
    if len(events) > MAX_EVENTS:
        raise AdapterProtocol(f"demasiados eventos (> {MAX_EVENTS})")
    return {"events": events, "text": "\n".join(texts)}


def run_command(
    prompt: str,
    *,
    model: str = DEFAULT_MODEL,
    binary: str | None = None,
    cwd: str | Path,
    runner=None,
    timeout_seconds: int | float = DEFAULT_TIMEOUT,
    max_output_bytes: int = DEFAULT_MAX_OUTPUT,
) -> dict:
    """Ejecuta el CLI documentado con límites y valida la salida JSON.

    `runner` permite inyectar un simulador en pruebas; con None usa el
    binario real vía subprocess.
    """
    binary = binary if binary is not None else default_binary()
    cmd = build_command(prompt, model, binary)
    if not isinstance(cwd, (str, Path)) or not Path(cwd).is_dir():
        raise AdapterUsage("cwd inválido: debe ser un directorio existente")
    if not isinstance(timeout_seconds, (int, float)) or isinstance(timeout_seconds, bool):
        raise AdapterUsage("timeout inválido")
    timeout = int(timeout_seconds)
    if not MIN_TIMEOUT <= timeout <= MAX_TIMEOUT:
        raise AdapterUsage(f"timeout fuera de rango ({MIN_TIMEOUT}..{MAX_TIMEOUT})")
    if not isinstance(max_output_bytes, int) or isinstance(max_output_bytes, bool):
        raise AdapterUsage("max_output inválido")
    if not 1024 <= max_output_bytes <= MAX_OUTPUT_LIMIT:
        raise AdapterUsage(f"max_output fuera de rango (1024..{MAX_OUTPUT_LIMIT})")

    env = child_environment()
    if runner is not None:
        return _run_with_runner(runner, cmd, cwd, timeout, max_output_bytes, env)

    with tempfile.TemporaryDirectory(prefix="cv-opencode-") as tmp:
        out_path = Path(tmp) / "stdout.jsonl"
        err_path = Path(tmp) / "stderr.txt"
        with out_path.open("wb") as out, err_path.open("wb") as err:
            try:
                proc = subprocess.Popen(
                    cmd,
                    cwd=str(cwd),
                    env=env,
                    stdin=subprocess.DEVNULL,
                    stdout=out,
                    stderr=err,
                    start_new_session=True,
                    preexec_fn=_child_limits(timeout, max_output_bytes),
                )
            except OSError as exc:
                raise AdapterCLI(f"no se pudo iniciar el CLI: {exc}")
            try:
                returncode = proc.wait(timeout=timeout)
            except subprocess.TimeoutExpired:
                _kill_group(proc)
                raise AdapterTimeout(f"CLI excedió el timeout de {timeout}s")
        return _finish_real(out_path, err_path, returncode, max_output_bytes)


def _kill_group(proc: subprocess.Popen) -> None:
    try:
        os.killpg(proc.pid, signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        pass
    try:
        proc.wait(timeout=10)
    except subprocess.TimeoutExpired:
        pass


def _finish_real(out_path: Path, err_path: Path, returncode: int, max_output: int) -> dict:
    raw = out_path.read_bytes()
    err = err_path.read_bytes().decode("utf-8", errors="replace")
    if returncode != 0:
        tail = err[-MAX_STDERR_TAIL:]
        if returncode == -signal.SIGXFSZ or len(raw) >= max_output:
            raise AdapterOutputLimit(f"salida excedió {max_output} bytes (RLIMIT_FSIZE); stderr: {tail}")
        raise AdapterCLI(f"CLI terminó con código {returncode}: {tail or 'sin stderr'}")
    if len(raw) > max_output:
        raise AdapterOutputLimit(f"salida excedió {max_output} bytes")
    return parse_output(raw)


def _run_with_runner(runner, cmd, cwd, timeout, max_output, env) -> dict:
    """Camino de simulador: el runner respeta el contrato (cmd, cwd, env,
    timeout, max_output) y devuelve (returncode, stdout_bytes, stderr_bytes)."""
    try:
        returncode, stdout, stderr = runner(cmd, str(cwd), env, timeout, max_output)
    except Exception as exc:
        raise AdapterCLI(f"runner de simulador falló: {exc}")
    if not isinstance(returncode, int) or not isinstance(stdout, (bytes, bytearray)) or not isinstance(stderr, (bytes, bytearray)):
        raise AdapterCLI("runner devolvió un contrato inválido")
    if returncode != 0:
        tail = bytes(stderr).decode("utf-8", errors="replace")[-MAX_STDERR_TAIL:]
        if len(stdout) >= max_output:
            raise AdapterOutputLimit(f"salida excedió {max_output} bytes; stderr: {tail}")
        raise AdapterCLI(f"CLI terminó con código {returncode}: {tail or 'sin stderr'}")
    if len(stdout) > max_output:
        raise AdapterOutputLimit(f"salida excedió {max_output} bytes")
    return parse_output(bytes(stdout))


def invoke(prompt: str, *, cwd: str | Path, runner=None, **kwargs) -> dict:
    """Puerta de alto nivel: disabled por defecto, bloqueo de aislamiento.

    Solo el camino del simulador (runner inyectado) puede ejecutarse hoy:
    el binario real exige sandbox efectivo y no se invoca automáticamente.
    """
    if not enabled():
        raise AdapterDisabled(
            f"integración desactivada por defecto: requiere {ENABLE_ENV}=1 y {SANDBOX_ENV}=1"
        )
    if runner is None and not ISOLATION_GUARANTEED:
        raise AdapterBlocked(BLOCK_REASON)
    return run_command(prompt, cwd=cwd, runner=runner, **kwargs)


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print(json.dumps({"adapter": ADAPTER_VERSION, "status": "error",
                          "code": AdapterUsage.code, "reason": "uso: opencode-adapter.py <prompt>"}))
        return AdapterUsage.code
    try:
        result = invoke(argv[0], cwd=os.getcwd())
    except AdapterError as exc:
        print(json.dumps({"adapter": ADAPTER_VERSION, "status": "error",
                          "code": exc.code, "reason": exc.message}))
        return exc.code
    print(json.dumps({"adapter": ADAPTER_VERSION, "status": "ok", "text": result["text"][:2000]}))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
