<?php
/**
 * Pa'llá transporte (5-nov-2026): prueba del flujo completo con datos inventados, sin red (CVD_PV_SIN_RED).
 * Correr: wp eval-file /var/www/html/integration-tests/pv-flujo.php
 * Precio (D140), pedir → ofertas → elegir → llegué → recogí (código) → terminé → cobrado; 1.ª oferta; envío con código
 * y foto; soltar; cancelar; caducar; comisión y tope; señales a ntfy.
 */
if ( ! defined( 'CVD_PV_SIN_RED' ) ) { define( 'CVD_PV_SIN_RED', true ); }

$GLOBALS['pv_n'] = 0;
function pv_ok( $cond, string $que ): void {
	if ( ! $cond ) { fwrite( STDERR, "FALLA: {$que}\n" ); exit( 1 ); }
	$GLOBALS['pv_n']++;
}
function pv_req( string $m, string $path, array $p = array(), array $h = array() ): array {
	$r = new WP_REST_Request( $m, '/casa-viva/v1/pv/' . $path );
	foreach ( $h as $k => $v ) { $r->set_header( $k, $v ); }
	if ( 'GET' === $m ) { $r->set_query_params( $p ); } else { $r->set_body_params( $p ); }
	$res = rest_do_request( $r );
	$d = $res->get_data();
	if ( is_wp_error( $d ) ) { $d = array( 'ok' => false, 'code' => $d->get_error_code(), 'message' => $d->get_error_message() ); }
	return array( $res->get_status(), is_array( $d ) ? $d : array() );
}

global $wpdb;
delete_option( 'cvd_tc_db' );
CVD_Triciclub_Db::install();
pv_ok( (int) get_option( 'cvd_tc_db' ) >= 4, 'tablas versión 4' );
pv_ok( $wpdb->get_var( "SHOW TABLES LIKE '" . CVD_Triciclub_Db::t( 'ofertas' ) . "'" ), 'tabla ofertas' );

// ---------- Precio (D140)
pv_ok( 3000 === CVD_Pv_Tarifa::justo( 'tric_electrico', 7000, false ), 'eléctrico 7 km = 3.000' );
pv_ok( 3600 === CVD_Pv_Tarifa::justo( 'tric_electrico', 7000, true ), 'noche +20 %' );
pv_ok( 1500 === CVD_Pv_Tarifa::justo( 'tric_electrico', 900, false ), 'mínimo con km incluidos' );
pv_ok( array( 2400, 4500 ) === CVD_Pv_Tarifa::limites( 'tric_electrico', 3000, false ), 'límites −20/+50' );
pv_ok( array( 1500, 2250 ) === CVD_Pv_Tarifa::limites( 'tric_electrico', 1500, false ), 'nunca bajo el mínimo' );
pv_ok( 4000 === CVD_Pv_Tarifa::justo( 'taxi_basico', 7000, false ), 'taxi básico 7 km = 4.000' );
pv_ok( 1650 === CVD_Pv_Tarifa::contra( 1500, 10 ) && 1950 === CVD_Pv_Tarifa::contra( 1500, 30 ), 'contraoferta +10/+30' );
pv_ok( 150 === CVD_Pv_Tarifa::espera( '2026-11-05 10:00:00', '2026-11-05 10:07:30' ), 'espera 5 min gratis + 50/min' );
pv_ok( 0 === CVD_Pv_Tarifa::espera( '2026-11-05 10:00:00', '2026-11-05 10:04:59' ), 'espera gratis' );
pv_ok( 2400 === CVD_Pv_Tarifa::ajustar( 100, array( 2400, 4500 ) ) && 4500 === CVD_Pv_Tarifa::ajustar( 99999, array( 2400, 4500 ) ), 'ajustar a los límites' );
pv_ok( CVD_Pv_Tarifa::noche( strtotime( '2026-11-05 03:30:00 UTC' ) ) && ! CVD_Pv_Tarifa::noche( strtotime( '2026-11-05 16:00:00 UTC' ) ), 'noche en hora de Cuba' );

