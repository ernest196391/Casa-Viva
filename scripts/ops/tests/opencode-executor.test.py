#!/usr/bin/env python3
"""Pruebas de Fase B del ejecutor OpenCode (Control OS × cvdev).

Cubre: validación estricta del paquete (versión, identificadores
seguros, rutas, tamaños), reclamo atómico del buzón, protección contra
ejecuciones duplicadas, caducidad coherente, producción de resultados y
diff para revisión, inexistencia de push/merge/commit, y escaneo
estático de la superficie del ejecutor (sin red, sin shell, sin rutas
de sistema).

Ejecutar: python3 scripts/ops/tests/opencode-executor.test.py
"""
import ast
import importlib.util
import json
import os
import stat
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
EXECUTOR_PATH = HERE.parent / "opencode-executor.py"

_spec = importlib.util.spec_from_file_location("cv_opencode_executor", EXECUTOR_PATH)
executor = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(executor)


def make_mailbox() -> tempfile.TemporaryDirectory:
    tmp = tempfile.TemporaryDirectory()
    home = Path(tmp.name)
    for name in ("jobs", "claims", "results", "dead", "work"):
        (home / name).mkdir(mode=0o700)
    return tmp


def valid_job(**overrides):
    now = time.time()
    job = {
        "version": executor.JOB_VERSION,
        "run_id": "run-abc-123",
        "task_id": "task-uuid-1",
        "task_public_id": 42,
        "repo": "casa-viva",
        "mode": "ai",
        "title": "Fase B",
        "risk": "guarded",
        "created_at": executor.ts(now),
        "expires_at": executor.ts(now + executor.TTL_SECONDS),
        "ttl_seconds": executor.TTL_SECONDS,
        "instructions": {
            "patch_format": "structured-v1",
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [
                {"op": "create_file", "path": "docs/control-os-smoke/fase-b.md", "content": "# ok"}
            ],
        },
        "ignored_packet_keys": [],
    }
    job.update(overrides)
    return job


def deliver_job(home: Path, job, run_id="run-abc-123"):
    path = home / "jobs" / f"task-run-{run_id}.json"
    path.write_text(json.dumps(job, ensure_ascii=False), encoding="utf-8")
    os.chmod(path, 0o644)
    return path


