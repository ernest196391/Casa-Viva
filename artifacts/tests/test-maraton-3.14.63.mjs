// Core 3.14.63 (maratón de ideas 24-oct): contrato estático del vale con menos datos, «Bajo pedido · llega en 24 h»,
// «Quedan X / vuelve el día», orden A–Z y tabla pública de mensajería.
import { readFileSync } from 'node:fs';
import { strict as assert } from 'node:assert';

const dir = new URL('../../wordpress/casa-viva-dropship-core/', import.meta.url);
const read = (p) => readFileSync(new URL(p, dir), 'utf8');

const main = read('casa-viva-dropship-core.php');
assert.match(main, /Version: 3.14.63/);
assert.match(main, /define\( 'CVD_VERSION', '3.14.63' \)/);
assert.match(main, /class-cvd-datos-a-mano\.php/);
assert.match(main, /CVD_Datos_A_Mano::register\(\)/);

const checkout = read('includes/class-cvd-cuban-checkout.php');
assert.match(checkout, /\['billing_first_name'\]\['required'\] = false/, 'nombre opcional');
assert.match(checkout, /\['billing_phone'\]\['required'\] = true/, 'teléfono siempre');
assert.match(checkout, /\['billing_address_1'\]\['required'\] = false/, 'calle opcional');
assert.match(checkout, /'¿Cómo paga el cliente\?', 'required' => false/, 'forma de pago opcional');
assert.match(checkout, /ADDRESS_PENDING = 'Dirección por confirmar: llamar al cliente'/);
assert.match(checkout, /set_billing_address_1\( self::ADDRESS_PENDING \)/);
assert.doesNotMatch(checkout, /'billing_address_1'\s*=> 'Escribe la calle/, 'la calle ya no está en $required');
assert.match(checkout, /'billing_cvd_locality' => 'Elige el reparto/, 'el reparto sigue obligatorio');
assert.match(checkout, /tarifas-mensajeria/, 'enlace a la tabla de mensajería');

const js = read('assets/checkout.js');
assert.match(js, /syncPhoneRequired\(true\)/);
assert.match(js, /markRequired\('#billing_cvd_payment_kind_field', '#billing_cvd_payment_kind', false\)/);

const dam = read('includes/class-cvd-datos-a-mano.php');
assert.match(dam, /BAJO_PEDIDO = 'Bajo pedido · llega en 24 h'/);
assert.match(dam, /META_BACK = '_cvd_vuelve'/);
assert.match(dam, /woocommerce_get_item_data/, 'carrito');
assert.match(dam, /woocommerce_single_product_summary/, 'ficha');
assert.match(dam, /\$date >= wp_date\( 'Y-m-d' \)/, 'fecha pasada no sale');

const depts = read('includes/class-cvd-departments.php');
assert.doesNotMatch(depts, /24–48/);
assert.match(depts, /'title' => 'A–Z'/);
assert.match(depts, /woocommerce_catalog_orderby/);

const app = read('includes/class-cvd-gestora-app.php');
assert.doesNotMatch(app, /24–48/);

const fotos = read('includes/class-cvd-gestora-fotos.php');
assert.match(fotos, /cvd_fotos_catalog_v2/);
assert.match(fotos, /CVD_Datos_A_Mano::BAJO_PEDIDO/);

const stock = read('includes/class-cvd-gestora-stock.php');
assert.doesNotMatch(stock.split('function loop_badge')[1].split('function exact_text')[0], /is_gestora\(\)/, '«Quedan X» para todos');

const intake = read('includes/class-cvd-voucher-intake.php');
assert.match(intake, /if \( ! \$phones \|\| ! \$municipality \|\| ! \$zone \|\| ! \$lines \)/);

console.log('OK: maratón 3.14.63 (vale con menos datos, bajo pedido 24 h, quedan/vuelve, A–Z, tabla de mensajería).');
