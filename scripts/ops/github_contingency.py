"""Read-only GitHub contingency transport for Casa Viva Ops Bridge.

No arbitrary commands, credentials in results, or production mutations.
Call only under the existing bridge lock; opt in locally after review.
"""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time
import tempfile
import urllib.request

REPO = 'ernest196391/casa-viva-centro-control'
OWNER = 'ernest196391'
PREFIX = 'CV-CONTINGENCY-V1 '
STATES = {'online', 'stopped', 'errored', 'launching', 'stopping', 'active', 'inactive', 'failed', 'unknown'}
PROCESSES = {'vivabot', 'vivabot-recovery-receiver', 'nexofirma'}
PREFLIGHT_SCRIPT = Path('/root/supervisor/recovery-dispatch-preflight.mjs')
PREFLIGHT_ENV = Path('/root/supervisor/.dispatch-preflight.env')
PREFLIGHT_BLOB = '5dfafab9d7120f8c002999c30f1dd31e5eff5754'
PREFLIGHT_KEYS = {'SUPABASE_URL', 'SUPABASE_SERVICE_KEY', 'DISPATCH_GROUP', 'BUSINESS_SLUG', 'WOO_URL', 'CURRU_KEY'}
PREFLIGHT_COUNTS = {'core_offers': {'offers', 'unannounced'}, 'reconcile_offers': {'ready', 'recorded', 'uncertain', 'ownershipConflict'}, 'core_direct': {'pending'}, 'core_palla': {'pending'}}
PREFLIGHT_CHECKS = {'local_config', 'business', 'group', 'dispatch_schema', 'outbox_schema'} | set(PREFLIGHT_COUNTS)
RECEIVER_REPLIES = Path('/root/backups/nexo-recovery/receiver/recovery-replies.mjs')
RECEIVER_TEST_RUNNER = Path('/root/supervisor/receiver-classification-isolated.py')
RECEIVER_TEST_RUNNER_SHA256 = 'e1bbcb9b5ea10c502bde48206964fc262b363459d465477a89d4700a139b71f0'
RECEIVER_TEST_OUTCOMES = {'detected', 'none', 'unsupported'}
RECEIVER_TEST_ERRORS = {'private_input_unavailable', 'probe_mismatch', 'source_changed', 'isolated_probe_unavailable'}
AGENT_TOOLS = {'hermes', 'opencode', 'ollama', 'git', 'node', 'python3'}
AGENT_SERVICES = {'cv-dev-worker.service', 'cv-control-sync.service', 'cv-ops-bridge.service'}


def config(env):
    if env.get('CV_GITHUB_CONTINGENCY_ENABLED') != '1':
        return None
    issue = env.get('CV_GITHUB_CONTINGENCY_ISSUE', '')
    token = env.get('CV_GITHUB_CONTINGENCY_TOKEN', '')
    if not re.fullmatch(r'[1-9][0-9]{0,8}', issue) or not token:
        return None
    return int(issue), token


def timestamp(value):
    if not isinstance(value, str) or not value.endswith('Z'):
        raise ValueError('invalid timestamp')
    return dt.datetime.fromisoformat(value[:-1] + '+00:00').timestamp()


def command(comment, now):
    """Strict owner-authored, unedited, fresh command, no parameters."""
    try:
        if comment.get('user', {}).get('login') != OWNER or comment['user'].get('type') != 'User' or comment.get('author_association') != 'OWNER':
            return None
        if comment['created_at'] != comment['updated_at'] or not isinstance(comment['id'], int) or comment['id'] <= 0:
            return None
        body = comment['body']
        if not isinstance(body, str) or not body.startswith(PREFIX) or len(body) > 400:
            return None
        data = json.loads(body[len(PREFIX):])
        action = data.get('action')
        fields = {'action', 'nonce', 'expires_at', 'sha'} if action == 'release_apply' else {'action', 'nonce', 'expires_at'}
        if action == 'receiver_classification':
            fields = fields | {'sha256'}
            if not isinstance(data.get('sha256'), str) or not re.fullmatch(r'[0-9a-f]{64}', data['sha256']):
                return None
        if action == 'order_lookup':
            fields = fields | {'product'}
            if not valid_product(data.get('product')):
                return None
        if set(data) != fields or action not in ('health', 'dispatch_preflight', 'agent_inventory', 'db_snapshot', 'receiver_contract', 'receiver_classification', 'urgent_triage', 'order_lookup', 'wa_catalog_case', 'release_apply', 'hermes_probe_card', 'hermes_offline_tests', 'hermes_dialog_tests', 'hermes_fin_contract', 'hermes_caja_probe', 'hermes_crm_writer_audit', 'hermes_crm_sync_status') or not isinstance(data['nonce'], str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}', data['nonce']):
            return None
        if action == 'release_apply' and (not isinstance(data['sha'], str) or not re.fullmatch(r'[0-9a-f]{40}', data['sha'])):
            return None
        created, expires = timestamp(comment['created_at']), timestamp(data['expires_at'])
        if not created <= now < expires <= created + 1800:
            return None
        if action in ('hermes_probe_card', 'hermes_offline_tests', 'hermes_dialog_tests', 'hermes_fin_contract', 'hermes_caja_probe', 'hermes_crm_writer_audit', 'hermes_crm_sync_status') and expires > created + 600:
            return None
        return data
    except (KeyError, TypeError, ValueError, AttributeError):
        return None


def private_dir(path):
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    if path.is_symlink() or path.stat().st_uid != os.geteuid() or path.stat().st_mode & 0o077:
        raise ValueError('insecure contingency directory')
    return path


