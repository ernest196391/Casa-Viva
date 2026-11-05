<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026, D138–D140): la API (casa-viva/v1/pv/…). Todo `no-store`.
 *
 * Precio mixto como inDrive:
 *   1. El cliente cotiza (precio justo FIRMADO por clase) y pide con SU oferta (−20 %…+50 %, nunca bajo el mínimo).
 *      El viaje queda «buscando» sin chofer, con un canal secreto de ntfy.
 *   2. Los choferes libres de esa clase cerca ven el pedido y contestan «Acepto» o +10/+20/+30 % (oferta 60 s).
 *      Si el cliente encendió «Coger la 1.ª oferta», el primer «Acepto» se queda con el viaje al momento.
 *   3. El cliente elige una oferta → «asignado» con ese precio, FIJO. Los demás choferes ven «lo cogió otro».
 *   4. Llegué → Recogí (código del cliente; en envío no hace falta) → Terminé (en envío: código de quien recibe + foto)
 *      → Cobrado. Espera: 5 min gratis desde «Llegué», luego 50 CUP/min. Comisión al terminar (CVD_Pv_Cuenta).
 *   Si el chofer suelta el viaje, vuelve a «buscando» para que oferten otros.
 *
 * Los cambios de estado son UPDATE … WHERE estado = el de antes: dos toques o dos teléfonos no se pisan, y repetir un
 * botón (cola sin conexión) devuelve lo mismo. Cada cambio sube `rev` y manda una señal al canal del viaje.
 */
final class CVD_Pv_Api {
	private const NS = 'casa-viva/v1';
	public const BUSCA_SEG = 240;     // 4 min buscando; luego «sin_chofer» (el cliente puede subir el precio o reintentar)
	public const OFERTA_SEG = 60;     // lo que vale la oferta de un chofer
	public const RADIO_1 = 4000;      // m: primeros 45 s
	public const RADIO_2 = 8000;      // m: después
	public const VIVO = 600;          // s: chofer «en línea» sin posición nueva en 10 min no cuenta
	public const ACTIVOS = array( 'asignado', 'llego', 'recogido' );
	public const AVISAR_MAX = 15;     // choferes a los que suena cada pedido nuevo

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$c = array( __CLASS__, 'auth_cliente' );
		$d = array( __CLASS__, 'auth_chofer' );
		$open = '__return_true';
		$r = static function ( string $path, string $method, string $cb, $perm ): void {
			register_rest_route( self::NS, '/pv/' . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		// Cliente
		$r( 'cliente/codigo', 'POST', 'pedir_codigo', $open );
		$r( 'cliente/entrar', 'POST', 'entrar', $open );
		$r( 'yo', 'GET', 'yo', $c );
		$r( 'yo', 'POST', 'yo_guardar', $c );
		$r( 'donde', 'GET', 'donde', $c );
		$r( 'sugerir', 'GET', 'sugerir', $c );
		$r( 'lugar', 'GET', 'lugar', $c );
		$r( 'cercanos', 'GET', 'cercanos', $c );
		$r( 'cotizar', 'GET', 'cotizar', $c );
		$r( 'viajes', 'GET', 'mis_viajes', $c );
		$r( 'viajes', 'POST', 'pedir', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})', 'GET', 'ver', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/precio', 'POST', 'subir', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/elegir', 'POST', 'elegir', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/cancelar', 'POST', 'cancelar', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/valorar', 'POST', 'valorar_cliente', $c );
		$r( 'foto', 'GET', 'ver_foto', $open ); // firmada
		// Chofer
		$r( 'chofer/hoy', 'GET', 'chofer_hoy', $d );
		$r( 'chofer/disponible', 'POST', 'chofer_disponible', $d );
		$r( 'chofer/posicion', 'POST', 'chofer_posicion', $d );
		$r( 'chofer/pedidos/(?P<id>\d+)/ofertar', 'POST', 'ofertar', $d );
		$r( 'chofer/pedidos/(?P<id>\d+)/retirar', 'POST', 'retirar', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/estado', 'POST', 'estado', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/foto', 'POST', 'subir_foto', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/valorar', 'POST', 'valorar_chofer', $d );
		$r( 'chofer/cuenta', 'GET', 'chofer_cuenta', $d );
		$r( 'chofer/pago', 'POST', 'chofer_pago', $d );
		$r( 'chofer/semana', 'GET', 'chofer_semana', $d );
		// SMS de reserva: la pasarela (un móvil con SIM, vía el bot) recoge los códigos pedidos «por SMS» y los manda.
		register_rest_route( self::NS, '/bot/pv/sms', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'sms_pendientes' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
		register_rest_route( self::NS, '/bot/pv/sms/(?P<id>\d+)/enviado', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'sms_enviado' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	/** GET /bot/pv/sms — códigos por mandar por SMS (uno a uno). En prueba, solo a los clientes de prueba (ya filtrado al pedirlos). */
	public static function sms_pendientes( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$t = self::t( 'codigos' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, telefono, codigo, app FROM {$t} WHERE enviado_at IS NULL AND usado_at IS NULL AND codigo<>'' AND canal='sms' AND vence_at>%s ORDER BY id ASC LIMIT 10", self::now() ) );
		return self::out( array( 'ok' => true, 'mode' => CVD_Pv::mode(), 'sms' => array_map( static fn( object $r ): array => array( 'id' => (int) $r->id, 'phone' => (string) $r->telefono, 'text' => CVD_Triciclub::texto_codigo( (string) $r->codigo, (string) $r->app ) ), $rows ) ) );
	}

	public static function sms_enviado( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'codigos' ) . " SET enviado_at=%s WHERE id=%d AND canal='sms' AND enviado_at IS NULL", self::now(), absint( $request['id'] ) ) );
		return self::out( array( 'ok' => true ) );
	}

	public static function auth_cliente( WP_REST_Request $request ) {
		$c = CVD_Pv::request_cliente( $request );
		return is_wp_error( $c ) ? $c : true;
	}

	public static function auth_chofer( WP_REST_Request $request ) {
		$u = CVD_Pv::request_chofer( $request );
		return is_wp_error( $u ) ? $u : true;
	}

	private static function out( array $data, int $status = 200 ): WP_REST_Response {
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá: datos privados' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function fail( string $code, string $message, int $status = 400, array $extra = array() ): WP_REST_Response {
		return self::out( array_merge( array( 'ok' => false, 'code' => $code, 'message' => $message ), $extra ), $status );
	}

	private static function cliente( WP_REST_Request $request ): object { return CVD_Pv::request_cliente( $request ); }

	private static function user( WP_REST_Request $request ): WP_User { return CVD_Pv::request_chofer( $request ); }

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	private static function coord( $v, bool $lat ): ?float {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) { return null; }
		$f = (float) $v;
		return $lat ? ( $f >= 19.5 && $f <= 23.5 ? $f : null ) : ( $f >= -85.0 && $f <= -74.0 ? $f : null );
	}

	private static function ip(): string { return substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ); }

	private static function now(): string { return CVD_Triciclub_Db::now(); }

	private static function iso( ?string $mysql ): ?string { return $mysql ? gmdate( 'c', (int) strtotime( $mysql . ' UTC' ) ) : null; }

	private static function t( string $n ): string { return CVD_Triciclub_Db::t( $n ); }

	// ================================================================== Entrar

	public static function pedir_codigo( WP_REST_Request $request ): WP_REST_Response {
		if ( 'no' === CVD_Pv::mode() ) { return self::fail( 'pv_closed', CVD_Pv::texto_cerrado( 'closed' ), 403 ); }
		$tel = CVD_Triciclub::telefono( $request->get_param( 'telefono' ) );
		if ( '' === $tel ) { return self::fail( 'phone', 'Escribe tu móvil: 8 números, empieza por 5.' ); }
		$r = CVD_Pv::cliente_bloqueado( $tel );
		if ( '' !== $r ) { return self::fail( 'pv_' . $r, CVD_Pv::texto_cerrado( $r ), 403 ); }
		$canal = 'sms' === sanitize_key( (string) $request->get_param( 'canal' ) ) ? 'sms' : 'whatsapp';
		$err = CVD_Triciclub::pedir_codigo( $tel, self::ip(), 4, $canal, 'pv' );
		if ( 'limit' === $err ) { return self::fail( 'limit', 'Ya pediste varios códigos. Espera una hora.', 429 ); }
		return self::out( array( 'ok' => true, 'canal' => $canal, 'message' => 'sms' === $canal ? 'Te mandamos un SMS con el código.' : 'Te mandamos el código por WhatsApp.' ) );
	}

	public static function entrar( WP_REST_Request $request ): WP_REST_Response {
		if ( 'no' === CVD_Pv::mode() ) { return self::fail( 'pv_closed', CVD_Pv::texto_cerrado( 'closed' ), 403 ); }
		$tel = CVD_Triciclub::telefono( $request->get_param( 'telefono' ) );
		if ( '' === $tel ) { return self::fail( 'phone', 'Escribe tu móvil.' ); }
		$r = CVD_Pv::cliente_bloqueado( $tel );
		if ( '' !== $r ) { return self::fail( 'pv_' . $r, CVD_Pv::texto_cerrado( $r ), 403 ); }
		$err = CVD_Triciclub::comprobar_codigo( $tel, (string) $request->get_param( 'codigo' ) );
		if ( '' !== $err ) {
			return self::fail( 'code_' . $err, array( 'wrong' => 'Ese código no es. Míralo bien.', 'expired' => 'El código venció. Pide otro.', 'tries' => 'Demasiados intentos. Pide otro código.' )[ $err ] ?? 'No se pudo.' );
		}
		$c = CVD_Triciclub::crear_cliente( $tel, self::txt( $request->get_param( 'nombre' ), 80 ), true );
		if ( ! $c ) { return self::fail( 'error', 'No se pudo entrar. Prueba otra vez.', 500 ); }
		if ( (int) $c->bloqueado ) { return self::fail( 'pv_blocked', CVD_Pv::texto_cerrado( 'blocked' ), 403 ); }
		return self::out( array( 'ok' => true, 'token' => CVD_Triciclub::token_cliente( $c ), 'yo' => self::yo_de( $c ) ) );
	}

