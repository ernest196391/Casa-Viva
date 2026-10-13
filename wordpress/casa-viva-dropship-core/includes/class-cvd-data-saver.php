<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.13.91 — Menos datos en el teléfono (auditoría de cliente del 13-oct, punto 11; pedido de Ernesto).
 *
 * Solo cosas seguras y que se quitan con un cambio: no toca precios, existencias, pedidos ni el plan de Hostinger.
 * 1. WooCommerce pre-descargaba en cada página el código del carrito (React, ~550 KB) cuando había algo en el carrito.
 *    Ahora se descarga solo al abrir el carrito.
 * 2. En la ficha, en el teléfono, la lupa (zoom) bajaba la foto grande nada más abrir la página. En el teléfono se quita
 *    la lupa; tocar la foto sigue abriéndola a pantalla completa. En el ordenador sigue igual.
 * 3. En la ficha, las fotos 2, 3… de la galería se bajan cuando se van a ver, no todas al abrir.
 * 4. En las listas de productos (tienda, categorías, relacionados) el teléfono pide la foto del tamaño de la tarjeta
 *    (media pantalla), no la foto entera.
 * Interruptor: `cvd_data_saver` = no lo apaga todo.
 */
final class CVD_Data_Saver {
	public static function register(): void {
		if ( 'no' === get_option( 'cvd_data_saver', 'yes' ) ) { return; }
		add_filter( 'wp_resource_hints', array( __CLASS__, 'no_cart_prefetch' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'no_touch_zoom' ), 30 );
		add_filter( 'woocommerce_gallery_image_html_attachment_image_params', array( __CLASS__, 'lazy_gallery_image' ), 20, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'card_image_sizes' ), 20, 3 );
	}

	/** 1. Sin rel=prefetch del código del carrito y del pago de WooCommerce (assets/client/blocks/). */
	public static function no_cart_prefetch( $urls, $relation_type ) {
		if ( 'prefetch' !== $relation_type || ! is_array( $urls ) ) { return $urls; }
		return array_values( array_filter( $urls, static function ( $url ): bool {
			$href = is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url;
			return false === strpos( $href, '/woocommerce/assets/client/blocks/' );
		} ) );
	}

	/** 2. Antes de single-product.js: en pantallas táctiles, sin lupa (no baja la foto grande al abrir la ficha). */
	public static function no_touch_zoom(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! wp_script_is( 'wc-single-product', 'enqueued' ) ) { return; }
		wp_add_inline_script( 'wc-single-product', "if(window.wc_single_product_params&&('ontouchstart' in document.documentElement||(window.matchMedia&&window.matchMedia('(hover: none)').matches))){window.wc_single_product_params.zoom_enabled=false;}", 'before' );
	}

	/** 3. Fotos de la galería que no son la primera: se bajan al verlas. */
	public static function lazy_gallery_image( $params, $attachment_id, $image_size, $main_image ) {
		unset( $attachment_id, $image_size );
		if ( ! $main_image && is_array( $params ) ) { $params['loading'] = 'lazy'; }
		return $params;
	}

	/** 4. Tarjetas de producto: en el teléfono ocupan media pantalla (2 columnas), no 300 px a pantalla completa. */
	public static function card_image_sizes( $attr, $attachment, $size ) {
		unset( $attachment );
		if ( is_admin() || 'woocommerce_thumbnail' !== $size || empty( $attr['sizes'] ) ) { return $attr; }
		$attr['sizes'] = str_replace( '(max-width: 300px) 100vw, 300px', '(max-width: 600px) 48vw, 300px', (string) $attr['sizes'] );
		return $attr;
	}
}
