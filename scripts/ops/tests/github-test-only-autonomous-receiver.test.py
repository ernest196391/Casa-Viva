import importlib.util
from pathlib import Path
import tempfile
import unittest
s=importlib.util.spec_from_file_location("receiver",Path(__file__).resolve().parents[1]/"github_test_only_autonomous_receiver.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ReceiverTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(dir="/tmp")
        self.addCleanup(self.tmp.cleanup)
        self.arm=dict(comment_id=7,sha="a"*40,armed_at=1000,expires_at=1600)
    def tick(self,fn,now=1100):
        return m.tick(self.tmp.name,self.arm,fn,now)
    def test_replay(self):
        self.assertEqual(self.tick(lambda *_:dict(ok=True,production_authorized=False))["state"],"passed")
        self.assertEqual(self.tick(lambda *_:self.fail("replay"))["state"],"already_reserved")
    def test_expiry(self):
        self.assertEqual(self.tick(lambda *_:self.fail("expired"),1600)["state"],"expired")
    def test_short_budget(self):
        self.assertEqual(self.tick(lambda *_:self.fail("short"),1500)["state"],"insufficient_time")
    def test_failure(self):
        self.assertEqual(self.tick(lambda *_:dict(ok=False))["state"],"blocked")
        self.assertTrue((Path(self.tmp.name)/"terminal.json").is_file())
    def test_crash_fence(self):
        m.atomic(Path(self.tmp.name)/"attempt.json",dict(state="reserved"))
        self.assertEqual(self.tick(lambda *_:self.fail("replay"))["state"],"already_reserved")
    def test_invalid_lifetime(self):
        self.arm["expires_at"]=2000
        with self.assertRaises(ValueError):self.tick(lambda *_:None)
if __name__=="__main__":unittest.main()
