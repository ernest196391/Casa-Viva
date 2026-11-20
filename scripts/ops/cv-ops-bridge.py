#!/usr/bin/env python3
"""Casa Viva Ops Bridge v3.

Polls a private Supabase queue and executes only a fixed allowlist of operations.
It never executes arbitrary shell supplied by the queue. Write actions are narrow,
preconditioned and reversible.

Expected runtime: root VPS (VivaBot/supervisor host).
"""
from __future__ import annotations

import fcntl
import io
import json
import re
import os
from pathlib import Path
import subprocess
import sys
import tarfile
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
BRIDGE_VERSION = "4.1.3"


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


def _rollback_vivabot(repo, deployed_sha, old_sha, expected_branch, reload_pm2=False):
    """Recover only the exact clean deployment; never discard concurrent edits."""
    cwd = str(repo)
    checks = [
        (["git", "rev-parse", "HEAD"], deployed_sha),
        (["git", "branch", "--show-current"], expected_branch),
        (["git", "status", "--porcelain", "--untracked-files=no"], ""),
    ]
    for command, expected in checks:
        result = run(command, timeout=20, cwd=cwd)
        if result["returncode"] or result["output"].strip() != expected:
            return {"returncode": 1, "output": "Rollback bloqueado: HEAD, rama o cambios locales no coinciden; revisión manual."}
    # --keep refuses to overwrite local changes; also refuses untracked path collisions.
    reset = run(["git", "reset", "--keep", old_sha], timeout=45, cwd=cwd)
    if reset["returncode"]:
        return {"returncode": 1, "output": "Rollback bloqueado por Git: " + reset["output"]}
    restored = run(["git", "rev-parse", "HEAD"], timeout=20, cwd=cwd)
    if restored["returncode"] or restored["output"].strip() != old_sha:
        return {"returncode": 1, "output": "Rollback no certificado: SHA anterior no comprobado."}
    if reload_pm2:
        restart = run(["pm2", "reload", "vivabot"], timeout=45)
        if restart["returncode"]:
            return {"returncode": 1, "output": "Código restaurado; PM2 no recargó: " + restart["output"]}
        online, detail = _pm2_vivabot_online()
        if not online:
            return {"returncode": 1, "output": "Código restaurado; PM2 no está online: " + detail}
    return {"returncode": 0, "output": "SHA anterior restaurado y comprobado."}


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
        rollback = _rollback_vivabot(repo, sha, old_sha, branch["output"].strip())
        return {
            "returncode": 9,
            "output": "PRUEBAS FALLARON. " + rollback["output"] + "\n"
                      + syntax["output"] + "\n" + focused["output"],
        }

    reload_result = run(["pm2", "reload", "vivabot", "--update-env"], timeout=45)
    if reload_result["returncode"] != 0:
        rollback = _rollback_vivabot(repo, sha, old_sha, branch["output"].strip(), reload_pm2=True)
        return {
            "returncode": 10,
            "output": "PM2 no pudo recargar el nuevo código. " + rollback["output"] + "\n"
                      + reload_result["output"],
        }

    time.sleep(5)
    online, pm = _pm2_vivabot_online()
    if not online:
        rollback = _rollback_vivabot(repo, sha, old_sha, branch["output"].strip(), reload_pm2=True)
        return {
            "returncode": 11,
            "output": "HEALTH CHECK FALLÓ. " + rollback["output"] + "\n"
                      + "PM2 nuevo: " + pm,
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



def action_bridge_service_status(args):
    p = run(["systemctl", "is-active", "cv-ops-bridge.service"], timeout=20)
    q = run(["systemctl", "is-enabled", "cv-ops-bridge.service"], timeout=20)
    return {
        "returncode": 0 if p["output"].strip() == "active" else 1,
        "output": json.dumps({
            "active": p["output"].strip(),
            "enabled": q["output"].strip(),
        }, ensure_ascii=False),
    }


def action_install_bridge_service(args):
    """Install persistent 3-second worker. Cron may remain as fallback; flock prevents overlap."""
    unit = """[Unit]
Description=Casa Viva Ops Bridge persistent worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/bin/bash -lc 'while true; do /usr/bin/python3 /root/supervisor/cv-ops-bridge.py; sleep 3; done'
Restart=always
RestartSec=2
Nice=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
"""
    path = Path("/etc/systemd/system/cv-ops-bridge.service")
    path.write_text(unit)
    os.chmod(path, 0o644)
    a = run(["systemctl", "daemon-reload"], timeout=30)
    if a["returncode"] != 0:
        return a
    b = run(["systemctl", "enable", "--now", "cv-ops-bridge.service"], timeout=45)
    if b["returncode"] != 0:
        return b
    time.sleep(2)
    c = run(["systemctl", "is-active", "cv-ops-bridge.service"], timeout=20)
    return {
        "returncode": 0 if c["output"].strip() == "active" else 1,
        "output": json.dumps({
            "installed": True,
            "active": c["output"].strip(),
            "unit": str(path),
            "poll_seconds": 3,
            "cron_fallback_kept": True,
        }, ensure_ascii=False),
    }



def action_update_control_sync(args):
    """Install the canonical Control OS portal importer from an approved Casa-Viva main SHA."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}

    # Verify SHA belongs to Casa-Viva main using git ls-remote + GitHub raw availability.
    url = f"https://raw.githubusercontent.com/ernest196391/Casa-Viva/{sha}/scripts/ops/control-os-sync.py"
    target = Path("/root/supervisor/control-sync.py")
    tmp = target.with_name(target.name + ".next")
    try:
        with urllib.request.urlopen(url, timeout=30) as resp:
            body = resp.read()
        if len(body) < 4000 or b"Casa Viva Control OS portal sync" not in body or b"def main(" not in body:
            return {"returncode": 3, "output": "El archivo remoto no parece el importador Control OS"}
        tmp.write_bytes(body)
        os.chmod(tmp, 0o700)
        checked = run(["python3", "-m", "py_compile", str(tmp)], timeout=30)
        if checked["returncode"] != 0:
            tmp.unlink(missing_ok=True)
            return {"returncode": 4, "output": "El importador nuevo no compila:\n" + checked["output"]}
        backup = target.with_name(target.name + ".legacy")
        if target.exists() and not backup.exists():
            backup.write_bytes(target.read_bytes())
            os.chmod(backup, 0o700)
        os.replace(tmp, target)
        test = run(["python3", str(target), "--once"], timeout=60)
        return {
            "returncode": test["returncode"],
            "output": json.dumps({
                "updated": test["returncode"] == 0,
                "sha": sha,
                "target": str(target),
                "legacy_backup": str(backup),
                "first_run": test["output"][:12000],
            }, ensure_ascii=False),
        }
    except Exception as exc:
        try:
            tmp.unlink(missing_ok=True)
        except Exception:
            pass
        return {"returncode": 5, "output": f"No pude actualizar control-sync: {exc}"}



def action_deploy_control_web(args):
    """Deploy the three allowlisted /control/ web files from an approved Casa-Viva SHA."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}

    names = ("index.php", "app.js", "style.css")
    files = {}
    try:
        for name in names:
            url = f"https://raw.githubusercontent.com/ernest196391/Casa-Viva/{sha}/scripts/ops/control-web/{name}"
            with urllib.request.urlopen(url, timeout=30) as resp:
                body = resp.read()
            if not body or len(body) > 300000:
                return {"returncode": 3, "output": f"{name} vacío o demasiado grande"}
            files[name] = body
    except Exception as exc:
        return {"returncode": 4, "output": f"No pude obtener los archivos del SHA: {exc}"}

    if b"Casa Viva Control OS" not in files["index.php"]:
        return {"returncode": 5, "output": "index.php no contiene la firma esperada de Control OS"}
    if b"function render" not in files["app.js"] or b"--green" not in files["style.css"]:
        return {"returncode": 5, "output": "app.js/style.css no parecen la interfaz Control OS esperada"}

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as archive:
        for name in names:
            info = tarfile.TarInfo(name=name)
            info.size = len(files[name])
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(files[name]))
    payload = buf.getvalue()

    short = sha[:12]
    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    base = "/home/u824654880/domains/casaviva.company/public_html"
    domain = "/home/u824654880/domains/casaviva.company"
    nxt = f"{base}/control-next-{short}"
    current = f"{base}/control"
    backup = f"{domain}/control-backups/control-{stamp}-{short}"

    script = (
        "set -e; "
        f"BASE={base}; DOMAIN={domain}; NEXT={nxt}; CURRENT={current}; BACKUP={backup}; "
        "mkdir -p \"$DOMAIN/control-backups\"; rm -rf \"$NEXT\"; mkdir -p \"$NEXT\"; "
        "tar -xzf - -C \"$NEXT\"; "
        "php -l \"$NEXT/index.php\" >/tmp/cv-control-php-lint.txt; "
        "test -s \"$NEXT/app.js\"; test -s \"$NEXT/style.css\"; "
        "moved=0; "
        "rollback(){ if [ \"$moved\" = 1 ] && [ ! -d \"$CURRENT\" ] && [ -d \"$BACKUP\" ]; then mv \"$BACKUP\" \"$CURRENT\"; fi; }; "
        "trap rollback EXIT; "
        "if [ -d \"$CURRENT\" ]; then mv \"$CURRENT\" \"$BACKUP\"; moved=1; fi; "
        "mv \"$NEXT\" \"$CURRENT\"; moved=2; "
        "chmod 755 \"$CURRENT\"; chmod 644 \"$CURRENT\"/*; "
        "trap - EXIT; "
        "php -l \"$CURRENT/index.php\"; "
        "grep -q 'Casa Viva Control OS' \"$CURRENT/index.php\"; "
        "printf 'BACKUP=%s\\n' \"$BACKUP\"; "
        "sha256sum \"$CURRENT/index.php\" \"$CURRENT/app.js\" \"$CURRENT/style.css\""
    )
    try:
        p = subprocess.run(
            WP_SSH + [script],
            input=payload,
            capture_output=True,
            timeout=60,
        )
        out = (p.stdout or b"").decode(errors="replace")
        err = (p.stderr or b"").decode(errors="replace")
        return {
            "returncode": p.returncode,
            "output": (out + (("\nSTDERR:\n" + err) if err else ""))[:MAX_OUTPUT],
        }
    except Exception as exc:
        return {"returncode": 6, "output": f"Falló el despliegue de Control OS: {exc}"}



def action_control_sync_service_status(args):
    p = run(["systemctl", "is-active", "cv-control-sync.service"], timeout=20)
    q = run(["systemctl", "is-enabled", "cv-control-sync.service"], timeout=20)
    return {
        "returncode": 0 if p["output"].strip() == "active" else 1,
        "output": json.dumps({
            "active": p["output"].strip(),
            "enabled": q["output"].strip(),
        }, ensure_ascii=False),
    }


