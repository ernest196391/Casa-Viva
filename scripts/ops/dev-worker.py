#!/usr/bin/env python3
"""Casa Viva Control OS Dev Worker v1.

Phase 1: fully autonomous deterministic development for VivaBot.
AI tasks are claimed into waiting_ai but are never executed as root.
No arbitrary shell, repo, path, test command, or deploy target comes from a task.

Fase A (OpenCode × Control OS): los trabajos de IA se preparan como
paquetes sin secretos (whitelist + redacción), con caducidad (TTL) y
manejo de errores; nunca se ejecutan aquí y nunca contienen credenciales.

Buzón cvdev (Fase B): los paquetes se entregan en el buzón del ejecutor
OpenCode (cvdev) con archivos 0644; la confidencialidad la aporta el
directorio provisionado para cvdev (0700/0750), no la redacción. El
directorio debe existir y ser seguro antes de escribir; el worker nunca
lo crea como root para no apropiarse de él.
"""
from __future__ import annotations

from datetime import datetime, timezone
import fcntl
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import time
import tempfile
import urllib.parse
import urllib.request

ROOT = Path("/root/supervisor")
DEV_ROOT = Path("/root/control-os-dev")
LOCK = "/tmp/cv-dev-worker.lock"
WORKER = "cv-dev-worker-v1"
MAX_LOG = 30000

REPOS = {
    "vivabot": Path("/root/vivabot"),
    "nexo": Path("/root/ernesto-rondon-nexo"),
    "casa-viva": Path("/root/Casa-Viva"),
}
TEST_PROFILES = {
    "vivabot": [
        ["npm", "run", "check"],
    ],
    "nexo": [
        ["npm", "run", "lint"],
        ["npm", "test"],
        ["npm", "run", "typecheck"],
    ],
    "casa-viva": [
        ["npm", "run", "lint"],
        ["npm", "run", "typecheck"],
        ["npm", "run", "build"],
        ["npm", "run", "test:order-center"],
    ],
}

