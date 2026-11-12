#!/usr/bin/env python3
"""Casa Viva Ops Bridge v2.

Polls a private Supabase queue and executes only a fixed allowlist of operations.
It never executes arbitrary shell supplied by the queue. Write actions are narrow,
preconditioned and reversible.

Expected runtime: root VPS (VivaBot/supervisor host).
"""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

LOCK = "/tmp/cv-ops-bridge.lock"
ENV_FILES = [Path("/root/vivabot/.env"), Path("/root/supervisor/.env")]
WP_PATH = "/home/u824654880/domains/casaviva.company/public_html"
WP_SSH = [
    "ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=12",
    "-o", "StrictHostKeyChecking=yes",
    "-i", "/root/.ssh/casa-viva-web", "-p", "65002",
    "u824654880@88.223.85.72",
]
MAX_OUTPUT = 50000


def load_env_files() -> dict[str, str]:
    env = dict(os.environ)
    for path in ENV_FILES:
        if not path.exists():
            continue
        for raw in path.read_text(errors="replace").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip().strip("'").strip('"')
            env.setdefault(key, value)
    return env


def find_supabase(env: dict[str, str]) -> tuple[str, str]:
    url_candidates = [
        "SUPABASE_URL", "NEXT_PUBLIC_SUPABASE_URL", "VIVABOT_SUPABASE_URL",
    ]
    key_candidates = [
        "SUPABASE_SERVICE_ROLE_KEY", "SUPABASE_SERVICE_KEY", "SUPABASE_SECRET_KEY",
        "SERVICE_ROLE_KEY", "VIVABOT_SUPABASE_SERVICE_ROLE_KEY",
    ]
    url = next((env.get(k) for k in url_candidates if env.get(k)), None)
    key = next((env.get(k) for k in key_candidates if env.get(k)), None)

    if not url:
        for k, v in env.items():
            if "SUPABASE" in k.upper() and k.upper().endswith("_URL") and v.startswith("http"):
                url = v
                break
    if not key:
        for k, v in env.items():
            ku = k.upper()
            if ("SUPABASE" in ku or "SERVICE_ROLE" in ku) and ("SECRET" in ku or "SERVICE_ROLE" in ku):
                if len(v) > 20:
                    key = v
                    break

    if not url or not key:
        raise RuntimeError("No encontré URL/clave privilegiada de Supabase en los env permitidos")
    return url.rstrip("/"), key


def api_request(base: str, key: str, method: str, path: str, body=None, prefer=None):
    data = None if body is None else json.dumps(body).encode()
    headers = {
        "apikey": key,
        "Authorization": f"Bearer {key}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }
    if prefer:
        headers["Prefer"] = prefer
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=20) as resp:
        payload = resp.read()
        return json.loads(payload.decode() or "null") if payload else None


def run(cmd: list[str], timeout=45, cwd=None) -> dict:
    p = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    out = (p.stdout or "") + (("\nSTDERR:\n" + p.stderr) if p.stderr else "")
    return {"returncode": p.returncode, "output": out[:MAX_OUTPUT]}


def wp_remote(command: str, timeout=45) -> dict:
    # command is static and built only by allowlisted actions below.
    return run(WP_SSH + [f"cd {WP_PATH} && {command}"], timeout=timeout)


def action_health(args):
    return run(["bash", "-lc", "cv salud --ahora"], timeout=60)


def action_errors_open(args):
    return run(["bash", "-lc", "cv errores abiertos"], timeout=60)


def action_pm2_status(args):
    p = run(["pm2", "jlist"], timeout=30)
    if p["returncode"] != 0:
        return p
    try:
        rows = json.loads(p["output"])
        slim = []
        for row in rows:
            env = row.get("pm2_env", {})
            slim.append({
                "name": row.get("name"),
                "status": env.get("status"),
                "restart_time": env.get("restart_time"),
                "pm_uptime": env.get("pm_uptime"),
            })
        return {"returncode": 0, "output": json.dumps(slim, ensure_ascii=False)}
    except Exception:
        return p


def action_supervisor_status(args):
    root = Path("/root/supervisor")
    data = {}
    for name in ("active-supervisor", "codex-status.json", "codex-health.json"):
        path = root / name
        if path.exists():
            data[name] = path.read_text(errors="replace")[:10000]
    return {"returncode": 0, "output": json.dumps(data, ensure_ascii=False)}


