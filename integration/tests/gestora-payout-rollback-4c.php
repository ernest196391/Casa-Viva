<?php

defined( 'ABSPATH' ) || exit;

global $wpdb;
function cvt_4c_rollback_assert( bool $condition, string $message ): void { if ( ! $condition ) { fwrite( STDERR, "FAIL 4C rollback: {$message}\n" ); exit( 1 ); } }
$fixture = get_option( 'cvt_payout_4c_fixture', array() );
wp_set_current_user( absint( $fixture['admin_id'] ?? 0 ) );
$owner_id = absint( $fixture['gestora_id'] ?? 0 );

$order = wc_create_order();
cvt_4c_rollback_assert( $order instanceof WC_Order, 'No se pudo crear pedido de rollback.' );
$order->set_currency( 'USD' );
$order->update_meta_data( '_cvd_owner_user_id', $owner_id );
$order->update_meta_data( '_cvd_owner_type', 'gestora' );
$order->update_meta_data( '_cvd_commission_status', 'approved' );
$order->update_meta_data( '_cvd_commission_amount', '11.0000' );
$order->save();

$now = current_time( 'mysql', true );
$inserted = $wpdb->insert( $wpdb->prefix . 'cvd_payouts', array(
	'payout_uuid' => wp_generate_uuid4(), 'owner_user_id' => $owner_id, 'amount' => '11.0000',
	'currency' => 'USD', 'status' => 'requested', 'method' => 'transferencia',
	'account_value' => 'synthetic-invalid-for-test', 'requested_at' => $now,
	'created_by' => absint( $fixture['admin_id'] ?? 0 ), 'created_at' => $now, 'updated_at' => $now,
) );
cvt_4c_rollback_assert( 1 === $inserted, 'No se pudo crear payout sintético inconsistente.' );
$payout_id = (int) $wpdb->insert_id;
$inserted_item = $wpdb->insert( $wpdb->prefix . 'cvd_payout_items', array(
	'payout_id' => $payout_id, 'order_id' => $order->get_id(), 'amount' => '11.0000',
	'base_commission' => '11.0000', 'markup' => '0.0000', 'currency' => 'USD', 'created_at' => $now,
) );
cvt_4c_rollback_assert( 1 === $inserted_item, 'No se pudo crear item inconsistente.' );

// Intencionalmente NO se escribe _cvd_payout_id en el pedido. La transición debe
// detectar la inconsistencia después de intentar actualizar la fila y revertir todo.
$result = CVD_Payouts::transition( $payout_id, 'approve' );
cvt_4c_rollback_assert( is_wp_error( $result ), 'Una liquidación inconsistente fue aprobada.' );
$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cvd_payouts WHERE id=%d", $payout_id ) );
cvt_4c_rollback_assert( 'requested' === $status, 'ROLLBACK no restauró el estado requested.' );
$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cvd_payout_events WHERE payout_id=%d", $payout_id ) );
cvt_4c_rollback_assert( 0 === $events, 'ROLLBACK dejó un evento fantasma.' );
$order = wc_get_order( $order->get_id() );
cvt_4c_rollback_assert( 'approved' === $order->get_meta( '_cvd_commission_status', true ), 'ROLLBACK alteró la comisión original.' );
echo "OK 4C rollback: ningún estado parcial persistió.\n";
