<?php
/**
 * Pruebas del cálculo del Programa de gestoras (CVD_Programa_Calc). Sin WordPress.
 * Ejecutar: php artifacts/tests/test-programa-gestoras.php   (sale con código 1 si algo falla)
 * Nombres inventados: no son gestoras reales.
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-programa-calc.php';

$fails = 0; $count = 0;
function ok( bool $cond, string $what ): void { global $fails, $count; $count++; if ( ! $cond ) { $fails++; echo "FALLA: $what\n"; } }
function near( $a, $b, string $what, float $eps = 0.011 ): void { ok( abs( (float) $a - (float) $b ) <= $eps, $what . " (esperado $b, salió $a)" ); }
function has_alert( array $r, string $type, int $user = 0 ): bool { foreach ( $r['alerts'] as $a ) { if ( $a['type'] === $type && ( ! $user || ( $a['user'] ?? 0 ) === $user ) ) { return true; } } return false; }

const P = '2026-11';
function member( string $name, int $inviter = 0, string $joined = '2026-10-15 10:00:00', bool $approved = true, string $title = '' ): array {
	return array( 'name' => $name, 'inviter' => $inviter, 'joined' => $joined, 'approved' => $approved, 'title' => $title );
}
/** Venta de una línea: base = precio Casa Viva, cost = costo unitario (null = sin costo), com = comisión. */
function sale( int $order, int $owner, float $base, ?float $cost, float $com, array $extra = array() ): array {
	return $extra + array( 'order_id' => $order, 'owner' => $owner, 'at' => P . '-10 12:00:00', 'status' => 'approved', 'self_order' => false, 'test' => false,
		'lines' => array( array( 'product_id' => 900 + $order, 'name' => 'Producto ' . $order, 'qty' => 1, 'base' => $base, 'cost_unit' => $cost, 'commission' => $com ) ) );
}
$C = 'CVD_Programa_Calc';

// ---------- 1. Puntos por línea ----------
near( $C::line_points( 30, 20, 1, 3 ), 7, 'alfombra 30 − costo 20 − comisión 3 = 7 puntos' );
near( $C::line_points( 30, null, 1, 3 ), 0, 'sin costo = 0 puntos' );
near( $C::line_points( 30, 0, 1, 3 ), 0, 'costo 0 = sin costo = 0 puntos' );
near( $C::line_points( 30, 29, 1, 3 ), 0, 'producto que pierde = 0 puntos (no negativo)' );
near( $C::line_points( 90, 20, 3, 9 ), 21, '3 unidades: 90 − 60 − 9 = 21' );
ok( 7 === $C::unit_points( 30, 20, 3 ), 'unit_points 30/20/3 = 7' );
ok( null === $C::unit_points( 30, null, 3 ), 'unit_points sin costo = null (no se enseña)' );
ok( 0 === $C::unit_points( 30, 29, 3 ), 'unit_points que pierde = 0' );

// ---------- 1b. Autocompra ----------
ok( 'si' === $C::self_purchase( 'Ana María Pérez', array( 'Ana Pérez' ), false ), 'se llama como ella: autocompra' );
ok( 'si' === $C::self_purchase( 'Ána Pérez', array( 'ana perez gomez' ), true ), 'sin tildes ni mayúsculas: autocompra' );
ok( 'revisar' === $C::self_purchase( 'Ana Pérez', array( 'Yunier Romero' ), true ), 'su teléfono con otro cliente: cuenta y se revisa' );
ok( '' === $C::self_purchase( 'Ana Pérez', array( 'Ana Gómez' ), false ), 'otra Ana sin su teléfono: venta normal' );
ok( 'revisar' === $C::self_purchase( 'Neisy', array( 'Neisy Fernández' ), false ) || '' === $C::self_purchase( 'Neisy', array( 'Neisy Fernández' ), false ), 'nombre de una palabra sin su teléfono: no se excluye' );
ok( 'si' === $C::self_purchase( 'Neisy', array( 'Neisy Fernández' ), true ), 'nombre de una palabra + su teléfono: autocompra' );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 5, 1, 200, 120, 10, array( 'self_check' => true ) ) ) );
ok( abs( $r['people'][1]['own_points'] - 70 ) < 0.01 && has_alert( $r, 'revisar', 1 ), 'self_check: cuenta y deja alerta' );

