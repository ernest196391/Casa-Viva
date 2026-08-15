<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
global $wpdb;
$fixture = get_option( 'cvt_transition_fixture' );
$order_id = absint( $fixture['order_id'] );
$order = wc_get_order( $order_id );
if ( ! $order || 'preparing' !== $order->get_meta( '_cvd_operation_status', true ) ) { throw new RuntimeException( 'Estado centralizado incorrecto.' ); }
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_order_events WHERE order_id=%d AND domain='operation' AND to_state='preparing'", $order_id ), ARRAY_A );
if ( 1 !== count( $rows ) ) { throw new RuntimeException( 'La transición concurrente no produjo exactamente un evento.' ); }
if ( ! in_array( (int) $rows[0]['actor_user_id'], array( absint($fixture['clerk_id']), absint($fixture['admin_id']) ), true ) ) { throw new RuntimeException( 'Actor concurrente incorrecto.' ); }
$history = $order->get_meta( '_cvd_operation_history', true );
if ( 1 !== count( is_array($history) ? $history : array() ) ) { throw new RuntimeException( 'La transición concurrente duplicó historial legacy.' ); }
echo "OK: estado, actor, historial y evento centralizado verificados directamente.\n";
