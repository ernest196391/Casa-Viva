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
    def test_host_templates_excluded_from_extraction(self):
        import time
        spec=importlib.util.spec_from_file_location("launcher",Path(m.__file__).parent/"github_test_only_launcher.py")
        launcher=importlib.util.module_from_spec(spec);spec.loader.exec_module(launcher)
        names=["scripts/ops/sandbox/cv-autonomous-fixture@.service","scripts/ops/sandbox/cv-autonomous-fixture@.timer","scripts/ops/tests/contract.py"]
        def git(args,**kw):
            if args[:2]==["cat-file","-t"]:return b"commit\n"
            if args[0]=="ls-tree":return b"".join(b"100644 blob "+b"a"*40+b" 1\t"+name.encode()+b"\0" for name in names)
            return b"x"
        with tempfile.TemporaryDirectory() as tmp,patch.object(launcher,"git",side_effect=git):
            launcher.extract("a"*40,Path(tmp),time.monotonic()+10)
            self.assertTrue((Path(tmp)/names[2]).is_file())
            self.assertFalse((Path(tmp)/names[0]).exists())
            self.assertFalse((Path(tmp)/names[1]).exists())
            names.append("scripts/ops/sandbox/other@.service")
            with self.assertRaisesRegex(ValueError,"unsafe path"):
                launcher.extract("a"*40,Path(tmp)/"second",time.monotonic()+10)
    def test_diagnostic_codes(self):
        self.assertEqual(m.diagnostic(125,True),"boundary_blocked")
        self.assertEqual(m.diagnostic(1,True),"child_failure")
        self.assertEqual(m.diagnostic(0,False),"cleanup_unconfirmed")
if __name__=="__main__":unittest.main()
