<?php

defined( 'ABSPATH' ) || exit;

/**
 * La gestora cambia, cancela o recupera SUS pedidos ella sola (Core 3.14.116, Ernesto 3-nov).
 * Plan del Vigía: docs/nexo-business/gestoras-modificar-pedidos-plan-2026-11-03.md (nivel 1). Caso: #6062 → #6102.
 *
 * Endpoints (sesión de la gestora + nonce wp_rest, como /gestora/busca y /gestora/confianza):
 *   GET  /gestora/pedidos                  sus pedidos abiertos (y los que canceló ella hace poco) con lo que puede hacer.
 *   GET  /gestora/pedido/{id}              lo que necesita el formulario «Cambiar».
 *   POST /gestora/pedido/{id}/cambiar      lista blanca: QUITAR productos o BAJAR cantidades, nombre, teléfono,
 *                                          dirección (la calle, en el mismo reparto), día, franja y nota.
 *   POST /gestora/pedido/{id}/cancelar     con motivo.
 *   POST /gestora/pedido/{id}/recuperar    vuelve a abrir un pedido que canceló ELLA (hasta 7 días).
 * Todo pasa por CVD_Order_Edit::edit / cancel / reactivate: existencias, cola de la caja, comisión y avisos de siempre.
 *
 * Solo si TODO se cumple: el pedido es suyo (_cvd_owner_user_id), el mensajero no lo recogió, no se paga por adelantado
 * (Zelle, PayPal, transferencia CUP, tarjeta clásica), no tiene reserva y la comisión no está aprobada ni pagada.
 * Lo demás (añadir, subir, cambiar de municipio o reparto, dividir, después de la recogida, prepago) es de la tienda.
 * Una sola orden a la vez por pedido; la misma orden repetida (doble toque, red que se cae) no se aplica dos veces.
 */
final class CVD_Gestora_Pedidos {
	private const NS = 'casa-viva/v1';
	/** Formas de pago que se cobran ANTES de entregar: cualquier cambio de dinero ahí lo hace la tienda. */
	public const PREPAID_KINDS = array( 'zelle', 'paypal', 'transfer_cup', 'clasica' );
	private const OPEN = array( 'pending', 'processing', 'on-hold' );
	private const IN_STORE = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store' );
	private const REQUEST_META = '_cvd_gestora_requests';
	public const RECOVER_DAYS = 7;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$perm = array( __CLASS__, 'can_use' );
		register_rest_route( self::NS, '/gestora/pedidos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_request' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/pedido/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'detail_request' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/pedido/(?P<id>\d+)/cambiar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'change_request' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/pedido/(?P<id>\d+)/cancelar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_request' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/pedido/(?P<id>\d+)/recuperar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'recover_request' ), 'permission_callback' => $perm ) );
	}

	/** Gestora aprobada con sesión (lo mismo que /gestora/busca). */
	public static function can_use(): bool {
		$user = wp_get_current_user();
		return $user->exists() && class_exists( 'CVD_Registration' ) && CVD_Registration::is_approved_gestora( $user );
	}

	// ---------- Reglas (cuentas puras, sin WordPress: tests/gestora-pedidos.test.php) ----------

	/**
	 * '' = puede; si no, el motivo en palabras sencillas. $action: cambiar | cancelar | recuperar.
	 * $f: mine, status (WooCommerce), operation, stage (reparto), pickup, payment_kind, reserva, commission_status,
	 *     payout, ledger_settled, cancelled_by_her, cancelled_days.
	 */
	public static function block_reason( string $action, array $f ): string {
		if ( empty( $f['mine'] ) ) { return 'Ese pedido no está entre los tuyos.'; }
		$status = (string) ( $f['status'] ?? '' );
		if ( 'recuperar' === $action ) {
			if ( 'cancelled' !== $status ) { return 'Solo se recupera un pedido cancelado.'; }
			if ( empty( $f['cancelled_by_her'] ) ) { return 'Este pedido lo canceló la tienda: para recuperarlo, escribe a la tienda.'; }
			if ( (float) ( $f['cancelled_days'] ?? 0 ) > self::RECOVER_DAYS ) { return 'Lleva más de ' . self::RECOVER_DAYS . ' días cancelado: haz un pedido nuevo.'; }
		} else {
			if ( ! in_array( $status, self::OPEN, true ) || in_array( (string) ( $f['operation'] ?? '' ), array( 'delivered', 'cancelled' ), true ) ) {
				return 'cancelled' === $status ? 'Este pedido ya está cancelado.' : 'Este pedido ya está cerrado: no se puede cambiar.';
			}
			if ( empty( $f['pickup'] ) && ! in_array( (string) ( $f['stage'] ?? 'unassigned' ), self::IN_STORE, true ) ) {
				return 'El mensajero ya recogió el paquete. Para cambiarlo, escribe a la tienda.';
			}
		}
		if ( in_array( (string) ( $f['payment_kind'] ?? '' ), self::PREPAID_KINDS, true ) ) {
			return 'Este pedido se paga por adelantado (Zelle, PayPal o transferencia): los cambios los hace la tienda.';
		}
		if ( ! empty( $f['reserva'] ) ) { return 'Este pedido tiene una reserva guardada en la tienda: los cambios los hace la tienda.'; }
		$commission = (string) ( $f['commission_status'] ?? '' );
		if ( in_array( $commission, array( 'approved', 'paid' ), true ) || ! empty( $f['payout'] ) ) {
			return 'Tu comisión de este pedido ya está aprobada o pagada: los cambios los hace la tienda.';
		}
		if ( ! empty( $f['ledger_settled'] ) ) { return 'Este pedido ya está liquidado con el mensajero: los cambios los hace la tienda.'; }
		return '';
	}