// ---------- Datos de prueba
update_option( 'cvd_palla_ntfy_url', 'https://ntfy.invalid' );
update_option( 'cvd_palla_ntfy_token', 'prueba' );
update_option( 'cvd_pv_comision_pct', 0 );
update_option( 'cvd_pv_tope', 2000 );
$O = array( 23.14031, -82.38105 ); // Calle N e/ 23 y 25
$D = array( 23.13909, -82.38293 ); // Coppelia
$mk = static function ( string $tel, string $nombre, string $tipo, string $serv, float $dlat ) use ( $wpdb, $O ): int {
	$c = CVD_Triciclub_Alta::cuenta( $tel, $nombre );
	$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'estado' => 'aprobado', 'tipo' => $tipo, 'servicios' => $serv, 'plazas' => 4, 'disponible' => 1, 'lat' => $O[0] + $dlat, 'lng' => $O[1], 'ubicado_at' => CVD_Triciclub_Db::now(), 'transferencia' => '9205 0000 1111 2222' ), array( 'user_id' => $c['user_id'] ) );
	return (int) $c['user_id'];
};
$d1 = $mk( '5350000201', 'Yoandri Rodríguez', 'electrico', 'viaje,paquete', 0.004 );
$d2 = $mk( '5350000202', 'Dayana Castro', 'electrico', 'viaje', 0.002 );
$d3 = $mk( '5350000203', 'Alexis Martínez', 'mensajero', 'paquete', 0.003 );
$d4 = $mk( '5350000204', 'Osmany Lugo', 'taxi_basico', 'viaje', 0.003 );
update_option( 'cvd_tc_testers', array( $d1, $d2, $d3, $d4 ) );
update_option( 'cvd_tc_clientes_prueba', array( '5350000101' ) );
$cli = CVD_Triciclub::crear_cliente( '5350000101', 'Laura Méndez', true );
$H = array( 'X-TC-Token' => CVD_Triciclub::token_cliente( $cli ) );
$P = static fn( int $uid ): array => array( 'X-Palla-Token' => CVD_Palla::token_for( $uid ) );

// ---------- Apagado: nadie entra
update_option( 'cvd_pv', 'no' );
list( $s ) = pv_req( 'GET', 'yo', array(), $H );
pv_ok( 403 === $s, 'apagado: cliente fuera' );
update_option( 'cvd_pv', 'prueba' );
list( $s ) = pv_req( 'POST', 'cliente/codigo', array( 'telefono' => '5359999999' ) );
pv_ok( 403 === $s, 'prueba: teléfono que no es de prueba fuera' );
list( $s, $d ) = pv_req( 'POST', 'cliente/codigo', array( 'telefono' => '5350000101', 'canal' => 'sms' ) );
pv_ok( 200 === $s && 'sms' === $d['canal'], 'código por SMS en cola' );
$cod = $wpdb->get_row( 'SELECT * FROM ' . CVD_Triciclub_Db::t( 'codigos' ) . " WHERE telefono='5350000101' ORDER BY id DESC LIMIT 1" );
pv_ok( 4 === strlen( $cod->codigo ) && 'sms' === $cod->canal && 'pv' === $cod->app, 'código de 4 cifras, sms, pv' );
$bot = CVD_Triciclub::bot_codigos( new WP_REST_Request( 'GET' ) )->get_data();
pv_ok( ! in_array( (int) $cod->id, array_column( $bot['codigos'], 'id' ), true ), 'el de SMS no va por WhatsApp' );
$sms = CVD_Pv_Api::sms_pendientes( new WP_REST_Request( 'GET' ) )->get_data();
pv_ok( false !== strpos( $sms['sms'][0]['text'] ?? '', "Pa'llá: tu código es " . $cod->codigo ), 'texto del SMS' );
list( $s, $d ) = pv_req( 'POST', 'cliente/entrar', array( 'telefono' => '5350000101', 'codigo' => $cod->codigo, 'nombre' => 'Laura Méndez' ) );
pv_ok( 200 === $s && ! empty( $d['token'] ), 'entrar con el código' );

// ---------- Choferes de prueba (otro chofer no probador no entra)
list( $s, $d ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d1 ) );
pv_ok( 200 === $s && 'aprobado' === $d['estado'] && 'tric_electrico' === $d['ficha']['clase'], 'chofer/hoy del chofer 1' );

