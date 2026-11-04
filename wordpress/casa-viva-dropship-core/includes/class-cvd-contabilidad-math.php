<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.125 (Ernesto 4-nov, D135): cuentas puras de la contabilidad diaria y mensual. Sin WordPress: se prueban con
 * php tests/contabilidad-math.test.php.
 *
 * Reglas (D135):
 *   - Bajo pedido (SKU MK-) va aparte de la tienda.
 *   - 0,5 % dependientas y 1,5 % Ernesto solo sobre la tienda, sobre su precio sin la subida de las gestoras.
 *   - La comisión del pedido se reparte entre sus líneas según lo que vale cada una.
 *
 * Pedido de entrada: [ 'commission' => USD, 'markup' => USD (subida de la gestora),
 *   'lines' => [ [ 'kind' => tienda|bajo_pedido, 'total' => USD, 'qty' => n, 'cost_unit' => USD|null, 'stamped' => bool ] ] ].
 */
final class CVD_Contabilidad_Math {
	public const TIENDA = 'tienda';
	public const BAJO_PEDIDO = 'bajo_pedido';

	public static function line_kind( string $sku ): string {
		return 0 === strpos( strtoupper( trim( $sku ) ), 'MK-' ) ? self::BAJO_PEDIDO : self::TIENDA;
	}

	public static function r2( float $x ): float {
		return round( $x + 0.0, 2 );
	}

	/** Totales de la tienda y de bajo pedido. $dep_pct y $ern_pct en tanto por uno (0,005 y 0,015). */
	public static function summarize( array $orders, float $dep_pct, float $ern_pct ): array {
		$t = array( 'pedidos' => 0, 'ventas' => 0.0, 'costo' => 0.0, 'comision' => 0.0, 'subida' => 0.0, 'ventas_sin_costo' => 0.0, 'lineas_sin_costo' => 0, 'lineas_costo_de_hoy' => 0 );
		$b = array( 'pedidos' => 0, 'ventas' => 0.0, 'proveedor' => 0.0, 'comision' => 0.0, 'subida' => 0.0, 'ventas_sin_costo' => 0.0, 'lineas_sin_costo' => 0, 'lineas_costo_de_hoy' => 0 );
		foreach ( $orders as $o ) {
			$lines = (array) ( $o['lines'] ?? array() );
			$sum = 0.0;
			foreach ( $lines as $l ) { $sum += (float) ( $l['total'] ?? 0 ); }
			$seen = array();
			foreach ( $lines as $l ) {
				$kind = self::BAJO_PEDIDO === ( $l['kind'] ?? '' ) ? self::BAJO_PEDIDO : self::TIENDA;
				$total = (float) ( $l['total'] ?? 0 );
				$share = $sum > 0 ? $total / $sum : 0.0;
				$cost = isset( $l['cost_unit'] ) && null !== $l['cost_unit'] ? (float) $l['cost_unit'] * max( 0, (int) ( $l['qty'] ?? 1 ) ) : null;
				$acc = self::BAJO_PEDIDO === $kind ? $b : $t;
				$acc['ventas'] += $total;
				$acc['comision'] += (float) ( $o['commission'] ?? 0 ) * $share;
				$acc['subida'] += (float) ( $o['markup'] ?? 0 ) * $share;
				if ( null === $cost ) { $acc['ventas_sin_costo'] += $total; $acc['lineas_sin_costo']++; }
				else {
					$acc[ self::BAJO_PEDIDO === $kind ? 'proveedor' : 'costo' ] += $cost;
					if ( empty( $l['stamped'] ) ) { $acc['lineas_costo_de_hoy']++; }
				}
				if ( self::BAJO_PEDIDO === $kind ) { $b = $acc; } else { $t = $acc; }
				$seen[ $kind ] = true;
			}
			if ( isset( $seen[ self::TIENDA ] ) ) { $t['pedidos']++; }
			if ( isset( $seen[ self::BAJO_PEDIDO ] ) ) { $b['pedidos']++; }
		}
		$base = $t['ventas'] - $t['subida'];
		$t['base_porcentajes'] = $base;
		$t['dependientas'] = $base * $dep_pct;
		$t['ernesto'] = $base * $ern_pct;
		$t['margen'] = $t['ventas'] - $t['costo'] - $t['comision'] - $t['dependientas'] - $t['ernesto'];
		$b['margen'] = $b['ventas'] - $b['proveedor'];
		$b['margen_tras_gestora'] = $b['margen'] - $b['comision'];
		$round = static function ( array $a ): array { return array_map( static fn( $v ) => is_float( $v ) ? self::r2( $v ) : $v, $a ); };
		return array( 'tienda' => $round( $t ), 'bajo_pedido' => $round( $b ) );
	}

	/** 3.14.126: ventas por día (clave 'dia' del pedido) → [ [ 'dia', 'tienda', 'bajo_pedido' ] ] en orden. */
	public static function by_day( array $orders ): array {
		$days = array();
		foreach ( $orders as $o ) {
			$d = (string) ( $o['dia'] ?? '' );
			$days[ $d ] = $days[ $d ] ?? array( 'dia' => $d, 'tienda' => 0.0, 'bajo_pedido' => 0.0 );
			foreach ( (array) ( $o['lines'] ?? array() ) as $l ) {
				$days[ $d ][ self::BAJO_PEDIDO === ( $l['kind'] ?? '' ) ? self::BAJO_PEDIDO : self::TIENDA ] += (float) ( $l['total'] ?? 0 );
			}
		}
		ksort( $days );
		return array_values( array_map( static fn( $r ) => array( 'dia' => $r['dia'], 'tienda' => self::r2( $r['tienda'] ), 'bajo_pedido' => self::r2( $r['bajo_pedido'] ) ), $days ) );
	}

	/** 3.14.126: comisiones pagadas y por pagar (estado del pedido 'commission_status'), repartidas por línea como en summarize. */
	public static function commission_by_status( array $orders ): array {
		$out = array( self::TIENDA => array( 'pagado' => 0.0, 'por_pagar' => 0.0 ), self::BAJO_PEDIDO => array( 'pagado' => 0.0, 'por_pagar' => 0.0 ) );
		foreach ( $orders as $o ) {
			$status = (string) ( $o['commission_status'] ?? '' );
			if ( 'cancelled' === $status ) { continue; }
			$bucket = 'paid' === $status ? 'pagado' : 'por_pagar';
			$lines = (array) ( $o['lines'] ?? array() );
			$sum = 0.0;
			foreach ( $lines as $l ) { $sum += (float) ( $l['total'] ?? 0 ); }
			foreach ( $lines as $l ) {
				$kind = self::BAJO_PEDIDO === ( $l['kind'] ?? '' ) ? self::BAJO_PEDIDO : self::TIENDA;
				$out[ $kind ][ $bucket ] += $sum > 0 ? (float) ( $o['commission'] ?? 0 ) * (float) ( $l['total'] ?? 0 ) / $sum : 0.0;
			}
		}
		foreach ( $out as $k => $v ) { $out[ $k ] = array_map( array( __CLASS__, 'r2' ), $v ); }
		return $out;
	}
}
