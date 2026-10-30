<?php
// Pruebas del cierre de turno (cuentas puras). Se corre con: php tests/cierre-math.test.php  (sale con código 1 si algo falla).
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-cvd-cierre-math.php';

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got ) . "\n  quería: " . json_encode( $want ) . "\n"; }
}
$M = 'CVD_Cierre_Math';

// Día de trabajo: de las 20:00 de ayer a las 20:00 de hoy (Cuba, UTC-4 hasta el 1-nov).
check( 'ventana 28-oct', $M::window( '2026-10-28' ), array( '2026-10-28 00:00:00', '2026-10-29 00:00:00' ) );
check( 'día de 23:59 UTC', $M::day_of( '2026-10-28 23:59:59' ), '2026-10-28' );
check( 'día a las 20:00 Cuba ya es el siguiente', $M::day_of( '2026-10-29 00:00:00' ), '2026-10-29' );
check( 'día de las 14:20 Cuba', $M::day_of( '2026-10-27 18:20:00' ), '2026-10-27' );
check( 'día válido', $M::valid_day( '2026-10-28' ), true );
check( 'día inválido', $M::valid_day( '2026-13-40' ), false );

// Dinero en enteros.
check( 'centavos 12,5', $M::cents( '12,5' ), 1250 );
check( 'centavos 0,29', $M::cents( 0.29 ), 29 );
check( 'pesos 12150.4', $M::pesos( '12150.4' ), 12150 );
check( 'centavos basura', $M::cents( 'abc' ), 0 );

// Columnas de la hoja.
check( 'efectivo USD y MN', $M::columns( 'cash_usd', '40.00', '0' ), array( 'usd' => 4000, 'mn' => 0, 'trf' => 0, 'otros' => 0 ) );
check( 'transferencia CUP', $M::columns( 'transfer', '0', '12150' ), array( 'usd' => 0, 'mn' => 0, 'trf' => 12150, 'otros' => 0 ) );
check( 'zelle', $M::columns( 'zelle', '25', '0' ), array( 'usd' => 0, 'mn' => 0, 'trf' => 0, 'otros' => 2500 ) );
check( 'mixto cuenta como efectivo', $M::columns( 'mixed', '10', '5000' ), array( 'usd' => 1000, 'mn' => 5000, 'trf' => 0, 'otros' => 0 ) );

// Un cobro: lo apuntó Nana (63) el 27-oct a las 14:20 (hora de Cuba) = 18:20 UTC.
[ $from, $to ] = $M::window( '2026-10-27' );
$clerks = array( 63, 64 ); $admins = array( 14, 1 );
$base = array( 'id' => 5001, 'number' => '5001', 'status' => 'completed', 'meta' => array(
	'_cvd_collection_method' => 'cash_usd', '_cvd_collection_amount_usd' => '35', '_cvd_collection_amount_cup' => '0',
	'_cvd_cash_verified_at' => '2026-10-27 18:20:00', '_cvd_cash_verified_by' => '63', '_cvd_fulfillment_type' => 'delivery' ) );
$row = $M::row_from_order( $base, $from, $to, $clerks, $admins );
check( 'cobro de Nana entra', $row['usd'] ?? null, 3500 );
check( 'cobro de Nana va al cajón', $row['group'] ?? null, 'cajon' );
check( 'cobro de Nana es de Nana', $row['by'] ?? null, 63 );
check( 'fuera de la ventana no entra', $M::row_from_order( $base, '2026-10-28 00:00:00', '2026-10-29 00:00:00', $clerks, $admins ), null );
$test = $base; $test['meta']['_cvd_test_order'] = 'yes';
check( 'pedido de prueba no entra', $M::row_from_order( $test, $from, $to, $clerks, $admins ), null );
$canc = $base; $canc['status'] = 'cancelled';
check( 'pedido cancelado no entra', $M::row_from_order( $canc, $from, $to, $clerks, $admins ), null );
$noMethod = $base; unset( $noMethod['meta']['_cvd_collection_method'] );
check( 'sin forma de cobro no entra', $M::row_from_order( $noMethod, $from, $to, $clerks, $admins ), null );
$byLennys = $base; $byLennys['meta']['_cvd_cash_verified_by'] = '14';
check( 'lo que cierra Lennys va aparte', $M::row_from_order( $byLennys, $from, $to, $clerks, $admins )['group'] ?? null, 'admin' );

// Recibido en tienda por recogida (sin devuelto/verificado).
$pick = array( 'id' => 5002, 'number' => '5002', 'status' => 'completed', 'meta' => array(
	'_cvd_collection_method' => 'cash_cup', '_cvd_collection_amount_usd' => '0', '_cvd_collection_amount_cup' => '4200',
	'_cvd_collection_received_at' => '2026-10-27 19:00:00', '_cvd_collection_received_by' => '64', '_cvd_fulfillment_type' => 'pickup', '_cvd_cash_status' => 'verified' ) );
