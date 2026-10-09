<?php

defined( 'ABSPATH' ) || exit;

/** Launch-safe catalog presentation helpers. Does not mutate WooCommerce product data. */
final class CVD_Catalog_Presentation {
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 35 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 20, 2 );
		add_filter( 'the_content', array( __CLASS__, 'strip_description_prices' ), 20 );
		add_filter( 'woocommerce_short_description', array( __CLASS__, 'strip_description_prices' ), 20 );
	}

	/**
	 * Las descripciones copiadas de BizneCubano traen el precio de tienda. En la ficha
	 * solo vale el precio de WooCommerce (el de la gestora si entra por su enlace).
	 */
	public static function strip_description_prices( $html ) {
		if ( ! is_string( $html ) || is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) { return $html; }
		$money = '/(💲|\d\s*\$|\$\s*\d|\d[\d.,]*\s*(usd|cup|mn|eur|euros?|d[oó]lares?|pesos)\b|precio\s*(:|de\s+tienda|\$|💲|\d))/iu';
		$parts = preg_split( '/(<\/p>|<br\s*\/?>|\R)/iu', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out = '';
		for ( $i = 0, $n = count( $parts ); $i < $n; $i += 2 ) {
			$text = $parts[ $i ];
			$sep  = $parts[ $i + 1 ] ?? '';
			if ( preg_match( $money, wp_strip_all_tags( $text ) ) ) {
				continue;
			}
			$out .= $text . $sep;
		}
		return $out;
	}

	private static function is_catalog_surface(): bool {
		if ( is_admin() || wp_doing_ajax() ) { return false; }
		return ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_product_tag' ) && is_product_tag() );
	}

	public static function assets(): void {
		if ( ! self::is_catalog_surface() ) { return; }
		wp_enqueue_style( 'cvd-catalog-presentation', CVD_URL . 'assets/catalog-presentation.css', array(), CVD_VERSION );
	}

	public static function body_class( array $classes ): array {
		if ( self::is_catalog_surface() ) { $classes[] = 'cvd-catalog-launch'; }
		return $classes;
	}

	public static function add_to_cart_text( string $text, $product ): string {
		if ( ! self::is_catalog_surface() || ! is_object( $product ) || ! method_exists( $product, 'is_in_stock' ) ) { return $text; }
		if ( ! $product->is_in_stock() ) { return 'Agotado'; }
		return $text;
	}
}
