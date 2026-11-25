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


    def bound_snapshot(self):
        return dict(digest=self.d,revision=self.r,epoch=self.epoch,
                    invocation='1'*32,uid=1001,confined=True)
    def bound_check(self,snapshot,statuses=("healthy","degraded","unknown")):
        values=iter(statuses)
        return e.measure_bound_contract(self.d,self.r,self.epoch,
            snapshot=snapshot,run_case=lambda facts:dict(status=next(values),advisory_only=True))
    def test_bound_contract_success_and_confirmed_failure(self):
        self.assertIs(self.bound_check(self.bound_snapshot),True)
        self.assertIs(self.bound_check(self.bound_snapshot,("healthy","healthy","unknown")),False)
    def test_bound_contract_rejects_untrusted_identity(self):
        for changes in (dict(uid=0),dict(uid=True),dict(confined=False),
                        dict(invocation='0'*32),dict(revision='c'*40),
                        dict(digest='c'*64),dict(epoch=(124,100))):
            with self.subTest(changes=changes):
                self.assertIsNone(self.bound_check(lambda:dict(self.bound_snapshot(),**changes)))
    def test_bound_contract_rejects_invocation_change(self):
        count=0
        def snapshot():
            nonlocal count
            count+=1
            return dict(self.bound_snapshot(),invocation=('1' if count<4 else '2')*32)
        self.assertIsNone(self.bound_check(snapshot))
    def test_bound_contract_unknown_is_not_signed(self):
        self.assertIsNone(self.check(lambda *args:e.measure_bound_contract(
            self.d,self.r,self.epoch,snapshot=lambda:None,run_case=lambda facts:None)))

if __name__=='__main__': unittest.main()
