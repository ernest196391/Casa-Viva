<?php

defined( 'ABSPATH' ) || exit;

function cvt_4c_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL 4C: {$message}\n" ); exit( 1 ); }
}

$admin_id = username_exists( 'cvt_admin' );
cvt_4c_assert( (bool) $admin_id, 'No existe el administrador sintético.' );
wp_set_current_user( (int) $admin_id );

$gestora_id = username_exists( 'cvt_gestora_4c' );
if ( ! $gestora_id ) { $gestora_id = wp_create_user( 'cvt_gestora_4c', 'Synthetic-Only-4C!', 'gestora-4c@example.invalid' ); }
cvt_4c_assert( ! is_wp_error( $gestora_id ), 'No se pudo crear la gestora 4C.' );
$gestora = new WP_User( (int) $gestora_id );
$gestora->set_role( 'cvd_gestora' );
update_user_meta( $gestora->ID, '_cvd_account_status', 'approved' );
update_user_meta( $gestora->ID, '_cvd_program_type', 'gestora' );
update_user_meta( $gestora->ID, '_cvd_payout_method', 'transferencia' );

$key = hash( 'sha256', wp_salt( 'auth' ), true );
$iv = random_bytes( 12 );
$tag = '';
$cipher = openssl_encrypt( 'synthetic-account-4c', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
cvt_4c_assert( false !== $cipher, 'No se pudo cifrar el destino sintético.' );
update_user_meta( $gestora->ID, '_cvd_payout_account', base64_encode( $iv . $tag . $cipher ) );

$order = wc_create_order();
cvt_4c_assert( $order instanceof WC_Order, 'No se pudo crear el pedido 4C.' );
$order->set_currency( 'USD' );
$order->update_meta_data( '_cvd_owner_user_id', $gestora->ID );
$order->update_meta_data( '_cvd_owner_type', 'gestora' );
$order->update_meta_data( '_cvd_commission_status', 'approved' );
$order->update_meta_data( '_cvd_commission_amount', '25.0000' );
$order->update_meta_data( '_cvd_base_commission_amount', '20.0000' );
$order->update_meta_data( '_cvd_margin_amount', '5.0000' );
$order->save();

update_option( 'cvt_payout_4c_fixture', array(
	'admin_id' => (int) $admin_id,
	'gestora_id' => $gestora->ID,
	'order_id' => $order->get_id(),
) );

echo wp_json_encode( get_option( 'cvt_payout_4c_fixture' ) ) . PHP_EOL;
