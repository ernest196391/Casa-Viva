<?php

defined( 'ABSPATH' ) || exit;

/**
 * Programa de gestoras (D53–D58, 14-oct): cálculo PURO, sin WordPress ni base de datos.
 *
 * Recibe gestoras, ventas y devoluciones ya leídas (CVD_Programa las lee de WooCommerce) y devuelve
 * lo que gana cada una en el mes: puntos, piedra, red (2 % + 1 %), bono, fondo y alertas. Todo lo que
 * decide dinero está aquí para poder probarlo con casos (artifacts/tests/test-programa-gestoras.php).
 *
 * Reglas (Ernesto, D58 y encargo del 14-oct):
 * - Punto = 1 USD de ganancia limpia de una venta entregada y cobrada: precio Casa Viva − costo − comisión
 *   de la gestora. Línea sin costo o que pierde = 0 puntos.
 * - Red: 2 % (1.er nivel) y 1 % (2.º nivel) del precio Casa Viva de lo que venden las invitadas, 12 meses
 *   desde que entró la invitada. Para cobrarla: 1 venta propia entregada en el mes; para el 2.º nivel,
 *   además, ser Plata ese mes.
 * - Piedras con umbral de puntos del equipo al mes (propio + 2 niveles). Bono solo el mes que se mantiene
 *   (umbral + mínimo propio). Ningún miembro del equipo aporta más del 60 % del umbral.
 * - Fondo = 15 % de la ganancia del equipo. Red primero, bonos con lo que quede. Además, el total del mes
 *   nunca pasa del 15 % de toda la ganancia (cada venta cuenta una vez); si no alcanza, todos cobran el
 *   mismo % (reparto proporcional). Casa Viva nunca pone más.
 */
final class CVD_Programa_Calc {
	/** slug, nombre, puntos del equipo en el mes, bono CUP, mínimo propio en USD vendidos (precio Casa Viva). */
	public const STONES = array(
		array( 'bronce', 'Bronce', 65, 5000, 0 ),
		array( 'plata', 'Plata', 130, 10000, 50 ),
		array( 'oro', 'Oro', 195, 15000, 50 ),
		array( 'platino', 'Platino', 320, 25000, 50 ),
		array( 'zafiro', 'Zafiro', 510, 40000, 100 ),
		array( 'rubi', 'Rubí', 765, 60000, 100 ),
		array( 'esmeralda', 'Esmeralda', 1150, 90000, 100 ),
		array( 'diamante', 'Diamante', 1660, 130000, 150 ),
		array( 'doble-diamante', 'Doble Diamante', 2300, 180000, 150 ),
		array( 'corona', 'Corona', 3190, 250000, 200 ),
	);

	public const DEFAULTS = array(
		'red1_pct'        => 0.02,
		'red2_pct'        => 0.01,
		'red_months'      => 12,
		'fund_pct'        => 0.15,
		'member_cap'      => 0.60,
		'red2_min_stone'  => 'plata',
		'rate_cup'        => 785.0,
		'min_own_sales'   => 1, // ventas propias entregadas en el mes para cobrar red y bono
	);

	/* ---------------- Piezas pequeñas ---------------- */

	/**
	 * Puntos de una línea: precio Casa Viva − costo × unidades − comisión − otros gastos de la línea. Sin costo o pierde = 0.
	 * 3.14.29: $other = gastos fijos de la línea (si cvd_programa_gastos_fijos = si; D74) + premio de la dependienta por un
	 * «extra en tienda» (regla del 15-oct; hoy 0, en espera). Con $other = 0 es la cuenta de D55.
	 */
	public static function line_points( float $base, ?float $unit_cost, int $qty, float $commission, float $other = 0.0 ): float {
		if ( null === $unit_cost || $unit_cost <= 0 ) { return 0.0; }
		return max( 0.0, round( $base - $unit_cost * max( 1, $qty ) - $commission - max( 0.0, $other ), 4 ) );
	}

