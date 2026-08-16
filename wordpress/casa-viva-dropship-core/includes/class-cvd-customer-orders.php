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
	}

	public static function assets(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() && function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'orders' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
			wp_enqueue_style( 'cvd-customer-orders', CVD_URL . 'assets/customer-orders.css', array(), CVD_VERSION );
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
		if ( $stage ) {
			return in_array( $stage, array( 'COMPLETED', 'CANCELLED', 'DELIVERY_FAILED' ), true );
		}
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
		try {
			$timeline = CVD_Order_Event_Timeline::for_wc_order( $order, 1, 50 );
		} catch ( Throwable $error ) {
			return array();
		}
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
		foreach ( $events as $event ) {
			$key = $event['label'] . '|' . $event['timestamp'];
			$deduped[ $key ] = $event;
		}
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
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			wc_print_notice( 'No pudimos encontrar ese pedido en tu cuenta.', 'error' );
			return;
		}
		$date = $order->get_date_created();
		$state = self::canonical_state( $order );
		$stage = (string) ( $state['canonical_stage'] ?? '' );
		$timeline = self::customer_timeline( $order );
		$orders_url = wc_get_account_endpoint_url( 'orders' );
		?>
		<div class="cvd-customer-order-detail" data-cvd-customer-order-detail="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<a class="cvd-customer-order-detail__back" href="<?php echo esc_url( $orders_url ); ?>">← Mis pedidos</a>
			<header class="cvd-customer-order-detail__hero">
				<div><p>Pedido #<?php echo esc_html( (string) $order->get_order_number() ); ?></p><h2><?php echo esc_html( self::stage_label( $stage, self::customer_stage( $order ) ) ); ?></h2><span><?php echo esc_html( $date ? wc_format_datetime( $date, 'j M Y · H:i' ) : '' ); ?></span></div>
				<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
			</header>
			<section class="cvd-customer-order-detail__card"><h3>Tu compra</h3><div class="cvd-customer-order-detail__items">
			<?php foreach ( $order->get_items() as $item ) : $product = $item->get_product(); ?>
				<div class="cvd-customer-order-detail__item"><div><?php echo $product ? $product->get_image( 'woocommerce_thumbnail' ) : ''; ?></div><p><strong><?php echo esc_html( $item->get_name() ); ?></strong><span>Cantidad: <?php echo esc_html( (string) $item->get_quantity() ); ?></span></p><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div>
			<?php endforeach; ?>
			</div></section>
			<section class="cvd-customer-order-detail__card"><h3>Entrega</h3><div class="cvd-customer-order-detail__delivery"><strong><?php echo esc_html( self::fulfillment_label( $order ) ); ?></strong><?php if ( 'pickup' !== (string) $order->get_meta( '_cvd_fulfillment_type', true ) && $order->get_formatted_shipping_address() ) : ?><p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></p><?php endif; ?></div></section>
			<section class="cvd-customer-order-detail__card"><h3>Seguimiento del pedido</h3>
			<?php if ( $timeline ) : ?><ol class="cvd-customer-order-detail__timeline"><?php foreach ( $timeline as $index => $event ) : ?><li class="<?php echo $index === array_key_last( $timeline ) ? 'is-current' : ''; ?>"><i></i><div><strong><?php echo esc_html( $event['label'] ); ?></strong><?php if ( $event['timestamp'] ) : ?><span><?php echo esc_html( wp_date( 'j M · H:i', strtotime( $event['timestamp'] . ' UTC' ) ) ); ?></span><?php endif; ?></div></li><?php endforeach; ?></ol><?php else : ?><p class="cvd-customer-order-detail__quiet">Estamos preparando la información de seguimiento.</p><?php endif; ?>
			</section>
		</div>
		<?php
	}
}