// ---------- 2. Fecha de «entregada y cobrada» ----------
ok( '2026-11-03 10:00:00' === $C::approved_at( array( array( 'to' => 'pending', 'at' => '2026-10-30 09:00:00' ), array( 'to' => 'approved', 'at' => '2026-11-03 10:00:00' ) ) ), 'approved_at toma la aprobación del historial' );
ok( '2026-11-04 08:00:00' === $C::approved_at( array(), '2026-11-04 08:00:00', '2026-11-05 08:00:00' ), 'approved_at sin historial: dinero verificado' );

// ---------- 3. Fondo que alcanza (gestora sola, Bronce) ----------
$m = array( 1 => member( 'Ana' ) );
$r = $C::compute( P, $m, array( sale( 1, 1, 200, 120, 10 ) ) ); // 70 puntos
$a = $r['people'][1];
near( $a['own_points'], 70, 'Ana: 70 puntos propios' );
ok( 'bronce' === $a['stone'], 'Ana: Bronce con 70 puntos' );
near( $a['fund_usd'], 10.5, 'Ana: fondo 15 % de 70 = 10,5 USD' );
ok( 5000 === $a['bonus_pay_cup'], 'Ana: cobra el bono completo de 5.000 CUP (fondo alcanza)' );
near( $a['coverage'], 1, 'Ana: cobertura 100 %' );
near( $r['totals']['pool_usd'], 10.5, 'fondo del mes 10,5' );
ok( $r['totals']['pay_usd'] <= $r['totals']['pool_usd'] + 0.01, 'nunca se paga más que el fondo' );
ok( 'plata' === $a['next']['slug'] && abs( $a['next']['missing'] - 60 ) < 0.01, 'Ana: faltan 60 puntos para Plata' );

// ---------- 4. Fondo que NO alcanza (la red se come el fondo del equipo) ----------
// Ana invitó a Rosa. Rosa vende 600 con poca ganancia (30 puntos). Ana vende 1 con 40 puntos.
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1 ) );
$r = $C::compute( P, $m, array( sale( 10, 2, 600, 510, 60 ), sale( 11, 1, 100, 50, 10 ) ) );
$a = $r['people'][1];
near( $a['team_points'], 70, 'Ana: equipo 70 (40 suyos + 30 de Rosa)' );
ok( 'bronce' === $a['stone'], 'Ana: Bronce (Rosa aporta 30, por debajo del 60 % de 65 = 39)' );
near( $a['red1'], 12, 'Ana: red 2 % de 600 = 12 USD' );
near( $a['fund_usd'], 10.5, 'Ana: fondo del equipo 10,5' );
near( $a['red_pay'], 10.5, 'Ana: la red se paga primero y se queda en el tope del fondo (10,5 de 12)' );
ok( 0 === $a['bonus_pay_cup'], 'Ana: el bono no se cubre (fondo agotado por la red)' );
near( $a['coverage'], 0, 'Ana: cobertura 0 %' );
ok( $r['totals']['pay_usd'] <= $r['totals']['pool_usd'] + 0.01, 'tope global respetado' );

// ---------- 5. Reparto proporcional global (equipos que se solapan) ----------
// Marta → Ana → Rosa. Todas venden con buena ganancia; Marta y Ana tienen bono y red.
$m = array( 1 => member( 'Marta' ), 2 => member( 'Ana', 1 ), 3 => member( 'Rosa', 2 ) );
$sales = array( sale( 20, 1, 300, 200, 30 ), sale( 21, 2, 300, 200, 30 ), sale( 22, 3, 300, 200, 30 ), sale( 23, 3, 300, 200, 30 ) );
$r = $C::compute( P, $m, $sales ); // 70 puntos cada venta → total 280; fondo 42
near( $r['totals']['profit_usd'], 280, 'ganancia del mes 280 (cada venta una vez)' );
near( $r['totals']['pool_usd'], 42, 'fondo del mes 42' );
ok( $r['totals']['pay_usd'] <= 42.01, 'el total pagado no pasa de 42 aunque los equipos se solapen' );
$covs = array(); foreach ( $r['people'] as $x ) { if ( $x['bonus_cup'] > 0 && $x['bonus_team_capped'] >= $x['bonus_usd'] - 0.001 ) { $covs[] = round( $x['coverage'], 3 ); } }
ok( count( array_unique( $covs ) ) <= 1, 'todas las que tienen el bono cubierto por su equipo cobran el mismo %' );
ok( 'plata' === $r['people'][1]['stone'] || 'oro' === $r['people'][1]['stone'], 'Marta llega a Plata u Oro con su equipo' );
near( $r['people'][1]['red2'], 6, 'Marta (Plata o más): 1 % de los 600 de Rosa = 6' );
near( $r['factor_bonus'] ?? $r['totals']['factor_bonus'], 18 / ( 15000 / 785 + 2 * 10000 / 785 ), 'bonos al mismo % para todas: lo que queda (42 − 24 de red) entre 44,59 de bonos', 0.002 );
near( $r['people'][2]['red1'], 12, 'Ana: 2 % de 600 de Rosa = 12' );
near( $r['people'][1]['red1'], 6, 'Marta: 2 % de 300 de Ana = 6' );

