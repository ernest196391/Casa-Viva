// Synthetic-only regression: no production receiver or credentials.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { probe } from './receiver-classification-probe.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const harness = readFileSync(join(here, 'receiver-classification-probe.mjs'));
const wrapper = readFileSync(join(here, 'receiver-classification-isolated.py'), 'utf8');
const pinned = wrapper.match(/^PROBE_SHA256 = '([a-f0-9]{64})'$/m)?.[1];
assert.equal(createHash('sha256').update(harness).digest('hex'), pinned, 'integrity pin');

const rejected = probe('async function classify() { throw Error("synthetic rejection"); }');
assert.deepEqual(rejected.classification, { saludo: 'unsupported', ventiladores: 'unsupported' });
await new Promise(resolve => setImmediate(resolve)); // Allow unhandled rejection to surface.
const none = probe('function classify() { return null; }');
assert.deepEqual(none.classification, { saludo: 'none', ventiladores: 'none' });
console.log('synthetic classifier regression: PASS');
