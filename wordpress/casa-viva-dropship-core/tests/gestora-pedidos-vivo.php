<?php
/**
 * Prueba en la base REAL, dentro de una transacción que se DESHACE al final (ROLLBACK). Core 3.14.116, Ernesto 3-nov.
 * No toca pedidos reales: crea pedidos de ensayo (_cvd_ensayo, _cvd_test_order, sin teléfono) y al final no queda nada.
 * - Correo bloqueado (pre_wp_mail) y toda salida a internet bloqueada (pre_http_request): no sale ningún WhatsApp ni aviso.
 * - Los avisos al bot (CVD_Order_Avisos) solo se apuntan dentro del pedido de ensayo (test=true) y se deshacen.
 * - Las transacciones internas del Core (CVD_Order_Transition_Service…) se convierten en SAVEPOINT, para que no
 *   confirmen la transacción de la prueba; cualquier CREATE/ALTER/DROP se frena (confirmaría la transacción).
 * Se corre: wp eval-file gestora-pedidos-vivo.php  (con la versión 3.14.116 cargada). Sale con código 1 si algo falla.
 * Referencia: caso real #6062 → #6102 (topper de 60 USD creado por error; el Vigía lo quitó a las 14:27 con edit()).
 */
global $wpdb;
if ( ! class_exists( 'CVD_Gestora_Pedidos' ) || ! method_exists( 'CVD_Order_Edit', 'split' ) ) { echo "Falta la versión nueva (CVD_Gestora_Pedidos / CVD_Order_Edit::split).\n"; exit( 1 ); }

// wp eval-file corre este archivo dentro de una función: contadores y listas van en $GLOBALS.
$GLOBALS['t_fails'] = 0; $GLOBALS['t_n'] = 0; $GLOBALS['t_created'] = array(); $GLOBALS['t_out'] = array(); $GLOBALS['t_step'] = '';
function t_ok( string $name, bool $cond, $extra = '' ): void {
	$GLOBALS['t_n']++;
	if ( $cond ) { echo "ok    {$name}\n"; return; }
	$GLOBALS['t_fails']++; echo "FALLA {$name}" . ( '' !== $extra ? ' → ' . ( is_string( $extra ) ? $extra : wp_json_encode( $extra, JSON_UNESCAPED_UNICODE ) ) : '' ) . "\n";
}
function t_stock( int $id ): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'", $id ) ); }

// ---------- 0. Nada sale de aquí ----------
$out = &$GLOBALS['t_out'];
add_filter( 'pre_wp_mail', static function ( $r, $atts ) { $GLOBALS['t_out'][] = 'correo «' . mb_substr( (string) ( $atts['subject'] ?? '' ), 0, 70 ) . '» a ' . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . ' [' . $GLOBALS['t_step'] . ']'; return false; }, 0, 2 );
add_filter( 'pre_http_request', static function ( $r, $args, $url ) { $GLOBALS['t_out'][] = 'http ' . $url . ' [' . $GLOBALS['t_step'] . ']'; return new WP_Error( 'cvd_prueba', 'bloqueado en la prueba' ); }, 0, 3 );
// Ganchos que publican o avisan por su cuenta (reparto al grupo, ampliar oferta): fuera durante la prueba.
foreach ( array( 'cvd_dispatch_new_order', 'cvd_expand_delivery_offer' ) as $hook ) { remove_all_actions( $hook ); }
// Las cuentas de prueba 45 y 46 cuentan como gestoras aprobadas SOLO en este proceso (no se escribe nada en su cuenta).
$G1 = 46; $G2 = 45;
add_filter( 'get_user_metadata', static function ( $v, $uid, $key ) use ( $G1, $G2 ) { return in_array( (int) $uid, array( $G1, $G2 ), true ) && '_cvd_account_status' === $key ? array( 'approved' ) : $v; }, 10, 3 );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) )[0] ?? 0 );

$CAMERO = (int) wc_get_product_id_by_sku( 'BC-109793-1' ); // Protector Camero 140x190 (34 USD), como en #6102
$TOPPER = 3702;                                           // Topper de gel 4 cm (Full) (60 USD), el que se puso por error en #6102
$stock0 = array( $CAMERO => t_stock( $CAMERO ), $TOPPER => t_stock( $TOPPER ) );
$ref = wc_get_order( 6102 );
echo "Antes: existencias Camero={$stock0[$CAMERO]} Topper={$stock0[$TOPPER]}. #6102 hoy: total " . ( $ref ? $ref->get_total() : '?' ) . " (solo lectura).\n";