def action_wp_version(args):
    return wp_remote("wp core version")


def action_wp_plugins(args):
    return wp_remote("wp plugin list --fields=name,status,version,update --format=json")


def action_wp_status(args):
    command = (
        "printf 'CORE='; wp core version; "
        "printf 'SITE='; wp option get siteurl; "
        "printf 'PLUGIN='; wp plugin get casa-viva-dropship-core "
        "--fields=name,status,version --format=json"
    )
    return wp_remote(command)


def action_wp_catalog_search(args):
    q = str((args or {}).get("q", "")).strip()
    if not q or len(q) > 80 or any(ord(c) < 32 for c in q):
        return {"returncode": 2, "output": "args.q inválido"}
    # Base64 keeps the user query out of shell syntax. PHP code is fixed.
    import base64
    q64 = base64.b64encode(q.encode()).decode()
    php = (
        "$q=base64_decode(getenv('CVQ'));"
        "$ids=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private'],"
        "'posts_per_page'=>20,'s'=>$q,'fields'=>'ids']);"
        "$o=[];foreach($ids as $id){$p=wc_get_product($id);if(!$p)continue;"
        "$o[]=['id'=>$id,'name'=>$p->get_name(),'status'=>$p->get_status(),"
        "'sku'=>$p->get_sku(),'price'=>$p->get_price(),'stock_status'=>$p->get_stock_status(),"
        "'stock_qty'=>$p->get_stock_quantity()];}"
        "echo json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);"
    )
    php64 = base64.b64encode(php.encode()).decode()
    return wp_remote(
        f"CVQ={q64} wp eval \"$(printf %s {php64} | base64 -d)\"",
        timeout=60,
    )



def action_wp_product(args):
    try:
        product_id = int((args or {}).get("id", 0))
    except (TypeError, ValueError):
        product_id = 0
    if product_id <= 0:
        return {"returncode": 2, "output": "args.id inválido"}
    import base64
    php = r"""
$id=(int)getenv('CVID');
$p=wc_get_product($id);
if(!$p){fwrite(STDERR,"producto no encontrado\n");exit(2);}
$one=function($x){
  $attrs=[];
  foreach($x->get_attributes() as $k=>$a){
    if($a instanceof WC_Product_Attribute){
      $attrs[$k]=['name'=>$a->get_name(),'options'=>$a->get_options(),'variation'=>$a->get_variation(),'taxonomy'=>$a->is_taxonomy()];
    } else {$attrs[$k]=$a;}
  }
  return [
    'id'=>$x->get_id(),'type'=>$x->get_type(),'parent_id'=>$x->get_parent_id(),
    'name'=>$x->get_name(),'status'=>$x->get_status(),'sku'=>$x->get_sku('edit'),
    'price'=>$x->get_price('edit'),'regular_price'=>$x->get_regular_price('edit'),
    'manage_stock'=>$x->managing_stock(),'stock_quantity'=>$x->get_stock_quantity(),
    'stock_status'=>$x->get_stock_status(),'attributes'=>$attrs,
    'variation_attributes'=>$x->is_type('variation')?$x->get_variation_attributes(false):[],
    'retired'=>$x->get_meta('_cvd_retired',true),'hold'=>$x->get_meta('_cvd_hold',true)
  ];
};
$out=['product'=>$one($p),'children'=>[]];
if($p->is_type('variable')){
  foreach($p->get_children() as $cid){
    $v=wc_get_product($cid);
    if($v)$out['children'][]=$one($v);
  }
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
"""
    php64 = base64.b64encode(php.encode()).decode()
    return wp_remote(
        f"CVID={product_id} wp eval \"$(printf %s {php64} | base64 -d)\"",
        timeout=60,
    )



def action_cv_order(args):
    try:
        order_id = int((args or {}).get("id", 0))
    except (TypeError, ValueError):
        order_id = 0
    if order_id <= 0:
        return {"returncode": 2, "output": "args.id inválido"}
    return run(["bash", "-lc", f"cv pedido {order_id}"], timeout=60)


