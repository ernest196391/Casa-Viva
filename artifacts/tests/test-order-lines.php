<?php

/**
 * Pruebas del precio de cada producto en vales y tarjetas (Core 3.14.7, Nana 14-oct).
 *
 * Desde la raíz del repo:
 * php artifacts/tests/test-order-lines.php
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public function __construct( private string $n, private int $q, private float $t ) {}
		public function get_name() { return $this->n; }
		public function get_quantity() { return $this->q; }
		public function get_total() { return $this->t; }
	}
}
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function __construct( private array $items, private string $cur = 'USD' ) {}
		public function get_items( $type ) { return $this->items; }
		public function get_currency() { return $this->cur; }
	}
}
require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-order-lines.php';

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

check( 'una unidad', CVD_Order_Lines::text( 1, 'Bata de baño azul', 30.0 ), '1× Bata de baño azul — 30 USD' );
check( 'dos unidades: c/u y subtotal', CVD_Order_Lines::text( 2, 'Toalla gris', 30.0 ), '2× Toalla gris — 15 USD c/u = 30 USD' );
check( 'céntimos', CVD_Order_Lines::text( 3, 'Vaso', 37.5 ), '3× Vaso — 12.50 USD c/u = 37.50 USD' );
check( 'nombre con espacios y etiquetas', CVD_Order_Lines::text( 1, "  <b>Set de baño</b>\n 3 piezas ", 28.0 ), '1× Set de baño 3 piezas — 28 USD' );
check( 'cantidad 0 cuenta como 1', CVD_Order_Lines::text( 0, 'X', 5.0 ), '1× X — 5 USD' );
check( 'miles', CVD_Order_Lines::money( 1250.0 ), '1,250 USD' );

// Pedido de gestora: la línea ya trae su precio (35 en vez de 30); el desglose usa ese.
$order = new WC_Order( array( new WC_Order_Item_Product( 'Organizador', 2, 70.0 ), new WC_Order_Item_Product( 'Bata de baño azul', 1, 30.0 ) ) );
check( 'textos del pedido', CVD_Order_Lines::texts( $order ), array( '2× Organizador — 35 USD c/u = 70 USD', '1× Bata de baño azul — 30 USD' ) );
$l = CVD_Order_Lines::lines( $order )[0];
check( 'unidad y total', array( $l['unit'], $l['total'], $l['quantity'] ), array( 35.0, 70.0, 2 ) );

echo $fails ? "\n{$fails} de {$runs} pruebas fallaron\n" : "OK: {$runs} pruebas\n";
exit( $fails ? 1 : 0 );
