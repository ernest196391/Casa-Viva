"""Validate a synthetic E3 probe result. Never certifies the entire sandbox."""
import json

CHECKS = frozenset({
    'workspace_write', 'root_hidden', 'sibling_hidden', 'run_hidden',
    'network_2', 'network_10', 'outside_write_denied', 'memory_limit',
    'process_limit', 'cpu_limit',
})
LIMITS = frozenset({'memory.max', 'pids.max', 'cpu.max'})


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate key')
        result[key] = value
    return result


def validate_report(stdout, returncode):
    """Return a fixed verdict only; never disclose arbitrary output.

    A valid result is evidence of this fixed probe alone. It is not E3
    certification, nor does it authorize running arbitrary payloads.
    """
    if type(returncode) is not int or returncode != 0:
        return 'probe-failed'
    if not isinstance(stdout, bytes) or not 0 < len(stdout) <= 8192:
        return 'invalid-report'
    try:
        report = json.loads(stdout.decode('utf-8'), object_pairs_hook=_unique_object)
        if type(report) is not dict or set(report) != {'checks', 'limits'}:
            return 'invalid-report'
        checks, limits = report['checks'], report['limits']
        if (type(checks) is not dict or set(checks) != CHECKS or
                any(type(v) is not bool for v in checks.values()) or
                type(limits) is not dict or set(limits) != LIMITS or
                any(type(v) is not str for v in limits.values())):
            return 'invalid-report'
        if not all(checks.values()):
            return 'probe-failed'
        parts = limits['cpu.max'].split(' ')
        if len(parts) != 2 or any(not p.isascii() or not p.isdecimal() or len(p) > 12 for p in parts):
            return 'invalid-report'
        quota, period = map(int, parts)
        if (limits['memory.max'] != '268435456' or limits['pids.max'] != '16'
                or quota <= 0 or period <= 0 or quota * 4 > period):
            return 'probe-failed'
    except (ValueError, UnicodeError, RecursionError):
        return 'invalid-report'
    return 'synthetic-probe-passed'
