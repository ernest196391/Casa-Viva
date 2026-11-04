<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reseñas reales pedidas por VivaBot (Ernesto 4-nov, Core 3.14.139).
 *
 * Después de la entrega, el bot escribe UNA sola vez al cliente directo de Casa Viva y le pide estrellas (opcional),
 * un comentario corto y permiso para publicarlo con su nombre e inicial. Si el cliente lo da, el bot lo guarda aquí como
 * reseña aprobada de WooCommerce en el producto del pedido. Nunca se inventa: solo el texto del cliente y solo con permiso.
 *
 *  GET  /bot/resenas/candidatos?dias=4   pedidos terminados (entregados o recogidos) de clientes directos, sin pruebas y sin pedir aún
 *  POST /bot/resenas/(id)/pedida         el bot va a escribir: se marca ANTES de enviar, así nunca se pide dos veces
 *  POST /bot/resenas                     guarda la reseña (pedido, producto, texto, nombre, estrellas, permiso); sin permiso, 422
 *
 * Pedidos de gestora y clientes de una gestora: nunca (al cliente de una gestora se le habla por su gestora).
 * Autenticación: la llave del bot (X-Vivabot-Key), igual que CVD_Bot_Bridge.
 */
final class CVD_Resenas_Bot {
	private const NS = 'casa-viva/v1';
	public const ASKED = '_cvd_resena_pedida_at';
	public const SAVED = '_cvd_resena_guardada';
	private const MAX_DAYS = 10;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/resenas/candidatos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'candidates' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/resenas/(?P<id>\d+)/pedida', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'asked' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/resenas', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $auth ) );
	}

	// ------------------------------------------------------------------ Reglas puras (tests/resenas-bot.test.php)

	/** Texto plano: sin etiquetas, sin caracteres de control y con los espacios juntos. */
	public static function plain( string $s, int $max ): string {
		$s = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = trim( preg_replace( '/[ \t]+/u', ' ', preg_replace( "/\R{3,}/u", "\n\n", (string) $s ) ) );
		return mb_substr( $s, 0, $max );
	}

	/** Solo un permiso explícito vale: true, 1, '1', 'si', 'sí'. Cualquier otra cosa (vacío, 'no', 'quizás') = sin permiso. */
	public static function permission_ok( $value ): bool {
		if ( true === $value || 1 === $value ) { return true; }
		return in_array( mb_strtolower( trim( (string) $value ) ), array( '1', 'si', 'sí', 'true' ), true );
	}

	/** «María P.»: el nombre y la inicial del apellido, como se le dice al cliente que se publicará. */
	public static function display_name( string $first, string $last ): string {
		$first = trim( (string) preg_replace( '/\s+/u', ' ', $first ) );
		$first = (string) strtok( $first, ' ' );
		if ( '' === $first ) { return ''; }
		$first = mb_strtoupper( mb_substr( $first, 0, 1 ) ) . mb_strtolower( mb_substr( $first, 1 ) );
		$initial = mb_strtoupper( mb_substr( trim( $last ), 0, 1 ) );
		return preg_match( '/^\p{L}$/u', $initial ) ? "{$first} {$initial}." : $first;
	}

	/**
	 * Valida lo que manda el bot. → array( text, name, rating ) o un código de error:
	 * 'permission' (sin permiso explícito), 'text' (comentario vacío o de menos de 5 letras), 'name', 'rating' (fuera de 0–5).
	 */
	public static function clean_review( array $in ) {
		if ( ! self::permission_ok( $in['permission'] ?? '' ) ) { return 'permission'; }
		$text = self::plain( (string) ( $in['text'] ?? '' ), 400 );
		if ( mb_strlen( preg_replace( '/[^\p{L}]/u', '', $text ) ) < 5 ) { return 'text'; }
		$name = self::plain( str_replace( array( "\n", "\r" ), ' ', (string) ( $in['name'] ?? '' ) ), 60 );
		if ( ! preg_match( '/\p{L}/u', $name ) ) { return 'name'; }
		$raw = $in['rating'] ?? 0;
		if ( '' === $raw || null === $raw ) { $raw = 0; }
		if ( ! is_numeric( $raw ) || (int) $raw != $raw || (int) $raw < 0 || (int) $raw > 5 ) { return 'rating'; }
		return array( 'text' => $text, 'name' => $name, 'rating' => (int) $raw );
	}

	/** Teléfono del cliente con 53 delante (8 cifras cubanas → 53XXXXXXXX). '' si no parece un móvil. */
	public static function phone( string $raw ): string {
		$d = preg_replace( '/\D+/', '', $raw );
		if ( 8 === strlen( $d ) ) { $d = '53' . $d; }
		return strlen( $d ) >= 10 && strlen( $d ) <= 15 ? $d : '';
	}

	// ------------------------------------------------------------------ Qué pedidos sirven

	/**
	 * Por qué este pedido NO sirve para pedir reseña ('' = sí sirve). No mira si ya se pidió.
	 * gestora · cliente_de_gestora · prueba · no_terminado · sin_telefono
	 */
	public static function excluded( WC_Order $order, bool $store = false ): string {
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$is_gestora = 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) || $owner_id;
		// $store (si Ernesto lo enciende): lo vendido por Nana, Zaymi o Lennys con su cuenta es venta de la tienda, no de una gestora.
		if ( $is_gestora && ! ( $store && self::is_staff( $owner_id ) ) ) { return 'gestora'; }
		if ( ( class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) ) )
			|| '' !== (string) $order->get_meta( '_cvd_ensayo', true ) ) { return 'prueba'; }
		$customer = absint( $order->get_customer_id() );
		if ( $customer && ( '1' === (string) get_user_meta( $customer, '_cvd_test_account', true ) || array_intersect( array( 'cvd_gestora', 'cvd_messenger', 'administrator', 'shop_manager' ), (array) ( get_userdata( $customer )->roles ?? array() ) ) ) ) { return 'prueba'; }
		if ( '' === CVD_Bot_Bridge::finished_kind( $order ) ) { return 'no_terminado'; }
		$phone = self::customer_phone( $order );
		if ( '' === $phone ) { return 'sin_telefono'; }
		// El teléfono es de quien vendió (Lennys o una dependienta comprando para probar o para sí): no es un cliente.
		if ( $owner_id && substr( self::phone( (string) ( get_user_meta( $owner_id, '_cvd_whatsapp', true ) ?: get_user_meta( $owner_id, 'billing_phone', true ) ) ), -8 ) === substr( $phone, -8 ) ) { return 'prueba'; }
		// D97: un cliente que ya es de una gestora es suyo para siempre; a él no se le escribe aunque compre directo.
		$owner = class_exists( 'CVD_Attribution' ) ? CVD_Attribution::customer_owner( $phone, (string) $order->get_billing_email(), $customer ) : null;
		$owner_user = absint( $owner['owner_user_id'] ?? 0 );
		if ( $owner && 'gestora' === sanitize_key( (string) ( $owner['owner_type'] ?? '' ) ) && $owner_user && ! ( $store && self::is_staff( $owner_user ) ) ) { return 'cliente_de_gestora'; }
		return '';
	}

	/** Cuenta de la tienda (dependienta o administradora) que vende con su usuario: Nana, Zaymi, Lennys. */
	public static function is_staff( int $user_id ): bool {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user && (bool) array_intersect( array( 'cvd_clerk', 'administrator', 'shop_manager' ), (array) $user->roles );
	}

	/** Quién vendió, para el bot: 'directo' (web sin gestora) o 'tienda' (con la cuenta de una dependienta). */
	private static function via( WC_Order $order ): string {
		return absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ? 'tienda' : 'directo';
	}

	private static function customer_phone( WC_Order $order ): string {
		return self::phone( (string) ( $order->get_meta( '_cvd_whatsapp_verified', true ) ?: ( $order->get_shipping_phone() ?: $order->get_billing_phone() ) ) );
	}

	/** Productos del pedido (el padre si es una variación), sin repetir: [ { id, name } ]. */
	private static function products( WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
			$id = absint( $item->get_product_id() );
			$p = $id ? wc_get_product( $id ) : null;
			if ( ! $p || 'publish' !== $p->get_status() || isset( $out[ $id ] ) ) { continue; }
			$out[ $id ] = array( 'id' => $id, 'name' => wp_strip_all_tags( $p->get_name() ) );
		}
		return array_values( $out );
	}

	/** Cuándo terminó (UTC 'Y-m-d H:i:s'), con la misma regla que el aviso a la gestora. */
	private static function done_at( WC_Order $order, string $kind ): string {
		$at = 'pickup' === $kind ? (string) $order->get_meta( '_cvd_pickup_handed_over_at', true ) : ( (string) $order->get_meta( '_cvd_delivered_at', true ) ?: (string) $order->get_meta( '_cvd_to_customer_at', true ) );
		if ( '' !== $at ) { return $at; }
		$date = $order->get_date_completed() ?: $order->get_date_modified();
		return $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
	}

	// ------------------------------------------------------------------ Rutas

	/** Pedidos terminados en los últimos ?dias (1–10, por defecto 4) que sirven y a los que aún no se pidió reseña. Solo lee. */
	public static function candidates( WP_REST_Request $request ): WP_REST_Response {
		$days = min( self::MAX_DAYS, max( 1, absint( $request->get_param( 'dias' ) ) ?: 4 ) );
		$store = (bool) $request->get_param( 'tienda' ); // también lo vendido con la cuenta de una dependienta (Ernesto decide)
		$since = time() - $days * DAY_IN_SECONDS;
		$orders = wc_get_orders( array( 'limit' => 500, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC',
			'status' => array( 'processing', 'on-hold', 'completed' ), 'date_modified' => '>=' . $since ) );
		$list = array(); $skipped = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			if ( '' !== (string) $order->get_meta( self::ASKED, true ) ) { $skipped['ya_pedida'] = ( $skipped['ya_pedida'] ?? 0 ) + 1; continue; }
			$why = self::excluded( $order, $store );
			if ( '' !== $why ) { $skipped[ $why ] = ( $skipped[ $why ] ?? 0 ) + 1; continue; }
			$kind = CVD_Bot_Bridge::finished_kind( $order );
			$done = self::done_at( $order, $kind );
			if ( '' === $done || strtotime( $done . ' UTC' ) < $since ) { $skipped['antiguo'] = ( $skipped['antiguo'] ?? 0 ) + 1; continue; }
			$products = self::products( $order );
			if ( ! $products ) { $skipped['sin_producto'] = ( $skipped['sin_producto'] ?? 0 ) + 1; continue; }
			$list[] = array(
				'id'        => $order->get_id(),
				'number'    => $order->get_order_number(),
				'via'       => self::via( $order ),
				'kind'      => $kind, // 'delivery' (lo llevó el mensajero) o 'pickup' (lo recogió en la tienda)
				'doneAt'    => $done,
				'phone'     => self::customer_phone( $order ),
				'firstName' => trim( (string) strtok( trim( (string) $order->get_billing_first_name() ), ' ' ) ),
				'signature' => self::display_name( (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name() ),
				'products'  => $products,
			);
		}
		$response = rest_ensure_response( array( 'candidates' => $list, 'skipped' => $skipped, 'days' => $days ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** El bot va a pedir la reseña: se marca antes de enviar. Si ya estaba marcado, already = true y el bot NO escribe. */
	public static function asked( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		if ( '' !== (string) $order->get_meta( self::ASKED, true ) ) { return rest_ensure_response( array( 'ok' => true, 'already' => true ) ); }
		$via = self::via( $order );
		$why = self::excluded( $order, (bool) $request->get_param( 'tienda' ) );
		if ( '' !== $why ) { return new WP_Error( 'cvd_resena_no', 'Este pedido no sirve para pedir reseña: ' . $why, array( 'status' => 409, 'reason' => $why ) ); }
		$order->update_meta_data( self::ASKED, current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_resena_via', $via );
		$order->add_order_note( 'VivaBot: se le pidió al cliente su opinión por WhatsApp (una sola vez; solo se publica con su permiso).' );
		$order->save();
		return rest_ensure_response( array( 'ok' => true, 'already' => false ) );
	}

	/**
	 * Guarda la reseña del cliente en el producto del pedido. Solo si: el pedido sirve, se le pidió la reseña, el producto
	 * está en el pedido, hay permiso explícito y comentario real. Una reseña por pedido y producto. simular=1 no escribe.
	 */
	public static function save( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request->get_param( 'order' ) ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$clean = self::clean_review( array( 'permission' => $request->get_param( 'permission' ), 'text' => (string) $request->get_param( 'text' ),
			'name' => (string) $request->get_param( 'name' ), 'rating' => $request->get_param( 'rating' ) ) );
		if ( 'permission' === $clean ) { return new WP_Error( 'cvd_resena_permiso', 'Sin el permiso del cliente no se publica nada.', array( 'status' => 422 ) ); }
		if ( is_string( $clean ) ) { return new WP_Error( 'cvd_resena_' . $clean, 'Falta el comentario del cliente, su nombre o las estrellas no son de 1 a 5.', array( 'status' => 422 ) ); }
		// Lo de la tienda solo vale si se pidió así (la marca la puso /pedida con tienda=1).
		$why = self::excluded( $order, 'tienda' === (string) $order->get_meta( '_cvd_resena_via', true ) || (bool) $request->get_param( 'tienda' ) );
		if ( '' !== $why ) { return new WP_Error( 'cvd_resena_no', 'Este pedido no sirve para reseña: ' . $why, array( 'status' => 409 ) ); }
		$asked = '' !== (string) $order->get_meta( self::ASKED, true );
		if ( ! $asked && ! $request->get_param( 'simular' ) ) { return new WP_Error( 'cvd_resena_no_pedida', 'A este cliente no se le pidió reseña.', array( 'status' => 409 ) ); }
		$product_id = absint( $request->get_param( 'product' ) );
		$ids = wp_list_pluck( self::products( $order ), 'id' );
		if ( 1 === count( $ids ) && ! $product_id ) { $product_id = (int) $ids[0]; }
		if ( ! in_array( $product_id, array_map( 'intval', $ids ), true ) ) { return new WP_Error( 'cvd_resena_producto', 'Ese producto no está en el pedido.', array( 'status' => 422 ) ); }
		$existing = get_comments( array( 'post_id' => $product_id, 'type' => 'review', 'status' => 'all', 'number' => 1, 'fields' => 'ids',
			'meta_query' => array( array( 'key' => '_cvd_resena_pedido', 'value' => (string) $order->get_id() ) ) ) );
		if ( $existing ) { return rest_ensure_response( array( 'ok' => true, 'already' => true, 'saved' => (int) $existing[0] ) ); }
		$phone = self::customer_phone( $order );
		$who = sprintf( 'pedido #%s · WhatsApp …%s', $order->get_order_number(), substr( $phone, -4 ) );
		$words = self::plain( (string) $request->get_param( 'permission_text' ), 200 );
		if ( $request->get_param( 'simular' ) ) {
			return rest_ensure_response( array( 'ok' => true, 'simulated' => true, 'asked' => $asked, 'product' => $product_id, 'review' => $clean, 'permiso' => $who, 'permiso_texto' => $words ) );
		}
		$comment = wp_insert_comment( array( 'comment_post_ID' => $product_id, 'comment_author' => $clean['name'], 'comment_content' => $clean['text'],
			'comment_type' => 'review', 'comment_approved' => 1, 'comment_author_email' => '' ) );
		if ( ! $comment ) { return new WP_Error( 'cvd_resena_save', 'No se pudo guardar la reseña.', array( 'status' => 500 ) ); }
		if ( $clean['rating'] ) { update_comment_meta( $comment, 'rating', $clean['rating'] ); }
		update_comment_meta( $comment, 'verified', 1 ); // compró este producto en este pedido (comprobado arriba)
		update_comment_meta( $comment, '_cvd_resena_origen', 'bot' );
		update_comment_meta( $comment, '_cvd_resena_permiso', $who );
		update_comment_meta( $comment, '_cvd_resena_pedido', (string) $order->get_id() );
		if ( '' !== $words ) { update_comment_meta( $comment, '_cvd_resena_permiso_texto', $words ); }
		if ( class_exists( 'WC_Comments' ) ) { WC_Comments::clear_transients( $product_id ); }
		$order->add_meta_data( self::SAVED, (string) $comment );
		$order->add_order_note( sprintf( 'VivaBot: el cliente dejó su reseña de «%s» con permiso para publicarla (reseña %d).', get_the_title( $product_id ), $comment ) );
		$order->save();
		return rest_ensure_response( array( 'ok' => true, 'already' => false, 'saved' => (int) $comment, 'product' => $product_id ) );
	}
}
