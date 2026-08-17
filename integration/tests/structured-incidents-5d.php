<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['product_id'] ) || empty( $fixture['clerk_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL 5D: ' . $message ); }
};

$make_order = static function ( string $fulfillment, string $operation, string $delivery ): WC_Order use ( $fixture ) {
	$order = wc_create_order();
	$product = wc_get_product( absint( $fixture['product_id'] ) );
	if ( ! $product ) { throw new RuntimeException( 'Producto sintético no encontrado.' ); }
	$order->add_product( $product, 1 );
	$order->set_status( 'processing' );
	$order->set_address( array( 'first_name'=>'Cliente', 'last_name'=>'Incidencia', 'phone'=>'+5355550177', 'country'=>'CU' ), 'billing' );
	$order->update_meta_data( '_cvd_fulfillment_type', $fulfillment );
	$order->update_meta_data( '_cvd_operation_status', $operation );
	$order->update_meta_data( '_cvd_delivery_status', $delivery );
	$order->calculate_totals();
	$order->save();
	return $order;
};

$call = static function ( WC_Order $order, array $payload, string $key ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/casa-viva/v1/structured-incidents/' . $order->get_id() );
	$request->set_url_params( array( 'id' => $order->get_id() ) );
	foreach ( $payload as $name => $value ) { $request->set_param( $name, $value ); }
	$request->set_header( 'X-CVD-Idempotency-Key', $key );
	$response = CVD_Structured_Incidents::act( $request );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( 'FAIL 5D REST: ' . $response->get_error_code() . ' · ' . $response->get_error_message() ); }
	return $response;
};

wp_set_current_user( absint( $fixture['clerk_id'] ) );

$pickup = $make_order( 'pickup', 'ready', 'unassigned' );
$open = $call( $pickup, array( 'action'=>'open', 'reason'=>'customer_no_show', 'note'=>'Cliente no acudió a la hora acordada.' ), '5d-open-pickup-' . $pickup->get_id() );
$data = $open->get_data();
$assert( ! empty( $data['transition']['success'] ), 'no abrió la incidencia de cliente que no recoge' );
$pickup = wc_get_order( $pickup->get_id() );
$assert( 'ready' === $pickup->get_meta( '_cvd_operation_status', true ), 'la incidencia cambió la etapa operativa ready' );
$assert( 'yes' === $pickup->get_meta( '_cvd_operation_incident_active', true ), 'la incidencia operativa no quedó activa' );
$assert( 'customer_no_show' === $pickup->get_meta( '_cvd_operation_incident_reason', true ), 'el motivo estructurado no se persistió' );
$history = (array) $pickup->get_meta( '_cvd_structured_incident_history', true );
$assert( 1 === count( $history ), 'la apertura no generó exactamente un registro estructurado' );
$assert( ! empty( $history[0]['event_id'] ) && $history[0]['event_id'] === $data['transition']['event_id'], 'el registro estructurado no enlaza el evento canónico' );

$replay = $call( $pickup, array( 'action'=>'open', 'reason'=>'customer_no_show', 'note'=>'Cliente no acudió a la hora acordada.' ), '5d-open-pickup-' . $pickup->get_id() );
$replay_data = $replay->get_data();
$assert( ! empty( $replay_data['transition']['idempotent_replay'] ), 'la apertura repetida no fue idempotente' );
$pickup = wc_get_order( $pickup->get_id() );
$assert( 1 === count( (array) $pickup->get_meta( '_cvd_structured_incident_history', true ) ), 'el replay duplicó el historial estructurado' );

$resolve = $call( $pickup, array( 'action'=>'resolve', 'note'=>'Cliente confirmó nueva recogida.' ), '5d-resolve-pickup-' . $pickup->get_id() );
$resolve_data = $resolve->get_data();
$assert( ! empty( $resolve_data['transition']['success'] ), 'no resolvió la incidencia operativa' );
$pickup = wc_get_order( $pickup->get_id() );
$assert( 'ready' === $pickup->get_meta( '_cvd_operation_status', true ), 'resolver la incidencia no preservó ready' );
$assert( 'no' === $pickup->get_meta( '_cvd_operation_incident_active', true ), 'la incidencia siguió activa tras resolver' );
$history = (array) $pickup->get_meta( '_cvd_structured_incident_history', true );
$assert( 2 === count( $history ) && 'resolve' === $history[1]['action'], 'la resolución no quedó auditada' );

$delivery = $make_order( 'delivery', 'ready', 'accepted' );
$delivery->update_meta_data( '_cvd_messenger_user_id', absint( $fixture['messenger_id'] ?? 0 ) );
$delivery->save();
$open_delivery = $call( $delivery, array( 'action'=>'open', 'reason'=>'messenger_no_show', 'note'=>'El mensajero aceptó pero no llegó a recoger.' ), '5d-open-messenger-' . $delivery->get_id() );
$delivery_data = $open_delivery->get_data();
$assert( ! empty( $delivery_data['transition']['success'] ), 'no abrió la incidencia de mensajero que no recoge' );
$delivery = wc_get_order( $delivery->get_id() );
$assert( 'accepted' === $delivery->get_meta( '_cvd_delivery_status', true ), 'la incidencia cambió la etapa accepted' );
$assert( 'messenger_no_show' === $delivery->get_meta( '_cvd_delivery_incident_reason', true ), 'no persistió el motivo logístico' );

$invalid = new WP_REST_Request( 'POST', '/casa-viva/v1/structured-incidents/' . $delivery->get_id() );
$invalid->set_url_params( array( 'id' => $delivery->get_id() ) );
$invalid->set_param( 'action', 'open' );
$invalid->set_param( 'reason', 'customer_no_show' );
$invalid->set_param( 'note', 'Motivo incompatible.' );
$invalid_response = CVD_Structured_Incidents::act( $invalid );
$assert( is_wp_error( $invalid_response ) && 'cvd_incident_reason_invalid' === $invalid_response->get_error_code(), 'aceptó un motivo incompatible con el flujo' );

echo "OK 5D: incidencias operativas estructuradas validadas en WordPress/WooCommerce/MariaDB.\n";