	private static function yo_de( object $c ): array {
		$lugares = json_decode( (string) get_option( 'cvd_pv_lugares_' . (int) $c->id, '' ), true );
		return array(
			'nombre'   => (string) $c->nombre,
			'telefono' => (string) $c->telefono,
			'viajes'   => (int) $c->viajes_total,
			'nota'     => null !== $c->nota_media ? round( (float) $c->nota_media, 1 ) : null,
			'lugares'  => is_array( $lugares ) ? $lugares : new stdClass(),
		);
	}

	public static function yo( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$activo = self::activo_cliente( (int) $c->id );
		// Recientes: los 5 últimos destinos distintos (para «¿A dónde vamos?» sin escribir).
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT destino_texto, destino_lat, destino_lng, MAX(id) m FROM ' . self::t( 'viajes' ) . " WHERE cliente_id=%d AND destino_texto<>'' GROUP BY destino_texto, destino_lat, destino_lng ORDER BY m DESC LIMIT 5", (int) $c->id ) );
		$rec = array_map( static fn( object $r ): array => array( 'texto' => (string) $r->destino_texto, 'lat' => (float) $r->destino_lat, 'lng' => (float) $r->destino_lng ), $rows );
		return self::out( array( 'ok' => true, 'yo' => self::yo_de( $c ), 'activo' => $activo ? self::vista_cliente( $activo ) : null, 'recientes' => $rec, 'noche' => CVD_Pv_Tarifa::noche() ) );
	}