def action_bot_access_audit(args):
    cmd = [
        "bash", "-lc",
        "cd /root/vivabot && "
        "echo '=== CODIGO ==='; "
        "grep -RIn --exclude-dir=node_modules -E 'mi acceso|accessLink|clerk/access|bot/partners|secure_access|gestora.*access' src scripts 2>/dev/null | head -n 160; "
        "echo '=== LOGS ==='; "
        "for f in /root/.pm2/logs/vivabot-out.log /root/.pm2/logs/vivabot-error.log; do "
        "echo ---$f---; [ -f $f ] && tail -n 1200 $f | grep -Ei 'mi acceso|5356885368|access|error|partner' | tail -n 220; "
        "done"
    ]
    return run(cmd, timeout=60)



def action_vivabot_git_status(args):
    repo = Path("/root/vivabot")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/vivabot no es un checkout Git"}
    branch = run(["git", "branch", "--show-current"], timeout=20, cwd=str(repo))
    head = run(["git", "rev-parse", "HEAD"], timeout=20, cwd=str(repo))
    tracked = run(["git", "status", "--porcelain", "--untracked-files=no"], timeout=20, cwd=str(repo))
    untracked = run(["git", "ls-files", "--others", "--exclude-standard"], timeout=20, cwd=str(repo))
    return {
        "returncode": 0 if branch["returncode"] == head["returncode"] == tracked["returncode"] == untracked["returncode"] == 0 else 1,
        "output": json.dumps({
            "bridge_version": BRIDGE_VERSION,
            "branch": branch["output"].strip(),
            "head": head["output"].strip(),
            "tracked_clean": not bool(tracked["output"].strip()),
            "tracked_status": tracked["output"][:12000],
            "untracked_count": len([x for x in untracked["output"].splitlines() if x.strip()]),
            "untracked_preview": untracked["output"][:12000],
        }, ensure_ascii=False),
    }

def _pm2_vivabot_online():
    p = run(["pm2", "jlist"], timeout=30)
    if p["returncode"] != 0:
        return False, p["output"]
    try:
        rows = json.loads(p["output"])
        row = next((x for x in rows if x.get("name") == "vivabot"), None)
        if not row:
            return False, "vivabot no aparece en PM2"
        env = row.get("pm2_env", {})
        return env.get("status") == "online", json.dumps({
            "status": env.get("status"),
            "restart_time": env.get("restart_time"),
            "pm_uptime": env.get("pm_uptime"),
        }, ensure_ascii=False)
    except Exception as exc:
        return False, f"No pude interpretar PM2: {exc}"


