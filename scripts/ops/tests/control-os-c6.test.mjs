// Regression tests for owner conversation display. Run: node --test scripts/ops/tests/control-os-c6.test.cjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';

const js = fs.readFileSync(new URL('../control-web/app.js', import.meta.url), 'utf8');
const match = js.match(/function isBriefContext\(x\)\{[\s\S]*?\n\}/);
assert.ok(match, 'isBriefContext must exist');
const context = vm.createContext({});
vm.runInContext(match[0], context);
const brief = context.isBriefContext;

test('acknowledgements are context, not new actionable work', () => {
  for (const subject of ['Hola, sí', 'Ok entiendo', 'Ahhh', 'Cual', 'Que hiciste', 'Solo los pedidos', 'Como lo tenemos todos']) {
    assert.equal(brief({subject}), true, subject);
  }
});
test('real operational requests and financial concerns are not hidden as context', () => {
  for (const subject of ['Necesito el link para pagar la comisión', 'Solo los pedidos #6452 necesitan revisión', 'Que hiciste con el cierre de caja?', 'Se pierde dinero en el cálculo de costos']) {
    assert.equal(brief({subject}), false, subject);
  }
});
test('summary takes precedence over short subject', () => {
  assert.equal(brief({subject:'Ok', summary:'Necesito revisar la comisión de Leya'}), false);
});
test('no automatic approval actions exist on blocked review cards', () => {
  const body = js.slice(js.indexOf('function blockedReviewCard('), js.indexOf('function isBriefContext('));
  assert.ok(body.includes('Sin aprobación formal'));
  assert.ok(!body.includes('data-decision'));
  assert.ok(!body.includes('approve-btn'));
});
