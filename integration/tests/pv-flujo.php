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

// ---------- Ruta: desvío del chofer (si se aparta > 120 m, el servidor rehace la ruta por calles)
$L = array( array( 23.14, -82.38 ), array( 23.14, -82.37 ) );
pv_ok( CVD_Pv_Api::lejos_de_linea( $L, 23.14, -82.375 ) < 1, 'sobre la ruta: 0 m' );
$d = CVD_Pv_Api::lejos_de_linea( $L, 23.141, -82.375 );
pv_ok( $d > 100 && $d < 120, 'a una cuadra de la ruta: ~110 m (' . round( $d ) . ')' );
pv_ok( CVD_Pv_Api::lejos_de_linea( $L, 23.143, -82.375 ) > 120, 'desviado: más de 120 m' );

// ---------- Ruta de bici más larga que la de carro (revisión 7-nov: en la web, 550 m del chofer a la recogida salían 5 km
// por el motor de bici, con una vuelta enorme en el mapa). Se usa la más corta de las dos; si la de bici es más corta, la de bici.
$osrm = static function ( float $bici, float $carro ): callable {
	return static function ( $pre, $args, $url ) use ( $bici, $carro ) {
		if ( false === strpos( (string) $url, '/route/v1/' ) ) { return $pre; }
		$m = false !== strpos( (string) $url, '/cycling/' ) ? $bici : $carro;
		$geo = array( 'coordinates' => array( array( -82.381, 23.140 ), array( -82.380, 23.141 ), array( -82.379, 23.142 ) ) );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'code' => 'Ok', 'routes' => array( array( 'distance' => $m, 'geometry' => $geo ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
};
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%cvd_pv_rb_%' OR option_name LIKE '%transient%cvd_tc_ruta2_%'" );
$f = $osrm( 5074, 1074 );
add_filter( 'pre_http_request', $f, 10, 3 );
$t = CVD_Pv_Api::trayecto( 'cycling', 23.1447, -82.3787, 23.14031, -82.38105 );
remove_filter( 'pre_http_request', $f, 10 );
pv_ok( 1074 === $t['metros'] && 'calles' === $t['como'], 'ruta de bici más larga que la de carro: se usa la de carro (' . $t['metros'] . ' m)' );
$f = $osrm( 800, 1100 );
add_filter( 'pre_http_request', $f, 10, 3 );
$t = CVD_Pv_Api::trayecto( 'cycling', 23.1300, -82.3900, 23.1250, -82.3850 );
remove_filter( 'pre_http_request', $f, 10 );
pv_ok( 800 === $t['metros'], 'ruta de bici más corta: se queda la de bici (' . $t['metros'] . ' m)' );

// ---------- 10-nov (Ernesto): el alfiler se pega a la calle (no se queda dentro de un edificio)
$calle = static function ( $pre, $args, $url ) {
	if ( false === strpos( (string) $url, '/route/v1/driving/' ) || false === strpos( (string) $url, 'overview=false' ) ) { return $pre; }
	$w = array( 'location' => array( -82.381382, 23.140689 ), 'name' => 'Calle N', 'distance' => 15.6 );
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'code' => 'Ok', 'routes' => array(), 'waypoints' => array( $w, $w ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%cvd_pv_calle_%'" );
add_filter( 'pre_http_request', $calle, 10, 3 );
$pc = CVD_Pv_Api::pegar_calle( 23.14060, -82.38150 );
remove_filter( 'pre_http_request', $calle, 10 );
pv_ok( abs( $pc[0] - 23.140689 ) < 1e-6 && abs( $pc[1] + 82.381382 ) < 1e-6 && 'Calle N' === $pc[2] && true === $pc[3], 'alfiler: se pega a la calle más cercana (Calle N, 15 m)' );
pv_ok( CVD_Pv_Api::pegar_calle( 23.14060, -82.38150 ) === $pc, 'alfiler: la segunda vez sale de la caché' );

// ---------- Mapa (revisión 7-nov): MapLibre suma la padding guardada por el jumpTo del inicio a la de fitBounds y en Viajar
// la ruta no cabía. mapa.js debe borrar la padding guardada justo antes de encuadrar.
$mjs = (string) file_get_contents( CVD_DIR . 'assets/pv/mapa.js' );
pv_ok( (bool) preg_match( '/setPadding\(\s*\{\s*top:\s*0,\s*bottom:\s*0,\s*left:\s*0,\s*right:\s*0\s*\}\s*\);\s*map\.fitBounds\(/', $mjs ), 'mapa: borra la padding guardada antes de encuadrar la ruta' );

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
// 10-nov: la app Android «Pa'llá Chofer» pide su tema de avisos (el mismo donde CVD_Palla_Push::avisar le manda los pedidos)
list( $s, $ap ) = pv_req( 'GET', 'chofer/app', array(), $P( $d1 ) );
pv_ok( 200 === $s && true === $ap['ok'] && 0 === strpos( $ap['topic'], 'palla-' . $d1 . '-' ) && $ap['topic'] === get_user_meta( $d1, CVD_Palla_Push::NTFY_TOPIC, true ), 'app del chofer: su tema de avisos' );
list( , $ap2 ) = pv_req( 'GET', 'chofer/app', array(), $P( $d1 ) );
pv_ok( $ap2['topic'] === $ap['topic'], 'app del chofer: el tema no cambia' );

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
// 10-nov: el aviso del pedido lleva «v<id>» (el botón Aceptar del aviso de la app manda «Acepto» a ese pedido)
$ev = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CVD_Triciclub_Db::t( 'viajes' ) . ' WHERE id=%d', $id ) );
pv_ok( 1 === (int) $ev, 'el pedido que ve el chofer existe (para el Aceptar del aviso)' );
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

// ====================================================================================== v3 (D142)
$TV = CVD_Triciclub_Db::t( 'viajes' );
pv_ok( 5 <= (int) get_option( 'cvd_tc_db' ) && $wpdb->get_var( "SHOW COLUMNS FROM {$TV} LIKE 'adelanto_cup'" ) && $wpdb->get_var( 'SHOW COLUMNS FROM ' . CVD_Triciclub_Db::t( 'choferes' ) . " LIKE 'adelanta'" ), 'v3: tablas versión 5 (adelanto, adelanta)' );
pv_ok( 'viaje' === $wpdb->get_var( $wpdb->prepare( "SELECT servicio FROM {$TV} WHERE uuid=%s", $V['uuid'] ) ) && 'paquete' === $wpdb->get_var( $wpdb->prepare( "SELECT servicio FROM {$TV} WHERE uuid=%s", $V3['uuid'] ) ), 'v3: las llamadas viejas guardan viaje / paquete' );
pv_ok( 'Bicitaxi' === CVD_Pv_Tarifa::CLASES['tric_bici']['nombre'] && 'Taxi XL' === CVD_Pv_Tarifa::CLASES['taxi_xl']['nombre'], 'v3: nombres Bicitaxi y Taxi XL' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array(), $H ); // libera al cliente (el envío del tope)

// ---------- Viajar: las 7 clases de una vez, cada una con su ruta, de la más barata a la más cara
list( $s, $qv ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'viajar', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ), $H );
$cl = array_column( $qv['clases'], 'clase' );
pv_ok( 200 === $s && 7 === count( $cl ) && ! array_diff( CVD_Pv_Tarifa::VIAJAR, $cl ), 'v3: cotizar viajar da las 7 clases' );
$js = array_column( $qv['clases'], 'justo' );
$ord = $js; sort( $ord );
pv_ok( $js === $ord, 'v3: de la más barata a la más cara (' . implode( ',', $js ) . ')' );
$pf = array_column( $qv['clases'], 'perfil', 'clase' );
pv_ok( 'driving' === $pf['taxi_basico'] && 'driving' === $pf['taxi_xl'] && 'cycling' === $pf['tric_electrico'] && 'cycling' === $pf['moto'], 'v3: taxi por calles de carro, el resto por el camino de bici' );
pv_ok( array_key_exists( 'driving', $qv['lineas'] ) && array_key_exists( 'cycling', $qv['lineas'] ) && 'tric_electrico' === $qv['ultima'], 'v3: rutas de los dos perfiles y última clase usada' );
$byc = array_column( $qv['clases'], null, 'clase' );
pv_ok( null === $byc['taxi_xl']['llega'] && 0 === $byc['taxi_xl']['libres'] && 2 === $byc['tric_electrico']['libres'], 'v3: sin nadie cerca, «llega» vacío' );
pv_ok( $byc['taxi_basico']['min'] >= 3000, 'v3: el taxi básico nunca baja de 3.000' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'v3a', 'firma' => $byc['taxi_basico']['firma'], 'precio' => $byc['taxi_basico']['justo'], 'origen_texto' => 'Calle N', 'destino_texto' => 'Coppelia' ), $H );
pv_ok( 200 === $s && 'viaje' === $d['viaje']['servicio'] && 'taxi_basico' === $d['viaje']['clase'], 'v3: pedir desde Viajar guarda «viaje»' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array(), $H );
list( , $qv ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'viajar', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ), $H );
pv_ok( 'tric_electrico' === $qv['ultima'], 'v3: un viaje cancelado no cuenta como el último usado' );

