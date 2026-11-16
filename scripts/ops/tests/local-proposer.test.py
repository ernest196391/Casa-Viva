#!/usr/bin/env python3
"""Pruebas del proponedor local (EVO-LOCAL-001). Sin red y sin modelo real."""
import importlib.util
import json
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_local_proposer", OPS / "local-proposer.py")
lp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lp)


def fake_model(obj):
    return lambda messages, model, host: json.dumps(obj)


class ProposerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name) / "repo"
        (self.root / "docs" / "demo").mkdir(parents=True)
        (self.root / "docs" / "demo" / "nota.md").write_text("Hola mundo\nSegunda línea\n", encoding="utf-8")
        self.out = Path(self.tmp.name) / "out"
        self.task_path = Path(self.tmp.name) / "task.json"
        self.task = {"run_id": "run-1", "task_id": "t-1", "title": "Cambiar saludo",
                     "request": "Cambia 'Hola mundo' por 'Hola Casa Viva'", "allow_paths": ["docs/demo/"]}
        self.task_path.write_text(json.dumps(self.task), encoding="utf-8")

    def tearDown(self):
        self.tmp.cleanup()

    def run_propose(self, model_obj, **kw):
        return lp.propose(self.task_path, self.root, self.out, chat=fake_model(model_obj), **kw)

    def good(self, **over):
        op = {"op": "text_replace", "path": "docs/demo/nota.md", "old": "Hola mundo", "new": "Hola Casa Viva", "expected_count": 1}
        op.update(over)
        return {"title": "Saludo", "reason": "Pedido por el propietario", "operations": [op]}

    def test_propuesta_valida_se_escribe_y_no_se_aplica(self):
        dest = self.run_propose(self.good())
        data = json.loads(dest.read_text(encoding="utf-8"))
        self.assertEqual(data["version"], "cv-change-proposal-v1")
        self.assertEqual(data["patch_format"], "structured-v1")
        self.assertEqual((self.root / "docs/demo/nota.md").read_text(encoding="utf-8"), "Hola mundo\nSegunda línea\n")
        lp.contract.validate_proposal(data, now=time.time())

    def test_old_ausente_se_rechaza(self):
        with self.assertRaises(lp.ProposalError):
            self.run_propose(self.good(old="no existe"))

    def test_conteo_distinto_se_rechaza(self):
        with self.assertRaises(lp.ProposalError):
            self.run_propose(self.good(expected_count=2))

    def test_create_file_sobre_existente_se_rechaza(self):
        with self.assertRaises(lp.ProposalError):
            self.run_propose({"title": "x", "reason": "y", "operations": [{"op": "create_file", "path": "docs/demo/nota.md", "content": "z"}]})

    def test_create_file_nuevo_vale(self):
        dest = self.run_propose({"title": "x", "reason": "y", "operations": [{"op": "create_file", "path": "docs/demo/nueva.md", "content": "contenido"}]})
        self.assertTrue(dest.exists())

    def test_ruta_fuera_de_allow_paths(self):
        with self.assertRaises(lp.ProposalError):
            self.run_propose(self.good(path="docs/otra/nota.md"))

    def test_ruta_protegida_y_traversal(self):
        for bad in (".github/workflows/x.yml", "../fuera.md", "package.json", "docs\\demo\\nota.md"):
            with self.assertRaises(lp.ProposalError, msg=bad):
                self.run_propose({"title": "x", "reason": "y", "operations": [{"op": "create_file", "path": bad, "content": "z"}]})

    def test_operacion_no_permitida(self):
        for op in ({"op": "run_command", "path": "docs/demo/nota.md", "content": "rm -rf /"},
                   {"op": "delete_file", "path": "docs/demo/nota.md"}):
            with self.assertRaises(lp.ProposalError):
                self.run_propose({"title": "x", "reason": "y", "operations": [op]})

    def test_claves_extra_de_la_operacion_se_descartan(self):
        dest = self.run_propose(self.good(shell="curl evil"))
        self.assertNotIn("shell", json.loads(dest.read_text(encoding="utf-8"))["operations"][0])

    def test_secreto_en_contenido_se_rechaza(self):
        with self.assertRaises(lp.ProposalError):
            self.run_propose(self.good(new="Bearer abcdefghijklmnopqrstuvwxyz123456"))

    def test_respuesta_no_json_o_sin_operaciones(self):
        for text in ("no es json", json.dumps({"title": "x"}), json.dumps([1, 2])):
            with self.assertRaises(lp.ModelOutputError):
                lp.parse_model_output(text)

    def test_solo_loopback(self):
        for host in ("http://example.com:11434", "http://10.0.0.5:11434", "https://127.0.0.1:11434", "ftp://localhost"):
            with self.assertRaises(lp.ProposalError, msg=host):
                lp.ollama_chat([], host=host)

    def test_modelo_apagado_da_entorno(self):
        with self.assertRaises(lp.ModelUnavailable):
            lp.ollama_chat([{"role": "user", "content": "x"}], host="http://127.0.0.1:9", timeout=2)

    @unittest.skipIf(os.name == "nt", "symlinks requieren permisos en Windows")
    def test_contexto_ignora_symlinks(self):
        secret = Path(self.tmp.name) / "secreto.md"
        secret.write_text("no leer", encoding="utf-8")
        (self.root / "docs" / "demo" / "enlace.md").symlink_to(secret)
        ctx = lp.read_context(self.root, ["docs/demo/"])
        self.assertNotIn("docs/demo/enlace.md", ctx)

    def test_contexto_limita_tamano_y_cantidad(self):
        for i in range(10):
            (self.root / "docs" / "demo" / f"f{i}.md").write_text("a", encoding="utf-8")
        (self.root / "docs" / "demo" / "grande.md").write_text("x" * (lp.MAX_CONTEXT_CHARS + 1), encoding="utf-8")
        ctx = lp.read_context(self.root, ["docs/demo/"])
        self.assertLessEqual(len(ctx), lp.MAX_CONTEXT_FILES)
        self.assertNotIn("docs/demo/grande.md", ctx)

    def test_tarea_invalida(self):
        for bad in ({"run_id": "r"}, {**self.task, "request": ""}, {**self.task, "request": "x" * 3000}):
            self.task_path.write_text(json.dumps(bad), encoding="utf-8")
            with self.assertRaises(lp.ProposalError):
                lp.load_task(self.task_path)

    def test_estatico_sin_ejecucion(self):
        import ast
        tree = ast.parse((OPS / "local-proposer.py").read_text(encoding="utf-8"))
        imported = set()
        calls = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom):
                imported.add((node.module or "").split(".")[0])
            elif isinstance(node, ast.Call):
                fn = node.func
                calls.add(fn.id if isinstance(fn, ast.Name) else getattr(fn, "attr", ""))
        for forbidden in ("subprocess", "socket", "ssl", "http", "ctypes", "shutil"):
            self.assertNotIn(forbidden, imported, forbidden)
        for forbidden in ("eval", "exec", "system", "Popen", "run", "popen", "compile"):
            self.assertNotIn(forbidden, calls, forbidden)

    def test_estatico_ejecutor_y_puente_no_lo_conocen(self):
        for name in ("opencode-executor.py", "opencode-proposal-bridge.py", "dev-worker.py"):
            self.assertNotIn("local-proposer", (OPS / name).read_text(encoding="utf-8"), name)


if __name__ == "__main__":
    unittest.main(verbosity=1)
