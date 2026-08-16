<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only interpretation of the current Casa Viva order state.
 *
 * This service deliberately registers no hooks, writes no metadata and does
 * not alter existing transition rules. Removing this file and its require is
 * a complete rollback.
 */
final class CVD_Canonical_Order_Reader {
	private const OPERATION_STATES = array( 'new', 'confirmed', 'preparing', 'ready', 'with_courier', 'delivered', 'incident', 'cancelled' );
	private const DELIVERY_STATES = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed', 'incident', 'failed', 'returned', 'cancelled' );
	private const CASH_STATES = array( '', 'pending_return', 'returned', 'verified' );
	private const COMMISSION_STATES = array( '', 'pending', 'approved', 'paid', 'cancelled' );
	private const TERMINAL_WC_CANCELLED = array( 'cancelled', 'refunded' );

	/** Read only: WC_Order getters are the sole integration surface. */
	public static function read( WC_Order $order ): array {
		return self::interpret(
			array(
				'order_id'          => (int) $order->get_id(),
				'woocommerce'       => (string) $order->get_status(),
				'operation'         => (string) $order->get_meta( '_cvd_operation_status', true ),
				'delivery'          => (string) $order->get_meta( '_cvd_delivery_status', true ),
				'fulfillment'       => (string) $order->get_meta( '_cvd_fulfillment_type', true ),
				'cash'              => (string) $order->get_meta( '_cvd_cash_status', true ),
				'commission'        => (string) $order->get_meta( '_cvd_commission_status', true ),
				'messenger_user_id' => (int) $order->get_meta( '_cvd_messenger_user_id', true ),
				'operation_history' => $order->get_meta( '_cvd_operation_history', true ),
				'delivery_history'  => $order->get_meta( '_cvd_delivery_history', true ),
				'operation_incident_active' => 'yes' === $order->get_meta('_cvd_operation_incident_active',true),
				'delivery_incident_active' => 'yes' === $order->get_meta('_cvd_delivery_incident_active',true),
				'operation_incident_stage' => (string)$order->get_meta('_cvd_operation_incident_stage',true),
				'delivery_incident_stage' => (string)$order->get_meta('_cvd_delivery_incident_stage',true),
			)
		);
	}