def load_env(path: Path) -> None:
    if not path.exists():
        return
    for raw in path.read_text(errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k and k not in os.environ:
            os.environ[k] = v

SUPABASE_URL = ""
SERVICE_KEY = ""

TS_FMT = "%Y-%m-%dT%H:%M:%SZ"


def load_credentials() -> None:
    """Carga .env y credenciales Supabase; solo se llama en main().

    Moverlo aquí (antes era código a nivel de módulo) permite importar
    este archivo en las pruebas unitarias sin tocar /root ni red.
    """
    global SUPABASE_URL, SERVICE_KEY
    for env_path in (Path("/root/vivabot/.env"), ROOT / ".env"):
        load_env(env_path)

    SUPABASE_URL = os.environ.get("SUPABASE_URL", "").rstrip("/")
    SERVICE_KEY = (
        os.environ.get("SUPABASE_SERVICE_ROLE_KEY")
        or os.environ.get("SUPABASE_SERVICE_KEY")
        or os.environ.get("SUPABASE_SECRET_KEY")
        or os.environ.get("SERVICE_ROLE_KEY")
        or os.environ.get("VIVABOT_SUPABASE_SERVICE_ROLE_KEY")
    )
    if not SUPABASE_URL or not SERVICE_KEY:
        raise SystemExit("Faltan SUPABASE_URL o service key")


def api(method: str, path: str, body=None, prefer=None):
    url = SUPABASE_URL + "/rest/v1/" + path.lstrip("/")
    data = None if body is None else json.dumps(body, ensure_ascii=False).encode()
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("apikey", SERVICE_KEY)
    req.add_header("Authorization", "Bearer " + SERVICE_KEY)
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json")
    if prefer:
        req.add_header("Prefer", prefer)
    with urllib.request.urlopen(req, timeout=30) as resp:
        raw = resp.read()
    return json.loads(raw) if raw else None


# Entorno de los procesos hijos (git, npm, pruebas): lista blanca, nunca el del worker.
# El worker carga /root/vivabot/.env en os.environ para hablar con Supabase; sin esta
# lista cada prueba heredaba claves de servicio (medido el 16-nov: 10 variables).
CHILD_ENV_KEYS = ("PATH", "HOME", "LANG", "LC_ALL", "LC_CTYPE", "USER", "LOGNAME", "TERM", "TZ", "TMPDIR")
DEFAULT_CHILD_PATH = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
SECRET_NAME_RE = re.compile(r"KEY|TOKEN|SECRET|PASS|CREDENTIAL|AUTH", re.IGNORECASE)


def child_env(environ=None) -> dict:
    """Entorno mínimo para hijos. `CV_DEV_CHILD_ENV_EXTRA` (nombres separados por comas)
    añade variables NO secretas que una prueba necesite; los nombres con aspecto de
    secreto se ignoran siempre."""
    source = os.environ if environ is None else environ
    env = {k: source[k] for k in CHILD_ENV_KEYS if k in source and source[k] != ""}
    env.setdefault("PATH", DEFAULT_CHILD_PATH)
    for name in str(source.get("CV_DEV_CHILD_ENV_EXTRA", "")).split(","):
        name = name.strip()
        if re.fullmatch(r"[A-Z][A-Z0-9_]{0,63}", name) and not SECRET_NAME_RE.search(name) and name in source:
            env[name] = source[name]
    return env


def run(cmd, cwd=None, timeout=120):
    p = subprocess.run(cmd, cwd=cwd, env=child_env(), capture_output=True, text=True, timeout=timeout)
    out = (p.stdout or "") + (("\nSTDERR:\n" + p.stderr) if p.stderr else "")
    return p.returncode, out[-MAX_LOG:]


def patch_table(table: str, row_id, values: dict):
    encoded = urllib.parse.quote(str(row_id), safe="")
    return api("PATCH", f"{table}?id=eq.{encoded}", values, "return=representation")


def task_row(task_id: str):
    rows = api("GET", "ops_tasks?select=*&id=eq." + urllib.parse.quote(task_id, safe=""))
    return rows[0] if rows else None


def bridge_row(bridge_id: int):
    rows = api("GET", f"crm_ops_bridge?select=id,status,result,error,started_at,finished_at&id=eq.{bridge_id}")
    return rows[0] if rows else None


def event(task: dict, event_type: str, detail: dict):
    api("POST", "ops_events", {
        "task_id": task["id"],
        "thread_id": task.get("thread_id"),
        "event_type": event_type,
        "actor_type": "worker",
        "actor_ref": WORKER,
        "detail": detail,
    })


def slug(value: str) -> str:
    s = value.lower()
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    return (s[:45] or "change")


def safe_rel_path(value: str) -> str:
    p = PurePosixPath(str(value))
    if p.is_absolute() or ".." in p.parts or not p.parts:
        raise ValueError("ruta no permitida")
    out = str(p)
    if len(out) > 220 or out.startswith(".git") or "/.git" in out:
        raise ValueError("ruta protegida")
    return out


def path_allowed(path: str, prefixes: list[str]) -> bool:
    for raw in prefixes:
        prefix = safe_rel_path(raw).rstrip("/")
        if path == prefix or path.startswith(prefix + "/"):
            return True
    return False


# --- Fase A: preparación de trabajos de IA para OpenCode (sin secretos) ---

AI_JOB_VERSION = "opencode-ai-job-v1"
AI_JOB_TTL_SECONDS = 45 * 60
AI_JOB_MAX_OPERATIONS = 8
AI_JOB_MAX_CONTENT = 120000
AI_JOB_MAX_DETERMINISTIC_CONTENT = 12000
# Buzón del ejecutor OpenCode (cvdev). Provisionado manualmente, propiedad
# de cvdev, sin escritura para grupo/otros; root escribe dentro pero no lo crea.
AI_MAILBOX = Path(os.environ.get("CV_AI_MAILBOX", "/home/cvdev/control-os-dev/jobs"))

# Claves que nunca deben viajar en un trabajo de IA.
SECRET_FIELD_RE = re.compile(
    r"passw|passwd|secret|token|api[-_]?key|authorization|credential|service[-_]?role",
    re.IGNORECASE,
)
# Valores que parecen credenciales aunque viajen dentro de otro campo.
SECRET_VALUE_RE = re.compile(
    r"eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}"  # JWT
    r"|sk-[A-Za-z0-9_-]{20,}"  # claves estilo OpenAI
    r"|sbp_[A-Za-z0-9_-]{20,}"  # claves estilo Supabase
    r"|Bearer\s+\S{20,}",  # cabeceras de autorización
    re.IGNORECASE,
)
# Contenido de ediciones estructuradas: código verbatim, nunca se reescribe.
CODE_FIELDS = ("content", "old", "new")


def redact_value(value, _depth: int = 0):
    """Redacción defensiva: claves sensibles y valores con forma de credencial.

    Los campos de código (content/old/new) viajan verbatim: reescribirlos
    rompería la edición determinista; la exclusión de secretos es por
    whitelist, esto es solo segunda línea de defensa en metadatos.
    """
    if _depth > 8:
        return "[REDACTED_DEEP]"
    if isinstance(value, dict):
        out = {}
        for key, item in value.items():
            if isinstance(key, str) and SECRET_FIELD_RE.search(key):
                out[key] = "[REDACTED]"
            elif key in CODE_FIELDS:
                out[key] = item
            else:
                out[key] = redact_value(item, _depth + 1)
        return out
    if isinstance(value, list):
        return [redact_value(item, _depth + 1) for item in value]
    if isinstance(value, str):
        return SECRET_VALUE_RE.sub("[REDACTED]", value)
    return value


def _clean_allow_paths(prefixes) -> list[str]:
    if not isinstance(prefixes, list) or not prefixes:
        raise ValueError("trabajo IA requiere allow_paths")
    out = []
    for raw in prefixes:
        if not isinstance(raw, str) or not raw:
            raise ValueError("allow_paths inválido")
        out.append(safe_rel_path(raw))
    return out


def _clean_operations(operations) -> list[dict]:
    if not isinstance(operations, list) or not 1 <= len(operations) <= AI_JOB_MAX_OPERATIONS:
        raise ValueError("structured patch requiere 1..8 operaciones")
    out = []
    for op in operations:
        if not isinstance(op, dict):
            raise ValueError("operación inválida")
        cleaned = {}
        for key in ("op", "path"):
            value = op.get(key)
            if not isinstance(value, str) or not value:
                raise ValueError(f"operación sin {key}")
            cleaned[key] = value[:300]
        safe_rel_path(cleaned["path"])
        for key in CODE_FIELDS:
            if key in op:
                value = op[key]
                if not isinstance(value, str) or len(value) > AI_JOB_MAX_CONTENT:
                    raise ValueError(f"campo {key} inválido o demasiado grande")
                cleaned[key] = value
        if "expected_count" in op:
            try:
                cleaned["expected_count"] = int(op["expected_count"])
            except (TypeError, ValueError):
                raise ValueError("expected_count inválido")
        out.append(cleaned)
    return out


def _clean_deterministic(spec) -> dict:
    if not isinstance(spec, dict) or spec.get("operation") != "write_smoke_file":
        raise ValueError("operación determinista no habilitada")
    content = spec.get("content")
    if not isinstance(content, str) or not content or len(content) > AI_JOB_MAX_DETERMINISTIC_CONTENT:
        raise ValueError("contenido smoke inválido")
    return {
        "operation": "write_smoke_file",
        "path": safe_rel_path(spec.get("path", "")),
        "content": content,
    }


def build_ai_job(task: dict, claim: dict, now=None) -> dict:
    """Construye el paquete OpenCode desde la tarea: whitelist, nunca secretos."""
    if not isinstance(task, dict) or not task.get("id"):
        raise ValueError("tarea inválida para trabajo IA")
    if not isinstance(claim, dict) or not claim.get("run_id"):
        raise ValueError("claim inválido para trabajo IA")
    packet = (task.get("payload") or {}).get("dev") or {}
    if not isinstance(packet, dict) or not packet:
        raise ValueError("payload dev ausente o inválido")

    allowed_keys = ("patch_format", "allow_paths", "operations", "deterministic")
    instructions: dict = {}
    ignored = sorted(str(key) for key in packet if key not in allowed_keys)
    for key in allowed_keys:
        if key not in packet:
            continue
        if key == "operations":
            instructions["operations"] = _clean_operations(packet[key])
        elif key == "allow_paths":
            instructions["allow_paths"] = _clean_allow_paths(packet[key])
        elif key == "deterministic":
            instructions["deterministic"] = _clean_deterministic(packet[key])
        else:
            if not isinstance(packet[key], str) or not packet[key]:
                raise ValueError("patch_format inválido")
            instructions["patch_format"] = packet[key][:40]

    if "allow_paths" not in instructions:
        raise ValueError("trabajo IA requiere allow_paths")
    if "operations" not in instructions and "deterministic" not in instructions:
        raise ValueError("trabajo IA requiere operations o deterministic")

    moment = time.time() if now is None else now
    job = {
        "version": AI_JOB_VERSION,
        "run_id": str(claim["run_id"]),
        "task_id": str(task["id"]),
        "task_public_id": task.get("public_id"),
        "repo": claim.get("repo"),
        "mode": "ai",
        "title": str(task.get("title") or "")[:200],
        "risk": task.get("risk") or "guarded",
        "created_at": time.strftime(TS_FMT, time.gmtime(moment)),
        "expires_at": time.strftime(TS_FMT, time.gmtime(moment + AI_JOB_TTL_SECONDS)),
        "ttl_seconds": AI_JOB_TTL_SECONDS,
        "instructions": instructions,
        "ignored_packet_keys": ignored,
    }
    return redact_value(job)


def ai_job_path(run_id: str, spool_dir) -> Path:
    safe = re.sub(r"[^A-Za-z0-9_-]+", "-", str(run_id)).strip("-") or "run"
    return Path(spool_dir) / f"task-run-{safe}.json"


def validate_mailbox_dir(spool) -> None:
    """El buzón debe existir y no ser escribible por grupo/otros.

    No se crea aquí: si root lo creara, sería de root y cvdev no podría
    entrar (o requeriría chmod posteriores). Es responsabilidad del
    provisionamiento como usuario cvdev.
    """
    mailbox = Path(spool)
    if not mailbox.is_dir():
        raise RuntimeError(f"buzón IA no provisionado: {mailbox}")
    mode = stat.S_IMODE(mailbox.stat().st_mode)
    if mode & 0o022:
        raise RuntimeError(f"buzón IA con permisos inseguros: {oct(mode)}")


def write_ai_job(job: dict, spool_dir=None) -> Path:
    """Entrega atómica en el buzón cvdev: tmp + rename, archivo 0644.

    La confidencialidad la aporta el directorio del buzón (provisionado
    para cvdev sin acceso de grupo/otros); el archivo debe poder leerlo
    cvdev, por eso 0644 y no 0600.
    """
    spool = Path(spool_dir) if spool_dir is not None else AI_MAILBOX
    validate_mailbox_dir(spool)
    path = ai_job_path(job.get("run_id", ""), spool)
    tmp = path.with_name(f"{path.name}.tmp-{os.getpid()}")
    tmp.write_text(json.dumps(job, ensure_ascii=False, indent=2, sort_keys=True), encoding="utf-8")
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)
    return path


