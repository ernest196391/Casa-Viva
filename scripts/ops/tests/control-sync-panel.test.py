"""Snapshot tests without credentials, network, systemd, PM2 or SSH."""
import ast
import datetime
import json
from pathlib import Path
import re
import types
import unittest
from unittest.mock import Mock

SOURCE = Path(__file__).resolve().parents[1] / 'control-os-sync.py'


class CleanPanelTests(unittest.TestCase):
    def snapshot(self, tasks, approvals=None, bridge=None):
        def query(path):
            self.paths.append(path)
            for prefix, rows in [('ops_tasks?', tasks), ('ops_threads?', []),
                                 ('ops_approvals?', approvals or []),
                                 ('ops_events?', []), ('crm_ops_bridge?', bridge or [])]:
                if path.startswith(prefix):
                    return rows
            raise AssertionError(path)
        self.paths = []
        tree = ast.parse(SOURCE.read_text(encoding='utf-8'))
        fn = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'snapshot')
        process = Mock()
        process.run.return_value.stdout = 'active'
        process.check_output.side_effect = lambda cmd, **kw: (
            '[{"name":"vivabot","pm2_env":{"status":"online"}}]' if cmd[0] == 'pm2' else 'abc123'
        )
        fake_path = Mock()
        fake_path.return_value.read_text.return_value = 'BRIDGE_VERSION = "test"'
        env = dict(datetime=datetime,json=json,re=re,subprocess=process,Path=fake_path,query=query)
        exec(compile(ast.Module(body=[fn],type_ignores=[]),str(SOURCE),'exec'),env)
        return env['snapshot']()

    def task(self, n, **extra):
        return dict(id=str(n),public_id=n,title='Solicitud',source='legacy-crm-supervisor',
                    status='triaged',priority='p0',created_at='2026-11-12T12:00:00Z',**extra)

    def test_new_owner_request_survives_legacy_limit(self):
        tasks = [self.task(n) for n in range(1,72)]
        owner = self.task(100,source_ref='request-100')
        owner.update(source='control-private',priority='p2',created_at='2026-11-14T23:00:00Z')
        data=self.snapshot([owner]+tasks)
        saved=next(t for t in data['tasks'] if t['id']==100)
        self.assertEqual(saved['request_id'],'request-100')
        self.assertEqual(saved['created_at'],owner['created_at'])
        self.assertEqual(len([t for t in data['tasks'] if t['id']==100]),1)

    def test_old_pending_approval_is_never_hidden_by_reset(self):
        data=self.snapshot([],approvals=[dict(public_id=99,task_id='old',warning='Review',created_at='2026-10-01T00:00:00Z')])
        self.assertEqual(data['decisions'][0]['approval_id'],99)

    def test_only_safe_completed_outputs_enter_summary(self):
        safe=self.task(1,result=dict(action='vivabot_git_status',bridge_id=203,summary='generic'))
        other=self.task(2,result=dict(action='deploy_vivabot',bridge_id=204,summary='not executed here'))
        data=self.snapshot([safe,other],bridge=[dict(id=203,result=dict(returncode=0,output=json.dumps(dict(branch='main',tracked_clean=True,untracked_count=0))))])
        self.assertIn('Sin cambios locales',data['tasks'][0]['summary'])
        request=next(p for p in self.paths if p.startswith('crm_ops_bridge?'))
        self.assertIn('id=in.(203)',request)
        self.assertNotIn('204',request)
        self.assertIn('status=eq.done',request)

if __name__=='__main__':
    unittest.main(verbosity=2)