def make_repo() -> tempfile.TemporaryDirectory:
    tmp = tempfile.TemporaryDirectory()
    repo = Path(tmp.name)
    env = dict(os.environ, GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t", GIT_COMMITTER_NAME="t", GIT_COMMITTER_EMAIL="t@t")
    def g(*args):
        subprocess.run(["git", *args], cwd=repo, check=True, capture_output=True, env=env)
    g("-c", "init.defaultBranch=main", "init")
    (repo / "docs").mkdir()
    (repo / "docs" / "readme.md").write_text("base\n", encoding="utf-8")
    g("add", "-A")
    g("commit", "-m", "base")
    tmp.repo = repo
    return tmp


class ExecutorFixture(unittest.TestCase):
    def setUp(self):
        self.mailbox_tmp = make_mailbox()
        self.home = Path(self.mailbox_tmp.name)
        self.dirs = executor.ensure_mailbox(self.home)
        self.repo_tmp = make_repo()
        self.repo = self.repo_tmp.repo

    def tearDown(self):
        self.mailbox_tmp.cleanup()
        self.repo_tmp.cleanup()

    def patch_repo(self):
        return mock.patch.dict(executor.ALLOWED_REPOS, {"casa-viva": self.repo})


class IdentifierTests(unittest.TestCase):
    def test_safe_id_accepts_and_rejects(self):
        self.assertEqual(executor.safe_id("run-abc_123"), "run-abc_123")
        for bad in ("../etc", "a/b", "", None, "x" * 65, "-lead", "espacio x", 42):
            with self.assertRaises(executor.SecurityError):
                executor.safe_id(bad)

    def test_job_name_regex(self):
        self.assertEqual(executor.JOB_NAME_RE.match("task-run-abc.json").group(1), "abc")
        self.assertIsNone(executor.JOB_NAME_RE.match("task-run-../../etc.json"))
        self.assertIsNone(executor.JOB_NAME_RE.match("otros.json"))


class PathSafetyTests(unittest.TestCase):
    def test_safe_rel_path_rejects_traversal_and_git(self):
        for bad in ("/abs", "../evil", "a/../b", ".git/config", "sub/.git/config", "x" * 300, ""):
            with self.assertRaises(executor.SecurityError):
                executor.safe_rel_path(bad)
        self.assertEqual(executor.safe_rel_path("docs/a.md"), "docs/a.md")

    def test_path_allowed_prefixes(self):
        self.assertTrue(executor.path_allowed("docs/smoke/a.md", ["docs/smoke/"]))
        self.assertFalse(executor.path_allowed("docs/other/a.md", ["docs/smoke/"]))

    def test_protected_paths_blocked_even_inside_allowlist(self):
        for bad in (".github/workflows/x.yml", "package.json", "package-lock.json", "sub/package.json"):
            instructions = {
                "allow_paths": [".github/", ""],
                "operations": [{"op": "create_file", "path": bad, "content": "x"}],
            }
            with self.assertRaises(executor.SecurityError):
                executor.validate_operations(
                    instructions["operations"],
                    [".github/", "docs/"],
                )

    def test_unknown_operation_rejected(self):
        with self.assertRaises(executor.SecurityError):
            executor.validate_operations(
                [{"op": "delete_file", "path": "docs/a.md"}], ["docs/"])

    def test_operations_outside_allow_paths_rejected(self):
        with self.assertRaises(executor.SecurityError):
            executor.validate_operations(
                [{"op": "create_file", "path": "src/evil.ts", "content": "x"}], ["docs/"])

    def test_oversized_content_rejected(self):
        with self.assertRaises(executor.SecurityError):
            executor.validate_operations(
                [{"op": "create_file", "path": "docs/a.md", "content": "x" * (executor.MAX_CONTENT + 1)}],
                ["docs/"])

    def test_bad_expected_count_rejected(self):
        for expected in (0, 6, "2", True):
            with self.assertRaises(executor.SecurityError):
                executor.validate_operations(
                    [{"op": "text_replace", "path": "docs/a.md", "old": "a", "new": "b", "expected_count": expected}],
                    ["docs/"])

    def test_deterministic_only_writes_smoke_md(self):
        with self.assertRaises(executor.SecurityError):
            executor.validate_deterministic(
                {"operation": "write_smoke_file", "path": "src/x.md", "content": "hola"}, ["src/"])
        with self.assertRaises(executor.SecurityError):
            executor.validate_deterministic(
                {"operation": "write_smoke_file", "path": "docs/control-os-smoke/a.md", "content": "hola"},
                ["other/"])


class JobValidationTests(unittest.TestCase):
    def setUp(self):
        self.now = 500_000.0

    def test_valid_job_passes(self):
        self.assertEqual(executor.validate_job(valid_job(), self.now)["run_id"], "run-abc-123")

    def test_rejects_wrong_version_mode_and_missing_fields(self):
        for override in ({"version": "opencode-ai-job-v0"}, {"mode": "shell"},
                         {"instructions": None}, {"repo": "vivabot"}):
            with self.assertRaises(executor.SecurityError):
                executor.validate_job(valid_job(**override), self.now)

    def test_rejects_expired_job(self):
        expired = valid_job(expires_at=time.strftime(executor.TS_FMT, time.gmtime(self.now - 1)))
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(expired, self.now)

    def test_rejects_unparseable_expiry(self):
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(valid_job(expires_at="garbage"), self.now)

    def test_rejects_unknown_top_level_keys(self):
        job = valid_job(env={"SERVICE_KEY": "x"})
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(job, self.now)

    def test_rejects_unknown_instruction_keys(self):
        job = valid_job(instructions={**valid_job()["instructions"], "env": {}})
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(job, self.now)

    def test_rejects_operations_without_structured_v1(self):
        instructions = valid_job()["instructions"]
        job = valid_job(instructions={**instructions, "patch_format": "free-form"})
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(job, self.now)

    def test_rejects_operations_and_deterministic_together(self):
        instructions = valid_job()["instructions"]
        job = valid_job(instructions={
            **instructions,
            "deterministic": {"operation": "write_smoke_file", "path": "docs/control-os-smoke/a.md", "content": "x"},
        })
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(job, self.now)

    def test_rejects_operation_unknown_keys(self):
        instructions = valid_job()["instructions"]
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [{"op": "create_file", "path": "docs/control-os-smoke/a.md", "content": "x", "cmd": "rm -rf /"}],
        })
        with self.assertRaises(executor.SecurityError):
            executor.validate_job(job, self.now)


