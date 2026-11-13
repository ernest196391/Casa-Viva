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
BRIDGE_VERSION = "4.1.0"


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



def action_deploy_casa_viva(args):
    """Dispatch the existing GitHub deploy workflow for one exact main SHA."""
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
    if main_sha["output"].strip() != sha:
        return {
            "returncode": 3,
            "output": f"SHA bloqueado: solicitado {sha}, origin/main {main_sha['output'].strip()}",
        }

    auth = run(["gh", "auth", "status"], timeout=30)
    if auth["returncode"] != 0:
        return {"returncode": 4, "output": "GitHub CLI no autenticado:\n" + auth["output"]}

    # The workflow itself re-verifies CI, version, checksum, backup, smoke and rollback.
    dispatched = run([
        "gh", "workflow", "run", "deploy-prototype.yml",
        "--repo", "ernest196391/Casa-Viva",
        "--ref", "main",
        "-f", f"expected_sha={sha}",
    ], timeout=45)
    if dispatched["returncode"] != 0:
        return dispatched

    # Return the newest matching workflow_dispatch run when visible; do not guess deployment success here.
    time.sleep(4)
    listed = run([
        "gh", "run", "list",
        "--repo", "ernest196391/Casa-Viva",
        "--workflow", "deploy-prototype.yml",
        "--event", "workflow_dispatch",
        "--limit", "10",
        "--json", "databaseId,headSha,status,conclusion,createdAt,url",
    ], timeout=30)
    info = []
    if listed["returncode"] == 0:
        try:
            info = json.loads(listed["output"])
        except Exception:
            info = []
    match = next((x for x in info if x.get("headSha") == sha), None)
    return {
        "returncode": 0,
        "output": json.dumps({
            "dispatched": True,
            "sha": sha,
            "workflow": "deploy-prototype.yml",
            "run": match,
            "note": "Resultado final pendiente del workflow; el propio workflow contiene rollback automático.",
        }, ensure_ascii=False),
    }


def action_casa_viva_deploy_status(args):
    """Read GitHub deploy status for one exact SHA without changing anything."""
    import re

    sha = str((args or {}).get("sha", "")).strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return {"returncode": 2, "output": "args.sha debe ser un SHA Git completo"}
    listed = run([
        "gh", "run", "list",
        "--repo", "ernest196391/Casa-Viva",
        "--workflow", "deploy-prototype.yml",
        "--limit", "30",
        "--json", "databaseId,headSha,status,conclusion,event,createdAt,updatedAt,url",
    ], timeout=30)
    if listed["returncode"] != 0:
        return listed
    try:
        rows = json.loads(listed["output"])
    except Exception as exc:
        return {"returncode": 3, "output": f"No pude interpretar gh run list: {exc}"}
    matches = [x for x in rows if x.get("headSha") == sha]
    return {
        "returncode": 0,
        "output": json.dumps({"sha": sha, "runs": matches[:10]}, ensure_ascii=False),
    }


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
