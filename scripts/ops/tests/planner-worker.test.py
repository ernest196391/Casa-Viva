#!/usr/bin/env python3
import importlib.util
import json
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
PATH = HERE.parent / "planner-worker.py"
spec = importlib.util.spec_from_file_location("cv_planner_worker", PATH)
planner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(planner)


class PlannerPolicyTests(unittest.TestCase):
    def test_repo_routing(self):
        self.assertEqual(planner.choose_repo("corrige VivaBot en WhatsApp"), "vivabot")
        self.assertEqual(planner.choose_repo("ajusta NEXO marketplace"), "nexo")
        self.assertEqual(planner.choose_repo("cambia la pantalla de pedidos de Casa Viva"), "casa-viva")

    def test_defense_in_depth_risk(self):
        self.assertEqual(planner.detected_min_risk("cambia el precio de envío"), "sensitive")
        self.assertEqual(planner.detected_min_risk("cambia una credencial del VPS"), "critical")
        self.assertEqual(planner.detected_min_risk("mejora el texto del botón"), "safe")

    def test_v1_config_read_only(self):
        cfg = json.loads(planner.planner_config())
        permission = cfg["permission"]
        self.assertEqual(permission["*"], "deny")
        self.assertEqual(permission["read"], "allow")
        self.assertEqual(permission["glob"], "allow")
        self.assertEqual(permission["grep"], "allow")
        self.assertEqual(permission["list"], "allow")
        for key in ("edit","bash","task","external_directory","webfetch","websearch","lsp","skill","question"):
            self.assertEqual(permission[key], "deny")

    def test_systemd_command_is_unprivileged_and_read_only(self):
        snap = Path("/home/cvdev/control-os-planner/repos/casa-viva")
        cmd = planner.planner_command(snap, "hola")
        joined = "\n".join(cmd)
        self.assertIn("User=cvdev", joined)
        self.assertIn("ProtectSystem=strict", joined)
        self.assertIn("ProtectHome=read-only", joined)
        self.assertIn("NoNewPrivileges=yes", joined)
        self.assertIn("CapabilityBoundingSet=", joined)
        self.assertIn("--pure", cmd)
        self.assertNotIn("--auto", cmd)

    def test_parse_opencode_json_events(self):
        proposal = {
            "kind":"patch","repo":"casa-viva","reason":"x","summary":"y",
            "allow_paths":["docs/control-os-smoke"],
            "operations":[{"op":"create_file","path":"docs/control-os-smoke/x.md","content":"ok"}],
        }
        output = "\n".join([
            json.dumps({"type":"step_start","part":{"id":"1"}}),
            json.dumps({"type":"text","part":{"text":json.dumps(proposal)}}),
        ])
        self.assertEqual(planner.parse_opencode_output(output)["kind"], "patch")

    def test_validate_patch_contract(self):
        raw = {
            "kind":"patch","repo":"casa-viva","reason":"x","summary":"y",
            "allow_paths":["docs/control-os-smoke"],
            "operations":[{"op":"create_file","path":"docs/control-os-smoke/x.md","content":"ok"}],
        }
        plan = planner.validate_plan(raw, "casa-viva")
        self.assertEqual(plan["operations"][0]["path"], "docs/control-os-smoke/x.md")

    def test_protected_path_rejected(self):
        raw = {
            "kind":"patch","repo":"casa-viva","reason":"x","summary":"y",
            "allow_paths":[".github"],
            "operations":[{"op":"replace_file","path":".github/workflows/validate.yml","content":"x"}],
        }
        with self.assertRaises(ValueError):
            planner.validate_plan(raw, "casa-viva")

    def test_cross_repo_rejected(self):
        raw = {
            "kind":"patch","repo":"vivabot","reason":"x","summary":"y",
            "allow_paths":["src"],
            "operations":[{"op":"create_file","path":"src/x.js","content":"x"}],
        }
        with self.assertRaises(ValueError):
            planner.validate_plan(raw, "casa-viva")


if __name__ == "__main__":
    unittest.main()
