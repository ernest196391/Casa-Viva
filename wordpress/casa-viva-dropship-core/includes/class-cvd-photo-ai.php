<?php
/** Product reference edits. Generation only returns previews to the editor. */
defined( 'ABSPATH' ) || exit;

final class CVD_Photo_AI {
	private const KEYS = array( 'account' => 'ID de cuenta Cloudflare (Workers Free)', 'cloudflare' => 'Token de Workers AI', 'vps' => 'Clave del servidor de imágenes del VPS (OpenAI con tope común)', 'openai' => 'Clave API de OpenAI', 'higgsfield' => 'Clave completa de Open Higgsfield' );
	// 6-nov-2026 (Ernesto): imágenes con OpenAI a través del VPS (vivabot scripts/imagen-servidor.mjs → src/imagen-ia.js):
	// la clave de OpenAI no sale del VPS y el gasto cuenta en el mismo tope que «#imagen» y `cv imagen` (1 USD/día, 10/mes).
	private const VPS_URL = 'https://179-236-242-212.sslip.io/imagen/panel';
	private const SCENES = array(
		'features' => 'Pale warm-grey seamless matte paper, soft diffuse studio light.',
		'spec' => 'Warm ivory seamless studio backdrop with a subtle sand-colored gradient at the edges, even diffuse light.',
		'offer' => 'Soft cream seamless background with a gentle warm gradient.',
		'use' => 'Empty pale stone tabletop viewed from above, quiet natural window light.',
	);

