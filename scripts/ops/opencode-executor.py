#!/usr/bin/env python3
"""Casa Viva Control OS OpenCode Executor v1 (Fase B).

Ejecuta como cvdev. Consume paquetes de trabajo del buzón OpenCode,
aplica ediciones estructuradas sobre un worktree desechable del repo
configurado y produce un paquete de resultados (estado + diff) para
revisión humana.

Invariante de seguridad:
- Nunca hace push, merge, commit, deploy ni ninguna operación de red.
- Nunca ejecuta comandos arbitrarios: los únicos subprocesos permitidos
  son git con subcomandos de la lista blanca (rev-parse, worktree
  add/remove/prune, status, diff, add -N), siempre contra rutas que
  salen de la configuración local, nunca del paquete.
- Nunca lee ni escribe fuera del buzón, del worktree y del repo
  configurado. No accede a rutas del sistema ni a credenciales.
- No confía en la redacción de secretos del productor: revalida todo el
  paquete (versión, identificadores, rutas, tamaños, allowlist) y
  rechaza archivos alterables por grupo/otros.
- Protección contra ejecuciones duplicadas: reclamo atómico (rename),
  flock sobre el reclamo y comprobación de resultado previo.
"""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone

EXECUTOR_VERSION = "opencode-executor-v1"
RESULT_VERSION = "opencode-result-v1"
JOB_VERSION = "opencode-ai-job-v1"
TTL_SECONDS = 45 * 60
TS_FMT = "%Y-%m-%dT%H:%M:%SZ"

ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$")
JOB_NAME_RE = re.compile(r"^task-run-([A-Za-z0-9][A-Za-z0-9_-]{0,63})\.json$")

MAX_JOB_BYTES = 2_000_000
MAX_OPERATIONS = 8
MAX_CONTENT = 120_000
MAX_DETERMINISTIC_CONTENT = 12_000
MAX_DIFF_FILES = 12
MAX_DIFF_LINES = 1_200
MAX_DIFF_CHARS = 200_000
MAX_ERROR = 8_000

ALLOWED_REPOS = {"casa-viva": Path(
    os.environ.get("CV_OPENCODE_REPO_CASA_VIVA", "/home/cvdev/workspace/casa-viva")
)}

GIT_ALLOWED = {
    "rev-parse",
    "worktree",   # segundo nivel: add/remove/prune
    "status",     # --porcelain, lectura
    "diff",       # lectura
    "add",        # solo -N (intent-to-add) para incluir nuevos en el diff
}

PROTECTED_PATHS = (".github/", "package.json", "package-lock.json")

HOME = Path(os.environ.get("CV_OPENCODE_HOME", "/home/cvdev/control-os-dev"))


class SecurityError(ValueError):
    """Violación de una invariante de seguridad del ejecutor."""


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
    """Identificador seguro: sin rutas, sin separadores, longitud acotada."""
    if not isinstance(value, str) or not ID_RE.match(value):
        raise SecurityError(f"identificador no seguro: {str(value)[:80]!r}")
    return value


def safe_rel_path(value) -> str:
    """Ruta relativa estricta: nunca absoluta, nunca traversal, nunca .git."""
    if not isinstance(value, str) or not value:
        raise SecurityError("ruta ausente")
    p = PurePosixPath(value)
    if p.is_absolute() or ".." in p.parts or not p.parts:
        raise SecurityError("ruta no permitida")
    out = str(p)
    if len(out) > 220 or out.startswith(".git") or "/.git" in out:
        raise SecurityError("ruta protegida")
    return out


def path_allowed(path: str, prefixes: list[str]) -> bool:
    for prefix in prefixes:
        if path == prefix or path.startswith(prefix.rstrip("/") + "/"):
            return True
    return False


def check_no_protected_path(path: str) -> None:
    if path in PROTECTED_PATHS or any(path.startswith(p) for p in PROTECTED_PATHS):
        raise SecurityError(f"ruta protegida del repo: {path}")


# --- Buzón ---------------------------------------------------------------

