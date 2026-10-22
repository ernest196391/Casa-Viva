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
// D77 (16-oct): la red pasó a 1 % + 0,5 %. Las secciones 4 y 5 prueban la mecánica «la red se come el fondo» con la red vieja (2 % + 1 %).
const OLD_RED = array( 'red1_pct' => 0.02, 'red2_pct' => 0.01, 'rate_cup' => 785.0 );

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
ok( abs( $r['people'][1]['own_points'] - 70 ) < 0.01 && has_alert( $r, 'revisar_resumen' ) && ! has_alert( $r, 'revisar' ), 'self_check: cuenta y va al resumen (D79: suelta solo si se repite 3 veces)' );

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
$r = $C::compute( P, $m, array( sale( 10, 2, 600, 510, 60 ), sale( 11, 1, 100, 50, 10 ) ), array(), OLD_RED );
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
$r = $C::compute( P, $m, $sales, array(), OLD_RED ); // 70 puntos cada venta → total 280; fondo 42
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
near( $r['people'][2]['red1'], 6, 'Ana vendió este mes: cobra el 1 % de Rosa (600 → 6)' );
near( $r['people'][1]['red1'] + $r['people'][1]['red2'], 0, 'Marta no vendió este mes: no cobra red' );
near( $r['people'][1]['red_lost'], 4, 'Marta pierde 1 % de Ana (1) + 0,5 % de Rosa (3) = 4' );
$r = $C::compute( P, $m, array( sale( 60, 3, 600, 500, 60 ), sale( 61, 2, 100, 50, 10 ), sale( 62, 1, 50, 30, 5 ) ) );
ok( '' === $r['people'][1]['stone'] || 'bronce' === $r['people'][1]['stone'], 'Marta con poco equipo: no es Plata' );
near( $r['people'][1]['red2'], 0, 'Marta sin Plata: no cobra el 0,5 % del 2.º nivel' );
near( $r['people'][1]['red1'], 1, 'Marta sí cobra el 1 % de Ana (100 → 1)' );

// ---------- 11. Ventana de 12 meses ----------
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1, '2025-10-01 09:00:00' ) );
$r = $C::compute( P, $m, array( sale( 70, 2, 600, 300, 60 ), sale( 71, 1, 100, 50, 10 ) ) );
near( $r['people'][1]['red1'], 0, 'Rosa entró hace más de 12 meses: ya no da red' );
ok( $r['people'][1]['team_points'] > 50, 'pero sus puntos siguen contando para el equipo' );
ok( $C::within_months( '2026-01-31 10:00:00', '2027-01-30 10:00:00', 12 ), 'dentro de 12 meses' );
ok( ! $C::within_months( '2026-01-31 10:00:00', '2027-02-01 10:00:00', 12 ), 'fuera de 12 meses' );

// ---------- 12. Devolución de un mes ya cerrado ----------
$m = array( 1 => member( 'Ana' ), 2 => member( 'Rosa', 1 ) );
$rev = array( array( 'order_id' => 80, 'owner' => 2, 'points' => 40, 'base' => 300, 'red' => array( array( 'user' => 1, 'level' => 1, 'amount' => 3 ) ) ) );
$r = $C::compute( P, $m, array( sale( 81, 2, 300, 200, 30 ), sale( 82, 1, 100, 50, 10 ) ), $rev );
near( $r['people'][2]['own_points'], 30, 'Rosa: 70 este mes − 40 devueltos = 30' );
near( $r['people'][1]['red_gross'], 0, 'Ana: red 3 este mes (1 % de 300) − 3 de la devolución = 0' );
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
	if ( $r['totals']['pay_usd'] > $r['totals']['pool_usd'] + 0.00001 ) { ok( false, "ronda $round: se pagaría más que el fondo (ni 1 céntimo, 3.14.30)" ); break; }
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
near( $r['people'][1]['red1'], 3, 'cliente normal: la madrina cobra su 1 % (3 USD)' );
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
	if ( $r['totals']['pay_usd'] > 0.15 * max( 0, $net ) + 0.00001 ) { ok( false, "ronda $round: se pagaría más que el 15 % de la ganancia neta" ); break; }
}
ok( true, 'invariante del fondo sobre la ganancia neta en 200 rondas con líneas que pierden' );

