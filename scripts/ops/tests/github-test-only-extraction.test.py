"""Real local Git extraction regressions, no network or production files."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

P=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location("launcher",P/"github_test_only_launcher.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)

class ExtractTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.repo=self.root/"repo";self.repo.mkdir()
        self.work=self.root/"work";self.work.mkdir()
        self.g("init","-q")
        self.g("config","user.name","Fixture")
        self.g("config","user.email","fixture@example.invalid")
        self.addCleanup(patch.stopall)
        patch.object(m,"REPO",self.repo).start()
    def g(self,*args):
        return subprocess.check_output(["git","-C",str(self.repo),*args],stderr=subprocess.DEVNULL).decode().strip()
    def commit(self):
        self.g("add",".");self.g("commit","-qm","fixture");return self.g("rev-parse","HEAD")
    def put(self,name,data):
        p=self.repo/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
    def test_exact_revision(self):
        self.put("scripts/ops/fixture.py",b"one")
        self.put("private.txt",b"excluded")
        sha=self.commit()
        self.put("scripts/ops/fixture.py",b"two");self.commit()
        m.extract(sha,self.work,time.monotonic()+10)
        self.assertEqual((self.work/"scripts/ops/fixture.py").read_bytes(),b"one")
        self.assertFalse((self.work/"private.txt").exists())
    def test_stream_limit(self):
        self.put("scripts/ops/fixture.py",b"x"*65536)
        sha=self.commit()
        oid=self.g("rev-parse",sha+":scripts/ops/fixture.py")
        with self.assertRaises(ValueError):m.git(["cat-file","blob",oid],max_output=16)
        self.assertEqual(m.git(["cat-file","blob",oid],max_output=65536),b"x"*65536)
    def test_blob_limit(self):
        self.put("scripts/ops/fixture.py",b"x"*(m.MAX_BLOB+1))
        sha=self.commit()
        with self.assertRaises(ValueError):m.extract(sha,self.work,time.monotonic()+10)
    def test_total_limit(self):
        self.put("scripts/ops/a.py",b"a"*20);self.put("scripts/ops/b.py",b"b"*20)
        sha=self.commit()
        with patch.object(m,"MAX_TOTAL",30):
            with self.assertRaises(ValueError):m.extract(sha,self.work,time.monotonic()+10)
    def test_symlink(self):
        p=self.repo/"scripts/ops/link";p.parent.mkdir(parents=True);p.symlink_to("/etc/passwd")
        sha=self.commit()
        with self.assertRaises(ValueError):m.extract(sha,self.work,time.monotonic()+10)
    def test_expired_global_deadline(self):
        self.put("scripts/ops/a.py",b"x");sha=self.commit()
        with self.assertRaises(TimeoutError):m.extract(sha,self.work,time.monotonic()-1)
    def test_invalid_revision(self):
        with self.assertRaises(ValueError):m.extract("HEAD",self.work,time.monotonic()+10)

if __name__=="__main__":unittest.main()
