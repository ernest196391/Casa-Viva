<?php

defined( 'ABSPATH' ) || exit;

/**
 * Puerta de VivaBot (WhatsApp) hacia Core (D29 · "un pedido, un hilo, 4 momentos").
 *
 * El bot es solo un canal: publica en el grupo las ofertas que Core ya abrió, pide a Core
 * que asigne al primer mensajero que responde, entrega el vale y registra hora y entrega.
 * Todas las escrituras pasan por CVD_Order_Transition_Service con el mensajero como actor,
 * así que Core sigue decidiendo quién gana la carrera y qué transición es válida.
 * Autenticación: cabecera X-Vivabot-Key (la misma clave que usa el asistente Curru).
 */
final class CVD_Bot_Bridge {
	private const NS = 'casa-viva/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( __CLASS__, 'authorized' );
		register_rest_route( self::NS, '/bot/dispatch', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'dispatch' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/announced', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'announced' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/claim', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'claim' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/eta', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eta' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/delivered', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'delivered' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'order_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/whatsapp', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'verified_whatsapp' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/gestora', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestora_link' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/gestora/orders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestora_orders' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/partners', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'partners' ), 'permission_callback' => $auth ) );
	}

	/** Gestoras y mensajeros con su estado de alta: el bot avisa de solicitudes nuevas y da la bienvenida al aprobar. */
	public static function partners(): WP_REST_Response {
		$rows = array();
		foreach ( array( 'cvd_gestora' => 'gestora', 'cvd_messenger' => 'mensajero' ) as $role => $kind ) {
			foreach ( get_users( array( 'role' => $role, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 200 ) ) as $user ) {
				$rows[] = array(
					'id'     => $user->ID,
					'kind'   => $kind,
					'name'   => $user->display_name,
					'phone'  => preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ) ),
					'status' => sanitize_key( (string) get_user_meta( $user->ID, '_cvd_account_status', true ) ) ?: 'pending',
					'since'  => $user->user_registered,
				);
			}
		}
		return self::no_cache( array( 'partners' => $rows, 'approveUrl' => admin_url( 'admin.php?page=cvd-gestoras' ) ) );
	}

	/** Lo que una gestora pregunta por WhatsApp: sus últimos pedidos, en qué punto van y su comisión. */
	public static function gestora_orders( WP_REST_Request $request ) {
		$gestora = self::user_by_phone( (string) $request->get_param( 'phone' ), 'cvd_gestora' );
		if ( ! $gestora ) { return self::no_cache( array( 'found' => false ) ); }
		$only = absint( $request->get_param( 'order' ) );
		if ( $only ) {
			// Un pedido concreto: solo si es de esta gestora (nunca se muestran pedidos ajenos).
			$one = wc_get_order( $only );
			$orders = $one instanceof WC_Order && ! $one instanceof WC_Order_Refund && absint( $one->get_meta( '_cvd_owner_user_id', true ) ) === $gestora->ID ? array( $one ) : array();
		} else {
			$orders = wc_get_orders( array( 'limit' => 8, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora->ID ) );
		}
		$list = array();
		$pending = 0.0; $approved = 0.0;
		foreach ( wc_get_orders( array( 'limit' => 200, 'type' => 'shop_order', 'return' => 'objects', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora->ID, 'status' => array( 'pending', 'processing', 'on-hold', 'completed' ) ) ) as $o ) {
			$amount = (float) $o->get_meta( '_cvd_commission_amount', true );
			$state = sanitize_key( (string) $o->get_meta( '_cvd_commission_status', true ) );
			if ( 'approved' === $state ) { $approved += $amount; } elseif ( in_array( $state, array( '', 'pending' ), true ) ) { $pending += $amount; }
		}
		foreach ( $orders as $o ) {
			$delivery = CVD_Delivery::status( $o );
			$messenger = get_userdata( absint( $o->get_meta( '_cvd_messenger_user_id', true ) ) );
			$list[] = array(
				'id' => $o->get_id(), 'date' => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd/m' ) : '',
				'customer' => $o->get_formatted_billing_full_name(), 'items' => self::items( $o ),
				'status' => 'cancelled' === $o->get_status() ? 'Cancelado' : ( 'pickup' === $o->get_meta( '_cvd_fulfillment_type', true ) ? ( 'completed' === $o->get_status() ? 'Recogido' : 'Para recoger en tienda' ) : CVD_Delivery::label( $delivery ) ),
				'messenger' => $messenger ? $messenger->display_name : '', 'eta' => (string) $o->get_meta( '_cvd_bot_eta', true ),
				'commission' => (float) $o->get_meta( '_cvd_commission_amount', true ), 'commissionStatus' => (string) $o->get_meta( '_cvd_commission_status', true ),
			);
		}
		return self::no_cache( array( 'found' => true, 'name' => $gestora->display_name, 'orders' => $list, 'commission' => array( 'pending' => round( $pending, 2 ), 'approved' => round( $approved, 2 ) ) ) );
	}

	/** Enlace personal de una gestora aprobada (por su WhatsApp): sus clientes compran desde ahí y la venta es suya. */
	public static function gestora_link( WP_REST_Request $request ) {
		$gestora = self::user_by_phone( (string) $request->get_param( 'phone' ), 'cvd_gestora' );
		if ( ! $gestora ) { return self::no_cache( array( 'found' => false ) ); }
		$code = (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true );
		if ( '' === $code ) { return self::no_cache( array( 'found' => true, 'name' => $gestora->display_name, 'link' => '' ) ); }
		return self::no_cache( array( 'found' => true, 'name' => $gestora->display_name, 'code' => $code, 'link' => add_query_arg( 'ref', rawurlencode( $code ), home_url( '/' ) ) ) );
	}

	/**
	 * El cliente mandó el vale desde su WhatsApp: ese número es seguro (el tecleado en la web puede
	 * tener erratas). Se guarda aparte y es el que usan el vale y los avisos. Si no coincide, se anota.
	 */
	public static function verified_whatsapp( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$phone = preg_replace( '/\D+/', '', (string) $request->get_param( 'phone' ) );
		if ( strlen( $phone ) < 8 ) { return new WP_Error( 'cvd_phone', 'Teléfono no válido.', array( 'status' => 400 ) ); }
		$typed = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		if ( (string) $order->get_meta( '_cvd_whatsapp_verified', true ) !== $phone ) {
			$order->update_meta_data( '_cvd_whatsapp_verified', $phone );
			$order->add_order_note( substr( $typed, -8 ) === substr( $phone, -8 )
				? 'VivaBot: el cliente envió el vale desde su WhatsApp (+' . $phone . ').'
				: 'VivaBot: el cliente envió el vale desde +' . $phone . ', distinto del teléfono escrito en la web (' . $typed . '). Se usa el de WhatsApp.' );
			$order->save();
		}
		return self::no_cache( array( 'ok' => true, 'matches' => substr( $typed, -8 ) === substr( $phone, -8 ) ) );
	}

	public static function authorized( WP_REST_Request $request ): bool {
		$key = (string) $request->get_header( 'x_vivabot_key' );
		return '' !== $key && CVD_Contextual_Assistant::vivabot_key_matches( $key );
	}

	/** Ofertas abiertas en Core: lo que el bot debe publicar en el grupo (sin datos del cliente). */
	public static function dispatch(): WP_REST_Response {
		$orders = wc_get_orders( array( 'limit' => 30, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'meta_key' => '_cvd_delivery_status', 'meta_value' => 'offered' ) );
		$offers = array();
		foreach ( $orders as $order ) {
			if ( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { continue; }
			$offers[] = array(
				'id'         => $order->get_id(),
				'zone'       => CVD_Delivery::destination_zone( $order ),
				'items'      => self::items( $order ),
				'earningCup' => CVD_Delivery::courier_amount( $order ),
				'window'     => self::window( $order ),
				'offeredAt'  => (string) $order->get_meta( '_cvd_delivery_offered_at', true ),
				'announced'  => '' !== (string) $order->get_meta( '_cvd_bot_announced_at', true ),
			);
		}
		return self::no_cache( array( 'offers' => $offers ) );
	}

	public static function announced( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! $order->get_meta( '_cvd_bot_announced_at', true ) ) {
			$order->update_meta_data( '_cvd_bot_announced_at', current_time( 'mysql', true ) );
			$order->add_order_note( 'VivaBot: oferta publicada en el grupo de mensajeros.' );
			$order->save();
		}
		return self::no_cache( array( 'ok' => true ) );
	}

	/** "Yo" en el grupo: Core asigna al primero; los demás reciben "ya está cogido". */
	public static function claim( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::messenger_by_phone( (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return self::no_cache( array( 'result' => 'not_messenger' ) ); }
		$current_owner = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( $current_owner === $messenger->ID ) { return self::no_cache( array( 'result' => 'yours', 'voucher' => self::voucher( $order ) ) ); }
		if ( $current_owner || 'offered' !== CVD_Delivery::status( $order ) ) { return self::no_cache( array( 'result' => 'taken' ) ); }
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'accepted', array(
			'actor_user_id'   => $messenger->ID,
			'idempotency_key' => 'bot-claim:' . $order->get_id() . ':' . $messenger->ID,
			'source'          => 'cvd_bot_bridge_claim',
			'metadata'        => array( 'messenger' => $messenger->ID, 'source' => 'whatsapp_group' ),
			'precondition'    => static function ( WC_Order $locked, string $current ) use ( $messenger ) {
				$assigned = absint( $locked->get_meta( '_cvd_messenger_user_id', true ) );
				if ( ( $assigned && $assigned !== $messenger->ID ) || 'offered' !== $current ) { return CVD_Order_Transition_Service::CONFLICT; }
				return true;
			},
			'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $actor, $at ) use ( $messenger ): void {
				$locked->update_meta_data( '_cvd_messenger_user_id', $messenger->ID );
				$locked->update_meta_data( '_cvd_delivery_accepted_at', $at );
				$locked->add_order_note( 'Carrera aceptada por ' . $messenger->display_name . ' desde el grupo de WhatsApp.' );
				update_user_meta( $messenger->ID, '_cvd_last_delivery_accepted_at', time() );
			},
		) );
		if ( empty( $result['success'] ) ) { return self::no_cache( array( 'result' => 'taken' ) ); }
		$fresh = wc_get_order( $order->get_id() );
		return self::no_cache( array( 'result' => 'won', 'messenger' => $messenger->display_name, 'voucher' => self::voucher( $fresh ) ) );
	}

	/** Hora a la que el mensajero dice que puede ir: queda en el pedido y en el seguimiento. */
	public static function eta( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::assigned_messenger( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return new WP_Error( 'cvd_not_assigned', 'Ese pedido no está asignado a este mensajero.', array( 'status' => 403 ) ); }
		$text = sanitize_text_field( (string) $request->get_param( 'text' ) );
		if ( '' === $text ) { return new WP_Error( 'cvd_eta_empty', 'Falta la hora.', array( 'status' => 400 ) ); }
		$order->update_meta_data( '_cvd_bot_eta', mb_substr( $text, 0, 120 ) );
		$order->update_meta_data( '_cvd_bot_eta_at', current_time( 'mysql', true ) );
		$order->add_order_note( 'VivaBot: ' . $messenger->display_name . ' puede ir ' . mb_substr( $text, 0, 120 ) . '.' );
		$order->save();
		return self::no_cache( array( 'ok' => true, 'customer' => self::customer( $order ) ) );
	}

	/**
	 * "Entregado": el mensajero declara lo que cobró. Respeta la custodia de Core: si la tienda
	 * aún no confirmó que le dio el paquete (picked_up), no se puede marcar entregado.
	 */
	public static function delivered( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::assigned_messenger( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return new WP_Error( 'cvd_not_assigned', 'Ese pedido no está asignado a este mensajero.', array( 'status' => 403 ) ); }
		$status = CVD_Delivery::status( $order );
		if ( 'delivered' === $status ) { return self::no_cache( array( 'result' => 'already' ) ); }
		if ( in_array( $status, array( 'accepted', 'to_store' ), true ) ) { return self::no_cache( array( 'result' => 'needs_pickup' ) ); }
		$usd    = (float) wc_format_decimal( $request->get_param( 'usd' ) ?? 0, 2 );
		$cup    = (float) wc_format_decimal( $request->get_param( 'cup' ) ?? 0, 2 );
		$method = sanitize_key( (string) $request->get_param( 'method' ) );
		if ( ! in_array( $method, array( 'cash_usd', 'cash_cup', 'transfer', 'mixed', 'other' ), true ) ) { $method = $usd > 0 && $cup > 0 ? 'mixed' : ( $usd > 0 ? 'cash_usd' : 'cash_cup' ); }
		if ( $usd <= 0 && $cup <= 0 ) { return self::no_cache( array( 'result' => 'needs_amount' ) ); }
		if ( 'picked_up' === $status ) {
			$step = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'handed_over', array( 'actor_user_id' => $messenger->ID, 'idempotency_key' => 'bot-handed:' . $order->get_id(), 'source' => 'cvd_bot_bridge_delivered',
				'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $actor, $at ): void { $locked->update_meta_data( '_cvd_to_customer_at', $at ); } ) );
			if ( empty( $step['success'] ) ) { return self::no_cache( array( 'result' => 'error', 'code' => $step['error_code'] ?? '' ) ); }
		}
		$done = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'delivered', array(
			'actor_user_id'        => $messenger->ID,
			'idempotency_key'      => 'bot-delivered:' . $order->get_id(),
			'source'               => 'cvd_bot_bridge_delivered',
			'metadata'             => array( 'collection_method' => $method, 'collected_usd' => $usd, 'collected_cup' => $cup, 'channel' => 'whatsapp' ),
			'coupled_payment_state'=> 'pending_return',
			'atomic_mutation'      => static function ( WC_Order $locked, $from, $to, WP_User $actor, string $at ) use ( $method, $usd, $cup ): void {
				$locked->update_meta_data( '_cvd_delivered_by', $actor->ID );
				$locked->update_meta_data( '_cvd_delivered_at', $at );
				$locked->update_meta_data( '_cvd_collection_method', $method );
				$locked->update_meta_data( '_cvd_collection_amount_usd', wc_format_decimal( $usd, 2 ) );
				$locked->update_meta_data( '_cvd_collection_amount_cup', wc_format_decimal( $cup, 2 ) );
				$locked->update_meta_data( '_cvd_collection_note', 'Declarado por WhatsApp' );
				// Lo que declaró el mensajero se conserva aparte: la tienda lo ve al cerrar y se avisa si no cuadra.
				$locked->update_meta_data( '_cvd_declared_usd', wc_format_decimal( $usd, 2 ) );
				$locked->update_meta_data( '_cvd_declared_cup', wc_format_decimal( $cup, 2 ) );
				$locked->update_meta_data( '_cvd_collection_received_by', $actor->ID );
				$locked->update_meta_data( '_cvd_collection_received_at', $at );
			},
		) );
		if ( empty( $done['success'] ) ) { return self::no_cache( array( 'result' => 'error', 'code' => $done['error_code'] ?? '' ) ); }
		return self::no_cache( array( 'result' => 'delivered', 'customer' => self::customer( $order ) ) );
	}

	/** Estado para el bot y Curru: dónde va el pedido, sin datos sensibles. */
	public static function order_status( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		$status = CVD_Delivery::status( $order );
		return self::no_cache( array(
			'id' => $order->get_id(), 'status' => $status, 'label' => CVD_Delivery::label( $status ),
			'operation' => sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ),
			'messenger' => $messenger ? $messenger->display_name : null,
			'eta' => (string) $order->get_meta( '_cvd_bot_eta', true ),
			'tracking' => CVD_Delivery::tracking_url( $order ),
		) );
	}

	private static function order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? $order : new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}

	private static function phone_key( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		return strlen( $digits ) >= 8 ? substr( $digits, -8 ) : '';
	}

	/** Mensajero aprobado cuyo WhatsApp (o teléfono de facturación) coincide en los últimos 8 dígitos. */
	public static function messenger_by_phone( string $phone ): ?WP_User {
		return self::user_by_phone( $phone, 'cvd_messenger' );
	}

	private static function user_by_phone( string $phone, string $role ): ?WP_User {
		$key = self::phone_key( $phone );
		if ( '' === $key ) { return null; }
		$users = get_users( array( 'role' => $role, 'meta_key' => '_cvd_account_status', 'meta_value' => 'approved' ) );
		foreach ( $users as $user ) {
			foreach ( array( '_cvd_whatsapp', 'billing_phone' ) as $meta ) {
				if ( $key === self::phone_key( (string) get_user_meta( $user->ID, $meta, true ) ) ) { return $user; }
			}
		}
		return null;
	}

	private static function assigned_messenger( WC_Order $order, string $phone ): ?WP_User {
		$messenger = self::messenger_by_phone( $phone );
		return $messenger && $messenger->ID === absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ? $messenger : null;
	}

	private static function items( WC_Order $order ): string {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) { $items[] = $item->get_quantity() . ' × ' . $item->get_name(); }
		return implode( ', ', $items );
	}

	/** Fecha y franja que eligió el cliente al comprar, en palabras ("hoy por la tarde"). */
	private static function window( WC_Order $order ): string {
		$date = (string) $order->get_meta( '_cvd_delivery_date', true );
		$slot = sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) );
		$slots = array( 'morning' => 'por la mañana', 'afternoon' => 'por la tarde', 'evening' => 'por la noche', 'anytime' => 'a cualquier hora' );
		$day = '';
		if ( $date ) {
			$today = current_time( 'Y-m-d' );
			$day = $date === $today ? 'hoy' : ( gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) ) === $date ? 'mañana' : date_i18n( 'j \d\e F', strtotime( $date ) ) );
		}
		return trim( $day . ' ' . ( $slots[ $slot ] ?? $slot ) );
	}

	/** WhatsApp de la gestora dueña del pedido: recibe los mismos avisos que su cliente. */
	private static function gestora_phone( WC_Order $order ): string {
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return ''; }
		$id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		if ( ! $id ) { return ''; }
		return preg_replace( '/\D+/', '', (string) ( get_user_meta( $id, '_cvd_whatsapp', true ) ?: get_user_meta( $id, 'billing_phone', true ) ) );
	}

	/** Vuelto pedido por el cliente: Core lo guarda como lista [{amount, currency}]. */
	private static function change( WC_Order $order ): string {
		$raw = $order->get_meta( '_cvd_change_required', true );
		$parts = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $line ) {
				$amount = is_array( $line ) ? (float) ( $line['amount'] ?? 0 ) : 0;
				if ( $amount > 0 ) { $parts[] = wc_format_decimal( $amount, 2, true ) . ' ' . strtoupper( (string) ( $line['currency'] ?? '' ) ); }
			}
		} elseif ( 'yes' === $raw ) {
			$parts[] = trim( $order->get_meta( '_cvd_change_amount', true ) . ' ' . $order->get_meta( '_cvd_change_currency', true ) );
		}
		return implode( ' + ', array_filter( $parts ) );
	}

	/** Foto principal de cada producto, para que tienda y mensajero no confundan productos parecidos. */
	private static function images( WC_Order $order ): array {
		$images = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$id = $product ? ( $product->get_image_id() ?: ( $product->get_parent_id() ? wc_get_product( $product->get_parent_id() )->get_image_id() : 0 ) ) : 0;
			$url = $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
			if ( $url ) { $images[] = array( 'url' => $url, 'caption' => $item->get_quantity() . ' × ' . $item->get_name() ); }
		}
		return array_slice( $images, 0, 5 );
	}

	private static function customer( WC_Order $order ): array {
		$phone = $order->get_meta( '_cvd_whatsapp_verified', true ) ?: ( $order->get_shipping_phone() ?: $order->get_billing_phone() );
		return array( 'name' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(), 'phone' => preg_replace( '/\D+/', '', (string) $phone ) );
	}

	/** Vale completo: solo se entrega al mensajero que ganó la carrera. */
	private static function voucher( WC_Order $order ): array {
		$address = trim( implode( ', ', array_filter( array( $order->get_shipping_address_1() ?: $order->get_billing_address_1(), $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) ) ) );
		$change = self::change( $order );
		return array(
			'id'          => $order->get_id(),
			'customer'    => self::customer( $order ),
			'altPhone'    => preg_replace( '/\D+/', '', (string) $order->get_meta( '_cvd_alternate_phone', true ) ),
			'zone'        => CVD_Delivery::destination_zone( $order ),
			'address'     => $address,
			'reference'   => (string) $order->get_meta( '_cvd_reference', true ),
			'mapUrl'      => (string) $order->get_meta( '_cvd_map_url', true ),
			'items'       => self::items( $order ),
			'productsUsd' => wc_format_decimal( $order->get_total(), 2 ),
			'shippingCup' => (int) ( class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : 0 ),
			'earningCup'  => CVD_Delivery::courier_amount( $order ),
			'change'      => $change,
			'window'      => self::window( $order ),
			'note'        => wp_strip_all_tags( (string) $order->get_customer_note() ),
			'pickup'      => 'Casa Viva · Calle Conill A esq. 45 #864, Nuevo Vedado',
			'tracking'    => CVD_Delivery::tracking_url( $order ),
			'app'         => home_url( '/area-mensajeros/' ),
			'images'      => self::images( $order ),
			'gestora'     => 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? (string) $order->get_meta( '_cvd_owner_display_name', true ) : '',
			'gestoraPhone'=> self::gestora_phone( $order ),
		);
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
