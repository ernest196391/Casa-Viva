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
                patch.object(bridge, 'load_env_files', return_value=ENV), patch.object(bridge, 'find_supabase', return_value=('synthetic', 'synthetic')), \
                patch.dict(sys.modules, {'github_contingency': Mock(Contingency=factory)}), \
                patch.object(bridge, 'api_request', side_effect=urllib.error.HTTPError('synthetic', 402, 'quota', None, None)):
            self.assertEqual(bridge.main(), 0)
            fallback.activate.assert_called_once(); fallback.poll.assert_called_once()
        fallback.reset_mock()
        with tempfile.TemporaryDirectory() as d, patch.object(bridge, 'LOCK', str(Path(d) / 'lock')), \
                patch.object(bridge, 'load_env_files', return_value=ENV), patch.object(bridge, 'find_supabase', return_value=('synthetic', 'synthetic')), \
                patch.dict(sys.modules, {'github_contingency': Mock(Contingency=factory)}), \
                patch.object(bridge, 'api_request', side_effect=urllib.error.HTTPError('synthetic', 401, 'denied', None, None)):
            with self.assertRaises(urllib.error.HTTPError): bridge.main()
            fallback.activate.assert_not_called(); fallback.poll.assert_not_called()


if __name__ == '__main__':
    unittest.main()