// ---------- D77 (3.14.30): red 1 % + 0,5 %, arrastre de devoluciones ----------
ok( 0.01 === $C::DEFAULTS['red1_pct'] && 0.005 === $C::DEFAULTS['red2_pct'], 'D77: la red por defecto es 1 % + 0,5 %' );
ok( '1 %' === $C::pct( 0.01 ) && '0,5 %' === $C::pct( 0.005 ) && '2 %' === $C::pct( 0.02 ), 'textos del %: «1 %», «0,5 %», «2 %»' );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 1, 1, 200, 120, 10 ) ) );
ok( false !== strpos( $C::receipt( $r['people'][1], P, 'si' ), 'Red 1.er nivel (1 %)' ), 'el recibo dice 1 %' );
// Mes 2: se devuelve todo lo de un mes ya pagado (280 puntos) y casi no se vende (14 puntos): el mes queda en negativo.
$m = array( 1 => member( 'Ana' ) );
$rev = array( array( 'order_id' => 500, 'owner' => 1, 'points' => 280, 'base' => 1200, 'red' => array() ) );
$r = $C::compute( P, $m, array( sale( 501, 1, 60, 40, 6 ) ), $rev );
near( $r['totals']['pool_usd'], 0, 'arrastre: el mes de la devolución no paga nada (fondo 0)' );
near( $r['totals']['carry_out_usd'], 39.9, 'arrastre: queda una deuda de 15 % × (280 − 14) = 39,90 USD' );
// Mes 3: vende normal (210 puntos → fondo 31,50): se descuenta la deuda entera y aún sobra deuda.
$r3 = $C::compute( P, $m, array( sale( 502, 1, 300, 200, 30 ), sale( 503, 1, 300, 200, 30 ), sale( 504, 1, 300, 200, 30 ) ), array(), array( 'carry_usd' => 39.9 ) );
near( $r3['totals']['carry_in_usd'], 39.9, 'arrastre: entra la deuda de 39,90' );
near( $r3['totals']['pool_usd'], 0, 'arrastre: 31,50 − 39,90 → fondo 0 este mes' );
near( $r3['totals']['carry_out_usd'], 8.4, 'arrastre: queda 8,40 de deuda para el mes siguiente' );
ok( 0 === $r3['people'][1]['bonus_pay_cup'], 'arrastre: este mes no hay bono (todo va a cubrir lo devuelto)' );
// Mes 4: con 210 puntos otra vez: fondo 31,50 − 8,40 = 23,10.
$r4 = $C::compute( P, $m, array( sale( 505, 1, 300, 200, 30 ), sale( 506, 1, 300, 200, 30 ), sale( 507, 1, 300, 200, 30 ) ), array(), array( 'carry_usd' => 8.4 ) );
near( $r4['totals']['pool_usd'], 23.1, 'arrastre: fondo 31,50 − 8,40 = 23,10' );
near( $r4['totals']['carry_out_usd'], 0, 'arrastre: deuda cubierta' );
ok( $r4['totals']['pay_usd'] <= 23.11, 'arrastre: se paga como mucho el fondo que queda' );
// Sin deuda no cambia nada.
$r = $C::compute( P, $m, array( sale( 508, 1, 300, 200, 30 ) ) );
near( $r['totals']['carry_out_usd'], 0, 'sin devoluciones no hay deuda' );
near( $r['totals']['pool_usd'], 10.5, 'sin deuda el fondo es el de siempre (15 % de 70)' );

