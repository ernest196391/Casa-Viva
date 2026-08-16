<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$user_id = username_exists( 'cvt_customer' );
if ( ! $user_id ) {
	$user_id = wp_create_user( 'cvt_customer', 'Synthetic-Customer-Only-1!', 'customer-orders@example.invalid' );
}
$user = new WP_User( $user_id );
$user->set_role( 'customer' );
wp_set_password( 'Synthetic-Customer-Only-1!', $user_id );

$product_id = wc_get_product_id_by_sku( 'CVT-SYNTHETIC-1' );
if ( ! $product_id ) { throw new RuntimeException( 'Producto sintético ausente.' ); }

$make_order = static function ( string $kind ) use ( $user_id, $product_id ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_address( array(
		'first_name' => 'Cliente', 'last_name' => 'Prueba', 'email' => 'customer-orders@example.invalid',
		'phone' => '0000000000', 'address_1' => 'Dirección sintética', 'city' => 'Zona Sintética', 'country' => 'CU',
	), 'billing' );
	$order->update_meta_data( '_cvd_fulfillment_type', 'delivery' );
	if ( 'active' === $kind ) {
		$order->update_meta_data( '_cvd_operation_status', 'preparing' );
		$order->update_meta_data( '_cvd_delivery_status', 'unassigned' );
		$order->set_status( 'processing' );
	} else {
		$order->update_meta_data( '_cvd_operation_status', 'delivered' );
		$order->update_meta_data( '_cvd_delivery_status', 'closed' );
		$order->update_meta_data( '_cvd_cash_status', 'verified' );
		$order->set_status( 'completed' );
	}
	$order->calculate_totals();
	$order->save();
	return $order;
};

$active = $make_order( 'active' );
$finished = $make_order( 'finished' );
$orders_url = wc_get_account_endpoint_url( 'orders' );
$orders_relative = wp_make_link_relative( $orders_url );

if ( ! $orders_relative || '/' === $orders_relative ) {
	throw new RuntimeException( 'WooCommerce no devolvió una URL utilizable para Pedidos.' );
}

echo wp_json_encode( array(
	'user' => 'cvt_customer',
	'password' => 'Synthetic-Customer-Only-1!',
	'active_id' => $active->get_id(),
	'finished_id' => $finished->get_id(),
	'orders_relative' => $orders_relative,
) ) . PHP_EOL;
