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
DECISION_STATE = ROOT / 'control-os-decisions-imported.json'
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



def read_portal_decisions():
    command = "php -r '$p=\"" + STORE + "/decisions.jsonl\";if(is_file($p)){$f=fopen($p,\"rb\");flock($f,LOCK_SH);fpassthru($f);flock($f,LOCK_UN);fclose($f);}'"
    response = subprocess.run(SSH + [command], capture_output=True, text=True, timeout=25, check=True)
    rows = []
    for line in response.stdout.splitlines():
        try:
            row = json.loads(line)
        except Exception:
            continue
        rid = str(row.get('id',''))
        approval_id = row.get('approval_id')
        decision = row.get('decision')
        if (row.get('actor_wp_id') != 1 or row.get('kind') != 'decision'
                or not VALID_ID.fullmatch(rid)
                or not isinstance(approval_id, int) or approval_id <= 0
                or decision not in ('approve','reject')):
            continue
        rows.append(row)
    return rows


def read_decision_state():
    if not DECISION_STATE.exists():
        return set()
    try:
        data = json.loads(DECISION_STATE.read_text())
        return {str(x) for x in data if VALID_ID.fullmatch(str(x))}
    except Exception:
        return set()


def save_decision_state(ids):
    tmp = DECISION_STATE.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(sorted(ids)[-5000:]))
    tmp.chmod(0o600)
    tmp.replace(DECISION_STATE)


