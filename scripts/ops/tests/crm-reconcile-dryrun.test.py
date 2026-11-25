import importlib.util
from pathlib import Path
import unittest
spec=importlib.util.spec_from_file_location('reconcile',Path(__file__).resolve().parents[1]/'crm_reconcile_dryrun.py')
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
A='00000000-0000-4000-8000-000000000001'
B='00000000-0000-4000-8000-000000000002'
def row(k,t=A,**changes):
    return dict(business_id=t,wa_id=k,chat_jid='fixture-chat',is_group=False,sender_jid=None,sender_phone=None,sender_name=None,from_me=False,body='PRIVATE_FIXTURE',sent_at='2026-11-25T12:00:00Z') | changes
def run(a,b,**kw):
    return m.reconcile(a,b,A,B,**({'identity_verified':True,'snapshot_complete':True}|kw))
class Tests(unittest.TestCase):
    def test_missing(self):
        r=run([row('a'),row('b')],[row('a',B)])
        self.assertEqual((r['matching'],r['eligible']),(1,1))
        self.assertNotIn('PRIVATE_FIXTURE',str(r))
    def test_repeat(self):
        self.assertEqual(run([row('a')],[row('a',B)])['eligible'],0)
    def test_duplicates(self):
        self.assertFalse(run([row('a'),row('a')],[])['ok'])
    def test_remote_duplicates(self):
        self.assertEqual(run([row('b')],[row('a',B),row('a',B)])['eligible'],0)
    def test_conflict(self):
        r=run([row('a'),row('b')],[row('a',B,body='different')])
        self.assertEqual((r['conflicts'],r['eligible']),(1,0))
    def test_remote_only(self):
        self.assertEqual(run([],[row('a',B)])['remote_only'],1)
    def test_foreign(self):
        self.assertFalse(run([row('a',B)],[])['ok'])
    def test_empty_key(self):
        self.assertEqual(run([row('')],[])['invalid'],1)
    def test_schema(self):
        self.assertFalse(run([row('a',extra='x')],[])['ok'])
    def test_direction(self):
        self.assertFalse(run([row('a',from_me=1)],[])['ok'])
    def test_timestamp(self):
        self.assertEqual(run([row('a')],[row('a',B,sent_at='2026-11-25T07:00:00-05:00')])['matching'],1)
    def test_naive_timestamp(self):
        self.assertFalse(run([row('a',sent_at='2026-11-25T12:00:00')],[])['ok'])
    def test_identity_gate(self):
        self.assertFalse(run([],[],identity_verified=False)['ok'])
    def test_snapshot_gate(self):
        self.assertFalse(run([],[],snapshot_complete=False)['ok'])
    def test_limit(self):
        from unittest.mock import patch
        with patch.object(m,'MAX_ROWS',1):
            self.assertEqual(run([row('a'),row('b')],[])['eligible'],0)
if __name__=='__main__':
    unittest.main()
