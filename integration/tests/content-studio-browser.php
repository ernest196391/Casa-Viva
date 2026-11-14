<?php
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Local test environment required.' ); }
$id = username_exists( 'cvt_content_browser' );
if ( ! $id ) { $id = wp_insert_user( array( 'user_login' => 'cvt_content_browser', 'user_pass' => 'Synthetic-Content-Only-1!', 'role' => 'subscriber' ) ); }
if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
$user = new WP_User( $id ); $user->add_cap( CVD_Content_Studio::CAP );
$p = new WC_Product_Simple(); $p->set_name( 'Studio browser fixture' ); $p->set_description( '<p>Datos sintéticos para prueba.</p>' );
$p->set_regular_price( '25' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 8 ); $p->set_status( 'publish' ); $p->save();
echo wp_json_encode( array( 'id' => $p->get_id() ) ) . PHP_EOL;
// Published products for the switch-while-saving race, one per order.
foreach ( array( 'Studio race fixture 1', 'Studio race fixture 2' ) as $name ) {
	$race = new WC_Product_Simple(); $race->set_name( $name ); $race->set_description( '<p>Datos sintéticos para prueba.</p>' );
	$race->set_regular_price( '30' ); $race->set_status( 'publish' ); $race->save();
}
// A private option saved before the source photo and the text changed.
$stale = new WC_Product_Simple(); $stale->set_name( 'Studio stale review fixture' ); $stale->set_description( '<p>Datos sintéticos para prueba.</p>' ); $stale->set_status( 'draft' ); $stale->save();
update_user_meta( $id, '_cvd_content_review_' . $stale->get_id(), array( 'version' => 'antigua', 'photo' => 999999, 'text' => 'antiguo',
	'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=' ) );
// Descriptions for plain-text editing: one with simple structure, one with formatting that must stay HTML.
$simple = new WC_Product_Simple(); $simple->set_name( 'Studio text fixture' ); $simple->set_status( 'draft' );
$simple->set_description( "<p>Un cortinero para vestir\nla ventana.</p>\n<h3>Características</h3>\n<ul>\n<li>Metal negro</li>\n<li>Ligero</li>\n</ul>" ); $simple->set_short_description( '<p>Corto.</p>' ); $simple->save();
$rich = new WC_Product_Simple(); $rich->set_name( 'Studio rich text fixture' ); $rich->set_status( 'draft' );
$rich->set_description( '<p>Con <strong>negrita</strong>.</p>' ); $rich->save();
// Formatting the plain editor cannot keep (a link): it stays as HTML, with a note.
$link = new WC_Product_Simple(); $link->set_name( 'Studio link text fixture' ); $link->set_status( 'draft' );
$link->set_description( '<p>Ver <a href="https://example.com">enlace</a>.</p>' ); $link->save();
// A real store sale with an end date, and an offer already written by the editor.
$offer = new WC_Product_Simple(); $offer->set_name( 'Studio offer fixture' ); $offer->set_description( '<p>Datos sintéticos para prueba.</p>' );
$offer->set_regular_price( '30' ); $offer->set_sale_price( '25' ); $offer->set_date_on_sale_to( gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) . ' 23:59:59' );
$offer->update_meta_data( '_cvd_content_brief', array( 'offer' => 'Entrega gratis en La Habana' ) ); $offer->set_status( 'publish' ); $offer->save();
// A draft with price but no photo and no stock: it cannot go live until Lennys sets the stock (3.14.145).
$stock = new WC_Product_Simple(); $stock->set_name( 'Studio stock fixture' ); $stock->set_description( '<p>Datos sintéticos para prueba.</p>' );
$stock->set_regular_price( '20' ); $stock->set_status( 'draft' ); $stock->save();

