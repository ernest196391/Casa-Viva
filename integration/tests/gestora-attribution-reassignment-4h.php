<?php

defined( 'ABSPATH' ) || exit;

function cvt_4h_assert( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "FAIL 4H: {$message}\n" ); exit( 1 ); }
}

function cvt_4h_owner( string $login, string $code ): WP_User {
	$id = username_exists( $login );
	if ( ! $id ) { $id = wp_create_user( $login, 'Synthetic-4H-Only!', $login . '@example.invalid' ); }
	cvt_4h_assert( ! is_wp_error( $id ), 'No se pudo crear propietaria.' );
	$user = new WP_User( (int) $id );
	$user->set_role( 'cvd_gestora' );
	update_user_meta( $user->ID, '_cvd_account_status', 'approved' );
	update_user_meta( $user->ID, '_cvd_program_type', 'gestora' );
	update_user_meta( $user->ID, '_cvd_referral_code', $code );
	return $user;
}

CVD_Attribution_Overrides::maybe_upgrade();
$a = cvt_4h_owner( 'cvt_gestora_4h_a', 'CV4HA' );
$b = cvt_4h_owner( 'cvt_gestora_4h_b', 'CV4HB' );
$phone = '5355594444';
$email = 'cliente-4h@example.invalid';

$old = wc_create_order();
$old->set_billing_phone( $phone );
$old->set_billing_email( $email );
$old->update_meta_data( '_cvd_identity_phone', hash( 'sha256', $phone ) );
$old->update_meta_data( '_cvd_identity_email', hash( 'sha256', $email ) );
$old->update_meta_data( '_cvd_owner_user_id', $a->ID );
$old->update_meta_data( '_cvd_owner_type', 'gestora' );
$old->update_meta_data( '_cvd_attribution_locked', 'yes' );
$old->update_meta_data( '_cvd_commission_status', 'approved' );
$old->update_meta_data( '_cvd_commission_amount', '13.0000' );
$old->save();

$admin_id = username_exists( 'cvt_admin' );
cvt_4h_assert( (bool) $admin_id, 'Falta admin sintético.' );
wp_set_current_user( (int) $admin_id );

$invalid = CVD_Attribution_Overrides::reassign_from_order( $old->get_id(), $b->ID, '' );
cvt_4h_assert( is_wp_error( $invalid ), 'Aceptó reasignación sin motivo.' );
$event = CVD_Attribution_Overrides::reassign_from_order( $old->get_id(), $b->ID, 'Transferencia administrativa validada' );
cvt_4h_assert( is_string( $event ) && $event, 'No creó evento.' );

global $wpdb;
$table = $wpdb->prefix . 'cvd_attribution_overrides';
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_uuid=%s", $event ), ARRAY_A );
cvt_4h_assert( 2 === count( $rows ), 'No registró teléfono y correo.' );
foreach ( $rows as $row ) {
	cvt_4h_assert( $a->ID === (int) $row['from_owner_user_id'], 'Perdió owner anterior.' );
	cvt_4h_assert( $b->ID === (int) $row['to_owner_user_id'], 'Perdió owner nuevo.' );
	cvt_4h_assert( (int) $admin_id === (int) $row['actor_user_id'], 'Perdió actor.' );
	cvt_4h_assert( 'Transferencia administrativa validada' === $row['reason'], 'Perdió motivo.' );
}

$new = wc_create_order();
$new->set_billing_phone( $phone );
$new->set_billing_email( $email );
CVD_Attribution_Overrides::apply_checkout_override( $new, array( 'billing_phone' => $phone, 'billing_email' => $email ) );
cvt_4h_assert( $b->ID === absint( $new->get_meta( '_cvd_owner_user_id', true ) ), 'Pedido futuro no usa override.' );
cvt_4h_assert( 'admin_reassignment' === $new->get_meta( '_cvd_attribution_source', true ), 'Fuente incorrecta.' );
$new->save();

$old = wc_get_order( $old->get_id() );
cvt_4h_assert( $a->ID === absint( $old->get_meta( '_cvd_owner_user_id', true ) ), 'Reescribió venta histórica.' );
cvt_4h_assert( 'approved' === $old->get_meta( '_cvd_commission_status', true ), 'Alteró comisión histórica.' );
cvt_4h_assert( 13.0 === (float) $old->get_meta( '_cvd_commission_amount', true ), 'Alteró importe histórico.' );

echo "OK 4H: reasignación futura auditable sin reescribir ventas históricas.\n";
