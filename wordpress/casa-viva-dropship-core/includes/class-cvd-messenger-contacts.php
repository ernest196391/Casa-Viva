<?php

defined( 'ABSPATH' ) || exit;

/** Eventos auditables de contacto, separados de los estados operativo y logístico. */
final class CVD_Messenger_Contacts {
	private const OUTCOMES = array(
		'confirmed' => 'contact.confirmed',
		'no_answer' => 'contact.no_answer',
		'reschedule_requested' => 'contact.reschedule_requested',
		'location_received' => 'contact.location_received',
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/messenger/orders/(?P<id>\d+)/contact', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'record' ),
			'permission_callback' => array( __CLASS__, 'can_record' ),
		) );
	}

	public static function can_record( WP_REST_Request $request ): bool {
		$user = wp_get_current_user();
		$order = wc_get_order( absint( $request->get_param( 'id' ) ) );
		if ( ! $user->exists() || ! $order || 'mensajero' !== CVD_Registration::program_type( $user ) || 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) { return false; }
		if ( $user->ID !== absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return false; }
		$stage = 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true ) ? sanitize_key( (string) $order->get_meta( '_cvd_delivery_incident_stage', true ) ) : sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) );
		return in_array( $stage, array( 'accepted', 'to_store', 'picked_up', 'handed_over' ), true );
	}

	public static function record( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$outcome = sanitize_key( (string) $request->get_param( 'outcome' ) );
		$key = trim( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotencyKey' ) ) );
		if ( ! isset( self::OUTCOMES[ $outcome ] ) ) { return new WP_Error( 'cvd_contact_invalid', 'Selecciona un resultado de contacto válido.', array( 'status' => 422 ) ); }
		if ( strlen( $key ) < 16 || strlen( $key ) > 128 ) { return new WP_Error( 'cvd_contact_idempotency', 'No se pudo validar la acción. Inténtalo otra vez.', array( 'status' => 400 ) ); }
		try {
			$event = CVD_Order_Events::record( array(
				'order_id' => $order_id,
				'event_type' => self::OUTCOMES[ $outcome ],
				'domain' => 'contact',
				'from_state' => '',
				'to_state' => $outcome,
				'source' => 'messenger_contact_result',
				'metadata' => array( 'channel' => sanitize_key( (string) $request->get_param( 'channel' ) ) ?: 'unspecified' ),
				'idempotency_key' => 'messenger-contact|' . $order_id . '|' . $key,
			) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'cvd_contact_storage', 'No se pudo registrar el contacto.', array( 'status' => 500 ) );
		}
		$response = rest_ensure_response( array( 'eventId' => $event['event_id'], 'eventType' => $event['event_type'], 'recordedAt' => $event['timestamp'], 'idempotentReplay' => ! $event['created'] ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function latest( WC_Order $order ): ?array {
		$events = array_filter( CVD_Order_Events::repository()->for_order( $order->get_id() ), static fn( array $event ): bool => 'contact' === ( $event['domain'] ?? '' ) );
		if ( ! $events ) { return null; }
		usort( $events, static fn( array $a, array $b ): int => array( $a['timestamp'] ?? '', $a['sequence_id'] ?? 0 ) <=> array( $b['timestamp'] ?? '', $b['sequence_id'] ?? 0 ) );
		return end( $events ) ?: null;
	}
}
