<?php

defined( 'ABSPATH' ) || exit;

/** Launch-safe catalog presentation helpers. Does not mutate WooCommerce product data. */
final class CVD_Catalog_Presentation {
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 35 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 20, 2 );
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