def atomic_state(path, value):
    temp = path.with_suffix('.new')
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(value, f)
        f.flush()
        os.fsync(f.fileno())
    os.replace(temp, path)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def reserve(directory, nonce, comment_id):
    path = directory / ('nonce-' + hashlib.sha256(nonce.encode()).hexdigest())
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    except FileExistsError:
        return False
    with os.fdopen(fd, 'w') as f:
        json.dump({'comment_id': comment_id}, f)
        f.flush()
        os.fsync(f.fileno())
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return True


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def github_api(token, method, path, body=None):
    # Call paths are generated internally; the token is never printed or forwarded.
    req = urllib.request.Request('https://api.github.com' + path,
        data=None if body is None else json.dumps(body).encode(), method=method,
        headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json',
                 'Content-Type': 'application/json', 'User-Agent': 'CasaViva-contingency-v1'})
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=12) as response:
        return json.loads(response.read(1_000_001))


def health_summary(pm2_result, service_status):
    """No raw output, env, identifiers of customers, or exceptions leave the VPS."""
    processes = {}
    try:
        if pm2_result.get('returncode') == 0:
            for row in json.loads(pm2_result.get('output', '')):
                name, status = row.get('name'), row.get('status')
                if name in PROCESSES:
                    processes[name] = {'status': status if status in STATES else 'unknown'}
    except (ValueError, TypeError, AttributeError):
        processes = {}
    active = service_status if service_status in STATES else 'unknown'
    return {'pm2': {name: processes.get(name, {'status': 'unknown'}) for name in sorted(PROCESSES)}, 'bridge': active}


def private_bytes(path, limit):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as f:
        info = os.fstat(f.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise ValueError('insecure preflight file')
        value = f.read(limit + 1)
        if len(value) > limit:
            raise ValueError('oversized preflight file')
        return value


def preflight_summary(raw):
    data = json.loads(raw)
    checks = data['checks']
    if not isinstance(checks, dict) or type(data['ok']) is not bool:
        raise ValueError('invalid preflight response')
    safe = {}
    for name in sorted(PREFLIGHT_CHECKS):
        row = checks.get(name, {})
        if not isinstance(row, dict) or type(row.get('ok')) is not bool:
            safe[name] = {'ok': False}
            continue
        safe[name] = {'ok': row['ok']}
        for key in PREFLIGHT_COUNTS.get(name, set()):
            value = row.get(key)
            if type(value) is int and 0 <= value <= 1000000:
                safe[name][key] = value
            elif row['ok']:
                safe[name]['ok'] = False
    return {'ok': data['ok'] and all(row['ok'] for row in safe.values()), 'checks': safe}


def dispatch_preflight():
    """Exact merged VivaBot source, fixed command, no inherited secrets or output."""
    try:
        script = private_bytes(PREFLIGHT_SCRIPT, 65536)
        blob = hashlib.sha1(b'blob ' + str(len(script)).encode() + b'\0' + script).hexdigest()
        if blob != PREFLIGHT_BLOB:
            return {'ok': False, 'reason': 'script_mismatch'}
        env = {'PATH': '/usr/bin:/bin', 'RECOVERY_RECONCILE': '1'}
        for line in private_bytes(PREFLIGHT_ENV, 65536).decode().splitlines():
            key, sep, value = line.partition('=')
            if sep and key in PREFLIGHT_KEYS:
                if key in env:
                    raise ValueError('duplicate config')
                env[key] = value.strip().strip('"').strip("'")
        if not PREFLIGHT_KEYS <= env.keys():
            return {'ok': False, 'reason': 'missing_config'}
        result = subprocess.run(['/usr/bin/node', '--input-type=module'],
            input=script + b'\nconsole.log(JSON.stringify(await preflight(process.env)));\n',
            env=env, cwd='/root/supervisor', capture_output=True, timeout=100, check=False)
        if result.returncode or len(result.stdout) > 16384:
            return {'ok': False, 'reason': 'execution_failed'}
        return preflight_summary(result.stdout)
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'preflight_unavailable'}


def receiver_contract():
    """Read the deployed reply module without importing or executing it."""
    try:
        raw = private_bytes(RECEIVER_REPLIES, 262144)
        source = raw.decode('utf-8')
    except (OSError, ValueError):
        return {'ok': False, 'reason': 'receiver_source_unavailable'}
    return {
        'ok': True,
        'sha256': hashlib.sha256(raw).hexdigest(),
        'mentions_classify': bool(re.search(r'\bclassify\s*\(', source)),
        'mentions_respond': bool(re.search(r'\brespond\s*\(', source)),
        'mentions_send_message': bool(re.search(r'\bsendMessage\s*\(', source)),
    }


def receiver_classification(expected):
    """Run the exact installed diagnostic and report only fixed result fields."""
    try:
        runner = private_bytes(RECEIVER_TEST_RUNNER, 65536)
        if hashlib.sha256(runner).hexdigest() != RECEIVER_TEST_RUNNER_SHA256:
            return {'ok': False, 'reason': 'runner_mismatch'}
        code = '__file__ = ' + repr(str(RECEIVER_TEST_RUNNER)) + '\n' + runner.decode('utf-8')
        result = subprocess.run(
            ['/usr/bin/python3', '-I', '-c', code, expected],
            env={'PATH': '/usr/bin:/bin'}, capture_output=True, timeout=15, check=False)
        if result.returncode or len(result.stdout) > 2048:
            raise ValueError('invalid runner response')
        data = json.loads(result.stdout)
        if data.get('ok') is False and data.get('reason') in RECEIVER_TEST_ERRORS:
            return {'ok': False, 'reason': data['reason']}
        cases = data['classification']
        if (data.get('ok') is not True or data.get('sha256') != expected or
                not isinstance(cases, dict) or set(cases) != {'saludo', 'ventiladores'} or
                any(not isinstance(value, str) or value not in RECEIVER_TEST_OUTCOMES
                    for value in cases.values())):
            raise ValueError('invalid classification')
        return {'ok': True, 'sha256': expected, 'classification': cases,
                'catalog': 'not_tested', 'reply': 'not_tested'}
    except (OSError, ValueError, TypeError, KeyError, AttributeError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'runner_unavailable'}


