<?php

defined( 'ABSPATH' ) || exit;

global $wpdb;
function cvt_4c_verify( bool $condition, string $message ): void { if ( ! $condition ) { fwrite( STDERR, "FAIL 4C: {$message}\n" ); exit( 1 ); } }
$fixture = get_option( 'cvt_payout_4c_fixture', array() );
$payout_id = absint( get_option( 'cvt_payout_4c_id', 0 ) );
$order_id = absint( $fixture['order_id'] ?? 0 );
$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payouts WHERE id=%d", $payout_id ), ARRAY_A );
cvt_4c_verify( (bool) $payout, 'La liquidación desapareció.' );
cvt_4c_verify( in_array( $payout['status'], array( 'paid', 'rejected' ), true ), 'La carrera no terminó en un estado terminal válido.' );
$terminal_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cvd_payout_events WHERE payout_id=%d AND to_status IN ('paid','rejected')", $payout_id ) );
cvt_4c_verify( 1 === $terminal_events, 'La carrera produjo más de un resultado terminal.' );
$approved_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cvd_payout_events WHERE payout_id=%d AND to_status='approved'", $payout_id ) );
cvt_4c_verify( 1 === $approved_events, 'La aprobación no quedó registrada exactamente una vez.' );
$order = wc_get_order( $order_id );
cvt_4c_verify( $order instanceof WC_Order, 'No se pudo releer el pedido liquidado.' );
if ( 'paid' === $payout['status'] ) {
	cvt_4c_verify( $payout_id === absint( $order->get_meta( '_cvd_payout_id', true ) ), 'Pago final perdió el vínculo con su liquidación.' );
	cvt_4c_verify( 'paid' === $order->get_meta( '_cvd_payout_status', true ), 'Pedido pagado no refleja payout paid.' );
	cvt_4c_verify( 'paid' === $order->get_meta( '_cvd_commission_status', true ), 'Comisión pagada no quedó cerrada como paid.' );
	cvt_4c_verify( 'REF-4C-SYNTHETIC' === $payout['reference'], 'Pago ganador perdió su referencia.' );
} else {
	cvt_4c_verify( 0 === absint( $order->get_meta( '_cvd_payout_id', true ) ), 'Rechazo no liberó la comisión.' );
	cvt_4c_verify( '' === (string) $order->get_meta( '_cvd_payout_status', true ), 'Rechazo dejó estado de payout en el pedido.' );
	cvt_4c_verify( 'approved' === $order->get_meta( '_cvd_commission_status', true ), 'Rechazo alteró indebidamente la comisión aprobada.' );
}
echo 'OK 4C race: terminal=' . $payout['status'] . PHP_EOL;
