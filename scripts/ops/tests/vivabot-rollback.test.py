import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("bridge", Path(__file__).resolve().parents[1] / "cv-ops-bridge.py")
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class RollbackTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = Path(self.tmp.name)
        self.git("init", "-b", "main")
        self.git("config", "user.name", "Test")
        self.git("config", "user.email", "test@example.invalid")
        (self.repo / "code.js").write_text("old")
        self.git("add", ".")
        self.git("commit", "-m", "old")
        self.old = self.git("rev-parse", "HEAD")
        (self.repo / "code.js").write_text("new")
        self.git("add", ".")
        self.git("commit", "-m", "new")
        self.new = self.git("rev-parse", "HEAD")

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.repo, text=True).strip()

    def rollback(self, reload_pm2=False):
        return bridge._rollback_vivabot(self.repo, self.new, self.old, "main", reload_pm2)

    def test_clean_restore_preserves_untracked(self):
        (self.repo / "local.txt").write_text("preserve")
        self.assertEqual(self.rollback()["returncode"], 0)
        self.assertEqual(self.git("rev-parse", "HEAD"), self.old)
        self.assertEqual((self.repo / "code.js").read_text(), "old")
        self.assertEqual((self.repo / "local.txt").read_text(), "preserve")

    def test_dirty_blocks_without_restarting(self):
        (self.repo / "code.js").write_text("local edit")
        with patch.object(bridge, "_pm2_vivabot_online") as health:
            self.assertNotEqual(self.rollback(True)["returncode"], 0)
            health.assert_not_called()
        self.assertEqual(self.git("rev-parse", "HEAD"), self.new)
        self.assertEqual((self.repo / "code.js").read_text(), "local edit")

    def test_moved_head_blocks(self):
        self.git("commit", "--allow-empty", "-m", "concurrent")
        head = self.git("rev-parse", "HEAD")
        self.assertNotEqual(self.rollback()["returncode"], 0)
        self.assertEqual(self.git("rev-parse", "HEAD"), head)

    def test_changed_branch_blocks(self):
        self.git("switch", "-c", "other")
        self.assertNotEqual(self.rollback()["returncode"], 0)
        self.assertEqual(self.git("rev-parse", "HEAD"), self.new)


if __name__ == "__main__":
    unittest.main()
