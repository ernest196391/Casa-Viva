import fs from 'node:fs';

const root = 'wordpress/casa-viva-dropship-core';
const plugin = fs.readFileSync(`${root}/casa-viva-dropship-core.php`, 'utf8');
const php = fs.readFileSync(`${root}/includes/class-cvd-customer-navigation.php`, 'utf8');
const js = fs.readFileSync(`${root}/assets/customer-navigation.js`, 'utf8');
const css = fs.readFileSync(`${root}/assets/customer-navigation.css`, 'utf8');

function must(condition, message) {
  if (!condition) throw new Error(message);
}

must(plugin.includes('class-cvd-customer-navigation.php'), 'El módulo de navegación debe cargarse desde el plugin.');
must(plugin.includes('CVD_Customer_Navigation::register()'), 'El módulo de navegación debe registrarse.');
for (const label of ['Inicio', 'Comprar', 'Carrito', 'Pedidos', 'Mi cuenta']) {
  must(php.includes(`'${label}'`), `Falta la entrada ${label}.`);
}
must(php.includes('woocommerce_add_to_cart_fragments'), 'El badge debe integrarse con fragmentos WooCommerce.');
must(php.includes('wp_ajax_nopriv_cvd_cart_count'), 'El contador debe funcionar para clientes sin sesión.');
must(php.includes("array( 'gestora', 'mensajero' )"), 'La navegación no debe mostrarse en portales de programa.');
must(php.includes("'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator'"), 'La navegación no debe invadir superficies internas.');
must(js.includes('added_to_cart') && js.includes('removed_from_cart') && js.includes('updated_wc_div'), 'El contador debe reaccionar a cambios del carrito.');
must(js.includes('.woocommerce-cart-form input.qty'), 'El contador debe responder al cambio de cantidad.');
must(css.includes('@media(max-width:820px)'), 'La navegación debe ser mobile-first.');
must(css.includes('env(safe-area-inset-bottom'), 'Debe respetarse el safe area inferior.');

console.log('Customer navigation 3A contract OK');
