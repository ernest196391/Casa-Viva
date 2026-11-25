#!/usr/bin/env python3
"""Pruebas de la PROPUESTA de sandbox Bubblewrap + AppArmor (Fase E).

Cubre: invariantes del perfil AppArmor propuesto (sin unconfined, sin
capabilities, userns y denegación de red presentes, escritura limitada,
lecturas acotadas sin /etc/ssl/private ni /etc/ssl entero, /usr/lib64
para el cargador), contrato del comando Bubblewrap (montaje mínimo:
sistema read-only, un único destino de escritura, red aislada, entorno
limpio por lista blanca en build y en validate, hoja run-<id>), lógica
del arnés de pruebas negativas con runner falso, protecciones contra
ejecución accidental del arnés, coherencia de ruta entre perfil, módulo
y ejecutor (CV_CONTROL_OS / CV_OPENCODE_HOME), el conflicto documentado
con el modelo remoto y escaneo estático de la fuente.

Importante: NINGUNA prueba ejecuta bwrap, apparmor_parser ni consulta
rutas del sistema ni del VPS. El arnés real (verify-sandbox.py) solo se
ejecutará tras instalación supervisada; aquí se ejercita con runner
inyectado. La propuesta NO declara el sandbox operativo.

Ejecutar: python3 scripts/ops/tests/opencode-sandbox.test.py
"""
import ast
import importlib.util
import json
import re
import stat
import subprocess
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
OPS = HERE.parent
SANDBOX_DIR = OPS / "sandbox"
SPEC_PATH = SANDBOX_DIR / "opencode-sandbox-spec.py"
PROFILE_PATH = SANDBOX_DIR / "opencode-sandbox.profile"
VERIFY_PATH = SANDBOX_DIR / "verify-sandbox.py"
REPO = HERE.parent.parent.parent
ADAPTER_PATH = OPS / "opencode-adapter.py"
EXECUTOR_PATH = OPS / "opencode-executor.py"
VALIDATE_PATH = REPO / ".github" / "workflows" / "validate.yml"

_spec = importlib.util.spec_from_file_location("cv_sandbox_spec", SPEC_PATH)
spec = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(spec)

_verify_spec = importlib.util.spec_from_file_location("cv_sandbox_verify", VERIFY_PATH)
verify = importlib.util.module_from_spec(_verify_spec)
_verify_spec.loader.exec_module(verify)

PROFILE_TEXT = PROFILE_PATH.read_text(encoding="utf-8")
SPEC_TEXT = SPEC_PATH.read_text(encoding="utf-8")
VERIFY_TEXT = VERIFY_PATH.read_text(encoding="utf-8")
ADAPTER_TEXT = ADAPTER_PATH.read_text(encoding="utf-8")
EXECUTOR_TEXT = EXECUTOR_PATH.read_text(encoding="utf-8")
VALIDATE_TEXT = VALIDATE_PATH.read_text(encoding="utf-8")

WORK_ROOT = spec.DEFAULT_WORK_ROOT
WORKSPACE = f"{WORK_ROOT}/run-123"


def probe(name, ok=True, detail=""):
    return json.dumps({"check": name, "ok": ok, "detail": detail}).encode() + b"\n"


def fake_runner(outcomes=None):
    """Runner falso: responde por nombre de prueba; nunca ejecuta bwrap."""
    outcomes = outcomes or {}

    def runner(cmd, **_):
        check = cmd[-1]
        if check in outcomes:
            return outcomes[check]
        return 0, probe(check), b""

    return runner


