<?php

defined( 'ABSPATH' ) || exit;

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
	}

	/** @return true|WP_Error */
	public static function permission( WP_REST_Request $request ) {
		$user = CVD_Palla::request_user( $request );
		return is_wp_error( $user ) ? $user : true;
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
		) );
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
		return array(
			'id'       => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'stage'    => $stage,
			'test'     => CVD_Tarjeta_Carrera::is_test( $order ),
			'when'     => self::when( $order ),
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
			'map'      => class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_map_url( $order ) : '',
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
		)[ $code ] ?? '#' . $number . ': no se pudo guardar ahora.';
	}

	public static function action( WP_REST_Request $request ): WP_REST_Response {
		$me = self::user( $request );
		$cid = self::cid( $request );
		$replay = self::replay( $me->ID, $cid );
		if ( $replay ) { return self::out( $replay ); }
		$order = self::order_param( $request );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! $order || ! in_array( $action, array( 'recogi', 'entregado', 'problema', 'suelto' ), true ) ) {
			return self::out( array( 'result' => 'not_found', 'message' => self::message( 'not_found', '' ) ) );
		}
		$id = $order->get_id();
		$number = (string) $order->get_order_number();
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
