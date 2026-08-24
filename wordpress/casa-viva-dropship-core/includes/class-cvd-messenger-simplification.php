<?php

defined( 'ABSPATH' ) || exit;

/**
 * Capa visual P0.3 para simplificar las superficies de mensajería sin duplicar
 * estados, cobros, tarifas ni lógica de negocio del Core.
 */
final class CVD_Messenger_Simplification {
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 80 );
	}

	public static function assets(): void {
		if ( ! is_page( array( 'area-mensajeros', 'ruta-cv', 'interpretar-vale' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'cvd-messenger-simplify',
			CVD_URL . 'assets/messenger-simplify.css',
			array(),
			CVD_VERSION
		);
		wp_enqueue_script(
			'cvd-messenger-simplify',
			CVD_URL . 'assets/messenger-simplify.js',
			array(),
			CVD_VERSION,
			true
		);
	}
}