// ---------- D79 (3.14.32): cuentas que comparten datos con su madrina, «revisar» agrupadas, cierre que espera ----------
ok( '53870260' === $C::phone_key( '+53 5387 0260' ) && '53870260' === $C::phone_key( '5387-0260' ), 'teléfono: 8 últimas cifras, con o sin +53' );
ok( '' === $C::phone_key( '1234' ) && '' === $C::phone_key( '00000000' ), 'teléfono corto o de ceros: no se compara' );
ok( '9200123412341234' === $C::account_key( '9200 1234 1234 1234' ) && 'ana@x.com' === $C::account_key( ' Ana@X.com ' ) && '' === $C::account_key( '1234' ), 'tarjeta: los números (12 o más) o un correo' );
ok( $C::address_key( 'Calle 23 #456 e/ A y B', 'Plaza' ) === $C::address_key( 'calle 23 No. 456 entre a y b', 'plaza' ), 'dirección: igual sin «calle», «#», «No.», «entre», mayúsculas' );
ok( '' === $C::address_key( 'Vedado', 'Plaza' ), 'dirección sin número: demasiado vaga, no se compara' );
ok( $C::address_key( 'Calle 23 #456', 'Plaza' ) !== $C::address_key( 'Calle 23 #456', 'Boyeros' ), 'dirección: el municipio cuenta' );
$ids = static fn( array $tel = array(), array $card = array(), array $dir = array() ): array => array( 'telefono' => $tel, 'tarjeta' => $card, 'direccion' => $dir );
// Marta (1) invitó a Ana (2); Ana invitó a Rosa (3). Lola (4) sin madrina.
$m = array(
	1 => member( 'Marta' ) + array( 'ids' => $ids( array( '53870260' => '…0260' ), array( '9200123412341234' => '…1234' ), array( '23 456|plaza' => 'Calle 23 #456, Plaza' ) ) ),
	2 => member( 'Ana', 1 ) + array( 'ids' => $ids( array( '53870260' => '…0260' ) ) ),
	3 => member( 'Rosa', 2 ) + array( 'ids' => $ids( array(), array( '9200123412341234' => '…1234' ), array( '23 456|plaza' => 'Calle 23 #456, Plaza' ) ) ),
	4 => member( 'Lola' ) + array( 'ids' => $ids( array( '53870260' => '…0260' ) ) ),
);
$r = $C::compute( P, $m, array() );
$cc = array_values( array_filter( $r['alerts'], static fn( $a ) => 'cuenta_compartida' === $a['type'] ) );
ok( 2 === count( $cc ), 'cuenta_compartida: 2 alertas (Ana con Marta; Rosa con Marta de 2.º nivel): ' . count( $cc ) );
ok( 2 === $cc[0]['user'] && 1 === $cc[0]['member'] && 1 === $cc[0]['level'] && array( 'telefono' ) === $cc[0]['kinds'], 'Ana y Marta comparten el teléfono (1.er nivel)' );
ok( 3 === $cc[1]['user'] && 1 === $cc[1]['member'] && 2 === $cc[1]['level'] && array( 'direccion', 'tarjeta' ) === $cc[1]['kinds'], 'Rosa y Marta (2.º nivel) comparten tarjeta y dirección' );
ok( false !== strpos( $cc[1]['text'], 'madrina de 2.º nivel' ) && false !== strpos( $cc[1]['text'], '…1234' ) && false === strpos( $cc[1]['text'], '9200123412341234' ), 'el texto dice «madrina de 2.º nivel» y solo las 4 últimas cifras de la tarjeta' );
ok( 'cuenta_compartida:3:1:direccion,tarjeta' === $C::alert_key( $cc[1] ), 'clave estable para marcarla «Revisada»' );
ok( ! has_alert( $r, 'cuenta_compartida', 4 ), 'Lola tiene el mismo teléfono pero no es invitada de nadie: sin alerta (solo con su madrina)' );
$m[2]['test'] = true;
ok( ! has_alert( $C::compute( P, $m, array() ), 'cuenta_compartida', 2 ), 'cuenta de prueba: sin alerta' );
unset( $m[2]['test'] );
// No bloquea nada: con la misma tarjeta, Ana sigue cobrando su venta y Marta su red.
$m[2]['ids']['tarjeta'] = array( '9200123412341234' => '…1234' );
$r = $C::compute( P, $m, array( sale( 700, 2, 300, 200, 30 ), sale( 701, 1, 60, 40, 6 ) ) );
ok( has_alert( $r, 'cuenta_compartida', 2 ) && abs( $r['people'][2]['own_points'] - 70 ) < 0.01 && abs( $r['people'][1]['red1'] - 3 ) < 0.01, 'cuenta_compartida no bloquea: Ana 70 puntos y Marta su red de 3 USD' );
// Sin datos (ids vacíos) no sale nada.
ok( ! has_alert( $C::compute( P, array( 1 => member( 'Marta' ), 2 => member( 'Ana', 1 ) ), array() ), 'cuenta_compartida' ), 'sin datos no hay alerta' );

// «revisar» agrupadas: 5 pedidos con su teléfono, 3 con la misma clienta → 1 suelta (con los 3 pedidos) + resumen de 2.
$m = array( 1 => member( 'Ana' ), 2 => member( 'Bea' ) );
$chk = static fn( int $o, int $owner, string $client ): array => sale( $o, $owner, 60, 40, 6, array( 'self_check' => true, 'buyer_names' => array( $client, '', '' ) ) );
$r = $C::compute( P, $m, array( $chk( 801, 1, 'Yuni Pérez' ), $chk( 802, 1, 'yuni  PÉREZ' ), $chk( 803, 1, 'Yuni Perez' ), $chk( 804, 1, 'Lía Gómez' ), $chk( 805, 2, 'Yuni Pérez' ) ) );
$rv = array_values( array_filter( $r['alerts'], static fn( $a ) => 'revisar' === $a['type'] ) );
$rs = array_values( array_filter( $r['alerts'], static fn( $a ) => 'revisar_resumen' === $a['type'] ) );
ok( 1 === count( $rv ) && 1 === $rv[0]['user'] && array( 801, 802, 803 ) === $rv[0]['orders'] && 3 === $rv[0]['count'], 'revisar: suelta solo la de Ana con Yuni (3 pedidos, sin tildes ni mayúsculas)' );
ok( 1 === count( $rs ) && 2 === $rs[0]['count'] && 2 === $rs[0]['gestoras'] && false !== strpos( $rs[0]['text'], '2 pedidos con el teléfono de la gestora (normal)' ), 'revisar_resumen: «2 pedidos con el teléfono de la gestora (normal)»' );
near( $r['people'][1]['own_points'], 56, 'las «revisar» cuentan igual: Ana 4 × 14 = 56 puntos' );
$r = $C::compute( P, $m, array( $chk( 806, 1, 'Yuni' ), $chk( 807, 1, 'Yuni' ) ) );
ok( ! has_alert( $r, 'revisar' ) && has_alert( $r, 'revisar_resumen' ), '2 veces la misma clienta: solo en el resumen' );
$r = $C::compute( P, $m, array( sale( 808, 1, 60, 40, 6 ) ) );
ok( ! has_alert( $r, 'revisar' ) && ! has_alert( $r, 'revisar_resumen' ), 'sin pedidos con su teléfono: ni suelta ni resumen' );
$many = array(); for ( $i = 0; $i < 58; $i++ ) { $many[] = $chk( 900 + $i, 1 + $i % 2, 'Clienta ' . $i ); }
$r = $C::compute( P, $m, $many );
ok( 1 === count( $r['alerts'] ) && 58 === $r['alerts'][0]['count'], '58 «revisar» de octubre → 1 sola línea de resumen' );