$r2 = $M::row_from_order( $pick, $from, $to, $clerks, $admins );
check( 'recogida de Zaymi', array( $r2['by'], $r2['mn'], $r2['pickup'] ), array( 64, 4200, true ) );
$pending = $pick; $pending['meta']['_cvd_fulfillment_type'] = 'delivery'; $pending['meta']['_cvd_collection_received_by'] = '47'; $pending['meta']['_cvd_cash_status'] = 'pending_return';
check( 'dinero aún con el mensajero no entra', $M::row_from_order( $pending, $from, $to, $clerks, $admins ), null );
$transferMessenger = $pending; $transferMessenger['meta']['_cvd_collection_method'] = 'transfer'; $transferMessenger['meta']['_cvd_cash_status'] = 'verified';
$r3 = $M::row_from_order( $transferMessenger, $from, $to, $clerks, $admins );
check( 'transferencia apuntada por mensajero va al cajón de la tienda', $r3['group'] ?? null, 'cajon' );

// mine(): solo lo de ella, y solo si es dependienta (la transferencia del mensajero 47 no es de nadie).
$rows = array( $row, $r2, $r3 );
$mine63 = $M::mine( $rows, 63 );
check( 'mine Nana', count( $mine63 ), 1 );
check( 'mine Zaymi', count( $M::mine( $rows, 64 ) ), 1 );
check( 'totales de Nana', $M::totals( $mine63 ), array( 'n' => 1, 'usd' => 3500, 'mn' => 0, 'trf' => 0, 'otros' => 0 ) );

// Billetes.
check( 'billetes USD', $M::bills_total( array( '100' => 2, '20' => 3, '1' => 7 ), $M::USD_BILLS ), 267 );
check( 'billete que no existe se ignora', $M::bills_total( array( '7' => 3, '5' => 1 ), $M::USD_BILLS ), 5 );
check( 'cantidad negativa se ignora', $M::bills_total( array( '5' => -3 ), $M::USD_BILLS ), 0 );
check( 'billetes MN', $M::bills_total( array( '5000' => 1, '1000' => 2, '10' => 5 ), $M::MN_BILLS ), 7050 );

check( 'billetes MN de 20.000 y 10.000', $M::bills_total( array( '20000' => 1, '10000' => 2, '5000' => 1 ), $M::MN_BILLS ), 45000 );

// Salidas.
check( 'salidas', $M::outs_total( array( array( 'usd' => '5', 'mn' => 0 ), array( 'usd' => 0, 'mn' => '1000' ), 'basura' ) ), array( 500, 1000 ) );

// Cuenta final (ejemplo de Lennys 20-oct: fondo 257, cobros 652 → debe haber 909).
$ok = $M::settle( array( 'usd' => 25700, 'mn' => 0 ), array( 'usd' => 65200, 'mn' => 12150 ), array( 'usd' => 0, 'mn' => 0 ), array( 'usd' => 90900, 'mn' => 12150 ) );
check( 'cuadra', $ok['ok'], true );
check( 'esperado USD', $ok['usd']['expected'], 90900 );
$short = $M::settle( array( 'usd' => 25700, 'mn' => 0 ), array( 'usd' => 65200, 'mn' => 0 ), array( 'usd' => 1600, 'mn' => 0 ), array( 'usd' => 87300, 'mn' => 0 ) );
check( 'faltan 20 USD', array( $short['usd']['diff'], $short['usd']['status'], $short['ok'] ), array( -2000, 'short', false ) );
$over = $M::settle( array( 'usd' => 0, 'mn' => 0 ), array( 'usd' => 0, 'mn' => 1000 ), array( 'usd' => 0, 'mn' => 0 ), array( 'usd' => 0, 'mn' => 1500 ) );
check( 'sobran 500 MN', array( $over['mn']['diff'], $over['mn']['status'] ), array( 500, 'over' ) );

// Tarjetas.
$cards = $M::by_card( array(
	array( 'method' => 'transfer', 'trf' => 12000, 'otros' => 0, 'account' => 'Clásica ·2690', 'number' => '1' ),
	array( 'method' => 'transfer', 'trf' => 3000, 'otros' => 0, 'account' => 'Clásica ·2690', 'number' => '2' ),
	array( 'method' => 'zelle', 'trf' => 0, 'otros' => 2500, 'account' => 'Zelle', 'number' => '3' ),
	array( 'method' => 'cash_usd', 'trf' => 0, 'otros' => 0, 'account' => '', 'number' => '4' ),
) );
check( 'Clásica junta', array( $cards[0]['name'], $cards[0]['n'], $cards[0]['cup'] ), array( 'Clásica ·2690', 2, 15000 ) );
check( 'Zelle aparte', array( $cards[1]['name'], $cards[1]['usd'] ), array( 'Zelle', 2500 ) );
check( 'efectivo no es tarjeta', count( $cards ), 2 );

echo ( $fails ? "{$fails} de {$n} pruebas FALLARON\n" : "OK: {$n} pruebas\n" );
exit( $fails ? 1 : 0 );
