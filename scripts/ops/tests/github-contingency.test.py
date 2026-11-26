import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock
import sys
import urllib.error

PATH = Path(__file__).resolve().parents[1] / 'github_contingency.py'
spec = importlib.util.spec_from_file_location('contingency', PATH)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

bridge_path = PATH.parent / 'cv-ops-bridge-contingency-candidate.py'
if not bridge_path.exists():
    bridge_path = PATH.parent / 'cv-ops-bridge.py'
bridge_spec = importlib.util.spec_from_file_location('bridge', bridge_path)
bridge = importlib.util.module_from_spec(bridge_spec)
bridge_spec.loader.exec_module(bridge)

NOW = mod.timestamp('2026-11-20T16:00:00Z')
ENV = {'CV_GITHUB_CONTINGENCY_ENABLED': '1', 'CV_GITHUB_CONTINGENCY_ISSUE': '22', 'CV_GITHUB_CONTINGENCY_TOKEN': 'synthetic'}


def request(**overrides):
    row = {'id': 1, 'user': {'login': mod.OWNER, 'type': 'User'}, 'author_association': 'OWNER',
           'created_at': '2026-11-20T15:59:00Z', 'updated_at': '2026-11-20T15:59:00Z',
           'body': mod.PREFIX + json.dumps({'action': 'health', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z'})}
    row.update(overrides)
    return row


class Tests(unittest.TestCase):
    def test_order_lookup_rejects_injection_and_unknown_fields(self):
        data={'action':'order_lookup','nonce':'syntheticnonce1234','expires_at':'2026-11-20T16:10:00Z','product':'puerta corredera'}
        self.assertIsNotNone(mod.command(request(body=mod.PREFIX+json.dumps(data)),NOW))
        for bad in ["x' OR 1=1", "12345678", "a"*49, "a_b", "a%", "a\nb"]:
            self.assertIsNone(mod.command(request(body=mod.PREFIX+json.dumps(dict(data,product=bad))),NOW))
        self.assertIsNone(mod.command(request(body=mod.PREFIX+json.dumps(dict(data,phone='private'))),NOW))

    def test_order_lookup_only_safe_metadata(self):
        good={'tenant_ok':True,'matches':2,'chats':1,'order_ids':[1234],'audio_notes':1,'last_day':'2026-11-20'}
        with patch.object(mod.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(good).encode())) as run:
            self.assertEqual(mod.order_lookup('corredera'),{'ok':True,'evidence':good})
            self.assertIn("BEGIN READ ONLY",run.call_args.args[0][-1])
            self.assertIn("LIKE '%corredera%'",run.call_args.args[0][-1])
        for bad in [dict(good,body='private'),dict(good,tenant_ok=False),dict(good,order_ids=['private']),dict(good,last_day='private')]:
            with patch.object(mod.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(bad).encode())):
                self.assertFalse(mod.order_lookup('corredera')['ok'])

    def test_urgent_triage_counts_only(self):
        good={k:1 for k in mod.TRIAGE_FIELDS}
        with patch.object(mod.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(good).encode())) as run:
            self.assertEqual(mod.urgent_triage(),{'ok':True,'signals':good})
            args,kw=run.call_args
            self.assertEqual(args[0][-1],mod.TRIAGE_SQL)
            self.assertIn('BEGIN READ ONLY',mod.TRIAGE_SQL)
            self.assertEqual(set(kw['env']),{'PATH','HOME'})
        for bad in [dict(good,raw='private'),dict(good,errors_open=2),dict(good,wa_money_signals='private')]:
            with patch.object(mod.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(bad).encode())):
                self.assertEqual(mod.urgent_triage(),{'ok':False,'reason':'triage_unavailable'})

    def test_urgent_triage_does_not_export_database_errors(self):
        result = mod.subprocess.CompletedProcess([], 1, b'', b'ERROR:  42P01\nDETAIL: private\n')
        with patch.object(mod.subprocess, 'run', return_value=result):
            self.assertEqual(mod.urgent_triage(),
                             {'ok': False, 'reason': 'triage_unavailable', 'stage': 'missing_relation'})

    def test_urgent_triage_no_arguments(self):
        payload={'action':'urgent_triage','nonce':'syntheticnonce1234','expires_at':'2026-11-20T16:10:00Z'}
        self.assertIsNotNone(mod.command(request(body=mod.PREFIX+json.dumps(payload)),NOW))
        self.assertIsNone(mod.command(request(body=mod.PREFIX+json.dumps(dict(payload,sql='SELECT private'))),NOW))

    def test_db_snapshot_fixed_read_only_and_redaction(self):
        good = {k: 1 for k in mod.DB_COUNT_FIELDS}
        good.update({k: '2026-11-19' for k in mod.DB_DAY_FIELDS})
        with patch.object(mod.subprocess, 'run', return_value=Mock(returncode=0, stdout=json.dumps(good).encode())) as run:
            self.assertEqual(mod.db_snapshot(), {'ok': True, 'snapshot': good})
            args, kw = run.call_args
            self.assertEqual(args[0][:6], ['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db','psql'])
            self.assertIn('BEGIN READ ONLY', args[0][-1])
            self.assertEqual(set(kw['env']), {'PATH', 'HOME'})
        for bad in [dict(good, password='private'), dict(good, devices=True), dict(good, last_crm_day='private')]:
            with patch.object(mod.subprocess, 'run', return_value=Mock(returncode=0, stdout=json.dumps(bad).encode())):
                self.assertEqual(mod.db_snapshot(), {'ok': False, 'reason': 'snapshot_unavailable'})

    def test_db_snapshot_command_rejects_parameters(self):
        payload = {'action':'db_snapshot','nonce':'syntheticnonce1234','expires_at':'2026-11-20T16:10:00Z'}
        self.assertIsNotNone(mod.command(request(body=mod.PREFIX+json.dumps(payload)), NOW))
        self.assertIsNone(mod.command(request(body=mod.PREFIX+json.dumps(dict(payload, sql='SELECT secret'))), NOW))

    def test_release_command_only_exact_sha(self):
        payload = {'action': 'release_apply', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z', 'sha': 'a'*40}
        self.assertIsNotNone(mod.command(request(body=mod.PREFIX + json.dumps(payload)), NOW))
        for bad in [dict(payload, sha='main'), dict(payload, sha='a'*39), dict(payload, command='reboot'), dict(payload, path='/root')]:
            self.assertIsNone(mod.command(request(body=mod.PREFIX + json.dumps(bad)), NOW))

    def test_release_requires_local_opt_in(self):
        row = request(body=mod.PREFIX + json.dumps({'action':'release_apply','sha':'a'*40,'nonce':'syntheticnonce1234','expires_at':'2026-11-20T16:10:00Z'}))
        def api(token, method, path, body=None):
            self.assertEqual(method, 'GET')
            if '/comments?' in path: return [row]
            if path.endswith('/issues/22'): return {'title':'CV contingency — diagnostic mailbox','state':'open'}
            return {'private':True,'full_name':mod.REPO}
        with tempfile.TemporaryDirectory() as d:
            c = mod.Contingency(ENV, lambda: self.fail('health'), Path(d), api, lambda: NOW)
            self.assertEqual(c.poll(), 'idle')
            self.assertEqual(list(Path(d).glob('nonce-*')), [])

    def test_release_gate_ci_requires_exact_push(self):
        spec = importlib.util.spec_from_file_location('release_gate', PATH.parent / 'github_release_gate.py')
        gate = importlib.util.module_from_spec(spec); spec.loader.exec_module(gate)
        sha = 'a'*40
        rows = [dict(workflow_id=wid, head_sha=sha, head_branch='main', event='push', status='completed', conclusion='success', run_number=1) for wid in gate.CHECKS]
        api = lambda *a: {'total_count': len(rows), 'workflow_runs': rows}
        self.assertTrue(gate.verified_checks(api, 'synthetic', sha))
        rows[0]['event'] = 'pull_request'
        self.assertFalse(gate.verified_checks(api, 'synthetic', sha))
        rows[0]['event'] = 'push'; rows[0]['conclusion'] = 'failure'
        self.assertFalse(gate.verified_checks(api, 'synthetic', sha))

    def test_release_gate_blocks_unprotected_main(self):
        spec = importlib.util.spec_from_file_location('release_gate', PATH.parent / 'github_release_gate.py')
        gate = importlib.util.module_from_spec(spec); spec.loader.exec_module(gate)
        sha = 'a'*40
        with patch.object(gate, 'read_private', return_value=b'CV_GITHUB_RELEASE_TOKEN=synthetic'), patch.object(gate, 'git', side_effect=[b'', sha.encode()]), patch.object(gate, 'atomic_file') as write:
            self.assertEqual(gate.apply_release(sha, lambda *a: {'protected': False, 'commit': {'sha': sha}}), {'ok': False, 'reason': 'main_not_protected'})
            write.assert_not_called()

    def test_release_gate_atomic_backup_and_main_recheck(self):
        spec = importlib.util.spec_from_file_location('release_gate', PATH.parent / 'github_release_gate.py')
        gate = importlib.util.module_from_spec(spec); spec.loader.exec_module(gate)
        sha = 'a'*40
        body = b'# Synthetic compiled module\n' + b'# x\n'*300
        with tempfile.TemporaryDirectory() as d:
            root = Path(d); target = root/'github_contingency.py'; token = root/'.github-release.env'
            target.write_bytes(b'old'); target.chmod(0o600)
            token.write_bytes(b'CV_GITHUB_RELEASE_TOKEN=synthetic'); token.chmod(0o600)
            api = lambda *a: {'protected': True, 'commit': {'sha': sha}}
            with patch.object(gate, 'ROOT', root), patch.object(gate, 'TARGET', target), patch.object(gate, 'TOKEN_FILE', token), patch.object(gate, 'verified_checks', return_value=True), patch.object(gate, 'git', side_effect=[b'', sha.encode(), body, b'', b'b'*40]):
                self.assertEqual(gate.apply_release(sha, api)['reason'], 'main_moved')
                self.assertEqual(target.read_bytes(), b'old')
            with patch.object(gate, 'ROOT', root), patch.object(gate, 'TARGET', target), patch.object(gate, 'TOKEN_FILE', token), patch.object(gate, 'verified_checks', return_value=True), patch.object(gate, 'git', side_effect=[b'', sha.encode(), body, b'', sha.encode()]):
                self.assertEqual(gate.apply_release(sha, api), {'ok': True, 'changed': True, 'sha': sha})
                self.assertEqual(target.read_bytes(), body)
                self.assertEqual(next(root.glob('github_contingency.backup.*')).read_bytes(), b'old')

    def test_preflight_requires_opt_in_and_redacts_payload(self):
        row = request(body=mod.PREFIX + json.dumps({'action': 'dispatch_preflight', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z'}))
        posts = []
        def api(token, method, path, body=None):
            if method == 'POST':
                posts.append(body)
                return {}
            if '/comments?' in path: return [row]
            if path.endswith('/issues/22'): return {'title': 'CV contingency — diagnostic mailbox', 'state': 'open'}
            return {'private': True, 'full_name': mod.REPO}
        with tempfile.TemporaryDirectory() as d, patch.object(mod, 'dispatch_preflight', return_value={'ok': False, 'reason': 'missing_config'}) as runner:
            c = mod.Contingency(ENV, lambda: self.fail('health'), Path(d), api, lambda: NOW)
            self.assertEqual(c.poll(), 'idle'); runner.assert_not_called()
            c = mod.Contingency(dict(ENV, CV_GITHUB_DISPATCH_PREFLIGHT_ENABLED='1'), lambda: self.fail('health'), Path(d), api, lambda: NOW + 61)
            self.assertEqual(c.poll(), 'reported'); runner.assert_called_once()
            self.assertIn('dispatch_preflight', posts[0]['body'])
            self.assertEqual(c.poll(), 'cooldown')

    def test_receiver_contract_requires_opt_in_and_filters_source(self):
        body = mod.PREFIX + json.dumps({
            'action': 'receiver_contract', 'nonce': 'syntheticnonce1234',
            'expires_at': '2026-11-20T16:10:00Z',
        })
        posts = []

        def api(token, method, path, body=None):
            if method == 'POST':
                posts.append(json.loads(body['body'].split(' ', 1)[1]))
                return {}
            if '/comments?' in path:
                return [request(body=test_request)]
            if path.endswith('/issues/22'):
                return {'title': 'CV contingency — diagnostic mailbox', 'state': 'open'}
            return {'private': True, 'full_name': mod.REPO}

        test_request = body
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            file = root / 'recovery-replies.mjs'
            file.write_text('function classify(m) {}\nfunction respond(m) {}\n// fixture-private')
            file.chmod(0o600)
            with patch.object(mod, 'RECEIVER_REPLIES', file):
                inactive = mod.Contingency(ENV, lambda: self.fail('health'), root, api, lambda: NOW)
                self.assertEqual(inactive.poll(), 'idle')
                self.assertEqual(posts, [])
                env = dict(ENV, CV_GITHUB_RECEIVER_CONTRACT_ENABLED='1')
                active = mod.Contingency(env, lambda: self.fail('health'), root, api, lambda: NOW + 61)
                self.assertEqual(active.poll(), 'reported')
                result = posts[0]['result']
                self.assertEqual(result, {
                    'ok': True,
                    'sha256': mod.hashlib.sha256(file.read_bytes()).hexdigest(),
                    'mentions_classify': True,
                    'mentions_respond': True,
                    'mentions_send_message': False,
                })
                file.chmod(0o644)
                self.assertEqual(mod.receiver_contract(), {
                    'ok': False, 'reason': 'receiver_source_unavailable',
                })

    def test_preflight_summary_strict_allowlist(self):
        checks = {name: {'ok': True, **{key: 1 for key in mod.PREFLIGHT_COUNTS.get(name, set())}, 'secret': 'private'} for name in mod.PREFLIGHT_CHECKS}
        data = {'ok': True, 'checks': checks, 'raw': 'private'}
        result = mod.preflight_summary(json.dumps(data))
        self.assertTrue(result['ok']); self.assertNotIn('private', str(result))
        checks['core_direct']['pending'] = 'secret'
        self.assertFalse(mod.preflight_summary(json.dumps(data))['ok'])
        del checks['group']
        self.assertFalse(mod.preflight_summary(json.dumps(data))['ok'])

    def test_preflight_mismatch_never_executes(self):
        with patch.object(mod, 'private_bytes', return_value=b'untrusted'), patch.object(mod.subprocess, 'run') as run:
            self.assertEqual(mod.dispatch_preflight(), {'ok': False, 'reason': 'script_mismatch'})
            run.assert_not_called()

    def test_preflight_uses_fixed_command_and_scoped_environment(self):
        source = b'export async function preflight() {}'
        blob = mod.hashlib.sha1(b'blob ' + str(len(source)).encode() + b'\0' + source).hexdigest()
        cfg = '\n'.join(k + '=synthetic' for k in sorted(mod.PREFLIGHT_KEYS)) + '\nNODE_OPTIONS=--require=unsafe\nCV_GITHUB_CONTINGENCY_TOKEN=private'
        result = Mock(returncode=0, stdout=b'{"ok":false,"checks":{}}')
        with patch.object(mod, 'PREFLIGHT_BLOB', blob), patch.object(mod, 'private_bytes', side_effect=[source, cfg.encode()]), patch.object(mod.subprocess, 'run', return_value=result) as run:
            self.assertFalse(mod.dispatch_preflight()['ok'])
            args, kw = run.call_args
            self.assertEqual(args[0], ['/usr/bin/node', '--input-type=module'])
            self.assertEqual(set(kw['env']), mod.PREFLIGHT_KEYS | {'PATH', 'RECOVERY_RECONCILE'})
            self.assertEqual(kw['env']['RECOVERY_RECONCILE'], '1')
            self.assertNotIn('private', str(kw))

    def test_command_allowlist(self):
        self.assertIsNotNone(mod.command(request(), NOW))
        for row in [request(author_association='MEMBER'), request(user={'login': 'unknown', 'type': 'User'}),
                    request(updated_at='2026-11-20T15:59:01Z'), request(body='shell: restart'),
                    request(body=mod.PREFIX + json.dumps({'action': 'deploy', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z'}))]:
            self.assertIsNone(mod.command(row, NOW))
        self.assertIsNone(mod.command(request(), NOW + 601))

    def test_explicit_opt_in(self):
        with tempfile.TemporaryDirectory() as d:
            c = mod.Contingency({}, lambda: self.fail('health'), Path(d), api=lambda *a: self.fail('api'))
            self.assertFalse(c.active())
            self.assertFalse(c.activate())
            self.assertEqual(c.poll(), 'disabled')

    def test_private_scope_throttle_replay_and_redacted_receipt(self):
        now = [NOW]
        posts = []
        calls = []
        def api(token, method, path, body=None):
            calls.append((method, path))
            self.assertEqual(token, 'synthetic')
            if method == 'POST':
                posts.append(body)
                return {}
            if path.endswith('/comments') or '/comments?' in path:
                return [request()]
            if path.endswith('/issues/22'):
                return {'title': 'CV contingency — diagnostic mailbox', 'state': 'open'}
            return {'private': True, 'full_name': mod.REPO}
        health_calls = []
        def health():
            health_calls.append(1)
            return ({'returncode': 0, 'output': json.dumps([{'name': 'vivabot', 'status': 'online', 'secret': 'must-not-leak'}, {'name': 'private-name', 'status': 'online'}])}, 'active')
        with tempfile.TemporaryDirectory() as d:
            c = mod.Contingency(ENV, health, Path(d), api, lambda: now[0])
            self.assertTrue(c.activate()); self.assertTrue(c.active())
            self.assertEqual(c.poll(), 'reported')
            self.assertEqual(c.poll(), 'cooldown')
            now[0] += 61
            c = mod.Contingency(ENV, health, Path(d), api, lambda: now[0])
            self.assertEqual(c.poll(), 'idle')
            self.assertEqual(len(posts), 1); self.assertEqual(len(health_calls), 1)
            self.assertNotIn('must-not-leak', str(posts)); self.assertNotIn('private-name', str(posts)); self.assertNotIn('syntheticnonce', str(posts))
            now[0] += 240
            self.assertFalse(c.active())

    def test_triage_and_lookup_route_to_read_only_handlers(self):
        self.assertNotIn('def __init__', mod.TRIAGE_SQL)
        for action, handler, fields, expected in [
            ('urgent_triage', 'urgent_triage', {}, ()),
            ('wa_catalog_case', 'wa_catalog_case', {}, ()),
            ('order_lookup', 'order_lookup', {'product': 'puerta corredera'}, ('puerta corredera',)),
        ]:
            with self.subTest(action=action), tempfile.TemporaryDirectory() as d:
                data = {'action': action, 'nonce': 'syntheticnonce1234',
                        'expires_at': '2026-11-20T16:10:00Z', **fields}
                posts = []

                def api(token, method, path, body=None):
                    if method == 'POST':
                        posts.append(body['body'])
                        return {}
                    if '/comments?' in path:
                        return [request(body=mod.PREFIX + json.dumps(data))]
                    if path.endswith('/issues/22'):
                        return {'title': 'CV contingency — diagnostic mailbox', 'state': 'open'}
                    return {'private': True, 'full_name': mod.REPO}

                result = {'ok': True}
                with patch.object(mod, handler, return_value=result) as diagnosis:
                    bridge = mod.Contingency(ENV, lambda: self.fail('health'), Path(d), api, lambda: NOW)
                    self.assertEqual(bridge.poll(), 'reported')
                    diagnosis.assert_called_once_with(*expected)
                self.assertEqual(json.loads(posts[0].split(' ', 1)[1]),
                                 {'action': action, 'request_id': 1, 'result': result})

    def test_fixed_wa_case_rejects_raw_output(self):
        for payload, expected in [
            ({'matches': 1, 'reply': 'catalog_no_match'}, True),
            ({'matches': 0, 'reply': 'not_checked'}, True),
            ({'matches': 2, 'reply': 'catalog_no_match'}, False),
            ({'matches': 1, 'reply': 'private text'}, False),
            ({'matches': 1, 'reply': 'no_outbound', 'body': 'private'}, False),
        ]:
            result = Mock(returncode=0, stdout=json.dumps(payload).encode())
            with patch.object(mod.subprocess, 'run', return_value=result):
                self.assertIs(mod.wa_catalog_case()['ok'], expected)
        self.assertIn('BEGIN READ ONLY', mod.WA_CATALOG_CASE_SQL)

    def test_public_repo_blocks_before_health(self):
        with tempfile.TemporaryDirectory() as d:
            c = mod.Contingency(ENV, lambda: self.fail('health'), Path(d), lambda *a: {'private': False, 'full_name': mod.REPO}, lambda: NOW)
            self.assertEqual(c.poll(), 'blocked')

    def test_uncertain_post_no_replay(self):
        now = [NOW]
        posts = []
        def api(token, method, path, body=None):
            if method == 'POST':
                posts.append(1); raise TimeoutError()
            if '/comments?' in path: return [request()]
            if path.endswith('/issues/22'): return {'title': 'CV contingency — diagnostic mailbox', 'state': 'open'}
            return {'private': True, 'full_name': mod.REPO}
        with tempfile.TemporaryDirectory() as d:
            c = mod.Contingency(ENV, lambda: ({'returncode': 1, 'output': 'secret'}, 'unknown'), Path(d), api, lambda: now[0])
            with self.assertRaises(TimeoutError): c.poll()
            now[0] += 61
            self.assertEqual(c.poll(), 'idle'); self.assertEqual(len(posts), 1)

    def test_existing_bridge_only_falls_back_on_initial_402(self):
        fallback = Mock(cfg=True)
        fallback.active.return_value = False
        factory = Mock(return_value=fallback)
        with tempfile.TemporaryDirectory() as d, patch.object(bridge, 'LOCK', str(Path(d) / 'lock')), \
                patch.object(bridge, 'load_env_files', return_value=dict(ENV, CV_GITHUB_CONTINGENCY_ALWAYS_POLL='0')), patch.object(bridge, 'find_supabase', return_value=('synthetic', 'synthetic')), \
                patch.dict(sys.modules, {'github_contingency': Mock(Contingency=factory)}), \
                patch.object(bridge, 'api_request', side_effect=urllib.error.HTTPError('synthetic', 402, 'quota', None, None)):
            self.assertEqual(bridge.main(), 0)
            fallback.activate.assert_called_once(); fallback.poll.assert_called_once()
        fallback.reset_mock()
        with tempfile.TemporaryDirectory() as d, patch.object(bridge, 'LOCK', str(Path(d) / 'lock')), \
                patch.object(bridge, 'load_env_files', return_value=dict(ENV, CV_GITHUB_CONTINGENCY_ALWAYS_POLL='0')), patch.object(bridge, 'find_supabase', return_value=('synthetic', 'synthetic')), \
                patch.dict(sys.modules, {'github_contingency': Mock(Contingency=factory)}), \
                patch.object(bridge, 'api_request', side_effect=urllib.error.HTTPError('synthetic', 401, 'denied', None, None)):
            with self.assertRaises(urllib.error.HTTPError): bridge.main()
            fallback.activate.assert_not_called(); fallback.poll.assert_not_called()

    def test_permanent_mailbox_default_and_healthy_primary(self):
        fallback=Mock(cfg=True)
        fallback.active.return_value=False
        with tempfile.TemporaryDirectory() as d, patch.object(bridge,'LOCK',str(Path(d)/'lock')), patch.object(bridge,'load_env_files',return_value=ENV), patch.object(bridge,'find_supabase',return_value=('synthetic','synthetic')), patch.dict(sys.modules,{'github_contingency':Mock(Contingency=Mock(return_value=fallback))}), patch.object(bridge,'api_request',return_value=[]) as primary:
            self.assertEqual(bridge.main(),0)
            fallback.poll.assert_called_once()
            primary.assert_called_once()
            fallback.activate.assert_not_called()

    def test_local_crm_probe_bounded_and_sanitized(self):
        good=dict(wa_count=10,crm_count=5,business_count=1,casa_viva_count=1,wa_casa_viva_count=10,wa_recent_count=3,wa_latest='2026-11-26T12:00:00+00:00',crm_latest=None)
        with patch.object(bridge.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(good).encode())) as run:
            self.assertEqual(json.loads(bridge.action_local_crm_status({})['output']),good)
            self.assertIn('BEGIN READ ONLY',run.call_args.args[0][-1])
            self.assertEqual(bridge.action_local_crm_status({'sql':'SELECT private'})['returncode'],2)
            run.assert_called_once()
        with patch.object(bridge.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(dict(good,secret='private')).encode())):
            self.assertEqual(bridge.action_local_crm_status({})['output'],'local_crm_probe_unavailable')

    def test_recovery_probe_does_not_export_secrets_or_certify_session(self):
        responses=[Mock(returncode=0,stdout=b'true'),Mock(returncode=0,stdout=b'true'),Mock(returncode=0,stdout=b'{"tables":8,"tenant":1,"message_columns":8}'),Mock(returncode=0,stdout=b'[{"name":"vivabot","pm2_env":{"status":"stopped","secret":"private"}},{"name":"vivabot-recovery-receiver","pm2_env":{"status":"online"}}]')]
        with patch.object(bridge.subprocess,'run',side_effect=responses) as run:
            r=bridge.action_local_recovery_preflight({})
            self.assertEqual(r['returncode'],0)
            d=json.loads(r['output'])
            self.assertTrue(d['schema_ok'])
            self.assertEqual(d['vivabot_state'],'stopped')
            self.assertFalse(d['postgrest_auth_certified'])
            self.assertFalse(d['whatsapp_session_certified'])
            self.assertNotIn('private',r['output'])
            self.assertIn('BEGIN READ ONLY',run.call_args_list[2].args[0][-1])
            self.assertEqual(bridge.action_local_recovery_preflight({'command':'anything'})['returncode'],2)
            self.assertEqual(run.call_count,4)

    def test_dispatch_auth_reuses_pinned_preflight_and_rejects_args(self):
        good={'ok':True,'checks':{name:dict({'ok':True},**{k:0 for k in mod.PREFLIGHT_COUNTS.get(name,set())}) for name in mod.PREFLIGHT_CHECKS}}
        good['secret']='private'
        with patch.dict(sys.modules,{'github_contingency':Mock(dispatch_preflight=Mock(return_value=good),preflight_summary=mod.preflight_summary)}) as modules:
            r=bridge.action_recovery_dispatch_auth({})
            self.assertEqual(r['returncode'],0)
            self.assertNotIn('private',r['output'])
            self.assertEqual(bridge.action_recovery_dispatch_auth({'url':'anything'})['returncode'],2)
            modules['github_contingency'].dispatch_preflight.assert_called_once()
        with patch.dict(sys.modules,{'github_contingency':Mock(dispatch_preflight=Mock(return_value={'ok':False,'reason':'missing_config','secret':'private'}),preflight_summary=mod.preflight_summary)}):
            r=bridge.action_recovery_dispatch_auth({})
            self.assertEqual(r['returncode'],1)
            self.assertEqual(json.loads(r['output']),{'ok':False,'reason':'missing_config'})

    def test_preflight_evidence_reports_only_metadata(self):
        import hashlib
        with tempfile.TemporaryDirectory() as d:
            script=Path(d)/'script'; config=Path(d)/'env'
            raw=b'fixed test script'; script.write_bytes(raw); script.chmod(0o600)
            config.write_text('SUPABASE_SERVICE_KEY=never-export-this\\n'); config.chmod(0o600)
            blob=hashlib.sha1(b'blob '+str(len(raw)).encode()+bytes([0])+raw).hexdigest()
            with patch.dict(sys.modules,{'github_contingency':Mock(private_bytes=mod.private_bytes,PREFLIGHT_SCRIPT=script,PREFLIGHT_ENV=config,PREFLIGHT_BLOB=blob,PREFLIGHT_KEYS={'SUPABASE_SERVICE_KEY','SUPABASE_URL'})}):
                r=bridge.action_recovery_preflight_evidence({})
                self.assertEqual(r['returncode'],0)
                data=json.loads(r['output'])
                self.assertTrue(data['script_hash_ok'])
                self.assertFalse(data['config_complete'])
                self.assertEqual(data['config_keys_present'],['SUPABASE_SERVICE_KEY'])
                self.assertNotIn('never-export-this',r['output'])
                config.unlink()
                self.assertFalse(json.loads(bridge.action_recovery_preflight_evidence({})['output'])['config_present'])
                self.assertEqual(bridge.action_recovery_preflight_evidence({'path':'x'})['returncode'],2)

    def test_pinned_install_rejects_arguments_and_wrong_contract(self):
        self.assertEqual(bridge.action_install_recovery_preflight({'url':'other'})['returncode'],2)
        with patch.dict(sys.modules,{'github_contingency':Mock(PREFLIGHT_SCRIPT=Path('/tmp/wrong'),PREFLIGHT_BLOB='wrong',private_bytes=Mock())}), patch.object(bridge.urllib.request,'urlopen') as fetch:
            self.assertEqual(bridge.action_install_recovery_preflight({})['returncode'],1)
            fetch.assert_not_called()
    def test_bundled_script_has_canonical_hash(self):
        import hashlib
        raw=bridge.BUNDLED_RECOVERY_PREFLIGHT.encode('utf-8')
        self.assertEqual(hashlib.sha1(b'blob '+str(len(raw)).encode()+bytes([0])+raw).hexdigest(),'5dfafab9d7120f8c002999c30f1dd31e5eff5754')
    def test_bundled_install_rejects_modified_payload(self):
        with patch.dict(sys.modules,{'github_contingency':Mock(PREFLIGHT_SCRIPT=Path('/root/supervisor/recovery-dispatch-preflight.mjs'),PREFLIGHT_BLOB='5dfafab9d7120f8c002999c30f1dd31e5eff5754',private_bytes=Mock())}), patch.object(Path,'exists',return_value=False), patch.object(bridge,'BUNDLED_RECOVERY_PREFLIGHT','wrong'), patch.object(bridge.os,'open') as write:
            self.assertEqual(bridge.action_install_recovery_preflight({})['returncode'],1)
            write.assert_not_called()
    def test_restore_config_rejects_remote_source(self):
        keys={'SUPABASE_URL','SUPABASE_SERVICE_KEY','DISPATCH_GROUP','BUSINESS_SLUG','WOO_URL','CURRU_KEY'}
        raw=b'SUPABASE_URL=https://cloud.example\\nSUPABASE_SERVICE_KEY=private\\nDISPATCH_GROUP=Reparto\\nBUSINESS_SLUG=casa-viva\\nWOO_URL=https://casaviva.company\\nCURRU_KEY=private\\n'
        with patch.dict(sys.modules,{'github_contingency':Mock(PREFLIGHT_ENV=Path('/root/supervisor/.dispatch-preflight.env'),PREFLIGHT_KEYS=keys,private_bytes=Mock(return_value=raw))}), patch.object(Path,'exists',return_value=False), patch.object(bridge,'action_install_recovery_preflight',return_value={'returncode':0}), patch.object(bridge.subprocess,'run') as run, patch.object(bridge.os,'open') as write:
            result=bridge.action_restore_recovery_config({})
            self.assertEqual(result['returncode'],1)
            self.assertNotIn('private',result['output'])
            run.assert_not_called()
            write.assert_not_called()
            self.assertEqual(bridge.action_restore_recovery_config({'path':'other'})['returncode'],2)

    def test_effective_recovery_missing_keys_safe(self):
        import hashlib
        raw=b'canonical script'
        blob=hashlib.sha1(b'blob '+str(len(raw)).encode()+bytes([0])+raw).hexdigest()
        keys={'SUPABASE_URL','SUPABASE_SERVICE_KEY','DISPATCH_GROUP','BUSINESS_SLUG','WOO_URL','CURRU_KEY'}
        script=Path('/root/supervisor/recovery-dispatch-preflight.mjs')
        def read(path,limit):
            return raw if path==script else b'SUPABASE_URL=http://localhost:3000\\nSUPABASE_SERVICE_KEY=file-private\\n'
        rows=[{'name':'vivabot-recovery-receiver','pm2_env':{'status':'online','pm_cwd':'/root/backups/nexo-recovery/receiver','SUPABASE_URL':'https://cloud.example','SUPABASE_SERVICE_KEY':'pm2-private'}}]
        contract=Mock(PREFLIGHT_ENV=Path('/root/supervisor/.dispatch-preflight.env'),PREFLIGHT_KEYS=keys,PREFLIGHT_SCRIPT=script,PREFLIGHT_BLOB=blob,private_bytes=read)
        with patch.dict(sys.modules,{'github_contingency':contract}), patch.object(Path,'exists',return_value=False), patch.object(bridge.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(rows).encode())) as run, patch.object(bridge.os,'open') as write:
            result=bridge.action_restore_effective_recovery({})
            self.assertEqual(result['returncode'],1)
            self.assertEqual(json.loads(result['output'])['reason'],'effective_config_incomplete')
            self.assertNotIn('private',result['output'])
            self.assertNotIn('cloud.example',result['output'])
            self.assertEqual(run.call_count,1)
            write.assert_not_called()
            self.assertEqual(bridge.action_restore_effective_recovery({'pid':123})['returncode'],2)

    def test_receiver_contract_rejects_external_entry(self):
        rows=[{'name':'vivabot-recovery-receiver','pm2_env':{'status':'online','pm_cwd':'/root/backups/nexo-recovery/receiver','pm_exec_path':'/tmp/outside.mjs','secret':'not-exported'}}]
        with patch.object(bridge.subprocess,'run',return_value=Mock(returncode=0,stdout=json.dumps(rows).encode())), patch.object(bridge.os,'open') as read:
            result=bridge.action_receiver_crm_contract({})
            self.assertEqual(result['returncode'],1)
            self.assertNotIn('not-exported',result['output'])
            read.assert_not_called()
            self.assertEqual(bridge.action_receiver_crm_contract({'path':'other'})['returncode'],2)

    def test_local_sql_adapter_restricts_core_and_crm_scope(self):
        wrapper=bridge.LOCAL_SQL_PREFLIGHT_WRAPPER
        self.assertIn("requireParams(q,['select','business_id','order_id','limit'])",wrapper)
        self.assertIn("q.get('business_id')!=='eq.'+s.businesses[0]",wrapper)
        self.assertIn("q.get('select')!=='order_id,stage,messenger_phone,announce_msg_id,announced_at'",wrapper)
        self.assertIn("if(u.origin!=='http://127.0.0.1')",wrapper)
        self.assertIn("if(u.origin==='https://casaviva.company')",wrapper)
        self.assertIn("q.get('slug')!=='eq.casa-viva'",wrapper)
        self.assertIn("options?.headers?.[\'X-VivaBot-Key\']!==c.CURRU_KEY",wrapper)

    def test_local_sql_schema_includes_tenant_scope(self):
        import inspect
        source=inspect.getsource(bridge.action_local_sql_reconcile)
        self.assertIn("'business_id','order_id','stage'",source)
        self.assertIn("count(*)=8",source)
        self.assertIn("BEGIN READ ONLY",inspect.getsource(bridge.local_crm_readonly))

    def test_local_sql_transport_readonly(self):
        with patch.object(bridge.subprocess,'run',return_value=Mock(returncode=0,stdout=b'{"ok":true}')) as run:
            self.assertEqual(bridge.local_crm_readonly('SELECT json_build_object()'),{'ok':True})
            cmd=run.call_args.args[0]
            self.assertEqual(cmd[:5],['/usr/bin/docker','exec','-u','postgres','vivabot-recovery-db'])
            self.assertIn('BEGIN READ ONLY',cmd[-1])
            self.assertIn('statement_timeout',cmd[-1])
            self.assertEqual(bridge.action_local_sql_reconcile({'sql':'anything'})['returncode'],2)
            run.assert_called_once()

    def test_local_sql_reconcile_keeps_canonical_hash(self):
        import hashlib
        raw=bridge.BUNDLED_RECOVERY_PREFLIGHT.encode('utf-8')
        self.assertEqual(hashlib.sha1(b'blob '+str(len(raw)).encode()+bytes([0])+raw).hexdigest(),'5dfafab9d7120f8c002999c30f1dd31e5eff5754')

    def test_local_sql_reconcile_rejects_missing_core_config(self):
        import hashlib
        raw=bridge.BUNDLED_RECOVERY_PREFLIGHT.encode('utf-8')
        blob=hashlib.sha1(b'blob '+str(len(raw)).encode()+bytes([0])+raw).hexdigest()
        script=Path('/root/supervisor/recovery-dispatch-preflight.mjs')
        contract=Mock(PREFLIGHT_SCRIPT=script,PREFLIGHT_BLOB=blob,preflight_summary=mod.preflight_summary,private_bytes=lambda p,n:raw if p==script else b'CURRU_KEY=do-not-export')
        with patch.dict(sys.modules,{'github_contingency':contract}), patch.object(bridge,'local_crm_readonly') as sql:
            result=bridge.action_local_sql_reconcile({})
            self.assertEqual(result['returncode'],1)
            self.assertEqual(json.loads(result['output'])['reason'],'core_config_incomplete')
            self.assertNotIn('do-not-export',result['output'])
            sql.assert_not_called()


if __name__ == '__main__':
    unittest.main()
