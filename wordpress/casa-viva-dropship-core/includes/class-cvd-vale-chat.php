<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.67 «VALE por chat» (Ernesto, 24-oct: «a veces las gestoras no tienen conexión»). La gestora escribe VALE a
 * VivaBot y, contestando con números, el bot hace el pedido de la web por ella. Aquí solo está la parte de la web:
 * VivaBot hace la conversación (src/vale-chat.js) y llama a estas rutas con la cabecera X-Vivabot-Key.
 *
 *   GET  /bot/vale/inicio?phone=      ¿es gestora aprobada? Su nombre, sus 3 mensajeros más usados y las formas de pago.
 *   GET  /bot/vale/buscar?phone=&q=   productos (su precio, existencia, colores, bajo pedido). Máx. 6.
 *   GET  /bot/vale/zonas[?municipio=] municipios de La Habana, o los repartos de uno con su tarifa oficial.
 *   POST /bot/vale/cotizar            líneas + entrega → total en USD, mensajería en CUP y lo que falta. No guarda nada.
 *   POST /bot/vale/crear              crea el pedido como el pago de la web (on-hold: descuenta existencia, comisión,
 *                                     cliente, reparto). Idempotente con idempotencyKey. Devuelve el vale para WhatsApp.
 *
 * Mismas reglas que el pago de la web (3.14.63): obligatorio el teléfono del cliente y, con mensajería, municipio y
 * reparto; nombre, calle y forma de pago opcionales. El precio es SIEMPRE el de la web (el de ella si lo subió), nunca
 * el que escriba nadie. El teléfono de la propia gestora como cliente no pasa sin selfPurchase (como «Es para mí»).
 * El pedido sale al reparto por los caminos de siempre (woocommerce_checkout_order_created): grupo, o directo al
 * mensajero elegido (CVD_Direct_Dispatch::PREFERRED_META); lo bajo pedido se confirma antes con el proveedor.
 */
