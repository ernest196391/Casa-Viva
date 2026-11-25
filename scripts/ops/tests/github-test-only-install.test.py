import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

HERE=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("installer",HERE/"install-test-only-candidate.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class TestInstall(unittest.TestCase):
    def test_install_rollback(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            root=Path(tmp); bundle=m.stage_contract.stage(root/"bundle")
            digest=hashlib.sha256((bundle/"MANIFEST.json").read_bytes()).hexdigest()
            dest=m.install(bundle,root,digest)
            self.assertEqual(m.verify(dest,digest),m.verify(bundle,digest))
            with self.assertRaises(ValueError):m.install(bundle,root,digest)
            m.rollback(root,digest)
            self.assertFalse(dest.exists())
            self.assertTrue(bundle.exists())
    def test_tamper_rejected(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            root=Path(tmp);bundle=m.stage_contract.stage(root/"bundle")
            digest=hashlib.sha256((bundle/"MANIFEST.json").read_bytes()).hexdigest()
            (bundle/m.FILES[0]).write_text("tampered")
            with self.assertRaises(ValueError):m.install(bundle,root,digest)
            self.assertFalse((root/"cv-test-only-inactive").exists())
    def test_wrong_manifest(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            root=Path(tmp);bundle=m.stage_contract.stage(root/"bundle")
            with self.assertRaises(ValueError):m.install(bundle,root,"0"*64)
    def test_live_prefix_refused(self):
        with self.assertRaises(ValueError):m.private_prefix("/opt")
    def test_rollback_refuses_extra_file(self):
        with tempfile.TemporaryDirectory(dir="/tmp") as tmp:
            root=Path(tmp);bundle=m.stage_contract.stage(root/"bundle")
            digest=hashlib.sha256((bundle/"MANIFEST.json").read_bytes()).hexdigest()
            dest=m.install(bundle,root,digest)
            (dest/"unexpected").write_text("keep")
            with self.assertRaises(ValueError):m.rollback(root,digest)
            self.assertTrue(dest.exists())

if __name__=="__main__":unittest.main()
