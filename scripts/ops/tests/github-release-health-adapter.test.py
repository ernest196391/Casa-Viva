import sys
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_health_adapter as a

class Tests(unittest.TestCase):
    @patch.object(a.subprocess,'run')
    def test_valid_epoch_and_fixed_unit(self,run):
        run.return_value.stdout=b'MainPID=123\nExecMainStartTimestampMonotonic=456\nInvocationID=abc\n'
        self.assertEqual(a.process_identity(),(123,456000))
        self.assertEqual(run.call_args.args[0][-1],'cv-ops-bridge.service')
        self.assertEqual(run.call_args.kwargs['timeout'],5)
        self.assertNotIn('shell',run.call_args.kwargs)
    @patch.object(a.subprocess,'run')
    def test_no_pid_unknown(self,run):
        run.return_value.stdout=b'MainPID=0\nExecMainStartTimestampMonotonic=456\nInvocationID=abc\n'
        self.assertIsNone(a.process_identity())
    @patch.object(a.subprocess,'run')
    def test_malformed_unknown(self,run):
        run.return_value.stdout=b'bad'
        self.assertIsNone(a.process_identity())
    @patch.object(a.subprocess,'run')
    def test_timeout_unknown(self,run):
        run.side_effect=subprocess.TimeoutExpired('systemctl',5)
        self.assertIsNone(a.process_identity())
    def test_no_functional_evidence_never_approves(self):
        self.assertIsNone(a.health_probe('a'*64,'b'*40,(123,456)))
if __name__=='__main__': unittest.main()
