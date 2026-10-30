<?php
/**
 * 3.14.83 · Pa'llá: 🟢 Disponible / ⚪ No disponible (Ernesto 29-oct: «como La Nave: un área para conectarse a esperar y
 * otra para salir de disponibilidad; las carreras solo le llegan a los que la tienen activa»).
 *
 * - Disponible: le suena la bocina de las carreras libres. No disponible: no le llega ninguna (la app se apaga). Lo de SUS
 *   carreras (asignada, cancelada, ya no es tuya) le llega siempre.
 * - Sus ajustes (libertad): sus zonas (municipios), «solo carreras de mis zonas» (apagado: le llegan todas y las de su zona
 *   salen marcadas) y su hora de fin: a esa hora se desconecta solo si se le olvidó.
 * - Privacidad: no se usa el GPS para esto (solo durante una carrera, como siempre). La tienda ve nombre y zonas.
 * - Quien ya tenía la app y nunca tocó el botón sigue como antes (le llega todo) hasta que lo toque por primera vez.
 */

defined( 'ABSPATH' ) || exit;

final class CVD_Palla_Disponible {
	public const META = '_cvd_palla_disp';

	public const ZONAS = array( 'Playa', 'Plaza de la Revolución', 'Centro Habana', 'La Habana Vieja', 'Cerro', 'Diez de Octubre', 'Arroyo Naranjo', 'Boyeros', 'Marianao', 'La Lisa', 'Regla', 'Guanabacoa', 'San Miguel del Padrón', 'La Habana del Este', 'Cotorro' );

	/** Barrios que salen en los vales → su municipio (lo demás se busca por el nombre del municipio). */
	private const BARRIOS = array(
		'vedado' => 'Plaza de la Revolución', 'nuevo vedado' => 'Plaza de la Revolución', 'plaza' => 'Plaza de la Revolución', 'colon' => 'Plaza de la Revolución', 'rampa' => 'Plaza de la Revolución',
		'miramar' => 'Playa', 'siboney' => 'Playa', 'atabey' => 'Playa', 'cubanacan' => 'Playa', 'buenavista' => 'Playa', 'kohly' => 'Playa', 'santa fe' => 'Playa', 'jaimanitas' => 'Playa', 'nautico' => 'Playa', 'flores' => 'Playa', 'almendares' => 'Playa',
		'cayo hueso' => 'Centro Habana', 'pueblo nuevo' => 'Centro Habana', 'los sitios' => 'Centro Habana', 'dragones' => 'Centro Habana', 'colon centro' => 'Centro Habana',
		'habana vieja' => 'La Habana Vieja', 'belen' => 'La Habana Vieja', 'jesus maria' => 'La Habana Vieja', 'san isidro' => 'La Habana Vieja',
		'sevillano' => 'Diez de Octubre', 'vibora' => 'Diez de Octubre', 'lawton' => 'Diez de Octubre', 'santos suarez' => 'Diez de Octubre', 'luyano' => 'Diez de Octubre', 'jesus del monte' => 'Diez de Octubre', '10 de octubre' => 'Diez de Octubre', 'diez de octubre' => 'Diez de Octubre', 'tamarindo' => 'Diez de Octubre', 'acosta' => 'Diez de Octubre',
		'el cano' => 'Arroyo Naranjo', 'mantilla' => 'Arroyo Naranjo', 'parraga' => 'Arroyo Naranjo', 'calvario' => 'Arroyo Naranjo', 'callejas' => 'Arroyo Naranjo', 'vibora park' => 'Arroyo Naranjo', 'los pinos' => 'Arroyo Naranjo', 'guinera' => 'Arroyo Naranjo', 'eléctrico' => 'Arroyo Naranjo', 'electrico' => 'Arroyo Naranjo',
		'santiago de las vegas' => 'Boyeros', 'wajay' => 'Boyeros', 'altahabana' => 'Boyeros', 'capdevila' => 'Boyeros', 'fontanar' => 'Boyeros', 'mulgoba' => 'Boyeros', 'rancho boyeros' => 'Boyeros', 'calabazar' => 'Boyeros',
		'alturas de la lisa' => 'La Lisa', 'punta brava' => 'La Lisa', 'arroyo arenas' => 'La Lisa', 'san agustin' => 'La Lisa', 'versalles' => 'La Lisa',
		'pogolotti' => 'Marianao', 'los quemados' => 'Marianao', 'zamora' => 'Marianao', 'santa felicia' => 'Marianao', 'libertad' => 'Marianao',
		'casino deportivo' => 'Cerro', 'la ceiba' => 'Cerro', 'palatino' => 'Cerro', 'pilar' => 'Cerro', 'atares' => 'Cerro', 'esquina de tejas' => 'Cerro',
		'alamar' => 'La Habana del Este', 'cojimar' => 'La Habana del Este', 'guanabo' => 'La Habana del Este', 'campo florido' => 'La Habana del Este', 'habana del este' => 'La Habana del Este', 'camilo cienfuegos' => 'La Habana del Este', 'tarara' => 'La Habana del Este', 'bacuranao' => 'La Habana del Este',
		'casablanca' => 'Regla', 'guanabacoa' => 'Guanabacoa', 'minas' => 'Guanabacoa', 'villa maria' => 'Guanabacoa',
		'san miguel' => 'San Miguel del Padrón', 'diezmero' => 'San Miguel del Padrón', 'rocafort' => 'San Miguel del Padrón', 'san francisco de paula' => 'San Miguel del Padrón', 'luyanó moderno' => 'San Miguel del Padrón',
		'cotorro' => 'Cotorro', 'cuatro caminos' => 'Cotorro', 'santa maria del rosario' => 'Cotorro',
	);

