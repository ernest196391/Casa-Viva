<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.13.95 — Los productos «Privado» no salen en la web para nadie (Ernesto y Lennys, 13-oct, 22:00).
 *
 * WordPress enseña los productos privados a los administradores, con «Privado:» delante del nombre, en la tienda, la
 * búsqueda y la ficha. Lennys hizo una prueba en la tienda de una gestora y pudo comprar dos (pedido #3668). Los
 * clientes y las gestoras ya no los veían. Ahora, en la web, tampoco los administradores: la ficha da 404 y no se
 * pueden añadir al carrito (lo que ya estuviera en un carrito sale solo al cargarlo, aviso de WooCommerce).
 * Siguen en wp-admin y en el panel, para revisarlos y volver a publicarlos, porque esas llamadas no pasan por aquí: la
 * API REST, salvo la del carrito (wc/store), wp-cli y cron. Una vista previa desde wp-admin (?preview=true) sigue
 * funcionando.
 * Interruptor: `cvd_hide_private_front` = no lo apaga.
 */
final class CVD_Private_Products {
	public static function register(): void {
		if ( 'no' === get_option( 'cvd_hide_private_front', 'yes' ) ) { return; }
		add_action( 'pre_get_posts', array( __CLASS__, 'only_published' ), 5 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
	}

	/** ¿Es la web que ve la gente? Sí: páginas y admin-ajax llamado desde la web. No: wp-admin, REST (salvo wc/store), wp-cli, cron. */
	private static function is_storefront( bool $allow_store_api = false ): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) { return false; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $allow_store_api && false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wc/store' );
		}
		if ( wp_doing_ajax() ) { return false === strpos( (string) wp_get_referer(), '/wp-admin/' ); }
		return ! is_admin();
	}

	/** Consultas de productos en la web: solo los publicados, también para quien puede ver los privados. */
	public static function only_published( WP_Query $q ): void {
		if ( ! self::is_storefront() || ! current_user_can( 'read_private_products' ) ) { return; }
		if ( $q->is_preview() || isset( $_GET['preview'] ) ) { return; }
		$types    = (array) $q->get( 'post_type' );
		$products = in_array( 'product', $types, true ) || in_array( 'product_variation', $types, true )
			|| $q->is_post_type_archive( 'product' ) || $q->is_tax( get_object_taxonomies( 'product' ) )
			|| ( $q->is_search() && ( empty( $q->get( 'post_type' ) ) || 'any' === $q->get( 'post_type' ) ) );
		if ( ! $products ) { return; }
		$status = $q->get( 'post_status' );
		if ( '' === $status || 'any' === $status || in_array( 'private', (array) $status, true ) ) {
			$q->set( 'post_status', in_array( 'product_variation', $types, true ) && ! in_array( 'product', $types, true ) ? array( 'publish', 'private' ) : 'publish' );
		}
	}

	/** No se puede comprar en la web un producto privado (ni sus variaciones), aunque quien mire sea administrador. */
	public static function purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof WC_Product || ! self::is_storefront( true ) ) { return $purchasable; }
		$parent = $product->get_parent_id() ? get_post_status( $product->get_parent_id() ) : $product->get_status();
		return 'publish' === $parent ? $purchasable : false;
	}
}
