import importlib.util
from pathlib import Path
import tempfile
import unittest
import datetime as dt
s=importlib.util.spec_from_file_location("admission",Path(__file__).resolve().parents[1]/"github_test_only_admission.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class Admission(unittest.TestCase):
    def test_restart_replay_quota_stop(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); sha="a"*40; now=1800000000
            def req(cid,nonce=None):
                return dict(comment_id=cid,nonce=nonce or f"fixture-nonce-{cid:08d}",sha=sha,repo="casa-viva",profile="ops-python-contract",expires_at=dt.datetime.fromtimestamp(now+600,dt.timezone.utc).isoformat().replace("+00:00","Z"))
            db=m.connect(root)
            self.assertEqual(m.reserve(db,root,req(1),sha,now,2),"reserved")
            self.assertEqual(m.reserve(db,root,req(2),sha,now,2),"busy")
            db.close();db=m.connect(root)
            self.assertEqual(m.recover(db),1)
            self.assertEqual(m.reserve(db,root,req(1),sha,now,2),"duplicate")
            self.assertEqual(m.reserve(db,root,req(2,req(1)["nonce"]),sha,now,2),"duplicate")
            self.assertEqual(m.reserve(db,root,req(2),sha,now,2),"reserved")
            self.assertTrue(m.finish(db,2,"passed"))
            self.assertEqual(m.reserve(db,root,req(3),sha,now,2),"quota")
            (root/"STOP").touch()
            self.assertEqual(m.reserve(db,root,req(3),sha,now),"stopped")
            db.close()
    def test_wrong_revision_expiry(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);db=m.connect(root)
            req=dict(comment_id=1,nonce="fixture-nonce-00000001",sha="a"*40,repo="casa-viva",profile="ops-python-contract",expires_at="2020-01-01T00:00:00Z")
            self.assertEqual(m.reserve(db,root,req,"b"*40,1800000000),"blocked")
            self.assertEqual(m.reserve(db,root,req,"a"*40,1800000000),"expired")
            db.close()
if __name__=="__main__":unittest.main()
