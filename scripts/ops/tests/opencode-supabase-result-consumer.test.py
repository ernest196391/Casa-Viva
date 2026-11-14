"""Sin red, credenciales ni SQL real. Transporte Supabase completamente mock."""
import importlib.util
import copy
from pathlib import Path
import unittest
from unittest.mock import Mock

P = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('real_consumer', P / 'opencode-supabase-result-consumer.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ConsumerTests(unittest.TestCase):
    def setUp(self):
        self.now = 1700000000
        self.result = dict(version='opencode-result-v1', run_id='1',
            task_id='00000000-0000-0000-0000-000000000001', repo='casa-viva',
            status='completed', error=None, base_sha='a'*40, changed_paths=['docs/a.md'],
            diff='+a', logs=[], finished_at=m.contract.ts(self.now-10),
            expires_at=m.contract.ts(self.now+60), ttl_seconds=70)
        self.client = Mock()
        self.client.rpc.return_value.execute.return_value.data = {'status':'waiting_approval','run_id':1}
        self.store = m.SupabaseResultStore(self.client)

    def test_one_transaction_no_table_writes(self):
        self.assertEqual(self.store.accept(self.result,self.now)['status'],'waiting_approval')
        self.client.rpc.assert_called_once_with('ops_accept_opencode_result',{'p_result':self.result})
        self.client.table.assert_not_called()

    def test_contract_negatives_never_reach_rpc(self):
        for patch in ({'run_id':'run-1'},{'run_id':'01'},{'run_id':'9223372036854775808'},
            {'task_id':'task-x'},{'status':'approved'},{'commands':['id']},
            {'diff':'x'*400001},{'changed_paths':['docs/../a']},
            {'changed_paths':['docs//a']},{'changed_paths':['docs/\\a']},
            {'changed_paths':['docs/a']*2},{'logs':['x'*2001]},
            {'finished_at':m.contract.ts(self.now+1)}, {'ttl_seconds':1},
            {'expires_at':m.contract.ts(self.now-1)}):
            with self.subTest(patch=str(patch)[:60]), self.assertRaises(ValueError):
                self.store.accept({**self.result,**patch},self.now)
        self.client.rpc.assert_not_called()

    def test_rpc_rejection_or_lost_response_keeps_mailbox(self):
        import tempfile, json
        for error in ('task mismatch','repo mismatch','base mismatch','invalid state',
                      'conflicting duplicate','approval insert failed','event insert failed','response lost'):
            with self.subTest(error=error), tempfile.TemporaryDirectory() as tmp:
                home=Path(tmp)
                for name in ('results','dead'): (home/name).mkdir(mode=0o700)
                path=home/'results/task-run-1.json'
                path.write_text(json.dumps(self.result))
                path.chmod(0o600)
                self.client.rpc.return_value.execute.side_effect=RuntimeError(error)
                self.assertEqual(m.consume_results(self.store,home,self.now)['retry'],[path.name])
                self.assertTrue(path.exists())

    def test_duplicate_receipt_and_failed(self):
        for status in ('duplicate','failed'):
            self.client.rpc.return_value.execute.return_value.data={'status':status,'run_id':1}
            self.assertEqual(self.store.accept(self.result,self.now)['status'],status)

    def test_invalid_response_not_acknowledged(self):
        self.client.rpc.return_value.execute.return_value.data={'status':'approved'}
        with self.assertRaises(RuntimeError): self.store.accept(self.result,self.now)

    def test_sql_security_and_transaction_shape(self):
        sql=(P/'sql/opencode-result-receipts.sql').read_text().lower()
        self.assertIn('security invoker',sql)
        self.assertNotIn('security definer',sql)
        self.assertIn('for update',sql)
        self.assertIn("grant execute on function public.ops_accept_opencode_result(jsonb) to service_role",sql)
        self.assertNotIn('exception when',sql)  # failures propagate: full RPC rollback
        self.assertIn('primary key references public.ops_dev_runs',sql)
        self.assertIn('insert into public.ops_approvals',sql)

if __name__=='__main__': unittest.main(verbosity=2)
