import fs from 'node:fs';

const orders = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-customer-orders.php', 'utf8');
const css = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/customer-orders.css', 'utf8');

const checks = [
  ['reemplaza view-order con detalle Casa Viva', /woocommerce_account_view-order_endpoint/.test(orders) && /render_detail/.test(orders)],
  ['verifica propietario del pedido', /get_customer_id\(\).*get_current_user_id/.test(orders)],
  ['usa lector canónico', /canonical_state/.test(orders) && /CVD_Canonical_Order_Reader::read/.test(orders)],
  ['reutiliza timeline canónico', /CVD_Order_Event_Timeline::for_wc_order/.test(orders)],
  ['filtra dominios internos', /allowed_domains/.test(orders) && !/allowed_domains[^;]+commission/.test(orders)],
  ['muestra compra entrega seguimiento', />Tu compra</.test(orders) && />Entrega</.test(orders) && />Seguimiento</.test(orders)],
  ['mantiene URL real de pedidos', /wc_get_account_endpoint_url\( 'orders' \)/.test(orders)],
  ['no escribe estados', !/update_meta_data|set_status|save\(\)/.test(orders)],
  ['estilos mobile-first del detalle', /cvd-customer-order-detail/.test(css) && /@media\(max-width:820px\)/.test(css)],
];

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL 3C: ${failed.join(', ')}`);
  process.exit(1);
}
console.log('OK: contrato 3C del detalle único del pedido del cliente.');
