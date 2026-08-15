<?php

defined( 'ABSPATH' ) || exit;

interface CVD_Order_Event_Repository {
	public function insert( array $event ): bool;
	public function for_order( int $order_id ): array;
}

final class CVD_Array_Order_Event_Repository implements CVD_Order_Event_Repository {
	private array $events = array();
	private int $next_sequence = 1;

	public function insert( array $event ): bool {
		if ( isset( $this->events[ $event['event_id'] ] ) ) { return false; }
		$event['sequence_id'] = $this->next_sequence++;
		$this->events[ $event['event_id'] ] = $event;
		return true;
	}

	public function for_order( int $order_id ): array {
		return array_values( array_filter( $this->events, static fn( array $event ): bool => $order_id === (int) $event['order_id'] ) );
	}
}

final class CVD_WPDB_Order_Event_Repository implements CVD_Order_Event_Repository {
	public function insert( array $event ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_order_events';
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (event_id,idempotency_key,order_id,event_type,domain,from_state,to_state,actor_user_id,actor_role,occurred_at,source,metadata,created_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)",
			$event['event_id'], $event['idempotency_key'], $event['order_id'], $event['event_type'], $event['domain'], $event['from_state'], $event['to_state'], $event['actor_user_id'], $event['actor_role'], $event['timestamp'], $event['source'], wp_json_encode( $event['metadata'] ), current_time( 'mysql', true )
		);
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			$message = trim( (string) $wpdb->last_error ) ?: 'Error desconocido de base de datos.';
			throw new RuntimeException( 'No se pudo registrar el evento canónico: ' . $message );
		}
		return 1 === (int) $result;
	}

	public function for_order( int $order_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_order_events';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id=%d ORDER BY occurred_at ASC,id ASC", $order_id ), ARRAY_A );
		return array_map( static function ( array $row ): array {
			$row['sequence_id'] = (int) $row['id'];
			$row['order_id'] = (int) $row['order_id'];
			$row['actor_user_id'] = (int) $row['actor_user_id'];
			$row['timestamp'] = $row['occurred_at'];
			$row['metadata'] = json_decode( (string) $row['metadata'], true ) ?: array();
			unset( $row['id'], $row['occurred_at'], $row['created_at'] );
			return $row;
		}, is_array( $rows ) ? $rows : array() );
	}
}

