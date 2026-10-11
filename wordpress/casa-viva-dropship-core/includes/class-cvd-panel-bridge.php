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
		register_rest_route( self::NS, '/panel/stock/levels', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'stock_levels' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/price', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_price' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/sales/(?P<id>\d+)/edit', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'edit_order' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/sales/(?P<id>\d+)/detail', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'order_detail' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/prices', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_prices' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/curru/status', array( 'methods' => 'GET', 'callback' => static fn() => self::no_cache( array( 'enabled' => class_exists( 'CVD_Curru_AI' ) && CVD_Curru_AI::enabled() ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/curru/invoice', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'curru_invoice' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/products/save', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_product' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/messengers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'messengers' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/messengers/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients/bulk', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clients_bulk' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'clients' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/clients', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'client_save' ), 'permission_callback' => $auth ) );
		// Tarifas de mensajería por zona (CUP): las mismas que usa el checkout y el reparto.
		register_rest_route( self::NS, '/panel/shipping-rates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'shipping_rates' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/shipping-rates', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'shipping_rate_save' ), 'permission_callback' => $auth ) );
	}

	private const EDITABLE = array( 'pending', 'processing', 'on-hold' );

	/** Líneas y datos de entrega de un pedido, para editarlo desde el panel. */
	public static function order_detail( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_panel_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$lines[] = array( 'itemId' => $item_id, 'name' => $item->get_name(), 'qty' => (int) $item->get_quantity(), 'total' => (float) $item->get_total() );
		}
		return self::no_cache( array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'editable' => in_array( $order->get_status(), self::EDITABLE, true ),
			'lines' => $lines, 'phone' => $order->get_billing_phone(), 'address' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(), 'note' => $order->get_customer_note() ) );
	}

	/**
	 * Editar un pedido abierto: cantidades (0 = quitar), añadir productos, teléfono, dirección y nota.
	 * Ajusta el stock como en el administrador de WooCommerce y recalcula totales y comisión.
	 */
	public static function edit_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || ! in_array( $order->get_status(), self::EDITABLE, true ) ) {
			return new WP_Error( 'cvd_panel_order', 'Solo se pueden cambiar pedidos que aún no se han entregado ni cancelado.', array( 'status' => 409 ) );
		}
		require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
		$p = (array) $request->get_json_params();
		// Antes de tocar nada: el pedido no puede quedar vacío.
		$final = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) { $final[ $item_id ] = (int) $item->get_quantity(); }
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) { if ( isset( $final[ absint( $l['itemId'] ?? 0 ) ] ) ) { $final[ absint( $l['itemId'] ) ] = max( 0, (int) ( $l['qty'] ?? 0 ) ); } }
		if ( ! array_filter( $final ) && ! array_filter( (array) ( $p['add'] ?? array() ), static fn( $a ) => absint( $a['wooId'] ?? 0 ) > 0 ) ) {
			return new WP_Error( 'cvd_panel_order', 'El pedido no puede quedar sin productos: cancélalo en su lugar.', array( 'status' => 422 ) );
		}
		$changes = array(); $stock = array();
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) {
			$item = $order->get_item( absint( $l['itemId'] ?? 0 ) );
			$qty = max( 0, (int) ( $l['qty'] ?? -1 ) );
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_quantity() === $qty ) { continue; }
			$reduced = (int) $item->get_meta( '_reduced_stock', true );
			$woo = $item->get_variation_id() ?: $item->get_product_id();
			// La caja también debe sumar/restar la diferencia (el puente solo cuenta cada línea una vez).
			if ( $reduced > 0 ) { $stock[] = array( 'itemId' => $item->get_id(), 'wooId' => $woo, 'delta' => (int) $item->get_quantity() - $qty ); }
			if ( 0 === $qty ) {
				wc_maybe_adjust_line_item_product_stock( $item, 0 );
				$changes[] = 'Quitado: ' . $item->get_name();
				$order->remove_item( $item->get_id() );
				continue;
			}
			$unit = (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() );
			$changes[] = $item->get_name() . ': ' . $item->get_quantity() . ' → ' . $qty;
			$item->set_quantity( $qty ); $item->set_subtotal( $unit * $qty ); $item->set_total( $unit * $qty ); $item->save();
			wc_maybe_adjust_line_item_product_stock( $item );
		}
		foreach ( (array) ( $p['add'] ?? array() ) as $a ) {
			$product = wc_get_product( absint( $a['wooId'] ?? 0 ) );
			$qty = max( 1, (int) ( $a['qty'] ?? 1 ) );
			if ( ! $product ) { continue; }
			$item_id = $order->add_product( $product, $qty );
			$item = $order->get_item( $item_id );
			if ( $item ) { wc_maybe_adjust_line_item_product_stock( $item ); }
			$changes[] = 'Añadido: ' . $qty . '× ' . $product->get_name();
		}
		if ( isset( $p['phone'] ) && '' !== trim( (string) $p['phone'] ) && $p['phone'] !== $order->get_billing_phone() ) { $order->set_billing_phone( sanitize_text_field( (string) $p['phone'] ) ); $changes[] = 'Teléfono'; }
		if ( isset( $p['address'] ) && '' !== trim( (string) $p['address'] ) ) { $order->set_shipping_address_1( sanitize_text_field( (string) $p['address'] ) ); $order->set_billing_address_1( sanitize_text_field( (string) $p['address'] ) ); $changes[] = 'Dirección'; }
		if ( isset( $p['note'] ) ) { $order->set_customer_note( sanitize_textarea_field( (string) $p['note'] ) ); }
		if ( ! $order->get_items( 'line_item' ) ) { return new WP_Error( 'cvd_panel_order', 'El pedido no puede quedar sin productos: cancélalo en su lugar.', array( 'status' => 422 ) ); }
		$order->calculate_totals( false );
		if ( $changes ) { $order->add_order_note( 'Cambiado desde el panel: ' . implode( '; ', $changes ) ); }
		$order->save();
		if ( class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_pending( $order->get_id() ); }
		return self::no_cache( array( 'ok' => true, 'changes' => $changes, 'stock' => $stock, 'total' => (float) wc_get_order( $order->get_id() )->get_total() ) );
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
			$rows[] = array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'modified' => $order->get_date_modified() ? $order->get_date_modified()->date( 'c' ) : null, 'lines' => $lines );
		}
		return self::no_cache( array( 'orders' => $rows, 'now' => gmdate( 'c' ) ) );
	}

	/** Existencias actuales de la web por producto (para comparar con la caja). */
	public static function stock_levels(): WP_REST_Response {
		$rows = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private' ), 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
			$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $items as $p ) {
				$rows[] = array( 'wooId' => $p->get_id(), 'stock' => $p->managing_stock() ? (int) $p->get_stock_quantity() : null, 'price' => (float) $p->get_regular_price(), 'status' => $product->get_status() );
			}
		}
		return self::no_cache( array( 'products' => $rows ) );
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
	 * Campos: nexoId, name, price, category, imageUrl, active, sku.
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
		return self::no_cache( array( 'ok' => true, 'wooId' => $id, 'created' => ! $woo_id, 'url' => get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $id ) ) );
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
		$allowed = array( 'status', 'collectionMethod', 'collectedUsd', 'collectedCup', 'collectionNote', 'moneyConfirmed', 'handoverConfirmed', 'idempotencyKey', 'reason', 'refundUsd', 'refundCup', 'voidMessenger' );
		$params = array_intersect_key( $params, array_flip( $allowed ) );
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