// ---------- Cotizar
list( $s, $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'tric', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ), $H );
pv_ok( 200 === $s && 4 === count( $q['clases'] ), 'cotizar triciclo: 4 clases' );
$el = array_values( array_filter( $q['clases'], static fn( $c ) => 'tric_electrico' === $c['clase'] ) )[0];
pv_ok( 2 === $el['libres'] && $el['justo'] >= 1500 && $el['min'] >= 1500, 'eléctrico: 2 libres y nunca bajo el mínimo' );

// ---------- Pedir (con oferta por debajo del mínimo → se ajusta)
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c1', 'firma' => $el['firma'], 'precio' => 100, 'origen_texto' => 'Calle N e/ 23 y 25, Vedado', 'destino_texto' => 'Coppelia', 'forma_pago' => 'efectivo' ), $H );
pv_ok( 200 === $s && 'buscando' === $d['viaje']['estado'] && $d['viaje']['oferta'] === $el['min'], 'pedido buscando con la oferta al mínimo' );
$V = $d['viaje'];
pv_ok( 0 === strpos( $V['canal'], 'palla-v' ), 'canal del viaje' );
list( $s ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c1b', 'firma' => $el['firma'], 'precio' => 2000 ), $H );
pv_ok( 409 === $s, 'no deja dos viajes a la vez' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c1', 'firma' => $el['firma'], 'precio' => 2000 ), $H );
pv_ok( 200 === $s && ! empty( $d['repetido'] ), 'repetir el pedido (sin señal) no crea otro' );

// ---------- Choferes ven el pedido; el taxi y el mensajero no
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d1 ) );
pv_ok( 1 === count( $h1['pedidos'] ) && $h1['pedidos'][0]['oferta'] === $V['oferta'], 'el chofer 1 ve el pedido' );
pv_ok( ! isset( $h1['pedidos'][0]['cliente']['telefono'] ), 'sin el teléfono del cliente antes de cogerlo' );
list( , $h4 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d4 ) );
pv_ok( 0 === count( $h4['pedidos'] ), 'el taxi no ve un pedido de triciclo' );

// ---------- Ofertas
$id = (int) $h1['pedidos'][0]['id'];
list( $s, $o1 ) = pv_req( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 10 ), $P( $d1 ) );
pv_ok( 200 === $s && $o1['oferta']['precio'] === CVD_Pv_Tarifa::contra( $V['oferta'], 10 ), 'chofer 1 pide +10 %' );
list( $s, $o2 ) = pv_req( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 0 ), $P( $d2 ) );
pv_ok( 200 === $s && $o2['oferta']['precio'] === $V['oferta'] && false === $o2['elegido'], 'chofer 2 acepta (sin 1.ª oferta no se asigna solo)' );
list( , $vv ) = pv_req( 'GET', 'viajes/' . $V['uuid'], array( 'rev' => $V['rev'] ), $H );
pv_ok( 2 === count( $vv['viaje']['ofertas'] ) && $vv['viaje']['ofertas'][0]['precio'] === $V['oferta'], 'el cliente ve 2 ofertas, la más barata primero' );
pv_ok( ! isset( $vv['viaje']['ofertas'][0]['chofer']['telefono'] ), 'sin teléfono del chofer en la oferta' );

// ---------- El cliente sube 10 % (sigue buscando)
list( $s, $sb ) = pv_req( 'POST', 'viajes/' . $V['uuid'] . '/precio', array( 'precio' => $V['oferta'] + 150 ), $H );
pv_ok( 200 === $s && $sb['viaje']['oferta'] === $V['oferta'] + 150, 'subir el precio' );

