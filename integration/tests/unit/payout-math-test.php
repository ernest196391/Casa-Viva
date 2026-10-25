<?php
/**
 * Pruebas de las cuentas de pagos de comisiones (Core 3.14.23). Sin WordPress:
 *   docker run --rm -v "$PWD:/app" -w /app php:8.3-cli php integration/tests/unit/payout-math-test.php
 */

define( 'CVD_PAYOUT_MATH_STANDALONE', true );
$cvd_math = __DIR__ . '/../../../wordpress/casa-viva-dropship-core/includes/class-cvd-payout-math.php';
if ( ! is_file( $cvd_math ) ) { $cvd_math = '/var/www/html/wp-content/plugins/casa-viva-dropship-core/includes/class-cvd-payout-math.php'; } // dentro del contenedor
require $cvd_math;

$fails = 0; $count = 0;
function check( string $name, $got, $want ): void {
	global $fails, $count; $count++;
	if ( $got !== $want ) { $fails++; fwrite( STDERR, "FALLA {$name}: esperaba " . var_export( $want, true ) . ' y salió ' . var_export( $got, true ) . "\n" ); }
}

// 1) Cada pedido con SU tasa (no la del día del pago).
$a = CVD_Payout_Math::line( 14, 790 );   // 12-oct
$b = CVD_Payout_Math::line( 5.2, 785 );  // 13-oct
$c = CVD_Payout_Math::line( 1.3, 795 );  // 15-oct
check( 'línea 14×790', $a['cup'], 11060.0 );
check( 'línea 5,2×785', $b['cup'], 4082.0 );
check( 'línea 1,3×795 con medio peso', $c['cup'], 1033.5 );
check( 'línea redondea USD a centavos', CVD_Payout_Math::line( 1.23456, 800 )['usd'], 1.23 );

// 2) Transferencia: suma de CUP por pedido, centavos incluidos.
$q = CVD_Payout_Math::quote( array( $a, $b, $c ), array(), 'transfer', 800 );
check( 'transfer ok', $q['ok'], true );
check( 'transfer USD bruto', $q['usd'], 20.5 );
check( 'transfer CUP = suma por pedido (no 20,5×800)', $q['pay'], 16175.5 );
check( 'transfer moneda', $q['currency'], 'CUP' );
check( 'texto CUP con decimal', CVD_Payout_Math::cup( $q['pay'] ), '16.175,5 CUP' );

// 3) Efectivo CUP: redondeado al peso.
$q = CVD_Payout_Math::quote( array( $a, $b, $c ), array(), 'cash_cup', 800 );
check( 'efectivo CUP redondeado', $q['pay'], 16176.0 );

// 4) Efectivo USD: la suma en USD.
$q = CVD_Payout_Math::quote( array( $a, $b, $c ), array(), 'cash_usd', 800 );
check( 'efectivo USD', $q['pay'], 20.5 );
check( 'texto USD', CVD_Payout_Math::usd( 20.5 ), '20,50 USD' );
check( 'texto USD redondo', CVD_Payout_Math::usd( 47 ), '47 USD' );

// 5) Deuda de 2.150 CUP: se descuenta completa y se ve.
$debts = array( array( 'amount' => 2150, 'currency' => 'CUP' ) );
$q = CVD_Payout_Math::quote( array( $a, $b, $c ), $debts, 'transfer', 795 );
check( 'deuda CUP en transferencia', $q['deduction'], 2150.0 );
check( 'neto tras deuda', $q['pay'], 14025.5 );
// En USD la deuda CUP va con la tasa de hoy y redondeada hacia arriba al centavo: 2150/795 = 2,7044 → 2,71.
$q = CVD_Payout_Math::quote( array( $a, $b, $c ), $debts, 'cash_usd', 795 );
check( 'deuda CUP→USD hacia arriba', $q['deduction'], 2.71 );
check( 'neto USD tras deuda', $q['pay'], 17.79 );
// Deuda en USD cobrando en CUP: con la tasa de hoy.
$q = CVD_Payout_Math::quote( array( $a ), array( array( 'amount' => 2, 'currency' => 'USD' ) ), 'transfer', 795 );
check( 'deuda USD→CUP', $q['deduction'], 1590.0 );
check( 'neto', $q['pay'], 9470.0 );
// Deuda mayor que lo que retira: no se crea el pago.
$q = CVD_Payout_Math::quote( array( $c ), $debts, 'transfer', 795 );
check( 'deuda mayor → no', $q['ok'], false );
check( 'deuda mayor → explica', str_contains( $q['error'], '2.150 CUP' ), true );
// Sin tasa de hoy y deuda en otra moneda: no adivina.
$q = CVD_Payout_Math::quote( array( $a ), array( array( 'amount' => 2, 'currency' => 'USD' ) ), 'transfer', 0 );
check( 'sin tasa de hoy', $q['ok'], false );