def agent_inventory():
    import shutil
    path = '/usr/local/bin:/usr/bin:/bin:/root/.local/bin'
    tools = {name: bool(shutil.which(name, path=path)) for name in sorted(AGENT_TOOLS)}
    services = {}
    for name in sorted(AGENT_SERVICES):
        try:
            p = subprocess.run(['/usr/bin/systemctl', 'is-active', name], capture_output=True, text=True, timeout=5, check=False)
            value = p.stdout.strip()
            services[name] = value if value in STATES else 'unknown'
        except (OSError, subprocess.SubprocessError):
            services[name] = 'unknown'
    return {'tools': tools, 'services': services}


# Fixed local snapshot probe; no caller-supplied SQL, table, or container.
DB_SNAPSHOT_SQL = """BEGIN READ ONLY;
SET LOCAL statement_timeout = '5s';
SET LOCAL TIME ZONE 'UTC';
SELECT json_build_object(
 'crm_messages', (SELECT count(*) FROM public.crm_messages),
 'wa_messages', (SELECT count(*) FROM public.crm_wa_messages),
 'dispatch', (SELECT count(*) FROM public.crm_dispatch),
 'outbox', (SELECT count(*) FROM public.crm_outbox),
 'devices', (SELECT count(*) FROM nexo_business.devices),
 'sync_events', (SELECT count(*) FROM nexo_business.sync_events),
 'last_crm_day', (SELECT max(created_at)::date FROM public.crm_messages),
 'last_dispatch_day', (SELECT max(updated_at)::date FROM public.crm_dispatch)
);
COMMIT;"""
DB_COUNT_FIELDS = {'crm_messages', 'wa_messages', 'dispatch', 'outbox', 'devices', 'sync_events'}
DB_DAY_FIELDS = {'last_crm_day', 'last_dispatch_day'}


