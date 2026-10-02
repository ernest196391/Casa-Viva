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

// La búsqueda de la tienda usa el mismo motor (consulta principal de producto).
global $wp_the_query, $wp_query;
$shop = new WP_Query();
$wp_the_query = $shop; // is_main_query() compara con esta consulta.
$shop->query( array( 's' => 'pailas', 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => 20 ) );
$assert( in_array( $pan, $shop->posts, true ) && ! in_array( $hidden, $shop->posts, true ), 'el buscador de la tienda debe encontrar la sartén con «pailas».' );
$wp_the_query = $wp_query;

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

// IA: solo redacta; productos limitados a candidatos reales y precio del servidor; fallo = respuesta local.
$local = CVD_Contextual_Assistant::answer( 'quiero una sartén', 'visitante' );
$assert( CVD_Curru_AI::improve( 'quiero una sartén', $local, array(), 'Curru' ) === $local, 'sin clave usa la respuesta local.' );
update_option( CVD_Curru_AI::OPTION_KEY, 'sk-prueba' );
$mock = static function ( $pre, $args, $url ) use ( $pan, $hidden ) {
	if ( false === strpos( $url, '/chat/completions' ) ) { return $pre; }
	$content = wp_json_encode( array( 'answer' => '¡Claro! Esta sartén te va a encantar por $1.', 'productIds' => array( $pan, $hidden, 999999 ) ) );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $content ) ) ) ) ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$ai = CVD_Curru_AI::improve( 'quiero una sartén', $local, CVD_Curru_AI::history( array( array( 'role' => 'user', 'text' => 'hola' ) ) ), 'Curru' );
$assert( ! empty( $ai['ai'] ) && array( $pan ) === array_column( $ai['products'], 'id' ), 'la IA solo puede elegir productos reales y visibles.' );
$assert( false !== strpos( $ai['products'][0]['price'], '18' ), 'el precio de la tarjeta sale de WooCommerce, no de la IA.' );
remove_filter( 'pre_http_request', $mock, 10 );
$fail = static fn( $pre, $args, $url ) => false !== strpos( $url, '/chat/completions' ) ? new WP_Error( 'http_request_failed', 'sin red' ) : $pre;
add_filter( 'pre_http_request', $fail, 10, 3 );
$assert( CVD_Curru_AI::improve( 'quiero una sartén', $local, array(), 'Curru' ) === $local, 'si la IA falla responde la regla local.' );
remove_filter( 'pre_http_request', $fail, 10 );
delete_option( CVD_Curru_AI::OPTION_KEY );

foreach ( array( $pan, $towel, $blend, $hidden ) as $id ) { wp_delete_post( $id, true ); }
CVD_Product_Search::flush();
echo "OK: Curru y búsqueda verificados.\n";
