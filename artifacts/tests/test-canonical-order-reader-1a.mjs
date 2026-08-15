import assert from 'node:assert/strict';
import fs from 'node:fs';

const readerPath = 'wordpress/casa-viva-dropship-core/includes/class-cvd-canonical-order-reader.php';
const pluginPath = 'wordpress/casa-viva-dropship-core/includes/class-cvd-plugin.php';
const phpTestPath = 'artifacts/tests/test-canonical-order-reader-1a.php';
const reader = fs.readFileSync(readerPath, 'utf8');
const plugin = fs.readFileSync(pluginPath, 'utf8');
const phpTest = fs.readFileSync(phpTestPath, 'utf8');

assert.match(plugin, /require_once CVD_DIR \. 'includes\/class-cvd-canonical-order-reader\.php';/);
assert.doesNotMatch(reader, /add_action|add_filter|update_meta_data|update_post_meta|save\s*\(|update_status/,
  'El lector no puede registrar hooks ni escribir datos o estados');

for (const field of [
  'order_id', 'woocommerce_status', 'operation_status', 'delivery_status',
  'canonical_stage', 'incident', 'cash_status', 'commission_status',
  'consistency', 'reasons', 'data_used',
]) {
  assert.match(reader, new RegExp(`'${field}'\\s*=>`), `Falta el campo ${field}`);
}

const exactMappings = {
  handed_over: 'ON_THE_WAY_TO_CUSTOMER',
  picked_up: 'PICKED_UP',
  delivered: 'DELIVERED',
  cash_returned: 'PAYMENT_RECONCILED',
  closed: 'COMPLETED',
  failed: 'DELIVERY_FAILED',
};
for (const [source, canonical] of Object.entries(exactMappings)) {
  assert.match(reader, new RegExp(`'${source}'\\s*=>\\s*'${canonical}'`), `${source} no conserva su significado real`);
}

for (const conflict of [
  'OPERATION_DELIVERY_IMPOSSIBLE',
  'WC_COMPLETED_BEFORE_OPERATION_CLOSE',
  'CUSTOM_CANCELLED_WC_ACTIVE',
  'CASH_DELIVERY_IMPOSSIBLE',
  'PICKUP_HAS_ACTIVE_DELIVERY',
  'CANONICAL_STAGE_UNDETERMINED',
]) {
  assert.match(reader, new RegExp(conflict), `Falta detector ${conflict}`);
}

for (const scenario of [
  'pedido nuevo', 'pedido histórico', 'mensajero va a recoger', 'recogida en tienda',
  'cancelado coherente', 'incidencia conserva etapa normal', 'entrega física',
  'handed over significa', 'dinero devuelto', 'cierre completo', 'entrega fallida',
  'contradicción entregado',
]) {
  assert.ok(phpTest.includes(scenario), `Falta escenario PHP: ${scenario}`);
}

console.log('OK: contrato estático y cobertura de Fase 1A verificados.');
