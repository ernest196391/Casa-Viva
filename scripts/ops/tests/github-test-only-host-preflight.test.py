import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("host_preflight",ROOT/"github_test_only_host_preflight.py")
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class Probe(unittest.TestCase):
    def test_all_deps_are_not_runtime_certification(self):
        expected={*m.BINARIES,m.SOURCE_REPO/".git/HEAD"}
        out=m.inspect(exists=lambda p:p in expected,
                      read=lambda p:chr(10).join(m.REQUIRED_PROFILES),
                      run=lambda argv:0)
        self.assertTrue(out["ready_for_reversible_host_pilot"])
        self.assertFalse(out["runtime_certified"])
        self.assertFalse(out["activation"])
    def test_missing_apparmor_fails_closed(self):
        out=m.inspect(exists=lambda p:True,read=lambda p:"",run=lambda argv:0)
        self.assertFalse(out["ready_for_reversible_host_pilot"])
    def test_invalid_unit_fails_closed(self):
        out=m.inspect(exists=lambda p:True,
                      read=lambda p:chr(10).join(m.REQUIRED_PROFILES),
                      run=lambda argv:1)
        self.assertFalse(out["ready_for_reversible_host_pilot"])
    def test_unavailable_kernel_profile_fails_closed(self):
        def inaccessible(_):raise PermissionError("denied")
        out=m.inspect(exists=lambda p:True,read=inaccessible,run=lambda argv:0)
        self.assertFalse(out["ready_for_reversible_host_pilot"])

if __name__=="__main__":
    unittest.main()
