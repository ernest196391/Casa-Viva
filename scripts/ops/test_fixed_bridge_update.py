"""Offline tests of the actual updater; no VPS or network."""
import ast
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

SOURCE=Path(__file__).with_name("cv-ops-bridge.py")
SHA="a"*40
TARGET=Path("/root/supervisor/cv-ops-bridge.py")

class FixedUpdate(unittest.TestCase):
    def setUp(self):
        node=next(n for n in ast.parse(SOURCE.read_text()).body if isinstance(n,ast.FunctionDef) and n.name=="action_update_bridge")
        self.gate=types.ModuleType("github_release_gate")
        self.gate.TOKEN_FILE=Path("/fake/token")
        self.gate.REPO="ernest196391/Casa-Viva"
        self.gate.read_private=Mock(side_effect=lambda p,*a: b"CV_GITHUB_RELEASE_TOKEN=fake" if p==self.gate.TOKEN_FILE else b"old")
        self.body=b"# Casa Viva Ops Bridge\n"+b"# padding\n"*600
        self.gate.git=Mock(side_effect=lambda *a: self.body if a[0]=="show" else SHA.encode() if a[0]=="rev-parse" else b"")
        self.gate.verified_checks=Mock(return_value=True)
        self.gate.atomic_file=Mock()
        self.api=Mock(side_effect=lambda t,m,p: {"protected":True,"commit":{"sha":SHA}} if p.endswith("/branches/main") else {"statuses":[{"context":"guardian/postmerge","state":"success"}]})
        ns={"Path":Path,"__file__":str(TARGET),"json":json,"_github_api":self.api}
        exec(compile(ast.Module(body=[node],type_ignores=[]),str(SOURCE),"exec"),ns)
        self.action=ns["action_update_bridge"]
    def invoke(self,args):
        with patch.dict(sys.modules,{"github_release_gate":self.gate}),patch.object(Path,"exists",return_value=False):
            return self.action(args)
    def test_fixed_update(self):
        self.assertEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.assertEqual(self.gate.atomic_file.call_count,2)
        self.gate.atomic_file.assert_called_with(TARGET,self.body)
    def test_guardian_required(self):
        self.api.side_effect=lambda t,m,p: {"protected":True,"commit":{"sha":SHA}} if p.endswith("/branches/main") else {"statuses":[]}
        self.assertNotEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.gate.atomic_file.assert_not_called()
    def test_extra_args_denied(self):
        self.assertEqual(self.invoke({"sha":SHA,"path":"/tmp/x"})["returncode"],2)
        self.gate.git.assert_not_called()
    def test_ci_required(self):
        self.gate.verified_checks.return_value=False
        self.assertNotEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.gate.atomic_file.assert_not_called()

    def test_main_moves_before_replace(self):
        self.gate.git.side_effect=[b"",SHA.encode(),self.body,b"",b"b"*40]
        self.assertNotEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.assertEqual(self.gate.atomic_file.call_count,1)
        self.assertNotEqual(self.gate.atomic_file.call_args.args[0],TARGET)
    def test_backup_conflict(self):
        import hashlib
        backup=TARGET.with_name(TARGET.name+".backup."+hashlib.sha256(b"old").hexdigest())
        self.gate.read_private.side_effect=lambda p,*a: b"CV_GITHUB_RELEASE_TOKEN=fake" if p==self.gate.TOKEN_FILE else b"wrong" if p==backup else b"old"
        with patch.dict(sys.modules,{"github_release_gate":self.gate}),patch.object(Path,"exists",return_value=True):
            self.assertNotEqual(self.action({"sha":SHA})["returncode"],0)
        self.gate.atomic_file.assert_not_called()
    def test_wrong_target(self):
        self.action.__globals__["__file__"]="/tmp/cv-ops-bridge.py"
        self.assertNotEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.gate.git.assert_not_called()
    def test_installed_bytes_drift(self):
        self.gate.read_private.side_effect=[b"CV_GITHUB_RELEASE_TOKEN=fake",b"old",b"changed"]
        self.assertNotEqual(self.invoke({"sha":SHA})["returncode"],0)
        self.assertEqual(self.gate.atomic_file.call_count,1)

if __name__=="__main__":
    unittest.main()
