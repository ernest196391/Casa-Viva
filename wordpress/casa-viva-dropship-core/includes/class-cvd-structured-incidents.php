<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Structured_Incidents {
	private const HISTORY_META = '_cvd_structured_incident_history';
	private const REASONS = array(
		'missing_product' => array( 'label' => 'Falta de producto', 'domain' => 'operation' ),
		'preparation_error' => array( 'label' => 'Preparación incorrecta', 'domain' => 'operation' ),
		'customer_no_show' => array( 'label' => 'Cliente no recoge', 'domain' => 'operation', 'fulfillment' => 'pickup' ),
		'messenger_no_show' => array( 'label' => 'Mensajero no recoge', 'domain' => 'delivery', 'fulfillment' => 'delivery' ),
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
	}
	public static function can_manage(): bool { return current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' ); }
	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/structured-incidents/(?P<id>\\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'act' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
	}
	public static function get( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order ? rest_ensure_response( self::project( $order ) ) : new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}

	public static function act( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$note = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$key = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotency_key' ) ) );
		if ( ! in_array( $action, array( 'open', 'resolve' ), true ) ) { return new WP_Error( 'cvd_incident_action_invalid', 'Acción de incidencia no válida.', array( 'status' => 400 ) ); }
		if ( '' === trim( $note ) ) { return new WP_Error( 'cvd_incident_note_required', 'Describe brevemente lo ocurrido.', array( 'status' => 400 ) ); }

		if ( 'open' === $action ) {
			$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
			$config = self::REASONS[ $reason ] ?? null;
			$active = self::active( $order );
			if ( $active['active'] ) {
				if ( ! $config || $active['reason'] !== $reason || $active['domain'] !== $config['domain'] ) {
					return new WP_Error( 'cvd_incident_already_active', 'El pedido ya tiene otra incidencia activa.', array( 'status' => 409 ) );
				}
			} elseif ( ! $config || ! isset( self::allowed_reasons( $order )[ $reason ] ) ) {
				return new WP_Error( 'cvd_incident_reason_invalid', 'Este motivo no corresponde a la etapa actual del pedido.', array( 'status' => 409 ) );
			}
			$domain = $config['domain'];
			$result = CVD_Order_Transition_Service::open_incident( $order->get_id(), $domain, array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $key ?: 'structured-incident:' . $order->get_id() . ':open:' . $reason . ':' . wp_generate_uuid4(),
				'note' => $config['label'] . ' · ' . $note,
			) );
			if ( empty( $result['success'] ) ) { return self::transition_error( $result ); }
			self::record_structured( $order->get_id(), 'open', $domain, $reason, $note, $result );
		} else {
			$active = self::active( $order );
			$domain = $active['domain'];
			$reason = $active['reason'];
			$replay_only = false;
			if ( ! $active['active'] || ! $domain ) {
				$latest = self::latest_structured( $order );
				if ( ! $key || ! $latest || 'resolve' !== (string) ( $latest['action'] ?? '' ) ) {
					return new WP_Error( 'cvd_incident_not_active', 'El pedido no tiene una incidencia activa.', array( 'status' => 409 ) );
				}
				$domain = sanitize_key( (string) ( $latest['domain'] ?? '' ) );
				$reason = sanitize_key( (string) ( $latest['reason'] ?? '' ) );
				$replay_only = true;
			}
			$result = CVD_Order_Transition_Service::resolve_incident( $order->get_id(), $domain, array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $key ?: 'structured-incident:' . $order->get_id() . ':resolve:' . wp_generate_uuid4(),
				'note' => $note,
			) );
			if ( empty( $result['success'] ) ) { return self::transition_error( $result ); }
			if ( $replay_only && empty( $result['idempotent_replay'] ) ) {
				return new WP_Error( 'cvd_incident_not_active', 'El pedido no tiene una incidencia activa.', array( 'status' => 409 ) );
			}
			self::record_structured( $order->get_id(), 'resolve', $domain, $reason, $note, $result );
		}
		return rest_ensure_response( array( 'transition' => $result, 'incident' => self::project( wc_get_order( $order->get_id() ) ) ) );
	}

	private static function transition_error( array $result ): WP_Error {
		$code = sanitize_key( (string) ( $result['error_code'] ?? '' ) );
		return new WP_Error( 'cvd_incident_rejected', 'No se pudo actualizar la incidencia.', array( 'status' => 'UNAUTHORIZED' === strtoupper( $code ) ? 403 : 409, 'transition' => $result ) );
	}
	private static function active( WC_Order $order ): array {
		foreach ( array( 'operation', 'delivery' ) as $domain ) {
			if ( 'yes' !== $order->get_meta( '_cvd_' . $domain . '_incident_active', true ) ) { continue; }
			$reason = sanitize_key( (string) $order->get_meta( '_cvd_' . $domain . '_incident_reason', true ) );
			return array( 'active'=>true, 'domain'=>$domain, 'reason'=>$reason, 'label'=>isset(self::REASONS[$reason])?self::REASONS[$reason]['label']:'Incidencia operativa', 'note'=>(string)$order->get_meta('_cvd_'.$domain.'_incident_note',true), 'at'=>(string)$order->get_meta('_cvd_'.$domain.'_incident_opened_at',true) );
		}
		return array( 'active'=>false, 'domain'=>'', 'reason'=>'', 'label'=>'', 'note'=>'', 'at'=>'' );
	}
	private static function latest_structured( WC_Order $order ): array {
		$history = $order->get_meta( self::HISTORY_META, true );
		if ( ! is_array( $history ) || ! $history ) { return array(); }
		$latest = end( $history );
		return is_array( $latest ) ? $latest : array();
	}
	private static function allowed_reasons( WC_Order $order ): array {
		if ( self::active( $order )['active'] || in_array( $order->get_status(), array( 'completed','cancelled','refunded','failed' ), true ) ) { return array(); }
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ?: 'delivery';
		$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ?: 'new';
		$delivery = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned';
		$result = array();
		foreach ( self::REASONS as $reason => $config ) {
			if ( ! empty( $config['fulfillment'] ) && $config['fulfillment'] !== $fulfillment ) { continue; }
			if ( 'operation' === $config['domain'] && ! in_array( $operation, array( 'new','confirmed','preparing','ready' ), true ) ) { continue; }
			if ( 'delivery' === $config['domain'] && ! in_array( $delivery, array( 'accepted','to_store' ), true ) ) { continue; }
			$result[$reason] = array( 'label'=>$config['label'], 'domain'=>$config['domain'] );
		}
		return $result;
	}
	private static function record_structured( int $order_id, string $action, string $domain, string $reason, string $note, array $transition ): void {
		$event_id = sanitize_text_field( (string) ( $transition['event_id'] ?? '' ) );
		if ( ! empty( $transition['idempotent_replay'] ) || '' === $event_id ) { return; }
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$history = $order->get_meta( self::HISTORY_META, true ); $history = is_array( $history ) ? $history : array();
		foreach ( $history as $entry ) { if ( is_array( $entry ) && $event_id === (string) ( $entry['event_id'] ?? '' ) ) { return; } }
		if ( 'open' === $action ) { $order->update_meta_data( '_cvd_' . $domain . '_incident_reason', $reason ); }
		$history[] = array( 'action'=>$action, 'domain'=>$domain, 'reason'=>$reason, 'label'=>isset(self::REASONS[$reason])?self::REASONS[$reason]['label']:'', 'note'=>$note, 'actor_user_id'=>get_current_user_id(), 'at'=>current_time('mysql',true), 'event_id'=>$event_id );
		$order->update_meta_data( self::HISTORY_META, array_slice( $history, -100 ) ); $order->save();
	}
	private static function project( WC_Order $order ): array {
		$history = $order->get_meta( self::HISTORY_META, true );
		return array( 'active'=>self::active($order), 'allowedReasons'=>self::allowed_reasons($order), 'historyCount'=>is_array($history)?count($history):0 );
	}
	public static function assets(): void {
		if ( ! is_page( 'centro-pedido' ) || ! self::can_manage() ) { return; }
		wp_enqueue_script( 'cvd-structured-incidents', CVD_URL . 'assets/structured-incidents.js', array( 'cvd-order-center' ), CVD_VERSION, true );
		wp_localize_script( 'cvd-structured-incidents', 'cvdStructuredIncidents', array( 'url'=>rest_url('casa-viva/v1/structured-incidents/'), 'nonce'=>wp_create_nonce('wp_rest') ) );
	}
}