// Cierre que espera: «ganancia_alta» y «cuenta_compartida» sin revisar; las demás no.
$al = array(
	array( 'type' => 'ganancia_alta', 'order' => 9, 'user' => 1, 'key' => 'ganancia_alta:9' ),
	array( 'type' => 'cuenta_compartida', 'user' => 3, 'member' => 1, 'key' => 'cuenta_compartida:3:1:tarjeta' ),
	array( 'type' => 'provisional', 'order' => 10, 'user' => 1 ),
	array( 'type' => 'revisar_resumen', 'count' => 58 ),
);
ok( 2 === count( $C::pending_review( $al, array() ) ), 'cierre: espera por 2 alertas (ganancia alta y cuenta compartida)' );
ok( 1 === count( $C::pending_review( $al, array( 'ganancia_alta:9' => array( 'at' => 'x' ) ) ) ), 'una revisada: queda 1' );
ok( array() === $C::pending_review( $al, array( 'ganancia_alta:9' => 1, 'cuenta_compartida:3:1:tarjeta' => 1 ) ), 'las dos revisadas: ya no espera' );
ok( array() === $C::pending_review( array( $al[2], $al[3] ), array() ), '«provisional» y «revisar» no hacen esperar al cierre' );
$r = $C::compute( P, array( 1 => member( 'Ana' ) ), array( sale( 950, 1, 32, 3.2, 3 ) ) );
ok( 'ganancia_alta:950' === $C::alert_key( $r['alerts'][0] ) && 1 === count( $C::pending_review( $r['alerts'], array() ) ), 'ganancia_alta del pedido #950: clave «ganancia_alta:950» y hace esperar' );
// Los números del programa no cambian (D79 no toca red, piedras ni fondo).
ok( 0.01 === $C::DEFAULTS['red1_pct'] && 0.005 === $C::DEFAULTS['red2_pct'] && 0.15 === $C::DEFAULTS['fund_pct'] && 65 === $C::STONES[0][2] && 5000 === $C::STONES[0][3], 'D79 no cambia red, fondo ni piedras' );

// ---------- 3.14.45. Equipo de la tienda (Ernesto 21-oct, pedido #4953) ----------
$r = $C::compute( P, array( 64 => member( 'Zaymi' ) ), array( sale( 4953, 64, 12, 6, 0, array( 'self_order' => true, 'staff_self' => true ) ) ) );
ok( has_alert( $r, 'autocompra', 64 ) && false !== strpos( $r['alerts'][0]['text'], 'equipo/tienda' ), 'compra del equipo/tienda: alerta autocompra con su texto' );
ok( ! has_alert( $r, 'equipo' ), 'compra de la tienda: sin alerta «equipo»' );
$r = $C::compute( P, array( 64 => member( 'Zaymi' ) ), array( sale( 4954, 64, 30, 20, 3, array( 'staff_check' => true, 'buyer_names' => array( 'Sharon Pérez' ) ) ) ) );
$eq = array_values( array_filter( $r['alerts'], static fn( $a ) => 'equipo' === $a['type'] ) );
ok( 1 === count( $eq ) && 'equipo:4954' === $C::alert_key( $eq[0] ) && false !== strpos( $eq[0]['text'], 'Sharon Pérez' ), 'venta del equipo a un cliente: alerta «equipo:4954» con el cliente' );
ok( 1 === count( $C::pending_review( $eq, array() ) ) && array() === $C::pending_review( $eq, array( 'equipo:4954' => 1 ) ), '«equipo» se marca «Revisada» (hace esperar al cierre hasta entonces)' );
near( $r['people'][64]['own_points'], 7, 'venta del equipo con enlace: sí cuenta (30 − 20 − 3)' );

