<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.42 (Ernesto 20-oct, auditoría de la caja): la caja NEXO cobra los pedidos web.
 *
 * La caja (teléfonos de las dependientas) no habla con WordPress: lo hace la función
 * nexo-device-web-orders de NEXO con la clave del panel (X-Nexo-Panel-Key, la misma de
 * CVD_Panel_Bridge), después de comprobar la clave del equipo. Solo lectura y cobro:
 *
 *  GET  /panel/caja/web-orders                 → pedidos que se pueden cobrar en el mostrador:
 *                                                 recogida «Listo para recoger» y a domicilio con el
 *                                                 paquete en manos del mensajero o entregado (dinero pendiente).
 *  POST /panel/caja/web-orders/{id}/charge     → «Dinero recibido» con las MISMAS reglas de /ventas/
 *                                                 (CVD_Sales::change_status), firmado por la dependienta de
 *                                                 «Atiende» (si se encuentra su usuaria; si no, la del panel).
 *  POST /panel/caja/web-orders/{id}/change     → poner o cambiar el vuelto que lleva el mensajero (Lennys 20-oct).
 *  GET  /panel/caja/cash-collections?from=ISO  → efectivo recibido en Ventas desde esa hora que NO pasó por la
 *                                                 caja (para el «debería haber» del cajón al cerrar).
 *
 * Contra cobrar dos veces: un pedido completado (ya cobrado en Ventas) se rechaza con 409
 * cvd_already_charged; un reintento del mismo cobro de la caja (chargeId) devuelve «hecho» sin repetir.
 * Fuente única del efectivo de los mensajeros: el cierre «Dinero recibido» de Core (CVD_Messenger_Cash::held);
 * el «Recibido» de Dinero por entregar es solo constancia y no se suma.
 */
final class CVD_Caja_Bridge {
	private const NS = 'casa-viva/v1';
	public const CHARGE_META = '_cvd_caja_charge';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( self::NS, '/panel/caja/web-orders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'web_orders' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/caja/web-orders/(?P<id>\d+)/charge', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'charge' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/caja/web-orders/(?P<id>\d+)/change', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'change' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/caja/cash-collections', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'cash_collections' ), 'permission_callback' => $auth ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** '' = no se puede cobrar en el mostrador; si no, 'pickup' o 'delivery'. */
	public static function chargeable( WC_Order $order ): string {
		if ( ! $order->has_status( array( 'pending', 'processing', 'on-hold' ) ) ) { return ''; }
		if ( 'yes' === $order->get_meta( '_cvd_operation_incident_active', true ) ) { return ''; }
		$pickup = 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		if ( $pickup ) {
			return 'ready' === sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ? 'pickup' : '';
		}
		$delivery = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::status( $order ) : '';
		return in_array( $delivery, array( 'picked_up', 'handed_over', 'delivered' ), true ) ? 'delivery' : '';
	}

