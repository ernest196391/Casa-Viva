import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import github_release_supervisor as s
import github_release_transaction as tx

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.old=b'x=1\n'
        self.new=b'x=2\n'
        tx.atomic_file(self.root/'github_contingency.py', self.old)
        tx.atomic_file(self.root/('github_contingency.backup.'+tx.digest(self.old)),self.old)
        with tx.release_lock(self.root):
            self.data=tx.prepare('a'*40,self.old,self.new,self.root)
        tx.atomic_file(self.root/'github_contingency.py',self.new)
        tx.save_state(self.data,'pending_health',self.root)
        self.epoch=(100,1)
        self.calls=0
    def identity(self):
        return self.epoch
    def restart(self):
        self.calls+=1
        self.epoch=(100+self.calls,self.calls+1)
        return True
    def check(self,result=True,**overrides):
        return s.supervise(root=self.root,process_identity=overrides.get('identity',self.identity),
            restart=overrides.get('restart',self.restart),
            health_probe=overrides.get('probe',lambda d,sha,e: result))
    def test_confirmed_only_with_independent_probe_and_restart(self):
        self.assertEqual(self.check(True)['state'],'confirmed')
    def test_health_failure_restores_but_not_confirms(self):
        self.assertEqual(self.check(False)['state'],'rollback_pending')
        self.assertEqual((self.root/'github_contingency.py').read_bytes(),self.old)
        self.assertEqual(tx.load_state(self.root)['state'],'rollback_pending')
        self.assertEqual(self.check(True)['state'],'restored')
    def test_unknown_not_confirmation_or_rollback(self):
        self.assertEqual(self.check(None)['reason'],'health_unknown')
        self.assertEqual(tx.load_state(self.root)['state'],'pending_health')
        self.assertEqual((self.root/'github_contingency.py').read_bytes(),self.new)
    def test_no_restart_no_approval(self):
        self.assertEqual(self.check(True,restart=lambda:False)['reason'],'restart_unavailable')
    def test_same_process_not_accepted(self):
        self.assertEqual(self.check(True,restart=lambda:True)['reason'],'restart_unverified')
    def test_stale_process_observation(self):
        def probe(d,sha,e):
            self.epoch=(999,999)
            return True
        self.assertEqual(self.check(probe=probe)['reason'],'observation_stale')
    def test_stale_journal_observation(self):
        def probe(d,sha,e):
            tx.save_state(self.data,'rollback_pending',self.root)
            return True
        self.assertEqual(self.check(probe=probe)['reason'],'observation_stale')
    def test_altered_target_never_approved(self):
        tx.atomic_file(self.root/'github_contingency.py',b'x=3\n')
        self.assertEqual(self.check(True)['reason'],'supervision_unavailable')
        self.assertEqual(self.calls,0)
    def test_restored_failure_stays_pending(self):
        tx.atomic_file(self.root/'github_contingency.py',self.old)
        tx.save_state(self.data,'rollback_pending',self.root)
        self.assertEqual(self.check(False)['reason'],'restored_health_failed')
    def test_prepared_state_requires_new_cycle(self):
        tx.atomic_file(self.root/'github_contingency.py',self.old)
        tx.save_state(self.data,'prepared',self.root)
        self.assertEqual(self.check(True)['state'],'aborted')
        self.assertEqual(self.calls,0)
    def test_insecure_target_rejected(self):
        (self.root/'github_contingency.py').chmod(0o644)
        self.assertEqual(self.check(True)['reason'],'supervision_unavailable')
    def test_invalid_epoch_rejected(self):
        self.assertEqual(self.check(True,identity=lambda:(0,0))['reason'],'process_unknown')
    def test_invalid_probe_fails_closed(self):
        self.assertEqual(self.check(probe=lambda d,sha,e:"healthy")['reason'],'health_unknown')

if __name__ == '__main__':
    unittest.main()
