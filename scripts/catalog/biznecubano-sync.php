<?php
/**
 * Sincroniza el catálogo público de Casa Viva en BizneCubano con WooCommerce.
 *
 * Uso (WP-CLI): CVD_SYNC_FILE=/tmp/biznecubano.json CVD_SYNC_MODE=dry-run wp eval-file biznecubano-sync.php
 *
 * Reglas:
 * - solo toca productos con SKU `BC-*` (nunca NEXO ni otros);
 * - productos existentes: actualiza precio normal y de oferta;
 * - productos nuevos: se crean como BORRADOR con foto, descripción y categoría;
 * - no cambia cantidades de stock (las gestiona el motor de inventario), no borra nada
 *   y no toca pedidos, comisiones ni payouts;
 * - idempotente: repetirlo no duplica productos.
 */

defined( 'ABSPATH' ) || exit( 1 );

$file  = getenv( 'CVD_SYNC_FILE' ) ?: '/tmp/biznecubano.json';
$mode  = getenv( 'CVD_SYNC_MODE' ) === 'apply' ? 'apply' : 'dry-run';
$limit = (int) ( getenv( 'CVD_SYNC_LIMIT' ) ?: 0 );
$apply = 'apply' === $mode;

$data = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
$products = $data['products'] ?? array();

const CVD_SYNC_CATEGORY_MAP = array(
	'Baño'                       => 'Baño',
	'Cocina'                     => 'Cocina',
	'Habitación'                 => 'Habitación',
	'Sala'                       => 'Sala y muebles',
	'Higiene & Cuidado Personal' => 'Cuidado personal',
	'Electrodomésticos'          => 'Electrodomésticos',
	'Ferretería'                 => 'Ferretería',
	'Otros'                      => 'Otros',
);

function cvd_sync_sku( array $p ): string {
	if ( preg_match( '#/products/(\d+)/#', (string) ( $p['image'] ?? '' ), $m ) ) {
		return 'BC-' . $m[1];
	}
	return 'BC-P-' . sanitize_key( (string) $p['code'] );
}

function cvd_sync_money( $value ): string {
	return null === $value || '' === $value ? '' : wc_format_decimal( (float) $value, 2 );
}

function cvd_sync_description( string $raw ): string {
	$lines = preg_split( '/\R/', $raw );
	$keep  = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		// El precio vive en WooCommerce; no se duplica en el texto para que no quede desfasado.
		if ( '' === $line || preg_match( '/precio\s*:/iu', $line ) || preg_match( '/^Ver (más|menos)/iu', $line ) ) {
			continue;
		}
		$keep[] = esc_html( $line );
	}
	return $keep ? '<p>' . implode( "</p>\n<p>", $keep ) . '</p>' : '';
}