def action_install_control_sync_service(args):
    """Run the private portal importer/snapshot every 10 seconds; cron remains fallback."""
    unit = """[Unit]
Description=Casa Viva Control OS portal sync
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/bin/bash -lc 'while true; do /usr/bin/python3 /root/supervisor/control-sync.py --once; sleep 10; done'
Restart=always
RestartSec=3
Nice=5
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
"""
    path = Path("/etc/systemd/system/cv-control-sync.service")
    path.write_text(unit)
    os.chmod(path, 0o644)
    a = run(["systemctl", "daemon-reload"], timeout=30)
    if a["returncode"] != 0:
        return a
    b = run(["systemctl", "enable", "--now", "cv-control-sync.service"], timeout=45)
    if b["returncode"] != 0:
        return b
    time.sleep(2)
    s = run(["systemctl", "is-active", "cv-control-sync.service"], timeout=20)
    return {
        "returncode": 0 if s["output"].strip() == "active" else 1,
        "output": json.dumps({
            "installed": True,
            "active": s["output"].strip(),
            "unit": str(path),
            "poll_seconds": 10,
            "cron_fallback_kept": True,
        }, ensure_ascii=False),
    }



def action_dev_environment_audit(args):
    """Read-only audit of tools/repos needed by the future Dev Worker. Never prints credentials."""
    import shutil
    import re

    def safe_remote(repo):
        p = run(["git", "-C", repo, "remote", "get-url", "origin"], timeout=20)
        raw = p["output"].strip()
        if not raw:
            return None
        # Redact embedded credentials/tokens.
        raw = re.sub(r"https://[^/@]+@", "https://***@", raw)
        raw = re.sub(r"https://[^:]+:[^@]+@", "https://***:***@", raw)
        return raw[:500]

    def repo_info(repo):
        path = Path(repo)
        if not (path / ".git").exists():
            return {"exists": False}
        head = run(["git", "-C", repo, "rev-parse", "HEAD"], timeout=20)
        branch = run(["git", "-C", repo, "branch", "--show-current"], timeout=20)
        tracked = run(["git", "-C", repo, "status", "--porcelain", "--untracked-files=no"], timeout=20)
        return {
            "exists": True,
            "head": head["output"].strip(),
            "branch": branch["output"].strip(),
            "tracked_clean": not bool(tracked["output"].strip()),
            "remote": safe_remote(repo),
        }

    tools = {}
    for name in ("git", "gh", "node", "npm", "python3", "php", "codex", "claude"):
        path = shutil.which(name)
        tools[name] = {"present": bool(path), "path": path}

    gh = {"present": bool(shutil.which("gh")), "authenticated": False}
    if gh["present"]:
        p = run(["gh", "auth", "status"], timeout=20)
        txt = re.sub(r"(token:\s*).+", r"\1***", p["output"], flags=re.I)
        txt = re.sub(r"(oauth_token:\s*).+", r"\1***", txt, flags=re.I)
        gh.update({"authenticated": p["returncode"] == 0, "status": txt[:4000]})

    # Do not push. ls-remote only proves read/auth path is usable.
    push_probe = {}
    for repo in ("/root/vivabot",):
        if (Path(repo) / ".git").exists():
            p = run(["git", "-C", repo, "ls-remote", "--heads", "origin", "main"], timeout=30)
            push_probe[repo] = {"remote_read_ok": p["returncode"] == 0}

    return {
        "returncode": 0,
        "output": json.dumps({
            "bridge_version": BRIDGE_VERSION,
            "tools": tools,
            "gh": gh,
            "repos": {
                "vivabot": repo_info("/root/vivabot"),
                "casa_viva": repo_info("/root/Casa-Viva"),
                "nexo": repo_info("/root/ernesto-rondon-nexo"),
            },
            "remote_probe": push_probe,
        }, ensure_ascii=False),
    }



def action_dev_push_probe(args):
    """Dry-run Git push auth for allowlisted repos. Does not create remote refs."""
    repos = {
        "vivabot": "/root/vivabot",
        "casa-viva": "/root/Casa-Viva",
        "nexo": "/root/ernesto-rondon-nexo",
    }
    out = {}
    for name, repo in repos.items():
        path = Path(repo)
        if not (path / ".git").exists():
            out[name] = {"exists": False, "push_dry_run_ok": False}
            continue
        head = run(["git", "-C", repo, "rev-parse", "HEAD"], timeout=20)
        if head["returncode"] != 0:
            out[name] = {"exists": True, "push_dry_run_ok": False, "error": head["output"][:1000]}
            continue
        probe_ref = f"refs/heads/control-os/probe-{name}"
        p = run(
            ["git", "-C", repo, "push", "--dry-run", "origin", f"{head['output'].strip()}:{probe_ref}"],
            timeout=45,
        )
        out[name] = {
            "exists": True,
            "push_dry_run_ok": p["returncode"] == 0,
            "detail": p["output"][:1800],
        }
    return {"returncode": 0, "output": json.dumps(out, ensure_ascii=False)}



def action_dev_claude_probe(args):
    """Minimal non-interactive Claude probe; no tools and no filesystem writes requested."""
    if not Path("/usr/bin/claude").exists():
        return {"returncode": 2, "output": "Claude CLI no está instalado"}
    p = run([
        "/usr/bin/claude", "-p",
        "Responde exactamente con la palabra OK. No uses herramientas.",
        "--max-turns", "1",
        "--output-format", "json",
        "--disallowedTools", "Bash,Edit,Write,NotebookEdit,WebFetch,WebSearch"
    ], timeout=90, cwd="/tmp")
    return p



def action_update_dev_worker(args):
    """Install the Dev Worker only from an approved Casa-Viva SHA."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}

    url = f"https://raw.githubusercontent.com/ernest196391/Casa-Viva/{sha}/scripts/ops/dev-worker.py"
    target = Path("/root/supervisor/dev-worker.py")
    tmp = target.with_name(target.name + ".next")
    try:
        with urllib.request.urlopen(url, timeout=30) as resp:
            body = resp.read()
        if len(body) < 5000 or b"Casa Viva Control OS Dev Worker" not in body or b"def main(" not in body:
            return {"returncode": 3, "output": "El archivo remoto no parece el Dev Worker esperado"}
        tmp.write_bytes(body)
        os.chmod(tmp, 0o700)
        checked = run(["python3", "-m", "py_compile", str(tmp)], timeout=30)
        if checked["returncode"] != 0:
            tmp.unlink(missing_ok=True)
            return {"returncode": 4, "output": "El Dev Worker nuevo no compila:\n" + checked["output"]}
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
            }, ensure_ascii=False),
        }
    except Exception as exc:
        try:
            tmp.unlink(missing_ok=True)
        except Exception:
            pass
        return {"returncode": 5, "output": f"No pude actualizar Dev Worker: {exc}"}


def action_install_dev_worker_service(args):
    """Persistent Dev Worker loop; script itself claims at most one safe task per pass."""
    worker = Path("/root/supervisor/dev-worker.py")
    if not worker.exists():
        return {"returncode": 2, "output": "Falta /root/supervisor/dev-worker.py"}

    unit = """[Unit]
Description=Casa Viva Control OS Dev Worker
After=network-online.target cv-ops-bridge.service
Wants=network-online.target

