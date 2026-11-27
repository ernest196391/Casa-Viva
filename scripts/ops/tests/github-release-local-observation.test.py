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
    def test_diagnostic_categories_are_redacted(self):
        diagnostics=[]
        run=Mock(return_value=Mock(returncode=0,stdout=b'active\n'))
        o.collect_summary(run=run,present=lambda:False,locate=lambda *a,**k:'/usr/bin/pm2',
                          diagnostics=diagnostics)
        self.assertEqual(diagnostics,['existing_daemon_not_verified'])
        diagnostics=[]
        o.collect_summary(run=run,present=lambda:True,locate=lambda *a,**k:None,
                          diagnostics=diagnostics)
        self.assertEqual(diagnostics,['pm2_or_node_not_in_fixed_path'])
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            (root/'bin').mkdir()
            (root/'bin'/'pm2').touch()
            (root/'package.json').write_text('{"name":"pm2"}')
            for rc,expected in ((21,'rpc_socket_error'),(22,'rpc_timeout'),(23,'rpc_method_error'),(24,'rpc_unexpected_shape'),(25,'rpc_transport_unavailable'),(1,'rpc_runtime_error')):
                diagnostics=[]
                run=Mock(side_effect=[Mock(returncode=rc,stdout=b'private data'),Mock(returncode=0,stdout=b'active\n')])
                o.collect_summary(run=run,present=lambda:True,locate=lambda n,**k:str(root/'bin'/'pm2') if n=='pm2' else '/usr/bin/node',diagnostics=diagnostics)
                self.assertEqual(diagnostics,[expected])

    def test_daemon_identity_without_pid_file_and_rejects_changes(self):
        import stat
        from unittest.mock import patch
        home=Path('/root/.pm2')
        proc=Path('/proc')
        pid=2429462
        directory=Mock(st_mode=stat.S_IFDIR|0o700,st_uid=0)
        endpoint=Mock(st_mode=stat.S_IFSOCK|0o775,st_uid=0,st_dev=1,st_ino=22)
        replaced=Mock(st_mode=stat.S_IFSOCK|0o775,st_uid=0,st_dev=1,st_ino=23)
        def stat_line(ticks):
            return '2429462 (PM2 daemon) '+' '.join(['S']+['0']*18+[str(ticks)])
        def probe(*, peer=(pid,0,0), changed=False, reused=False, title=None, absent=False):
            calls=[]
            def lstat(p):
                if p==home/'rpc.sock':
                    if absent:
                        raise FileNotFoundError()
                    calls.append(p)
                    return replaced if changed and len(calls)>1 else endpoint
                self.assertIn(p,(home,*home.parents))
                return directory
            with patch.object(Path,'lstat',lstat), \
                 patch.object(Path,'read_text',side_effect=[stat_line(123),stat_line(124 if reused else 123)]), \
                 patch.object(Path,'read_bytes',return_value=title or b'PM2 v7.0.4: God Daemon (/root/.pm2)\0'):
                return o.daemon_identity(home=home,proc=proc,connect=lambda _:peer)
        self.assertEqual(probe(),(pid,123,1,22))
        for kwargs in (dict(peer=(pid,1000,1000)),dict(peer=(1,0,0)),dict(changed=True),
                       dict(reused=True),dict(title=b'not pm2\0'),dict(absent=True)):
            with self.subTest(kwargs=kwargs):
                self.assertIsNone(probe(**kwargs))

    def test_rpc_rejects_changed_daemon_identity(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            (root/'bin').mkdir()
            (root/'bin'/'pm2').touch()
            (root/'package.json').write_text('{"name":"pm2"}')
            for after in ((42,124,1,22),(42,123,1,23),None):
                with self.subTest(after=after):
                    present=Mock(side_effect=[(42,123,1,22),after])
                    run=Mock(side_effect=[Mock(returncode=0,stdout=b'[{"name":"vivabot","status":"online"}]'),
                                          Mock(returncode=0,stdout=b'active\n')])
                    s=o.collect_summary(run=run,present=present,
                        locate=lambda n,**k:str(root/'bin'/'pm2') if n=='pm2' else '/usr/bin/node')
                    self.assertTrue(all(r['status']=='unknown' for r in s['pm2'].values()))

    def test_node_transport_resolution(self):
        import shutil
        import subprocess
        node=shutil.which('node')
        if not node:
            self.skipTest('Node unavailable')
        # Execute only the transport loader, not the socket connection.
        code=o.RPC.split('const socket =')[0]
        for layout in ('modules','node_modules','missing'):
            with self.subTest(layout=layout), tempfile.TemporaryDirectory() as d:
                root=Path(d)
                if layout!='missing':
                    for name in ('pm2-axon','pm2-axon-rpc'):
                        folder=root/layout/name
                        folder.mkdir(parents=True)
                        (folder/'index.js').write_text('module.exports = {};')
                r=subprocess.run([node,'-e',code,str(root)],capture_output=True,timeout=5)
                self.assertEqual(r.returncode,25 if layout=='missing' else 0)

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
