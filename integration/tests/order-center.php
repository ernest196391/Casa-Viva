<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$f = get_option( 'cvt_integration_fixture' );
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$make = static function ( string $operation, string $delivery, string $fulfillment = 'delivery', string $wc = 'processing' ) use ( $f ): WC_Order {
	$o = wc_create_order(); $o->add_product( wc_get_product( absint( $f['product_id'] ) ), 1 ); $o->set_status( $wc );
	$o->set_address( array( 'first_name' => 'Centro', 'last_name' => 'Sintético', 'phone' => '0000000000', 'address_1' => 'Dirección sintética', 'city' => 'Zona sintética', 'country' => 'CU' ), 'billing' );
	$o->update_meta_data( '_cvd_fulfillment_type', $fulfillment ); $o->update_meta_data( '_cvd_operation_status', $operation ); $o->update_meta_data( '_cvd_delivery_status', $delivery );
	$o->update_meta_data( '_cvd_owner_user_id', absint( $f['gestora_id'] ) ); $o->update_meta_data( '_cvd_owner_type', 'gestora' ); $o->update_meta_data( 'gestora_nombre', 'Gestora sintética' );
	$o->update_meta_data( '_cvd_commission_status', 'pending' ); $o->calculate_totals(); $o->save(); return $o;
};

wp_set_current_user( absint( $f['clerk_id'] ) );
$flow = $make( 'new', 'unassigned' );
$p = CVD_Order_Center::project( $flow, absint( $f['clerk_id'] ) );
$assert( 'CREATED' === $p['canonical_stage'] && ! empty( $p['available_actions'] ), 'proyección new/acciones dependienta' );
$a = CVD_Order_Transition_Service::transition( $flow->get_id(), 'operation', 'preparing', array( 'actor_user_id' => absint( $f['clerk_id'] ), 'idempotency_key' => '2a-preparing' ) );
$b = CVD_Order_Transition_Service::transition( $flow->get_id(), 'operation', 'ready', array( 'actor_user_id' => absint( $f['clerk_id'] ), 'idempotency_key' => '2a-ready' ) );
$assert( ! empty( $a['success'] ) && ! empty( $b['success'] ) && 'READY_FOR_COURIER' === CVD_Order_Center::project( wc_get_order( $flow->get_id() ), absint( $f['clerk_id'] ) )['canonical_stage'], 'flujo new → preparing → ready por servicio' );

$advanced = $make( 'with_courier', 'handed_over' ); $advanced->update_meta_data( '_cvd_messenger_user_id', absint( $f['messenger_id'] ) ); $advanced->save();
$pa = CVD_Order_Center::project( $advanced, absint( $f['admin_id'] ) );
$assert( 'ON_THE_WAY_TO_CUSTOMER' === $pa['canonical_stage'] && absint( $f['messenger_id'] ) === $pa['courier']['id'], 'flujo logístico avanzado' );

foreach ( array(
	array( 'new', 'unassigned', 'delivery' ), array( 'preparing', 'unassigned', 'delivery' ), array( 'ready', 'offered', 'delivery' ),
	array( 'with_courier', 'picked_up', 'delivery' ), array( 'with_courier', 'handed_over', 'delivery' ), array( 'with_courier', 'delivered', 'delivery' ), array( 'delivered', 'cash_returned', 'delivery' ),
	array( 'delivered', 'closed', 'delivery', 'completed' ), array( 'with_courier', 'failed', 'delivery' ), array( 'with_courier', 'returned', 'delivery' ),
	array( 'cancelled', 'cancelled', 'delivery', 'cancelled' ), array( 'ready', 'unassigned', 'pickup' ),
) as $state ) { $projection = CVD_Order_Center::project( $make( $state[0], $state[1], $state[2], $state[3] ?? 'processing' ), absint( $f['admin_id'] ) ); $assert( isset( $projection['operation'], $projection['delivery'], $projection['payment'], $projection['incident'], $projection['timeline'], $projection['available_actions'] ), 'modelo incompleto' ); }

$incident = $make( 'preparing', 'unassigned' ); $incident->update_meta_data( '_cvd_operation_incident_active', 'yes' ); $incident->update_meta_data( '_cvd_operation_incident_stage', 'preparing' ); $incident->update_meta_data( '_cvd_operation_incident_note', 'Incidencia sintética' ); $incident->save();
$assert( CVD_Order_Center::project( $incident, absint( $f['admin_id'] ) )['incident']['active'], 'incidencia activa no proyectada' );
$conflict = $make( 'new', 'picked_up' ); $pc = CVD_Order_Center::project( $conflict, absint( $f['admin_id'] ) );
$assert( 'CONFLICT' === $pc['consistency']['level'] && ! empty( $pc['available_actions'][0]['blocked'] ), 'CONFLICT no bloqueó acciones' );
$clerk_view = CVD_Order_Center::project( $advanced, absint( $f['clerk_id'] ) );
$assert( ! isset( $clerk_view['gestora']['id'] ) && empty( $clerk_view['consistency']['reasons'] ), 'privacidad dependienta' );
echo "OK: Centro Único 2A verificado en WordPress/WooCommerce/MariaDB.\n";
