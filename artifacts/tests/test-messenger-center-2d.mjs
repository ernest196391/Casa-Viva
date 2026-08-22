import fs from 'node:fs';

const source = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/portal.js', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');
const delivery = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-delivery.php', 'utf8');
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
for (const marker of ['messenger_contacts', 'messenger_preparation', 'cvd-contact-outcomes', 'cvd-preparation-manifest', "get_option( 'cvd_pickup_address'"]) {
  if (!portal.includes(marker)) throw new Error(`Falta contrato P0.3 canónico: ${marker}`);
}
for (const marker of ['messenger_delivery_stage', '_cvd_delivery_incident_stage', 'get_variation_id', 'get_formatted_meta_data']) {
  if (!portal.includes(marker)) throw new Error(`Falta integridad del manifiesto P0.3: ${marker}`);
}
for (const marker of ['messenger_route', 'data-route-stop', 'Parada', 'Subir', 'Bajar', 'NEXO no optimiza esta ruta todavía', 'messenger_closeout', '_cvd_collection_amount_usd', '_cvd_collection_amount_cup']) {
  if (!portal.includes(marker)) throw new Error(`Falta contrato P0.4 canónico: ${marker}`);
}
for (const marker of ['initializeMessengerRoute', 'sessionStorage.setItem(storageKey', 'data-route-up', 'data-route-down']) {
  if (!source.includes(marker)) throw new Error(`Falta orden manual de sesión P0.4: ${marker}`);
}
if (portal.includes("update_meta_data( '_cvd_route") || portal.includes('route-suggest')) {
  throw new Error('P0.4 no puede persistir una ruta paralela ni activar route-suggest.');
}
for (const marker of ['operationStatus', 'operationUpdatedAt']) {
  if (!delivery.includes(marker)) throw new Error(`Falta refresco operativo mínimo P0.3: ${marker}`);
}
if (!source.includes("current.operationStatus") || !source.includes("data-operation-status")) {
  throw new Error('El polling del mensajero debe detectar cambios de preparación canónica.');
}
for (const marker of ['Confirmó', 'No responde', 'Reprogramar', 'Ubicación recibida', 'data-contact-outcome', 'CVD_Messenger_Contacts::latest']) {
  if (!portal.includes(marker)) throw new Error(`Falta contacto canónico del mensajero: ${marker}`);
}
for (const marker of ['messengerContactUrl', 'X-CVD-Idempotency-Key', 'Resultado registrado y auditado.']) {
  if (!source.includes(marker)) throw new Error(`Falta escritura de contacto auditable: ${marker}`);
}
if (!portal.includes("wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )")) {
  throw new Error('El desglose P0.2 debe separar el total del pedido de la mensajería CUP.');
}
for (const marker of ['Stitch P0.2', '.cvd-messenger-today-stats', '.cvd-delivery-money']) {
  if (!styles.includes(marker)) throw new Error(`Falta contrato visual P0.2: ${marker}`);
}
for (const marker of ['Stitch P0.3', '.cvd-contact-list', '.cvd-preparation-status']) {
  if (!styles.includes(marker)) throw new Error(`Falta contrato visual P0.3: ${marker}`);
}
for (const marker of ['Stitch P0.4', '.cvd-route-list', '.cvd-closeout-list']) {
  if (!styles.includes(marker)) throw new Error(`Falta contrato visual P0.4: ${marker}`);
}
console.log('OK: contrato del Centro Operativo del Mensajero.');
