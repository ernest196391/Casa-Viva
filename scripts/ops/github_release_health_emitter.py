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
