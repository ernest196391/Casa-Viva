import importlib.util
from pathlib import Path
import tempfile
import os
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
class LeaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(dir="/tmp")
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        spec=importlib.util.spec_from_file_location("lease",Path(m.__file__).parent/"github_test_only_apparmor_lease.py")
        self.lease=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.lease)
        # CI runs unprivileged. Simulate only file ownership, leaving the
        # real root-only production checks intact. No actual AppArmor calls.
        real_fstat=os.fstat
        def fixture_fstat(fd):
            info=real_fstat(fd)
            if info.st_uid!=os.geteuid():return info
            values=list(info)
            values[4]=0
            return os.stat_result(values)
        p=patch.object(self.lease.os,"fstat",side_effect=fixture_fstat)
        p.start();self.addCleanup(p.stop)
        self.lease.LOCK=self.root/"lock"
        self.lease.LEASE=self.root/"owner"
        self.source=self.root/"source/scripts/ops/sandbox/dev-worker-sandbox.profile"
        self.source.parent.mkdir(parents=True)
        self.source.write_bytes((Path(m.__file__).parent/"sandbox/dev-worker-sandbox.profile").read_bytes())
        self.active=set()
        self.calls=[]
    def run_cmd(self,args):
        self.calls.append(args)
        if "-a" in args:self.active.update(self.lease.NAMES)
        if "-R" in args:self.active.clear()
    def acquire(self,owner="1-2"):
        return self.lease.acquire(self.root,owner,self.run_cmd,lambda:self.active.copy())
    def release(self,owner="1-2"):
        return self.lease.release(self.root,owner,self.run_cmd,lambda:self.active.copy())
    def test_owned_cleanup(self):
        self.acquire()
        self.assertEqual(self.active,self.lease.NAMES)
        self.release()
        self.assertFalse(self.active)
        self.assertFalse(self.lease.LEASE.exists())
    def test_foreign_release(self):
        self.acquire()
        with self.assertRaises(ValueError):self.release("3-4")
        self.assertEqual(self.active,self.lease.NAMES)
    def test_preexisting_profiles(self):
        self.active.update(self.lease.NAMES)
        with self.assertRaises(ValueError):self.acquire()
        self.assertFalse(self.calls)
    def test_tampered_payload(self):
        self.acquire()
        (self.root/"pilot.profile").write_text("changed")
        with self.assertRaises(ValueError):self.release()
        self.assertEqual(self.active,self.lease.NAMES)
    def test_interrupted_load_reservation(self):
        def fail(args):
            if "-a" in args:raise RuntimeError("interrupted")
        with self.assertRaises(RuntimeError):
            self.lease.acquire(self.root,"1-2",fail,lambda:self.active.copy())
        self.assertTrue(self.lease.LEASE.exists())
        self.release()
        self.assertFalse(self.lease.LEASE.exists())

class RemoteDeadlineTests(unittest.TestCase):
    def setUp(self):
        self.fixture=MagicMock()
        self.fixture.rx.REPO="ernest196391/casa-viva-centro-control"
        self.fixture.ghmod.github_api.return_value={"issue_url":"https://api.github.com/repos/ernest196391/casa-viva-centro-control/issues/3"}
        self.fixture.rx.parse_comment.return_value={"comment_id":7,"sha":"a"*40,"expires_at":"1970-01-01T00:26:40Z"}
    def check(self,local=1600,now=1100):
        return m.remote_deadline(self.fixture,"token",7,"a"*40,local,now)
    def test_remote_earlier(self):
        self.assertEqual(self.check(1700),1600)
    def test_local_earlier(self):
        self.assertEqual(self.check(1500),1500)
    def test_remote_short(self):
        with self.assertRaises(ValueError):self.check(now=1300)
    def test_local_short(self):
        with self.assertRaises(ValueError):self.check(1400)
    def test_wrong_revision(self):
        self.fixture.rx.parse_comment.return_value["sha"]="b"*40
        with self.assertRaises(ValueError):self.check()
    def test_wrong_mailbox(self):
        self.fixture.ghmod.github_api.return_value["issue_url"]="https://api.github.com/repos/other/repo/issues/3"
        with self.assertRaises(ValueError):self.check()
    def test_invalid_comment(self):
        self.fixture.rx.parse_comment.return_value=None
        with self.assertRaises(ValueError):self.check()
    def test_exact_margin(self):
        self.assertEqual(self.check(now=1270),1600)

if __name__=="__main__":unittest.main()