/** Persistencia aditiva e inmutable de eventos observados. No gobierna transiciones. */
final class CVD_Order_Events {
	private const DOMAINS = array( 'order', 'operation', 'delivery', 'payment', 'commission', 'incident' );
	private static ?CVD_Order_Event_Repository $repository = null;

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'observe_order_created' ), 90 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'observe_woocommerce_status' ), 50, 4 );
		add_action( 'cvd_order_transition_observed', array( __CLASS__, 'observe_transition' ), 10, 7 );
	}

	public static function observe_order_created( WC_Order $order ): void {
		$date = $order->get_date_created(); $anchor = $date && method_exists( $date, 'getTimestamp' ) ? (string) $date->getTimestamp() : (string) $order->get_id();
		self::safe_record( array(
			'order_id' => $order->get_id(), 'event_type' => 'order.created', 'domain' => 'order',
			'from_state' => '', 'to_state' => $order->get_status(), 'source' => 'woocommerce_checkout_order_created',
			'idempotency_key' => self::transition_key( $order->get_id(), 'order', '', $order->get_status(), 'woocommerce_created', $anchor ),
		) );
	}

	public static function repository(): CVD_Order_Event_Repository {
		return self::$repository ??= new CVD_WPDB_Order_Event_Repository();
	}

	public static function use_repository( CVD_Order_Event_Repository $repository ): void { self::$repository = $repository; }

	public static function record( array $input ): array {
		$event = self::normalize( $input );
		$event['created'] = self::repository()->insert( $event );
		return $event;
	}

	public static function observe_woocommerce_status( int $order_id, string $from, string $to, $order ): void {
		$modified = is_object( $order ) && method_exists( $order, 'get_date_modified' ) ? $order->get_date_modified() : null;
		$anchor = $modified && method_exists( $modified, 'getTimestamp' ) ? (string) $modified->getTimestamp() : self::current_anchor();
		self::safe_record( array(
			'order_id' => $order_id, 'event_type' => 'order.status_changed', 'domain' => 'order',
			'from_state' => $from, 'to_state' => $to, 'source' => 'woocommerce_order_status_changed',
			'idempotency_key' => self::transition_key( $order_id, 'order', $from, $to, 'woocommerce', $anchor ),
		) );
	}

	public static function observe_transition( int $order_id, string $domain, string $from, string $to, string $source, array $metadata = array(), string $anchor = '' ): void {
		$event_type = $domain . '.state_changed';
		if ( 'incident' === $to ) { $domain = 'incident'; $event_type = 'incident.opened'; }
		elseif ( 'incident' === $from ) { $domain = 'incident'; $event_type = 'incident.resolved'; }
		self::safe_record( array(
			'order_id' => $order_id, 'event_type' => $event_type, 'domain' => $domain,
			'from_state' => $from, 'to_state' => $to, 'source' => $source, 'metadata' => $metadata,
			'idempotency_key' => self::transition_key( $order_id, $domain, $from, $to, $source, $anchor ?: self::current_anchor() ),
		) );
	}

	public static function transition_key( int $order_id, string $domain, string $from, string $to, string $source, string $anchor ): string {
		return implode( '|', array( $order_id, $domain, $from, $to, $source, $anchor ) );
	}

	private static function normalize( array $input ): array {
		$order_id = absint( $input['order_id'] ?? 0 );
		$domain = sanitize_key( (string) ( $input['domain'] ?? '' ) );
		$type = preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) ( $input['event_type'] ?? '' ) ) );
		$key = trim( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( ! $order_id || ! in_array( $domain, self::DOMAINS, true ) || '' === $type || '' === $key ) { throw new InvalidArgumentException( 'Evento canónico incompleto.' ); }
		$actor = self::actor( $input );
		$timestamp = self::utc_timestamp( (string) ( $input['timestamp'] ?? '' ) );
		return array(
			'event_id' => 'cv_evt_' . hash( 'sha256', $key ), 'idempotency_key' => hash( 'sha256', $key ),
			'order_id' => $order_id, 'event_type' => $type, 'domain' => $domain,
			'from_state' => sanitize_key( (string) ( $input['from_state'] ?? '' ) ),
			'to_state' => sanitize_key( (string) ( $input['to_state'] ?? '' ) ),
			'actor_user_id' => $actor['id'], 'actor_role' => $actor['role'], 'timestamp' => $timestamp,
			'source' => sanitize_key( (string) ( $input['source'] ?? 'unknown' ) ) ?: 'unknown',
			'metadata' => is_array( $input['metadata'] ?? null ) ? $input['metadata'] : array(),
		);
	}

	private static function actor( array $input ): array {
		if ( array_key_exists( 'actor_user_id', $input ) ) {
			$id = absint( $input['actor_user_id'] );
			$role = sanitize_key( (string) ( $input['actor_role'] ?? ( $id ? 'unknown' : 'system' ) ) );
			return array( 'id' => $id, 'role' => $role ?: ( $id ? 'unknown' : 'system' ) );
		}
		$id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! $id ) { return array( 'id' => 0, 'role' => 'system' ); }
		$user = function_exists( 'get_userdata' ) ? get_userdata( $id ) : null;
		$roles = $user && isset( $user->roles ) ? (array) $user->roles : array();
		return array( 'id' => $id, 'role' => $roles ? sanitize_key( (string) reset( $roles ) ) : 'unknown' );
	}

	private static function utc_timestamp( string $value ): string {
		if ( '' === trim( $value ) ) { return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ); }
		try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
		catch ( Throwable $error ) { throw new InvalidArgumentException( 'Timestamp inválido.', 0, $error ); }
	}

	private static function current_anchor(): string { return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ); }
	private static function safe_record( array $event ): void {
		try { self::record( $event ); }
		catch ( Throwable $error ) {
			if ( function_exists( 'error_log' ) ) { error_log( 'Casa Viva event store: ' . $error->getMessage() ); }
			if ( function_exists( 'do_action' ) ) { do_action( 'cvd_order_event_store_failed', $error, $event ); }
		}
	}
}

