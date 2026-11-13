<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026, D138–D140): el precio. Lo calcula SIEMPRE el servidor; el móvil solo lo enseña.
 *
 * - Precio justo = mínimo (con sus km incluidos) + CUP por km de más, por calles (OSRM del VPS). Noche (22:00–6:00 Cuba)
 *   +20 %. Redondeo hacia arriba a 10 CUP. Tabla D140 en la opción `cvd_pv_tarifas` (JSON; si falta o está mal, la de aquí).
 * - El cliente ofrece de 10 en 10 entre −20 % y +50 % del justo, pero NUNCA por debajo del mínimo de su servicio.
 * - El chofer acepta o contraoferta +10/+20/+30 % sobre la oferta del cliente (redondeo a 10).
 * - Espera: 5 min gratis desde «Llegué»; después 50 CUP por minuto (se suma al final).
 * - La cotización (clase, puntos, km, justo, noche) va FIRMADA 10 min: el móvil no puede cambiar el justo ni los límites.
 */
final class CVD_Pv_Tarifa {
	public const OPTION = 'cvd_pv_tarifas';
	public const NOCHE_PCT = 20;
	public const NOCHE_DESDE = 22;
	public const NOCHE_HASTA = 6;
	public const BAJA_PCT = 20;
	public const SUBE_PCT = 50;
	public const CONTRA = array( 0, 10, 20, 30 );
	public const ESPERA_GRATIS_MIN = 5;
	public const ESPERA_CUP_MIN = 50;
	public const FIRMA_TTL = 600;

	/**
	 * Las clases de vehículo. grupo: taxi | tric | moto | mens. [mínimo CUP, km incluidos, CUP por km de más] (D140).
	 * m_min = metros por minuto en la ciudad (para «llega en» y «tardas»).
	 */
	public const CLASES = array(
		'taxi_basico'    => array( 'grupo' => 'taxi', 'nombre' => 'Básico', 'largo' => 'Taxi básico', 'plazas' => '4', 'img' => 'taxi-basico', 't' => array( 3000, 2, 200 ), 'm_min' => 400 ),
		'taxi_clasico'   => array( 'grupo' => 'taxi', 'nombre' => 'Clásico', 'largo' => 'Almendrón clásico', 'plazas' => '4', 'img' => 'taxi-clasico', 't' => array( 3500, 2, 400 ), 'm_min' => 380 ),
		'taxi_xl'        => array( 'grupo' => 'taxi', 'nombre' => 'Taxi XL', 'largo' => 'Taxi XL', 'plazas' => '7', 'img' => 'taxi-xl', 't' => array( 4500, 2, 400 ), 'm_min' => 380 ),
		'tric_electrico' => array( 'grupo' => 'tric', 'nombre' => 'Eléctrico', 'largo' => 'Triciclo eléctrico', 'plazas' => '4', 'img' => 'tric-electrico', 't' => array( 1500, 2, 300 ), 'm_min' => 250 ),
		'tric_bici'      => array( 'grupo' => 'tric', 'nombre' => 'Bicitaxi', 'largo' => 'Bicitaxi', 'plazas' => '2', 'img' => 'tric-bici', 't' => array( 800, 2, 250 ), 'm_min' => 180 ),
		'tric_xl'        => array( 'grupo' => 'tric', 'nombre' => 'Eléctrico XL', 'largo' => 'Triciclo eléctrico XL', 'plazas' => '6–8', 'img' => 'tric-xl', 't' => array( 2200, 2, 400 ), 'm_min' => 230 ),
		'tric_carga'     => array( 'grupo' => 'tric', 'nombre' => 'Carga', 'largo' => 'Triciclo de carga', 'plazas' => '300 kg', 'img' => 'tric-carga', 't' => array( 2000, 3, 300 ), 'm_min' => 200 ),
		'moto'           => array( 'grupo' => 'moto', 'nombre' => 'Moto', 'largo' => 'Moto', 'plazas' => '1', 'img' => 'moto', 't' => array( 800, 3, 230 ), 'm_min' => 400 ),
		'mensajero'      => array( 'grupo' => 'mens', 'nombre' => 'Mensajero', 'largo' => 'Mensajero', 'plazas' => '', 'img' => 'mensajero', 't' => array( 1000, 3, 250 ), 'm_min' => 380 ),
	);