[Service]
Type=simple
User=root
ExecStart=/bin/bash -lc 'while true; do /usr/bin/python3 /root/supervisor/dev-worker.py; sleep 10; done'
Restart=always
RestartSec=4
Nice=8
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
"""
    path = Path("/etc/systemd/system/cv-dev-worker.service")
    path.write_text(unit)
    os.chmod(path, 0o644)
    a = run(["systemctl", "daemon-reload"], timeout=30)
    if a["returncode"] != 0:
        return a
    b = run(["systemctl", "enable", "--now", "cv-dev-worker.service"], timeout=45)
    if b["returncode"] != 0:
        return b
    time.sleep(2)
    s = run(["systemctl", "is-active", "cv-dev-worker.service"], timeout=20)
    return {
        "returncode": 0 if s["output"].strip() == "active" else 1,
        "output": json.dumps({
            "installed": True,
            "active": s["output"].strip(),
            "unit": str(path),
            "poll_seconds": 10,
        }, ensure_ascii=False),
    }


def action_dev_worker_status(args):
    p = run(["systemctl", "is-active", "cv-dev-worker.service"], timeout=20)
    q = run(["systemctl", "is-enabled", "cv-dev-worker.service"], timeout=20)
    tail = run(["journalctl", "-u", "cv-dev-worker.service", "-n", "20", "--no-pager"], timeout=20)
    return {
        "returncode": 0 if p["output"].strip() == "active" else 1,
        "output": json.dumps({
            "active": p["output"].strip(),
            "enabled": q["output"].strip(),
            "journal": tail["output"][-6000:],
        }, ensure_ascii=False),
    }


def action_core_drift_audit(args):
    """Compare production Core files with origin/main by SHA-256. Read-only except git fetch refs."""
    import hashlib
    import re
    import tempfile

    repo = Path("/root/Casa-Viva")
    prefix = "wordpress/casa-viva-dropship-core"
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/Casa-Viva no es un checkout Git"}

    fetched = run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
    if fetched["returncode"] != 0:
        return fetched
    head = run(["git", "-C", str(repo), "rev-parse", "origin/main"], timeout=20)
    if head["returncode"] != 0:
        return head
    git_sha = head["output"].strip()

    local = {}
    git_version = None
    with tempfile.TemporaryDirectory(prefix="cv-core-main-") as tmp:
        archive = subprocess.Popen(
            ["git", "-C", str(repo), "archive", "origin/main", prefix],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        extract = subprocess.run(
            ["tar", "-x", "-C", tmp],
            stdin=archive.stdout, capture_output=True, timeout=90,
        )
        archive.stdout.close()
        arc_err = archive.stderr.read().decode(errors="replace")
        arc_rc = archive.wait(timeout=20)
        if arc_rc != 0 or extract.returncode != 0:
            return {"returncode": 3, "output": "git archive/tar falló\n" + arc_err + "\n" + extract.stderr.decode(errors="replace")}
        root = Path(tmp) / prefix
        for path in root.rglob("*"):
            if not path.is_file():
                continue
            rel = path.relative_to(root).as_posix()
            if rel in (".cvd-deployed-sha", ".cvd-deployed-archive-sha256") or rel.endswith(".log"):
                continue
            local[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
        main_file = root / "casa-viva-dropship-core.php"
        if main_file.exists():
            m = re.search(r"define\(\s*'CVD_VERSION'\s*,\s*'([^']+)'\s*\)", main_file.read_text(errors="replace"))
            git_version = m.group(1) if m else None

    command = (
        "cd /home/u824654880/domains/casaviva.company/public_html/wp-content/plugins/casa-viva-dropship-core && "
        "find . -type f ! -name '.cvd-deployed-sha' ! -name '.cvd-deployed-archive-sha256' ! -name '*.log' -print0 "
        "| sort -z | xargs -0 sha256sum"
    )
    remote_run = run(WP_SSH + [command], timeout=90)
    if remote_run["returncode"] != 0:
        return remote_run
    remote = {}
    for line in remote_run["output"].splitlines():
        if len(line) < 67:
            continue
        h = line[:64]
        rel = line[66:].strip()
        if rel.startswith("./"):
            rel = rel[2:]
        if re.fullmatch(r"[0-9a-f]{64}", h) and rel:
            remote[rel] = h

    changed = sorted(k for k in (set(local) & set(remote)) if local[k] != remote[k])
    only_live = sorted(set(remote) - set(local))
    only_git = sorted(set(local) - set(remote))
    live_version = wp_remote("wp plugin get casa-viva-dropship-core --field=version")

    return {
        "returncode": 0,
        "output": json.dumps({
            "git_sha": git_sha,
            "git_version": git_version,
            "live_version": live_version["output"].strip(),
            "git_files": len(local),
            "live_files": len(remote),
            "changed_count": len(changed),
            "only_live_count": len(only_live),
            "only_git_count": len(only_git),
            "changed": changed[:400],
            "only_live": only_live[:400],
            "only_git": only_git[:400],
        }, ensure_ascii=False),
    }


def action_dev_agent_status(args):
    """Read-only inventory of coding engines and git tooling available on the VPS."""
    tools_to_check = {
        "git": ["git", "--version"],
        "gh": ["gh", "--version"],
        "claude": ["claude", "--version"],
        "codex": ["codex", "--version"],
        "node": ["node", "--version"],
        "python3": ["python3", "--version"],
    }
    out = {}
    for name, cmd in tools_to_check.items():
        try:
            p = run(cmd, timeout=20)
            out[name] = {
                "installed": p["returncode"] == 0,
                "returncode": p["returncode"],
                "version": p["output"].strip()[:500],
            }
        except Exception as exc:
            out[name] = {"installed": False, "error": str(exc)}
    for path in (
        "/root/supervisor/run.sh",
        "/root/supervisor/CLAUDE.md",
        "/root/supervisor/CODEX-VPS.md",
        "/root/vivabot/AGENTS.md",
    ):
        p = Path(path)
        out[path] = {"exists": p.exists(), "size": p.stat().st_size if p.exists() else 0}
    return {"returncode": 0, "output": json.dumps(out, ensure_ascii=False)}



def action_snapshot_live_core_branch(args):
    """Capture the live Casa Viva Core plugin into a review branch. Never modifies production or main."""
    import hashlib
    import re
    import shutil
    import tempfile

    repo = Path("/root/Casa-Viva")
    prefix = Path("wordpress/casa-viva-dropship-core")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/Casa-Viva no es un checkout Git"}

    fetched = run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
    if fetched["returncode"] != 0:
        return fetched
    base = run(["git", "-C", str(repo), "rev-parse", "origin/main"], timeout=20)
    if base["returncode"] != 0:
        return base
    base_sha = base["output"].strip()

    live_version_result = wp_remote("wp plugin get casa-viva-dropship-core --field=version")
    if live_version_result["returncode"] != 0:
        return live_version_result
    live_version = live_version_result["output"].strip()
    safe_version = re.sub(r"[^0-9A-Za-z._-]+", "-", live_version)[:40] or "unknown"
    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    branch = f"control-os/live-core-{safe_version}-{stamp}"

    with tempfile.TemporaryDirectory(prefix="cv-live-core-") as tmp:
        tmp_path = Path(tmp)
        live_dir = tmp_path / "live"
        live_dir.mkdir(parents=True)

        remote_plugin = "/home/u824654880/domains/casaviva.company/public_html/wp-content/plugins/casa-viva-dropship-core"
        remote_cmd = (
            f"cd {remote_plugin} && "
            "tar -czf - "
            "--exclude='.cvd-deployed-sha' "
            "--exclude='.cvd-deployed-archive-sha256' "
            "--exclude='*.log' "
            "--exclude='*.bak' "
            "--exclude='*.tmp' "
            "."
        )
        p = subprocess.Popen(WP_SSH + [remote_cmd], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        extracted = subprocess.run(
            ["tar", "-xzf", "-", "-C", str(live_dir)],
            stdin=p.stdout, capture_output=True, timeout=120,
        )
        if p.stdout:
            p.stdout.close()
        err = p.stderr.read().decode(errors="replace") if p.stderr else ""
        rc = p.wait(timeout=20)
        if rc != 0 or extracted.returncode != 0:
            return {
                "returncode": 3,
                "output": "No pude capturar Core vivo.\n" + err + "\n" + extracted.stderr.decode(errors="replace"),
            }

        files = [x for x in live_dir.rglob("*") if x.is_file()]
        if not 1 <= len(files) <= 800:
            return {"returncode": 4, "output": f"Cantidad de archivos inesperada: {len(files)}"}
        total = sum(x.stat().st_size for x in files)
        if total > 120 * 1024 * 1024:
            return {"returncode": 4, "output": f"Snapshot demasiado grande: {total} bytes"}

        forbidden_names = {
            ".env", "wp-config.php", "id_rsa", "id_ed25519", "authorized_keys",
            "credentials.json", "service-account.json", "auth.json",
        }
        forbidden_suffixes = (".pem", ".key", ".p12", ".pfx", ".sqlite", ".sqlite3")
        suspicious = []
        token_rx = re.compile(
            rb"(BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY|"
            rb"SUPABASE_SERVICE_ROLE_KEY\s*=|"
            rb"ANTHROPIC_API_KEY\s*=|OPENAI_API_KEY\s*=|"
            rb"AWS_SECRET_ACCESS_KEY\s*=|"
            rb"sk-[A-Za-z0-9_-]{24,})"
        )
        for path in files:
            rel = path.relative_to(live_dir).as_posix()
            if path.name in forbidden_names or path.name.lower().endswith(forbidden_suffixes):
                suspicious.append(rel)
                continue
            if path.stat().st_size <= 2 * 1024 * 1024:
                try:
                    if token_rx.search(path.read_bytes()):
                        suspicious.append(rel + " [token-pattern]")
                except Exception:
                    pass
        if suspicious:
            return {
                "returncode": 5,
                "output": "Snapshot bloqueado por posibles secretos:\n" + "\n".join(suspicious[:100]),
            }

        work = Path("/root/control-os-dev") / f"live-core-{stamp}"
        if work.exists():
            shutil.rmtree(work, ignore_errors=True)
        created = run(
            ["git", "-C", str(repo), "worktree", "add", "--detach", str(work), base_sha],
            timeout=60,
        )
        if created["returncode"] != 0:
            return created
        try:
            switched = run(["git", "switch", "-c", branch], cwd=str(work), timeout=30)
            if switched["returncode"] != 0:
                return switched

            target = work / prefix
            if target.exists():
                shutil.rmtree(target)
            shutil.copytree(live_dir, target)

            status = run(["git", "status", "--short", "--", str(prefix)], cwd=str(work), timeout=30)
            if status["returncode"] != 0:
                return status
            if not status["output"].strip():
                return {
                    "returncode": 0,
                    "output": json.dumps({
                        "changed": False,
                        "base_sha": base_sha,
                        "live_version": live_version,
                        "files": len(files),
                        "bytes": total,
                        "note": "Producción ya coincide con origin/main",
                    }, ensure_ascii=False),
                }

            diff_check = run(["git", "diff", "--check", "--", str(prefix)], cwd=str(work), timeout=45)
            whitespace_warnings = diff_check["output"][:12000] if diff_check["returncode"] != 0 else ""

            add_result = run(["git", "add", "--", str(prefix)], cwd=str(work), timeout=60)
            if add_result["returncode"] != 0:
                return add_result
            commit = run([
                "git",
                "-c", "user.name=Casa Viva Control OS",
                "-c", "user.email=control-os@local.invalid",
                "commit", "-m",
                f"Snapshot live Core {live_version} for reconciliation",
            ], cwd=str(work), timeout=90)
            if commit["returncode"] != 0:
                return commit
            sha = run(["git", "rev-parse", "HEAD"], cwd=str(work), timeout=20)
            if sha["returncode"] != 0:
                return sha
            work_sha = sha["output"].strip()

            push = run(["git", "push", "-u", "origin", branch], cwd=str(work), timeout=120)
            if push["returncode"] != 0:
                return {"returncode": 7, "output": "push falló:\n" + push["output"]}

            changed_files = run(
                ["git", "diff", "--name-only", f"{base_sha}..{work_sha}", "--", str(prefix)],
                cwd=str(work), timeout=30,
            )
            names = [x.strip() for x in changed_files["output"].splitlines() if x.strip()]
            return {
                "returncode": 0,
                "output": json.dumps({
                    "changed": True,
                    "branch": branch,
                    "base_sha": base_sha,
                    "snapshot_sha": work_sha,
                    "live_version": live_version,
                    "files": len(files),
                    "bytes": total,
                    "changed_files": len(names),
                    "changed_preview": names[:200],
                    "whitespace_warnings": whitespace_warnings,
                    "production_modified": False,
                    "main_modified": False,
                }, ensure_ascii=False),
            }
        finally:
            run(["git", "-C", str(repo), "worktree", "remove", "--force", str(work)], timeout=90)
            run(["git", "-C", str(repo), "branch", "-D", branch], timeout=30)



def _github_repo_token(repo: Path) -> str:
    """Use existing Git credential without logging or persisting it."""
    env = load_env_files()
    for name in ("GH_TOKEN", "GITHUB_TOKEN", "GITHUB_PAT", "GIT_TOKEN"):
        value = env.get(name)
        if value and len(value) > 20:
            return value.strip()

    try:
        p = subprocess.run(
            ["git", "-C", str(repo), "credential", "fill"],
            input="protocol=https\nhost=github.com\n\n",
            capture_output=True, text=True, timeout=15,
        )
        if p.returncode == 0:
            values = {}
            for line in (p.stdout or "").splitlines():
                if "=" in line:
                    k, v = line.split("=", 1)
                    values[k.strip()] = v.strip()
            token = values.get("password", "")
            if len(token) > 20:
                return token
    except Exception:
        pass

    # Last resort: extract credential embedded in HTTPS origin, without ever returning/logging URL.
    try:
        p = subprocess.run(
            ["git", "-C", str(repo), "remote", "get-url", "origin"],
            capture_output=True, text=True, timeout=10,
        )
        if p.returncode == 0:
            parsed = urllib.parse.urlsplit((p.stdout or "").strip())
            if parsed.hostname == "github.com" and parsed.password and len(parsed.password) > 20:
                return parsed.password
            if parsed.hostname == "github.com" and parsed.username and len(parsed.username) > 20:
                return parsed.username
    except Exception:
        pass
    raise RuntimeError("No encontré una credencial GitHub utilizable en el VPS")


def _github_api(token: str, method: str, path: str, body=None):
    url = "https://api.github.com" + path
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Bearer " + token)
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    req.add_header("User-Agent", "Casa-Viva-Control-OS")
    with urllib.request.urlopen(req, timeout=30) as resp:
        raw = resp.read()
        return resp.status, (json.loads(raw.decode()) if raw else None)


def action_deploy_casa_viva(args):
    """Dispatch the existing GitHub deploy workflow for one certified plugin SHA."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}

    repo = Path("/root/Casa-Viva")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/Casa-Viva no es un checkout Git"}

    fetched = run(["git", "-C", str(repo), "fetch", "origin", "main"], timeout=90)
    if fetched["returncode"] != 0:
        return fetched
    main_sha = run(["git", "-C", str(repo), "rev-parse", "origin/main"], timeout=20)
    if main_sha["returncode"] != 0:
        return main_sha
    current_main = main_sha["output"].strip()

    if current_main != sha:
        ancestor = run(["git", "-C", str(repo), "merge-base", "--is-ancestor", sha, "origin/main"], timeout=20)
        if ancestor["returncode"] != 0:
            return {"returncode": 3, "output": f"SHA bloqueado: {sha} no es ancestro de origin/main {current_main}"}
        plugin_path = "wordpress/casa-viva-dropship-core"
        requested_tree = run(["git", "-C", str(repo), "rev-parse", f"{sha}:{plugin_path}"], timeout=20)
        current_tree = run(["git", "-C", str(repo), "rev-parse", f"{current_main}:{plugin_path}"], timeout=20)
        if requested_tree["returncode"] != 0 or current_tree["returncode"] != 0:
            return {"returncode": 3, "output": "No pude comparar el árbol del plugin antes del deploy"}
        if requested_tree["output"].strip() != current_tree["output"].strip():
            return {
                "returncode": 3,
                "output": f"SHA bloqueado: origin/main avanzó a {current_main} y el plugin también cambió.",
            }

    try:
        token = _github_repo_token(repo)
        status, payload = _github_api(
            token, "POST",
            "/repos/ernest196391/Casa-Viva/actions/workflows/deploy-prototype.yml/dispatches",
            {"ref": "main", "inputs": {"expected_sha": sha}},
        )
        if status != 204:
            return {"returncode": 4, "output": f"GitHub workflow dispatch devolvió HTTP {status}"}

        time.sleep(4)
        _, runs = _github_api(
            token, "GET",
            "/repos/ernest196391/Casa-Viva/actions/workflows/deploy-prototype.yml/runs?event=workflow_dispatch&per_page=20",
        )
        match = None
        for row in (runs or {}).get("workflow_runs", []):
            # workflow_dispatch checks out ref=main, so head_sha may be current main even when expected_sha is an ancestor.
            if row.get("status") in ("queued", "in_progress") or row.get("created_at"):
                match = {
                    "id": row.get("id"),
                    "head_sha": row.get("head_sha"),
                    "status": row.get("status"),
                    "conclusion": row.get("conclusion"),
                    "url": row.get("html_url"),
                    "created_at": row.get("created_at"),
                }
                break
        return {
            "returncode": 0,
            "output": json.dumps({
                "dispatched": True,
                "expected_sha": sha,
                "current_main": current_main,
                "plugin_tree_verified_equal": True,
                "workflow": "deploy-prototype.yml",
                "run": match,
                "note": "El workflow vuelve a verificar CI/versión/checksum y tiene rollback automático.",
            }, ensure_ascii=False),
        }
    except urllib.error.HTTPError as exc:
        body = exc.read().decode(errors="replace")[:1000]
        return {"returncode": 5, "output": f"GitHub API HTTP {exc.code}: {body}"}
    except Exception as exc:
        return {"returncode": 6, "output": f"No pude despachar deploy: {type(exc).__name__}: {exc}"}

