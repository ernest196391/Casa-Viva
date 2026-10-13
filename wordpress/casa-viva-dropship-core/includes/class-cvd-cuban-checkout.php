<?php

defined( 'ABSPATH' ) || exit;

/**
 * Checkout orientado a direcciones y formas de compra cubanas.
 *
 * El pedido siempre se guarda en WooCommerce antes de continuar a WhatsApp.
 */
final class CVD_Cuban_Checkout {
	/** Roles del equipo (venden o trabajan para la tienda). Los usan keep_team_profile() y el selector de mensajero (3.13.62). */
	public const TEAM_ROLES = array( 'cvd_gestora', 'cvd_influencer', 'cvd_messenger', 'administrator', 'shop_manager' );
	/** Notas para el pedido que se está creando: en woocommerce_checkout_create_order aún no tiene número y add_order_note() no guardaría nada. */
	private static array $pending_notes = array();
	/**
	 * 3.13.67 (Lennys, 12-oct): «¿Cómo paga el cliente?». Cada forma de pago dice en qué moneda se cobra y qué tasa
	 * del día usa (CVD_Daily_Rates: lo que paga el cliente por cada 1 USD del precio; `rate` vacío = 1:1).
	 * Transferencia CUP: el cliente transfiere a la tarjeta que le da el MENSAJERO y le enseña la captura; el mensajero
	 * luego le da el dinero a la tienda. Zelle y PayPal (prepaid): consulta antes con la tienda, el cliente paga TODO antes
	 * y manda la captura, la tienda lo verifica antes de coordinar la entrega y no hay devolución; el mensajero solo cobra
	 * su mensajería. La mensajería siempre se paga aparte, en CUP, al mensajero.
	 */
	public const PAYMENT_KINDS = array(
		'cash_usd'     => array( 'label' => 'Efectivo USD', 'option' => 'Efectivo en dólares (USD)', 'currency' => 'USD', 'rate' => '' ),
		'cash_cup'     => array( 'label' => 'Efectivo CUP', 'option' => 'Efectivo en pesos (CUP)', 'currency' => 'CUP', 'rate' => 'cup' ),
		'transfer_cup' => array( 'label' => 'Transferencia CUP', 'option' => 'Transferencia en CUP (a la tarjeta que da el mensajero)', 'currency' => 'CUP', 'rate' => 'cup' ),
		'eur'          => array( 'label' => 'Euros (EUR)', 'option' => 'Euros (EUR) en efectivo', 'currency' => 'EUR', 'rate' => 'eur' ),
		'clasica'      => array( 'label' => 'Tarjeta Clásica', 'option' => 'Tarjeta Clásica (transferencia en el momento)', 'currency' => 'USD', 'rate' => 'clasica' ),
		'zelle'        => array( 'label' => 'Zelle', 'option' => 'Zelle (paga todo antes)', 'currency' => 'USD', 'rate' => 'zelle', 'prepaid' => true ),
		'paypal'       => array( 'label' => 'PayPal', 'option' => 'PayPal (paga todo antes)', 'currency' => 'USD', 'rate' => 'paypal_pct', 'prepaid' => true ),
	);
	/** Formas de pago en efectivo: las únicas en las que el cliente puede pedir vuelto. */
	public const CASH_KINDS = array( 'cash_usd', 'cash_cup', 'eur' );
	public const PREPAID_NOTICE = 'Consulta antes con la tienda; el cliente paga todo antes y manda la captura.';
	/**
	 * Respaldo SOLO si todavía no hay ninguna tasa guardada de ese tipo: lo que publicó la tienda el 12-oct
	 * (1 EUR = 1 USD, Clásica ×1,15, Zelle ×1,04, PayPal +4 %). El pedido queda marcado «tasa por confirmar».
	 * La CUP no tiene respaldo: cambia cada día (sin tasa, el vale dice «a la tasa de hoy, pregúntala a la tienda»).
	 */
	private const PAYMENT_FALLBACK = array( 'eur' => 1.0, 'clasica' => 1.15, 'zelle' => 1.04, 'paypal_pct' => 4.0 );
	/**
	 * 3.13.70 (Ernesto, 13-oct): «Armado por el mensajero», solo con mensajería. Se cobra APARTE: el cliente se lo paga al
	 * mensajero, como la mensajería; no entra en el total de WooCommerce. El pedido guarda _cvd_assembly (small|large) y
	 * _cvd_assembly_usd (5|10): esos nombres los lee VivaBot. El mensajero lo ve antes de coger la carrera; si no quiere
	 * armar, no la coge y la coge otro.
	 */
	public const ASSEMBLY = array(
		'small' => array( 'usd' => 5, 'label' => 'producto pequeño', 'option' => 'Sí, producto pequeño (5 USD)' ),
		'large' => array( 'usd' => 10, 'label' => 'producto grande', 'option' => 'Sí, producto grande (10 USD)' ),
	);
	/** Sin prefijo billing_: no se copia al perfil del cliente (cada pedido se elige de nuevo). */
	public const ASSEMBLY_FIELD = 'cvd_assembly';
	public static function register(): void {
		add_filter( 'woocommerce_states', array( __CLASS__, 'register_provinces' ) );
		add_filter( 'default_checkout_billing_country', array( __CLASS__, 'default_country' ) );
		add_filter( 'default_checkout_shipping_country', array( __CLASS__, 'default_country' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 10, 2 );
		add_filter( 'woocommerce_checkout_update_customer_data', array( __CLASS__, 'keep_team_profile' ) );
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
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'flush_notes' ), 5 );
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
		// 3.13.67: la forma de pago se elige cada vez (Zelle/PayPal piden consultar antes): nunca se precarga del pedido anterior.
		if ( 'billing_cvd_payment_kind' === $input || self::ASSEMBLY_FIELD === $input ) { // 3.13.70: el armado, igual
			return '';
		}
		if ( 'billing_state' === $input && ! array_key_exists( strtoupper( (string) $value ), self::provinces() ) ) {
			return strtoupper( (string) get_option( 'cvd_default_province', 'LH' ) );
		}
		return $value;
	}

	/**
	 * 3.13.58: una gestora (o alguien del equipo) que compra para un cliente no guarda los datos del CLIENTE en su
	 * propia cuenta. Antes el teléfono del cliente quedaba como teléfono de la gestora y el bot lo trataba como
	 * gestora (8 cuentas afectadas el 12-oct, entre ellas Lennys con el cliente de los faroles).
	 */
	public static function keep_team_profile( $update ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) { return $update; }
		$team = array_intersect( (array) $user->roles, self::TEAM_ROLES );
		return $team ? false : $update;
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
			return is_order_received_page() ? 'Tu pedido está listo' : 'Completa tu pedido';
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
		// Opcional (Dany, 12-oct): muchos clientes solo dan el nombre.
		$fields['billing']['billing_last_name']['label'] = 'Apellidos de quien recibe'; // WooCommerce ya añade «(opcional)»
		$fields['billing']['billing_last_name']['required'] = false;
		$fields['billing']['billing_last_name']['class'] = array( 'form-row-wide' );
		$fields['billing']['billing_last_name']['priority'] = 30;
		$fields['billing']['billing_phone']['label'] = 'WhatsApp o teléfono de quien recibe';
		// 3.13.69 (Dany, 13-oct): con «Recogida en tienda» el teléfono es opcional (el cliente pasa por la tienda);
		// con mensajería sigue siendo obligatorio. checkout.js cambia el asterisco por «(opcional)» al elegir recogida.
		$fields['billing']['billing_phone']['required'] = $is_delivery;
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
			'placeholder' => 'Toca y elige tu reparto de la lista',
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
			// Día de entrega (3.13.51): vacío = lo antes posible. Antes solo se veía la franja y un mensajero cogió para hoy un pedido de mañana.
			'type' => 'date', 'label' => 'Día de entrega', 'required' => false, 'description' => 'Vacío = lo antes posible.',
			'class' => array( 'form-row-first', 'cvd-delivery-field' ), 'priority' => 121,
			'custom_attributes' => array( 'min' => CVD_Delivery_When::min_date(), 'max' => CVD_Delivery_When::max_date() ),
		);
		$fields['billing']['billing_cvd_delivery_window'] = array(
			'type' => 'select', 'label' => 'Horario', 'required' => false,
			// 3.13.54: «Por la mañana», no «Mañana» a secas: en la prueba grabada se confundía con el DÍA de mañana.
			'options' => array( '' => 'Sin preferencia', 'morning' => 'Por la mañana', 'afternoon' => 'Por la tarde' ),
			'class' => array( 'form-row-last', 'cvd-delivery-field' ), 'priority' => 122,
		);
		// 3.13.67 (Lennys, 12-oct): forma de pago, obligatoria con mensajería. checkout.js enseña cuánto es con la tasa de hoy
		// y, en Zelle/PayPal, el aviso de consultar antes con la tienda. El vuelto (abajo) solo sale si paga en efectivo.
		$fields['billing']['billing_cvd_payment_kind'] = array(
			'type' => 'select', 'label' => '¿Cómo paga el cliente?', 'required' => $is_delivery,
			'options' => array( '' => 'Elige cómo paga' ) + array_map( static fn( array $kind ): string => $kind['option'], self::PAYMENT_KINDS ),
			// 3.13.74 (Dany): también en la recogida en tienda, ahí opcional (sin cvd-delivery-field: no se oculta).
			'class' => array( 'form-row-wide', 'cvd-payment-kind' ), 'priority' => 123,
		);
		$fields['billing']['billing_cvd_change_amount'] = array(
			'type' => 'number', 'label' => '¿Con cuánto paga los productos? (para llevarle el vuelto)', 'placeholder' => 'Ej. 20 · vacío si paga exacto', 'required' => false,
			'class' => array( 'form-row-first', 'cvd-delivery-field', 'cvd-change-field' ), 'priority' => 124,
			'custom_attributes' => array( 'min' => '0', 'step' => '0.01', 'inputmode' => 'decimal' ),
		);
		$fields['billing']['billing_cvd_change_currency'] = array(
			'type' => 'select', 'label' => 'Moneda en que paga', 'required' => false,
			'options' => array( '' => 'Elige la moneda', 'USD' => 'Dólares (USD)', 'CUP' => 'Pesos cubanos (CUP)', 'EUR' => 'Euros (EUR)' ), // sin moneda preseleccionada (prueba: 2 USD salieron como 2 CUP)
			'class' => array( 'form-row-last', 'cvd-delivery-field', 'cvd-change-field' ), 'priority' => 125,
		);
		// 3.13.70: armado por el mensajero (solo mensajería: cvd-delivery-field se oculta con «Recogida en tienda»).
		// 3.13.89: solo si en el carrito hay algo que se arma (assembly_offered()).
		if ( self::assembly_offered() ) {
			$fields['billing'][ self::ASSEMBLY_FIELD ] = array(
				'type' => 'select', 'label' => 'Armado por el mensajero', 'required' => false,
				'options' => array( '' => 'No' ) + array_map( static fn( array $a ): string => $a['option'], self::ASSEMBLY ), 'default' => '',
				'description' => '¿Hay que armar el producto? Se le paga aparte al mensajero, como la mensajería: no se suma al total.',
				'class' => array( 'form-row-wide', 'cvd-delivery-field', 'cvd-assembly-field' ), 'priority' => 126,
			);
		}
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
		// 3.13.62 (12-oct, petición de Ernesto): la gestora (o alguien del equipo) elige al hacer el pedido qué mensajero
		// lo lleva. Solo lo ve el equipo: un cliente nunca ve nombres de mensajeros. Es de mensajería (cvd-delivery-field:
		// checkout.js lo oculta con «Recogida en tienda»). Sin prefijo billing_: no se copia al perfil ni a otro meta.
		// Se guarda en CVD_Direct_Dispatch::from_checkout().
		if ( class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::can_choose_at_checkout() ) {
			$messenger_options = CVD_Direct_Dispatch::checkout_options();
			if ( count( $messenger_options ) > 1 ) {
				$fields['billing'][ CVD_Direct_Dispatch::CHECKOUT_FIELD ] = array(
					'type' => 'select', 'label' => '¿Tienes algún mensajero preferido?', 'required' => false,
					'options' => $messenger_options, 'default' => '0',
					'description' => 'Si eliges uno, el pedido le llega directo a él y no sale al grupo.',
					'class' => array( 'form-row-wide', 'cvd-delivery-field' ), 'priority' => 132,
				);
			}
		}
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
				'payment'        => self::payment_script_data(),
			),
		);
	}

	public static function validate( array $data, WP_Error $errors ): void {
		$type = isset( $_POST['billing_cvd_fulfillment_type'] ) ? sanitize_key( wp_unslash( $_POST['billing_cvd_fulfillment_type'] ) ) : '';
		if ( ! in_array( $type, array( 'delivery', 'pickup' ), true ) ) {
			$errors->add( 'cvd_fulfillment', 'Selecciona mensajería o recogida en tienda.' );
		}
		$phone = isset( $_POST['billing_phone'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['billing_phone'] ) ) : '';
		if ( 'delivery' !== $type ) {
			// Recogida (3.13.69): el teléfono puede ir vacío, pero si lo escriben tiene que estar completo.
			if ( '' !== $phone && strlen( $phone ) < 8 ) {
				$errors->add( 'billing_phone', 'Escribe el teléfono completo (8 cifras) o déjalo vacío: en la recogida en tienda es opcional.' );
			}
			// 3.13.74: «¿Cómo paga el cliente?» es opcional en la recogida; si lo eligen, tiene que ser de la lista.
			$pickup_kind = sanitize_key( wp_unslash( $_POST['billing_cvd_payment_kind'] ?? '' ) );
			if ( '' !== $pickup_kind && ! isset( self::PAYMENT_KINDS[ $pickup_kind ] ) ) { $errors->add( 'billing_cvd_payment_kind', 'Elige cómo paga el cliente de la lista, o déjalo vacío.' ); }
			return;
		}
		if ( strlen( $phone ) < 8 ) {
			$errors->add( 'billing_phone', 'Escribe un teléfono válido de quien recibe.' );
		}
		$alternate = isset( $_POST['billing_cvd_alternate_phone'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['billing_cvd_alternate_phone'] ) ) : '';
		if ( $alternate && strlen( $alternate ) < 8 ) { $errors->add( 'billing_cvd_alternate_phone', 'Escribe un teléfono alternativo válido.' ); }
		$date = sanitize_text_field( wp_unslash( $_POST['billing_cvd_delivery_date'] ?? '' ) );
		$date_error = CVD_Delivery_When::error( $date );
		if ( $date_error ) { $errors->add( 'billing_cvd_delivery_date', $date_error ); }
		// 3.13.67: forma de pago obligatoria con mensajería (con nuestro mensaje, no el genérico de WooCommerce).
		$errors->remove( 'billing_cvd_payment_kind_required' );
		$kind = sanitize_key( wp_unslash( $_POST['billing_cvd_payment_kind'] ?? '' ) );
		if ( ! isset( self::PAYMENT_KINDS[ $kind ] ) ) { $errors->add( 'billing_cvd_payment_kind', 'Elige cómo paga el cliente: efectivo (USD o CUP), transferencia CUP, euros, Tarjeta Clásica, Zelle o PayPal.' ); }
		$assembly = sanitize_key( wp_unslash( $_POST[ self::ASSEMBLY_FIELD ] ?? '' ) );
		if ( '' !== $assembly && 'no' !== $assembly && ! isset( self::ASSEMBLY[ $assembly ] ) ) { $errors->add( self::ASSEMBLY_FIELD, 'Elige si hay que armar el producto: No, pequeño (5 USD) o grande (10 USD).' ); }
		// El vuelto solo cuenta en efectivo, y en la moneda en que paga (la de la forma de pago).
		$change_amount = (float) wc_format_decimal( wp_unslash( $_POST['billing_cvd_change_amount'] ?? 0 ), 2 );
		$cash_currency = in_array( $kind, self::CASH_KINDS, true ) ? self::PAYMENT_KINDS[ $kind ]['currency'] : '';
		$products_total = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
		$due = '' !== $cash_currency ? self::payment_amount( $products_total, self::payment_factors()[ $kind ]['factor'], $cash_currency ) : null;
		if ( $change_amount > 0 && null !== $due && $change_amount < $due ) {
			$errors->add( 'billing_cvd_change_amount', sprintf( 'Con %s no alcanza: los productos cuestan %s. Escribe el billete con el que paga (o déjalo vacío si paga exacto).', self::money( $change_amount, $cash_currency ), self::money( $due, $cash_currency ) ) );
		}

		$required = array(
			'billing_state'      => 'Selecciona la provincia de entrega.',
			'billing_city'       => 'Selecciona el municipio de entrega.',
			'billing_cvd_locality' => 'Elige el reparto de entrega de la lista.',
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
		$order->update_meta_data( '_cvd_buyer_name', isset( $_POST['billing_cvd_buyer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_buyer_name'] ) ) : '' );
		$order->update_meta_data( '_cvd_alternate_phone', sanitize_text_field( wp_unslash( $_POST['billing_cvd_alternate_phone'] ?? '' ) ) );
		// Pedido de prueba automático (3.13.54): «PRUEBA» en el nombre o la nota → nunca sale al reparto (ver CVD_Delivery::is_test_order).
		if ( class_exists( 'CVD_Delivery' ) && ! CVD_Delivery::is_test_order( $order ) && CVD_Delivery::looks_like_test(
			(string) ( $data['billing_first_name'] ?? '' ), (string) ( $data['billing_last_name'] ?? '' ), (string) ( $data['order_comments'] ?? '' ),
			sanitize_text_field( wp_unslash( $_POST['billing_cvd_buyer_name'] ?? '' ) )
		) ) {
			$order->update_meta_data( CVD_Delivery::TEST_META, 'auto' );
			self::note( $order, 'Pedido de prueba (el nombre o la nota dicen «PRUEBA»): no sale al reparto.' );
		}
		if ( 'pickup' === $type ) {
			// Recogida: el formulario puede traer precargados dirección y vuelto de pedidos anteriores
			// (campos ocultos). No se guardan en el pedido ni salen en el vale (mejora #23).
			$order->set_billing_address_1( '' );
			$order->set_billing_address_2( '' );
			$order->set_billing_city( '' );
			$order->set_billing_state( '' );
			foreach ( array( '_cvd_province_name', '_cvd_locality', '_cvd_reference', '_cvd_map_url', '_cvd_map_accuracy', '_cvd_delivery_date', '_cvd_delivery_window', '_cvd_pay_with', '_cvd_payment_kind', '_cvd_payment_currency', '_cvd_payment_amount', '_cvd_payment_factor', '_cvd_payment_usd', '_cvd_payment_estimated', '_cvd_assembly', '_cvd_assembly_usd' ) as $key ) {
				$order->delete_meta_data( $key );
			}
			$order->update_meta_data( '_cvd_change_required', array() );
			$order->add_order_note( 'Cliente seleccionó recogida en tienda.' );
			self::save_payment( $order, sanitize_key( wp_unslash( $_POST['billing_cvd_payment_kind'] ?? '' ) ), true ); // 3.13.74
			return;
		}
		$order->update_meta_data( '_cvd_province_name', self::provinces()[ $state ] ?? $state );
		$order->update_meta_data( '_cvd_locality', isset( $_POST['billing_cvd_locality'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_locality'] ) ) : '' );
		$order->update_meta_data( '_cvd_reference', isset( $_POST['billing_cvd_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_cvd_reference'] ) ) : '' );
		// 3.13.54: nunca se guarda una ubicación de fuera de Cuba (en la prueba del 11-oct se guardó Madrid y el vale abrió el mapa allí).
		$map_raw = isset( $_POST['billing_cvd_map_url'] ) ? (string) esc_url_raw( wp_unslash( $_POST['billing_cvd_map_url'] ) ) : '';
		$map_url = CVD_Shipping_Rates::safe_map_url( $map_raw );
		$order->update_meta_data( '_cvd_map_url', $map_url );
		$order->update_meta_data( '_cvd_map_accuracy', $map_url && isset( $_POST['billing_cvd_map_accuracy'] ) ? absint( $_POST['billing_cvd_map_accuracy'] ) : 0 );
		if ( $map_raw && ! $map_url ) { self::note( $order, 'La ubicación del mapa estaba fuera de Cuba y no se guardó. El mensajero se guía por la dirección escrita.' ); }
		// 3.13.70: armado por el mensajero (aparte, fuera del total de WooCommerce).
		$assembly = sanitize_key( wp_unslash( $_POST[ self::ASSEMBLY_FIELD ] ?? '' ) );
		if ( isset( self::ASSEMBLY[ $assembly ] ) ) {
			$order->update_meta_data( '_cvd_assembly', $assembly );
			$order->update_meta_data( '_cvd_assembly_usd', (string) self::ASSEMBLY[ $assembly ]['usd'] );
			self::note( $order, 'Armado por el mensajero: ' . self::ASSEMBLY[ $assembly ]['label'] . ', ' . self::ASSEMBLY[ $assembly ]['usd'] . ' USD. Lo cobra el mensajero aparte, como la mensajería (no está en el total).' );
		} else {
			$order->delete_meta_data( '_cvd_assembly' );
			$order->delete_meta_data( '_cvd_assembly_usd' );
		}
		$order->update_meta_data( '_cvd_delivery_date', CVD_Delivery_When::normalize( sanitize_text_field( wp_unslash( $_POST['billing_cvd_delivery_date'] ?? '' ) ) ) );
		$window = sanitize_key( wp_unslash( $_POST['billing_cvd_delivery_window'] ?? '' ) );
		$order->update_meta_data( '_cvd_delivery_window', in_array( $window, array( 'morning', 'afternoon' ), true ) ? $window : '' );
		// 3.13.67: forma de pago y cuánto es en su moneda con la tasa de hoy (save_payment()).
		$kind = sanitize_key( wp_unslash( $_POST['billing_cvd_payment_kind'] ?? '' ) );
		$due = self::save_payment( $order, $kind, false );
		$cash_currency = isset( self::PAYMENT_KINDS[ $kind ] ) && in_array( $kind, self::CASH_KINDS, true ) ? self::PAYMENT_KINDS[ $kind ]['currency'] : '';
		// El cliente dice con cuánto paga (solo en efectivo, en la moneda de su forma de pago) y el vuelto se calcula aquí.
		// Sin forma de pago (pedido de antes): como siempre, la moneda que eligió y el vuelto solo si es en USD.
		$pay_with = (float) wc_format_decimal( wp_unslash( $_POST['billing_cvd_change_amount'] ?? 0 ), 2 );
		$pay_currency = strtoupper( sanitize_key( wp_unslash( $_POST['billing_cvd_change_currency'] ?? '' ) ) );
		$pay_currency = '' !== $cash_currency ? $cash_currency : ( in_array( $pay_currency, array( 'USD', 'CUP', 'EUR' ), true ) ? $pay_currency : 'USD' );
		if ( isset( self::PAYMENT_KINDS[ $kind ] ) && '' === $cash_currency ) { $pay_with = 0; } // transferencia, Clásica, Zelle o PayPal: no hay vuelto
		$change_required = array();
		if ( $pay_with > 0 ) {
			$order->update_meta_data( '_cvd_pay_with', array( 'amount' => $pay_with, 'currency' => $pay_currency ) );
			$owed = 'USD' === $pay_currency ? (float) $order->get_total() : ( '' !== $cash_currency ? $due : null );
			if ( null !== $owed ) {
				$change = round( $pay_with - $owed, 2 );
				if ( $change > 0 ) { $change_required[] = array( 'amount' => $change, 'currency' => $pay_currency ); }
			}
		}
		$order->update_meta_data( '_cvd_change_required', $change_required );
	}

	/**
	 * Guarda la forma de pago y cuánto es en su moneda con la tasa de hoy: _cvd_payment_kind, _currency, _factor, _usd,
	 * _amount (y _estimated si usó el respaldo). El vale, la tienda y la gestora leen lo mismo (order_payment()).
	 * 3.13.74: también en la recogida en tienda (Dany). Devuelve el importe (null si no hay forma o no hay tasa).
	 */
	private static function save_payment( WC_Order $order, string $kind, bool $pickup ): ?float {
		if ( ! isset( self::PAYMENT_KINDS[ $kind ] ) ) { return null; }
		$def = self::PAYMENT_KINDS[ $kind ];
		$rate = self::payment_factors()[ $kind ];
		$usd = round( (float) $order->get_total(), 2 );
		$due = self::payment_amount( $usd, $rate['factor'], $def['currency'] );
		$order->update_meta_data( '_cvd_payment_kind', $kind );
		$order->update_meta_data( '_cvd_payment_currency', $def['currency'] );
		$order->update_meta_data( '_cvd_payment_factor', $rate['factor'] );
		$order->update_meta_data( '_cvd_payment_usd', $usd );
		if ( null !== $due ) { $order->update_meta_data( '_cvd_payment_amount', $due ); }
		if ( $rate['estimated'] ) { $order->update_meta_data( '_cvd_payment_estimated', 'yes' ); }
		$prepaid = empty( $def['prepaid'] ) ? '' : ' ' . self::PREPAID_NOTICE . ( $pickup ? ' La tienda verifica el pago antes de entregarlo; no hay devolución.' : ' La tienda verifica el pago antes de coordinar la entrega; no hay devolución. El mensajero solo cobra su mensajería.' );
		self::note( $order, 'Forma de pago' . ( $pickup ? ' (recogida en tienda)' : '' ) . ': ' . self::payment_text( $kind, $due, $rate['factor'], $usd ) . '.' . $prepaid );
		return $due;
	}

	private static function note( WC_Order $order, string $text ): void {
		if ( $order->get_id() ) { $order->add_order_note( $text ); return; }
		self::$pending_notes[] = $text;
	}

	public static function flush_notes( $order ): void {
		$notes = self::$pending_notes; self::$pending_notes = array();
		if ( ! $order instanceof WC_Order ) { return; }
		foreach ( $notes as $text ) { $order->add_order_note( $text ); }
	}

	public static function admin_order_fields( WC_Order $order ): void {
		echo '<div class="cvd-order-delivery"><h3>Entrega Casa Viva</h3>';
		echo '<p><strong>Modalidad:</strong> ' . esc_html( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ? 'Recogida en tienda' : 'Mensajería' ) . '</p>';
		$shipping_cup = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		echo '<p><strong>Tarifa guardada:</strong> ' . esc_html( $shipping_cup ? number_format_i18n( $shipping_cup, 0 ) . ' CUP' : 'Por confirmar' ) . '</p>';
		$payment = self::order_payment( $order );
		if ( $payment ) { echo '<p><strong>Cómo paga el cliente:</strong> ' . esc_html( $payment['text'] . ( $payment['prepaid'] ? ' — verificar la captura antes de coordinar la entrega (sin devolución)' : '' ) ) . '</p>'; }
		$assembly = self::order_assembly( $order );
		if ( $assembly ) { echo '<p style="color:#a35a1f"><strong>🔧 Armado por el mensajero:</strong> ' . esc_html( $assembly['text'] ) . '</p>'; }
		foreach ( array( '_cvd_locality' => 'Reparto/localidad', '_cvd_reference' => 'Referencia', '_cvd_alternate_phone' => 'Teléfono alternativo', '_cvd_delivery_date' => 'Día de entrega', '_cvd_delivery_window' => 'Horario solicitado', '_cvd_map_url' => 'Ubicación en mapa', '_cvd_buyer_name' => 'Persona que compra/paga' ) as $key => $label ) {
			$value = $order->get_meta( $key, true );
			if ( '_cvd_map_url' === $key ) { $value = CVD_Shipping_Rates::safe_map_url( (string) $value ); }
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
		$payment = self::order_payment( $order ); // 3.13.74: también en la recogida
		if ( $payment ) {
			echo '<p><strong>Pago:</strong> ' . esc_html( $payment['text'] ) . ( $payment['prepaid'] ? '<br>' . esc_html( self::PREPAID_NOTICE . ' No hay devolución.' ) : '' ) . ( 'pickup' === $type ? '' : '<br><small>' . esc_html( 'La mensajería se paga aparte, en CUP, al mensajero.' ) . '</small>' ) . '</p>';
		}
		$assembly = self::order_assembly( $order );
		if ( $assembly ) { echo '<p><strong>Armado por el mensajero:</strong> ' . esc_html( $assembly['text'] ) . '</p>'; }
		$map_url = CVD_Shipping_Rates::order_map_url( $order );
		if ( $map_url ) { echo '<p><a class="button" href="' . esc_url( $map_url ) . '" target="_blank" rel="noopener">Abrir ubicación en el mapa</a></p>'; }
		echo '</section>';
	}

	// ---------- Armado por el mensajero (3.13.70) ----------

	/** 3.13.89: productos que se arman, por su nombre (sin tildes, en minúscula; singular o plural). */
	private const ASSEMBLY_WORDS = '/\b(?:escritorio|silla|sillon|mesa|mesita|estante|estanteria|librero|repisa|zapatera|perchero|percha doble|closet|armario|gavetero|cama|litera|cuna|corral|mueble|sofa|butaca|banqueta|taburete|tocador|aparador|vitrina|gondola|organizador (?:multifuncional|multiusos|de \d+ niveles|de bano))(?:s|es)?\b/';
	/** …salvo lo que solo los nombra (forro para sillas, lámpara de mesa) o no se arma (plegable, soporte de TV). */
	private const ASSEMBLY_NOT = '/\b(?:forro|funda|lampara|mantel|protector|cojin|plegable|refrigerad\w*|cables?|ganchos?|casco|soporte|alfombras?|sabanas?|edredon|colchon|cortinas?)\b/';

	/**
	 * 3.13.89 (auditoría de cliente 13-oct, punto 19: salía «Armado por el mensajero» hasta con una sombrilla).
	 * Se ofrece si el carrito trae algo que se arma (muebles, estantes, camas, sillas, zapateras…). El equipo (gestoras,
	 * tienda) lo ve siempre: conoce sus productos y la regla por nombre puede fallar. Un producto se puede forzar con el
	 * dato `_cvd_needs_assembly` = yes | no (en el producto o en su producto padre).
	 */
	public static function assembly_offered(): bool {
		$user = wp_get_current_user();
		if ( $user->exists() && array_intersect( (array) $user->roles, array_merge( self::TEAM_ROLES, array( 'cvd_clerk', 'cvd_operator' ) ) ) ) { return true; }
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return true; }
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && self::needs_assembly( $product ) ) { return true; }
		}
		return false;
	}

	public static function needs_assembly( WC_Product $product ): bool {
		foreach ( array_filter( array( $product->get_id(), $product->get_parent_id() ) ) as $id ) {
			$flag = sanitize_key( (string) get_post_meta( $id, '_cvd_needs_assembly', true ) );
			if ( 'yes' === $flag ) { return true; }
			if ( 'no' === $flag ) { return false; }
		}
		$name = CVD_Product_Search::normalize( $product->get_name() );
		return (bool) preg_match( self::ASSEMBLY_WORDS, $name ) && ! preg_match( self::ASSEMBLY_NOT, $name );
	}

	/**
	 * Armado del pedido o null (sin armado, recogida en tienda o pedido de antes de 3.13.70).
	 * → kind (small|large), usd (5|10), label, text (para pantallas) y notice (para WhatsApp, con *negritas*).
	 */
	public static function order_assembly( WC_Order $order ): ?array {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return null; }
		$kind = sanitize_key( (string) $order->get_meta( '_cvd_assembly', true ) );
		if ( ! isset( self::ASSEMBLY[ $kind ] ) ) { return null; }
		$usd = (float) $order->get_meta( '_cvd_assembly_usd', true );
		$usd = $usd > 0 ? $usd : (float) self::ASSEMBLY[ $kind ]['usd'];
		return array(
			'kind'   => $kind,
			'usd'    => $usd,
			'label'  => self::ASSEMBLY[ $kind ]['label'],
			'text'   => 'Sí, ' . self::ASSEMBLY[ $kind ]['label'] . ' · el mensajero cobra ' . self::money( $usd, 'USD' ) . ' de armado al cliente, aparte (como la mensajería)',
			'notice' => self::assembly_notice( $usd ),
		);
	}

	/** «🔧 *OJO: hay que ARMAR el producto* · el mensajero cobra *10 USD* de armado al cliente» (texto de Ernesto, 13-oct). */
	public static function assembly_notice( float $usd ): string {
		return '🔧 *OJO: hay que ARMAR el producto* · el mensajero cobra *' . self::money( $usd, 'USD' ) . '* de armado al cliente';
	}

	// ---------- Formas de pago (3.13.67) ----------

	/**
	 * Factor de HOY por forma de pago: cuánto paga el cliente por cada 1 USD del precio (CVD_Daily_Rates; PayPal es
	 * 1 + %/100). estimated = salió del respaldo PAYMENT_FALLBACK porque aún no hay esa tasa. factor 0 = sin tasa (CUP).
	 */
	public static function payment_factors(): array {
		$rates = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::get() : array();
		$out = array();
		foreach ( self::PAYMENT_KINDS as $kind => $def ) {
			$field = $def['rate'];
			$value = '' === $field ? 1.0 : ( isset( $rates[ $field ] ) && is_numeric( $rates[ $field ] ) ? (float) $rates[ $field ] : null );
			$estimated = false;
			if ( null === $value && isset( self::PAYMENT_FALLBACK[ $field ] ) ) { $value = self::PAYMENT_FALLBACK[ $field ]; $estimated = true; }
			$factor = null === $value ? 0.0 : ( 'paypal_pct' === $field ? 1 + $value / 100 : $value );
			$out[ $kind ] = array( 'factor' => $factor > 0 ? round( $factor, 4 ) : 0.0, 'estimated' => $estimated );
		}
		return $out;
	}

	/** Importe en la moneda de la forma de pago: CUP entero; USD y EUR con 2 decimales. null si no hay tasa. */
	public static function payment_amount( float $usd, float $factor, string $currency ): ?float {
		if ( $usd <= 0 || $factor <= 0 ) { return null; }
		return 'CUP' === $currency ? (float) round( $usd * $factor ) : round( $usd * $factor, 2 );
	}

	/** ¿Zelle o PayPal? El cliente ya pagó todo antes: el mensajero no cobra productos (solo su mensajería). */
	public static function is_prepaid( WC_Order $order ): bool {
		$kind = sanitize_key( (string) $order->get_meta( '_cvd_payment_kind', true ) );
		return ! empty( self::PAYMENT_KINDS[ $kind ]['prepaid'] );
	}

	/**
	 * Forma de pago del pedido para el vale del bot, la tienda (Pedidos) y la gestora, o null si es un pedido de antes
	 * de 3.13.67 (entonces todo sigue como siempre). Manda la cifra guardada al comprar; solo si luego cambió el total
	 * del pedido se recalcula, con el mismo factor del día de la venta.
	 * → kind, label, currency, amount (null = sin tasa), factor, usd, prepaid, estimated, text.
	 */
	public static function order_payment( WC_Order $order ): ?array {
		$kind = sanitize_key( (string) $order->get_meta( '_cvd_payment_kind', true ) );
		if ( ! isset( self::PAYMENT_KINDS[ $kind ] ) ) { return null; }
		$def = self::PAYMENT_KINDS[ $kind ];
		$usd = round( (float) $order->get_total(), 2 );
		$factor = (float) $order->get_meta( '_cvd_payment_factor', true );
		$saved = (string) $order->get_meta( '_cvd_payment_amount', true );
		$same_total = abs( (float) $order->get_meta( '_cvd_payment_usd', true ) - $usd ) < 0.01;
		$amount = '' !== $saved && $same_total ? (float) $saved : self::payment_amount( $usd, $factor, $def['currency'] );
		return array(
			'kind'      => $kind,
			'label'     => $def['label'],
			'currency'  => $def['currency'],
			'amount'    => $amount,
			'factor'    => $factor > 0 ? $factor : null,
			'usd'       => $usd,
			'prepaid'   => ! empty( $def['prepaid'] ),
			'estimated' => 'yes' === (string) $order->get_meta( '_cvd_payment_estimated', true ),
			'text'      => self::payment_text( $kind, $amount, $factor, $usd ),
		);
	}

	/** "Efectivo CUP · 28.260 CUP (36 USD × 785)"; Zelle/PayPal terminan en "· por adelantado". */
	public static function payment_text( string $kind, ?float $amount, float $factor, float $usd ): string {
		$def = self::PAYMENT_KINDS[ $kind ] ?? null;
		if ( ! $def ) { return ''; }
		if ( null === $amount ) {
			$money = self::money( $usd, 'USD' ) . ' en ' . $def['currency'] . ' a la tasa del día (por confirmar con la tienda)';
		} else {
			$money = self::money( $amount, $def['currency'] ) . ( 'cash_usd' === $kind ? '' : ' (' . self::payment_formula( $kind, $factor, $usd ) . ')' );
		}
		return $def['label'] . ' · ' . $money . ( empty( $def['prepaid'] ) ? '' : ' · por adelantado' );
	}

	/** "36 USD × 785", "36 USD × 1,15" o, en PayPal, "36 USD + 4 %". */
	private static function payment_formula( string $kind, float $factor, float $usd ): string {
		$rate = static fn( float $v ): string => class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::format_rate( $v ) : (string) $v;
		if ( 'paypal' === $kind ) { return self::money( $usd, 'USD' ) . ' + ' . $rate( round( ( $factor - 1 ) * 100, 2 ) ) . ' %'; }
		return self::money( $usd, 'USD' ) . ' × ' . $rate( $factor );
	}

	/** Dinero a la cubana: 28260 CUP → "28.260 CUP"; 41.4 USD → "41,40 USD"; 36 USD → "36 USD". */
	public static function money( float $amount, string $currency ): string {
		$decimals = 'CUP' === $currency || abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
		return number_format( $amount, $decimals, ',', '.' ) . ' ' . $currency;
	}

	/** Lo que necesita checkout.js para enseñar cuánto es con la tasa de hoy (las mismas cuentas que el servidor). */
	private static function payment_script_data(): array {
		$factors = self::payment_factors();
		$kinds = array();
		foreach ( self::PAYMENT_KINDS as $kind => $def ) {
			$kinds[ $kind ] = array( 'label' => $def['label'], 'currency' => $def['currency'], 'factor' => $factors[ $kind ]['factor'], 'estimated' => $factors[ $kind ]['estimated'], 'prepaid' => ! empty( $def['prepaid'] ), 'cash' => in_array( $kind, self::CASH_KINDS, true ) );
		}
		return array( 'kinds' => $kinds, 'prepaidNotice' => self::PREPAID_NOTICE );
	}
}
