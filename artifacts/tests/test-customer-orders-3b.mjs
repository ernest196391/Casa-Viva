import fs from 'node:fs';

const orders = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-customer-orders.php', 'utf8');
const nav = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-customer-navigation.php', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');

const checks = [
  ['reutiliza WooCommerce como origen', /wc_get_orders\(/.test(orders)],
  ['usa lector canónico', /CVD_Canonical_Order_Reader::read/.test(orders)],
  ['separa activos', />Activos</.test(orders)],
  ['separa terminados', />Terminados</.test(orders)],
  ['muestra modalidad', /Recogida en tienda/.test(orders) && /Entrega a domicilio/.test(orders)],
  ['abre pedido existente WooCommerce', /view-order/.test(orders)],
  ['badge de pedidos activos', /data-cvd-orders-count/.test(orders) && /CVD_Customer_Orders::badge_html/.test(nav)],
  ['clase registrada', /class-cvd-customer-orders\.php/.test(plugin) && /CVD_Customer_Orders::register/.test(plugin)],
  ['no escribe estados', !/update_meta_data|set_status|save\(\)/.test(orders)],
];

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL 3B: ${failed.join(', ')}`);
  process.exit(1);
}
console.log('OK: contrato 3B de Pedidos del cliente.');
