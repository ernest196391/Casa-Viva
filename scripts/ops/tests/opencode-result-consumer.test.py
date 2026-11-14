#!/usr/bin/env python3
"""Pruebas de Fase C del consumidor de resultados OpenCode (Control OS).

Cubre: validación estricta del paquete de resultados (versión,
identificadores, tamaños, claves), conciliación con run y tarea,
detección de duplicados y estados caducados, caducidad de runs
waiting_ai sin resultado, cuarentena con motivo, aprobación y rechazo
humanos, y escaneo estático de la fuente (sin red, sin subprocesos, sin
rutas de sistema, sin credenciales). Todo con datos ficticios en
tienda simulada; sin Supabase ni credenciales.

Ejecutar: python3 scripts/ops/tests/opencode-result-consumer.test.py
"""
import ast
import importlib.util
import json
import os
import stat
import tempfile
import time
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
CONSUMER_PATH = HERE.parent / "opencode-result-consumer.py"

_spec = importlib.util.spec_from_file_location("cv_result_consumer", CONSUMER_PATH)
consumer = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(consumer)


def make_mailbox() -> tempfile.TemporaryDirectory:
    tmp = tempfile.TemporaryDirectory()
    home = Path(tmp.name)
    for name in ("jobs", "claims", "results", "dead", "work"):
        (home / name).mkdir(mode=0o700)
    return tmp


class Fixture(unittest.TestCase):
    def setUp(self):
        self.mailbox_tmp = make_mailbox()
        self.home = Path(self.mailbox_tmp.name)
        self.dirs = consumer.ensure_mailbox_dirs(self.home)
        self.store_tmp = tempfile.TemporaryDirectory()
        self.store_path = Path(self.store_tmp.name) / "sim-store.json"
        self.store = consumer.SimStore(self.store_path)
        self.now = 1_700_000_000.0

    def tearDown(self):
        self.mailbox_tmp.cleanup()
        self.store_tmp.cleanup()

    def seed_run(self, run_id="run-abc-123", task_id="task-uuid-1", repo="casa-viva",
                 status="waiting_ai", updated_at=None):
        self.store.runs[run_id] = {
            "id": run_id,
            "task_id": task_id,
            "repo": repo,
            "status": status,
            "updated_at": updated_at or consumer.ts(self.now),
        }
        self.store.tasks[task_id] = {
            "id": task_id,
            "public_id": 42,
            "title": "Fase C simulada",
            "status": "waiting_ai",
            "risk": "guarded",
            "approval_required": False,
        }
        return self.store

    def deliver_result(self, result, run_id="run-abc-123"):
        path = self.home / "results" / f"task-run-{run_id}.json"
        path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
        os.chmod(path, 0o600)
        return path

    def valid_result(self, **overrides):
        result = {
            "version": consumer.RESULT_VERSION,
            "executor": "opencode-executor-v1",
            "run_id": "run-abc-123",
            "task_id": "task-uuid-1",
            "task_public_id": 42,
            "repo": "casa-viva",
            "status": "completed",
            "error": None,
            "base_sha": "a" * 40,
            "changed_paths": ["docs/control-os-smoke/fase-c.md"],
            "diff": "--- a/docs/control-os-smoke/fase-c.md\n+++ b/docs/control-os-smoke/fase-c.md\n@@ -0,0 +1 @@\n+# ok\n",
            "logs": ["worktree base aaaaaaaaaaaa"],
            "finished_at": consumer.ts(self.now - 60),
            "ttl_seconds": consumer.RESULT_TTL_SECONDS,
            "expires_at": consumer.ts(self.now + 1800),
        }
        result.update(overrides)
        return result