	/** POST yo {nombre?, lugares?:{casa:{texto,lat,lng}, trabajo:{…}}} */
	public static function yo_guardar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$p = (array) $request->get_json_params();
		if ( isset( $p['nombre'] ) && mb_strlen( self::txt( $p['nombre'], 80 ) ) >= 2 ) {
			$wpdb->update( self::t( 'clientes' ), array( 'nombre' => self::txt( $p['nombre'], 80 ), 'updated_at' => self::now() ), array( 'id' => (int) $c->id ) );
		}
		if ( isset( $p['lugares'] ) && is_array( $p['lugares'] ) ) {
			$l = array();
			foreach ( array( 'casa', 'trabajo' ) as $k ) {
				$v = $p['lugares'][ $k ] ?? null;
				$lat = is_array( $v ) ? self::coord( $v['lat'] ?? null, true ) : null;
				$lng = is_array( $v ) ? self::coord( $v['lng'] ?? null, false ) : null;
				if ( null !== $lat && null !== $lng ) { $l[ $k ] = array( 'texto' => self::txt( $v['texto'] ?? '', 160 ), 'lat' => $lat, 'lng' => $lng ); }
			}
			update_option( 'cvd_pv_lugares_' . (int) $c->id, wp_json_encode( $l ), false );
		}
		return self::out( array( 'ok' => true, 'yo' => self::yo_de( CVD_Triciclub::cliente( (int) $c->id ) ) ) );
	}

	// Direcciones: las mismas de Triciclub (15.110 lugares de La Habana + calles).
	public static function donde( WP_REST_Request $request ): WP_REST_Response { return CVD_Triciclub_Api::donde( $request ); }
	public static function sugerir( WP_REST_Request $request ): WP_REST_Response { return CVD_Triciclub_Api::sugerir( $request ); }
	public static function lugar( WP_REST_Request $request ): WP_REST_Response { return CVD_Triciclub_Api::lugar( $request ); }

	// ================================================================== Choferes cerca

	/** La clase de un chofer (por el tipo de su alta). */
	public static function clase_de( object $ch ): string {
		return CVD_Pv_Tarifa::TIPO_A_CLASE[ CVD_Triciclub_Alta::tipo_de( $ch ) ] ?? 'tric_electrico';
	}

	/** ¿Puede este chofer llevar un pedido de esta clase (y este tamaño de envío)? */
	public static function sirve( object $ch, string $clase, string $tam = '' ): bool {
		$mia = self::clase_de( $ch );
		$serv = explode( ',', (string) $ch->servicios );
		if ( 'mensajero' !== $clase ) { return $mia === $clase && ( in_array( 'viaje', $serv, true ) || 'tric_carga' === $mia ); }
		if ( 'mensajero' === $mia ) { return 'grande' !== $tam; }
		if ( ! in_array( 'paquete', $serv, true ) && ! in_array( 'carga', $serv, true ) ) { return false; }
		if ( in_array( $mia, array( 'moto', 'tric_bici' ), true ) ) { return 'grande' !== $tam; }
		return 'tric' === CVD_Pv_Tarifa::grupo( $mia ); // triciclos: cualquier tamaño
	}

	/** Choferes en línea (aprobados, con posición reciente, sin viaje en marcha). En prueba: solo los probadores. */
	private static function en_linea(): array {
		global $wpdb;
		$t = self::t( 'choferes' );
		$v = self::t( 'viajes' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.* FROM {$t} c WHERE c.estado='aprobado' AND c.disponible=1 AND c.ubicado_at>%s AND c.lat IS NOT NULL
			AND NOT EXISTS (SELECT 1 FROM {$v} x WHERE x.chofer_id=c.user_id AND x.estado IN ('asignado','llego','recogido'))
			LIMIT 300", gmdate( 'Y-m-d H:i:s', time() - self::VIVO ) ) );
		if ( 'prueba' === CVD_Pv::mode() ) {
			$testers = CVD_Triciclub::testers();
			$rows = array_values( array_filter( $rows, static fn( object $r ): bool => in_array( (int) $r->user_id, $testers, true ) ) );
		}
		return $rows;
	}

	/** GET cercanos?lat&lng&grupo — puntos (algo movidos) de los vehículos libres, para pintarlos en el mapa. */
	public static function cercanos( WP_REST_Request $request ): WP_REST_Response {
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		$grupo = sanitize_key( (string) $request->get_param( 'grupo' ) );
		if ( null === $lat || null === $lng ) { return self::out( array( 'ok' => true, 'puntos' => array() ) ); }
		$out = array();
		foreach ( self::en_linea() as $ch ) {
			$cl = self::clase_de( $ch );
			if ( '' !== $grupo && CVD_Pv_Tarifa::grupo( $cl ) !== $grupo && ! ( 'mens' === $grupo && self::sirve( $ch, 'mensajero' ) ) ) { continue; }
			if ( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, $lat, $lng ) > 5000 ) { continue; }
			$out[] = array( 'lat' => round( (float) $ch->lat + wp_rand( -4, 4 ) / 10000, 5 ), 'lng' => round( (float) $ch->lng + wp_rand( -4, 4 ) / 10000, 5 ), 'img' => CVD_Pv_Tarifa::CLASES[ $cl ]['img'] );
			if ( count( $out ) >= 20 ) { break; }
		}
		return self::out( array( 'ok' => true, 'puntos' => $out ) );
	}

	/**
	 * GET cotizar?grupo=taxi|tric|moto|mens&olat&olng&dlat&dlng[&tam] — por cada clase del grupo: precio justo, límites
	 * de la oferta, minutos, cuántos libres cerca y cuánto tarda el más cercano; todo con la cotización firmada (10 min).
	 */
	public static function cotizar( WP_REST_Request $request ): WP_REST_Response {
		$grupo = sanitize_key( (string) $request->get_param( 'grupo' ) );
		if ( ! in_array( $grupo, array( 'taxi', 'tric', 'moto', 'mens' ), true ) ) { $grupo = 'tric'; }
		$olat = self::coord( $request->get_param( 'olat' ), true );
		$olng = self::coord( $request->get_param( 'olng' ), false );
		$dlat = self::coord( $request->get_param( 'dlat' ), true );
		$dlng = self::coord( $request->get_param( 'dlng' ), false );
		if ( null === $olat || null === $olng || null === $dlat || null === $dlng ) { return self::fail( 'puntos', 'Falta dónde recoger o a dónde ir.' ); }
		$tam = sanitize_key( (string) $request->get_param( 'tam' ) );
		$tam = isset( CVD_Pv_Tarifa::TAMANOS[ $tam ] ) ? $tam : 'mano';
		$tray = CVD_Triciclub_Precio::trayecto( $olat, $olng, $dlat, $dlng );
		$recta = CVD_Triciclub_Precio::recta_m( $olat, $olng, $dlat, $dlng );
		$noche = CVD_Pv_Tarifa::noche();
		$libres = self::en_linea();
		$clases = 'mens' === $grupo ? array( 'mensajero' ) : array_keys( array_filter( CVD_Pv_Tarifa::CLASES, static fn( array $c ): bool => $c['grupo'] === $grupo ) );
		$lista = array();
		foreach ( $clases as $clase ) {
			$precio_de = 'mensajero' === $clase && 'grande' === $tam ? 'tric_carga' : $clase;
			$metros = CVD_Pv_Tarifa::metros_cobro( $precio_de, (int) $tray['metros'], $recta );
			$justo = CVD_Pv_Tarifa::justo( $precio_de, $metros, $noche );
			$lim = CVD_Pv_Tarifa::limites( $precio_de, $justo, $noche );
			$cerca = null;
			$n = 0;
			foreach ( $libres as $ch ) {
				if ( ! self::sirve( $ch, $clase, 'mensajero' === $clase ? $tam : '' ) ) { continue; }
				$d = CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, $olat, $olng );
				if ( $d > self::RADIO_2 ) { continue; }
				$n++;
				$cerca = null === $cerca ? $d : min( $cerca, $d );
			}
			$minutos = CVD_Pv_Tarifa::minutos( $precio_de, $metros );
			$lista[] = array(
				'clase'   => $clase,
				'justo'   => $justo,
				'min'     => $lim[0],
				'max'     => $lim[1],
				'km'      => round( $metros / 1000, 1 ),
				'minutos' => $minutos,
				'libres'  => $n,
				'llega'   => null === $cerca ? null : CVD_Pv_Tarifa::minutos( $clase, (int) round( $cerca * 1.3 ) ),
				'firma'   => CVD_Pv_Tarifa::firmar( array( 'clase' => $clase, 'olat' => $olat, 'olng' => $olng, 'dlat' => $dlat, 'dlng' => $dlng, 'metros' => $metros, 'minutos' => $minutos, 'justo' => $justo, 'noche' => $noche, 'tam' => 'mensajero' === $clase ? $tam : '' ) ),
			);
		}
		return self::out( array( 'ok' => true, 'grupo' => $grupo, 'noche' => $noche, 'como' => $tray['como'], 'linea' => $tray['linea'] ?? null, 'clases' => $lista, 'vence' => time() + CVD_Pv_Tarifa::FIRMA_TTL ) );
	}

	// ================================================================== Pedir

	/**
	 * POST viajes {cid, firma, precio, primera, forma_pago, origen_texto, destino_texto, nota,
	 *              envio?: {que, recibe_nombre, recibe_telefono, cobrar_cup}}
	 */
	public static function pedir( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$cid = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_param( 'cid' ) ), 0, 40 );
		if ( '' === $cid ) { return self::fail( 'cid', 'Falta el identificador del pedido.' ); }
		$v = self::t( 'viajes' );
		$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v} WHERE cliente_id=%d AND cid=%s", (int) $c->id, $cid ) );
		if ( $ya ) { return self::out( array( 'ok' => true, 'repetido' => true, 'viaje' => self::vista_cliente( self::caducar( $ya ) ) ) ); }
		$q = CVD_Pv_Tarifa::verificar( (string) $request->get_param( 'firma' ) );
		if ( ! $q || ! CVD_Pv_Tarifa::clase_ok( (string) $q['clase'] ) ) { return self::fail( 'precio_vencido', 'El precio caducó. Vuelve a mirarlo.', 409 ); }
		if ( self::activo_cliente( (int) $c->id ) ) { return self::fail( 'ya_tienes', 'Ya tienes un viaje en marcha.', 409 ); }
		$clase = (string) $q['clase'];
		$envio = 'mensajero' === $clase;
		$precio_de = $envio && 'grande' === $q['tam'] ? 'tric_carga' : $clase;
		$lim = CVD_Pv_Tarifa::limites( $precio_de, (int) $q['justo'], (bool) $q['noche'] );
		$oferta = CVD_Pv_Tarifa::ajustar( (int) $request->get_param( 'precio' ), $lim );
		$e = (array) $request->get_param( 'envio' );
		if ( $envio && mb_strlen( self::txt( $e['que'] ?? '', 120 ) ) < 2 ) { return self::fail( 'envio', 'Escribe qué mandas.' ); }
		if ( $envio && '' === CVD_Triciclub::telefono( $e['recibe_telefono'] ?? '' ) ) { return self::fail( 'envio', 'Falta el teléfono de quien recibe.' ); }
		$now = self::now();
		$row = array(
			'uuid'            => wp_generate_uuid4(),
			'cliente_id'      => (int) $c->id,
			'cid'             => $cid,
			'chofer_id'       => 0,
			'servicio'        => $envio ? 'paquete' : ( 'tric_carga' === $clase ? 'carga' : 'viaje' ),
			'clase'           => $clase,
			'estado'          => 'buscando',
			'origen_texto'    => self::txt( $request->get_param( 'origen_texto' ), 160 ),
			'origen_lat'      => $q['olat'],
			'origen_lng'      => $q['olng'],
			'destino_texto'   => self::txt( $request->get_param( 'destino_texto' ), 160 ),
			'destino_lat'     => $q['dlat'],
			'destino_lng'     => $q['dlng'],
			'distancia_m'     => (int) $q['metros'],
			'minutos'         => (int) $q['minutos'],
			'precio'          => $oferta,
			'precio_justo'    => (int) $q['justo'],
			'precio_oferta'   => $oferta,
			'primera'         => rest_sanitize_boolean( $request->get_param( 'primera' ) ) ? 1 : 0,
			'noche'           => (int) (bool) $q['noche'],
			'forma_pago'      => 'transferencia' === sanitize_key( (string) $request->get_param( 'forma_pago' ) ) ? 'transferencia' : 'efectivo',
			'codigo'          => str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ),
			'nota'            => self::txt( $request->get_param( 'nota' ), 200 ),
			'envio_que'       => $envio ? self::txt( $e['que'] ?? '', 120 ) : '',
			'envio_tam'       => $envio ? (string) $q['tam'] : '',
			'recibe_nombre'   => $envio ? self::txt( $e['recibe_nombre'] ?? '', 80 ) : '',
			'recibe_telefono' => $envio ? CVD_Triciclub::telefono( $e['recibe_telefono'] ?? '' ) : '',
			'cobrar_cup'      => $envio ? max( 0, min( 500000, (int) ( $e['cobrar_cup'] ?? 0 ) ) ) : 0,
			'entrega_codigo'  => $envio ? str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ) : '',
			'rev'             => 1,
			'busca_desde'     => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		if ( ! $wpdb->insert( $v, $row ) ) {
			$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v} WHERE cliente_id=%d AND cid=%s", (int) $c->id, $cid ) );
			return $ya ? self::out( array( 'ok' => true, 'repetido' => true, 'viaje' => self::vista_cliente( $ya ) ) ) : self::fail( 'error', 'No se pudo pedir. Prueba otra vez.', 500 );
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->update( $v, array( 'canal' => CVD_Pv_Canal::nuevo_tema( $id ) ), array( 'id' => $id ) );
		$viaje = self::viaje( $id );
		CVD_Triciclub_Db::evento( $id, 'cliente', (int) $c->id, 'pv_pedido', array( 'clase' => $clase, 'oferta' => $oferta, 'justo' => (int) $q['justo'] ) );
		self::avisar_choferes( $viaje, false );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $viaje ) ) );
	}

	/** Suena a los choferes que pueden llevarlo (los más cercanos primero). $subio = el cliente subió el precio. */
	private static function avisar_choferes( object $viaje, bool $subio ): void {
		$lista = array();
		foreach ( self::en_linea() as $ch ) {
			if ( ! self::puede_ver( $ch, $viaje ) ) { continue; }
			$lista[] = array( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $viaje->origen_lat, (float) $viaje->origen_lng ), (int) $ch->user_id );
		}
		sort( $lista );
		$titulo = ( 'paquete' === $viaje->servicio ? '📦 Envío' : '🚖 Pedido' ) . ( $subio ? ' · subió a ' : ' · ' ) . number_format( (int) $viaje->precio_oferta, 0, ',', '.' ) . ' CUP';
		$txt = self::corto( (string) $viaje->origen_texto ) . ' → ' . self::corto( (string) $viaje->destino_texto ) . ' · ' . round( (int) $viaje->distancia_m / 1000, 1 ) . ' km. Acepta o pide más.';
		foreach ( array_slice( $lista, 0, self::AVISAR_MAX ) as $l ) {
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $l[1] ), array( 't' => 'pedido', 'id' => (int) $viaje->id ) );
			if ( ! $subio ) { self::aviso( $l[1], 'pv_pedido', $titulo, $txt, array( 'pv', 'pedido' ) ); }
		}
	}

	private static function aviso( int $user_id, string $type, string $title, string $txt, array $tags ): void {
		try {
			if ( class_exists( 'CVD_Palla_Push' ) && method_exists( 'CVD_Palla_Push', 'avisar' ) ) { CVD_Palla_Push::avisar( $user_id, $type, $title, $txt, home_url( '/palla/chofer/' ), $tags ); }
		} catch ( Throwable $e ) { unset( $e ); }
	}

	private static function corto( string $t ): string { return trim( explode( ',', $t )[0] ) ?: 'Punto en el mapa'; }

	/** ¿Ve este chofer este pedido? Clase, distancia (4 km; 8 km pasados 45 s) y, si es en efectivo, sin deuda al tope. */
	private static function puede_ver( object $ch, object $viaje ): bool {
		if ( (int) $ch->user_id === (int) $viaje->chofer_id ) { return false; }
		if ( ! self::sirve( $ch, (string) $viaje->clase, (string) $viaje->envio_tam ) ) { return false; }
		$edad = time() - (int) strtotime( (string) ( $viaje->busca_desde ?: $viaje->created_at ) . ' UTC' );
		$radio = $edad > 45 ? self::RADIO_2 : self::RADIO_1;
		if ( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $viaje->origen_lat, (float) $viaje->origen_lng ) > $radio ) { return false; }
		if ( 'efectivo' === $viaje->forma_pago && CVD_Pv_Cuenta::pausa_efectivo( (int) $ch->user_id, CVD_Pv_Tarifa::grupo( self::clase_de( $ch ) ) ) ) { return false; }
		return true;
	}

	// ================================================================== El viaje (cliente)

	public static function viaje( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE id=%d AND clase<>''", $id ) );
		return $row ? self::caducar( $row ) : null;
	}

	private static function por_uuid( string $uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE uuid=%s AND clase<>''", $uuid ) );
		return $row ? self::caducar( $row ) : null;
	}

	/** «buscando» de más de 4 min → «sin_chofer». */
	private static function caducar( object $v ): object {
		if ( 'buscando' !== $v->estado ) { return $v; }
		$desde = (int) strtotime( (string) ( $v->busca_desde ?: $v->created_at ) . ' UTC' );
		if ( $desde > time() - self::BUSCA_SEG ) { return $v; }
		global $wpdb;
		$now = self::now();
		if ( $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='sin_chofer', rev=rev+1, updated_at=%s WHERE id=%d AND estado='buscando'", $now, (int) $v->id ) ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='caducada', updated_at=%s WHERE viaje_id=%d AND estado='activa'", $now, (int) $v->id ) );
			CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', 0, 'pv_sin_chofer' );
			$v->rev = (int) $v->rev + 1;
		}
		$v->estado = 'sin_chofer';
		return $v;
	}

	private static function activo_cliente( int $cliente_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE cliente_id=%d AND clase<>'' AND estado IN ('buscando','asignado','llego','recogido') ORDER BY id DESC LIMIT 1", $cliente_id ) );
		if ( ! $row ) { return null; }
		$row = self::caducar( $row );
		return in_array( $row->estado, array( 'buscando', 'asignado', 'llego', 'recogido' ), true ) ? $row : null;
	}

	/** Sube `rev`, apunta el evento y manda la señal al canal del cliente. */
	private static function tocar( object $viaje, string $tipo, array $extra = array() ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . ' SET rev=rev+1 WHERE id=%d', (int) $viaje->id ) );
		$rev = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT rev FROM ' . self::t( 'viajes' ) . ' WHERE id=%d', (int) $viaje->id ) );
		if ( '' !== (string) $viaje->canal ) { CVD_Pv_Canal::senal( (string) $viaje->canal, array_merge( array( 't' => $tipo, 'rev' => $rev ), $extra ) ); }
	}

	/** Lo que el cliente ve de un chofer (nunca su teléfono antes de que lo elija). */
	public static function chofer_publico( int $user_id ): ?array {
		$ch = CVD_Triciclub::chofer_de( $user_id );
		if ( ! $ch ) { return null; }
		$u = get_userdata( $user_id );
		$limpio = trim( (string) preg_replace( '/\([^)]*\)/u', '', $u ? (string) $u->display_name : '' ) );
		$partes = preg_split( '/\s+/', '' !== $limpio ? $limpio : 'Chofer' );
		$nombre = $partes[0] . ( isset( $partes[1] ) && preg_match( '/^\p{L}/u', $partes[1] ) ? ' ' . mb_substr( $partes[1], 0, 1 ) . '.' : '' );
		$clase = self::clase_de( $ch );
		$hoy = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) ) )->format( 'Y-m-d' );
		return array(
			'nombre' => $nombre,
			'nota'   => null !== $ch->nota_media ? round( (float) $ch->nota_media, 1 ) : null,
			'viajes' => (int) $ch->viajes_total,
			'clase'  => $clase,
			'tipo'   => CVD_Pv_Tarifa::CLASES[ $clase ]['nombre'],
			'img'    => CVD_Pv_Tarifa::CLASES[ $clase ]['img'],
			'color'  => (string) $ch->color,
			'chapa'  => (string) $ch->chapa,
			'lot_ok' => '' !== (string) $ch->lot_numero && null !== $ch->lot_vence && (string) $ch->lot_vence >= $hoy,
			'foto'   => CVD_Triciclub_Alta::foto_url( $ch, 'perfil', true ),
		);
	}

	private static function ofertas_de( object $viaje ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'ofertas' ) . " WHERE viaje_id=%d AND estado='activa' AND vence_at>%s ORDER BY precio ASC, llega_min ASC LIMIT 12", (int) $viaje->id, self::now() ) );
		$out = array();
		foreach ( $rows as $o ) {
			$pub = self::chofer_publico( (int) $o->chofer_id );
			if ( ! $pub ) { continue; }
			$out[] = array( 'id' => (int) $o->id, 'precio' => (int) $o->precio, 'pct' => (int) $o->pct, 'llega' => (int) $o->llega_min, 'vence' => self::iso( $o->vence_at ), 'chofer' => $pub );
		}
		return $out;
	}

	private static function posicion( object $viaje ): ?array {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT lat, lng, created_at FROM ' . self::t( 'ubicaciones' ) . ' WHERE viaje_id=%d ORDER BY id DESC LIMIT 1', (int) $viaje->id ) );
		if ( ! $p ) {
			$ch = CVD_Triciclub::chofer_de( (int) $viaje->chofer_id );
			if ( ! $ch || null === $ch->lat || ! $ch->ubicado_at ) { return null; }
			$p = (object) array( 'lat' => $ch->lat, 'lng' => $ch->lng, 'created_at' => $ch->ubicado_at );
		}
		return array( 'lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'hace' => max( 0, time() - (int) strtotime( $p->created_at . ' UTC' ) ) );
	}

	/** El viaje como lo ve el cliente. */
	public static function vista_cliente( object $v ): array {
		$activo = in_array( $v->estado, self::ACTIVOS, true );
		$chofer = (int) $v->chofer_id ? self::chofer_publico( (int) $v->chofer_id ) : null;
		// Al terminar y hasta que cobre, el cliente sigue viendo cómo pagarle (puede cambiar a Transfermóvil en la llegada).
		$por_pagar = 'terminado' === $v->estado && null === $v->cobrado_at;
		if ( $chofer && ( $activo || $por_pagar ) ) {
			$tel = preg_replace( '/\D+/', '', (string) ( get_user_meta( (int) $v->chofer_id, '_cvd_whatsapp', true ) ?: get_user_meta( (int) $v->chofer_id, 'billing_phone', true ) ) );
			$chofer['telefono'] = 8 === strlen( $tel ) ? '53' . $tel : $tel;
			if ( 'transferencia' === $v->forma_pago || $por_pagar ) { $ch = CVD_Triciclub::chofer_de( (int) $v->chofer_id ); $chofer['transferencia'] = $ch ? (string) $ch->transferencia : ''; }
		}
		$envio = 'mensajero' === $v->clase;
		$precio_de = $envio && 'grande' === $v->envio_tam ? 'tric_carga' : (string) $v->clase;
		$lim = CVD_Pv_Tarifa::limites( $precio_de, (int) $v->precio_justo, (bool) $v->noche );
		$espera = 'llego' === $v->estado ? CVD_Pv_Tarifa::espera( $v->llego_at, self::now() ) : (int) $v->espera_cup;
		return array(
			'uuid'          => (string) $v->uuid,
			'estado'        => (string) $v->estado,
			'rev'           => (int) $v->rev,
			'canal'         => (string) $v->canal,
			'clase'         => (string) $v->clase,
			'grupo'         => CVD_Pv_Tarifa::grupo( (string) $v->clase ),
			'origen'        => array( 'texto' => (string) $v->origen_texto, 'lat' => (float) $v->origen_lat, 'lng' => (float) $v->origen_lng ),
			'destino'       => array( 'texto' => (string) $v->destino_texto, 'lat' => (float) $v->destino_lat, 'lng' => (float) $v->destino_lng ),
			'km'            => round( (int) $v->distancia_m / 1000, 1 ),
			'minutos'       => (int) $v->minutos,
			'justo'         => (int) $v->precio_justo,
			'oferta'        => (int) $v->precio_oferta,
			'min'           => $lim[0],
			'max'           => $lim[1],
			'precio'        => (int) $v->precio,
			'espera'        => $espera,
			'total'         => (int) $v->precio + $espera,
			'noche'         => (bool) (int) $v->noche,
			'primera'       => (bool) (int) $v->primera,
			'forma_pago'    => (string) $v->forma_pago,
			'codigo'        => $envio ? '' : (string) $v->codigo,
			'nota'          => (string) $v->nota,
			'chofer'        => $chofer,
			'ofertas'       => 'buscando' === $v->estado ? self::ofertas_de( $v ) : array(),
			'busca_hasta'   => 'buscando' === $v->estado ? gmdate( 'c', (int) strtotime( (string) ( $v->busca_desde ?: $v->created_at ) . ' UTC' ) + self::BUSCA_SEG ) : null,
			'pos'           => $activo ? self::posicion( $v ) : null,
			'linea'         => CVD_Triciclub_Precio::linea_guardada( (float) $v->origen_lat, (float) $v->origen_lng, (float) $v->destino_lat, (float) $v->destino_lng ),
			'envio'         => $envio ? array( 'que' => (string) $v->envio_que, 'tam' => (string) $v->envio_tam, 'recibe' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'cobrar' => (int) $v->cobrar_cup, 'codigo' => (string) $v->entrega_codigo, 'foto' => '' !== (string) $v->entrega_foto ? self::foto_url( $v ) : '' ) : null,
			'llego'         => self::iso( $v->llego_at ),
			'recogido'      => self::iso( $v->recogido_at ),
			'terminado'     => self::iso( $v->terminado_at ),
			'cobrado'       => null !== $v->cobrado_at,
			'motivo'        => (string) $v->motivo,
			'cancelado_por' => (string) $v->cancelado_por,
			'creado'        => self::iso( $v->created_at ),
			'valorado'      => self::valorado( (int) $v->id, 'cliente' ),
		);
	}

	/** GET viajes/{uuid}?rev=N — si no cambió nada desde N, contesta corto (la app pregunta cada pocos segundos). */
	public static function ver( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		$rev = $request->get_param( 'rev' );
		// En «buscando» las ofertas caducan solas (sin tocar rev), y en «llego» corre la espera: ahí siempre todo.
		if ( null !== $rev && (int) $rev === (int) $v->rev && ! in_array( $v->estado, array( 'buscando', 'llego' ), true ) ) {
			$out = array( 'ok' => true, 'igual' => true, 'rev' => (int) $v->rev );
			if ( in_array( $v->estado, self::ACTIVOS, true ) ) { $out['pos'] = self::posicion( $v ); }
			return self::out( $out );
		}
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) );
	}

	public static function mis_viajes( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE cliente_id=%d AND clase<>'' ORDER BY id DESC LIMIT 30", (int) $c->id ) );
		$out = array();
		foreach ( $rows as $r ) {
			$r = self::caducar( $r );
			$ch = (int) $r->chofer_id ? self::chofer_publico( (int) $r->chofer_id ) : null;
			$out[] = array( 'uuid' => (string) $r->uuid, 'estado' => (string) $r->estado, 'clase' => (string) $r->clase, 'grupo' => CVD_Pv_Tarifa::grupo( (string) $r->clase ), 'destino' => array( 'texto' => (string) $r->destino_texto, 'lat' => (float) $r->destino_lat, 'lng' => (float) $r->destino_lng ), 'origen' => array( 'texto' => (string) $r->origen_texto, 'lat' => (float) $r->origen_lat, 'lng' => (float) $r->origen_lng ), 'total' => (int) $r->precio + (int) $r->espera_cup, 'chofer' => $ch ? $ch['nombre'] : '', 'fecha' => self::iso( $r->created_at ), 'foto' => '' !== (string) $r->entrega_foto );
		}
		return self::out( array( 'ok' => true, 'viajes' => $out ) );
	}

	/** POST viajes/{uuid}/precio {precio} — el cliente sube (o baja) su oferta mientras busca; suena a los choferes. */
	public static function subir( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		if ( ! in_array( $v->estado, array( 'buscando', 'sin_chofer' ), true ) ) { return self::fail( 'tarde', 'Ya tienes chofer.', 409 ); }
		$precio_de = 'mensajero' === $v->clase && 'grande' === $v->envio_tam ? 'tric_carga' : (string) $v->clase;
		$p = CVD_Pv_Tarifa::ajustar( (int) $request->get_param( 'precio' ), CVD_Pv_Tarifa::limites( $precio_de, (int) $v->precio_justo, (bool) $v->noche ) );
		$now = self::now();
		// Volver a buscar desde ahora (4 min más). Las ofertas «Acepto» viejas quedan (son más baratas para el cliente).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET precio_oferta=%d, precio=%d, estado='buscando', busca_desde=%s, updated_at=%s WHERE id=%d AND estado IN ('buscando','sin_chofer')", $p, $p, $now, $now, (int) $v->id ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'cliente', (int) $c->id, 'pv_precio', array( 'antes' => (int) $v->precio_oferta, 'ahora' => $p ) );
		$v = self::viaje( (int) $v->id );
		self::tocar( $v, 'precio' );
		self::avisar_choferes( $v, true );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::viaje( (int) $v->id ) ) ) );
	}

	/** POST viajes/{uuid}/elegir {oferta} */
	public static function elegir( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		$oid = absint( $request->get_param( 'oferta' ) );
		if ( in_array( $v->estado, self::ACTIVOS, true ) && (int) $v->oferta_id === $oid ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) ); } // repetido
		$err = self::asignar( $v, $oid );
		if ( '' !== $err ) { return self::fail( 'oferta', $err, 409, array( 'viaje' => self::vista_cliente( self::viaje( (int) $v->id ) ) ) ); }
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::viaje( (int) $v->id ) ) ) );
	}

	/** El viaje pasa a «asignado» con esa oferta. Devuelve '' o por qué no. */
	private static function asignar( object $v, int $oferta_id ): string {
		global $wpdb;
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'ofertas' ) . ' WHERE id=%d AND viaje_id=%d', $oferta_id, (int) $v->id ) );
		if ( ! $o || 'activa' !== $o->estado || $o->vence_at < self::now() ) { return 'Esa oferta ya no vale. Elige otra.'; }
		if ( 'buscando' !== $v->estado ) { return 'Este pedido ya no está buscando chofer.'; }
		if ( self::ocupado( (int) $o->chofer_id ) ) {
			$wpdb->update( self::t( 'ofertas' ), array( 'estado' => 'retirada', 'updated_at' => self::now() ), array( 'id' => (int) $o->id ) );
			return 'Ese chofer acaba de coger otro viaje. Elige otra oferta.';
		}
		$now = self::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='asignado', chofer_id=%d, precio=%d, oferta_id=%d, respondido_at=%s, updated_at=%s WHERE id=%d AND estado='buscando'", (int) $o->chofer_id, (int) $o->precio, (int) $o->id, $now, $now, (int) $v->id ) );
		if ( ! $n ) { return 'Este pedido ya cambió.'; }
		$of = self::t( 'ofertas' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$of} SET estado='elegida', updated_at=%s WHERE id=%d", $now, (int) $o->id ) );
		$perdieron = $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$of} WHERE viaje_id=%d AND id<>%d AND estado='activa'", (int) $v->id, (int) $o->id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$of} SET estado='perdio', updated_at=%s WHERE viaje_id=%d AND id<>%d AND estado='activa'", $now, (int) $v->id, (int) $o->id ) );
		// Sus otras ofertas abiertas se retiran (ya tiene viaje) y sus clientes lo ven al momento.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.viaje_id FROM {$of} o WHERE o.chofer_id=%d AND o.estado='activa'", (int) $o->chofer_id ) ) as $otra ) {
			$wpdb->update( $of, array( 'estado' => 'retirada', 'updated_at' => $now ), array( 'id' => (int) $otra->id ) );
			$ov = self::viaje( (int) $otra->viaje_id );
			if ( $ov ) { self::tocar( $ov, 'oferta' ); }
		}
		CVD_Triciclub_Db::evento( (int) $v->id, 'cliente', (int) $v->cliente_id, 'pv_elegido', array( 'chofer' => (int) $o->chofer_id, 'precio' => (int) $o->precio ) );
		$v = self::viaje( (int) $v->id );
		self::tocar( $v, 'asignado' );
		CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( (int) $o->chofer_id ), array( 't' => 'elegido', 'id' => (int) $v->id ) );
		self::aviso( (int) $o->chofer_id, 'pv_elegido', '✅ ¡Te eligieron! ' . number_format( (int) $o->precio, 0, ',', '.' ) . ' CUP', 'Ve a recoger en ' . self::corto( (string) $v->origen_texto ) . '.', array( 'pv', 'elegido' ) );
		foreach ( array_map( 'intval', (array) $perdieron ) as $uid ) { CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $uid ), array( 't' => 'perdio', 'id' => (int) $v->id ) ); }
		return '';
	}

	private static function ocupado( int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'viajes' ) . " WHERE chofer_id=%d AND estado IN ('asignado','llego','recogido') LIMIT 1", $user_id ) );
	}

	public static function cancelar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		if ( 'cancelado' === $v->estado ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) ); }
		if ( ! in_array( $v->estado, array( 'buscando', 'sin_chofer', 'asignado', 'llego' ), true ) ) { return self::fail( 'tarde', 'Ya no se puede cancelar: el viaje empezó.', 409 ); }
		$now = self::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='cancelado', cancelado_at=%s, cancelado_por='cliente', motivo=%s, updated_at=%s WHERE id=%d AND estado=%s", $now, self::txt( $request->get_param( 'motivo' ), 200 ), $now, (int) $v->id, $v->estado ) );
		if ( ! $n ) { return self::fail( 'tarde', 'El viaje cambió. Mira otra vez.', 409 ); }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='caducada', updated_at=%s WHERE viaje_id=%d AND estado='activa'", $now, (int) $v->id ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'cliente', (int) $c->id, 'cancelado', array( 'antes' => $v->estado ) );
		$v2 = self::viaje( (int) $v->id );
		self::tocar( $v2, 'cancelado' );
		if ( (int) $v->chofer_id && in_array( $v->estado, self::ACTIVOS, true ) ) {
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( (int) $v->chofer_id ), array( 't' => 'cancelado', 'id' => (int) $v->id ) );
			self::aviso( (int) $v->chofer_id, 'pv_cancelado', 'El cliente canceló', 'Ya no hace falta que vayas a ' . self::corto( (string) $v->origen_texto ) . '.', array( 'pv', 'cancelled' ) );
		}
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::viaje( (int) $v->id ) ) ) );
	}

	public static function valorar_cliente( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		return self::valorar( $v, 'cliente', $request );
	}

	// ================================================================== Chofer

	private static function ficha( WP_User $u ): ?object { return CVD_Triciclub::chofer_de( $u->ID ); }

	public static function chofer_hoy( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = self::ficha( $u );
		$estado = $ch ? (string) $ch->estado : 'nuevo';
		$base = array( 'ok' => true, 'now' => gmdate( 'c' ), 'tema' => CVD_Pv_Canal::tema_chofer( $u->ID ), 'estado' => $estado, 'nombre' => (string) $u->display_name );
		if ( ! $ch || 'aprobado' !== $estado ) { return self::out( $base + array( 'alta' => home_url( '/palla/triciclub' ) ) ); }
		$clase = self::clase_de( $ch );
		$grupo = CVD_Pv_Tarifa::grupo( $clase );
		$v = self::t( 'viajes' );
		$activo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v} WHERE chofer_id=%d AND clase<>'' AND estado IN ('asignado','llego','recogido') ORDER BY id DESC LIMIT 1", $u->ID ) );
		$inicio = ( new DateTimeImmutable( 'today', new DateTimeZone( 'America/Havana' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$t = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(precio+espera_cup),0) cup FROM {$v} WHERE chofer_id=%d AND estado='terminado' AND terminado_at>=%s", $u->ID, $inicio ) );
		$pedidos = array();
		if ( ! $activo && (int) $ch->disponible ) { $pedidos = self::pedidos_para( $ch ); }
		$pub = self::chofer_publico( $u->ID );
		return self::out( $base + array(
			'ficha'      => array(
				'clase'      => $clase,
				'grupo'      => $grupo,
				'tipo'       => CVD_Pv_Tarifa::CLASES[ $clase ]['nombre'],
				'img'        => CVD_Pv_Tarifa::CLASES[ $clase ]['img'],
				'plazas'     => (int) $ch->plazas,
				'color'      => (string) $ch->color,
				'chapa'      => (string) $ch->chapa,
				'disponible' => (bool) (int) $ch->disponible,
				'pasaje'     => in_array( 'viaje', explode( ',', (string) $ch->servicios ), true ),
				'envios'     => in_array( 'paquete', explode( ',', (string) $ch->servicios ), true ),
				'nota'       => $pub['nota'],
				'viajes'     => (int) $ch->viajes_total,
				'foto'       => $pub['foto'],
				'papeles'    => self::papeles( $ch, $clase ),
				'desde'      => self::iso( $ch->aprobado_at ?: $ch->created_at ),
				'transferencia' => (string) $ch->transferencia,
			),
			'hoy'        => array( 'viajes' => (int) $t->n, 'cup' => (int) $t->cup ),
			'cuenta'     => CVD_Pv_Cuenta::resumen( $u->ID, $grupo ),
			'pedidos'    => $pedidos,
			'activo'     => $activo ? self::vista_chofer( $activo ) : null,
			'oferta_seg' => self::OFERTA_SEG,
		) );
	}

	private static function papeles( object $ch, string $clase ): array {
		$f = json_decode( (string) $ch->fotos, true );
		$f = is_array( $f ) ? $f : array();
		$taxi = 'taxi' === CVD_Pv_Tarifa::grupo( $clase );
		$hoy = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) ) )->format( 'Y-m-d' );
		return array(
			array( 'id' => 'carne', 'nombre' => 'Carné de identidad', 'ok' => ! empty( $f['carne'] ) ),
			array( 'id' => 'selfie', 'nombre' => 'Selfie', 'ok' => ! empty( $f['selfie'] ) || ! empty( $f['perfil'] ) ),
			array( 'id' => 'vehiculo', 'nombre' => 'Foto del vehículo', 'ok' => ! empty( $f['triciclo'] ) ),
			array( 'id' => 'licencia', 'nombre' => 'Licencia de conducción', 'ok' => ! empty( $f['licencia'] ), 'obligatorio' => $taxi ),
			array( 'id' => 'lot', 'nombre' => 'LOT', 'ok' => ! empty( $f['lot'] ) || ( '' !== (string) $ch->lot_numero && null !== $ch->lot_vence && (string) $ch->lot_vence >= $hoy ), 'obligatorio' => $taxi, 'vence' => (string) $ch->lot_vence ),
		);
	}

	/** Los pedidos que este chofer puede ver ahora, con su oferta si ya hizo una. */
	private static function pedidos_para( object $ch ): array {
		global $wpdb;
		$v = self::t( 'viajes' );
		$desde = gmdate( 'Y-m-d H:i:s', time() - self::BUSCA_SEG );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$v} WHERE estado='buscando' AND clase<>'' AND busca_desde>%s ORDER BY id DESC LIMIT 60", $desde ) );
		$mias = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT viaje_id, precio, pct, estado, vence_at FROM ' . self::t( 'ofertas' ) . ' WHERE chofer_id=%d AND created_at>%s', (int) $ch->user_id, $desde ) ) as $o ) { $mias[ (int) $o->viaje_id ] = $o; }
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! self::puede_ver( $ch, $r ) ) { continue; }
			$mia = $mias[ (int) $r->id ] ?? null;
			if ( $mia && in_array( $mia->estado, array( 'retirada', 'perdio' ), true ) ) { continue; }
			$item = self::vista_pedido( $r, $ch );
			$item['mia'] = $mia ? array( 'precio' => (int) $mia->precio, 'pct' => (int) $mia->pct, 'estado' => (string) $mia->estado, 'vence' => self::iso( $mia->vence_at ) ) : null;
			$out[] = $item;
			if ( count( $out ) >= 8 ) { break; }
		}
		usort( $out, static fn( array $a, array $b ): int => $a['lejos_m'] <=> $b['lejos_m'] );
		return $out;
	}

	/** Un pedido como lo ve el chofer ANTES de cogerlo: sin teléfono ni apellido del cliente. */
	private static function vista_pedido( object $r, object $ch ): array {
		$cli = CVD_Triciclub::cliente( (int) $r->cliente_id );
		$lejos = CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $r->origen_lat, (float) $r->origen_lng );
		return array(
			'id'       => (int) $r->id,
			'clase'    => (string) $r->clase,
			'envio'    => 'mensajero' === $r->clase ? array( 'que' => (string) $r->envio_que, 'tam' => (string) $r->envio_tam ) : null,
			'oferta'   => (int) $r->precio_oferta,
			'contra'   => array_map( static fn( int $p ): array => array( 'pct' => $p, 'precio' => CVD_Pv_Tarifa::contra( (int) $r->precio_oferta, $p ) ), CVD_Pv_Tarifa::CONTRA ),
			'origen'   => array( 'texto' => (string) $r->origen_texto, 'lat' => (float) $r->origen_lat, 'lng' => (float) $r->origen_lng ),
			'destino'  => array( 'texto' => (string) $r->destino_texto, 'lat' => (float) $r->destino_lat, 'lng' => (float) $r->destino_lng ),
			'km'       => round( (int) $r->distancia_m / 1000, 1 ),
			'lejos_m'  => $lejos,
			'forma_pago' => (string) $r->forma_pago,
			'nota'     => (string) $r->nota,
			'cliente'  => $cli ? array( 'nombre' => trim( explode( ' ', (string) $cli->nombre )[0] ) ?: 'Cliente', 'nota' => null !== $cli->nota_media ? round( (float) $cli->nota_media, 1 ) : null, 'viajes' => (int) $cli->viajes_total ) : null,
			'hasta'    => gmdate( 'c', (int) strtotime( (string) ( $r->busca_desde ?: $r->created_at ) . ' UTC' ) + self::BUSCA_SEG ),
		);
	}

	/** El viaje como lo ve el chofer que lo lleva. */
	private static function vista_chofer( object $v ): array {
		$cli = CVD_Triciclub::cliente( (int) $v->cliente_id );
		$envio = 'mensajero' === $v->clase;
		$espera = 'llego' === $v->estado ? CVD_Pv_Tarifa::espera( $v->llego_at, self::now() ) : (int) $v->espera_cup;
		return array(
			'id'         => (int) $v->id,
			'estado'     => (string) $v->estado,
			'clase'      => (string) $v->clase,
			'origen'     => array( 'texto' => (string) $v->origen_texto, 'lat' => (float) $v->origen_lat, 'lng' => (float) $v->origen_lng ),
			'destino'    => array( 'texto' => (string) $v->destino_texto, 'lat' => (float) $v->destino_lat, 'lng' => (float) $v->destino_lng ),
			'km'         => round( (int) $v->distancia_m / 1000, 1 ),
			'minutos'    => (int) $v->minutos,
			'precio'     => (int) $v->precio,
			'espera'     => $espera,
			'total'      => (int) $v->precio + $espera,
			'forma_pago' => (string) $v->forma_pago,
			'nota'       => (string) $v->nota,
			'cliente'    => $cli ? array( 'nombre' => (string) $cli->nombre, 'telefono' => (string) $cli->telefono, 'nota' => null !== $cli->nota_media ? round( (float) $cli->nota_media, 1 ) : null, 'viajes' => (int) $cli->viajes_total ) : null,
			'envio'      => $envio ? array( 'que' => (string) $v->envio_que, 'tam' => (string) $v->envio_tam, 'recibe' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'cobrar' => (int) $v->cobrar_cup, 'foto' => '' !== (string) $v->entrega_foto ) : null,
			'llego'      => self::iso( $v->llego_at ),
			'cobrado'    => null !== $v->cobrado_at,
			'linea'      => CVD_Triciclub_Precio::linea_guardada( (float) $v->origen_lat, (float) $v->origen_lng, (float) $v->destino_lat, (float) $v->destino_lng ),
			'valorado'   => self::valorado( (int) $v->id, 'chofer' ),
		);
	}

	/** POST chofer/disponible {on, lat, lng, pasaje?, envios?} */
	public static function chofer_disponible( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = self::ficha( $u );
		if ( ! $ch || 'aprobado' !== $ch->estado ) { return self::fail( 'no_aprobado', CVD_Pv::texto_cerrado( 'no_chofer' ), 403 ); }
		$on = rest_sanitize_boolean( $request->get_param( 'on' ) );
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( $on && ( null === $lat || null === $lng ) ) { return self::fail( 'gps', 'Activa la ubicación del teléfono para recibir pedidos.' ); }
		$now = self::now();
		$set = array( 'disponible' => $on ? 1 : 0, 'updated_at' => $now );
		if ( null !== $lat && null !== $lng ) { $set += array( 'lat' => $lat, 'lng' => $lng, 'ubicado_at' => $now ); }
		$serv = explode( ',', (string) $ch->servicios );
		foreach ( array( 'pasaje' => 'viaje', 'envios' => 'paquete' ) as $k => $s ) {
			$val = $request->get_param( $k );
			if ( null === $val ) { continue; }
			$serv = rest_sanitize_boolean( $val ) ? array_unique( array_merge( $serv, array( $s ) ) ) : array_diff( $serv, array( $s ) );
		}
		$set['servicios'] = implode( ',', array_values( array_filter( $serv ) ) );
		$wpdb->update( self::t( 'choferes' ), $set, array( 'user_id' => $u->ID ) );
		return self::out( array( 'ok' => true, 'disponible' => $on ) );
	}

	/** POST chofer/posicion {lat, lng, precision} — cada 30 s en línea; cada 10 s con viaje (y se manda al cliente). */
	public static function chofer_posicion( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( null === $lat || null === $lng ) { return self::fail( 'gps', 'Ubicación no válida.' ); }
		$now = self::now();
		$wpdb->update( self::t( 'choferes' ), array( 'lat' => $lat, 'lng' => $lng, 'ubicado_at' => $now ), array( 'user_id' => $u->ID ) );
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE chofer_id=%d AND clase<>'' AND estado IN ('asignado','llego','recogido') ORDER BY id DESC LIMIT 1", $u->ID ) );
		if ( $v ) {
			$prec = $request->get_param( 'precision' );
			$wpdb->insert( self::t( 'ubicaciones' ), array( 'viaje_id' => (int) $v->id, 'chofer_id' => $u->ID, 'lat' => $lat, 'lng' => $lng, 'precision_m' => is_numeric( $prec ) ? min( 65535, max( 0, (int) $prec ) ) : null, 'created_at' => $now ) );
			if ( '' !== (string) $v->canal ) { CVD_Pv_Canal::senal( (string) $v->canal, array( 't' => 'pos', 'lat' => round( $lat, 6 ), 'lng' => round( $lng, 6 ) ) ); }
		}
		return self::out( array( 'ok' => true, 'viaje' => $v ? (int) $v->id : 0 ) );
	}

	/** POST chofer/pedidos/{id}/ofertar {pct: 0|10|20|30} */
	public static function ofertar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = self::ficha( $u );
		if ( ! $ch || 'aprobado' !== $ch->estado ) { return self::fail( 'no_aprobado', CVD_Pv::texto_cerrado( 'no_chofer' ), 403 ); }
		if ( ! (int) $ch->disponible ) { return self::fail( 'desconectado', 'Conéctate para ofertar.', 409 ); }
		if ( self::ocupado( $u->ID ) ) { return self::fail( 'ocupado', 'Termina tu viaje antes de coger otro.', 409 ); }
		$v = self::viaje( absint( $request['id'] ) );
		if ( ! $v || 'buscando' !== $v->estado ) { return self::fail( 'tarde', 'Lo cogió otro chofer o el cliente canceló.', 409 ); }
		if ( ! self::puede_ver( $ch, $v ) ) { return self::fail( 'lejos', 'Este pedido ya no es para ti.', 409 ); }
		$pct = (int) $request->get_param( 'pct' );
		if ( ! in_array( $pct, CVD_Pv_Tarifa::CONTRA, true ) ) { $pct = 0; }
		$precio = CVD_Pv_Tarifa::contra( (int) $v->precio_oferta, $pct );
		$lejos = CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $v->origen_lat, (float) $v->origen_lng );
		$now = self::now();
		$of = self::t( 'ofertas' );
		$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$of} WHERE viaje_id=%d AND chofer_id=%d", (int) $v->id, $u->ID ) );
		if ( $ya && in_array( $ya->estado, array( 'perdio', 'elegida' ), true ) ) { return self::fail( 'tarde', 'Este pedido ya se decidió.', 409 ); }
		$datos = array( 'precio' => $precio, 'pct' => $pct, 'llega_min' => CVD_Pv_Tarifa::minutos( self::clase_de( $ch ), (int) round( $lejos * 1.3 ) ), 'lejos_m' => $lejos, 'estado' => 'activa', 'vence_at' => gmdate( 'Y-m-d H:i:s', time() + self::OFERTA_SEG ), 'updated_at' => $now );
		if ( $ya ) { $wpdb->update( $of, $datos, array( 'id' => (int) $ya->id ) ); $oid = (int) $ya->id; }
		else { $wpdb->insert( $of, $datos + array( 'viaje_id' => (int) $v->id, 'chofer_id' => $u->ID, 'created_at' => $now ) ); $oid = (int) $wpdb->insert_id; }
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_oferta', array( 'precio' => $precio, 'pct' => $pct ) );
		// «Coger la 1.ª oferta»: el primer «Acepto» (a su precio) se queda con el viaje al momento.
		if ( (int) $v->primera && 0 === $pct && '' === self::asignar( $v, $oid ) ) {
			return self::out( array( 'ok' => true, 'elegido' => true, 'activo' => self::vista_chofer( self::viaje( (int) $v->id ) ) ) );
		}
		self::tocar( $v, 'oferta' );
		return self::out( array( 'ok' => true, 'elegido' => false, 'oferta' => array( 'id' => $oid, 'precio' => $precio, 'pct' => $pct, 'vence' => self::iso( $datos['vence_at'] ) ) ) );
	}

	public static function retirar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::viaje( absint( $request['id'] ) );
		if ( ! $v ) { return self::fail( 'no_existe', 'No encontramos ese pedido.', 404 ); }
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='retirada', updated_at=%s WHERE viaje_id=%d AND chofer_id=%d AND estado='activa'", self::now(), (int) $v->id, $u->ID ) );
		if ( $n ) { self::tocar( $v, 'oferta' ); }
		return self::out( array( 'ok' => true ) );
	}

	private static function viaje_mio( WP_REST_Request $request, WP_User $u ): ?object {
		$v = self::viaje( absint( $request['id'] ) );
		return $v && (int) $v->chofer_id === $u->ID ? $v : null;
	}

	/** POST chofer/viajes/{id}/estado {accion: llegue|recogi|termine|cobrado|suelto|nopago, codigo, motivo} */
	public static function estado( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::viaje_mio( $request, $u );
		$accion = sanitize_key( (string) $request->get_param( 'accion' ) );
		if ( ! $v ) {
			// Si lo soltó y otro lo cogió, o el cliente canceló, el viaje ya no es suyo: se le dice claro.
			return self::fail( 'no_existe', 'Ese viaje ya no es tuyo.', 404 );
		}
		$now = self::now();
		$t = self::t( 'viajes' );
		$envio = 'mensajero' === $v->clase;
		if ( 'cobrado' === $accion || 'nopago' === $accion ) {
			if ( 'terminado' !== $v->estado ) { return self::fail( 'tarde', 'Cobra al terminar el viaje.', 409 ); }
			if ( 'cobrado' === $accion && null === $v->cobrado_at ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET cobrado_at=%s, updated_at=%s WHERE id=%d AND cobrado_at IS NULL", $now, $now, (int) $v->id ) );
				CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'cobrado', array( 'total' => (int) $v->precio + (int) $v->espera_cup, 'forma' => $v->forma_pago ) );
			}
			if ( 'nopago' === $accion ) {
				CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_no_pago' );
				$correo = (string) get_option( 'cvd_pv_correo', get_option( 'admin_email' ) );
				if ( is_email( $correo ) ) { wp_mail( $correo, 'Pa\'llá: un cliente no pagó (viaje ' . (int) $v->id . ')', 'El chofer ' . $u->display_name . ' dice que el cliente no le pagó ' . ( (int) $v->precio + (int) $v->espera_cup ) . ' CUP. Míralo en /triciclub/admin.' ); }
			}
			return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( self::viaje( (int) $v->id ) ) ) );
		}
		$pasos = array(
			'llegue'  => array( array( 'asignado' ), 'llego', 'llego_at' ),
			'recogi'  => array( array( 'asignado', 'llego' ), 'recogido', 'recogido_at' ),
			'termine' => array( array( 'recogido' ), 'terminado', 'terminado_at' ),
		);
		if ( 'suelto' === $accion ) { return self::soltar( $v, $u, self::txt( $request->get_param( 'motivo' ), 200 ) ); }
		if ( ! isset( $pasos[ $accion ] ) ) { return self::fail( 'accion', 'Botón desconocido.' ); }
		list( $desde, $hacia, $col ) = $pasos[ $accion ];
		if ( $v->estado === $hacia ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( $v ) ) ); } // repetido
		if ( ! in_array( $v->estado, $desde, true ) ) { return self::fail( 'tarde', 'cancelado' === $v->estado ? 'El cliente canceló el viaje.' : 'Ese paso ya no toca.', 409 ); }
		$codigo = preg_replace( '/\D+/', '', (string) $request->get_param( 'codigo' ) );
		if ( 'recogi' === $accion && ! $envio && ! hash_equals( (string) $v->codigo, $codigo ) ) {
			return self::fail( 'codigo', 'Ese no es el código. Pídeselo al cliente: lo tiene en su app.' );
		}
		if ( 'termine' === $accion && $envio ) {
			if ( ! hash_equals( (string) $v->entrega_codigo, $codigo ) ) { return self::fail( 'codigo', 'Ese no es el código de entrega. Pídeselo a quien recibe.' ); }
			if ( '' === (string) $v->entrega_foto ) { return self::fail( 'foto', 'Haz la foto de la entrega antes de terminar.' ); }
		}
		$set = array( 'estado' => $hacia, $col => $now, 'updated_at' => $now );
		if ( 'recogi' === $accion ) { $set['espera_cup'] = CVD_Pv_Tarifa::espera( $v->llego_at, $now ); }
		if ( 'termine' === $accion ) {
			list( $pct, $cup ) = CVD_Pv_Cuenta::apuntar( $v, (int) $v->precio + (int) $v->espera_cup );
			$set += array( 'comision_pct' => $pct, 'comision_cup' => $cup );
		}
		$n = $wpdb->update( $t, $set, array( 'id' => (int) $v->id, 'estado' => $v->estado ) );
		if ( ! $n ) { return self::fail( 'tarde', 'El viaje cambió. Mira otra vez.', 409 ); }
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, $accion );
		if ( 'termine' === $accion ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'choferes' ) . ' SET viajes_total=viajes_total+1 WHERE user_id=%d', $u->ID ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'clientes' ) . ' SET viajes_total=viajes_total+1 WHERE id=%d', (int) $v->cliente_id ) );
		}
		$v2 = self::viaje( (int) $v->id );
		self::tocar( $v2, $hacia );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( $v2 ) ) );
	}

	/** El chofer ya no puede ir: el pedido vuelve a buscar chofer (y él no lo vuelve a ver). */
	private static function soltar( object $v, WP_User $u, string $motivo ): WP_REST_Response {
		global $wpdb;
		if ( ! in_array( $v->estado, array( 'asignado', 'llego' ), true ) ) { return self::fail( 'tarde', 'Ya recogiste: si hay un problema, llama a Pa\'llá.', 409 ); }
		$now = self::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='buscando', chofer_id=0, oferta_id=0, precio=precio_oferta, llego_at=NULL, respondido_at=NULL, busca_desde=%s, updated_at=%s WHERE id=%d AND chofer_id=%d AND estado=%s", $now, $now, (int) $v->id, $u->ID, $v->estado ) );
		if ( ! $n ) { return self::fail( 'tarde', 'El viaje cambió. Mira otra vez.', 409 ); }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='retirada', updated_at=%s WHERE viaje_id=%d AND chofer_id=%d", $now, (int) $v->id, $u->ID ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_suelto', array( 'motivo' => $motivo ) );
		$v2 = self::viaje( (int) $v->id );
		self::tocar( $v2, 'suelto' );
		self::avisar_choferes( $v2, false );
		return self::out( array( 'ok' => true, 'suelto' => true ) );
	}

	// ------------------------------------------------------------------ Foto de entrega (envíos)

	private static function carpeta(): string {
		$up = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'cvd-tc-privado/entregas';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( dirname( $dir ) . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@file_put_contents( dirname( $dir ) . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $dir;
	}

	private static function firma_foto( string $uuid ): string { return substr( hash_hmac( 'sha256', 'pv-foto|' . $uuid, wp_salt( 'auth' ) ), 0, 24 ); }

	private static function foto_url( object $v ): string {
		return add_query_arg( array( 'v' => (string) $v->uuid, 'f' => self::firma_foto( (string) $v->uuid ) ), rest_url( self::NS . '/pv/foto' ) );
	}

	/** POST chofer/viajes/{id}/foto (multipart «foto») — la foto de la entrega, guardada de nuevo (sin EXIF ni GPS). */
	public static function subir_foto( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::viaje_mio( $request, $u );
		if ( ! $v || 'mensajero' !== $v->clase || 'recogido' !== $v->estado ) { return self::fail( 'tarde', 'La foto se hace al entregar.', 409 ); }
		$files = $request->get_file_params();
		$f = $files['foto'] ?? null;
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? 1 ) || (int) $f['size'] > 6 * MB_IN_BYTES ) { return self::fail( 'foto', 'No llegó la foto. Prueba otra vez.' ); }
		$info = @getimagesize( (string) $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) { return self::fail( 'foto', 'Eso no es una foto.' ); }
		$ed = wp_get_image_editor( (string) $f['tmp_name'] );
		if ( is_wp_error( $ed ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.', 500 ); }
		$ed->resize( 1280, 1280, false );
		$ed->set_quality( 80 );
		$nombre = 'e' . (int) $v->id . '-' . bin2hex( random_bytes( 4 ) ) . '.jpg';
		$res = $ed->save( self::carpeta() . '/' . $nombre, 'image/jpeg' );
		if ( is_wp_error( $res ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.', 500 ); }
		$wpdb->update( self::t( 'viajes' ), array( 'entrega_foto' => $nombre, 'updated_at' => self::now() ), array( 'id' => (int) $v->id ) );
		return self::out( array( 'ok' => true ) );
	}

	/** GET foto?v=uuid&f=firma — la foto de la entrega (solo con el enlace firmado que ve el cliente). */
	public static function ver_foto( WP_REST_Request $request ) {
		global $wpdb;
		$uuid = (string) $request->get_param( 'v' );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || ! hash_equals( self::firma_foto( $uuid ), (string) $request->get_param( 'f' ) ) ) { return new WP_Error( 'pv_foto', 'No.', array( 'status' => 404 ) ); }
		$nombre = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT entrega_foto FROM ' . self::t( 'viajes' ) . ' WHERE uuid=%s', $uuid ) );
		$path = self::carpeta() . '/' . basename( $nombre );
		if ( '' === $nombre || ! is_file( $path ) ) { return new WP_Error( 'pv_foto', 'No.', array( 'status' => 404 ) ); }
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Cache-Control: private, max-age=86400' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// ------------------------------------------------------------------ Dinero del chofer

	public static function chofer_cuenta( WP_REST_Request $request ): WP_REST_Response {
		$u = self::user( $request );
		$ch = self::ficha( $u );
		return self::out( array( 'ok' => true, 'cuenta' => CVD_Pv_Cuenta::resumen( $u->ID, $ch ? CVD_Pv_Tarifa::grupo( self::clase_de( $ch ) ) : '' ) ) );
	}

	/** POST chofer/pago {cup, ref} — «ya pagué por Transfermóvil» (cuenta al momento; Ernesto lo revisa). */
	public static function chofer_pago( WP_REST_Request $request ): WP_REST_Response {
		$u = self::user( $request );
		$cup = absint( $request->get_param( 'cup' ) );
		$ref = self::txt( $request->get_param( 'ref' ), 60 );
		if ( $cup < 1 || $cup > 500000 ) { return self::fail( 'cup', 'Escribe cuánto pagaste.' ); }
		if ( mb_strlen( $ref ) < 4 ) { return self::fail( 'ref', 'Escribe el número de la transferencia (sale en el SMS del banco).' ); }
		CVD_Pv_Cuenta::pago( $u->ID, $cup, $ref );
		$ch = self::ficha( $u );
		return self::out( array( 'ok' => true, 'cuenta' => CVD_Pv_Cuenta::resumen( $u->ID, $ch ? CVD_Pv_Tarifa::grupo( self::clase_de( $ch ) ) : '' ) ) );
	}

	/** GET chofer/semana — lo ganado cada día de esta semana (lunes a domingo, hora de Cuba) y los últimos viajes. */
	public static function chofer_semana( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$tz = new DateTimeZone( 'America/Havana' );
		$lunes = new DateTimeImmutable( 'monday this week', $tz );
		$desde = $lunes->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, clase, destino_texto, precio, espera_cup, forma_pago, terminado_at, cliente_id FROM ' . self::t( 'viajes' ) . " WHERE chofer_id=%d AND clase<>'' AND estado='terminado' AND terminado_at>=%s ORDER BY id DESC", $u->ID, $desde ) );
		$dias = array_fill( 0, 7, 0 );
		$ult = array();
		foreach ( $rows as $r ) {
			$d = ( new DateTimeImmutable( $r->terminado_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$dias[ (int) $d->format( 'N' ) - 1 ] += (int) $r->precio + (int) $r->espera_cup;
			if ( count( $ult ) < 10 ) {
				$cli = CVD_Triciclub::cliente( (int) $r->cliente_id );
				$ult[] = array( 'clase' => (string) $r->clase, 'destino' => (string) $r->destino_texto, 'total' => (int) $r->precio + (int) $r->espera_cup, 'pago' => (string) $r->forma_pago, 'cliente' => $cli ? trim( explode( ' ', (string) $cli->nombre )[0] ) : '', 'hora' => gmdate( 'c', (int) strtotime( $r->terminado_at . ' UTC' ) ) );
			}
		}
		return self::out( array( 'ok' => true, 'dias' => $dias, 'hoy' => (int) ( new DateTimeImmutable( 'now', $tz ) )->format( 'N' ) - 1, 'total' => array_sum( $dias ), 'viajes' => count( $rows ), 'ultimos' => $ult ) );
	}

	public static function valorar_chofer( WP_REST_Request $request ): WP_REST_Response {
		$u = self::user( $request );
		$v = self::viaje_mio( $request, $u );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese viaje no es tuyo.', 404 ); }
		return self::valorar( $v, 'chofer', $request );
	}

	// ------------------------------------------------------------------ Valoraciones

	private static function valorado( int $viaje_id, string $de ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'valoraciones' ) . ' WHERE viaje_id=%d AND de=%s', $viaje_id, $de ) );
	}

	/** Una valoración por lado y viaje. Recalcula la nota media del otro. */
	private static function valorar( object $v, string $de, WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		if ( 'terminado' !== $v->estado ) { return self::fail( 'tarde', 'Se valora al terminar.', 409 ); }
		$estrellas = max( 1, min( 5, (int) $request->get_param( 'estrellas' ) ) );
		$etq = $request->get_param( 'etiquetas' );
		$etiquetas = is_array( $etq ) ? implode( ',', array_slice( array_map( 'sanitize_key', $etq ), 0, 8 ) ) : '';
		$propina = absint( $request->get_param( 'propina' ) );
		$t = self::t( 'valoraciones' );
		if ( ! self::valorado( (int) $v->id, $de ) ) {
			$wpdb->insert( $t, array( 'viaje_id' => (int) $v->id, 'de' => $de, 'estrellas' => $estrellas, 'etiquetas' => mb_substr( $etiquetas . ( $propina ? ',propina_' . min( 9999, $propina ) : '' ), 0, 200 ), 'texto' => self::txt( $request->get_param( 'texto' ), 300 ), 'created_at' => self::now() ) );
			$vt = self::t( 'viajes' );
			if ( 'cliente' === $de ) {
				$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.estrellas) FROM {$t} r JOIN {$vt} x ON x.id=r.viaje_id WHERE r.de='cliente' AND x.chofer_id=%d", (int) $v->chofer_id ) );
				$wpdb->update( self::t( 'choferes' ), array( 'nota_media' => null !== $avg ? round( (float) $avg, 2 ) : null ), array( 'user_id' => (int) $v->chofer_id ) );
			} else {
				$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.estrellas) FROM {$t} r JOIN {$vt} x ON x.id=r.viaje_id WHERE r.de='chofer' AND x.cliente_id=%d", (int) $v->cliente_id ) );
				$wpdb->update( self::t( 'clientes' ), array( 'nota_media' => null !== $avg ? round( (float) $avg, 2 ) : null ), array( 'id' => (int) $v->cliente_id ) );
			}
		}
		return self::out( array( 'ok' => true ) );
	}
}