// ---------- Elegir
list( $s, $e ) = pv_req( 'POST', 'viajes/' . $V['uuid'] . '/elegir', array( 'oferta' => $o2['oferta']['id'] ), $H );
pv_ok( 200 === $s && 'asignado' === $e['viaje']['estado'] && $e['viaje']['precio'] === $V['oferta'] && ! empty( $e['viaje']['chofer']['nombre'] ), 'eligió al chofer 2 a su precio (fijo)' );
pv_ok( 'Dayana C.' === $e['viaje']['chofer']['nombre'], 'nombre corto del chofer' );
list( $s ) = pv_req( 'POST', 'viajes/' . $V['uuid'] . '/elegir', array( 'oferta' => $o2['oferta']['id'] ), $H );
pv_ok( 200 === $s, 'elegir dos veces no rompe nada' );
list( $s ) = pv_req( 'POST', 'viajes/' . $V['uuid'] . '/elegir', array( 'oferta' => $o1['oferta']['id'] ), $H );
pv_ok( 409 === $s, 'no se puede elegir otra oferta después' );
pv_ok( 'perdio' === $wpdb->get_var( $wpdb->prepare( 'SELECT estado FROM ' . CVD_Triciclub_Db::t( 'ofertas' ) . ' WHERE id=%d', $o1['oferta']['id'] ) ), 'la oferta del chofer 1 perdió' );
list( $s ) = pv_req( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 0 ), $P( $d1 ) );
pv_ok( 409 === $s, 'ya no se puede ofertar' );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d2 ) );
pv_ok( $h2['activo'] && $h2['activo']['cliente']['telefono'] === '5350000101', 'el chofer elegido ve su viaje y el teléfono' );

// ---------- Viaje
$vid = (int) $h2['activo']['id'];
list( $s ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'termine' ), $P( $d2 ) );
pv_ok( 409 === $s, 'no se termina sin recoger' );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'llegue' ), $P( $d2 ) );
pv_ok( 200 === $s, 'llegué' );
$wpdb->update( CVD_Triciclub_Db::t( 'viajes' ), array( 'llego_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * 60 ) ), array( 'id' => $vid ) ); // esperó 8 min
list( $s ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'recogi', 'codigo' => '0000' === $e['viaje']['codigo'] ? '1111' : '0000' ), $P( $d2 ) );
pv_ok( 400 === $s, 'código malo' );
list( $s, $rg ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'recogi', 'codigo' => $e['viaje']['codigo'] ), $P( $d2 ) );
pv_ok( 200 === $s && 150 === $rg['viaje']['espera'] && $rg['viaje']['total'] === $V['oferta'] + 150, 'recogí: 3 min de espera = 150 CUP' );
list( $s, $tm ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'termine' ), $P( $d2 ) );
pv_ok( 200 === $s && 'terminado' === $tm['viaje']['estado'], 'terminé' );
pv_ok( 0 === CVD_Pv_Cuenta::saldo( $d2 ), 'comisión 0 % en el piloto' );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$vid}/estado", array( 'accion' => 'cobrado' ), $P( $d2 ) );
pv_ok( 200 === $s, 'cobrado' );
list( $s ) = pv_req( 'POST', 'viajes/' . $V['uuid'] . '/valorar', array( 'estrellas' => 5, 'etiquetas' => array( 'puntual' ), 'propina' => 50 ), $H );
pv_ok( 200 === $s && 5.0 === (float) CVD_Triciclub::chofer_de( $d2 )->nota_media, 'valoración del cliente' );

// ---------- «Coger la 1.ª oferta»
list( , $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'tric', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ), $H );
$el = array_values( array_filter( $q['clases'], static fn( $c ) => 'tric_electrico' === $c['clase'] ) )[0];
list( , $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c2', 'firma' => $el['firma'], 'precio' => $el['justo'], 'primera' => 1, 'forma_pago' => 'transferencia' ), $H );
$V2 = $d['viaje'];
list( $s, $o ) = pv_req( 'POST', 'chofer/pedidos/' . $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE uuid=%s', $V2['uuid'] ) ) . '/ofertar', array( 'pct' => 20 ), $P( $d1 ) );
pv_ok( 200 === $s && false === $o['elegido'], 'una contraoferta no se coge sola' );
$id2 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE uuid=%s', $V2['uuid'] ) );
list( $s, $o ) = pv_req( 'POST', "chofer/pedidos/{$id2}/ofertar", array( 'pct' => 0 ), $P( $d2 ) );
pv_ok( 200 === $s && true === $o['elegido'], '1.ª oferta «Acepto» se queda el viaje' );
list( , $vv ) = pv_req( 'GET', 'viajes/' . $V2['uuid'], array(), $H );
pv_ok( 'asignado' === $vv['viaje']['estado'] && ! empty( $vv['viaje']['chofer']['transferencia'] ), 'pago por transferencia: el cliente ve la tarjeta' );

// ---------- Soltar: vuelve a buscar
list( $s, $so ) = pv_req( 'POST', "chofer/viajes/{$id2}/estado", array( 'accion' => 'suelto', 'motivo' => 'se me pinchó' ), $P( $d2 ) );
list( , $vv ) = pv_req( 'GET', 'viajes/' . $V2['uuid'], array(), $H );
pv_ok( 200 === $s && 'buscando' === $vv['viaje']['estado'] && null === $vv['viaje']['chofer'], 'soltar: vuelve a buscar chofer' );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d2 ) );
pv_ok( ! in_array( $id2, array_column( $h2['pedidos'], 'id' ), true ), 'quien lo soltó ya no lo ve' );

