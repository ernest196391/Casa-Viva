<?php

defined( 'ABSPATH' ) || exit;

/**
 * «NEXO Busca» (antes «Buscar precios»; nombre nuevo en 3.14.28, Ernesto 16-oct) dentro del área de gestoras: la
 * gestora sube la foto de un producto (o escribe qué busca) y la IA busca ofertas públicas en Cuba (Revolico,
 * Facebook público, tiendas, Cubísima). Usa la misma clave y base que Curru; no inventa precios ni enlaces.
 * 3.14.28: tope diario en la opción cvd_busca_daily_limit (20 por defecto), búsqueda solo con texto, y cada búsqueda
 * se guarda (CVD_Nexo_Busca) con su foto para poder comparar proveedores. Lo de después (precio, proveedor, cliente,
 * foto bonita, vender por Casa Viva) está en CVD_Nexo_Busca y assets/gestora-busca.js.
 */
final class CVD_Gestora_Busca {
	private const NS = 'casa-viva/v1';
	private const OPTION_MODEL = 'cvd_busca_model';
	private const DEFAULT_MODEL = 'gpt-4.1-mini';
	private const DAILY_LIMIT = 20;
	private const MAX_BYTES = 8388608;

	public static function register(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( self::NS, '/gestora/busca', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			) );
		} );
	}

	public static function can_use(): bool {
		$user = wp_get_current_user();
		return $user->exists() && CVD_Registration::is_approved_gestora( $user );
	}

	public static function available(): bool {
		return class_exists( 'CVD_Curru_AI' ) && CVD_Curru_AI::enabled();
	}

	public static function daily_limit(): int {
		$n = absint( get_option( 'cvd_busca_daily_limit', self::DAILY_LIMIT ) );
		return $n > 0 ? $n : self::DAILY_LIMIT;
	}

	public static function remaining( int $user_id ): int {
		$used = (int) get_transient( self::counter_key( $user_id ) );
		return max( 0, self::daily_limit() - $used );
	}

	private static function counter_key( int $user_id ): string {
		return 'cvd_busca_' . $user_id . '_' . wp_date( 'Ymd' );
	}

	public static function search( WP_REST_Request $request ) {
		if ( ! self::available() ) { return new WP_Error( 'cvd_busca_off', 'NEXO Busca no está disponible ahora.', array( 'status' => 503 ) ); }
		$user_id = get_current_user_id();
		if ( self::remaining( $user_id ) < 1 ) { return new WP_Error( 'cvd_busca_limit', 'Ya hiciste ' . self::daily_limit() . ' búsquedas hoy. Mañana puedes seguir.', array( 'status' => 429 ) ); }
		$files = $request->get_file_params();
		$file = $files['image'] ?? null;
		$context = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'context' ) ), 0, 500 );
		$zone = mb_substr( sanitize_text_field( (string) $request->get_param( 'zone' ) ), 0, 120 );
		$has_file = is_array( $file ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && '' !== (string) ( $file['name'] ?? '' );
		if ( $has_file && ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) ) {
			return new WP_Error( 'cvd_busca_image', 'No llegó la foto. Prueba otra vez (con buena señal).', array( 'status' => 400 ) );
		}
		return self::run( $user_id, $has_file ? (string) $file['tmp_name'] : '', $context, $zone );
	}

	/**
	 * La búsqueda en sí (foto ya subida en $path, o '' para buscar solo por texto). Separada de search() para poder
	 * probarla desde el servidor (wp eval) con una foto real, sin subirla por HTTP.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run( int $user_id, string $path, string $context, string $zone ) {
		$jpeg = '';
		$data_url = '';
		if ( '' !== $path ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || (int) filesize( $path ) > self::MAX_BYTES ) {
				return new WP_Error( 'cvd_busca_image', 'La foto debe ser JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 400 ) );
			}
			$jpeg = self::jpeg( $path );
			$data_url = '' !== $jpeg ? 'data:image/jpeg;base64,' . base64_encode( $jpeg ) : self::image_data_url( $path, (string) $info['mime'] ); // phpcs:ignore
		} elseif ( mb_strlen( trim( $context ) ) < 3 ) {
			return new WP_Error( 'cvd_busca_image', 'Sube una foto del producto o escribe qué buscas.', array( 'status' => 400 ) );
		}
		$content = array( array( 'type' => 'input_text', 'text' => 'Lo que busca el/la gestor/a: ' . ( $context ?: 'sin texto (mira la foto)' ) . "\nZona: " . ( $zone ?: 'La Habana' ) . ( $data_url ? '' : "\n(No hay foto: busca por el texto.)" ) ) );
		if ( $data_url ) { $content[] = array( 'type' => 'input_image', 'image_url' => $data_url ); }

		set_transient( self::counter_key( $user_id ), ( (int) get_transient( self::counter_key( $user_id ) ) ) + 1, DAY_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 150 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$base = untrailingslashit( (string) get_option( CVD_Curru_AI::OPTION_BASE, CVD_Curru_AI::DEFAULT_BASE ) ?: CVD_Curru_AI::DEFAULT_BASE );
		$response = wp_remote_post( $base . '/responses', array(
			'timeout' => 120,
			'headers' => array( 'Authorization' => 'Bearer ' . get_option( CVD_Curru_AI::OPTION_KEY, '' ), 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'model'        => (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) ?: self::DEFAULT_MODEL,
				'store'        => false,
				'instructions' => self::instructions(),
				'tools'        => array( array( 'type' => 'web_search' ) ),
				'input'        => array( array( 'role' => 'user', 'content' => $content ) ),
				'text'         => array( 'format' => array( 'type' => 'json_schema', 'name' => 'busca_precios', 'strict' => true, 'schema' => self::schema() ) ),
			) ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			error_log( 'CVD_BUSCA ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) . ' ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'cvd_busca_fail', 'La búsqueda no pudo terminar. Inténtalo otra vez en un rato.', array( 'status' => 502 ) );
		}
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$parsed = self::parse( self::output_text( is_array( $payload ) ? $payload : array() ) );
		if ( ! $parsed ) { return new WP_Error( 'cvd_busca_fail', 'La búsqueda devolvió un resultado incompleto. Inténtalo otra vez.', array( 'status' => 502 ) ); }
		$parsed['remaining'] = self::remaining( $user_id );
		// 3.14.28: se guarda la búsqueda (con su foto) para comparar proveedores y para «Vender por Casa Viva».
		$parsed['id'] = 0;
		$parsed['photo'] = '';
		if ( class_exists( 'CVD_Nexo_Busca' ) ) {
			$photo = CVD_Nexo_Busca::store_photo( $jpeg );
			$parsed['id'] = CVD_Nexo_Busca::save_search( $user_id, $photo, $context, $zone, $parsed );
			$parsed['photo'] = CVD_Nexo_Busca::photo_url( $photo );
		}
		return rest_ensure_response( $parsed );
	}

	/** La foto como JPEG de 1280 px como máximo (para la IA y para guardarla). '' si no se pudo. */
	private static function jpeg( string $path ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) { return ''; }
		$size = $editor->get_size();
		if ( max( (int) $size['width'], (int) $size['height'] ) > 1280 ) { $editor->resize( 1280, 1280, false ); }
		$editor->set_quality( 82 );
		$saved = $editor->save( wp_tempnam( 'cvd-busca' ) . '.jpg', 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) { return ''; }
		$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $saved['path'] );
		return $bytes;
	}

	/** Reduce la foto a 1280 px como máximo: menos espera y menos coste. */
	private static function image_data_url( string $path, string $mime ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) ) {
			$size = $editor->get_size();
			if ( max( (int) $size['width'], (int) $size['height'] ) > 1280 ) {
				$editor->resize( 1280, 1280, false );
				$saved = $editor->save( wp_tempnam( 'cvd-busca' ) . '.jpg', 'image/jpeg' );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
					$bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					wp_delete_file( $saved['path'] );
					return 'data:image/jpeg;base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
			}
		}
		return 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore
	}

	private static function output_text( array $payload ): string {
		foreach ( (array) ( $payload['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) ) { return (string) ( $content['text'] ?? '' ); }
			}
		}
		return '';
	}

	private static function parse( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['hypothesis'], $data['summary'], $data['results'] ) ) { return null; }
		$results = array();
		foreach ( array_slice( (array) $data['results'], 0, 8 ) as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$url = esc_url_raw( (string) ( $r['url'] ?? '' ), array( 'http', 'https' ) );
			$results[] = array(
				'title'    => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
				'match'    => in_array( $r['match'] ?? '', array( 'exact', 'close', 'alternative', 'unconfirmed' ), true ) ? $r['match'] : 'unconfirmed',
				'price'    => sanitize_text_field( (string) ( $r['priceLabel'] ?? '' ) ),
				'amount'   => is_numeric( $r['priceValue'] ?? null ) && (float) $r['priceValue'] > 0 ? round( (float) $r['priceValue'], 2 ) : null,
				'currency' => in_array( $r['currency'] ?? '', array( 'USD', 'CUP', 'MLC', 'EUR' ), true ) ? $r['currency'] : '',
				'location' => sanitize_text_field( (string) ( $r['location'] ?? '' ) ),
				'phone'    => preg_replace( '/[^0-9+]/', '', (string) ( $r['phone'] ?? '' ) ),
				'url'      => $url,
				'source'   => sanitize_text_field( (string) ( $r['source'] ?? '' ) ),
				'note'     => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
			);
		}
		return array(
			'product'   => sanitize_text_field( (string) ( $data['hypothesis']['name'] ?? '' ) ),
			'summary'   => sanitize_text_field( (string) $data['summary'] ),
			'results'   => $results,
			'questions' => array_map( 'sanitize_text_field', array_slice( (array) ( $data['verificationQuestions'] ?? array() ), 0, 5 ) ),
		);
	}

	private static function instructions(): string {
		return 'Eres "NEXO Busca", asistente de un/a gestor/a de ventas en Cuba. Le ahorras tiempo para decidir qué ofrecer y a qué precio. Analiza la foto (si es una captura de WhatsApp o Facebook, ignora la interfaz y céntrate en el producto). No inventes marca, modelo, especificaciones, teléfonos, precios, enlaces ni disponibilidad: si algo no se puede confirmar, dilo.'
			. "\n\n" . 'Busca con web_search ofertas públicas actuales en Cuba, en este orden: 1) Revolico; 2) Facebook público indexado (Marketplace, páginas y grupos públicos); 3) tiendas cubanas con web; 4) Cubísima u otros clasificados cubanos; 5) web abierta. Prioriza enlaces directos al anuncio, resultados recientes, la zona indicada y vendedores distintos. No repitas el mismo anuncio.'
			. "\n\n" . 'Devuelve: una hipótesis breve del producto; summary = UNA frase con el rango de precios y la mejor coincidencia; hasta 8 opciones (priceLabel tal como aparece, con moneda: USD, CUP, MLC; priceValue = el número de UNA unidad, o null si no hay precio claro; currency = USD, CUP, MLC, EUR o vacío); note solo si cambia la decisión (garantía, entrega, dato dudoso); 3 a 5 preguntas que el/la gestor/a debe hacer al vendedor. Clasifica match como exact, close, alternative o unconfirmed. Español sencillo. Cumple estrictamente el esquema JSON.';
	}

	private static function schema(): array {
		$result = array( 'title' => array( 'type' => 'string' ), 'match' => array( 'type' => 'string', 'enum' => array( 'exact', 'close', 'alternative', 'unconfirmed' ) ), 'priceLabel' => array( 'type' => 'string' ), 'priceValue' => array( 'type' => array( 'number', 'null' ) ), 'currency' => array( 'type' => 'string', 'enum' => array( 'USD', 'CUP', 'MLC', 'EUR', '' ) ), 'location' => array( 'type' => 'string' ), 'phone' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ), 'source' => array( 'type' => 'string' ), 'note' => array( 'type' => 'string' ) );
		return array(
			'type' => 'object', 'additionalProperties' => false, 'required' => array( 'hypothesis', 'summary', 'results', 'verificationQuestions' ),
			'properties' => array(
				'hypothesis' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name', 'confidence' ), 'properties' => array( 'name' => array( 'type' => 'string' ), 'confidence' => array( 'type' => 'number' ) ) ),
				'summary' => array( 'type' => 'string' ),
				'verificationQuestions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array_keys( $result ), 'properties' => $result ) ),
			),
		);
	}
}
