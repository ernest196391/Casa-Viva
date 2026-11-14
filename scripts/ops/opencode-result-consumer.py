#!/usr/bin/env python3
"""Casa Viva Control OS Result Consumer v1 (Fase C, modo simulación).

Corre como root (lado worker). Consume los paquetes de resultados que el
ejecutor OpenCode (cvdev, Fase B) deja en ``results/`` del buzón, valida
todo sin confiar en el ejecutor, concilia los identificadores contra el
registro de runs/tareas, detecta duplicados y estados caducados y deja
los cambios pendientes de aprobación humana. Nunca integra nada por sí
mismo: sin commit, sin push, sin merge, sin despliegue.

Modo simulación: el registro de runs/tareas es una tienda de archivos
(`SimStore`) con datos ficticios; el cableado real a Supabase queda
pendiente y se implementa como una tienda equivalente más adelante.

Invariantes:
- Solo lee ``results/`` y mueve archivos a ``dead/`` (nada se borra en
  silencio; cada movimiento deja ``.motivo.txt``).
- Nunca ejecuta subprocesos ni accede a red.
- Rechaza paquetes con versión, identificadores, tamaños o claves
  inesperadas; los duplicados y los caducados van a ``dead/``.
- La aprobación humana es la única vía hacia estados de integración y
  se registra sin aplicar cambios al repo.
"""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import time
from datetime import datetime, timezone

CONSUMER_VERSION = "opencode-result-consumer-v1"
RESULT_VERSION = "opencode-result-v1"
RESULT_TTL_SECONDS = 45 * 60
TS_FMT = "%Y-%m-%dT%H:%M:%SZ"

ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$")
RESULT_NAME_RE = re.compile(r"^task-run-([A-Za-z0-9][A-Za-z0-9_-]{0,63})\.json$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")

ALLOWED_REPOS = {"casa-viva"}

MAX_RESULT_BYTES = 1_000_000
MAX_CHANGED_PATHS = 12
MAX_LOGS = 10
MAX_LOG_LINE = 2000
MAX_DIFF_CHARS = 400_000
MAX_ERROR = 8_000

RESULT_KEYS = {
    "version", "executor", "run_id", "task_id", "task_public_id", "repo",
    "status", "error", "base_sha", "changed_paths", "diff", "logs",
    "finished_at", "ttl_seconds", "expires_at",
}

# Estados del ciclo run IA (simulación, espejo de ops_dev_runs):
RUN_EXPECTED_STATUS = "waiting_ai"
RUN_APPROVAL_STATUS = "awaiting_approval"

HOME = Path(os.environ.get("CV_OPENCODE_HOME", "/home/cvdev/control-os-dev"))


class ConsumerError(ValueError):
    """Violación de contrato del paquete de resultados."""


def ts(moment: float) -> str:
    return time.strftime(TS_FMT, time.gmtime(moment))


def parse_ts(value) -> float | None:
    if not isinstance(value, str) or not value:
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.timestamp()


def safe_id(value) -> str:
    if not isinstance(value, str) or not ID_RE.match(value):
        raise ConsumerError(f"identificador no seguro: {str(value)[:80]!r}")
    return value


def safe_rel_path(value) -> str:
    if not isinstance(value, str) or not value:
        raise ConsumerError("ruta ausente")
    p = PurePosixPath(value)
    if p.is_absolute() or ".." in p.parts or not p.parts:
        raise ConsumerError("ruta no permitida")
    out = str(p)
    if len(out) > 220 or out.startswith(".git") or "/.git" in out:
        raise ConsumerError("ruta protegida")
    return out


# --- Buzón ----------------------------------------------------------------

def ensure_mailbox_dirs(home: Path) -> dict[str, Path]:
    """Valida que results/ y dead/ existan y no sean alterables."""
    home = Path(home)
    dirs = {}
    for name in ("results", "dead"):
        path = home / name
        if not path.is_dir():
            raise ConsumerError(f"directorio de buzón ausente: {path}")
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o022:
            raise ConsumerError(f"directorio {name} escribible por grupo/otros: {oct(mode)}")
        dirs[name] = path
    return dirs


def move_to_dead(dirs: dict[str, Path], path: Path, reason: str) -> None:
    """Cuarentena con motivo: nunca se borra ni se sobrescribe en silencio."""
    target = dirs["dead"] / path.name
    try:
        if target.exists():
            os.unlink(target)
        os.rename(path, target)
        note = target.with_name(target.name + ".motivo.txt")
        note.write_text(reason[:2000], encoding="utf-8")
    except OSError:
        pass


