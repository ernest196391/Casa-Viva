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
		// v2 (9/10-nov): crear (HERO/LIFESTYLE por escalones), pieza (3 estados), restaurar, matriz y prioridad.
		foreach ( array( 'products' => 'GET', 'save' => 'POST', 'upload' => 'POST', 'review' => 'POST', 'resena' => 'POST', 'anuncio' => 'POST', 'suggest' => 'POST', 'password' => 'POST',
			'crear' => 'POST', 'pieza' => 'POST', 'restaurar' => 'POST', 'matriz' => 'POST', 'prioridad' => 'POST' ) as $action => $method ) {
			register_rest_route( 'casa-viva/v1', '/contenido/' . $action, array(
				'methods' => $method, 'callback' => array( __CLASS__, $action ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			) );
		}
		// Bandeja «Dudas del catálogo»: 2 rutas del panel y 3 del VPS (class-cvd-dudas-catalogo.php).
		if ( class_exists( 'CVD_Dudas_Catalogo' ) ) { CVD_Dudas_Catalogo::routes(); }
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
	 * v2 (Ernesto 9-nov, «Apruebo, construye»; servidor terminado el 10-nov): 3 piezas por producto, prioridad, motor de
	 * imágenes por escalones, publicación automática del HERO (D152) y la bandeja (class-cvd-dudas-catalogo.php).
	 *
	 * Rutas del panel (casa-viva/v1/contenido/…, permiso allowed()):
	 *   GET  products   lista: filtro=hero|piezas|borradores|revisar, search, page · un producto: id.
	 *   POST crear      id, pieza=hero|life, escalon=auto|recorte|ia, base1/base2 (archivos) o base_id, forzar=1. Síncrona.
	 *   POST pieza      id, pieza=hero|life|ben, estado=aprobada|revision|rechazada, opcion, motivos[], web=1 · o pedir_foto=1, motivo.
	 *   POST restaurar  id → vuelve la foto principal de antes del HERO del panel.
	 *   POST matriz     id, version, duda, resultado, prueba, condicion, fuente, confirmada=1|0.
	 *   POST prioridad  id, anuncios=1|0.
	 * Filtros: cvd_content_hero_auto (true por defecto). cvd_content_recorte y cvd_content_motor no tienen ningún callback en
	 * producción: solo el entorno de prueba los usa para hacer de VPS.
	 * ---------------------------------------------------------------------------------------------- */
	public const PIEZAS = array( 'hero', 'life', 'ben' );
	public const ESTADOS = array( 'aprobada', 'revision', 'rechazada' );
	public const MOTIVOS = array( 'Color', 'Tamaño o forma', 'Piezas o etiqueta', 'Cortado', 'Fondo' );
	/** Subtope del panel en el VPS (USD por día). El recorte gratis no cuenta y nunca se bloquea. */
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
	/** Foto principal de antes del HERO del panel: array( image, gallery, att, opcion, auto, at ). Lo usa «restaurar». */
	public const HERO_ANTES = '_cvd_hero_antes';
	/** En el adjunto: es el HERO que puso el panel (valor = id del producto). */
	public const HERO_ADJUNTO = '_cvd_hero_panel';
	public const HERO_LADO = 1600;
	/** El producto ocupa ~80 % del lado mayor del HERO, centrado y con márgenes iguales. */
	public const HERO_OCUPA = 0.8;
	/** Datos confirmados en la bandeja: array( array( dato, valor, at, quien ) ). El VPS los copia a crm_knowledge. */
	public const DATOS_META = '_cvd_datos_confirmados';
	private const MATRIZ = array( 'duda', 'resultado', 'prueba', 'condicion', 'fuente', 'matriz_ok' );
	private const IMAGEN_LOCK = 'cvd_content_imagen';
	private static $top = null;

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	private static function brief( WC_Product $p ): array {
		$b = $p->get_meta( '_cvd_content_brief' );
		return is_array( $b ) ? $b : array();
	}

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

	/**
	 * HERO, LIFESTYLE and BENEFICIO. estado falta|revision|aprobada|rechazada; web = id of the HERO the panel put as main
	 * photo; auto = it was published automatically (D152) and Zayri has not looked at it yet. With $full: url (thumbnail)
	 * and sin_revisar (options of the piece without a state).
	 */
	public static function piezas( int $id, bool $full = true ): array {
		$raw = $id ? get_post_meta( $id, self::PIEZAS_META, true ) : array();
		$raw = is_array( $raw ) ? $raw : array();
		$ops = $full ? self::opciones( $id ) : array();
		$out = array();
		foreach ( self::PIEZAS as $k ) {
			$p = isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? $raw[ $k ] : array();
			$out[ $k ] = array( 'estado' => in_array( $p['estado'] ?? '', self::ESTADOS, true ) ? $p['estado'] : 'falta', 'opcion' => (string) ( $p['opcion'] ?? '' ),
				'escalon' => (string) ( $p['escalon'] ?? '' ), 'coste' => round( (float) ( $p['coste'] ?? 0 ), 2 ), 'motivos' => array_values( (array) ( $p['motivos'] ?? array() ) ),
				'web' => (int) ( $p['web'] ?? 0 ), 'auto' => ! empty( $p['auto'] ), 'at' => (string) ( $p['at'] ?? '' ) );
			if ( $full ) {
				$mias = array_filter( $ops, static fn( $o ) => $o['pieza'] === $k );
				$sel = array_values( array_filter( $mias, static fn( $o ) => $o['id'] === $out[ $k ]['opcion'] ) );
				$out[ $k ]['sin_revisar'] = count( array_filter( $mias, static fn( $o ) => '' === ( $o['estado'] ?? '' ) ) );
				$out[ $k ]['url'] = $out[ $k ]['web'] ? (string) wp_get_attachment_image_url( $out[ $k ]['web'], 'large' ) : (string) ( $sel[0]['url'] ?? '' );
			}
		}
		return $out;
	}

	private static function poner_pieza( int $id, string $pieza, array $data ): array {
		$raw = get_post_meta( $id, self::PIEZAS_META, true );
		$raw = is_array( $raw ) ? $raw : array();
		$cur = isset( $raw[ $pieza ] ) && is_array( $raw[ $pieza ] ) ? $raw[ $pieza ] : array();
		$new = array_merge( $cur, array_intersect_key( $data, array_flip( array( 'estado', 'opcion', 'escalon', 'coste', 'motivos', 'web', 'auto' ) ) ), array( 'at' => gmdate( 'c' ), 'por' => get_current_user_id() ) );
		if ( ! in_array( $new['estado'] ?? '', self::ESTADOS, true ) ) { $new['estado'] = ''; }
		$raw[ $pieza ] = $new;
		update_post_meta( $id, self::PIEZAS_META, $raw );
		return self::piezas( $id, false );
	}

	/** What the list needs for one product, cheap (no URLs): HERO on the web, missing pieces, published-not-reviewed. */
	private static function estado_lista( int $id, int $image ): array {
		$pz = self::piezas( $id, false ); $prios = self::prioridades( $id );
		$en_web = $pz['hero']['web'] > 0 && $pz['hero']['web'] === $image;
		$nohero = ! $en_web && 'aprobada' !== $pz['hero']['estado'];
		$faltan = $prios ? count( array_filter( array( 'life', 'ben' ), static fn( $k ) => 'aprobada' !== $pz[ $k ]['estado'] ) ) : 0;
		return array( 'piezas' => $pz, 'prios' => $prios, 'en_web' => $en_web, 'nohero' => $nohero, 'faltan' => $faltan,
			'revisar' => $en_web && $pz['hero']['auto'] && 'aprobada' !== $pz['hero']['estado'],
			'texto' => $nohero ? 'Falta HERO' : ( $faltan ? 'Faltan piezas de anuncio · ' . $faltan : '' ) );
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

	private static function opcion( int $id, string $pieza, string $opcion ): ?array {
		foreach ( self::opciones( $id, true ) as $o ) { if ( '' !== $opcion && $o['id'] === $opcion && $o['pieza'] === $pieza ) { return $o; } }
		return null;
	}

	private static function marcar_opcion( int $id, string $opcion, string $estado, array $motivos ): void {
		$all = self::opciones( $id, true );
		foreach ( $all as $i => $o ) { if ( $o['id'] === $opcion ) { $all[ $i ]['estado'] = $estado; $all[ $i ]['motivos'] = $motivos; } }
		update_post_meta( $id, self::OPCIONES_META, $all );
	}

	private static function ruta_opcion( array $o ): string {
		$file = (string) ( $o['file'] ?? '' );
		if ( 0 !== strpos( $file, '/cvd-opciones/' ) || false !== strpos( $file, '..' ) ) { return ''; }
		$up = wp_upload_dir();
		return empty( $up['error'] ) ? $up['basedir'] . $file : '';
	}

	/** Spend of the panel today (USD). The VPS does not return the real cost, so the panel keeps its own count. */
	public static function gasto(): array {
		$g = get_option( 'cvd_content_gasto', array() );
		$usd = is_array( $g ) && ( $g['dia'] ?? '' ) === wp_date( 'Y-m-d' ) ? (float) $g['usd'] : 0.0;
		return array( 'hoy' => round( $usd, 2 ), 'tope' => self::TOPE_DIA, 'ia' => self::COSTE_IA, 'restante' => round( max( 0, self::TOPE_DIA - $usd ), 2 ),
			'lleno' => $usd + self::COSTE_IA > self::TOPE_DIA + 1e-9 );
	}

	private static function sumar_gasto( float $usd ): void {
		$hoy = wp_date( 'Y-m-d' ); $g = get_option( 'cvd_content_gasto', array() );
		$cur = is_array( $g ) && ( $g['dia'] ?? '' ) === $hoy ? (float) $g['usd'] : 0.0;
		update_option( 'cvd_content_gasto', array( 'dia' => $hoy, 'usd' => round( $cur + $usd, 4 ) ), false );
	}

	/**
	 * Escalón 1, recorte GRATIS del VPS: CVD_Photo_AI::vps_recorte( $bytes ) → array( png, fiable, motivos, medidas ) | WP_Error.
	 * Only when the VPS key is configured. The filter lets the disposable test environment stand in for the VPS.
	 */
	public static function recorte(): ?callable {
		$fn = class_exists( 'CVD_Photo_AI' ) && method_exists( 'CVD_Photo_AI', 'vps_recorte' ) && ! empty( CVD_Photo_AI::providers()['vps'] ) ? array( 'CVD_Photo_AI', 'vps_recorte' ) : null;
		$fn = apply_filters( 'cvd_content_recorte', $fn );
		return is_callable( $fn ) ? $fn : null;
	}

	/** Escalón 2, IA de pago: CVD_Photo_AI::vps_image( $prompt, $opts, $refs ) → bytes | WP_Error (429 tope u ocupado, 502 no respondió). */
	public static function ia(): ?callable {
		$fn = class_exists( 'CVD_Photo_AI' ) && method_exists( 'CVD_Photo_AI', 'vps_image' ) && ! empty( CVD_Photo_AI::providers()['vps'] ) ? array( 'CVD_Photo_AI', 'vps_image' ) : null;
		$fn = apply_filters( 'cvd_content_motor', $fn );
		return is_callable( $fn ) ? $fn : null;
	}

	/** D152 (Ernesto 10-nov): a HERO that passes the automatic check goes to the web without waiting for Zayri. */
	public static function auto_activa( ?WC_Product $p = null ): bool {
		return (bool) apply_filters( 'cvd_content_hero_auto', true, $p );
	}

	/** What the phone needs to draw the ladder: which steps exist and today's spend. */
	public static function motor(): array {
		return array( 'recorte' => null !== self::recorte(), 'ia' => null !== self::ia(), 'gasto' => self::gasto(), 'max' => self::MAX_OPCIONES, 'motivos' => self::MOTIVOS, 'auto' => self::auto_activa() );
	}

	/** Product photos that can be the starting photo (main image first, then the gallery). */
	private static function fotos( WC_Product $p ): array {
		$out = array();
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'intval', array_merge( array( $p->get_image_id() ), (array) $p->get_gallery_image_ids() ) ) ) ) ), 0, 6 ) as $att ) {
			$url = (string) wp_get_attachment_image_url( $att, 'large' );
			if ( $url ) { $out[] = array( 'id' => $att, 'url' => $url, 'full' => (string) wp_get_attachment_image_url( $att, 'full' ), 'hero' => (bool) get_post_meta( $att, self::HERO_ADJUNTO, true ) ); }
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
		foreach ( array( 'duda', 'resultado', 'prueba', 'condicion', 'fuente' ) as $k ) { $m[ $k ] = self::txt( $r->get_param( $k ), 160 ); }
		if ( '' === $m['duda'] || '' === $m['resultado'] || '' === $m['prueba'] ) { return new WP_Error( 'matriz', 'Escribe la duda, el resultado y la prueba.', array( 'status' => 422 ) ); }
		if ( class_exists( 'CVD_Fichas' ) && CVD_Fichas::frase_prohibida( implode( ' ', $m ) ) ) { return new WP_Error( 'matriz', 'Revisa el contenido: contiene una nota interna.', array( 'status' => 422 ) ); }
		$lock = 'cvd_content_save_' . $p->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', 'Hay otro guardado en curso. Vuelve a intentarlo.', array( 'status' => 409 ) ); }
		try {
			clean_post_cache( $p->get_id() ); $p = wc_get_product( $p->get_id() );
			if ( ! hash_equals( self::version( $p ), (string) $r->get_param( 'version' ) ) ) { return new WP_Error( 'conflict', 'Recarga el producto: ha cambiado.', array( 'status' => 409 ) ); }
			// Only the matrix keys change: the rest of the brief (facts, buyer, offer…) is kept.
			$brief = array_merge( self::brief( $p ), $m, array( 'fuente' => $m['fuente'] ?: 'Ficha · características' ) );
			if ( '0' === (string) $r->get_param( 'confirmada' ) ) { unset( $brief['matriz_ok'] ); } else { $brief['matriz_ok'] = gmdate( 'c' ); }
			$p->update_meta_data( '_cvd_content_brief', $brief ); $p->save();
			return rest_ensure_response( self::fresh( $p ) );
		} finally { delete_option( $lock ); }
	}

	/**
	 * Aprobada · Revisión · Rechazada for one piece (and the option it refers to). Aprobada + web=1 puts a HERO on the web;
	 * Rechazada keeps what failed (for PREFERENCIAS) and, if it was the HERO on the web, the previous photo comes back;
	 * Revisión sends it to the inbox. pedir_foto=1 asks for a new real photo in the inbox, without spending.
	 */
	public static function pieza( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$id = $p->get_id(); $quien = (string) wp_get_current_user()->user_login;
		if ( '1' === (string) $r->get_param( 'pedir_foto' ) ) {
			if ( ! class_exists( 'CVD_Dudas_Catalogo' ) ) { return new WP_Error( 'dudas_off', 'La bandeja de dudas no está instalada.', array( 'status' => 422 ) ); }
			$d = CVD_Dudas_Catalogo::pedir_foto( $p, self::txt( $r->get_param( 'motivo' ), 120 ), $quien );
			if ( is_wp_error( $d ) ) { return $d; }
			return rest_ensure_response( array_merge( self::fresh( $p ), array( 'duda' => $d['id'] ) ) );
		}
		$pieza = sanitize_key( (string) $r->get_param( 'pieza' ) ); $estado = sanitize_key( (string) $r->get_param( 'estado' ) );
		if ( ! in_array( $pieza, self::PIEZAS, true ) || ! in_array( $estado, self::ESTADOS, true ) ) { return new WP_Error( 'pieza', 'Elige la pieza y su estado.', array( 'status' => 422 ) ); }
		if ( 'hero' !== $pieza && ! self::prioridades( $id ) ) { return new WP_Error( 'solo_top', 'Este producto lleva solo HERO. Márcalo «Va a anuncios» para hacer las 3 piezas.', array( 'status' => 422 ) ); }
		$motivos = array_values( array_intersect( self::MOTIVOS, array_map( 'strval', (array) $r->get_param( 'motivos' ) ) ) );
		$opcion = sanitize_key( (string) $r->get_param( 'opcion' ) );
		$o = self::opcion( $id, $pieza, $opcion );
		if ( 'ben' !== $pieza && ! $o ) { return new WP_Error( 'opcion', 'Esa opción ya no está. Recarga el producto.', array( 'status' => 404 ) ); }
		$extra = array();
		if ( ! $o ) {
			// BENEFICIO drawn on the phone: no saved option, only its state.
			self::poner_pieza( $id, 'ben', array( 'estado' => $estado, 'motivos' => $motivos ) );
		} elseif ( 'aprobada' === $estado ) {
			$hecho = self::aprobar_opcion( $p, $pieza, $opcion, '1' === (string) $r->get_param( 'web' ) );
			if ( is_wp_error( $hecho ) ) { return $hecho; }
			$extra['publicada'] = $hecho['web'] > 0;
		} elseif ( 'rechazada' === $estado ) {
			$hecho = self::rechazar_opcion( $p, $pieza, $opcion, $motivos );
			if ( is_wp_error( $hecho ) ) { return $hecho; }
			$extra['restaurada'] = $hecho['restaurada'];
			if ( $motivos && class_exists( 'CVD_Dudas_Catalogo' ) ) {
				CVD_Dudas_Catalogo::anotar( array( 'tipo' => 'pieza', 'accion' => 'rechazada', 'producto' => $id, 'nombre' => self::clean_title( $p->get_name() ), 'pieza' => $pieza,
					'opcion' => $opcion, 'escalon' => (string) $o['escalon'], 'motivos' => $motivos, 'foto' => (string) $o['url'], 'destino' => 'preferencias', 'quien' => $quien ) );
			}
		} else {
			self::marcar_opcion( $id, $opcion, 'revision', $motivos );
			self::poner_pieza( $id, $pieza, array( 'estado' => 'revision', 'opcion' => $opcion, 'escalon' => (string) $o['escalon'], 'coste' => (float) $o['coste'], 'motivos' => $motivos ) );
			if ( class_exists( 'CVD_Dudas_Catalogo' ) ) {
				$d = CVD_Dudas_Catalogo::desde_pieza( $p, $pieza, $o, $quien );
				if ( ! is_wp_error( $d ) ) { $extra['duda'] = $d['id']; }
			}
		}
		return rest_ensure_response( array_merge( self::fresh( $p ), $extra ) );
	}

	/** Approve an option. A HERO with $web goes to the web (unless it already is). → array( web => attachment id | 0 ). */
	public static function aprobar_opcion( WC_Product $p, string $pieza, string $opcion, bool $web ) {
		$id = $p->get_id(); $o = self::opcion( $id, $pieza, $opcion );
		if ( ! $o ) { return new WP_Error( 'opcion', 'Esa opción ya no está. Recarga el producto.', array( 'status' => 404 ) ); }
		self::marcar_opcion( $id, $opcion, 'aprobada', array() );
		$hero = self::piezas( $id, false )['hero'];
		$en_web = 'hero' === $pieza && $hero['web'] > 0 && $hero['opcion'] === $opcion && $hero['web'] === (int) get_post_meta( $id, '_thumbnail_id', true );
		if ( 'hero' === $pieza && $web && ! $en_web ) {
			$att = self::publicar_hero( $p, $o, false );
			return is_wp_error( $att ) ? $att : array( 'web' => $att );
		}
		$data = array( 'estado' => 'aprobada', 'opcion' => $opcion, 'escalon' => (string) $o['escalon'], 'coste' => (float) $o['coste'], 'motivos' => array(), 'auto' => false );
		if ( 'hero' === $pieza && ! $en_web ) { $data['web'] = 0; }
		self::poner_pieza( $id, $pieza, $data );
		return array( 'web' => $en_web ? $hero['web'] : 0 );
	}

	/** Reject an option; a rejected HERO that is on the web gives way to the photo it replaced. → array( restaurada ). */
	public static function rechazar_opcion( WC_Product $p, string $pieza, string $opcion, array $motivos ) {
		$id = $p->get_id(); $o = self::opcion( $id, $pieza, $opcion );
		if ( ! $o ) { return new WP_Error( 'opcion', 'Esa opción ya no está. Recarga el producto.', array( 'status' => 404 ) ); }
		self::marcar_opcion( $id, $opcion, 'rechazada', $motivos );
		$hero = self::piezas( $id, false )['hero']; $restaurada = false;
		if ( 'hero' === $pieza && $hero['web'] > 0 && $hero['opcion'] === $opcion ) { $restaurada = ! is_wp_error( self::volver( $p ) ); }
		$data = array( 'estado' => 'rechazada', 'opcion' => $opcion, 'escalon' => (string) $o['escalon'], 'coste' => (float) $o['coste'], 'motivos' => $motivos, 'auto' => false );
		if ( $restaurada ) { $data['web'] = 0; }
		self::poner_pieza( $id, $pieza, $data );
		return array( 'restaurada' => $restaurada );
	}

	/** «Volver a la foto anterior» in one touch. */
	public static function restaurar( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$done = self::volver( $p );
		if ( is_wp_error( $done ) ) { return $done; }
		return rest_ensure_response( array_merge( self::fresh( $p ), array( 'restaurada' => true ) ) );
	}

	/** Price, stock, SKU, URL and commission: a photo change must never touch them. */
	private static function comercial( WC_Product $p ): array {
		return array( (string) $p->get_price(), (string) $p->get_regular_price(), (string) $p->get_sale_price(), (string) $p->get_stock_quantity(), (string) $p->get_stock_status(),
			(string) $p->get_sku(), (string) $p->get_slug(), (string) $p->get_status(), (string) $p->get_meta( '_cvd_commission_type' ), (string) $p->get_meta( '_cvd_commission_value' ) );
	}

	private static function bloquear( int $id ) {
		$lock = 'cvd_content_save_' . $id;
		return add_option( $lock, time(), '', false ) ? $lock : new WP_Error( 'busy', 'Hay otro guardado en curso. Vuelve a intentarlo.', array( 'status' => 409 ) );
	}

	/**
	 * Puts a HERO option as the main photo: WebP 1600×1600 on pure white, no text. The photo it replaces stays in the gallery
	 * and in HERO_ANTES (to come back in one touch). Price, stock, SKU, URL and commission are checked before and after.
	 */
	public static function publicar_hero( WC_Product $p, array $o, bool $auto ) {
		$id = $p->get_id(); $ruta = self::ruta_opcion( $o );
		$bytes = '' !== $ruta && is_readable( $ruta ) ? (string) file_get_contents( $ruta ) : '';
		if ( '' === $bytes ) { return new WP_Error( 'opcion', 'Esa opción ya no está. Créala de nuevo.', array( 'status' => 404 ) ); }
		$web = self::hero_webp( $bytes );
		if ( is_wp_error( $web ) ) { return $web; }
		$lock = self::bloquear( $id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			clean_post_cache( $id ); $p = wc_get_product( $id ); $com = self::comercial( $p );
			$att = self::adjunto( $id, $web, self::clean_title( $p->get_name() ) );
			if ( is_wp_error( $att ) ) { return $att; }
			update_post_meta( $att, self::HERO_ADJUNTO, $id );
			update_post_meta( $att, 'ia' === ( $o['escalon'] ?? '' ) ? '_cvd_foto_ia' : '_cvd_foto_recorte', '1' );
			$old = (int) $p->get_image_id(); $gallery = array_values( array_map( 'intval', (array) $p->get_gallery_image_ids() ) );
			$prev = $p->get_meta( self::HERO_ANTES );
			// The first panel HERO keeps the photo it replaced; a later one keeps that same «antes».
			$antes = is_array( $prev ) && $old && (int) ( $prev['att'] ?? 0 ) === $old ? $prev : array( 'image' => $old, 'gallery' => $gallery );
			$nueva = $old && ! get_post_meta( $old, self::HERO_ADJUNTO, true ) ? array_merge( array( $old ), $gallery ) : $gallery;
			$p->set_gallery_image_ids( array_values( array_diff( array_unique( array_filter( $nueva ) ), array( $att ) ) ) );
			$p->set_image_id( $att );
			$p->update_meta_data( self::HERO_ANTES, array_merge( $antes, array( 'att' => $att, 'opcion' => (string) $o['id'], 'auto' => $auto, 'at' => gmdate( 'c' ) ) ) );
			$p->update_meta_data( self::LOCK, get_current_user_id() );
			if ( 'publish' !== $p->get_status() ) { $p->update_meta_data( self::READY, gmdate( 'c' ) ); }
			$p->save();
			clean_post_cache( $id );
			if ( self::comercial( wc_get_product( $id ) ) !== $com ) {
				$q = wc_get_product( $id ); $q->set_image_id( $old ); $q->set_gallery_image_ids( $gallery );
				if ( is_array( $prev ) ) { $q->update_meta_data( self::HERO_ANTES, $prev ); } else { $q->delete_meta_data( self::HERO_ANTES ); }
				$q->save();
				return new WP_Error( 'comercial', 'No se puso el HERO: habría cambiado precio, existencias, SKU o enlace.', array( 'status' => 500 ) );
			}
			do_action( 'litespeed_purge_post', $id );
		} finally { delete_option( $lock ); }
		self::poner_pieza( $id, 'hero', array( 'estado' => $auto ? '' : 'aprobada', 'opcion' => (string) $o['id'], 'escalon' => (string) ( $o['escalon'] ?? '' ),
			'coste' => (float) ( $o['coste'] ?? 0 ), 'motivos' => array(), 'web' => $att, 'auto' => $auto ) );
		return $att;
	}

	/** Back to the main photo from before the panel HERO. Only while that HERO is still the main photo. → previous image id. */
	private static function volver( WC_Product $p ) {
		$id = $p->get_id();
		$lock = self::bloquear( $id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			clean_post_cache( $id ); $p = wc_get_product( $id );
			$antes = $p->get_meta( self::HERO_ANTES );
			if ( ! is_array( $antes ) || empty( $antes['att'] ) ) { return new WP_Error( 'restaurar', 'No hay una foto anterior que poner.', array( 'status' => 404 ) ); }
			if ( (int) $p->get_image_id() !== (int) $antes['att'] ) { return new WP_Error( 'restaurar', 'La foto principal cambió después del HERO. No se toca.', array( 'status' => 409 ) ); }
			$com = self::comercial( $p );
			$p->set_image_id( (int) ( $antes['image'] ?? 0 ) );
			$p->set_gallery_image_ids( array_values( array_filter( array_map( 'intval', (array) ( $antes['gallery'] ?? array() ) ) ) ) );
			$p->delete_meta_data( self::HERO_ANTES );
			$p->save();
			clean_post_cache( $id );
			if ( self::comercial( wc_get_product( $id ) ) !== $com ) { return new WP_Error( 'comercial', 'Revisa el producto: cambió un dato de la tienda al restaurar.', array( 'status' => 500 ) ); }
			do_action( 'litespeed_purge_post', $id );
		} finally { delete_option( $lock ); }
		self::poner_pieza( $id, 'hero', array( 'web' => 0, 'auto' => false ) );
		return (int) ( $antes['image'] ?? 0 );
	}

	/** Media library attachment for a HERO (bytes already WebP or JPEG). */
	private static function adjunto( int $id, array $img, string $titulo ) {
		$up = wp_upload_bits( 'hero-' . $id . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false ) ) . '.' . $img['ext'], null, $img['bytes'] );
		if ( ! empty( $up['error'] ) ) { return new WP_Error( 'save', 'No se pudo guardar el HERO.', array( 'status' => 500 ) ); }
		$att = wp_insert_attachment( array( 'post_mime_type' => $img['mime'], 'post_title' => $titulo ?: 'HERO', 'post_content' => '', 'post_status' => 'inherit' ), $up['file'], $id, true );
		if ( is_wp_error( $att ) || ! $att ) { wp_delete_file( $up['file'] ); return new WP_Error( 'save', 'No se pudo guardar el HERO.', array( 'status' => 500 ) ); }
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) { wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) ); }
		update_post_meta( $att, '_wp_attachment_image_alt', $titulo );
		return (int) $att;
	}

	/** HERO for the web: exactly 1600×1600, pure white, as WebP (JPEG only if this server has no WebP). */
	public static function hero_webp( string $bytes ) {
		$src = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $src ) { return new WP_Error( 'motor', 'No se pudo abrir el HERO.', array( 'status' => 502 ) ); }
		$L = self::HERO_LADO; $out = imagecreatetruecolor( $L, $L );
		imagefilledrectangle( $out, 0, 0, $L - 1, $L - 1, imagecolorallocate( $out, 255, 255, 255 ) );
		$w = imagesx( $src ); $h = imagesy( $src ); $k = min( $L / $w, $L / $h );
		$dw = max( 1, (int) round( $w * $k ) ); $dh = max( 1, (int) round( $h * $k ) );
		imagecopyresampled( $out, $src, (int) floor( ( $L - $dw ) / 2 ), (int) floor( ( $L - $dh ) / 2 ), 0, 0, $dw, $dh, $w, $h );
		if ( function_exists( 'imagewebp' ) ) {
			ob_start(); imagewebp( $out, null, 90 ); $webp = (string) ob_get_clean();
			if ( '' !== $webp ) { return array( 'bytes' => $webp, 'mime' => 'image/webp', 'ext' => 'webp' ); }
		}
		ob_start(); imagejpeg( $out, null, 92 );
		return array( 'bytes' => (string) ob_get_clean(), 'mime' => 'image/jpeg', 'ext' => 'jpg' );
	}

	/** Uploaded starting photos (base1, base2): JPG, PNG or WebP. Without upload: base_id or the first real photo of the product. */
	private static function bases( WP_REST_Request $r, WC_Product $p ) {
		$out = array();
		foreach ( array( 'base1', 'base2' ) as $name ) {
			$file = $r->get_file_params()[ $name ] ?? array();
			if ( empty( $file['tmp_name'] ) ) { continue; }
			if ( ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 8 * MB_IN_BYTES ) { return new WP_Error( 'base', 'La foto de partida debe pesar hasta 8 MB.', array( 'status' => 422 ) ); }
			$base = self::base( (string) file_get_contents( $file['tmp_name'] ), 0, '' );
			if ( is_wp_error( $base ) ) { return $base; }
			$out[] = $base;
		}
		if ( $out ) { return $out; }
		$fotos = self::fotos( $p ); $att = absint( $r->get_param( 'base_id' ) );
		if ( ! in_array( $att, array_column( $fotos, 'id' ), true ) ) {
			$reales = array_values( array_filter( $fotos, static fn( $f ) => ! $f['hero'] ) );
			$att = (int) ( $reales[0]['id'] ?? 0 );
		}
		return $att ? self::base_adjunto( $att ) : new WP_Error( 'base', 'Elige la foto real de partida.', array( 'status' => 422 ) );
	}

	private static function base_adjunto( int $att ) {
		$file = (string) get_attached_file( $att );
		$base = self::base( '' !== $file && is_readable( $file ) ? (string) file_get_contents( $file ) : '', $att, (string) wp_get_attachment_image_url( $att, 'large' ) );
		return is_wp_error( $base ) ? $base : array( $base );
	}

	private static function base( string $bytes, int $att, string $url ) {
		$size = '' !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return new WP_Error( 'base', 'La foto de partida debe ser JPG, PNG o WebP.', array( 'status' => 422 ) ); }
		// vps_image takes up to 4 MB per photo.
		if ( strlen( $bytes ) > 4 * MB_IN_BYTES ) { $bytes = self::reducir( $bytes, 1536 ); if ( is_wp_error( $bytes ) ) { return $bytes; } }
		return array( 'bytes' => $bytes, 'w' => (int) $size[0], 'h' => (int) $size[1], 'att' => $att, 'url' => $url );
	}

	private static function reducir( string $bytes, int $max ) {
		$img = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $img ) { return new WP_Error( 'base', 'No se pudo abrir la foto de partida.', array( 'status' => 422 ) ); }
		$w = imagesx( $img ); $h = imagesy( $img ); $s = min( 1, $max / max( $w, $h ) );
		$out = imagecreatetruecolor( max( 1, (int) round( $w * $s ) ), max( 1, (int) round( $h * $s ) ) );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopyresampled( $out, $img, 0, 0, 0, 0, imagesx( $out ), imagesy( $out ), $w, $h );
		ob_start(); imagejpeg( $out, null, 90 ); return (string) ob_get_clean();
	}

	/**
	 * HERO on pure white, product ~80 % of the longest side, centered with equal margins, square. With $recorte the input is
	 * the transparent PNG of the free cut-out (the product is measured by its alpha); otherwise (IA) the near-white background
	 * that touches the edges becomes #FFFFFF and the product is measured on it. Returns JPEG bytes.
	 */
	public static function blanco( string $bytes, bool $recorte = false ) {
		$src = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $src ) { return new WP_Error( 'motor', 'El motor devolvió una imagen que no se pudo abrir.', array( 'status' => 502 ) ); }
		$w = imagesx( $src ); $h = imagesy( $src ); $s = min( 1, self::HERO_LADO / max( $w, $h ) );
		$W = max( 1, (int) round( $w * $s ) ); $H = max( 1, (int) round( $h * $s ) );
		$img = imagecreatetruecolor( $W, $H ); $white = imagecolorallocate( $img, 255, 255, 255 );
		imagefilledrectangle( $img, 0, 0, $W - 1, $H - 1, $white );
		$x0 = $W; $y0 = $H; $x1 = -1; $y1 = -1;
		if ( $recorte ) {
			$t = imagecreatetruecolor( $W, $H ); imagealphablending( $t, false ); imagesavealpha( $t, true );
			imagefilledrectangle( $t, 0, 0, $W - 1, $H - 1, imagecolorallocatealpha( $t, 255, 255, 255, 127 ) );
			imagecopyresampled( $t, $src, 0, 0, 0, 0, $W, $H, $w, $h );
			for ( $y = 0; $y < $H; $y++ ) {
				for ( $x = 0; $x < $W; $x++ ) {
					if ( ( ( imagecolorat( $t, $x, $y ) >> 24 ) & 0x7F ) < 110 ) { $x0 = min( $x0, $x ); $x1 = max( $x1, $x ); $y0 = min( $y0, $y ); $y1 = max( $y1, $y ); }
				}
			}
			imagealphablending( $img, true ); imagecopy( $img, $t, 0, 0, 0, 0, $W, $H );
		} else {
			imagealphablending( $img, true );
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
			for ( $y = 0; $y < $H; $y++ ) {
				for ( $x = 0; $x < $W; $x++ ) {
					if ( 0xFFFFFF !== ( imagecolorat( $img, $x, $y ) & 0xFFFFFF ) ) { $x0 = min( $x0, $x ); $x1 = max( $x1, $x ); $y0 = min( $y0, $y ); $y1 = max( $y1, $y ); }
				}
			}
		}
		if ( $x1 < 0 ) { return new WP_Error( 'motor', 'La imagen salió en blanco. Prueba con otra foto de partida.', array( 'status' => 502 ) ); }
		$bw = $x1 - $x0 + 1; $bh = $y1 - $y0 + 1;
		$side = (int) ceil( max( $bw, $bh ) / self::HERO_OCUPA ); $k = min( 1, self::HERO_LADO / $side ); $S = max( 1, (int) round( $side * $k ) );
		$pw = max( 1, (int) round( $bw * $k ) ); $ph = max( 1, (int) round( $bh * $k ) );
		$out = imagecreatetruecolor( $S, $S ); imagefilledrectangle( $out, 0, 0, $S - 1, $S - 1, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopyresampled( $out, $img, (int) floor( ( $S - $pw ) / 2 ), (int) floor( ( $S - $ph ) / 2 ), $x0, $y0, $pw, $ph, $bw, $bh );
		ob_start(); imagejpeg( $out, null, 92 ); $jpg = (string) ob_get_clean();
		// Touching the frame means the generated product may be cut: the panel says «Toca el borde». A cut-out is cropped to the product.
		return array( 'bytes' => $jpg, 'cortado' => ! $recorte && ( 0 === $x0 || 0 === $y0 || $W - 1 === $x1 || $H - 1 === $y1 ), 'lado' => $S );
	}

	/** Keeps an image as an option of the piece (private file under uploads/cvd-opciones). At most 3 per piece. */
	private static function guardar_opcion( int $id, array $o, string $jpg ) {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		$sub = '/cvd-opciones/' . $id; $dir = $up['basedir'] . $sub;
		if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); }
		$name = $o['pieza'] . '-' . strtolower( wp_generate_password( 24, false ) ) . '.jpg';
		if ( false === file_put_contents( $dir . '/' . $name, $jpg ) ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		$o = array_merge( array( 'escalon' => '', 'coste' => 0.0 ), $o, array( 'id' => strtolower( wp_generate_password( 12, false ) ), 'url' => $up['baseurl'] . $sub . '/' . $name,
			'file' => $sub . '/' . $name, 'at' => gmdate( 'c' ), 'por' => get_current_user_id(), 'estado' => '', 'motivos' => array() ) );
		$all = self::opciones( $id, true ); $all[] = $o;
		// Max. 3 per piece: the oldest ones go, unless a piece, or the photo of the web, still points to them.
		$usados = array_filter( array_column( self::piezas( $id, false ), 'opcion' ) );
		$antes = get_post_meta( $id, self::HERO_ANTES, true );
		if ( is_array( $antes ) && ! empty( $antes['opcion'] ) ) { $usados[] = (string) $antes['opcion']; }
		$sobran = count( array_filter( $all, static fn( $x ) => $x['pieza'] === $o['pieza'] ) ) - self::MAX_OPCIONES;
		foreach ( $all as $i => $x ) {
			if ( $sobran <= 0 ) { break; }
			if ( $x['pieza'] !== $o['pieza'] || $x['id'] === $o['id'] || in_array( $x['id'], $usados, true ) ) { continue; }
			if ( ! empty( $x['file'] ) && 0 === strpos( (string) $x['file'], '/cvd-opciones/' ) && false === strpos( (string) $x['file'], '..' ) ) { wp_delete_file( $up['basedir'] . $x['file'] ); }
			unset( $all[ $i ] ); $sobran--;
		}
		update_post_meta( $id, self::OPCIONES_META, array_values( $all ) );
		unset( $o['file'] );
		return $o;
	}

	/** A photo from the inbox (made by the VPS) kept as an option of the product, on white if it is a HERO. */
	public static function opcion_desde_archivo( WC_Product $p, string $pieza, string $ruta, string $escalon, float $coste ) {
		$bytes = '' !== $ruta && is_readable( $ruta ) ? (string) file_get_contents( $ruta ) : '';
		if ( '' === $bytes ) { return new WP_Error( 'foto', 'La foto de la duda ya no está.', array( 'status' => 404 ) ); }
		$made = 'hero' === $pieza ? self::blanco( $bytes ) : self::reducir( $bytes, self::HERO_LADO );
		if ( is_wp_error( $made ) ) { return $made; }
		return self::guardar_opcion( $p->get_id(), array( 'pieza' => $pieza, 'escalon' => sanitize_key( $escalon ) ?: 'vps', 'coste' => round( $coste, 2 ), 'cortado' => is_array( $made ) && $made['cortado'] ), is_array( $made ) ? $made['bytes'] : $made );
	}

	private static function fallo( string $code, string $msg, int $status, array $escalones ): WP_Error {
		return new WP_Error( $code, $msg, array( 'status' => $status, 'escalones' => $escalones, 'gasto' => self::gasto() ) );
	}

	private static function prompt( WC_Product $p, string $pieza ): string {
		$name = self::clean_title( $p->get_name() ); $b = self::brief( $p );
		if ( 'hero' === $pieza ) {
			return "E-commerce catalog photo of exactly the product in the reference photo(s): {$name}. Keep its exact shape, proportions, colors, materials, labels, parts and accessories; do not add, remove or redesign anything. Whole product visible, centered, with generous margin on every side, nothing cut by the frame. Pure white seamless background (#FFFFFF), soft even studio light, very soft contact shadow only. No text, no logo, no price, no props, no hands.";
		}
		$quien = trim( (string) ( $b['buyer'] ?? '' ) ); $uso = trim( (string) ( $b['use'] ?? '' ) );
		return "Lifestyle photo for an online store: one adult person" . ( $quien ? ' (' . mb_substr( $quien, 0, 120 ) . ')' : '' ) . " at a warm, simple Cuban home using exactly the product in the reference photo(s) ({$name})"
			. ( $uso ? ' for ' . mb_substr( $uso, 0, 120 ) : '' ) . ', answering the doubt «' . ( $b['duda'] ?? '' ) . '» and living this result: ' . ( $b['resultado'] ?? '' )
			. '. Keep the product exact: shape, proportions, colors, labels and parts unchanged, at real scale. Natural light, realistic, calm emotion. No text, no logo, no price, no watermark.';
	}

	/**
	 * The ladder. HERO: 1) free cut-out (if fiable, composed on white; nothing is spent) → 2) paid IA (gpt-image-1.5, calidad
	 * media, fidelidad alta, the real photos as base, 1536×1024 when the product is wide) and composed on white.
	 * LIFESTYLE: paid IA only. → array( bytes, escalon, coste, cortado, ancho, escalones ) | WP_Error with data.escalones.
	 */
	private static function escalera( WC_Product $p, string $pieza, string $modo, array $bases ) {
		$escalones = array(); $ancho = $bases[0]['w'] >= 1.25 * $bases[0]['h'];
		if ( 'hero' === $pieza && 'ia' !== $modo ) {
			$recorte = self::recorte();
			if ( ! $recorte ) {
				if ( 'recorte' === $modo ) { return self::fallo( 'sin_recorte', 'El recorte gratis aún no está disponible.', 422, $escalones ); }
				$escalones[] = array( 'escalon' => 'recorte', 'ok' => false, 'motivo' => 'No disponible', 'coste' => 0.0, 'segundos' => 0 );
			} else {
				$t = microtime( true ); $out = call_user_func( $recorte, $bases[0]['bytes'] ); $seg = (int) round( microtime( true ) - $t );
				if ( is_wp_error( $out ) && 429 === (int) ( $out->get_error_data()['status'] ?? 0 ) ) {
					// Busy: wait instead of paying for what the free step will do in a minute.
					$escalones[] = array( 'escalon' => 'recorte', 'ok' => false, 'motivo' => $out->get_error_message(), 'coste' => 0.0, 'segundos' => $seg );
					return self::fallo( 'ocupado', 'Recorte gratis ocupado: ' . $out->get_error_message(), 429, $escalones );
				}
				$png = is_array( $out ) ? (string) ( $out['png'] ?? '' ) : ( is_string( $out ) ? $out : '' );
				$motivo = '';
				if ( is_wp_error( $out ) ) { $motivo = $out->get_error_message(); }
				elseif ( '' === $png ) { $motivo = 'No devolvió imagen.'; }
				elseif ( is_array( $out ) && empty( $out['fiable'] ) ) { $motivo = implode( ' · ', array_map( static fn( $m ) => self::txt( $m, 80 ), (array) ( $out['motivos'] ?? array() ) ) ) ?: 'El recorte no es fiable.'; }
				$size = '' !== $png ? @getimagesizefromstring( $png ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $size ) { $ancho = $size[0] >= 1.25 * $size[1]; }
				if ( '' === $motivo ) {
					$made = self::blanco( $png, true );
					if ( ! is_wp_error( $made ) ) {
						$escalones[] = array( 'escalon' => 'recorte', 'ok' => true, 'motivo' => '', 'coste' => 0.0, 'segundos' => $seg );
						return array( 'bytes' => $made['bytes'], 'escalon' => 'recorte', 'coste' => 0.0, 'cortado' => false, 'ancho' => $ancho, 'escalones' => $escalones );
					}
					$motivo = $made->get_error_message();
				}
				$escalones[] = array( 'escalon' => 'recorte', 'ok' => false, 'motivo' => $motivo, 'coste' => 0.0, 'segundos' => $seg );
				if ( 'recorte' === $modo ) { return self::fallo( 'recorte', 'Recorte gratis: ' . $motivo, 422, $escalones ); }
			}
		}
		$antes = $escalones ? 'Recorte gratis: ' . $escalones[0]['motivo'] . '. ' : '';
		$ia = self::ia();
		if ( ! $ia ) { return self::fallo( 'motor', $antes . 'La IA de pago no está configurada.', 422, $escalones ); }
		if ( self::gasto()['lleno'] ) { return self::fallo( 'tope', $antes . 'Límite de hoy alcanzado (0,50 USD). Mañana se puede seguir; el recorte gratis sigue disponible.', 429, $escalones ); }
		$tam = $ancho ? '1536x1024' : '1024x1536';
		$t = microtime( true );
		$bytes = call_user_func( $ia, self::prompt( $p, $pieza ), array( 'calidad' => 'media', 'tam' => $tam, 'modelo' => 'gpt-image-1.5', 'fidelidad' => 'alta' ), array_slice( array_column( $bases, 'bytes' ), 0, 2 ) );
		$seg = (int) round( microtime( true ) - $t );
		if ( is_wp_error( $bytes ) || ! is_string( $bytes ) || '' === $bytes ) {
			$e = is_wp_error( $bytes ) ? $bytes : new WP_Error( 'motor', 'El servidor de imágenes no devolvió imagen.', array( 'status' => 502 ) );
			$status = (int) ( $e->get_error_data()['status'] ?? 502 );
			$escalones[] = array( 'escalon' => 'ia', 'ok' => false, 'motivo' => $e->get_error_message(), 'coste' => 0.0, 'segundos' => $seg );
			return self::fallo( 'motor', $antes . 'IA de pago: ' . $e->get_error_message(), in_array( $status, array( 400, 422, 429 ), true ) ? $status : 502, $escalones );
		}
		self::sumar_gasto( self::COSTE_IA );
		$made = 'hero' === $pieza ? self::blanco( $bytes ) : self::reducir( $bytes, self::HERO_LADO );
		if ( is_wp_error( $made ) ) {
			$escalones[] = array( 'escalon' => 'ia', 'ok' => false, 'motivo' => $made->get_error_message(), 'coste' => self::COSTE_IA, 'segundos' => $seg );
			return self::fallo( 'motor', $made->get_error_message(), 502, $escalones );
		}
		$escalones[] = array( 'escalon' => 'ia', 'ok' => true, 'motivo' => '', 'coste' => self::COSTE_IA, 'segundos' => $seg, 'tam' => $tam );
		return array( 'bytes' => is_array( $made ) ? $made['bytes'] : $made, 'escalon' => 'ia', 'coste' => self::COSTE_IA, 'cortado' => is_array( $made ) && $made['cortado'], 'ancho' => $ancho, 'escalones' => $escalones );
	}

	/** One image at a time for the whole panel; the result is kept as an option and a good HERO goes to the web (D152). */
	private static function generar( WC_Product $p, string $pieza, string $modo, array $bases ) {
		$id = $p->get_id();
		// A lock older than 400 s is a crashed request (cut-out and IA wait up to 150 s each).
		if ( ! add_option( self::IMAGEN_LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::IMAGEN_LOCK ) < 400 ) { return self::fallo( 'ocupado', 'Otra foto en marcha. Espera a que termine.', 429, array() ); }
			delete_option( self::IMAGEN_LOCK );
			if ( ! add_option( self::IMAGEN_LOCK, time(), '', false ) ) { return self::fallo( 'ocupado', 'Otra foto en marcha. Espera a que termine.', 429, array() ); }
		}
		try {
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 400 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$t0 = microtime( true );
			$hecho = self::escalera( $p, $pieza, $modo, $bases );
			if ( is_wp_error( $hecho ) ) { return $hecho; }
			$fallos = array_values( array_filter( $hecho['escalones'], static fn( $e ) => ! $e['ok'] ) );
			$opcion = self::guardar_opcion( $id, array( 'pieza' => $pieza, 'escalon' => $hecho['escalon'], 'coste' => $hecho['coste'], 'ancho' => $hecho['ancho'], 'cortado' => $hecho['cortado'],
				'fallo' => implode( ' · ', array_column( $fallos, 'motivo' ) ), 'escalones' => $hecho['escalones'], 'antes' => (string) ( $bases[0]['url'] ?? '' ),
				'base' => (int) ( $bases[0]['att'] ?? 0 ), 'segundos' => (int) round( microtime( true ) - $t0 ) ), $hecho['bytes'] );
			if ( is_wp_error( $opcion ) ) { return $opcion; }
		} finally { delete_option( self::IMAGEN_LOCK ); }
		$publicada = false; $aviso = 'Opción lista para comparar.';
		if ( 'hero' === $pieza ) {
			$hero = self::piezas( $id, false )['hero'];
			if ( 'aprobada' === $hero['estado'] && $hero['web'] ) { $aviso = 'La web tiene el HERO que aprobaste: compara la nueva antes de cambiarla.'; }
			elseif ( $hecho['cortado'] ) { $aviso = 'Toca el borde: revísala antes de ponerla en la web.'; }
			elseif ( ! self::auto_activa( $p ) ) { $aviso = 'Apruébala para ponerla en la web.'; }
			else {
				$att = self::publicar_hero( $p, (array) self::opcion( $id, 'hero', $opcion['id'] ), true );
				if ( is_wp_error( $att ) ) { $aviso = 'No se puso en la web: ' . $att->get_error_message(); }
				else { $publicada = true; $aviso = 'HERO puesto en la web. Si no te gusta, recházalo y vuelve la foto anterior.'; }
			}
		}
		return array( 'opcion' => $opcion, 'opciones' => self::opciones( $id ), 'escalones' => $hecho['escalones'], 'escalon' => $hecho['escalon'], 'coste' => $hecho['coste'],
			'gasto' => self::gasto(), 'publicada' => $publicada, 'aviso' => $aviso );
	}

	/**
	 * Crear una pieza, síncrona (recorte ~7–25 s, IA 20–60 s; Core espera hasta 150 s cada uno). escalon: auto (HERO: recorte
	 * y, si falla, IA), recorte (solo el gratis) o ia (salta el recorte; LIFESTYLE siempre). Una imagen a la vez.
	 */
	public static function crear( WP_REST_Request $r ) {
		$p = self::editable( $r->get_param( 'id' ) );
		if ( is_wp_error( $p ) ) { return $p; }
		$id = $p->get_id(); $pieza = sanitize_key( (string) $r->get_param( 'pieza' ) );
		$modo = sanitize_key( (string) $r->get_param( 'escalon' ) ) ?: ( 'life' === $pieza ? 'ia' : 'auto' );
		if ( ! in_array( $pieza, array( 'hero', 'life' ), true ) || ! in_array( $modo, array( 'auto', 'recorte', 'ia' ), true ) || ( 'life' === $pieza && 'ia' !== $modo ) ) { return new WP_Error( 'pieza', 'Elige la pieza y el escalón.', array( 'status' => 422 ) ); }
		if ( 'life' === $pieza ) {
			if ( ! self::prioridades( $id ) ) { return new WP_Error( 'solo_top', 'Este producto lleva solo HERO. Márcalo «Va a anuncios» para hacer las 3 piezas.', array( 'status' => 422 ) ); }
			if ( empty( self::brief( $p )['matriz_ok'] ) ) { return new WP_Error( 'matriz', 'Primero confirma duda → resultado → prueba.', array( 'status' => 422 ) ); }
		}
		$hechas = count( array_filter( self::opciones( $id ), static fn( $o ) => $o['pieza'] === $pieza ) );
		if ( $hechas >= self::MAX_OPCIONES && '1' !== (string) $r->get_param( 'forzar' ) ) { return new WP_Error( 'opciones', 'Ya hay ' . $hechas . ' opciones. Elige una o confirma hacer otra.', array( 'status' => 409, 'opciones' => $hechas ) ); }
		if ( 'recorte' === $modo && ! self::recorte() ) { return new WP_Error( 'sin_recorte', 'El recorte gratis aún no está disponible.', array( 'status' => 422 ) ); }
		if ( 'ia' === $modo ) {
			if ( ! self::ia() ) { return new WP_Error( 'motor', 'Pide al administrador que configure el servidor de imágenes.', array( 'status' => 422 ) ); }
			if ( self::gasto()['lleno'] ) { return new WP_Error( 'tope', 'Límite de hoy alcanzado (0,50 USD). Mañana se puede seguir; el recorte gratis sigue disponible.', array( 'status' => 429, 'gasto' => self::gasto() ) ); }
		}
		$bases = self::bases( $r, $p );
		if ( is_wp_error( $bases ) ) { return $bases; }
		$out = self::generar( $p, $pieza, $modo, $bases );
		if ( is_wp_error( $out ) ) { return $out; }
		$out['producto'] = self::fresh( $p );
		return rest_ensure_response( $out );
	}

	/** «Pedir foto» answered: HERO from the new real photo, same ladder and same automatic publication. */
	public static function hero_desde_adjunto( WC_Product $p, int $att ) {
		$bases = self::base_adjunto( $att );
		return is_wp_error( $bases ) ? $bases : self::generar( $p, 'hero', 'auto', $bases );
	}

	/** A new real photo for the product (inbox: corregir / pedir foto). It goes to the gallery, or is the main photo if there is none. */
	public static function foto_real( WC_Product $p, array $file ) {
		$ext = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ (string) ( $file['type'] ?? '' ) ] ?? '';
		if ( '' === $ext ) { return new WP_Error( 'foto', 'Elige una foto JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 422 ) ); }
		foreach ( array( 'file', 'media', 'image' ) as $inc ) { if ( file_exists( ABSPATH . 'wp-admin/includes/' . $inc . '.php' ) ) { require_once ABSPATH . 'wp-admin/includes/' . $inc . '.php'; } }
		$id = $p->get_id();
		$att = media_handle_sideload( array( 'name' => 'foto-real-' . $id . '.' . $ext, 'tmp_name' => $file['tmp_name'] ), $id );
		if ( is_wp_error( $att ) ) { return $att; }
		update_post_meta( $att, '_cvd_foto_real', '1' );
		$lock = self::bloquear( $id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			clean_post_cache( $id ); $p = wc_get_product( $id );
			if ( ! $p->get_image_id() ) { $p->set_image_id( $att ); } else { $p->set_gallery_image_ids( array_values( array_unique( array_merge( array_map( 'intval', (array) $p->get_gallery_image_ids() ), array( (int) $att ) ) ) ) ); }
			$p->update_meta_data( self::LOCK, get_current_user_id() );
			if ( 'publish' !== $p->get_status() ) { $p->update_meta_data( self::READY, gmdate( 'c' ) ); }
			$p->save();
			do_action( 'litespeed_purge_post', $id ); clean_post_cache( $id );
		} finally { delete_option( $lock ); }
		return (int) $att;
	}

	/** A fact confirmed in the inbox, kept on the product (the VPS also writes it to crm_knowledge). */
	public static function dato_confirmado( WC_Product $p, string $dato, string $valor, string $quien ): void {
		$all = get_post_meta( $p->get_id(), self::DATOS_META, true );
		$all = array_values( array_filter( is_array( $all ) ? $all : array(), static fn( $x ) => is_array( $x ) && ( $x['dato'] ?? '' ) !== $dato ) );
		$all[] = array( 'dato' => self::txt( $dato, 160 ), 'valor' => self::txt( $valor, 160 ), 'at' => gmdate( 'c' ), 'quien' => self::txt( $quien, 60 ) );
		update_post_meta( $p->get_id(), self::DATOS_META, array_slice( $all, -40 ) );
	}

	/** A text approved in the inbox goes to the ficha with a copy «antes» in the history, as a save does. → the text before. */
	public static function texto_ficha( WC_Product $p, string $campo, string $texto ) {
		$campo = in_array( $campo, array( 'name', 'description', 'short' ), true ) ? $campo : 'description';
		// wp_kses_post keeps <strong>/<b> (preguntas frecuentes en negrita).
		$texto = 'name' === $campo ? self::clean_title( sanitize_text_field( $texto ) ) : wp_kses_post( $texto );
		if ( '' === trim( wp_strip_all_tags( $texto ) ) || mb_strlen( $texto ) > ( 'name' === $campo ? 160 : ( 'short' === $campo ? 2000 : 20000 ) ) ) { return new WP_Error( 'content', 'Revisa la longitud del texto.', array( 'status' => 422 ) ); }
		if ( class_exists( 'CVD_Fichas' ) && CVD_Fichas::frase_prohibida( $texto ) ) { return new WP_Error( 'content', 'Revisa el contenido: contiene una nota interna.', array( 'status' => 422 ) ); }
		$id = $p->get_id(); $lock = self::bloquear( $id );
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			clean_post_cache( $id ); $p = wc_get_product( $id );
			if ( 'short' === $campo && 0 === strpos( (string) $p->get_sku(), 'MK-' ) ) { return new WP_Error( 'content', 'El resumen de este producto lo pone el mercado.', array( 'status' => 422 ) ); }
			$antes = 'name' === $campo ? $p->get_name() : ( 'short' === $campo ? $p->get_short_description() : $p->get_description() );
			$history = $p->get_meta( '_cvd_content_history' ); $history = is_array( $history ) ? $history : array();
			$history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'before' => self::projection( $p ), 'origen' => 'dudas' );
			$p->update_meta_data( '_cvd_content_history', array_slice( $history, -10 ) );
			if ( 'name' === $campo ) { $p->set_name( $texto ); } elseif ( 'short' === $campo ) { $p->set_short_description( $texto ); } else { $p->set_description( $texto ); }
			$p->update_meta_data( self::LOCK, get_current_user_id() );
			$p->update_meta_data( '_cvd_texto_fijo', gmdate( 'c' ) );
			$p->save();
			do_action( 'litespeed_purge_post', $id ); clean_post_cache( $id );
			return (string) $antes;
		} finally { delete_option( $lock ); }
	}

	private static function projection( WC_Product $p ): array {
		$faltan = self::faltan( $p );
		$id = (int) $p->get_id(); $lista = self::estado_lista( $id, (int) $p->get_image_id() );
		$antes = $p->get_meta( self::HERO_ANTES );
		return array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'description' => $p->get_description(),
			// v2: prioridad (top · nuevo · anuncios), las 3 piezas, opciones guardadas, fotos de partida y lo que falta de fotos.
			'prioridad' => $lista['prios'], 'prio' => $lista['prios'][0] ?? '', 'prios' => $lista['prios'], 'piezas' => self::piezas( $id ), 'opciones' => self::opciones( $id ), 'fotos' => self::fotos( $p ),
			'falta_hero' => $lista['nohero'], 'faltan_piezas' => $lista['faltan'], 'falta_fotos' => $lista['texto'], 'hero_en_web' => $lista['en_web'], 'hero_revisar' => $lista['revisar'],
			'restaurable' => is_array( $antes ) && (int) ( $antes['att'] ?? 0 ) === (int) $p->get_image_id() && (int) $p->get_image_id() > 0,
			'short' => $p->get_short_description(), 'status' => $p->get_status(),
			'price' => '' === $p->get_price() ? '' : ( $p->is_type( 'variable' ) ? 'Desde ' : '' ) . wp_strip_all_tags( wc_price( $p->get_price() ) ), 'url' => $p->get_permalink(),
			'image' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ),
			'image_full' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'full' ),
			// A real store sale on a simple product, for the offer card. Variable products show their «Desde» price only.
			'sale' => $p->is_type( 'simple' ) && $p->is_on_sale() ? array( 'regular' => wp_strip_all_tags( wc_price( $p->get_regular_price() ) ), 'price' => wp_strip_all_tags( wc_price( $p->get_sale_price() ) ),
				'until' => $p->get_date_on_sale_to() ? $p->get_date_on_sale_to()->date( 'Y-m-d' ) : '' ) : null,
			'image_id' => (int) $p->get_image_id(), 'gallery' => $p->get_gallery_image_ids(),
			'brief' => self::brief( $p ), 'reviews' => self::reviews( $p->get_id() ),
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
		// v2: filtro Todos · Falta HERO · Faltan piezas · Borradores · Por revisar (HERO puesto solo), con sus cuentas, en todo el catálogo.
		self::$top = null;
		$page = max( 1, absint( $r->get_param( 'page' ) ) );
		$alias = array( 'hero' => 'nohero', 'nohero' => 'nohero', 'piezas' => 'piezas', 'borradores' => 'draft', 'draft' => 'draft', 'revisar' => 'revisar' );
		$filter = $alias[ sanitize_key( (string) ( $r->get_param( 'filtro' ) ?? $r->get_param( 'filter' ) ) ) ] ?? 'all';
		$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => 3000, 'fields' => 'ids',
			's' => sanitize_text_field( (string) $r->get_param( 'search' ) ), 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ) );
		$ids = array_map( 'intval', $q->posts );
		if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, false, true ); }
		$counts = array( 'nohero' => 0, 'piezas' => 0, 'draft' => 0, 'revisar' => 0 ); $keep = array();
		foreach ( $ids as $id ) {
			$l = self::estado_lista( $id, (int) get_post_meta( $id, '_thumbnail_id', true ) );
			$is = array( 'nohero' => $l['nohero'], 'piezas' => ! $l['nohero'] && $l['faltan'] > 0, 'draft' => 'publish' !== get_post_status( $id ), 'revisar' => $l['revisar'] );
			foreach ( $is as $k => $v ) { $counts[ $k ] += (int) $v; }
			if ( 'all' === $filter || $is[ $filter ] ) { $keep[] = $id; }
		}
		$items = array();
		foreach ( array_slice( $keep, ( $page - 1 ) * 20, 20 ) as $id ) { $p = wc_get_product( $id ); if ( $p ) { $items[] = self::projection( $p ); } }
		return rest_ensure_response( array( 'items' => $items, 'pages' => max( 1, (int) ceil( count( $keep ) / 20 ) ), 'counts' => $counts, 'filtro' => $filter, 'gasto' => self::gasto() ) );
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
			if ( 'aprobada' === $hero['estado'] && $opcion === $hero['opcion'] ) { self::poner_pieza( $p->get_id(), 'hero', array( 'web' => (int) $att, 'auto' => false ) ); }
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
			'motor' => self::motor(), 'dudas' => class_exists( 'CVD_Dudas_Catalogo' ) && CVD_Dudas_Catalogo::activa() ) );
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
