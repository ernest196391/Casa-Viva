import fs from 'node:fs';

const root = 'wordpress/casa-viva-dropship-core';
const plugin = fs.readFileSync(`${root}/casa-viva-dropship-core.php`, 'utf8');
const php = fs.readFileSync(`${root}/includes/class-cvd-market-bridge.php`, 'utf8');
const sync = fs.readFileSync('scripts/catalog/biznecubano-sync.php', 'utf8');
const must = (c, m) => { if (!c) throw new Error(m); };

must(plugin.includes('class-cvd-market-bridge.php') && plugin.includes('CVD_Market_Bridge::register()'), 'El puente del mercado debe cargarse y registrarse.');
must(php.includes("array( 'CVD_Bot_Bridge', 'authorized' )"), 'El mercado debe usar la misma clave del bot.');
must(php.includes("SKU_PREFIX = 'MK-'") && php.includes("'Bajo pedido'"), 'SKU MK- y categoría Bajo pedido.');
must(php.includes("'_cvd_commission_type', 'fixed'"), 'Comisión fija por producto.');
must(php.includes('set_manage_stock( false )'), 'Sin control de stock: se confirma con el proveedor.');
must(/supabase\\\.co/.test(php), 'Solo imágenes de Supabase Storage.');
must(!/seller|proveedor_tel|seller_phone/i.test(php.replace(/El proveedor nunca se guarda aquí/, '')), 'El proveedor no se guarda en la web.');
must(sync.includes("0 === strpos( $sku, 'MK-' )"), 'La sincronización de BizneCubano no debe ocultar MK-*.');
must(php.includes('_cvd_market_version') && php.includes('stale('), 'Publicar y retirar deben ser idempotentes por versión.');
must(php.includes('$commission <= 0'), 'La comisión debe ser positiva.');
must(sync.includes('function cvd_sync_photo') && sync.includes('_cvd_bc_image'), 'La sincronización debe actualizar la foto cuando cambia en BizneCubano.');
console.log('Market bridge contract OK');
