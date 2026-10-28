// 3.14.71 (D121): contrato de «Lennys publica bajo pedido desde su panel». Sin red ni base de datos.
// Ejecutar: node artifacts/tests/test-panel-bajo-pedido.mjs   (la prueba con PHP: test-panel-bajo-pedido.php)
import fs from 'node:fs';

const root = 'wordpress/casa-viva-dropship-core';
const plugin = fs.readFileSync(`${root}/casa-viva-dropship-core.php`, 'utf8');
const php = fs.readFileSync(`${root}/includes/class-cvd-market-bridge.php`, 'utf8');
const mail = fs.readFileSync(`${root}/includes/class-cvd-bot-delivery-change.php`, 'utf8');
const must = (c, m) => { if (!c) throw new Error(m); };
const between = (s, a, b) => s.slice(s.indexOf(a), s.indexOf(b, s.indexOf(a) + a.length));

// Versión: la cabecera y CVD_VERSION iguales (siguiente libre sobre 3.14.70).
const header = plugin.match(/\* Version: ([\d.]+)/)?.[1];
const constant = plugin.match(/define\( 'CVD_VERSION', '([\d.]+)' \)/)?.[1];
must(header && header === constant, `Versión distinta en cabecera (${header}) y CVD_VERSION (${constant}).`);
must(header === '3.14.71', `La versión debe ser 3.14.71 (es ${header}).`);

// La puerta del bot sigue igual: mismas rutas y misma clave.
must(php.includes("'/bot/market/publish'") && php.includes("'/bot/market/(?P<item>\\d+)/unpublish'"), 'Las rutas del bot deben seguir.');
must(php.includes("$auth = array( 'CVD_Bot_Bridge', 'authorized' )"), 'El bot usa su clave.');
// Las del panel, con la clave del panel (la que ya usan /panel/*).
for (const r of ["'/panel/market/preview'", "'/panel/market/publish'", "'/panel/market/(?P<item>\\d+)/unpublish'"]) {
  must(php.includes(r), `Falta la ruta ${r}.`);
}
must(php.includes("$panel = array( 'CVD_Panel_Bridge', 'authorized' )"), 'Las rutas del panel deben usar CVD_Panel_Bridge::authorized.');
const panelRoutes = php.split('\n').filter(l => l.includes("'/panel/market/"));
must(panelRoutes.length === 3 && panelRoutes.every(l => l.includes("'permission_callback' => $panel")), 'Toda ruta /panel/market/* con la clave del panel.');

// Una sola lógica para publicar y retirar (sin duplicar).
must((php.match(/->set_sku\(/g) || []).length === 1, 'set_sku solo una vez: la lógica de publicar no se duplica.');
must((php.match(/->set_status\( 'private' \)/g) || []).length === 1, 'Retirar solo una vez: la lógica no se duplica.');
must(between(php, 'public static function publish(', 'private static function respond').includes('self::publish_item('), 'La ruta del bot usa publish_item.');
must(between(php, 'public static function panel_publish', 'public static function panel_unpublish').includes('self::publish_item('), 'La ruta del panel usa publish_item.');
must(between(php, 'public static function panel_unpublish', 'private static function mail').includes('self::unpublish_item('), 'Retirar del panel usa unpublish_item.');

// Panel: comisión fija 40 % calculada aquí, mínimo que rechaza, quién lo hizo, correo a Ernesto.
const pub = between(php, 'public static function panel_publish', 'public static function panel_unpublish');
must(!pub.includes("$p['commission']"), 'El panel no puede mandar la comisión.');
must(pub.includes('self::gestora_share( $price, $cost )'), 'Comisión = 40 % de la ganancia.');
must(/\$price \+ 0\.005 < \$q\['floor'\][\s\S]{0,400}422/.test(pub), 'Un precio bajo el mínimo se rechaza con 422.');
must(pub.includes("'by' => $by") && php.includes("'_cvd_market_by'"), 'Queda anotado quién lo hizo.');
must(pub.includes('self::mail(') && php.includes("CVD_Bot_Delivery_Change::mail_owner("), 'Correo a Ernesto con el mecanismo de /bot/aviso-correo.');
must(mail.includes('public static function mail_owner(') && /owner_mail[\s\S]{0,200}self::mail_owner\(/.test(mail), '/bot/aviso-correo usa mail_owner (mismo destino y tope).');
must(pub.includes('strip_contacts('), 'Sin teléfonos en el título ni la descripción.');
must(!/seller|proveedor_tel|seller_phone/i.test(php.replace(/El proveedor nunca se guarda aquí/, '')), 'El proveedor no se guarda en la web.');
must(!/notify|grupo|canal|whatsapp/i.test(pub), 'Sin aviso automático a las gestoras (D121).');

// Regla del precio = la del bot (vivabot src/market-price.js). Si existe el bot al lado, se compara el código.
const want = { MIN_MARGIN_USD: 17, EXPENSIVE_USD: 300, EXPENSIVE_PCT: 0.10, ROUND_TO: 5, CHEAP_USD: 60, CHEAP_PCT: 0.4, CHEAP_MIN_USD: 5, GESTORA_SHARE: 0.4 };
for (const [k, v] of Object.entries(want)) {
  const m = php.match(new RegExp(`public const ${k} = ([\\d.]+);`));
  must(m && Number(m[1]) === v, `${k} debe ser ${v} como en el bot.`);
}
const botFile = process.env.VIVABOT_DIR ? `${process.env.VIVABOT_DIR}/src/market-price.js` : '../vivabot/src/market-price.js';
if (fs.existsSync(botFile)) {
  const bot = await import(new URL(`file:///${fs.realpathSync(botFile).replace(/\\/g, '/')}`).href);
  for (const [k, v] of Object.entries(want)) must(bot[k] === v, `El bot cambió ${k} (${bot[k]}): actualiza Core y el panel.`);
  console.log('  (comparado con el bot de verdad)');
}

// Los números del diseño, con la misma cuenta que Core (CVD_Costs::floor_for, bajo pedido: 2,5 % y 1 USD).
const suggested = c => c < 60 ? Math.ceil(c + Math.min(17, Math.max(5, c * 0.4))) : Math.ceil((c + Math.max(17, c > 300 ? c * 0.1 : 0)) / 5) * 5;
const share = (p, c) => Math.max(0, Math.round((p - c) * 0.4 * 100) / 100);
const floor = (c, k) => Math.ceil(Math.max(c + k + 1, (c + k) / 0.975) * 100 - 1e-6) / 100;
const s = suggested(50), k = share(s, 50), f = floor(50, k);
must(s === 67 && k === 6.8 && f === 58.26 && Math.round((s - 50 - k) * 100) / 100 === 10.2, `50 → ${s}, ${k}, ${f}: debe ser 67, 6,80, 58,26.`);

console.log('Panel bajo pedido (D121) contract OK');
