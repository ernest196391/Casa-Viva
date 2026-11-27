"""Receipt emitter for a separately trusted local observer.

This is a protocol component, not an independent production measurement.
The measurement callback MUST belong to an independently installed observer.
Never expose its signing key or callback to the release candidate.
"""
import hashlib
import hmac
import json
import re
import time

DOMAIN = b'CV-RELEASE-HEALTH-V1\x00'

def emit_receipt(key, measure, digest, revision, epoch, nonce):
    if not isinstance(key, bytes) or len(key) != 32:
        return None
    if not isinstance(digest,str) or not re.fullmatch(r'[0-9a-f]{64}',digest):
        return None
    if not isinstance(revision,str) or not (re.fullmatch(r'[0-9a-f]{40}',revision) or revision=='sha256:'+digest):
        return None
    if not isinstance(epoch,tuple) or len(epoch)!=2 or any(type(v) is not int or v<=0 for v in epoch):
        return None
    if not isinstance(nonce,str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}',nonce):
        return None
    try:
        outcome=measure(digest,revision,epoch)
        if type(outcome) is not bool:
            return None
        observed=time.monotonic_ns()
        if observed<epoch[1]:
            return None
        report=dict(digest=digest,commit=revision,epoch=list(epoch),nonce=nonce,
                    observed_ns=observed,functional_ok=outcome)
        payload=json.dumps(report,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
        return dict(report=report,mac=hmac.new(key,DOMAIN+payload,hashlib.sha256).hexdigest())
    except (OSError,ValueError,TypeError,OverflowError):
        return None

def measure_synthetic_contract(digest, revision, epoch, *, identity, artifact_digest, run_case):
    """Independent expected-value oracle for the isolated health_summary pilot.

    Dependencies are supplied by the trusted harness, not the candidate.
    run_case must execute a bounded, independently confined candidate and
    return its typed advisory result; absence or malformed data is UNKNOWN.
    This does not establish that the live bridge loaded a revision.
    """
    cases = (
        ({"pm2":{"vivabot":"online","vivabot-recovery-receiver":"online","nexofirma":"online"},"bridge":"active"}, "healthy"),
        ({"pm2":{"vivabot":"stopped","vivabot-recovery-receiver":"online","nexofirma":"online"},"bridge":"active"}, "degraded"),
        ({"pm2":{"vivabot":"unknown","vivabot-recovery-receiver":"unknown","nexofirma":"unknown"},"bridge":"unknown"}, "unknown"),
    )
    try:
        if identity() != epoch or artifact_digest() != digest:
            return None
        matches = []
        for facts, expected in cases:
            result = run_case(facts)
            if identity() != epoch or artifact_digest() != digest:
                return None
            if type(result) is not dict or set(result) != {"status","advisory_only"}:
                return None
            if result["advisory_only"] is not True or result["status"] not in {"healthy","degraded","unknown"}:
                return None
            matches.append(result["status"] == expected)
        return all(matches)
    except (OSError, ValueError, TypeError, RuntimeError):
        return None


def measure_bound_contract(digest, revision, epoch, *, snapshot, run_case):
    """Observer-side contract; dependencies must be trusted, independently installed.

    snapshot must attest bytes actually loaded by a confined worker, not merely
    hash a file on disk or repeat a candidate claim. No production provider is
    installed here. Never use the synthetic pilot epoch as production evidence.
    """
    def bound(value):
        return (type(value) is dict and set(value) == {
            'digest', 'revision', 'epoch', 'invocation', 'uid', 'confined'
        } and value['digest'] == digest and value['revision'] == revision
            and type(value['epoch']) is tuple and value['epoch'] == epoch
            and all(type(v) is int and v > 0 for v in value['epoch'])
            and type(value['invocation']) is str
            and re.fullmatch(r'[0-9a-f]{32}', value['invocation']) is not None
            and value['invocation'] != '0' * 32
            and type(value['uid']) is int and value['uid'] > 0
            and value['confined'] is True)
    try:
        first = snapshot()
        if not bound(first):
            return None
        # Copy the attestation; a callback must not mutate our baseline.
        first = dict(first)
        def unchanged():
            current = snapshot()
            return bound(current) and current == first
        return measure_synthetic_contract(
            digest, revision, epoch,
            identity=lambda: epoch if unchanged() else None,
            artifact_digest=lambda: digest if unchanged() else None,
            run_case=run_case)
    except (OSError, ValueError, TypeError, RuntimeError, KeyError):
        return None
