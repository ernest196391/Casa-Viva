// Core 3.14.70 (27-oct, Ernesto: #5582 Gretel). Contrato estático de la regla del «cliente fijo»:
// 1) quien arma el vale (vale por chat/NEXO, gestora con sesión) o trae al cliente con su enlace/código cobra ESA venta;
// 2) el cliente fijo (linked_customer, «Mis clientes», reasignación) solo cuenta en pedidos sin gestora;
// 3) aviso «Este cliente ya compró con otra gestora» sin decir cuál (privacidad); no impide la venta;
// 4) pedidos viejos no se reescriben y las reasignaciones siguen valiendo para el cliente fijo.
// Ejecutar: node artifacts/tests/test-cliente-fijo-3.14.70.mjs
import { readFileSync } from 'node:fs';
import { strict as assert } from 'node:assert';

const dir = new URL('../../wordpress/casa-viva-dropship-core/', import.meta.url);
const read = (p) => readFileSync(new URL(p, dir), 'utf8').replace(/\r\n/g, '\n');
const between = (text, start, end) => {
	const a = text.indexOf(start);
	assert.ok(a >= 0, `no encuentro «${start}»`);
	const b = text.indexOf(end, a + start.length);
	assert.ok(b > a, `no encuentro «${end}» después de «${start}»`);
	return text.slice(a, b);
};
const before = (text, first, second, why) => {
	const a = text.indexOf(first), b = text.indexOf(second);
	assert.ok(a >= 0 && b >= 0 && a < b, why);
};

