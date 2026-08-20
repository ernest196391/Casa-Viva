import fs from 'node:fs';

const root = 'wordpress/casa-viva-dropship-core';
const plugin = fs.readFileSync(`${root}/casa-viva-dropship-core.php`, 'utf8');
const php = fs.readFileSync(`${root}/includes/class-cvd-catalog-presentation.php`, 'utf8');
const css = fs.readFileSync(`${root}/assets/catalog-presentation.css`, 'utf8');

function must(condition, message) {
  if (!condition) throw new Error(message);
}

must(plugin.includes('class-cvd-catalog-presentation.php'), 'El módulo de catálogo debe cargarse desde el plugin.');
must(plugin.includes('CVD_Catalog_Presentation::register()'), 'El módulo de catálogo debe registrarse.');
must(php.includes("wp_enqueue_style( 'cvd-catalog-presentation'"), 'La capa visual debe cargarse mediante un asset propio.');
must(php.includes("is_shop()") && php.includes('is_product_category()') && php.includes('is_product_tag()'), 'La capa debe limitarse a superficies de catálogo WooCommerce.');
must(php.includes("return 'Agotado';"), 'Los productos sin stock deben comunicar Agotado sin modificar inventario.');
must(!php.includes('set_stock_quantity') && !php.includes('update_post_meta') && !php.includes('wp_update_post'), 'La capa de presentación no debe mutar producto ni stock.');
must(css.includes('aspect-ratio:1/1') && css.includes('object-fit:contain'), 'Las imágenes deben tener presentación consistente sin recorte destructivo.');
must(css.includes(':focus-visible'), 'Debe existir foco visible en catálogo.');
must(css.includes('prefers-reduced-motion'), 'Debe respetarse reducción de movimiento.');

console.log('Catalog presentation 7C.3 contract OK');
