<?php
/** Run only in the disposable integration database. Real WooCommerce CRUD and REST permissions. */
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Disposable localhost environment required.' ); }
function studio_assert( $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; }
function studio_call( string $method, string $path, array $data = array() ) {
	$r = new WP_REST_Request( $method, '/casa-viva/v1/contenido/' . $path );
	if ( 'GET' === $method ) { $r->set_query_params( $data ); } else { $r->set_body_params( $data ); }
	return rest_do_request( $r );
}
$login = 'cv_content_integration'; $id = username_exists( $login );
if ( ! $id ) { $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) ); }
studio_assert( ! is_wp_error( $id ), 'test account created' );
$user = new WP_User( $id ); $user->add_cap( CVD_Content_Studio::CAP );
$p = new WC_Product_Simple(); $p->set_name( 'Lámpara integración' ); $p->set_description( '<p>Metal, 30 cm.</p>' );
$p->set_regular_price( '25' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 8 ); $p->set_sku( 'CV-STUDIO-' . wp_generate_uuid4() ); $p->set_status( 'publish' ); $p->save();
wp_set_current_user( 0 );
studio_assert( studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_status() >= 400, 'anonymous REST access denied' );
wp_set_current_user( $id ); $user->remove_cap( CVD_Content_Studio::CAP );
wp_set_current_user( 0 ); wp_set_current_user( $id );
studio_assert( studio_call( 'GET', 'products' )->get_status() === 403, 'subscriber REST access denied' );
$user->add_cap( CVD_Content_Studio::CAP ); wp_set_current_user( 0 ); wp_set_current_user( $id );
studio_assert( ! current_user_can( 'manage_woocommerce' ), 'editor has no store administration permission' );
$r = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) ); studio_assert( 200 === $r->get_status(), 'editor can read product' );
$item = $r->get_data();
$edit = array( 'id' => $p->get_id(), 'version' => $item['version'], 'name' => 'Lámpara de metal revisada', 'description' => '<p>Metal. Altura confirmada: 30 cm.</p><script>bad()</script>', 'short' => 'Metal, 30 cm.', 'price' => '1', 'stock_quantity' => 0, 'brief' => array( 'facts' => 'Metal, 30 cm.', 'buyer' => 'Persona que necesita iluminación de mesa.' ) );
$r = studio_call( 'POST', 'save', $edit ); studio_assert( 200 === $r->get_status(), 'editor saves through real REST route' );
$p = wc_get_product( $p->get_id() );
studio_assert( '25' === $p->get_regular_price() && 8 === $p->get_stock_quantity(), 'real Woo price and stock preserved' );
studio_assert( ! str_contains( $p->get_description(), '<script' ) && (int) $p->get_meta( CVD_Content_Studio::LOCK ) === (int) $id, 'real sanitation and ownership stored' );
studio_assert( 'Metal, 30 cm.' === $p->get_meta( '_cvd_content_brief' )['facts'], 'brief persists outside public description' );
$pending = new WP_REST_Request( 'GET' ); $pending->set_param( 'ids', (string) $p->get_id() );
studio_assert( empty( CVD_Catalogo_Auto::pendientes( $pending )->get_data()['productos'] ), 'automatic writer skips human-owned products' );
studio_assert( 409 === studio_call( 'POST', 'save', $edit )->get_status(), 'real REST rejects outdated version' );
$new = array( 'token' => wp_generate_uuid4(), 'name' => 'Mercancía nueva de prueba', 'description' => 'Descripción confirmada.', 'action' => 'save' );
$r = studio_call( 'POST', 'save', $new ); studio_assert( 200 === $r->get_status(), 'new draft saved' );
$draft = $r->get_data();
studio_assert( 'draft' === $draft['status'] && $draft['id'] === studio_call( 'POST', 'save', $new )->get_data()['id'], 'retry returns same real Woo draft' );
$publish = array_merge( $new, array( 'id' => $draft['id'], 'version' => $draft['version'], 'action' => 'publish' ) );
studio_assert( 422 === studio_call( 'POST', 'save', $publish )->get_status(), 'unpriced draft cannot publish' );
echo "Real WordPress content studio integration passed.\n";