	/** v3 (D142): las 7 clases de «Viajar» (la fila de vehículos de la app). Carga y mensajero van por sus pantallas. */
	public const VIAJAR = array( 'moto', 'tric_bici', 'tric_electrico', 'tric_xl', 'taxi_basico', 'taxi_clasico', 'taxi_xl' );

	/** v3: el servicio de un pedido. */
	public const SERVICIOS = array( 'viaje', 'paquete', 'comida', 'carga' );

	/** v3 Carga: peso aproximado (solo se le enseña al chofer; NO cambia el precio). Más de 300 kg → «escríbenos». */
	public const PESOS = array( 'poco' => 'Hasta 50 kg', 'medio' => '50–150 kg', 'mucho' => '150–300 kg' );

	/** v3 Carga: precio fijo del ayudante para cargar (opción `cvd_pv_ayudante_cup`, 500 CUP por defecto). */
	public static function ayudante(): int { return max( 0, min( 20000, (int) get_option( 'cvd_pv_ayudante_cup', 500 ) ) ); }

	/** v3 Comida: lo más que un mensajero adelanta al recoger (opción `cvd_pv_comida_tope`, 5.000 CUP por defecto). */
	public static function comida_tope(): int { return max( 0, min( 100000, (int) get_option( 'cvd_pv_comida_tope', 5000 ) ) ); }

	/** Límites con un extra fijo (el ayudante): el extra se suma al mínimo y al máximo; $justo ya lo lleva dentro. */
	public static function limites_extra( string $clase, int $justo, bool $noche, int $extra ): array {
		$l = self::limites( $clase, max( 0, $justo - $extra ), $noche );
		return array( $l[0] + $extra, $l[1] + $extra );
	}

	/** El tipo del alta del chofer → su clase. Un triciclo de motor de gasolina cobra como el eléctrico. */
	public const TIPO_A_CLASE = array(
		'bici' => 'tric_bici', 'electrico' => 'tric_electrico', 'motor' => 'tric_electrico', 'electrico_xl' => 'tric_xl',
		'carga' => 'tric_carga', 'moto' => 'moto', 'taxi_basico' => 'taxi_basico', 'taxi_clasico' => 'taxi_clasico',
		'taxi_xl' => 'taxi_xl', 'mensajero' => 'mensajero',
	);

	/** Tamaños del envío. Lo «grande» va en triciclo y cobra como Carga. */
	public const TAMANOS = array( 'mano' => 'En la mano', 'mochila' => 'Mochila', 'grande' => 'Grande' );

	/** La tabla en uso: la de la opción (si está bien) o la de aquí. */
	public static function tabla(): array {
		$raw = get_option( self::OPTION, '' );
		$o = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$out = array();
		foreach ( self::CLASES as $id => $c ) {
			$t = is_array( $o ) && isset( $o[ $id ] ) && is_array( $o[ $id ] ) && 3 === count( $o[ $id ] ) ? array_map( 'floatval', array_values( $o[ $id ] ) ) : $c['t'];
			if ( $t[0] < 50 || $t[1] < 0 || $t[2] < 0 ) { $t = $c['t']; }
			$out[ $id ] = array( (int) $t[0], (float) $t[1], (int) $t[2] );
		}
		return $out;
	}

	public static function clase_ok( string $clase ): bool { return isset( self::CLASES[ $clase ] ); }

	public static function grupo( string $clase ): string { return self::CLASES[ $clase ]['grupo'] ?? ''; }

