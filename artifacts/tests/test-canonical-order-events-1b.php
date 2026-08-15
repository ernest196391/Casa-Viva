<?php

define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_time( $type, $gmt = false ): string { return '2026-08-15 12:00:00'; }
function get_current_user_id(): int { return 0; }
function get_userdata( $id ) { return false; }

require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-order-events.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FALLO: {$message}\n" ); exit( 1 ); }
}

function base_event( string $key, array $changes = array() ): array {
	return array_merge( array(
		'order_id' => 459, 'event_type' => 'operation.state_changed', 'domain' => 'operation',
		'from_state' => 'new', 'to_state' => 'preparing', 'timestamp' => '2026-08-15 12:01:00 UTC',
		'source' => 'unit_test', 'idempotency_key' => $key,
	), $changes );
}

$repo = new CVD_Array_Order_Event_Repository();
CVD_Order_Events::use_repository( $repo );

// 1. Creación de evento.
$created = CVD_Order_Events::record( base_event( 'create' ) );
check( true === $created['created'] && str_starts_with( $created['event_id'], 'cv_evt_' ), 'creación de evento' );

// 2. Orden cronológico.
CVD_Order_Events::record( base_event( 'earlier', array( 'timestamp' => '2026-08-15 11:59:00 UTC', 'to_state' => 'confirmed' ) ) );
$timeline = CVD_Order_Event_Timeline::read( 459, array(), 1, 20, $repo );
check( 'confirmed' === $timeline['events'][0]['to_state'], 'orden cronológico' );

// 3. Dos dominios en el mismo pedido.
CVD_Order_Events::record( base_event( 'delivery', array( 'domain' => 'delivery', 'event_type' => 'delivery.state_changed', 'from_state' => 'accepted', 'to_state' => 'to_store' ) ) );
check( 2 === count( array_unique( array_column( CVD_Order_Event_Timeline::read( 459, array(), 1, 20, $repo )['events'], 'domain' ) ) ), 'dos dominios' );

// 4. Actor conocido.
$known = CVD_Order_Events::record( base_event( 'known', array( 'actor_user_id' => 18, 'actor_role' => 'cvd_messenger' ) ) );
check( 18 === $known['actor_user_id'] && 'cvd_messenger' === $known['actor_role'], 'actor conocido' );

// 5. Actor desconocido.
$unknown = CVD_Order_Events::record( base_event( 'unknown', array( 'actor_user_id' => 99 ) ) );
check( 'unknown' === $unknown['actor_role'], 'actor desconocido explícito' );

// 6. Incidencia adicional sin borrar la etapa logística.
$incident_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $incident_repo );
CVD_Order_Events::record( base_event( 'logistics', array( 'domain' => 'delivery', 'event_type' => 'delivery.state_changed', 'from_state' => 'picked_up', 'to_state' => 'handed_over' ) ) );
CVD_Order_Events::record( base_event( 'incident', array( 'domain' => 'incident', 'event_type' => 'incident.opened', 'from_state' => '', 'to_state' => 'open', 'timestamp' => '2026-08-15 12:02:00 UTC' ) ) );
$incident_timeline = CVD_Order_Event_Timeline::read( 459, array(), 1, 20, $incident_repo );
check( 2 === $incident_timeline['total'] && 'handed_over' === $incident_timeline['events'][0]['to_state'], 'incidencia no altera etapa' );

// 7. Evento duplicado.
$duplicate = CVD_Order_Events::record( base_event( 'incident', array( 'domain' => 'incident', 'event_type' => 'incident.opened' ) ) );
check( false === $duplicate['created'] && 2 === CVD_Order_Event_Timeline::read( 459, array(), 1, 20, $incident_repo )['total'], 'evento duplicado' );

// 8. Doble pulsación con la misma clave de acción.
$click_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $click_repo );
CVD_Order_Events::record( base_event( 'button:459:to_store' ) );
$second_click = CVD_Order_Events::record( base_event( 'button:459:to_store', array( 'timestamp' => '2026-08-15 12:01:05 UTC' ) ) );
check( false === $second_click['created'] && 1 === CVD_Order_Event_Timeline::read( 459, array(), 1, 20, $click_repo )['total'], 'doble pulsación' );

// 9. Reintento HTTP de la misma acción.
$retry = CVD_Order_Events::record( base_event( 'button:459:to_store' ) );
check( false === $retry['created'], 'reintento HTTP' );

// 10. Pedido sin eventos.
check( 0 === CVD_Order_Event_Timeline::read( 999, array(), 1, 20, $click_repo )['total'], 'pedido sin eventos' );

// 11. Pedido histórico marcado legacy sin inventar huecos.
$legacy = array( 'delivery_history' => array( array( 'from' => 'accepted', 'to' => 'to_store', 'actor_user_id' => 7, 'at' => '2025-01-01 10:00:00' ) ) );
$historical = CVD_Order_Event_Timeline::read( 777, $legacy, 1, 20, $click_repo );
check( 1 === $historical['total'] && 'legacy' === $historical['events'][0]['source'], 'pedido histórico' );

