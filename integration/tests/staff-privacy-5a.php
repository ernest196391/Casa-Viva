<?php

defined( 'ABSPATH' ) || exit;

function cvt_5a_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 5A: {$message}\n" );
		exit( 1 );
	}
}

$clerk = get_user_by( 'login', 'cvt_clerk' );
$admin = get_user_by( 'login', 'cvt_admin' );
$gestora = get_user_by( 'login', 'cvt_gestora' );

cvt_5a_assert( $clerk instanceof WP_User, 'Falta la dependienta sintética.' );
cvt_5a_assert( $admin instanceof WP_User, 'Falta el administrador sintético.' );
cvt_5a_assert( $gestora instanceof WP_User, 'Falta la gestora sintética.' );
cvt_5a_assert( user_can( $clerk, 'cvd_manage_sales' ), 'La dependienta no conserva cvd_manage_sales.' );
cvt_5a_assert( ! user_can( $clerk, 'manage_woocommerce' ), 'La dependienta heredó permisos administrativos.' );
cvt_5a_assert( user_can( $admin, 'manage_woocommerce' ), 'El administrador no conserva manage_woocommerce.' );
cvt_5a_assert( false !== has_filter( 'rest_post_dispatch', array( 'CVD_Staff_Privacy', 'filter_response' ) ), 'La frontera de privacidad no está registrada.' );

$order = wc_create_order();
cvt_5a_assert( $order instanceof WC_Order, 'No se pudo crear el pedido sintético.' );
$order->set_status( 'processing' );
$order->set_billing_first_name( 'Privacidad5A' );
$order->set_billing_last_name( 'Sintética' );
$order->set_billing_phone( '+5355550505' );
$order->update_meta_data( '_cvd_fulfillment_type', 'pickup' );
$order->update_meta_data( '_cvd_operation_status', 'new' );
$order->update_meta_data( '_cvd_owner_user_id', $gestora->ID );
$order->update_meta_data( '_cvd_owner_type', 'gestora' );
$order->update_meta_data( 'gestora_nombre', 'Gestora privada 5A' );
$order->update_meta_data( '_cvd_commission_amount', '99.99' );
$order->update_meta_data( '_cvd_commission_status', 'approved' );
$order->save();

$server = rest_get_server();
$sales_request = new WP_REST_Request( 'GET', '/casa-viva/v1/sales' );
$sales_request->set_param( 'search', 'Privacidad5A' );
$order_center_request = new WP_REST_Request( 'GET', '/casa-viva/v1/order-center/' . $order->get_id() );

wp_set_current_user( $clerk->ID );
$clerk_sales = CVD_Staff_Privacy::filter_response( CVD_Sales::orders( $sales_request ), $server, $sales_request );
$clerk_sales_data = $clerk_sales->get_data();
cvt_5a_assert( 1 === count( $clerk_sales_data['orders'] ), 'La dependienta no recibió el pedido operativo esperado.' );
$clerk_order = $clerk_sales_data['orders'][0];
foreach ( array( 'gestora', 'commission', 'commissionStatus', 'adminUrl' ) as $forbidden ) {
	cvt_5a_assert( ! array_key_exists( $forbidden, $clerk_order ), "La dependienta recibió {$forbidden}." );
}
cvt_5a_assert( isset( $clerk_order['customer'], $clerk_order['products'], $clerk_order['actions'] ), 'La privacidad eliminó datos necesarios para operar.' );

$clerk_projection_raw = new WP_REST_Response( CVD_Order_Center::project( $order, $clerk->ID ) );
$clerk_projection = CVD_Staff_Privacy::filter_response( $clerk_projection_raw, $server, $order_center_request )->get_data();
cvt_5a_assert( ! array_key_exists( 'gestora', $clerk_projection ), 'El Centro Único filtró datos de gestora a la dependienta.' );
cvt_5a_assert( ! array_key_exists( 'commission_summary', $clerk_projection ), 'El Centro Único filtró estado de comisión a la dependienta.' );
cvt_5a_assert( isset( $clerk_projection['customer'], $clerk_projection['items'], $clerk_projection['operation'], $clerk_projection['available_actions'] ), 'El filtro rompió la proyección operativa de dependienta.' );

wp_set_current_user( $admin->ID );
$admin_sales = CVD_Staff_Privacy::filter_response( CVD_Sales::orders( $sales_request ), $server, $sales_request )->get_data();
cvt_5a_assert( isset( $admin_sales['orders'][0]['gestora'], $admin_sales['orders'][0]['commission'], $admin_sales['orders'][0]['commissionStatus'], $admin_sales['orders'][0]['adminUrl'] ), 'Administración perdió datos comerciales del Centro de ventas.' );

$admin_projection_raw = new WP_REST_Response( CVD_Order_Center::project( $order, $admin->ID ) );
$admin_projection = CVD_Staff_Privacy::filter_response( $admin_projection_raw, $server, $order_center_request )->get_data();
cvt_5a_assert( isset( $admin_projection['gestora'], $admin_projection['commission_summary'] ), 'Administración perdió la vista comercial del Centro Único.' );

wp_set_current_user( 0 );
$order->delete( true );

echo "OK 5A: dependienta limitada a datos operativos y administración conserva la vista completa.\n";
