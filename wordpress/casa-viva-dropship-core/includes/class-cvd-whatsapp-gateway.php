<?php

defined( 'ABSPATH' ) || exit;

final class CVD_WhatsApp_Gateway {
	public static function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_filter( 'wc_get_template', array( __CLASS__, 'thankyou_template' ), 20, 2 );
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

	/**
	 * Sustituye la plantilla nativa (tarjetas de resumen, tabla y facturación)
	 * por una sola página ordenada al estilo Colo Shop.
	 */
	public static function thankyou_template( string $template, string $template_name ): string {
		if ( 'checkout/thankyou.php' === $template_name ) {
			return CVD_DIR . 'templates/checkout/thankyou.php';
		}
		return $template;
	}

	public static function thankyou_button( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$url       = self::whatsapp_url( $order );
		$order_url = self::customer_order_url( $order );
		$is_pickup = 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true );
		$currency  = array( 'currency' => $order->get_currency() );
		$fee       = $is_pickup || ! class_exists( 'CVD_Shipping_Rates' ) ? 0 : CVD_Shipping_Rates::order_fee( $order );

		echo '<section class="cvd-thanks" aria-label="Pedido recibido">';
		echo '<p class="cvd-thanks__ref">✅ Pedido #' . esc_html( $order->get_order_number() ) . ' finalizado</p>';
		if ( $url ) {
			// Lennys: dejar claro que falta un último paso (mandar el vale) y que el pedido ya está hecho.
			echo '<p class="cvd-thanks__lead" data-cvd-thanks-lead><strong>Último paso:</strong> envía el vale por WhatsApp. Así lo tienes en tu chat y te avisamos de todo: quién lo lleva, a qué hora y cuándo se entregó.</p>';
			// esc_url() strips encoded CR/LF sequences and destroys WhatsApp line breaks.
			// whatsapp_url() validates the destination; esc_attr() only protects the HTML attribute.
			echo '<a class="cvd-thanks__whatsapp" href="' . esc_attr( $url ) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.7a8.5 8.5 0 0 1-12.6 7.4L3 20.4l1.3-4.7a8.5 8.5 0 1 1 16.2-4Zm-4.7 2.4c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.9 6.9 0 0 1-3.4-3c-.2-.3 0-.4.1-.5l.6-.7c.1-.2.1-.4 0-.5l-.8-2c-.1-.3-.3-.3-.5-.3h-.5c-.2 0-.5.1-.7.3-.2.2-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.5 3.9.6.3 1.1.4 1.5.5.6.2 1.2.2 1.7.1.5-.1 1.4-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.2-.2-.3-.2-.5-.3Z"/></svg><span>Confirmar por WhatsApp</span></a>';
			echo '<p class="cvd-thanks__hint">Se abre con el vale del pedido listo para enviar.</p>';
		} else {
			echo '<p class="cvd-thanks__lead">Casa Viva te escribirá para confirmarlo.</p>';
		}

		echo '<div class="cvd-thanks__card"><h2 class="cvd-thanks__heading">Tu pedido</h2><ul class="cvd-thanks__lines">';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$details = array();
			foreach ( $item->get_formatted_meta_data() as $meta ) {
				$details[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value );
			}
			echo '<li><span class="cvd-thanks__qty">' . esc_html( $item->get_quantity() ) . ' ×</span><span class="cvd-thanks__name">' . esc_html( $item->get_name() );
			if ( $details ) {
				echo '<small>' . esc_html( implode( ' · ', $details ) ) . '</small>';
			}
			echo '</span><strong>' . wp_kses_post( wc_price( $item->get_subtotal(), $currency ) ) . '</strong></li>';
		}
		echo '</ul><dl class="cvd-thanks__totals">';
		self::total_row( 'Productos', wc_price( $order->get_subtotal(), $currency ) );
		if ( (float) $order->get_discount_total() > 0 ) {
			self::total_row( 'Descuento', '-' . wc_price( $order->get_discount_total(), $currency ) );
		}
		self::total_row( $is_pickup ? 'Recogida' : 'Mensajería', $is_pickup ? 'Sin costo' : ( $fee ? esc_html( number_format_i18n( $fee, 0 ) . ' CUP' ) : 'Por confirmar' ) );
		self::total_row( 'Total', wc_price( $order->get_total(), $currency ) . ( $fee ? '<small>+ ' . esc_html( number_format_i18n( $fee, 0 ) ) . ' CUP de mensajería</small>' : '' ), 'is-total' );
		echo '</dl></div>';

		self::delivery_card( $order, $is_pickup );

		echo '<div class="cvd-thanks__secondary">';
		if ( $order_url ) {
			echo '<a class="cvd-thanks__button" href="' . esc_url( $order_url ) . '">Ver seguimiento</a>';
		}
		echo '<a class="cvd-thanks__button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Seguir comprando</a>';
		echo '</div>';
		if ( ! $order_url && ! is_user_logged_in() ) {
			echo '<p class="cvd-thanks__note">Para ver tus pedidos aquí, inicia sesión o crea tu cuenta antes de tu próxima compra.</p>';
		}
		echo '</section>';
	}

	private static function total_row( string $label, string $value_html, string $class = '' ): void {
		echo '<div' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value_html ) . '</dd></div>';
	}

	private static function delivery_card( WC_Order $order, bool $is_pickup ): void {
		$who = array_filter( array( $order->get_formatted_billing_full_name(), $order->get_billing_phone() ) );
		echo '<div class="cvd-thanks__card"><h2 class="cvd-thanks__heading">' . ( $is_pickup ? 'Recogida en tienda' : 'Entrega a domicilio' ) . '</h2>';
		if ( $who ) {
			echo '<p class="cvd-thanks__who">' . esc_html( implode( ' · ', $who ) ) . '</p>';
		}
		if ( $is_pickup ) {
			echo '<p>' . esc_html( get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) ) . '</p>';
			echo '<p class="cvd-thanks__muted">Te avisamos por WhatsApp cuándo estará listo.</p>';
		} else {
			$address = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_meta( '_cvd_locality', true ), $order->get_billing_city(), $order->get_meta( '_cvd_province_name', true ) ) );
			echo '<p>' . esc_html( implode( ', ', array_map( static fn( $part ) => trim( (string) $part, " ,\t" ), $address ) ) ) . '</p>';
			$reference = (string) $order->get_meta( '_cvd_reference', true );
			if ( $reference ) {
				echo '<p class="cvd-thanks__muted">Referencia: ' . esc_html( $reference ) . '</p>';
			}
			$map_url = (string) $order->get_meta( '_cvd_map_url', true );
			if ( $map_url ) {
				echo '<a class="cvd-thanks__link" href="' . esc_url( $map_url ) . '" target="_blank" rel="noopener">Ver ubicación en el mapa</a>';
			}
		}
		echo '</div>';
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
