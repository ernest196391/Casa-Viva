import importlib.util
import json
from pathlib import Path
import unittest
p=Path(__file__).resolve().parents[1]/"github_test_only_synthetic_pilot.py"
s=importlib.util.spec_from_file_location("pilot",p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)

def fixture():
    a=dict(digest=m.DIGEST,nnp=True,caps_zero=True,uid=1001,pid=2,
           label="cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)")
    h=dict(measurement=dict(a),digest=m.DIGEST,namespace_pid=2,uid=1001,
           production_authorized=False,scope="synthetic_only",host_pid=123,start_ticks=42,invocation="a"*32)
    return dict(ok=True,functional_ok=True,scope="synthetic_only",production_authorized=False,
                digest=m.DIGEST,measurements=[dict(a) for _ in range(3)],host_measurements=[dict(h) for _ in range(3)])

class Tests(unittest.TestCase):
    def test_fixed_command(self):
        cmd=m.command("cv-release-pilot-123-456.service")
        self.assertEqual(cmd[-1],"--synthetic-pilot")
        for p in ("NoNewPrivileges=yes","PrivateNetwork=yes","MemoryMax=256M","TasksMax=32","CPUQuota=50%"):
            self.assertIn("--property="+p,cmd)
        with self.assertRaises(ValueError):m.command("cv-ops-bridge.service")
    def test_success_is_synthetic_only(self):
        r=m.validate_report(json.dumps(fixture()))
        self.assertEqual(r["cases"],3);self.assertFalse(r["production_authorized"])
    def test_no_host_evidence(self):
        f=fixture();f["host_measurements"]=[]
        with self.assertRaises(ValueError):m.validate_report(json.dumps(f))
    def test_changed_invocation(self):
        f=fixture();f["host_measurements"][1]["invocation"]="b"*32
        with self.assertRaises(ValueError):m.validate_report(json.dumps(f))
    def test_bad_identity_or_capabilities(self):
        for key,value in (("uid",0),("caps_zero",False),("nnp",False)):
            f=fixture();f["measurements"][0][key]=value
            with self.assertRaises(ValueError):m.validate_report(json.dumps(f))
    def test_production_permission_refused(self):
        f=fixture();f["production_authorized"]=True
        with self.assertRaises(ValueError):m.validate_report(json.dumps(f))
if __name__=="__main__":unittest.main()
