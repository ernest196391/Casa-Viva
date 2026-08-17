import fs from 'node:fs';

const integrity = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-inventory-integrity.php', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const ui = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/inventory-integrity.js', 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL 5C: ${message}`);
    process.exit(1);
  }
}

assert(plugin.includes("class-cvd-inventory-integrity.php"), 'la frontera de inventario no se carga');
assert(plugin.includes('CVD_Inventory_Integrity::register()'), 'la frontera de inventario no se registra');
assert(integrity.includes("private const ORDER_TYPES    = array( 'sale', 'return' )"), 'venta/devolución no están reservadas al pedido');
assert(integrity.includes('cvd_order_inventory_only'), 'falta rechazo explícito de venta/devolución manual');
assert(integrity.includes('cvd_inventory_reconciliation_required'), 'falta bloqueo ante discrepancia');
assert(integrity.includes("'count' !== $type"), 'el conteo físico no queda como vía de reconciliación');
assert(integrity.includes('function snapshot'), 'falta diagnóstico de integridad');
assert(integrity.includes('MAX(id) AS latest_id'), 'el diagnóstico no usa el último saldo auditado');
assert(integrity.includes("add_query_arg( 'cvd_order'"), 'los enlaces de pedido del inventario no usan un parámetro propio');
assert(ui.includes('["sale", "return"]'), 'la UI todavía ofrece venta/devolución manual');
assert(ui.includes('Reconciliación requerida'), 'la UI no presenta discrepancias');
assert(ui.includes('Inventario conciliado'), 'la UI no presenta estado sano');

console.log('OK 5C: contrato de integridad de inventario validado.');