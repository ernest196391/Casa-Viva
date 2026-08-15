<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$fixture = get_option( 'cvt_integration_fixture' );
$order = wc_create_order();
$order->update_meta_data( '_cvd_fulfillment_type', 'pickup' );
$order->set_status( 'processing' );
$order->save();
CVD_Sales::initialize_order( $order );
update_option( 'cvt_transition_fixture', array(
	'order_id' => $order->get_id(),
	'clerk_id' => absint( $fixture['clerk_id'] ),
	'admin_id' => absint( $fixture['admin_id'] ),
) );
echo $order->get_id() . PHP_EOL;
