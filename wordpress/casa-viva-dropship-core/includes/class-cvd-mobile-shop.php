<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.13.84 — La tienda en el teléfono (auditoría de cliente del 13-oct, pedida por Ernesto).
 *
 * Solo presentación: menos espacio vacío arriba, precio y botón en la primera pantalla de la ficha,
 * barra de compra opaca, colores con nombre legible y «Comprar ahora» que primero pide el color.
 * No cambia precios, existencias, comisiones ni pedidos.
 */
final class CVD_Mobile_Shop {
	public static function register(): void {
		// Después del plugin de marca (prioridad 999): sus reglas generales de títulos no deben ganar.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 1000 );
	}

	public static function assets(): void {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'cvd-mobile-shop', CVD_URL . 'assets/mobile-shop.css', array(), CVD_VERSION );
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'cvd-mobile-shop', CVD_URL . 'assets/mobile-shop.js', array( 'jquery' ), CVD_VERSION, true );
		}
	}
}
