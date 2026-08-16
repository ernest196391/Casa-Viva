<?php

defined( 'ABSPATH' ) || exit;

function cvt_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 4A: {$message}\n" );
		exit( 1 );
	}
}

function cvt_clear_referral(): void {
	unset( $_GET['ref'], $_GET['cv_ref'], $_COOKIE['cvd_referral'] );
	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( 'cvd_referral', '' );
	}
}

function cvt_program_user( string $login, string $email, string $role, string $code ): WP_User {
	$user_id = username_exists( $login );
	if ( ! $user_id ) {
		$user_id = wp_create_user( $login, 'Synthetic-Only-4A!', $email );
	}
	cvt_assert( ! is_wp_error( $user_id ), "No se pudo crear {$login}." );
	$user = new WP_User( (int) $user_id );
	$user->set_role( $role );
	wp_update_user( array( 'ID' => $user->ID, 'display_name' => strtoupper( $login ) ) );
	update_user_meta( $user->ID, '_cvd_account_status', 'approved' );
	update_user_meta( $user->ID, '_cvd_program_type', 'gestora' );
	update_user_meta( $user->ID, '_cvd_referral_code', $code );
	return $user;
}

function cvt_customer( string $login, string $email ): WP_User {
	$user_id = username_exists( $login );
	if ( ! $user_id ) {
		$user_id = wp_create_user( $login, 'Synthetic-Only-4A!', $email );
	}
	cvt_assert( ! is_wp_error( $user_id ), "No se pudo crear cliente {$login}." );
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'customer' );
	return $user;
}

function cvt_order( int $customer_id, string $phone, string $email, string $name ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	cvt_assert( $order instanceof WC_Order, 'No se pudo crear pedido sintético.' );
	$order->set_billing_first_name( $name );
	$order->set_billing_last_name( 'Synthetic' );
	$order->set_billing_phone( $phone );
	$order->set_billing_email( $email );
	return $order;
}

function cvt_attach( WC_Order $order ): WC_Order {
	CVD_Attribution::attach_to_order(
		$order,
		array(
			'billing_phone' => $order->get_billing_phone(),
			'billing_email' => $order->get_billing_email(),
		)
	);
	$order->save();
	return wc_get_order( $order->get_id() );
}

$gestora_a = cvt_program_user( 'cvt_gestora_a', 'gestora-a@example.invalid', 'cvd_gestora', 'CVA4A' );
$gestora_b = cvt_program_user( 'cvt_gestora_b', 'gestora-b@example.invalid', 'cvd_gestora', 'CVB4A' );
$customer = cvt_customer( 'cvt_customer_4a', 'cliente-4a@example.invalid' );

// 1. Primer contacto: el enlace A fija la propietaria del cliente y del pedido.
cvt_clear_referral();
$_GET['ref'] = 'CVA4A';
CVD_Attribution::capture_referral();
$order_one = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_one->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'El primer referido no quedó atribuido a A.' );
cvt_assert( 'referral_link' === $order_one->get_meta( '_cvd_attribution_source', true ), 'La fuente inicial no quedó registrada como referral_link.' );
cvt_assert( 'yes' === $order_one->get_meta( '_cvd_attribution_locked', true ), 'La atribución inicial no quedó bloqueada.' );

// 2. Un enlace posterior B no roba el navegador ni el cliente ya vinculado a A.
$_GET['ref'] = 'CVB4A';
CVD_Attribution::capture_referral();
$current = CVD_Attribution::current_referral_owner();
cvt_assert( $current && (int) $current['owner_user_id'] === $gestora_a->ID, 'Un segundo enlace sustituyó el first touch guardado.' );
$order_two = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_two->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'Un segundo enlace reasignó un cliente permanente.' );
cvt_assert( 'linked_customer' === $order_two->get_meta( '_cvd_attribution_source', true ), 'El cliente recurrente no se resolvió desde su vínculo permanente.' );

// 3. Navegación orgánica posterior mantiene la gestora original.
cvt_clear_referral();
$order_three = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_three->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'La navegación orgánica hizo perder la gestora a un cliente vinculado.' );

// 4. Una gestora puede comprar por un cliente ya vinculado sin que su propia cuenta WordPress sea tratada como identidad del cliente.
cvt_clear_referral();
$_GET['ref'] = 'CVB4A';
$order_delegated_existing = cvt_attach( cvt_order( $gestora_b->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_delegated_existing->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'La cuenta operadora B robó un cliente ya vinculado a A.' );
cvt_assert( '' === (string) $order_delegated_existing->get_meta( '_cvd_identity_customer', true ), 'La cuenta de una gestora se guardó como identidad del cliente delegado.' );

// 5. Una gestora puede comprar por un cliente nuevo usando su enlace; el teléfono/email del cliente quedan vinculados.
cvt_clear_referral();
$_GET['ref'] = 'CVA4A';
CVD_Attribution::capture_referral();
$order_delegated_new = cvt_attach( cvt_order( $gestora_a->ID, '5355510099', 'nuevo-delegado@example.invalid', 'Cliente Delegado' ) );
cvt_assert( (int) $order_delegated_new->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'La compra delegada de cliente nuevo no se atribuyó a la gestora.' );
cvt_assert( '' !== (string) $order_delegated_new->get_meta( '_cvd_identity_phone', true ), 'La compra delegada no guardó identidad de teléfono del cliente.' );

// 6. Ese cliente nuevo vuelve sin enlace y conserva a A.
cvt_clear_referral();
$order_delegated_return = cvt_attach( cvt_order( 0, '5355510099', 'nuevo-delegado@example.invalid', 'Cliente Delegado' ) );
cvt_assert( (int) $order_delegated_return->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'El cliente delegado recurrente perdió su gestora al volver orgánicamente.' );

// 7. Un cliente realmente orgánico, sin vínculo previo, permanece Casa Viva directo.
cvt_clear_referral();
$order_organic = cvt_attach( cvt_order( 0, '5355510777', 'organico-4a@example.invalid', 'Cliente Organico' ) );
cvt_assert( 0 === (int) $order_organic->get_meta( '_cvd_owner_user_id', true ), 'Un cliente orgánico recibió gestora artificial.' );
cvt_assert( 'organic' === $order_organic->get_meta( '_cvd_owner_type', true ), 'La venta directa no quedó marcada como orgánica.' );

echo "OK 4A: first-touch permanente, compra delegada y retorno orgánico validados.\n";