def ingest_decisions(rows, known):
    added = 0
    for row in rows:
        rid = row['id']
        if rid in known:
            continue
        result = api('POST', 'rpc/ops_decide_approval', {
            'p_public_id': row['approval_id'],
            'p_decision': row['decision'],
            'p_actor_role': 'owner',
            'p_actor_ref': 'ernesto',
            'p_note': 'Decisión desde /control/ · request ' + rid,
        })
        if not isinstance(result, list) or not result:
            raise RuntimeError('Supabase no devolvió confirmación para decisión ' + rid)
        known.add(rid)
        added += 1
        save_decision_state(known)
    return added


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
    now = datetime.datetime.now(datetime.timezone.utc)
    tasks = query(
        'ops_tasks?select=id,public_id,thread_id,title,status,priority,risk,result,error,created_at,started_at,finished_at,source,requested_by_name,approval_required'
        '&order=created_at.desc&limit=120'
    )
    open_tasks = [x for x in tasks if x.get('status') not in ('done','cancelled','superseded')]
    rank = {'p0': 0, 'p1': 1, 'p2': 2, 'p3': 3}
    open_tasks.sort(key=lambda x: (rank.get(x.get('priority'), 9), x.get('created_at') or ''))

    threads = query(
        'ops_threads?select=id,source,source_ref,subject,summary,status,priority,owner_role,owner_ref,last_activity_at'
        '&status=eq.active&order=last_activity_at.desc&limit=100'
    )
    approvals = query(
        'ops_approvals?select=public_id,task_id,requested_for_role,warning,impact,proposed_change,status,created_at'
        '&status=eq.pending&order=created_at.asc&limit=500'
    )
    # Do not rely on the latest 120 tasks for financial approval safety:
    # older waiting tasks must remain visible until formally resolved.
    waiting_approval_tasks = query(
        'ops_tasks?select=id,public_id,title,status,risk,created_at'
        '&status=eq.waiting_approval&order=created_at.asc&limit=500'
    )
    task_by_uuid = {str(x.get('id')): x for x in tasks + waiting_approval_tasks}

    def task_summary(x):
        result = x.get('result')
        if isinstance(result, dict):
            return str(result.get('summary') or result.get('note') or result.get('goal') or '')
        return ''

    task_cards = [{
        'id': x.get('public_id'),
        'title': x.get('title') or 'Tarea',
        'status': x.get('status'),
        'priority': x.get('priority'),
        'risk': x.get('risk'),
        'source': x.get('source'),
        'summary': task_summary(x),
        'detail': x.get('error') or '',
        'requested_by': x.get('requested_by_name') or '',
        'approval_required': bool(x.get('approval_required')),
        'at': x.get('finished_at') or x.get('started_at') or x.get('created_at'),
    } for x in open_tasks[:60]]

    # A task may be waiting for approval without an ops_approvals row.
    # Never fabricate an approval_id: the decision RPC only accepts real approvals.
    # Surface these orphaned financial reviews separately so the owner can see
    # the blocked work without an invalid Approve/Reject button.
    # A truncated approval list must never turn a legitimate pending approval into an orphan.\n    approval_page_truncated = len(approvals) >= 500\n    waiting_page_truncated = len(waiting_approval_tasks) >= 500\n    pending_approval_task_ids = {str(a.get('task_id')) for a in approvals}
    orphan_reviews = [{
        'task_id': x.get('public_id'),
        'title': x.get('title') or 'Revisión pendiente',
        'warning': 'Falta la solicitud formal de aprobación. No se puede aprobar desde el panel todavía.',
        'status': x.get('status'),
        'risk': x.get('risk'),
        'created_at': x.get('created_at'),
    } for x in waiting_approval_tasks
      if x.get('status') == 'waiting_approval'
      and str(x.get('id')) not in pending_approval_task_ids] if not approval_page_truncated else []

    decisions = []
    for a in approvals:
        t = task_by_uuid.get(str(a.get('task_id')), {})
        decisions.append({
            'approval_id': a.get('public_id'),
            'task_id': t.get('public_id'),
            'title': t.get('title') or 'Decisión pendiente',
            'warning': a.get('warning') or '',
            'impact': a.get('impact') or {},
            'proposed_change': a.get('proposed_change') or {},
            'created_at': a.get('created_at'),
        })

    lennys_sources = {'legacy-crm-supervisor','whatsapp-lennys-live','whatsapp-lennys'}
    lennys = [{
        'id': x.get('source_ref'),
        'subject': x.get('subject') or 'Asunto de Lennys',
        'summary': x.get('summary') or '',
        'priority': x.get('priority'),
        'last_activity_at': x.get('last_activity_at'),
    } for x in threads if x.get('source') in lennys_sources][:20]

    errors = [x for x in open_tasks if x.get('source') == 'crm_errores']
    attention = [x for x in open_tasks if x.get('priority') in ('p0','p1')]

    events_raw = query(
        'ops_events?select=id,event_type,actor_type,actor_ref,detail,created_at'
        '&order=created_at.desc&limit=30'
    )
    activity = [{
        'at': x.get('created_at'),
        'provider': x.get('actor_ref') or x.get('actor_type') or 'Control OS',
        'title': str(x.get('event_type') or 'evento').replace('_',' '),
        'detail': x.get('detail') or {},
    } for x in events_raw]

    processes = json.loads(subprocess.check_output(['pm2','jlist'], text=True, timeout=10))
    bot = next((p for p in processes if p.get('name') == 'vivabot'), {})
    bot_status = bot.get('pm2_env', {}).get('status', 'unknown')

    bridge_active = subprocess.run(
        ['systemctl','is-active','cv-ops-bridge.service'],
        capture_output=True,text=True,timeout=10
    ).stdout.strip()
    bridge_enabled = subprocess.run(
        ['systemctl','is-enabled','cv-ops-bridge.service'],
        capture_output=True,text=True,timeout=10
    ).stdout.strip()
    try:
        bridge_text = Path('/root/supervisor/cv-ops-bridge.py').read_text(errors='ignore')
        m = re.search(r'BRIDGE_VERSION\s*=\s*"([^"]+)"', bridge_text)
        bridge_version = m.group(1) if m else 'unknown'
    except Exception:
        bridge_version = 'unknown'
    try:
        vivabot_sha = subprocess.check_output(
            ['git','-C','/root/vivabot','rev-parse','--short','HEAD'],
            text=True,timeout=10
        ).strip()
    except Exception:
        vivabot_sha = 'unknown'

    system_ok = bot_status == 'online' and bridge_active == 'active'
    return {
        'at': now.isoformat(),
        'summary': {
            'attention': len(attention),
            'decisions': len(decisions),
            'reviews_blocked': len(orphan_reviews),
            'reviews_audit_incomplete': approval_page_truncated or waiting_page_truncated,
            'lennys': len(lennys),
            'errors': len(errors),
            'open_tasks': len(open_tasks),
        },
        'today': task_cards[:10],
        'tasks': task_cards,
        'decisions': decisions,
        'reviews_blocked': orphan_reviews,
        'lennys': lennys,
        'system': {
            'ok': system_ok,
            'vivabot': {'status': bot_status, 'sha': vivabot_sha},
            'bridge': {'status': bridge_active, 'enabled': bridge_enabled, 'version': bridge_version, 'poll_seconds': 3},
            'control_sync': {'status': 'online', 'at': now.isoformat()},
        },
        'activity': activity,
        # Compatibility with the old UI until C3 web files are deployed.
        'owner': 'control-os',
        'supervisor': {'at': now.isoformat(), 'event': 'running'},
        'health': {'state': 'ok' if system_ok else 'degraded', 'at': now.isoformat(), 'error': None if system_ok else 'Revisar servicios del Control OS'},
        'bot': {'status': bot_status},
        'findings': [{
            'id': 'ops-' + str(x.get('public_id')),
            'priority': x.get('priority'),
            'title': x.get('title'),
            'detail': x.get('error') or 'Error abierto en Control OS.',
            'status': 'pending',
            'order_id': None,
            'question': None,
        } for x in errors[:20]],
        'events': [{
            'at': x.get('at'),
            'provider': x.get('provider'),
            'title': x.get('title'),
            'detail': json.dumps(x.get('detail') or {}, ensure_ascii=False)[:300],
        } for x in activity],
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
        decisions = read_portal_decisions()
        known_decisions = read_decision_state()
        decisions_added = ingest_decisions(decisions, known_decisions)
        state = snapshot()
        push_snapshot(state)
        print(json.dumps({
            'ok': True,
            'portal_requests': len(rows),
            'imported_now': added,
            'known': len(known),
            'decision_requests': len(decisions),
            'decisions_imported_now': decisions_added,
            'known_decisions': len(known_decisions),
            'open_tasks': len(state['tasks']),
            'open_errors': len(state['findings']),
        }, ensure_ascii=False))


if __name__ == '__main__':
    main()
