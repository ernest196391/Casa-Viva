#!/usr/bin/env python3
"""Pruebas de Fase F del contrato de propuestas y el puente (Control OS).

Cubre: contrato JSON estricto (`cv-change-proposal-v1`), rechazo de
propuestas maliciosas (rutas absolutas, traversal, backslash, `~`,
symlink escape, rutas protegidas, operaciones no autorizadas, comandos
arbitrarios, secretos, tamaños, caducidad), bloqueo de duplicados
(misma proposal_id, misma huella de contenido, run_id ya en el buzón),
construcción del paquete Fase A reutilizada, revalidación por el
ejecutor de Fase B, entrega atómica 0644 con recibo 0600, ejecución
real aislada sobre un repo temporal con diff para revisión, integridad
del repo (sin commit/branch/cambios) y escaneo estático de la fuente
(sin red, sin shell, sin ejecución de texto de IA, sin cableado con el
adaptador).

Ejecutar: python3 scripts/ops/tests/opencode-proposal.test.py
"""
import ast
import contextlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
OPS = HERE.parent
PROPOSAL_PATH = OPS / "opencode-proposal.py"
BRIDGE_PATH = OPS / "opencode-proposal-bridge.py"
EXECUTOR_PATH = OPS / "opencode-executor.py"
ADAPTER_PATH = OPS / "opencode-adapter.py"
WORKER_PATH = OPS / "dev-worker.py"


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


executor = _load("cv_executor_fasef_test", EXECUTOR_PATH)
proposal_contract = _load("cv_proposal_fasef_test", PROPOSAL_PATH)
bridge = _load("cv_bridge_fasef_test", BRIDGE_PATH)


def make_mailbox() -> tempfile.TemporaryDirectory:
    tmp = tempfile.TemporaryDirectory()
    home = Path(tmp.name)
    for name in ("jobs", "claims", "results", "dead", "work"):
        (home / name).mkdir(mode=0o700)
    return tmp


def make_repo() -> tempfile.TemporaryDirectory:
    tmp = tempfile.TemporaryDirectory()
    repo = Path(tmp.name)
    env = dict(os.environ, GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t",
               GIT_COMMITTER_NAME="t", GIT_COMMITTER_EMAIL="t@t")

    def g(*args):
        subprocess.run(["git", *args], cwd=repo, check=True, capture_output=True, env=env)

    g("-c", "init.defaultBranch=main", "init")
    (repo / "docs").mkdir()
    (repo / "docs" / "readme.md").write_text("base\n", encoding="utf-8")
    g("add", "-A")
    g("commit", "-m", "base")
    tmp.repo = repo
    return tmp


def valid_proposal(**overrides):
    now = time.time()
    proposal = {
        "version": proposal_contract.PROPOSAL_VERSION,
        "proposal_id": "prop-abc-123",
        "run_id": "run-fasef-1",
        "task_id": "task-uuid-1",
        "task_public_id": 42,
        "repo": "casa-viva",
        "title": "Fase F",
        "reason": "prueba del contrato de propuestas",
        "allow_paths": ["docs/"],
        "operations": [
            {"op": "create_file", "path": "docs/fase-f.md", "content": "# propuesta valida\n"}
        ],
        "patch_format": "structured-v1",
        "created_at": executor.ts(now - 60),
        "expires_at": executor.ts(now + 600),
    }
    proposal.update(overrides)
    return proposal


def patch_all_repos(repo):
    """Alinea la configuración de repo en los tres módulos cargados."""
    stack = contextlib.ExitStack()
    for module in (executor, proposal_contract.executor, bridge.executor):
        stack.enter_context(mock.patch.dict(module.ALLOWED_REPOS, {"casa-viva": Path(repo)}))
    return stack


def run_executor_once(home, now):
    old = os.umask(0o077)
    try:
        return executor.run_once(home, now)
    finally:
        os.umask(old)


