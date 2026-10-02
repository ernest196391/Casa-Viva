<?php

defined( 'ABSPATH' ) || exit;

/**
 * Curru, el asistente de Casa Viva.
 *
 * Responde en el servidor con reglas locales: busca productos reales (precios de WooCommerce),
 * da la tarifa oficial de mensajería y orienta sobre pedidos y pagos. No recibe PII ni muta
 * pedidos; añadir al carrito usa el flujo estándar de WooCommerce en el navegador.
 */
final class CVD_Contextual_Assistant {
	private const RATE_LIMIT = 30;
	private const DEFAULT_AVATAR = 'https://d8j0ntlcm91z4.cloudfront.net/user_3JKeIPPvD2MrM6gfmHcWhePZrny/hf_20261002_160940_c68a53bc-a02e-49eb-874c-33a1286b7e1b_min.webp';
	private const RATE_WINDOW = 300;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 90 );
		// El marcado debe existir antes de que WordPress imprima los scripts del pie (prioridad 20).
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_settings' ) );
	}

	public static function admin_menu(): void {
		add_submenu_page( 'woocommerce', 'Curru', 'Curru (asistente)', 'manage_woocommerce', 'cvd-curru', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_settings(): void {
		register_setting( 'cvd_curru', 'cvd_assistant_avatar_url', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
		CVD_Curru_AI::register_settings();
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$avatar = self::avatar_url();
		?>
		<div class="wrap">
			<h1>Curru, asistente de Casa Viva</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'cvd_curru' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cvd_assistant_avatar_url">Foto de Curru</label></th>
						<td>
							<input class="regular-text code" id="cvd_assistant_avatar_url" name="cvd_assistant_avatar_url" type="url" value="<?php echo esc_attr( (string) get_option( 'cvd_assistant_avatar_url', '' ) ); ?>" placeholder="https://casavivadecuba.com/wp-content/uploads/…">
							<p class="description">Vacío = dibujo de Curru incluido en la tienda. Para usar otra imagen, súbela en Medios › Añadir, copia su URL y pégala aquí (mejor cuadrada).</p>
							<?php if ( $avatar ) : ?><p><img src="<?php echo esc_url( $avatar ); ?>" alt="" width="72" height="72" style="border-radius:50%;object-fit:cover"></p><?php endif; ?>
						</td>
					</tr>
					<?php CVD_Curru_AI::admin_rows(); ?>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>
		</div>
		<?php
	}

	public static function name(): string {
		return (string) apply_filters( 'cvd_assistant_name', 'Curru' );
	}

	/** Foto de Curru: opción de wp-admin, archivo del plugin, ilustración por defecto o dibujo SVG. Vacío = inicial. */
	public static function avatar_url(): string {
		$option = (string) get_option( 'cvd_assistant_avatar_url', '' );
		if ( $option ) {
			return esc_url_raw( $option );
		}
		foreach ( array( 'curru-avatar.webp', 'curru-avatar.jpg', 'curru-avatar.png' ) as $file ) {
			if ( file_exists( CVD_DIR . 'assets/' . $file ) ) {
				return CVD_URL . 'assets/' . $file;
			}
		}
		// Ilustración 3D de Curru generada en Higgsfield (2026-10-02). Si no carga, el navegador usa el dibujo incluido.
		$remote = (string) apply_filters( 'cvd_curru_default_avatar', self::DEFAULT_AVATAR );
		if ( $remote ) {
			return esc_url_raw( $remote );
		}
		return file_exists( CVD_DIR . 'assets/curru-avatar.svg' ) ? CVD_URL . 'assets/curru-avatar.svg' : '';
	}

	public static function context(): string {
		if ( ! is_user_logged_in() ) { return 'visitante'; }
		$user = wp_get_current_user();
		$program = class_exists( 'CVD_Registration' ) ? CVD_Registration::program_type( $user ) : '';
		if ( 'mensajero' === $program ) { return 'mensajero'; }
		if ( 'gestora' === $program ) { return 'gestora'; }
		if ( array_intersect( array( 'administrator', 'shop_manager', 'cvd_operator', 'cvd_clerk' ), (array) $user->roles ) ) { return 'operacion'; }
		return 'cliente';
	}

	private static function whatsapp_url(): string {
		$phone = preg_replace( '/\D+/', '', (string) get_option( 'cvd_central_whatsapp', '' ) );
		return $phone ? 'https://wa.me/' . $phone . '?text=' . rawurlencode( 'Hola Casa Viva, necesito ayuda.' ) : '';
	}

	private static function urls(): array {
		return array(
			'routeUrl'    => home_url( '/ruta-cv/' ),
			'managersUrl' => home_url( '/gestores/' ),
			'ordersUrl'   => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ),
			'ratesUrl'    => home_url( '/tarifas-mensajeria/' ),
			'shopUrl'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' ),
			'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/carrito/' ),
		);
	}

	public static function assets(): void {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'cvd-contextual-assistant', CVD_URL . 'assets/contextual-assistant.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-contextual-assistant', CVD_URL . 'assets/contextual-assistant.js', array(), CVD_VERSION, true );
		wp_localize_script(
			'cvd-contextual-assistant',
			'cvdContextualAssistant',
			array_merge(
				self::urls(),
				array(
					'context'   => self::context(),
					'name'      => self::name(),
					'askUrl'    => rest_url( 'casa-viva/v1/curru/ask' ),
					'addUrl'    => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
					'whatsapp'  => self::whatsapp_url(),
					// Solo con sesión: las páginas de visitantes pueden estar en caché y un nonce caducado bloquearía la consulta.
					'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				)
			)
		);
	}

	public static function render(): void {
		if ( is_admin() ) { return; }
		$name = self::name();
		$avatar = self::avatar_url();
		$photo = static function ( string $class ) use ( $name, $avatar ): string {
			$initial = '<span class="cvd-curru-initial" aria-hidden="true">' . esc_html( mb_substr( $name, 0, 1 ) ) . '</span>';
			return $avatar ? '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $avatar ) . '" data-fallback="' . esc_url( CVD_URL . 'assets/curru-avatar.svg' ) . '" alt="" width="96" height="96" decoding="async" loading="lazy">' . $initial : $initial;
		};
		?>
		<div class="cvd-curru-nudge" data-cvd-curru-nudge hidden><button type="button" data-cvd-curru-open>¿Te ayudo a encontrar algo? <span aria-hidden="true">👋</span></button></div>
		<button class="cvd-assistant-launcher<?php echo $avatar ? ' has-photo' : ''; ?>" type="button" aria-label="<?php echo esc_attr( 'Abrir ' . $name . ', asistente de Casa Viva' ); ?>" aria-expanded="false" aria-controls="cvd-contextual-assistant"><?php echo $photo( 'cvd-curru-photo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<div class="cvd-curru-backdrop" data-cvd-curru-backdrop hidden></div>
		<aside class="cvd-contextual-assistant" id="cvd-contextual-assistant" data-context="<?php echo esc_attr( self::context() ); ?>" role="dialog" aria-modal="true" aria-labelledby="cvd-curru-name" tabindex="-1" hidden>
			<header>
				<span class="cvd-curru-avatar<?php echo $avatar ? ' has-photo' : ''; ?>"><?php echo $photo( 'cvd-curru-photo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div><strong id="cvd-curru-name"><?php echo esc_html( $name ); ?></strong><small class="cvd-curru-online">● En línea</small><small>Asistente de Casa Viva</small></div>
				<button type="button" data-cvd-assistant-close aria-label="<?php echo esc_attr( 'Cerrar ' . $name ); ?>">×</button>
			</header>
			<div class="cvd-curru-messages" data-cvd-curru-messages aria-live="polite">
				<p class="cvd-curru-bubble"><b>¡Hola! Soy <?php echo esc_html( $name ); ?>.</b><br>Te ayudo a encontrar productos, saber cuánto cuesta la mensajería a tu zona y seguir tu pedido.</p>
			</div>
			<div class="cvd-contextual-assistant__quick" data-cvd-curru-quick aria-label="Preguntas frecuentes">
				<button type="button" data-question="Quiero buscar productos">Buscar productos<small>Dime qué necesitas</small></button>
				<button type="button" data-question="¿Cuánto cuesta la mensajería?">Mensajería<small>Precio a tu zona</small></button>
				<button type="button" data-question="¿Dónde está mi pedido?">Mi pedido<small>Estado y seguimiento</small></button>
				<button type="button" data-question="¿Cómo puedo pagar?">Cómo pagar<small>Monedas y formas</small></button>
			</div>
			<p class="cvd-curru-voice" data-cvd-curru-voice role="status" aria-live="polite"></p>
			<form data-cvd-curru-form>
				<label for="cvd-contextual-question">Tu mensaje</label>
				<div>
					<input id="cvd-contextual-question" maxlength="240" autocomplete="off" enterkeyhint="send" placeholder="Escribe o dicta tu mensaje…">
					<button type="button" class="cvd-curru-mic" data-cvd-curru-mic aria-pressed="false" aria-label="Dictar por voz" hidden><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg></button>
					<button type="submit" class="cvd-curru-send" aria-label="Enviar"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 9-18 9 4-9-4-9Zm4 9h14"/></svg></button>
				</div>
			</form>
		</aside>
		<?php
	}

	public static function routes(): void {
		register_rest_route(
			'casa-viva/v1',
			'/curru/ask',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'ask' ),
				'args'                => array( 'question' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	private static function rate_limited(): bool {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'cvd_curru_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}

	public static function ask( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_Error( 'cvd_curru_busy', 'Has hecho muchas preguntas seguidas. Espera un momento y vuelve a intentarlo.', array( 'status' => 429 ) );
		}
		$question = trim( mb_substr( sanitize_text_field( (string) $request->get_param( 'question' ) ), 0, 240 ) );
		if ( '' === $question ) {
			return new WP_Error( 'cvd_curru_empty', 'Escribe tu pregunta.', array( 'status' => 422 ) );
		}
		$context = self::context();
		$answer = CVD_Curru_AI::improve( $question, self::answer( $question, $context ), CVD_Curru_AI::history( $request->get_param( 'history' ) ), self::name(), $context );
		$response = rest_ensure_response( $answer );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** @return array{answer:string, products:array, links:array} */
	public static function answer( string $question, string $context ): array {
		$q = CVD_Product_Search::normalize( $question );
		$urls = self::urls();
		$whatsapp = self::whatsapp_url();
		$reply = static fn( string $answer, array $links = array(), array $products = array() ): array => array( 'answer' => $answer, 'links' => $links, 'products' => $products );
		$help_link = $whatsapp ? array( array( 'label' => 'Escribir por WhatsApp', 'url' => $whatsapp ) ) : array();

		if ( preg_match( '/\b(pedido|orden|estado|seguimiento|donde esta|llega|rastrear)\b/', $q ) ) {
			if ( 'mensajero' === $context ) {
				return $reply( 'Abre tu Ruta para ver solo tus entregas, contactos y cobros.', array( array( 'label' => 'Abrir Ruta', 'url' => $urls['routeUrl'] ) ) );
			}
			return $reply( 'En Mis pedidos ves el estado y el progreso de cada compra. Si algo no cuadra, escríbenos con el número de pedido.', array_merge( array( array( 'label' => 'Mis pedidos', 'url' => $urls['ordersUrl'] ) ), $help_link ) );
		}
		if ( preg_match( '/\b(mensajeria|envio|envios|domicilio|tarifa|tarifas|zona|reparto|municipio|entrega|entregan|llevan)\b/', $q ) ) {
			return self::shipping_answer( $q, $urls );
		}
		if ( preg_match( '/\b(pago|pagar|pagos|transferencia|efectivo|moneda|zelle|paypal|cripto|usd|cup|mlc|tarjeta)\b/', $q ) ) {
			return $reply( 'Al confirmar el pedido verás los importes y las formas de pago aceptadas. Casa Viva nunca convierte monedas sin avisarte. No compartas datos bancarios por chat.', $help_link );
		}
		if ( 'gestora' === $context && preg_match( '/\b(gestora|comision|vale|tienda espejo)\b/', $q ) ) {
			return $reply( 'Desde Gestoras revisas tus pedidos y envías vales. Las asignaciones de mensajería siguen el mecanismo oficial.', array( array( 'label' => 'Abrir Gestoras', 'url' => $urls['managersUrl'] ) ) );
		}
		if ( 'mensajero' === $context && preg_match( '/\b(ruta|cliente|llamar|vuelto|cobro)\b/', $q ) ) {
			return $reply( 'En Ruta tienes contactos, preparación, vuelto, cobro y el asistente de tu jornada.', array( array( 'label' => 'Abrir Ruta', 'url' => $urls['routeUrl'] ) ) );
		}
		if ( preg_match( '/\b(devolver|devolucion|cambio|garantia|problema|reclamo|queja|persona|humano|contacto|whatsapp|llamar)\b/', $q ) ) {
			return $reply( 'Para una incidencia o postventa escríbenos por WhatsApp con el número de pedido y una descripción breve. Te atiende una persona.', $help_link );
		}
		if ( preg_match( '/^(hola|buenas|buenos dias|buenas tardes|buenas noches|hey|saludos)\b/', $q ) && count( CVD_Product_Search::terms( $question ) ) === 0 ) {
			return $reply( '¡Hola! Dime qué buscas (por ejemplo "sartén" o "alfombra de baño") o pregúntame por la mensajería a tu zona.' );
		}
		if ( preg_match( '/\b(buscar productos|que venden|catalogo|productos)\b/', $q ) && count( CVD_Product_Search::terms( preg_replace( '/\b(buscar|productos|catalogo|que venden)\b/', '', $q ) ) ) === 0 ) {
			return $reply( 'Claro. Escribe qué necesitas, por ejemplo "toallas", "licuadora" o "organizador de cocina".', array( array( 'label' => 'Ver toda la tienda', 'url' => $urls['shopUrl'] ) ) );
		}

		$ids = CVD_Product_Search::search( $question, 6 );
		$stock_question = (bool) preg_match( '/\b(quedan?|existencias?|stock|disponib\w*|unidades|cuant[oa]s|modelos?)\b/', $q );
		// «modelo» también es un reparto: una pregunta de existencias no se responde con mensajería.
		if ( ! $ids && ! $stock_question && self::find_place( $q ) ) {
			return self::shipping_answer( $q, $urls );
		}
		if ( $ids ) {
			$exact = self::sees_stock( $context );
			$cards = CVD_Product_Search::cards( $ids, $exact );
			$count = count( $cards );
			if ( $exact && $stock_question ) {
				return $reply( 'Existencias ahora mismo: ' . self::stock_summary( $cards ), array(), $cards );
			}
			return $reply( 1 === $count ? 'Encontré este producto:' : "Encontré {$count} productos que te pueden servir:", array(), $cards );
		}
		return $reply(
			'No encontré eso en la tienda. Prueba con otra palabra o pregúntanos por WhatsApp si lo podemos conseguir.',
			array_merge( array( array( 'label' => 'Ver toda la tienda', 'url' => $urls['shopUrl'] ) ), $help_link )
		);
	}

	/** Gestoras y operación ven las unidades exactas; clientes solo disponible, últimas unidades o agotado. */
	public static function sees_stock( string $context ): bool {
		return in_array( $context, array( 'gestora', 'operacion' ), true );
	}

	/** Resumen breve de existencias por producto y modelo, para gestoras. */
	private static function stock_summary( array $cards ): string {
		$parts = array();
		foreach ( $cards as $card ) {
			$line = $card['name'] . ': ' . mb_strtolower( $card['stockLabel'] );
			if ( ! empty( $card['variants'] ) ) {
				$line .= ' (' . implode( ', ', array_map( static fn( $v ) => $v['name'] . ' ' . ( null === $v['stock'] ? ( $v['inStock'] ? 'disponible' : 'agotado' ) : $v['stock'] ), $card['variants'] ) ) . ')';
			}
			$parts[] = $line;
		}
		return implode( ' · ', $parts ) . '.';
	}

	/** Busca un municipio o reparto oficial mencionado en el texto. */
	private static function find_place( string $q ): ?array {
		if ( ! class_exists( 'CVD_Shipping_Rates' ) ) {
			return null;
		}
		$found = null;
		foreach ( CVD_Shipping_Rates::localities() as $municipality => $zones ) {
			foreach ( $zones as $zone ) {
				$key = CVD_Product_Search::normalize( $zone );
				if ( mb_strlen( $key ) >= 4 && preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/', $q ) && ( ! $found || mb_strlen( $key ) > mb_strlen( (string) $found['zone_key'] ) ) ) {
					$found = array( 'municipality' => $municipality, 'zone' => $zone, 'zone_key' => $key );
				}
			}
			$mkey = CVD_Product_Search::normalize( $municipality );
			if ( ! $found && preg_match( '/\b' . preg_quote( $mkey, '/' ) . '\b/', $q ) ) {
				$found = array( 'municipality' => $municipality, 'zone' => '', 'zone_key' => '' );
			}
		}
		return $found;
	}

	private static function shipping_answer( string $q, array $urls ): array {
		$link = array( array( 'label' => 'Ver tarifas por zona', 'url' => $urls['ratesUrl'] ) );
		$place = self::find_place( $q );
		if ( ! $place ) {
			return array( 'answer' => 'La mensajería depende del municipio y el reparto. Dime, por ejemplo: "mensajería a Vedado, Plaza", o consulta la tabla oficial.', 'links' => $link, 'products' => array() );
		}
		if ( '' === $place['zone'] ) {
			return array( 'answer' => "¿En qué reparto de {$place['municipality']}? La tarifa cambia según el reparto.", 'links' => $link, 'products' => array() );
		}
		$quote = CVD_Shipping_Rates::quote( $place['municipality'], $place['zone'] );
		$where = $place['zone'] . ', ' . $place['municipality'];
		if ( 'zone' === ( $quote['status'] ?? '' ) && ! empty( $quote['fee'] ) ) {
			return array( 'answer' => "La mensajería a {$where} cuesta {$quote['fee']} CUP.", 'links' => $link, 'products' => array() );
		}
		return array( 'answer' => "Esa zona ({$where}) todavía no tiene tarifa fija. Te la confirmamos al hacer el pedido.", 'links' => $link, 'products' => array() );
	}
}