// ---------- Cancelar
list( $s, $cc ) = pv_req( 'POST', 'viajes/' . $V2['uuid'] . '/cancelar', array(), $H );
pv_ok( 200 === $s && 'cancelado' === $cc['viaje']['estado'], 'el cliente cancela' );

// ---------- Envío con código y foto
list( , $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'mens', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1], 'tam' => 'mano' ), $H );
pv_ok( 1 === count( $q['clases'] ) && 'mensajero' === $q['clases'][0]['clase'] && $q['clases'][0]['libres'] >= 1, 'cotizar envío' );
list( $s ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c3', 'firma' => $q['clases'][0]['firma'], 'precio' => $q['clases'][0]['justo'], 'envio' => array( 'que' => 'Llaves', 'recibe_nombre' => 'Marta' ) ), $H );
pv_ok( 400 === $s, 'envío sin teléfono de quien recibe' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c3', 'firma' => $q['clases'][0]['firma'], 'precio' => $q['clases'][0]['justo'], 'envio' => array( 'que' => 'Llaves y un sobre', 'recibe_nombre' => 'Marta', 'recibe_telefono' => '53124567', 'cobrar_cup' => 1500 ) ), $H );
pv_ok( 200 === $s && 4 === strlen( $d['viaje']['envio']['codigo'] ) && '' === $d['viaje']['codigo'], 'envío con código de entrega' );
$V3 = $d['viaje'];
$id3 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE uuid=%s', $V3['uuid'] ) );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
pv_ok( in_array( $id3, array_column( $h3['pedidos'], 'id' ), true ), 'el mensajero ve el envío' );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d2 ) );
pv_ok( ! in_array( $id3, array_column( $h2['pedidos'], 'id' ), true ), 'el triciclo sin «envíos» no lo ve' );
list( , $o ) = pv_req( 'POST', "chofer/pedidos/{$id3}/ofertar", array( 'pct' => 0 ), $P( $d3 ) );
pv_req( 'POST', 'viajes/' . $V3['uuid'] . '/elegir', array( 'oferta' => $o['oferta']['id'] ), $H );
pv_req( 'POST', "chofer/viajes/{$id3}/estado", array( 'accion' => 'llegue' ), $P( $d3 ) );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$id3}/estado", array( 'accion' => 'recogi' ), $P( $d3 ) );
pv_ok( 200 === $s, 'envío: recoger sin código del cliente' );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id3}/estado", array( 'accion' => 'termine', 'codigo' => $V3['envio']['codigo'] ), $P( $d3 ) );
pv_ok( 400 === $s && 'foto' === $x['code'], 'no se entrega sin foto' );
$wpdb->update( CVD_Triciclub_Db::t( 'viajes' ), array( 'entrega_foto' => 'e-prueba.jpg' ), array( 'id' => $id3 ) );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id3}/estado", array( 'accion' => 'termine', 'codigo' => '0000' === $V3['envio']['codigo'] ? '1111' : '0000' ), $P( $d3 ) );
pv_ok( 400 === $s && 'codigo' === $x['code'], 'código de entrega malo' );

