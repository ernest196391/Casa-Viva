// Regression tests for owner conversation display. Run: node --test scripts/ops/tests/control-os-c6.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

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

// C7: prevent false orphan financial reviews if Supabase returns a capped page.
const sync = fs.readFileSync(new URL('../control-os-sync.py', import.meta.url), 'utf8');
test('pending approval audit must fail closed when query is truncated', () => {
  assert.match(sync, /status=eq\.pending&order=created_at\.asc&limit=500/);
  assert.match(sync, /approval_page_truncated = len\(approvals\) >= 500/);
  assert.match(sync, /if not approval_page_truncated else \[\]/);
  assert.match(sync, /'reviews_audit_incomplete': approval_page_truncated or waiting_page_truncated/);
});
test('panel must show an incomplete audit warning', () => {
  assert.match(js, /reviews_audit_incomplete/);
  assert.match(js, /No se pudieron comprobar todas las aprobaciones/);
});

const bridge = fs.readFileSync(new URL('../cv-ops-bridge.py', import.meta.url), 'utf8');
test('C8 VPS diagnostic is explicitly allowlisted and read-only', () => {
  assert.match(bridge, /"owner_ingest_audit": action_owner_ingest_audit/);
  const start = bridge.indexOf('def action_owner_ingest_audit(args):');
  const end = bridge.indexOf('\ndef action_ai_audit(args):', start);
  assert.ok(start >= 0 && end > start);
  const body = bridge.slice(start, end);
  assert.match(body, /"read_only": True/);
  assert.match(body, /len\(hits\) >= 80/);
  assert.match(body, /examined >= 2000/);
  assert.doesNotMatch(body, /subprocess\.|os\.system|eval\(|exec\(/);
  assert.doesNotMatch(body, /line\.strip\(\)|"content": line|send_message|api_request\(/);
});

// C8: the chat diagnostic must not be an arbitrary command execution path.
test('owner_ingest_audit is explicitly read-only and allowlisted', () => {
  const start = bridge.indexOf('def action_owner_ingest_audit(args):');
  const end = bridge.indexOf('def action_ai_audit(args):', start);
  assert.ok(start >= 0 && end > start, 'bounded audit action must exist');
  const body = bridge.slice(start, end);
  assert.match(body, /files_examined/);
  assert.match(body, /read_only.*True/);
  assert.match(body, /len\(hits\) >= 80/);
  assert.match(body, /examined >= 2000/);
  assert.doesNotMatch(body, /subprocess\.|os\.system|shell=True|\.write_text\(|\.write_bytes\(/);
  assert.match(bridge, /"owner_ingest_audit": action_owner_ingest_audit/);
});

// Mensajeria: no volver a ofertar una carrera por silencio del titular.
test('courier timeout requires gestora decision and respects daytime hours', () => {
  const chain = fs.readFileSync(new URL('../../../wordpress/casa-viva-dropship-core/includes/class-cvd-cadena-mensajeros.php', import.meta.url), 'utf8');
  const tick = chain.split('public static function tick_order(')[1].split('// ------------------------------------------------------------------ Avisos')[0];
  assert.doesNotMatch(tick, /self::system_release\(/);
  assert.match(tick, /\$hour < 9 \|\| \$hour >= 17/);
  assert.match(chain, /max\( 20, absint/);
  assert.match(tick, /Llámalo para confirmar/);
});
test('OK confirmation verifies assigned courier and uses atomic transition', () => {
  const bot = fs.readFileSync(new URL('../../../wordpress/casa-viva-dropship-core/includes/class-cvd-bot-bridge.php', import.meta.url), 'utf8');
  const confirm = bot.split('public static function confirm_received(')[1].split('public static function eta(')[0];
  assert.match(bot, /\/confirm'/);
  assert.match(confirm, /self::assigned_messenger\(/);
  assert.match(confirm, /CVD_Delivery::accept_assigned\(/);
  assert.match(confirm, /'ok', 'aceptado', 'acepto'/);
});
