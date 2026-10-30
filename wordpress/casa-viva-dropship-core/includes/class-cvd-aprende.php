<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Aprende» (3.13.77, boceto aprobado por Ernesto el 13-oct): pestaña de tutoriales en el área de la gestora
 * (app de gestoras) y en la del mensajero. Lista numerada con ▶ (vídeo, nota de voz o guías en imagen, que solo
 * se descargan al tocar), «Marcar como visto» / «✓ Ya lo vi», barra de avance y «Dudas rápidas».
 *
 * Lo visto se guarda por persona en la meta `_cvd_aprende`:
 *   seen => [ id => [ 'at' => ms, 'by' => 'web'|'whatsapp' ] ]   lo que cuenta como visto
 *   sent => [ id => ms ]                                         lo que VivaBot dice que mandó por WhatsApp
 *   off  => [ id => ms ]                                         la persona lo desmarcó en la web
 * Los id son los mismos de VivaBot (src/onboarding-rules.js). Lo que el bot ya mandó por WhatsApp cuenta como
 * visto (así el bot no repite y la web no lo pide otra vez), salvo que la persona lo desmarque DESPUÉS del envío.
 *
 * Puertas:
 *   POST /casa-viva/v1/aprende            (la propia persona, con sesión y nonce wp_rest) {card, role, seen}
 *   GET  /casa-viva/v1/bot/aprende?phone= (VivaBot, X-VivaBot-Key)
 *   POST /casa-viva/v1/bot/aprende        (VivaBot) {phone, sent: {id: ms}} o {phone, ids: [...], at}
 */
final class CVD_Aprende {
	private const META = '_cvd_aprende';
	private const MEDIA_OPTION = 'cvd_aprende_media';
	private const NS = 'casa-viva/v1';
	public const WHATSAPP = 'https://wa.me/5354056173';

	/** Vídeos que ya estaban en la página pública /tutoriales/ (página 2942). El resto, en la opción cvd_aprende_media. */
	private const DEFAULT_MEDIA = array(
		'tutorial-primera-venta'       => 2937,
		'tutorial-dudas-gestoras'      => 2938,
		'tutorial-fotos-para-publicar' => 2939,
		'tutorial-como-comprar'        => 2940,
		'tutorial-mensajero'           => 2941,
	);

