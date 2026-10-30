<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.87 (Ernesto, 29-oct): «la tienda propia de Daykel». Un vendedor (mensajero + gestora con la marca de vendedor) pone,
 * edita, pausa y borra SUS artículos desde el móvil; el cliente los ve en casaviva.company/vendedor/<nombre>/ y los pide
 * por WhatsApp (decisión de Ernesto: sin carrito). Diseño: docs/nexo-business/tienda-de-vendedor-daykel-diseno-2026-10-29.md.
 *
 * Los artículos NO son productos de WooCommerce: son un tipo de contenido propio (cvd_seller_item), así que no tocan el
 * catálogo, la Caja, el bot, los mínimos ni los anuncios. Las ventas se apuntan a mano (cvd_seller_sale): es una libreta,
 * NO mueve dinero ni pagos. La comisión de Casa Viva queda «pendiente» (la habla Ernesto con el vendedor; no se inventa).
 * «También en Casa Viva en general» está bloqueado mientras la opción cvd_seller_general_open no sea «si».
 * Cada ruta comprueba que el artículo es de quien llama.
 */
final class CVD_Seller_Store {
	public const ITEM = 'cvd_seller_item';
	public const SALE = 'cvd_seller_sale';
	private const NS = 'casa-viva/v1';
	private const MAX_ITEMS = 200;
	private const MAX_PHOTOS = 5;
	private const MAX_UPLOAD_BYTES = 12582912; // 12 MB
	public const CATS = array( 'Motor y transmisión', 'Frenos', 'Eléctrico y luces', 'Suspensión y dirección', 'Llantas y ruedas', 'Filtros, aceite y lubricantes', 'Carrocería y accesorios', 'Herramientas', 'Otros' );
	public const DELIVERY = array( 'yo' => 'Yo lo entrego', 'mensajero' => 'Mensajero de Casa Viva', 'tienda' => 'Se recoge en la tienda' );
	public const PAY = array( 'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'coordinar' => 'Lo coordinamos por WhatsApp' );

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'types' ) );
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static function ( array $vars ): array { $vars[] = 'cvd_vendedor'; return $vars; } );
		add_action( 'template_redirect', array( __CLASS__, 'public_page' ), 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_cvd_seller_review', array( __CLASS__, 'admin_review' ) );
	}

	/* ------------------------------------------------------------------ tipos y rutas bonitas */

	public static function types(): void {
		register_post_type( self::ITEM, array( 'label' => 'Artículos de vendedores', 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title', 'editor' ), 'rewrite' => false, 'query_var' => false ) );
		register_post_type( self::SALE, array( 'label' => 'Ventas de vendedores', 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title' ), 'rewrite' => false, 'query_var' => false ) );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^vendedor/([^/]+)/?$', 'index.php?cvd_vendedor=$matches[1]', 'top' );
		if ( get_option( 'cvd_seller_rw_v' ) !== '1' ) { flush_rewrite_rules( false ); update_option( 'cvd_seller_rw_v', '1', false ); }
	}

	/* ------------------------------------------------------------------ cuenta */

	/** ¿Es una cuenta de vendedor (gestora aprobada con la marca)? */
	public static function is_seller( WP_User $user ): bool {
		return $user->exists() && 'yes' === (string) get_user_meta( $user->ID, '_cvd_seller_enabled', true ) && class_exists( 'CVD_Registration' ) && CVD_Registration::is_approved_gestora( $user );
	}

	public static function can_sell(): bool {
		return is_user_logged_in() && self::is_seller( wp_get_current_user() );
	}

	public static function general_open(): bool {
		return 'si' === get_option( 'cvd_seller_general_open', 'no' );
	}

	/**
	 * Deja a un mensajero aprobado como cuenta doble (mensajero + gestora) con la marca de vendedor, igual que el registro
	 * («Mensajero que añade venta»). Idempotente. Guarda una copia de lo que cambió en _cvd_seller_backup. Devuelve el
	 * resumen o WP_Error. Probado antes con una cuenta de prueba; no toca Pa'llá ni el área de mensajeros.
	 */
	public static function enable_account( int $user_id, string $name = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return new WP_Error( 'cvd_seller', 'Esa cuenta no existe.' ); }
		if ( in_array( 'administrator', (array) $user->roles, true ) ) { return new WP_Error( 'cvd_seller', 'No se usa con cuentas de administración.' ); }
		$roles = (array) $user->roles;
		$was_messenger = in_array( 'cvd_messenger', $roles, true );
		$was_gestora = in_array( 'cvd_gestora', $roles, true );
		if ( ! $was_messenger && ! $was_gestora ) { return new WP_Error( 'cvd_seller', 'La cuenta no es de mensajero ni de gestora.' ); }
		if ( ! get_user_meta( $user_id, '_cvd_seller_backup', true ) ) {
			update_user_meta( $user_id, '_cvd_seller_backup', wp_json_encode( array(
				'at' => gmdate( 'c' ), 'roles' => $roles,
				'account_status' => (string) get_user_meta( $user_id, '_cvd_account_status', true ),
				'messenger_status' => (string) get_user_meta( $user_id, '_cvd_messenger_status', true ),
				'program_type' => (string) get_user_meta( $user_id, '_cvd_program_type', true ),
				'referral' => (string) get_user_meta( $user_id, '_cvd_referral_code', true ),
			) ) );
		}
		if ( $was_messenger && ! $was_gestora ) {
			// Su estado de mensajero pasa a su propia clave; la gestora nace aprobada (decisión de Ernesto, 29-oct).
			$current = (string) get_user_meta( $user_id, '_cvd_account_status', true ) ?: 'approved';
			update_user_meta( $user_id, '_cvd_messenger_status', $current );
			update_user_meta( $user_id, '_cvd_account_status', 'approved' );
			$user->add_role( 'cvd_gestora' );
		} elseif ( $was_gestora && ! $was_messenger ) {
			// Gestora sin mensajería: solo la marca de vendedor.
			update_user_meta( $user_id, '_cvd_account_status', 'approved' );
		}
		update_user_meta( $user_id, '_cvd_program_type', 'gestora' );
		if ( '' === (string) get_user_meta( $user_id, '_cvd_referral_code', true ) ) {
			update_user_meta( $user_id, '_cvd_referral_code', self::unique_code( $user_id, $user->user_login ) );
		}
		$user = get_userdata( $user_id );
		$user->add_role( 'cvd_supplier' );
		update_user_meta( $user_id, '_cvd_seller_enabled', 'yes' );
		$display = '' !== trim( $name ) ? sanitize_text_field( $name ) : (string) $user->display_name;
		if ( '' === (string) get_user_meta( $user_id, '_cvd_seller_name', true ) ) { update_user_meta( $user_id, '_cvd_seller_name', $display ); }
		if ( '' === (string) get_user_meta( $user_id, '_cvd_seller_slug', true ) ) { update_user_meta( $user_id, '_cvd_seller_slug', self::unique_slug( $display, $user_id ) ); }
		$user = get_userdata( $user_id );
		return array(
			'user' => $user_id, 'roles' => (array) $user->roles, 'dual' => CVD_Registration::is_dual( $user ),
			'gestora' => CVD_Registration::status_for( $user, 'gestora' ), 'mensajero' => CVD_Registration::status_for( $user, 'mensajero' ),
			'ref' => (string) get_user_meta( $user_id, '_cvd_referral_code', true ), 'slug' => (string) get_user_meta( $user_id, '_cvd_seller_slug', true ),
			'seller' => self::is_seller( $user ),
		);
	}

	private static function unique_code( int $user_id, string $login ): string {
		$stem = 'CV' . $user_id . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $login ), 0, 8 ) );
		$code = $stem;
		$n = 1;
		while ( get_users( array( 'number' => 1, 'meta_key' => '_cvd_referral_code', 'meta_value' => $code, 'fields' => 'ids' ) ) ) { $code = $stem . $n++; }
		return $code;
	}

	private static function unique_slug( string $name, int $user_id ): string {
		$base = sanitize_title( remove_accents( $name ) ) ?: 'vendedor' . $user_id;
		$slug = $base;
		$n = 2;
		while ( true ) {
			$ids = get_users( array( 'number' => 1, 'meta_key' => '_cvd_seller_slug', 'meta_value' => $slug, 'fields' => 'ids', 'exclude' => array( $user_id ) ) );
			if ( ! $ids && ! in_array( $slug, array( 'admin', 'api', 'tienda', 'casa-viva' ), true ) ) { return $slug; }
			$slug = $base . '-' . $n++;
		}
	}

	private static function seller_by_slug( string $slug ): ?WP_User {
		$ids = get_users( array( 'number' => 1, 'meta_key' => '_cvd_seller_slug', 'meta_value' => sanitize_title( $slug ), 'fields' => 'ids' ) );
		$user = $ids ? get_userdata( (int) $ids[0] ) : null;
		return $user && self::is_seller( $user ) ? $user : null;
	}

	public static function store_url( WP_User $user ): string {
		return home_url( '/vendedor/' . get_user_meta( $user->ID, '_cvd_seller_slug', true ) . '/' );
	}

	public static function store_name( WP_User $user ): string {
		return (string) ( get_user_meta( $user->ID, '_cvd_seller_name', true ) ?: $user->display_name );
	}

	/** WhatsApp de los clientes (el que puso el vendedor o el de su cuenta), solo cifras, con 53 si son 8. */
	public static function client_phone( WP_User $user ): string {
		$phone = preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_seller_whatsapp', true ) ?: get_user_meta( $user->ID, '_cvd_whatsapp', true ) ) );
		return 8 === strlen( $phone ) ? '53' . $phone : $phone;
	}

	/* ------------------------------------------------------------------ artículos */

	private static function items_of( int $user_id, array $status = array( 'publish', 'draft' ) ): array {
		return get_posts( array( 'post_type' => self::ITEM, 'post_status' => $status, 'author' => $user_id, 'numberposts' => self::MAX_ITEMS, 'orderby' => 'date', 'order' => 'DESC' ) );
	}

	private static function photo_url( int $id, string $size = 'medium' ): string {
		$url = $id ? wp_get_attachment_image_url( $id, $size ) : false;
		return $url ? (string) $url : '';
	}

	/** El artículo como lo ve el vendedor y la app (nada interno). */
	public static function item_data( WP_Post $post ): array {
		$ids = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_cvd_si_photos', true ) ) ) );
		$stock = get_post_meta( $post->ID, '_cvd_si_stock', true );
		return array(
			'id' => $post->ID, 'title' => $post->post_title, 'description' => $post->post_content,
			'price' => (float) get_post_meta( $post->ID, '_cvd_si_price', true ),
			'stock' => '' === (string) $stock ? null : (int) $stock,
			'category' => (string) get_post_meta( $post->ID, '_cvd_si_cat', true ),
			'photos' => array_map( static fn( int $id ): array => array( 'id' => $id, 'url' => self::photo_url( $id, 'medium' ) ), $ids ),
			'paused' => 'draft' === $post->post_status,
			'adminHidden' => '1' === (string) get_post_meta( $post->ID, '_cvd_si_admin_hidden', true ),
			'wantGeneral' => '1' === (string) get_post_meta( $post->ID, '_cvd_si_want_general', true ),
			'offer' => '1' === (string) get_post_meta( $post->ID, '_cvd_si_offer', true ),
			'gestoraUsd' => (float) get_post_meta( $post->ID, '_cvd_si_gestora_usd', true ),
			'delivery' => (string) ( get_post_meta( $post->ID, '_cvd_si_delivery', true ) ?: 'yo' ),
			'pay' => array_values( array_intersect( array_keys( self::PAY ), (array) get_post_meta( $post->ID, '_cvd_si_pay', true ) ) ),
			'consultas' => (int) get_post_meta( $post->ID, '_cvd_si_consultas', true ),
			'reviewed' => (string) get_post_meta( $post->ID, '_cvd_si_reviewed', true ),
		);
	}

	/** Valida y guarda un artículo. $id = 0 crea. Devuelve el WP_Post o WP_Error. */
	private static function save_item( int $user_id, int $id, array $p ) {
		$title = trim( sanitize_text_field( (string) ( $p['title'] ?? '' ) ) );
		if ( '' === $title || mb_strlen( $title ) > 90 ) { return new WP_Error( 'cvd_seller', 'Escribe el nombre del artículo (máximo 90 letras).', array( 'status' => 422 ) ); }
		$price = round( (float) str_replace( ',', '.', (string) ( $p['price'] ?? 0 ) ), 2 );
		if ( $price < 0.5 || $price > 5000 ) { return new WP_Error( 'cvd_seller', 'Pon el precio en USD (entre 0,50 y 5000).', array( 'status' => 422 ) ); }
		$description = mb_substr( sanitize_textarea_field( (string) ( $p['description'] ?? '' ) ), 0, 1500 );
		$stock_in = $p['stock'] ?? '';
		$stock = ( '' === $stock_in || null === $stock_in ) ? '' : max( 0, min( 9999, (int) $stock_in ) );
		$cat = mb_substr( sanitize_text_field( (string) ( $p['category'] ?? '' ) ), 0, 40 );
		$offer = ! empty( $p['offer'] );
		$gest = round( (float) str_replace( ',', '.', (string) ( $p['gestoraUsd'] ?? 0 ) ), 2 );
		if ( $offer && ( $gest <= 0 || $gest > $price * 0.5 ) ) { return new WP_Error( 'cvd_seller', 'Para ofrecerlo a las gestoras, pon cuánto gana cada una (más de 0 y menos de la mitad del precio).', array( 'status' => 422 ) ); }
		$delivery = sanitize_key( (string) ( $p['delivery'] ?? 'yo' ) );
		if ( ! isset( self::DELIVERY[ $delivery ] ) ) { $delivery = 'yo'; }
		$pay = array_values( array_intersect( array_keys( self::PAY ), array_map( 'sanitize_key', (array) ( $p['pay'] ?? array() ) ) ) );
		if ( ! $pay ) { $pay = array( 'coordinar' ); }
		$photos = array();
		foreach ( array_slice( array_map( 'absint', (array) ( $p['photos'] ?? array() ) ), 0, self::MAX_PHOTOS ) as $att ) {
			if ( $att && (int) get_post_meta( $att, '_cvd_seller_photo_of', true ) === $user_id ) { $photos[] = $att; }
		}
		$status = ! empty( $p['paused'] ) ? 'draft' : 'publish';
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || self::ITEM !== $post->post_type || (int) $post->post_author !== $user_id || 'trash' === $post->post_status ) { return new WP_Error( 'cvd_seller', 'Ese artículo no es tuyo o ya no existe.', array( 'status' => 404 ) ); }
			wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $description, 'post_status' => $status ) );
		} else {
			if ( count( self::items_of( $user_id, array( 'publish', 'draft' ) ) ) >= self::MAX_ITEMS ) { return new WP_Error( 'cvd_seller', 'Llegaste al máximo de ' . self::MAX_ITEMS . ' artículos.', array( 'status' => 422 ) ); }
			$id = (int) wp_insert_post( array( 'post_type' => self::ITEM, 'post_status' => $status, 'post_title' => $title, 'post_content' => $description, 'post_author' => $user_id ), true );
			if ( ! $id ) { return new WP_Error( 'cvd_seller', 'No se pudo guardar. Prueba otra vez.', array( 'status' => 500 ) ); }
		}
		update_post_meta( $id, '_cvd_si_price', wc_format_decimal( $price, 2 ) );
		update_post_meta( $id, '_cvd_si_stock', $stock );
		update_post_meta( $id, '_cvd_si_cat', $cat );
		update_post_meta( $id, '_cvd_si_photos', $photos );
		update_post_meta( $id, '_cvd_si_offer', $offer ? '1' : '0' );
		update_post_meta( $id, '_cvd_si_gestora_usd', $offer ? wc_format_decimal( $gest, 2 ) : '0' );
		update_post_meta( $id, '_cvd_si_delivery', $delivery );
		update_post_meta( $id, '_cvd_si_pay', $pay );
		// «También en Casa Viva en general»: se guarda lo que quiere, pero no sale mientras esté bloqueado.
		update_post_meta( $id, '_cvd_si_want_general', ! empty( $p['wantGeneral'] ) ? '1' : '0' );
		update_post_meta( $id, '_cvd_si_commission_casa_viva', 'pendiente' );
		if ( '' === (string) get_post_meta( $id, '_cvd_si_reviewed', true ) ) { update_post_meta( $id, '_cvd_si_reviewed', 'nuevo' ); }
		update_post_meta( $id, '_cvd_si_updated', time() );
		return get_post( $id );
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes(): void {
		$sell = array( __CLASS__, 'can_sell' );
		$r = static function ( string $path, string $method, string $cb, $perm ): void {
			register_rest_route( self::NS, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/vendedor/articulos', 'GET', 'rest_items', $sell );
		$r( '/vendedor/articulos', 'POST', 'rest_save', $sell );
		$r( '/vendedor/articulos/(?P<id>\d+)/pausa', 'POST', 'rest_pause', $sell );
		$r( '/vendedor/articulos/(?P<id>\d+)/borrar', 'POST', 'rest_delete', $sell );
		$r( '/vendedor/foto', 'POST', 'rest_photo', $sell );
		$r( '/vendedor/tienda', 'POST', 'rest_settings', $sell );
		$r( '/vendedor/ventas', 'GET', 'rest_sales', $sell );
		$r( '/vendedor/ventas', 'POST', 'rest_sale_add', $sell );
		$r( '/vendedor/ventas/(?P<id>\d+)/anular', 'POST', 'rest_sale_cancel', $sell );
		$r( '/vendedor/consulta', 'POST', 'rest_consulta', '__return_true' );
		$panel = array( 'CVD_Panel_Bridge', 'authorized' );
		$r( '/panel/vendedores/articulos', 'GET', 'rest_panel_items', $panel );
		$r( '/panel/vendedores/articulos/(?P<id>\d+)/revisar', 'POST', 'rest_panel_review', $panel );
	}

	private static function ok( array $data ): WP_REST_Response {
		$res = new WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function own_item( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post || self::ITEM !== $post->post_type || (int) $post->post_author !== get_current_user_id() || 'trash' === $post->post_status ) { return new WP_Error( 'cvd_seller', 'Ese artículo no es tuyo o ya no existe.', array( 'status' => 404 ) ); }
		return $post;
	}

	public static function rest_items(): WP_REST_Response {
		return self::ok( array( 'items' => array_map( array( __CLASS__, 'item_data' ), self::items_of( get_current_user_id() ) ) ) );
	}

	public static function rest_save( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$post = self::save_item( get_current_user_id(), absint( $params['id'] ?? 0 ), $params );
		return is_wp_error( $post ) ? $post : self::ok( array( 'item' => self::item_data( $post ) ) );
	}

	public static function rest_pause( WP_REST_Request $request ) {
		$post = self::own_item( $request );
		if ( is_wp_error( $post ) ) { return $post; }
		wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' === $post->post_status ? 'publish' : 'draft' ) );
		return self::ok( array( 'item' => self::item_data( get_post( $post->ID ) ) ) );
	}

	public static function rest_delete( WP_REST_Request $request ) {
		$post = self::own_item( $request );
		if ( is_wp_error( $post ) ) { return $post; }
		wp_trash_post( $post->ID ); // a la papelera: un administrador lo puede recuperar
		return self::ok( array( 'deleted' => $post->ID ) );
	}

	/** Foto: JPG/PNG/WebP de hasta 12 MB, se reduce a 1600 px; queda marcada como suya. */
	public static function rest_photo( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$file = $request->get_file_params()['photo'] ?? null;
		$bad = new WP_Error( 'cvd_seller', 'La foto debe ser JPG, PNG o WebP de hasta 12 MB.', array( 'status' => 400 ) );
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) || ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) ( $file['size'] ?? 0 ) > self::MAX_UPLOAD_BYTES ) { return $bad; }
		$info = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return $bad; }
		$day_key = 'cvd_seller_fotos_' . $user_id . '_' . wp_date( 'Ymd' );
		if ( (int) get_transient( $day_key ) >= 80 ) { return new WP_Error( 'cvd_seller', 'Hoy ya subiste muchas fotos. Sigue mañana.', array( 'status' => 429 ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$moved = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['file'] ) ) { return $bad; }
		$editor = wp_get_image_editor( $moved['file'] );
		if ( ! is_wp_error( $editor ) ) {
			$size = $editor->get_size();
			if ( max( (int) $size['width'], (int) $size['height'] ) > 1600 ) { $editor->resize( 1600, 1600, false ); }
			$editor->set_quality( 82 );
			$editor->save( $moved['file'] );
		}
		$att = wp_insert_attachment( array( 'post_mime_type' => $moved['type'], 'post_title' => 'Foto de vendedor ' . $user_id, 'post_status' => 'inherit', 'post_author' => $user_id ), $moved['file'] );
		if ( is_wp_error( $att ) || ! $att ) { return $bad; }
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $moved['file'] ) );
		update_post_meta( $att, '_cvd_seller_photo_of', $user_id );
		set_transient( $day_key, (int) get_transient( $day_key ) + 1, DAY_IN_SECONDS );
		return self::ok( array( 'photo' => array( 'id' => (int) $att, 'url' => self::photo_url( (int) $att, 'medium' ) ) ) );
	}

	/** Nombre de la tienda y WhatsApp para clientes. */
	public static function rest_settings( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$p = (array) $request->get_json_params();
		$name = trim( sanitize_text_field( (string) ( $p['name'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $p['whatsapp'] ?? '' ) );
		if ( '' === $name || mb_strlen( $name ) > 40 ) { return new WP_Error( 'cvd_seller', 'Escribe el nombre de tu tienda (máximo 40 letras).', array( 'status' => 422 ) ); }
		if ( '' !== $phone && ! preg_match( '/^(53[56]\d{7}|[56]\d{7}|\d{10,15})$/', $phone ) ) { return new WP_Error( 'cvd_seller', 'Ese WhatsApp no parece válido. Pon tus 8 cifras (5XXXXXXX).', array( 'status' => 422 ) ); }
		update_user_meta( $user_id, '_cvd_seller_name', $name );
		update_user_meta( $user_id, '_cvd_seller_whatsapp', $phone );
		return self::ok( array( 'name' => $name, 'whatsapp' => $phone ) );
	}

	/** Alguien tocó «Pedir por WhatsApp»: se cuenta como consulta (una por persona, artículo y 10 minutos). */
	public static function rest_consulta( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::ITEM !== $post->post_type || 'publish' !== $post->post_status ) { return self::ok( array( 'ok' => false ) ); }
		$key = 'cvd_si_c_' . md5( $id . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( get_transient( $key ) ) { return self::ok( array( 'ok' => true, 'dup' => true ) ); }
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		update_post_meta( $id, '_cvd_si_consultas', (int) get_post_meta( $id, '_cvd_si_consultas', true ) + 1 );
		$ref = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		if ( '' !== $ref && strlen( $ref ) <= 40 ) {
			$list = array_slice( (array) get_user_meta( (int) $post->post_author, '_cvd_seller_recent_refs', true ), -19 );
			$list[] = array( 'ref' => strtoupper( $ref ), 'item' => $id, 'at' => time() );
			update_user_meta( (int) $post->post_author, '_cvd_seller_recent_refs', $list );
		}
		return self::ok( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ ventas apuntadas a mano (no mueve dinero) */

	private static function sale_data( WP_Post $s ): array {
		$g = absint( get_post_meta( $s->ID, '_cvd_ss_gestora', true ) );
		$gu = $g ? get_userdata( $g ) : null;
		return array(
			'id' => $s->ID, 'date' => get_post_time( 'Y-m-d', false, $s ), 'item' => absint( get_post_meta( $s->ID, '_cvd_ss_item', true ) ),
			'title' => $s->post_title, 'qty' => (int) get_post_meta( $s->ID, '_cvd_ss_qty', true ), 'total' => (float) get_post_meta( $s->ID, '_cvd_ss_total', true ),
			'buyer' => (string) get_post_meta( $s->ID, '_cvd_ss_buyer', true ), 'gestora' => $gu ? $gu->display_name : '', 'gestoraCode' => (string) get_post_meta( $s->ID, '_cvd_ss_ref', true ),
			'gestoraUsd' => (float) get_post_meta( $s->ID, '_cvd_ss_gestora_usd', true ), 'canceled' => 'canceled' === (string) get_post_meta( $s->ID, '_cvd_ss_state', true ),
			'casaViva' => 'pendiente',
		);
	}

	private static function sales_of( int $user_id, int $limit = 100 ): array {
		return get_posts( array( 'post_type' => self::SALE, 'post_status' => 'publish', 'author' => $user_id, 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC' ) );
	}

	public static function sales_summary( int $user_id ): array {
		$month = wp_date( 'Y-m' );
		$total = 0.0;
		$gest = 0.0;
		$n = 0;
		foreach ( self::sales_of( $user_id, 500 ) as $s ) {
			$d = self::sale_data( $s );
			if ( $d['canceled'] || 0 !== strpos( (string) $d['date'], $month ) ) { continue; }
			$n++;
			$total += $d['total'];
			$gest += $d['gestoraUsd'];
		}
		return array( 'month' => $month, 'count' => $n, 'totalUsd' => round( $total, 2 ), 'gestoraUsd' => round( $gest, 2 ), 'casaViva' => 'pendiente' );
	}

	public static function rest_sales(): WP_REST_Response {
		$uid = get_current_user_id();
		return self::ok( array( 'sales' => array_map( array( __CLASS__, 'sale_data' ), self::sales_of( $uid ) ), 'summary' => self::sales_summary( $uid ), 'refs' => self::recent_refs( $uid ) ) );
	}

	private static function recent_refs( int $user_id ): array {
		$out = array();
		foreach ( array_reverse( (array) get_user_meta( $user_id, '_cvd_seller_recent_refs', true ) ) as $r ) {
			$code = (string) ( $r['ref'] ?? '' );
			if ( '' === $code || isset( $out[ $code ] ) ) { continue; }
			$ids = get_users( array( 'number' => 1, 'fields' => 'ids', 'meta_key' => '_cvd_referral_code', 'meta_value' => $code ) );
			$u = $ids ? get_userdata( (int) $ids[0] ) : null;
			if ( $u ) { $out[ $code ] = array( 'code' => $code, 'name' => $u->display_name ); }
		}
		return array_values( $out );
	}

	public static function rest_sale_add( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$p = (array) $request->get_json_params();
		$item = get_post( absint( $p['item'] ?? 0 ) );
		if ( ! $item || self::ITEM !== $item->post_type || (int) $item->post_author !== $uid || 'trash' === $item->post_status ) { return new WP_Error( 'cvd_seller', 'Elige uno de tus artículos.', array( 'status' => 422 ) ); }
		$qty = max( 1, min( 999, (int) ( $p['qty'] ?? 1 ) ) );
		$unit = round( (float) str_replace( ',', '.', (string) ( $p['unit'] ?? get_post_meta( $item->ID, '_cvd_si_price', true ) ) ), 2 );
		if ( $unit <= 0 || $unit > 5000 ) { return new WP_Error( 'cvd_seller', 'Pon el precio al que lo vendiste (USD).', array( 'status' => 422 ) ); }
		$stock = get_post_meta( $item->ID, '_cvd_si_stock', true );
		if ( '' !== (string) $stock && (int) $stock < $qty ) { return new WP_Error( 'cvd_seller', 'Te quedan ' . (int) $stock . ' de este artículo.', array( 'status' => 422 ) ); }
		$code = strtoupper( trim( sanitize_text_field( (string) ( $p['gestoraCode'] ?? '' ) ) ) );
		$g = 0;
		$g_usd = 0.0;
		if ( '' !== $code ) {
			$ids = get_users( array( 'number' => 1, 'fields' => 'ids', 'meta_key' => '_cvd_referral_code', 'meta_value' => $code ) );
			$gu = $ids ? get_userdata( (int) $ids[0] ) : null;
			if ( ! $gu || (int) $gu->ID === $uid || ! CVD_Registration::is_approved_gestora( $gu ) ) { return new WP_Error( 'cvd_seller', 'Ese código de gestora no existe. Déjalo vacío si la venta es tuya.', array( 'status' => 422 ) ); }
			$g = (int) $gu->ID;
			if ( '1' === (string) get_post_meta( $item->ID, '_cvd_si_offer', true ) ) { $g_usd = round( (float) get_post_meta( $item->ID, '_cvd_si_gestora_usd', true ) * $qty, 2 ); }
		}
		$id = (int) wp_insert_post( array( 'post_type' => self::SALE, 'post_status' => 'publish', 'post_title' => $item->post_title, 'post_author' => $uid ), true );
		if ( ! $id ) { return new WP_Error( 'cvd_seller', 'No se pudo apuntar. Prueba otra vez.', array( 'status' => 500 ) ); }
		foreach ( array( '_cvd_ss_item' => $item->ID, '_cvd_ss_qty' => $qty, '_cvd_ss_total' => round( $unit * $qty, 2 ), '_cvd_ss_buyer' => mb_substr( sanitize_text_field( (string) ( $p['buyer'] ?? '' ) ), 0, 80 ), '_cvd_ss_gestora' => $g, '_cvd_ss_ref' => $g ? $code : '', '_cvd_ss_gestora_usd' => $g_usd, '_cvd_ss_state' => 'ok' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
		if ( '' !== (string) $stock ) { update_post_meta( $item->ID, '_cvd_si_stock', max( 0, (int) $stock - $qty ) ); }
		return self::ok( array( 'sale' => self::sale_data( get_post( $id ) ), 'item' => self::item_data( get_post( $item->ID ) ), 'summary' => self::sales_summary( $uid ) ) );
	}

	public static function rest_sale_cancel( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$s = get_post( absint( $request['id'] ) );
		if ( ! $s || self::SALE !== $s->post_type || (int) $s->post_author !== $uid ) { return new WP_Error( 'cvd_seller', 'Esa venta no es tuya.', array( 'status' => 404 ) ); }
		if ( 'canceled' !== (string) get_post_meta( $s->ID, '_cvd_ss_state', true ) ) {
			update_post_meta( $s->ID, '_cvd_ss_state', 'canceled' );
			$item = get_post( absint( get_post_meta( $s->ID, '_cvd_ss_item', true ) ) );
			$stock = $item ? get_post_meta( $item->ID, '_cvd_si_stock', true ) : '';
			if ( $item && '' !== (string) $stock ) { update_post_meta( $item->ID, '_cvd_si_stock', (int) $stock + (int) get_post_meta( $s->ID, '_cvd_ss_qty', true ) ); }
		}
		return self::ok( array( 'sale' => self::sale_data( get_post( $s->ID ) ), 'summary' => self::sales_summary( $uid ) ) );
	}

	/* ------------------------------------------------------------------ revisión de Lennys (después de publicar) */

	private static function visible_to_public( WP_Post $post ): bool {
		return 'publish' === $post->post_status && '1' !== (string) get_post_meta( $post->ID, '_cvd_si_admin_hidden', true );
	}

	private static function review_rows( bool $only_new ): array {
		$q = array( 'post_type' => self::ITEM, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 200, 'orderby' => 'date', 'order' => 'DESC' );
		if ( $only_new ) { $q['meta_query'] = array( array( 'key' => '_cvd_si_reviewed', 'value' => 'nuevo' ) ); }
		$rows = array();
		foreach ( get_posts( $q ) as $post ) {
			$u = get_userdata( (int) $post->post_author );
			$d = self::item_data( $post );
			$rows[] = array( 'id' => $post->ID, 'seller' => $u ? self::store_name( $u ) : '', 'title' => $d['title'], 'price' => $d['price'], 'stock' => $d['stock'], 'photo' => $d['photos'][0]['url'] ?? '', 'paused' => $d['paused'], 'hidden' => $d['adminHidden'], 'reviewed' => $d['reviewed'], 'offer' => $d['offer'], 'gestoraUsd' => $d['gestoraUsd'], 'url' => $u ? self::store_url( $u ) . '#a' . $post->ID : '' );
		}
		return $rows;
	}

	private static function review_apply( int $id, string $action ) {
		$post = get_post( $id );
		if ( ! $post || self::ITEM !== $post->post_type ) { return new WP_Error( 'cvd_seller', 'Ese artículo no existe.', array( 'status' => 404 ) ); }
		if ( 'ocultar' === $action ) { update_post_meta( $id, '_cvd_si_admin_hidden', '1' ); update_post_meta( $id, '_cvd_si_reviewed', 'ocultado' ); }
		elseif ( 'mostrar' === $action ) { delete_post_meta( $id, '_cvd_si_admin_hidden' ); update_post_meta( $id, '_cvd_si_reviewed', 'ok' ); }
		else { update_post_meta( $id, '_cvd_si_reviewed', 'ok' ); }
		return true;
	}

	public static function rest_panel_items( WP_REST_Request $request ): WP_REST_Response {
		return self::ok( array( 'items' => self::review_rows( 'todos' !== (string) $request->get_param( 'ver' ) ) ) );
	}

	public static function rest_panel_review( WP_REST_Request $request ) {
		$action = sanitize_key( (string) ( ( (array) $request->get_json_params() )['accion'] ?? 'ok' ) );
		$done = self::review_apply( absint( $request['id'] ), $action );
		return is_wp_error( $done ) ? $done : self::ok( array( 'ok' => true ) );
	}

	public static function admin_menu(): void {
		add_submenu_page( 'woocommerce', 'Artículos de vendedores', 'Artículos de vendedores', 'manage_woocommerce', 'cvd-seller-items', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Sin permiso.' ); }
		$all = isset( $_GET['ver'] ) && 'todos' === $_GET['ver'];
		echo '<div class="wrap"><h1>Artículos de vendedores</h1><p>Revisión después de publicar: ✓ lo deja como bueno, «Ocultar» lo quita de la tienda del vendedor y de las gestoras. <a href="' . esc_url( admin_url( 'admin.php?page=cvd-seller-items' . ( $all ? '' : '&ver=todos' ) ) ) . '">' . ( $all ? 'Ver solo los nuevos' : 'Ver todos' ) . '</a></p>';
		echo '<p>«También en Casa Viva en general»: ' . ( self::general_open() ? 'ABIERTO' : 'bloqueado hasta fijar la comisión de Casa Viva (opción <code>cvd_seller_general_open</code> = si).' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Foto</th><th>Vendedor</th><th>Artículo</th><th>Precio</th><th>Stock</th><th>Estado</th><th></th></tr></thead><tbody>';
		$rows = self::review_rows( ! $all );
		if ( ! $rows ) { echo '<tr><td colspan="7">Nada nuevo por revisar.</td></tr>'; }
		foreach ( $rows as $r ) {
			$state = $r['hidden'] ? 'Oculto por Casa Viva' : ( $r['paused'] ? 'Pausado' : ( 'nuevo' === $r['reviewed'] ? 'Nuevo' : 'Revisado' ) );
			echo '<tr><td>' . ( $r['photo'] ? '<img src="' . esc_url( $r['photo'] ) . '" width="56" height="56" style="object-fit:cover">' : '' ) . '</td><td>' . esc_html( $r['seller'] ) . '</td><td><a href="' . esc_url( $r['url'] ) . '" target="_blank">' . esc_html( $r['title'] ) . '</a>' . ( $r['offer'] ? ' <small>(gestoras ganan ' . esc_html( number_format( (float) $r['gestoraUsd'], 2 ) ) . ' USD)</small>' : '' ) . '</td><td>' . esc_html( number_format( (float) $r['price'], 2 ) ) . ' USD</td><td>' . esc_html( null === $r['stock'] ? '—' : (string) $r['stock'] ) . '</td><td>' . esc_html( $state ) . '</td><td>';
			foreach ( array( 'ok' => '✓', $r['hidden'] ? 'mostrar' : 'ocultar' => $r['hidden'] ? 'Mostrar' : 'Ocultar' ) as $act => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'cvd_seller_review', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="cvd_seller_review"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="accion" value="' . esc_attr( $act ) . '"><button class="button">' . esc_html( $label ) . '</button></form> ';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function admin_review(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Sin permiso.' ); }
		check_admin_referer( 'cvd_seller_review' );
		self::review_apply( absint( $_POST['id'] ?? 0 ), sanitize_key( (string) ( $_POST['accion'] ?? 'ok' ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=cvd-seller-items' ) );
		exit;
	}

	/* ------------------------------------------------------------------ gestoras: «Artículos de vendedores» */

	/** Artículos que un vendedor ofrece a las gestoras (publicados, no ocultos). */
	public static function offered_items(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::ITEM, 'post_status' => 'publish', 'numberposts' => 300, 'meta_key' => '_cvd_si_offer', 'meta_value' => '1', 'orderby' => 'date', 'order' => 'DESC' ) ) as $post ) {
			$u = get_userdata( (int) $post->post_author );
			if ( ! $u || ! self::is_seller( $u ) || ! self::visible_to_public( $post ) ) { continue; }
			$stock = get_post_meta( $post->ID, '_cvd_si_stock', true );
			if ( '' !== (string) $stock && (int) $stock <= 0 ) { continue; }
			$out[] = array( 'post' => $post, 'seller' => $u );
		}
		return $out;
	}

	/** Lo que los vendedores le deben apuntado a una gestora (solo información). */
	public static function gestora_owed( int $gestora_id ): array {
		$total = 0.0;
		$rows = array();
		foreach ( get_posts( array( 'post_type' => self::SALE, 'post_status' => 'publish', 'numberposts' => 200, 'meta_key' => '_cvd_ss_gestora', 'meta_value' => $gestora_id ) ) as $s ) {
			$d = self::sale_data( $s );
			if ( $d['canceled'] ) { continue; }
			$u = get_userdata( (int) $s->post_author );
			$total += $d['gestoraUsd'];
			$rows[] = $d + array( 'seller' => $u ? self::store_name( $u ) : '' );
		}
		return array( 'totalUsd' => round( $total, 2 ), 'rows' => $rows );
	}

	public static function has_offered(): bool {
		return (bool) self::offered_items();
	}

	public static function render_gestora_section( WP_User $user ): string {
		$items = self::offered_items();
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		$owed = self::gestora_owed( $user->ID );
		ob_start();
		?>
		<section class="cvga-view" data-view="articulos" id="cvga-articulos" hidden>
			<div class="cvga-head"><h1>Artículos de vendedores</h1><p>Piezas y artículos de otros vendedores que puedes ofrecer. El cliente los pide por WhatsApp al vendedor y tú ganas lo que dice cada uno.</p></div>
			<?php if ( $owed['rows'] ) : ?><section class="cvga-card"><h2>Lo que te deben</h2><p class="cvga-note">Apuntado por cada vendedor. El pago lo arreglas tú con él; Casa Viva no mueve este dinero.</p><p><b><?php echo esc_html( number_format( $owed['totalUsd'], 2 ) ); ?> USD</b> en <?php echo esc_html( (string) count( $owed['rows'] ) ); ?> ventas</p>
			<ul class="cvsl-list"><?php foreach ( array_slice( $owed['rows'], 0, 8 ) as $r ) : ?><li><?php echo esc_html( $r['date'] . ' · ' . $r['title'] . ' (' . $r['seller'] . ') — ' . number_format( $r['gestoraUsd'], 2 ) . ' USD' ); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
			<?php if ( ! $items ) : ?><section class="cvga-card"><p class="cvga-empty">Aún no hay artículos ofrecidos.</p></section><?php endif; ?>
			<div class="cvsl-grid">
			<?php foreach ( $items as $it ) : $post = $it['post']; $d = self::item_data( $post ); $url = add_query_arg( 'ref', rawurlencode( $code ), self::store_url( $it['seller'] ) ) . '#a' . $post->ID; $share = $d['title'] . ' — ' . number_format( $d['price'], 2 ) . ' USD. Míralo y pídelo aquí: ' . $url; ?>
				<article class="cvga-card cvsl-card">
					<?php if ( $d['photos'] ) : ?><img src="<?php echo esc_url( $d['photos'][0]['url'] ); ?>" alt="" loading="lazy"><?php endif; ?>
					<h2><?php echo esc_html( $d['title'] ); ?></h2>
					<p class="cvga-note"><?php echo esc_html( 'Vendedor: ' . self::store_name( $it['seller'] ) ); ?> · <b><?php echo esc_html( number_format( $d['price'], 2 ) ); ?> USD</b> · ganas <b><?php echo esc_html( number_format( $d['gestoraUsd'], 2 ) ); ?> USD</b> c/u</p>
					<div class="cvga-actions"><a class="cvga-btn cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( $share ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Ver</a></div>
				</article>
			<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ vista del vendedor «Mis artículos» */

	public static function assets(): void {
		if ( ! is_page( 'area-gestoras' ) || ! is_user_logged_in() ) { return; }
		$user = wp_get_current_user();
		if ( ! self::is_seller( $user ) && ! self::has_offered() ) { return; }
		wp_enqueue_style( 'cvd-vendedor', CVD_URL . 'assets/vendedor.css', array( 'cvd-gestora-app' ), CVD_VERSION );
		if ( self::is_seller( $user ) ) { wp_enqueue_script( 'cvd-vendedor', CVD_URL . 'assets/vendedor.js', array( 'cvd-gestora-app' ), CVD_VERSION, true ); }
	}

	public static function render_seller_view( WP_User $user ): string {
		if ( ! self::is_seller( $user ) ) { return ''; }
		$config = array(
			'base' => esc_url_raw( rest_url( self::NS ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			'items' => array_map( array( __CLASS__, 'item_data' ), self::items_of( $user->ID ) ),
			'sales' => array_map( array( __CLASS__, 'sale_data' ), self::sales_of( $user->ID ) ), 'summary' => self::sales_summary( $user->ID ), 'refs' => self::recent_refs( $user->ID ),
			'store' => array( 'url' => self::store_url( $user ), 'name' => self::store_name( $user ), 'whatsapp' => (string) get_user_meta( $user->ID, '_cvd_seller_whatsapp', true ), 'phone' => self::client_phone( $user ) ),
			'cats' => self::CATS, 'delivery' => self::DELIVERY, 'pay' => self::PAY, 'generalOpen' => self::general_open(),
		);
		ob_start();
		?>
		<section class="cvga-view" data-view="vendedor" id="cvga-vendedor" hidden>
			<div class="cvsl" data-cvsl>
				<div class="cvga-head"><h1>Mis artículos</h1><p>Tu tienda propia: pon tus piezas con foto y precio. El cliente te las pide por WhatsApp.</p></div>
				<section class="cvga-card" data-cvsl-store></section>
				<nav class="cvsl-tabs" aria-label="Mi tienda"><button type="button" class="is-on" data-cvsl-tab="items">Artículos</button><button type="button" data-cvsl-tab="sales">Ventas</button></nav>
				<div data-cvsl-pane="items"><div class="cvga-actions"><button class="cvga-btn" type="button" data-cvsl-new>+ Nuevo artículo</button></div><div data-cvsl-list></div></div>
				<div data-cvsl-pane="sales" hidden></div>
				<div class="cvsl-sheet" data-cvsl-sheet hidden role="dialog" aria-modal="true" aria-label="Artículo"><div class="cvsl-sheet-body" data-cvsl-form></div></div>
				<p class="cvsl-toast" data-cvsl-toast role="status" hidden></p>
			</div>
			<script type="application/json" data-cvsl-config><?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ la tienda pública /vendedor/<nombre>/ */

	public static function public_page(): void {
		$slug = (string) get_query_var( 'cvd_vendedor' );
		if ( '' === $slug ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva vendedor: lista que cambia' );
		$seller = self::seller_by_slug( $slug );
		if ( ! $seller ) { global $wp_query; $wp_query->set_404(); status_header( 404 ); nocache_headers(); return; }
		$ref = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) ) );
		if ( '' !== $ref && ( strlen( $ref ) > 40 || ! get_users( array( 'number' => 1, 'fields' => 'ids', 'meta_key' => '_cvd_referral_code', 'meta_value' => $ref ) ) ) ) { $ref = ''; }
		$name = self::store_name( $seller );
		$phone = self::client_phone( $seller );
		$posts = array_filter( self::items_of( $seller->ID, array( 'publish' ) ), array( __CLASS__, 'visible_to_public' ) );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		$consulta_url = esc_url_raw( rest_url( self::NS . '/vendedor/consulta' ) );
		?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php echo esc_html( $name ); ?> · Casa Viva</title>
<style>
:root{--c:#2b1f17;--bg:#faf5ec;--card:#fff;--acc:#b8651b;--soft:#efe5d3;--ok:#1f7a45}*{box-sizing:border-box}body{margin:0;font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--c)}header{padding:20px 16px 10px}h1{margin:0;font-size:1.5rem}.by{color:#6b5b4d;font-size:.9rem;margin-top:2px}.wrap{padding:8px 12px 40px;max-width:900px;margin:0 auto;display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}.card{background:var(--card);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.12)}.card img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:var(--soft)}.in{padding:12px 14px 14px}.t{font-weight:700;margin:0 0 4px}.p{font-size:1.25rem;font-weight:800;color:var(--acc)}.m{font-size:.85rem;color:#6b5b4d;margin:4px 0}.d{white-space:pre-line;font-size:.95rem;margin:8px 0}.btn{display:block;text-align:center;background:var(--ok);color:#fff;text-decoration:none;padding:13px;border-radius:12px;font-weight:700;margin-top:10px}.thumbs{display:flex;gap:6px;padding:6px 10px 0;overflow-x:auto}.thumbs img{width:56px;height:56px;border-radius:8px;aspect-ratio:auto}.empty{padding:40px 20px;text-align:center;color:#6b5b4d}.out{opacity:.6}.tag{display:inline-block;background:var(--soft);border-radius:99px;padding:2px 9px;font-size:.78rem;margin:2px 4px 0 0}footer{padding:16px;text-align:center;color:#6b5b4d;font-size:.85rem}
</style></head><body>
<header><h1><?php echo esc_html( $name ); ?></h1><div class="by">Vendedor: <?php echo esc_html( $name ); ?> · en Casa Viva</div></header>
<main class="wrap">
<?php if ( ! $posts ) : ?><p class="empty">Aquí pronto habrá artículos. Vuelve en un rato.</p><?php endif; ?>
<?php foreach ( $posts as $post ) :
	$d = self::item_data( $post );
	$sold = null !== $d['stock'] && $d['stock'] <= 0;
	$text = 'Hola ' . $name . ', me interesa: ' . $d['title'] . ' (' . number_format( $d['price'], 2 ) . ' USD), que vi en tu tienda de Casa Viva.' . ( '' !== $ref ? ' Código de gestora: ' . $ref . '.' : '' );
	?>
<article class="card<?php echo $sold ? ' out' : ''; ?>" id="a<?php echo (int) $post->ID; ?>">
	<?php if ( $d['photos'] ) : ?><img src="<?php echo esc_url( self::photo_url( (int) $d['photos'][0]['id'], 'large' ) ); ?>" alt="<?php echo esc_attr( $d['title'] ); ?>" loading="lazy"><?php if ( count( $d['photos'] ) > 1 ) : ?><div class="thumbs"><?php foreach ( array_slice( $d['photos'], 1 ) as $ph ) : ?><a href="<?php echo esc_url( self::photo_url( (int) $ph['id'], 'large' ) ); ?>"><img src="<?php echo esc_url( $ph['url'] ); ?>" alt="" loading="lazy"></a><?php endforeach; ?></div><?php endif; endif; ?>
	<div class="in">
		<p class="t"><?php echo esc_html( $d['title'] ); ?></p>
		<div class="p"><?php echo esc_html( number_format( $d['price'], 2 ) ); ?> USD</div>
		<div class="m"><?php echo $d['category'] ? '<span class="tag">' . esc_html( $d['category'] ) . '</span>' : ''; ?><span class="tag"><?php echo esc_html( $sold ? 'Agotado' : ( null === $d['stock'] ? 'Consulta disponibilidad' : 'Quedan ' . $d['stock'] ) ); ?></span><span class="tag"><?php echo esc_html( self::DELIVERY[ $d['delivery'] ] ?? '' ); ?></span></div>
		<?php if ( $d['description'] ) : ?><div class="d"><?php echo esc_html( $d['description'] ); ?></div><?php endif; ?>
		<div class="m">Pago: <?php echo esc_html( implode( ' · ', array_map( static fn( string $k ): string => self::PAY[ $k ], $d['pay'] ) ) ); ?></div>
		<?php if ( $phone && ! $sold ) : ?><a class="btn" data-id="<?php echo (int) $post->ID; ?>" href="<?php echo esc_url( 'https://wa.me/' . $phone . '?text=' . rawurlencode( $text ) ); ?>" target="_blank" rel="noopener">Pedir por WhatsApp</a><?php endif; ?>
	</div>
</article>
<?php endforeach; ?>
</main>
<footer>Vendedor independiente en Casa Viva. El pedido y el pago se acuerdan directamente con el vendedor por WhatsApp.</footer>
<script>document.addEventListener('click',function(e){var a=e.target.closest('a.btn[data-id]');if(!a)return;try{var f=new FormData();f.append('id',a.getAttribute('data-id'));f.append('ref',<?php echo wp_json_encode( $ref ); ?>);navigator.sendBeacon(<?php echo wp_json_encode( $consulta_url ); ?>,f)}catch(x){}});</script>
</body></html>
		<?php
		exit;
	}
}