// ---------- Transacción ----------
$wpdb->query( 'START TRANSACTION' );
$depth = 0; $ddl = array();
$qf = static function ( $q ) use ( &$depth, &$ddl ) {
	$t = strtoupper( trim( (string) $q ) );
	if ( 'START TRANSACTION' === $t || 'BEGIN' === $t ) { $depth++; return 'SAVEPOINT cvd_t' . $depth; }
	if ( 'COMMIT' === $t ) { if ( $depth > 0 ) { return 'RELEASE SAVEPOINT cvd_t' . ( $depth-- ); } return 'SELECT 1'; }
	if ( 'ROLLBACK' === $t ) { if ( $depth > 0 ) { return 'ROLLBACK TO SAVEPOINT cvd_t' . ( $depth-- ); } return 'SELECT 1'; }
	if ( preg_match( '/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK TABLES|UNLOCK TABLES)\b/', $t ) ) { $ddl[] = substr( (string) $q, 0, 90 ); return 'SELECT 1'; }
	return $q;
};
add_filter( 'query', $qf, 999 );
$created = &$GLOBALS['t_created'];

function t_order( int $owner, array $lines, string $name = 'PRUEBA ensayo Claude' ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'cvd-prueba-1103' ) );
	foreach ( $lines as $pid => $qty ) {
		$product = wc_get_product( $pid ); $base = (float) $product->get_price();
		$unit = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $owner, $base ) : $base;
		$item_id = $order->add_product( $product, $qty, array( 'subtotal' => $unit * $qty, 'total' => $unit * $qty ) );
		$item = $order->get_item( $item_id );
		$item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $base, 4 ), true ); $item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $unit, 4 ), true );
		$item->add_meta_data( '_cvd_margin_unit', wc_format_decimal( max( 0, $unit - $base ), 4 ), true ); $item->add_meta_data( '_cvd_pricing_gestora_user_id', $owner, true ); $item->save();
	}
	$order->set_billing_first_name( $name ); $order->set_billing_last_name( 'no enviar' ); $order->set_billing_phone( '' ); $order->set_billing_email( '' );
	$order->set_billing_address_1( 'Calle de prueba #1' ); $order->set_billing_city( 'Diez de Octubre' ); $order->set_billing_country( 'CU' );
	foreach ( array( '_cvd_fulfillment_type' => 'delivery', '_cvd_locality' => 'La Palma', '_cvd_payment_kind' => 'cash_usd', '_cvd_payment_factor' => 1, '_cvd_payment_currency' => 'USD',
		'_cvd_owner_user_id' => $owner, '_cvd_owner_type' => 'gestora', '_cvd_pricing_gestora_user_id' => $owner, 'gestora_nombre' => get_userdata( $owner )->display_name, 'gestora_id' => $owner,
		'_cvd_test_order' => 'claude-1103', '_cvd_ensayo' => 'claude-1103', '_cvd_staff_new_order_notified' => current_time( 'mysql', true ) ) as $k => $v ) { $order->update_meta_data( $k, $v ); }
	$order->calculate_totals( false ); $order->save();
	$order->update_status( 'on-hold', 'Pedido de ensayo de la prueba 3.14.116 (se deshace).' );
	CVD_Delivery::initialize_order( wc_get_order( $order->get_id() ) );
	CVD_Sales::initialize_order( wc_get_order( $order->get_id() ) );
	$GLOBALS['t_created'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
}
function t_rest( int $user, string $method, string $path, array $body = array() ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/casa-viva/v1' . $path );
	if ( $body ) { $req->set_header( 'content-type', 'application/json' ); $req->set_body( wp_json_encode( $body ) ); }
	$res = rest_do_request( $req );
	$data = $res->get_data();
	return array( 'status' => $res->get_status(), 'data' => is_array( $data ) ? $data : array(), 'msg' => is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '' );
}
function t_item( WC_Order $order, int $pid ): ?WC_Order_Item_Product { foreach ( $order->get_items() as $it ) { if ( (int) $it->get_product_id() === $pid ) { return $it; } } return null; }
function t_set( int $id, array $meta ): void { $o = wc_get_order( $id ); foreach ( $meta as $k => $v ) { null === $v ? $o->delete_meta_data( $k ) : $o->update_meta_data( $k, $v ); } $o->save(); }