	/** Pasos por orden. card = clave de la tarjeta; ids = lo que se guarda (los id de VivaBot). */
	public static function steps( string $role ): array {
		if ( 'mensajero' === $role ) {
			return array(
				array( 'card' => 'tutorial-mensajero', 'title' => 'Cómo funciona una carrera', 'kind' => 'Vídeo', 'type' => 'video', 'media' => array( 'tutorial-mensajero' ), 'ids' => array( 'tutorial-mensajero' ) ),
				array( 'card' => 'guia-mensajeros', 'title' => 'Guía rápida del mensajero', 'kind' => '2 imágenes para guardar', 'type' => 'images', 'media' => array( 'guia-mensajeros-1', 'guia-mensajeros-2' ), 'ids' => array( 'guia-mensajeros' ) ),
			);
		}
		return array(
			array( 'card' => 'tutorial-primera-venta', 'title' => 'Tu primera venta, paso a paso', 'kind' => 'Vídeo', 'type' => 'video', 'media' => array( 'tutorial-primera-venta' ), 'ids' => array( 'tutorial-primera-venta' ) ),
			array( 'card' => 'tutorial-tu-precio', 'title' => 'Cómo poner tu precio', 'kind' => 'Vídeo', 'type' => 'video', 'media' => array( 'tutorial-tu-precio' ), 'ids' => array( 'tutorial-tu-precio' ) ),
			array( 'card' => 'tutorial-como-comprar', 'title' => 'Cómo compra tu cliente', 'kind' => 'Vídeo · para enseñárselo a tus clientes', 'type' => 'video', 'media' => array( 'tutorial-como-comprar' ), 'ids' => array( 'tutorial-como-comprar' ) ),
			array( 'card' => 'tutorial-fotos-para-publicar', 'title' => 'Fotos para publicar', 'kind' => 'Vídeo', 'type' => 'video', 'media' => array( 'tutorial-fotos-para-publicar' ), 'ids' => array( 'tutorial-fotos-para-publicar' ) ),
			// 3.14.36 (Ernesto 17-oct): las herramientas nuevas, para que la bienvenida pueda mandar aquí.
			array( 'card' => 'tutorial-estudio-fotos', 'title' => 'Estudio de fotos: fotos bonitas gratis', 'kind' => 'Vídeo · Herramientas', 'type' => 'video', 'media' => array( 'tutorial-estudio-fotos' ), 'ids' => array( 'tutorial-estudio-fotos' ) ),
			// 3.14.93 (Ernesto, 30-oct): la guía del «Taller de fotos» queda aquí para consultarla cuando quieran.
			array( 'card' => 'guia-taller-fotos', 'title' => 'Taller de fotos: tu foto y tu texto listos', 'kind' => '5 imágenes para guardar · Herramientas', 'type' => 'images', 'media' => array( 'guia-taller-1', 'guia-taller-2', 'guia-taller-3', 'guia-taller-4', 'guia-taller-5' ), 'ids' => array( 'guia-taller-fotos' ) ),
			array( 'card' => 'tutorial-nexo-busca', 'title' => 'NEXO Busca: vende lo que no tenemos', 'kind' => 'Vídeo · Herramientas', 'type' => 'video', 'media' => array( 'tutorial-nexo-busca' ), 'ids' => array( 'tutorial-nexo-busca' ) ),
			array( 'card' => 'tutorial-dudas-gestoras', 'title' => 'Tus dudas del primer día', 'kind' => 'Vídeo', 'type' => 'video', 'media' => array( 'tutorial-dudas-gestoras' ), 'ids' => array( 'tutorial-dudas-gestoras' ) ),
			array( 'card' => 'audio-como-funciona-pedidos', 'title' => 'Cómo funcionan los pedidos', 'kind' => 'Nota de voz de Ernesto', 'type' => 'audio', 'media' => array( 'audio-como-funciona-pedidos-ogg', 'audio-como-funciona-pedidos-mp3' ), 'ids' => array( 'audio-como-funciona-pedidos' ) ),
			array( 'card' => 'guias', 'title' => 'Guías rápidas', 'kind' => '4 imágenes para guardar', 'type' => 'images', 'media' => array( 'guia-gestoras-1', 'guia-gestoras-2', 'guia-gestoras-3', 'guia-gestoras-4' ), 'ids' => array( 'guia-gestoras-1-2', 'guia-gestoras-3-4' ) ),
		);
	}

	/** «Dudas rápidas» (gestora) y «Lo que escribes» (mensajero), tal cual el boceto. *texto* = negrita. */
	private static function topics( string $role ): array {
		if ( 'mensajero' === $role ) {
			return array(
				'yo'        => array( 'Yo', 'Cuando sale una carrera en el grupo de mensajeros, escribe solo *Yo*. Si hay varias, *Yo #número*. El primero se la lleva y le llega el vale por privado, con fotos.' ),
				'hora'      => array( 'La hora', 'Responde al vale solo con la hora a la que vas, por ejemplo «2:30-3 pm». El bot se la pasa a quien vendió.' ),
				'pasar'     => array( 'Pasar', 'Si la hace otro compañero, escribe «pásasela a Pedri» o «pasar #número a Pedri». Desde ese momento es suya.' ),
				'suelto'    => array( 'Suelto', 'Si al final no puedes, escribe *suelto* cuanto antes y la carrera pasa a otro mensajero.' ),
				'entregado' => array( 'Entregado', 'Al entregar, escribe *entregado #número*. Así queda cerrada en el sistema.' ),
				'armado'    => array( 'Armado', 'Si el vale dice «OJO hay que armar», el precio del armado es para ti. Si no lo vas a armar, no cojas esa carrera.' ),
			);
		}
		return array(
			'entrar'     => array( 'Entrar', 'Escribe *mi acceso* aquí en WhatsApp y te llega un enlace para entrar sin contraseña. Ese enlace sirve una sola vez.' ),
			'enlace'     => array( 'Mi enlace', 'Escribe *mi enlace*. Todo lo que compren tus clientes entrando por ahí queda a tu nombre y suma a tu comisión.' ),
			'comision'   => array( 'Mi comisión', 'Ganas el 10 % del precio Casa Viva, o la comisión fija que tenga el producto. Se aprueba cuando el pedido se entrega. Escribe *mi comisión* para ver la tuya.' ),
			'cobrar'     => array( 'Cobrar', 'La cobras como prefieras: en USD o en CUP, en efectivo o por transferencia, sin mínimo. En CUP se calcula con la tasa del día de la venta.' ),
			'mensajeria' => array( 'Mensajería', 'No tienes que buscar mensajero: el pedido sale solo al grupo de mensajeros y uno lo coge. Si quieres uno en concreto, escribe en la nota *mensajero: Nombre*.' ),
		);
	}

