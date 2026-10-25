<?php
/**
 * Cuentas de los pagos de comisiones (3.14.23, Ernesto y Lennys 15-oct). Sin WordPress: funciones puras con pruebas
 * en integration/tests/unit/payout-math-test.php (se corren con `php` a secas).
 *
 * Reglas (decisiones de Ernesto, no se cambian aquí):
 *  - La comisión es en USD. En CUP va con la tasa del DÍA DE CADA VENTA (cada pedido con la suya).
 *  - Retiro parcial: la gestora elige cuánto retira; se toman pedidos enteros, del más viejo al más nuevo.
 *  - Las deudas de la gestora con la tienda se descuentan completas del retiro (en CUP o USD). Si una deuda está en
 *    otra moneda que el pago, se convierte con la tasa de HOY (la deuda es de hoy, no de una venta).
 *  - Efectivo CUP se redondea al peso entero; transferencia CUP admite centavos; efectivo USD, centavos.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CVD_PAYOUT_MATH_STANDALONE' ) ) { exit; }

final class CVD_Payout_Math {
	/**
	 * 3.14.68 (Dany, 24-oct): 'mixed' = una parte en efectivo USD y el resto en efectivo CUP (las dos en la tienda). La parte
	 * en CUP es la comisión que queda en USD pasada con la tasa media de esos pedidos (la de cada venta, en proporción) y
	 * de ella se descuentan las deudas (todas en CUP; las de USD con la tasa de hoy). Efectivo CUP: al peso entero.
	 */
	public const METHODS = array( 'transfer', 'cash_cup', 'cash_usd', 'mixed' );

	/** Una línea: comisión USD (en centavos, como la ve la gestora) × tasa de esa venta = CUP (con centavos). */
	public static function line( float $usd, float $rate ): array {
		$usd = round( $usd, 2 );
		return array( 'usd' => $usd, 'rate' => $rate, 'cup' => $rate > 0 ? round( $usd * $rate, 2 ) : 0.0 );
	}

	/**
	 * Tasa CUP de un momento a partir del registro de cambios de la tasa (opción cvd_daily_rates_log):
	 * [ ['at' => ISO, 'changed' => ['cup' => [antes, después]]], ... ]. Para pedidos viejos que no guardaron la tasa.
	 * La última que cambió antes (o en) ese momento; si el pedido es anterior al primer cambio, el valor de «antes»
	 * del primer cambio, o si no lo hay, el primero conocido. null si el registro no tiene ninguna tasa CUP.
	 */
	public static function rate_from_log( array $log, int $ts ): ?float {
		$changes = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['changed']['cup'] ) || ! is_array( $entry['changed']['cup'] ) ) { continue; }
			$at = strtotime( (string) ( $entry['at'] ?? '' ) );
			if ( ! $at ) { continue; }
			$changes[] = array( 'at' => $at, 'from' => $entry['changed']['cup'][0] ?? null, 'to' => $entry['changed']['cup'][1] ?? null );
		}
		if ( ! $changes ) { return null; }
		usort( $changes, static fn( array $a, array $b ): int => $a['at'] <=> $b['at'] );
		$found = null;
		foreach ( $changes as $change ) {
			if ( $change['at'] <= $ts && is_numeric( $change['to'] ) && (float) $change['to'] > 0 ) { $found = (float) $change['to']; }
		}
		if ( null !== $found ) { return $found; }
		$first = $changes[0];
		if ( is_numeric( $first['from'] ) && (float) $first['from'] > 0 ) { return (float) $first['from']; }
		return is_numeric( $first['to'] ) && (float) $first['to'] > 0 ? (float) $first['to'] : null;
	}

	/**
	 * Qué pedidos entran si la gestora quiere retirar $want USD. $lines: [['id' => int, 'usd' => float], ...] del más
	 * viejo al más nuevo. Pedidos enteros: entra cada pedido que todavía cabe (y se sigue mirando los siguientes).
	 * null o un monto ≥ al total = todos.
	 */
	public static function pick( array $lines, ?float $want ): array {
		$total = array_sum( array_map( static fn( array $l ): float => round( (float) $l['usd'], 2 ), $lines ) );
		if ( null === $want || $want >= $total - 0.004 ) { return array_map( static fn( array $l ): int => (int) $l['id'], $lines ); }
		$ids = array(); $sum = 0.0;
		foreach ( $lines as $line ) {
			$usd = round( (float) $line['usd'], 2 );
			if ( $sum + $usd <= $want + 0.004 ) { $ids[] = (int) $line['id']; $sum += $usd; }
		}
		return $ids;
	}

	/**
	 * Lo que se paga. $lines: pedidos elegidos con ['usd','cup','rate'] (de line()); $debts: [['amount','currency'], ...]
	 * abiertas; $method: transfer | cash_cup | cash_usd; $today: tasa CUP de hoy (para convertir deudas).
	 * Devuelve ok, error, usd (bruto), cup (bruto), deduction (en la moneda del pago), pay, currency.
	 */
	public static function quote( array $lines, array $debts, string $method, float $today, float $usd_part = 0.0 ): array {
		if ( 'mixed' === $method ) { return self::quote_mixed( $lines, $debts, $today, $usd_part ); }
		$out = array( 'ok' => false, 'error' => '', 'usd' => 0.0, 'cup' => 0.0, 'deduction' => 0.0, 'debt_cup' => 0.0, 'debt_usd' => 0.0, 'pay' => 0.0, 'currency' => 'cash_usd' === $method ? 'USD' : 'CUP', 'method' => $method, 'missing_rate' => false );
		if ( ! in_array( $method, self::METHODS, true ) ) { $out['error'] = 'Elige cómo quieres cobrar.'; return $out; }
		if ( ! $lines ) { $out['error'] = 'Elige al menos un pedido.'; return $out; }
		foreach ( $lines as $line ) {
			$out['usd'] += round( (float) $line['usd'], 2 );
			$out['cup'] += round( (float) $line['cup'], 2 );
			if ( (float) ( $line['rate'] ?? 0 ) <= 0 ) { $out['missing_rate'] = true; }
		}
		$out['usd'] = round( $out['usd'], 2 );
		$out['cup'] = round( $out['cup'], 2 );
		if ( 'cash_usd' !== $method && $out['missing_rate'] ) { $out['error'] = 'Falta la tasa de un pedido. Cóbralo en USD o avisa a Lennys.'; return $out; }
		$cup_debt = 0.0; $usd_debt = 0.0;
		foreach ( $debts as $debt ) {
			$amount = (float) ( $debt['amount'] ?? 0 );
			if ( $amount <= 0 ) { continue; }
			if ( 'USD' === strtoupper( (string) ( $debt['currency'] ?? '' ) ) ) { $usd_debt += $amount; } else { $cup_debt += $amount; }
		}
		if ( ( 'cash_usd' === $method && $cup_debt > 0 ) || ( 'cash_usd' !== $method && $usd_debt > 0 ) ) {
			if ( $today <= 0 ) { $out['error'] = 'No hay tasa de hoy para convertir la deuda. Avisa a Lennys.'; return $out; }
		}
		$out['debt_cup'] = round( $cup_debt + $usd_debt * $today, 2 );
		// En USD se redondea la deuda hacia arriba al centavo: nunca se descuenta de menos.
		$out['debt_usd'] = round( $usd_debt + ( $cup_debt > 0 && $today > 0 ? ceil( round( $cup_debt / $today * 100, 6 ) ) / 100 : 0.0 ), 2 );
		if ( 'cash_usd' === $method ) {
			$out['deduction'] = $out['debt_usd'];
			$out['pay'] = round( $out['usd'] - $out['debt_usd'], 2 );
		} else {
			$out['deduction'] = $out['debt_cup'];
			$net = $out['cup'] - $out['debt_cup'];
			$out['pay'] = 'cash_cup' === $method ? (float) round( $net, 0 ) : round( $net, 2 );
		}
		if ( $out['pay'] <= 0 ) {
			$out['error'] = 'Lo que retiras no alcanza para la deuda con la tienda (' . ( 'cash_usd' === $method ? self::usd( $out['deduction'] ) : self::cup( $out['deduction'] ) ) . '). Retira más o espera a tener más ventas.';
			return $out;
		}
		$out['ok'] = true;
		return $out;
	}

	/**
	 * 3.14.68: X USD en efectivo + el resto en CUP en efectivo. Devuelve lo mismo que quote() con currency 'USD',
	 * pay = la parte en USD, pay_cup = la parte en CUP ya sin deudas, deduction en CUP y deduction_currency 'CUP'.
	 */
	public static function quote_mixed( array $lines, array $debts, float $today, float $usd_part ): array {
		$out = self::quote( $lines, array(), 'cash_usd', $today ); // suma de pedidos (sin deudas)
		$out['method'] = 'mixed'; $out['currency'] = 'USD'; $out['pay_cup'] = 0.0; $out['deduction_currency'] = 'CUP'; $out['ok'] = false; $out['deduction'] = 0.0;
		if ( '' !== $out['error'] && 0.0 === (float) $out['usd'] ) { return $out; }
		$out['error'] = '';
		if ( $out['missing_rate'] ) { $out['error'] = 'Falta la tasa de un pedido. Cóbralo todo en USD o avisa a Lennys.'; return $out; }
		$usd_part = round( $usd_part, 2 );
		if ( $usd_part <= 0 ) { $out['error'] = 'Escribe cuánto quieres en USD.'; return $out; }
		if ( $usd_part >= $out['usd'] - 0.004 ) { $out['error'] = 'Eso es todo en USD: elige «Efectivo en USD».'; return $out; }
		$cup_debt = 0.0; $usd_debt = 0.0;
		foreach ( $debts as $debt ) {
			$amount = (float) ( $debt['amount'] ?? 0 );
			if ( $amount <= 0 ) { continue; }
			if ( 'USD' === strtoupper( (string) ( $debt['currency'] ?? '' ) ) ) { $usd_debt += $amount; } else { $cup_debt += $amount; }
		}
		if ( $usd_debt > 0 && $today <= 0 ) { $out['error'] = 'No hay tasa de hoy para convertir la deuda. Avisa a Lennys.'; return $out; }
		$rest_usd = round( $out['usd'] - $usd_part, 2 );
		$rest_cup = $out['usd'] > 0 ? $out['cup'] * $rest_usd / $out['usd'] : 0.0;
		$out['debt_cup'] = round( $cup_debt + $usd_debt * $today, 2 );
		$out['deduction'] = $out['debt_cup'];
		$out['pay'] = $usd_part;
		$out['pay_cup'] = (float) round( $rest_cup - $out['debt_cup'], 0 );
		$out['rest_usd'] = $rest_usd;
		if ( $out['pay_cup'] <= 0 ) {
			$out['error'] = $out['debt_cup'] > 0 ? 'La parte en CUP no alcanza para la deuda con la tienda (' . self::cup( $out['debt_cup'] ) . '). Pon menos en USD.' : 'Deja algo para el CUP o elige «Efectivo en USD».';
			return $out;
		}
		$out['ok'] = true;
		return $out;
	}

	/** «40 USD + 520 CUP». */
	public static function mixed_text( float $usd, float $cup ): string {
		return self::usd( $usd ) . ' + ' . self::cup( $cup );
	}

	/** 5854.5 → «5.854,5 CUP»; 5854 → «5.854 CUP»; 5854.25 → «5.854,25 CUP». */
	public static function cup( float $amount ): string {
		return self::number( $amount ) . ' CUP';
	}

	/** 47 → «47 USD»; 47.3 → «47,30 USD». */
	public static function usd( float $amount ): string {
		$amount = round( $amount, 2 );
		return ( floor( $amount ) === $amount ? number_format( $amount, 0, ',', '.' ) : number_format( $amount, 2, ',', '.' ) ) . ' USD';
	}

	public static function money( float $amount, string $currency ): string {
		return 'USD' === strtoupper( $currency ) ? self::usd( $amount ) : self::cup( $amount );
	}

	private static function number( float $amount ): string {
		$amount = round( $amount, 2 );
		if ( floor( $amount ) === $amount ) { return number_format( $amount, 0, ',', '.' ); }
		return rtrim( rtrim( number_format( $amount, 2, ',', '.' ), '0' ), ',' );
	}

	/** Solo dígitos. */
	public static function digits( string $value ): string {
		return (string) preg_replace( '/\D+/', '', $value );
	}

	/** «9205129976543210» → «9205 1299 7654 3210». */
	public static function card_pretty( string $card ): string {
		$d = self::digits( $card );
		return 16 === strlen( $d ) ? trim( chunk_split( $d, 4, ' ' ) ) : $card;
	}

	/** Tarjeta cubana: 16 dígitos. */
	public static function valid_card( string $card ): bool {
		return 16 === strlen( self::digits( $card ) );
	}

	/** Móvil cubano: 8 dígitos que empiezan por 5 (con o sin 53 delante). */
	public static function phone8( string $phone ): string {
		$d = self::digits( $phone );
		if ( 10 === strlen( $d ) && str_starts_with( $d, '53' ) ) { $d = substr( $d, 2 ); }
		return 8 === strlen( $d ) && str_starts_with( $d, '5' ) ? $d : '';
	}

	/**
	 * Enlace firmado de la página «Pagar a…» para un teléfono: «caducidad-teléfono-firma». La firma ata el pago, el
	 * teléfono y la caducidad; cualquier cambio la invalida. $key: secreto del sitio (wp_salt).
	 */
	public static function sign( int $payout_id, string $phone8, int $expires, string $key ): string {
		$sig = substr( hash_hmac( 'sha256', 'cvd-pago|' . $payout_id . '|' . $phone8 . '|' . $expires, $key ), 0, 24 );
		return $expires . '-' . $phone8 . '-' . $sig;
	}

	/** El teléfono de quien recibió el enlace, o '' si la firma no vale o caducó. */
	public static function verify( int $payout_id, string $token, int $now, string $key ): string {
		if ( ! preg_match( '/^(\d{9,11})-(5\d{7})-([a-f0-9]{24})$/', $token, $m ) ) { return ''; }
		if ( (int) $m[1] < $now ) { return ''; }
		return hash_equals( self::sign( $payout_id, $m[2], (int) $m[1], $key ), $token ) ? $m[2] : '';
	}
}