	/**
	 * Lista blanca de «Cambiar». $lines: [itemId => cantidad actual]. $data: lo que mandó la app.
	 * Devuelve array( 'data' => lo que se pasa a CVD_Order_Edit::edit, 'error' => '' ) o con 'error' lleno.
	 * Solo quitar o bajar cantidades; nunca añadir, subir, el vuelto, el municipio ni el reparto.
	 */
	public static function whitelist( array $lines, array $data, bool $pickup = false ): array {
		$bad = static fn( string $why ): array => array( 'data' => array(), 'error' => $why );
		if ( ! empty( $data['add'] ) ) { return $bad( 'Desde aquí solo puedes quitar productos o bajar cantidades. Para añadir algo, escribe a la tienda.' ); }
		if ( array_key_exists( 'changeRequired', $data ) && null !== $data['changeRequired'] ) { return $bad( 'El vuelto lo cambia la tienda.' ); }
		foreach ( array( 'locality', 'municipality', 'city', 'zone', 'price', 'total', 'fulfillment' ) as $key ) {
			if ( array_key_exists( $key, $data ) && '' !== (string) ( is_scalar( $data[ $key ] ) ? $data[ $key ] : 'x' ) ) {
				return $bad( 'Cambiar de municipio, de reparto o de precio lo hace la tienda (cambia la mensajería). Escríbele a la tienda.' );
			}
		}
		$out = array();
		foreach ( array( 'firstName', 'lastName', 'phone', 'address', 'deliveryDate', 'deliveryWindow', 'note' ) as $key ) {
			if ( array_key_exists( $key, $data ) && null !== $data[ $key ] && is_scalar( $data[ $key ] ) ) { $out[ $key ] = (string) $data[ $key ]; }
		}
		if ( $pickup ) { unset( $out['address'], $out['deliveryDate'], $out['deliveryWindow'] ); }
		if ( array_key_exists( 'lines', $data ) ) {
			$wanted = array();
			foreach ( (array) $data['lines'] as $l ) {
				$id = is_array( $l ) && is_numeric( $l['itemId'] ?? null ) ? (int) $l['itemId'] : 0;
				if ( $id <= 0 || ! is_numeric( $l['qty'] ?? null ) ) { continue; }
				if ( ! isset( $lines[ $id ] ) ) { return $bad( 'Uno de esos productos no está en el pedido. Actualiza la página y vuelve a probar.' ); }
				$qty = (int) $l['qty'];
				if ( $qty < 0 ) { return $bad( 'La cantidad no puede ser negativa.' ); }
				if ( $qty > $lines[ $id ] ) { return $bad( 'Desde aquí solo puedes bajar cantidades, no subirlas. Para subir, escribe a la tienda.' ); }
				if ( $qty !== $lines[ $id ] ) { $wanted[ $id ] = $qty; }
			}
			$final = $lines;
			foreach ( $wanted as $id => $qty ) { $final[ $id ] = $qty; }
			if ( ! array_filter( $final ) ) { return $bad( 'El pedido no puede quedar sin productos. Si ya no lo quieren, cancélalo.' ); }
			if ( $wanted ) {
				$out['lines'] = array();
				foreach ( $wanted as $id => $qty ) { $out['lines'][] = array( 'itemId' => $id, 'qty' => $qty ); }
			}
		}
		return array( 'data' => $out, 'error' => '' );
	}

	// ---------- Datos del pedido ----------