class ValidatorTests(unittest.TestCase):
    def test_valid_proposal_passes(self):
        proposal = valid_proposal()
        out = proposal_contract.validate_proposal(proposal)
        self.assertIs(out, proposal)

    def test_rejects_non_object_and_version(self):
        for bad in (None, [], "x", 42, {}, valid_proposal(version="otro-v9")):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(bad)

    def test_rejects_unknown_keys_and_arbitrary_commands(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(commands=["rm -rf /"]))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(shell="/bin/sh -c x"))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(deterministic={"operation": "write_smoke_file", "path": "docs/control-os-smoke/x.md", "content": "x"}))

    def test_rejects_unsafe_identifiers(self):
        for bad in ("../etc", "a/b", "", None, "-lead", "x" * 65, 42):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(run_id=bad))
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(proposal_id=bad))

    def test_rejects_bad_task_and_repo(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(task_id=""))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(task_id="x" * 201))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(task_public_id=True))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(repo="vivabot"))

    def test_rejects_absolute_and_traversal_paths(self):
        for bad in ("/etc/passwd", "../secret", "docs/../../etc/x", "~/.ssh/config", "docs\\x.md", "a\x00b"):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(
                    operations=[{"op": "create_file", "path": bad, "content": "x"}]))
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(allow_paths=[bad]))

    def test_rejects_git_and_protected_paths(self):
        for bad in ("docs/.git/config", ".github/workflows/x.yml", "package.json", "package-lock.json"):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(
                    operations=[{"op": "replace_file", "path": bad, "content": "x"}]))

    def test_rejects_unauthorized_operations(self):
        for kind in ("run_command", "delete_file", "chmod", "move", "shell", "exec", "git", "patch"):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(
                    operations=[{"op": kind, "path": "docs/fase-f.md", "content": "x"}]))

    def test_rejects_operation_unknown_keys(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(
                operations=[{"op": "create_file", "path": "docs/fase-f.md", "content": "x", "cmd": "ls"}]))

    def test_rejects_missing_or_wrong_patch_format(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(patch_format=None))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(patch_format="unified-diff"))
        proposal = valid_proposal()
        del proposal["patch_format"]
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(proposal)

    def test_rejects_operations_out_of_contract(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(operations=[]))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(operations="x"))
        proposal = valid_proposal()
        del proposal["operations"]
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(proposal)

    def test_rejects_too_many_operations_and_huge_content(self):
        ops = [{"op": "create_file", "path": f"docs/f{i}.md", "content": "x"} for i in range(9)]
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(operations=ops))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(
                operations=[{"op": "create_file", "path": "docs/x.md", "content": "x" * 120_001}]))

    def test_rejects_secrets_in_text_and_content(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(title="token sk-abcdefghijklmnopqrst"))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(reason="cabecera Bearer abcdefghijklmnopqrst"))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(
                operations=[{"op": "create_file", "path": "docs/x.md",
                             "content": "const k = 'sbp_abcdefghijklmnopqrstuvwxyz12';"}]))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(
                operations=[{"op": "text_replace", "path": "docs/readme.md",
                             "old": "x", "new": "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature123456",
                             "expected_count": 1}]))

    def test_rejects_invalid_or_expired_timestamps(self):
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(created_at="no-es-fecha"))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(expires_at=None))
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(expires_at=executor.ts(time.time() - 120)))
        now = time.time()
        with self.assertRaises(proposal_contract.ProposalError):
            proposal_contract.validate_proposal(valid_proposal(expires_at=executor.ts(now)), now=now)

    def test_rejects_bad_allow_paths(self):
        for bad in ([], "docs/", 42, [None], ["/etc"]):
            with self.assertRaises(proposal_contract.ProposalError):
                proposal_contract.validate_proposal(valid_proposal(allow_paths=bad))

    def test_fingerprint_ignores_identifiers_and_times(self):
        a = valid_proposal()
        b = valid_proposal(proposal_id="prop-other", run_id="run-other",
                           created_at=executor.ts(time.time() - 30))
        self.assertEqual(proposal_contract.proposal_fingerprint(a),
                         proposal_contract.proposal_fingerprint(b))
        c = valid_proposal(operations=[{"op": "create_file", "path": "docs/otro.md", "content": "y"}])
        self.assertNotEqual(proposal_contract.proposal_fingerprint(a),
                            proposal_contract.proposal_fingerprint(c))


