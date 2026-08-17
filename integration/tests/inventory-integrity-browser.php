<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['clerk_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

wp_set_password( 'Synthetic-Clerk-Only-1!', absint( $fixture['clerk_id'] ) );
wp_set_current_user( absint( $fixture['clerk_id'] ) );

$page = get_page_by_path( 'inventario' );
if ( ! $page ) {
	$page_id = wp_insert_post( array(
		'post_title' => 'Inventario Casa Viva',
		'post_name' => 'inventario',
		'post_status' => 'publish',
		'post_type' => 'page',
		'post_content' => '[casa_viva_inventory]',
	), true );
	if ( is_wp_error( $page_id ) ) { throw new RuntimeException( $page_id->get_error_message() ); }
} else {
	$page_id = (int) $page->ID;
	wp_update_post( array( 'ID' => $page_id, 'post_content' => '[casa_viva_inventory]', 'post_status' => 'publish' ) );
}

$product = new WC_Product_Simple();
$product->set_name( 'Producto discrepancia visual 5C' );
$product->set_regular_price( '15' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 5 );
$product->set_status( 'publish' );
$product_id = $product->save();
CVD_Inventory::ensure_product_code( $product_id );
$code = (string) get_post_meta( $product_id, '_cvd_inventory_code', true );

$request = new WP_REST_Request( 'POST', '/casa-viva/v1/inventory/movement' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array(
	'uuid' => '5c-browser-seed-' . wp_generate_uuid4(),
	'productId' => $product_id,
	'type' => 'count',
	'quantity' => 5,
	'reason' => 'Conteo inicial visual',
	'referenceType' => 'manual',
) ) );
$response = rest_do_request( $request );
if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'No pudo sembrarse el conteo visual 5C.' ); }

// Desajuste intencional para que la pantalla de inventario lo detecte.
wc_update_product_stock( wc_get_product( $product_id ), 3, 'set' );

echo wp_json_encode( array(
	'page_id' => (int) $page_id,
	'product_id' => $product_id,
	'product_code' => $code,
) ) . PHP_EOL;