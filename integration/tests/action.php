<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$fixture = get_option( 'cvt_integration_fixture' );
$kind = sanitize_key( $args[0] ?? '' ); $status = sanitize_key( $args[1] ?? '' );
$order_id = absint( $fixture['order_id'] ?? 0 );

if ( 'operation' === $kind ) {
	wp_set_current_user( absint( $fixture['clerk_id'] ) );
	$request = new WP_REST_Request( 'POST' ); $request->set_param( 'status', $status ); $request->set_url_params( array( 'id' => $order_id ) );
	$result = CVD_Sales::change_status( $request );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	echo "operation={$status}\n"; return;
}

if ( 'assign' === $kind ) {
	wp_set_current_user( absint( $fixture['admin_id'] ) );
	$_POST['cvd_assign_messenger_nonce'] = wp_create_nonce( 'cvd_assign_messenger_' . $order_id );
	$_POST['cvd_messenger_user_id'] = absint( $fixture['messenger_id'] );
	CVD_Delivery::save_assignment( $order_id ); echo "delivery=assigned\n"; return;
}

if ( 'handover' === $kind ) {
	wp_set_current_user( absint( $fixture['clerk_id'] ) );
	$order = wc_get_order( $order_id );
	if ( ! CVD_Delivery::handover_by_staff( $order, absint( $fixture['clerk_id'] ), 'integration_test' ) ) { throw new RuntimeException( 'No se pudo confirmar la custodia.' ); }
	echo "delivery=picked_up\n"; return;
}

if ( 'delivery' === $kind ) {
	$user_key = in_array( $status, array( 'cash_returned', 'closed' ), true ) ? 'admin_id' : 'messenger_id';
	wp_set_current_user( absint( $fixture[ $user_key ] ) );
	$_REQUEST = array( 'order_id' => $order_id, 'status' => $status, '_wpnonce' => wp_create_nonce( 'cvd_delivery_' . $order_id . '_' . $status ) );
	$_GET = $_REQUEST;
	if ( 'incident' === $status ) { $_POST['note'] = 'Incidencia sintética sin datos privados'; }
	CVD_Delivery::change_status();
}

throw new RuntimeException( 'Acción de integración no reconocida.' );
