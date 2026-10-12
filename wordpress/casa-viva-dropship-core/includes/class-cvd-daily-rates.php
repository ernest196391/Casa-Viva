<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tasa del día (3.13.51): cuánto se cobra por cada 1 USD del precio en cada forma de pago.
 * La tienda la publica cada mañana en el grupo General ("FORMAS DE PAGO Y SUS CAMBIOS"):
 * VivaBot la lee y la manda aquí (POST /bot/rates) y la dueña la puede corregir desde su panel
 * (POST /panel/daily-rates). Una sola opción `cvd_daily_rates`; la usan la calculadora de la app
 * de gestoras y el "· 5.460 CUP" que ven las gestoras aprobadas junto a cada precio.
 *
 * Todos los valores se leen igual: lo que paga el cliente por cada 1 USD del precio.
 *   cup 780  → 7 USD = 5.460 CUP        eur 1    → 7 USD = 7 EUR (1:1)
 *   clasica 1.15 → 7 USD = 8,05 USD     zelle 1.04 → 7 USD = 7,28 USD     paypal_pct 4 → +4 %
 * Si falta un valor se queda el anterior. Los precios de la tienda no cambian: es solo información.
 */
final class CVD_Daily_Rates {
	private const OPTION = 'cvd_daily_rates';
	private const LOG = 'cvd_daily_rates_log';
	public const STALE_HOURS = 36;
	// Campo => [mínimo, máximo]: fuera de rango se ignora (un número mal leído no pisa la tasa buena).
	private const FIELDS = array(
		'cup'        => array( 50, 5000 ),
		'eur'        => array( 0.5, 2 ),
		'clasica'    => array( 0.8, 2 ),
		'zelle'      => array( 0.8, 2 ),
		'paypal_pct' => array( 0, 30 ),
	);

	/**
	 * 3.13.64 (12-oct, regla de Ernesto): la comisión de la gestora es en USD; si la cobra en CUP se convierte
	 * con la tasa del DÍA DE LA VENTA. Por eso cada pedido guarda al crearse la tasa CUP de ese momento
	 * (_cvd_cup_rate) y de cuándo es esa tasa (_cvd_cup_rate_at). Nunca se pisa; los pedidos viejos no la tienen.
	 * Caminos de creación: checkout clásico y vales (woocommerce_checkout_order_created) y Store API.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'remember_order_rate' ), 6 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'remember_order_rate' ), 6 );
	}

	public static function remember_order_rate( $order ): void {
		if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( '_cvd_cup_rate', true ) ) { return; }
		$rates = self::get();
		if ( ! $rates['cup'] || $rates['cup'] <= 0 ) { return; }
		$order->update_meta_data( '_cvd_cup_rate', (float) $rates['cup'] );
		$order->update_meta_data( '_cvd_cup_rate_at', gmdate( 'c', $rates['updated_at'] ?: time() ) );
		$order->save();
	}

	/** Tasa CUP guardada en el pedido el día de la venta (3.13.64), o null (pedidos viejos: solo USD). */
	public static function order_rate( WC_Order $order ): ?float {
		$rate = (float) $order->get_meta( '_cvd_cup_rate', true );
		return $rate > 0 ? $rate : null;
	}

	/** "12-oct": día (hora de Cuba) de la tasa guardada en el pedido, o '' si no la tiene. */
	public static function order_rate_day( WC_Order $order ): string {
		$ts = strtotime( (string) $order->get_meta( '_cvd_cup_rate_at', true ) );
		$month = $ts ? (int) wp_date( 'n', $ts ) : 0;
		$names = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
		return $month ? wp_date( 'j', $ts ) . '-' . $names[ $month - 1 ] : '';
	}

	/** 780 → "780"; 780.5 → "780,5". */
	public static function format_rate( float $rate ): string {
		return floor( $rate ) === $rate ? number_format( $rate, 0, ',', '.' ) : rtrim( rtrim( number_format( $rate, 2, ',', '.' ), '0' ), ',' );
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rates = array();
		foreach ( array_keys( self::FIELDS ) as $field ) { $rates[ $field ] = isset( $stored[ $field ] ) && is_numeric( $stored[ $field ] ) ? (float) $stored[ $field ] : null; }
		$rates['updated_at'] = absint( $stored['updated_at'] ?? 0 );
		$rates['source'] = sanitize_key( (string) ( $stored['source'] ?? '' ) );
		$rates['bot_sent_at'] = absint( $stored['bot_sent_at'] ?? 0 );
		return $rates;
	}

	public static function is_stale( ?array $rates = null ): bool {
		$rates = $rates ?? self::get();
		return ! $rates['updated_at'] || time() - $rates['updated_at'] > self::STALE_HOURS * HOUR_IN_SECONDS;
	}

