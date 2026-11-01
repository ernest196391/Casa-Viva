<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.103 (Ernesto 31-oct, caso de la olla Ariete #web 62 USD): cuentas puras de una devolución.
 * Sin WordPress ni WooCommerce, para poder probarlas (tests/return-plan.test.php).
 *
 *  lines()  → qué líneas y cuántas unidades vuelven (vacío = el pedido entero).
 *  money()  → el dinero devuelto, validado (nunca negativo; con importe hace falta la forma).
 *  note()   → la nota del pedido: quién, desde dónde, qué, cuánto (USD y CUP), cómo y la causa.
 *
 * No decide dinero ni comisiones: el importe lo escribe la tienda y lo aprueba Lennys.
 */
final class CVD_Return_Plan {
	public const METHODS = array( 'cash' => 'en efectivo', 'transfer' => 'por transferencia', 'mixed' => 'parte en efectivo y parte por transferencia', 'none' => '' );

	/** Causa: una frase corta (mínimo 3 letras, máximo 200). '' si no vale. */
	public static function reason( string $raw ): string {
		$reason = trim( (string) preg_replace( '/\s+/u', ' ', $raw ) );
		if ( mb_strlen( $reason ) < 3 ) { return ''; }
		return mb_substr( $reason, 0, 200 );
	}

	/**
	 * $items: [ itemId => ['name' => string, 'qty' => int] ] (las líneas del pedido).
	 * $requested: [ ['itemId' => int, 'qty' => int], … ] o vacío (= todo).
	 * Devuelve ['ok' => bool, 'error' => string, 'full' => bool, 'lines' => [ ['itemId','name','qty'] ]].
	 */
	public static function lines( array $items, array $requested ): array {
		$all = array();
		foreach ( $items as $id => $item ) {
			$qty = max( 0, (int) ( $item['qty'] ?? 0 ) );
			if ( $qty > 0 ) { $all[ (int) $id ] = array( 'itemId' => (int) $id, 'name' => (string) ( $item['name'] ?? '' ), 'qty' => $qty ); }
		}
		if ( ! $all ) { return array( 'ok' => false, 'error' => 'Este pedido no tiene productos que devolver.', 'full' => false, 'lines' => array() ); }
		if ( ! $requested ) { return array( 'ok' => true, 'error' => '', 'full' => true, 'lines' => array_values( $all ) ); }
		$want = array();
		foreach ( $requested as $row ) {
			$id = (int) ( is_array( $row ) ? ( $row['itemId'] ?? 0 ) : 0 );
			$qty = (int) ( is_array( $row ) ? ( $row['qty'] ?? 0 ) : 0 );
			if ( $qty <= 0 ) { continue; }
			if ( ! isset( $all[ $id ] ) ) { return array( 'ok' => false, 'error' => 'Ese producto no está en este pedido.', 'full' => false, 'lines' => array() ); }
			$want[ $id ] = ( $want[ $id ] ?? 0 ) + $qty;
			if ( $want[ $id ] > $all[ $id ]['qty'] ) {
				return array( 'ok' => false, 'error' => sprintf( 'Del producto «%s» el pedido tiene %d: no se pueden devolver más.', $all[ $id ]['name'], $all[ $id ]['qty'] ), 'full' => false, 'lines' => array() );
			}
		}
		if ( ! $want ) { return array( 'ok' => false, 'error' => 'Elige qué producto vuelve y cuántos.', 'full' => false, 'lines' => array() ); }
		$lines = array();
		$full = count( $want ) === count( $all );
		foreach ( $want as $id => $qty ) {
			$lines[] = array( 'itemId' => $id, 'name' => $all[ $id ]['name'], 'qty' => $qty );
			if ( $qty < $all[ $id ]['qty'] ) { $full = false; }
		}
		return array( 'ok' => true, 'error' => '', 'full' => $full, 'lines' => $lines );
	}

	/** ['ok', 'error', 'usd', 'cup', 'method']. Con dinero devuelto hace falta la forma; sin dinero, la forma es 'none'. */
	public static function money( $usd_raw, $cup_raw, string $method ): array {
		$usd = self::amount( $usd_raw );
		$cup = self::amount( $cup_raw );
		if ( null === $usd || null === $cup ) { return array( 'ok' => false, 'error' => 'El dinero devuelto no puede ser negativo ni llevar letras.', 'usd' => 0.0, 'cup' => 0.0, 'method' => 'none' ); }
		$method = strtolower( trim( $method ) );
		if ( $usd <= 0 && $cup <= 0 ) { return array( 'ok' => true, 'error' => '', 'usd' => 0.0, 'cup' => 0.0, 'method' => 'none' ); }
		if ( ! isset( self::METHODS[ $method ] ) || 'none' === $method ) {
			return array( 'ok' => false, 'error' => 'Di cómo se le devolvió el dinero: en efectivo o por transferencia.', 'usd' => $usd, 'cup' => $cup, 'method' => 'none' );
		}
		return array( 'ok' => true, 'error' => '', 'usd' => $usd, 'cup' => $cup, 'method' => $method );
	}

	/** Importe ≥ 0 con 2 decimales (acepta coma). null si es negativo o no es un número. */
	public static function amount( $raw ): ?float {
		if ( null === $raw || '' === $raw || false === $raw ) { return 0.0; }
		$text = str_replace( ',', '.', trim( (string) $raw ) );
		if ( ! is_numeric( $text ) ) { return null; }
		$value = round( (float) $text, 2 );
		return $value < 0 ? null : $value;
	}

	/** «62» / «62,50». */
	public static function fmt( float $value ): string {
		return str_replace( '.', ',', rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) ?: '0' );
	}

	/** «1× Olla de presión eléctrica Ariete digital, 2× Taza». */
	public static function products_text( array $lines ): string {
		return implode( ', ', array_map( static fn( array $l ): string => (int) $l['qty'] . '× ' . $l['name'], $lines ) );
	}

	/**
	 * Nota del pedido. $c: who, where ('' o «la caja (Teléfono 2)»), lines, full, usd, cup, method, reason,
	 * commission ('anulada' | 'ya pagada' | 'sin tocar' | 'sin comisión'), messenger ('se mantiene' | 'anulada').
	 */
	public static function note( array $c ): string {
		$money = ( $c['usd'] > 0 || $c['cup'] > 0 )
			? sprintf( 'Se le devolvió al cliente %s USD + %s CUP %s.', self::fmt( (float) $c['usd'] ), self::fmt( (float) $c['cup'] ), self::METHODS[ $c['method'] ] ?? '' )
			: 'No se le devolvió dinero.';
		$commission = array(
			'anulada' => 'comisión de quien vendió anulada',
			'ya pagada' => 'la comisión de quien vendió ya estaba pagada: descontarla en su próximo pago',
			'sin tocar' => 'devolución parcial: la comisión de quien vendió NO se tocó (lo decide Lennys)',
			'sin comisión' => 'el pedido no tenía comisión',
		)[ $c['commission'] ] ?? 'comisión sin cambios';
		return sprintf(
			'Devolución %sregistrada por %s%s: %s. %s Causa: %s. Stock repuesto; %s; ganancia del mensajero %s.%s',
			$c['full'] ? '' : 'PARCIAL ',
			$c['who'],
			'' !== (string) ( $c['where'] ?? '' ) ? ' desde ' . $c['where'] : '',
			self::products_text( $c['lines'] ),
			$money,
			$c['reason'],
			$commission,
			$c['messenger'],
			( $c['usd'] > 0 || $c['cup'] > 0 ) ? ' Pendiente de que Lennys apruebe el dinero devuelto.' : ''
		);
	}
}
