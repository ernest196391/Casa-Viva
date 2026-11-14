#!/usr/bin/env python3
"""Pruebas de Fase A del Dev Worker (OpenCode × Control OS).

Cubre: paquetes de IA sin secretos (whitelist + redacción), caducidad
(TTL) del trabajo y de las runs waiting_ai, escritura en spool con
permisos estrictos y manejo de errores del claim IA.

Ejecutar: python3 scripts/ops/tests/dev-worker.test.py
"""
import importlib.util
import json
import os
import stat
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
WORKER_PATH = HERE.parent / "dev-worker.py"

_spec = importlib.util.spec_from_file_location("cv_dev_worker", WORKER_PATH)
worker = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(worker)


def valid_task(**overrides):
    task = {
        "id": "task-uuid-1",
        "public_id": 42,
        "title": "Smoke OpenCode",
        "risk": "guarded",
        "payload": {
            "dev": {
                "patch_format": "structured-v1",
                "allow_paths": ["docs/control-os-smoke/"],
                "operations": [
                    {"op": "create_file", "path": "docs/control-os-smoke/fase-a.md", "content": "# ok"}
                ],
            }
        },
    }
    task.update(overrides)
    return task


def valid_job(**overrides):
    return worker.build_ai_job(valid_task(), valid_claim(), **overrides)


def valid_claim(**overrides):
    claim = {"run_id": "run-abc-123", "repo": "casa-viva", "mode": "ai"}
    claim.update(overrides)
    return claim


class BuildAiJobTests(unittest.TestCase):
    def test_happy_path_builds_sanitized_job_with_ttl(self):
        job = worker.build_ai_job(valid_task(), valid_claim(), now=1_000_000)
        self.assertEqual(job["version"], worker.AI_JOB_VERSION)
        self.assertEqual(job["run_id"], "run-abc-123")
        self.assertEqual(job["repo"], "casa-viva")
        self.assertEqual(job["ttl_seconds"], worker.AI_JOB_TTL_SECONDS)
        created = worker.parse_ts(job["created_at"])
        expires = worker.parse_ts(job["expires_at"])
        self.assertEqual(expires - created, worker.AI_JOB_TTL_SECONDS)
        self.assertEqual(job["instructions"]["allow_paths"], ["docs/control-os-smoke"])
        self.assertEqual(job["instructions"]["operations"][0]["path"], "docs/control-os-smoke/fase-a.md")

    def test_unknown_packet_keys_are_dropped_and_listed(self):
        task = valid_task(payload={"dev": {
            "notes": "marcador interno",
            "env": {"SUPABASE_SERVICE_ROLE_KEY": "sbp_aaaaaaaaaaaaaaaaaaaa"},
            **valid_task()["payload"]["dev"],
        }})
        job = worker.build_ai_job(task, valid_claim())
        self.assertIn("env", job["ignored_packet_keys"])
        self.assertIn("notes", job["ignored_packet_keys"])
        dumped = json.dumps(job)
        self.assertNotIn("sbp_", dumped)
        self.assertNotIn("SUPABASE_SERVICE_ROLE_KEY=", dumped)

    def test_metadata_secrets_are_redacted(self):
        task = valid_task(title="JWT eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0 leaked")
        job = worker.build_ai_job(task, valid_claim())
        self.assertNotIn("eyJ", job["title"])

    def test_requires_allow_paths_and_instructions(self):
        task = valid_task(payload={"dev": {"patch_format": "structured-v1", "operations": valid_task()["payload"]["dev"]["operations"]}})
        with self.assertRaises(ValueError):
            worker.build_ai_job(task, valid_claim())
        task2 = valid_task(payload={"dev": {"allow_paths": ["docs/"]}})
        with self.assertRaises(ValueError):
            worker.build_ai_job(task2, valid_claim())

    def test_rejects_bad_paths_and_oversized_operations(self):
        task = valid_task(payload={"dev": {
            "allow_paths": ["../evil"],
            "operations": valid_task()["payload"]["dev"]["operations"],
        }})
        with self.assertRaises(ValueError):
            worker.build_ai_job(task, valid_claim())
        nine = [{"op": "create_file", "path": "docs/control-os-smoke/a.md", "content": "x"}] * 9
        task2 = valid_task(payload={"dev": {
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": nine,
        }})
        with self.assertRaises(ValueError):
            worker.build_ai_job(task2, valid_claim())

    def test_deterministic_packet_is_whitelisted(self):
        spec = {"operation": "write_smoke_file", "path": "docs/control-os-smoke/a.md", "content": "hola", "extra": "x"}
        task = valid_task(payload={"dev": {"allow_paths": ["docs/control-os-smoke/"], "deterministic": spec}})
        job = worker.build_ai_job(task, valid_claim())
        self.assertEqual(job["instructions"]["deterministic"], {"operation": "write_smoke_file", "path": "docs/control-os-smoke/a.md", "content": "hola"})


