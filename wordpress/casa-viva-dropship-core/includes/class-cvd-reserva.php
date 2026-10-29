<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reserva de una recogida (Core 3.14.72, Pilar 3 del plan «menos errores», Ernesto 28-oct).
 *
 * Caso real: el bot le dijo a Kay que el #5551 se guardaba hasta mañana; la tienda no lo sabía y Nana lo canceló
 * («cliente no pasó») → Kay perdió la comisión (error #118). Una promesa de guardar un pedido no vive en un mensaje:
 * vive DENTRO del pedido, la ve la dependienta y frena la cancelación.
 *
 * Campos del pedido:
 *  _cvd_reserva_hasta        Y-m-d (hora de la web); vale hasta las 23:59 de ese día. Máximo MAX_DAYS días.
 *  _cvd_reserva_por          quién la pidió («Kay»).
 *  _cvd_reserva_nota         nota corta opcional.
 *  _cvd_reserva_via          bot | ventas | panel (quién la registró).
 *  _cvd_reserva_registrada   {nombre, fecha UTC} de quien la apuntó.
 *  _cvd_reserva_avisada      fecha UTC en que el bot avisó de que venció (una sola vez).
 *
 * Quién la pone/quita: el bot (POST /bot/orders/{id}/reserva, con la clave de VivaBot), la tienda (POST /sales/{id}/reserva)
 * y el panel (POST /panel/orders/{id}/reserva). Solo recogidas abiertas.
 * Cancelar con reserva vigente: dependienta = bloqueado; administración = pide confirmar (confirmReserva); el bot por la
 * API de WooCommerce = bloqueado (409 cvd_reserva_vigente). Al vencer, el bot avisa UNA vez (GET /bot/reservas/vencidas).
 */
final class CVD_Reserva {
	public const UNTIL = '_cvd_reserva_hasta';
	public const BY = '_cvd_reserva_por';
	public const NOTE = '_cvd_reserva_nota';
	public const VIA = '_cvd_reserva_via';
	public const LOGGED = '_cvd_reserva_registrada';
	public const NOTIFIED = '_cvd_reserva_avisada';
	public const MAX_DAYS = 7;
	private const NS = 'casa-viva/v1';
	private const OPEN = array( 'pending', 'processing', 'on-hold' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// El bot cancela las recogidas viejas por la API de WooCommerce: con reserva vigente, no.
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( __CLASS__, 'guard_rest_cancel' ), 10, 3 );
		// Cancelar a mano en el administrador de WooCommerce no se puede frenar: al menos queda escrito.
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'note_admin_cancel' ), 4 );
	}

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		$panel = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/reserva', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_get' ), 'permission_callback' => $bot ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_set' ), 'permission_callback' => $bot ),
		) );
		register_rest_route( self::NS, '/bot/reservas/vencidas', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_expired' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/reservas/(?P<id>\d+)/avisada', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_notified' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/sales/(?P<id>\d+)/reserva', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sales_set' ), 'permission_callback' => array( 'CVD_Order_Edit', 'can_manage' ) ) );
		register_rest_route( self::NS, '/panel/orders/(?P<id>\d+)/reserva', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'panel_set' ), 'permission_callback' => $panel ) );
	}

	// ---------- Leer ----------

	private static function today(): string {
		return class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::min_date() : wp_date( 'Y-m-d' );
	}

	private static function is_open( WC_Order $order ): bool {
		if ( ! $order->has_status( self::OPEN ) ) { return false; }
		return ! in_array( sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ), array( 'delivered', 'cancelled' ), true );
	}

	/** La reserva del pedido o null. active = vigente hoy y el pedido sigue abierto; expired = ya venció y el pedido sigue abierto. */
	public static function get( WC_Order $order ): ?array {
		$until = trim( (string) $order->get_meta( self::UNTIL, true ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) { return null; }
		$open = self::is_open( $order );
		$active = $open && $until >= self::today();
		$parts = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::parts( $until, '' ) : array( 'day' => $until );
		$by = trim( (string) $order->get_meta( self::BY, true ) );
		$logged = $order->get_meta( self::LOGGED, true );
		return array(
			'until' => $until, 'untilLabel' => (string) $parts['day'], 'by' => $by, 'note' => trim( (string) $order->get_meta( self::NOTE, true ) ),
			'via' => sanitize_key( (string) $order->get_meta( self::VIA, true ) ), 'loggedBy' => is_array( $logged ) ? (string) ( $logged['name'] ?? '' ) : '',
			'active' => $active, 'expired' => $open && ! $active, 'notified' => '' !== (string) $order->get_meta( self::NOTIFIED, true ),
			'text' => self::banner( $by, (string) $parts['day'], $active ),
		);
	}

	/** «Kay pidió guardarlo hasta mañana jue 29/10» (vigente) o «La reserva de Kay venció (…)». */
	private static function banner( string $by, string $day, bool $active ): string {
		if ( $active ) { return ( '' !== $by ? $by . ' pidió guardarlo' : 'Este pedido está reservado' ) . ' hasta ' . $day; }
		return 'La reserva' . ( '' !== $by ? ' de ' . $by : '' ) . ' venció (' . $day . ')';
	}

	public static function active( WC_Order $order ): ?array {
		$r = self::get( $order );
		return $r && $r['active'] ? $r : null;
	}

	// ---------- Escribir ----------

	/**
	 * Pone, cambia o quita la reserva. $data: until (texto de fecha o Y-m-d), by, note, clear (bool).
	 * $via: bot | ventas | panel. $who: nombre de quien lo apunta. Devuelve array( ok, message, reserva ) o WP_Error.
	 */
	public static function set( int $order_id, array $data, string $via, string $who ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_reserva_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$num = $order->get_order_number();
		$via = in_array( $via, array( 'bot', 'ventas', 'panel' ), true ) ? $via : 'ventas';
		$who = mb_substr( trim( sanitize_text_field( $who ) ), 0, 80 );
		$before = self::get( $order );

		if ( ! empty( $data['clear'] ) ) {
			if ( ! $before ) { return array( 'ok' => true, 'message' => 'El pedido #' . $num . ' no tenía reserva.', 'reserva' => null ); }
			foreach ( array( self::UNTIL, self::BY, self::NOTE, self::VIA, self::LOGGED, self::NOTIFIED ) as $key ) { $order->delete_meta_data( $key ); }
			$order->add_order_note( sprintf( 'Reserva QUITADA (era hasta %s%s). La quitó %s (%s).', $before['untilLabel'], '' !== $before['by'] ? ', pedida por ' . $before['by'] : '', '' !== $who ? $who : 'la tienda', $via ) );
			$order->save();
			return array( 'ok' => true, 'message' => 'Reserva del pedido #' . $num . ' quitada.', 'reserva' => null );
		}

		if ( ! $order->has_status( self::OPEN ) || ! self::is_open( $order ) ) { return new WP_Error( 'cvd_reserva_cerrado', 'Este pedido ya está cerrado o cancelado: no se reserva.', array( 'status' => 409 ) ); }
		if ( 'pickup' !== sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ) {
			return new WP_Error( 'cvd_reserva_no_recogida', 'Solo se reservan los pedidos de recogida en tienda.', array( 'status' => 409 ) );
		}
		$raw = trim( (string) ( $data['until'] ?? '' ) );
		$until = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::normalize( $raw ) : ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '' );
		if ( '' === $until ) { return new WP_Error( 'cvd_reserva_fecha', 'No entendí hasta qué día se guarda. Usa «mañana», «viernes» o una fecha como 29/10.', array( 'status' => 422 ) ); }
		$max = ( new DateTimeImmutable( self::today(), wp_timezone() ) )->modify( '+' . self::MAX_DAYS . ' days' )->format( 'Y-m-d' );
		if ( $until < self::today() || $until > $max ) {
			return new WP_Error( 'cvd_reserva_rango', 'La reserva va de hoy a ' . self::MAX_DAYS . ' días. Para más tiempo, habla con Lennys.', array( 'status' => 422 ) );
		}
		$by = mb_substr( trim( sanitize_text_field( (string) ( $data['by'] ?? '' ) ) ), 0, 60 );
		if ( '' === $by ) { $by = trim( (string) $order->get_meta( 'gestora_nombre', true ) ); }
		$note = mb_substr( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $data['note'] ?? '' ) ) ) ), 0, 160 );

		$order->update_meta_data( self::UNTIL, $until );
		$order->update_meta_data( self::BY, $by );
		$order->update_meta_data( self::NOTE, $note );
		$order->update_meta_data( self::VIA, $via );
		$order->update_meta_data( self::LOGGED, array( 'name' => $who, 'at' => current_time( 'mysql', true ) ) );
		$order->delete_meta_data( self::NOTIFIED ); // una reserva nueva o cambiada vuelve a avisar cuando venza
		$order->save();
		$order = wc_get_order( $order_id );
		$after = self::get( $order );
		if ( ! $after || $after['until'] !== $until ) { return new WP_Error( 'cvd_reserva_guardar', 'No se pudo guardar la reserva. No cambió nada.', array( 'status' => 500 ) ); } // se comprueba lo guardado
		$order->add_order_note( sprintf( 'Reserva %s hasta %s%s%s. La apuntó %s (%s).', $before ? 'CAMBIADA (antes ' . $before['untilLabel'] . ')' : 'PUESTA', $after['untilLabel'], '' !== $by ? ', pedida por ' . $by : '', '' !== $note ? '. Nota: ' . $note : '', '' !== $who ? $who : 'la tienda', $via ) );
		return array( 'ok' => true, 'message' => 'Pedido #' . $num . ': ' . $after['text'] . '.', 'reserva' => $after );
	}

	// ---------- Cancelar ----------

	/** Para CVD_Order_Edit::cancel_block: la dependienta no cancela con reserva vigente ('' = sin freno). */
	public static function clerk_block( WC_Order $order, int $user_id ): string {
		$r = self::active( $order );
		if ( ! $r || user_can( $user_id, 'manage_woocommerce' ) ) { return ''; }
		return $r['text'] . '. No se cancela. Habla con Lennys.';
	}

	/** Para CVD_Order_Edit::cancel: true, o WP_Error (bloqueado, o needsConfirm para administración). */
	public static function cancel_gate( WC_Order $order, int $user_id, bool $confirmed ) {
		$r = self::active( $order );
		if ( ! $r ) { return true; }
		$admin = user_can( $user_id, 'manage_woocommerce' );
		if ( ! $admin ) { return new WP_Error( 'cvd_cancel_reserva', $r['text'] . '. No se cancela. Habla con Lennys.', array( 'status' => 409, 'reserva' => $r ) ); }
		if ( ! $confirmed ) {
			return new WP_Error( 'cvd_cancel_reserva_confirmar', $r['text'] . '. ¿Cancelar igual? Se pierde la comisión de quien lo vendió.', array( 'status' => 409, 'needsConfirm' => true, 'reserva' => $r ) );
		}
		return true;
	}

	/** woocommerce_rest_pre_insert_shop_order_object: PUT wc/v3/orders/{id} con status=cancelled y reserva vigente → 409. */
	public static function guard_rest_cancel( $order, $request, $creating ) {
		if ( $creating || ! $order instanceof WC_Order || ! $order->get_id() ) { return $order; }
		if ( 'cancelled' !== sanitize_key( (string) $request->get_param( 'status' ) ) ) { return $order; }
		$fresh = wc_get_order( $order->get_id() );
		$r = $fresh ? self::active( $fresh ) : null;
		if ( ! $r ) { return $order; }
		return new WP_Error( 'cvd_reserva_vigente', $r['text'] . '. No se cancela mientras dure la reserva.', array( 'status' => 409, 'reserva' => $r ) );
	}

	public static function note_admin_cancel( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$r = $order instanceof WC_Order ? self::get_even_closed( $order ) : null;
		if ( $r && $r['until'] >= self::today() ) { $order->add_order_note( 'OJO: se canceló con la reserva vigente (' . $r['text'] . ').' ); }
	}

	/** Como get() pero sin mirar el estado del pedido (para notas de pedidos ya cancelados). */
	private static function get_even_closed( WC_Order $order ): ?array {
		$until = trim( (string) $order->get_meta( self::UNTIL, true ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) { return null; }
		$parts = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::parts( $until, '' ) : array( 'day' => $until );
		return array( 'until' => $until, 'text' => self::banner( trim( (string) $order->get_meta( self::BY, true ) ), (string) $parts['day'], true ) );
	}

	// ---------- Rutas ----------

	private static function order_or_error( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? $order : new WP_Error( 'cvd_reserva_order', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function bot_get( WP_REST_Request $request ) {
		$order = self::order_or_error( $request );
		return is_wp_error( $order ) ? $order : self::no_cache( array( 'id' => $order->get_id(), 'reserva' => self::get( $order ) ) );
	}

	/**
	 * Body: until, by, byPhone, byRole (gestora|tienda), note, clear. Con byRole=gestora el teléfono tiene que ser el de
	 * la gestora dueña del pedido (nadie reserva pedidos ajenos). El bot solo promete «se guarda» si la respuesta trae ok:true.
	 */
	public static function bot_set( WP_REST_Request $request ) {
		$order = self::order_or_error( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$params = (array) $request->get_json_params();
		$role = sanitize_key( (string) ( $params['byRole'] ?? 'gestora' ) );
		$by = trim( (string) ( $params['by'] ?? '' ) );
		if ( 'gestora' === $role ) {
			$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
			$gestora = $owner ? CVD_Bot_Bridge::gestora_by_phone( (string) ( $params['byPhone'] ?? '' ) ) : null;
			if ( ! $gestora || $gestora->ID !== $owner ) { return new WP_Error( 'cvd_reserva_no_es_tuya', 'Ese pedido no es de esa gestora: no se reserva.', array( 'status' => 403 ) ); }
			if ( '' === $by ) { $by = $gestora->display_name; $params['by'] = $by; }
		}
		$result = self::set( $order->get_id(), $params, 'bot', 'VivaBot' . ( '' !== $by ? ' (' . $by . ')' : '' ) );
		return is_wp_error( $result ) ? $result : self::no_cache( $result );
	}

	public static function sales_set( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		$result = self::set( absint( $request['id'] ), (array) $request->get_json_params() + (array) $request->get_params(), 'ventas', $user && $user->exists() ? $user->display_name : '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function panel_set( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = self::set( absint( $request['id'] ), $params, 'panel', 'panel · ' . mb_substr( sanitize_text_field( (string) ( $params['actor'] ?? '' ) ), 0, 60 ) );
		return is_wp_error( $result ) ? $result : self::no_cache( $result );
	}

	/** Reservas que ya vencieron (pedido aún abierto) y todavía no se avisaron: el bot avisa 1 vez y confirma con /avisada. */
	public static function bot_expired(): WP_REST_Response {
		$out = array();
		$orders = wc_get_orders( array(
			'limit' => 50, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'status' => self::OPEN,
			'meta_query' => array(
				array( 'key' => self::UNTIL, 'value' => self::today(), 'compare' => '<', 'type' => 'CHAR' ),
				array( 'key' => self::NOTIFIED, 'compare' => 'NOT EXISTS' ),
			),
		) );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			$r = self::get( $order );
			if ( ! $r || ! $r['expired'] || $r['notified'] ) { continue; }
			$owner = 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? absint( $order->get_meta( '_cvd_owner_user_id', true ) ) : 0;
			$out[] = array(
				'id' => $order->get_id(), 'number' => $order->get_order_number(), 'reserva' => $r,
				'customer' => $order->get_billing_first_name(), 'items' => class_exists( 'CVD_Order_Lines' ) ? implode( ', ', CVD_Order_Lines::texts( $order ) ) : '',
				'gestora' => $owner ? array( 'name' => (string) $order->get_meta( 'gestora_nombre', true ), 'phone' => CVD_Sales::user_phone( $owner ) ) : null,
				'test' => class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) ),
			);
		}
		return self::no_cache( array( 'reservas' => $out ) );
	}

	public static function bot_notified( WP_REST_Request $request ) {
		$order = self::order_or_error( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! self::get( $order ) ) { return new WP_Error( 'cvd_reserva_no_hay', 'Ese pedido no tiene reserva.', array( 'status' => 404 ) ); }
		if ( '' === (string) $order->get_meta( self::NOTIFIED, true ) ) {
			$order->update_meta_data( self::NOTIFIED, current_time( 'mysql', true ) );
			$order->add_order_note( 'Reserva vencida: VivaBot avisó una vez a la gestora y a la tienda.' );
			$order->save();
		}
		return self::no_cache( array( 'ok' => true ) );
	}
}