class ProfileFileTests(unittest.TestCase):
    def test_profile_exists_with_safe_permissions(self):
        self.assertTrue(PROFILE_PATH.is_file())
        mode = stat.S_IMODE(PROFILE_PATH.stat().st_mode)
        self.assertEqual(mode & 0o022, 0)

    def test_profile_passes_all_invariants(self):
        self.assertEqual(spec.validate_profile(PROFILE_TEXT), [])

    def test_profile_attached_to_bwrap_path(self):
        self.assertIn(f"profile {spec.PROFILE_NAME} /usr/bin/bwrap", PROFILE_TEXT)

    def test_profile_allows_userns_creation(self):
        self.assertIn("userns,", PROFILE_TEXT)

    def test_profile_denies_network(self):
        self.assertIn("deny network,", PROFILE_TEXT)

    def test_profile_has_no_unconfined_flag(self):
        rules = spec._profile_rules(PROFILE_TEXT)
        self.assertNotIn("unconfined", "\n".join(rules).lower())

    def test_dev_worker_bootstrap_constants_are_read_only(self):
        rules = spec._profile_rules((SANDBOX_DIR / "dev-worker-sandbox.profile").read_text(encoding="utf-8").split("# CV_RELEASE_PILOT_BEGIN")[0])
        self.assertIn("/proc/sys/kernel/overflowuid r,", rules)
        self.assertIn("/proc/sys/kernel/overflowgid r,", rules)
        self.assertEqual([rule for rule in rules if rule.startswith("/proc/")],
                         ["/proc/sys/kernel/overflowuid r,", "/proc/sys/kernel/overflowgid r,",
                          "/proc/sys/user/max_user_namespaces r,"])
        self.assertIn("deny network,", rules)
        self.assertFalse(any(rule.startswith("capability") for rule in rules))

    def test_split_pilot_candidate_denies_privileged_operations(self):
        text = (SANDBOX_DIR / "dev-worker-sandbox.profile").read_text(encoding="utf-8")
        bootstrap, candidate = text.split("# CV_RELEASE_PILOT_BEGIN")[1].split("profile cv-release-pilot-candidate flags=")
        self.assertIn("/usr/bin/python3.12 Px -> cv-release-pilot-candidate,", bootstrap)
        rules = spec._profile_rules(candidate)
        for rule in ("deny capability,", "deny userns,", "deny network,", "deny mount,", "deny pivot_root,"):
            self.assertIn(rule, rules)
        self.assertFalse(any(rule.startswith("capability ") for rule in rules))

    def test_profile_grants_no_capability(self):
        rules = spec._profile_rules(PROFILE_TEXT)
        for rule in rules:
            self.assertNotIn("capability", rule.lower())

    def test_profile_writes_limited_to_worktree_and_tmp(self):
        rules = spec._profile_rules(PROFILE_TEXT)
        writes = [spec._rule_write_path(rule) for rule in rules]
        for path in writes:
            if path is None:
                continue
            self.assertTrue(
                path.startswith("@{CV_CONTROL_OS}/work/") or path.startswith("/tmp/"),
                f"escritura fuera de contrato: {path}",
            )

    def test_profile_variable_matches_spec_work_root(self):
        self.assertIn(f"@{{CV_CONTROL_OS}} = {spec.CV_CONTROL_OS}", PROFILE_TEXT)
        self.assertEqual(WORK_ROOT, f"{spec.CV_CONTROL_OS}/work")

    def test_profile_never_grants_etc_ssl_wide_read(self):
        rules = spec._profile_rules(PROFILE_TEXT)
        joined = "\n".join(rules)
        self.assertNotIn("/etc/ssl/** r,", joined)
        self.assertNotIn("/etc/ssl/private", joined)
        self.assertIn("/etc/ssl/certs/** r,", joined)

    def test_profile_covers_dynamic_loader_in_usr_lib64(self):
        rules = spec._profile_rules(PROFILE_TEXT)
        self.assertIn("/usr/lib64/** mr,", rules)


class ProfileNegativeTests(unittest.TestCase):
    def mutate(self, old, new):
        self.assertIn(old, PROFILE_TEXT)
        violations = spec.validate_profile(PROFILE_TEXT.replace(old, new))
        self.assertTrue(violations, "la mutación no fue detectada")

    def test_detects_unconfined_flag(self):
        self.mutate("flags=(attach_disconnected,mediate_deleted)", "flags=(unconfined)")

    def test_detects_capability_grant(self):
        self.mutate("  userns,", "  userns,\n  capability net_admin,")

    def test_detects_broad_write_rule(self):
        self.mutate("  /tmp/{,**} rw,", "  /tmp/{,**} rw,\n  / rw,")

    def test_detects_etc_write_rule(self):
        self.mutate("  /tmp/{,**} rw,", "  /tmp/{,**} rw,\n  /etc/{,**} rw,")

    def test_detects_missing_userns(self):
        self.mutate("  userns,", "  # userns,")

    def test_detects_missing_network_deny(self):
        self.mutate("  deny network,", "  # deny network,")

    def test_detects_second_profile_block(self):
        self.mutate(
            "profile cv-opencode-bwrap /usr/bin/bwrap flags=",
            "profile cv-other /usr/bin/other flags=\nprofile cv-opencode-bwrap /usr/bin/bwrap flags=",
        )

    def test_detects_wide_etc_ssl_read_exposing_private_keys(self):
        self.mutate("/etc/ssl/certs/** r,", "/etc/ssl/** r,")

    def test_detects_missing_certs_read(self):
        self.mutate("  /etc/ssl/certs/** r,", "  # /etc/ssl/certs/** r,")

    def test_detects_missing_usr_lib64_rule(self):
        self.mutate("  /usr/lib64/** mr,", "  # /usr/lib64/** mr,")


