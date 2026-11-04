<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.118 (Ernesto 3-nov, «como La Nave de verdad»): direcciones para Triciclub con las calles de La Habana que ya
 * tenemos en el servidor (uploads/cvd-geo/habana-calles.json, de OpenStreetMap; lo usa también CVD_Geo_Direccion).
 *
 * - donde( lat, lng ): el punto del GPS o del mapa → «Calle 23 e/ L y M, Vedado» (como lo escribe La Nave).
 * - sugerir( texto ): mientras el cliente escribe → calles y barrios que empiezan o contienen lo escrito.
 * Formato del archivo: n = nombres de calle normalizados; s[i] = puntos [lat, lon, lat, lon…] de la calle i;
 * x["i,j"] = [lat, lon] de la esquina de i con j; p = [nombre, lat, lon] de barrios y repartos.
 * Sin lugares (tiendas, heladerías): La Nave los tiene, nosotros todavía no.
 */
final class CVD_Triciclub_Geo {
	private static $data = null;
	private static $porcalle = null;

	private static function data(): ?array {
		if ( null !== self::$data ) { return self::$data ?: null; }
		$up = wp_upload_dir( null, false );
		$file = trailingslashit( (string) $up['basedir'] ) . 'cvd-geo/habana-calles.json';
		$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio.
		self::$data = is_array( $json ) && isset( $json['n'], $json['s'], $json['x'] ) ? $json : array();
		return self::$data ?: null;
	}

	/** «23» → «Calle 23», «l» → «Calle L», «san lazaro» → «San Lazaro». */
	public static function nombre( string $n ): string {
		$n = trim( $n );
		if ( preg_match( '/^\d+[a-z]?$|^[a-z]{1,2}$|^\d+ [a-z]$/i', $n ) ) { return 'Calle ' . strtoupper( $n ); }
		return mb_convert_case( $n, MB_CASE_TITLE, 'UTF-8' );
	}

	private static function d2( float $a, float $b, float $c, float $d ): float {
		$x = ( $d - $b ) * cos( deg2rad( ( $a + $c ) / 2 ) );
		$y = $c - $a;
		return $x * $x + $y * $y;
	}

	/** El barrio más cercano a un punto. */
	public static function barrio( float $lat, float $lng ): string {
		$g = self::data();
		$best = ''; $bd = INF;
		foreach ( (array) ( $g['p'] ?? array() ) as $p ) {
			$d = self::d2( $lat, $lng, (float) $p[1], (float) $p[2] );
			if ( $d < $bd ) { $bd = $d; $best = (string) $p[0]; }
		}
		return '' !== $best ? mb_convert_case( $best, MB_CASE_TITLE, 'UTF-8' ) : '';
	}

	/** Esquinas de cada calle: i => [[j, lat, lon]…] (se arma una vez por petición). */
	private static function esquinas(): array {
		if ( null !== self::$porcalle ) { return self::$porcalle; }
		$out = array();
		foreach ( (array) ( self::data()['x'] ?? array() ) as $k => $p ) {
			$ij = explode( ',', (string) $k );
			if ( 2 !== count( $ij ) ) { continue; }
			$out[ (int) $ij[0] ][] = array( (int) $ij[1], (float) $p[0], (float) $p[1] );
			$out[ (int) $ij[1] ][] = array( (int) $ij[0], (float) $p[0], (float) $p[1] );
		}
		return self::$porcalle = $out;
	}

	/**
	 * La calle exacta de un punto, con nuestro motor de rutas del VPS (OSRM; el mismo de los km). Se usa /route de un
	 * punto a sí mismo porque es lo único abierto fuera del VPS: su «waypoint» trae el nombre de la calle. 1 h en caché.
	 */
	public static function calle_osrm( float $lat, float $lng ): string {
		$key = 'cvd_tc_calle_' . md5( number_format( $lat, 4, '.', '' ) . ',' . number_format( $lng, 4, '.', '' ) );
		$hit = get_transient( $key );
		if ( is_string( $hit ) ) { return $hit; }
		$api = rtrim( (string) get_option( 'cvd_tc_ruta_api', 'https://179-236-242-212.sslip.io' ), '/' );
		$pt = $lng . ',' . $lat;
		$res = wp_remote_get( $api . '/route/v1/driving/' . $pt . ';' . $pt . '?overview=false', array( 'timeout' => 3, 'redirection' => 0 ) );
		$d = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$w = is_array( $d ) && 'Ok' === ( $d['code'] ?? '' ) ? ( $d['waypoints'][0] ?? null ) : null;
		$name = is_array( $w ) && (float) ( $w['distance'] ?? 999 ) < 80 ? trim( (string) ( $w['name'] ?? '' ) ) : '';
		if ( null !== $d ) { set_transient( $key, $name, HOUR_IN_SECONDS ); }
		return $name;
	}

