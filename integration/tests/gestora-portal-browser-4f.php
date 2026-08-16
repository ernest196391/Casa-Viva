<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function cvt_4f_user( string $login, string $email, string $password, string $name ): WP_User {
	$user_id = username_exists( $login );
	if ( ! $user_id ) {
		$user_id = wp_create_user( $login, $password, $email );
	}
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( 'No se pudo crear usuario 4F.' ); }
	wp_set_password( $password, (int) $user_id );
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'cvd_gestora' );
	$user->display_name = $name;
	wp_update_user( array( 'ID' => $user->ID, 'display_name' => $name ) );
	update_user_meta( $user->ID, '_cvd_account_status', 'approved' );
	update_user_meta( $user->ID, '_cvd_program_type', 'gestora' );
	update_user_meta( $user->ID, '_cvd_referral_code', 'CV4F' . $user->ID );
	return get_userdata( $user->ID );
}

function cvt_4f_order( WP_User $gestora, string $first_name, string $last_name, string $email, string $currency, float $commission ): WC_Order {
	$product_id = wc_get_product_id_by_sku( 'CVT-SYNTHETIC-1' );
	if ( ! $product_id ) { throw new RuntimeException( 'Producto sintético ausente para 4F.' ); }
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_currency( $currency );
	$order->set_billing_first_name( $first_name );
	$order->set_billing_last_name( $last_name );
	$order->set_billing_email( $email );
	$order->set_billing_phone( '5000' . $gestora->ID );
	$order->update_meta_data( '_cvd_owner_user_id', $gestora->ID );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	$order->update_meta_data( '_cvd_commission_status', 'approved' );
	$order->update_meta_data( '_cvd_base_commission_amount', $commission );
	$order->update_meta_data( '_cvd_margin_amount', 0 );
	$order->update_meta_data( '_cvd_commission_amount', $commission );
	$order->update_meta_data( '_cvd_commission_currency', $currency );
	$order->update_meta_data( '_cvd_commission_breakdown', array( array(
		'type' => 'percent',
		'value' => 13,
		'policy_source' => 'gestora',
		'base_commission' => $commission,
		'base_amount' => 100,
		'sale_amount' => 100,
	) ) );
	$order->update_meta_data( '_cvd_identity_email', hash( 'sha256', strtolower( $email ) ) );
	$order->update_meta_data( '_cvd_internal_fixture_secret', 'DO-NOT-RENDER-4F-' . $gestora->ID );
	$order->calculate_totals();
	$order->save();
	return $order;
}

$gestora_a = cvt_4f_user( 'cvt_gestora_4f_a', 'gestora-4f-a@example.invalid', 'Synthetic-Gestora-4F-A!', 'Gestora Cuatro F A' );
$gestora_b = cvt_4f_user( 'cvt_gestora_4f_b', 'gestora-4f-b@example.invalid', 'Synthetic-Gestora-4F-B!', 'Gestora Cuatro F B' );

$order_a = cvt_4f_order( $gestora_a, 'ClienteA4F', 'PrivadoA4F', 'cliente-a-4f@example.invalid', 'USD', 13 );
$order_b = cvt_4f_order( $gestora_b, 'ClienteB4F', 'PrivadoB4F', 'cliente-b-4f@example.invalid', 'CUP', 500 );

$page = get_page_by_path( 'area-gestoras' );
if ( ! $page instanceof WP_Post ) { throw new RuntimeException( 'Página area-gestoras ausente.' ); }

echo wp_json_encode( array(
	'portal_relative' => wp_make_link_relative( get_permalink( $page ) ),
	'a_user' => 'cvt_gestora_4f_a',
	'a_password' => 'Synthetic-Gestora-4F-A!',
	'a_client' => 'ClienteA4F PrivadoA4F',
	'a_order_id' => $order_a->get_id(),
	'b_user' => 'cvt_gestora_4f_b',
	'b_password' => 'Synthetic-Gestora-4F-B!',
	'b_client' => 'ClienteB4F PrivadoB4F',
	'b_order_id' => $order_b->get_id(),
) ) . PHP_EOL;
