import fs from 'node:fs';

const service = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-messenger-contacts.php', 'utf8');
const events = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-order-events.php', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-plugin.php', 'utf8');

for (const marker of ['contact.confirmed', 'contact.no_answer', 'contact.reschedule_requested', 'contact.location_received', 'X-CVD-Idempotency-Key', "'domain' => 'contact'", '_cvd_messenger_user_id']) {
  if (!service.includes(marker)) throw new Error(`Falta contrato de contacto canónico: ${marker}`);
}
if (!events.includes("'incident', 'contact'")) throw new Error('El event store no admite el dominio contact.');
if (!plugin.includes('CVD_Messenger_Contacts::register()')) throw new Error('El servicio de contacto no está registrado.');
for (const forbidden of ['_cvd_contact_status', "update_meta_data( '_cvd_contact", 'operation.state_changed', 'delivery.state_changed']) {
  if (service.includes(forbidden)) throw new Error(`Contacto no puede crear estado paralelo: ${forbidden}`);
}

console.log('OK: eventos auditables de contacto del mensajero.');