/** Une eventos canónicos y evidencia legacy sin rellenar huecos. */
final class CVD_Order_Event_Timeline {
	public static function for_wc_order( WC_Order $order, int $page = 1, int $per_page = 50, ?CVD_Order_Event_Repository $repository = null ): array {
		return self::read( $order->get_id(), array(
			'operation_history' => $order->get_meta( '_cvd_operation_history', true ),
			'delivery_history' => $order->get_meta( '_cvd_delivery_history', true ),
			'commission_history' => $order->get_meta( '_cvd_commission_history', true ),
		), $page, $per_page, $repository );
	}

	public static function read( int $order_id, array $legacy = array(), int $page = 1, int $per_page = 50, ?CVD_Order_Event_Repository $repository = null ): array {
		$events = ( $repository ?? CVD_Order_Events::repository() )->for_order( $order_id );
		$canonical_signatures = array();
		foreach ( $events as $event ) { $signature = self::signature( $event ); $canonical_signatures[ $signature ] = ( $canonical_signatures[ $signature ] ?? 0 ) + 1; }
		$legacy_events = array();
		foreach ( self::legacy_events( $order_id, $legacy ) as $event ) {
			$signature = self::signature( $event );
			if ( ! empty( $canonical_signatures[ $signature ] ) ) { $canonical_signatures[ $signature ]--; continue; }
			$legacy_events[] = $event;
		}
		$events = array_merge( $events, $legacy_events );
		usort( $events, static fn( array $a, array $b ): int => self::order_key( $a ) <=> self::order_key( $b ) );
		$total = count( $events ); $per_page = max( 1, min( 200, $per_page ) ); $page = max( 1, $page );
		return array( 'events' => array_slice( $events, ( $page - 1 ) * $per_page, $per_page ), 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}

	public static function legacy_events( int $order_id, array $legacy ): array {
		$result = array();
		$maps = array( 'operation_history' => 'operation', 'delivery_history' => 'delivery', 'commission_history' => 'commission' );
		foreach ( $maps as $key => $domain ) {
			foreach ( is_array( $legacy[ $key ] ?? null ) ? $legacy[ $key ] : array() as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['at'] ) ) { continue; }
				$timestamp = self::legacy_timestamp( (string) $row['at'] ); if ( null === $timestamp ) { continue; }
				$from = sanitize_key( (string) ( $row['from'] ?? '' ) ); $to = sanitize_key( (string) ( $row['to'] ?? '' ) );
				$event_domain = ( 'incident' === $to || 'incident' === $from ) ? 'incident' : $domain;
				$type = 'incident' === $to ? 'incident.opened' : ( 'incident' === $from ? 'incident.resolved' : $domain . '.state_changed' );
				$fingerprint = implode( '|', array( $order_id, $key, $index, $row['at'], $from, $to ) );
				$result[] = array(
					'event_id' => 'cv_legacy_' . hash( 'sha256', $fingerprint ), 'idempotency_key' => '', 'order_id' => $order_id,
					'event_type' => $type, 'domain' => $event_domain, 'from_state' => $from, 'to_state' => $to,
					'actor_user_id' => absint( $row['actor_user_id'] ?? $row['user_id'] ?? 0 ), 'actor_role' => 'unknown',
					'timestamp' => $timestamp, 'source' => 'legacy',
					'metadata' => array( 'legacy_source' => '_' . 'cvd_' . str_replace( '_history', '_history', $key ), 'legacy_data' => is_array( $row['data'] ?? null ) ? $row['data'] : array() ),
					'sequence_id' => 0, 'legacy_sequence' => $index,
				);
			}
		}
		return $result;
	}

	private static function legacy_timestamp( string $value ): ?string {
		try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
		catch ( Throwable $error ) { return null; }
	}

	private static function signature( array $event ): string {
		return implode( '|', array( $event['domain'] ?? '', $event['from_state'] ?? '', $event['to_state'] ?? '', $event['timestamp'] ?? '' ) );
	}

	private static function order_key( array $event ): array {
		$sequence = (int) ( $event['sequence_id'] ?? 0 );
		return array(
			(string) ( $event['timestamp'] ?? '' ),
			$sequence > 0 ? 1 : 0,
			$sequence > 0 ? $sequence : (int) ( $event['legacy_sequence'] ?? 0 ),
			(string) ( $event['domain'] ?? '' ),
		);
	}
}
