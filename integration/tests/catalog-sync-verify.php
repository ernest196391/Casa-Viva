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
$drafts   = wc_get_products( array( 'sku' => 'BC-P-bbbb2', 'status' => array( 'draft', 'publish' ), 'limit' => -1 ) );

$assert( '99' === $nexo->get_regular_price() && 'instock' === $nexo->get_stock_status(), 'NEXO no debe cambiar.' );
$assert( 'publish' === $gone->get_status() && '20' === $gone->get_regular_price(), 'Lo retirado de BizneCubano solo se informa.' );
$assert( 'outofstock' === $existing->get_stock_status(), 'El stock existente no se toca.' );
$assert( ! wc_get_product_id_by_sku( 'BC-P-cccc3' ), 'Un producto sin precio no se crea.' );

if ( 'dry-run' === $phase ) {
	$assert( '10' === $existing->get_regular_price() && '' === $existing->get_sale_price(), 'El dry-run no debe cambiar precios.' );
	$assert( 0 === count( $drafts ), 'El dry-run no debe crear productos.' );
} else {
	$assert( 10.0 === (float) $existing->get_regular_price() && 8.0 === (float) $existing->get_sale_price(), 'El precio de oferta debe sincronizarse.' );
	$assert( 1 === count( $drafts ), 'Debe existir exactamente un borrador nuevo (idempotencia).' );
	$draft = $drafts[0];
	$assert( 'draft' === $draft->get_status(), 'Los productos nuevos se crean como borrador.' );
	$assert( 15.0 === (float) $draft->get_regular_price() && '' === $draft->get_sale_price(), 'Precio del nuevo producto.' );
	$assert( false === stripos( $draft->get_description(), 'precio' ), 'La descripción no duplica el precio.' );
	$assert( false === strpos( $draft->get_description(), '<b>' ), 'La descripción se escapa.' );
	$names = wp_list_pluck( array_map( 'get_term', $draft->get_category_ids() ), 'name' );
	sort( $names );
	$assert( array( 'Otros', 'Sala y muebles' ) === $names, 'Mapeo de categorías: ' . implode( ',', $names ) );
}
echo "OK: sincronización de catálogo verificada ({$phase}).\n";