def action_deploy_vivabot(args):
    """Fast-forward VivaBot to an approved main SHA, test, reload PM2 and rollback on failure."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo de 40 caracteres"}

    repo = Path("/root/vivabot")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/vivabot no es un checkout Git"}

    status = run(["git", "status", "--porcelain", "--untracked-files=no"], timeout=20, cwd=str(repo))
    if status["returncode"] != 0:
        return status
    if status["output"].strip():
        return {
            "returncode": 3,
            "output": "DESPLIEGUE BLOQUEADO: hay cambios TRACKED locales en /root/vivabot. No se tocó nada.\n"
                      + status["output"][:12000],
        }

    old = run(["git", "rev-parse", "HEAD"], timeout=20, cwd=str(repo))
    branch = run(["git", "branch", "--show-current"], timeout=20, cwd=str(repo))
    if old["returncode"] or branch["returncode"]:
        return {"returncode": 4, "output": (old["output"] + "\n" + branch["output"])[:MAX_OUTPUT]}
    old_sha = old["output"].strip()

    fetched = run(["git", "fetch", "origin", "main"], timeout=90, cwd=str(repo))
    if fetched["returncode"] != 0:
        return fetched

    exists = run(["git", "cat-file", "-e", f"{sha}^{{commit}}"], timeout=20, cwd=str(repo))
    if exists["returncode"] != 0:
        return {"returncode": 5, "output": f"SHA {sha} no existe después de git fetch"}

    # Los respaldos/temporales locales no bloquean, salvo que el SHA objetivo vaya a escribir exactamente esa ruta.
    untracked = run(["git", "ls-files", "--others", "--exclude-standard"], timeout=20, cwd=str(repo))
    target_paths = run(["git", "ls-tree", "-r", "--name-only", sha], timeout=30, cwd=str(repo))
    if untracked["returncode"] != 0 or target_paths["returncode"] != 0:
        return {"returncode": 5, "output": "No pude comprobar conflictos con archivos locales no versionados"}
    local_only = {x.strip() for x in untracked["output"].splitlines() if x.strip()}
    target = {x.strip() for x in target_paths["output"].splitlines() if x.strip()}
    conflicts = sorted(local_only.intersection(target))
    if conflicts:
        return {
            "returncode": 5,
            "output": "DESPLIEGUE BLOQUEADO: archivos locales no versionados chocan con el SHA objetivo:\n"
                      + "\n".join(conflicts[:100]),
        }

    on_main = run(["git", "merge-base", "--is-ancestor", sha, "origin/main"], timeout=20, cwd=str(repo))
    if on_main["returncode"] != 0:
        return {"returncode": 6, "output": f"SHA {sha} no pertenece a origin/main; bloqueado"}

    forward = run(["git", "merge-base", "--is-ancestor", old_sha, sha], timeout=20, cwd=str(repo))
    if forward["returncode"] != 0:
        return {
            "returncode": 7,
            "output": f"DESPLIEGUE BLOQUEADO: producción {old_sha} no es ancestro de {sha}. Hay divergencia; no se fuerza.",
        }

    if old_sha == sha:
        online, pm = _pm2_vivabot_online()
        return {
            "returncode": 0 if online else 8,
            "output": json.dumps({
                "changed": False, "sha": sha, "branch": branch["output"].strip(),
                "pm2": pm, "note": "VivaBot ya estaba en ese SHA",
            }, ensure_ascii=False),
        }

    merged = run(["git", "merge", "--ff-only", sha], timeout=45, cwd=str(repo))
    if merged["returncode"] != 0:
        return merged

    syntax = run(["node", "--check", "src/index.js"], timeout=45, cwd=str(repo))
    focused = run(["npm", "run", "test:owner-change"], timeout=120, cwd=str(repo))
    if syntax["returncode"] != 0 or focused["returncode"] != 0:
        run(["git", "reset", "--hard", old_sha], timeout=45, cwd=str(repo))
        return {
            "returncode": 9,
            "output": "PRUEBAS FALLARON; código devuelto al SHA anterior antes de recargar PM2.\n"
                      + syntax["output"] + "\n" + focused["output"],
        }

    reload_result = run(["pm2", "reload", "vivabot", "--update-env"], timeout=45)
    if reload_result["returncode"] != 0:
        run(["git", "reset", "--hard", old_sha], timeout=45, cwd=str(repo))
        run(["pm2", "reload", "vivabot", "--update-env"], timeout=45)
        return {
            "returncode": 10,
            "output": "PM2 no pudo recargar el nuevo código. Se restauró el SHA anterior.\n"
                      + reload_result["output"],
        }

    time.sleep(5)
    online, pm = _pm2_vivabot_online()
    if not online:
        rollback_git = run(["git", "reset", "--hard", old_sha], timeout=45, cwd=str(repo))
        rollback_pm2 = run(["pm2", "reload", "vivabot", "--update-env"], timeout=45)
        return {
            "returncode": 11,
            "output": "HEALTH CHECK FALLÓ. Rollback automático ejecutado.\n"
                      + "PM2 nuevo: " + pm + "\n"
                      + "Rollback Git: " + rollback_git["output"] + "\n"
                      + "Rollback PM2: " + rollback_pm2["output"],
        }

    final = run(["git", "rev-parse", "HEAD"], timeout=20, cwd=str(repo))
    return {
        "returncode": 0,
        "output": json.dumps({
            "changed": True,
            "old_sha": old_sha,
            "new_sha": final["output"].strip(),
            "requested_sha": sha,
            "branch": branch["output"].strip(),
            "tests": "node --check src/index.js + npm run test:owner-change",
            "pm2": pm,
            "rollback_sha": old_sha,
        }, ensure_ascii=False),
    }



def action_bridge_status(args):
    return {
        "returncode": 0,
        "output": json.dumps({
            "bridge_version": BRIDGE_VERSION,
            "path": str(Path(__file__).resolve()),
            "actions": sorted(ACTIONS.keys()) if "ACTIONS" in globals() else [],
        }, ensure_ascii=False),
    }


def action_update_bridge(args):
    """Update this bridge only from a full Casa-Viva main commit SHA."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}

    url = f"https://raw.githubusercontent.com/ernest196391/Casa-Viva/{sha}/scripts/ops/cv-ops-bridge.py"
    target = Path(__file__).resolve()
    tmp = target.with_name(target.name + ".next")

    try:
        with urllib.request.urlopen(url, timeout=30) as resp:
            body = resp.read()
        if len(body) < 5000 or b"Casa Viva Ops Bridge" not in body or b"def main(" not in body:
            return {"returncode": 3, "output": "El archivo remoto no parece un bridge válido"}
        tmp.write_bytes(body)
        os.chmod(tmp, 0o700)
        checked = run(["python3", "-m", "py_compile", str(tmp)], timeout=30)
        if checked["returncode"] != 0:
            tmp.unlink(missing_ok=True)
            return {"returncode": 4, "output": "El bridge nuevo no compila:\\n" + checked["output"]}
        backup = target.with_name(target.name + ".prev")
        if target.exists():
            backup.write_bytes(target.read_bytes())
            os.chmod(backup, 0o700)
        os.replace(tmp, target)
        return {
            "returncode": 0,
            "output": json.dumps({
                "updated": True,
                "sha": sha,
                "target": str(target),
                "backup": str(backup),
                "note": "El siguiente ciclo del cron usará la nueva versión.",
            }, ensure_ascii=False),
        }
    except Exception as exc:
        try:
            tmp.unlink(missing_ok=True)
        except Exception:
            pass
        return {"returncode": 5, "output": f"No pude actualizar el bridge: {exc}"}


