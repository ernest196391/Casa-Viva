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