// 6) Retiro parcial: pedidos enteros, del más viejo al más nuevo; lo demás queda guardado.
$lines = array( array( 'id' => 1, 'usd' => 5 ), array( 'id' => 2, 'usd' => 10 ), array( 'id' => 3, 'usd' => 2 ), array( 'id' => 4, 'usd' => 8 ) );
check( 'parcial todo (null)', CVD_Payout_Math::pick( $lines, null ), array( 1, 2, 3, 4 ) );
check( 'parcial todo (mayor)', CVD_Payout_Math::pick( $lines, 999 ), array( 1, 2, 3, 4 ) );
check( 'parcial 15', CVD_Payout_Math::pick( $lines, 15 ), array( 1, 2 ) );
check( 'parcial 8 salta el que no cabe', CVD_Payout_Math::pick( $lines, 8 ), array( 1, 3 ) );
check( 'parcial 4 toma el de 2', CVD_Payout_Math::pick( $lines, 4 ), array( 3 ) );
check( 'parcial 1 = nada', CVD_Payout_Math::pick( $lines, 1 ), array() );
check( 'parcial exacto 25', CVD_Payout_Math::pick( $lines, 25 ), array( 1, 2, 3, 4 ) );

// 7) Falta tasa de un pedido: en CUP no se adivina; en USD sí se puede.
$sin = CVD_Payout_Math::line( 3, 0 );
check( 'sin tasa CUP no', CVD_Payout_Math::quote( array( $a, $sin ), array(), 'transfer', 795 )['ok'], false );
check( 'sin tasa USD sí', CVD_Payout_Math::quote( array( $a, $sin ), array(), 'cash_usd', 795 )['pay'], 17.0 );
check( 'método raro', CVD_Payout_Math::quote( array( $a ), array(), 'bitcoin', 795 )['ok'], false );
check( 'sin pedidos', CVD_Payout_Math::quote( array(), array(), 'transfer', 795 )['ok'], false );

// 8) Tasa de pedidos viejos desde el registro de la tasa.
$log = array(
	array( 'at' => '2026-10-11T19:29:57+00:00', 'changed' => array( 'cup' => array( null, 780 ) ) ),
	array( 'at' => '2026-10-12T11:53:32+00:00', 'changed' => array( 'cup' => array( 780, 785 ) ) ),
	array( 'at' => '2026-10-13T11:39:43+00:00', 'changed' => array() ),
	array( 'at' => '2026-10-14T15:15:52+00:00', 'changed' => array( 'cup' => array( 785, 790 ) ) ),
);
check( 'pedido antes del primer registro', CVD_Payout_Math::rate_from_log( $log, strtotime( '2026-10-11T11:29:21+00:00' ) ), 780.0 );
check( 'pedido del 12 antes del cambio', CVD_Payout_Math::rate_from_log( $log, strtotime( '2026-10-12T09:50:14+00:00' ) ), 780.0 );
check( 'pedido del 12 después del cambio', CVD_Payout_Math::rate_from_log( $log, strtotime( '2026-10-12T12:00:00+00:00' ) ), 785.0 );
check( 'pedido del 15', CVD_Payout_Math::rate_from_log( $log, strtotime( '2026-10-15T12:00:00+00:00' ) ), 790.0 );
check( 'registro vacío', CVD_Payout_Math::rate_from_log( array(), time() ), null );

