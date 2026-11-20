"""Quota backoff regression: execute only the actual entrypoint AST, no imports/network."""
import ast
import contextlib
import io
from pathlib import Path
import unittest
import urllib.error
from types import SimpleNamespace

OPS = Path(__file__).resolve().parents[1]

class QuotaBackoffTest(unittest.TestCase):
    def execute(self, name, error=None):
        tree = ast.parse((OPS / name).read_text())
        entry = tree.body[-1]
        self.assertIsInstance(entry, ast.If)
        calls = []
        def main():
            calls.append("main")
            if error:
                raise urllib.error.HTTPError("https://example.invalid", error, "test", {}, None)
            return 0
        scope = {"__name__": "__main__", "main": main, "urllib": urllib,
                 "time": SimpleNamespace(sleep=lambda n: calls.append(n))}
        with contextlib.redirect_stdout(io.StringIO()):
            try:
                exec(compile(ast.Module(body=[entry], type_ignores=[]), name, "exec"), scope)
            except SystemExit as exc:
                self.assertEqual(exc.code, 0)
        return calls

    def test_quota_and_rate_limit(self):
        for name in ("cv-ops-bridge.py", "dev-worker.py"):
            for code in (402, 429):
                with self.subTest(name=name, status=code):
                    self.assertEqual(self.execute(name, code), ["main", 300])

    def test_other_errors_propagate(self):
        for name in ("cv-ops-bridge.py", "dev-worker.py"):
            for code in (401, 403, 500):
                with self.subTest(name=name, status=code):
                    with self.assertRaises(urllib.error.HTTPError):
                        self.execute(name, code)

    def test_success_has_no_backoff(self):
        for name in ("cv-ops-bridge.py", "dev-worker.py"):
            self.assertEqual(self.execute(name), ["main"])

if __name__ == "__main__":
    unittest.main()
