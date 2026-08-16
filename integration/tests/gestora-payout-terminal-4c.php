<?php

defined( 'ABSPATH' ) || exit;
$action = sanitize_key( (string) ( $args[0] ?? '' ) );
if ( ! in_array( $action, array( 'pay', 'reject' ), true ) ) { fwrite( STDERR, "Uso: pay|reject\n" ); exit( 1 ); }
$fixture = get_option( 'cvt_payout_4c_fixture', array() );
wp_set_current_user( absint( $fixture['admin_id'] ?? 0 ) );
$payout_id = absint( get_option( 'cvt_payout_4c_id', 0 ) );
$result = CVD_Payouts::transition( $payout_id, $action, 'pay' === $action ? 'REF-4C-SYNTHETIC' : '', 0 );
if ( is_wp_error( $result ) ) {
	echo 'LOST:' . $action . ':' . $result->get_error_code() . PHP_EOL;
	exit( 0 );
}
echo 'WON:' . $action . PHP_EOL;
