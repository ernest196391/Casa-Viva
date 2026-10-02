<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL Curru: ' . $message ); }
};
$make = static function ( string $name, string $price, string $status = 'publish', string $stock = 'instock' ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_status( $status );
	$product->set_stock_status( $stock );
	return $product->save();
};

$pan    = $make( 'Sartén antiadherente 24 cm', '18' );
$towel  = $make( 'Toalla de baño gris 70x140', '9' );
$blend  = $make( 'Licuadora de 5 velocidades', '35' );
$hidden = $make( 'Sartén oculta de prueba', '1', 'private' );
CVD_Product_Search::flush();

$assert( in_array( $pan, CVD_Product_Search::search( 'sartenes' ), true ), 'el plural debe encontrar la sartén.' );
$assert( in_array( $pan, CVD_Product_Search::search( 'SARTEN' ), true ), 'debe ignorar acentos y mayúsculas.' );
$assert( in_array( $pan, CVD_Product_Search::search( 'quiero una paila' ), true ), 'el sinónimo paila debe encontrar la sartén.' );
$assert( array( $towel ) === array_values( array_intersect( CVD_Product_Search::search( 'toallas baño' ), array( $towel, $pan, $blend ) ) ), 'todas las palabras deben coincidir con la toalla.' );
$assert( ! in_array( $hidden, CVD_Product_Search::search( 'sarten' ), true ), 'no debe mostrar productos privados.' );
$assert( array() === CVD_Product_Search::search( 'de la para' ), 'solo palabras vacías no busca nada.' );

$cards = CVD_Product_Search::cards( array( $blend ) );
$assert( 1 === count( $cards ) && false !== strpos( $cards[0]['price'], '35' ) && $cards[0]['quickAdd'], 'la tarjeta usa el precio real de WooCommerce.' );

$answer = CVD_Contextual_Assistant::answer( '¿tienen licuadoras?', 'visitante' );
$assert( $blend === ( $answer['products'][0]['id'] ?? 0 ), 'Curru debe responder con la licuadora real.' );
$shipping = CVD_Contextual_Assistant::answer( '¿Cuánto cuesta la mensajería?', 'visitante' );
$assert( array() === $shipping['products'] && ! empty( $shipping['links'] ), 'la mensajería responde con la tabla oficial, sin productos.' );
$none = CVD_Contextual_Assistant::answer( 'xilofono cuantico', 'visitante' );
$assert( array() === $none['products'] && false !== strpos( $none['answer'], 'No encontré' ), 'sin resultados lo dice claramente.' );
$order = CVD_Contextual_Assistant::answer( 'dónde está mi pedido', 'mensajero' );
$assert( false !== strpos( $order['links'][0]['url'], 'ruta-cv' ), 'el mensajero va a su Ruta.' );

foreach ( array( $pan, $towel, $blend, $hidden ) as $id ) { wp_delete_post( $id, true ); }
CVD_Product_Search::flush();
echo "OK: Curru y búsqueda verificados.\n";
