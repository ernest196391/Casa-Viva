<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Customer_Orders {
	private static ?int $active_count = null;

	public static function register(): void {
		remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders', 10 );
		add_action( 'woocommerce_account_orders_endpoint', array( __CLASS__, 'render' ), 10 );
		remove_action( 'woocommerce_account_view-order_endpoint', 'woocommerce_account_view_order', 10 );
		add_action( 'woocommerce_account_view-order_endpoint', array( __CLASS__, 'render_detail' ), 10 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 35 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/customer/orders/(?P<id>\\d+)/tracking', array(
			'methods' => 'GET',
			'callback' => array( __CLASS__, 'tracking' ),
			'permission_callback' => array( __CLASS__, 'can_view_order' ),
		) );
	}

	public static function can_view_order( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) { return false; }
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && (int) $order->get_customer_id() === get_current_user_id();
	}

	public static function tracking( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return new WP_Error( 'cvd_customer_order_forbidden', 'Pedido no disponible.', array( 'status' => 403 ) );
		}

		$live = array( 'status' => '', 'statusLabel' => '', 'location' => null );
		if ( class_exists( 'CVD_Live_Tracking' ) ) {
			$tracking_request = new WP_REST_Request( 'GET', '/casa-viva/v1/delivery/' . $order->get_id() . '/tracking' );
			$tracking_request->set_param( 'id', $order->get_id() );
			$tracking_request->set_param( 'key', $order->get_order_key() );
			$response = CVD_Live_Tracking::tracking( $tracking_request );
			if ( ! is_wp_error( $response ) && $response instanceof WP_REST_Response ) {
				$live = array_merge( $live, (array) $response->get_data() );
			}
		}

		$stage = self::canonical_stage( $order );
		return rest_ensure_response( array(
			'orderId' => $order->get_id(),
			'stage' => $stage,
			'stageLabel' => self::stage_label( $stage, self::customer_stage( $order ) ),
			'deliveryStatus' => (string) ( $live['status'] ?? '' ),
			'deliveryStatusLabel' => (string) ( $live['statusLabel'] ?? '' ),
			'location' => $live['location'] ?? null,
			'timeline' => self::customer_timeline( $order ),
		) );
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! function_exists( 'is_wc_endpoint_url' ) || ( ! is_wc_endpoint_url( 'orders' ) && ! is_wc_endpoint_url( 'view-order' ) ) ) { return; }
		wp_enqueue_style( 'cvd-customer-orders', CVD_URL . 'assets/customer-orders.css', array(), CVD_VERSION );
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			$order_id = absint( get_query_var( 'view-order' ) );
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && (int) $order->get_customer_id() === get_current_user_id() ) {
				wp_enqueue_script( 'cvd-customer-order-live', CVD_URL . 'assets/customer-order-live.js', array(), CVD_VERSION, true );
				wp_localize_script( 'cvd-customer-order-live', 'cvdCustomerOrderLive', array(
					'url' => rest_url( 'casa-viva/v1/customer/orders/' . $order_id . '/tracking' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'interval' => 8000,
				) );
			}
		}
	}

	private static function canonical_state( WC_Order $order ): array {
		if ( class_exists( 'CVD_Canonical_Order_Reader' ) ) {
			$state = CVD_Canonical_Order_Reader::read( $order );
			return is_array( $state ) ? $state : array();
		}
		return array();
	}

	private static function canonical_stage( WC_Order $order ): string {
		$state = self::canonical_state( $order );
		return (string) ( $state['canonical_stage'] ?? '' );
	}

	private static function is_terminal( WC_Order $order ): bool {
		$stage = self::canonical_stage( $order );
		if ( $stage ) { return in_array( $stage, array( 'COMPLETED', 'CANCELLED', 'DELIVERY_FAILED' ), true ); }
		return $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) );
	}

	private static function stage_label( string $stage, string $fallback = '' ): string {
		$labels = array(
			'CREATED' => 'Pedido recibido', 'CONFIRMED' => 'Pedido confirmado', 'PREPARING' => 'Preparando pedido',
			'READY_FOR_COURIER' => 'Listo para mensajería', 'READY_FOR_PICKUP' => 'Listo para recoger',
			'COURIER_ASSIGNED' => 'Mensajero asignado', 'COURIER_GOING_TO_PICKUP' => 'Mensajero va a recoger',
			'PICKED_UP' => 'Pedido recogido', 'ON_THE_WAY_TO_CUSTOMER' => 'En camino', 'DELIVERED' => 'Entregado',
			'PAYMENT_RECONCILED' => 'Entrega conciliada', 'COMPLETED' => 'Completado', 'CANCELLED' => 'Cancelado',
			'DELIVERY_FAILED' => 'Entrega no completada', 'CONFLICT' => 'En revisión',
		);
		return $labels[ $stage ] ?? $fallback;
	}

	private static function customer_stage( WC_Order $order ): string {
		$stage = self::canonical_stage( $order );
		return self::stage_label( $stage, wc_get_order_status_name( $order->get_status() ) );
	}

	private static function fulfillment_label( WC_Order $order ): string {
		return 'pickup' === (string) $order->get_meta( '_cvd_fulfillment_type', true ) ? 'Recogida en tienda' : 'Entrega a domicilio';
	}

	private static function card( WC_Order $order, bool $active ): string {
		$date = $order->get_date_created();
		$url = wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
		ob_start(); ?>
		<article class="cvd-customer-order-card <?php echo $active ? 'is-active' : 'is-finished'; ?>" data-cvd-customer-order="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<div class="cvd-customer-order-card__top"><div><strong>Pedido #<?php echo esc_html( (string) $order->get_order_number() ); ?></strong><span><?php echo esc_html( $date ? wc_format_datetime( $date, 'j M Y' ) : '' ); ?></span></div><span class="cvd-customer-order-card__status"><?php echo esc_html( self::customer_stage( $order ) ); ?></span></div>
			<div class="cvd-customer-order-card__meta"><span><?php echo esc_html( self::fulfillment_label( $order ) ); ?></span><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
			<a class="cvd-customer-order-card__action" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $active ? 'Ver pedido' : 'Ver detalles' ); ?></a>
		</article>
		<?php return (string) ob_get_clean();
	}

	private static function customer_timeline( WC_Order $order ): array {
		if ( ! class_exists( 'CVD_Order_Event_Timeline' ) ) { return array(); }
		try { $timeline = CVD_Order_Event_Timeline::for_wc_order( $order, 1, 50 ); } catch ( Throwable $error ) { return array(); }
		$events = array();
		$allowed_domains = array( 'order', 'operation', 'delivery', 'incident' );
		foreach ( (array) ( $timeline['events'] ?? array() ) as $event ) {
			$domain = (string) ( $event['domain'] ?? '' );
			if ( ! in_array( $domain, $allowed_domains, true ) ) { continue; }
			$to = strtoupper( (string) ( $event['to_state'] ?? '' ) );
			$label = self::stage_label( $to );
			if ( ! $label ) {
				$legacy_map = array(
					'NEW' => 'Pedido recibido', 'CONFIRMED' => 'Pedido confirmado', 'PREPARING' => 'Preparando pedido', 'READY' => 'Listo para mensajería',
					'OFFERED' => 'Buscando mensajero', 'ASSIGNED' => 'Mensajero asignado', 'ACCEPTED' => 'Mensajero asignado', 'TO_STORE' => 'Mensajero va a recoger',
					'PICKED_UP' => 'Pedido recogido', 'HANDED_OVER' => 'En camino', 'DELIVERED' => 'Entregado', 'CLOSED' => 'Completado',
					'CANCELLED' => 'Cancelado', 'FAILED' => 'Entrega no completada', 'RETURNED' => 'Entrega no completada', 'INCIDENT' => 'Pedido en revisión',
				);
				$label = $legacy_map[ $to ] ?? '';
			}
			if ( ! $label ) { continue; }
			$events[] = array( 'label' => $label, 'timestamp' => (string) ( $event['timestamp'] ?? '' ) );
		}
		$deduped = array();
		foreach ( $events as $event ) { $deduped[ $event['label'] . '|' . $event['timestamp'] ] = $event; }
		return array_slice( array_values( $deduped ), -8 );
	}

	public static function active_count(): int {
		if ( null !== self::$active_count ) { return self::$active_count; }
		if ( ! is_user_logged_in() || ! function_exists( 'wc_get_orders' ) ) { return self::$active_count = 0; }
		$count = 0;
		foreach ( wc_get_orders( array( 'customer' => get_current_user_id(), 'limit' => 25, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) ) as $order ) {
			if ( $order instanceof WC_Order && ! self::is_terminal( $order ) ) { ++$count; }
		}
		return self::$active_count = $count;
	}

	public static function badge_html(): string {
		$count = self::active_count();
		return '<span class="cvd-customer-nav__badge cvd-customer-nav__badge--orders" data-cvd-orders-count' . ( $count ? '' : ' hidden' ) . '>' . esc_html( (string) $count ) . '</span>';
	}

	public static function render( $current_page = 1 ): void {
		if ( ! is_user_logged_in() ) { echo '<p>Inicia sesión para consultar tus pedidos.</p>'; return; }
		$active = array(); $finished = array();
		foreach ( wc_get_orders( array( 'customer' => get_current_user_id(), 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) ) as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			self::is_terminal( $order ) ? $finished[] = $order : $active[] = $order;
		}
		echo '<div class="cvd-customer-orders"><header class="cvd-customer-orders__header"><p>Tus compras de Casa Viva</p><h2>Pedidos</h2></header>';
		echo '<section class="cvd-customer-orders__section"><div class="cvd-customer-orders__section-title"><h3>Activos</h3><span>' . esc_html( (string) count( $active ) ) . '</span></div>';
		if ( $active ) { foreach ( $active as $order ) { echo self::card( $order, true ); } } else { echo '<p class="cvd-customer-orders__empty">No tienes pedidos activos ahora mismo.</p>'; }
		echo '</section><section class="cvd-customer-orders__section"><div class="cvd-customer-orders__section-title"><h3>Terminados</h3><span>' . esc_html( (string) count( $finished ) ) . '</span></div>';
		if ( $finished ) { foreach ( $finished as $order ) { echo self::card( $order, false ); } } else { echo '<p class="cvd-customer-orders__empty">Aquí aparecerán tus pedidos terminados.</p>'; }
		echo '</section></div>';
	}

	public static function render_detail( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) { wc_print_notice( 'No pudimos encontrar ese pedido en tu cuenta.', 'error' ); return; }
		$date = $order->get_date_created();
		$state = self::canonical_state( $order );
		$stage = (string) ( $state['canonical_stage'] ?? '' );
		$timeline = self::customer_timeline( $order );
		$orders_url = wc_get_account_endpoint_url( 'orders' );
		?>
		<div class="cvd-customer-order-detail" data-cvd-customer-order-detail="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<a class="cvd-customer-order-detail__back" href="<?php echo esc_url( $orders_url ); ?>">← Mis pedidos</a>
			<header class="cvd-customer-order-detail__hero">
				<div><p>Pedido #<?php echo esc_html( (string) $order->get_order_number() ); ?></p><h2 data-cvd-customer-stage><?php echo esc_html( self::stage_label( $stage, self::customer_stage( $order ) ) ); ?></h2><span><?php echo esc_html( $date ? wc_format_datetime( $date, 'j M Y · H:i' ) : '' ); ?></span></div>
				<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
			</header>
			<section class="cvd-customer-order-detail__card"><h3>Tu compra</h3><div class="cvd-customer-order-detail__items">
			<?php foreach ( $order->get_items() as $item ) : $product = $item->get_product(); ?>
				<div class="cvd-customer-order-detail__item"><div><?php echo $product ? $product->get_image( 'woocommerce_thumbnail' ) : ''; ?></div><p><strong><?php echo esc_html( $item->get_name() ); ?></strong><span>Cantidad: <?php echo esc_html( (string) $item->get_quantity() ); ?></span></p><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div>
			<?php endforeach; ?>
			</div></section>
			<section class="cvd-customer-order-detail__card"><h3>Entrega</h3><div class="cvd-customer-order-detail__delivery"><strong><?php echo esc_html( self::fulfillment_label( $order ) ); ?></strong><?php if ( 'pickup' !== (string) $order->get_meta( '_cvd_fulfillment_type', true ) && $order->get_formatted_shipping_address() ) : ?><p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></p><?php endif; ?><div class="cvd-customer-order-detail__live" data-cvd-live-tracking><span data-cvd-live-status>Actualizando seguimiento…</span><a data-cvd-live-location href="#" target="_blank" rel="noopener noreferrer" hidden>Ver ubicación del mensajero</a></div></div></section>
			<section class="cvd-customer-order-detail__card"><h3>Seguimiento del pedido</h3>
			<?php if ( $timeline ) : ?><ol class="cvd-customer-order-detail__timeline" data-cvd-customer-timeline><?php foreach ( $timeline as $index => $event ) : ?><li class="<?php echo $index === array_key_last( $timeline ) ? 'is-current' : ''; ?>"><i></i><div><strong><?php echo esc_html( $event['label'] ); ?></strong><?php if ( $event['timestamp'] ) : ?><span><?php echo esc_html( wp_date( 'j M · H:i', strtotime( $event['timestamp'] . ' UTC' ) ) ); ?></span><?php endif; ?></div></li><?php endforeach; ?></ol><?php else : ?><ol class="cvd-customer-order-detail__timeline" data-cvd-customer-timeline></ol><p class="cvd-customer-order-detail__quiet">Estamos preparando la información de seguimiento.</p><?php endif; ?>
			</section>
		</div>
		<?php
	}
}