	public static function register(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'IA para fotos', 'IA para fotos', 'manage_options', 'cvd-photo-ai', array( __CLASS__, 'settings' ) );
		} );
		add_action( 'rest_api_init', static function () {
			foreach ( array( 'generate', 'poll' ) as $action ) {
				register_rest_route( 'casa-viva/v1', '/contenido/ia/' . $action, array( 'methods' => 'POST', 'permission_callback' => array( 'CVD_Content_Studio', 'allowed' ), 'callback' => array( __CLASS__, $action ) ) );
			}
		} );
	}

	private static function key( string $name ): string {
		$constant = 'CVD_PHOTO_AI_' . strtoupper( $name );
		if ( defined( $constant ) ) { return (string) constant( $constant ); }
		$stored = get_option( 'cvd_photo_ai_secrets', array() );
		if ( empty( $stored[ $name ] ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) { return ''; }
		$raw = base64_decode( $stored[ $name ], true );
		if ( ! $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) { return ''; }
		$n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		return (string) sodium_crypto_secretbox_open( substr( $raw, $n ), substr( $raw, 0, $n ), hash( 'sha256', wp_salt( 'auth' ), true ) );
	}

	public static function providers(): array {
		return array( 'cloudflare' => (bool) ( self::key( 'cloudflare' ) && preg_match( '/^[a-f0-9]{32}$/i', self::key( 'account' ) ) ), 'vps' => (bool) self::key( 'vps' ), 'openai' => (bool) self::key( 'openai' ), 'higgsfield' => (bool) self::key( 'higgsfield' ) );
	}

	/**
	 * Imagen con OpenAI a través del VPS (tope común). Sin $refs genera desde texto; con $refs (bytes de 1–2 fotos reales,
	 * JPG/PNG/WebP de hasta 4 MB) edita a partir de ellas. $opts: calidad (baja|media|alta), tam (1024x1024|1024x1536|
	 * 1536x1024|auto), modelo (de la lista con precio del VPS), fidelidad ('alta' = respeta más forma, color y etiquetas
	 * de la foto real). → bytes de la imagen o WP_Error (429 = tope del día, subtope del panel 0,5 USD/día u ocupado).
	 * Tarda 20–60 s. Para el rediseño del panel (fotos de estudio a partir de la foto del móvil).
	 */
	public static function vps_image( string $prompt, array $opts = array(), array $refs = array() ) {
		$key = self::key( 'vps' );
		if ( ! $key ) { return self::error( 'Pide al administrador que configure el servidor de imágenes.' ); }
		$body = array( 'prompt' => $prompt, 'quien' => (string) wp_get_current_user()->user_login );
		foreach ( array( 'calidad', 'tam', 'modelo', 'fidelidad' ) as $k ) { if ( ! empty( $opts[ $k ] ) ) { $body[ $k ] = (string) $opts[ $k ]; } }
		foreach ( array_slice( $refs, 0, 2 ) as $bytes ) {
			$size = is_string( $bytes ) && strlen( $bytes ) <= 4 * MB_IN_BYTES ? @getimagesizefromstring( $bytes ) : false;
			if ( ! $size || ! in_array( $size['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) { return self::error( 'La foto de base debe ser JPG, PNG o WebP de hasta 4 MB.' ); }
			$body['refs'][] = array( 'mime' => $size['mime'], 'b64' => base64_encode( $bytes ) );
		}
		$url = defined( 'CVD_PHOTO_AI_VPS_URL' ) ? (string) CVD_PHOTO_AI_VPS_URL : self::VPS_URL;
		$response = wp_safe_remote_post( $url, array( 'timeout' => 150, 'redirection' => 0, 'limit_response_size' => 16 * MB_IN_BYTES, 'headers' => array( 'Content-Type' => 'application/json', 'X-Imagen-Key' => $key ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) { return self::error( 'El servidor de imágenes no respondió a tiempo. Espera un minuto antes de repetir.', 502 ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || empty( $data['ok'] ) || empty( $data['image_b64'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'El servidor de imágenes no hizo la imagen (HTTP ' . $status . ').';
			return self::error( $msg, in_array( $status, array( 400, 429 ), true ) ? $status : 502 );
		}
		$bytes = base64_decode( (string) $data['image_b64'], true );
		return $bytes ? $bytes : self::error( 'El servidor de imágenes devolvió una imagen vacía.', 502 );
	}

	/**
	 * 9-nov-2026 · Recorte GRATIS del producto en el VPS (no cuenta en el tope). $bytes = foto real (JPG, PNG o WebP,
	 * ≤ 8 MB). → array( 'png' => bytes del producto con fondo TRANSPARENTE recortado a su tamaño, 'fiable' => bool,
	 * 'motivos' => string[], 'medidas' => array ) o WP_Error (429 = otro recorte en marcha). Tarda ~7–25 s.
	 * Si 'fiable' es false (no quitó el fondo, producto cortado en la foto, bordes difusos) → usar vps_image().
	 * Ojo: piezas finas sobre fondo claro pueden borrarse sin que salga en 'motivos'; lo confirma Zayri en la bandeja.
	 * El panel pone el PNG sobre blanco puro (HERO) y lo pasa a WebP.
	 */
	public static function vps_recorte( string $bytes ) {
		$key = self::key( 'vps' );
		if ( ! $key ) { return self::error( 'Pide al administrador que configure el servidor de imágenes.' ); }
		$size = strlen( $bytes ) <= 8 * MB_IN_BYTES ? @getimagesizefromstring( $bytes ) : false;
		if ( ! $size || ! in_array( $size['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) { return self::error( 'La foto debe ser JPG, PNG o WebP de hasta 8 MB.' ); }
		$url = defined( 'CVD_PHOTO_AI_VPS_RECORTE_URL' ) ? (string) CVD_PHOTO_AI_VPS_RECORTE_URL : str_replace( '/imagen/panel', '/imagen/recorte', self::VPS_URL );
		$body = array( 'mime' => $size['mime'], 'foto_b64' => base64_encode( $bytes ), 'quien' => (string) wp_get_current_user()->user_login );
		$response = wp_safe_remote_post( $url, array( 'timeout' => 150, 'redirection' => 0, 'limit_response_size' => 24 * MB_IN_BYTES, 'headers' => array( 'Content-Type' => 'application/json', 'X-Imagen-Key' => $key ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) { return self::error( 'El servidor de imágenes no respondió a tiempo. Espera un minuto antes de repetir.', 502 ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || empty( $data['ok'] ) || empty( $data['png_b64'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'No se pudo recortar la foto (HTTP ' . $status . ').';
			return self::error( $msg, in_array( $status, array( 400, 429 ), true ) ? $status : 502 );
		}
		$png = base64_decode( (string) $data['png_b64'], true );
		if ( ! $png ) { return self::error( 'El recorte llegó vacío.', 502 ); }
		return array( 'png' => $png, 'fiable' => ! empty( $data['fiable'] ), 'motivos' => array_map( 'strval', (array) ( $data['motivos'] ?? array() ) ), 'medidas' => (array) ( $data['medidas'] ?? array() ) );
	}

	public static function settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap"><h1>IA para fotos</h1><p>Para la cuota gratuita, usa una cuenta Workers Free y un token con permiso Workers AI en esa cuenta. Cada clave de pago habilita ese proveedor para Zayri.</p>';
		if ( isset( $_POST['cvd_ai_save'] ) ) {
			check_admin_referer( 'cvd_ai_keys' );
			$stored = get_option( 'cvd_photo_ai_secrets', array() );
			foreach ( self::KEYS as $name => $label ) {
				if ( ! empty( $_POST['remove_' . $name] ) ) { unset( $stored[ $name ] ); continue; }
				$value = trim( (string) wp_unslash( $_POST[ $name ] ?? '' ) );
				if ( '' === $value ) { continue; }
				if ( strlen( $value ) > 1024 || preg_match( '/[^!-~]/', $value ) || ( 'account' === $name && ! preg_match( '/^[a-f0-9]{32}$/i', $value ) ) ) { wp_die( 'Revisa el formato del campo ' . esc_html( $label ) ); }
				if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { wp_die( 'Configura las claves en wp-config.php o activa Sodium en el servidor.' ); }
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$stored[ $name ] = base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, hash( 'sha256', wp_salt( 'auth' ), true ) ) );
			}
			update_option( 'cvd_photo_ai_secrets', $stored, false );
			echo '<p role="status">Configuración guardada.</p>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'cvd_ai_keys' );
		foreach ( self::KEYS as $name => $label ) {
			echo '<p><label>' . esc_html( $label ) . '<br><input autocomplete="new-password" type="password" name="' . esc_attr( $name ) . '"></label> ' . ( self::key( $name ) ? 'Configurado' : 'Pendiente' ) . ' <label><input type="checkbox" name="remove_' . esc_attr( $name ) . '"> Quitar clave guardada</label></p>';
		}
		echo '<button class="button button-primary" name="cvd_ai_save" value="1">Guardar conexiones</button></form></div>';
	}

	private static function error( string $message, int $status = 422 ): WP_Error {
		return new WP_Error( 'cvd_photo_ai', $message, array( 'status' => $status ) );
	}

	private static function request( string $url, array $args ) {
		$response = wp_safe_remote_request( $url, $args + array( 'timeout' => 90, 'redirection' => 0, 'limit_response_size' => 12 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) { return self::error( 'No se recibió el resultado. Comprueba el historial del proveedor antes de volver a generar.', 502 ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( 429 === $status ) { return self::error( 'El proveedor alcanzó su cuota o límite. Inténtalo más tarde.', 429 ); }
		if ( $status < 200 || $status >= 300 ) { return self::error( 'El proveedor rechazó la solicitud (HTTP ' . $status . '). Revisa la conexión y el saldo.', 502 ); }
		return wp_remote_retrieve_body( $response );
	}

	private static function preview( $bytes ) {
		$size = is_string( $bytes ) && strlen( $bytes ) <= 8 * MB_IN_BYTES ? @getimagesizefromstring( $bytes ) : false;
		if ( ! $size || ! in_array( $size['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) || $size[0] * $size[1] > 16000000 ) { return self::error( 'El proveedor no devolvió una imagen válida.', 502 ); }
		return array( 'background' => 'data:' . $size['mime'] . ';base64,' . base64_encode( $bytes ) );
	}

	public static function generate( WP_REST_Request $r ) {
		$provider = (string) $r['provider'];
		if ( empty( self::providers()[ $provider ] ) ) { return self::error( 'Pide al administrador que configure este proveedor.' ); }
		if ( 'cloudflare' !== $provider && '1' !== $r['paid'] ) { return self::error( 'Confirma el uso de la API de pago.' ); }
		if ( 'background' !== $r['mode'] ) { return self::error( 'Actualiza el panel para generar el fondo con tu foto protegida.' ); }
		$scene = self::SCENES[ (string) $r['layout'] ] ?? '';
		if ( ! $scene ) { return self::error( 'Elige una plantilla.' ); }
		$guard = 'cvd_ai_last_' . get_current_user_id();
		if ( get_transient( $guard ) ) { return self::error( 'Espera unos segundos antes de generar otra opción.', 429 ); }
		set_transient( $guard, 1, 10 );
		$prompt = $scene . ' Background texture only, edge to edge. The catalog product will be composited afterwards. No objects, props, hardware, lettering, watermark, borders or cast shadows.';
		$vertical = '1920' === $r['format'];
		if ( 'vps' === $provider ) {
			$image = self::vps_image( $prompt, array( 'calidad' => 'media', 'tam' => $vertical ? '1024x1536' : '1024x1024' ) );
			return is_wp_error( $image ) ? $image : self::preview( $image );
		}
		if ( 'higgsfield' === $provider ) { return self::higgsfield( $prompt, $vertical ); }
		$cf = 'cloudflare' === $provider;
		if ( $cf ) {
			$url = 'https://api.cloudflare.com/client/v4/accounts/' . self::key( 'account' ) . '/ai/run/@cf/black-forest-labs/flux-2-klein-4b';
			$boundary = wp_generate_uuid4();
			$body = '';
			foreach ( array( 'prompt' => $prompt, 'width' => $vertical ? 576 : 1024, 'height' => 1024 ) as $key => $value ) {
				$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
			}
			$body .= "--$boundary--\r\n";
			$type = 'multipart/form-data; boundary=' . $boundary;
		} else {
			$url = 'https://api.openai.com/v1/images/generations';
			$type = 'application/json';
			$body = wp_json_encode( array( 'model' => 'gpt-image-1.5', 'quality' => 'low', 'size' => $vertical ? '1024x1536' : '1024x1024', 'output_format' => 'jpeg', 'prompt' => $prompt ) );
		}
		$out = self::request( $url, array( 'method' => 'POST', 'headers' => array( 'Authorization' => 'Bearer ' . self::key( $provider ), 'Content-Type' => $type ), 'body' => $body ) );
		if ( is_wp_error( $out ) ) { return $out; }
		if ( $cf && @getimagesizefromstring( $out ) ) { return self::preview( $out ); }
		$data = json_decode( $out, true );
		$image = $cf ? ( $data['result']['image'] ?? $data['image'] ?? '' ) : ( $data['data'][0]['b64_json'] ?? '' );
		return self::preview( base64_decode( $image, true ) );
	}

	private static function higgsfield( string $prompt, bool $vertical ) {
		$headers = array( 'Authorization' => 'Key ' . self::key( 'higgsfield' ), 'Content-Type' => 'application/json' );
		$out = self::request( 'https://api.higgsfield.ai/marketing-studio/image', array( 'method' => 'POST', 'headers' => $headers, 'body' => wp_json_encode( array( 'prompt' => $prompt, 'resolution' => '1k', 'aspect_ratio' => $vertical ? '9:16' : '1:1', 'enhance_prompt' => false ) ) ) );
		if ( is_wp_error( $out ) ) { return $out; }
		$id = json_decode( $out, true )['request_id'] ?? '';
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/', $id ) ) { return self::error( 'Higgsfield no devolvió el identificador.', 502 ); }
		set_transient( 'cvd_ai_job_' . md5( $id ), get_current_user_id(), HOUR_IN_SECONDS );
		return array( 'job' => $id );
	}

	public static function poll( WP_REST_Request $r ) {
		$id = (string) $r['job'];
		if ( (int) get_transient( 'cvd_ai_job_' . md5( $id ) ) !== get_current_user_id() ) { return self::error( 'Generación no disponible.', 404 ); }
		$out = self::request( 'https://api.higgsfield.ai/requests/' . rawurlencode( $id ) . '/status', array( 'headers' => array( 'Authorization' => 'Key ' . self::key( 'higgsfield' ) ) ) );
		if ( is_wp_error( $out ) ) { return $out; }
		$data = json_decode( $out, true );
		if ( in_array( $data['status'] ?? '', array( 'queued', 'in_progress', 'in_queue', 'running', 'pending' ), true ) ) { return array( 'job' => $id ); }
		if ( 'completed' !== ( $data['status'] ?? '' ) || empty( $data['images'][0]['url'] ) ) { return self::error( 'Higgsfield no completó la imagen.', 502 ); }
		$image = self::request( $data['images'][0]['url'], array() );
		return is_wp_error( $image ) ? $image : self::preview( $image );
	}
}
