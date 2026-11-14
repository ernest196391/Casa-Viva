#!/usr/bin/env python3
"""Pruebas del adaptador real de OpenCode (Control OS × CLI simulada).

Cubre: puerta de integración desactivada por defecto y bloqueo de
aislamiento (ISOLATION_GUARANTEED=False), construcción exacta del
comando documentado (`opencode run --pure -m <modelo> --format json`),
scrubbing del entorno del hijo, límites de recursos (RLIMIT_FSIZE),
timeout con muerte del grupo de procesos, validación estricta de la
salida JSON Lines, manejo de errores de CLI y escaneo estático de la
superficie (sin shell, sin red, sin --auto, sin cableado en el
ejecutor de Fase B).

Importante: TODAS las invocaciones de esta suite usan un simulador de
CLI escrito en un directorio temporal. Ninguna prueba invoca el modelo
real ni el binario opencode.

Ejecutar: python3 scripts/ops/tests/opencode-adapter.test.py
"""
import ast
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

HERE = Path(__file__).resolve().parent
ADAPTER_PATH = HERE.parent / "opencode-adapter.py"
EXECUTOR_PATH = HERE.parent / "opencode-executor.py"

_spec = importlib.util.spec_from_file_location("cv_opencode_adapter", ADAPTER_PATH)
adapter = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(adapter)

executor_text = EXECUTOR_PATH.read_text(encoding="utf-8")
adapter_text = ADAPTER_PATH.read_text(encoding="utf-8")

SIM_MODEL = adapter.DEFAULT_MODEL

SIM_TEMPLATE = '''#!/usr/bin/env python3
import json, os, sys, time
RECV = {recv!r}
SCENARIO = {scenario!r}
with open(RECV, "w") as f:
    json.dump({{"argv": sys.argv, "cwd": os.getcwd(), "env_keys": sorted(os.environ)}}, f)
with open(SCENARIO) as f:
    sc = json.load(f)
if sys.argv[1:7] != ["run", "--pure", "-m", sc["model"], "--format", "json"]:
    sys.stderr.write("argv mismatch\\n")
    sys.exit(99)
if "expect_prompt" in sc and sys.argv[7] != sc["expect_prompt"]:
    sys.stderr.write("prompt mismatch\\n")
    sys.exit(98)
if sc.get("sleep"):
    time.sleep(sc["sleep"])
    if sc.get("marker"):
        with open(sc["marker"], "w") as f:
            f.write("x")
out = "\\n".join(sc.get("stdout", []))
repeat = sc.get("stdout_repeat")
if repeat:
    out = (repeat["line"] + "\\n") * repeat["count"]
if out:
    sys.stdout.write(out)
    sys.stdout.flush()
errout = "\\n".join(sc.get("stderr", []))
if errout:
    sys.stderr.write(errout + "\\n")
sys.exit(sc.get("exit", 0))
'''


def valid_events():
    return [
        {"type": "step_start", "part": {"type": "step-start"}},
        {"type": "text", "part": {"type": "text", "text": "Primera línea"}},
        {"type": "text", "text": "segunda"},
        {"type": "step_finish", "part": {"type": "step-finish", "tokens": 10}},
    ]


def expected_text():
    return "Primera línea\nsegunda"


