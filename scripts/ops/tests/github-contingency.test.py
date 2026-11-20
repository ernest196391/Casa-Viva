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
