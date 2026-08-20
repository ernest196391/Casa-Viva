<?php

defined( 'ABSPATH' ) || exit;

/** Mobile-first navigation for the customer-facing WooCommerce experience. */
final class CVD_Customer_Navigation {
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 30 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'cart_fragments' ) );
		add_action( 'wp_ajax_cvd_cart_count', array( __CLASS__, 'ajax_cart_count' ) );
		add_action( 'wp_ajax_nopriv_cvd_cart_count', array( __CLASS__, 'ajax_cart_count' ) );
	}

	private static function is_customer_surface(): bool {
		if ( is_admin() || wp_doing_ajax() ) { return false; }
		if ( function_exists( 'is_checkout' ) && is_checkout() ) { return false; }
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$program = class_exists( 'CVD_Registration' ) ? CVD_Registration::program_type( $user ) : '';
			if ( in_array( $program, array( 'gestora', 'mensajero' ), true ) ) { return false; }
			if ( array_intersect( array( 'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator' ), (array) $user->roles ) ) { return false; }
		}
		return true;
	}

	public static function assets(): void {
		if ( ! self::is_customer_surface() ) { return; }
		wp_enqueue_style( 'cvd-customer-navigation', CVD_URL . 'assets/customer-navigation.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-customer-navigation', CVD_URL . 'assets/customer-navigation.js', array( 'jquery' ), CVD_VERSION, true );
		wp_localize_script( 'cvd-customer-navigation', 'cvdCustomerNav', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	}

	public static function body_class( array $classes ): array {
		if ( self::is_customer_surface() ) { $classes[] = 'cvd-customer-navigation-visible'; }
		return $classes;
	}

	private static function cart_count(): int {
		return function_exists( 'WC' ) && WC()->cart ? absint( WC()->cart->get_cart_contents_count() ) : 0;
	}

	private static function badge_html(): string {
		$count = self::cart_count();
		return '<span class="cvd-customer-nav__badge" data-cvd-cart-count aria-label="' . esc_attr( sprintf( _n( '%d producto en el carrito', '%d productos en el carrito', $count, 'casa-viva-dropship' ), $count ) ) . '"' . ( $count ? '' : ' hidden' ) . '>' . esc_html( $count ) . '</span>';
	}

	public static function cart_fragments( array $fragments ): array {
		$fragments['span[data-cvd-cart-count]'] = self::badge_html();
		return $fragments;
	}

	public static function ajax_cart_count(): void { wp_send_json_success( array( 'count' => self::cart_count() ) ); }

	private static function active_key(): string {
		if ( function_exists( 'is_cart' ) && is_cart() ) { return 'cart'; }
		if ( function_exists( 'is_account_page' ) && is_account_page() ) { return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' ) ? 'orders' : 'account'; }
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) { return 'categories'; }
		return is_front_page() ? 'home' : '';
	}

	private static function item( string $key, string $label, string $url, string $glyph, string $active ): string {
		$classes = 'cvd-customer-nav__item' . ( $key === $active ? ' is-active' : '' ) . ( 'cart' === $key ? ' cvd-customer-nav__item--cart' : '' );
		$current = $key === $active ? ' aria-current="page"' : '';
		$badge = '';
		if ( 'cart' === $key ) { $badge = self::badge_html(); }
		if ( 'orders' === $key && class_exists( 'CVD_Customer_Orders' ) && is_user_logged_in() ) { $badge = CVD_Customer_Orders::badge_html(); }
		return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '"' . $current . ' data-cvd-nav="' . esc_attr( $key ) . '"><span class="cvd-customer-nav__icon" aria-hidden="true">' . esc_html( $glyph ) . '</span><span class="cvd-customer-nav__label">' . esc_html( $label ) . '</span>' . $badge . '</a>';
	}

	public static function render(): void {
		if ( ! self::is_customer_surface() || ! function_exists( 'wc_get_page_permalink' ) ) { return; }
		$active = self::active_key();
		$account_url = wc_get_page_permalink( 'myaccount' );
		$orders_url = is_user_logged_in() ? wc_get_account_endpoint_url( 'orders' ) : $account_url;
		echo '<nav class="cvd-customer-nav" aria-label="Navegación principal">';
		echo self::item( 'home', 'Inicio', home_url( '/' ), '⌂', $active );
		echo self::item( 'categories', 'Comprar', wc_get_page_permalink( 'shop' ), '▦', $active );
		echo self::item( 'cart', 'Carrito', wc_get_cart_url(), '🛒', $active );
		echo self::item( 'orders', 'Pedidos', $orders_url, '≡', $active );
		echo self::item( 'account', 'Mi cuenta', $account_url, '○', $active );
		echo '</nav>';
	}
}
