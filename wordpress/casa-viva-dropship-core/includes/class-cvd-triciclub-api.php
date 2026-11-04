<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.117 (Ernesto 3-nov, D129–D130): Triciclub, la API (casa-viva/v1/triciclub/…). Todo `no-store`.
 *
 * Cliente (X-TC-Token): entrar con código · buscar un lugar · ver los triciclos libres cerca con el precio de cada uno
 * (cada chofer pone el suyo, según la franja horaria) · pedir al que elija · seguir el viaje · cancelar · valorar.
 * Chofer (X-Palla-Token): su ficha y sus precios · disponible · aceptar o no (90 s) · llegué / recogí (con el código) /
 * terminé / cobré · soltar · ubicación · valorar al cliente.
 *
 * Los cambios de estado se guardan con UPDATE … WHERE estado = el de antes: dos toques o dos teléfonos no se pisan, y
 * repetir un botón (cola sin conexión) devuelve lo mismo sin hacer nada dos veces.
 */
final class CVD_Triciclub_Api {
	private const NS = 'casa-viva/v1';
	public const ESPERA = 90;          // s que tiene el chofer para aceptar
	public const RADIO_M = 4000;       // triciclos a menos de 4 km del punto de recogida (opción cvd_tc_radio_m)
	public const VIVO = 600;           // s: un chofer «disponible» sin posición nueva en 10 min no sale
	public const ACTIVOS = array( 'asignado', 'llego', 'recogido' );
	public const SERVICIOS = array( 'viaje', 'paquete', 'carga' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$c = array( __CLASS__, 'auth_cliente' );
		$d = array( __CLASS__, 'auth_chofer' );
		$open = '__return_true';
		$r = static function ( string $path, string $method, string $cb, $perm ): void {
			register_rest_route( self::NS, '/triciclub/' . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		// Cliente
		$r( 'cliente/codigo', 'POST', 'pedir_codigo', $open );
		$r( 'cliente/entrar', 'POST', 'entrar', $open );
		$r( 'yo', 'GET', 'yo', $c );
		$r( 'lugar', 'GET', 'lugar', $c );
		$r( 'donde', 'GET', 'donde', $c );       // 3.14.118: GPS/mapa → «Calle 23 e/ L y M»
		$r( 'sugerir', 'GET', 'sugerir', $c );   // 3.14.118: sugerencias mientras escribe
		$r( 'libres', 'GET', 'libres', $c );     // 3.14.118: triciclos libres en el mapa
		$r( 'cerca', 'GET', 'cerca', $c );
		$r( 'viajes', 'GET', 'mis_viajes', $c );
		$r( 'viajes', 'POST', 'crear_viaje', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})', 'GET', 'ver_viaje', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/cancelar', 'POST', 'cancelar', $c );
		$r( 'viajes/(?P<uuid>[a-f0-9-]{36})/valorar', 'POST', 'valorar_cliente', $c );
		// Chofer
		$r( 'chofer/hoy', 'GET', 'chofer_hoy', $d );
		$r( 'chofer/perfil', 'POST', 'chofer_perfil', $d );
		$r( 'chofer/disponible', 'POST', 'chofer_disponible', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/responder', 'POST', 'responder', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/estado', 'POST', 'estado', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/ubicacion', 'POST', 'ubicacion', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/valorar', 'POST', 'valorar_chofer', $d );
	}

	public static function auth_cliente( WP_REST_Request $request ) {
		if ( 'no' === CVD_Triciclub::mode() ) { return new WP_Error( 'tc_closed', CVD_Triciclub::texto_cerrado( 'closed' ), array( 'status' => 403 ) ); }
		$c = CVD_Triciclub::request_cliente( $request );
		return is_wp_error( $c ) ? $c : true;
	}

	public static function auth_chofer( WP_REST_Request $request ) {
		$u = CVD_Triciclub::request_chofer( $request );
		return is_wp_error( $u ) ? $u : true;
	}

	private static function out( array $data, int $status = 200 ): WP_REST_Response {
		// 3.14.118.1: LiteSpeed guardaba estas respuestas (sin cookies) y las repetía: el chofer no veía que aceptó, y la
		// caché no distingue el token, así que podía enseñar datos de una persona a otra. Nunca a la caché.
		do_action( 'litespeed_control_set_nocache', 'Triciclub: datos privados' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function fail( string $code, string $message, int $status = 400 ): WP_REST_Response {
		return self::out( array( 'ok' => false, 'code' => $code, 'message' => $message ), $status );
	}

	private static function cliente( WP_REST_Request $request ): object { return CVD_Triciclub::request_cliente( $request ); }

	private static function user( WP_REST_Request $request ): WP_User { return CVD_Triciclub::request_chofer( $request ); }

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	private static function coord( $v, bool $lat ): ?float {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) { return null; }
		$f = (float) $v;
		// Cuba: latitud 19.5–23.5, longitud -85 – -74. Fuera de ahí es un error del GPS o de la dirección.
		return $lat ? ( $f >= 19.5 && $f <= 23.5 ? $f : null ) : ( $f >= -85.0 && $f <= -74.0 ? $f : null );
	}

	private static function ip(): string { return substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ); }

	// ================================================================== Cliente

	public static function pedir_codigo( WP_REST_Request $request ): WP_REST_Response {
		if ( 'no' === CVD_Triciclub::mode() ) { return self::fail( 'tc_closed', CVD_Triciclub::texto_cerrado( 'closed' ), 403 ); }
		$tel = CVD_Triciclub::telefono( $request->get_param( 'telefono' ) );
		if ( '' === $tel ) { return self::fail( 'phone', 'Escribe tu móvil cubano: 8 números, empieza por 5.' ); }
		$reason = CVD_Triciclub::cliente_bloqueado( $tel );
		if ( '' !== $reason ) { return self::fail( 'tc_' . $reason, CVD_Triciclub::texto_cerrado( $reason ), 403 ); }
		$err = CVD_Triciclub::pedir_codigo( $tel, self::ip() );
		if ( 'limit' === $err ) { return self::fail( 'limit', 'Ya pediste varios códigos. Espera una hora o llama a Triciclub.', 429 ); }
		return self::out( array( 'ok' => true, 'message' => 'Te mandamos un código por WhatsApp. Llega en 1 o 2 minutos.' ) );
	}

