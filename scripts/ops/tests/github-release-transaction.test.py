import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_transaction as m

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.old=b'x=1\n'
        self.new=b'x=2\n'
        self.target=self.root/'github_contingency.py'
        self.backup=self.root/('github_contingency.backup.'+m.digest(self.old))
        m.atomic_file(self.target,self.old)
        m.atomic_file(self.backup,self.old)
    def prepare(self):
        with m.release_lock(self.root):
            return m.prepare('a'*40,self.old,self.new,self.root)
    def installed(self):
        d=self.prepare()
        m.atomic_file(self.target,self.new)
        m.save_state(d,'pending_health',self.root)
        return d
    def test_concurrent_lock(self):
        with m.release_lock(self.root):
            self.assertEqual(m.recover(root=self.root)['reason'],'release_busy')
    def test_interrupted_before_install(self):
        self.prepare()
        self.assertEqual(m.recover(root=self.root)['state'],'aborted')
        self.assertEqual(self.target.read_bytes(),self.old)
    def test_interrupted_after_install(self):
        self.prepare()
        m.atomic_file(self.target,self.new)
        self.assertEqual(m.recover(True,self.root)['state'],'pending_health')
        self.assertEqual(m.load_state(self.root)['state'],'pending_health')
    def test_confirmed(self):
        self.installed()
        self.assertEqual(m.recover(True,self.root)['state'],'confirmed')
    def test_failure_restores_then_confirms(self):
        self.installed()
        self.assertEqual(m.recover(False,self.root)['state'],'rollback_pending')
        self.assertEqual(self.target.read_bytes(),self.old)
        self.assertEqual(m.recover(None,self.root)['state'],'rollback_pending')
        self.assertEqual(m.recover(True,self.root)['state'],'restored')
    def test_interrupted_rollback(self):
        d=self.installed()
        m.save_state(d,'rollback_pending',self.root)
        self.assertEqual(m.recover(None,self.root)['state'],'rollback_pending')
        self.assertEqual(self.target.read_bytes(),self.old)
    def test_tampered_backup(self):
        self.installed()
        m.atomic_file(self.backup,b'x=3')
        self.assertEqual(m.recover(False,self.root)['reason'],'backup_mismatch')
        self.assertEqual(self.target.read_bytes(),self.new)
    def test_pending_blocks_second_prepare(self):
        self.prepare()
        with self.assertRaisesRegex(ValueError,'release_pending'):
            self.prepare()
    def test_unknown_target(self):
        self.installed()
        m.atomic_file(self.target,b'x=3')
        self.assertEqual(m.recover(False,self.root)['reason'],'target_changed')
if __name__=='__main__': unittest.main()
