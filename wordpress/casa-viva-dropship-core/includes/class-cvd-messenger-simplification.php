<?php

defined( 'ABSPATH' ) || exit;

/**
 * Capa visual P0.3 para simplificar las superficies de mensajería sin duplicar
 * estados, cobros, tarifas ni lógica de negocio del Core.
 */
final class CVD_Messenger_Simplification {
	public static function register(): void {
		// Portal registra sus assets con prioridad 10. Entramos después para poder
		// modificar su configuración antes de que portal.js se ejecute en navegador.
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
		wp_enqueue_style(
			'cvd-messenger-simplify-fixes',
			CVD_URL . 'assets/messenger-simplify-fixes.css',
			array( 'cvd-messenger-simplify' ),
			CVD_VERSION
		);
		wp_enqueue_script(
			'cvd-messenger-simplify',
			CVD_URL . 'assets/messenger-simplify.js',
			array(),
			CVD_VERSION,
			true
		);

		// Estabilidad del piloto: portal.js inicia pollOffers() inmediatamente cuando
		// cvdPortal.isMessenger es true y ese bloque contiene recargas automáticas.
		// Desactivamos ese polling en las dos superficies del mensajero. El resto de
		// funciones (contactos, WhatsApp, ruta, cobros, asistente) no depende de esta
		// bandera. Los avisos push siguen gestionándose por CVD_PWA.
		if ( is_page( array( 'area-mensajeros', 'ruta-cv' ) ) ) {
			wp_add_inline_script(
				'cvd-portal',
				'if (window.cvdPortal) { window.cvdPortal.isMessenger = false; }',
				'before'
			);
		}
	}
}