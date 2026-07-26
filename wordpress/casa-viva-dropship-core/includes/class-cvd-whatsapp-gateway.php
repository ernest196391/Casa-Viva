<?php

defined( 'ABSPATH' ) || exit;

final class CVD_WhatsApp_Gateway {
	public static function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_action( 'woocommerce_thankyou_cvd_whatsapp', array( __CLASS__, 'thankyou_button' ) );
	}

	public static function add_gateway( array $gateways ): array {
		$gateways[] = 'CVD_Gateway_WhatsApp';
		return $gateways;
	}

	public static function thankyou_button( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$url = self::whatsapp_url( $order );
		if ( ! $url ) {
			echo '<p>Tu pedido fue guardado. Casa Viva se comunicará contigo para confirmarlo.</p>';
			return;
		}

		echo '<p><a class="button alt" href="' . esc_url( $url ) . '">Enviar pedido por WhatsApp</a></p>';
	}

	public static function whatsapp_url( WC_Order $order ): string {
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type = sanitize_key( $order->get_meta( '_cvd_owner_type', true ) );
		$phone    = ( $owner_id && 'gestora' === $owner_type ) ? get_user_meta( $owner_id, '_cvd_whatsapp', true ) : '';
		$phone    = $phone ?: get_option( 'cvd_central_whatsapp', '' );
		$phone    = preg_replace( '/\D+/', '', (string) $phone );

		if ( ! $phone ) {
			return '';
		}

		$lines = array(
			'Hola, quiero confirmar mi pedido de Casa Viva.',
			'Pedido: #' . $order->get_order_number(),
			'Cliente: ' . $order->get_formatted_billing_full_name(),
			'Teléfono: ' . $order->get_billing_phone(),
			'Entrega: ' . wp_strip_all_tags( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ),
			'Productos:',
		);

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$lines[] = '- ' . $item->get_name() . ' × ' . $item->get_quantity() . ': ' . wp_strip_all_tags( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) );
		}

		$lines[] = 'Mensajería: ' . wp_strip_all_tags( wc_price( $order->get_shipping_total(), array( 'currency' => $order->get_currency() ) ) );
		$lines[] = 'Total: ' . wp_strip_all_tags( $order->get_formatted_order_total() );

		return 'https://wa.me/' . $phone . '?text=' . rawurlencode( implode( "\n", $lines ) );
	}
}

class CVD_Gateway_WhatsApp extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'cvd_whatsapp';
		$this->method_title       = 'Confirmar por WhatsApp';
		$this->method_description = 'Guarda el pedido y abre el WhatsApp de la gestora o de Casa Viva.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', 'Confirmar por WhatsApp' );
		$this->description = $this->get_option( 'description', 'Al terminar, enviaremos el resumen al WhatsApp que atenderá tu compra.' );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => 'Activar',
				'type'    => 'checkbox',
				'label'   => 'Activar confirmación por WhatsApp',
				'default' => 'yes',
			),
			'title' => array(
				'title'   => 'Título',
				'type'    => 'text',
				'default' => 'Confirmar por WhatsApp',
			),
			'description' => array(
				'title'   => 'Descripción',
				'type'    => 'textarea',
				'default' => 'Al terminar, enviaremos el resumen al WhatsApp que atenderá tu compra.',
			),
		);
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'on-hold', 'Pendiente de confirmación por WhatsApp.' );
		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$url = CVD_WhatsApp_Gateway::whatsapp_url( $order );

		return array(
			'result'   => 'success',
			'redirect' => $url ?: $this->get_return_url( $order ),
		);
	}
}