class RedactionTests(unittest.TestCase):
    def test_secret_field_names_are_redacted(self):
        out = worker.redact_value({"nested": {"api_key": "abc", "keep": 1}})
        self.assertEqual(out, {"nested": {"api_key": "[REDACTED]", "keep": 1}})

    def test_credential_looking_values_are_redacted(self):
        out = worker.redact_value(["Bearer aaaaaaaaaaaaaaaaaaaaaaaa", "sk-abcdefghijklmnopqrstuvwxyz", "ok"])
        self.assertEqual(out, ["[REDACTED]", "[REDACTED]", "ok"])

    def test_code_fields_travel_verbatim(self):
        code = "Authorization: Bearer x  # literal del código"
        out = worker.redact_value({"operations": [{"op": "text_replace", "old": code, "new": code}]})
        self.assertEqual(out["operations"][0]["old"], code)
        self.assertEqual(out["operations"][0]["new"], code)

    def test_is_secret_field(self):
        self.assertTrue(worker.SECRET_FIELD_RE.search("SUPABASE_SERVICE_ROLE_KEY"))
        self.assertFalse(worker.SECRET_FIELD_RE.search("allow_paths"))


class SpoolTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.spool = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_write_ai_job_mailbox_permissions_and_roundtrip(self):
        job = worker.build_ai_job(valid_task(), valid_claim())
        path = worker.write_ai_job(job, self.spool)
        self.assertTrue(path.exists())
        # El buzón pertenece a cvdev: el archivo debe poder leerlo cvdev (0644).
        mode = stat.S_IMODE(os.stat(path).st_mode)
        self.assertEqual(oct(mode), oct(0o644))
        loaded = json.loads(path.read_text(encoding="utf-8"))
        self.assertEqual(loaded["run_id"], job["run_id"])

    def test_write_ai_job_atomic_no_tmp_leftovers(self):
        job = worker.build_ai_job(valid_task(), valid_claim())
        worker.write_ai_job(job, self.spool)
        self.assertEqual([p.name for p in self.spool.iterdir()], [f"task-run-{job['run_id']}.json"])

    def test_write_ai_job_requires_provisioned_mailbox(self):
        missing = self.spool / "no-existe"
        with self.assertRaises(RuntimeError):
            worker.write_ai_job(valid_job(), missing)
        self.assertFalse(missing.exists())

    def test_write_ai_job_rejects_group_writable_mailbox(self):
        insecure = self.spool / "inseguro"
        insecure.mkdir()
        os.chmod(insecure, 0o777)
        try:
            with self.assertRaises(RuntimeError):
                worker.write_ai_job(valid_job(), insecure)
        finally:
            os.chmod(insecure, 0o700)

    def test_clean_expired_ai_jobs_removes_expired_keeps_valid(self):
        fresh = worker.build_ai_job(valid_task(), valid_claim(run_id="fresh"))
        stale = worker.build_ai_job(valid_task(), valid_claim(run_id="stale"), now=0)
        worker.write_ai_job(fresh, self.spool)
        worker.write_ai_job(stale, self.spool)
        now = worker.AI_JOB_TTL_SECONDS + 10
        removed = worker.clean_expired_ai_jobs(self.spool, now=now)
        self.assertIn(worker.ai_job_path("stale", self.spool).name, removed)
        self.assertFalse(worker.ai_job_path("stale", self.spool).exists())
        self.assertTrue(worker.ai_job_path("fresh", self.spool).exists())

    def test_corrupt_job_file_is_removed(self):
        corrupt = self.spool / "task-run-corrupt.json"
        corrupt.write_text("{no es json", encoding="utf-8")
        removed = worker.clean_expired_ai_jobs(self.spool, now=time.time())
        self.assertIn(corrupt.name, removed)
        self.assertFalse(corrupt.exists())

    def test_purge_ai_job(self):
        job = worker.build_ai_job(valid_task(), valid_claim())
        path = worker.write_ai_job(job, self.spool)
        self.assertTrue(worker.purge_ai_job(job["run_id"], self.spool))
        self.assertFalse(path.exists())
        self.assertFalse(worker.purge_ai_job(job["run_id"], self.spool))