// ---------- 6. Regla del 60 % ----------
$m = array( 1 => member( 'Lía' ), 2 => member( 'Bea', 1 ) );
$r = $C::compute( P, $m, array( sale( 30, 1, 30, 15, 5 ), sale( 31, 2, 400, 270, 30 ) ) ); // Lía 10, Bea 100
ok( '' === $r['people'][1]['stone'], 'Lía: sin piedra, Bea sola no puede aportar más del 60 % (10 + 39 < 65)' );
ok( has_alert( $r, 'concentracion', 1 ), 'alerta: un miembro con más del 60 % del equipo' );
$m[3] = member( 'Ceci', 1 );
$r = $C::compute( P, $m, array( sale( 30, 1, 30, 15, 5 ), sale( 31, 2, 400, 270, 30 ), sale( 32, 3, 200, 120, 30 ) ) ); // Ceci 50
ok( 'bronce' === $r['people'][1]['stone'], 'Lía: con dos invitadas sí llega a Bronce (10 + 39 + 39 = 88)' );
ok( '' === $C::month_stone( 10, 30, 1, array( 60, 50 ) ) || 'bronce' === $C::month_stone( 10, 30, 1, array( 60, 50 ) ), 'month_stone devuelve una piedra válida' );
ok( 'bronce' === $C::month_stone( 10, 30, 1, array( 60, 50 ) ), 'Plata no: 10 + 60 + 50 = 120 < 130 y además le falta el mínimo propio de 50 USD' );

// ---------- 7. Mínimo propio ----------
ok( '' === $C::month_stone( 200, 0, 0, array() ), 'sin venta propia no hay piedra del mes' );
ok( 'bronce' === $C::month_stone( 140, 40, 1, array() ), '140 puntos pero 40 USD propios: Bronce (Plata pide 50 USD propios)' );
ok( 'plata' === $C::month_stone( 140, 50, 1, array() ), '140 puntos y 50 USD propios: Plata' );

// ---------- 8. Autocompra, pruebas, pendientes y fuera de mes ----------
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1 ) );
$r = $C::compute( P, $m, array(
	sale( 40, 2, 500, 300, 50, array( 'self_order' => true ) ),
	sale( 41, 2, 500, 300, 50, array( 'test' => true ) ),
	sale( 42, 2, 500, 300, 50, array( 'status' => 'pending' ) ),
	sale( 43, 2, 500, 300, 50, array( 'status' => 'cancelled' ) ),
	sale( 44, 2, 500, 300, 50, array( 'at' => '2026-10-31 23:59:00' ) ),
	sale( 45, 1, 100, 50, 10 ),
) );
near( $r['people'][2]['own_points'], 0, 'Rosa: autocompra, prueba, pendiente, cancelado y otro mes no dan puntos' );
near( $r['people'][1]['red1'], 0, 'Ana: nada de red por esas ventas' );
ok( has_alert( $r, 'autocompra', 2 ), 'alerta de autocompra para Rosa' );

// ---------- 9. Sin costo ----------
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 50, 1, 100, null, 10 ) ) );
near( $r['people'][1]['own_points'], 0, 'producto sin costo: 0 puntos' );
near( $r['people'][1]['own_sales_usd'], 100, 'pero la venta sí cuenta como venta propia (100 USD)' );
ok( has_alert( $r, 'sin_costo', 1 ), 'alerta «sin costo»' );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 51, 1, 100, 95, 10 ) ) );
ok( has_alert( $r, 'pierde', 1 ), 'alerta «se vende perdiendo»' );

