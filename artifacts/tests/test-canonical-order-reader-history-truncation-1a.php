<?php

/** Run: php artifacts/tests/test-canonical-order-reader-history-truncation-1a.php */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-canonical-order-reader.php';

$history = array();
for ( $index = 0; $index < 100; $index++ ) {
	$history[] = array( 'from' => 'new', 'to' => 'preparing' );
}

$result = CVD_Canonical_Order_Reader::interpret(
	array(
		'order_id'          => 460,
		'woocommerce'       => 'processing',
		'operation'         => 'incident',
		'delivery'          => 'to_store',
		'fulfillment'       => 'delivery',
		'cash'              => '',
		'commission'        => 'pending',
		'messenger_user_id' => 17,
		'operation_history' => $history,
		'delivery_history'  => array(),
	)
);

$codes = array_column( $result['reasons'], 'code' );
if ( 'WARNING' !== $result['consistency']
	|| 'COURIER_GOING_TO_PICKUP' !== $result['canonical_stage']
	|| 'unknown' !== $result['data_used']['operation_effective']
	|| ! in_array( 'INCIDENT_OPERATION_STAGE_UNKNOWN', $codes, true ) ) {
	fwrite( STDERR, 'FALLO: el historial operativo truncado produjo una etapa inventada.' . PHP_EOL );
	exit( 1 );
}

$delivery_history = array();
for ( $index = 0; $index < 150; $index++ ) {
	$delivery_history[] = array( 'from' => 'accepted', 'to' => 'to_store' );
}

$delivery_incident = CVD_Canonical_Order_Reader::interpret(
	array(
		'order_id'          => 461,
		'woocommerce'       => 'processing',
		'operation'         => 'with_courier',
		'delivery'          => 'incident',
		'fulfillment'       => 'delivery',
		'cash'              => '',
		'commission'        => 'pending',
		'messenger_user_id' => 17,
		'operation_history' => array(),
		'delivery_history'  => $delivery_history,
	)
);

$delivery_codes = array_column( $delivery_incident['reasons'], 'code' );
if ( 'WARNING' !== $delivery_incident['consistency']
	|| 'PICKED_UP' !== $delivery_incident['canonical_stage']
	|| 'unknown' !== $delivery_incident['data_used']['delivery_effective']
	|| ! $delivery_incident['incident']['active']
	|| ! in_array( 'delivery', $delivery_incident['incident']['sources'], true )
	|| ! in_array( 'INCIDENT_DELIVERY_STAGE_UNKNOWN', $delivery_codes, true ) ) {
	fwrite( STDERR, 'FALLO: delivery=incident con historial truncado produjo una etapa logística inventada.' . PHP_EOL );
	exit( 1 );
}

echo "OK: historiales truncados de operación y mensajería tratados como WARNING sin inventar etapas.\n";
