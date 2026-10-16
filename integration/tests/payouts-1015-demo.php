<?php
/**
 * Datos de ejemplo (sintéticos) para las capturas de «Pagos de comisiones sin chat» (Core 3.14.23).
 * wp eval-file integration-tests/payouts-1015-demo.php → imprime JSON con la gestora, el pago abierto y los enlaces.
 * Solo para el entorno local de pruebas.
 */

defined( 'ABSPATH' ) || exit;
global $wpdb;

CVD_Plugin::activate();
update_option( 'cvd_gestora_app', 'all' );
update_option( 'cvd_daily_rates', array( 'cup' => 795, 'updated_at' => time(), 'source' => 'test' ) );
$admin_id = (int) username_exists( 'cvt_admin' );
update_user_meta( $admin_id, '_cvd_whatsapp', '5356885368' );
wp_update_user( array( 'ID' => $admin_id, 'display_name' => 'Lennys Rondon' ) );

$login = 'cvt_demo_pagos_' . wp_rand( 1000, 9999 );
$gid = wp_create_user( $login, wp_generate_password( 24 ), $login . '@example.invalid' );
$g = new WP_User( (int) $gid );
$g->set_role( 'cvd_gestora' );
foreach ( array( '_cvd_account_status' => 'approved', '_cvd_program_type' => 'gestora', '_cvd_whatsapp' => '5351234567', '_cvd_referral_code' => 'PATRI' ) as $k => $v ) { update_user_meta( $g->ID, $k, $v ); }
wp_update_user( array( 'ID' => $g->ID, 'display_name' => 'Patricia Jiménez' ) );

$mk = static function ( float $usd, float $rate, string $date, array $products ) use ( $g ): int {
	$o = wc_create_order();
	foreach ( $products as $name => $qty ) { $p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_regular_price( '25' ); $p->save(); $o->add_product( $p, $qty ); }
	$o->set_currency( 'USD' ); $o->set_date_created( $date ); $o->set_status( 'completed' );
	$o->set_billing_first_name( 'Cliente' );
	$o->update_meta_data( '_cvd_owner_user_id', $g->ID ); $o->update_meta_data( '_cvd_owner_type', 'gestora' );
	$o->update_meta_data( '_cvd_commission_status', 'approved' ); $o->update_meta_data( '_cvd_commission_amount', (string) $usd );
	$o->update_meta_data( '_cvd_base_commission_amount', (string) $usd ); $o->update_meta_data( '_cvd_margin_amount', '0' );
	$o->update_meta_data( '_cvd_cup_rate', $rate ); $o->update_meta_data( '_cvd_cup_rate_at', $date );
	$o->save();
	return $o->get_id();
};

// Historial: una transferencia pagada con captura, un efectivo por recoger.
$mk( 8, 780, '2026-10-09T15:00:00+00:00', array( 'Olla arrocera 1,8 L' => 1 ) );
$mk( 4.5, 780, '2026-10-10T15:00:00+00:00', array( 'Juego de sábanas' => 1 ) );
wp_set_current_user( $g->ID );
$a = CVD_Payouts::request( $g->ID, array( 'method' => 'transfer', 'card' => '9205129976543210', 'phone' => '51234567', 'token' => wp_generate_uuid4() ) );
$png = wp_tempnam( 'cap' ) . '.png'; $im = imagecreatetruecolor( 360, 640 ); imagefill( $im, 0, 0, imagecolorallocate( $im, 230, 240, 235 ) ); imagepng( $im, $png ); imagedestroy( $im );
wp_set_current_user( $admin_id );
CVD_Payouts::pay_now( $a, 'Captura · Lennys (enlace)', (int) CVD_Payout_Pay_Page::store_proof_file( $png, $a ) );
$mk( 6, 785, '2026-10-12T15:00:00+00:00', array( 'Ventilador de pie' => 1 ) );
wp_set_current_user( $g->ID );
$b = CVD_Payouts::request( $g->ID, array( 'method' => 'cash_cup', 'token' => wp_generate_uuid4() ) );
wp_set_current_user( $admin_id );
CVD_Payouts::pay_now( $b, 'Efectivo listo en la tienda · Zaymi (enlace)' );

// Pago abierto (para la página «Pagar a…»): 3 pedidos con su tasa y una deuda de 2.150 CUP.
CVD_Payouts::add_debt( $g->ID, 2150, 'CUP', 'Mercancía que se llevó el 10-oct', $admin_id );
$mk( 14, 790, '2026-10-14T09:00:00+00:00', array( 'Ventilador de techo' => 1 ) );
$mk( 5.2, 790, '2026-10-14T16:00:00+00:00', array( 'Set de baño 3 piezas' => 1, 'Toalla gris' => 2 ) );
$mk( 1.3, 795, '2026-10-15T10:00:00+00:00', array( 'Jabonera de bambú' => 1 ) );
wp_set_current_user( $g->ID );
$c = CVD_Payouts::request( $g->ID, array( 'method' => 'transfer', 'card' => '9205129976543210', 'phone' => '51234567', 'token' => wp_generate_uuid4() ) );

// Lo disponible ahora (pantalla «Cobrar»), con otra deuda abierta.
CVD_Payouts::add_debt( $g->ID, 1200, 'CUP', 'Faltó en el cuadre del 14-oct', $admin_id );
$mk( 12, 790, '2026-10-14T18:00:00+00:00', array( 'Batidora de mano' => 1 ) );
$mk( 3, 795, '2026-10-15T09:30:00+00:00', array( 'Cortina de baño' => 1 ) );
$mk( 7, 795, '2026-10-15T12:10:00+00:00', array( 'Juego de vasos x6' => 1, 'Jarra de vidrio' => 1 ) );
$mk( 2.5, 795, '2026-10-15T17:40:00+00:00', array( 'Organizador de cocina' => 1 ) );

echo wp_json_encode( array(
	'gestora' => $g->ID,
	'logged_in' => array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $g->ID, time() + 4 * HOUR_IN_SECONDS, 'logged_in' ) ),
	'area' => home_url( '/area-gestoras/' ),
	'pay_url' => CVD_Payout_Pay_Page::signed_url( (int) $c, '5356885368' ),
	'paid_url' => CVD_Payout_Pay_Page::signed_url( (int) $a, '5356885368' ),
	'ids' => array( 'transfer_paid' => $a, 'cash_pickup' => $b, 'open' => $c ),
) ) . PHP_EOL;
