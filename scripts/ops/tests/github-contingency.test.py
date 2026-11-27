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
    def test_offline_supervised_sql_requires_verified_installed_release(self):
        release_path = PATH.parent / 'github_release_gate.py'
        gate_spec = importlib.util.spec_from_file_location('release_gate_offline_sql', release_path)
        gate = importlib.util.module_from_spec(gate_spec)
        gate_spec.loader.exec_module(gate)
        sha = 'a' * 40
        source = ("from pathlib import Path\n"
                  "LOCAL_SQL_BRIDGE_PATH = Path('/root/supervisor/cv-local-sql-certified.py')\n"
                  "LOCAL_SQL_BRIDGE_BLOB = '84bc0eb4a57a3dc5c4f1ea5377c50eb952446a3a'\n"
                  "def provision_local_sql_artifact():\n"
                  "    return {'ok': True, 'changed': False}\n"
                  + '# reviewed fixture\n' * 90).encode()
        def api(token, method, uri):
            self.assertEqual(method, 'GET')
            return {'protected': True, 'commit': {'sha': sha}}
        def git_ok(*args):
            if args[:2] == ('rev-parse', 'origin/main'):
                return (sha + '\n').encode()
            if args[0] == 'show':
                return source
            if args[0] == 'fetch':
                return b''
            self.fail('unexpected git call')
        def read_ok(path, limit=65536):
            if path == gate.TOKEN_FILE:
                return b'CV_GITHUB_RELEASE_TOKEN=synthetic'
            if path == gate.TARGET:
                return source
            self.fail('unexpected private read')
        with patch.object(gate, 'read_private', side_effect=read_ok), \
             patch.object(gate, 'git', side_effect=git_ok), \
             patch.object(gate, 'verified_checks', return_value=True):
            self.assertEqual(gate.offline_provision_certified_sql(sha, api), {'ok': True, 'changed': False})
        with patch.object(gate, 'git', side_effect=AssertionError('unexpected fetch')):
            self.assertEqual(gate.offline_provision_certified_sql('invalid', api)['reason'], 'invalid_sha')
        with patch.object(gate, 'read_private', side_effect=read_ok), \
             patch.object(gate, 'git', side_effect=git_ok), \
             patch.object(gate, 'verified_checks', return_value=False):
            self.assertEqual(gate.offline_provision_certified_sql(sha, api)['reason'], 'ci_not_certified')
        with patch.object(gate, 'read_private', side_effect=lambda path, limit=65536: b'wrong' if path == gate.TARGET else read_ok(path, limit)), \
             patch.object(gate, 'git', side_effect=git_ok), \
             patch.object(gate, 'verified_checks', return_value=True):
            self.assertEqual(gate.offline_provision_certified_sql(sha, api)['reason'], 'release_not_installed')

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

    def test_local_sql_mailbox_rejects_args_expiry_and_nonowner(self):
        payload = {'action': 'local_sql_reconcile', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z'}
        good = request(body=mod.PREFIX + json.dumps(payload))
        self.assertIsNotNone(mod.command(good, NOW))
        self.assertIsNone(mod.command(good, NOW + 601))
        for extra in ({'sql': 'SELECT 1'}, {'args': {}}, {'sha': 'a'*40}, {'mode': 'write'}):
            bad = dict(payload, **extra)
            self.assertIsNone(mod.command(request(body=mod.PREFIX + json.dumps(bad)), NOW))
        for invalid in (request(body=good['body'], author_association='MEMBER'),
                        request(body=good['body'], user={'login': 'intruder', 'type': 'User'}),
                        request(body=good['body'], updated_at='2026-11-20T15:59:01Z')):
            self.assertIsNone(mod.command(invalid, NOW))

    def test_local_sql_mailbox_requires_local_opt_in(self):
        payload = {'action': 'local_sql_reconcile', 'nonce': 'syntheticnonce1234', 'expires_at': '2026-11-20T16:10:00Z'}
        row = request(body=mod.PREFIX + json.dumps(payload))
        def api(token, method, path, body=None):
            self.assertEqual(method, 'GET')
            if '/comments?' in path: return [row]
            if path.endswith('/issues/22'): return {'title':'CV contingency — diagnostic mailbox','state':'open'}
            return {'private':True,'full_name':mod.REPO}
        with tempfile.TemporaryDirectory() as d, patch.object(mod,'local_sql_reconcile') as action:
            c = mod.Contingency(ENV, lambda: self.fail('health'), Path(d), api, lambda: NOW)
            self.assertEqual(c.poll(), 'idle')
            self.assertEqual(list(Path(d).glob('nonce-*')), [])
            action.assert_not_called()

    def test_local_sql_mailbox_single_use_and_safe_receipt(self):
        payload = {'action':'local_sql_reconcile','nonce':'syntheticnonce1234','expires_at':'2026-11-20T16:10:00Z'}
        row = request(body=mod.PREFIX + json.dumps(payload))
        posts = []
        def api(token, method, path, body=None):
            if method == 'POST':
                posts.append(body['body'])
                return {}
            if '/comments?' in path: return [row]
            if path.endswith('/issues/22'): return {'title':'CV contingency — diagnostic mailbox','state':'open'}
            return {'private':True,'full_name':mod.REPO}
        env = dict(ENV, CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED='1')
        safe = {'ok':True,'transport':'local_sql_readonly','postgrest_auth_certified':False,
                'checks': {'local_config': {'ok':True}}}
        with tempfile.TemporaryDirectory() as d, patch.object(mod,'local_sql_reconcile',return_value=safe) as action:
            c = mod.Contingency(env, lambda: self.fail('health'), Path(d), api, lambda: NOW)
            self.assertEqual(c.poll(), 'reported')
            self.assertEqual(c.poll(), 'cooldown')
            c2 = mod.Contingency(env, lambda: self.fail('health'), Path(d), api, lambda: NOW+61)
            self.assertEqual(c2.poll(), 'idle')
            action.assert_called_once_with()
            self.assertEqual(len(posts), 1)
            receipt = json.loads(posts[0].split(' ',1)[1])
            self.assertEqual(receipt['result'], safe)
            self.assertEqual(receipt['action'], 'local_sql_reconcile')
            self.assertEqual(receipt['request_id'], row['id'])

    def test_private_sql_reader_rejects_permissions_and_symlinks(self):
        with tempfile.TemporaryDirectory() as d:
            target = Path(d) / 'artifact'
            target.write_bytes(b'fixture')
            target.chmod(0o600)
            self.assertEqual(mod.private_bytes(target, 262144), b'fixture')
            target.chmod(0o644)
            with self.assertRaises(ValueError):
                mod.private_bytes(target, 262144)
            target.chmod(0o600)
            link = Path(d) / 'link'
            link.symlink_to(target)
            with self.assertRaises(OSError):
                mod.private_bytes(link, 262144)

    def sql_fixture_fstat(self, fd):
        import os
        import stat
        # The CI runner is not root; simulate ownership only for directory checks.
        st = self._real_sql_fstat(fd)
        if stat.S_ISDIR(st.st_mode):
            return Mock(st_uid=0, st_mode=st.st_mode)
        return st

    def test_sql_installer_rejects_concurrent_lock(self):
        import fcntl
        import os
        with tempfile.TemporaryDirectory() as d:
            target = Path(d) / 'cv-local-sql-certified.py'
            guard = os.open(d, os.O_RDONLY | os.O_DIRECTORY)
            self._real_sql_fstat = os.fstat
            try:
                fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with patch.object(mod, 'LOCAL_SQL_BRIDGE_PATH', target), patch.object(mod.os, 'geteuid', return_value=0), patch.object(mod.Path, 'lstat', return_value=Mock(st_mode=0o40700, st_uid=0)), patch.object(mod.os, 'fstat', side_effect=self.sql_fixture_fstat), patch.object(mod, 'private_bytes') as read:
                    self.assertEqual(mod.provision_local_sql_artifact(), {'ok': False, 'reason': 'installer_busy'})
                    read.assert_not_called()
                self.assertFalse(target.exists())
            finally:
                os.close(guard)

    def test_sql_provision_rolls_back_only_its_own_publication(self):
        self._real_sql_fstat = mod.os.fstat
        import hashlib
        raw = b'certified fixture'
        pin = hashlib.sha1(b'blob ' + str(len(raw)).encode() + bytes([0]) + raw).hexdigest()
        for replace in (False, True):
            with self.subTest(replace=replace), tempfile.TemporaryDirectory() as d:
                target = Path(d) / 'cv-local-sql-certified.py'
                source = target.with_name('cv-ops-bridge.py.prev')
                source.write_bytes(raw)
                def read(p, n):
                    if p == target and p.exists():
                        if replace:
                            p.unlink()
                            p.write_bytes(b'concurrent replacement')
                        raise ValueError('post-publication failure')
                    return p.read_bytes()
                with patch.object(mod, 'LOCAL_SQL_BRIDGE_PATH', target), patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', pin), patch.object(mod.os, 'geteuid', return_value=0), patch.object(mod, 'private_bytes', side_effect=read), patch.object(mod.os, 'fstat', side_effect=self.sql_fixture_fstat), patch.object(mod.Path, 'lstat', return_value=Mock(st_mode=0o40700, st_uid=0)):
                    receipt = mod.provision_local_sql_artifact()
                    self.assertFalse(receipt['ok'])
                    if replace:
                        self.assertEqual(receipt['reason'], 'rollback_conflict')
                if replace:
                    self.assertEqual(target.read_bytes(), b'concurrent replacement')
                else:
                    self.assertFalse(target.exists())

    def test_sql_cleanup_failure_rolls_back_published_inode(self):
        self._real_sql_fstat = mod.os.fstat
        import hashlib
        raw = b'certified fixture'
        pin = hashlib.sha1(b'blob ' + str(len(raw)).encode() + bytes([0]) + raw).hexdigest()
        with tempfile.TemporaryDirectory() as d:
            target = Path(d) / 'cv-local-sql-certified.py'
            target.with_name('cv-ops-bridge.py.prev').write_bytes(raw)
            real_unlink = mod.os.unlink
            fault = [True]
            def fail_first_temp_cleanup(path, *args, **kwargs):
                if str(path).split('/')[-1].startswith('.cv-sql-') and fault[0]:
                    fault[0] = False
                    raise OSError('synthetic cleanup failure')
                return real_unlink(path, *args, **kwargs)
            with patch.object(mod, 'LOCAL_SQL_BRIDGE_PATH', target), \
                 patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', pin), \
                 patch.object(mod.os, 'geteuid', return_value=0), \
                 patch.object(mod, 'private_bytes', side_effect=lambda p, n: p.read_bytes()), \
                 patch.object(mod.os, 'fstat', side_effect=self.sql_fixture_fstat), patch.object(mod.Path, 'lstat', return_value=Mock(st_mode=0o40700, st_uid=0)), \
                 patch.object(mod.os, 'unlink', side_effect=fail_first_temp_cleanup):
                self.assertFalse(mod.provision_local_sql_artifact()['ok'])
            self.assertFalse(target.exists())
            self.assertEqual(list(Path(d).glob('.cv-sql-*')), [])

    def test_offline_sql_provision_is_idempotent_and_never_overwrites(self):
        self._real_sql_fstat = mod.os.fstat
        import hashlib
        raw = b'certified fixture'
        pin = hashlib.sha1(b'blob ' + str(len(raw)).encode() + bytes([0]) + raw).hexdigest()
        with tempfile.TemporaryDirectory() as d:
            target = Path(d) / 'cv-local-sql-certified.py'
            source = target.with_name('cv-ops-bridge.py.prev')
            source.write_bytes(raw)
            source.chmod(0o600)
            with patch.object(mod, 'LOCAL_SQL_BRIDGE_PATH', target), \
                 patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', pin), \
                 patch.object(mod.os, 'geteuid', return_value=0), \
                 patch.object(mod, 'private_bytes', side_effect=lambda p, n: p.read_bytes()), \
                 patch.object(mod.os, 'fstat', side_effect=self.sql_fixture_fstat), patch.object(mod.Path, 'lstat', return_value=Mock(st_mode=0o40700, st_uid=0)):
                self.assertEqual(mod.provision_local_sql_artifact(), {'ok': True, 'changed': True})
                self.assertEqual(target.read_bytes(), raw)
                self.assertEqual(target.stat().st_mode & 0o777, 0o600)
                self.assertEqual(mod.provision_local_sql_artifact(), {'ok': True, 'changed': False})
                target.write_bytes(b'conflict')
                self.assertFalse(mod.provision_local_sql_artifact()['ok'])
                self.assertEqual(target.read_bytes(), b'conflict')
                target.unlink()
                source.write_bytes(b'tampered')
                self.assertFalse(mod.provision_local_sql_artifact()['ok'])
                self.assertFalse(target.exists())

    def test_local_sql_receipt_masks_worker_output(self):
        import hashlib
        from unittest.mock import mock_open
        import builtins
        # The production pin stays exact, and the worker receives no arguments.
        self.assertEqual(mod.LOCAL_SQL_BRIDGE_BLOB, '84bc0eb4a57a3dc5c4f1ea5377c50eb952446a3a')
        payload = {
            'transport': 'local_sql_readonly',
            'postgrest_auth_certified': False,
            'result': {'ok': True, 'checks': {
                name: {'ok': True, **{key: 0 for key in mod.PREFLIGHT_COUNTS.get(name, set())}}
                for name in mod.PREFLIGHT_CHECKS}},
        }
        # Synthetic executable source: mocked digest identifies exactly these bytes.
        source = ("def action_local_sql_reconcile(args):\n"
                  "    assert args == {}\n"
                  "    return {'returncode': 0, 'output': " + repr(json.dumps(payload)) + "}\n").encode()
        blob = hashlib.sha1(b'blob ' + str(len(source)).encode() + bytes([0]) + source).hexdigest()
        calls = []
        def private_once(path, limit):
            calls.append((str(path), limit))
            if len(calls) > 1:
                raise AssertionError('unexpected second source read')
            return source
        with patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', blob), \
             patch.object(mod, 'private_bytes', side_effect=private_once), \
             patch('importlib.util.spec_from_file_location', side_effect=AssertionError('unsafe path re-import')):
            safe = mod.local_sql_reconcile()
        self.assertTrue(safe['ok'])
        self.assertEqual(safe['transport'], 'local_sql_readonly')
        self.assertFalse(safe['postgrest_auth_certified'])
        self.assertNotIn('output', safe)
        self.assertEqual(calls, [('/root/supervisor/cv-local-sql-certified.py', 262144)])

        # A modified blob must fail closed and never execute injected code.
        tampered = source + b"\nraise RuntimeError('worker executed')\n"
        with patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', blob), \
             patch.object(mod, 'private_bytes', return_value=tampered), \
             patch.object(builtins, 'exec', side_effect=AssertionError('must not execute')) as executor:
            self.assertEqual(mod.local_sql_reconcile(), {'ok': False, 'reason': 'bridge_integrity_mismatch'})
            executor.assert_not_called()

        # Missing or insecure dedicated artifact must never fall back to the worker or backup.
        for error in (FileNotFoundError(), PermissionError(), ValueError('insecure file')):
            with patch.object(mod, 'private_bytes', side_effect=error) as reader, \
                 patch.object(builtins, 'exec') as executor:
                self.assertFalse(mod.local_sql_reconcile()['ok'])
                reader.assert_called_once_with(mod.LOCAL_SQL_BRIDGE_PATH, 262144)
                executor.assert_not_called()

        # Worker failure output must not leak credentials or data.
        failure_source = ("def action_local_sql_reconcile(args):\n"
                          "    assert args == {}\n"
                          "    return {'returncode': 1, 'output': "
                          + repr(json.dumps({'reason': 'private-credential-do-not-leak'})) + "}\n").encode()
        failure_blob = hashlib.sha1(b'blob ' + str(len(failure_source)).encode() + bytes([0]) + failure_source).hexdigest()
        with patch.object(mod, 'LOCAL_SQL_BRIDGE_BLOB', failure_blob), \
             patch.object(mod, 'private_bytes', return_value=failure_source):
            safe = mod.local_sql_reconcile()
        self.assertEqual(safe, {'ok': False, 'reason': 'local_sql_reconcile_unavailable'})
        self.assertNotIn('private-credential', json.dumps(safe))

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

    def test_local_sql_adapter_executes_pinned_preflight_fixtures(self):
        import subprocess
        import shutil
        node=shutil.which('node')
        if not node:
            self.skipTest('Node unavailable')
        core_fixture = """
globalThis.fetch=async(raw,options)=>{
 const u=new URL(raw);
 if(u.origin!=='https://casaviva.company'||options.headers['X-VivaBot-Key']!=='fixture-key') throw Error('auth');
 const data=u.pathname.endsWith('/direct')?{direct:[]}:u.pathname.endsWith('/palla')?{palla:[]}:{offers:[{id:1,offeredAt:'2026-11-20T10:00:00',announced:false}]};
 return {ok:true,json:async()=>data};
};
"""
        config={'WOO_URL':'https://casaviva.company','CURRU_KEY':'fixture-key','BUSINESS_SLUG':'casa-viva','DISPATCH_GROUP':'Dispatch'}
        snapshot={'businesses':['tenant'],'groups':[{'jid':'fixture@g.us','name':'Dispatch'}],'dispatch_schema':True,'outbox_schema':True,'dispatch':[]}
        row={'order_id':1,'stage':'announced','messenger_phone':None,'announce_msg_id':'present','announced_at':'2026-11-20T11:00:00Z'}
        for rows,expected in [([],True),([row],True),([dict(row,messenger_phone='present')],False),([row,row],False)]:
            payload=json.dumps({'snapshot':dict(snapshot,dispatch=rows),'config':config})
            p=subprocess.run([node,'--input-type=module','-e',bridge.BUNDLED_RECOVERY_PREFLIGHT+core_fixture+bridge.LOCAL_SQL_PREFLIGHT_WRAPPER],input=payload,text=True,capture_output=True,timeout=10)
            self.assertEqual(p.returncode,0,p.stderr)
            result=json.loads(p.stdout)
            self.assertEqual(result['ok'],expected, msg=json.dumps(result,sort_keys=True))
            self.assertTrue(result['checks']['core_offers']['ok'])

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

    def test_guardian_runtime_evidence_requires_empty_args(self):
        self.assertEqual(bridge.action_guardian_runtime_evidence({'path': '/etc/shadow'})['returncode'], 2)

    def test_guardian_runtime_evidence_reports_only_safe_fixed_metadata(self):
        import hashlib
        raw = b'certified-worker-fixture'
        prior = b'previous-known-good-fixture'
        root = Path('/root/supervisor')
        class Stat:
            st_mode = 0o100700
        def private_mock(path, limit):
            self.assertEqual(limit, 262144)
            if path == root / 'cv-local-sql-certified.py':
                raise FileNotFoundError()
            self.assertIn(path, (root / 'cv-ops-bridge.py', root / 'cv-ops-bridge.py.prev'))
            return prior if path.name.endswith('.prev') else raw
        def run_mock(cmd, **kwargs):
            self.assertEqual(cmd[:2], ['/usr/bin/systemctl', 'is-active'])
            self.assertIn(cmd[2], ('cv-ops-bridge.service', 'cv-dev-worker.service',
                                    'cv-control-sync.service', 'cv-receiver-isolation-check.service'))
            self.assertNotIn('shell', kwargs)
            return Mock(stdout=b'active\n')
        with patch.dict(sys.modules, {'github_contingency':Mock(private_bytes=private_mock)}), \
             patch.object(bridge, 'load_env_files', return_value={
                 'CV_GITHUB_CONTINGENCY_ENABLED': '1',
                 'CV_GITHUB_RELEASE_ENABLED': '0',
                 'CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED': '0',
                 'SECRET_DO_NOT_EXPORT': 'private-value'}), \
             patch.object(bridge.Path, 'stat', return_value=Stat()), \
             patch.object(bridge.subprocess, 'run', side_effect=run_mock):
            receipt = bridge.action_guardian_runtime_evidence({})
        self.assertEqual(receipt['returncode'], 0)
        result = json.loads(receipt['output'])
        def blob(b):
            return hashlib.sha1(b'blob ' + str(len(b)).encode() + bytes([0]) + b).hexdigest()
        self.assertEqual(result['worker_git_blob'], blob(raw))
        self.assertFalse(result['startup_matches_installed'])
        self.assertFalse(result['runtime_artifact_certified'])
        self.assertFalse(result['guardian_release_approval_verified'])
        self.assertFalse(result['sql_pin_matches_installed'])
        self.assertFalse(result['sql_pin_matches_backup'])
        self.assertFalse(result['sql_artifact_matches_pin'])
        self.assertIsNone(result['sql_artifact_blob'])
        self.assertFalse(result['isolation']['functional_isolation_certified'])
        self.assertEqual(result['backup_git_blob'], blob(prior))
        self.assertTrue(result['worker_owner_only'])
        self.assertTrue(result['backup_present'])
        self.assertFalse(result['local_flags']['CV_GITHUB_RELEASE_ENABLED'])
        self.assertFalse(result['local_flags']['CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED'])
        self.assertNotIn('private-value', receipt['output'])
        self.assertNotIn('SECRET_DO_NOT_EXPORT', receipt['output'])
        self.assertEqual(set(result['services']), {
            'cv-ops-bridge.service', 'cv-dev-worker.service',
            'cv-control-sync.service', 'cv-receiver-isolation-check.service'})

    def test_guardian_runtime_evidence_fails_closed_no_worker_source_exposure(self):
        with patch.dict(sys.modules, {'github_contingency':Mock(private_bytes=Mock(side_effect=ValueError('sensitive-worker-content')))}):
            receipt = bridge.action_guardian_runtime_evidence({})
        self.assertEqual(receipt['returncode'], 1)
        self.assertEqual(json.loads(receipt['output']), {'reason': 'guardian_evidence_unavailable'})
        self.assertNotIn('sensitive-worker-content', receipt['output'])

    def test_guardian_isolation_metadata_never_certifies_functional_isolation(self):
        good = b'NoNewPrivileges=yes\nAppArmorProfile=cv-receiver\nResult=success\nExecMainStatus=0\n'
        for raw, rc, available in ((good, 0, True), (b'', 0, False), (good, 1, False)):
            with self.subTest(raw=raw, rc=rc), patch.object(
                    bridge.subprocess, 'run', return_value=Mock(stdout=raw, returncode=rc)):
                result = bridge.guardian_isolation_metadata()
                self.assertEqual(result['metadata_available'], available)
                self.assertFalse(result['functional_isolation_certified'])

    def test_guardian_sql_backup_match_does_not_approve_running_worker(self):
        import hashlib
        raw, prior = b'new-guardian', b'pinned-sql'
        pin = hashlib.sha1(b'blob ' + str(len(prior)).encode() + bytes([0]) + prior).hexdigest()
        contract = Mock(LOCAL_SQL_BRIDGE_BLOB=pin,
                        private_bytes=lambda path, limit: prior if path.name.endswith('.prev') else raw)
        flags = dict.fromkeys(('CV_GITHUB_CONTINGENCY_ENABLED', 'CV_GITHUB_RELEASE_ENABLED',
                               'CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED'), '0')
        with patch.dict(sys.modules, {'github_contingency': contract}), \
             patch.object(bridge, 'load_env_files', return_value=flags), \
             patch.object(bridge.Path, 'stat', return_value=Mock(st_mode=0o100700)), \
             patch.object(bridge.subprocess, 'run', return_value=Mock(stdout=b'active', returncode=0)), \
             patch.object(bridge, '_GUARDIAN_STARTUP_BLOB', bridge.guardian_blob(raw)):
            receipt = bridge.action_guardian_runtime_evidence({})
        self.assertEqual(receipt['returncode'], 0)
        result = json.loads(receipt['output'])
        self.assertTrue(result['startup_matches_installed'])
        self.assertTrue(result['sql_pin_matches_backup'])
        self.assertFalse(result['sql_pin_matches_installed'])
        self.assertFalse(result['guardian_release_approval_verified'])
        self.assertFalse(result['runtime_artifact_certified'])

    def test_guardian_artifact_binding_requires_three_exact_valid_hashes(self):
        approved = 'a' * 40
        other = 'b' * 40
        self.assertTrue(bridge.guardian_artifact_binding(approved, approved, approved))
        for values in ((other, approved, approved), (approved, other, approved),
                       (approved, approved, other), (None, approved, approved),
                       (approved, None, approved), (approved, approved, None),
                       ('bad', 'bad', 'bad')):
            with self.subTest(values=values):
                self.assertFalse(bridge.guardian_artifact_binding(*values))

    def test_guardian_startup_identity_rejects_noninstalled_path(self):
        with patch.object(bridge, '__file__', '/tmp/untrusted-worker.py'):
            self.assertIsNone(bridge.guardian_startup_blob())

    def test_guardian_runtime_evidence_rejects_unknown_flags(self):
        valid = {
            'CV_GITHUB_CONTINGENCY_ENABLED': '1',
            'CV_GITHUB_RELEASE_ENABLED': '0',
            'CV_GITHUB_LOCAL_SQL_RECONCILE_ENABLED': '0',
        }
        cases = [({}, None), ({**valid, 'CV_GITHUB_RELEASE_ENABLED': 'false'}, None),
                 (valid, OSError('private-config-error'))]
        for name in valid:
            cases.append(({k: v for k, v in valid.items() if k != name}, None))
        for config, error in cases:
            with self.subTest(config=config, error=type(error).__name__), \
                 patch.dict(sys.modules, {'github_contingency': Mock(private_bytes=Mock(return_value=b'fixture'))}), \
                 patch.object(bridge, 'load_env_files', return_value=config, side_effect=error), \
                 patch.object(bridge.subprocess, 'run') as run:
                receipt = bridge.action_guardian_runtime_evidence({})
                self.assertEqual(receipt['returncode'], 1)
                self.assertEqual(json.loads(receipt['output']), {'reason': 'guardian_flags_unknown'})
                run.assert_not_called()

    def test_guardian_only_metadata_queue_migration(self):
        sql_root = PATH.parent / 'sql'
        allow = (sql_root / 'allow-guardian-runtime-evidence.sql').read_text()
        rollback = (sql_root / 'rollback-guardian-runtime-evidence.sql').read_text()
        self.assertIn("action = ''guardian_runtime_evidence'' AND args = ''{}''::jsonb", allow)
        self.assertIn("position('local_sql_reconcile' in prior)>0", allow)
        self.assertIn("substring(prior FROM 8 FOR char_length(prior)-8)", allow)
        self.assertIn("IF EXISTS (SELECT 1 FROM public.crm_ops_bridge", rollback)
        self.assertIn("status IN ('queued', 'running')", rollback)
        self.assertIn("unexpected_guardian_constraint_shape", rollback)
        self.assertIn("original_allowlist_missing", rollback)
        self.assertIn("md5(original_def)", rollback)
        self.assertIn("de228436c4c60d8820858cf4dba191dc", allow)
        self.assertIn("de228436c4c60d8820858cf4dba191dc", rollback)
        self.assertNotIn("action = ''local_sql_reconcile''", allow)
        self.assertNotIn("DROP TABLE", allow.upper())
        self.assertNotIn("TRUNCATE", allow.upper())

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
