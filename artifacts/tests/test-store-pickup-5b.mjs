import fs from 'node:fs';

const service = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-order-transition-service.php', 'utf8');
const sales = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-sales.php', 'utf8');
const ui = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/sales.js', 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL 5B: ${message}`);
    process.exit(1);
  }
}

assert(service.includes('function complete_pickup'), 'falta la operación canónica complete_pickup');
assert(service.includes("'pickup'!==sanitize_key"), 'complete_pickup no limita el cierre a recogidas');
assert(service.includes("'ready'!==$operation"), 'complete_pickup no exige estado ready');
assert(service.includes("'_cvd_pickup_handover_confirmed'"), 'no se registra entrega física');
assert(service.includes("'_cvd_cash_status','verified'"), 'no se verifica el cobro de recogida');
assert(service.includes('approve_for_closeout'), 'la comisión no se aprueba dentro del cierre canónico');
assert(service.includes("set_status('completed'"), 'WooCommerce no se completa en la misma operación');
assert(service.includes("self::replay($receipt,'pickup','delivered')"), 'falta idempotencia específica de recogida');
assert(sales.includes('CVD_Order_Transition_Service::complete_pickup'), 'Centro de ventas no usa la autoridad canónica');
assert(sales.includes("'pickup' === $fulfillment && 'ready' === $operation_status"), 'la acción de entrega no está restringida a pickup ready');
assert(sales.includes("'Listo para recoger'"), 'falta etiqueta específica de recogida');
assert(ui.includes('Confirmar recogida y cobro'), 'falta confirmación UX de recogida');
assert(ui.includes('handoverConfirmed'), 'la UI no envía confirmación de entrega física');

console.log('OK 5B: contrato de recogida en tienda canónica validado.');
// Mejora #23: la recogida no guarda dirección ni vuelto precargados de pedidos anteriores.
{
  const fs2 = await import('node:fs');
  const co = fs2.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-cuban-checkout.php', 'utf8');
  const save = co.slice(co.indexOf('function save_order_fields'), co.indexOf('function admin_order_fields'));
  const pick = save.slice(save.indexOf("if ( 'pickup' === $type )"), save.indexOf('return;'));
  if (!pick.includes("set_billing_address_1( '' )") || !pick.includes("'_cvd_pay_with'") || !pick.includes("'_cvd_change_required', array()")) throw new Error('#23: recogida debe limpiar dirección y vuelto.');
  if (save.indexOf("if ( 'pickup' === $type )") > save.indexOf("'_cvd_locality', isset")) throw new Error('#23: la recogida debe salir antes de guardar campos de mensajería.');
  const js = fs2.readFileSync('wordpress/casa-viva-dropship-core/assets/checkout.js', 'utf8');
  if (!js.includes('var payWith = pickup ? 0')) throw new Error('#23: el resumen no muestra vuelto en recogida.');
  console.log('Pickup ignores prefilled delivery fields OK');
}