def ensure_mailbox(home: Path) -> dict[str, Path]:
    """Valida el provisionamiento del buzón; nunca lo crea con permisos amplios."""
    home = Path(home)
    if not home.is_dir():
        raise SecurityError(f"buzón no provisionado: {home}")
    mode = stat.S_IMODE(home.stat().st_mode)
    if mode & 0o022:
        raise SecurityError(f"buzón escribible por grupo/otros: {oct(mode)}")
    dirs = {}
    for name in ("jobs", "claims", "results", "dead", "work"):
        path = home / name
        if not path.is_dir():
            raise SecurityError(f"directorio de buzón ausente: {path}")
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o022:
            raise SecurityError(f"directorio {name} escribible por grupo/otros: {oct(mode)}")
        dirs[name] = path
    return dirs


def claim_next_job(dirs: dict[str, Path]) -> tuple[Path, dict] | None:
    """Reclamo atómico: rename jobs → claims. Si el archivo ya no está,
    otro ejecutor lo tomó; nunca se ejecuta dos veces por rename."""
    jobs, claims = dirs["jobs"], dirs["claims"]
    for path in sorted(p for p in jobs.iterdir() if p.is_file()):
        match = JOB_NAME_RE.match(path.name)
        if not match:
            move_to_dead(dirs, path, "nombre de trabajo inválido")
            continue
        run_id = match.group(1)
        try:
            safe_id(run_id)
        except SecurityError as exc:
            move_to_dead(dirs, path, str(exc))
            continue
        if not path.is_file():
            continue
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o022:
            move_to_dead(dirs, path, f"trabajo alterable (permisos {oct(mode)})")
            continue
        try:
            if path.stat().st_size > MAX_JOB_BYTES:
                move_to_dead(dirs, path, "trabajo demasiado grande")
                continue
            raw = path.read_text(encoding="utf-8")
            job = json.loads(raw)
        except (OSError, ValueError) as exc:
            move_to_dead(dirs, path, f"trabajo ilegible: {exc}")
            continue

        claimed = claims / path.name
        try:
            os.rename(path, claimed)
        except OSError:
            continue  # otro ejecutor lo reclamó primero
        os.chmod(claimed, 0o600)
        return claimed, job
    return None


def move_to_dead(dirs: dict[str, Path], path: Path, reason: str) -> None:
    """Cuarentena de revisión: nunca se ejecuta ni se borra silenciosamente."""
    target = dirs["dead"] / path.name
    try:
        os.rename(path, target)
        note = target.with_name(target.name + ".motivo.txt")
        note.write_text(reason[:2000], encoding="utf-8")
    except OSError:
        pass


def move_to_dead_claim(dirs: dict[str, Path], claimed: Path, reason: str) -> None:
    target = dirs["dead"] / claimed.name
    try:
        os.rename(claimed, target)
    except OSError:
        pass


# --- Validación del paquete (no se confía en el productor) ----------------

def validate_job(job, now: float) -> dict:
    if not isinstance(job, dict):
        raise SecurityError("paquete no es un objeto")
    if job.get("version") != JOB_VERSION:
        raise SecurityError("versión de paquete no soportada")
    if job.get("mode") != "ai":
        raise SecurityError("modo no permitido")
    run_id = safe_id(job.get("run_id"))
    task_id = job.get("task_id")
    if not isinstance(task_id, str) or not task_id or len(task_id) > 200:
        raise SecurityError("task_id inválido")
    repo = job.get("repo")
    if repo not in ALLOWED_REPOS:
        raise SecurityError(f"repo no habilitado: {str(repo)[:80]}")
    expires = parse_ts(job.get("expires_at"))
    if expires is None:
        raise SecurityError("expires_at ausente o inválido")
    if expires <= now:
        raise SecurityError("trabajo caducado")

    allowed_keys = {
        "version", "run_id", "task_id", "task_public_id", "repo", "mode",
        "title", "risk", "created_at", "expires_at", "ttl_seconds",
        "instructions", "ignored_packet_keys",
    }
    unknown = sorted(str(key) for key in job if key not in allowed_keys)
    if unknown:
        raise SecurityError(f"claves de paquete no autorizadas: {unknown}")

    instructions = job.get("instructions")
    if not isinstance(instructions, dict):
        raise SecurityError("instructions ausente")
    instruction_keys = {"allow_paths", "operations", "deterministic", "patch_format"}
    unknown = sorted(str(key) for key in instructions if key not in instruction_keys)
    if unknown:
        raise SecurityError(f"claves de instrucciones no autorizadas: {unknown}")

    prefixes_raw = instructions.get("allow_paths")
    if not isinstance(prefixes_raw, list) or not prefixes_raw:
        raise SecurityError("allow_paths ausente o vacío")
    prefixes = []
    for raw in prefixes_raw:
        if not isinstance(raw, str) or not raw:
            raise SecurityError("allow_paths inválido")
        prefixes.append(safe_rel_path(raw))

    operations = instructions.get("operations")
    deterministic = instructions.get("deterministic")
    patch_format = instructions.get("patch_format")
    if operations is not None and deterministic is not None:
        raise SecurityError("operaciones y deterministic son excluyentes")
    if operations is not None:
        if patch_format != "structured-v1":
            raise SecurityError("operaciones requieren patch_format structured-v1")
        validate_operations(operations, prefixes)
    elif deterministic is not None:
        if patch_format is not None:
            raise SecurityError("deterministic no admite patch_format")
        validate_deterministic(deterministic, prefixes)
    else:
        raise SecurityError("instrucciones sin operaciones ni deterministic")
    return job


