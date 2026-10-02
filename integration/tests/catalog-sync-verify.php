<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_catalog_sync_fixture' );
$phase   = $args[0] ?? 'applied';
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL catálogo: ' . $message ); }
};

$existing = wc_get_product( $fixture['existing'] );
$nexo     = wc_get_product( $fixture['nexo'] );
$gone     = wc_get_product( $fixture['gone'] );
$drafts   = wc_get_products( array( 'sku' => 'BC-P-bbbb2', 'status' => array( 'draft', 'publish', 'private' ), 'limit' => -1 ) );
$ledger   = static function ( int $id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cvd_inventory_movements WHERE product_id=%d AND reference_type='biznecubano'", $id ) );
};

$assert( '99' === $nexo->get_regular_price() && '20' === $gone->get_regular_price(), 'Ocultar no cambia precios.' );
$assert( ! wc_get_product_id_by_sku( 'BC-P-cccc3' ), 'Un producto sin precio no se crea.' );

if ( 'restored' === $phase ) {
	foreach ( array( $nexo, $gone ) as $restored ) {
		$assert( 'publish' === $restored->get_status() && '' === $restored->get_meta( '_cvd_sync_hidden_from' ), 'La reversión restaura lo oculto: ' . $restored->get_sku() );
	}
} elseif ( 'dry-run' === $phase ) {
	$assert( '10' === $existing->get_regular_price() && '' === $existing->get_sale_price(), 'El dry-run no debe cambiar precios.' );
	$assert( 0 === count( $drafts ), 'El dry-run no debe crear productos.' );
	$assert( 'outofstock' === $existing->get_stock_status() && 0 === $ledger( $existing->get_id() ), 'El dry-run no cambia stock.' );
	$assert( 'publish' === $nexo->get_status() && 'publish' === $gone->get_status(), 'El dry-run no oculta nada.' );
} else {
	$assert( 10.0 === (float) $existing->get_regular_price() && 8.0 === (float) $existing->get_sale_price(), 'El precio de oferta debe sincronizarse.' );
	$assert( $existing->get_manage_stock() && 2 === (int) $existing->get_stock_quantity() && 'instock' === $existing->get_stock_status(), 'El stock debe igualar "quedan 2".' );
	$assert( 1 === $ledger( $existing->get_id() ), 'El cambio de stock queda una sola vez en el libro de inventario.' );
	foreach ( array( $nexo, $gone ) as $hidden ) {
		$assert( 'private' === $hidden->get_status() && 'publish' === $hidden->get_meta( '_cvd_sync_hidden_from' ), 'Lo que no está en BizneCubano se oculta y es reversible: ' . $hidden->get_sku() );
	}
	$assert( 1 === count( $drafts ), 'Debe existir exactamente un producto nuevo (idempotencia).' );
	$draft = $drafts[0];
	$assert( 'publish' === $draft->get_status(), 'Los productos nuevos se publican.' );
	$assert( ! $draft->get_manage_stock() && 'instock' === $draft->get_stock_status(), 'Disponible sin cantidad queda disponible.' );
	$assert( 15.0 === (float) $draft->get_regular_price() && '' === $draft->get_sale_price(), 'Precio del nuevo producto.' );
	$assert( false === stripos( $draft->get_description(), 'precio' ), 'La descripción no duplica el precio.' );
	$assert( false === strpos( $draft->get_description(), '<b>' ), 'La descripción se escapa.' );
	$names = wp_list_pluck( array_map( 'get_term', $draft->get_category_ids() ), 'name' );
	sort( $names );
	$assert( array( 'Otros', 'Sala y muebles' ) === $names, 'Mapeo de categorías: ' . implode( ',', $names ) );
}
echo "OK: sincronización de catálogo verificada ({$phase}).\n";
