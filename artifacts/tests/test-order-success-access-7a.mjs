import fs from 'node:fs';

const path = 'wordpress/casa-viva-dropship-core/includes/class-cvd-whatsapp-gateway.php';
if (!fs.existsSync(path)) throw new Error(`Missing ${path}`);

const source = fs.readFileSync(path, 'utf8');

for (const marker of [
  'customer_order_url',
  "(int) $order->get_customer_id() !== get_current_user_id()",
  "wc_get_endpoint_url( 'view-order'",
  'Ver seguimiento del pedido',
  'Abrir WhatsApp y confirmar',
  'Seguir comprando',
  'inicia sesión o crea tu cuenta antes de tu próxima compra',
]) {
  if (!source.includes(marker)) {
    throw new Error(`7A order-success access missing contract marker: ${marker}`);
  }
}

if (/Ver seguimiento del pedido[\s\S]{0,500}get_order_key\s*\(/.test(source)) {
  throw new Error('7A must not expose order-key based tracking in the success CTA');
}

console.log('7A order-success access contract OK');
