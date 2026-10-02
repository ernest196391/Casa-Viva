<?php

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp Receipt Template.
 *
 * Flujo único: data() obtiene y normaliza datos permitidos; build_message()
 * construye texto plano línea por línea; el gateway codifica el resultado.
 */
final class CVD_WhatsApp_Receipt_Template {
	public const VERSION = '3.2.0';

	public static function render( WC_Order $order ): string {
		return self::build_message( self::data( $order ), $order );
	}

	/**
	 * Plantilla editable única del vale. No aplicar codificación URL aquí.
	 */
	public static function build_message( array $data, ?WC_Order $order = null ): string {
		// Formato Colo Shop: resumen arriba, secciones en mayúsculas y cierre con la referencia.
		$number    = $data['order']['number'];
		$is_pickup = 'pickup' === $data['delivery']['type'];
		$address   = $data['delivery']['address_lines'];
		$units     = array_sum( array_map( static fn( array $item ): int => (int) $item['quantity'], $data['items'] ) );
		$products  = count( $data['items'] );

		$lines = array(
			'🛍️ *PEDIDO CASA VIVA*',
			'*Pedido #' . $number . '*',
			'🟠 *PENDIENTE DE CONFIRMACIÓN*',
			'',
			'*TOTAL · ' . implode( ' + ', $data['totals']['total_lines'] ) . '*',
			$products . ( 1 === $products ? ' producto' : ' productos' ) . ' · ' . $units . ( 1 === $units ? ' unidad' : ' unidades' ),
			$is_pickup ? '🏬 Recogida en tienda' : '🚚 A domicilio' . ( isset( $address[1] ) ? ' · ' . str_replace( ', ', ' · ', $address[1] ) : '' ),
		);

		$items = array();
		foreach ( $data['items'] as $index => $item ) {
			$items[] = ( $index + 1 ) . '. *' . $item['name'] . '* ×' . $item['quantity'] . ' — ' . $item['price'];
			if ( $item['variations'] ) {
				$items[] = '   _' . implode( ' · ', $item['variations'] ) . '_';
			}
		}
		self::section( $lines, 'PRODUCTOS', $items );

		$amounts = array();
		foreach ( $data['totals']['rows'] as $row ) {
			if ( '' === $row['formatted'] ) {
				continue;
			}
			$amounts[] = ( 'shipping' === $row['key'] && $is_pickup ) ? 'Recogida: Sin costo' : $row['label'] . ': ' . $row['formatted'];
		}
		self::section( $lines, 'IMPORTES', $amounts );

		if ( $is_pickup ) {
			self::section( $lines, 'RECOGIDA', array( $address ? '📍 ' . $address[0] : '', 'Te confirmaremos por este chat cuándo estará listo.' ) );
		} else {
			$street = isset( $address[1] ) ? $address[0] : '';
			$place  = isset( $address[1] ) ? $address[1] : ( $address[0] ?? '' );
			self::section(
				$lines,
				'ENTREGA',
				array(
					$place ? '📍 ' . $place : '',
					$street ? 'Dirección: ' . $street : '',
					$data['delivery']['reference'] ? 'Referencia: ' . $data['delivery']['reference'] : '',
					$data['delivery']['action_url'] ? 'Ubicación: ' . $data['delivery']['action_url'] : '',
				)
			);
		}

		self::section(
			$lines,
			'CLIENTE',
			array(
				$data['customer']['name'] ? '👤 ' . $data['customer']['name'] : '',
				$data['customer']['phone'] ? '📞 ' . $data['customer']['phone'] : '',
			)
		);

		if ( ! empty( $data['extras']['tracking_url'] ) ) {
			self::section( $lines, 'SEGUIMIENTO', array( $data['extras']['tracking_url'] ) );
		}

		self::section(
			$lines,
			'PARA CONFIRMAR EL PEDIDO',
			array(
				'1. Disponibilidad de los productos',
				'2. Total final y forma de pago',
				'3. Horario estimado de ' . ( $is_pickup ? 'recogida' : 'entrega' ),
			)
		);
		$lines[] = '';
		$lines[] = '_Pedido registrado en Casa Viva. Conserva la referencia #' . $number . '._';

		$message = implode( "\n", $lines );
		return (string) apply_filters( 'cvd_whatsapp_receipt_text', $message, $order, $data );
	}