def action_ai_audit(args):
    cmd = [
        "bash", "-lc",
        "crontab -l | grep -Ein 'claude|codex|anthropic|supervisor|catalogo-fichas|revision-errores|arrancar-sesion' || true",
    ]
    return run(cmd, timeout=30)


def action_catalog_sync_status(args):
    cmd = [
        "bash", "-lc",
        "for f in /root/supervisor/logs/catalogo-fichas.log /root/supervisor/control-sync.log; "
        "do echo ===$f===; [ -f $f ] && tail -n 80 $f; done",
    ]
    return run(cmd, timeout=30)


ACTIONS = {
    "health": action_health,
    "errors_open": action_errors_open,
    "supervisor_status": action_supervisor_status,
    "pm2_status": action_pm2_status,
    "wp_status": action_wp_status,
    "wp_plugins": action_wp_plugins,
    "wp_version": action_wp_version,
    "wp_catalog_search": action_wp_catalog_search,
    "wp_product": action_wp_product,
    "cv_order": action_cv_order,
    "bot_access_audit": action_bot_access_audit,
    "vivabot_git_status": action_vivabot_git_status,
    "deploy_vivabot": action_deploy_vivabot,
    "bridge_status": action_bridge_status,
    "update_bridge": action_update_bridge,
    "ai_audit": action_ai_audit,
    "catalog_sync_status": action_catalog_sync_status,
}


def patch(base, key, task_id: int, payload: dict, queued_only=False):
    filt = f"id=eq.{task_id}"
    if queued_only:
        filt += "&status=eq.queued"
    return api_request(
        base, key, "PATCH",
        f"/rest/v1/crm_ops_bridge?{filt}",
        payload,
        prefer="return=representation",
    )


def main():
    os.umask(0o077)
    Path(LOCK).touch(mode=0o600, exist_ok=True)
    with open(LOCK, "r+") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        env = load_env_files()
        base, key = find_supabase(env)
        rows = api_request(
            base, key, "GET",
            "/rest/v1/crm_ops_bridge?status=eq.queued&select=id,action,args&order=created_at.asc&limit=1",
        )
        if not rows:
            return 0

        task = rows[0]
        task_id = int(task["id"])
        action = str(task["action"])
        claimed = patch(base, key, task_id, {"status": "running", "started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}, True)
        if not claimed:
            return 0

        fn = ACTIONS.get(action)
        if fn is None:
            patch(base, key, task_id, {
                "status": "blocked",
                "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "error": "Acción no permitida por el worker",
            })
            return 0

        try:
            result = fn(task.get("args") or {})
            ok = int(result.get("returncode", 1)) == 0
            patch(base, key, task_id, {
                "status": "done" if ok else "failed",
                "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "result": result,
                "error": None if ok else "La acción devolvió código distinto de cero",
            })
        except Exception as exc:
            patch(base, key, task_id, {
                "status": "failed",
                "finished_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "error": f"{type(exc).__name__}: {exc}"[:2000],
            })
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
