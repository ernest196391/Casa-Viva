<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
global $wpdb; $table = $wpdb->prefix . 'cvd_order_events';
$fixture = get_option( 'cvt_integration_fixture' );
$order = wc_create_order(); $order->update_meta_data( '_cvd_fulfillment_type', 'pickup' ); $order->save();
CVD_Sales::initialize_order( $order );
$wpdb->query( "DROP TABLE {$table}" );
wp_set_current_user( absint( $fixture['clerk_id'] ) );
$request = new WP_REST_Request( 'POST' ); $request->set_param( 'status', 'preparing' ); $request->set_url_params( array( 'id' => $order->get_id() ) );
$result = CVD_Sales::change_status( $request );
$order = wc_get_order( $order->get_id() );
if ( ! is_wp_error( $result ) || 'new' !== $order->get_meta( '_cvd_operation_status', true ) ) { throw new RuntimeException( 'El fallo del event store dejó una transición parcial.' ); }
CVD_Plugin::activate();
if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { throw new RuntimeException( 'No se restauró la tabla.' ); }
echo "OK: fallo de tabla revirtió transición y tabla restaurada.\n";