class ValidationResultTests(unittest.TestCase):
    def setUp(self):
        self.now = 1_700_000_000.0
        self.result = {
            "version": consumer.RESULT_VERSION,
            "run_id": "run-abc-123",
            "task_id": "task-uuid-1",
            "repo": "casa-viva",
            "status": "completed",
            "error": None,
            "base_sha": "a" * 40,
            "changed_paths": ["docs/x.md"],
            "diff": "+x",
            "finished_at": consumer.ts(self.now - 60),
            "ttl_seconds": consumer.RESULT_TTL_SECONDS,
            "expires_at": consumer.ts(self.now + 1800),
        }

    def test_valid_passes_and_fills_defaults(self):
        self.assertEqual(consumer.validate_result(self.result, self.now)["run_id"], "run-abc-123")

    def test_rejects_version_status_and_repo(self):
        for override in ({"version": "opencode-result-v0"}, {"status": "merged"},
                         {"status": None}, {"repo": "vivabot"}):
            with self.assertRaises(consumer.ConsumerError):
                consumer.validate_result({**self.result, **override}, self.now)

    def test_rejects_unknown_keys_and_unsafe_ids(self):
        with self.assertRaises(consumer.ConsumerError):
            consumer.validate_result({**self.result, "env": {"KEY": "x"}}, self.now)
        for run_id in ("../evil", "a/b", "", None, "x" * 65):
            with self.assertRaises(consumer.ConsumerError):
                consumer.validate_result({**self.result, "run_id": run_id}, self.now)

    def test_rejects_bad_timestamps_and_ttl(self):
        for override in ({"finished_at": "garbage"}, {"expires_at": "garbage"},
                         {"expires_at": consumer.ts(self.now - 3600)},
                         {"expires_at": consumer.ts(self.now - 120)},
                         {"ttl_seconds": 0}, {"ttl_seconds": -1}, {"ttl_seconds": True}):
            with self.assertRaises(consumer.ConsumerError):
                consumer.validate_result({**self.result, **override}, self.now)

    def test_completed_requires_sha_paths_and_diff(self):
        for override in ({"base_sha": "zz"}, {"base_sha": None}, {"base_sha": "a" * 41},
                         {"changed_paths": []}, {"changed_paths": ["../x"]},
                         {"changed_paths": [".git/config"]}, {"diff": None}, {"diff": ""},
                         {"error": "algo"}):
            with self.assertRaises(consumer.ConsumerError):
                consumer.validate_result({**self.result, **override}, self.now)

    def test_failed_requires_error_and_no_residual_changes(self):
        failed = {**self.result, "status": "failed", "base_sha": None,
                  "changed_paths": [], "diff": None, "error": "fallo simulado"}
        self.assertEqual(consumer.validate_result(failed, self.now)["status"], "failed")
        for override in ({"error": None}, {"error": ""}, {"changed_paths": ["docs/x.md"]}, {"diff": "+x"}):
            with self.assertRaises(consumer.ConsumerError):
                consumer.validate_result({**failed, **override}, self.now)

    def test_too_many_changed_paths_rejected(self):
        with self.assertRaises(consumer.ConsumerError):
            consumer.validate_result(
                {**self.result, "changed_paths": [f"docs/f{i}.md" for i in range(consumer.MAX_CHANGED_PATHS + 1)]},
                self.now)

    def test_bad_logs_and_public_id_rejected(self):
        with self.assertRaises(consumer.ConsumerError):
            consumer.validate_result({**self.result, "logs": ["x" * 3000]}, self.now)
        with self.assertRaises(consumer.ConsumerError):
            consumer.validate_result({**self.result, "task_public_id": "42"}, self.now)


