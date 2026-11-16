#!/usr/bin/env python3
"""Pruebas del programa del portátil (EVO-LOCAL-001). Sin red, sin SSH, sin modelo real."""
import ast
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_local_runner_under_test", OPS / "local-runner.py")
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)

UUID = "11111111-2222-4333-8444-555555555555"
ITEM = {"public_id": 77, "task_id": UUID, "title": "Aclarar horario", "request": "Anade una linea al horario",
        "allow_paths": ["docs/demo/"], "risk": "guarded"}


class FakeGate:
    def __init__(self):
        self.calls = []
        self.replies = {}

    def __call__(self, command, stdin=None):
        self.calls.append((command, stdin))
        verb = command.split(" ")[0]
        default = {"ok": True, "code": 0}
        if verb == "lista":
            default["items"] = [ITEM]
        elif verb == "tarea":
            default.update({"item": ITEM, "reservada_hasta": "2099-01-01T00:00:00Z"})
        elif verb == "latido":
            default.update({"hora": "2026-11-16T05:00:00Z", "encendida": False})
        elif verb == "entregar":
            default.update({"estado": "triaged", "auto_merge": False})
        return self.replies.get(verb, default)

    def verbs(self):
        return [c[0].split(" ")[0] for c in self.calls]


def good_model(messages, model, host):
    return json.dumps({"title": "Horario", "reason": "Pedido", "operations": [
        {"op": "text_replace", "path": "docs/demo/horario.md", "old": "Horario: 9:00 a 17:00.", "new": "Horario: 9:00 a 17:00.\nDomingos cerrado.", "expected_count": 1}]})


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name) / "repo"
        (self.repo / "docs" / "demo").mkdir(parents=True)
        (self.repo / "docs" / "demo" / "horario.md").write_text("Casa Viva abre de lunes a sabado.\nHorario: 9:00 a 17:00.\n", encoding="utf-8")
        self.out = Path(self.tmp.name) / "out"
        self.gate = FakeGate()
        runner.ssh_gate = self.gate

    def tearDown(self):
        self.tmp.cleanup()

    def proponer(self, deliver, chat=good_model):
        buf = io.StringIO()
        with redirect_stdout(buf):
            code = runner.cmd_proponer(77, deliver, self.repo, self.out, runner.DEFAULT_MODEL, runner.proposer.DEFAULT_HOST, chat=chat)
        return code, buf.getvalue()

    def test_lista_solo_lee(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            self.assertEqual(runner.cmd_lista(), 0)
        self.assertEqual(self.gate.verbs(), ["lista"])
        self.assertIn("#77", buf.getvalue())

    def test_lista_vacia(self):
        self.gate.replies["lista"] = {"ok": True, "code": 0, "items": []}
        buf = io.StringIO()
        with redirect_stdout(buf):
            self.assertEqual(runner.cmd_lista(), 0)
        self.assertIn("No hay tareas", buf.getvalue())

    def test_proponer_guarda_y_no_entrega_por_defecto(self):
        code, text = self.proponer(deliver=False)
        self.assertEqual(code, 0)
        self.assertEqual(self.gate.verbs(), ["tarea"])
        self.assertIn("No se entregó", text)
        saved = list((self.out / "tarea-77").glob("lp-*.json"))
        self.assertEqual(len(saved), 1)
        self.assertEqual(json.loads(saved[0].read_text(encoding="utf-8"))["task_id"], UUID)

    def test_proponer_con_entregar(self):
        code, text = self.proponer(deliver=True)
        self.assertEqual(code, 0)
        self.assertEqual(self.gate.verbs(), ["tarea", "entregar"])
        command, body = self.gate.calls[-1]
        self.assertEqual(command, "entregar 77")
        sent = json.loads(body.decode("utf-8"))
        self.assertEqual(sent["task_public_id"], 77)
        self.assertEqual(sent["repo"], "casa-viva")
        self.assertIn("pendiente de aprobación humana", text)

    def test_entregar_guardada_tras_revisar(self):
        self.proponer(deliver=False)
        saved = next((self.out / "tarea-77").glob("lp-*.json"))
        self.gate.calls.clear()
        with redirect_stdout(io.StringIO()):
            self.assertEqual(runner.cmd_entregar_guardada(77, saved), 0)
        self.assertEqual(self.gate.verbs(), ["entregar"])
        self.assertEqual(self.gate.calls[0][1], saved.read_bytes())

    def test_entregar_guardada_valida_el_archivo(self):
        bad = Path(self.tmp.name) / "mala.json"
        bad.write_text("no es json", encoding="utf-8")
        with self.assertRaises(runner.RunnerError) as ctx:
            runner.cmd_entregar_guardada(77, bad)
        self.assertEqual(ctx.exception.code, runner.EXIT_USAGE)
        self.proponer(deliver=False)
        saved = next((self.out / "tarea-77").glob("lp-*.json"))
        self.gate.calls.clear()
        with self.assertRaises(runner.RunnerError):
            runner.cmd_entregar_guardada(78, saved)  # propuesta de otra tarea
        with self.assertRaises(runner.RunnerError):
            runner.cmd_entregar_guardada(77, Path(self.tmp.name) / "no-existe.json")
        self.assertNotIn("entregar", self.gate.verbs())

    def test_la_puerta_rechaza_la_reserva(self):
        self.gate.replies["tarea"] = {"ok": False, "code": 4, "reason": "tarea reservada"}
        with self.assertRaises(runner.RunnerError) as ctx:
            self.proponer(deliver=True)
        self.assertEqual(ctx.exception.code, runner.EXIT_GATE)
        self.assertEqual(self.gate.verbs(), ["tarea"])

    def test_la_puerta_rechaza_la_entrega(self):
        self.gate.replies["entregar"] = {"ok": False, "code": 6, "reason": "fuera de las rutas de la tarea"}
        with self.assertRaises(runner.RunnerError) as ctx:
            self.proponer(deliver=True)
        self.assertEqual(ctx.exception.code, runner.EXIT_GATE)
        self.assertIn("rutas", ctx.exception.message)

    def test_modelo_malo_no_entrega(self):
        for bad in (lambda m, mo, h: "no es json",
                    lambda m, mo, h: json.dumps({"operations": [{"op": "run_command", "path": "docs/demo/horario.md", "content": "id"}]}),
                    lambda m, mo, h: json.dumps({"operations": [{"op": "text_replace", "path": "docs/demo/horario.md", "old": "no existe", "new": "x"}]})):
            self.gate.calls.clear()
            with self.assertRaises(runner.RunnerError) as ctx:
                self.proponer(deliver=True, chat=bad)
            self.assertEqual(ctx.exception.code, runner.EXIT_GATE)
            self.assertNotIn("entregar", self.gate.verbs())

    def test_modelo_apagado_da_entorno(self):
        def down(messages, model, host):
            raise runner.proposer.ModelUnavailable("apagado")
        with self.assertRaises(runner.RunnerError) as ctx:
            self.proponer(deliver=True, chat=down)
        self.assertEqual(ctx.exception.code, runner.EXIT_ENV)
        self.assertNotIn("entregar", self.gate.verbs())

    def test_latido(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            self.assertEqual(runner.cmd_latido("qwen2.5-coder:7b"), 0)
        command, body = self.gate.calls[0]
        self.assertEqual(command, "latido")
        self.assertEqual(json.loads(body.decode("utf-8")), {"modelo": "qwen2.5-coder:7b", "version": runner.VERSION})
        self.assertIn("apagada", buf.getvalue())

    def test_main_codigos_de_salida(self):
        err = io.StringIO()
        self.gate.replies["lista"] = {"ok": False, "code": 7, "reason": "puerta apagada"}
        with redirect_stderr(err), redirect_stdout(io.StringIO()):
            self.assertEqual(runner.main(["lista"]), runner.EXIT_GATE)
            self.assertEqual(runner.main(["proponer", "0"]), runner.EXIT_USAGE)
        self.assertIn("puerta apagada", err.getvalue())

    def test_main_no_muestra_trazas(self):
        def boom(command, stdin=None):
            raise RuntimeError("detalle-interno-xyz")
        runner.ssh_gate = boom
        err = io.StringIO()
        with redirect_stderr(err), redirect_stdout(io.StringIO()):
            self.assertEqual(runner.main(["lista"]), runner.EXIT_INTERNAL)
        self.assertNotIn("detalle-interno-xyz", err.getvalue())

    # --- la única llamada externa
    def test_ssh_solo_ordenes_de_la_lista_blanca(self):
        real = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(real)
        called = []
        real.subprocess.run = lambda *a, **k: called.append((a, k))
        for bad in ("", "ls", "lista; id", "tarea", "tarea 0", "entregar -1", "bash", "lista\nid"):
            with self.assertRaises(real.RunnerError):
                real.ssh_gate(bad)
        self.assertEqual(called, [])

    def test_ssh_construye_la_orden_sin_shell(self):
        real = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(real)
        seen = {}

        class Done:
            stdout = b'{"ok": true, "code": 0, "items": []}\n'

        def fake_run(args, **kw):
            seen["args"], seen["kw"] = args, kw
            return Done()
        real.subprocess.run = fake_run
        self.assertTrue(real.ssh_gate("tarea 77")["ok"])
        self.assertEqual(seen["args"], ["ssh", "-o", "BatchMode=yes", "vps-gate", "tarea 77"])
        self.assertNotIn("shell", seen["kw"])
        self.assertIsInstance(seen["args"], list)

    def test_ssh_sin_conexion_o_respuesta_rara(self):
        real = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(real)

        def timeout(*a, **k):
            raise real.subprocess.TimeoutExpired("ssh", 1)
        real.subprocess.run = timeout
        with self.assertRaises(real.RunnerError) as ctx:
            real.ssh_gate("lista")
        self.assertEqual(ctx.exception.code, real.EXIT_ENV)

        class Junk:
            stdout = b"Permission denied (publickey).\n"
        real.subprocess.run = lambda *a, **k: Junk()
        with self.assertRaises(real.RunnerError) as ctx:
            real.ssh_gate("lista")
        self.assertEqual(ctx.exception.code, real.EXIT_ENV)

    # --- estáticas
    def test_estatico_una_sola_llamada_externa(self):
        tree = ast.parse((OPS / "local-runner.py").read_text(encoding="utf-8"))
        runs, bad_calls = [], []
        for node in ast.walk(tree):
            if isinstance(node, ast.Call):
                fn = node.func
                if isinstance(fn, ast.Attribute) and isinstance(fn.value, ast.Name) and fn.value.id == "subprocess":
                    runs.append((fn.attr, [k.arg for k in node.keywords]))
                name = fn.id if isinstance(fn, ast.Name) else getattr(fn, "attr", "")
                if name in ("eval", "exec", "system", "Popen", "popen", "__import__") :
                    bad_calls.append(name)
        self.assertEqual([r[0] for r in runs], ["run"])
        self.assertNotIn("shell", runs[0][1])
        self.assertEqual(bad_calls, [])

    def test_estatico_nadie_mas_lo_conoce(self):
        for name in ("opencode-executor.py", "opencode-proposal-bridge.py", "dev-worker.py", "cv-proposal-gate.py"):
            self.assertNotIn("local-runner", (OPS / name).read_text(encoding="utf-8"), name)


if __name__ == "__main__":
    unittest.main(verbosity=1)
