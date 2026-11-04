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
