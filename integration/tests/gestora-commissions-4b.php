<?php

defined( 'ABSPATH' ) || exit;

function cvt_4b_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 4B: {$message}\n" );
		exit( 1 );
	}
}

function cvt_4b_money( $actual, float $expected, string $message ): void {
	cvt_4b_assert( abs( (float) $actual - $expected ) < 0.0001, $message . ' actual=' . (string) $actual . ' expected=' . (string) $expected );
}

function cvt_4b_gestora(): WP_User {
	$user_id = username_exists( 'cvt_gestora_4b' );
	if ( ! $user_id ) {
		$user_id = wp_create_user( 'cvt_gestora_4b', 'Synthetic-Only-4B!', 'gestora-4b@example.invalid' );
	}
	cvt_4b_assert( ! is_wp_error( $user_id ), 'No se pudo crear la gestora 4B.' );
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'cvd_gestora' );
	update_user_meta( $user->ID, '_cvd_account_status', 'approved' );
	update_user_meta( $user->ID, '_cvd_program_type', 'gestora' );
	update_user_meta( $user->ID, '_cvd_whatsapp', '5355512345' );
	delete_user_meta( $user->ID, '_cvd_phone' );
	return $user;
}

function cvt_4b_product( string $sku, float $price ): WC_Product_Simple {
	$product_id = wc_get_product_id_by_sku( $sku );
	$product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
	cvt_4b_assert( $product instanceof WC_Product_Simple, "Producto inválido {$sku}." );
	$product->set_name( 'Synthetic ' . $sku );
	$product->set_sku( $sku );
	$product->set_regular_price( (string) $price );
	$product->set_price( (string) $price );
	$product->set_status( 'publish' );
	$product->save();
	$product->delete_meta_data( '_cvd_commission_type' );
	$product->delete_meta_data( '_cvd_commission_value' );
	$product->save_meta_data();
	return $product;
}

function cvt_4b_order( WP_User $gestora, WC_Product $product, int $quantity, float $line_total, float $base_unit = 0, bool $mirror = false ): WC_Order {
	$order = wc_create_order();
	cvt_4b_assert( $order instanceof WC_Order, 'No se pudo crear pedido 4B.' );
	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( $quantity );
	$item->set_subtotal( $line_total );
	$item->set_total( $line_total );
	if ( $base_unit > 0 ) {
		$item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $base_unit, 4 ), true );
		$item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $line_total / max( 1, $quantity ), 4 ), true );
		$item->add_meta_data( '_cvd_pricing_gestora_user_id', $gestora->ID, true );
	}
	$order->add_item( $item );
	$order->update_meta_data( '_cvd_owner_user_id', $gestora->ID );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	if ( $mirror ) {
		$order->update_meta_data( '_cvd_pricing_gestora_user_id', $gestora->ID );
	}
	$order->set_currency( 'USD' );
	$order->save();
	return wc_get_order( $order->get_id() );
}

$gestora = cvt_4b_gestora();
update_option( 'cvd_default_commission_rate', 13 );
delete_user_meta( $gestora->ID, '_cvd_commission_rate' );

// 1. Política base Casa Viva: 13%.
$product_default = cvt_4b_product( 'CVT-4B-DEFAULT', 100 );
$order_default = cvt_4b_order( $gestora, $product_default, 1, 100 );
$calc_default = CVD_Commissions::calculate( $order_default, $gestora->ID );
cvt_4b_money( $calc_default['commission_amount'], 13, 'La comisión base no es 13%.' );
cvt_4b_assert( 'gestora' === $calc_default['breakdown'][0]['policy_source'], 'La fuente de política base no quedó trazable.' );

// 2. Excepción individual de gestora: 15%.
update_user_meta( $gestora->ID, '_cvd_commission_rate', 15 );
$product_override = cvt_4b_product( 'CVT-4B-OVERRIDE', 100 );
$order_override = cvt_4b_order( $gestora, $product_override, 1, 100 );
$calc_override = CVD_Commissions::calculate( $order_override, $gestora->ID );
cvt_4b_money( $calc_override['commission_amount'], 15, 'La excepción individual del 15% no se aplicó.' );

// 3. Regla fija por producto tiene prioridad sobre el porcentaje de la gestora.
$product_fixed = cvt_4b_product( 'CVT-4B-FIXED', 95 );
$product_fixed->update_meta_data( '_cvd_commission_type', 'fixed' );
$product_fixed->update_meta_data( '_cvd_commission_value', 10 );
$product_fixed->save_meta_data();
$order_fixed = cvt_4b_order( $gestora, $product_fixed, 2, 190 );
$calc_fixed = CVD_Commissions::calculate( $order_fixed, $gestora->ID );
cvt_4b_money( $calc_fixed['commission_amount'], 20, 'La comisión fija de 10 USD por unidad no se aplicó.' );
cvt_4b_assert( 'product' === $calc_fixed['breakdown'][0]['policy_source'], 'La regla fija no quedó identificada como producto.' );

// 4. Tienda espejo: comisión base sobre precio Casa Viva + margen de reventa.
update_user_meta( $gestora->ID, '_cvd_commission_rate', 13 );
$product_mirror = cvt_4b_product( 'CVT-4B-MIRROR', 100 );
$order_mirror = cvt_4b_order( $gestora, $product_mirror, 1, 120, 100, true );
$calc_mirror = CVD_Commissions::calculate( $order_mirror, $gestora->ID );
cvt_4b_money( $calc_mirror['commission_amount'], 13, 'La comisión de tienda espejo no usa la base Casa Viva.' );
cvt_4b_money( $calc_mirror['margin_amount'], 20, 'El margen propio de tienda espejo no se separó.' );
cvt_4b_money( $calc_mirror['amount'], 33, 'El total de ganancia de gestora es incorrecto.' );

