<?php

/**
 * Unit scenarios for the read-only canonical order interpreter.
 *
 * Run from the repository root:
 * php artifacts/tests/test-canonical-order-reader-1a.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-canonical-order-reader.php';

$base = array(
	'order_id'          => 459,
	'woocommerce'       => 'processing',
	'operation'         => 'new',
	'delivery'          => 'unassigned',
	'fulfillment'       => 'delivery',
	'cash'              => '',
	'commission'        => 'pending',
	'messenger_user_id' => 0,
	'operation_history' => array(),
	'delivery_history'  => array(),
);

$cases = array(
	'pedido nuevo' => array(
		'facts'    => array(),
		'expected' => array( 'canonical_stage' => 'CREATED', 'consistency' => 'OK' ),
	),
	'pedido histórico sin estado de mensajería' => array(
		'facts'    => array( 'delivery' => '' ),
		'expected' => array( 'canonical_stage' => 'CREATED', 'consistency' => 'WARNING', 'reason' => 'DELIVERY_STATE_MISSING' ),
	),
	'mensajero va a recoger' => array(
		'facts'    => array( 'operation' => 'ready', 'delivery' => 'to_store', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'COURIER_GOING_TO_PICKUP', 'consistency' => 'OK' ),
	),
	'recogida en tienda lista' => array(
		'facts'    => array( 'operation' => 'ready', 'fulfillment' => 'pickup' ),
		'expected' => array( 'canonical_stage' => 'READY_FOR_PICKUP', 'consistency' => 'OK' ),
	),
	'cancelado coherente' => array(
		'facts'    => array( 'woocommerce' => 'cancelled', 'operation' => 'cancelled', 'delivery' => 'cancelled', 'commission' => 'cancelled' ),
		'expected' => array( 'canonical_stage' => 'CANCELLED', 'consistency' => 'OK' ),
	),
	'incidencia conserva etapa normal' => array(
		'facts'    => array(
			'operation'        => 'with_courier',
			'delivery'         => 'incident',
			'messenger_user_id'=> 17,
			'delivery_history' => array( array( 'from' => 'handed_over', 'to' => 'incident' ) ),
		),
		'expected' => array( 'canonical_stage' => 'ON_THE_WAY_TO_CUSTOMER', 'consistency' => 'OK', 'incident' => true ),
	),
	'entrega física al mensajero' => array(
		'facts'    => array( 'operation' => 'with_courier', 'delivery' => 'picked_up', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'PICKED_UP', 'consistency' => 'OK' ),
	),
	'handed over significa en camino al cliente' => array(
		'facts'    => array( 'operation' => 'with_courier', 'delivery' => 'handed_over', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'ON_THE_WAY_TO_CUSTOMER', 'consistency' => 'OK' ),
	),
	'dinero devuelto todavía no cerrado' => array(
		'facts'    => array( 'operation' => 'delivered', 'delivery' => 'cash_returned', 'cash' => 'returned', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'PAYMENT_RECONCILED', 'consistency' => 'OK' ),
	),
	'cierre completo' => array(
		'facts'    => array( 'woocommerce' => 'completed', 'operation' => 'delivered', 'delivery' => 'closed', 'cash' => 'verified', 'commission' => 'approved', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'COMPLETED', 'consistency' => 'OK' ),
	),
	'entrega fallida después de salir' => array(
		'facts'    => array( 'operation' => 'with_courier', 'delivery' => 'failed', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'DELIVERY_FAILED', 'consistency' => 'OK' ),
	),
	'contradicción entregado contra va a tienda' => array(
		'facts'    => array( 'operation' => 'delivered', 'delivery' => 'to_store', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'CONFLICT', 'consistency' => 'CONFLICT', 'reason' => 'OPERATION_DELIVERY_IMPOSSIBLE' ),
	),
	'woocommerce cerrado antes de liquidación' => array(
		'facts'    => array( 'woocommerce' => 'completed', 'operation' => 'delivered', 'delivery' => 'cash_returned', 'cash' => 'returned', 'messenger_user_id' => 17 ),
		'expected' => array( 'canonical_stage' => 'CONFLICT', 'consistency' => 'CONFLICT', 'reason' => 'WC_COMPLETED_BEFORE_OPERATION_CLOSE' ),
	),
	'cancelación personalizada con Woo activo' => array(
		'facts'    => array( 'operation' => 'cancelled', 'delivery' => 'cancelled', 'commission' => 'cancelled' ),
		'expected' => array( 'canonical_stage' => 'CONFLICT', 'consistency' => 'CONFLICT', 'reason' => 'CUSTOM_CANCELLED_WC_ACTIVE' ),
	),
);

$failures = array();
foreach ( $cases as $name => $case ) {
	$result = CVD_Canonical_Order_Reader::interpret( array_replace( $base, $case['facts'] ) );
	$expected = $case['expected'];
	foreach ( array( 'canonical_stage', 'consistency' ) as $field ) {
		if ( $result[ $field ] !== $expected[ $field ] ) {
			$failures[] = sprintf( '%s: %s esperado=%s real=%s', $name, $field, $expected[ $field ], $result[ $field ] );
		}
	}
	if ( isset( $expected['incident'] ) && (bool) $result['incident']['active'] !== $expected['incident'] ) {
		$failures[] = sprintf( '%s: incidencia activa no coincide', $name );
	}
	if ( isset( $expected['reason'] ) ) {
		$codes = array_column( $result['reasons'], 'code' );
		if ( ! in_array( $expected['reason'], $codes, true ) ) {
			$failures[] = sprintf( '%s: falta razón %s', $name, $expected['reason'] );
		}
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo sprintf( "OK: %d escenarios canónicos.\n", count( $cases ) );
