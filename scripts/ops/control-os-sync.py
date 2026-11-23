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


def local_infrastructure():
    processes = json.loads(subprocess.check_output(['pm2','jlist'], text=True, timeout=10))
    bot = next((p for p in processes if p.get('name') == 'vivabot'), {})
    bot_status = bot.get('pm2_env', {}).get('status', 'unknown')

    bridge_active = subprocess.run(
        ['systemctl','is-active','cv-ops-bridge.service'],
        capture_output=True,text=True,timeout=10
    ).stdout.strip()
    receiver = next((p for p in processes if p.get('name') == 'vivabot-recovery-receiver'), {})
    receiver_status = receiver.get('pm2_env', {}).get('status', 'unknown')
    active_vivabot_count = (bot_status == 'online') + (receiver_status == 'online')
    system_ok = active_vivabot_count == 1 and bridge_active == 'active'
    return {
        'at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'ok': system_ok,
        'vivabot': {'status': bot_status},
        'receiver': {'status': receiver_status},
        'bridge': {'status': bridge_active},
    }


def push_local_infrastructure():
    command = (
        'umask 077; cd ' + STORE +
        ' && cat > infrastructure.json.tmp && mv -f infrastructure.json.tmp infrastructure.json'
    )
    subprocess.run(SSH + [command], input=json.dumps(local_infrastructure()), text=True,
                   capture_output=True, timeout=25, check=True)