class CrossArtifactTests(unittest.TestCase):
    """CV_CONTROL_OS: una sola ruta en perfil, módulo y ejecutor."""

    def test_spec_work_root_derived_from_control_os(self):
        self.assertEqual(spec.DEFAULT_WORK_ROOT, spec.CV_CONTROL_OS + "/work")

    def test_executor_default_home_matches_control_os(self):
        match = re.search(
            r'os\.environ\.get\("CV_OPENCODE_HOME", "([^"]+)"\)', EXECUTOR_TEXT
        )
        self.assertIsNotNone(match, "ejecutor sin valor por defecto de CV_OPENCODE_HOME")
        self.assertEqual(match.group(1), spec.CV_CONTROL_OS)
        self.assertEqual(spec.DEFAULT_WORK_ROOT, spec.CV_CONTROL_OS + "/work")

    def test_profile_write_rule_covers_work_root(self):
        self.assertIn("@{CV_CONTROL_OS}/work/{,**} rw,", PROFILE_TEXT)
        self.assertTrue(spec.DEFAULT_WORK_ROOT.startswith(spec.CV_CONTROL_OS + "/"))


class CommandTests(unittest.TestCase):
    def setUp(self):
        self.inner = ["opencode", "run", "--pure", "-m", "opencode/glm-5.3-flash-free",
                      "--format", "json", "prueba"]
        self.cmd = spec.build_bwrap_command(WORKSPACE, self.inner)

    def test_build_starts_with_bwrap(self):
        self.assertEqual(Path(self.cmd[0]).name, "bwrap")

    def test_build_isolation_flags(self):
        for flag in ("--unshare-all", "--die-with-parent", "--new-session", "--clearenv"):
            self.assertEqual(self.cmd.count(flag), 1, flag)
        self.assertNotIn("--share-net", self.cmd)
        self.assertNotIn("--dev-bind", self.cmd)

    def test_build_single_writable_bind_is_workspace(self):
        binds = spec._pairs(self.cmd, "--bind")
        self.assertEqual(binds, [(WORKSPACE, WORKSPACE)])

    def test_build_work_root_masked_with_tmpfs(self):
        tmpfs = [pair[0] for pair in spec._pairs(self.cmd, "--tmpfs")]
        self.assertIn(WORK_ROOT, tmpfs)
        self.assertIn("/tmp", tmpfs)
        self.assertIn("/run", tmpfs)

    def test_build_binds_only_public_certs_not_etc_ssl(self):
        ro_pairs = spec._pairs(self.cmd, "--ro-bind") + spec._pairs(self.cmd, "--ro-bind-try")
        self.assertIn(("/etc/ssl/certs", "/etc/ssl/certs"), ro_pairs)
        self.assertNotIn(("/etc/ssl", "/etc/ssl"), ro_pairs)

    def test_build_includes_lib64_symlink_for_dynamic_loader(self):
        self.assertIn(("usr/lib64", "/lib64"), spec._pairs(self.cmd, "--symlink"))

    def test_build_system_binds_read_only(self):
        self.assertIn("--ro-bind", self.cmd)
        for source, target in spec._pairs(self.cmd, "--ro-bind-try"):
            self.assertEqual(source, target)

    def test_build_clears_env_and_sets_whitelist(self):
        env = {}
        for key, value in spec._pairs(self.cmd, "--setenv"):
            env[key] = value
        self.assertEqual(set(env) <= spec.CHILD_ENV_KEYS, True)
        self.assertEqual(env["HOME"], "/tmp")
        self.assertEqual(env["PATH"], "/usr/bin:/bin")

    def test_build_inner_command_preserved_verbatim(self):
        self.assertEqual(self.cmd[self.cmd.index("--") + 1:], self.inner)

    def test_build_chdir_matches_workspace(self):
        chdirs = spec._pairs(self.cmd, "--chdir", arity=1)
        self.assertEqual(chdirs, [(WORKSPACE,)])

    def test_validate_command_accepts_built(self):
        self.assertEqual(spec.validate_command(self.cmd), [])

    def test_child_env_whitelist(self):
        merged = spec.validate_child_env({"TERM": "dumb", "LANG": "es_ES.UTF-8"})
        self.assertEqual(merged["TERM"], "dumb")
        with self.assertRaises(spec.SandboxSpecError):
            spec.validate_child_env({"SUPABASE_SERVICE_ROLE_KEY": "x"})
        with self.assertRaises(spec.SandboxSpecError):
            spec.validate_child_env({"HOME": "con\x00nul"})
        with self.assertRaises(spec.SandboxSpecError):
            spec.validate_child_env("no-dict")

    def test_child_env_covers_adapter_keep_env(self):
        # Toda clave conservada por child_environment() del adaptador
        # (Fase D) debe estar habilitada en CHILD_ENV_KEYS (Fase E),
        # incluida SHELL; nunca al revés con claves de secretos.
        adapter_keys = set(re.findall(r'"([A-Z_]+)"', ADAPTER_TEXT.split("KEEP_ENV = (")[1].split(")")[0]))
        self.assertEqual(adapter_keys <= set(spec.CHILD_ENV_KEYS), True)

    def test_workspace_validation(self):
        self.assertEqual(spec.validate_workspace(WORKSPACE), WORKSPACE)
        self.assertEqual(spec.validate_workspace(f"{WORK_ROOT}/run-1a_b-2"), f"{WORK_ROOT}/run-1a_b-2")
        for bad in ("run-123", f"{WORK_ROOT}", "/etc/passwd", f"{WORK_ROOT}/../escape",
                    f"{WORK_ROOT}/a/b", "", "/tmp/run-1", f"{WORK_ROOT}/directorio",
                    f"{WORK_ROOT}/run-"):
            with self.assertRaises(spec.SandboxSpecError):
                spec.validate_workspace(bad)

    def test_inner_cmd_validation(self):
        with self.assertRaises(spec.SandboxSpecError):
            spec.build_bwrap_command(WORKSPACE, [])
        with self.assertRaises(spec.SandboxSpecError):
            spec.build_bwrap_command(WORKSPACE, ["python3", ".."])
        with self.assertRaises(spec.SandboxSpecError):
            spec.build_bwrap_command(WORKSPACE, ["python3", "-c", "x\x00y"])
        with self.assertRaises(spec.SandboxSpecError):
            spec.build_bwrap_command(WORKSPACE, ["python3", "-c", "x", "&&", "rm"])


