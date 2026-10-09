<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mercado "bajo pedido" (decisión de Ernesto, 2026-10-09).
 *
 * VivaBot publica aquí los artículos de proveedores de los grupos que Ernesto aprobó:
 * SKU `MK-<id>`, categoría «Bajo pedido», sin control de stock (se confirma con el
 * proveedor antes de cobrar) y comisión fija = mitad de la ganancia de Ernesto.
 * El proveedor nunca se guarda aquí: solo el id del artículo en VivaBot.
 * Autenticación: la misma cabecera X-Vivabot-Key que el resto del puente.
 */
final class CVD_Market_Bridge {
	private const NS = 'casa-viva/v1';
	public const SKU_PREFIX = 'MK-';
	public const CATEGORY = 'Bajo pedido';
	public const NOTICE = 'Producto bajo pedido: confirmamos disponibilidad en 1 hora antes de cobrar.';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/market/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'publish' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/market/(?P<item>\d+)/unpublish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unpublish' ), 'permission_callback' => $auth ) );
	}

	public static function is_market_sku( string $sku ): bool {
		return 0 === strpos( $sku, self::SKU_PREFIX );
	}

	private static function find( int $item ): ?WC_Product {
		$id = wc_get_product_id_by_sku( self::SKU_PREFIX . $item );
		$product = $id ? wc_get_product( $id ) : null;
		return $product instanceof WC_Product ? $product : null;
	}

	private static function category_id(): int {
		$term = get_term_by( 'name', self::CATEGORY, 'product_cat' );
		if ( $term ) { return (int) $term->term_id; }
		$new = wp_insert_term( self::CATEGORY, 'product_cat', array( 'slug' => 'bajo-pedido' ) );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/** Solo imágenes https públicas de Supabase Storage (las que firma VivaBot). */
	private static function allowed_image( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 0 === strpos( $url, 'https://' ) && (bool) preg_match( '/\.supabase\.co$/', $host );
	}

	private static function sideload( string $url, int $product_id, string $title ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) { return 0; }
		$file = array( 'name' => sanitize_file_name( 'mercado-' . $product_id . '-' . wp_generate_password( 6, false ) . '.jpg' ), 'tmp_name' => $tmp );
		$id = media_handle_sideload( $file, $product_id, $title );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
		return (int) $id;
	}

	public static function publish( WP_REST_Request $request ): WP_REST_Response {
		$item = absint( $request->get_param( 'item_id' ) );
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$price = (float) $request->get_param( 'price' );
		$commission = (float) $request->get_param( 'commission' );
		$version = absint( $request->get_param( 'version' ) );
		if ( ! $item || '' === $title || $price <= 0 || ! $version ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Faltan item_id, title, price o version.' ), 400 );
		}
		// La comisión del gestor (mitad de la ganancia) debe ser positiva: un 0 explícito anularía la tarifa.
		if ( $commission <= 0 || $commission >= $price ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'La comisión debe ser mayor que 0 y menor que el precio.' ), 400 );
		}
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		$images = array_values( array_filter( array_map( 'esc_url_raw', (array) $request->get_param( 'images' ) ), array( __CLASS__, 'allowed_image' ) ) );

		$product = self::find( $item ) ?: new WC_Product_Simple();
		$is_new = ! $product->get_id();
		// Idempotente: un reintento viejo nunca pisa una versión más nueva (la versión la pone VivaBot).
		if ( $stale = self::stale( $product, $version ) ) { return $stale; }
		$product->set_name( $title );
		$product->set_sku( self::SKU_PREFIX . $item );
		$product->set_regular_price( wc_format_decimal( $price, 2 ) );
		$product->set_sale_price( '' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_short_description( esc_html( self::NOTICE ) );
		$product->set_description( $description ? '<p>' . nl2br( esc_html( $description ) ) . '</p>' : '' );
		$cat = self::category_id();
		if ( $cat ) { $product->set_category_ids( array( $cat ) ); }
		$product->update_meta_data( '_cvd_market_item_id', $item );
		$product->update_meta_data( '_cvd_market_version', $version );
		$product->update_meta_data( '_cvd_commission_type', 'fixed' );
		$product->update_meta_data( '_cvd_commission_value', wc_format_decimal( $commission, 2 ) );
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product_id = $product->save();

		// Fotos: solo la primera vez o si VivaBot manda otras nuevas.
		if ( $images && ( $is_new || $request->get_param( 'replace_images' ) ) ) {
			$ids = array();
			foreach ( array_slice( $images, 0, 4 ) as $url ) {
				$att = self::sideload( $url, $product_id, $title );
				if ( $att ) { $ids[] = $att; }
			}
			if ( $ids ) {
				$product->set_image_id( array_shift( $ids ) );
				$product->set_gallery_image_ids( $ids );
				$product->save();
			}
		}

		return new WP_REST_Response( array( 'ok' => true, 'product_id' => $product_id, 'url' => get_permalink( $product_id ), 'new' => $is_new ), 200 );
	}

	/** Respuesta "ya aplicado" si llega una versión igual o anterior a la guardada. */
	private static function stale( WC_Product $product, int $version ): ?WP_REST_Response {
		$current = $product->get_id() ? absint( $product->get_meta( '_cvd_market_version', true ) ) : 0;
		if ( $current && $version <= $current ) {
			return new WP_REST_Response( array( 'ok' => true, 'stale' => true, 'product_id' => $product->get_id(), 'url' => get_permalink( $product->get_id() ), 'status' => $product->get_status() ), 200 );
		}
		return null;
	}

	public static function unpublish( WP_REST_Request $request ): WP_REST_Response {
		$version = absint( $request->get_param( 'version' ) );
		if ( ! $version ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'Falta version.' ), 400 ); }
		$product = self::find( absint( $request['item'] ) );
		if ( ! $product ) { return new WP_REST_Response( array( 'ok' => true, 'found' => false ), 200 ); }
		if ( $stale = self::stale( $product, $version ) ) { return $stale; }
		$product->update_meta_data( '_cvd_market_version', $version );
		$product->set_status( 'private' );
		$product->save();
		return new WP_REST_Response( array( 'ok' => true, 'found' => true, 'product_id' => $product->get_id() ), 200 );
	}
}