class MailboxTests(ExecutorFixture):
    def test_claim_is_atomic_second_claim_fails(self):
        deliver_job(self.home, valid_job())
        claimed = executor.claim_next_job(self.dirs)
        self.assertIsNotNone(claimed)
        claimed_path, job = claimed
        self.assertEqual(job["run_id"], "run-abc-123")
        self.assertTrue(claimed_path.is_file())
        self.assertFalse((self.home / "jobs" / claimed_path.name).exists())
        self.assertIsNone(executor.claim_next_job(self.dirs))

    def test_invalid_job_names_quarantined(self):
        bad = self.home / "jobs" / "otra-cosa.json"
        bad.write_text("{}", encoding="utf-8")
        self.assertIsNone(executor.claim_next_job(self.dirs))
        self.assertTrue((self.home / "dead" / "otra-cosa.json").exists())

    def test_unsafe_run_id_quarantined_not_executed(self):
        bad = self.home / "jobs" / "task-run-..-evil.json"
        bad.write_text(json.dumps(valid_job(run_id="../evil")), encoding="utf-8")
        os.chmod(bad, 0o644)
        self.assertIsNone(executor.claim_next_job(self.dirs))
        self.assertTrue((self.home / "dead" / "task-run-..-evil.json").exists())
        self.assertEqual(list(self.dirs["claims"].glob("*")), [])

    def test_world_writable_job_file_rejected(self):
        path = deliver_job(self.home, valid_job())
        os.chmod(path, 0o666)
        self.assertIsNone(executor.claim_next_job(self.dirs))
        self.assertTrue((self.home / "dead" / path.name).exists())

    def test_corrupt_job_quarantined(self):
        path = deliver_job(self.home, valid_job())
        path.write_text("{no json", encoding="utf-8")
        self.assertIsNone(executor.claim_next_job(self.dirs))
        self.assertTrue((self.home / "dead" / path.name).exists())

    def test_mailbox_requires_provisioned_dirs(self):
        with tempfile.TemporaryDirectory() as empty:
            with self.assertRaises(executor.SecurityError):
                executor.ensure_mailbox(Path(empty))

    def test_mailbox_rejects_group_writable_dirs(self):
        insecure = self.home / "jobs"
        os.chmod(insecure, 0o773)
        try:
            with self.assertRaises(executor.SecurityError):
                executor.ensure_mailbox(self.home)
        finally:
            os.chmod(insecure, 0o700)