def load_result(dirs: dict[str, Path], path: Path):
    """Lee y pre-valida el archivo; devuelve (result, None) o (None, motivo)."""
    try:
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o022:
            return None, f"resultado alterable (permisos {oct(mode)})"
        if path.stat().st_size > MAX_RESULT_BYTES:
            return None, "resultado demasiado grande"
        raw = path.read_text(encoding="utf-8")
        return json.loads(raw), None
    except (OSError, ValueError) as exc:
        return None, f"resultado ilegible: {exc}"


# --- Validación del paquete -----------------------------------------------

def validate_result(result, now: float) -> dict:
    """Valida el paquete completo sin confiar en el ejecutor."""
    if not isinstance(result, dict):
        raise ConsumerError("resultado no es un objeto")
    if result.get("version") != RESULT_VERSION:
        raise ConsumerError("versión de resultado no soportada")
    run_id = safe_id(result.get("run_id"))
    if result.get("repo") not in ALLOWED_REPOS:
        raise ConsumerError(f"repo no habilitado: {str(result.get('repo'))[:80]}")

    unknown = sorted(str(key) for key in result if key not in RESULT_KEYS)
    if unknown:
        raise ConsumerError(f"claves de resultado no autorizadas: {unknown}")

    task_id = result.get("task_id")
    if not isinstance(task_id, str) or not task_id or len(task_id) > 200:
        raise ConsumerError("task_id inválido")

    status = result.get("status")
    if status not in ("completed", "failed"):
        raise ConsumerError(f"status no permitido: {str(status)[:60]}")

    finished = parse_ts(result.get("finished_at"))
    if finished is None:
        raise ConsumerError("finished_at ausente o inválido")
    expires = parse_ts(result.get("expires_at"))
    if expires is None:
        raise ConsumerError("expires_at ausente o inválido")
    if expires <= finished:
        raise ConsumerError("expires_at anterior a finished_at")
    if expires <= now:
        raise ConsumerError("resultado caducado")

    ttl = result.get("ttl_seconds")
    if not isinstance(ttl, int) or isinstance(ttl, bool) or not 0 < ttl <= 3600:
        raise ConsumerError("ttl_seconds inválido")

    error = result.get("error")
    base_sha = result.get("base_sha")
    changed = result.get("changed_paths")
    diff = result.get("diff")
    logs = result.get("logs")

    if status == "completed":
        if error is not None:
            raise ConsumerError("resultado completed con error no nulo")
        if not isinstance(base_sha, str) or not SHA_RE.match(base_sha):
            raise ConsumerError("base_sha inválido para completed")
        if not isinstance(changed, list) or not 1 <= len(changed) <= MAX_CHANGED_PATHS:
            raise ConsumerError(f"changed_paths fuera de rango (1..{MAX_CHANGED_PATHS})")
        for rel in changed:
            safe_rel_path(rel)
        if len(set(changed)) != len(changed):
            raise ConsumerError("changed_paths con duplicados")
        if not isinstance(diff, str) or not diff or len(diff) > MAX_DIFF_CHARS:
            raise ConsumerError("diff ausente o demasiado grande")
    else:
        if not isinstance(error, str) or not error or len(error) > MAX_ERROR:
            raise ConsumerError("resultado failed sin error válido")
        if changed != [] or diff is not None:
            raise ConsumerError("resultado failed con cambios residuales")

    if logs is not None:
        if not isinstance(logs, list) or len(logs) > MAX_LOGS:
            raise ConsumerError("logs fuera de rango")
        for line in logs:
            if not isinstance(line, str) or len(line) > MAX_LOG_LINE:
                raise ConsumerError("log inválido")

    public_id = result.get("task_public_id")
    if public_id is not None and (not isinstance(public_id, int) or isinstance(public_id, bool)):
        raise ConsumerError("task_public_id inválido")
    executor = result.get("executor")
    if executor is not None and (not isinstance(executor, str) or not executor or len(executor) > 80):
        raise ConsumerError("executor inválido")
    return result


# --- Tienda simulada (ops_dev_runs / ops_tasks ficticias) ------------------

