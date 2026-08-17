<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['product_id'] ) || empty( $fixture['clerk_id'] ) || empty( $fixture['gestora_id'] ) ) {
	throw new RuntimeException( 'Falta el fixture base de integración.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL 5B: ' . $message ); }
};

$make_order = static function ( string $fulfillment ) use ( $fixture ): WC_Order {
	$order = wc_create_order();
	$product = wc_get_product( absint( $fixture['product_id'] ) );
	if ( ! $product ) { throw new RuntimeException( 'Producto sintético no encontrado.' ); }
	$order->add_product( $product, 1 );
	$order->set_status( 'processing' );
	$order->set_address( array( 'first_name'=>'Cliente', 'last_name'=>'Recogida', 'phone'=>'+5355550199', 'country'=>'CU' ), 'billing' );
	$order->update_meta_data( '_cvd_fulfillment_type', $fulfillment );
	$order->update_meta_data( '_cvd_operation_status', 'ready' );
	$order->update_meta_data( '_cvd_delivery_status', 'unassigned' );
	$order->update_meta_data( '_cvd_owner_user_id', absint( $fixture['gestora_id'] ) );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	$order->update_meta_data( 'gestora_nombre', 'Gestora sintética' );
	$order->update_meta_data( '_cvd_commission_status', 'pending' );
	$order->calculate_totals();
	$order->save();
	return $order;
};

wp_set_current_user( absint( $fixture['clerk_id'] ) );
$pickup = $make_order( 'pickup' );

$missing_handover = CVD_Order_Transition_Service::complete_pickup( $pickup->get_id(), array(
	'actor_user_id' => absint( $fixture['clerk_id'] ),
	'idempotency_key' => '5b-missing-handover-' . $pickup->get_id(),
	'collection_method' => 'cash_usd',
	'money_confirmed' => true,
	'handover_confirmed' => false,
) );
$assert( empty( $missing_handover['success'] ) && CVD_Order_Transition_Service::PRECONDITION_FAILED === $missing_handover['error_code'], 'permite cerrar sin confirmar entrega física' );
$pickup = wc_get_order( $pickup->get_id() );
$assert( 'ready' === $pickup->get_meta( '_cvd_operation_status', true ), 'un intento inválido cambió operación' );
$assert( 'processing' === $pickup->get_status(), 'un intento inválido completó WooCommerce' );

$key = '5b-complete-' . $pickup->get_id();
$result = CVD_Order_Transition_Service::complete_pickup( $pickup->get_id(), array(
	'actor_user_id' => absint( $fixture['clerk_id'] ),
	'idempotency_key' => $key,
	'collection_method' => 'cash_usd',
	'collected_usd' => '25.00',
	'collected_cup' => '0',
	'collection_note' => 'Cobro sintético de integración',
	'money_confirmed' => true,
	'handover_confirmed' => true,
) );
$assert( ! empty( $result['success'] ), 'la recogida válida no pudo cerrarse' );
$assert( 'ready' === $result['previous_state'] && 'delivered' === $result['new_state'], 'resultado de transición incorrecto' );

$pickup = wc_get_order( $pickup->get_id() );
$assert( 'completed' === $pickup->get_status(), 'WooCommerce no quedó completed' );
$assert( 'delivered' === $pickup->get_meta( '_cvd_operation_status', true ), 'operación no quedó delivered' );
$assert( 'unassigned' === $pickup->get_meta( '_cvd_delivery_status', true ), 'la recogida inventó una etapa de mensajería' );
$assert( 'verified' === $pickup->get_meta( '_cvd_cash_status', true ), 'el cobro no quedó verificado' );
$assert( 'yes' === $pickup->get_meta( '_cvd_pickup_handover_confirmed', true ), 'falta evidencia de entrega física' );
$assert( absint( $pickup->get_meta( '_cvd_pickup_handed_over_by', true ) ) === absint( $fixture['clerk_id'] ), 'actor de entrega incorrecto' );
$assert( 'cash_usd' === $pickup->get_meta( '_cvd_collection_method', true ), 'método de cobro no persistido' );
$assert( 'approved' === $pickup->get_meta( '_cvd_commission_status', true ), 'la comisión no quedó aprobada' );
$assert( ! $pickup->get_meta( '_cvd_payout_id', true ), 'la recogida creó un payout prematuramente' );
$history_before = $pickup->get_meta( '_cvd_operation_history', true );
$commission_history_before = $pickup->get_meta( '_cvd_commission_history', true );

$replay = CVD_Order_Transition_Service::complete_pickup( $pickup->get_id(), array(
	'actor_user_id' => absint( $fixture['clerk_id'] ),
	'idempotency_key' => $key,
	'collection_method' => 'cash_usd',
	'collected_usd' => '25.00',
	'money_confirmed' => true,
	'handover_confirmed' => true,
) );
$assert( ! empty( $replay['success'] ) && ! empty( $replay['idempotent_replay'] ), 'el replay no fue idempotente' );
$pickup = wc_get_order( $pickup->get_id() );
$assert( count( (array) $pickup->get_meta( '_cvd_operation_history', true ) ) === count( (array) $history_before ), 'el replay duplicó historial operativo' );
$assert( count( (array) $pickup->get_meta( '_cvd_commission_history', true ) ) === count( (array) $commission_history_before ), 'el replay duplicó historial de comisión' );

$delivery = $make_order( 'delivery' );
$wrong_mode = CVD_Order_Transition_Service::complete_pickup( $delivery->get_id(), array(
	'actor_user_id' => absint( $fixture['clerk_id'] ),
	'idempotency_key' => '5b-delivery-rejected-' . $delivery->get_id(),
	'collection_method' => 'cash_cup',
	'money_confirmed' => true,
	'handover_confirmed' => true,
) );
$assert( empty( $wrong_mode['success'] ) && CVD_Order_Transition_Service::PRECONDITION_FAILED === $wrong_mode['error_code'], 'un pedido a domicilio pudo usar complete_pickup' );
$delivery = wc_get_order( $delivery->get_id() );
$assert( 'processing' === $delivery->get_status() && 'ready' === $delivery->get_meta( '_cvd_operation_status', true ), 'el rechazo de domicilio mutó el pedido' );

echo "OK 5B: recogida canónica validada en WordPress/WooCommerce/MariaDB.\n";