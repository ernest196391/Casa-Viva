<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function cvt_field_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$fixture = get_option( 'cvt_integration_fixture' ); $gestora_id = absint( $fixture['gestora_id'] ?? 0 ); $messenger_id = absint( $fixture['messenger_id'] ?? 0 ); $product_id = absint( $fixture['product_id'] ?? 0 );
update_user_meta( $gestora_id, '_cvd_account_status', 'approved' ); update_user_meta( $gestora_id, '_cvd_program_type', 'gestora' ); update_user_meta( $gestora_id, '_cvd_referral_code', 'CVTFIELD' );
update_option( 'cvd_shipping_rates', array( array( 'municipality'=>'Municipio Sintético', 'zone'=>'Zona Sintética', 'fee'=>1234, 'active'=>true ) ) );
wp_set_current_user( $gestora_id );
$draft = array(
	'orderCode'=>'CVT-FIELD-001', 'managerCode'=>'CVTFIELD', 'customer'=>'Cliente Prueba',
	'phones'=>array('50000001','50000002'), 'address'=>'Dirección sintética 1', 'betweenStreets'=>'Calle A y Calle B',
	'reference'=>'Referencia sintética', 'municipality'=>'Municipio Sintético', 'zone'=>'Zona Sintética',
	'scheduledDate'=>'2026-08-23', 'scheduledTime'=>'tarde', 'notes'=>array('Nota sintética'),
	'changeRequired'=>array(array('amount'=>20,'currency'=>'USD')),
);
$request = new WP_REST_Request( 'POST' ); $request->set_header( 'Idempotency-Key', 'field-ready-voucher-confirmation-001' ); $request->set_param( 'draft', $draft ); $request->set_param( 'lines', array(array('productId'=>$product_id,'quantity'=>2)) );
$first = CVD_Voucher_Intake::create_order( $request ); cvt_field_assert( ! is_wp_error( $first ), 'Falló la creación canónica desde vale.' ); $first_data = $first->get_data();
$second = CVD_Voucher_Intake::create_order( $request ); cvt_field_assert( ! is_wp_error( $second ), 'Falló el replay idempotente.' ); $second_data = $second->get_data();
cvt_field_assert( $first_data['orderId'] === $second_data['orderId'] && true === $second_data['replayed'], 'El doble submit creó dos pedidos.' );
$order = wc_get_order( $first_data['orderId'] ); cvt_field_assert( $order instanceof WC_Order && 'processing' === $order->get_status(), 'El pedido no quedó operativo en WooCommerce.' );
cvt_field_assert( $gestora_id === absint( $order->get_meta( '_cvd_owner_user_id', true ) ), 'Ownership de gestora incorrecto.' );
cvt_field_assert( 1234 === CVD_Shipping_Rates::order_fee( $order ) && 'zone' === $order->get_meta( '_cvd_shipping_rate_status', true ), 'No se usó la tarifa oficial.' );
cvt_field_assert( '50000002' === $order->get_meta( '_cvd_alternate_phone', true ), 'No se conservó teléfono alternativo.' );
cvt_field_assert( 'afternoon' === $order->get_meta( '_cvd_delivery_window', true ), 'No se estructuró la ventana horaria.' );
$change = $order->get_meta( '_cvd_change_required', true ); cvt_field_assert( is_array( $change ) && 20.0 === (float) ( $change[0]['amount'] ?? 0 ), 'No se conservó el vuelto.' );

$contact_order = wc_create_order(); $contact_order->add_product( wc_get_product( $product_id ), 1 ); $contact_order->update_meta_data( '_cvd_messenger_user_id', $messenger_id ); $contact_order->update_meta_data( '_cvd_delivery_status', 'accepted' ); $contact_order->save();
wp_set_current_user( $messenger_id );
foreach ( array( 'confirmed', 'no_answer', 'reschedule_requested', 'location_received' ) as $index => $outcome ) {
	$contact = new WP_REST_Request( 'POST' ); $contact->set_param( 'id', $contact_order->get_id() ); $contact->set_param( 'outcome', $outcome ); $contact->set_param( 'channel', 'phone' ); $contact->set_header( 'X-CVD-Idempotency-Key', 'field-contact-' . $index . '-00000001' );
	cvt_field_assert( CVD_Messenger_Contacts::can_record( $contact ), 'Privacidad impidió al mensajero asignado registrar contacto.' ); $response = CVD_Messenger_Contacts::record( $contact ); cvt_field_assert( ! is_wp_error( $response ), 'Falló evento de contacto: ' . $outcome );
}
$events = array_filter( CVD_Order_Events::repository()->for_order( $contact_order->get_id() ), static fn( array $event ): bool => 'contact' === $event['domain'] );
cvt_field_assert( 4 === count( $events ), 'No quedaron los cuatro resultados de contacto auditables.' );
wp_set_current_user( $gestora_id ); $denied = new WP_REST_Request( 'POST' ); $denied->set_param( 'id', $contact_order->get_id() ); cvt_field_assert( ! CVD_Messenger_Contacts::can_record( $denied ), 'Una gestora pudo escribir contacto del mensajero.' );
echo wp_json_encode( array( 'voucher_order_id'=>$order->get_id(), 'contact_events'=>count($events), 'shipping_fee_cup'=>CVD_Shipping_Rates::order_fee($order) ) ) . PHP_EOL;
