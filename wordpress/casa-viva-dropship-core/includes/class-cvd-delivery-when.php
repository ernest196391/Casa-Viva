<?php

defined( 'ABSPATH' ) || exit;

/**
 * Día de entrega (3.13.51, pedido de Lennys 11-oct): un mensajero cogió una carrera creyendo que era
 * para hoy y era para mañana. El pedido guarda el día en `_cvd_delivery_date` (Y-m-d, hora de la web)
 * y la franja en `_cvd_delivery_window` (morning | afternoon). Vacío = "lo antes posible".
 * Aquí se valida lo que llega (checkout, vale pegado en /ventas/) y se dice en palabras igual en
 * todas partes: bot, /ventas/, panel de la dueña, área de gestoras, mensajeros y recibo de WhatsApp.
 */
final class CVD_Delivery_When {
	public const MAX_DAYS = 30;
	private const DAYS = array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' );
	private const DAY_NAMES = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
	private const SLOTS = array( 'morning' => 'por la mañana', 'afternoon' => 'por la tarde', 'evening' => 'por la noche', 'anytime' => 'a cualquier hora' );

	private static function today(): DateTimeImmutable {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	public static function min_date(): string { return self::today()->format( 'Y-m-d' ); }
	public static function max_date(): string { return self::today()->modify( '+' . self::MAX_DAYS . ' days' )->format( 'Y-m-d' ); }

	/**
	 * Lo que escribió alguien → Y-m-d, o '' si no se entiende o está fuera de hoy..+30 días.
	 * Acepta 2026-10-12, 12/10, 12/10/2026, "hoy", "mañana", "pasado mañana" y días de la semana
	 * ("sábado" = el próximo sábado; si hoy es sábado, hoy).
	 */
	public static function normalize( string $raw ): string {
		$s = strtolower( trim( remove_accents( wp_strip_all_tags( $raw ) ) ) );
		if ( '' === $s ) { return ''; }
		$today = self::today();
		$date = null;
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			$date = checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $today->setDate( (int) $m[1], (int) $m[2], (int) $m[3] ) : null;
		} elseif ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?$/', $s, $m ) ) {
			$year = isset( $m[3] ) ? ( strlen( $m[3] ) === 2 ? 2000 + (int) $m[3] : (int) $m[3] ) : (int) $today->format( 'Y' );
			$date = checkdate( (int) $m[2], (int) $m[1], $year ) ? $today->setDate( $year, (int) $m[2], (int) $m[1] ) : null;
			// "5/1" escrito en diciembre: es enero del año que viene.
			if ( $date && ! isset( $m[3] ) && $date < $today ) { $date = $date->modify( '+1 year' ); }
		} elseif ( preg_match( '/\bpasado\s+manana\b/', $s ) ) {
			$date = $today->modify( '+2 days' );
		} elseif ( preg_match( '/^(ahora|ya)\b|\bhoy\b/', $s ) ) {
			$date = $today;
		} elseif ( preg_match( '/(?<!la )\bmanana\b/', $s ) ) { // "mañana" = día; "por la mañana" = franja
			$date = $today->modify( '+1 day' );
		} else {
			foreach ( self::DAY_NAMES as $index => $name ) {
				if ( preg_match( '/\b' . remove_accents( $name ) . '\b/', $s ) ) {
					$diff = ( $index - (int) $today->format( 'w' ) + 7 ) % 7;
					$date = $today->modify( '+' . $diff . ' days' );
					break;
				}
			}
		}
		if ( ! $date ) { return ''; }
		$ymd = $date->format( 'Y-m-d' );
		return $ymd >= self::min_date() && $ymd <= self::max_date() ? $ymd : '';
	}

	/** Mensaje de error para un día escrito a mano (checkout), o '' si vale (vacío también vale). */
	public static function error( string $raw ): string {
		if ( '' === trim( $raw ) ) { return ''; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $raw ) ) || '' === self::normalize( $raw ) ) {
			return 'Elige un día de entrega entre hoy y dentro de ' . self::MAX_DAYS . ' días (o déjalo vacío: lo antes posible).';
		}
		return '';
	}

	/**
	 * Franja de lo que escribió alguien: "tarde" / "2 a 5 pm" → afternoon, "por la mañana" / "9 am" → morning.
	 * "mañana" a secas es el DÍA (lo resuelve normalize), no la franja; "de 9 a 3 pm" cruza las dos: sin franja.
	 */
	public static function window_from_text( string $raw ): string {
		$s = strtolower( remove_accents( $raw ) );
		if ( preg_match( '/\b(\d{1,2})(?::\d{2})?\s*(?:a|-|hasta)\s*(\d{1,2})(?::\d{2})?\s*(?:pm|p\.\s?m)/', $s, $m ) && (int) $m[1] < 12 && (int) $m[1] > (int) $m[2] ) { return ''; }
		if ( preg_match( '/\btarde\b|(?<![a-z])pm\b|p\.\s?m/', $s ) && ! preg_match( '/(?<![a-z])am\b|a\.\s?m|(por|en|de)\s+la\s+manana/', $s ) ) { return 'afternoon'; }
		if ( preg_match( '/(por|en|de)\s+la\s+manana|(?<![a-z])am\b|a\.\s?m|temprano/', $s ) ) { return 'morning'; }
		return '';
	}

	/** Día y franja de un vale pegado: la fecha puede venir en "Fecha" o dentro del "Horario" ("mañana de 9 a 3"). */
	public static function from_voucher( string $date_raw, string $time_raw ): array {
		$date = self::normalize( $date_raw );
		if ( '' === $date && '' !== trim( $time_raw ) ) { $date = self::normalize( $time_raw ); }
		$window = self::window_from_text( $time_raw ) ?: self::window_from_text( $date_raw );
		return array( 'date' => $date, 'window' => $window );
	}

	/** Partes del día: ['date' => Y-m-d|'', 'day' => "mañana sáb 12/10", 'dayName' => "mañana sábado", 'future' => bool, 'slot' => "por la mañana"]. */
	public static function parts( string $date, string $window ): array {
		$slot = self::SLOTS[ sanitize_key( $window ) ] ?? '';
		$out = array( 'date' => '', 'day' => '', 'dayName' => '', 'future' => false, 'slot' => $slot );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return $out; }
		$tz = wp_timezone();
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( ! $d ) { return $out; }
		$today = self::today();
		$diff = (int) round( ( $d->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
		$w = (int) $d->format( 'w' );
		$short = self::DAYS[ $w ] . ' ' . $d->format( 'j/n' );
		$out['date'] = $date;
		$out['future'] = $diff > 0;
		if ( 0 === $diff ) { $out['day'] = 'hoy'; $out['dayName'] = 'hoy'; }
		elseif ( 1 === $diff ) { $out['day'] = 'mañana ' . $short; $out['dayName'] = 'mañana ' . self::DAY_NAMES[ $w ]; }
		elseif ( 2 === $diff ) { $out['day'] = 'pasado mañana ' . $short; $out['dayName'] = 'pasado mañana ' . self::DAY_NAMES[ $w ]; }
		elseif ( $diff < 0 ) { $out['day'] = $short . ' (ya pasó)'; $out['dayName'] = 'el ' . self::DAY_NAMES[ $w ] . ' ' . $d->format( 'j/n' ); }
		else { $out['day'] = $short; $out['dayName'] = 'el ' . self::DAY_NAMES[ $w ] . ' ' . $d->format( 'j/n' ); }
		return $out;
	}

	/** "mañana sáb 12/10 · por la mañana", "hoy · por la tarde", "lo antes posible". */
	public static function label( string $date, string $window ): string {
		$p = self::parts( $date, $window );
		$day = $p['day'] ?: 'lo antes posible';
		return $p['slot'] ? $day . ' · ' . $p['slot'] : $day;
	}

	public static function for_order( WC_Order $order ): array {
		$date = trim( (string) $order->get_meta( '_cvd_delivery_date', true ) );
		$window = sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) );
		return self::parts( $date, $window ) + array( 'label' => self::label( $date, $window ) );
	}

	public static function order_label( WC_Order $order ): string {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return ''; }
		return self::for_order( $order )['label'];
	}
}