	/**
	 * Añade «*TÍTULO*» y sus líneas no vacías, separado por una línea en blanco.
	 */
	private static function section( array &$lines, string $title, array $rows ): void {
		$rows = array_values( array_filter( $rows, static fn( $row ): bool => '' !== (string) $row ) );
		if ( ! $rows ) {
			return;
		}
		$lines[] = '';
		$lines[] = '*' . $title . '*';
		foreach ( $rows as $row ) {
			$lines[] = $row;
		}
	}

	/**
	 * Fuente de verdad normalizada para WhatsApp, paneles, email, PDF e impresión.
	 */
	public static function data( WC_Order $order ): array {
		$fulfillment = 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ? 'pickup' : 'delivery';
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_name = self::clean( $order->get_meta( '_cvd_owner_display_name', true ) );
		if ( ! $owner_name && $owner_id ) {
			$owner_name = self::clean( get_the_author_meta( 'display_name', $owner_id ) );
		}

		$data = array(
			'schema_version' => self::VERSION,
			'locale'         => determine_locale(),
			'brand'          => 'Casa Viva',
			'order'          => array(
				'id'         => $order->get_id(),
				'number'     => self::clean( $order->get_order_number() ),
				'status'     => sanitize_key( $order->get_status() ),
				'created_at' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : '',
			),
			'customer'       => array(
				'name'  => self::clean( $order->get_formatted_billing_full_name() ),
				'phone' => self::phone( $order->get_billing_phone() ),
				'email' => sanitize_email( $order->get_billing_email() ),
			),
			'managers'       => array(
				array(
					'id'   => $owner_id,
					'type' => sanitize_key( $order->get_meta( '_cvd_owner_type', true ) ) ?: 'organic',
					'name' => $owner_name ?: 'Casa Viva · Venta directa',
					'code' => self::clean( $order->get_meta( '_cvd_referral_code', true ) ),
				),
			),
			'delivery'       => self::delivery_data( $order, $fulfillment ),
			'items'          => self::items_data( $order ),
			'totals'         => self::totals_data( $order, $fulfillment ),
			'payment'        => array(
				'id'    => sanitize_key( $order->get_payment_method() ),
				'label' => self::payment_label( $order ),
			),
			'extras'         => array(
				'customer_note' => self::clean( $order->get_customer_note() ),
				'tracking'      => self::clean( $order->get_meta( '_cvd_tracking_number', true ) ),
				'tracking_url'  => 'pickup' === $fulfillment || ! class_exists( 'CVD_Delivery' ) ? '' : CVD_Delivery::tracking_url( $order ),
			),
		);

		return (array) apply_filters( 'cvd_order_receipt_data', $data, $order );
	}

	private static function delivery_data( WC_Order $order, string $fulfillment ): array {
		$is_pickup = 'pickup' === $fulfillment;
		$address_lines = array();

		if ( $is_pickup ) {
			$address_lines[] = self::clean( get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) );
		} else {
			$street = self::join_unique( array( $order->get_billing_address_1(), $order->get_billing_address_2() ) );
			$location = self::join_unique(
				array(
					$order->get_meta( '_cvd_locality', true ),
					$order->get_billing_city(),
					$order->get_meta( '_cvd_province_name', true ),
				)
			);
			$address_lines = array_values( array_filter( array( $street, $location ) ) );
		}