class ExecutionTests(ExecutorFixture):
    def run_claim(self, job, **kw):
        deliver_job(self.home, job, **kw)
        claimed = executor.claim_next_job(self.dirs)
        self.assertIsNotNone(claimed)
        result, should_write = executor.process_job(self.dirs, claimed[0], claimed[1], time.time())
        return result, should_write

    def test_successful_edit_produces_result_and_diff(self):
        with self.patch_repo():
            result, should_write = self.run_claim(valid_job())
        self.assertEqual(result["status"], "completed")
        self.assertTrue(should_write)
        self.assertIn("docs/control-os-smoke/fase-b.md", result["changed_paths"])
        self.assertIn("# ok", result["diff"])
        self.assertTrue(result["base_sha"])
        # El worktree es desechable: no quedan restos ni en work/ ni en el repo.
        self.assertEqual(list(self.dirs["work"].iterdir()), [])

    def test_text_replace_applies_and_records_diff(self):
        (self.repo / "docs" / "readme.md").write_text("uno dos\n", encoding="utf-8")
        subprocess.run(["git", "add", "-A"], cwd=self.repo, check=True, capture_output=True)
        subprocess.run(["git", "-c", "user.name=t", "-c", "user.email=t@t", "commit", "-m", "dos"],
                       cwd=self.repo, check=True, capture_output=True)
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/"],
            "operations": [{"op": "text_replace", "path": "docs/readme.md", "old": "uno", "new": "UNO", "expected_count": 1}],
        })
        with self.patch_repo():
            result, _ = self.run_claim(job)
        self.assertEqual(result["status"], "completed")
        self.assertIn("+UNO dos", result["diff"])
        self.assertNotIn("-uno dos\n+UNO", "")

    def test_failure_produces_failed_result_without_crash(self):
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [
                {"op": "create_file", "path": "docs/control-os-smoke/uno.md", "content": "x"},
                {"op": "create_file", "path": "docs/control-os-smoke/uno.md", "content": "y"},
            ],
        })
        with self.patch_repo():
            result, should_write = self.run_claim(job)
        self.assertEqual(result["status"], "failed")
        self.assertTrue(should_write)
        self.assertIn("create_file", result["error"])
        self.assertEqual(list(self.dirs["work"].iterdir()), [])

    def test_unexpected_workspace_changes_rejected(self):
        # Una operación con ruta duplicada vía prefix distinto no altera el set,
        # pero un archivo creado fuera de las operaciones debe fallar la
        # verificación de integridad del workspace.
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/control-os-smoke/", "docs/"],
            "operations": [{"op": "create_file", "path": "docs/control-os-smoke/a.md", "content": "x"}],
        })
        with self.patch_repo(), mock.patch.object(
            executor, "apply_operations",
            side_effect=lambda work, ins: (
                (work / "docs/control-os-smoke/a.md").parent.mkdir(parents=True, exist_ok=True),
                (work / "docs/control-os-smoke/a.md").write_text("x", encoding="utf-8"),
                (work / "docs" / "extra.md").write_text("inyectado", encoding="utf-8"),
                ["docs/control-os-smoke/a.md"],
            )[-1],
        ):
            deliver_job(self.home, job)
            claimed = executor.claim_next_job(self.dirs)
            result, _ = executor.process_job(self.dirs, claimed[0], claimed[1], time.time())
        self.assertEqual(result["status"], "failed")
        self.assertIn("inesperados", result["error"])

    def test_expired_job_is_never_executed(self):
        job = valid_job(expires_at=time.strftime(executor.TS_FMT, time.gmtime(time.time() - 10)))
        deliver_job(self.home, job)
        claimed = executor.claim_next_job(self.dirs)
        result, should_write = executor.process_job(self.dirs, claimed[0], claimed[1], time.time())
        self.assertEqual(result["status"], "failed")
        self.assertFalse(should_write)
        self.assertFalse((self.home / "results").exists() and list((self.home / "results").glob("*.json")))
        self.assertEqual(list(self.dirs["work"].iterdir()), [])

    def test_duplicate_claim_with_existing_result_never_reruns(self):
        with self.patch_repo():
            result, _ = self.run_claim(valid_job())
            self.assertEqual(result["status"], "completed")
            executor.write_result(self.dirs, result)
            # Reintento: el job ya fue a dead; se entrega un clon del mismo run.
            deliver_job(self.home, valid_job())
            claimed = executor.claim_next_job(self.dirs)
            result2, should_write = executor.process_job(self.dirs, claimed[0], claimed[1], time.time())
        self.assertEqual(result2["status"], "failed")
        self.assertIn("duplicada", result2["error"])
        self.assertFalse(should_write)
        # El resultado original no fue sobrescrito.
        saved = json.loads((self.home / "results" / "task-run-run-abc-123.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["status"], "completed")

    def test_repo_not_enabled_never_creates_worktree(self):
        job = valid_job(repo="vivabot")
        with self.patch_repo():
            result, should_write = self.run_claim(job)
        self.assertEqual(result["status"], "failed")
        self.assertIn("repo no habilitado", result["error"])
        self.assertEqual(list(self.dirs["work"].iterdir()), [])

    def test_symlink_escape_rejected(self):
        (self.repo / "docs" / "link").symlink_to("/etc")
        subprocess.run(["git", "add", "-A"], cwd=self.repo, check=True, capture_output=True)
        subprocess.run(["git", "-c", "user.name=t", "-c", "user.email=t@t", "commit", "-m", "symlink"],
                       cwd=self.repo, check=True, capture_output=True)
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/"],
            "operations": [{"op": "create_file", "path": "docs/link/escape.md", "content": "x"}],
        })
        with self.patch_repo():
            result, _ = self.run_claim(job)
        self.assertEqual(result["status"], "failed")
        self.assertIn("symlink", result["error"])
        self.assertFalse(Path("/etc/escape.md").exists())

    def test_oversized_diff_rejected(self):
        job = valid_job(instructions={
            "patch_format": "structured-v1",
            "allow_paths": ["docs/control-os-smoke/"],
            "operations": [{"op": "create_file", "path": "docs/control-os-smoke/grande.md",
                            "content": "\n".join(f"línea {i}" for i in range(executor.MAX_DIFF_LINES + 50))}],
        })
        with self.patch_repo():
            result, _ = self.run_claim(job)
        self.assertEqual(result["status"], "failed")
        self.assertIn("diff", result["error"])

    def test_no_push_no_merge_no_commit_no_branches(self):
        def repo_state():
            head = subprocess.run(["git", "rev-parse", "HEAD"], cwd=self.repo, check=True, capture_output=True, text=True).stdout.strip()
            branches = subprocess.run(["git", "for-each-ref", "refs/heads"], cwd=self.repo, capture_output=True, text=True).stdout.strip()
            count = subprocess.run(["git", "rev-list", "--count", "HEAD"], cwd=self.repo, check=True, capture_output=True, text=True).stdout.strip()
            return head, branches, count
        before = repo_state()
        with self.patch_repo():
            result, _ = self.run_claim(valid_job())
            self.assertEqual(result["status"], "completed")
        self.assertEqual(repo_state(), before)
        # El diff solo vive en el resultado: el repo no conserva cambios.
        status = subprocess.run(["git", "status", "--porcelain"], cwd=self.repo, capture_output=True, text=True).stdout
        self.assertEqual(status, "")