def db_snapshot():
    try:
        p = subprocess.run(['/usr/bin/docker', 'exec', '-u', 'postgres',
            'vivabot-recovery-db', 'psql', '-X', '-q', '-A', '-t',
            '-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', DB_SNAPSHOT_SQL],
            env={'PATH': '/usr/bin:/bin', 'HOME': '/root'}, capture_output=True,
            timeout=20, check=False)
        if p.returncode != 0 or len(p.stdout) > 2048:
            raise ValueError('probe failed')
        data = json.loads(p.stdout)
        if not isinstance(data, dict) or set(data) != DB_COUNT_FIELDS | DB_DAY_FIELDS:
            raise ValueError('invalid shape')
        for name in DB_COUNT_FIELDS:
            if type(data[name]) is not int or not 0 <= data[name] <= 10**12:
                raise ValueError('invalid count')
        for name in DB_DAY_FIELDS:
            if data[name] is not None:
                if not isinstance(data[name], str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', data[name]):
                    raise ValueError('invalid date')
                dt.date.fromisoformat(data[name])
        return {'ok': True, 'snapshot': data}
    except (OSError, ValueError, TypeError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'snapshot_unavailable'}


# Counts of potential signals, not verified individual incidents. No text leaves VPS.
TRIAGE_SQL = """BEGIN READ ONLY;
SET LOCAL statement_timeout = '10s';
SET LOCAL TIME ZONE 'UTC';
WITH esc AS (
 SELECT lower(concat_ws(' ',kind,reason,client_message)) AS txt
 FROM public.crm_escalations WHERE status='abierta'
), messages AS (
 SELECT lower(coalesce(body,'')) AS txt FROM public.crm_messages
 WHERE direction='in' AND created_at >= now()-interval '48 hours'
), errors AS (
 SELECT lower(coalesce(to_jsonb(e)->>'status',to_jsonb(e)->>'estado','unknown')) AS state
 FROM public.crm_errores e
), wa AS (
 SELECT to_jsonb(w) AS j FROM public.crm_wa_messages w
), wa_days AS (
 SELECT left(coalesce(j->>'created_at',j->>'timestamp',j->>'sent_at',''),10) AS day,
 lower(concat_ws(' ',j->>'body',j->>'text',j->>'content',j->>'message')) AS txt FROM wa
), wa_recent AS (
 SELECT txt FROM wa_days WHERE day ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'
 AND day >= (current_date-2)::text AND day <= current_date::text
)
SELECT json_build_object(
 'escalations_open',(SELECT count(*) FROM esc),
 'escalations_money',(SELECT count(*) FROM esc WHERE txt ~ 'cobro|dinero|pago|transfer|zelle|vuelto|efectivo|reembolso|devoluci'),
 'escalations_orders',(SELECT count(*) FROM esc WHERE txt ~ 'pedido|vale|mensaj|entrega|caja|recog'),
 'escalations_bot',(SELECT count(*) FROM esc WHERE txt ~ 'bot|error|no responde|no contesta'),
 'crm_in_48h',(SELECT count(*) FROM messages),
 'crm_money_signals',(SELECT count(*) FROM messages WHERE txt ~ 'cobro|dinero|pago|transfer|zelle|vuelto|efectivo|reembolso|devoluci'),
 'crm_order_signals',(SELECT count(*) FROM messages WHERE txt ~ 'pedido|vale|mensaj|entrega|caja|recog'),
 'errors_total',(SELECT count(*) FROM errors),
 'errors_open',(SELECT count(*) FROM errors WHERE state IN ('abierta','abierto','open','pendiente','pending')),
 'errors_unknown_state',(SELECT count(*) FROM errors WHERE state='unknown'),
 'wa_recent_dated',(SELECT count(*) FROM wa_recent),
 'wa_money_signals',(SELECT count(*) FROM wa_recent WHERE txt ~ 'cobro|dinero|pago|transfer|zelle|vuelto|efectivo|reembolso|devoluci'),
 'wa_order_signals',(SELECT count(*) FROM wa_recent WHERE txt ~ 'pedido|vale|mensaj|entrega|caja|recog'),
 'wa_bot_signals',(SELECT count(*) FROM wa_recent WHERE txt ~ 'bot|error|no responde|no contesta')
);
COMMIT;"""
TRIAGE_FIELDS = {'escalations_open','escalations_money','escalations_orders','escalations_bot',
    'crm_in_48h','crm_money_signals','crm_order_signals','errors_total','errors_open',
    'errors_unknown_state','wa_recent_dated','wa_money_signals','wa_order_signals','wa_bot_signals'}


def urgent_triage():
    try:
        p = subprocess.run(['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db',
            'psql','-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-v','VERBOSITY=sqlstate','-d','postgres','-c',TRIAGE_SQL],
            env={'PATH':'/usr/bin:/bin','HOME':'/root'},capture_output=True,timeout=25,check=False)
        if p.returncode:
            states = {
                b'42P01': 'missing_relation', b'42703': 'missing_column',
                b'42501': 'permission', b'57014': 'query_cancelled',
            }
            state = re.search(rb'^ERROR:\s+([0-9A-Z]{5})\s*$', p.stderr, re.M)
            return {'ok':False,'reason':'triage_unavailable',
                    'stage':states.get(state[1], 'database') if state else 'database'}
        if len(p.stdout)>2048:
            raise ValueError('triage unavailable')
        data=json.loads(p.stdout)
        if not isinstance(data,dict) or set(data)!=TRIAGE_FIELDS:
            raise ValueError('shape')
        if any(type(v) is not int or not 0<=v<=10**12 for v in data.values()):
            raise ValueError('count')
        for key,parent in [('escalations_money','escalations_open'),('escalations_orders','escalations_open'),
            ('escalations_bot','escalations_open'),('crm_money_signals','crm_in_48h'),('crm_order_signals','crm_in_48h'),
            ('errors_open','errors_total'),('errors_unknown_state','errors_total'),
            ('wa_money_signals','wa_recent_dated'),('wa_order_signals','wa_recent_dated'),('wa_bot_signals','wa_recent_dated')]:
            if data[key]>data[parent]:
                raise ValueError('count relation')
        return {'ok':True,'signals':data}
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        return {'ok':False,'reason':'triage_unavailable'}



# Only product phrases (no digits, punctuation, SQL, contact IDs or raw chat export).
def valid_product(value):
    return isinstance(value, str) and re.fullmatch(r'[a-z]{3,24}(?: [a-z]{2,24}){0,2}', value) is not None and len(value) <= 48


ORDER_LOOKUP_SQL = """BEGIN READ ONLY;
SET LOCAL statement_timeout = '8s';
SET LOCAL TIME ZONE 'UTC';
WITH tenant AS (
 SELECT id FROM public.crm_businesses
 WHERE (SELECT count(*) FROM public.crm_businesses)=1
), recent AS (
 SELECT w.chat_jid,w.body,w.sent_at FROM public.crm_wa_messages w
 JOIN tenant t ON t.id=w.business_id
 WHERE w.is_group=false AND w.sent_at>=now()-interval '72 hours'
), matches AS (
 SELECT chat_jid,body FROM recent WHERE lower(body) LIKE '%{product}%'
), chats AS (
 SELECT DISTINCT chat_jid FROM matches LIMIT 21
), refs AS (
 SELECT DISTINCT (m)[1]::integer AS id FROM matches
 CROSS JOIN LATERAL regexp_matches(lower(body),'pedido[[:space:]]*#?[[:space:]]*([1-9][0-9]{2,8})([^0-9]|$)','g') m
 ORDER BY id LIMIT 21
)
SELECT json_build_object(
 'tenant_ok',(SELECT count(*)=1 FROM tenant),
 'matches',(SELECT count(*) FROM matches),
 'chats',(SELECT count(*) FROM chats),
 'order_ids',coalesce((SELECT json_agg(id ORDER BY id) FROM refs),'[]'::json),
 'audio_notes',(SELECT count(*) FROM recent r JOIN chats c USING(chat_jid)
 WHERE lower(r.body) LIKE '[nota de voz%' OR lower(r.body) LIKE '[audio%'),
 'last_day',(SELECT max(sent_at)::date FROM recent));
COMMIT;"""


def order_lookup(product):
    if not valid_product(product):
        return {'ok':False,'reason':'invalid_product'}
    try:
        p = subprocess.run(['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db',
            'psql','-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-d','postgres','-c',
            ORDER_LOOKUP_SQL.replace('{product}',product)],
            env={'PATH':'/usr/bin:/bin','HOME':'/root'},capture_output=True,timeout=20,check=False)
        if p.returncode or len(p.stdout)>2048:
            raise ValueError('lookup failed')
        d=json.loads(p.stdout)
        if not isinstance(d,dict) or set(d)!={'tenant_ok','matches','chats','order_ids','audio_notes','last_day'} or d['tenant_ok'] is not True:
            raise ValueError('tenant or shape')
        if any(type(d[k]) is not int or not 0<=d[k]<=10**9 for k in ('matches','chats','audio_notes')):
            raise ValueError('counts')
        ids=d['order_ids']
        if not isinstance(ids,list) or len(ids)>21 or any(type(i) is not int or not 100<=i<=999999999 for i in ids) or ids!=sorted(set(ids)):
            raise ValueError('ids')
        if d['chats']>20 or len(ids)>20:
            return {'ok':False,'reason':'too_many_candidates'}
        if d['last_day'] is not None:
            if not isinstance(d['last_day'],str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}',d['last_day']):
                raise ValueError('date')
            dt.date.fromisoformat(d['last_day'])
        return {'ok':True,'evidence':d}
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        return {'ok':False,'reason':'lookup_unavailable'}


# Fixed case approved for diagnosis. No chat IDs, names, or message bodies
# cross the bridge. The category is evidence, not attribution to a bot.
WA_CATALOG_CASE_SQL = """BEGIN READ ONLY;
SET LOCAL statement_timeout = '8s';
SET LOCAL TIME ZONE 'UTC';
WITH incoming AS (
 SELECT w.business_id,w.chat_jid,w.sent_at
 FROM public.crm_wa_messages w
 JOIN public.crm_businesses b ON b.id=w.business_id
 WHERE b.slug='casa-viva' AND w.is_group=false AND w.from_me=false
 AND w.sent_at >= now()-interval '72 hours'
 AND regexp_replace(lower(btrim(w.body)), '[[:space:]¿?!]+', ' ', 'g')
     ~ '^ ?hay mesitas auxiliares disponibles ?$'
 LIMIT 2
)
SELECT json_build_object(
 'matches', (SELECT count(*) FROM incoming),
 'reply', CASE WHEN (SELECT count(*) FROM incoming)=1 THEN COALESCE((
   SELECT CASE WHEN lower(w.body) LIKE 'no encontré una coincidencia en la búsqueda.%'
               THEN 'catalog_no_match' ELSE 'other_outbound' END
   FROM incoming i JOIN public.crm_wa_messages w
     ON w.business_id=i.business_id AND w.chat_jid=i.chat_jid
   WHERE w.from_me=true AND w.sent_at>=i.sent_at AND w.sent_at<i.sent_at+interval '2 minutes'
   ORDER BY w.sent_at, w.id LIMIT 1
 ), 'no_outbound') ELSE 'not_checked' END
);
COMMIT;"""


def wa_catalog_case():
    try:
        p = subprocess.run([
            '/usr/bin/docker', 'exec', '-u', 'postgres', 'vivabot-recovery-db',
            'psql', '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-d', 'postgres',
            '-c', WA_CATALOG_CASE_SQL],
            env={'PATH': '/usr/bin:/bin', 'HOME': '/root'}, capture_output=True,
            timeout=20, check=False)
        if p.returncode or len(p.stdout) > 512:
            raise ValueError('query failed')
        result = json.loads(p.stdout)
        if not isinstance(result, dict) or set(result) != {'matches', 'reply'}:
            raise ValueError('shape')
        if type(result['matches']) is not int or not 0 <= result['matches'] <= 2:
            raise ValueError('count')
        allowed = ('catalog_no_match', 'other_outbound', 'no_outbound') if result['matches'] == 1 else ('not_checked',)
        if result['reply'] not in allowed:
            raise ValueError('category')
        return {'ok': True, **result}
    except (OSError, ValueError, TypeError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'wa_catalog_case_unavailable'}


def hermes_probe_card(data, now):
    """Fixed blocked card; never dispatch. Return only allowlisted metadata."""
    failure = {'ok': False, 'reason': 'hermes_probe_unavailable'}
    try:
        if data.get('action') != 'hermes_probe_card' or set(data) != {'action', 'nonce', 'expires_at'}:
            return failure
        nonce = data['nonce']
        if not isinstance(nonce, str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}', nonce):
            return failure
        if not 0 < timestamp(data['expires_at']) - now <= 600:
            return failure
        argv = ['/usr/bin/sudo', '-n', '-u', 'hermes', '-H', '--',
                '/home/hermes/.local/bin/hermes', 'kanban', '--board', 'default',
                'create', 'CV bridge connectivity probe', '--body',
                'Inert connectivity test. Keep blocked. Do not dispatch.',
                '--assignee', 'default', '--workspace', 'scratch',
                '--initial-status', 'blocked', '--idempotency-key', 'cv-probe-' + nonce,
                '--max-runtime', '90s', '--max-retries', '1',
                '--completion-contract', 'local-only', '--json']
        result = subprocess.run(argv, cwd='/home/hermes', env={'PATH': '/usr/bin:/bin', 'HOME': '/root'},
                                stdin=subprocess.DEVNULL, capture_output=True, timeout=20, check=False)
        if result.returncode != 0 or len(result.stdout) > 16384:
            return failure
        card = json.loads(result.stdout)
        if not isinstance(card, dict) or not isinstance(card.get('id'), str) or not re.fullmatch(r't_[0-9a-f]{8}', card['id']):
            return failure
        if any(card.get(k) != v for k, v in {'title': 'CV bridge connectivity probe',
                'body': 'Inert connectivity test. Keep blocked. Do not dispatch.',
                'assignee': 'default', 'status': 'blocked', 'workspace_kind': 'scratch',
                'max_runtime_seconds': 90, 'max_retries': 1, 'completion_contract': 'local-only'}.items()):
            return failure
        if any(k not in card or card[k] is not None for k in ('started_at', 'session_id', 'result')):
            return failure
        return {'ok': True, 'card_id': card['id'], 'status': 'blocked'}
    except (OSError, ValueError, TypeError, AttributeError, KeyError, subprocess.TimeoutExpired):
        return failure


OFFLINE_COMMIT = '2478a5a7cd8b82c1b0a9dbe8ff2fb46189cd7649'
OFFLINE_FILES = ('test/helpers/cv-fin-receiver.mjs', 'test/helpers/cv-fin-summary.mjs',
                 'test/cv-fin-receiver.test.mjs', 'test/cv-fin-summary.test.mjs')


DIALOG_COMMIT = 'd0720b0d4fd5b2ce1006f5ed3d20786d4565f852'
DIALOG_FILES = ('test/helpers/cv-bot-dialog.mjs', 'test/cv-bot-dialog.test.mjs')


def hermes_offline_tests(data, now):
    """Fixed local revision, no fetch, no model, no raw output."""
    failure = {'ok': False, 'reason': 'offline_tests_failed'}
    try:
        if set(data) != {'action', 'nonce', 'expires_at'} or data['action'] not in ('hermes_offline_tests', 'hermes_dialog_tests'):
            return failure
        if not re.fullmatch(r'[A-Za-z0-9_-]{16,64}', data['nonce']) or not 0 < timestamp(data['expires_at']) - now <= 600:
            return failure
        commit, files, expected = (DIALOG_COMMIT, DIALOG_FILES, 14) if data['action'] == 'hermes_dialog_tests' else (OFFLINE_COMMIT, OFFLINE_FILES, 17)
        env = {'PATH': '/usr/bin:/bin', 'HOME': '/root', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_TERMINAL_PROMPT': '0'}
        with tempfile.TemporaryDirectory(prefix='cv-offline-', dir='/var/tmp') as tmp:
            stage = Path(tmp)
            for name in files:
                r = subprocess.run(['/usr/bin/git', '--no-replace-objects', '-C', '/root/vivabot', 'show', commit + ':' + name],
                                   env=env, stdin=subprocess.DEVNULL, capture_output=True, timeout=5)
                if r.returncode or not 0 < len(r.stdout) <= 65536:
                    return failure
                dest = stage / name
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(r.stdout)
                dest.chmod(0o444)
            for directory in (stage, stage / 'test', stage / 'test/helpers'):
                directory.chmod(0o755)
            argv = ['/usr/bin/systemd-run', '--wait', '--pipe', '--collect', '--quiet',
                    '-p', 'DynamicUser=yes', '-p', 'PrivateNetwork=yes',
                    '-p', 'ProtectSystem=strict', '-p', 'ProtectHome=yes',
                    '-p', 'PrivateTmp=yes', '-p', 'NoNewPrivileges=yes',
                    '-p', 'RestrictAddressFamilies=AF_UNIX', '-p', 'MemoryMax=256M',
                    '-p', 'CPUQuota=50%', '-p', 'TasksMax=32', '-p', 'RuntimeMaxSec=30',
                    # Mask sensitive sockets individually: masking all /run breaks systemd namespaces.
                    '-p', 'InaccessiblePaths=/var/lib /var/log /srv /mnt /media -/run/systemd/private -/run/dbus -/run/docker.sock -/run/containerd -/run/user -/run/redis -/run/postgresql',
                    '-p', 'SystemCallFilter=~connect',
                    '-p', 'SystemCallErrorNumber=EPERM',
                    '-p', 'BindReadOnlyPaths=' + tmp + ':/opt/cv-offline-tests',
                    '/usr/bin/node', '--test', '--test-reporter=tap',
                    *('/opt/cv-offline-tests/' + name for name in files if name.endswith('.test.mjs'))]
            result = subprocess.run(argv, env={'PATH': '/usr/bin:/bin', 'HOME': '/root'},
                                    stdin=subprocess.DEVNULL, capture_output=True, timeout=45)
            if result.returncode or len(result.stdout) > 65536:
                return failure
            out = result.stdout.decode('utf-8', errors='strict')
            for line in (f'# tests {expected}', f'# pass {expected}', '# fail 0', '# skipped 0', '# cancelled 0', '# todo 0'):
                if out.splitlines().count(line) != 1:
                    return failure
            return {'ok': True, 'commit': commit, 'tests': expected, 'passed': expected, 'failed': 0}
    except (OSError, ValueError, TypeError, KeyError, subprocess.TimeoutExpired):
        return failure


FIN_SOURCE = Path('/root/backups/nexo-recovery/receiver/operational-queries.mjs')
FIN_RENDERER = Path('/root/vivabot/src/gestora-payout-status-rules.js')


def hermes_fin_contract():
    """Static indicators only; never import modules, query Core or return source."""
    result = {}
    for label, path, patterns in (
        ('operations', FIN_SOURCE, {'partners_route': '/partners', 'orders_route': '/gestora/orders',
          'payout_notice_route': '/payout-avisos', 'finance_handler': 'financeAnswer',
          'cash_close_marker': 'cashclose', 'currency_marker': '_cvd_commission_currency'}),
        ('renderer', FIN_RENDERER, {'summary_handler': 'commissionSummaryText',
          'usd_literal': ' USD', 'number_coercion_marker': 'Number('}),
    ):
        try:
            # Source code may be world-readable; require root-owned regular file, no symlink or group/other writes.
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            with os.fdopen(fd, 'rb') as f:
                info = os.fstat(f.fileno())
                if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                    raise ValueError('unsafe source')
                raw = f.read(262145)
                if len(raw) > 262144:
                    raise ValueError('oversized source')
            source = raw.decode('utf-8')
            result[label] = {'sha256': hashlib.sha256(raw).hexdigest(),
                             'markers': {k: v in source for k, v in patterns.items()}}
        except (OSError, ValueError):
            return {'ok': False, 'reason': 'finance_source_unavailable'}
    return {'ok': True, 'scope': 'static_only', **result}



# Fixed unauthenticated probes. No response bodies, redirects, proxies or credentials.
CAJA_PROBES = (
    ('render', 'https://ernesto-rondon-nexo.onrender.com/api/business/caja-recovery', 'POST'),
    ('core', 'https://casaviva.company/wp-json/casa-viva/v1/panel/caja/recovery-orders', 'GET'),
)


def hermes_caja_probe():
    import urllib.error
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    result = {}
    for label, url, method in CAJA_PROBES:
        request = urllib.request.Request(url, method=method,
            data=b'{"action":"list"}' if method == 'POST' else None,
            headers={'Content-Type': 'application/json', 'User-Agent': 'CasaViva-diagnostic'})
        try:
            with opener.open(request, timeout=8) as response:
                status = response.status
        except urllib.error.HTTPError as exc:
            status = exc.code
            exc.close()
        except (OSError, ValueError):
            result[label] = {'status': None, 'category': 'transport_error'}
            continue
        if type(status) is not int or not 100 <= status <= 599:
            result[label] = {'status': None, 'category': 'invalid_status'}
            continue
        category = ('unexpected_public_success' if 200 <= status < 300 else
                    'auth_required' if status in (401, 403) else
                    'unavailable' if status >= 500 else
                    'redirect_blocked' if 300 <= status < 400 else 'other_http')
        result[label] = {'status': status, 'category': category}
    return {'ok': all(v['category'] == 'auth_required' for v in result.values()),
            'scope': 'unauthenticated_only', **result}


CRM_RECEIVER = Path('/root/backups/nexo-recovery/receiver')
CRM_ORIGINAL = Path('/root/vivabot/src')
CRM_MARKERS = {'wa_table': 'crm_wa_messages', 'supabase': 'supabase',
               'upsert': 'upsert', 'message_event': 'messages.upsert',
               'rest_api': '/rest/v1/', 'insert': 'insert'}


def hermes_crm_writer_audit():
    """Bounded static scan of immediate JS sources only; no env/logs/content."""
    result = {}
    for label, root in (('receiver', CRM_RECEIVER), ('original', CRM_ORIGINAL)):
        try:
            if root.is_symlink() or not root.is_dir():
                raise ValueError('invalid directory')
            candidates = []
            with os.scandir(root) as entries:
                for i, entry in enumerate(entries):
                    if i >= 256:
                        raise ValueError('directory limit')
                    if re.fullmatch(r'[A-Za-z0-9_-]{1,80}\.(?:mjs|js|cjs)', entry.name):
                        candidates.append(entry.name)
            if len(candidates) > 64:
                raise ValueError('source limit')
            hits = {k: 0 for k in CRM_MARKERS}
            skipped = 0
            for name in candidates:
                try:
                    fd = os.open(root / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                    with os.fdopen(fd, 'rb') as f:
                        st = os.fstat(f.fileno())
                        if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_mode & 0o022:
                            raise ValueError('unsafe source')
                        raw = f.read(262145)
                        if len(raw) > 262144:
                            raise ValueError('source limit')
                    source = raw.decode('utf-8').lower()
                    for key, marker in CRM_MARKERS.items():
                        hits[key] += int(marker in source)
                except (OSError, ValueError):
                    skipped += 1
            result[label] = {'files': len(candidates), 'skipped': skipped, 'marker_counts': hits}
        except (OSError, ValueError):
            return {'ok': False, 'reason': 'writer_sources_unavailable'}
    return {'ok': True, 'scope': 'static_nonrecursive', **result}


CRM_SYNC_SQL = """BEGIN READ ONLY;
SET LOCAL statement_timeout='5s';
SET LOCAL TIME ZONE 'UTC';
SELECT json_build_object(
 'tenant_matches',(SELECT count(*) FROM public.crm_businesses WHERE slug='casa-viva'),
 'messages',count(*),
 'since_cut',count(*) FILTER (WHERE w.sent_at > '2026-11-19T22:43:06Z'::timestamptz),
 'latest',to_char(max(w.sent_at),'YYYY-MM-DD"T"HH24:MI:SS"Z"'))
 FROM public.crm_wa_messages w JOIN public.crm_businesses b ON b.id=w.business_id
 WHERE b.slug='casa-viva';
COMMIT;"""


def hermes_crm_sync_status():
    """Reuse local recovery database read-only; never return IDs or messages."""
    failure = {'ok': False, 'reason': 'local_sync_unavailable'}
    try:
        p = subprocess.run(['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db',
            'psql','-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-d','postgres','-c',CRM_SYNC_SQL],
            env={'PATH':'/usr/bin:/bin','HOME':'/root'}, stdin=subprocess.DEVNULL,
            capture_output=True,timeout=15,check=False)
        if p.returncode or len(p.stdout)>1024:
            return failure
        d=json.loads(p.stdout)
        if not isinstance(d,dict) or set(d)!={'tenant_matches','messages','since_cut','latest'}:
            return failure
        if type(d['tenant_matches']) is not int or d['tenant_matches']!=1:
            return failure
        if any(type(d[k]) is not int or not 0<=d[k]<=10**12 for k in ('messages','since_cut')):
            return failure
        if d['since_cut']>d['messages'] or (d['messages']==0)!=(d['latest'] is None):
            return failure
        if d['latest'] is not None:
            if not isinstance(d['latest'],str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',d['latest']):
                return failure
            timestamp(d['latest'])
        return {'ok':True,'scope':'local_recovery_db','messages':d['messages'],
                'since_cut':d['since_cut'],'latest':d['latest']}
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        return failure


class Contingency:
    def __init__(self, env, health, directory=Path('/var/lib/cv-ops-bridge-contingency'), api=github_api, clock=time.time):
        self.cfg = config(env)
        self.diagnostics_enabled = {action for action, flag in (
            ('hermes_caja_probe', 'CV_GITHUB_HERMES_CAJA_PROBE_ENABLED'),
            ('hermes_crm_writer_audit', 'CV_GITHUB_HERMES_CRM_WRITER_ENABLED'),
            ('hermes_crm_sync_status', 'CV_GITHUB_HERMES_CRM_SYNC_ENABLED'),
        ) if env.get(flag) == '1'}
        self.hermes_dialog_enabled = env.get('CV_GITHUB_HERMES_DIALOG_ENABLED') == '1'
        self.hermes_fin_contract_enabled = env.get('CV_GITHUB_HERMES_FIN_CONTRACT_ENABLED') == '1'
        self.hermes_offline_enabled = env.get('CV_GITHUB_HERMES_OFFLINE_ENABLED') == '1'
        self.hermes_probe_enabled = env.get('CV_GITHUB_HERMES_PROBE_ENABLED') == '1'
        self.receiver_classification_enabled = env.get('CV_GITHUB_RECEIVER_CLASSIFICATION_ENABLED') == '1'
        self.receiver_contract_enabled = env.get('CV_GITHUB_RECEIVER_CONTRACT_ENABLED') == '1'
        self.preflight_enabled = env.get('CV_GITHUB_DISPATCH_PREFLIGHT_ENABLED') == '1'
        self.release_enabled = env.get('CV_GITHUB_RELEASE_ENABLED') == '1'
        self.health, self.directory, self.api, self.clock = health, Path(directory), api, clock

    def state(self):
        path = private_dir(self.directory) / 'state.json'
        if not path.exists():
            return {}
        if path.is_symlink():
            raise ValueError('invalid state')
        return json.loads(path.read_text())

    def active(self):
        return bool(self.cfg and self.state().get('remote_retry_at', 0) > self.clock())

    def activate(self):
        if not self.cfg:
            return False
        state = self.state()
        state['remote_retry_at'] = self.clock() + 300
        atomic_state(self.directory / 'state.json', state)
        return True

    def poll(self, *, health_only=False):
        if not self.cfg:
            return 'disabled'
        now = self.clock()
        state = self.state()
        if state.get('next_poll_at', 0) > now:
            return 'cooldown'
        # Throttle prior to network access, including failed API calls.
        state['next_poll_at'] = now + 60
        atomic_state(self.directory / 'state.json', state)
        issue, token = self.cfg
        root = '/repos/' + REPO
        repo = self.api(token, 'GET', root)
        if repo.get('private') is not True or repo.get('full_name') != REPO:
            return 'blocked'
        target = self.api(token, 'GET', f'{root}/issues/{issue}')
        if target.get('state') != 'open' or target.get('title') != 'CV contingency — diagnostic mailbox' or 'pull_request' in target:
            return 'blocked'
        since = dt.datetime.fromtimestamp(now - 1800, dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
        path = f'{root}/issues/{issue}/comments'
        comments = self.api(token, 'GET', f'{path}?per_page=100&since={since}')
        if not isinstance(comments, list) or len(comments) >= 100:
            return 'blocked'
        for comment in comments:
            data = command(comment, now)
            if not data:
                continue
            if data['action'] == 'hermes_probe_card' and not self.hermes_probe_enabled:
                continue
            if data['action'] == 'hermes_offline_tests' and not self.hermes_offline_enabled:
                continue
            if data['action'] == 'hermes_dialog_tests' and not self.hermes_dialog_enabled:
                continue
            if data['action'] == 'hermes_fin_contract' and not self.hermes_fin_contract_enabled:
                continue
            if data['action'] in ('hermes_caja_probe', 'hermes_crm_writer_audit', 'hermes_crm_sync_status') and data['action'] not in self.diagnostics_enabled:
                continue
            if health_only and data['action'] not in ('health', 'hermes_probe_card', 'hermes_offline_tests', 'hermes_dialog_tests', 'hermes_fin_contract', 'hermes_caja_probe', 'hermes_crm_writer_audit', 'hermes_crm_sync_status'):
                continue
            if not data or (data['action'] == 'receiver_classification' and not self.receiver_classification_enabled) or (data['action'] == 'receiver_contract' and not self.receiver_contract_enabled) or (data['action'] == 'dispatch_preflight' and not self.preflight_enabled) or (data['action'] == 'release_apply' and not self.release_enabled) or not reserve(self.directory, data['nonce'], comment['id']):
                continue
            # One fixed read-only diagnostic. No retry if post outcome is uncertain.
            if data['action'] == 'health':
                summary = self.health()
                safe = health_summary(summary[0], summary[1])
            elif data['action'] == 'hermes_probe_card':
                safe = hermes_probe_card(data, self.clock())
            elif data['action'] in ('hermes_offline_tests', 'hermes_dialog_tests'):
                safe = hermes_offline_tests(data, self.clock())
            elif data['action'] == 'hermes_fin_contract':
                safe = hermes_fin_contract()
            elif data['action'] == 'hermes_caja_probe':
                safe = hermes_caja_probe()
            elif data['action'] == 'hermes_crm_writer_audit':
                safe = hermes_crm_writer_audit()
            elif data['action'] == 'hermes_crm_sync_status':
                safe = hermes_crm_sync_status()
            elif data['action'] == 'receiver_classification':
                safe = receiver_classification(data['sha256'])
            elif data['action'] == 'receiver_contract':
                safe = receiver_contract()
            elif data['action'] == 'dispatch_preflight':
                safe = dispatch_preflight()
            elif data['action'] == 'agent_inventory':
                safe = agent_inventory()
            elif data['action'] == 'db_snapshot':
                safe = db_snapshot()
            elif data['action'] == 'urgent_triage':
                safe = urgent_triage()
            elif data['action'] == 'wa_catalog_case':
                safe = wa_catalog_case()
            elif data['action'] == 'order_lookup':
                safe = order_lookup(data['product'])
            elif data['action'] == 'release_apply':
                try:
                    from github_release_gate import apply_release
                    safe = apply_release(data['sha'], self.api)
                except ImportError:
                    safe = {'ok': False, 'reason': 'release_unavailable'}
            else:
                continue
            report = {'action': data['action'], 'request_id': comment['id'], 'result': safe}
            self.api(token, 'POST', path, {'body': 'CV-CONTINGENCY-RESULT-V1 ' + json.dumps(report, ensure_ascii=False, separators=(',', ':'))})
            return 'reported'
        return 'idle'
