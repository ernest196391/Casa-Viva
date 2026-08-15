<?php

defined( 'ABSPATH' ) || exit;

/**
 * Autoridad progresiva para transiciones de pedido.
 *
 * Fase 1C migra solamente un subconjunto operativo sin efectos contables. Los demás
 * dominios continúan en sus escritores legacy hasta una fase aprobada.
 */
final class CVD_Order_Transition_Service {
	public const INVALID_TRANSITION = 'INVALID_TRANSITION';
	public const UNAUTHORIZED = 'UNAUTHORIZED';
	public const PRECONDITION_FAILED = 'PRECONDITION_FAILED';
	public const CONFLICT = 'CONFLICT';
	public const ALREADY_APPLIED = 'ALREADY_APPLIED';
	public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
	public const SIDE_EFFECT_FAILED = 'SIDE_EFFECT_FAILED';

	private const RECEIPTS_META = '_cvd_transition_receipts';
	private const OPERATION_TRANSITIONS = array(
		'new'       => array( 'preparing', 'incident' ),
		'confirmed' => array( 'preparing', 'incident' ),
		'preparing' => array( 'incident' ),
		'incident'  => array( 'confirmed', 'preparing' ),
	);

	/** Indica si una transición pertenece al primer subconjunto centralizado. */
	public static function governs( string $domain, string $from, string $to ): bool {
		return 'operation' === $domain && in_array( $to, self::OPERATION_TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * @return array{success:bool,previous_state:string,new_state:string,event_id:string,idempotent_replay:bool,error_code:string}
	 */
	public static function transition( int $order_id, string $domain, string $target_state, array $context = array() ): array {
		$domain = sanitize_key( $domain );
		$target_state = sanitize_key( $target_state );
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return self::failure( self::ORDER_NOT_FOUND ); }

		$actor_id = array_key_exists( 'actor_user_id', $context ) ? absint( $context['actor_user_id'] ) : get_current_user_id();
		$actor = $actor_id ? get_userdata( $actor_id ) : null;
		if ( ! $actor || ( ! user_can( $actor, 'cvd_manage_sales' ) && ! user_can( $actor, 'manage_woocommerce' ) ) ) {
			return self::failure( self::UNAUTHORIZED );
		}
		if ( 'operation' !== $domain ) { return self::failure( self::INVALID_TRANSITION ); }

		$idempotency_key = trim( (string) ( $context['idempotency_key'] ?? '' ) );
		$receipt_hash = $idempotency_key ? hash( 'sha256', $idempotency_key ) : '';
		$receipts = self::receipts( $order );
		if ( $receipt_hash && isset( $receipts[ $receipt_hash ] ) ) {
			$receipt = $receipts[ $receipt_hash ];
			if ( $domain !== ( $receipt['domain'] ?? '' ) || $target_state !== ( $receipt['to'] ?? '' ) ) {
				return self::failure( self::CONFLICT );
			}
			return self::success( (string) ( $receipt['from'] ?? '' ), $target_state, (string) ( $receipt['event_id'] ?? '' ), true, self::ALREADY_APPLIED );
		}

		global $wpdb;
		$lock_key = 'cvd_transition_' . $order_id;
		if ( ! self::acquire_lock( $wpdb, $lock_key ) ) { return self::failure( self::CONFLICT ); }

		$transaction_started = false;
		$operation_snapshot = null;
		try {
			// Otro actor pudo avanzar el pedido mientras se esperaba el lock.
			$order = wc_get_order( $order_id );
			if ( ! $order ) { return self::failure( self::ORDER_NOT_FOUND ); }
			$current = self::operation_state( $order );
			$receipts = self::receipts( $order );
			if ( $receipt_hash && isset( $receipts[ $receipt_hash ] ) ) {
				$receipt = $receipts[ $receipt_hash ];
				return self::success( (string) ( $receipt['from'] ?? '' ), (string) ( $receipt['to'] ?? '' ), (string) ( $receipt['event_id'] ?? '' ), true, self::ALREADY_APPLIED );
			}
			if ( $current === $target_state ) { return self::success( $current, $current, '', true, self::ALREADY_APPLIED ); }
			if ( ! self::governs( $domain, $current, $target_state ) ) { return self::failure( self::INVALID_TRANSITION, $current ); }
			if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
				return self::failure( self::PRECONDITION_FAILED, $current );
			}

			$wpdb->query( 'START TRANSACTION' );
			$transaction_started = true;
			$operation_snapshot = array(
				'status' => $order->get_meta( '_cvd_operation_status', true ),
				'updated_at' => $order->get_meta( '_cvd_operation_updated_at', true ),
				'history' => $order->get_meta( '_cvd_operation_history', true ),
				'receipts' => $order->get_meta( self::RECEIPTS_META, true ),
			);
			$at = current_time( 'mysql', true );
			$anchor = $idempotency_key ?: wp_generate_uuid4();
			$history = $order->get_meta( '_cvd_operation_history', true );
			$history = is_array( $history ) ? $history : array();
			$history[] = array( 'from' => $current, 'to' => $target_state, 'user_id' => $actor_id, 'at' => $at, 'event_anchor' => $anchor );
			$order->update_meta_data( '_cvd_operation_status', $target_state );
			$order->update_meta_data( '_cvd_operation_updated_at', $at );
			$order->update_meta_data( '_cvd_operation_history', array_slice( $history, -100 ) );
			$order->add_order_note( sprintf( 'Operación Casa Viva: %s → %s.', self::label( $current ), self::label( $target_state ) ) );
			$order->save();

			// Punto de extensión controlado: permite probar rollback y alojar futuros
			// efectos sin ejecutarlos dos veces desde el wrapper legacy.
			if ( isset( $context['side_effect'] ) && is_callable( $context['side_effect'] ) ) {
				call_user_func( $context['side_effect'], $order, $current, $target_state );
			}

			$is_incident = 'incident' === $target_state || 'incident' === $current;
			$event_domain = $is_incident ? 'incident' : 'operation';
			$event_type = 'incident' === $target_state ? 'incident.opened' : ( 'incident' === $current ? 'incident.resolved' : 'operation.state_changed' );
			$event = CVD_Order_Events::record( array(
				'order_id' => $order_id,
				'event_type' => $event_type,
				'domain' => $event_domain,
				'from_state' => $current,
				'to_state' => $target_state,
				'actor_user_id' => $actor_id,
				'actor_role' => self::actor_role( $actor ),
				'timestamp' => $at,
				'source' => sanitize_key( (string) ( $context['source'] ?? 'cvd_order_transition_service' ) ) ?: 'cvd_order_transition_service',
				'metadata' => array( 'centralized' => true ),
				'idempotency_key' => CVD_Order_Events::transition_key( $order_id, $event_domain, $current, $target_state, 'cvd_order_transition_service', $anchor ),
			) );
			if ( empty( $event['created'] ) ) { throw new RuntimeException( 'canonical_event_not_created' ); }

			if ( $receipt_hash ) {
				$receipts[ $receipt_hash ] = array( 'domain' => $domain, 'from' => $current, 'to' => $target_state, 'event_id' => $event['event_id'] );
				$order->update_meta_data( self::RECEIPTS_META, array_slice( $receipts, -50, null, true ) );
				$order->save();
			}
			$wpdb->query( 'COMMIT' );
			$transaction_started = false;
			return self::success( $current, $target_state, (string) $event['event_id'] );
		} catch ( Throwable $error ) {
			if ( $transaction_started ) { $wpdb->query( 'ROLLBACK' ); }
			if ( is_array( $operation_snapshot ) ) {
				foreach ( array( '_cvd_operation_status'=>'status', '_cvd_operation_updated_at'=>'updated_at', '_cvd_operation_history'=>'history', self::RECEIPTS_META=>'receipts' ) as $meta_key=>$snapshot_key ) {
					if ( '' === $operation_snapshot[$snapshot_key] ) { $order->delete_meta_data( $meta_key ); }
					else { $order->update_meta_data( $meta_key, $operation_snapshot[$snapshot_key] ); }
				}
			}
			if ( function_exists( 'clean_post_cache' ) ) { clean_post_cache( $order_id ); }
			return self::failure( self::SIDE_EFFECT_FAILED );
		} finally {
			self::release_lock( $wpdb, $lock_key );
		}
	}