def action_casa_viva_deploy_status(args):
    """Read deploy workflow status using the existing GitHub credential."""
    import re
    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}
    repo = Path("/root/Casa-Viva")
    try:
        token = _github_repo_token(repo)
        _, payload = _github_api(
            token, "GET",
            "/repos/ernest196391/Casa-Viva/actions/workflows/deploy-prototype.yml/runs?event=workflow_dispatch&per_page=30",
        )
        rows = []
        for x in (payload or {}).get("workflow_runs", []):
            rows.append({
                "id": x.get("id"),
                "head_sha": x.get("head_sha"),
                "status": x.get("status"),
                "conclusion": x.get("conclusion"),
                "created_at": x.get("created_at"),
                "updated_at": x.get("updated_at"),
                "url": x.get("html_url"),
            })
        return {"returncode": 0, "output": json.dumps({"expected_sha": sha, "runs": rows[:10]}, ensure_ascii=False)}
    except Exception as exc:
        return {"returncode": 3, "output": f"No pude consultar deploy: {type(exc).__name__}: {exc}"}

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



CVDEV_REPO = Path("/home/cvdev/workspace/casa-viva")
CVDEV_BRANCH = "control-os/opencode-fase-a"
CVDEV_BACKUP = "control-os/backup-pre-rebase-25142ed"


def _cvdev(cmd, timeout=120):
    """Run a fixed Git command as the unprivileged cvdev account."""
    return run(["runuser", "-u", "cvdev", "--", *cmd], cwd=str(CVDEV_REPO), timeout=timeout)


def _cvdev_rebase_meta():
    git_dir = CVDEV_REPO / ".git"
    rm = git_dir / "rebase-merge"
    ra = git_dir / "rebase-apply"
    if rm.is_dir():
        mode = "merge"
        base = rm
    elif ra.is_dir():
        mode = "apply"
        base = ra
    else:
        return {"active": False}
    def read(name):
        try:
            return (base / name).read_text(errors="replace").strip()
        except Exception:
            return ""
    return {
        "active": True,
        "mode": mode,
        "head_name": read("head-name"),
        "onto": read("onto"),
        "msgnum": read("msgnum"),
        "end": read("end"),
        "done": read("done")[-6000:],
    }


def action_cvdev_control_status(args):
    """Read-only status of the dedicated Control OS development checkout."""
    if not (CVDEV_REPO / ".git").exists():
        return {"returncode": 2, "output": "Falta checkout cvdev"}
    branch = _cvdev(["git", "branch", "--show-current"], timeout=20)
    head = _cvdev(["git", "rev-parse", "HEAD"], timeout=20)
    status = _cvdev(["git", "status", "--porcelain=v1"], timeout=20)
    conflicts = _cvdev(["git", "diff", "--name-only", "--diff-filter=U"], timeout=20)
    backup = _cvdev(["git", "rev-parse", "--verify", CVDEV_BACKUP], timeout=20)
    return {
        "returncode": 0,
        "output": json.dumps({
            "repo": str(CVDEV_REPO),
            "branch": branch["output"].strip(),
            "head": head["output"].strip(),
            "status": status["output"][:12000],
            "conflicts": [x for x in conflicts["output"].splitlines() if x.strip()],
            "backup": backup["output"].strip() if backup["returncode"] == 0 else None,
            "rebase": _cvdev_rebase_meta(),
        }, ensure_ascii=False),
    }


def _cvdev_last_done_sha(meta):
    done = str(meta.get("done") or "").splitlines()
    for line in reversed(done):
        m = re.match(r"^(?:pick|edit|reword|squash|fixup)\s+([0-9a-f]{7,40})\b", line.strip())
        if m:
            return m.group(1)
    return None


def action_cvdev_rebase_resume(args):
    """Resume only the pre-authorized Control OS A-G rebase, never arbitrary Git history."""
    if not (CVDEV_REPO / ".git").exists():
        return {"returncode": 2, "output": "Falta checkout cvdev"}
    meta = _cvdev_rebase_meta()
    if not meta.get("active") or meta.get("mode") != "merge":
        return {"returncode": 3, "output": "No hay rebase-merge activo"}
    if meta.get("head_name") != f"refs/heads/{CVDEV_BRANCH}":
        return {"returncode": 4, "output": "Rebase fuera de la rama Control OS autorizada"}
    backup = _cvdev(["git", "rev-parse", "--verify", CVDEV_BACKUP], timeout=20)
    if backup["returncode"] != 0 or backup["output"].strip() != "25142ed89c0fa947a1a7d246d2df775c5865ca31":
        # Accept the known short SHA only if Git cannot expose full historical object identically.
        if not backup["output"].strip().startswith("25142ed"):
            return {"returncode": 5, "output": "Respaldo 25142ed ausente o inesperado"}
    logs = []
    for _ in range(12):
        conflicts = _cvdev(["git", "diff", "--name-only", "--diff-filter=U"], timeout=20)
        if conflicts["returncode"] != 0:
            return conflicts
        if conflicts["output"].strip():
            return {"returncode": 6, "output": "CONFLICTOS; no se modificó nada:\n" + conflicts["output"][:6000]}
        meta = _cvdev_rebase_meta()
        if not meta.get("active"):
            break
        staged = _cvdev(["git", "diff", "--cached", "--quiet"], timeout=20)
        # git diff --cached --quiet => 1 means staged changes exist.
        if staged["returncode"] == 1:
            old_sha = _cvdev_last_done_sha(meta)
            if not old_sha:
                return {"returncode": 7, "output": "Hay cambios staged pero no pude identificar el commit original"}
            chk = _cvdev(["git", "cat-file", "-e", f"{old_sha}^{{commit}}"], timeout=20)
            if chk["returncode"] != 0:
                return {"returncode": 8, "output": f"No existe commit original {old_sha}"}
            made = _cvdev(["git", "-c", "user.name=Casa Viva Control OS", "-c", "user.email=control-os@local.invalid",
                           "commit", "-C", old_sha], timeout=60)
            logs.append("$ git commit -C " + old_sha + "\n" + made["output"])
            if made["returncode"] != 0:
                return {"returncode": 9, "output": "\n".join(logs)[-12000:]}
        cont = _cvdev(["env", "GIT_EDITOR=true", "git", "rebase", "--continue"], timeout=120)
        logs.append("$ git rebase --continue\n" + cont["output"])
        if cont["returncode"] != 0:
            # A shallow interactive rebase can stop after applying the next patch with it staged.
            after = _cvdev_rebase_meta()
            staged2 = _cvdev(["git", "diff", "--cached", "--quiet"], timeout=20)
            conflicts2 = _cvdev(["git", "diff", "--name-only", "--diff-filter=U"], timeout=20)
            if after.get("active") and staged2["returncode"] == 1 and not conflicts2["output"].strip():
                continue
            return {"returncode": 10, "output": "\n".join(logs)[-12000:]}
    if _cvdev_rebase_meta().get("active"):
        return {"returncode": 11, "output": "Rebase no terminó dentro del límite de pasos\n" + "\n".join(logs)[-10000:]}
    branch = _cvdev(["git", "branch", "--show-current"], timeout=20)
    clean = _cvdev(["git", "status", "--porcelain=v1"], timeout=20)
    head = _cvdev(["git", "rev-parse", "HEAD"], timeout=20)
    if branch["output"].strip() != CVDEV_BRANCH or clean["output"].strip():
        return {"returncode": 12, "output": json.dumps({"branch": branch["output"], "status": clean["output"], "head": head["output"]}, ensure_ascii=False)}
    return {"returncode": 0, "output": json.dumps({
        "rebased": True, "branch": CVDEV_BRANCH, "head": head["output"].strip(),
        "log": "\n".join(logs)[-10000:]
    }, ensure_ascii=False)}


