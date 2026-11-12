#!/usr/bin/env python3
"""Casa Viva Control OS portal sync.

Reads the private /control/ request journal from Hostinger and ingests each owner
instruction idempotently into Supabase ops_threads/ops_tasks. It does not write to
codex-backlog.json and does not call any AI model.
"""
import datetime
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.parse
import urllib.request

ROOT = Path('/root/supervisor')
SSH = ['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','StrictHostKeyChecking=yes',
       '-i','/root/.ssh/casa-viva-web','-p','65002','u824654880@88.223.85.72']
STORE = 'domains/casaviva.company/control-private'
STATE = ROOT / 'control-os-imported.json'
VALID_ID = re.compile(r'^[a-f0-9]{32}$')


def load_env(path):
    if not path.exists():
        return
    for raw in path.read_text(errors='ignore').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        k, v = line.split('=', 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k and k not in os.environ:
            os.environ[k] = v


for env_path in (Path('/root/vivabot/.env'), ROOT / '.env'):
    load_env(env_path)

SUPABASE_URL = os.environ.get('SUPABASE_URL', '').rstrip('/')
SERVICE_KEY = (
    os.environ.get('SUPABASE_SERVICE_ROLE_KEY')
    or os.environ.get('SUPABASE_SERVICE_KEY')
    or os.environ.get('SUPABASE_SECRET_KEY')
    or os.environ.get('SERVICE_ROLE_KEY')
    or os.environ.get('VIVABOT_SUPABASE_SERVICE_ROLE_KEY')
)
if not SUPABASE_URL or not SERVICE_KEY:
    raise SystemExit('Faltan SUPABASE_URL o service key')


def api(method, path, body=None):
    url = SUPABASE_URL + '/rest/v1/' + path.lstrip('/')
    data = None if body is None else json.dumps(body, ensure_ascii=False).encode()
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('apikey', SERVICE_KEY)
    req.add_header('Authorization', 'Bearer ' + SERVICE_KEY)
    req.add_header('Content-Type', 'application/json')
    req.add_header('Accept', 'application/json')
    if method in ('POST','PATCH'):
        req.add_header('Prefer', 'return=representation')
    with urllib.request.urlopen(req, timeout=25) as resp:
        raw = resp.read()
    return json.loads(raw) if raw else None


def read_state():
    if not STATE.exists():
        return set()
    try:
        data = json.loads(STATE.read_text())
        return {str(x) for x in data if VALID_ID.fullmatch(str(x))}
    except Exception:
        return set()


def save_state(ids):
    tmp = STATE.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(sorted(ids)[-5000:]))
    tmp.chmod(0o600)
    tmp.replace(STATE)


def read_portal_requests():
    command = "php -r '$p=\"" + STORE + "/requests.jsonl\";if(is_file($p)){$f=fopen($p,\"rb\");flock($f,LOCK_SH);fpassthru($f);flock($f,LOCK_UN);fclose($f);}'"
    response = subprocess.run(SSH + [command], capture_output=True, text=True, timeout=25, check=True)
    rows = []
    for line in response.stdout.splitlines():
        try:
            row = json.loads(line)
        except Exception:
            continue
        text = row.get('text')
        rid = str(row.get('id',''))
        if (row.get('actor_wp_id') != 1 or row.get('kind') != 'task'
                or not VALID_ID.fullmatch(rid)
                or not isinstance(text, str)
                or not 8 <= len(text.encode()) <= 6000
                or '\0' in text):
            continue
        rows.append(row)
    return rows


def ingest(rows, known):
    added = 0
    for row in rows:
        rid = row['id']
        if rid in known:
            continue
        at = row.get('at') or datetime.datetime.now(datetime.timezone.utc).isoformat()
        result = api('POST', 'rpc/ops_ingest_control_request', {
            'p_request_id': rid,
            'p_text': row['text'],
            'p_at': at,
        })
        if not isinstance(result, list) or not result:
            raise RuntimeError('Supabase no devolvió confirmación para ' + rid)
        known.add(rid)
        added += 1
        save_state(known)
    return added


def query(path):
    return api('GET', path) or []


def snapshot():
    tasks = query('ops_tasks?select=public_id,title,status,priority,risk,result,error,created_at,started_at,finished_at,source&order=created_at.desc&limit=60')
    open_tasks = [x for x in tasks if x.get('status') not in ('done','cancelled','superseded')]
    findings = []
    for x in open_tasks:
        if x.get('source') != 'crm_errores':
            continue
        findings.append({
            'id': 'ops-' + str(x.get('public_id')),
            'priority': x.get('priority'),
            'title': x.get('title'),
            'detail': x.get('error') or 'Error abierto en Control OS.',
            'status': 'pending',
            'order_id': None,
            'question': None,
        })
    task_cards = []
    for x in open_tasks[:40]:
        result = x.get('result')
        if isinstance(result, dict):
            summary = result.get('summary') or result.get('note') or ''
        else:
            summary = ''
        task_cards.append({
            'title': '#' + str(x.get('public_id')) + ' · ' + str(x.get('title') or 'Tarea'),
            'status': x.get('status'),
            'summary': summary,
            'detail': x.get('error') or '',
            'at': x.get('finished_at') or x.get('started_at') or x.get('created_at'),
        })
    events_raw = query('ops_events?select=id,event_type,actor_type,actor_ref,detail,created_at&order=created_at.desc&limit=20')
    events = [{
        'at': x.get('created_at'),
        'provider': 'Control OS',
        'title': str(x.get('event_type') or 'Evento').replace('_',' '),
        'detail': json.dumps(x.get('detail') or {}, ensure_ascii=False)[:300],
    } for x in events_raw]

    processes = json.loads(subprocess.check_output(['pm2','jlist'], text=True, timeout=10))
    bot = next((p for p in processes if p.get('name') == 'vivabot'), {})
    return {
        'at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'owner': 'control-os',
        'supervisor': {'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'event': 'running'},
        'health': {'state': 'ok', 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'error': None},
        'bot': {'status': bot.get('pm2_env', {}).get('status', 'unknown')},
        'findings': findings[:20],
        'tasks': task_cards,
        'events': events,
    }


def push_snapshot(state):
    command = 'umask 077; mkdir -p ' + STORE + '; cat > ' + STORE + '/snapshot.json.tmp && mv ' + STORE + '/snapshot.json.tmp ' + STORE + '/snapshot.json'
    subprocess.run(SSH + [command], input=json.dumps(state, ensure_ascii=False), text=True,
                   capture_output=True, timeout=25, check=True)


def main():
    os.umask(0o077)
    with open('/tmp/casa-control-sync.lock', 'a') as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        rows = read_portal_requests()
        known = read_state()
        added = ingest(rows, known)
        state = snapshot()
        push_snapshot(state)
        print(json.dumps({
            'ok': True,
            'portal_requests': len(rows),
            'imported_now': added,
            'known': len(known),
            'open_tasks': len(state['tasks']),
            'open_errors': len(state['findings']),
        }, ensure_ascii=False))


if __name__ == '__main__':
    main()