def validate_operations(operations, prefixes: list[str]) -> None:
    if not isinstance(operations, list) or not 1 <= len(operations) <= MAX_OPERATIONS:
        raise SecurityError(f"operaciones fuera de rango (1..{MAX_OPERATIONS})")
    for op in operations:
        if not isinstance(op, dict):
            raise SecurityError("operación inválida")
        allowed = {"op", "path", "content", "old", "new", "expected_count"}
        unknown = sorted(str(key) for key in op if key not in allowed)
        if unknown:
            raise SecurityError(f"claves de operación no autorizadas: {unknown}")
        kind = op.get("op")
        if kind not in ("create_file", "replace_file", "text_replace"):
            raise SecurityError(f"operación no permitida: {str(kind)[:60]}")
        path = safe_rel_path(op.get("path"))
        check_no_protected_path(path)
        if not path_allowed(path, prefixes):
            raise SecurityError(f"ruta fuera de allow_paths: {path}")
        if kind in ("create_file", "replace_file"):
            content = op.get("content")
            if not isinstance(content, str) or not content or len(content) > MAX_CONTENT:
                raise SecurityError(f"contenido inválido: {path}")
        else:
            old = op.get("old")
            new = op.get("new")
            if not isinstance(old, str) or not old or len(old) > MAX_CONTENT:
                raise SecurityError(f"old inválido: {path}")
            if not isinstance(new, str) or len(new) > MAX_CONTENT:
                raise SecurityError(f"new inválido: {path}")
            expected = op.get("expected_count", 1)
            if not isinstance(expected, int) or isinstance(expected, bool) or not 1 <= expected <= 5:
                raise SecurityError(f"expected_count inválido: {path}")


def validate_deterministic(spec, prefixes: list[str]) -> None:
    if not isinstance(spec, dict) or spec.get("operation") != "write_smoke_file":
        raise SecurityError("operación determinista no habilitada")
    unknown = sorted(str(key) for key in spec if key not in ("operation", "path", "content"))
    if unknown:
        raise SecurityError(f"claves deterministas no autorizadas: {unknown}")
    path = safe_rel_path(spec.get("path"))
    check_no_protected_path(path)
    if not path.startswith("docs/control-os-smoke/") or not path.endswith(".md"):
        raise SecurityError("smoke solo puede escribir docs/control-os-smoke/*.md")
    if not path_allowed(path, prefixes):
        raise SecurityError(f"ruta fuera de allow_paths: {path}")
    content = spec.get("content")
    if not isinstance(content, str) or not content or len(content) > MAX_DETERMINISTIC_CONTENT:
        raise SecurityError("contenido smoke inválido")


# --- Git con lista blanca -------------------------------------------------

