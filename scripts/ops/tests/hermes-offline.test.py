import datetime as dt
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('contingency', Path(__file__).resolve().parents[1] / 'github_contingency.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
NOW = 1795626000
def stamp(t):
    return dt.datetime.fromtimestamp(t, dt.timezone.utc).isoformat().replace('+00:00','Z')
def data(**kw):
    return dict(action='hermes_offline_tests', nonce='cv-offline-test-1234', expires_at=stamp(NOW+300), **kw)
def comment(d):
    import json
    return dict(id=123,user=dict(login=m.OWNER,type='User'),author_association='OWNER',created_at=stamp(NOW),updated_at=stamp(NOW),body=m.PREFIX+json.dumps(d))
class OfflineTests(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(m.command(comment(data()),NOW),data())
    def test_extra_command_rejected(self):
        self.assertIsNone(m.command(comment(data(cmd='id')),NOW))
    def test_revision_override_rejected(self):
        self.assertIsNone(m.command(comment(data(sha='a'*40)),NOW))
    def test_expired(self):
        self.assertIsNone(m.command(comment(data()),NOW+301))
    def test_long_window(self):
        d=data();d['expires_at']=stamp(NOW+601)
        self.assertIsNone(m.command(comment(d),NOW))
    def test_non_owner(self):
        c=comment(data());c['author_association']='MEMBER'
        self.assertIsNone(m.command(c,NOW))
    def test_default_disabled(self):
        self.assertFalse(m.Contingency({},lambda:None).hermes_offline_enabled)
    def test_explicit_opt_in(self):
        self.assertTrue(m.Contingency({'CV_GITHUB_HERMES_OFFLINE_ENABLED':'1'},lambda:None).hermes_offline_enabled)
    @patch.object(m.subprocess,'run')
    def test_invalid_never_executes(self,run):
        self.assertFalse(m.hermes_offline_tests(data(cmd='id'),NOW)['ok'])
        run.assert_not_called()
    @patch.object(m.subprocess,'run')
    def test_missing_local_revision(self,run):
        run.return_value=subprocess.CompletedProcess([],1,b'',b'private')
        self.assertEqual(m.hermes_offline_tests(data(),NOW),{'ok':False,'reason':'offline_tests_failed'})
        self.assertEqual(run.call_count,1)
    @patch.object(m.subprocess,'run')
    def test_fixed_execution_and_redacted_result(self,run):
        run.side_effect=[subprocess.CompletedProcess([],0,b'fixture',b'')]*4+[subprocess.CompletedProcess([],0,b'# tests 17\n# pass 17\n# fail 0\n# skipped 0\n# cancelled 0\n# todo 0\nprivate\n',b'private')]
        # Keep temporary data local; no actual Git or systemd invocation.
        result=m.hermes_offline_tests(data(),NOW)
        self.assertEqual(result,dict(ok=True,commit=m.OFFLINE_COMMIT,tests=17,passed=17,failed=0))
        argv=run.call_args.args[0]
        self.assertIn('PrivateNetwork=yes',argv)
        self.assertIn('DynamicUser=yes',argv)
        self.assertIn('ProtectHome=yes',argv)
        self.assertIn('SystemCallFilter=~connect',argv)
        masks=next(x for x in argv if x.startswith('InaccessiblePaths='))
        self.assertNotIn('/run',masks.split('=',1)[1].split())
        self.assertIn('-/run/systemd/private',masks)
        self.assertIn('-/run/dbus',masks)
        self.assertIn('/usr/bin/node',argv)
        self.assertNotIn('private',str(result))
    @patch.object(m.subprocess,'run')
    def test_missing_summary_fails_closed(self,run):
        run.side_effect=[subprocess.CompletedProcess([],0,b'fixture',b'')]*4+[subprocess.CompletedProcess([],0,b'# pass 17\n',b'')]
        self.assertFalse(m.hermes_offline_tests(data(),NOW)['ok'])
if __name__=='__main__':
    unittest.main()
