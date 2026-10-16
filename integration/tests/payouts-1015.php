<?php
/**
 * Pagos de comisiones sin chat (Core 3.14.23). wp eval-file integration-tests/payouts-1015.php
 * Pedido con la tasa de su venta, retiro parcial, deuda descontada, avisos una sola vez, «Ya pagué» idempotente,
 * captura privada, efectivo con «Recibido», enlace firmado y la puerta del bot.
 */

defined( 'ABSPATH' ) || exit;
global $wpdb;

function cvt_p( bool $ok, string $msg ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL pagos: {$msg}\n" ); exit( 1 ); } echo "  ok · {$msg}\n"; }

CVD_Plugin::activate(); // columnas y tabla nuevas (como maybe_upgrade al subir la versión)
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}cvd_payouts" );
cvt_p( in_array( 'pay_method', $cols, true ) && in_array( 'received_at', $cols, true ) && in_array( 'details', $cols, true ), 'migración: columnas nuevas en cvd_payouts' );
cvt_p( (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}cvd_payout_avisos'" ), 'migración: tabla cvd_payout_avisos' );

$admin_id = (int) username_exists( 'cvt_admin' );
update_user_meta( $admin_id, '_cvd_whatsapp', '5356885368' );
$clerk_id = username_exists( 'cvt_clerk_pagos' ) ?: wp_create_user( 'cvt_clerk_pagos', 'Synthetic-Only-P1!', 'clerk-pagos@example.invalid' );
( new WP_User( (int) $clerk_id ) )->set_role( 'cvd_clerk' );
update_user_meta( (int) $clerk_id, '_cvd_whatsapp', '5359163951' );
wp_update_user( array( 'ID' => (int) $clerk_id, 'display_name' => 'Zaymi Prueba' ) );

$gid = username_exists( 'cvt_gestora_pagos' ) ?: wp_create_user( 'cvt_gestora_pagos', 'Synthetic-Only-P2!', 'gestora-pagos@example.invalid' );
$g = new WP_User( (int) $gid );
$g->set_role( 'cvd_gestora' );
update_user_meta( $g->ID, '_cvd_account_status', 'approved' );
update_user_meta( $g->ID, '_cvd_program_type', 'gestora' );
update_user_meta( $g->ID, '_cvd_whatsapp', '5353784147' );
wp_update_user( array( 'ID' => $g->ID, 'display_name' => 'Yudi Prueba' ) );
update_option( 'cvd_daily_rates', array( 'cup' => 795, 'updated_at' => time(), 'source' => 'test' ) );

$mk = static function ( float $usd, float $rate, string $date, string $name ) use ( $g ): int {
	$o = wc_create_order();
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_regular_price( '20' ); $p->save();
	$o->add_product( $p, 1 );
	$o->set_currency( 'USD' ); $o->set_date_created( $date ); $o->set_status( 'completed' );
	$o->update_meta_data( '_cvd_owner_user_id', $g->ID ); $o->update_meta_data( '_cvd_owner_type', 'gestora' );
	$o->update_meta_data( '_cvd_commission_status', 'approved' ); $o->update_meta_data( '_cvd_commission_amount', (string) $usd );
	$o->update_meta_data( '_cvd_base_commission_amount', (string) $usd ); $o->update_meta_data( '_cvd_margin_amount', '0' );
	if ( $rate > 0 ) { $o->update_meta_data( '_cvd_cup_rate', $rate ); $o->update_meta_data( '_cvd_cup_rate_at', $date ); }
	$o->save();
	return $o->get_id();
};
$o1 = $mk( 14, 790, '2026-10-12T10:00:00+00:00', 'Ventilador de pie' );
$o2 = $mk( 5.2, 785, '2026-10-13T10:00:00+00:00', 'Set de baño' );
$o3 = $mk( 1.3, 795, '2026-10-15T10:00:00+00:00', 'Toalla gris' );
$o4 = $mk( 6, 795, '2026-10-15T11:00:00+00:00', 'Sábanas azul' );

$lines = CVD_Payouts::available_lines( $g->ID );
cvt_p( 4 === count( $lines ) && $lines[0]['id'] === $o1, 'disponibles: 4 pedidos, del más viejo al más nuevo' );
cvt_p( 11060.0 === $lines[0]['cup'] && 4082.0 === $lines[1]['cup'] && 1033.5 === $lines[2]['cup'], 'cada pedido con la tasa de SU venta' );

$debt = CVD_Payouts::add_debt( $g->ID, 2150, 'CUP', 'Mercancía del 10-oct', $admin_id );
cvt_p( is_int( $debt ), 'deuda de 2.150 CUP apuntada' );

// 1) Transferencia parcial: 20,50 USD de 26,50 (deja guardado el de 6 USD). Deuda descontada.
wp_set_current_user( $g->ID );
$token = wp_generate_uuid4();
$opts = array( 'method' => 'transfer', 'amount' => 20.5, 'card' => '9205 1299 7654 3210', 'phone' => '5378 4147', 'token' => $token );
$p1 = CVD_Payouts::request( $g->ID, $opts );
cvt_p( is_int( $p1 ) && $p1 > 0, 'solicitud por transferencia creada (#' . ( is_int( $p1 ) ? $p1 : 'error: ' . ( is_wp_error( $p1 ) ? $p1->get_error_message() : '' ) ) . ')' );
$row = CVD_Payouts::get( $p1 );
cvt_p( 'CUP' === $row['currency'] && abs( (float) $row['amount'] - 14025.5 ) < 0.001, 'paga 16.175,5 − 2.150 = 14.025,5 CUP' );
cvt_p( 'transfer' === $row['pay_method'] && abs( (float) $row['commission_usd'] - 20.5 ) < 0.001 && abs( (float) $row['deduction'] - 2150 ) < 0.001, 'guarda método, USD bruto y deuda' );
$items = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,cup_rate,cup_amount FROM {$wpdb->prefix}cvd_payout_items WHERE payout_id=%d ORDER BY id", $p1 ), ARRAY_A );
cvt_p( 3 === count( $items ) && 790.0 === (float) $items[0]['cup_rate'] && 1033.5 === (float) $items[2]['cup_amount'], 'líneas con su tasa y sus CUP' );
cvt_p( 'reserved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cvd_owner_financial_ledger WHERE id=%d", $debt ) ), 'deuda reservada en esta solicitud' );
$left = CVD_Payouts::available_lines( $g->ID );
cvt_p( 1 === count( $left ) && $left[0]['id'] === $o4, 'retiro parcial: el pedido de 6 USD sigue disponible' );
cvt_p( '9205129976543210' === CVD_Payouts::profile( $g )['card'], 'la tarjeta queda guardada (cifrada) para la próxima' );

// Doble toque: mismo token → misma solicitud, sin otra ni avisos repetidos.
$again = CVD_Payouts::request( $g->ID, $opts );
cvt_p( $again === $p1, 'doble toque devuelve la misma solicitud' );
$avisos = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payout_avisos WHERE payout_id=%d", $p1 ), ARRAY_A );
cvt_p( 1 === count( $avisos ) && 'admin' === $avisos[0]['role'] && '5356885368' === $avisos[0]['phone'], 'transferencia: un aviso, solo a Lennys' );
cvt_p( str_contains( $avisos[0]['body'], '9205 1299 7654 3210' ) && str_contains( $avisos[0]['body'], '53784147' ) && str_contains( $avisos[0]['body'], '14.025,5 CUP' ), 'el aviso trae tarjeta, teléfono y total' );
preg_match( '~\?cvd_pago=(\d+)&t=([0-9a-f-]+)~', $avisos[0]['body'], $m );
cvt_p( isset( $m[2] ) && (int) $m[1] === $p1, 'el aviso trae el enlace firmado' );
echo "\n--- AVISO A LENNYS (transferencia) ---\n" . $avisos[0]['body'] . "\n---\n";

// Enlace firmado: vale para Lennys; uno tocado no vale.
wp_set_current_user( 0 );
$_REQUEST['t'] = $m[2];
$acc = CVD_Payout_Pay_Page::access( $row );
cvt_p( $acc['ok'] && $admin_id === $acc['actor'], 'el enlace abre la página y firma como Lennys' );
$_REQUEST['t'] = substr( $m[2], 0, -1 ) . ( 'a' === substr( $m[2], -1 ) ? 'b' : 'a' );
cvt_p( ! CVD_Payout_Pay_Page::access( $row )['ok'], 'un enlace tocado no abre' );
$_REQUEST['t'] = CVD_Payout_Math::sign( $p1, '59163951', time() + 3600, wp_salt( 'auth' ) . '|cvd-pagos' );
cvt_p( ! CVD_Payout_Pay_Page::access( $row )['ok'], 'la dependienta no abre una transferencia (las paga Lennys)' );
unset( $_REQUEST['t'] );

// «Ya pagué» con captura privada, dos veces.
$png = wp_tempnam( 'cap' ) . '.png';
$im = imagecreatetruecolor( 40, 30 ); imagepng( $im, $png ); imagedestroy( $im );
$proof = CVD_Payout_Pay_Page::store_proof_file( $png, $p1 );
cvt_p( is_int( $proof ) && 'private' === get_post_status( $proof ), 'captura guardada como adjunto privado' );
$file = get_attached_file( $proof );
cvt_p( str_contains( $file, '/cvd-privado/pagos/' ) && file_exists( dirname( $file ) . '/.htaccess' ) && file_exists( dirname( $file, 2 ) . '/.htaccess' ), 'en carpeta cerrada con .htaccess' );
wp_set_current_user( $admin_id );
cvt_p( 'paid' === CVD_Payouts::pay_now( $p1, 'Captura · Lennys (enlace)', $proof ), '«Ya pagué»: aprueba y marca pagado' );
cvt_p( 'already' === CVD_Payouts::pay_now( $p1, 'Captura · Lennys (enlace)', $proof ), 'segundo toque: «ya estaba», no paga dos veces' );
$row = CVD_Payouts::get( $p1 );
cvt_p( 'paid' === $row['status'] && $proof === (int) $row['proof_attachment_id'] && $admin_id === (int) $row['paid_by'], 'pagado, con captura y quién pagó' );
cvt_p( 'settled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cvd_owner_financial_ledger WHERE id=%d", $debt ) ), 'la deuda queda saldada' );
cvt_p( 'paid' === wc_get_order( $o1 )->get_meta( '_cvd_commission_status', true ), 'la comisión del pedido queda pagada' );
$ev = $wpdb->get_col( $wpdb->prepare( "SELECT event_type FROM {$wpdb->prefix}cvd_payout_events WHERE payout_id=%d ORDER BY id", $p1 ) );
cvt_p( array( 'requested', 'approve', 'pay' ) === $ev, 'historial: pedido → aprobado → pagado (una vez cada uno)' );
$g_av = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payout_avisos WHERE payout_id=%d AND role='gestora'", $p1 ), ARRAY_A );
cvt_p( 1 === count( $g_av ) && str_contains( $g_av[0]['body'], 'Te pagaron 14.025,5 CUP' ) && str_contains( $g_av[0]['body'], '3210' ), 'aviso a la gestora, una vez' );
echo "\n--- AVISO A LA GESTORA (transferencia pagada) ---\n" . $g_av[0]['body'] . "\n---\n";

// 2) Efectivo CUP con el pedido que quedó: aviso a Lennys y a la dependienta; «Dinero listo»; «Recibido».
wp_set_current_user( $g->ID );
$p2 = CVD_Payouts::request( $g->ID, array( 'method' => 'cash_cup', 'token' => wp_generate_uuid4() ) );
cvt_p( is_int( $p2 ), 'solicitud en efectivo CUP' );
$row2 = CVD_Payouts::get( $p2 );
cvt_p( 'CUP' === $row2['currency'] && 4770.0 === (float) $row2['amount'], 'efectivo: 6 × 795 = 4.770 CUP' );
$av2 = $wpdb->get_results( $wpdb->prepare( "SELECT role,phone,body FROM {$wpdb->prefix}cvd_payout_avisos WHERE payout_id=%d ORDER BY id", $p2 ), ARRAY_A );
cvt_p( 2 === count( $av2 ) && 'admin' === $av2[0]['role'] && 'clerk' === $av2[1]['role'] && '5359163951' === $av2[1]['phone'], 'efectivo: avisa a Lennys y a la dependienta' );
cvt_p( str_contains( $av2[0]['body'], 'Ya le avisé a Zaymi' ), 'Lennys sabe a quién se avisó' );
echo "\n--- AVISO A LENNYS (efectivo) ---\n" . $av2[0]['body'] . "\n---\n--- AVISO A LA DEPENDIENTA (efectivo) ---\n" . $av2[1]['body'] . "\n---\n";
preg_match( '~&t=([0-9a-f-]+)~', $av2[1]['body'], $mc );
wp_set_current_user( 0 );
$_REQUEST['t'] = $mc[1];
$acc = CVD_Payout_Pay_Page::access( $row2 );
cvt_p( $acc['ok'] && (int) $clerk_id === $acc['actor'], 'la dependienta abre el pago en efectivo y firma ella' );
unset( $_REQUEST['t'] );
wp_set_current_user( (int) $clerk_id );
cvt_p( 'paid' === CVD_Payouts::pay_now( $p2, 'Efectivo listo en la tienda · Zaymi (enlace)' ), '«Dinero listo» marca pagado' );
$pick = $wpdb->get_var( $wpdb->prepare( "SELECT body FROM {$wpdb->prefix}cvd_payout_avisos WHERE payout_id=%d AND role='gestora'", $p2 ) );
cvt_p( str_contains( (string) $pick, 'Pasa por la tienda' ), 'la gestora recibe «Pasa por la tienda a recogerlo»' );
echo "\n--- AVISO A LA GESTORA (efectivo listo) ---\n" . $pick . "\n---\n";
$view = CVD_Payouts::view( CVD_Payouts::get( $p2 ) );
cvt_p( 'pickup' === CVD_Payouts::gestora_state( $view )[0], 'historial de la gestora: «Por recoger»' );
cvt_p( is_wp_error( CVD_Payouts::mark_received( $p2, $admin_id ) ), 'otra persona no puede marcar «Recibido»' );
cvt_p( true === CVD_Payouts::mark_received( $p2, $g->ID ) && true === CVD_Payouts::mark_received( $p2, $g->ID ), '«Recibido» (dos toques, una vez)' );
cvt_p( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cvd_payout_events WHERE payout_id=%d AND event_type='received'", $p2 ) ), 'queda un solo comprobante «received»' );
cvt_p( 'received' === CVD_Payouts::gestora_state( CVD_Payouts::view( CVD_Payouts::get( $p2 ) ) )[0], 'historial: «Recibido»' );

// 3) Deuda mayor que lo que retira: no se crea.
$o5 = $mk( 1, 795, '2026-10-15T12:00:00+00:00', 'Jabonera' );
CVD_Payouts::add_debt( $g->ID, 5000, 'CUP', 'Prueba deuda grande', $admin_id );
wp_set_current_user( $g->ID );
$p3 = CVD_Payouts::request( $g->ID, array( 'method' => 'transfer', 'card' => '9205129976543210', 'phone' => '53784147', 'token' => wp_generate_uuid4() ) );
cvt_p( is_wp_error( $p3 ) && str_contains( $p3->get_error_message(), '5.000 CUP' ), 'deuda mayor: no se crea y lo explica' );
cvt_p( 1 === count( CVD_Payouts::available_lines( $g->ID ) ), 'tras el error, nada quedó a medias' );

// 4) Puerta del bot: lista y «done» (idempotente).
$list = CVD_Payout_Avisos::pending_request()->get_data()['avisos'];
cvt_p( count( $list ) >= 5, 'GET /bot/payout-avisos lista los pendientes (' . count( $list ) . ')' );
$req = new WP_REST_Request( 'POST', '/casa-viva/v1/bot/payout-avisos/' . $list[0]['id'] . '/done' );
$req->set_url_params( array( 'id' => $list[0]['id'] ) );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'result' => 'sent', 'note' => 'prueba' ) ) );
$first = CVD_Payout_Avisos::done_request( $req )->get_data();
$second = CVD_Payout_Avisos::done_request( $req )->get_data();
cvt_p( false === $first['already'] && true === $second['already'], 'POST …/done marca una vez' );
cvt_p( count( CVD_Payout_Avisos::pending_request()->get_data()['avisos'] ) === count( $list ) - 1, 'ya no vuelve a salir' );

// 5) Pantalla de la gestora (sin errores) y pagos de antes (sin método nuevo) se siguen viendo.
wp_set_current_user( $g->ID );
$html = CVD_Payouts::render_gestora( $g );
cvt_p( str_contains( $html, 'Cobrar mi comisión' ) && str_contains( $html, 'Recibido' ) && str_contains( $html, 'Pagado' ), 'pantalla de la gestora con su historial' );
update_option( 'cvt_pagos_1015', array( 'gestora' => $g->ID, 'p1' => $p1, 'p2' => $p2, 'admin' => $admin_id, 'clerk' => (int) $clerk_id ) );
echo "OK pagos 3.14.23\n";
