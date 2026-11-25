import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import subprocess
import tempfile
s=importlib.util.spec_from_file_location("cert",Path(__file__).resolve().parents[1]/"github_test_only_launcher_certification.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class CertTests(unittest.TestCase):
    def test_command_isolated(self):
        cmd=m.command("cv-test-only-cert-1-2.service",Path("/tmp/fixture"),"a"*40)
        for prop in ("PrivateNetwork=yes","NoNewPrivileges=yes","MemoryMax=256M","TasksMax=32","CPUQuota=50%","LimitFSIZE=33554432"):
            self.assertIn("--property="+prop,cmd)
        self.assertNotIn("CAP_SYS_PTRACE"," ".join(cmd))
        self.assertNotIn("dev-worker.py",cmd)
        self.assertEqual(cmd[-5:],["--child","cv-test-only-cert-1-2.service","/tmp/fixture","a"*40,"70"])
    def test_budget(self):
        cmd=m.command("cv-test-only-cert-1-2.service",Path("/tmp/fixture"),"a"*40,60)
        self.assertIn("--property=RuntimeMaxSec=43",cmd)
        self.assertEqual(cmd[-1],"40")
        for budget in (0,24,91,True):
            with self.assertRaises(ValueError):m.command("cv-test-only-cert-1-2.service",Path("/tmp/fixture"),"a"*40,budget)
    def test_reject_live_unit(self):
        with self.assertRaises(ValueError):m.command("cv-dev-worker.service",Path("/tmp/fixture"),"a"*40)
    def test_reject_revision(self):
        with self.assertRaises(ValueError):m.command("cv-test-only-cert-1-2.service",Path("/tmp/fixture"),"HEAD")
    def test_reject_live_repo(self):
        self.assertEqual(m.child("cv-test-only-cert-1-2.service","/root/Casa-Viva","a"*40),125)
    def test_cleanup_required(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            (Path(tmp)/".git").mkdir()
            for state,expected in [("LoadState=not-found\nActiveState=inactive",True),("LoadState=loaded\nActiveState=active",False)]:
                def run(argv,**kwargs):
                    return subprocess.CompletedProcess(argv,0,stdout=state if "show" in argv else "",stderr="")
                with patch.object(m.os,"geteuid",return_value=0),patch.object(m.subprocess,"run",side_effect=run):
                    report=m.run(tmp,"a"*40)
                self.assertEqual(report["ok"],expected)
                self.assertFalse(report["production_authorized"])
if __name__=="__main__":unittest.main()
