"""Real subprocess regression; no bwrap, network or production credentials."""
import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('box',Path(__file__).resolve().parents[1]/'dev-sandbox.py')
box=importlib.util.module_from_spec(spec)
spec.loader.exec_module(box)

@unittest.skipUnless(os.name=='posix','POSIX pipes')
class Tests(unittest.TestCase):
    def invoke(self,code,timeout=3):
        # Same identity in CI only: exercise real pipe handling, not isolation.
        def spawn(argv,**kwargs):
            for name in ('user','group','extra_groups'):
                kwargs.pop(name)
            return subprocess.Popen(argv,**kwargs)
        with tempfile.TemporaryDirectory() as cwd:
            return box._launch([sys.executable,'-I','-c',code],cwd=cwd,env={},timeout=timeout,uid=1000,gid=1001,spawn=spawn)
    def test_exact_limit(self):
        rc,out=self.invoke("import sys; sys.stdout.write('x'*30000)")
        self.assertEqual((rc,len(out)),(0,30000))
    def test_overflow_rejects_even_valid_suffix(self):
        rc,out=self.invoke("import sys; sys.stdout.write('x'*1000000)")
        self.assertEqual(rc,125)
    def test_timeout(self):
        rc,out=self.invoke("import time; time.sleep(10)",timeout=0.2)
        self.assertEqual(rc,124)
    def test_nonzero(self):
        self.assertEqual(self.invoke("raise SystemExit(7)")[0],7)
if __name__=='__main__': unittest.main()