def git(args: list[str], cwd: Path, timeout: int = 30) -> tuple[int, str]:
    """Subproceso git único permitido: subcomando de lista blanca, sin shell."""
    if not args or args[0] not in GIT_ALLOWED:
        raise SecurityError(f"subcomando git no permitido: {str(args[:1])}")
    if args[0] == "worktree" and (len(args) < 2 or args[1] not in ("add", "remove", "prune")):
        raise SecurityError(f"subcomando git no permitido: {args[:2]}")
    if args[0] == "add":
        if "-N" not in args or any(a in ("-A", "--all", "--force") for a in args):
            raise SecurityError("git add restringido a -N")
    cmd = ["git", *args]
    proc = subprocess.run(cmd, cwd=str(cwd), capture_output=True, text=True, timeout=timeout)
    out = (proc.stdout or "") + (("\n" + proc.stderr) if proc.stderr else "")
    return proc.returncode, out


# --- Workspace ------------------------------------------------------------

def workspace_target(work: Path, rel: str) -> Path:
    """Resolución segura dentro del workspace; rechaza symlink escape."""
    path = safe_rel_path(rel)
    current = work
    for part in PurePosixPath(path).parts[:-1]:
        current = current / part
        if current.exists() and current.is_symlink():
            raise SecurityError(f"ruta pasa por symlink: {path}")
    target = work / path
    if target.exists() and target.is_symlink():
        raise SecurityError(f"target symlink no permitido: {path}")
    resolved_root = work.resolve()
    resolved = target.parent.resolve() if target.parent.exists() else resolved_root
    try:
        resolved.relative_to(resolved_root)
    except ValueError:
        raise SecurityError(f"resolución fuera del workspace: {path}")
    return target


def prepare_worktree(repo: Path, work_root: Path, run_id: str) -> tuple[Path, str]:
    repo = Path(repo)
    if not (repo / ".git").exists():
        raise SecurityError("repo configurado no es un checkout git")
    work = work_root / f"run-{run_id}"
    if work.exists():
        shutil.rmtree(work, ignore_errors=True)
    rc, out = git(["rev-parse", "HEAD"], cwd=repo)
    if rc:
        raise SecurityError("repo sin HEAD: " + out.strip()[:200])
    base_sha = out.strip().splitlines()[0]
    rc, out = git(["worktree", "add", "--detach", str(work), base_sha], cwd=repo, timeout=60)
    if rc:
        raise SecurityError("worktree add falló: " + out.strip()[:500])
    mode = stat.S_IMODE(work.stat().st_mode)
    if mode & 0o022:
        raise SecurityError(f"workspace con permisos inseguros: {oct(mode)}")
    return work, base_sha


def cleanup_worktree(repo: Path, work: Path) -> None:
    try:
        git(["worktree", "remove", "--force", str(work)], cwd=repo, timeout=60)
    except Exception:
        shutil.rmtree(work, ignore_errors=True)
    try:
        git(["worktree", "prune"], cwd=repo, timeout=30)
    except Exception:
        pass


# --- Ediciones ------------------------------------------------------------

def apply_operations(work: Path, instructions: dict) -> list[str]:
    operations = instructions["operations"]
    prefixes = instructions["allow_paths"]
    changed = []
    for op in operations:
        kind = op["op"]
        rel = safe_rel_path(op["path"])
        if not path_allowed(rel, prefixes):
            raise SecurityError(f"ruta fuera de allow_paths: {rel}")
        check_no_protected_path(rel)
        target = workspace_target(work, rel)
        if kind == "create_file":
            if target.exists():
                raise SecurityError(f"create_file ya existe: {rel}")
            content = op["content"]
            if len(content) > MAX_CONTENT:
                raise SecurityError(f"contenido demasiado grande: {rel}")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")
        elif kind == "replace_file":
            if not target.is_file():
                raise SecurityError(f"replace_file no existe: {rel}")
            content = op["content"]
            if len(content) > MAX_CONTENT:
                raise SecurityError(f"contenido demasiado grande: {rel}")
            target.write_text(content, encoding="utf-8")
        elif kind == "text_replace":
            if not target.is_file():
                raise SecurityError(f"text_replace no existe: {rel}")
            old, new = op["old"], op["new"]
            expected = op.get("expected_count", 1)
            before = target.read_text(encoding="utf-8")
            count = before.count(old)
            if count != expected:
                raise SecurityError(f"{rel}: esperaba {expected} coincidencia(s), encontré {count}")
            target.write_text(before.replace(old, new), encoding="utf-8")
        else:
            raise SecurityError(f"operación no permitida: {kind}")
        changed.append(rel)
    return sorted(set(changed))


