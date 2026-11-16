#!/usr/bin/env python3
"""Pruebas del cableado del Dev Worker con la caja (EVO-LOCAL-001). Sin bwrap, sin root, sin red."""
import ast
import importlib.util
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

for _name in ("fcntl", "resource", "pwd", "grp"):
    try:
        __import__(_name)
    except ImportError:
        sys.modules[_name] = types.ModuleType(_name)

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_dev_worker_sandbox_under_test", OPS / "dev-worker.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class FakeBox:
    def __init__(self, root="/tmp/cv-work"):
        self.WORK_ROOT = root
        self.calls = []
        self.rc = 0

    def hand_over(self, work, uid, gid):
        self.calls.append(("hand_over", str(work), uid, gid))

    def take_back(self, work):
        self.calls.append(("take_back", str(work)))

    def install_deps(self, work, timeout, uid, gid):
        self.calls.append(("install", str(work), timeout, uid, gid))
        return self.rc, "instalado"

    def run_in_box(self, argv, work, timeout, uid, gid):
        self.calls.append(("box", list(argv), timeout))
        return self.rc, "ok"

    def kinds(self):
        return [c[0] for c in self.calls]


class SandboxWiringTests(unittest.TestCase):
    def setUp(self):
        self.env = mock.patch.dict(os.environ, {}, clear=False)
        self.env.start()
        os.environ.pop("CV_DEV_SANDBOX", None)
        self.fake = FakeBox()
        worker._BOX = self.fake
        self.ids = mock.patch.object(worker, "_sandbox_ids", lambda: {"uid": 1000, "gid": 1001})
        self.ids.start()
        self.tmp = tempfile.TemporaryDirectory()
        self.work = Path(self.tmp.name) / "run-task-150"
        self.work.mkdir()

    def tearDown(self):
        worker._BOX = None
        self.ids.stop()
        self.env.stop()
        self.tmp.cleanup()

    def sandbox(self, on=True):
        if on:
            os.environ["CV_DEV_SANDBOX"] = "1"
        else:
            os.environ.pop("CV_DEV_SANDBOX", None)

    # --- interruptor
    def test_solo_el_valor_1_la_enciende(self):
        for value, expected in (("1", True), ("0", False), ("", False), ("true", False), ("si", False), (" 1", False)):
            os.environ["CV_DEV_SANDBOX"] = value
            self.assertEqual(worker.sandbox_enabled(), expected, repr(value))
        os.environ.pop("CV_DEV_SANDBOX")
        self.assertFalse(worker.sandbox_enabled())

    # --- apagada: comportamiento de siempre
    def test_apagada_no_toca_la_caja(self):
        calls = []
        with mock.patch.object(worker, "run", lambda cmd, cwd=None, timeout=120: (calls.append(cmd), (0, "ok"))[1]):
            ok, _ = worker.run_tests("casa-viva", self.work)
        self.assertTrue(ok)
        self.assertEqual(self.fake.calls, [])
        self.assertIn(["npm", "install", "--no-audit", "--no-fund"], calls)
        for profile in worker.TEST_PROFILES["casa-viva"]:
            self.assertIn(profile, calls)

    def test_apagada_isolated_equivale_a_run_tests(self):
        with mock.patch.object(worker, "run_tests", return_value=(True, "logs")) as rt:
            self.assertEqual(worker.run_tests_isolated("vivabot", self.work, ["docs/a.md"], ["docs/"]), (True, "logs"))
        rt.assert_called_once()
        self.assertEqual(self.fake.calls, [])

    # --- encendida
    def test_encendida_todo_pasa_por_la_caja(self):
        self.sandbox()
        with mock.patch.object(worker, "run", side_effect=AssertionError("run() no debe usarse con la caja encendida")):
            for repo in ("vivabot", "nexo", "casa-viva"):
                self.fake.calls.clear()
                ok, _ = worker.run_tests(repo, self.work)
                self.assertTrue(ok, repo)
                self.assertEqual(self.fake.kinds()[0], "install")
                boxed = [c[1] for c in self.fake.calls if c[0] == "box"]
                for profile in worker.TEST_PROFILES[repo]:
                    self.assertIn(profile, boxed, repo)
                self.assertEqual(self.fake.calls[0][3:], (1000, 1001))

    def test_instalacion_con_los_tiempos_de_siempre(self):
        self.sandbox()
        worker.run_tests("casa-viva", self.work)
        self.assertEqual(self.fake.calls[0][2], 300)
        self.fake.calls.clear()
        worker.run_tests("vivabot", self.work)
        self.assertEqual(self.fake.calls[0][2], 180)

    def test_un_fallo_en_la_caja_corta_las_pruebas(self):
        self.sandbox()
        self.fake.rc = 1
        ok, logs = worker.run_tests("casa-viva", self.work)
        self.assertFalse(ok)
        self.assertEqual(self.fake.kinds(), ["install"])  # no sigue tras fallar la instalación

    def test_orden_entregar_probar_recuperar(self):
        self.sandbox()
        with mock.patch.object(worker, "run_tests", side_effect=lambda r, w: (self.fake.calls.append(("tests",)), (True, "ok"))[1]), \
                mock.patch.object(worker, "validate_diff", return_value=["docs/a.md"]):
            ok, _ = worker.run_tests_isolated("casa-viva", self.work, ["docs/a.md"], ["docs/"])
        self.assertTrue(ok)
        self.assertEqual(self.fake.kinds(), ["hand_over", "tests", "take_back"])

    def test_se_recupera_aunque_las_pruebas_revienten(self):
        self.sandbox()
        with mock.patch.object(worker, "run_tests", side_effect=RuntimeError("boom")):
            with self.assertRaises(RuntimeError):
                worker.run_tests_isolated("casa-viva", self.work, ["docs/a.md"], ["docs/"])
        self.assertEqual(self.fake.kinds(), ["hand_over", "take_back"])

    def test_si_las_pruebas_tocan_otros_archivos_se_rechaza(self):
        self.sandbox()
        with mock.patch.object(worker, "run_tests", return_value=(True, "ok")), \
                mock.patch.object(worker, "validate_diff", return_value=["docs/a.md", "docs/trampa.md"]):
            with self.assertRaises(RuntimeError) as ctx:
                worker.run_tests_isolated("casa-viva", self.work, ["docs/a.md"], ["docs/"])
        self.assertIn("trampa.md", str(ctx.exception))

    def test_si_las_pruebas_dejan_un_enlace_simbolico_se_rechaza(self):
        if os.name == "nt":
            self.skipTest("symlinks requieren permisos en Windows")
        self.sandbox()
        (self.work / "docs").mkdir()
        (self.work / "docs" / "a.md").symlink_to("/etc/passwd")
        with mock.patch.object(worker, "run_tests", return_value=(True, "ok")), \
                mock.patch.object(worker, "validate_diff", return_value=["docs/a.md"]):
            with self.assertRaises(RuntimeError) as ctx:
                worker.run_tests_isolated("casa-viva", self.work, ["docs/a.md"], ["docs/"])
        self.assertIn("simbólico", str(ctx.exception))

    # --- ubicación del worktree
    def _prepare(self, root_dir):
        repo = Path(self.tmp.name) / "repo"
        (repo / ".git").mkdir(parents=True, exist_ok=True)
        seen = []

        def fake_run(cmd, cwd=None, timeout=120):
            seen.append(cmd)
            return 0, "abc123\n"
        with mock.patch.object(worker, "REPOS", {"casa-viva": repo}), mock.patch.object(worker, "run", fake_run):
            out = worker.prepare_worktree("casa-viva", 150, "Titulo de prueba")
        return out, seen

    def test_apagada_usa_dev_root_y_task_n(self):
        root = Path(self.tmp.name) / "devroot"
        with mock.patch.object(worker, "DEV_ROOT", root):
            (_, work, branch, _), seen = self._prepare(root)
        self.assertEqual(work, root / "task-150")
        self.assertTrue(root.is_dir())

    def test_encendida_usa_la_raiz_de_la_caja_y_run_task_n(self):
        self.sandbox()
        root = Path(self.tmp.name) / "caja-work"
        root.mkdir()
        self.fake.WORK_ROOT = str(root)
        (_, work, branch, _), seen = self._prepare(root)
        self.assertEqual(work, root / "run-task-150")
        add = [c for c in seen if c[:4] == ["git", "-C", seen[0][2], "worktree"] and "add" in c]
        self.assertTrue(any(str(root / "run-task-150") in c for c in add))

    def test_encendida_sin_raiz_provisionada_falla(self):
        self.sandbox()
        self.fake.WORK_ROOT = str(Path(self.tmp.name) / "no-existe")
        with self.assertRaises(RuntimeError) as ctx:
            self._prepare(None)
        self.assertIn("no provisionada", str(ctx.exception))

    # --- estáticas
    def test_estatico_la_caja_se_carga_perezosamente(self):
        src = (OPS / "dev-worker.py").read_text(encoding="utf-8")
        self.assertEqual(src.count("dev-sandbox.py"), 1)
        tree = ast.parse(src)
        for node in tree.body:
            if isinstance(node, (ast.Import, ast.ImportFrom)):
                names = [a.name for a in node.names] + [getattr(node, "module", None) or ""]
                self.assertFalse(any("sandbox" in n for n in names), names)

    def test_estatico_el_run_tests_ya_no_llama_a_run_directamente(self):
        tree = ast.parse((OPS / "dev-worker.py").read_text(encoding="utf-8"))
        fn = next(n for n in ast.walk(tree) if isinstance(n, ast.FunctionDef) and n.name == "run_tests")
        direct = [c for c in ast.walk(fn) if isinstance(c, ast.Call) and isinstance(c.func, ast.Name) and c.func.id == "run"]
        self.assertEqual(len(direct), 2, "solo _install y _step (rama sin caja) pueden llamar a run()")

    def test_estatico_el_worktree_solo_va_a_la_caja_con_el_interruptor(self):
        src = (OPS / "dev-worker.py").read_text(encoding="utf-8")
        self.assertIn('os.environ.get("CV_DEV_SANDBOX") == "1"', src)


if __name__ == "__main__":
    unittest.main(verbosity=1)