class ConsumerFlowTests(Fixture):
    def test_completed_result_sets_awaiting_approval(self):
        self.seed_run()
        self.deliver_result(self.valid_result())
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertEqual(summary["consumed"][0]["status"], "awaiting_approval")
        run = self.store.get_run("run-abc-123")
        self.assertEqual(run["status"], "awaiting_approval")
        self.assertEqual(run["base_sha"], "a" * 40)
        self.assertIn("docs/control-os-smoke/fase-c.md", run["changed_paths"])
        task = self.store.get_task("task-uuid-1")
        self.assertEqual(task["status"], "waiting_approval")
        self.assertTrue(task["approval_required"])
        self.assertIn("ai_result_validated", [e["event_type"] for e in self.store.events])
        self.assertIn("pending_approvals", summary)

    def test_failed_result_closes_run_and_releases_task(self):
        self.seed_run()
        self.deliver_result(self.valid_result(
            status="failed", error="text_replace falló", base_sha=None,
            changed_paths=[], diff=None))
        consumer.run_once(self.store, self.home, self.now)
        run = self.store.get_run("run-abc-123")
        self.assertEqual(run["status"], "failed")
        self.assertIn("text_replace", run["error"])
        task = self.store.get_task("task-uuid-1")
        self.assertEqual(task["status"], "failed")
        self.assertIn("ai_result_failed", [e["event_type"] for e in self.store.events])

    def test_reconciliation_detects_task_mismatch(self):
        self.seed_run(task_id="task-uuid-1")
        path = self.deliver_result(self.valid_result(task_id="task-otro-uuid"))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        self.assertIn(path.name, summary["dead"])
        self.assertTrue((self.home / "dead" / path.name).exists())
        self.assertTrue((self.home / "dead" / (path.name + ".motivo.txt")).exists())
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "waiting_ai")

    def test_reconciliation_detects_repo_and_public_id_mismatch(self):
        self.seed_run()
        path = self.deliver_result(self.valid_result(repo="nexo"))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        self.assertTrue((self.home / "dead" / path.name).exists())
        motivo = (self.home / "dead" / (path.name + ".motivo.txt")).read_text(encoding="utf-8")
        self.assertIn("repo", motivo)
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "waiting_ai")
        self.deliver_result(self.valid_result(task_public_id=99))
        consumer.run_once(self.store, self.home, self.now)
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "waiting_ai")

    def test_orphan_result_quarantined(self):
        self.deliver_result(self.valid_result())
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        self.assertTrue((self.home / "dead" / "task-run-run-abc-123.json").exists())

    def test_duplicate_result_never_overrides_approval(self):
        self.seed_run()
        self.deliver_result(self.valid_result())
        consumer.run_once(self.store, self.home, self.now)
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "awaiting_approval")
        # Un segundo paquete del mismo run llega de nuevo (anomalía).
        self.deliver_result(self.valid_result(diff="+cambio distinto"))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        run = self.store.get_run("run-abc-123")
        self.assertEqual(run["status"], "awaiting_approval")
        self.assertEqual(run["diff"], self.valid_result()["diff"])
        self.assertNotIn("+cambio distinto", run["diff"])
        motivo = (self.home / "dead" / "task-run-run-abc-123.json.motivo.txt").read_text(encoding="utf-8")
        self.assertIn("duplicado", motivo)

    def test_result_only_accepted_from_waiting_ai(self):
        self.seed_run(status="failed")
        self.deliver_result(self.valid_result())
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        motivo = (self.home / "dead" / "task-run-run-abc-123.json.motivo.txt").read_text(encoding="utf-8")
        self.assertIn("duplicado o fuera de estado", motivo)

    def test_expired_result_quarantined_not_consumed(self):
        self.seed_run()
        self.deliver_result(self.valid_result(expires_at=consumer.ts(self.now - 5)))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        motivo = (self.home / "dead" / "task-run-run-abc-123.json.motivo.txt").read_text(encoding="utf-8")
        self.assertIn("caducado", motivo)

    def test_corrupt_oversized_and_alterable_files_quarantined(self):
        self.seed_run()
        corrupt = self.home / "results" / "task-run-corrupto.json"
        corrupt.write_text("{no json", encoding="utf-8")
        big = self.home / "results" / "task-run-grande.json"
        big.write_text("x" * (consumer.MAX_RESULT_BYTES + 1), encoding="utf-8")
        world = self.deliver_result(self.valid_result(run_id="alterable"), run_id="alterable")
        os.chmod(world, 0o666)
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        for name in ("task-run-corrupto.json", "task-run-grande.json", "task-run-alterable.json"):
            self.assertTrue((self.home / "dead" / name).exists())
            self.assertTrue((self.home / "dead" / (name + ".motivo.txt")).exists())

    def test_invalid_result_names_quarantined(self):
        self.seed_run()
        weird = self.home / "results" / "task-run-..-evil.json"
        weird.write_text("{}", encoding="utf-8")
        other = self.home / "results" / "otra-cosa.json"
        other.write_text("{}", encoding="utf-8")
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertFalse(summary["consumed"])
        self.assertTrue((self.home / "dead" / weird.name).exists())
        self.assertTrue((self.home / "dead" / other.name).exists())

    def test_mailbox_dirs_required(self):
        with tempfile.TemporaryDirectory() as empty:
            with self.assertRaises(consumer.ConsumerError):
                consumer.ensure_mailbox_dirs(Path(empty))


