import sys
from pathlib import Path
import unittest
from unittest.mock import patch
import tempfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_test_only_periodic_host as h
class Host(unittest.TestCase):
    def test_absence_requires_all_checks(self):
        with tempfile.TemporaryDirectory() as d,patch.object(h.lease,"LEASE",Path(d)/"lease"):
            def run(args):return "LoadState=not-found" if "show" in args else ""
            self.assertTrue(h.confirm_absent("123-456",run,lambda:set()))
            self.assertFalse(h.confirm_absent("123-456",run,lambda:h.lease.NAMES))
            self.assertFalse(h.confirm_absent("123-456",lambda args:"active" if "show" in args else "",lambda:set()))
            self.assertFalse(h.confirm_absent("123-456",lambda args:"LoadState=not-found" if "show" in args else "container",lambda:set()))
            h.lease.LEASE.touch()
            self.assertFalse(h.confirm_absent("123-456",run,lambda:set()))
    def test_stop_fixed_units(self):
        s=(Path(h.__file__).parent/"stop-isolated-periodic.sh").read_text()
        self.assertLess(s.index('p/"STOP"'),s.index("systemctl stop"))
        self.assertIn("systemctl stop cv-test-only-periodic.timer",s)
        self.assertIn("systemctl stop cv-test-only-periodic.service",s)
        self.assertNotIn("systemctl stop cv-dev-worker",s)
    def test_owner_record_reopened_and_mismatch_rejected(self):
        import json
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);path=root/"owner-42.json"
            h.receiver.atomic(path,{"cid":42,"owner":"123-456"})
            with patch.object(h,"read_private",side_effect=lambda p:p.read_bytes()):
                self.assertEqual(h.owner_for(root,42),"123-456")
                path.write_text(json.dumps({"cid":43,"owner":"123-456"}))
                with self.assertRaises(ValueError):h.owner_for(root,42)
                path.write_text(json.dumps({"cid":42,"owner":"../other"}))
                with self.assertRaises(ValueError):h.owner_for(root,42)
if __name__=="__main__":unittest.main()
