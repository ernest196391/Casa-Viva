// 3.14.6 (Thaly, 14-oct, D54): la tienda de la gestora es «pegajosa» para quien llegó por su enlace.
import fs from 'node:fs';

const dir = 'wordpress/casa-viva-dropship-core/';
const store = fs.readFileSync(dir + 'includes/class-cvd-gestora-store.php', 'utf8');
const attribution = fs.readFileSync(dir + 'includes/class-cvd-attribution.php', 'utf8');
const support = fs.readFileSync(dir + 'includes/class-cvd-customer-order-support.php', 'utf8');
const search = fs.readFileSync(dir + 'includes/class-cvd-product-search.php', 'utf8');
const read = (f) => fs.readFileSync(dir + 'includes/' + f, 'utf8');
const bridge = read('class-cvd-bot-bridge.php');
const fn = (src, name) => {
  const start = src.indexOf('function ' + name + '(');
  if (start < 0) return '';
  const next = src.indexOf('\n\t/**', start + 1);
  const next2 = src.indexOf('\n\tpublic static function', start + 1);
  const next3 = src.indexOf('\n\tprivate static function', start + 1);
  const ends = [next, next2, next3].filter((n) => n > start);
  return src.slice(start, ends.length ? Math.min(...ends) : undefined);
};

const active = fn(store, 'active_gestora');
const sticky = fn(store, 'sticky_owner');
const checks = [
  ['el ?ref= de la visita sigue mandando', /pricing_owner_from_request\(\)/.test(active) && active.indexOf('pricing_owner_from_request') < active.indexOf('sticky_owner')],
  ['sin ?ref= usa el último enlace guardado', /sticky_owner\(\)/.test(active) && /CVD_Attribution::saved_referral_owner\(\)/.test(sticky)],
  ['solo gestoras aprobadas', /'approved' !== get_user_meta\( \$user->ID, '_cvd_account_status', true \)/.test(active)],
  ['cliente con cuenta: su tienda de gestora', /'cliente' === \$context/.test(sticky)],
  ['la gestora solo ve SU tienda guardada y solo en páginas de tienda', /'gestora' === \$context && absint\( \$owner\['owner_user_id'\] \?\? 0 \) === get_current_user_id\(\) && self::storefront_page\(\)/.test(sticky)],
  ['equipo (mensajero, tienda, admin) sin tienda pegajosa', /return null;\s*}\s*$/.test(sticky.trim() + '\n}') || /return null;/.test(sticky)],
  ['API: solo Cúrru y el carrito', /self::is_rest\(\) && ! self::storefront_rest\(\)/.test(sticky) && /'\/casa-viva\/v1\/curru\/'/.test(store) && /\/wc\/store\//.test(store)],
  ['sugerencias del buscador con precio de la gestora (Niurka)', /'\/casa-viva\/v1\/products\/search'/.test(fn(store, 'storefront_rest'))],
  ['sugerencias con gestora sin caché', /CVD_Gestora_Store::active_gestora\(\)/.test(search) && /'private, no-store'/.test(search)],
  ['3.14.9 cliente de gestora: sin Casa Viva, la gestora en su tienda sí', /\$id > 0 && \$id !== get_current_user_id\(\)/.test(fn(store, 'hides_casa_viva'))],
  ['3.14.9 recogida sin dirección', /te dice dónde y cuándo recogerlo/.test(fn(store, 'pickup_place'))],
  ['3.14.9 páginas y fichas sin dirección ni número', /cv-info-address/.test(fn(store, 'without_casa_viva')) && /5405/.test(fn(store, 'without_casa_viva')) && /como-llegar/.test(fn(store, 'without_casa_viva'))],
  ['3.14.9 pago, gracias, seguimiento, vale, Cúrru y Mi cuenta', ['class-cvd-cuban-checkout.php', 'class-cvd-whatsapp-gateway.php', 'class-cvd-delivery.php', 'class-cvd-whatsapp-receipt-template.php', 'class-cvd-contextual-assistant.php', 'class-cvd-registration.php', 'class-cvd-premium-storefront.php'].every((f) => /CVD_Gestora_Store::(pickup_place|pickup_label|order_gestora_name|hides_casa_viva)/.test(read(f)))],
  ['3.14.10 «Cómo recoger tu pedido» con gestora', /'Cómo recoger tu pedido'/.test(fn(store, 'pickup_page_title')) && /self::hides_casa_viva\(\)/.test(fn(store, 'pickup_page_title'))],
  ['3.14.11 /bot/client-owner: solo gestora aprobada y con su WhatsApp', /'\/bot\/client-owner'/.test(bridge) && /is_approved_gestora/.test(fn(bridge, 'client_owner')) && /_cvd_whatsapp/.test(fn(bridge, 'client_owner'))],
  ['API detectada por la dirección, no solo REST_REQUEST', /rest_get_url_prefix\(\)/.test(fn(store, 'is_rest'))],
  ['memo por etapa (wp_loaded / wp)', /did_action\( 'wp' \)/.test(active) && /did_action\( 'wp_loaded' \)/.test(active)],
  ['enlaces de producto con ?ref= de la tienda activa', /self::active_gestora\(\)/.test(fn(store, 'preserve_referral_on_product_links'))],
  ['marca en carrito y pago', /woocommerce_before_cart', array\( __CLASS__, 'store_banner' \)/.test(store) && /woocommerce_before_checkout_form', array\( __CLASS__, 'store_banner' \)/.test(store)],
  ['marca delante del carrito en bloque', /render_block', array\( __CLASS__, 'banner_in_cart_block' \)/.test(store) && /'woocommerce\/cart'/.test(fn(store, 'banner_in_cart_block'))],
  ['carrito fija el precio de la gestora', /_cvd_locked_price/.test(fn(store, 'lock_cart_price')) && /set_price/.test(fn(store, 'apply_locked_cart_prices'))],
  ['marca para la caché de Hostinger', /BYPASS_COOKIE = 'cvd_wp_woocommerce_session_tienda'/.test(attribution)],
  ['la marca se pone al guardar el enlace', /self::set_bypass_cookie\( \$days \)/.test(fn(attribution, 'capture_referral'))],
  ['navegadores de antes reciben la marca', /self::ensure_bypass_cookie\(\)/.test(fn(attribution, 'capture_referral'))],
  ['páginas con marca no se guardan en caché', /self::BYPASS_COOKIE/.test(fn(attribution, 'disable_cache_for_referral_request'))],
  ['LiteSpeed varía por las dos cookies', /self::COOKIE_NAME;\s*\$cookies\[\] = self::BYPASS_COOKIE;/.test(fn(attribution, 'litespeed_vary_cookies'))],
  ['el último enlace manda (D50)', /hash_equals\( \(string\) \( \$saved_owner\['referral_code'\] \?\? '' \), \$code \)/.test(attribution)],
  ['ayuda del pedido nombra a la gestora', /support_title/.test(support) && /'Habla con ' \. ucfirst\( \$name \)/.test(support)],
];

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL tienda pegajosa: ${failed.join(', ')}`);
  process.exit(1);
}
console.log(`OK: tienda pegajosa de la gestora (${checks.length} comprobaciones).`);
