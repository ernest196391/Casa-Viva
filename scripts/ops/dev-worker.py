#!/usr/bin/env python3
"""Casa Viva Control OS Dev Worker v1.

Phase 1: fully autonomous deterministic development for VivaBot.
AI tasks are claimed into waiting_ai but are never executed as root.
No arbitrary shell, repo, path, test command, or deploy target comes from a task.
"""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import time
import urllib.parse
import urllib.request

ROOT = Path("/root/supervisor")
DEV_ROOT = Path("/root/control-os-dev")
LOCK = "/tmp/cv-dev-worker.lock"
WORKER = "cv-dev-worker-v1"
MAX_LOG = 30000

REPOS = {
    "vivabot": Path("/root/vivabot"),
}
TEST_PROFILES = {
    "vivabot": [
        ["npm", "run", "check"],
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


def run(cmd, cwd=None, timeout=120):
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout)
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

    DEV_ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    work = DEV_ROOT / f"task-{task_public_id}"
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


def deterministic_edit(task: dict, work: Path, packet: dict):
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
    target = work / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding="utf-8")
    return [path]


def validate_diff(work: Path, allowed_paths: list[str]):
    rc, names = run(["git", "diff", "--name-only"], cwd=str(work), timeout=20)
    if rc:
        raise RuntimeError(names)
    changed = [x.strip() for x in names.splitlines() if x.strip()]
    if not changed:
        # New untracked files do not appear in git diff.
        rc, others = run(["git", "ls-files", "--others", "--exclude-standard"], cwd=str(work), timeout=20)
        if rc:
            raise RuntimeError(others)
        changed = [x.strip() for x in others.splitlines() if x.strip()]
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


def run_tests(repo_name: str, work: Path):
    logs = []
    if repo_name == "vivabot":
        rc, out = run(["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund"], cwd=str(work), timeout=180)
        logs.append("$ npm ci\n" + out)
        if rc:
            return False, "\n".join(logs)
        for cmd in TEST_PROFILES["vivabot"]:
            rc, out = run(cmd, cwd=str(work), timeout=240)
            logs.append("$ " + " ".join(cmd) + "\n" + out)
            if rc:
                return False, "\n".join(logs)
        php_file = work / "scripts/core-chat-purchase.php"
        php_test = work / "test/core-chat-purchase.php"
        if php_file.exists() and php_test.exists():
            for cmd in (["php", "-l", str(php_file)], ["php", str(php_test)]):
                rc, out = run(cmd, cwd=str(work), timeout=90)
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
        # Hard safety gate: do not run an AI editor as root.
        patch_table("ops_dev_runs", run_id, {
            "status": "waiting_ai",
            "summary": "Esperando sandbox de edición IA; no se ejecutó Claude como root.",
        })
        patch_table("ops_tasks", task["id"], {
            "status": "waiting_ai",
            "claim_expires_at": None,
            "updated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        })
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
        ok, test_logs = run_tests(repo_name, work)
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
        auto = bool(claim.get("auto_merge")) and risk in ("safe", "guarded")
        message = f"Control OS task #{task['public_id']} [risk={risk}] [base={base_sha}] [auto_merge={1 if auto else 0}]"
        rc, out = run(["git", "commit", "-m", message], cwd=str(work), timeout=45)
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


def monitor_runs():
    rows = api("GET", "ops_dev_runs?select=*&status=in.(pushed,deploy_queued,deploying)&order=updated_at.asc&limit=20") or []
    for row in rows:
        task = task_for_run(row)
        if not task:
            continue
        repo_name = row["repo"]
        if repo_name != "vivabot":
            continue
        repo = REPOS["vivabot"]

        if row["status"] == "pushed":
            run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
            rc, _ = run(["git", "-C", str(repo), "merge-base", "--is-ancestor", row["work_sha"], "origin/main"], timeout=20)
            if rc == 0:
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
            elif not row.get("auto_merge"):
                # Validated branch intentionally awaits a final approval.
                patch_table("ops_dev_runs", row["id"], {"status": "waiting_approval"})

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
    os.umask(0o077)
    with open(LOCK, "a") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        monitor_runs()
        claims = api("POST", "rpc/ops_claim_dev_task", {"p_worker": WORKER}) or []
        if claims:
            process_claim(claims[0])


if __name__ == "__main__":
    main()
