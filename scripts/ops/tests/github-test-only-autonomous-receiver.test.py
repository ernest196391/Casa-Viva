import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, MagicMock
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
    def test_integral_adapter(self):
        fixture=MagicMock()
        fixture.DockerFixtureDB.return_value.__enter__.return_value.sql.return_value="1"
        fixture.run.return_value={"ok":True,"production_authorized":False}
        with patch.object(m,"load",return_value=fixture):
            result=m.execute_integral("/tmp/source","image","token",7,"a"*40)
        self.assertTrue(result["ok"])
        self.assertEqual(result["durable_rows"],1)
        fixture.DockerFixtureDB.return_value.__exit__.assert_called_once()
    def test_missing_ack(self):
        fixture=MagicMock()
        fixture.DockerFixtureDB.return_value.__enter__.return_value.sql.return_value="0"
        fixture.run.return_value={"ok":True,"production_authorized":False}
        with patch.object(m,"load",return_value=fixture):
            self.assertFalse(m.execute_integral("/tmp/source","image","token",7,"a"*40)["ok"])
    def test_units_inactive_and_bounded(self):
        sandbox=Path(m.__file__).parent/"sandbox"
        service=(sandbox/"cv-autonomous-fixture@.service").read_text()
        timer=(sandbox/"cv-autonomous-fixture@.timer").read_text()
        for value in ("TimeoutStartSec=210","KillMode=control-group","MemoryMax=256M","NoNewPrivileges=yes"):
            self.assertIn(value,service)
        self.assertIn("OnActiveSec=30s",timer)
        self.assertIn("RemainAfterElapse=no",timer)
        self.assertNotIn("[Install]",timer)
        self.assertNotIn("OnUnitInactiveSec",timer)
if __name__=="__main__":unittest.main()

