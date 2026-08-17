import fs from 'node:fs';

const privacy = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-staff-privacy.php', 'utf8');
const bootstrap = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const sales = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/sales.js', 'utf8');
const center = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/order-center.js', 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL 5A: ${message}`);
    process.exit(1);
  }
}

assert(bootstrap.includes("class-cvd-staff-privacy.php"), 'el filtro de privacidad no se carga');
assert(bootstrap.includes('CVD_Staff_Privacy::register()'), 'el filtro de privacidad no se registra');
assert(privacy.includes("rest_post_dispatch"), 'la privacidad no se aplica al despacho REST');
assert(privacy.includes("manage_woocommerce"), 'falta la excepción explícita para administración');
assert(privacy.includes("cvd_manage_sales"), 'falta la frontera de capability de dependienta');
for (const field of ['gestora', 'commission', 'commissionStatus', 'adminUrl']) {
  assert(privacy.includes(`order['${field}']`), `no se elimina ${field} del Centro de ventas`);
}
assert(privacy.includes("projection['commission_summary']"), 'no se elimina commission_summary del Centro Único');
assert(privacy.includes("projection['gestora']"), 'no se elimina gestora del Centro Único');
assert(sales.includes('Object.prototype.hasOwnProperty.call(order, "commission")'), 'la UI de ventas no tolera payload mínimo de dependienta');
assert(center.includes('if(p.commission_summary)'), 'el Centro Único no oculta la tarjeta financiera cuando el servidor la omite');

console.log('OK 5A: contrato de privacidad dependienta/admin validado.');