class CommandNegativeTests(unittest.TestCase):
    def setUp(self):
        self.inner = ["/usr/bin/python3", "-c", "print('x')", "bootstrap"]
        self.cmd = spec.build_bwrap_command(WORKSPACE, self.inner)

    def test_rejects_dev_bind(self):
        tampered = self.cmd[:1] + ["--dev-bind", "/", "/"] + self.cmd[1:]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--dev-bind" in v for v in violations))

    def test_rejects_dev_bind_try(self):
        index = self.cmd.index("--")
        tampered = self.cmd[:index] + ["--dev-bind-try", "/", "/"] + self.cmd[index:]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--dev-bind-try" in v for v in violations))

    def test_rejects_setenv_outside_whitelist(self):
        index = self.cmd.index("--")
        tampered = self.cmd[:index] + [
            "--setenv", "SUPABASE_SERVICE_ROLE_KEY", "filtrado",
        ] + self.cmd[index:]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--setenv" in v for v in violations))

    def test_rejects_bind_of_wide_etc_ssl(self):
        index = self.cmd.index("--")
        tampered = self.cmd[:index] + ["--ro-bind-try", "/etc/ssl", "/etc/ssl"] + self.cmd[index:]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("/etc/ssl" in v for v in violations))

    def test_explicit_capability_drop_required(self):
        cmd = spec.build_bwrap_command(WORKSPACE, self.inner, drop_capabilities=True)
        self.assertEqual(spec.validate_command(cmd, require_cap_drop=True), [])
        self.assertTrue(spec.validate_command(self.cmd, require_cap_drop=True))
        cmd[cmd.index("--cap-drop")+1] = "CAP_NET_ADMIN"
        self.assertTrue(spec.validate_command(cmd, require_cap_drop=True))

    def test_capability_addition_rejected(self):
        cmd = self.cmd[:1] + ["--cap-add", "CAP_SYS_ADMIN"] + self.cmd[1:]
        self.assertTrue(spec.validate_command(cmd))

    def test_rejects_share_net(self):
        tampered = self.cmd[:1] + ["--share-net"] + self.cmd[1:]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--share-net" in v for v in violations))

    def test_rejects_second_writable_bind(self):
        tampered = self.cmd + ["--bind", "/tmp/extra", "/tmp/extra"]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--bind" in v for v in violations))

    def test_rejects_writable_etc_bind(self):
        tampered = self.cmd + ["--ro-bind-try", "/etc", "/etc"]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("/etc" in v for v in violations))

    def test_rejects_missing_unshare_all(self):
        tampered = [t for t in self.cmd if t != "--unshare-all"]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("--unshare-all" in v for v in violations))

    def test_rejects_missing_inner(self):
        tampered = self.cmd[: self.cmd.index("--")]
        violations = spec.validate_command(tampered)
        self.assertTrue(any("interno" in v or "separador" in v for v in violations))

    def test_rejects_non_bwrap_head(self):
        tampered = ["sudo"] + self.cmd
        violations = spec.validate_command(tampered)
        self.assertTrue(any("bwrap" in v for v in violations))

    def test_rejects_chdir_mismatch(self):
        index = self.cmd.index("--chdir")
        self.cmd[index + 1] = "/tmp"
        violations = spec.validate_command(self.cmd)
        self.assertTrue(any("--chdir" in v for v in violations))


