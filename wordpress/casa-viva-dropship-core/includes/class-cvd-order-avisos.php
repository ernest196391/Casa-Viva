<?php

defined( 'ABSPATH' ) || exit;

/**
 * Avisos de WhatsApp al CANCELAR o CAMBIAR un pedido (Core 3.13.98, Ernesto 14-oct).
 *
 * Core apunta cada cancelación y cada cambio como un «evento» dentro del pedido (meta _cvd_bot_avisos) y VivaBot los
 * recoge con GET /bot/order-avisos, escribe a quien toca y confirma con POST /bot/order-avisos/{pedido}/done.
 * Un evento se avisa una sola vez aunque el bot reinicie (queda pendiente hasta el «done»).
 * - A quién (lo decide el bot con estos datos): mensajero asignado (si lo hay) y gestora del pedido; al cliente
 *   solo si es venta directa (sin gestora). En pedidos de gestora nunca se escribe a su cliente.
 * - Pedidos de PRUEBA: el evento se apunta con test=true y el bot no escribe a nadie.
 * - Se apunta desde CVD_Order_Edit (/ventas/ y el panel) y desde una cancelación a mano en el administrador de
 *   WooCommerce. Las cancelaciones automáticas (sin persona) no avisan.
 */
final class CVD_Order_Avisos {
	public const META = '_cvd_bot_avisos';
	public const PENDING_META = '_cvd_bot_aviso_pending';
	private const NS = 'casa-viva/v1';
	/** Mientras CVD_Order_Edit cancela, el gancho de WooCommerce no apunta otro evento. */
	private static bool $manual = false;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Antes que CVD_Delivery::sync_cancelled (40): todavía se ve qué mensajero lo tenía.
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'from_admin_cancel' ), 5 );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/order-avisos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pending_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/order-avisos/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'done_request' ), 'permission_callback' => $auth ) );
	}

	public static function begin_manual(): void { self::$manual = true; }
	public static function end_manual(): void { self::$manual = false; }

	// ---------- Foto del pedido antes de tocarlo ----------

	/** Lo que se compara al cambiar: dirección, día y franja, teléfono, productos, total y forma de pago. */
	public static function snapshot( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) { $items[] = $item->get_quantity() . ' × ' . $item->get_name(); }
		$when = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::for_order( $order ) : array( 'label' => '' );
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		return array(
			'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'   => preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ),
			'address' => trim( (string) ( $order->get_billing_address_1() ?: $order->get_shipping_address_1() ) ),
			'when'    => 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ? '' : (string) ( $when['label'] ?? '' ),
			'note'    => trim( wp_strip_all_tags( (string) $order->get_customer_note() ) ),
			'items'   => implode( ', ', $items ),
			'total'   => round( (float) $order->get_total(), 2 ),
			'payment' => $payment ? (string) $payment['text'] : '',
		);
	}

	// ---------- Apuntar eventos ----------

	/** Cancelación con motivo (CVD_Order_Edit::cancel). $messenger = el que lo tenía antes de cancelar. */
	public static function record_cancel( WC_Order $order, array $before, string $reason, int $user_id, string $source, ?array $messenger, string $stage ): void {
		self::add_event( $order, array(
			'type' => 'cancel', 'reason' => $reason, 'by' => $user_id, 'source' => $source, 'stage' => $stage,
			'messenger' => $messenger ? $messenger + array( 'hasPackage' => in_array( $stage, array( 'picked_up', 'handed_over' ), true ) ) : null,
			'before' => $before,
		) );
	}

	/** Cambio (CVD_Order_Edit::edit): solo lo que de verdad cambió. Nada que avisar → no se apunta. */
	public static function record_edit( WC_Order $order, array $before, int $user_id, string $source, ?array $messenger ): void {
		$after = self::snapshot( $order );
		$labels = array( 'address' => 'Dirección', 'when' => 'Día de entrega', 'phone' => 'Teléfono del cliente', 'name' => 'Nombre del cliente', 'items' => 'Productos', 'note' => 'Nota' );
		$changes = array();
		foreach ( $labels as $field => $label ) {
			if ( (string) ( $before[ $field ] ?? '' ) !== (string) $after[ $field ] ) {
				$changes[] = array( 'field' => $field, 'label' => $label, 'from' => (string) ( $before[ $field ] ?? '' ), 'to' => (string) $after[ $field ] );
			}
		}
		$money = abs( (float) ( $before['total'] ?? 0 ) - $after['total'] ) >= 0.01;
		if ( ! $changes && ! $money ) { return; }
		self::add_event( $order, array(
			'type' => 'edit', 'by' => $user_id, 'source' => $source, 'changes' => $changes,
			'oldTotal' => (float) ( $before['total'] ?? 0 ), 'totalChanged' => $money, 'oldPayment' => (string) ( $before['payment'] ?? '' ),
			'messenger' => $messenger ? $messenger + array( 'hasPackage' => false ) : null,
		) );
	}

	/** Cancelación a mano desde el administrador de WooCommerce (no por /ventas/, el panel ni el sistema). */
	public static function from_admin_cancel( int $order_id ): void {
		if ( self::$manual || ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) { return; }
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return; }
		$stage = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned';
		if ( in_array( $stage, array( 'delivered', 'cash_returned', 'closed', 'cancelled' ), true ) ) { return; }
		$messenger = CVD_Order_Edit::messenger_to_warn( $order );
		self::add_event( $order, array(
			'type' => 'cancel', 'reason' => '', 'by' => get_current_user_id(), 'source' => 'woocommerce', 'stage' => $stage,
			'messenger' => $messenger ? $messenger + array( 'hasPackage' => in_array( $stage, array( 'picked_up', 'handed_over' ), true ) ) : null,
			'before' => self::snapshot( $order ),
		) );
	}

	private static function add_event( WC_Order $order, array $event ): void {
		$list = $order->get_meta( self::META, true );
		$list = is_array( $list ) ? $list : array();
		$event['id'] = substr( $event['type'], 0, 1 ) . gmdate( 'ymdHis' ) . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 6 );
		$event['at'] = current_time( 'mysql', true );
		$event['test'] = class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) );
		$event['notified'] = '';
		$list[] = $event;
		$order->update_meta_data( self::META, array_slice( $list, -20 ) );
		$order->update_meta_data( self::PENDING_META, '1' );
		$order->save();
	}

	// ---------- Para el bot ----------

	/** Eventos sin avisar (como mucho de los últimos 3 días), con todo lo que necesita el bot para escribir. */
	public static function pending_request(): WP_REST_Response {
		$orders = wc_get_orders( array( 'limit' => 50, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC', 'status' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ), 'meta_key' => self::PENDING_META, 'meta_value' => '1' ) );
		$floor = time() - 3 * DAY_IN_SECONDS;
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			foreach ( (array) $order->get_meta( self::META, true ) as $event ) {
				if ( ! is_array( $event ) || '' !== (string) ( $event['notified'] ?? '' ) ) { continue; }
				if ( strtotime( (string) $event['at'] . ' UTC' ) < $floor ) { continue; } // viejo: ya no se avisa
				$out[] = self::payload( $order, $event );
			}
		}
		$response = rest_ensure_response( array( 'avisos' => $out ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** El evento con los datos de hoy del pedido (teléfonos, total, forma de pago, día). */
	public static function payload( WC_Order $order, array $event ): array {
		$gestora_id = 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? absint( $order->get_meta( '_cvd_owner_user_id', true ) ) : 0;
		$gestora = $gestora_id ? get_userdata( $gestora_id ) : null;
		$customer_phone = (string) $order->get_meta( '_cvd_whatsapp_verified', true ) ?: ( $order->get_shipping_phone() ?: $order->get_billing_phone() );
		$when = class_exists( 'CVD_Delivery_When' ) ? CVD_Delivery_When::for_order( $order ) : array( 'date' => '', 'label' => '' );
		$today = wp_date( 'Y-m-d' );
		$created = $order->get_date_created();
		// «Para hoy»: el día elegido es hoy, o «lo antes posible» de un pedido hecho hoy.
		$for_today = $today === (string) $when['date'] || ( '' === (string) $when['date'] && $created && $today === wp_date( 'Y-m-d', $created->getTimestamp() ) );
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		$after = self::snapshot( $order );
		$by = get_userdata( absint( $event['by'] ?? 0 ) );
		return array(
			'event'     => (string) $event['id'],
			'type'      => (string) $event['type'],
			'at'        => (string) $event['at'],
			'test'      => ! empty( $event['test'] ) || ( class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) ) ),
			'orderId'   => $order->get_id(),
			'number'    => $order->get_order_number(),
			'status'    => $order->get_status(),
			'pickup'    => 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ),
			'zone'      => class_exists( 'CVD_Delivery' ) ? (string) CVD_Delivery::destination_zone( $order ) : '',
			'forToday'  => $for_today,
			'when'      => (string) ( $when['label'] ?? '' ),
			'reason'    => (string) ( $event['reason'] ?? '' ),
			'by'        => $by ? $by->display_name : '',
			'source'    => (string) ( $event['source'] ?? '' ),
			'customer'  => array( 'first' => trim( (string) $order->get_billing_first_name() ), 'phone' => preg_replace( '/\D+/', '', (string) $customer_phone ) ),
			'gestora'   => $gestora_id ? array( 'id' => $gestora_id, 'name' => $gestora ? $gestora->display_name : (string) $order->get_meta( '_cvd_owner_display_name', true ), 'phone' => class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $gestora_id ) : '' ) : null,
			'messenger' => is_array( $event['messenger'] ?? null ) ? $event['messenger'] : null,
			'changes'   => (array) ( $event['changes'] ?? array() ),
			'items'     => 'cancel' === $event['type'] ? (string) ( $event['before']['items'] ?? $after['items'] ) : $after['items'],
			'total'     => $after['total'],
			'oldTotal'  => isset( $event['oldTotal'] ) ? (float) $event['oldTotal'] : null,
			'totalChanged' => ! empty( $event['totalChanged'] ),
			'currency'  => $order->get_currency(),
			'payment'   => $payment ? (string) $payment['text'] : '',
			'prepaid'   => $payment ? ! empty( $payment['prepaid'] ) : false,
			'commissionText' => $gestora_id && class_exists( 'CVD_Commissions' ) ? (string) CVD_Commissions::commission_text( $order ) : '',
		);
	}

	/** El bot ya avisó (o lo saltó: prueba, viejo, sin destinatarios). {event, result: sent|skipped, to: [...]} */
	public static function done_request( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$params = (array) $request->get_json_params();
		$id = sanitize_text_field( (string) ( $params['event'] ?? '' ) );
		$result = 'skipped' === ( $params['result'] ?? '' ) ? 'skipped' : 'sent';
		$to = array_slice( array_map( 'sanitize_text_field', (array) ( $params['to'] ?? array() ) ), 0, 5 );
		$list = $order->get_meta( self::META, true );
		$list = is_array( $list ) ? $list : array();
		$found = false; $already = false; $pending = false;
		foreach ( $list as $i => $event ) {
			if ( ! is_array( $event ) ) { continue; }
			if ( (string) ( $event['id'] ?? '' ) === $id ) {
				$found = true;
				if ( '' !== (string) ( $event['notified'] ?? '' ) ) { $already = true; continue; }
				$list[ $i ]['notified'] = current_time( 'mysql', true );
				$list[ $i ]['result'] = $result;
				$list[ $i ]['to'] = $to;
			} elseif ( '' === (string) ( $event['notified'] ?? '' ) ) {
				$pending = true;
			}
		}
		if ( ! $found ) { return new WP_Error( 'cvd_aviso_not_found', 'Ese aviso no existe en el pedido.', array( 'status' => 404 ) ); }
		if ( ! $already ) {
			$order->update_meta_data( self::META, $list );
			if ( $pending ) { $order->update_meta_data( self::PENDING_META, '1' ); } else { $order->delete_meta_data( self::PENDING_META ); }
			$what = 'c' === substr( $id, 0, 1 ) ? 'de la cancelación' : 'del cambio';
			$order->add_order_note( 'sent' === $result
				? 'VivaBot avisó por WhatsApp ' . $what . ( $to ? ' a: ' . implode( ', ', $to ) : '' ) . '.'
				: 'VivaBot no avisó ' . $what . ( $to ? ' (' . implode( ', ', $to ) . ')' : '' ) . '.' );
			$order->save();
		}
		$response = rest_ensure_response( array( 'ok' => true, 'already' => $already ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
