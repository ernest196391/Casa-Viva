import sys
from pathlib import Path
import tempfile
import unittest
import datetime as dt
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_test_only_periodic as p
class Periodic(unittest.TestCase):
    def test_recovery_and_no_replay(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);sha="a"*40;now=1800000000
            req=dict(comment_id=1,nonce="fixture-nonce-00000001",sha=sha,repo="casa-viva",profile="ops-python-contract",expires_at=dt.datetime.fromtimestamp(now+600,dt.timezone.utc).isoformat().replace("+00:00","Z"))
            calls=[]
            def execute(r):
                calls.append(r["comment_id"])
                return dict(ok=True,production_authorized=False,durable_rows=1)
            def tick(clean):
                return p.tick(root,sha,lambda:[req],execute,clean,now=now)
            self.assertEqual(tick(lambda cid:False),"cleanup_unconfirmed")
            self.assertEqual(tick(lambda cid:False),"cleanup_unconfirmed")
            self.assertEqual(tick(lambda cid:True),"idle")
            self.assertEqual(calls,[1])
            (root/"STOP").touch()
            self.assertEqual(tick(lambda cid:True),"stopped")
    def test_lock(self):
        import fcntl
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            with (root/"execution.lock").open("w") as lock:
                (root/"execution.lock").chmod(0o600)
                fcntl.flock(lock,fcntl.LOCK_EX)
                self.assertEqual(p.tick(root,"a"*40,lambda: self.fail("discovery"),None,None),"busy")
if __name__=="__main__":unittest.main()
