<?php

defined( 'ABSPATH' ) || exit;

function cvt_4e_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 4E: {$message}\n" );
		exit( 1 );
	}
}

function cvt_4e_user( string $login ): WP_User {
	$user_id = username_exists( $login );
	if ( ! $user_id ) {
		$user_id = wp_create_user( $login, 'Synthetic-Only-4E!', $login . '@example.invalid' );
	}
	cvt_4e_assert( ! is_wp_error( $user_id ), 'No se pudo crear usuario sintético.' );
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'cvd_gestora' );
	update_user_meta( $user->ID, '_cvd_account_status', 'approved' );
	update_user_meta( $user->ID, '_cvd_program_type', 'gestora' );
	return $user;
}

function cvt_4e_order_with_margin_line( WP_User $owner, int $pricing_gestora_id ): WC_Order {
	$product = new WC_Product_Simple();
	$product->set_name( 'Producto sintético 4E ' . wp_generate_uuid4() );
	$product->set_regular_price( '100' );
	$product->set_price( '100' );
	$product->save();

	$order = wc_create_order();
	$order->set_currency( 'USD' );
	$order->update_meta_data( '_cvd_owner_user_id', $owner->ID );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	if ( $pricing_gestora_id ) {
		$order->update_meta_data( '_cvd_pricing_gestora_user_id', $pricing_gestora_id );
	}

	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( 1 );
	$item->set_subtotal( 120 );
	$item->set_total( 120 );
	$item->add_meta_data( '_cvd_base_unit_price', '100.0000', true );
	$item->add_meta_data( '_cvd_sale_unit_price', '120.0000', true );
	$item->add_meta_data( '_cvd_margin_unit', '20.0000', true );
	$item->add_meta_data( '_cvd_pricing_gestora_user_id', $pricing_gestora_id, true );
	$order->add_item( $item );
	$order->save();
	return $order;
}

$gestora_a = cvt_4e_user( 'cvt_gestora_4e_a' );
$gestora_b = cvt_4e_user( 'cvt_gestora_4e_b' );

$ids = CVD_Gestora_Price_Integrity::pricing_gestora_ids(
	array(
		array( '_cvd_gestora_id' => $gestora_b->ID ),
		array( '_cvd_gestora_id' => 0 ),
		array( '_cvd_gestora_id' => $gestora_a->ID ),
		array( '_cvd_gestora_id' => $gestora_b->ID ),
	)
);
cvt_4e_assert( $ids === array( min( $gestora_a->ID, $gestora_b->ID ), max( $gestora_a->ID, $gestora_b->ID ) ), 'No se detectaron de forma determinista las gestoras del carrito.' );

$mixed = wc_create_order();
CVD_Gestora_Price_Integrity::apply_cart_snapshot(
	$mixed,
	array(
		array( '_cvd_gestora_id' => $gestora_a->ID ),
		array( '_cvd_gestora_id' => $gestora_b->ID ),
	)
);
cvt_4e_assert( 0 === absint( $mixed->get_meta( '_cvd_pricing_gestora_user_id', true ) ), 'Un carrito mixto conservó una gestora de precio arbitraria.' );
cvt_4e_assert( 'mixed_gestoras' === $mixed->get_meta( '_cvd_pricing_conflict', true ), 'El carrito mixto no quedó marcado para revisión.' );

$unique = wc_create_order();
CVD_Gestora_Price_Integrity::apply_cart_snapshot(
	$unique,
	array(
		array( '_cvd_gestora_id' => $gestora_a->ID ),
		array( '_cvd_gestora_id' => $gestora_a->ID ),
		array(),
	)
);
cvt_4e_assert( $gestora_a->ID === absint( $unique->get_meta( '_cvd_pricing_gestora_user_id', true ) ), 'Un carrito coherente perdió su gestora de precio.' );
cvt_4e_assert( '' === (string) $unique->get_meta( '_cvd_pricing_conflict', true ), 'Un carrito coherente quedó marcado como conflicto.' );

$valid_order = cvt_4e_order_with_margin_line( $gestora_a, $gestora_a->ID );
$valid = CVD_Commissions::calculate( $valid_order, $gestora_a->ID );
cvt_4e_assert( 20.0 === (float) $valid['margin_amount'], 'El margen válido de la propietaria no se conservó.' );
cvt_4e_assert( 13.0 === (float) $valid['commission_amount'], 'La comisión base válida cambió inesperadamente.' );

$mismatch_order = cvt_4e_order_with_margin_line( $gestora_a, $gestora_b->ID );
CVD_Gestora_Price_Integrity::enforce_owner_alignment( $mismatch_order, array() );
cvt_4e_assert( 0 === absint( $mismatch_order->get_meta( '_cvd_pricing_gestora_user_id', true ) ), 'El conflicto propietaria/precio no cerró el margen automático.' );
cvt_4e_assert( 'owner_mismatch' === $mismatch_order->get_meta( '_cvd_pricing_conflict', true ), 'No se registró el conflicto propietaria/precio.' );
cvt_4e_assert( $gestora_b->ID === absint( $mismatch_order->get_meta( '_cvd_pricing_conflict_gestora_user_id', true ) ), 'No se preservó la gestora de precio conflictiva para auditoría interna.' );

$mismatch = CVD_Commissions::calculate( $mismatch_order, $gestora_a->ID );
cvt_4e_assert( 0.0 === (float) $mismatch['margin_amount'], 'Se acreditó margen de otra gestora a la propietaria permanente.' );
cvt_4e_assert( 13.0 === (float) $mismatch['commission_amount'], 'La propietaria permanente perdió su comisión base por un conflicto de precio.' );

echo "OK 4E: carrito mixto y coherencia propietaria/precio fallan cerrado sin perder comisión base.\n";
