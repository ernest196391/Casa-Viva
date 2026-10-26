<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.69.1 (Ernesto 25-oct: «quiero que la app pueda avisar como notificación a los mensajeros»): avisos push de Pa'llá
 * al teléfono del mensajero aunque tenga la app cerrada. Usa el web push de Core (CVD_Web_Push: claves VAPID, tabla
 * cvd_push_subscriptions, aviso sin datos y el teléfono pide el último con su cookie), con los teléfonos de Pa'llá marcados
 * aparte: solo reciben avisos de Pa'llá, que abren la app en la carrera.
 *
 * Un solo aviso (Ernesto eligió solo este, 25-oct): «🛵 Carrera libre #N» cada vez que se oferta una carrera sin mensajero,
 * a todos los de Pa'llá con avisos, menos al que la soltó. Sin recordatorios repetidos (uno por cada vez que se oferta).
 * Interruptor `cvd_palla_push`: no | prueba (solo `cvd_palla_push_testers`, por defecto Daiquel 59 y la cuenta de prueba 161)
 * | si (todo el que entra en Pa'llá). Siempre además CVD_Palla::blocked_reason() vacío (respeta `cvd_palla`).
 */
final class CVD_Palla_Push {
	public const OPTION = 'cvd_palla_push';
	public const TESTERS = 'cvd_palla_push_testers';
	private const NS = 'casa-viva/v1';
	private const TTL = 604800; // 7 días: lo último avisado de cada pedido
	private static bool $busy = false;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'on_save' ), 30, 1 );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Palla_Api', 'permission' );
		register_rest_route( self::NS, '/palla/push', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'subscribe' ), 'permission_callback' => $auth ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'unsubscribe' ), 'permission_callback' => $auth ),
		) );
	}

	public static function mode(): string {
		$mode = sanitize_key( (string) get_option( self::OPTION, 'prueba' ) );
		return in_array( $mode, array( 'no', 'prueba', 'si' ), true ) ? $mode : 'prueba';
	}

	/** @return int[] */
	public static function testers(): array {
		$raw = get_option( self::TESTERS, array( 59, 161 ) );
		return array_values( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw ) ) ) );
	}

	public static function allowed( WP_User $user ): bool {
		$mode = self::mode();
		if ( 'no' === $mode || '' !== CVD_Palla::blocked_reason( $user ) || '' === CVD_Web_Push::public_key() ) { return false; }
		return 'si' === $mode || in_array( $user->ID, self::testers(), true );
	}

	/** Lo que la app necesita para el botón «Activar avisos». */
	public static function status_for( WP_User $user ): array {
		return array( 'allowed' => self::allowed( $user ), 'key' => self::allowed( $user ) ? CVD_Web_Push::public_key() : '' );
	}

	// ------------------------------------------------------------------ Alta del teléfono

	/** POST palla/push {endpoint}: guarda el teléfono como «de Pa'llá» y deja la cookie con la que pide el último aviso. */
	public static function subscribe( WP_REST_Request $request ) {
		global $wpdb;
		$me = CVD_Palla::request_user( $request );
		if ( ! self::allowed( $me ) ) { return self::out( array( 'subscribed' => false, 'result' => 'not_allowed' ) ); }
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ) );
		if ( ! $endpoint || 0 !== strpos( $endpoint, 'https://' ) || strlen( $endpoint ) > 1000 ) { return new WP_Error( 'cvd_bad_subscription', 'Suscripción no válida.', array( 'status' => 422 ) ); }
		$now = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 32 ) );
		$ua = CVD_Web_Push::PALLA_DEVICE . sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		$ok = $wpdb->replace( CVD_Web_Push::subscriptions_table(), array(
			'endpoint_hash' => hash( 'sha256', $endpoint ), 'device_token' => $token, 'user_id' => $me->ID, 'endpoint' => $endpoint,
			'user_agent' => function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 255 ) : substr( $ua, 0, 255 ),
			'created_at' => $now, 'updated_at' => $now, 'last_success_at' => null, 'failure_count' => 0,
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ) );
		if ( false === $ok ) { return new WP_Error( 'cvd_subscription_storage', 'No se pudo guardar el teléfono.', array( 'status' => 500 ) ); }
		setcookie( 'cvd_push_device', $token, array( 'expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
		return self::out( array( 'subscribed' => true ) );
	}

	public static function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$me = CVD_Palla::request_user( $request );
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ) );
		$wpdb->delete( CVD_Web_Push::subscriptions_table(), array( 'endpoint_hash' => hash( 'sha256', $endpoint ), 'user_id' => $me->ID ), array( '%s', '%d' ) );
		return self::out( array( 'subscribed' => false ) );
	}

	private static function out( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// ------------------------------------------------------------------ Los avisos

	/** Mensajeros con un teléfono de Pa'llá dado de alta y con permiso de avisos. */
	private static function subscribers(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . CVD_Web_Push::subscriptions_table() . ' WHERE failure_count<5 AND user_agent LIKE %s', $wpdb->esc_like( CVD_Web_Push::PALLA_DEVICE ) . '%' ) );
		$out = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$user = get_userdata( $id );
			if ( $user && self::allowed( $user ) ) { $out[] = $user; }
		}
		return $out;
	}

	private static function send( int $user_id, WC_Order $order, string $type, string $title, string $message, string $hash ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::allowed( $user ) ) { return; }
		CVD_Web_Push::notify_palla( $user_id, $order->get_id(), $type, $title, $message, home_url( '/palla/' ) . $hash );
	}

	public static function on_save( $order ): void {
		if ( self::$busy || 'no' === self::mode() || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 'shop_order' !== $order->get_type() ) { return; }
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) || CVD_Tarjeta_Carrera::is_test( $order ) ) { return; }
		self::$busy = true;
		try { self::check( $order ); } catch ( Throwable $e ) { error_log( 'Pa\'llá avisos: ' . $e->getMessage() ); } finally { self::$busy = false; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/** Carrera libre: cada vez que se oferta (de nuevo) sin mensajero, un aviso a cada uno (menos al que la soltó). */
	private static function check( WC_Order $order ): void {
		if ( 'offered' !== CVD_Delivery::status( $order ) || absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) || '' !== CVD_Delivery::blocks_dispatch( $order ) ) { return; }
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return; }
		$id = $order->get_id();
		$at = (string) $order->get_meta( '_cvd_delivery_offered_at', true ); // cambia cada vez que se vuelve a ofertar
		if ( '' === $at || get_transient( 'cvd_palla_offer_' . $id ) === $at ) { return; }
		set_transient( 'cvd_palla_offer_' . $id, $at, self::TTL );
		$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ?: (int) round( CVD_Delivery::courier_amount( $order ) );
		$body = CVD_Delivery::destination_zone( $order ) . ( $fee > 0 ? ' · ' . CVD_Messenger_Delivered_Link::money( (float) $fee, 'CUP' ) : '' ) . ' · toca para verla y cogerla';
		$skip = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) );
		foreach ( self::subscribers() as $user ) {
			if ( in_array( $user->ID, $skip, true ) ) { continue; }
			self::send( $user->ID, $order, 'offer', '🛵 Carrera libre #' . $order->get_order_number(), $body, '#carreras' );
		}
	}

	/** Prueba a mano (wp eval 'CVD_Palla_Push::test( 161 );'): un aviso al teléfono de ese mensajero. */
	public static function test( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::allowed( $user ) ) { return 'no puede recibir avisos (cvd_palla_push o cvd_palla)'; }
		CVD_Web_Push::notify_palla( $user_id, 0, 'test', '🔔 Prueba de Pa\'llá', 'Si ves esto, los avisos funcionan. Toca para abrir la app.', home_url( '/palla/#carreras' ) );
		return 'enviado';
	}
}
