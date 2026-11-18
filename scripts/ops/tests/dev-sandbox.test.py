#!/usr/bin/env python3
"""Pruebas del módulo de ejecución en caja (EVO-LOCAL-001, E1). Sin bwrap, sin root, sin procesos reales."""
import ast
import importlib.util
import os
import signal
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path

for _name in ("fcntl", "resource", "pwd", "grp"):
    try:
        __import__(_name)
    except ImportError:
        sys.modules[_name] = types.ModuleType(_name)

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_dev_sandbox_under_test", OPS / "dev-sandbox.py")
box = importlib.util.module_from_spec(spec)
spec.loader.exec_module(box)

WS = box.WORK_ROOT + "/run-task-150"


class FakeProc:
    def __init__(self, rc=0, out=b"ok", hang=False):
        self.returncode, self.out, self.hang, self.pid, self.killed = rc, out, hang, 4242, False

    def communicate(self, timeout=None):
        if self.hang and timeout is not None:
            raise subprocess.TimeoutExpired("x", timeout)
        return self.out, None

    def kill(self):
        self.killed = True


class FakePopen:
    def __init__(self, **proc_kw):
        self.calls, self.proc_kw = [], proc_kw

    def __call__(self, argv, **kw):
        self.calls.append((argv, kw))
        self.last = FakeProc(**self.proc_kw)
        return self.last


