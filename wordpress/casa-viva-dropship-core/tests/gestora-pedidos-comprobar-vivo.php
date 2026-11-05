<?php
/**
 * Comprobación en vivo DESPUÉS de desplegar 3.14.116 (Ernesto 3-nov). Esto SÍ se guarda, por eso:
 * - pedidos de ensayo (_cvd_ensayo, _cvd_test_order, «PRUEBA … no enviar»), sin teléfono ni correo, de la gestora de prueba 46;
 * - productos que NO llevan existencias (no tocan la tienda ni la caja);
 * - correo e internet bloqueados en este proceso; los avisos al bot quedan como PRUEBA (el bot no escribe);
 * - pedidos reales (#6062, #6102): solo lectura;
 * - al final los pedidos de ensayo se cancelan y van a la papelera.
 * Se corre: wp eval-file gestora-pedidos-comprobar-vivo.php
 */
$GLOBALS['c_fails'] = 0; $GLOBALS['c_n'] = 0; $GLOBALS['c_out'] = array();
function c_ok( string $name, bool $cond, $extra = '' ): void {
	$GLOBALS['c_n']++;
	echo ( $cond ? 'ok    ' : 'FALLA ' ) . $name . ( $cond || '' === $extra ? '' : ' → ' . ( is_string( $extra ) ? $extra : wp_json_encode( $extra, JSON_UNESCAPED_UNICODE ) ) ) . "\n";
	if ( ! $cond ) { $GLOBALS['c_fails']++; }
}
add_filter( 'pre_wp_mail', static function ( $r, $atts ) { $GLOBALS['c_out'][] = 'correo «' . ( $atts['subject'] ?? '' ) . '»'; return false; }, 0, 2 );
add_filter( 'pre_http_request', static function ( $r, $a, $url ) { $GLOBALS['c_out'][] = 'http ' . $url; return new WP_Error( 'cvd_prueba', 'bloqueado' ); }, 0, 3 );
foreach ( array( 'cvd_dispatch_new_order', 'cvd_expand_delivery_offer' ) as $hook ) { remove_all_actions( $hook ); }
$G = 46; // «gestora prueba» (cuenta de prueba, sin teléfono): aprobada SOLO en este proceso, no se toca su cuenta.
add_filter( 'get_user_metadata', static function ( $v, $uid, $key ) use ( $G ) { return (int) $uid === $G && '_cvd_account_status' === $key ? array( 'approved' ) : $v; }, 10, 3 );
$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) )[0] ?? 0 );
function c_rest( int $user, string $method, string $path, array $body = array() ): array {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, '/casa-viva/v1' . $path );
	if ( $body ) { $req->set_header( 'content-type', 'application/json' ); $req->set_body( wp_json_encode( $body ) ); }
	$res = rest_do_request( $req ); $d = $res->get_data();
	return array( 'status' => $res->get_status(), 'data' => is_array( $d ) ? $d : array(), 'msg' => is_array( $d ) ? (string) ( $d['message'] ?? '' ) : '' );
}
$A = 5959; $B = 5961; // productos sin control de existencias
foreach ( array( $A, $B ) as $pid ) { $p = wc_get_product( $pid ); if ( ! $p || $p->managing_stock() ) { echo "El producto {$pid} lleva existencias: paro.\n"; exit( 1 ); } }

