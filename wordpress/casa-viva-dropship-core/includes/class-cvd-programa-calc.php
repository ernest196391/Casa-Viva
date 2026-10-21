<?php

defined( 'ABSPATH' ) || exit;

/**
 * Programa de gestoras (D53–D58, 14-oct): cálculo PURO, sin WordPress ni base de datos.
 *
 * Recibe gestoras, ventas y devoluciones ya leídas (CVD_Programa las lee de WooCommerce) y devuelve
 * lo que gana cada una en el mes: puntos, piedra, red (1 % + 0,5 %, D77), bono, fondo y alertas. Todo lo que
 * decide dinero está aquí para poder probarlo con casos (artifacts/tests/test-programa-gestoras.php).
 *
 * Reglas (Ernesto, D58 y encargo del 14-oct):
 * - Punto = 1 USD de ganancia limpia de una venta entregada y cobrada: precio Casa Viva − costo − comisión
 *   de la gestora. Línea sin costo o que pierde = 0 puntos.
 * - Red: 1 % (1.er nivel) y 0,5 % (2.º nivel; D77, antes 2 % y 1 %) del precio Casa Viva de lo que venden las invitadas, 12 meses
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
		'red1_pct'        => 0.01,  // D77 (Ernesto 16-oct 15:40): 1 % + 0,5 % (antes 2 % + 1 %, que se comía todo el fondo)
		'red2_pct'        => 0.005,
		'carry_usd'       => 0.0,   // D77: deuda del fondo que viene de meses anteriores (devoluciones que no se pudieron restar)
		'red_months'      => 12,
		'fund_pct'        => 0.15,
		'member_cap'      => 0.60,
		'red2_min_stone'  => 'plata',
		'rate_cup'        => 785.0,
		'min_own_sales'   => 1, // ventas propias entregadas en el mes para cobrar red y bono
		'high_margin'     => 0.55, // auditoría 16-oct: línea con ganancia > 55 % del precio = «revisar costo» (¿costo mal puesto?)
		'high_margin_min' => 10.0, // solo líneas de 10 USD o más (lo barato puede ganar mucho de verdad)
		'revisar_repeat'  => 3,    // D79: «revisar» sale suelta solo si la misma gestora repite su teléfono con la misma clienta 3 veces o más
	);

	/** D79: alertas que hacen esperar al cierre automático del mes mientras Lennys no las marque «Revisada». */
	public const REVIEW_TYPES = array( 'ganancia_alta', 'cuenta_compartida', 'equipo' ); // 3.14.45: «equipo» = venta de alguien de la tienda con su enlace

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

	/** Nombre en minúsculas, sin tildes ni signos («Ána  Pérez» → «ana perez»), para comparar clientas. */
	public static function name_key( string $s ): string {
		$s = strtolower( strtr( $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n' ) ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/** D79: las 8 últimas cifras de un teléfono ('' si no hay 8 cifras o son todas iguales). */
	public static function phone_key( string $phone ): string {
		$d = (string) preg_replace( '/\D+/', '', $phone );
		if ( strlen( $d ) < 8 ) { return ''; }
		$d = substr( $d, -8 );
		return preg_match( '/^(\d)\1{7}$/', $d ) ? '' : $d;
	}

	/** D79: tarjeta o cuenta de cobro: los números (12 o más) o un correo. '' si no sirve para comparar. */
	public static function account_key( string $account ): string {
		$d = (string) preg_replace( '/\D+/', '', $account );
		if ( strlen( $d ) >= 12 && ! preg_match( '/^(\d)\1+$/', $d ) ) { return $d; }
		$e = strtolower( trim( $account ) );
		return preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/', $e ) ? $e : '';
	}

	/**
	 * D79: dirección de entrega para comparar: calle y número + municipio, sin tildes, signos ni palabras de relleno
	 * («Calle 23 #456 e/ A y B», «Plaza» → «23 456 a b|plaza»). Tiene que llevar un número (si no, es demasiado vaga
	 * para decir que es la misma casa). '' si no sirve.
	 */
	public static function address_key( string $address, string $city = '' ): string {
		$stop = array( 'calle', 'c', 'e', 'entre', 'y', 'no', 'num', 'numero', 'n', 'apto', 'apt', 'esq', 'esquina', 'el', 'la', 'de', 'del', 'los', 'las', 'edificio', 'edif', 'casa', 'avenida', 'ave', 'av' );
		$tokens = array_values( array_diff( array_filter( explode( ' ', self::name_key( $address ) ), 'strlen' ), $stop ) );
		$a = implode( ' ', $tokens );
		if ( strlen( str_replace( ' ', '', $a ) ) < 4 || ! preg_match( '/\d/', $a ) ) { return ''; }
		return $a . '|' . self::name_key( $city );
	}

	/**
	 * D79 (auditoría 16-oct §4, D53): una invitada (o la invitada de su invitada) que comparte con su madrina el teléfono,
	 * la tarjeta o cuenta de cobro, o la dirección de entrega de sus pedidos. Puede ser la misma persona con dos cuentas
	 * para cobrarse la red. NO bloquea nada: solo avisa (Lennys decide).
	 * @param array $members id => [..., 'inviter', 'test', 'ids' => ['telefono' => [clave => texto], 'tarjeta' => [...], 'direccion' => [...]]].
	 */
	public static function shared_accounts( array $members ): array {
		$labels = array( 'telefono' => 'el teléfono', 'tarjeta' => 'la tarjeta o cuenta de cobro', 'direccion' => 'la dirección de entrega' );
		$inviter_of = static function ( int $id ) use ( $members ): int {
			$inv = (int) ( $members[ $id ]['inviter'] ?? 0 );
			return ( $inv && $inv !== $id && isset( $members[ $inv ] ) ) ? $inv : 0;
		};
		$alerts = array();
		foreach ( $members as $id => $m ) {
			$id = (int) $id;
			if ( ! empty( $m['test'] ) ) { continue; }
			$b1 = $inviter_of( $id );
			$b2 = $b1 ? $inviter_of( $b1 ) : 0;
			foreach ( array( 1 => $b1, 2 => ( $b2 && $b2 !== $id ) ? $b2 : 0 ) as $level => $madrina ) {
				if ( ! $madrina || ! empty( $members[ $madrina ]['test'] ) ) { continue; }
				$found = array();
				foreach ( $labels as $kind => $label ) {
					$mine = (array) ( $m['ids'][ $kind ] ?? array() );
					$hers = (array) ( $members[ $madrina ]['ids'][ $kind ] ?? array() );
					$same = array_intersect_key( $mine, $hers );
					unset( $same[''] );
					if ( $same ) { $found[ $kind ] = $label . ' (' . implode( '; ', array_slice( array_map( 'strval', $same ), 0, 2 ) ) . ')'; }
				}
				if ( ! $found ) { continue; }
				$kinds = array_keys( $found );
				sort( $kinds );
				$alerts[] = array(
					'type' => 'cuenta_compartida', 'user' => $id, 'member' => $madrina, 'level' => $level, 'kinds' => $kinds,
					'key' => 'cuenta_compartida:' . $id . ':' . $madrina . ':' . implode( ',', $kinds ),
					'text' => (string) ( $m['name'] ?? '#' . $id ) . ' y su madrina' . ( 2 === $level ? ' de 2.º nivel' : '' ) . ' ' . (string) ( $members[ $madrina ]['name'] ?? '#' . $madrina )
						. ' comparten ' . implode( ', ', $found ) . '. Puede ser la misma persona con dos cuentas: revisar. No se bloquea nada.',
				);
			}
		}
		return $alerts;
	}

	/**
	 * D79: las alertas «revisar» (la gestora puso su teléfono en el pedido de otra clienta) son lo normal: unas 58 al mes.
	 * Salen sueltas solo cuando la misma gestora lo repite con la misma clienta $repeat veces o más; el resto va en un resumen.
	 * @param array $checks lista de ['owner', 'order', 'name' (de la gestora), 'client' (nombre de la clienta)].
	 */
	public static function group_revisar( array $checks, int $repeat = 3 ): array {
		$groups = array();
		foreach ( $checks as $c ) {
			$k = (int) $c['owner'] . '|' . self::name_key( (string) ( $c['client'] ?? '' ) );
			$groups[ $k ][] = $c;
		}
		$alerts = array(); $rest = 0; $owners = array();
		foreach ( $groups as $g ) {
			if ( count( $g ) >= max( 1, $repeat ) ) {
				$orders = array_map( static fn( $c ) => (int) $c['order'], $g );
				$client = trim( (string) ( $g[0]['client'] ?? '' ) );
				$alerts[] = array(
					'type' => 'revisar', 'user' => (int) $g[0]['owner'], 'order' => $orders[0], 'orders' => $orders, 'count' => count( $g ),
					'text' => (string) ( $g[0]['name'] ?? '' ) . ': ' . count( $g ) . ' pedidos con su teléfono y la misma clienta' . ( '' !== $client ? ' «' . $client . '»' : ' (sin nombre)' )
						. ' (#' . implode( ', #', array_slice( $orders, 0, 8 ) ) . ( count( $orders ) > 8 ? '…' : '' ) . '). Cuentan; revisar si son para ella.',
				);
			} else {
				$rest += count( $g );
				$owners[ (int) $g[0]['owner'] ] = true;
			}
		}
		if ( $rest ) {
			$alerts[] = array(
				'type' => 'revisar_resumen', 'user' => 0, 'order' => 0, 'count' => $rest, 'gestoras' => count( $owners ),
				'text' => $rest . ( 1 === $rest ? ' pedido' : ' pedidos' ) . ' con el teléfono de la gestora (normal): cada una con otra clienta, menos de ' . max( 1, $repeat ) . ' veces la misma (' . count( $owners ) . ( 1 === count( $owners ) ? ' gestora' : ' gestoras' ) . '). Cuentan.',
			);
		}
		return $alerts;
	}

	/** D79: clave estable de una alerta (para marcarla «Revisada»). */
	public static function alert_key( array $a ): string {
		if ( ! empty( $a['key'] ) ) { return (string) $a['key']; }
		return (string) ( $a['type'] ?? '' ) . ':' . (int) ( $a['order'] ?? 0 ) . ':' . (int) ( $a['user'] ?? 0 );
	}

	/**
	 * D79: alertas que hacen esperar al cierre automático y que nadie marcó «Revisada».
	 * @param array $reviewed clave => [...] (opción cvd_programa_revisadas).
	 */
	public static function pending_review( array $alerts, array $reviewed ): array {
		return array_values( array_filter( $alerts, static fn( $a ) => in_array( $a['type'] ?? '', self::REVIEW_TYPES, true ) && ! isset( $reviewed[ self::alert_key( $a ) ] ) ) );
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
	 *                         'self_check' (cuenta con alerta), 'staff_self' (compra del equipo/tienda, 3.14.45), 'staff_check' (alerta «equipo»), 'test',
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
		// Auditoría 16-oct: ganancia NETA del mes (las líneas que pierden restan). Los puntos de cada gestora no bajan de 0
		// por línea (para no desanimar), pero el fondo del mes se calcula sobre lo que de verdad ganó Casa Viva.
		$total_net = 0.0;
		$self_checks = array();
		foreach ( $sales as $s ) {
			$order = (int) ( $s['order_id'] ?? 0 ); $owner = (int) ( $s['owner'] ?? 0 ); $at = (string) ( $s['at'] ?? '' );
			if ( ! isset( $people[ $owner ] ) || substr( $at, 0, 7 ) !== $period ) { continue; }
			if ( ! in_array( (string) ( $s['status'] ?? '' ), array( 'approved', 'paid' ), true ) ) { continue; }
			if ( ! empty( $s['test'] ) ) { continue; }
			if ( ! empty( $s['self_order'] ) ) {
				$alerts[] = array( 'type' => 'autocompra', 'user' => $owner, 'order' => $order, 'text' => 'Pedido #' . $order . ( ! empty( $s['staff_self'] ) ? ': compra del equipo/tienda, sin comisión de gestora; no da puntos ni red.' : ': lo compra o lo recibe la misma gestora; no da puntos ni red.' ) );
				continue;
			}
			if ( ! empty( $s['staff_check'] ) ) {
				// 3.14.45 (Ernesto, 21-oct): alguien del equipo de la tienda vendió con su enlace a un cliente: cobra su comisión y Lennys lo revisa.
				$client = ''; foreach ( (array) ( $s['buyer_names'] ?? array() ) as $bn ) { if ( '' !== trim( (string) $bn ) ) { $client = trim( (string) $bn ); break; } }
				$alerts[] = array( 'type' => 'equipo', 'user' => $owner, 'order' => $order, 'key' => 'equipo:' . $order, 'text' => 'Pedido #' . $order . ': lo vendió ' . $people[ $owner ]['name'] . ' (equipo de la tienda) con su enlace' . ( '' !== $client ? ' a «' . $client . '»' : '' ) . '. Cobra comisión de gestora: márcalo «Revisada» si es un cliente de verdad.' );
			}
			if ( ! empty( $s['self_check'] ) ) {
				// D79: se agrupan al final (group_revisar): sueltas solo si se repite la misma clienta 3 veces o más.
				$client = ''; foreach ( (array) ( $s['buyer_names'] ?? array() ) as $bn ) { if ( '' !== trim( (string) $bn ) ) { $client = trim( (string) $bn ); break; } }
				$self_checks[] = array( 'owner' => $owner, 'order' => $order, 'name' => $people[ $owner ]['name'], 'client' => $client );
			}
			$base = 0.0; $points = 0.0; $cost = 0.0; $commission = 0.0; $missing = array(); $losing = array(); $provisional = array(); $high = array();
			foreach ( (array) ( $s['lines'] ?? array() ) as $l ) {
				$lb = (float) ( $l['base'] ?? 0 ); $qty = max( 1, (int) ( $l['qty'] ?? 1 ) ); $lc = (float) ( $l['commission'] ?? 0 );
				$lo = max( 0.0, (float) ( $l['other'] ?? 0 ) ); // 3.14.29: gastos fijos (si se restan) + premio de dependienta
				$unit = isset( $l['cost_unit'] ) && null !== $l['cost_unit'] ? (float) $l['cost_unit'] : null;
				$lp = self::line_points( $lb, $unit, $qty, $lc, $lo );
				$label = (string) ( $l['name'] ?? ( '#' . ( $l['product_id'] ?? '' ) ) );
				if ( null === $unit || $unit <= 0 ) { $missing[] = $label; }
				elseif ( $lb - $unit * $qty - $lc - $lo < 0 ) { $losing[] = $label; }
				elseif ( ! empty( $l['cost_provisional'] ) ) { $provisional[] = $label; }
				if ( $lb >= (float) $p['high_margin_min'] && $lp > (float) $p['high_margin'] * $lb ) { $high[] = $label; }
				if ( null !== $unit && $unit > 0 ) { $total_net += $lb - $unit * $qty - $lc - $lo; }
				$base += $lb; $points += $lp; $commission += $lc; $cost += ( null === $unit ? 0 : $unit * $qty ) + $lo;
			}
			if ( $high ) { $alerts[] = array( 'type' => 'ganancia_alta', 'user' => $owner, 'order' => $order, 'key' => 'ganancia_alta:' . $order, 'text' => 'Pedido #' . $order . ': gana más del ' . round( 100 * (float) $p['high_margin'] ) . ' % del precio (' . implode( ', ', array_slice( $high, 0, 3 ) ) . '). Revisar que el costo esté bien puesto.' ); }
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
				// Auditoría 16-oct: si quien compra o recibe se llama como la madrina (o la de 2.º nivel), esa madrina no
				// cobra red de ese pedido: sería cobrarse a sí misma por comprar a través de su invitada.
				$buyers = array_values( array_filter( array_map( 'strval', (array) ( $s['buyer_names'] ?? array() ) ) ) );
				$is_buyer = static function ( int $uid ) use ( $buyers, $people ): bool {
					return $buyers && 'si' === self::self_purchase( (string) ( $people[ $uid ]['name'] ?? '' ), $buyers, false );
				};
				if ( $b1 ) {
					$amt = round( $base * (float) $p['red1_pct'], 4 );
					if ( $is_buyer( $b1 ) ) { $alerts[] = array( 'type' => 'autocompra_red', 'user' => $b1, 'order' => $order, 'text' => 'Pedido #' . $order . ': lo compra o lo recibe la madrina; no cobra red de él.' ); }
					else { $red_raw[ $b1 ][1][] = array( 'order' => $order, 'source' => $owner, 'base' => $base, 'amount' => $amt ); }
					$b2 = $inviter_of( $b1 );
					if ( $b2 && $b2 !== $owner ) {
						$amt2 = round( $base * (float) $p['red2_pct'], 4 );
						if ( $is_buyer( $b2 ) ) { $alerts[] = array( 'type' => 'autocompra_red', 'user' => $b2, 'order' => $order, 'text' => 'Pedido #' . $order . ': lo compra o lo recibe la madrina de 2.º nivel; no cobra red de él.' ); }
						else { $red_raw[ $b2 ][2][] = array( 'order' => $order, 'source' => $owner, 'base' => $base, 'amount' => $amt2 ); }
					}
				}
			}
		}

		foreach ( self::group_revisar( $self_checks, (int) $p['revisar_repeat'] ) as $a ) { $alerts[] = $a; } // D79
		foreach ( self::shared_accounts( $members ) as $a ) { $alerts[] = $a; } // D79: cuentas que comparten datos con su madrina

		// 2) Devoluciones de meses ya cerrados: restan puntos, ventas y red.
		$red_reversal = array();
		foreach ( $reversals as $r ) {
			$owner = (int) ( $r['owner'] ?? 0 ); $order = (int) ( $r['order_id'] ?? 0 );
			$pts = (float) ( $r['points'] ?? 0 ); $base = (float) ( $r['base'] ?? 0 );
			if ( isset( $people[ $owner ] ) ) {
				$people[ $owner ]['own_points'] -= $pts;
				$people[ $owner ]['own_sales_usd'] -= $base;
			}
			$total_points -= $pts; $total_sales -= $base; $total_net -= $pts;
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
						$me['red_lost_reason'] = ! $active ? 'Sin venta propia entregada este mes' : 'El ' . self::pct( (float) $p['red2_pct'] ) . ' del 2.º nivel es desde Plata';
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
		// Auditoría 16-oct: el fondo sale de la ganancia NETA (si una línea pierde, resta), nunca de más que los puntos.
		// D77: arrastre. Si las devoluciones dejan el mes en negativo, ese 15 % que faltó restar pasa como deuda al
		// fondo de los meses siguientes hasta cubrirse (así lo pagado de una venta devuelta siempre se recupera).
		$fund_raw = (float) $p['fund_pct'] * min( $total_points, $total_net ) - max( 0.0, (float) $p['carry_usd'] );
		$pool = round( max( 0.0, $fund_raw ), 4 );
		$carry_out = round( max( 0.0, -$fund_raw ), 4 );
		$f_red = $red_capped > $pool && $red_capped > 0 ? $pool / $red_capped : 1.0;
		$left = max( 0.0, $pool - $red_capped * $f_red );
		$f_bonus = $bonus_capped > 0 ? min( 1.0, $left / $bonus_capped ) : 1.0;

		$tot_red = 0.0; $tot_bonus_usd = 0.0; $tot_bonus_cup = 0;
		foreach ( $people as $id => &$me ) {
			// 3.14.30 (D77): todo se redondea HACIA ABAJO (céntimos y CUP enteros), para que la suma nunca pase del fondo ni por 1 céntimo.
			$me['red_pay'] = floor( $me['red_team_capped'] * $f_red * 100 + 1e-6 ) / 100;
			$cov_exact = $me['bonus_usd'] > 0 ? min( 1.0, ( $me['bonus_team_capped'] * $f_bonus ) / $me['bonus_usd'] ) : ( $me['bonus_cup'] ? 0.0 : 1.0 );
			$me['bonus_pay_cup'] = (int) floor( $me['bonus_cup'] * $cov_exact + 1e-6 );
			$me['bonus_pay_usd'] = floor( 100 * $me['bonus_pay_cup'] / $rate + 1e-6 ) / 100;
			$me['coverage'] = round( $cov_exact, 4 );
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
				'net_profit_usd' => round( $total_net, 2 ),
				'pool_usd' => round( $pool, 2 ),
				'carry_in_usd' => round( max( 0.0, (float) $p['carry_usd'] ), 2 ),
				'carry_out_usd' => round( $carry_out, 2 ),
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
	/** Id de un renglón del programa en «Pedir mi pago»: base + id de la fila del libro (nunca choca con un pedido). */
	public const PAYOUT_BASE = 1000000000;

	public const MONTHS = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );

	/**
	 * 3.14.29 (Ernesto, 16-oct, D75): la red y el bono de un cierre de mes como un renglón más de «Pedir mi pago»,
	 * con la misma forma que un pedido (CVD_Payouts::order_line). La red va en USD con la tasa del cierre; el bono es
	 * fijo en CUP (no cambia con la tasa): en CUP se paga exacto y en USD se convierte con la tasa del cierre.
	 */
	public static function payout_line( int $row_id, string $period, float $red_usd, int $bonus_cup, float $rate, string $stone_slug, int $ts ): array {
		$red_usd = round( max( 0.0, $red_usd ), 2 );
		$bonus_cup = max( 0, $bonus_cup );
		$bonus_usd = $rate > 0 ? round( $bonus_cup / $rate, 2 ) : 0.0;
		$month = ( self::MONTHS[ (int) substr( $period, 5, 2 ) ] ?? $period ) . ' ' . substr( $period, 0, 4 );
		$items = array();
		if ( $red_usd > 0 ) { $items[] = array( 'name' => 'Red de tu equipo (' . $month . ')', 'qty' => 1, 'usd' => $red_usd ); }
		if ( $bonus_cup > 0 ) {
			$stone = self::stone( $stone_slug );
			$items[] = array( 'name' => 'Bono ' . ( $stone ? $stone['name'] : 'de piedra' ) . ' (' . $month . ', ' . number_format( $bonus_cup, 0, ',', '.' ) . ' CUP)', 'qty' => 1, 'usd' => $bonus_usd );
		}
		return array(
			'id' => self::PAYOUT_BASE + $row_id, 'programa' => $row_id, 'number' => 'Programa', 'ts' => $ts, 'date' => $month,
			'items' => $items, 'usd' => round( $red_usd + $bonus_usd, 2 ), 'rate' => $rate,
			'cup' => $rate > 0 ? round( $red_usd * $rate + $bonus_cup, 2 ) : 0.0,
			'rate_day' => 'cierre de ' . $month, 'rate_src' => 'programa',
		);
	}

	public static function receipt( array $person, string $period, string $mode, string $rate_label = '' ): string {
		$stone = self::stone( (string) $person['stone'] );
		$out = array(
			'CASA VIVA · Programa de gestoras',
			'Recibo de ' . $period . ( 'si' === $mode ? '' : ' (PRUEBA: no se paga)' ),
			'Gestora: ' . $person['name'] . ' (#' . $person['id'] . ')',
			'Piedra del mes: ' . ( $stone ? $stone['name'] : 'sin piedra' ) . ' · Título: ' . ( self::stone( (string) $person['title'] )['name'] ?? '—' ),
			'Puntos propios: ' . self::n( $person['own_points'] ) . ' · Puntos del equipo: ' . self::n( $person['team_points'] ),
			'Red 1.er nivel (' . self::pct( self::DEFAULTS['red1_pct'] ) . '): ' . self::n( $person['red1'] ) . ' USD',
			'Red 2.º nivel (' . self::pct( self::DEFAULTS['red2_pct'] ) . '): ' . self::n( $person['red2'] ) . ' USD',
		);
		if ( ! empty( $person['red_reversal'] ) ) { $out[] = 'Devoluciones: −' . self::n( $person['red_reversal'] ) . ' USD'; }
		$out[] = 'Red a pagar: ' . self::n( $person['red_pay'] ) . ' USD';
		$out[] = 'Bono de piedra: ' . number_format( (int) $person['bonus_pay_cup'], 0, ',', '.' ) . ' CUP' . ( $person['bonus_cup'] && $person['coverage'] < 1 ? ' (' . round( 100 * $person['coverage'] ) . ' % de ' . number_format( (int) $person['bonus_cup'], 0, ',', '.' ) . ', el fondo no alcanzó)' : '' );
		if ( $rate_label ) { $out[] = $rate_label; }
		return implode( "\n", $out );
	}

	private static function n( $v ): string { return number_format( (float) $v, 2, ',', '.' ); }

	/** «1 %», «0,5 %»: el % de la red para los textos (sale de DEFAULTS, así no se queda un número viejo en pantalla). */
	public static function pct( float $fraction ): string {
		$v = round( 100 * $fraction, 2 );
		return str_replace( '.', ',', (string) ( floor( $v ) == $v ? (int) $v : $v ) ) . ' %'; // phpcs:ignore Universal.Operators.StrictComparisons
	}
}
