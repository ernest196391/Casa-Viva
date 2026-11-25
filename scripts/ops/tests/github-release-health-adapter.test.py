import sys
import hashlib
import hmac
import json
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
    @patch.object(a.subprocess,'run')
    def test_oneshot_type(self,run):
        run.return_value.stdout=b'Type=oneshot\nMainPID=0\n'
        self.assertEqual(a.service_type(),'oneshot')
    @patch.object(a.subprocess,'run')
    def test_unrecognized_type_unknown(self,run):
        run.return_value.stdout=b'Type=unexpected\n'
        self.assertIsNone(a.service_type())
    def test_external_evidence_binding(self):
        digest,commit,epoch,nonce='a'*64,'b'*40,(123,100),'nonce-0123456789'
        base=dict(digest=digest,commit=commit,epoch=list(epoch),nonce=nonce,observed_ns=110,functional_ok=True)
        def validate(report, now=120):
            return a.validate_observation(report,digest,commit,epoch,nonce,now_ns=now)
        self.assertIs(validate(base),True)
        self.assertIs(validate(dict(base,functional_ok=False)),False)
        for changes in (dict(digest='c'*64),dict(commit='c'*40),dict(epoch=[123,99]),dict(nonce='other'),dict(observed_ns=99),dict(observed_ns=121),dict(functional_ok=1)):
            self.assertIsNone(validate(dict(base,**changes)))
        self.assertIsNone(validate(base,now=31_000_000_110))
    def test_authenticated_observer_rejects_wrong_mac_and_replay(self):
        key=b'k'*32
        digest,revision,epoch='a'*64,'b'*40,(123,100)
        captured=[]
        def observer(d,r,e,n):
            report=dict(digest=d,commit=r,epoch=list(e),nonce=n,observed_ns=110,functional_ok=True)
            payload=json.dumps(report,sort_keys=True,separators=(',',':')).encode()
            envelope=dict(report=report,mac=hmac.new(key,b'CV-RELEASE-HEALTH-V1\x00'+payload,hashlib.sha256).hexdigest())
            captured.append(envelope)
            return envelope
        def check(fn):
            return a.authenticated_observer_probe(fn,key,digest,revision,epoch,now_ns=120)
        self.assertIs(check(observer),True)
        self.assertIsNone(check(lambda *args: captured[0]))
        self.assertIsNone(check(lambda *args: dict(observer(*args),mac='0'*64)))
    def test_restored_digest_evidence(self):
        d='a'*64
        revision='sha256:'+d
        epoch=(123,100)
        nonce='nonce-0123456789'
        report=dict(digest=d,commit=revision,epoch=list(epoch),nonce=nonce,observed_ns=110,functional_ok=True)
        self.assertIs(a.validate_observation(report,d,revision,epoch,nonce,now_ns=120),True)
        self.assertIsNone(a.validate_observation(report,d,'sha256:'+'b'*64,epoch,nonce,now_ns=120))
    def test_no_functional_evidence_never_approves(self):
        self.assertIsNone(a.health_probe('a'*64,'b'*40,(123,456)))
if __name__=='__main__': unittest.main()
