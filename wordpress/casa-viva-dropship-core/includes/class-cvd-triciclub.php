<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.117 (Ernesto 3-nov, D129–D130): Triciclub, la app de triciclos (viajes, paquetes, carga). La puerta: interruptor,
 * entrada del cliente, páginas, manifest, service worker y el puente con VivaBot para el código de WhatsApp.
 * Motor dentro del Core de Casa Viva (D129), pero aparte: prefijo «tc» en tablas, opciones, rutas y archivos.
 *
 * - Interruptor `cvd_tc`: no (por defecto) | prueba | si.
 *   En prueba solo entran los clientes de `cvd_tc_clientes_prueba` (teléfonos) y los choferes de `cvd_tc_testers` (user ids).
 * - Cliente: /triciclub/ (PWA). Entra con su teléfono y un código de 6 cifras que manda VivaBot por WhatsApp
 *   (GET /bot/triciclub/codigos). En prueba también con enlace: CVD_Triciclub::enlace_cliente( '5355…' ).
 *   Token = id.caducidad.firma (90 días), cabecera X-TC-Token; subir token_epoca anula sus enlaces.
 * - Chofer: /palla/triciclub, con el MISMO enlace de Pa'llá (X-Palla-Token). Tiene que ser mensajero aprobado.
 *   Ernesto lo aprueba: wp eval 'CVD_Triciclub::aprobar_chofer( 59 );'.
 */
final class CVD_Triciclub {
	public const OPTION = 'cvd_tc';
	public const TESTERS = 'cvd_tc_testers';
	public const CLIENTES_PRUEBA = 'cvd_tc_clientes_prueba';
	public const TTL = 7776000; // 90 días
	public const TELEFONO = '5354056173'; // Triciclub (opción cvd_tc_telefono)
	public const CRON = 'cvd_tc_diario';
	private const NS = 'casa-viva/v1';

