<?php
/**
 * 3.14.78 · Pa'llá: la ubicación sale de la dirección (Ernesto 29-oct: «sin que el cliente tenga que enviárnosla, hay veces
 * que no van a tener conexión y hay veces que no van a saber»).
 *
 * «Calle X entre Y y Z» (63 % de los vales) → las dos esquinas X∩Y y X∩Z del mapa libre (OpenStreetMap) → la 🏠 en medio
 * de la cuadra. Con una sola esquina, en la esquina. Sin entrecalles: un punto de la calle X cerca del barrio que diga la
 * dirección (Ernesto: «buscar solo por calle y barrio»). Las calles están en nuestro servidor
 * (uploads/cvd-geo/habana-calles.json, lo hace scripts/palla-geo/construir-calles.mjs): sin internet de fuera y gratis.
 * El resultado queda en el pedido (_cvd_geo_auto) y se rehace solo si cambia la dirección.
 */

defined( 'ABSPATH' ) || exit;

final class CVD_Geo_Direccion {
	public const META = '_cvd_geo_auto';

	private const STOP = array( 'calle', 'c', 'ave', 'av', 'avda', 'avenida', 'calzada', 'calz', 'cz', 'de', 'del', 'la', 'las', 'los', 'el' );
	private const WORDS = array( 'primer' => '1', 'primera' => '1', 'primero' => '1', 'segunda' => '2', 'segundo' => '2', 'tercera' => '3', 'tercero' => '3', 'cuarta' => '4', 'cuarto' => '4', 'quinta' => '5', 'quinto' => '5', 'sexta' => '6', 'sexto' => '6', 'septima' => '7', 'septimo' => '7', 'setima' => '7', 'octava' => '8', 'octavo' => '8', 'novena' => '9', 'noveno' => '9', 'decima' => '10', 'decimo' => '10', 'gral' => 'general', 'sto' => 'santo', 'sta' => 'santa', 'sn' => 'san' );

	private static $data = null;
	private static $loose = null;

	/** La misma forma de escribir un nombre que norm() de construir-calles.mjs. «5ta Avenida» → «5», «D’Strampes» → «dstrampes». */
	public static function norm( string $s ): string {
		$t = strtolower( remove_accents( $s ) );
		$t = str_replace( array( "'", '’', '´', '`', '‘' ), '', $t );
		$t = (string) preg_replace( '/[^a-z0-9]+/', ' ', $t );
		$t = (string) preg_replace_callback( '/\b(\d+)(?:ra|ro|era|ero|da|do|ta|to|ma|mo|va|vo|na|no)?(?:\s?([a-z])\b)?(?![a-z])/', static fn( array $m ): string => isset( $m[2] ) && '' !== $m[2] ? $m[1] . ' ' . $m[2] : $m[1], $t );
		$tok = array_map( static fn( string $w ): string => self::WORDS[ $w ] ?? $w, array_values( array_filter( explode( ' ', $t ), 'strlen' ) ) );
		$keep = array_values( array_filter( $tok, static fn( string $w ): bool => ! in_array( $w, self::STOP, true ) ) );
		return implode( ' ', $keep ?: array_slice( $tok, -1 ) );
	}

