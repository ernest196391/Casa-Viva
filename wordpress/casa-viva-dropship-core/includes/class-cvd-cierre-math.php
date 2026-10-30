<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cierre de turno (Pilar 2, 28/29-oct): cuentas puras, sin WordPress, para poder probarlas solas.
 *
 * Mismas reglas que el cuadre del bot (vivabot src/ventas-rules.js `cajonDelDia`): un cobro cuenta en el cajón de
 * quien lo apuntó (devuelto por el mensajero > verificado > recibido en tienda), en el día de trabajo en que se
 * apuntó. El día de trabajo va de las 20:00 de ayer a las 20:00 de hoy, hora de Cuba (CIERRE_HORA = 20).
 * Dinero: USD en centavos y MN (CUP) en pesos enteros, siempre enteros.
 */
final class CVD_Cierre_Math {
	public const USD_BILLS = array( 100, 50, 20, 10, 5, 3, 1 );
	public const MN_BILLS = array( 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 1 );
	public const CLOSE_HOUR = 20;
	public const TZ = 'America/Havana';
	private const NO_COUNT = array( 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft' );
	private const DIGITAL = array( 'zelle', 'usdt' );

	/** Ventana [from, to) del día de trabajo `Y-m-d`, en UTC `Y-m-d H:i:s` (para comparar con los metas de la web). */
	public static function window( string $day ): array {
		$tz = new DateTimeZone( self::TZ );
		$utc = new DateTimeZone( 'UTC' );
		$to = new DateTimeImmutable( $day . ' ' . sprintf( '%02d:00:00', self::CLOSE_HOUR ), $tz );
		$from = $to->modify( '-1 day' );
		return array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/** Día de trabajo (`Y-m-d`) de un instante UTC `Y-m-d H:i:s`: desde las 20:00 ya es el día siguiente. */
	public static function day_of( string $utc ): string {
		$t = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		$local = $t->setTimezone( new DateTimeZone( self::TZ ) );
		return (int) $local->format( 'G' ) >= self::CLOSE_HOUR ? $local->modify( '+1 day' )->format( 'Y-m-d' ) : $local->format( 'Y-m-d' );
	}

	public static function valid_day( string $day ): bool {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $day );
		return $d && $d->format( 'Y-m-d' ) === $day;
	}

	/** 12,5 → 1250 centavos. Acepta números o texto con coma. */
	public static function cents( $value ): int {
		if ( is_string( $value ) ) { $value = str_replace( ',', '.', trim( $value ) ); }
		return is_numeric( $value ) ? (int) round( (float) $value * 100 ) : 0;
	}

	public static function pesos( $value ): int {
		if ( is_string( $value ) ) { $value = str_replace( ',', '.', trim( $value ) ); }
		return is_numeric( $value ) ? (int) round( (float) $value ) : 0;
	}

	/** Columnas de la hoja de papel para un cobro: usd (efectivo), mn (efectivo CUP), trf (transferencia CUP), otros (Zelle, USDT…). */
	public static function columns( string $method, $amount_usd, $amount_cup ): array {
		$usd = self::cents( $amount_usd );
		$cup = self::pesos( $amount_cup );
		$c = array( 'usd' => 0, 'mn' => 0, 'trf' => 0, 'otros' => 0 );
		if ( 'transfer' === $method ) { $c['trf'] = $cup; $c['otros'] = $usd; }
		elseif ( in_array( $method, self::DIGITAL, true ) ) { $c['otros'] = $usd; $c['mn'] = $cup; }
		else { $c['usd'] = $usd; $c['mn'] = $cup; } // cash_usd, cash_cup, mixed, other, allocated
		return $c;
	}

	/**
	 * Un pedido (array plano: id, status, created_gmt, meta[]) → fila del cajón o null si no entró dinero en [from, to).
	 * `clerks` y `admins` son listas de ids de usuario de la web (dependientas y administración).
	 */
	public static function row_from_order( array $o, string $from, string $to, array $clerks, array $admins ): ?array {
		$m = (array) ( $o['meta'] ?? array() );
		if ( ! empty( $m['_cvd_test_order'] ) || in_array( (string) ( $o['status'] ?? '' ), self::NO_COUNT, true ) || 'cancelled' === (string) ( $m['_cvd_operation_status'] ?? '' ) ) { return null; }
		$method = (string) ( $m['_cvd_collection_method'] ?? '' );
		if ( '' === $method ) { return null; }
		$at = (string) ( $m['_cvd_cash_returned_at'] ?? '' );
		$by = (int) ( $m['_cvd_cash_returned_by'] ?? 0 );
		if ( '' === $at && '' !== (string) ( $m['_cvd_cash_verified_at'] ?? '' ) ) { $at = (string) $m['_cvd_cash_verified_at']; $by = (int) ( $m['_cvd_cash_verified_by'] ?? 0 ); }
		if ( '' === $at ) {
			$received = (string) ( $m['_cvd_collection_received_at'] ?? '' );
			$who = (int) ( $m['_cvd_collection_received_by'] ?? 0 );
			$in_store = 'pickup' === (string) ( $m['_cvd_fulfillment_type'] ?? '' ) || in_array( $who, $clerks, true ) || in_array( $who, $admins, true );
			if ( '' !== $received && 'pending_return' !== (string) ( $m['_cvd_cash_status'] ?? '' ) && ( $in_store || 'transfer' === $method || in_array( $method, self::DIGITAL, true ) ) ) { $at = $received; $by = $who; }
		}
		if ( '' === $at || $at < $from || $at >= $to ) { return null; }
		$digital = 'transfer' === $method || in_array( $method, self::DIGITAL, true );
		$clerk = in_array( $by, $clerks, true );
		$store = $clerk || ( $digital && ! in_array( $by, $admins, true ) );
		$group = $store ? 'cajon' : ( in_array( $by, $admins, true ) ? 'admin' : 'otros' );
		$c = self::columns( $method, $m['_cvd_collection_amount_usd'] ?? 0, $m['_cvd_collection_amount_cup'] ?? 0 );
		return array(
			'id' => (int) ( $o['id'] ?? 0 ), 'number' => (string) ( $o['number'] ?? ( $o['id'] ?? '' ) ), 'at' => $at, 'by' => $by, 'group' => $group, 'method' => $method,
			'usd' => $c['usd'], 'mn' => $c['mn'], 'trf' => $c['trf'], 'otros' => $c['otros'], 'mixed' => 'mixed' === $method,
			'account' => (string) ( $m['_cvd_collection_account_name'] ?? '' ), 'pickup' => 'pickup' === (string) ( $m['_cvd_fulfillment_type'] ?? '' ),
			'gestora' => (string) ( $m['gestora_nombre'] ?? '' ), 'messenger' => (int) ( $m['_cvd_messenger_user_id'] ?? 0 ),
		);
	}

	/** Cobros de una persona: los que ella apuntó (por = ella). Los de transferencia/Zelle no entran al cajón de billetes pero se listan. */
	public static function mine( array $rows, int $user_id ): array {
		return array_values( array_filter( $rows, static fn( array $r ): bool => $r['by'] === $user_id && 'cajon' === $r['group'] ) );
	}

	/** Totales de las filas por columna. */
	public static function totals( array $rows ): array {
		$t = array( 'n' => count( $rows ), 'usd' => 0, 'mn' => 0, 'trf' => 0, 'otros' => 0 );
		foreach ( $rows as $r ) { foreach ( array( 'usd', 'mn', 'trf', 'otros' ) as $k ) { $t[ $k ] += $r[ $k ]; } }
		return $t;
	}

	/** Transferencias agrupadas por tarjeta: [ 'Clásica ·2690' => ['n'=>2,'cup'=>…,'usd'=>…], … ] */
	public static function by_card( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			if ( 0 === $r['trf'] && 0 === $r['otros'] && 'transfer' !== $r['method'] ) { continue; }
			if ( ! in_array( $r['method'], array_merge( array( 'transfer' ), self::DIGITAL ), true ) ) { continue; }
			$name = '' !== $r['account'] ? $r['account'] : 'Sin decir la tarjeta';
			$out[ $name ] = $out[ $name ] ?? array( 'name' => $name, 'n' => 0, 'cup' => 0, 'usd' => 0, 'orders' => array() );
			$out[ $name ]['n']++;
			$out[ $name ]['cup'] += $r['trf'];
			$out[ $name ]['usd'] += $r['otros'];
			$out[ $name ]['orders'][] = $r['number'];
		}
		return array_values( $out );
	}

