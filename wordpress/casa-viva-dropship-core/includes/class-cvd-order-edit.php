<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cancelar y cambiar pedidos a mano (Core 3.13.97, Ernesto 14-oct: «que Lennys, Zaymi y Nana puedan cancelar
 * pedidos manualmente y editarlos, incluso desde sus centros»).
 *
 * Una sola lógica para /ventas/ (dependientas, operador y administración) y para el panel de Lennys.
 * - Cancelar: la cascada de CVD_Order_Transition_Service (WooCommerce devuelve las existencias, se anula la
 *   comisión, la carrera y la ganancia del mensajero). Pide un motivo y deja nota con quién y por qué.
 *   No se cancela lo ya entregado o cerrado (eso es «Devolución»). La dependienta tampoco cancela lo que ya
 *   tiene el mensajero en la mano: eso lo decide administración.
 * - Cambiar: nombre, teléfono, dirección, día y franja, nota y productos (cantidad, quitar, añadir).
 *   Solo mientras el paquete sigue en la tienda (antes de «Entregado al mensajero»). Recalcula totales,
 *   existencias (como el administrador de WooCommerce) y la comisión de la gestora.
 * - Caja (NEXO): un cambio de productos hecho en /ventas/ queda en _cvd_caja_edits y el puente
 *   nexo-stock-bridge lo aplica en la caja (o lo descarta si la línea aún no había pasado a la caja).
 *   Los cambios del panel los aplica en el acto la función nexo-panel-web (con el campo «stock»).
 */
