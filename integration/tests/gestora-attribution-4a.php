<?php

defined( 'ABSPATH' ) || exit;

function cvt_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 4A: {$message}\n" );
		exit( 1 );
	}
}

function cvt_clear_referral(): void {
	wp_set_current_user( 0 );
	unset( $_GET['ref'], $_GET['cv_ref'], $_COOKIE['cvd_referral'] );

	if ( function_exists( 'WC' ) ) {
		if ( WC()->session ) {
			if ( is_callable( array( WC()->session, '__unset' ) ) ) {
				WC()->session->__unset( 'cvd_referral' );
			} else {
				WC()->session->set( 'cvd_referral', null );
			}
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
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
	clean_user_cache( $user->ID );
	return get_userdata( $user->ID );
}

function cvt_customer( string $login, string $email ): WP_User {
	$user_id = username_exists( $login );
	if ( ! $user_id ) {
		$user_id = wp_create_user( $login, 'Synthetic-Only-4A!', $email );
	}
	cvt_assert( ! is_wp_error( $user_id ), "No se pudo crear cliente {$login}." );
	$user = new WP_User( (int) $user_id );
	$user->set_role( 'customer' );
	clean_user_cache( $user->ID );
	return get_userdata( $user->ID );
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
	// Como el checkout real (CVD_WhatsApp_Gateway::process_payment): el pedido queda «En espera» al instante. La
	// gestora del cliente se busca en pedidos confirmados (processing/on-hold/completed), no en carritos sin pagar.
	$order->set_status( 'on-hold' );
	$order->save();
	return wc_get_order( $order->get_id() );
}

function cvt_owner_debug( ?array $owner ): string {
	return $owner ? wp_json_encode( $owner ) : 'null';
}

$gestora_a = cvt_program_user( 'cvt_gestora_a', 'gestora-a@example.invalid', 'cvd_gestora', 'CVA4A' );
$gestora_b = cvt_program_user( 'cvt_gestora_b', 'gestora-b@example.invalid', 'cvd_gestora', 'CVB4A' );
$customer = cvt_customer( 'cvt_customer_4a', 'cliente-4a@example.invalid' );

// 1. Primer contacto: el enlace A fija la propietaria del cliente y del pedido.
cvt_clear_referral();
$_GET['ref'] = 'CVA4A';
$request_owner = CVD_Attribution::current_referral_owner();
cvt_assert(
	$request_owner && (int) $request_owner['owner_user_id'] === $gestora_a->ID,
	'El código A no resolvió a la gestora A antes del checkout. owner=' . cvt_owner_debug( $request_owner ) . ' expected=' . $gestora_a->ID
);
CVD_Attribution::capture_referral();
$saved_owner = CVD_Attribution::current_referral_owner();
cvt_assert(
	$saved_owner && (int) $saved_owner['owner_user_id'] === $gestora_a->ID,
	'El primer referido no persistió en navegador/sesión. owner=' . cvt_owner_debug( $saved_owner ) . ' expected=' . $gestora_a->ID
);
$order_one = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
$first_owner_id = (int) $order_one->get_meta( '_cvd_owner_user_id', true );
cvt_assert(
	$first_owner_id === $gestora_a->ID,
	'El primer referido no quedó atribuido a A. actual=' . $first_owner_id . ' expected=' . $gestora_a->ID . ' source=' . (string) $order_one->get_meta( '_cvd_attribution_source', true )
);
cvt_assert( 'referral_link' === $order_one->get_meta( '_cvd_attribution_source', true ), 'La fuente inicial no quedó registrada como referral_link.' );
cvt_assert( 'yes' === $order_one->get_meta( '_cvd_attribution_locked', true ), 'La atribución inicial no quedó bloqueada.' );

// 2. 3.14.70 (27-oct, Ernesto: #5582 Gretel): un enlace posterior B se lleva ESA venta (quien trae al cliente con su
//    enlace cobra), con el aviso «ya compró con otra gestora». El cliente fijo sigue siendo A (paso 3).
$_GET['ref'] = 'CVB4A';
CVD_Attribution::capture_referral();
$current = CVD_Attribution::current_referral_owner();
cvt_assert( $current && (int) $current['owner_user_id'] === $gestora_b->ID, 'El último enlace del navegador debe ser el de B (3.13.79). owner=' . cvt_owner_debug( $current ) );
$order_two = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_two->get_meta( '_cvd_owner_user_id', true ) === $gestora_b->ID, 'La venta con el enlace de B no fue para B (3.14.70).' );
cvt_assert( 'referral_link' === $order_two->get_meta( '_cvd_attribution_source', true ), 'La venta con enlace no quedó como referral_link.' );
cvt_assert( 'yes' === $order_two->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ), 'Falta el aviso «ya compró con otra gestora».' );