class VerifyLogicTests(unittest.TestCase):
    """Lógica del arnés con runner falso: sin bwrap, sin sistema."""

    def test_all_checks_pass(self):
        report = verify.run_checks(WORKSPACE, fake_runner())
        self.assertEqual(report["verdict"], verify.VERDICT_OK)
        self.assertEqual(set(report["checks"]), set(verify.CHECKS))

    def test_probe_commands_use_bwrap_and_workspace(self):
        captured = {}

        def runner(cmd):
            captured["cmd"] = cmd
            return 0, probe(cmd[-1]), b""

        verify.run_checks(WORKSPACE, runner)
        cmd = captured["cmd"]
        self.assertEqual(Path(cmd[0]).name, "bwrap")
        self.assertEqual(spec.validate_command(cmd), [])
        self.assertIn("net_denied", cmd)

    def test_network_failure_reported(self):
        outcomes = {"write_usr_denied": (0, probe("write_usr_denied", ok=False, detail="wrote"), b"")}
        report = verify.run_checks(WORKSPACE, fake_runner(outcomes))
        self.assertEqual(report["verdict"], verify.VERDICT_FAILED)
        self.assertFalse(report["checks"]["write_usr_denied"]["ok"])
        self.assertEqual(len(report["checks"]), len(verify.CHECKS))

    def test_unexpected_write_success_reported(self):
        outcomes = {"write_etc_denied": (0, probe("write_etc_denied", ok=False), b"")}
        report = verify.run_checks(WORKSPACE, fake_runner(outcomes))
        self.assertEqual(report["verdict"], verify.VERDICT_FAILED)

    def test_bootstrap_without_answer_is_no_operativo(self):
        outcomes = {"bootstrap": (1, b"", b"bwrap: setting up uid map: Permission denied")}
        report = verify.run_checks(WORKSPACE, fake_runner(outcomes))
        self.assertEqual(report["verdict"], verify.VERDICT_NO_OPERATIVO)
        self.assertEqual(set(report["checks"]), {"bootstrap"})
        self.assertEqual(report["checks"]["bootstrap"]["stderr"], "bwrap: setting up uid map: Permission denied")

    def test_bootstrap_diagnostic_is_bounded(self):
        report = verify.run_checks(WORKSPACE, fake_runner({"bootstrap": (1, b"", b"x"*4096)}))
        self.assertEqual(len(report["checks"]["bootstrap"]["stderr"]), 512)

    def test_mid_check_without_answer_is_failed(self):
        outcomes = {"home_masked": (1, b"", b"cualquier error")}
        report = verify.run_checks(WORKSPACE, fake_runner(outcomes))
        self.assertEqual(report["verdict"], verify.VERDICT_FAILED)

    def test_runner_exception_is_no_operativo(self):
        def runner(_cmd):
            raise FileNotFoundError("bwrap no instalado")

        report = verify.run_checks(WORKSPACE, runner)
        self.assertEqual(report["verdict"], verify.VERDICT_NO_OPERATIVO)

    def test_nonzero_exit_cannot_pass(self):
        report = verify.run_checks(WORKSPACE, fake_runner({"bootstrap": (1, probe("bootstrap"), b"")}))
        self.assertEqual(report["verdict"], verify.VERDICT_NO_OPERATIVO)

    def test_mismatched_check_cannot_pass(self):
        report = verify.run_checks(WORKSPACE, fake_runner({"bootstrap": (0, probe("net_denied"), b"")}))
        self.assertEqual(report["verdict"], verify.VERDICT_NO_OPERATIVO)

    def test_string_boolean_cannot_pass(self):
        payload = json.dumps({"check": "bootstrap", "ok": "false", "detail": ""}).encode()
        report = verify.run_checks(WORKSPACE, fake_runner({"bootstrap": (0, payload, b"")}))
        self.assertEqual(report["verdict"], verify.VERDICT_NO_OPERATIVO)

    def test_dedicated_dev_command(self):
        root = verify.DEV_WORK_ROOT
        report = verify.run_checks(root + "/run-e3", fake_runner(), work_root=root,
                                   bwrap_binary=verify.DEV_BWRAP)
        self.assertEqual(report["verdict"], verify.VERDICT_OK)

    def test_positive_control_must_pass(self):
        outcomes = {"write_workspace_allowed": (0, probe("write_workspace_allowed", ok=False), b"")}
        report = verify.run_checks(WORKSPACE, fake_runner(outcomes))
        self.assertEqual(report["verdict"], verify.VERDICT_FAILED)


