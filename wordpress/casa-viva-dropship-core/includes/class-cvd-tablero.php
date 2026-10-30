<?php
defined( 'ABSPATH' ) || exit;
/**
 * Tablero único del móvil (3.14.97, D126 frente 2 y D127, Ernesto 30-oct).
 *
 * /tablero/ enseña a cada persona un «semáforo del día» y solo lo que le toca mirar. SOLO LEE: no cambia pedidos,
 * dinero ni mensajes; las acciones se hacen en sus páginas (Ventas, panel, bandeja de decisiones).
 * - Acceso: enlace personal firmado (id.caducidad.firma, 30 días, como Pa'llá). Al abrirlo se cambia por una cookie
 *   HttpOnly y la dirección queda limpia. Subir la «época» del usuario (revoke()) anula todos sus enlaces.
 *   También entra con la sesión de WordPress si la persona tiene rol en la configuración.
 * - Rol: sale de la opción cvd_negocio['personas'] (usuario → rol), nunca del enlace.
 * - Multi-negocio: todo lo propio del negocio (nombre, zona horaria, umbrales, personas, enlaces) va en cvd_negocio.
 * - Datos de Core: se calculan al pedirlos (caché de 60 s). Datos del VPS (errores del bot, salud, mensajes): llegan
 *   cada 3 min por POST /bot/tablero/resumen con la llave del bot; si son viejos se dice, nunca se enseña un cero falso.
 * - GET /bot/atrasos: la regla de atraso vive aquí y el bot la lee (acordado con la sesión «menos mensajes»).
 * Interruptor: opción cvd_tablero = si | no. Generar un enlace: wp eval 'echo CVD_Tablero::link_for( ID );'
 */
final class CVD_Tablero {
	public const OPTION = 'cvd_tablero';
	public const CONFIG = 'cvd_negocio';
	public const VPS = 'cvd_tablero_vps';
	public const ACCESS = 'cvd_tablero_accesos';
	public const EPOCH_META = '_cvd_tablero_epoch';
	private const COOKIE = 'cvd_tablero';
	private const TTL = 30 * DAY_IN_SECONDS;
	private const NS = 'casa-viva/v1';
	private const OPEN = array( 'pending', 'processing', 'on-hold' );
	private const VIEWS = array( 'dueno', 'administracion' ); // Etapa 1
	private const MAX_VPS_BYTES = 300000;

	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function enabled(): bool { return 'si' === sanitize_key( (string) get_option( self::OPTION, 'no' ) ); }

	/* ---------- configuración del negocio ---------- */