	/** ¿Es de noche (22:00–6:00 Cuba)? */
	public static function noche( ?int $ts = null ): bool {
		$h = (int) ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'G' );
		return $h >= self::NOCHE_DESDE || $h < self::NOCHE_HASTA;
	}

	public static function arriba10( float $v ): int { return (int) ( ceil( round( $v, 2 ) / 10 ) * 10 ); }

	public static function abajo10( float $v ): int { return (int) ( floor( round( $v, 2 ) / 10 ) * 10 ); }

	/** Mínimo del servicio (con la noche). */
	public static function minimo( string $clase, bool $noche ): int {
		$t = self::tabla()[ $clase ] ?? self::CLASES['tric_electrico']['t'];
		return self::arriba10( $t[0] * ( $noche ? ( 100 + self::NOCHE_PCT ) / 100 : 1 ) );
	}

	/** Precio justo de un trayecto de $metros. */
	public static function justo( string $clase, int $metros, bool $noche ): int {
		$t = self::tabla()[ $clase ] ?? self::CLASES['tric_electrico']['t'];
		$km = max( 0, $metros ) / 1000;
		$base = $t[0] + max( 0, $km - $t[1] ) * $t[2];
		return self::arriba10( $base * ( $noche ? ( 100 + self::NOCHE_PCT ) / 100 : 1 ) );
	}

	/** Lo que el cliente puede ofrecer: [min, max]. Nunca por debajo del mínimo del servicio. */
	public static function limites( string $clase, int $justo, bool $noche ): array {
		$min = max( self::minimo( $clase, $noche ), self::arriba10( $justo * ( 100 - self::BAJA_PCT ) / 100 ) );
		$max = max( $min, self::abajo10( $justo * ( 100 + self::SUBE_PCT ) / 100 ) );
		return array( $min, $max );
	}

	/** La oferta del cliente dentro de los límites y de 10 en 10. */
	public static function ajustar( int $precio, array $lim ): int {
		$p = (int) ( round( $precio / 10 ) * 10 );
		return max( $lim[0], min( $lim[1], $p ) );
	}

	/** Contraoferta del chofer: +0/+10/+20/+30 % sobre la oferta del cliente. */
	public static function contra( int $oferta, int $pct ): int {
		$pct = in_array( $pct, self::CONTRA, true ) ? $pct : 0;
		return 0 === $pct ? $oferta : self::arriba10( $oferta * ( 100 + $pct ) / 100 );
	}

	/** Espera cobrada: 5 min gratis desde «Llegué»; luego 50 CUP por minuto empezado. */
	public static function espera( ?string $llego_at, ?string $recogido_at ): int {
		if ( ! $llego_at || ! $recogido_at ) { return 0; }
		$seg = (int) strtotime( $recogido_at . ' UTC' ) - (int) strtotime( $llego_at . ' UTC' );
		$min = (int) ceil( max( 0, $seg - self::ESPERA_GRATIS_MIN * 60 ) / 60 );
		return $min * self::ESPERA_CUP_MIN;
	}

	/** Metros que se cobran: el taxi por calles de carro; triciclo, bici, moto y mensajero, como mucho la recta × 1,25. */
	public static function metros_cobro( string $clase, int $calles, int $recta ): int {
		if ( 'taxi' === self::grupo( $clase ) ) { return $calles; }
		return max( $recta, min( $calles, (int) round( $recta * 1.25 ) ) );
	}

	public static function minutos( string $clase, int $metros ): int {
		$v = (int) ( self::CLASES[ $clase ]['m_min'] ?? 250 );
		return max( 1, (int) ceil( $metros / $v ) );
	}

	/** Las clases para la app (nombre, plazas, imagen, grupo). */
	public static function publicas(): array {
		$out = array();
		foreach ( self::CLASES as $id => $c ) { $out[ $id ] = array( 'id' => $id, 'grupo' => $c['grupo'], 'nombre' => $c['nombre'], 'largo' => $c['largo'], 'plazas' => $c['plazas'], 'img' => $c['img'] ); }
		return $out;
	}

	// ------------------------------------------------------------------ Firma de la cotización

	private static function clave(): string { return wp_salt( 'auth' ) . '|cvd-pv-precio'; }

	public static function firmar( array $q ): string {
		$q['vence'] = $q['vence'] ?? time() + self::FIRMA_TTL;
		$json = (string) wp_json_encode( self::campos( $q ) );
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) . '.' . substr( hash_hmac( 'sha256', $json, self::clave() ), 0, 40 );
	}

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
			'clase'   => (string) $q['clase'],
			'olat'    => round( (float) $q['olat'], 6 ),
			'olng'    => round( (float) $q['olng'], 6 ),
			'dlat'    => round( (float) $q['dlat'], 6 ),
			'dlng'    => round( (float) $q['dlng'], 6 ),
			'metros'  => (int) $q['metros'],
			'minutos' => (int) $q['minutos'],
			'justo'   => (int) $q['justo'],
			'noche'   => (int) (bool) $q['noche'],
			'tam'     => (string) ( $q['tam'] ?? '' ),
			'serv'    => (string) ( $q['serv'] ?? '' ),  // v3: viaje | paquete | comida | carga ('' = cotización vieja)
			'extra'   => (int) ( $q['extra'] ?? 0 ),     // v3: ayudante de la Carga (ya va dentro de «justo»)
			'vence'   => (int) $q['vence'],
		);
	}
}