	/** Pure function used by unit tests and diagnostics. */
	public static function interpret( array $facts ): array {
		$raw = array(
			'order_id'          => abs( (int) ( $facts['order_id'] ?? 0 ) ),
			'woocommerce'       => self::key( $facts['woocommerce'] ?? '' ),
			'operation'         => self::key( $facts['operation'] ?? '' ),
			'delivery'          => self::key( $facts['delivery'] ?? '' ),
			'fulfillment'       => self::key( $facts['fulfillment'] ?? '' ),
			'cash'              => self::key( $facts['cash'] ?? '' ),
			'commission'        => self::key( $facts['commission'] ?? '' ),
			'messenger_user_id' => abs( (int) ( $facts['messenger_user_id'] ?? 0 ) ),
		);
		$operation_history = is_array( $facts['operation_history'] ?? null ) ? $facts['operation_history'] : array();
		$delivery_history = is_array( $facts['delivery_history'] ?? null ) ? $facts['delivery_history'] : array();
		$reasons = array();
		$severity = 'OK';

		self::validate_catalog( $raw['operation'], self::OPERATION_STATES, 'operation', $reasons, $severity );
		self::validate_catalog( $raw['delivery'], self::DELIVERY_STATES, 'delivery', $reasons, $severity );
		self::validate_catalog( $raw['cash'], self::CASH_STATES, 'cash', $reasons, $severity );
		self::validate_catalog( $raw['commission'], self::COMMISSION_STATES, 'commission', $reasons, $severity );

		$is_pickup = 'pickup' === $raw['fulfillment'];
		$incident_sources = array();
		$operation_effective = $raw['operation'];
		$delivery_effective = $raw['delivery'];
		if(!empty($facts['operation_incident_active'])){$incident_sources[]='operation';$preserved=self::key($facts['operation_incident_stage']??'');if($preserved&&in_array($preserved,self::OPERATION_STATES,true)&&'incident'!==$preserved){$operation_effective=$preserved;}else{self::reason($reasons,$severity,'WARNING','INCIDENT_OPERATION_STAGE_UNKNOWN','La incidencia operativa separada no conserva una etapa válida.');}}
		if(!empty($facts['delivery_incident_active'])){$incident_sources[]='delivery';$preserved=self::key($facts['delivery_incident_stage']??'');if($preserved&&in_array($preserved,self::DELIVERY_STATES,true)&&'incident'!==$preserved){$delivery_effective=$preserved;}else{self::reason($reasons,$severity,'WARNING','INCIDENT_DELIVERY_STAGE_UNKNOWN','La incidencia logística separada no conserva una etapa válida.');}}
		if ( 'incident' === $raw['operation'] ) {
			if(!in_array('operation',$incident_sources,true)){$incident_sources[] = 'operation';}
			$operation_effective = self::previous_stage( $operation_history, 'incident', self::OPERATION_STATES );
			if ( '' === $operation_effective ) { self::reason( $reasons, $severity, 'WARNING', 'INCIDENT_OPERATION_STAGE_UNKNOWN', 'La incidencia operativa no conserva una etapa previa recuperable.' ); }
		}
		if ( 'incident' === $raw['delivery'] ) {
			if(!in_array('delivery',$incident_sources,true)){$incident_sources[] = 'delivery';}
			$delivery_effective = self::previous_stage( $delivery_history, 'incident', self::DELIVERY_STATES );
			if ( '' === $delivery_effective ) { self::reason( $reasons, $severity, 'WARNING', 'INCIDENT_DELIVERY_STAGE_UNKNOWN', 'La incidencia de mensajería no conserva una etapa previa recuperable.' ); }
		}

		$operation_candidate = self::operation_stage( $operation_effective, $is_pickup );
		$delivery_candidate = self::delivery_stage( $delivery_effective );
		$canonical = self::select_stage( $raw, $operation_effective, $delivery_effective, $operation_candidate, $delivery_candidate, $is_pickup, $reasons, $severity );

		self::check_cash( $raw['cash'], $delivery_effective, $canonical, $is_pickup, $reasons, $severity );
		self::check_commission( $raw['commission'], $canonical, $reasons, $severity );
		if ( $incident_sources && 'CONFLICT' === $canonical ) {
			self::reason( $reasons, $severity, 'CONFLICT', 'INCIDENT_STAGE_UNRESOLVED', 'La etapa normal no puede determinarse con certeza mientras la incidencia está activa.' );
		}
		if ( 'CONFLICT' === $severity ) { $canonical = 'CONFLICT'; }

		return array(
			'order_id'         => $raw['order_id'],
			'woocommerce_status'=> $raw['woocommerce'],
			'operation_status' => $raw['operation'],
			'delivery_status'  => $raw['delivery'],
			'canonical_stage'  => $canonical,
			'incident'         => $incident_sources ? array( 'active' => true, 'sources' => $incident_sources ) : array( 'active' => false, 'sources' => array() ),
			'cash_status'      => $raw['cash'] ?: 'none',
			'commission_status'=> $raw['commission'] ?: 'none',
			'consistency'      => $severity,
			'reasons'          => $reasons,
			'data_used'        => array(
				'fulfillment'                => $raw['fulfillment'] ?: 'unknown',
				'messenger_user_id'           => $raw['messenger_user_id'],
				'operation_effective'         => $operation_effective ?: 'unknown',
				'delivery_effective'          => $delivery_effective ?: 'unknown',
				'operation_candidate'         => $operation_candidate,
				'delivery_candidate'          => $delivery_candidate,
				'operation_history_event_count'=> count( $operation_history ),
				'delivery_history_event_count' => count( $delivery_history ),
			),
		);
	}