// Exercise real REST permissions and multipart transport with a synthetic provider response.
define( 'CVD_PHOTO_AI_ACCOUNT', str_repeat( 'a', 32 ) );
define( 'CVD_PHOTO_AI_CLOUDFLARE', 'integration-only' );
define( 'CVD_PHOTO_AI_OPENAI', 'integration-only' );
wp_set_current_user( 0 );
studio_assert( studio_call( 'POST', 'ia/generate' )->get_status() === 401, 'anonymous generation denied' );
wp_set_current_user( $id );
studio_assert( studio_call( 'POST', 'ia/generate', array( 'provider' => 'openai' ) )->get_status() === 422, 'paid generation requires confirmation' );
$bytes = base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAIAAgDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AD//Z' );
$tmp = tempnam( sys_get_temp_dir(), 'cv-ai-' );
file_put_contents( $tmp, $bytes );
$fake_provider = static function ( $pre, $args, $url ) use ( $bytes ) {
	studio_assert( str_ends_with( $url, '/ai/run/@cf/black-forest-labs/flux-2-klein-4b' ), 'free route contacts only configured provider' );
	studio_assert( ! str_contains( $args['body'], 'input_image' ) && ! str_contains( $args['body'], $bytes ), 'provider only receives a backdrop prompt' );
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'result' => array( 'image' => base64_encode( $bytes ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $fake_provider, 10, 3 );
$request = new WP_REST_Request( 'POST', '/casa-viva/v1/contenido/ia/generate' );
$request->set_body_params( array( 'provider' => 'cloudflare', 'format' => '1920', 'layout' => 'features', 'mode' => 'background' ) );
$request->set_file_params( array( 'photo' => array( 'tmp_name' => $tmp, 'error' => 0 ) ) );
$before = wc_get_product( $p->get_id() )->get_image_id();
$result = rest_do_request( $request );
remove_filter( 'pre_http_request', $fake_provider, 10 );
unlink( $tmp );
studio_assert( 200 === $result->get_status() && str_starts_with( $result->get_data()['background'], 'data:image/jpeg;base64,' ), 'editor receives a reviewable image' );
studio_assert( $before === wc_get_product( $p->get_id() )->get_image_id(), 'generation does not publish a preview' );

// A review belongs to this editor, not the public product or another editor.
$before_item = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
$tmp = tempnam( sys_get_temp_dir(), 'cv-review-' );
file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=' ) );
$review_req = new WP_REST_Request( 'POST', '/casa-viva/v1/contenido/review' );
$review_req->set_body_params( array( 'id' => $p->get_id(), 'version' => $before_item['version'] ) );
$review_req->set_file_params( array( 'photo' => array( 'tmp_name' => $tmp, 'error' => 0 ) ) );
studio_assert( rest_do_request( $review_req )->get_status() === 200, 'editor saves a private preview' );
unlink( $tmp );
$after_item = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
studio_assert( str_starts_with( $after_item['review'], 'data:image/png;base64,' ) && $before_item['version'] === $after_item['version'], 'preview survives reopening without updating the catalog entry' );
wp_set_current_user( 1 );
studio_assert( empty( studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data()['review'] ), 'other users do not receive the editor preview' );

// After the product changes, the editor still gets the option, marked as old, with what changed.
$p = wc_get_product( $p->get_id() ); $p->set_short_description( 'Texto cambiado por otra persona.' ); $p->save();
wp_set_current_user( $id );
$stale = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
studio_assert( empty( $stale['review'] ) && str_starts_with( $stale['review_old'], 'data:image/png;base64,' ) && array( 'texto' ) === $stale['review_changed'], 'changed product keeps the old option and says the text changed' );
studio_assert( wc_get_product( $p->get_id() )->get_image_id() === $before, 'old option is not applied to the product' );
$legacy = get_user_meta( $id, '_cvd_content_review_' . $p->get_id(), true );
update_user_meta( $id, '_cvd_content_review_' . $p->get_id(), array( 'version' => 'antigua', 'image' => $legacy['image'] ) );
$stale = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
studio_assert( $legacy['image'] === $stale['review_old'] && array() === $stale['review_changed'], 'option saved before this change is still shown without guessing what changed' );
wp_set_current_user( 1 );
studio_assert( empty( studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data()['review_old'] ), 'other users do not receive an old editor option' );

// Logging in with the editor account opens the panel, not the shop.
$panel = add_query_arg( 'cvd_contenido', '1', home_url( '/' ) );
studio_assert( $panel === apply_filters( 'login_redirect', $panel, $panel, get_user_by( 'id', $id ) ), 'editor login lands on the catalog panel' );
studio_assert( $panel === apply_filters( 'login_redirect', home_url( '/' ), '', get_user_by( 'id', $id ) ), 'editor login without a destination also lands on the panel' );

// A full-size option is kept as JPEG so it fits the private review limit.
wp_set_current_user( $id );
$current = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
$tmp = tempnam( sys_get_temp_dir(), 'cv-review-' );
$gd = imagecreatetruecolor( 2, 2 ); imagejpeg( $gd, $tmp, 95 ); imagedestroy( $gd );
$review_req = new WP_REST_Request( 'POST', '/casa-viva/v1/contenido/review' );
$review_req->set_body_params( array( 'id' => $p->get_id(), 'version' => $current['version'] ) );
$review_req->set_file_params( array( 'photo' => array( 'tmp_name' => $tmp, 'error' => 0 ) ) );
studio_assert( rest_do_request( $review_req )->get_status() === 200, 'editor saves a JPEG option' );
unlink( $tmp );
studio_assert( str_starts_with( studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data()['review'], 'data:image/jpeg;base64,' ), 'JPEG option comes back as JPEG' );

// A real customer review is saved only with the customer's permission, as a store review.
$denied = studio_call( 'POST', 'resena', array( 'id' => $p->get_id(), 'text' => 'Llegó rápido y es muy bonito.', 'name' => 'María González', 'rating' => '5', 'permission' => '0' ) );
studio_assert( 422 === $denied->get_status() && 0 === (int) get_comments( array( 'post_id' => $p->get_id(), 'type' => 'review', 'count' => true ) ), 'review without permission is not saved' );
$saved = studio_call( 'POST', 'resena', array( 'id' => $p->get_id(), 'text' => 'Llegó rápido y es muy bonito.', 'name' => 'María González', 'rating' => '5', 'permission' => '1' ) );
studio_assert( 200 === $saved->get_status(), 'review with permission is saved' );
$comment = get_comment( $saved->get_data()['saved'] );
studio_assert( 'review' === $comment->comment_type && '1' === (string) $comment->comment_approved && 5 === (int) get_comment_meta( $comment->comment_ID, 'rating', true ) && 'whatsapp' === get_comment_meta( $comment->comment_ID, '_cvd_resena_origen', true ), 'review stored as an approved store review with stars and origin' );
$item = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data();
studio_assert( 'María González' === $item['reviews'][0]['name'] && 5 === $item['reviews'][0]['rating'], 'the panel receives the review for the card' );

// Ad measurement: link with a code, the bot reports who wrote, and an order from that phone within 14 days counts.
$ad = studio_call( 'POST', 'anuncio', array( 'id' => $p->get_id(), 'layout' => 'features', 'format' => '1920' ) )->get_data();
studio_assert( preg_match( '/^A\d+$/', $ad['code'] ) && str_contains( $ad['link'], 'wa.me/' ) && str_contains( rawurldecode( $ad['link'] ), '· ' . $ad['code'] ), 'ad link carries its code in the prefilled text' );
$bot = static fn( array $body ) => CVD_Content_Studio::anuncio_mensaje( ( static function ( $b ) { $q = new WP_REST_Request( 'POST', '/casa-viva/v1/bot/anuncio/mensaje' ); $q->set_body_params( $b ); return $q; } )( $body ) )->get_data();
studio_assert( true === $bot( array( 'code' => $ad['code'], 'phone' => '+53 5123 4567' ) )['nuevo'] && false === $bot( array( 'code' => $ad['code'], 'phone' => '5351234567' ) )['nuevo'], 'the same phone is counted once' );
studio_assert( false === $bot( array( 'code' => 'A999999', 'phone' => '5351234567' ) )['ok'], 'unknown codes are ignored' );
$order = wc_create_order(); $order->set_billing_phone( '51234567' ); $order->set_total( 25 ); $order->set_status( 'processing' ); $order->save();
$other = wc_create_order(); $other->set_billing_phone( '59999999' ); $other->set_total( 40 ); $other->set_status( 'processing' ); $other->save();
$stats = studio_call( 'GET', 'products', array( 'id' => $p->get_id() ) )->get_data()['anuncios'][0];
studio_assert( $ad['code'] === $stats['code'] && 1 === $stats['mensajes'] && 1 === $stats['pedidos'] && str_contains( $stats['total'], '25' ), 'the ad shows one person who wrote and one order from that phone' );
$route = rest_do_request( new WP_REST_Request( 'POST', '/casa-viva/v1/bot/anuncio/mensaje' ) );
studio_assert( $route->get_status() >= 400, 'the bot route needs the bot key' );

// v2 (10-nov): HERO by steps with the VPS simulated ONLY in this disposable process (filters), automatic publication
// (D152) with copy «antes» and restore, real WooCommerce data untouched, and the inbox through the real REST routes.
$v2_png = static function ( int $w, int $h ): string {
	$im = imagecreatetruecolor( $w, $h ); imagealphablending( $im, false ); imagesavealpha( $im, true );
	imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
	imagefilledrectangle( $im, 2, 2, $w - 3, $h - 3, imagecolorallocatealpha( $im, 30, 60, 90, 0 ) );
	ob_start(); imagepng( $im ); return (string) ob_get_clean();
};
$v2_jpg = static function ( int $w, int $h ): string {
	$im = imagecreatetruecolor( $w, $h ); imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, 0xF6F6F6 );
	imagefilledrectangle( $im, (int) ( $w * 0.2 ), (int) ( $h * 0.2 ), (int) ( $w * 0.8 ), (int) ( $h * 0.8 ), 0x203040 );
	ob_start(); imagejpeg( $im, null, 90 ); return (string) ob_get_clean();
};
$v2_mode = array( 'recorte' => 'fiable', 'ia' => 0 );
$v2_recorte = static function () use ( &$v2_mode, $v2_png ) {
	return static function ( string $bytes ) use ( &$v2_mode, $v2_png ) {
		return array( 'png' => $v2_png( 900, 500 ), 'fiable' => 'fiable' === $v2_mode['recorte'], 'motivos' => 'fiable' === $v2_mode['recorte'] ? array() : array( 'Borró las barras finas' ), 'medidas' => array() );
	};
};
$v2_motor = static function () use ( &$v2_mode, $v2_jpg ) {
	return static function ( string $prompt, array $opts, array $refs ) use ( &$v2_mode, $v2_jpg ) {
		$v2_mode['ia']++;
		return '1536x1024' === $opts['tam'] ? $v2_jpg( 1536, 1024 ) : $v2_jpg( 1024, 1536 );
	};
};
add_filter( 'cvd_content_recorte', $v2_recorte );
add_filter( 'cvd_content_motor', $v2_motor );
wp_set_current_user( $id );
$up = wp_upload_bits( 'cv-v2-real-' . wp_generate_password( 6, false ) . '.jpg', null, $v2_jpg( 1200, 800 ) );
$real = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Foto real', 'post_status' => 'inherit' ), $up['file'] );
require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata( $real, wp_generate_attachment_metadata( $real, $up['file'] ) );
$hp = new WC_Product_Simple(); $hp->set_name( 'HERO integración' ); $hp->set_description( '<p>Metal.</p>' ); $hp->set_regular_price( '30' ); $hp->set_manage_stock( true ); $hp->set_stock_quantity( 5 );
$hp->set_sku( 'CV-V2-' . wp_generate_uuid4() ); $hp->set_image_id( $real ); $hp->update_meta_data( '_cvd_commission_type', 'percent' ); $hp->update_meta_data( '_cvd_commission_value', '10' ); $hp->set_status( 'publish' ); $hp->save();
$commerce = static function ( int $pid ): array {
	clean_post_cache( $pid ); $q = wc_get_product( $pid );
	return array( $q->get_price(), $q->get_regular_price(), $q->get_stock_quantity(), $q->get_sku(), $q->get_slug(), $q->get_permalink(), $q->get_status(), $q->get_meta( '_cvd_commission_type' ), $q->get_meta( '_cvd_commission_value' ) );
};
$before = $commerce( $hp->get_id() );
$r = studio_call( 'POST', 'crear', array( 'id' => $hp->get_id(), 'pieza' => 'hero' ) );
$d = $r->get_data();
studio_assert( 200 === $r->get_status() && 'recorte' === $d['escalon'] && 0.0 === (float) $d['coste'] && $d['publicada'] && 0 === $v2_mode['ia'], 'v2: free cut-out → white HERO published by itself, nothing paid' );
clean_post_cache( $hp->get_id() ); $hero = (int) wc_get_product( $hp->get_id() )->get_image_id();
$meta = wp_get_attachment_metadata( $hero );
studio_assert( $hero !== $real && 'image/webp' === get_post_mime_type( $hero ) && 1600 === (int) ( $meta['width'] ?? 0 ) && 1600 === (int) ( $meta['height'] ?? 0 ), 'v2: the web HERO is a WebP 1600×1600 attachment' );
studio_assert( in_array( $real, wc_get_product( $hp->get_id() )->get_gallery_image_ids(), true ) && $before === $commerce( $hp->get_id() ), 'v2: real photo kept in the gallery; price, stock, SKU, URL and commission untouched' );
$r = studio_call( 'POST', 'restaurar', array( 'id' => $hp->get_id() ) );
clean_post_cache( $hp->get_id() );
studio_assert( 200 === $r->get_status() && $real === (int) wc_get_product( $hp->get_id() )->get_image_id() && $before === $commerce( $hp->get_id() ), 'v2: restaurar puts back the previous photo in one touch' );
$v2_mode['recorte'] = 'nofiable';
$r = studio_call( 'POST', 'crear', array( 'id' => $hp->get_id(), 'pieza' => 'hero' ) ); $d = $r->get_data();
studio_assert( 200 === $r->get_status() && 'ia' === $d['escalon'] && 'Borró las barras finas' === $d['escalones'][0]['motivo'] && 1 === $v2_mode['ia'] && 0.15 === (float) $d['gasto']['hoy'], 'v2: cut-out not fiable → paid IA, cost counted for today' );
update_option( 'cvd_content_gasto', array( 'dia' => wp_date( 'Y-m-d' ), 'usd' => 0.45 ), false );
studio_assert( 429 === studio_call( 'POST', 'crear', array( 'id' => $hp->get_id(), 'pieza' => 'hero' ) )->get_status(), 'v2: daily cap blocks paid images' );
$v2_mode['recorte'] = 'fiable';
studio_assert( 200 === studio_call( 'POST', 'crear', array( 'id' => $hp->get_id(), 'pieza' => 'hero' ) )->get_status(), 'v2: the free cut-out is never blocked by the cap' );
delete_option( 'cvd_content_gasto' );
$item = studio_call( 'GET', 'products', array( 'id' => $hp->get_id() ) )->get_data();
$opt = $item['opciones'][0]['id'];
$r = studio_call( 'POST', 'pieza', array( 'id' => $hp->get_id(), 'pieza' => 'hero', 'estado' => 'revision', 'opcion' => $opt ) );
studio_assert( 200 === $r->get_status() && preg_match( '/^D\d+$/', (string) $r->get_data()['duda'] ), 'v2: «Revisión» sends the option to the inbox' );
$list = studio_call( 'GET', 'dudas', array( 'estado' => 'pendiente' ) );
$card = $list->get_data()['dudas'][0];
studio_assert( 200 === $list->get_status() && $list->get_data()['activa'] && 'foto' === $card['tipo'] && 'HERO' === $card['pieza'], 'v2: the inbox lists the card through REST' );
$answer = static function ( string $duda, array $body ) {
	$q = new WP_REST_Request( 'POST', '/casa-viva/v1/contenido/dudas/' . $duda . '/respuesta' ); $q->set_body_params( $body ); return rest_do_request( $q );
};
studio_assert( 409 === $answer( $card['id'], array( 'respuesta' => 'aprobada', 'version' => 'vieja' ) )->get_status(), 'v2: an outdated answer gets 409' );
$r = $answer( $card['id'], array( 'respuesta' => 'aprobada', 'version' => $card['version'] ) );
clean_post_cache( $hp->get_id() );
studio_assert( 200 === $r->get_status() && 'hecha' === $r->get_data()['estado'] && 'web' === $r->get_data()['destino'] && 'aprobada' === studio_call( 'GET', 'products', array( 'id' => $hp->get_id() ) )->get_data()['piezas']['hero']['estado'], 'v2: approving the card puts that HERO on the web' );
studio_assert( $before === $commerce( $hp->get_id() ), 'v2: commerce still untouched after every HERO' );
// The VPS side, with the bot key.
update_option( 'cvd_vivabot_key_hash', hash( 'sha256', 'integration-bot-key' ) );
$bot = static function ( string $method, string $path, array $json = array(), bool $key = true ) {
	$q = new WP_REST_Request( $method, '/casa-viva/v1/bot/' . $path );
	if ( $key ) { $q->set_header( 'X-VivaBot-Key', 'integration-bot-key' ); }
	if ( 'GET' === $method ) { $q->set_query_params( $json ); } else { $q->set_header( 'content-type', 'application/json' ); $q->set_body( wp_json_encode( $json ) ); }
	return rest_do_request( $q );
};
studio_assert( $bot( 'GET', 'dudas/respuestas', array(), false )->get_status() >= 400 && $bot( 'POST', 'dudas', array( 'tipo' => 'regla', 'regla' => 'x' ), false )->get_status() >= 400, 'v2: the bot inbox routes need the bot key' );
$r = $bot( 'POST', 'dudas', array( 'dudas' => array( array( 'ref' => 'integ-dato', 'tipo' => 'dato', 'producto' => array( 'id' => $hp->get_id() ), 'dato' => 'Medida', 'despues' => array( 'valor' => '30 cm' ) ),
	array( 'ref' => 'integ-regla', 'tipo' => 'regla', 'regla' => 'Mango a la derecha' ) ) ) );
studio_assert( 200 === $r->get_status() && 2 === count( $r->get_data()['dudas'] ), 'v2: the VPS creates cards' );
$dato = $r->get_data()['dudas'][0];
$r = $answer( $dato['id'], array( 'respuesta' => 'corregir', 'valor' => '32 cm', 'version' => $dato['version'] ) );
studio_assert( 200 === $r->get_status() && 'hecha' === $r->get_data()['estado'], 'v2: a corrected fact is applied' );
$out = $bot( 'GET', 'dudas/respuestas', array( 'desde' => 0 ) )->get_data();
studio_assert( in_array( 'crm_knowledge', array_column( $out['respuestas'], 'destino' ), true ) && '32 cm' === end( $out['respuestas'] )['valor'] && $out['cursor'] > 0, 'v2: the VPS reads what to apply, with a cursor' );
remove_filter( 'cvd_content_recorte', $v2_recorte ); remove_filter( 'cvd_content_motor', $v2_motor );
echo "Panel v2 server integration passed.\n";
