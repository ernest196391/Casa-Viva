<?php

defined( 'ABSPATH' ) || exit;

/**
 * Clasificación operativa sobre el servicio canónico de incidencias.
 *
 * No crea estados nuevos: cada apertura/resolución se ejecuta mediante
 * CVD_Order_Transition_Service y este adaptador conserva únicamente el motivo
 * estructurado y un historial enlazado al event_id canónico.
 */
final class CVD_Structured_Incidents {
	private const HISTORY_META = '_cvd_structured_incident_history';

	private const REASONS = array(
		'missing_product' => array(
			'label'  => 'Falta de producto',
			'domain' => 'operation',
		),
		'preparation_error' => array(
			'label'  => 'Preparación incorrecta',
			'domain' => 'operation',
		),
		'customer_no_show' => array(
			'label'       => 'Cliente no recoge',
			'domain'      => 'operation',
			'fulfillment' => 'pickup',
		),
		'messenger_no_show' => array(
			'label'       => 'Mensajero no recoge',
			'domain'      => 'delivery',
			'fulfillment' => 'delivery',
		),
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
	}

	public static function can_manage(): bool {
		return current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function routes(): void {
		register_rest_route(
			'casa-viva/v1',
			'/structured-incidents/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'act' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	public static function get( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::project( $order ) );
	}

	public static function act( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
		}

		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$note   = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$key    = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotency_key' ) ) );
		if ( ! in_array( $action, array( 'open', 'resolve' ), true ) ) {
			return new WP_Error( 'cvd_incident_action_invalid', 'Acción de incidencia no válida.', array( 'status' => 400 ) );
		}
		if ( '' === trim( $note ) ) {
			return new WP_Error( 'cvd_incident_note_required', 'Describe brevemente lo ocurrido.', array( 'status' => 400 ) );
		}

		if ( 'open' === $action ) {
			$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
			$allowed = self::allowed_reasons( $order );
			if ( ! isset( $allowed[ $reason ] ) ) {
				return new WP_Error( 'cvd_incident_reason_invalid', 'Este motivo no corresponde a la etapa actual del pedido.', array( 'status' => 409 ) );
			}
			$config = self::REASONS[ $reason ];
			$domain = $config['domain'];
			$canonical_note = $config['label'] . ' · ' . $note;
			$result = CVD_Order_Transition_Service::open_incident(
				$order->get_id(),
				$domain,
				array(
					'actor_user_id'  => get_current_user_id(),
					'idempotency_key' => $key ?: 'structured-incident:' . $order->get_id() . ':open:' . $reason . ':' . wp_generate_uuid4(),
					'note'            => $canonical_note,
				)
			);
			if ( empty( $result['success'] ) ) {
				return self::transition_error( $result );
			}
			self::record_structured( $order->get_id(), 'open', $domain, $reason, $note, $result );
		} else {
			$active = self::active( $order );
			if ( ! $active['active'] || ! $active['domain'] ) {
				return new WP_Error( 'cvd_incident_not_active', 'El pedido no tiene una incidencia activa.', array( 'status' => 409 ) );
			}
			$result = CVD_Order_Transition_Service::resolve_incident(
				$order->get_id(),
				$active['domain'],
				array(
					'actor_user_id'  => get_current_user_id(),
					'idempotency_key' => $key ?: 'structured-incident:' . $order->get_id() . ':resolve:' . wp_generate_uuid4(),
					'note'            => $note,
				)
			);
			if ( empty( $result['success'] ) ) {
				return self::transition_error( $result );
			}
			self::record_structured( $order->get_id(), 'resolve', $active['domain'], $active['reason'], $note, $result );
		}

		return rest_ensure_response(
			array(
				'transition' => $result,
				'incident'   => self::project( wc_get_order( $order->get_id() ) ),
			)
		);
	}

	private static function transition_error( array $result ): WP_Error {
		$code = sanitize_key( (string) ( $result['error_code'] ?? '' ) );
		$status = 'UNAUTHORIZED' === strtoupper( $code ) ? 403 : 409;
		return new WP_Error( 'cvd_incident_rejected', 'No se pudo actualizar la incidencia.', array( 'status' => $status, 'transition' => $result ) );
	}

	private static function active( WC_Order $order ): array {
		foreach ( array( 'operation', 'delivery' ) as $domain ) {
			if ( 'yes' !== $order->get_meta( '_cvd_' . $domain . '_incident_active', true ) ) {
				continue;
			}
			$reason = sanitize_key( (string) $order->get_meta( '_cvd_' . $domain . '_incident_reason', true ) );
			return array(
				'active' => true,
				'domain' => $domain,
				'reason' => $reason,
				'label'  => isset( self::REASONS[ $reason ] ) ? self::REASONS[ $reason ]['label'] : 'Incidencia operativa',
				'note'   => (string) $order->get_meta( '_cvd_' . $domain . '_incident_note', true ),
				'at'     => (string) $order->get_meta( '_cvd_' . $domain . '_incident_opened_at', true ),
			);
		}
		return array( 'active' => false, 'domain' => '', 'reason' => '', 'label' => '', 'note' => '', 'at' => '' );
	}

	private static function allowed_reasons( WC_Order $order ): array {
		if ( self::active( $order )['active'] || in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
			return array();
		}
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ?: 'delivery';
		$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ?: 'new';
		$delivery = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned';
		$result = array();
		foreach ( self::REASONS as $reason => $config ) {
			if ( ! empty( $config['fulfillment'] ) && $config['fulfillment'] !== $fulfillment ) {
				continue;
			}
			if ( 'operation' === $config['domain'] && ! in_array( $operation, array( 'new', 'confirmed', 'preparing', 'ready' ), true ) ) {
				continue;
			}
			if ( 'delivery' === $config['domain'] && ! in_array( $delivery, array( 'accepted', 'to_store' ), true ) ) {
				continue;
			}
			$result[ $reason ] = array( 'label' => $config['label'], 'domain' => $config['domain'] );
		}
		return $result;
	}

	private static function record_structured( int $order_id, string $action, string $domain, string $reason, string $note, array $transition ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'open' === $action ) {
			$order->update_meta_data( '_cvd_' . $domain . '_incident_reason', $reason );
		}
		$history = $order->get_meta( self::HISTORY_META, true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array(
			'action'        => $action,
			'domain'        => $domain,
			'reason'        => $reason,
			'label'         => isset( self::REASONS[ $reason ] ) ? self::REASONS[ $reason ]['label'] : '',
			'note'          => $note,
			'actor_user_id' => get_current_user_id(),
			'at'            => current_time( 'mysql', true ),
			'event_id'      => sanitize_text_field( (string) ( $transition['event_id'] ?? '' ) ),
		);
		$order->update_meta_data( self::HISTORY_META, array_slice( $history, -100 ) );
		$order->save();
	}

	private static function project( WC_Order $order ): array {
		$active = self::active( $order );
		$history = $order->get_meta( self::HISTORY_META, true );
		return array(
			'active'         => $active,
			'allowedReasons' => self::allowed_reasons( $order ),
			'historyCount'   => is_array( $history ) ? count( $history ) : 0,
		);
	}

	public static function assets(): void {
		if ( ! is_page( 'centro-pedido' ) || ! self::can_manage() ) {
			return;
		}
		wp_enqueue_script( 'cvd-structured-incidents', CVD_URL . 'assets/structured-incidents.js', array( 'cvd-order-center' ), CVD_VERSION, true );
		wp_localize_script(
			'cvd-structured-incidents',
			'cvdStructuredIncidents',
			array(
				'url'   => rest_url( 'casa-viva/v1/structured-incidents/' ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
