#!/usr/bin/env python3
"""Pruebas de la puerta de propuestas (EVO-LOCAL-001). Sin red, sin Supabase real."""
import ast
import importlib.util
import io
import json
import sys
import tempfile
import time
import types
import unittest
from pathlib import Path

for _name in ("fcntl", "resource", "pwd", "grp"):
    try:
        __import__(_name)
    except ImportError:
        sys.modules[_name] = types.ModuleType(_name)

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_gate_under_test", OPS / "cv-proposal-gate.py")
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

TASK_UUID = "11111111-2222-4333-8444-555555555555"


class FakeDb:
    def __init__(self):
        self.settings = {"local_proposer": "si"}
        self.tasks = [{
            "id": TASK_UUID, "public_id": 77, "title": "Aclarar horario", "risk": "guarded",
            "payload": {"proposal_request": {"request": "Anade una linea al horario", "allow_paths": ["docs/demo/"]}},
        }]
        self.calls = []
        self.fail_rpc = False

    def api(self, method, path, body=None, prefer=None):
        self.calls.append((method, path.split("?")[0], body))
        if path.startswith("crm_settings?select=value"):
            key = path.split("key=eq.")[1]
            return [{"value": self.settings[key]}] if key in self.settings else []
        if path.startswith("ops_tasks?select"):
            if "public_id=eq." in path:
                pid = int(path.split("public_id=eq.")[1])
                return [t for t in self.tasks if t["public_id"] == pid]
            return list(self.tasks)
        if path.startswith("rpc/ops_prepare_dev_patch"):
            if self.fail_rpc:
                raise RuntimeError("task cerrada")
            return [{"task_public_id": body["p_task_public_id"], "status": "triaged", "ready": True}]
        if path.startswith("crm_settings?on_conflict"):
            self.settings[body["key"]] = body["value"]
            return None
        raise AssertionError(f"llamada inesperada: {method} {path}")


class GateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        gate.STATE = Path(self.tmp.name)
        self.db = FakeDb()
        gate.dev_worker.api = self.db.api
        self.now = 1_800_000_000.0
        gate._clock = lambda: self.now

    def tearDown(self):
        self.tmp.cleanup()

    def say(self, command, stdin=""):
        sys.stdin = types.SimpleNamespace(buffer=io.BytesIO(stdin.encode("utf-8") if isinstance(stdin, str) else stdin))
        return gate.run(command)

    def proposal(self, **over):
        created = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(self.now))
        expires = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(self.now + 2700))
        base = {
            "version": "cv-change-proposal-v1", "proposal_id": "lp-run-77-abc", "run_id": "run-77", "task_id": TASK_UUID,
            "task_public_id": 77, "repo": "casa-viva", "title": "Horario", "reason": "Pedido",
            "allow_paths": ["docs/demo/"], "patch_format": "structured-v1", "created_at": created, "expires_at": expires,
            "operations": [{"op": "create_file", "path": "docs/demo/horario.md", "content": "Domingos cerrado.\n"}],
        }
        base.update(over)
        return json.dumps(base)

    # --- lista blanca de órdenes
    def test_ordenes_no_permitidas(self):
        for bad in ("", "ls", "lista; id", "lista extra", "LISTA", "tarea", "tarea 0", "tarea 01", "tarea 5 6",
                    "entregar -1", "tarea 1\nid", "latido x", "bash -c id", "tarea 1234567890123"):
            code, out = self.say(bad)
            self.assertEqual(code, gate.EXIT_USAGE, repr(bad))
            self.assertFalse(out["ok"])

    def test_apagada_por_defecto(self):
        for off in (None, "no", "SI", ""):
            self.db.settings.pop("local_proposer", None)
            if off is not None:
                self.db.settings["local_proposer"] = off
            for cmd in ("lista", "tarea 77"):
                self.assertEqual(self.say(cmd)[0], gate.EXIT_ENV, (off, cmd))
        self.assertEqual(self.say("entregar 77", self.proposal())[0], gate.EXIT_ENV)

    # --- lista y tarea
    def test_lista_solo_tareas_que_lo_piden(self):
        self.db.tasks += [
            {"id": "a", "public_id": 1, "title": "sin petición", "payload": {"tipo": "entendio_mal"}},
            {"id": "b", "public_id": 2, "title": "ya con parche", "payload": {"dev": {"ready": True}, "proposal_request": {"request": "x", "allow_paths": ["docs/"]}}},
            {"id": "c", "public_id": 3, "title": "ruta de código", "payload": {"proposal_request": {"request": "x", "allow_paths": ["src/"]}}},
            {"id": "d", "public_id": 4, "title": "sin rutas", "payload": {"proposal_request": {"request": "x", "allow_paths": []}}},
        ]
        code, out = self.say("lista")
        self.assertEqual(code, 0)
        self.assertEqual([i["public_id"] for i in out["items"]], [77])

    def test_lista_redacta_secretos(self):
        self.db.tasks[0]["payload"]["proposal_request"]["request"] = "usa Bearer abcdefghijklmnopqrstuvwxyz123456 aqui"
        out = self.say("lista")[1]
        self.assertNotIn("abcdefghijklmnopqrstuvwxyz123456", json.dumps(out))

    def test_tarea_reserva_y_conflicto_y_caducidad(self):
        self.assertEqual(self.say("tarea 77")[0], 0)
        self.assertEqual(self.say("tarea 77")[0], gate.EXIT_BUSY)
        self.now += gate.RESERVE_SECONDS + 1
        self.assertEqual(self.say("tarea 77")[0], 0)

    def test_tarea_inexistente(self):
        self.assertEqual(self.say("tarea 999")[0], gate.EXIT_CONTRACT)

    # --- entregar
    def test_entrega_correcta(self):
        self.say("tarea 77")
        code, out = self.say("entregar 77", self.proposal())
        self.assertEqual(code, 0, out)
        rpc = [c for c in self.db.calls if c[1] == "rpc/ops_prepare_dev_patch"][0][2]
        self.assertFalse(rpc["p_auto_merge"])
        self.assertEqual(rpc["p_test_profile"], "casa-viva")
        self.assertEqual(rpc["p_repo"], "casa-viva")
        self.assertEqual(rpc["p_provider"], "local-proposer")

    def test_entrega_sin_reserva_o_caducada(self):
        self.assertEqual(self.say("entregar 77", self.proposal())[0], gate.EXIT_NORESERVE)
        self.say("tarea 77")
        self.now += gate.RESERVE_SECONDS + 1
        self.assertEqual(self.say("entregar 77", self.proposal(expires_at="2099-01-01T00:00:00Z"))[0], gate.EXIT_NORESERVE)

    def test_duplicada_es_idempotente(self):
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", self.proposal())[0], 0)
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", self.proposal(proposal_id="lp-run-77-zzz"))[0], gate.EXIT_BUSY)

    def test_tarea_distinta_o_repo_distinto(self):
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", self.proposal(task_id="otra"))[0], gate.EXIT_CONTRACT)
        self.assertEqual(self.say("entregar 77", self.proposal(task_public_id=78))[0], gate.EXIT_CONTRACT)
        self.assertEqual(self.say("entregar 77", self.proposal(repo="vivabot"))[0], gate.EXIT_CONTRACT)

    def test_rutas_fuera_de_docs_o_de_la_tarea(self):
        self.say("tarea 77")
        for path in ("src/app.js", "tests/x.test.js", "scripts/x.py", "docs/otra/nota.md", "docs/../src/a.js", ".github/w.yml"):
            body = self.proposal(operations=[{"op": "create_file", "path": path, "content": "x"}],
                                 allow_paths=["docs/"] if path.startswith("docs") else [path.split("/")[0] + "/"])
            code, out = self.say("entregar 77", body)
            self.assertIn(code, (gate.EXIT_PATH, gate.EXIT_CONTRACT), (path, out))

    def test_operaciones_peligrosas_y_secretos(self):
        self.say("tarea 77")
        bad_ops = (
            [{"op": "run_command", "path": "docs/demo/a.md", "content": "id"}],
            [{"op": "delete_file", "path": "docs/demo/a.md"}],
            [{"op": "create_file", "path": "docs/demo/a.md", "content": "Bearer abcdefghijklmnopqrstuvwxyz123456"}],
        )
        for ops in bad_ops:
            self.assertNotEqual(self.say("entregar 77", self.proposal(operations=ops))[0], 0)
        self.assertFalse([c for c in self.db.calls if c[1] == "rpc/ops_prepare_dev_patch"])

    def test_cuerpo_invalido_o_enorme(self):
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", "no es json")[0], gate.EXIT_CONTRACT)
        self.assertEqual(self.say("entregar 77", b"x" * (gate.MAX_BODY + 5))[0], gate.EXIT_CONTRACT)

    def test_tope_por_hora(self):
        for i in range(gate.MAX_PER_HOUR):
            gate.STATE.joinpath("ledger").mkdir(exist_ok=True)
        stamps = [self.now - 60 * i for i in range(gate.MAX_PER_HOUR)]
        (gate.STATE / "rate.json").write_text(json.dumps(stamps))
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", self.proposal())[0], gate.EXIT_BUSY)
        self.now += 3600
        self.say("tarea 77")
        self.assertEqual(self.say("entregar 77", self.proposal(expires_at=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(self.now + 2700)), created_at=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(self.now))))[0], 0)

    def test_si_la_base_rechaza_no_se_registra(self):
        self.say("tarea 77")
        self.db.fail_rpc = True
        self.assertEqual(self.say("entregar 77", self.proposal())[0], gate.EXIT_ENV)
        self.db.fail_rpc = False
        self.assertEqual(self.say("entregar 77", self.proposal())[0], 0)

    # --- latido
    def test_latido(self):
        code, out = self.say("latido", json.dumps({"modelo": "qwen2.5-coder:7b", "version": "1.0"}))
        self.assertEqual(code, 0)
        self.assertTrue(out["encendida"])
        stored = json.loads(self.db.settings["programador_local_latido"])
        self.assertEqual(stored["modelo"], "qwen2.5-coder:7b")
        for bad in ({"modelo": "a b", "version": "1"}, {"modelo": "x", "version": "1;2"}, {"modelo": "x"}, []):
            self.assertEqual(self.say("latido", json.dumps(bad))[0], gate.EXIT_CONTRACT)

    def test_registro_sin_cuerpos(self):
        self.say("tarea 77")
        self.say("entregar 77", self.proposal())
        log = (gate.STATE / "gate.log").read_text(encoding="utf-8")
        self.assertIn('"tarea": 77', log)
        self.assertNotIn("Domingos cerrado", log)

    def test_error_interno_no_filtra_detalles(self):
        def boom(*a, **k):
            raise RuntimeError("secreto-interno-xyz")
        gate.dev_worker.api = boom
        code, out = self.say("lista")
        self.assertEqual(code, gate.EXIT_INTERNAL)
        self.assertNotIn("secreto-interno-xyz", json.dumps(out))

    # --- estáticas
    def test_estatico_sin_ejecucion(self):
        tree = ast.parse((OPS / "cv-proposal-gate.py").read_text(encoding="utf-8"))
        imported, calls, attrs = set(), set(), set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom):
                imported.add((node.module or "").split(".")[0])
            elif isinstance(node, ast.Call):
                fn = node.func
                if isinstance(fn, ast.Name):
                    calls.add(fn.id)  # funciones incorporadas: eval, exec, compile...
                else:
                    attrs.add(getattr(fn, "attr", ""))  # os.system, re.compile (inocuo)...
        for forbidden in ("subprocess", "socket", "ssl", "ctypes", "shutil", "pickle"):
            self.assertNotIn(forbidden, imported, forbidden)
        for forbidden in ("eval", "exec", "compile", "__import__"):
            self.assertNotIn(forbidden, calls, forbidden)
        for forbidden in ("system", "Popen", "popen", "run", "call", "check_output"):
            self.assertNotIn(forbidden, attrs, forbidden)

    def test_estatico_ejecutor_y_worker_no_la_conocen(self):
        for name in ("opencode-executor.py", "opencode-proposal-bridge.py", "dev-worker.py", "local-proposer.py"):
            self.assertNotIn("cv-proposal-gate", (OPS / name).read_text(encoding="utf-8"), name)


if __name__ == "__main__":
    unittest.main(verbosity=1)
