import fs from 'node:fs';

const root = 'wordpress/casa-viva-dropship-core';
const plugin = fs.readFileSync(`${root}/casa-viva-dropship-core.php`, 'utf8');
const php = fs.readFileSync(`${root}/includes/class-cvd-launch-polish.php`, 'utf8');
const js = fs.readFileSync(`${root}/assets/launch-polish.js`, 'utf8');
const css = fs.readFileSync(`${root}/assets/launch-polish.css`, 'utf8');
const svg = fs.readFileSync(`${root}/assets/category-fallback.svg`, 'utf8');

function must(condition, message) {
  if (!condition) throw new Error(message);
}

must(plugin.includes("Version: 3.13.6") && plugin.includes("CVD_VERSION', '3.13.6"), 'La versión 3.13.6 debe identificar el candidato de launch polish.');
must(plugin.includes('class-cvd-launch-polish.php') && plugin.includes('CVD_Launch_Polish::register()'), 'Launch polish debe cargarse y registrarse desde el Core.');
must(php.includes('is_front_page()') && php.includes("$_SERVER['REQUEST_URI']") && php.includes("home_url( '/' )"), 'El alcance SEO/assets debe limitarse a la URL canónica de portada.');
must(php.includes('pre_get_document_title'), 'Falta título técnico de portada.');
must(php.includes('name="description"') && php.includes('rel="canonical"'), 'Faltan description o canonical de portada.');
must(php.includes('cvd-launch-home-h1'), 'Falta H1 semántico de portada.');
must(php.includes('categoryFallback') && php.includes('category-fallback.svg'), 'Falta fallback versionado para imágenes de categoría.');
must(js.includes('a[href*="/categoria-producto/"] img'), 'La reparación debe limitarse a imágenes enlazadas a categorías WooCommerce.');
must(js.includes("addEventListener('error'") && js.includes('MutationObserver'), 'La reparación debe cubrir errores y categorías cargadas dinámicamente.');
must(js.includes("srcset = ''") && js.includes("sizes = ''"), 'La reparación debe neutralizar variantes rotas antes del fallback.');
must(css.includes('@media(max-width:640px)') && css.includes('min-height:120px'), 'Falta comportamiento móvil del fallback.');
must(css.includes('.cv-market-shelf{position:relative}'), 'Las estanterías horizontales de portada deben contener los textos absolutos de precio (overflow móvil).');
must(css.includes('prefers-reduced-motion'), 'Launch polish debe respetar reducción de movimiento.');
must(svg.includes('viewBox="0 0 640 420"') && svg.includes('#004042'), 'El fallback debe ser local, escalable y coherente con Casa Viva.');

for (const forbidden of ['cvd_shipping_rates', 'set_stock_quantity', 'update_post_meta', 'update_option(', 'wc_get_order(', 'CVD_Payouts::', 'CVD_Order_Transition_Service::']) {
  must(!php.includes(forbidden), `Launch polish no debe ejecutar mutaciones comerciales/operativas: ${forbidden}`);
}

console.log('CV-LAUNCH-POLISH-001 contract OK');