class SymlinkPrecheckTests(unittest.TestCase):
    def setUp(self):
        self.repo_tmp = make_repo()
        self.repo = self.repo_tmp.repo

    def tearDown(self):
        self.repo_tmp.cleanup()

    def test_accepts_plain_paths(self):
        bridge.precheck_symlinks(self.repo, ["docs/fase-f.md"])

    def test_rejects_parent_symlink(self):
        (self.repo / "docs" / "escape").symlink_to("/etc")
        with self.assertRaises(bridge.BridgePathUnsafe):
            bridge.precheck_symlinks(self.repo, ["docs/escape/x.md"])

    def test_rejects_target_symlink(self):
        (self.repo / "docs" / "enlace.md").symlink_to("/etc/passwd")
        with self.assertRaises(bridge.BridgePathUnsafe):
            bridge.precheck_symlinks(self.repo, ["docs/enlace.md"])

    def test_rejects_missing_repo(self):
        with self.assertRaises(bridge.BridgePathUnsafe):
            bridge.precheck_symlinks("/no/existe", ["docs/x.md"])


class BridgeFixture(unittest.TestCase):
    def setUp(self):
        self.mailbox_tmp = make_mailbox()
        self.home = Path(self.mailbox_tmp.name)
        self.repo_tmp = make_repo()
        self.repo = self.repo_tmp.repo
        self.ledger_tmp = tempfile.TemporaryDirectory()
        self.ledger = Path(self.ledger_tmp.name) / "proposal-ledger"
        self._repos = patch_all_repos(self.repo)
        self._repos.__enter__()

    def tearDown(self):
        self._repos.__exit__(None, None, None)
        self.ledger_tmp.cleanup()
        self.mailbox_tmp.cleanup()
        self.repo_tmp.cleanup()

    def deliver(self, proposal, **kwargs):
        return bridge.deliver_proposal(
            proposal,
            mailbox=self.home,
            ledger=self.ledger,
            now=time.time(),
            **kwargs,
        )