// ---------- Carga: ayudante +500 en justo, mínimo y máximo; el peso no cambia el precio
$d5 = $mk( '5350000205', 'Reinier Torres', 'carga', 'viaje,paquete,carga', 0.003 );
update_option( 'cvd_tc_testers', array( $d1, $d2, $d3, $d4, $d5 ) );
$cq = static function ( array $extra ) use ( $O, $D, $H ): array { list( , $x ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'mens', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ) + $extra, $H ); return $x; };
$c0 = $cq( array( 'servicio' => 'carga' ) )['clases'][0];
$c1 = $cq( array( 'servicio' => 'carga', 'ayudante' => 1 ) )['clases'][0];
pv_ok( 500 === $c1['justo'] - $c0['justo'] && 500 === $c1['min'] - $c0['min'] && 500 === $c1['max'] - $c0['max'] && 500 === $c1['extra'], 'v3: ayudante +500 CUP en justo, mínimo y máximo' );
pv_ok( $c0['min'] >= 2000, 'v3: la carga cobra como triciclo de carga (mínimo 2.000)' );
update_option( 'cvd_pv_ayudante_cup', 700 );
pv_ok( 700 === $cq( array( 'servicio' => 'carga', 'ayudante' => 1 ) )['clases'][0]['extra'], 'v3: el ayudante sale de la opción cvd_pv_ayudante_cup' );
delete_option( 'cvd_pv_ayudante_cup' );
list( $s ) = pv_req( 'POST', 'viajes', array( 'cid' => 'v3c0', 'firma' => $c1['firma'], 'precio' => $c1['justo'], 'envio' => array( 'que' => 'Un refrigerador' ) ), $H );
pv_ok( 400 === $s, 'v3: carga sin peso no se pide' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'v3c', 'firma' => $c1['firma'], 'precio' => 1, 'forma_pago' => 'transferencia', 'envio' => array( 'que' => 'Un refrigerador y 2 sillas', 'peso' => 'medio' ) ), $H );
pv_ok( 200 === $s && 'carga' === $d['viaje']['servicio'] && 'medio' === $d['viaje']['envio']['peso'] && 500 === $d['viaje']['envio']['ayudante'], 'v3: carga guardada con peso y ayudante' );
pv_ok( $d['viaje']['oferta'] === $c1['min'] && $d['viaje']['min'] === $c1['min'] && $d['viaje']['max'] === $c1['max'], 'v3: la oferta nunca baja del mínimo con ayudante' );
pv_ok( '5350000101' === $d['viaje']['envio']['telefono'], 'v3: en la carga recibe el mismo cliente si no dice otro' );
$idc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $d['viaje']['uuid'] ) );
list( , $h5 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d5 ) );
$pc = array_values( array_filter( $h5['pedidos'], static fn( $p ) => (int) $p['id'] === $idc ) );
pv_ok( $pc && 'Carga · 50–150 kg · con ayudante' === $pc[0]['etiqueta'] && 'carga' === $pc[0]['servicio'], 'v3: el chofer de carga ve «Carga · 50–150 kg · con ayudante»' );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
pv_ok( ! in_array( $idc, array_column( $h3['pedidos'], 'id' ), true ), 'v3: el mensajero de mochila no ve la carga' );
list( $s, $sb ) = pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/precio', array( 'precio' => 1 ), $H );
pv_ok( 200 === $s && $sb['viaje']['oferta'] === $c1['min'], 'v3: bajar el precio de la carga se queda en el mínimo con ayudante' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array(), $H );

