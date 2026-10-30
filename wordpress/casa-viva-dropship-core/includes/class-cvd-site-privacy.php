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
		add_action( 'wp', array( __CLASS__, 'swap_social_meta' ), 20 );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'structured_data' ), 20, 2 );
	}

	/**
	 * 3.14.83 (auditoría del catálogo, 29-oct): texto del producto para Google (meta description), la vista previa de
	 * WhatsApp (og:description) y el schema. El aviso de bajo pedido de la descripción corta no describe nada: 688
	 * productos tenían la MISMA meta. Orden: _cvd_meta_description → descripción corta si no es un aviso → la larga →
	 * el nombre. Si queda corto, se completa con cómo se compra.
	 */
	public static function product_text( WC_Product $p ): string {
		$own = trim( (string) $p->get_meta( '_cvd_meta_description' ) );
		if ( '' !== $own ) { return $own; }
		$short = self::plain( $p->get_short_description() );
		$text = self::is_notice( $short ) ? self::plain( $p->get_description() ) : $short;
		if ( '' === $text ) { $text = $p->get_name(); }
		elseif ( mb_strlen( $text ) < 40 && false === mb_stripos( $text, $p->get_name() ) ) { $text = $p->get_name() . '. ' . $text; }
		if ( mb_strlen( $text ) <= 100 ) {
			$market = class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::is_market_sku( (string) $p->get_sku() );
			$text = preg_replace( '/[\s.…]+$/u', '', $text ) . '. ' . ( $market ? 'Bajo pedido en Casa Viva: confirmamos disponibilidad en 1 hora. Entrega en La Habana.' : 'En Casa Viva, La Habana: entrega a domicilio o recogida en Nuevo Vedado.' );
		}
		return $text;
	}

	/** Avisos genéricos que no dicen qué es el producto (bajo pedido y «consulta disponibilidad…»). */
	private static function is_notice( string $text ): bool {
		return '' === $text || (bool) preg_match( '/^(producto )?bajo pedido\b|^consulta disponibilidad, variantes/iu', $text );
	}

	private static function plain( $html ): string {
		// Sin emojis: los textos de proveedor los traen y en Google y WhatsApp quedan como cuadritos o ruido.
		$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', wp_strip_all_tags( strip_shortcodes( (string) $html ) ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/** El tema (cv_storefront_product_social_meta) usa la descripción corta: en la ficha se cambia por la nuestra. */
	public static function swap_social_meta(): void {
		if ( function_exists( 'is_product' ) && is_product() && false !== has_action( 'wp_head', 'cv_storefront_product_social_meta' ) ) {
			remove_action( 'wp_head', 'cv_storefront_product_social_meta', 5 );
			add_action( 'wp_head', array( __CLASS__, 'social_meta' ), 5 );
		}
	}

	/** Igual que el tema (mismas etiquetas y foto «large»), con el texto de product_text(). */
	public static function social_meta(): void {
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) { return; }
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
		$description = wp_html_excerpt( self::product_text( $product ), 200, '…' );
		echo '<meta property="og:type" content="product">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $product->get_name() ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $product->get_id() ) ) . '">' . "\n";
		if ( $description ) { echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n"; }
		if ( $image ) { echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n"; }
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	}

	/** schema.org Product: descripción de verdad (no el aviso) y hasta 5 fotos en vez de 1. */
	public static function structured_data( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product instanceof WC_Product ) { return $markup; }
		$short = self::plain( $product->get_short_description() );
		if ( self::is_notice( $short ) ) {
			$long = self::plain( $product->get_description() );
			$markup['description'] = '' !== $long ? $long : self::product_text( $product );
		}
		$ids = array_slice( array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) ) ), 0, 5 );
		$images = array_values( array_filter( array_map( static fn( $id ) => wp_get_attachment_image_url( $id, 'large' ), $ids ) ) );
		if ( count( $images ) > 1 ) { $markup['image'] = $images; }
		return $markup;
	}

	/** Descripción para Google en productos, tienda y categorías (la portada ya trae la suya). */
	public static function meta_description(): void {
		$text = '';
		if ( function_exists( 'is_product' ) && is_product() ) {
			$p = wc_get_product( get_queried_object_id() );
			if ( $p ) { $text = self::product_text( $p ); }
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