// v2 (10-nov): data for the v2 panel — priorities, pieces, saved options, the simulated VPS (test-only mu-plugin) and an
// inbox with cards of the 5 types. The products above stay as they are for the current browser test.
$mu = WP_CONTENT_DIR . '/mu-plugins'; wp_mkdir_p( $mu );
if ( ! copy( __DIR__ . '/fixtures/cvd-motor-simulado.php', $mu . '/cvd-motor-simulado.php' ) ) { throw new RuntimeException( 'Simulated VPS not installed.' ); }
require_once $mu . '/cvd-motor-simulado.php';
update_option( 'cvd_test_motor_modo', 'fiable', false );
update_option( 'cvd_content_dudas_activa', '1', false );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_set_current_user( $id );
$v2_photo = static function ( string $name, int $w, int $h ): int {
	$up = wp_upload_bits( $name . '-' . wp_generate_password( 6, false ) . '.jpg', null, cvd_test_imagen( $w, $h, false ) );
	$att = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'] );
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
	update_post_meta( $att, '_cvd_foto_real', '1' );
	return (int) $att;
};
$v2_product = static function ( string $name, int $photo, string $status = 'publish' ): int {
	$q = new WC_Product_Simple(); $q->set_name( $name ); $q->set_description( '<p>Datos sintéticos para prueba.</p><p><strong>¿Trae herrajes?</strong> Sí.</p>' );
	$q->set_regular_price( '40' ); $q->set_manage_stock( true ); $q->set_stock_quantity( 6 ); $q->set_image_id( $photo ); $q->set_status( $status );
	return (int) $q->save();
};
$v2_call = static function ( string $method, string $path, array $body ) {
	$q = new WP_REST_Request( $method, '/casa-viva/v1/' . $path );
	if ( 'GET' === $method ) { $q->set_query_params( $body ); } else { $q->set_header( 'content-type', 'application/json' ); $q->set_body( wp_json_encode( $body ) ); }
	$r = rest_do_request( $q );
	if ( $r->get_status() >= 400 ) { throw new RuntimeException( $path . ': ' . wp_json_encode( $r->get_data() ) ); }
	return $r->get_data();
};
// 1) HERO published by itself (free cut-out), «Va a anuncios», matrix confirmed and a LIFESTYLE option to compare.
$hero_id = $v2_product( 'Studio v2 HERO fixture', $v2_photo( 'v2-real-ancho', 1200, 800 ) );
$v2_call( 'POST', 'contenido/crear', array( 'id' => $hero_id, 'pieza' => 'hero' ) );
$v2_call( 'POST', 'contenido/prioridad', array( 'id' => $hero_id, 'anuncios' => '1' ) );
$version = $v2_call( 'GET', 'contenido/products', array( 'id' => $hero_id ) )['version'];
$v2_call( 'POST', 'contenido/matriz', array( 'id' => $hero_id, 'version' => $version, 'duda' => '¿Se pega?', 'resultado' => 'Huevo sin aceite', 'prueba' => 'Antiadherente',
	'condicion' => 'Fuego medio', 'fuente' => 'Ficha · características' ) );
