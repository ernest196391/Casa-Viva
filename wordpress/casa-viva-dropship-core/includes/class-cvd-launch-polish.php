<?php

defined( 'ABSPATH' ) || exit;

/**
 * Launch-safe polish for the public storefront.
 *
 * This layer is presentation-only: it does not mutate products, stock, prices,
 * shipping rates, orders, roles, commissions, payouts or operational state.
 */
final class CVD_Launch_Polish {
	private const HOME_TITLE = 'Casa Viva | Tienda online para el hogar en La Habana';
	private const HOME_DESCRIPTION = 'Compra productos para el hogar en Casa Viva con precios visibles, catálogo online, recogida en Nuevo Vedado y entrega en La Habana.';

	public static function register(): void {
		add_action( 'wp', array( __CLASS__, 'prepare_home_head' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 50 );
		add_action( 'wp_head', array( __CLASS__, 'home_meta' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'home_h1' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
	}

	private static function is_public_home(): bool {
		return ! is_admin() && ! wp_doing_ajax() && is_front_page();
	}

	public static function prepare_home_head(): void {
		if ( self::is_public_home() ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	public static function document_title( string $title ): string {
		return self::is_public_home() ? self::HOME_TITLE : $title;
	}

	public static function home_meta(): void {
		if ( ! self::is_public_home() ) { return; }
		echo '<meta name="description" content="' . esc_attr( self::HOME_DESCRIPTION ) . '" data-cvd-launch-meta="description">' . "\n";
		echo '<link rel="canonical" href="' . esc_url( home_url( '/' ) ) . '" data-cvd-launch-meta="canonical">' . "\n";
	}

	public static function home_h1(): void {
		if ( ! self::is_public_home() ) { return; }
		echo '<h1 class="cvd-launch-home-h1">Casa Viva — tienda online para el hogar en La Habana</h1>';
	}

	public static function assets(): void {
		if ( ! self::is_public_home() ) { return; }
		wp_enqueue_style( 'cvd-launch-polish', CVD_URL . 'assets/launch-polish.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-launch-polish', CVD_URL . 'assets/launch-polish.js', array(), CVD_VERSION, true );
		wp_localize_script(
			'cvd-launch-polish',
			'cvdLaunchPolish',
			array(
				'categoryFallback' => CVD_URL . 'assets/category-fallback.svg',
			)
		);
	}
}
