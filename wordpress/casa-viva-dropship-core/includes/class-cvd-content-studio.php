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
		foreach ( array( 'products' => 'GET', 'save' => 'POST', 'upload' => 'POST', 'review' => 'POST', 'suggest' => 'POST', 'password' => 'POST' ) as $action => $method ) {
			register_rest_route( 'casa-viva/v1', '/contenido/' . $action, array(
				'methods' => $method, 'callback' => array( __CLASS__, $action ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			) );
		}
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

	private static function projection( WC_Product $p ): array {
		return array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'description' => $p->get_description(),
			'short' => $p->get_short_description(), 'status' => $p->get_status(),
			'price' => '' === $p->get_price() ? '' : ( $p->is_type( 'variable' ) ? 'Desde ' : '' ) . wp_strip_all_tags( wc_price( $p->get_price() ) ), 'url' => $p->get_permalink(),
			'image' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ),
			'image_id' => (int) $p->get_image_id(), 'gallery' => $p->get_gallery_image_ids(),
			'brief' => (array) $p->get_meta( '_cvd_content_brief' ),
			'version' => self::version( $p ), 'protected' => (bool) $p->get_meta( self::LOCK ) );
	}

	private static function version( WC_Product $p ): string {
		return hash( 'sha256', wp_json_encode( array( $p->get_name(), $p->get_description(), $p->get_short_description(), $p->get_image_id(), $p->get_gallery_image_ids(), $p->get_status(), $p->get_meta( '_cvd_content_brief' ) ) ) );
	}

	public static function review( WP_REST_Request $r ) {
		$p = wc_get_product( absint( $r['id'] ) );
		if ( ! $p || $p->is_type( 'variation' ) || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) || ! hash_equals( self::version( $p ), (string) $r['version'] ) ) { return new WP_Error( 'conflict', 'Abre de nuevo el producto antes de guardar la opción.', array( 'status' => 409 ) ); }
		$file = $r->get_file_params()['photo'] ?? array();
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || filesize( $file['tmp_name'] ) > 3 * MB_IN_BYTES ) { return new WP_Error( 'image', 'La opción es demasiado grande.', array( 'status' => 422 ) ); }
		$bytes = file_get_contents( $file['tmp_name'] );
		$dim = @getimagesizefromstring( $bytes );
		if ( ! $dim || 'image/png' !== $dim['mime'] || $dim[0] > 1920 || $dim[1] > 1920 ) { return new WP_Error( 'image', 'La opción no es válida.', array( 'status' => 422 ) ); }
		$key = '_cvd_content_review_' . $p->get_id();
		$value = array( 'version' => self::version( $p ), 'image' => 'data:image/png;base64,' . base64_encode( $bytes ) );
		if ( false === update_user_meta( get_current_user_id(), $key, $value ) && get_user_meta( get_current_user_id(), $key, true ) !== $value ) { return new WP_Error( 'save', 'No se pudo guardar la opción.', array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function products( WP_REST_Request $r ) {
		$id = absint( $r->get_param( 'id' ) );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! in_array( $p->get_status(), array( 'publish', 'draft', 'pending', 'private' ), true ) || $p->is_type( 'variation' ) ) { return new WP_Error( 'missing', 'Producto no encontrado.', array( 'status' => 404 ) ); }
			$item = self::projection( $p );
			$review = get_user_meta( get_current_user_id(), '_cvd_content_review_' . $id, true );
			if ( ! is_array( $review ) || ( $review['version'] ?? '' ) !== $item['version'] ) { return rest_ensure_response( $item ); }
			$item['review'] = $review['image'];
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
		$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
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
			if ( $publish && ( '' === $p->get_price() || ! $p->get_image_id() || '' === trim( wp_strip_all_tags( $description ) ) ) ) { return new WP_Error( 'incomplete', 'Para publicar necesita foto, descripción y un precio configurado en el catálogo comercial.', array( 'status' => 422 ) ); }
			if ( $id ) {
				$history = $p->get_meta( '_cvd_content_history' );
				$history = is_array( $history ) ? $history : array();
				$history[] = array( 'at' => gmdate( 'c' ), 'by' => get_current_user_id(), 'before' => self::projection( $p ) );
				$p->update_meta_data( '_cvd_content_history', array_slice( $history, -10 ) );
			}
			$p->set_name( $name ); $p->set_description( $description );
			$brief = array(); $input_brief = (array) $r->get_param( 'brief' );
			foreach ( array( 'facts', 'buyer', 'objection', 'evidence', 'use', 'headline' ) as $key ) {
				$brief[ $key ] = mb_substr( sanitize_textarea_field( (string) ( $input_brief[ $key ] ?? '' ) ), 0, 1000 );
			}
			$p->update_meta_data( '_cvd_content_brief', $brief );
			// Preserve the canonical notice for market products.
			if ( 0 !== strpos( (string) $p->get_sku(), 'MK-' ) ) { $p->set_short_description( $short ); }
			if ( $publish ) { $p->set_status( 'publish' ); }
			$p->update_meta_data( self::LOCK, get_current_user_id() );
			$p->update_meta_data( '_cvd_texto_fijo', gmdate( 'c' ) );
			if ( ! $id ) { $p->update_meta_data( '_cvd_content_local', '1' ); }
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
		wp_enqueue_style( 'cvd-content-studio', CVD_URL . 'assets/content-studio.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-product-background', CVD_URL . 'assets/product-background.js', array(), (string) filemtime( CVD_DIR . 'assets/product-background.js' ), true );
		wp_enqueue_script( 'cvd-content-studio', CVD_URL . 'assets/content-studio.js', array( 'cvd-product-background' ), (string) filemtime( CVD_DIR . 'assets/content-studio.js' ), true );
		wp_localize_script( 'cvd-content-studio', 'cvContent', array( 'providers' => CVD_Photo_AI::providers(), 'base' => rest_url( 'casa-viva/v1/contenido/' ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mi catálogo · Casa Viva</title><?php wp_head(); ?></head><body class="cv-content"><main>
		<header><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">← Mi cuenta</a><span>CASA VIVA</span><h1>Mi catálogo y fotos</h1><p>De la foto real a tu tienda.</p></header>
		<section><form id="cv-search"><label>Buscar un producto<input name="search" type="search" placeholder="Nombre del producto"></label><button>Buscar</button><button type="button" id="cv-new">Nueva mercancía</button></form><div id="cv-items"></div><button id="cv-more" hidden>Ver más</button></section>
		<p id="cv-message" role="status" aria-live="polite"></p>
		<details><summary>Cambiar mi contraseña</summary><form id="cv-password"><label>Contraseña actual<input id="cv-current-password" type="password" autocomplete="current-password" required></label><label>Nueva contraseña (12 caracteres como mínimo)<input id="cv-next-password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label><button>Guardar contraseña y volver a entrar</button></form></details>
		<form id="cv-editor" hidden><h2>1. Ficha del producto</h2><p id="cv-commercial"></p><label>Título<input id="cv-name" maxlength="160" required></label><label>Descripción<textarea id="cv-description" rows="6"></textarea></label><label>Resumen<textarea id="cv-short" rows="3"></textarea></label><p>Conservamos precios y existencias. En mercancía nueva, guarda el borrador y completa esos datos en el catálogo comercial antes de publicar.</p><button id="cv-save" type="submit">Guardar cambios</button><button id="cv-publish" type="button">Publicar en Casa Viva</button><a id="cv-view" target="_blank" rel="noopener" hidden>Ver en la tienda</a>
		<h2>2. Foto real</h2><label>Subir foto desde el móvil<input id="cv-photo" type="file" accept="image/jpeg,image/png,image/webp"></label><img id="cv-original" alt="Foto principal del producto" hidden><button type="button" id="cv-upload">Subir como foto principal</button><p>La foto anterior se conserva en la galería. Las fotos que subas se protegerán al sincronizar.</p>
		<h2>3. Preparar imágenes para redes</h2><details><summary>¿A quién le sirve y qué necesita saber?</summary><label>Comprador y situación de uso<textarea id="cv-buyer" maxlength="1000" rows="2" placeholder="Qué necesita resolver y qué alternativa usa hoy"></textarea></label><label>Duda antes de comprar<textarea id="cv-objection" maxlength="1000" rows="2" placeholder="Qué le haría dudar de este producto"></textarea></label><label>Evidencia que tenemos<textarea id="cv-evidence" maxlength="1000" rows="2" placeholder="Foto del detalle, ficha del fabricante, comentarios reales o enlace"></textarea></label><p>Si no tienes evidencia, deja el dato pendiente. La guía no equivale a una investigación de mercado.</p></details><label>Plantilla<select id="cv-layout"><option value="features">Características señaladas</option><option value="spec">Ficha técnica</option><option value="offer">Producto y precio</option><option value="use">Uso cotidiano</option></select></label><label>Formato<select id="cv-format"><option value="1920">Historia 9:16</option><option value="1080">Publicación 1:1</option></select></label><label>Características confirmadas (una por línea, hasta tres)<textarea id="cv-facts" rows="3" maxlength="400" placeholder="Medida real, material confirmado, detalle visible"></textarea></label><label>Frase principal<input id="cv-headline" maxlength="90" placeholder="Qué hace útil este producto"></label><label>Uso cotidiano confirmado<input id="cv-use" maxlength="150" placeholder="Dónde y para qué se utiliza"></label><fieldset><legend>Generar opciones con mi foto</legend><label>Proveedor<select id="cv-provider"><option value="cloudflare">Cloudflare · cuota gratuita con Workers Free</option><option value="openai">OpenAI · API de pago</option><option value="higgsfield">Higgsfield · API de pago</option></select></label><p id="cv-ai-setup" hidden>El administrador puede activar la generación en WooCommerce → IA para fotos.</p><p>La IA prepara solo el fondo. Tu móvil coloca encima la foto real completa y conserva las piezas. Puedes guardar una opción por producto para revisarla desde tu perfil.</p><label><input type="checkbox" id="cv-protect-whole"> Conservar también el fondo original dentro de la foto</label><button type="button" id="cv-generate">Generar una opción</button><p id="cv-ai-status" aria-live="polite"></p><div id="cv-variants"></div><details><summary>Usar mi suscripción en su web</summary><p>Prepara los textos en el paso 4, copia la instrucción y adjunta la foto en <a href="https://chatgpt.com/" target="_blank" rel="noopener">ChatGPT</a> o <a href="https://higgsfield.ai/" target="_blank" rel="noopener">Higgsfield</a>. Descarga el resultado para traerlo al panel.</p><input id="cv-import-ai" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Importar resultado de IA"><img id="cv-import-preview" alt="Vista previa de la imagen importada" style="max-width:100%" hidden><button type="button" id="cv-import-button">Elegir imagen importada como principal</button></details></fieldset><button type="button" id="cv-publish-layout" hidden>Elegir el anuncio preparado como imagen principal</button><button type="button" id="cv-render">Preparar imagen</button><canvas id="cv-canvas" width="1080" height="1920" hidden aria-label="Vista previa del anuncio"></canvas><a id="cv-download" download="casa-viva.jpg" hidden>Descargar imagen</a>
		<h2>4. Texto y guía para IA</h2><button type="button" id="cv-title-suggest">Mejorar título con mis datos</button><button type="button" id="cv-suggest">Mejorar descripción con mis datos</button><label>Descripción sugerida<textarea id="cv-suggestion" rows="5"></textarea></label><button type="button" id="cv-use-suggestion">Usar en la ficha</button><button type="button" id="cv-copy-build">Preparar textos para redes</button><label>Texto para compartir<textarea id="cv-copy" rows="6"></textarea></label><button type="button" id="cv-copy-save">Descargar textos</button><details><summary>Instrucción para crear una escena con IA</summary><p>Adjunta siempre la foto real. Revisa el resultado antes de subirlo como foto del producto. Puedes usar la instrucción en tu cuenta del proveedor.</p><textarea id="cv-prompt" rows="9" readonly></textarea></details>
		</form></main><?php wp_footer(); ?></body></html><?php
		exit;
	}
}