def parse_ts(value) -> float | None:
    """Parsea marcas ISO-8601 del worker; naive se interpreta como UTC."""
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


def ts_expired(expires_at, now: float) -> bool:
    epoch = parse_ts(expires_at)
    if epoch is None:
        return True
    return epoch <= now


def ai_run_expired(row: dict, now=None) -> bool:
    moment = time.time() if now is None else now
    base = row.get("updated_at") or row.get("started_at") if isinstance(row, dict) else None
    epoch = parse_ts(base) if base else None
    if epoch is None:
        return False
    return moment - epoch >= AI_JOB_TTL_SECONDS


def purge_ai_job(run_id: str, spool_dir=None) -> bool:
    path = ai_job_path(run_id, spool_dir if spool_dir is not None else AI_MAILBOX)
    try:
        path.unlink()
    except OSError:
        return False
    return True


def clean_expired_ai_jobs(spool_dir, now=None) -> list[str]:
    """Borra trabajos caducados o corruptos del spool; devuelve nombres borrados."""
    moment = time.time() if now is None else now
    spool = Path(spool_dir)
    removed: list[str] = []
    if not spool.is_dir():
        return removed
    for path in sorted(spool.glob("*.json")):
        try:
            job = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            job = None
        if job is None or ts_expired(job.get("expires_at") if isinstance(job, dict) else None, moment):
            try:
                path.unlink()
            except OSError:
                continue
            removed.append(path.name)
    return removed