def action_cvdev_control_tests(args):
    """Run the fixed Control OS test files as scripts; no task-supplied command is accepted."""
    if _cvdev_rebase_meta().get("active"):
        return {"returncode": 3, "output": "Rebase activo; pruebas bloqueadas"}
    branch = _cvdev(["git", "branch", "--show-current"], timeout=20)
    if branch["output"].strip() != CVDEV_BRANCH:
        return {"returncode": 4, "output": "Rama cvdev inesperada"}
    test_files = [
        "scripts/ops/tests/dev-worker.test.py",
        "scripts/ops/tests/opencode-adapter.test.py",
        "scripts/ops/tests/opencode-demo-e2e.test.py",
        "scripts/ops/tests/opencode-executor.test.py",
        "scripts/ops/tests/opencode-proposal.test.py",
        "scripts/ops/tests/opencode-result-consumer.test.py",
        "scripts/ops/tests/opencode-sandbox.test.py",
    ]
    logs = []
    total = 0
    for rel in test_files:
        p = _cvdev(["python3", rel], timeout=300)
        logs.append("$ python3 " + rel + "\n" + p["output"])
        if p["returncode"] != 0:
            return {"returncode": p["returncode"], "output": "\n".join(logs)[-30000:]}
        m = re.search(r"Ran\s+(\d+)\s+tests?", p["output"])
        if m:
            total += int(m.group(1))
    compiled = _cvdev([
        "python3", "-m", "py_compile",
        "scripts/ops/dev-worker.py",
        "scripts/ops/opencode-adapter.py",
        "scripts/ops/opencode-executor.py",
        "scripts/ops/opencode-proposal.py",
        "scripts/ops/opencode-proposal-bridge.py",
        "scripts/ops/opencode-result-consumer.py",
        "scripts/ops/opencode-demo-e2e.py",
        "scripts/ops/sandbox/opencode-sandbox-spec.py",
        "scripts/ops/sandbox/verify-sandbox.py",
    ], timeout=180)
    logs.append("$ python3 -m py_compile ...\n" + compiled["output"])
    if compiled["returncode"] != 0:
        return {"returncode": compiled["returncode"], "output": "\n".join(logs)[-30000:]}
    return {"returncode": 0, "output": json.dumps({
        "test_files": len(test_files),
        "reported_tests": total,
        "summary": "Control OS tests + py_compile OK",
        "logs": "\n".join(logs)[-24000:]
    }, ensure_ascii=False)}

def action_cvdev_control_push(args):
    """Publish the clean rebased Control OS HEAD through the VPS existing Casa Viva SSH identity. Never force."""
    if _cvdev_rebase_meta().get("active"):
        return {"returncode": 3, "output": "Rebase activo; push bloqueado"}
    branch = _cvdev(["git", "branch", "--show-current"], timeout=20)
    status = _cvdev(["git", "status", "--porcelain=v1"], timeout=20)
    if branch["output"].strip() != CVDEV_BRANCH or status["output"].strip():
        return {"returncode": 4, "output": "Rama inesperada o checkout no limpio"}
    head = _cvdev(["git", "rev-parse", "HEAD"], timeout=20)
    if head["returncode"] != 0:
        return head
    remote_branch = "review/control-os-opencode-a-g"
    # The bridge already runs as root and the VPS already has a dedicated SSH alias/key
    # for Casa Viva. We reuse that identity only for this fixed repository/ref.
    pushed = run([
        "git", "-c", f"safe.directory={CVDEV_REPO}", "-C", str(CVDEV_REPO),
        "push", "git@github-casaviva:ernest196391/Casa-Viva.git",
        f"{head['output'].strip()}:refs/heads/{remote_branch}",
    ], timeout=120)
    return {"returncode": pushed["returncode"], "output": json.dumps({
        "head": head["output"].strip(), "remote_branch": remote_branch,
        "push": pushed["output"][-10000:]
    }, ensure_ascii=False)}


def action_snapshot_vivabot_live_branch(args):
    """Push exact clean live VivaBot HEAD to review after scanning only live-only changes for actual secret values."""
    repo = Path("/root/vivabot")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/vivabot no es un checkout Git"}
    tracked = run(["git", "status", "--porcelain", "--untracked-files=no"], cwd=str(repo), timeout=20)
    untracked = run(["git", "ls-files", "--others", "--exclude-standard"], cwd=str(repo), timeout=20)
    if tracked["returncode"] != 0 or untracked["returncode"] != 0:
        return {"returncode": 3, "output": "No pude comprobar limpieza del repo"}
    if tracked["output"].strip() or untracked["output"].strip():
        return {"returncode": 4, "output": "Snapshot bloqueado: el checkout VivaBot no está limpio"}

    head = run(["git", "rev-parse", "HEAD"], cwd=str(repo), timeout=20)
    if head["returncode"] != 0:
        return head
    live_sha = head["output"].strip()
    fetched = run(["git", "fetch", "origin", "main"], cwd=str(repo), timeout=90)
    if fetched["returncode"] != 0:
        return fetched
    remote = run(["git", "rev-parse", "origin/main"], cwd=str(repo), timeout=20)
    if remote["returncode"] != 0:
        return remote
    remote_sha = remote["output"].strip()
    if live_sha == remote_sha:
        return {"returncode": 0, "output": json.dumps({"changed": False, "head": live_sha, "origin_main": remote_sha}, ensure_ascii=False)}

    changed = run(["git", "diff", "--name-only", "origin/main...HEAD"], cwd=str(repo), timeout=45)
    if changed["returncode"] != 0:
        return {"returncode": 5, "output": "No pude calcular cambios vivos contra origin/main:\n" + changed["output"][:6000]}
    changed_files = [x.strip() for x in changed["output"].splitlines() if x.strip()]
    if not 1 <= len(changed_files) <= 500:
        return {"returncode": 5, "output": f"Cantidad inesperada de archivos cambiados: {len(changed_files)}"}

    forbidden_names = {".env", "id_rsa", "id_ed25519", "authorized_keys", "credentials.json", "auth.json", "service-account.json"}
    forbidden_suffixes = (".pem", ".key", ".p12", ".pfx")
    bad_names = [rel for rel in changed_files
                 if Path(rel).name.lower() in forbidden_names or Path(rel).name.lower().endswith(forbidden_suffixes)]
    if bad_names:
        return {"returncode": 6, "output": "Snapshot bloqueado por archivos sensibles nuevos/modificados:\n" + "\n".join(bad_names[:100])}

    pem_rx = re.compile(rb"BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY")
    assign_rx = re.compile(
        rb"(?:SUPABASE_SERVICE_ROLE_KEY|ANTHROPIC_API_KEY|OPENAI_API_KEY|AWS_SECRET_ACCESS_KEY)"
        rb"\s*=\s*[\"']?([^\s\"']{20,})"
    )
    suspicious = []
    placeholders = (b"example", b"placeholder", b"dummy", b"changeme", b"your_", b"xxxx", b"test_")
    for rel in changed_files:
        blob = subprocess.run(["git", "show", f"HEAD:{rel}"], cwd=str(repo), capture_output=True, timeout=20)
        if blob.returncode != 0:
            # Deleted paths are safe to skip; anything else is surfaced by git show failure.
            exists = subprocess.run(["git", "cat-file", "-e", f"HEAD:{rel}"], cwd=str(repo), capture_output=True)
            if exists.returncode != 0:
                continue
            return {"returncode": 7, "output": f"No pude inspeccionar {rel}"}
        data = blob.stdout
        if len(data) > 3 * 1024 * 1024:
            return {"returncode": 7, "output": f"Archivo cambiado demasiado grande para auditar: {rel}"}
        if pem_rx.search(data):
            suspicious.append(rel + " [private-key]")
            continue
        for m in assign_rx.finditer(data):
            value = m.group(1).lower()
            if not any(p in value for p in placeholders) and len(set(value)) > 4:
                suspicious.append(rel + " [secret-assignment]")
                break
    if suspicious:
        return {"returncode": 8, "output": "Snapshot bloqueado por posibles secretos nuevos:\n" + "\n".join(suspicious[:100])}

    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    review_branch = f"review/live-vivabot-{live_sha[:8]}-{stamp}"
    pushed = run(["git", "push", "origin", f"{live_sha}:refs/heads/{review_branch}"], cwd=str(repo), timeout=120)
    if pushed["returncode"] != 0:
        return pushed
    return {"returncode": 0, "output": json.dumps({
        "changed": True,
        "branch": review_branch,
        "live_sha": live_sha,
        "origin_main": remote_sha,
        "changed_files": changed_files,
        "note": "Snapshot de revisión creado; producción y main no cambiaron."
    }, ensure_ascii=False)}


def action_snapshot_vivabot_dirty_branch(args):
    """Snapshot tracked dirty VivaBot changes without modifying HEAD, index or working tree."""
    repo = Path("/root/vivabot")
    if not (repo / ".git").exists():
        return {"returncode": 2, "output": "/root/vivabot no es un checkout Git"}
    before_head = run(["git", "rev-parse", "HEAD"], cwd=str(repo), timeout=20)
    before_status = run(["git", "status", "--porcelain=v1"], cwd=str(repo), timeout=20)
    untracked = run(["git", "ls-files", "--others", "--exclude-standard"], cwd=str(repo), timeout=20)
    if before_head["returncode"] or before_status["returncode"] or untracked["returncode"]:
        return {"returncode": 3, "output": "No pude auditar el repo vivo"}
    if untracked["output"].strip():
        return {"returncode": 4, "output": "Snapshot dirty bloqueado: existen archivos no rastreados"}
    changed = run(["git", "diff", "--name-only"], cwd=str(repo), timeout=20)
    if changed["returncode"] != 0:
        return changed
    changed_files = [x.strip() for x in changed["output"].splitlines() if x.strip()]
    if not 1 <= len(changed_files) <= 12:
        return {"returncode": 5, "output": f"Cantidad inesperada de archivos dirty: {len(changed_files)}"}
    check = run(["git", "diff", "--check"], cwd=str(repo), timeout=30)
    if check["returncode"] != 0:
        return {"returncode": 6, "output": "git diff --check falló:\n" + check["output"][:6000]}
    num = run(["git", "diff", "--numstat"], cwd=str(repo), timeout=20)
    lines = 0
    for row in num["output"].splitlines():
        parts = row.split("\t")
        if len(parts) >= 2 and parts[0].isdigit() and parts[1].isdigit():
            lines += int(parts[0]) + int(parts[1])
    if lines > 1200:
        return {"returncode": 7, "output": f"Diff dirty demasiado grande: {lines} líneas"}

    forbidden_names = {".env", "id_rsa", "id_ed25519", "authorized_keys", "credentials.json", "auth.json", "service-account.json"}
    forbidden_suffixes = (".pem", ".key", ".p12", ".pfx")
    bad_names = [rel for rel in changed_files
                 if Path(rel).name.lower() in forbidden_names or Path(rel).name.lower().endswith(forbidden_suffixes)]
    if bad_names:
        return {"returncode": 8, "output": "Snapshot bloqueado por nombres sensibles:\n" + "\n".join(bad_names)}

    pem_rx = re.compile(rb"BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY")
    assign_rx = re.compile(
        rb"(?:SUPABASE_SERVICE_ROLE_KEY|ANTHROPIC_API_KEY|OPENAI_API_KEY|AWS_SECRET_ACCESS_KEY)"
        rb"\s*=\s*[\"']?([^\s\"']{20,})"
    )
    placeholders = (b"example", b"placeholder", b"dummy", b"changeme", b"your_", b"xxxx", b"test_")
    suspicious = []
    for rel in changed_files:
        path = repo / rel
        if not path.is_file():
            continue
        data = path.read_bytes()
        if len(data) > 3 * 1024 * 1024:
            return {"returncode": 9, "output": f"Archivo dirty demasiado grande: {rel}"}
        if pem_rx.search(data):
            suspicious.append(rel + " [private-key]")
            continue
        for m in assign_rx.finditer(data):
            value = m.group(1).lower()
            if not any(p in value for p in placeholders) and len(set(value)) > 4:
                suspicious.append(rel + " [secret-assignment]")
                break
    if suspicious:
        return {"returncode": 10, "output": "Snapshot bloqueado por posibles secretos:\n" + "\n".join(suspicious)}

    snap = run(["git", "stash", "create", "Control OS snapshot dirty VivaBot"], cwd=str(repo), timeout=45)
    if snap["returncode"] != 0 or not re.fullmatch(r"[0-9a-f]{40}", snap["output"].strip()):
        return {"returncode": 11, "output": "git stash create no produjo commit válido:\n" + snap["output"][:3000]}
    snapshot_sha = snap["output"].strip()

    # Prove that stash create did not alter the live checkout before publishing.
    after_head = run(["git", "rev-parse", "HEAD"], cwd=str(repo), timeout=20)
    after_status = run(["git", "status", "--porcelain=v1"], cwd=str(repo), timeout=20)
    if after_head["output"].strip() != before_head["output"].strip() or after_status["output"] != before_status["output"]:
        return {"returncode": 12, "output": "Snapshot abortado: el checkout vivo cambió durante la captura"}

    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    review_branch = f"review/live-vivabot-dirty-{snapshot_sha[:8]}-{stamp}"
    pushed = run(["git", "push", "origin", f"{snapshot_sha}:refs/heads/{review_branch}"], cwd=str(repo), timeout=120)
    if pushed["returncode"] != 0:
        return pushed
    return {"returncode": 0, "output": json.dumps({
        "snapshot": True,
        "branch": review_branch,
        "snapshot_sha": snapshot_sha,
        "live_head": before_head["output"].strip(),
        "changed_files": changed_files,
        "changed_lines": lines,
        "checkout_unchanged": True,
    }, ensure_ascii=False)}