// ---------- Comisión 8 % y tope: se pausan solo los viajes en efectivo
update_option( 'cvd_pv_comision_pct', 8 );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id3}/estado", array( 'accion' => 'termine', 'codigo' => $V3['envio']['codigo'] ), $P( $d3 ) );
pv_ok( 200 === $s && 'terminado' === $x['viaje']['estado'], 'entregado con código y foto' );
$com = (int) round( $V3['oferta'] * 0.08 );
pv_ok( $com === CVD_Pv_Cuenta::saldo( $d3 ), 'comisión 8 % apuntada' );
update_option( 'cvd_pv_tope', 50 );
pv_ok( CVD_Pv_Cuenta::pausa_efectivo( $d3, 'mens' ), 'tope pasado: pausa de efectivo' );
list( , $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'mens', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1], 'tam' => 'mano' ), $H );
list( , $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'c4', 'firma' => $q['clases'][0]['firma'], 'precio' => $q['clases'][0]['justo'], 'forma_pago' => 'efectivo', 'envio' => array( 'que' => 'Sobre', 'recibe_nombre' => 'Ana', 'recibe_telefono' => '53124567' ) ), $H );
$id4 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE uuid=%s', $d['viaje']['uuid'] ) );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
pv_ok( ! in_array( $id4, array_column( $h3['pedidos'], 'id' ), true ) && $h3['cuenta']['pausa'], 'con deuda al tope no ve envíos en efectivo' );
list( $s, $pg ) = pv_req( 'POST', 'chofer/pago', array( 'cup' => $com, 'ref' => 'TM123456' ), $P( $d3 ) );
pv_ok( 200 === $s && 0 === $pg['cuenta']['debe'] && ! $pg['cuenta']['pausa'], 'pagó: deuda 0 y sin pausa' );
$pago = CVD_Pv_Cuenta::pendientes()[0];
CVD_Pv_Cuenta::revisar_pago( (int) $pago->id, false, 1 );
pv_ok( $com === CVD_Pv_Cuenta::saldo( $d3 ), 'pago rechazado: vuelve la deuda' );

// ---------- Caduca a los 4 min sin elegir
$wpdb->update( CVD_Triciclub_Db::t( 'viajes' ), array( 'busca_desde' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ), array( 'id' => $id4 ) );
list( , $vv ) = pv_req( 'GET', 'viajes/' . $d['viaje']['uuid'], array(), $H );
pv_ok( 'sin_chofer' === $vv['viaje']['estado'], 'sin chofer a los 4 min' );
list( $s, $sb ) = pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/precio', array( 'precio' => $q['clases'][0]['justo'] + 100 ), $H );
pv_ok( 200 === $s && 'buscando' === $sb['viaje']['estado'], 'subir el precio vuelve a buscar' );

// ---------- Señales a ntfy (sin red): canal del viaje y tema callado del chofer, nunca el de la app Android
$temas = array_unique( array_map( static fn( $e ) => preg_replace( '/-[a-f0-9]{12}$/', '', $e[0] ), CVD_Pv_Canal::$enviados ) );
pv_ok( count( CVD_Pv_Canal::$enviados ) > 5, 'se mandaron señales' );
pv_ok( ! array_filter( $temas, static fn( $t ) => ! preg_match( '/^palla-[vc]\d+$/', $t ) ), 'solo temas palla-v / palla-c' );

// ---------- Viajes del cliente y semana del chofer
list( , $mv ) = pv_req( 'GET', 'viajes', array(), $H );
pv_ok( count( $mv['viajes'] ) >= 4, 'mis viajes' );
list( , $se ) = pv_req( 'GET', 'chofer/semana', array(), $P( $d2 ) );
pv_ok( 1 === $se['viajes'] && $se['total'] === $V['oferta'] + 150, 'semana del chofer' );

// ---------- Limpieza
foreach ( array( $d1, $d2, $d3, $d4 ) as $u ) { $wpdb->delete( CVD_Triciclub_Db::t( 'choferes' ), array( 'user_id' => $u ) ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u ); }
foreach ( array( 'viajes' => 'cliente_id', 'clientes' => 'id' ) as $tb => $col ) { $wpdb->delete( CVD_Triciclub_Db::t( $tb ), array( $col => (int) $cli->id ) ); }
echo "OK Pa'llá transporte: " . $GLOBALS['pv_n'] . " comprobaciones\n";
