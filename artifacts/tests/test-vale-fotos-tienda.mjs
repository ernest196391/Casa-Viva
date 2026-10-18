// Contrato de la foto de cada producto en la tarjeta de /ventas/ (Core 3.14.37, Lennys 16 y 17-oct:
// «Al dependiente hay que mandarle foto con el vale. Ayer se le mandó al cliente un cortinero que no era.»).
// Desde la raíz del repo: node artifacts/tests/test-vale-fotos-tienda.mjs
import fs from 'node:fs';
import vm from 'node:vm';

const dir = 'wordpress/casa-viva-dropship-core';
const sales = fs.readFileSync(`${dir}/includes/class-cvd-sales.php`, 'utf8');
const bridge = fs.readFileSync(`${dir}/includes/class-cvd-bot-bridge.php`, 'utf8');
const lines = fs.readFileSync(`${dir}/includes/class-cvd-order-lines.php`, 'utf8');
const privacy = fs.readFileSync(`${dir}/includes/class-cvd-staff-privacy.php`, 'utf8');
const js = fs.readFileSync(`${dir}/assets/sales.js`, 'utf8');
const css = fs.readFileSync(`${dir}/assets/sales.css`, 'utf8');
const boot = fs.readFileSync(`${dir}/casa-viva-dropship-core.php`, 'utf8');

let runs = 0;
function assert(condition, message) {
  runs++;
  if (!condition) { console.error(`FALLA fotos tienda: ${message}`); process.exit(1); }
}

// Una sola regla de foto para el vale del mensajero y para la tarjeta de la tienda.
assert(/public static function photo_url\( WC_Order_Item_Product \$item, string \$size = 'large' \): string/.test(lines), 'falta CVD_Order_Lines::photo_url');
assert(/public static function variation_text\( WC_Order_Item_Product \$item \): string/.test(lines), 'falta CVD_Order_Lines::variation_text');
assert(bridge.includes("CVD_Order_Lines::photo_url( $item, 'large' )"), 'el vale del mensajero no usa la misma foto');
assert(!/wc_get_product\( \$product->get_parent_id\(\) \)->get_image_id\(\)/.test(bridge), 'queda la búsqueda vieja de foto (fallaba si el producto padre no existe)');
assert(sales.includes("'image' => $is_line ? CVD_Order_Lines::photo_url( $item, 'medium' )"), 'la tarjeta de /ventas/ no recibe la foto pequeña');
assert(sales.includes("'imageLarge' => $is_line ? CVD_Order_Lines::photo_url( $item, 'large' )"), 'la tarjeta de /ventas/ no recibe la foto grande');
assert(sales.includes("'variation' => $is_line ? CVD_Order_Lines::variation_text( $item )"), 'la tarjeta de /ventas/ no recibe el color');
// La dependienta ve la foto: la privacidad solo quita el dinero de la gestora.
for (const f of ['image', 'imageLarge', 'variation', 'products']) assert(!privacy.includes(`'${f}'`) && !privacy.includes(`['${f}']`), `la privacidad no debe quitar ${f}`);

// Versión del Core (la caché de sales.js/sales.css se renueva con CVD_VERSION).
assert(boot.includes(' * Version: 3.14.37') && boot.includes("define( 'CVD_VERSION', '3.14.37' );"), 'la versión del Core no es 3.14.37');
assert(css.includes('.cvd-sale-data .cvd-sale-photo') && css.includes('object-fit: contain'), 'falta el estilo de la foto en la tarjeta');

// productLinks real de sales.js, con el escapeText de la página.
const start = js.indexOf('function productLinks(products)');
assert(start > 0, 'no encuentro productLinks en sales.js');
let depth = 0, end = -1;
for (let i = js.indexOf('{', start); i < js.length; i++) {
  if (js[i] === '{') depth++;
  if (js[i] === '}' && --depth === 0) { end = i + 1; break; }
}
const escapeText = (v) => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const ctx = vm.createContext({ escapeText });
vm.runInContext(`${js.slice(start, end)}; this.productLinks = productLinks;`, ctx);
const render = ctx.productLinks;

const html = render([
  { name: 'Cortinero extensible', quantity: 1, text: '1× Cortinero extensible — 12 USD', url: 'https://casaviva.company/producto/cortinero/', image: 'https://casaviva.company/f/621-300x300.jpg', imageLarge: 'https://casaviva.company/f/621-1024x1024.jpg', variation: 'Color: Negro' },
  { name: 'Toalla', quantity: 2, text: '2× Toalla — 5 USD c/u = 10 USD', url: '', image: '', imageLarge: '', variation: '' },
]);
assert(html.includes('<img src="https://casaviva.company/f/621-300x300.jpg"'), 'la tarjeta no pinta la foto');
assert(html.includes('href="https://casaviva.company/f/621-1024x1024.jpg"'), 'tocar la foto no la abre grande');
assert(html.includes('loading="lazy"'), 'la foto debe cargarse sin frenar la lista (conexiones lentas)');
assert(html.includes('<small>Color: Negro</small>'), 'falta el color debajo del nombre');
assert(html.includes('1× Cortinero extensible — 12 USD'), 'falta el nombre con su precio (3.14.7)');
assert(html.includes('2× Toalla — 5 USD c/u = 10 USD') && (html.match(/<img /g) || []).length === 1, 'un producto sin foto sale como siempre, sin imagen rota');
assert(!/<(div|p)\b/.test(html), 'dentro del <p> de la tarjeta solo puede ir texto en línea (span/a/img/small)');

// Lo que viene de la web se escapa (nombre con comillas o etiquetas no rompe la tarjeta).
const evil = render([{ name: 'X" onerror="alert(1)', quantity: 1, text: '<b>X</b>', url: '', image: 'https://a/b.jpg" onerror="alert(1)', imageLarge: '', variation: '<script>' }]);
assert(!evil.includes('onerror="alert(1)"') && !evil.includes('<script>') && !evil.includes('<b>X</b>'), 'la tarjeta no escapa lo que viene de la web');
// Pedidos de antes (payload sin image/variation) siguen funcionando.
assert(render([{ name: 'Vaso', quantity: 3 }]).includes('3 × Vaso'), 'un pedido sin los campos nuevos rompe la tarjeta');

console.log(`OK fotos tienda: ${runs} comprobaciones (foto y color de cada producto en /ventas/, misma foto que el vale del mensajero).`);
