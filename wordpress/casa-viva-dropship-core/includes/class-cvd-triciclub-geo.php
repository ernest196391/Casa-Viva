<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.118 (Ernesto 3-nov, «como La Nave de verdad»): direcciones para Triciclub con las calles de La Habana que ya
 * tenemos en el servidor (uploads/cvd-geo/habana-calles.json, de OpenStreetMap; lo usa también CVD_Geo_Direccion).
 *
 * - donde( lat, lng ): el punto del GPS o del mapa → «Calle 23 e/ L y M, Vedado» (como lo escribe La Nave).
 * - sugerir( texto ): mientras el cliente escribe → calles, lugares y barrios que empiezan o contienen lo escrito.
 * Formato del archivo: n = nombres de calle normalizados; s[i] = puntos [lat, lon, lat, lon…] de la calle i;
 * x["i,j"] = [lat, lon] de la esquina de i con j; p = [nombre, lat, lon] de barrios y repartos.
 *
 * 4-nov (v2 del archivo, lo hace triciclub-geo/construir_v2.py desde el .osm.pbf): además d[i] = el nombre como se
 * escribe («San Lázaro»), g[i] = la forma de la calle (tramos en «encoded polyline»), c = rejilla de ~500 m → calles,
 * xs = puntos donde una calle solo cambia de nombre (no son esquinas), alt = nombres que solo son alternativos y
 * par = «i,j» si i es el nombre entre paréntesis de j en el mismo tramo («Línea (9)» → se dice Línea).
 * Con eso, «dónde estoy» sale de la calle de verdad y de sus dos esquinas reales a cada lado del punto. Si el archivo es
 * el viejo (sin g), sigue como antes. Lugares (tiendas, cafeterías, hospitales…): uploads/cvd-geo/habana-lugares.json.
 */
final class CVD_Triciclub_Geo {
	private static $data = null;
	private static $porcalle = null;
	private static $lugares = null;
	private static $formas = array();
	private static $seguidas = null;

	private static function data(): ?array {
		if ( null !== self::$data ) { return self::$data ?: null; }
		$up = wp_upload_dir( null, false );
		$file = trailingslashit( (string) $up['basedir'] ) . 'cvd-geo/habana-calles.json';
		$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio.
		self::$data = is_array( $json ) && isset( $json['n'], $json['s'], $json['x'] ) ? $json : array();
		return self::$data ?: null;
	}

	/** Los lugares con nombre: [[nombre, tipo, lat, lon, dirección, barrio, municipio, clave]…] o []. */
	private static function lugares(): array {
		if ( null !== self::$lugares ) { return self::$lugares; }
		$up = wp_upload_dir( null, false );
		$file = trailingslashit( (string) $up['basedir'] ) . 'cvd-geo/habana-lugares.json';
		$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio.
		return self::$lugares = is_array( $json ) && isset( $json['l'] ) && is_array( $json['l'] ) ? $json['l'] : array();
	}

	/** «23» → «Calle 23», «l» → «Calle L», «san lazaro» → «San Lazaro». */
	public static function nombre( string $n ): string {
		$n = trim( $n );
		if ( preg_match( '/^\d+[a-z]?$|^[a-z]{1,2}$|^\d+ [a-z]$/i', $n ) ) { return 'Calle ' . strtoupper( $n ); }
		return mb_convert_case( $n, MB_CASE_TITLE, 'UTF-8' );
	}

	/** El nombre de la calle i para mostrar: el de OpenStreetMap con sus tildes (v2) o el de antes. */
	private static function mostrar( int $i ): string {
		$g = self::data();
		$d = (string) ( $g['d'][ $i ] ?? '' );
		return '' !== $d ? $d : self::nombre( (string) ( $g['n'][ $i ] ?? '' ) );
	}

