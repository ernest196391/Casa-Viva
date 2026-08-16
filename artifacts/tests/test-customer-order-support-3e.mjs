import fs from 'node:fs';

const support = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-customer-order-support.php', 'utf8');
const bootstrap = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const css = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/customer-order-support.css', 'utf8');

const checks = [
  ['cargado por el plugin', /class-cvd-customer-order-support\.php/.test(bootstrap) && /CVD_Customer_Order_Support::register/.test(bootstrap)],
  ['solo propietario autenticado', /is_user_logged_in/.test(support) && /get_customer_id\(\).*get_current_user_id/.test(support)],
  ['reutiliza gestora o WhatsApp central', /_cvd_owner_user_id/.test(support) && /_cvd_owner_type/.test(support) && /_cvd_whatsapp/.test(support) && /cvd_central_whatsapp/.test(support)],
  ['mensaje contextual seguro', /necesito ayuda con mi pedido/.test(support) && /get_order_number/.test(support) && /Estado:/.test(support)],
  ['WhatsApp y llamada', /https:\/\/wa\.me\//.test(support) && /tel:\+/.test(support)],
  ['no usa teléfono del cliente como soporte', !/get_billing_phone/.test(support)],
  ['no expone order key', !/get_order_key|order_key/.test(support)],
  ['no escribe pedido ni estados', !/update_meta_data|set_status|save\(|transition\(/.test(support)],
  ['mobile first sin dependencias nuevas', /@media\(max-width:820px\)/.test(css)],
];

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL 3E: ${failed.join(', ')}`);
  process.exit(1);
}
console.log('OK: contrato 3E de ayuda contextual del cliente.');
