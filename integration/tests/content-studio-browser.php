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
