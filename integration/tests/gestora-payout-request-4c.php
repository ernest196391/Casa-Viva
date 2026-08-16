<?php

defined( 'ABSPATH' ) || exit;
$fixture = get_option( 'cvt_payout_4c_fixture', array() );
wp_set_current_user( absint( $fixture['gestora_id'] ?? 0 ) );
$result = CVD_Payouts::request( absint( $fixture['gestora_id'] ?? 0 ) );
if ( is_wp_error( $result ) ) {
	echo 'EXPECTED_OR_CONFLICT:' . $result->get_error_code() . ':' . $result->get_error_message() . PHP_EOL;
	exit( 0 );
}
echo 'CREATED:' . (int) $result . PHP_EOL;
