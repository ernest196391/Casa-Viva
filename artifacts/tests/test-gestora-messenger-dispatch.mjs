import fs from 'node:fs';

const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');
const delivery = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-delivery.php', 'utf8');

for (const marker of [
  'admin_post_cvd_gestora_publish_delivery',
  'CVD_Registration::is_approved_gestora',
  "'_cvd_owner_user_id'",
  "'ready' === sanitize_key",
  "'_cvd_operation_status'",
  "'unassigned' === CVD_Delivery::status",
  'CVD_Delivery::publish_offer',
  'check_admin_referer',
  'Enviar vale',
]) {
  if (!portal.includes(marker)) throw new Error(`Falta frontera canónica de envío: ${marker}`);
}
for (const marker of ['available_messengers', "'_cvd_messenger_available'", "'_cvd_account_status'"]) {
  if (!delivery.includes(marker)) throw new Error(`Falta disponibilidad canónica: ${marker}`);
}
if (/update_meta_data\(\s*'_cvd_messenger_user_id'/.test(portal)) throw new Error('La gestora no debe asignar directamente al mensajero.');
if ((portal.match(/'_cvd_operation_status'/g) || []).length < 2) throw new Error('La disponibilidad visual y el handler deben exigir preparación lista.');

console.log('OK: la gestora publica sus pedidos al pool canónico sin asignación paralela.');
