import fs from 'node:fs';

const orders = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-customer-orders.php', 'utf8');
const js = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/customer-order-live.js', 'utf8');

const checks = [
  ['ruta autenticada del cliente', /customer\/orders\/\(\?P<id>/.test(orders) && /permission_callback/.test(orders) && /can_view_order/.test(orders)],
  ['verifica propiedad del pedido', /get_customer_id\(\).*get_current_user_id/.test(orders)],
  ['reutiliza live tracking existente', /CVD_Live_Tracking::tracking/.test(orders)],
  ['no expone order key al navegador', !/wp_localize_script[^;]+order_key/.test(orders) && !/data-[^=]+order-key/.test(orders)],
  ['devuelve payload seguro', /stageLabel/.test(orders) && /deliveryStatusLabel/.test(orders) && /location/.test(orders) && /timeline/.test(orders)],
  ['polling pausa con pestaña oculta', /visibilitychange/.test(js) && /document\.hidden/.test(js)],
  ['actualiza etapa sin recargar', /data-cvd-customer-stage/.test(orders) && /stage\.textContent/.test(js)],
  ['ubicación abre mapas externos', /google\.com\/maps\/search/.test(js) && /data-cvd-live-location/.test(orders)],
  ['no escribe estados', !/set_status|update_meta_data|save\(\)/.test(orders)],
];

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL 3D: ${failed.join(', ')}`);
  process.exit(1);
}
console.log('OK: contrato 3D de seguimiento en vivo del cliente.');
