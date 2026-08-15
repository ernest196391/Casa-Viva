<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$fixture = get_option( 'cvt_transition_fixture' );
$actor_key = sanitize_key( $args[0] ?? 'clerk_id' );
$actor_id = absint( $fixture[ $actor_key ] ?? 0 );
wp_set_current_user( $actor_id );
$result = CVD_Order_Transition_Service::transition(
	absint( $fixture['order_id'] ),
	'operation',
	'preparing',
	array( 'actor_user_id'=>$actor_id, 'idempotency_key'=>'integration-concurrent-transition', 'source'=>'integration_concurrent' )
);
if ( ! $result['success'] ) { throw new RuntimeException( 'Transición concurrente falló: ' . $result['error_code'] ); }
echo wp_json_encode( $result ) . PHP_EOL;