def _planner_probe_run(cmd, cwd, timeout=50):
    """Bound the whole process group and retain diagnostic output on timeout."""
    import signal
    proc = subprocess.Popen(cmd, cwd=cwd, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, text=True, start_new_session=True)
    timed_out = False
    try:
        stdout, stderr = proc.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        timed_out = True
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        stdout, stderr = proc.communicate(timeout=5)
    output = ((stdout or "") + ("\nSTDERR:\n" + stderr if stderr else ""))[-16000:]
    output = re.sub(r"(?i)(bearer\s+)[^\s\"']+", r"\1[REDACTED]", output)
    output = re.sub(r"\b(?:sk-|sbp_)[A-Za-z0-9_-]{12,}", "[REDACTED]", output)
    output = re.sub(r"\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+", "[REDACTED]", output)
    return {"returncode": 124 if timed_out else proc.returncode,
            "timed_out": timed_out, "output": output}


def action_opencode_planner_probe(args):
    """Fixed, tool-denied probe: no task-supplied prompt, path, model or command."""
    import pwd
    import tempfile
    binary = Path("/usr/bin/opencode")
    if not binary.exists():
        return {"returncode": 2, "output": "/usr/bin/opencode no existe"}
    cv = pwd.getpwnam("cvdev")
    # Fresh HOME prevents stale config, sessions and plugins affecting diagnosis.
    with tempfile.TemporaryDirectory(prefix="cv-opencode-probe-") as temp:
        probe_dir = Path(temp)
        os.chown(probe_dir, cv.pw_uid, cv.pw_gid)
        config = json.dumps({"permission": {"*": "deny"}})
        env = ["env", "-i", "PATH=/usr/bin:/bin", f"HOME={probe_dir}",
               "LANG=C.UTF-8", "OPENCODE_CONFIG_CONTENT=" + config]
        prefix = ["runuser", "-u", "cvdev", "--", *env]
        version = _planner_probe_run(prefix + [str(binary), "--version"], temp, 10)
        help_run = _planner_probe_run(prefix + [str(binary), "run", "--help"], temp, 10)
        if version["returncode"] or help_run["returncode"]:
            return {"returncode": 3, "output": json.dumps({"stage": "startup",
                    "version": version, "help": help_run})}
        # Fixed public endpoints; status only, never response bodies or credentials.
        network_code = """import json,urllib.request,urllib.error
result={}
for url in ('https://opencode.ai/zen/v1/models','https://models.dev/api.json'):
    try:
        with urllib.request.urlopen(url,timeout=6) as r:
            result[url]={'status':r.status}
    except urllib.error.HTTPError as e:
        result[url]={'status':e.code}
    except Exception as e:
        result[url]={'error_type':type(e).__name__}
print(json.dumps(result))
"""
        network = _planner_probe_run(prefix + ["/usr/bin/python3", "-c", network_code], temp, 16)
        cmd = prefix + [str(binary), "run", "--pure", "--print-logs",
                        "--log-level", "DEBUG", "--model", "opencode/glm-5.3-flash-free",
                        "--format", "json",
                        "Responde exactamente con: CV_PROBE_OK. No uses herramientas."]
        probe = _planner_probe_run(cmd, temp, 50)
        # CLI exit 0 is insufficient: require an actual text event.
        confirmed = False
        for line in probe["output"].splitlines():
            try:
                event = json.loads(line)
            except (ValueError, TypeError):
                continue
            if isinstance(event, dict) and event.get("type") == "text":
                part = event.get("part") or {}
                value = part.get("text", "") if isinstance(part, dict) else ""
                confirmed = confirmed or value.strip() == "CV_PROBE_OK"
        return {"returncode": 0 if confirmed and probe["returncode"] == 0 else
                (probe["returncode"] or 7),
                "output": json.dumps({"stage": "model", "version": version,
                    "network": network, "probe": probe, "confirmed": confirmed})}

def action_prepare_control_dev_checkout(args):
    """Create one dedicated cvdev checkout at an exact main SHA. Never updates existing checkouts."""
    import pwd
    sha = str((args or {}).get("sha", "")).lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "SHA completo obligatorio"}
    target = Path("/home/cvdev/workspace/control-os-certified")
    parent = target.parent
    if parent.is_symlink() or parent.resolve() != parent:
        return {"returncode": 3, "output": "Workspace padre inseguro"}
    cv = pwd.getpwnam("cvdev")
    def as_dev(argv):
        return run(["runuser", "-u", "cvdev", "--", "git", "-C", str(target), *argv], timeout=90)
    if target.is_symlink():
        return {"returncode": 3, "output": "Target symlink bloqueado"}
    if target.exists():
        head = as_dev(["rev-parse", "HEAD"])
        status = as_dev(["status", "--porcelain"])
        origin = as_dev(["remote", "get-url", "origin"])
        ok = (head["returncode"] == status["returncode"] == origin["returncode"] == 0
              and head["output"].strip() == sha and not status["output"].strip()
              and origin["output"].strip() == "https://github.com/ernest196391/Casa-Viva.git"
              and target.stat().st_uid == cv.pw_uid)
        return {"returncode": 0 if ok else 4, "output": json.dumps({
            "changed": False, "verified": ok, "path": str(target),
            "head": head["output"].strip(), "note": "Checkout existente no modificado"})}
    cloned = run(["git", "clone", "--no-checkout", "--single-branch", "--branch", "main",
                  "git@github-casaviva:ernest196391/Casa-Viva.git", str(target)], timeout=120)
    if cloned["returncode"]:
        return cloned
    head = run(["git", "-C", str(target), "rev-parse", "origin/main"], timeout=20)
    if head["returncode"] or head["output"].strip() != sha:
        return {"returncode": 5, "output": "main cambió; checkout no activado ni borrado"}
    remote = run(["git", "-C", str(target), "remote", "set-url", "origin",
                  "https://github.com/ernest196391/Casa-Viva.git"], timeout=20)
    if remote["returncode"]:
        return remote
    # Only this newly created, fixed tree; never follows a symlink.
    for root, dirs, files in os.walk(target, followlinks=False):
        os.chown(root, cv.pw_uid, cv.pw_gid, follow_symlinks=False)
        for name in dirs + files:
            os.chown(Path(root) / name, cv.pw_uid, cv.pw_gid, follow_symlinks=False)
    checked = as_dev(["checkout", "--detach", sha])
    if checked["returncode"]:
        return checked
    status = as_dev(["status", "--porcelain"])
    final = as_dev(["rev-parse", "HEAD"])
    ok = (status["returncode"] == final["returncode"] == 0
          and not status["output"].strip() and final["output"].strip() == sha)
    return {"returncode": 0 if ok else 6, "output": json.dumps({
        "changed": True, "verified": ok, "path": str(target), "user": "cvdev",
        "head": final["output"].strip(), "tracked_clean": not status["output"].strip(),
        "production_changed": False, "worker_switched": False})}


def _control_codex_paths():
    import pwd
    cv = pwd.getpwnam("cvdev")
    base = Path("/home/cvdev/control-os-tools")
    auth = Path("/home/cvdev/control-os-auth")
    for p in (base, auth):
        if p.is_symlink():
            raise ValueError("Ruta Codex insegura")
        p.mkdir(mode=0o700, exist_ok=True)
        os.chown(p, cv.pw_uid, cv.pw_gid)
        os.chmod(p, 0o700)
    return base, auth


def _control_codex_cmd():
    base, auth = _control_codex_paths()
    return ["runuser", "-u", "cvdev", "--", "env", "-i",
            "PATH=/usr/local/lib/control-os:/usr/bin:/bin", "HOME=/home/cvdev",
            "CODEX_HOME=" + str(auth),
            str(base / "node_modules/.bin/codex")]


def action_control_codex_setup(args):
    """Install one pinned official CLI as cvdev. No login, API key or model request."""
    base, auth = _control_codex_paths()
    result = run(["runuser","-u","cvdev","--","env","-i",
                  "PATH=/usr/bin:/bin","HOME=/home/cvdev",
                  "npm","install","--prefix",str(base),"--save-exact",
                  "--no-audit","--no-fund","@openai/codex@0.169.0"], timeout=180)
    if result["returncode"]:
        return result
    version = run(_control_codex_cmd() + ["--version"], timeout=20)
    return {"returncode":version["returncode"],"output":json.dumps({
        "version":version["output"],"user":"cvdev","model_called":False,
        "api_key_configured":False,"production_changed":False})}


def action_control_codex_login(args):
    """Device login only; never returns auth.json, tokens, or API credentials."""
    mode = str((args or {}).get("mode", "status"))
    if mode not in ("start","status"):
        return {"returncode":2,"output":"Modo inválido"}
    base, auth = _control_codex_paths()
    command = _control_codex_cmd()
    if not (base / "node_modules/.bin/codex").exists():
        return {"returncode":2,"output":"Ejecutar control_codex_setup primero"}
    status = run(command + ["login","status"], timeout=20)
    if status["returncode"] == 0:
        return {"returncode":0,"output":json.dumps({"logged_in":True,
                "subscription_login": "ChatGPT" in status["output"],
                "model_called":False})}
    log = auth / "device-login.log"
    pid_path = auth / "device-login.pid"
    live = False
    try:
        pid = int(pid_path.read_text())
        os.kill(pid, 0)
        live = True
    except (OSError, ValueError):
        pass
    if mode == "start" and not live:
        with log.open("w") as output:
            os.chmod(log,0o600)
            proc = subprocess.Popen(command + ["login","--device-auth"],
                    cwd="/home/cvdev",stdout=output,stderr=subprocess.STDOUT,
                    start_new_session=True)
        pid_path.write_text(str(proc.pid))
        os.chmod(pid_path,0o600)
        live = True
    text = log.read_text(errors="replace")[-6000:] if log.exists() else ""
    text = re.sub(r"\x1b\[[0-9;]*m", "", text)
    url = re.search(r"https://auth\.openai\.com/[^\s]+",text)
    code = re.search(r"\b[A-Z0-9]{4,8}-[A-Z0-9]{4,8}\b",text)
    return {"returncode":0,"output":json.dumps({"logged_in":False,"login_running":live,
            "login_url":url.group(0) if url else None,
            "user_code":code.group(0) if code else None,
            "model_called":False,"note":"Completar inicio de sesión en el navegador; no enviar contraseñas."})}