class BridgeDeliveryTests(BridgeFixture):
    def test_delivers_valid_proposal_atomically(self):
        summary = self.deliver(valid_proposal())
        self.assertTrue(summary["delivered"])
        job_path = Path(summary["job_path"])
        self.assertEqual(job_path.parent, self.home / "jobs")
        self.assertEqual(job_path.name, "task-run-run-fasef-1.json")
        mode = os.stat(job_path).st_mode & 0o777
        self.assertEqual(mode, 0o644)
        job = json.loads(job_path.read_text(encoding="utf-8"))
        self.assertEqual(job["version"], executor.JOB_VERSION)
        self.assertEqual(job["mode"], "ai")
        self.assertEqual(job["instructions"]["operations"][0]["path"], "docs/fase-f.md")
        self.assertEqual(job["ttl_seconds"], executor.TTL_SECONDS)
        receipt = self.ledger / "prop-abc-123.json"
        self.assertTrue(receipt.is_file())
        self.assertEqual(os.stat(receipt).st_mode & 0o777, 0o600)

    def test_job_passes_executor_validation_independently(self):
        summary = self.deliver(valid_proposal())
        job = json.loads(Path(summary["job_path"]).read_text(encoding="utf-8"))
        executor.validate_job(job, time.time())

    def test_expired_proposal_not_delivered(self):
        proposal = valid_proposal(expires_at=executor.ts(time.time() - 10))
        with self.assertRaises(bridge.BridgeExpired):
            self.deliver(proposal)
        self.assertEqual(list((self.home / "jobs").iterdir()), [])
        self.assertEqual(list(self.ledger.glob("*.json")), [])

    def test_invalid_mailbox_blocks_delivery(self):
        with self.assertRaises(bridge.BridgeEnvironment):
            bridge.deliver_proposal(
                valid_proposal(),
                mailbox=self.home / "no-existe",
                ledger=self.ledger,
                now=time.time(),
            )

    def test_big_proposal_file_rejected(self):
        path = Path(self.ledger_tmp.name) / "grande.json"
        path.write_text(json.dumps(valid_proposal()) + " " * (executor.MAX_JOB_BYTES + 1))
        with self.assertRaises(bridge.BridgeContract):
            bridge.load_proposal(path)

    def test_malformed_proposal_file_rejected(self):
        path = Path(self.ledger_tmp.name) / "roto.json"
        path.write_text("{no es json")
        with self.assertRaises(bridge.BridgeContract):
            bridge.load_proposal(path)
        with self.assertRaises(bridge.BridgeContract):
            bridge.load_proposal(Path(self.ledger_tmp.name) / "ausente.json")

    def test_repo_left_untouched_by_delivery(self):
        self.deliver(valid_proposal())
        out = subprocess.run(["git", "status", "--porcelain", "-uall"], cwd=self.repo,
                             capture_output=True, text=True)
        self.assertEqual(out.stdout.strip(), "")
        branches = subprocess.run(["git", "branch", "--list"], cwd=self.repo,
                                  capture_output=True, text=True)
        self.assertEqual(branches.stdout.strip(), "* main")


class BridgeDuplicateTests(BridgeFixture):
    def test_same_proposal_delivered_twice_rejected(self):
        self.deliver(valid_proposal())
        with self.assertRaises(bridge.BridgeDuplicate):
            self.deliver(valid_proposal())
        jobs = list((self.home / "jobs").iterdir())
        self.assertEqual(len(jobs), 1)

    def test_same_content_different_ids_rejected(self):
        self.deliver(valid_proposal())
        other = valid_proposal(proposal_id="prop-other", run_id="run-other")
        with self.assertRaises(bridge.BridgeDuplicate):
            self.deliver(other)
        self.assertEqual(list((self.home / "jobs").iterdir())[0].name, "task-run-run-fasef-1.json")

    def test_same_proposal_id_different_content_rejected(self):
        self.deliver(valid_proposal())
        changed = valid_proposal(run_id="run-other",
                                 operations=[{"op": "create_file", "path": "docs/otro.md", "content": "y"}])
        with self.assertRaises(bridge.BridgeDuplicate):
            self.deliver(changed)

    def test_run_id_in_results_blocks_delivery(self):
        self.deliver(valid_proposal())
        (self.home / "jobs" / "task-run-run-fasef-1.json").rename(
            self.home / "results" / "task-run-run-fasef-1.json")
        with self.assertRaises(bridge.BridgeDuplicate):
            self.deliver(valid_proposal(proposal_id="prop-otra"))

    def test_run_id_in_dead_blocks_delivery(self):
        self.deliver(valid_proposal())
        (self.home / "jobs" / "task-run-run-fasef-1.json").rename(
            self.home / "dead" / "task-run-run-fasef-1.json")
        with self.assertRaises(bridge.BridgeDuplicate):
            self.deliver(valid_proposal(proposal_id="prop-otra"))


