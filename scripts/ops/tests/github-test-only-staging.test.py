import importlib.util
from pathlib import Path
import tempfile
import unittest
import json
import hashlib
from unittest.mock import patch

spec=importlib.util.spec_from_file_location("stage",Path(__file__).resolve().parents[1]/"stage-test-only-candidate.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class StageTests(unittest.TestCase):
    def test_manifest_and_no_overwrite(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            dest=Path(tmp)/"bundle"
            m.stage(dest)
            manifest=json.loads((dest/"MANIFEST.json").read_text())
            self.assertIs(manifest["activation"],False)
            self.assertEqual(set(manifest["sha256"]),set(m.FILES))
            for name,digest in manifest["sha256"].items():
                self.assertEqual(hashlib.sha256((dest/name).read_bytes()).hexdigest(),digest)
                self.assertEqual((dest/name).stat().st_mode & 0o777,0o600)
            with self.assertRaises(ValueError):m.stage(dest)
    def test_reject_symlink_parent(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            root=Path(tmp);(root/"real").mkdir();(root/"alias").symlink_to(root/"real")
            with self.assertRaises(ValueError):m.stage(root/"alias"/"bundle")
    def test_missing_source_cleans_stage(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            with patch.object(m,"FILES",("missing-fixture-file",)):
                with self.assertRaises(ValueError):m.stage(Path(tmp)/"bundle")
            self.assertEqual(list(Path(tmp).iterdir()),[])
    def test_reject_non_tmp(self):
        with self.assertRaises(ValueError):m.stage(Path("/opt/cv-test-only-fixture"))
if __name__=="__main__":unittest.main()
