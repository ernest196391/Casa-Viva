<?php

defined( 'ABSPATH' ) || exit;

/** Tienda espejo y precios de reventa sin duplicar el catálogo. */
final class CVD_Gestora_Store {
	private static bool $resolving_price = false;
	private static array $price_maps = array();

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
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
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'lock_cart_price' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_locked_cart_prices' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'snapshot_line' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'snapshot_order' ), 15, 2 );
	}

	public static function preserve_referral_on_product_links( string $url, WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $url;
		}
		$owner = self::pricing_owner_from_request();
		if ( ! $owner || empty( $owner['referral_code'] ) ) {
			return $url;
		}
		return add_query_arg( 'ref', $owner['referral_code'], $url );
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

	public static function active_gestora(): ?WP_User {
		if ( is_admin() && ! wp_doing_ajax() ) { return null; }
		// La cookie atribuye la venta, pero nunca debe alterar la tienda general.
		// Los precios de la tienda espejo requieren un código explícito en la URL.
		$owner = self::pricing_owner_from_request();
		if ( ! $owner || 'gestora' !== ( $owner['owner_type'] ?? '' ) ) { return null; }
		$user = get_user_by( 'id', absint( $owner['owner_user_id'] ?? 0 ) );
		if ( ! $user || 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) { return null; }
		return $user;
	}

	/** Gestora usada exclusivamente para presentar precios de tienda espejo. */
	private static function pricing_owner_from_request(): ?array {
		$raw = isset( $_GET['ref'] ) ? wp_unslash( $_GET['ref'] ) : ( isset( $_GET['cv_ref'] ) ? wp_unslash( $_GET['cv_ref'] ) : '' );
		$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( (string) $raw ) ) );
		if ( ! $code ) { return null; }
		$owner = CVD_Attribution::current_referral_owner();
		return $owner && hash_equals( (string) ( $owner['referral_code'] ?? '' ), $code ) ? $owner : null;
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
		$max = (float) $product->get_meta( '_cvd_max_price', true );
		$min = $min > 0 ? $min : $base;
		if ( $max <= 0 ) {
			$user_max = (float) get_user_meta( $gestora_id, '_cvd_max_markup_percent', true );
			$max_percent = $user_max > 0 ? $user_max : (float) get_option( 'cvd_default_max_markup_percent', 30 );
			$max = $base * ( 1 + $max_percent / 100 );
		}
		return wc_format_decimal( min( $max, max( $min, $price ) ), wc_get_price_decimals() );
	}

	public static function limits( WC_Product $product, int $gestora_id ): array {
		$base = self::base_price( $product );
		$min = (float) $product->get_meta( '_cvd_min_price', true );
		$max = (float) $product->get_meta( '_cvd_max_price', true );
		$min = $min > 0 ? $min : $base;
		if ( $max <= 0 ) {
			$user_max = (float) get_user_meta( $gestora_id, '_cvd_max_markup_percent', true );
			$percent = $user_max > 0 ? $user_max : (float) get_option( 'cvd_default_max_markup_percent', 30 );
			$max = $base * ( 1 + $percent / 100 );
		}
		return array( 'base' => $base, 'min' => $min, 'max' => $max );
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
			if ( $price < $limits['min'] || $price > $limits['max'] ) { $errors[] = $product->get_name(); continue; }
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
