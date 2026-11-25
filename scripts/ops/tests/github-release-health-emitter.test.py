import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import github_release_health_emitter as e
import github_release_health_adapter as a

class Tests(unittest.TestCase):
    def setUp(self):
        self.key=b'k'*32
        self.d='a'*64
        self.r='b'*40
        self.epoch=(123,100)
    def check(self,measure):
        def observer(d,r,epoch,nonce):
            return e.emit_receipt(self.key,measure,d,r,epoch,nonce)
        with patch.object(e.time,'monotonic_ns',return_value=110):
            return a.authenticated_observer_probe(observer,self.key,self.d,self.r,self.epoch,now_ns=120)
    def test_success(self):
        self.assertIs(self.check(lambda *args:True),True)
    def test_confirmed_failure(self):
        self.assertIs(self.check(lambda *args:False),False)
    def test_unknown_not_signed(self):
        self.assertIsNone(self.check(lambda *args:None))
    def test_invalid_key_and_input(self):
        self.assertIsNone(e.emit_receipt(b'',lambda *args:True,self.d,self.r,self.epoch,'nonce-0123456789'))
        self.assertIsNone(e.emit_receipt(self.key,lambda *args:True,self.d,self.r,(True,100),'nonce-0123456789'))
    def test_synthetic_oracle_positive_and_negative(self):
        for statuses, expected in ((["healthy","degraded","unknown"],True),
                                   (["healthy","healthy","unknown"],False)):
            with self.subTest(statuses=statuses):
                values=iter(statuses)
                outcome=e.measure_synthetic_contract(self.d,self.r,self.epoch,
                    identity=lambda:self.epoch,artifact_digest=lambda:self.d,
                    run_case=lambda facts:dict(status=next(values),advisory_only=True))
                self.assertIs(outcome,expected)
    def test_synthetic_oracle_unknown_on_missing_or_malformed(self):
        for value in (None,{"status":"healthy"}, {"status":"healthy","advisory_only":False}):
            self.assertIsNone(e.measure_synthetic_contract(self.d,self.r,self.epoch,
                identity=lambda:self.epoch,artifact_digest=lambda:self.d,
                run_case=lambda facts:value))
    def test_synthetic_oracle_rejects_revision_or_epoch_change(self):
        for identities,digests in (([self.epoch,(124,101)],[self.d,self.d]),
                                   ([self.epoch,self.epoch],[self.d,'c'*64])):
            epochs=iter(identities); hashes=iter(digests)
            self.assertIsNone(e.measure_synthetic_contract(self.d,self.r,self.epoch,
                identity=lambda:next(epochs),artifact_digest=lambda:next(hashes),
                run_case=lambda facts:dict(status="healthy",advisory_only=True)))

if __name__=='__main__': unittest.main()