def apply_deterministic(work: Path, instructions: dict) -> list[str]:
    spec = instructions["deterministic"]
    rel = safe_rel_path(spec["path"])
    check_no_protected_path(rel)
    target = workspace_target(work, rel)
    if target.exists():
        raise SecurityError(f"smoke ya existe: {rel}")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(spec["content"], encoding="utf-8")
    return [rel]


def collect_diff(repo: Path, work: Path, expected: list[str]) -> tuple[list[str], str]:
    """Verifica que solo cambiaron los archivos esperados y produce el diff."""
    rc, out = git(["status", "--porcelain", "-uall"], cwd=work)
    if rc:
        raise SecurityError("git status falló")
    actual = set()
    for line in out.splitlines():
        if len(line) < 4:
            continue
        rel = line[3:].strip().strip('"')
        if rel:
            actual.add(safe_rel_path(rel))
    if actual != set(expected):
        raise SecurityError(
            "cambios inesperados en el workspace: "
            + ", ".join(sorted(actual ^ set(expected))[:10])
        )
    if len(actual) > MAX_DIFF_FILES:
        raise SecurityError("demasiados archivos cambiados")

    rc, out = git(["diff", "--check"], cwd=work)
    if rc:
        raise SecurityError("git diff --check falló: " + out[:300])

    for rel in expected:
        rc, _ = git(["add", "-N", "--", rel], cwd=work)
        if rc:
            raise SecurityError(f"git add -N falló: {rel}")

    rc, diff = git(["diff"], cwd=work)
    if rc:
        raise SecurityError("git diff falló")
    diff = diff.rstrip("\n")
    if diff.count("\n") + 1 > MAX_DIFF_LINES:
        raise SecurityError("diff demasiado grande")
    if len(diff) > MAX_DIFF_CHARS:
        diff = diff[:MAX_DIFF_CHARS] + "\n[DIFF TRUNCADO]"
    return sorted(actual), diff


# --- Resultados -----------------------------------------------------------

def write_result(dirs: dict[str, Path], result: dict) -> Path:
    """Entrega atómica del resultado: tmp + rename, 0600. Solo cvdev lo escribe;
    el worker root lo lee vía directorio (provisionamiento documentado)."""
    run_id = safe_id(result.get("run_id"))
    path = dirs["results"] / f"task-run-{run_id}.json"
    tmp = path.with_name(f"{path.name}.tmp-{os.getpid()}")
    tmp.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True), encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)
    return path


def base_result(job: dict, status: str, now: float) -> dict:
    return {
        "version": RESULT_VERSION,
        "executor": EXECUTOR_VERSION,
        "run_id": job.get("run_id"),
        "task_id": job.get("task_id"),
        "task_public_id": job.get("task_public_id"),
        "repo": job.get("repo"),
        "status": status,
        "error": None,
        "base_sha": None,
        "changed_paths": [],
        "diff": None,
        "logs": [],
        "finished_at": ts(now),
        "ttl_seconds": TTL_SECONDS,
        "expires_at": ts(now + TTL_SECONDS),
    }


def fail_result(job, now: float, error: Exception | str) -> dict:
    result = base_result(job if isinstance(job, dict) else {}, "failed", now)
    message = error if isinstance(error, str) else (str(error) or error.__class__.__name__)
    result["error"] = message[:MAX_ERROR]
    return result


# --- Proceso de un trabajo ------------------------------------------------