// 3. Navegación orgánica posterior (sin enlace ni vale): el cliente fijo A, sin aviso.
cvt_clear_referral();
$order_fixed = cvt_attach( cvt_order( $customer->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_fixed->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'Sin enlace ni vale, el pedido no fue para el cliente fijo A.' );
cvt_assert( 'linked_customer' === $order_fixed->get_meta( '_cvd_attribution_source', true ), 'El cliente recurrente no se resolvió desde su vínculo permanente.' );
cvt_assert( '' === (string) $order_fixed->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ), 'Sin gestora en el pedido no hay aviso.' );

// 4. Una gestora con sesión compra por un cliente ya vinculado a A: 3.14.70, la venta es de quien la hace (B), con aviso;
//    su propia cuenta WordPress no se trata como identidad del cliente.
cvt_clear_referral();
wp_set_current_user( $gestora_b->ID );
$order_delegated_existing = cvt_attach( cvt_order( $gestora_b->ID, '5355510001', $customer->user_email, 'Cliente Uno' ) );
cvt_assert( (int) $order_delegated_existing->get_meta( '_cvd_owner_user_id', true ) === $gestora_b->ID, 'La gestora B con sesión no se quedó la venta que hizo (3.14.70).' );
cvt_assert( 'seller_session' === $order_delegated_existing->get_meta( '_cvd_attribution_source', true ), 'La venta de la gestora con sesión no quedó como seller_session.' );
cvt_assert( 'yes' === $order_delegated_existing->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ), 'Falta el aviso a B.' );
cvt_assert( '' === (string) $order_delegated_existing->get_meta( '_cvd_identity_customer', true ), 'La cuenta de una gestora se guardó como identidad del cliente delegado.' );
wp_set_current_user( 0 );

// 4b. Vale de una operadora con gestora (vale por chat / NEXO): la de ese vale, aunque el cliente sea de A.
cvt_clear_referral();
$order_vale = cvt_order( 0, '5355510001', $customer->user_email, 'Cliente Uno' );
CVD_Attribution::attach_operator_order( $order_vale, $gestora_b->ID );
cvt_assert( (int) $order_vale->get_meta( '_cvd_owner_user_id', true ) === $gestora_b->ID, 'El vale de B se ató a la gestora anterior del teléfono.' );
cvt_assert( 'operator_confirmed_voucher' === $order_vale->get_meta( '_cvd_attribution_source', true ), 'Fuente del vale incorrecta.' );
cvt_assert( 'yes' === $order_vale->get_meta( CVD_Attribution::OTHER_GESTORA_META, true ), 'Falta el aviso en el vale.' );
$order_vale->delete( true );

// 4c. Vale de una operadora SIN gestora: el cliente fijo A (como antes).
$order_vale_plain = cvt_order( 0, '5355510001', $customer->user_email, 'Cliente Uno' );
CVD_Attribution::attach_operator_order( $order_vale_plain, 0 );
cvt_assert( (int) $order_vale_plain->get_meta( '_cvd_owner_user_id', true ) === $gestora_a->ID, 'El vale sin gestora no fue al cliente fijo.' );
$order_vale_plain->delete( true );

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
