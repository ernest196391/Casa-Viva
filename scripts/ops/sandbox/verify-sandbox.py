#!/usr/bin/env python3
"""Casa Viva Control OS — arnés de pruebas negativas del sandbox (Fase E, PROPUESTA).

Verifica, en una instalación real y supervisada, que la jaula Bubblewrap
+ AppArmor proposed en scripts/ops/sandbox/ DENIEGA lo que debe denegar:

- bootstrap: el sandbox arranca y responde (control de vida).
- write_workspace_allowed: control positivo, escribir en el worktree SÍ.
- write_usr_denied / write_etc_denied / write_root_denied: escrituras
  prohibidas fuera del workspace.
- home_masked: el resto del home del host no es visible.
- etc_shadow_absent: /etc/shadow no existe dentro de la jaula.
- net_denied: no hay salida TCP.

Importante:
- ESTE ARNÉS NO SE EJECUTA EN CI Y NO SE HA EJECUTADO AÚN: la propuesta
  no está instalada. Veredictos posibles: ok (todo denegado/permitido
  según diseño), failed (alguna denegación faltó), sandbox-no-operativo
  (la jaula ni siquiera arrancó: revisar la propuesta, NO forzarla con
  un perfil más permisivo).
- Las pruebas de CI solo ejercitan la lógica con un runner falso; nunca
  invocan bwrap. La ejecución real exige pasar `--execute` explícito.
- Nunca convertir un fallo en éxito aflojando el perfil o el comando:
  los resultados alimentan la revisión humana del documento de propuesta.

Uso (solo tras instalar el perfil y con revisión humana):
    python3 scripts/ops/sandbox/verify-sandbox.py \
        --workspace /home/cvdev/control-os-dev/work/run-<run_id> --execute
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

import importlib.util

_HERE = Path(__file__).resolve().parent

_spec_file = _HERE / "opencode-sandbox-spec.py"
_spec = importlib.util.spec_from_file_location("cv_opencode_sandbox_spec", _spec_file)
spec = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(spec)

SANDBOX_VERIFY_VERSION = "opencode-sandbox-verify-v2"
DEV_BWRAP = "/usr/local/lib/control-os/bwrap-dev"
DEV_WORK_ROOT = "/var/lib/control-os-dev/work"

VERDICT_OK = "ok"
VERDICT_FAILED = "failed"
VERDICT_NO_OPERATIVO = "sandbox-no-operativo"

EXIT_OK = 0
EXIT_USAGE = 2
EXIT_REFUSED = 3
EXIT_NO_OPERATIVO = 4
EXIT_FAILED = 5

CHECKS = (
    "bootstrap",
    "write_workspace_allowed",
    "write_usr_denied",
    "write_etc_denied",
    "write_root_denied",
    "home_masked",
    "etc_shadow_absent",
    "net_denied",
)

INNER_PROBE = r'''
import json, os, socket, sys
name = sys.argv[1]
def done(ok, detail=""):
    print(json.dumps({"check": name, "ok": bool(ok), "detail": str(detail)[:500]}))
    sys.stdout.flush()
    sys.exit(0)
if name == "bootstrap":
    done(True, "viva")
elif name == "write_workspace_allowed":
    try:
        p = os.path.join(os.getcwd(), "__cv_probe_rw.txt")
        with open(p, "w") as f:
            f.write("x")
        os.remove(p)
        done(True)
    except OSError as exc:
        done(False, repr(exc))
elif name == "write_usr_denied":
    try:
        with open("/usr/bin/__cv_probe_w", "wb") as f:
            f.write(b"x")
        os.remove("/usr/bin/__cv_probe_w")
        done(False, "escritura en /usr/bin permitida (inesperado)")
    except OSError:
        done(True)
elif name == "write_etc_denied":
    try:
        with open("/etc/__cv_probe_w", "w") as f:
            f.write("x")
        os.remove("/etc/__cv_probe_w")
        done(False, "escritura en /etc permitida (inesperado)")
    except OSError:
        done(True)
elif name == "write_root_denied":
    try:
        with open("/__cv_probe_w", "w") as f:
            f.write("x")
        os.remove("/__cv_probe_w")
        done(False, "escritura en / permitida (inesperado)")
    except OSError:
        done(True)
elif name == "home_masked":
    done(not os.path.isdir("/home/cvdev/workspace"), "el home del host es visible")
elif name == "etc_shadow_absent":
    done(not os.path.exists("/etc/shadow"), "/etc/shadow es visible en la jaula")
elif name == "net_denied":
    try:
        socket.create_connection(("1.1.1.1", 443), timeout=3).close()
        done(False, "conexión TCP permitida (inesperado)")
    except OSError as exc:
        done(True, type(exc).__name__)
else:
    done(False, "prueba desconocida")
'''


def build_probe_command(workspace: str, check: str, **kwargs) -> list:
    """Comando bwrap completo para una prueba individual."""
    inner = ["/usr/bin/python3", "-c", INNER_PROBE, check]
    return spec.build_bwrap_command(workspace, inner, **kwargs)


def real_runner(cmd: list):
    """Runner real: SOLO lo usa main() tras la confirmación --execute."""
    proc = subprocess.run(cmd, capture_output=True, timeout=120, env={}, stdin=subprocess.DEVNULL)
    return proc.returncode, proc.stdout, proc.stderr


def _parse_probe(stdout: bytes):
    try:
        text = stdout.decode("utf-8", errors="replace")
    except Exception:
        return None
    for line in reversed(text.splitlines()):
        line = line.strip()
        if not line.startswith("{"):
            continue
        try:
            data = json.loads(line)
        except ValueError:
            continue
        if isinstance(data, dict) and {"check", "ok", "detail"} <= set(data):
            return data
    return None


def classify_bootstrap_error(stderr: bytes) -> str:
    """Return a fixed diagnostic code; never expose arbitrary stderr."""
    if isinstance(stderr, bytes):
        if b"loopback: Failed RTM_NEWADDR: Operation not permitted" in stderr:
            return "loopback-rtnetlink-denied"
        if b"setting up uid map" in stderr and b"Permission denied" in stderr:
            return "uid-map-denied"
    return "bootstrap-unclassified"


def run_checks(workspace: str, runner, **kwargs) -> dict:
    """Ejecuta todas las pruebas con el runner dado (inyectable en CI).

    El runner recibe el comando bwrap completo (argv, sin shell) y
    devuelve (returncode, stdout, stderr). Con un runner falso la lógica
    se prueba sin tocar el sistema.
    """
    results = {}
    verdict = VERDICT_OK
    for check in CHECKS:
        try:
            cmd = build_probe_command(workspace, check, **kwargs)
            violations = spec.validate_command(cmd, kwargs.get("work_root", spec.DEFAULT_WORK_ROOT),
                                               bwrap_binary=kwargs.get("bwrap_binary"))
            if violations:
                raise ValueError("comando de prueba rechazado")
            returncode, stdout, _stderr = runner(cmd)
        except Exception as exc:
            results[check] = {"ok": False, "detail": f"runner falló: {exc}"}
            verdict = VERDICT_NO_OPERATIVO if check == "bootstrap" else VERDICT_FAILED
            break
        probe = _parse_probe(stdout)
        if (returncode != 0 or probe is None or probe["check"] != check
                or type(probe["ok"]) is not bool or not isinstance(probe["detail"], str)):
            results[check] = {
                "ok": False,
                "detail": f"sin respuesta válida (returncode {returncode}); "
                          "si esto ocurre en bootstrap, la jaula no arranca",
            }
            if check == "bootstrap":
                results[check]["diagnostic_code"] = classify_bootstrap_error(_stderr)
            verdict = VERDICT_NO_OPERATIVO if check == "bootstrap" else VERDICT_FAILED
            break
        results[check] = {"ok": bool(probe["ok"]), "detail": probe["detail"]}
        if not probe["ok"]:
            # Se acumulan todas las denegaciones fallidas para el informe
            # humano; el veredicto global queda en failed.
            verdict = VERDICT_FAILED
    return {
        "verify_version": SANDBOX_VERIFY_VERSION,
        "spec_version": spec.SANDBOX_SPEC_VERSION,
        "workspace": workspace,
        "verdict": verdict,
        "checks": results,
    }


def assess_cgroup_limits(observed: dict, *, memory_bytes: int, max_pids: int, cpu_cores: float) -> dict:
    """Comprueba mediciones cgroup v2; no configura ni consulta el host."""
    if (type(memory_bytes) is not int or memory_bytes <= 0
            or type(max_pids) is not int or max_pids <= 0
            or not isinstance(cpu_cores, (int, float)) or not 0 < cpu_cores < float("inf")):
        raise ValueError("límites aprobados inválidos")
    checks = {}
    for key, limit in (("memory.max", memory_bytes), ("pids.max", max_pids)):
        raw = observed.get(key)
        try:
            value = int(raw)
            checks[key] = 0 < value <= limit
        except (TypeError, ValueError):
            checks[key] = False
    try:
        quota, period = (int(v) for v in observed["cpu.max"].split())
        checks["cpu.max"] = quota > 0 and period > 0 and quota / period <= cpu_cores
    except (KeyError, AttributeError, TypeError, ValueError, ZeroDivisionError):
        checks["cpu.max"] = False
    return {"checks": checks, "verdict": VERDICT_OK if all(checks.values()) else VERDICT_FAILED}


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(add_help=True)
    parser.add_argument("--workspace", default=None)
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--dev-worker", action="store_true", help="Usar binario y raíz dedicados de E2")
    parser.add_argument("--cgroup-evidence", type=Path, help="Evaluar JSON de mediciones, sin ejecutar sandbox")
    parser.add_argument("--memory-bytes", type=int)
    parser.add_argument("--max-pids", type=int)
    parser.add_argument("--cpu-cores", type=float)
    args = parser.parse_args(argv)

    if args.cgroup_evidence is not None:
        if args.execute or args.workspace:
            parser.error("evaluación documental separada de la ejecución real")
        try:
            observed = json.loads(args.cgroup_evidence.read_text(encoding="utf-8"))
            if not isinstance(observed, dict):
                raise ValueError("evidencia no es objeto")
            result = assess_cgroup_limits(observed, memory_bytes=args.memory_bytes,
                                          max_pids=args.max_pids, cpu_cores=args.cpu_cores)
        except (OSError, ValueError, TypeError):
            print(json.dumps({"verdict": VERDICT_FAILED, "reason": "evidencia o límites inválidos"}))
            return EXIT_FAILED
        result["scope"] = "evaluación de datos aportados; no certificación del VPS"
        print(json.dumps(result))
        return EXIT_OK if result["verdict"] == VERDICT_OK else EXIT_FAILED

    if not args.workspace or not args.execute:
        print(json.dumps({
            "verify": SANDBOX_VERIFY_VERSION,
            "verdict": "refused",
            "reason": "ejecución real requiere --workspace <run-<id>> y --execute; "
                      "esta herramienta no se ejecuta automáticamente ni en CI",
        }))
        return EXIT_REFUSED
    options = {"bwrap_binary": DEV_BWRAP, "work_root": DEV_WORK_ROOT} if args.dev_worker else {}
    try:
        spec.validate_workspace(args.workspace, options.get("work_root", spec.DEFAULT_WORK_ROOT))
    except spec.SandboxSpecError as exc:
        print(json.dumps({"verify": SANDBOX_VERIFY_VERSION, "verdict": "refused",
                          "reason": str(exc)}))
        return EXIT_USAGE
    if not Path(args.workspace).is_dir():
        print(json.dumps({"verify": SANDBOX_VERIFY_VERSION, "verdict": "refused",
                          "reason": f"workspace inexistente: {args.workspace}"}))
        return EXIT_USAGE

    if os.geteuid() == 0:
        print(json.dumps({"verdict": "refused", "reason": "ejecutar como usuario no privilegiado"}))
        return EXIT_REFUSED
    report = run_checks(args.workspace, real_runner, **options)
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if report["verdict"] == VERDICT_OK:
        return EXIT_OK
    if report["verdict"] == VERDICT_NO_OPERATIVO:
        return EXIT_NO_OPERATIVO
    return EXIT_FAILED


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