	/**
	 * Punto → {texto: «Calle 23 e/ L y M», barrio, calle} o null (fuera de La Habana).
	 * 1) La calle: la de OSRM; si no contesta, la de nuestros datos con el punto más cercano.
	 * 2) Su dirección: sus dos puntos más cercanos. 3) Entrecalles: las otras calles que la cruzan cerca (su punto más cercano
	 * cae sobre la línea de la calle, a menos de 35 m), la más cercana a cada lado. Las paralelas (K al lado de L) quedan fuera.
	 */
	public static function donde( float $lat, float $lng ): ?array {
		$g = self::data();
		if ( ! $g ) { return null; }
		$ky = 111000.0; $kx = 111000.0 * cos( deg2rad( $lat ) );
		$cerca = array(); // i => [distancia del punto más cercano, [x, y] de ese punto, todos los puntos a < 450 m]
		foreach ( $g['s'] as $i => $pts ) {
			$row = null;
			for ( $k = 0, $n = count( $pts ) - 1; $k < $n; $k += 2 ) {
				$x = ( (float) $pts[ $k + 1 ] - $lng ) * $kx; $y = ( (float) $pts[ $k ] - $lat ) * $ky;
				$d = sqrt( $x * $x + $y * $y );
				if ( $d > 450 ) { continue; }
				if ( ! $row ) { $row = array( INF, null, array() ); }
				$row[2][] = array( $x, $y, $d );
				if ( $d < $row[0] ) { $row[0] = $d; $row[1] = array( $x, $y ); }
			}
			if ( $row ) { $cerca[ (int) $i ] = $row; }
		}
		if ( ! $cerca ) { return null; }
		uasort( $cerca, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		$osrm = self::calle_osrm( $lat, $lng );
		$main = null;
		if ( '' !== $osrm ) {
			$q = self::norm( preg_replace( '/^(calle|avenida|ave\.?|calzada)\s+/iu', '', $osrm ) );
			foreach ( $cerca as $i => $c ) { if ( self::norm( (string) $g['n'][ $i ] ) === $q ) { $main = $i; break; } }
		}
		if ( null === $main ) {
			$main = (int) array_key_first( $cerca );
			if ( $cerca[ $main ][0] > 200 ) { return null; } // lejos de cualquier calle conocida
		}
		$nombre = '' !== $osrm ? $osrm : self::nombre( (string) $g['n'][ $main ] );
		$u = null; // dirección de la calle: sus dos puntos más cercanos
		$mp = $cerca[ $main ][2] ?? array();
		usort( $mp, static fn( array $a, array $b ): int => $a[2] <=> $b[2] );
		if ( count( $mp ) >= 2 ) {
			$vx = $mp[1][0] - $mp[0][0]; $vy = $mp[1][1] - $mp[0][1]; $l = sqrt( $vx * $vx + $vy * $vy );
			if ( $l > 5 ) { $u = array( $vx / $l, $vy / $l ); }
		}
		$nm = self::norm( (string) $g['n'][ $main ] );
		$lados = array( -1 => null, 1 => null );
		foreach ( $cerca as $j => $c ) {
			if ( $j === $main || $c[0] > 300 || self::norm( (string) $g['n'][ $j ] ) === $nm ) { continue; }
			if ( $u ) {
				$t = $c[1][0] * $u[0] + $c[1][1] * $u[1];
				$perp = abs( $c[1][0] * $u[1] - $c[1][1] * $u[0] );
				if ( $perp > 35 ) { continue; } // paralela (K al lado de L)
				$sobre = 0; // otro nombre de la misma calle («Rampa» = 23): tiene varios puntos encima de la línea
				foreach ( $c[2] as $q ) { if ( abs( $q[0] * $u[1] - $q[1] * $u[0] ) < 12 ) { $sobre++; } }
				if ( $sobre >= 2 ) { continue; }
			} else { $t = $c[1][0] ?: $c[1][1]; }
			$lado = $t < 0 ? -1 : 1;
			if ( ! $lados[ $lado ] || abs( $t ) < $lados[ $lado ][1] ) { $lados[ $lado ] = array( $j, abs( $t ) ); }
		}
		$a = $lados[-1]; $b = $lados[1];
		if ( $a && $b && $b[1] < $a[1] ) { list( $a, $b ) = array( $b, $a ); }
		if ( ! $a && $b ) { $a = $b; $b = null; }
		$na = $a ? self::nombre( (string) $g['n'][ $a[0] ] ) : '';
		if ( $a && $a[1] < 22 ) { $texto = $nombre . ' esq. ' . $na; }
		elseif ( $a && $b ) { $texto = $nombre . ' e/ ' . $na . ' y ' . self::nombre( (string) $g['n'][ $b[0] ] ); }
		elseif ( $a ) { $texto = $nombre . ' cerca de ' . $na; }
		else { $texto = $nombre; }
		return array( 'texto' => $texto, 'barrio' => self::barrio( $lat, $lng ), 'calle' => $nombre );
	}

	private static function norm( string $s ): string {
		return class_exists( 'CVD_Geo_Direccion' ) ? CVD_Geo_Direccion::norm( $s ) : strtolower( trim( remove_accents( $s ) ) );
	}

	/**
	 * Lo que escribe el cliente → hasta 8 sugerencias [{texto, sub, lat, lng, tipo}]. Primero la dirección exacta si la
	 * entiende («23 y L», «Obispo entre Habana y Compostela»), luego calles que empiezan por lo escrito, luego las que lo
	 * contienen, luego barrios.
	 */
	public static function sugerir( string $texto, ?float $cerca_lat = null, ?float $cerca_lng = null ): array {
		$g = self::data();
		$q = self::norm( $texto );
		if ( ! $g || mb_strlen( $q ) < 2 ) { return array(); }
		$key = 'cvd_tc_sug_' . md5( $q . '|' . ( null === $cerca_lat ? '' : round( $cerca_lat, 2 ) . ',' . round( (float) $cerca_lng, 2 ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; } // lo mismo escrito otra vez (o por otra persona cerca): al momento
		$out = array();
		// La búsqueda de dirección exacta es lenta: solo si parece una («23 y L», «Obispo entre…», «esq.», un número).
		$exacta = preg_match( '/\s(y|e|entre|esq\w*)\s|e\/|#|\d/iu', ' ' . $texto . ' ' ) ? CVD_Triciclub_Api::localizar( $texto, '' ) : null;
		if ( $exacta ) {
			$out[] = array( 'texto' => $texto, 'sub' => self::barrio( (float) $exacta['lat'], (float) $exacta['lon'] ), 'lat' => (float) $exacta['lat'], 'lng' => (float) $exacta['lon'], 'tipo' => 'direccion' );
		}
		$empieza = array(); $contiene = array();
		foreach ( $g['n'] as $i => $n ) {
			$n = (string) $n;
			if ( '' === $n || 0 === strpos( $n, 'enlace' ) || 0 === strpos( $n, 'tunel' ) ) { continue; }
			$pos = strpos( $n, $q );
			if ( false === $pos ) { continue; }
			$pts = $g['s'][ $i ] ?? array();
			if ( count( $pts ) < 2 ) { continue; }
			// El punto de la calle más cercano al cliente (o el del medio): la sugerencia cae en su parte de la calle.
			$best = array( (float) $pts[0], (float) $pts[1] );
			if ( null !== $cerca_lat ) {
				$bd = INF;
				for ( $k = 0, $m = count( $pts ) - 1; $k < $m; $k += 2 ) { $d = self::d2( $cerca_lat, $cerca_lng, (float) $pts[ $k ], (float) $pts[ $k + 1 ] ); if ( $d < $bd ) { $bd = $d; $best = array( (float) $pts[ $k ], (float) $pts[ $k + 1 ] ); } }
			} else {
				$mid = (int) ( floor( count( $pts ) / 4 ) * 2 );
				$best = array( (float) $pts[ $mid ], (float) $pts[ $mid + 1 ] );
			}
			$item = array( 'texto' => self::nombre( $n ), 'sub' => self::barrio( $best[0], $best[1] ), 'lat' => $best[0], 'lng' => $best[1], 'tipo' => 'calle', '_l' => strlen( $n ) );
			if ( 0 === $pos ) { $empieza[] = $item; } else { $contiene[] = $item; }
			if ( count( $empieza ) >= 8 ) { break; }
		}
		$corto = static fn( array $a, array $b ): int => $a['_l'] <=> $b['_l'];
		usort( $empieza, $corto ); usort( $contiene, $corto );
		foreach ( array_merge( $empieza, $contiene ) as $it ) { unset( $it['_l'] ); $out[] = $it; }
		foreach ( (array) ( $g['p'] ?? array() ) as $p ) {
			if ( false !== strpos( (string) $p[0], $q ) ) { $out[] = array( 'texto' => mb_convert_case( (string) $p[0], MB_CASE_TITLE, 'UTF-8' ), 'sub' => 'Barrio', 'lat' => (float) $p[1], 'lng' => (float) $p[2], 'tipo' => 'barrio' ); }
		}
		$out = array_slice( $out, 0, 8 );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}
}