	private static function select_stage( array $raw, string $operation, string $delivery, ?string $operation_candidate, ?string $delivery_candidate, bool $is_pickup, array &$reasons, string &$severity ): string {
		$wc = $raw['woocommerce'];
		if ( in_array( $wc, self::TERMINAL_WC_CANCELLED, true ) ) {
			if ( $operation && 'cancelled' !== $operation ) { self::reason( $reasons, $severity, 'CONFLICT', 'WC_CANCELLED_OPERATION_ACTIVE', 'WooCommerce está cancelado/reembolsado, pero operación conserva un estado activo.' ); }
			if ( $delivery && ! in_array( $delivery, array( 'unassigned', 'cancelled' ), true ) ) { self::reason( $reasons, $severity, 'CONFLICT', 'WC_CANCELLED_DELIVERY_ACTIVE', 'WooCommerce está cancelado/reembolsado, pero mensajería conserva una etapa activa.' ); }
			return 'CANCELLED';
		}
		if ( 'failed' === $wc ) {
			if ( $delivery_candidate && self::stage_rank( $delivery_candidate ) >= self::stage_rank( 'COURIER_ASSIGNED' ) ) { self::reason( $reasons, $severity, 'CONFLICT', 'WC_FAILED_DELIVERY_ACTIVE', 'WooCommerce falló mientras la mensajería ya estaba en ejecución.' ); }
			else { self::reason( $reasons, $severity, 'WARNING', 'WC_FAILED_TREATED_AS_CANCELLED', 'El código operativo actual trata un pedido WooCommerce fallido como cancelado.' ); }
			return 'CANCELLED';
		}
		if ( 'cancelled' === $operation || 'cancelled' === $delivery ) {
			self::reason( $reasons, $severity, 'CONFLICT', 'CUSTOM_CANCELLED_WC_ACTIVE', 'Un estado personalizado indica cancelación, pero WooCommerce continúa activo.' );
			if ( 'cancelled' !== $operation || ( $delivery && 'cancelled' !== $delivery ) ) { self::reason( $reasons, $severity, 'WARNING', 'PARTIAL_CANCELLATION_STATE', 'La cancelación no está reflejada en todos los estados personalizados.' ); }
			return 'CANCELLED';
		}
		if ( 'failed' === $delivery || 'returned' === $delivery ) {
			if ( 'with_courier' !== $operation ) { self::reason( $reasons, $severity, 'CONFLICT', 'DELIVERY_FAILURE_WITHOUT_COURIER', 'La entrega falló o fue devuelta sin que operación demuestre custodia del mensajero.' ); }
			return 'DELIVERY_FAILED';
		}

		if ( 'completed' === $wc ) {
			$completed_custom = 'delivered' === $operation && ( $is_pickup || 'closed' === $delivery );
			if ( ! $completed_custom ) { self::reason( $reasons, $severity, 'CONFLICT', 'WC_COMPLETED_BEFORE_OPERATION_CLOSE', 'WooCommerce está completado, pero los estados operativos no demuestran cierre y conciliación.' ); }
			return 'COMPLETED';
		}

		if ( ! $is_pickup && '' === $raw['delivery'] ) {
			self::reason( $reasons, $severity, 'WARNING', 'DELIVERY_STATE_MISSING', 'El pedido a domicilio no tiene metadato de mensajería; puede ser histórico.' );
		}
		if ( '' === $raw['operation'] ) {
			self::reason( $reasons, $severity, 'WARNING', 'OPERATION_STATE_MISSING', 'El pedido no tiene metadato operativo; puede ser histórico.' );
		}

		self::check_operation_delivery_pair( $operation, $delivery, $is_pickup, $reasons, $severity );
		if ( 'CONFLICT' === $severity ) { return 'CONFLICT'; }

		if ( ! $is_pickup && 'delivered' === $operation && $delivery_candidate ) { return $delivery_candidate; }
		if ( $delivery_candidate && ( ! $operation_candidate || self::stage_rank( $delivery_candidate ) >= self::stage_rank( $operation_candidate ) ) ) { return $delivery_candidate; }
		if ( $operation_candidate ) { return $operation_candidate; }
		if ( ! $operation_candidate && ! $delivery_candidate ) {
			self::reason( $reasons, $severity, 'CONFLICT', 'CANONICAL_STAGE_UNDETERMINED', 'Los datos actuales no permiten determinar una etapa canónica.' );
			return 'CONFLICT';
		}
		return 'CONFLICT';
	}