	/** Total de un conteo de billetes {valor: cantidad} por las denominaciones permitidas (ignora lo demás). */
	public static function bills_total( $bills, array $allowed ): int {
		$sum = 0;
		foreach ( (array) $bills as $value => $qty ) {
			$v = (int) $value;
			if ( ! in_array( $v, $allowed, true ) ) { continue; }
			$sum += $v * max( 0, min( 100000, (int) $qty ) );
		}
		return $sum;
	}

	/** Salidas del cajón → [usd_cents, mn_pesos]. Cada una: {why, usd, mn}. */
	public static function outs_total( $outs ): array {
		$usd = 0; $mn = 0;
		foreach ( (array) $outs as $o ) {
			if ( ! is_array( $o ) ) { continue; }
			$usd += max( 0, self::cents( $o['usd'] ?? 0 ) );
			$mn += max( 0, self::pesos( $o['mn'] ?? 0 ) );
		}
		return array( $usd, $mn );
	}

	/**
	 * Cuenta final. fund/out/received/counted en {usd: centavos, mn: pesos}.
	 * expected = fondo + cobros − salidas · diff = contado − esperado. status: ok | short (faltan) | over (sobran).
	 */
	public static function settle( array $fund, array $received, array $outs, array $counted ): array {
		$res = array();
		foreach ( array( 'usd', 'mn' ) as $k ) {
			$expected = $fund[ $k ] + $received[ $k ] - $outs[ $k ];
			$diff = $counted[ $k ] - $expected;
			$res[ $k ] = array( 'fund' => $fund[ $k ], 'received' => $received[ $k ], 'out' => $outs[ $k ], 'expected' => $expected, 'counted' => $counted[ $k ], 'diff' => $diff, 'status' => 0 === $diff ? 'ok' : ( $diff < 0 ? 'short' : 'over' ) );
		}
		$res['ok'] = 'ok' === $res['usd']['status'] && 'ok' === $res['mn']['status'];
		return $res;
	}
}
