import fs from 'node:fs';

const php = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-contextual-assistant.php', 'utf8');
const js = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/contextual-assistant.js', 'utf8');
const css = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/contextual-assistant.css', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');

for (const marker of ['visitante', 'cliente', 'gestora', 'mensajero', 'operacion', 'cvd-assistant-launcher']) {
  if (!php.includes(marker) && !js.includes(marker)) throw new Error(`Falta contexto del asistente: ${marker}`);
}
if (!plugin.includes('CVD_Contextual_Assistant::register()')) throw new Error('El asistente contextual no está registrado.');
if (!css.includes('bottom:112px') || !css.includes('z-index:61')) throw new Error('El robot no está situado de forma estable sobre WhatsApp.');
if (!js.includes('#asistente[data-cvd-assistant]')) throw new Error('El mensajero debe reutilizar su asistente operativo autorizado.');
if (!js.includes('window.cvdContextualAssistant ||') || !php.includes('data-context=')) throw new Error('El robot debe abrir incluso si una caché difiere la configuración localizada.');
if (!php.includes("array( __CLASS__, 'render' ), 5")) throw new Error('El HTML del robot debe renderizarse antes de los scripts del pie.');
if (/email|billing_phone|address_1|customer_id/i.test(php + js)) throw new Error('El asistente global no debe recibir PII.');

console.log('Asistente contextual: roles, privacidad y acceso flotante verificados.');