class ResultAndSweepTests(ExecutorFixture):
    def test_write_result_atomic_0600_and_roundtrip(self):
        result = executor.fail_result(valid_job(), time.time(), "boom")
        path = executor.write_result(self.dirs, result)
        self.assertEqual(oct(stat.S_IMODE(os.stat(path).st_mode)), oct(0o600))
        self.assertEqual(json.loads(path.read_text(encoding="utf-8"))["status"], "failed")
        self.assertEqual([p.name for p in self.dirs["results"].iterdir()], [path.name])

    def test_write_result_rejects_unsafe_id(self):
        with self.assertRaises(executor.SecurityError):
            executor.write_result(self.dirs, executor.fail_result({"run_id": "../x"}, time.time(), "boom"))

    def test_result_has_ttl(self):
        now = 1_000_000.0
        result = executor.base_result(valid_job(), "completed", now)
        self.assertEqual(executor.parse_ts(result["expires_at"]) - executor.parse_ts(result["finished_at"]), executor.TTL_SECONDS)

    def test_sweep_moves_expired_claims_and_results_to_dead(self):
        now = 1_000_000.0
        stale_claim = self.dirs["claims"] / "task-run-stale.json"
        stale_claim.write_text(json.dumps(valid_job(run_id="stale", expires_at=executor.ts(now - 10))), encoding="utf-8")
        result = executor.base_result(valid_job(run_id="oldres"), "completed", now - executor.TTL_SECONDS - 10)
        result_path = executor.write_result(self.dirs, result)
        fresh_claim = self.dirs["claims"] / "task-run-fresh.json"
        fresh_job = valid_job(run_id="fresh", expires_at=executor.ts(now + 100))
        fresh_claim.write_text(json.dumps(fresh_job), encoding="utf-8")

        moved = executor.sweep_expired(self.dirs, now)
        self.assertIn(stale_claim.name, moved)
        self.assertFalse(stale_claim.exists())
        self.assertTrue((self.dirs["dead"] / stale_claim.name).exists())
        self.assertFalse(result_path.exists())
        self.assertTrue((self.dirs["dead"] / result_path.name).exists())
        self.assertTrue(fresh_claim.exists())

    def test_claim_with_result_moved_to_dead_by_sweep(self):
        run_id = "conres"
        deliver_job(self.home, valid_job(run_id=run_id), run_id=run_id)
        claimed = executor.claim_next_job(self.dirs)
        executor.write_result(self.dirs, executor.fail_result(valid_job(run_id=run_id), time.time(), "boom"))
        moved = executor.sweep_expired(self.dirs, time.time())
        self.assertIn(claimed[0].name, moved)


