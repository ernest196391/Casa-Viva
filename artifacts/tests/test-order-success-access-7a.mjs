import fs from 'node:fs';

const path = 'wordpress/casa-viva-dropship-core/includes/class-cvd-whatsapp-gateway.php';
if (!fs.existsSync(path)) throw new Error(`Missing ${path}`);

const source = fs.readFileSync(path, 'utf8');

for (const marker of [
  'customer_order_url',
  "(int) $order->get_customer_id() !== get_current_user_id()",
  "wc_get_endpoint_url( 'view-order'",
  'Ver seguimiento',
  'Confirmar por WhatsApp',
  "'checkout/thankyou.php' === $template_name",
  'Seguir comprando',
  'inicia sesión o crea tu cuenta antes de tu próxima compra',
]) {
  if (!source.includes(marker)) {
    throw new Error(`7A order-success access missing contract marker: ${marker}`);
  }
}

if (/Ver seguimiento[\s\S]{0,500}get_order_key\s*\(/.test(source)) {
  throw new Error('7A must not expose order-key based tracking in the success CTA');
}

const template = 'wordpress/casa-viva-dropship-core/templates/checkout/thankyou.php';
if (!fs.existsSync(template)) throw new Error(`Missing ${template}`);
const view = fs.readFileSync(template, 'utf8');
for (const marker of ["do_action( 'woocommerce_thankyou'", 'CVD_WhatsApp_Gateway::thankyou_button', "has_status( 'failed' )"]) {
  if (!view.includes(marker)) throw new Error(`Thank-you template missing: ${marker}`);
}
if (/woocommerce_thankyou', array\( __CLASS__, 'render_thankyou_tracking'/.test(fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-delivery.php', 'utf8'))) {
  throw new Error('The order-received page must keep a single tracking CTA');
}

console.log('7A order-success access contract OK');