		$map_url = $is_pickup ? '' : esc_url_raw( (string) $order->get_meta( '_cvd_map_url', true ) );
		return array(
			'type'          => $fulfillment,
			'label'         => $is_pickup ? 'Recogida en tienda' : 'Entrega a domicilio',
			'address_lines' => $address_lines,
			'reference'     => $is_pickup ? '' : self::clean( $order->get_meta( '_cvd_reference', true ) ),
			'map_url'       => $map_url,
			'action_url'    => $map_url && class_exists( 'CVD_Receipt_Links' ) ? CVD_Receipt_Links::url( $order, 'map' ) : '',
		);
	}

	/**
	 * Lista blanca: solo nombre, variaciones visibles, cantidad, precio y URL.
	 */
	private static function items_data( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name'       => self::clean( $item->get_name() ),
				'quantity'   => max( 1, (int) $item->get_quantity() ),
				'variations' => self::visible_variations( $product ),
				'price'      => self::money( $order, $item->get_total() ),
				'action_url' => $product && class_exists( 'CVD_Receipt_Links' ) ? CVD_Receipt_Links::url( $order, 'product', (int) $item_id ) : '',
			);
		}
		return $items;
	}

	private static function visible_variations( $product ): array {
		if ( ! $product instanceof WC_Product_Variation ) {
			return array();
		}

		$visible = array();
		foreach ( $product->get_variation_attributes() as $attribute => $value ) {
			$attribute = str_replace( 'attribute_', '', (string) $attribute );
			$label = self::clean( wc_attribute_label( $attribute, $product ) );
			$value = self::variation_value( $attribute, $value );
			if ( $label && $value ) {
				$visible[] = $label . ': ' . $value;
			}
		}
		return $visible;
	}

	private static function variation_value( string $attribute, $value ): string {
		$value = self::clean( $value );
		if ( $value && taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $value, $attribute );
			if ( $term && ! is_wp_error( $term ) ) {
				return self::clean( $term->name );
			}
		}
		return $value;
	}

	private static function totals_data( WC_Order $order, string $fulfillment ): array {
		$shipping_cup = class_exists( 'CVD_Shipping_Rates' )
			? CVD_Shipping_Rates::order_fee( $order )
			: absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		$rows = array(
			array( 'key' => 'products', 'label' => 'Productos', 'formatted' => self::money( $order, $order->get_subtotal() ) ),
		);
		if ( (float) $order->get_discount_total() > 0 ) {
			$rows[] = array( 'key' => 'discount', 'label' => 'Descuentos', 'formatted' => '-' . self::money( $order, $order->get_discount_total() ) );
		}
		if ( $order->get_coupon_codes() ) {
			$rows[] = array( 'key' => 'coupons', 'label' => 'Cupones', 'formatted' => self::clean( implode( ', ', $order->get_coupon_codes() ) ) );
		}
		if ( (float) $order->get_total_tax() > 0 ) {
			$rows[] = array( 'key' => 'tax', 'label' => 'Impuestos', 'formatted' => self::money( $order, $order->get_total_tax() ) );
		}

		$shipping_label = 'pickup' === $fulfillment ? 'No aplica' : ( $shipping_cup ? self::cup( $shipping_cup ) : 'Por confirmar' );
		$rows[] = array( 'key' => 'shipping', 'label' => 'Mensajería', 'formatted' => $shipping_label );
		$total_lines = array( self::money( $order, $order->get_total() ) );
		if ( 'pickup' !== $fulfillment && $shipping_cup ) {
			$total_lines[] = self::cup( $shipping_cup );
		}

		return array( 'rows' => $rows, 'total_lines' => $total_lines );
	}

	private static function payment_label( WC_Order $order ): string {
		$label = self::clean( $order->get_payment_method_title() );
		if ( 'cvd_whatsapp' === $order->get_payment_method() || ! $label ) {
			return 'A coordinar por WhatsApp';
		}
		return $label;
	}

	private static function money( WC_Order $order, $amount ): string {
		return number_format( (float) $amount, 2, '.', ',' ) . ' ' . self::clean( $order->get_currency() );
	}

	private static function cup( $amount ): string {
		return number_format( (float) $amount, 0, ',', '.' ) . ' CUP';
	}

	private static function phone( $phone ): string {
		$raw = self::clean( $phone );
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 8 === strlen( $digits ) ) {
			return '+53 ' . $digits;
		}
		if ( 10 === strlen( $digits ) && str_starts_with( $digits, '53' ) ) {
			return '+53 ' . substr( $digits, 2 );
		}
		return $raw;
	}

	private static function join_unique( array $values ): string {
		$clean = array();
		$seen = array();
		foreach ( $values as $value ) {
			$value = self::clean( $value );
			$key = strtolower( remove_accents( $value ) );
			if ( $value && ! isset( $seen[ $key ] ) ) {
				$clean[] = $value;
				$seen[ $key ] = true;
			}
		}
		return implode( ', ', $clean );
	}

	private static function clean( $value ): string {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[\r\n\t]+/u', ' ', $value );
		return trim( preg_replace( '/\s{2,}/u', ' ', $value ) );
	}
}
