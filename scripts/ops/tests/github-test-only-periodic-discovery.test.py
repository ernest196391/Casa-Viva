import sys
from pathlib import Path
import unittest
import datetime as dt
import json
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_test_only_periodic_discovery as d
class Discovery(unittest.TestCase):
    def test_verified_scope_and_revision(self):
        now=1800000000;sha="a"*40;rx=d.load()
        def stamp(t):return dt.datetime.fromtimestamp(t,dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        def comment(cid,revision=sha,issue=3):
            return dict(id=cid,user=dict(id=rx.OWNER_ID,login=rx.OWNER,type="User"),author_association="OWNER",created_at=stamp(now-10),updated_at=stamp(now-10),issue_url="https://api.github.com/repos/"+rx.REPO+"/issues/"+str(issue),body=rx.PREFIX+json.dumps(dict(nonce=f"fixture-nonce-{cid:08d}",expires_at=stamp(now+600),repo="casa-viva",sha=revision,profile="ops-python-contract")))
        comments=[comment(1),comment(2,"b"*40),comment(3,issue=4),comment(4)]
        comments[3]["user"]["id"]=1
        def gh(token,verb,path):
            self.assertEqual(verb,"GET")
            if "/comments?" in path:return comments
            if path.endswith("/issues/3"):return dict(state="open",title="CV contingency — diagnostic mailbox")
            return dict(private=True,full_name=rx.REPO)
        self.assertEqual([r["comment_id"] for r in d.discover(gh,"fixture",sha,now)],[1])
        comments[:]=[comment(1)]*100
        with self.assertRaises(ValueError):d.discover(gh,"fixture",sha,now)
if __name__=="__main__":unittest.main()
