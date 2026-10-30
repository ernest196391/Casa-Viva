<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-cvd-geo-direccion.php'; // 3.14.78: la ubicación sale de la dirección (sin tocar el archivo principal)
require_once __DIR__ . '/class-cvd-palla-disponible.php'; // 3.14.83: 🟢 Disponible / ⚪ No disponible

/**
 * 3.14.61: la API de Pa'llá (casa-viva/v1/palla/…). Clase aparte de CVD_Palla para llevarla a NEXO en la fase 2.
 * Entra con X-Palla-Token (CVD_Palla::request_user). Todo no-store. Ningún camino nuevo: cada botón usa lo de siempre.
 *
 * - GET  hoy     — todo en una llamada (mala conexión): yo, ofertas abiertas (las mismas que ve el grupo: offered, sin
 *                  mensajero, blocks_dispatch() vacío, sin datos del cliente), mis carreras en curso con lo de la tarjeta
 *                  de carrera, y mi dinero del día (bring_after() de lo entregado hoy, sin «Dinero recibido»).
 * - POST cojo    {order, cid}  → CVD_Bot_Bridge::claim_by_messenger (el «Yo» del grupo: primero que llega).
 * - POST carrera {order, action: recogi|entregado|problema|suelto, otra, usd, cup, eur, como, motivo, texto, cid}
 *                → CVD_Tarjeta_Carrera::submit() (los 4 botones de la tarjeta, mismos códigos). El cid va como cvd_n
 *                del «Problema».
 * - POST hora    {order, texto, cid} (3.14.67.1) — a qué hora lo entrega: lo mismo que la hora por WhatsApp (_cvd_bot_eta y, si estaba
 *                «asignada», aceptarla). Marca _cvd_palla_eta_at: el bot la avisa a la gestora o al cliente (GET /bot/dispatch/palla).
 * - Idempotencia de la cola sin conexión: cada acción lleva cid; se guardan los últimos 50 cid del mensajero con su
 *   respuesta y un reenvío devuelve lo mismo sin repetir nada.
 *
 * Nunca escribe al leer: la mensajería de las ofertas sale de lo guardado (courier_amount / _cvd_shipping_fee_cup).
 * (Las carreras propias usan money_plan(), lo mismo que la página de la tarjeta.)
 */
