import sys
import hashlib
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_candidate_boundary as b

class Tests(unittest.TestCase):
    def setUp(self):
        self.facts={'pm2':{'vivabot':'stopped','vivabot-recovery-receiver':'online','nexofirma':'online'},'bridge':'active'}
        self.box=Mock()
        self.box.run_in_box.return_value=(0,'{"status":"degraded"}')
    def evaluate(self,**kw):
        return b.evaluate('/var/lib/control-os-dev/work/run-pilot',self.facts,uid=kw.get('uid',1000),gid=kw.get('gid',1001),sandbox=self.box)
    def test_fixed_command_and_advisory(self):
        self.assertEqual(self.evaluate(),{'ok':True,'result':{'status':'degraded','advisory_only':True}})
        args,kw=self.box.run_in_box.call_args
        self.assertEqual(args[0][:4],['/usr/bin/python3','-I','-B','candidate.py'])
        self.assertEqual(args[2],10)
        self.assertEqual(kw,{'uid':1000,'gid':1001})
    def test_root_refused(self):
        self.assertFalse(self.evaluate(uid=0)['ok'])
        self.box.run_in_box.assert_not_called()
    def test_arbitrary_operation_refused(self):
        with self.assertRaises(ValueError):
            b.request_bytes('restart',self.facts)
    def test_secret_field_refused(self):
        self.facts['token']='synthetic'
        self.assertFalse(self.evaluate()['ok'])
        self.box.run_in_box.assert_not_called()
    def test_arbitrary_output_refused(self):
        self.box.run_in_box.return_value=(0,'{"status":"healthy","command":"restart"}')
        self.assertFalse(self.evaluate()['ok'])
    def test_failure_cannot_approve(self):
        self.box.run_in_box.return_value=(124,'{"status":"healthy"}')
        self.assertFalse(self.evaluate()['ok'])
    def test_oversized_output_refused(self):
        self.box.run_in_box.return_value=(0,'x'*2049)
        self.assertFalse(self.evaluate()['ok'])
    def test_invalid_status_refused(self):
        self.facts['pm2']['vivabot']='arbitrary'
        self.assertFalse(self.evaluate()['ok'])
    def test_stage_refuses_wrong_digest(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            source=root/'source.py'
            source.write_bytes(b'x=1\n')
            with self.assertRaises(ValueError):
                with b.stage_candidate(source,'0'*64,uid=1000,gid=1001,work_root=root):
                    self.fail('unexpected stage')
    def test_stage_exact_bytes_readonly_and_cleanup(self):
        with tempfile.TemporaryDirectory(dir=Path.home()) as tmp:
            root=Path(tmp)
            source=root/'source.py'
            data=b'x=1\n'
            source.write_bytes(data)
            with b.stage_candidate(source,hashlib.sha256(data).hexdigest(),uid=1000,gid=1001,work_root=root) as work:
                self.assertEqual((work/'candidate.py').read_bytes(),data)
                self.assertEqual(work.stat().st_mode & 0o777,0o555)
                self.assertEqual((work/'candidate.py').stat().st_mode & 0o777,0o444)
            self.assertFalse(work.exists())
if __name__=='__main__': unittest.main()
