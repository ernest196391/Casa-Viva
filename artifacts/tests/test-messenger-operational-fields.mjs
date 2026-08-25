import fs from 'node:fs';

const checkout = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-cuban-checkout.php', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const simplifyPhp = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-messenger-simplification.php', 'utf8');
const simplifyJs = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/messenger-simplify.js', 'utf8');
const simplifyCss = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/messenger-simplify.css', 'utf8');

for (const marker of ['billing_cvd_alternate_phone', 'billing_cvd_delivery_date', 'billing_cvd_delivery_window', 'billing_cvd_change_amount', '_cvd_alternate_phone', '_cvd_delivery_date', '_cvd_delivery_window', '_cvd_change_required']) {
  if (!checkout.includes(marker)) throw new Error(`Falta campo operativo canónico: ${marker}`);
}
for (const marker of ['messenger_change_label', 'messenger_schedule_label', 'Vuelto confirmado', 'Sin preferencia registrada']) {
  if (!portal.includes(marker)) throw new Error(`El mensajero no consume el campo operativo: ${marker}`);
}
for (const marker of ['Subir vale', 'messenger_assistant', 'Asistente operativo', 'FALTA INFORMACIÓN', 'get_formatted_meta_data()']) {
  if (!portal.includes(marker)) throw new Error(`Falta experiencia operativa V2: ${marker}`);
}
for (const marker of ['CVD_Messenger_Simplification::register', 'class-cvd-messenger-simplification.php']) {
  if (!plugin.includes(marker)) throw new Error(`Falta registro P0.3: ${marker}`);
}
if (!/define\( 'CVD_VERSION', '\d+\.\d+\.\d+' \)/.test(plugin)) throw new Error('La versión del plugin debe conservar semver.');
for (const marker of ["'area-mensajeros'", "'ruta-cv'", "'interpretar-vale'", 'messenger-simplify.css', 'messenger-simplify.js']) {
  if (!simplifyPhp.includes(marker)) throw new Error(`Falta superficie P0.3: ${marker}`);
}
for (const marker of ['Añadir vale', 'Siguiente tarea', 'Clientes por llamar', 'Ver todos los datos', 'Parada ${index + 1} de ${stops.length}', "['#hoy', 'Hoy']", "['#ruta', 'Ruta']", "['#ganancias', 'Dinero']"]) {
  if (!simplifyJs.includes(marker)) throw new Error(`Falta simplificación UX: ${marker}`);
}
for (const marker of ['.cvd-messenger-nav', 'position:fixed', '.cvd-customer-collectible', '.cvd-field-confirmed', '@media(max-width:640px)', '@media(max-width:380px)']) {
  if (!simplifyCss.includes(marker)) throw new Error(`Falta contrato responsive P0.3: ${marker}`);
}
if (portal.includes("get_formatted_meta_data( '' )")) throw new Error('El manifiesto expone metadatos internos de WooCommerce.');
if (portal.includes('No registrados en Core')) throw new Error('La ruta todavía degrada horario aunque Core ya lo registra.');
if (/api[_-]?key|Authorization/i.test(simplifyJs)) throw new Error('La capa visual no debe contener secretos.');

console.log('OK: campos operativos y simplificación móvil P0.3 cubiertos.');