function cvd_sync_term_ids( array $source_categories, bool $apply, array &$created ): array {
	$ids = array();
	foreach ( $source_categories as $source ) {
		$source = trim( strtok( (string) $source, "\n" ) );
		$name = CVD_SYNC_CATEGORY_MAP[ $source ] ?? null;
		if ( ! $name ) {
			continue;
		}
		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( ! $term && $apply ) {
			$new = wp_insert_term( $name, 'product_cat' );
			if ( ! is_wp_error( $new ) ) {
				$term = get_term( $new['term_id'], 'product_cat' );
				$created[ $name ] = true;
			}
		} elseif ( ! $term ) {
			$created[ $name ] = true;
		}
		if ( $term && ! is_wp_error( $term ) ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return array_values( array_unique( $ids ) );
}

function cvd_sync_image( string $url, int $product_id, string $name ): int {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$id = media_sideload_image( $url, $product_id, $name, 'id' );
	return is_wp_error( $id ) ? 0 : (int) $id;
}

$report = array(
	'mode'            => $mode,
	'source_count'    => count( $products ),
	'price_updates'   => array(),
	'created_drafts'  => array(),
	'unchanged'       => 0,
	'skipped'         => array(),
	'stock_mismatch'  => array(),
	'not_in_source'   => array(),
	'categories_new'  => array(),
	'errors'          => array(),
);
$created_terms = array();
$seen_skus = array();
$created_count = 0;

foreach ( $products as $p ) {
	$sku = cvd_sync_sku( $p );
	$seen_skus[ $sku ] = true;
	$price   = $p['price'] ?? null;
	$regular = $p['regular_price'] ?? $price;
	if ( null === $price ) {
		$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'sin precio en BizneCubano' );
		continue;
	}
	$on_sale = (float) $price < (float) $regular;
	$product_id = wc_get_product_id_by_sku( $sku );

	try {
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || $product->is_type( 'variable' ) ) {
				$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'producto variable o ilegible' );
				continue;
			}
			$before = array( 'regular' => $product->get_regular_price(), 'sale' => $product->get_sale_price() );
			$after  = array( 'regular' => cvd_sync_money( $regular ), 'sale' => $on_sale ? cvd_sync_money( $price ) : '' );
			$source_out = ! empty( $p['out_of_stock'] );
			if ( $source_out === $product->is_in_stock() ) {
				$report['stock_mismatch'][] = array( 'id' => $product_id, 'sku' => $sku, 'name' => $product->get_name(), 'woocommerce' => $product->get_stock_status(), 'biznecubano' => $source_out ? 'agotado' : 'disponible', 'stock_hint' => $p['stock_hint'] ?? null );
			}
			if ( (float) $before['regular'] === (float) $after['regular'] && (float) $before['sale'] === (float) $after['sale'] && ( '' === $before['sale'] ) === ( '' === $after['sale'] ) ) {
				$report['unchanged']++;
				continue;
			}
			if ( $apply ) {
				$product->set_regular_price( $after['regular'] );
				$product->set_sale_price( $after['sale'] );
				$product->update_meta_data( '_cvd_biznecubano_synced_at', gmdate( 'c' ) );
				$product->save();
			}
			$report['price_updates'][] = array( 'id' => $product_id, 'sku' => $sku, 'name' => $product->get_name(), 'before' => $before, 'after' => $after );
			continue;
		}

		if ( $limit && $created_count >= $limit ) {
			$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'límite de creación alcanzado' );
			continue;
		}
		$term_ids = cvd_sync_term_ids( (array) ( $p['categories'] ?? array() ), $apply, $created_terms );
		$entry = array( 'sku' => $sku, 'name' => $p['name'], 'regular' => cvd_sync_money( $regular ), 'sale' => $on_sale ? cvd_sync_money( $price ) : '', 'categories' => $p['categories'] ?? array(), 'source' => $p['url'] );
		if ( $apply ) {
			$product = new WC_Product_Simple();
			$product->set_name( (string) $p['name'] );
			$product->set_status( 'draft' );
			$product->set_sku( $sku );
			$product->set_regular_price( $entry['regular'] );
			$product->set_sale_price( $entry['sale'] );
			$product->set_description( cvd_sync_description( (string) ( $p['description'] ?? '' ) ) );
			$product->set_stock_status( ! empty( $p['out_of_stock'] ) ? 'outofstock' : 'instock' );
			$product->set_category_ids( $term_ids );
			$product->update_meta_data( '_cvd_biznecubano_url', esc_url_raw( (string) $p['url'] ) );
			$product->update_meta_data( '_cvd_biznecubano_synced_at', gmdate( 'c' ) );
			$new_id = $product->save();
			if ( ! empty( $p['image'] ) ) {
				$image_id = cvd_sync_image( (string) $p['image'], $new_id, (string) $p['name'] );
				if ( $image_id ) {
					$product = wc_get_product( $new_id );
					$product->set_image_id( $image_id );
					$product->save();
				} else {
					$report['errors'][] = array( 'sku' => $sku, 'error' => 'no se pudo descargar la foto' );
				}
			}
			$entry['id'] = $new_id;
		}
		$created_count++;
		$report['created_drafts'][] = $entry;
	} catch ( Throwable $e ) {
		$report['errors'][] = array( 'sku' => $sku, 'error' => $e->getMessage() );
	}
}

// Productos BC-* que ya no están en BizneCubano: solo se informan.
$existing = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ), 'return' => 'objects' ) );
foreach ( $existing as $product ) {
	$sku = (string) $product->get_sku();
	if ( 0 === strpos( $sku, 'BC-' ) && empty( $seen_skus[ $sku ] ) ) {
		$report['not_in_source'][] = array( 'id' => $product->get_id(), 'sku' => $sku, 'name' => $product->get_name(), 'status' => $product->get_status(), 'stock' => $product->get_stock_status() );
	}
}
$report['categories_new'] = array_keys( $created_terms );
$report['totals'] = array(
	'price_updates'  => count( $report['price_updates'] ),
	'created_drafts' => count( $report['created_drafts'] ),
	'unchanged'      => $report['unchanged'],
	'skipped'        => count( $report['skipped'] ),
	'stock_mismatch' => count( $report['stock_mismatch'] ),
	'not_in_source'  => count( $report['not_in_source'] ),
	'errors'         => count( $report['errors'] ),
);
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