$v2_call( 'POST', 'contenido/crear', array( 'id' => $hero_id, 'pieza' => 'life' ) );
// 2) Options waiting to compare, nothing on the web yet: publication switched off only while the fixture runs.
add_filter( 'cvd_content_hero_auto', '__return_false' );
$cmp_id = $v2_product( 'Studio v2 compare fixture', $v2_photo( 'v2-real-alto', 700, 1100 ), 'draft' );
$v2_call( 'POST', 'contenido/crear', array( 'id' => $cmp_id, 'pieza' => 'hero' ) );
update_option( 'cvd_test_motor_modo', 'nofiable', false );
$v2_call( 'POST', 'contenido/crear', array( 'id' => $cmp_id, 'pieza' => 'hero' ) );
update_option( 'cvd_test_motor_modo', 'fiable', false );
remove_filter( 'cvd_content_hero_auto', '__return_false' );
// 3) A product with nothing yet (Falta HERO).
$none_id = $v2_product( 'Studio v2 sin HERO fixture', $v2_photo( 'v2-real-cuadrado', 900, 900 ) );
// 4) Inbox: cards of the 5 types, one in progress that already has a result to accept. Created as the VPS does.
$bot_hash = get_option( 'cvd_vivabot_key_hash', '' );
update_option( 'cvd_vivabot_key_hash', hash( 'sha256', 'browser-fixture-bot-key' ) );
$bot = static function ( string $path, array $json ) {
	$q = new WP_REST_Request( 'POST', '/casa-viva/v1/bot/' . $path ); $q->set_header( 'X-VivaBot-Key', 'browser-fixture-bot-key' );
	$q->set_header( 'content-type', 'application/json' ); $q->set_body( wp_json_encode( $json ) );
	$r = rest_do_request( $q );
	if ( $r->get_status() >= 400 ) { throw new RuntimeException( $path . ': ' . wp_json_encode( $r->get_data() ) ); }
	return $r->get_data();
};
$b64 = base64_encode( cvd_test_imagen( 1024, 1536, false ) );
$cards = $bot( 'dudas', array( 'dudas' => array(
	array( 'ref' => 'fx-foto', 'tipo' => 'foto', 'pieza' => 'HERO', 'producto' => array( 'id' => $none_id ), 'pregunta' => '¿Es el mismo producto?', 'origen' => 'noche',
		'origen_texto' => 'Noche · IA de pago · 0,15 USD', 'antes' => array( 'foto' => (string) wp_get_attachment_image_url( (int) get_post_thumbnail_id( $none_id ), 'large' ) ),
		'despues' => array( 'foto_b64' => $b64 ), 'escalon' => array( 'usado' => 'ia', 'coste_usd' => 0.15, 'fallo_previo' => 'Borró las barras finas' ) ),
	array( 'ref' => 'fx-dato', 'tipo' => 'dato', 'producto' => array( 'id' => $hero_id ), 'pregunta' => '¿Trae herrajes para instalarla?', 'origen' => 'grupos',
		'dato' => 'Herrajes de instalación', 'despues' => array( 'valor' => 'Sí, incluidos' ), 'opciones' => array( 'corregir' => 'texto' ) ),
	array( 'ref' => 'fx-texto', 'tipo' => 'texto', 'producto' => array( 'id' => $hero_id ), 'pregunta' => '¿Este título está bien?', 'campo' => 'name',
		'antes' => array( 'texto' => 'Studio v2 HERO fixture' ), 'despues' => array( 'texto' => 'Studio v2 HERO fixture revisado' ) ),
	array( 'ref' => 'fx-regla', 'tipo' => 'regla', 'pregunta' => '¿Siempre con el mango a la derecha?', 'regla' => 'Sartén: mango a la derecha, nunca cortado' ),
	array( 'ref' => 'fx-pedir', 'tipo' => 'pedir_foto', 'producto' => array( 'id' => $none_id ), 'origen_texto' => 'No se ve el producto entero' ),
	array( 'ref' => 'fx-marcha', 'tipo' => 'foto', 'pieza' => 'LIFESTYLE', 'producto' => array( 'id' => $hero_id ), 'pregunta' => '¿Se ve bien la escena?', 'estado' => 'marcha',
		'tarea' => array( 'id' => 'sup-fixture', 'texto' => 'Rehacer · IA de pago ~0,15 USD', 'listo' => false ), 'despues' => array( 'foto_b64' => $b64 ) ),
) ) );
$ids = array_column( $cards['dudas'], 'id' );
$bot( 'dudas/' . $ids[5] . '/resultado', array( 'tarea_id' => 'sup-fixture', 'foto_b64' => $b64 ) );
update_option( 'cvd_vivabot_key_hash', $bot_hash );
echo wp_json_encode( array( 'v2' => array( 'hero' => $hero_id, 'compare' => $cmp_id, 'sin_hero' => $none_id, 'dudas' => $ids ) ) ) . PHP_EOL;
