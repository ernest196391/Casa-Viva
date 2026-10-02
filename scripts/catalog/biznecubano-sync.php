<?php
/**
 * Sincroniza el catálogo público de Casa Viva en BizneCubano con WooCommerce.
 *
 * Uso (WP-CLI): CVD_SYNC_FILE=/tmp/biznecubano.json CVD_SYNC_MODE=dry-run wp eval-file biznecubano-sync.php
 *
 * Reglas (BizneCubano es la fuente del catálogo de Casa Viva; aprobado por Ernesto el 2026-10-02):
 * - productos `BC-*` existentes: precio normal y de oferta, stock y publicación;
 * - productos nuevos: se publican con foto, descripción y categoría;
 * - stock: agotado => 0; "quedan N" => N; disponible sin cantidad => disponible sin control.
 *   Cada cambio de cantidad queda en el libro de movimientos de inventario como conteo;
 * - lo publicado que no está en BizneCubano (BC retirados, NEXO y otros) se oculta como privado,
 *   guardando el estado anterior en `_cvd_sync_hidden_from` para poder restaurarlo;
 * - nunca borra nada ni toca pedidos, comisiones ni payouts;
 * - idempotente: repetirlo no duplica productos ni movimientos.
 */

defined( 'ABSPATH' ) || exit( 1 );

$file  = getenv( 'CVD_SYNC_FILE' ) ?: '/tmp/biznecubano.json';
$mode  = in_array( getenv( 'CVD_SYNC_MODE' ), array( 'apply', 'restore' ), true ) ? getenv( 'CVD_SYNC_MODE' ) : 'dry-run';
$limit = (int) ( getenv( 'CVD_SYNC_LIMIT' ) ?: 0 );
$apply = 'apply' === $mode;