// 9) Enlace firmado: vale, caduca, no se puede tocar.
$key = 'secreto-de-prueba';
$t = CVD_Payout_Math::sign( 12, '56885368', 2000000000, $key );
check( 'firma vale', CVD_Payout_Math::verify( 12, $t, 1900000000, $key ), '56885368' );
check( 'firma caducada', CVD_Payout_Math::verify( 12, $t, 2000000001, $key ), '' );
check( 'otro pago', CVD_Payout_Math::verify( 13, $t, 1900000000, $key ), '' );
check( 'teléfono cambiado', CVD_Payout_Math::verify( 12, str_replace( '56885368', '59163951', $t ), 1900000000, $key ), '' );
check( 'otra clave', CVD_Payout_Math::verify( 12, $t, 1900000000, 'otra' ), '' );
check( 'basura', CVD_Payout_Math::verify( 12, '<script>', 1900000000, $key ), '' );

// 10) Datos de cobro.
check( 'tarjeta bonita', CVD_Payout_Math::card_pretty( '9205-1299-7654-3210' ), '9205 1299 7654 3210' );
check( 'tarjeta válida', CVD_Payout_Math::valid_card( '9205 1299 7654 3210' ), true );
check( 'tarjeta corta', CVD_Payout_Math::valid_card( '9205 1299' ), false );
check( 'teléfono +53', CVD_Payout_Math::phone8( '+53 5378 4147' ), '53784147' );
check( 'teléfono fijo no', CVD_Payout_Math::phone8( '72041234' ), '' );

// 3.14.68 (Dany, 24-oct): una parte en efectivo USD y el resto en efectivo CUP.
$dany = array( array( 'id' => 1, 'usd' => 5.0, 'cup' => 2600.0, 'rate' => 520.0 ), array( 'id' => 2, 'usd' => 20.0, 'cup' => 10400.0, 'rate' => 520.0 ), array( 'id' => 3, 'usd' => 16.2, 'cup' => 8586.0, 'rate' => 530.0 ) );
$m = CVD_Payout_Math::quote( $dany, array(), 'mixed', 540.0, 40.0 );
check( 'mixto ok', $m['ok'], true );
check( 'mixto USD', $m['pay'], 40.0 );
check( 'mixto CUP = 1,20 USD con la tasa de esas ventas', $m['pay_cup'], 629.0 );
check( 'mixto texto', CVD_Payout_Math::mixed_text( $m['pay'], $m['pay_cup'] ), '40 USD + 629 CUP' );
$m = CVD_Payout_Math::quote( $dany, array( array( 'amount' => 100, 'currency' => 'CUP' ), array( 'amount' => 1, 'currency' => 'USD' ) ), 'mixed', 540.0, 30.0 );
check( 'mixto: deudas (CUP y USD a la tasa de hoy) salen del CUP', array( $m['deduction'], $m['pay_cup'] ), array( 640.0, 5228.0 ) );
check( 'mixto: todo en USD no', CVD_Payout_Math::quote( $dany, array(), 'mixed', 540.0, 41.2 )['ok'], false );
check( 'mixto: 0 USD no', CVD_Payout_Math::quote( $dany, array(), 'mixed', 540.0, 0 )['ok'], false );
check( 'mixto: deuda mayor que el CUP', CVD_Payout_Math::quote( $dany, array( array( 'amount' => 900, 'currency' => 'CUP' ) ), 'mixed', 540.0, 40.0 )['ok'], false );
check( 'mixto: sin tasa no', CVD_Payout_Math::quote( array( array( 'id' => 9, 'usd' => 10.0, 'cup' => 0.0, 'rate' => 0.0 ) ), array(), 'mixed', 540.0, 5.0 )['ok'], false );

echo ( $fails ? "FALLARON {$fails} de {$count}\n" : "OK: {$count} pruebas de cuentas de pagos\n" );
exit( $fails ? 1 : 0 );