def action_control_codex_consumer_build(args):
    """One fixed development task, in a dedicated cvdev worktree; never applies SQL or deploys."""
    mode = str((args or {}).get("mode", "status"))
    if mode not in ("start","retry","status","diagnostic"):
        return {"returncode":2,"output":"Modo inválido"}
    unit = "cv-control-consumer-build"
    work = Path("/home/cvdev/workspace/control-os-consumer-build")
    command = _control_codex_cmd()
    if mode == "diagnostic":
        profiles = {}
        for name in ("cv-opencode-bwrap","bwrap-userns-restrict"):
            path = Path("/etc/apparmor.d") / name
            profiles[name] = path.read_text(errors="replace")[:14000] if path.is_file() else None
        loaded = Path("/sys/kernel/security/apparmor/profiles")
        loaded_text = loaded.read_text(errors="replace") if loaded.is_file() else ""
        restrict = Path("/proc/sys/kernel/apparmor_restrict_unprivileged_userns")
        probe = run(command + ["sandbox","--","/usr/bin/true"],timeout=30)
        kernel = run(["journalctl","-k","-n","150","--no-pager","--output=cat"],timeout=20)
        denied = [line for line in kernel["output"].splitlines()
                  if ("DENIED" in line or "userns" in line) and
                  any(x in line.lower() for x in ("bwrap","codex","userns"))]
        return {"returncode":0,"output":json.dumps({
            "restriction":restrict.read_text().strip() if restrict.exists() else None,
            "profiles":profiles,
            "loaded_profiles":[x for x in loaded_text.splitlines() if "bwrap" in x or "codex" in x],
            "native_sandbox_probe":probe,"kernel_denials":denied[-20:],
            "model_called":False,"policy_changed":False})}
    if mode in ("start","retry"):
        state = run(["systemctl","is-active",unit],timeout=10)
        if state["output"].strip() in ("active","activating"):
            return {"returncode":0,"output":json.dumps({"already_running":True,"unit":unit})}
        if work.is_symlink():
            return {"returncode":3,"output":"Workspace symlink bloqueado"}
        source = "/home/cvdev/workspace/control-os-certified"
        prefix = ["runuser","-u","cvdev","--","git","-C",source]
        if work.exists():
            if mode != "retry":
                return {"returncode":3,"output":"Workspace existente: revisar antes de repetir"}
            clean = run(["runuser","-u","cvdev","--","git","-C",str(work),"status","--porcelain"],timeout=20)
            head = run(["runuser","-u","cvdev","--","git","-C",str(work),"rev-parse","HEAD"],timeout=20)
            base = run(prefix+["rev-parse","origin/main"],timeout=20)
            if clean["returncode"] or clean["output"].strip() or head["returncode"] or base["returncode"] or head["output"].strip()!=base["output"].strip():
                return {"returncode":3,"output":"Retry requiere worktree limpio y base intacta"}
        else:
            fetched = run(prefix + ["fetch","origin","main"],timeout=90)
            if fetched["returncode"]:
                return fetched
            made = run(prefix + ["worktree","add","--detach",str(work),"origin/main"],timeout=60)
            if made["returncode"]:
                return made
        prompt = (
            "Implementa SOLO la integración de resultados Control OS con Supabase en este worktree. "
            "Lee AGENTS.md y blueprints/checkpoint. No ejecutes red, SQL real, git push, deploy ni leas credenciales. "
            "Reutiliza el contrato de opencode-result-consumer.py; SimStore actual no debe romperse. "
            "Crea consumidor real separado con RPC transaccional para conciliar task/run, deduplicar resultados, "
            "guardar diff/base_sha/changed_paths y crear aprobación humana real. ops_dev_runs actual tiene "
            "id bigint,task_id uuid,repo,mode,status,base_sha,work_sha,auto_merge,allowed_paths jsonb,"
            "summary,error,logs,started_at,updated_at,finished_at; no tiene columna diff/result. "
            "Documenta cualquier migración necesaria en scripts/ops/sql, solo SECURITY INVOKER y grants "
            "limitados a service_role para funciones internas. No añadas ejecución de comandos del modelo. "
            "Ningún resultado puede saltarse aprobación, integrar, hacer push ni desplegar. "
            "Haz pruebas negativas de conciliación, duplicados, límites, estados y atomicidad mediante mocks "
            "y SQL fixtures con rollback. No actives servicios ni implementes promoción aún. "
            "Actualiza checkpoint. Ejecuta suites Python pertinentes; resume cambios y límites. "
            "No hagas commits. Mantén cambios limitados a scripts/ops y docs/."
        )
        launched = run(["systemd-run","--unit="+unit,"--uid=cvdev","--gid=cvdev","--collect",
            "--property=RuntimeMaxSec=600","--property=NoNewPrivileges=yes",
            "--property=ProtectSystem=strict","--property=ProtectHome=read-only",
            "--property=PrivateTmp=yes",
            "--property=ReadWritePaths="+str(work)+" /home/cvdev/control-os-auth",
            "--working-directory="+str(work),
            *command[4:], "exec","--sandbox","workspace-write","--ephemeral","--json",prompt],timeout=20)
        return {"returncode":launched["returncode"],"output":json.dumps({
            "unit":unit,"workspace":str(work),"launched":launched["returncode"]==0,
            "details":launched["output"],"production_changed":False})}
    state = run(["systemctl","show",unit,"--property=ActiveState,SubState,Result,ExecMainStatus"],timeout=10)
    journal = run(["journalctl","-u",unit,"-n","30","--no-pager","--output=cat"],timeout=20)
    diff = run(["runuser","-u","cvdev","--","git","-C",str(work),"diff","--stat"],timeout=20) if work.is_dir() else {"output":""}
    output = journal["output"][-12000:]
    output = re.sub(r"\\b(?:sk-|sbp_)[A-Za-z0-9_-]{12,}", "[REDACTED]",output)
    return {"returncode":0,"output":json.dumps({"unit":unit,"state":state["output"],
            "journal":output,"diff_stat":diff["output"],"production_changed":False})}

def action_control_codex_consumer_publish(args):
    """Publish reviewed-material branch only. No merge, SQL, deploy or generated-code execution."""
    work = Path("/home/cvdev/workspace/control-os-consumer-build")
    if work.is_symlink() or work.resolve() != work or not (work / ".git").is_file():
        return {"returncode":2,"output":"Worktree inesperado"}
    state = run(["systemctl","is-active","cv-control-consumer-build"],timeout=10)
    if state["output"].strip() in ("active","activating"):
        return {"returncode":3,"output":"Preparación todavía activa"}
    def git(argv):
        return run(["runuser","-u","cvdev","--","git","-C",str(work),*argv],timeout=90)
    head = git(["rev-parse","HEAD"])
    base = git(["rev-parse","origin/main"])
    if head["returncode"] or base["returncode"] or head["output"].strip()!=base["output"].strip():
        return {"returncode":4,"output":"HEAD no coincide con base; no se publica"}
    names = git(["ls-files","--modified","--others","--exclude-standard"])
    if names["returncode"]:
        return names
    paths = sorted(set(names["output"].splitlines()))
    if not paths or len(paths)>20:
        return {"returncode":5,"output":"Cantidad de archivos fuera de límite"}
    total = 0
    for rel in paths:
        if not re.fullmatch(r"[A-Za-z0-9_./-]+",rel) or ".." in Path(rel).parts:
            return {"returncode":5,"output":"Ruta insegura"}
        if not rel.startswith(("scripts/ops/","docs/")) or any(x.startswith(".") for x in Path(rel).parts):
            return {"returncode":5,"output":"Ruta fuera del alcance: "+rel}
        current = work
        for part in Path(rel).parts:
            current = current / part
            if current.is_symlink():
                return {"returncode":5,"output":"Symlink rechazado"}
        if not current.is_file():
            return {"returncode":5,"output":"Borrados o archivos especiales requieren revisión"}
        body = current.read_text(encoding="utf-8")
        total += len(body.encode())
        if total>500000 or re.search(r"BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY|\b(?:sk-|sbp_)[A-Za-z0-9_-]{16,}|\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+",body):
            return {"returncode":6,"output":"Límite o patrón sensible: "+rel}
    check = git(["diff","--check"])
    if check["returncode"]:
        return check
    branch = "review/control-os-real-consumer"
    for cmd in (["switch","-c",branch],["add","--",*paths],
                ["-c","user.name=Casa Viva Control OS","-c","user.email=control-os@local.invalid",
                 "commit","-m","Control OS: propuesta de consumidor real preparada por cvdev"]):
        step = git(cmd)
        if step["returncode"]:
            return step
    pushed = run(["git","-c","safe.directory="+str(work),"-c","core.hooksPath=/dev/null",
                  "-C",str(work),"push","git@github-casaviva:ernest196391/Casa-Viva.git",
                  "HEAD:refs/heads/"+branch],timeout=120)
    if pushed["returncode"]:
        return pushed
    return {"returncode":0,"output":json.dumps({"branch":branch,
        "head":git(["rev-parse","HEAD"])["output"].strip(),"paths":paths,
        "merged":False,"deployed":False})}

