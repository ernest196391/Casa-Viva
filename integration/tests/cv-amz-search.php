<?php
defined( 'ABSPATH' ) || exit;
if ( wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Disposable environment only' ); }
update_option( 'woocommerce_currency', 'USD' );
function cvamz_product( $name, $sku, $price, $status = 'instock', $visibility = 'visible' ) {
    $product = new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_sku( $sku );
    $product->set_regular_price( (string) $price );
    $product->set_status( 'publish' );
    $product->set_catalog_visibility( $visibility );
    $product->set_stock_status( $status );
    return $product->save();
}
$ids = array(
    'fan' => cvamz_product( 'Ventilador CVAMZ', 'CVAMZ-FAN', 100 ),
    'accessory' => cvamz_product( 'Aceite para ventilador CVAMZ', 'CVAMZ-OIL', 20 ),
    'supplier' => cvamz_product( 'Ventilador CVAMZ proveedor', 'MK-CVAMZ-FAN', 80 ),
    'out' => cvamz_product( 'Ventilador CVAMZ agotado', 'CVAMZ-OUT', 70, 'outofstock' ),
    'hidden' => cvamz_product( 'Ventilador CVAMZ oculto', 'CVAMZ-HIDDEN', 60, 'instock', 'hidden' ),
);
$user_id = wp_create_user( 'cvamz_gestora', 'Synthetic-Only-CVAMZ!', 'cvamz@example.invalid' );
if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
$user = new WP_User( $user_id );
$user->set_role( 'cvd_gestora' );
update_user_meta( $user_id, '_cvd_account_status', 'approved' );
update_user_meta( $user_id, '_cvd_program_type', 'gestora' );
update_user_meta( $user_id, '_cvd_referral_code', 'CVAMZTEST' );
update_user_meta( $user_id, '_cvd_global_markup_percent', 20 );
if ( ! CVD_Datos_A_Mano::is_bajo_pedido( wc_get_product( $ids['supplier'] ) ) ) { throw new RuntimeException( 'Supplier fixture not recognized' ); }
// Native related-product eligibility, including stale cached candidate IDs.
if ( ! has_filter( 'woocommerce_related_products', array( 'CVD_Product_Search', 'eligible_related_products' ) ) ) {
    throw new RuntimeException( 'Related products hook not registered' );
}
$candidates = array( $ids['fan'], $ids['accessory'], $ids['supplier'], $ids['out'], $ids['hidden'], $ids['fan'], 0 );
$related = apply_filters( 'woocommerce_related_products', $candidates, $ids['accessory'], array() );
if ( $related !== array( $ids['fan'] ) ) { throw new RuntimeException( 'Ineligible related products: ' . wp_json_encode( $related ) ); }
$fan = wc_get_product( $ids['fan'] );
$fan->set_stock_status( 'outofstock' );
$fan->save();
if ( apply_filters( 'woocommerce_related_products', $candidates, $ids['accessory'], array() ) !== array() ) {
    throw new RuntimeException( 'Stale recommendation survived live stock change' );
}
$fan->set_stock_status( 'instock' );
$fan->save();
// Optional scale benchmark; never run against a real store.
if ( getenv( 'CVAMZ_SCALE' ) === '1000' ) {
    global $wpdb;
    if ( $wpdb->prefix !== 'cvt_' ) { throw new RuntimeException( 'Synthetic database required' ); }
    $types = array( 'Nevera', 'Ventilador', 'Sartén', 'Licuadora', 'Toalla', 'Generador', 'Lámpara', 'Alfombra', 'Cocina', 'Batería' );
    for ( $i = 0; $i < 995; $i++ ) {
        $type = $types[ $i % count( $types ) ];
        cvamz_product( $type . ' Modelo ensayo ' . $i, 'CVBENCH-' . $i,
            15 + ( $i % 400 ), $i % 7 === 0 ? 'outofstock' : 'instock',
            $i % 23 === 0 ? 'hidden' : 'visible' );
    }
    $count = (int) wp_count_posts( 'product' )->publish;
    if ( $count !== 1000 ) { throw new RuntimeException( 'Expected exactly 1000 synthetic products, got ' . $count ); }
    $ids['catalog_size'] = $count;
}
CVD_Product_Search::flush();
echo wp_json_encode( $ids ) . PHP_EOL;
