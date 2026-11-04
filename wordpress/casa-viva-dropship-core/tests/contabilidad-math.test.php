<?php
// Pruebas de la contabilidad diaria (cuentas puras). Se corre con: php tests/contabilidad-math.test.php  (sale con código 1 si algo falla).
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-cvd-contabilidad-math.php';

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got ) . "\n  quería: " . json_encode( $want ) . "\n"; }
}
$M = 'CVD_Contabilidad_Math';

check( 'MK- es bajo pedido', $M::line_kind( 'MK-1234' ), 'bajo_pedido' );
check( 'mk- en minúsculas también', $M::line_kind( ' mk-9' ), 'bajo_pedido' );
check( 'SKU normal es tienda', $M::line_kind( 'CV-ALF-01' ), 'tienda' );
check( 'sin SKU es tienda', $M::line_kind( '' ), 'tienda' );

// Un pedido de tienda: 2 alfombras de 25 (costo 15 c/u), comisión 5, subida de la gestora 4.
$tienda = array( 'commission' => 5, 'markup' => 4, 'lines' => array( array( 'kind' => 'tienda', 'total' => 50, 'qty' => 2, 'cost_unit' => 15, 'stamped' => true ) ) );
// Un pedido mixto: tienda 30 (sin costo) + bajo pedido 70 (proveedor 50); comisión 10 se reparte 3 / 7.
$mixto = array( 'commission' => 10, 'markup' => 0, 'lines' => array(
	array( 'kind' => 'tienda', 'total' => 30, 'qty' => 1, 'cost_unit' => null, 'stamped' => true ),
	array( 'kind' => 'bajo_pedido', 'total' => 70, 'qty' => 1, 'cost_unit' => 50, 'stamped' => false ),
) );
$s = $M::summarize( array( $tienda, $mixto ), 0.005, 0.015 );
$t = $s['tienda']; $b = $s['bajo_pedido'];
check( 'pedidos de tienda', $t['pedidos'], 2 );
check( 'pedidos bajo pedido', $b['pedidos'], 1 );
check( 'ventas tienda', $t['ventas'], 80.0 );
check( 'costo tienda', $t['costo'], 30.0 );
check( 'comisión tienda (5 + 3)', $t['comision'], 8.0 );
check( 'ventas sin costo', $t['ventas_sin_costo'], 30.0 );
check( 'líneas sin costo', $t['lineas_sin_costo'], 1 );
check( 'base sin la subida', $t['base_porcentajes'], 76.0 );
check( 'dependientas 0,5 %', $t['dependientas'], 0.38 );
check( 'Ernesto 1,5 %', $t['ernesto'], 1.14 );
check( 'margen tienda', $t['margen'], 40.48 );
check( 'ventas bajo pedido', $b['ventas'], 70.0 );
check( 'proveedor', $b['proveedor'], 50.0 );
check( 'comisión bajo pedido', $b['comision'], 7.0 );
check( 'margen bajo pedido', $b['margen'], 20.0 );
check( 'margen tras gestora', $b['margen_tras_gestora'], 13.0 );
check( 'costo de hoy (sin guardar)', $b['lineas_costo_de_hoy'], 1 );
check( 'sin pedidos', $M::summarize( array(), 0.005, 0.015 )['tienda']['margen'], 0.0 );

// 3.14.126: por día y comisiones por estado.
$tienda['dia'] = '2026-11-02'; $tienda['commission_status'] = 'paid';
$mixto['dia'] = '2026-11-01'; $mixto['commission_status'] = 'approved';
$cancelado = array( 'dia' => '2026-11-02', 'commission' => 4, 'commission_status' => 'cancelled', 'lines' => array( array( 'kind' => 'tienda', 'total' => 20 ) ) );
check( 'ventas por día en orden', $M::by_day( array( $tienda, $mixto, $cancelado ) ), array(
	array( 'dia' => '2026-11-01', 'tienda' => 30.0, 'bajo_pedido' => 70.0 ),
	array( 'dia' => '2026-11-02', 'tienda' => 70.0, 'bajo_pedido' => 0.0 ),
) );
check( 'comisiones por estado', $M::commission_by_status( array( $tienda, $mixto, $cancelado ) ), array(
	'tienda' => array( 'pagado' => 5.0, 'por_pagar' => 3.0 ),
	'bajo_pedido' => array( 'pagado' => 0.0, 'por_pagar' => 7.0 ),
) );

echo $fails ? "\n{$fails} de {$n} pruebas fallan\n" : "OK: {$n} pruebas\n";
exit( $fails ? 1 : 0 );
