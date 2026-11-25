import sys
import hashlib
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from github_release_rollback import rollback_candidate

class RollbackTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.old=b'x=1\n'
        self.new=b'x=2\n'
        self.a=hashlib.sha256(self.old).hexdigest()
        self.b=hashlib.sha256(self.new).hexdigest()
        self.target=self.root/'github_contingency.py'
        self.backup=self.root/('github_contingency.backup.'+self.a)
        self.write(self.target,self.new)
        self.write(self.backup,self.old)
    def write(self,path,body):
        path.write_bytes(body)
        path.chmod(0o600)
    def test_restores_exact_backup(self):
        r=rollback_candidate(self.a,self.b,False,root=self.root)
        self.assertTrue(r['ok'])
        self.assertEqual(self.target.read_bytes(),self.old)
    def test_healthy_never_restores(self):
        for state in (True,None,0):
            self.assertFalse(rollback_candidate(self.a,self.b,state,root=self.root)['ok'])
        self.assertEqual(self.target.read_bytes(),self.new)
    def test_tampered_backup(self):
        self.write(self.backup,b'x=3')
        self.assertEqual(rollback_candidate(self.a,self.b,False,root=self.root)['reason'],'backup_mismatch')
    def test_changed_target(self):
        self.write(self.target,b'x=3')
        self.assertEqual(rollback_candidate(self.a,self.b,False,root=self.root)['reason'],'target_changed')
    def test_path_injection(self):
        self.assertEqual(rollback_candidate('../secret',self.b,False,root=self.root)['reason'],'invalid_digest')
    def test_missing_backup(self):
        self.backup.unlink()
        self.assertFalse(rollback_candidate(self.a,self.b,False,root=self.root)['ok'])
if __name__=='__main__': unittest.main()
