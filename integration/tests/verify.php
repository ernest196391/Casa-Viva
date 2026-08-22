<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function cvt_verify( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
$fixture = get_option( 'cvt_integration_fixture' ); $order_id = absint( $fixture['order_id'] ?? 0 );
$table = $wpdb->prefix . 'cvd_order_events';
$order = wc_get_order( $order_id );
cvt_verify( $order instanceof WC_Order, 'Pedido sintético ausente.' );
cvt_verify( 'closed' === CVD_Delivery::status( $order ), 'La entrega no quedó cerrada.' );
cvt_verify( 'verified' === $order->get_meta( '_cvd_cash_status', true ), 'El efectivo no quedó verificado.' );
cvt_verify( 'mixed' === $order->get_meta( '_cvd_collection_method', true ), 'No se conservó el medio cobrado al entregar.' );
cvt_verify( 90.0 === (float) $order->get_meta( '_cvd_collection_amount_usd', true ) && 1000.0 === (float) $order->get_meta( '_cvd_collection_amount_cup', true ), 'No se conservaron importes por moneda.' );
cvt_verify( absint( $fixture['messenger_id'] ) === absint( $order->get_meta( '_cvd_collection_received_by', true ) ), 'Actor de cobro incorrecto.' );

$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id=%d ORDER BY occurred_at,id", $order_id ), ARRAY_A );
cvt_verify( count( $rows ) >= 14, 'Faltan eventos del flujo sintético.' );
$pairs = array_map( static fn( array $row ): string => $row['domain'] . ':' . $row['to_state'], $rows );
foreach ( array( 'operation:preparing','operation:ready','delivery:assigned','delivery:to_store','delivery:picked_up','delivery:handed_over','delivery:delivered','payment:returned','delivery:closed','payment:verified' ) as $expected ) {
	cvt_verify( in_array( $expected, $pairs, true ), 'Falta evento: ' . $expected );
}
$incident_counts = array_count_values( array_column( $rows, 'event_type' ) );
cvt_verify( 2 === ( $incident_counts['incident.opened'] ?? 0 ), 'Deben existir dos aperturas legítimas de incidencia.' );
cvt_verify( 2 === ( $incident_counts['incident.resolved'] ?? 0 ), 'Deben existir dos resoluciones de incidencia.' );

$expected_actors = array(
	array( 'domain'=>'operation', 'to_state'=>'preparing', 'role'=>'cvd_clerk', 'id'=>absint($fixture['clerk_id']) ),
	array( 'domain'=>'delivery', 'to_state'=>'picked_up', 'role'=>'cvd_clerk', 'id'=>absint($fixture['clerk_id']) ),
	array( 'domain'=>'delivery', 'to_state'=>'handed_over', 'role'=>'cvd_messenger', 'id'=>absint($fixture['messenger_id']) ),
	array( 'domain'=>'delivery', 'to_state'=>'closed', 'role'=>'administrator', 'id'=>absint($fixture['admin_id']) ),
);
foreach ( $expected_actors as $expected_actor ) {
	$match = array_values( array_filter( $rows, static fn( array $row ): bool => $row['domain'] === $expected_actor['domain'] && $row['to_state'] === $expected_actor['to_state'] && (int)$row['actor_user_id'] === $expected_actor['id'] && $row['actor_role'] === $expected_actor['role'] ) );
	cvt_verify( (bool) $match, 'Actor incorrecto para ' . $expected_actor['domain'] . ':' . $expected_actor['to_state'] );
}
cvt_verify( (bool) array_filter( $rows, static fn( array $row ): bool => 'order.created' === $row['event_type'] && 0 === (int)$row['actor_user_id'] && 'system' === $row['actor_role'] ), 'La creación automática no figura como system.' );

// Idempotencia real de INSERT IGNORE.
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d", $order_id ) );
$event = array( 'order_id'=>$order_id, 'event_type'=>'order.integration_probe', 'domain'=>'order', 'source'=>'integration', 'idempotency_key'=>'integration-duplicate-' . $order_id, 'timestamp'=>'2026-08-15 16:00:00 UTC' );
CVD_Order_Events::record( $event ); CVD_Order_Events::record( $event );
$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d", $order_id ) );
cvt_verify( $after === $before + 1, 'La repetición creó más de una fila.' );

// Tres eventos repetibles con el mismo segundo exacto contra la tabla real.
foreach ( array( array('incident.opened','handed_over','incident','same-second-open-1'), array('incident.resolved','incident','handed_over','same-second-resolved'), array('incident.opened','handed_over','incident','same-second-open-2') ) as $probe ) {
	CVD_Order_Events::record( array( 'order_id'=>$order_id, 'event_type'=>$probe[0], 'domain'=>'incident', 'from_state'=>$probe[1], 'to_state'=>$probe[2], 'timestamp'=>'2026-08-15 16:30:00 UTC', 'source'=>'integration_same_second', 'idempotency_key'=>$probe[3] . '-' . $order_id ) );
}
cvt_verify( 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d AND source='integration_same_second'", $order_id ) ), 'Los eventos repetibles del mismo segundo colisionaron.' );

// Actores reales y sistema.
wp_set_current_user( absint( $fixture['gestora_id'] ) );
CVD_Order_Events::record( array( 'order_id'=>$order_id, 'event_type'=>'commission.integration_actor_probe', 'domain'=>'commission', 'source'=>'integration', 'idempotency_key'=>'gestora-actor-' . $order_id ) );
wp_set_current_user( 0 );
CVD_Order_Events::record( array( 'order_id'=>$order_id, 'event_type'=>'order.integration_system_probe', 'domain'=>'order', 'source'=>'integration', 'idempotency_key'=>'system-actor-' . $order_id ) );
$actors = $wpdb->get_results( $wpdb->prepare( "SELECT actor_user_id,actor_role FROM {$table} WHERE order_id=%d AND event_type LIKE %s", $order_id, '%integration_%' ), ARRAY_A );
cvt_verify( in_array( 'cvd_gestora', array_column( $actors, 'actor_role' ), true ), 'No se registró el rol de gestora.' );
cvt_verify( in_array( 'system', array_column( $actors, 'actor_role' ), true ), 'No se registró el actor system.' );

// Pedido legacy sin migración masiva, seguido de un evento nuevo.
$legacy = wc_create_order(); $legacy->update_meta_data( '_cvd_operation_history', array( array( 'from'=>'new','to'=>'preparing','user_id'=>0,'at'=>'2025-01-01 10:00:00' ) ) );
$legacy->update_meta_data( '_cvd_delivery_history', array( array( 'from'=>'accepted','to'=>'to_store','actor_user_id'=>0,'at'=>'2025-01-01 10:05:00','data'=>array() ) ) );
$legacy->update_meta_data( '_cvd_commission_history', array( array( 'from'=>'pending','to'=>'approved','user_id'=>0,'at'=>'2025-01-01 10:10:00' ) ) ); $legacy->save();
$legacy_before = CVD_Order_Event_Timeline::for_wc_order( $legacy, 1, 50 ); cvt_verify( 3 === $legacy_before['total'], 'Timeline legacy incorrecto.' );
CVD_Order_Events::record( array( 'order_id'=>$legacy->get_id(), 'event_type'=>'delivery.state_changed', 'domain'=>'delivery', 'from_state'=>'to_store','to_state'=>'picked_up','timestamp'=>'2025-01-01 10:15:00 UTC','source'=>'integration','idempotency_key'=>'legacy-new-' . $legacy->get_id() ) );
$legacy_after = CVD_Order_Event_Timeline::for_wc_order( $legacy, 1, 50 ); cvt_verify( 4 === $legacy_after['total'], 'No se unieron legacy y canonical.' );

// 250 eventos persistidos y paginados.
$bulk = wc_create_order();
$started = microtime( true );
for ( $i = 0; $i < 250; $i++ ) { CVD_Order_Events::record( array( 'order_id'=>$bulk->get_id(), 'event_type'=>'order.bulk_probe', 'domain'=>'order', 'source'=>'integration', 'idempotency_key'=>'bulk-' . $bulk->get_id() . '-' . $i, 'metadata'=>array('sequence'=>$i) ) ); }
$elapsed = microtime( true ) - $started;
cvt_verify( 250 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id=%d", $bulk->get_id() ) ), 'Se truncaron eventos canónicos.' );
$page = CVD_Order_Event_Timeline::for_wc_order( $bulk, 3, 100 ); cvt_verify( 250 === $page['total'] && 50 === count( $page['events'] ), 'Paginación de 250 eventos incorrecta.' );

// Privacidad sobre metadata real.
$metadata = implode( '\n', array_column( $wpdb->get_results( "SELECT metadata FROM {$table}", ARRAY_A ), 'metadata' ) );
foreach ( array( 'customer@example.invalid', '0000000000', 'Dirección sintética no real', 'Synthetic-Admin-Only', 'token', 'password' ) as $forbidden ) { cvt_verify( false === stripos( $metadata, $forbidden ), 'Metadata contiene dato prohibido: ' . $forbidden ); }

update_option( 'cvt_integration_result', array( 'order_id'=>$order_id, 'flow_events'=>count($rows), 'bulk_events'=>250, 'bulk_seconds'=>$elapsed, 'legacy_total'=>$legacy_after['total'] ) );
echo wp_json_encode( get_option( 'cvt_integration_result' ), JSON_PRETTY_PRINT ) . PHP_EOL;