	private static function operation_state( WC_Order $order ): string {
		$value = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
		return $value ?: 'new';
	}

	private static function receipts( WC_Order $order ): array {
		$value = $order->get_meta( self::RECEIPTS_META, true );
		return is_array( $value ) ? $value : array();
	}

	private static function acquire_lock( $wpdb, string $key ): bool {
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $key ) );
	}

	private static function release_lock( $wpdb, string $key ): void {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	private static function actor_role( WP_User $actor ): string {
		$roles = (array) $actor->roles;
		return sanitize_key( (string) ( reset( $roles ) ?: 'unknown' ) );
	}

	private static function label( string $status ): string {
		$labels = array( 'new'=>'Nuevo', 'confirmed'=>'Confirmado', 'preparing'=>'Preparando', 'incident'=>'Incidencia' );
		return $labels[ $status ] ?? ucfirst( $status );
	}

	private static function success( string $previous, string $new, string $event_id, bool $replay = false, string $code = '' ): array {
		return array( 'success'=>true, 'previous_state'=>$previous, 'new_state'=>$new, 'event_id'=>$event_id, 'idempotent_replay'=>$replay, 'error_code'=>$code );
	}

	private static function failure( string $code, string $previous = '' ): array {
		return array( 'success'=>false, 'previous_state'=>$previous, 'new_state'=>$previous, 'event_id'=>'', 'idempotent_replay'=>false, 'error_code'=>$code );
	}
}