class CgroupLimitTests(unittest.TestCase):
    def test_finite_limits_at_or_below_budget(self):
        result = verify.assess_cgroup_limits(
            {"memory.max": "1048576", "pids.max": "32", "cpu.max": "50000 100000"},
            memory_bytes=1048576, max_pids=32, cpu_cores=1)
        self.assertEqual(result["verdict"], verify.VERDICT_OK)

    def test_unlimited_missing_and_invalid_fail_closed(self):
        for observed in ({}, {"memory.max": "max", "pids.max": "max", "cpu.max": "max 100000"},
                         {"memory.max": "0", "pids.max": "-1", "cpu.max": "100000 0"}):
            with self.subTest(observed=observed):
                result = verify.assess_cgroup_limits(observed, memory_bytes=1024, max_pids=16, cpu_cores=1)
                self.assertEqual(result["verdict"], verify.VERDICT_FAILED)

    def test_over_budget_fails(self):
        result = verify.assess_cgroup_limits(
            {"memory.max": "2048", "pids.max": "17", "cpu.max": "200000 100000"},
            memory_bytes=1024, max_pids=16, cpu_cores=1)
        self.assertEqual(result["checks"], {"memory.max": False, "pids.max": False, "cpu.max": False})


class VerifyGuardTests(unittest.TestCase):
    """El arnés real se niega a ejecutarse sin confirmación explícita."""

    def run_script(self, args):
        return subprocess.run(
            [sys.executable, str(VERIFY_PATH), *args],
            capture_output=True, text=True, timeout=30,
        )

    def test_refuses_without_execute(self):
        proc = self.run_script(["--workspace", WORKSPACE])
        self.assertEqual(proc.returncode, verify.EXIT_REFUSED)
        self.assertEqual(json.loads(proc.stdout)["verdict"], "refused")

    def test_refuses_without_workspace(self):
        proc = self.run_script(["--execute"])
        self.assertEqual(proc.returncode, verify.EXIT_REFUSED)

    def test_refuses_invalid_workspace_even_with_execute(self):
        proc = self.run_script(["--workspace", "/tmp/escape", "--execute"])
        self.assertEqual(proc.returncode, verify.EXIT_USAGE)
        self.assertEqual(json.loads(proc.stdout)["verdict"], "refused")

    def test_refuses_nonexistent_workspace_even_with_execute(self):
        proc = self.run_script([
            "--workspace", f"{WORK_ROOT}/run-no-existe-prueba-negativa", "--execute",
        ])
        self.assertEqual(proc.returncode, verify.EXIT_USAGE)
        self.assertEqual(json.loads(proc.stdout)["verdict"], "refused")


