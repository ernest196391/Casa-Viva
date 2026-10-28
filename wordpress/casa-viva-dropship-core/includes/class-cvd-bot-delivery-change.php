<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.62 (#4223, Katia y Daiquel, 20-oct): dos puertas de VivaBot para lo que antes quedaba «para revisión de la tienda»
 * y nadie revisaba.
 *
 * 1) POST /bot/orders/{id}/to-delivery — la gestora DUEÑA del pedido pide pasarlo de recogida a mensajería con
 *    dirección, municipio (y reparto). Core comprueba la tarifa OFICIAL de la zona (CVD_Shipping_Rates::quote) y, si la
 *    gestora dijo un costo distinto, NO cambia nada (fee_mismatch). Si todo cuadra, hace lo mismo que se hizo a mano en
 *    #3871 y #4223 (tipo de entrega, dirección, reparto, tarifa, nota) y lo reparte como un pedido a domicilio nuevo
 *    (CVD_Delivery::dispatch_new_order: al grupo, o directo al mensajero elegido; fuera de hora, a la hora de reparto).
 *    dry=1 solo comprueba y cotiza.
 * 2) POST /bot/dispatch/{id}/assign — la gestora dueña dice «que lo lleve Daiquel», o el mensajero dice «la gestora me lo
 *    asignó a mí». Se asigna DIRECTO a ese mensajero (CVD_Delivery::assign_direct, no sale al grupo); si ya salió al grupo
 *    y nadie lo cogió, se le da como su «Yo»; si aún es de recogida o está fuera de hora, queda como mensajero elegido
 *    y se le asigna al repartirse. Cuando lo dice el mensajero, la nota lo dice así y el bot avisa a la gestora.
 * Nunca toca productos, importes, comisiones ni cobros; nunca pedidos de prueba, cerrados o ya recogidos.
 */
