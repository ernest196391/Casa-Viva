import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { alternateCatalogQuery, cleanCatalogQuery, isCommissionQuestion } from './receiver-commercial-ai.mjs';

assert.equal(cleanCatalogQuery('mesitas auxiliares disponibles?'), 'mesitas auxiliares');
assert.equal(cleanCatalogQuery('ventilador RA12RSL'), 'ventilador RA12RSL');
assert.equal(isCommissionQuestion('Buenos días me puede decir si tengo comisiones pendientes a transferencia'), true);
assert.equal(isCommissionQuestion('Paga mis comisiones pendientes'), false);
assert.equal(isCommissionQuestion('Suelto #999991'), false);

const root = mkdtempSync(join(tmpdir(), 'cv-catalog-'));
try {
  const settingsFile = join(root, 'settings');
  const directory = join(root, 'budget');
  const now = Date.parse('2026-11-24T18:00:00Z');
  writeFileSync(settingsFile, 'OPENAI_API_KEY=sk-example\nCV_RECEIVER_AI_UNTIL=2026-12-08T18:00:00Z\n');
  let calls = 0;
  const options = { settingsFile, directory, now, fetcher: async (url, options) => {
    calls++;
    assert.equal(url, 'https://api.openai.com/v1/chat/completions');
    const request = JSON.parse(options.body);
    assert.equal(request.max_completion_tokens, 32);
    assert.equal(request.messages.at(-1).content, 'mesitas auxiliares');
    return { ok: true, json: async () => ({ choices: [{ message: { content: 'mesa auxiliar' } }] }) };
  } };
  assert.equal(await alternateCatalogQuery('pedido privado', options), null);
  assert.equal(await alternateCatalogQuery('mesa para 55512345', options), null);
  assert.equal(await alternateCatalogQuery('mesitas auxiliares', {...options, now: now + 15 * 86400e3}), null);
  assert.equal(await alternateCatalogQuery('mesitas auxiliares', options), 'mesa auxiliar');
  for (let n = 1; n < 250; n++) writeFileSync(join(directory, String(n)), '');
  assert.equal(await alternateCatalogQuery('mesitas auxiliares', options), null);
  assert.equal(calls, 1);
} finally {
  rmSync(root, { recursive: true, force: true });
}
