<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['product_id'] ) || empty( $fixture['messenger_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

wp_set_password( 'Synthetic-Messenger-Only-1!', absint( $fixture['messenger_id'] ) );
update_user_meta( absint( $fixture['messenger_id'] ), '_cvd_account_status', 'approved' );
update_user_meta( absint( $fixture['messenger_id'] ), '_cvd_messenger_available', 'yes' );

$page = get_page_by_path( 'area-mensajeros' );
if ( ! $page ) {
	$page_id = wp_insert_post( array(
		'post_title'   => 'Área mensajeros',
		'post_name'    => 'area-mensajeros',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[casa_viva_portal role="mensajero"]',
	), true );
	if ( is_wp_error( $page_id ) ) { throw new RuntimeException( $page_id->get_error_message() ); }
} else {
	$page_id = $page->ID;
	wp_update_post( array( 'ID' => $page_id, 'post_content' => '[casa_viva_portal role="mensajero"]', 'post_status' => 'publish' ) );
}

$order = wc_create_order();
$order->add_product( wc_get_product( absint( $fixture['product_id'] ) ), 1 );
$order->set_status( 'processing' );
$order->set_address( array(
	'first_name' => 'Cliente',
	'last_name'  => 'Mensajería',
	'phone'      => '5355512345',
	'address_1'  => 'Calle sintética 123',
	'city'       => 'Nuevo Vedado',
	'country'    => 'CU',
), 'billing' );
$order->update_meta_data( '_cvd_fulfillment_type', 'delivery' );
$order->update_meta_data( '_cvd_operation_status', 'with_courier' );
$order->update_meta_data( '_cvd_delivery_status', 'handed_over' );
$order->update_meta_data( '_cvd_messenger_user_id', absint( $fixture['messenger_id'] ) );
$order->update_meta_data( '_cvd_shipping_fee_cup', '1200' );
$order->update_meta_data( '_cvd_shipping_courier_amount_cup', '1080' );
$order->update_meta_data( '_cvd_messenger_earning_status', 'pending' );
$order->update_meta_data( '_cvd_map_url', 'https://maps.google.com/?q=23.1136,-82.3666' );
$order->calculate_totals();
$order->save();

$result = array(
	'page_id'       => $page_id,
	'order_id'      => $order->get_id(),
	'messenger_user'=> 'cvt_messenger',
	'messenger_pass'=> 'Synthetic-Messenger-Only-1!',
);
update_option( 'cvt_messenger_center_browser_fixture', $result, false );
echo wp_json_encode( $result ) . PHP_EOL;
