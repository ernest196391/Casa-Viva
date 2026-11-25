import datetime as dt
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('contingency',Path(__file__).resolve().parents[1]/'github_contingency.py')
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
NOW=1795626000
def stamp(t):
    return dt.datetime.fromtimestamp(t,dt.timezone.utc).isoformat().replace('+00:00','Z')
def data(action='hermes_dialog_tests',**kw):
    return dict(action=action,nonce='cv-catalog-test-1234',expires_at=stamp(NOW+300),**kw)
def comment(d):
    import json
    return dict(id=123,user=dict(login=m.OWNER,type='User'),author_association='OWNER',created_at=stamp(NOW),updated_at=stamp(NOW),body=m.PREFIX+json.dumps(d))
class CatalogTests(unittest.TestCase):
    def test_allowlisted_actions(self):
        for action in ('hermes_dialog_tests','hermes_fin_contract'):
            self.assertEqual(m.command(comment(data(action)),NOW),data(action))
    def test_no_arbitrary_parameters(self):
        for action in ('hermes_dialog_tests','hermes_fin_contract'):
            for k in ('cmd','sha','path','query'):
                self.assertIsNone(m.command(comment(data(action,**{k:'untrusted'})),NOW))
    def test_ttl(self):
        for action in ('hermes_dialog_tests','hermes_fin_contract'):
            d=data(action);d['expires_at']=stamp(NOW+601)
            self.assertIsNone(m.command(comment(d),NOW))
    def test_separate_opt_in(self):
        c=m.Contingency({'CV_GITHUB_HERMES_OFFLINE_ENABLED':'1'},lambda:None)
        self.assertTrue(c.hermes_offline_enabled)
        self.assertFalse(c.hermes_dialog_enabled)
        self.assertFalse(c.hermes_fin_contract_enabled)
    @patch.object(m.subprocess,'run')
    def test_dialog_fixed_suite(self,run):
        run.side_effect=[subprocess.CompletedProcess([],0,b'fixture',b'')]*2+[subprocess.CompletedProcess([],0,b'# tests 14\n# pass 14\n# fail 0\n# skipped 0\n# cancelled 0\n# todo 0\n',b'')]
        self.assertEqual(m.hermes_offline_tests(data(),NOW),dict(ok=True,commit=m.DIALOG_COMMIT,tests=14,passed=14,failed=0))
        self.assertIn(m.DIALOG_COMMIT+':'+m.DIALOG_FILES[0],run.call_args_list[0].args[0])
        argv=run.call_args.args[0]
        self.assertIn('/opt/cv-offline-tests/test/cv-bot-dialog.test.mjs',argv)
        self.assertIn('SystemCallFilter=~connect',argv)
        self.assertIn('SystemCallErrorNumber=EPERM',argv)
    @patch.object(m.subprocess,'run')
    def test_wrong_suite_count_rejected(self,run):
        run.side_effect=[subprocess.CompletedProcess([],0,b'fixture',b'')]*2+[subprocess.CompletedProcess([],0,b'# tests 17\n# pass 17\n# fail 0\n# skipped 0\n# cancelled 0\n# todo 0\n',b'')]
        self.assertFalse(m.hermes_offline_tests(data(),NOW)['ok'])
    @patch.object(m.subprocess,'run')
    def test_invalid_action_never_executes(self,run):
        self.assertFalse(m.hermes_offline_tests(data('hermes_fin_contract'),NOW)['ok'])
        run.assert_not_called()
    def test_finance_missing_source(self):
        with tempfile.TemporaryDirectory() as d,patch.object(m,'FIN_SOURCE',Path(d)/'missing'):
            self.assertEqual(m.hermes_fin_contract(),dict(ok=False,reason='finance_source_unavailable'))
    def test_finance_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'source';p.write_text('fixture')
            q=Path(d)/'link';q.symlink_to(p)
            with patch.object(m,'FIN_SOURCE',q):
                self.assertFalse(m.hermes_fin_contract()['ok'])
    @unittest.skipUnless(__import__('os').geteuid()==0,'requires root-owned fixtures')
    def test_finance_only_markers(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'ops';p.write_text('/partners financeAnswer PRIVATE_FIXTURE')
            q=Path(d)/'renderer';q.write_text('commissionSummaryText USD Number(')
            with patch.object(m,'FIN_SOURCE',p),patch.object(m,'FIN_RENDERER',q):
                r=m.hermes_fin_contract()
                self.assertTrue(r['ok'])
                self.assertEqual(r['scope'],'static_only')
                self.assertTrue(r['operations']['markers']['partners_route'])
                self.assertFalse(r['operations']['markers']['orders_route'])
                self.assertNotIn('PRIVATE_FIXTURE',str(r))
if __name__=='__main__':
    unittest.main()
