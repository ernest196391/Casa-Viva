import fs from 'node:fs';

const source = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/portal.js', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');
const styles = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/portal.css', 'utf8');
const required = [
  'enhanceMessengerCenter',
  'cvd-messenger-center',
  'cvd-messenger-primary',
  'https://wa.me/',
  'tel:+',
  "map.textContent = 'Navegar'",
  "title.textContent = 'Entrega activa'",
];
for (const marker of required) {
  if (!source.includes(marker)) throw new Error(`Falta contrato 2D: ${marker}`);
}
for (const marker of ['messenger_today_summary', 'cvd-messenger-today', 'cvd-delivery-products', 'CVD_Delivery::action_url']) {
  if (!portal.includes(marker)) throw new Error(`Falta contrato P0.2 canónico: ${marker}`);
}
if (!portal.includes("wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )")) {
  throw new Error('El desglose P0.2 debe separar el total del pedido de la mensajería CUP.');
}
for (const marker of ['Stitch P0.2', '.cvd-messenger-today-stats', '.cvd-delivery-money']) {
  if (!styles.includes(marker)) throw new Error(`Falta contrato visual P0.2: ${marker}`);
}
console.log('OK: contrato del Centro Operativo del Mensajero.');