def handle_ai_claim(task: dict, claim: dict, run_id: str):
    # Hard safety gate: do not run an AI editor as root.
    try:
        job = build_ai_job(task, claim)
        write_ai_job(job)
    except Exception as exc:
        message = str(exc)[:8000]
        patch_table("ops_dev_runs", run_id, {"status": "failed", "error": message, "finished_at": time.strftime(TS_FMT, time.gmtime())})
        patch_table("ops_tasks", task["id"], {"status": "failed", "error": message, "claim_expires_at": None})
        event(task, "ai_job_failed", {"run_id": run_id, "error": message})
        return

    summary = f"Trabajo IA preparado sin secretos; expira {job['expires_at']}. Esperando sandbox OpenCode."
    patch_table("ops_dev_runs", run_id, {
        "status": "waiting_ai",
        "summary": summary,
        "updated_at": time.strftime(TS_FMT, time.gmtime()),
    })
    patch_table("ops_tasks", task["id"], {
        "status": "waiting_ai",
        "claim_expires_at": None,
        "updated_at": time.strftime(TS_FMT, time.gmtime()),
    })
    event(task, "ai_job_prepared", {
        "run_id": run_id,
        "job_version": job["version"],
        "expires_at": job["expires_at"],
        "operations": len(job.get("instructions", {}).get("operations") or []),
        "ignored_packet_keys": job.get("ignored_packet_keys", []),
    })


def expire_waiting_ai_runs(now=None):
    moment = time.time() if now is None else now
    rows = api("GET", "ops_dev_runs?select=*&status=eq.waiting_ai&order=updated_at.asc&limit=20") or []
    for row in rows:
        if not ai_run_expired(row, moment):
            continue
        err = "Trabajo IA caducado sin ser recogido del spool."
        finished = time.strftime(TS_FMT, time.gmtime())
        patch_table("ops_dev_runs", row["id"], {"status": "failed", "error": err, "finished_at": finished})
        task = task_for_run(row)
        if task:
            patch_table("ops_tasks", task["id"], {"status": "failed", "error": err, "claim_expires_at": None})
            event(task, "ai_job_expired", {"run_id": row["id"]})
        purge_ai_job(row["id"])


def prepare_worktree(repo_name: str, task_public_id: int, title: str):
    if repo_name not in REPOS:
        raise ValueError("repo no habilitado en C5 fase 1")
    repo = REPOS[repo_name]
    if not (repo / ".git").exists():
        raise RuntimeError(f"{repo} no es un checkout Git")

    rc, out = run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
    if rc:
        raise RuntimeError("git fetch falló: " + out)

    rc, base = run(["git", "-C", str(repo), "rev-parse", "origin/main"], timeout=20)
    if rc:
        raise RuntimeError(base)
    base_sha = base.strip()

    # Git metadata and the commit checkout must never enter the sandbox.
    root = DEV_ROOT
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    work = root / f"task-{task_public_id}"
    if work.exists():
        run(["git", "-C", str(repo), "worktree", "remove", "--force", str(work)], timeout=45)
        shutil.rmtree(work, ignore_errors=True)

    rc, out = run(["git", "-C", str(repo), "worktree", "add", "--detach", str(work), base_sha], timeout=60)
    if rc:
        raise RuntimeError("worktree add falló: " + out)

    branch = f"control-os/task-{task_public_id}-{slug(title)}"
    rc, out = run(["git", "switch", "-c", branch], cwd=str(work), timeout=30)
    if rc:
        raise RuntimeError("branch create falló: " + out)
    return repo, work, branch, base_sha


def _safe_target(work: Path, rel: str) -> Path:
    path = safe_rel_path(rel)
    current = work
    for part in PurePosixPath(path).parts[:-1]:
        current = current / part
        if current.is_symlink():
            raise ValueError("ruta pasa por symlink")
    target = work / path
    if target.is_symlink():
        raise ValueError("target symlink no permitido")
    return target


def structured_edit(work: Path, packet: dict):
    operations = packet.get("operations") or []
    prefixes = packet.get("allow_paths") or []
    if not isinstance(operations, list) or not 1 <= len(operations) <= 8:
        raise ValueError("structured patch requiere 1..8 operaciones")
    if not isinstance(prefixes, list) or not prefixes:
        raise ValueError("structured patch requiere allow_paths")

    changed = []
    for op in operations:
        if not isinstance(op, dict):
            raise ValueError("operación inválida")
        kind = op.get("op")
        path = safe_rel_path(op.get("path", ""))
        if not path_allowed(path, prefixes):
            raise ValueError(f"ruta fuera de allow_paths: {path}")
        target = _safe_target(work, path)

        if kind == "create_file":
            if target.exists():
                raise ValueError(f"create_file ya existe: {path}")
            content = str(op.get("content", ""))
            if not content or len(content) > 120000:
                raise ValueError(f"contenido inválido: {path}")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")

        elif kind == "replace_file":
            if not target.is_file():
                raise ValueError(f"replace_file no existe: {path}")
            content = str(op.get("content", ""))
            if len(content) > 120000:
                raise ValueError(f"contenido demasiado grande: {path}")
            target.write_text(content, encoding="utf-8")

        elif kind == "text_replace":
            if not target.is_file():
                raise ValueError(f"text_replace no existe: {path}")
            old = str(op.get("old", ""))
            new = str(op.get("new", ""))
            expected = int(op.get("expected_count", 1))
            if not old or not 1 <= expected <= 5:
                raise ValueError(f"text_replace inválido: {path}")
            before = target.read_text(encoding="utf-8")
            count = before.count(old)
            if count != expected:
                raise ValueError(f"{path}: esperaba {expected} coincidencia(s), encontré {count}")
            target.write_text(before.replace(old, new), encoding="utf-8")

        else:
            raise ValueError(f"op no permitida: {kind}")
        changed.append(path)
    return sorted(set(changed))