	/** Lo guardado del mensajero. «legacy»: nunca tocó el botón (sigue como antes: le llega todo, sin hora de fin). */
	public static function get( int $user_id ): array {
		$raw = json_decode( (string) get_user_meta( $user_id, self::META, true ), true );
		if ( ! is_array( $raw ) ) { return array( 'on' => true, 'since' => 0, 'zonas' => array(), 'solo' => false, 'hasta' => '', 'legacy' => true ); }
		return array(
			'on'     => ! empty( $raw['on'] ),
			'since'  => absint( $raw['since'] ?? 0 ),
			'zonas'  => array_values( array_intersect( self::ZONAS, (array) ( $raw['zonas'] ?? array() ) ) ),
			'solo'   => ! empty( $raw['solo'] ),
			'hasta'  => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) ( $raw['hasta'] ?? '' ) ) ? (string) $raw['hasta'] : '',
			'legacy' => false,
		);
	}

	/** Cuándo se desconecta solo (timestamp) o 0: la primera «hasta» después de conectarse, y como mucho 12 h después. */
	public static function until( array $d ): int {
		if ( ! $d['on'] || '' === $d['hasta'] || ! $d['since'] ) { return 0; }
		$tz = new DateTimeZone( 'America/Havana' );
		$from = ( new DateTimeImmutable( '@' . $d['since'] ) )->setTimezone( $tz );
		$end = $from->setTime( (int) substr( $d['hasta'], 0, 2 ), (int) substr( $d['hasta'], 3, 2 ) );
		if ( $end <= $from ) { $end = $end->modify( '+1 day' ); }
		return min( $end->getTimestamp(), $d['since'] + 12 * HOUR_IN_SECONDS );
	}

	/** ¿Le suenan las carreras libres ahora? */
	public static function is_on( int $user_id ): bool {
		$d = self::get( $user_id );
		if ( ! $d['on'] ) { return false; }
		$until = self::until( $d );
		return ! $until || time() < $until;
	}

	/** ¿Esta carrera libre le suena? Disponible y, si pidió «solo mis zonas», de una de sus zonas (o de zona desconocida). */
	public static function wants_offer( int $user_id, WC_Order $order ): bool {
		if ( ! self::is_on( $user_id ) ) { return false; }
		$d = self::get( $user_id );
		if ( ! $d['solo'] || ! $d['zonas'] ) { return true; }
		$zone = self::zone_of( $order );
		return '' === $zone || in_array( $zone, $d['zonas'], true );
	}

	/** El municipio de la entrega ('' si no se sabe). */
	public static function zone_of( WC_Order $order ): string {
		$text = ' ' . strtolower( remove_accents( implode( ' ', array( CVD_Delivery::destination_zone( $order ), $order->get_billing_city(), $order->get_billing_state() ) ) ) ) . ' ';
		$best = '';
		$len = 0;
		foreach ( self::ZONAS as $z ) {
			$k = strtolower( remove_accents( $z ) );
			if ( false !== strpos( $text, ' ' . $k . ' ' ) && strlen( $k ) > $len ) { $best = $z; $len = strlen( $k ); }
		}
		foreach ( self::BARRIOS as $k => $z ) {
			$k = strtolower( remove_accents( $k ) );
			if ( false !== strpos( $text, ' ' . $k . ' ' ) && strlen( $k ) > $len ) { $best = $z; $len = strlen( $k ); }
		}
		return $best;
	}

	/** Para la app (GET hoy). */
	public static function view( int $user_id ): array {
		$d = self::get( $user_id );
		$on = self::is_on( $user_id );
		$until = $on ? self::until( $d ) : 0;
		return array(
			'on'     => $on,
			'legacy' => $d['legacy'],
			'zonas'  => $d['zonas'],
			'solo'   => $d['solo'],
			'hasta'  => $d['hasta'],
			'until'  => $until ? $until * 1000 : 0, // ms, para que la app de Android se apague sola a esa hora
			'untilText' => $until ? wp_date( 'g:i a', $until, new DateTimeZone( 'America/Havana' ) ) : '',
			'todas'  => self::ZONAS,
		);
	}

	/** POST palla/disponible {on?, zonas?, solo?, hasta?} → view. */
	public static function rest( WP_REST_Request $request ): WP_REST_Response {
		$me = CVD_Palla::request_user( $request );
		$d = self::get( $me->ID );
		$on = null !== $request->get_param( 'on' ) ? (bool) rest_sanitize_boolean( $request->get_param( 'on' ) ) : self::is_on( $me->ID );
		if ( null !== $request->get_param( 'zonas' ) ) { $d['zonas'] = array_values( array_intersect( self::ZONAS, array_map( 'sanitize_text_field', (array) $request->get_param( 'zonas' ) ) ) ); }
		if ( null !== $request->get_param( 'solo' ) ) { $d['solo'] = (bool) rest_sanitize_boolean( $request->get_param( 'solo' ) ); }
		if ( null !== $request->get_param( 'hasta' ) ) {
			$h = (string) $request->get_param( 'hasta' );
			$d['hasta'] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $h ) ? $h : '';
		}
		$was = self::is_on( $me->ID ) && ! $d['legacy'];
		$since = $on ? ( $was ? $d['since'] : time() ) : 0;
		// wp_slash + sin \u: update_user_meta quita las barras y «Revolución» quedaba «Revoluciu00f3n» (prueba 29-oct).
		update_user_meta( $me->ID, self::META, wp_slash( wp_json_encode( array( 'on' => $on, 'since' => $since, 'zonas' => $d['zonas'], 'solo' => $d['solo'], 'hasta' => $d['hasta'] ), JSON_UNESCAPED_UNICODE ) ) );
		$response = rest_ensure_response( array( 'ok' => true, 'disp' => self::view( $me->ID ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Para la tienda: quién está disponible ahora (con la app y el botón tocado). → [{name, zonas, until}] */
	public static function available_now(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s", self::META ) ) as $id ) {
			$id = absint( $id );
			if ( ! self::is_on( $id ) ) { continue; }
			$u = get_userdata( $id );
			if ( ! $u ) { continue; }
			$d = self::get( $id );
			$until = self::until( $d );
			$out[] = array( 'name' => (string) $u->display_name, 'zonas' => $d['zonas'], 'until' => $until ? wp_date( 'g:i a', $until, new DateTimeZone( 'America/Havana' ) ) : '' );
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		return $out;
	}
}
