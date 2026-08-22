<?php

defined( 'ABSPATH' ) || exit;

/**
 * Checkout orientado a direcciones y formas de compra cubanas.
 *
 * El pedido siempre se guarda en WooCommerce antes de continuar a WhatsApp.
 */
final class CVD_Cuban_Checkout {
	public static function register(): void {
		add_filter( 'woocommerce_states', array( __CLASS__, 'register_provinces' ) );
		add_filter( 'default_checkout_billing_country', array( __CLASS__, 'default_country' ) );
		add_filter( 'default_checkout_shipping_country', array( __CLASS__, 'default_country' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 10, 2 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ) );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
		add_filter( 'woocommerce_cart_needs_shipping', '__return_false' );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'payment_gateways' ) );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'gateway_title' ), 10, 2 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );
		add_filter( 'gettext', array( __CLASS__, 'checkout_text' ), 20, 3 );
		add_filter( 'the_title', array( __CLASS__, 'checkout_title' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_fields' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_fields' ) );
		add_action( 'woocommerce_order_details_after_customer_details', array( __CLASS__, 'customer_order_fields' ) );
	}

	public static function default_country(): string {
		return 'CU';
	}

	public static function checkout_value( $value, string $input ) {
		if ( in_array( $input, array( 'billing_country', 'shipping_country' ), true ) ) {
			return 'CU';
		}
		if ( 'billing_state' === $input && ! array_key_exists( strtoupper( (string) $value ), self::provinces() ) ) {
			return strtoupper( (string) get_option( 'cvd_default_province', 'LH' ) );
		}
		return $value;
	}

	public static function payment_gateways( array $gateways ): array {
		unset( $gateways['cheque'], $gateways['cod'] );
		return $gateways;
	}

	public static function gateway_title( string $title, string $gateway_id ): string {
		if ( 'bacs' === $gateway_id ) {
			return 'Pago por transferencia';
		}
		return $title;
	}

	public static function order_button_text(): string {
		return 'Continuar por WhatsApp';
	}

	public static function checkout_title( string $title, int $post_id ): string {
		if ( ! is_admin() && function_exists( 'wc_get_page_id' ) && $post_id === wc_get_page_id( 'checkout' ) && in_the_loop() ) {
			return 'Completa tu pedido';
		}
		return $title;
	}

	public static function checkout_text( string $translated, string $original, string $domain ): string {
		if ( ! is_admin() && did_action( 'wp' ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			$replacements = array(
				'Billing details'       => 'Datos para recibir tu compra',
				'Detalles de facturación' => 'Datos para recibir tu compra',
				'Additional information' => '¿Alguna indicación adicional?',
				'Información adicional' => '¿Alguna indicación adicional?',
				'Your order'            => 'Resumen de tu pedido',
				'Tu pedido'             => 'Resumen de tu pedido',
			);
			if ( isset( $replacements[ $original ] ) ) {
				return $replacements[ $original ];
			}
			if ( isset( $replacements[ $translated ] ) ) {
				return $replacements[ $translated ];
			}
		}
		return $translated;
	}

	public static function provinces(): array {
		return array(
			'PR' => 'Pinar del Río',
			'AR' => 'Artemisa',
			'LH' => 'La Habana',
			'MY' => 'Mayabeque',
			'MT' => 'Matanzas',
			'CF' => 'Cienfuegos',
			'VC' => 'Villa Clara',
			'SS' => 'Sancti Spíritus',
			'CA' => 'Ciego de Ávila',
			'CM' => 'Camagüey',
			'LT' => 'Las Tunas',
			'HG' => 'Holguín',
			'GR' => 'Granma',
			'SC' => 'Santiago de Cuba',
			'GT' => 'Guantánamo',
			'IJ' => 'Isla de la Juventud',
		);
	}

	public static function municipalities(): array {
		return array(
			'PR' => array( 'Consolación del Sur', 'Guane', 'La Palma', 'Los Palacios', 'Mantua', 'Minas de Matahambre', 'Pinar del Río', 'San Juan y Martínez', 'San Luis', 'Sandino', 'Viñales' ),
			'AR' => array( 'Alquízar', 'Artemisa', 'Bahía Honda', 'Bauta', 'Caimito', 'Candelaria', 'Guanajay', 'Güira de Melena', 'Mariel', 'San Antonio de los Baños', 'San Cristóbal' ),
			'LH' => array( 'Arroyo Naranjo', 'Boyeros', 'Centro Habana', 'Cerro', 'Cotorro', 'Diez de Octubre', 'Guanabacoa', 'Habana del Este', 'Habana Vieja', 'La Lisa', 'Marianao', 'Playa', 'Plaza de la Revolución', 'Regla', 'San Miguel del Padrón' ),
			'MY' => array( 'Batabanó', 'Bejucal', 'Güines', 'Jaruco', 'Madruga', 'Melena del Sur', 'Nueva Paz', 'Quivicán', 'San José de las Lajas', 'San Nicolás', 'Santa Cruz del Norte' ),
			'MT' => array( 'Calimete', 'Cárdenas', 'Ciénaga de Zapata', 'Colón', 'Jagüey Grande', 'Jovellanos', 'Limonar', 'Los Arabos', 'Martí', 'Matanzas', 'Pedro Betancourt', 'Perico', 'Unión de Reyes' ),
			'CF' => array( 'Abreus', 'Aguada de Pasajeros', 'Cienfuegos', 'Cruces', 'Cumanayagua', 'Lajas', 'Palmira', 'Rodas' ),
			'VC' => array( 'Caibarién', 'Camajuaní', 'Cifuentes', 'Corralillo', 'Encrucijada', 'Manicaragua', 'Placetas', 'Quemado de Güines', 'Ranchuelo', 'Remedios', 'Sagua la Grande', 'Santa Clara', 'Santo Domingo' ),
			'SS' => array( 'Cabaiguán', 'Fomento', 'Jatibonico', 'La Sierpe', 'Sancti Spíritus', 'Taguasco', 'Trinidad', 'Yaguajay' ),
			'CA' => array( 'Baraguá', 'Bolivia', 'Chambas', 'Ciego de Ávila', 'Ciro Redondo', 'Florencia', 'Majagua', 'Morón', 'Primero de Enero', 'Venezuela' ),
			'CM' => array( 'Camagüey', 'Carlos Manuel de Céspedes', 'Esmeralda', 'Florida', 'Guáimaro', 'Jimaguayú', 'Minas', 'Najasa', 'Nuevitas', 'Santa Cruz del Sur', 'Sibanicú', 'Sierra de Cubitas', 'Vertientes' ),
			'LT' => array( 'Amancio', 'Colombia', 'Jesús Menéndez', 'Jobabo', 'Las Tunas', 'Majibacoa', 'Manatí', 'Puerto Padre' ),
			'HG' => array( 'Antilla', 'Báguanos', 'Banes', 'Cacocum', 'Calixto García', 'Cueto', 'Frank País', 'Gibara', 'Holguín', 'Mayarí', 'Moa', 'Rafael Freyre', 'Sagua de Tánamo', 'Urbano Noris' ),
			'GR' => array( 'Bartolomé Masó', 'Bayamo', 'Buey Arriba', 'Campechuela', 'Cauto Cristo', 'Guisa', 'Jiguaní', 'Manzanillo', 'Media Luna', 'Niquero', 'Pilón', 'Río Cauto', 'Yara' ),
			'SC' => array( 'Contramaestre', 'Guamá', 'Mella', 'Palma Soriano', 'San Luis', 'Santiago de Cuba', 'Segundo Frente', 'Songo-La Maya', 'Tercer Frente' ),
			'GT' => array( 'Baracoa', 'Caimanera', 'El Salvador', 'Guantánamo', 'Imías', 'Maisí', 'Manuel Tames', 'Niceto Pérez', 'San Antonio del Sur', 'Yateras' ),
			'IJ' => array( 'Isla de la Juventud' ),
		);
	}

	public static function localities(): array {
		if ( class_exists( 'CVD_Shipping_Rates' ) && CVD_Shipping_Rates::localities() ) {
			return CVD_Shipping_Rates::localities();
		}
		return array(
			'Arroyo Naranjo' => array( 'Calvario', 'La Palma', 'Los Pinos', 'Mantilla', 'Poey', 'Párraga', 'Víbora Park' ),
			'Boyeros' => array( 'Altahabana', 'Capdevila', 'Fontanar', 'Mazorra', 'Rancho Boyeros', 'Santiago de las Vegas', 'Wajay' ),
			'Centro Habana' => array( 'Cayo Hueso', 'Colón', 'Dragones', 'Los Sitios', 'Pueblo Nuevo' ),
			'Cerro' => array( 'Casino Deportivo', 'Cerro', 'El Canal', 'Las Cañas', 'Palatino' ),
			'Cotorro' => array( 'Cuatro Caminos', 'Lotería', 'Magdalena', 'San Pedro' ),
			'Diez de Octubre' => array( 'La Víbora', 'Lawton', 'Luyanó', 'Santos Suárez', 'Sevillano', 'Tamarindo' ),
			'Guanabacoa' => array( 'Chibás', 'Guanabacoa', 'Mañana de la Santa Ana', 'Peñalver', 'Villa María' ),
			'Habana del Este' => array( 'Alamar', 'Camilo Cienfuegos', 'Campo Florido', 'Cojímar', 'Guanabo', 'Reparto Guiteras', 'Santa María del Mar' ),
			'Habana Vieja' => array( 'Belén', 'Catedral', 'Jesús María', 'Plaza Vieja', 'Prado' ),
			'La Lisa' => array( 'Alturas de La Lisa', 'Arroyo Arenas', 'El Cano', 'La Coronela', 'San Agustín' ),
			'Marianao' => array( 'Los Pocitos', 'Pogolotti', 'Santa Felicia', 'Zamora' ),
			'Playa' => array( 'Ampliación de Almendares', 'Atabey', 'Buenavista', 'Jaimanitas', 'La Ceiba', 'Miramar', 'Náutico', 'Siboney', 'Santa Fe' ),
			'Plaza de la Revolución' => array( 'El Vedado', 'La Rampa', 'Nuevo Vedado', 'Príncipe', 'Puentes Grandes', 'Vedado-Malecón' ),
			'Regla' => array( 'Casablanca', 'Guaicanamar', 'Loma Modelo', 'Regla' ),
			'San Miguel del Padrón' => array( 'Diezmero', 'Juanelo', 'La Rosita', 'San Francisco de Paula', 'San Miguel del Padrón' ),
		);
	}

	public static function register_provinces( array $states ): array {
		$states['CU'] = self::provinces();
		return $states;
	}

	public static function checkout_fields( array $fields ): array {
		$default_state = strtoupper( (string) get_option( 'cvd_default_province', 'LH' ) );
		$fulfillment = isset( $_POST['billing_cvd_fulfillment_type'] ) ? sanitize_key( wp_unslash( $_POST['billing_cvd_fulfillment_type'] ) ) : 'delivery';
		$is_delivery = 'pickup' !== $fulfillment;
		$posted_state  = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : $default_state;
		$municipalities = self::municipalities();
		$city_options = array( '' => 'Selecciona el municipio' );
		foreach ( $municipalities[ $posted_state ] ?? array() as $municipality ) {
			$city_options[ $municipality ] = $municipality;
		}

		$fields['billing']['billing_cvd_fulfillment_type'] = array(
			'type'     => 'radio',
			'label'    => '¿Cómo quieres recibir tu compra?',
			'options'  => array(
				'delivery' => 'Mensajería',
				'pickup'   => 'Recogida en tienda',
			),
			'default'  => 'delivery',
			'required' => true,
			'class'    => array( 'form-row-wide', 'cvd-fulfillment' ),
			'priority' => 5,
		);
		$fields['billing']['billing_country'] = array(
			'type'     => 'hidden',
			'default'  => 'CU',
			'required' => true,
			'priority' => 10,
		);
		$fields['billing']['billing_first_name']['label'] = 'Nombre de quien recibe';
		$fields['billing']['billing_first_name']['class'] = array( 'form-row-wide' );
		$fields['billing']['billing_first_name']['priority'] = 20;
		$fields['billing']['billing_last_name']['label'] = 'Apellidos de quien recibe';
		$fields['billing']['billing_last_name']['class'] = array( 'form-row-wide' );
		$fields['billing']['billing_last_name']['priority'] = 30;
		$fields['billing']['billing_phone']['label'] = 'WhatsApp o teléfono de quien recibe';
		$fields['billing']['billing_phone']['required'] = true;
		$fields['billing']['billing_phone']['class'] = array( 'form-row-wide' );
		$fields['billing']['billing_phone']['autocomplete'] = 'tel';
		$fields['billing']['billing_phone']['priority'] = 40;
		$fields['billing']['billing_cvd_alternate_phone'] = array(
			'type' => 'tel', 'label' => 'Teléfono alternativo', 'required' => false,
			'class' => array( 'form-row-wide' ), 'priority' => 41, 'autocomplete' => 'tel',
		);
		$fields['billing']['billing_email']['label'] = 'Correo de quien compra';
		$fields['billing']['billing_email']['required'] = false;
		$fields['billing']['billing_email']['class'] = array( 'form-row-wide' );
		$fields['billing']['billing_email']['priority'] = 60;
		$fields['billing']['billing_cvd_buyer_name'] = array(
			'type'        => 'text',
			'label'       => 'Nombre de quien compra o paga',
			'placeholder' => 'Solo si es diferente de quien recibe',
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 50,
		);
		$fields['billing']['billing_state'] = array(
			'type'     => 'select',
			'label'    => 'Provincia',
			'options'  => array( '' => 'Selecciona la provincia' ) + self::provinces(),
			'default'  => $default_state,
			'required' => $is_delivery,
			'class'    => array( 'form-row-wide', 'cvd-delivery-field' ),
			'priority' => 70,
		);
		$fields['billing']['billing_city'] = array(
			'type'     => 'select',
			'label'    => 'Municipio',
			'options'  => $city_options,
			'required' => $is_delivery,
			'class'    => array( 'form-row-wide', 'cvd-delivery-field', 'cvd-municipality-search' ),
			'priority' => 80,
		);
		$fields['billing']['billing_cvd_locality'] = array(
			'type'        => 'text',
			'label'       => 'Reparto o localidad',
			'placeholder' => 'Escribe o elige una sugerencia',
			'required'    => $is_delivery,
			'class'       => array( 'form-row-wide', 'cvd-delivery-field' ),
			'priority'    => 90,
		);
		$fields['billing']['billing_address_1']['label'] = 'Calle, número y edificio';
		$fields['billing']['billing_address_1']['placeholder'] = 'Ej.: Calle 26 #123';
		$fields['billing']['billing_address_1']['required'] = $is_delivery;
		$fields['billing']['billing_address_1']['class'] = array( 'form-row-wide', 'cvd-delivery-field' );
		$fields['billing']['billing_address_1']['autocomplete'] = 'address-line1';
		$fields['billing']['billing_address_1']['priority'] = 100;
		$fields['billing']['billing_address_2']['label'] = 'Entre calles, apartamento o piso';
		$fields['billing']['billing_address_2']['placeholder'] = 'Ej.: entre 41 y 43, apto. 4';
		$fields['billing']['billing_address_2']['class'] = array( 'form-row-wide', 'cvd-delivery-field' );
		$fields['billing']['billing_address_2']['priority'] = 110;
		$fields['billing']['billing_cvd_reference'] = array(
			'type'        => 'text',
			'label'       => 'Punto de referencia para el mensajero',
			'placeholder' => 'Ej.: frente al policlínico',
			'required'    => false,
			'class'       => array( 'form-row-wide', 'cvd-delivery-field' ),
			'priority'    => 120,
		);
		$fields['billing']['billing_cvd_delivery_date'] = array(
			'type' => 'date', 'label' => 'Fecha solicitada de entrega', 'required' => false,
			'class' => array( 'form-row-first', 'cvd-delivery-field' ), 'priority' => 121,
		);
		$fields['billing']['billing_cvd_delivery_window'] = array(
			'type' => 'select', 'label' => 'Horario preferido', 'required' => false,
			'options' => array( '' => 'Sin preferencia', 'morning' => 'Mañana', 'afternoon' => 'Tarde' ),
			'class' => array( 'form-row-last', 'cvd-delivery-field' ), 'priority' => 122,
		);
		$fields['billing']['billing_cvd_change_amount'] = array(
			'type' => 'number', 'label' => 'Necesita vuelto de', 'required' => false,
			'class' => array( 'form-row-first', 'cvd-delivery-field' ), 'priority' => 123,
			'custom_attributes' => array( 'min' => '0', 'step' => '0.01', 'inputmode' => 'decimal' ),
		);
		$fields['billing']['billing_cvd_change_currency'] = array(
			'type' => 'select', 'label' => 'Moneda del vuelto', 'required' => false,
			'options' => array( 'USD' => 'USD', 'CUP' => 'CUP', 'EUR' => 'EUR' ),
			'class' => array( 'form-row-last', 'cvd-delivery-field' ), 'priority' => 124,
		);
		$fields['billing']['billing_cvd_map_url'] = array(
			'type'        => 'url',
			'label'       => 'Ubicación exacta',
			'placeholder' => 'Opcional: pega un enlace de Maps',
			'description' => 'Si estás en el lugar de entrega, puedes usar tu ubicación actual.',
			'required'    => false,
			'class'       => array( 'form-row-wide', 'cvd-delivery-field', 'cvd-map-field' ),
			'priority'    => 130,
		);
		$fields['billing']['billing_cvd_map_accuracy'] = array(
			'type'     => 'hidden',
			'required' => false,
			'priority' => 131,
		);
		unset( $fields['billing']['billing_postcode'], $fields['billing']['billing_company'] );
		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label'] = 'Indicaciones adicionales';
			$fields['order']['order_comments']['placeholder'] = 'Color, horario, acceso al edificio u otra indicación útil.';
		}

		unset( $fields['shipping'] );
		return $fields;
	}

	public static function assets(): void {
		if ( ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'cvd-cuban-checkout', CVD_URL . 'assets/checkout.css', array(), CVD_VERSION );
		if ( is_order_received_page() ) { return; }
		wp_enqueue_script( 'cvd-cuban-checkout', CVD_URL . 'assets/checkout.js', array( 'jquery' ), CVD_VERSION, true );
		wp_localize_script(
			'cvd-cuban-checkout',
			'cvdCheckout',
			array(
				'municipalities' => self::municipalities(),
				'localities'     => self::localities(),
				'defaultState'   => strtoupper( (string) get_option( 'cvd_default_province', 'LH' ) ),
				'pickupAddress'  => get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'addressNonce'   => wp_create_nonce( 'cvd_address_search' ),
			),
		);
	}

	public static function validate( array $data, WP_Error $errors ): void {
		$type = isset( $_POST['billing_cvd_fulfillment_type'] ) ? sanitize_key( wp_unslash( $_POST['billing_cvd_fulfillment_type'] ) ) : '';
		if ( ! in_array( $type, array( 'delivery', 'pickup' ), true ) ) {
			$errors->add( 'cvd_fulfillment', 'Selecciona mensajería o recogida en tienda.' );
		}
		if ( 'delivery' !== $type ) {
			return;
		}
		$phone = isset( $_POST['billing_phone'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['billing_phone'] ) ) : '';
		if ( strlen( $phone ) < 8 ) {
			$errors->add( 'billing_phone', 'Escribe un teléfono válido de quien recibe.' );
		}
		$alternate = isset( $_POST['billing_cvd_alternate_phone'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['billing_cvd_alternate_phone'] ) ) : '';
		if ( $alternate && strlen( $alternate ) < 8 ) { $errors->add( 'billing_cvd_alternate_phone', 'Escribe un teléfono alternativo válido.' ); }
		$date = sanitize_text_field( wp_unslash( $_POST['billing_cvd_delivery_date'] ?? '' ) );
		if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $errors->add( 'billing_cvd_delivery_date', 'Selecciona una fecha de entrega válida.' ); }

		$required = array(
			'billing_state'      => 'Selecciona la provincia de entrega.',
			'billing_city'       => 'Selecciona el municipio de entrega.',
			'billing_cvd_locality' => 'Selecciona o escribe el reparto de entrega.',
			'billing_address_1'  => 'Escribe la calle, el número y el edificio.',
		);
		foreach ( $required as $key => $message ) {
			if ( empty( $_POST[ $key ] ) ) {
				$errors->add( $key, $message );
			}
		}

		$state = isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '';
		$city  = isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '';
		if ( $state && $city && ! in_array( $city, self::municipalities()[ $state ] ?? array(), true ) ) {
			$errors->add( 'billing_city', 'El municipio no pertenece a la provincia seleccionada.' );
		}
	}

	public static function save_order_fields( WC_Order $order, array $data ): void {
		$type = isset( $_POST['billing_cvd_fulfillment_type'] ) ? sanitize_key( wp_unslash( $_POST['billing_cvd_fulfillment_type'] ) ) : 'delivery';
		$state = isset( $data['billing_state'] ) ? sanitize_text_field( $data['billing_state'] ) : '';
		$order->set_billing_country( 'CU' );
		$order->update_meta_data( '_cvd_fulfillment_type', $type );
		$order->update_meta_data( '_cvd_province_name', self::provinces()[ $state ] ?? $state );
		$order->update_meta_data( '_cvd_locality', isset( $_POST['billing_cvd_locality'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_locality'] ) ) : '' );
		$order->update_meta_data( '_cvd_reference', isset( $_POST['billing_cvd_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_reference'] ) ) : '' );
		$order->update_meta_data( '_cvd_buyer_name', isset( $_POST['billing_cvd_buyer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_buyer_name'] ) ) : '' );
		$order->update_meta_data( '_cvd_map_url', isset( $_POST['billing_cvd_map_url'] ) ? esc_url_raw( wp_unslash( $_POST['billing_cvd_map_url'] ) ) : '' );
		$order->update_meta_data( '_cvd_map_accuracy', isset( $_POST['billing_cvd_map_accuracy'] ) ? absint( $_POST['billing_cvd_map_accuracy'] ) : 0 );
		$order->update_meta_data( '_cvd_alternate_phone', sanitize_text_field( wp_unslash( $_POST['billing_cvd_alternate_phone'] ?? '' ) ) );
		$order->update_meta_data( '_cvd_delivery_date', sanitize_text_field( wp_unslash( $_POST['billing_cvd_delivery_date'] ?? '' ) ) );
		$window = sanitize_key( wp_unslash( $_POST['billing_cvd_delivery_window'] ?? '' ) );
		$order->update_meta_data( '_cvd_delivery_window', in_array( $window, array( 'morning', 'afternoon' ), true ) ? $window : '' );
		$change_amount = (float) wc_format_decimal( wp_unslash( $_POST['billing_cvd_change_amount'] ?? 0 ), 2 );
		$change_currency = strtoupper( sanitize_key( wp_unslash( $_POST['billing_cvd_change_currency'] ?? 'USD' ) ) );
		$order->update_meta_data( '_cvd_change_required', $change_amount > 0 ? array( array( 'amount' => $change_amount, 'currency' => in_array( $change_currency, array( 'USD', 'CUP', 'EUR' ), true ) ? $change_currency : 'USD' ) ) : array() );
		if ( 'pickup' === $type ) {
			$order->add_order_note( 'Cliente seleccionó recogida en tienda.' );
		}
	}

	public static function admin_order_fields( WC_Order $order ): void {
		echo '<div class="cvd-order-delivery"><h3>Entrega Casa Viva</h3>';
		echo '<p><strong>Modalidad:</strong> ' . esc_html( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ? 'Recogida en tienda' : 'Mensajería' ) . '</p>';
		$shipping_cup = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		echo '<p><strong>Tarifa guardada:</strong> ' . esc_html( $shipping_cup ? number_format_i18n( $shipping_cup, 0 ) . ' CUP' : 'Por confirmar' ) . '</p>';
		foreach ( array( '_cvd_locality' => 'Reparto/localidad', '_cvd_reference' => 'Referencia', '_cvd_alternate_phone' => 'Teléfono alternativo', '_cvd_delivery_date' => 'Fecha solicitada', '_cvd_delivery_window' => 'Horario solicitado', '_cvd_map_url' => 'Ubicación en mapa', '_cvd_buyer_name' => 'Persona que compra/paga' ) as $key => $label ) {
			$value = $order->get_meta( $key, true );
			if ( $value ) {
				echo '<p><strong>' . esc_html( $label ) . ':</strong> ';
				echo '_cvd_map_url' === $key ? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">Abrir ubicación</a>' : esc_html( $value );
				echo '</p>';
			}
		}
		$change = $order->get_meta( '_cvd_change_required', true ); if ( is_array( $change ) && $change ) { $item = reset( $change ); echo '<p><strong>Vuelto solicitado:</strong> ' . esc_html( wc_format_decimal( $item['amount'] ?? 0, 2 ) . ' ' . ( $item['currency'] ?? '' ) ) . '</p>'; }
		echo '</div>';
	}

	public static function customer_order_fields( WC_Order $order ): void {
		$type = $order->get_meta( '_cvd_fulfillment_type', true );
		echo '<section class="woocommerce-customer-details cvd-customer-delivery"><h2>Entrega</h2><p>';
		if ( 'pickup' === $type ) {
			echo esc_html( 'Recogida en tienda: ' . get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) );
		} else {
			echo esc_html( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_meta( '_cvd_locality', true ), $order->get_billing_city(), $order->get_meta( '_cvd_province_name', true ) ) ) ) );
		}
		echo '</p>';
		$map_url = $order->get_meta( '_cvd_map_url', true );
		if ( $map_url ) { echo '<p><a class="button" href="' . esc_url( $map_url ) . '" target="_blank" rel="noopener">Abrir ubicación en el mapa</a></p>'; }
		echo '</section>';
	}
}
