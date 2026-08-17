<?php

defined( 'ABSPATH' ) || exit;

/** Flujo operativo de ventas. WooCommerce sigue siendo la única fuente de verdad. */
final class CVD_Sales {
	private const STATUS_META = '_cvd_operation_status';
	private const STATUSES = array( 'new', 'confirmed', 'preparing', 'ready', 'with_courier', 'delivered', 'incident', 'cancelled' );

	public static function register(): void {
		add_shortcode( 'casa_viva_sales', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'initialize_order' ), 50 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'initialize_order' ), 50 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled' ), 50 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_cancelled' ), 50 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'sync_cancelled' ), 50 );
	}

	public static function can_view(): bool {
		return current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function initialize_order( WC_Order $order ): void {
		if ( ! $order->get_meta( self::STATUS_META, true ) ) {
			$order->update_meta_data( self::STATUS_META, 'new' );
			$at = current_time( 'mysql', true );
			$order->update_meta_data( '_cvd_operation_updated_at', $at );
			$order->save();
			do_action( 'cvd_order_transition_observed', $order->get_id(), 'operation', '', 'new', 'cvd_sales_initialize_order', array(), $at );
		}
		if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_new_order( $order ); }
	}

	public static function sync_cancelled( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if(class_exists('CVD_Order_Transition_Service')){CVD_Order_Transition_Service::cancel($order_id,$order->get_status(),array('system'=>true,'source'=>'woocommerce_hook'));}
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/sales', array(
			'methods' => 'GET',
			'callback' => array( __CLASS__, 'orders' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/status', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'change_status' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
	}

	public static function orders( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$args = array(
			'limit' => 50,
			'orderby' => 'date',
			'order' => 'DESC',
			'status' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
		);
		$orders = wc_get_orders( $args );
		$items = array();
		$summary = array_fill_keys( self::STATUSES, 0 );
		foreach ( $orders as $order ) {
			if ( $search && ! self::matches_search( $order, $search ) ) { continue; }
			$operation_status = self::operation_status( $order );
			$summary[ $operation_status ]++;
			if ( $status && in_array( $status, self::STATUSES, true ) && $status !== $operation_status ) { continue; }
			$items[] = self::payload( $order );
		}
		return rest_ensure_response( array( 'summary' => $summary, 'orders' => $items ) );
	}

	private static function matches_search( WC_Order $order, string $search ): bool {
		$haystack = array( $order->get_order_number(), $order->get_formatted_billing_full_name(), $order->get_billing_phone(), $order->get_billing_email() );
		foreach ( $order->get_items( 'line_item' ) as $item ) { $haystack[] = $item->get_name(); }
		return false !== stripos( implode( ' ', $haystack ), $search );
	}

	public static function change_status( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		$next = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! $order || ( ! in_array( $next, self::STATUSES, true ) && 'picked_up' !== $next ) ) {
			return new WP_Error( 'cvd_invalid_sale', 'Pedido o estado no válido.', array( 'status' => 422 ) );
		}
		if ( 'picked_up' === $next ) {
			if ( ! class_exists( 'CVD_Delivery' ) || ! CVD_Delivery::handover_by_staff( $order, get_current_user_id(), 'sales_dashboard' ) ) {
				return new WP_Error( 'cvd_invalid_handover', 'El mensajero todavía no está listo para recibir este pedido.', array( 'status' => 409 ) );
			}
			return rest_ensure_response( array( 'message' => 'Pedido entregado al mensajero.', 'order' => self::payload( wc_get_order( $order->get_id() ) ) ) );
		}
		$current = self::operation_status( $order );
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		if ( 'pickup' === $fulfillment && 'ready' === $current && 'delivered' === $next && class_exists( 'CVD_Order_Transition_Service' ) ) {
			$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotencyKey' ) ) );
			$result = CVD_Order_Transition_Service::complete_pickup( $order->get_id(), array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $idempotency_key,
				'collection_method' => sanitize_key( (string) $request->get_param( 'collectionMethod' ) ),
				'collected_usd' => $request->get_param( 'collectedUsd' ),
				'collected_cup' => $request->get_param( 'collectedCup' ),
				'collection_note' => sanitize_textarea_field( (string) $request->get_param( 'collectionNote' ) ),
				'money_confirmed' => rest_sanitize_boolean( $request->get_param( 'moneyConfirmed' ) ),
				'handover_confirmed' => rest_sanitize_boolean( $request->get_param( 'handoverConfirmed' ) ),
			) );
			if ( empty( $result['success'] ) ) {
				$status = CVD_Order_Transition_Service::UNAUTHORIZED === $result['error_code'] ? 403 : ( CVD_Order_Transition_Service::ORDER_NOT_FOUND === $result['error_code'] ? 404 : ( CVD_Order_Transition_Service::PRECONDITION_FAILED === $result['error_code'] ? 422 : 409 ) );
				return new WP_Error( 'cvd_pickup_' . strtolower( $result['error_code'] ), 'No se pudo completar la recogida. Confirma entrega física y cobro.', array( 'status' => $status, 'transition' => $result ) );
			}
			return rest_ensure_response( array( 'message' => 'Recogida completada.', 'order' => self::payload( wc_get_order( $order->get_id() ) ), 'transition' => $result ) );
		}
		if(class_exists('CVD_Order_Transition_Service')&&('incident'===$next||'incident'===$current)){
			$key=sanitize_text_field((string)($request->get_header('X-CVD-Idempotency-Key')?:$request->get_param('idempotencyKey')));$result='incident'===$next?CVD_Order_Transition_Service::open_incident($order->get_id(),'operation',array('actor_user_id'=>get_current_user_id(),'idempotency_key'=>$key,'note'=>sanitize_textarea_field((string)$request->get_param('note')))):CVD_Order_Transition_Service::resolve_incident($order->get_id(),'operation',array('actor_user_id'=>get_current_user_id(),'idempotency_key'=>$key,'note'=>sanitize_textarea_field((string)$request->get_param('note'))));
			if(empty($result['success'])||('incident'===$current&&$next!==$result['new_state'])){return new WP_Error('cvd_incident_conflict','No se pudo actualizar la incidencia sin inventar una etapa.',array('status'=>409,'transition'=>$result));}return rest_ensure_response(array('message'=>'Pedido actualizado.','order'=>self::payload(wc_get_order($order->get_id())),'transition'=>$result));
		}
		if ( class_exists( 'CVD_Order_Transition_Service' ) && CVD_Order_Transition_Service::governs( 'operation', $current, $next ) ) {
			$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotencyKey' ) ) );
			$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'operation', $next, array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $idempotency_key,
				'source' => 'cvd_sales_change_status',
			) );
			if ( empty( $result['success'] ) ) {
				$status = in_array( $result['error_code'], array( CVD_Order_Transition_Service::UNAUTHORIZED ), true ) ? 403 : ( CVD_Order_Transition_Service::ORDER_NOT_FOUND === $result['error_code'] ? 404 : 409 );
				return new WP_Error( 'cvd_transition_' . strtolower( $result['error_code'] ), 'No se pudo actualizar el pedido.', array( 'status' => $status, 'transition' => $result ) );
			}
			if ( 'ready' === $next && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) && class_exists( 'CVD_Delivery' ) ) {
				CVD_Delivery::publish_offer( wc_get_order( $order->get_id() ) );
			}
			return rest_ensure_response( array( 'message' => 'Pedido actualizado.', 'order' => self::payload( wc_get_order( $order->get_id() ) ), 'transition' => $result ) );
		}
		$allowed = self::allowed_transitions( $current );
		if ( ! in_array( $next, $allowed, true ) ) {
			return new WP_Error( 'cvd_invalid_transition', 'Ese cambio de estado no está permitido.', array( 'status' => 409 ) );
		}
		if ( 'cancelled' === $next && ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_admin_confirmation_required', 'La cancelación debe confirmarla administración.', array( 'status' => 403 ) );
		}
		if('cancelled'===$next){$order->update_status('cancelled','Cancelado por administración desde el Centro de ventas.');$fresh=wc_get_order($order->get_id());$coherent=$fresh&&'cancelled'===sanitize_key((string)$fresh->get_meta(self::STATUS_META,true));if(!$coherent){return new WP_Error('cvd_cancellation_failed','No se pudo cancelar el pedido de forma coherente.',array('status'=>409));}return rest_ensure_response(array('message'=>'Pedido actualizado.','order'=>self::payload($fresh)));}
		if ( 'delivered' === $next ) {
			$payment_method = sanitize_key( (string) $request->get_param( 'collectionMethod' ) );
			if ( ! in_array( $payment_method, array( 'cash_usd', 'cash_cup', 'transfer', 'mixed', 'other' ), true ) || ! rest_sanitize_boolean( $request->get_param( 'moneyConfirmed' ) ) ) {
				return new WP_Error( 'cvd_money_confirmation', 'Confirma cómo se recibió el dinero antes de completar.', array( 'status' => 422 ) );
			}
			$order->update_meta_data( '_cvd_collection_method', $payment_method );
			$order->update_meta_data( '_cvd_collection_amount_usd', wc_format_decimal( $request->get_param( 'collectedUsd' ) ?: 0, 2 ) );
			$order->update_meta_data( '_cvd_collection_amount_cup', wc_format_decimal( $request->get_param( 'collectedCup' ) ?: 0, 2 ) );
			$order->update_meta_data( '_cvd_collection_note', sanitize_textarea_field( (string) $request->get_param( 'collectionNote' ) ) );
			$order->update_meta_data( '_cvd_collection_received_by', get_current_user_id() );
			$order->update_meta_data( '_cvd_collection_received_at', current_time( 'mysql', true ) );
		}

		$order->update_meta_data( self::STATUS_META, $next );
		$order->update_meta_data( '_cvd_operation_updated_at', current_time( 'mysql', true ) );
		$history = $order->get_meta( '_cvd_operation_history', true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array( 'from' => $current, 'to' => $next, 'user_id' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'event_anchor' => wp_generate_uuid4() );
		$order->update_meta_data( '_cvd_operation_history', array_slice( $history, -100 ) );
		$order->add_order_note( sprintf( 'Operación Casa Viva: %s → %s.', self::label( $current ), self::label( $next ) ) );
		$order->save();
		$last_event = end( $history );
		do_action( 'cvd_order_transition_observed', $order->get_id(), 'operation', $current, $next, 'cvd_sales_change_status', array(), (string) ( $last_event['event_anchor'] ?? $last_event['at'] ?? '' ) );
		if ( 'ready' === $next && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) && class_exists( 'CVD_Delivery' ) ) {
			CVD_Delivery::publish_offer( $order );
		}

		if ( 'delivered' === $next ) {
			$order->update_meta_data( '_cvd_commission_review_ready', 'yes' );
			$order->save();
			$delivery_closed = class_exists( 'CVD_Delivery' ) && 'delivered' === CVD_Delivery::status( $order )
				? CVD_Delivery::close_after_cash_received( $order )
				: false;
			if ( ! $delivery_closed && class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_approved( $order->get_id() ); }
			$order = wc_get_order( $order->get_id() );
			if ( $order && ! $order->has_status( 'completed' ) ) { $order->update_status( 'completed', 'Dinero recibido y operación completada.' ); }
		}
		return rest_ensure_response( array( 'message' => 'Pedido actualizado.', 'order' => self::payload( wc_get_order( $order->get_id() ) ) ) );
	}

	private static function allowed_transitions( string $current ): array {
		$map = array(
			'new' => array( 'preparing', 'incident', 'cancelled' ),
			'confirmed' => array( 'preparing', 'incident', 'cancelled' ),
			'preparing' => array( 'ready', 'incident', 'cancelled' ),
			'ready' => array( 'incident', 'cancelled' ),
			'with_courier' => array( 'delivered', 'incident', 'cancelled' ),
			'incident' => array( 'confirmed', 'preparing', 'ready', 'with_courier', 'cancelled' ),
			'delivered' => array(),
			'cancelled' => array(),
		);
		return $map[ $current ] ?? array();
	}

	private static function operation_status( WC_Order $order ): string {
		$status = sanitize_key( (string) $order->get_meta( self::STATUS_META, true ) );
		if ( 'cancelled' === $order->get_status() || in_array( $order->get_status(), array( 'refunded', 'failed' ), true ) ) { return 'cancelled'; }
		if ( 'completed' === $order->get_status() ) { return 'delivered'; }
		if('yes'===$order->get_meta('_cvd_operation_incident_active',true)){return 'incident';}return in_array( $status, self::STATUSES, true ) ? $status : 'new';
	}

	private static function payload( WC_Order $order ): array {
		$products = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$products[] = array( 'name' => $item->get_name(), 'quantity' => (int) $item->get_quantity(), 'url' => $product ? get_permalink( $product->get_parent_id() ?: $product->get_id() ) : '' );
		}
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		$operation_status = self::operation_status( $order );
		$delivery_status = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::status( $order ) : '';
		$actions = self::allowed_transitions( $operation_status );
		if ( 'pickup' === $fulfillment && 'ready' === $operation_status ) { array_unshift( $actions, 'delivered' ); }
		if ( 'pickup' !== $fulfillment && class_exists( 'CVD_Delivery' ) ) {
			$actions = array_values( array_diff( $actions, array( 'with_courier', 'delivered' ) ) );
			if ( in_array( $delivery_status, array( 'accepted', 'to_store' ), true ) ) { array_unshift( $actions, 'picked_up' ); }
			if ( 'delivered' === $delivery_status ) { array_unshift( $actions, 'delivered' ); }
		}
		$phone = preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() );
		return array(
			'id' => $order->get_id(),
			'number' => $order->get_order_number(),
			'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
			'status' => $operation_status,
			'statusLabel' => 'pickup' === $fulfillment && 'ready' === $operation_status ? 'Listo para recoger' : self::label( $operation_status ),
			'customer' => $order->get_formatted_billing_full_name(),
			'phone' => $phone,
			'address' => 'pickup' === $fulfillment ? get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) : trim( $order->get_billing_address_1() . ', ' . $order->get_meta( '_cvd_locality', true ) . ', ' . $order->get_billing_city() ),
			'fulfillment' => 'pickup' === $fulfillment ? 'Recogida en tienda' : 'Entrega a domicilio',
			'total' => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'products' => $products,
			'gestora' => (string) $order->get_meta( 'gestora_nombre', true ),
			'commission' => (float) $order->get_meta( '_cvd_commission_amount', true ),
			'commissionStatus' => sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'none',
			'shippingCup' => 'pickup' === $fulfillment ? 0 : ( class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ),
			'orderCode' => 'CV-PEDIDO-' . $order->get_id(),
			'deliveryStatus' => 'pickup' === $fulfillment ? '' : ( class_exists( 'CVD_Delivery' ) ? CVD_Delivery::label( $delivery_status ) : '' ),
			'trackingUrl' => class_exists( 'CVD_Delivery' ) && 'pickup' !== $fulfillment ? CVD_Delivery::tracking_url( $order ) : '',
			'actions' => array_values( array_unique( $actions ) ),
			'adminUrl' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ),
		);
	}

	private static function label( string $status ): string {
		$labels = array( 'new' => 'Nuevo', 'confirmed' => 'Confirmado', 'preparing' => 'Preparando', 'ready' => 'Listo para mensajería', 'with_courier' => 'En camino', 'delivered' => 'Dinero recibido · completado', 'incident' => 'Incidencia', 'cancelled' => 'Cancelado' );
		return $labels[ $status ] ?? ucfirst( $status );
	}

	public static function assets(): void {
		if ( ! is_page( 'ventas' ) ) { return; }
		wp_enqueue_style( 'cvd-sales', CVD_URL . 'assets/sales.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-qr-code', CVD_URL . 'assets/qr-code.js', array(), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-sales', CVD_URL . 'assets/sales.js', array( 'cvd-qr-code' ), CVD_VERSION, true );
		wp_localize_script( 'cvd-sales', 'cvdSales', array(
			'url' => rest_url( 'casa-viva/v1/sales' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'isAdmin' => current_user_can( 'manage_woocommerce' ),
			'logoutUrl' => wp_logout_url( home_url( '/casa-viva-app/' ) ),
			'notificationsUrl' => rest_url( 'casa-viva/v1/notifications' ),
		) );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) { return '<section class="cvd-sales-denied"><h1>Centro de ventas</h1><p>Inicia sesión para continuar.</p><a href="' . esc_url( home_url( '/casa-viva-app/' ) ) . '">Iniciar sesión</a></section>'; }
		if ( ! self::can_view() ) { return '<section class="cvd-sales-denied"><h1>Acceso restringido</h1><p>Esta cuenta no administra pedidos.</p></section>'; }
		return '<section class="cvd-sales-app"><nav><a href="' . esc_url( home_url( '/centro-operaciones/' ) ) . '">Operaciones</a><button data-cvd-enable-notifications type="button">Activar alertas</button><a href="' . esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ) . '">Salir</a></nav><header><h1>Pedidos</h1></header><div class="cvd-sales-summary" id="cvd-sales-summary"></div><div class="cvd-sales-tools"><input id="cvd-sales-search" type="search" placeholder="Buscar pedido, cliente o producto"><select id="cvd-sales-filter"><option value="">Todos</option><option value="new">Nuevos</option><option value="preparing">Preparando</option><option value="ready">Listos</option><option value="with_courier">Con mensajero</option><option value="delivered">Completados</option><option value="incident">Incidencias</option><option value="cancelled">Cancelados</option></select><button id="cvd-sales-refresh" type="button">Actualizar</button><button id="cvd-scan-order" type="button">Escanear vale</button></div><video id="cvd-order-scanner" playsinline hidden></video><p id="cvd-sales-message" role="status"></p><div class="cvd-sales-list" id="cvd-sales-list"><p>Cargando pedidos…</p></div><dialog id="cvd-money-dialog"><form method="dialog" id="cvd-money-form"><h2 id="cvd-money-title">Dinero recibido</h2><input id="cvd-money-order" type="hidden"><input id="cvd-money-pickup" type="hidden" value="0"><label>Forma de cobro<select id="cvd-money-method" required><option value="">Selecciona</option><option value="cash_usd">Efectivo USD</option><option value="cash_cup">Efectivo CUP</option><option value="transfer">Transferencia</option><option value="mixed">Pago mixto</option><option value="other">Otro</option></select></label><label>Recibido en USD<input id="cvd-money-usd" min="0" step="0.01" type="number"></label><label>Recibido en CUP<input id="cvd-money-cup" min="0" step="1" type="number"></label><label>Observación<textarea id="cvd-money-note" rows="2"></textarea></label><label class="cvd-money-check"><input id="cvd-handover-confirmed" type="checkbox"> Confirmo que el producto fue entregado físicamente al cliente.</label><label class="cvd-money-check"><input id="cvd-money-confirmed" type="checkbox" required> Confirmo el dinero recibido.</label><div><button value="cancel">Volver</button><button id="cvd-money-submit" value="default">Completar</button></div></form></dialog></section>';
	}
}