// ---------- 3.14.48 (D92). Cuenta de gestor del dueño (opción cvd_owner_gestor_user) ----------
/** Venta de varias líneas: cada una [base, costo, comisión, otros (fondo de la operación), ¿proveedor MK-?]. */
function msale( int $order, int $owner, array $ls, array $extra = array() ): array {
	$lines = array();
	foreach ( $ls as $i => $l ) { $lines[] = array( 'product_id' => 900 + $order * 10 + $i, 'name' => ( $l[4] ? 'MK ' : 'CV ' ) . $order . '.' . $i, 'qty' => 1, 'base' => $l[0], 'cost_unit' => $l[1], 'commission' => $l[2], 'other' => $l[3], 'bajo_pedido' => $l[4] ); }
	return $extra + array( 'order_id' => $order, 'owner' => $owner, 'at' => P . '-12 12:00:00', 'status' => 'approved', 'self_order' => false, 'test' => false, 'lines' => $lines );
}
const OG = 137;
$OGP = array( 'owner_gestor' => OG );
function lines_of( array $r, string $kind, int $user ): array { return array_values( array_filter( $r['lines'], static fn( $l ) => $kind === $l['kind'] && $user === $l['user'] ) ); }

// Regla 2: los puntos de una línea MK- de otra gestora también suman a su piedra; a ella no se le quitan.
$m = array( OG => member( 'Ernesto' ), 1 => member( 'Ana' ) );
$s = array( msale( 4253, 1, array( array( 140, 119, 8.4, 3.68, true ) ) ), msale( 4300, 1, array( array( 200, 120, 10, 0, false ) ) ) );
$on = $C::compute( P, $m, $s, array(), $OGP );
$off = $C::compute( P, $m, $s );
near( $on['people'][1]['own_points'], 78.92, 'D92: Ana conserva sus puntos (8,92 de la tablet MK- + 70)' );
near( $on['people'][OG]['supplier_points'], 8.92, 'D92: la línea MK- de Ana da 8,92 puntos de proveedor al dueño' );
near( $on['people'][OG]['own_points'], 8.92, '3.14.49: los puntos de proveedor son sus puntos propios' );
near( $on['people'][OG]['own_points_web'], 0, '3.14.49: sin ventas propias en la web (own_points_web = 0)' );
near( $on['people'][OG]['team_points'], 8.92, 'D92: sus puntos de equipo incluyen los de proveedor' );
ok( 1 === count( lines_of( $on, 'proveedor', OG ) ) && 4253 === lines_of( $on, 'proveedor', OG )[0]['order'] && 1 === lines_of( $on, 'proveedor', OG )[0]['source'], 'D92: renglón «proveedor» del pedido #4253 (vendió Ana)' );
near( $on['totals']['profit_usd'], $off['totals']['profit_usd'], 'D92: los puntos de proveedor no cambian la ganancia del mes' );
near( $on['totals']['pool_usd'], $off['totals']['pool_usd'], 'D92: ni el fondo del mes' );
ok( $on['people'][1]['bonus_pay_cup'] === $off['people'][1]['bonus_pay_cup'] && $on['people'][1]['stone'] === $off['people'][1]['stone'], 'D92: Ana cobra y tiene la misma piedra con o sin la regla' );
ok( 0.0 === $off['people'][OG]['supplier_points'] && ! lines_of( $off, 'proveedor', OG ), 'D92: opción vacía = sin efecto' );
$r = $C::compute( P, $m, $s, array(), array( 'owner_gestor' => 999 ) );
ok( 0.0 === $r['people'][OG]['supplier_points'] && $r['totals'] === $off['totals'], 'D92: opción con un usuario que no es gestora = sin efecto' );

// Si la vende él mismo, no se cuenta dos veces.
$r = $C::compute( P, $m, array( msale( 4400, OG, array( array( 140, 119, 8.4, 3.68, true ) ) ) ), array(), $OGP );
near( $r['people'][OG]['own_points'], 8.92, 'D92: venta MK- propia: sus puntos propios' );
near( $r['people'][OG]['supplier_points'], 0, 'D92: venta MK- propia: no suma también como «de proveedor»' );
near( $r['people'][OG]['team_points'], 8.92, 'D92: venta MK- propia: 8,92 una sola vez' );