// Pedido de ensayo.
$o = wc_create_order( array( 'status' => 'pending', 'created_via' => 'cvd-comprobar-1103' ) );
foreach ( array( $A => 2, $B => 1 ) as $pid => $qty ) {
	$p = wc_get_product( $pid ); $base = (float) $p->get_price(); $unit = (float) CVD_Gestora_Store::resolve_price( $p, $G, $base );
	$iid = $o->add_product( $p, $qty, array( 'subtotal' => $unit * $qty, 'total' => $unit * $qty ) ); $it = $o->get_item( $iid );
	$it->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $base, 4 ), true ); $it->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $unit, 4 ), true ); $it->add_meta_data( '_cvd_pricing_gestora_user_id', $G, true ); $it->save();
}
$o->set_billing_first_name( 'PRUEBA ensayo Claude' ); $o->set_billing_last_name( 'no enviar' ); $o->set_billing_phone( '' ); $o->set_billing_email( '' );
$o->set_billing_address_1( 'Calle de prueba #1' ); $o->set_billing_city( 'Diez de Octubre' ); $o->set_billing_country( 'CU' );
foreach ( array( '_cvd_fulfillment_type' => 'delivery', '_cvd_locality' => 'La Palma', '_cvd_payment_kind' => 'cash_usd', '_cvd_payment_factor' => 1, '_cvd_owner_user_id' => $G, '_cvd_owner_type' => 'gestora', '_cvd_pricing_gestora_user_id' => $G, 'gestora_nombre' => get_userdata( $G )->display_name,
	'_cvd_test_order' => 'claude-1103-vivo', '_cvd_ensayo' => 'claude-1103-vivo', '_cvd_staff_new_order_notified' => current_time( 'mysql', true ) ) as $k => $v ) { $o->update_meta_data( $k, $v ); }