// ---------- Comida: adelanto con tope 5.000, cobrar ≥ adelanto, solo choferes con «Puedo adelantar»
pv_ok( 5000 === CVD_Pv_Tarifa::comida_tope(), 'v3: tope por defecto 5.000 (opción cvd_pv_comida_tope)' );
$cm = $cq( array( 'servicio' => 'comida' ) );
pv_ok( 'comida' === $cm['servicio'] && $cm['clases'][0]['justo'] === $q['clases'][0]['justo'], 'v3: la comida cobra como el mensajero' );
$cmf = $cm['clases'][0];
list( $s, $x ) = pv_req( 'POST', 'viajes', array( 'cid' => 'v3m0', 'firma' => $cmf['firma'], 'precio' => $cmf['justo'], 'envio' => array( 'que' => '2 pizzas', 'adelanto_cup' => 5010 ) ), $H );
pv_ok( 400 === $s && 'adelanto' === $x['code'], 'v3: tope de 5.000 CUP para adelantar' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'v3m', 'firma' => $cmf['firma'], 'precio' => $cmf['justo'], 'forma_pago' => 'transferencia', 'envio' => array( 'que' => '2 pizzas familiares', 'recoge' => 'Pizzería Obispo', 'adelanto_cup' => 2400, 'cobrar_cup' => 0 ) ), $H );
$VM = $d['viaje'] ?? array();
pv_ok( 200 === $s && 'comida' === $VM['servicio'] && 2400 === $VM['envio']['adelanto'] && 2400 === $VM['envio']['cobrar'] && 'Pizzería Obispo' === $VM['envio']['recoge'], 'v3: comida con adelanto; al entregar se cobra al menos lo adelantado' );
$idm = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $VM['uuid'] ) );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
pv_ok( ! in_array( $idm, array_column( $h3['pedidos'], 'id' ), true ) && false === $h3['ficha']['adelanta'], 'v3: sin «Puedo adelantar» no ve la comida con adelanto' );
list( $s ) = pv_req( 'POST', 'chofer/disponible', array( 'on' => 1, 'lat' => $O[0] + 0.003, 'lng' => $O[1], 'adelanta' => 1 ), $P( $d3 ) );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
$pm = array_values( array_filter( $h3['pedidos'], static fn( $p ) => (int) $p['id'] === $idm ) );
pv_ok( 200 === $s && true === $h3['ficha']['adelanta'] && $pm && 'Comida · adelanta 2.400' === $pm[0]['etiqueta'], 'v3: con «Puedo adelantar» ve «Comida · adelanta 2.400»' );
list( , $o ) = pv_req( 'POST', "chofer/pedidos/{$idm}/ofertar", array( 'pct' => 0 ), $P( $d3 ) );
pv_ok( false === $o['elegido'], 'v3: sin «1.ª oferta» el «Acepto» espera' );
list( $s, $pr ) = pv_req( 'POST', 'viajes/' . $VM['uuid'] . '/primera', array( 'on' => 1 ), $H );
pv_ok( 200 === $s && 'asignado' === $pr['viaje']['estado'] && true === $pr['viaje']['primera'], 'v3: encender «Coger la 1.ª» en las ofertas coge el «Acepto» que ya estaba' );
pv_req( 'POST', "chofer/viajes/{$idm}/estado", array( 'accion' => 'llegue' ), $P( $d3 ) );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$idm}/estado", array( 'accion' => 'recogi' ), $P( $d3 ) );
pv_ok( 400 === $s && 'recibo' === $x['code'], 'v3: no sale sin la foto del recibo' );
list( , $ha ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d3 ) );
pv_ok( 'comida' === $ha['activo']['servicio'] && 2400 === $ha['activo']['envio']['adelanto'] && false === $ha['activo']['envio']['recibo'] && 'Pizzería Obispo' === $ha['activo']['envio']['recoge'], 'v3: el chofer ve servicio, adelanto y sitio' );
$wpdb->update( $TV, array( 'recibo_foto' => 'r-prueba.jpg' ), array( 'id' => $idm ) );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$idm}/estado", array( 'accion' => 'recogi' ), $P( $d3 ) );
list( , $vv ) = pv_req( 'GET', 'viajes/' . $VM['uuid'], array(), $H );
pv_ok( 200 === $s && 'recogido' === $vv['viaje']['estado'] && false !== strpos( $vv['viaje']['envio']['recibo'], 't=recibo' ), 'v3: con el recibo sale; el cliente ve el enlace de la foto del recibo' );