	/** Puntos de una unidad para enseñarlos en la tienda de la gestora («⭐ N puntos»); null = sin costo. */
	public static function unit_points( float $price, ?float $unit_cost, float $commission, float $other = 0.0 ): ?int {
		if ( null === $unit_cost || $unit_cost <= 0 ) { return null; }
		return (int) floor( max( 0.0, $price - $unit_cost - $commission - max( 0.0, $other ) ) );
	}

	public static function stone_index( string $slug ): int {
		foreach ( self::STONES as $i => $s ) { if ( $s[0] === $slug ) { return $i; } }
		return -1;
	}

	public static function stone( string $slug ): ?array {
		$i = self::stone_index( $slug );
		return $i < 0 ? null : self::stone_row( $i );
	}

	private static function stone_row( int $i ): array {
		$s = self::STONES[ $i ];
		return array( 'slug' => $s[0], 'name' => $s[1], 'points' => $s[2], 'bonus_cup' => $s[3], 'min_own_usd' => $s[4], 'index' => $i );
	}

	/** La más alta de dos piedras ('' = ninguna). */
	public static function best_stone( string $a, string $b ): string {
		return self::stone_index( $a ) >= self::stone_index( $b ) ? $a : $b;
	}

	/** Siguiente piedra por encima de $slug ('' = empieza en Bronce); null si ya es Corona. */
	public static function next_stone( string $slug ): ?array {
		$i = self::stone_index( $slug ) + 1;
		return $i < count( self::STONES ) ? self::stone_row( $i ) : null;
	}

	/**
	 * Puntos que cuentan para una piedra: los suyos completos + los de cada miembro del equipo hasta el
	 * 60 % del umbral (regla «que no dependa de una sola persona»; sus ventas propias no tienen tope).
	 */
	public static function qualifying_points( float $own, array $members_points, int $threshold, float $cap ): float {
		$q = max( 0.0, $own );
		foreach ( $members_points as $p ) { $q += min( max( 0.0, (float) $p ), $cap * $threshold ); }
		return round( $q, 4 );
	}

	/** Piedra del mes: la más alta con umbral cubierto (con el tope del 60 %) y mínimo propio cumplido. */
	public static function month_stone( float $own_points, float $own_sales_usd, int $own_orders, array $members_points, array $params = array() ): string {
		$p = $params + self::DEFAULTS;
		if ( $own_orders < (int) $p['min_own_sales'] ) { return ''; }
		$best = '';
		foreach ( self::STONES as $i => $s ) {
			if ( $own_sales_usd + 1e-9 < $s[4] ) { continue; }
			if ( self::qualifying_points( $own_points, $members_points, $s[2], (float) $p['member_cap'] ) + 1e-9 >= $s[2] ) { $best = $s[0]; }
		}
		return $best;
	}

	/** ¿La venta de $at cae dentro de los N meses desde $joined? (fechas 'Y-m-d H:i:s' en hora de Cuba). */
	public static function within_months( string $joined, string $at, int $months ): bool {
		if ( '' === $joined ) { return true; }
		$start = strtotime( $joined . ' UTC' );
		$end = strtotime( '+' . $months . ' months', $start );
		$t = strtotime( $at . ' UTC' );
		return $t >= $start && $t < $end;
	}

