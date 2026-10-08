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
 * - lo retenido a mano (modo hold, meta `_cvd_hold`) sigue privado aunque esté en BizneCubano;
 *   el modo unhold lo devuelve a la sincronización normal;
 * - nunca borra nada ni toca pedidos, comisiones ni payouts;
 * - idempotente: repetirlo no duplica productos ni movimientos.
 */

defined( 'ABSPATH' ) || exit( 1 );

$file  = getenv( 'CVD_SYNC_FILE' ) ?: '/tmp/biznecubano.json';
$mode  = in_array( getenv( 'CVD_SYNC_MODE' ), array( 'apply', 'restore', 'hold', 'unhold' ), true ) ? getenv( 'CVD_SYNC_MODE' ) : 'dry-run';
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

// Retener: oculta los SKU indicados y evita que la sincronización los vuelva a publicar.
if ( 'hold' === $mode || 'unhold' === $mode ) {
	$skus = array_filter( array_map( 'trim', explode( ',', (string) getenv( 'CVD_SYNC_SKUS' ) ) ) );
	$done = array();
	foreach ( $skus as $sku ) {
		$id      = wc_get_product_id_by_sku( $sku );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product instanceof WC_Product ) {
			$done[] = array( 'sku' => $sku, 'error' => 'no existe' );
			continue;
		}
		if ( 'hold' === $mode ) {
			$product->update_meta_data( '_cvd_hold', gmdate( 'c' ) );
			$product->set_status( 'private' );
		} else {
			$product->delete_meta_data( '_cvd_hold' );
		}
		$product->save();
		$done[] = array( 'id' => $product->get_id(), 'sku' => $sku, 'name' => $product->get_name(), 'status' => $product->get_status() );
	}
	echo wp_json_encode( array( 'mode' => $mode, 'products' => $done, 'totals' => array( $mode => count( $done ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
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

/** Libro de inventario: deja anotado un conteo hecho por la sincronización. */
function cvd_sync_log_count( int $product_id, int $variation_id, float $from, float $to, string $url ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'cvd_inventory_movements';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}
	$wpdb->insert(
		$table,
		array(
			'movement_uuid' => wp_generate_uuid4(), 'product_id' => $product_id, 'variation_id' => $variation_id,
			'movement_type' => 'count', 'quantity_delta' => $to - $from, 'stock_before' => $from, 'stock_after' => $to,
			'reason' => 'Sincronización con BizneCubano', 'reference_type' => 'biznecubano', 'reference_id' => 0,
			'actor_user_id' => 0, 'created_at' => current_time( 'mysql', true ),
			'metadata' => wp_json_encode( array( 'source' => 'biznecubano-sync', 'url' => $url ) ),
		),
		array( '%s', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s' )
	);
}

/**
 * Colores (variantes) de BizneCubano → variaciones de WooCommerce con su cantidad.
 * - si en la web era simple y en BizneCubano tiene colores, pasa a variable;
 * - color que falta en la web y tiene existencias: se crea;
 * - color que está en la web y ya no en BizneCubano (o con 0): queda agotado (nunca se borra).
 * Devuelve la lista de cambios (y los aplica si $apply).
 */
function cvd_sync_variations( WC_Product $product, array $p, bool $apply ): array {
	$source = array();
	foreach ( (array) ( $p['variations'] ?? array() ) as $v ) {
		$name = sanitize_title( (string) ( $v['name'] ?? '' ) );
		if ( '' !== $name ) {
			$source[ $name ] = $v;
		}
	}
	if ( ! $source ) {
		return array();
	}
	$changes = array();
	$attr_key = 'color';

	if ( ! $product->is_type( 'variable' ) ) {
		$changes[] = array( 'action' => 'convertir a producto con colores', 'from' => $product->get_type() );
		if ( ! $apply ) {
			foreach ( $source as $name => $v ) {
				if ( (int) $v['qty'] > 0 ) {
					$changes[] = array( 'action' => 'crear color', 'color' => $name, 'qty' => (int) $v['qty'] );
				}
			}
			return $changes;
		}
		$fallback_price = $product->get_regular_price();
		wp_set_object_terms( $product->get_id(), 'variable', 'product_type' );
		$product = new WC_Product_Variable( $product->get_id() );
		$product->set_manage_stock( false );
		$product->update_meta_data( '_cvd_sync_fallback_price', $fallback_price );
		$product->save();
	}

	// Atributo "color" local con todas las opciones conocidas (las de la web y las de BizneCubano).
	$attributes = $product->get_attributes();
	foreach ( $attributes as $key => $attribute ) {
		if ( ! $attribute->is_taxonomy() && $attribute->get_variation() ) {
			$attr_key = $key;
			break;
		}
	}
	$existing = array();
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );
		if ( $variation ) {
			$existing[ sanitize_title( (string) $variation->get_attribute( $attr_key ) ) ] = $variation;
		}
	}
	$options = array_values( array_unique( array_merge( array_keys( $existing ), array_keys( $source ) ) ) );
	if ( $apply ) {
		$attribute = $attributes[ $attr_key ] ?? new WC_Product_Attribute();
		$attribute->set_name( $attr_key );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$attributes[ $attr_key ] = $attribute;
		$product->set_attributes( $attributes );
		$product->save();
	}

	$fallback = (string) ( $product->get_meta( '_cvd_sync_fallback_price' ) ?: $product->get_price() );
	foreach ( $source as $name => $v ) {
		$qty = max( 0, (int) $v['qty'] );
		$variation = $existing[ $name ] ?? null;
		if ( ! $variation ) {
			if ( $qty <= 0 ) {
				continue;
			}
			$changes[] = array( 'action' => 'crear color', 'color' => $name, 'qty' => $qty );
			if ( $apply ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes( array( $attr_key => $name ) );
				$regular = $v['regular_price'] ?? null;
				$sale = $v['price'] ?? null;
				$variation->set_regular_price( cvd_sync_money( $regular ?: $fallback ) );
				if ( $sale && $regular && (float) $sale < (float) $regular ) {
					$variation->set_sale_price( cvd_sync_money( $sale ) );
				}
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $qty );
				$variation->set_stock_status( 'instock' );
				$variation->set_status( 'publish' );
				$new_id = $variation->save();
				cvd_sync_log_count( $product->get_id(), $new_id, 0, $qty, (string) ( $p['url'] ?? '' ) );
			}
			continue;
		}
		$before = $variation->get_manage_stock() ? (int) $variation->get_stock_quantity() : null;
		if ( $before === $qty && $variation->get_manage_stock() ) {
			continue;
		}
		$changes[] = array( 'action' => 'cantidad', 'color' => $name, 'before' => $before, 'after' => $qty );
		if ( $apply ) {
			$variation->set_manage_stock( true );
			$variation->save();
			wc_update_product_stock( $variation, $qty, 'set' );
			cvd_sync_log_count( $product->get_id(), $variation->get_id(), (float) ( $before ?? 0 ), $qty, (string) ( $p['url'] ?? '' ) );
		}
	}
	// Colores que la web tiene y BizneCubano ya no: agotados.
	foreach ( $existing as $name => $variation ) {
		if ( isset( $source[ $name ] ) ) {
			continue;
		}
		if ( $variation->get_manage_stock() && 0 === (int) $variation->get_stock_quantity() ) {
			continue;
		}
		$changes[] = array( 'action' => 'agotar color (ya no está en BizneCubano)', 'color' => $name, 'before' => $variation->get_manage_stock() ? (int) $variation->get_stock_quantity() : null );
		if ( $apply ) {
			$before = (float) ( $variation->get_stock_quantity() ?? 0 );
			$variation->set_manage_stock( true );
			$variation->save();
			wc_update_product_stock( $variation, 0, 'set' );
			cvd_sync_log_count( $product->get_id(), $variation->get_id(), $before, 0, (string) ( $p['url'] ?? '' ) );
		}
	}
	if ( $apply && $changes ) {
		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
	}
	return $changes;
}

/** Publica un producto que está en BizneCubano si estaba en borrador u oculto por la sincronización. */
function cvd_sync_publish( WC_Product $product, bool $apply ): bool {
	if ( '' !== (string) $product->get_meta( '_cvd_hold' ) ) {
		return false;
	}
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
	'variation_updates' => array(),
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
			if ( 'variable' === ( $p['type'] ?? '' ) && ! empty( $p['variations'] ) ) {
				$var_changes = cvd_sync_variations( $product, $p, $apply );
				if ( $var_changes ) {
					$report['variation_updates'][] = array( 'id' => $product_id, 'sku' => $sku, 'name' => $product->get_name(), 'changes' => $var_changes );
				} else {
					$report['unchanged']++;
				}
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				$report['skipped'][] = array( 'sku' => $sku, 'name' => $p['name'], 'reason' => 'en la web tiene colores y en BizneCubano no: revisar a mano' );
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
		$entry = array( 'sku' => $sku, 'name' => $p['name'], 'regular' => cvd_sync_money( $regular ), 'sale' => $on_sale ? cvd_sync_money( $price ) : '', 'categories' => $p['categories'] ?? array(), 'source' => $p['url'], 'qty' => $p['qty'] ?? null, 'colors' => array_map( static fn( $v ) => $v['name'] . ' ' . $v['qty'], (array) ( $p['variations'] ?? array() ) ) );
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
			if ( 'variable' === ( $p['type'] ?? '' ) && ! empty( $p['variations'] ) ) {
				cvd_sync_variations( wc_get_product( $new_id ), $p, true );
			} else {
				cvd_sync_stock( wc_get_product( $new_id ), $p, true );
			}
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
	'variation_updates' => count( $report['variation_updates'] ),
	'hidden'         => count( $report['hidden'] ),
	'no_image'       => count( $report['no_image'] ),
	'unchanged'      => $report['unchanged'],
	'skipped'        => count( $report['skipped'] ),
	'errors'         => count( $report['errors'] ),
);
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
