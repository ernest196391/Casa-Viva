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
        if set(data) != fields or action not in ('health', 'dispatch_preflight', 'agent_inventory', 'db_snapshot', 'receiver_contract', 'receiver_classification', 'urgent_triage', 'order_lookup', 'wa_catalog_case', 'release_apply', 'local_sql_reconcile') or not isinstance(data['nonce'], str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}', data['nonce']):
            return None
        if action == 'release_apply' and (not isinstance(data['sha'], str) or not re.fullmatch(r'[0-9a-f]{40}', data['sha'])):
            return None
        created, expires = timestamp(comment['created_at']), timestamp(data['expires_at'])
        if not created <= now < expires <= created + 1800:
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


# Git blob SHA-1 of the full certified worker (not a substring or a path check).
# A future worker revision requires a separate reviewed change to this pin.
LOCAL_SQL_BRIDGE_BLOB = '84bc0eb4a57a3dc5c4f1ea5377c50eb952446a3a'


def local_sql_reconcile():
    """Execute only the exact certified worker bytes, never reopen the path."""
    try:
        import types
        bridge_path = Path('/root/supervisor/cv-ops-bridge.py')
        source = private_bytes(bridge_path, 262144)
        digest = hashlib.sha1(b'blob ' + str(len(source)).encode() + b'\\0' + source).hexdigest()
        if digest != LOCAL_SQL_BRIDGE_BLOB:
            return {'ok': False, 'reason': 'bridge_integrity_mismatch'}
        # No importlib path load or second disk read: compilation uses the bytes
        # just verified. The module is isolated from the import machinery.
        worker = types.ModuleType('cv_certified_local_sql_bridge')
        worker.__file__ = str(bridge_path)
        exec(compile(source, str(bridge_path), 'exec'), worker.__dict__)
        result = worker.action_local_sql_reconcile({})
        if not isinstance(result, dict) or set(result) != {'returncode', 'output'}:
            raise ValueError('invalid_bridge_response')
        if type(result['returncode']) is not int or result['returncode'] not in (0, 1):
            raise ValueError('invalid_bridge_status')
        output = result['output']
        if not isinstance(output, str) or len(output) > 16384:
            raise ValueError('invalid_bridge_output')
        data = json.loads(output)
        if result['returncode'] == 0:
            if not isinstance(data, dict) or set(data) != {'transport', 'postgrest_auth_certified', 'result'} or data['transport'] != 'local_sql_readonly' or data['postgrest_auth_certified'] is not False:
                raise ValueError('invalid_readonly_proof')
            safe = preflight_summary(json.dumps(data['result']))
            return {'ok': safe['ok'], 'transport': 'local_sql_readonly', 'postgrest_auth_certified': False, 'checks': safe['checks']}
        return {'ok': False, 'reason': 'local_sql_reconcile_unavailable'}
    except Exception:
        return {'ok': False, 'reason': 'local_sql_reconcile_unavailable'}


class Contingency:
    def __init__(self, env, health, directory=Path('/var/lib/cv-ops-bridge-contingency'), api=github_api, clock=time.time):
        self.cfg = config(env)
        self.receiver_classification_enabled = env.get('CV_GITHUB_RECEIVER_CLASSIFICATION_ENABLED') == '1'
        self.receiver_contract_enabled = env.get('CV_GITHUB_RECEIVER_CONTRACT_ENABLED') == '1'
        self.preflight_enabled = env.get('CV_GITHUB_DISPATCH_PREFLIGHT_ENABLED') == '1'
        self.release_enabled = env.get('CV_GITHUB_RELEASE_ENABLED') == '1'
        self.local_sql_enabled = env.get('CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED') == '1'
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

    def poll(self):
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
            if not data or (data['action'] == 'receiver_classification' and not self.receiver_classification_enabled) or (data['action'] == 'receiver_contract' and not self.receiver_contract_enabled) or (data['action'] == 'dispatch_preflight' and not self.preflight_enabled) or (data['action'] == 'release_apply' and not self.release_enabled) or (data['action'] == 'local_sql_reconcile' and not self.local_sql_enabled) or not reserve(self.directory, data['nonce'], comment['id']):
                continue
            # One fixed read-only diagnostic. No retry if post outcome is uncertain.
            if data['action'] == 'health':
                summary = self.health()
                safe = health_summary(summary[0], summary[1])
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
            elif data['action'] == 'local_sql_reconcile':
                safe = local_sql_reconcile()
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
