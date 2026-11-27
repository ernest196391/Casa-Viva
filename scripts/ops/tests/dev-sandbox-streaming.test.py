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
    def invoke(self,code,timeout=3,preflight=None):
        # Same identity in CI only: exercise real pipe handling, not isolation.
        def spawn(argv,**kwargs):
            for name in ('user','group','extra_groups'):
                kwargs.pop(name)
            return subprocess.Popen(argv,**kwargs)
        with tempfile.TemporaryDirectory() as cwd:
            return box._launch([sys.executable,'-I','-c',code],cwd=cwd,env={},timeout=timeout,uid=1000,gid=1001,spawn=spawn,preflight=preflight)
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
    def test_handshake_real_pipe(self):
        code="import sys; print('ready',flush=True); assert sys.stdin.buffer.read(1)==b'G'; print('executed')"
        self.assertEqual(self.invoke(code,preflight=lambda line:line==b'ready'),(0,'ready\nexecuted\n'))
        self.assertEqual(self.invoke(code,preflight=lambda line:False)[0],125)
    def test_missing_handshake_rejected(self):
        self.assertEqual(self.invoke("print('')",preflight=lambda line:False)[0],125)
        self.assertEqual(self.invoke("pass",preflight=lambda line:True)[0],125)
    def test_observer_error_rejected(self):
        def broken(line):
            raise RuntimeError('observer unavailable')
        self.assertEqual(self.invoke("print('ready',flush=True); import time; time.sleep(10)",preflight=broken)[0],125)
if __name__=='__main__': unittest.main()
