<?php

defined( 'ABSPATH' ) || exit;

final class CVD_WhatsApp_Gateway {
	public static function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_button' ), 20 );
	}

	public static function add_gateway( array $gateways ): array {
		$gateways[] = 'CVD_Gateway_WhatsApp';
		return $gateways;
	}

	private static function customer_order_url( WC_Order $order ): string {
		if ( ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return '';
		}
		return wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
	}

	public static function thankyou_button( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$url = self::whatsapp_url( $order );
		$order_url = self::customer_order_url( $order );

		echo '<section class="cvd-order-success">';
		echo '<div class="cvd-order-success__icon" aria-hidden="true">✓</div>';
		echo '<h2>Tu pedido está siendo procesado</h2>';
		if ( $url ) {
			echo '<p>El pedido #' . esc_html( $order->get_order_number() ) . ' quedó guardado. Envíalo por WhatsApp para confirmar disponibilidad, envío y pago.</p>';
		} else {
			echo '<p>El pedido #' . esc_html( $order->get_order_number() ) . ' quedó guardado. Casa Viva se comunicará contigo para confirmarlo.</p>';
		}
		echo '<div class="cvd-order-success__actions">';
		if ( $url ) {
			// esc_url() strips encoded CR/LF sequences and destroys WhatsApp line breaks.
			// whatsapp_url() validates the destination; esc_attr() only protects the HTML attribute.
			echo '<a class="button alt cvd-order-success__whatsapp" href="' . esc_attr( $url ) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.7a8.5 8.5 0 0 1-12.6 7.4L3 20.4l1.3-4.7a8.5 8.5 0 1 1 16.2-4Zm-4.7 2.4c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.9 6.9 0 0 1-3.4-3c-.2-.3 0-.4.1-.5l.6-.7c.1-.2.1-.4 0-.5l-.8-2c-.1-.3-.3-.3-.5-.3h-.5c-.2 0-.5.1-.7.3-.2.2-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.5 3.9.6.3 1.1.4 1.5.5.6.2 1.2.2 1.7.1.5-.1 1.4-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.2-.2-.3-.2-.5-.3Z"/></svg><span>Abrir WhatsApp y confirmar</span></a>';
		}
		if ( $order_url ) {
			echo '<a class="button cvd-order-success__order" href="' . esc_url( $order_url ) . '">Ver seguimiento del pedido</a>';
		}
		echo '<a class="button cvd-order-success__shop" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Seguir comprando</a>';
		echo '</div>';
		if ( ! $order_url && ! is_user_logged_in() ) {
			echo '<p class="cvd-order-success__account-note">Si quieres consultar pedidos y seguimiento desde Casa Viva, inicia sesión o crea tu cuenta antes de tu próxima compra.</p>';
		}
		echo '</section>';
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

		$data = CVD_WhatsApp_Receipt_Template::data( $order );
		$receipt = CVD_WhatsApp_Receipt_Template::build_message( $data, $order );
		$order->update_meta_data( '_cvd_receipt_data', wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$order->update_meta_data( '_cvd_whatsapp_receipt_text', $receipt );
		$order->update_meta_data( '_cvd_whatsapp_receipt_template_version', CVD_WhatsApp_Receipt_Template::VERSION );
		$order->save();
		$encoded_message = rawurlencode( $receipt );
		return 'https://wa.me/' . $phone . '?text=' . $encoded_message;
	}
}

class CVD_Gateway_WhatsApp extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'cvd_whatsapp';
		$this->method_title       = 'Confirmar y coordinar por WhatsApp';
		$this->method_description = 'Guarda el pedido y abre el WhatsApp de la gestora o de Casa Viva.';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = 'Confirmar y coordinar por WhatsApp';
		$this->description = 'Guardaremos tu pedido y abriremos WhatsApp para confirmar disponibilidad, mensajería y pago.';
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
				'default' => 'Confirmar y coordinar por WhatsApp',
			),
			'description' => array(
				'title'   => 'Descripción',
				'type'    => 'textarea',
				'default' => 'Guardaremos tu pedido y abriremos WhatsApp para confirmar disponibilidad, mensajería y pago.',
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

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
