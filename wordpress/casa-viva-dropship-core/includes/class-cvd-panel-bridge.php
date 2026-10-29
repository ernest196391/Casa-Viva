<?php

defined( 'ABSPATH' ) || exit;

/**
 * Puerta del panel de Lennys (NEXO, casaviva.company/panel) hacia Core (D37: un solo panel).
 *
 * El panel no habla con WordPress directamente: lo hace la función nexo-panel-web de NEXO,
 * que antes comprueba que quien pide es dueña. Aquí solo se lee y se ejecutan las mismas
 * acciones que ya existen (aprobar gestoras como el bot, transiciones de CVD_Payouts).
 * Autenticación: cabecera X-Nexo-Panel-Key, comparada con su hash en la opción cvd_panel_key_hash.
 * Las acciones de pago quedan firmadas por el usuario de la opción cvd_panel_actor_user_id.
 */
final class CVD_Panel_Bridge {
	private const NS = 'casa-viva/v1';
	private const KEY_OPTION = 'cvd_panel_key_hash';
	private const ACTOR_OPTION = 'cvd_panel_actor_user_id';
	/** 3.13.97: la petición en curso viene del panel (y quién lo pulsó), para las notas del pedido. */
	private static bool $via = false;
	private static string $by = '';

	public static function via_panel(): bool { return self::$via; }
	public static function current_by(): string { return self::$by; }
	private static function enter( array $params ): void { self::$via = true; self::$by = mb_substr( sanitize_text_field( (string) ( $params['by'] ?? '' ) ), 0, 80 ); }

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( __CLASS__, 'authorized' );
		register_rest_route( self::NS, '/panel/gestoras', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestoras' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/gestoras/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/payouts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'payouts' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/payouts/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'payout_action' ), 'permission_callback' => $auth ) );
		// Pedidos: las mismas rutas del Centro de ventas (/sales), ejecutadas como la dueña.
		register_rest_route( self::NS, '/panel/sales', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'sales' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/sales/(?P<id>\d+)/(?P<what>status|return)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sales_action' ), 'permission_callback' => $auth ) );
		// Puente de existencias NEXO ↔ web (D37 fase 2: un solo stock). Solo movimientos, nunca totales a ciegas.
		register_rest_route( self::NS, '/panel/stock/adjust', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stock_adjust' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/stock/web-sales', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'web_sales' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/stock/edits-done', array( 'methods' => 'POST', 'callback' => static fn( WP_REST_Request $r ) => self::no_cache( CVD_Order_Edit::mark_caja_edits( (array) $r->get_param( 'items' ) ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/stock/levels', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stock_levels' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/price', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_price' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/sales/(?P<id>\d+)/edit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'edit_order' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/sales/(?P<id>\d+)/detail', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'order_detail' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/prices', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_prices' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/curru/status', array( 'methods' => 'GET', 'callback' => static fn() => self::no_cache( array( 'enabled' => class_exists( 'CVD_Curru_AI' ) && CVD_Curru_AI::enabled() ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/curru/invoice', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'curru_invoice' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/save', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_product' ), 'permission_callback' => $auth ) );
		// 3.13.52: diseños y colores (variaciones) y «Dejar de vender» desde el panel. Nada se borra.
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/variations', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'product_variations' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/variations', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add_variation' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/variable', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_variable' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/visibility', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_visibility' ), 'permission_callback' => $auth ) );
		// Lennys 29-oct: «Eliminar producto» / «Eliminar este diseño» (a la papelera; con pedidos, se oculta).
		register_rest_route( self::NS, '/panel/products/delete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'delete_product' ), 'permission_callback' => $auth ) );
		// 3.14.41 (Lennys 20-oct): descripción de un producto con diseños, foto principal (la misma de la web) y «Duplicar».
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/description', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_description' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/photo', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_main_photo' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/duplicate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'duplicate' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/copies', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'copies' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/copy', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'copy_read' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/copy-publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'copy_publish' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/copy-discard', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'copy_discard' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/messengers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'messengers' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/messengers/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients/bulk', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clients_bulk' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'clients' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'client_save' ), 'permission_callback' => $auth ) );
		// Tarifas de mensajería por zona (CUP): las mismas que usa el checkout y el reparto.
		register_rest_route( self::NS, '/panel/shipping-rates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'shipping_rates' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/shipping-rates', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'shipping_rate_save' ), 'permission_callback' => $auth ) );
		// Tarjetas de la tienda (3.13.50): la lista que sale en «¿A qué tarjeta entró?» y los cobros de la web por tarjeta.
		register_rest_route( self::NS, '/panel/pay-accounts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pay_accounts' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/pay-accounts', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pay_account_save' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/collections', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'collections' ), 'permission_callback' => $auth ) );
		// Tasa del día para las gestoras (3.13.51): la misma que VivaBot lee de «FORMAS DE PAGO»; la dueña la corrige aquí.
		register_rest_route( self::NS, '/panel/daily-rates', array( 'methods' => 'GET', 'callback' => static fn() => self::no_cache( array( 'rates' => CVD_Daily_Rates::public_data(), 'recent' => CVD_Daily_Rates::recent() ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/daily-rates', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'daily_rates_save' ), 'permission_callback' => $auth ) );
		// 3.13.55: «Dinero por entregar a la tienda»: qué dinero tiene cada mensajero (solo lo registrado) y «Recibido».
		register_rest_route( self::NS, '/panel/messenger-cash', array( 'methods' => 'GET', 'callback' => static fn() => self::no_cache( CVD_Messenger_Cash::report() ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/messenger-cash/(?P<id>\d+)/received', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'messenger_cash_received' ), 'permission_callback' => $auth ) );
	}

	/** «Recibido» desde el panel: firma el usuario del panel y se guarda el nombre de quien lo pulsó ({by}). */
	public static function messenger_cash_received( WP_REST_Request $request ) {
		$actor = absint( get_option( self::ACTOR_OPTION, 0 ) );
		if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma las acciones del panel.', array( 'status' => 500 ) );
		}
		$result = CVD_Messenger_Cash::mark_received( absint( $request['id'] ), $actor, self::by( (array) $request->get_json_params() ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	/** Cambia solo las tasas que lleguen (cup, eur, clasica, zelle, paypalPct); las demás se quedan. */
	public static function daily_rates_save( WP_REST_Request $request ) {
		$result = CVD_Daily_Rates::save( (array) $request->get_json_params(), 'panel' );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result + array( 'recent' => CVD_Daily_Rates::recent() ) );
	}

	/** Todas las tarjetas (también las desactivadas, para poder reactivarlas) y lo que sale al cerrar un cobro. */
	public static function pay_accounts(): WP_REST_Response {
		return self::no_cache( array( 'accounts' => CVD_Sales::payment_accounts(), 'choices' => CVD_Sales::account_choices() ) );
	}

	/** Añadir tarjeta (nombre + últimos 4) o cambiarla / activar-desactivar (con id). Nunca el número completo. */
	public static function pay_account_save( WP_REST_Request $request ) {
		$params = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'id', 'label', 'last4', 'active' ) ) );
		$result = CVD_Sales::save_payment_account( $params );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( array( 'accounts' => $result, 'choices' => CVD_Sales::account_choices() ) );
	}

	/**
	 * Cobros de la web que no fueron en efectivo (transferencia, Zelle, USDT, mixto u otro) entre dos días
	 * (Y-m-d, hora de la web), con a qué tarjeta entró y su número: para cuadrar con cada banco. Solo lectura.
	 */
	public static function collections( WP_REST_Request $request ): WP_REST_Response {
		$tz = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$from_raw = (string) $request->get_param( 'from' );
		$to_raw = (string) $request->get_param( 'to' );
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) ? new DateTimeImmutable( $from_raw . ' 00:00:00', $tz ) : $today->setTime( 0, 0 );
		$to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_raw ) ? new DateTimeImmutable( $to_raw . ' 23:59:59', $tz ) : $today->setTime( 23, 59, 59 );
		if ( $from < $today->modify( '-100 days' ) ) { $from = $today->modify( '-100 days' )->setTime( 0, 0 ); } // como la caja: 90 días y un margen
		$from_gmt = $from->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to_gmt = $to->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		// El cobro se guarda al cerrar, así que el pedido se modificó después: se filtra por fecha de cambio y luego por la hora del cobro.
		$orders = wc_get_orders( array( 'limit' => 400, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC', 'date_modified' => '>=' . $from->getTimestamp(), 'status' => array( 'completed', 'refunded' ) ) );
		$labels = array( 'transfer' => 'Transferencia', 'mixed' => 'Mixto', 'other' => 'Otro' );
		$rows = array();
		foreach ( $orders as $order ) {
			$method = sanitize_key( (string) $order->get_meta( '_cvd_collection_method', true ) );
			$at = (string) $order->get_meta( '_cvd_collection_received_at', true );
			if ( ! isset( $labels[ $method ] ) || '' === $at || $at < $from_gmt || $at > $to_gmt ) { continue; }
			$account = sanitize_key( (string) $order->get_meta( '_cvd_collection_account', true ) );
			$rows[] = array(
				'id' => $order->get_id(), 'number' => $order->get_order_number(), 'at' => get_date_from_gmt( $at, 'c' ),
				'method' => $method, 'methodLabel' => $labels[ $method ],
				'account' => $account, 'accountName' => (string) $order->get_meta( '_cvd_collection_account_name', true ),
				'reference' => (string) $order->get_meta( '_cvd_collection_reference', true ),
				'usd' => (float) $order->get_meta( '_cvd_collection_amount_usd', true ), 'cup' => (float) $order->get_meta( '_cvd_collection_amount_cup', true ),
				'customer' => $order->get_formatted_billing_full_name(), 'refunded' => $order->has_status( 'refunded' ),
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['at'], $a['at'] ) );
		return self::no_cache( array( 'from' => $from->format( 'Y-m-d' ), 'to' => $to->format( 'Y-m-d' ), 'items' => $rows ) );
	}

	/** Líneas y datos del pedido para «Cambiar pedido» del panel (3.13.97: los mismos que /ventas/, CVD_Order_Edit). */
	public static function order_detail( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_panel_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		return self::no_cache( CVD_Order_Edit::detail( $order, absint( get_option( self::ACTOR_OPTION, 0 ) ) ) );
	}

	/**
	 * Cambiar un pedido desde el panel: nombre, teléfono, dirección, día y franja, nota, cantidades (0 = quitar) y añadir.
	 * La lógica es la de /ventas/ (CVD_Order_Edit::edit); firma la usuaria del panel y la nota dice quién lo pulsó.
	 * «stock» = diferencias de líneas ya descontadas: la función nexo-panel-web las aplica en la caja.
	 */
	public static function edit_order( WP_REST_Request $request ) {
		$actor = absint( get_option( self::ACTOR_OPTION, 0 ) );
		if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma las acciones del panel.', array( 'status' => 500 ) );
		}
		$p = (array) $request->get_json_params();
		self::enter( $p );
		wp_set_current_user( $actor );
		$data = array_intersect_key( $p, array_flip( array( 'firstName', 'lastName', 'phone', 'address', 'deliveryDate', 'deliveryWindow', 'note', 'lines', 'add', 'changeRequired' ) ) );
		// El panel viejo mandaba phone/address vacíos cuando no cambiaban: vacío = no se toca.
		foreach ( array( 'phone', 'address' ) as $k ) { if ( isset( $data[ $k ] ) && '' === trim( (string) $data[ $k ] ) ) { unset( $data[ $k ] ); } }
		$result = CVD_Order_Edit::edit( absint( $request['id'] ), $data, $actor, 'panel' );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	/** Precios en masa desde el panel: [{wooId, regular, sale|null}]. sale = precio de oferta (null la quita). */
	public static function set_prices( WP_REST_Request $request ): WP_REST_Response {
		$done = array();
		foreach ( array_slice( (array) $request->get_param( 'items' ), 0, 500 ) as $it ) {
			$product = wc_get_product( absint( $it['wooId'] ?? 0 ) );
			$regular = (float) ( $it['regular'] ?? 0 );
			$sale = isset( $it['sale'] ) && null !== $it['sale'] ? (float) $it['sale'] : null;
			if ( ! $product || $regular <= 0 || ( null !== $sale && $sale >= $regular ) ) { $done[] = array( 'wooId' => absint( $it['wooId'] ?? 0 ), 'ok' => false ); continue; }
			$product->set_regular_price( wc_format_decimal( $regular, 2 ) );
			$product->set_sale_price( null === $sale ? '' : wc_format_decimal( $sale, 2 ) );
			$product->save();
			$done[] = array( 'wooId' => $product->get_id(), 'ok' => true );
		}
		return self::no_cache( array( 'items' => $done ) );
	}

	/** Curru lee una factura para "Llegó mercancía" del panel (misma IA que la tienda). */
	public static function curru_invoice( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$image = (string) ( $p['image'] ?? '' );
		if ( strlen( $image ) > 1500000 ) { return new WP_Error( 'cvd_curru', 'Foto demasiado grande.', array( 'status' => 413 ) ); }
		$data = class_exists( 'CVD_Curru_AI' ) ? CVD_Curru_AI::read_image( (string) ( $p['system'] ?? '' ), $image ) : null;
		if ( null === $data ) { return new WP_Error( 'cvd_curru', 'Curru no pudo leerla.', array( 'status' => 502 ) ); }
		return self::no_cache( $data );
	}

	/** Mensajeros: estado (aprobar/rechazar con la misma lógica que gestoras), zona, vehículo, disponibilidad, carreras y saldo. */
	public static function messengers(): WP_REST_Response {
		global $wpdb;
		$done = array();
		foreach ( (array) $wpdb->get_results( "SELECT meta_value AS uid, COUNT(*) AS n FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_cvd_messenger_user_id' GROUP BY meta_value", ARRAY_A ) as $r ) { $done[ absint( $r['uid'] ) ] = (int) $r['n']; }
		$rows = array();
		foreach ( get_users( array( 'role' => 'cvd_messenger', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ) ) as $u ) {
			$bal = class_exists( 'CVD_Messenger_Accounting' ) ? CVD_Messenger_Accounting::balances( $u->ID ) : array( 'available' => 0, 'paid' => 0 );
			$rows[] = array(
				'id' => $u->ID, 'name' => $u->display_name,
				'phone' => preg_replace( '/\D+/', '', (string) ( get_user_meta( $u->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $u->ID, 'billing_phone', true ) ) ),
				// Estado como mensajero (en una cuenta doble no es el de gestora).
				'status' => sanitize_key( CVD_Registration::status_for( $u, 'mensajero' ) ) ?: 'pending', 'dual' => CVD_Registration::is_dual( $u ),
				'zone' => (string) get_user_meta( $u->ID, '_cvd_zone', true ), 'vehicle' => (string) get_user_meta( $u->ID, '_cvd_vehicle', true ),
				'available' => 'yes' === get_user_meta( $u->ID, '_cvd_messenger_available', true ),
				'deliveries' => $done[ $u->ID ] ?? 0, 'owedCup' => round( (float) ( $bal['available'] ?? 0 ), 2 ), 'paidCup' => round( (float) ( $bal['paid'] ?? 0 ), 2 ),
				'since' => $u->user_registered, 'pilot' => str_starts_with( $u->user_login, 'cv_' ) && str_contains( $u->user_login, 'piloto' ),
			);
		}
		return self::no_cache( array( 'messengers' => $rows, 'signupUrl' => home_url( '/registro-mensajero/' ) ) );
	}

	/** Clientes que llegan de la caja o del bot: [{phone, name, ownerPhone, source, optOut}]. La gestora se busca por su WhatsApp. */
	public static function clients_bulk( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$owners = array();
		foreach ( get_users( array( 'role' => 'cvd_gestora', 'number' => 1000 ) ) as $g ) {
			$p = CVD_Clients::phone( (string) ( get_user_meta( $g->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $g->ID, 'billing_phone', true ) ) );
			if ( $p ) { $owners[ $p ] = $g->ID; }
		}
		$out = array( 'saved' => 0, 'skipped' => 0 );
		foreach ( array_slice( (array) $request->get_param( 'items' ), 0, 500 ) as $item ) {
			$owner = $owners[ CVD_Clients::phone( (string) ( $item['ownerPhone'] ?? '' ) ) ] ?? 0;
			$r = CVD_Clients::upsert( (string) ( $item['phone'] ?? '' ), (string) ( $item['name'] ?? '' ), $owner, sanitize_key( (string) ( $item['source'] ?? 'caja' ) ) );
			if ( isset( $r['error'] ) ) { $out['skipped']++; continue; }
			if ( ! empty( $item['optOut'] ) ) { $wpdb->update( CVD_Clients::table(), array( 'opt_out' => 1 ), array( 'id' => $r['id'] ) ); }
			if ( ! empty( $item['purchase'] ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Clients::table() . ' SET orders = orders + 1, spent = spent + %f, last_order_at = GREATEST(COALESCE(last_order_at, %s), %s) WHERE id=%d',
					(float) ( $item['purchase']['usd'] ?? 0 ), gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $item['purchase']['at'] ?? 'now' ) ) ), gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $item['purchase']['at'] ?? 'now' ) ) ), $r['id'] ) );
			}
			$out['saved']++;
		}
		return self::no_cache( $out );
	}

	/** Todos los clientes (o de una gestora: ?owner=ID), con el nombre de su gestora. */
	public static function clients( WP_REST_Request $request ): WP_REST_Response {
		$names = array();
		$rows = array();
		foreach ( CVD_Clients::list( absint( $request->get_param( 'owner' ) ), sanitize_text_field( (string) $request->get_param( 'search' ) ), 2000 ) as $c ) {
			$owner = (int) $c->owner_user_id;
			if ( $owner && ! isset( $names[ $owner ] ) ) { $u = get_userdata( $owner ); $names[ $owner ] = $u ? $u->display_name : '#' . $owner; }
			$rows[] = array( 'id' => (int) $c->id, 'phone' => $c->phone, 'name' => $c->name, 'ownerId' => $owner, 'owner' => $owner ? $names[ $owner ] : null,
				'source' => $c->source, 'orders' => (int) $c->orders, 'spent' => (float) $c->spent, 'lastOrderAt' => $c->last_order_at, 'note' => $c->note, 'optOut' => (bool) $c->opt_out, 'since' => $c->created_at );
		}
		return self::no_cache( array( 'clients' => $rows ) );
	}

	/** La dueña añade o corrige un cliente (nombre, nota, "no quiere mensajes", gestora si aún no tiene). */
	public static function client_save( WP_REST_Request $request ) {
		global $wpdb;
		$r = CVD_Clients::upsert( (string) $request->get_param( 'phone' ), (string) $request->get_param( 'name' ), absint( $request->get_param( 'ownerId' ) ), 'panel', (string) $request->get_param( 'note' ) );
		if ( isset( $r['error'] ) ) { return new WP_Error( 'cvd_panel_client', $r['error'], array( 'status' => 422 ) ); }
		$update = array();
		if ( null !== $request->get_param( 'optOut' ) ) { $update['opt_out'] = $request->get_param( 'optOut' ) ? 1 : 0; }
		if ( '' !== trim( (string) $request->get_param( 'name' ) ) ) { $update['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) ); }
		if ( $update ) { $wpdb->update( CVD_Clients::table(), $update, array( 'id' => $r['id'] ) ); }
		return self::no_cache( $r );
	}

	public static function shipping_rates(): WP_REST_Response {
		$log = get_option( 'cvd_shipping_rates_log', array() );
		return self::no_cache( array(
			'rates'           => array_values( CVD_Shipping_Rates::rates() ),
			'platformPercent' => (float) get_option( 'cvd_shipping_platform_percent', 10 ),
			'recent'          => array_reverse( array_slice( is_array( $log ) ? $log : array(), -10 ) ),
		) );
	}

	public static function shipping_rate_save( WP_REST_Request $request ) {
		$result = CVD_Shipping_Rates::save_rate(
			(string) $request->get_param( 'municipality' ), (string) $request->get_param( 'zone' ),
			(int) $request->get_param( 'fee' ), (bool) $request->get_param( 'active' ), 'panel'
		);
		if ( isset( $result['error'] ) ) { return new WP_Error( 'cvd_panel_rate', $result['error'], array( 'status' => 422 ) ); }
		return self::no_cache( $result );
	}

	private static function keys_table(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_nexo_stock_keys';
		if ( get_option( 'cvd_nexo_stock_keys_v' ) !== '1' ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( "CREATE TABLE {$table} ( stock_key varchar(190) NOT NULL, product_id bigint(20) unsigned NOT NULL, delta int NOT NULL, applied_at datetime NOT NULL, PRIMARY KEY  (stock_key) ) {$wpdb->get_charset_collate()};" );
			update_option( 'cvd_nexo_stock_keys_v', '1', false );
		}
		return $table;
	}

	/** Aplica movimientos de la caja a la web: [{key, wooId, delta}]. Cada key se aplica una sola vez. */
	public static function stock_adjust( WP_REST_Request $request ) {
		global $wpdb;
		$table = self::keys_table();
		$done = array();
		foreach ( (array) $request->get_param( 'items' ) as $item ) {
			$key = substr( sanitize_text_field( (string) ( $item['key'] ?? '' ) ), 0, 190 );
			$id = absint( $item['wooId'] ?? 0 );
			$delta = (int) ( $item['delta'] ?? 0 );
			if ( '' === $key || ! $id || 0 === $delta ) { continue; }
			// Reservar la clave antes de tocar el stock: si ya existe, ya se aplicó.
			if ( ! $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (stock_key, product_id, delta, applied_at) VALUES (%s,%d,%d,%s)", $key, $id, $delta, current_time( 'mysql', true ) ) ) ) {
				$done[] = array( 'key' => $key, 'already' => true );
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->managing_stock() ) {
				$done[] = array( 'key' => $key, 'skipped' => 'sin control de stock' );
				continue;
			}
			$new = wc_update_product_stock( $product, abs( $delta ), $delta < 0 ? 'decrease' : 'increase' );
			$done[] = array( 'key' => $key, 'stock' => $new );
		}
		return self::no_cache( array( 'applied' => $done ) );
	}

	/** Líneas de pedidos web que descontaron (o devolvieron) stock, modificados desde ?since=. */
	public static function web_sales( WP_REST_Request $request ): WP_REST_Response {
		$since = sanitize_text_field( (string) $request->get_param( 'since' ) );
		$orders = wc_get_orders( array( 'limit' => 200, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'ASC', 'date_modified' => '>=' . ( strtotime( $since ) ?: time() - 86400 ), 'status' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) ) );
		$rows = array();
		foreach ( $orders as $order ) {
			$lines = array();
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$lines[] = array( 'itemId' => $item_id, 'wooId' => $item->get_variation_id() ?: $item->get_product_id(), 'qty' => (int) $item->get_quantity(), 'reduced' => (int) $item->get_meta( '_reduced_stock', true ) );
			}
			// 3.13.97: cambios de productos hechos en /ventas/ que la caja aún no aplicó (ver CVD_Order_Edit).
			$edits = class_exists( 'CVD_Order_Edit' ) ? CVD_Order_Edit::pending_caja_edits( $order ) : array();
			$rows[] = array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'modified' => $order->get_date_modified() ? $order->get_date_modified()->date( 'c' ) : null, 'lines' => $lines, 'edits' => $edits );
		}
		return self::no_cache( array( 'orders' => $rows, 'now' => gmdate( 'c' ) ) );
	}

	/** Existencias actuales de la web por producto (para comparar con la caja). */
	public static function stock_levels(): WP_REST_Response {
		$rows = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private' ), 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
			$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			// 3.14.40: «Falta información» en la lista del panel (lo que le falta a la ficha del producto; los diseños, la del padre).
			$ficha = class_exists( 'CVD_Ficha_Estado' ) && 'publish' === $product->get_status() && ! CVD_Ficha_Estado::excluded( $product ) ? CVD_Ficha_Estado::of_product( $product )['etiquetas'] : array();
			// 3.14.41 (Lennys 20-oct: «la foto del panel no es la misma de la tienda»): la foto principal de la web
			// (mainImage = la del producto o del padre) y la del diseño (image; si no tiene, la del padre).
			$main = self::thumb( (int) $product->get_image_id() );
			foreach ( $items as $p ) {
				// status = el del producto (padre); itemStatus = el de la variante (private = diseño retirado).
				$own = $p === $product ? $main : self::thumb( (int) $p->get_image_id() );
				$rows[] = array( 'wooId' => $p->get_id(), 'stock' => $p->managing_stock() ? (int) $p->get_stock_quantity() : null, 'price' => (float) $p->get_regular_price(), 'status' => $product->get_status(), 'itemStatus' => $p->get_status(), 'ficha' => $ficha, 'mainImage' => $main, 'image' => $own ?? $main );
			}
		}
		// Lennys 29-oct: lo que está en la papelera (eliminado desde el panel o desde wp-admin) no sale en las listas del panel.
		$trashed = array_map( 'intval', get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'trash', 'numberposts' => 3000, 'fields' => 'ids', 'suppress_filters' => true ) ) );
		return self::no_cache( array( 'products' => $rows, 'trashed' => $trashed ) );
	}

	/** Cambiar el precio normal de un producto o variante desde el panel. */
	public static function set_price( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request->get_param( 'wooId' ) ) );
		$price = (float) $request->get_param( 'price' );
		if ( ! $product || $price <= 0 ) { return new WP_Error( 'cvd_panel_price', 'Producto o precio no válido.', array( 'status' => 422 ) ); }
		$product->set_regular_price( wc_format_decimal( $price, 2 ) );
		$product->save();
		return self::no_cache( array( 'ok' => true, 'price' => (float) wc_get_product( $product->get_id() )->get_regular_price() ) );
	}

	/**
	 * Crear (sin wooId) o actualizar (con wooId) un producto simple desde el panel (D39: la caja manda).
	 * Al crear, el stock empieza en 0 con control de stock: el puente de existencias le suma lo que tenga la caja.
	 * Campos: nexoId, name, price, category, imageUrl, active, sku, description (3.14.40: texto; solo si cambió).
	 */
	public static function save_product( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$price = (float) ( $p['price'] ?? 0 );
		$woo_id = absint( $p['wooId'] ?? 0 );
		if ( '' === $name || $price <= 0 ) { return new WP_Error( 'cvd_panel_product', 'Falta el nombre o el precio.', array( 'status' => 422 ) ); }
		if ( $woo_id ) {
			$product = wc_get_product( $woo_id );
			if ( ! $product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
			// 29-oct: guardar no saca de la papelera un producto eliminado (eso se hace a mano en wp-admin).
			if ( 'trash' === $product->get_status() ) { return new WP_Error( 'cvd_panel_product', 'Ese producto está en la papelera de la web (se eliminó).', array( 'status' => 409 ) ); }
		} else {
			$nexo_id = sanitize_text_field( (string) ( $p['nexoId'] ?? '' ) );
			// Si ya se creó antes (reintento), no duplicar.
			$existing = $nexo_id ? wc_get_products( array( 'limit' => 1, 'return' => 'ids', 'status' => array( 'publish', 'private', 'draft' ), 'meta_key' => '_cvd_nexo_product_id', 'meta_value' => $nexo_id ) ) : array();
			$product = $existing ? wc_get_product( $existing[0] ) : new WC_Product_Simple();
			if ( ! $existing ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( 0 );
				$product->update_meta_data( '_cvd_nexo_product_id', $nexo_id );
			}
		}
		// El nombre de una variante lo pone WooCommerce desde el padre: no se toca.
		if ( ! $product->is_type( 'variation' ) ) { $product->set_name( $name ); }
		$product->set_regular_price( wc_format_decimal( $price, 2 ) );
		$sku = sanitize_text_field( (string) ( $p['sku'] ?? '' ) );
		if ( $sku && ! $product->get_sku() && ! wc_get_product_id_by_sku( $sku ) ) { $product->set_sku( $sku ); }
		if ( ! $product->is_type( 'variation' ) ) {
			$product->set_status( ( $p['active'] ?? true ) ? 'publish' : 'private' );
			$category = sanitize_text_field( (string) ( $p['category'] ?? '' ) );
			if ( '' !== $category ) {
				$term = term_exists( $category, 'product_cat' ) ?: wp_insert_term( $category, 'product_cat' );
				if ( ! is_wp_error( $term ) ) { $product->set_category_ids( array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ) ); }
			}
		}
		// 3.14.40 (Lennys 18-oct: «al crear un producto en el panel no me deja añadir la descripción»): texto del panel.
		if ( isset( $p['description'] ) && is_string( $p['description'] ) && class_exists( 'CVD_Ficha_Estado' ) ) { CVD_Ficha_Estado::set_from_panel( $product, $p['description'] ); }
		$id = $product->save();
		$image = esc_url_raw( (string) ( $p['imageUrl'] ?? '' ) );
		if ( $image && $image !== get_post_meta( $id, '_cvd_nexo_image_url', true ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_sideload_image( $image, $id, $name, 'id' );
			if ( ! is_wp_error( $att ) ) {
				$product = wc_get_product( $id );
				$product->set_image_id( $att );
				$product->save();
				update_post_meta( $id, '_cvd_nexo_image_url', $image );
			}
		}
		$gallery = self::add_gallery( $id, (array) ( $p['galleryUrls'] ?? array() ), $name );
		// 3.14.40: lo que le falta a la ficha (medidas, material…), para que el panel lo diga al guardar.
		$saved = wc_get_product( $id );
		$ficha = $saved && class_exists( 'CVD_Ficha_Estado' ) && ! CVD_Ficha_Estado::excluded( $saved ) ? CVD_Ficha_Estado::of_product( $saved )['etiquetas'] : array();
		return self::no_cache( array( 'ok' => true, 'wooId' => $id, 'gallery' => $gallery, 'created' => ! $woo_id, 'url' => get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $id ), 'ficha' => $ficha ) );
	}

	// ---------- 3.13.52: diseños y colores (variaciones) ----------

	/** El producto con diseños de un id (el del padre o el de una de sus variantes). */
	private static function variable_parent( int $id ) {
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && $product->is_type( 'variation' ) ) { $product = wc_get_product( $product->get_parent_id() ); }
		if ( ! $product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		if ( ! $product->is_type( 'variable' ) ) { return new WP_Error( 'cvd_panel_product', 'Este producto no tiene diseños ni colores en la web.', array( 'status' => 422 ) ); }
		return $product;
	}

	private static function thumb( int $image_id ): ?string {
		$url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : false;
		return $url ? (string) $url : null;
	}

	/** Nombre visible de una variante: «rojo», «flores azules», «M / azul». */
	private static function variation_label( WC_Product_Variation $v ): string {
		$parts = array();
		foreach ( $v->get_variation_attributes( false ) as $key => $value ) {
			if ( '' === (string) $value ) { $parts[] = 'cualquiera'; continue; }
			$term = taxonomy_exists( (string) $key ) ? get_term_by( 'slug', (string) $value, (string) $key ) : null;
			$parts[] = $term && ! is_wp_error( $term ) ? $term->name : (string) $value;
		}
		return implode( ' / ', $parts );
	}

	private static function variation_row( WC_Product_Variation $v ): array {
		return array(
			'id' => $v->get_id(), 'label' => self::variation_label( $v ), 'sku' => (string) $v->get_sku(),
			'price' => (float) $v->get_regular_price(), 'sale' => '' !== (string) $v->get_sale_price() ? (float) $v->get_sale_price() : null,
			// null = la web no cuenta las unidades de este diseño.
			'stock' => $v->get_manage_stock() ? (int) $v->get_stock_quantity() : null,
			'status' => $v->get_status(), 'image' => self::thumb( (int) $v->get_image_id() ),
			'retired' => 'private' === $v->get_status(),
		);
	}

	/**
	 * Líneas recientes (última hora) de pedidos web con estas variantes. Al enlazar un diseño con la caja,
	 * NEXO las marca como ya contadas: la cantidad de la web ya las incluye y el puente no debe restarlas otra vez.
	 */
	private static function recent_lines( array $ids ): array {
		$ids = array_map( 'absint', $ids );
		$out = array();
		if ( ! $ids ) { return $out; }
		$orders = wc_get_orders( array( 'limit' => 300, 'type' => 'shop_order', 'date_modified' => '>=' . ( time() - HOUR_IN_SECONDS ), 'status' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) ) );
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$vid = (int) $item->get_variation_id();
				if ( ! in_array( $vid, $ids, true ) ) { continue; }
				$out[ $vid ][] = array( 'orderId' => $order->get_id(), 'itemId' => (int) $item_id, 'status' => $order->get_status(), 'reduced' => (int) $item->get_meta( '_reduced_stock', true ) );
			}
		}
		return $out;
	}

	/** Diseños y colores de un producto, con su cantidad y estado en la web. */
	public static function product_variations( WP_REST_Request $request ) {
		$parent = self::variable_parent( absint( $request['id'] ) );
		if ( is_wp_error( $parent ) ) { return $parent; }
		$children = $parent->get_children();
		$recent = self::recent_lines( $children );
		$rows = array();
		foreach ( $children as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v instanceof WC_Product_Variation ) { continue; }
			$rows[] = self::variation_row( $v ) + array( 'recent' => $recent[ $child_id ] ?? array() );
		}
		$attr = self::variation_attribute( $parent );
		return self::no_cache( array(
			'parent' => array(
				'id' => $parent->get_id(), 'name' => $parent->get_name(), 'status' => $parent->get_status(),
				'image' => self::thumb( (int) $parent->get_image_id() ), 'retired' => '' !== (string) $parent->get_meta( '_cvd_retired' ),
				'url' => get_permalink( $parent->get_id() ),
				// Se pueden añadir diseños si el producto varía en una sola cosa (color o diseño).
				'canAdd' => ! is_wp_error( $attr ), 'cantAddReason' => is_wp_error( $attr ) ? $attr->get_error_message() : null,
			),
			'variations' => $rows,
		) );
	}

	/** La única característica que cambia entre variantes (color o diseño). [clave, atributo] o error. */
	private static function variation_attribute( WC_Product_Variable $parent ) {
		$vars = array_filter( $parent->get_attributes(), static fn( $a ) => $a instanceof WC_Product_Attribute && $a->get_variation() );
		if ( count( $vars ) > 1 ) {
			return new WP_Error( 'cvd_panel_variation', 'Este producto cambia en varias cosas a la vez (por ejemplo talla y color). Para añadirle diseños pídeselo a Ernesto.', array( 'status' => 422 ) );
		}
		if ( ! $vars ) {
			$attr = new WC_Product_Attribute();
			$attr->set_name( 'Diseño' );
			$attr->set_options( array() );
			$attr->set_visible( true );
			$attr->set_variation( true );
			return array( 'diseno', $attr );
		}
		$key = (string) array_key_first( $vars );
		return array( $key, $vars[ $key ] );
	}

	/** Añade la opción (término o texto) al producto si falta. Devuelve [clave, valor] para la variante. */
	private static function add_option( WC_Product_Variable $parent, string $label ) {
		$found = self::variation_attribute( $parent );
		if ( is_wp_error( $found ) ) { return $found; }
		list( $key, $attr ) = $found;
		if ( $attr->is_taxonomy() ) {
			$tax = $attr->get_name();
			$term = get_term_by( 'name', $label, $tax ) ?: get_term_by( 'slug', sanitize_title( $label ), $tax );
			if ( ! $term ) {
				$new = wp_insert_term( $label, $tax );
				if ( is_wp_error( $new ) ) { return new WP_Error( 'cvd_panel_variation', 'No se pudo crear ese color o diseño en la web.', array( 'status' => 500 ) ); }
				$term = get_term( (int) $new['term_id'], $tax );
			}
			$ids = array_map( 'intval', $attr->get_options() );
			if ( ! in_array( (int) $term->term_id, $ids, true ) ) {
				$ids[] = (int) $term->term_id;
				$attr->set_options( $ids );
				wp_set_object_terms( $parent->get_id(), (int) $term->term_id, $tax, true );
			}
			$value = (string) $term->slug;
		} else {
			$options = $attr->get_options();
			$value = null;
			foreach ( $options as $o ) {
				if ( sanitize_title( (string) $o ) === sanitize_title( $label ) ) { $value = (string) $o; }
			}
			if ( null === $value ) {
				$options[] = $label;
				$attr->set_options( $options );
				$value = $label;
			}
		}
		$attributes = $parent->get_attributes();
		$attributes[ $key ] = $attr;
		$parent->set_attributes( $attributes );
		$parent->save();
		return array( $key, $value );
	}

	/** Variante con ese valor (para no duplicar si se pulsa dos veces o el nombre ya existe). */
	private static function find_variation( WC_Product_Variable $parent, string $key, string $value ): ?WC_Product_Variation {
		foreach ( $parent->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v instanceof WC_Product_Variation ) { continue; }
			$attrs = $v->get_variation_attributes( false );
			if ( sanitize_title( (string) ( $attrs[ $key ] ?? '' ) ) === sanitize_title( $value ) ) { return $v; }
		}
		return null;
	}

	/** Crea la variante con 0 unidades (el puente de existencias le suma lo que diga la caja). */
	private static function create_variation( WC_Product_Variable $parent, string $key, string $value, float $price, string $image, string $by ): array {
		$existing = self::find_variation( $parent, $key, $value );
		if ( $existing ) { return self::variation_row( $existing ) + array( 'existing' => true ); }
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent->get_id() );
		$v->set_attributes( array( $key => $value ) );
		$v->set_regular_price( wc_format_decimal( $price, 2 ) );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 0 );
		$v->set_status( 'publish' );
		$v->update_meta_data( '_cvd_panel_created', wp_json_encode( array( 'at' => gmdate( 'c' ), 'by' => $by ) ) );
		$id = $v->save();
		$att = self::sideload( $image, $parent->get_id(), $parent->get_name() . ' — ' . $value );
		if ( $att ) {
			$v = wc_get_product( $id );
			$v->set_image_id( $att );
			$v->save();
		}
		self::log_product( 'diseño añadido', $id, $parent->get_name() . ' — ' . $value, $by );
		return self::variation_row( wc_get_product( $id ) ) + array( 'existing' => false );
	}

	private static function sideload( string $url, int $post_id, string $title ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url ) { return 0; }
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = media_sideload_image( $url, $post_id, $title, 'id' );
		return is_wp_error( $att ) ? 0 : (int) $att;
	}

	/**
	 * 3.14.25 (Lennys 16-oct): varias fotos al crear o editar un producto → galería de la web (debajo de la foto principal).
	 * Se AÑADEN a las que ya tiene (nunca se quitan). Cada URL se baja una sola vez (_cvd_nexo_gallery_urls), así un
	 * reintento no la duplica. Máximo 8 por llamada. Devuelve cuántas fotos tiene la galería.
	 */
	private static function add_gallery( int $id, array $urls, string $title ): int {
		$product = wc_get_product( $id );
		if ( ! $product || $product->is_type( 'variation' ) ) { return 0; }
		$done = (array) json_decode( (string) get_post_meta( $id, '_cvd_nexo_gallery_urls', true ), true );
		$ids = array_map( 'intval', $product->get_gallery_image_ids() );
		$added = false;
		foreach ( array_slice( $urls, 0, 8 ) as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url || in_array( $url, $done, true ) ) { continue; }
			$att = self::sideload( $url, $id, $title );
			if ( ! $att ) { continue; }
			$ids[] = $att;
			$done[] = $url;
			$added = true;
		}
		if ( $added ) {
			$product = wc_get_product( $id );
			$product->set_gallery_image_ids( array_values( array_unique( $ids ) ) );
			$product->save();
			update_post_meta( $id, '_cvd_nexo_gallery_urls', wp_json_encode( array_values( $done ) ) );
		}
		return count( $ids );
	}

	private static function by( array $p ): string {
		return substr( sanitize_text_field( (string) ( $p['by'] ?? 'panel' ) ), 0, 120 ) ?: 'panel';
	}

	/** Añadir un diseño o color a un producto que ya tiene diseños: {label, price, imageUrl, by}. */
	public static function add_variation( WP_REST_Request $request ) {
		$parent = self::variable_parent( absint( $request['id'] ) );
		if ( is_wp_error( $parent ) ) { return $parent; }
		$p = (array) $request->get_json_params();
		$label = trim( sanitize_text_field( (string) ( $p['label'] ?? '' ) ) );
		$price = (float) ( $p['price'] ?? 0 );
		if ( '' === $label || mb_strlen( $label ) > 60 ) { return new WP_Error( 'cvd_panel_variation', 'Escribe el nombre del diseño o color (máximo 60 letras).', array( 'status' => 422 ) ); }
		if ( $price <= 0 ) { return new WP_Error( 'cvd_panel_variation', 'Escribe un precio mayor que 0.', array( 'status' => 422 ) ); }
		$option = self::add_option( $parent, $label );
		if ( is_wp_error( $option ) ) { return $option; }
		$row = self::create_variation( $parent, $option[0], $option[1], $price, (string) ( $p['imageUrl'] ?? '' ), self::by( $p ) );
		WC_Product_Variable::sync( $parent->get_id() );
		wc_delete_product_transients( $parent->get_id() );
		return self::no_cache( array( 'ok' => true, 'parentId' => $parent->get_id(), 'parentName' => $parent->get_name(), 'variation' => $row ) );
	}

	/**
	 * Producto nuevo con diseños: {name, price, category, imageUrl, designs: [{label, imageUrl}], ref, by}.
	 * `ref` (lo genera el panel) evita duplicarlo si se pulsa dos veces o se reintenta.
	 */
	public static function create_variable( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$price = (float) ( $p['price'] ?? 0 );
		$ref = substr( sanitize_key( (string) ( $p['ref'] ?? '' ) ), 0, 64 );
		$by = self::by( $p );
		$designs = array();
		foreach ( array_slice( (array) ( $p['designs'] ?? array() ), 0, 30 ) as $d ) {
			$label = trim( sanitize_text_field( (string) ( $d['label'] ?? '' ) ) );
			if ( '' !== $label && mb_strlen( $label ) <= 60 ) { $designs[ sanitize_title( $label ) ] = array( 'label' => $label, 'imageUrl' => (string) ( $d['imageUrl'] ?? '' ) ); }
		}
		if ( '' === $name || $price <= 0 ) { return new WP_Error( 'cvd_panel_product', 'Falta el nombre o el precio.', array( 'status' => 422 ) ); }
		if ( ! $designs ) { return new WP_Error( 'cvd_panel_product', 'Escribe al menos un diseño o color.', array( 'status' => 422 ) ); }
		$existing = $ref ? wc_get_products( array( 'limit' => 1, 'return' => 'ids', 'status' => array( 'publish', 'private', 'draft' ), 'meta_key' => '_cvd_panel_ref', 'meta_value' => $ref ) ) : array();
		if ( $existing ) {
			$parent = wc_get_product( $existing[0] );
		} else {
			$parent = new WC_Product_Variable();
			$parent->set_name( $name );
			$parent->set_status( 'publish' );
			$parent->set_manage_stock( false );
			$attr = new WC_Product_Attribute();
			$attr->set_name( 'Diseño' );
			$attr->set_options( array_values( wp_list_pluck( $designs, 'label' ) ) );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$parent->set_attributes( array( 'diseno' => $attr ) );
			$category = sanitize_text_field( (string) ( $p['category'] ?? '' ) );
			if ( '' !== $category ) {
				$term = term_exists( $category, 'product_cat' ) ?: wp_insert_term( $category, 'product_cat' );
				if ( ! is_wp_error( $term ) ) { $parent->set_category_ids( array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ) ); }
			}
			if ( $ref ) { $parent->update_meta_data( '_cvd_panel_ref', $ref ); }
			$parent->update_meta_data( '_cvd_panel_created', wp_json_encode( array( 'at' => gmdate( 'c' ), 'by' => $by ) ) );
			$parent_id = $parent->save();
			$att = self::sideload( (string) ( $p['imageUrl'] ?? '' ), $parent_id, $name );
			$parent = wc_get_product( $parent_id );
			if ( $att ) {
				$parent->set_image_id( $att );
				$parent->save();
			}
			self::log_product( 'producto con diseños creado', $parent_id, $name, $by );
		}
		// 3.14.25: más fotos comerciales del producto (galería); la foto real de cada color va en su diseño.
		if ( $parent instanceof WC_Product_Variable ) { self::add_gallery( $parent->get_id(), (array) ( $p['galleryUrls'] ?? array() ), $name ); }
		if ( ! $parent instanceof WC_Product_Variable ) { return new WP_Error( 'cvd_panel_product', 'No se pudo crear el producto en la web.', array( 'status' => 500 ) ); }
		$rows = array();
		foreach ( $designs as $d ) {
			$option = self::add_option( $parent, $d['label'] );
			if ( is_wp_error( $option ) ) { return $option; }
			$rows[] = self::create_variation( $parent, $option[0], $option[1], $price, $d['imageUrl'], $by );
			$parent = wc_get_product( $parent->get_id() );
		}
		WC_Product_Variable::sync( $parent->get_id() );
		wc_delete_product_transients( $parent->get_id() );
		return self::no_cache( array( 'ok' => true, 'parentId' => $parent->get_id(), 'name' => $parent->get_name(), 'url' => get_permalink( $parent->get_id() ), 'variations' => $rows ) );
	}

	// ---------- 3.13.52: «Dejar de vender» / «Volver a vender» ----------

	/**
	 * {items: [wooId], sell: bool, by}. Retirar = privado (no sale en la tienda, el buscador, la tienda de las
	 * gestoras ni el catálogo), oculto del catálogo y retenido (`_cvd_hold`) para que ninguna sincronización
	 * lo vuelva a publicar. Una variante retirada queda desactivada (no se puede elegir). Nunca se borra nada.
	 */
	public static function set_visibility( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		$sell = ! empty( $p['sell'] );
		$by = self::by( $p );
		$done = array();
		$parents = array();
		foreach ( array_slice( array_unique( array_map( 'absint', (array) ( $p['items'] ?? array() ) ) ), 0, 300 ) as $id ) {
			$product = $id ? wc_get_product( $id ) : null;
			// Los artículos «bajo pedido» del mercado los publica VivaBot: aquí no se tocan.
			if ( ! $product || '' !== (string) $product->get_meta( '_cvd_market_item_id' ) ) { $done[] = array( 'wooId' => $id, 'ok' => false ); continue; }
			$sell ? self::put_on_sale( $product ) : self::retire( $product, $by );
			if ( $product->is_type( 'variation' ) ) { $parents[ $product->get_parent_id() ] = true; }
			self::log_product( $sell ? 'vuelve a la venta' : 'retirado (dejar de vender)', $id, $product->get_name(), $by );
			$done[] = array( 'wooId' => $id, 'ok' => true, 'status' => get_post_status( $id ) );
		}
		foreach ( array_keys( $parents ) as $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		}
		return self::no_cache( array( 'items' => $done ) );
	}

	private static function retire( WC_Product $product, string $by ): void {
		$variation = $product->is_type( 'variation' );
		if ( '' === (string) $product->get_meta( '_cvd_retired' ) ) {
			$product->update_meta_data( '_cvd_retired', wp_json_encode( array(
				'at' => gmdate( 'c' ), 'by' => $by, 'status' => $product->get_status(),
				'visibility' => $variation ? '' : $product->get_catalog_visibility(),
				'hold' => '' !== (string) $product->get_meta( '_cvd_hold' ),
			) ) );
		}
		$product->set_status( 'private' );
		if ( ! $variation ) {
			$product->set_catalog_visibility( 'hidden' );
			if ( '' === (string) $product->get_meta( '_cvd_hold' ) ) { $product->update_meta_data( '_cvd_hold', gmdate( 'c' ) ); }
		}
		// Si no, la reversión de la sincronización de BizneCubano lo volvería a publicar.
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product->save();
	}

	private static function put_on_sale( WC_Product $product ): void {
		$old = json_decode( (string) $product->get_meta( '_cvd_retired' ), true );
		$old = is_array( $old ) ? $old : array();
		// Ya estaba a la venta y no lo retiró el panel: no se toca (no cambiar su visibilidad).
		if ( ! $old && 'publish' === $product->get_status() ) { return; }
		$product->set_status( 'publish' );
		if ( ! $product->is_type( 'variation' ) ) {
			$visibility = (string) ( $old['visibility'] ?? '' );
			$product->set_catalog_visibility( '' !== $visibility && 'hidden' !== $visibility ? $visibility : 'visible' );
			// Solo se quita la retención que puso «Dejar de vender», no una puesta a mano antes.
			if ( $old && empty( $old['hold'] ) ) { $product->delete_meta_data( '_cvd_hold' ); }
		}
		$product->delete_meta_data( '_cvd_retired' );
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product->save();
	}

	// ---------- Lennys 29-oct: «Eliminar producto» / «Eliminar este diseño» ----------
	// «Una vez creado el producto no me deja eliminarlo si lo hice mal.» Nunca se toca un pedido:
	// - Si el producto (o alguno de sus diseños) o el diseño ya sale en algún pedido de la web (cualquier estado,
	//   también en la papelera) o la caja ya lo vendió (lo dice nexo-panel-web: cajaSold), NO se borra: se oculta
	//   como «Ocultar» (producto privado y retenido; diseño desactivado), para no romper esos pedidos.
	// - Si no, va a la PAPELERA de WordPress (se recupera en wp-admin → Productos → Papelera). Nunca se borra del todo.
	// Con check = true solo dice qué haría (ids, pedidos), sin cambiar nada.

	/** Ids que cuentan para «ya tiene pedidos»: el producto y, si tiene diseños, todos (también ocultos o en la papelera). */
	private static function delete_ids( WC_Product $product ): array {
		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variable' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', get_posts( array(
				'post_type' => 'product_variation', 'post_parent' => $product->get_id(), 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true,
				'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
			) ) ) );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Cuántos pedidos (de cualquier estado) tienen una línea con alguno de estos productos o diseños. Lee las líneas de
	 * pedido (woocommerce_order_items / itemmeta), que son las mismas con HPOS y sin él.
	 */
	public static function orders_with( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) { return 0; }
		$in = implode( ',', array_map( static fn( int $id ): string => "'" . $id . "'", $ids ) );
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT oi.order_id) FROM {$wpdb->prefix}woocommerce_order_itemmeta im"
			. " INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id"
			. " WHERE oi.order_item_type = 'line_item' AND im.meta_key IN ('_product_id','_variation_id') AND im.meta_value IN ({$in})"
		);
	}

	/** El artículo de la bolsa (crm_market_items) de un producto «bajo pedido» publicado desde el mercado (D121), o 0. */
	private static function market_item_of( WC_Product $product ): int {
		$item = absint( $product->get_meta( '_cvd_market_item_id', true ) );
		if ( ! $item && preg_match( '/^MK-(\d+)$/i', (string) $product->get_sku( 'edit' ), $m ) ) { $item = absint( $m[1] ); }
		return $item;
	}

	/** Ocultar en vez de borrar (tiene pedidos o ventas en la caja) o papelera. Puro, para las pruebas. */
	public static function delete_plan( int $orders, bool $caja_sold, bool $trash_on ): string {
		return ( $orders > 0 || $caja_sold || ! $trash_on ) ? 'hide' : 'trash';
	}

	/**
	 * POST /panel/products/delete { wooId? | marketItem?, check?, cajaSold?, by } → { result: hidden|trashed, reason?,
	 * name, type, ids, orders, marketItem, parentId }. wooId = producto (simple o con diseños) o un diseño (variación).
	 */
	public static function delete_product( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$id = absint( $p['wooId'] ?? 0 );
		$item = absint( $p['marketItem'] ?? 0 );
		if ( ! $id && $item ) { $id = (int) wc_get_product_id_by_sku( CVD_Market_Bridge::SKU_PREFIX . $item ); }
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product instanceof WC_Product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		$variation = $product->is_type( 'variation' );
		$parent = $variation ? wc_get_product( $product->get_parent_id() ) : null;
		$name = $variation ? ( $parent ? $parent->get_name() . ' · ' : '' ) . self::variation_label( $product ) : $product->get_name();
		$ids = self::delete_ids( $product );
		$orders = self::orders_with( $ids );
		$market = self::market_item_of( $variation && $parent ? $parent : $product );
		$out = array(
			'ok' => true, 'wooId' => $product->get_id(), 'type' => $variation ? 'variation' : $product->get_type(), 'name' => $name,
			'parentId' => $variation ? $product->get_parent_id() : null, 'ids' => $ids, 'orders' => $orders, 'marketItem' => $market ?: null,
		);
		if ( 'trash' === $product->get_status() ) { return self::no_cache( $out + array( 'result' => 'trashed', 'already' => true ) ); }
		if ( ! empty( $p['check'] ) ) { return self::no_cache( $out + array( 'result' => null ) ); }
		$by = self::by( $p );
		$pid = $product->get_id(); // WC_Data::delete() deja el objeto con id 0: se guarda antes
		// Sin papelera (EMPTY_TRASH_DAYS = 0) WordPress borraría del todo: entonces solo se oculta.
		$plan = self::delete_plan( $orders, ! empty( $p['cajaSold'] ), (bool) EMPTY_TRASH_DAYS );
		$reason = null;
		if ( 'hide' === $plan ) {
			self::retire( $product, $by );
			$reason = $orders > 0 ? 'orders' : ( ! empty( $p['cajaSold'] ) ? 'caja' : 'notrash' );
			self::log_product( 'eliminar → oculto (' . array( 'orders' => 'tiene pedidos', 'caja' => 'vendido en la caja', 'notrash' => 'sin papelera' )[ $reason ] . ')', $pid, $name, $by );
		} else {
			$product->delete( false ); // false = a la papelera, nunca borrado del todo
			if ( 'trash' !== get_post_status( $pid ) ) { return new WP_Error( 'cvd_panel_product', 'La web no lo pudo mandar a la papelera. Prueba otra vez.', array( 'status' => 500 ) ); }
			self::log_product( 'eliminado (papelera)', $pid, $name, $by );
		}
		if ( $variation && $parent ) { WC_Product_Variable::sync( $parent->get_id() ); wc_delete_product_transients( $parent->get_id() ); }
		else { wc_delete_product_transients( $pid ); }
		return self::no_cache( $out + array( 'result' => 'hide' === $plan ? 'hidden' : 'trashed', 'reason' => $reason, 'status' => get_post_status( $pid ) ) );
	}

	// ---------- 3.14.41 (Lennys 20-oct): descripción para todos, foto principal y «Duplicar» ----------

	/** El producto de un id; si es un diseño (variante), su producto (padre): la descripción y la foto principal son de él. */
	private static function main_product( int $id ): ?WC_Product {
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && $product->is_type( 'variation' ) ) { $product = wc_get_product( $product->get_parent_id() ); }
		return $product ?: null;
	}

	/** {description, by}: la descripción del producto (en uno con diseños, la del padre, que es la que ve todo el mundo). */
	public static function save_description( WP_REST_Request $request ) {
		$product = self::main_product( absint( $request['id'] ) );
		if ( ! $product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		if ( ! class_exists( 'CVD_Ficha_Estado' ) ) { return new WP_Error( 'cvd_panel_product', 'Falta actualizar la web.', array( 'status' => 500 ) ); }
		$p = (array) $request->get_json_params();
		$text = is_string( $p['description'] ?? null ) ? (string) $p['description'] : '';
		if ( '' === trim( $text ) ) { return new WP_Error( 'cvd_panel_product', 'Escribe la descripción.', array( 'status' => 422 ) ); }
		$changed = CVD_Ficha_Estado::set_from_panel( $product, $text );
		if ( $changed ) {
			$product->save();
			self::log_product( 'descripción cambiada', $product->get_id(), $product->get_name(), self::by( $p ) );
		}
		$saved = wc_get_product( $product->get_id() );
		$ficha = $saved && ! CVD_Ficha_Estado::excluded( $saved ) ? CVD_Ficha_Estado::of_product( $saved )['etiquetas'] : array();
		return self::no_cache( array( 'ok' => true, 'wooId' => $product->get_id(), 'changed' => $changed, 'ficha' => $ficha ) );
	}

	/**
	 * {imageUrl, by}: foto principal de un producto (la de la tienda, la de las gestoras y la del bot). Con el id de un
	 * producto con diseños se cambia la del producto, no la de un diseño (esa va por /panel/products/save con su wooId).
	 */
	public static function set_main_photo( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['id'] ) );
		if ( ! $product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		$p = (array) $request->get_json_params();
		$image = esc_url_raw( (string) ( $p['imageUrl'] ?? '' ) );
		if ( '' === $image ) { return new WP_Error( 'cvd_panel_product', 'Falta la foto.', array( 'status' => 422 ) ); }
		$att = $image === get_post_meta( $product->get_id(), '_cvd_nexo_image_url', true ) ? (int) $product->get_image_id() : self::sideload( $image, $product->get_id(), $product->get_name() );
		if ( ! $att ) { return new WP_Error( 'cvd_panel_product', 'Esa foto no se pudo poner en la web. Prueba con otra.', array( 'status' => 422 ) ); }
		$product->set_image_id( $att );
		$product->update_meta_data( '_cvd_nexo_image_url', $image );
		$product->save();
		self::log_product( 'foto principal cambiada', $product->get_id(), $product->get_name(), self::by( $p ) );
		return self::no_cache( array( 'ok' => true, 'wooId' => $product->get_id(), 'image' => self::thumb( $att ) ) );
	}

	/** Datos que una copia NO hereda: enlaces con la caja y el mercado, «retirado», historiales y fotos de las gestoras. */
	public const COPY_SKIP_META = array( '_cvd_nexo_product_id', '_cvd_panel_ref', '_cvd_panel_created', '_cvd_retired', '_cvd_hold', '_cvd_sync_hidden_from', '_cvd_market_item_id', '_cvd_market_version', '_cvd_ficha_historial', '_cvd_ficha_log', '_cvd_desc_backup_panel', '_cvd_estudio_tienda', '_cvd_original_duplicate_title', '_cvd_no_reponer' );
	private const COPY_OF = '_cvd_panel_copy_of';

	/** Limpia una copia (o un diseño de la copia): sin datos heredados que no son suyos y con 0 unidades. */
	public static function clean_copy( WC_Product $copy ): void {
		foreach ( self::COPY_SKIP_META as $key ) { $copy->delete_meta_data( $key ); }
		if ( ! $copy->is_type( 'variable' ) ) {
			// Las unidades son de la mercancía de verdad: la copia empieza en 0 y la caja pone lo que haya.
			$copy->set_manage_stock( true );
			$copy->set_stock_quantity( 0 );
		}
	}

	/** Una copia en borrador (lo que el panel enseña para editarla antes de publicarla). */
	private static function copy_row( WC_Product $copy ): array {
		$vars = array();
		if ( $copy->is_type( 'variable' ) ) {
			foreach ( $copy->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v instanceof WC_Product_Variation ) { $vars[] = self::variation_row( $v ); }
			}
		}
		$source = wc_get_product( absint( $copy->get_meta( self::COPY_OF ) ) );
		$price = $copy->is_type( 'variable' ) ? ( $vars ? (float) $vars[0]['price'] : 0.0 ) : (float) $copy->get_regular_price();
		$cats = wc_get_product_terms( $copy->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		return array(
			'wooId' => $copy->get_id(), 'name' => $copy->get_name(), 'type' => $copy->get_type(), 'status' => $copy->get_status(),
			'copyOf' => $source ? $source->get_id() : null, 'copyOfName' => $source ? $source->get_name() : '',
			'price' => $price, 'category' => is_array( $cats ) && $cats ? (string) $cats[0] : '',
			'description' => class_exists( 'CVD_Ficha_Estado' ) ? mb_substr( CVD_Ficha_Estado::plain( (string) $copy->get_description() ), 0, 6000 ) : '',
			'image' => self::thumb( (int) $copy->get_image_id() ), 'variations' => $vars,
			'url' => 'publish' === $copy->get_status() ? get_permalink( $copy->get_id() ) : null,
		);
	}

	/** La copia de un id (en borrador o ya publicada) o un error que la dueña entiende. */
	private static function copy_of_id( int $id ) {
		$copy = $id ? wc_get_product( $id ) : null;
		if ( ! $copy || '' === (string) $copy->get_meta( self::COPY_OF ) ) { return new WP_Error( 'cvd_panel_copy', 'No encontré esa copia. Pulsa actualizar.', array( 'status' => 404 ) ); }
		return $copy;
	}

	/**
	 * «Duplicar» {ref, by}: copia un producto (y todos sus diseños, fotos, descripción, categoría y precio) en BORRADOR:
	 * no sale en la tienda, ni a las gestoras, ni al bot, ni en la caja hasta que la dueña la publica. Las unidades
	 * empiezan en 0. `ref` (lo genera el panel) evita una segunda copia si se pulsa dos veces o se reintenta.
	 */
	public static function duplicate( WP_REST_Request $request ) {
		$product = self::main_product( absint( $request['id'] ) );
		$p = (array) $request->get_json_params();
		if ( ! $product ) { return new WP_Error( 'cvd_panel_product', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		if ( ! $product->is_type( array( 'simple', 'variable' ) ) ) { return new WP_Error( 'cvd_panel_copy', 'Este producto no se puede duplicar desde aquí.', array( 'status' => 422 ) ); }
		if ( '' !== (string) $product->get_meta( '_cvd_market_item_id' ) ) { return new WP_Error( 'cvd_panel_copy', 'Los productos de proveedor (bajo pedido) no se duplican desde aquí.', array( 'status' => 422 ) ); }
		$ref = substr( sanitize_key( (string) ( $p['ref'] ?? '' ) ), 0, 64 );
		$existing = $ref ? get_posts( array( 'post_type' => 'product', 'post_status' => array( 'draft', 'publish', 'private' ), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_cvd_panel_ref', 'meta_value' => $ref ) ) : array();
		if ( $existing ) { return self::no_cache( array( 'ok' => true, 'existing' => true ) + self::copy_row( wc_get_product( (int) $existing[0] ) ) ); }
		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) && defined( 'WC_ABSPATH' ) ) { require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php'; }
		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) { return new WP_Error( 'cvd_panel_copy', 'La web no puede duplicar productos ahora.', array( 'status' => 500 ) ); }
		$clean = array( __CLASS__, 'clean_copy' );
		add_action( 'woocommerce_product_duplicate_before_save', $clean, 10, 1 );
		try {
			$copy = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $product );
		} finally {
			remove_action( 'woocommerce_product_duplicate_before_save', $clean, 10 );
		}
		if ( ! $copy instanceof WC_Product || ! $copy->get_id() ) { return new WP_Error( 'cvd_panel_copy', 'No se pudo duplicar el producto.', array( 'status' => 500 ) ); }
		// Por si una versión de WooCommerce no avisa al copiar los diseños: se limpian otra vez (no cambia nada si ya están).
		foreach ( $copy->is_type( 'variable' ) ? $copy->get_children() : array() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v ) { continue; }
			self::clean_copy( $v );
			$v->save();
		}
		$copy = wc_get_product( $copy->get_id() );
		self::clean_copy( $copy );
		$copy->set_name( $product->get_name() . ' (copia)' );
		$copy->set_status( 'draft' );
		if ( 'hidden' === $copy->get_catalog_visibility() ) { $copy->set_catalog_visibility( 'visible' ); }
		$copy->update_meta_data( self::COPY_OF, (string) $product->get_id() );
		if ( $ref ) { $copy->update_meta_data( '_cvd_panel_ref', $ref ); }
		$copy->update_meta_data( '_cvd_panel_created', wp_json_encode( array( 'at' => gmdate( 'c' ), 'by' => self::by( $p ), 'copyOf' => $product->get_id() ) ) );
		$copy->save();
		if ( $copy->is_type( 'variable' ) ) { WC_Product_Variable::sync( $copy->get_id() ); }
		wc_delete_product_transients( $copy->get_id() );
		self::log_product( 'copia creada (borrador) de #' . $product->get_id(), $copy->get_id(), $copy->get_name(), self::by( $p ) );
		return self::no_cache( array( 'ok' => true, 'existing' => false ) + self::copy_row( wc_get_product( $copy->get_id() ) ) );
	}

	/** Copias sin publicar (borradores hechos con «Duplicar»), para que no se pierdan si se cierra el panel. */
	public static function copies(): WP_REST_Response {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'draft', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => self::COPY_OF, 'orderby' => 'date', 'order' => 'DESC' ) );
		$rows = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p ) { $rows[] = array( 'wooId' => $p->get_id(), 'name' => $p->get_name(), 'type' => $p->get_type(), 'image' => self::thumb( (int) $p->get_image_id() ), 'at' => (string) get_post_field( 'post_date_gmt', $p->get_id() ) ); }
		}
		return self::no_cache( array( 'copies' => $rows ) );
	}

	public static function copy_read( WP_REST_Request $request ) {
		$copy = self::copy_of_id( absint( $request['id'] ) );
		return is_wp_error( $copy ) ? $copy : self::no_cache( array( 'ok' => true ) + self::copy_row( $copy ) );
	}

	/** Quita una opción (color o diseño) del producto cuando su diseño se borra de la copia. */
	private static function drop_option( WC_Product_Variable $parent, string $key, string $value ): void {
		$attributes = $parent->get_attributes();
		$attr = $attributes[ $key ] ?? null;
		if ( ! $attr instanceof WC_Product_Attribute ) { return; }
		if ( $attr->is_taxonomy() ) {
			$term = get_term_by( 'slug', $value, $attr->get_name() );
			if ( ! $term ) { return; }
			$attr->set_options( array_values( array_diff( array_map( 'intval', $attr->get_options() ), array( (int) $term->term_id ) ) ) );
			wp_remove_object_terms( $parent->get_id(), (int) $term->term_id, $attr->get_name() );
		} else {
			$attr->set_options( array_values( array_filter( $attr->get_options(), static fn( $o ) => sanitize_title( (string) $o ) !== sanitize_title( $value ) ) ) );
		}
		$attributes[ $key ] = $attr;
		$parent->set_attributes( $attributes );
		$parent->save();
	}

	/**
	 * Publicar una copia {name, price, category, description, imageUrl, keep: [wooId de diseños], photos: {wooId: url},
	 * add: [{label, imageUrl}], by}. Los diseños de la copia que no se quedan se borran (son de un borrador que nadie
	 * vio ni vendió). Reintento: si ya está publicada, devuelve la copia como está (el panel la enlaza con la caja).
	 */
	public static function copy_publish( WP_REST_Request $request ) {
		$copy = self::copy_of_id( absint( $request['id'] ) );
		if ( is_wp_error( $copy ) ) { return $copy; }
		if ( 'publish' === $copy->get_status() ) { return self::no_cache( array( 'ok' => true, 'published' => true, 'again' => true ) + self::copy_row( $copy ) ); }
		if ( 'draft' !== $copy->get_status() ) { return new WP_Error( 'cvd_panel_copy', 'Esta copia ya no está en borrador.', array( 'status' => 422 ) ); }
		$p = (array) $request->get_json_params();
		$by = self::by( $p );
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$price = (float) ( $p['price'] ?? 0 );
		if ( '' === $name || $price <= 0 ) { return new WP_Error( 'cvd_panel_copy', 'Falta el nombre o el precio.', array( 'status' => 422 ) ); }
		$variable = $copy->is_type( 'variable' );
		$children = $variable ? array_map( 'intval', $copy->get_children() ) : array();
		$keep = array_values( array_intersect( $children, array_map( 'absint', (array) ( $p['keep'] ?? array() ) ) ) );
		$add = array();
		foreach ( array_slice( (array) ( $p['add'] ?? array() ), 0, 30 ) as $d ) {
			$label = trim( sanitize_text_field( (string) ( $d['label'] ?? '' ) ) );
			if ( '' !== $label && mb_strlen( $label ) <= 60 ) { $add[ sanitize_title( $label ) ] = array( 'label' => $label, 'imageUrl' => (string) ( $d['imageUrl'] ?? '' ) ); }
		}
		if ( $variable && ! $keep && ! $add ) { return new WP_Error( 'cvd_panel_copy', 'Deja al menos un diseño o color.', array( 'status' => 422 ) ); }
		// Antes de cambiar nada: si el producto varía en varias cosas (talla y color) no se le pueden añadir colores desde aquí.
		$found = $variable ? self::variation_attribute( $copy ) : null;
		if ( $add && is_wp_error( $found ) ) { return $found; }
		$photos = (array) ( $p['photos'] ?? array() );
		$copy->set_name( $name );
		$category = sanitize_text_field( (string) ( $p['category'] ?? '' ) );
		if ( '' !== $category ) {
			$term = term_exists( $category, 'product_cat' ) ?: wp_insert_term( $category, 'product_cat' );
			if ( ! is_wp_error( $term ) ) { $copy->set_category_ids( array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ) ); }
		}
		if ( isset( $p['description'] ) && is_string( $p['description'] ) && class_exists( 'CVD_Ficha_Estado' ) ) { CVD_Ficha_Estado::set_from_panel( $copy, $p['description'] ); }
		if ( ! $variable ) {
			$copy->set_regular_price( wc_format_decimal( $price, 2 ) );
			$copy->set_sale_price( '' );
		}
		$copy->save();
		$main = esc_url_raw( (string) ( $p['imageUrl'] ?? '' ) );
		if ( $main ) {
			$att = self::sideload( $main, $copy->get_id(), $name );
			if ( $att ) {
				$copy = wc_get_product( $copy->get_id() );
				$copy->set_image_id( $att );
				$copy->update_meta_data( '_cvd_nexo_image_url', $main );
				$copy->save();
			}
		}
		if ( $variable ) {
			foreach ( $children as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v instanceof WC_Product_Variation ) { continue; }
				if ( ! in_array( $child_id, $keep, true ) ) {
					// Un diseño que no se queda: se quita la opción del producto y se borra (borrador sin ventas).
					if ( ! is_wp_error( $found ) ) {
						$value = (string) ( $v->get_variation_attributes( false )[ $found[0] ] ?? '' );
						if ( '' !== $value ) { self::drop_option( wc_get_product( $copy->get_id() ), $found[0], $value ); }
					}
					$v->delete( true );
					continue;
				}
				$v->set_status( 'publish' );
				$v->set_regular_price( wc_format_decimal( $price, 2 ) );
				$v->set_sale_price( '' );
				$photo = esc_url_raw( (string) ( $photos[ $child_id ] ?? $photos[ (string) $child_id ] ?? '' ) );
				$att = $photo ? self::sideload( $photo, $copy->get_id(), $name . ' — ' . self::variation_label( $v ) ) : 0;
				if ( $att ) { $v->set_image_id( $att ); }
				$v->save();
			}
			foreach ( $add as $d ) {
				$copy = wc_get_product( $copy->get_id() );
				$option = self::add_option( $copy, $d['label'] );
				if ( is_wp_error( $option ) ) { return $option; }
				self::create_variation( wc_get_product( $copy->get_id() ), $option[0], $option[1], $price, $d['imageUrl'], $by );
			}
		}
		$copy = wc_get_product( $copy->get_id() );
		$copy->set_status( 'publish' );
		if ( 'hidden' === $copy->get_catalog_visibility() ) { $copy->set_catalog_visibility( 'visible' ); }
		$copy->save();
		if ( $variable ) { WC_Product_Variable::sync( $copy->get_id() ); }
		wc_delete_product_transients( $copy->get_id() );
		self::log_product( 'copia publicada', $copy->get_id(), $copy->get_name(), $by );
		return self::no_cache( array( 'ok' => true, 'published' => true, 'again' => false ) + self::copy_row( wc_get_product( $copy->get_id() ) ) );
	}

	/** Descartar una copia sin publicar: va a la papelera de WordPress (se puede recuperar), nunca se borra del todo. */
	public static function copy_discard( WP_REST_Request $request ) {
		$copy = self::copy_of_id( absint( $request['id'] ) );
		if ( is_wp_error( $copy ) ) { return $copy; }
		if ( 'draft' !== $copy->get_status() ) { return new WP_Error( 'cvd_panel_copy', 'Esa copia ya está publicada: para quitarla, usa «Ocultar».', array( 'status' => 422 ) ); }
		$p = (array) $request->get_json_params();
		wp_trash_post( $copy->get_id() );
		self::log_product( 'copia descartada (papelera)', $copy->get_id(), $copy->get_name(), self::by( $p ) );
		return self::no_cache( array( 'ok' => true, 'wooId' => $copy->get_id() ) );
	}

	/** Registro de cambios de productos hechos desde el panel (quién y cuándo), últimos 500. */
	private static function log_product( string $action, int $id, string $name, string $by ): void {
		$log = get_option( 'cvd_panel_product_log', array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array( 'at' => gmdate( 'c' ), 'by' => $by, 'action' => $action, 'wooId' => $id, 'name' => $name );
		update_option( 'cvd_panel_product_log', array_slice( $log, -500 ), false );
	}

	/** Ejecuta una ruta del Centro de ventas como la usuaria que firma el panel (sus permisos y reglas). */
	private static function as_owner( string $method, string $route, array $params ) {
		$actor = absint( get_option( self::ACTOR_OPTION, 0 ) );
		if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma las acciones del panel.', array( 'status' => 500 ) );
		}
		wp_set_current_user( $actor );
		$inner = new WP_REST_Request( $method, '/' . self::NS . $route );
		foreach ( $params as $key => $value ) { $inner->set_param( $key, $value ); }
		$response = rest_do_request( $inner );
		if ( $response->is_error() ) { return $response->as_error(); }
		return self::no_cache( (array) $response->get_data() );
	}

	public static function sales( WP_REST_Request $request ) {
		return self::as_owner( 'GET', '/sales', array(
			'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
			'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		) );
	}

	/** Cambiar el estado (Listo para salir, entregado al mensajero, dinero recibido, cancelar…) o registrar devolución. */
	public static function sales_action( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$allowed = array( 'status', 'collectionMethod', 'collectionAccount', 'collectionReference', 'collectionCardLabel', 'collectionCardLast4', 'collectionSaveCard', 'collectedUsd', 'collectedCup', 'collectionNote', 'moneyConfirmed', 'handoverConfirmed', 'idempotencyKey', 'reason', 'refundUsd', 'refundCup', 'voidMessenger', 'confirmReserva' );
		$params = array_intersect_key( $params, array_flip( $allowed ) );
		self::enter( (array) $request->get_json_params() ); // 3.13.97: la nota de cancelación dice quién lo pulsó en el panel
		return self::as_owner( 'POST', '/sales/' . absint( $request['id'] ) . '/' . ( 'return' === $request['what'] ? 'return' : 'status' ), $params );
	}

	public static function authorized( WP_REST_Request $request ): bool {
		$key = (string) $request->get_header( 'x_nexo_panel_key' );
		$hash = (string) get_option( self::KEY_OPTION, '' );
		return '' !== $key && '' !== $hash && hash_equals( $hash, hash( 'sha256', $key ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Todas las gestoras con sus números: ventas de la web, comisiones por estado y último pago. */
	public static function gestoras(): WP_REST_Response {
		global $wpdb;
		$stats = array();
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => array( 'completed', 'processing', 'on-hold' ), 'meta_key' => '_cvd_owner_type', 'meta_value' => 'gestora' ) );
		foreach ( $orders as $order ) {
			$id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
			if ( ! $id ) { continue; }
			$stats[ $id ] = $stats[ $id ] ?? array( 'orders' => 0, 'sales' => 0.0, 'pending' => 0.0, 'available' => 0.0, 'paid' => 0.0 );
			if ( 'completed' === $order->get_status() ) { $stats[ $id ]['orders']++; $stats[ $id ]['sales'] += (float) $order->get_total(); }
			$amount = (float) $order->get_meta( '_cvd_commission_amount', true );
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'paid' === $status ) { $stats[ $id ]['paid'] += $amount; }
			elseif ( 'approved' === $status ) { $stats[ $id ]['available'] += $amount; }
			elseif ( 'pending' === $status ) { $stats[ $id ]['pending'] += $amount; }
		}
		$last = array();
		foreach ( (array) $wpdb->get_results( "SELECT owner_user_id, MAX(paid_at) AS paid_at FROM {$wpdb->prefix}cvd_payouts WHERE status='paid' GROUP BY owner_user_id", ARRAY_A ) as $r ) {
			$last[ absint( $r['owner_user_id'] ) ] = $r['paid_at'];
		}
		$rows = array();
		foreach ( get_users( array( 'role' => 'cvd_gestora', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ) ) as $user ) {
			$s = $stats[ $user->ID ] ?? array( 'orders' => 0, 'sales' => 0.0, 'pending' => 0.0, 'available' => 0.0, 'paid' => 0.0 );
			$inviter = absint( get_user_meta( $user->ID, '_cvd_invited_by', true ) );
			$inviter_user = $inviter ? get_userdata( $inviter ) : null;
			$rows[] = array(
				'id'         => $user->ID,
				'name'       => $user->display_name,
				'phone'      => preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ) ),
				'status'     => sanitize_key( CVD_Registration::status_for( $user, 'gestora' ) ) ?: 'pending',
				'dual'       => CVD_Registration::is_dual( $user ), // También es mensajero (sale en las dos listas).
				'code'       => (string) get_user_meta( $user->ID, '_cvd_referral_code', true ),
				'since'      => $user->user_registered,
				'invitedBy'  => $inviter_user ? $inviter_user->display_name : null,
				'orders'     => $s['orders'],
				'sales'      => round( $s['sales'], 2 ),
				'commission' => array( 'pending' => round( $s['pending'], 2 ), 'available' => round( $s['available'], 2 ), 'paid' => round( $s['paid'], 2 ) ),
				'lastPaidAt' => $last[ $user->ID ] ?? null,
				// Cuenta de prueba o del equipo (dueña, administración, piloto, sin WhatsApp): no se muestra ni va a la caja.
				'test'       => '1' === get_user_meta( $user->ID, '_cvd_test_account', true ) || user_can( $user, 'manage_woocommerce' ) || str_contains( $user->user_login, 'piloto' ) || '' === preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ) ),
			);
		}
		return self::no_cache( array( 'gestoras' => $rows ) );
	}

	/** Aprobar o rechazar: la misma lógica que usa el bot (estado y código de referido). */
	public static function gestora_status( WP_REST_Request $request ) {
		$forward = new WP_REST_Request( 'POST' );
		$forward->set_url_params( array( 'id' => absint( $request['id'] ) ) );
		$forward->set_param( 'status', (string) $request->get_param( 'status' ) );
		$forward->set_param( 'by', 'panel' );
		// Cuenta doble: la lista de mensajeros cambia su estado de mensajero; la de gestoras, el de gestora.
		$forward->set_param( 'kind', false !== strpos( (string) $request->get_route(), '/panel/messengers/' ) ? 'mensajero' : 'gestora' );
		$result = CVD_Bot_Bridge::partner_status( $forward );
		if ( ! is_wp_error( $result ) ) { update_user_meta( absint( $request['id'] ), '_cvd_status_changed_by', 'panel' ); }
		return $result;
	}

	/** Solicitudes y pagos (por defecto, los que esperan acción). */
	public static function payouts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$where = in_array( $status, array( 'requested', 'approved', 'paid', 'rejected' ), true )
			? $wpdb->prepare( 'WHERE status=%s', $status ) : "WHERE status IN ('requested','approved')";
		$owner = absint( $request->get_param( 'owner' ) );
		if ( $owner ) { $where .= $wpdb->prepare( ' AND owner_user_id=%d', $owner ); }
		$rows = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, owner_user_id, amount, currency, status, method, reference, requested_at, approved_at, paid_at FROM {$wpdb->prefix}cvd_payouts {$where} ORDER BY id DESC LIMIT 100", ARRAY_A ) as $r ) {
			$user = get_userdata( absint( $r['owner_user_id'] ) );
			$rows[] = array(
				'id' => absint( $r['id'] ), 'gestora' => $user ? $user->display_name : '#' . $r['owner_user_id'],
				'gestoraId' => absint( $r['owner_user_id'] ), 'amount' => round( (float) $r['amount'], 2 ), 'currency' => $r['currency'],
				'status' => $r['status'], 'method' => $r['method'], 'reference' => $r['reference'],
				'requestedAt' => $r['requested_at'], 'approvedAt' => $r['approved_at'], 'paidAt' => $r['paid_at'],
			);
			// 3.14.23: lo que hay que pagar (CUP con la tasa de cada venta) y el enlace a «Pagar a…» firmado para Lennys.
			if ( class_exists( 'CVD_Payout_Pay_Page' ) ) {
				$full = CVD_Payouts::get( absint( $r['id'] ) );
				$view = $full ? CVD_Payouts::view( $full ) : null;
				$admin = CVD_Payout_Avisos::phones( 'cvd_payout_admin_phones', array( '5356885368' ) );
				$rows[ count( $rows ) - 1 ] += array(
					'payMethod' => $view ? $view['kind'] : '', 'payText' => $view ? $view['pay_text'] : '',
					'payUrl' => $admin && in_array( $r['status'], array( 'requested', 'approved' ), true ) ? CVD_Payout_Pay_Page::signed_url( absint( $r['id'] ), $admin[0] ) : '',
					'receivedAt' => $full['received_at'] ?? null,
				);
			}
		}
		return self::no_cache( array( 'payouts' => $rows ) );
	}

	/** Aprobar, marcar pagado o rechazar una solicitud (CVD_Payouts decide si la transición vale). */
	public static function payout_action( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! in_array( $action, array( 'approve', 'pay', 'reject' ), true ) ) {
			return new WP_Error( 'cvd_panel_action', 'Acción no válida.', array( 'status' => 422 ) );
		}
		$actor = absint( get_option( self::ACTOR_OPTION, 0 ) );
		if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma los pagos del panel.', array( 'status' => 500 ) );
		}
		wp_set_current_user( $actor );
		$result = CVD_Payouts::transition( absint( $request['id'] ), $action, sanitize_text_field( (string) $request->get_param( 'reference' ) ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( array( 'ok' => true ) );
	}
}