	/** La entrecalle como se dice en Cuba: «Calle L» → «L», «Avenida 25» → «25», «5ta Avenida» → «5ta». */
	public static function corto( string $n ): string {
		$cod = '/^(\d+(?:ra|da|ta|ma|na|va|ro|do|to|mo|no|vo|era|ero)?|[a-zñ]{1,2})(?:\s?[a-z])?$/iu';
		foreach ( array( '/^(?:calle|avenida|ave\.?|av\.?)\s+(.+)$/iu', '/^(.+?)\s+(?:calle|avenida)$/iu' ) as $pat ) {
			if ( preg_match( $pat, $n, $m ) && preg_match( $cod, $m[1] ) ) { return $m[1]; }
		}
		return $n;
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
			for ( $m = 0, $n = count( $p ) - 1; $m < $n; $m += 2 ) { // todas las esquinas del par, no solo la primera
				$out[ (int) $ij[0] ][] = array( (int) $ij[1], (float) $p[ $m ], (float) $p[ $m + 1 ] );
				$out[ (int) $ij[1] ][] = array( (int) $ij[0], (float) $p[ $m ], (float) $p[ $m + 1 ] );
			}
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

	/** «encoded polyline» (precisión 5) → [[lat, lon]…]. */
	private static function decodificar( string $s ): array {
		$out = array(); $i = 0; $la = 0; $lo = 0; $n = strlen( $s );
		while ( $i < $n ) {
			foreach ( array( 0, 1 ) as $k ) {
				$r = 0; $sh = 0;
				do { $b = ord( $s[ $i++ ] ) - 63; $r |= ( $b & 31 ) << $sh; $sh += 5; } while ( $b >= 32 && $i < $n );
				$v = ( $r & 1 ) ? ~( $r >> 1 ) : ( $r >> 1 );
				if ( 0 === $k ) { $la += $v; } else { $lo += $v; }
			}
			$out[] = array( $la / 1e5, $lo / 1e5 );
		}
		return $out;
	}

	/** Los tramos de la calle i ya en metros respecto al punto ($o = [lat, lng, kx, ky]). */
	private static function forma( int $i ): array {
		if ( ! isset( self::$formas[ $i ] ) ) {
			self::$formas[ $i ] = array_map( array( __CLASS__, 'decodificar' ), (array) ( self::data()['g'][ $i ] ?? array() ) );
		}
		return self::$formas[ $i ];
	}

	/** Punto (en metros, respecto al origen) sobre un tramo → [distancia a la línea, posición a lo largo, nº de segmento]. */
	private static function proyectar( float $px, float $py, array $tramo, array $o ): array {
		$best = array( INF, 0.0, 0 ); $acc = 0.0;
		for ( $k = 0, $n = count( $tramo ) - 1; $k < $n; $k++ ) {
			$ax = ( $tramo[ $k ][1] - $o[1] ) * $o[2] - $px; $ay = ( $tramo[ $k ][0] - $o[0] ) * $o[3] - $py;
			$bx = ( $tramo[ $k + 1 ][1] - $o[1] ) * $o[2] - $px; $by = ( $tramo[ $k + 1 ][0] - $o[0] ) * $o[3] - $py;
			$dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
			$t = $l2 > 0 ? max( 0.0, min( 1.0, ( -$ax * $dx - $ay * $dy ) / $l2 ) ) : 0.0;
			$d = sqrt( ( $ax + $t * $dx ) ** 2 + ( $ay + $t * $dy ) ** 2 );
			$seg = sqrt( $l2 );
			if ( $d < $best[0] ) { $best = array( $d, $acc + $t * $seg, $k ); }
			$acc += $seg;
		}
		return $best;
	}

	private static function largo( array $tramo, array $o ): float {
		$l = 0.0;
		for ( $k = 0, $n = count( $tramo ) - 1; $k < $n; $k++ ) { $l += sqrt( ( ( $tramo[ $k + 1 ][1] - $tramo[ $k ][1] ) * $o[2] ) ** 2 + ( ( $tramo[ $k + 1 ][0] - $tramo[ $k ][0] ) * $o[3] ) ** 2 ); }
		return $l;
	}

	/** El punto a $s metros del principio del tramo, en metros respecto al origen. */
	private static function punto_en( array $tramo, float $s, array $o ): array {
		$acc = 0.0;
		for ( $k = 0, $n = count( $tramo ) - 1; $k < $n; $k++ ) {
			$ax = ( $tramo[ $k ][1] - $o[1] ) * $o[2]; $ay = ( $tramo[ $k ][0] - $o[0] ) * $o[3];
			$bx = ( $tramo[ $k + 1 ][1] - $o[1] ) * $o[2]; $by = ( $tramo[ $k + 1 ][0] - $o[0] ) * $o[3];
			$seg = sqrt( ( $bx - $ax ) ** 2 + ( $by - $ay ) ** 2 );
			if ( $seg > 0 && $acc + $seg >= $s ) { $f = ( $s - $acc ) / $seg; return array( $ax + $f * ( $bx - $ax ), $ay + $f * ( $by - $ay ) ); }
			$acc += $seg;
		}
		$z = end( $tramo );
		return array( ( $z[1] - $o[1] ) * $o[2], ( $z[0] - $o[0] ) * $o[3] );
	}

	/** Hacia dónde va la calle en $pos (vector de 1 m): de 30 m antes a 30 m después (un segmento corto engaña). */
	private static function rumbo( array $tramo, float $pos, array $o ): array {
		$a = self::punto_en( $tramo, max( 0.0, $pos - 30 ), $o ); $b = self::punto_en( $tramo, $pos + 30, $o );
		$x = $b[0] - $a[0]; $y = $b[1] - $a[1]; $l = sqrt( $x * $x + $y * $y ) ?: 1.0;
		return array( $x / $l, $y / $l );
	}

	/** «e/ M y L» → «e/ L y M», «e/ 12 y 10» → «e/ 10 y 12» (como se dice). Con nombres, el más cercano primero. */
	private static function orden( string $a, string $b ): array {
		$cod = '/^(\d+(?:ra|da|ta|ma|na|va|ro|do|to|mo|no|vo|era|ero)?|[a-zñ]{1,2})(?:\s?[a-z])?$/iu';
		if ( preg_match( $cod, $a ) && preg_match( $cod, $b ) ) {
			$ka = ctype_digit( $a[0] ) ? array( 0, (int) $a, $a ) : array( 1, 0, strtolower( $a ) );
			$kb = ctype_digit( $b[0] ) ? array( 0, (int) $b, $b ) : array( 1, 0, strtolower( $b ) );
			if ( $kb < $ka ) { return array( $b, $a ); }
		}
		return array( $a, $b );
	}

	/** Puntos donde una calle solo cambia de nombre (xs): no son esquinas. «lat,lon» => true. */
	private static function seguidas(): array {
		if ( null === self::$seguidas ) {
			self::$seguidas = array();
			foreach ( (array) ( self::data()['xs'] ?? array() ) as $p ) {
				for ( $m = 0, $n = count( $p ) - 1; $m < $n; $m += 2 ) { self::$seguidas[ round( (float) $p[ $m ], 5 ) . ',' . round( (float) $p[ $m + 1 ], 5 ) ] = true; }
			}
		}
		return self::$seguidas;
	}

	/**
	 * Punto → {texto: «Calle 23 e/ L y M», barrio, calle} o null (fuera de La Habana).
	 * Con el archivo v2: 1) las calles a menos de 150 m (por la rejilla c y su forma g); la calle es la de OSRM si está
	 * entre ellas y casi igual de cerca (≤ 5 m más: desempata), si no la más cercana (OSRM no ve las peatonales: Obispo).
	 * 2) Dónde cae el punto a lo largo de esa calle. 3) Sus esquinas reales (x) a cada lado, la más cercana de cada lado;
	 * también las del otro carril si lo tiene (Paseo, Presidentes: parque en medio).
	 */
	public static function donde( float $lat, float $lng ): ?array {
		$g = self::data();
		if ( ! $g ) { return null; }
		if ( empty( $g['g'] ) || empty( $g['c'] ) ) { return self::donde_v1( $lat, $lng ); }
		$o = array( $lat, $lng, 111320.0 * cos( deg2rad( $lat ) ), 110574.0 );
		$gc = 0.005; $cla = (int) floor( $lat / $gc ); $clo = (int) floor( $lng / $gc );
		$cand = array();
		for ( $a = $cla - 1; $a <= $cla + 1; $a++ ) {
			for ( $b = $clo - 1; $b <= $clo + 1; $b++ ) {
				foreach ( (array) ( $g['c'][ $a . ':' . $b ] ?? array() ) as $i ) { $cand[ (int) $i ] = true; }
			}
		}
		$alt = array_flip( (array) ( $g['alt'] ?? array() ) );
		$cerca = array(); // i => [distancia, posición, tramo]
		foreach ( array_keys( $cand ) as $i ) {
			foreach ( self::forma( $i ) as $tramo ) {
				$pr = self::proyectar( 0.0, 0.0, $tramo, $o );
				if ( $pr[0] <= 150 && ( ! isset( $cerca[ $i ] ) || $pr[0] < $cerca[ $i ][0] ) ) { $cerca[ $i ] = array( $pr[0], $pr[1], $tramo ); }
			}
		}
		if ( ! $cerca ) { return null; }
		uksort( $cerca, static fn( int $a, int $b ): int => ( $cerca[ $a ][0] + ( isset( $alt[ $a ] ) ? 3 : 0 ) ) <=> ( $cerca[ $b ][0] + ( isset( $alt[ $b ] ) ? 3 : 0 ) ) );
		$main = (int) array_key_first( $cerca );
		$osrm = self::calle_osrm( $lat, $lng );
		$nombre = '';
		if ( '' !== $osrm ) {
			// «Calle 23 (La Rampa)» → «Calle 23» o, si no, «La Rampa»; se muestra la parte que case.
			$partes = preg_match( '/^(.*?)\s*\((.*)\)\s*$/u', $osrm, $pm ) ? array( trim( $pm[1] ), trim( $pm[2] ) ) : array( $osrm );
			$lim = $cerca[ $main ][0] + 5;
			foreach ( $partes as $pt ) {
				foreach ( $cerca as $i => $c ) {
					if ( $c[0] > $lim ) { break; }
					if ( (string) $g['n'][ $i ] === self::norm( $pt ) ) { $main = $i; $nombre = $pt; break 2; }
				}
			}
		}
		$par = array_flip( (array) ( $g['par'] ?? array() ) ); // «i,j»: i es el alias entre paréntesis de j («Línea (9)»)
		foreach ( $cerca as $i => $c ) { // el mismo tramo con dos nombres: se dice «Línea», no «9»
			if ( $c[0] > $cerca[ $main ][0] + 1.5 ) { break; }
			if ( isset( $par[ $main . ',' . $i ] ) ) { $main = $i; $nombre = ''; break; }
		}
		$nombre = '' !== $nombre ? $nombre : self::mostrar( $main );
		list( $dist, $pos, $tramo ) = $cerca[ $main ];
		// Referencia: el trozo largo (≥ 80 m) más cercano, no un pasito que cruza el parque de en medio (Presidentes).
		$ref = null;
		foreach ( self::forma( $main ) as $tr ) {
			$pr = self::proyectar( 0.0, 0.0, $tr, $o );
			if ( $pr[0] <= $dist + 25 && self::largo( $tr, $o ) >= 80 && ( ! $ref || $pr[0] < $ref[0] ) ) { $ref = array( $pr[0], $pr[1], $tr ); }
		}
		if ( $ref ) { list( , $pos, $tramo ) = $ref; }
		$u0 = self::rumbo( $tramo, $pos, $o );
		$otros = array(); // el otro carril de la calle, si lo tiene (Paseo, Presidentes: parque en medio)
		foreach ( self::forma( $main ) as $tr ) {
			if ( $tr !== $tramo && self::proyectar( 0.0, 0.0, $tr, $o )[0] <= $cerca[ $main ][0] + 60 ) { $otros[] = $tr; }
		}
		$seg = self::seguidas();
		$lados = array( -1 => null, 1 => null ); // [j, distancia a lo largo, ¿peor nombre?]
		foreach ( self::esquinas()[ $main ] ?? array() as $e ) {
			list( $j, $ela, $elo ) = $e;
			$ex = ( $elo - $lng ) * $o[2]; $ey = ( $ela - $lat ) * $o[3];
			if ( $ex * $ex + $ey * $ey > 810000 || isset( $seg[ round( $ela, 5 ) . ',' . round( $elo, 5 ) ] ) ) { continue; } // > 900 m
			$pr = self::proyectar( $ex, $ey, $tramo, $o );
			if ( $pr[0] <= 30 ) {
				$t = $pr[1] - $pos; // a lo largo de la calle
			} else {
				$en_otro = false;
				foreach ( $otros as $tr ) { if ( self::proyectar( $ex, $ey, $tr, $o )[0] <= 30 ) { $en_otro = true; break; } }
				if ( ! $en_otro ) { continue; } // en otro tramo de la calle, lejos
				$t = $ex * $u0[0] + $ey * $u0[1]; // en el otro carril: según hacia dónde va la calle aquí
			}
			$lado = $t < 0 ? -1 : 1;
			$cur = $lados[ $lado ];
			$peor = isset( $alt[ $j ] ) || ( $cur && isset( $par[ $j . ',' . $cur[0] ] ) );
			$mejor = $cur && ( $cur[2] || isset( $par[ $cur[0] . ',' . $j ] ) );
			if ( ! $cur || abs( $t ) < $cur[1] - 8 || ( abs( $t ) < $cur[1] + 8 && $mejor && ! $peor ) ) { $lados[ $lado ] = array( $j, abs( $t ), $peor ); }
		}
		$a = $lados[-1]; $b = $lados[1];
		if ( $a && $b && $b[1] < $a[1] ) { list( $a, $b ) = array( $b, $a ); }
		if ( ! $a && $b ) { $a = $b; $b = null; }
		$na = $a ? self::corto( self::mostrar( $a[0] ) ) : '';
		if ( $a && $a[1] < 15 ) { $texto = $nombre . ' esq. ' . $na; }
		elseif ( $a && $b ) { $texto = $nombre . ' e/ ' . implode( ' y ', self::orden( $na, self::corto( self::mostrar( $b[0] ) ) ) ); }
		elseif ( $a ) { $texto = $nombre . ' cerca de ' . $na; }
		else { $texto = $nombre; }
		return array( 'texto' => $texto, 'barrio' => self::barrio( $lat, $lng ), 'calle' => $nombre );
	}

	/**
	 * Como era antes del 4-nov (archivo v1, sin la forma de las calles).
	 * 1) La calle: la de OSRM; si no contesta, la de nuestros datos con el punto más cercano.
	 * 2) Su dirección: sus dos puntos más cercanos. 3) Entrecalles: las otras calles que la cruzan cerca (su punto más cercano
	 * cae sobre la línea de la calle, a menos de 35 m), la más cercana a cada lado. Las paralelas (K al lado de L) quedan fuera.
	 */
	private static function donde_v1( float $lat, float $lng ): ?array {
		$g = self::data();
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

	/** Para buscar lugares: minúsculas, sin tildes, solo letras y números («Café Bahía» → «cafe bahia»). */
	private static function clave( string $s ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $s ) ) ) );
	}

	/**
	 * Lugares cuyo nombre empieza por lo escrito (0) o tiene una palabra que empieza así (1). Con GPS, los más cercanos
	 * primero, contando la importancia (0–4: un hospital o Coppelia «acerca» más que una casa particular); sin GPS, los
	 * más importantes y luego los de nombre más corto. → hasta $max [{texto, sub, lat, lng, tipo: lugar, _r}].
	 */
	private static function buscar_lugares( string $texto, ?float $cerca_lat, ?float $cerca_lng, int $max ): array {
		$q = self::clave( $texto );
		if ( strlen( $q ) < 3 ) { return array(); }
		$hits = array();
		foreach ( self::lugares() as $l ) {
			$k = (string) ( $l[7] ?? '' );
			$pos = strpos( $k, $q );
			if ( false === $pos ) { continue; }
			if ( 0 === $pos ) { $r = 0; } elseif ( ' ' === $k[ $pos - 1 ] ) { $r = 1; } else { continue; }
			$imp = (int) ( $l[8] ?? 0 );
			$d = null !== $cerca_lat ? sqrt( self::d2( $cerca_lat, (float) $cerca_lng, (float) $l[2], (float) $l[3] ) ) * 111 / ( 1 + $imp ) : 100 - 10 * $imp + strlen( $k ) / 10;
			$hits[] = array( $r, $d, $l );
		}
		usort( $hits, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		$out = array();
		foreach ( array_slice( $hits, 0, $max ) as $h ) {
			$l = $h[2];
			$sub = trim( (string) $l[4] . ( '' !== (string) $l[6] ? ', ' . $l[6] : '' ), ', ' );
			$out[] = array( 'texto' => (string) $l[0], 'sub' => ( '' !== $sub ? $sub : (string) $l[1] ), 'lat' => (float) $l[2], 'lng' => (float) $l[3], 'tipo' => 'lugar', '_r' => $h[0] );
		}
		return $out;
	}

	/**
	 * Lo que escribe el cliente → hasta 8 sugerencias [{texto, sub, lat, lng, tipo}]. Primero la dirección exacta si la
	 * entiende («23 y L», «Obispo entre Habana y Compostela»), luego calles que empiezan por lo escrito (hasta 3), los
	 * lugares que empiezan por lo escrito (Coppelia, Variedades Obispo…; los más cercanos primero), el resto de calles,
	 * los lugares con una palabra que empieza así («Calixto» → Hospital Calixto García) y por último barrios.
	 */
	public static function sugerir( string $texto, ?float $cerca_lat = null, ?float $cerca_lng = null ): array {
		$g = self::data();
		$q = self::norm( $texto );
		if ( ! $g || mb_strlen( $q ) < 2 ) { return array(); }
		$key = 'cvd_tc_sug2_' . md5( $q . '|' . self::clave( $texto ) . '|' . ( null === $cerca_lat ? '' : round( $cerca_lat, 2 ) . ',' . round( (float) $cerca_lng, 2 ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; } // lo mismo escrito otra vez (o por otra persona cerca): al momento
		$out = array();
		// La búsqueda de dirección exacta es lenta: solo si parece una («23 y L», «Obispo entre…», «esq.», un número).
		$exacta = preg_match( '/\s(y|e|entre|esq\w*)\s|e\/|#|\d/iu', ' ' . $texto . ' ' ) ? CVD_Triciclub_Api::localizar( $texto, '', null !== $cerca_lat ? array( $cerca_lat, (float) $cerca_lng ) : null ) : null;
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
			$item = array( 'texto' => self::mostrar( (int) $i ), 'sub' => self::barrio( $best[0], $best[1] ), 'lat' => $best[0], 'lng' => $best[1], 'tipo' => 'calle', '_l' => strlen( $n ) );
			if ( 0 === $pos ) { $empieza[] = $item; } else { $contiene[] = $item; }
			if ( count( $empieza ) >= 8 ) { break; }
		}
		$corto = static fn( array $a, array $b ): int => $a['_l'] <=> $b['_l'];
		usort( $empieza, $corto ); usort( $contiene, $corto );
		$lug = self::buscar_lugares( $texto, $cerca_lat, $cerca_lng, 8 );
		$lug0 = array_values( array_filter( $lug, static fn( array $l ): bool => 0 === $l['_r'] ) );
		$lug1 = array_values( array_filter( $lug, static fn( array $l ): bool => 0 !== $l['_r'] ) );
		foreach ( array_merge( array_slice( $empieza, 0, 3 ), $lug0, array_slice( $empieza, 3 ), $lug1, $contiene ) as $it ) { unset( $it['_l'], $it['_r'] ); $out[] = $it; }
		foreach ( (array) ( $g['p'] ?? array() ) as $p ) {
			if ( false !== strpos( (string) $p[0], $q ) ) { $out[] = array( 'texto' => mb_convert_case( (string) $p[0], MB_CASE_TITLE, 'UTF-8' ), 'sub' => 'Barrio', 'lat' => (float) $p[1], 'lng' => (float) $p[2], 'tipo' => 'barrio' ); }
		}
		$out = array_slice( $out, 0, 8 );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}
}
