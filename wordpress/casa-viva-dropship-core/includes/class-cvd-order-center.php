<?php

defined( 'ABSPATH' ) || exit;

/** Proyección operativa de solo lectura y adaptador de acciones 1C. */
final class CVD_Order_Center {
	private const LABELS = array(
		'preparing' => 'Comenzar preparación', 'ready' => 'Pedido listo', 'picked_up' => 'Entregar al mensajero',
		'handed_over' => 'En camino al cliente', 'delivered' => 'Confirmar entrega', 'cash_returned' => 'Confirmar efectivo recibido',
		'closed' => 'Cerrar pedido', 'failed' => 'Marcar entrega fallida', 'returned' => 'Confirmar devolución',
	);

	public static function register(): void {
		add_shortcode( 'casa_viva_order_center', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function can_view(): bool { return current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' ); }

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/order-center/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_order' ), 'permission_callback' => array( __CLASS__, 'can_view' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'act' ), 'permission_callback' => array( __CLASS__, 'can_view' ) ),
		) );
	}

	public static function get_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		return rest_ensure_response( self::project( $order, get_current_user_id(), absint( $request->get_param( 'timeline_page' ) ) ?: 1 ) );
	}

	public static function act( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		$action_id = sanitize_key( (string) $request->get_param( 'action_id' ) );
		if ( ! $order ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$projection = self::project( $order, get_current_user_id() );
		$action = null;
		foreach ( $projection['available_actions'] as $candidate ) { if ( $candidate['id'] === $action_id && ! $candidate['blocked'] ) { $action = $candidate; break; } }
		if ( ! $action ) { return new WP_Error( 'cvd_action_unavailable', 'Esta acción ya no está disponible.', array( 'status' => 409 ) ); }
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), $action['domain'], $action['target'], array(
			'actor_user_id' => get_current_user_id(), 'idempotency_key' => sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotency_key' ) ) ), 'source' => 'cvd_order_center',
		) );
		if ( empty( $result['success'] ) ) { return new WP_Error( 'cvd_action_rejected', 'No se pudo actualizar el pedido.', array( 'status' => 409, 'transition' => $result ) ); }
		return rest_ensure_response( array( 'transition' => $result, 'projection' => self::project( wc_get_order( $order->get_id() ), get_current_user_id() ) ) );
	}

	public static function project( WC_Order $order, int $actor_id, int $timeline_page = 1 ): array {
		$is_admin = user_can( $actor_id, 'manage_woocommerce' );
		$canonical = CVD_Canonical_Order_Reader::read( $order );
		$timeline = CVD_Order_Event_Timeline::for_wc_order( $order, $timeline_page, 50 );
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) ?: 'delivery';
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$variation = array();
			foreach ( $item->get_formatted_meta_data( '' ) as $meta ) { $variation[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value ); }
			$items[] = array( 'name' => $item->get_name(), 'variation' => implode( ' · ', $variation ), 'quantity' => (int) $item->get_quantity(), 'price' => wp_strip_all_tags( $order->get_formatted_line_subtotal( $item ) ), 'image' => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '' );
		}
		$courier_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$incident_domain = ! empty( $canonical['incident']['sources'] ) ? (string) reset( $canonical['incident']['sources'] ) : '';
		$incident_prefix = $incident_domain ? '_cvd_' . $incident_domain . '_incident_' : '';
		$projection = array(
			'order' => array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'created_at' => $order->get_date_created() ? $order->get_date_created()->date_i18n( DATE_ATOM ) : '', 'woocommerce_status' => $order->get_status() ),
			'customer' => array( 'name' => $order->get_formatted_billing_full_name(), 'phone' => preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() ) ),
			'items' => $items,
			'pricing' => array( 'total' => wp_strip_all_tags( $order->get_formatted_order_total() ), 'shipping_cup' => class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ),
			'operation' => array( 'status' => $canonical['operation_status'], 'updated_at' => (string) $order->get_meta( '_cvd_operation_updated_at', true ) ),
			'delivery' => array( 'mode' => $fulfillment, 'status' => $canonical['delivery_status'], 'address' => 'pickup' === $fulfillment ? get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) : trim( $order->get_billing_address_1() . ', ' . $order->get_meta( '_cvd_locality', true ) . ', ' . $order->get_billing_city() ), 'location' => (string) $order->get_meta( '_cvd_location_url', true ) ),
			'courier' => array( 'id' => $courier_id, 'name' => $courier_id ? (string) get_the_author_meta( 'display_name', $courier_id ) : '' ),
			'payment' => array( 'status' => $canonical['cash_status'], 'method' => (string) $order->get_meta( '_cvd_collection_method', true ), 'verified_at' => (string) $order->get_meta( '_cvd_collection_received_at', true ) ),
			'commission_summary' => array( 'status' => $canonical['commission_status'] ),
			'gestora' => array( 'id' => $owner_id, 'name' => (string) $order->get_meta( 'gestora_nombre', true ), 'attributed' => $owner_id > 0 ),
			'incident' => array( 'active' => (bool) $canonical['incident']['active'], 'domain' => $incident_domain, 'note' => $incident_prefix ? (string) $order->get_meta( $incident_prefix . 'note', true ) : '', 'actor_id' => $incident_prefix ? absint( $order->get_meta( $incident_prefix . 'opened_by', true ) ) : 0, 'at' => $incident_prefix ? (string) $order->get_meta( $incident_prefix . 'opened_at', true ) : '' ),
			'canonical_stage' => $canonical['canonical_stage'],
			'consistency' => array( 'level' => $canonical['consistency'], 'review_required' => 'CONFLICT' === $canonical['consistency'], 'reasons' => $is_admin ? $canonical['reasons'] : array() ),
			'timeline' => $timeline,
		);
		$projection['available_actions'] = self::actions( $order, $actor_id, $canonical );
		if ( ! $is_admin ) { unset( $projection['gestora']['id'] ); }
		return $projection;
	}

	private static function actions( WC_Order $order, int $actor_id, array $canonical ): array {
		if ( 'CONFLICT' === $canonical['consistency'] ) { return array( array( 'id' => 'review_required', 'label' => 'Revisión requerida', 'domain' => 'consistency', 'target' => '', 'requires_confirmation' => false, 'required_fields' => array(), 'capability' => 'manage_woocommerce', 'blocked' => true, 'blocked_reason' => 'El pedido necesita revisión administrativa.' ) ); }
		$actions = array();
		foreach ( array( 'operation', 'delivery' ) as $domain ) {
			foreach ( CVD_Order_Transition_Service::available_targets( $order, $domain, $actor_id ) as $target ) {
				$id = $domain . '_' . $target;
				$actions[] = array( 'id' => $id, 'label' => self::LABELS[ $target ] ?? ucfirst( str_replace( '_', ' ', $target ) ), 'domain' => $domain, 'target' => $target, 'requires_confirmation' => in_array( $target, array( 'picked_up', 'delivered', 'cash_returned', 'closed', 'failed', 'returned' ), true ), 'required_fields' => array(), 'capability' => 'operation' === $domain ? 'cvd_manage_sales' : 'cvd_manage_sales', 'blocked' => false, 'blocked_reason' => '' );
			}
		}
		return $actions;
	}

	public static function assets(): void {
		if ( ! is_page( 'centro-pedido' ) ) { return; }
		wp_enqueue_style( 'cvd-order-center', CVD_URL . 'assets/order-center.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-order-center', CVD_URL . 'assets/order-center.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-order-center', 'cvdOrderCenter', array( 'url' => rest_url( 'casa-viva/v1/order-center/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function render(): string {
		if ( ! self::can_view() ) { return '<section class="cvd-order-center-denied"><h1>Acceso restringido</h1></section>'; }
		$order_id = absint( $_GET['order_id'] ?? 0 );
		return '<main class="cvd-order-center" data-order-id="' . esc_attr( $order_id ) . '"><p id="cvd-order-center-status" role="status">Cargando pedido…</p><div id="cvd-order-center-root"></div></main>';
	}
}