class SimStore:
    """Registro de runs y tareas en un archivo JSON (datos ficticios).

    Sustituto local de Supabase para el modo simulación; la integración
    real implementará la misma interfaz contra ``ops_dev_runs`` /
    ``ops_tasks`` / ``ops_events`` sin cambiar el consumidor.
    """

    def __init__(self, path: Path):
        self.path = Path(path)
        if self.path.exists():
            data = json.loads(self.path.read_text(encoding="utf-8"))
            self.runs = data.get("runs", {})
            self.tasks = data.get("tasks", {})
            self.events = data.get("events", [])
        else:
            self.runs, self.tasks, self.events = {}, {}, []

    def save(self) -> None:
        tmp = self.path.with_name(f"{self.path.name}.tmp-{os.getpid()}")
        tmp.write_text(json.dumps(
            {"runs": self.runs, "tasks": self.tasks, "events": self.events},
            ensure_ascii=False, indent=2, sort_keys=True,
        ), encoding="utf-8")
        os.chmod(tmp, 0o600)
        os.replace(tmp, self.path)

    def get_run(self, run_id: str):
        return self.runs.get(run_id)

    def get_task(self, task_id: str):
        return self.tasks.get(task_id)

    def set_run(self, run_id: str, patch: dict) -> None:
        run = self.runs.setdefault(run_id, {})
        run.update(patch)
        run["updated_at"] = ts(time.time())

    def set_task(self, task_id: str, patch: dict) -> None:
        task = self.tasks.setdefault(task_id, {})
        task.update(patch)
        task["updated_at"] = ts(time.time())

    def add_event(self, task_id: str, event_type: str, detail: dict) -> None:
        self.events.append({
            "task_id": task_id,
            "event_type": event_type,
            "actor_type": "worker",
            "actor_ref": CONSUMER_VERSION,
            "detail": detail,
            "at": ts(time.time()),
        })


# --- Conciliación, duplicados y caducidad ----------------------------------

def reconcile(store: SimStore, result: dict):
    """Concilia el resultado con run y tarea; devuelve (run, task) o motivo."""
    run = store.get_run(result["run_id"])
    if run is None:
        return None, None, "run no registrada: resultado huérfano"
    if run.get("status") != RUN_EXPECTED_STATUS:
        return None, None, (
            f"run no está en {RUN_EXPECTED_STATUS} (estado {run.get('status')}): "
            "resultado duplicado o fuera de estado"
        )
    if result.get("task_id") != run.get("task_id"):
        return None, None, "task_id del resultado no concilia con la run"
    if result.get("repo") != run.get("repo"):
        return None, None, "repo del resultado no concilia con la run"
    task = store.get_task(run.get("task_id"))
    if task is None:
        return None, None, "tarea de la run no encontrada"
    public_id = result.get("task_public_id")
    if public_id is not None and task.get("public_id") is not None and public_id != task.get("public_id"):
        return None, None, "task_public_id del resultado no concilia con la tarea"
    return run, task, None


def expire_waiting_runs(store: SimStore, now: float) -> list[str]:
    """Runs waiting_ai sin resultado tras el TTL fallan (espejo de Fase A)."""
    expired = []
    for run_id, run in sorted(store.runs.items()):
        if run.get("status") != RUN_EXPECTED_STATUS:
            continue
        base = run.get("updated_at") or run.get("started_at")
        epoch = parse_ts(base)
        if epoch is None or now - epoch < RESULT_TTL_SECONDS:
            continue
        err = "Resultado IA no llegó antes del TTL: run caducada sin aprobación."
        finished = ts(now)
        store.set_run(run_id, {"status": "failed", "error": err, "finished_at": finished})
        task_id = run.get("task_id")
        if task_id and store.get_task(task_id):
            store.set_task(task_id, {"status": "failed", "error": err, "claim_expires_at": None})
            store.add_event(task_id, "ai_job_expired", {"run_id": run_id})
        expired.append(run_id)
    return expired


def apply_result(store: SimStore, result: dict, run: dict, task: dict) -> str:
    """Aplica el resultado validado y conciliado a la tienda simulada."""
    run_id = result["run_id"]
    task_id = result["task_id"]
    if result["status"] == "completed":
        store.set_run(run_id, {
            "status": RUN_APPROVAL_STATUS,
            "repo": result["repo"],
            "base_sha": result["base_sha"],
            "changed_paths": result["changed_paths"],
            "diff": result["diff"],
            "executor": result.get("executor"),
            "result_finished_at": result["finished_at"],
            "result_expires_at": result["expires_at"],
            "summary": "Resultado del ejecutor validado; pendiente de aprobación humana.",
        })
        store.set_task(task_id, {
            "status": "waiting_approval",
            "approval_required": True,
            "claim_expires_at": None,
            "result": {
                "run_id": run_id,
                "repo": result["repo"],
                "base_sha": result["base_sha"],
                "changed_paths": result["changed_paths"],
                "diff": result["diff"],
                "source": "opencode-sandbox",
            },
        })
        store.add_event(task_id, "ai_result_validated", {
            "run_id": run_id,
            "base_sha": result["base_sha"],
            "changed_paths": result["changed_paths"],
        })
        return RUN_APPROVAL_STATUS
    store.set_run(run_id, {
        "status": "failed",
        "error": result["error"],
        "finished_at": ts(time.time()),
        "summary": "Ejecutor reportó fallo; run cerrada sin cambios.",
    })
    store.set_task(task_id, {"status": "failed", "error": result["error"], "claim_expires_at": None})
    store.add_event(task_id, "ai_result_failed", {"run_id": run_id, "error": result["error"][:500]})
    return "failed"