class StaticScanTests(unittest.TestCase):
    spec_tree = ast.parse(SPEC_TEXT)
    verify_tree = ast.parse(VERIFY_TEXT)

    def _imports(self, tree):
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    yield alias.name
            elif isinstance(node, ast.ImportFrom):
                yield node.module or ""

    def test_spec_executes_nothing(self):
        self.assertNotIn("subprocess", SPEC_TEXT)
        for name in self._imports(self.spec_tree):
            self.assertNotIn(name, ("subprocess", "socket", "urllib", "http", "requests"))

    def test_spec_has_no_shell_or_inspection_tools(self):
        self.assertNotIn("os.system", SPEC_TEXT)
        self.assertNotIn("Popen", SPEC_TEXT)
        lowered = SPEC_TEXT.lower()
        for banned in ("apparmor_parser", "aa-status", "sysctl", "/etc/shadow", "/root"):
            self.assertNotIn(banned, lowered)

    def test_verify_has_no_shell(self):
        self.assertNotIn("shell=True", VERIFY_TEXT)
        self.assertNotIn("os.system", VERIFY_TEXT)

    def test_verify_network_code_only_inside_probe_string(self):
        # socket solo existe en el código interno que corre DENTRO de la
        # jaula; el arnés mismo no abre conexiones.
        for name in self._imports(self.verify_tree):
            self.assertNotIn(name, ("socket", "urllib", "http", "requests"))

    def test_verify_requires_explicit_confirmation(self):
        self.assertIn("--execute", VERIFY_TEXT)
        self.assertIn("EXIT_REFUSED", VERIFY_TEXT)

    def test_verify_inner_probe_covers_all_checks(self):
        for check in verify.CHECKS:
            self.assertIn(f'name == "{check}"', verify.INNER_PROBE)

    def test_no_wire_to_adapter_yet(self):
        # El adaptador sigue bloqueado y sin integración con la propuesta.
        self.assertIn("ISOLATION_GUARANTEED = False", ADAPTER_TEXT)
        self.assertNotIn("sandbox-spec", ADAPTER_TEXT)
        self.assertNotIn("build_bwrap_command", ADAPTER_TEXT)

    def test_validate_yml_wires_config_tests(self):
        self.assertIn("opencode-sandbox.test.py", VALIDATE_TEXT)
        self.assertIn("opencode-sandbox-spec.py", VALIDATE_TEXT)
        self.assertIn("verify-sandbox.py", VALIDATE_TEXT)
        self.assertNotIn("verify-sandbox.py --execute", VALIDATE_TEXT)

    def test_sources_compile(self):
        compile(SPEC_TEXT, "opencode-sandbox-spec.py", "exec")
        compile(VERIFY_TEXT, "verify-sandbox.py", "exec")
        compile(ADAPTER_TEXT, "opencode-adapter.py", "exec")

    def test_safe_permissions(self):
        for path in (SPEC_PATH, VERIFY_PATH):
            mode = stat.S_IMODE(path.stat().st_mode)
            self.assertEqual(mode & 0o022, 0, str(path))

    def test_proposal_document_exists(self):
        doc = REPO / "docs" / "CONTROL_OS_SANDBOX_PROPOSAL.md"
        self.assertTrue(doc.is_file())
        text = doc.read_text(encoding="utf-8").lower()
        self.assertIn("no instalada", text)
        self.assertIn("no probada", text)
        self.assertIn("reversión", text)

    def test_proposal_document_declares_remote_model_conflict(self):
        # deny network hace inviable el modelo remoto dentro de la jaula
        # tal como está propuesta: el documento no puede perderlo.
        doc = (REPO / "docs" / "CONTROL_OS_SANDBOX_PROPOSAL.md").read_text(encoding="utf-8").lower()
        self.assertIn("modelo remoto", doc)
        self.assertIn("credenciales", doc)
        self.assertIn("proxy", doc)

    def test_proposal_document_declares_path_single_source(self):
        doc = (REPO / "docs" / "CONTROL_OS_SANDBOX_PROPOSAL.md").read_text(encoding="utf-8")
        self.assertIn("CV_OPENCODE_HOME", doc)
        self.assertIn("@{CV_CONTROL_OS} = /home/cvdev/control-os-dev", doc)

    def test_proposal_document_keeps_ssl_private_out(self):
        doc = (REPO / "docs" / "CONTROL_OS_SANDBOX_PROPOSAL.md").read_text(encoding="utf-8")
        self.assertIn("/etc/ssl/private", doc)
        self.assertIn("/etc/ssl/certs", doc)


if __name__ == "__main__":
    unittest.main(verbosity=1)
