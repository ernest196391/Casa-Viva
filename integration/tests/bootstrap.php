<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function cvt_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$admin_id = username_exists( 'cvt_admin' ) ?: wp_create_user( 'cvt_admin', 'Synthetic-Admin-Only-1!', 'admin@example.invalid' );
$admin = new WP_User( $admin_id ); $admin->set_role( 'administrator' );
$clerk_id = username_exists( 'cvt_clerk' ) ?: wp_create_user( 'cvt_clerk', 'Synthetic-Clerk-Only-1!', 'clerk@example.invalid' );
$clerk = new WP_User( $clerk_id ); $clerk->set_role( 'cvd_clerk' );
$messenger_id = username_exists( 'cvt_messenger' ) ?: wp_create_user( 'cvt_messenger', 'Synthetic-Messenger-Only-1!', 'messenger@example.invalid' );
$messenger = new WP_User( $messenger_id ); $messenger->set_role( 'cvd_messenger' );
update_user_meta( $messenger_id, '_cvd_account_status', 'approved' );
update_user_meta( $messenger_id, '_cvd_program_type', 'mensajero' );
update_user_meta( $messenger_id, '_cvd_messenger_available', 'yes' );
update_user_meta( $messenger_id, '_cvd_zone', 'Zona Sintética' );
$gestora_id = username_exists( 'cvt_gestora' ) ?: wp_create_user( 'cvt_gestora', 'Synthetic-Gestora-Only-1!', 'gestora@example.invalid' );
$gestora = new WP_User( $gestora_id ); $gestora->set_role( 'cvd_gestora' );

$product_id = wc_get_product_id_by_sku( 'CVT-SYNTHETIC-1' );
if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Producto sintético de integración' );
	$product->set_sku( 'CVT-SYNTHETIC-1' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
}

wp_set_current_user( 0 );
$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_address( array(
	'first_name' => 'Cliente', 'last_name' => 'Sintético',
	'email' => 'customer@example.invalid', 'phone' => '0000000000',
	'address_1' => 'Dirección sintética no real', 'city' => 'Zona Sintética', 'country' => 'CU',
), 'billing' );
$order->update_meta_data( '_cvd_fulfillment_type', 'delivery' );
$order->update_meta_data( '_cvd_owner_user_id', $gestora_id );
$order->update_meta_data( '_cvd_owner_type', 'gestora' );
$order->calculate_totals(); $order->save();
do_action( 'woocommerce_checkout_order_created', $order );
$order->set_status( 'processing' ); $order->save();

update_option( 'cvt_integration_fixture', array(
	'order_id' => $order->get_id(), 'product_id' => $product_id,
	'admin_id' => $admin_id, 'clerk_id' => $clerk_id,
	'messenger_id' => $messenger_id, 'gestora_id' => $gestora_id,
) );

echo wp_json_encode( get_option( 'cvt_integration_fixture' ) ) . PHP_EOL;