final class CVD_Palla_Api {
	private const NS = 'casa-viva/v1';
	public const CIDS_META = '_cvd_palla_cids';
	private const OPEN_STATUSES = array( 'pending', 'processing', 'on-hold' );
	private const STORE = 'Casa Viva · Conill A esq. 45 #864';
	private const STORE_ZONE = 'Nuevo Vedado';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( __CLASS__, 'permission' );
		register_rest_route( self::NS, '/palla/hoy', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'today' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/palla/cojo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'claim' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/palla/carrera', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'action' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/palla/hora', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eta' ), 'permission_callback' => $auth ) );
		// 3.14.69.16 (Pedri 25-oct: «ya no puedo ver las carreras que hice ayer… lo llevo en un Excel»): historial y acumulado.
		register_rest_route( self::NS, '/palla/historial', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'history' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/palla/disponible', array( 'methods' => 'POST', 'callback' => array( 'CVD_Palla_Disponible', 'rest' ), 'permission_callback' => $auth ) ); // 3.14.83
		// 3.14.74.3 (fase 3 del mapa): el punto exacto del cliente antes de salir. La gestora lo manda por WhatsApp (el bot
		// lo trae aquí) o el cliente marca su casa en el seguimiento.
		register_rest_route( self::NS, '/bot/gestora/ubicacion', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_point' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
		register_rest_route( self::NS, '/delivery/(?P<id>\d+)/casa', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'client_point' ), 'permission_callback' => '__return_true' ) );
		// 3.14.71.2 (Ernesto 28-oct, «como Mandao»): el recorrido del mensajero durante sus carreras.
		register_rest_route( self::NS, '/palla/ubicacion', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'track' ), 'permission_callback' => $auth ) );
		// 3.14.67.1: pasar la carrera a un compañero (él la acepta en su app; hasta entonces sigue siendo de quien la pasa).
		register_rest_route( self::NS, '/palla/pasar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pass_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/palla/pase', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pass_answer' ), 'permission_callback' => $auth ) );
	}

	/** @return true|WP_Error */
	public static function permission( WP_REST_Request $request ) {
		$user = CVD_Palla::request_user( $request );
		if ( is_wp_error( $user ) ) { return $user; }
		// 3.14.69.1: lo que toca el mensajero queda a su nombre (historial y avisos: no se le avisa de lo que hizo él).
		wp_set_current_user( $user->ID );
		return true;
	}

	private static function user( WP_REST_Request $request ): WP_User {
		return CVD_Palla::request_user( $request ); // ya comprobado en permission()
	}

	private static function out( array $data ): WP_REST_Response {
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá: datos privados del mensajero' );
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// ------------------------------------------------------------------ GET hoy

	public static function today( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$mine = array();
		foreach ( self::my_open_orders( $me ) as $order ) { $mine[] = self::card( $order, $me ); }
		return self::out( array(
			'ok'       => true,
			'now'      => gmdate( 'c' ),
			'yo'       => self::me( $me ),
			'ofertas'  => self::offers(),
			'carreras' => $mine,
			'dinero'   => self::money_today( $me ),
			'pases'    => self::passes_for( $me ),     // 3.14.67.1: carreras que un compañero me quiere pasar
			'companeros' => self::companions( $me ), // a quién puedo pasar una carrera (solo nombre)
			'push'     => class_exists( 'CVD_Palla_Push' ) ? CVD_Palla_Push::status_for( $me ) : array( 'allowed' => false, 'key' => '' ), // 3.14.69.1
			'disp'     => CVD_Palla_Disponible::view( $me->ID ), // 3.14.83: 🟢 Disponible / ⚪ No disponible
		) );
	}

	// ------------------------------------------------------------------ Pasar la carrera (3.14.67.1)

	/** El pase pendiente del pedido: {from, to, at} o null. */
	public static function pass_of( WC_Order $order ): ?array {
		$raw = json_decode( (string) $order->get_meta( '_cvd_palla_pase', true ), true );
		return is_array( $raw ) && ! empty( $raw['from'] ) && ! empty( $raw['to'] ) ? array( 'from' => absint( $raw['from'] ), 'to' => absint( $raw['to'] ), 'at' => (string) ( $raw['at'] ?? '' ) ) : null;
	}

	private static function clear_pass( WC_Order $order ): void {
		$order->delete_meta_data( '_cvd_palla_pase' );
		$order->delete_meta_data( '_cvd_palla_pase_to' );
	}

	/** El pase que yo pedí y todavía no contestaron: {to, toName} o null. */
	private static function outgoing_pass( WC_Order $order, WP_User $me ): ?array {
		$pass = self::pass_of( $order );
		if ( ! $pass || $pass['from'] !== $me->ID ) { return null; }
		$to = get_userdata( $pass['to'] );
		return array( 'to' => $pass['to'], 'toName' => $to ? (string) $to->display_name : 'tu compañero' );
	}

	/** Mensajeros a los que se les puede pasar una carrera: los que pueden entrar en Pa'llá (para poder aceptarla), sin mí. */
	public static function companions( WP_User $me ): array {
		$list = array();
		foreach ( get_users( array( 'role' => 'cvd_messenger', 'number' => 200 ) ) as $user ) {
			if ( $user->ID === $me->ID || '' !== CVD_Palla::blocked_reason( $user ) ) { continue; }
			$list[] = array( 'id' => $user->ID, 'name' => (string) $user->display_name );
		}
		usort( $list, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return array_slice( $list, 0, 60 );
	}

	/** Las carreras que me quieren pasar (quien la pasa sigue teniéndola y antes de que haya un problema abierto). */
	public static function passes_for( WP_User $me ): array {
		$orders = wc_get_orders( array( 'limit' => 10, 'type' => 'shop_order', 'status' => self::OPEN_STATUSES, 'meta_key' => '_cvd_palla_pase_to', 'meta_value' => $me->ID ) );
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$pass = self::pass_of( $order );
			if ( ! $pass || $pass['to'] !== $me->ID || $pass['from'] !== absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { continue; }
			$from = get_userdata( $pass['from'] );
			$out[] = array(
				'id'       => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'from'     => $from ? (string) $from->display_name : 'Un compañero',
				'zone'     => CVD_Delivery::destination_zone( $order ),
				'picked'   => in_array( CVD_Delivery::status( $order ), array( 'picked_up', 'handed_over' ), true ),
				'items'    => CVD_Order_Lines::texts( $order ),
				'feeText'  => self::fee( $order ) > 0 ? self::money( (float) self::fee( $order ), 'CUP' ) : '',
				'when'     => self::when( $order ),
			);
		}
		return $out;
	}

	/** POST pasar {order, to, cid}: le pido a un compañero que acepte mi carrera. Sigue siendo mía hasta que diga que sí. */
	public static function pass_request( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found', 'message' => self::message( 'not_found', '' ) ) ); }
		$number = (string) $order->get_order_number();
		$to = get_userdata( absint( $request->get_param( 'to' ) ) );
		$stage = CVD_Tarjeta_Carrera::stage( $order, $me->ID );
		if ( CVD_Tarjeta_Carrera::is_test( $order ) ) { $out = array( 'result' => 'test', 'message' => self::message( 'test', $number ) ); }
		elseif ( ! in_array( $stage, array( 'pickup', 'route' ), true ) ) { $out = array( 'result' => 'not_now', 'message' => '#' . $number . ': ahora no se puede pasar (si hay un problema avisado, la tienda decide).' ); }
		elseif ( ! $to instanceof WP_User || $to->ID === $me->ID || '' !== CVD_Palla::blocked_reason( $to ) ) { $out = array( 'result' => 'bad_to', 'message' => 'Elige a un compañero que use Pa\'llá.' ); }
		else {
			$order->update_meta_data( '_cvd_palla_pase', wp_json_encode( array( 'from' => $me->ID, 'to' => $to->ID, 'at' => current_time( 'mysql', true ) ) ) );
			$order->update_meta_data( '_cvd_palla_pase_to', $to->ID );
			$order->update_meta_data( '_cvd_palla_bot', '1' ); // el bot le escribe al compañero
			$order->add_order_note( 'Pa\'llá: ' . $me->display_name . ' le pide a ' . $to->display_name . ' que coja esta carrera (sigue a nombre de ' . $me->display_name . ' hasta que acepte).' );
			$order->save();
			$out = array( 'result' => 'pass_sent', 'message' => '🔁 #' . $number . ': le pedimos a ' . self::first( (string) $to->display_name ) . ' que la acepte. Sigue siendo tuya hasta que diga que sí.' );
		}
		$out['number'] = $number;
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof WC_Order && in_array( CVD_Tarjeta_Carrera::stage( $fresh, $me->ID ), array( 'pickup', 'route', 'incident' ), true ) ) { $out['carrera'] = self::card( $fresh, $me ); }
		self::remember( $me->ID, $cid, $out );
		return self::out( $out );
	}

	/**
	 * POST pase {order, decision: acepto | no | cancelar, cid}. «acepto»/«no» los dice quien la recibe; «cancelar», quien la pasa.
	 * Al aceptar, el pedido pasa a su nombre sin cambiar el paso (como CVD_Bot_Bridge::handover): antes o después de recoger.
	 * La hora se borra (la pone el nuevo) y queda _cvd_palla_paso_at para que el bot le mande el vale y avise a la gestora.
	 */
	public static function pass_answer( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found', 'message' => self::message( 'not_found', '' ) ) ); }
		$number = (string) $order->get_order_number();
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );
		$pass = self::pass_of( $order );
		$owner = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$status = CVD_Delivery::status( $order );
		$out = array( 'result' => 'gone', 'message' => '#' . $number . ': ese pase ya no está (la soltó, la entregó o lo canceló).' );
		if ( $pass && 'cancelar' === $decision && $pass['from'] === $me->ID ) {
			self::clear_pass( $order );
			$order->add_order_note( 'Pa\'llá: ' . $me->display_name . ' canceló el pase de la carrera.' );
			$order->save();
			$out = array( 'result' => 'pass_cancelled', 'message' => '#' . $number . ': cancelaste el pase. Sigue siendo tuya.' );
		} elseif ( $pass && $pass['to'] === $me->ID && $pass['from'] !== $owner ) {
			self::clear_pass( $order ); // quien la pasaba ya no la tiene
			$order->save();
		} elseif ( $pass && $pass['to'] === $me->ID && 'no' === $decision ) {
			$from = get_userdata( $pass['from'] );
			self::clear_pass( $order );
			$order->add_order_note( 'Pa\'llá: ' . $me->display_name . ' no aceptó el pase' . ( $from ? ' de ' . $from->display_name : '' ) . '.' );
			$order->save();
			$out = array( 'result' => 'pass_declined', 'message' => 'Listo, no aceptaste #' . $number . '. Se lo decimos a tu compañero.' );
		} elseif ( $pass && $pass['to'] === $me->ID && 'acepto' === $decision ) {
			if ( 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true ) || ! in_array( $status, array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over' ), true ) ) {
				self::clear_pass( $order );
				$order->save();
				$out = array( 'result' => 'not_now', 'message' => '#' . $number . ': ya no se puede pasar (la tienda tiene un problema abierto o cambió el pedido).' );
			} else {
				$from = get_userdata( $pass['from'] );
				$now = current_time( 'mysql', true );
				$order->update_meta_data( '_cvd_messenger_user_id', $me->ID );
				$order->delete_meta_data( '_cvd_bot_eta' );
				$order->delete_meta_data( '_cvd_bot_eta_at' );
				$order->delete_meta_data( '_cvd_palla_eta_at' );
				$order->update_meta_data( '_cvd_palla_paso', wp_json_encode( array( 'from' => $pass['from'], 'to' => $me->ID, 'at' => $now ) ) );
				$order->update_meta_data( '_cvd_palla_paso_at', $now );
				$order->update_meta_data( '_cvd_palla_bot', '1' ); // el bot manda el vale al nuevo y avisa a la gestora
				self::clear_pass( $order );
				$order->add_order_note( 'Pa\'llá: carrera pasada de ' . ( $from ? $from->display_name : 'otro mensajero' ) . ' a ' . $me->display_name . ( in_array( $status, array( 'picked_up', 'handed_over' ), true ) ? ' con el paquete ya recogido. Lo entrega y devuelve el dinero ' . $me->display_name . '.' : ' antes de recogerla.' ) );
				$order->save();
				if ( class_exists( 'CVD_Messenger_Reputation' ) ) { CVD_Messenger_Reputation::invalidate( $pass['from'] ); CVD_Messenger_Reputation::invalidate( $me->ID ); }
				$out = array( 'result' => 'pass_accepted', 'message' => '✅ #' . $number . ' ahora es tuya. Pon a qué hora la entregas.' );
			}
		}
		$out['number'] = $number;
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof WC_Order && in_array( CVD_Tarjeta_Carrera::stage( $fresh, $me->ID ), array( 'pickup', 'route', 'incident' ), true ) ) { $out['carrera'] = self::card( $fresh, $me ); }
		self::remember( $me->ID, $cid, $out );
		return self::out( $out );
	}

	private static function first( string $name ): string { $name = trim( $name ); return '' !== $name ? (string) strtok( $name, ' ' ) : ''; }

	private static function me( WP_User $me ): array {
		return array(
			'id'        => $me->ID,
			'name'      => (string) $me->display_name,
			'first'     => self::first( (string) $me->display_name ),
			'vehicle'   => trim( (string) get_user_meta( $me->ID, '_cvd_vehicle', true ) ),
			'available' => 'yes' === get_user_meta( $me->ID, '_cvd_messenger_available', true ),
		);
	}

	private static function money( float $amount, string $currency ): string { return CVD_Messenger_Delivered_Link::money( $amount, $currency ); }

	/** Mensajería guardada del pedido, sin escribir (order_fee() puede guardar la tarifa; aquí solo se lee). */
	private static function fee( WC_Order $order ): int {
		if ( absint( $order->get_meta( '_cvd_joined_with', true ) ) ) { return 0; } // va junto con otro vale (mismo viaje)
		$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		return $fee ?: (int) round( CVD_Delivery::courier_amount( $order ) );
	}

	private static function when( WC_Order $order ): string {
		return class_exists( 'CVD_Delivery_When' ) ? (string) ( CVD_Delivery_When::for_order( $order )['label'] ?? '' ) : '';
	}

	private static function payment( WC_Order $order ): ?array {
		return class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
	}

	/** Proveedores «bajo pedido» del pedido (la tienda dice dónde se recoge). */
	private static function suppliers( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			if ( ! $product || ! class_exists( 'CVD_Costs' ) || ! CVD_Costs::is_bajo_pedido( $product ) ) { continue; }
			$name = (string) $product->get_meta( '_cvd_supplier_name', true );
			if ( '' === $name && $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); $name = $parent ? (string) $parent->get_meta( '_cvd_supplier_name', true ) : ''; }
			$out[] = '' !== $name ? $name : 'el proveedor';
		}
		return array_values( array_unique( $out ) );
	}

	/** Las mismas ofertas que el bot publica en el grupo (CVD_Bot_Bridge::dispatch), sin datos del cliente. */
	public static function offers(): array {
		$orders = wc_get_orders( array( 'limit' => 30, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'meta_key' => '_cvd_delivery_status', 'meta_value' => 'offered' ) );
		$list = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			if ( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { continue; }
			if ( '' !== CVD_Delivery::blocks_dispatch( $order ) ) { continue; } // prueba, sin mensajería o no activo
			$a = CVD_Messenger_Delivered_Link::default_amounts( self::payment( $order ), (float) $order->get_total() );
			$products = array_filter( array( 'USD' => $a['usd'], 'CUP' => $a['cup'], 'EUR' => $a['eur'] ), static fn( $v ): bool => (float) $v > 0 );
			$change = CVD_Tarjeta_Carrera::store_change( $order );
			$assembly = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
			$fee = self::fee( $order );
			$list[] = array(
				'id'       => $order->get_id(),
				'number'   => (string) $order->get_order_number(),
				'when'     => self::when( $order ),
				'zone'     => CVD_Delivery::destination_zone( $order ),
				'muni'     => CVD_Palla_Disponible::zone_of( $order ), // 3.14.83: para marcar «de tu zona»
				'from'     => 'Casa Viva · ' . self::STORE_ZONE,
				'items'    => CVD_Order_Lines::texts( $order ),
				'fee'      => $fee,
				'feeText'  => $fee > 0 ? self::money( (float) $fee, 'CUP' ) : 'Mensajería por confirmar',
				'collect'  => $a['prepaid'] ? 'Nada, ya pagó' : ( CVD_Tarjeta_Carrera::money_text( $products ) ?: 'Lo que diga la tienda' ),
				'change'   => CVD_Tarjeta_Carrera::money_text( $change ),
				'assembly' => $assembly ? 'Hay que armarlo: cobras ' . self::money( (float) $assembly['usd'], 'USD' ) . ' de armado (es tuyo)' : '',
				'supplier' => (bool) self::suppliers( $order ),
			);
		}
		return $list;
	}

	/** Mis carreras sin terminar (por recoger, en camino o con un problema avisado). */
	private static function my_open_orders( WP_User $me ): array {
		$orders = wc_get_orders( array( 'limit' => 40, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'status' => self::OPEN_STATUSES, 'meta_key' => '_cvd_messenger_user_id', 'meta_value' => $me->ID ) );
		return array_values( array_filter( $orders, static fn( $o ): bool => $o instanceof WC_Order && in_array( CVD_Tarjeta_Carrera::stage( $o, $me->ID ), array( 'pickup', 'route', 'incident' ), true ) ) );
	}

	private static function phone_label( string $digits ): string {
		return class_exists( 'CVD_Sales' ) ? CVD_Sales::phone_label( $digits ) : '+' . $digits;
	}

	private static function delivered_ts( WC_Order $order ): int {
		$at = (string) $order->get_meta( '_cvd_delivered_at', true );
		$ts = '' !== $at ? strtotime( $at . ' UTC' ) : false;
		return $ts ?: time();
	}

	/** Lo mismo que enseña la tarjeta de carrera (CVD_Tarjeta_Carrera), en datos para la app. */
	public static function card( WC_Order $order, WP_User $me ): array {
		$stage = CVD_Tarjeta_Carrera::stage( $order, $me->ID );
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = trim( implode( ', ', array_filter( array( $order->get_shipping_address_1() ?: $order->get_billing_address_1(), $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) ) ) );
		$phone = CVD_Tarjeta_Carrera::customer_phone( $order );
		$alt = preg_replace( '/\D+/', '', (string) $order->get_meta( '_cvd_alternate_phone', true ) );
		$alt = 8 === strlen( $alt ) ? '53' . $alt : $alt;
		$alt = strlen( $alt ) >= 8 && substr( $alt, -8 ) !== substr( $phone, -8 ) ? $alt : '';
		$assembly = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
		$map = self::map_for( $order );
		return array(
			'id'       => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'stage'    => $stage,
			'test'     => CVD_Tarjeta_Carrera::is_test( $order ),
			'when'     => self::when( $order ),
			'eta'      => (string) $order->get_meta( '_cvd_bot_eta', true ), // hora que dio el mensajero (app o WhatsApp)
			'pase'     => self::outgoing_pass( $order, $me ),                // 3.14.67.1: «Esperando que X acepte»
			'customer' => array(
				'name'       => '' !== $name ? $name : 'Cliente',
				'first'      => self::first( $name ),
				'address'    => $address,
				'zone'       => CVD_Delivery::destination_zone( $order ),
				'reference'  => trim( (string) $order->get_meta( '_cvd_reference', true ) ),
				'note'       => trim( wp_strip_all_tags( (string) $order->get_customer_note() ) ),
				'phone'      => $phone,
				'phoneLabel' => '' !== $phone ? self::phone_label( $phone ) : '',
				'alt'        => $alt,
				'altLabel'   => '' !== $alt ? self::phone_label( $alt ) : '',
			),
			'map'      => $map[0],
			'mapFrom'  => $map[1], // 3.14.69.17: pedido | entrega | cliente (de otra entrega al mismo cliente) | auto (3.14.78: de la dirección) | ''
			'dest'     => self::coords( $map[0] ),                                     // 3.14.71.2: [lat, lon] o null
			'arrived'  => (string) $order->get_meta( '_cvd_llegue_at', true ),           // 3.14.71.2: «Llegué» (UTC) o ''
			'items'    => CVD_Order_Lines::texts( $order ),
			'pickup'   => array(
				'place'     => self::STORE,
				'zone'      => self::STORE_ZONE,
				'hours'     => (string) get_option( 'cvd_tarjeta_recogida_horas', 'de 10:00 a. m. a 4:00 p. m.' ),
				'suppliers' => self::suppliers( $order ),
			),
			'assembly' => $assembly ? self::money( (float) $assembly['usd'], 'USD' ) : '',
			'money'    => 'delivered' === $stage ? self::money_after( $order ) : self::money_before( $order ),
		);
	}

	/** Dinero ANTES de entregar: misma cuenta que money_card() de la tarjeta (money_plan()). */
	private static function money_before( WC_Order $order ): array {
		$plan = CVD_Tarjeta_Carrera::money_plan( $order );
		$payment = $plan['payment'];
		$products = CVD_Tarjeta_Carrera::money_text( $plan['products'] );
		if ( $plan['prepaid'] ) {
			$charge = 'Nada de productos: ya pagó por ' . ( $payment ? $payment['label'] : 'adelantado' ) . '.';
			$short = 'Nada';
		} elseif ( $plan['unknown'] ) {
			$charge = ( $payment ? $payment['text'] : 'Productos' ) . ' — pregunta la tasa en la tienda.';
			$short = 'Pregunta';
		} else {
			$how = array( 'transfer_cup' => ' por transferencia a la tarjeta de Casa Viva (pide la captura)', 'clasica' => ' por Tarjeta Clásica en el momento (pide la captura)', 'cash_usd' => ' en efectivo', 'cash_cup' => ' en efectivo', 'eur' => ' en efectivo' )[ $plan['kind'] ] ?? '';
			$charge = $products . $how;
			$short = $products;
		}
		$bring = CVD_Tarjeta_Carrera::money_text( $plan['bring'] );
		if ( $plan['unknown'] ) { $bring = 'Lo que cobres' . ( $plan['change'] ? ' + ' . CVD_Tarjeta_Carrera::money_text( $plan['change'] ) . ' del vuelto' : '' ); }
		$cash_products = $plan['products'] && ! $plan['transfer'] && ! $plan['prepaid'];
		$detail = $plan['change'] && ! $plan['unknown'] ? ( $cash_products ? $products . ' de productos + ' : '' ) . CVD_Tarjeta_Carrera::money_text( $plan['change'] ) . ' del vuelto que te dio la tienda' : '';
		$fee = (int) $plan['fee'];
		return array(
			'after'       => false,
			'prepaid'     => (bool) $plan['prepaid'],
			'unknown'     => (bool) $plan['unknown'],
			'transfer'    => (bool) $plan['transfer'],
			'kind'        => (string) $plan['kind'],
			'label'       => $payment ? (string) $payment['label'] : '',
			'currency'    => $payment ? (string) $payment['currency'] : 'USD',
			'products'    => $plan['products'],
			'productsText'=> $products,
			'change'      => $plan['change'],
			'changeText'  => CVD_Tarjeta_Carrera::money_text( $plan['change'] ),
			'payWith'     => (string) $plan['pay_with'],
			'charge'      => $charge,
			'chargeShort' => $short,
			'bring'       => '' !== $bring ? $bring : 'Nada',
			'bringDetail' => $detail,
			'deadline'    => '' !== $bring ? 'Antes de las 4:00 p. m.' : 'La mensajería es tuya.',
			'fee'         => $fee,
			'feeText'     => $fee > 0 ? self::money( (float) $fee, 'CUP' ) : 'por confirmar con la tienda',
		);
	}

	/** Dinero DESPUÉS de entregar: lo que quedó anotado (bring_after()). */
	private static function money_after( WC_Order $order ): array {
		$after = CVD_Tarjeta_Carrera::bring_after( $order );
		$fee = self::fee( $order );
		$bring = CVD_Tarjeta_Carrera::money_text( $after['bring'] );
		return array(
			'after'    => true,
			'prepaid'  => (bool) $after['prepaid'],
			'bring'    => '' !== $bring ? $bring : 'Nada',
			'sentence' => CVD_Tarjeta_Carrera::bring_sentence( $after, self::delivered_ts( $order ) ),
			'deadline' => CVD_Messenger_Delivered_Link::deadline( self::delivered_ts( $order ) ),
			'fee'      => $fee,
			'feeText'  => $fee > 0 ? self::money( (float) $fee, 'CUP' ) : '',
		);
	}

	/** Inicio de hoy en Cuba, en UTC 'Y-m-d H:i:s' (las fechas de los pedidos están en UTC). */
	private static function today_start_utc(): string {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( 'America/Havana' ) );
		return $today->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function today_label( int $delivered ): string {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) );
		$days = array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' );
		$months = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
		$count = 1 === $delivered ? '1 carrera entregada' : $delivered . ' carreras entregadas';
		return $days[ (int) $now->format( 'w' ) ] . ' ' . $now->format( 'j' ) . ' ' . $months[ (int) $now->format( 'n' ) - 1 ] . ' · ' . $count;
	}

	/** Mi dinero de hoy: mensajería ganada y lo que traigo a la tienda (lo entregado hoy, sin «Dinero recibido»). */
	public static function money_today( WP_User $me ): array {
		$since = self::today_start_utc();
		$orders = wc_get_orders( array( 'limit' => 60, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'date_modified' => '>=' . ( time() - 2 * DAY_IN_SECONDS ), 'meta_key' => '_cvd_messenger_user_id', 'meta_value' => $me->ID ) );
		$rows = array();
		$earned = 0;
		$pending = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			$at = (string) $order->get_meta( '_cvd_delivered_at', true );
			if ( '' === $at || $at < $since || CVD_Tarjeta_Carrera::is_test( $order ) ) { continue; }
			if ( ! in_array( CVD_Tarjeta_Carrera::stage( $order, $me->ID ), array( 'delivered', 'closed' ), true ) ) { continue; }
			$fee = self::fee( $order );
			$earned += $fee;
			$bring = CVD_Tarjeta_Carrera::bring_after( $order )['bring'];
			$received = '' !== CVD_Messenger_Cash::received_at( $order ) || in_array( (string) $order->get_meta( '_cvd_cash_status', true ), array( 'returned', 'verified' ), true );
			$owes = $bring && ! $received;
			if ( $owes ) { $pending = CVD_Tarjeta_Carrera::add( $pending, $bring ); }
			$rows[] = array(
				'id'      => $order->get_id(),
				'number'  => (string) $order->get_order_number(),
				'zone'    => CVD_Delivery::destination_zone( $order ),
				'feeText' => $fee > 0 ? self::money( (float) $fee, 'CUP' ) : '—',
				'cash'    => CVD_Tarjeta_Carrera::money_text( $bring ) ?: 'Nada que llevar',
				'pending' => $owes,
				'at'      => $at,
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['at'], $b['at'] ) );
		return array(
			'label'       => self::today_label( count( $rows ) ),
			'delivered'   => count( $rows ),
			'earned'      => $earned,
			'earnedText'  => number_format( $earned, 0, ',', '.' ),
			'pending'     => $pending,
			'pendingText' => CVD_Tarjeta_Carrera::money_text( $pending ),
			'deadline'    => CVD_Messenger_Delivered_Link::deadline( time() ),
			'rows'        => $rows,
		);
	}

	// ------------------------------------------------------------------ Ubicación del cliente (3.14.69.17)
	// Ernesto 27-oct: «que la app lo lleve a la ubicación del cliente». Casi ningún pedido trae coordenadas (1 de 39), así
	// que al tocar «Entregado» la app manda el GPS del mensajero (en la puerta del cliente) y se guarda en el pedido
	// (_cvd_entrega_geo). La próxima carrera del mismo teléfono y la misma dirección ya trae el punto exacto para «Ir».
	public const GEO_META = '_cvd_entrega_geo';

	private static function save_geo( int $order_id, WP_REST_Request $request ): void {
		$lat = (float) $request->get_param( 'lat' );
		$lon = (float) $request->get_param( 'lon' );
		$acc = absint( $request->get_param( 'acc' ) );
		if ( ! $lat || ! $lon || $lat < 19.5 || $lat > 23.5 || $lon < -85.2 || $lon > -74 || ! $acc || $acc > 200 ) { return; } // solo Cuba y con buena precisión
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) { return; }
		$order->update_meta_data( self::GEO_META, wp_json_encode( array( 'lat' => round( $lat, 6 ), 'lon' => round( $lon, 6 ), 'acc' => $acc, 'at' => gmdate( 'c' ) ) ) );
		$order->save_meta_data();
	}

	private static function geo_of( WC_Order $order ): ?array {
		$geo = json_decode( (string) $order->get_meta( self::GEO_META, true ), true );
		return is_array( $geo ) && isset( $geo['lat'], $geo['lon'] ) ? $geo : null;
	}

	private static function same_address( WC_Order $a, WC_Order $b ): bool {
		$norm = static fn( WC_Order $o ): string => preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) ( $o->get_shipping_address_1() ?: $o->get_billing_address_1() ) ) ) );
		return '' === $norm( $a ) || $norm( $a ) === $norm( $b );
	}

	/** El mapa de la carrera: el del pedido, o el GPS de su entrega, o el de otra entrega al mismo cliente y dirección. */
	private static function map_for( WC_Order $order ): array {
		$url = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_map_url( $order ) : '';
		if ( '' !== $url ) { return array( $url, 'pedido' ); }
		$osm = static fn( array $g ): string => 'https://www.openstreetmap.org/?mlat=' . $g['lat'] . '&mlon=' . $g['lon'] . '#map=18/' . $g['lat'] . '/' . $g['lon'];
		$own = self::geo_of( $order );
		if ( $own ) { return array( $osm( $own ), 'entrega' ); }
		$phone = substr( preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ), -8 );
		if ( 8 === strlen( $phone ) ) {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND phone LIKE %s AND order_id<>%d ORDER BY order_id DESC LIMIT 15", '%' . $wpdb->esc_like( $phone ), $order->get_id() ) );
			foreach ( array_map( 'absint', (array) $ids ) as $id ) {
				$other = wc_get_order( $id );
				if ( ! $other instanceof WC_Order || substr( preg_replace( '/\D+/', '', (string) $other->get_billing_phone() ), -8 ) !== $phone ) { continue; }
				$geo = self::geo_of( $other );
				if ( $geo && self::same_address( $order, $other ) ) { return array( $osm( $geo ), 'cliente' ); }
			}
		}
		// 3.14.78: si nadie mandó el punto, sale de la dirección (las entrecalles en el mapa libre). Aproximado: la cuadra.
		$auto = 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) ? CVD_Geo_Direccion::for_order( $order ) : null;
		return $auto ? array( $osm( $auto ), 'auto' ) : array( '', '' );
	}

	/** El punto aproximado sacado de la dirección (para el seguimiento: la gestora o el cliente lo corrigen). */
	public static function auto_point( WC_Order $order ): ?array {
		return 'auto' === self::map_for( $order )[1] ? self::coords( self::map_for( $order )[0] ) : null;
	}

	// ------------------------------------------------------------------ Recorrido y «Llegué» (3.14.71.2)
	// Ernesto 28-oct: «como Mandao»: desde que coge la carrera se ve por dónde va (a la tienda y luego al cliente). La app
	// manda puntos (cada 30 s si se mueve; sin señal los guarda y los manda al volver) y se guardan en cada carrera abierta
	// suya (_cvd_trail, [[lat, lon, t], …], máx. 720). Si está a 120 m o menos del punto exacto del cliente con la carrera en
	// camino, «Llegué» se marca solo y la gestora recibe el aviso. Solo durante sus carreras: fuera, la app no manda nada.
	public const TRAIL_META = '_cvd_trail';

	/** «…?mlat=23.1&mlon=-82.3…» → [23.1, -82.3] o null. */
	public static function coords( string $url ): ?array {
		return preg_match( '/mlat=(-?\d+(?:\.\d+)?)&mlon=(-?\d+(?:\.\d+)?)/', $url, $m ) ? array( (float) $m[1], (float) $m[2] ) : null;
	}

	/** Distancia en metros (haversine). */
	public static function meters( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$r = 6371000.0;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlon = deg2rad( $lon2 - $lon1 );
		$a = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
		return 2 * $r * asin( min( 1.0, sqrt( $a ) ) );
	}

	// ------------------------------------------------------------------ Punto exacto del cliente (3.14.74.3, fase 3)

	/** ¿Se puede (aún) guardar el punto de este pedido? A domicilio y sin entregar ni cancelar. */
	private static function point_open( WC_Order $order ): bool {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return false; }
		if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return false; }
		return ! in_array( CVD_Delivery::status( $order ), array( 'delivered', 'cash_returned', 'closed', 'cancelled' ), true );
	}

	/** Guarda el punto en _cvd_map_url (lo leen el vale, el mensajero, «Ir 📍» y el mapa). $from: gestora | cliente. */
	public static function set_client_point( WC_Order $order, float $lat, float $lon, string $from ): bool {
		if ( $lat < 19.5 || $lat > 23.5 || $lon < -85.2 || $lon > -74 || ! self::point_open( $order ) ) { return false; }
		$lat = round( $lat, 6 ); $lon = round( $lon, 6 );
		$order->update_meta_data( '_cvd_map_url', 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=18/' . $lat . '/' . $lon );
		$order->update_meta_data( '_cvd_map_from', 'cliente' === $from ? 'cliente' : 'gestora' );
		$order->add_order_note( '📍 Ubicación exacta del cliente guardada (' . ( 'cliente' === $from ? 'la marcó el cliente en el seguimiento' : 'la mandó la gestora por WhatsApp' ) . ').' );
		$order->save();
		return true;
	}

	/**
	 * POST /bot/gestora/ubicacion {phone, lat, lon, order?} → la gestora reenvió la ubicación de su cliente.
	 * Sin order: su único pedido abierto a domicilio (de los últimos 3 días). Con varios: { result: choose, orders: [...] }.
	 * → { result: saved | choose | none | not_gestora | not_yours | closed, number?, orders? }
	 */
	public static function gestora_point( WP_REST_Request $request ): WP_REST_Response {
		$gestora = class_exists( 'CVD_Vale_Chat' ) ? CVD_Vale_Chat::gestora_by_phone( (string) $request->get_param( 'phone' ) ) : null;
		if ( ! $gestora ) { return self::out( array( 'result' => 'not_gestora' ) ); }
		$lat = (float) $request->get_param( 'lat' );
		$lon = (float) $request->get_param( 'lon' );
		$mine = static fn( WC_Order $o ): bool => 'gestora' === sanitize_key( (string) $o->get_meta( '_cvd_owner_type', true ) ) && absint( $o->get_meta( '_cvd_owner_user_id', true ) ) === $gestora->ID;
		$wanted = absint( $request->get_param( 'order' ) );
		if ( $wanted ) {
			$order = wc_get_order( $wanted );
			if ( ! $order instanceof WC_Order || ! $mine( $order ) ) { return self::out( array( 'result' => 'not_yours', 'number' => (string) $wanted ) ); }
			if ( ! self::set_client_point( $order, $lat, $lon, 'gestora' ) ) { return self::out( array( 'result' => 'closed', 'number' => (string) $order->get_order_number() ) ); }
			return self::out( array( 'result' => 'saved', 'number' => (string) $order->get_order_number() ) );
		}
		$open = array();
		foreach ( wc_get_orders( array( 'limit' => 30, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'date_created' => '>=' . ( time() - 3 * DAY_IN_SECONDS ), 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora->ID ) ) as $o ) {
			if ( $o instanceof WC_Order && ! $o instanceof WC_Order_Refund && $mine( $o ) && ! CVD_Tarjeta_Carrera::is_test( $o ) && self::point_open( $o ) ) { $open[] = $o; }
		}
		if ( ! $open ) { return self::out( array( 'result' => 'none' ) ); }
		if ( count( $open ) > 1 ) {
			return self::out( array( 'result' => 'choose', 'orders' => array_map( static fn( WC_Order $o ): array => array( 'id' => $o->get_id(), 'number' => (string) $o->get_order_number(), 'client' => self::first( (string) $o->get_billing_first_name() ), 'zone' => CVD_Delivery::destination_zone( $o ) ), array_slice( $open, 0, 6 ) ) ) );
		}
		if ( ! self::set_client_point( $open[0], $lat, $lon, 'gestora' ) ) { return self::out( array( 'result' => 'closed', 'number' => (string) $open[0]->get_order_number() ) ); }
		return self::out( array( 'result' => 'saved', 'number' => (string) $open[0]->get_order_number() ) );
	}

	/** POST /delivery/{id}/casa {key, lat, lon}: el cliente marca su casa desde su enlace de seguimiento (con la clave). */
	public static function client_point( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( absint( $request['id'] ) );
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			$r = self::out( array( 'ok' => false, 'message' => 'Enlace no válido.' ) );
			$r->set_status( 403 );
			return $r;
		}
		$ok = self::set_client_point( $order, (float) $request->get_param( 'lat' ), (float) $request->get_param( 'lon' ), 'cliente' );
		return self::out( array( 'ok' => $ok, 'message' => $ok ? '¡Gracias! El mensajero irá directo a ese punto.' : 'Ya no se puede cambiar (el pedido está entregado) o el punto no está en Cuba.' ) );
	}

	/** Para el seguimiento: ¿enseñar «Marca tu casa»? (a domicilio, abierto y todavía sin punto de la gestora o del cliente). */
	public static function needs_client_point( WC_Order $order ): bool {
		return self::point_open( $order ) && ! in_array( (string) $order->get_meta( '_cvd_map_from', true ), array( 'gestora', 'cliente' ), true );
	}

	/**
	 * 3.14.72.2 (fase 2 del mapa): lo que pinta el mapa del seguimiento (gestora y cliente) y el de la tienda. Solo con la
	 * carrera en curso (va a la tienda o va al cliente): entregada o sin mensajero, no se enseña dónde está.
	 * → null | { active, stage: tienda|cliente, name, pos: {lat, lon, ago}, trail: [[lat, lon]…], store, dest, arrived, eta }
	 */
	public static function route_view( WC_Order $order ): ?array {
		$uid = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $uid ) { return null; }
		$stage = CVD_Tarjeta_Carrera::stage( $order, $uid );
		if ( ! in_array( $stage, array( 'pickup', 'route' ), true ) ) { return array( 'active' => false, 'delivered' => in_array( $stage, array( 'delivered', 'closed' ), true ) ); }
		$user = get_userdata( $uid );
		$trail = json_decode( (string) $order->get_meta( self::TRAIL_META, true ), true );
		$trail = is_array( $trail ) ? array_slice( $trail, -300 ) : array();
		$last = $trail ? end( $trail ) : null;
		$store = array_map( 'floatval', array_slice( explode( ',', (string) get_option( 'cvd_palla_tienda_geo', '23.11504,-82.39749' ) ), 0, 2 ) );
		$dest = self::coords( self::map_for( $order )[0] );
		$target = 'pickup' === $stage ? $store : $dest;
		$eta = null;
		if ( $last && $target && count( $target ) === 2 ) {
			// En moto por La Habana: unos 300 m por minuto, y las calles alargan la línea recta un 35 %.
			$eta = max( 1, (int) round( self::meters( (float) $last[0], (float) $last[1], $target[0], $target[1] ) * 1.35 / 300 ) );
		}
		return array(
			'active'  => true,
			'stage'   => 'pickup' === $stage ? 'tienda' : 'cliente',
			'name'    => $user ? self::first( (string) $user->display_name ) : 'El mensajero',
			'pos'     => $last ? array( 'lat' => (float) $last[0], 'lon' => (float) $last[1], 'ago' => max( 0, time() - (int) $last[2] ) ) : null,
			'trail'   => array_map( static fn( $p ): array => array( (float) $p[0], (float) $p[1] ), $trail ),
			'store'   => count( $store ) === 2 ? $store : null,
			'dest'    => $dest,
			'arrived' => '' !== (string) $order->get_meta( '_cvd_llegue_at', true ),
			'eta'     => $eta,
		);
	}

	/** POST palla/ubicacion {points: [{lat, lon, acc, t}]} → { ok, saved, arrived: [números] }. */
	public static function track( WP_REST_Request $request ): WP_REST_Response {
		$me = CVD_Palla::request_user( $request );
		$now = time();
		$pts = array();
		foreach ( (array) $request->get_param( 'points' ) as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$lat = (float) ( $p['lat'] ?? 0 );
			$lon = (float) ( $p['lon'] ?? 0 );
			$t = absint( $p['t'] ?? 0 );
			$acc = absint( $p['acc'] ?? 0 );
			if ( $lat < 19.5 || $lat > 23.5 || $lon < -85.2 || $lon > -74 || $acc > 150 || $t > $now + 120 || $t < $now - 12 * HOUR_IN_SECONDS ) { continue; }
			$pts[] = array( round( $lat, 5 ), round( $lon, 5 ), $t );
		}
		usort( $pts, static fn( array $a, array $b ): int => $a[2] <=> $b[2] );
		$pts = array_slice( $pts, -240 );
		if ( ! $pts ) { return self::out( array( 'ok' => true, 'saved' => 0, 'arrived' => array() ) ); }
		$last = end( $pts );
		update_user_meta( $me->ID, '_cvd_palla_pos', wp_json_encode( array( 'lat' => $last[0], 'lon' => $last[1], 't' => $last[2] ) ) );
		$arrived = array();
		$saved = 0;
		foreach ( self::my_open_orders( $me ) as $order ) {
			$trail = json_decode( (string) $order->get_meta( self::TRAIL_META, true ), true );
			$trail = is_array( $trail ) ? $trail : array();
			$known = $trail ? (int) ( end( $trail )[2] ?? 0 ) : 0;
			foreach ( $pts as $p ) { if ( $p[2] > $known ) { $trail[] = $p; $saved++; } }
			$order->update_meta_data( self::TRAIL_META, wp_json_encode( array_slice( $trail, -720 ) ) );
			$order->save_meta_data(); // solo el dato: sin los avisos de «pedido guardado»
			if ( 'route' === CVD_Tarjeta_Carrera::stage( $order, $me->ID ) && '' === (string) $order->get_meta( '_cvd_llegue_at', true ) ) {
				$map = self::map_for( $order );
				$dest = 'auto' !== $map[1] ? self::coords( $map[0] ) : null; // con el punto aproximado, «Llegué» lo toca él
				if ( $dest && self::meters( $last[0], $last[1], $dest[0], $dest[1] ) <= 120 && CVD_Tarjeta_Carrera::arrived( $order, $me, 'auto' ) ) {
					$arrived[] = (string) $order->get_order_number();
				}
			}
		}
		return self::out( array( 'ok' => true, 'saved' => $saved, 'arrived' => $arrived ) );
	}

	/**
	 * GET palla/historial?dias=31 (7–62): mis carreras entregadas por día (hora de Cuba), y lo ganado hoy, esta semana
	 * (desde el lunes) y este mes. Mismas cuentas que money_today(); solo lectura.
	 */
	public static function history( WP_REST_Request $request ): WP_REST_Response {
		$me = CVD_Palla::request_user( $request );
		$days = min( 62, max( 7, absint( $request->get_param( 'dias' ) ?: 31 ) ) );
		$tz = new DateTimeZone( 'America/Havana' );
		$now = new DateTimeImmutable( 'now', $tz );
		$from = $now->setTime( 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' );
		$week = $now->setTime( 0, 0 )->modify( '-' . ( ( (int) $now->format( 'N' ) ) - 1 ) . ' days' )->format( 'Y-m-d' );
		$month = $now->format( 'Y-m-01' );
		$today = $now->format( 'Y-m-d' );
		$orders = wc_get_orders( array( 'limit' => 500, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'date_created' => '>=' . ( $from->getTimestamp() - 7 * DAY_IN_SECONDS ), 'meta_key' => '_cvd_messenger_user_id', 'meta_value' => $me->ID ) );
		$by_day = array();
		$totals = array( 'hoy' => array( 0, 0 ), 'semana' => array( 0, 0 ), 'mes' => array( 0, 0 ) );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || CVD_Tarjeta_Carrera::is_test( $order ) ) { continue; }
			$at = (string) $order->get_meta( '_cvd_delivered_at', true );
			if ( '' === $at || ! in_array( CVD_Tarjeta_Carrera::stage( $order, $me->ID ), array( 'delivered', 'closed' ), true ) ) { continue; }
			$day = ( new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( $day < $from->format( 'Y-m-d' ) ) { continue; }
			$fee = self::fee( $order );
			$bring = CVD_Tarjeta_Carrera::bring_after( $order )['bring'];
			$received = '' !== CVD_Messenger_Cash::received_at( $order ) || in_array( (string) $order->get_meta( '_cvd_cash_status', true ), array( 'returned', 'verified' ), true );
			$by_day[ $day ][] = array(
				'id'      => $order->get_id(),
				'number'  => (string) $order->get_order_number(),
				'zone'    => CVD_Delivery::destination_zone( $order ),
				'feeText' => $fee > 0 ? self::money( (float) $fee, 'CUP' ) : '—',
				'fee'     => $fee,
				'cash'    => CVD_Tarjeta_Carrera::money_text( $bring ),
				'pending' => $bring && ! $received,
				'at'      => $at,
			);
			foreach ( array( 'hoy' => $today, 'semana' => $week, 'mes' => $month ) as $k => $start ) {
				if ( $day >= $start ) { $totals[ $k ][0]++; $totals[ $k ][1] += $fee; }
			}
		}
		krsort( $by_day );
		$week_days = array( 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' );
		$months = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
		$list = array();
		foreach ( $by_day as $day => $rows ) {
			usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['at'], $b['at'] ) );
			$d = new DateTimeImmutable( $day, $tz );
			$earned = array_sum( array_column( $rows, 'fee' ) );
			$list[] = array(
				'day'        => $day,
				'label'      => $day === $today ? 'Hoy' : $week_days[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'j' ) . ' ' . $months[ (int) $d->format( 'n' ) - 1 ],
				'count'      => count( $rows ),
				'earnedText' => self::money( (float) $earned, 'CUP' ),
				'rows'       => array_map( static function ( array $r ): array { unset( $r['fee'], $r['at'] ); return $r; }, $rows ),
			);
		}
		$out = array( 'ok' => true, 'days' => $list );
		foreach ( $totals as $k => $t ) { $out[ $k ] = array( 'count' => $t[0], 'earnedText' => self::money( (float) $t[1], 'CUP' ) ); }
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// ------------------------------------------------------------------ Idempotencia (cola sin conexión)

	private static function cid( WP_REST_Request $request ): string {
		return substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $request->get_param( 'cid' ) ), 0, 40 );
	}

	private static function replay( int $user_id, string $cid ): ?array {
		if ( '' === $cid ) { return null; }
		$saved = get_user_meta( $user_id, self::CIDS_META, true );
		return is_array( $saved ) && isset( $saved[ $cid ]['r'] ) && is_array( $saved[ $cid ]['r'] ) ? $saved[ $cid ]['r'] + array( 'replay' => true ) : null;
	}

	private static function remember( int $user_id, string $cid, array $response ): void {
		if ( '' === $cid ) { return; }
		$saved = get_user_meta( $user_id, self::CIDS_META, true );
		$saved = is_array( $saved ) ? $saved : array();
		unset( $saved[ $cid ] );
		$saved[ $cid ] = array( 't' => time(), 'r' => $response );
		update_user_meta( $user_id, self::CIDS_META, array_slice( $saved, -50, null, true ) );
	}

	private static function order_param( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order' ) ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && 'shop_order' === $order->get_type() ? $order : null;
	}

	// ------------------------------------------------------------------ POST cojo

	/** «La cojo» = el «Yo» del grupo. Sin conexión la app NO la pone en cola (otro puede cogerla antes). */
	public static function claim( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found', 'message' => 'No encuentro esa carrera.' ) ); }
		$number = (string) $order->get_order_number();
		$r = CVD_Bot_Bridge::claim_by_messenger( $order, $me, 'palla' );
		$code = (string) ( $r['result'] ?? 'taken' );
		$messages = array(
			'won'   => '✅ #' . $number . ' es tuya. Ve a recogerla a la tienda.',
			'yours' => '#' . $number . ' ya era tuya.',
			'taken' => '#' . $number . ' ya la cogió otro.',
		);
		$out = array( 'result' => $code, 'number' => $number, 'message' => $messages[ $code ] ?? $messages['taken'] );
		if ( in_array( $code, array( 'won', 'yours' ), true ) ) { $out['carrera'] = self::card( wc_get_order( $order->get_id() ), $me ); }
		self::remember( $me->ID, $cid, $out );
		return self::out( $out );
	}

	// ------------------------------------------------------------------ POST hora

	/**
	 * A qué hora lo entrega (obligatorio en la app antes de «Recogí»). Igual que CVD_Bot_Bridge::eta (la hora por WhatsApp):
	 * queda en el pedido y en el seguimiento, y si la entrega estaba «asignada» (directa), darla es aceptarla.
	 */
	public static function eta( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found', 'message' => self::message( 'not_found', '' ) ) ); }
		$number = (string) $order->get_order_number();
		$text = trim( sanitize_text_field( (string) $request->get_param( 'texto' ) ) );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 80 ) : substr( $text, 0, 80 );
		$stage = CVD_Tarjeta_Carrera::stage( $order, $me->ID );
		if ( CVD_Tarjeta_Carrera::is_test( $order ) ) { $out = array( 'result' => 'test', 'number' => $number, 'message' => self::message( 'test', $number ) ); }
		elseif ( ! in_array( $stage, array( 'pickup', 'route', 'incident' ), true ) ) { $out = array( 'result' => 'not_now', 'number' => $number, 'message' => self::message( 'not_now', $number ) ); }
		elseif ( strlen( $text ) < 2 ) { $out = array( 'result' => 'eta_empty', 'number' => $number, 'message' => 'Elige a qué hora lo entregas.' ); }
		else {
			$now = current_time( 'mysql', true );
			$order->update_meta_data( '_cvd_bot_eta', $text );
			$order->update_meta_data( '_cvd_bot_eta_at', $now );
			$order->update_meta_data( '_cvd_palla_eta_at', $now );
			$order->update_meta_data( '_cvd_palla_bot', '1' ); // el bot lo ve en /bot/dispatch/palla
			$order->add_order_note( 'Pa\'llá: ' . $me->display_name . ' lo entrega ' . $text . '.' );
			$order->save();
			if ( 'assigned' === CVD_Delivery::status( $order ) ) { CVD_Delivery::accept_assigned( $order, $me->ID, 'cvd_palla_eta', 'en Pa\'llá al dar la hora' ); }
			$out = array( 'result' => 'eta', 'number' => $number, 'message' => '🕒 #' . $number . ': anotado, lo entregas ' . $text . '. Se lo decimos a quien lo espera.' );
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof WC_Order && in_array( CVD_Tarjeta_Carrera::stage( $fresh, $me->ID ), array( 'pickup', 'route', 'incident' ), true ) ) { $out['carrera'] = self::card( $fresh, $me ); }
		self::remember( $me->ID, $cid, $out );
		return self::out( $out );
	}

	// ------------------------------------------------------------------ POST carrera

	/** Textos tras cada botón (los mismos que la tarjeta de carrera, para el teléfono). */
	public static function message( string $code, string $number ): string {
		return array(
			'picked'          => '✅ #' . $number . ': anotado, ya tienes el paquete. Ahora llévalo al cliente.',
			'already_picked'  => '#' . $number . ': ya constaba que tienes el paquete.',
			'delivered'       => '✅ ¡#' . $number . ' entregado! La mensajería es tuya.',
			'already'         => '#' . $number . ' ya constaba como entregado.',
			'needs_amount'    => '#' . $number . ': escribe cuánto cobraste de los productos (USD o CUP).',
			'problem'         => '⚠️ #' . $number . ': avisado. La tienda ya lo sabe y te llama o te escribe. No lo marques entregado hasta que te digan.',
			'problem_already' => '#' . $number . ': ya había un problema avisado. La tienda lo está viendo.',
			'problem_empty'   => 'Elige qué pasó (y si es «Otro problema», escríbelo en una frase).',
			'released'        => 'Soltaste #' . $number . '. Vuelve a estar libre para otro mensajero. ¡Gracias por avisar!',
			'too_late'        => '#' . $number . ': ya tienes el paquete, no se puede soltar. Si no puedes entregarlo, toca «Problema».',
			'test'            => '#' . $number . ' es un pedido de PRUEBA: no se cambió nada.',
			'not_now'         => '#' . $number . ': eso ya no se puede en este paso del pedido.',
			'not_found'       => 'No encuentro ese pedido.',
			'error'           => '#' . $number . ': no se pudo guardar ahora. Toca otra vez en un momento.',
			'arrived'         => '📍 #' . $number . ': avisamos a quien lo vendió de que ya estás allí.', // 3.14.71.2
			'already_arrived' => '#' . $number . ': ya constaba que llegaste.',
		)[ $code ] ?? '#' . $number . ': no se pudo guardar ahora.';
	}

	public static function action( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! $order || ! in_array( $action, array( 'recogi', 'entregado', 'problema', 'suelto', 'llegue' ), true ) ) {
			return self::out( array( 'result' => 'not_found', 'message' => self::message( 'not_found', '' ) ) );
		}
		$id = $order->get_id();
		$number = (string) $order->get_order_number();
		// 3.14.71.2: «Llegué» (botón de la app; el automático llega por palla/ubicacion).
		if ( 'llegue' === $action ) {
			$code = CVD_Tarjeta_Carrera::is_test( $order ) ? 'test' : ( CVD_Tarjeta_Carrera::arrived( $order, $me, 'boton' ) ? 'arrived' : ( '' !== (string) $order->get_meta( '_cvd_llegue_at', true ) ? 'already_arrived' : 'not_now' ) );
			$out = array( 'result' => $code, 'action' => $action, 'number' => $number, 'message' => self::message( $code, $number ) );
			$fresh = wc_get_order( $id );
			if ( $fresh instanceof WC_Order ) { $out['carrera'] = self::card( $fresh, $me ); }
			self::remember( $me->ID, $cid, $out );
			return self::out( $out );
		}
		// Lo mismo que manda el formulario de la tarjeta (CVD_Tarjeta_Carrera::actions), con 4 argumentos: vale con y sin 3.14.59.
		$post = array(
			'cvd_otra'   => rest_sanitize_boolean( $request->get_param( 'otra' ) ) ? '1' : '',
			'cvd_usd'    => (string) ( $request->get_param( 'usd' ) ?? '' ),
			'cvd_cup'    => (string) ( $request->get_param( 'cup' ) ?? '' ),
			'cvd_eur'    => (string) ( $request->get_param( 'eur' ) ?? '' ),
			'cvd_como'   => sanitize_key( (string) ( $request->get_param( 'como' ) ?? '' ) ),
			'cvd_motivo' => sanitize_key( (string) ( $request->get_param( 'motivo' ) ?? '' ) ),
			'cvd_texto'  => (string) ( $request->get_param( 'texto' ) ?? '' ),
			'cvd_n'      => $cid,
			'cvd_seguro' => 'suelto' === $action ? '1' : '',
		);
		$code = CVD_Tarjeta_Carrera::submit( $order, $me, $action, $post );
		$out = array( 'result' => $code, 'action' => $action, 'number' => $number, 'message' => self::message( $code, $number ) );
		if ( 'entregado' === $action && 'delivered' === $code ) { self::save_geo( $id, $request ); } // 3.14.69.17
		$fresh = wc_get_order( $id );
		if ( $fresh instanceof WC_Order ) {
			if ( in_array( $code, array( 'delivered', 'already' ), true ) ) { $out['message'] .= ' ' . CVD_Tarjeta_Carrera::bring_sentence( CVD_Tarjeta_Carrera::bring_after( $fresh ), self::delivered_ts( $fresh ) ); }
			$stage = CVD_Tarjeta_Carrera::stage( $fresh, $me->ID );
			if ( in_array( $stage, array( 'pickup', 'route', 'incident', 'delivered' ), true ) ) { $out['carrera'] = self::card( $fresh, $me ); }
			$out['stage'] = $stage;
		}
		// Un error de red de verdad se reintenta; un «no se pudo guardar» de Core no se queda grabado como hecho.
		if ( 'error' !== $code ) { self::remember( $me->ID, $cid, $out ); }
		return self::out( $out );
	}
}