final class CVD_Order_Edit {
	private const OPEN_WC = array( 'pending', 'processing', 'on-hold' );
	/** Etapas del reparto en que el paquete sigue en la tienda. */
	private const IN_STORE = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store' );
	/** Etapas en que el cliente ya lo tiene (o el dinero ya se cuadró): no se cancela, se registra devolución. */
	private const DELIVERED = array( 'delivered', 'cash_returned', 'closed' );
	public const CAJA_EDITS_META = '_cvd_caja_edits';
	private const WINDOWS = array( '', 'morning', 'afternoon' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$can = array( __CLASS__, 'can_manage' );
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_request' ), 'permission_callback' => $can ) );
		// 3.14.104 (Ernesto 1-nov, #5976): reactivar un pedido cancelado por error.
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/reactivate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reactivate_request' ), 'permission_callback' => $can ) );
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/edit', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'detail_request' ), 'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'edit_request' ), 'permission_callback' => $can ),
		) );
		// 3.14.42 (Lennys 20-oct): poner o cambiar el vuelto que lleva el mensajero, también con el pedido ya en la calle.
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/change', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'change_request' ), 'permission_callback' => $can ) );
		// 3.14.116 (Ernesto 3-nov, caso #6062 → #6102): dividir un pedido en dos (otro cliente y otra dirección), solo la tienda.
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/split', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'split_request' ), 'permission_callback' => $can ) );
		register_rest_route( 'casa-viva/v1', '/sales/products', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'products_request' ), 'permission_callback' => $can ) );
		register_rest_route( 'casa-viva/v1', '/sales/extras', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'extras_request' ), 'permission_callback' => $can ) );
	}

	/** administrator, shop_manager (manage_woocommerce), cvd_operator y cvd_clerk (cvd_manage_sales). */
	public static function can_manage(): bool {
		return is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'cvd_manage_sales' ) );
	}

	private static function user_can_manage( int $user_id ): bool {
		if ( $user_id > 0 && self::$gestora_actor === $user_id ) { return true; } // 3.14.116: la gestora, sobre SU pedido (ya comprobado en CVD_Gestora_Pedidos)
		return $user_id > 0 && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'cvd_manage_sales' ) );
	}

	/**
	 * 3.14.116 (Ernesto 3-nov): la gestora usa edit/cancel/reactivate sobre SUS pedidos (nivel 1 del plan del Vigía).
	 * Solo CVD_Gestora_Pedidos lo llama, DESPUÉS de comprobar que el pedido es suyo, sin recoger, sin prepago, sin
	 * reserva y con la comisión sin pagar. Mientras dura $fn, esa gestora cuenta como quien puede cambiar pedidos.
	 */
	private static int $gestora_actor = 0;

	public static function as_gestora( int $user_id, callable $fn ) {
		$previous = self::$gestora_actor;
		self::$gestora_actor = $user_id;
		try { return $fn(); } finally { self::$gestora_actor = $previous; }
	}

	/** Etapa del reparto tal cual (sin la marca de incidencia). */
	private static function delivery_stage( WC_Order $order ): string {
		return sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned';
	}

	private static function is_pickup( WC_Order $order ): bool {
		return 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
	}

	/** 3.14.104: el paquete está con el mensajero; la dependienta cancela solo si confirma que ya volvió a la tienda. */
	public const RETURN_BLOCK = 'El mensajero tiene el paquete. Cuando lo devuelva a la tienda, cancélalo marcando que ya está en la tienda.';

	/**
	 * '' = se puede cancelar; si no, el motivo en palabras de la tienda.
	 * $returned = quien cancela confirma que el paquete ya volvió a la tienda (Ernesto 1-nov: las dependientas cancelan).
	 */
	public static function cancel_block( WC_Order $order, int $user_id, bool $returned = false ): string {
		if ( ! self::user_can_manage( $user_id ) ) { return 'Esta cuenta no puede cancelar pedidos.'; }
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) { return 'Este pedido ya está cancelado.'; }
		$stage = self::delivery_stage( $order );
		$verified = 'verified' === sanitize_key( (string) $order->get_meta( '_cvd_cash_status', true ) );
		if ( $order->has_status( 'completed' ) || $verified || in_array( $stage, self::DELIVERED, true ) ) {
			return 'Este pedido ya se entregó: no se cancela. Cuando esté cerrado, usa «Devolución».';
		}
		if ( ! $order->has_status( self::OPEN_WC ) ) { return 'Este pedido no está abierto.'; }
		if ( ! $returned && ! user_can( $user_id, 'manage_woocommerce' ) && ! self::is_pickup( $order ) && in_array( $stage, array( 'picked_up', 'handed_over' ), true ) ) {
			return self::RETURN_BLOCK;
		}
		if ( class_exists( 'CVD_Messenger_Accounting' ) && ! CVD_Messenger_Accounting::can_void_order( $order ) ) {
			return 'La ganancia del mensajero de este pedido ya está liquidada: no se puede cancelar desde aquí.';
		}
		// 3.14.72 (Pilar 3): con reserva vigente la dependienta no cancela (administración sí, confirmando).
		if ( class_exists( 'CVD_Reserva' ) ) { return CVD_Reserva::clerk_block( $order, $user_id ); }
		return '';
	}

	/** '' = se puede cambiar; si no, el motivo. */
	public static function edit_block( WC_Order $order, int $user_id ): string {
		if ( ! self::user_can_manage( $user_id ) ) { return 'Esta cuenta no puede cambiar pedidos.'; }
		if ( ! $order->has_status( self::OPEN_WC ) ) { return 'Este pedido ya está cerrado o cancelado: no se puede cambiar.'; }
		$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
		if ( in_array( $operation, array( 'delivered', 'cancelled' ), true ) ) { return 'Este pedido ya está cerrado o cancelado: no se puede cambiar.'; }
		if ( ! self::is_pickup( $order ) && ! in_array( self::delivery_stage( $order ), self::IN_STORE, true ) ) {
			return 'El mensajero ya recogió el paquete: ya no se cambia. Si hace falta, habla con él y con Lennys.';
		}
		return '';
	}

	/** «Lennys Rondon (administración)», «Nana (dependienta)»; desde el panel, el nombre de quien lo pulsó. */
	private static function who( int $user_id ): string {
		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : 'usuario #' . $user_id;
		if ( class_exists( 'CVD_Panel_Bridge' ) && CVD_Panel_Bridge::via_panel() ) { $by = CVD_Panel_Bridge::current_by(); return $name . ' (panel' . ( '' !== $by ? ' · ' . $by : '' ) . ')'; }
		if ( $user_id > 0 && self::$gestora_actor === $user_id ) { return $name . ' (gestora, desde su área)'; } // 3.14.116
		$role = $user && user_can( $user, 'manage_woocommerce' ) ? 'administración' : ( $user && in_array( 'cvd_operator', (array) $user->roles, true ) ? 'operador' : 'dependienta' );
		return $name . ' (' . $role . ')';
	}

	/** El mensajero que ya tenía el pedido (para avisarle), o null. */
	public static function messenger_to_warn( WC_Order $order ): ?array {
		if ( self::is_pickup( $order ) || ! in_array( self::delivery_stage( $order ), array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over' ), true ) ) { return null; }
		$user = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		if ( ! $user ) { return null; }
		return array( 'name' => $user->display_name, 'phone' => class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $user->ID ) : '' );
	}

	// ---------- Cancelar ----------

	/**
	 * Cancela un pedido con motivo. Devuelve array( ok, message, messenger ) o WP_Error.
	 * $source: 'ventas' o 'panel' (solo para la nota).
	 */
	public static function cancel( int $order_id, string $reason, int $user_id, string $source = 'ventas', bool $confirm_reserva = false, bool $returned = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_cancel_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$reason = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $reason ) ) );
		if ( mb_strlen( $reason ) < 3 ) { return new WP_Error( 'cvd_cancel_reason', 'Escribe por qué se cancela (una frase corta).', array( 'status' => 422 ) ); }
		$reason = mb_substr( $reason, 0, 200 );
		$block = self::cancel_block( $order, $user_id, $returned );
		if ( '' !== $block ) { return new WP_Error( 'cvd_cancel_blocked', $block, array( 'status' => 409, 'needsReturn' => self::RETURN_BLOCK === $block ) ); }
		$returned = $returned && self::RETURN_BLOCK === self::cancel_block( $order, $user_id ); // solo cuenta si hacía falta
		// 3.14.72 (Pilar 3, caso #5551): reserva vigente → administración confirma; la dependienta ya quedó bloqueada arriba.
		if ( class_exists( 'CVD_Reserva' ) ) {
			$gate = CVD_Reserva::cancel_gate( $order, $user_id, $confirm_reserva );
			if ( is_wp_error( $gate ) ) { return $gate; }
		}
		$messenger = self::messenger_to_warn( $order );
		$stage = self::delivery_stage( $order );
		$before = class_exists( 'CVD_Order_Avisos' ) ? CVD_Order_Avisos::snapshot( $order ) : array(); // 3.13.98: aviso por WhatsApp
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) ) { return new WP_Error( 'cvd_cancel_service', 'No se pudo cancelar ahora.', array( 'status' => 500 ) ); }
		// system: el permiso ya se comprobó arriba (la dependienta no tiene manage_woocommerce).
		if ( class_exists( 'CVD_Order_Avisos' ) ) { CVD_Order_Avisos::begin_manual(); }
		try {
			$result = CVD_Order_Transition_Service::cancel( $order_id, 'cancelled', array(
				'actor_user_id' => $user_id, 'system' => true, 'source' => 'cvd_order_edit_' . sanitize_key( $source ),
				'idempotency_key' => 'manual-cancel:' . $order_id . ':' . wp_generate_uuid4(),
			) );
		} finally {
			if ( class_exists( 'CVD_Order_Avisos' ) ) { CVD_Order_Avisos::end_manual(); }
		}
		$fresh = wc_get_order( $order_id );
		if ( empty( $result['success'] ) || ! $fresh || ! $fresh->has_status( 'cancelled' ) ) {
			$code = (string) ( $result['error_code'] ?? '' );
			$text = CVD_Order_Transition_Service::CONFLICT === $code ? 'Otra persona está cambiando este pedido ahora mismo, o ya no se puede cancelar. Actualiza y vuelve a probar.' : 'No se pudo cancelar el pedido. No cambió nada.';
			return new WP_Error( 'cvd_cancel_failed', $text, array( 'status' => 409, 'transition' => $result ) );
		}
		$at = current_time( 'mysql', true );
		$fresh->update_meta_data( '_cvd_cancel_reason', $reason );
		$fresh->update_meta_data( '_cvd_cancelled_by', $user_id );
		$fresh->update_meta_data( '_cvd_cancelled_at', $at );
		$fresh->update_meta_data( '_cvd_cancel_source', sanitize_key( $source ) );
		if ( $returned ) { $fresh->update_meta_data( '_cvd_cancel_returned_to_store', $at ); $fresh->add_order_note( self::who( $user_id ) . ' confirma que el mensajero ya devolvió el paquete a la tienda.' ); }
		$fresh->add_order_note( sprintf( 'Pedido CANCELADO por %s. Motivo: %s. Las existencias vuelven a la tienda%s.%s', self::who( $user_id ), $reason, absint( $fresh->get_meta( '_cvd_owner_user_id', true ) ) ? ' y la comisión de quien vendió queda anulada' : '', $messenger ? ' Lo tenía el mensajero ' . $messenger['name'] . ' (' . CVD_Delivery::label( $stage ) . '): hay que avisarle.' : '' ) );
		$fresh->save();
		// 3.13.98: VivaBot avisa solo al mensajero, a la gestora y (venta directa) al cliente (CVD_Order_Avisos).
		if ( class_exists( 'CVD_Order_Avisos' ) ) { CVD_Order_Avisos::record_cancel( $fresh, $before, $reason, $user_id, sanitize_key( $source ), $messenger, $stage ); }
		$message = 'Pedido #' . $fresh->get_order_number() . ' cancelado. Las existencias vuelven a la tienda.';
		if ( $messenger ) { $message .= ' El bot le avisa por WhatsApp a ' . $messenger['name'] . ' que ya no lo lleve.'; }
		return array( 'ok' => true, 'message' => $message, 'messenger' => $messenger );
	}

	public static function cancel_request( WP_REST_Request $request ) {
		$result = self::cancel( absint( $request['id'] ), (string) $request->get_param( 'reason' ), get_current_user_id(), 'ventas', (bool) $request->get_param( 'confirmReserva' ), (bool) $request->get_param( 'returnedToStore' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	// ---------- Reactivar (3.14.104, Ernesto 1-nov: #5976 se canceló solo y no había cómo deshacerlo) ----------
	public const REACTIVATE_DAYS = 7;

	/** '' = se puede reactivar; si no, el motivo en palabras de la tienda. */
	public static function reactivate_block( WC_Order $order, int $user_id ): string {
		if ( ! self::user_can_manage( $user_id ) ) { return 'Esta cuenta no puede reactivar pedidos.'; }
		if ( ! $order->has_status( 'cancelled' ) ) { return 'Solo se reactivan pedidos cancelados.'; }
		if ( (float) $order->get_total_refunded() > 0 || $order->get_meta( '_cvd_return', true ) ) { return 'Este pedido tiene una devolución o un reembolso: haz un pedido nuevo.'; }
		if ( 'paid' === sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ) { return 'La comisión de este pedido ya se pagó: haz un pedido nuevo.'; }
		$at = (string) $order->get_meta( '_cvd_cancelled_at', true );
		$when = '' !== $at ? strtotime( $at . ' UTC' ) : ( $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0 );
		if ( $when && time() - $when > self::REACTIVATE_DAYS * DAY_IN_SECONDS ) { return 'Lleva más de ' . self::REACTIVATE_DAYS . ' días cancelado: haz un pedido nuevo.'; }
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) { return 'Un producto de este pedido ya no existe: haz un pedido nuevo.'; }
			if ( $product->managing_stock() && (int) $product->get_stock_quantity() < (int) $item->get_quantity() ) { return 'No queda existencia de «' . $product->get_name() . '»: no se puede reactivar.'; }
		}
		return '';
	}

	/** Vuelve a «En espera» con las existencias descontadas, la comisión pendiente y el reparto sin asignar. */
	public static function reactivate( int $order_id, string $reason, int $user_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_reactivate_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$reason = mb_substr( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $reason ) ) ), 0, 200 );
		if ( mb_strlen( $reason ) < 3 ) { return new WP_Error( 'cvd_reactivate_reason', 'Escribe por qué se reactiva (una frase corta).', array( 'status' => 422 ) ); }
		$block = self::reactivate_block( $order, $user_id );
		if ( '' !== $block ) { return new WP_Error( 'cvd_reactivate_blocked', $block, array( 'status' => 409 ) ); }
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) || ! method_exists( 'CVD_Order_Transition_Service', 'reopen_after_cancel' ) ) { return new WP_Error( 'cvd_reactivate_service', 'No se pudo reactivar ahora.', array( 'status' => 500 ) ); }
		// La operación vuelve a como estaba antes de cancelar («Listo», «Preparando»…).
		$operation = 'new';
		$history = $order->get_meta( '_cvd_operation_history', true );
		foreach ( array_reverse( is_array( $history ) ? $history : array() ) as $step ) {
			if ( 'cancelled' === ( $step['to'] ?? '' ) && ! in_array( (string) ( $step['from'] ?? '' ), array( '', 'cancelled', 'delivered' ), true ) ) { $operation = sanitize_key( (string) $step['from'] ); break; }
		}
		$who = self::who( $user_id );
		// WooCommerce descuenta otra vez las existencias al pasar a «En espera» (al cancelar las devolvió).
		$order->update_status( 'on-hold', sprintf( 'Pedido REACTIVADO por %s. Motivo: %s.', $who, $reason ) );
		$fresh = wc_get_order( $order_id );
		CVD_Order_Transition_Service::reopen_after_cancel( $fresh, $operation, $user_id );
		foreach ( array( '_cvd_messenger_user_id', '_cvd_messenger_earning_status', '_cvd_delivery_accepted_at', '_cvd_to_store_at', '_cvd_bot_eta', '_cvd_bot_eta_at', '_cvd_direct_assignment' ) as $key ) { $fresh->delete_meta_data( $key ); }
		$fresh->update_meta_data( '_cvd_reactivated_at', current_time( 'mysql', true ) );
		$fresh->update_meta_data( '_cvd_reactivated_by', $user_id );
		$fresh->save();
		if ( class_exists( 'CVD_Commissions' ) && method_exists( 'CVD_Commissions', 'reopen' ) ) { CVD_Commissions::reopen( $order_id ); }
		$fresh = wc_get_order( $order_id );
		$delivery = ! self::is_pickup( $fresh );
		if ( $delivery && 'ready' === $operation && class_exists( 'CVD_Delivery' ) ) { CVD_Delivery::dispatch_new_order( $order_id ); }
		$message = 'Pedido #' . $fresh->get_order_number() . ' reactivado: está «En espera», las existencias se descontaron otra vez y la comisión vuelve a pendiente.';
		$message .= $delivery ? ( 'ready' === $operation ? ' Sale otra vez al grupo de mensajeros.' : ' Cuando esté listo, sale al grupo de mensajeros.' ) : ' Sigue de recogida en tienda.';
		return array( 'ok' => true, 'message' => $message );
	}

	public static function reactivate_request( WP_REST_Request $request ) {
		$result = self::reactivate( absint( $request['id'] ), (string) $request->get_param( 'reason' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	// ---------- Cambiar ----------

	/** Lo que necesita el formulario de «Cambiar pedido». */
	public static function detail( WC_Order $order, int $user_id ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$qty = max( 1, (int) $item->get_quantity() );
			$lines[] = array( 'itemId' => $item_id, 'name' => $item->get_name(), 'qty' => (int) $item->get_quantity(), 'unit' => round( (float) $item->get_subtotal() / $qty, 2 ), 'total' => (float) $item->get_total(), 'extra' => self::is_extra_item( $item ) );
		}
		$why = self::edit_block( $order, $user_id );
		$when = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::for_order( $order ) : array( 'date' => '', 'label' => '' );
		return array(
			'id' => $order->get_id(), 'number' => $order->get_order_number(), 'editable' => '' === $why, 'why' => $why,
			'firstName' => $order->get_billing_first_name(), 'lastName' => $order->get_billing_last_name(), 'phone' => $order->get_billing_phone(),
			'address' => $order->get_billing_address_1() ?: $order->get_shipping_address_1(), 'locality' => (string) $order->get_meta( '_cvd_locality', true ),
			'note' => $order->get_customer_note(), 'pickup' => self::is_pickup( $order ),
			'date' => (string) $order->get_meta( '_cvd_delivery_date', true ), 'window' => sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) ),
			'whenLabel' => (string) ( $when['label'] ?? '' ),
			'minDate' => class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::min_date() : '', 'maxDate' => class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::max_date() : '',
			'lines' => $lines, 'total' => (float) $order->get_total(), 'currency' => $order->get_currency(),
			'messenger' => self::messenger_to_warn( $order ),
			// 3.14.18 (33/33/33): solo en pedidos de gestora se puede añadir un «extra en tienda».
			'gestoraOrder' => self::is_gestora_order( $order ), 'gestoraName' => (string) $order->get_meta( 'gestora_nombre', true ),
			// 3.14.42: vuelto que lleva el mensajero [{amount, currency}] (vacío = sin vuelto).
			'change' => self::change_of( $order ),
		);
	}

	/** Vuelto guardado en el pedido como lista [{amount, currency}] (también el formato viejo «yes» + importe). */
	public static function change_of( WC_Order $order ): array {
		$raw = $order->get_meta( '_cvd_change_required', true );
		if ( 'yes' === $raw ) { $raw = array( array( 'amount' => $order->get_meta( '_cvd_change_amount', true ), 'currency' => $order->get_meta( '_cvd_change_currency', true ) ) ); }
		$list = self::change_list( is_array( $raw ) ? $raw : array() );
		return is_wp_error( $list ) ? array() : $list;
	}

	/** Valida un vuelto: hasta 3 líneas, importe > 0, moneda USD, CUP o EUR. Lista vacía = sin vuelto. */
	public static function change_list( $raw ) {
		$out = array();
		foreach ( array_slice( (array) $raw, 0, 3 ) as $line ) {
			$amount = (float) wc_format_decimal( is_array( $line ) ? ( $line['amount'] ?? 0 ) : 0, 2 );
			$currency = strtoupper( sanitize_key( (string) ( is_array( $line ) ? ( $line['currency'] ?? '' ) : '' ) ) );
			if ( $amount <= 0 ) { continue; }
			if ( ! in_array( $currency, array( 'USD', 'CUP', 'EUR' ), true ) || $amount > 1000000 ) {
				return new WP_Error( 'cvd_change_invalid', 'El vuelto va en USD, CUP o EUR y con un importe razonable.', array( 'status' => 422 ) );
			}
			$out[] = array( 'amount' => $amount, 'currency' => $currency );
		}
		return $out;
	}

	/**
	 * 3.14.42 (Lennys 20-oct): pone o cambia el vuelto que lleva el mensajero (sale del FONDO de la caja y vuelve
	 * con el cobro; no es venta). Se puede con el pedido ya en la calle; no con el pedido cerrado o cancelado.
	 * Deja nota «Vuelto: antes … → ahora …» con quién lo cambió.
	 */
	public static function set_change( int $order_id, $raw, int $user_id, string $source = 'ventas', string $by = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_edit_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		if ( $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
			return new WP_Error( 'cvd_change_closed', 'Este pedido ya está cerrado o cancelado: el vuelto ya no se cambia.', array( 'status' => 409 ) );
		}
		$list = self::change_list( $raw );
		if ( is_wp_error( $list ) ) { return $list; }
		$label = static fn( array $l ): string => $l ? implode( ' + ', array_map( static fn( $x ) => wc_format_decimal( $x['amount'], 2, true ) . ' ' . $x['currency'], $l ) ) : 'sin vuelto';
		$before = self::change_of( $order );
		if ( $before == $list ) { return array( 'ok' => true, 'change' => $list, 'message' => 'El vuelto ya era ' . $label( $list ) . '.' ); }
		$order->update_meta_data( '_cvd_change_required', $list );
		$who = '' !== $by ? $by : ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : 'la tienda' );
		$order->add_order_note( sprintf( 'Vuelto (%s): antes %s → ahora %s. Lo cambió %s.', 'caja' === $source ? 'caja' : ( 'panel' === $source ? 'panel' : 'Ventas' ), $label( $before ), $label( $list ), $who ) );
		$order->save();
		return array( 'ok' => true, 'change' => $list, 'message' => 'Vuelto del pedido #' . $order->get_order_number() . ': ' . $label( $list ) . '. Avísale al mensajero.' );
	}

	public static function change_request( WP_REST_Request $request ) {
		$result = self::set_change( absint( $request['id'] ), (array) $request->get_param( 'changeRequired' ), get_current_user_id(), 'ventas' );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/** 3.14.18: pedido vendido por una gestora (su enlace o su área), no venta directa. */
	public static function is_gestora_order( WC_Order $order ): bool {
		return absint( $order->get_meta( '_cvd_owner_user_id', true ) ) > 0 && 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
	}

	private static function is_extra_item( $item ): bool {
		$mark = $item instanceof WC_Order_Item_Product && class_exists( 'CVD_Commissions' ) ? $item->get_meta( CVD_Commissions::EXTRA_META, true ) : null;
		return is_array( $mark ) && ! empty( $mark );
	}

	public static function detail_request( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_edit_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		return rest_ensure_response( self::detail( $order, get_current_user_id() ) );
	}

	/**
	 * Aplica los cambios que lleguen. $data: firstName, lastName, phone, address, deliveryDate, deliveryWindow, note,
	 * lines [{itemId, qty}] (0 = quitar), add [{wooId, qty}]. Campo ausente = no se toca.
	 * Devuelve array( ok, changes[], stock[], total ) o WP_Error. Nada se guarda si algo no vale.
	 */
	public static function edit( int $order_id, array $data, int $user_id, string $source = 'ventas' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_edit_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$block = self::edit_block( $order, $user_id );
		if ( '' !== $block ) { return new WP_Error( 'cvd_edit_blocked', $block, array( 'status' => 409 ) ); }
		$before = class_exists( 'CVD_Order_Avisos' ) ? CVD_Order_Avisos::snapshot( $order ) : array(); // 3.13.98: aviso por WhatsApp

		// 1. Validar todo antes de tocar nada.
		$text = static fn( $key ): ?string => array_key_exists( $key, $data ) && null !== $data[ $key ] ? trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $data[ $key ] ) ) ) : null;
		$first = $text( 'firstName' ); $last = $text( 'lastName' ); $phone = $text( 'phone' ); $address = $text( 'address' );
		if ( null !== $first && '' === $first ) { return new WP_Error( 'cvd_edit_name', 'El nombre no puede quedar vacío.', array( 'status' => 422 ) ); }
		// 3.14.69.12 (Dany, Ernesto 26-oct): una recogida en tienda puede no tener teléfono; vacío = no se toca.
		if ( null !== $phone && '' === preg_replace( '/\D/', '', $phone ) && self::is_pickup( $order ) ) { $phone = null; }
		if ( null !== $phone ) {
			$phone = preg_replace( '/[^0-9+ ]/', '', $phone );
			if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 8 ) { return new WP_Error( 'cvd_edit_phone', 'El teléfono necesita al menos 8 números.', array( 'status' => 422 ) ); }
		}
		if ( null !== $address && '' === $address ) { $address = null; } // vacío = no se toca (como antes en el panel)
		$date = null; $window = null; $pickup = self::is_pickup( $order );
		if ( ! $pickup && array_key_exists( 'deliveryDate', $data ) && null !== $data['deliveryDate'] ) {
			$raw = trim( (string) $data['deliveryDate'] );
			// El día que ya tenía (aunque ya pasó) no se vuelve a validar: solo se valida un día nuevo.
			$date = $raw === (string) $order->get_meta( '_cvd_delivery_date', true ) ? null : ( '' === $raw ? '' : CVD_Delivery_When::normalize( $raw ) );
			if ( '' !== $raw && '' === $date ) { return new WP_Error( 'cvd_edit_date', 'Elige un día de entrega entre hoy y dentro de ' . CVD_Delivery_When::MAX_DAYS . ' días (o déjalo vacío: lo antes posible).', array( 'status' => 422 ) ); }
		}
		if ( ! $pickup && array_key_exists( 'deliveryWindow', $data ) && null !== $data['deliveryWindow'] ) {
			$window = sanitize_key( (string) $data['deliveryWindow'] );
			if ( ! in_array( $window, self::WINDOWS, true ) ) { return new WP_Error( 'cvd_edit_window', 'Elige mañana, tarde o cualquier hora.', array( 'status' => 422 ) ); }
		}
		$note = array_key_exists( 'note', $data ) && null !== $data['note'] ? sanitize_textarea_field( (string) $data['note'] ) : null;
		// 3.14.42: vuelto que lleva el mensajero (se guarda al final con set_change, con su nota).
		$change = array_key_exists( 'changeRequired', $data ) && null !== $data['changeRequired'] ? self::change_list( $data['changeRequired'] ) : null;
		if ( is_wp_error( $change ) ) { return $change; }
		$lines = array();
		foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
			$id = absint( is_array( $l ) ? ( $l['itemId'] ?? 0 ) : 0 );
			if ( $id && is_numeric( $l['qty'] ?? null ) ) { $lines[ $id ] = max( 0, min( 999, (int) $l['qty'] ) ); }
		}
		$adds = array();
		foreach ( (array) ( $data['add'] ?? array() ) as $a ) {
			$product = wc_get_product( absint( is_array( $a ) ? ( $a['wooId'] ?? 0 ) : 0 ) );
			if ( ! $product || $product->is_type( 'variable' ) || 'publish' !== $product->get_status() || 'publish' !== get_post_status( $product->get_parent_id() ?: $product->get_id() ) ) { return new WP_Error( 'cvd_edit_add', 'Uno de los productos para añadir no está a la venta (o falta elegir el color o diseño).', array( 'status' => 422 ) ); }
			$adds[] = array( $product, max( 1, min( 999, (int) ( $a['qty'] ?? 1 ) ) ), ! empty( $a['extra'] ) );
		}
		// 3.14.18 (33/33/33): el «extra en tienda» solo existe en pedidos de una gestora.
		if ( array_filter( array_column( $adds, 2 ) ) && ( ! self::is_gestora_order( $order ) || ! class_exists( 'CVD_Commissions' ) ) ) {
			return new WP_Error( 'cvd_edit_extra', '«Extra en tienda» solo va en pedidos de una gestora. En una venta directa, añádelo normal.', array( 'status' => 422 ) );
		}
		$final = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) { $final[ $item_id ] = $lines[ $item_id ] ?? (int) $item->get_quantity(); }
		if ( ! array_filter( $final ) && ! $adds ) { return new WP_Error( 'cvd_edit_empty', 'El pedido no puede quedar sin productos: cancélalo en su lugar.', array( 'status' => 422 ) ); }

		// 2. Aplicar.
		$changes = array(); $stock = array();
		if ( null !== $first && $first !== $order->get_billing_first_name() ) { $changes[] = 'Nombre: ' . $order->get_billing_first_name() . ' → ' . $first; $order->set_billing_first_name( $first ); if ( '' !== $order->get_shipping_first_name() ) { $order->set_shipping_first_name( $first ); } }
		if ( null !== $last && $last !== $order->get_billing_last_name() ) { $changes[] = 'Apellido: ' . ( $order->get_billing_last_name() ?: '—' ) . ' → ' . ( $last ?: '—' ); $order->set_billing_last_name( $last ); if ( '' !== $order->get_shipping_last_name() ) { $order->set_shipping_last_name( $last ); } }
		if ( null !== $phone && $phone !== $order->get_billing_phone() ) { $changes[] = 'Teléfono: ' . $order->get_billing_phone() . ' → ' . $phone; $order->set_billing_phone( $phone ); }
		if ( null !== $address && $address !== ( $order->get_billing_address_1() ?: $order->get_shipping_address_1() ) ) { $changes[] = 'Dirección: ' . ( $order->get_billing_address_1() ?: $order->get_shipping_address_1() ) . ' → ' . $address; $order->set_billing_address_1( $address ); $order->set_shipping_address_1( $address ); }
		$old_date = (string) $order->get_meta( '_cvd_delivery_date', true ); $old_window = sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) );
		$new_date = null === $date ? $old_date : $date; $new_window = null === $window ? $old_window : $window;
		if ( $new_date !== $old_date || $new_window !== $old_window ) {
			$changes[] = 'Día de entrega: ' . CVD_Delivery_When::label( $old_date, $old_window ) . ' → ' . CVD_Delivery_When::label( $new_date, $new_window );
			$order->update_meta_data( '_cvd_delivery_date', $new_date ); $order->update_meta_data( '_cvd_delivery_window', $new_window );
		}
		if ( null !== $note && $note !== $order->get_customer_note() ) { $changes[] = 'Nota del pedido cambiada'; $order->set_customer_note( $note ); }

		$items_changed = false; $has_extra = false;
		if ( $lines || $adds ) {
			require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php'; // wc_maybe_adjust_line_item_product_stock
			foreach ( $lines as $item_id => $qty ) {
				// false = la misma línea que tiene el pedido en memoria; si no, calculate_totals() suma la cantidad vieja.
				$item = $order->get_item( $item_id, false );
				if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_quantity() === $qty ) { continue; }
				$items_changed = true;
				$reduced = (int) $item->get_meta( '_reduced_stock', true );
				$woo = $item->get_variation_id() ?: $item->get_product_id();
				// Lo que ya se descontó de la tienda se ajusta también en la caja (ver la cabecera).
				if ( $reduced > 0 ) { $stock[] = array( 'itemId' => $item->get_id(), 'wooId' => $woo, 'delta' => (int) $item->get_quantity() - $qty ); }
				if ( 0 === $qty ) {
					wc_maybe_adjust_line_item_product_stock( $item, 0 );
					$changes[] = 'Quitado: ' . $item->get_quantity() . '× ' . $item->get_name();
					$order->remove_item( $item->get_id() );
					continue;
				}
				$unit = (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() );
				$changes[] = $item->get_name() . ': ' . $item->get_quantity() . ' → ' . $qty;
				$item->set_quantity( $qty ); $item->set_subtotal( $unit * $qty ); $item->set_total( $unit * $qty );
				$item->delete_meta_data( '_cvd_commission_snapshot' ); // 3.13.98: la comisión de esa línea se recalcula con la cantidad nueva
				$item->save();
				wc_maybe_adjust_line_item_product_stock( $item );
			}
			foreach ( $adds as $add ) {
				list( $product, $qty, $is_extra ) = $add;
				// 3.13.98 (Ernesto, 14-oct): en un pedido de gestora entra con el precio de ESA gestora (su precio propio
				// o su aumento general, como en su enlace), no con el de Casa Viva; y su ganancia cuenta en la comisión.
				// 3.14.18: un «extra en tienda» lo eligió la clienta en la tienda → precio de la tienda (el que está a la vista).
				$price = $is_extra ? array( 'base' => 0, 'unit' => 0, 'gestora' => 0 ) : self::unit_price_for( $order, $product );
				$item_id = $price['gestora'] ? $order->add_product( $product, $qty, array( 'subtotal' => $price['unit'] * $qty, 'total' => $price['unit'] * $qty ) ) : $order->add_product( $product, $qty );
				$item = $item_id ? $order->get_item( $item_id, false ) : null;
				if ( $item && $price['gestora'] ) {
					$item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $price['base'], 4 ), true );
					$item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $price['unit'], 4 ), true );
					$item->add_meta_data( '_cvd_margin_unit', wc_format_decimal( max( 0, $price['unit'] - $price['base'] ), 4 ), true );
					$item->add_meta_data( '_cvd_pricing_gestora_user_id', $price['gestora'], true );
					$item->save();
					if ( ! absint( $order->get_meta( '_cvd_pricing_gestora_user_id', true ) ) ) { $order->update_meta_data( '_cvd_pricing_gestora_user_id', $price['gestora'] ); }
				}
				if ( $item && $is_extra ) {
					$staff = self::staff_for_extra( $user_id );
					$item->add_meta_data( CVD_Commissions::EXTRA_META, array( 'staff' => $staff ? $staff->ID : 0, 'staffName' => $staff ? $staff->display_name : '', 'by' => $user_id, 'at' => current_time( 'mysql', true ) ), true );
					$item->save();
					$has_extra = true;
				}
				if ( $item ) { wc_maybe_adjust_line_item_product_stock( $item ); }
				$items_changed = true;
				$changes[] = ( $is_extra ? 'Añadido como EXTRA EN TIENDA: ' : 'Añadido: ' ) . $qty . '× ' . $product->get_name();
			}
		}
		if ( ! $changes ) { return array( 'ok' => true, 'changes' => array(), 'stock' => array(), 'total' => (float) $order->get_total(), 'message' => 'No había nada que cambiar.' ); }
		if ( $items_changed ) { $order->calculate_totals( false ); }
		if ( $stock && 'panel' !== $source ) { self::queue_caja_edits( $order, $stock ); }
		$messenger = self::messenger_to_warn( $order );
		$order->add_order_note( 'Pedido CAMBIADO por ' . self::who( $user_id ) . ': ' . implode( '; ', $changes ) . '.' . ( $messenger ? ' Ya lo tenía asignado ' . $messenger['name'] . ': hay que avisarle.' : '' ) );
		$order->save();
		if ( $items_changed && class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_pending( $order->get_id() ); }
		$saved = wc_get_order( $order->get_id() );
		// 3.14.18: nota con el reparto de lo añadido como extra (después de calcular la comisión).
		$extra_note = $has_extra && class_exists( 'CVD_Commissions' ) ? CVD_Commissions::extra_note( $saved ) : '';
		if ( '' !== $extra_note ) { $saved->add_order_note( $extra_note ); $saved->update_meta_data( '_cvd_has_extra_tienda', 1 ); $saved->save(); }
		if ( null !== $change ) { self::set_change( $saved->get_id(), $change, $user_id, $source ); $saved = wc_get_order( $saved->get_id() ); }
		$total = (float) $saved->get_total();
		if ( class_exists( 'CVD_Order_Avisos' ) ) { CVD_Order_Avisos::record_edit( $saved, $before, $user_id, sanitize_key( $source ), $messenger ); }
		$message = 'Pedido #' . $order->get_order_number() . ' cambiado' . ( $items_changed ? '. Nuevo total: ' . wc_format_decimal( $total, 2 ) . ' ' . $order->get_currency() : '' ) . '.';
		// La nota completa (ganancia y partes) queda en el pedido; a la dependienta solo se le dice lo suyo (CVD_Staff_Privacy).
		if ( '' !== $extra_note ) { $message .= ' ' . ( user_can( $user_id, 'manage_woocommerce' ) ? $extra_note : self::extra_staff_message( $saved ) ); }
		if ( $messenger ) { $message .= ' El bot le avisa del cambio por WhatsApp a ' . $messenger['name'] . '.'; }
		return array( 'ok' => true, 'changes' => $changes, 'stock' => $stock, 'total' => $total, 'message' => $message, 'messenger' => $messenger );
	}

	public static function edit_request( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$data = array_intersect_key( $params, array_flip( array( 'firstName', 'lastName', 'phone', 'address', 'deliveryDate', 'deliveryWindow', 'note', 'lines', 'add', 'changeRequired' ) ) );
		$result = self::edit( absint( $request['id'] ), $data, get_current_user_id(), 'ventas' );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/** 3.14.18: «Extra en tienda apuntado a nombre de Katia: la gestora cobra su comisión normal.» */
	private static function extra_staff_message( WC_Order $order ): string {
		$staff = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$snap = $item->get_meta( '_cvd_commission_snapshot', true );
			$x = is_array( $snap ) && is_array( $snap['extra'] ?? null ) ? $snap['extra'] : null;
			if ( ! $x ) { continue; }
			$staff += (float) $x['staff'];
		}
		$who = (string) $order->get_meta( 'gestora_nombre', true );
		return 'Extra en tienda apuntado a nombre de ' . ( '' !== $who ? $who : 'la gestora' ) . ( $staff > 0 ? ': te tocan ' . number_format( $staff, 2, ',', '.' ) . ' USD de premio, se pagan un día fijo a la semana.' : '. La gestora cobra su comisión normal.' );
	}

	/** 3.14.18: la dependienta que atiende (quien pulsa, si es dependienta u operador); administración no cobra premio. */
	private static function staff_for_extra( int $user_id ): ?WP_User {
		$user = get_userdata( $user_id );
		if ( ! $user || ( class_exists( 'CVD_Panel_Bridge' ) && CVD_Panel_Bridge::via_panel() ) ) { return null; }
		return array_intersect( array( 'cvd_clerk', 'cvd_operator' ), (array) $user->roles ) ? $user : null;
	}

	/**
	 * 3.14.18: extras en tienda de los últimos $days días, una fila por producto. $staff_id > 0 = solo los de esa
	 * dependienta. Sin pedidos cancelados. closed = pedido completado (ya se puede pagar).
	 */
	public static function extras( int $staff_id = 0, int $days = 35 ): array {
		$orders = wc_get_orders( array( 'limit' => 300, 'meta_query' => array( array( 'key' => '_cvd_has_extra_tienda', 'value' => '1' ) ), 'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ), 'status' => array( 'pending', 'processing', 'on-hold', 'completed' ) ) );
		$rows = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$snap = $item->get_meta( '_cvd_commission_snapshot', true );
				$x = is_array( $snap ) && is_array( $snap['extra'] ?? null ) ? $snap['extra'] : null;
				if ( ! $x || ( $staff_id && absint( $x['staff_user_id'] ) !== $staff_id ) ) { continue; }
				$rows[] = array(
					'order' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m' ) : '',
					'name' => $item->get_name(), 'qty' => (int) $item->get_quantity(), 'profit' => null === $x['profit'] ? null : (float) $x['profit'],
					'staff' => (float) $x['staff'], 'staffName' => (string) $x['staff_name'], 'gestora' => null === $x['gestora'] ? null : (float) $x['gestora'],
					'gestoraName' => (string) $order->get_meta( 'gestora_nombre', true ), 'store' => null === $x['store'] ? null : (float) $x['store'],
					'closed' => $order->has_status( 'completed' ),
				);
			}
		}
		return $rows;
	}

	public static function extras_request( WP_REST_Request $request ) {
		$admin = current_user_can( 'manage_woocommerce' );
		$rows = self::extras( $admin ? 0 : get_current_user_id(), 35 );
		return rest_ensure_response( array( 'admin' => $admin, 'rows' => $rows, 'staffTotal' => round( array_sum( array_column( $rows, 'staff' ) ), 2 ) ) );
	}

	// ---------- Dividir (3.14.116, Ernesto 3-nov; caso #6062 → #6102) ----------

	/**
	 * Pasa líneas elegidas del pedido A a un pedido NUEVO B con otro cliente y otra dirección. Solo la tienda.
	 * - Dinero: cada línea pasa con SU precio y su comisión; A + B = el total de antes. Si pasa una línea entera, su
	 *   comisión se conserva tal cual; si pasa solo una parte, esa línea recalcula su comisión en los dos pedidos.
	 * - Existencias: lo que A ya había descontado vuelve a la tienda y B lo descuenta otra vez = la tienda queda igual.
	 *   Caja (NEXO): A deja su cola de cambios (+) y B sale como venta web normal (−) = la caja queda igual.
	 *   B lleva la fecha de venta de A: los resúmenes del día no cuentan esa venta dos veces.
	 * - Sin avisos al cliente: B se crea sin correo ni avisos; de A solo se apunta el cambio si es de gestora
	 *   (mensajero y gestora; al cliente de una gestora el bot nunca le escribe, ver CVD_Order_Avisos).
	 * - B empieza «Nuevo» y sin mensajero: sale al reparto cuando la tienda pulse «Listo», como cualquier pedido.
	 * $moves: [{itemId, qty}]. $to: firstName, lastName, phone, address, municipality, locality, deliveryDate, deliveryWindow, note.
	 * Devuelve array( ok, message, newOrderId, newNumber, totals, commission ) o WP_Error. Si algo no vale, nada cambia.
	 */
	public static function split( int $order_id, array $moves, array $to, int $user_id, string $request_id = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_split_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		if ( self::$gestora_actor ) { return new WP_Error( 'cvd_split_staff', 'Dividir un pedido lo hace la tienda.', array( 'status' => 403 ) ); }
		$request_id = substr( (string) preg_replace( '/[^a-zA-Z0-9-]/', '', $request_id ), 0, 64 );
		$done = $order->get_meta( '_cvd_split_requests', true );
		if ( '' !== $request_id && is_array( $done ) && isset( $done[ $request_id ] ) ) { // la misma orden repetida (doble toque): no se divide dos veces
			$child = wc_get_order( absint( $done[ $request_id ] ) );
			return array( 'ok' => true, 'replayed' => true, 'newOrderId' => $child ? $child->get_id() : 0, 'newNumber' => $child ? $child->get_order_number() : '', 'message' => 'Ya estaba dividido: el pedido nuevo es el #' . ( $child ? $child->get_order_number() : '' ) . '.' );
		}
		$block = self::edit_block( $order, $user_id );
		if ( '' !== $block ) { return new WP_Error( 'cvd_split_blocked', $block, array( 'status' => 409 ) ); }
		$why = self::split_block( $order );
		if ( '' !== $why ) { return new WP_Error( 'cvd_split_blocked', $why, array( 'status' => 409 ) ); }

		// 1. Validar todo antes de tocar nada.
		$current = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) { $current[ $item_id ] = (int) $item->get_quantity(); }
		$plan = self::split_plan( $current, $moves );
		if ( is_string( $plan ) ) { return new WP_Error( 'cvd_split_lines', $plan, array( 'status' => 422 ) ); }
		foreach ( $plan as $item_id => $qty ) {
			$item = $order->get_item( $item_id, false );
			if ( ! $item instanceof WC_Order_Item_Product || ! $item->get_product() ) { return new WP_Error( 'cvd_split_product', 'Un producto de este pedido ya no existe: no se puede dividir aquí.', array( 'status' => 409 ) ); }
			$reduced = (int) $item->get_meta( '_reduced_stock', true );
			if ( $reduced > 0 && $reduced !== (int) $item->get_quantity() ) { return new WP_Error( 'cvd_split_stock', 'Las existencias de «' . $item->get_name() . '» no cuadran en este pedido: no se divide. Habla con Lennys.', array( 'status' => 409 ) ); }
		}
		$pickup = self::is_pickup( $order );
		$test = ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) || '' !== (string) $order->get_meta( '_cvd_ensayo', true );
		$clean = static fn( string $key ): string => trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $to[ $key ] ?? '' ) ) ) );
		$first = mb_substr( $clean( 'firstName' ), 0, 60 );
		$last = mb_substr( $clean( 'lastName' ), 0, 60 );
		$phone = (string) preg_replace( '/[^0-9+ ]/', '', $clean( 'phone' ) );
		$address = mb_substr( $clean( 'address' ), 0, 200 );
		if ( '' === $first ) { return new WP_Error( 'cvd_split_name', 'Escribe el nombre del otro cliente.', array( 'status' => 422 ) ); }
		// Un pedido de ensayo nunca lleva teléfono (así no sale ningún WhatsApp); los reales lo necesitan para el mensajero.
		if ( $test ) { $phone = ''; }
		elseif ( ! $pickup && strlen( (string) preg_replace( '/\D/', '', $phone ) ) < 8 ) { return new WP_Error( 'cvd_split_phone', 'Escribe el teléfono del otro cliente (al menos 8 números).', array( 'status' => 422 ) ); }
		if ( ! $pickup && '' === $address ) { return new WP_Error( 'cvd_split_address', 'Escribe la dirección del otro cliente.', array( 'status' => 422 ) ); }
		$municipality = $clean( 'municipality' ) ?: (string) $order->get_billing_city();
		$locality = $clean( 'locality' ) ?: (string) $order->get_meta( '_cvd_locality', true );
		$date = ''; $window = '';
		if ( ! $pickup ) {
			$raw = trim( (string) ( $to['deliveryDate'] ?? '' ) );
			$date = '' === $raw ? '' : CVD_Delivery_When::normalize( $raw );
			if ( '' !== $raw && '' === $date ) { return new WP_Error( 'cvd_split_date', 'Elige un día de entrega entre hoy y dentro de ' . CVD_Delivery_When::MAX_DAYS . ' días (o déjalo vacío: lo antes posible).', array( 'status' => 422 ) ); }
			$window = sanitize_key( (string) ( $to['deliveryWindow'] ?? '' ) );
			if ( ! in_array( $window, self::WINDOWS, true ) ) { return new WP_Error( 'cvd_split_window', 'Elige mañana, tarde o cualquier hora.', array( 'status' => 422 ) ); }
		}
		$note = mb_substr( sanitize_textarea_field( (string) ( $to['note'] ?? '' ) ), 0, 500 );
		// Una sola división a la vez por pedido (dos dependientas pulsando a la vez).
		$lock = 'cvd_split_lock_' . $order_id;
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			if ( time() - (int) get_option( $lock ) < 60 ) { return new WP_Error( 'cvd_split_busy', 'Otra persona está dividiendo este pedido ahora mismo. Espera un momento y actualiza.', array( 'status' => 409 ) ); }
			update_option( $lock, time(), false );
		}
		try {
			return self::split_apply( $order, $plan, compact( 'first', 'last', 'phone', 'address', 'municipality', 'locality', 'date', 'window', 'note', 'pickup' ), $user_id, $request_id );
		} finally {
			delete_option( $lock );
		}
	}

	/** '' = se puede dividir (además de edit_block); si no, el motivo en palabras de la tienda. */
	public static function split_block( WC_Order $order ): string {
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		if ( $payment && ! empty( $payment['prepaid'] ) ) { return 'Este pedido se pagó por adelantado: no se divide aquí. Habla con Lennys.'; }
		if ( class_exists( 'CVD_Payment_Obligations' ) && CVD_Payment_Obligations::for_order( $order ) ) { return 'Este pedido tiene la mensajería repartida entre cliente y gestora: no se divide aquí. Habla con Lennys.'; }
		if ( class_exists( 'CVD_Reserva' ) && CVD_Reserva::active( $order ) ) { return 'Este pedido tiene una reserva: no se divide.'; }
		if ( (float) $order->get_total_refunded() > 0 || $order->get_meta( '_cvd_return', true ) ) { return 'Este pedido tiene una devolución o un reembolso: no se divide.'; }
		$commission = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) );
		if ( in_array( $commission, array( 'approved', 'paid' ), true ) || absint( $order->get_meta( '_cvd_payout_id', true ) ) ) { return 'La comisión de este pedido ya está aprobada o pagada: no se divide.'; }
		return '';
	}

	/**
	 * Cuenta pura (sin WordPress): $current [itemId => cantidad], $moves [{itemId, qty}] → [itemId => cantidad que pasa]
	 * o el motivo (texto) si no vale. El pedido de antes tiene que quedarse con algo.
	 */
	public static function split_plan( array $current, array $moves ) {
		$plan = array();
		foreach ( $moves as $m ) {
			$id = is_array( $m ) && is_numeric( $m['itemId'] ?? null ) ? (int) $m['itemId'] : 0;
			$qty = is_array( $m ) && is_numeric( $m['qty'] ?? null ) ? (int) $m['qty'] : 0;
			if ( $id <= 0 || $qty <= 0 ) { continue; }
			if ( ! isset( $current[ $id ] ) ) { return 'Uno de los productos elegidos no está en este pedido.'; }
			$plan[ $id ] = ( $plan[ $id ] ?? 0 ) + $qty;
			if ( $plan[ $id ] > $current[ $id ] ) { return 'No puedes pasar más unidades de las que tiene el pedido.'; }
		}
		if ( ! $plan ) { return 'Elige qué productos pasan al pedido nuevo.'; }
		if ( array_sum( $current ) - array_sum( $plan ) < 1 ) { return 'El pedido de antes no puede quedar vacío. Si todo es para el otro cliente, cambia los datos del cliente con «Cambiar pedido».'; }
		return $plan;
	}

	/** Metadatos del pedido A que pasan a B: quién vendió y con qué precios, forma de pago y marcas de prueba. */
	private const SPLIT_COPY_META = array(
		'_cvd_owner_user_id', '_cvd_owner_type', '_cvd_owner_display_name', '_cvd_pricing_gestora_user_id', '_cvd_pricing_snapshot_at',
		'_cvd_referral_code', '_cvd_referred_at', '_cvd_attribution_locked', '_cvd_attribution_source', '_cvd_commission_risk',
		'gestora_id', 'gestora_nombre', 'gestora_codigo', 'referido', 'referido_fecha',
		'_cvd_fulfillment_type', '_cvd_payment_kind', '_cvd_payment_factor', '_cvd_payment_currency', '_cvd_payment_estimated', '_cvd_cup_rate', '_cvd_cup_rate_at',
		'_nexo_marketplace_order', '_nexo_marketplace_version', '_cvd_test_order', '_cvd_ensayo',
	);
	/** En la línea que pasa NO se copia lo descontado (lo pone B al descontar) ni las secuencias de existencias. */
	private const SPLIT_SKIP_ITEM_META = array( '_reduced_stock', '_restock_refunded_items', '_cvd_stock_reduction_sequence', '_cvd_stock_restore_sequence' );

	private static function split_apply( WC_Order $order, array $plan, array $to, int $user_id, string $request_id ) {
		require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php'; // wc_maybe_adjust_line_item_product_stock
		$before_total = (float) $order->get_total();
		$before_commission = (float) $order->get_meta( '_cvd_commission_amount', true );
		$snapshot = class_exists( 'CVD_Order_Avisos' ) ? CVD_Order_Avisos::snapshot( $order ) : array();
		$messenger = self::messenger_to_warn( $order );
		// Sin correos: ni al cliente nuevo ni el de «pedido nuevo» (la tienda es quien lo está creando).
		$no_mail = static fn(): bool => false;
		$mails = array( 'woocommerce_email_enabled_new_order', 'woocommerce_email_enabled_customer_on_hold_order', 'woocommerce_email_enabled_customer_processing_order', 'woocommerce_email_enabled_customer_pending_order' );
		foreach ( $mails as $hook ) { add_filter( $hook, $no_mail, 999 ); }
		$child = null;
		try {
			// 2. Pedido nuevo B (pendiente mientras se arma; luego el mismo estado que A).
			$child = new WC_Order();
			$child->set_created_via( 'casa-viva-split' );
			$child->set_currency( $order->get_currency() );
			$child->set_prices_include_tax( $order->get_prices_include_tax() );
			$child->set_payment_method( $order->get_payment_method() );
			$child->set_payment_method_title( $order->get_payment_method_title() );
			$child->set_billing_first_name( $to['first'] );
			$child->set_billing_last_name( $to['last'] );
			$child->set_billing_phone( $to['phone'] );
			$child->set_billing_country( $order->get_billing_country() ?: 'CU' );
			$child->set_billing_city( $to['municipality'] );
			if ( ! $to['pickup'] ) {
				$child->set_billing_address_1( $to['address'] ); $child->set_shipping_address_1( $to['address'] );
				$child->set_shipping_city( $to['municipality'] ); $child->set_shipping_country( $order->get_shipping_country() ?: 'CU' );
			}
			if ( '' !== $to['note'] ) { $child->set_customer_note( $to['note'] ); }
			if ( $order->get_date_created() ) { $child->set_date_created( $order->get_date_created()->getTimestamp() ); }
			foreach ( self::SPLIT_COPY_META as $key ) {
				$value = $order->get_meta( $key, true );
				if ( '' !== $value && null !== $value ) { $child->update_meta_data( $key, $value ); }
			}
			$child->update_meta_data( '_cvd_split_from', $order->get_id() );
			$child->update_meta_data( '_cvd_split_by', $user_id );
			$child->update_meta_data( '_cvd_staff_new_order_notified', current_time( 'mysql', true ) ); // la tienda ya lo sabe: sin aviso de «pedido nuevo»
			if ( ! $to['pickup'] ) {
				$child->update_meta_data( '_cvd_locality', $to['locality'] );
				$child->update_meta_data( '_cvd_delivery_date', $to['date'] );
				$child->update_meta_data( '_cvd_delivery_window', $to['window'] );
				$quote = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::quote( $to['municipality'], $to['locality'] ) : array();
				$child->update_meta_data( '_cvd_shipping_fee_cup', absint( $quote['fee'] ?? 0 ) );
				$child->update_meta_data( '_cvd_shipping_rate_status', sanitize_key( (string) ( $quote['status'] ?? 'pending' ) ) );
				$child->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( (string) ( $quote['label'] ?? '' ) ) );
			}
			$child->save();
			if ( ! $child->get_id() ) { throw new RuntimeException( 'No se pudo crear el pedido nuevo.' ); }

			// 3. Las líneas: a B con su precio y sus datos; A se queda con lo demás.
			$moved = array(); $caja = array(); $reduce = array();
			foreach ( $plan as $item_id => $qty ) {
				$item = $order->get_item( $item_id, false );
				$all = (int) $item->get_quantity() === $qty;
				$unit_sub = (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() );
				$unit_tot = (float) $item->get_total() / max( 1, (int) $item->get_quantity() );
				$copy = new WC_Order_Item_Product();
				$copy->set_props( array(
					'name' => $item->get_name(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(),
					'quantity' => $qty, 'tax_class' => $item->get_tax_class(),
					'subtotal' => $all ? $item->get_subtotal() : $unit_sub * $qty, 'total' => $all ? $item->get_total() : $unit_tot * $qty,
				) );
				foreach ( $item->get_meta_data() as $meta ) {
					if ( in_array( $meta->key, self::SPLIT_SKIP_ITEM_META, true ) ) { continue; }
					if ( '_cvd_commission_snapshot' === $meta->key && ! $all ) { continue; } // parte de una línea: su comisión se recalcula
					$copy->add_meta_data( $meta->key, $meta->value, false );
				}
				$child->add_item( $copy );
				$reduced = (int) $item->get_meta( '_reduced_stock', true );
				if ( $reduced > 0 ) { $reduce[] = $copy; $caja[] = array( 'itemId' => $item->get_id(), 'wooId' => $item->get_variation_id() ?: $item->get_product_id(), 'delta' => $qty ); }
				$moved[] = $qty . '× ' . $item->get_name() . ' (' . wc_format_decimal( $all ? (float) $item->get_total() : $unit_tot * $qty, 2 ) . ' ' . $order->get_currency() . ')';
				// A: quitar la línea o bajar su cantidad, devolviendo a la tienda lo que pasa a B (igual que edit()).
				if ( $all ) {
					wc_maybe_adjust_line_item_product_stock( $item, 0 );
					$order->remove_item( $item->get_id() );
				} else {
					$left = (int) $item->get_quantity() - $qty;
					$item->set_quantity( $left ); $item->set_subtotal( $unit_sub * $left ); $item->set_total( $unit_tot * $left );
					$item->delete_meta_data( '_cvd_commission_snapshot' );
					$item->save();
					wc_maybe_adjust_line_item_product_stock( $item );
				}
			}
			$child->calculate_totals( false );
			$child->save();
			// B descuenta lo que A devolvió (y queda marcado como descontado: WooCommerce no lo vuelve a descontar al cambiar de estado).
			foreach ( $reduce as $copy ) { wc_maybe_adjust_line_item_product_stock( $copy ); }
			$status = $order->get_status();
			if ( 'pending' !== $status ) { $child = wc_get_order( $child->get_id() ); $child->set_status( $status ); $child->save(); }
			if ( class_exists( 'CVD_Delivery' ) && ! $to['pickup'] ) { CVD_Delivery::initialize_order( wc_get_order( $child->get_id() ) ); }
			if ( class_exists( 'CVD_Sales' ) ) { CVD_Sales::initialize_order( wc_get_order( $child->get_id() ) ); }

			// 4. A: totales, cola para la caja, enlace con B.
			$order->calculate_totals( false );
			if ( $caja ) { self::queue_caja_edits( $order, $caja ); }
			$children = $order->get_meta( '_cvd_split_children', true );
			$children = is_array( $children ) ? $children : array();
			$children[] = $child->get_id();
			$order->update_meta_data( '_cvd_split_children', array_values( array_unique( array_map( 'absint', $children ) ) ) );
			if ( '' !== $request_id ) {
				$done = $order->get_meta( '_cvd_split_requests', true );
				$done = is_array( $done ) ? $done : array();
				$done[ $request_id ] = $child->get_id();
				$order->update_meta_data( '_cvd_split_requests', array_slice( $done, -20, null, true ) );
			}
			$order->save();
		} catch ( Throwable $error ) {
			foreach ( $mails as $hook ) { remove_filter( $hook, $no_mail, 999 ); }
			return new WP_Error( 'cvd_split_failed', 'No se pudo dividir el pedido: ' . $error->getMessage() . ' Revisa los dos pedidos con Lennys.', array( 'status' => 500 ) );
		}
		foreach ( $mails as $hook ) { remove_filter( $hook, $no_mail, 999 ); }
		if ( class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_pending( $order->get_id() ); CVD_Commissions::mark_pending( $child->get_id() ); }
		$a = wc_get_order( $order->get_id() );
		$b = wc_get_order( $child->get_id() );
		$cur = $a->get_currency();
		$money = static fn( float $v ): string => wc_format_decimal( $v, 2 );
		$who = self::who( $user_id );
		$b_name = trim( $b->get_billing_first_name() . ' ' . $b->get_billing_last_name() );
		$a->add_order_note( sprintf( 'Pedido DIVIDIDO por %s: pasaron al pedido nuevo #%s (para %s%s): %s. Este pedido: %s → %s %s. Los dos juntos: %s %s, igual que antes. Sin aviso al cliente.%s',
			$who, $b->get_order_number(), $b_name, $to['pickup'] || '' === $to['locality'] ? '' : ', ' . $to['locality'], implode( '; ', $moved ), $money( $before_total ), $money( (float) $a->get_total() ), $cur,
			$money( (float) $a->get_total() + (float) $b->get_total() ), $cur, $messenger ? ' Ya lo tenía asignado ' . $messenger['name'] . ': hay que avisarle.' : '' ) );
		$a->save();
		$b->add_order_note( sprintf( 'Pedido creado al DIVIDIR el #%s (lo hizo %s): %s. Mismo vendedor, mismos precios y misma forma de pago; la comisión va por línea. Sale al reparto cuando la tienda pulse «Listo».', $a->get_order_number(), $who, implode( '; ', $moved ) ) );
		$b->save();
		if ( class_exists( 'CVD_Order_Avisos' ) && self::is_gestora_order( $a ) ) { CVD_Order_Avisos::record_edit( wc_get_order( $a->get_id() ), $snapshot, $user_id, 'split', $messenger ); }
		$a = wc_get_order( $a->get_id() );
		$b = wc_get_order( $b->get_id() );
		$after_commission = (float) $a->get_meta( '_cvd_commission_amount', true ) + (float) $b->get_meta( '_cvd_commission_amount', true );
		$message = 'Listo: el pedido #' . $a->get_order_number() . ' queda en ' . $money( (float) $a->get_total() ) . ' ' . $cur . ' y el nuevo #' . $b->get_order_number() . ' (' . $b_name . ') en ' . $money( (float) $b->get_total() ) . ' ' . $cur . '. Juntos: ' . $money( (float) $a->get_total() + (float) $b->get_total() ) . ' ' . $cur . ', igual que antes. El nuevo sale al reparto cuando pulses «Listo».';
		if ( $messenger ) { $message .= ' El #' . $a->get_order_number() . ' ya lo tenía ' . $messenger['name'] . ( self::is_gestora_order( $a ) ? ': el bot le avisa del cambio.' : ': avísale tú del cambio.' ); }
		return array(
			'ok' => true, 'message' => $message, 'newOrderId' => $b->get_id(), 'newNumber' => $b->get_order_number(),
			'totals' => array( 'before' => $before_total, 'order' => (float) $a->get_total(), 'newOrder' => (float) $b->get_total() ),
			// La comisión solo la ve administración (a las dependientas no se les enseña: CVD_Staff_Privacy).
			'commission' => user_can( $user_id, 'manage_woocommerce' ) ? array( 'before' => $before_commission, 'after' => round( $after_commission, 4 ) ) : null,
		);
	}

	public static function split_request( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$result = self::split( absint( $request['id'] ), (array) ( $params['lines'] ?? array() ), (array) ( $params['to'] ?? array() ), get_current_user_id(), (string) ( $params['requestId'] ?? '' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	// ---------- Precio de la gestora ----------

	/** Gestora cuyos precios lleva el pedido: la de su enlace (_cvd_pricing_gestora_user_id) o, si no, la dueña gestora. */
	public static function pricing_gestora( WC_Order $order ): int {
		$id = absint( $order->get_meta( '_cvd_pricing_gestora_user_id', true ) );
		if ( $id ) { return $id; }
		return 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? absint( $order->get_meta( '_cvd_owner_user_id', true ) ) : 0;
	}

	/**
	 * Precio por unidad de un producto que se añade al pedido: el de la gestora (CVD_Gestora_Store::resolve_price, lo
	 * mismo que ve su cliente en su enlace) en pedidos de gestora; el de Casa Viva en los demás.
	 * → [ 'base' => precio Casa Viva, 'unit' => precio que se cobra, 'gestora' => id o 0 ].
	 */
	public static function unit_price_for( WC_Order $order, WC_Product $product ): array {
		$base = (float) get_post_meta( $product->get_id(), '_price', true );
		$gestora = self::pricing_gestora( $order );
		if ( ! $gestora || $base <= 0 || ! class_exists( 'CVD_Gestora_Store' ) ) { return array( 'base' => $base, 'unit' => $base, 'gestora' => 0 ); }
		return array( 'base' => $base, 'unit' => (float) CVD_Gestora_Store::resolve_price( $product, $gestora, $base ), 'gestora' => $gestora );
	}

	// ---------- Caja (NEXO) ----------

	/** Apunta los movimientos de caja pendientes de este cambio (los lee el puente en panel/stock/web-sales). */
	private static function queue_caja_edits( WC_Order $order, array $stock ): void {
		$list = $order->get_meta( self::CAJA_EDITS_META, true );
		$list = is_array( $list ) ? $list : array();
		$stamp = gmdate( 'YmdHis' ) . substr( wp_generate_uuid4(), 0, 6 );
		foreach ( $stock as $s ) {
			if ( empty( $s['delta'] ) ) { continue; }
			$key = 'web:' . $order->get_id() . ':' . absint( $s['itemId'] ) . ':edit:' . $stamp;
			$list[ $key ] = array( 'key' => $key, 'itemId' => absint( $s['itemId'] ), 'wooId' => absint( $s['wooId'] ), 'delta' => (int) $s['delta'], 'at' => current_time( 'mysql', true ), 'done' => '' );
		}
		$order->update_meta_data( self::CAJA_EDITS_META, array_slice( $list, -50, null, true ) );
	}

	/** Pendientes para el puente: [{key, itemId, wooId, delta}]. */
	public static function pending_caja_edits( WC_Order $order ): array {
		$list = $order->get_meta( self::CAJA_EDITS_META, true );
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( is_array( $row ) && '' === (string) ( $row['done'] ?? '' ) ) { $out[] = array( 'key' => (string) $row['key'], 'itemId' => absint( $row['itemId'] ), 'wooId' => absint( $row['wooId'] ), 'delta' => (int) $row['delta'] ); }
		}
		return $out;
	}

	/** El puente marca cada movimiento como aplicado o descartado: [{order, key, result}]. */
	public static function mark_caja_edits( array $items ): array {
		$by_order = array(); $done = 0;
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) { continue; }
			$by_order[ absint( $it['order'] ?? 0 ) ][ (string) ( $it['key'] ?? '' ) ] = 'skipped' === ( $it['result'] ?? '' ) ? 'skipped' : 'applied';
		}
		foreach ( $by_order as $order_id => $keys ) {
			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof WC_Order ) { continue; }
			$list = $order->get_meta( self::CAJA_EDITS_META, true );
			if ( ! is_array( $list ) ) { continue; }
			foreach ( $keys as $key => $result ) {
				if ( isset( $list[ $key ] ) && '' === (string) ( $list[ $key ]['done'] ?? '' ) ) { $list[ $key ]['done'] = $result; $list[ $key ]['doneAt'] = current_time( 'mysql', true ); $done++; }
			}
			$order->update_meta_data( self::CAJA_EDITS_META, $list );
			$order->save();
		}
		return array( 'marked' => $done );
	}

	// ---------- Buscar productos para añadir ----------

	/** Hasta 10 productos (o colores/diseños) a la venta que coinciden con ?q= (nombre, SKU o número). */
	public static function products_request( WP_REST_Request $request ) {
		$q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
		if ( mb_strlen( $q ) < 2 ) { return rest_ensure_response( array( 'products' => array() ) ); }
		// 3.13.98: con ?order=, el precio que tendrá en ESE pedido (el de la gestora si es suyo).
		$for = absint( $request->get_param( 'order' ) ) ? wc_get_order( absint( $request->get_param( 'order' ) ) ) : null;
		$for = $for instanceof WC_Order && 'shop_order' === $for->get_type() ? $for : null;
		$ids = array();
		if ( ctype_digit( $q ) ) { $ids[] = absint( $q ); }
		$sku = wc_get_product_id_by_sku( $q );
		if ( $sku ) { $ids[] = $sku; }
		$query = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 10, 'fields' => 'ids', 'no_found_rows' => true ) );
		$ids = array_unique( array_merge( $ids, array_map( 'absint', $query->posts ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'publish' !== get_post_status( $product->get_parent_id() ?: $product->get_id() ) ) { continue; }
			$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $items as $p ) {
				if ( ! $p->is_purchasable() || 'publish' !== $p->get_status() ) { continue; }
				$price = $for ? self::unit_price_for( $for, $p ) : null;
				$out[] = array( 'wooId' => $p->get_id(), 'name' => $p->get_name(), 'price' => $price ? round( $price['unit'], 2 ) : (float) $p->get_price(), 'gestoraPrice' => $price && $price['gestora'] ? true : false, 'stock' => $p->managing_stock() ? (int) $p->get_stock_quantity() : null, 'inStock' => $p->is_in_stock() );
				if ( count( $out ) >= 10 ) { break 2; }
			}
		}
		return rest_ensure_response( array( 'products' => $out ) );
	}
}
