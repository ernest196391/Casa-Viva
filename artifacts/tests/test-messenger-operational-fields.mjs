import fs from 'node:fs';

const checkout = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-cuban-checkout.php', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');

for (const marker of ['billing_cvd_alternate_phone', 'billing_cvd_delivery_date', 'billing_cvd_delivery_window', 'billing_cvd_change_amount', '_cvd_alternate_phone', '_cvd_delivery_date', '_cvd_delivery_window', '_cvd_change_required']) {
  if (!checkout.includes(marker)) throw new Error(`Falta campo operativo canónico: ${marker}`);
}
for (const marker of ['messenger_change_label', 'messenger_schedule_label', 'Vuelto confirmado', 'Sin preferencia registrada']) {
  if (!portal.includes(marker)) throw new Error(`El mensajero no consume el campo operativo: ${marker}`);
}
for (const marker of ['Subir vale', 'messenger_assistant', 'Asistente operativo', 'FALTA INFORMACIÓN', 'get_formatted_meta_data()']) {
  if (!portal.includes(marker)) throw new Error(`Falta experiencia operativa V2: ${marker}`);
}
if (portal.includes("get_formatted_meta_data( '' )")) throw new Error('El manifiesto expone metadatos internos de WooCommerce.');
if (portal.includes('No registrados en Core')) throw new Error('La ruta todavía degrada horario aunque Core ya lo registra.');

console.log('OK: teléfonos, horario y vuelto estructurados.');