// 5. El snapshot histórico no cambia aunque cambien después tasa, producto o total de línea.
$first_snapshot = $calc_mirror['breakdown'][0];
update_user_meta( $gestora->ID, '_cvd_commission_rate', 25 );
$product_mirror->update_meta_data( '_cvd_commission_type', 'fixed' );
$product_mirror->update_meta_data( '_cvd_commission_value', 50 );
$product_mirror->save_meta_data();
$items = $order_mirror->get_items( 'line_item' );
$item = reset( $items );
$item->set_total( 150 );
$item->save();
$order_mirror->save();
$order_mirror = wc_get_order( $order_mirror->get_id() );
$calc_frozen = CVD_Commissions::calculate( $order_mirror, $gestora->ID );
cvt_4b_money( $calc_frozen['commission_amount'], 13, 'Cambiar la política alteró una comisión histórica.' );
cvt_4b_money( $calc_frozen['margin_amount'], 20, 'Cambiar el pedido alteró el margen histórico.' );
cvt_4b_money( $calc_frozen['base_amount'], 100, 'Cambiar el pedido alteró la base histórica.' );
cvt_4b_money( $calc_frozen['sale_amount'], 120, 'Cambiar el pedido alteró el precio de venta histórico.' );
cvt_4b_assert( $first_snapshot['captured_at'] === $calc_frozen['breakdown'][0]['captured_at'], 'El snapshot fue reemplazado en vez de reutilizado.' );

// 6. Detección de auto-compra usa el WhatsApp realmente guardado por el registro de gestoras.
$product_risk = cvt_4b_product( 'CVT-4B-RISK', 50 );
$order_risk = cvt_4b_order( $gestora, $product_risk, 1, 50 );
$order_risk->set_billing_email( 'cliente-distinto@example.invalid' );
$order_risk->set_billing_phone( '5355512345' );
$order_risk->save();
CVD_Commissions::mark_pending_from_order( $order_risk );
$order_risk = wc_get_order( $order_risk->get_id() );
cvt_4b_assert( 'self_order' === $order_risk->get_meta( '_cvd_commission_risk', true ), 'El WhatsApp de la gestora no activa la revisión de auto-compra.' );

// 7. Una venta normal no queda marcada por un riesgo viejo.
$order_risk->set_billing_phone( '5355599999' );
$order_risk->save();
CVD_Commissions::mark_pending_from_order( $order_risk );
$order_risk = wc_get_order( $order_risk->get_id() );
cvt_4b_assert( '' === (string) $order_risk->get_meta( '_cvd_commission_risk', true ), 'El riesgo de auto-compra quedó pegado después de corregir la identidad.' );

// 8. Una comisión aprobada no puede saltar a pagada fuera de una liquidación pagada.
$product_payout = cvt_4b_product( 'CVT-4G-PAYOUT-GUARD', 100 );
$order_payout = cvt_4b_order( $gestora, $product_payout, 1, 100 );
CVD_Commissions::mark_approved( $order_payout->get_id() );
$order_payout = wc_get_order( $order_payout->get_id() );
cvt_4b_assert( 'approved' === $order_payout->get_meta( '_cvd_commission_status', true ), 'No se pudo preparar la comisión aprobada para 4G.' );

CVD_Commissions::mark_paid( $order_payout->get_id() );
$order_payout = wc_get_order( $order_payout->get_id() );
cvt_4b_assert( 'approved' === $order_payout->get_meta( '_cvd_commission_status', true ), 'mark_paid permitió pagar una comisión sin payout pagado.' );

$admin_id = username_exists( 'cvt_admin' );
cvt_4b_assert( (bool) $admin_id, 'No existe el administrador sintético para validar el intento manual.' );
$previous_user_id = get_current_user_id();
wp_set_current_user( (int) $admin_id );
$_POST['cvd_commission_status_nonce'] = wp_create_nonce( 'cvd_save_commission_status_' . $order_payout->get_id() );
$_POST['cvd_commission_status'] = 'paid';
CVD_Commissions::save_admin_status( $order_payout->get_id() );
unset( $_POST['cvd_commission_status_nonce'], $_POST['cvd_commission_status'] );
wp_set_current_user( $previous_user_id );
$order_payout = wc_get_order( $order_payout->get_id() );
cvt_4b_assert( 'approved' === $order_payout->get_meta( '_cvd_commission_status', true ), 'El formulario administrativo permitió saltarse la liquidación.' );

$order_payout->update_meta_data( '_cvd_payout_id', 999999 );
$order_payout->update_meta_data( '_cvd_payout_status', 'paid' );
$order_payout->save();
CVD_Commissions::mark_paid( $order_payout->get_id() );
$order_payout = wc_get_order( $order_payout->get_id() );
cvt_4b_assert( 'paid' === $order_payout->get_meta( '_cvd_commission_status', true ), 'Una liquidación pagada válida no pudo cerrar la comisión.' );

echo "OK 4B: porcentajes, excepciones, fijo, margen, snapshot y auto-compra validados.\n";
echo "OK 4G: una comisión solo pasa a pagada mediante una liquidación pagada.\n";