def action_control_codex_sandbox(args):
    """Dedicated bwrap profile only; no global AppArmor, sysctl, setuid or file-capability changes."""
    import shutil
    import pwd
    mode = str((args or {}).get("mode","verify"))
    if mode not in ("install","verify","rollback"):
        return {"returncode":2,"output":"Modo inválido"}
    folder = Path("/usr/local/lib/control-os")
    binary = folder / "bwrap"
    policy = Path("/etc/apparmor.d/cv-control-codex-bwrap")
    previous_profile = "abi <abi/4.0>,\ninclude <tunables/global>\nprofile cv-control-codex-bwrap /usr/local/lib/control-os/bwrap flags=(attach_disconnected,mediate_deleted) {\n  include <abstractions/base>\n  userns,\n  # These mediate capabilities acquired INSIDE bwrap's user namespace.\n  # The root-owned copy is 0755, without setuid or file capabilities;\n  # cvdev acquires no host capabilities.\n  capability setpcap,\n  capability sys_admin,\n  capability net_admin,\n  capability sys_chroot,\n  capability setuid,\n  capability setgid,\n  mount,\n  umount,\n  pivot_root,\n  network netlink raw,\n  network unix,\n  deny network inet,\n  deny network inet6,\n  signal (send,receive) peer=cv-control-codex-bwrap,\n  signal (receive) peer=unconfined,\n  / r,\n  /** r,\n  /usr/** rixm,\n  /bin/** rixm,\n  /sbin/** rixm,\n  /lib/** mr,\n  /lib64/** mr,\n  /home/cvdev/control-os-tools/** rixm,\n  /home/cvdev/workspace/control-os-consumer-build/{,**} rwkixm,\n  /tmp/{,**} rwkixm,\n  /dev/{null,zero,random,urandom,tty} rw,\n  /dev/pts/{,**} rw,\n  /proc/[0-9]*/{uid_map,gid_map,setgroups} rw,\n  deny /root/{,**} rwklmx,\n  deny /home/cvdev/control-os-auth/{,**} rwklmx,\n  deny /home/cvdev/.ssh/{,**} rwklmx,\n  deny /etc/ssl/private/{,**} rwklmx,\n}\n"
    profile = "abi <abi/4.0>,\ninclude <tunables/global>\nprofile cv-control-codex-bwrap /usr/local/lib/control-os/bwrap flags=(attach_disconnected,mediate_deleted) {\n  include <abstractions/base>\n  userns,\n  # These mediate capabilities acquired INSIDE bwrap's user namespace.\n  # The root-owned copy is 0755, without setuid or file capabilities;\n  # cvdev acquires no host capabilities.\n  capability setpcap,\n  capability sys_admin,\n  capability net_admin,\n  capability sys_chroot,\n  capability setuid,\n  capability setgid,\n  mount,\n  umount,\n  pivot_root,\n  network netlink raw,\n  network unix,\n  deny network inet,\n  deny network inet6,\n  signal (send,receive) peer=cv-control-codex-bwrap,\n  signal (receive) peer=unconfined,\n  # Private tmpfs staging root used by Bubblewrap before pivot_root.\n  /newroot/ rw,\n  /newroot/** rwkixm,\n  deny /newroot/root/{,**} rwklmx,\n  deny /newroot/home/cvdev/control-os-auth/{,**} rwklmx,\n  deny /newroot/home/cvdev/.ssh/{,**} rwklmx,\n  deny /newroot/etc/ssl/private/{,**} rwklmx,\n  / r,\n  /** r,\n  /usr/** rixm,\n  /bin/** rixm,\n  /sbin/** rixm,\n  /lib/** mr,\n  /lib64/** mr,\n  /home/cvdev/control-os-tools/** rixm,\n  /home/cvdev/workspace/control-os-consumer-build/{,**} rwkixm,\n  /tmp/{,**} rwkixm,\n  /dev/{null,zero,random,urandom,tty} rw,\n  /dev/pts/{,**} rw,\n  /proc/[0-9]*/{uid_map,gid_map,setgroups} rw,\n  deny /root/{,**} rwklmx,\n  deny /home/cvdev/control-os-auth/{,**} rwklmx,\n  deny /home/cvdev/.ssh/{,**} rwklmx,\n  deny /etc/ssl/private/{,**} rwklmx,\n}\n"
    if folder.is_symlink() or policy.is_symlink() or binary.is_symlink():
        return {"returncode":3,"output":"Ruta insegura"}
    if policy.exists() and policy.read_text() not in (previous_profile,profile):
        return {"returncode":3,"output":"Perfil existente distinto: requiere revisión"}
    if mode == "rollback":
        if policy.exists():
            unloaded = run(["apparmor_parser","-R",str(policy)],timeout=30)
            if unloaded["returncode"]:
                return unloaded
            policy.unlink()
        if binary.is_file():
            binary.unlink()
        return {"returncode":0,"output":"Perfil dedicado revertido; protección global intacta"}
    if mode == "install":
        tools_root = Path("/home/cvdev/control-os-tools")
        candidates = [p for p in tools_root.rglob("bwrap") if p.is_file() and not p.is_symlink()
                      and p.resolve().is_relative_to(tools_root)
                      and "codex-resources" in p.parts]
        if len(candidates)!=1:
            return {"returncode":4,"output":json.dumps({"error":"Recurso bwrap oficial ambiguo",
                "candidates":[str(p) for p in candidates]})}
        source = candidates[0]
        help_probe = run(["runuser","-u","cvdev","--",str(source),"--help"],timeout=15)
        if help_probe["returncode"] or not all(x in help_probe["output"] for x in ("--perms","--as-pid-1")):
            return {"returncode":4,"output":"bwrap incluido no satisface requisitos de Codex"}
        state = run(["systemctl","is-active","cv-control-consumer-build"],timeout=10)
        if state["output"].strip() in ("active","activating"):
            return {"returncode":4,"output":"Detener preparación antes de reemplazar binario"}
        folder.mkdir(mode=0o755,exist_ok=True)
        if folder.stat().st_uid!=0 or folder.stat().st_mode & 0o022:
            return {"returncode":3,"output":"Directorio de binarios no es seguro"}
        folder.chmod(0o755)  # Bridge umask is 077; cvdev must traverse this public binary directory.
        temporary = Path("/etc/apparmor.d/cv-control-codex-bwrap.next")
        temporary.write_text(profile)
        temporary.chmod(0o644)
        parsed = run(["apparmor_parser","-Q",str(temporary)],timeout=30)
        if parsed["returncode"]:
            temporary.unlink()
            return parsed
        if binary.exists() and binary.read_bytes()!=source.read_bytes():
            if not Path("/usr/bin/bwrap").is_file() or binary.read_bytes()!=Path("/usr/bin/bwrap").read_bytes():
                return {"returncode":3,"output":"Binario anterior no reconocido"}
        if not binary.exists() or binary.read_bytes()!=source.read_bytes():
            next_binary = folder / "bwrap.next"
            shutil.copyfile(source,next_binary)
            next_binary.chmod(0o755)
            os.replace(next_binary,binary)
        if ("security.capability" in os.listxattr(binary)
                or binary.read_bytes()!=source.read_bytes()):
            return {"returncode":3,"output":"Binario distinto o con file capabilities"}
        if binary.stat().st_uid!=0 or binary.stat().st_mode & 0o6022:
            return {"returncode":3,"output":"Binario con propietario/permisos inseguros"}
        os.replace(temporary,policy)
        loaded = run(["apparmor_parser","-r",str(policy)],timeout=30)
        if loaded["returncode"]:
            return loaded
    if not binary.is_file() or not policy.is_file():
        return {"returncode":4,"output":"Perfil dedicado no instalado"}
    command = _control_codex_cmd()
    work = "/home/cvdev/workspace/control-os-consumer-build"
    direct = run(["runuser","-u","cvdev","--",str(binary),
                  "--unshare-user","--unshare-net","--ro-bind","/","/","/usr/bin/true"],
                  cwd=work,timeout=20)
    if direct["returncode"]:
        return {"returncode":5,"output":json.dumps({"stage":"dedicated_binary",
            "directory_mode":oct(folder.stat().st_mode & 0o777),"probe":direct})}
    boot = run(command+["sandbox","--","/usr/bin/true"],cwd=work,timeout=30)
    if boot["returncode"]:
        return {"returncode":5,"output":json.dumps({"stage":"bootstrap","probe":boot,
            "global_restriction":Path("/proc/sys/kernel/apparmor_restrict_unprivileged_userns").read_text().strip()})}
    canary = Path("/home/cvdev/control-os-auth/isolation-read-check")
    canary.write_text("CONTROL_OS_FAKE_SECRET")
    cv = pwd.getpwnam("cvdev")
    os.chown(canary,cv.pw_uid,cv.pw_gid)
    canary.chmod(0o600)
    checks = """import json,os,pathlib,socket
report={}
try:
    pathlib.Path('/home/cvdev/control-os-auth/isolation-read-check').read_text();report['auth_read_denied']=False
except OSError as e:
    report['auth_read_denied']=e.errno in (1,13)
p=pathlib.Path('.control-os-isolation-check')
try:
    p.write_text('fixture'); report['workspace_write']=p.read_text()=='fixture'; p.unlink()
except OSError:
    report['workspace_write']=False
for key,path in [('auth_write','/home/cvdev/control-os-auth/isolation-write-check'),('root_write','/root/isolation-write-check')]:
    try:
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600);os.close(fd);os.unlink(path);report[key]=False
    except OSError as e:
        report[key]=e.errno in (1,13,30)
try:
    s=socket.socket();s.settimeout(2);s.connect(('1.1.1.1',443));s.close();report['network_denied']=False
except OSError as e:
    report['network_denied']=e.errno in (1,13)
print(json.dumps(report))
raise SystemExit(0 if all(report.values()) else 1)
"""
    verified = run(command+["-c",'sandbox_mode="workspace-write"',"sandbox","--",
                   "/usr/bin/python3","-c",checks],cwd=work,timeout=30)
    canary.unlink(missing_ok=True)
    return {"returncode":verified["returncode"],"output":json.dumps({
        "stage":"negative_tests","probe":verified,"bootstrap_passed":True,
        "global_restriction":Path("/proc/sys/kernel/apparmor_restrict_unprivileged_userns").read_text().strip(),
        "production_changed":False,"model_called":False})}

ACTIONS = {
    "prepare_control_dev_checkout": action_prepare_control_dev_checkout,
    "control_codex_setup": action_control_codex_setup,
    "control_codex_login": action_control_codex_login,
    "control_codex_consumer_build": action_control_codex_consumer_build,
    "control_codex_consumer_publish": action_control_codex_consumer_publish,
    "control_codex_sandbox": action_control_codex_sandbox,
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
    "bridge_service_status": action_bridge_service_status,
    "install_bridge_service": action_install_bridge_service,
    "update_control_sync": action_update_control_sync,
    "deploy_control_web": action_deploy_control_web,
    "control_sync_service_status": action_control_sync_service_status,
    "install_control_sync_service": action_install_control_sync_service,
    "dev_agent_status": action_dev_agent_status,
    "dev_environment_audit": action_dev_environment_audit,
    "dev_push_probe": action_dev_push_probe,
    "dev_claude_probe": action_dev_claude_probe,
    "update_dev_worker": action_update_dev_worker,
    "install_dev_worker_service": action_install_dev_worker_service,
    "dev_worker_status": action_dev_worker_status,
    "core_drift_audit": action_core_drift_audit,
    "snapshot_live_core_branch": action_snapshot_live_core_branch,
    "deploy_casa_viva": action_deploy_casa_viva,
    "casa_viva_deploy_status": action_casa_viva_deploy_status,
    "ai_audit": action_ai_audit,
    "catalog_sync_status": action_catalog_sync_status,
    "cvdev_control_status": action_cvdev_control_status,
    "cvdev_rebase_resume": action_cvdev_rebase_resume,
    "cvdev_control_tests": action_cvdev_control_tests,
    "cvdev_control_push": action_cvdev_control_push,
    "snapshot_vivabot_live_branch": action_snapshot_vivabot_live_branch,
    "snapshot_vivabot_dirty_branch": action_snapshot_vivabot_dirty_branch,
    "opencode_planner_probe": action_opencode_planner_probe,
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
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError as exc:
        if exc.code not in (402, 429):
            raise
        print(f"CV_REMOTE_BACKOFF status={exc.code} seconds=300", flush=True)
        time.sleep(300)