class BoxTests(unittest.TestCase):
    def test_comando_de_la_caja(self):
        spawn = FakePopen()
        rc, out = box.run_in_box(["npm", "run", "lint"], WS, 60, uid=1000, gid=1001, spawn=spawn)
        argv, kw = spawn.calls[0]
        self.assertEqual((rc, out), (0, "ok"))
        self.assertEqual(argv[0], box.DEDICATED_BWRAP)
        for needed in ("--unshare-all", "--die-with-parent", "--new-session", "--clearenv"):
            self.assertIn(needed, argv)
        for forbidden in ("--share-net", "--dev-bind", "--dev-bind-try", "--cap-add", "--unshare-user-try"):
            self.assertNotIn(forbidden, argv)
        binds = [argv[i + 1] for i, a in enumerate(argv) if a == "--bind"]
        self.assertEqual(binds, [WS])  # un único destino de escritura real
        self.assertEqual(argv[argv.index("--chdir") + 1], WS)
        self.assertEqual(argv[-3:], ["npm", "run", "lint"])

    def test_violaciones_del_validador_impiden_lanzar(self):
        from unittest import mock
        spawn = FakePopen()
        with mock.patch.object(box.spec, "validate_command", return_value=["invalid"]):
            with self.assertRaises(box.SandboxError):
                box.run_in_box(["npm", "test"], WS, 60, uid=1000, gid=1001, spawn=spawn)
        self.assertEqual(spawn.calls, [])

    def test_proceso_sin_shell_ni_privilegios_ni_entorno_heredado(self):
        spawn = FakePopen()
        box.run_in_box(["npm", "test"], WS, 60, uid=1000, gid=1001, spawn=spawn)
        argv, kw = spawn.calls[0]
        self.assertIsInstance(argv, list)
        self.assertNotIn("shell", kw)
        self.assertEqual((kw["user"], kw["group"], kw["extra_groups"]), (1000, 1001, []))
        self.assertEqual(kw["env"], {})  # el entorno lo fija --setenv dentro de la caja
        self.assertTrue(kw["start_new_session"])
        self.assertEqual(kw["stdin"], subprocess.DEVNULL)

    def test_el_entorno_de_la_caja_es_minimo(self):
        spawn = FakePopen()
        os.environ["OPENAI_API_KEY"] = "secreto-no-debe-aparecer"
        try:
            box.run_in_box(["npm", "test"], WS, 60, uid=1000, gid=1001, spawn=spawn)
        finally:
            del os.environ["OPENAI_API_KEY"]
        argv = spawn.calls[0][0]
        setenv = {argv[i + 1]: argv[i + 2] for i, a in enumerate(argv) if a == "--setenv"}
        self.assertEqual(set(setenv), {"PATH", "HOME", "TMPDIR"})
        self.assertNotIn("secreto-no-debe-aparecer", " ".join(argv))

    def test_nunca_como_root(self):
        for uid, gid in ((0, 1001), (1000, 0), (0, 0)):
            with self.assertRaises(box.SandboxError):
                box.run_in_box(["npm", "test"], WS, 60, uid=uid, gid=gid, spawn=FakePopen())
            with self.assertRaises(box.SandboxError):
                box.install_deps(WS, 60, uid=uid, gid=gid, spawn=FakePopen())

    def test_workspace_fuera_de_la_raiz_o_mal_nombrado(self):
        for bad in ("/root/control-os-dev/task-1", "/home/cvdev/control-os-dev/work/task-150",
                    "/home/cvdev/control-os-dev/work/run-1/sub", "/home/cvdev/control-os-dev/work/../run-1",
                    "/home/cvdev/control-os-dev/work", "relativo/run-1", ""):
            with self.assertRaises(box.SandboxError, msg=bad):
                box.run_in_box(["npm", "test"], bad, 60, uid=1000, gid=1001, spawn=FakePopen())

    def test_comandos_con_aspecto_de_shell_se_rechazan(self):
        for cmd in (["npm", "test", "&&", "id"], ["sh", ";", "id"], [], ["npm", "../x"], ["npm", ""]):
            with self.assertRaises(box.SandboxError, msg=str(cmd)):
                box.run_in_box(cmd, WS, 60, uid=1000, gid=1001, spawn=FakePopen())

    def test_tiempo_agotado_mata_el_grupo(self):
        killed = []
        had = hasattr(box.os, "killpg")
        real = getattr(box.os, "killpg", None)
        box.os.killpg = lambda pid, sig: killed.append((pid, sig))
        try:
            spawn = FakePopen(hang=True)
            rc, out = box.run_in_box(["npm", "test"], WS, 5, uid=1000, gid=1001, spawn=spawn)
        finally:
            if had:
                box.os.killpg = real
            else:
                del box.os.killpg
        self.assertEqual(rc, 124)
        self.assertEqual([k[0] for k in killed], [4242])
        self.assertEqual(killed[0][1], getattr(signal, "SIGKILL", signal.SIGTERM))
        self.assertIn("tiempo agotado", out)

    def test_salida_limitada(self):
        spawn = FakePopen(rc=1, out=b"x" * (box.MAX_OUT * 3))
        rc, out = box.run_in_box(["npm", "test"], WS, 60, uid=1000, gid=1001, spawn=spawn)
        self.assertEqual(rc, 1)
        self.assertLessEqual(len(out), box.MAX_OUT)

    def test_instalacion_con_red_pero_sin_secretos(self):
        spawn = FakePopen()
        environ = {"PATH": "/usr/bin", "LANG": "C.UTF-8", "HOME": "/root", "OPENAI_API_KEY": "x", "SUPABASE_SERVICE_KEY": "y", "WOO_SECRET": "z"}
        box.install_deps(WS, 120, uid=1000, gid=1001, environ=environ, spawn=spawn)
        argv, kw = spawn.calls[0]
        self.assertEqual(argv, ["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund"])
        self.assertEqual(kw["env"]["HOME"], WS + "/.cv-npm-home")
        self.assertEqual(kw["env"]["PATH"], box.INSTALL_PATH)
        self.assertEqual(kw["env"]["NPM_CONFIG_USERCONFIG"], "/dev/null")
        self.assertEqual(kw["env"]["NPM_CONFIG_GLOBALCONFIG"], "/dev/null")
        self.assertEqual(kw["env"]["NPM_CONFIG_CACHE"], WS + "/.cv-npm-home/cache")
        self.assertEqual(set(kw["env"]), {"PATH", "LANG", "HOME", "NPM_CONFIG_USERCONFIG", "NPM_CONFIG_GLOBALCONFIG", "NPM_CONFIG_CACHE"})
        self.assertEqual((kw["user"], kw["group"]), (1000, 1001))
        self.assertNotIn("shell", kw)

    @unittest.skipIf(os.name == "nt", "rutas POSIX")
    def test_instalacion_rechaza_config_y_home_existente(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            ws = root / "run-test"
            ws.mkdir()
            for name in (".npmrc", ".cv-npm-home"):
                with self.subTest(name=name):
                    target = ws / name
                    target.symlink_to(root / "canario-ausente")
                    spawn = FakePopen()
                    with self.assertRaises(box.SandboxError):
                        box.install_deps(ws, 60, uid=1000, gid=1001, work_root=str(root), spawn=spawn)
                    self.assertEqual(spawn.calls, [])
                    target.unlink()
                    target.touch()
                    with self.assertRaises(box.SandboxError):
                        box.install_deps(ws, 60, uid=1000, gid=1001, work_root=str(root), spawn=spawn)
                    self.assertEqual(spawn.calls, [])
                    target.unlink()

    @unittest.skipIf(os.name == "nt", "las rutas del sandbox son POSIX; corre en Linux (CI)")
    def test_entregar_y_recuperar_sin_seguir_enlaces(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "work"
            ws = root / "run-task-9"
            (ws / "sub").mkdir(parents=True)
            (ws / "a.txt").write_text("a")
            (ws / "sub" / "b.txt").write_text("b")
            link = ws / "enlace"
            try:
                link.symlink_to("/etc")
                has_link = True
            except (OSError, NotImplementedError):
                has_link = False
            calls = []
            had = hasattr(box.os, "lchown")
            real = getattr(box.os, "lchown", None)
            box.os.lchown = lambda p, u, g: calls.append((str(p), u, g))
            try:
                n = box.hand_over(ws, 1000, 1001, work_root=str(root).replace("\\", "/"))
                m = box.take_back(ws, work_root=str(root).replace("\\", "/"))
            finally:
                if had:
                    box.os.lchown = real
                else:
                    del box.os.lchown
            self.assertEqual(n, m)
            self.assertGreaterEqual(n, 4 if has_link else 3)
            self.assertTrue(all(u == 1000 for _, u, _ in calls[:n]))
            self.assertTrue(all(u == 0 for _, u, _ in calls[n:]))
            self.assertFalse(any(p.startswith("/etc") for p, _, _ in calls))  # el destino del enlace no se toca

    def test_hand_over_rechaza_rutas_fuera_de_la_raiz(self):
        for bad in ("/", "/root", "/home/cvdev", "/home/cvdev/control-os-dev/work", "/home/cvdev/control-os-dev/work/run-1/../.."):
            with self.assertRaises(Exception, msg=bad):
                box.hand_over(bad, 1000, 1001)

    # --- estáticas
    def test_estatico_sin_shell_ni_ejecucion_de_texto(self):
        tree = ast.parse((OPS / "dev-sandbox.py").read_text(encoding="utf-8"))
        for node in ast.walk(tree):
            if isinstance(node, ast.keyword) and node.arg == "shell":
                self.fail("shell= no permitido")
            if isinstance(node, ast.Call):
                fn = node.func
                name = fn.id if isinstance(fn, ast.Name) else getattr(fn, "attr", "")
                self.assertNotIn(name, ("eval", "exec", "system", "popen", "run", "call", "check_output", "check_call", "compile"))

    def test_estatico_solo_el_worker_lo_conoce_y_de_forma_perezosa(self):
        for name in ("cv-proposal-gate.py", "local-runner.py", "opencode-executor.py", "local-proposer.py"):
            self.assertNotIn("dev-sandbox", (OPS / name).read_text(encoding="utf-8"), name)
        self.assertEqual((OPS / "dev-worker.py").read_text(encoding="utf-8").count("dev-sandbox.py"), 1)


if __name__ == "__main__":
    unittest.main(verbosity=1)
