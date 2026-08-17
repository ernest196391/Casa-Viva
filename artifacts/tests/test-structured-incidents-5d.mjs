import fs from 'node:fs';

const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const incidents = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-structured-incidents.php', 'utf8');
const ui = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/structured-incidents.js', 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL 5D: ${message}`);
    process.exit(1);
  }
}

assert(plugin.includes("class-cvd-structured-incidents.php"), 'el adaptador de incidencias no se carga');
assert(plugin.includes('CVD_Structured_Incidents::register()'), 'el adaptador de incidencias no se registra');
assert(incidents.includes("'missing_product'"), 'falta el motivo falta de producto');
assert(incidents.includes("'preparation_error'"), 'falta el motivo preparación incorrecta');
assert(incidents.includes("'customer_no_show'"), 'falta el motivo cliente no recoge');
assert(incidents.includes("'messenger_no_show'"), 'falta el motivo mensajero no recoge');
assert(incidents.includes('CVD_Order_Transition_Service::open_incident'), 'la apertura no usa la autoridad canónica');
assert(incidents.includes('CVD_Order_Transition_Service::resolve_incident'), 'la resolución no usa la autoridad canónica');
assert(incidents.includes("'event_id'"), 'el historial estructurado no queda enlazado al evento canónico');
assert(incidents.includes("'_cvd_structured_incident_history'"), 'falta historial estructurado auditable');
assert(!incidents.includes("update_meta_data( '_cvd_operation_status'"), 'el adaptador escribe un estado operativo paralelo');
assert(!incidents.includes("update_meta_data( '_cvd_delivery_status'"), 'el adaptador escribe un estado logístico paralelo');
assert(ui.includes('Registrar incidencia'), 'la UI no permite abrir la incidencia');
assert(ui.includes('Resolver incidencia'), 'la UI no permite resolver la incidencia');

console.log('OK 5D: contrato de incidencias operativas estructuradas validado.');