	public static function entrar( WP_REST_Request $request ): WP_REST_Response {
		if ( 'no' === CVD_Triciclub::mode() ) { return self::fail( 'tc_closed', CVD_Triciclub::texto_cerrado( 'closed' ), 403 ); }
		$tel = CVD_Triciclub::telefono( $request->get_param( 'telefono' ) );
		$reason = '' === $tel ? 'phone' : CVD_Triciclub::cliente_bloqueado( $tel );
		if ( 'phone' === $reason ) { return self::fail( 'phone', 'Escribe tu móvil cubano.' ); }
		if ( '' !== $reason ) { return self::fail( 'tc_' . $reason, CVD_Triciclub::texto_cerrado( $reason ), 403 ); }
		$err = CVD_Triciclub::comprobar_codigo( $tel, (string) $request->get_param( 'codigo' ) );
		if ( '' !== $err ) {
			return self::fail( 'code_' . $err, array( 'wrong' => 'Ese código no es. Míralo bien en WhatsApp.', 'expired' => 'El código venció. Pide otro.', 'tries' => 'Demasiados intentos. Pide otro código.' )[ $err ] ?? 'No se pudo.' );
		}
		$nombre = self::txt( $request->get_param( 'nombre' ), 80 );
		$c = CVD_Triciclub::crear_cliente( $tel, $nombre, rest_sanitize_boolean( $request->get_param( 'consentimiento' ) ) );
		if ( ! $c ) { return self::fail( 'error', 'No se pudo entrar. Prueba otra vez.', 500 ); }
		if ( (int) $c->bloqueado ) { return self::fail( 'tc_blocked', CVD_Triciclub::texto_cerrado( 'blocked' ), 403 ); }
		return self::out( array( 'ok' => true, 'token' => CVD_Triciclub::token_cliente( $c ), 'yo' => self::yo_de( $c ) ) );
	}

	private static function yo_de( object $c ): array {
		return array( 'nombre' => (string) $c->nombre, 'telefono' => (string) $c->telefono, 'viajes' => (int) $c->viajes_total );
	}

	public static function yo( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$activo = self::activo_cliente( (int) $c->id );
		return self::out( array( 'ok' => true, 'yo' => self::yo_de( $c ), 'activo' => $activo ? self::vista_cliente( $activo ) : null, 'franja' => self::franja_publica() ) );
	}

	private static function franja_publica(): array {
		$f = CVD_Triciclub_Precio::franja_de();
		return array( 'id' => $f['id'], 'nombre' => $f['nombre'], 'desde' => $f['desde'], 'hasta' => $f['hasta'] );
	}

	/** GET lugar?texto=23 y L, Vedado → {lat, lng, texto} con las calles de La Habana del servidor. */
	public static function lugar( WP_REST_Request $request ): WP_REST_Response {
		$texto = self::txt( $request->get_param( 'texto' ), 160 );
		if ( mb_strlen( $texto ) < 3 ) { return self::fail( 'texto', 'Escribe la dirección: calle y entrecalles, o una esquina.' ); }
		$lat = self::coord( $request->get_param( 'lat' ), true ); // opcional: dónde está quien escribe (desempata cuadras)
		$lng = self::coord( $request->get_param( 'lng' ), false );
		$p = self::localizar( $texto, (string) $request->get_param( 'zona' ), null !== $lat && null !== $lng ? array( $lat, $lng ) : null );
		if ( ! $p ) { return self::fail( 'no_encontrado', 'No encontramos esa dirección. Prueba con calle y entrecalles, o toca el mapa.', 404 ); }
		return self::out( array( 'ok' => true, 'lat' => (float) $p['lat'], 'lng' => (float) $p['lon'], 'texto' => $texto, 'como' => (string) ( $p['how'] ?? '' ) ) );
	}

	/** Texto → {lat, lon, how} o null. «23 y L», «Obispo y Bernaza» = una esquina en Cuba (si no, sería «la calle 23»). */
	public static function localizar( string $texto, string $zona = '', ?array $cerca = null ): ?array {
		if ( ! class_exists( 'CVD_Geo_Direccion' ) || mb_strlen( trim( $texto ) ) < 3 ) { return null; }
		$p = null;
		// «23 y L» es una esquina; «L e/ 21 y 23» o «82 e 9 y 11» no (4-nov: se partía por la «y» y salía la esquina L y 21).
		if ( ! preg_match( '/\bentre\b|\besq|\be\s*\/|\be\.\s|\se\s+(?=\S+(?:\s+\S+)?\s+y\s)/i', $texto ) && preg_match( '/^(.+?)\s+y\s+(.+)$/iu', $texto, $m ) ) {
			$p = CVD_Geo_Direccion::locate( $m[1] . ' esquina ' . $m[2], $zona, $cerca );
			if ( $p && 'esquina' !== ( $p['how'] ?? '' ) ) { $p = null; }
		}
		if ( ! $p ) { $p = CVD_Geo_Direccion::locate( $texto, $zona, $cerca ); }
		// Una calle suelta («Obispo») no es una dirección: para eso están las sugerencias de calle + el mapa.
		return $p && isset( $p['lat'], $p['lon'] ) && in_array( $p['how'] ?? '', array( 'esquina', 'cuadra' ), true ) ? $p : null;
	}

	/** GET donde?lat&lng — el punto del GPS o del mapa escrito como en Cuba: «Calle 23 e/ L y M», con el barrio. */
	public static function donde( WP_REST_Request $request ): WP_REST_Response {
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( null === $lat || null === $lng ) { return self::fail( 'puntos', 'Ese punto está fuera de Cuba.' ); }
		$d = CVD_Triciclub_Geo::donde( $lat, $lng );
		return self::out( array( 'ok' => true, 'texto' => $d ? $d['texto'] : 'Punto en el mapa', 'barrio' => $d ? $d['barrio'] : '', 'conocido' => (bool) $d ) );
	}

	/** GET sugerir?texto&lat&lng — mientras escribe: dirección exacta si la entiende, calles y barrios. */
	public static function sugerir( WP_REST_Request $request ): WP_REST_Response {
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		$l = CVD_Triciclub_Geo::sugerir( self::txt( $request->get_param( 'texto' ), 80 ), $lat, null === $lat ? null : $lng );
		return self::out( array( 'ok' => true, 'sugerencias' => $l ) );
	}