// ---------- Viajes del cliente y semana del chofer
list( , $mv ) = pv_req( 'GET', 'viajes', array(), $H );
pv_ok( count( $mv['viajes'] ) >= 4, 'mis viajes' );
list( , $se ) = pv_req( 'GET', 'chofer/semana', array(), $P( $d2 ) );
pv_ok( 1 === $se['viajes'] && $se['total'] === $V['oferta'] + 150, 'semana del chofer' );

// ---------- 10-nov: Viajar «para otra persona» (Ernesto: el bot le escribe a quien viaja; paga al llegar)
$d6 = $mk( '5350000206', 'Reinier Pozo', 'electrico', 'viaje', 0.001 );
update_option( 'cvd_tc_testers', array( $d1, $d2, $d3, $d4, $d5, $d6 ) );
update_option( 'cvd_tc_clientes_prueba', array( '5350000101', '5350000105', '5350000106' ) );
$cli6 = CVD_Triciclub::crear_cliente( '5350000105', 'Marta Gil', true );
$H6 = array( 'X-TC-Token' => CVD_Triciclub::token_cliente( $cli6 ) );
$TC = CVD_Triciclub_Db::t( 'codigos' );
$cot6 = static function () use ( $H6, $O, $D ): array {
	list( , $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'tric', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1] ), $H6 );
	return array_values( array_filter( $q['clases'], static fn( $c ) => 'tric_electrico' === $c['clase'] ) )[0];
};
$el6 = $cot6();
list( $s, $x ) = pv_req( 'POST', 'viajes', array( 'cid' => 'p0', 'firma' => $el6['firma'], 'precio' => $el6['justo'], 'pasajero' => array( 'nombre' => 'Ana Pérez', 'telefono' => '1234' ) ), $H6 );
pv_ok( 400 === $s && 'pasajero' === $x['code'], 'para otro: móvil malo de quien viaja' );
list( $s, $x ) = pv_req( 'POST', 'viajes', array( 'cid' => 'p0b', 'firma' => $el6['firma'], 'precio' => $el6['justo'], 'pasajero' => array( 'nombre' => '', 'telefono' => '50000106' ) ), $H6 );
pv_ok( 400 === $s && 'pasajero' === $x['code'], 'para otro: falta el nombre' );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'p1', 'firma' => $el6['firma'], 'precio' => $el6['justo'], 'origen_texto' => 'Calle N e/ 23 y 25, Vedado', 'destino_texto' => 'Coppelia', 'pasajero' => array( 'nombre' => 'Ana Pérez', 'telefono' => '50000106' ) ), $H6 );
$VP = $d['viaje'] ?? array();
pv_ok( 200 === $s && 'Ana Pérez' === ( $VP['pasajero']['nombre'] ?? '' ) && '5350000106' === $VP['pasajero']['telefono'], 'para otro: el pedido guarda a quien viaja' );
$idp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $VP['uuid'] ) );
list( , $h6 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d6 ) );
$pp = array_values( array_filter( $h6['pedidos'], static fn( $p ) => (int) $p['id'] === $idp ) );
pv_ok( $pp && 'Ana' === $pp[0]['cliente']['nombre'] && 'Marta' === $pp[0]['pide'] && ! isset( $pp[0]['cliente']['telefono'] ), 'para otro: el chofer ve «Ana (lo pide Marta)» sin teléfono antes de cogerlo' );
list( , $o6 ) = pv_req( 'POST', "chofer/pedidos/{$idp}/ofertar", array( 'pct' => 0 ), $P( $d6 ) );
$antes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$TC} WHERE app='pva'" );
list( $s, $e6 ) = pv_req( 'POST', 'viajes/' . $VP['uuid'] . '/elegir', array( 'oferta' => $o6['oferta']['id'] ), $H6 );
pv_ok( 200 === $s && 'asignado' === $e6['viaje']['estado'] && true === $e6['viaje']['pasajero']['avisado'], 'para otro: eligió chofer y el cliente ve que se avisó a Ana' );
$av = $wpdb->get_row( "SELECT * FROM {$TC} WHERE app='pva' ORDER BY id DESC LIMIT 1" );
pv_ok( $antes + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$TC} WHERE app='pva'" ) && '5350000106' === $av->telefono && 'whatsapp' === $av->canal, 'para otro: WhatsApp a quien viaja en la cola del bot' );
pv_ok( false !== strpos( $av->texto, 'Hola Ana' ) && false !== strpos( $av->texto, 'Marta te pidió' ) && false !== strpos( $av->texto, 'Código para subir: ' . $e6['viaje']['codigo'] ) && false !== strpos( $av->texto, 'Reinier P.' ), 'para otro: el mensaje lleva quién lo pidió, chofer y código' );
$bot = CVD_Triciclub::bot_codigos( new WP_REST_Request( 'GET' ) )->get_data();
$bt = array_values( array_filter( $bot['codigos'], static fn( $c ) => (int) $c['id'] === (int) $av->id ) );
pv_ok( $bt && $bt[0]['text'] === $av->texto && '5350000106' === $bt[0]['phone'], 'para otro: el bot lo recoge con su texto (sin cambiar el bot)' );
pv_ok( 'expired' === CVD_Triciclub::comprobar_codigo( '5350000106', $e6['viaje']['codigo'] ), 'para otro: el aviso no sirve para entrar' );
list( , $ha6 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $d6 ) );
pv_ok( '5350000106' === $ha6['activo']['cliente']['telefono'] && 'Ana Pérez' === $ha6['activo']['cliente']['nombre'] && 'Marta' === $ha6['activo']['pide'], 'para otro: el chofer llama a Ana y sabe que lo pidió Marta' );
list( $s ) = pv_req( 'POST', 'viajes/' . $VP['uuid'] . '/cancelar', array( 'motivo' => '' ), $H6 );
$av2 = $wpdb->get_row( "SELECT * FROM {$TC} WHERE app='pva' ORDER BY id DESC LIMIT 1" );
pv_ok( 200 === $s && (int) $av2->id > (int) $av->id && false !== strpos( $av2->texto, 'canceló' ), 'para otro: si cancela, a Ana le llega que ya no espere' );
// En prueba, a un móvil que no es de prueba no se le escribe (una cuenta con teléfono recibe WhatsApp real).
$el6 = $cot6();
list( , $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'p2', 'firma' => $el6['firma'], 'precio' => $el6['justo'], 'pasajero' => array( 'nombre' => 'Pedro Real', 'telefono' => '59990000' ) ), $H6 );
$idp2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $d['viaje']['uuid'] ) );
list( , $o6 ) = pv_req( 'POST', "chofer/pedidos/{$idp2}/ofertar", array( 'pct' => 0 ), $P( $d6 ) );
list( $s ) = pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/elegir', array( 'oferta' => $o6['oferta']['id'] ), $H6 );
pv_ok( 200 === $s && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$TC} WHERE telefono='5359990000'" ), 'para otro: en prueba no se escribe a un móvil que no es de prueba' );
list( , $vv6 ) = pv_req( 'GET', 'viajes/' . $d['viaje']['uuid'], array(), $H6 );
pv_ok( false === $vv6['viaje']['pasajero']['avisado'], 'para otro: el cliente ve que NO se avisó (y lo manda él)' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array( 'motivo' => '' ), $H6 );
list( , $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'p3', 'firma' => $cot6()['firma'], 'precio' => $el6['justo'], 'pasajero' => array( 'nombre' => 'Marta', 'telefono' => '50000105' ) ), $H6 );
pv_ok( null === $d['viaje']['pasajero'], 'para otro: si pone su propio móvil, viaja él' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array( 'motivo' => '' ), $H6 );
$wpdb->query( "DELETE FROM {$TC} WHERE app='pva'" );