// 12. Más de 150 eventos sin pérdida.
$many_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $many_repo );
for ( $i = 0; $i < 175; $i++ ) {
	CVD_Order_Events::record( base_event( 'many-' . $i, array( 'order_id' => 800, 'timestamp' => sprintf( '2026-08-15 12:%02d:%02d UTC', intdiv( $i, 60 ), $i % 60 ), 'metadata' => array( 'sequence' => $i ) ) ) );
}
check( 175 === CVD_Order_Event_Timeline::read( 800, array(), 1, 200, $many_repo )['total'], 'más de 150 eventos' );

// 13. Metadata vacía.
$empty_metadata = CVD_Order_Events::record( base_event( 'empty-metadata', array( 'order_id' => 801, 'metadata' => array() ) ) );
check( array() === $empty_metadata['metadata'], 'metadata vacía' );

// 14. Timestamps equivalentes se normalizan igual y se ordenan establemente.
$utc = CVD_Order_Events::record( base_event( 'tz-utc', array( 'order_id' => 802, 'timestamp' => '2026-08-15T16:00:00+00:00' ) ) );
$local = CVD_Order_Events::record( base_event( 'tz-local', array( 'order_id' => 802, 'timestamp' => '2026-08-15T12:00:00-04:00' ) ) );
check( $utc['timestamp'] === $local['timestamp'], 'timestamp equivalente' );

// 15. Lectura paginada completa, sin borrar eventos.
$page_one = CVD_Order_Event_Timeline::read( 800, array(), 1, 50, $many_repo );
$page_four = CVD_Order_Event_Timeline::read( 800, array(), 4, 50, $many_repo );
check( 50 === count( $page_one['events'] ) && 25 === count( $page_four['events'] ) && 175 === $page_four['total'], 'lectura paginada' );

// 16. Dos aperturas legítimas de incidencia en el mismo segundo no colisionan.
$repeat_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $repeat_repo );
CVD_Order_Events::observe_transition( 900, 'delivery', 'handed_over', 'incident', 'cvd_delivery_transition', array(), 'incident-attempt-1' );
CVD_Order_Events::observe_transition( 900, 'delivery', 'incident', 'handed_over', 'cvd_delivery_transition', array(), 'incident-resolution-1' );
CVD_Order_Events::observe_transition( 900, 'delivery', 'handed_over', 'incident', 'cvd_delivery_transition', array(), 'incident-attempt-2' );
check( 3 === CVD_Order_Event_Timeline::read( 900, array(), 1, 20, $repeat_repo )['total'], 'incidencias legítimamente repetibles' );

// 17. El orden dentro del mismo segundo usa la secuencia de inserción, no el hash.
$same_second_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $same_second_repo );
$first_same_second = CVD_Order_Events::record( base_event( 'same-second-z', array( 'order_id' => 901 ) ) );
CVD_Order_Events::record( base_event( 'same-second-a', array( 'order_id' => 901 ) ) );
$same_second = CVD_Order_Event_Timeline::read( 901, array(), 1, 20, $same_second_repo );
check( $first_same_second['event_id'] === $same_second['events'][0]['event_id'], 'desempate secuencial en el mismo segundo' );

// 18. Un fallo del store no interrumpe al observador y deja diagnóstico.
final class CVD_Failing_Order_Event_Repository implements CVD_Order_Event_Repository {
	public function insert( array $event ): bool { throw new RuntimeException( 'tabla de prueba no disponible' ); }
	public function for_order( int $order_id ): array { return array(); }
}
$error_log = tempnam( sys_get_temp_dir(), 'cvd-event-log-' );
ini_set( 'log_errors', '1' ); ini_set( 'error_log', $error_log );
CVD_Order_Events::use_repository( new CVD_Failing_Order_Event_Repository() );
CVD_Order_Events::observe_transition( 902, 'operation', 'new', 'preparing', 'unit_test', array(), 'failure-1' );
$diagnostic = file_get_contents( $error_log ); unlink( $error_log );
check( false !== strpos( $diagnostic, 'Casa Viva event store:' ) && false !== strpos( $diagnostic, 'tabla de prueba no disponible' ), 'diagnóstico no bloqueante del event store' );

// 19. La deduplicación legacy/canonical es uno-a-uno y no borra eventos parecidos legítimos.
$dedup_repo = new CVD_Array_Order_Event_Repository(); CVD_Order_Events::use_repository( $dedup_repo );
CVD_Order_Events::record( base_event( 'canonical-overlap', array( 'order_id' => 903, 'domain' => 'delivery', 'event_type' => 'delivery.state_changed', 'from_state' => 'accepted', 'to_state' => 'to_store', 'timestamp' => '2026-08-15 12:01:00 UTC' ) ) );
$duplicate_legacy = array( 'delivery_history' => array(
	array( 'from' => 'accepted', 'to' => 'to_store', 'at' => '2026-08-15 12:01:00' ),
	array( 'from' => 'accepted', 'to' => 'to_store', 'at' => '2026-08-15 12:01:00' ),
) );
check( 2 === CVD_Order_Event_Timeline::read( 903, $duplicate_legacy, 1, 20, $dedup_repo )['total'], 'deduplicación uno-a-uno' );

echo "OK: 19 escenarios del historial canónico de eventos.\n";