	/** A domicilio con mensajero asignado pero aún sin cobrar: sale en la lista para darle el vuelto (no para cobrar). */
	public static function outgoing( WC_Order $order ): bool {
		if ( ! $order->has_status( array( 'pending', 'processing', 'on-hold' ) ) ) { return false; }
		if ( 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) || ! class_exists( 'CVD_Delivery' ) ) { return false; }
		return in_array( CVD_Delivery::status( $order ), array( 'assigned', 'accepted', 'to_store' ), true );
	}

	private static function row( WC_Order $order, string $kind ): array {
		$products = array();
		$supplier = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			$sku = $product ? (string) $product->get_sku() : '';
			if ( preg_match( '/^MK-/i', $sku ) ) { $supplier = true; }
			$products[] = array( 'name' => $item->get_name(), 'qty' => (int) $item->get_quantity(), 'sku' => $sku );
		}
		$delivery = 'pickup' !== $kind && class_exists( 'CVD_Delivery' ) ? CVD_Delivery::status( $order ) : '';
		$messenger = 'pickup' !== $kind ? get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) : false;
		return array(
			'id' => $order->get_id(),
			'number' => $order->get_order_number(),
			'date' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'kind' => $kind,
			'statusLabel' => 'pickup' === $kind ? 'Listo para recoger' : ( 'outgoing' === $kind ? 'Por salir con el mensajero' : ( 'delivered' === $delivery ? 'Entregado · efectivo pendiente' : 'Con el mensajero' ) ),
			'chargeable' => 'outgoing' !== $kind,
			// 3.14.42: vuelto que lleva el mensajero [{amount, currency}] (sale del fondo y vuelve con el cobro).
			'change' => class_exists( 'CVD_Order_Edit' ) ? CVD_Order_Edit::change_of( $order ) : array(),
			'customer' => $order->get_formatted_billing_full_name(),
			'phone' => preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() ),
			'gestora' => (string) $order->get_meta( 'gestora_nombre', true ),
			'messenger' => $messenger ? $messenger->display_name : '',
			'totalUsd' => round( (float) $order->get_total(), 2 ),
			'payment' => class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null,
			'prepaid' => class_exists( 'CVD_Cuban_Checkout' ) && CVD_Cuban_Checkout::is_prepaid( $order ),
			'declaredUsd' => (string) $order->get_meta( '_cvd_declared_usd', true ),
			'declaredCup' => (string) $order->get_meta( '_cvd_declared_cup', true ),
			'products' => $products,
			'supplier' => $supplier,
			'test' => class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ),
		);
	}

	public static function web_orders(): WP_REST_Response {
		$rows = array();
		foreach ( wc_get_orders( array( 'limit' => 200, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => array( 'pending', 'processing', 'on-hold' ) ) ) as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			$kind = self::chargeable( $order );
			if ( '' === $kind && self::outgoing( $order ) ) { $kind = 'outgoing'; }
			if ( '' !== $kind ) { $rows[] = self::row( $order, $kind ); }
		}
		return self::no_cache( array( 'orders' => $rows, 'generatedAt' => gmdate( 'c' ) ) );
	}

	/** Usuaria de la dependienta: por su id de la web (people.woo_user_id) o por los últimos 8 números de su teléfono. */
	public static function clerk( int $woo_id, string $phone ): ?WP_User {
		$roles = array( 'cvd_clerk', 'cvd_operator', 'shop_manager', 'administrator' );
		if ( $woo_id ) {
			$user = get_userdata( $woo_id );
			if ( $user && array_intersect( $roles, (array) $user->roles ) ) { return $user; }
		}
		$digits = substr( preg_replace( '/\D+/', '', $phone ), -8 );
		if ( strlen( $digits ) < 8 ) { return null; }
		foreach ( get_users( array( 'role__in' => array( 'cvd_clerk', 'cvd_operator' ), 'number' => 50 ) ) as $user ) {
			$theirs = substr( preg_replace( '/\D+/', '', CVD_Sales::user_phone( $user->ID ) ), -8 );
			if ( $theirs === $digits ) { return $user; }
		}
		return null;
	}

	public static function charge( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'cvd_caja_order', 'Pedido no encontrado.', array( 'status' => 404 ) );
		}
		$charge_id = substr( sanitize_text_field( (string) ( $p['chargeId'] ?? '' ) ), 0, 80 );
		if ( '' === $charge_id ) { return new WP_Error( 'cvd_caja_charge_id', 'Falta el identificador del cobro.', array( 'status' => 422 ) ); }
		$previous = $order->get_meta( self::CHARGE_META, true );
		if ( is_array( $previous ) && ( $previous['id'] ?? '' ) === $charge_id && $order->has_status( 'completed' ) ) {
			return self::no_cache( array( 'ok' => true, 'repeated' => true, 'message' => 'Ya estaba cobrado con este mismo cobro de la caja.' ) );
		}
		if ( $order->has_status( 'completed' ) ) {
			$at = (string) $order->get_meta( '_cvd_collection_received_at', true );
			return new WP_Error( 'cvd_already_charged', 'Este pedido ya se cobró en Ventas' . ( '' !== $at ? ' (' . get_date_from_gmt( $at, 'd/m H:i' ) . ')' : '' ) . '. No lo cobres otra vez.', array( 'status' => 409 ) );
		}
		$kind = self::chargeable( $order );
		if ( '' === $kind ) {
			return new WP_Error( 'cvd_not_chargeable', 'Este pedido no está listo para cobrar en la tienda (cancelado, sin entregar o con incidencia).', array( 'status' => 409 ) );
		}
		$actor = absint( get_option( 'cvd_panel_actor_user_id', 0 ) );
		$name = substr( sanitize_text_field( (string) ( $p['clerkName'] ?? '' ) ), 0, 80 );
		$clerk = self::clerk( absint( $p['clerkWooId'] ?? 0 ), (string) ( $p['clerkPhone'] ?? '' ) );
		$signer = $clerk ? $clerk->ID : $actor;
		if ( ! $signer || ( ! user_can( $signer, 'cvd_manage_sales' ) && ! user_can( $signer, 'manage_woocommerce' ) ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma las acciones del panel.', array( 'status' => 500 ) );
		}
		$device = substr( sanitize_text_field( (string) ( $p['device'] ?? '' ) ), 0, 60 );
		// Se marca ANTES de cerrar: el «debería haber» del cajón no cuenta este pedido dos veces.
		$order->update_meta_data( self::CHARGE_META, array( 'id' => $charge_id, 'by' => $name, 'clerk' => $clerk ? $clerk->ID : 0, 'device' => $device, 'at' => current_time( 'mysql', true ) ) );
		$order->save();

		wp_set_current_user( $signer );
		$inner = new WP_REST_Request( 'POST', '/' . self::NS . '/sales/' . $order->get_id() . '/status' );
		$params = array(
			'status' => 'delivered',
			'collectionMethod' => sanitize_key( (string) ( $p['collectionMethod'] ?? '' ) ),
			'collectionReference' => (string) ( $p['collectionReference'] ?? '' ),
			'collectedUsd' => $p['collectedUsd'] ?? 0,
			'collectedCup' => $p['collectedCup'] ?? 0,
			'collectionNote' => trim( 'Cobrado en la caja' . ( '' !== $device ? ' (' . $device . ')' : '' ) . ( '' !== $name ? ' por ' . $name : '' ) . '. ' . sanitize_textarea_field( (string) ( $p['collectionNote'] ?? '' ) ) ),
			'moneyConfirmed' => true,
			'handoverConfirmed' => true,
			'idempotencyKey' => 'caja:' . $charge_id,
		);
		foreach ( $params as $key => $value ) { $inner->set_param( $key, $value ); }
		$response = rest_do_request( $inner );
		$fresh = wc_get_order( $order->get_id() );
		if ( $response->is_error() || ! $fresh || ! $fresh->has_status( 'completed' ) ) {
			if ( $fresh ) { $fresh->delete_meta_data( self::CHARGE_META ); $fresh->save(); }
			$error = $response->is_error() ? $response->as_error() : new WP_Error( 'cvd_caja_not_closed', 'No se pudo cerrar el pedido.', array( 'status' => 409 ) );
			return $error;
		}
		$fresh->add_order_note( sprintf( 'Cobrado en la caja NEXO%s por %s%s.', '' !== $device ? ' (' . $device . ')' : '', '' !== $name ? $name : 'la tienda', $clerk ? '' : ' (firmado por el panel: no encontré su usuaria en la web)' ) );
		$data = (array) $response->get_data();
		return self::no_cache( array( 'ok' => true, 'message' => (string) ( $data['message'] ?? 'Pedido cobrado.' ), 'signedBy' => $clerk ? $clerk->display_name : 'panel' ) );
	}

	/** Vuelto desde la caja: {changeRequired:[{amount, currency}], clerkName, device}. Firma el panel; la nota dice quién. */
	public static function change( WP_REST_Request $request ) {
		if ( ! class_exists( 'CVD_Order_Edit' ) ) { return new WP_Error( 'cvd_caja_change', 'No disponible.', array( 'status' => 501 ) ); }
		$p = (array) $request->get_json_params();
		$name = substr( sanitize_text_field( (string) ( $p['clerkName'] ?? '' ) ), 0, 80 );
		$device = substr( sanitize_text_field( (string) ( $p['device'] ?? '' ) ), 0, 60 );
		$by = trim( ( '' !== $name ? $name : 'la tienda' ) . ( '' !== $device ? ' (' . $device . ')' : '' ) );
		$result = CVD_Order_Edit::set_change( absint( $request['id'] ), (array) ( $p['changeRequired'] ?? array() ), absint( get_option( 'cvd_panel_actor_user_id', 0 ) ), 'caja', $by );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	/** Efectivo cerrado en Ventas desde {from} (ISO), por moneda, sin los cobrados en la caja. */
	public static function cash_collections( WP_REST_Request $request ): WP_REST_Response {
		$from_raw = (string) $request->get_param( 'from' );
		$from = strtotime( $from_raw );
		if ( ! $from ) { $from = time() - DAY_IN_SECONDS; }
		$from = max( $from, time() - 3 * DAY_IN_SECONDS );
		$totals = array();
		$orders = array();
		foreach ( wc_get_orders( array( 'limit' => 300, 'type' => 'shop_order', 'status' => array( 'completed' ), 'date_completed' => '>=' . $from ) ) as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			if ( is_array( $order->get_meta( self::CHARGE_META, true ) ) ) { continue; } // ya está en la caja
			$held = class_exists( 'CVD_Messenger_Cash' ) ? CVD_Messenger_Cash::held( $order ) : array();
			if ( ! $held ) { continue; }
			foreach ( $held as $h ) {
				$currency = strtoupper( (string) $h['currency'] );
				$totals[ $currency ] = ( $totals[ $currency ] ?? 0 ) + (int) round( (float) $h['amount'] * 100 );
			}
			$orders[] = array( 'id' => $order->get_id(), 'number' => $order->get_order_number(),
				'change' => class_exists( 'CVD_Order_Edit' ) ? CVD_Order_Edit::change_of( $order ) : array(), 'at' => $order->get_date_completed() ? $order->get_date_completed()->date( 'c' ) : '', 'cash' => $held,
				'method' => (string) $order->get_meta( '_cvd_collection_method', true ) );
		}
		$web = array();
		foreach ( $totals as $currency => $minor ) { $web[] = array( 'currency' => $currency, 'amountMinor' => $minor ); }
		return self::no_cache( array( 'from' => gmdate( 'c', $from ), 'web' => $web, 'orders' => $orders ) );
	}
}