	/** Lo que necesita el navegador (calculadora) y lo que devuelven las puertas del panel y del bot. */
	public static function public_data(): array {
		$r = self::get();
		return array(
			'cup'        => $r['cup'],
			'eur'        => $r['eur'],
			'clasica'    => $r['clasica'],
			'zelle'      => $r['zelle'],
			'paypalPct'  => $r['paypal_pct'],
			'updatedAt'  => $r['updated_at'] ? gmdate( 'c', $r['updated_at'] ) : null,
			'updatedTs'  => $r['updated_at'],
			'source'     => $r['source'],
			'stale'      => self::is_stale( $r ),
			'staleHours' => self::STALE_HOURS,
		);
	}

	/**
	 * Guarda los valores válidos que lleguen (cup, eur, clasica, zelle, paypal_pct o paypalPct) y deja los demás.
	 * $sent_at (solo el bot): hora del mensaje de WhatsApp; si no es más nueva que la última aplicada, no se toca
	 * nada (el bot puede reenviar el mismo mensaje al reiniciarse sin pisar una corrección hecha en el panel).
	 */
	public static function save( array $input, string $source, string $sent_at = '' ) {
		if ( isset( $input['paypalPct'] ) && ! isset( $input['paypal_pct'] ) ) { $input['paypal_pct'] = $input['paypalPct']; }
		$current = self::get();
		$sent_ts = '' !== $sent_at ? (int) strtotime( $sent_at ) : 0;
		if ( 'bot' === $source && $sent_ts && $sent_ts <= $current['bot_sent_at'] ) {
			return array( 'applied' => false, 'replayed' => true, 'rates' => self::public_data() );
		}
		$given = array_filter( array_intersect_key( $input, self::FIELDS ), static fn( $v ): bool => null !== $v && '' !== $v );
		if ( ! $given ) { return new WP_Error( 'cvd_rates_empty', 'No llegó ninguna tasa (cup, eur, clasica, zelle, paypal_pct).', array( 'status' => 422 ) ); }
		$changed = array(); $ignored = array();
		$next = $current;
		foreach ( $given as $field => $raw ) {
			$value = self::number( $raw );
			$range = self::FIELDS[ $field ];
			if ( null === $value || $value < $range[0] || $value > $range[1] ) { $ignored[] = $field; continue; }
			$value = round( $value, 4 );
			if ( $value !== $current[ $field ] ) { $changed[ $field ] = array( $current[ $field ], $value ); }
			$next[ $field ] = $value;
		}
		if ( count( $ignored ) === count( $given ) ) { return new WP_Error( 'cvd_rates_invalid', 'Ninguna tasa es válida: ' . implode( ', ', $ignored ) . '.', array( 'status' => 422 ) ); }
		$next['updated_at'] = time();
		$next['source'] = sanitize_key( $source );
		if ( 'bot' === $source && $sent_ts ) { $next['bot_sent_at'] = $sent_ts; }
		update_option( self::OPTION, $next, false );
		$log = get_option( self::LOG, array() );
		$log = array_slice( array_merge( is_array( $log ) ? $log : array(), array( array( 'at' => gmdate( 'c' ), 'by' => $next['source'], 'sentAt' => $sent_ts ? gmdate( 'c', $sent_ts ) : null, 'changed' => $changed, 'ignored' => $ignored ) ) ), -60 );
		update_option( self::LOG, $log, false );
		return array( 'applied' => true, 'replayed' => false, 'changed' => array_keys( $changed ), 'ignored' => $ignored, 'rates' => self::public_data() );
	}

	public static function recent( int $limit = 10 ): array {
		$log = get_option( self::LOG, array() );
		return array_reverse( array_slice( is_array( $log ) ? $log : array(), -$limit ) );
	}

	/** "780", "1,15", "1.15", "4%" → número. Nada más (ni letras ni miles raros). */
	private static function number( $raw ): ?float {
		if ( is_int( $raw ) || is_float( $raw ) ) { return (float) $raw; }
		$s = str_replace( array( ' ', '%' ), '', (string) $raw );
		$s = str_replace( ',', '.', $s );
		return preg_match( '/^\d+(\.\d+)?$/', $s ) ? (float) $s : null;
	}

	/** CUP de un precio en USD con la tasa del día (entero), o null si aún no hay tasa. */
	public static function cup_for( float $usd ): ?int {
		$cup = self::get()['cup'];
		return $cup && $usd > 0 ? (int) round( $usd * $cup ) : null;
	}

	public static function format_cup( int $cup ): string {
		return number_format( $cup, 0, ',', '.' ) . ' CUP';
	}
}