	/** Todos los id que se aceptan (los de las tarjetas + la bienvenida del bot, que se guarda pero no se enseña). */
	public static function known_ids(): array {
		$ids = array( 'bienvenida-voz' );
		foreach ( array( 'gestora', 'mensajero' ) as $role ) {
			foreach ( self::steps( $role ) as $step ) { $ids = array_merge( $ids, $step['ids'] ); }
		}
		return array_values( array_unique( $ids ) );
	}

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 45 );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/aprende', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_toggle' ), 'permission_callback' => static fn(): bool => is_user_logged_in() ) );
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/aprende', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_get' ), 'permission_callback' => $bot ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_post' ), 'permission_callback' => $bot ),
		) );
	}

	public static function assets(): void {
		if ( ! is_user_logged_in() || ! is_page( array( 'area-gestoras', 'area-mensajeros', 'ruta-cv' ) ) ) { return; }
		wp_enqueue_style( 'cvd-aprende', CVD_URL . 'assets/aprende.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-aprende', CVD_URL . 'assets/aprende.js', array(), CVD_VERSION, true );
	}

	/* ------------------------------------------------------------------ estado */

	private static function now_ms(): int {
		return (int) round( microtime( true ) * 1000 );
	}

	public static function state( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META, true );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'seen' => is_array( $raw['seen'] ?? null ) ? $raw['seen'] : array(),
			'sent' => is_array( $raw['sent'] ?? null ) ? $raw['sent'] : array(),
			'off'  => is_array( $raw['off'] ?? null ) ? $raw['off'] : array(),
		);
	}

	private static function save( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::META, $state );
	}

	/** Cada tarjeta del rol: vista (todos sus id vistos) y cuántos de sus id mandó el bot por WhatsApp. */
	public static function cards( int $user_id, string $role ): array {
		$state = self::state( $user_id );
		$out = array();
		foreach ( self::steps( $role ) as $step ) {
			$seen = 0; $sent = 0;
			foreach ( $step['ids'] as $id ) {
				if ( isset( $state['seen'][ $id ] ) ) { $seen++; }
				if ( isset( $state['sent'][ $id ] ) ) { $sent++; }
			}
			$out[ $step['card'] ] = array( 'done' => $seen === count( $step['ids'] ), 'sent' => $sent, 'of' => count( $step['ids'] ) );
		}
		return $out;
	}

	/** { done, total, next } del rol. next = la primera tarjeta sin ver (o ''). */
	public static function progress( int $user_id, string $role ): array {
		$cards = self::cards( $user_id, $role );
		$done = count( array_filter( $cards, static fn( array $c ): bool => $c['done'] ) );
		$next = '';
		foreach ( $cards as $key => $c ) { if ( ! $c['done'] ) { $next = $key; break; } }
		return array( 'done' => $done, 'total' => count( $cards ), 'next' => $next );
	}

	public static function finished( int $user_id, string $role ): bool {
		$p = self::progress( $user_id, $role );
		return $p['total'] > 0 && $p['done'] >= $p['total'];
	}

	/** Marca o desmarca una tarjeta desde la web (la propia persona). */
	public static function set_card( int $user_id, string $role, string $card, bool $seen ): bool {
		$step = null;
		foreach ( self::steps( $role ) as $s ) { if ( $s['card'] === $card ) { $step = $s; break; } }
		if ( ! $step ) { return false; }
		$state = self::state( $user_id );
		$now = self::now_ms();
		foreach ( $step['ids'] as $id ) {
			if ( $seen ) {
				if ( ! isset( $state['seen'][ $id ] ) ) { $state['seen'][ $id ] = array( 'at' => $now, 'by' => 'web' ); }
				unset( $state['off'][ $id ] );
			} else {
				unset( $state['seen'][ $id ] );
				$state['off'][ $id ] = $now;
			}
		}
		self::save( $user_id, $state );
		return true;
	}

	/**
	 * Lo que VivaBot mandó por WhatsApp ({ id: ms }). Cuenta como visto, salvo que la persona lo desmarcara en la
	 * web después de ese envío. Devuelve los id que no se conocen (no se guardan).
	 */
	public static function mark_sent( int $user_id, array $sent ): array {
		$known = array_flip( self::known_ids() );
		$state = self::state( $user_id );
		$ignored = array();
		foreach ( $sent as $id => $at ) {
			$id = sanitize_key( (string) $id );
			if ( ! isset( $known[ $id ] ) ) { $ignored[] = $id; continue; }
			$at = self::to_ms( $at );
			$state['sent'][ $id ] = max( (int) ( $state['sent'][ $id ] ?? 0 ), $at );
			$off = (int) ( $state['off'][ $id ] ?? 0 );
			if ( ! isset( $state['seen'][ $id ] ) && $off < $at ) {
				$state['seen'][ $id ] = array( 'at' => $at, 'by' => 'whatsapp' );
				unset( $state['off'][ $id ] );
			}
		}
		self::save( $user_id, $state );
		return $ignored;
	}

	/** ms, segundos o fecha ISO → ms (nunca en el futuro; vacío = ahora). */
	private static function to_ms( $value ): int {
		$now = self::now_ms();
		if ( is_numeric( $value ) ) {
			$n = (float) $value;
			$ms = $n > 100000000000 ? (int) $n : (int) ( $n * 1000 );
		} elseif ( is_string( $value ) && '' !== trim( $value ) && false !== strtotime( $value ) ) {
			$ms = (int) strtotime( $value ) * 1000;
		} else {
			$ms = $now;
		}
		return $ms > 0 && $ms <= $now + 300000 ? min( $ms, $now ) : $now;
	}

	/* ------------------------------------------------------------------ REST */

	/** La propia persona marca o desmarca una tarjeta. Solo su cuenta y solo un rol que tenga. */
	public static function rest_toggle( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		$role = 'mensajero' === sanitize_key( (string) $request->get_param( 'role' ) ) ? 'mensajero' : 'gestora';
		if ( ! $user->exists() || ! class_exists( 'CVD_Registration' ) || ! CVD_Registration::has_program_account( $user, $role ) ) {
			return new WP_Error( 'cvd_aprende_forbidden', 'Esta cuenta no tiene esa área.', array( 'status' => 403 ) );
		}
		$card = sanitize_key( (string) $request->get_param( 'card' ) );
		$seen = rest_sanitize_boolean( $request->get_param( 'seen' ) );
		if ( ! self::set_card( $user->ID, $role, $card, $seen ) ) {
			return new WP_Error( 'cvd_aprende_card', 'Ese paso no existe.', array( 'status' => 422 ) );
		}
		$response = rest_ensure_response( array( 'ok' => true, 'card' => $card, 'cards' => self::cards( $user->ID, $role ), 'progress' => self::progress( $user->ID, $role ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Gestora o mensajero por su WhatsApp (últimos 8 dígitos). Si hay varias cuentas, la aprobada y más nueva. */
	private static function user_by_phone( string $phone ): ?WP_User {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( strlen( $digits ) < 8 ) { return null; }
		$key = substr( $digits, -8 );
		$found = array();
		foreach ( get_users( array( 'role__in' => array( 'cvd_gestora', 'cvd_messenger' ), 'orderby' => 'registered', 'order' => 'DESC', 'fields' => 'all_with_meta' ) ) as $user ) {
			// Solo su WhatsApp; el teléfono de facturación solo si no tiene WhatsApp (igual que CVD_Bot_Bridge).
			$own = preg_replace( '/\D+/', '', (string) get_user_meta( $user->ID, '_cvd_whatsapp', true ) );
			$src = strlen( $own ) >= 8 ? $own : preg_replace( '/\D+/', '', (string) get_user_meta( $user->ID, 'billing_phone', true ) );
			if ( strlen( $src ) >= 8 && substr( $src, -8 ) === $key ) { $found[] = $user; }
		}
		foreach ( $found as $user ) {
			if ( CVD_Registration::is_approved_gestora( $user ) || CVD_Registration::is_approved_messenger( $user ) ) { return $user; }
		}
		return $found[0] ?? null;
	}

	/** Respuesta común del bot: lo visto (con quién lo marcó), lo enviado y el avance por área. */
	private static function bot_payload( WP_User $user, array $extra = array() ): WP_REST_Response {
		$state = self::state( $user->ID );
		$programs = CVD_Registration::programs( $user );
		$progress = array();
		foreach ( $programs as $role ) { $progress[ $role ] = self::progress( $user->ID, $role ) + array( 'cards' => self::cards( $user->ID, $role ) ); }
		$response = rest_ensure_response( array(
			'found'    => true,
			'userId'   => $user->ID,
			'programs' => $programs,
			'seen'     => (object) $state['seen'],
			'sent'     => (object) $state['sent'],
			'progress' => (object) $progress,
		) + $extra );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function bot_get( WP_REST_Request $request ) {
		$user = self::user_by_phone( (string) $request->get_param( 'phone' ) );
		if ( ! $user ) { return rest_ensure_response( array( 'found' => false ) ); }
		return self::bot_payload( $user );
	}

	/** VivaBot avisa de lo que ya mandó por WhatsApp: { phone, sent: { id: ms } } o { phone, ids: [id…], at }. */
	public static function bot_post( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		$user = self::user_by_phone( (string) ( $params['phone'] ?? '' ) );
		if ( ! $user ) { return rest_ensure_response( array( 'found' => false ) ); }
		$sent = array();
		if ( isset( $params['sent'] ) && is_array( $params['sent'] ) ) {
			foreach ( $params['sent'] as $id => $at ) { $sent[ (string) $id ] = $at; }
		}
		if ( isset( $params['ids'] ) && is_array( $params['ids'] ) ) {
			foreach ( $params['ids'] as $id ) { if ( is_scalar( $id ) ) { $sent[ (string) $id ] = $params['at'] ?? ''; } }
		}
		if ( ! $sent ) { return new WP_Error( 'cvd_aprende_empty', 'Manda sent: {id: ms} o ids: [...].', array( 'status' => 422 ) ); }
		$ignored = self::mark_sent( $user->ID, array_slice( $sent, 0, 50, true ) );
		return self::bot_payload( $user, array( 'ignored' => $ignored ) );
	}

	/* ------------------------------------------------------------------ medios */

	/** URL de un medio por su clave (opción cvd_aprende_media → id de adjunto; los vídeos tienen id por defecto). */
	private static function media_url( string $key ): string {
		$map = get_option( self::MEDIA_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		$id = absint( $map[ $key ] ?? ( self::DEFAULT_MEDIA[ $key ] ?? 0 ) );
		$url = $id ? wp_get_attachment_url( $id ) : '';
		return $url ? (string) $url : '';
	}

	/** Lo que necesita aprende.js para montar el reproductor al tocar ▶ (nada se descarga antes). */
	private static function media_for( array $step ): array {
		if ( 'video' === $step['type'] ) {
			$src = self::media_url( $step['media'][0] );
			return $src ? array( 'type' => 'video', 'src' => $src ) : array();
		}
		if ( 'audio' === $step['type'] ) {
			$sources = array();
			foreach ( $step['media'] as $key ) {
				$src = self::media_url( $key );
				if ( $src ) { $sources[] = array( 'src' => $src, 'type' => str_ends_with( $key, '-mp3' ) ? 'audio/mpeg' : 'audio/ogg' ); }
			}
			return $sources ? array( 'type' => 'audio', 'sources' => $sources ) : array();
		}
		$imgs = array();
		foreach ( $step['media'] as $n => $key ) {
			$src = self::media_url( $key );
			if ( $src ) { $imgs[] = array( 'src' => $src, 'alt' => $step['title'] . ' (' . ( $n + 1 ) . ' de ' . count( $step['media'] ) . ')', 'name' => $key . '.png' ); }
		}
		return $imgs ? array( 'type' => 'images', 'imgs' => $imgs ) : array();
	}

	/* ------------------------------------------------------------------ pantalla */

	private static function bold( string $text ): string {
		return (string) preg_replace( '/\*([^*]+)\*/u', '<strong>$1</strong>', esc_html( $text ) );
	}

	private static function first_name( WP_User $user ): string {
		$first = strtok( trim( (string) $user->display_name ), ' ' );
		return $first ?: (string) $user->display_name;
	}

	private static function kind_line( array $step, array $card ): string {
		$line = $step['kind'];
		if ( $card['sent'] >= $card['of'] ) {
			$line .= ' · enviado por WhatsApp';
		} elseif ( $card['sent'] > 0 ) {
			$line .= ' · ' . ( 2 * $card['sent'] ) . ' enviadas por WhatsApp'; // solo pasa en las guías (2 imágenes por envío)
		}
		return $line;
	}

	/**
	 * La pestaña. $role = gestora (dentro de la app de gestoras: vista data-view="aprende") o mensajero (sección
	 * #aprende del área del mensajero; messenger-premium-ux.js la pone en su propia pantalla).
	 */
	public static function render( WP_User $user, string $role ): string {
		$role = 'mensajero' === $role ? 'mensajero' : 'gestora';
		$steps = self::steps( $role );
		$cards = self::cards( $user->ID, $role );
		$progress = self::progress( $user->ID, $role );
		$pct = $progress['total'] ? (int) round( $progress['done'] / $progress['total'] * 100 ) : 0;
		$topics = self::topics( $role );
		$is_gestora = 'gestora' === $role;
		$attrs = $is_gestora
			? 'class="cvga-view cva cva--gestora" data-view="aprende" id="cvga-aprende"'
			: 'class="cva cva--mensajero" id="aprende"';
		ob_start();
		?>
		<section <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texto fijo. ?> data-cva data-cva-role="<?php echo esc_attr( $role ); ?>" data-cva-done="<?php echo $progress['done'] >= $progress['total'] ? '1' : '0'; ?>" data-endpoint="<?php echo esc_url( rest_url( self::NS . '/aprende' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
			<header class="cva-hero">
				<div class="cva-hero-top"><span class="cva-area"><?php echo esc_html( $is_gestora ? 'MI ÁREA DE GESTOR/A' : 'ÁREA DEL MENSAJERO' ); ?></span><span class="cva-hello"><?php echo esc_html( 'Hola, ' . self::first_name( $user ) ); ?></span></div>
				<h1 class="cva-h1">APRENDE</h1>
				<p class="cva-intro"><?php echo esc_html( $is_gestora ? 'Todo para empezar a vender, a tu ritmo. Toca ▶ para verlo y marca lo que ya viste.' : 'Cómo coger una carrera, dar la hora y cerrarla bien. Son dos minutos.' ); ?></p>
				<div class="cva-progress">
					<div class="cva-progress-text"><span data-cva-count><?php echo esc_html( $progress['done'] . ' de ' . $progress['total'] . ' vistos' ); ?></span><span data-cva-pct><?php echo esc_html( $pct . ' %' ); ?></span></div>
					<div class="cva-bar" role="progressbar" aria-label="Vistos" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $progress['total'] ); ?>" aria-valuenow="<?php echo esc_attr( (string) $progress['done'] ); ?>"><i data-cva-bar style="width:<?php echo esc_attr( (string) $pct ); ?>%"></i></div>
				</div>
			</header>

			<div class="cva-list">
				<?php foreach ( $steps as $i => $step ) :
					$card = $cards[ $step['card'] ];
					$done = $card['done'];
					$next = $step['card'] === $progress['next'];
					$media = self::media_for( $step );
					$panel = 'cva-m-' . $role . '-' . $step['card'];
					?>
				<article class="cva-card<?php echo $next ? ' is-next' : ''; ?><?php echo $done ? ' is-done' : ''; ?>" data-cva-card="<?php echo esc_attr( $step['card'] ); ?>" data-done="<?php echo $done ? '1' : '0'; ?>" data-num="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
					<div class="cva-row">
						<div class="cva-num" aria-hidden="true"><?php echo esc_html( $done ? '✓' : (string) ( $i + 1 ) ); ?></div>
						<div class="cva-body">
							<div class="cva-titleline"><span class="cva-title"><?php echo esc_html( $step['title'] ); ?></span><span class="cva-next" data-cva-next<?php echo $next ? '' : ' hidden'; ?>>Te toca</span></div>
							<span class="cva-kind"><?php echo esc_html( self::kind_line( $step, $card ) ); ?></span>
							<button class="cva-seen" type="button" data-cva-toggle aria-pressed="<?php echo $done ? 'true' : 'false'; ?>"><?php echo esc_html( $done ? '✓ Ya lo vi' : 'Marcar como visto' ); ?></button>
						</div>
						<?php if ( $media ) : ?>
						<button class="cva-play" type="button" data-cva-play aria-expanded="false" aria-controls="<?php echo esc_attr( $panel ); ?>" aria-label="<?php echo esc_attr( 'Ver «' . $step['title'] . '»' ); ?>"><svg class="cva-ic-play" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M8 5 L19 12 L8 19 Z"></path></svg><svg class="cva-ic-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
						<?php else : ?>
						<span class="cva-soon">Pronto</span>
						<?php endif; ?>
					</div>
					<?php if ( $media ) : ?>
					<div class="cva-media" id="<?php echo esc_attr( $panel ); ?>" data-cva-media="<?php echo esc_attr( (string) wp_json_encode( $media ) ); ?>" hidden></div>
					<?php endif; ?>
				</article>
				<?php endforeach; ?>
				<p class="cva-status" data-cva-status role="status" aria-live="polite" hidden></p>
			</div>

			<div class="cva-faq">
				<h2 class="cva-h2"><?php echo esc_html( $is_gestora ? 'DUDAS RÁPIDAS' : 'LO QUE ESCRIBES' ); ?></h2>
				<div class="cva-chips">
					<?php foreach ( $topics as $key => $topic ) : ?>
					<button class="cva-chip" type="button" data-cva-topic="<?php echo esc_attr( $key ); ?>" aria-expanded="false" aria-controls="cva-a-<?php echo esc_attr( $role . '-' . $key ); ?>"><?php echo esc_html( $topic[0] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $topics as $key => $topic ) : ?>
				<div class="cva-answer" id="cva-a-<?php echo esc_attr( $role . '-' . $key ); ?>" data-cva-answer="<?php echo esc_attr( $key ); ?>" hidden><?php echo self::bold( $topic[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html dentro de bold(). ?></div>
				<?php endforeach; ?>
				<p class="cva-help"><?php echo esc_html( $is_gestora ? '¿No está tu duda?' : '¿Otra duda?' ); ?> <a href="<?php echo esc_url( self::WHATSAPP ); ?>" target="_blank" rel="noopener">Escríbele al asistente por WhatsApp</a>.</p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