	/** Lo de cada pedido (guardado en el pedido). → { lat, lon, how: cuadra | esquina | calle } o null. */
	public static function for_order( WC_Order $order ): ?array {
		$address = trim( ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) . ' ' . ( $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) );
		if ( '' === $address ) { return null; }
		$context = implode( ' ', array( (string) $order->get_meta( '_cvd_locality', true ), $order->get_shipping_city() ?: $order->get_billing_city(), $order->get_billing_state(), (string) $order->get_meta( '_cvd_reference', true ) ) );
		$hash = md5( $address . '|' . $context );
		$saved = json_decode( (string) $order->get_meta( self::META, true ), true );
		if ( is_array( $saved ) && ( $saved['h'] ?? '' ) === $hash ) { return isset( $saved['lat'] ) ? $saved : null; } // sin cargar las calles
		if ( ! self::data() ) { return null; }
		$found = self::locate( $address, $context );
		$order->update_meta_data( self::META, wp_json_encode( $found ? array_merge( array( 'h' => $hash ), $found ) : array( 'h' => $hash ) ) );
		$order->save_meta_data();
		return $found;
	}

	/** «Belascoaín 610 entre Clavel y Santo Tomás» (+ barrio o municipio) → { lat, lon, how } o null. */
	public static function locate( string $address, string $context = '' ): ?array {
		$data = self::data();
		if ( ! $data ) { return null; }
		$raw = ' ' . strtolower( remove_accents( $address ) ) . ' ';
		// El número de la casa, el apartamento o el edificio no es la «Calle 106» ni la «Calle 4».
		$raw = (string) preg_replace( '/(?:#|\b(?:numero|num|n[o°º]|apto|apt|apartamento|piso|edif|edf|edificio|bloque|lote|casa)\b\.?)\s*\d+\s*[a-z]?\b/', ' ', $raw );
		$raw = (string) preg_replace( '/\s+e\s*\/\s*|\s+e\.\s+|\s+entre\s+calles?\s+|\s+entre\s+/', ' entre ', $raw );
		$raw = (string) preg_replace( '/\s+esq(?:uina|\.)?\s+(?:a\s+|con\s+)?/', ' esq ', $raw );
		if ( false === strpos( $raw, ' entre ' ) ) { // «82 e 9 y 11», «46 # 112 / 37 y 39»
			$raw = (string) preg_replace( '/\s+e\s+(?=\S+(?:\s+\S+)?\s+y\s)|\s*\/\s*(?=\S+(?:\s+\S+)?\s+y\s)/', ' entre ', $raw, 1 );
		}
		$cross = array();
		if ( false !== strpos( $raw, ' entre ' ) ) {
			list( $main, $rest ) = explode( ' entre ', $raw, 2 );
			$cross = array_slice( preg_split( '/\s+y\s+|\s*&\s*/', $rest, 2 ), 0, 2 );
		} elseif ( false !== strpos( $raw, ' esq ' ) ) {
			list( $main, $rest ) = explode( ' esq ', $raw, 2 );
			$cross = array( $rest );
		} else {
			$main = $raw;
		}
		$barrio = self::barrio( $address . ' ' . $context );
		$near = static fn( array $p ): float => $barrio ? min( array_map( static fn( array $b ): float => CVD_Palla_Api::meters( $p[0], $p[1], $b[0], $b[1] ), $barrio ) ) : 0.0;
		$first = self::match( $main );
		if ( ! $first ) { return null; }
		// Primero con la primera calle de la dirección; las otras que nombre («carretera del roble calle 20»), solo si no sale.
		foreach ( $cross ? array_unique( array( $first, self::match( $main, true ) ), SORT_REGULAR ) : array() as $m ) {
			$found = self::corners( $m, $cross, $barrio, $near );
			if ( $found ) { return $found; }
		}
		// Sin entrecalles (o no están en el mapa): un punto de la calle cerca del barrio. Sin barrio no se adivina.
		if ( ! $barrio ) { return null; }
		$pts = array();
		foreach ( $first as $x ) {
			$flat = $data['s'][ (string) $x ] ?? array();
			for ( $i = 0; $i + 1 < count( $flat ); $i += 2 ) { $pts[] = array( (float) $flat[ $i ], (float) $flat[ $i + 1 ] ); }
		}
		usort( $pts, static fn( array $a, array $b ): int => $near( $a ) <=> $near( $b ) );
		return $pts && $near( $pts[0] ) <= 1500 ? self::out( $pts[0], 'calle' ) : null;
	}

	/** Las esquinas de la calle $m con sus entrecalles → la cuadra (las dos), la esquina (una) o null. */
	private static function corners( array $m, array $cross, ?array $barrio, callable $near ): ?array {
		$data = self::data();
		$sets = array();
		foreach ( $cross as $c ) {
			$pts = array();
			foreach ( self::match( $c ) as $y ) {
				foreach ( $m as $x ) {
					if ( $x === $y ) { continue; }
					$flat = $data['x'][ min( $x, $y ) . ',' . max( $x, $y ) ] ?? array();
					for ( $i = 0; $i + 1 < count( $flat ); $i += 2 ) { $pts[] = array( (float) $flat[ $i ], (float) $flat[ $i + 1 ] ); }
				}
			}
			if ( $pts ) { $sets[] = $pts; }
		}
		if ( 2 === count( $sets ) ) {
			$best = null;
			foreach ( $sets[0] as $p ) {
				foreach ( $sets[1] as $q ) {
					$d = CVD_Palla_Api::meters( $p[0], $p[1], $q[0], $q[1] );
					if ( $d > 600 ) { continue; }
					$mid = array( ( $p[0] + $q[0] ) / 2, ( $p[1] + $q[1] ) / 2 );
					$score = $d + $near( $mid ) * 0.5;
					if ( ! $best || $score < $best[0] ) { $best = array( $score, $mid ); }
				}
			}
			if ( $best && ( ! $barrio || $near( $best[1] ) < 6000 ) ) { return self::out( $best[1], 'cuadra' ); }
		}
		foreach ( $sets as $pts ) { // una esquina sola (o las dos no casan): la de la primera entrecalle que se sepa cuál es
			$pick = self::pick( $pts, $barrio, $near, 1500 );
			if ( $pick ) { return self::out( $pick, 'esquina' ); }
		}
		return null;
	}

	/** De varios puntos: el más cerca del barrio; sin barrio, solo si están todos juntos (si no, no se sabe cuál es). */
	private static function pick( array $pts, ?array $barrio, callable $near, float $spread ): ?array {
		if ( $barrio ) {
			usort( $pts, static fn( array $a, array $b ): int => $near( $a ) <=> $near( $b ) );
			return $near( $pts[0] ) < 6000 ? $pts[0] : null;
		}
		foreach ( $pts as $p ) {
			if ( CVD_Palla_Api::meters( $p[0], $p[1], $pts[0][0], $pts[0][1] ) > $spread ) { return null; }
		}
		return $pts[0];
	}

	private static function out( array $p, string $how ): array {
		return array( 'lat' => round( $p[0], 5 ), 'lon' => round( $p[1], 5 ), 'how' => $how );
	}

	/**
	 * Los nombres de calle de un trozo de dirección («santo tomas frente al cupet» → santo tomas). Con la forma exacta y la
	 * parecida («Kesell» = «Kessel»): de las dos, las esquinas dicen cuál es. $all: todas las calles que se nombren
	 * («carretera del roble calle 20» → las dos), si no, la primera.
	 */
	private static function match( string $phrase, bool $all = false ): array {
		$tok = array_values( array_filter( explode( ' ', self::norm( $phrase ) ), 'strlen' ) );
		$index = self::data()['ix'];
		$found = array();
		for ( $start = 0; $start < count( $tok ); $start++ ) {
			if ( ! $all && $start > 1 ) { break; }
			for ( $len = min( 5, count( $tok ) - $start ); $len >= 1; $len-- ) {
				$key = implode( ' ', array_slice( $tok, $start, $len ) );
				$hit = isset( $index[ $key ] ) ? array( $index[ $key ] ) : array();
				if ( strlen( $key ) >= 5 ) { $hit = array_merge( $hit, self::loose_index()[ self::loose( $key ) ] ?? array() ); }
				if ( ! $hit ) { continue; }
				$found = array_merge( $found, $hit );
				if ( ! $all ) { return array_values( array_unique( $found ) ); }
				$start += $len - 1;
				break;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/** Para «Destrampes» = «D’Strampes», «Ofarrill» = «O’Farril»: las consonantes, sin repetir. */
	private static function loose( string $k ): string {
		$k = str_replace( array( ' ', 'h', 'v', 'z' ), array( '', '', 'b', 's' ), $k );
		$k = $k[0] . preg_replace( '/[aeiouy]/', '', substr( $k, 1 ) );
		return (string) preg_replace( '/(.)\1+/', '$1', $k );
	}

	private static function loose_index(): array {
		if ( null === self::$loose ) {
			self::$loose = array();
			foreach ( self::data()['n'] as $i => $n ) { if ( strlen( $n ) >= 5 ) { self::$loose[ self::loose( $n ) ][] = $i; } }
		}
		return self::$loose;
	}

	/**
	 * Los barrios y municipios que nombra la dirección («Víbora Park, Arroyo Naranjo» → los dos): la casa está cerca de
	 * alguno. El municipio solo tiene su centro, así que cuenta el más cercano. → [[lat, lon], …] o null.
	 */
	private static function barrio( string $text ): ?array {
		$t = ' ' . self::norm( $text ) . ' ';
		$pts = array();
		foreach ( self::data()['p'] as $p ) {
			$name = (string) $p[0];
			if ( strlen( $name ) < 4 || in_array( $name, array( 'habana', 'cuba' ), true ) || false === strpos( $t, ' ' . $name . ' ' ) ) { continue; }
			$pts[] = array( (float) $p[1], (float) $p[2] );
		}
		return $pts ?: null;
	}

	public static function file(): string {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'cvd-geo/habana-calles.json';
	}

	private static function data(): ?array {
		if ( null === self::$data ) {
			$file = self::file();
			$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			self::$data = is_array( $json ) && isset( $json['n'], $json['x'] ) ? $json : false;
			if ( self::$data ) { self::$data['ix'] = array_flip( self::$data['n'] ); }
		}
		return self::$data ?: null;
	}
}
