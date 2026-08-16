<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Customer_Order_Support {
	public static function register(): void {
		add_action( 'woocommerce_account_view-order_endpoint', array( __CLASS__, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
	}

	private static function customer_order( int $order_id ): ?WC_Order {
		if ( ! is_user_logged_in() ) { return null; }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) { return null; }
		return $order;
	}

	private static function support_phone( WC_Order $order ): string {
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
		$phone = ( $owner_id && 'gestora' === $owner_type ) ? (string) get_user_meta( $owner_id, '_cvd_whatsapp', true ) : '';
		$phone = $phone ?: (string) get_option( 'cvd_central_whatsapp', '' );
		return preg_replace( '/\D+/', '', $phone );
	}

	private static function customer_stage( WC_Order $order ): string {
		if ( class_exists( 'CVD_Canonical_Order_Reader' ) ) {
			$state = CVD_Canonical_Order_Reader::read( $order );
			$stage = strtoupper( (string) ( $state['canonical_stage'] ?? '' ) );
			$labels = array(
				'CREATED' => 'Pedido recibido', 'CONFIRMED' => 'Pedido confirmado', 'PREPARING' => 'Preparando pedido',
				'READY_FOR_COURIER' => 'Listo para mensajería', 'READY_FOR_PICKUP' => 'Listo para recoger',
				'COURIER_ASSIGNED' => 'Mensajero asignado', 'COURIER_GOING_TO_PICKUP' => 'Mensajero va a recoger',
				'PICKED_UP' => 'Pedido recogido', 'ON_THE_WAY_TO_CUSTOMER' => 'En camino', 'DELIVERED' => 'Entregado',
				'PAYMENT_RECONCILED' => 'Entrega conciliada', 'COMPLETED' => 'Completado', 'CANCELLED' => 'Cancelado',
				'DELIVERY_FAILED' => 'Entrega no completada', 'CONFLICT' => 'En revisión',
			);
			if ( isset( $labels[ $stage ] ) ) { return $labels[ $stage ]; }
		}
		return wc_get_order_status_name( $order->get_status() );
	}

	private static function whatsapp_url( WC_Order $order, string $phone ): string {
		$message = sprintf(
			'Hola, necesito ayuda con mi pedido #%s. Estado: %s.',
			$order->get_order_number(),
			self::customer_stage( $order )
		);
		return 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) { return; }
		$order = self::customer_order( absint( get_query_var( 'view-order' ) ) );
		if ( ! $order ) { return; }
		wp_enqueue_style( 'cvd-customer-order-support', CVD_URL . 'assets/customer-order-support.css', array( 'cvd-customer-orders' ), CVD_VERSION );
	}

	public static function render( $order_id ): void {
		$order = self::customer_order( absint( $order_id ) );
		if ( ! $order ) { return; }
		$phone = self::support_phone( $order );
		?>
		<section class="cvd-customer-order-support" data-cvd-customer-order-support="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<div class="cvd-customer-order-support__copy">
				<p>¿Necesitas ayuda?</p>
				<h3>Habla con Casa Viva sobre este pedido</h3>
				<span>El mensaje incluirá automáticamente el número del pedido y su estado visible.</span>
			</div>
			<?php if ( $phone ) : ?>
				<div class="cvd-customer-order-support__actions">
					<a class="cvd-customer-order-support__primary" data-cvd-support-whatsapp href="<?php echo esc_attr( self::whatsapp_url( $order, $phone ) ); ?>" target="_blank" rel="noopener noreferrer">Escribir por WhatsApp</a>
					<a class="cvd-customer-order-support__secondary" data-cvd-support-call href="<?php echo esc_attr( 'tel:+' . $phone ); ?>">Llamar</a>
				</div>
			<?php else : ?>
				<p class="cvd-customer-order-support__unavailable">Casa Viva se comunicará contigo desde los datos de contacto asociados a tu compra.</p>
			<?php endif; ?>
		</section>
		<?php
	}
}
