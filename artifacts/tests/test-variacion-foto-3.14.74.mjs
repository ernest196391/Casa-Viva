// Core 3.14.74 (Lennys 28-oct): el diseño elegido (color/estampado) se ve con su foto y su nombre.
// a) ficha: «Elegiste: Verde» con la foto del diseño junto al botón; b) carrito: lo hace WooCommerce (foto de la variación);
// c) app de gestoras, «Fotos para publicar»: cada foto dice de qué diseño es y el texto copiado lo lleva;
// d) vale de WhatsApp: «Color: Verde» también con atributos «cualquiera» o variación borrada.
import { readFileSync } from 'node:fs';
import { strict as assert } from 'node:assert';

const dir = new URL('../../wordpress/casa-viva-dropship-core/', import.meta.url);
const read = (p) => readFileSync(new URL(p, dir), 'utf8');

const main = read('casa-viva-dropship-core.php');
assert.match(main, /Version: 3\.14\.(7[4-9]|[89]\d)/);

// a) Ficha de producto.
const shop = read('assets/mobile-shop.js');
assert.match(shop, /on\("found_variation", function \(event, variation\)/);
assert.match(shop, /<small>Elegiste<\/small>/);
assert.match(shop, /gallery_thumbnail_src \|\| image\.thumb_src \|\| image\.src/, 'miniatura del diseño (Woo pone la del producto si no tiene)');
assert.match(shop, /on\("reset_data hide_variation"/, 'se esconde al quitar la elección');
assert.match(read('assets/mobile-shop.css'), /\.cvd-chosen\[hidden\]\{display:none\}/);

// c) Fotos para publicar.
const fotos = read('includes/class-cvd-gestora-fotos.php');
assert.match(fotos, /cvd_fotos_catalog_v3/, 'caché nueva: la vieja no trae el diseño');
assert.match(fotos, /get_image_id\( 'edit' \)/, 'solo la foto propia del diseño lleva su nombre');
assert.match(fotos, /'v'\] = implode\( ' \/ ', \$names \)/);
const app = read('assets/gestora-app.js');
assert.match(app, /data-cvgf-design/);
assert.match(app, /'Elegiste: '/);

// ftext: «*Nombre* — Verde» y el enlace abre la ficha con ese diseño ya elegido.
const src = app.match(/var ftext = (function \(p, k\) \{[\s\S]*?\n    \});/);
assert.ok(src, 'ftext existe');
const ftext = new Function('return ' + src[1])();
const p = { t: '*Cortina con alfombra*\nBonita.\n35 USD\nPídelo aquí y te lo llevamos 🛵 https://casaviva.company/producto/cortina/?ref=ana', i: [['a.jpg', 'a'], ['v.jpg', 'v', 'Verde', 'attribute_pa_color=verde'], ['x.jpg', 'x', 'Azul / Rojo', '']] };
assert.equal(ftext(p, 0), p.t, 'foto sin diseño: texto igual');
assert.equal(ftext(p, 1), '*Cortina con alfombra* — Verde\nBonita.\n35 USD\nPídelo aquí y te lo llevamos 🛵 https://casaviva.company/producto/cortina/?ref=ana&attribute_pa_color=verde');
assert.ok(ftext(p, 2).startsWith('*Cortina con alfombra* — Azul / Rojo\n') && ftext(p, 2).endsWith('?ref=ana'), 'foto compartida: nombre sí, enlace sin elegir');

// d) Vale de WhatsApp.
const receipt = read('includes/class-cvd-whatsapp-receipt-template.php');
assert.match(receipt, /visible_variations\( \$product, \$item \)/);
assert.match(receipt, /\$item->get_meta\( \$attribute, true \)/, 'valor «cualquiera» leído de la línea');

console.log('ok variación con foto 3.14.74');