class SimFixture(unittest.TestCase):
    """Directorio temporal con simulador de CLI y registro de recepción."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.home = Path(self.tmp.name)
        self.bin = self.home / "bin"
        self.bin.mkdir(mode=0o700)
        self.sim = self.bin / "opencode-sim"
        self.recv = self.home / "recv.json"
        self.scenario = self.home / "scenario.json"
        self.sim.write_text(
            SIM_TEMPLATE.format(recv=str(self.recv), scenario=str(self.scenario)),
            encoding="utf-8",
        )
        os.chmod(self.sim, 0o755)

    def tearDown(self):
        self.tmp.cleanup()

    def set_scenario(self, **scenario):
        scenario.setdefault("model", SIM_MODEL)
        self.scenario.write_text(json.dumps(scenario), encoding="utf-8")
        return scenario

    def recv_record(self):
        return json.loads(self.recv.read_text(encoding="utf-8"))


class GateTests(unittest.TestCase):
    def test_disabled_by_default(self):
        env = {k: v for k, v in os.environ.items() if k not in (adapter.ENABLE_ENV, adapter.SANDBOX_ENV)}
        with mock.patch.dict(os.environ, env, clear=True):
            self.assertFalse(adapter.enabled())
            with self.assertRaises(adapter.AdapterDisabled):
                adapter.invoke("hola", cwd="/tmp")

    def test_enabled_flag_alone_is_not_enough(self):
        with mock.patch.dict(os.environ, {adapter.ENABLE_ENV: "1"}, clear=False):
            self.assertFalse(adapter.enabled())

    def test_enabled_but_real_binary_blocked(self):
        with mock.patch.dict(os.environ, {
            adapter.ENABLE_ENV: "1",
            adapter.SANDBOX_ENV: "1",
        }, clear=False):
            with self.assertRaises(adapter.AdapterBlocked) as ctx:
                adapter.invoke("hola", cwd="/tmp")
            self.assertIn("--pure", ctx.exception.message)

    def test_enabled_with_simulator_runner_passes(self):
        calls = {}

        def runner(cmd, cwd, env, timeout, max_output):
            calls["cmd"] = cmd
            return 0, json.dumps({"type": "text", "text": "ok"}).encode(), b""

        with mock.patch.dict(os.environ, {
            adapter.ENABLE_ENV: "1",
            adapter.SANDBOX_ENV: "1",
        }, clear=False):
            result = adapter.invoke("hola", cwd="/tmp", runner=runner)
        self.assertEqual(result["text"], "ok")
        self.assertIn("--pure", calls["cmd"])

    def test_isolation_not_guaranteed(self):
        self.assertIs(adapter.ISOLATION_GUARANTEED, False)


class CommandTests(unittest.TestCase):
    def test_build_command_exact(self):
        cmd = adapter.build_command("edita docs", SIM_MODEL, "opencode")
        self.assertEqual(
            cmd,
            ["opencode", "run", "--pure", "-m", SIM_MODEL, "--format", "json", "edita docs"],
        )

    def test_prompt_validation(self):
        self.assertEqual(adapter.validate_prompt("x"), "x")
        for bad in ("", "   ", None, 42, "\x00x", "x" * (adapter.MAX_PROMPT + 1)):
            with self.assertRaises(adapter.AdapterUsage):
                adapter.validate_prompt(bad)

    def test_model_validation(self):
        self.assertEqual(adapter.validate_model(SIM_MODEL), SIM_MODEL)
        for bad in ("", "sin-slash", "otros/gpt-4", "opencode/../../etc", None, 42):
            with self.assertRaises(adapter.AdapterUsage):
                adapter.validate_model(bad)

    def test_limits_validation(self):
        with self.assertRaises(adapter.AdapterUsage):
            adapter.run_command("p", cwd="/tmp", timeout_seconds=1)
        with self.assertRaises(adapter.AdapterUsage):
            adapter.run_command("p", cwd="/tmp", timeout_seconds=10_000)
        with self.assertRaises(adapter.AdapterUsage):
            adapter.run_command("p", cwd="/tmp", max_output_bytes=10)
        with self.assertRaises(adapter.AdapterUsage):
            adapter.run_command("p", cwd="/tmp/no-existe")


class EnvironmentTests(unittest.TestCase):
    def test_child_environment_scrubs_secrets(self):
        secrets = {
            "SUPABASE_URL": "https://example.supabase.co",
            "SUPABASE_SERVICE_ROLE_KEY": "sbp_secreto",
            "MY_API_TOKEN": "tok",
            "VIVABOT_PASSWORD": "pw",
            "AWS_SECRET_ACCESS_KEY": "sk",
        }
        env = dict(os.environ, **secrets)
        env.pop("PATH", None)
        with mock.patch.dict(os.environ, env, clear=True):
            child = adapter.child_environment()
        for key in secrets:
            self.assertNotIn(key, child)
        self.assertEqual(child["PATH"], "/usr/bin:/bin")
        self.assertIn("HOME", child)
        self.assertTrue(set(child) <= set(adapter.KEEP_ENV))


class OutputParsingTests(unittest.TestCase):
    def test_valid_jsonl_with_text_extraction(self):
        raw = "\n".join(json.dumps(e) for e in valid_events()).encode() + b"\n"
        result = adapter.parse_output(raw)
        self.assertEqual(result["text"], expected_text())
        self.assertEqual(len(result["events"]), 4)

    def test_empty_lines_are_skipped(self):
        raw = b"\n\n" + json.dumps({"type": "text", "text": "hola"}).encode() + b"\n\n"
        self.assertEqual(adapter.parse_output(raw)["text"], "hola")

    def test_invalid_json_line(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b'{"type": "text"\n')

    def test_non_object_event(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b"[1, 2]\n")

    def test_missing_type(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b'{"text": "x"}\n')

    def test_text_event_without_text(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b'{"type": "text", "part": {}}\n')

    def test_text_event_with_non_string(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b'{"type": "text", "text": 5}\n')

    def test_type_too_long(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(('{"type": "' + "x" * 100 + '"}').encode())

    def test_not_utf8(self):
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(b'\xff\xfe\n')

    def test_too_many_events(self):
        line = json.dumps({"type": "other"}) + "\n"
        raw = line * (adapter.MAX_EVENTS + 1)
        with self.assertRaises(adapter.AdapterProtocol):
            adapter.parse_output(raw.encode())

    def test_unknown_types_retained_not_extracted(self):
        raw = json.dumps({"type": "tool", "part": {"tool": "edit"}}).encode() + b"\n"
        result = adapter.parse_output(raw)
        self.assertEqual(result["text"], "")
        self.assertEqual(result["events"][0]["type"], "tool")


class SimulatedInvocationTests(SimFixture):
    """Invocaciones reales del simulador de CLI: nunca del modelo real."""

    def run_sim(self, prompt="escribe un smoke", **kwargs):
        return adapter.run_command(prompt, binary=str(self.sim), cwd=self.bin, **kwargs)

    def test_simulator_receives_exact_command(self):
        self.set_scenario(stdout=[json.dumps({"type": "text", "text": "listo"})])
        self.run_sim(prompt="escribe un smoke")
        record = self.recv_record()
        self.assertEqual(record["argv"][1:7], ["run", "--pure", "-m", SIM_MODEL, "--format", "json"])
        self.assertEqual(record["argv"][7], "escribe un smoke")
        self.assertEqual(record["cwd"], str(self.bin))

    def test_simulator_receives_scrubbed_env(self):
        self.set_scenario(stdout=[json.dumps({"type": "text", "text": "ok"})])
        secrets = {"SUPABASE_SERVICE_ROLE_KEY": "x", "CV_SECRET_TOKEN": "y"}
        with mock.patch.dict(os.environ, secrets, clear=False):
            self.run_sim()
        env_keys = self.recv_record()["env_keys"]
        self.assertNotIn("SUPABASE_SERVICE_ROLE_KEY", env_keys)
        self.assertNotIn("CV_SECRET_TOKEN", env_keys)
        self.assertIn("PATH", env_keys)

    def test_success_with_events(self):
        self.set_scenario(stdout=[json.dumps(e) for e in valid_events()])
        result = self.run_sim()
        self.assertEqual(result["text"], expected_text())

    def test_cli_error_exit_two(self):
        self.set_scenario(exit=2, stderr=["boom: fallo simulado"])
        with self.assertRaises(adapter.AdapterCLI) as ctx:
            self.run_sim()
        self.assertIn("código 2", ctx.exception.message)
        self.assertIn("fallo simulado", ctx.exception.message)
        self.assertEqual(ctx.exception.code, 8)

    def test_output_limit_kills_child(self):
        self.set_scenario(stdout_repeat={"line": "x" * 128, "count": 5000})
        with self.assertRaises(adapter.AdapterOutputLimit):
            self.run_sim(max_output_bytes=4096)

    def test_timeout_kills_process_group(self):
        marker = self.home / "marker.txt"
        self.assertFalse(marker.exists())
        self.set_scenario(sleep=60, marker=str(marker))
        with self.assertRaises(adapter.AdapterTimeout):
            self.run_sim(timeout_seconds=5)
        # El grupo fue muerto: el marcador posterior al sleep no existe.
        self.assertFalse(marker.exists())

    def test_missing_binary(self):
        with self.assertRaises(adapter.AdapterCLI):
            adapter.run_command("p", binary=str(self.bin / "no-existe"), cwd=self.bin)

    def test_runner_contract(self):
        self.set_scenario(stdout=[json.dumps({"type": "text", "text": "via runner"})])

        def runner(cmd, cwd, env, timeout, max_output):
            proc = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, timeout=timeout)
            return proc.returncode, proc.stdout, proc.stderr

        result = adapter.run_command("p", binary=str(self.sim), cwd=self.bin, runner=runner, timeout_seconds=10)
        self.assertEqual(result["text"], "via runner")

    def test_runner_bad_contract(self):
        with self.assertRaises(adapter.AdapterCLI):
            adapter.run_command("p", binary=str(self.sim), cwd=self.bin,
                                runner=lambda *a: ("no-int", b"", b""), timeout_seconds=10)
        with self.assertRaises(adapter.AdapterCLI):
            adapter.run_command("p", binary=str(self.sim), cwd=self.bin,
                                runner=lambda *a: 1 / 0, timeout_seconds=10)

    def test_runner_nonzero_with_oversize_output(self):
        with self.assertRaises(adapter.AdapterOutputLimit):
            adapter.run_command("p", binary=str(self.sim), cwd=self.bin,
                                runner=lambda *a: (1, b"y" * 4096, b""), max_output_bytes=4096,
                                timeout_seconds=10)


class MainTests(unittest.TestCase):
    def run_main(self, args, env_overrides):
        env = {k: v for k, v in os.environ.items()
               if k not in (adapter.ENABLE_ENV, adapter.SANDBOX_ENV)}
        env.update(env_overrides)
        return subprocess.run(
            [sys.executable, str(ADAPTER_PATH), *args],
            capture_output=True, text=True, timeout=30, env=env,
        )

    def test_disabled_exit_three(self):
        proc = self.run_main(["hola"], {})
        self.assertEqual(proc.returncode, 3)
        self.assertEqual(json.loads(proc.stdout)["code"], 3)

    def test_blocked_exit_four_without_invoking_binary(self):
        proc = self.run_main(["hola"], {
            adapter.ENABLE_ENV: "1",
            adapter.SANDBOX_ENV: "1",
            adapter.BINARY_ENV: "/no-existe/opencode",
        })
        self.assertEqual(proc.returncode, 4)
        self.assertIn("bloqueado", json.loads(proc.stdout)["reason"])

    def test_usage_exit_two(self):
        proc = self.run_main([], {})
        self.assertEqual(proc.returncode, 2)
        proc = self.run_main(["a", "b"], {})
        self.assertEqual(proc.returncode, 2)


class StaticScanTests(unittest.TestCase):
    tree = ast.parse(adapter_text)

    def _calls(self):
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Call):
                yield node

    def _imports(self):
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    yield alias.name
            elif isinstance(node, ast.ImportFrom):
                yield node.module or ""

    def test_no_shell(self):
        for node in self._calls():
            for keyword in node.keywords:
                self.assertNotEqual(keyword.arg, "shell", "shell está prohibido")

    def test_no_network_imports(self):
        banned = {"socket", "urllib", "urllib.request", "http", "requests", "ftplib", "smtplib"}
        for name in self._imports():
            self.assertNotIn(name, banned)

    def test_no_auto_approve_flag(self):
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Constant) and node.value == "--auto":
                self.fail("--auto aparece como constante en el código")

    def test_documented_flags_present(self):
        for flag in ("--pure", "--format", '"json"', '"run"', '"-m"'):
            self.assertIn(flag, adapter_text)

    def test_process_group_kill_and_limits_present(self):
        self.assertIn("os.killpg", adapter_text)
        self.assertIn("start_new_session=True", adapter_text)
        self.assertIn("RLIMIT_FSIZE", adapter_text)
        self.assertIn("RLIMIT_CPU", adapter_text)
        self.assertIn("RLIMIT_CORE", adapter_text)

    def test_keep_env_whitelist_only(self):
        for node in ast.walk(self.tree):
            if isinstance(node, ast.Assign) and any(
                isinstance(t, ast.Name) and t.id == "KEEP_ENV" for t in node.targets
            ):
                keys = {elt.value for elt in node.value.elts}
                self.assertTrue(keys <= set(adapter.KEEP_ENV))
                self.assertNotIn("SUPABASE_URL", keys)
                return
        self.fail("KEEP_ENV no encontrado")

    def test_no_secret_references(self):
        lowered = adapter_text.lower()
        for banned in ("supabase", "service_role", "/root", "sbp_", "sk-", "eyJ"):
            self.assertNotIn(banned, lowered)

    def test_no_system_config_paths(self):
        lowered = adapter_text.lower()
        self.assertNotIn(".config", lowered)
        self.assertNotIn(".local", lowered)

    def test_sim_mode_marker_in_docstring(self):
        self.assertIn("simulador", adapter_text.lower())

    def test_executor_not_wired_to_adapter(self):
        self.assertNotIn("opencode-adapter", executor_text)
        self.assertNotIn("opencode_adapter", executor_text)

    def test_source_compiles(self):
        compile(adapter_text, "opencode-adapter.py", "exec")

    def test_sim_permissions_never_writable_by_others(self):
        mode = stat.S_IMODE(ADAPTER_PATH.stat().st_mode)
        self.assertEqual(mode & 0o022, 0)


if __name__ == "__main__":
    unittest.main(verbosity=1)
