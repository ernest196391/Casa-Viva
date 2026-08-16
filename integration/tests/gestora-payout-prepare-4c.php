<?php

defined( 'ABSPATH' ) || exit;

global $wpdb;
$fixture = get_option( 'cvt_payout_4c_fixture', array() );
wp_set_current_user( absint( $fixture['admin_id'] ?? 0 ) );
$gestora_id = absint( $fixture['gestora_id'] ?? 0 );
$order_id = absint( $fixture['order_id'] ?? 0 );

$payouts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payouts WHERE owner_user_id=%d ORDER BY id ASC", $gestora_id ), ARRAY_A );
if ( 1 !== count( $payouts ) ) { fwrite( STDERR, 'FAIL 4C: concurrencia de solicitud creó ' . count( $payouts ) . " liquidaciones.\n" ); exit( 1 ); }
$payout = $payouts[0];
$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payout_items WHERE payout_id=%d", $payout['id'] ), ARRAY_A );
if ( 1 !== count( $items ) || $order_id !== (int) $items[0]['order_id'] ) { fwrite( STDERR, "FAIL 4C: la solicitud no contiene exactamente la comisión esperada.\n" ); exit( 1 ); }
$order = wc_get_order( $order_id );
if ( ! $order || (int) $payout['id'] !== absint( $order->get_meta( '_cvd_payout_id', true ) ) || 'requested' !== $order->get_meta( '_cvd_payout_status', true ) ) { fwrite( STDERR, "FAIL 4C: el pedido no quedó enlazado a la solicitud.\n" ); exit( 1 ); }

$result = CVD_Payouts::transition( (int) $payout['id'], 'approve' );
if ( is_wp_error( $result ) ) { fwrite( STDERR, 'FAIL 4C: no se pudo aprobar la liquidación: ' . $result->get_error_message() . "\n" ); exit( 1 ); }
update_option( 'cvt_payout_4c_id', (int) $payout['id'] );
echo 'APPROVED:' . (int) $payout['id'] . PHP_EOL;