class ExpiryTests(unittest.TestCase):
    def test_parse_ts_formats(self):
        epoch = worker.parse_ts("2026-11-14T10:00:00Z")
        self.assertEqual(epoch, worker.parse_ts("2026-11-14T10:00:00+00:00"))
        self.assertEqual(epoch, worker.parse_ts("2026-11-14T10:00:00"))
        self.assertIsNone(worker.parse_ts("no es fecha"))
        self.assertIsNone(worker.parse_ts(None))

    def test_ai_run_expired_boundaries(self):
        now = 1_000_000.0
        fresh = {"updated_at": time.strftime(worker.TS_FMT, time.gmtime(now - 60))}
        self.assertFalse(worker.ai_run_expired(fresh, now=now))
        old = {"updated_at": time.strftime(worker.TS_FMT, time.gmtime(now - worker.AI_JOB_TTL_SECONDS - 1))}
        self.assertTrue(worker.ai_run_expired(old, now=now))
        exactly = {"updated_at": time.strftime(worker.TS_FMT, time.gmtime(now - worker.AI_JOB_TTL_SECONDS))}
        self.assertTrue(worker.ai_run_expired(exactly, now=now))

    def test_rows_without_timestamp_never_expire(self):
        self.assertFalse(worker.ai_run_expired({}, now=1_000_000))
        self.assertFalse(worker.ai_run_expired({"updated_at": "garbage"}, now=1_000_000))


class HandleAiClaimTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.spool = Path(self.tmp.name)
        self.patches = []
        self.events = []

    def tearDown(self):
        self.tmp.cleanup()

    def _enter(self):
        patcher_table = mock.patch.object(worker, "patch_table", side_effect=lambda table, rid, values: self.patches.append((table, rid, values)))
        patcher_event = mock.patch.object(worker, "event", side_effect=lambda task, etype, detail: self.events.append((etype, detail)))
        patcher_spool = mock.patch.object(worker, "AI_MAILBOX", self.spool)
        return patcher_table, patcher_event, patcher_spool

    def test_success_marks_waiting_ai_and_writes_job(self):
        p1, p2, p3 = self._enter()
        with p1, p2, p3:
            worker.handle_ai_claim(valid_task(), valid_claim(), "run-abc-123")
        run_patch = next(v for t, _i, v in self.patches if t == "ops_dev_runs")
        task_patch = next(v for t, _i, v in self.patches if t == "ops_tasks")
        self.assertEqual(run_patch["status"], "waiting_ai")
        self.assertIn("expira", run_patch["summary"])
        self.assertEqual(task_patch["status"], "waiting_ai")
        self.assertEqual(self.events[0][0], "ai_job_prepared")
        files = list(self.spool.glob("*.json"))
        self.assertEqual(len(files), 1)
        self.assertNotIn("SERVICE", files[0].read_text(encoding="utf-8"))

    def test_failure_marks_run_and_task_failed(self):
        p1, p2, _p3 = self._enter()
        task = valid_task(payload={"dev": {"allow_paths": []}})
        with p1, p2:
            worker.handle_ai_claim(task, valid_claim(), "run-abc-123")
        statuses = {t: v["status"] for t, _i, v in self.patches if t in ("ops_dev_runs", "ops_tasks")}
        self.assertEqual(statuses, {"ops_dev_runs": "failed", "ops_tasks": "failed"})
        self.assertEqual(self.events[0][0], "ai_job_failed")
        self.assertEqual(list(self.spool.glob("*.json")), [])

    def test_write_error_is_contained(self):
        p1, p2, _p3 = self._enter()
        with p1, p2, mock.patch.object(worker, "write_ai_job", side_effect=OSError("disco lleno")):
            worker.handle_ai_claim(valid_task(), valid_claim(), "run-abc-123")
        statuses = {t: v["status"] for t, _i, v in self.patches if t in ("ops_dev_runs", "ops_tasks")}
        self.assertEqual(statuses, {"ops_dev_runs": "failed", "ops_tasks": "failed"})
        self.assertEqual(self.events[0][0], "ai_job_failed")