	/**
	 * ¿Autocompra? Core marca «self_order» cuando el pedido lleva el teléfono o el correo de la gestora, pero en
	 * Casa Viva muchas gestoras ponen su propio teléfono en el pedido de su cliente (datos reales de octubre: 19 de
	 * 24 pedidos cobrados con esa marca, todos con el nombre del cliente). Por eso:
	 *  - 'si'       = quien compra o recibe se llama como la gestora (y, si su nombre es de una sola palabra, además
	 *                 coincide el teléfono o el correo) → no cuenta;
	 *  - 'revisar'  = solo coincide el teléfono o el correo → cuenta, con alerta para revisarlo;
	 *  - ''         = nada.
	 */
	public static function self_purchase( string $seller_name, array $buyer_names, bool $contact_match ): string {
		$tok = static function ( string $s ): array {
			$s = strtolower( strtr( $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n' ) ) );
			return array_values( array_filter( preg_split( '/[^a-z0-9]+/', $s ) ?: array(), static fn( $t ) => strlen( $t ) > 1 ) );
		};
		$g = $tok( $seller_name );
		if ( $g ) {
			foreach ( $buyer_names as $name ) {
				$b = $tok( (string) $name );
				if ( ! $b || $b[0] !== $g[0] ) { continue; }
				$more = count( $g ) > 1 && array_intersect( array_slice( $g, 1 ), array_slice( $b, 1 ) );
				if ( $more || ( 1 === count( $g ) && $contact_match ) ) { return 'si'; }
			}
		}
		return $contact_match ? 'revisar' : '';
	}

	/** Fecha de «entregada y cobrada»: última aprobación del historial de la comisión, si no la verificación del dinero, si no la de completado. */
	public static function approved_at( $history, string $cash_verified_at = '', string $completed_at = '' ): string {
		$at = '';
		foreach ( is_array( $history ) ? $history : array() as $h ) {
			if ( is_array( $h ) && 'approved' === ( $h['to'] ?? '' ) && ! empty( $h['at'] ) ) { $at = (string) $h['at']; }
		}
		return $at ?: ( $cash_verified_at ?: $completed_at );
	}

	/* ---------------- Cálculo del mes ---------------- */

	/**
	 * @param string $period   'AAAA-MM'.
	 * @param array  $members  id => ['name', 'inviter' => id|0, 'joined' => 'Y-m-d H:i:s', 'approved' => bool, 'title' => slug].
	 * @param array  $sales    lista de ['order_id', 'owner', 'at' (hora de Cuba), 'status' (approved|paid|…), 'self_order' (no cuenta),
	 *                         'self_check' (cuenta con alerta), 'test',
	 *                         'lines' => [['product_id', 'name', 'qty', 'base', 'cost_unit' (null si no hay), 'commission', 'other' (USD de la línea: gastos fijos si se restan + premio de dependienta; 3.14.29), 'cost_provisional' (bool)]]].
	 * @param array  $reversals devoluciones de pedidos ya cerrados: ['order_id', 'owner', 'points', 'base', 'red' => [['user', 'level', 'amount']]].
	 */
	public static function compute( string $period, array $members, array $sales, array $reversals = array(), array $params = array() ): array {
		$p = $params + self::DEFAULTS;
		$alerts = array();
		$lines = array();
		$people = array();
		foreach ( $members as $id => $m ) {
			$people[ (int) $id ] = array(
				'id' => (int) $id, 'name' => (string) ( $m['name'] ?? '' ), 'inviter' => (int) ( $m['inviter'] ?? 0 ),
				'joined' => (string) ( $m['joined'] ?? '' ), 'approved' => ! empty( $m['approved'] ),
				'own_points' => 0.0, 'own_sales_usd' => 0.0, 'own_orders' => 0, 'own_profit_lines_sin_costo' => 0,
				'red1' => 0.0, 'red2' => 0.0, 'red_lost' => 0.0, 'red_lost_reason' => '',
				'prev_title' => (string) ( $m['title'] ?? '' ),
			);
		}
		$inviter_of = static function ( int $id ) use ( $people ): int {
			$inv = $people[ $id ]['inviter'] ?? 0;
			return ( $inv && $inv !== $id && isset( $people[ $inv ] ) ) ? $inv : 0;
		};

		// 1) Ventas del mes que cuentan.
		$red_raw = array(); // beneficiaria => [nivel => importe]
		$total_points = 0.0; $total_sales = 0.0;
		foreach ( $sales as $s ) {
			$order = (int) ( $s['order_id'] ?? 0 ); $owner = (int) ( $s['owner'] ?? 0 ); $at = (string) ( $s['at'] ?? '' );
			if ( ! isset( $people[ $owner ] ) || substr( $at, 0, 7 ) !== $period ) { continue; }
			if ( ! in_array( (string) ( $s['status'] ?? '' ), array( 'approved', 'paid' ), true ) ) { continue; }
			if ( ! empty( $s['test'] ) ) { continue; }
			if ( ! empty( $s['self_order'] ) ) {
				$alerts[] = array( 'type' => 'autocompra', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ': lo compra o lo recibe la misma gestora; no da puntos ni red.' );
				continue;
			}
			if ( ! empty( $s['self_check'] ) ) {
				$alerts[] = array( 'type' => 'revisar', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ': lleva el teléfono o el correo de la gestora con otro nombre de cliente. Cuenta; revisar si fuera para ella.' );
			}
			$base = 0.0; $points = 0.0; $cost = 0.0; $commission = 0.0; $missing = array(); $losing = array(); $provisional = array();
			foreach ( (array) ( $s['lines'] ?? array() ) as $l ) {
				$lb = (float) ( $l['base'] ?? 0 ); $qty = max( 1, (int) ( $l['qty'] ?? 1 ) ); $lc = (float) ( $l['commission'] ?? 0 );
				$lo = max( 0.0, (float) ( $l['other'] ?? 0 ) ); // 3.14.29: gastos fijos (si se restan) + premio de dependienta
				$unit = isset( $l['cost_unit'] ) && null !== $l['cost_unit'] ? (float) $l['cost_unit'] : null;
				$lp = self::line_points( $lb, $unit, $qty, $lc, $lo );
				$label = (string) ( $l['name'] ?? ( '#' . ( $l['product_id'] ?? '' ) ) );
				if ( null === $unit || $unit <= 0 ) { $missing[] = $label; }
				elseif ( $lb - $unit * $qty - $lc - $lo < 0 ) { $losing[] = $label; }
				elseif ( ! empty( $l['cost_provisional'] ) ) { $provisional[] = $label; }
				$base += $lb; $points += $lp; $commission += $lc; $cost += ( null === $unit ? 0 : $unit * $qty ) + $lo;
			}
			if ( $missing ) { $alerts[] = array( 'type' => 'sin_costo', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ': sin costo cargado (' . implode( ', ', array_slice( $missing, 0, 3 ) ) . '), esas líneas dan 0 puntos.' ); }
			if ( $losing ) { $alerts[] = array( 'type' => 'pierde', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ': se vende perdiendo (' . implode( ', ', array_slice( $losing, 0, 3 ) ) . '), 0 puntos.' ); }
			if ( $provisional ) { $alerts[] = array( 'type' => 'provisional', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ': costo provisional (' . implode( ', ', array_slice( $provisional, 0, 3 ) ) . '); los puntos cambian cuando se cargue el costo real.' ); }
			$people[ $owner ]['own_points'] += $points;
			$people[ $owner ]['own_sales_usd'] += $base;
			$people[ $owner ]['own_orders']++;
			$total_points += $points; $total_sales += $base;
			$lines[] = array( 'kind' => 'venta', 'user' => $owner, 'order' => $order, 'source' => $owner, 'base' => round( $base, 4 ), 'cost' => round( $cost, 4 ), 'commission' => round( $commission, 4 ), 'points' => round( $points, 4 ), 'pct' => 0, 'amount' => 0 );

			// Red de esta venta (las condiciones de cobro se aplican después, con el mes completo).
			$joined = $people[ $owner ]['joined'];
			if ( self::within_months( $joined, $at, (int) $p['red_months'] ) ) {
				$b1 = $inviter_of( $owner );
				if ( $b1 ) {
					$amt = round( $base * (float) $p['red1_pct'], 4 );
					$red_raw[ $b1 ][1][] = array( 'order' => $order, 'source' => $owner, 'base' => $base, 'amount' => $amt );
					$b2 = $inviter_of( $b1 );
					if ( $b2 && $b2 !== $owner ) {
						$amt2 = round( $base * (float) $p['red2_pct'], 4 );
						$red_raw[ $b2 ][2][] = array( 'order' => $order, 'source' => $owner, 'base' => $base, 'amount' => $amt2 );
					}
				}
			}
		}

		// 2) Devoluciones de meses ya cerrados: restan puntos, ventas y red.
		$red_reversal = array();
		foreach ( $reversals as $r ) {
			$owner = (int) ( $r['owner'] ?? 0 ); $order = (int) ( $r['order_id'] ?? 0 );
			$pts = (float) ( $r['points'] ?? 0 ); $base = (float) ( $r['base'] ?? 0 );
			if ( isset( $people[ $owner ] ) ) {
				$people[ $owner ]['own_points'] -= $pts;
				$people[ $owner ]['own_sales_usd'] -= $base;
			}
			$total_points -= $pts; $total_sales -= $base;
			$lines[] = array( 'kind' => 'devolucion', 'user' => $owner, 'order' => $order, 'source' => $owner, 'base' => round( -$base, 4 ), 'cost' => 0, 'commission' => 0, 'points' => round( -$pts, 4 ), 'pct' => 0, 'amount' => 0 );
			foreach ( (array) ( $r['red'] ?? array() ) as $rr ) {
				$u = (int) ( $rr['user'] ?? 0 ); $amt = (float) ( $rr['amount'] ?? 0 );
				if ( ! $u || ! $amt ) { continue; }
				$red_reversal[ $u ] = ( $red_reversal[ $u ] ?? 0 ) + $amt;
				$lines[] = array( 'kind' => 'red_devolucion', 'user' => $u, 'order' => $order, 'source' => $owner, 'base' => 0, 'cost' => 0, 'commission' => 0, 'points' => 0, 'pct' => 0, 'amount' => round( -$amt, 4 ) );
			}
			$alerts[] = array( 'type' => 'devolucion', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ' se anuló después de cerrar su mes: se resta ahora.' );
		}

		// 3) Equipo, piedra del mes y título.
		foreach ( $people as $id => &$me ) {
			$l1 = array(); $l2 = array();
			foreach ( $people as $oid => $o ) { if ( $oid !== $id && $inviter_of( $oid ) === $id ) { $l1[] = $oid; } }
			foreach ( $l1 as $a ) { foreach ( $people as $oid => $o ) { if ( $oid !== $id && $oid !== $a && $inviter_of( $oid ) === $a ) { $l2[] = $oid; } } }
			$contrib = array();
			foreach ( array_merge( $l1, $l2 ) as $oid ) { $contrib[ $oid ] = max( 0.0, round( $people[ $oid ]['own_points'], 4 ) ); }
			$own = max( 0.0, round( $me['own_points'], 4 ) );
			$me['level1'] = $l1; $me['level2'] = $l2; $me['contrib'] = $contrib;
			$me['team_points'] = round( $own + array_sum( $contrib ), 4 );
			$me['stone'] = $me['approved'] ? self::month_stone( $own, max( 0.0, $me['own_sales_usd'] ), $me['own_orders'], array_values( $contrib ), $p ) : '';
			$me['title'] = self::best_stone( $me['prev_title'], $me['stone'] );
			$me['qualifying'] = array();
			$next = self::next_stone( $me['stone'] );
			if ( $next ) {
				$q = self::qualifying_points( $own, array_values( $contrib ), $next['points'], (float) $p['member_cap'] );
				$me['next'] = array( 'slug' => $next['slug'], 'name' => $next['name'], 'points' => $next['points'], 'have' => round( $q, 2 ), 'missing' => round( max( 0, $next['points'] - $q ), 2 ), 'min_own_usd' => $next['min_own_usd'], 'own_usd_missing' => round( max( 0, $next['min_own_usd'] - $me['own_sales_usd'] ), 2 ) );
			} else { $me['next'] = null; }
			// Alerta: un miembro con más del 60 % de los puntos del equipo.
			if ( $me['team_points'] >= self::STONES[0][2] ) {
				foreach ( $contrib as $oid => $pts ) {
					if ( $pts > (float) $p['member_cap'] * $me['team_points'] ) {
						$alerts[] = array( 'type' => 'concentracion', 'user' => $id, 'member' => $oid, 'text' => $me['name'] . ': ' . ( $people[ $oid ]['name'] ?? '#' . $oid ) . ' aporta el ' . round( 100 * $pts / $me['team_points'] ) . ' % de los puntos del equipo (tope para la piedra: 60 %).' );
					}
				}
			}
		}
		unset( $me );

		// 4) Red con sus condiciones: venta propia en el mes; 2.º nivel solo si es Plata o más ese mes.
		$min2 = self::stone_index( (string) $p['red2_min_stone'] );
		foreach ( $people as $id => &$me ) {
			$active = $me['approved'] && $me['own_orders'] >= (int) $p['min_own_sales'];
			foreach ( array( 1, 2 ) as $lvl ) {
				foreach ( $red_raw[ $id ][ $lvl ] ?? array() as $r ) {
					$ok = $active && ( 1 === $lvl || self::stone_index( $me['stone'] ) >= $min2 );
					$kind = 'red' . $lvl;
					if ( $ok ) { $me[ $kind ] += $r['amount']; }
					else {
						$me['red_lost'] += $r['amount'];
						$me['red_lost_reason'] = ! $active ? 'Sin venta propia entregada este mes' : 'El 1 % del 2.º nivel es desde Plata';
						$kind .= '_perdida';
					}
					$lines[] = array( 'kind' => $kind, 'user' => $id, 'order' => $r['order'], 'source' => $r['source'], 'base' => round( $r['base'], 4 ), 'cost' => 0, 'commission' => 0, 'points' => 0, 'pct' => 1 === $lvl ? (float) $p['red1_pct'] : (float) $p['red2_pct'], 'amount' => round( $r['amount'], 4 ) );
				}
			}
			$me['red_reversal'] = round( $red_reversal[ $id ] ?? 0, 4 );
			$me['red_gross'] = round( $me['red1'] + $me['red2'] - $me['red_reversal'], 4 );
			if ( $me['red_gross'] < 0 ) { $alerts[] = array( 'type' => 'red_negativa', 'user' => $id, 'text' => $me['name'] . ': las devoluciones dejan la red en ' . number_format( $me['red_gross'], 2, ',', '.' ) . ' USD. Este mes no cobra red; el resto se revisa a mano.' ); }
		}
		unset( $me );

		// 5) Fondo: por equipo (15 % de su ganancia) y del mes entero (15 % de toda la ganancia, cada venta una vez).
		$rate = max( 1.0, (float) $p['rate_cup'] );
		$red_capped = 0.0; $bonus_capped = 0.0;
		foreach ( $people as $id => &$me ) {
			$me['fund_usd'] = round( (float) $p['fund_pct'] * max( 0.0, $me['team_points'] ), 4 );
			$stone = self::stone( $me['stone'] );
			$me['bonus_cup'] = $stone ? $stone['bonus_cup'] : 0;
			$me['bonus_usd'] = round( $me['bonus_cup'] / $rate, 4 );
			$red = max( 0.0, $me['red_gross'] );
			$me['red_team_capped'] = round( min( $red, $me['fund_usd'] ), 4 );
			$me['bonus_team_capped'] = round( min( $me['bonus_usd'], max( 0.0, $me['fund_usd'] - $me['red_team_capped'] ) ), 4 );
			$red_capped += $me['red_team_capped'];
			$bonus_capped += $me['bonus_team_capped'];
		}
		unset( $me );
		$pool = round( (float) $p['fund_pct'] * max( 0.0, $total_points ), 4 );
		$f_red = $red_capped > $pool && $red_capped > 0 ? $pool / $red_capped : 1.0;
		$left = max( 0.0, $pool - $red_capped * $f_red );
		$f_bonus = $bonus_capped > 0 ? min( 1.0, $left / $bonus_capped ) : 1.0;

		$tot_red = 0.0; $tot_bonus_usd = 0.0; $tot_bonus_cup = 0;
		foreach ( $people as $id => &$me ) {
			$me['red_pay'] = round( $me['red_team_capped'] * $f_red, 2 );
			$me['bonus_pay_usd'] = round( $me['bonus_team_capped'] * $f_bonus, 2 );
			$me['coverage'] = $me['bonus_usd'] > 0 ? min( 1.0, round( ( $me['bonus_team_capped'] * $f_bonus ) / $me['bonus_usd'], 4 ) ) : ( $me['bonus_cup'] ? 0.0 : 1.0 );
			$me['bonus_pay_cup'] = (int) round( $me['bonus_cup'] * $me['coverage'] );
			$me['own_points'] = round( $me['own_points'], 4 );
			$me['own_sales_usd'] = round( $me['own_sales_usd'], 4 );
			$me['red1'] = round( $me['red1'], 4 ); $me['red2'] = round( $me['red2'], 4 ); $me['red_lost'] = round( $me['red_lost'], 4 );
			$tot_red += $me['red_pay']; $tot_bonus_usd += $me['bonus_pay_usd']; $tot_bonus_cup += $me['bonus_pay_cup'];
			if ( $me['bonus_pay_cup'] ) { $lines[] = array( 'kind' => 'bono', 'user' => $id, 'order' => 0, 'source' => $id, 'base' => 0, 'cost' => 0, 'commission' => 0, 'points' => 0, 'pct' => $me['coverage'], 'amount' => $me['bonus_pay_usd'] ); }
		}
		unset( $me );

		return array(
			'period' => $period,
			'params' => $p,
			'totals' => array(
				'sales_usd' => round( $total_sales, 2 ),
				'profit_usd' => round( $total_points, 2 ),
				'pool_usd' => round( $pool, 2 ),
				'red_usd' => round( $tot_red, 2 ),
				'bonus_usd' => round( $tot_bonus_usd, 2 ),
				'bonus_cup' => $tot_bonus_cup,
				'pay_usd' => round( $tot_red + $tot_bonus_usd, 2 ),
				'factor_red' => round( $f_red, 4 ),
				'factor_bonus' => round( $f_bonus, 4 ),
				'people_with_stone' => count( array_filter( $people, static fn( $x ) => '' !== $x['stone'] ) ),
			),
			'people' => $people,
			'lines' => $lines,
			'alerts' => $alerts,
		);
	}

	/** Recibo en texto para pegar o imprimir cuando se pague. */
	public static function receipt( array $person, string $period, string $mode, string $rate_label = '' ): string {
		$stone = self::stone( (string) $person['stone'] );
		$out = array(
			'CASA VIVA · Programa de gestoras',
			'Recibo de ' . $period . ( 'si' === $mode ? '' : ' (PRUEBA: no se paga)' ),
			'Gestora: ' . $person['name'] . ' (#' . $person['id'] . ')',
			'Piedra del mes: ' . ( $stone ? $stone['name'] : 'sin piedra' ) . ' · Título: ' . ( self::stone( (string) $person['title'] )['name'] ?? '—' ),
			'Puntos propios: ' . self::n( $person['own_points'] ) . ' · Puntos del equipo: ' . self::n( $person['team_points'] ),
			'Red 1.er nivel (2 %): ' . self::n( $person['red1'] ) . ' USD',
			'Red 2.º nivel (1 %): ' . self::n( $person['red2'] ) . ' USD',
		);
		if ( ! empty( $person['red_reversal'] ) ) { $out[] = 'Devoluciones: −' . self::n( $person['red_reversal'] ) . ' USD'; }
		$out[] = 'Red a pagar: ' . self::n( $person['red_pay'] ) . ' USD';
		$out[] = 'Bono de piedra: ' . number_format( (int) $person['bonus_pay_cup'], 0, ',', '.' ) . ' CUP' . ( $person['bonus_cup'] && $person['coverage'] < 1 ? ' (' . round( 100 * $person['coverage'] ) . ' % de ' . number_format( (int) $person['bonus_cup'], 0, ',', '.' ) . ', el fondo no alcanzó)' : '' );
		if ( $rate_label ) { $out[] = $rate_label; }
		return implode( "\n", $out );
	}

	private static function n( $v ): string { return number_format( (float) $v, 2, ',', '.' ); }
}
