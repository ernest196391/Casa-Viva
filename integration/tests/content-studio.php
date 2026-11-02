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
