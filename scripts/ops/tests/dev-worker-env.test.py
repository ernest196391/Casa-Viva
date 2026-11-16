#!/usr/bin/env python3
"""Pruebas del entorno limpio de los procesos hijos del Dev Worker (EVO-LOCAL-001)."""
import importlib.util
import os
import sys
import types
import unittest
from pathlib import Path

for _name in ("fcntl", "resource", "pwd", "grp"):
    try:
        __import__(_name)
    except ImportError:
        sys.modules[_name] = types.ModuleType(_name)

OPS = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("cv_dev_worker_env_under_test", OPS / "dev-worker.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

SECRETS = {
    "SUPABASE_SERVICE_KEY": "s1", "WOO_KEY": "s2", "WOO_SECRET": "s3", "OPENAI_API_KEY": "s4",
    "ANTHROPIC_API_KEY": "s5", "GEMINI_API_KEY": "s6", "GROQ_API_KEY": "s7", "ELEVENLABS_API_KEY": "s8",
    "CURRU_KEY": "s9", "IMAGEN_PANEL_KEY": "s10", "GITHUB_TOKEN": "s11", "DB_PASSWORD": "s12", "CLIENT_CREDENTIALS": "s13",
}
BASE = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LANG": "C.UTF-8", "USER": "root", "SUPABASE_URL": "https://x.supabase.co",
        "WOO_URL": "https://shop", "BUSINESS_SLUG": "casa-viva", "SSH_AUTH_SOCK": "/tmp/agent"}


class ChildEnvTests(unittest.TestCase):
    def test_los_secretos_no_pasan(self):
        env = worker.child_env({**BASE, **SECRETS})
        for name in SECRETS:
            self.assertNotIn(name, env, name)
        self.assertFalse(any(worker.SECRET_NAME_RE.search(k) for k in env), sorted(env))

    def test_lo_que_git_y_npm_necesitan_si_pasa(self):
        env = worker.child_env(BASE)
        self.assertEqual(env["PATH"], "/usr/bin:/bin")
        self.assertEqual(env["HOME"], "/root")  # git push usa ~/.ssh/config
        self.assertEqual(env["LANG"], "C.UTF-8")

    def test_lista_blanca_estricta(self):
        env = worker.child_env(BASE)
        self.assertTrue(set(env) <= set(worker.CHILD_ENV_KEYS))
        for noisy in ("SUPABASE_URL", "WOO_URL", "BUSINESS_SLUG", "SSH_AUTH_SOCK"):
            self.assertNotIn(noisy, env)

    def test_path_por_defecto_si_falta(self):
        self.assertEqual(worker.child_env({})["PATH"], worker.DEFAULT_CHILD_PATH)

    def test_extra_permite_variables_no_secretas(self):
        env = worker.child_env({**BASE, "CV_DEV_CHILD_ENV_EXTRA": "BUSINESS_SLUG, SUPABASE_URL"})
        self.assertEqual(env["BUSINESS_SLUG"], "casa-viva")
        self.assertEqual(env["SUPABASE_URL"], "https://x.supabase.co")

    def test_extra_nunca_deja_pasar_secretos_ni_nombres_raros(self):
        extra = ",".join(["OPENAI_API_KEY", "WOO_SECRET", "GITHUB_TOKEN", "DB_PASSWORD", "CLIENT_CREDENTIALS", "lower", "A;B", "X" * 80, "NO_EXISTE"])
        env = worker.child_env({**BASE, **SECRETS, "CV_DEV_CHILD_ENV_EXTRA": extra})
        self.assertTrue(set(env) <= set(worker.CHILD_ENV_KEYS))

    def test_el_interruptor_extra_no_se_reenvia(self):
        env = worker.child_env({**BASE, "CV_DEV_CHILD_ENV_EXTRA": "BUSINESS_SLUG"})
        self.assertNotIn("CV_DEV_CHILD_ENV_EXTRA", env)

    @unittest.skipIf(os.name == "nt", "usa python del sistema con entorno POSIX")
    def test_run_real_no_expone_secretos(self):
        old = dict(os.environ)
        try:
            os.environ.update(SECRETS)
            rc, out = worker.run([sys.executable, "-c", "import os,json;print(json.dumps(sorted(os.environ)))"])
        finally:
            os.environ.clear()
            os.environ.update(old)
        self.assertEqual(rc, 0)
        for name in SECRETS:
            self.assertNotIn(name, out, name)

    def test_run_pasa_env_a_subprocess(self):
        seen = {}

        class Done:
            returncode, stdout, stderr = 0, "ok", ""

        def fake(cmd, **kw):
            seen.update(kw)
            return Done()
        old = worker.subprocess.run
        worker.subprocess.run = fake
        try:
            old_env = dict(os.environ)
            os.environ.update(SECRETS)
            worker.run(["git", "status"])
        finally:
            worker.subprocess.run = old
            os.environ.clear()
            os.environ.update(old_env)
        self.assertIn("env", seen)
        for name in SECRETS:
            self.assertNotIn(name, seen["env"])

    def test_todas_las_llamadas_a_subprocess_del_worker_pasan_por_run(self):
        import ast
        tree = ast.parse((OPS / "dev-worker.py").read_text(encoding="utf-8"))
        direct = []
        for fn in [n for n in ast.walk(tree) if isinstance(n, ast.FunctionDef)]:
            for node in ast.walk(fn):
                if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) \
                        and node.func.value.id == "subprocess" and node.func.attr in ("run", "Popen", "call", "check_output", "check_call"):
                    direct.append((fn.name, node.func.attr))
        self.assertEqual(direct, [("run", "run")], "solo run() puede lanzar procesos")


if __name__ == "__main__":
    unittest.main(verbosity=1)
