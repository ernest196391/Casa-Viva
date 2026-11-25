import importlib.util
from pathlib import Path
import unittest
P=Path(__file__).resolve().parents[1]
def load(name,file):
 s=importlib.util.spec_from_file_location(name,P/file);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
r=load("reporter","github_test_only_reporter.py")
class TestReport(unittest.TestCase):
 def test_ambiguous_post(self):
  row={"id":"fixture","public_id":1,"source_ref":"42","payload":{"test_only":{"sha":"a"*40}},"result":{"outcome":"passed"}}
  remote=[];acks=[];posts=[]
  def api(verb,path,data=None):
   if verb=="GET":return [] if acks else [row]
   acks.append(data);return True
  def gh(token,verb,path,data=None):
   if path=="/repos/"+r.REPO:return {"private":True,"full_name":r.REPO}
   if path.endswith("/issues/3"):return {"state":"open","title":"CV contingency — diagnostic mailbox"}
   if verb=="GET":return remote[:]
   posts.append(data)
   remote.append({"id":999,"body":data["body"],"user":{"id":123},"created_at":"t","updated_at":"t"})
   raise TimeoutError("ambiguous POST")
  with self.assertRaises(TimeoutError):r.deliver(api,gh,"fixture",123)
  self.assertTrue(r.deliver(api,gh,"fixture",123))
  self.assertFalse(r.deliver(api,gh,"fixture",123))
  self.assertEqual(len(posts),1)
  self.assertEqual(acks,[{"p_task_id":"fixture","p_comment_id":999}])
 def test_invalid_outcome(self):
  with self.assertRaises((ValueError,KeyError)):r.report_body({"result":{"outcome":"arbitrary"}})
if __name__=="__main__":unittest.main()
