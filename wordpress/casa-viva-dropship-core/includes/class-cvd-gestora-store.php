<?php

defined( 'ABSPATH' ) || exit;

/** Tienda espejo y precios de reventa sin duplicar el catálogo. */
final class CVD_Gestora_Store {
	private static bool $resolving_price = false;
	private static array $price_maps = array();
	private static ?array $contact = null;
	/** Gestora de la tienda de esta visita ya calculada, por etapa de la petición (null = tienda de Casa Viva). */
	private static array $active = array();
	/** Páginas de atención al cliente con un botón de WhatsApp escrito en la página (3.13.88: van a la gestora en su tienda). */
	private const CONTACT_PAGES = array( 'atencion-al-cliente', 'formas-de-pago', 'condiciones-de-mensajeria', 'como-llegar', 'tarifas-mensajeria', 'cotizar-mensajeria', 'seguimiento' );

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Prioridad 9: antes de los shortcodes (11), así solo cambia el botón escrito en la página, nunca el de «mi acceso».
		add_filter( 'the_content', array( __CLASS__, 'contact_in_page_content' ), 9 );
		// 3.14.10 (D67): con gestora, «Cómo llegar a la tienda» es «Cómo recoger tu pedido» (página, pestaña y vista previa).
		add_filter( 'the_title', array( __CLASS__, 'pickup_page_title' ), 10, 2 );
		add_filter( 'single_post_title', array( __CLASS__, 'pickup_page_title' ), 10, 2 );
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price' ) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'customer_price' ), 30, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_price' ), 30, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'variation_price' ), 30, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_price' ), 30, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'variation_prices_hash' ), 30, 3 );
		add_filter( 'post_type_link', array( __CLASS__, 'preserve_referral_on_product_links' ), 20, 2 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'store_banner' ), 5 );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'store_banner' ), 5 );
		// 3.14.6: la marca de la gestora también en el carrito y en el pago (antes solo en la lista y la ficha).
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'store_banner' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'store_banner' ), 1 );
		add_filter( 'render_block', array( __CLASS__, 'banner_in_cart_block' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'lock_cart_price' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_locked_cart_prices' ), 20 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'relock_cart' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'snapshot_line' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'snapshot_order' ), 15, 2 );
	}

	public static function preserve_referral_on_product_links( string $url, WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $url;
		}
		$gestora = self::active_gestora();
		$code = $gestora ? (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true ) : '';
		return '' !== $code ? add_query_arg( 'ref', $code, $url ) : $url;
	}

	public static function lock_cart_price( array $cart_item_data, int $product_id, int $variation_id ): array {
		$gestora = self::active_gestora(); if ( ! $gestora ) { return $cart_item_data; }
		$product = wc_get_product( $variation_id ?: $product_id ); if ( ! $product ) { return $cart_item_data; }
		$base = (float) get_post_meta( $product->get_id(), '_price', true );
		$cart_item_data['_cvd_gestora_id'] = $gestora->ID;
		$cart_item_data['_cvd_base_price'] = $base;
		$cart_item_data['_cvd_locked_price'] = (float) self::resolve_price( $product, $gestora->ID, $base );
		return $cart_item_data;
	}

	public static function apply_locked_cart_prices( WC_Cart $cart ): void {
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 2 ) { return; }
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['_cvd_locked_price'], $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) { $cart_item['data']->set_price( (float) $cart_item['_cvd_locked_price'] ); }
		}
	}

	/**
	 * 3.13.81 (Thaly, 13-oct): lo que ya estaba en el carrito pasa al precio de la gestora cuyo enlace se está usando.
	 * Thaly tenía los organizadores en el carrito desde antes (a 30, sin gestora); al volver por su enlace la ficha
	 * decía 35 pero el carrito seguía a 30. Mismo criterio que la atribución: el último enlace manda.
	 */
	public static function relock_cart( WC_Cart $cart ): void {
		$gestora = self::active_gestora();
		if ( ! $gestora ) { return; }
		$contents = array(); $changed = false;
		// Lo que no es dato propio de la línea; lo que queda es lo que add_to_cart usó para su clave.
		$not_line_data = array_flip( array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ) );
		foreach ( $cart->get_cart_contents() as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && absint( $item['_cvd_gestora_id'] ?? 0 ) !== $gestora->ID ) {
				$base = (float) get_post_meta( $product->get_id(), '_price', true );
				$item['_cvd_gestora_id'] = $gestora->ID;
				$item['_cvd_base_price'] = $base;
				$item['_cvd_locked_price'] = (float) self::resolve_price( $product, $gestora->ID, $base );
				// Nueva clave, la misma que daría añadirlo ahora desde su tienda: así no salen dos líneas del mismo producto.
				$key = $cart->generate_cart_id( $item['product_id'], $item['variation_id'], $item['variation'], array_diff_key( $item, $not_line_data ) );
				$item['key'] = $key;
				$changed = true;
			}
			if ( isset( $contents[ $key ] ) ) { $contents[ $key ]['quantity'] += $item['quantity']; } else { $contents[ $key ] = $item; }
		}
		if ( ! $changed ) { return; }
		$cart->set_cart_contents( $contents );
		// WooCommerce no guarda el carrito tras cargarlo salvo que él mismo lo cambie: se guarda aquí, como
		// WC_Cart_Session::get_cart_for_session() (sin el objeto producto), y también el carrito de la cuenta.
		$for_session = array();
		foreach ( $contents as $key => $item ) { unset( $item['data'] ); $for_session[ $key ] = $item; }
		if ( WC()->session ) { WC()->session->set( 'cart', $for_session ); }
		if ( get_current_user_id() && apply_filters( 'woocommerce_persistent_cart_enabled', true ) ) {
			update_user_meta( get_current_user_id(), '_woocommerce_persistent_cart_' . get_current_blog_id(), array( 'cart' => $for_session ) );
		}
	}

	public static function assets(): void {
		$gestora = self::active_gestora();
		if ( ! $gestora ) { return; }
		wp_enqueue_style( 'cvd-gestora-store', CVD_URL . 'assets/gestora-store.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-gestora-store', CVD_URL . 'assets/gestora-store.js', array(), CVD_VERSION, true );
		wp_localize_script(
			'cvd-gestora-store',
			'cvdMirrorStore',
			array(
				'referralCode' => (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true ),
				'origin'       => home_url( '/' ),
			)
		);
	}

	/**
	 * Gestora cuya tienda (precios, marca, fotos, WhatsApp) ve esta visita, o null = tienda de Casa Viva.
	 *
	 * 3.14.6 (Thaly, 14-oct, D54: la tienda de la gestora es «pegajosa»). Antes los precios de la gestora solo salían con
	 * ?ref= en la dirección: su cliente abría el enlace y veía 35, tocaba Inicio, el logo, Menú, un departamento o el
	 * buscador y le salía 30, el precio de Casa Viva. Ahora manda, por este orden:
	 *  1. el enlace de esta visita (?ref= de una gestora aprobada), para cualquiera, como siempre;
	 *  2. si no, el último enlace de gestora guardado en este navegador (cookie firmada, el último manda, D50), en TODA la web,
	 *     para visitantes y clientes con cuenta. La gestora con su sesión abierta ve así SU tienda (inicio, lista, ficha,
	 *     buscador, carrito, pago) solo si el enlace guardado es el suyo, y nunca en su panel; mensajeros, tienda y
	 *     administración ven la de Casa Viva salvo que abran un ?ref=. En la API (REST) solo cuenta para Cúrru y el
	 *     carrito de WooCommerce: el panel, el bot y las apps del equipo siguen viendo los precios de Casa Viva.
	 * Las páginas con gestora no se guardan en ninguna caché (CVD_Attribution: DONOTCACHEPAGE, no-cache y la cookie
	 * BYPASS_COOKIE que la caché de Hostinger respeta), así que un visitante sin enlace nunca ve los precios de una gestora.
	 */
	public static function active_gestora(): ?WP_User {
		if ( is_admin() && ! wp_doing_ajax() ) { return null; }
		// Se recuerda por etapa: antes de wp_loaded la sesión y la cookie aún no están leídas, y hasta «wp» no se sabe qué página es.
		$stage = did_action( 'wp' ) ? 'wp' : ( did_action( 'wp_loaded' ) ? 'loaded' : '' );
		if ( '' !== $stage && array_key_exists( $stage, self::$active ) ) { return self::$active[ $stage ]; }
		$owner = self::pricing_owner_from_request();
		if ( ! $owner ) { $owner = self::sticky_owner(); }
		$user = null;
		if ( $owner && 'gestora' === ( $owner['owner_type'] ?? '' ) ) {
			$user = get_user_by( 'id', absint( $owner['owner_user_id'] ?? 0 ) ) ?: null;
			if ( $user && 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) { $user = null; }
		}
		if ( '' !== $stage ) { self::$active[ $stage ] = $user; }
		return $user;
	}

	/** El último enlace guardado en este navegador, si a quien navega le toca ver la tienda de esa gestora (ver active_gestora). */
	private static function sticky_owner(): ?array {
		if ( self::is_rest() && ! self::storefront_rest() ) { return null; }
		$owner = CVD_Attribution::saved_referral_owner();
		if ( ! $owner || ! is_user_logged_in() ) { return $owner; }
		$context = class_exists( 'CVD_Contextual_Assistant' ) ? CVD_Contextual_Assistant::context() : 'cliente';
		if ( 'cliente' === $context ) { return $owner; }
		if ( 'gestora' === $context && absint( $owner['owner_user_id'] ?? 0 ) === get_current_user_id() && self::storefront_page() ) { return $owner; }
		return null;
	}

	/** ¿Llamada a la API? Se mira la dirección, no solo REST_REQUEST: esa constante aún no existe cuando WooCommerce carga el carrito. */
	private static function is_rest(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) || false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/' . rest_get_url_prefix() . '/' );
	}

	/**
	 * Llamadas a la API que forman parte de la tienda: Cúrru, las sugerencias del buscador (3.14.8, Niurka: la lista
	 * que sale al escribir daba el precio de Casa Viva y la ficha el suyo) y el carrito/pago de WooCommerce (Store API).
	 */
	private static function storefront_rest(): bool {
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		foreach ( array( '/casa-viva/v1/curru/', '/casa-viva/v1/products/search', '/wc/store/' ) as $prefix ) {
			if ( false !== strpos( $route, $prefix ) ) { return true; }
		}
		return false;
	}

	/** Páginas de la tienda (no el panel de la gestora): inicio, lista, ficha, buscador, carrito, pago, páginas de atención. */
	private static function storefront_page(): bool {
		if ( wp_doing_ajax() ) { return isset( $_GET['wc-ajax'] ); }
		if ( self::is_rest() ) { return true; }
		if ( ! did_action( 'wp' ) ) { return false; }
		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() ) ) { return true; }
		return is_front_page() || is_search() || is_page( self::CONTACT_PAGES );
	}

	/** Gestora del enlace ?ref= de esta visita (o, en AJAX, de la página que lo pidió). */
	private static function pricing_owner_from_request(): ?array {
		$raw = isset( $_GET['ref'] ) ? wp_unslash( $_GET['ref'] ) : ( isset( $_GET['cv_ref'] ) ? wp_unslash( $_GET['cv_ref'] ) : '' );
		// "Añadir al carrito" desde la lista va por AJAX (/?wc-ajax=add_to_cart) sin ?ref=: se lee de la página que lo pidió.
		// Sin esto el carrito no fijaba el precio de la gestora y el pedido salía al precio de Casa Viva.
		if ( '' === $raw && wp_doing_ajax() ) { $raw = self::referral_from_referer(); }
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( (string) $raw ) ) );
		if ( ! $code ) { return null; }
		$owner = CVD_Attribution::current_referral_owner();
		return $owner && hash_equals( (string) ( $owner['referral_code'] ?? '' ), $code ) ? $owner : null;
	}

	/**
	 * Código ?ref= de la página de esta misma web desde la que se hizo la petición AJAX.
	 *
	 * 3.13.81 (Thaly, 13-oct, pedido #3598): al pulsar «Todo bien, finalizar», el formulario de pago manda
	 * _wp_http_referer, una ruta relativa sin dominio (/?wc-ajax=update_order_review o /finalizar-compra/?ref=…),
	 * y wp_get_raw_referer() la prefiere a la cabecera Referer. Se descartaba por no tener dominio: la página de
	 * pago mostraba 140 (precio de la gestora) y el pedido se creaba a 120 (precio de Casa Viva). Ahora se mira
	 * esa ruta y, si no trae código, la cabecera Referer.
	 */
	private static function referral_from_referer(): string {
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$referers = array( (string) wp_get_raw_referer(), isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' );
		foreach ( $referers as $referer ) {
			if ( '' === $referer ) { continue; }
			// Solo esta web: dirección completa con nuestro dominio o ruta relativa (/…). «//otra-web» trae dominio y no pasa.
			$host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( $host ? $host !== $home_host : 0 !== strpos( $referer, '/' ) ) { continue; }
			$query = array();
			parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
			$value = $query['ref'] ?? ( $query['cv_ref'] ?? '' );
			if ( is_string( $value ) && '' !== $value ) { return $value; }
		}
		return '';
	}

	public static function customer_price( $price, WC_Product $product ) {
		if ( self::$resolving_price || '' === $price || null === $price ) { return $price; }
		$gestora = self::active_gestora();
		if ( ! $gestora ) { return $price; }
		return self::resolve_price( $product, $gestora->ID, (float) $price );
	}

	public static function variation_price( $price, WC_Product_Variation $variation, WC_Product $product ) {
		unset( $product );
		return self::customer_price( $price, $variation );
	}

	public static function variation_prices_hash( array $hash, WC_Product $product, bool $for_display ): array {
		unset( $product, $for_display );
		$gestora = self::active_gestora();
		$hash['cvd_gestora_id'] = $gestora ? $gestora->ID : 0;
		$hash['cvd_price_version'] = $gestora ? (int) get_user_meta( $gestora->ID, '_cvd_price_version', true ) : 0;
		return $hash;
	}

	public static function resolve_price( WC_Product $product, int $gestora_id, ?float $base = null ): string {
		global $wpdb;
		self::$resolving_price = true;
		if ( null === $base ) { $base = self::base_price( $product ); }
		self::$resolving_price = false;
		if ( $base <= 0 ) { return (string) $base; }

		$prices = self::stored_prices( $gestora_id );
		$custom = $prices[ $product->get_id() ] ?? null;
		if ( null === $custom && $product->is_type( 'variation' ) ) {
			$custom = $prices[ $product->get_parent_id() ] ?? null;
		}
		$price = null !== $custom ? (float) $custom : $base * ( 1 + ( (float) get_user_meta( $gestora_id, '_cvd_global_markup_percent', true ) / 100 ) );
		$min = (float) $product->get_meta( '_cvd_min_price', true );
		$min = $min > 0 ? $min : $base;
		$max = self::max_price( $product, $gestora_id, $base );
		$price = max( $min, $price );
		if ( $max > 0 ) { $price = min( $max, $price ); }
		return wc_format_decimal( $price, wc_get_price_decimals() );
	}

	/** 'max' = 0 significa sin tope (regla de Casa Viva desde 2026-10-11: la gestora sube lo que quiera). */
	public static function limits( WC_Product $product, int $gestora_id ): array {
		$base = self::base_price( $product );
		$min = (float) $product->get_meta( '_cvd_min_price', true );
		$min = $min > 0 ? $min : $base;
		return array( 'base' => $base, 'min' => $min, 'max' => self::max_price( $product, $gestora_id, $base ) );
	}

	/**
	 * Tope solo si Casa Viva lo puso a mano en el producto (_cvd_max_price) o a esa gestora
	 * (_cvd_max_markup_percent). El "aumento máximo predeterminado" general (30 %) ya no se aplica.
	 */
	private static function max_price( WC_Product $product, int $gestora_id, float $base ): float {
		$max = (float) $product->get_meta( '_cvd_max_price', true );
		if ( $max > 0 ) { return $max; }
		$percent = (float) get_user_meta( $gestora_id, '_cvd_max_markup_percent', true );
		return $percent > 0 ? $base * ( 1 + $percent / 100 ) : 0.0;
	}

	private static function base_price( WC_Product $product ): float {
		$base = (float) get_post_meta( $product->get_id(), '_price', true );
		if ( $base > 0 || ! $product->is_type( 'variable' ) ) { return $base; }
		$prices = array();
		foreach ( $product->get_children() as $variation_id ) {
			$value = (float) get_post_meta( $variation_id, '_price', true );
			if ( $value > 0 ) { $prices[] = $value; }
		}
		return $prices ? min( $prices ) : 0.0;
	}

	public static function save_prices( int $gestora_id, array $posted ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_gestora_prices'; $saved = 0; $errors = array(); $now = current_time( 'mysql', true );
		foreach ( $posted as $product_id => $raw_price ) {
			$product = wc_get_product( absint( $product_id ) );
			if ( ! $product ) { continue; }
			$raw_price = trim( (string) $raw_price );
			if ( '' === $raw_price ) { $wpdb->delete( $table, array( 'gestora_user_id' => $gestora_id, 'product_id' => $product->get_id() ), array( '%d', '%d' ) ); continue; }
			$price = (float) wc_format_decimal( $raw_price ); $limits = self::limits( $product, $gestora_id );
			if ( $price < $limits['min'] || ( $limits['max'] > 0 && $price > $limits['max'] ) ) { $errors[] = $product->get_name(); continue; }
			$wpdb->replace( $table, array( 'gestora_user_id' => $gestora_id, 'product_id' => $product->get_id(), 'price' => $price, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%f', '%s', '%s' ) ); $saved++;
		}
		if ( $saved || $posted ) {
			self::bump_price_version( $gestora_id );
		}
		return array( 'saved' => $saved, 'errors' => $errors );
	}

	public static function bump_price_version( int $gestora_id ): void {
		unset( self::$price_maps[ $gestora_id ] );
		update_user_meta( $gestora_id, '_cvd_price_version', time() );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		do_action( 'litespeed_purge_all' );
	}

	public static function stored_prices( int $gestora_id ): array {
		if ( isset( self::$price_maps[ $gestora_id ] ) ) {
			return self::$price_maps[ $gestora_id ];
		}
		global $wpdb; $table = $wpdb->prefix . 'cvd_gestora_prices';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,price FROM {$table} WHERE gestora_user_id=%d", $gestora_id ), ARRAY_A );
		self::$price_maps[ $gestora_id ] = wp_list_pluck( $rows, 'price', 'product_id' );
		return self::$price_maps[ $gestora_id ];
	}

	/**
	 * 3.13.88 (D52, Ernesto 13-oct: «usamos el WhatsApp de la gestora en toda su tienda»). A quién escribe por WhatsApp
	 * quien mira la web (pie, botón verde, «Pedir por WhatsApp» de la ficha, Ayuda, páginas de atención):
	 * - en la tienda de una gestora (su enlace ?ref= en esta visita, o el último enlace guardado en este navegador,
	 *   CVD_Attribution::current_referral_owner()), a ELLA. Es la misma gestora que se queda la venta;
	 * - un cliente con cuenta que no viene por ningún enlace, a su gestora de siempre (la que se queda sus compras);
	 * - el equipo (gestoras, mensajeros, tienda) habla con Casa Viva. La gestora que abre SU tienda ve su número,
	 *   lo mismo que ven sus clientes;
	 * - si no, Casa Viva (cvd_central_whatsapp).
	 * Nunca sale el número de otra gestora. Las páginas con gestora no se guardan en caché (CVD_Attribution).
	 *
	 * @return array{phone:string, display:string, name:string, gestora_id:int}
	 */
	public static function contact(): array {
		if ( null !== self::$contact ) { return self::$contact; }
		$central = self::wa_digits( (string) get_option( 'cvd_central_whatsapp', '' ) ) ?: '5354056173';
		$result = array( 'phone' => $central, 'display' => self::wa_display( $central ), 'name' => '', 'gestora_id' => 0 );
		$gestora = self::contact_gestora();
		$phone = $gestora ? self::wa_digits( (string) get_user_meta( $gestora->ID, '_cvd_whatsapp', true ) ) : '';
		if ( $gestora && $phone ) {
			$name = (string) strtok( trim( (string) $gestora->display_name ), ' ' );
			$result = array( 'phone' => $phone, 'display' => self::wa_display( $phone ), 'name' => $name ? ucfirst( $name ) : '', 'gestora_id' => $gestora->ID );
		}
		// Se recuerda solo cuando la sesión de WooCommerce ya está cargada (antes de wp_loaded no se ve la gestora guardada).
		if ( did_action( 'wp_loaded' ) ) { self::$contact = $result; }
		return $result;
	}

	/** Enlace de WhatsApp con el texto ya escrito. En la tienda de una gestora, el texto nombra el producto que se mira. */
	public static function contact_url( string $casa_viva_text = '' ): string {
		$text = self::contact_text( $casa_viva_text );
		return 'https://wa.me/' . self::contact()['phone'] . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' );
	}

	/** Texto para escribir: el de Casa Viva tal cual; a una gestora, «vi en tu tienda…» y el producto de la ficha. */
	public static function contact_text( string $casa_viva_text = '' ): string {
		if ( ! self::contact()['gestora_id'] ) { return $casa_viva_text; }
		$product = function_exists( 'is_product' ) && is_product() ? wc_get_product( get_queried_object_id() ) : null;
		if ( $product instanceof WC_Product ) {
			return 'Hola, vi en tu tienda de Casa Viva: ' . $product->get_name() . ' · ' . get_permalink( $product->get_id() ) . ' · Tengo una pregunta: ';
		}
		return 'Hola, vi tu tienda de Casa Viva y tengo una pregunta: ';
	}

	/**
	 * WhatsApp para un pedido ya hecho (página de «pedido recibido»): la gestora del pedido, salvo que sea ella misma
	 * quien lo está mirando (hizo el pedido para su cliente: entonces habla con Casa Viva).
	 */
	public static function order_contact_phone( WC_Order $order ): string {
		$central = self::wa_digits( (string) get_option( 'cvd_central_whatsapp', '' ) ) ?: '5354056173';
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		if ( ! $owner_id || 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) || get_current_user_id() === $owner_id ) { return $central; }
		return self::wa_digits( (string) get_user_meta( $owner_id, '_cvd_whatsapp', true ) ) ?: $central;
	}

	/**
	 * El botón de WhatsApp escrito dentro de las páginas de atención (Atención al cliente, Formas de pago…) y, para el
	 * cliente de una gestora (3.14.9), también en fichas y demás páginas, junto con la dirección de la tienda (ver
	 * hides_casa_viva()).
	 */
	public static function contact_in_page_content( $content ) {
		if ( ! is_string( $content ) || '' === $content || ! self::contact()['gestora_id'] ) { return $content; }
		$hide = self::hides_casa_viva();
		if ( false !== strpos( $content, 'wa.me/' ) && ( $hide || is_page( self::CONTACT_PAGES ) ) ) {
			$central = self::wa_digits( (string) get_option( 'cvd_central_whatsapp', '' ) ) ?: '5354056173';
			$pattern = '#https://wa\.me/' . preg_quote( $central, '#' ) . '(?:\?text=[^"\'\s<>]*)?#';
			$content = (string) preg_replace_callback( $pattern, static fn(): string => self::contact_url( '' ), $content );
		}
		return $hide ? self::without_casa_viva( $content ) : $content;
	}

	/**
	 * 3.14.9 (Ernesto 14-oct, D67; lo pidió Thaly: que su cliente no se salte a la gestora yendo o escribiendo a la tienda).
	 * ¿Esta visita es de un cliente en la tienda de una gestora? Entonces nada de Casa Viva que le lleve fuera de ella:
	 * ni la dirección de la tienda, ni horario o mapa, ni el WhatsApp de Casa Viva, ni «Vende con nosotros».
	 * La web sin enlace sigue igual, y la gestora mirando su propia tienda (su sesión) también.
	 */
	public static function hides_casa_viva(): bool {
		$id = (int) self::contact()['gestora_id'];
		return $id > 0 && $id !== get_current_user_id();
	}

	/** Nombre corto de la gestora de este pedido si quien lo mira no es ella (su cliente, el bot); '' = pedido de Casa Viva. */
	public static function order_gestora_name( WC_Order $order ): string {
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		if ( ! $owner_id || 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) || get_current_user_id() === $owner_id ) { return ''; }
		$user = get_userdata( $owner_id );
		$name = $user ? (string) strtok( trim( (string) $user->display_name ), ' ' ) : '';
		return '' !== $name ? ucfirst( $name ) : ( $user ? 'Tu gestora' : '' );
	}

	/** Título de la página «Cómo llegar a la tienda» para el cliente de una gestora. */
	public static function pickup_page_title( $title, $post = null ) {
		if ( ! is_string( $title ) || 'Cómo llegar a la tienda' !== $title || is_admin() ) { return $title; }
		$post = get_post( $post );
		if ( ! $post || 'como-llegar' !== $post->post_name || ! self::hides_casa_viva() ) { return $title; }
		return 'Cómo recoger tu pedido';
	}

	/** Nombre de la gestora de esta visita para los textos de recogida ('' si se ve la tienda de Casa Viva). */
	private static function hidden_store_name(): string {
		return self::hides_casa_viva() ? ( self::contact()['name'] ?: 'Tu gestora' ) : '';
	}

	/** Dónde se recoge: la dirección de Casa Viva o, en la tienda de una gestora (o su pedido), «Thalia te dice dónde y cuándo recogerlo». */
	public static function pickup_place( ?WC_Order $order = null ): string {
		$name = $order ? self::order_gestora_name( $order ) : self::hidden_store_name();
		return '' !== $name ? $name . ' te dice dónde y cuándo recogerlo' : (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' );
	}

	/** «Recogida en tienda» o, con gestora, solo «Recogida» (la tienda de Casa Viva no se nombra). */
	public static function pickup_label( ?WC_Order $order = null ): string {
		return '' !== ( $order ? self::order_gestora_name( $order ) : self::hidden_store_name() ) ? 'Recogida' : 'Recogida en tienda';
	}

	/** Quita del texto de una página o ficha la dirección, el horario y el número de Casa Viva (cliente de una gestora). */
	private static function without_casa_viva( string $html ): string {
		$contact = self::contact();
		$name = esc_html( $contact['name'] ?: 'Tu gestora' );
		if ( is_page( 'como-llegar' ) ) {
			return '<p><strong>Para recoger tu pedido</strong>, ' . $name . ' te dice dónde y cuándo.</p><p><a class="button cv-info-cta" href="' . esc_url( self::contact_url( '' ) ) . '" target="_blank" rel="noopener">Escribir a ' . $name . ' por WhatsApp</a></p>';
		}
		$html = (string) preg_replace( '#<p class="cv-info-address">.*?</p>#s', '', $html );
		$html = str_replace( 'recogida en nuestra tienda de Nuevo Vedado', 'recogida (' . $name . ' te dice dónde y cuándo)', $html );
		$html = str_replace( 'Escríbenos por WhatsApp al', 'Escríbele a ' . $name . ' por WhatsApp al', $html );
		$html = str_replace( '<h2>Recogida en tienda</h2>', '<h2>Recogida</h2>', $html );
		$html = str_replace( 'También puedes recoger después de recibir la confirmación. No acudas antes de que Casa Viva confirme que el pedido está preparado.', 'También puedes recogerlo: ' . $name . ' te dice dónde y cuándo, cuando esté listo.', $html );
		$html = (string) preg_replace( '/(?:\+?53\s?)?\b5405[\s-]?6173\b/', esc_html( $contact['display'] ), $html );
		return (string) preg_replace( '#<a [^>]*href="[^"]*/como-llegar/?"[^>]*>.*?</a>#s', '', $html );
	}

	private static function contact_gestora(): ?WP_User {
		if ( is_admin() && ! wp_doing_ajax() ) { return null; }
		$owner = CVD_Attribution::current_referral_owner();
		if ( is_user_logged_in() ) {
			$context = class_exists( 'CVD_Contextual_Assistant' ) ? CVD_Contextual_Assistant::context() : 'cliente';
			if ( 'gestora' === $context ) {
				if ( ! $owner || absint( $owner['owner_user_id'] ?? 0 ) !== get_current_user_id() ) { return null; }
			} elseif ( 'cliente' === $context ) {
				$owner = $owner ?: CVD_Attribution::current_customer_owner();
			} else {
				return null;
			}
		}
		if ( ! $owner || 'gestora' !== ( $owner['owner_type'] ?? '' ) ) { return null; }
		$gestora = get_user_by( 'id', absint( $owner['owner_user_id'] ?? 0 ) );
		if ( ! $gestora || 'approved' !== get_user_meta( $gestora->ID, '_cvd_account_status', true ) || ! in_array( 'cvd_gestora', (array) $gestora->roles, true ) ) { return null; }
		return $gestora;
	}

	/** Cifras para wa.me: un número cubano de 8 cifras lleva delante el 53. */
	private static function wa_digits( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 8 === strlen( $digits ) ) { $digits = '53' . $digits; }
		return strlen( $digits ) >= 10 && strlen( $digits ) <= 15 ? $digits : '';
	}

	/** «5405 6173» para un número cubano; otros, con su prefijo. */
	private static function wa_display( string $digits ): string {
		if ( 10 === strlen( $digits ) && str_starts_with( $digits, '53' ) ) { return substr( $digits, 2, 4 ) . ' ' . substr( $digits, 6 ); }
		return '+' . $digits;
	}

	/** El carrito de la web es un bloque de WooCommerce (no pasa por woocommerce_before_cart): la marca va delante del bloque. */
	public static function banner_in_cart_block( $content, $block ) {
		if ( ! is_string( $content ) || ! in_array( $block['blockName'] ?? '', array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) { return $content; }
		ob_start();
		self::store_banner();
		return (string) ob_get_clean() . $content;
	}

	public static function store_banner(): void {
		$gestora = self::active_gestora(); if ( ! $gestora ) { return; }
		echo '<aside class="cvd-store-banner"><span>Tienda atendida por</span><strong>' . esc_html( $gestora->display_name ) . '</strong><small>Precios y pedidos gestionados con el respaldo de Casa Viva.</small></aside>';
	}

	public static function snapshot_line( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		unset( $cart_item_key, $order ); $gestora = self::active_gestora();
		$product = $values['data'] ?? null; if ( ! $product instanceof WC_Product ) { return; }
		$gestora_id = absint( $values['_cvd_gestora_id'] ?? ( $gestora ? $gestora->ID : 0 ) ); if ( ! $gestora_id ) { return; }
		$base = isset( $values['_cvd_base_price'] ) ? (float) $values['_cvd_base_price'] : (float) get_post_meta( $product->get_id(), '_price', true );
		$sale = isset( $values['_cvd_locked_price'] ) ? (float) $values['_cvd_locked_price'] : (float) $product->get_price();
		$item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $base, 4 ), true );
		$item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $sale, 4 ), true );
		$item->add_meta_data( '_cvd_margin_unit', wc_format_decimal( max( 0, $sale - $base ), 4 ), true );
		$item->add_meta_data( '_cvd_pricing_gestora_user_id', $gestora_id, true );
	}

	public static function snapshot_order( WC_Order $order, array $data ): void {
		unset( $data ); $gestora = self::active_gestora(); $gestora_id = $gestora ? $gestora->ID : 0;
		if ( WC()->cart ) { foreach ( WC()->cart->get_cart() as $cart_item ) { $gestora_id = absint( $cart_item['_cvd_gestora_id'] ?? $gestora_id ); if ( $gestora_id ) { break; } } }
		if ( ! $gestora_id ) { return; }
		$order->update_meta_data( '_cvd_pricing_gestora_user_id', $gestora_id );
		$order->update_meta_data( '_cvd_pricing_snapshot_at', current_time( 'mysql', true ) );
	}
}