final class CVD_Vale_Chat {
	private const NS = 'casa-viva/v1';
	private const MAX_LINES = 8;
	private const MAX_QTY = 50;
	private const CREATED_VIA = 'casa-viva-vale-chat';
	public const KEY_META = '_cvd_vale_chat_key';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/vale/inicio', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'inicio' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/vale/buscar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'buscar' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/vale/zonas', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'zonas' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/vale/cotizar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cotizar' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/vale/crear', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'crear' ), 'permission_callback' => $auth ) );
	}

	private static function json( array $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	private static function phone_key( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( $d ) >= 8 ? substr( $d, -8 ) : '';
	}

	/** Gestora aprobada por su WhatsApp (o, si no lo tiene guardado, por su teléfono del perfil), como el puente del bot. */
	public static function gestora_by_phone( string $phone ): ?WP_User {
		$key = self::phone_key( $phone );
		if ( '' === $key ) { return null; }
		foreach ( get_users( array( 'role' => 'cvd_gestora' ) ) as $user ) {
			if ( 'approved' !== CVD_Registration::status_for( $user, 'gestora' ) ) { continue; }
			$own = (string) get_user_meta( $user->ID, '_cvd_whatsapp', true );
			$mine = '' !== self::phone_key( $own ) ? $own : (string) get_user_meta( $user->ID, 'billing_phone', true );
			if ( $key === self::phone_key( $mine ) ) { return $user; }
		}
		return null;
	}

	private static function gestora_or_error( WP_REST_Request $request ) {
		$gestora = self::gestora_by_phone( (string) $request->get_param( 'phone' ) );
		return $gestora ?: new WP_Error( 'cvd_vale_not_gestora', 'Ese número no es de una gestora aprobada.', array( 'status' => 403 ) );
	}

	/** Sus 3 mensajeros más usados (últimos 60 pedidos suyos), solo los que siguen aprobados. */
	private static function favorite_messengers( int $gestora_id ): array {
		$ids = wc_get_orders( array( 'limit' => 60, 'return' => 'ids', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora_id, 'orderby' => 'date', 'order' => 'DESC' ) );
		$count = array();
		foreach ( $ids as $id ) {
			$m = absint( get_post_meta( $id, '_cvd_messenger_user_id', true ) );
			if ( ! $m ) { $o = wc_get_order( $id ); $m = $o ? absint( $o->get_meta( '_cvd_messenger_user_id', true ) ) : 0; }
			if ( $m ) { $count[ $m ] = ( $count[ $m ] ?? 0 ) + 1; }
		}
		arsort( $count );
		$out = array();
		foreach ( array_keys( $count ) as $mid ) {
			$user = get_userdata( $mid );
			if ( $user instanceof WP_User && CVD_Registration::is_approved_messenger( $user ) ) { $out[] = array( 'id' => $user->ID, 'name' => $user->display_name, 'veces' => $count[ $mid ] ); }
			if ( count( $out ) >= 3 ) { break; }
		}
		return $out;
	}

	public static function inicio( WP_REST_Request $request ): WP_REST_Response {
		$gestora = self::gestora_by_phone( (string) $request->get_param( 'phone' ) );
		if ( ! $gestora ) { return self::json( array( 'ok' => false, 'reason' => 'not_gestora' ) ); }
		$payments = array();
		foreach ( CVD_Cuban_Checkout::PAYMENT_KINDS as $kind => $def ) { $payments[] = array( 'kind' => $kind, 'label' => $def['label'] ); }
		return self::json( array(
			'ok'         => true,
			'gestora'    => array( 'id' => $gestora->ID, 'name' => $gestora->display_name, 'code' => (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true ) ),
			'messengers' => self::favorite_messengers( $gestora->ID ),
			'payments'   => $payments,
			'palla'      => 'si' === (string) get_option( 'cvd_palla', 'no' ),
		) );
	}

	/** Precio de la gestora (el suyo si lo subió) de un producto o variante. */
	private static function her_price( WC_Product $product, int $gestora_id ): array {
		$base = (float) get_post_meta( $product->get_id(), '_price', true );
		$unit = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $gestora_id, $base ) : $base;
		return array( $base, $unit > 0 ? $unit : $base );
	}

	private static function is_market( WC_Product $product ): bool {
		return class_exists( 'CVD_Datos_A_Mano' ) && CVD_Datos_A_Mano::is_bajo_pedido( $product );
	}

	private static function stock_of( WC_Product $product ): ?int {
		return $product->managing_stock() ? max( 0, (int) $product->get_stock_quantity() ) : null;
	}

	public static function buscar( WP_REST_Request $request ) {
		$gestora = self::gestora_or_error( $request );
		if ( is_wp_error( $gestora ) ) { return $gestora; }
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$ids = '' !== trim( $q ) && class_exists( 'CVD_Product_Search' ) ? CVD_Product_Search::search( $q, 12 ) : array();
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) { continue; }
			if ( class_exists( 'CVD_Private_Products' ) && method_exists( 'CVD_Private_Products', 'is_private' ) && CVD_Private_Products::is_private( $product ) ) { continue; }
			list( , $unit ) = self::her_price( $product, $gestora->ID );
			$variants = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$v = wc_get_product( $child_id );
					if ( ! $v || 'publish' !== $v->get_status() || ! $v->is_purchasable() ) { continue; }
					list( , $vu ) = self::her_price( $v, $gestora->ID );
					$variants[] = array( 'id' => $v->get_id(), 'label' => wp_strip_all_tags( wc_get_formatted_variation( $v, true, false, false ) ), 'priceUsd' => round( $vu, 2 ), 'stock' => self::stock_of( $v ), 'inStock' => $v->is_in_stock() );
				}
				if ( ! array_filter( $variants, static fn( $v ) => $v['inStock'] ) && ! self::is_market( $product ) ) { continue; }
			} elseif ( ! $product->is_in_stock() && ! self::is_market( $product ) ) {
				continue; // agotado: no se ofrece (la gestora no puede venderlo ahora)
			}
			$items[] = array(
				'id'       => $product->get_id(),
				'name'     => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'priceUsd' => round( $unit, 2 ),
				'stock'    => self::stock_of( $product ),
				'inStock'  => $product->is_in_stock(),
				'market'   => self::is_market( $product ),
				'variants' => $variants,
				'url'      => (string) get_permalink( $product->get_id() ),
			);
			if ( count( $items ) >= 6 ) { break; }
		}
		return self::json( array( 'ok' => true, 'items' => $items ) );
	}

	public static function zonas( WP_REST_Request $request ): WP_REST_Response {
		$localities = CVD_Shipping_Rates::localities();
		$municipio = sanitize_text_field( (string) $request->get_param( 'municipio' ) );
		if ( '' === $municipio ) {
			$names = array_keys( $localities );
			usort( $names, static fn( $a, $b ) => strcmp( remove_accents( $a ), remove_accents( $b ) ) );
			return self::json( array( 'ok' => true, 'municipalities' => $names ) );
		}
		$match = null;
		foreach ( array_keys( $localities ) as $name ) { if ( remove_accents( mb_strtolower( $name ) ) === remove_accents( mb_strtolower( $municipio ) ) ) { $match = $name; break; } }
		if ( null === $match ) { return self::json( array( 'ok' => false, 'reason' => 'municipio' ) ); }
		$zones = array();
		foreach ( $localities[ $match ] as $zone ) {
			$q = CVD_Shipping_Rates::quote( $match, $zone );
			$zones[] = array( 'zone' => $zone, 'feeCup' => absint( $q['fee'] ?? 0 ) );
		}
		usort( $zones, static fn( $a, $b ) => strcmp( remove_accents( $a['zone'] ), remove_accents( $b['zone'] ) ) );
		return self::json( array( 'ok' => true, 'municipality' => $match, 'zones' => $zones ) );
	}

	/**
	 * Lee y comprueba las líneas: [{productId, variationId?, quantity}] → productos de verdad con el precio de ella.
	 * @return array|WP_Error
	 */
	private static function lines( array $raw, int $gestora_id ) {
		if ( ! $raw || count( $raw ) > self::MAX_LINES ) { return new WP_Error( 'cvd_vale_lines', 'Elige entre 1 y 8 productos.', array( 'status' => 400 ) ); }
		$out = array();
		foreach ( $raw as $line ) {
			$pid = absint( $line['productId'] ?? 0 ); $vid = absint( $line['variationId'] ?? 0 );
			$qty = absint( $line['quantity'] ?? 0 );
			if ( $qty < 1 || $qty > self::MAX_QTY ) { return new WP_Error( 'cvd_vale_qty', 'La cantidad tiene que ser de 1 a 50.', array( 'status' => 400 ) ); }
			$product = wc_get_product( $vid ?: $pid );
			if ( ! $product || ( $vid && $product->get_parent_id() !== $pid ) ) { return new WP_Error( 'cvd_vale_product', 'Ese producto no existe.', array( 'status' => 400 ) ); }
			if ( $product->is_type( 'variable' ) ) { return new WP_Error( 'cvd_vale_variant', 'Falta elegir el color o modelo de ' . $product->get_name() . '.', array( 'status' => 400 ) ); }
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( ! $parent || 'publish' !== $parent->get_status() || ! $product->is_purchasable() ) { return new WP_Error( 'cvd_vale_product', 'Ese producto ya no está a la venta.', array( 'status' => 400 ) ); }
			$market = self::is_market( $parent );
			$stock = self::stock_of( $product );
			if ( ! $market && ( ! $product->is_in_stock() || ( null !== $stock && $stock < $qty ) ) ) {
				return new WP_Error( 'cvd_vale_stock', sprintf( 'De %s %s.', $product->get_name(), null !== $stock && $stock > 0 ? 'solo quedan ' . $stock : 'no queda ninguno' ), array( 'status' => 409 ) );
			}
			list( $base, $unit ) = self::her_price( $product, $gestora_id );
			$out[] = array( 'product' => $product, 'quantity' => $qty, 'base' => $base, 'unit' => $unit, 'market' => $market,
				'name' => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ), 'stock' => $stock );
		}
		return $out;
	}

	/** Entrega: pickup, o delivery con municipio y reparto oficiales. @return array|WP_Error */
	private static function delivery( array $p ) {
		$type = 'pickup' === sanitize_key( (string) ( $p['fulfillment'] ?? '' ) ) ? 'pickup' : 'delivery';
		if ( 'pickup' === $type ) { return array( 'type' => 'pickup', 'municipality' => '', 'zone' => '', 'fee' => 0, 'status' => 'pickup', 'quote' => array() ); }
		$municipality = sanitize_text_field( (string) ( $p['municipality'] ?? '' ) ); $zone = sanitize_text_field( (string) ( $p['zone'] ?? '' ) );
		if ( '' === $municipality || '' === $zone ) { return new WP_Error( 'cvd_vale_zone', 'Con mensajería hacen falta el municipio y el reparto.', array( 'status' => 400 ) ); }
		$quote = CVD_Shipping_Rates::quote( $municipality, $zone );
		if ( 'zone' !== ( $quote['status'] ?? '' ) ) {
			// «Vedado» → «El Vedado»: si un solo reparto oficial de ese municipio contiene lo escrito (o al revés), es ese.
			$typed = remove_accents( mb_strtolower( trim( $zone ) ) );
			$hits = array();
			foreach ( CVD_Shipping_Rates::localities() as $mun => $zones ) {
				if ( remove_accents( mb_strtolower( $mun ) ) !== remove_accents( mb_strtolower( $municipality ) ) ) { continue; }
				foreach ( $zones as $official ) {
					$o = remove_accents( mb_strtolower( $official ) );
					if ( strlen( $typed ) >= 4 && ( false !== strpos( $o, $typed ) || false !== strpos( $typed, $o ) ) ) { $hits[] = array( $mun, $official ); }
				}
			}
			if ( 1 === count( $hits ) ) { $municipality = $hits[0][0]; $quote = CVD_Shipping_Rates::quote( $hits[0][0], $hits[0][1] ); }
		}
		return array( 'type' => 'delivery', 'municipality' => $municipality, 'zone' => (string) ( $quote['label'] ?? $zone ), 'fee' => absint( $quote['fee'] ?? 0 ), 'status' => sanitize_key( (string) ( $quote['status'] ?? 'pending' ) ), 'quote' => $quote );
	}

	private static function summary( array $lines, array $delivery ): array {
		$total = 0.0; $rows = array();
		foreach ( $lines as $l ) {
			$total += $l['unit'] * $l['quantity'];
			$rows[] = array( 'name' => $l['name'], 'quantity' => $l['quantity'], 'unitUsd' => round( $l['unit'], 2 ), 'totalUsd' => round( $l['unit'] * $l['quantity'], 2 ), 'market' => $l['market'], 'stock' => $l['stock'] );
		}
		return array( 'lines' => $rows, 'totalUsd' => round( $total, 2 ), 'market' => (bool) array_filter( $lines, static fn( $l ) => $l['market'] ),
			'shipping' => array( 'type' => $delivery['type'], 'feeCup' => $delivery['fee'], 'status' => $delivery['status'], 'zone' => $delivery['zone'], 'municipality' => $delivery['municipality'] ) );
	}

	public static function cotizar( WP_REST_Request $request ) {
		$gestora = self::gestora_or_error( $request );
		if ( is_wp_error( $gestora ) ) { return $gestora; }
		$p = (array) $request->get_json_params();
		$lines = self::lines( (array) ( $p['lines'] ?? array() ), $gestora->ID );
		if ( is_wp_error( $lines ) ) { return $lines; }
		$delivery = self::delivery( $p );
		if ( is_wp_error( $delivery ) ) { return $delivery; }
		return self::json( array( 'ok' => true ) + self::summary( $lines, $delivery ) );
	}

	public static function crear( WP_REST_Request $request ) {
		$gestora = self::gestora_or_error( $request );
		if ( is_wp_error( $gestora ) ) { return $gestora; }
		$p = (array) $request->get_json_params();
		$key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $p['idempotencyKey'] ?? '' ) );
		if ( strlen( $key ) < 12 ) { return new WP_Error( 'cvd_vale_key', 'Falta la clave del vale.', array( 'status' => 400 ) ); }
		$hash = hash( 'sha256', 'vale-chat|' . $key );
		$existing = wc_get_orders( array( 'limit' => 1, 'meta_key' => self::KEY_META, 'meta_value' => $hash ) );
		if ( $existing ) { return self::json( self::created( $existing[0], true ) ); }
		$lock = '_cvd_vale_chat_lock_' . $hash;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - absint( get_option( $lock, 0 ) ) > 120 ) { delete_option( $lock ); }
			if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'cvd_vale_busy', 'Ese vale ya se está creando. Espera un momento.', array( 'status' => 409 ) ); }
		}
		$order = null;
		try {
			$lines = self::lines( (array) ( $p['lines'] ?? array() ), $gestora->ID );
			if ( is_wp_error( $lines ) ) { delete_option( $lock ); return $lines; }
			$delivery = self::delivery( $p );
			if ( is_wp_error( $delivery ) ) { delete_option( $lock ); return $delivery; }
			$customer = (array) ( $p['customer'] ?? array() );
			$phone = preg_replace( '/\D+/', '', (string) ( $customer['phone'] ?? '' ) );
			if ( strlen( $phone ) === 10 && 0 === strpos( $phone, '53' ) ) { $phone = substr( $phone, 2 ); }
			// 3.14.69.12 (Dany, Ernesto 26-oct): en la recogida en tienda el teléfono del cliente es opcional (el vale es de la
			// gestora, que sabe quién recoge; el bot lo pide con «0 = sin teléfono»); con mensajería, obligatorio (D110).
			// Si lo escriben, completo.
			$pickup = 'delivery' !== $delivery['type'];
			if ( '' !== $phone && strlen( $phone ) < 8 ) { delete_option( $lock ); return new WP_Error( 'cvd_vale_phone', 'El teléfono del cliente va completo (8 cifras).', array( 'status' => 400 ) ); }
			if ( '' === $phone && ! $pickup ) { delete_option( $lock ); return new WP_Error( 'cvd_vale_phone', 'Falta el teléfono del cliente (8 cifras).', array( 'status' => 400 ) ); }
			$own = CVD_Cuban_Checkout::own_phone_match( $phone, array( $gestora->ID ) );
			if ( $own && empty( $p['selfPurchase'] ) ) { delete_option( $lock ); return new WP_Error( 'cvd_vale_own_phone', 'Ese teléfono es el tuyo. Si la compra es para ti, dilo («es para mí»); si es de un cliente, pon el teléfono del cliente.', array( 'status' => 422 ) ); }
			$kind = sanitize_key( (string) ( $p['paymentKind'] ?? '' ) );
			if ( '' !== $kind && ! isset( CVD_Cuban_Checkout::PAYMENT_KINDS[ $kind ] ) ) { $kind = ''; }

			$order = wc_create_order( array( 'status' => 'pending', 'created_via' => self::CREATED_VIA ) );
			if ( is_wp_error( $order ) ) { throw new RuntimeException( $order->get_error_message() ); }
			foreach ( $lines as $l ) {
				$item_id = $order->add_product( $l['product'], $l['quantity'], array( 'subtotal' => $l['unit'] * $l['quantity'], 'total' => $l['unit'] * $l['quantity'] ) );
				$item = $order->get_item( $item_id );
				if ( $item ) {
					$item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $l['base'], 4 ), true );
					$item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $l['unit'], 4 ), true );
					$item->add_meta_data( '_cvd_margin_unit', wc_format_decimal( max( 0, $l['unit'] - $l['base'] ), 4 ), true );
					$item->add_meta_data( '_cvd_pricing_gestora_user_id', $gestora->ID, true );
					$item->save();
				}
			}
			$name = trim( sanitize_text_field( (string) ( $customer['name'] ?? '' ) ) );
			$parts = '' !== $name ? preg_split( '/\s+/', $name, 2 ) : array( '', '' );
			$order->set_billing_first_name( $parts[0] ?? '' ); $order->set_billing_last_name( $parts[1] ?? '' );
			$order->set_billing_phone( $phone ); $order->set_billing_country( 'CU' ); $order->set_currency( get_woocommerce_currency() );
			$order->update_meta_data( '_cvd_fulfillment_type', $delivery['type'] );
			if ( 'delivery' === $delivery['type'] ) {
				$address = trim( sanitize_text_field( (string) ( $p['address'] ?? '' ) ) );
				$order->set_billing_address_1( '' !== $address ? $address : CVD_Cuban_Checkout::ADDRESS_PENDING );
				$order->set_billing_city( $delivery['municipality'] ); $order->set_billing_state( 'LH' );
				$order->update_meta_data( '_cvd_province_name', CVD_Cuban_Checkout::provinces()['LH'] ?? 'La Habana' );
				$order->update_meta_data( '_cvd_locality', $delivery['zone'] );
				$order->update_meta_data( '_cvd_reference', sanitize_text_field( (string) ( $p['reference'] ?? '' ) ) );
				$order->update_meta_data( '_cvd_shipping_fee_cup', $delivery['fee'] );
				$order->update_meta_data( '_cvd_shipping_rate_status', $delivery['status'] );
				$order->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( (string) ( $delivery['quote']['label'] ?? '' ) ) );
				$order->update_meta_data( '_cvd_delivery_date', '' ); $order->update_meta_data( '_cvd_delivery_window', '' );
				$order->update_meta_data( '_cvd_change_required', array() );
				// Mensajero: el que ella eligió (va directo, CVD_Direct_Dispatch) o el grupo como siempre.
				$mid = absint( $p['messenger']['userId'] ?? 0 );
				$messenger = $mid ? get_userdata( $mid ) : false;
				if ( 'preferido' === sanitize_key( (string) ( $p['messenger']['mode'] ?? '' ) ) && $messenger instanceof WP_User && CVD_Registration::is_approved_messenger( $messenger ) ) {
					$order->update_meta_data( CVD_Direct_Dispatch::PREFERRED_META, $messenger->ID );
					$order->update_meta_data( CVD_Direct_Dispatch::SOURCE_META, 'vale-chat' );
				}
			}
			$order->update_meta_data( self::KEY_META, $hash );
			$order->update_meta_data( '_cvd_order_intake_source', 'vale_chat' );
			$order->set_customer_note( sanitize_textarea_field( (string) ( $p['note'] ?? '' ) ) );
			$order->set_payment_method( 'cvd_whatsapp' ); $order->set_payment_method_title( 'Vale por chat (VivaBot)' );
			CVD_Attribution::attach_operator_order( $order, $gestora->ID );
			$order->calculate_totals();
			CVD_Cuban_Checkout::apply_payment( $order, $kind, 'pickup' === $delivery['type'] );
			if ( $own ) { CVD_Cuban_Checkout::mark_self_declared( $order, $own, 'vale por chat' ); }
			$order->add_order_note( 'Vale por chat (VivaBot): lo hizo ' . $gestora->display_name . ' desde WhatsApp.' . ( '' === $kind ? ' Forma de pago: por confirmar con el cliente.' : '' ) );
			$order->save();
			do_action( 'woocommerce_checkout_order_created', $order );
			$order->update_status( 'on-hold', 'Vale por chat: pendiente de confirmación por WhatsApp (como los pedidos de la web).' );
			delete_option( $lock );
			return self::json( self::created( wc_get_order( $order->get_id() ), false ) );
		} catch ( Throwable $error ) {
			delete_option( $lock );
			if ( $order instanceof WC_Order && $order->get_id() ) { $order->delete( true ); }
			return new WP_Error( 'cvd_vale_create', 'No se pudo crear el vale: ' . $error->getMessage(), array( 'status' => 500 ) );
		}
	}

	private static function created( WC_Order $order, bool $replayed ): array {
		$reserved = class_exists( 'CVD_Datos_A_Mano' ) && CVD_Datos_A_Mano::order_reserved( $order );
		$market = class_exists( 'CVD_Datos_A_Mano' ) && CVD_Datos_A_Mano::order_has_bajo_pedido( $order );
		// 3.14.69.10 (enlaces-ref, D111): el seguimiento del vale lleva ?ref= de la gestora (CVD_Delivery::tracking_url, #48),
		// así si el cliente vuelve a comprar desde ahí la venta es de su gestora. Antes salía sin ref.
		$tracking = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::tracking_url( $order ) : '';
		if ( '' === $tracking ) { $tracking = add_query_arg( array( 'pedido' => $order->get_id(), 'clave' => $order->get_order_key() ), home_url( '/seguimiento/' ) ); }
		$messenger = class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::preferred_messenger( $order ) : null;
		return array(
			'ok'          => true,
			'replayed'    => $replayed,
			'orderId'     => $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'status'      => $order->get_status(),
			'totalUsd'    => round( (float) $order->get_total(), 2 ),
			'shippingCup' => class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : 0,
			'fulfillment' => (string) $order->get_meta( '_cvd_fulfillment_type', true ),
			'reserved'    => $reserved,
			'market'      => $market,
			'messenger'   => $messenger ? $messenger->display_name : '',
			'customer'    => array( 'phone' => preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ), 'first' => trim( (string) $order->get_billing_first_name() ) ),
			'trackingUrl' => $tracking,
			'valeText'    => class_exists( 'CVD_WhatsApp_Receipt_Template' ) ? CVD_WhatsApp_Receipt_Template::render( $order ) : '',
			// 3.14.70 (27-oct, Ernesto: #5582 Gretel): la venta es de quien arma el vale aunque el cliente ya tuviera otra
			// gestora. El bot se lo dice con otherGestoraText; nunca con cuál (privacidad).
			'otherGestora'     => 'yes' === (string) $order->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ),
			'otherGestoraText' => 'yes' === (string) $order->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ) ? CVD_Attribution::OTHER_GESTORA_TEXT . ' La venta es tuya: la armaste tú.' : '',
		);
	}
}