$o->calculate_totals( false ); $o->save();
$o->update_status( 'on-hold', 'Pedido de ENSAYO para comprobar 3.14.116 en vivo (se cancela y va a la papelera al terminar).' );
CVD_Delivery::initialize_order( wc_get_order( $o->get_id() ) ); CVD_Sales::initialize_order( wc_get_order( $o->get_id() ) );
$id = $o->get_id(); $o = wc_get_order( $id ); $total0 = (float) $o->get_total();
echo "Pedido de ensayo #{$o->get_order_number()} (id {$id}), total {$total0}.\n";
$ids = array( $id );
try {
	$items = array(); foreach ( $o->get_items() as $iid => $it ) { $items[ (int) $it->get_product_id() ] = (int) $iid; }
	// Para la guía (capturas): lo que ve la app de la gestora, antes de tocar nada.
	$cap = array( 'pedidos' => c_rest( $G, 'GET', '/gestora/pedidos' )['data'], 'detalle' => c_rest( $G, 'GET', '/gestora/pedido/' . $id )['data'] );
	$hist = new ReflectionMethod( 'CVD_Portal', 'gestora_history' ); $hist->setAccessible( true ); wp_set_current_user( $G );
	$cap['tabla'] = $hist->invoke( null, array( wc_get_order( $id ) ) );
	file_put_contents( getenv( 'HOME' ) . '/tmp/gp-capture-1103.json', wp_json_encode( $cap, JSON_UNESCAPED_UNICODE ) );
	$r = c_rest( $G, 'GET', '/gestora/pedidos' );
	$row = array_values( array_filter( $r['data']['orders'] ?? array(), static fn( $x ) => (int) $x['id'] === $id ) )[0] ?? null;
	c_ok( 'GET /gestora/pedidos: el ensayo sale con Cambiar y Cancelar', $row && '' === $row['can']['cambiar'] && '' === $row['can']['cancelar'], $r );
	$r = c_rest( $G, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'requestId' => 'vivo-' . wp_generate_uuid4(), 'lines' => array( array( 'itemId' => $items[ $A ], 'qty' => 1 ) ) ) );
	$o = wc_get_order( $id );
	c_ok( 'POST cambiar (bajar 2 → 1): ' . $r['msg'], 200 === $r['status'] && (float) $o->get_total() < $total0, $r );
	$r = c_rest( $G, 'POST', "/gestora/pedido/{$id}/cambiar", array( 'lines' => array( array( 'itemId' => $items[ $A ], 'qty' => 3 ) ) ) );
	c_ok( 'POST cambiar (subir): no → ' . $r['msg'], 422 === $r['status'], $r );
	$r = c_rest( $G, 'POST', "/gestora/pedido/{$id}/cancelar", array( 'reason' => 'comprobación en vivo (ensayo)' ) );
	c_ok( 'POST cancelar: ' . $r['msg'], 200 === $r['status'] && wc_get_order( $id )->has_status( 'cancelled' ), $r );
	$r = c_rest( $G, 'POST', "/gestora/pedido/{$id}/recuperar", array( 'reason' => 'comprobación en vivo (ensayo)' ) );
	c_ok( 'POST recuperar: ' . $r['msg'], 200 === $r['status'] && wc_get_order( $id )->has_status( 'on-hold' ), $r );
	$before = (float) wc_get_order( $id )->get_total();
	$r = c_rest( $admin, 'POST', "/sales/{$id}/split", array( 'requestId' => 'vivo-s-' . wp_generate_uuid4(), 'lines' => array( array( 'itemId' => $items[ $B ], 'qty' => 1 ) ), 'to' => array( 'firstName' => 'PRUEBA Katy', 'lastName' => 'no enviar', 'address' => 'Calle de prueba #2', 'locality' => 'La Palma', 'deliveryWindow' => '' ) ) );
	$bid = (int) ( $r['data']['newOrderId'] ?? 0 ); if ( $bid ) { $ids[] = $bid; }
	$b = $bid ? wc_get_order( $bid ) : null;
	c_ok( 'POST /sales/{id}/split (tienda): ' . $r['msg'], 200 === $r['status'] && $b && abs( (float) wc_get_order( $id )->get_total() + (float) $b->get_total() - $before ) < 0.01 && 'claude-1103-vivo' === $b->get_meta( '_cvd_ensayo', true ) && '' === $b->get_billing_phone(), $r );
	// Pedidos reales: solo lectura.
	$r = c_rest( $G, 'GET', '/gestora/pedido/6062' );
	c_ok( 'pedido real #6062 visto por otra gestora: 404 «' . $r['msg'] . '»', 404 === $r['status'], $r );
	$r = c_rest( $G, 'POST', '/gestora/pedido/6062/cancelar', array( 'reason' => 'no es mío' ) );
	c_ok( 'pedido real #6062: otra gestora no lo cancela (404, no se toca)', 404 === $r['status'] && ! wc_get_order( 6062 )->has_status( 'cancelled' ), $r );
	$owner = absint( wc_get_order( 6062 )->get_meta( '_cvd_owner_user_id', true ) );
	$r = c_rest( $owner, 'GET', '/gestora/pedido/6062' );
	c_ok( 'pedido real #6062 visto por su dueña (Nadielys, solo lectura): «' . ( $r['data']['can']['cambiar'] ?? $r['msg'] ) . '»', 200 === $r['status'] && '' !== ( $r['data']['can']['cambiar'] ?? '' ), $r['data']['can'] ?? $r );
	$r = c_rest( $owner, 'GET', '/gestora/pedido/6102' );
	echo '      #6102 para su dueña (solo lectura): cambiar=«' . ( $r['data']['can']['cambiar'] ?? '?' ) . '» cancelar=«' . ( $r['data']['can']['cancelar'] ?? '?' ) . '» estado reparto=' . wc_get_order( 6102 )->get_meta( '_cvd_delivery_status', true ) . "\n";
} finally {
	// Limpieza: cancelar y a la papelera.
	foreach ( $ids as $x ) {
		$ord = wc_get_order( $x ); if ( ! $ord ) { continue; }
		if ( ! $ord->has_status( 'cancelled' ) ) { CVD_Order_Edit::cancel( $x, 'Ensayo de comprobación 3.14.116 terminado', $admin, 'ventas' ); }
		$ord = wc_get_order( $x ); $ord->delete( false );
		echo "Pedido de ensayo {$x}: " . ( wc_get_order( $x ) ? wc_get_order( $x )->get_status() : 'borrado' ) . "\n";
	}
	wp_set_current_user( 0 );
}
echo 'Salidas bloqueadas: ' . count( $GLOBALS['c_out'] ) . ( $GLOBALS['c_out'] ? ' (' . implode( '; ', array_unique( $GLOBALS['c_out'] ) ) . ')' : '' ) . "\n";
echo $GLOBALS['c_fails'] ? "{$GLOBALS['c_fails']} de {$GLOBALS['c_n']} FALLARON\n" : "OK: {$GLOBALS['c_n']} comprobaciones en vivo.\n";
