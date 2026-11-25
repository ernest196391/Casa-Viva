import sys
from pathlib import Path
import unittest
from unittest.mock import patch
import json
import subprocess
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import crm_reconcile_adapter as m
A='00000000-0000-4000-8000-000000000001'
B='00000000-0000-4000-8000-000000000002'
def row(k,tenant=A):
    return dict(business_id=tenant,wa_id=k,chat_jid='fixture',is_group=False,sender_jid=None,sender_phone=None,sender_name=None,from_me=False,body='PRIVATE_FIXTURE',sent_at='2026-11-25T12:00:00Z')
class Tests(unittest.TestCase):
    def test_real_adapter_composition(self):
        r=m.simulate(lambda:(A,[row('a'),row('b')]),lambda key:(A,[row('a')]),lambda:'fixture')
        self.assertEqual(r['candidate_count'],1)
        self.assertEqual(r['eligible'],0)
        self.assertEqual(r['snapshot_quality'],'repeated_observation_not_transactional')
        self.assertNotIn('PRIVATE_FIXTURE',str(r))
    def test_changed_remote(self):
        from unittest.mock import Mock
        remote=Mock(side_effect=[(A,[row('a')]),(A,[row('b')])])
        self.assertEqual(m.simulate(lambda:(A,[]),remote,lambda:'fixture')['reason'],'remote_changed')
    def test_tenant_mismatch(self):
        self.assertEqual(m.simulate(lambda:(A,[]),lambda k:(B,[]),lambda:'fixture')['reason'],'tenant_mapping_unverified')
    def test_error_redacted(self):
        def fail(): raise RuntimeError('PRIVATE_FIXTURE')
        self.assertEqual(m.simulate(config=fail),dict(ok=False,reason='snapshot_unavailable',eligible=0))
    def test_pagination(self):
        calls=[]
        def get(k,t,p):
            calls.append((t,p))
            if t=='crm_businesses': return [{'id':A}],1
            return [row('a' if p['offset']=='0' else 'b')],2
        with patch.object(m,'PAGE',1):
            self.assertEqual(len(m.remote_snapshot('fixture',get)[1]),2)
        self.assertEqual(calls[-1][1]['business_id'],'eq.'+A)
    def test_count_changes(self):
        def get(k,t,p):
            if t=='crm_businesses': return [{'id':A}],1
            return [row('a')],2 if p['offset']=='0' else 3
        with self.assertRaises(ValueError): m.remote_snapshot('fixture',get)
    def test_duplicate_page(self):
        def get(k,t,p):
            return ([{'id':A}],1) if t=='crm_businesses' else ([row('a')],2)
        with self.assertRaises(ValueError): m.remote_snapshot('fixture',get)
    def test_empty_page(self):
        def get(k,t,p):
            return ([{'id':A}],1) if t=='crm_businesses' else ([],2)
        with self.assertRaises(ValueError): m.remote_snapshot('fixture',get)
    def test_foreign_page(self):
        def get(k,t,p):
            return ([{'id':A}],1) if t=='crm_businesses' else ([row('a',B)],1)
        with self.assertRaises(ValueError): m.remote_snapshot('fixture',get)
    def test_ambiguous_tenant(self):
        with self.assertRaises(ValueError): m.remote_snapshot('fixture',lambda *a:([{'id':A},{'id':B}],2))
    @patch.object(m.subprocess,'run')
    def test_local_read_only(self,run):
        run.return_value=subprocess.CompletedProcess([],0,json.dumps(dict(tenants=[A],count=1,rows=[row('a')])).encode(),b'')
        self.assertEqual(m.local_snapshot()[0],A)
        self.assertIn('REPEATABLE READ READ ONLY',run.call_args.args[0][-1])
    @patch.object(m.subprocess,'run')
    def test_local_truncated(self,run):
        run.return_value=subprocess.CompletedProcess([],0,b'{"tenants":[],"count":2,"rows":[]}',b'')
        with self.assertRaises(ValueError): m.local_snapshot()
    def test_config_status_redacted(self):
        for exc,reason in [(FileNotFoundError('PRIVATE_FIXTURE'),'config_missing'),(PermissionError('PRIVATE_FIXTURE'),'config_access_denied'),(ValueError('wrong_project'),'wrong_project'),(ValueError('PRIVATE_FIXTURE'),'config_invalid')]:
            with patch.object(m,'read_config',side_effect=exc):
                self.assertEqual(m.config_status(),dict(ok=False,reason=reason))
        with patch.object(m,'read_config',return_value='PRIVATE_FIXTURE'):
            self.assertEqual(m.config_status(),dict(ok=True,scope='configuration_only',permission_scope='not_verified'))
if __name__=='__main__': unittest.main()
