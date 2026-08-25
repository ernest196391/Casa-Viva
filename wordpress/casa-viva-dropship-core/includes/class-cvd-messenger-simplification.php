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

		wp_enqueue_style( 'cvd-messenger-simplify', CVD_URL . 'assets/messenger-simplify.css', array(), CVD_VERSION );
		wp_enqueue_style( 'cvd-messenger-simplify-fixes', CVD_URL . 'assets/messenger-simplify-fixes.css', array( 'cvd-messenger-simplify' ), CVD_VERSION );
		wp_enqueue_style( 'cvd-messenger-premium-ux', CVD_URL . 'assets/messenger-premium-ux.css', array( 'cvd-messenger-simplify-fixes' ), CVD_VERSION );
		wp_enqueue_style( 'cvd-messenger-premium-ux-polish', CVD_URL . 'assets/messenger-premium-ux-polish.css', array( 'cvd-messenger-premium-ux' ), CVD_VERSION );

		wp_enqueue_script( 'cvd-messenger-simplify', CVD_URL . 'assets/messenger-simplify.js', array(), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-messenger-premium-ux', CVD_URL . 'assets/messenger-premium-ux.js', array( 'cvd-messenger-simplify' ), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-messenger-assistant-summary', CVD_URL . 'assets/messenger-assistant-summary.js', array( 'cvd-messenger-premium-ux' ), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-messenger-premium-ux-polish', CVD_URL . 'assets/messenger-premium-ux-polish.js', array( 'cvd-messenger-assistant-summary' ), CVD_VERSION, true );

		if ( is_page( array( 'area-mensajeros', 'ruta-cv' ) ) ) {
			wp_add_inline_script(
				'cvd-messenger-simplify',
				'document.addEventListener("DOMContentLoaded",function(){var c=document.querySelector(".cvd-messenger-center");if(!c)return;var cards=Array.prototype.slice.call(c.querySelectorAll("[data-delivery-id]"));if(!cards.length)return;var current=cards.find(function(card){return Array.prototype.slice.call(card.querySelectorAll("a")).some(function(a){return (a.textContent||"").trim()==="Navegar";});})||cards[0];cards.forEach(function(card){card.classList.toggle("is-current",card===current);});});',
				'after'
			);
		}

		if ( is_page( array( 'area-mensajeros', 'ruta-cv' ) ) ) {
			wp_add_inline_script(
				'cvd-portal',
				'if (window.cvdPortal) { window.cvdPortal.isMessenger = false; }',
				'before'
			);
		}
	}
}
