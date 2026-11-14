#!/usr/bin/env python3
"""Pruebas de Fase G de la demostración E2E del Control OS.

Cubre: mundo temporal reproducible (repo Git con datos ficticios,
buzón, ledger y tienda simulada), flujo completo solicitud → propuesta
(Fase F) → puente real → ejecutor determinista real (Fase B) →
consumidor (Fase C) → decisión humana, demostración de que aprobar no
despliega (HEAD, ramas, commits, árbol y remotos intactos), defensas
(propuesta maliciosa, duplicados, paquete manipulado tras la entrega,
resultado manipulado antes del consumidor), CLI real de la
demostración y escaneo estático de la fuente (solo git y CLI fijas,
sin red, sin adaptador de OpenCode, sin secretos).

Ejecutar: python3 scripts/ops/tests/opencode-demo-e2e.test.py
"""
import ast
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

HERE = Path(__file__).resolve().parent
OPS = HERE.parent
DEMO_PATH = OPS / "opencode-demo-e2e.py"

import importlib.util

spec = importlib.util.spec_from_file_location("cv_demo_faseg_test", DEMO_PATH)
demo = importlib.util.module_from_spec(spec)
spec.loader.exec_module(demo)

consumer = demo.consumer


class DemoFixture(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.world = demo.build_world(Path(self.tmp.name))

    def tearDown(self):
        self.tmp.cleanup()

    def _git(self, *args):
        return subprocess.run(["git", "-C", str(self.world["repo"]), *args],
                              capture_output=True, text=True, check=True).stdout.strip()


class MundoTemporalTests(DemoFixture):
    def test_repo_temporal_con_datos_ficticios(self):
        pagina = self.world["repo"] / "docs" / "demo" / "pagina-demo.md"
        self.assertTrue(pagina.is_file())
        self.assertIn(demo.HORARIO_ANTIGUO, pagina.read_text(encoding="utf-8"))
        self.assertIn("datos ficticios", pagina.read_text(encoding="utf-8"))
        self.assertEqual(len(self._git("log", "--oneline").splitlines()), 1)
        self.assertEqual(self._git("remote"), "")
        self.assertEqual(self._git("status", "--porcelain", "-uall"), "")

    def test_buzon_provisionado(self):
        for name in ("jobs", "claims", "results", "dead", "work"):
            self.assertTrue((self.world["home"] / name).is_dir())

    def test_registrar_solicitud_deja_run_waiting_ai(self):
        now = time.time()
        demo.registrar_solicitud(self.world, demo.SOLICITUDES[0], now)
        run = self.world["store"].runs["demo-run-1"]
        self.assertEqual(run["status"], consumer.RUN_EXPECTED_STATUS)
        self.assertIn("demo-task-1", self.world["store"].tasks)

    def test_proposal_main_es_text_replace_y_defensas_create_file(self):
        now = time.time()
        main = demo.construir_proposal(demo.SOLICITUDES[0], now)
        self.assertEqual(main["operations"][0]["op"], "text_replace")
        other = demo.construir_proposal(demo.SOLICITUDES[1], now)
        self.assertEqual(other["operations"][0]["op"], "create_file")
        self.assertEqual(main["version"], "cv-change-proposal-v1")


class FlujoCompletoTests(DemoFixture):
    def test_flujo_aprobado_sin_despliegue(self):
        resumen = demo.ejecutar_demostracion(self.world, "approve")
        self.assertEqual(resumen["decision"], "approve")
        self.assertEqual(resumen["estado_run"], "approved")
        self.assertEqual(resumen["estado_tarea"], "approved")
        self.assertEqual(resumen["archivos_afectados"], ["docs/demo/pagina-demo.md"])
        self.assertIn(f"-Horario de atención: {demo.HORARIO_ANTIGUO}", resumen["diff"])
        self.assertIn(f"+Horario de atención: {demo.HORARIO_NUEVO}", resumen["diff"])
        self.assertTrue(all(resumen["repo_intacto"].values()))
        self.assertTrue((self.world["ledger"] / "demo-prop-1.json").is_file())
        # Aprobar no despliega: sin commit, rama, push, merge ni cambios.
        self.assertEqual(self._git("branch", "--list"), "* main")
        self.assertEqual(len(self._git("log", "--oneline").splitlines()), 1)
        self.assertEqual(self._git("status", "--porcelain", "-uall"), "")
        self.assertEqual(self._git("remote"), "")
        # La decisión humana quedó registrada sin aplicar cambios.
        self.assertEqual(self.world["store"].tasks["demo-task-1"]["status"], "approved")
        self.assertEqual(self.world["store"].tasks["demo-task-1"]["approval_required"], False)
        eventos = [e["event_type"] for e in self.world["store"].events]
        self.assertIn("ai_change_approved", eventos)
        self.assertIn("ai_result_validated", eventos)

    def test_flujo_rechazado_cierra_la_run(self):
        resumen = demo.ejecutar_demostracion(self.world, "reject")
        self.assertEqual(resumen["estado_run"], "rejected")
        self.assertEqual(resumen["estado_tarea"], "rejected")
        self.assertTrue(all(resumen["repo_intacto"].values()))
        run = self.world["store"].runs["demo-run-1"]
        self.assertIn("Rechazado", run.get("error", ""))
        eventos = [e["event_type"] for e in self.world["store"].events]
        self.assertIn("ai_change_rejected", eventos)
        self.assertNotIn("ai_change_approved", eventos)

    def test_defensas_en_el_mismo_mundo(self):
        resumen = demo.ejecutar_demostracion(self.world, "approve")
        defensas = resumen["defensas"]
        self.assertTrue(defensas["duplicado_bloqueado"])
        self.assertTrue(defensas["propuesta_maliciosa_rechazada"])
        self.assertEqual(defensas["paquete_manipulado_rechazado"]["run_status"], "failed")
        self.assertEqual(defensas["resultado_manipulado_cuarentena"]["run_status"],
                         consumer.RUN_EXPECTED_STATUS)
        # Cuarentena con motivo visible.
        motivo = self.world["dead"] / "task-run-demo-run-3.json.motivo.txt"
        self.assertTrue(motivo.is_file())
        self.assertIn("base_sha", motivo.read_text(encoding="utf-8"))
        estados = {rid: r["status"] for rid, r in self.world["store"].runs.items()}
        self.assertEqual(estados["demo-run-2"], "failed")
        self.assertEqual(estados["demo-run-3"], consumer.RUN_EXPECTED_STATUS)


class InteraccionHumanaTests(DemoFixture):
    def test_sin_terminal_exige_decision(self):
        self.assertIsNone(demo.pedir_decision())

    def test_main_sin_decision_y_sin_tty_devuelve_2(self):
        with mock.patch.object(sys, "stdin") as fake:
            fake.isatty.return_value = False
            self.assertEqual(demo.main([]), 2)

    def test_main_con_decision_invalida_termina_en_2(self):
        with self.assertRaises(SystemExit) as ctx:
            demo.main(["--decision", "deploy"])
        self.assertEqual(ctx.exception.code, 2)


class CliDemoTests(unittest.TestCase):
    def _run(self, *args):
        return subprocess.run(
            [sys.executable, str(DEMO_PATH), *args],
            capture_output=True, text=True, timeout=600, stdin=subprocess.DEVNULL,
        )

    def _ultimo_resumen(self, output):
        for line in reversed(output.splitlines()):
            if line.startswith("DEMO_RESULT "):
                return json.loads(line[len("DEMO_RESULT "):])
        self.fail("sin línea DEMO_RESULT")

    def test_cli_flujo_aprobado(self):
        proc = self._run("--decision", "approve")
        self.assertEqual(proc.returncode, 0, proc.stderr[-2000:])
        resumen = self._ultimo_resumen(proc.stdout)
        self.assertEqual(resumen["decision"], "approve")
        self.assertEqual(resumen["estado_run"], "approved")
        self.assertTrue(all(resumen["repo_intacto"].values()))
        self.assertIn("Escena 4", proc.stdout)
        self.assertIn("docs/demo/pagina-demo.md", proc.stdout)
        self.assertIn(f"+Horario de atención: {demo.HORARIO_NUEVO}", proc.stdout)

    def test_cli_flujo_rechazado(self):
        proc = self._run("--decision", "reject")
        self.assertEqual(proc.returncode, 0, proc.stderr[-2000:])
        resumen = self._ultimo_resumen(proc.stdout)
        self.assertEqual(resumen["decision"], "reject")
        self.assertEqual(resumen["estado_run"], "rejected")

    def test_cli_sin_decision_devuelve_2(self):
        proc = self._run()
        self.assertEqual(proc.returncode, 2)
        self.assertIn("--decision", proc.stdout + proc.stderr)


class StaticScanTests(unittest.TestCase):
    SOURCE = DEMO_PATH.read_text(encoding="utf-8")
    TREE = ast.parse(SOURCE)

    def test_sin_red_ni_ejecucion_dinamica(self):
        prohibidos = ("urllib", "socket", "http", "ssl", "requests", "ftplib", "telnetlib")
        for node in ast.walk(self.TREE):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    self.assertNotIn(alias.name.split(".")[0], prohibidos)
            if isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], prohibidos)
            if isinstance(node, ast.Call):
                name = getattr(node.func, "id", None) or getattr(node.func, "attr", None)
                self.assertNotIn(name, ("eval", "exec", "compile", "system", "popen"))

    def test_solo_git_y_cli_fijas_de_las_fases(self):
        """Los subprocesos son git y las dos CLI fijas de las fases A-F."""
        permitted = {"opencode-proposal-bridge.py", "opencode-executor.py"}
        for node in ast.walk(self.TREE):
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) \
                    and node.func.attr == "run" \
                    and isinstance(node.func.value, ast.Name) and node.func.value.id == "subprocess":
                first = node.args[0]
                constants = [
                    n.value for n in ast.walk(first)
                    if isinstance(n, ast.Constant) and isinstance(n.value, str)
                ]
                scripts = {c for c in constants if c.endswith(".py")}
                self.assertLessEqual(scripts, permitted, f"script no permitido: {scripts}")
                for banned in ("push", "merge", "clone", "fetch", "rebase"):
                    self.assertNotIn(banned, constants)

    def test_adaptador_de_opcode_no_participa(self):
        self.assertNotIn("opencode-adapter", self.SOURCE)
        self.assertNotIn("opencode_adapter", self.SOURCE)
        self.assertNotIn("CV_OPENCODE_MODEL_ENABLED", self.SOURCE)
        self.assertNotIn("CV_OPENCODE_SANDBOX_ATTESTED", self.SOURCE)
        # La demo menciona la propuesta de sandbox solo como NO instalada;
        # ningún subproceso lanza Bubblewrap ni AppArmor (cubierto por
        # test_solo_git_y_cli_fijas_de_las_fases).
        self.assertNotIn("apparmor_parser", self.SOURCE)

    def test_sin_secretos_ni_rutas_reales_del_repo(self):
        # Patrones de credencial (con contenido tras el prefijo), no
        # prefijos sueltos que colisionan con identificadores legítimos.
        for pattern in (r"sk-[A-Za-z0-9]{20}", r"sbp_[A-Za-z0-9]{12}",
                        r"Bearer [A-Za-z0-9]{20}", r"eyJ[A-Za-z0-9_-]{20}"):
            self.assertIsNone(re.search(pattern, self.SOURCE), pattern)
        self.assertNotIn("/root", self.SOURCE)
        self.assertNotIn("/home/cvdev", self.SOURCE)

    def test_reutiliza_las_fases_reales(self):
        self.assertIn("opencode-result-consumer.py", self.SOURCE)
        self.assertIn("opencode-proposal.py", self.SOURCE)
        self.assertIn("opencode-proposal-bridge.py", self.SOURCE)
        self.assertIn("opencode-executor.py", self.SOURCE)
        self.assertIn("record_decision", self.SOURCE)
        self.assertIn("cv-change-proposal-v1", self.SOURCE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