def deterministic_edit(task: dict, work: Path, packet: dict):
    if packet.get("patch_format") == "structured-v1":
        return structured_edit(work, packet)

    spec = packet.get("deterministic") or {}
    operation = spec.get("operation")
    if operation != "write_smoke_file":
        raise ValueError("operación determinista no habilitada")
    path = safe_rel_path(spec.get("path", ""))
    if not path.startswith("docs/control-os-smoke/") or not path.endswith(".md"):
        raise ValueError("smoke solo puede escribir docs/control-os-smoke/*.md")
    prefixes = packet.get("allow_paths") or []
    if not isinstance(prefixes, list) or not path_allowed(path, prefixes):
        raise ValueError("ruta fuera de allow_paths")
    content = str(spec.get("content", ""))
    if not content or len(content) > 12000:
        raise ValueError("contenido smoke inválido")
    target = _safe_target(work, path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding="utf-8")
    return [path]


def validate_diff(work: Path, allowed_paths: list[str]):
    rc, names = run(["git", "diff", "--name-only"], cwd=str(work), timeout=20)
    if rc:
        raise RuntimeError(names)
    changed = [x.strip() for x in names.splitlines() if x.strip()]
    # Untracked files never appear in git diff; include them even when tracked files changed too.
    rc, others = run(["git", "ls-files", "--others", "--exclude-standard"], cwd=str(work), timeout=20)
    if rc:
        raise RuntimeError(others)
    changed = sorted(set(changed + [x.strip() for x in others.splitlines() if x.strip()]))
    if not changed or len(changed) > 12:
        raise ValueError("cantidad de archivos cambiados fuera de límite")
    for path in changed:
        safe_rel_path(path)
        if path.startswith(".github/") or path in ("package.json", "package-lock.json"):
            raise ValueError(f"ruta protegida: {path}")
        if not path_allowed(path, allowed_paths):
            raise ValueError(f"ruta fuera de allowlist: {path}")

    rc, out = run(["git", "diff", "--check"], cwd=str(work), timeout=30)
    if rc:
        raise ValueError("git diff --check falló: " + out)

    rc, num = run(["git", "diff", "--numstat", "--", *changed], cwd=str(work), timeout=20)
    if rc:
        raise RuntimeError(num)
    lines = 0
    for line in num.splitlines():
        parts = line.split("\t")
        if len(parts) >= 2 and parts[0].isdigit() and parts[1].isdigit():
            lines += int(parts[0]) + int(parts[1])
    # Untracked smoke file is bounded separately by content size.
    if lines > 1200:
        raise ValueError("diff demasiado grande")
    return changed


# --- Ejecución en caja (EVO-LOCAL-001, E1). APAGADA por defecto: solo CV_DEV_SANDBOX=1 la enciende. ---
SANDBOX_USER = "cvdev"
_BOX = None


def sandbox_enabled() -> bool:
    return os.environ.get("CV_DEV_SANDBOX") == "1"