// ---------- 10-nov: arreglos de la auditoría
$TCH = CVD_Triciclub_Db::t( 'choferes' );
update_option( 'cvd_pv_tope', 0 ); // sin tope de deuda en este bloque (una prueba de antes lo deja en 50)
$viaje6 = static function ( string $cid ) use ( $cot6, $H6, $P, $d6, $TV, $wpdb ): array {
	$q = $cot6();
	list( , $d ) = pv_req( 'POST', 'viajes', array( 'cid' => $cid, 'firma' => $q['firma'], 'precio' => $q['justo'] ), $H6 );
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $d['viaje']['uuid'] ) );
	pv_ok( $id > 0, 'auditoría: pedido ' . $cid . ' ' . wp_json_encode( $d ) );
	list( , $o ) = pv_req( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 0 ), $P( $d6 ) );
	$fd = CVD_Triciclub::chofer_de( $d6 );
	pv_ok( ! empty( $o['oferta']['id'] ), 'auditoría: oferta ' . $cid . ' ' . wp_json_encode( $o ) . ' saldo=' . CVD_Pv_Cuenta::saldo( $d6 ) . ' tope=' . get_option( 'cvd_pv_tope' ) . ' lat=' . $fd->lat . ' lng=' . $fd->lng . ' v=' . wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT origen_lat, origen_lng, forma_pago, clase, chofer_id FROM {$TV} WHERE id=%d", $id ) ) ) );
	list( , $e ) = pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/elegir', array( 'oferta' => $o['oferta']['id'] ), $H6 );
	pv_ok( 'asignado' === ( $e['viaje']['estado'] ?? '' ), 'auditoría: elegir ' . $cid . ' ' . wp_json_encode( $e['message'] ?? '' ) );
	return array( $id, $d['viaje']['uuid'], $e['viaje']['codigo'] ?? '' );
};
list( $ia, $ua, $ca ) = $viaje6( 'a1' );
$wpdb->update( $TCH, array( 'lat' => $O[0] + 0.02, 'ubicado_at' => CVD_Triciclub_Db::now() ), array( 'user_id' => $d6 ) ); // a ~2,2 km
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$ia}/estado", array( 'accion' => 'llegue' ), $P( $d6 ) );
pv_ok( 409 === $s && 'lejos' === $x['code'], 'auditoría: «Llegué» desde lejos no vale' );
$wpdb->update( $TCH, array( 'lat' => $O[0] + 0.001 ), array( 'user_id' => $d6 ) );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$ia}/estado", array( 'accion' => 'llegue' ), $P( $d6 ) );
list( $s2 ) = pv_req( 'POST', "chofer/viajes/{$ia}/estado", array( 'accion' => 'recogi', 'codigo' => $ca ), $P( $d6 ) );
pv_ok( 200 === $s && 200 === $s2, 'auditoría: cerca sí (llegué y recogí)' );
list( $s, $x ) = pv_req( 'POST', 'viajes/' . $ua . '/llegue', array(), $H6 );
pv_ok( 409 === $s && 'pronto' === $x['code'], 'auditoría: «Ya llegué» del cliente no vale nada más subir' );
$wpdb->update( $TV, array( 'recogido_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $ia ) );
list( $s, $x ) = pv_req( 'POST', 'viajes/' . $ua . '/llegue', array(), $H6 );
pv_ok( 200 === $s && 'terminado' === $x['viaje']['estado'], 'auditoría: el cliente cierra con «Ya llegué» si el chofer no toca Terminé' );
pv_req( 'POST', "chofer/viajes/{$ia}/estado", array( 'accion' => 'cobrado' ), $P( $d6 ) );
update_option( 'cvd_pv_comision_pct', 8 );
$saldo0 = CVD_Pv_Cuenta::saldo( $d6 );
list( $ib, $ub, $cb ) = $viaje6( 'b1' );
pv_req( 'POST', "chofer/viajes/{$ib}/estado", array( 'accion' => 'llegue' ), $P( $d6 ) );
pv_req( 'POST', "chofer/viajes/{$ib}/estado", array( 'accion' => 'recogi', 'codigo' => $cb ), $P( $d6 ) );
$wpdb->update( $TV, array( 'recogido_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ), array( 'id' => $ib ) );
list( , $vb ) = pv_req( 'GET', 'viajes/' . $ub, array(), $H6 );
pv_ok( 'terminado' === $vb['viaje']['estado'], 'auditoría: un viaje colgado en «recogido» se cierra solo' );
pv_ok( CVD_Pv_Cuenta::saldo( $d6 ) > $saldo0, 'auditoría: el cierre solo apunta la comisión (8 %)' );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$ib}/estado", array( 'accion' => 'nopago' ), $P( $d6 ) );
pv_ok( 200 === $s && $saldo0 === CVD_Pv_Cuenta::saldo( $d6 ), 'auditoría: «No me pagó» deja esa comisión en revisión (no es deuda)' );
update_option( 'cvd_pv_comision_pct', 0 );
$rq = new WP_REST_Request( 'POST', '/casa-viva/v1/pv/yo' ); // yo_guardar lee JSON (como la app)
$rq->set_header( 'X-TC-Token', $H6['X-TC-Token'] );
$rq->set_header( 'Content-Type', 'application/json' );
$rq->set_body( wp_json_encode( array( 'lugares' => array( 'casa' => array( 'texto' => 'Calle N e/ 23 y 25', 'lat' => 23.14069, 'lng' => -82.38138 ) ) ) ) );
$s = rest_do_request( $rq )->get_status();
$lg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT lugares FROM ' . CVD_Triciclub_Db::t( 'clientes' ) . ' WHERE id=%d', (int) $cli6->id ) );
pv_ok( 200 === $s && false !== strpos( $lg, 'Calle N' ) && false === get_option( 'cvd_pv_lugares_' . (int) $cli6->id ), 'auditoría: Casa y Trabajo en la tabla de clientes' );
list( , $yo6 ) = pv_req( 'GET', 'yo', array(), $H6 );
pv_ok( 'Calle N e/ 23 y 25' === ( $yo6['yo']['lugares']['casa']['texto'] ?? '' ), 'auditoría: y se leen de ahí' );

// ---------- Limpieza
$wpdb->delete( CVD_Triciclub_Db::t( 'viajes' ), array( 'cliente_id' => (int) $cli6->id ) );
$wpdb->delete( CVD_Triciclub_Db::t( 'clientes' ), array( 'id' => (int) $cli6->id ) );
foreach ( array( $d1, $d2, $d3, $d4, $d5, $d6 ) as $u ) { $wpdb->delete( CVD_Triciclub_Db::t( 'choferes' ), array( 'user_id' => $u ) ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $u ); }
foreach ( array( 'viajes' => 'cliente_id', 'clientes' => 'id' ) as $tb => $col ) { $wpdb->delete( CVD_Triciclub_Db::t( $tb ), array( $col => (int) $cli->id ) ); }
echo "OK Pa'llá transporte: " . $GLOBALS['pv_n'] . " comprobaciones\n";