	public static function config(): array {
		$saved = get_option( self::CONFIG, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$defaults = array(
			'business_id'  => '',
			'nombre'       => (string) get_bloginfo( 'name' ),
			'zona_horaria' => wp_timezone_string(),
			'hora_cierre'  => '17:30',
			'umbrales'     => array(),
			'personas'     => array(),
			'enlaces'      => array(),
			'cierre_rol'   => 'cvd_clerk',
			'decisiones_archivo' => dirname( ABSPATH ) . '/control-private/bandeja.json',
		);
		$cfg = array_merge( $defaults, $saved );
		$cfg['umbrales'] = array_merge( array(
			'atascada_hora' => '11:00', // carrera de ayer sin recoger a partir de esta hora (como el bot)
			'atraso_rojo_h' => 24,
			'sin_mensajero_h' => 3,
			'calle_h' => 24,
			'calle_rojo_h' => 48,
			'comision_h' => 24,
			'vps_viejo_min' => 10,
			'senal_mensajes_mensajero' => 3,
		), is_array( $saved['umbrales'] ?? null ) ? $saved['umbrales'] : array() );
		$cfg['enlaces'] = array_merge( array(
			'ventas' => home_url( '/ventas/' ),
			'panel' => home_url( '/panel/' ),
			'decisiones' => home_url( '/control/decisiones/' ),
			'centro' => home_url( '/control/' ),
		), is_array( $saved['enlaces'] ?? null ) ? $saved['enlaces'] : array() );
		return $cfg;
	}

	public static function role_of( int $user_id ): string {
		$people = (array) self::config()['personas'];
		$role = sanitize_key( (string) ( $people[ $user_id ] ?? $people[ (string) $user_id ] ?? '' ) );
		return in_array( $role, self::VIEWS, true ) ? $role : '';
	}

	private static function tz(): DateTimeZone {
		try { return new DateTimeZone( (string) self::config()['zona_horaria'] ); } catch ( Exception $e ) { return wp_timezone(); }
	}

	private static function local( int $ts, string $format ): string { return wp_date( $format, $ts, self::tz() ); }

	private static function utc_ts( string $mysql ): int { return '' === $mysql ? 0 : (int) strtotime( $mysql . ' UTC' ); }

	/* ---------- enlace firmado ---------- */

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-tablero'; }

	private static function epoch( int $user_id ): int { return absint( get_user_meta( $user_id, self::EPOCH_META, true ) ); }

	private static function signature( int $user_id, int $expires, int $epoch ): string {
		return substr( hash_hmac( 'sha256', 'cvd-tablero|' . $user_id . '|' . $expires . '|' . $epoch, self::key() ), 0, 32 );
	}

	public static function token_for( int $user_id, ?int $now = null ): string {
		$expires = ( $now ?? time() ) + self::TTL;
		return $user_id . '.' . $expires . '.' . self::signature( $user_id, $expires, self::epoch( $user_id ) );
	}

	/** Devuelve el usuario del token, o 0 si no vale, caducó, se revocó o la persona ya no tiene rol. */
	public static function verify( string $token, ?int $now = null ): int {
		if ( ! preg_match( '/^(\d{1,10})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) ) { return 0; }
		$user_id = (int) $m[1];
		if ( (int) $m[2] < ( $now ?? time() ) || '' === self::role_of( $user_id ) ) { return 0; }
		return hash_equals( self::signature( $user_id, (int) $m[2], self::epoch( $user_id ) ), $m[3] ) ? $user_id : 0;
	}

	public static function link_for( int $user_id ): string {
		return '' === self::role_of( $user_id ) ? '' : add_query_arg( 'k', self::token_for( $user_id ), home_url( '/tablero/' ) );
	}

	public static function revoke( int $user_id ): void { update_user_meta( $user_id, self::EPOCH_META, self::epoch( $user_id ) + 1 ); }

	private static function token_expires( string $token ): int { return preg_match( '/^\d+\.(\d+)\./', $token, $m ) ? (int) $m[1] : 0; }

	/** Quién mira: cookie del enlace o sesión de WordPress con rol. */
	private static function viewer(): int {
		$token = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		$user_id = '' !== $token ? self::verify( $token ) : 0;
		if ( $user_id ) { return $user_id; }
		$wp = get_current_user_id();
		return $wp && '' !== self::role_of( $wp ) ? $wp : 0;
	}

	/* ---------- rutas ---------- */

	private static function path_kind( string $uri ): string {
		$path = '/' . trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) { $path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' ); }
		$map = array( '/tablero' => 'page', '/tablero/datos' => 'data', '/tablero/sw.js' => 'sw', '/tablero/manifest.webmanifest' => 'manifest' );
		return $map[ $path ] ?? '';
	}

	public static function route(): void {
		$kind = self::path_kind( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $kind ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Tablero: datos privados' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( 'sw' === $kind ) { self::service_worker(); }
		if ( 'manifest' === $kind ) { self::manifest(); }
		if ( ! self::enabled() ) { self::closed(); }
		if ( 'data' === $kind ) { self::data_request(); }
		self::page();
	}

	private static function closed(): void {
		nocache_headers();
		status_header( 503 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Tablero</title></head><body style="font:16px Helvetica,Arial,sans-serif;padding:24px"><p>El tablero está apagado ahora mismo.</p></body></html>';
		exit;
	}

	private static function asset( string $file ): string { return CVD_URL . 'assets/' . $file . '?v=' . rawurlencode( CVD_VERSION ); }

	private static function page(): void {
		nocache_headers();
		$token = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el token firmado es la credencial.
		if ( '' !== $token ) {
			$user_id = self::verify( $token );
			if ( $user_id ) {
				setcookie( self::COOKIE, $token, array( 'expires' => self::token_expires( $token ), 'path' => '/tablero', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
				self::log_access( $user_id, 'enlace' );
			}
			wp_safe_redirect( home_url( '/tablero/' ), 302 );
			exit;
		}
		$user_id = self::viewer();
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( ! $user_id ) {
			status_header( 403 );
			echo self::shell( '<main class="tb-solo"><div class="tb-logo" aria-hidden="true">' . self::logo_svg() . '</div><h1>Este tablero es personal</h1><p>Abre el enlace que te dieron. Si ya no funciona, pide uno nuevo.</p></main>', false );
			exit;
		}
		self::log_access( $user_id, 'vista' );
		$boot = array( 'datos' => home_url( '/tablero/datos' ), 'sw' => home_url( '/tablero/sw.js' ), 'scope' => home_url( '/tablero/' ), 'version' => CVD_VERSION );
		$body = '<div id="tb-carga" class="tb-carga" role="status"><div class="tb-logo" aria-hidden="true">' . self::logo_svg() . '</div><p>Cargando el tablero</p></div>'
			. '<div id="tb" class="tb-wrap" hidden></div>'
			. '<noscript><main class="tb-solo"><p>El tablero necesita JavaScript.</p></main></noscript>'
			. '<script>window.TABLERO=' . wp_json_encode( $boot ) . ';</script>'
			. '<script src="' . esc_url( self::asset( 'tablero.js' ) ) . '" defer></script>';
		echo self::shell( $body, true );
		exit;
	}

	private static function logo_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19V5l14 14V5"/></svg>';
	}

	private static function shell( string $body, bool $app ): string {
		return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#1D3B5C"><title>Tablero del día</title>'
			. ( $app ? '<link rel="manifest" href="' . esc_url( home_url( '/tablero/manifest.webmanifest' ) ) . '">' : '' )
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'tablero.css' ) ) . '">'
			. '</head><body>' . $body . '</body></html>';
	}

	private static function manifest(): void {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		$icon = class_exists( 'CVD_PWA' ) ? CVD_PWA::icon_url( 'icon-192.png' ) : '';
		echo wp_json_encode( array(
			'name' => 'Tablero · ' . self::config()['nombre'], 'short_name' => 'Tablero', 'start_url' => home_url( '/tablero/' ), 'scope' => home_url( '/tablero/' ),
			'display' => 'standalone', 'background_color' => '#F4F2EE', 'theme_color' => '#1D3B5C', 'lang' => 'es',
			'icons' => $icon ? array( array( 'src' => $icon, 'sizes' => '192x192', 'type' => 'image/png' ) ) : array(),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/** Guarda la página y sus archivos para abrir sin conexión; los datos nunca se guardan aquí (los guarda la página). */
	private static function service_worker(): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: /tablero/' );
		$cache = 'tablero-' . preg_replace( '/[^0-9a-z.]/i', '', CVD_VERSION );
		$shell = array( home_url( '/tablero/' ), self::asset( 'tablero.js' ), self::asset( 'tablero.css' ) );
		echo "const C=" . wp_json_encode( $cache ) . ",S=" . wp_json_encode( $shell, JSON_UNESCAPED_SLASHES ) . ";\n"
			. "self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(S)).catch(()=>{}));self.skipWaiting();});\n"
			. "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(n=>n.startsWith('tablero-')&&n!==C).map(n=>caches.delete(n)))).then(()=>self.clients.claim()));});\n"
			. "self.addEventListener('fetch',e=>{const r=e.request;if(r.method!=='GET'||r.url.indexOf('/tablero/datos')>-1)return;if(!S.some(u=>r.url.split('?')[0]===u.split('?')[0]))return;"
			. "e.respondWith(fetch(r).then(x=>{if(x.ok){const y=x.clone();caches.open(C).then(c=>c.put(r,y));}return x;}).catch(()=>caches.match(r,{ignoreSearch:true})));});\n";
		exit;
	}

	/** POST /tablero/datos {ver?, hash?}. POST para que ninguna caché del hosting guarde datos privados. */
	private static function data_request(): void {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: private, no-store' );
		$fail = static function ( int $status, string $code, string $message ): void {
			status_header( $status );
			echo wp_json_encode( array( 'ok' => false, 'code' => $code, 'message' => $message ), JSON_UNESCAPED_UNICODE );
			exit;
		};
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '1' !== (string) ( $_SERVER['HTTP_X_TABLERO'] ?? '' ) ) { $fail( 405, 'metodo', 'Solicitud no válida.' ); }
		$user_id = self::viewer();
		if ( ! $user_id ) { $fail( 403, 'acceso', 'Tu enlace caducó o ya no vale. Pide uno nuevo.' ); }
		$hits_key = 'cvd_tb_hits_' . $user_id . '_' . gmdate( 'YmdHi' );
		$hits = (int) get_transient( $hits_key );
		if ( $hits >= 30 ) { $fail( 429, 'despacio', 'Demasiadas actualizaciones seguidas. Espera un minuto.' ); }
		set_transient( $hits_key, $hits + 1, 2 * MINUTE_IN_SECONDS );
		$raw = file_get_contents( 'php://input', false, null, 0, 2001 );
		$in = json_decode( (string) $raw, true );
		$in = is_array( $in ) ? $in : array();
		$role = self::role_of( $user_id );
		$as = sanitize_key( (string) ( $in['ver'] ?? '' ) );
		if ( 'dueno' === $role && in_array( $as, self::VIEWS, true ) ) { $role = $as; }
		$out = self::view( $role, $user_id );
		$hash = substr( md5( wp_json_encode( $out ) ), 0, 16 );
		if ( (string) ( $in['hash'] ?? '' ) === $hash ) {
			echo wp_json_encode( array( 'ok' => true, 'igual' => true, 'hash' => $hash, 'visto' => self::local( time(), 'H:i' ) ) );
			exit;
		}
		$out['ok'] = true;
		$out['hash'] = $hash;
		$out['visto'] = self::local( time(), 'H:i' );
		echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private static function log_access( int $user_id, string $via ): void {
		$once = 'cvd_tb_log_' . $user_id . '_' . $via;
		if ( get_transient( $once ) ) { return; }
		set_transient( $once, 1, HOUR_IN_SECONDS );
		$list = get_option( self::ACCESS, array() );
		$list = is_array( $list ) ? $list : array();
		array_unshift( $list, array( 'user' => $user_id, 'rol' => self::role_of( $user_id ), 'via' => $via, 'at' => gmdate( 'Y-m-d H:i:s' ) ) );
		update_option( self::ACCESS, array_slice( $list, 0, 50 ), false );
	}

	/* ---------- REST (VPS y bot) ---------- */

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/tablero/resumen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_resumen' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/atrasos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_atrasos' ), 'permission_callback' => $bot ) );
	}

	public static function rest_resumen( WP_REST_Request $request ): WP_REST_Response {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_VPS_BYTES ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'demasiado_grande' ), 413 ); }
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'formato' ), 400 ); }
		$biz = (string) self::config()['business_id'];
		if ( '' !== $biz && (string) ( $data['business_id'] ?? '' ) !== $biz ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'otro_negocio' ), 409 ); }
		if ( ! empty( $request->get_param( 'prueba' ) ) ) { return new WP_REST_Response( array( 'ok' => true, 'prueba' => true, 'bytes' => strlen( $body ) ), 200 ); }
		update_option( self::VPS, array( 'at' => time(), 'data' => $data ), false );
		delete_transient( 'cvd_tb_core' );
		return new WP_REST_Response( array( 'ok' => true, 'bytes' => strlen( $body ) ), 200 );
	}

	/** La regla de atraso para el bot: sin datos de clientes, solo pedido, qué falta, mensajero y horas. */
	public static function rest_atrasos(): WP_REST_Response {
		$core = self::core_data();
		$response = new WP_REST_Response( array( 'ok' => true, 'generado' => gmdate( 'c', $core['at'] ), 'umbrales' => self::config()['umbrales'], 'atrasos' => $core['atrasos'], 'calle' => $core['calle'] ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/* ---------- datos de Core (caché 60 s) ---------- */

	public static function core_data(): array {
		$cached = get_transient( 'cvd_tb_core' );
		if ( is_array( $cached ) ) { return $cached; }
		$cfg = self::config();
		$u = $cfg['umbrales'];
		$now = time();
		$today = self::local( $now, 'Y-m-d' );
		$tomorrow = self::local( $now + DAY_IN_SECONDS, 'Y-m-d' );
		$orders = wc_get_orders( array( 'limit' => 300, 'type' => 'shop_order', 'status' => self::OPEN, 'orderby' => 'date', 'order' => 'ASC' ) );
		$open = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			if ( CVD_Delivery::is_test_order( $order ) || '' !== (string) $order->get_meta( '_cvd_ensayo', true ) ) { continue; }
			$open[ $order->get_id() ] = $order;
		}
		$ready_at = self::state_times( array_keys( $open ), 'operation', 'ready' );
		$counts = array( 'nuevos' => 0, 'preparando' => 0, 'listos' => 0, 'con_mensajero' => 0, 'entregados' => 0, 'incidencia' => 0 );
		$runs = array(); $atrasos = array(); $manana = array( 'entregas' => array(), 'reservas' => array() );
		foreach ( $open as $id => $order ) {
			$op = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ?: 'new';
			$del = CVD_Delivery::status( $order );
			$pickup = 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true );
			$number = (string) $order->get_order_number();
			if ( 'incident' === $op || 'incident' === $del ) { $counts['incidencia']++; }
			elseif ( in_array( $op, array( 'new', 'confirmed' ), true ) ) { $counts['nuevos']++; }
			elseif ( 'preparing' === $op ) { $counts['preparando']++; }
			elseif ( in_array( $del, array( 'delivered', 'cash_returned' ), true ) ) { $counts['entregados']++; } // entregado, falta cerrar (dinero o cierre)
			elseif ( in_array( $del, array( 'picked_up', 'handed_over' ), true ) || 'with_courier' === $op ) { $counts['con_mensajero']++; }
			else { $counts['listos']++; } // listo en la tienda (aunque ya tenga mensajero asignado)
			$messenger_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
			$messenger = $messenger_id ? get_userdata( $messenger_id ) : false;
			$messenger_name = $messenger ? (string) $messenger->display_name : '';
			if ( $messenger_id && in_array( $del, array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over' ), true ) ) {
				$runs[ $messenger_id ] = $runs[ $messenger_id ] ?? array( 'id' => $messenger_id, 'nombre' => $messenger_name, 'carreras' => 0, 'calle' => array(), 'calle_h' => 0 );
				$runs[ $messenger_id ]['carreras']++;
			}
			// 1) Carrera de un día anterior sin recoger, pasada la hora (igual que el bot).
			$accepted = self::utc_ts( (string) $order->get_meta( '_cvd_delivery_accepted_at', true ) );
			if ( $messenger_id && $accepted && in_array( $del, array( 'assigned', 'accepted', 'to_store' ), true )
				&& self::local( $accepted, 'Y-m-d' ) < $today && self::local( $now, 'H:i' ) >= (string) $u['atascada_hora'] ) {
				$atrasos[] = self::atraso( $id, $number, 'sin_recoger', 'sin recoger', $messenger_name, $now - $accepted, (int) $u['atraso_rojo_h'] );
				continue;
			}
			// 2) Listo y sin mensajero.
			if ( ! $pickup && 'ready' === $op && in_array( $del, array( 'unassigned', 'offered' ), true ) && ! empty( $ready_at[ $id ] )
				&& $now - $ready_at[ $id ] >= (int) $u['sin_mensajero_h'] * HOUR_IN_SECONDS ) {
				$atrasos[] = self::atraso( $id, $number, 'sin_mensajero', 'listo y sin mensajero', '', $now - $ready_at[ $id ], (int) $u['atraso_rojo_h'] );
				continue;
			}
			// 3) La fecha de entrega ya pasó.
			$date = (string) $order->get_meta( '_cvd_delivery_date', true );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				if ( $date < $today && ! in_array( $del, array( 'delivered', 'cash_returned', 'closed' ), true ) ) {
					$late = $now - (int) ( new DateTimeImmutable( $date . ' 23:59:59', self::tz() ) )->getTimestamp();
					$atrasos[] = self::atraso( $id, $number, 'fecha_pasada', 'la fecha de entrega ya pasó', $messenger_name, max( HOUR_IN_SECONDS, $late ), (int) $u['atraso_rojo_h'] );
					continue;
				}
				if ( $tomorrow === $date ) { $manana['entregas'][] = '#' . $number; }
			}
			// 4) Reserva vencida.
			$until = (string) $order->get_meta( '_cvd_reserva_hasta', true );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
				if ( $until < $today ) { $atrasos[] = self::atraso( $id, $number, 'reserva_vencida', 'reserva vencida', '', HOUR_IN_SECONDS, PHP_INT_MAX ); }
				elseif ( $tomorrow === $until ) { $manana['reservas'][] = '#' . $number; }
			}
		}
		usort( $atrasos, static fn( array $a, array $b ): int => $b['horas'] <=> $a['horas'] );
		$calle = array();
		$calle_tot = array();
		if ( class_exists( 'CVD_Messenger_Cash' ) ) {
			foreach ( CVD_Messenger_Cash::pending() as $row ) {
				$h = (int) ( $row['hours'] ?? 0 );
				$calle[] = array( 'pedido' => (string) $row['number'], 'mensajero_id' => (int) $row['messengerId'], 'mensajero' => (string) $row['messengerName'], 'importe' => (string) $row['label'], 'horas' => $h,
					'nivel' => $h >= (int) $u['calle_rojo_h'] ? 'rojo' : ( $h >= (int) $u['calle_h'] ? 'amarillo' : '' ) );
				foreach ( (array) $row['amounts'] as $a ) { $calle_tot[ $a['currency'] ] = ( $calle_tot[ $a['currency'] ] ?? 0 ) + (float) $a['amount']; }
				$mid = (int) $row['messengerId'];
				if ( $mid ) {
					$runs[ $mid ] = $runs[ $mid ] ?? array( 'id' => $mid, 'nombre' => (string) $row['messengerName'], 'carreras' => 0, 'calle' => array(), 'calle_h' => 0 );
					foreach ( (array) $row['amounts'] as $a ) { $runs[ $mid ]['calle'][ $a['currency'] ] = ( $runs[ $mid ]['calle'][ $a['currency'] ] ?? 0 ) + (float) $a['amount']; }
					$runs[ $mid ]['calle_h'] = max( $runs[ $mid ]['calle_h'], $h );
				}
			}
		}
		$data = array(
			'at' => $now, 'hoy' => $today, 'manana_dia' => $tomorrow,
			'pedidos' => $counts, 'abiertos' => count( $open ),
			'atrasos' => $atrasos, 'mensajeros' => array_values( $runs ),
			'calle' => $calle, 'calle_total' => $calle_tot,
			'cierres' => self::cierres( $today ),
			'pagos' => self::payouts(),
			'catalogo' => self::catalog_pending(),
			'manana' => $manana,
			'decisiones' => self::decisions(),
		);
		set_transient( 'cvd_tb_core', $data, MINUTE_IN_SECONDS );
		return $data;
	}

	private static function atraso( int $id, string $number, string $tipo, string $texto, string $messenger, int $seconds, int $red_h ): array {
		$h = max( 1, (int) floor( $seconds / HOUR_IN_SECONDS ) );
		return array( 'pedido' => $number, 'id' => $id, 'tipo' => $tipo, 'texto' => $texto, 'mensajero' => $messenger, 'horas' => $h, 'nivel' => $h >= $red_h ? 'rojo' : 'amarillo' );
	}

	/** Hora (UTC ts) del último paso a un estado, por pedido, de la línea de tiempo wp_cvd_order_events. */
	private static function state_times( array $ids, string $domain, string $state ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) { return array(); }
		$table = $wpdb->prefix . 'cvd_order_events';
		$in = implode( ',', $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, MAX(occurred_at) AS at FROM {$table} WHERE order_id IN ({$in}) AND domain=%s AND to_state=%s GROUP BY order_id", $domain, $state ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in son enteros.
		$out = array();
		foreach ( (array) $rows as $r ) { $out[ (int) $r['order_id'] ] = self::utc_ts( (string) $r['at'] ); }
		return $out;
	}

	/** Cierre del día por persona (D122): firmado o no, diferencia y si cobró algo hoy. */
	private static function cierres( string $today ): array {
		global $wpdb;
		$role = sanitize_key( (string) self::config()['cierre_rol'] );
		$table = $wpdb->prefix . 'cvd_cierres';
		$signed = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, signed_at, diff_usd, diff_mn, note FROM {$table} WHERE day=%s AND is_test=0 ORDER BY id ASC", $today ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $r ) { $signed[ (int) $r['user_id'] ] = $r; } // el último manda (una corrección pisa al anterior)
		}
		$day_rows = array();
		if ( class_exists( 'CVD_Cierre' ) && class_exists( 'CVD_Cierre_Math' ) ) {
			try { $day_rows = CVD_Cierre::day_rows( $today ); } catch ( Throwable $e ) { $day_rows = array(); }
		}
		$out = array();
		foreach ( get_users( array( 'role' => $role, 'orderby' => 'display_name' ) ) as $user ) {
			$uid = (int) $user->ID;
			$mine = $day_rows ? CVD_Cierre_Math::mine( $day_rows, $uid ) : array();
			$cash = array_filter( $mine, static fn( array $r ): bool => 0 !== $r['usd'] || 0 !== $r['mn'] );
			$s = $signed[ $uid ] ?? null;
			$out[] = array(
				'nombre' => (string) $user->display_name,
				'firmado' => $s ? self::local( self::utc_ts( (string) $s['signed_at'] ), 'H:i' ) : '',
				'diff_usd' => $s ? (float) $s['diff_usd'] : 0.0,
				'diff_mn' => $s ? (float) $s['diff_mn'] : 0.0,
				'nota' => $s ? '' !== trim( (string) $s['note'] ) : false,
				'cobros' => count( $cash ),
			);
		}
		return $out;
	}

	/** Comisiones pedidas y aprobadas sin pagar (wp_cvd_payouts). */
	private static function payouts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_payouts';
		$out = array( 'pedidas' => array( 'n' => 0, 'importe' => array(), 'horas' => 0 ), 'aprobadas' => array( 'n' => 0, 'importe' => array() ) );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { return $out; }
		$rows = $wpdb->get_results( "SELECT status, currency, COUNT(*) n, SUM(amount) total, MIN(COALESCE(requested_at, created_at)) oldest FROM {$table} WHERE status IN ('requested','approved') GROUP BY status, currency", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$k = 'requested' === $r['status'] ? 'pedidas' : 'aprobadas';
			$out[ $k ]['n'] += (int) $r['n'];
			$out[ $k ]['importe'][ strtoupper( (string) $r['currency'] ) ] = round( (float) $r['total'], 2 );
			if ( 'pedidas' === $k && $r['oldest'] ) { $out[ $k ]['horas'] = max( $out[ $k ]['horas'], (int) floor( ( time() - self::utc_ts( (string) $r['oldest'] ) ) / HOUR_IN_SECONDS ) ); }
		}
		return $out;
	}

	private static function catalog_pending(): array {
		if ( ! class_exists( 'CVD_Catalogo_Auto' ) ) { return array( 'pendientes' => null ); }
		$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false, 'meta_key' => CVD_Catalogo_Auto::META_REV, 'meta_value' => 'pendiente' ) );
		return array( 'pendientes' => (int) $q->found_posts );
	}

	/** Bandeja de decisiones (D105): el mismo archivo que lee /control/decisiones/. */
	private static function decisions(): array {
		$file = (string) self::config()['decisiones_archivo'];
		if ( '' === $file || ! is_readable( $file ) ) { return array( 'ok' => false ); }
		$d = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $d ) ) { return array( 'ok' => false ); }
		$limit = (int) ( $d['reglas']['caduca_dias'] ?? 0 );
		$live = 0; $soon = 0; $por = array();
		foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
			if ( ! empty( $it['caducada'] ) ) { continue; }
			$live++;
			$tipo = (string) ( $it['tipo'] ?? 'otro' );
			$por[ $tipo ] = ( $por[ $tipo ] ?? 0 ) + 1;
			if ( $limit && (int) ( $it['dias'] ?? 0 ) >= $limit - 1 ) { $soon++; }
		}
		$at = strtotime( (string) ( $d['generado_at'] ?? '' ) ) ?: 0;
		return array( 'ok' => true, 'pendientes' => $live, 'caducan' => $soon, 'por_tipo' => $por, 'min' => $at ? (int) floor( ( time() - $at ) / 60 ) : null );
	}

	/** Resumen del VPS y cuántos minutos tiene. */
	private static function vps(): array {
		$saved = get_option( self::VPS, array() );
		if ( ! is_array( $saved ) || empty( $saved['data'] ) ) { return array( 'data' => array(), 'min' => null ); }
		return array( 'data' => (array) $saved['data'], 'min' => (int) floor( ( time() - (int) $saved['at'] ) / 60 ) );
	}

	/* ---------- vistas ---------- */

	private static function money( array $by_currency ): string {
		$parts = array();
		foreach ( $by_currency as $cur => $amount ) {
			if ( abs( (float) $amount ) < 0.01 ) { continue; }
			$parts[] = number_format_i18n( (float) $amount, fmod( (float) $amount, 1.0 ) ? 2 : 0 ) . ' ' . ( 'CUP' === $cur ? 'CUP' : $cur );
		}
		return $parts ? implode( ' + ', $parts ) : '0';
	}

	private static function hours_text( int $h ): string { return $h >= 48 ? floor( $h / 24 ) . ' días' : $h . ' h'; }

	/** Lo que ve cada rol. Cada tarjeta: titulo, nota, nums[], filas[], enlace, aviso. */
	public static function view( string $role, int $user_id ): array {
		// La vista hecha se guarda 60 s (y se rehace al llegar otro resumen del VPS): con internet lento cada segundo cuenta.
		$saved = get_option( self::VPS, array() );
		$key = 'cvd_tb_view_' . md5( $role . '|' . $user_id . '|' . (int) ( is_array( $saved ) ? ( $saved['at'] ?? 0 ) : 0 ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }
		$out = self::build_view( $role, $user_id );
		set_transient( $key, $out, MINUTE_IN_SECONDS );
		return $out;
	}

	private static function build_view( string $role, int $user_id ): array {
		$cfg = self::config();
		$core = self::core_data();
		$links = $cfg['enlaces'];
		$user = get_userdata( $user_id );
		$first = $user ? strtok( (string) $user->display_name, ' ' ) : '';
		$red = array(); $yellow = array(); $cards = array();
		$day_label = self::local( (int) $core['at'], 'l j \d\e F' );

		// --- Tarjetas de administración (también las ve el dueño, más abajo) ---
		$lennys_cards = array(); $l_red = array(); $l_yellow = array();
		$p = $core['pedidos'];
		$lennys_cards[] = array( 'id' => 'pedidos', 'titulo' => 'Pedidos abiertos', 'nota' => (string) $core['abiertos'],
			'nums' => array( array( 'n' => $p['nuevos'] + $p['preparando'], 'label' => 'por preparar' ), array( 'n' => $p['listos'], 'label' => 'listos' ), array( 'n' => $p['con_mensajero'], 'label' => 'en camino' ) ),
			'filas' => array_values( array_filter( array( $p['entregados'] ? array( 't' => 'Entregados, falta cerrar', 'r' => (string) $p['entregados'] ) : null, $p['incidencia'] ? array( 't' => 'Con incidencia', 'r' => (string) $p['incidencia'], 'tag' => 'rojo' ) : null ) ) ),
			'enlace' => array( 'txt' => 'Abrir Ventas', 'url' => $links['ventas'] ) );
		$rows = array();
		foreach ( array_slice( $core['atrasos'], 0, 8 ) as $a ) {
			$rows[] = array( 't' => '#' . $a['pedido'] . ' · ' . $a['texto'] . ( $a['mensajero'] ? ' · ' . $a['mensajero'] : '' ), 'r' => self::hours_text( $a['horas'] ), 'tag' => $a['nivel'] );
			if ( 'rojo' === $a['nivel'] ) { $l_red[] = '#' . $a['pedido'] . ' ' . $a['texto'] . ' (' . self::hours_text( $a['horas'] ) . ')'; }
		}
		$n_late = count( $core['atrasos'] );
		if ( $n_late && ! $l_red ) { $l_yellow[] = 1 === $n_late ? '1 pedido atrasado' : $n_late . ' pedidos atrasados'; }
		$lennys_cards[] = array( 'id' => 'atrasos', 'titulo' => 'Atrasos', 'nota' => (string) $n_late, 'filas' => $rows ?: array( array( 't' => 'Ningún pedido atrasado', 'r' => '✓' ) ) );
		$rows = array();
		foreach ( $core['mensajeros'] as $m ) {
			$cash = self::money( $m['calle'] );
			$rows[] = array( 't' => $m['nombre'], 'r' => $m['carreras'] . ( 1 === $m['carreras'] ? ' carrera' : ' carreras' ) . ' · ' . ( '0' === $cash ? 'sin dinero' : $cash ),
				'tag' => $m['calle_h'] >= (int) $cfg['umbrales']['calle_h'] ? 'amarillo' : '', 'tag_txt' => $m['calle_h'] >= (int) $cfg['umbrales']['calle_h'] ? self::hours_text( $m['calle_h'] ) : '' );
		}
		$lennys_cards[] = array( 'id' => 'mensajeros', 'titulo' => 'Mensajeros', 'nota' => 'quién lleva qué', 'filas' => $rows ?: array( array( 't' => 'Nadie con carreras ahora', 'r' => '' ) ) );
		$rows = array();
		$close_time = (string) $cfg['hora_cierre'];
		$after_close = self::local( $core['at'], 'H:i' ) >= $close_time;
		foreach ( $core['cierres'] as $c ) {
			if ( $c['firmado'] ) {
				$diff = self::money( array( 'USD' => $c['diff_usd'], 'CUP' => $c['diff_mn'] ) );
				$ok = '0' === $diff;
				$rows[] = array( 't' => $c['nombre'], 'r' => $ok ? 'cuadra' : 'diferencia ' . $diff, 'tag' => $ok ? 'verde' : ( $c['nota'] ? 'amarillo' : 'rojo' ), 'tag_txt' => 'firmado ' . $c['firmado'] );
				if ( ! $ok ) { if ( $c['nota'] ) { $l_yellow[] = $c['nombre'] . ' cerró con diferencia (explicada)'; } else { $l_red[] = $c['nombre'] . ' cerró con diferencia sin explicar'; } }
			} elseif ( $c['cobros'] ) {
				$rows[] = array( 't' => $c['nombre'], 'r' => $c['cobros'] . ' cobros hoy', 'tag' => $after_close ? 'amarillo' : '', 'tag_txt' => 'sin firmar' );
				if ( $after_close ) { $l_yellow[] = $c['nombre'] . ' aún no firmó su cierre'; }
			} else {
				$rows[] = array( 't' => $c['nombre'], 'r' => 'sin cobros hoy' );
			}
		}
		$street = self::money( $core['calle_total'] );
		$max_h = 0; foreach ( $core['calle'] as $c ) { $max_h = max( $max_h, $c['horas'] ); }
		$rows[] = array( 't' => 'Dinero en la calle', 'r' => ( '0' === $street ? 'nada' : $street . ' · ' . count( $core['calle'] ) . ' pedidos' ),
			'tag' => $max_h >= (int) $cfg['umbrales']['calle_rojo_h'] ? 'rojo' : ( $max_h >= (int) $cfg['umbrales']['calle_h'] ? 'amarillo' : '' ), 'tag_txt' => $max_h >= (int) $cfg['umbrales']['calle_h'] ? 'desde hace ' . self::hours_text( $max_h ) : '' );
		foreach ( $core['calle'] as $c ) {
			if ( 'rojo' === $c['nivel'] ) { $l_red[] = $c['mensajero'] . ' tiene ' . $c['importe'] . ' desde hace ' . self::hours_text( $c['horas'] ); }
			elseif ( 'amarillo' === $c['nivel'] ) { $l_yellow[] = $c['mensajero'] . ' tiene ' . $c['importe'] . ' desde ayer'; }
		}
		$lennys_cards[] = array( 'id' => 'dinero', 'titulo' => 'Dinero del día', 'nota' => 'cierre por persona', 'filas' => $rows, 'enlace' => array( 'txt' => 'Ver cierre en Ventas', 'url' => $links['ventas'] ) );
		$pay = $core['pagos'];
		$lennys_cards[] = array( 'id' => 'salidas', 'titulo' => 'Salidas por aprobar', 'filas' => array(
			array( 't' => 'Comisiones pedidas', 'r' => $pay['pedidas']['n'] . ( $pay['pedidas']['n'] ? ' · ' . self::money( $pay['pedidas']['importe'] ) : '' ), 'tag' => $pay['pedidas']['n'] && $pay['pedidas']['horas'] >= (int) $cfg['umbrales']['comision_h'] ? 'amarillo' : '', 'tag_txt' => $pay['pedidas']['n'] && $pay['pedidas']['horas'] >= (int) $cfg['umbrales']['comision_h'] ? self::hours_text( $pay['pedidas']['horas'] ) : '' ),
			array( 't' => 'Aprobadas, falta pagar', 'r' => $pay['aprobadas']['n'] . ( $pay['aprobadas']['n'] ? ' · ' . self::money( $pay['aprobadas']['importe'] ) : '' ) ),
		), 'enlace' => array( 'txt' => 'Abrir el panel', 'url' => $links['panel'] ) );
		if ( $pay['pedidas']['n'] && $pay['pedidas']['horas'] >= (int) $cfg['umbrales']['comision_h'] ) { $l_yellow[] = $pay['pedidas']['n'] . ( 1 === $pay['pedidas']['n'] ? ' comisión pedida espera' : ' comisiones pedidas esperan' ) . ' tu visto bueno'; }
		if ( null !== $core['catalogo']['pendientes'] ) {
			$lennys_cards[] = array( 'id' => 'catalogo', 'titulo' => 'Catálogo', 'filas' => array( array( 't' => 'Fichas nuevas para tu ✓ / ✗', 'r' => (string) $core['catalogo']['pendientes'] ) ),
				'enlace' => array( 'txt' => 'Revisar fichas', 'url' => class_exists( 'CVD_Catalogo_Auto' ) ? CVD_Catalogo_Auto::url() : $links['panel'] ) );
		}

		if ( 'administracion' === $role ) {
			$red = $l_red; $yellow = $l_yellow; $cards = $lennys_cards;
		} else {
			// --- Vista del dueño ---
			$vps = self::vps();
			$v = $vps['data'];
			$stale = null === $vps['min'] || $vps['min'] > (int) $cfg['umbrales']['vps_viejo_min'];
			$stale_txt = null === $vps['min'] ? 'Sin datos del servidor del bot todavía.' : ( $stale ? 'Dato de hace ' . $vps['min'] . ' min: el servidor no ha actualizado.' : '' );
			if ( $stale ) { $yellow[] = null === $vps['min'] ? 'Aún no llegan datos del servidor del bot' : 'Los datos del bot son de hace ' . $vps['min'] . ' min'; }
			// Errores del bot
			$e = (array) ( $v['errores'] ?? array() );
			if ( $e ) {
				$g = (array) ( $e['abiertos_por_gravedad'] ?? array() );
				$rows = array();
				foreach ( array_slice( (array) ( $e['ultimos'] ?? array() ), 0, 4 ) as $it ) {
					$rows[] = array( 't' => '#' . (int) $it['id'] . ' ' . self::tipo_label( (string) $it['tipo'] ) . ' · ' . (string) $it['texto'], 'r' => (string) $it['cuando'], 'tag' => 'alta' === ( $it['gravedad'] ?? '' ) && 'decidir' === ( $it['estado'] ?? '' ) ? 'rojo' : '', 'tag_txt' => 'alta' === ( $it['gravedad'] ?? '' ) ? 'alta' : '' );
				}
				$types = (array) ( $e['por_tipo'] ?? array() );
				arsort( $types );
				$top = array(); foreach ( array_slice( $types, 0, 3, true ) as $k => $n ) { $top[] = self::tipo_label( (string) $k ) . ' ' . (int) $n; }
				$cards[] = array( 'id' => 'errores', 'titulo' => 'Errores del bot', 'nota' => 'últimos ' . (int) ( $e['dias'] ?? 7 ) . ' días',
					'nums' => array( array( 'n' => (int) ( $g['alta'] ?? 0 ), 'label' => 'alta' ), array( 'n' => (int) ( $g['media'] ?? 0 ), 'label' => 'media' ), array( 'n' => (int) ( $g['baja'] ?? 0 ), 'label' => 'baja' ) ),
					'texto' => 'Para decidir: ' . (int) ( $e['para_decidir'] ?? 0 ) . ' · corregidos: ' . (int) ( $e['corregidos'] ?? 0 ) . ( $top ? '. Más repetidos: ' . implode( ' · ', $top ) . '.' : '' ),
					'filas' => $rows, 'enlace' => array( 'txt' => 'Revisar en el centro', 'url' => $links['centro'] ), 'aviso' => $stale_txt );
				$graves = (int) ( $e['graves_hoy_sin_decidir'] ?? 0 );
				if ( $graves ) { $red[] = $graves . ( 1 === $graves ? ' error grave del bot de hoy sin decidir' : ' errores graves del bot de hoy sin decidir' ); }
			}
			// Decisiones
			$d = $core['decisiones'];
			if ( ! empty( $d['ok'] ) ) {
				$tipos = array(); foreach ( (array) $d['por_tipo'] as $k => $n ) { $tipos[] = ucfirst( (string) $k ) . ' ' . (int) $n; }
				$cards[] = array( 'id' => 'decisiones', 'titulo' => 'Decisiones', 'nota' => 'bandeja', 'filas' => array(
					array( 't' => 'Pendientes', 'r' => (string) $d['pendientes'] ),
					array( 't' => 'Caducan pronto (lo reversible se aplica solo)', 'r' => (string) $d['caducan'], 'tag' => $d['caducan'] ? 'amarillo' : '' ),
					array( 't' => 'Por tema', 'r' => implode( ' · ', $tipos ) ?: '—' ),
				), 'enlace' => array( 'txt' => 'Decidir ahora', 'url' => $links['decisiones'] ) );
				if ( $d['caducan'] ) { $yellow[] = $d['caducan'] . ( 1 === $d['caducan'] ? ' decisión caduca pronto' : ' decisiones caducan pronto' ); }
			}
			// Salud
			$s = (array) ( $v['salud'] ?? array() );
			if ( $s ) {
				$rows = array();
				foreach ( (array) ( $s['checks'] ?? array() ) as $c ) {
					$rows[] = array( 't' => ( ! empty( $c['ok'] ) ? '🟢 ' : ( ! empty( $c['grave'] ) ? '🔴 ' : '🟡 ' ) ) . (string) $c['nombre'], 'r' => (string) $c['detalle'] );
					if ( ! empty( $c['grave'] ) ) { $red[] = (string) $c['nombre'] . ': ' . (string) $c['detalle']; }
					elseif ( empty( $c['ok'] ) ) { $yellow[] = (string) $c['nombre'] . ': ' . (string) $c['detalle']; }
				}
				foreach ( (array) ( $v['modos'] ?? array() ) as $mo ) { // interruptores del bot (D126: modo tranquilo)
					$rows[] = array( 't' => ( ! empty( $mo['encendido'] ) ? '🔵 ' : '⚪ ' ) . (string) $mo['nombre'], 'r' => ! empty( $mo['encendido'] ) ? 'encendido' : 'apagado' );
				}
				$cards[] = array( 'id' => 'salud', 'titulo' => 'Salud del sistema', 'nota' => isset( $s['min'] ) ? 'revisado hace ' . (int) $s['min'] . ' min' : '', 'filas' => $rows, 'aviso' => $stale_txt );
			}
			// Mensajes del bot por persona
			$m = (array) ( $v['mensajes'] ?? array() );
			if ( $m ) {
				$signal = (int) $cfg['umbrales']['senal_mensajes_mensajero'];
				$rows = array();
				foreach ( array_slice( (array) ( $m['por_persona'] ?? array() ), 0, 12 ) as $pp ) {
					$role_label = self::person_role( (string) ( $pp['tel8'] ?? '' ), (string) ( $pp['rol'] ?? '' ) );
					$over = 'mensajero' === $role_label && (int) $pp['n'] > $signal;
					$rows[] = array( 't' => (string) $pp['nombre'] . ' · ' . $role_label, 'r' => (string) (int) $pp['n'], 'tag' => $over ? 'amarillo' : ( ! empty( $pp['fallidos'] ) ? 'rojo' : '' ),
						'tag_txt' => $over ? 'más de ' . $signal : ( ! empty( $pp['fallidos'] ) ? (int) $pp['fallidos'] . ' fallaron' : '' ) );
				}
				$o = (array) ( $m['outbox'] ?? array() );
				$extra = array();
				foreach ( array_slice( (array) ( $o['por_origen'] ?? array() ), 0, 6 ) as $pair ) { $extra[] = array( 't' => (string) $pair[0], 'r' => (string) (int) $pair[1] ); }
				$cards[] = array( 'id' => 'mensajes', 'titulo' => 'Mensajes del bot hoy', 'nota' => (int) ( $m['total_bot'] ?? 0 ) . ' a personas',
					'texto' => 'Grupos: ' . (int) ( $o['grupos'] ?? 0 ) . ' · buzón con error: ' . (int) ( $o['fallidos'] ?? 0 ), 'filas' => $rows, 'mas' => array( 'txt' => 'Ver quién los manda (buzón)', 'filas' => $extra ), 'aviso' => $stale_txt );
			}
			// Mañana
			$rows = array();
			if ( $core['manana']['entregas'] ) { $rows[] = array( 't' => self::plural( count( $core['manana']['entregas'] ), 'entrega', 'entregas' ) . ' con fecha de mañana', 'r' => implode( ', ', array_slice( $core['manana']['entregas'], 0, 4 ) ) ); }
			if ( $core['manana']['reservas'] ) { $rows[] = array( 't' => self::plural( count( $core['manana']['reservas'] ), 'reserva vence', 'reservas vencen' ) . ' mañana', 'r' => implode( ', ', array_slice( $core['manana']['reservas'], 0, 4 ) ) ); }
			if ( $pay['aprobadas']['n'] ) { $rows[] = array( 't' => self::plural( $pay['aprobadas']['n'], 'comisión aprobada', 'comisiones aprobadas' ) . ' sin pagar', 'r' => self::money( $pay['aprobadas']['importe'] ) ); }
			$sup = (array) ( $v['supervisor'] ?? array() );
			if ( ! empty( $sup['pendientes'] ) ) { $rows[] = array( 't' => self::plural( (int) $sup['pendientes'], 'tarea', 'tareas' ) . ' del supervisor pendientes', 'r' => 'servidor' ); }
			if ( ! empty( $v['escalaciones']['abiertas'] ) ) { $rows[] = array( 't' => self::plural( (int) $v['escalaciones']['abiertas'], 'escalación abierta', 'escalaciones abiertas' ) . ' del bot', 'r' => 'centro' ); }
			$cards[] = array( 'id' => 'manana', 'titulo' => 'Para mañana', 'nota' => self::local( (int) ( new DateTimeImmutable( $core['manana_dia'] . ' 12:00', self::tz() ) )->getTimestamp(), 'l j' ), 'filas' => $rows ?: array( array( 't' => 'Nada apuntado para mañana', 'r' => '' ) ) );
			// Resumen de la parte de administración
			$red = array_merge( $red, $l_red );
			$yellow = array_merge( $yellow, $l_yellow );
			$urgent = array_slice( array_merge( $red, $yellow ), 0, 3 );
			array_unshift( $cards, array( 'id' => 'urgente', 'titulo' => 'Lo urgente', 'nota' => 'máx. 3', 'filas' => array_map( static fn( string $t ): array => array( 't' => $t, 'r' => '' ), $urgent ) ?: array( array( 't' => 'Nada urgente ahora', 'r' => '✓' ) ) ) );
			$cards[] = array( 'id' => 'ver_admin', 'boton' => array( 'txt' => 'Ver como ' . self::admin_name(), 'ver' => 'administracion' ) );
		}
		$color = $red ? 'rojo' : ( $yellow ? 'amarillo' : 'verde' );
		$titles = array( 'rojo' => 'Día en rojo', 'amarillo' => 'Día en amarillo', 'verde' => 'Día en verde' );
		return array(
			'rol' => $role, 'hola' => 'Hola, ' . ( 'administracion' === $role && self::role_of( $user_id ) === 'dueno' ? self::admin_name() . ' (vista)' : $first ),
			'negocio' => (string) $cfg['nombre'], 'dia' => $day_label,
			'semaforo' => array( 'color' => $color, 'titulo' => $titles[ $color ], 'razones' => array_slice( array_merge( $red, $yellow ), 0, 3 ) ),
			'tarjetas' => $cards,
			'volver' => 'administracion' === $role && 'dueno' === self::role_of( $user_id ) ? array( 'txt' => 'Volver a mi vista', 'ver' => 'dueno' ) : null,
		);
	}

	private static function admin_name(): string {
		foreach ( (array) self::config()['personas'] as $uid => $role ) {
			if ( 'administracion' === $role ) { $u = get_userdata( (int) $uid ); if ( $u ) { return (string) strtok( (string) $u->display_name, ' ' ); } }
		}
		return 'administración';
	}

	/** Rol de una persona según la web (manda sobre el del CRM: 8 mensajeros tienen rol «gestora» allí). */
	private static function person_role( string $tel8, string $crm_role ): string {
		$tel8 = substr( preg_replace( '/\D+/', '', $tel8 ), -8 );
		if ( 8 === strlen( $tel8 ) ) {
			$cache = get_transient( 'cvd_tb_roles' );
			$cache = is_array( $cache ) ? $cache : array();
			if ( ! isset( $cache[ $tel8 ] ) ) {
				// Una persona puede tener varios roles (Lennys: admin, gestora y mensajera): manda el de más arriba.
				$labels = array( 'administrator' => 'admin', 'cvd_clerk' => 'dependienta', 'cvd_messenger' => 'mensajero', 'cvd_gestora' => 'gestora' );
				$found = array();
				foreach ( get_users( array( 'number' => 3, 'fields' => 'ID', 'meta_query' => array( array( 'key' => '_cvd_whatsapp', 'value' => $tel8, 'compare' => 'LIKE' ) ) ) ) as $id ) {
					$user = get_userdata( (int) $id );
					$people = (array) self::config()['personas'];
					if ( $user && isset( $people[ (int) $id ] ) ) { $found['administrator'] = true; }
					foreach ( $user ? (array) $user->roles : array() as $r ) { $found[ $r ] = true; }
				}
				$cache[ $tel8 ] = '';
				foreach ( $labels as $r => $label ) { if ( isset( $found[ $r ] ) ) { $cache[ $tel8 ] = $label; break; } }
				set_transient( 'cvd_tb_roles', $cache, 6 * HOUR_IN_SECONDS );
			}
			if ( '' !== $cache[ $tel8 ] ) { return $cache[ $tel8 ]; }
		}
		return '' !== $crm_role ? $crm_role : 'sin rol';
	}

	private static function tipo_label( string $tipo ): string {
		$labels = array( 'invento' => 'invento', 'entendio_mal' => 'entendió mal', 'destino_equivocado' => 'destino equivocado', 'no_ayudo' => 'no ayudó', 'promesa' => 'promesa', 'tecnico' => 'técnico', 'regla' => 'regla' );
		return $labels[ $tipo ] ?? str_replace( '_', ' ', $tipo );
	}

	private static function plural( int $n, string $one, string $many ): string { return $n . ' ' . ( 1 === $n ? $one : $many ); }
}