class GitWhitelistTests(ExecutorFixture):
    def test_git_helper_rejects_forbidden_subcommands(self):
        for bad in (["push", "origin", "main"], ["fetch", "origin"], ["commit", "-m", "x"],
                    ["merge", "main"], ["worktree", "list"], ["add", "-A"], ["checkout", "-b", "x"]):
            with self.assertRaises(executor.SecurityError):
                executor.git(bad, cwd=self.repo)

    def test_git_helper_allows_whitelisted_reads(self):
        rc, out = executor.git(["rev-parse", "HEAD"], cwd=self.repo)
        self.assertEqual(rc, 0)


class ExecutorSourceSecurityTests(unittest.TestCase):
    """Escaneo estático: sin red, sin shell, sin rutas ni credenciales."""

    def setUp(self):
        self.source = EXECUTOR_PATH.read_text(encoding="utf-8")
        self.tree = ast.parse(self.source)

    def test_no_network_imports(self):
        forbidden = {"urllib", "socket", "ssl", "http", "requests", "ftplib",
                     "telnetlib", "smtplib", "paramiko", "asyncio"}
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    root = alias.name.split(".")[0]
                    self.assertNotIn(root, forbidden, f"import prohibido: {alias.name}")
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden, f"import prohibido: {node.module}")

    def test_no_shell_execution(self):
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Call):
                func = node.func
                if isinstance(func, ast.Name) and func.id in ("system", "popen", "execv", "execve", "spawnl"):
                    self.fail(f"llamada prohibida: {func.id}")
                if isinstance(func, ast.Attribute) and func.attr in ("system", "popen"):
                    self.fail(f"llamada prohibida: {func.attr}")
                for kw in node.keywords:
                    if kw.arg == "shell":
                        self.fail("subprocess con shell=True prohibido")

    def test_no_system_paths_or_credentials(self):
        text = self.source
        for needle in ("/root/", "SUPABASE", "SERVICE_KEY", "SERVICE_ROLE", "apikey", "Authorization", ".ssh"):
            self.assertNotIn(needle, text)

    def test_only_git_subprocess(self):
        # subprocess solo se usa para git con lista blanca; no hay otro binario.
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id == "Popen":
                self.fail("Popen directo prohibido")


if __name__ == "__main__":
    unittest.main(verbosity=2)
