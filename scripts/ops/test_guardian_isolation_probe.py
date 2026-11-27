import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("bridge", Path(__file__).with_name("cv-ops-bridge.py"))
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

class IsolationProbeTests(unittest.TestCase):
    def test_reject_arguments(self):
        with patch.object(bridge, "guardian_isolation_probe") as probe:
            self.assertEqual(bridge.action_guardian_isolation_probe({"unit":"other"})["returncode"], 2)
            probe.assert_not_called()

    def test_flags_fail_closed(self):
        with patch.object(bridge, "load_env_files", return_value={"CV_GITHUB_RELEASE_ENABLED":"1"}), patch.object(bridge.subprocess, "Popen") as launch:
            result = bridge.guardian_isolation_probe()
            self.assertFalse(result["functional_isolation_certified"])
            launch.assert_not_called()

    def test_gate_fail_closed(self):
        with patch.object(bridge, "load_env_files", return_value={}), patch.object(bridge, "guardian_gate_attestation", return_value={}), patch.object(bridge.subprocess, "Popen") as launch:
            self.assertFalse(bridge.guardian_isolation_probe()["functional_isolation_certified"])
            launch.assert_not_called()

    def test_no_unit_start(self):
        import inspect
        source = inspect.getsource(bridge.guardian_isolation_probe)
        self.assertNotIn("systemctl", source)
        self.assertIn("extra_groups=[]", source)
        self.assertIn("dir_fd=workspace_fd", source)
        self.assertIn("--unshare-all", source)

    def test_exact_apparmor_profile_required(self):
        import inspect
        source = inspect.getsource(bridge.guardian_isolation_probe)
        self.assertIn("profile=='cv-dev-worker-bwrap (enforce)'", source)
        self.assertNotIn("profile.endswith(' (enforce)')", source)

    def launch(self, code):
        import subprocess
        import sys
        return subprocess.Popen([sys.executable, "-c", code], stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, start_new_session=True)

    def test_capture_valid_and_cleanup(self):
        proc = self.launch("print('{}')")
        self.assertEqual(bridge.guardian_capture_probe(proc), {})
        self.assertTrue(proc.stdout.closed)
        self.assertEqual(proc.returncode, 0)

    def test_capture_oversized_and_cleanup(self):
        proc = self.launch("import time; print('x'*8192, flush=True); time.sleep(30)")
        with self.assertRaisesRegex(ValueError, "output_limit"):
            bridge.guardian_capture_probe(proc)
        self.assertTrue(proc.stdout.closed)
        self.assertIsNotNone(proc.returncode)

    def test_capture_timeout_and_cleanup(self):
        proc = self.launch("import time; time.sleep(30)")
        with self.assertRaises(ValueError):
            bridge.guardian_capture_probe(proc, timeout=0.1)
        self.assertTrue(proc.stdout.closed)
        self.assertIsNotNone(proc.returncode)

    def test_capture_nonzero(self):
        proc = self.launch("print('{}'); raise SystemExit(1)")
        self.assertEqual(bridge.guardian_capture_probe(proc), {})
        self.assertTrue(proc.stdout.closed)

    def test_check_matrix_requires_every_true(self):
        import inspect
        source = inspect.getsource(bridge.guardian_isolation_probe)
        self.assertIn("set(checks) == required", source)
        self.assertIn("all(result[\"checks\"].values())", source)
        self.assertIn("result[\"cleanup_verified\"] = False", source)

    def test_failed_probe_is_not_success(self):
        with patch.object(bridge, "guardian_isolation_probe", return_value={"functional_isolation_certified":False}):
            self.assertEqual(bridge.action_guardian_isolation_probe({})["returncode"],1)

if __name__ == "__main__":
    unittest.main()