def _box():
    """Carga perezosa del módulo de la caja (junto a este archivo); sin él, el modo caja no arranca."""
    global _BOX
    if _BOX is None:
        path = Path(__file__).with_name("dev-sandbox.py")
        spec = importlib.util.spec_from_file_location("cv_dev_sandbox", path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        _BOX = module
    return _BOX


def _sandbox_ids():
    import pwd  # solo POSIX; únicamente se llama con la caja encendida
    try:
        entry = pwd.getpwnam(SANDBOX_USER)
    except KeyError:
        raise RuntimeError(f"usuario {SANDBOX_USER} no existe: no se puede usar la caja")
    if entry.pw_uid == 0 or entry.pw_gid == 0:
        raise RuntimeError("el usuario de la caja no puede ser root")
    return {"uid": entry.pw_uid, "gid": entry.pw_gid}


def _sandbox_parent() -> Path:
    root = Path(_box().WORK_ROOT)
    # No ancestor may be replaced by cvdev (including its home directory).
    for path in (root, *root.parents):
        try:
            info = path.lstat()
        except OSError as exc:
            raise RuntimeError(f"raíz de caja no provisionada: {path}") from exc
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise RuntimeError(f"raíz de caja no confiable: {path}")
    return root


def run_tests_isolated(repo_name: str, work: Path, changed: list, allowed: list):
    """Test a disposable copy; never execute Git or import files from that copy."""
    if not sandbox_enabled():
        raise RuntimeError("isolated testing required")
    for rel in changed:
        target = _safe_target(work, rel)
        if target.is_symlink() or not target.is_file():
            raise RuntimeError(f"ruta cambiada no regular o simbólica: {rel}")
    box, ids = _box(), _sandbox_ids()
    root = _sandbox_parent()
    if not shutil.rmtree.avoids_symlink_attacks:
        raise RuntimeError("limpieza segura de caja no disponible")
    test_work = Path(tempfile.mkdtemp(prefix="run-task-", dir=root))
    try:
        shutil.copytree(work, test_work, symlinks=True, dirs_exist_ok=True,
                        ignore=lambda _directory, names: {n for n in names if n.lower() == ".git"})
        box.hand_over(test_work, ids["uid"], ids["gid"])
        # No take_back(): the untrusted copy will never be used by privileged Git.
        ok, logs = run_tests(repo_name, test_work)
    finally:
        shutil.rmtree(test_work)
    after = validate_diff(work, allowed)
    if sorted(after) != sorted(changed):
        raise RuntimeError("el checkout confiable cambió durante las pruebas")
    return ok, logs


def run_tests(repo_name: str, work: Path):
    logs = []
    if not sandbox_enabled():
        raise RuntimeError("isolated testing required")
    ids = _sandbox_ids()

    def _install(argv, timeout):
        # Caja: `npm ci --ignore-scripts` como cvdev, con red y sin secretos. Sin caja: la orden de siempre.
        return _box().install_deps(work, timeout, **ids)

    def _step(argv, timeout):
        return _box().run_in_box(argv, work, timeout, **ids)

    if repo_name == "vivabot":
        rc, out = _install(["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund"], 180)
        logs.append("$ npm ci\n" + out)
        if rc:
            return False, "\n".join(logs)
        for cmd in TEST_PROFILES["vivabot"]:
            rc, out = _step(cmd, 240)
            logs.append("$ " + " ".join(cmd) + "\n" + out)
            if rc:
                return False, "\n".join(logs)
        php_file = work / "scripts/core-chat-purchase.php"
        php_test = work / "test/core-chat-purchase.php"
        if php_file.exists() and php_test.exists():
            for cmd in (["php", "-l", str(php_file)], ["php", str(php_test)]):
                rc, out = _step(cmd, 90)
                logs.append("$ " + " ".join(cmd) + "\n" + out)
                if rc:
                    return False, "\n".join(logs)
    elif repo_name == "nexo":
        rc, out = _install(["npm", "install", "--no-audit", "--no-fund"], 240)
        logs.append("$ npm install\n" + out)
        if rc:
            return False, "\n".join(logs)
        for cmd in TEST_PROFILES["nexo"]:
            rc, out = _step(cmd, 300)
            logs.append("$ " + " ".join(cmd) + "\n" + out)
            if rc:
                return False, "\n".join(logs)
        py = work / "worker/app.py"
        if py.exists():
            rc, out = _step(["python3", "-m", "py_compile", str(py)], 60)
            logs.append("$ python3 -m py_compile worker/app.py\n" + out)
            if rc:
                return False, "\n".join(logs)
    elif repo_name == "casa-viva":
        rc, out = _install(["npm", "install", "--no-audit", "--no-fund"], 300)
        logs.append("$ npm install\n" + out)
        if rc:
            return False, "\n".join(logs)
        for cmd in TEST_PROFILES["casa-viva"]:
            rc, out = _step(cmd, 360)
            logs.append("$ " + " ".join(cmd) + "\n" + out)
            if rc:
                return False, "\n".join(logs)
        for rel in ("scripts/ops/cv-ops-bridge.py", "scripts/ops/control-os-sync.py", "scripts/ops/dev-worker.py", "scripts/ops/opencode-executor.py"):
            target = work / rel
            if target.exists():
                rc, out = _step(["python3", "-m", "py_compile", str(target)], 60)
                logs.append("$ python3 -m py_compile " + rel + "\n" + out)
                if rc:
                    return False, "\n".join(logs)
        for cmd in (
            ["php", "artifacts/tests/test-panel-bajo-pedido.php"],
            ["node", "artifacts/tests/test-inventory-integrity-5c.mjs"],
        ):
            rc, out = _step(cmd, 120)
            logs.append("$ " + " ".join(cmd) + "\n" + out)
            if rc:
                return False, "\n".join(logs)
    else:
        return False, "repo no habilitado"
    return True, "\n".join(logs)[-MAX_LOG:]


def cleanup_worktree(repo: Path, work: Path, branch: str):
    run(["git", "-C", str(repo), "worktree", "remove", "--force", str(work)], timeout=60)
    run(["git", "-C", str(repo), "branch", "-D", branch], timeout=30)


def process_claim(claim: dict):
    run_id = claim["run_id"]
    task = task_row(claim["task_id"])
    if not task:
        patch_table("ops_dev_runs", run_id, {"status": "failed", "error": "tarea no encontrada", "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
        return

    packet = (task.get("payload") or {}).get("dev") or {}
    repo_name = claim["repo"]
    mode = claim["mode"]
    if mode == "ai":
        handle_ai_claim(task, claim, run_id)
        return

    repo = work = branch = None
    try:
        patch_table("ops_dev_runs", run_id, {"status": "preparing"})
        repo, work, branch, base_sha = prepare_worktree(repo_name, task["public_id"], task["title"])
        patch_table("ops_dev_runs", run_id, {"branch": branch, "base_sha": base_sha, "status": "editing"})
        event(task, "dev_worktree_ready", {"run_id": run_id, "repo": repo_name, "branch": branch, "base_sha": base_sha})

        deterministic_edit(task, work, packet)
        allowed = packet.get("allow_paths") or []
        changed = validate_diff(work, allowed)

        patch_table("ops_dev_runs", run_id, {"status": "testing"})
        ok, test_logs = run_tests_isolated(repo_name, work, changed, allowed)
        if not ok:
            raise RuntimeError("pruebas locales fallaron\n" + test_logs)

        # Stage only validated paths.
        rc, out = run(["git", "add", "--", *changed], cwd=str(work), timeout=30)
        if rc:
            raise RuntimeError(out)
        rc, sha_before = run(["git", "rev-parse", "HEAD"], cwd=str(work), timeout=20)
        if rc:
            raise RuntimeError(sha_before)

        risk = task.get("risk") or "guarded"
        auto = False  # Autonomous worker never authorizes its own production release.
        message = f"Control OS task #{task['public_id']} [risk={risk}] [base={base_sha}] [auto_merge={1 if auto else 0}]"
        rc, out = run([
            "git",
            "-c", "user.name=Casa Viva Control OS",
            "-c", "user.email=control-os@local.invalid",
            "commit", "-m", message
        ], cwd=str(work), timeout=45)
        if rc:
            raise RuntimeError("commit falló: " + out)
        rc, work_sha = run(["git", "rev-parse", "HEAD"], cwd=str(work), timeout=20)
        if rc:
            raise RuntimeError(work_sha)
        work_sha = work_sha.strip()

        rc, push = run(["git", "push", "-u", "origin", branch], cwd=str(work), timeout=90)
        if rc:
            raise RuntimeError("push falló: " + push)

        patch_table("ops_dev_runs", run_id, {
            "status": "pushed",
            "branch": branch,
            "base_sha": base_sha,
            "work_sha": work_sha,
            "auto_merge": auto,
            "summary": "Rama enviada; esperando GitHub Control OS Dev Gate.",
            "logs": test_logs[-MAX_LOG:],
        })
        patch_table("ops_tasks", task["id"], {
            "status": "verifying" if auto else "waiting_approval",
            "approval_required": False if auto else True,
            "result": {
                "dev_run_id": run_id,
                "repo": repo_name,
                "branch": branch,
                "base_sha": base_sha,
                "work_sha": work_sha,
                "changed_paths": changed,
                "local_tests": "passed",
                "auto_merge": auto,
            },
            "claim_expires_at": None,
        })
        event(task, "dev_branch_pushed", {"run_id": run_id, "branch": branch, "work_sha": work_sha, "auto_merge": auto, "changed_paths": changed})
    except Exception as exc:
        message = str(exc)[:8000]
        patch_table("ops_dev_runs", run_id, {"status": "failed", "error": message, "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
        patch_table("ops_tasks", task["id"], {"status": "failed", "error": message, "claim_expires_at": None})
        event(task, "dev_failed", {"run_id": run_id, "error": message})
    finally:
        if repo and work and branch:
            cleanup_worktree(repo, work, branch)


def task_for_run(run_row: dict):
    return task_row(run_row["task_id"])


def http_json(url: str):
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Casa-Viva-Control-OS/1"})
        with urllib.request.urlopen(req, timeout=20) as resp:
            raw = resp.read()
            status = resp.status
        return status, json.loads(raw.decode() or "{}")
    except Exception as exc:
        return 0, {"error": str(exc)[:500]}


def nexo_deploy_health(expected_sha: str):
    checks = {
        "web": "https://ernesto-rondon-nexo.onrender.com/api/health",
        "worker": "https://nexo-content-worker.onrender.com/health",
    }
    results = {}
    ok = True
    for name, url in checks.items():
        status, body = http_json(url)
        commit = body.get("gitCommit") if isinstance(body, dict) else None
        healthy = status == 200 and body.get("ok") is True and commit == expected_sha
        results[name] = {"http": status, "ok": body.get("ok") if isinstance(body, dict) else None, "gitCommit": commit, "matches": healthy}
        ok = ok and healthy
    return ok, results


def casa_viva_deploy_health(expected_sha: str):
    cmd = [
        "ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=12",
        "-o", "StrictHostKeyChecking=yes",
        "-i", "/root/.ssh/casa-viva-web", "-p", "65002",
        "u824654880@88.223.85.72",
        "cat /home/u824654880/domains/casaviva.company/public_html/wp-content/plugins/casa-viva-dropship-core/.cvd-deployed-sha 2>/dev/null || true",
    ]
    rc, out = run(cmd, timeout=30)
    deployed = out.strip().splitlines()[0] if out.strip() else None
    return rc == 0 and deployed == expected_sha, {"deployed_sha": deployed, "expected_sha": expected_sha}


def monitor_runs():
    expire_waiting_ai_runs()
    rows = api("GET", "ops_dev_runs?select=*&status=in.(pushed,deploy_queued,deploying)&order=updated_at.asc&limit=20") or []
    for row in rows:
        task = task_for_run(row)
        if not task:
            continue
        repo_name = row["repo"]
        if repo_name not in REPOS:
            continue
        repo = REPOS[repo_name]

        if row["status"] == "pushed":
            run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
            rc, _ = run(["git", "-C", str(repo), "merge-base", "--is-ancestor", row["work_sha"], "origin/main"], timeout=20)
            if rc == 0:
                if repo_name == "vivabot":
                    result = api("POST", "rpc/ops_enqueue_bridge", {
                        "p_task_public_id": task["public_id"],
                        "p_action": "deploy_vivabot",
                        "p_args": {"sha": row["work_sha"]},
                    })
                    bridge_id = result if isinstance(result, int) else (result[0] if isinstance(result, list) and result else None)
                    if isinstance(bridge_id, dict):
                        bridge_id = next(iter(bridge_id.values()), None)
                    if not bridge_id:
                        continue
                    patch_table("ops_dev_runs", row["id"], {"status": "deploy_queued", "deploy_bridge_id": int(bridge_id)})
                    event(task, "dev_ci_gate_passed", {"run_id": row["id"], "work_sha": row["work_sha"], "deploy_bridge_id": int(bridge_id)})
                elif repo_name == "nexo":
                    patch_table("ops_dev_runs", row["id"], {
                        "status": "deploying",
                        "summary": "GitHub Gate pasó; Render auto-deploy detectado. Esperando health con SHA exacto.",
                    })
                    event(task, "dev_ci_gate_passed", {"run_id": row["id"], "work_sha": row["work_sha"], "deploy": "render-auto"})
                elif repo_name == "casa-viva":
                    patch_table("ops_dev_runs", row["id"], {
                        "status": "deploying",
                        "summary": "Gate de rama pasó; esperando CI completo de main + deploy GitHub→Hostinger + SHA desplegado.",
                    })
                    event(task, "dev_ci_gate_passed", {"run_id": row["id"], "work_sha": row["work_sha"], "deploy": "github-hostinger"})
            elif not row.get("auto_merge"):
                # Validated branch intentionally awaits a final approval.
                patch_table("ops_dev_runs", row["id"], {"status": "waiting_approval"})

        elif row["status"] == "deploying" and repo_name == "nexo" and not row.get("deploy_bridge_id"):
            healthy, health = nexo_deploy_health(row["work_sha"])
            if healthy:
                patch_table("ops_dev_runs", row["id"], {
                    "status": "done",
                    "summary": "GitHub Gate y Render deploy verificados por SHA en web + worker.",
                    "logs": json.dumps({"render_health": health}, ensure_ascii=False),
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                })
                patch_table("ops_tasks", task["id"], {
                    "status": "done",
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                    "claimed_by": None,
                    "claimed_at": None,
                    "claim_expires_at": None,
                    "error": None,
                })
                event(task, "dev_deployed", {"run_id": row["id"], "work_sha": row["work_sha"], "deploy": "render-auto", "health": health})
            else:
                started = row.get("started_at")
                age = time.time() - (time.mktime(time.strptime(started[:19], "%Y-%m-%dT%H:%M:%S")) if started else time.time())
                if age > 1200:
                    err = "Render no confirmó el SHA esperado en 20 minutos: " + json.dumps(health, ensure_ascii=False)
                    patch_table("ops_dev_runs", row["id"], {"status": "failed", "error": err, "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
                    patch_table("ops_tasks", task["id"], {"status": "failed", "error": err, "claim_expires_at": None})
                    event(task, "dev_deploy_failed", {"run_id": row["id"], "work_sha": row["work_sha"], "health": health})

        elif row["status"] == "deploying" and repo_name == "casa-viva" and not row.get("deploy_bridge_id"):
            healthy, health = casa_viva_deploy_health(row["work_sha"])
            if healthy:
                patch_table("ops_dev_runs", row["id"], {
                    "status": "done",
                    "summary": "CI completo + deploy GitHub→Hostinger verificados por SHA desplegado.",
                    "logs": json.dumps({"hostinger_health": health}, ensure_ascii=False),
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                })
                patch_table("ops_tasks", task["id"], {
                    "status": "done",
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                    "claimed_by": None,
                    "claimed_at": None,
                    "claim_expires_at": None,
                    "error": None,
                })
                event(task, "dev_deployed", {"run_id": row["id"], "work_sha": row["work_sha"], "deploy": "github-hostinger", "health": health})
            else:
                started = row.get("started_at")
                age = time.time() - (time.mktime(time.strptime(started[:19], "%Y-%m-%dT%H:%M:%S")) if started else time.time())
                if age > 3600:
                    err = "Hostinger no confirmó el SHA esperado en 60 minutos: " + json.dumps(health, ensure_ascii=False)
                    patch_table("ops_dev_runs", row["id"], {"status": "failed", "error": err, "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
                    patch_table("ops_tasks", task["id"], {"status": "failed", "error": err, "claim_expires_at": None})
                    event(task, "dev_deploy_failed", {"run_id": row["id"], "work_sha": row["work_sha"], "health": health})

        elif row["status"] in ("deploy_queued", "deploying") and row.get("deploy_bridge_id"):
            bridge = bridge_row(int(row["deploy_bridge_id"]))
            if not bridge:
                continue
            if bridge["status"] in ("queued", "running"):
                if row["status"] != "deploying" and bridge["status"] == "running":
                    patch_table("ops_dev_runs", row["id"], {"status": "deploying"})
                continue
            if bridge["status"] == "done":
                patch_table("ops_dev_runs", row["id"], {
                    "status": "done",
                    "summary": "CI gate y despliegue completados.",
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                })
                patch_table("ops_tasks", task["id"], {
                    "status": "done",
                    "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                    "claimed_by": None,
                    "claimed_at": None,
                    "claim_expires_at": None,
                    "error": None,
                })
                event(task, "dev_deployed", {"run_id": row["id"], "work_sha": row["work_sha"], "bridge_id": row["deploy_bridge_id"]})
            elif bridge["status"] in ("failed", "blocked"):
                err = bridge.get("error") or "deploy bridge failed"
                patch_table("ops_dev_runs", row["id"], {"status": "failed", "error": err, "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
                patch_table("ops_tasks", task["id"], {"status": "failed", "error": err, "claim_expires_at": None})
                event(task, "dev_deploy_failed", {"run_id": row["id"], "bridge_id": row["deploy_bridge_id"], "error": err})


def main():
    if not sandbox_enabled():
        return  # fail closed before credentials or task claim
    load_credentials()
    os.umask(0o077)
    with open(LOCK, "a") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        # Dedicated invocation of the SAME worker; never claim legacy work in
        # the bounded test-only unit. The ordinary service remains unchanged.
        if os.environ.get("CV_DEV_TEST_ONLY_MODE") == "1":
            if os.environ.get("CV_TEST_ONLY_SANDBOX_CERTIFIED") != "1":
                return
            if "0::/system.slice/cv-test-only-candidate.service" not in Path("/proc/self/cgroup").read_text().splitlines():
                return
            from github_test_only_executor import worker_once
            from github_test_only_launcher import launch
            worker_once(api, launch)
            if os.environ.get("CV_GITHUB_TEST_ONLY_REPORT_ENABLED") == "1":
                from github_contingency import config, github_api
                from github_test_only_reporter import deliver
                cfg = config(os.environ)
                if cfg and cfg[0] == 3:
                    author = os.environ.get("CV_GITHUB_TEST_ONLY_REPORTER_ID", "")
                    if author.isascii() and author.isdecimal():
                        deliver(api, github_api, cfg[1], int(author))
            return
        monitor_runs()
        claims = api("POST", "rpc/ops_claim_dev_task", {"p_worker": WORKER}) or []
        if claims:
            process_claim(claims[0])
        try:
            clean_expired_ai_jobs(AI_MAILBOX)
        except Exception as exc:
            print(f"clean_expired_ai_jobs: {exc}", file=sys.stderr)


if __name__ == "__main__":
    main()