def snapshot():
    now = datetime.datetime.now(datetime.timezone.utc)
    task_fields = 'id,public_id,thread_id,title,status,priority,risk,result,error,created_at,started_at,finished_at,source,source_ref,executor,task_type,requested_by_name,approval_required'
    tasks = query(
        'ops_tasks?select=' + task_fields + '&business_id=eq.casa-viva&order=created_at.desc&limit=120'
    )
    pending = query('ops_tasks?select=' + task_fields + '&business_id=eq.casa-viva&status=not.in.(done,cancelled,superseded)&order=created_at.asc&limit=500')
    tasks = list({str(x['id']): x for x in tasks + pending}.values())
    open_tasks = [x for x in tasks if x.get('status') not in ('done','cancelled','superseded')]
    rank = {'p0': 0, 'p1': 1, 'p2': 2, 'p3': 3}
    open_tasks.sort(key=lambda x: (rank.get(x.get('priority'), 9), x.get('created_at') or ''))

    threads = query(
        'ops_threads?select=id,source,source_ref,subject,summary,status,priority,owner_role,owner_ref,last_activity_at'
        '&business_id=eq.casa-viva&status=eq.active&order=last_activity_at.desc&limit=100'
    )
    approvals = query(
        'ops_approvals?select=public_id,task_id,requested_for_role,warning,impact,proposed_change,status,created_at'
        '&status=eq.pending&order=created_at.asc&limit=500'
    )
    task_by_uuid = {str(x.get('id')): x for x in tasks}
    missing = sorted({str(a.get('task_id')) for a in approvals} - set(task_by_uuid))
    for start in range(0, len(missing), 50):
        ids = urllib.parse.quote(','.join(missing[start:start+50]), safe=',-')
        for row in query('ops_tasks?select=' + task_fields + '&business_id=eq.casa-viva&id=in.(' + ids + ')'):
            task_by_uuid[str(row['id'])] = row
    approval_complete = len(approvals) < 500 and all(str(a.get('task_id')) in task_by_uuid for a in approvals)
    pending_links = {str(a.get('task_id')) for a in approvals}
    reviews = [x for x in pending if x.get('status') == 'waiting_approval' and str(x['id']) not in pending_links] if len(approvals) < 500 else []

    # Only successful allowlisted read-only outputs enter the private UI summary.
    lookup_ids = sorted({str((x.get('result') or {}).get('bridge_id'))
                         for x in tasks if isinstance(x.get('result'), dict)
                         and x['result'].get('action') in ('wp_status','vivabot_git_status')
                         and str(x['result'].get('bridge_id','')).isdigit()})[-30:]
    outputs = {}
    if lookup_ids:
        for row in query('crm_ops_bridge?select=id,action,status,result&id=in.(' + ','.join(lookup_ids) + ')&action=in.(wp_status,vivabot_git_status)&status=eq.done'):
            result = row.get('result') or {}
            if isinstance(result, dict) and result.get('returncode') == 0:
                outputs[str(row['id'])] = result.get('output') or ''

    def task_summary(x):
        result = x.get('result')
        if isinstance(result, dict):
            output = outputs.get(str(result.get('bridge_id')), '')
            if result.get('action') == 'vivabot_git_status' and output:
                try:
                    info = json.loads(output)
                    return 'Código del bot verificado: rama ' + str(info.get('branch') or 'desconocida') + '. ' + ('Sin cambios locales.' if info.get('tracked_clean') and info.get('untracked_count') == 0 else 'Hay cambios locales que revisar.')
                except (ValueError, TypeError):
                    pass
            if result.get('action') == 'wp_status' and output:
                core = re.search(r'^CORE=(.+)$', output, re.M)
                return 'WordPress ' + core.group(1).strip() + ': consulta completada.' if core else 'Consulta de WordPress completada.'
            return str(result.get('summary') or result.get('note') or result.get('goal') or '')
        return ''

    # Recent requests must not disappear behind the first 60 legacy tasks.
    recent_owner = [x for x in tasks if x.get('source') in ('control-private','whatsapp-owner')][:30]
    selected = {}
    for x in open_tasks + recent_owner + [t for t in tasks if t.get('status') in ('done','cancelled','superseded','failed')][:20]:
        selected[str(x.get('id'))] = x
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
        'request_id': x.get('source_ref'),
        'created_at': x.get('created_at'),
        'executor': x.get('executor'),
        'task_type': x.get('task_type'),
        'at': x.get('finished_at') or x.get('started_at') or x.get('created_at'),
    } for x in selected.values()]

    decisions = []
    for a in approvals:
        t = task_by_uuid.get(str(a.get('task_id')), {})
        decisions.append({
            'approval_id': a.get('public_id'),
            'task_id': t.get('public_id'),
            'task_status': t.get('status'),
            'actionable': t.get('status') == 'waiting_approval' and a.get('requested_for_role') == 'owner',
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

    diagnostics = local_infrastructure()
    bot_status = diagnostics['vivabot']['status']
    system_ok = diagnostics['ok']
    bridge_active = diagnostics['bridge']['status']
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

    # Read only the public heartbeat. Never send arbitrary crm_settings to the browser.
    heartbeat = None
    try:
        settings = query('crm_settings?select=value&business_id=eq.casa-viva&key=eq.programador_local_latido&limit=1')
        if settings:
            value = settings[0].get('value')
            value = json.loads(value) if isinstance(value, str) else value
            if isinstance(value, dict):
                heartbeat = {k: str(value[k])[:100] for k in ('at','modelo','version') if k in value}
    except (ValueError, TypeError, OSError):
        pass
    try:
        dev_status = subprocess.run(['systemctl','is-active','cv-dev-worker.service'], capture_output=True,text=True,timeout=10).stdout.strip()
    except (OSError, subprocess.TimeoutExpired):
        dev_status = 'unknown'
    return {
        'at': now.isoformat(),
        'summary': {
            'attention': len(attention),
            'decisions': len(decisions),
            'lennys': len(lennys),
            'errors': len(errors),
            'open_tasks': len(open_tasks),
        },
        'today': [x for x in task_cards if x.get('status') not in ('done','cancelled','superseded')][:10],
        'tasks': task_cards,
        'decisions': decisions,
        'approval_complete': approval_complete,
        'pending_complete': len(pending) < 500,
        'reviews_blocked': [{'id': x.get('public_id'), 'title': x.get('title'), 'status': x.get('status'), 'source': x.get('source'), 'created_at': x.get('created_at'), 'risk': x.get('risk')} for x in reviews],
        'coverage': {'tasks': 'Hasta 120 recientes y 500 pendientes; no es el total histórico.', 'conversations': 'Hasta 20 asuntos activos de Lennys; no es el chat completo.', 'incidents': 'Hasta 20 incidencias registradas; no es un análisis nuevo de WhatsApp.'},
        'programmer': {'heartbeat': heartbeat, 'worker_status': dev_status, 'source': 'crm_settings.programador_local_latido / systemd'},
        'lennys': lennys,
        'system': {
            'ok': system_ok,
            'vivabot': {'status': bot_status, 'sha': vivabot_sha},
            'receiver': diagnostics['receiver'],
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
        try:
            rows = read_portal_requests()
            known = read_state()
            added = ingest(rows, known)
            decisions = read_portal_decisions()
            known_decisions = read_decision_state()
            decisions_added = ingest_decisions(decisions, known_decisions)
            state = snapshot()
            push_snapshot(state)
        except urllib.error.HTTPError as exc:
            if exc.code == 402:
                push_local_infrastructure()
            raise
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