def process_job(dirs: dict[str, Path], claimed: Path, job, now: float) -> tuple[dict, bool]:
    """Ejecuta un trabajo ya reclamado. Devuelve (resultado, escribir).

    Los duplicados bloqueados no devuelven un resultado escribible: nunca
    se sobrescribe un resultado existente.
    """
    if not isinstance(job, dict):
        move_to_dead_claim(dirs, claimed, "paquete no es un objeto")
        return fail_result({}, now, "paquete no es un objeto"), False

    try:
        run_id = safe_id(job.get("run_id"))
    except SecurityError as exc:
        move_to_dead_claim(dirs, claimed, str(exc))
        return fail_result(job, now, exc), False

    expires = parse_ts(job.get("expires_at"))
    if expires is None or expires <= now:
        move_to_dead_claim(dirs, claimed, "trabajo caducado o sin validez: no se ejecuta")
        return fail_result(job, now, "trabajo caducado"), False

    lock_path = dirs["claims"] / f"task-run-{run_id}.lock"
    with open(lock_path, "a") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            move_to_dead_claim(dirs, claimed, "otro ejecutor está procesando este run")
            return fail_result(job, now, "ejecución duplicada bloqueada"), False
        try:
            result_path = dirs["results"] / f"task-run-{run_id}.json"
            if result_path.exists():
                move_to_dead_claim(dirs, claimed, "resultado previo existe: no se re-ejecuta")
                return fail_result(job, now, "ejecución duplicada: resultado previo"), False

            validate_job(job, now)
            repo = ALLOWED_REPOS[job["repo"]]
            work, base_sha = prepare_worktree(repo, dirs["work"], run_id)
            logs = [f"worktree base {base_sha[:12]}"]
            try:
                if "operations" in job["instructions"]:
                    changed = apply_operations(work, job["instructions"])
                else:
                    changed = apply_deterministic(work, job["instructions"])
                changed, diff = collect_diff(repo, work, changed)
                result = base_result(job, "completed", now)
                result["base_sha"] = base_sha
                result["changed_paths"] = changed
                result["diff"] = diff
                result["logs"] = logs + [f"{len(changed)} archivo(s) cambiados, pendiente de revisión"]
                return result, True
            finally:
                cleanup_worktree(repo, work)
        except Exception as exc:
            return fail_result(job, now, exc), True
        finally:
            try:
                lock_path.unlink()
            except OSError:
                pass


def sweep_expired(dirs: dict[str, Path], now: float) -> list[str]:
    """Caducidad coherente: reclamos y resultados vencidos van a dead/,
    nunca se re-ejecutan ni se borran silenciosamente."""
    moved = []
    for claimed in sorted(dirs["claims"].glob("task-run-*.json")):
        try:
            job = json.loads(claimed.read_text(encoding="utf-8"))
            expires = parse_ts(job.get("expires_at")) if isinstance(job, dict) else None
        except (OSError, ValueError):
            expires = None
        run_id = JOB_NAME_RE.match(claimed.name)
        has_result = run_id and (dirs["results"] / claimed.name).exists()
        if has_result or (expires is not None and expires <= now):
            reason = "con resultado" if has_result else "caducado sin ejecutar"
            move_to_dead_claim(dirs, claimed, reason)
            moved.append(claimed.name)
    for result_path in sorted(dirs["results"].glob("task-run-*.json")):
        try:
            result = json.loads(result_path.read_text(encoding="utf-8"))
            expires = parse_ts(result.get("expires_at")) if isinstance(result, dict) else None
        except (OSError, ValueError):
            expires = None
        if expires is None or expires <= now:
            move_to_dead(dirs, result_path, "resultado caducado sin recoger")
            moved.append(result_path.name)
    return moved


def run_once(home: Path, now: float | None = None) -> dict | None:
    """Una pasada del ejecutor: barrido, un reclamo, una ejecución, un resultado."""
    moment = time.time() if now is None else now
    os.umask(0o077)
    dirs = ensure_mailbox(home)
    sweep_expired(dirs, moment)
    claimed_job = claim_next_job(dirs)
    if not claimed_job:
        return None
    claimed, job = claimed_job
    result, should_write = process_job(dirs, claimed, job, moment)
    if should_write:
        try:
            write_result(dirs, result)
        except Exception as exc:
            print(f"write_result: {exc}", file=sys.stderr)
            return result
    try:
        os.rename(claimed, dirs["dead"] / claimed.name)
    except OSError:
        pass
    return result


def main() -> int:
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        print("El ejecutor OpenCode nunca corre como root", file=sys.stderr)
        return 2
    result = run_once(HOME)
    if result is None:
        return 0
    print(json.dumps({"run_id": result.get("run_id"), "status": result.get("status")}))
    return 0 if result.get("status") == "completed" else 1


if __name__ == "__main__":
    sys.exit(main())