	public static function register(): void {
		add_action( 'init', array( 'CVD_Triciclub_Db', 'install' ) );
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::CRON, array( 'CVD_Triciclub_Db', 'limpiar' ) );
		add_action( 'init', static function (): void {
			if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON ); }
		} );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/bot/triciclub/codigos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_codigos' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
		register_rest_route( self::NS, '/bot/triciclub/codigos/(?P<id>\d+)/enviado', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_enviado' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	// ------------------------------------------------------------------ Interruptor

	public static function mode(): string {
		$mode = sanitize_key( (string) get_option( self::OPTION, 'no' ) );
		return in_array( $mode, array( 'prueba', 'si' ), true ) ? $mode : 'no';
	}

	/** @return int[] */
	public static function testers(): array {
		$raw = get_option( self::TESTERS, array() );
		$list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		return array_values( array_filter( array_map( 'absint', (array) $list ) ) );
	}

	/** @return string[] teléfonos normalizados */
	public static function clientes_prueba(): array {
		$raw = get_option( self::CLIENTES_PRUEBA, array() );
		$list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		return array_values( array_filter( array_map( array( __CLASS__, 'telefono' ), (array) $list ) ) );
	}

	/** '' = el cliente puede entrar; si no, closed | not_tester. */
	public static function cliente_bloqueado( string $telefono ): string {
		$mode = self::mode();
		if ( 'no' === $mode ) { return 'closed'; }
		if ( 'prueba' === $mode && ! in_array( self::telefono( $telefono ), self::clientes_prueba(), true ) ) { return 'not_tester'; }
		return '';
	}

	/** '' = el chofer puede entrar; si no, closed | not_tester | not_messenger. */
	public static function chofer_bloqueado( WP_User $user ): string {
		$mode = self::mode();
		if ( 'no' === $mode ) { return 'closed'; }
		$propio = CVD_Triciclub_Alta::es_propio( $user ); // 3.14.130: chofer de Triciclub sin ser mensajero (entra a darse de alta)
		if ( 'prueba' === $mode && ! $propio && ! in_array( $user->ID, self::testers(), true ) ) { return 'not_tester'; }
		if ( ! $propio && ! CVD_Registration::is_approved_messenger( $user ) ) { return 'not_messenger'; }
		return '';
	}

	public static function texto_cerrado( string $reason ): string {
		return array(
			'closed'        => 'Triciclub todavía no está abierto. Muy pronto.',
			'not_tester'    => 'Triciclub está en prueba con pocas personas. Te avisamos cuando abra para todos.',
			'not_messenger' => 'Para llevar viajes de Triciclub tienes que ser mensajero aprobado.',
			'blocked'       => 'Tu cuenta está parada. Llama a Triciclub.',
		)[ $reason ] ?? 'No puedes entrar ahora.';
	}

	/** «+53 5 555-1234», «55551234» → «5355551234». Vacío si no parece un móvil cubano. */
	public static function telefono( $raw ): string {
		$d = preg_replace( '/\D+/', '', (string) $raw );
		if ( 8 === strlen( $d ) && '5' === $d[0] ) { $d = '53' . $d; }
		return ( 10 === strlen( $d ) && 0 === strpos( $d, '535' ) ) ? $d : '';
	}

	// ------------------------------------------------------------------ Token del cliente

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-tc'; }

	private static function firma( int $id, int $expires, int $epoca ): string {
		return substr( hash_hmac( 'sha256', 'cvd-tc|' . $id . '|' . $expires . '|' . $epoca, self::key() ), 0, 32 );
	}

	public static function token_cliente( object $cliente, ?int $now = null ): string {
		$expires = ( $now ?? time() ) + self::TTL;
		return (int) $cliente->id . '.' . $expires . '.' . self::firma( (int) $cliente->id, $expires, (int) $cliente->token_epoca );
	}

	public static function cliente( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'clientes' ) . ' WHERE id=%d', $id ) );
		return $row ?: null;
	}

	public static function cliente_por_telefono( string $telefono ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'clientes' ) . ' WHERE telefono=%s', self::telefono( $telefono ) ) );
		return $row ?: null;
	}

	/** El cliente del token, o null. */
	public static function verificar_token( string $token, ?int $now = null ): ?object {
		if ( ! preg_match( '/^(\d{1,10})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) ) { return null; }
		if ( (int) $m[2] < ( $now ?? time() ) ) { return null; }
		$c = self::cliente( (int) $m[1] );
		if ( ! $c || ! hash_equals( self::firma( (int) $c->id, (int) $m[2], (int) $c->token_epoca ), $m[3] ) ) { return null; }
		return $c;
	}

	/** Quién llama (cliente): el de X-TC-Token, o el error. */
	public static function request_cliente( WP_REST_Request $request ) {
		$c = self::verificar_token( trim( (string) $request->get_header( 'x_tc_token' ) ) );
		if ( ! $c ) { return new WP_Error( 'tc_token', 'Vuelve a entrar con tu teléfono.', array( 'status' => 401 ) ); }
		if ( (int) $c->bloqueado ) { return new WP_Error( 'tc_blocked', self::texto_cerrado( 'blocked' ), array( 'status' => 403 ) ); }
		$reason = self::cliente_bloqueado( (string) $c->telefono );
		if ( '' !== $reason ) { return new WP_Error( 'tc_' . $reason, self::texto_cerrado( $reason ), array( 'status' => 403 ) ); }
		return $c;
	}

	/** Quién llama (chofer): el usuario de Pa'llá, o el error. */
	public static function request_chofer( WP_REST_Request $request ) {
		$user_id = CVD_Palla::verify( trim( (string) $request->get_header( 'x_palla_token' ) ) );
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) { return new WP_Error( 'tc_token', 'Tu enlace de Pa\'llá ya no vale. Pide uno nuevo.', array( 'status' => 401 ) ); }
		$reason = self::chofer_bloqueado( $user );
		if ( '' !== $reason ) { return new WP_Error( 'tc_' . $reason, self::texto_cerrado( $reason ), array( 'status' => 403 ) ); }
		return $user;
	}

	/** Crea (o devuelve) el cliente de ese teléfono. */
	public static function crear_cliente( string $telefono, string $nombre = '', bool $consiente = false ): ?object {
		global $wpdb;
		$tel = self::telefono( $telefono );
		if ( '' === $tel ) { return null; }
		$now = CVD_Triciclub_Db::now();
		$c = self::cliente_por_telefono( $tel );
		if ( ! $c ) {
			$wpdb->insert( CVD_Triciclub_Db::t( 'clientes' ), array( 'telefono' => $tel, 'nombre' => $nombre, 'created_at' => $now, 'updated_at' => $now ) );
			$c = self::cliente_por_telefono( $tel );
		}
		if ( ! $c ) { return null; }
		$set = array( 'verificado_at' => $now, 'updated_at' => $now );
		if ( '' !== $nombre ) { $set['nombre'] = $nombre; }
		if ( $consiente && ! $c->consentimiento_at ) { $set['consentimiento_at'] = $now; }
		$wpdb->update( CVD_Triciclub_Db::t( 'clientes' ), $set, array( 'id' => (int) $c->id ) );
		return self::cliente( (int) $c->id );
	}

	/** Prueba: enlace de entrada para un cliente (no envía nada). wp eval 'echo CVD_Triciclub::enlace_cliente("5355555555");' */
	public static function enlace_cliente( string $telefono, string $nombre = '' ): string {
		$c = self::crear_cliente( $telefono, $nombre );
		return $c ? home_url( '/triciclub/' ) . '#k=' . self::token_cliente( $c ) : '';
	}

	/** Anula todos los enlaces de un cliente. */
	public static function revocar_cliente( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'clientes' ) . ' SET token_epoca=token_epoca+1, updated_at=%s WHERE id=%d', CVD_Triciclub_Db::now(), $id ) );
	}

	// ------------------------------------------------------------------ Choferes (Ernesto)

	public static function chofer_de( int $user_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'choferes' ) . ' WHERE user_id=%d', $user_id ) );
		return $row ?: null;
	}

	/** wp eval 'CVD_Triciclub::aprobar_chofer( 59 );' — lo aprueba (crea la ficha si no existe). */
	public static function aprobar_chofer( int $user_id, int $por = 0 ): bool {
		global $wpdb;
		if ( ! get_userdata( $user_id ) ) { return false; }
		$now = CVD_Triciclub_Db::now();
		if ( ! self::chofer_de( $user_id ) ) {
			$wpdb->insert( CVD_Triciclub_Db::t( 'choferes' ), array( 'user_id' => $user_id, 'created_at' => $now, 'updated_at' => $now ) );
		}
		return false !== $wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'estado' => 'aprobado', 'aprobado_por' => $por ?: null, 'aprobado_at' => $now, 'updated_at' => $now ), array( 'user_id' => $user_id ) );
	}

	public static function suspender_chofer( int $user_id ): void {
		global $wpdb;
		$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'estado' => 'suspendido', 'disponible' => 0, 'updated_at' => CVD_Triciclub_Db::now() ), array( 'user_id' => $user_id ) );
	}

	/** Para Pa'llá: ¿enseñar el botón de Triciclub a este mensajero? */
	public static function palla_flag( WP_User $user ): ?array {
		if ( '' !== self::chofer_bloqueado( $user ) ) { return null; }
		$ch = self::chofer_de( $user->ID );
		return array( 'url' => home_url( '/palla/triciclub' ), 'estado' => $ch ? (string) $ch->estado : 'nuevo' );
	}

	// ------------------------------------------------------------------ Código por WhatsApp (VivaBot lo manda)

	/** Crea un código para ese teléfono. Devuelve '' o el motivo: phone | limit. */
	public static function pedir_codigo( string $telefono, string $ip, int $cifras = 6, string $canal = 'whatsapp', string $app = 'tc' ): string {
		global $wpdb;
		$tel = self::telefono( $telefono );
		if ( '' === $tel ) { return 'phone'; }
		$t = CVD_Triciclub_Db::t( 'codigos' );
		$hace1h = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$por_tel = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE telefono=%s AND created_at>%s AND app<>'pva'", $tel, $hace1h ) );
		$por_ip = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ip=%s AND created_at>%s AND app<>'pva'", $ip, $hace1h ) );
		if ( $por_tel >= 3 || $por_ip >= 10 ) { return 'limit'; }
		$cifras = 4 === $cifras ? 4 : 6; // 5-nov (Pa'llá, diseño aprobado): 4 cifras, 5 intentos, 15 min, 3 por hora
		$codigo = str_pad( (string) random_int( 0, ( 10 ** $cifras ) - 1 ), $cifras, '0', STR_PAD_LEFT );
		$now = CVD_Triciclub_Db::now();
		$wpdb->insert( $t, array(
			'telefono'    => $tel,
			'codigo'      => $codigo,
			'codigo_hash' => hash_hmac( 'sha256', $tel . '|' . $codigo, self::key() ),
			'canal'       => 'sms' === $canal ? 'sms' : 'whatsapp',
			'app'         => 'pv' === $app ? 'pv' : 'tc',
			'ip'          => substr( $ip, 0, 45 ),
			'vence_at'    => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ),
			'created_at'  => $now,
		) );
		return '';
	}

	/** Comprueba el código (5 intentos). Devuelve '' si vale; si no: wrong | expired | tries. */
	public static function comprobar_codigo( string $telefono, string $codigo ): string {
		global $wpdb;
		$tel = self::telefono( $telefono );
		$t = CVD_Triciclub_Db::t( 'codigos' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE telefono=%s AND usado_at IS NULL AND app<>'pva' ORDER BY id DESC LIMIT 1", $tel ) );
		if ( ! $row || $row->vence_at < CVD_Triciclub_Db::now() ) { return 'expired'; }
		if ( (int) $row->intentos >= 5 ) { return 'tries'; }
		$ok = hash_equals( (string) $row->codigo_hash, hash_hmac( 'sha256', $tel . '|' . preg_replace( '/\D+/', '', $codigo ), self::key() ) );
		$wpdb->update( $t, $ok ? array( 'usado_at' => CVD_Triciclub_Db::now(), 'codigo' => '' ) : array( 'intentos' => (int) $row->intentos + 1 ), array( 'id' => (int) $row->id ) );
		return $ok ? '' : 'wrong';
	}

	/**
	 * 10-nov (Pa'llá «para otra persona»): un aviso de WhatsApp a quien viaja (código para subir, chofer, chapa).
	 * Va por la misma cola que los códigos (el bot no cambia): app «pva», el texto entero en `texto`, vence en 30 min.
	 * No cuenta para el límite de códigos ni sirve para entrar.
	 */
	public static function aviso_whatsapp( string $telefono, string $texto, string $codigo ): bool {
		global $wpdb;
		$tel = self::telefono( $telefono );
		if ( '' === $tel || '' === trim( $texto ) ) { return false; }
		return (bool) $wpdb->insert( CVD_Triciclub_Db::t( 'codigos' ), array(
			'telefono'    => $tel,
			'codigo'      => substr( (string) preg_replace( '/\D+/', '', $codigo ), 0, 6 ) ?: '0',
			'codigo_hash' => hash( 'sha256', wp_generate_password( 32, false ) ),
			'canal'       => 'whatsapp',
			'app'         => 'pva',
			'texto'       => mb_substr( $texto, 0, 600 ),
			'ip'          => '',
			'vence_at'    => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ),
			'created_at'  => CVD_Triciclub_Db::now(),
		) );
	}

	public static function texto_codigo( string $codigo, string $app ): string {
		return 'pv' === $app
			? 'Pa\'llá: tu código es ' . $codigo . '. Vence en 15 minutos. No se lo des a nadie.'
			: 'Triciclub: tu código es ' . $codigo . '. Vence en 15 minutos. No se lo des a nadie.';
	}

	/** GET /bot/triciclub/codigos — los códigos por mandar (uno a uno por WhatsApp; nunca en masa). */
	public static function bot_codigos( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$t = CVD_Triciclub_Db::t( 'codigos' );
		// 5-nov: solo los de WhatsApp (los de SMS los manda la pasarela de SMS, CVD_Pv::sms_pendientes).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, telefono, codigo, app, texto FROM {$t} WHERE enviado_at IS NULL AND usado_at IS NULL AND codigo<>'' AND canal='whatsapp' AND vence_at>%s ORDER BY id ASC LIMIT 20", CVD_Triciclub_Db::now() ) );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'id' => (int) $r->id, 'phone' => (string) $r->telefono, 'text' => '' !== (string) $r->texto ? (string) $r->texto : self::texto_codigo( (string) $r->codigo, (string) $r->app ) );
		}
		// El bot pregunta cada 15 s si Triciclub o Pa'llá están abiertos (y cada 5 min si los dos están cerrados).
		$orden = array( 'no' => 0, 'prueba' => 1, 'si' => 2 );
		$modo = self::mode();
		if ( class_exists( 'CVD_Pv' ) && $orden[ CVD_Pv::mode() ] > $orden[ $modo ] ) { $modo = CVD_Pv::mode(); } // el más abierto de los dos
		$res = rest_ensure_response( array( 'ok' => true, 'mode' => $modo, 'codigos' => $out ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function bot_enviado( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'codigos' ) . ' SET enviado_at=%s WHERE id=%d AND enviado_at IS NULL', CVD_Triciclub_Db::now(), absint( $request['id'] ) ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// ------------------------------------------------------------------ Páginas

	/** 'app' | 'sw' | 'manifest' | 'chofer' | '' */
	public static function path_kind( string $request_uri ): string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
		return array(
			'/triciclub'                      => 'app',
			'/triciclub/'                     => 'app',
			'/triciclub/sw.js'                => 'sw',
			'/triciclub/manifest.webmanifest' => 'manifest',
			'/triciclub/admin'                => 'admin',
			'/triciclub/admin/'               => 'admin',
			'/palla/triciclub'                => 'chofer',
			'/palla/triciclub/'               => 'chofer',
		)[ $path ] ?? '';
	}

	public static function route(): void {
		$kind = self::path_kind( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $kind ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Triciclub' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'sw' === $kind ) { self::service_worker(); }
		if ( 'manifest' === $kind ) { self::manifest(); }
		if ( 'chofer' === $kind ) { self::page_chofer(); }
		if ( 'admin' === $kind ) { CVD_Triciclub_Alta::pagina_admin(); }
		// 5-nov (D138): con Pa'llá transporte encendido, el cliente de Triciclub va a la app nueva (triciclos dentro).
		if ( 'app' === $kind && class_exists( 'CVD_Pv' ) && 'no' !== CVD_Pv::mode() ) { wp_redirect( home_url( '/palla/ir/' ), 302 ); exit; }
		self::page();
	}

	public static function asset( string $file ): string {
		return CVD_URL . 'assets/triciclub/' . $file . '?v=' . rawurlencode( CVD_VERSION );
	}

	private static function tel_triciclub(): string {
		$d = preg_replace( '/\D+/', '', (string) get_option( 'cvd_tc_telefono', self::TELEFONO ) );
		return strlen( $d ) >= 8 ? $d : self::TELEFONO;
	}

	public static function config(): array {
		return array(
			'api'      => rest_url( self::NS . '/triciclub/' ),
			'sw'       => home_url( '/triciclub/sw.js' ),
			'scope'    => home_url( '/triciclub/' ),
			'version'  => CVD_VERSION,
			'mode'     => self::mode(),
			'telefono' => self::tel_triciclub(),
			'logo'     => self::asset( 'logo.png' ),
			'mapaUrl'  => self::asset( 'mapa.js' ), // 3.14.118: el mapa de Triciclub
			'habana'   => array( 23.125, -82.38 ),
			'tipos'    => CVD_Triciclub_Alta::tipos_publicos(), // 3.14.130: los 6 tipos de triciclo, con su dibujo
			'verificar' => CVD_Triciclub_Alta::verificar_on(), // 3.14.132: carné + selfie con carné, comprobados solos
		);
	}

	private static function loading( string $texto ): string {
		return '<main class="tc-carga" role="status"><div class="tc-carga__logo"><img src="' . esc_url( self::asset( 'logo.png' ) ) . '" alt="Triciclub" width="220" height="43"></div>'
			. '<div class="tc-carga__via" aria-hidden="true"><svg class="tc-carga__tri" width="60" height="46" viewBox="0 0 60 46" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h34v18H6z"/><path d="M6 24h48l-4 8H8z"/><path d="M44 6l10 18"/><circle cx="14" cy="36" r="6"/><circle cx="46" cy="36" r="6"/></svg></div>'
			. '<p class="tc-carga__txt">' . esc_html( $texto ) . '</p></main>';
	}

	private static function page(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		$closed = 'no' === self::mode();
		$body = $closed
			? '<main class="tc-cerrado"><img src="' . esc_url( self::asset( 'logo.png' ) ) . '" alt="Triciclub" width="220" height="43"><h1>Muy pronto</h1><p>' . esc_html( self::texto_cerrado( 'closed' ) ) . '</p></main>'
			: '<div id="app" aria-live="polite">' . self::loading( 'Buscando triciclos cerca de ti' ) . '</div>'
				. '<noscript><main class="tc-cerrado"><h1>Activa JavaScript</h1><p>Triciclub necesita JavaScript.</p></main></noscript>'
				. '<script>window.TRICICLUB=' . wp_json_encode( self::config() ) . ';</script>'
				. '<script src="' . esc_url( self::asset( 'app.js' ) ) . '" defer></script>';
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#087a3d"><title>Triciclub</title>'
			. '<link rel="manifest" href="' . esc_url( home_url( '/triciclub/manifest.webmanifest' ) ) . '">'
			. '<link rel="icon" href="' . esc_url( self::asset( 'icon.svg' ) ) . '" type="image/svg+xml">'
			. '<link rel="apple-touch-icon" href="' . esc_url( self::asset( 'icon-192.png' ) ) . '">'
			. '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Triciclub">'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'app.css' ) ) . '">'
			. '</head><body>' . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* arriba.
		exit;
	}

	/** /palla/triciclub — la parte de Triciclub para el chofer, con el estilo y el enlace de Pa'llá. */
	private static function page_chofer(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		$cfg = array_merge( self::config(), array( 'palla' => home_url( '/palla/' ), 'franjas' => CVD_Triciclub_Precio::franjas(), 'pvChofer' => class_exists( 'CVD_Pv' ) && 'no' !== CVD_Pv::mode() ? home_url( '/palla/chofer/' ) : '' ) );
		$body = 'no' === self::mode()
			? '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>Triciclub todavía no está abierto</h1><p>Sigue con Pa\'llá como siempre.</p><a class="pl-btn pl-btn--secondary" href="' . esc_url( home_url( '/palla/' ) ) . '">Volver a Pa\'llá</a></main>'
			: '<div id="app" aria-live="polite"><main class="pl-closed pl-loading" role="status"><div class="pl-logo" aria-hidden="true">P</div><div class="pl-bar" aria-hidden="true"></div><p class="pl-dots">Cargando Triciclub</p></main></div>'
				. '<script>window.TRICICLUB=' . wp_json_encode( $cfg ) . ';</script>'
				. '<script src="' . esc_url( self::asset( 'chofer.js' ) ) . '" defer></script>';
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#121212"><title>Pa\'llá · Triciclub</title>'
			. '<link rel="stylesheet" href="' . esc_url( CVD_Palla::asset( 'app.css' ) ) . '">'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'app.css' ) ) . '">' // 3.14.122: mapa a pantalla completa y hoja, como el cliente
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'chofer.css' ) ) . '">'
			. '</head><body>' . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* arriba.
		exit;
	}

	private static function manifest(): void {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		$scope = (string) wp_parse_url( home_url( '/triciclub/' ), PHP_URL_PATH );
		echo wp_json_encode( array(
			'id'               => $scope,
			'name'             => 'Triciclub · triciclos en La Habana',
			'short_name'       => 'Triciclub',
			'description'      => 'Pide un triciclo para ti, un paquete o una carga. Ves el precio antes y no cambia.',
			'lang'             => 'es',
			'start_url'        => $scope . '?source=pwa',
			'scope'            => $scope,
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#087a3d',
			'theme_color'      => '#087a3d',
			'icons'            => array(
				array( 'src' => self::asset( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::asset( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::asset( 'icon-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/** /triciclub/sw.js: guarda la app; la API siempre va a la red (la cola sin conexión está en la app). */
	private static function service_worker(): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		$shell = wp_json_encode( home_url( '/triciclub/' ) );
		$files = wp_json_encode( array_map( array( __CLASS__, 'asset' ), array( 'app.js', 'mapa.js', 'app.css', 'icon.svg', 'logo.png' ) ), JSON_UNESCAPED_SLASHES );
		echo "const CACHE='tc-" . esc_js( CVD_VERSION ) . "',SHELL={$shell},FILES={$files};\n";
		echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(CACHE).then(c=>Promise.race([Promise.all([SHELL].concat(FILES).map(u=>c.add(u).catch(()=>null))),new Promise(ok=>setTimeout(ok,10000))])).catch(()=>null))});\n";
		echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k.startsWith('tc-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});\n";
		echo "self.addEventListener('fetch',e=>{const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin)return;\n";
		echo "if(r.mode==='navigate'){e.respondWith(fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(SHELL,cp))}return res}).catch(()=>caches.match(SHELL)));return}\n";
		echo "if(u.pathname.indexOf('/assets/triciclub/')!==-1){e.respondWith(caches.match(r).then(x=>x||fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(r,cp))}return res})))}});\n";
		exit;
	}
}