class PathSafetyTests(unittest.TestCase):
    def test_safe_rel_path_rejects_traversal_and_git(self):
        for bad in ("/abs", "../evil", "a/../b", ".git/config", "sub/.git/config", "x" * 300):
            with self.assertRaises(ValueError):
                worker.safe_rel_path(bad)
        self.assertEqual(worker.safe_rel_path("docs/a.md"), "docs/a.md")

    def test_path_allowed_prefixes(self):
        self.assertTrue(worker.path_allowed("docs/control-os-smoke/a.md", ["docs/control-os-smoke/"]))
        self.assertFalse(worker.path_allowed("docs/other/a.md", ["docs/control-os-smoke/"]))

    def test_slug(self):
        self.assertEqual(worker.slug("Título con Espacios!"), "t-tulo-con-espacios")


class StructuredEditTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.work = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_create_file_within_allow_paths(self):
        packet = {
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [{"op": "create_file", "path": "docs/control-os-smoke/fase-a.md", "content": "# ok"}],
        }
        changed = worker.structured_edit(self.work, packet)
        self.assertEqual(changed, ["docs/control-os-smoke/fase-a.md"])
        self.assertEqual((self.work / "docs/control-os-smoke/fase-a.md").read_text(encoding="utf-8"), "# ok")

    def test_operations_outside_allow_paths_rejected(self):
        packet = {
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [{"op": "create_file", "path": "src/evil.ts", "content": "x"}],
        }
        with self.assertRaises(ValueError):
            worker.structured_edit(self.work, packet)

    def test_text_replace_count_mismatch_rejected(self):
        target = self.work / "docs/control-os-smoke/a.md"
        target.parent.mkdir(parents=True)
        target.write_text("uno uno", encoding="utf-8")
        packet = {
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [{"op": "text_replace", "path": "docs/control-os-smoke/a.md", "old": "uno", "new": "dos", "expected_count": 1}],
        }
        with self.assertRaises(ValueError):
            worker.structured_edit(self.work, packet)
        self.assertEqual(target.read_text(encoding="utf-8"), "uno uno")

    def test_create_file_refuses_existing_target(self):
        target = self.work / "docs/a.md"
        target.parent.mkdir(parents=True)
        target.write_text("original", encoding="utf-8")
        packet = {
            "allow_paths": ["docs/"],
            "operations": [{"op": "create_file", "path": "docs/a.md", "content": "x"}],
        }
        with self.assertRaises(ValueError):
            worker.structured_edit(self.work, packet)
        self.assertEqual(target.read_text(encoding="utf-8"), "original")


if __name__ == "__main__":
    unittest.main(verbosity=2)