// Reversión: devuelve a su estado anterior todo lo que la sincronización ocultó.
if ( 'restore' === $mode ) {
	$restored = array();
	foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'private' ), 'return' => 'objects' ) ) as $product ) {
		if ( '' === (string) $product->get_meta( '_cvd_sync_hidden_from' ) ) {
			continue;
		}
		$product->set_status( (string) $product->get_meta( '_cvd_sync_hidden_from' ) ?: 'publish' );
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product->save();
		$restored[] = array( 'id' => $product->get_id(), 'sku' => $product->get_sku(), 'name' => $product->get_name() );
	}
	echo wp_json_encode( array( 'mode' => 'restore', 'restored' => $restored, 'totals' => array( 'restored' => count( $restored ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}

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

function cvd_sync_stock_target( array $p ): array {
	if ( ! empty( $p['out_of_stock'] ) ) {
		return array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' );
	}
	if ( is_int( $p['stock_hint'] ?? null ) && $p['stock_hint'] > 0 ) {
		return array( 'manage' => true, 'qty' => $p['stock_hint'], 'status' => 'instock' );
	}
	return array( 'manage' => false, 'qty' => null, 'status' => 'instock' );
}

/** Devuelve el cambio de stock necesario (o null) y lo aplica si $apply. */
function cvd_sync_stock( WC_Product $product, array $p, bool $apply ): ?array {
	global $wpdb;
	$target = cvd_sync_stock_target( $p );
	$before = array(
		'manage' => $product->get_manage_stock(),
		'qty'    => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
		'status' => $product->get_stock_status(),
	);
	// Disponible sin cantidad: si ya hay existencias controladas positivas, se respetan.
	if ( ! $target['manage'] && $before['manage'] && $before['qty'] > 0 ) {
		return null;
	}
	if ( $before['manage'] === $target['manage'] && $before['qty'] === $target['qty'] && $before['status'] === $target['status'] ) {
		return null;
	}
	if ( $apply ) {
		if ( $target['manage'] ) {
			$product->set_manage_stock( true );
			$product->save();
			wc_update_product_stock( $product, $target['qty'], 'set' );
			$table = $wpdb->prefix . 'cvd_inventory_movements';
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$from = (float) ( $before['qty'] ?? 0 );
				$wpdb->insert(
					$table,
					array(
						'movement_uuid'  => wp_generate_uuid4(),
						'product_id'     => $product->get_id(),
						'variation_id'   => 0,
						'movement_type'  => 'count',
						'quantity_delta' => $target['qty'] - $from,
						'stock_before'   => $from,
						'stock_after'    => $target['qty'],
						'reason'         => 'Sincronización con BizneCubano',
						'reference_type' => 'biznecubano',
						'reference_id'   => 0,
						'actor_user_id'  => 0,
						'created_at'     => current_time( 'mysql', true ),
						'metadata'       => wp_json_encode( array( 'source' => 'biznecubano-sync', 'url' => $p['url'] ?? '' ) ),
					),
					array( '%s', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s' )
				);
			}
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$product->save();
		}
	}
	return array( 'id' => $product->get_id(), 'sku' => $product->get_sku(), 'name' => $product->get_name(), 'before' => $before, 'after' => $target );
}

/** Publica un producto que está en BizneCubano si estaba en borrador u oculto por la sincronización. */
function cvd_sync_publish( WC_Product $product, bool $apply ): bool {
	$hidden_from = (string) $product->get_meta( '_cvd_sync_hidden_from' );
	if ( 'publish' === $product->get_status() && '' === $hidden_from ) {
		return false;
	}
	if ( ! in_array( $product->get_status(), array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
		return false;
	}
	if ( $apply ) {
		$product->set_status( 'publish' );
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product->save();
	}
	return true;
}

$report = array(
	'mode'            => $mode,
	'source_count'    => count( $products ),
	'price_updates'   => array(),
	'created'         => array(),
	'published'       => array(),
	'stock_updates'   => array(),
	'hidden'          => array(),
	'no_image'        => array(),
	'unchanged'       => 0,
	'skipped'         => array(),
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
			if ( ! $product ) {
				$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'producto ilegible' );
				continue;
			}
			$from_status = $product->get_status();
			if ( cvd_sync_publish( $product, $apply ) ) {
				$report['published'][] = array( 'id' => $product_id, 'sku' => $sku, 'name' => $product->get_name(), 'from' => $from_status );
				$product = wc_get_product( $product_id );
			}
			if ( ! $product->get_image_id() ) {
				$report['no_image'][] = array( 'id' => $product_id, 'sku' => $sku, 'name' => $product->get_name() );
			}
			if ( $product->is_type( 'variable' ) ) {
				// Precio y stock viven en cada variante; BizneCubano no las expone por separado.
				$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'producto variable: precio y stock por variante' );
				continue;
			}
			$stock_change = cvd_sync_stock( $product, $p, $apply );
			if ( $stock_change ) {
				$report['stock_updates'][] = $stock_change;
				$product = wc_get_product( $product_id );
			}
			$before = array( 'regular' => $product->get_regular_price(), 'sale' => $product->get_sale_price() );
			$after  = array( 'regular' => cvd_sync_money( $regular ), 'sale' => $on_sale ? cvd_sync_money( $price ) : '' );
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
			$product->set_status( 'publish' );
			$product->set_sku( $sku );
			$product->set_regular_price( $entry['regular'] );
			$product->set_sale_price( $entry['sale'] );
			$product->set_description( cvd_sync_description( (string) ( $p['description'] ?? '' ) ) );
			$product->set_category_ids( $term_ids );
			$product->update_meta_data( '_cvd_biznecubano_url', esc_url_raw( (string) $p['url'] ) );
			$product->update_meta_data( '_cvd_biznecubano_synced_at', gmdate( 'c' ) );
			$new_id = $product->save();
			cvd_sync_stock( wc_get_product( $new_id ), $p, true );
			if ( ! empty( $p['image'] ) ) {
				$image_id = cvd_sync_image( (string) $p['image'], $new_id, (string) $p['name'] );
				if ( $image_id ) {
					$product = wc_get_product( $new_id );
					$product->set_image_id( $image_id );
					$product->save();
				} else {
					$report['errors'][] = array( 'sku' => $sku, 'error' => 'no se pudo descargar la foto' );
					$report['no_image'][] = array( 'id' => $new_id, 'sku' => $sku, 'name' => (string) $p['name'] );
				}
			}
			$entry['id'] = $new_id;
		}
		$created_count++;
		$report['created'][] = $entry;
	} catch ( Throwable $e ) {
		$report['errors'][] = array( 'sku' => $sku, 'error' => $e->getMessage() );
	}
}

// Todo lo publicado que no está en BizneCubano (BC retirados, NEXO y otros) se oculta como privado.
$existing = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish' ), 'return' => 'objects' ) );
foreach ( $existing as $product ) {
	$sku = (string) $product->get_sku();
	if ( ! empty( $seen_skus[ $sku ] ) ) {
		continue;
	}
	if ( $apply ) {
		$product->update_meta_data( '_cvd_sync_hidden_from', $product->get_status() );
		$product->set_status( 'private' );
		$product->save();
	}
	$report['hidden'][] = array( 'id' => $product->get_id(), 'sku' => $sku, 'name' => $product->get_name(), 'reason' => 0 === strpos( $sku, 'BC-' ) ? 'ya no está en BizneCubano' : 'no está en BizneCubano' );
}
$report['categories_new'] = array_keys( $created_terms );
$report['totals'] = array(
	'price_updates'  => count( $report['price_updates'] ),
	'created'        => count( $report['created'] ),
	'published'      => count( $report['published'] ),
	'stock_updates'  => count( $report['stock_updates'] ),
	'hidden'         => count( $report['hidden'] ),
	'no_image'       => count( $report['no_image'] ),
	'unchanged'      => $report['unchanged'],
	'skipped'        => count( $report['skipped'] ),
	'errors'         => count( $report['errors'] ),
);
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
