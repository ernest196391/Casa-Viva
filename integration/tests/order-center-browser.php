<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['product_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

wp_set_password( 'Synthetic-Admin-Only-1!', absint( $fixture['admin_id'] ) );
wp_set_password( 'Synthetic-Clerk-Only-1!', absint( $fixture['clerk_id'] ) );

$ensure_page = static function ( string $slug, string $title, string $shortcode ): int {
	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		$page_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => $shortcode,
		), true );
		if ( is_wp_error( $page_id ) ) { throw new RuntimeException( $page_id->get_error_message() ); }
		return (int) $page_id;
	}
	wp_update_post( array( 'ID' => $page->ID, 'post_content' => $shortcode, 'post_status' => 'publish' ) );
	return (int) $page->ID;
};

$page_id = $ensure_page( 'centro-pedido', 'Centro pedido', '[casa_viva_order_center]' );
$sales_page_id = $ensure_page( 'ventas', 'Centro de ventas', '[casa_viva_sales]' );

$make_order = static function ( string $operation, string $delivery, string $fulfillment = 'delivery' ) use ( $fixture ): WC_Order {
	$order = wc_create_order();
	$order->add_product( wc_get_product( absint( $fixture['product_id'] ) ), 1 );
	$order->set_status( 'processing' );
	$order->set_address( array(
		'first_name' => 'Cliente',
		'last_name'  => 'Visual',
		'phone'      => '+5355550101',
		'address_1'  => 'Dirección sintética',
		'city'       => 'Zona sintética',
		'country'    => 'CU',
	), 'billing' );
	$order->update_meta_data( '_cvd_fulfillment_type', $fulfillment );
	$order->update_meta_data( '_cvd_operation_status', $operation );
	$order->update_meta_data( '_cvd_delivery_status', $delivery );
	$order->update_meta_data( '_cvd_location_url', 'https://maps.google.com/?q=23.1136,-82.3666' );
	$order->update_meta_data( '_cvd_owner_user_id', absint( $fixture['gestora_id'] ) );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	$order->update_meta_data( 'gestora_nombre', 'Gestora sintética' );
	$order->update_meta_data( '_cvd_commission_status', 'pending' );
	$order->calculate_totals();
	$order->save();
	return $order;
};

$new_order = $make_order( 'new', 'unassigned' );
$ready_order = $make_order( 'ready', 'offered' );
$pickup_order = $make_order( 'ready', 'unassigned', 'pickup' );
$incident_order = $make_order( 'ready', 'unassigned', 'pickup' );
$handed_order = $make_order( 'with_courier', 'handed_over' );
$handed_order->update_meta_data( '_cvd_messenger_user_id', absint( $fixture['messenger_id'] ) );
$handed_order->save();
$conflict_order = $make_order( 'new', 'picked_up' );

$browser = array(
	'page_id'        => $page_id,
	'sales_page_id'  => $sales_page_id,
	'new_id'         => $new_order->get_id(),
	'ready_id'       => $ready_order->get_id(),
	'pickup_id'      => $pickup_order->get_id(),
	'incident_id'    => $incident_order->get_id(),
	'handed_id'      => $handed_order->get_id(),
	'conflict_id'    => $conflict_order->get_id(),
	'admin_user'     => 'cvt_admin',
	'admin_password' => 'Synthetic-Admin-Only-1!',
	'clerk_user'     => 'cvt_clerk',
	'clerk_password' => 'Synthetic-Clerk-Only-1!',
);
update_option( 'cvt_order_center_browser_fixture', $browser, false );
echo wp_json_encode( $browser ) . PHP_EOL;
