<?php
/** Mobile content desk. WooCommerce remains the product authority. */
defined( 'ABSPATH' ) || exit;

final class CVD_Content_Studio {
	public const CAP = 'cvd_edit_catalog_content';
	public const LOCK = '_cvd_content_owned';

	public static function register(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'Acceso al catálogo', 'Acceso al catálogo', 'manage_options', 'cvd-content-access', array( __CLASS__, 'access_page' ) );
		} );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'page' ), -10 );
		add_filter( 'woocommerce_account_menu_items', static function ( $items ) {
			if ( self::allowed() ) { $items['cvd-content'] = 'Mi catálogo y fotos'; }
			return $items;
		} );
		add_filter( 'woocommerce_get_endpoint_url', static function ( $url, $endpoint ) {
			return 'cvd-content' === $endpoint ? add_query_arg( 'cvd_contenido', '1', home_url( '/' ) ) : $url;
		}, 10, 2 );
	}

	public static function allowed(): bool {
		return is_user_logged_in() && ( current_user_can( self::CAP ) || current_user_can( 'manage_woocommerce' ) );
	}

	public static function routes(): void {
		// v2 (9-nov): crear (HERO/LIFESTYLE con el motor del VPS), pieza (3 estados), matriz, prioridad y la bandeja de dudas.
		foreach ( array( 'products' => 'GET', 'save' => 'POST', 'upload' => 'POST', 'review' => 'POST', 'resena' => 'POST', 'anuncio' => 'POST', 'suggest' => 'POST', 'password' => 'POST',
			'crear' => 'POST', 'pieza' => 'POST', 'matriz' => 'POST', 'prioridad' => 'POST', 'dudas' => 'GET' ) as $action => $method ) {
			register_rest_route( 'casa-viva/v1', '/contenido/' . $action, array(
				'methods' => $method, 'callback' => array( __CLASS__, $action ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			) );
		}
		register_rest_route( 'casa-viva/v1', '/contenido/dudas/(?P<duda>[A-Za-z0-9_-]{1,40})/respuesta', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'dudas_respuesta' ), 'permission_callback' => array( __CLASS__, 'allowed' ),
		) );
		// VivaBot tells which ad a WhatsApp message came from (code in the prefilled text of the ad link).
		register_rest_route( 'casa-viva/v1', '/bot/anuncio/mensaje', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'anuncio_mensaje' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	/** Orders count for an ad when the same phone orders within this many days after writing. */
	private const AD_WINDOW_DAYS = 14;
	private static function phone_key( string $phone ): string { return substr( preg_replace( '/\D/', '', $phone ), -8 ); }

	/** A WhatsApp link for the caption of one ad: prefilled text with its code (A + number) so the bot can count it. */
	public static function anuncio( WP_REST_Request $r ) {
		$p = wc_get_product( absint( $r->get_param( 'id' ) ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) { return new WP_Error( 'missing', 'Primero guarda el producto.', array( 'status' => 404 ) ); }
		$code = 'A' . ( (int) get_option( 'cvd_anuncio_seq', 0 ) + 1 );
		update_option( 'cvd_anuncio_seq', (int) substr( $code, 1 ), false );
		update_option( 'cvd_anuncio_' . $code, array( 'product' => $p->get_id(), 'layout' => sanitize_key( (string) $r->get_param( 'layout' ) ), 'format' => sanitize_key( (string) $r->get_param( 'format' ) ),
			'created' => time(), 'by' => get_current_user_id(), 'contacts' => array() ), false );
		$codes = array_filter( (array) $p->get_meta( '_cvd_anuncios' ) ); $codes[] = $code;
		update_post_meta( $p->get_id(), '_cvd_anuncios', array_values( $codes ) );
		// v2: a finished BENEFICIO card is the BENEFICIO piece of the product (approved by Zayri when she downloads it).
		if ( 'ben' === sanitize_key( (string) $r->get_param( 'layout' ) ) ) { self::poner_pieza( $p->get_id(), 'ben', array( 'estado' => 'aprobada', 'escalon' => 'anuncio', 'coste' => 0.0 ) ); }
		$name = mb_substr( $p->get_name(), 0, 40 );
		$base = class_exists( 'CVD_Aprende' ) ? CVD_Aprende::WHATSAPP : 'https://wa.me/5354056173';
		return rest_ensure_response( array( 'code' => $code, 'link' => $base . '?text=' . rawurlencode( "Hola Casa Viva, vi el anuncio de {$name} · {$code}" ), 'anuncios' => self::anuncios( $p->get_id() ) ) );
	}

	/** The bot records the first time each phone writes with an ad code. It never changes what the bot answers. */
	public static function anuncio_mensaje( WP_REST_Request $r ) {
		$code = strtoupper( sanitize_key( (string) $r->get_param( 'code' ) ) );
		$phone = self::phone_key( (string) $r->get_param( 'phone' ) );
		$ad = get_option( 'cvd_anuncio_' . $code );
		if ( ! is_array( $ad ) || 8 !== strlen( $phone ) ) { return rest_ensure_response( array( 'ok' => false ) ); }
		$nuevo = ! isset( $ad['contacts'][ $phone ] );
		if ( $nuevo ) { $ad['contacts'][ $phone ] = time(); update_option( 'cvd_anuncio_' . $code, $ad, false ); }
		return rest_ensure_response( array( 'ok' => true, 'nuevo' => $nuevo, 'product' => (int) $ad['product'] ) );
	}

	/** Per ad: people who wrote, and orders (with total) from those phones within AD_WINDOW_DAYS after they wrote. */
	private static function anuncios( int $id ): array {
		$codes = array_filter( (array) get_post_meta( $id, '_cvd_anuncios', true ) );
		if ( ! $codes ) { return array(); }
		$skip = array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-pending', 'wc-checkout-draft' );
		$out = array();
		foreach ( array_reverse( $codes ) as $code ) {
			$ad = get_option( 'cvd_anuncio_' . $code );
			if ( ! is_array( $ad ) ) { continue; }
			$contacts = (array) ( $ad['contacts'] ?? array() ); $orders = 0; $total = 0.0;
			if ( $contacts ) {
				$ids = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'date_created' => '>=' . min( $contacts ),
					'status' => array_diff( array_keys( wc_get_order_statuses() ), $skip ) ) );
				foreach ( $ids as $order_id ) {
					$order = wc_get_order( $order_id ); $since = $contacts[ self::phone_key( (string) $order->get_billing_phone() ) ] ?? null;
					$at = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
					if ( null !== $since && $at >= $since && $at <= $since + self::AD_WINDOW_DAYS * DAY_IN_SECONDS ) { $orders++; $total += (float) $order->get_total(); }
				}
			}
			$out[] = array( 'code' => $code, 'layout' => $ad['layout'] ?? '', 'format' => $ad['format'] ?? '', 'created' => gmdate( 'Y-m-d', (int) $ad['created'] ),
				'mensajes' => count( $contacts ), 'pedidos' => $orders, 'total' => $orders ? wp_strip_all_tags( wc_price( $total ) ) : '' );
		}
		return $out;
	}

	/** Create a dedicated account only on an explicit administrator form submission. */
	public static function access_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap"><h1>Acceso al catálogo</h1><p>Cuenta para preparar fotos, editar contenido y publicar. Sin permisos para cambiar precios, existencias o pedidos.</p>';
		if ( isset( $_POST['cvd_create_content_user'] ) ) {
			check_admin_referer( 'cvd_create_content_user' );
			$login = sanitize_user( wp_unslash( (string) ( $_POST['login'] ?? '' ) ), true );
			$name = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
			if ( ! $login || ! $name || username_exists( $login ) ) {
				echo '<p role="alert">El nombre de usuario está vacío o ya existe. No se cambió ninguna cuenta.</p>';
			} else {
				$pass = wp_generate_password( 20, false );
				$id = wp_insert_user( array( 'user_login' => $login, 'display_name' => $name, 'user_pass' => $pass, 'role' => 'subscriber' ) );
				if ( is_wp_error( $id ) ) { echo '<p role="alert">' . esc_html( $id->get_error_message() ) . '</p>'; }
				else {
					$user = new WP_User( $id ); $user->add_cap( self::CAP );
					update_user_meta( $id, '_cvd_content_temporary_password', '1' );
					echo '<p>Cuenta creada: <strong>' . esc_html( $login ) . '</strong></p><p>Contraseña temporal (se muestra una sola vez): <code>' . esc_html( $pass ) . '</code></p><p>Comparte estos datos solo con la persona responsable. Puede cambiar la contraseña desde el panel.</p>';
				}
			}
		}
		echo '<form method="post">'; wp_nonce_field( 'cvd_create_content_user' );
		echo '<p><label>Nombre <input name="display_name" value="Zayri" required></label></p><p><label>Usuario <input name="login" value="zayri" required></label></p><button class="button button-primary" name="cvd_create_content_user" value="1">Crear cuenta de contenido</button></form></div>';
	}

	public static function password( WP_REST_Request $r ) {
		$user = wp_get_current_user();
		$current = (string) $r->get_param( 'current' ); $next = (string) $r->get_param( 'next' );
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) || strlen( $next ) < 12 || strlen( $next ) > 200 || $current === $next ) { return new WP_Error( 'password', 'Comprueba tu contraseña actual y escribe una nueva de al menos 12 caracteres.', array( 'status' => 422 ) ); }
		wp_set_password( $next, $user->ID ); delete_user_meta( $user->ID, '_cvd_content_temporary_password' );
		return rest_ensure_response( array( 'ok' => true, 'login' => wp_login_url( add_query_arg( 'cvd_contenido', '1', home_url( '/' ) ) ) ) );
	}

	public static function suggest( WP_REST_Request $r ) {
		$name = mb_substr( sanitize_text_field( (string) $r->get_param( 'name' ) ), 0, 160 );
		$extra = mb_substr( sanitize_textarea_field( (string) $r->get_param( 'facts' ) ), 0, CVD_Taller_Text::MAX_EXTRA );
		if ( '' === trim( $name ) || '' === trim( $extra ) ) { return new WP_Error( 'facts', 'Escribe el nombre y al menos una característica confirmada.', array( 'status' => 422 ) ); }
		$facts = array( 'name' => $name, 'extra' => $extra, 'lines' => array(), 'attrs' => array(), 'price' => '', 'link' => '' );
		return rest_ensure_response( CVD_Gestora_Taller::content_text( $facts, (string) $r->get_param( 'kind' ) ) );
	}

	/** 3.14.145 (Ernesto 5-nov, #6144 «Mesa de oficina.»): title without trailing dots or extra spaces. */
	public static function clean_title( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		return trim( (string) preg_replace( '/[\s.]+$/u', '', $name ) );
	}

	/** Stock that Lennys set: a managed quantity above zero (on the product or, if variable, on one of its variations). */
	private static function has_stock( WC_Product $p ): bool {
		if ( $p->managing_stock() && (float) $p->get_stock_quantity() > 0 ) { return true; }
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v && $v->managing_stock() && (float) $v->get_stock_quantity() > 0 ) { return true; }
			}
		}
		return false;
	}

	/**
	 * 3.14.145 (Ernesto 5-nov): what a draft still needs before «Publicar en Casa Viva». The real photo is optional to save;
	 * price and stock are set by Lennys. Keys: descripcion, foto, precio, existencias.
	 */
	public static function faltan( WC_Product $p ): array {
		return array_keys( array_filter( array(
			'descripcion' => '' === trim( wp_strip_all_tags( $p->get_description() ) ),
			'foto'        => ! $p->get_image_id(),
			'precio'      => '' === $p->get_price(),
			'existencias' => ! self::has_stock( $p ),
		) ) );
	}

	/** «descripción (medidas y color), foto real, precio y existencias, que pone Lennys». Empty when nothing is missing. */
	public static function faltan_texto( array $faltan ): string {
		$names = array( 'descripcion' => 'descripción (medidas y color)', 'foto' => 'foto real', 'precio' => 'precio', 'existencias' => 'existencias' );
		$join = static function ( array $items ): string {
			$last = array_pop( $items );
			return $items ? implode( ', ', $items ) . ' y ' . $last : (string) $last;
		};
		$own = array(); $lennys = array();
		foreach ( $faltan as $key ) {
			if ( in_array( $key, array( 'precio', 'existencias' ), true ) ) { $lennys[] = $names[ $key ]; } elseif ( isset( $names[ $key ] ) ) { $own[] = $names[ $key ]; }
		}
		$parts = $own;
		if ( $lennys ) { $parts[] = $join( $lennys ) . ', que pone Lennys'; }
		if ( ! $parts ) { return ''; }
		return 1 === count( $parts ) ? $parts[0] : implode( ', ', array_slice( $parts, 0, -1 ) ) . ', ' . end( $parts );
	}

	/** Meta read by the 17:00 summary to Lennys (GET /bot/atrasos → fichas): set while a studio product stays unpublished. */
	public const READY = '_cvd_ficha_lista';

	/** Drafts saved from this screen, waiting for Lennys (price, stock, publish). For the 17:00 summary; no WhatsApp of its own. */
	public static function fichas_para_revisar(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'draft', 'pending' ), 'posts_per_page' => 20,
			'meta_key' => self::READY, 'orderby' => 'meta_value', 'order' => 'DESC', 'fields' => 'ids' ) ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || preg_match( '/\bprueba\b/iu', $p->get_name() ) ) { continue; }
			$faltan = self::faltan( $p );
			$out[] = array( 'id' => $p->get_id(), 'nombre' => self::clean_title( $p->get_name() ), 'faltan' => $faltan,
				'falta' => self::faltan_texto( $faltan ), 'desde' => (string) $p->get_meta( self::READY ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------------------------------------
	 * v2 (Ernesto 9-nov, «Apruebo, construye»): 3 piezas por producto, prioridad, motor de imágenes y bandeja.
	 * ---------------------------------------------------------------------------------------------- */
	public const PIEZAS = array( 'hero', 'life', 'ben' );
	public const ESTADOS = array( 'aprobada', 'revision', 'rechazada' );
	public const MOTIVOS = array( 'Color', 'Tamaño o forma', 'Piezas o etiqueta', 'Cortado', 'Fondo' );
	/** Subtope del panel en el VPS (USD por día). El recorte gratis no cuenta. */
	public const TOPE_DIA = 0.5;
	/** gpt-image-1.5, calidad media, fidelidad alta, 1–2 fotos de base: ~0,12–0,17 USD. El motor no devuelve el gasto real. */
	public const COSTE_IA = 0.15;
	public const MAX_OPCIONES = 3;
	/** «Nuevo» = creado hace menos de 30 días. */
	public const NUEVO_DIAS = 30;
	/** «Más vendido» = entre los 20 productos publicados con más ventas reales de WooCommerce (total_sales) y con 3 ventas o más. */
	public const TOP_N = 20;
	public const TOP_MIN = 3;
	public const ANUNCIOS = '_cvd_va_anuncios';
	public const PIEZAS_META = '_cvd_piezas';
	public const OPCIONES_META = '_cvd_opciones';
	private const MATRIZ = array( 'duda', 'resultado', 'prueba', 'condicion', 'fuente', 'matriz_ok' );
	private const IMAGEN_LOCK = 'cvd_content_imagen';
	private static $top = null;

	/** Best sellers, from WooCommerce's own sales counter. Computed once per request. */
	private static function top_ids(): array {
		if ( null === self::$top ) {
			self::$top = array();
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => self::TOP_N, 'fields' => 'ids',
				'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( (array) $ids as $id ) {
				if ( (int) get_post_meta( $id, 'total_sales', true ) >= self::TOP_MIN ) { self::$top[] = (int) $id; }
			}
		}
		return self::$top;
	}

	/** Why a product gets the 3 pieces: top (más vendido), nuevo (< 30 días) or anuncios (marcado desde el panel). */
	public static function prioridades( int $id ): array {
		if ( ! $id ) { return array(); }
		$out = array();
		if ( in_array( $id, self::top_ids(), true ) ) { $out[] = 'top'; }
		$created = get_post_time( 'U', true, $id ) ?: get_post_time( 'U', false, $id );
		if ( $created && time() - (int) $created < self::NUEVO_DIAS * DAY_IN_SECONDS ) { $out[] = 'nuevo'; }
		if ( '1' === (string) get_post_meta( $id, self::ANUNCIOS, true ) ) { $out[] = 'anuncios'; }
		return $out;
	}

	/** State of HERO, LIFESTYLE and BENEFICIO. estado '' = falta. */
	public static function piezas( int $id ): array {
		$raw = $id ? get_post_meta( $id, self::PIEZAS_META, true ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::PIEZAS as $k ) {
			$p = isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? $raw[ $k ] : array();
			$out[ $k ] = array( 'estado' => in_array( $p['estado'] ?? '', self::ESTADOS, true ) ? $p['estado'] : '', 'opcion' => (string) ( $p['opcion'] ?? '' ),
				'escalon' => (string) ( $p['escalon'] ?? '' ), 'coste' => (float) ( $p['coste'] ?? 0 ), 'motivos' => array_values( (array) ( $p['motivos'] ?? array() ) ),
				'web' => (int) ( $p['web'] ?? 0 ), 'at' => (string) ( $p['at'] ?? '' ) );
		}
		return $out;
	}

	private static function poner_pieza( int $id, string $pieza, array $data ): array {
		$all = self::piezas( $id );
		$keep = $all[ $pieza ];
		$all[ $pieza ] = array_merge( array( 'opcion' => '', 'escalon' => '', 'coste' => 0.0, 'motivos' => array(), 'web' => 0 ), array_intersect_key( $data, $keep ),
			array( 'at' => gmdate( 'c' ), 'por' => get_current_user_id() ) );
		// Approving the same HERO again keeps «ya está en la web».
		if ( 'hero' === $pieza && ! isset( $data['web'] ) && $keep['opcion'] && $keep['opcion'] === ( $data['opcion'] ?? '' ) ) { $all[ $pieza ]['web'] = $keep['web']; }
		update_post_meta( $id, self::PIEZAS_META, $all );
		return $all;
	}

	/** Each generated image is kept as an option of the product, so nothing is lost if the page is closed. */
	public static function opciones( int $id, bool $with_file = false ): array {
		$raw = $id ? get_post_meta( $id, self::OPCIONES_META, true ) : array();
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $o ) {
			if ( ! is_array( $o ) || empty( $o['id'] ) ) { continue; }
			if ( ! $with_file ) { unset( $o['file'] ); }
			$out[] = $o;
		}
		return $out;
	}

	/** Spend of the panel today (USD). The VPS does not return the real cost, so the panel keeps its own count. */
	public static function gasto(): array {
		$g = get_option( 'cvd_content_gasto', array() );
		$usd = is_array( $g ) && ( $g['dia'] ?? '' ) === wp_date( 'Y-m-d' ) ? (float) $g['usd'] : 0.0;
		return array( 'hoy' => round( $usd, 2 ), 'tope' => self::TOPE_DIA, 'ia' => self::COSTE_IA, 'lleno' => $usd + self::COSTE_IA > self::TOPE_DIA + 1e-9 );
	}

	private static function sumar_gasto( float $usd ): void {
		$hoy = wp_date( 'Y-m-d' ); $g = get_option( 'cvd_content_gasto', array() );
		$cur = is_array( $g ) && ( $g['dia'] ?? '' ) === $hoy ? (float) $g['usd'] : 0.0;
		update_option( 'cvd_content_gasto', array( 'dia' => $hoy, 'usd' => round( $cur + $usd, 4 ) ), false );
	}

	/**
	 * Free cut-out of the VPS (escalón 1). It is used only when the images session ships CVD_Photo_AI::vps_recorte();
	 * expected contract: vps_recorte( string $bytes ) → image bytes (PNG with transparency or on white) | WP_Error.
	 * The filter exists so the disposable test environment can stand in for the VPS; production never sets it.
	 */
	public static function recorte(): ?callable {
		$fn = method_exists( 'CVD_Photo_AI', 'vps_recorte' ) ? array( 'CVD_Photo_AI', 'vps_recorte' ) : null;
		$fn = apply_filters( 'cvd_content_recorte', $fn );
		return is_callable( $fn ) ? $fn : null;
	}

	/** What the phone needs to draw the ladder: which steps exist and today's spend. */
	public static function motor(): array {
		$providers = class_exists( 'CVD_Photo_AI' ) ? CVD_Photo_AI::providers() : array();
		return array( 'recorte' => null !== self::recorte(), 'ia' => ! empty( $providers['vps'] ), 'gasto' => self::gasto(), 'max' => self::MAX_OPCIONES, 'motivos' => self::MOTIVOS );
	}

	/** Product photos that can be the starting photo (main image first, then the gallery). */
	private static function fotos( WC_Product $p ): array {
		$out = array();
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'intval', array_merge( array( $p->get_image_id() ), (array) $p->get_gallery_image_ids() ) ) ) ) ), 0, 6 ) as $att ) {
			$url = (string) wp_get_attachment_image_url( $att, 'large' );
			if ( $url ) { $out[] = array( 'id' => $att, 'url' => $url, 'full' => (string) wp_get_attachment_image_url( $att, 'full' ) ); }
		}
		return $out;
	}

	private static function editable( $id ) {
		$p = wc_get_product( absint( $id ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) { return new WP_Error( 'missing', 'Primero guarda el producto.', array( 'status' => 404 ) ); }
		return $p;
	}

	private static function fresh( WC_Product $p ): array {
		clean_post_cache( $p->get_id() );
		$item = self::projection( wc_get_product( $p->get_id() ) );
		$item['anuncios'] = self::anuncios( $p->get_id() );
		return $item;
	}

	/** «Va a anuncios»: the product gets the 3 pieces even if it is not new or a best seller. */
	public static function prioridad( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		if ( '1' === (string) $r->get_param( 'anuncios' ) ) { update_post_meta( $p->get_id(), self::ANUNCIOS, '1' ); } else { delete_post_meta( $p->get_id(), self::ANUNCIOS ); }
		return rest_ensure_response( self::fresh( $p ) );
	}

	/** Duda → resultado → prueba (+ condición y fuente), kept in the product brief before LIFESTYLE and BENEFICIO. */
	public static function matriz( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$m = array();
		foreach ( array( 'duda', 'resultado', 'prueba', 'condicion', 'fuente' ) as $k ) { $m[ $k ] = mb_substr( sanitize_text_field( (string) $r->get_param( $k ) ), 0, 160 ); }
		if ( '' === $m['duda'] || '' === $m['resultado'] || '' === $m['prueba'] ) { return new WP_Error( 'matriz', 'Escribe la duda, el resultado y la prueba.', array( 'status' => 422 ) ); }
		if ( class_exists( 'CVD_Fichas' ) && CVD_Fichas::frase_prohibida( implode( ' ', $m ) ) ) { return new WP_Error( 'matriz', 'Revisa el contenido: contiene una nota interna.', array( 'status' => 422 ) ); }
		$lock = 'cvd_content_save_' . $p->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', 'Hay otro guardado en curso. Vuelve a intentarlo.', array( 'status' => 409 ) ); }
		try {
			clean_post_cache( $p->get_id() ); $p = wc_get_product( $p->get_id() );
			if ( ! hash_equals( self::version( $p ), (string) $r->get_param( 'version' ) ) ) { return new WP_Error( 'conflict', 'Recarga el producto: ha cambiado.', array( 'status' => 409 ) ); }
			$brief = (array) $p->get_meta( '_cvd_content_brief' );
			$brief = array_merge( $brief, $m, array( 'fuente' => $m['fuente'] ?: 'Ficha · características', 'matriz_ok' => gmdate( 'c' ) ) );
			$p->update_meta_data( '_cvd_content_brief', $brief ); $p->save();
			return rest_ensure_response( self::fresh( $p ) );
		} finally { delete_option( $lock ); }
	}

	/** Aprobada · Revisión · Rechazada for one piece (and the option it refers to). Rechazada keeps what failed. */
	public static function pieza( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$pieza = sanitize_key( (string) $r->get_param( 'pieza' ) ); $estado = sanitize_key( (string) $r->get_param( 'estado' ) );
		if ( ! in_array( $pieza, self::PIEZAS, true ) || ! in_array( $estado, self::ESTADOS, true ) ) { return new WP_Error( 'pieza', 'Elige la pieza y su estado.', array( 'status' => 422 ) ); }
		if ( 'hero' !== $pieza && ! self::prioridades( $p->get_id() ) ) { return new WP_Error( 'solo_top', 'Este producto lleva solo HERO. Márcalo «Va a anuncios» para hacer las 3 piezas.', array( 'status' => 422 ) ); }
		$motivos = array_values( array_intersect( self::MOTIVOS, array_map( 'strval', (array) $r->get_param( 'motivos' ) ) ) );
		$id = sanitize_key( (string) $r->get_param( 'opcion' ) ); $found = null;
		$all = self::opciones( $p->get_id(), true );
		foreach ( $all as $i => $o ) { if ( $o['id'] === $id && $o['pieza'] === $pieza ) { $found = $i; } }
		if ( 'ben' !== $pieza && null === $found ) { return new WP_Error( 'opcion', 'Esa opción ya no está. Recarga el producto.', array( 'status' => 404 ) ); }
		if ( null !== $found ) {
			$all[ $found ]['estado'] = $estado; $all[ $found ]['motivos'] = $motivos;
			update_post_meta( $p->get_id(), self::OPCIONES_META, $all );
		}
		$o = null !== $found ? $all[ $found ] : array();
		self::poner_pieza( $p->get_id(), $pieza, array( 'estado' => $estado, 'opcion' => $id, 'escalon' => (string) ( $o['escalon'] ?? '' ), 'coste' => (float) ( $o['coste'] ?? 0 ), 'motivos' => $motivos ) );
		return rest_ensure_response( self::fresh( $p ) );
	}

	/** Uploaded starting photos (base1, base2): JPG, PNG or WebP. Over 4 MB they are reduced for the VPS. */
	private static function bases( WP_REST_Request $r ) {
		$out = array();
		foreach ( array( 'base1', 'base2' ) as $name ) {
			$file = $r->get_file_params()[ $name ] ?? array();
			if ( empty( $file['tmp_name'] ) ) { continue; }
			if ( ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 8 * MB_IN_BYTES ) { return new WP_Error( 'base', 'La foto de partida debe pesar hasta 8 MB.', array( 'status' => 422 ) ); }
			$bytes = (string) file_get_contents( $file['tmp_name'] ); $size = @getimagesizefromstring( $bytes );
			if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return new WP_Error( 'base', 'La foto de partida debe ser JPG, PNG o WebP.', array( 'status' => 422 ) ); }
			if ( strlen( $bytes ) > 4 * MB_IN_BYTES ) { $bytes = self::reducir( $bytes, 1536 ); if ( is_wp_error( $bytes ) ) { return $bytes; } }
			$out[] = array( 'bytes' => $bytes, 'w' => (int) $size[0], 'h' => (int) $size[1] );
		}
		return $out ?: new WP_Error( 'base', 'Elige la foto real de partida.', array( 'status' => 422 ) );
	}

	private static function reducir( string $bytes, int $max ) {
		$img = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false;
		if ( ! $img ) { return new WP_Error( 'base', 'No se pudo abrir la foto de partida.', array( 'status' => 422 ) ); }
		$w = imagesx( $img ); $h = imagesy( $img ); $s = min( 1, $max / max( $w, $h ) );
		$out = imagecreatetruecolor( max( 1, (int) round( $w * $s ) ), max( 1, (int) round( $h * $s ) ) );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopyresampled( $out, $img, 0, 0, 0, 0, imagesx( $out ), imagesy( $out ), $w, $h );
		ob_start(); imagejpeg( $out, null, 90 ); return (string) ob_get_clean();
	}

	/**
	 * HERO on pure white: the near-white background that touches the edges becomes #FFFFFF, the product is measured and
	 * placed on a larger square white canvas so it stays whole with equal margins. Returns JPEG bytes.
	 */
	public static function blanco( string $bytes ) {
		$src = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false;
		if ( ! $src ) { return new WP_Error( 'motor', 'El motor devolvió una imagen que no se pudo abrir.', array( 'status' => 502 ) ); }
		$w = imagesx( $src ); $h = imagesy( $src ); $s = min( 1, 1600 / max( $w, $h ) );
		$W = max( 1, (int) round( $w * $s ) ); $H = max( 1, (int) round( $h * $s ) );
		$img = imagecreatetruecolor( $W, $H ); $white = imagecolorallocate( $img, 255, 255, 255 );
		imagefilledrectangle( $img, 0, 0, $W - 1, $H - 1, $white ); imagealphablending( $img, true );
		imagecopyresampled( $img, $src, 0, 0, 0, 0, $W, $H, $w, $h );
		$near = static function ( int $c ): bool { return ( ( $c >> 16 ) & 255 ) >= 236 && ( ( $c >> 8 ) & 255 ) >= 236 && ( $c & 255 ) >= 236; };
		for ( $y = 0; $y < $H; $y++ ) {
			for ( $x = 0; $x < $W && $near( imagecolorat( $img, $x, $y ) ); $x++ ) { imagesetpixel( $img, $x, $y, $white ); }
			for ( $x = $W - 1; $x >= 0 && $near( imagecolorat( $img, $x, $y ) ); $x-- ) { imagesetpixel( $img, $x, $y, $white ); }
		}
		for ( $x = 0; $x < $W; $x++ ) {
			for ( $y = 0; $y < $H && $near( imagecolorat( $img, $x, $y ) ); $y++ ) { imagesetpixel( $img, $x, $y, $white ); }
			for ( $y = $H - 1; $y >= 0 && $near( imagecolorat( $img, $x, $y ) ); $y-- ) { imagesetpixel( $img, $x, $y, $white ); }
		}
		$x0 = $W; $y0 = $H; $x1 = -1; $y1 = -1;
		for ( $y = 0; $y < $H; $y++ ) {
			for ( $x = 0; $x < $W; $x++ ) {
				if ( 0xFFFFFF !== ( imagecolorat( $img, $x, $y ) & 0xFFFFFF ) ) { $x0 = min( $x0, $x ); $x1 = max( $x1, $x ); $y0 = min( $y0, $y ); $y1 = max( $y1, $y ); }
			}
		}
		if ( $x1 < 0 ) { return new WP_Error( 'motor', 'La imagen salió en blanco. Prueba con otra foto de partida.', array( 'status' => 502 ) ); }
		$bw = $x1 - $x0 + 1; $bh = $y1 - $y0 + 1;
		$side = (int) ceil( max( $bw, $bh ) / 0.84 ); $k = min( 1, 1600 / $side ); $S = max( 1, (int) round( $side * $k ) );
		$pw = max( 1, (int) round( $bw * $k ) ); $ph = max( 1, (int) round( $bh * $k ) );
		$out = imagecreatetruecolor( $S, $S ); imagefilledrectangle( $out, 0, 0, $S - 1, $S - 1, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopyresampled( $out, $img, (int) floor( ( $S - $pw ) / 2 ), (int) floor( ( $S - $ph ) / 2 ), $x0, $y0, $pw, $ph, $bw, $bh );
		ob_start(); imagejpeg( $out, null, 92 ); $jpg = (string) ob_get_clean();
		// Touching the frame means the generated product may be cut: the panel says «Toca el borde».
		return array( 'bytes' => $jpg, 'cortado' => 0 === $x0 || 0 === $y0 || $W - 1 === $x1 || $H - 1 === $y1, 'lado' => $S );
	}

	private static function guardar_opcion( int $id, array $o, string $jpg ) {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		$sub = '/cvd-opciones/' . $id; $dir = $up['basedir'] . $sub;
		if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); }
		$name = $o['pieza'] . '-' . strtolower( wp_generate_password( 24, false ) ) . '.jpg';
		if ( false === file_put_contents( $dir . '/' . $name, $jpg ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		$o = array_merge( $o, array( 'id' => strtolower( wp_generate_password( 12, false ) ), 'url' => $up['baseurl'] . $sub . '/' . $name, 'file' => $sub . '/' . $name,
			'at' => gmdate( 'c' ), 'por' => get_current_user_id(), 'estado' => '', 'motivos' => array() ) );
		$all = self::opciones( $id, true ); $all[] = $o;
		// Keep the last 9 options; older files go away unless a piece still points to them.
		$used = array_column( self::piezas( $id ), 'opcion' );
		while ( count( $all ) > 9 ) {
			$old = array_shift( $all );
			if ( in_array( $old['id'], $used, true ) ) { $all[] = $old; if ( count( array_diff( array_column( $all, 'id' ), $used ) ) === 0 ) { break; } continue; }
			if ( ! empty( $old['file'] ) && false === strpos( (string) $old['file'], '..' ) ) { wp_delete_file( $up['basedir'] . $old['file'] ); }
		}
		update_post_meta( $id, self::OPCIONES_META, $all );
		unset( $o['file'] );
		return $o;
	}

	/**
	 * One step of the ladder, synchronous (the VPS takes 9–60 s; Core waits up to 150 s). escalon: recorte (free, only if
	 * the VPS offers it) or ia (gpt-image-1.5, calidad media, fidelidad alta, real photos as base). One image at a time.
	 */
	public static function crear( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$id = $p->get_id(); $pieza = sanitize_key( (string) $r->get_param( 'pieza' ) ); $escalon = sanitize_key( (string) $r->get_param( 'escalon' ) );
		if ( ! in_array( $pieza, array( 'hero', 'life' ), true ) || ! in_array( $escalon, array( 'recorte', 'ia' ), true ) || ( 'life' === $pieza && 'ia' !== $escalon ) ) { return new WP_Error( 'pieza', 'Elige la pieza y el escalón.', array( 'status' => 422 ) ); }
		if ( 'life' === $pieza ) {
			if ( ! self::prioridades( $id ) ) { return new WP_Error( 'solo_top', 'Este producto lleva solo HERO. Márcalo «Va a anuncios» para hacer las 3 piezas.', array( 'status' => 422 ) ); }
			if ( empty( ( (array) $p->get_meta( '_cvd_content_brief' ) )['matriz_ok'] ) ) { return new WP_Error( 'matriz', 'Primero confirma duda → resultado → prueba.', array( 'status' => 422 ) ); }
		}
		$hechas = count( array_filter( self::opciones( $id ), static fn( $o ) => $o['pieza'] === $pieza ) );
		if ( $hechas >= self::MAX_OPCIONES && '1' !== (string) $r->get_param( 'forzar' ) ) { return new WP_Error( 'opciones', 'Ya hay ' . $hechas . ' opciones. Elige una o confirma hacer otra.', array( 'status' => 409, 'opciones' => $hechas ) ); }
		$recorte = self::recorte();
		if ( 'recorte' === $escalon && ! $recorte ) { return new WP_Error( 'sin_recorte', 'El recorte gratis aún no está disponible.', array( 'status' => 422 ) ); }
		if ( 'ia' === $escalon ) {
			if ( empty( CVD_Photo_AI::providers()['vps'] ) ) { return new WP_Error( 'motor', 'Pide al administrador que configure el servidor de imágenes.', array( 'status' => 422 ) ); }
			if ( self::gasto()['lleno'] ) { return new WP_Error( 'tope', 'Límite de hoy alcanzado (0,50 USD). Mañana se puede seguir; el recorte gratis sigue disponible.', array( 'status' => 429 ) ); }
		}
		$bases = self::bases( $r );
		if ( is_wp_error( $bases ) ) { return $bases; }
		// One image at a time for the whole panel (a lock older than 200 s is a crashed request).
		if ( ! add_option( self::IMAGEN_LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::IMAGEN_LOCK ) < 200 ) { return new WP_Error( 'ocupado', 'Otra foto en marcha. Espera a que termine.', array( 'status' => 429 ) ); }
			delete_option( self::IMAGEN_LOCK );
			if ( ! add_option( self::IMAGEN_LOCK, time(), '', false ) ) { return new WP_Error( 'ocupado', 'Otra foto en marcha. Espera a que termine.', array( 'status' => 429 ) ); }
		}
		try {
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 200 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$t0 = microtime( true ); $ancho = $bases[0]['w'] >= 1.25 * $bases[0]['h']; $coste = 0.0;
			if ( 'recorte' === $escalon ) {
				$bytes = call_user_func( $recorte, $bases[0]['bytes'] );
			} else {
				$m = (array) $p->get_meta( '_cvd_content_brief' ); $name = self::clean_title( $p->get_name() );
				$prompt = 'hero' === $pieza
					? "E-commerce catalog photo of exactly the product in the reference photo(s): {$name}. Keep its exact shape, proportions, colors, materials, labels, parts and accessories; do not add, remove or redesign anything. Whole product visible, centered, with generous margin on every side, nothing cut by the frame. Pure white seamless background (#FFFFFF), soft even studio light, very soft contact shadow only. No text, no logo, no price, no props, no hands."
					: "Lifestyle photo for an online store: one adult person at a warm, simple Cuban home using exactly the product in the reference photo(s) ({$name}) and living this result: " . ( $m['resultado'] ?? '' ) . '. Keep the product exact: shape, proportions, colors, labels and parts unchanged, at real scale. Natural light, realistic, calm emotion. No text, no logo, no price, no watermark.';
				$bytes = CVD_Photo_AI::vps_image( $prompt, array( 'calidad' => 'media', 'tam' => $ancho ? '1536x1024' : '1024x1536', 'modelo' => 'gpt-image-1.5', 'fidelidad' => 'alta' ), array_column( $bases, 'bytes' ) );
				if ( ! is_wp_error( $bytes ) ) { $coste = self::COSTE_IA; self::sumar_gasto( $coste ); }
			}
			if ( is_wp_error( $bytes ) || ! is_string( $bytes ) || '' === $bytes ) {
				$e = is_wp_error( $bytes ) ? $bytes : new WP_Error( 'motor', 'El recorte no devolvió imagen.', array( 'status' => 502 ) );
				$status = (int) ( $e->get_error_data()['status'] ?? 502 );
				return new WP_Error( 'motor', $e->get_error_message(), array( 'status' => in_array( $status, array( 400, 422, 429 ), true ) ? $status : 502, 'escalon' => $escalon, 'gasto' => self::gasto() ) );
			}
			if ( 'hero' === $pieza ) {
				$made = self::blanco( $bytes );
			} else {
				$made = @getimagesizefromstring( $bytes ) ? array( 'bytes' => self::reducir( $bytes, 1600 ), 'cortado' => false ) : new WP_Error( 'motor', 'El motor devolvió una imagen que no se pudo abrir.', array( 'status' => 502 ) );
			}
			if ( is_wp_error( $made ) || is_wp_error( $made['bytes'] ) ) { return is_wp_error( $made ) ? $made : $made['bytes']; }
			$base = absint( $r->get_param( 'base_id' ) );
			$antes = $base && in_array( $base, array_column( self::fotos( $p ), 'id' ), true ) ? (string) wp_get_attachment_image_url( $base, 'large' ) : '';
			$opcion = self::guardar_opcion( $id, array( 'pieza' => $pieza, 'escalon' => $escalon, 'coste' => $coste, 'ancho' => $ancho, 'cortado' => (bool) $made['cortado'],
				'fallo' => mb_substr( sanitize_text_field( (string) $r->get_param( 'fallo' ) ), 0, 120 ), 'antes' => $antes, 'segundos' => (int) round( microtime( true ) - $t0 ) ), $made['bytes'] );
			if ( is_wp_error( $opcion ) ) { return $opcion; }
			return rest_ensure_response( array( 'opcion' => $opcion, 'opciones' => self::opciones( $id ), 'gasto' => self::gasto() ) );
		} finally { delete_option( self::IMAGEN_LOCK ); }
	}

	/* ---------- Bandeja «Dudas del catálogo» ---------- */

	/**
	 * Where the doubts come from. The images session will provide it (contract: bandeja-dudas-contrato.md); until then
	 * there is no source and the inbox stays hidden. A source is a class name or object with
	 * dudas( string $estado ): array and responder( string $id, array $respuesta ): array|WP_Error.
	 */
	public static function fuente() {
		$f = apply_filters( 'cvd_content_dudas_fuente', class_exists( 'CVD_Dudas_Catalogo' ) ? 'CVD_Dudas_Catalogo' : null );
		if ( ( is_object( $f ) || ( is_string( $f ) && class_exists( $f ) ) ) && method_exists( $f, 'dudas' ) && method_exists( $f, 'responder' ) ) { return $f; }
		return null;
	}

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	/** One card of the contract, cleaned: unknown keys dropped, text limited, URLs checked. */
	private static function duda_limpia( $d ): ?array {
		if ( ! is_array( $d ) || empty( $d['id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $d['id'] ) ) { return null; }
		$media = static fn( $x ) => array( 'foto' => esc_url_raw( (string) ( $x['foto'] ?? '' ) ), 'texto' => self::txt( $x['texto'] ?? '', 300 ), 'valor' => self::txt( $x['valor'] ?? '', 160 ) );
		$tipo = in_array( $d['tipo'] ?? '', array( 'foto', 'dato', 'texto', 'regla' ), true ) ? $d['tipo'] : 'dato';
		$prod = (array) ( $d['producto'] ?? array() );
		$hist = array();
		foreach ( array_slice( (array) ( $d['historial'] ?? array() ), -10 ) as $h ) {
			if ( is_array( $h ) ) { $hist[] = array( 'quien' => self::txt( $h['quien'] ?? '', 60 ), 'cuando' => self::txt( $h['cuando'] ?? '', 40 ), 'accion' => sanitize_key( (string) ( $h['accion'] ?? '' ) ), 'antes' => self::txt( $h['antes'] ?? '', 300 ), 'despues' => self::txt( $h['despues'] ?? '', 300 ), 'destino' => self::txt( $h['destino'] ?? '', 120 ) ); }
		}
		$tarea = (array) ( $d['tarea'] ?? array() ); $esc = (array) ( $d['escalon'] ?? array() ); $op = (array) ( $d['opciones'] ?? array() );
		return array( 'id' => (string) $d['id'], 'tipo' => $tipo, 'pieza' => in_array( $d['pieza'] ?? '', array( 'HERO', 'LIFESTYLE', 'BENEFICIO' ), true ) ? $d['pieza'] : null,
			'producto' => array( 'id' => absint( $prod['id'] ?? 0 ), 'nombre' => self::txt( $prod['nombre'] ?? '', 160 ), 'foto' => esc_url_raw( (string) ( $prod['foto'] ?? '' ) ) ),
			'pregunta' => self::txt( $d['pregunta'] ?? '', 120 ), 'origen_texto' => self::txt( $d['origen_texto'] ?? '', 120 ),
			'antes' => $media( (array) ( $d['antes'] ?? array() ) ), 'despues' => $media( (array) ( $d['despues'] ?? array() ) ),
			'dato' => self::txt( $d['dato'] ?? '', 160 ), 'regla' => self::txt( $d['regla'] ?? '', 300 ),
			'escalon' => $esc ? array( 'usado' => sanitize_key( (string) ( $esc['usado'] ?? '' ) ), 'coste_usd' => (float) ( $esc['coste_usd'] ?? 0 ), 'fallo_previo' => self::txt( $esc['fallo_previo'] ?? '', 120 ) ) : null,
			'opciones' => array( 'corregir' => in_array( $op['corregir'] ?? '', array( 'texto', 'numero', 'foto' ), true ) ? $op['corregir'] : ( 'foto' === $tipo ? 'foto' : 'texto' ), 'explicar' => false !== ( $op['explicar'] ?? true ) ),
			'estado' => in_array( $d['estado'] ?? '', array( 'pendiente', 'revision', 'marcha', 'resultado', 'hecha' ), true ) ? $d['estado'] : 'pendiente',
			'tarea' => $tarea ? array( 'id' => self::txt( $tarea['id'] ?? '', 40 ), 'texto' => self::txt( $tarea['texto'] ?? '', 160 ), 'listo' => ! empty( $tarea['listo'] ) ) : null,
			'resultado' => isset( $d['resultado'] ) && is_array( $d['resultado'] ) ? $media( $d['resultado'] ) : null,
			'version' => self::txt( $d['version'] ?? '', 40 ), 'historial' => $hist );
	}

	public static function dudas( WP_REST_Request $r ) {
		$f = self::fuente();
		if ( ! $f ) { return new WP_Error( 'dudas_off', 'La bandeja de dudas aún no está conectada.', array( 'status' => 404 ) ); }
		$estado = in_array( $r->get_param( 'estado' ), array( 'pendiente', 'marcha', 'hecha' ), true ) ? $r->get_param( 'estado' ) : 'pendiente';
		$data = is_object( $f ) ? $f->dudas( $estado ) : call_user_func( array( $f, 'dudas' ), $estado );
		if ( is_wp_error( $data ) ) { return $data; }
		$list = isset( $data['dudas'] ) ? (array) $data['dudas'] : (array) $data;
		$cuenta = array();
		foreach ( array( 'pendiente', 'marcha', 'hecha' ) as $k ) { $cuenta[ $k ] = absint( $data['cuenta'][ $k ] ?? 0 ); }
		$response = rest_ensure_response( array( 'dudas' => array_values( array_filter( array_map( array( __CLASS__, 'duda_limpia' ), $list ) ) ), 'cuenta' => $cuenta ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public static function dudas_respuesta( WP_REST_Request $r ) {
		$f = self::fuente();
		if ( ! $f ) { return new WP_Error( 'dudas_off', 'La bandeja de dudas aún no está conectada.', array( 'status' => 404 ) ); }
		$resp = sanitize_key( (string) $r->get_param( 'respuesta' ) );
		if ( ! in_array( $resp, array( 'aprobada', 'revision', 'rechazada', 'corregir', 'explicar' ), true ) ) { return new WP_Error( 'respuesta', 'Elige una respuesta.', array( 'status' => 422 ) ); }
		$data = array( 'respuesta' => $resp, 'motivos' => array_values( array_intersect( self::MOTIVOS, array_map( 'strval', (array) $r->get_param( 'motivos' ) ) ) ),
			'valor' => mb_substr( sanitize_textarea_field( (string) $r->get_param( 'valor' ) ), 0, 300 ), 'texto' => mb_substr( sanitize_textarea_field( (string) $r->get_param( 'texto' ) ), 0, 1000 ),
			'quien' => (string) wp_get_current_user()->user_login, 'version' => self::txt( $r->get_param( 'version' ), 40 ) );
		foreach ( array( 'foto' => array( 'image/jpeg', 'image/png', 'image/webp' ), 'audio' => array( 'audio/webm', 'video/webm', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/mpeg' ) ) as $name => $mimes ) {
			$file = $r->get_file_params()[ $name ] ?? array();
			if ( empty( $file['tmp_name'] ) ) { continue; }
			$type = 'foto' === $name ? (string) wp_get_image_mime( $file['tmp_name'] ) : strtolower( (string) preg_replace( '/;.*$/', '', (string) ( $file['type'] ?? '' ) ) );
			if ( ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 8 * MB_IN_BYTES || ! in_array( $type, $mimes, true ) ) { return new WP_Error( $name, 'foto' === $name ? 'Elige una foto JPG, PNG o WebP de hasta 8 MB.' : 'La nota de voz no es válida.', array( 'status' => 422 ) ); }
			$data[ $name ] = array( 'tmp_name' => $file['tmp_name'], 'type' => $type, 'size' => (int) filesize( $file['tmp_name'] ) );
		}
		if ( 'corregir' === $resp && '' === trim( $data['valor'] ) && empty( $data['foto'] ) ) { return new WP_Error( 'respuesta', 'Escribe el dato bueno o sube otra foto.', array( 'status' => 422 ) ); }
		if ( 'explicar' === $resp && '' === trim( $data['texto'] ) && empty( $data['audio'] ) ) { return new WP_Error( 'respuesta', 'Graba una nota de voz o escribe la explicación.', array( 'status' => 422 ) ); }
		$id = (string) $r->get_param( 'duda' );
		$out = is_object( $f ) ? $f->responder( $id, $data ) : call_user_func( array( $f, 'responder' ), $id, $data );
		return is_wp_error( $out ) ? $out : rest_ensure_response( array( 'estado' => self::txt( $out['estado'] ?? '', 20 ), 'tarea_id' => isset( $out['tarea_id'] ) ? self::txt( $out['tarea_id'], 40 ) : null,
			'destino' => self::txt( $out['destino'] ?? '', 120 ), 'aviso' => self::txt( $out['aviso'] ?? 'Hecho.', 160 ) ) );
	}

	private static function projection( WC_Product $p ): array {
		$faltan = self::faltan( $p );
		$id = (int) $p->get_id(); $prios = self::prioridades( $id ); $piezas = self::piezas( $id );
		return array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'description' => $p->get_description(),
			// v2: prioridad (top · nuevo · anuncios), las 3 piezas, opciones guardadas y fotos de partida.
			'prio' => $prios[0] ?? '', 'prios' => $prios, 'piezas' => $piezas, 'opciones' => self::opciones( $id ), 'fotos' => self::fotos( $p ),
			'falta_hero' => 'aprobada' !== $piezas['hero']['estado'],
			'faltan_piezas' => $prios ? count( array_filter( array( 'life', 'ben' ), static fn( $k ) => 'aprobada' !== $piezas[ $k ]['estado'] ) ) : 0,
			'short' => $p->get_short_description(), 'status' => $p->get_status(),
			'price' => '' === $p->get_price() ? '' : ( $p->is_type( 'variable' ) ? 'Desde ' : '' ) . wp_strip_all_tags( wc_price( $p->get_price() ) ), 'url' => $p->get_permalink(),
			'image' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ),
			'image_full' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'full' ),
			// A real store sale on a simple product, for the offer card. Variable products show their «Desde» price only.
			'sale' => $p->is_type( 'simple' ) && $p->is_on_sale() ? array( 'regular' => wp_strip_all_tags( wc_price( $p->get_regular_price() ) ), 'price' => wp_strip_all_tags( wc_price( $p->get_sale_price() ) ),
				'until' => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->date( 'Y-m-d' ) : '' ) : null,
			'image_id' => (int) $p->get_image_id(), 'gallery' => $p->get_gallery_image_ids(),
			'brief' => (array) $p->get_meta( '_cvd_content_brief' ), 'reviews' => self::reviews( $p->get_id() ),
			'version' => self::version( $p ), 'protected' => (bool) $p->get_meta( self::LOCK ),
			// 3.14.145: what is still missing before it can go live (shown on save; «Publicar» waits for price and stock).
			'faltan' => $faltan, 'falta' => self::faltan_texto( $faltan ) );
	}

	/** Approved store reviews: written on the web by customers, or real comments saved with the customer's permission. */
	private static function reviews( int $id ): array {
		if ( ! $id ) { return array(); }
		return array_map( static fn( $c ) => array( 'id' => (int) $c->comment_ID, 'text' => $c->comment_content, 'name' => $c->comment_author,
			'rating' => (int) get_comment_meta( $c->comment_ID, 'rating', true ), 'origin' => get_comment_meta( $c->comment_ID, '_cvd_resena_origen', true ) ?: 'web' ),
			get_comments( array( 'post_id' => $id, 'type' => 'review', 'status' => 'approve', 'number' => 10, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) ) );
	}

	/** A real customer comment pasted by the editor becomes a store review, only with the customer's permission. Never invented. */
	public static function resena( WP_REST_Request $r ) {
		$p = wc_get_product( absint( $r->get_param( 'id' ) ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) { return new WP_Error( 'missing', 'Primero guarda el producto.', array( 'status' => 404 ) ); }
		if ( '1' !== (string) $r->get_param( 'permission' ) ) { return new WP_Error( 'permission', 'Solo con el permiso del cliente para publicar su comentario y su nombre.', array( 'status' => 422 ) ); }
		$text = mb_substr( sanitize_textarea_field( (string) $r->get_param( 'text' ) ), 0, 400 );
		$name = mb_substr( sanitize_text_field( (string) $r->get_param( 'name' ) ), 0, 60 );
		$rating = absint( $r->get_param( 'rating' ) );
		if ( mb_strlen( trim( $text ) ) < 5 || '' === trim( $name ) || $rating > 5 ) { return new WP_Error( 'content', 'Escribe el comentario del cliente y su nombre.', array( 'status' => 422 ) ); }
		$comment = wp_insert_comment( array( 'comment_post_ID' => $p->get_id(), 'comment_author' => $name, 'comment_content' => $text, 'comment_type' => 'review', 'comment_approved' => 1 ) );
		if ( ! $comment ) { return new WP_Error( 'save', 'No se pudo guardar la reseña.', array( 'status' => 500 ) ); }
		if ( $rating ) { update_comment_meta( $comment, 'rating', $rating ); }
		update_comment_meta( $comment, '_cvd_resena_origen', 'whatsapp' );
		update_comment_meta( $comment, '_cvd_resena_permiso', get_current_user_id() );
		if ( class_exists( 'WC_Comments' ) ) { WC_Comments::clear_transients( $p->get_id() ); }
		return rest_ensure_response( array( 'reviews' => self::reviews( $p->get_id() ), 'saved' => (int) $comment ) );
	}

	private static function version( WC_Product $p ): string {
		return hash( 'sha256', wp_json_encode( array( $p->get_name(), $p->get_description(), $p->get_short_description(), $p->get_image_id(), $p->get_gallery_image_ids(), $p->get_status(), $p->get_meta( '_cvd_content_brief' ) ) ) );
	}

	/** Text part of the version, to tell the editor whether text or the source photo changed. */
	private static function text_version( WC_Product $p ): string {
		return hash( 'sha256', wp_json_encode( array( $p->get_name(), $p->get_description(), $p->get_short_description(), $p->get_meta( '_cvd_content_brief' ) ) ) );
	}

	public static function review( WP_REST_Request $r ) {
		$p = wc_get_product( absint( $r['id'] ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) || ! hash_equals( self::version( $p ), (string) $r['version'] ) ) { return new WP_Error( 'conflict', 'Abre de nuevo el producto antes de guardar la opción.', array( 'status' => 409 ) ); }
		$file = $r->get_file_params()['photo'] ?? array();
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 3 * MB_IN_BYTES ) { return new WP_Error( 'image', 'La opción es demasiado grande.', array( 'status' => 422 ) ); }
		$bytes = file_get_contents( $file['tmp_name'] );
		$dim = @getimagesizefromstring( $bytes );
		if ( ! $dim || ! in_array( $dim['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) || $dim[0] > 1920 || $dim[1] > 1920 ) { return new WP_Error( 'image', 'La opción no es válida.', array( 'status' => 422 ) ); }
		$key = '_cvd_content_review_' . $p->get_id();
		$value = array( 'version' => self::version( $p ), 'photo' => (int) $p->get_image_id(), 'text' => self::text_version( $p ), 'image' => 'data:' . $dim['mime'] . ';base64,' . base64_encode( $bytes ) );
		if ( false === update_user_meta( get_current_user_id(), $key, $value ) && get_user_meta( get_current_user_id(), $key, true ) !== $value ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function products( WP_REST_Request $r ) {
		$id = absint( $r->get_param( 'id' ) );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) || $p->is_type( 'variation' ) ) { return new WP_Error( 'missing', 'Producto no encontrado.', array( 'status' => 404 ) ); }
			$item = self::projection( $p );
			$item['anuncios'] = self::anuncios( $p->get_id() );
			$review = get_user_meta( get_current_user_id(), '_cvd_content_review_' . $id, true );
			if ( ! is_array( $review ) || empty( $review['image'] ) ) { return rest_ensure_response( $item ); }
			if ( ( $review['version'] ?? '' ) === $item['version'] ) {
				$item['review'] = $review['image'];
			} else {
				// Kept for the editor to compare with the current product; never applied automatically.
				$item['review_old'] = $review['image'];
				$item['review_changed'] = array_keys( array_filter( array(
					'foto' => isset( $review['photo'] ) && (int) $review['photo'] !== (int) $p->get_image_id(),
					'texto' => isset( $review['text'] ) && $review['text'] !== self::text_version( $p ),
				) ) );
			}
			$response = rest_ensure_response( $item );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		}
		// v2: filters Todos · Falta HERO · Faltan piezas · Borradores, with their counts, over the whole catalog.
		self::$top = null;
		$page = max( 1, absint( $r->get_param( 'page' ) ) );
		$filter = sanitize_key( (string) $r->get_param( 'filter' ) );
		$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => 3000, 'fields' => 'ids',
			's' => sanitize_text_field( (string) $r->get_param( 'search' ) ), 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ) );
		$ids = array_map( 'intval', $q->posts );
		if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, false, true ); }
		$counts = array( 'nohero' => 0, 'piezas' => 0, 'draft' => 0 ); $keep = array();
		foreach ( $ids as $id ) {
			$piezas = self::piezas( $id ); $nohero = 'aprobada' !== $piezas['hero']['estado'];
			$faltan = ! $nohero && self::prioridades( $id ) && ( 'aprobada' !== $piezas['life']['estado'] || 'aprobada' !== $piezas['ben']['estado'] );
			$draft = 'publish' !== get_post_status( $id );
			$counts['nohero'] += (int) $nohero; $counts['piezas'] += (int) $faltan; $counts['draft'] += (int) $draft;
			if ( 'nohero' === $filter ? $nohero : ( 'piezas' === $filter ? $faltan : ( 'draft' === $filter ? $draft : true ) ) ) { $keep[] = $id; }
		}
		$items = array();
		foreach ( array_slice( $keep, ( $page - 1 ) * 20, 20 ) as $id ) { $p = wc_get_product( $id ); if ( $p ) { $items[] = self::projection( $p ); } }
		return rest_ensure_response( array( 'items' => $items, 'pages' => max( 1, (int) ceil( count( $keep ) / 20 ) ), 'counts' => $counts, 'gasto' => self::gasto() ) );
	}

	/** Accept content only. No price, stock, SKU, type, categories or commercial setters. */
	public static function save( WP_REST_Request $r ) {
		$id = absint( $r->get_param( 'id' ) );
		$name = self::clean_title( sanitize_text_field( (string) $r->get_param( 'name' ) ) ); // 3.14.145: «Mesa de oficina.» → «Mesa de oficina»
		$description = wp_kses_post( (string) $r->get_param( 'description' ) );
		$short = wp_kses_post( (string) $r->get_param( 'short' ) );
		if ( '' === trim( $name ) || mb_strlen( $name ) > 160 || mb_strlen( $description ) > 20000 || mb_strlen( $short ) > 2000 ) { return new WP_Error( 'content', 'Revisa el título y la longitud del texto.', array( 'status' => 422 ) ); }
		if ( class_exists( 'CVD_Fichas' ) && CVD_Fichas::frase_prohibida( $name . ' ' . $description . ' ' . $short ) ) { return new WP_Error( 'content', 'Revisa el contenido: contiene una nota interna.', array( 'status' => 422 ) ); }
		$p = $id ? wc_get_product( $id ) : new WC_Product_Simple();
		if ( ! $p || $p->is_type( 'variation' ) || ( $id && ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) ) { return new WP_Error( 'missing', 'Producto no encontrado.', array( 'status' => 404 ) ); }
		// Atomic per-product lock, shared with this panel's requests. Reject stale edits.
		$token = sanitize_key( (string) $r->get_param( 'token' ) );
		if ( ! $id && ! preg_match( '/^[a-z0-9-]{20,80}$/', $token ) ) { return new WP_Error( 'token', 'Vuelve a abrir el formulario.', array( 'status' => 400 ) ); }
		$lock = 'cvd_content_save_' . ( $id ?: get_current_user_id() . '_' . $token );
		if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', 'Este guardado ya se está procesando. Recarga el catálogo antes de reintentar.', array( 'status' => 409 ) ); }
		try {
			if ( $id ) { clean_post_cache( $id ); $p = wc_get_product( $id ); }
			if ( ! $id ) {
				$done = absint( get_user_meta( get_current_user_id(), '_cvd_content_token_' . $token, true ) );
				if ( $done ) { return rest_ensure_response( self::projection( wc_get_product( $done ) ) ); }
			} elseif ( ! hash_equals( self::version( $p ), (string) $r->get_param( 'version' ) ) ) { return new WP_Error( 'conflict', 'El producto cambió. Recárgalo antes de guardar para conservar los cambios del equipo.', array( 'status' => 409 ) ); }
			if ( ! $id ) { $p->set_status( 'draft' ); }
			$publish = 'publish' === $r->get_param( 'action' );
			if ( $publish && 'publish' !== $p->get_status() ) {
				// 3.14.145 (Ernesto 5-nov): a draft goes live only with photo, description, price AND stock (price and stock: Lennys).
				$faltan = array_values( array_diff( self::faltan( $p ), '' === trim( wp_strip_all_tags( $description ) ) ? array() : array( 'descripcion' ) ) );
				if ( $faltan ) { return new WP_Error( 'incomplete', 'Todavía no se puede publicar. Falta: ' . self::faltan_texto( $faltan ) . '. Guárdalo como borrador.', array( 'status' => 422, 'faltan' => $faltan ) ); }
			} elseif ( $publish && ( '' === $p->get_price() || ! $p->get_image_id() || '' === trim( wp_strip_all_tags( $description ) ) ) ) { return new WP_Error( 'incomplete', 'Para publicar necesita foto, descripción y un precio configurado en el catálogo comercial.', array( 'status' => 422 ) ); }
			if ( $id ) {
				$history = $p->get_meta( '_cvd_content_history' );
				$history = is_array( $history ) ? $history : array();
				$history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'before' => self::projection( $p ) );
				$p->update_meta_data( '_cvd_content_history', array_slice( $history, -10 ) );
			}
			$p->set_name( $name ); $p->set_description( $description );
			$brief = array(); $input_brief = (array) $r->get_param( 'brief' );
			foreach ( array( 'facts', 'buyer', 'objection', 'evidence', 'use', 'headline', 'offer' ) as $key ) {
				$brief[ $key ] = mb_substr( sanitize_textarea_field( (string) ( $input_brief[ $key ] ?? '' ) ), 0, 1000 );
			}
			// v2: the matrix (duda → resultado → prueba) is kept unless this save sends it.
			$stored = $id ? (array) $p->get_meta( '_cvd_content_brief' ) : array();
			foreach ( self::MATRIZ as $key ) {
				if ( array_key_exists( $key, $input_brief ) ) { $brief[ $key ] = mb_substr( sanitize_text_field( (string) $input_brief[ $key ] ), 0, 160 ); } elseif ( isset( $stored[ $key ] ) ) { $brief[ $key ] = $stored[ $key ]; }
			}
			$p->update_meta_data( '_cvd_content_brief', $brief );
			// Preserve the canonical notice for market products.
			if ( 0 !== strpos( (string) $p->get_sku(), 'MK-' ) ) { $p->set_short_description( $short ); }
			if ( $publish ) { $p->set_status( 'publish' ); }
			$p->update_meta_data( self::LOCK, get_current_user_id() );
			$p->update_meta_data( '_cvd_texto_fijo', gmdate( 'c' ) );
			if ( ! $id ) { $p->update_meta_data( '_cvd_content_local', '1' ); }
			// 3.14.145: an unpublished ficha goes into Lennys' 17:00 summary («ficha lista para revisar»); live ones leave it.
			if ( 'publish' === $p->get_status() ) { $p->delete_meta_data( self::READY ); } else { $p->update_meta_data( self::READY, gmdate( 'c' ) ); }
			$p->save();
			do_action( 'litespeed_purge_post', $p->get_id() ); clean_post_cache( $p->get_id() );
			if ( ! $id ) { update_user_meta( get_current_user_id(), '_cvd_content_token_' . $token, $p->get_id() ); }
			return rest_ensure_response( self::projection( $p ) );
		} finally { delete_option( $lock ); }
	}

	public static function upload( WP_REST_Request $r ) {
		$p = wc_get_product( absint( $r->get_param( 'id' ) ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) ) { return new WP_Error( 'missing', 'Primero guarda el producto.', array( 'status' => 404 ) ); }
		$lock = 'cvd_content_save_' . $p->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', 'Hay otro guardado en curso. Vuelve a intentarlo.', array( 'status' => 409 ) ); }
		try {
		clean_post_cache( $p->get_id() ); $p = wc_get_product( $p->get_id() );
		if ( ! hash_equals( self::version( $p ), (string) $r->get_param( 'version' ) ) ) { return new WP_Error( 'conflict', 'Recarga el producto: ha cambiado.', array( 'status' => 409 ) ); }
		$file = $r->get_file_params()['photo'] ?? array();
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 8 * MB_IN_BYTES || ! in_array( wp_get_image_mime( $file['tmp_name'] ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return new WP_Error( 'image', 'Elige una foto JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 422 ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// media_handle_upload reads the PHP upload array, supplied by the REST multipart request.
		$att = media_handle_upload( 'photo', $p->get_id(), array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
		if ( is_wp_error( $att ) ) { return $att; }
		update_post_meta( $att, 'ai' === $r->get_param( 'source' ) ? '_cvd_foto_ia' : '_cvd_foto_real', '1' );
		$gallery = array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() );
		$p->set_gallery_image_ids( array_values( array_unique( array_filter( array_map( 'intval', $gallery ) ) ) ) );
		$p->set_image_id( $att );
		$p->update_meta_data( self::LOCK, get_current_user_id() );
		if ( 'publish' !== $p->get_status() ) { $p->update_meta_data( self::READY, gmdate( 'c' ) ); } // 3.14.145: photo added → ready for review again
		$p->save();
		do_action( 'litespeed_purge_post', $p->get_id() ); clean_post_cache( $p->get_id() );
		// v2: an approved HERO option sent to the web is recorded as «HERO en la web».
		$opcion = sanitize_key( (string) $r->get_param( 'opcion' ) );
		if ( $opcion ) {
			$hero = self::piezas( $p->get_id() )['hero'];
			if ( 'aprobada' === $hero['estado'] && $opcion === $hero['opcion'] ) { self::poner_pieza( $p->get_id(), 'hero', array_merge( $hero, array( 'web' => (int) $att ) ) ); }
		}
		return rest_ensure_response( self::projection( $p ) );
		} finally { delete_option( $lock ); }
	}

	public static function page(): void {
		if ( '1' !== (string) ( $_GET['cvd_contenido'] ?? '' ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
		if ( ! self::allowed() ) { wp_die( 'Tu cuenta no tiene acceso al catálogo.', 'Casa Viva', array( 'response' => 403 ) ); }
		nocache_headers();
		wp_enqueue_style( 'cvd-content-studio', CVD_URL . 'assets/content-studio.css', array(), (string) filemtime( CVD_DIR . 'assets/content-studio.css' ) );
		wp_enqueue_script( 'cvd-product-background', CVD_URL . 'assets/product-background.js', array(), (string) filemtime( CVD_DIR . 'assets/product-background.js' ), true );
		wp_enqueue_script( 'cvd-content-studio', CVD_URL . 'assets/content-studio.js', array( 'cvd-product-background' ), (string) filemtime( CVD_DIR . 'assets/content-studio.js' ), true );
		wp_localize_script( 'cvd-content-studio', 'cvContent', array( 'providers' => CVD_Photo_AI::providers(), 'base' => rest_url( 'casa-viva/v1/contenido/' ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			// v2: motor (escalones disponibles y gasto de hoy) y bandeja de dudas, solo si existe su fuente de datos.
			'motor' => self::motor(), 'dudas' => null !== self::fuente() ) );
		// The theme's admin bar left an empty strip at the top of the panel (live test 5-nov).
		add_filter( 'show_admin_bar', '__return_false' );
		$x = '<svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
		$back = '<svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>';
		?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Mi catálogo · Casa Viva</title><?php wp_head(); ?></head><body class="cv-content"><main>
		<section id="cv-list-screen" aria-labelledby="cv-title">
			<header class="cv-brand"><div class="cv-brand-row"><img class="cv-logo" src="<?php echo esc_url( CVD_URL . 'assets/logo-casa-viva-crema.svg' ); ?>" alt="Casa Viva" width="117" height="24"><button type="button" class="cv-icon" id="cv-account" aria-label="Mi cuenta" aria-haspopup="dialog"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-4.2 14.4-4.2 16 0"/></svg></button></div><h1 id="cv-title">Mi catálogo y fotos</h1></header>
			<div class="cv-pad" id="cv-dudas-wrap" hidden><button type="button" id="cv-dudas-open" class="cv-inbox">Dudas del catálogo<span class="cv-count" id="cv-dudas-count"></span></button></div>
			<form id="cv-search" role="search"><label class="cv-searchbox"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg><input name="search" type="search" placeholder="Buscar producto" aria-label="Buscar un producto" autocomplete="off"></label></form>
			<div class="cv-filters" role="group" aria-label="Filtrar"><button type="button" data-filter="all" aria-pressed="true">Todos</button><button type="button" data-filter="nohero" aria-pressed="false">Falta HERO<span class="cv-n" id="cv-n-nohero"></span></button><button type="button" data-filter="piezas" aria-pressed="false">Faltan piezas<span class="cv-n" id="cv-n-piezas"></span></button><button type="button" data-filter="draft" aria-pressed="false">Borradores</button></div>
			<div id="cv-items"></div><button type="button" id="cv-more" class="cv-link cv-center" hidden>Ver más</button>
			<button type="button" id="cv-new" class="cv-fab"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Nueva mercancía</button>
		</section>
		<section id="cv-dudas-screen" aria-labelledby="cv-dudas-title" hidden>
			<header class="cv-brand cv-brand-sub"><div class="cv-brand-row"><button type="button" class="cv-icon" id="cv-dudas-back" aria-label="Volver al catálogo"><?php echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button><h1 id="cv-dudas-title">Dudas del catálogo</h1></div></header>
			<div class="cv-seg cv-seg3 cv-dtabs" id="cv-dudas-tabs" role="tablist" aria-label="Dudas"><button type="button" role="tab" data-d="pendiente" aria-selected="true">Por contestar <i></i></button><button type="button" role="tab" data-d="marcha" aria-selected="false">En marcha <i></i></button><button type="button" role="tab" data-d="hecha" aria-selected="false">Hechas <i></i></button></div>
			<div id="cv-dudas-body" class="cv-dbody" aria-live="polite"></div>
			<div class="cv-actionbar" id="cv-dudas-bar"></div>
		</section>
		<form id="cv-editor" hidden>
			<header class="cv-top"><button type="button" class="cv-icon" id="cv-back" aria-label="Volver a la lista"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button><h2 id="cv-heading"></h2><span class="cv-pill" id="cv-status"></span><button type="button" class="cv-icon" id="cv-menu" aria-label="Más opciones" aria-haspopup="dialog"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></button></header>
			<div class="cv-tabs" role="tablist" aria-label="Producto"><button type="button" role="tab" id="cv-tab-ficha" aria-controls="cv-panel-ficha" aria-selected="true">Ficha</button><button type="button" role="tab" id="cv-tab-fotos" aria-controls="cv-panel-fotos" aria-selected="false" tabindex="-1">Fotos<span class="cv-bd" id="cv-fotos-badge" hidden></span></button><button type="button" role="tab" id="cv-tab-anuncio" aria-controls="cv-panel-anuncio" aria-selected="false" tabindex="-1">Anuncio</button><button type="button" role="tab" id="cv-tab-res" aria-controls="cv-panel-res" aria-selected="false" tabindex="-1">Resultados<span id="cv-ads-count"></span></button></div>

			<section id="cv-panel-ficha" role="tabpanel" aria-labelledby="cv-tab-ficha">
				<div class="cv-photo"><img id="cv-original" alt="Foto principal del producto" hidden><span class="cv-hero-tag" id="cv-hero-tag" hidden></span><span class="cv-photo-empty" id="cv-photo-empty"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.6-2.5h6.8L17 8h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/></svg></span><label class="cv-photo-pick" for="cv-photo"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.6-2.5h6.8L17 8h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/></svg><span id="cv-photo-label">Cambiar foto</span></label><input id="cv-photo" class="cv-file" type="file" accept="image/jpeg,image/png,image/webp"></div>
				<button type="button" id="cv-upload" class="cv-btn cv-primary cv-wide" hidden><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7.5 8.5L12 4l4.5 4.5M5 20h14"/></svg>Subir a la web</button>
				<div class="cv-field"><div class="cv-lab"><label for="cv-name">Título</label><button type="button" class="cv-mini" id="cv-title-suggest" aria-label="Mejorar título"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Mejorar</button></div><input id="cv-name" maxlength="160" required placeholder="Nombre del producto"></div>
				<div class="cv-row"><span>Precio</span><b id="cv-commercial"></b><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-label="Lo pone la tienda" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div>
				<div class="cv-field"><div class="cv-lab"><label for="cv-description">Descripción</label><button type="button" class="cv-mini" id="cv-suggest" aria-label="Mejorar descripción"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Mejorar</button></div><div class="cv-tools" id="cv-tools"><button type="button" data-insert="bold"><b>N</b>egrita</button><button type="button" data-insert="point">• Punto</button><button type="button" data-insert="title">Subtítulo</button></div><p id="cv-text-note" class="cv-tag" hidden>Formato especial · se edita como código</p><textarea id="cv-description" rows="7"></textarea></div>
				<div id="cv-suggest-box" class="cv-suggest" data-nodirty hidden><label for="cv-suggestion">Sugerencia</label><textarea id="cv-suggestion" rows="5"></textarea><div class="cv-actions"><button type="button" id="cv-use-suggestion" class="cv-btn cv-primary cv-small">Usar</button><button type="button" id="cv-drop-suggestion" class="cv-btn cv-small">Descartar</button></div></div>
				<div class="cv-field"><div class="cv-lab"><label for="cv-short">Resumen</label></div><textarea id="cv-short" rows="2"></textarea></div>
				<fieldset class="cv-field cv-facts"><legend>Características</legend><?php for ( $i = 1; $i <= 3; $i++ ) : ?><div class="cv-fact" id="cv-fact-row-<?php echo (int) $i; ?>"><span class="cv-num" aria-hidden="true"><?php echo (int) $i; ?></span><input id="cv-fact-<?php echo (int) $i; ?>" maxlength="150" placeholder="Dato" aria-label="Característica <?php echo (int) $i; ?>"><input id="cv-benefit-<?php echo (int) $i; ?>" maxlength="150" placeholder="Para qué sirve" aria-label="Para qué sirve <?php echo (int) $i; ?>"></div><?php endfor; ?></fieldset>
				<div class="cv-field"><div class="cv-lab"><span>Reseñas</span><small id="cv-reviews-count"></small></div><div id="cv-reviews"></div><button type="button" class="cv-add" id="cv-review-open"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Añadir reseña real</button></div>
			</section>

			<section id="cv-panel-fotos" role="tabpanel" aria-labelledby="cv-tab-fotos" hidden>
				<div id="cv-pieces" class="cv-pzs" role="group" aria-label="Piezas del producto"></div>
				<p id="cv-pieces-note" class="cv-note2"></p>
				<div id="cv-foto-body"></div>
				<input id="cv-camera" class="cv-file" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" aria-label="Hacer foto del producto" data-nodirty>
			</section>

			<section id="cv-panel-anuncio" role="tabpanel" aria-labelledby="cv-tab-anuncio" hidden>
				<div class="cv-seg cv-seg3" id="cv-layout" role="radiogroup" aria-label="Tipo de anuncio"><button type="button" role="radio" data-value="ben" aria-checked="true">Beneficio</button><button type="button" role="radio" data-value="offer" aria-checked="false">Oferta</button><button type="button" role="radio" data-value="review" aria-checked="false">Reseña</button></div>
				<div class="cv-stage"><p id="cv-point-hint" class="cv-hint" hidden></p><div id="cv-pts" class="cv-pts" role="group" aria-label="Puntos del diagrama" hidden></div><canvas id="cv-canvas" width="1080" height="1920" hidden aria-label="Vista previa del anuncio"></canvas><p id="cv-preview-note" class="cv-note" hidden></p>
					<div class="cv-seg" id="cv-format" role="radiogroup" aria-label="Formato"><button type="button" role="radio" data-value="1920" aria-checked="true">Historia 9:16</button><button type="button" role="radio" data-value="1080" aria-checked="false">Post 1:1</button></div></div>
				<p class="cv-note2">Sin precio ni logo en la imagen: el precio va en el pie.</p>
				<h3 class="cv-sec">Foto</h3>
				<div class="cv-scroller cv-photos"><div class="cv-options" role="radiogroup" aria-label="Foto del anuncio"><button type="button" role="radio" class="cv-opt" id="cv-photo-own" aria-checked="true"><span class="cv-opt-pic"><img id="cv-own-thumb" alt="" hidden></span><span>Tu foto</span></button><span id="cv-sources"></span><span id="cv-variants"></span></div><button type="button" id="cv-generate" class="cv-opt cv-opt-add" aria-haspopup="dialog"><span class="cv-opt-pic"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg></span><span id="cv-generate-label">Fondo IA</span></button><label class="cv-opt cv-opt-add" for="cv-import-ai"><span class="cv-opt-pic"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7.5 8.5L12 4l4.5 4.5M5 20h14"/></svg></span><span>Del móvil</span></label><input id="cv-import-ai" class="cv-file" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Foto del móvil para el anuncio" data-nodirty></div>
				<p id="cv-ai-status" class="cv-status-line" aria-live="polite"></p>
				<div id="cv-option-panel" class="cv-option" hidden></div>
				<div class="cv-field" data-for="ben"><div class="cv-lab"><span>Duda → resultado → prueba</span><button type="button" class="cv-link" id="cv-ben-edit">Editar</button></div><div id="cv-ben-matrix" class="cv-mxr"></div></div>
				<div data-for="offer" hidden><div class="cv-row"><span>Precio · solo en el pie</span><b id="cv-ad-price"></b><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-label="Lo pone la tienda" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div><div class="cv-field"><div class="cv-lab"><label for="cv-offer">Oferta vigente</label><small>solo si es real</small></div><input id="cv-offer" maxlength="120" placeholder="Ej.: entrega gratis hasta el domingo"></div></div>
				<div class="cv-field" data-for="review" hidden><div class="cv-lab"><span>Reseña</span><button type="button" class="cv-link" id="cv-review-goto">Añadir</button></div><div id="cv-review-pick" role="radiogroup" aria-label="Reseña para la tarjeta"></div></div>
			</section>

			<section id="cv-panel-res" role="tabpanel" aria-labelledby="cv-tab-res" hidden><div id="cv-ads-total" class="cv-totals"></div><ul id="cv-ads"></ul></section>

			<div class="cv-actionbar" id="cv-actionbar"><div class="cv-fotobar" id="cv-fotobar" hidden></div><button type="submit" id="cv-save" class="cv-btn">Guardar cambios</button><button type="button" id="cv-publish" class="cv-btn cv-primary">Publicar</button><button type="button" id="cv-render" class="cv-btn cv-primary" hidden>Terminar anuncio</button></div>

			<div class="cv-sheet" id="cv-review-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-review-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-review-heading" tabindex="-1">Reseña real</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<label class="cv-label" for="cv-review-text">Comentario del cliente</label><textarea id="cv-review-text" maxlength="400" rows="3" placeholder="Pégalo tal cual del WhatsApp"></textarea>
				<label class="cv-label" for="cv-review-name">Nombre e inicial</label><input id="cv-review-name" maxlength="60" placeholder="María G., La Habana">
				<span class="cv-label" id="cv-review-stars-title">Estrellas</span><div class="cv-stars" id="cv-review-stars" role="group" aria-labelledby="cv-review-stars-title"><?php for ( $i = 1; $i <= 5; $i++ ) : ?><button type="button" data-stars="<?php echo (int) $i; ?>" aria-pressed="false" aria-label="<?php echo esc_attr( 1 === $i ? '1 estrella' : $i . ' estrellas' ); ?>">★</button><?php endfor; ?><small id="cv-review-stars-label">Sin estrellas</small></div><input type="hidden" id="cv-review-rating" value="0">
				<label class="cv-switch"><input type="checkbox" id="cv-review-permission"><span>El cliente dio permiso para publicar</span></label>
				<button type="button" id="cv-review-save" class="cv-btn cv-primary cv-wide">Guardar reseña</button></div>

			<div class="cv-sheet" id="cv-pie-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-pie-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-pie-heading" tabindex="-1">Pie del anuncio</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<p class="cv-note2">El precio va aquí, nunca en la imagen. Sin dato, la línea no se pone.</p>
				<div id="cv-proof" class="cv-proof"></div>
				<label class="cv-label" for="cv-copy"><span class="cv-sr">Pie del anuncio</span><span aria-hidden="true">Texto que se copia</span></label><textarea id="cv-copy" rows="6"></textarea>
				<p class="cv-wa"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 19.5l1.2-3.6A7.8 7.8 0 1112 19.8a7.7 7.7 0 01-3.8-1z"/></svg><span><b>Enlace de WhatsApp nuevo</b><br>Cuenta quién escribe y compra</span></p>
				<button type="button" id="cv-ad-link" class="cv-btn cv-primary cv-wide"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/></svg>Descargar imagen y copiar pie</button>
				<a id="cv-download" class="cv-link cv-center" download="casa-viva.jpg">Solo la imagen</a></div>

			<div class="cv-sheet" id="cv-menu-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-menu-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-menu-heading" tabindex="-1">Más opciones</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<div class="cv-menu"><a id="cv-view" target="_blank" rel="noopener" hidden><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>Ver en la tienda</a><button type="button" id="cv-copy-save"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/></svg>Descargar textos (.txt)</button><button type="button" id="cv-prio-toggle"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h3l6 4V6L7 10z"/><path d="M17 9a4 4 0 010 6"/></svg><span id="cv-prio-label">Va a anuncios: hacer las 3 piezas</span></button><button type="button" id="cv-prompt-open"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Instrucción para IA</button></div></div>

			<div class="cv-sheet" id="cv-prompt-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-prompt-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-prompt-heading" tabindex="-1">Instrucción para IA</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<label class="cv-sr" for="cv-prompt">Instrucción para IA</label><textarea id="cv-prompt" rows="8" readonly></textarea>
				<button type="button" id="cv-prompt-copy" class="cv-btn cv-primary cv-wide">Copiar instrucción</button>
				<button type="button" id="cv-copy-save-prompt" class="cv-btn cv-wide">Descargar textos con la instrucción (.txt)</button></div>

			<div class="cv-sheet" id="cv-ai-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-ai-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-ai-heading" tabindex="-1">Fondo con IA</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<div id="cv-provider-wrap"><label class="cv-label" for="cv-provider">Proveedor</label><select id="cv-provider"><option value="cloudflare">Gratis</option><option value="vps">OpenAI · tope común</option><option value="openai">OpenAI · de pago</option><option value="higgsfield">Higgsfield · de pago</option></select></div>
				<label class="cv-switch"><input type="checkbox" id="cv-protect-whole"><span>Foto entera, sin recortar</span></label>
				<button type="button" id="cv-ai-create" class="cv-btn cv-primary cv-wide">Crear opción</button></div>
		</form>

		<div class="cv-sheet" id="cv-gen-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-gen-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-gen-heading" tabindex="-1"></h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><?php echo $x; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></div><div id="cv-gen-body"></div></div>

		<div class="cv-sheet" id="cv-account-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-account-heading" hidden><div class="cv-sheet-head"><h2 id="cv-account-heading" tabindex="-1">Mi cuenta</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
			<div class="cv-menu"><button type="button" id="cv-password-open"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4M16 7l2 2"/></svg>Cambiar contraseña</button><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-4.2 14.4-4.2 16 0"/></svg>Mi cuenta</a><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>Salir</a></div>
			<form id="cv-password" hidden><label class="cv-label" for="cv-current-password">Contraseña actual</label><input id="cv-current-password" type="password" autocomplete="current-password" required><label class="cv-label" for="cv-next-password">Nueva (mínimo 12)</label><input id="cv-next-password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required><button class="cv-btn cv-primary cv-wide">Guardar contraseña y volver a entrar</button></form></div>
		<div id="cv-scrim" hidden></div>
		<p id="cv-message" role="status" aria-live="polite"></p>
		</main><?php wp_footer(); ?></body></html><?php
		exit;
	}
}
