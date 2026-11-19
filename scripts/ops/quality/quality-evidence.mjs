// CV-03: offline evidence classifier; never authorizes deployment or executes actions.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const sha = /^[0-9a-f]{40}$/;
const required = ['application', 'release', 'staging', 'integration', 'regression', 'rollback'];

export function classifyEvidence(record) {
  if (!record || !['general', 'E3'].includes(record.scope) || typeof record.candidate !== 'string' ||
      typeof record.base !== 'string' || !sha.test(record.candidate) || !sha.test(record.base) ||
      record.checkedCandidate !== record.candidate || record.checkedBase !== record.base ||
      record.incrementalCost !== 0 || record.conflict !== false || record.criticalOpen !== 0) {
    return 'BLOCKED';
  }
  if (!required.every(key => record.checks?.[key] === 'pass')) return 'BLOCKED';
  if (record.scope === 'E3' && !['isolation', 'trustedSupervisor', 'cleanup', 'dependencyIsolation']
      .every(key => record.checks?.[key] === 'pass')) return 'BLOCKED';
  // Evidence is supplied by a reviewer, not an attestation from untrusted payloads.
  // No output is a production authorization, including REVIEW_READY.
  return 'REVIEW_READY';
}

export function selfTest() {
  const candidate = 'a'.repeat(40), base = 'b'.repeat(40);
  const valid = { scope: 'general', candidate, base, checkedCandidate: candidate, checkedBase: base,
    incrementalCost: 0, conflict: false, criticalOpen: 0,
    checks: Object.fromEntries(required.map(key => [key, 'pass'])) };
  const cases = [
    [valid, 'REVIEW_READY'],
    [{...valid, checkedCandidate: base}, 'BLOCKED'],
    [{...valid, checkedBase: candidate}, 'BLOCKED'],
    [{...valid, incrementalCost: 1}, 'BLOCKED'],
    [{...valid, conflict: true}, 'BLOCKED'],
    [{...valid, criticalOpen: 1}, 'BLOCKED'],
    [{...valid, checks: {...valid.checks, integration: 'skipped'}}, 'BLOCKED'],
    [{...valid, checks: {...valid.checks, rollback: undefined}}, 'BLOCKED'],
    [{...valid, scope: 'E3'}, 'BLOCKED'],
    [{...valid, scope: 'unknown'}, 'BLOCKED'],
    [{...valid, candidate: [candidate]}, 'BLOCKED'],
    [{...valid, scope: 'E3', checks: {...valid.checks, isolation: 'pass', trustedSupervisor: 'pass', cleanup: 'pass', dependencyIsolation: 'pass'}}, 'REVIEW_READY'],
    [null, 'BLOCKED'],
  ];
  for (const [input, expected] of cases) assert.equal(classifyEvidence(input), expected);
  return cases.length;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  console.log(`${selfTest()} offline evidence checks passed`);
}