final class CVD_Bot_Delivery_Change {
	private const NS = 'casa-viva/v1';
	private const OPEN = array( 'pending', 'processing', 'on-hold' );
	private const CLOSED_OPERATION = array( 'picked_up', 'delivered', 'closed', 'cancelled', 'completed' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/to-delivery', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'to_delivery' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/assign', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'assign' ), 'permission_callback' => $auth ) );
		// 3.14.64 (Ernesto 24-oct: «los avisos de WhatsApp no me llegan, déjalo en mi correo, gratis»): aviso del bot por correo.
		register_rest_route( self::NS, '/bot/aviso-correo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'owner_mail' ), 'permission_callback' => $auth ) );
	}

	/**
	 * → result: changed (+ dispatch: published | assigned | scheduled | held | waiting) | quote (dry) | not_owner | not_open |
	 *   already_delivery | too_late | zone_unknown (+ zones) | fee_mismatch (+ fee oficial) | missing (+ fields).
	 */
	public static function to_delivery( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found' ) ); }
		$gestora = self::owner_gestora( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $gestora ) { return self::out( array( 'result' => 'not_owner' ) ); }
		$block = self::block( $order );
		if ( '' !== $block ) { return self::out( array( 'result' => $block, 'status' => $order->get_status(), 'operation' => self::operation( $order ) ) ); }
		if ( 'pickup' !== self::fulfillment( $order ) ) { $holder = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ); return self::out( array( 'result' => 'already_delivery', 'delivery' => CVD_Delivery::status( $order ), 'address' => $order->get_billing_address_1(), 'zone' => (string) $order->get_meta( '_cvd_locality', true ), 'fee' => absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ), 'holder' => $holder ? $holder->display_name : '' ) ); }

		$address = self::text( $request->get_param( 'address' ), 200 );
		$municipality = self::text( $request->get_param( 'municipality' ), 80 );
		$zone = self::text( $request->get_param( 'zone' ), 80 );
		$missing = array_values( array_filter( array( '' === $address ? 'address' : '', '' === $municipality ? 'municipality' : '' ) ) );
		if ( $missing ) { return self::out( array( 'result' => 'missing', 'fields' => $missing ) ); }
		$official = self::official_municipality( $municipality );
		if ( '' === $official ) { return self::out( array( 'result' => 'zone_unknown', 'municipality' => $municipality, 'municipalities' => array_keys( CVD_Shipping_Rates::localities() ) ) ); }
		// Sin reparto escrito aparte, se busca dentro de la dirección («… Tulipán y La Rosa. Nuevo Vedado.»).
		$quote = CVD_Shipping_Rates::quote( $official, '' !== $zone ? $zone : $address );
		if ( 'zone' !== ( $quote['status'] ?? '' ) || empty( $quote['fee'] ) ) {
			return self::out( array( 'result' => 'zone_unknown', 'municipality' => $official, 'zone' => $zone, 'zones' => CVD_Shipping_Rates::localities()[ $official ] ?? array() ) );
		}
		$fee = absint( $quote['fee'] );
		$said = $request->get_param( 'fee' );
		if ( null !== $said && '' !== $said && absint( $said ) !== $fee ) {
			return self::out( array( 'result' => 'fee_mismatch', 'fee' => $fee, 'said' => absint( $said ), 'zone' => (string) $quote['label'], 'municipality' => $official ) );
		}
		if ( rest_sanitize_boolean( $request->get_param( 'dry' ) ) ) {
			return self::out( array( 'result' => 'quote', 'fee' => $fee, 'zone' => (string) $quote['label'], 'municipality' => $official ) );
		}

		$when = self::text( $request->get_param( 'when' ), 80 );
		$order->update_meta_data( '_cvd_fulfillment_type', 'delivery' );
		$order->update_meta_data( '_billing_cvd_fulfillment_type', 'delivery' );
		$order->set_billing_address_1( $address );
		$order->set_shipping_address_1( $address );
		$order->set_billing_city( $official );
		$order->set_shipping_city( $official );
		$order->update_meta_data( '_cvd_locality', (string) $quote['label'] );
		$order->update_meta_data( '_cvd_shipping_fee_cup', $fee );
		$order->update_meta_data( '_cvd_shipping_rate_status', 'zone' );
		$order->update_meta_data( '_cvd_shipping_rate_label', (string) $quote['label'] );
		$order->update_meta_data( '_cvd_shipping_rate_version', CVD_Shipping_Rates::VERSION );
		$order->delete_meta_data( '_cvd_delivery_status' ); // reparto desde cero: «por ofertar»
		$order->add_order_note( sprintf(
			'VivaBot: pasado de recogida a mensajería a petición de %s (gestor/a dueño/a del pedido) por WhatsApp: %s, %s (%s). Mensajería %s CUP según la tarifa oficial.%s',
			$gestora->display_name, $address, (string) $quote['label'], $official, number_format_i18n( $fee, 0 ), '' !== $when ? ' Cuándo: ' . $when . '.' : ''
		) );
		$order->save();

		$messenger = self::messenger_param( $request );
		if ( $messenger ) { self::prefer( $order, $messenger, 'gestora', $gestora->display_name ); }
		CVD_Delivery::dispatch_new_order( $order->get_id() );
		$fresh = wc_get_order( $order->get_id() );
		return self::out( array( 'result' => 'changed', 'fee' => $fee, 'zone' => (string) $quote['label'], 'municipality' => $official ) + self::dispatch_state( $fresh ) );
	}

	/**
	 * → result: assigned | won (estaba en el grupo; voucher) | yours | preferred (se asigna al repartirse) | taken (+ messenger) |
	 *   not_messenger | not_owner | not_gestora_order | not_open | too_late (+ status).
	 * phone = quien lo pide. role = gestora (la dueña nombra al mensajero, messenger_phone) o mensajero (se lo asignaron a él).
	 */
	public static function assign( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found' ) ); }
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		$phone = (string) $request->get_param( 'phone' );
		$owner = self::owner( $order );
		if ( ! $owner ) { return self::out( array( 'result' => 'not_gestora_order' ) ); }
		if ( 'gestora' === $role ) {
			if ( ! self::owner_gestora( $order, $phone ) ) { return self::out( array( 'result' => 'not_owner' ) ); }
			$messenger = self::messenger_param( $request );
			$said_by = 'gestora';
		} else {
			$messenger = CVD_Bot_Bridge::messenger_by_phone( $phone );
			$said_by = 'mensajero';
		}
		if ( ! $messenger ) { return self::out( array( 'result' => 'not_messenger' ) ); }
		$base = array( 'messenger' => $messenger->display_name, 'messengerId' => $messenger->ID, 'gestora' => $owner->display_name, 'gestoraPhone' => self::whatsapp( $owner ) );
		$block = self::block( $order );
		if ( '' !== $block ) { return self::out( array( 'result' => $block, 'status' => $order->get_status() ) + $base ); }
		$current = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( $current === $messenger->ID ) { return self::out( array( 'result' => 'yours' ) + $base ); }
		if ( $current ) { $other = get_userdata( $current ); return self::out( array( 'result' => 'taken', 'holder' => $other ? $other->display_name : '' ) + $base ); }

		$status = CVD_Delivery::status( $order );
		if ( 'pickup' === self::fulfillment( $order ) || 'unassigned' === $status ) {
			self::prefer( $order, $messenger, $said_by, 'gestora' === $said_by ? $owner->display_name : $messenger->display_name );
			if ( 'pickup' === self::fulfillment( $order ) ) { return self::out( array( 'result' => 'preferred', 'pickup' => true ) + $base ); }
			CVD_Delivery::dispatch_new_order( $order->get_id() );
			$fresh = wc_get_order( $order->get_id() );
			$state = self::dispatch_state( $fresh );
			return self::out( array( 'result' => 'assigned' === $state['dispatch'] ? 'assigned' : 'preferred' ) + $state + $base );
		}
		if ( 'offered' === $status ) {
			// Ya salió al grupo y nadie dijo «Yo»: se le da a él como si lo hubiera cogido (misma transición y avisos).
			$data = CVD_Bot_Bridge::claim_by_messenger( $order, $messenger, 'whatsapp_group' ); // 3.14.61: el mismo «Yo» del grupo y de Pa'llá
			if ( 'won' === ( $data['result'] ?? '' ) ) {
				$fresh = wc_get_order( $order->get_id() );
				if ( $fresh ) { $fresh->add_order_note( 'VivaBot: ' . self::who_said( $said_by, $owner, $messenger ) . ' (estaba en el grupo sin mensajero).' ); $fresh->save(); }
			}
			return self::out( array( 'result' => $data['result'] ?? 'error', 'voucher' => $data['voucher'] ?? null ) + $base );
		}
		return self::out( array( 'result' => 'too_late', 'status' => $status ) + $base );
	}

	/**
	 * Aviso del bot a Ernesto por correo (cambios de entrega, mensajero asignado, recordatorios). Sale con el correo de la
	 * propia web (wp_mail de Hostinger, sin coste). Destino: opción cvd_bot_aviso_correo (si no, el correo del administrador).
	 * Tope de 40 por hora para que un error del bot no llene el buzón. → { sent: bool } | { sent: false, reason }.
	 */
	public static function owner_mail( WP_REST_Request $request ): WP_REST_Response {
		return self::out( self::mail_owner( (string) $request->get_param( 'asunto' ), (string) $request->get_param( 'texto' ) ) );
	}

	/**
	 * 3.14.71 (D121): el mismo correo a Ernesto, para usarlo también desde Core (p. ej. «Lennys publicó MK-…» desde el panel).
	 * Mismo destino y mismo tope de 40 por hora. $footer = quién firma el aviso.
	 */
	public static function mail_owner( string $subject, string $body, string $footer = 'VivaBot' ): array {
		$to = sanitize_email( (string) get_option( 'cvd_bot_aviso_correo', '' ) ) ?: sanitize_email( (string) get_option( 'admin_email' ) );
		$subject = self::text( $subject, 140 );
		$body = trim( wp_strip_all_tags( $body ) );
		if ( '' === $to || '' === $body ) { return array( 'sent' => false, 'reason' => 'missing' ); }
		$count = (int) get_transient( 'cvd_bot_aviso_correo_hora' );
		if ( $count >= 40 ) { return array( 'sent' => false, 'reason' => 'limit' ); }
		set_transient( 'cvd_bot_aviso_correo_hora', $count + 1, HOUR_IN_SECONDS );
		$sent = wp_mail( $to, '[Casa Viva] ' . ( '' !== $subject ? $subject : 'Aviso del bot' ), mb_substr( $body, 0, 5000 ) . "\n\n— " . $footer . ' (aviso automático, no hace falta responder)' );
		return array( 'sent' => (bool) $sent );
	}

	/** Mensajero elegido (CVD_Direct_Dispatch): dispatch_ready() se lo asigna directo en vez de publicarlo en el grupo. */
	private static function prefer( WC_Order $order, WP_User $messenger, string $said_by, string $who ): void {
		if ( absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) ) === $messenger->ID ) { return; }
		$order->update_meta_data( CVD_Direct_Dispatch::PREFERRED_META, $messenger->ID );
		$order->update_meta_data( CVD_Direct_Dispatch::SOURCE_META, 'gestora' );
		$owner = self::owner( $order );
		$order->add_order_note( 'VivaBot: ' . ( $owner ? self::who_said( $said_by, $owner, $messenger ) : $who . ' eligió a ' . $messenger->display_name ) . '. Se le asigna directo (no sale al grupo de mensajeros).' );
		$order->save();
	}

	private static function who_said( string $said_by, WP_User $owner, WP_User $messenger ): string {
		return 'gestora' === $said_by
			? $owner->display_name . ' (gestor/a) eligió al mensajero ' . $messenger->display_name . ' por WhatsApp'
			: $messenger->display_name . ' dice por WhatsApp que ' . $owner->display_name . ' (gestor/a) le asignó este pedido';
	}

	/** Cómo quedó el reparto después de intentarlo. */
	private static function dispatch_state( $order ): array {
		if ( ! $order instanceof WC_Order ) { return array( 'dispatch' => 'error' ); }
		$status = CVD_Delivery::status( $order );
		$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		if ( in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) && $messenger ) { return array( 'dispatch' => 'assigned', 'messenger' => $messenger->display_name ); }
		if ( 'offered' === $status ) { return array( 'dispatch' => 'published' ); }
		if ( wp_next_scheduled( 'cvd_dispatch_new_order', array( $order->get_id() ) ) ) { return array( 'dispatch' => 'scheduled' ); }
		if ( class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::order_has_market_items( $order ) ) { return array( 'dispatch' => 'held' ); }
		return array( 'dispatch' => 'waiting', 'status' => $status, 'wc' => $order->get_status() );
	}

	/** '' = se puede tocar; si no, por qué no. */
	private static function block( WC_Order $order ): string {
		if ( CVD_Delivery::is_test_order( $order ) ) { return 'test_order'; }
		if ( ! $order->has_status( self::OPEN ) ) { return 'not_open'; }
		if ( in_array( self::operation( $order ), self::CLOSED_OPERATION, true ) ) { return 'too_late'; }
		$delivery = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) );
		if ( in_array( $delivery, array( 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed', 'failed', 'returned', 'cancelled' ), true ) ) { return 'too_late'; }
		return '';
	}

	/** Nombre del municipio tal como está en la lista de tarifas («Plaza» → «Plaza de la Revolución»), o ''. */
	private static function official_municipality( string $typed ): string {
		$key = self::loose( $typed );
		if ( '' === $key ) { return ''; }
		$names = array();
		foreach ( CVD_Shipping_Rates::rates() as $row ) { if ( ! empty( $row['active'] ) ) { $names[ (string) $row['municipality'] ] = self::loose( (string) $row['municipality'] ); } }
		foreach ( $names as $name => $loose ) { if ( $loose === $key ) { return $name; } }
		$hits = array_keys( array_filter( $names, static fn( $loose ) => '' !== $loose && ( 0 === strpos( $loose, $key . ' ' ) || false !== strpos( ' ' . $key . ' ', ' ' . $loose . ' ' ) ) ) );
		return 1 === count( $hits ) ? $hits[0] : '';
	}

	private static function loose( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = preg_replace( '/\b(municipio|mun|de|la|el|del)\b/', ' ', $text );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	private static function owner( WC_Order $order ): ?WP_User {
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return null; }
		$user = get_userdata( absint( $order->get_meta( '_cvd_owner_user_id', true ) ) );
		return $user instanceof WP_User ? $user : null;
	}

	/** La gestora dueña del pedido, solo si es quien escribe (mismos 8 últimos dígitos de su WhatsApp). */
	private static function owner_gestora( WC_Order $order, string $phone ): ?WP_User {
		$owner = self::owner( $order );
		$key = self::key( $phone );
		return $owner && '' !== $key && $key === self::key( self::whatsapp( $owner ) ) ? $owner : null;
	}

	private static function messenger_param( WP_REST_Request $request ): ?WP_User {
		$phone = (string) $request->get_param( 'messenger_phone' );
		return '' !== self::key( $phone ) ? CVD_Bot_Bridge::messenger_by_phone( $phone ) : null;
	}

	private static function whatsapp( WP_User $user ): string {
		return preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ) );
	}

	private static function key( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		return strlen( $digits ) >= 8 ? substr( $digits, -8 ) : '';
	}

	private static function fulfillment( WC_Order $order ): string {
		return sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
	}

	private static function operation( WC_Order $order ): string {
		return sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
	}

	private static function text( $value, int $max ): string {
		return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $max );
	}

	private static function order( WP_REST_Request $request ): ?WC_Order {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? $order : null;
	}

	private static function out( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
