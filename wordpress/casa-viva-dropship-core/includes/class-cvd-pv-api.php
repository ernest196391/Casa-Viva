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
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/primera', 'POST', 'primera', $c ); // v3
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/cancelar', 'POST', 'cancelar', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/valorar', 'POST', 'valorar_cliente', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/llegue', 'POST', 'llegue_cliente', $c ); // 10-nov: «Ya llegué» si el chofer no toca Terminé
		$r( 'foto', 'GET', 'ver_foto', $open ); // firmada
		// Chofer
		$r( 'chofer/hoy', 'GET', 'chofer_hoy', $d );
		$r( 'chofer/app', 'GET', 'chofer_app', $d ); // 10-nov: la app Android «Pa'llá Chofer» pide aquí su tema de avisos
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
		$crudo = (string) ( $c->lugares ?? '' ); // 10-nov (auditoría): en la tabla de clientes; antes, una opción por cliente
		$lugares = json_decode( '' !== $crudo ? $crudo : (string) get_option( 'cvd_pv_lugares_' . (int) $c->id, '' ), true );
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
			$wpdb->update( self::t( 'clientes' ), array( 'lugares' => wp_json_encode( $l ), 'updated_at' => self::now() ), array( 'id' => (int) $c->id ) );
			delete_option( 'cvd_pv_lugares_' . (int) $c->id );
		}
		return self::out( array( 'ok' => true, 'yo' => self::yo_de( CVD_Triciclub::cliente( (int) $c->id ) ) ) );
	}

	// Direcciones: las mismas de Triciclub (15.110 lugares de La Habana + calles).
	/**
	 * GET donde?lat&lng — 10-nov (Ernesto: «que el alfiler se pueda poner solamente en las calles, no en los edificios;
	 * si no, el chofer se confunde»): el punto se pega a la calle más cercana por donde pasa un carro (como Uber/Yandex)
	 * y la dirección se busca ahí. Devuelve también `lat`, `lng` (ya en la calle) y `pegado`.
	 */
	public static function donde( WP_REST_Request $request ): WP_REST_Response {
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( null === $lat || null === $lng ) { return CVD_Triciclub_Api::donde( $request ); }
		$c = self::pegar_calle( $lat, $lng );
		$d = CVD_Triciclub_Geo::donde( $c[0], $c[1] );
		return self::out( array( 'ok' => true, 'texto' => $d ? $d['texto'] : ( '' !== $c[2] ? $c[2] : 'Punto en el mapa' ), 'barrio' => $d ? $d['barrio'] : '', 'conocido' => (bool) $d,
			'lat' => round( $c[0], 6 ), 'lng' => round( $c[1], 6 ), 'pegado' => $c[3] ) );
	}

	/**
	 * [lat, lng, nombre de la calle, ¿se movió?]: el punto de la calle más cercana. Truco sin servicio nuevo: una ruta de
	 * carro del punto a sí mismo; OSRM devuelve en `waypoints` el punto ya sobre la calle y su nombre. 1 día en caché
	 * (~1 m). Si el motor no contesta o la calle está a más de 150 m, se queda donde lo puso.
	 */
	public static function pegar_calle( float $lat, float $lng ): array {
		$key = 'cvd_pv_calle_' . md5( number_format( $lat, 5, '.', '' ) . ',' . number_format( $lng, 5, '.', '' ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }
		$out = array( $lat, $lng, '', false );
		if ( defined( 'CVD_PV_SIN_RED' ) && ! has_filter( 'pre_http_request' ) ) { return $out; }
		$api = rtrim( (string) get_option( 'cvd_tc_ruta_api', 'https://179-236-242-212.sslip.io' ), '/' );
		$res = wp_remote_get( $api . '/route/v1/driving/' . $lng . ',' . $lat . ';' . $lng . ',' . $lat . '?overview=false', array( 'timeout' => 2, 'redirection' => 0 ) );
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$w = is_array( $data ) && 'Ok' === ( $data['code'] ?? '' ) ? ( $data['waypoints'][0] ?? null ) : null;
		if ( is_array( $w ) && isset( $w['location'][0], $w['location'][1] ) && (float) ( $w['distance'] ?? 999 ) <= 150 ) {
			$out = array( (float) $w['location'][1], (float) $w['location'][0], (string) ( $w['name'] ?? '' ), (float) ( $w['distance'] ?? 0 ) > 3 );
			set_transient( $key, $out, DAY_IN_SECONDS );
		}
		return $out;
	}
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
	private static function en_linea( ?float $lat = null, ?float $lng = null ): array {
		global $wpdb;
		$t = self::t( 'choferes' );
		$v = self::t( 'viajes' );
		// 10-nov (auditoría): con un punto, solo los de un cuadro de ~9 km alrededor (el radio mayor es 8 km) y los más
		// cercanos primero; así con muchos choferes no se pierden los de cerca por el tope.
		$caja = '';
		$orden = '';
		if ( null !== $lat && null !== $lng ) {
			$caja = $wpdb->prepare( ' AND c.lat BETWEEN %f AND %f AND c.lng BETWEEN %f AND %f', $lat - 0.08, $lat + 0.08, $lng - 0.085, $lng + 0.085 );
			$orden = $wpdb->prepare( ' ORDER BY (c.lat-%f)*(c.lat-%f)+(c.lng-%f)*(c.lng-%f)', $lat, $lat, $lng, $lng );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.* FROM {$t} c WHERE c.estado='aprobado' AND c.disponible=1 AND c.ubicado_at>%s AND c.lat IS NOT NULL{$caja}
			AND NOT EXISTS (SELECT 1 FROM {$v} x WHERE x.chofer_id=c.user_id AND x.estado IN ('asignado','llego','recogido'))
			{$orden} LIMIT 300", gmdate( 'Y-m-d H:i:s', time() - self::VIVO ) ) );
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
		foreach ( self::en_linea( $lat, $lng ) as $ch ) {
			$cl = self::clase_de( $ch );
			if ( '' !== $grupo && CVD_Pv_Tarifa::grupo( $cl ) !== $grupo && ! ( 'mens' === $grupo && self::sirve( $ch, 'mensajero' ) ) ) { continue; }
			if ( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, $lat, $lng ) > 5000 ) { continue; }
			// 10-nov (auditoría): ±200 m (antes ±45 m): se ve que hay choferes cerca, no dónde está cada uno.
			$out[] = array( 'lat' => round( (float) $ch->lat + wp_rand( -18, 18 ) / 10000, 5 ), 'lng' => round( (float) $ch->lng + wp_rand( -18, 18 ) / 10000, 5 ), 'img' => CVD_Pv_Tarifa::CLASES[ $cl ]['img'] );
			if ( count( $out ) >= 20 ) { break; }
		}
		return self::out( array( 'ok' => true, 'puntos' => $out ) );
	}

	/**
	 * GET cotizar?grupo=viajar|taxi|tric|moto|mens&olat&olng&dlat&dlng[&tam][&servicio=paquete|comida|carga][&ayudante=1]
	 * Por cada clase: precio justo, límites de la oferta, minutos, cuántos libres cerca y cuánto tarda el más cercano;
	 * todo con la cotización firmada (10 min).
	 * - v3 (D142) `grupo=viajar`: las 7 clases de Viajar de una vez, cada una con SU ruta (taxi por calles de carro, el resto
	 *   por el camino corto de bici), de la más barata a la más cara, + `lineas` {driving, cycling} y `ultima` (la clase
	 *   que usó el cliente la última vez; la 1.ª vez, el triciclo eléctrico).
	 * - v3 `grupo=mens&servicio=carga`: triciclo de carga (precio tric_carga) + ayudante fijo si `ayudante=1`;
	 *   `servicio=comida`: precio del mensajero (cabe en la mochila).
	 * Los grupos de antes (taxi, tric, moto, mens sin servicio) contestan igual que siempre (móviles con la app vieja).
	 */
	public static function cotizar( WP_REST_Request $request ): WP_REST_Response {
		$grupo = sanitize_key( (string) $request->get_param( 'grupo' ) );
		if ( ! in_array( $grupo, array( 'viajar', 'taxi', 'tric', 'moto', 'mens' ), true ) ) { $grupo = 'tric'; }
		$olat = self::coord( $request->get_param( 'olat' ), true );
		$olng = self::coord( $request->get_param( 'olng' ), false );
		$dlat = self::coord( $request->get_param( 'dlat' ), true );
		$dlng = self::coord( $request->get_param( 'dlng' ), false );
		if ( null === $olat || null === $olng || null === $dlat || null === $dlng ) { return self::fail( 'puntos', 'Falta dónde recoger o a dónde ir.' ); }
		$serv = sanitize_key( (string) $request->get_param( 'servicio' ) );
		$serv = 'mens' === $grupo ? ( in_array( $serv, array( 'paquete', 'comida', 'carga' ), true ) ? $serv : '' ) : ( 'viajar' === $grupo ? 'viaje' : '' );
		$tam = sanitize_key( (string) $request->get_param( 'tam' ) );
		$tam = isset( CVD_Pv_Tarifa::TAMANOS[ $tam ] ) ? $tam : 'mano';
		if ( 'carga' === $serv ) { $tam = 'grande'; }
		if ( 'comida' === $serv ) { $tam = 'mochila'; }
		$extra = 'carga' === $serv && rest_sanitize_boolean( $request->get_param( 'ayudante' ) ) ? CVD_Pv_Tarifa::ayudante() : 0;
		// Ruta: de carro para el taxi, de bici para el resto. En «viajar» hacen falta las dos (cada clase con la suya).
		$tray = array();
		$perfiles = 'viajar' === $grupo ? array( 'driving', 'cycling' ) : array( 'taxi' === $grupo ? 'driving' : 'cycling' );
		foreach ( $perfiles as $pf ) { $tray[ $pf ] = self::trayecto( $pf, $olat, $olng, $dlat, $dlng ); }
		$recta = CVD_Triciclub_Precio::recta_m( $olat, $olng, $dlat, $dlng );
		$noche = CVD_Pv_Tarifa::noche();
		$libres = self::en_linea( $olat, $olng );
		if ( 'viajar' === $grupo ) { $clases = CVD_Pv_Tarifa::VIAJAR; }
		elseif ( 'mens' === $grupo ) { $clases = array( 'mensajero' ); }
		else { $clases = array_keys( array_filter( CVD_Pv_Tarifa::CLASES, static fn( array $c ): bool => $c['grupo'] === $grupo ) ); }
		$lista = array();
		foreach ( $clases as $clase ) {
			$precio_de = 'mensajero' === $clase && 'grande' === $tam ? 'tric_carga' : $clase;
			$pf = 'viajar' === $grupo ? self::perfil( $clase ) : $perfiles[0];
			$metros = CVD_Pv_Tarifa::metros_cobro( $precio_de, (int) $tray[ $pf ]['metros'], $recta );
			$justo = CVD_Pv_Tarifa::justo( $precio_de, $metros, $noche ) + $extra;
			$lim = CVD_Pv_Tarifa::limites_extra( $precio_de, $justo, $noche, $extra );
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
			$fq = array( 'clase' => $clase, 'olat' => $olat, 'olng' => $olng, 'dlat' => $dlat, 'dlng' => $dlng, 'metros' => $metros, 'minutos' => $minutos, 'justo' => $justo, 'noche' => $noche, 'tam' => 'mensajero' === $clase ? $tam : '' );
			if ( '' !== $serv ) { $fq += array( 'serv' => $serv, 'extra' => $extra ); }
			$lista[] = array(
				'clase'   => $clase,
				'justo'   => $justo,
				'min'     => $lim[0],
				'max'     => $lim[1],
				'extra'   => $extra,
				'perfil'  => $pf,
				'km'      => round( $metros / 1000, 1 ),
				'minutos' => $minutos,
				'libres'  => $n,
				'llega'   => null === $cerca ? null : CVD_Pv_Tarifa::minutos( $clase, (int) round( $cerca * 1.3 ) ),
				'firma'   => CVD_Pv_Tarifa::firmar( $fq ),
			);
		}
		$out = array( 'ok' => true, 'grupo' => $grupo, 'noche' => $noche, 'como' => $tray[ $perfiles[0] ]['como'], 'linea' => $tray[ $perfiles[ count( $perfiles ) - 1 ] ]['linea'] ?? null, 'clases' => $lista, 'vence' => time() + CVD_Pv_Tarifa::FIRMA_TTL );
		if ( '' !== $serv ) { $out['servicio'] = $serv; }
		if ( 'viajar' === $grupo ) {
			usort( $out['clases'], static fn( array $a, array $b ): int => array( $a['justo'], array_search( $a['clase'], CVD_Pv_Tarifa::VIAJAR, true ) ) <=> array( $b['justo'], array_search( $b['clase'], CVD_Pv_Tarifa::VIAJAR, true ) ) );
			$out['lineas'] = array( 'driving' => $tray['driving']['linea'] ?? null, 'cycling' => $tray['cycling']['linea'] ?? null );
			$out['ultima'] = self::ultima_clase( (int) self::cliente( $request )->id );
		}
		return self::out( $out );
	}

	/** v3: la clase de Viajar que usó el cliente la última vez (un viaje que no se canceló); la 1.ª vez, el triciclo eléctrico. */
	private static function ultima_clase( int $cliente_id ): string {
		global $wpdb;
		$in = "'" . implode( "','", CVD_Pv_Tarifa::VIAJAR ) . "'";
		$c = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT clase FROM ' . self::t( 'viajes' ) . " WHERE cliente_id=%d AND clase IN ({$in}) AND estado<>'cancelado' ORDER BY id DESC LIMIT 1", $cliente_id ) );
		return '' !== $c ? $c : 'tric_electrico';
	}

	/** Lo que el cliente puede ofrecer en este pedido (con el ayudante dentro si lo lleva). */
	private static function lim_de( object $v ): array {
		$precio_de = 'mensajero' === $v->clase && 'grande' === $v->envio_tam ? 'tric_carga' : (string) $v->clase;
		return CVD_Pv_Tarifa::limites_extra( $precio_de, (int) $v->precio_justo, (bool) $v->noche, (int) ( $v->extra_cup ?? 0 ) );
	}

	// ================================================================== Pedir

	/**
	 * POST viajes {cid, firma, precio, primera, forma_pago, origen_texto, destino_texto, nota,
	 *              envio?: {que, recibe_nombre, recibe_telefono, cobrar_cup,
	 *                       v3: recoge (texto del sitio), adelanto_cup (comida, tope 5.000), peso (carga: poco|medio|mucho)}}
	 * El servicio (viaje · paquete · comida · carga) y el ayudante vienen en la cotización firmada.
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
		$extra = $envio ? max( 0, (int) ( $q['extra'] ?? 0 ) ) : 0;
		$lim = CVD_Pv_Tarifa::limites_extra( $precio_de, (int) $q['justo'], (bool) $q['noche'], $extra );
		$oferta = CVD_Pv_Tarifa::ajustar( (int) $request->get_param( 'precio' ), $lim );
		// v3: el servicio viene en la cotización firmada; las cotizaciones viejas (sin él) se leen como antes.
		$serv = (string) ( $q['serv'] ?? '' );
		if ( ! in_array( $serv, CVD_Pv_Tarifa::SERVICIOS, true ) || ( $envio xor 'viaje' !== $serv ) ) { $serv = $envio ? 'paquete' : ( 'tric_carga' === $clase ? 'carga' : 'viaje' ); }
		$e = (array) $request->get_param( 'envio' );
		$que = self::txt( $e['que'] ?? '', 120 );
		if ( $envio && mb_strlen( $que ) < 2 ) { return self::fail( 'envio', 'comida' === $serv ? 'Escribe qué comida es.' : ( 'carga' === $serv ? 'Escribe qué llevas.' : 'Escribe qué mandas.' ) ); }
		// Comida y Carga: si no dice quién recibe, recibe el mismo cliente.
		$rtel = CVD_Triciclub::telefono( $e['recibe_telefono'] ?? '' );
		$rnom = self::txt( $e['recibe_nombre'] ?? '', 80 );
		if ( $envio && '' === $rtel && in_array( $serv, array( 'comida', 'carga' ), true ) ) { $rtel = (string) $c->telefono; $rnom = '' !== $rnom ? $rnom : (string) $c->nombre; }
		if ( $envio && '' === $rtel ) { return self::fail( 'envio', 'Falta el teléfono de quien recibe.' ); }
		$peso = '';
		if ( 'carga' === $serv ) {
			$peso = sanitize_key( (string) ( $e['peso'] ?? '' ) );
			if ( ! isset( CVD_Pv_Tarifa::PESOS[ $peso ] ) ) { return self::fail( 'envio', 'Di cuánto pesa más o menos. Más de 300 kg o una mudanza: escríbenos.' ); }
		}
		$cobrar = $envio ? max( 0, min( 500000, (int) ( $e['cobrar_cup'] ?? 0 ) ) ) : 0;
		$adelanto = 0;
		if ( 'comida' === $serv ) {
			$adelanto = max( 0, (int) ( $e['adelanto_cup'] ?? 0 ) );
			$tope = CVD_Pv_Tarifa::comida_tope();
			if ( $adelanto > $tope ) { return self::fail( 'adelanto', 'El mensajero adelanta como mucho ' . number_format( $tope, 0, ',', '.' ) . ' CUP. Si es más, paga tú al restaurante por Transfermóvil.' ); }
			$cobrar = max( $cobrar, $adelanto ); // lo que adelantó el mensajero vuelve a él al entregar
		}
		// 10-nov: Viajar «para otra persona» — nombre y móvil de quien viaja (se guardan en recibe_*: el chofer la llama
		// a ella y el bot le manda por WhatsApp el código, el chofer y la chapa cuando hay chofer). Paga quien viaja, al llegar.
		$pas = $envio ? array() : (array) $request->get_param( 'pasajero' );
		$ptel = $envio ? '' : CVD_Triciclub::telefono( $pas['telefono'] ?? '' );
		$pnom = $envio ? '' : self::txt( $pas['nombre'] ?? '', 80 );
		if ( ! $envio && ( '' !== trim( (string) ( $pas['telefono'] ?? '' ) ) || '' !== $pnom ) ) {
			if ( mb_strlen( $pnom ) < 2 ) { return self::fail( 'pasajero', 'Escribe el nombre de quien viaja.' ); }
			if ( '' === $ptel ) { return self::fail( 'pasajero', 'El móvil de quien viaja: 8 números, empieza por 5.' ); }
			if ( $ptel === (string) $c->telefono ) { $ptel = ''; $pnom = ''; } // es el mismo cliente: viaja él
		}
		$now = self::now();
		$row = array(
			'uuid'            => wp_generate_uuid4(),
			'cliente_id'      => (int) $c->id,
			'cid'             => $cid,
			'chofer_id'       => 0,
			'servicio'        => $serv,
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
			'envio_que'       => $envio ? $que : '',
			'envio_tam'       => $envio ? (string) $q['tam'] : '',
			'recibe_nombre'   => $envio ? $rnom : $pnom,
			'recibe_telefono' => $envio ? $rtel : $ptel,
			'cobrar_cup'      => $cobrar,
			'entrega_codigo'  => $envio ? str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ) : '',
			'recoge_texto'    => $envio ? self::txt( $e['recoge'] ?? '', 120 ) : '',
			'adelanto_cup'    => $adelanto,
			'peso'            => $peso,
			'extra_cup'       => $extra,
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
		CVD_Triciclub_Db::evento( $id, 'cliente', (int) $c->id, 'pv_pedido', array( 'clase' => $clase, 'servicio' => $serv, 'oferta' => $oferta, 'justo' => (int) $q['justo'] ) );
		self::avisar_choferes( $viaje, false );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $viaje ) ) );
	}

	/** Suena a los choferes que pueden llevarlo (los más cercanos primero). $subio = el cliente subió el precio. */
	private static function avisar_choferes( object $viaje, bool $subio ): void {
		$lista = array();
		foreach ( self::en_linea( (float) $viaje->origen_lat, (float) $viaje->origen_lng ) as $ch ) {
			if ( ! self::puede_ver( $ch, $viaje ) ) { continue; }
			$lista[] = array( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $viaje->origen_lat, (float) $viaje->origen_lng ), (int) $ch->user_id );
		}
		sort( $lista );
		$ico = array( 'paquete' => '📦 Envío', 'comida' => '🍕 Comida', 'carga' => '📦 Carga' )[ (string) $viaje->servicio ] ?? '🚖 Pedido';
		$titulo = $ico . ( $subio ? ' · subió a ' : ' · ' ) . number_format( (int) $viaje->precio_oferta, 0, ',', '.' ) . ' CUP';
		// 10-nov (Ernesto): el aviso trae botones Aceptar · Rechazar · Silenciar; el texto, solo de dónde a dónde.
		$txt = self::corto( (string) $viaje->origen_texto ) . ' → ' . self::corto( (string) $viaje->destino_texto ) . ' · ' . number_format( (int) $viaje->distancia_m / 1000, 1, ',', '' ) . ' km';
		foreach ( array_slice( $lista, 0, self::AVISAR_MAX ) as $l ) {
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $l[1] ), array( 't' => 'pedido', 'id' => (int) $viaje->id ) );
			if ( ! $subio ) { self::aviso( $l[1], 'pv_pedido', $titulo, $txt, array( 'pv', 'pedido', 'v' . (int) $viaje->id ) ); } // «v<id>»: el botón Aceptar del aviso
		}
	}

	private static function aviso( int $user_id, string $type, string $title, string $txt, array $tags ): void {
		try {
			if ( class_exists( 'CVD_Palla_Push' ) && method_exists( 'CVD_Palla_Push', 'avisar' ) ) { CVD_Palla_Push::avisar( $user_id, $type, $title, $txt, home_url( '/palla/chofer/' ), $tags ); }
		} catch ( Throwable $e ) { unset( $e ); }
	}

	private static function corto( string $t ): string { return trim( explode( ',', $t )[0] ) ?: 'Punto en el mapa'; }

	/** v3: lo que el chofer lee del pedido en una línea: «Comida · adelanta 2.400», «Carga · 50–150 kg · con ayudante». */
	public static function etiqueta( object $v ): string {
		$s = (string) $v->servicio;
		if ( 'comida' === $s ) { return 'Comida' . ( (int) $v->adelanto_cup ? ' · adelanta ' . number_format( (int) $v->adelanto_cup, 0, ',', '.' ) : ' · ya pagada' ); }
		if ( 'carga' === $s ) { return 'Carga' . ( isset( CVD_Pv_Tarifa::PESOS[ (string) $v->peso ] ) ? ' · ' . CVD_Pv_Tarifa::PESOS[ (string) $v->peso ] : '' ) . ( (int) $v->extra_cup ? ' · con ayudante' : '' ); }
		if ( 'mensajero' === $v->clase ) { return 'Paquete'; }
		return 'Pasaje';
	}

	/** ¿Ve este chofer este pedido? Clase, distancia (4 km; 8 km pasados 45 s) y, si es en efectivo, sin deuda al tope. */
	private static function puede_ver( object $ch, object $viaje ): bool {
		if ( (int) $ch->user_id === (int) $viaje->chofer_id ) { return false; }
		if ( ! self::sirve( $ch, (string) $viaje->clase, (string) $viaje->envio_tam ) ) { return false; }
		// v3: la comida que hay que pagar al recoger, solo a quien marcó «Puedo adelantar dinero».
		if ( (int) ( $viaje->adelanto_cup ?? 0 ) > 0 && ! (int) ( $ch->adelanta ?? 0 ) ) { return false; }
		$edad = time() - (int) strtotime( (string) ( $viaje->busca_desde ?: $viaje->created_at ) . ' UTC' );
		$radio = $edad > 45 ? self::RADIO_2 : self::RADIO_1;
		if ( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $viaje->origen_lat, (float) $viaje->origen_lng ) > $radio ) { return false; }
		if ( 'efectivo' === $viaje->forma_pago ) { // 10-nov (auditoría): la deuda, una consulta por chofer y petición (no una por pedido)
			static $pausa = array();
			$k = (int) $ch->user_id . '|' . CVD_Pv_Tarifa::grupo( self::clase_de( $ch ) );
			if ( ! isset( $pausa[ $k ] ) ) { $pausa[ $k ] = CVD_Pv_Cuenta::pausa_efectivo( (int) $ch->user_id, CVD_Pv_Tarifa::grupo( self::clase_de( $ch ) ) ); }
			if ( $pausa[ $k ] ) { return false; }
		}
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
		// 10-nov (auditoría): si el chofer no toca «Terminé» (sin batería, se le olvidó), el viaje se cierra solo pasados
		// minutos × 3 + 30 min desde que recogió; si no, el cliente no podía pedir otro ni cancelar.
		if ( 'recogido' === $v->estado && $v->recogido_at ) {
			$limite = (int) strtotime( (string) $v->recogido_at . ' UTC' ) + ( max( 5, (int) $v->minutos ) * 3 + 30 ) * 60;
			return time() > $limite ? self::terminar( $v, 'sistema', 0 ) : $v;
		}
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

	/** Recogido → terminado (el chofer con «Terminé», el cliente con «Ya llegué» o el sistema si se quedó colgado). */
	private static function terminar( object $v, string $actor, int $actor_id ): object {
		global $wpdb;
		$now = self::now();
		list( $pct, $cup ) = CVD_Pv_Cuenta::apuntar( $v, (int) $v->precio + (int) $v->espera_cup );
		$n = $wpdb->update( self::t( 'viajes' ), array( 'estado' => 'terminado', 'terminado_at' => $now, 'updated_at' => $now, 'comision_pct' => $pct, 'comision_cup' => $cup ), array( 'id' => (int) $v->id, 'estado' => 'recogido' ) );
		if ( ! $n ) { return self::viaje( (int) $v->id ) ?: $v; }
		CVD_Triciclub_Db::evento( (int) $v->id, $actor, $actor_id, 'chofer' === $actor ? 'termine' : 'pv_terminado_' . $actor );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'choferes' ) . ' SET viajes_total=viajes_total+1 WHERE user_id=%d', (int) $v->chofer_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'clientes' ) . ' SET viajes_total=viajes_total+1 WHERE id=%d', (int) $v->cliente_id ) );
		$v2 = self::viaje( (int) $v->id );
		self::tocar( $v2, 'terminado' );
		return $v2;
	}

	/** POST viajes/{uuid}/llegue — 10-nov: el cliente dice «Ya llegué» (pasados 2 min de recoger) si el chofer no toca Terminé. */
	public static function llegue_cliente( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		if ( 'terminado' === $v->estado ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) ); }
		if ( 'recogido' !== $v->estado ) { return self::fail( 'tarde', 'Todavía no has subido.', 409 ); }
		if ( (int) strtotime( (string) $v->recogido_at . ' UTC' ) > time() - 120 ) { return self::fail( 'pronto', 'Acabas de subir. Avisa al llegar.', 409 ); }
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::terminar( $v, 'cliente', (int) $c->id ) ) ) );
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
		$por_pagar = 'terminado' === $v->estado && null === $v->cobrado_at && ( ! $v->terminado_at || (int) strtotime( (string) $v->terminado_at . ' UTC' ) > time() - 2 * HOUR_IN_SECONDS ); // 10-nov: no para siempre
		if ( $chofer && ( $activo || $por_pagar ) ) {
			$tel = preg_replace( '/\D+/', '', (string) ( get_user_meta( (int) $v->chofer_id, '_cvd_whatsapp', true ) ?: get_user_meta( (int) $v->chofer_id, 'billing_phone', true ) ) );
			$chofer['telefono'] = 8 === strlen( $tel ) ? '53' . $tel : $tel;
			if ( 'transferencia' === $v->forma_pago || $por_pagar ) { $ch = CVD_Triciclub::chofer_de( (int) $v->chofer_id ); $chofer['transferencia'] = $ch ? (string) $ch->transferencia : ''; }
		}
		$envio = 'mensajero' === $v->clase;
		$lim = self::lim_de( $v );
		$espera = 'llego' === $v->estado ? CVD_Pv_Tarifa::espera( $v->llego_at, self::now() ) : (int) $v->espera_cup;
		return array(
			'uuid'          => (string) $v->uuid,
			'estado'        => (string) $v->estado,
			'rev'           => (int) $v->rev,
			'canal'         => (string) $v->canal,
			'clase'         => (string) $v->clase,
			'servicio'      => (string) $v->servicio,
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
			'pasajero'      => self::pasajero( $v ),
			'chofer'        => $chofer,
			'ofertas'       => 'buscando' === $v->estado ? self::ofertas_de( $v ) : array(),
			'busca_hasta'   => 'buscando' === $v->estado ? gmdate( 'c', (int) strtotime( (string) ( $v->busca_desde ?: $v->created_at ) . ' UTC' ) + self::BUSCA_SEG ) : null,
			'pos'           => $pos = ( $activo ? self::posicion( $v ) : null ),
			'linea'         => self::linea_viaje( $v ),
			'viene'         => in_array( $v->estado, array( 'asignado', 'llego' ), true ) ? self::ruta_viene( $v, $pos ) : null,
			'envio'         => $envio ? array( 'que' => (string) $v->envio_que, 'tam' => (string) $v->envio_tam, 'recibe' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'cobrar' => (int) $v->cobrar_cup, 'codigo' => (string) $v->entrega_codigo, 'foto' => '' !== (string) $v->entrega_foto ? self::foto_url( $v ) : '',
				'recoge' => (string) $v->recoge_texto, 'adelanto' => (int) $v->adelanto_cup, 'recibo' => '' !== (string) $v->recibo_foto ? self::foto_url( $v, 'recibo' ) : '', 'peso' => (string) $v->peso, 'ayudante' => (int) $v->extra_cup ) : null,
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
			if ( 'asignado' === $v->estado ) { $out['viene'] = self::ruta_viene( $v, $out['pos'] ); } // si se desvió, la ruta nueva
			return self::out( $out );
		}
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) );
	}

	// ------------------------------------------------------------------ Rutas por calles (como Uber/Yandex: las hace el servidor)

	/** La ruta del viaje (recogida → destino) por calles: de la caché o de nuestro OSRM. Nunca una recta: si falla, null. */
	private static function linea_viaje( object $v ): ?array {
		$t = self::trayecto( self::perfil( (string) $v->clase ), (float) $v->origen_lat, (float) $v->origen_lng, (float) $v->destino_lat, (float) $v->destino_lng );
		return ! empty( $t['linea'] ) ? $t['linea'] : null;
	}

	/**
	 * La ruta del chofer hasta la recogida, por calles. Se calcula una vez y se guarda (2 h); solo se rehace si el chofer
	 * se aparta más de 120 m de ella (se desvió o cogió otra calle), como el «rerouting» de las apps grandes.
	 */
	private static function ruta_viene( object $v, ?array $pos ): ?array {
		if ( ! $pos ) { return null; }
		$key = 'cvd_pv_viene_' . (int) $v->id;
		$guardada = get_transient( $key );
		$linea = is_array( $guardada ) ? $guardada : null;
		if ( $linea && self::lejos_de_linea( $linea, (float) $pos['lat'], (float) $pos['lng'] ) <= 120 ) { return $linea; }
		$t = self::trayecto( self::perfil( (string) $v->clase ), (float) $pos['lat'], (float) $pos['lng'], (float) $v->origen_lat, (float) $v->origen_lng );
		if ( empty( $t['linea'] ) ) { return $linea; } // OSRM no contestó: la de antes (o nada), nunca una recta
		set_transient( $key, $t['linea'], 2 * HOUR_IN_SECONDS );
		return $t['linea'];
	}

	/** Con qué calles se va: el taxi por las de carro; triciclo, bici, moto y mensajero, por el camino corto (perfil de bici). */
	public static function perfil( string $clase ): string { return 'taxi' === CVD_Pv_Tarifa::grupo( $clase ) ? 'driving' : 'cycling'; }

	/**
	 * Ruta por calles con el perfil del vehículo (5-nov, Ernesto: «el triciclo por el camino corto, no el del carro»).
	 * 'cycling' = segundo motor OSRM del VPS con el perfil de bici (/route/v1/cycling → 127.0.0.1:5001): usa parques, calles
	 * peatonales y atajos, como las apps grandes con su perfil de moto/bici. Si no contesta, la de carro; nunca una recta.
	 * Apagar: wp option update cvd_pv_ruta_bici no.
	 * Revisión 7-nov: a veces el perfil de bici da una vuelta enorme (550 m del chofer a la recogida → 5 km por el motor de
	 * bici, 1 km por el de carro). Si la de bici sale más larga que la de carro, se usa la de carro: siempre la más corta.
	 */
	public static function trayecto( string $perfil, float $olat, float $olng, float $dlat, float $dlng ): array {
		if ( 'cycling' !== $perfil || 'no' === get_option( 'cvd_pv_ruta_bici', 'si' ) ) { return CVD_Triciclub_Precio::trayecto( $olat, $olng, $dlat, $dlng ); }
		$bici = self::trayecto_bici( $olat, $olng, $dlat, $dlng );
		if ( 'calles' !== $bici['como'] ) { return $bici; } // ya es la de carro (o nada)
		$carro = CVD_Triciclub_Precio::trayecto( $olat, $olng, $dlat, $dlng );
		return 'calles' === $carro['como'] && (int) $carro['metros'] < (int) $bici['metros'] ? $carro : $bici;
	}

	/** La ruta del motor OSRM de bici (caché 1 h). Si no contesta, la de carro. */
	private static function trayecto_bici( float $olat, float $olng, float $dlat, float $dlng ): array {
		$key = 'cvd_pv_rb_' . md5( implode( ',', array_map( static fn( float $x ): string => number_format( $x, 4, '.', '' ), array( $olat, $olng, $dlat, $dlng ) ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }
		$api = rtrim( (string) get_option( 'cvd_tc_ruta_api', 'https://179-236-242-212.sslip.io' ), '/' );
		$url = $api . '/route/v1/cycling/' . $olng . ',' . $olat . ';' . $dlng . ',' . $dlat . '?overview=full&geometries=geojson';
		for ( $i = 0; $i < 1; $i++ ) { // 10-nov (auditoría): 1 intento de 2 s (no ocupar Hostinger si el VPS va lento)
			$res = wp_remote_get( $url, array( 'timeout' => 2, 'redirection' => 0 ) );
			$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$route = is_array( $data ) && 'Ok' === ( $data['code'] ?? '' ) ? ( $data['routes'][0] ?? null ) : null;
			if ( is_array( $route ) && (float) ( $route['distance'] ?? 0 ) > 0 ) {
				$linea = array();
				foreach ( (array) ( $route['geometry']['coordinates'] ?? array() ) as $c ) {
					if ( is_array( $c ) && isset( $c[0], $c[1] ) ) { $linea[] = array( round( (float) $c[1], 5 ), round( (float) $c[0], 5 ) ); }
				}
				$metros = (int) round( (float) $route['distance'] );
				$out = array( 'metros' => $metros, 'minutos' => CVD_Triciclub_Precio::minutos_triciclo( $metros ), 'como' => 'calles', 'linea' => count( $linea ) > 1 ? $linea : null );
				set_transient( $key, $out, HOUR_IN_SECONDS );
				return $out;
			}
		}
		return CVD_Triciclub_Precio::trayecto( $olat, $olng, $dlat, $dlng );
	}

	/** Metros desde un punto a la línea más cercana de una ruta [[lat, lng]…]. */
	public static function lejos_de_linea( array $linea, float $lat, float $lng ): float {
		$kx = 111320 * cos( deg2rad( $lat ) );
		$ky = 110540;
		$mejor = INF;
		for ( $i = 1, $n = count( $linea ); $i < $n; $i++ ) {
			$ax = ( $linea[ $i - 1 ][1] - $lng ) * $kx; $ay = ( $linea[ $i - 1 ][0] - $lat ) * $ky;
			$bx = ( $linea[ $i ][1] - $lng ) * $kx; $by = ( $linea[ $i ][0] - $lat ) * $ky;
			$dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
			$t = $l2 > 0 ? max( 0, min( 1, -( $ax * $dx + $ay * $dy ) / $l2 ) ) : 0;
			$mejor = min( $mejor, hypot( $ax + $t * $dx, $ay + $t * $dy ) );
		}
		return $mejor;
	}

	public static function mis_viajes( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE cliente_id=%d AND clase<>'' ORDER BY id DESC LIMIT 30", (int) $c->id ) );
		$out = array();
		foreach ( $rows as $r ) {
			$r = self::caducar( $r );
			$ch = (int) $r->chofer_id ? self::chofer_publico( (int) $r->chofer_id ) : null;
			$out[] = array( 'uuid' => (string) $r->uuid, 'estado' => (string) $r->estado, 'clase' => (string) $r->clase, 'servicio' => (string) $r->servicio, 'que' => (string) $r->envio_que, 'grupo' => CVD_Pv_Tarifa::grupo( (string) $r->clase ), 'destino' => array( 'texto' => (string) $r->destino_texto, 'lat' => (float) $r->destino_lat, 'lng' => (float) $r->destino_lng ), 'origen' => array( 'texto' => (string) $r->origen_texto, 'lat' => (float) $r->origen_lat, 'lng' => (float) $r->origen_lng ), 'total' => (int) $r->precio + (int) $r->espera_cup, 'chofer' => $ch ? $ch['nombre'] : '', 'fecha' => self::iso( $r->created_at ), 'foto' => '' !== (string) $r->entrega_foto );
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
		$p = CVD_Pv_Tarifa::ajustar( (int) $request->get_param( 'precio' ), self::lim_de( $v ) );
		$now = self::now();
		// Volver a buscar desde ahora (4 min más). Las ofertas «Acepto» viejas quedan (son más baratas para el cliente).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET precio_oferta=%d, precio=%d, estado='buscando', busca_desde=%s, updated_at=%s WHERE id=%d AND estado IN ('buscando','sin_chofer')", $p, $p, $now, $now, (int) $v->id ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'cliente', (int) $c->id, 'pv_precio', array( 'antes' => (int) $v->precio_oferta, 'ahora' => $p ) );
		$v = self::viaje( (int) $v->id );
		self::tocar( $v, 'precio' );
		self::avisar_choferes( $v, true );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::viaje( (int) $v->id ) ) ) );
	}

	/**
	 * POST viajes/{uuid}/primera {on} — v3: «Coger la 1.ª que acepte» se enciende en la pantalla de ofertas. Si al encenderlo
	 * ya hay un «Acepto» (a su precio) vivo, se queda con el viaje al momento (el más barato; si empatan, el que llega antes).
	 */
	public static function primera( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$v = self::por_uuid( (string) $request['uuid'] );
		if ( ! $v || (int) $v->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		$on = rest_sanitize_boolean( $request->get_param( 'on' ) );
		if ( ! in_array( $v->estado, array( 'buscando', 'sin_chofer' ), true ) ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $v ) ) ); }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . ' SET primera=%d, updated_at=%s WHERE id=%d', $on ? 1 : 0, self::now(), (int) $v->id ) );
		$v = self::viaje( (int) $v->id );
		if ( $on && 'buscando' === $v->estado ) {
			$oids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'ofertas' ) . " WHERE viaje_id=%d AND estado='activa' AND pct=0 AND vence_at>%s ORDER BY precio ASC, llega_min ASC", (int) $v->id, self::now() ) );
			foreach ( array_map( 'intval', (array) $oids ) as $oid ) {
				if ( '' === self::asignar( $v, $oid ) ) { break; }
				$v = self::viaje( (int) $v->id );
			}
		}
		$v = self::viaje( (int) $v->id );
		if ( 'buscando' === $v->estado ) { self::tocar( $v, 'precio' ); }
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
		// 10-nov (auditoría): con candado por chofer, dos clientes que lo eligen en el mismo segundo no se lo quedan los
		// dos; y no se puede elegir a un chofer que ya se desconectó.
		$lock = 'cvd_pv_ch_' . (int) $o->chofer_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		$fch = CVD_Triciclub::chofer_de( (int) $o->chofer_id );
		if ( self::ocupado( (int) $o->chofer_id ) || ! $fch || ! (int) $fch->disponible ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$wpdb->update( self::t( 'ofertas' ), array( 'estado' => 'retirada', 'updated_at' => self::now() ), array( 'id' => (int) $o->id ) );
			return $fch && ! (int) $fch->disponible ? 'Ese chofer se desconectó. Elige otra oferta.' : 'Ese chofer acaba de coger otro viaje. Elige otra oferta.';
		}
		$now = self::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='asignado', chofer_id=%d, precio=%d, oferta_id=%d, respondido_at=%s, updated_at=%s WHERE id=%d AND estado='buscando'", (int) $o->chofer_id, (int) $o->precio, (int) $o->id, $now, $now, (int) $v->id ) );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
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
		self::aviso( (int) $o->chofer_id, 'pv_elegido', '✅ ¡Te eligieron! ' . number_format( (int) $o->precio, 0, ',', '.' ) . ' CUP', 'Ve a recoger en ' . self::corto( (string) $v->origen_texto ) . '.', array( 'pv', 'elegido', 'v' . (int) $v->id ) );
		foreach ( array_map( 'intval', (array) $perdieron ) as $uid ) { CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $uid ), array( 't' => 'perdio', 'id' => (int) $v->id ) ); }
		self::avisar_pasajero( $v, 'asignado' );
		self::cerrar_avisos( $v );
		return '';
	}

	/** 10-nov (auditoría): a los choferes cerca, «ese pedido ya no está»: su app calla la bocina y quita el aviso. */
	private static function cerrar_avisos( object $v ): void {
		if ( ! class_exists( 'CVD_Palla_Push' ) || ! method_exists( 'CVD_Palla_Push', 'cerrar' ) ) { return; }
		$n = 0;
		foreach ( self::en_linea( (float) $v->origen_lat, (float) $v->origen_lng ) as $ch ) {
			if ( (int) $ch->user_id === (int) $v->chofer_id ) { continue; }
			if ( CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $v->origen_lat, (float) $v->origen_lng ) > self::RADIO_2 ) { continue; }
			try { CVD_Palla_Push::cerrar( (int) $ch->user_id, array( 'pv', 'cerrada', 'v' . (int) $v->id ) ); } catch ( Throwable $e ) { unset( $e ); }
			if ( ++$n >= self::AVISAR_MAX ) { break; }
		}
	}

	/** 10-nov: quien viaja cuando el cliente pidió para otra persona (null = viaja el cliente; los envíos nunca). */
	private static function pasajero( object $v ): ?array {
		if ( 'mensajero' === $v->clase || '' === (string) $v->recibe_telefono ) { return null; }
		return array( 'nombre' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'avisado' => self::pasajero_avisado( (int) $v->id ) );
	}

	/** Si el bot ya tiene en cola el WhatsApp a quien viaja (en prueba, a un móvil que no es de prueba no se le escribe). */
	private static function pasajero_avisado( int $viaje_id ): bool {
		global $wpdb;
		$d = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT datos FROM ' . self::t( 'eventos' ) . " WHERE viaje_id=%d AND tipo='pv_pasajero_aviso' ORDER BY id DESC LIMIT 1", $viaje_id ) );
		return '' !== $d && ! empty( json_decode( $d, true )['ok'] );
	}

	private static function nombre1( string $n ): string { return trim( (string) explode( ' ', trim( $n ) )[0] ); }

	/**
	 * 10-nov (Ernesto: «le escribe el bot»): WhatsApp a quien viaja — al tener chofer (quién, carro, chapa, código, precio)
	 * y si el cliente cancela después. En prueba solo a los teléfonos de prueba (una cuenta con teléfono recibe WhatsApp real).
	 */
	private static function avisar_pasajero( object $v, string $que ): void {
		$p = self::pasajero( $v );
		if ( ! $p ) { return; }
		if ( 'si' !== CVD_Pv::mode() && ! in_array( $p['telefono'], CVD_Triciclub::clientes_prueba(), true ) ) {
			CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', 0, 'pv_pasajero_sin_aviso', array( 'que' => $que, 'por' => 'prueba' ) );
			return;
		}
		$cli = CVD_Triciclub::cliente( (int) $v->cliente_id );
		$quien = $cli ? self::nombre1( (string) $cli->nombre ) : '';
		$nom = self::nombre1( $p['nombre'] );
		if ( 'cancelado' === $que ) {
			$txt = 'Hola ' . $nom . '. ' . ( '' !== $quien ? $quien : 'Quien te pidió el viaje' ) . " canceló el Pa'llá. Ya no tienes que esperar.";
		} else {
			$ch = self::chofer_publico( (int) $v->chofer_id );
			$veh = $ch ? trim( $ch['tipo'] . ( '' !== $ch['color'] ? ' ' . $ch['color'] : '' ) . ( '' !== $ch['chapa'] ? ', chapa ' . $ch['chapa'] : '' ) ) : '';
			$txt = 'Hola ' . $nom . ' 👋 ' . ( '' !== $quien ? $quien : 'Alguien' ) . " te pidió un Pa'llá.\n"
				. '🚗 Te recoge ' . ( $ch ? $ch['nombre'] : 'tu chofer' ) . ( '' !== $veh ? ' (' . $veh . ')' : '' ) . ".\n"
				. '📍 En: ' . self::corto( (string) $v->origen_texto ) . "\n"
				. '🔑 Código para subir: ' . $v->codigo . " (díselo al chofer)\n"
				. '💵 Pagas al llegar: ' . number_format( (int) $v->precio, 0, ',', '.' ) . ' CUP';
		}
		$ok = CVD_Triciclub::aviso_whatsapp( $p['telefono'], $txt, (string) $v->codigo );
		CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', 0, 'pv_pasajero_aviso', array( 'que' => $que, 'ok' => $ok ) );
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
		if ( 'buscando' === $v->estado ) { self::cerrar_avisos( $v2 ); }
		if ( (int) $v->chofer_id && in_array( $v->estado, self::ACTIVOS, true ) ) {
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( (int) $v->chofer_id ), array( 't' => 'cancelado', 'id' => (int) $v->id ) );
			self::aviso( (int) $v->chofer_id, 'pv_cancelado', 'El cliente canceló', 'Ya no hace falta que vayas a ' . self::corto( (string) $v->origen_texto ) . '.', array( 'pv', 'cancelled' ) );
			self::avisar_pasajero( $v2, 'cancelado' );
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
				'adelanta'   => (bool) (int) ( $ch->adelanta ?? 0 ), // v3: «Puedo adelantar dinero» (comida pagada al recoger)
				'adelanta_tope' => CVD_Pv_Tarifa::comida_tope(),
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
		// 10-nov (auditoría): solo los de un cuadro de ~9 km alrededor del chofer (antes los 60 más nuevos de toda la ciudad).
		$caja = null !== $ch->lat ? $wpdb->prepare( ' AND origen_lat BETWEEN %f AND %f AND origen_lng BETWEEN %f AND %f', (float) $ch->lat - 0.08, (float) $ch->lat + 0.08, (float) $ch->lng - 0.085, (float) $ch->lng + 0.085 ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$v} WHERE estado='buscando' AND clase<>'' AND busca_desde>%s{$caja} ORDER BY id DESC LIMIT 60", $desde ) );
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
			'servicio' => (string) $r->servicio,
			'etiqueta' => self::etiqueta( $r ),
			'envio'    => 'mensajero' === $r->clase ? array( 'que' => (string) $r->envio_que, 'tam' => (string) $r->envio_tam, 'recoge' => (string) $r->recoge_texto, 'adelanto' => (int) $r->adelanto_cup, 'cobrar' => (int) $r->cobrar_cup, 'peso' => (string) $r->peso, 'ayudante' => (int) $r->extra_cup ) : null,
			'oferta'   => (int) $r->precio_oferta,
			'contra'   => array_map( static fn( int $p ): array => array( 'pct' => $p, 'precio' => CVD_Pv_Tarifa::contra( (int) $r->precio_oferta, $p ) ), CVD_Pv_Tarifa::CONTRA ),
			'origen'   => array( 'texto' => (string) $r->origen_texto, 'lat' => (float) $r->origen_lat, 'lng' => (float) $r->origen_lng ),
			'destino'  => array( 'texto' => (string) $r->destino_texto, 'lat' => (float) $r->destino_lat, 'lng' => (float) $r->destino_lng ),
			'km'       => round( (int) $r->distancia_m / 1000, 1 ),
			'lejos_m'  => $lejos,
			'forma_pago' => (string) $r->forma_pago,
			'nota'     => (string) $r->nota,
			'cliente'  => $cli ? array( 'nombre' => ( self::pasajero( $r ) ? self::nombre1( (string) $r->recibe_nombre ) : trim( explode( ' ', (string) $cli->nombre )[0] ) ) ?: 'Cliente', 'nota' => null !== $cli->nota_media ? round( (float) $cli->nota_media, 1 ) : null, 'viajes' => (int) $cli->viajes_total ) : null,
			'pide'     => self::pasajero( $r ) && $cli ? self::nombre1( (string) $cli->nombre ) : '', // 10-nov: lo pidió otra persona
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
			'servicio'   => (string) $v->servicio,
			'etiqueta'   => self::etiqueta( $v ),
			'origen'     => array( 'texto' => (string) $v->origen_texto, 'lat' => (float) $v->origen_lat, 'lng' => (float) $v->origen_lng ),
			'destino'    => array( 'texto' => (string) $v->destino_texto, 'lat' => (float) $v->destino_lat, 'lng' => (float) $v->destino_lng ),
			'km'         => round( (int) $v->distancia_m / 1000, 1 ),
			'minutos'    => (int) $v->minutos,
			'precio'     => (int) $v->precio,
			'espera'     => $espera,
			'total'      => (int) $v->precio + $espera,
			'forma_pago' => (string) $v->forma_pago,
			'nota'       => (string) $v->nota,
			// 10-nov: si pidió para otra persona, el chofer ve y llama a quien viaja (y sabe quién lo pidió).
			'cliente'    => $cli ? ( self::pasajero( $v ) ? array( 'nombre' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'nota' => null, 'viajes' => 0 ) : array( 'nombre' => (string) $cli->nombre, 'telefono' => (string) $cli->telefono, 'nota' => null !== $cli->nota_media ? round( (float) $cli->nota_media, 1 ) : null, 'viajes' => (int) $cli->viajes_total ) ) : null,
			'pide'       => self::pasajero( $v ) && $cli ? self::nombre1( (string) $cli->nombre ) : '',
			'envio'      => $envio ? array( 'que' => (string) $v->envio_que, 'tam' => (string) $v->envio_tam, 'recibe' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'cobrar' => (int) $v->cobrar_cup, 'foto' => '' !== (string) $v->entrega_foto,
				'recoge' => (string) $v->recoge_texto, 'adelanto' => (int) $v->adelanto_cup, 'recibo' => '' !== (string) $v->recibo_foto, 'peso' => (string) $v->peso, 'ayudante' => (int) $v->extra_cup ) : null,
			'llego'      => self::iso( $v->llego_at ),
			'cobrado'    => null !== $v->cobrado_at,
			'linea'      => self::linea_viaje( $v ),
			'viene'      => in_array( $v->estado, array( 'asignado', 'llego' ), true ) ? self::ruta_viene( $v, self::posicion( $v ) ) : null, // su camino a la recogida, por calles
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
		if ( null !== $request->get_param( 'adelanta' ) ) { $set['adelanta'] = rest_sanitize_boolean( $request->get_param( 'adelanta' ) ) ? 1 : 0; } // v3
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

	/**
	 * GET chofer/app — 10-nov (Ernesto: app de chofer aparte, con la bocina de los mensajeros): el tema de ntfy donde
	 * `CVD_Palla_Push::avisar` le manda «🚖 Pedido», «✅ ¡Te eligieron!» y «El cliente canceló». La app lo escucha con un
	 * servicio en primer plano y suena con la pantalla apagada (la web no puede). Mismo tema que usaría la app de mensajeros.
	 */
	public static function chofer_app( WP_REST_Request $request ): WP_REST_Response {
		$u = self::user( $request );
		$ch = self::ficha( $u );
		if ( ! $ch || 'aprobado' !== $ch->estado ) { return self::out( array( 'ok' => false, 'result' => 'no_aprobado' ) ); }
		$url = (string) get_option( 'cvd_palla_ntfy_url', '' );
		if ( 0 !== strpos( $url, 'https://' ) ) { return self::out( array( 'ok' => false, 'result' => 'no_server' ) ); }
		$topic = (string) get_user_meta( $u->ID, CVD_Palla_Push::NTFY_TOPIC, true );
		if ( '' === $topic ) {
			$topic = 'palla-' . $u->ID . '-' . bin2hex( random_bytes( 8 ) );
			update_user_meta( $u->ID, CVD_Palla_Push::NTFY_TOPIC, $topic );
		}
		return self::out( array( 'ok' => true, 'url' => untrailingslashit( $url ), 'topic' => $topic ) );
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
		else {
			$wpdb->insert( $of, $datos + array( 'viaje_id' => (int) $v->id, 'chofer_id' => $u->ID, 'created_at' => $now ) );
			$oid = (int) $wpdb->insert_id;
			if ( ! $oid ) { $oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$of} WHERE viaje_id=%d AND chofer_id=%d ORDER BY id DESC LIMIT 1", (int) $v->id, $u->ID ) ); } // 10-nov: dos toques rápidos
		}
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
				CVD_Pv_Cuenta::revision( (int) $v->id ); // 10-nov (auditoría): no debe comisión de lo que no cobró (hasta revisar)
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
		// 10-nov (auditoría): «Llegué» solo cerca de la recogida (si su posición es de los últimos 3 min); si no, la espera
		// de 50 CUP/min empezaría a contar desde lejos.
		if ( 'llegue' === $accion ) {
			$fch = self::ficha( $u );
			if ( $fch && null !== $fch->lat && $fch->ubicado_at && (int) strtotime( (string) $fch->ubicado_at . ' UTC' ) > time() - 180 ) {
				$lejos = CVD_Triciclub_Precio::recta_m( (float) $fch->lat, (float) $fch->lng, (float) $v->origen_lat, (float) $v->origen_lng );
				if ( $lejos > 400 ) { return self::fail( 'lejos', 'Estás a ' . ( $lejos >= 1000 ? number_format( $lejos / 1000, 1, ',', '' ) . ' km' : $lejos . ' m' ) . '. Toca «Llegué» al llegar.', 409 ); }
			}
		}
		$codigo = preg_replace( '/\D+/', '', (string) $request->get_param( 'codigo' ) );
		if ( 'recogi' === $accion && ! $envio && ! hash_equals( (string) $v->codigo, $codigo ) ) {
			return self::fail( 'codigo', 'Código mal. Pídeselo al cliente.' );
		}
		// v3 Comida: si el mensajero adelanta el dinero, antes de salir hace la foto del recibo.
		if ( 'recogi' === $accion && (int) $v->adelanto_cup > 0 && '' === (string) $v->recibo_foto ) {
			return self::fail( 'recibo', 'Haz la foto del recibo antes de salir.' );
		}
		if ( 'termine' === $accion && $envio ) {
			if ( ! hash_equals( (string) $v->entrega_codigo, $codigo ) ) { return self::fail( 'codigo', 'Ese no es el código de entrega. Pídeselo a quien recibe.' ); }
			if ( '' === (string) $v->entrega_foto ) { return self::fail( 'foto', 'Haz la foto de la entrega antes de terminar.' ); }
		}
		if ( 'termine' === $accion ) {
			$v2 = self::terminar( $v, 'chofer', $u->ID );
			if ( 'terminado' !== $v2->estado ) { return self::fail( 'tarde', 'El viaje cambió. Mira otra vez.', 409 ); }
			return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( $v2 ) ) );
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
		// 10-nov (auditoría): los que perdieron antes vuelven a ver el pedido y pueden ofertar otra vez.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='caducada', updated_at=%s WHERE viaje_id=%d AND estado='perdio'", $now, (int) $v->id ) );
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

	private static function firma_foto( string $uuid, string $tipo = '' ): string { return substr( hash_hmac( 'sha256', 'pv-foto|' . $uuid . ( 'recibo' === $tipo ? '|recibo' : '' ), wp_salt( 'auth' ) ), 0, 24 ); }

	private static function foto_url( object $v, string $tipo = '' ): string {
		$q = array( 'v' => (string) $v->uuid, 'f' => self::firma_foto( (string) $v->uuid, $tipo ) );
		if ( 'recibo' === $tipo ) { $q['t'] = 'recibo'; }
		return add_query_arg( $q, rest_url( self::NS . '/pv/foto' ) );
	}

	/**
	 * POST chofer/viajes/{id}/foto (multipart «foto» [, tipo=recibo]) — la foto de la entrega (al entregar) o, v3, la del
	 * recibo de la comida que adelantó (al recoger). Se guarda de nuevo (sin EXIF ni GPS).
	 */
	public static function subir_foto( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::viaje_mio( $request, $u );
		$recibo = 'recibo' === sanitize_key( (string) $request->get_param( 'tipo' ) );
		if ( $recibo ) {
			if ( ! $v || 'mensajero' !== $v->clase || ! in_array( $v->estado, array( 'asignado', 'llego' ), true ) ) { return self::fail( 'tarde', 'La foto del recibo se hace al recoger.', 409 ); }
		} elseif ( ! $v || 'mensajero' !== $v->clase || 'recogido' !== $v->estado ) { return self::fail( 'tarde', 'La foto se hace al entregar.', 409 ); }
		$files = $request->get_file_params();
		$f = $files['foto'] ?? null;
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? 1 ) || (int) $f['size'] > 6 * MB_IN_BYTES ) { return self::fail( 'foto', 'No llegó la foto. Prueba otra vez.' ); }
		$info = @getimagesize( (string) $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) { return self::fail( 'foto', 'Eso no es una foto.' ); }
		$ed = wp_get_image_editor( (string) $f['tmp_name'] );
		if ( is_wp_error( $ed ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.', 500 ); }
		$ed->resize( 1280, 1280, false );
		$ed->set_quality( 80 );
		$nombre = ( $recibo ? 'r' : 'e' ) . (int) $v->id . '-' . bin2hex( random_bytes( 4 ) ) . '.jpg';
		$res = $ed->save( self::carpeta() . '/' . $nombre, 'image/jpeg' );
		if ( is_wp_error( $res ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.', 500 ); }
		$wpdb->update( self::t( 'viajes' ), array( $recibo ? 'recibo_foto' : 'entrega_foto' => $nombre, 'updated_at' => self::now() ), array( 'id' => (int) $v->id ) );
		if ( $recibo ) { self::tocar( self::viaje( (int) $v->id ), 'recibo' ); }
		return self::out( array( 'ok' => true ) );
	}

	/** GET foto?v=uuid&f=firma[&t=recibo] — la foto de la entrega o del recibo (solo con el enlace firmado que ve el cliente). */
	public static function ver_foto( WP_REST_Request $request ) {
		global $wpdb;
		$uuid = (string) $request->get_param( 'v' );
		$tipo = 'recibo' === (string) $request->get_param( 't' ) ? 'recibo' : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || ! hash_equals( self::firma_foto( $uuid, $tipo ), (string) $request->get_param( 'f' ) ) ) { return new WP_Error( 'pv_foto', 'No.', array( 'status' => 404 ) ); }
		$nombre = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ' . ( 'recibo' === $tipo ? 'recibo_foto' : 'entrega_foto' ) . ' FROM ' . self::t( 'viajes' ) . ' WHERE uuid=%s', $uuid ) );
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
