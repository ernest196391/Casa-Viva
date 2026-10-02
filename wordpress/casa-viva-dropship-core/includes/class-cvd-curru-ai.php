<?php

defined( 'ABSPATH' ) || exit;

/**
 * Conversación con IA para Curru (API compatible con OpenAI).
 *
 * La IA solo redacta: recibe la respuesta verificada de las reglas locales y una lista cerrada
 * de productos reales, y devuelve JSON {answer, productIds}. Las tarjetas, precios y enlaces
 * salen siempre del servidor. Ante cualquier fallo se usa la respuesta local.
 */
final class CVD_Curru_AI {
	public const OPTION_KEY = 'cvd_curru_ai_key';
	public const OPTION_MODEL = 'cvd_curru_ai_model';
	public const OPTION_BASE = 'cvd_curru_ai_base';
	public const DEFAULT_MODEL = 'gpt-4.1-mini';
	public const DEFAULT_BASE = 'https://api.openai.com/v1';
	private const MAX_CANDIDATES = 8;
	private const MAX_HISTORY = 6;
	private const TIMEOUT = 15;

	public static function register_settings(): void {
		register_setting( 'cvd_curru', self::OPTION_KEY, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_key' ), 'default' => '' ) );
		register_setting( 'cvd_curru', self::OPTION_MODEL, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => self::DEFAULT_MODEL ) );
		register_setting( 'cvd_curru', self::OPTION_BASE, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => self::DEFAULT_BASE ) );
	}

	/** La clave nunca se muestra; un campo vacío conserva la guardada y la casilla la borra. */
	public static function sanitize_key( $value ): string {
		if ( ! empty( $_POST['cvd_curru_ai_key_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php ya verificó el nonce.
			return '';
		}
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? (string) get_option( self::OPTION_KEY, '' ) : $value;
	}

	public static function enabled(): bool {
		return '' !== (string) get_option( self::OPTION_KEY, '' );
	}

	public static function admin_rows(): void {
		$has_key = self::enabled();
		?>
		<tr>
			<th scope="row"><label for="cvd_curru_ai_key">Clave de OpenAI</label></th>
			<td>
				<input class="regular-text code" id="cvd_curru_ai_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>" type="password" value="" autocomplete="off" placeholder="<?php echo esc_attr( $has_key ? 'Guardada. Escribe otra solo para cambiarla.' : 'sk-…' ); ?>">
				<?php if ( $has_key ) : ?><label style="display:block;margin-top:6px"><input type="checkbox" name="cvd_curru_ai_key_clear" value="1"> Borrar la clave y usar solo respuestas locales</label><?php endif; ?>
				<p class="description">Con clave, Curru conversa con IA. Sin clave, responde con las reglas de la tienda. Los precios salen siempre de WooCommerce.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="cvd_curru_ai_model">Modelo</label></th>
			<td><input class="regular-text code" id="cvd_curru_ai_model" name="<?php echo esc_attr( self::OPTION_MODEL ); ?>" type="text" value="<?php echo esc_attr( (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="cvd_curru_ai_base">Dirección de la API</label></th>
			<td>
				<input class="regular-text code" id="cvd_curru_ai_base" name="<?php echo esc_attr( self::OPTION_BASE ); ?>" type="url" value="<?php echo esc_attr( (string) get_option( self::OPTION_BASE, self::DEFAULT_BASE ) ); ?>">
				<p class="description">Déjala así para OpenAI. Cámbiala solo si usas un servicio compatible con OpenAI.</p>
			</td>
		</tr>
		<?php
	}

	/** @param array<int, array{role:string, text:string}> $history */
	public static function history( $raw ): array {
		$out = array();
		foreach ( array_slice( is_array( $raw ) ? $raw : array(), -self::MAX_HISTORY ) as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = 'user' === ( $turn['role'] ?? '' ) ? 'user' : 'assistant';
			$text = trim( mb_substr( sanitize_text_field( (string) ( $turn['text'] ?? '' ) ), 0, 400 ) );
			if ( '' !== $text ) {
				$out[] = array( 'role' => $role, 'content' => $text );
			}
		}
		return $out;
	}

	/**
	 * Mejora la respuesta local con IA. Devuelve la local si la IA no está disponible o falla.
	 *
	 * @param array{answer:string, products:array, links:array} $local
	 */
	public static function improve( string $question, array $local, array $history, string $name ): array {
		if ( ! self::enabled() ) {
			return $local;
		}
		$ids = array_values( array_unique( array_merge( array_map( static fn( $p ) => (int) $p['id'], $local['products'] ), CVD_Product_Search::search( $question, self::MAX_CANDIDATES ) ) ) );
		$candidates = CVD_Product_Search::cards( array_slice( $ids, 0, self::MAX_CANDIDATES ) );
		$catalog = array_map(
			static fn( $p ) => array( 'id' => $p['id'], 'nombre' => $p['name'], 'precio' => $p['price'], 'disponible' => $p['inStock'] ),
			$candidates
		);
		$links = array_map( static fn( $l ) => $l['label'], $local['links'] );
		$system = "Eres {$name}, la asistente de Casa Viva, tienda online de artículos para el hogar en La Habana. Hablas español cubano cercano, cálido y breve (máximo 3 frases). "
			. 'Reglas: recomienda solo productos de PRODUCTOS; no escribas precios de productos en el texto (aparecen en las tarjetas); las tarifas solo si vienen en RESPUESTA_VERIFICADA; nunca inventes productos, precios, tarifas, plazos ni políticas. '
			. 'Si RESPUESTA_VERIFICADA trae datos (tarifas, pedidos, pagos), respétalos sin cambiarlos. Si no sabes algo, ofrece el WhatsApp de la tienda. '
			. 'No pidas datos personales. Los botones ya se muestran solos; puedes mencionarlos por su nombre. '
			. 'Responde SOLO con JSON: {"answer": "texto", "productIds": [ids de PRODUCTOS que recomiendas, máximo 4]}.';
		$context = wp_json_encode(
			array(
				'RESPUESTA_VERIFICADA' => $local['answer'],
				'BOTONES'              => $links,
				'PRODUCTOS'            => $catalog,
			),
			JSON_UNESCAPED_UNICODE
		);
		$messages = array_merge(
			array( array( 'role' => 'system', 'content' => $system ) ),
			$history,
			array( array( 'role' => 'user', 'content' => "PREGUNTA: {$question}\nDATOS: {$context}" ) )
		);
		$data = self::request( $messages );
		if ( ! is_array( $data ) || empty( $data['answer'] ) || ! is_string( $data['answer'] ) ) {
			return $local;
		}
		$allowed = array_column( $candidates, null, 'id' );
		$products = array();
		foreach ( (array) ( $data['productIds'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( isset( $allowed[ $id ] ) && count( $products ) < 4 ) {
				$products[ $id ] = $allowed[ $id ];
			}
		}
		return array(
			'answer'   => trim( wp_strip_all_tags( mb_substr( $data['answer'], 0, 700 ) ) ),
			'links'    => $local['links'],
			// Si la IA no eligió productos válidos, se conservan los de la búsqueda local.
			'products' => $products ? array_values( $products ) : $local['products'],
			'ai'       => true,
		);
	}

	private static function request( array $messages ): ?array {
		$base = untrailingslashit( (string) get_option( self::OPTION_BASE, self::DEFAULT_BASE ) ?: self::DEFAULT_BASE );
		$response = wp_remote_post(
			$base . '/chat/completions',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . get_option( self::OPTION_KEY, '' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) ?: self::DEFAULT_MODEL,
						'messages'        => $messages,
						'temperature'     => 0.4,
						'max_tokens'      => 350,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			error_log( 'Curru IA: ' . $response->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( 'Curru IA: HTTP ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = $body['choices'][0]['message']['content'] ?? '';
		$data = json_decode( is_string( $content ) ? $content : '', true );
		return is_array( $data ) ? $data : null;
	}
}