// Con 70 puntos de proveedor llega a Bronce (las ventas de proveedor cuentan como suyas para la piedra), pero sin bono.
$s = array( msale( 4500, 1, array( array( 300, 220, 10, 0, true ) ) ) );
$on = $C::compute( P, $m, $s, array(), $OGP );
$off = $C::compute( P, $m, $s );
ok( 'bronce' === $on['people'][OG]['stone'] && 'bronce' === $on['people'][OG]['title'], 'D92: 70 puntos de proveedor y ninguna venta propia → piedra y título Bronce' );
ok( 0 === $on['people'][OG]['bonus_cup'] && 0 === $on['people'][OG]['bonus_pay_cup'] && 0.0 === (float) $on['people'][OG]['bonus_pay_usd'], 'D92: el dueño nunca cobra bono de piedra' );
near( $on['people'][OG]['fund_usd'], 0, 'D92: los puntos de proveedor no le dan fondo de equipo' );
ok( '' === $off['people'][OG]['stone'], 'D92: sin la opción, sin piedra' );
ok( $on['people'][1]['bonus_pay_cup'] === $off['people'][1]['bonus_pay_cup'] && 5000 === $on['people'][1]['bonus_pay_cup'], 'D92: Ana sigue cobrando sus 5.000 CUP de Bronce' );
near( $on['totals']['bonus_usd'], $off['totals']['bonus_usd'], 'D92: el total de bonos del mes no cambia' );

// Regla 3: su red (1 % + 0,5 %) solo sobre artículos de Casa Viva; la 2.ª línea según su piedra SIN puntos de proveedor.
$m = array( OG => member( 'Ernesto' ), 1 => member( 'Ana' ), 2 => member( 'Bea', OG ), 3 => member( 'Cris', 2 ) );
$s = array(
	msale( 10, OG, array( array( 60, 50, 0, 0, false ) ) ),                                   // venta propia: 10 puntos, 60 USD
	msale( 11, 2, array( array( 100, 60, 10, 0, false ), array( 50, 30, 5, 5, true ) ) ),     // Bea: 30 + 10 (MK-)
	msale( 12, 3, array( array( 200, 150, 10, 0, false ) ) ),                                 // Cris (2.º nivel): 40
	msale( 13, 1, array( array( 300, 250, 10, 0, true ) ) ),                                  // Ana (fuera de su red): 40 MK-
	msale( 14, 2, array( array( 80, 60, 5, 5, true ) ) ),                                     // Bea, solo MK-: 10
);
$on = $C::compute( P, $m, $s, array(), $OGP );
$off = $C::compute( P, $m, $s );
$E = $on['people'][OG];
near( $E['supplier_points'], 60, 'D92 red: 10 (Bea #11) + 40 (Ana #13) + 10 (Bea #14) puntos de proveedor' );
near( $E['red1'], 1.0, 'D92 red: 1 % de los 100 USD de Casa Viva de Bea (no de los 150 del pedido)' );
near( $off['people'][OG]['red1'], 2.3, 'sin la regla sería 1 % de 150 + 80 = 2,30' );
ok( ! array_filter( lines_of( $on, 'red1', OG ), static fn( $l ) => 14 === $l['order'] ), 'D92 red: el pedido #14 (solo MK-) no le da red' );
ok( 'bronce' === $E['stone_red'] && 'plata' === $E['stone'], 'D92: piedra Plata con proveedor (10+60 propios + 50 Bea + 40 Cris = 160), pero Bronce para la red (100)' );
near( $E['red2'], 0, 'D92: sin Plata «de red» no cobra el 0,5 % de Cris' );
near( $E['red_lost'], 1.0, 'D92: 0,5 % de los 200 de Cris se pierde (como a cualquier madrina Bronce)' );
near( $E['red_pay'], 1.0, 'D92: su red se paga (1,00 USD) como a cualquier madrina' );
near( $E['fund_usd'], 0.15 * 100, 'D92: su fondo de equipo es el 15 % de 100 (sin los 60 de proveedor)' );
ok( 0 === $E['bonus_pay_cup'] && 'plata' === $E['title'], 'D92: título Plata, sin bono' );
foreach ( array( 1, 2, 3 ) as $g ) {
	ok( $on['people'][ $g ]['red_pay'] === $off['people'][ $g ]['red_pay'] && $on['people'][ $g ]['bonus_pay_cup'] === $off['people'][ $g ]['bonus_pay_cup'] && $on['people'][ $g ]['own_points'] === $off['people'][ $g ]['own_points'], 'D92: ' . $m[ $g ]['name'] . ' cobra igual con o sin la regla' );
}
near( $on['people'][2]['red1'], 2.0, 'D92: Bea sigue cobrando el 1 % de todo lo de Cris' );
near( $on['totals']['pool_usd'], $off['totals']['pool_usd'], 'D92: el fondo del mes no cambia' );

