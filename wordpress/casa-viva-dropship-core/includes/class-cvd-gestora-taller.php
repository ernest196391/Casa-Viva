<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Taller de fotos» (3.14.91, Ernesto 29-oct; caso Daykel, piezas de moto): en la oficina de la gestora, Herramientas.
 *   1. Foto lista en el móvil, sin IA y sin costo: recorte cuadrado 1080 (o vertical para estado), fondo liso y banda
 *      con el nombre (y el precio si ella quiere). Todo se hace en el teléfono (assets/gestora-taller.js).
 *   2. Texto para Facebook / estado / descripción del producto: sale de los datos REALES del producto y de lo que ella
 *      escriba. Con IA gratis de Casa Viva (Gemini de texto, tope diario) si hay clave; si no, con plantilla. La IA
 *      nunca pone el precio ni el enlace y si inventa una cifra o una promesa se descarta (CVD_Taller_Text::guard).
 *
 * Quién lo ve: gestoras aprobadas. Opción cvd_taller_acceso = «all» o «prueba» (por defecto: cuentas de prueba y los
 * usuarios de cvd_taller_usuarios). Clave de texto de Casa Viva: opción cvd_taller_gemini_key (nunca sale al navegador).
 */
final class CVD_Gestora_Taller {
	private const NS = 'casa-viva/v1';
	private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const DEFAULT_TEXT_MODEL = 'gemini-flash-lite-latest';
	public const MAX_PER_DAY = 15;      // textos con IA por gestora y día
	public const MAX_GLOBAL_DAY = 400;  // textos con IA entre todas, para no pasar la capa gratis de Google

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 46 );
	}

	/* ------------------------------------------------------------------ acceso */

	public static function enabled_for( WP_User $user ): bool {
		if ( ! $user->exists() || ! class_exists( 'CVD_Registration' ) || 'gestora' !== CVD_Registration::program_type( $user ) || ! CVD_Registration::is_approved_gestora( $user ) ) { return false; }
		if ( 'all' === get_option( 'cvd_taller_acceso', 'prueba' ) || '1' === (string) get_user_meta( $user->ID, '_cvd_test_account', true ) ) { return true; }
		$ids = array_filter( array_map( 'absint', explode( ',', (string) get_option( 'cvd_taller_usuarios', '' ) ) ) );
		return in_array( $user->ID, $ids, true );
	}

	public static function can_use(): bool {
		return is_user_logged_in() && self::enabled_for( wp_get_current_user() );
	}

	public static function assets(): void {
		if ( ! is_page( 'area-gestoras' ) || ! self::can_use() ) { return; }
		wp_enqueue_style( 'cvd-gestora-taller', CVD_URL . 'assets/gestora-taller.css', array( 'cvd-gestora-app' ), CVD_VERSION );
		wp_enqueue_script( 'cvd-gestora-taller', CVD_URL . 'assets/gestora-taller.js', array( 'cvd-gestora-app' ), CVD_VERSION, true );
	}

	/* ------------------------------------------------------------------ texto */

	public static function routes(): void {
		register_rest_route( self::NS, '/gestora/taller/texto', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'rest_text' ),
			'permission_callback' => static fn(): bool => self::can_use(),
		) );
	}

	public static function rest_text( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( ! isset( CVD_Taller_Text::KINDS[ $kind ] ) ) { $kind = 'facebook'; }
		$extra = mb_substr( trim( sanitize_textarea_field( (string) $request->get_param( 'extra' ) ) ), 0, CVD_Taller_Text::MAX_EXTRA );
		$typed = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) ), 0, 120 );

		$f = self::facts( $user, absint( $request->get_param( 'product_id' ) ), $typed, $extra );
		if ( '' === trim( (string) $f['name'] ) ) {
			return new WP_Error( 'cvd_taller_name', 'Elige un producto o escribe cómo se llama.', array( 'status' => 400 ) );
		}
		if ( ! $f['lines'] && ! $f['attrs'] && '' === $extra ) {
			$f['thin'] = true; // sin datos: la plantilla queda corta y se lo decimos
		}

		$body = '';
		$used_ai = false;
		$note = '';
		$left = max( 0, self::MAX_PER_DAY - (int) get_transient( self::day_key( $user->ID ) ) );
		$key = self::key();
		if ( '' !== $key && ! empty( $f['thin'] ) ) {
			$note = 'Hay pocos datos de este producto, así que te dejo lo básico. Añade en «Lo que sabes» las medidas o para qué sirve y vuelve a intentar.';
		} elseif ( '' !== $key && $left <= 0 ) {
			$note = 'Hoy ya usaste tus ' . self::MAX_PER_DAY . ' textos con IA. Te dejo uno sencillo con los datos del producto.';
		} elseif ( '' !== $key && (int) get_transient( self::global_key() ) >= self::MAX_GLOBAL_DAY ) {
			$note = 'La IA está ocupada hoy. Te dejo uno sencillo con los datos del producto.';
		} elseif ( '' !== $key ) {
			set_transient( self::day_key( $user->ID ), self::MAX_PER_DAY - $left + 1, DAY_IN_SECONDS );
			set_transient( self::global_key(), (int) get_transient( self::global_key() ) + 1, DAY_IN_SECONDS );
			$raw = self::ask( $key, $f, $kind );
			$clean = '' !== $raw ? CVD_Taller_Text::guard( $raw, $f, $kind ) : '';
			if ( '' !== $clean ) {
				$body = CVD_Taller_Text::finish( $clean, $f, $kind );
				$used_ai = true;
			} else {
				$note = '' === $raw ? 'La IA no contestó. Te dejo un texto sencillo con los datos del producto.' : 'La IA puso algo que no está en los datos del producto y lo descarté. Te dejo un texto sencillo.';
			}
		}
		if ( '' === $body ) { $body = CVD_Taller_Text::template( $f, $kind ); }
		$left = max( 0, self::MAX_PER_DAY - (int) get_transient( self::day_key( $user->ID ) ) );
		return rest_ensure_response( array( 'text' => $body, 'ai' => $used_ai, 'left' => $left, 'note' => $note, 'has_ai' => '' !== $key ) );
	}

	/** Datos reales del producto (o solo lo que ella escribió). */
	private static function facts( WP_User $user, int $product_id, string $typed, string $extra ): array {
		$f = array( 'name' => $typed, 'lines' => array(), 'attrs' => array(), 'extra' => $extra, 'price' => '', 'link' => '' );
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		if ( '' === $code ) { $code = 'CV' . $user->ID . strtoupper( substr( (string) preg_replace( '/[^A-Z0-9]/i', '', $user->user_login ), 0, 8 ) ); }
		$product = $product_id ? self::product( $product_id ) : null;
		if ( ! $product ) {
			$f['link'] = add_query_arg( 'ref', $code, wc_get_page_permalink( 'shop' ) );
			return $f;
		}
		$f['name'] = $product->get_name();
		$html = (string) $product->get_short_description() . "\n" . (string) $product->get_description();
		$f['lines'] = CVD_Taller_Text::description_lines( $html, $f['name'], 6 );
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) ) { continue; }
			$label = wc_attribute_label( $attr->get_name() );
			$value = trim( (string) $product->get_attribute( $attr->get_name() ) );
			if ( '' !== $label && '' !== $value && mb_strlen( $value ) <= 120 ) { $f['attrs'][ $label ] = $value; }
		}
		$price = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $user->ID ) : (float) $product->get_price();
		if ( $price > 0 ) { $f['price'] = ( floor( $price ) === $price ? number_format( $price, 0, ',', '.' ) : number_format( $price, 2, ',', '.' ) ) . ' USD'; }
		$f['link'] = add_query_arg( 'ref', $code, $product->get_permalink() );
		return $f;
	}

	/** Solo productos que la gestora ve en «Fotos para publicar» (publicados, en existencia, no ocultos). */
	private static function product( int $id ): ?WC_Product {
		if ( class_exists( 'CVD_Gestora_Fotos' ) ) {
			$ids = array_map( 'intval', array_column( (array) ( CVD_Gestora_Fotos::catalog()['items'] ?? array() ), 'id' ) );
			if ( ! in_array( $id, $ids, true ) ) { return null; }
		}
		$product = wc_get_product( $id );
		return $product && 'publish' === $product->get_status() ? $product : null;
	}

	private static function day_key( int $user_id ): string { return 'cvd_taller_ia_' . $user_id . '_' . wp_date( 'Ymd' ); }
	private static function global_key(): string { return 'cvd_taller_ia_all_' . wp_date( 'Ymd' ); }

	private static function key(): string {
		return trim( (string) get_option( 'cvd_taller_gemini_key', '' ) );
	}

	/** Llama al modelo de texto. '' si falla (nunca se registra la clave). */
	private static function ask( string $key, array $f, string $kind ): string {
		$model = (string) get_option( 'cvd_taller_text_model', self::DEFAULT_TEXT_MODEL ) ?: self::DEFAULT_TEXT_MODEL;
		$response = wp_remote_post( self::API . rawurlencode( $model ) . ':generateContent', array(
			'timeout' => 25,
			'headers' => array( 'x-goog-api-key' => $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => CVD_Taller_Text::system_instruction( $kind ) ) ) ),
				'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => CVD_Taller_Text::user_message( $f ) ) ) ) ),
				'generationConfig' => array( 'temperature' => 0.4, 'maxOutputTokens' => 400 ),
			) ),
		) );
		if ( is_wp_error( $response ) ) { error_log( '[cvd-taller] sin conexión con Google' ); return ''; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) { error_log( '[cvd-taller] Google respondió ' . $code ); return ''; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$out = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) { $out .= (string) ( $part['text'] ?? '' ); }
		return trim( wp_strip_all_tags( $out ) );
	}

	/* ------------------------------------------------------------------ vista */

	public static function render( WP_User $user ): string {
		$config = array(
			'base' => esc_url_raw( rest_url( self::NS . '/gestora/taller/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'kinds' => CVD_Taller_Text::KINDS,
			'left' => max( 0, self::MAX_PER_DAY - (int) get_transient( self::day_key( $user->ID ) ) ),
			'ai' => '' !== self::key(),
			'extraMax' => CVD_Taller_Text::MAX_EXTRA,
		);
		ob_start();
		?>
		<section class="cvga-view cvtl" data-view="taller" id="cvga-taller" data-cvtl>
			<div class="cvga-head"><h1>Taller de fotos</h1><p>Sube una foto desde tu móvil y te la dejo lista para publicar, con su texto. Gratis y sin esperar.</p></div>

			<div class="cvga-card cvtl-card">
				<h2>1. ¿De qué producto?</h2>
				<div class="cvtl-pick" data-cvtl-pick hidden><img alt="" width="56" height="56"><div><b data-cvtl-pick-name></b><small class="cvga-note" data-cvtl-pick-price></small></div><button type="button" class="cvga-btn cvga-btn-soft cvga-btn-sm" data-cvtl-pick-clear>Cambiar</button></div>
				<div data-cvtl-search>
					<label class="cvgf-search" for="cvtl-q"><svg class="cvga-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg><input id="cvtl-q" type="search" placeholder="Busca en tu tienda o escribe el nombre" enterkeyhint="search" data-cvtl-q aria-label="Producto"></label>
					<div class="cvtl-hits" data-cvtl-hits></div>
					<small class="cvga-note">¿No está en tu tienda (una pieza tuya)? Escribe el nombre y toca «Usar este nombre».</small>
				</div>
			</div>

			<div class="cvga-card cvtl-card">
				<h2>2. Tu foto</h2>
				<div class="cvtl-src">
					<label class="cvga-btn" for="cvtl-photo">Elegir foto del móvil</label>
					<input id="cvtl-photo" class="cvtl-file" type="file" accept="image/*" data-cvtl-photo>
					<div class="cvtl-thumbs" data-cvtl-thumbs hidden></div>
				</div>
				<p class="cvtl-msg" data-cvtl-msg role="status" hidden></p>
				<div class="cvtl-stage" data-cvtl-stage hidden>
					<canvas class="cvtl-canvas" data-cvtl-canvas aria-label="Vista previa de la foto"></canvas>
					<small class="cvga-note">Arrastra la foto con el dedo para moverla.</small>
					<div class="cvtl-opts">
						<div class="cvtl-opt"><span>Formato</span>
							<div class="cvtl-seg" role="group" aria-label="Formato" data-cvtl-seg="size">
								<button type="button" data-v="sq" aria-pressed="true">Cuadrada 1080</button>
								<button type="button" data-v="v" aria-pressed="false">Vertical (estado)</button>
							</div>
						</div>
						<div class="cvtl-opt"><span>Encuadre</span>
							<div class="cvtl-seg" role="group" aria-label="Encuadre" data-cvtl-seg="mode">
								<button type="button" data-v="cover" aria-pressed="true">Llenar</button>
								<button type="button" data-v="fit" aria-pressed="false">Entera</button>
							</div>
						</div>
						<div class="cvtl-opt" data-cvtl-bg-opt><span>Fondo</span>
							<div class="cvtl-seg" role="group" aria-label="Fondo" data-cvtl-seg="bg">
								<button type="button" data-v="white" aria-pressed="true">Blanco</button>
								<button type="button" data-v="cream" aria-pressed="false">Crema</button>
								<button type="button" data-v="gray" aria-pressed="false">Gris</button>
							</div>
						</div>
						<div class="cvtl-opt"><span>Acercar</span><input type="range" min="100" max="220" value="100" step="5" data-cvtl-zoom aria-label="Acercar"></div>
						<div class="cvtl-opt"><span>Banda</span>
							<div class="cvtl-seg" role="group" aria-label="Banda" data-cvtl-seg="band">
								<button type="button" data-v="none" aria-pressed="false">Sin banda</button>
								<button type="button" data-v="name" aria-pressed="true">Nombre</button>
								<button type="button" data-v="price" aria-pressed="false" data-cvtl-price-btn>Nombre y precio</button>
							</div>
						</div>
						<div class="cvtl-opt" data-cvtl-band-opt><span>Color de la banda</span>
							<div class="cvtl-seg" role="group" aria-label="Color de la banda" data-cvtl-seg="bandcolor">
								<button type="button" data-v="dark" aria-pressed="true">Oscura</button>
								<button type="button" data-v="light" aria-pressed="false">Clara</button>
								<button type="button" data-v="gold" aria-pressed="false">Dorada</button>
							</div>
						</div>
						<label class="cvga-field" for="cvtl-band-text" data-cvtl-band-opt><span>Texto de la banda</span><input id="cvtl-band-text" type="text" maxlength="80" data-cvtl-band-text autocomplete="off" placeholder="Nombre del producto"></label>
						<label class="cvga-field" for="cvtl-price-text" data-cvtl-price-opt hidden><span>Precio en la banda</span><input id="cvtl-price-text" type="text" maxlength="30" data-cvtl-price-text autocomplete="off" placeholder="Ej.: 25 USD"></label>
					</div>
					<div class="cvga-actions">
						<button type="button" class="cvga-btn" data-cvtl-save>Guardar foto</button>
						<button type="button" class="cvga-btn cvga-btn-soft" data-cvtl-share hidden>Compartir</button>
					</div>
					<small class="cvga-note">Se guarda en tu móvil como JPG listo para publicar. No gasta tus datos: se hace aquí mismo.</small>
				</div>
			</div>

			<div class="cvga-card cvtl-card">
				<h2>3. Tu texto</h2>
				<div class="cvtl-seg cvtl-kinds" role="group" aria-label="Qué texto" data-cvtl-kinds></div>
				<label class="cvga-field" for="cvtl-extra"><span>Lo que sabes del producto <small>(opcional: medidas, para qué moto o para qué sirve)</small></span><textarea id="cvtl-extra" rows="3" maxlength="<?php echo esc_attr( (string) CVD_Taller_Text::MAX_EXTRA ); ?>" data-cvtl-extra placeholder="Ej.: para Suzuki GN 125, original usado en buen estado"></textarea></label>
				<div class="cvga-actions"><button type="button" class="cvga-btn" data-cvtl-write>Escribir mi texto</button><small class="cvga-note" data-cvtl-left></small></div>
				<p class="cvtl-msg" data-cvtl-text-msg role="status" hidden></p>
				<div class="cvtl-result" data-cvtl-result hidden>
					<textarea rows="8" data-cvtl-text aria-label="Tu texto"></textarea>
					<div class="cvga-actions"><button type="button" class="cvga-btn cvga-btn-sm" data-cvtl-copy>Copiar texto</button><small class="cvga-note" data-cvtl-copy-msg role="status"></small></div>
					<small class="cvga-note">Lo escribo solo con los datos del producto y lo que tú pusiste. Léelo antes de publicar y cambia lo que haga falta.</small>
				</div>
			</div>
			<script type="application/json" data-cvtl-config><?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
