import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_local_observation as o

class Tests(unittest.TestCase):
    def test_fixed_rpc_and_redaction(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            (root/'bin').mkdir()
            (root/'bin'/'pm2').touch()
            (root/'package.json').write_text('{"name":"pm2"}')
            def locate(name,**kw):
                return str(root/'bin'/'pm2') if name=='pm2' else '/usr/bin/node'
            def run(args,**kw):
                self.assertNotIn('NODE_OPTIONS',kw['env'])
                self.assertNotIn('shell',kw)
                if args[0]=='/usr/bin/node':
                    self.assertEqual(args[1:3],['-e',o.RPC])
                    self.assertIn("client.call('getMonitorData'",args[2])
                    return Mock(returncode=0,stdout=b'[{"name":"vivabot","status":"online"}]')
                self.assertEqual(args, ['/usr/bin/systemctl','show','--no-pager','--property=ActiveState','--value','cv-ops-bridge.service'])
                return Mock(returncode=0,stdout=b'active\n')
            s=o.collect_summary(run=run,present=lambda:True,locate=locate)
            self.assertEqual(s['pm2']['vivabot']['status'],'online')
            self.assertEqual(s['pm2']['nexofirma']['status'],'unknown')
            self.assertEqual(s['bridge'],'active')
    def test_missing_daemon_never_calls_pm2_or_node(self):
        run=Mock(return_value=Mock(returncode=0,stdout=b'active\n'))
        s=o.collect_summary(run=run,present=lambda:False,locate=lambda *a,**k:'/usr/bin/pm2')
        self.assertEqual(run.call_count,1)
        self.assertEqual(run.call_args.args[0][0],'/usr/bin/systemctl')
        self.assertTrue(all(r['status']=='unknown' for r in s['pm2'].values()))
    def test_rpc_malformed_or_sensitive_fields_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            (root/'bin').mkdir()
            (root/'bin'/'pm2').touch()
            (root/'package.json').write_text('{"name":"pm2"}')
            for raw in (b'not json',b'[{"name":"vivabot","status":"online","secret":"x"}]',b'[{"name":"private","status":"online"}]'):
                run=Mock(side_effect=[Mock(returncode=0,stdout=raw),Mock(returncode=1,stdout=b'')])
                s=o.collect_summary(run=run,present=lambda:True,locate=lambda n,**k:str(root/'bin'/'pm2') if n=='pm2' else '/usr/bin/node')
                self.assertTrue(all(r['status']=='unknown' for r in s['pm2'].values()))
                self.assertEqual(s['bridge'],'unknown')

if __name__=='__main__':
    unittest.main()