// ---------- 10. Red: condiciones ----------
$m = array( 1 => member( 'Marta' ), 2 => member( 'Ana', 1 ), 3 => member( 'Rosa', 2 ) );
$r = $C::compute( P, $m, array( sale( 60, 3, 600, 500, 60 ), sale( 61, 2, 100, 50, 10 ) ) );
near( $r['people'][2]['red1'], 12, 'Ana vendió este mes: cobra el 2 % de Rosa' );
near( $r['people'][1]['red1'] + $r['people'][1]['red2'], 0, 'Marta no vendió este mes: no cobra red' );
near( $r['people'][1]['red_lost'], 8, 'Marta pierde 2 % de Ana (2) + 1 % de Rosa (6) = 8' );
$r = $C::compute( P, $m, array( sale( 60, 3, 600, 500, 60 ), sale( 61, 2, 100, 50, 10 ), sale( 62, 1, 50, 30, 5 ) ) );
ok( '' === $r['people'][1]['stone'] || 'bronce' === $r['people'][1]['stone'], 'Marta con poco equipo: no es Plata' );
near( $r['people'][1]['red2'], 0, 'Marta sin Plata: no cobra el 1 % del 2.º nivel' );
near( $r['people'][1]['red1'], 2, 'Marta sí cobra el 2 % de Ana (100 → 2)' );

// ---------- 11. Ventana de 12 meses ----------
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1, '2025-10-01 09:00:00' ) );
$r = $C::compute( P, $m, array( sale( 70, 2, 600, 300, 60 ), sale( 71, 1, 100, 50, 10 ) ) );
near( $r['people'][1]['red1'], 0, 'Rosa entró hace más de 12 meses: ya no da red' );
ok( $r['people'][1]['team_points'] > 50, 'pero sus puntos siguen contando para el equipo' );
ok( $C::within_months( '2026-01-31 10:00:00', '2027-01-30 10:00:00', 12 ), 'dentro de 12 meses' );
ok( ! $C::within_months( '2026-01-31 10:00:00', '2027-02-01 10:00:00', 12 ), 'fuera de 12 meses' );

// ---------- 12. Devolución de un mes ya cerrado ----------
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1 ) );
$rev = array( array( 'order_id' => 80, 'owner' => 2, 'points' => 40, 'base' => 300, 'red' => array( array( 'user' => 1, 'level' => 1, 'amount' => 6 ) ) ) );
$r = $C::compute( P, $m, array( sale( 81, 2, 300, 200, 30 ), sale( 82, 1, 100, 50, 10 ) ), $rev );
near( $r['people'][2]['own_points'], 30, 'Rosa: 70 este mes − 40 devueltos = 30' );
near( $r['people'][1]['red_gross'], 0, 'Ana: red 6 este mes − 6 de la devolución = 0' );
ok( has_alert( $r, 'devolucion', 2 ), 'alerta de devolución' );
$r = $C::compute( P, $m, array( sale( 82, 1, 100, 50, 10 ) ), $rev );
ok( has_alert( $r, 'red_negativa', 1 ), 'si la devolución deja la red en negativo, alerta y no se paga' );
near( $r['people'][1]['red_pay'], 0, 'red negativa: 0 a pagar' );

// ---------- 13. Título que no se pierde ----------
$r = $C::compute( P, array( 1 => member( 'Ana', 0, '2026-10-01 00:00:00', true, 'oro' ) ), array( sale( 90, 1, 30, 20, 3 ) ) );
ok( 'oro' === $r['people'][1]['title'], 'Ana conserva el título Oro' );
ok( '' === $r['people'][1]['stone'] && 0 === $r['people'][1]['bonus_pay_cup'], 'pero este mes no mantiene piedra: sin bono' );

// ---------- 14. Recibo ----------
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 1, 1, 200, 120, 10 ) ) );
$txt = $C::receipt( $r['people'][1], P, 'sombra' );
ok( false !== strpos( $txt, 'PRUEBA' ) && false !== strpos( $txt, '5.000 CUP' ), 'recibo en modo sombra dice PRUEBA y el bono' );
ok( false === strpos( $C::receipt( $r['people'][1], P, 'si' ), 'PRUEBA' ), 'recibo real sin la palabra PRUEBA' );

