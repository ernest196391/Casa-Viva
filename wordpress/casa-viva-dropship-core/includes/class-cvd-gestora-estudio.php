<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Mi estudio de fotos» en el área de la gestora (3.14.1, decisión de Ernesto 14-oct).
 *
 * - Cada gestora conecta SU clave de Google (Gemini API key de AI Studio). Se comprueba con una llamada mínima,
 *   se guarda cifrada (CVD_Estudio_Vault) y nunca vuelve al navegador ni sale en registros. Todas las llamadas
 *   a Gemini salen del servidor con su clave.
 * - Elige un producto de Casa Viva y/o sube una foto del móvil, escribe lo que quiere y pulsa una idea. El
 *   «ayudante» (CVD_Estudio_Prompt) arma la instrucción con las reglas de foto de producto; si hay texto, la
 *   Gemini de ella lo pasa a inglés (modelo de texto barato) y, si falla, va en español.
 * - No se inventan colores: solo los de las variaciones/atributo «Color» del producto.
 * - La foto se guarda en su galería (uploads/cvd-estudio/<carpeta suya>, con límites) y puede ponerla como foto de
 *   ese producto en SU tienda: solo se ve entrando por su enlace ?ref= (la tienda general no cambia).
 *
 * Acceso: opción `cvd_estudio_acceso` = 'all' para todas; por defecto 'prueba' = cuentas de prueba y las gestoras
 * de la opción `cvd_estudio_usuarios` (IDs separados por comas). Lo mismo vale para «Mi contabilidad».
 */
final class CVD_Gestora_Estudio {
	private const NS = 'casa-viva/v1';
	private const META_KEY = '_cvd_estudio_key';
	private const META_KEY_AT = '_cvd_estudio_key_at';
	private const META_GALLERY = '_cvd_estudio_gallery';
	private const META_STORE = '_cvd_estudio_tienda';
	private const META_OWNER = '_cvd_estudio_owner';
	private const DIR = 'cvd-estudio';
	private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const DEFAULT_MODEL = 'gemini-nano-banana-2.1';
	private const FALLBACK_MODEL = 'gemini-3.1-flash-lite-image';
	private const DEFAULT_TEXT_MODEL = 'gemini-flash-lite-latest';

	/** Mapa producto => adjunto de la gestora cuya tienda se mira en esta visita (null = aún sin calcular). */
	private static ?array $store_map = null;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
		add_filter( 'woocommerce_product_get_image_id', array( __CLASS__, 'store_image' ), 20, 2 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'hide_from_media' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_media_list' ) );
	}

	/* ------------------------------------------------------------------ acceso */

	public static function enabled_for( WP_User $user ): bool {
		if ( ! $user->exists() || ! class_exists( 'CVD_Registration' ) || 'gestora' !== CVD_Registration::program_type( $user ) || ! CVD_Registration::is_approved_gestora( $user ) ) { return false; }
		if ( 'all' === get_option( 'cvd_estudio_acceso', 'prueba' ) || '1' === (string) get_user_meta( $user->ID, '_cvd_test_account', true ) ) { return true; }
		$ids = array_filter( array_map( 'absint', explode( ',', (string) get_option( 'cvd_estudio_usuarios', '' ) ) ) );
		return in_array( $user->ID, $ids, true );
	}

	public static function can_use(): bool {
		return is_user_logged_in() && self::enabled_for( wp_get_current_user() );
	}

	public static function assets(): void {
		if ( ! is_page( 'area-gestoras' ) || ! self::can_use() ) { return; }
		wp_enqueue_style( 'cvd-gestora-estudio', CVD_URL . 'assets/gestora-estudio.css', array( 'cvd-gestora-app' ), CVD_VERSION );
		wp_enqueue_script( 'cvd-gestora-estudio', CVD_URL . 'assets/gestora-estudio.js', array( 'cvd-gestora-app' ), CVD_VERSION, true );
	}

	/* ------------------------------------------------------------------ clave */

	private static function vault_key(): string {
		$secret = defined( 'CVD_ESTUDIO_SECRET' ) && strlen( (string) CVD_ESTUDIO_SECRET ) >= 32 ? (string) CVD_ESTUDIO_SECRET : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		return CVD_Estudio_Vault::derive_key( $secret ?: wp_salt( 'auth' ) );
	}

