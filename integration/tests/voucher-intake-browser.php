<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$page = get_page_by_path( 'interpretar-vale' );
if ( ! $page instanceof WP_Post ) { throw new RuntimeException( 'Página interpretar-vale ausente.' ); }
wp_update_post( array( 'ID'=>$page->ID, 'post_content'=>'[casa_viva_voucher_intake]', 'post_status'=>'publish' ) );
echo wp_json_encode( array( 'page_id'=>$page->ID ) ) . PHP_EOL;