	/** El pedido si existe y es de esta gestora; si no, null (a otra gestora no se le dice nada de él). */
	private static function her_order( int $order_id, int $user_id ): ?WC_Order {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return null; }
		return $user_id > 0 && absint( $order->get_meta( '_cvd_owner_user_id', true ) ) === $user_id ? $order : null;
	}

	public static function facts( WC_Order $order, int $user_id ): array {
		$cancelled_at = (string) $order->get_meta( '_cvd_cancelled_at', true );
		$when = '' !== $cancelled_at ? strtotime( $cancelled_at . ' UTC' ) : 0;
		return array(
			'mine'              => absint( $order->get_meta( '_cvd_owner_user_id', true ) ) === $user_id && $user_id > 0,
			'status'            => $order->get_status(),
			'operation'         => sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ),
			'stage'             => sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned',
			'pickup'            => 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ),
			'payment_kind'      => sanitize_key( (string) $order->get_meta( '_cvd_payment_kind', true ) ),
			// Cualquier reserva apuntada en un pedido abierto (vigente o vencida): eso lo lleva la tienda.
			'reserva'           => class_exists( 'CVD_Reserva' ) && null !== CVD_Reserva::get( $order ),
			'commission_status' => sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ),
			'payout'            => absint( $order->get_meta( '_cvd_payout_id', true ) ) > 0,
			'ledger_settled'    => class_exists( 'CVD_Messenger_Accounting' ) && ! CVD_Messenger_Accounting::can_void_order( $order ),
			'cancelled_by_her'  => absint( $order->get_meta( '_cvd_cancelled_by', true ) ) === $user_id && 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_cancel_source', true ) ),
			'cancelled_days'    => $when ? ( time() - $when ) / DAY_IN_SECONDS : 0,
		);
	}

	/** Qué puede hacer con el pedido: [cambiar => motivo|'' , cancelar => …, recuperar => …]. */
	public static function abilities( WC_Order $order, int $user_id ): array {
		$f = self::facts( $order, $user_id );
		$out = array();
		foreach ( array( 'cambiar', 'cancelar', 'recuperar' ) as $action ) { $out[ $action ] = self::block_reason( $action, $f ); }
		return $out;
	}

	private static function lines_of( WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) { $lines[ (int) $item_id ] = (int) $item->get_quantity(); }
		return $lines;
	}

	// ---------- Endpoints ----------

	public static function list_request( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$orders = wc_get_orders( array( 'limit' => 80, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user_id, 'status' => array( 'pending', 'processing', 'on-hold', 'cancelled' ), 'date_created' => '>' . ( time() - 45 * DAY_IN_SECONDS ) ) );
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$can = self::abilities( $order, $user_id );
			// Cancelados: solo los que puede recuperar (los demás ni se pintan).
			if ( $order->has_status( 'cancelled' ) && '' !== $can['recuperar'] ) { continue; }
			$out[] = array( 'id' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'status' => $order->get_status(), 'total' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'can' => $can );
		}
		return self::no_cache( array( 'orders' => $out ) );
	}

	public static function detail_request( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$order = self::her_order( absint( $request['id'] ), $user_id );
		if ( ! $order ) { return new WP_Error( 'cvd_gp_not_found', 'Ese pedido no está entre los tuyos.', array( 'status' => 404 ) ); }
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$qty = max( 1, (int) $item->get_quantity() );
			$lines[] = array( 'itemId' => (int) $item_id, 'name' => $item->get_name(), 'qty' => (int) $item->get_quantity(), 'unit' => round( (float) $item->get_total() / $qty, 2 ), 'total' => round( (float) $item->get_total(), 2 ) );
		}
		$when = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::for_order( $order ) : array( 'label' => '' );
		$pickup = 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		return self::no_cache( array(
			'id' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'can' => self::abilities( $order, $user_id ),
			'firstName' => $order->get_billing_first_name(), 'lastName' => $order->get_billing_last_name(), 'phone' => $order->get_billing_phone(),
			'address' => $order->get_billing_address_1() ?: $order->get_shipping_address_1(), 'locality' => (string) $order->get_meta( '_cvd_locality', true ),
			'note' => $order->get_customer_note(), 'pickup' => $pickup,
			'date' => (string) $order->get_meta( '_cvd_delivery_date', true ), 'window' => sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) ),
			'whenLabel' => (string) ( $when['label'] ?? '' ),
			'minDate' => class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::min_date() : '', 'maxDate' => class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::max_date() : '',
			'lines' => $lines, 'total' => (float) $order->get_total(), 'currency' => $order->get_currency(),
			'hasMessenger' => null !== CVD_Order_Edit::messenger_to_warn( $order ),
		) );
	}

	public static function change_request( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::run( 'cambiar', absint( $request['id'] ), get_current_user_id(), $params );
	}

	public static function cancel_request( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::run( 'cancelar', absint( $request['id'] ), get_current_user_id(), $params );
	}

	public static function recover_request( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::run( 'recuperar', absint( $request['id'] ), get_current_user_id(), $params );
	}

	/**
	 * Lo común a cambiar, cancelar y recuperar: suyo + reglas + una orden a la vez + la misma orden no se aplica dos veces.
	 * Público para la prueba en la base real (tests/gestora-pedidos-vivo.php), que llama sin petición HTTP.
	 */
	public static function run( string $action, int $order_id, int $user_id, array $params ) {
		$order = self::her_order( $order_id, $user_id );
		if ( ! $order ) { return new WP_Error( 'cvd_gp_not_found', 'Ese pedido no está entre los tuyos.', array( 'status' => 404 ) ); }
		$request_id = substr( (string) preg_replace( '/[^a-zA-Z0-9-]/', '', (string) ( $params['requestId'] ?? '' ) ), 0, 64 );
		$seen = $order->get_meta( self::REQUEST_META, true );
		if ( '' !== $request_id && is_array( $seen ) && isset( $seen[ $request_id ] ) ) {
			return self::no_cache( array( 'ok' => true, 'replayed' => true, 'message' => (string) $seen[ $request_id ]['message'] ) );
		}
		$why = self::block_reason( $action, self::facts( $order, $user_id ) );
		if ( '' !== $why ) { return new WP_Error( 'cvd_gp_blocked', $why, array( 'status' => 409 ) ); }
		$reason = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $params['reason'] ?? '' ) ) ) );
		$data = array();
		if ( 'cambiar' === $action ) {
			$pickup = 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
			$white = self::whitelist( self::lines_of( $order ), $params, $pickup );
			if ( '' !== $white['error'] ) { return new WP_Error( 'cvd_gp_not_allowed', $white['error'], array( 'status' => 422 ) ); }
			$data = $white['data'];
		} elseif ( mb_strlen( $reason ) < 3 ) {
			return new WP_Error( 'cvd_gp_reason', 'cancelar' === $action ? 'Escribe por qué se cancela (una frase corta).' : 'Escribe por qué lo recuperas (una frase corta).', array( 'status' => 422 ) );
		}
		$lock = 'cvd_gp_lock_' . $order_id;
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			if ( time() - (int) get_option( $lock ) < 60 ) { return new WP_Error( 'cvd_gp_busy', 'Ese pedido se está cambiando ahora mismo. Espera un momento y vuelve a probar.', array( 'status' => 409 ) ); }
			update_option( $lock, time(), false );
		}
		try {
			$result = CVD_Order_Edit::as_gestora( $user_id, static function () use ( $action, $order_id, $user_id, $reason, $data ) {
				if ( 'cambiar' === $action ) { return CVD_Order_Edit::edit( $order_id, $data, $user_id, 'gestora' ); }
				if ( 'cancelar' === $action ) { return CVD_Order_Edit::cancel( $order_id, $reason, $user_id, 'gestora' ); }
				return CVD_Order_Edit::reactivate( $order_id, $reason, $user_id );
			} );
		} finally {
			delete_option( $lock );
		}
		if ( is_wp_error( $result ) ) { return $result; }
		$message = self::message_for_her( $action, $result, $order_id );
		if ( '' !== $request_id ) {
			$fresh = wc_get_order( $order_id );
			if ( $fresh ) {
				$seen = $fresh->get_meta( self::REQUEST_META, true );
				$seen = is_array( $seen ) ? $seen : array();
				$seen[ $request_id ] = array( 'action' => $action, 'at' => current_time( 'mysql', true ), 'message' => $message );
				$fresh->update_meta_data( self::REQUEST_META, array_slice( $seen, -20, null, true ) );
				$fresh->save();
			}
		}
		$fresh = wc_get_order( $order_id );
		return self::no_cache( array( 'ok' => true, 'message' => $message, 'total' => $fresh ? (float) $fresh->get_total() : null, 'changes' => $result['changes'] ?? array() ) );
	}

	/** Una línea clara para ella, con el total nuevo (sin datos internos de la tienda). */
	private static function message_for_her( string $action, array $result, int $order_id ): string {
		$order = wc_get_order( $order_id );
		$num = $order ? $order->get_order_number() : (string) $order_id;
		$money = $order ? wc_format_decimal( (float) $order->get_total(), 2 ) . ' ' . $order->get_currency() : '';
		$messenger = ! empty( $result['messenger'] );
		if ( 'cancelar' === $action ) { return 'Listo: el pedido #' . $num . ' quedó cancelado.' . ( $messenger ? ' El bot le avisa al mensajero.' : '' ) . ' Si fue un error, puedes recuperarlo en los próximos ' . self::RECOVER_DAYS . ' días.'; }
		if ( 'recuperar' === $action ) { return 'Listo: el pedido #' . $num . ' está otra vez activo. Total: ' . $money . '.'; }
		if ( empty( $result['changes'] ) ) { return 'No había nada que cambiar en el pedido #' . $num . '.'; }
		return 'Listo: el pedido #' . $num . ' quedó cambiado. Total ahora: ' . $money . '.' . ( $messenger ? ' El bot le avisa del cambio al mensajero.' : '' );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