try {
	$GLOBALS['t_step'] = 'crear y quitar topper';
	// ---------- 1. El caso #6102: Camero 34 + Topper 60 = 94; la gestora quita el topper ----------
	$o = t_order( $G1, array( $CAMERO => 1, $TOPPER => 1 ) ); $id = $o->get_id();
	$pc = (float) t_item( $o, $CAMERO )->get_total(); $pt = (float) t_item( $o, $TOPPER )->get_total(); // precios de la gestora de prueba (34 y 60 sin aumento)
	t_ok( "pedido de ensayo creado como #6102 ({$pc} + {$pt} USD, en espera, sin teléfono)", abs( $pc + $pt - (float) $o->get_total() ) < 0.01 && $o->has_status( 'on-hold' ) && '' === $o->get_billing_phone(), $o->get_total() );
	t_ok( 'al crearlo bajan las existencias (Camero −1, Topper −1)', t_stock( $CAMERO ) === $stock0[ $CAMERO ] - 1 && t_stock( $TOPPER ) === $stock0[ $TOPPER ] - 1 );
	$com0 = (float) $o->get_meta( '_cvd_commission_amount', true );
	$topper = t_item( $o, $TOPPER ); $camero = t_item( $o, $CAMERO );
	$list = t_rest( $G1, 'GET', '/gestora/pedidos' );
	$row = array_values( array_filter( $list['data']['orders'] ?? array(), static fn( $r ) => (int) $r['id'] === $id ) )[0] ?? null;
	t_ok( 'GET /gestora/pedidos: sale con «Cambiar» y «Cancelar» permitidos', $row && '' === $row['can']['cambiar'] && '' === $row['can']['cancelar'], $list );
	$det = t_rest( $G1, 'GET', '/gestora/pedido/' . $id );
	t_ok( 'GET /gestora/pedido/{id}: dos líneas y el total', 200 === $det['status'] && 2 === count( $det['data']['lines'] ?? array() ) && abs( $pc + $pt - (float) $det['data']['total'] ) < 0.01, $det );
	$rq = 'prueba-' . wp_generate_uuid4();
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'requestId' => $rq, 'lines' => array( array( 'itemId' => $topper->get_id(), 'qty' => 0 ), array( 'itemId' => $camero->get_id(), 'qty' => 1 ) ) ) );
	$o = wc_get_order( $id );
	t_ok( "quitar el topper: 200 y total {$pc} USD (como el Vigía a las 14:27)", 200 === $r['status'] && abs( $pc - (float) $o->get_total() ) < 0.01, $r );
	t_ok( 'el mensaje dice el total nuevo', false !== strpos( $r['msg'], wc_format_decimal( $pc, 2 ) ), $r['msg'] );
	t_ok( 'el topper vuelve a la tienda; el Camero sigue apartado', t_stock( $TOPPER ) === $stock0[ $TOPPER ] && t_stock( $CAMERO ) === $stock0[ $CAMERO ] - 1, array( t_stock( $TOPPER ), t_stock( $CAMERO ) ) );
	$edits = CVD_Order_Edit::pending_caja_edits( $o );
	t_ok( 'la caja recibe +1 topper (una sola vez)', 1 === count( $edits ) && 1 === $edits[0]['delta'] && $TOPPER === $edits[0]['wooId'], $edits );
	$com1 = (float) $o->get_meta( '_cvd_commission_amount', true );
	t_ok( 'la comisión baja (solo queda la del Camero)', $com1 < $com0 && $com1 > 0, array( $com0, $com1 ) );
	$notes = implode( ' | ', array_map( static fn( $x ) => $x->content, wc_get_order_notes( array( 'order_id' => $id ) ) ) );
	t_ok( 'nota «Pedido CAMBIADO por … (gestora, desde su área): Quitado: 1× Topper»', false !== strpos( $notes, '(gestora, desde su área)' ) && false !== strpos( $notes, 'Quitado: 1×' ), $notes );
	$av = (array) $o->get_meta( CVD_Order_Avisos::META, true );
	$last = end( $av );
	t_ok( 'aviso al bot apuntado como PRUEBA (no escribe a nadie) y de la gestora', is_array( $last ) && ! empty( $last['test'] ) && 'gestora' === $last['source'] && 'edit' === $last['type'], $last );
	$r2 = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'requestId' => $rq, 'lines' => array( array( 'itemId' => $topper->get_id(), 'qty' => 0 ) ) ) );
	t_ok( 'la misma orden repetida (doble toque) no se aplica dos veces', 200 === $r2['status'] && ! empty( $r2['data']['replayed'] ) && 1 === count( CVD_Order_Edit::pending_caja_edits( wc_get_order( $id ) ) ) && t_stock( $TOPPER ) === $stock0[ $TOPPER ], $r2 );
	t_ok( '#6102 real (34 USD tras quitar el topper) y el ensayo quedan igual', $ref && ( 34.0 !== $pc || abs( (float) $ref->get_total() - (float) wc_get_order( $id )->get_total() ) < 0.01 ), array( $ref ? $ref->get_total() : null, $pc ) );

	$GLOBALS['t_step'] = 'los no';
	// ---------- 2. Lo que NO se puede (y no cambia nada) ----------
	$o = wc_get_order( $id ); $camero = t_item( $o, $CAMERO ); $before = (float) $o->get_total(); $stB = t_stock( $CAMERO );
	$cases = array(
		'subir la cantidad'      => array( array( 'lines' => array( array( 'itemId' => $camero->get_id(), 'qty' => 2 ) ) ), 422, 'solo puedes bajar' ),
		'añadir un producto'     => array( array( 'add' => array( array( 'wooId' => $TOPPER, 'qty' => 1 ) ) ), 422, 'Para añadir' ),
		'poner vuelto'           => array( array( 'changeRequired' => array( array( 'amount' => 5, 'currency' => 'USD' ) ) ), 422, 'vuelto' ),
		'cambiar de reparto'     => array( array( 'locality' => 'Playa' ), 422, 'municipio' ),
		'dejarlo vacío'          => array( array( 'lines' => array( array( 'itemId' => $camero->get_id(), 'qty' => 0 ) ) ), 422, 'sin productos' ),
		'línea de otro pedido'   => array( array( 'lines' => array( array( 'itemId' => 999999, 'qty' => 0 ) ) ), 422, 'no está en el pedido' ),
	);
	foreach ( $cases as $label => $c ) {
		$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", $c[0] );
		t_ok( "no: {$label} ({$c[1]})", $c[1] === $r['status'] && false !== mb_stripos( $r['msg'], $c[2] ), $r );
	}
	$states = array(
		'el mensajero ya lo recogió'     => array( array( '_cvd_delivery_status' => 'picked_up' ), 'recogió' ),
		'el mensajero ya lo entregó'     => array( array( '_cvd_delivery_status' => 'handed_over' ), 'recogió' ),
		'pagado por Zelle'               => array( array( '_cvd_payment_kind' => 'zelle' ), 'adelantado' ),
		'pagado por PayPal'              => array( array( '_cvd_payment_kind' => 'paypal' ), 'adelantado' ),
		'transferencia CUP'              => array( array( '_cvd_payment_kind' => 'transfer_cup' ), 'adelantado' ),
		'tarjeta clásica'                => array( array( '_cvd_payment_kind' => 'clasica' ), 'adelantado' ),
		'con reserva'                    => array( array( CVD_Reserva::UNTIL => wp_date( 'Y-m-d' ) ), 'reserva' ),
		'comisión aprobada'              => array( array( '_cvd_commission_status' => 'approved' ), 'comisión' ),
		'comisión pagada'                => array( array( '_cvd_commission_status' => 'paid' ), 'comisión' ),
		'comisión en una solicitud de pago' => array( array( '_cvd_payout_id' => 123 ), 'comisión' ),
	);
	foreach ( $states as $label => $s ) {
		$restore = array(); $o = wc_get_order( $id );
		foreach ( $s[0] as $k => $v ) { $old = $o->get_meta( $k, true ); $restore[ $k ] = '' === $old ? null : $old; }
		t_set( $id, $s[0] );
		$r1 = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'note' => 'no debería guardarse' ) );
		$r2 = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'no debería cancelarse' ) );
		t_ok( "no: {$label} (cambiar y cancelar 409)", 409 === $r1['status'] && 409 === $r2['status'] && false !== mb_stripos( $r1['msg'], $s[1] ) && wc_get_order( $id )->has_status( 'on-hold' ), array( $r1, $r2 ) );
		t_set( $id, $restore );
	}
	t_ok( 'tras los «no», el pedido sigue igual (total, existencias, nota)', $before === (float) wc_get_order( $id )->get_total() && $stB === t_stock( $CAMERO ) && 'no debería guardarse' !== wc_get_order( $id )->get_customer_note() );
	$r = t_rest( $G2, 'GET', '/gestora/pedido/' . $id );
	$r2 = t_rest( $G2, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'no es mío' ) );
	t_ok( 'otra gestora: 404 «no está entre los tuyos» (ver y cancelar)', 404 === $r['status'] && 404 === $r2['status'] && wc_get_order( $id )->has_status( 'on-hold' ), array( $r, $r2 ) );
	$r = t_rest( 0, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'sin sesión' ) );
	t_ok( 'sin sesión: 401', 401 === $r['status'], $r );
	$r = t_rest( $G1, 'GET', '/gestora/pedido/6062' );
	t_ok( 'pedido real #6062 de Nadielys pedido por otra gestora: 404 (solo lectura)', 404 === $r['status'], $r );
	add_option( 'cvd_gp_lock_' . $id, time(), '', 'no' );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'note' => 'a la vez' ) );
	t_ok( 'dos cambios a la vez: el segundo espera (409)', 409 === $r['status'] && false !== mb_stripos( $r['msg'], 'ahora mismo' ), $r );
	delete_option( 'cvd_gp_lock_' . $id );
	$r = t_rest( $G1, 'POST', "/sales/{$id}/split", array( 'lines' => array( array( 'itemId' => 1, 'qty' => 1 ) ), 'to' => array( 'firstName' => 'x' ) ) );
	t_ok( 'la gestora no puede «Dividir» (solo la tienda): 403', 403 === $r['status'], $r );

	$GLOBALS['t_step'] = 'contacto';
	// ---------- 3. Contacto, calle, día y nota ----------
	$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'firstName' => 'PRUEBA Katy', 'address' => 'Calle Georgia #3 (prueba)', 'deliveryDate' => $tomorrow, 'deliveryWindow' => 'morning', 'note' => 'tocar fuerte (prueba)' ) );
	$o = wc_get_order( $id );
	t_ok( 'cambia nombre, calle, día, franja y nota; el reparto y el total siguen igual', 200 === $r['status'] && 'PRUEBA Katy' === $o->get_billing_first_name() && 'Calle Georgia #3 (prueba)' === $o->get_billing_address_1() && $tomorrow === $o->get_meta( '_cvd_delivery_date', true ) && 'morning' === $o->get_meta( '_cvd_delivery_window', true ) && 'La Palma' === $o->get_meta( '_cvd_locality', true ) && abs( $pc - (float) $o->get_total() ) < 0.01, $r );

	$GLOBALS['t_step'] = 'cancelar/recuperar (gestora)';
	// ---------- 4. Cancelar y recuperar ----------
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => '' ) );
	t_ok( 'cancelar sin motivo: 422', 422 === $r['status'], $r );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'el cliente ya no lo quiere (prueba)', 'requestId' => 'prueba-c-' . $id ) );
	$o = wc_get_order( $id );
	t_ok( 'cancelar con motivo: cancelado, el Camero vuelve a la tienda, comisión anulada', 200 === $r['status'] && $o->has_status( 'cancelled' ) && t_stock( $CAMERO ) === $stock0[ $CAMERO ] && 'cancelled' === $o->get_meta( '_cvd_commission_status', true ) && 'gestora' === $o->get_meta( '_cvd_cancel_source', true ), $r );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'otra vez', 'requestId' => 'prueba-c-' . $id ) );
	t_ok( 'cancelar repetido (misma orden): no hace nada más', 200 === $r['status'] && ! empty( $r['data']['replayed'] ) && t_stock( $CAMERO ) === $stock0[ $CAMERO ], $r );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'note' => 'x' ) );
	t_ok( 'cancelado: ya no se cambia (409)', 409 === $r['status'], $r );
	$r = t_rest( $G1, 'POST', "/gestora/pedido/{$id}/recuperar", array( 'reason' => 'lo cancelé por error (prueba)' ) );
	$o = wc_get_order( $id );
	t_ok( 'recuperar: en espera otra vez, el Camero se aparta otra vez, comisión pendiente', 200 === $r['status'] && $o->has_status( 'on-hold' ) && t_stock( $CAMERO ) === $stock0[ $CAMERO ] - 1 && 'pending' === $o->get_meta( '_cvd_commission_status', true ), $r );
	$GLOBALS['t_step'] = 'cancelar por la tienda';
	// Cancelado por la tienda → ella no lo recupera.
	$o2 = t_order( $G1, array( $CAMERO => 1 ) );
	CVD_Order_Edit::cancel( $o2->get_id(), 'cancelado por la tienda (prueba)', $admin, 'ventas' );
	$r = t_rest( $G1, 'POST', '/gestora/pedido/' . $o2->get_id() . '/recuperar', array( 'reason' => 'quiero recuperarlo' ) );
	t_ok( 'lo canceló la tienda: ella no lo recupera (409)', 409 === $r['status'] && false !== mb_stripos( $r['msg'], 'la tienda' ) && wc_get_order( $o2->get_id() )->has_status( 'cancelled' ), $r );

	// ---------- 5. Dividir (la tienda), como debió hacerse con #6062: Queen para Ricardo, Camero para Katy ----------
	$GLOBALS['t_step'] = 'crear para dividir';
	$a = t_order( $G1, array( $TOPPER => 2, $CAMERO => 1 ), 'PRUEBA Ricardo' ); $aid = $a->get_id();
	$total0 = (float) $a->get_total(); $acom0 = (float) $a->get_meta( '_cvd_commission_amount', true ); $s1 = array( $CAMERO => t_stock( $CAMERO ), $TOPPER => t_stock( $TOPPER ) );
	$at = t_item( $a, $TOPPER ); $ac = t_item( $a, $CAMERO );
	$r = t_rest( $G1, 'POST', "/sales/{$aid}/split", array( 'lines' => array( array( 'itemId' => $ac->get_id(), 'qty' => 1 ) ), 'to' => array( 'firstName' => 'x' ) ) );
	t_ok( 'Dividir pedido: una gestora no puede (403)', 403 === $r['status'], $r );
	$r = t_rest( $admin, 'POST', "/sales/{$aid}/split", array( 'lines' => array( array( 'itemId' => $ac->get_id(), 'qty' => 1 ), array( 'itemId' => $at->get_id(), 'qty' => 2 ) ), 'to' => array( 'firstName' => 'PRUEBA Katy', 'address' => 'Calle Georgia #3' ) ) );
	t_ok( 'dividir TODO: no (el pedido de antes no puede quedar vacío)', 422 === $r['status'], $r );
	t_set( $aid, array( '_cvd_payment_kind' => 'zelle' ) );
	$r = t_rest( $admin, 'POST', "/sales/{$aid}/split", array( 'lines' => array( array( 'itemId' => $ac->get_id(), 'qty' => 1 ) ), 'to' => array( 'firstName' => 'PRUEBA Katy', 'address' => 'Calle Georgia #3' ) ) );
	t_ok( 'dividir un prepago: no (409)', 409 === $r['status'], $r );
	t_set( $aid, array( '_cvd_payment_kind' => 'cash_usd', '_cvd_delivery_status' => 'picked_up' ) );
	$r = t_rest( $admin, 'POST', "/sales/{$aid}/split", array( 'lines' => array( array( 'itemId' => $ac->get_id(), 'qty' => 1 ) ), 'to' => array( 'firstName' => 'PRUEBA Katy', 'address' => 'Calle Georgia #3' ) ) );
	t_ok( 'dividir con el paquete ya recogido: no (409)', 409 === $r['status'], $r );
	t_set( $aid, array( '_cvd_delivery_status' => 'unassigned' ) );
	$GLOBALS['t_step'] = 'dividir';
	$srq = 'prueba-s-' . wp_generate_uuid4();
	$body = array( 'requestId' => $srq, 'lines' => array( array( 'itemId' => $ac->get_id(), 'qty' => 1 ), array( 'itemId' => $at->get_id(), 'qty' => 1 ) ),
		'to' => array( 'firstName' => 'PRUEBA Katy', 'lastName' => 'ensayo', 'phone' => '53999999', 'address' => 'Calle Georgia #3 entre Porvenir y Hatuey', 'locality' => 'La Palma', 'deliveryDate' => '', 'deliveryWindow' => 'afternoon', 'note' => 'nota de prueba' ) );
	$r = t_rest( $admin, 'POST', "/sales/{$aid}/split", $body );
	$bid = (int) ( $r['data']['newOrderId'] ?? 0 ); if ( $bid ) { $created[] = $bid; }
	$a = wc_get_order( $aid ); $b = $bid ? wc_get_order( $bid ) : null;
	t_ok( 'dividir Camero ×1 + Topper 1 de 2 → pedido nuevo', 200 === $r['status'] && $b instanceof WC_Order, $r );
	if ( $b ) {
		t_ok( 'el total de los dos es el de antes (' . $total0 . ')', abs( (float) $a->get_total() + (float) $b->get_total() - $total0 ) < 0.01, array( $a->get_total(), $b->get_total() ) );
		t_ok( 'el nuevo vale Camero + 1 Topper; el de antes queda con 1 Topper', abs( $pc + $pt - (float) $b->get_total() ) < 0.01 && abs( $pt - (float) $a->get_total() ) < 0.01, array( $a->get_total(), $b->get_total() ) );
		t_ok( 'las existencias NO cambian al dividir (ni dobles ni liberadas)', $s1[ $CAMERO ] === t_stock( $CAMERO ) && $s1[ $TOPPER ] === t_stock( $TOPPER ), array( $s1, t_stock( $CAMERO ), t_stock( $TOPPER ) ) );
		$bred = array_sum( array_map( static fn( $it ) => (int) $it->get_meta( '_reduced_stock', true ), $b->get_items() ) );
		$ared = array_sum( array_map( static fn( $it ) => (int) $it->get_meta( '_reduced_stock', true ), $a->get_items() ) );
		t_ok( 'lo descontado: nuevo 2 unidades, el de antes 1 (total 3 = lo de antes)', 2 === $bred && 1 === $ared, array( $ared, $bred ) );
		$ce = CVD_Order_Edit::pending_caja_edits( $a );
		t_ok( 'caja: el de antes devuelve +1 Camero y +1 Topper; el nuevo saldrá como venta (−2) = la caja igual', 2 === count( $ce ) && 2 === array_sum( array_column( $ce, 'delta' ) ) && 2 === $bred, $ce );
		$bcom = (float) $b->get_meta( '_cvd_commission_amount', true ); $acom = (float) $a->get_meta( '_cvd_commission_amount', true );
		t_ok( 'comisión por línea: la de los dos suma la de antes (' . $acom0 . ')', abs( $acom + $bcom - $acom0 ) < 0.011 && $bcom > 0 && $acom > 0, array( $acom0, $acom, $bcom, $r['data']['commission'] ?? null ) );
		t_ok( 'el nuevo: misma gestora, mismo estado, ensayo, efectivo USD, sin teléfono', $G1 === absint( $b->get_meta( '_cvd_owner_user_id', true ) ) && 'gestora' === $b->get_meta( '_cvd_owner_type', true ) && $b->has_status( 'on-hold' ) && 'claude-1103' === $b->get_meta( '_cvd_ensayo', true ) && 'cash_usd' === $b->get_meta( '_cvd_payment_kind', true ) && '' === $b->get_billing_phone(), array( $b->get_status(), $b->get_billing_phone() ) );
		t_ok( 'el nuevo: fecha de venta del de antes (el día no cuenta dos veces)', $a->get_date_created()->getTimestamp() === $b->get_date_created()->getTimestamp() );
		t_ok( 'el nuevo: «Nuevo», sin mensajero, reparto La Palma, por la tarde', 'new' === $b->get_meta( '_cvd_operation_status', true ) && 'unassigned' === $b->get_meta( '_cvd_delivery_status', true ) && ! $b->get_meta( '_cvd_messenger_user_id', true ) && 'La Palma' === $b->get_meta( '_cvd_locality', true ) && 'afternoon' === $b->get_meta( '_cvd_delivery_window', true ) );
		t_ok( 'el nuevo: sin avisos del bot ni correo', ! $b->get_meta( CVD_Order_Avisos::META, true ) && ! array_filter( $out, static fn( $x ) => 0 === strpos( $x, 'correo' ) && false !== strpos( $x, '[dividir]' ) ) && '' === $b->get_billing_email(), $out );
		$an = implode( ' | ', array_map( static fn( $x ) => $x->content, wc_get_order_notes( array( 'order_id' => $aid ) ) ) );
		$bn = implode( ' | ', array_map( static fn( $x ) => $x->content, wc_get_order_notes( array( 'order_id' => $bid ) ) ) );
		t_ok( 'notas en los dos pedidos', false !== strpos( $an, 'Pedido DIVIDIDO' ) && false !== strpos( $an, '#' . $b->get_order_number() ) && false !== strpos( $bn, 'Pedido creado al DIVIDIR el #' . $a->get_order_number() ), array( $an, $bn ) );
		t_ok( 'notas privadas (ninguna al cliente)', ! array_filter( wc_get_order_notes( array( 'order_id' => $bid ) ), static fn( $x ) => $x->customer_note ) && ! array_filter( wc_get_order_notes( array( 'order_id' => $aid ) ), static fn( $x ) => $x->customer_note ) );
		$aav = (array) $a->get_meta( CVD_Order_Avisos::META, true ); $al = end( $aav );
		t_ok( 'el de antes (de gestora) apunta el cambio para mensajero y gestora, como PRUEBA', is_array( $al ) && 'split' === $al['source'] && ! empty( $al['test'] ), $al );
		$again = t_rest( $admin, 'POST', "/sales/{$aid}/split", $body );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_cvd_split_from' AND meta_value=%s", (string) $aid ) );
		t_ok( 'dividir repetido (doble toque): no crea un tercer pedido', 200 === $again['status'] && ! empty( $again['data']['replayed'] ) && 1 === $count && $bid === (int) $again['data']['newOrderId'], array( $again, $count ) );
		$GLOBALS['t_step'] = 'cancelar los dos (tienda)';
		// Cancelar el nuevo devuelve sus 2 unidades; cancelar el de antes, la suya: todo vuelve a como al principio.
		CVD_Order_Edit::cancel( $bid, 'prueba: cancelar el nuevo', $admin, 'ventas' );
		CVD_Order_Edit::cancel( $aid, 'prueba: cancelar el de antes', $admin, 'ventas' );
		t_ok( 'cancelar los dos devuelve todo: existencias como al principio de la prueba', t_stock( $CAMERO ) === $stock0[ $CAMERO ] - 1 && t_stock( $TOPPER ) === $stock0[ $TOPPER ], array( t_stock( $CAMERO ), t_stock( $TOPPER ) ) ); // −1 = el pedido 1 recuperado sigue apartando su Camero
	}
	t_ok( 'ningún correo al dividir ni a clientes (solo el aviso de WooCommerce «pocas existencias» a la tienda, bloqueado)', ! array_filter( $out, static fn( $x ) => 0 === strpos( $x, 'correo' ) && ( false !== strpos( $x, '[dividir]' ) || false !== strpos( $x, '[los no]' ) || false === strpos( $x, 'pocas existencias' ) ) ), $out );
	t_ok( 'nada intentó crear o cambiar tablas', ! $ddl, $ddl );
} catch ( Throwable $e ) {
	t_ok( 'sin errores de PHP', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	remove_filter( 'query', $qf, 999 );
	$wpdb->query( 'ROLLBACK' );
	wp_set_current_user( 0 );
}

// ---------- Después del ROLLBACK: no queda nada ----------
$left = $created ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id IN (" . implode( ',', array_map( 'absint', $created ) ) . ')' ) : 0;
t_ok( 'después de deshacer: no queda ningún pedido de ensayo (' . implode( ',', $created ) . ')', 0 === $left, $left );
t_ok( 'después de deshacer: existencias como antes', t_stock( $CAMERO ) === $stock0[ $CAMERO ] && t_stock( $TOPPER ) === $stock0[ $TOPPER ], array( t_stock( $CAMERO ), t_stock( $TOPPER ) ) );
t_ok( 'después de deshacer: sin candados colgados', ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'cvd\\_gp\\_lock\\_%' OR option_name LIKE 'cvd\\_split\\_lock\\_%'" ) );
echo "Intentos de salida bloqueados: " . count( $out ) . ( $out ? "
  " . implode( "
  ", array_unique( $out ) ) : '' ) . "
";
$fails = $GLOBALS['t_fails']; $n = $GLOBALS['t_n'];
echo $fails ? "\n{$fails} de {$n} pruebas FALLARON.\n" : "\nOK: {$n} pruebas en la base real, todo deshecho.\n";
exit( $fails ? 1 : 0 );
