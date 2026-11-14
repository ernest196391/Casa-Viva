"""Probe regression: timeout kills process group and retains redacted output."""
import ast
import os
from pathlib import Path
import re
import signal
import subprocess
import unittest
from unittest.mock import Mock, patch

source = Path(__file__).resolve().parents[1] / "cv-ops-bridge.py"
tree = ast.parse(source.read_text(encoding="utf-8"))
node = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == "_planner_probe_run")
scope = dict(os=os, re=re, subprocess=subprocess)
exec(compile(ast.Module(body=[node], type_ignores=[]), str(source), "exec"), scope)
probe_run = scope["_planner_probe_run"]

class ProbeTests(unittest.TestCase):
    def test_success_and_secrets(self):
        proc = Mock(pid=987, returncode=0)
        proc.communicate.return_value = ("CV_PROBE_OK", "Bearer secret-value sk-abcdefghijklmnop")
        with patch.object(subprocess, "Popen", return_value=proc) as spawn:
            result = probe_run(["fixed"], "/tmp")
        self.assertEqual(result["returncode"], 0)
        self.assertNotIn("secret-value", result["output"])
        self.assertNotIn("sk-abcdefghijklmnop", result["output"])
        self.assertTrue(spawn.call_args.kwargs["start_new_session"])
        self.assertNotIn("shell", spawn.call_args.kwargs)

    def test_timeout_retains_output_and_kills_group(self):
        proc = Mock(pid=987, returncode=-9)
        proc.communicate.side_effect = [subprocess.TimeoutExpired("fixed", 1),
                                        ("partial", "last diagnostic")]
        with patch.object(subprocess, "Popen", return_value=proc), patch.object(os, "killpg", create=True) as kill, patch.object(signal, "SIGKILL", 9, create=True):
            result = probe_run(["fixed"], "/tmp", 1)
        kill.assert_called_once()
        self.assertEqual(kill.call_args.args[0], 987)
        self.assertEqual(result["returncode"], 124)
        self.assertTrue(result["timed_out"])
        self.assertIn("last diagnostic", result["output"])

    def test_nonzero_is_not_success(self):
        proc = Mock(returncode=8)
        proc.communicate.return_value = ("", "bad model")
        with patch.object(subprocess, "Popen", return_value=proc):
            result = probe_run(["fixed"], "/tmp")
        self.assertEqual(result["returncode"], 8)
        self.assertFalse(result["timed_out"])

if __name__ == "__main__":
    unittest.main(verbosity=2)
