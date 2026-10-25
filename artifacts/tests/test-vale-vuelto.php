<?php

/**
 * Vuelto en el vale por chat (Core 3.14.70, Dany 24-oct: «sería bueno preguntar si el cliente va a necesitar vuelto»).
 *
 * CVD_Cuban_Checkout::apply_change: las mismas cuentas que el checkout de la web, ahora también para CVD_Vale_Chat.
 *
 * Desde la raíz del repo:
 * php artifacts/tests/test-vale-vuelto.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WC_Order {
	public array $meta = array();
	public function __construct( private float $total ) {}
	public function get_total() { return $this->total; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
}

require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-cuban-checkout.php';

$fails = 0;
$runs = 0;
function check( string $name, $got, $want ): void {
	global $fails, $runs;
	$runs++;
	if ( $got !== $want ) {
		$fails++;
		echo "FALLA  {$name}\n  esperado: " . var_export( $want, true ) . "\n  obtenido: " . var_export( $got, true ) . "\n";
	}
}

// Efectivo USD: 7 USD, paga con 50 → vuelto 43 USD.
$o = new WC_Order( 7.0 );
check( 'USD con vuelto', CVD_Cuban_Checkout::apply_change( $o, 'cash_usd', 7.0, 50.0 ), array( array( 'amount' => 43.0, 'currency' => 'USD' ) ) );
check( 'USD guarda con cuánto paga', $o->meta['_cvd_pay_with'], array( 'amount' => 50.0, 'currency' => 'USD' ) );

// Efectivo CUP: lo que debe en CUP (due) viene de apply_payment.
$o = new WC_Order( 7.0 );
check( 'CUP con vuelto', CVD_Cuban_Checkout::apply_change( $o, 'cash_cup', 5915.0, 10000.0 ), array( array( 'amount' => 4085.0, 'currency' => 'CUP' ) ) );

// Paga exacto (0) o con menos de lo que debe: sin vuelto.
$o = new WC_Order( 7.0 );
check( 'paga exacto', CVD_Cuban_Checkout::apply_change( $o, 'cash_usd', 7.0, 0.0 ), array() );
check( 'paga exacto: no guarda con cuánto', isset( $o->meta['_cvd_pay_with'] ), false );
check( 'paga exacto: vuelto vacío', $o->meta['_cvd_change_required'], array() );
$o = new WC_Order( 7.0 );
check( 'paga con menos', CVD_Cuban_Checkout::apply_change( $o, 'cash_usd', 7.0, 5.0 ), array() );

// Transferencia: nunca hay vuelto aunque llegue un número.
$o = new WC_Order( 7.0 );
check( 'transferencia', CVD_Cuban_Checkout::apply_change( $o, 'transfer_cup', 7014.0, 10000.0 ), array() );

// Sin forma de pago: como siempre, solo si es en USD.
$o = new WC_Order( 7.0 );
check( 'sin forma de pago, USD', CVD_Cuban_Checkout::apply_change( $o, '', null, 20.0 ), array( array( 'amount' => 13.0, 'currency' => 'USD' ) ) );
$o = new WC_Order( 7.0 );
check( 'sin forma de pago, CUP sin tasa', CVD_Cuban_Checkout::apply_change( $o, '', null, 10000.0, 'CUP' ), array() );

echo ( $fails ? "{$fails} de {$runs} fallan\n" : "OK {$runs} pruebas\n" );
exit( $fails ? 1 : 0 );