def consume_results(store: SimStore, home: Path, now: float) -> dict:
    """Una pasada: caducados a dead/, luego consume cada resultado presente."""
    moment = time.time() if now is None else now
    dirs = ensure_mailbox_dirs(home)
    summary = {"consumed": [], "dead": []}

    for path in sorted(p for p in dirs["results"].iterdir() if p.is_file()):
        match = RESULT_NAME_RE.match(path.name)
        if not match:
            move_to_dead(dirs, path, "nombre de resultado inválido")
            summary["dead"].append(path.name)
            continue
        try:
            safe_id(match.group(1))
        except ConsumerError as exc:
            move_to_dead(dirs, path, str(exc))
            summary["dead"].append(path.name)
            continue
        result, reason = load_result(dirs, path)
        if result is None:
            move_to_dead(dirs, path, reason)
            summary["dead"].append(path.name)
            continue
        try:
            validate_result(result, moment)
        except ConsumerError as exc:
            move_to_dead(dirs, path, str(exc))
            summary["dead"].append(path.name)
            continue
        run, task, why = reconcile(store, result)
        if why:
            move_to_dead(dirs, path, why)
            summary["dead"].append(path.name)
            continue
        status = apply_result(store, result, run, task)
        move_to_dead(dirs, path, f"consumido: run en {status}")
        summary["consumed"].append({"run_id": result["run_id"], "status": status})
    return summary


def pending_approvals(store: SimStore) -> list[dict]:
    """Cambios listos para revisión humana (nunca integrados por el sistema)."""
    out = []
    for run_id, run in sorted(store.runs.items()):
        if run.get("status") == RUN_APPROVAL_STATUS:
            out.append({
                "run_id": run_id,
                "task_id": run.get("task_id"),
                "repo": run.get("repo"),
                "base_sha": run.get("base_sha"),
                "changed_paths": run.get("changed_paths"),
            })
    return out


def record_decision(store: SimStore, run_id: str, decision: str, actor: str,
                    reason: str = "", now: float | None = None) -> str:
    """Registra la decisión humana; no aplica cambios al repo."""
    moment = time.time() if now is None else now
    try:
        run_id = safe_id(run_id)
    except ConsumerError as exc:
        return str(exc)
    if decision not in ("approve", "reject"):
        return "decisión no permitida"
    run = store.get_run(run_id)
    if run is None:
        return "run no registrada"
    if run.get("status") != RUN_APPROVAL_STATUS:
        return "run no está pendiente de aprobación"
    task_id = run.get("task_id")
    if decision == "approve":
        store.set_run(run_id, {"status": "approved", "approved_by": actor, "approved_at": ts(moment)})
        if task_id and store.get_task(task_id):
            store.set_task(task_id, {"status": "approved", "approval_required": False})
            store.add_event(task_id, "ai_change_approved", {"run_id": run_id, "actor": actor})
        return "approved"
    store.set_run(run_id, {
        "status": "rejected", "approved_by": actor, "approved_at": ts(moment),
        "error": (reason or "cambio rechazado en revisión humana")[:MAX_ERROR],
    })
    if task_id and store.get_task(task_id):
        store.set_task(task_id, {"status": "rejected", "approval_required": False, "claim_expires_at": None})
        store.add_event(task_id, "ai_change_rejected", {"run_id": run_id, "actor": actor, "reason": reason[:500]})
    return "rejected"


def run_once(store: SimStore, home: Path, now: float | None = None) -> dict:
    """Pasada completa: caducidad de runs, consumo de resultados y resumen."""
    moment = time.time() if now is None else now
    summary = run_once_locked(store, home, moment)
    summary["expired_runs"] = expire_waiting_runs(store, moment)
    summary["pending_approvals"] = pending_approvals(store)
    store.save()
    return summary


def run_once_locked(store: SimStore, home: Path, moment: float) -> dict:
    dirs = ensure_mailbox_dirs(home)
    lock_path = home / "consumer.lock"
    with open(lock_path, "a") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {"consumed": [], "dead": [], "skipped": "otro consumidor activo"}
        return consume_results(store, home, moment)


def main() -> int:
    if hasattr(os, "geteuid") and os.geteuid() != 0:
        print("El consumidor de resultados corre como root; use el modo simulación en pruebas", file=sys.stderr)
        return 2
    store_path = os.environ.get("CV_RESULT_SIM_STORE")
    if not store_path:
        print("Modo real no cableado: falta CV_RESULT_SIM_STORE (integración Supabase pendiente)", file=sys.stderr)
        return 2
    store = SimStore(Path(store_path))
    summary = run_once(store, HOME)
    print(json.dumps(summary, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