class ExpiryTests(Fixture):
    def test_waiting_ai_runs_expire_after_ttl(self):
        self.seed_run(updated_at=consumer.ts(self.now - consumer.RESULT_TTL_SECONDS - 10))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertIn("run-abc-123", summary["expired_runs"])
        run = self.store.get_run("run-abc-123")
        self.assertEqual(run["status"], "failed")
        task = self.store.get_task("task-uuid-1")
        self.assertEqual(task["status"], "failed")
        self.assertIn("ai_job_expired", [e["event_type"] for e in self.store.events])

    def test_fresh_and_terminal_runs_not_expired(self):
        self.seed_run(updated_at=consumer.ts(self.now - 60))
        self.seed_run(run_id="run-done", task_id="task-done", status="awaiting_approval",
                      updated_at=consumer.ts(self.now - consumer.RESULT_TTL_SECONDS - 99))
        summary = consumer.run_once(self.store, self.home, self.now)
        self.assertEqual(summary["expired_runs"], [])
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "waiting_ai")


class ApprovalTests(Fixture):
    def prepare(self):
        self.seed_run()
        self.deliver_result(self.valid_result())
        consumer.run_once(self.store, self.home, self.now)

    def test_pending_approvals_lists_changes(self):
        self.prepare()
        pending = consumer.pending_approvals(self.store)
        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0]["run_id"], "run-abc-123")
        self.assertEqual(pending[0]["changed_paths"], ["docs/control-os-smoke/fase-c.md"])

    def test_approval_marks_and_records_event_without_integration(self):
        self.prepare()
        self.assertEqual(
            consumer.record_decision(self.store, "run-abc-123", "approve", "humana-test"),
            "approved")
        self.assertEqual(self.store.get_run("run-abc-123")["status"], "approved")
        task = self.store.get_task("task-uuid-1")
        self.assertEqual(task["status"], "approved")
        self.assertFalse(task["approval_required"])
        self.assertIn("ai_change_approved", [e["event_type"] for e in self.store.events])
        self.assertEqual(consumer.pending_approvals(self.store), [])

    def test_rejection_marks_and_keeps_reason(self):
        self.prepare()
        self.assertEqual(
            consumer.record_decision(self.store, "run-abc-123", "reject", "humana-test", "diff no aceptable"),
            "rejected")
        run = self.store.get_run("run-abc-123")
        self.assertEqual(run["status"], "rejected")
        self.assertIn("no aceptable", run["error"])
        self.assertIn("ai_change_rejected", [e["event_type"] for e in self.store.events])

    def test_decision_only_valid_on_awaiting_approval(self):
        self.seed_run(status="failed")
        self.assertEqual(
            consumer.record_decision(self.store, "run-abc-123", "approve", "humana-test"),
            "run no está pendiente de aprobación")
        self.assertEqual(
            consumer.record_decision(self.store, "run-no-existe", "approve", "humana-test"),
            "run no registrada")
        self.assertEqual(
            consumer.record_decision(self.store, "run-abc-123", "merge", "humana-test"),
            "decisión no permitida")

    def test_store_persists_roundtrip(self):
        self.prepare()
        self.store.save()
        again = consumer.SimStore(self.store_path)
        self.assertEqual(again.get_run("run-abc-123")["status"], "awaiting_approval")
        mode = stat.S_IMODE(self.store_path.stat().st_mode)
        self.assertEqual(oct(mode), oct(0o600))


class ConsumerSourceSecurityTests(unittest.TestCase):
    """Escaneo estático: sin red, sin subprocesos, sin rutas ni credenciales."""

    def setUp(self):
        self.source = CONSUMER_PATH.read_text(encoding="utf-8")
        self.tree = ast.parse(self.source)

    def test_no_network_or_process_imports(self):
        forbidden = {"urllib", "socket", "ssl", "http", "requests", "ftplib",
                     "smtplib", "paramiko", "asyncio", "subprocess", "shutil"}
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    root = alias.name.split(".")[0]
                    self.assertNotIn(root, forbidden, f"import prohibido: {alias.name}")
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden, f"import prohibido: {node.module}")

    def test_no_system_paths_or_credentials(self):
        text = self.source
        for needle in ("/root/", "/etc/", ".ssh", "SUPABASE", "SERVICE_KEY",
                       "SERVICE_ROLE", "apikey", "Authorization", "git "):
            self.assertNotIn(needle, text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
