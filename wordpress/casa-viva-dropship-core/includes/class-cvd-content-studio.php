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
		foreach ( array( 'products' => 'GET', 'save' => 'POST', 'upload' => 'POST', 'review' => 'POST', 'resena' => 'POST', 'anuncio' => 'POST', 'suggest' => 'POST', 'password' => 'POST' ) as $action => $method ) {
			register_rest_route( 'casa-viva/v1', '/contenido/' . $action, array(
				'methods' => $method, 'callback' => array( __CLASS__, $action ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			) );
		}
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

	private static function projection( WC_Product $p ): array {
		$faltan = self::faltan( $p );
		return array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'description' => $p->get_description(),
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
		$page = max( 1, absint( $r->get_param( 'page' ) ) );
		$q = wc_get_products( array( 'limit' => 20, 'page' => $page, 'paginate' => true,
			'status' => array( 'publish', 'draft', 'pending', 'private' ),
			's' => sanitize_text_field( (string) $r->get_param( 'search' ) ), 'orderby' => 'modified', 'order' => 'DESC' ) );
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'projection' ), $q->products ), 'pages' => $q->max_num_pages ) );
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
		wp_localize_script( 'cvd-content-studio', 'cvContent', array( 'providers' => CVD_Photo_AI::providers(), 'base' => rest_url( 'casa-viva/v1/contenido/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Mi catálogo · Casa Viva</title><?php wp_head(); ?></head><body class="cv-content"><main>
		<section id="cv-list-screen" aria-labelledby="cv-title">
			<header class="cv-brand"><div class="cv-brand-row"><img class="cv-logo" src="<?php echo esc_url( CVD_URL . 'assets/logo-casa-viva-crema.svg' ); ?>" alt="Casa Viva" width="117" height="24"><button type="button" class="cv-icon" id="cv-account" aria-label="Mi cuenta" aria-haspopup="dialog"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-4.2 14.4-4.2 16 0"/></svg></button></div><h1 id="cv-title">Mi catálogo y fotos</h1></header>
			<form id="cv-search" role="search"><label class="cv-searchbox"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg><input name="search" type="search" placeholder="Buscar producto" aria-label="Buscar un producto" autocomplete="off"></label></form>
			<div class="cv-filters" role="group" aria-label="Filtrar"><button type="button" data-filter="all" aria-pressed="true">Todos</button><button type="button" data-filter="publish" aria-pressed="false">Publicados</button><button type="button" data-filter="draft" aria-pressed="false">Borradores</button></div>
			<div id="cv-items"></div><button type="button" id="cv-more" class="cv-link cv-center" hidden>Ver más</button>
			<button type="button" id="cv-new" class="cv-fab"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Nueva mercancía</button>
		</section>
		<form id="cv-editor" hidden>
			<header class="cv-top"><button type="button" class="cv-icon" id="cv-back" aria-label="Volver a la lista"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button><h2 id="cv-heading"></h2><span class="cv-pill" id="cv-status"></span><button type="button" class="cv-icon" id="cv-menu" aria-label="Más opciones" aria-haspopup="dialog"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></button></header>
			<div class="cv-tabs" role="tablist" aria-label="Producto"><button type="button" role="tab" id="cv-tab-ficha" aria-controls="cv-panel-ficha" aria-selected="true">Ficha</button><button type="button" role="tab" id="cv-tab-anuncio" aria-controls="cv-panel-anuncio" aria-selected="false" tabindex="-1">Anuncio</button><button type="button" role="tab" id="cv-tab-res" aria-controls="cv-panel-res" aria-selected="false" tabindex="-1">Resultados<span id="cv-ads-count"></span></button></div>

			<section id="cv-panel-ficha" role="tabpanel" aria-labelledby="cv-tab-ficha">
				<div class="cv-photo"><img id="cv-original" alt="Foto principal del producto" hidden><span class="cv-photo-empty" id="cv-photo-empty"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.6-2.5h6.8L17 8h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/></svg></span><label class="cv-photo-pick" for="cv-photo"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.6-2.5h6.8L17 8h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/></svg><span id="cv-photo-label">Cambiar foto</span></label><input id="cv-photo" class="cv-file" type="file" accept="image/jpeg,image/png,image/webp"></div>
				<button type="button" id="cv-upload" class="cv-btn cv-primary cv-wide" hidden><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7.5 8.5L12 4l4.5 4.5M5 20h14"/></svg>Subir a la web</button>
				<div class="cv-field"><div class="cv-lab"><label for="cv-name">Título</label><button type="button" class="cv-mini" id="cv-title-suggest" aria-label="Mejorar título"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Mejorar</button></div><input id="cv-name" maxlength="160" required placeholder="Nombre del producto"></div>
				<div class="cv-row"><span>Precio</span><b id="cv-commercial"></b><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-label="Lo pone la tienda" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div>
				<div class="cv-field"><div class="cv-lab"><label for="cv-description">Descripción</label><button type="button" class="cv-mini" id="cv-suggest" aria-label="Mejorar descripción"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Mejorar</button></div><div class="cv-tools" id="cv-tools"><button type="button" data-insert="point">• Punto</button><button type="button" data-insert="title">Subtítulo</button></div><p id="cv-text-note" class="cv-tag" hidden>Formato especial · se edita como código</p><textarea id="cv-description" rows="7"></textarea></div>
				<div id="cv-suggest-box" class="cv-suggest" data-nodirty hidden><label for="cv-suggestion">Sugerencia</label><textarea id="cv-suggestion" rows="5"></textarea><div class="cv-actions"><button type="button" id="cv-use-suggestion" class="cv-btn cv-primary cv-small">Usar</button><button type="button" id="cv-drop-suggestion" class="cv-btn cv-small">Descartar</button></div></div>
				<div class="cv-field"><div class="cv-lab"><label for="cv-short">Resumen</label></div><textarea id="cv-short" rows="2"></textarea></div>
				<fieldset class="cv-field cv-facts"><legend>Características</legend><?php for ( $i = 1; $i <= 3; $i++ ) : ?><div class="cv-fact" id="cv-fact-row-<?php echo (int) $i; ?>"><span class="cv-num" aria-hidden="true"><?php echo (int) $i; ?></span><input id="cv-fact-<?php echo (int) $i; ?>" maxlength="150" placeholder="Dato" aria-label="Característica <?php echo (int) $i; ?>"><input id="cv-benefit-<?php echo (int) $i; ?>" maxlength="150" placeholder="Para qué sirve" aria-label="Para qué sirve <?php echo (int) $i; ?>"></div><?php endfor; ?></fieldset>
				<div class="cv-field"><div class="cv-lab"><span>Reseñas</span><small id="cv-reviews-count"></small></div><div id="cv-reviews"></div><button type="button" class="cv-add" id="cv-review-open"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Añadir reseña real</button></div>
			</section>

			<section id="cv-panel-anuncio" role="tabpanel" aria-labelledby="cv-tab-anuncio" hidden>
				<div class="cv-stage"><p id="cv-point-hint" class="cv-hint" hidden></p><canvas id="cv-canvas" width="1080" height="1920" hidden aria-label="Vista previa del anuncio"></canvas><p id="cv-preview-note" class="cv-note" hidden></p>
					<div class="cv-seg" id="cv-format" role="radiogroup" aria-label="Formato"><button type="button" role="radio" data-value="1920" aria-checked="true">Historia 9:16</button><button type="button" role="radio" data-value="1080" aria-checked="false">Post 1:1</button></div></div>
				<h3 class="cv-sec">Plantilla</h3>
				<div class="cv-scroller" id="cv-layout" role="radiogroup" aria-label="Plantilla">
					<?php
					$minis = array(
						'features' => array( 'Características', '<rect x="6" y="18" width="44" height="30" fill="#fff"/><circle cx="20" cy="30" r="3" fill="#173e35"/><circle cx="36" cy="38" r="3" fill="#173e35"/><path d="M20 30H3v26h7M36 38h17v12h-8" stroke="#173e35" stroke-width="1" fill="none"/><g fill="#173e35"><circle cx="13" cy="56" r="2.5"/><rect x="18" y="54.5" width="26" height="3"/><circle cx="13" cy="64" r="2.5"/><rect x="18" y="62.5" width="22" height="3"/></g>' ),
						'spec'     => array( 'Ficha técnica', '<rect x="6" y="18" width="44" height="30" fill="#fff"/><g fill="#173e35"><rect x="6" y="54" width="34" height="3"/><rect x="6" y="61" width="30" height="3"/><rect x="6" y="68" width="36" height="3"/></g>' ),
						'offer'    => array( 'Oferta', '<rect x="6" y="18" width="44" height="30" fill="#fff"/><rect x="6" y="53" width="28" height="7" fill="#173e35"/><rect x="6" y="64" width="44" height="7" fill="#e4eee8"/>' ),
						'use'      => array( 'Uso diario', '<rect x="6" y="18" width="44" height="30" fill="#fff"/><g fill="#173e35"><rect x="6" y="55" width="44" height="3.5"/><rect x="6" y="62" width="32" height="3.5"/></g>' ),
						'review'   => array( 'Reseña', '<rect x="6" y="18" width="44" height="26" fill="#fff"/><path d="M8 50h4v6H8zM14 50h4v6h-4z" fill="#173e35"/><rect x="6" y="60" width="40" height="3" fill="#173e35"/><g fill="#c99a1e"><circle cx="9" cy="69" r="2"/><circle cx="15" cy="69" r="2"/><circle cx="21" cy="69" r="2"/><circle cx="27" cy="69" r="2"/><circle cx="33" cy="69" r="2"/></g>' ),
					);
					foreach ( $minis as $value => $mini ) {
						// The drawings are fixed markup written above, not user data.
						echo '<button type="button" role="radio" class="cv-tpl" data-value="' . esc_attr( $value ) . '" aria-checked="' . ( 'features' === $value ? 'true' : 'false' ) . '"><span class="cv-tpl-pic"><svg viewBox="0 0 56 88" width="56" height="88" aria-hidden="true"><rect x="6" y="6" width="20" height="3" fill="#173e35"/><rect x="6" y="11" width="34" height="4" fill="#173e35"/>' . $mini[1] . '<rect x="6" y="78" width="44" height="5" fill="#173e35"/></svg></span>' . esc_html( $mini[0] ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
				<h3 class="cv-sec">Foto</h3>
				<div class="cv-scroller cv-photos"><div class="cv-options" role="radiogroup" aria-label="Foto del anuncio"><button type="button" role="radio" class="cv-opt" id="cv-photo-own" aria-checked="true"><span class="cv-opt-pic"><img id="cv-own-thumb" alt="" hidden></span><span>Tu foto</span></button><span id="cv-variants"></span></div><button type="button" id="cv-generate" class="cv-opt cv-opt-add" aria-haspopup="dialog"><span class="cv-opt-pic"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg></span><span id="cv-generate-label">Fondo IA</span></button><label class="cv-opt cv-opt-add" for="cv-import-ai"><span class="cv-opt-pic"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7.5 8.5L12 4l4.5 4.5M5 20h14"/></svg></span><span>Del móvil</span></label><input id="cv-import-ai" class="cv-file" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Foto del móvil para el anuncio" data-nodirty></div>
				<p id="cv-ai-status" class="cv-status-line" aria-live="polite"></p>
				<div id="cv-option-panel" class="cv-option" hidden></div>
				<div class="cv-field"><div class="cv-lab"><label for="cv-headline">Frase</label></div><input id="cv-headline" maxlength="90" placeholder="Qué hace útil este producto"></div>
				<div class="cv-field" data-for="features"><div class="cv-lab"><span>Puntos en la foto</span><button type="button" class="cv-link" data-goto="ficha">Editar</button></div><div id="cv-points" class="cv-points"></div></div>
				<div class="cv-field" data-for="spec" hidden><div class="cv-lab"><span>Características de la ficha</span><button type="button" class="cv-link" data-goto="ficha">Editar</button></div></div>
				<div data-for="offer" hidden><div class="cv-row"><span>Precio</span><b id="cv-ad-price"></b><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-label="Lo pone la tienda" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div><div class="cv-field"><div class="cv-lab"><label for="cv-offer">Oferta vigente</label><small>solo si es real</small></div><input id="cv-offer" maxlength="120" placeholder="Ej.: entrega gratis hasta el domingo"></div></div>
				<div class="cv-field" data-for="use" hidden><div class="cv-lab"><label for="cv-use">Uso diario</label></div><input id="cv-use" maxlength="150" placeholder="Dónde y para qué se usa"></div>
				<div class="cv-field" data-for="review" hidden><div class="cv-lab"><span>Reseña</span><button type="button" class="cv-link" id="cv-review-goto">Añadir</button></div><div id="cv-review-pick" role="radiogroup" aria-label="Reseña para la tarjeta"></div></div>
			</section>

			<section id="cv-panel-res" role="tabpanel" aria-labelledby="cv-tab-res" hidden><div id="cv-ads-total" class="cv-totals"></div><ul id="cv-ads"></ul></section>

			<div class="cv-actionbar" id="cv-actionbar"><button type="submit" id="cv-save" class="cv-btn">Guardar cambios</button><button type="button" id="cv-publish" class="cv-btn cv-primary">Publicar</button><button type="button" id="cv-render" class="cv-btn cv-primary" hidden>Terminar anuncio</button></div>

			<div class="cv-sheet" id="cv-review-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-review-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-review-heading" tabindex="-1">Reseña real</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<label class="cv-label" for="cv-review-text">Comentario del cliente</label><textarea id="cv-review-text" maxlength="400" rows="3" placeholder="Pégalo tal cual del WhatsApp"></textarea>
				<label class="cv-label" for="cv-review-name">Nombre e inicial</label><input id="cv-review-name" maxlength="60" placeholder="María G., La Habana">
				<span class="cv-label" id="cv-review-stars-title">Estrellas</span><div class="cv-stars" id="cv-review-stars" role="group" aria-labelledby="cv-review-stars-title"><?php for ( $i = 1; $i <= 5; $i++ ) : ?><button type="button" data-stars="<?php echo (int) $i; ?>" aria-pressed="false" aria-label="<?php echo esc_attr( 1 === $i ? '1 estrella' : $i . ' estrellas' ); ?>">★</button><?php endfor; ?><small id="cv-review-stars-label">Sin estrellas</small></div><input type="hidden" id="cv-review-rating" value="0">
				<label class="cv-switch"><input type="checkbox" id="cv-review-permission"><span>El cliente dio permiso para publicar</span></label>
				<button type="button" id="cv-review-save" class="cv-btn cv-primary cv-wide">Guardar reseña</button></div>

			<div class="cv-sheet" id="cv-pie-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-pie-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-pie-heading" tabindex="-1">Pie del anuncio</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<label class="cv-sr" for="cv-copy">Pie del anuncio</label><textarea id="cv-copy" rows="8"></textarea>
				<p class="cv-wa"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 19.5l1.2-3.6A7.8 7.8 0 1112 19.8a7.7 7.7 0 01-3.8-1z"/></svg><span><b>Enlace de WhatsApp nuevo</b><br>Cuenta quién escribe y compra</span></p>
				<button type="button" id="cv-ad-link" class="cv-btn cv-primary cv-wide"><svg class="cv-ic cv-sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/></svg>Descargar imagen y copiar pie</button>
				<a id="cv-download" class="cv-link cv-center" download="casa-viva.jpg">Solo la imagen</a></div>

			<div class="cv-sheet" id="cv-menu-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-menu-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-menu-heading" tabindex="-1">Más opciones</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<div class="cv-menu"><a id="cv-view" target="_blank" rel="noopener" hidden><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>Ver en la tienda</a><button type="button" id="cv-copy-save"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/></svg>Descargar textos (.txt)</button><button type="button" id="cv-prompt-open"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.8 4.8 1.7-4.8 1.7L12 16.5l-1.7-4.8L5.5 10l4.8-1.7z"/></svg>Instrucción para IA</button></div></div>

			<div class="cv-sheet" id="cv-prompt-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-prompt-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-prompt-heading" tabindex="-1">Instrucción para IA</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<label class="cv-sr" for="cv-prompt">Instrucción para IA</label><textarea id="cv-prompt" rows="8" readonly></textarea>
				<button type="button" id="cv-prompt-copy" class="cv-btn cv-primary cv-wide">Copiar instrucción</button>
				<button type="button" id="cv-copy-save-prompt" class="cv-btn cv-wide">Descargar textos con la instrucción (.txt)</button></div>

			<div class="cv-sheet" id="cv-ai-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-ai-heading" data-nodirty hidden><div class="cv-sheet-head"><h2 id="cv-ai-heading" tabindex="-1">Fondo con IA</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
				<div id="cv-provider-wrap"><label class="cv-label" for="cv-provider">Proveedor</label><select id="cv-provider"><option value="cloudflare">Gratis</option><option value="vps">OpenAI · tope común</option><option value="openai">OpenAI · de pago</option><option value="higgsfield">Higgsfield · de pago</option></select></div>
				<label class="cv-switch"><input type="checkbox" id="cv-protect-whole"><span>Foto entera, sin recortar</span></label>
				<button type="button" id="cv-ai-create" class="cv-btn cv-primary cv-wide">Crear opción</button></div>
		</form>

		<div class="cv-sheet" id="cv-account-sheet" role="dialog" aria-modal="true" aria-labelledby="cv-account-heading" hidden><div class="cv-sheet-head"><h2 id="cv-account-heading" tabindex="-1">Mi cuenta</h2><button type="button" class="cv-icon" data-close aria-label="Cerrar"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
			<div class="cv-menu"><button type="button" id="cv-password-open"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4M16 7l2 2"/></svg>Cambiar contraseña</button><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-4.2 14.4-4.2 16 0"/></svg>Mi cuenta</a><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><svg class="cv-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>Salir</a></div>
			<form id="cv-password" hidden><label class="cv-label" for="cv-current-password">Contraseña actual</label><input id="cv-current-password" type="password" autocomplete="current-password" required><label class="cv-label" for="cv-next-password">Nueva (mínimo 12)</label><input id="cv-next-password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required><button class="cv-btn cv-primary cv-wide">Guardar contraseña y volver a entrar</button></form></div>
		<div id="cv-scrim" hidden></div>
		<p id="cv-message" role="status" aria-live="polite"></p>
		</main><?php wp_footer(); ?></body></html><?php
		exit;
	}
}
