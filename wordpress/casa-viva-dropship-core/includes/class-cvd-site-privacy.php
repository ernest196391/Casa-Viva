<?php

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes de lanzamiento (3.13.40, revisión de la web 2026-10-11):
 * - La lista de usuarios (/wp-json/wp/v2/users) y su sitemap no se publican a visitantes.
 * - Las páginas internas (operación, caja, contabilidad, ayuda interna) no se indexan ni salen en el sitemap.
 * - En el checkout se aclara que la mensajería se paga en CUP al mensajero.
 */
final class CVD_Site_Privacy {
	private const INTERNAL = array( 'centro-operaciones', 'inventario', 'ventas', 'contabilidad', 'mensajeria', 'contabilidad-mensajeros', 'interpretar-vale', 'ruta-cv', 'casa-viva-app', 'ayuda' );

	public static function register(): void {
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_users' ) );
		add_filter( 'wp_sitemaps_add_provider', static fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_exclude' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'cup_note' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'meta_description' ), 2 );
	}

	/** Descripción para Google en productos, tienda y categorías (la portada ya trae la suya). */
	public static function meta_description(): void {
		$text = '';
		if ( function_exists( 'is_product' ) && is_product() ) {
			$p = wc_get_product( get_queried_object_id() );
			if ( $p ) { $text = trim( (string) $p->get_meta( '_cvd_meta_description' ) ) ?: wp_strip_all_tags( $p->get_short_description() ?: $p->get_description() ); if ( '' === trim( $text ) ) { $text = $p->get_name() . ' en Casa Viva, tienda para el hogar en La Habana. Entrega a domicilio o recogida en tienda.'; } }
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			$text = $term && $term->description ? wp_strip_all_tags( $term->description ) : ( $term ? $term->name . ' para tu hogar en Casa Viva, La Habana. Entrega a domicilio o recogida en tienda.' : '' );
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$text = 'Tienda online de Casa Viva: artículos para el hogar, baño, cocina y decoración en La Habana. Entrega a domicilio o recogida en tienda.';
		}
		if ( '' !== $text ) { echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $text, 158, '…' ) ) . '">' . "\n"; }
	}

	public static function hide_users( array $endpoints ): array {
		if ( is_user_logged_in() ) { return $endpoints; }
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( $route, '/wp/v2/users' ) ) { unset( $endpoints[ $route ] ); }
		}
		return $endpoints;
	}

	private static function internal_ids(): array {
		$ids = array();
		foreach ( self::INTERNAL as $slug ) { $page = get_page_by_path( $slug ); if ( $page ) { $ids[] = $page->ID; } }
		return $ids;
	}

	public static function sitemap_exclude( array $args, string $post_type ): array {
		if ( 'page' === $post_type ) { $args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), self::internal_ids() ); }
		return $args;
	}

	public static function noindex( array $robots ): array {
		if ( is_page( self::INTERNAL ) ) { $robots['noindex'] = true; $robots['nofollow'] = true; }
		return $robots;
	}

	public static function cup_note(): void {
		echo '<tr class="cvd-shipping-note"><td colspan="2"><small>Los productos se pagan en USD. La mensajería se paga aparte, en CUP, al mensajero cuando te entrega.</small></td></tr>';
	}
}
