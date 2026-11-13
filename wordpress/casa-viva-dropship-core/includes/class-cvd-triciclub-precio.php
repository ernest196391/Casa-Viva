<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.117 (Ernesto 3-nov, D130): Triciclub, el precio. **Lo pone cada chofer**, no Triciclub.
 *
 * - Perfil del chofer: precio por km (300 CUP por defecto) los primeros `km_tramo` km (5), y otro precio por km después (150).
 * - Franjas horarias (las mismas para todos, opción cvd_tc_franjas, en hora de Cuba): el chofer pone un % extra en cada una.
 *   Las horas y el % por defecto salen del estudio docs/nexo-business/apps/triciclub/estudio-horarios-2026-11-03.md.
 * - El cliente ve la lista de triciclos libres con el precio de cada uno para la hora de ahora y elige.
 * - Ese precio va FIRMADO (HMAC, 10 min): nadie lo cambia por el camino y es el que se cobra al llegar.
 * - Kilómetros por calles con nuestro OSRM del VPS (el de Pa'llá); si no contesta en 4 s, línea recta × 1,3.
 */
final class CVD_Triciclub_Precio {
	public const PRECIO_KM = 300;
	public const KM_TRAMO = 5.0;
	public const PRECIO_KM_DESPUES = 150;
	public const REDONDEO = 10;
	public const EXTRA_MAX = 100;   // % extra como mucho en una franja
	public const FIRMA_TTL = 600;   // 10 min
	public const FRANJAS_OPTION = 'cvd_tc_franjas';
	private const RUTA_API = 'https://179-236-242-212.sslip.io'; // OSRM del VPS (opción cvd_tc_ruta_api)

	/**
	 * Franjas por defecto (hora de Cuba, sin huecos). `extra` = % que se propone al chofer nuevo; él lo cambia.
	 * Horas con evidencia (picos de 6 a 9 y desde las 15:00, cuando los triciclos se quedan sin batería); los % son una
	 * recomendación sin dato público (estudio-horarios-2026-11-03.md). Tope: +100 %.
	 */
	public static function franjas_defecto(): array {
		return array(
			array( 'id' => 'madrugada', 'nombre' => 'Madrugada', 'desde' => '00:00', 'hasta' => '06:00', 'extra' => 50 ),
			array( 'id' => 'pico_manana', 'nombre' => 'Pico de la mañana', 'desde' => '06:00', 'hasta' => '09:00', 'extra' => 20 ),
			array( 'id' => 'dia', 'nombre' => 'Día', 'desde' => '09:00', 'hasta' => '15:00', 'extra' => 0 ),
			array( 'id' => 'pico_tarde', 'nombre' => 'Pico de la tarde', 'desde' => '15:00', 'hasta' => '19:00', 'extra' => 25 ),
			array( 'id' => 'noche', 'nombre' => 'Noche', 'desde' => '19:00', 'hasta' => '24:00', 'extra' => 30 ),
		);
	}

	/** Las franjas en uso (opción) o las de defecto si la opción falta o está mal. */
	public static function franjas(): array {
		$raw = get_option( self::FRANJAS_OPTION, '' );
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$ok = self::validar_franjas( is_array( $list ) ? $list : array() );
		return $ok ?: self::franjas_defecto();
	}

	/** Comprueba que cubren 00:00–24:00 sin huecos ni solapes; devuelve la lista limpia o array() si no vale. */
	public static function validar_franjas( array $list ): array {
		$out = array();
		foreach ( $list as $f ) {
			if ( ! is_array( $f ) ) { return array(); }
			$id = sanitize_key( (string) ( $f['id'] ?? '' ) );
			$desde = self::minutos( (string) ( $f['desde'] ?? '' ) );
			$hasta = self::minutos( (string) ( $f['hasta'] ?? '' ) );
			if ( '' === $id || null === $desde || null === $hasta || $hasta <= $desde ) { return array(); }
			$out[] = array( 'id' => $id, 'nombre' => sanitize_text_field( (string) ( $f['nombre'] ?? $id ) ), 'desde' => (string) $f['desde'], 'hasta' => (string) $f['hasta'], 'extra' => self::extra( $f['extra'] ?? 0 ), '_d' => $desde, '_h' => $hasta );
		}
		usort( $out, static fn( array $a, array $b ): int => $a['_d'] <=> $b['_d'] );
		$fin = 0;
		foreach ( $out as $f ) { if ( $f['_d'] !== $fin ) { return array(); } $fin = $f['_h']; }
		if ( 1440 !== $fin ) { return array(); }
		return array_map( static function ( array $f ): array { unset( $f['_d'], $f['_h'] ); return $f; }, $out );
	}

	/** «06:30» → 390; «24:00» → 1440; si no es una hora, null. */
	public static function minutos( string $hhmm ): ?int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) { return null; }
		$v = (int) $m[1] * 60 + (int) $m[2];
		return ( (int) $m[2] < 60 && $v <= 1440 ) ? $v : null;
	}

	public static function extra( $v ): int { return max( 0, min( self::EXTRA_MAX, (int) round( (float) $v ) ) ); }

	/** La franja de un momento (hora de Cuba). */
	public static function franja_de( ?DateTimeImmutable $cuando = null ): array {
		$t = ( $cuando ?? new DateTimeImmutable( 'now' ) )->setTimezone( new DateTimeZone( 'America/Havana' ) );
		$m = (int) $t->format( 'G' ) * 60 + (int) $t->format( 'i' );
		foreach ( self::franjas() as $f ) {
			if ( $m >= (int) self::minutos( $f['desde'] ) && $m < (int) self::minutos( $f['hasta'] ) ) { return $f; }
		}
		$all = self::franjas();
		return end( $all );
	}

	/** El perfil de precios del chofer con los valores por defecto donde falte algo. */
	public static function perfil( ?object $chofer ): array {
		$extras = array();
		$raw = $chofer && '' !== (string) $chofer->extras ? json_decode( (string) $chofer->extras, true ) : null;
		foreach ( self::franjas() as $f ) {
			$extras[ $f['id'] ] = is_array( $raw ) && array_key_exists( $f['id'], $raw ) ? self::extra( $raw[ $f['id'] ] ) : (int) $f['extra'];
		}
		return array(
			'precio_km'         => $chofer && (int) $chofer->precio_km > 0 ? (int) $chofer->precio_km : self::PRECIO_KM,
			'km_tramo'          => $chofer && (float) $chofer->km_tramo > 0 ? (float) $chofer->km_tramo : self::KM_TRAMO,
			'precio_km_despues' => $chofer && (int) $chofer->precio_km_despues > 0 ? (int) $chofer->precio_km_despues : self::PRECIO_KM_DESPUES,
			'extras'            => $extras,
		);
	}

	/**
	 * Precio de un trayecto con el perfil de un chofer en una franja.
	 * km ≤ tramo: km × precio_km. km > tramo: tramo × precio_km + (km − tramo) × precio_km_despues.
	 * Mínimo: el precio de 1 km. Después el % extra de la franja y se redondea hacia arriba a 10 CUP.
	 */
	public static function calcular( array $perfil, int $metros, string $franja_id ): int {
		$km = max( 0, $metros ) / 1000;
		$tramo = (float) $perfil['km_tramo'];
		$base = $km <= $tramo ? $km * $perfil['precio_km'] : $tramo * $perfil['precio_km'] + ( $km - $tramo ) * $perfil['precio_km_despues'];
		$base = max( (float) $perfil['precio_km'], $base );
		$extra = (int) ( $perfil['extras'][ $franja_id ] ?? 0 );
		$total = round( $base * ( 100 + $extra ) / 100, 2 ); // round: 720,0000001 no debe subir a 730
		return (int) ( ceil( $total / self::REDONDEO ) * self::REDONDEO );
	}

	// ------------------------------------------------------------------ Distancia

	public static function recta_m( float $lat1, float $lng1, float $lat2, float $lng2 ): int {
		$r = 6371000;
		$p1 = deg2rad( $lat1 ); $p2 = deg2rad( $lat2 );
		$dp = deg2rad( $lat2 - $lat1 ); $dl = deg2rad( $lng2 - $lng1 );
		$a = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return (int) round( 2 * $r * atan2( sqrt( $a ), sqrt( 1 - $a ) ) );
	}

	/** {metros, minutos, como: calles|recta}. Guarda 1 h en caché por par de puntos (~10 m). */
	public static function trayecto( float $olat, float $olng, float $dlat, float $dlng ): array {
		// 3.14.121 (Ernesto 3-nov, «ruta por calles siempre»): además de los km, el camino por las calles (`linea`,
		// [[lat, lon]…]) para que la app lo pinte sin pedirlo ella al VPS (desde el móvil a veces tardaba y salía recta).
		$key = 'cvd_tc_ruta2_' . md5( implode( ',', array_map( static fn( float $v ): string => number_format( $v, 4, '.', '' ), array( $olat, $olng, $dlat, $dlng ) ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }
		$api = rtrim( (string) get_option( 'cvd_tc_ruta_api', self::RUTA_API ), '/' );
		$url = $api . '/route/v1/driving/' . $olng . ',' . $olat . ';' . $dlng . ',' . $dlat . '?overview=full&geometries=geojson';
		// 10-nov (auditoría): un solo intento de 2 s. Esperar más ocupaba los procesos de Hostinger (y la tienda) si el
		// VPS iba lento; sin ruta, el precio sale con la recta y la línea llega en la siguiente consulta.
		for ( $intento = 0; $intento < 1; $intento++ ) {
			$res = wp_remote_get( $url, array( 'timeout' => 2, 'redirection' => 0 ) );
			$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$route = is_array( $data ) && 'Ok' === ( $data['code'] ?? '' ) ? ( $data['routes'][0] ?? null ) : null;
			if ( is_array( $route ) && (float) ( $route['distance'] ?? 0 ) > 0 ) {
				$metros = (int) round( (float) $route['distance'] );
				$linea = array();
				foreach ( (array) ( $route['geometry']['coordinates'] ?? array() ) as $c ) {
					if ( is_array( $c ) && isset( $c[0], $c[1] ) ) { $linea[] = array( round( (float) $c[1], 5 ), round( (float) $c[0], 5 ) ); }
				}
				$out = array( 'metros' => $metros, 'minutos' => self::minutos_triciclo( $metros ), 'como' => 'calles', 'linea' => count( $linea ) > 1 ? $linea : null );
				set_transient( $key, $out, HOUR_IN_SECONDS );
				return $out;
			}
		}
		$metros = (int) round( self::recta_m( $olat, $olng, $dlat, $dlng ) * 1.3 );
		return array( 'metros' => $metros, 'minutos' => self::minutos_triciclo( $metros ), 'como' => 'recta', 'linea' => null );
	}

	/** El camino por las calles si ya está en caché (lo dejó «cerca» al cotizar); nunca llama al VPS. */
	public static function linea_guardada( float $olat, float $olng, float $dlat, float $dlng ): ?array {
		$hit = get_transient( 'cvd_tc_ruta2_' . md5( implode( ',', array_map( static fn( float $v ): string => number_format( $v, 4, '.', '' ), array( $olat, $olng, $dlat, $dlng ) ) ) ) );
		return is_array( $hit ) && ! empty( $hit['linea'] ) ? $hit['linea'] : null;
	}

	/** Un triciclo eléctrico en la ciudad: ~15 km/h con paradas (el OSRM de coche iría demasiado rápido). */
	public static function minutos_triciclo( int $metros ): int { return max( 1, (int) ceil( $metros / 250 ) ); }

	// ------------------------------------------------------------------ Firma (el precio que vio el cliente)

	private static function clave(): string { return wp_salt( 'auth' ) . '|cvd-tc-precio'; }

	/** Datos que se firman: el cliente no puede cambiar ni el precio, ni el chofer, ni los puntos. */
	public static function firmar( array $q ): string {
		$q['vence'] = $q['vence'] ?? time() + self::FIRMA_TTL;
		$json = wp_json_encode( self::campos( $q ) );
		return rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' ) . '.' . substr( hash_hmac( 'sha256', (string) $json, self::clave() ), 0, 40 );
	}

	/** La cotización firmada, o null si la firma no vale o caducó. */
	public static function verificar( string $firma, ?int $now = null ): ?array {
		if ( ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{40})$/', $firma, $m ) ) { return null; }
		$json = base64_decode( strtr( $m[1], '-_', '+/' ), true );
		if ( false === $json || ! hash_equals( substr( hash_hmac( 'sha256', $json, self::clave() ), 0, 40 ), $m[2] ) ) { return null; }
		$q = json_decode( $json, true );
		if ( ! is_array( $q ) || (int) ( $q['vence'] ?? 0 ) < ( $now ?? time() ) ) { return null; }
		return $q;
	}

	private static function campos( array $q ): array {
		return array(
			'chofer'   => (int) $q['chofer'],
			'servicio' => (string) $q['servicio'],
			'olat'     => round( (float) $q['olat'], 6 ),
			'olng'     => round( (float) $q['olng'], 6 ),
			'dlat'     => round( (float) $q['dlat'], 6 ),
			'dlng'     => round( (float) $q['dlng'], 6 ),
			'metros'   => (int) $q['metros'],
			'minutos'  => (int) $q['minutos'],
			'precio'   => (int) $q['precio'],
			'franja'   => (string) $q['franja'],
			'extra'    => (int) $q['extra'],
			'vence'    => (int) $q['vence'],
		);
	}
}