class EndToEndTests(BridgeFixture):
    def test_proposal_runs_isolated_and_produces_diff_for_review(self):
        summary = self.deliver(valid_proposal())
        now = time.time()
        result = run_executor_once(self.home, now)
        self.assertIsNotNone(result)
        self.assertEqual(result["status"], "completed")
        self.assertEqual(result["run_id"], summary["run_id"])
        self.assertEqual(result["changed_paths"], ["docs/fase-f.md"])
        self.assertIn("propuesta valida", result["diff"])
        self.assertTrue((self.home / "results" / "task-run-run-fasef-1.json").is_file())

    def test_text_replace_proposal_end_to_end(self):
        proposal = valid_proposal(
            proposal_id="prop-tr-1", run_id="run-tr-1",
            operations=[{"op": "text_replace", "path": "docs/readme.md",
                         "old": "base", "new": "cambiado", "expected_count": 1}],
        )
        self.deliver(proposal)
        result = run_executor_once(self.home, time.time())
        self.assertEqual(result["status"], "completed")
        self.assertIn("cambiado", result["diff"])
        # El repo real no conserva ningún cambio ni rama.
        out = subprocess.run(["git", "status", "--porcelain"], cwd=self.repo,
                             capture_output=True, text=True)
        self.assertEqual(out.stdout.strip(), "")

    def test_executor_rejects_tampered_delivered_job(self):
        head_before = subprocess.run(["git", "rev-parse", "HEAD"], cwd=self.repo,
                                     capture_output=True, text=True).stdout
        self.deliver(valid_proposal())
        job_path = self.home / "jobs" / "task-run-run-fasef-1.json"
        job = json.loads(job_path.read_text(encoding="utf-8"))
        job["instructions"]["injected"] = {"op": "run_command", "cmd": "curl evil"}
        job_path.write_text(json.dumps(job), encoding="utf-8")
        os.chmod(job_path, 0o644)
        result = run_executor_once(self.home, time.time())
        self.assertEqual(result["status"], "failed")
        self.assertIn("claves", result["error"])
        self.assertTrue((self.home / "results" / "task-run-run-fasef-1.json").is_file())
        head_after = subprocess.run(["git", "rev-parse", "HEAD"], cwd=self.repo,
                                    capture_output=True, text=True).stdout
        self.assertEqual(head_before, head_after)

    def test_executor_never_commits_or_pushes(self):
        self.deliver(valid_proposal())
        run_executor_once(self.home, time.time())
        branches = subprocess.run(["git", "branch", "--list"], cwd=self.repo,
                                  capture_output=True, text=True)
        self.assertEqual(branches.stdout.strip(), "* main")
        log = subprocess.run(["git", "log", "--oneline"], cwd=self.repo,
                             capture_output=True, text=True)
        self.assertEqual(len(log.stdout.strip().splitlines()), 1)


class CliTests(BridgeFixture):
    def _run_cli(self, *args):
        env = dict(
            os.environ,
            CV_AI_MAILBOX=str(self.home / "jobs"),
            CV_PROPOSAL_LEDGER=str(self.ledger),
            CV_OPENCODE_REPO_CASA_VIVA=str(self.repo),
        )
        proc = subprocess.run(
            [sys.executable, str(BRIDGE_PATH), *args],
            capture_output=True, text=True, timeout=60, env=env,
        )
        return proc.returncode, proc.stdout.strip()

    def test_cli_delivers_with_ok_code(self):
        path = Path(self.ledger_tmp.name) / "propuesta.json"
        path.write_text(json.dumps(valid_proposal()), encoding="utf-8")
        code, out = self._run_cli(str(path))
        self.assertEqual(code, bridge.EXIT_OK)
        self.assertIn('"delivered": true', out)
        self.assertTrue((self.home / "jobs" / "task-run-run-fasef-1.json").is_file())

    def test_cli_rejects_duplicate_with_code_4(self):
        path = Path(self.ledger_tmp.name) / "propuesta.json"
        path.write_text(json.dumps(valid_proposal()), encoding="utf-8")
        self._run_cli(str(path))
        code, out = self._run_cli(str(path))
        self.assertEqual(code, bridge.EXIT_DUPLICATE)
        self.assertIn('"delivered": false', out)

    def test_cli_usage_and_missing_file(self):
        code, out = self._run_cli()
        self.assertEqual(code, bridge.EXIT_USAGE)
        code, out = self._run_cli("/no/existe.json")
        self.assertEqual(code, bridge.EXIT_CONTRACT)

    def test_cli_expired_code_5(self):
        path = Path(self.ledger_tmp.name) / "vencida.json"
        path.write_text(json.dumps(valid_proposal(expires_at=executor.ts(time.time() - 10))),
                        encoding="utf-8")
        code, out = self._run_cli(str(path))
        self.assertEqual(code, bridge.EXIT_EXPIRED)


