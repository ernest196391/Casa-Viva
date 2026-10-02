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

// Existencias: exactas para gestoras y operación; para clientes solo estados.
$robe = new WC_Product_Variable();
$robe->set_name( 'Albornoz de algodón' );
$robe->set_status( 'publish' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Color' );
$attribute->set_options( array( 'Azul', 'Blanco' ) );
$attribute->set_variation( true );
$attribute->set_visible( true );
$robe->set_attributes( array( $attribute ) );
$robe_id = $robe->save();
foreach ( array( 'Azul' => 3, 'Blanco' => 5 ) as $color => $qty ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $robe_id );
	$variation->set_attributes( array( 'color' => $color ) );
	$variation->set_regular_price( '22' );
	$variation->set_manage_stock( true );
	$variation->set_stock_quantity( $qty );
	$variation->set_status( 'publish' );
	$variation->save();
}
WC_Product_Variable::sync( $robe_id );
$few = new WC_Product_Simple();
$few->set_name( 'Sábana de lino beige' );
$few->set_regular_price( '30' );
$few->set_status( 'publish' );
$few->set_manage_stock( true );
$few->set_stock_quantity( 2 );
$few->set_low_stock_amount( 3 );
$few_id = $few->save();
CVD_Product_Search::flush();
$public = CVD_Product_Search::cards( array( $robe_id, $few_id ) );
$assert( ! isset( $public[0]['stock'] ) && ! isset( $public[0]['variants'] ) && 'Disponible' === $public[0]['stockLabel'], 'la clienta no ve cantidades.' );
$assert( 'Últimas unidades' === $public[1]['stockLabel'], 'stock en el umbral se muestra como últimas unidades.' );
$exact = CVD_Product_Search::cards( array( $robe_id, $few_id ), true );
$assert( 'Quedan 8 en total' === $exact[0]['stockLabel'] && 2 === count( $exact[0]['variants'] ), 'la gestora ve el total y cada modelo.' );
$assert( array( 3, 5 ) === array_column( $exact[0]['variants'], 'stock' ), 'cada modelo trae su cantidad real.' );
$assert( 'Quedan 2' === $exact[1]['stockLabel'], 'la gestora ve la cantidad exacta.' );
$g = CVD_Contextual_Assistant::answer( '¿Cuántos albornoces quedan de cada modelo?', 'gestora' );
$assert( false !== strpos( $g['answer'], 'Existencias ahora mismo' ) && false !== strpos( $g['answer'], 'Azul' ) && false !== strpos( $g['answer'], '3' ), 'Curru da a la gestora las existencias por modelo: ' . $g['answer'] );
$c = CVD_Contextual_Assistant::answer( '¿Cuántos albornoces quedan?', 'cliente' );
$assert( false === strpos( $c['answer'], 'Existencias' ) && ! isset( $c['products'][0]['stock'] ), 'Curru no da cantidades a la clienta.' );
$assert( 'Últimas unidades' === CVD_Premium_Storefront::availability_text( '2 disponibles', wc_get_product( $few_id ) ), 'la ficha muestra estados a clientes.' );
foreach ( array_merge( wc_get_product( $robe_id )->get_children(), array( $robe_id, $few_id ) ) as $id ) { wp_delete_post( $id, true ); }
CVD_Product_Search::flush();

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