const main = read('casa-viva-dropship-core.php');
assert.match(main, /Version: 3\.14\.(7\d|[89]\d)/, 'versión 3.14.70 o posterior');
assert.match(main, /define\( 'CVD_VERSION', '3\.14\.(7\d|[89]\d)/);

const attr = read('includes/class-cvd-attribution.php');
assert.doesNotMatch(attr, /Permanent customer ownership always wins/, 'ya no gana siempre el cliente fijo');

// --- Prioridades dentro de resolve_and_attach ---
const resolve = between(attr, 'private static function resolve_and_attach(', 'private static function identities(');
assert.match(resolve, /3\.14\.70 \(27-oct, Ernesto: #5582 Gretel\)/, 'comentario fechado');
assert.match(resolve, /bool \$operator_order = false/);
assert.match(resolve, /\$fixed = self::customer_owner\( \$phone, \$email, \$customer_id \);/);
const web = between(resolve, '} else {', "if ( ! $owner ) {\n\t\t\t$owner = array( 'owner_user_id' => 0");
before(web, 'self::seller_owner(', '$owner = $fixed;', 'enlace/vale > cliente fijo');
assert.match(web, /if \( \$owner \) \{[\s\S]*sold_by_other_gestora[\s\S]*\} else \{\s*\$owner = \$fixed;/, 'cliente fijo SOLO si el pedido no trae gestora');
assert.match(web, /if \( ! \$owner && \$operator_order \) \{\s*\$owner = self::browser_owner\( \$order \);/, 'vale sin gestora: como antes');
assert.match(resolve, /if \( \$admin_order \) \{\s*[^\n]*\n\s*\$owner = \$fixed \?: self::owner_from_coupon\( \$order \);/, 'pedido a mano en el admin: igual que antes (D97)');
assert.match(resolve, /delete_meta_data\( self::OTHER_GESTORA_META \)/);
assert.match(resolve, /update_meta_data\( self::OTHER_GESTORA_META, 'yes' \)/);
assert.match(resolve, /get_meta\( '_cvd_attribution_locked', true \) \) \{\s*return;/, 'pedidos viejos (bloqueados) no se reescriben');

// --- Quién vende ---
const seller = between(attr, 'private static function seller_owner(', 'public static function checkout_seller(');
before(seller, "'operator_confirmed_voucher'", "'seller_session'", 'la gestora del vale va primero');
before(seller, "'seller_session'", 'self::browser_owner( $order )', 'después la gestora con sesión, luego su enlace');
assert.match(seller, /if \( ! \$owner && \$use_browser \)/, 'en un vale de operadora no se mira el navegador antes que el cliente fijo');
const browser = between(attr, 'private static function browser_owner(', 'private static function program_owner(');
assert.match(browser, /owner_from_saved_referral\(\) \?: self::owner_from_request\(\) \?: self::owner_from_coupon\( \$order \) \?: self::owner_from_cart\(\)/);
const program = between(attr, 'private static function program_owner(', 'public static function sold_by_other_gestora(');
assert.match(program, /'approved' !== get_user_meta\( \$user_id, '_cvd_account_status', true \)/, 'solo gestoras aprobadas');
assert.match(program, /array\( 'cvd_gestora', 'cvd_influencer' \)/, 'solo gestora/influencer (no admin ni mensajero)');
assert.match(attr, /attach_operator_order\( WC_Order \$order, int \$preferred_owner_id = 0 \): void \{\s*self::resolve_and_attach\([^;]*, \$preferred_owner_id, false, true \);/);

// --- Aviso sin nombre ---
assert.match(attr, /OTHER_GESTORA_META = '_cvd_other_gestora';/);
assert.match(attr, /OTHER_GESTORA_TEXT = 'Este cliente ya compró con otra gestora\.';/);
const other = between(attr, 'public static function sold_by_other_gestora(', 'private static function owner_from_saved_referral(');
assert.match(other, /\): bool \{/, 'solo sí/no');
assert.doesNotMatch(other, /display_name|gestora_nombre|_cvd_owner_display_name/, 'nunca lee el nombre de la otra');
assert.match(other, /\$fixed_id && \$fixed_id !== \$seller_id/, 'cliente fijo de otra');
assert.match(other, /\$past_owner !== \$seller_id/, 'pedido vivo con otra');

// --- Reasignaciones: siguen, pero para el cliente fijo ---
const ov = read('includes/class-cvd-attribution-overrides.php');
const apply = between(ov, 'private static function apply_override(', 'public static function reassign_from_order(');
before(apply, 'CVD_Attribution::checkout_seller( $order )', 'self::latest_override( $identities )', 'con gestora en el pedido, la reasignación no se la quita');
assert.match(ov, /function override_owner\( array \$identities \)/, 'override_owner sigue (cliente fijo)');
assert.match(ov, /function reassign_from_order\(/, 'reasignar a mano sigue');

// --- Vale por chat: campo nuevo para que el bot lo diga ---
const vale = read('includes/class-cvd-vale-chat.php');
assert.match(vale, /CVD_Attribution::attach_operator_order\( \$order, \$gestora->ID \)/);
const created = between(vale, 'private static function created(', '\n\t}\n');
assert.match(created, /'otherGestora'\s*=> 'yes' === \(string\) \$order->get_meta\( CVD_Attribution::OTHER_GESTORA_META, true \)/);
assert.match(created, /'otherGestoraText' => [^\n]*CVD_Attribution::OTHER_GESTORA_TEXT/);
const otherLines = created.split('\n').filter((l) => /otherGestora/.test(l)).join('\n');
assert.doesNotMatch(otherLines, /display_name|gestora_nombre|_cvd_owner/, 'el aviso del bot no dice con qué gestora');

// --- Vale de NEXO ---
const intake = read('includes/class-cvd-voucher-intake.php');
assert.match(intake, /'otherGestora' => 'yes' === \(string\) \$order->get_meta\( CVD_Attribution::OTHER_GESTORA_META, true \)/);

// --- Pago de la web: aviso no bloqueante en la confirmación, solo a la gestora que lo hizo ---
const checkout = read('includes/class-cvd-cuban-checkout.php');
assert.match(checkout, /add_action\( 'woocommerce_before_thankyou', array\( __CLASS__, 'other_gestora_notice' \), 5 \);/);
const notice = between(checkout, 'public static function other_gestora_notice(', '\n\t}\n');
assert.match(notice, /absint\( \$order->get_meta\( '_cvd_owner_user_id', true \) \) !== \$me/, 'solo la gestora que vendió lo ve');
assert.match(notice, /esc_html\( CVD_Attribution::OTHER_GESTORA_TEXT \)/);
assert.doesNotMatch(notice, /display_name|gestora_nombre|_cvd_owner_display_name|\$errors|wc_add_notice/, 'sin nombre y sin bloquear');

console.log('OK: cliente fijo 3.14.70 (vale/enlace > cliente fijo; cliente fijo solo sin gestora; aviso sin nombre; reasignaciones intactas).');
