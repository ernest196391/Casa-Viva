<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.69.1 (Ernesto 25-oct: «quiero que la app pueda avisar como notificación a los mensajeros»): avisos push de Pa'llá
 * al teléfono del mensajero aunque tenga la app cerrada. Usa el web push de Core (CVD_Web_Push: claves VAPID, tabla
 * cvd_push_subscriptions, aviso sin datos y el teléfono pide el último con su cookie), con los teléfonos de Pa'llá marcados
 * aparte: solo reciben avisos de Pa'llá, que abren la app en la carrera.
 *
 * Avisos (uno por cosa, sin recordatorios repetidos; se detectan al guardar el pedido):
 *   1. offer     — sale una carrera libre (a todos los de Pa'llá con avisos, menos al que la soltó).
 *   2. assigned  — te asignaron una carrera (asignación directa o un pase que aceptaste no cuenta: solo si lo hizo otro).
 *   3. cancelled — tu carrera se canceló · lost — ya no está a tu nombre · changed — cambió la dirección, los productos o el día.
 * Interruptor `cvd_palla_push`: no | prueba (solo `cvd_palla_push_testers`, por defecto Daiquel 59 y la cuenta de prueba 161)
 * | si (todo el que entra en Pa'llá). Siempre además CVD_Palla::blocked_reason() vacío (respeta `cvd_palla`).
 * 3.14.69.3 (Ernesto 25-oct eligió solo «Carrera libre»): un interruptor por tipo, `cvd_palla_push_tipos` (lista de
 * offer, assigned, cancelled, lost, changed); por defecto solo `offer`. Los apagados se siguen vigilando sin enviar («changed»
 * empieza a contar al encenderlo), así al encenderlos no salen avisos viejos. Encender uno: wp option update cvd_palla_push_tipos "offer,assigned".
 */
final class CVD_Palla_Push {
	public const OPTION = 'cvd_palla_push';
	public const TESTERS = 'cvd_palla_push_testers';
	public const TYPES = 'cvd_palla_push_tipos';
	public const NTFY_TOPIC = '_cvd_palla_ntfy_topic';
	private const ALL_TYPES = array( 'offer', 'assigned', 'cancelled', 'lost', 'changed' );
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
		// 3.14.69.18: la app instalable de Pa'llá (Android) pide aquí su tema de avisos de ntfy (sin Google).
		register_rest_route( self::NS, '/palla/app', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'app_config' ), 'permission_callback' => $auth ) );
		// 3.14.69.4: botón «Probar desde la tienda» en Ayuda (una vez cada 30 s).
		register_rest_route( self::NS, '/palla/push/prueba', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'self_test' ), 'permission_callback' => $auth ) );
	}

	/**
	 * GET palla/app → { ok, url, topic } para la app de Android: se conecta a NUESTRO ntfy (VPS) y avisa con la app cerrada.
	 * Mismo permiso que los avisos (cvd_palla_push: prueba = probadores). El tema se crea la primera vez (secreto; en ntfy
	 * «palla-*» es de solo lectura para todos y solo publica Core).
	 */
	public static function app_config( WP_REST_Request $request ): WP_REST_Response {
		$me = CVD_Palla::request_user( $request );
		if ( ! self::allowed( $me ) ) { return self::out( array( 'ok' => false, 'result' => 'not_allowed' ) ); }
		$url = (string) get_option( 'cvd_palla_ntfy_url', '' );
		if ( 0 !== strpos( $url, 'https://' ) ) { return self::out( array( 'ok' => false, 'result' => 'no_server' ) ); }
		$topic = (string) get_user_meta( $me->ID, self::NTFY_TOPIC, true );
		if ( '' === $topic ) {
			$topic = 'palla-' . $me->ID . '-' . bin2hex( random_bytes( 8 ) );
			update_user_meta( $me->ID, self::NTFY_TOPIC, $topic );
		}
		return self::out( array( 'ok' => true, 'url' => untrailingslashit( $url ), 'topic' => $topic ) );
	}

	public static function self_test( WP_REST_Request $request ): WP_REST_Response {
		$me = CVD_Palla::request_user( $request );
		if ( get_transient( 'cvd_palla_push_prueba_' . $me->ID ) ) { return self::out( array( 'sent' => false, 'message' => 'Ya te mandamos uno hace un momento. Espera medio minuto.' ) ); }
		set_transient( 'cvd_palla_push_prueba_' . $me->ID, 1, 30 );
		$result = self::test( $me->ID );
		return self::out( 'enviado' === $result ? array( 'sent' => true ) : array( 'sent' => false, 'message' => 'Los avisos no están activados para ti todavía.' ) );
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

	/** Tipos de aviso encendidos (por defecto solo «Carrera libre»). @return string[] */
	public static function types(): array {
		$raw = get_option( self::TYPES, array( 'offer' ) );
		$list = array_map( 'sanitize_key', is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw ) );
		return array_values( array_intersect( self::ALL_TYPES, $list ) );
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
		$ids = array_merge( (array) $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value<>''", self::NTFY_TOPIC ) ) );
		$out = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$user = get_userdata( $id );
			if ( $user && self::allowed( $user ) ) { $out[] = $user; }
		}
		return $out;
	}

	private static function send( int $user_id, WC_Order $order, string $type, string $title, string $message, string $hash ): void {
		if ( ! in_array( $type, self::types(), true ) ) { return; }
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::allowed( $user ) ) { return; }
		CVD_Web_Push::notify_palla( $user_id, $order->get_id(), $type, $title, $message, home_url( '/palla/' ) . $hash );
		self::ntfy( $user_id, $title, $message, home_url( '/palla/' ) . $hash, array( $type, 'o' . $order->get_id() ) );
	}

	/**
	 * 3.14.82 (Ernesto 29-oct): alguien cogió una carrera libre → a los demás teléfonos les llega «cerrada» (sin aviso a la
	 * vista) y la app 1.2 calla la bocina de esa carrera si todavía está sonando.
	 */
	private static function closed_for_others( WC_Order $order, int $owner ): void {
		if ( ! in_array( 'offer', self::types(), true ) ) { return; }
		foreach ( self::subscribers() as $user ) {
			if ( $user->ID !== $owner ) { self::ntfy( $user->ID, '#' . $order->get_order_number() . ' ya tiene mensajero', 'La cogió otro compañero.', home_url( '/palla/#carreras' ), array( 'cerrada', 'o' . $order->get_id() ) ); }
		}
	}

	/**
	 * 3.14.69.7 (Ernesto 26-oct: «Mandao, La Nave… funcionan sin VPN»): el mismo aviso por nuestro ntfy del VPS, que no
	 * pasa por Google. Solo a quien tiene tema (user meta `_cvd_palla_ntfy_topic`, secreto y de solo lectura en ntfy);
	 * publica el usuario `core` con su token (`cvd_palla_ntfy_token`). Sin esperar respuesta: no frena el guardado.
	 */
	private static function ntfy( int $user_id, string $title, string $message, string $click, array $tags = array() ): bool {
		$topic = (string) get_user_meta( $user_id, self::NTFY_TOPIC, true );
		$url = (string) get_option( 'cvd_palla_ntfy_url', '' );
		$token = (string) get_option( 'cvd_palla_ntfy_token', '' );
		if ( '' === $topic || 0 !== strpos( $url, 'https://' ) || '' === $token ) { return false; }
		wp_remote_post( $url, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'topic' => $topic, 'title' => $title, 'message' => $message, 'click' => $click, 'priority' => 5, 'tags' => array_merge( array( 'motor_scooter' ), $tags ) ) ), // 3.14.82: la app 1.2 lee el tipo y la carrera («o<id>»)
		) );
		return true;
	}

	/** Dirección, productos, total y día: si cambian con la carrera en curso, se avisa al mensajero. */
	private static function fingerprint( WC_Order $order ): string {
		$items = class_exists( 'CVD_Order_Lines' ) ? CVD_Order_Lines::texts( $order ) : array();
		return md5( wp_json_encode( array( $order->get_shipping_address_1() ?: $order->get_billing_address_1(), $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
			(string) $order->get_meta( '_cvd_locality', true ), (string) $order->get_total(), $items, (string) $order->get_meta( '_cvd_delivery_date', true ), (string) $order->get_meta( '_cvd_delivery_window', true ) ) ) );
	}

	public static function on_save( $order ): void {
		if ( self::$busy || 'no' === self::mode() || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 'shop_order' !== $order->get_type() ) { return; }
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) || CVD_Tarjeta_Carrera::is_test( $order ) ) { return; }
		if ( '' !== (string) $order->get_meta( '_cvd_ensayo', true ) ) { return; } // 3.14.82: la carrera de ensayo nunca avisa a nadie (29-oct salió como libre)
		self::$busy = true;
		try { self::check( $order ); } catch ( Throwable $e ) { error_log( 'Pa\'llá avisos: ' . $e->getMessage() ); } finally { self::$busy = false; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function check( WC_Order $order ): void {
		$id = $order->get_id();
		$n = (string) $order->get_order_number();
		$status = CVD_Delivery::status( $order );
		$owner = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$actor = get_current_user_id();
		$zone = CVD_Delivery::destination_zone( $order );
		$closed = 'cancelled' === $status || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true );
		$prev = get_transient( 'cvd_palla_owner_' . $id ); // false = no lo habíamos visto

		// 1. Carrera libre (cada vez que se oferta de nuevo: _cvd_delivery_offered_at cambia).
		if ( ! $closed && 'offered' === $status && ! $owner && '' === CVD_Delivery::blocks_dispatch( $order ) ) {
			// Volvió a quedar libre: si la tenía alguien y no la soltó él, ya no es suya.
			$had = absint( $prev );
			$skip = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) );
			if ( $had && $actor !== $had && ! in_array( $had, $skip, true ) ) {
				self::send( $had, $order, 'lost', '#' . $n . ' ya no está a tu nombre', 'La tienda la volvió a dejar libre. Si tienes el paquete, llama a la tienda.', '#carreras' );
			}
			set_transient( 'cvd_palla_owner_' . $id, 0, self::TTL );
			$at = (string) $order->get_meta( '_cvd_delivery_offered_at', true );
			if ( '' !== $at && get_transient( 'cvd_palla_offer_' . $id ) !== $at ) {
				set_transient( 'cvd_palla_offer_' . $id, $at, self::TTL );
				$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ?: (int) round( CVD_Delivery::courier_amount( $order ) );
				foreach ( self::subscribers() as $user ) {
					if ( in_array( $user->ID, $skip, true ) ) { continue; }
					if ( class_exists( 'CVD_Palla_Disponible' ) && ! CVD_Palla_Disponible::wants_offer( $user->ID, $order ) ) { continue; } // 3.14.83: solo a los disponibles
					self::send( $user->ID, $order, 'offer', '🛵 Carrera libre #' . $n, $zone . ( $fee > 0 ? ' · ' . CVD_Messenger_Delivered_Link::money( (float) $fee, 'CUP' ) : '' ) . ' · toca para verla y cogerla', '#carreras' );
				}
			}
			return;
		}

		// 3a. Cancelada con mensajero.
		$who = $owner ?: absint( $prev );
		if ( $closed ) {
			if ( $who && ! get_transient( 'cvd_palla_cx_' . $id ) ) {
				set_transient( 'cvd_palla_cx_' . $id, 1, self::TTL );
				self::send( $who, $order, 'cancelled', '❌ #' . $n . ' se canceló', 'No la lleves. Si ya tienes el paquete, devuélvelo a la tienda.', '#carreras' );
			}
			delete_transient( 'cvd_palla_owner_' . $id );
			return;
		}
		if ( ! in_array( $status, array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'incident' ), true ) && ! ( false !== $prev && absint( $prev ) && ! $owner ) ) { return; }

		// 2 y 3b. Cambio de dueño.
		if ( false === $prev ) {
			// Primera vez que lo vemos: solo cuenta como «te la asignaron» si es una asignación directa de hace poco.
			$assigned_at = (string) $order->get_meta( '_cvd_direct_assigned_at', true );
			$prev = ( $owner && 'assigned' === $status && '' !== $assigned_at && strtotime( $assigned_at . ' UTC' ) > time() - 600 ) ? 0 : $owner;
		}
		$prev = absint( $prev );
		if ( $owner !== $prev ) {
			if ( $owner && ! $prev && false !== get_transient( 'cvd_palla_offer_' . $id ) ) { self::closed_for_others( $order, $owner ); } // estaba libre y se ofertó
			set_transient( 'cvd_palla_owner_' . $id, $owner, self::TTL );
			delete_transient( 'cvd_palla_h_' . $id );
			$paso = json_decode( (string) $order->get_meta( '_cvd_palla_paso', true ), true );
			$passed = is_array( $paso ) && absint( $paso['to'] ?? 0 ) === $owner && absint( $paso['from'] ?? 0 ) === $prev;
			if ( $owner && $actor !== $owner && 'assigned' === $status ) {
				self::send( $owner, $order, 'assigned', '📦 Te asignaron #' . $n, $zone . ' · pon la hora y ve a recogerla', '#hora/' . $id );
			}
			if ( $prev && $actor !== $prev ) {
				$released = in_array( $prev, array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) ), true );
				if ( $passed ) {
					$to = get_userdata( $owner );
					self::send( $prev, $order, 'lost', '✅ ' . ( $to ? strtok( (string) $to->display_name, ' ' ) : 'Tu compañero' ) . ' aceptó #' . $n, 'Ya no es tuya. ¡Gracias por pasarla!', '#carreras' );
				} elseif ( ! $released ) {
					self::send( $prev, $order, 'lost', '#' . $n . ' ya no está a tu nombre', 'La tienda se la pasó a otro mensajero o la quitó. Si tienes el paquete, llama a la tienda.', '#carreras' );
				}
			}
		}

		// 3c. Cambió la dirección, los productos o el día con la carrera en curso.
		if ( $owner && in_array( 'changed', self::types(), true ) ) {
			$fp = self::fingerprint( $order );
			$old = get_transient( 'cvd_palla_h_' . $id );
			set_transient( 'cvd_palla_h_' . $id, $fp, self::TTL );
			if ( false !== $old && $old !== $fp && $actor !== $owner ) {
				self::send( $owner, $order, 'changed', '✏️ #' . $n . ' cambió', 'Revisa la dirección, los productos o el día antes de salir.', '#carrera/' . $id );
			}
		}
	}

	/** Prueba a mano (wp eval 'CVD_Palla_Push::test( 161 );'): un aviso al teléfono de ese mensajero. */
	public static function test( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::allowed( $user ) ) { return 'no puede recibir avisos (cvd_palla_push o cvd_palla)'; }
		CVD_Web_Push::notify_palla( $user_id, 0, 'test', '🔔 Prueba de Pa\'llá', 'Si ves esto, los avisos funcionan. Toca para abrir la app.', home_url( '/palla/#carreras' ) );
		self::ntfy( $user_id, '🔔 Prueba de Pa\'llá', 'Si ves esto, los avisos funcionan sin Google. Toca para abrir la app.', home_url( '/palla/#carreras' ), array( 'prueba' ) );
		return 'enviado';
	}

	/** Enlace para suscribirse en la app oficial de ntfy (F-Droid/GitHub, sin Google): '' si ese mensajero no tiene tema. */
	public static function ntfy_link( int $user_id ): string {
		$topic = (string) get_user_meta( $user_id, self::NTFY_TOPIC, true );
		$host = (string) wp_parse_url( (string) get_option( 'cvd_palla_ntfy_url', '' ), PHP_URL_HOST );
		return ( '' !== $topic && '' !== $host ) ? 'ntfy://' . $host . '/' . $topic : '';
	}
}
