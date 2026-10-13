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
		// Asignación directa (la gestora eligió mensajero): el vale va por privado, nunca al grupo.
		register_rest_route( self::NS, '/bot/dispatch/direct', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'direct' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/direct-announced', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'direct_announced' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/claim', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'claim' ), 'permission_callback' => $auth ) );
		// Relevo (3.13.49): el mensajero asignado suelta la carrera antes de recogerla.
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/release', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'release' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/eta', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'eta' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/delivered', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'delivered' ), 'permission_callback' => $auth ) );
		// 3.13.55: el mensajero dice que ya tiene el paquete → «Entregado al mensajero» automático.
		register_rest_route( self::NS, '/bot/dispatch/(?P<id>\d+)/picked-up', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'picked_up' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'order_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/whatsapp', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'verified_whatsapp' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/gestora', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestora_link' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/gestora/orders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestora_orders' ), 'permission_callback' => $auth ) );
		// Aviso a la gestora cuando su pedido termina por CUALQUIER camino (3.13.53): recogida cerrada en /ventas/ o el panel,
		// o «Entregado» marcado desde el área del mensajero. El «entregado» por WhatsApp avisa al momento y marca lo mismo.
		register_rest_route( self::NS, '/bot/gestora/finished', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestora_finished' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/gestora/finished/(?P<id>\d+)/notified', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_finished_notified' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/partners', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'partners' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/partners/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'partner_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/partners/(?P<id>\d+)/welcome', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'partner_welcome' ), 'permission_callback' => $auth ) );
		// «mi acceso» de una dependienta (3.13.49): enlace de un solo uso a Operaciones.
		register_rest_route( self::NS, '/bot/clerk/access', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clerk_access' ), 'permission_callback' => $auth ) );
		// Tasa del día (3.13.51): lo que el bot lee del mensaje «FORMAS DE PAGO Y SUS CAMBIOS» del grupo General.
		register_rest_route( self::NS, '/bot/rates', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rates' ), 'permission_callback' => $auth ) );
	}

	/**
	 * Guarda la tasa del día que leyó el bot. sentAt = hora del mensaje: el mismo mensaje (o uno más viejo)
	 * no vuelve a aplicarse, así un reinicio del bot no pisa lo que la dueña corrigió en el panel.
	 */
	public static function rates( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = CVD_Daily_Rates::save( array_intersect_key( $params, array_flip( array( 'cup', 'eur', 'clasica', 'zelle', 'paypal_pct', 'paypalPct' ) ) ), 'bot', sanitize_text_field( (string) ( $params['sentAt'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	/**
	 * Dependienta que escribe «mi acceso» al bot: enlace de acceso seguro (7 días, un solo uso) que la
	 * deja en /centro-operaciones/ para poner su contraseña. Solo por su propio WhatsApp (_cvd_whatsapp)
	 * y nunca para cuentas de administración.
	 */
	public static function clerk_access( WP_REST_Request $request ): WP_REST_Response {
		$key = self::phone_key( (string) $request->get_param( 'phone' ) );
		if ( '' === $key ) { return self::no_cache( array( 'found' => false ) ); }
		foreach ( get_users( array( 'role' => 'cvd_clerk' ) ) as $user ) {
			if ( $key !== self::phone_key( (string) get_user_meta( $user->ID, '_cvd_whatsapp', true ) ) ) { continue; }
			if ( array_intersect( array( 'administrator', 'shop_manager' ), (array) $user->roles ) ) { return self::no_cache( array( 'found' => false ) ); }
			return self::no_cache( array(
				'found'      => true,
				'name'       => $user->display_name,
				'accessLink' => CVD_Registration::create_secure_access_link( $user ),
				'panel'      => home_url( '/ventas/' ),
			) );
		}
		return self::no_cache( array( 'found' => false ) );
	}

	private static function partner( WP_REST_Request $request ) {
		$user = get_user_by( 'id', absint( $request['id'] ) );
		if ( ! $user || ! array_intersect( array( 'cvd_gestora', 'cvd_messenger' ), (array) $user->roles ) ) {
			return new WP_Error( 'cvd_partner', 'No es gestor/a ni mensajero.', array( 'status' => 404 ) );
		}
		return $user;
	}

	/**
	 * Programa al que se refiere la aprobación o la bienvenida. Con un solo programa, el suyo.
	 * Cuenta doble: el que se manda en "kind" (gestora | mensajero). Si no se manda: al cambiar
	 * el estado, el único programa pendiente; en la bienvenida, el último que cambió. Si no, gestora.
	 */
	private static function partner_kind( WP_User $user, WP_REST_Request $request, bool $welcome = false ): string {
		if ( ! CVD_Registration::is_dual( $user ) ) { return CVD_Registration::program_type( $user ); }
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( in_array( $kind, array( 'gestora', 'mensajero' ), true ) ) { return $kind; }
		if ( $welcome ) {
			$last = sanitize_key( (string) get_user_meta( $user->ID, '_cvd_status_changed_program', true ) );
			return 'mensajero' === $last ? 'mensajero' : 'gestora';
		}
		$pending = array_values( array_filter( array( 'gestora', 'mensajero' ), static fn( string $type ): bool => ! in_array( CVD_Registration::status_for( $user, $type ), array( 'approved', 'suspended', 'rejected' ), true ) ) );
		return 1 === count( $pending ) ? $pending[0] : 'gestora';
	}

	/**
	 * Aprobar o rechazar desde WhatsApp (APRUEBA / RECHAZA, o según el modo de altas del bot).
	 * Hace lo mismo que la página de Solicitudes: estado, código de referido y correo de aviso.
	 */
	public static function partner_status( WP_REST_Request $request ) {
		$user = self::partner( $request );
		if ( is_wp_error( $user ) ) { return $user; }
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) { return new WP_Error( 'cvd_partner_status', 'Estado no válido.', array( 'status' => 422 ) ); }
		// Cuenta doble: cada programa tiene su estado (gestora en _cvd_account_status, mensajero en _cvd_messenger_status).
		$kind = self::partner_kind( $user, $request );
		CVD_Registration::set_status( $user, $kind, $status );
		if ( 'approved' === $status && 'gestora' === $kind && ! get_user_meta( $user->ID, '_cvd_referral_code', true ) ) {
			update_user_meta( $user->ID, '_cvd_referral_code', 'CV' . $user->ID . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $user->user_login ), 0, 8 ) ) );
		}
		// Mensajero recién aprobado: queda "Disponible". Sin esto, si ningún mensajero aprobado lo está,
		// "Listo para salir" no abre la oferta (CVD_Delivery::publish_offer) y el bot no publica nada en el grupo.
		if ( 'approved' === $status && 'mensajero' === $kind && '' === (string) get_user_meta( $user->ID, '_cvd_messenger_available', true ) ) {
			update_user_meta( $user->ID, '_cvd_messenger_available', 'yes' );
		}
		clean_user_cache( $user->ID );
		update_user_meta( $user->ID, '_cvd_status_changed_by', 'vivabot:' . sanitize_text_field( (string) $request->get_param( 'by' ) ) );
		update_user_meta( $user->ID, '_cvd_status_changed_program', $kind );
		return self::no_cache( array( 'ok' => true, 'status' => $status, 'kind' => $kind ) );
	}

	/**
	 * Datos para la bienvenida por WhatsApp: enlace de gestora y enlace de acceso seguro (7 días,
	 * sin contraseña). Nunca se crea enlace de acceso para cuentas de administración.
	 */
	public static function partner_welcome( WP_REST_Request $request ) {
		$user = self::partner( $request );
		if ( is_wp_error( $user ) ) { return $user; }
		$kind = self::partner_kind( $user, $request, true );
		if ( 'approved' !== CVD_Registration::status_for( $user, $kind ) ) { return new WP_Error( 'cvd_partner_pending', 'La cuenta no está aprobada.', array( 'status' => 409 ) ); }
		$is_staff = (bool) array_intersect( array( 'administrator', 'shop_manager' ), (array) $user->roles );
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		return self::no_cache( array(
			'name'       => $user->display_name,
			'kind'       => $kind,
			'link'       => $code && 'gestora' === $kind ? add_query_arg( 'ref', rawurlencode( $code ), home_url( '/tienda/' ) ) : '',
			'accessLink' => ! $is_staff && class_exists( 'CVD_Registration' ) ? CVD_Registration::create_secure_access_link( $user, $kind ) : '',
			'panel'      => 'mensajero' === $kind ? home_url( '/area-mensajeros/' ) : home_url( '/area-gestoras/' ),
		) );
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
					// Una cuenta doble sale en las dos listas, cada vez con el estado de ese programa.
					'status' => sanitize_key( CVD_Registration::status_for( $user, $kind ) ) ?: 'pending',
					'dual'   => CVD_Registration::is_dual( $user ),
					'since'  => $user->user_registered,
				);
			}
		}
		return self::no_cache( array( 'partners' => $rows, 'approveUrl' => admin_url( 'admin.php?page=cvd-gestoras' ) ) );
	}

	/** Lo que una gestora pregunta por WhatsApp: sus últimos pedidos, en qué punto van y su comisión. */
	public static function gestora_orders( WP_REST_Request $request ) {
		$gestora = self::user_by_phone( (string) $request->get_param( 'phone' ), 'cvd_gestora', false ); // también pendientes: el bot les dice que esperen la aprobación (si no, les pedía registrarse otra vez)
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
				'status' => 'cancelled' === $o->get_status() ? 'Cancelado' : ( 'refunded' === $o->get_status() ? 'Devuelto' : ( 'pickup' === $o->get_meta( '_cvd_fulfillment_type', true ) ? ( 'completed' === $o->get_status() ? 'Recogido' : 'Para recoger en tienda' ) : self::friendly( $delivery ) ) ),
				'messenger' => $messenger ? $messenger->display_name : '', 'eta' => (string) $o->get_meta( '_cvd_bot_eta', true ), 'when' => CVD_Delivery_When::order_label( $o ),
				'commission' => (float) $o->get_meta( '_cvd_commission_amount', true ), 'commissionStatus' => (string) $o->get_meta( '_cvd_commission_status', true ),
				// 3.13.64 (12-oct, regla de Ernesto): CUP con la tasa del día de la venta; null/solo USD en pedidos viejos sin tasa.
				'commissionCup' => CVD_Commissions::commission_cup( $o ), 'cupRate' => CVD_Daily_Rates::order_rate( $o ), 'commissionText' => CVD_Commissions::commission_text( $o ),
			);
		}
		return self::no_cache( array( 'found' => true, 'id' => $gestora->ID, 'approved' => 'approved' === CVD_Registration::status_for( $gestora, 'gestora' ), 'name' => $gestora->display_name, 'orders' => $list, 'commission' => array( 'pending' => round( $pending, 2 ), 'approved' => round( $approved, 2 ) ) ) );
	}

	/**
	 * Pedidos de gestora que ya terminaron y a los que el bot aún no le avisó a la gestora (3.13.53).
	 * Terminado = recogida en tienda cerrada («Lo recogió y pagó») o mensajería entregada (delivered, dinero recibido o cerrada).
	 * since = desde cuándo mirar cambios (ISO o unix; por defecto 24 h, como mucho 3 días). Al cliente de la gestora nunca se le escribe.
	 */
	public static function gestora_finished( WP_REST_Request $request ): WP_REST_Response {
		$raw = (string) $request->get_param( 'since' );
		$since = '' === $raw ? 0 : ( is_numeric( $raw ) ? (int) $raw : (int) strtotime( $raw ) );
		$floor = time() - 3 * DAY_IN_SECONDS;
		if ( $since <= 0 ) { $since = time() - DAY_IN_SECONDS; }
		$since = max( $since, $floor );
		$orders = wc_get_orders( array( 'limit' => 100, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC', 'status' => array( 'processing', 'on-hold', 'completed' ),
			'date_modified' => '>=' . $since, 'meta_key' => '_cvd_owner_type', 'meta_value' => 'gestora' ) );
		$list = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( '_cvd_bot_gestora_done_notified_at', true ) ) { continue; }
			$kind = self::finished_kind( $order );
			$gestora_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
			if ( '' === $kind || ! $gestora_id ) { continue; }
			$gestora = get_userdata( $gestora_id );
			$list[] = array(
				'id'               => $order->get_id(),
				'number'           => $order->get_order_number(),
				'kind'             => $kind, // 'pickup' (se lo llevó de la tienda) o 'delivery' (lo entregó el mensajero)
				'doneAt'           => self::finished_at( $order, $kind ), // 'Y-m-d H:i:s' UTC
				'gestoraId'        => $gestora_id,
				'gestoraName'      => $gestora ? $gestora->display_name : (string) $order->get_meta( '_cvd_owner_display_name', true ),
				'gestoraPhone'     => self::gestora_phone( $order ),
				'customerFirst'    => trim( (string) $order->get_billing_first_name() ),
				'items'            => self::items( $order ),
				'commission'       => round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 ),
				'commissionStatus' => sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ),
				// 3.13.64 (12-oct, regla de Ernesto): CUP con la tasa del día de la venta; null/solo USD en pedidos viejos sin tasa.
				'commissionCup'    => CVD_Commissions::commission_cup( $order ),
				'cupRate'          => CVD_Daily_Rates::order_rate( $order ),
				'commissionText'   => CVD_Commissions::commission_text( $order ),
			);
		}
		return self::no_cache( array( 'finished' => $list, 'since' => gmdate( 'Y-m-d H:i:s', $since ) ) );
	}

	/** El bot ya le avisó a la gestora (por el aviso automático o por el «entregado» de WhatsApp): no se repite. */
	public static function gestora_finished_notified( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return new WP_Error( 'cvd_not_gestora', 'Ese pedido no es de un/a gestor/a.', array( 'status' => 409 ) ); }
		if ( '' !== (string) $order->get_meta( '_cvd_bot_gestora_done_notified_at', true ) ) { return self::no_cache( array( 'ok' => true, 'already' => true ) ); }
		$via = 'whatsapp' === sanitize_key( (string) $request->get_param( 'via' ) ) ? 'whatsapp' : 'poll';
		$order->update_meta_data( '_cvd_bot_gestora_done_notified_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_bot_gestora_done_via', $via );
		if ( ! $request->get_param( 'skipped' ) ) {
			$order->add_order_note( 'whatsapp' === $via ? 'VivaBot: quien vendió ya sabe que el pedido se entregó (aviso del «entregado» por WhatsApp).' : 'VivaBot: se avisó por WhatsApp a quien vendió de que su pedido terminó.' );
		}
		$order->save();
		return self::no_cache( array( 'ok' => true, 'already' => false ) );
	}

	/** 'pickup' | 'delivery' si el pedido ya terminó para la gestora; '' si todavía no. */
	public static function finished_kind( WC_Order $order ): string {
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { return ''; }
		if ( 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ) {
			return 'completed' === $order->get_status() || 'delivered' === sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ? 'pickup' : '';
		}
		$delivery = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::status( $order ) : sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) );
		return in_array( $delivery, array( 'delivered', 'cash_returned', 'closed' ), true ) || 'completed' === $order->get_status() ? 'delivery' : '';
	}

	private static function finished_at( WC_Order $order, string $kind ): string {
		$at = 'pickup' === $kind ? (string) $order->get_meta( '_cvd_pickup_handed_over_at', true ) : ( (string) $order->get_meta( '_cvd_delivered_at', true ) ?: (string) $order->get_meta( '_cvd_to_customer_at', true ) );
		if ( '' !== $at ) { return $at; }
		$date = $order->get_date_completed() ?: $order->get_date_modified();
		return $date ? gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) : '';
	}

	/** Enlace personal de una gestora aprobada (por su WhatsApp): sus clientes compran desde ahí y la venta es suya. */
	public static function gestora_link( WP_REST_Request $request ) {
		$gestora = self::user_by_phone( (string) $request->get_param( 'phone' ), 'cvd_gestora' );
		if ( ! $gestora ) { return self::no_cache( array( 'found' => false ) ); }
		$code = (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true );
		if ( '' === $code ) { return self::no_cache( array( 'found' => true, 'name' => $gestora->display_name, 'link' => '' ) ); }
		return self::no_cache( array( 'found' => true, 'name' => $gestora->display_name, 'code' => $code, 'link' => add_query_arg( 'ref', rawurlencode( $code ), home_url( '/tienda/' ) ) ) );
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
		$current = (string) $order->get_meta( '_cvd_whatsapp_verified', true );
		// Solo cuenta el primero que manda el vale (el cliente, justo al comprar). Si después otro número
		// manda un texto con el mismo "Pedido #N", no se le desvían los avisos ni el teléfono del vale del mensajero.
		if ( '' !== $current && substr( $current, -8 ) !== substr( $phone, -8 ) ) {
			$order->add_order_note( 'VivaBot: +' . $phone . ' envió el vale de este pedido, pero ya estaba verificado +' . $current . '. No se cambia.' );
			$order->save();
			return self::no_cache( array( 'ok' => false, 'locked' => true, 'matches' => substr( $typed, -8 ) === substr( $phone, -8 ) ) );
		}
		if ( $current !== $phone ) {
			$order->update_meta_data( '_cvd_whatsapp_verified', $phone );
			$order->add_order_note( substr( $typed, -8 ) === substr( $phone, -8 )
				? 'VivaBot: el cliente envió el vale desde su WhatsApp (+' . $phone . ').'
				: ( '' === $typed // 3.13.69: en la recogida en tienda el teléfono es opcional
					? 'VivaBot: el cliente envió el vale desde +' . $phone . ' (en la web no escribió teléfono). Se usa ese.'
					: 'VivaBot: el cliente envió el vale desde +' . $phone . ', distinto del teléfono escrito en la web (' . $typed . '). Se usa el de WhatsApp.' ) );
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
			if ( '' !== CVD_Delivery::blocks_dispatch( $order ) ) { continue; } // 3.13.54/3.13.71: prueba, sin mensajería o no activo: nunca se anuncia en el grupo
			$offered_at = (string) $order->get_meta( '_cvd_delivery_offered_at', true );
			$announced_at = (string) $order->get_meta( '_cvd_bot_announced_at', true );
			$offers[] = array(
				'id'         => $order->get_id(),
				'zone'       => CVD_Delivery::destination_zone( $order ),
				'items'      => self::items( $order ),
				'earningCup' => CVD_Delivery::courier_amount( $order ),
				'window'     => self::window( $order ),
				// 3.13.70: el mensajero ve el armado ANTES de coger la carrera (si no quiere armar, no la coge).
				'assembly'   => self::assembly( $order ),
			) + self::when( $order ) + array(
				'offeredAt'  => $offered_at,
				// Si el pedido se volvió a ofertar (se quitó el mensajero y otra vez "Listo para salir"),
				// el aviso anterior no cuenta: hay que publicarlo de nuevo. Ambas fechas son 'Y-m-d H:i:s' UTC.
				'announced'  => '' !== $announced_at && $announced_at >= $offered_at,
			);
		}
		// Mensajeros aprobados y «Disponible»: el bot solo recuerda una carrera libre en el grupo si hay alguno.
		return self::no_cache( array( 'offers' => $offers, 'availableMessengers' => count( CVD_Delivery::available_messengers() ) ) );
	}

	public static function announced( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$previous = (string) $order->get_meta( '_cvd_bot_announced_at', true );
		if ( '' === $previous || $previous < (string) $order->get_meta( '_cvd_delivery_offered_at', true ) ) {
			$order->update_meta_data( '_cvd_bot_announced_at', current_time( 'mysql', true ) );
			$order->add_order_note( 'VivaBot: oferta publicada en el grupo de mensajeros.' );
			$order->save();
		}
		return self::no_cache( array( 'ok' => true ) );
	}

	/**
	 * Asignaciones directas que el bot todavía no avisó: el bot manda el vale por privado al
	 * mensajero (mismo vale que tras un "Yo"), guarda el hilo y llama a direct-announced.
	 */
	public static function direct(): WP_REST_Response {
		$orders = wc_get_orders( array( 'limit' => 30, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => array( 'pending', 'processing', 'on-hold' ), 'meta_key' => '_cvd_direct_assignment', 'meta_value' => '1' ) );
		$list = array();
		foreach ( $orders as $order ) {
			$status = CVD_Delivery::status( $order );
			// Si ya la aceptó desde la web antes de que el bot la viera, igual necesita el vale por WhatsApp.
			if ( ! in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ) { continue; }
			if ( '' !== CVD_Delivery::blocks_dispatch( $order ) ) { continue; } // 3.13.54/3.13.71: ni vale por privado
			$assigned_at = (string) $order->get_meta( '_cvd_direct_assigned_at', true );
			$announced_at = (string) $order->get_meta( '_cvd_bot_direct_announced_at', true );
			if ( '' !== $announced_at && $announced_at >= $assigned_at ) { continue; } // ambas 'Y-m-d H:i:s' UTC
			$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
			if ( ! $messenger ) { continue; }
			$list[] = array(
				'id'             => $order->get_id(),
				'status'         => $status,
				'assignedAt'     => $assigned_at,
				'messengerId'    => $messenger->ID,
				'messengerName'  => $messenger->display_name,
				'messengerPhone' => preg_replace( '/\D+/', '', (string) ( get_user_meta( $messenger->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $messenger->ID, 'billing_phone', true ) ) ),
				'gestora'        => class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::gestora_name( $order ) : '',
				'requestedBy'    => class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::requested_by( $order ) : '',
				'voucher'        => self::voucher( $order ),
			);
		}
		return self::no_cache( array( 'direct' => $list ) );
	}

	public static function direct_announced( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( '1' !== (string) $order->get_meta( '_cvd_direct_assignment', true ) ) { return new WP_Error( 'cvd_not_direct', 'Ese pedido no tiene asignación directa.', array( 'status' => 409 ) ); }
		$previous = (string) $order->get_meta( '_cvd_bot_direct_announced_at', true );
		if ( '' === $previous || $previous < (string) $order->get_meta( '_cvd_direct_assigned_at', true ) ) {
			$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
			$order->update_meta_data( '_cvd_bot_direct_announced_at', current_time( 'mysql', true ) );
			$order->add_order_note( 'VivaBot: vale enviado por privado a ' . ( $messenger ? $messenger->display_name : 'el mensajero' ) . ' (asignación directa, no salió al grupo).' );
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
		if ( $current_owner || 'offered' !== CVD_Delivery::status( $order ) || '' !== CVD_Delivery::blocks_dispatch( $order ) ) { return self::no_cache( array( 'result' => 'taken' ) ); }
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'accepted', array(
			'actor_user_id'   => $messenger->ID,
			// Con la hora de la oferta: si la carrera se soltó (relevo) y el mismo mensajero la vuelve a
			// coger, no se repite el recibo viejo (que daría "ganada" sin asignarla de verdad).
			'idempotency_key' => 'bot-claim:' . $order->get_id() . ':' . $messenger->ID . ':' . (string) $order->get_meta( '_cvd_delivery_offered_at', true ),
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
			// Mismos avisos que cuando se acepta desde la web (web push, reputación).
			'after_commit'    => static function ( WC_Order $saved ): void { CVD_Delivery::after_commit_effects( $saved, 'accepted' ); },
		) );
		if ( empty( $result['success'] ) ) { return self::no_cache( array( 'result' => 'taken' ) ); }
		$fresh = wc_get_order( $order->get_id() );
		return self::no_cache( array( 'result' => 'won', 'messenger' => $messenger->display_name, 'voucher' => self::voucher( $fresh ) ) );
	}

	/**
	 * Relevo (3.13.49): el mensajero asignado suelta la carrera (borró su «Yo» en el grupo o escribió
	 * «suelto» / «no puedo» por privado). Solo él y solo antes de recoger el paquete (asignada, aceptada
	 * o va a recoger). La entrega vuelve a «oferta abierta» sin mensajero; el bot se la pasa al siguiente
	 * en reserva con el claim de siempre o la vuelve a anunciar en el grupo.
	 */
	public static function release( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::assigned_messenger( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return new WP_Error( 'cvd_not_assigned', 'Ese pedido no está asignado a este mensajero.', array( 'status' => 403 ) ); }
		$status = CVD_Delivery::status( $order ); // 'incident' si hay una incidencia abierta: entonces no se suelta
		if ( ! in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ) { return self::no_cache( array( 'result' => 'too_late', 'status' => $status ) ); }
		$how = 'revoke' === sanitize_key( (string) $request->get_param( 'reason' ) ) ? 'borró su «Yo» en el grupo' : 'lo dijo por WhatsApp';
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'offered', array(
			'actor_user_id'     => $messenger->ID,
			'source'            => 'cvd_bot_bridge_release',
			'messenger_release' => true,
			'metadata'          => array( 'messenger' => $messenger->ID, 'release' => true, 'source' => 'whatsapp' ),
			'precondition'      => static function ( WC_Order $locked, string $current ) use ( $messenger ) {
				if ( 'yes' === $locked->get_meta( '_cvd_delivery_incident_active', true ) ) { return CVD_Order_Transition_Service::PRECONDITION_FAILED; }
				if ( ! in_array( $current, array( 'assigned', 'accepted', 'to_store' ), true ) || absint( $locked->get_meta( '_cvd_messenger_user_id', true ) ) !== $messenger->ID ) { return CVD_Order_Transition_Service::CONFLICT; }
				return true;
			},
			'atomic_mutation'   => static function ( WC_Order $locked, $from, $to, $actor, string $at ) use ( $messenger, $how ): void {
				$locked->delete_meta_data( '_cvd_messenger_user_id' );
				$locked->delete_meta_data( '_cvd_direct_assignment' ); // ya no es directa: sale al grupo como cualquier oferta
				$locked->delete_meta_data( '_cvd_delivery_accepted_at' );
				$locked->delete_meta_data( '_cvd_to_store_at' );
				$locked->delete_meta_data( '_cvd_bot_eta' );
				$locked->delete_meta_data( '_cvd_bot_eta_at' );
				// Oferta nueva: el bot la ve como no anunciada y el reloj de «oferta vieja» empieza otra vez.
				$locked->update_meta_data( '_cvd_delivery_offered_at', $at );
				// En la web: la ven todos los mensajeros disponibles, menos el que la soltó.
				$invited = array_map( 'absint', (array) $locked->get_meta( '_cvd_delivery_invited_messengers', true ) );
				$available = array_map( static fn( WP_User $user ): int => $user->ID, CVD_Delivery::available_messengers() );
				$locked->update_meta_data( '_cvd_delivery_invited_messengers', array_values( array_unique( array_merge( $invited, $available ) ) ) );
				$rejected = array_map( 'absint', (array) $locked->get_meta( '_cvd_delivery_rejected_by', true ) );
				$locked->update_meta_data( '_cvd_delivery_rejected_by', array_values( array_unique( array_merge( $rejected, array( $messenger->ID ) ) ) ) );
				$locked->add_order_note( 'Carrera soltada por ' . $messenger->display_name . ' (' . $how . '). Vuelve a estar libre para otro mensajero.' );
			},
			'after_commit'      => static function () use ( $messenger ): void {
				if ( class_exists( 'CVD_Messenger_Reputation' ) ) { CVD_Messenger_Reputation::invalidate( $messenger->ID ); }
			},
		) );
		if ( empty( $result['success'] ) ) { return self::no_cache( array( 'result' => 'error', 'code' => $result['error_code'] ?? '' ) ); }
		return self::no_cache( array( 'result' => 'released', 'messenger' => $messenger->display_name ) );
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
		// Entrega asignada (p. ej. directa por la gestora): dar la hora por WhatsApp es aceptarla.
		if ( 'assigned' === CVD_Delivery::status( $order ) ) {
			CVD_Delivery::accept_assigned( $order, $messenger->ID, 'cvd_bot_bridge_eta', 'por WhatsApp al dar la hora' );
		}
		return self::no_cache( array( 'ok' => true, 'customer' => self::customer( $order ) ) );
	}

	/**
	 * 3.13.55: el mensajero asignado avisa por WhatsApp que ya tiene el paquete («lo tengo», «recogido #N»).
	 * Marca «Entregado al mensajero» con la nota «automático: confirmó el mensajero» (la dependienta no tiene
	 * que hacerlo). result: picked_up | already | test_order | too_early | error.
	 */
	public static function picked_up( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::assigned_messenger( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return new WP_Error( 'cvd_not_assigned', 'Ese pedido no está asignado a este mensajero.', array( 'status' => 403 ) ); }
		$result = CVD_Delivery::messenger_confirms_pickup( $order, $messenger, 'cvd_bot_bridge_picked_up', 'dijo por WhatsApp que ya tiene el paquete' );
		return self::no_cache( array( 'result' => $result ) );
	}

	/**
	 * "Entregado": el mensajero declara lo que cobró. Si la tienda aún no marcó que le dio el paquete,
	 * se marca solo (3.13.55, CVD_Delivery::messenger_confirms_pickup): quien entregó, lo tenía.
	 */
	public static function delivered( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$messenger = self::assigned_messenger( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { return new WP_Error( 'cvd_not_assigned', 'Ese pedido no está asignado a este mensajero.', array( 'status' => 403 ) ); }
		$status = CVD_Delivery::status( $order );
		if ( in_array( $status, array( 'delivered', 'cash_returned', 'closed' ), true ) ) { return self::no_cache( array( 'result' => 'already' ) ); }
		$usd    = (float) wc_format_decimal( $request->get_param( 'usd' ) ?? 0, 2 );
		$cup    = (float) wc_format_decimal( $request->get_param( 'cup' ) ?? 0, 2 );
		$eur    = (float) wc_format_decimal( $request->get_param( 'eur' ) ?? 0, 2 ); // 3.13.67: pagó en euros
		$method = sanitize_key( (string) $request->get_param( 'method' ) );
		if ( ! in_array( $method, array( 'cash_usd', 'cash_cup', 'transfer', 'mixed', 'other' ), true ) ) {
			$method = count( array_filter( array( $usd, $cup, $eur ), static fn( float $v ): bool => $v > 0 ) ) > 1 ? 'mixed' : ( $usd > 0 ? 'cash_usd' : ( $cup > 0 ? 'cash_cup' : 'other' ) );
		}
		// 3.13.67: Zelle / PayPal ya pagados por adelantado (la tienda lo verificó antes): el mensajero no cobra productos,
		// así que «entregado» vale con 0 y no se le anota dinero de la tienda (solo su mensajería, que es suya).
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		$collection_note = 'Declarado por WhatsApp' . ( $payment ? ' · ' . $payment['label'] : '' );
		if ( $payment && $payment['prepaid'] ) {
			if ( $usd > 0 || $cup > 0 || $eur > 0 ) { $order->add_order_note( sprintf( 'El mensajero declaró %s USD + %s CUP + %s EUR, pero el pedido es %s (pagado por adelantado): no se le anota dinero de productos.', $usd, $cup, $eur, $payment['label'] ) ); }
			$usd = 0.0; $cup = 0.0; $eur = 0.0; $method = 'transfer';
			$collection_note .= ' (pagado por adelantado; sin dinero de productos)';
		} elseif ( $usd <= 0 && $cup <= 0 && $eur <= 0 ) {
			return self::no_cache( array( 'result' => 'needs_amount' ) );
		}
		if ( $payment && 'transfer_cup' === $payment['kind'] ) { $collection_note .= ' (el cliente transfirió a la tarjeta del mensajero)'; }
		if ( $eur > 0 ) { $collection_note .= ' · ' . wc_format_decimal( $eur, 2, true ) . ' EUR'; }
		// 3.13.55: si ya lo entregó, es que tenía el paquete. Si la dependienta no marcó «Entregado al mensajero»,
		// se marca solo («automático: confirmó el mensajero») en vez de pedirle que vuelva a escribir. Nunca en pruebas.
		if ( in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ) {
			$picked = CVD_Delivery::messenger_confirms_pickup( $order, $messenger, 'cvd_bot_bridge_delivered', 'dijo por WhatsApp que ya lo entregó' );
			if ( ! in_array( $picked, array( 'picked_up', 'already' ), true ) ) { return self::no_cache( array( 'result' => 'test_order' === $picked ? 'test_order' : 'needs_pickup', 'code' => $picked ) ); }
			$order  = wc_get_order( $order->get_id() );
			$status = CVD_Delivery::status( $order );
		}
		if ( 'picked_up' === $status ) {
			$step = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'handed_over', array( 'actor_user_id' => $messenger->ID, 'idempotency_key' => 'bot-handed:' . $order->get_id(), 'source' => 'cvd_bot_bridge_delivered',
				'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $actor, $at ): void { $locked->update_meta_data( '_cvd_to_customer_at', $at ); },
				'after_commit'    => static function ( WC_Order $saved ): void { CVD_Delivery::after_commit_effects( $saved, 'handed_over' ); } ) );
			if ( empty( $step['success'] ) ) { return self::no_cache( array( 'result' => 'error', 'code' => $step['error_code'] ?? '' ) ); }
		}
		$done = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'delivered', array(
			'actor_user_id'        => $messenger->ID,
			'idempotency_key'      => 'bot-delivered:' . $order->get_id(),
			'source'               => 'cvd_bot_bridge_delivered',
			'metadata'             => array( 'collection_method' => $method, 'collected_usd' => $usd, 'collected_cup' => $cup, 'collected_eur' => $eur, 'channel' => 'whatsapp' ),
			'coupled_payment_state'=> 'pending_return',
			'atomic_mutation'      => static function ( WC_Order $locked, $from, $to, WP_User $actor, string $at ) use ( $method, $usd, $cup, $eur, $collection_note ): void {
				$locked->update_meta_data( '_cvd_delivered_by', $actor->ID );
				$locked->update_meta_data( '_cvd_delivered_at', $at );
				$locked->update_meta_data( '_cvd_collection_method', $method );
				$locked->update_meta_data( '_cvd_collection_amount_usd', wc_format_decimal( $usd, 2 ) );
				$locked->update_meta_data( '_cvd_collection_amount_cup', wc_format_decimal( $cup, 2 ) );
				$locked->update_meta_data( '_cvd_collection_note', $collection_note );
				// Lo que declaró el mensajero se conserva aparte: la tienda lo ve al cerrar y se avisa si no cuadra.
				$locked->update_meta_data( '_cvd_declared_usd', wc_format_decimal( $usd, 2 ) );
				$locked->update_meta_data( '_cvd_declared_cup', wc_format_decimal( $cup, 2 ) );
				if ( $eur > 0 ) { // 3.13.67: euros en mano del mensajero (salen en «Dinero por entregar»)
					$locked->update_meta_data( '_cvd_collection_amount_eur', wc_format_decimal( $eur, 2 ) );
					$locked->update_meta_data( '_cvd_declared_eur', wc_format_decimal( $eur, 2 ) );
				}
				$locked->update_meta_data( '_cvd_collection_received_by', $actor->ID );
				$locked->update_meta_data( '_cvd_collection_received_at', $at );
			},
			// Mismos avisos que el «Entregado» de la web: web push y nota al cliente con el seguimiento.
			'after_commit'         => static function ( WC_Order $saved ): void { CVD_Delivery::after_commit_effects( $saved, 'delivered' ); },
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

	private static function user_by_phone( string $phone, string $role, bool $approved_only = true ): ?WP_User {
		$key = self::phone_key( $phone );
		if ( '' === $key ) { return null; }
		$users = get_users( array( 'role' => $role ) );
		// Aprobado en el programa de ese rol (una cuenta doble tiene un estado por programa).
		$program = 'cvd_messenger' === $role ? 'mensajero' : 'gestora';
		foreach ( $users as $user ) {
			if ( $approved_only && 'approved' !== CVD_Registration::status_for( $user, $program ) ) { continue; }
			// Solo su WhatsApp; el teléfono de facturación solo si no tiene WhatsApp guardado. Al comprar para un
			// cliente, WooCommerce guardaba el teléfono del CLIENTE en la cuenta de la gestora (Lennys, 12-oct:
			// el bot trató al cliente de los faroles como gestora y podía enseñarle los pedidos de ella).
			$own = (string) get_user_meta( $user->ID, '_cvd_whatsapp', true );
			if ( $key === self::phone_key( '' !== self::phone_key( $own ) ? $own : (string) get_user_meta( $user->ID, 'billing_phone', true ) ) ) { return $user; }
		}
		return null;
	}

	private static function assigned_messenger( WC_Order $order, string $phone ): ?WP_User {
		$messenger = self::messenger_by_phone( $phone );
		return $messenger && $messenger->ID === absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ? $messenger : null;
	}

	/**
	 * 3.13.70 (Ernesto, 13-oct): armado por el mensajero, o null. { kind: small|large, usd: 5|10, label, notice }.
	 * notice = «🔧 *OJO: hay que ARMAR el producto* · el mensajero cobra *10 USD* de armado al cliente» (listo para WhatsApp).
	 * Se cobra aparte, como la mensajería: no está en productsUsd ni en payment.
	 */
	private static function assembly( WC_Order $order ): ?array {
		$a = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
		return $a ? array( 'kind' => $a['kind'], 'usd' => $a['usd'], 'label' => $a['label'], 'notice' => $a['notice'] ) : null;
	}

	private static function items( WC_Order $order ): string {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) { $items[] = $item->get_quantity() . ' × ' . $item->get_name(); }
		return implode( ', ', $items );
	}

	/** Fecha y franja que eligió el cliente al comprar, en palabras ("mañana sáb 12/10 · por la tarde"); vacío si no eligió nada. */
	private static function window( WC_Order $order ): string {
		$when = CVD_Delivery_When::for_order( $order );
		return $when['date'] || $when['slot'] ? $when['label'] : '';
	}

	/**
	 * Día de entrega para el bot (3.13.51): deliveryWhen siempre lleno ("hoy", "mañana sáb 12/10 · por la mañana",
	 * "lo antes posible"); deliveryFuture = es para otro día (el bot lo dice al pedir la hora); deliveryDay = "mañana sábado".
	 */
	private static function when( WC_Order $order ): array {
		$when = CVD_Delivery_When::for_order( $order );
		return array( 'deliveryWhen' => $when['label'], 'deliveryDate' => $when['date'], 'deliveryFuture' => $when['future'], 'deliveryDay' => $when['dayName'] );
	}

	/** Estado de mensajería en palabras de gestora y cliente (no las etiquetas internas de la tienda). */
	private static function friendly( string $delivery ): string {
		$map = array(
			'unassigned' => 'Recibido · la tienda lo está preparando', 'offered' => 'Listo · buscando mensajero',
			'assigned' => 'Mensajero asignado', 'accepted' => 'Mensajero asignado', 'to_store' => 'El mensajero va a recogerlo',
			'picked_up' => 'Salió de la tienda', 'handed_over' => 'En camino al cliente', 'delivered' => 'Entregado',
			'cash_returned' => 'Entregado', 'closed' => 'Entregado ✅', 'incident' => 'Con una incidencia (la tienda lo revisa)',
			'failed' => 'No se pudo entregar', 'returned' => 'Devuelto a la tienda', 'cancelled' => 'Cancelado',
		);
		return $map[ $delivery ] ?? CVD_Delivery::label( $delivery );
	}

	/** WhatsApp de la gestora dueña del pedido: recibe los mismos avisos que su cliente. */
	private static function gestora_phone( WC_Order $order ): string {
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return ''; }
		$id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		if ( ! $id ) { return ''; }
		return preg_replace( '/\D+/', '', (string) ( get_user_meta( $id, '_cvd_whatsapp', true ) ?: get_user_meta( $id, 'billing_phone', true ) ) );
	}

	/** Vuelto pedido por el cliente: Core lo guarda como lista [{amount, currency}]. */
	/** Vuelto legible para el vale de WhatsApp (misma regla que el bot). */
	public static function change_label( WC_Order $order ): string {
		return self::change( $order );
	}

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
		$change = implode( ' + ', array_filter( $parts ) );
		// Desde 3.13.20 el cliente dice con cuánto paga: el vale lo muestra junto al vuelto calculado.
		$pay = $order->get_meta( '_cvd_pay_with', true );
		if ( is_array( $pay ) && (float) ( $pay['amount'] ?? 0 ) > 0 ) {
			$currency = strtoupper( (string) ( $pay['currency'] ?? '' ) );
			$with = wc_format_decimal( (float) $pay['amount'], 2, true ) . ' ' . $currency;
			// 3.13.67: en CUP o EUR el vuelto ya se calcula con la cifra de la forma de pago; sin ella, lo calcula el mensajero.
			$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
			$known = 'USD' === $currency || ( $payment && $payment['currency'] === $currency && null !== $payment['amount'] );
			return $change ? $change . ' (paga con ' . $with . ')' : ( $known ? 'no (paga exacto: ' . $with . ')' : 'paga con ' . $with . ' · calcula el vuelto' );
		}
		return $change;
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
		$shipping = (int) ( class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : 0 );
		return array(
			'id'          => $order->get_id(),
			'customer'    => self::customer( $order ),
			'altPhone'    => preg_replace( '/\D+/', '', (string) $order->get_meta( '_cvd_alternate_phone', true ) ),
			'zone'        => CVD_Delivery::destination_zone( $order ),
			'address'     => $address,
			'reference'   => (string) $order->get_meta( '_cvd_reference', true ),
			'mapUrl'      => (string) CVD_Shipping_Rates::order_map_url( $order ),
			'items'       => self::items( $order ),
			'productsUsd' => wc_format_decimal( $order->get_total(), 2 ),
			'shippingCup' => $shipping,
			// 3.13.53: nunca «+ 0 CUP». Sin tarifa (reparto fuera de la lista) el vale dice «por confirmar con la tienda».
			'shippingLabel'   => $shipping ? number_format_i18n( $shipping, 0 ) . ' CUP' : 'por confirmar con la tienda',
			'shippingPending' => ! $shipping,
			'zoneUnknown'     => 'unknown' === (string) $order->get_meta( '_cvd_locality_check', true ),
			'earningCup'  => CVD_Delivery::courier_amount( $order ),
			'change'      => $change,
			// 3.13.67: cómo paga el cliente y cuánto en su moneda (null en pedidos de antes: el bot hace como siempre).
			// { kind, label, currency, amount, factor, usd, prepaid, estimated, text }; la mensajería sigue aparte, en CUP.
			'payment'     => class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null,
			'window'      => self::window( $order ),
			'assembly'    => self::assembly( $order ), // 3.13.70
		) + self::when( $order ) + array(
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