// Su red, como cualquier madrina, pide 1 venta propia entregada en el mes (las de proveedor no cuentan para cobrar).
$r = $C::compute( P, $m, array( msale( 20, 2, array( array( 100, 60, 10, 0, false ) ) ), msale( 21, 1, array( array( 300, 220, 10, 0, true ) ) ) ), array(), $OGP );
ok( 'bronce' === $r['people'][OG]['stone'] && 0.0 === (float) $r['people'][OG]['red_pay'] && abs( $r['people'][OG]['red_lost'] - 1.0 ) < 0.01 && 'Sin venta propia entregada este mes' === $r['people'][OG]['red_lost_reason'], 'D92: sin venta propia no cobra red aunque tenga piedra por proveedor' );

// Devolución de un mes cerrado: también se restan sus puntos de proveedor de ese pedido.
$m = array( OG => member( 'Ernesto' ), 1 => member( 'Ana' ) );
$rev = array( array( 'order_id' => 3845, 'owner' => 1, 'points' => 7.32, 'base' => 105, 'supplier' => array( array( 'user' => OG, 'points' => 7.32, 'base' => 105 ) ) ) );
$r = $C::compute( P, $m, array( msale( 4600, 1, array( array( 140, 119, 8.4, 3.68, true ) ) ) ), $rev, $OGP );
near( $r['people'][OG]['supplier_points'], 1.6, 'D92: 8,92 − 7,32 de la devolución = 1,60 puntos de proveedor' );
ok( 1 === count( lines_of( $r, 'proveedor_devolucion', OG ) ), 'D92: renglón «proveedor_devolucion»' );
near( $r['people'][1]['own_points'], 1.6, 'la devolución resta a Ana como siempre' );

// Ventas de proveedor fuera de la web (opción cvd_owner_offweb_sales): margen limpio = venta − proveedor − gestora.
$offweb = array(
	array( 'date' => P . '-11', 'points' => 380 - 351 - 10, 'base' => 380, 'state' => 'cerrado' ), // Split Parker de Harley: 19
	array( 'date' => P . '-20', 'points' => 50, 'base' => 300, 'state' => 'abierto' ),              // sin entregar: no cuenta
	array( 'date' => '2026-09-30', 'points' => 50, 'base' => 300, 'state' => 'cerrado' ),           // otro mes: no cuenta
);
$r = $C::compute( P, $m, array(), array(), $OGP + array( 'owner_offweb' => $offweb ) );
near( $r['people'][OG]['supplier_points'], 19, 'D92 fuera de la web: el Split Parker da 19 puntos (solo la cerrada del mes)' );
ok( 1 === count( lines_of( $r, 'proveedor_fuera', OG ) ) && 0 === $r['people'][OG]['bonus_pay_cup'], 'D92 fuera de la web: 1 renglón «proveedor_fuera», sin bono' );
near( $r['totals']['profit_usd'], 0, 'D92 fuera de la web: no entra en la ganancia del mes (ni en el fondo)' );
$r = $C::compute( P, $m, array(), array(), array( 'owner_offweb' => $offweb ) );
ok( ! lines_of( $r, 'proveedor_fuera', OG ), 'D92 fuera de la web: sin la opción del dueño, nada' );

