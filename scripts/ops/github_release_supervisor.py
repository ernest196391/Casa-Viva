"""Independent fixed-target release health supervisor.

This module is installed outside the candidate release and must not be loaded
from the untrusted checkout. Its restart and functional probe are supplied by
an independently installed, fixed-scope service adapter. No mailbox commands.
Unknown health never authorizes a release or a rollback.
"""
import hashlib
import os
from pathlib import Path
from github_release_gate import read_private, atomic_file
from github_release_transaction import load_state, recover, digest, TERMINAL, release_lock

ROOT = Path('/root/supervisor')
TARGET = 'github_contingency.py'

def _identity(root):
    """Fail closed for unsafe files and preserve the verified artifact hash."""
    return digest(read_private(root / TARGET))

def _snapshot(root):
    state = load_state(root)
    if state is None or state['state'] in TERMINAL:
        return state, None
    actual = _identity(root)
    if actual not in (state['candidate'], state['previous']):
        raise ValueError('target_changed')
    return state, actual

def _valid_process(value):
    """An independently collected process epoch: PID + monotonic start time."""
    return (isinstance(value, tuple) and len(value) == 2
            and isinstance(value[0], int) and value[0] > 0
            and isinstance(value[1], int) and value[1] > 0)

def supervise(*, root=ROOT, process_identity, restart, health_probe):
    """Reconcile one transaction; callbacks are locally trusted, never user input.

    process_identity() -> (pid, start_monotonic_ns), not just systemd 'active'.
    restart() -> True only if a fixed service restart was requested successfully.
    health_probe(expected_digest, expected_commit, epoch) -> True/False/None.
    The probe must independently validate the running revision and functionality.
    Calls are bounded by the *adapter*; this module does no network or shell IO.
    """
    try:
        before, actual = _snapshot(root)
        if before is None or before['state'] in TERMINAL:
            return {'ok': True, 'state': 'idle' if before is None else before['state']}
        state = before['state']
        if state == 'prepared':
            result = recover(root=root, expected=before)
            return result  # Always require another cycle after prepared -> pending.
        if state not in ('pending_health', 'rollback_pending'):
            return {'ok': False, 'reason': 'invalid_state'}
        expected_digest = before['candidate'] if state == 'pending_health' else before['previous']
        # A restored file is not automatically a healthy running process.
        if actual != expected_digest:
            if state == 'rollback_pending' and actual == before['candidate']:
                return recover(False, root, expected=before)
            return {'ok': False, 'reason': 'target_changed'}
        epoch_before = process_identity()
        if not _valid_process(epoch_before):
            return {'ok': False, 'state': state, 'reason': 'process_unknown'}
        # Persist one restart attempt per revision/state to prevent unattended loops.\n        # Hold the release lock while claiming the attempt; the updater uses it too.\n        marker = root / '.github-release-supervisor-attempt'\n        key = (before['sha'] + ' ' + state + ' ' + expected_digest + '\\n').encode()\n        try:\n            with release_lock(root):\n                if load_state(root) != before or _identity(root) != expected_digest:\n                    return {'ok': False, 'reason': 'observation_stale'}\n                try:\n                    existing = read_private(marker, 256)\n                except FileNotFoundError:\n                    existing = None\n                if existing == key:\n                    return {'ok': False, 'state': state, 'reason': 'restart_already_attempted'}\n                atomic_file(marker, key)\n        except BlockingIOError:\n            return {'ok': False, 'reason': 'release_busy'}\n        # Cannot assume that a still-running old process serves the newly installed file.
        # The independent adapter must restart explicitly for each verification attempt.
        # A restart failure is unknown, not a candidate failure.
        if restart() is not True:
            return {'ok': False, 'state': state, 'reason': 'restart_unavailable'}
        epoch_after = process_identity()
        if not _valid_process(epoch_after) or epoch_after == epoch_before:
            return {'ok': False, 'state': state, 'reason': 'restart_unverified'}
        if _identity(root) != expected_digest or load_state(root) != before:
            return {'ok': False, 'reason': 'observation_stale'}
        observation = health_probe(expected_digest, before['sha'], epoch_after)
        if observation not in (True, False, None) or type(observation) not in (bool, type(None)):
            observation = None
        if process_identity() != epoch_after or _identity(root) != expected_digest:
            return {'ok': False, 'reason': 'observation_stale'}
        if load_state(root) != before:
            return {'ok': False, 'reason': 'observation_stale'}
        if observation is None:
            return {'ok': False, 'state': state, 'reason': 'health_unknown'}
        if state == 'rollback_pending' and observation is False:
            return {'ok': False, 'state': state, 'reason': 'restored_health_failed'}
        return recover(observation, root, expected=before)
    except (OSError, ValueError, TypeError, OverflowError):
        return {'ok': False, 'reason': 'supervision_unavailable'}

def main():
    # No production adapter yet: never infer health from systemd active alone.
    # The installed supervisor must bind independent, bounded service callbacks.
    return {'ok': False, 'reason': 'independent_adapter_required'}

if __name__ == '__main__':
    import json
    print(json.dumps(main(), sort_keys=True))
