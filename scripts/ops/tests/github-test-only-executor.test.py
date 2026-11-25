import datetime as dt
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
HERE=Path(__file__).resolve().parents[1]
def load(name):
    spec=importlib.util.spec_from_file_location(name,HERE/(name+".py"))
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
ex=load("github_test_only_executor")
la=load("github_test_only_launcher")
class Tests(unittest.TestCase):
    def claim(self):
        return {"profile":ex.PROFILE,"sha":"a"*40,"lease":dt.datetime.fromtimestamp(1100,dt.timezone.utc).isoformat(),"run_id":1,"task_id":"fixture"}
    def test_budget(self):
        self.assertEqual(ex.execute_claim(self.claim(),lambda sha,budget:0 if (sha,budget)==("a"*40,90) else 1,now=1000),"passed")
    def test_reject(self):
        for key,value in [("sha","main"),("profile","shell"),("lease","bad")]:
            c=self.claim();c[key]=value
            self.assertEqual(ex.execute_claim(c,lambda *_:self.fail("launch"),now=1000),"blocked")
    def test_expired(self):
        self.assertEqual(ex.execute_claim(self.claim(),lambda *_:self.fail("launch"),now=1100),"blocked")
    def test_timeout(self):
        self.assertEqual(ex.execute_claim(self.claim(),lambda *_:124,now=1000),"timeout")
    def test_closed(self):
        with patch.dict(la.os.environ,{},clear=True):
            self.assertEqual(la.launch("a"*40,60),125)
    def test_fence(self):
        c=self.claim();calls=[]
        def api(method,path,data):
            calls.append((path,data))
            return c if "claim" in path else {"outcome":"passed","run_id":1}
        with patch.object(ex.time,"time",return_value=1000):
            self.assertTrue(ex.worker_once(api,lambda *_:0))
        self.assertEqual(calls[-1][1],{"p_task_id":"fixture","p_run_id":1,"p_worker":ex.WORKER,"p_outcome":"passed"})
if __name__=="__main__": unittest.main()
