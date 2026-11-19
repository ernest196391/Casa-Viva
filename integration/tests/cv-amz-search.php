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
CVD_Product_Search::flush();
echo wp_json_encode( $ids ) . PHP_EOL;