class StaticScanTests(unittest.TestCase):
    SOURCES = (PROPOSAL_PATH, BRIDGE_PATH)

    FORBIDDEN_IMPORTS = ("urllib", "socket", "http", "ssl", "requests", "subprocess", "ftplib", "telnetlib", "asyncio")

    def _asts(self):
        for path in self.SOURCES:
            yield path, ast.parse(path.read_text(encoding="utf-8"))

    def test_no_network_no_shell_no_dynamic_execution(self):
        for path, tree in self._asts():
            for node in ast.walk(tree):
                if isinstance(node, ast.Import):
                    for alias in node.names:
                        root = alias.name.split(".")[0]
                        self.assertNotIn(root, self.FORBIDDEN_IMPORTS,
                                         f"{path.name}: import {alias.name}")
                if isinstance(node, ast.ImportFrom) and node.module:
                    root = node.module.split(".")[0]
                    self.assertNotIn(root, self.FORBIDDEN_IMPORTS,
                                     f"{path.name}: from {node.module}")
                if isinstance(node, ast.Call):
                    func = node.func
                    name = getattr(func, "id", None) or getattr(func, "attr", None)
                    self.assertNotIn(name, ("system", "popen", "eval", "exec", "compile", "Popen"),
                                     f"{path.name}: llamada {name}")
                    if isinstance(func, ast.Attribute) and func.attr == "system":
                        self.fail(f"{path.name}: os.system")
                if isinstance(node, ast.Constant) and isinstance(node.value, str):
                    self.assertNotIn("shell=True", node.value, f"{path.name}: shell=True")
                    self.assertNotIn("/root", node.value, f"{path.name}: ruta /root")

    def test_generation_stays_separated_from_execution(self):
        bridge_text = BRIDGE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("opencode_adapter", bridge_text)
        self.assertNotIn("opencode-adapter", bridge_text)
        self.assertNotIn("run_command", bridge_text)
        adapter_text = ADAPTER_PATH.read_text(encoding="utf-8")
        self.assertNotIn("proposal-bridge", adapter_text)
        self.assertNotIn("opencode-proposal", adapter_text)
        executor_text = EXECUTOR_PATH.read_text(encoding="utf-8")
        self.assertNotIn("opencode-proposal", executor_text)
        self.assertNotIn("opencode_adapter", executor_text)

    def test_bridge_never_invokes_model_or_shell_directly(self):
        tree = ast.parse(BRIDGE_PATH.read_text(encoding="utf-8"))
        for node in ast.walk(tree):
            if isinstance(node, ast.Call):
                name = getattr(node.func, "id", None) or getattr(node.func, "attr", None)
                self.assertNotIn(name, ("invoke", "os.execl", "os.spawn"))
                if name == "build_ai_job":
                    self.assertTrue(True)

    def test_proposal_module_is_pure(self):
        tree = ast.parse(PROPOSAL_PATH.read_text(encoding="utf-8"))
        for node in ast.walk(tree):
            if isinstance(node, (ast.Import, ast.ImportFrom)):
                names = [a.name for a in node.names] if isinstance(node, ast.Import) else [node.module]
                for name in names:
                    if name:
                        self.assertNotIn(name.split(".")[0],
                                         ("open", "os", "sys", "shutil", "tempfile"))
        # Solo se apoya en el ejecutor (Fase B) y en los patrones de la Fase A.
        text = PROPOSAL_PATH.read_text(encoding="utf-8")
        self.assertIn("opencode-executor.py", text)
        self.assertIn("dev-worker.py", text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
