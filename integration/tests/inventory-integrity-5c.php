<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['clerk_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL 5C: ' . $message ); }
};

$request_movement = static function ( array $payload ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/casa-viva/v1/inventory/movement' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $payload ) );
	$response = rest_do_request( $request );
	if ( ! $response instanceof WP_REST_Response ) {
		throw new RuntimeException( 'La API de inventario no devolvió WP_REST_Response.' );
	}
	return $response;
};

wp_set_current_user( absint( $fixture['clerk_id'] ) );
$product = new WC_Product_Simple();
$product->set_name( 'Producto integridad 5C' );
$product->set_regular_price( '12' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 10 );
$product->set_status( 'publish' );
$product_id = $product->save();
CVD_Inventory::ensure_product_code( $product_id );

$seed = $request_movement( array(
	'uuid' => '5c-seed-' . wp_generate_uuid4(),
	'productId' => $product_id,
	'type' => 'count',
	'quantity' => 10,
	'reason' => 'Conteo inicial de integración',
	'referenceType' => 'manual',
) );
$assert( 200 === $seed->get_status(), 'no pudo sembrarse el saldo auditado inicial' );
$initial = CVD_Inventory_Integrity::snapshot();
$assert( 0 === (int) $initial['discrepancyCount'], 'el saldo recién auditado aparece discrepante' );

// Simula una modificación fuera del diario: WooCommerce cambia, el diario no.
wc_update_product_stock( wc_get_product( $product_id ), 8, 'set' );
wp_cache_delete( $product_id, 'posts' );
$broken = CVD_Inventory_Integrity::snapshot();
$match = array_values( array_filter( $broken['discrepancies'], static fn( array $row ): bool => (int) $row['productId'] === $product_id ) );
$assert( 1 === count( $match ), 'no detectó la discrepancia de stock' );
$assert( 10.0 === (float) $match[0]['expectedStock'] && 8.0 === (float) $match[0]['currentStock'], 'saldo esperado/actual incorrecto' );

$entry = $request_movement( array(
	'uuid' => '5c-entry-' . wp_generate_uuid4(),
	'productId' => $product_id,
	'type' => 'entry',
	'quantity' => 1,
	'reason' => 'Entrada que debe bloquearse',
	'referenceType' => 'manual',
) );
$assert( 409 === $entry->get_status(), 'permitió una entrada sobre stock discrepante' );
$entry_data = $entry->get_data();
$assert( 'cvd_inventory_reconciliation_required' === ( $entry_data['code'] ?? '' ), 'código de bloqueo por discrepancia incorrecto' );
$assert( 8.0 === (float) wc_get_product( $product_id )->get_stock_quantity(), 'el intento bloqueado alteró stock' );

$sale = $request_movement( array(
	'uuid' => '5c-sale-' . wp_generate_uuid4(),
	'productId' => $product_id,
	'type' => 'sale',
	'quantity' => 1,
	'reason' => 'Venta manual prohibida',
	'referenceType' => 'manual',
) );
$assert( 422 === $sale->get_status(), 'permitió una venta manual fuera del pedido' );
$sale_data = $sale->get_data();
$assert( 'cvd_order_inventory_only' === ( $sale_data['code'] ?? '' ), 'código de venta manual incorrecto' );

$count = $request_movement( array(
	'uuid' => '5c-reconcile-' . wp_generate_uuid4(),
	'productId' => $product_id,
	'type' => 'count',
	'quantity' => 8,
	'reason' => 'Conteo físico reconciliador',
	'referenceType' => 'manual',
) );
$assert( 200 === $count->get_status(), 'el conteo físico no pudo reconciliar la discrepancia' );
$healthy = CVD_Inventory_Integrity::snapshot();
$remaining = array_values( array_filter( $healthy['discrepancies'], static fn( array $row ): bool => (int) $row['productId'] === $product_id ) );
$assert( 0 === count( $remaining ), 'la discrepancia no se cerró después del conteo' );
$assert( 8.0 === (float) wc_get_product( $product_id )->get_stock_quantity(), 'el conteo reconciliador cambió indebidamente el stock físico informado' );

// El filtro de reporte debe conservar ventas históricas pero enlazarlas con un parámetro propio.
$report_request = new WP_REST_Request( 'GET', '/casa-viva/v1/inventory/report' );
$report_response = rest_do_request( $report_request );
$assert( 200 === $report_response->get_status(), 'el reporte de inventario falló' );
$report_data = $report_response->get_data();
$assert( isset( $report_data['integrity']['discrepancyCount'] ), 'el reporte no incluye diagnóstico de integridad' );

echo "OK 5C: integridad y reconciliación de inventario validadas.\n";