// ---------- 15. Invariante con datos al azar: nunca más que el 15 % ----------
mt_srand( 1014 );
for ( $round = 0; $round < 200; $round++ ) {
	$n = mt_rand( 2, 12 ); $m = array();
	for ( $i = 1; $i <= $n; $i++ ) { $m[ $i ] = member( 'G' . $i, $i > 1 && mt_rand( 0, 3 ) ? mt_rand( 1, $i - 1 ) : 0 ); }
	$sales = array();
	for ( $o = 1; $o <= mt_rand( 1, 40 ); $o++ ) {
		$base = mt_rand( 10, 400 ); $cost = mt_rand( 0, 4 ) ? $base * mt_rand( 50, 95 ) / 100 : null;
		$sales[] = sale( $o, mt_rand( 1, $n ), $base, $cost, $base * 0.1 );
	}
	$r = $C::compute( P, $m, $sales, array(), array( 'rate_cup' => mt_rand( 300, 900 ) ) );
	if ( $r['totals']['pay_usd'] > $r['totals']['pool_usd'] + 0.05 ) { ok( false, "ronda $round: se pagaría más que el fondo" ); break; }
	foreach ( $r['people'] as $x ) {
		if ( $x['red_pay'] + $x['bonus_pay_usd'] > $x['fund_usd'] + 0.02 ) { ok( false, "ronda $round: {$x['name']} cobra más que el 15 % de su equipo" ); break 2; }
	}
}
ok( true, 'invariante del fondo en 200 rondas al azar' );

// ---------- 3.14.29: gastos fijos, premio de dependienta y costo provisional ----------
near( $C::line_points( 30, 20, 1, 3, 1.6 ), 5.4, 'con gastos fijos 1,60: 30 − 20 − 3 − 1,60 = 5,40 puntos' );
near( $C::line_points( 30, 20, 1, 3, 0 ), 7, 'sin «otros» sigue siendo la cuenta de D55 (7 puntos)' );
near( $C::line_points( 30, 20, 1, 3, -5 ), 7, '«otros» negativo no suma puntos' );
ok( 5 === $C::unit_points( 30, 20, 3, 1.6 ), 'unit_points con gastos fijos 1,60 = 5' );
$x = sale( 70, 1, 30, 20, 3 ); $x['lines'][0]['other'] = 1.6; $x['lines'][0]['cost_provisional'] = true;
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( $x ) );
near( $r['people'][1]['own_points'], 5.4, 'compute resta «otros» de la línea (gastos fijos + premio de dependienta)' );
ok( has_alert( $r, 'provisional', 1 ), 'alerta «costo provisional» (cuenta igual)' );
$x = sale( 71, 1, 30, 25, 3 ); $x['lines'][0]['other'] = 2.5;
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( $x ) );
ok( has_alert( $r, 'pierde', 1 ) && ! has_alert( $r, 'provisional', 1 ), 'con «otros» puede pasar a «pierde» (30 − 25 − 3 − 2,5 < 0)' );

// ---------- 3.14.29 (D75): red y bono por «Pedir mi pago» ----------
$l = $C::payout_line( 7, '2026-11', 8, 5000, 800, 'bronce', 1700000000 );
ok( $C::PAYOUT_BASE + 7 === $l['id'] && 7 === $l['programa'], 'renglón del programa: id = base + fila del libro (no choca con pedidos)' );
near( $l['usd'], 14.25, 'red 8 USD + bono 5.000 CUP / 800 = 14,25 USD' );
near( $l['cup'], 11400, 'en CUP: 8 × 800 + 5.000 exactos = 11.400', 0.001 );
ok( 2 === count( $l['items'] ) && false !== strpos( $l['items'][1]['name'], 'Bronce' ) && 'noviembre 2026' === $l['date'], 'detalle: red y bono Bronce de noviembre 2026' );
$l2 = $C::payout_line( 8, '2026-11', 0, 3467, 800, 'bronce', 1700000000 );
ok( 1 === count( $l2['items'] ) && abs( $l2['cup'] - 3467 ) < 0.001, 'solo bono a medias (3.467 CUP): un renglón, CUP exactos' );
define( 'CVD_PAYOUT_MATH_STANDALONE', true );
require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-payout-math.php';
$q = CVD_Payout_Math::quote( array( array( 'usd' => 5, 'cup' => 3950, 'rate' => 790 ), $l ), array(), 'cash_cup', 800 );
ok( $q['ok'] && abs( $q['pay'] - 15350 ) < 0.01, 'Pedir mi pago en CUP: comisión 3.950 + programa 11.400 = 15.350 CUP' );
$q = CVD_Payout_Math::quote( array( $l ), array(), 'cash_usd', 800 );
ok( $q['ok'] && abs( $q['pay'] - 14.25 ) < 0.01, 'en efectivo USD: 14,25 USD' );