	/** GET libres?lat&lng — dónde hay triciclos libres cerca (solo puntos, para pintarlos en el mapa). */
	public static function libres( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( null === $lat || null === $lng ) { return self::out( array( 'ok' => true, 'triciclos' => array() ) ); }
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, lat, lng FROM {$t} WHERE estado='aprobado' AND disponible=1 AND ubicado_at>%s AND lat IS NOT NULL LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - self::VIVO ) ) );
		$testers = 'prueba' === CVD_Triciclub::mode() ? CVD_Triciclub::testers() : null;
		$out = array();
		foreach ( $rows as $r ) {
			if ( null !== $testers && ! in_array( (int) $r->user_id, $testers, true ) ) { continue; }
			if ( CVD_Triciclub_Precio::recta_m( (float) $r->lat, (float) $r->lng, $lat, $lng ) > 5000 ) { continue; }
			// Un poco movido (±60 m): se ve dónde hay triciclos, no la casa exacta del chofer.
			$out[] = array( round( (float) $r->lat + ( wp_rand( -5, 5 ) / 10000 ), 5 ), round( (float) $r->lng + ( wp_rand( -5, 5 ) / 10000 ), 5 ) );
		}
		return self::out( array( 'ok' => true, 'triciclos' => $out ) );
	}

	/**
	 * GET cerca?servicio&olat&olng&dlat&dlng — los triciclos libres cerca de la recogida, cada uno con SU precio para ahora.
	 * Ordenados por precio y después por cercanía. Cada uno lleva su cotización firmada (10 min).
	 */
	public static function cerca( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$servicio = sanitize_key( (string) $request->get_param( 'servicio' ) );
		if ( ! in_array( $servicio, self::SERVICIOS, true ) ) { $servicio = 'viaje'; }
		$olat = self::coord( $request->get_param( 'olat' ), true );
		$olng = self::coord( $request->get_param( 'olng' ), false );
		$dlat = self::coord( $request->get_param( 'dlat' ), true );
		$dlng = self::coord( $request->get_param( 'dlng' ), false );
		if ( null === $olat || null === $olng || null === $dlat || null === $dlng ) { return self::fail( 'puntos', 'Falta dónde recoger o a dónde llevar.' ); }
		$tray = CVD_Triciclub_Precio::trayecto( $olat, $olng, $dlat, $dlng );
		$franja = CVD_Triciclub_Precio::franja_de();
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$v = CVD_Triciclub_Db::t( 'viajes' );
		$desde = gmdate( 'Y-m-d H:i:s', time() - self::VIVO );
		$ocupado = gmdate( 'Y-m-d H:i:s', time() - self::ESPERA );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.* FROM {$t} c WHERE c.estado='aprobado' AND c.disponible=1 AND c.ubicado_at>%s AND c.lat IS NOT NULL
			AND NOT EXISTS (SELECT 1 FROM {$v} x WHERE x.chofer_id=c.user_id AND (x.estado IN ('asignado','llego','recogido') OR (x.estado='esperando' AND x.created_at>%s)))
			LIMIT 200", $desde, $ocupado ) );
		$radio = absint( get_option( 'cvd_tc_radio_m', self::RADIO_M ) ) ?: self::RADIO_M;
		// 3.14.123 (prueba del 4-nov): el chofer que dijo «No puedo» (o no contestó) a este cliente no vuelve a salirle en 15 min.
		$yo = self::cliente( $request );
		$no = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT chofer_id FROM {$v} WHERE cliente_id=%d AND estado='rechazado' AND updated_at>%s", (int) $yo->id, gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) ) );
		$testers = 'prueba' === CVD_Triciclub::mode() ? CVD_Triciclub::testers() : null;
		$lista = array();
		foreach ( $rows as $ch ) {
			if ( null !== $testers && ! in_array( (int) $ch->user_id, $testers, true ) ) { continue; }
			if ( in_array( (int) $ch->user_id, $no, true ) ) { continue; }
			if ( ! in_array( $servicio, explode( ',', (string) $ch->servicios ), true ) ) { continue; }
			$lejos = CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, $olat, $olng );
			if ( $lejos > $radio ) { continue; }
			$perfil = CVD_Triciclub_Precio::perfil( $ch );
			$extra = (int) ( $perfil['extras'][ $franja['id'] ] ?? 0 );
			$precio = CVD_Triciclub_Precio::calcular( $perfil, (int) $tray['metros'], $franja['id'] );
			$lista[] = array_merge( self::chofer_publico( $ch ), array(
				'chofer'  => (int) $ch->user_id,
				'lejos_m' => $lejos,
				'llega'   => CVD_Triciclub_Precio::minutos_triciclo( (int) round( $lejos * 1.3 ) ),
				'precio'  => $precio,
				'extra'   => $extra,
				'firma'   => CVD_Triciclub_Precio::firmar( array(
					'chofer' => (int) $ch->user_id, 'servicio' => $servicio, 'olat' => $olat, 'olng' => $olng, 'dlat' => $dlat, 'dlng' => $dlng,
					'metros' => (int) $tray['metros'], 'minutos' => (int) $tray['minutos'], 'precio' => $precio, 'franja' => $franja['id'], 'extra' => $extra,
				) ),
			) );
		}
		usort( $lista, static fn( array $a, array $b ): int => array( $a['precio'], $a['lejos_m'] ) <=> array( $b['precio'], $b['lejos_m'] ) );
		return self::out( array(
			'ok'       => true,
			'servicio' => $servicio,
			'km'       => round( $tray['metros'] / 1000, 1 ),
			'minutos'  => (int) $tray['minutos'],
			'como'     => $tray['como'],
			'linea'    => $tray['linea'] ?? null, // 3.14.121: el camino por las calles, para pintarlo ya
			'franja'   => self::franja_publica(),
			'vence'    => time() + CVD_Triciclub_Precio::FIRMA_TTL,
			'triciclos' => array_slice( $lista, 0, 10 ),
		) );
	}

	/** Lo que el cliente ve de un chofer (nunca su teléfono antes de aceptar, nunca su LOT entera). */
	private static function chofer_publico( object $ch ): array {
		$u = get_userdata( (int) $ch->user_id );
		$limpio = trim( (string) preg_replace( '/\([^)]*\)/u', '', $u ? (string) $u->display_name : '' ) ); // «Ernesto (prueba)» → «Ernesto»
		$partes = preg_split( '/\s+/', '' !== $limpio ? $limpio : 'Chofer' );
		$nombre = $partes[0] . ( isset( $partes[1] ) && preg_match( '/^\p{L}/u', $partes[1] ) ? ' ' . mb_substr( $partes[1], 0, 1 ) . '.' : '' );
		$hoy = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) ) )->format( 'Y-m-d' );
		return array(
			'nombre'      => $nombre,
			'numero'      => (string) $ch->numero,
			'descripcion' => (string) $ch->descripcion,
			'plazas'      => (int) $ch->plazas,
			'nota'        => null !== $ch->nota_media ? round( (float) $ch->nota_media, 1 ) : null,
			'viajes'      => (int) $ch->viajes_total,
			'lot_ok'      => '' !== (string) $ch->lot_numero && null !== $ch->lot_vence && (string) $ch->lot_vence >= $hoy,
			'tipo'        => CVD_Triciclub_Alta::tipo_de( $ch ), // 3.14.130: el cliente elige el tipo
			'foto'        => CVD_Triciclub_Alta::foto_url( $ch, 'perfil', true ),
		);
	}

	public static function crear_viaje( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$cid = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_param( 'cid' ) ), 0, 40 );
		if ( '' === $cid ) { return self::fail( 'cid', 'Falta el identificador del pedido.' ); }
		$v = CVD_Triciclub_Db::t( 'viajes' );
		$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v} WHERE cliente_id=%d AND cid=%s", (int) $c->id, $cid ) );
		if ( $ya ) { return self::out( array( 'ok' => true, 'repetido' => true, 'viaje' => self::vista_cliente( self::caducar( $ya ) ) ) ); }
		$q = CVD_Triciclub_Precio::verificar( (string) $request->get_param( 'firma' ) );
		if ( ! $q ) { return self::fail( 'precio_vencido', 'El precio caducó. Mira otra vez los triciclos libres.', 409 ); }
		if ( self::activo_cliente( (int) $c->id ) ) { return self::fail( 'ya_tienes', 'Ya tienes un viaje en marcha.', 409 ); }
		$chofer = CVD_Triciclub::chofer_de( (int) $q['chofer'] );
		$u = get_userdata( (int) $q['chofer'] );
		if ( ! $chofer || 'aprobado' !== $chofer->estado || ! (int) $chofer->disponible || ! $u || '' !== CVD_Triciclub::chofer_bloqueado( $u ) || self::ocupado( (int) $q['chofer'] ) ) {
			return self::fail( 'ocupado', 'Ese triciclo ya no está libre. Elige otro.', 409 );
		}
		$pago = 'transferencia' === sanitize_key( (string) $request->get_param( 'forma_pago' ) ) ? 'transferencia' : 'efectivo';
		$now = CVD_Triciclub_Db::now();
		$row = array(
			'uuid'            => wp_generate_uuid4(),
			'cliente_id'      => (int) $c->id,
			'cid'             => $cid,
			'chofer_id'       => (int) $q['chofer'],
			'servicio'        => in_array( $q['servicio'], self::SERVICIOS, true ) ? $q['servicio'] : 'viaje',
			'estado'          => 'esperando',
			'origen_texto'    => self::txt( $request->get_param( 'origen_texto' ), 160 ),
			'origen_lat'      => $q['olat'],
			'origen_lng'      => $q['olng'],
			'destino_texto'   => self::txt( $request->get_param( 'destino_texto' ), 160 ),
			'destino_lat'     => $q['dlat'],
			'destino_lng'     => $q['dlng'],
			'distancia_m'     => (int) $q['metros'],
			'minutos'         => (int) $q['minutos'],
			'precio'          => (int) $q['precio'],
			'franja'          => (string) $q['franja'],
			'extra_pct'       => (int) $q['extra'],
			'forma_pago'      => $pago,
			'codigo'          => str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ),
			'nota'            => self::txt( $request->get_param( 'nota' ), 200 ),
			'recibe_nombre'   => 'paquete' === $q['servicio'] ? self::txt( $request->get_param( 'recibe_nombre' ), 80 ) : '',
			'recibe_telefono' => 'paquete' === $q['servicio'] ? CVD_Triciclub::telefono( $request->get_param( 'recibe_telefono' ) ) : '',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		if ( ! $wpdb->insert( $v, $row ) ) {
			$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v} WHERE cliente_id=%d AND cid=%s", (int) $c->id, $cid ) );
			return $ya ? self::out( array( 'ok' => true, 'repetido' => true, 'viaje' => self::vista_cliente( $ya ) ) ) : self::fail( 'error', 'No se pudo pedir. Prueba otra vez.', 500 );
		}
		$viaje = self::viaje_por_uuid( $row['uuid'] );
		CVD_Triciclub_Db::evento( (int) $viaje->id, 'cliente', (int) $c->id, 'pedido', array( 'precio' => $row['precio'], 'chofer' => $row['chofer_id'] ) );
		self::avisar_chofer( $viaje );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $viaje ) ) );
	}

	/**
	 * Aviso al teléfono del chofer: «te piden un viaje». 3.14.124: por las dos vías de Pa'llá (web push y nuestro ntfy, sin
	 * Google), para que le llegue con la pantalla apagada o la app cerrada. Nunca para el pedido si falla.
	 */
	private static function avisar_chofer( object $viaje ): void {
		$txt = sprintf( '%s → %s · %s CUP. Tienes %d s para aceptar.', self::corto( (string) $viaje->origen_texto ) ?: 'Recogida', self::corto( (string) $viaje->destino_texto ) ?: 'destino', number_format( (int) $viaje->precio, 0, ',', '.' ), self::ESPERA );
		self::aviso( (int) $viaje->chofer_id, 'tc_pedido', '🛺 Triciclub: te piden un ' . self::servicio_txt( (string) $viaje->servicio ), $txt, array( 'tc', 'pedido' ) );
	}

	private static function corto( string $t ): string { return trim( explode( ',', $t )[0] ); }

	private static function aviso( int $user_id, string $type, string $title, string $txt, array $tags ): void {
		try {
			if ( class_exists( 'CVD_Palla_Push' ) && method_exists( 'CVD_Palla_Push', 'avisar' ) ) { CVD_Palla_Push::avisar( $user_id, $type, $title, $txt, home_url( '/palla/triciclub' ), $tags ); }
			elseif ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::notify_palla( $user_id, 0, $type, $title, $txt, home_url( '/palla/triciclub' ) ); }
		} catch ( Throwable $e ) { unset( $e ); }
	}

	private static function servicio_txt( string $s ): string { return array( 'viaje' => 'viaje', 'paquete' => 'paquete', 'carga' => 'viaje de carga' )[ $s ] ?? 'viaje'; }

	private static function ocupado( int $user_id ): bool {
		global $wpdb;
		$v = CVD_Triciclub_Db::t( 'viajes' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$v} WHERE chofer_id=%d AND (estado IN ('asignado','llego','recogido') OR (estado='esperando' AND created_at>%s)) LIMIT 1", $user_id, gmdate( 'Y-m-d H:i:s', time() - self::ESPERA ) ) );
	}

	private static function viaje_por_uuid( string $uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE uuid=%s', $uuid ) );
		return $row ? self::caducar( $row ) : null;
	}

	private static function viaje( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE id=%d', $id ) );
		return $row ? self::caducar( $row ) : null;
	}

	/** Un pedido «esperando» de más de 90 s pasa a «rechazado» (el chofer no contestó). */
	private static function caducar( object $viaje ): object {
		if ( 'esperando' !== $viaje->estado || strtotime( $viaje->created_at . ' UTC' ) > time() - self::ESPERA ) { return $viaje; }
		global $wpdb;
		$now = CVD_Triciclub_Db::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'viajes' ) . " SET estado='rechazado', motivo='no contestó', respondido_at=%s, updated_at=%s WHERE id=%d AND estado='esperando'", $now, $now, (int) $viaje->id ) );
		if ( $n ) { CVD_Triciclub_Db::evento( (int) $viaje->id, 'sistema', 0, 'no_contesto' ); }
		$viaje->estado = 'rechazado';
		$viaje->motivo = 'no contestó';
		return $viaje;
	}

	private static function activo_cliente( int $cliente_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . " WHERE cliente_id=%d AND estado IN ('esperando','asignado','llego','recogido') ORDER BY id DESC LIMIT 1", $cliente_id ) );
		if ( ! $row ) { return null; }
		$row = self::caducar( $row );
		return in_array( $row->estado, array( 'esperando', 'asignado', 'llego', 'recogido' ), true ) ? $row : null;
	}

	private static function posicion( object $viaje ): ?array {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT lat, lng, created_at FROM ' . CVD_Triciclub_Db::t( 'ubicaciones' ) . ' WHERE viaje_id=%d ORDER BY id DESC LIMIT 1', (int) $viaje->id ) );
		if ( ! $p ) {
			$ch = CVD_Triciclub::chofer_de( (int) $viaje->chofer_id );
			if ( ! $ch || null === $ch->lat || ! $ch->ubicado_at ) { return null; }
			$p = (object) array( 'lat' => $ch->lat, 'lng' => $ch->lng, 'created_at' => $ch->ubicado_at );
		}
		return array( 'lat' => (float) $p->lat, 'lon' => (float) $p->lng, 'ago' => max( 0, time() - (int) strtotime( $p->created_at . ' UTC' ) ) );
	}

	/** El viaje como lo ve el cliente. */
	private static function vista_cliente( object $viaje ): array {
		$activo = in_array( $viaje->estado, self::ACTIVOS, true );
		$ch = CVD_Triciclub::chofer_de( (int) $viaje->chofer_id );
		$chofer = $ch ? self::chofer_publico( $ch ) : null;
		if ( $chofer && $activo ) {
			$tel = preg_replace( '/\D+/', '', (string) ( get_user_meta( (int) $viaje->chofer_id, '_cvd_whatsapp', true ) ?: get_user_meta( (int) $viaje->chofer_id, 'billing_phone', true ) ) );
			$chofer['telefono'] = 8 === strlen( $tel ) ? '53' . $tel : $tel;
			if ( 'transferencia' === $viaje->forma_pago ) { $chofer['transferencia'] = (string) $ch->transferencia; }
		}
		return array(
			'uuid'          => (string) $viaje->uuid,
			'estado'        => (string) $viaje->estado,
			'servicio'      => (string) $viaje->servicio,
			'origen'        => array( 'texto' => (string) $viaje->origen_texto, 'lat' => (float) $viaje->origen_lat, 'lng' => (float) $viaje->origen_lng ),
			'destino'       => array( 'texto' => (string) $viaje->destino_texto, 'lat' => (float) $viaje->destino_lat, 'lng' => (float) $viaje->destino_lng ),
			'km'            => round( (int) $viaje->distancia_m / 1000, 1 ),
			'minutos'       => (int) $viaje->minutos,
			'precio'        => (int) $viaje->precio,
			'moneda'        => (string) $viaje->moneda,
			'forma_pago'    => (string) $viaje->forma_pago,
			'codigo'        => $activo || 'esperando' === $viaje->estado ? (string) $viaje->codigo : '',
			'chofer'        => $chofer,
			'pos'           => $activo ? self::posicion( $viaje ) : null,
			// 3.14.121: el camino por las calles (1 h en caché: no vuelve a pedirlo al VPS cada 8 s).
			'linea'         => $activo || 'esperando' === $viaje->estado ? CVD_Triciclub_Precio::linea_guardada( (float) $viaje->origen_lat, (float) $viaje->origen_lng, (float) $viaje->destino_lat, (float) $viaje->destino_lng ) : null,
			'motivo'        => (string) $viaje->motivo,
			'cancelado_por' => (string) $viaje->cancelado_por, // 3.14.123: si fue el chofer, el cliente elige otro
			'cobrado'       => null !== $viaje->cobrado_at,
			'creado'        => gmdate( 'c', (int) strtotime( $viaje->created_at . ' UTC' ) ),
			'espera_hasta'  => 'esperando' === $viaje->estado ? gmdate( 'c', (int) strtotime( $viaje->created_at . ' UTC' ) + self::ESPERA ) : null,
			'valorado'      => self::valorado( (int) $viaje->id, 'cliente' ),
		);
	}

	public static function ver_viaje( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$viaje = self::viaje_por_uuid( (string) $request['uuid'] );
		if ( ! $viaje || (int) $viaje->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $viaje ) ) );
	}

	public static function mis_viajes( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE cliente_id=%d ORDER BY id DESC LIMIT 30', (int) $c->id ) );
		return self::out( array( 'ok' => true, 'viajes' => array_map( static fn( object $v ): array => self::vista_cliente( self::caducar( $v ) ), $rows ) ) );
	}

	public static function cancelar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::cliente( $request );
		$viaje = self::viaje_por_uuid( (string) $request['uuid'] );
		if ( ! $viaje || (int) $viaje->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		if ( 'cancelado' === $viaje->estado ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( $viaje ) ) ); }
		if ( ! in_array( $viaje->estado, array( 'esperando', 'asignado', 'llego' ), true ) ) { return self::fail( 'tarde', 'Ya no se puede cancelar: el viaje empezó.', 409 ); }
		$now = CVD_Triciclub_Db::now();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'viajes' ) . " SET estado='cancelado', cancelado_at=%s, cancelado_por='cliente', motivo=%s, updated_at=%s WHERE id=%d AND estado=%s", $now, self::txt( $request->get_param( 'motivo' ), 200 ), $now, (int) $viaje->id, $viaje->estado ) );
		CVD_Triciclub_Db::evento( (int) $viaje->id, 'cliente', (int) $c->id, 'cancelado', array( 'antes' => $viaje->estado ) );
		if ( 'esperando' !== $viaje->estado ) { self::aviso( (int) $viaje->chofer_id, 'tc_cancelado', '🛺 Triciclub: el cliente canceló', 'Ya no hace falta que vayas a ' . self::corto( (string) $viaje->origen_texto ) . '.', array( 'tc', 'cancelado' ) ); } // 3.14.124
		return self::out( array( 'ok' => true, 'viaje' => self::vista_cliente( self::viaje( (int) $viaje->id ) ) ) );
	}

	public static function valorar_cliente( WP_REST_Request $request ): WP_REST_Response {
		$c = self::cliente( $request );
		$viaje = self::viaje_por_uuid( (string) $request['uuid'] );
		if ( ! $viaje || (int) $viaje->cliente_id !== (int) $c->id ) { return self::fail( 'no_existe', 'No encontramos ese viaje.', 404 ); }
		return self::valorar( $viaje, 'cliente', $request );
	}

	// ================================================================== Chofer

	public static function chofer_hoy( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = CVD_Triciclub::chofer_de( $u->ID );
		$v = CVD_Triciclub_Db::t( 'viajes' );
		$pedidos = array();
		$activo = null;
		$hoy = array( 'viajes' => 0, 'cup' => 0 );
		if ( $ch ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$v} WHERE chofer_id=%d AND estado IN ('esperando','asignado','llego','recogido') ORDER BY id ASC", $u->ID ) );
			foreach ( $rows as $r ) {
				$r = self::caducar( $r );
				if ( 'esperando' === $r->estado ) { $pedidos[] = self::vista_chofer( $r ); }
				elseif ( in_array( $r->estado, self::ACTIVOS, true ) ) { $activo = self::vista_chofer( $r ); }
			}
			$inicio = ( new DateTimeImmutable( 'today', new DateTimeZone( 'America/Havana' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$t = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(precio),0) cup FROM {$v} WHERE chofer_id=%d AND estado='terminado' AND terminado_at>=%s", $u->ID, $inicio ) );
			$hoy = array( 'viajes' => (int) $t->n, 'cup' => (int) $t->cup );
		}
		$perfil = CVD_Triciclub_Precio::perfil( $ch );
		$franja = CVD_Triciclub_Precio::franja_de();
		$ejemplos = array();
		foreach ( array( 2, 5, 8 ) as $km ) { $ejemplos[] = array( 'km' => $km, 'precio' => CVD_Triciclub_Precio::calcular( $perfil, $km * 1000, $franja['id'] ) ); }
		return self::out( array(
			'ok'       => true,
			'now'      => gmdate( 'c' ),
			'yo'       => array( 'nombre' => (string) $u->display_name ),
			'ficha'    => $ch ? self::ficha( $ch, $u ) : null,
			'perfil'   => $perfil,
			'franjas'  => CVD_Triciclub_Precio::franjas(),
			'franja'   => self::franja_publica(),
			'ejemplos' => $ejemplos,
			'pedidos'  => $pedidos,
			'activo'   => $activo,
			'hoy'      => $hoy,
			'espera'   => self::ESPERA,
		) );
	}

	private static function ficha( object $ch, ?WP_User $u = null ): array {
		return array(
			'estado'        => (string) $ch->estado,
			// 3.14.130: alta de choferes (se aprueba sola con todo puesto)
			'tipo'          => isset( CVD_Triciclub_Alta::TIPOS[ (string) $ch->tipo ] ) ? (string) $ch->tipo : '',
			'motor'         => (string) $ch->motor,
			'carga_kg'      => (int) $ch->carga_kg,
			'fotos'         => array_combine( CVD_Triciclub_Alta::FOTOS, array_map( static fn( string $c ): string => CVD_Triciclub_Alta::foto_url( $ch, $c, true ), CVD_Triciclub_Alta::FOTOS ) ),
			'nombre'        => $u ? (string) $u->display_name : '',
			'propio'        => $u ? CVD_Triciclub_Alta::es_propio( $u ) : false,
			'faltan'        => $u ? CVD_Triciclub_Alta::faltan( $ch, $u ) : array(),
			'enviado'       => null !== $ch->enviado_at,
			'motivo'        => (string) $ch->motivo,
			'numero'        => (string) $ch->numero,
			'descripcion'   => (string) $ch->descripcion,
			'plazas'        => (int) $ch->plazas,
			'servicios'     => array_values( array_intersect( self::SERVICIOS, explode( ',', (string) $ch->servicios ) ) ),
			'lot_numero'    => (string) $ch->lot_numero,
			'lot_vence'     => (string) $ch->lot_vence,
			'licencia_cat'  => (string) $ch->licencia_cat,
			'transferencia' => (string) $ch->transferencia,
			'disponible'    => (bool) (int) $ch->disponible,
			'nota'          => null !== $ch->nota_media ? round( (float) $ch->nota_media, 1 ) : null,
			'viajes'        => (int) $ch->viajes_total,
		);
	}

	/** El viaje como lo ve el chofer: nombre y teléfono del cliente solo después de aceptar. */
	private static function vista_chofer( object $viaje ): array {
		$activo = in_array( $viaje->estado, self::ACTIVOS, true );
		$cli = $activo ? CVD_Triciclub::cliente( (int) $viaje->cliente_id ) : null;
		return array(
			'id'              => (int) $viaje->id,
			'estado'          => (string) $viaje->estado,
			'servicio'        => (string) $viaje->servicio,
			'origen'          => array( 'texto' => (string) $viaje->origen_texto, 'lat' => (float) $viaje->origen_lat, 'lng' => (float) $viaje->origen_lng ),
			'destino'         => array( 'texto' => (string) $viaje->destino_texto, 'lat' => (float) $viaje->destino_lat, 'lng' => (float) $viaje->destino_lng ),
			'km'              => round( (int) $viaje->distancia_m / 1000, 1 ),
			'minutos'         => (int) $viaje->minutos,
			'precio'          => (int) $viaje->precio,
			'extra'           => (int) $viaje->extra_pct,
			'forma_pago'      => (string) $viaje->forma_pago,
			'nota'            => (string) $viaje->nota,
			'cliente'         => $cli ? array( 'nombre' => (string) $cli->nombre, 'telefono' => (string) $cli->telefono, 'nota' => null !== $cli->nota_media ? round( (float) $cli->nota_media, 1 ) : null ) : null,
			'recibe'          => $activo && '' !== (string) $viaje->recibe_nombre ? array( 'nombre' => (string) $viaje->recibe_nombre, 'telefono' => (string) $viaje->recibe_telefono ) : null,
			'cobrado'         => null !== $viaje->cobrado_at,
			'espera_hasta'    => 'esperando' === $viaje->estado ? gmdate( 'c', (int) strtotime( $viaje->created_at . ' UTC' ) + self::ESPERA ) : null,
			'linea'           => CVD_Triciclub_Precio::linea_guardada( (float) $viaje->origen_lat, (float) $viaje->origen_lng, (float) $viaje->destino_lat, (float) $viaje->destino_lng ), // 3.14.122: la ruta en el mapa del chofer
		);
	}

	public static function chofer_perfil( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$now = CVD_Triciclub_Db::now();
		$ch = CVD_Triciclub::chofer_de( $u->ID );
		if ( ! $ch ) {
			$wpdb->insert( $t, array( 'user_id' => $u->ID, 'created_at' => $now, 'updated_at' => $now ) );
			$ch = CVD_Triciclub::chofer_de( $u->ID );
		}
		$p = (array) $request->get_json_params();
		$set = array( 'updated_at' => $now );
		$int = static fn( $v, int $min, int $max ): int => max( $min, min( $max, (int) $v ) );
		if ( isset( $p['precio_km'] ) && is_numeric( $p['precio_km'] ) ) { $set['precio_km'] = $int( $p['precio_km'], 50, 3000 ); }
		if ( isset( $p['km_tramo'] ) && is_numeric( str_replace( ',', '.', (string) $p['km_tramo'] ) ) ) { $set['km_tramo'] = max( 1, min( 50, round( (float) str_replace( ',', '.', (string) $p['km_tramo'] ), 1 ) ) ); } // 3.14.123: «abc» no se guarda
		if ( isset( $p['precio_km_despues'] ) && is_numeric( $p['precio_km_despues'] ) ) { $set['precio_km_despues'] = $int( $p['precio_km_despues'], 50, 3000 ); }
		if ( isset( $p['extras'] ) && is_array( $p['extras'] ) ) {
			$extras = array();
			foreach ( CVD_Triciclub_Precio::franjas() as $f ) { $extras[ $f['id'] ] = CVD_Triciclub_Precio::extra( $p['extras'][ $f['id'] ] ?? 0 ); }
			$set['extras'] = (string) wp_json_encode( $extras );
		}
		if ( isset( $p['servicios'] ) && is_array( $p['servicios'] ) ) {
			$s = array_values( array_intersect( self::SERVICIOS, array_map( 'sanitize_key', $p['servicios'] ) ) );
			$set['servicios'] = implode( ',', $s ?: array( 'viaje' ) );
		}
		foreach ( array( 'numero' => 12, 'descripcion' => 80, 'lot_numero' => 40, 'licencia_cat' => 8, 'transferencia' => 40, 'chapa_o_chasis' => 40 ) as $k => $max ) {
			if ( isset( $p[ $k ] ) ) { $set[ $k ] = self::txt( $p[ $k ], $max ); }
		}
		if ( isset( $p['plazas'] ) ) { $set['plazas'] = $int( $p['plazas'], 0, 12 ); }
		if ( isset( $p['tipo'] ) && isset( CVD_Triciclub_Alta::TIPOS[ (string) $p['tipo'] ] ) ) { $set['tipo'] = (string) $p['tipo']; } // 3.14.130
		if ( isset( $p['motor'] ) && isset( CVD_Triciclub_Alta::MOTORES[ (string) $p['motor'] ] ) ) { $set['motor'] = (string) $p['motor']; }
		if ( isset( $p['carga_kg'] ) ) { $set['carga_kg'] = $int( $p['carga_kg'], 0, 2000 ); }
		if ( isset( $p['nombre'] ) && CVD_Triciclub_Alta::es_propio( $u ) ) {
			$nombre = self::txt( $p['nombre'], 60 );
			if ( mb_strlen( $nombre ) >= 2 ) { wp_update_user( array( 'ID' => $u->ID, 'display_name' => $nombre ) ); $u = get_userdata( $u->ID ); }
		}
		if ( isset( $p['lot_vence'] ) ) { $d = (string) $p['lot_vence']; $set['lot_vence'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null; }
		$wpdb->update( $t, $set, array( 'user_id' => $u->ID ) );
		$ch = CVD_Triciclub::chofer_de( $u->ID );
		return self::out( array( 'ok' => true, 'ficha' => self::ficha( $ch, $u ), 'perfil' => CVD_Triciclub_Precio::perfil( $ch ) ) );
	}

	public static function chofer_disponible( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = CVD_Triciclub::chofer_de( $u->ID );
		if ( ! $ch || 'aprobado' !== $ch->estado ) { return self::fail( 'no_aprobado', 'Triciclub todavía no aprobó tu triciclo. Te avisamos.', 403 ); }
		$on = rest_sanitize_boolean( $request->get_param( 'on' ) );
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( $on && ( null === $lat || null === $lng ) ) { return self::fail( 'gps', 'Activa la ubicación del teléfono para salir en la lista.' ); }
		$set = array( 'disponible' => $on ? 1 : 0, 'updated_at' => CVD_Triciclub_Db::now() );
		if ( null !== $lat && null !== $lng ) { $set += array( 'lat' => $lat, 'lng' => $lng, 'ubicado_at' => CVD_Triciclub_Db::now() ); }
		$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), $set, array( 'user_id' => $u->ID ) );
		return self::out( array( 'ok' => true, 'disponible' => $on ) );
	}

	private static function viaje_mio( WP_REST_Request $request, WP_User $u ): ?object {
		$viaje = self::viaje( absint( $request['id'] ) );
		return $viaje && (int) $viaje->chofer_id === $u->ID ? $viaje : null;
	}

	/** POST chofer/viajes/{id}/responder {acepto} — dentro de los 90 s. */
	public static function responder( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$viaje = self::viaje_mio( $request, $u );
		if ( ! $viaje ) { return self::fail( 'no_existe', 'Ese viaje no es tuyo.', 404 ); }
		$acepto = rest_sanitize_boolean( $request->get_param( 'acepto' ) );
		if ( 'esperando' !== $viaje->estado ) {
			$same = ( $acepto && in_array( $viaje->estado, self::ACTIVOS, true ) ) || ( ! $acepto && 'rechazado' === $viaje->estado );
			return $same ? self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( $viaje ) ) ) : self::fail( 'tarde', 'rechazado' === $viaje->estado ? 'Se acabó el tiempo: el cliente ya puede elegir otro triciclo.' : 'El cliente canceló.', 409 );
		}
		$now = CVD_Triciclub_Db::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'viajes' ) . ' SET estado=%s, respondido_at=%s, motivo=%s, updated_at=%s WHERE id=%d AND chofer_id=%d AND estado=%s', $acepto ? 'asignado' : 'rechazado', $now, $acepto ? '' : 'el chofer no puede', $now, (int) $viaje->id, $u->ID, 'esperando' ) );
		if ( ! $n ) { return self::fail( 'tarde', 'Ese pedido ya cambió. Mira otra vez.', 409 ); }
		CVD_Triciclub_Db::evento( (int) $viaje->id, 'chofer', $u->ID, $acepto ? 'aceptado' : 'rechazado' );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( self::viaje( (int) $viaje->id ) ) ) );
	}

	/** POST chofer/viajes/{id}/estado {accion: llegue|recogi|termine|cobrado|suelto, codigo, motivo} */
	public static function estado( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$viaje = self::viaje_mio( $request, $u );
		if ( ! $viaje ) { return self::fail( 'no_existe', 'Ese viaje no es tuyo.', 404 ); }
		$accion = sanitize_key( (string) $request->get_param( 'accion' ) );
		$now = CVD_Triciclub_Db::now();
		$pasos = array(
			'llegue'  => array( array( 'asignado' ), 'llego', 'llego_at' ),
			'recogi'  => array( array( 'asignado', 'llego' ), 'recogido', 'recogido_at' ),
			'termine' => array( array( 'recogido' ), 'terminado', 'terminado_at' ),
			'suelto'  => array( array( 'asignado', 'llego' ), 'cancelado', 'cancelado_at' ),
		);
		$t = CVD_Triciclub_Db::t( 'viajes' );
		if ( 'cobrado' === $accion ) {
			if ( ! in_array( $viaje->estado, array( 'recogido', 'terminado' ), true ) ) { return self::fail( 'tarde', 'Cobra al terminar el viaje.', 409 ); }
			if ( null === $viaje->cobrado_at ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET cobrado_at=%s, updated_at=%s WHERE id=%d AND cobrado_at IS NULL", $now, $now, (int) $viaje->id ) );
				CVD_Triciclub_Db::evento( (int) $viaje->id, 'chofer', $u->ID, 'cobrado', array( 'precio' => (int) $viaje->precio, 'forma' => $viaje->forma_pago ) );
			}
			return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( self::viaje( (int) $viaje->id ) ) ) );
		}
		if ( ! isset( $pasos[ $accion ] ) ) { return self::fail( 'accion', 'Botón desconocido.' ); }
		list( $desde, $hacia, $col ) = $pasos[ $accion ];
		if ( $viaje->estado === $hacia ) { return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( $viaje ) ) ); } // repetido
		if ( ! in_array( $viaje->estado, $desde, true ) ) { return self::fail( 'tarde', 'cancelado' === $viaje->estado ? 'El cliente canceló el viaje.' : 'Ese paso ya no toca.', 409 ); }
		if ( 'recogi' === $accion && ! hash_equals( (string) $viaje->codigo, preg_replace( '/\D+/', '', (string) $request->get_param( 'codigo' ) ) ) ) {
			return self::fail( 'codigo', 'Ese no es el código. Pídeselo al cliente: lo tiene en su app.' );
		}
		$set = array( 'estado' => $hacia, $col => $now, 'updated_at' => $now );
		if ( 'suelto' === $accion ) { $set += array( 'cancelado_por' => 'chofer', 'motivo' => self::txt( $request->get_param( 'motivo' ), 200 ) ); }
		$n = $wpdb->update( $t, $set, array( 'id' => (int) $viaje->id, 'estado' => $viaje->estado ) ); // solo si sigue en el paso de antes
		if ( ! $n ) { return self::fail( 'tarde', 'El viaje cambió. Mira otra vez.', 409 ); }
		CVD_Triciclub_Db::evento( (int) $viaje->id, 'chofer', $u->ID, $accion );
		if ( 'termine' === $accion ) { self::contar( $viaje ); }
		return self::out( array( 'ok' => true, 'viaje' => self::vista_chofer( self::viaje( (int) $viaje->id ) ) ) );
	}

	/** Suma el viaje terminado al chofer y al cliente. */
	private static function contar( object $viaje ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'choferes' ) . ' SET viajes_total=viajes_total+1 WHERE user_id=%d', (int) $viaje->chofer_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CVD_Triciclub_Db::t( 'clientes' ) . ' SET viajes_total=viajes_total+1 WHERE id=%d', (int) $viaje->cliente_id ) );
	}

	/** POST chofer/viajes/{id}/ubicacion {lat, lng, precision} — mientras el viaje está activo (cada 15 s). */
	public static function ubicacion( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$viaje = self::viaje_mio( $request, $u );
		if ( ! $viaje || ! in_array( $viaje->estado, self::ACTIVOS, true ) ) { return self::out( array( 'ok' => true, 'parar' => true ) ); }
		$lat = self::coord( $request->get_param( 'lat' ), true );
		$lng = self::coord( $request->get_param( 'lng' ), false );
		if ( null === $lat || null === $lng ) { return self::fail( 'gps', 'Ubicación no válida.' ); }
		$now = CVD_Triciclub_Db::now();
		$prec = $request->get_param( 'precision' );
		$wpdb->insert( CVD_Triciclub_Db::t( 'ubicaciones' ), array( 'viaje_id' => (int) $viaje->id, 'chofer_id' => $u->ID, 'lat' => $lat, 'lng' => $lng, 'precision_m' => is_numeric( $prec ) ? min( 65535, max( 0, (int) $prec ) ) : null, 'created_at' => $now ) );
		$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'lat' => $lat, 'lng' => $lng, 'ubicado_at' => $now ), array( 'user_id' => $u->ID ) );
		return self::out( array( 'ok' => true ) );
	}

	public static function valorar_chofer( WP_REST_Request $request ): WP_REST_Response {
		$u = self::user( $request );
		$viaje = self::viaje_mio( $request, $u );
		if ( ! $viaje ) { return self::fail( 'no_existe', 'Ese viaje no es tuyo.', 404 ); }
		return self::valorar( $viaje, 'chofer', $request );
	}

	// ================================================================== Valoraciones

	private static function valorado( int $viaje_id, string $de ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . CVD_Triciclub_Db::t( 'valoraciones' ) . ' WHERE viaje_id=%d AND de=%s', $viaje_id, $de ) );
	}

	/** Una valoración por lado y viaje (repetir no suma dos). Recalcula la nota media del otro. */
	private static function valorar( object $viaje, string $de, WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		if ( 'terminado' !== $viaje->estado ) { return self::fail( 'tarde', 'Se valora al terminar el viaje.', 409 ); }
		$estrellas = max( 1, min( 5, (int) $request->get_param( 'estrellas' ) ) );
		$etq = $request->get_param( 'etiquetas' );
		$etiquetas = is_array( $etq ) ? implode( ',', array_slice( array_map( 'sanitize_key', $etq ), 0, 8 ) ) : '';
		$t = CVD_Triciclub_Db::t( 'valoraciones' );
		if ( ! self::valorado( (int) $viaje->id, $de ) ) {
			$wpdb->insert( $t, array( 'viaje_id' => (int) $viaje->id, 'de' => $de, 'estrellas' => $estrellas, 'etiquetas' => mb_substr( $etiquetas, 0, 200 ), 'texto' => self::txt( $request->get_param( 'texto' ), 300 ), 'created_at' => CVD_Triciclub_Db::now() ) );
			$v = CVD_Triciclub_Db::t( 'viajes' );
			if ( 'cliente' === $de ) {
				$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.estrellas) FROM {$t} r JOIN {$v} x ON x.id=r.viaje_id WHERE r.de='cliente' AND x.chofer_id=%d", (int) $viaje->chofer_id ) );
				$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'nota_media' => null !== $avg ? round( (float) $avg, 2 ) : null ), array( 'user_id' => (int) $viaje->chofer_id ) );
			} else {
				$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(r.estrellas) FROM {$t} r JOIN {$v} x ON x.id=r.viaje_id WHERE r.de='chofer' AND x.cliente_id=%d", (int) $viaje->cliente_id ) );
				$wpdb->update( CVD_Triciclub_Db::t( 'clientes' ), array( 'nota_media' => null !== $avg ? round( (float) $avg, 2 ) : null ), array( 'id' => (int) $viaje->cliente_id ) );
			}
		}
		return self::out( array( 'ok' => true ) );
	}
}