	public static function has_key( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_KEY, true );
	}

	/** La clave en claro, solo dentro del servidor. null si no hay o no se puede abrir. */
	private static function key_for( int $user_id ): ?string {
		$stored = (string) get_user_meta( $user_id, self::META_KEY, true );
		return '' === $stored ? null : CVD_Estudio_Vault::decrypt( $stored, self::vault_key() );
	}

	/* ------------------------------------------------------------------ rutas */

	public static function routes(): void {
		$perm = array( __CLASS__, 'can_use' );
		register_rest_route( self::NS, '/gestora/estudio/clave', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_key' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/clave/quitar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_key_remove' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/producto', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_product' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/prompt', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_prompt' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/crear', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/borrar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_delete' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/gestora/estudio/tienda', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_store' ), 'permission_callback' => $perm ) );
	}

	/** Conectar: comprueba la clave con una llamada mínima (leer el modelo de fotos) y la guarda cifrada. */
	public static function rest_key( WP_REST_Request $request ) {
		$key = trim( (string) $request->get_param( 'key' ) );
		$key = (string) preg_replace( '/\s+/', '', $key );
		if ( ! CVD_Estudio_Vault::looks_like_key( $key ) ) {
			return new WP_Error( 'cvd_estudio_key', 'Eso no parece una clave de Google. Cópiala completa desde aistudio.google.com/apikey (es un texto largo, sin espacios).', array( 'status' => 400 ) );
		}
		$check = self::check_key( $key );
		if ( true !== $check ) { return new WP_Error( 'cvd_estudio_key', $check, array( 'status' => 400 ) ); }
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::META_KEY, CVD_Estudio_Vault::encrypt( $key, self::vault_key() ) );
		update_user_meta( $user_id, self::META_KEY_AT, time() );
		return rest_ensure_response( array( 'ok' => true, 'since' => self::date_label( time() ), 'message' => 'Listo: tu clave de Google está conectada. Ya puedes crear fotos.' ) );
	}

	public static function rest_key_remove() {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::META_KEY_AT );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Tu clave se borró de Casa Viva. Tus fotos siguen en tu galería.' ) );
	}

	/** true, o el motivo en español. */
	private static function check_key( string $key ) {
		$response = wp_remote_get( self::API . rawurlencode( self::model() ), array( 'timeout' => 20, 'headers' => array( 'x-goog-api-key' => $key ) ) );
		if ( is_wp_error( $response ) ) { return CVD_Estudio_Errors::human( 0, '', '', 'clave' ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) { return true; }
		$err = self::error_of( (string) wp_remote_retrieve_body( $response ) );
		if ( 404 === $code ) { // el modelo de fotos no existe para esa clave: probar con la lista (la clave puede estar bien)
			$list = wp_remote_get( self::API . '?pageSize=1', array( 'timeout' => 20, 'headers' => array( 'x-goog-api-key' => $key ) ) );
			if ( ! is_wp_error( $list ) && 200 === (int) wp_remote_retrieve_response_code( $list ) ) { return true; }
		}
		self::log( 'clave', $code, $err['status'], $err['message'], $key );
		return CVD_Estudio_Errors::human( $code, $err['status'], $err['message'], 'clave' );
	}

	/** Colores reales e imagen de un producto, para los botones «En otro color». */
	public static function rest_product( WP_REST_Request $request ) {
		$product = self::product( absint( $request->get_param( 'id' ) ) );
		if ( ! $product ) { return new WP_Error( 'cvd_estudio_product', 'Ese producto no está disponible.', array( 'status' => 404 ) ); }
		$colors = array();
		foreach ( self::product_colors( $product ) as $label => $info ) {
			$colors[] = array( 'label' => (string) $label, 'img' => $info['img'] ? (string) wp_get_attachment_image_url( $info['img'], 'thumbnail' ) : '' );
		}
		return rest_ensure_response( array(
			'id' => $product->get_id(), 'name' => $product->get_name(), 'colors' => $colors,
			'img' => (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'medium' ),
		) );
	}

	/** Enseña la instrucción que se mandará (para la parte plegada «Ver la instrucción»). */
	public static function rest_prompt( WP_REST_Request $request ) {
		$plan = self::plan( $request, true );
		if ( is_wp_error( $plan ) ) { return $plan; }
		return rest_ensure_response( array( 'prompt' => $plan['prompt'], 'aspect' => $plan['aspect'], 'color' => $plan['color'] ) );
	}

	/**
	 * Arma todo lo que hace falta para una foto: producto, color comprobado, fotos de referencia e instrucción.
	 *
	 * @return array|WP_Error
	 */
	private static function plan( WP_REST_Request $request, bool $rewrite ) {
		$user_id = get_current_user_id();
		$product = self::product( absint( $request->get_param( 'product_id' ) ) );
		$text = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'text' ) ), 0, CVD_Estudio_Limits::MAX_TEXT );
		$preset = sanitize_key( (string) $request->get_param( 'preset' ) );
		$vertical = in_array( (string) $request->get_param( 'vertical' ), array( '1', 'true', 'on' ), true );
		$chosen = sanitize_text_field( (string) $request->get_param( 'color' ) );
		$has_photo = ! empty( $request->get_file_params()['photo']['tmp_name'] ) || '1' === (string) $request->get_param( 'has_photo' );
		if ( ! $product && ! $has_photo ) {
			return new WP_Error( 'cvd_estudio_input', 'Elige un producto de Casa Viva o sube una foto del móvil.', array( 'status' => 400 ) );
		}
		$colors = $product ? self::product_colors( $product ) : array();
		$check = CVD_Estudio_Prompt::check_color( $chosen, $text, array_keys( $colors ), (bool) $product );
		if ( 'color' === $preset && '' === $chosen && '' === $check['color'] && '' === $check['error'] ) {
			$check['error'] = $colors ? 'Toca uno de los colores que tenemos para este producto.' : 'Este producto solo lo tenemos en el color de su foto.';
		}
		if ( '' !== $check['error'] ) { return new WP_Error( 'cvd_estudio_color', $check['error'], array( 'status' => 400 ) ); }
		$color = $check['color'];
		$ref_id = $product ? (int) $product->get_image_id() : 0;
		$color_from_ref = false;
		if ( '' !== $color && ! empty( $colors[ $color ]['img'] ) ) { $ref_id = (int) $colors[ $color ]['img']; $color_from_ref = true; }

		$text_en = '';
		$custom = trim( (string) $request->get_param( 'prompt' ) );
		if ( '' === $custom && $rewrite && '' !== $text ) {
			$key = self::key_for( $user_id );
			if ( $key ) { $text_en = self::rewrite( $key, $text ); }
		}
		$desc = $product && class_exists( 'CVD_Gestora_Fotos' ) ? CVD_Gestora_Fotos::selling_line( $product ) : '';
		$built = CVD_Estudio_Prompt::build( array(
			'name' => $product ? $product->get_name() : '', 'desc' => $desc, 'text' => $text, 'text_en' => $text_en,
			'preset' => $preset, 'vertical' => $vertical, 'color' => $color, 'color_from_ref' => $color_from_ref,
			'has_product_ref' => $ref_id > 0, 'has_user_photo' => $has_photo,
		) );
		if ( '' !== $custom ) {
			$built['prompt'] = CVD_Estudio_Prompt::with_rules( sanitize_textarea_field( $custom ), $ref_id > 0 || $has_photo, '' !== $color ? CVD_Estudio_Prompt::color_english( $color ) : '', $color_from_ref );
		}
		return array( 'product' => $product, 'text' => $text, 'color' => $color, 'ref_id' => $ref_id, 'prompt' => $built['prompt'], 'aspect' => $built['aspect'] );
	}

	/** Crear la foto con la clave de ella y guardarla en su galería. */
	public static function rest_create( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$key = self::key_for( $user_id );
		if ( ! $key ) {
			return new WP_Error( 'cvd_estudio_nokey', self::has_key( $user_id ) ? 'No pude abrir tu clave guardada. Vuelve a conectarla en «Conectar mi clave de Google».' : 'Primero conecta tu clave de Google.', array( 'status' => 400 ) );
		}
		$gallery = self::gallery( $user_id );
		$full = CVD_Estudio_Limits::gallery_check( count( $gallery ), (int) array_sum( array_column( $gallery, 'bytes' ) ) );
		if ( $full ) { return new WP_Error( 'cvd_estudio_full', $full, array( 'status' => 400 ) ); }
		$day_key = 'cvd_estudio_dia_' . $user_id . '_' . wp_date( 'Ymd' );
		$day = CVD_Estudio_Limits::day_check( (int) get_transient( $day_key ) );
		if ( $day ) { return new WP_Error( 'cvd_estudio_day', $day, array( 'status' => 429 ) ); }
		$lock = 'cvd_estudio_lock_' . $user_id;
		if ( get_transient( $lock ) ) { return new WP_Error( 'cvd_estudio_busy', 'Ya estoy creando una foto. Espera a que termine.', array( 'status' => 409 ) ); }

		$photo = null;
		$file = $request->get_file_params()['photo'] ?? null;
		if ( is_array( $file ) && ! empty( $file['tmp_name'] ) ) {
			if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) || ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) $file['size'] > CVD_Estudio_Limits::MAX_UPLOAD_BYTES ) {
				return new WP_Error( 'cvd_estudio_photo', 'La foto debe ser JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 400 ) );
			}
			$info = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return new WP_Error( 'cvd_estudio_photo', 'La foto debe ser JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 400 ) );
			}
			$photo = self::jpeg_bytes( (string) $file['tmp_name'], 1280 );
		}
		$plan = self::plan( $request, true );
		if ( is_wp_error( $plan ) ) { return $plan; }

		$parts = array();
		if ( $plan['ref_id'] && class_exists( 'CVD_Gestora_Fotos' ) ) {
			$path = CVD_Gestora_Fotos::jpg_path( $plan['ref_id'] );
			if ( $path ) { $parts[] = array( 'inline_data' => array( 'mime_type' => 'image/jpeg', 'data' => base64_encode( (string) file_get_contents( $path ) ) ) ); } // phpcs:ignore
		}
		if ( $photo ) { $parts[] = array( 'inline_data' => array( 'mime_type' => 'image/jpeg', 'data' => base64_encode( $photo ) ) ); } // phpcs:ignore
		$parts[] = array( 'text' => $plan['prompt'] );

		set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );
		set_transient( $day_key, (int) get_transient( $day_key ) + 1, DAY_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 170 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = self::generate( $key, $parts, $plan['aspect'] );
		delete_transient( $lock );
		if ( is_wp_error( $result ) ) { return $result; }

		$item = self::save_to_gallery( $user_id, $result['bytes'], $plan['product'] ? $plan['product']->get_id() : 0, $plan['text'], $plan['color'] );
		if ( is_wp_error( $item ) ) { return $item; }
		return rest_ensure_response( array( 'photo' => self::item_for_js( $item, $user_id ), 'left' => max( 0, CVD_Estudio_Limits::MAX_PER_DAY - (int) get_transient( $day_key ) ) ) );
	}

	public static function rest_delete( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		$gallery = self::gallery( $user_id );
		$keep = array();
		$found = null;
		foreach ( $gallery as $g ) { if ( $g['id'] === $id ) { $found = $g; } else { $keep[] = $g; } }
		if ( ! $found ) { return new WP_Error( 'cvd_estudio_missing', 'Esa foto ya no está.', array( 'status' => 404 ) ); }
		self::unset_store_photo( $user_id, (int) $found['product'], $id );
		list( $dir ) = self::dir( $user_id );
		$path = $dir . '/' . basename( (string) $found['file'] );
		if ( file_exists( $path ) ) { wp_delete_file( $path ); }
		update_user_meta( $user_id, self::META_GALLERY, $keep );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Poner (o quitar) la foto como foto del producto en SU tienda. */
	public static function rest_store( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		$remove = '1' === (string) $request->get_param( 'quitar' );
		$item = null;
		foreach ( self::gallery( $user_id ) as $g ) { if ( $g['id'] === $id ) { $item = $g; break; } }
		if ( ! $item ) { return new WP_Error( 'cvd_estudio_missing', 'Esa foto ya no está.', array( 'status' => 404 ) ); }
		$product = self::product( (int) $item['product'] );
		if ( ! $product ) { return new WP_Error( 'cvd_estudio_product', 'Esta foto no es de un producto de Casa Viva: puedes descargarla y compartirla, pero no ponerla en la tienda.', array( 'status' => 400 ) ); }
		if ( $remove ) {
			self::unset_store_photo( $user_id, $product->get_id(), '' );
			return rest_ensure_response( array( 'ok' => true, 'in_store' => false, 'message' => 'Quitada. En tu tienda vuelve a salir la foto de Casa Viva.' ) );
		}
		list( $dir, $url_base ) = self::dir( $user_id );
		$source = $dir . '/' . basename( (string) $item['file'] );
		if ( ! file_exists( $source ) ) { return new WP_Error( 'cvd_estudio_missing', 'Esa foto ya no está.', array( 'status' => 404 ) ); }
		$target = $dir . '/tienda-' . $product->get_id() . '-' . strtolower( wp_generate_password( 8, false ) ) . '.jpg';
		if ( ! copy( $source, $target ) ) { return new WP_Error( 'cvd_estudio_store', 'No pude poner la foto. Prueba otra vez.', array( 'status' => 500 ) ); }
		$att = wp_insert_attachment( array(
			'post_mime_type' => 'image/jpeg', 'post_title' => $product->get_name() . ' (tienda de ' . wp_get_current_user()->display_name . ')',
			'post_status' => 'inherit', 'post_content' => '', 'guid' => $url_base . '/' . basename( $target ),
		), $target, 0, true );
		if ( is_wp_error( $att ) ) { wp_delete_file( $target ); return new WP_Error( 'cvd_estudio_store', 'No pude poner la foto. Prueba otra vez.', array( 'status' => 500 ) ); }
		update_post_meta( $att, self::META_OWNER, $user_id );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $target ) );
		self::unset_store_photo( $user_id, $product->get_id(), '' ); // la anterior de ese producto, si había
		$map = self::store_map_of( $user_id );
		$map[ $product->get_id() ] = array( 'att' => (int) $att, 'photo' => $id );
		update_user_meta( $user_id, self::META_STORE, $map );
		do_action( 'litespeed_purge_post', $product->get_id() );
		return rest_ensure_response( array( 'ok' => true, 'in_store' => true, 'message' => 'Listo: en tu tienda este producto sale con tu foto. La tienda general no cambia.', 'link' => self::store_link( $product->get_id(), $user_id ) ) );
	}

	/** Producto => ['att' => adjunto, 'photo' => id de su galería]. */
	private static function store_map_of( int $user_id ): array {
		$map = get_user_meta( $user_id, self::META_STORE, true );
		return is_array( $map ) ? array_filter( $map, 'is_array' ) : array();
	}

	private static function unset_store_photo( int $user_id, int $product_id, string $only_if_photo ): void {
		$map = self::store_map_of( $user_id );
		if ( ! $product_id || empty( $map[ $product_id ] ) ) { return; }
		if ( '' !== $only_if_photo && ( $map[ $product_id ]['photo'] ?? '' ) !== $only_if_photo ) { return; }
		$att = (int) ( $map[ $product_id ]['att'] ?? 0 );
		if ( $att && (int) get_post_meta( $att, self::META_OWNER, true ) === $user_id ) { wp_delete_attachment( $att, true ); }
		unset( $map[ $product_id ] );
		update_user_meta( $user_id, self::META_STORE, $map );
	}

	/* ------------------------------------------------------------------ tienda de la gestora */

	/** En SU tienda (enlace ?ref=), la foto principal del producto es la suya. Fuera de su tienda, nada cambia. */
	public static function store_image( $image_id, $product ) {
		if ( ! $product instanceof WC_Product || ( is_admin() && ! wp_doing_ajax() ) ) { return $image_id; }
		if ( null === self::$store_map ) {
			self::$store_map = array();
			$gestora = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::active_gestora() : null;
			if ( $gestora ) {
				foreach ( self::store_map_of( $gestora->ID ) as $pid => $row ) {
					if ( ! empty( $row['att'] ) ) { self::$store_map[ (int) $pid ] = (int) $row['att']; }
				}
			}
		}
		return self::$store_map[ $product->get_id() ] ?? $image_id;
	}

	/** Las fotos de las tiendas de gestoras no llenan la Biblioteca de medios de Casa Viva. */
	public static function hide_from_media( array $args ): array {
		$args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? array() ), array( array( 'key' => self::META_OWNER, 'compare' => 'NOT EXISTS' ) ) );
		return $args;
	}

	public static function hide_from_media_list( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) { return; }
		global $pagenow;
		if ( 'upload.php' !== $pagenow ) { return; }
		$query->set( 'meta_query', array_merge( (array) $query->get( 'meta_query' ), array( array( 'key' => self::META_OWNER, 'compare' => 'NOT EXISTS' ) ) ) );
	}

	/* ------------------------------------------------------------------ Gemini */

	private static function model(): string {
		return (string) get_option( 'cvd_estudio_model', self::DEFAULT_MODEL ) ?: self::DEFAULT_MODEL;
	}

	/**
	 * Llama a Gemini (generateContent) y devuelve ['bytes' => imagen] o un WP_Error en español.
	 * Si el modelo no existe, prueba el de reserva; si Google no acepta imageConfig, repite sin él.
	 */
	public static function generate( string $key, array $parts, string $aspect ) {
		$models = array_values( array_unique( array( self::model(), self::FALLBACK_MODEL ) ) );
		$with_config = true;
		$last = null;
		foreach ( $models as $model ) {
			for ( $try = 0; $try < 2; $try++ ) {
				$config = array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) );
				if ( $with_config ) { $config['imageConfig'] = array( 'aspectRatio' => $aspect ); }
				$response = wp_remote_post( self::API . rawurlencode( $model ) . ':generateContent', array(
					'timeout' => 150,
					'headers' => array( 'x-goog-api-key' => $key, 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'contents' => array( array( 'role' => 'user', 'parts' => $parts ) ), 'generationConfig' => $config ) ),
				) );
				if ( is_wp_error( $response ) ) {
					self::log( 'foto', 0, '', $response->get_error_message(), $key );
					return new WP_Error( 'cvd_estudio_google', CVD_Estudio_Errors::human( 0, '', '' ), array( 'status' => 502 ) );
				}
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );
				if ( 200 === $code ) {
					$data = json_decode( $body, true );
					$image = self::image_of( is_array( $data ) ? $data : array() );
					if ( $image ) { return array( 'bytes' => $image, 'model' => $model ); }
					$finish = (string) ( $data['candidates'][0]['finishReason'] ?? '' );
					$block = (string) ( $data['promptFeedback']['blockReason'] ?? '' );
					self::log( 'foto-sin-imagen', 200, $finish, $block, $key );
					return new WP_Error( 'cvd_estudio_noimage', CVD_Estudio_Errors::no_image( $finish, $block ), array( 'status' => 422 ) );
				}
				$err = self::error_of( $body );
				$last = array( $code, $err );
				self::log( 'foto', $code, $err['status'], $err['message'], $key );
				if ( 400 === $code && $with_config && 'INVALID_ARGUMENT' === $err['status'] && false !== stripos( $err['message'], 'config' ) ) { $with_config = false; continue; }
				break;
			}
			if ( $last && 404 === $last[0] ) { continue; } // modelo inexistente: el de reserva
			break;
		}
		return new WP_Error( 'cvd_estudio_google', CVD_Estudio_Errors::human( (int) $last[0], (string) $last[1]['status'], (string) $last[1]['message'] ), array( 'status' => 429 === $last[0] ? 429 : 502 ) );
	}

	/** Bytes de la primera imagen de la respuesta (inlineData o inline_data). */
	public static function image_of( array $data ): string {
		foreach ( (array) ( $data['candidates'] ?? array() ) as $cand ) {
			foreach ( (array) ( $cand['content']['parts'] ?? array() ) as $part ) {
				$blob = $part['inlineData'] ?? ( $part['inline_data'] ?? null );
				if ( is_array( $blob ) && ! empty( $blob['data'] ) && 0 === strpos( (string) ( $blob['mimeType'] ?? ( $blob['mime_type'] ?? 'image/' ) ), 'image/' ) ) {
					$bytes = base64_decode( (string) $blob['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $bytes && strlen( $bytes ) > 1000 ) { return $bytes; }
				}
			}
		}
		return '';
	}

	/** Pasa a inglés lo que pidió (una frase de escena) con el modelo de texto barato de su clave. '' si falla. */
	private static function rewrite( string $key, string $text ): string {
		$model = (string) get_option( 'cvd_estudio_text_model', self::DEFAULT_TEXT_MODEL ) ?: self::DEFAULT_TEXT_MODEL;
		$response = wp_remote_post( self::API . rawurlencode( $model ) . ':generateContent', array(
			'timeout' => 15,
			'headers' => array( 'x-goog-api-key' => $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => CVD_Estudio_Prompt::rewrite_instruction() ) ) ),
				'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $text ) ) ) ),
				'generationConfig' => array( 'temperature' => 0.2, 'maxOutputTokens' => 120 ),
			) ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return ''; }
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$out = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) { $out .= (string) ( $part['text'] ?? '' ); }
		$out = trim( str_replace( array( '"', "\n" ), array( '', ' ' ), wp_strip_all_tags( $out ) ) );
		return mb_strlen( $out ) >= 5 && mb_strlen( $out ) <= 400 ? $out : '';
	}

	private static function error_of( string $body ): array {
		$data = json_decode( $body, true );
		$err = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		return array( 'status' => (string) ( $err['status'] ?? '' ), 'message' => (string) ( $err['message'] ?? '' ) );
	}

	/** Registro sin la clave (y sin nada con forma de clave). */
	private static function log( string $what, int $code, string $status, string $message, string $key ): void {
		error_log( 'CVD_ESTUDIO ' . $what . ' u' . get_current_user_id() . ' ' . $code . ' ' . $status . ' ' . substr( CVD_Estudio_Vault::redact( $message, $key ), 0, 240 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/* ------------------------------------------------------------------ galería */

	/** Carpeta suya: uploads/cvd-estudio/u<ID>-<10 letras secretas>. Devuelve [ruta, url]. */
	private static function dir( int $user_id ): array {
		$up = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . self::DIR;
		$name = 'u' . $user_id . '-' . substr( hash_hmac( 'sha256', 'estudio-' . $user_id, wp_salt( 'auth' ) ), 0, 10 );
		$path = $base . '/' . $name;
		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
			foreach ( array( $base, $path ) as $d ) { if ( ! file_exists( $d . '/index.php' ) ) { file_put_contents( $d . '/index.php', "<?php\n// Silencio.\n" ); } } // phpcs:ignore
		}
		return array( $path, trailingslashit( $up['baseurl'] ) . self::DIR . '/' . $name );
	}

	public static function gallery( int $user_id ): array {
		$list = get_user_meta( $user_id, self::META_GALLERY, true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/** Imagen → JPEG de como mucho $max px (bytes). */
	private static function jpeg_bytes( string $path, int $max ): string {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) { return (string) file_get_contents( $path ); } // phpcs:ignore
		$editor->resize( $max, $max, false );
		$editor->set_quality( 85 );
		$tmp = wp_tempnam( 'cvd-estudio' ) . '.jpg';
		$saved = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) { return (string) file_get_contents( $path ); } // phpcs:ignore
		$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore
		wp_delete_file( $saved['path'] );
		return $bytes;
	}

	/** Guarda la foto de Google en su galería como JPEG (máx. 1600 px). */
	private static function save_to_gallery( int $user_id, string $bytes, int $product_id, string $text, string $color ) {
		list( $dir ) = self::dir( $user_id );
		$tmp = wp_tempnam( 'cvd-estudio-raw' );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore
		$id = strtolower( wp_generate_password( 10, false ) );
		$file = wp_date( 'Ymd-His' ) . '-' . $id . '.jpg';
		$target = $dir . '/' . $file;
		$editor = wp_get_image_editor( $tmp );
		$ok = false;
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1600, 1600, false );
			$editor->set_quality( 86 );
			$saved = $editor->save( $target, 'image/jpeg' );
			$ok = ! is_wp_error( $saved );
		}
		wp_delete_file( $tmp );
		if ( ! $ok || ! file_exists( $target ) ) { return new WP_Error( 'cvd_estudio_save', 'Google creó la foto pero no pude guardarla. Prueba otra vez.', array( 'status' => 500 ) ); }
		$size = @getimagesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$item = array( 'id' => $id, 'file' => $file, 'w' => (int) ( $size[0] ?? 0 ), 'h' => (int) ( $size[1] ?? 0 ), 'bytes' => (int) filesize( $target ), 'product' => $product_id, 'text' => mb_substr( $text, 0, 160 ), 'color' => $color, 'at' => time() );
		$gallery = self::gallery( $user_id );
		array_unshift( $gallery, $item );
		update_user_meta( $user_id, self::META_GALLERY, $gallery );
		return $item;
	}

	private static function item_for_js( array $item, int $user_id ): array {
		list( , $url ) = self::dir( $user_id );
		$map = self::store_map_of( $user_id );
		$pid = (int) $item['product'];
		$product = $pid ? wc_get_product( $pid ) : null;
		return array(
			'id' => $item['id'], 'url' => $url . '/' . rawurlencode( (string) $item['file'] ), 'w' => (int) $item['w'], 'h' => (int) $item['h'],
			'product' => $product ? $product->get_name() : '', 'pid' => $product ? $pid : 0, 'text' => (string) $item['text'], 'color' => (string) ( $item['color'] ?? '' ),
			'date' => self::date_label( (int) $item['at'] ), 'inStore' => $pid && ( $map[ $pid ]['photo'] ?? '' ) === $item['id'],
			'storeLink' => $product ? self::store_link( $pid, $user_id ) : '',
			'name' => 'casa-viva-' . ( $product ? sanitize_title( $product->get_name() ) : 'foto' ) . '-' . $item['id'] . '.jpg',
		);
	}

	/* ------------------------------------------------------------------ productos */

	private static function product( int $id ): ?WC_Product {
		if ( ! $id ) { return null; }
		$product = wc_get_product( $id );
		if ( ! $product || $product->is_type( 'variation' ) || 'publish' !== $product->get_status() ) { return null; }
		return $product;
	}

	/**
	 * Colores que existen de verdad: atributo con «color» en el nombre (de taxonomía o propio). En productos
	 * variables, solo los que tienen una variación, con su foto si la variación tiene una propia.
	 *
	 * @return array<string,array{img:int}>
	 */
	public static function product_colors( WC_Product $product ): array {
		$out = array();
		$keys = array();
		foreach ( $product->get_attributes() as $attr_key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) { continue; }
			$label = wc_attribute_label( $attr->get_name(), $product );
			if ( false === strpos( CVD_Estudio_Prompt::normalize( $label . ' ' . $attr->get_name() ), 'color' ) ) { continue; }
			$keys[] = sanitize_title( $attr_key );
			if ( $attr->is_taxonomy() ) {
				foreach ( wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'all' ) ) as $term ) { $out[ $term->name ] = array( 'img' => 0, 'slug' => $term->slug ); }
			} else {
				foreach ( $attr->get_options() as $opt ) { $out[ self::pretty( (string) $opt ) ] = array( 'img' => 0, 'slug' => (string) $opt ); }
			}
		}
		if ( $out && $product->is_type( 'variable' ) ) {
			$seen = array();
			$parent_img = (int) $product->get_image_id();
			foreach ( $product->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v || 'publish' !== $v->get_status() ) { continue; }
				foreach ( $v->get_attributes() as $k => $value ) {
					if ( ! in_array( sanitize_title( $k ), $keys, true ) || '' === (string) $value ) { continue; }
					foreach ( $out as $label => $info ) {
						if ( (string) $value === (string) $info['slug'] || sanitize_title( (string) $value ) === sanitize_title( (string) $label ) ) {
							$seen[ $label ] = true;
							$img = (int) $v->get_image_id();
							if ( $img && $img !== $parent_img && ! $out[ $label ]['img'] ) { $out[ $label ]['img'] = $img; }
						}
					}
				}
			}
			if ( $seen ) { $out = array_intersect_key( $out, $seen ); }
		}
		return $out;
	}

	/** «rosado-claro» → «Rosado claro». */
	private static function pretty( string $label ): string {
		$label = trim( str_replace( array( '-', '_' ), ' ', $label ) );
		return mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );
	}

	private static function store_link( int $product_id, int $user_id ): string {
		$code = (string) get_user_meta( $user_id, '_cvd_referral_code', true );
		return $code ? add_query_arg( 'ref', $code, (string) get_permalink( $product_id ) ) : (string) get_permalink( $product_id );
	}

	private static function date_label( int $ts ): string {
		$meses = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
		return wp_date( 'j', $ts ) . ' ' . $meses[ (int) wp_date( 'n', $ts ) - 1 ] . ' · ' . wp_date( 'H:i', $ts );
	}

	/* ------------------------------------------------------------------ pantalla */

	public static function render( WP_User $user ): string {
		$has_key = self::has_key( $user->ID );
		$since = (int) get_user_meta( $user->ID, self::META_KEY_AT, true );
		$gallery = array_map( static fn( array $g ): array => self::item_for_js( $g, $user->ID ), self::gallery( $user->ID ) );
		$day_key = 'cvd_estudio_dia_' . $user->ID . '_' . wp_date( 'Ymd' );
		$left = max( 0, CVD_Estudio_Limits::MAX_PER_DAY - (int) get_transient( $day_key ) );
		$config = array(
			'base' => esc_url_raw( rest_url( self::NS . '/gestora/estudio/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			'hasKey' => $has_key, 'gallery' => $gallery, 'left' => $left, 'maxPhotos' => CVD_Estudio_Limits::MAX_PHOTOS,
		);
		ob_start();
		?>
		<section class="cvga-view cves" data-view="estudio" id="cvga-estudio" data-cves>
			<div class="cvga-head"><h1>Mi estudio de fotos</h1><p>Elige un producto, di cómo quieres la foto y Google la crea con tu clave. Quedan en tu galería para descargar, compartir o poner en tu tienda.</p></div>

			<section class="cvga-card cves-key<?php echo $has_key ? ' is-on' : ''; ?>" data-cves-key>
				<div class="cves-key-on"<?php echo $has_key ? '' : ' hidden'; ?>>
					<div class="cvga-row"><h2>Tu clave de Google</h2><span class="cvga-chip is-good" data-cves-since><?php echo esc_html( $since ? 'Conectada · ' . self::date_label( $since ) : 'Conectada' ); ?></span></div>
					<p class="cvga-note">Cada foto la paga Google con tu clave (unos 3 centavos de USD por foto). Tu clave está guardada cifrada y no se enseña a nadie.</p>
					<div class="cvga-actions"><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cves-key-remove>Quitar mi clave</button></div>
				</div>
				<form class="cves-key-off" data-cves-key-form autocomplete="off"<?php echo $has_key ? ' hidden' : ''; ?>>
					<h2>Conectar mi clave de Google</h2>
					<ol class="cves-steps">
						<li>Abre <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> con tu cuenta de Gmail (desde Cuba, con VPN).</li>
						<li>Toca <b>Create API key</b> y copia la clave (un texto largo de letras y números).</li>
						<li>Pégala aquí y toca <b>Comprobar y guardar</b>.</li>
					</ol>
					<label class="cvga-field" for="cves-key-input"><span>Tu clave de Google</span><input id="cves-key-input" name="key" type="password" inputmode="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Pega aquí tu clave" required></label>
					<div class="cvga-actions"><button class="cvga-btn" type="submit">Comprobar y guardar</button></div>
					<p class="cvga-note">La guardamos cifrada en Casa Viva, nunca se enseña y la puedes quitar cuando quieras. Google cobra unos 3 centavos de USD por foto a tu cuenta.</p>
				</form>
				<p class="cves-msg" data-cves-key-msg role="status" hidden></p>
			</section>

			<form class="cvga-card cves-make" data-cves-form autocomplete="off"<?php echo $has_key ? '' : ' hidden'; ?>>
				<h2>1. ¿De qué producto?</h2>
				<div class="cves-pick" data-cves-pick hidden><img alt="" width="56" height="56"><div><b data-cves-pick-name></b><small class="cvga-note" data-cves-pick-colors></small></div><button type="button" class="cvga-btn cvga-btn-soft cvga-btn-sm" data-cves-pick-clear>Cambiar</button></div>
				<label class="cvgf-search cves-search" for="cves-q" data-cves-search><svg class="cvga-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg><input id="cves-q" type="search" placeholder="Busca en tu tienda: organizador, cortina…" enterkeyhint="search" data-cves-q aria-label="Buscar producto"></label>
				<div class="cves-hits" data-cves-hits></div>
				<label class="cves-upload" for="cves-photo"><span>o sube una foto del móvil <small>(opcional)</small></span><input id="cves-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></label>
				<img class="cves-preview" alt="" data-cves-preview hidden>

				<h2>2. ¿Cómo la quieres?</h2>
				<div class="cves-ideas" role="group" aria-label="Ideas">
					<?php foreach ( CVD_Estudio_Prompt::PRESETS as $slug => $label ) : ?>
					<button type="button" class="cves-idea" data-cves-idea="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="cves-colors" data-cves-colors hidden></div>
				<label class="cvga-field" for="cves-text"><span>Dilo con tus palabras <small>(opcional)</small></span><textarea id="cves-text" name="text" rows="2" maxlength="<?php echo esc_attr( (string) CVD_Estudio_Limits::MAX_TEXT ); ?>" placeholder="Ej.: el organizador en un baño bonito, con luz de mañana"></textarea></label>
				<details class="cves-prompt" data-cves-prompt>
					<summary>Ver la instrucción para Google <small>(si quieres tocarla)</small></summary>
					<textarea rows="8" data-cves-prompt-text aria-label="Instrucción para Google"></textarea>
					<small class="cvga-note">Las reglas del final (producto fiel, sin textos ni marcas) se ponen siempre, aunque las borres.</small>
				</details>
				<div class="cvga-actions"><button class="cvga-btn" type="submit" data-cves-go>Crear foto</button><small class="cvga-note" data-cves-left><?php echo esc_html( 'Te quedan ' . $left . ' fotos hoy.' ); ?></small></div>
				<p class="cves-msg" data-cves-msg role="status" hidden></p>
				<div class="cves-result" data-cves-result></div>
			</form>

			<section class="cves-gallery-wrap"<?php echo $gallery || $has_key ? '' : ' hidden'; ?> data-cves-gallery-wrap>
				<div class="cvga-row"><h2>Mi galería</h2><span class="cvga-chip" data-cves-count><?php echo esc_html( count( $gallery ) . ' de ' . CVD_Estudio_Limits::MAX_PHOTOS ); ?></span></div>
				<p class="cvga-note">«Poner en mi tienda» cambia la foto de ese producto solo para quien entra con tu enlace. La tienda de Casa Viva no cambia.</p>
				<div class="cves-gallery" data-cves-gallery></div>
				<p class="cvga-empty" data-cves-empty<?php echo $gallery ? ' hidden' : ''; ?>>Aún no has creado fotos.</p>
			</section>
			<script type="application/json" data-cves-config><?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