// ---------- Auditoría 16-oct: fondo sobre la ganancia neta, madrina que compra por su invitada, costo sospechoso ----------
// Ana vende 2 líneas: una gana 70 y otra (liquidación) pierde 60. Puntos de Ana = 70 (no baja de 0 por línea),
// pero Casa Viva solo ganó 10: el fondo del mes es 15 % de 10 = 1,50, no 10,50.
$x = sale( 80, 1, 200, 120, 10 ); $x['lines'][] = array( 'product_id' => 981, 'name' => 'Liquidación', 'qty' => 1, 'base' => 40, 'cost_unit' => 96, 'commission' => 4 );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( $x ) );
near( $r['people'][1]['own_points'], 70, 'neto: Ana sigue con 70 puntos (la línea que pierde da 0, no resta)' );
near( $r['totals']['net_profit_usd'], 10, 'neto: Casa Viva ganó 10 USD en el mes (70 − 60)' );
near( $r['totals']['pool_usd'], 1.5, 'neto: fondo = 15 % de 10 = 1,50 USD (antes 10,50)' );
ok( $r['totals']['pay_usd'] <= 1.51, 'neto: no se paga más que el 15 % de lo que de verdad ganó la tienda' );
ok( has_alert( $r, 'pierde', 1 ), 'neto: sale la alerta «pierde»' );
// Sin líneas que pierden no cambia nada.
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 81, 1, 200, 120, 10 ) ) );
near( $r['totals']['pool_usd'], 10.5, 'sin pérdidas: el fondo sigue siendo 15 % de los puntos (10,50)' );
// Madrina que compra a través de su invitada: la invitada vende normal, la madrina no cobra red de ese pedido.
$m = array( 1 => member( 'Marta López' ), 2 => member( 'Ana Pérez', 1 ) );
$r = $C::compute( P, $m, array( sale( 82, 2, 300, 180, 30, array( 'buyer_names' => array( 'Marta López García', '' ) ) ), sale( 83, 1, 50, 30, 5 ) ) );
near( $r['people'][1]['red1'], 0, 'madrina compradora: 0 de red de ese pedido' );
near( $r['people'][2]['own_points'], 90, 'madrina compradora: la invitada sí suma sus 90 puntos' );
ok( has_alert( $r, 'autocompra_red', 1 ), 'madrina compradora: alerta «autocompra_red»' );
$r = $C::compute( P, $m, array( sale( 84, 2, 300, 180, 30, array( 'buyer_names' => array( 'Yunier Romero' ) ) ), sale( 85, 1, 50, 30, 5 ) ) );
near( $r['people'][1]['red1'], 6, 'cliente normal: la madrina cobra su 2 % (6 USD)' );
// Costo sospechoso: gana más del 55 % del precio en una línea de 10 USD o más.
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 86, 1, 32, 3.2, 3 ) ) );
ok( has_alert( $r, 'ganancia_alta', 1 ), 'costo 3,20 en un producto de 32: alerta «ganancia_alta»' );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 87, 1, 8, 1, 1 ) ) );
ok( ! has_alert( $r, 'ganancia_alta', 1 ), 'producto barato (8 USD) con mucho margen: sin alerta' );
// El invariante del fondo con líneas que pierden, 200 rondas al azar.
mt_srand( 1016 );
for ( $round = 0; $round < 200; $round++ ) {
	$n = mt_rand( 2, 12 ); $m = array();
	for ( $i = 1; $i <= $n; $i++ ) { $m[ $i ] = member( 'G' . $i, $i > 1 && mt_rand( 0, 2 ) ? mt_rand( 1, $i - 1 ) : 0 ); }
	$sales = array(); $net = 0.0;
	for ( $o = 1; $o <= mt_rand( 1, 40 ); $o++ ) {
		$base = mt_rand( 10, 400 ); $cost = $base * mt_rand( 40, 120 ) / 100; $com = $base * 0.1;
		$sales[] = sale( 1000 + $o, mt_rand( 1, $n ), $base, $cost, $com ); $net += $base - $cost - $com;
	}
	$r = $C::compute( P, $m, $sales, array(), array( 'rate_cup' => mt_rand( 300, 900 ) ) );
	if ( $r['totals']['pay_usd'] > 0.15 * max( 0, $net ) + 0.05 ) { ok( false, "ronda $round: se pagaría más que el 15 % de la ganancia neta" ); break; }
}
ok( true, 'invariante del fondo sobre la ganancia neta en 200 rondas con líneas que pierden' );

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