	private static function check_operation_delivery_pair( string $operation, string $delivery, bool $is_pickup, array &$reasons, string &$severity ): void {
		if ( $is_pickup ) {
			if ( $delivery && 'unassigned' !== $delivery ) { self::reason( $reasons, $severity, 'CONFLICT', 'PICKUP_HAS_ACTIVE_DELIVERY', 'Un pedido de recogida en tienda contiene una etapa activa de mensajería.' ); }
			return;
		}
		if ( '' === $operation || '' === $delivery ) { return; }
		$allowed = array(
			'new'          => array( 'unassigned' ),
			'confirmed'    => array( 'unassigned' ),
			'preparing'    => array( 'unassigned' ),
			'ready'        => array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store' ),
			'with_courier' => array( 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed' ),
			'delivered'    => array( 'cash_returned', 'closed' ),
		);
		if ( isset( $allowed[ $operation ] ) && ! in_array( $delivery, $allowed[ $operation ], true ) ) {
			self::reason( $reasons, $severity, 'CONFLICT', 'OPERATION_DELIVERY_IMPOSSIBLE', sprintf( 'La combinación operation=%s y delivery=%s no puede producirla el flujo actual.', $operation, $delivery ) );
		}
	}

	private static function check_cash( string $cash, string $delivery, string $canonical, bool $is_pickup, array &$reasons, string &$severity ): void {
		if ( '' === $cash ) { return; }
		$allowed = array(
			'pending_return' => array( 'delivered' ),
			'returned'       => array( 'cash_returned', 'closed' ),
			'verified'       => array( 'closed' ),
		);
		if ( ! $is_pickup && isset( $allowed[ $cash ] ) && ! in_array( $delivery, $allowed[ $cash ], true ) ) {
			self::reason( $reasons, $severity, 'CONFLICT', 'CASH_DELIVERY_IMPOSSIBLE', sprintf( 'El cobro %s no corresponde a la etapa de mensajería %s.', $cash, $delivery ?: 'vacía' ) );
		}
		if ( 'verified' === $cash && 'COMPLETED' !== $canonical ) { self::reason( $reasons, $severity, 'CONFLICT', 'CASH_VERIFIED_BEFORE_COMPLETION', 'El efectivo figura verificado antes del cierre canónico.' ); }
	}

	private static function check_commission( string $commission, string $canonical, array &$reasons, string &$severity ): void {
		if ( '' === $commission ) { return; }
		$rank = self::stage_rank( $canonical );
		if ( in_array( $commission, array( 'approved', 'paid' ), true ) && $rank >= 0 && $rank < self::stage_rank( 'DELIVERED' ) ) {
			self::reason( $reasons, $severity, 'WARNING', 'COMMISSION_AHEAD_OF_FULFILLMENT', 'La comisión está aprobada/pagada antes de que la entrega esté finalizada.' );
		}
		if ( 'cancelled' === $commission && ! in_array( $canonical, array( 'CANCELLED', 'DELIVERY_FAILED', 'CONFLICT' ), true ) ) {
			self::reason( $reasons, $severity, 'WARNING', 'COMMISSION_CANCELLED_ON_ACTIVE_ORDER', 'La comisión está cancelada mientras el pedido continúa activo.' );
		}
		if ( 'pending' === $commission && 'COMPLETED' === $canonical ) {
			self::reason( $reasons, $severity, 'WARNING', 'COMMISSION_PENDING_AFTER_COMPLETION', 'El pedido está cerrado, pero la comisión continúa pendiente.' );
		}
	}

	private static function operation_stage( string $status, bool $is_pickup ): ?string {
		$map = array(
			'new'          => 'CREATED',
			'confirmed'    => 'CONFIRMED',
			'preparing'    => 'PREPARING',
			'ready'        => $is_pickup ? 'READY_FOR_PICKUP' : 'READY_FOR_COURIER',
			'with_courier' => 'PICKED_UP',
			'delivered'    => 'COMPLETED',
			'cancelled'    => 'CANCELLED',
		);
		return $map[ $status ] ?? null;
	}

	private static function delivery_stage( string $status ): ?string {
		$map = array(
			'offered'       => 'READY_FOR_COURIER',
			'assigned'      => 'COURIER_ASSIGNED',
			'accepted'      => 'COURIER_ASSIGNED',
			'to_store'      => 'COURIER_GOING_TO_PICKUP',
			'picked_up'     => 'PICKED_UP',
			'handed_over'   => 'ON_THE_WAY_TO_CUSTOMER',
			'delivered'     => 'DELIVERED',
			'cash_returned' => 'PAYMENT_RECONCILED',
			'closed'        => 'COMPLETED',
			'failed'        => 'DELIVERY_FAILED',
			'returned'      => 'DELIVERY_FAILED',
			'cancelled'     => 'CANCELLED',
		);
		return $map[ $status ] ?? null;
	}

	private static function previous_stage( array $history, string $current, array $catalog ): string {
		for ( $index = count( $history ) - 1; $index >= 0; $index-- ) {
			$event = is_array( $history[ $index ] ) ? $history[ $index ] : array();
			$to = self::key( $event['to'] ?? '' );
			$from = self::key( $event['from'] ?? '' );
			if ( ! $to ) { continue; }
			if ( $current !== $to ) { return ''; }
			return $from && $current !== $from && in_array( $from, $catalog, true ) ? $from : '';
		}
		return '';
	}

	private static function validate_catalog( string $value, array $catalog, string $axis, array &$reasons, string &$severity ): void {
		if ( '' !== $value && ! in_array( $value, $catalog, true ) ) { self::reason( $reasons, $severity, 'CONFLICT', strtoupper( $axis ) . '_STATE_UNKNOWN', sprintf( 'El estado %s=%s no pertenece al catálogo conocido.', $axis, $value ) ); }
	}

	private static function reason( array &$reasons, string &$severity, string $level, string $code, string $message ): void {
		$reasons[] = array( 'level' => $level, 'code' => $code, 'message' => $message );
		if ( 'CONFLICT' === $level || ( 'WARNING' === $level && 'OK' === $severity ) ) { $severity = $level; }
	}

	private static function key( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $value ) ?: '';
	}

	private static function stage_rank( ?string $stage ): int {
		$stages = array( 'CREATED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'READY_FOR_COURIER', 'COURIER_ASSIGNED', 'COURIER_GOING_TO_PICKUP', 'PICKED_UP', 'ON_THE_WAY_TO_CUSTOMER', 'DELIVERED', 'PAYMENT_RECONCILED', 'COMPLETED' );
		$rank = array_search( $stage, $stages, true );
		return false === $rank ? -1 : (int) $rank;
	}
}
