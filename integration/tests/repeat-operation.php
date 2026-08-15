<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
global $wpdb; $fixture = get_option( 'cvt_integration_fixture' ); $order_id = absint( $fixture['order_id'] );
$table = $wpdb->prefix . 'cvd_order_events';
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d AND domain='operation' AND to_state='preparing'", $order_id ) );
wp_set_current_user( absint( $fixture['clerk_id'] ) );
$request = new WP_REST_Request( 'POST' ); $request->set_param( 'status', 'preparing' ); $request->set_url_params( array( 'id' => $order_id ) );
$result = CVD_Sales::change_status( $request );
$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d AND domain='operation' AND to_state='preparing'", $order_id ) );
if ( ! is_wp_error( $result ) || 1 !== $before || $before !== $after ) { throw new RuntimeException( 'La acción repetida no fue rechazada limpiamente.' ); }
echo "OK: acción operativa repetida no duplicó evento.\n";