// ---------- 3.14.49 (Ernesto 22-oct): sus puntos de proveedor son SUS PUNTOS PROPIOS, solo su parte del reparto ----------
$m = array( OG => member( 'Ernesto' ), 1 => member( 'Ana' ) );
// (b) Patines #3845 de otra gestora: margen de las líneas 12,60 (105 − 84 − 8,4), pero su parte del reparto es 7,32
// (5.817 CUP / 795). Suma 7,32, no 105 ni 12,60.
$s = array( msale( 3845, 1, array( array( 105, 84, 8.4, 0, true ) ), array( 'owner_share_usd' => 5817 / 795 ) ), msale( 4300, 1, array( array( 200, 120, 10, 0, false ) ) ) );
$on = $C::compute( P, $m, $s, array(), $OGP );
$off = $C::compute( P, $m, $s );
near( $on['people'][OG]['own_points'], 7.32, '3.14.49 (b): patines de otra gestora → sus puntos propios 7,32 (su parte), no 105 ni 12,60' );
near( $on['people'][OG]['supplier_points'], 7.32, '3.14.49 (b): el detalle «de proveedor» también 7,32' );
near( lines_of( $on, 'proveedor', OG )[0]['points'], 7.32, '3.14.49 (b): el renglón «proveedor» guarda 7,32 (así una devolución resta lo mismo)' );
near( $on['people'][OG]['team_points'], 7.32, '3.14.49 (b): sus puntos del equipo = sus propios (no tiene invitadas)' );
near( $on['people'][1]['own_points'], 12.6 + 70, '3.14.49: Ana conserva todos sus puntos (12,60 + 70)' );
ok( $on['totals'] === $off['totals'], '3.14.49: el mes (ganancia, fondo, red, bonos) es idéntico con o sin la regla' );
ok( 0 === $on['people'][OG]['bonus_pay_cup'] && 0.0 === (float) $on['people'][OG]['fund_usd'], '3.14.49: sin bono y sin fondo de equipo' );
// Sin tasa en el reparto: 0 puntos (nunca el margen entero).
$r = $C::compute( P, $m, array( msale( 3846, 1, array( array( 105, 84, 8.4, 0, true ) ), array( 'owner_share_usd' => 0.0 ) ) ), array(), $OGP );
near( $r['people'][OG]['own_points'], 0, '3.14.49 (b): reparto sin tasa = 0 puntos, no el margen' );
// Reparto que no llega (parte negativa): 0, no resta.
$r = $C::compute( P, $m, array( msale( 3847, 1, array( array( 105, 84, 8.4, 0, true ) ), array( 'owner_share_usd' => -2.5 ) ) ), array(), $OGP );
near( $r['people'][OG]['own_points'], 0, '3.14.49 (b): parte negativa = 0 puntos' );
// (a) Venta MK- suya: los puntos completos de esas líneas (aunque viniera una parte, no se usa: es su venta).
$r = $C::compute( P, $m, array( msale( 4401, OG, array( array( 105, 84, 0, 4.2, true ) ), array( 'owner_share_usd' => 1.0 ) ) ), array(), $OGP );
near( $r['people'][OG]['own_points'], 16.8, '3.14.49 (a): venta MK- suya = puntos completos de la línea (105 − 84 − 4,20 de fondo = 16,80)' );
near( $r['people'][OG]['supplier_points'], 0, '3.14.49 (a): no se cuenta también como «de proveedor»' );
// (c) Fuera de la web: specialUsd (ya viene como «points» desde CVD_Programa::offweb_for_calc).
$r = $C::compute( P, $m, array(), array(), $OGP + array( 'owner_offweb' => array( array( 'date' => P . '-11', 'points' => 19, 'base' => 380, 'state' => 'cerrado' ) ) ) );
near( $r['people'][OG]['own_points'], 19, '3.14.49 (c): Split Parker fuera de la web → 19 puntos propios' );
// Mezcla: venta propia (a) + parte de otra (b) + fuera (c) + devolución de proveedor de un mes cerrado.
$s = array( msale( 4402, OG, array( array( 60, 50, 0, 0, false ) ) ), msale( 3848, 1, array( array( 140, 119, 8.4, 3.68, true ) ), array( 'owner_share_usd' => 6.83 ) ) );
$rev = array( array( 'order_id' => 3693, 'owner' => 1, 'points' => 2.74, 'base' => 45, 'supplier' => array( array( 'user' => OG, 'points' => 6.74, 'base' => 45 ) ) ) );
$r = $C::compute( P, $m, $s, $rev, $OGP + array( 'owner_offweb' => array( array( 'date' => P . '-11', 'points' => 19, 'base' => 380, 'state' => 'cerrado' ) ) ) );
near( $r['people'][OG]['own_points'], 10 + 6.83 + 19 - 6.74, '3.14.49: 10 (suya) + 6,83 (parte) + 19 (fuera) − 6,74 (devolución) = 29,09 puntos propios' );
near( $r['people'][OG]['own_points_web'], 10, '3.14.49: de ellos, 10 de su venta en la web' );
// Su madrina (si la tuviera) no recibe sus puntos de proveedor: lo de las demás no cambia.
$m = array( 1 => member( 'Ana' ), OG => member( 'Ernesto', 1 ) );
$s = array( msale( 4403, OG, array( array( 60, 50, 0, 0, false ) ) ), msale( 3849, 1, array( array( 300, 220, 10, 0, true ) ), array( 'owner_share_usd' => 70 ) ) );
$on = $C::compute( P, $m, $s, array(), $OGP );
$off = $C::compute( P, $m, $s );
near( $on['people'][OG]['own_points'], 80, '3.14.49: él 10 + 70 de su parte' );
ok( $on['people'][1]['team_points'] === $off['people'][1]['team_points'] && $on['people'][1]['stone'] === $off['people'][1]['stone'] && $on['people'][1]['bonus_pay_cup'] === $off['people'][1]['bonus_pay_cup'], '3.14.49: a su madrina le cuentan solo sus 10 de la web (equipo, piedra y bono iguales)' );
ok( 'bronce' === $on['people'][OG]['stone'] && 0 === $on['people'][OG]['bonus_pay_cup'], '3.14.49: con 80 puntos propios llega a Bronce, sin bono' );

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
