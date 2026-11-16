<?php
/**
 * Pa'llá Reparto (16-nov-2026, D157): prueba con datos inventados, sin red (CVD_PV_SIN_RED + toda petición HTTP cortada).
 * Correr: wp eval-file /var/www/html/integration-tests/pv-reparto.php (después de pv-flujo.php, en la misma web de prueba).
 * Interruptor apagado = nada cambia · negocio y clave · encargo por API · «Te toca» (fases, rechazo, candado, tope) ·
 * «¿Cuándo sales?» (ventana, «¿Sigues?», se suelta solo, «Ya no puedo») · persona de confianza (alta, aprobación, enlace,
 * solo ve lo suyo, anular) · panel de soporte · comisión del negocio · seguimiento.
 * Teléfonos inventados 5350000xxx, sin `_cvd_whatsapp`: nadie recibe nada.
 */
if ( ! defined( 'CVD_PV_SIN_RED' ) ) { define( 'CVD_PV_SIN_RED', true ); }

$GLOBALS['pv_n'] = 0;
$GLOBALS['pv_ntfy'] = array();
if ( ! function_exists( 'pv_ok' ) ) {
	function pv_ok( $cond, string $que ): void {
		if ( ! $cond ) { fwrite( STDERR, "FALLA: {$que}\n" ); exit( 1 ); }
		$GLOBALS['pv_n']++;
	}
}
if ( ! function_exists( 'pv_req' ) ) {
	function pv_req( string $m, string $path, array $p = array(), array $h = array() ): array {
		$r = new WP_REST_Request( $m, '/casa-viva/v1/pv/' . $path );
		foreach ( $h as $k => $v ) { $r->set_header( $k, $v ); }
		if ( 'GET' === $m ) { $r->set_query_params( $p ); } else { $r->set_body_params( $p ); }
		$res = rest_do_request( $r );
		$d = $res->get_data();
		if ( is_wp_error( $d ) ) { $d = array( 'ok' => false, 'code' => $d->get_error_code(), 'message' => $d->get_error_message() ); }
		return array( $res->get_status(), is_array( $d ) ? $d : array() );
	}
}
// Sin red: ninguna petición sale. Los avisos a ntfy se apuntan para comprobarlos (tema, título, etiquetas).
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( false !== strpos( (string) $url, 'ntfy.invalid' ) ) { $GLOBALS['pv_ntfy'][] = json_decode( (string) ( $args['body'] ?? '' ), true ); }
	return new WP_Error( 'sin_red', 'Prueba sin red' );
}, 10, 3 );

global $wpdb;
$TV = CVD_Triciclub_Db::t( 'viajes' );
$TT = CVD_Triciclub_Db::t( 'turnos' );
$TCH = CVD_Triciclub_Db::t( 'choferes' );
pv_ok( 'no' === get_option( 'cvd_pv_reparto', 'no' ), 'Reparto nace apagado' );
delete_option( 'cvd_tc_db' );
CVD_Triciclub_Db::install();
pv_ok( 8 === (int) get_option( 'cvd_tc_db' ), 'tablas versión 8' );
foreach ( array( 'negocios', 'puntos', 'confianza', 'turnos' ) as $tb ) { pv_ok( $wpdb->get_var( "SHOW TABLES LIKE '" . CVD_Triciclub_Db::t( $tb ) . "'" ), "tabla {$tb}" ); }
foreach ( array( 'negocio_id', 'punto_id', 'ventana_desde', 'ventana_hasta', 'sale_at', 'sigue_at', 'lleva_id', 'cobrar_para', 'turno_fase' ) as $col ) { pv_ok( $wpdb->get_var( "SHOW COLUMNS FROM {$TV} LIKE '{$col}'" ), "viajes.{$col}" ); }

// ---------- Datos de prueba
update_option( 'cvd_pv', 'prueba' );
update_option( 'cvd_palla_ntfy_url', 'https://ntfy.invalid' );
update_option( 'cvd_palla_ntfy_token', 'prueba' );
update_option( 'cvd_pv_comision_pct', 0 );
update_option( 'cvd_pv_tope', 100000 );
delete_option( 'cvd_pv_tope_activas' );
$O = array( 23.14031, -82.38105 ); // Calle N e/ 23 y 25 (el punto de recogida)
$D = array( 23.13909, -82.38293 ); // Coppelia
$mk = static function ( string $tel, string $nombre, float $dlat ) use ( $wpdb, $O, $TCH ): int {
	$c = CVD_Triciclub_Alta::cuenta( $tel, $nombre );
	$wpdb->update( $TCH, array( 'estado' => 'aprobado', 'tipo' => 'mensajero', 'servicios' => 'paquete', 'plazas' => 0, 'disponible' => 1, 'lat' => $O[0] + $dlat, 'lng' => $O[1], 'ubicado_at' => CVD_Triciclub_Db::now() ), array( 'user_id' => $c['user_id'] ) );
	update_user_meta( (int) $c['user_id'], CVD_Palla_Push::NTFY_TOPIC, 'palla-' . (int) $c['user_id'] . '-prueba' );
	return (int) $c['user_id'];
};
$m1 = $mk( '5350000311', 'Yunior Pérez', 0.0027 );   // ~300 m
$m2 = $mk( '5350000312', 'Dayron Ruiz', 0.0054 );    // ~600 m
$m3 = $mk( '5350000313', 'Pedro Gómez', 0.0081 );    // ~900 m (titular de la persona de confianza)
$m4 = $mk( '5350000314', 'Maikel Soto', 0.0108 );    // ~1,2 km
$m5 = $mk( '5350000315', 'Liset Mora', 0.0135 );     // ~1,5 km
$todos = array( $m1, $m2, $m3, $m4, $m5 );
update_option( 'cvd_tc_testers', $todos );
$P = static fn( int $uid ): array => array( 'X-Palla-Token' => CVD_Palla::token_for( $uid ) );
$fresco = static function () use ( $wpdb, $TCH, $todos ): void { foreach ( $todos as $u ) { $wpdb->update( $TCH, array( 'ubicado_at' => CVD_Triciclub_Db::now(), 'disponible' => 1 ), array( 'user_id' => $u ) ); } };
$avisos = static function ( int $uid, string $tag ): int {
	$n = 0;
	foreach ( $GLOBALS['pv_ntfy'] as $a ) { if ( is_array( $a ) && ( $a['topic'] ?? '' ) === 'palla-' . $uid . '-prueba' && in_array( $tag, (array) ( $a['tags'] ?? array() ), true ) ) { $n++; } }
	return $n;
};
$fila = static fn( int $id ): object => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$TV} WHERE id=%d", $id ) );
$ofrecidos = static fn( int $id ): array => array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$TT} WHERE viaje_id=%d AND estado='ofrecido' ORDER BY id", $id ) ) );
$vencer = static function ( int $id ) use ( $wpdb, $TT ): void {
	$wpdb->query( $wpdb->prepare( "UPDATE {$TT} SET hasta=%s WHERE viaje_id=%d AND estado='ofrecido'", gmdate( 'Y-m-d H:i:s', time() - 1 ), $id ) );
	CVD_Pv_Reparto::tick( true );
};
$hav = static fn( int $seg ): string => ( new DateTimeImmutable( '@' . ( time() + $seg ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'Y-m-d H:i' );

// ====================================================================================== Interruptor apagado
CVD_Pv_Reparto::programar();
pv_ok( ! wp_next_scheduled( CVD_Pv_Reparto::CRON ), 'apagado: sin wp-cron' );
list( $nid, $clave ) = CVD_Pv_Reparto::crear_negocio( array( 'nombre' => 'Tienda La Prueba', 'tipo' => 'tienda', 'telefono' => '5350000301', 'paga_envio' => 'cliente', 'cobro' => 'cobra_devuelve', 'asignar' => 'te_toca' ) );
$N = array( 'X-Negocio-Clave' => $clave );
$pid = CVD_Pv_Reparto::guardar_punto( array( 'negocio_id' => $nid, 'nombre' => 'Almacén', 'direccion' => 'Calle N e/ 23 y 25, Vedado', 'lat' => $O[0], 'lng' => $O[1], 'desde' => '00:00', 'hasta' => '23:59', 'dias' => '1234567', 'telefono' => '5350000302' ) );
$base = array( 'punto_id' => $pid, 'entrega' => array( 'texto' => 'Coppelia, 23 y L', 'lat' => $D[0], 'lng' => $D[1] ), 'recibe' => array( 'nombre' => 'Marta', 'telefono' => '5350000401' ), 'que' => 'Caja de zapatos', 'tam' => 'mano', 'cobrar_cup' => 3000 );
list( $s ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'off' ), $N );
pv_ok( 403 === $s, 'apagado: la API del negocio contesta 403' );
pv_ok( is_wp_error( CVD_Pv_Reparto::crear_encargo( CVD_Pv_Reparto::negocio( $nid ), $base ) ), 'apagado: el panel tampoco crea encargos' );
list( $s, $h ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( 200 === $s && ! isset( $h['reparto'] ), 'apagado: chofer/hoy igual que antes (sin «reparto»)' );
list( $s ) = pv_req( 'POST', 'chofer/turnos/1/aceptar', array(), $P( $m1 ) );
pv_ok( 403 === $s, 'apagado: «Aceptar» de Te toca contesta 403' );
delete_option( 'cvd_pv_tick_at' );
CVD_Pv_Reparto::tick( true );
pv_ok( false === get_option( 'cvd_pv_tick_at' ), 'apagado: el reloj no hace nada' );
pv_ok( '' === (string) $wpdb->get_var( "SELECT 1 FROM {$TV} WHERE negocio_id>0 LIMIT 1" ), 'apagado: ningún encargo creado' );

// ====================================================================================== Negocio y clave
update_option( 'cvd_pv_reparto', 'si' );
CVD_Pv_Reparto::programar();
pv_ok( (bool) wp_next_scheduled( CVD_Pv_Reparto::CRON ), 'encendido: wp-cron cada minuto' );
pv_ok( (bool) preg_match( '/^pvn_[a-f0-9]{32}$/', $clave ), 'clave del negocio' );
pv_ok( ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . CVD_Triciclub_Db::t( 'negocios' ) . ' WHERE clave_hash=%s', $clave ) ), 'la clave no se guarda (solo su hash)' );
pv_ok( (int) CVD_Pv_Reparto::negocio_por_clave( $clave )->id === $nid, 'la clave abre su negocio' );
list( $s ) = pv_req( 'GET', 'negocio/encargos', array(), array( 'X-Negocio-Clave' => 'pvn_' . str_repeat( '0', 32 ) ) );
pv_ok( 401 === $s, 'clave mala: 401' );
pv_ok( $pid > 0 && '1234567' === CVD_Pv_Reparto::punto( $pid )->dias, 'punto de recogida con horario y días' );
list( $nid2, $clave2 ) = CVD_Pv_Reparto::crear_negocio( array( 'nombre' => 'Mercado Dos', 'tipo' => 'mercado', 'asignar' => 'ofertas', 'paga_envio' => 'negocio', 'cobro' => 'pagado' ) );
$pid2 = CVD_Pv_Reparto::guardar_punto( array( 'negocio_id' => $nid2, 'nombre' => 'Puesto', 'lat' => $O[0] + 0.001, 'lng' => $O[1] ) );

// ====================================================================================== Encargo por API + «Te toca»
list( $s, $x ) = pv_req( 'POST', 'negocio/encargos', array_merge( $base, array( 'recibe' => array( 'nombre' => 'Marta' ) ) ), $N );
pv_ok( 400 === $s && 'recibe' === $x['code'], 'encargo sin móvil de quien recibe: 400' );
list( $s, $x ) = pv_req( 'POST', 'negocio/encargos', array_merge( $base, array( 'punto_id' => $pid2 ) ), $N );
pv_ok( 400 === $s && 'punto' === $x['code'], 'el punto de otro negocio no vale' );
list( $s, $x ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ventana' => array( 'desde' => $hav( 7200 ), 'hasta' => $hav( 3600 ) ) ), $N );
pv_ok( 400 === $s && 'ventana' === $x['code'], 'ventana al revés: 400' );
// JSON, como lo manda otra web
$rq = new WP_REST_Request( 'POST', '/casa-viva/v1/pv/negocio/encargos' );
$rq->set_header( 'X-Negocio-Clave', $clave );
$rq->set_header( 'Content-Type', 'application/json' );
$rq->set_body( wp_json_encode( $base + array( 'ref' => 'pedido-1' ) ) );
$res = rest_do_request( $rq );
$e1 = $res->get_data();
pv_ok( 200 === $res->get_status() && 'buscando' === $e1['estado'] && $e1['precio'] >= 1000, 'encargo por API (JSON): buscando con la tarifa justa (' . ( $e1['precio'] ?? '?' ) . ')' );
pv_ok( 3000 + $e1['precio'] === $e1['cobrar'], 'el envío lo paga el cliente: cobra producto + envío' );
pv_ok( false !== strpos( $e1['seguimiento_url'], '/pv/seguir' ) && 4 === strlen( $e1['codigo_entrega'] ), 'seguimiento y código de entrega' );
list( $s, $x ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-1' ), $N );
pv_ok( 200 === $s && $x['repetido'] && $x['uuid'] === $e1['uuid'], 'repetir el encargo (sin señal) no crea otro' );
$id1 = (int) $e1['id'];
$r1 = $fila( $id1 );
pv_ok( 0 === (int) $r1->cliente_id && $nid === (int) $r1->negocio_id && $pid === (int) $r1->punto_id && 'mensajero' === $r1->clase && 'paquete' === $r1->servicio && 1 === (int) $r1->primera && 'negocio' === $r1->cobrar_para, 'el encargo es un viaje de mensajero del negocio' );
pv_ok( 1 === (int) $r1->turno_fase && array( $m1 ) === $ofrecidos( $id1 ), 'Te toca fase 1: al mejor (el más cerca, ' . implode( ',', $ofrecidos( $id1 ) ) . ')' );
pv_ok( 1 === $avisos( $m1, 'tetoca' ) && 0 === $avisos( $m2, 'tetoca' ), 'aviso «Te toca» con su etiqueta solo a él' );
pv_ok( in_array( 'v' . $id1, (array) end( $GLOBALS['pv_ntfy'] )['tags'], true ) || $avisos( $m1, 'v' . $id1 ), 'el aviso lleva «v<id>» para el botón Aceptar' );
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( 1 === count( $h1['reparto']['turno'] ) && $id1 === $h1['reparto']['turno'][0]['id'] && ! empty( $h1['reparto']['turno'][0]['turno_hasta'] ), 'chofer/hoy: le toca a él, con la hora límite' );
pv_ok( 'Tienda La Prueba' === $h1['reparto']['turno'][0]['reparto']['negocio']['nombre'] && 'Almacén' === $h1['reparto']['turno'][0]['reparto']['punto']['nombre'], 'la tarjeta trae negocio y punto' );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m2 ) );
pv_ok( ! in_array( $id1, array_column( $h2['pedidos'], 'id' ), true ) && ! $h2['reparto']['turno'], 'en la fase 1 los demás no lo ven' );
list( $s ) = pv_req( 'POST', "chofer/pedidos/{$id1}/ofertar", array( 'pct' => 0 ), $P( $m2 ) );
pv_ok( 409 === $s, 'ni pueden cogerlo por la lista abierta' );
list( $s, $x ) = pv_req( 'POST', "chofer/turnos/{$id1}/aceptar", array(), $P( $m2 ) );
pv_ok( 409 === $s, 'ni con «Aceptar» si no les toca' );
list( $s, $ng ) = pv_req( 'GET', 'negocio/encargos/' . $e1['uuid'], array(), $N );
pv_ok( 200 === $s && 'buscando' === $ng['encargo']['estado'] && '' === $ng['encargo']['mensajero'], 'el negocio lo ve buscando' );

// Rechazo → fase 2 (a los 3 siguientes)
list( $s ) = pv_req( 'POST', "chofer/turnos/{$id1}/rechazar", array(), $P( $m1 ) );
$o2 = $ofrecidos( $id1 );
pv_ok( 200 === $s && 2 === (int) $fila( $id1 )->turno_fase && 3 === count( $o2 ) && ! in_array( $m1, $o2, true ), 'rechazo: fase 2 a los 3 siguientes (' . implode( ',', $o2 ) . ')' );
pv_ok( array( $m2, $m3, $m4 ) === $o2, 'fase 2: los 3 mejores por cercanía' );
// Candado: gana el primero
list( $s, $a3 ) = pv_req( 'POST', "chofer/turnos/{$id1}/aceptar", array(), $P( $m3 ) );
pv_ok( 200 === $s && 'asignado' === $a3['viaje']['estado'] && $e1['precio'] === $a3['viaje']['precio'], 'fase 2: acepta el primero, al precio fijo' );
list( $s ) = pv_req( 'POST', "chofer/turnos/{$id1}/aceptar", array(), $P( $m2 ) );
pv_ok( 409 === $s, 'el segundo que acepta llega tarde (candado)' );
list( $s ) = pv_req( 'POST', "chofer/turnos/{$id1}/aceptar", array(), $P( $m3 ) );
pv_ok( 200 === $s, 'aceptar dos veces no rompe nada' );
pv_ok( 'aceptado' === $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$TT} WHERE viaje_id=%d AND chofer_id=%d", $id1, $m3 ) ) && ! $ofrecidos( $id1 ), 'turnos: aceptado y el resto vencido' );
pv_ok( 'Tienda La Prueba' === $a3['viaje']['cliente']['nombre'] && '5350000302' === $a3['viaje']['cliente']['telefono'], 'el mensajero ve el negocio y el móvil del punto' );
pv_ok( ! empty( $a3['viaje']['reparto']['salida']['ahora'] ) && 3000 === $a3['viaje']['reparto']['cobro']['devolver'], '«¿Cuándo sales?»: puede salir ahora; devuelve 3.000 al negocio' );
list( $s, $sa ) = pv_req( 'POST', "chofer/viajes/{$id1}/salida", array( 'cuando' => 'ahora' ), $P( $m3 ) );
pv_ok( 200 === $s && ! $sa['viaje']['reparto']['programado'] && $sa['viaje']['reparto']['sigue'], 'sale ahora: no hace falta «¿Sigues?»' );
list( , $ng ) = pv_req( 'GET', 'negocio/encargos/' . $e1['uuid'], array(), $N );
pv_ok( 'asignado' === $ng['encargo']['estado'] && 'Pedro G.' === $ng['encargo']['mensajero'] && '5350000313' === $ng['encargo']['mensajero_tel'], 'el negocio ve al mensajero y su móvil' );
// Entrega con la comisión propia del negocio (5 %)
$wpdb->update( CVD_Triciclub_Db::t( 'negocios' ), array( 'comision_pct' => 5 ), array( 'id' => $nid ) );
pv_req( 'POST', "chofer/viajes/{$id1}/estado", array( 'accion' => 'recogi' ), $P( $m3 ) );
$wpdb->update( $TV, array( 'entrega_foto' => 'e-prueba.jpg' ), array( 'id' => $id1 ) );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id1}/estado", array( 'accion' => 'termine', 'codigo' => $e1['codigo_entrega'] ), $P( $m3 ) );
pv_ok( 200 === $s && 'terminado' === $x['viaje']['estado'], 'entregado con código y foto (el motor de siempre)' );
pv_ok( (int) round( $e1['precio'] * 0.05 ) === CVD_Pv_Cuenta::saldo( $m3 ), 'comisión propia del negocio: 5 %' );
$wpdb->update( CVD_Triciclub_Db::t( 'negocios' ), array( 'comision_pct' => null ), array( 'id' => $nid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CVD_Triciclub_Db::t( 'cuenta' ) . ' WHERE chofer_id=%d', $m3 ) );
list( , $sg ) = pv_req( 'GET', 'seguir', array( 'u' => $e1['uuid'], 'f' => substr( (string) wp_parse_args( (string) wp_parse_url( $e1['seguimiento_url'], PHP_URL_QUERY ) )['f'], 0, 24 ) ) );
pv_ok( 'terminado' === $sg['estado'] && 'Pedro G.' === $sg['mensajero'], 'seguimiento: entregado por Pedro' );
list( $s ) = pv_req( 'GET', 'seguir', array( 'u' => $e1['uuid'], 'f' => str_repeat( 'a', 24 ) ) );
pv_ok( 404 === $s, 'seguimiento con firma mala: 404' );

// Vencidos → fase 2 → fase 3 (abierta, «Acepto» al precio fijo, sin contraofertas)
$fresco();
list( , $e2 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-2' ), $N );
$id2 = (int) $e2['id'];
pv_ok( array( $m1 ) === $ofrecidos( $id2 ), 'encargo 2: le toca a m1 (m3 ya hizo una hoy)' );
$vencer( $id2 );
pv_ok( 2 === (int) $fila( $id2 )->turno_fase && array( $m2, $m4, $m5 ) === $ofrecidos( $id2 ), 'a los 45 s: fase 2 (' . implode( ',', $ofrecidos( $id2 ) ) . '); m3 baja por «pocas carreras hoy»' );
$vencer( $id2 );
pv_ok( 3 === (int) $fila( $id2 )->turno_fase && ! $ofrecidos( $id2 ), 'otros 45 s: fase 3, abierta' );
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( in_array( $id2, array_column( $h1['pedidos'], 'id' ), true ), 'abierta: lo ve en la lista quien estaba cerca' );
list( $s, $of ) = pv_req( 'POST', "chofer/pedidos/{$id2}/ofertar", array( 'pct' => 20 ), $P( $m1 ) );
pv_ok( 200 === $s && $of['elegido'] && $e2['precio'] === $of['activo']['precio'], 'abierta: «Acepto» se lo queda al precio fijo (sin +20 %)' );
pv_req( 'POST', "chofer/viajes/{$id2}/salida", array( 'cuando' => 'ahora' ), $P( $m1 ) );

// ====================================================================================== «¿Cuándo sales?» con ventana
$fresco();
list( $s, $e3 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-3', 'ventana' => array( 'desde' => $hav( 7200 ), 'hasta' => $hav( 4 * 3600 ) ) ), $N );
$id3 = (int) $e3['id'];
pv_ok( 200 === $s && array( $m2 ) === $ofrecidos( $id3 ), 'encargo con ventana: le toca a m2' );
list( $s, $a ) = pv_req( 'POST', "chofer/turnos/{$id3}/aceptar", array(), $P( $m2 ) );
$op = $a['viaje']['reparto']['salida'];
pv_ok( 200 === $s && false === $op['ahora'] && count( $op['horas'] ) >= 2, 'ventana dentro de 2 h: no puede salir ahora; elige hora (' . $op['desde'] . '–' . $op['hasta'] . ')' );
pv_ok( strtotime( $op['horas'][0]['at'] ) >= time() + 7200 - 60 && 0 === (int) substr( $op['horas'][0]['hora'], 3 ) % 15, 'la primera hora es dentro de la ventana y de 15 en 15' );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$id3}/salida", array( 'cuando' => 'ahora' ), $P( $m2 ) );
pv_ok( 400 === $s, '«Ahora» no vale antes de la ventana' );
list( $s ) = pv_req( 'POST', "chofer/viajes/{$id3}/salida", array( 'cuando' => '03:07' ), $P( $m2 ) );
pv_ok( 400 === $s, 'una hora fuera de la lista no vale' );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id3}/salida", array( 'cuando' => $op['horas'][0]['hora'] ), $P( $m2 ) );
pv_ok( 200 === $s && $x['viaje']['reparto']['programado'] && ! $x['viaje']['reparto']['sigue'] && $op['horas'][0]['hora'] === $x['viaje']['reparto']['sale_hora'], 'sale a las ' . $op['horas'][0]['hora'] . ': programado' );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m2 ) );
pv_ok( null === $h2['activo'] && $id3 === ( $h2['reparto']['programados'][0]['id'] ?? 0 ), 'programado: no es su viaje en marcha; sale en «programados»' );
list( , $ps ) = pv_req( 'POST', 'chofer/posicion', array( 'lat' => $O[0] + 0.0054, 'lng' => $O[1] ), $P( $m2 ) );
pv_ok( 0 === $ps['viaje'], 'programado: sin GPS de viaje todavía' );
list( , $ng ) = pv_req( 'GET', 'negocio/encargos/' . $e3['uuid'], array(), $N );
pv_ok( $ng['encargo']['programado'] && $op['horas'][0]['hora'] === $ng['encargo']['sale_hora'] && null === $ng['encargo']['pos'], 'el negocio ve «Sale a las ' . $ng['encargo']['sale_hora'] . '»' );
// Tope contra acaparar: con un programado puede coger otro (tope 2), con tope 1 no
$fresco();
pv_ok( in_array( $m2, array_column( CVD_Pv_Reparto::candidatos( $fila( $id3 ) ), 1 ), true ), 'tope 2: con un programado sigue siendo candidato' );
update_option( 'cvd_pv_tope_activas', 1 );
pv_ok( ! in_array( $m2, array_column( CVD_Pv_Reparto::candidatos( $fila( $id3 ) ), 1 ), true ), 'tope 1: ya no' );
list( , $e4 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-4' ), $N );
$id4 = (int) $e4['id'];
pv_ok( ! array_intersect( array( $m1, $m2 ), $ofrecidos( $id4 ) ), 'tope 1: el encargo 4 no se ofrece a quien ya tiene uno' );
$vencer( $id4 );
$vencer( $id4 );
list( , $h2 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m2 ) );
list( $s ) = pv_req( 'POST', "chofer/pedidos/{$id4}/ofertar", array( 'pct' => 0 ), $P( $m2 ) );
pv_ok( ! in_array( $id4, array_column( $h2['pedidos'], 'id' ), true ) && 409 === $s, 'tope 1: tampoco lo ve ni lo coge en la lista abierta' );
delete_option( 'cvd_pv_tope_activas' );
list( $s ) = pv_req( 'POST', 'negocio/encargos/' . $e4['uuid'] . '/cancelar', array( 'motivo' => 'prueba' ), $N );
pv_ok( 200 === $s && 'cancelado' === $fila( $id4 )->estado && 'negocio' === $fila( $id4 )->cancelado_por, 'el negocio cancela un encargo' );

// «¿Sigues?» 30 min antes; «Sigo»
$wpdb->update( $TV, array( 'sale_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * 60 ) ), array( 'id' => $id3 ) );
CVD_Pv_Reparto::tick( true );
CVD_Pv_Reparto::tick( true );
pv_ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CVD_Triciclub_Db::t( 'eventos' ) . " WHERE viaje_id=%d AND tipo='pv_sigues'", $id3 ) ) && 1 === $avisos( $m2, 'sigues' ), '«¿Sigues?» una sola vez, con su aviso' );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id3}/sigo", array(), $P( $m2 ) );
pv_ok( 200 === $s && $x['viaje']['reparto']['sigue'] && ! $x['viaje']['reparto']['programado'], '«Sigo»: confirmado y ya en marcha' );
list( , $ps ) = pv_req( 'POST', 'chofer/posicion', array( 'lat' => $O[0] + 0.0054, 'lng' => $O[1] ), $P( $m2 ) );
pv_ok( $id3 === $ps['viaje'], 'a 30 min de salir ya manda el GPS del viaje' );
CVD_Pv_Reparto::tick( true );
pv_ok( 'asignado' === $fila( $id3 )->estado && $m2 === (int) $fila( $id3 )->chofer_id, 'confirmado: no se suelta' );

// Sin «Sigo» 10 min antes: se suelta solo, vuelve a «Te toca» sin él y baja su cumplimiento
$fresco();
list( , $e5 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-5', 'ventana' => array( 'desde' => $hav( 7200 ), 'hasta' => $hav( 4 * 3600 ) ) ), $N );
$id5 = (int) $e5['id'];
$quien = $ofrecidos( $id5 )[0] ?? 0;
pv_ok( in_array( $quien, array( $m3, $m4, $m5 ), true ), 'encargo 5: le toca a uno libre (' . $quien . ')' );
list( , $a ) = pv_req( 'POST', "chofer/turnos/{$id5}/aceptar", array(), $P( $quien ) );
pv_req( 'POST', "chofer/viajes/{$id5}/salida", array( 'cuando' => $a['viaje']['reparto']['salida']['horas'][0]['hora'] ), $P( $quien ) );
$antes = array_column( CVD_Pv_Reparto::candidatos( $fila( $id5 ) ), 0, 1 )[ $quien ] ?? 0;
$wpdb->update( $TV, array( 'sale_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * 60 ) ), array( 'id' => $id5 ) );
CVD_Pv_Reparto::tick( true );
$r5 = $fila( $id5 );
pv_ok( 'buscando' === $r5->estado && 0 === (int) $r5->chofer_id && null === $r5->sale_at, 'sin «Sigo» a 10 min: se suelta solo' );
pv_ok( 1 === (int) $r5->turno_fase && $ofrecidos( $id5 ) && ! in_array( $quien, $ofrecidos( $id5 ), true ), 'vuelve a «Te toca» sin él' );
pv_ok( 'solto' === $wpdb->get_var( $wpdb->prepare( "SELECT estado FROM {$TT} WHERE viaje_id=%d AND chofer_id=%d ORDER BY id DESC LIMIT 1", $id5, $quien ) ) && 1 === $avisos( $quien, 'suelto' ), 'apuntado como «soltó» y avisado' );
$despues = array_column( CVD_Pv_Reparto::candidatos( $fila( $id5 ) ), 0, 1 )[ $quien ] ?? 0;
pv_ok( $despues < $antes && 0 < (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CVD_Triciclub_Db::t( 'eventos' ) . " WHERE viaje_id=%d AND tipo='pv_no_confirmo' AND actor_id=%d", $id5, $quien ) ), "baja su puntuación por no confirmar ({$antes} → {$despues})" );
CVD_Pv_Reparto::cancelar( $fila( $id5 ), 'negocio' );
pv_ok( 'cancelado' === $fila( $id5 )->estado && ! $ofrecidos( $id5 ), 'cancelado: sin turnos vivos' );

// «Ya no puedo» (soltar a mano): vuelve a «Te toca» sin él
list( $s ) = pv_req( 'POST', "chofer/viajes/{$id2}/estado", array( 'accion' => 'suelto', 'motivo' => 'se me pinchó' ), $P( $m1 ) );
$r2 = $fila( $id2 );
pv_ok( 200 === $s && 'buscando' === $r2->estado && 1 === (int) $r2->turno_fase && ! in_array( $m1, $ofrecidos( $id2 ), true ), '«Ya no puedo»: vuelve a «Te toca» sin él' );
list( $s ) = pv_req( 'POST', "chofer/turnos/{$id2}/aceptar", array(), $P( $m1 ) );
pv_ok( 409 === $s, 'quien lo soltó no lo puede volver a coger con «Aceptar»' );
$dar = static function ( int $id, int $uid ) use ( $ofrecidos, $fila, $vencer, $P ): bool {
	for ( $i = 0; $i < 6; $i++ ) {
		$o = $ofrecidos( $id );
		if ( in_array( $uid, $o, true ) ) { list( $s ) = pv_req( 'POST', "chofer/turnos/{$id}/aceptar", array(), $P( $uid ) ); return 200 === $s; }
		if ( 3 === (int) $fila( $id )->turno_fase ) { list( $s, $x ) = pv_req( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 0 ), $P( $uid ) ); return 200 === $s && ! empty( $x['elegido'] ); }
		$vencer( $id );
	}
	return false;
};
$fresco();
pv_ok( $dar( $id2, $m5 ), 'otro mensajero coge el encargo soltado' );

// ====================================================================================== Persona de confianza
$fresco();
list( , $e6 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-6' ), $N );
$id6 = (int) $e6['id'];
pv_ok( $dar( $id6, $m3 ), 'Pedro coge el encargo 6' );
pv_req( 'POST', "chofer/viajes/{$id6}/salida", array( 'cuando' => 'ahora' ), $P( $m3 ) );
list( $s, $x ) = pv_req( 'POST', 'chofer/confianza', array( 'nombre' => 'Isabel Díaz', 'telefono' => '5350000501', 'carne4' => '12' ), $P( $m3 ) );
pv_ok( 400 === $s && 'carne' === $x['code'], 'persona de confianza: hacen falta 4 cifras del carné' );
list( $s, $x ) = pv_req( 'POST', 'chofer/confianza', array( 'nombre' => 'Isabel Díaz', 'telefono' => '5350000501', 'carne4' => '1234' ), $P( $m3 ) );
pv_ok( 200 === $s && 'pendiente' === $x['confianza'][0]['estado'], 'Pedro registra a Isabel: pendiente' );
$cid = (int) $x['confianza'][0]['id'];
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id6}/lleva", array( 'confianza_id' => $cid ), $P( $m3 ) );
pv_ok( 400 === $s, 'sin aprobar no puede llevarlo' );
wp_set_current_user( 1 );
$msg = CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'aprobar', 'id' => $cid ) );
pv_ok( (bool) preg_match( '/#a=(a\d+\.\d+\.[a-f0-9]{32})/', $msg, $mm ), 'el panel la aprueba y genera su enlace' );
$A = array( 'X-Pv-Ayuda' => $mm[1] );
list( $s, $ay ) = pv_req( 'GET', 'ayuda/hoy', array(), $A );
pv_ok( 200 === $s && 'Isabel Díaz' === $ay['nombre'] && 'Pedro' === $ay['titular'] && ! $ay['viajes'], 'Isabel entra con su enlace (todavía sin viajes)' );
list( $s ) = pv_req( 'GET', 'chofer/hoy', array(), array( 'X-Palla-Token' => $mm[1] ) );
pv_ok( 401 === $s, 'su enlace no abre la app del mensajero (ni pedidos ni dinero)' );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id2}/lleva", array( 'confianza_id' => $cid ), $P( $m5 ) );
pv_ok( 400 === $s, 'otro mensajero no puede usar a la persona de Pedro' );
list( $s, $x ) = pv_req( 'POST', "chofer/viajes/{$id6}/lleva", array( 'confianza_id' => $cid ), $P( $m3 ) );
pv_ok( 200 === $s && 'Isabel Díaz' === $x['viaje']['reparto']['lleva']['nombre'], '«Lo lleva… Isabel»' );
list( , $ng ) = pv_req( 'GET', 'negocio/encargos/' . $e6['uuid'], array(), $N );
pv_ok( 'Isabel (de Pedro)' === $ng['encargo']['mensajero'], 'el negocio ve «Isabel (de Pedro)»' );
list( , $ay ) = pv_req( 'GET', 'ayuda/hoy', array(), $A );
pv_ok( 1 === count( $ay['viajes'] ) && $id6 === $ay['viajes'][0]['id'] && ! isset( $ay['viajes'][0]['precio'] ) && ! isset( $ay['viajes'][0]['total'] ), 'Isabel ve solo su viaje, sin el precio' );
list( $s ) = pv_req( 'POST', "ayuda/viajes/{$id2}/estado", array( 'accion' => 'recogi' ), $A );
pv_ok( 404 === $s, 'no puede tocar un viaje que no lleva' );
list( $s ) = pv_req( 'POST', "ayuda/viajes/{$id6}/estado", array( 'accion' => 'problema', 'motivo' => 'No abren' ), $A );
pv_ok( 200 === $s && 1 === $avisos( $m3, 'problema' ), '«Problema»: avisa a Pedro' );
list( $s, $x ) = pv_req( 'POST', "ayuda/viajes/{$id6}/estado", array( 'accion' => 'recogi' ), $A );
pv_ok( 200 === $s && 'recogido' === $x['viaje']['estado'], 'Isabel: «Recogí»' );
list( $s, $x ) = pv_req( 'POST', "ayuda/viajes/{$id6}/estado", array( 'accion' => 'termine', 'codigo' => $e6['codigo_entrega'] ), $A );
pv_ok( 400 === $s && 'foto' === $x['code'], 'Isabel: sin foto no entrega' );
$wpdb->update( $TV, array( 'entrega_foto' => 'e-prueba.jpg' ), array( 'id' => $id6 ) );
list( $s, $x ) = pv_req( 'POST', "ayuda/viajes/{$id6}/estado", array( 'accion' => 'termine', 'codigo' => '0000' === $e6['codigo_entrega'] ? '1111' : '0000' ), $A );
pv_ok( 400 === $s && 'codigo' === $x['code'], 'Isabel: código malo' );
list( $s, $x ) = pv_req( 'POST', "ayuda/viajes/{$id6}/estado", array( 'accion' => 'termine', 'codigo' => $e6['codigo_entrega'] ), $A );
pv_ok( 200 === $s && 'terminado' === $x['viaje']['estado'] && $m3 === (int) $fila( $id6 )->chofer_id, 'Isabel entrega; el viaje y el dinero siguen a nombre de Pedro' );
list( , $h3 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m3 ) );
pv_ok( 2 === $h3['hoy']['viajes'], 'cuenta en las carreras de hoy de Pedro' );
pv_ok( in_array( 'pv_problema', array_column( CVD_Pv_Reparto_Panel::alertas(), 'tipo' ), true ), 'el panel ve la alerta del problema' );
CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'anular', 'id' => $cid ) );
list( $s ) = pv_req( 'GET', 'ayuda/hoy', array(), $A );
pv_ok( 401 === $s, 'anulada: su enlace ya no vale' );

// ====================================================================================== Panel de soporte
$fresco();
$msg = CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'negocio', 'nombre' => 'Dulcería Tres', 'tipo' => 'tienda', 'paga_envio' => 'mitad', 'cobro' => 'cobra_devuelve', 'asignar' => 'te_toca' ) );
pv_ok( (bool) preg_match( '/#(\d+) creado.*(pvn_[a-f0-9]{32})/', $msg, $mm ), 'panel: alta de negocio con su clave (una vez)' );
$nid3 = (int) $mm[1];
$msg = CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'punto', 'negocio_id' => $nid3, 'nombre' => 'Mostrador', 'direccion' => 'Calle 23', 'lat' => (string) $O[0], 'lng' => (string) $O[1], 'desde' => '08:00', 'hasta' => '20:00', 'dias' => '123456' ) );
pv_ok( (bool) preg_match( '/Punto #(\d+)/', $msg, $mm ), 'panel: punto de recogida' );
$pid3 = (int) $mm[1];
$msg = CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'encargo', 'negocio_id' => $nid3, 'punto_id' => $pid3, 'entrega' => 'Coppelia', 'lat' => (string) $D[0], 'lng' => (string) $D[1], 'recibe' => 'Ana', 'telefono' => '5350000402', 'que' => 'Un cake', 'cobrar_cup' => 2000 ) );
pv_ok( (bool) preg_match( '/Encargo #(\d+) creado/', $msg, $mm ), 'panel: encargo a mano (mismo código)' );
$id7 = (int) $mm[1];
$r7 = $fila( $id7 );
pv_ok( 2000 + CVD_Pv_Tarifa::arriba10( (int) $r7->precio / 2 ) === (int) $r7->cobrar_cup, 'envío a medias: cobra producto + la mitad del envío' );
$res = CVD_Pv_Reparto_Panel::buscar( (string) $id7 );
pv_ok( 1 === count( $res ) && $id7 === (int) $res[0]->id, 'panel: buscar por número' );
$res = CVD_Pv_Reparto_Panel::buscar( '50000402' );
pv_ok( in_array( $id7, array_map( 'intval', array_column( $res, 'id' ) ), true ), 'panel: buscar por el móvil de quien recibe' );
pv_ok( $dar( $id7, $m4 ), 'm4 coge el encargo del panel' );
CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'reasignar', 'id' => $id7 ) );
pv_ok( 'buscando' === $fila( $id7 )->estado && ! in_array( $m4, $ofrecidos( $id7 ), true ), 'panel: reasignar = soltar y «Te toca» sin él' );
$fresco();
pv_ok( $dar( $id7, $m1 ), 'm1 lo coge' );
pv_ok( 'Marcado entregado.' === CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'entregado', 'id' => $id7 ) ) && 'terminado' === $fila( $id7 )->estado, 'panel: marcar entregado' );
// Sin chofer → alerta → «Buscar otra vez»
list( , $e8 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-8' ), $N );
$id8 = (int) $e8['id'];
$wpdb->update( $TV, array( 'turno_fase' => 3, 'busca_desde' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $id8 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$TT} SET estado='vencido' WHERE viaje_id=%d", $id8 ) );
CVD_Pv_Reparto::tick( true );
pv_ok( 'sin_chofer' === $fila( $id8 )->estado && in_array( $id8, array_column( CVD_Pv_Reparto_Panel::alertas(), 'viaje' ), true ), 'nadie lo cogió: «sin chofer» y alerta en el panel' );
list( , $ng ) = pv_req( 'GET', 'negocio/encargos/' . $e8['uuid'], array(), $N );
pv_ok( 'sin_chofer' === $ng['encargo']['estado'], 'el negocio lo ve «sin chofer»' );
$fresco();
CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'relanzar', 'id' => $id8 ) );
pv_ok( 'buscando' === $fila( $id8 )->estado && 1 === (int) $fila( $id8 )->turno_fase && $ofrecidos( $id8 ), 'panel: «Buscar otra vez»' );
CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'cancelar', 'id' => $id8 ) );
pv_ok( 'cancelado' === $fila( $id8 )->estado && 'panel' === $fila( $id8 )->cancelado_por, 'panel: cancelar' );
// Teléfono perdido: anular los enlaces del mensajero
$tok4 = CVD_Palla::token_for( $m4 );
CVD_Pv_Reparto_Panel::accion( array( 'accion' => 'anular_chofer', 'uid' => '5350000314' ) );
pv_ok( 0 === CVD_Palla::verify( $tok4 ), 'panel: anular el enlace de un mensajero (por su móvil)' );
$html = CVD_Pv_Reparto_Panel::html( '', (string) $id7 );
pv_ok( false !== strpos( $html, 'Pa\'llá · Reparto' ) && false !== strpos( $html, 'Dulcería Tres' ) && false !== strpos( $html, '#' . $id7 ), 'panel: la página sale con negocios y la búsqueda' );
// Negocio en modo «ofertas»: abierto desde el principio, «Acepto» al precio fijo
$fresco();
list( $s, $e9 ) = pv_req( 'POST', 'negocio/encargos', array( 'punto_id' => $pid2, 'entrega' => $base['entrega'], 'recibe' => $base['recibe'], 'que' => 'Viandas', 'cobrar_cup' => 999, 'ref' => 'm-1' ), array( 'X-Negocio-Clave' => $clave2 ) );
$id9 = (int) $e9['id'];
pv_ok( 200 === $s && 0 === (int) $fila( $id9 )->turno_fase && ! $ofrecidos( $id9 ) && 0 === $e9['cobrar'], 'negocio «ofertas»: abierto a todos; ya pagado y el envío lo paga el negocio: no cobra nada' );
list( $s, $of ) = pv_req( 'POST', "chofer/pedidos/{$id9}/ofertar", array( 'pct' => 30 ), $P( $m3 ) );
pv_ok( 200 === $s && $of['elegido'] && $e9['precio'] === $of['activo']['precio'] && $e9['precio'] === $of['activo']['reparto']['cobro']['envio_negocio'], 'negocio «ofertas»: el primer «Acepto» se lo queda; el envío lo paga el negocio' );
list( , $ls ) = pv_req( 'GET', 'negocio/encargos', array(), $N );
pv_ok( count( $ls['encargos'] ) >= 7 && 1 === count( $ls['puntos'] ), 'el negocio ve su lista y sus puntos (no los de otro)' );

// ====================================================================================== Apagado otra vez: nada cambia
list( , $e10 ) = pv_req( 'POST', 'negocio/encargos', $base + array( 'ref' => 'pedido-10' ), $N );
$id10 = (int) $e10['id'];
$vencer( $id10 );
$vencer( $id10 );
update_option( 'cvd_pv_reparto', 'no' );
CVD_Pv_Reparto::programar();
pv_ok( ! wp_next_scheduled( CVD_Pv_Reparto::CRON ), 'apagado: se quita el wp-cron' );
$fresco();
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( ! isset( $h1['reparto'] ) && ! in_array( $id10, array_column( $h1['pedidos'], 'id' ), true ), 'apagado: chofer/hoy sin «reparto» y sin encargos abiertos' );
list( $s ) = pv_req( 'GET', 'negocio/encargos/' . $e10['uuid'], array(), $N );
pv_ok( 403 === $s, 'apagado: la API del negocio, cerrada' );
$t0 = get_option( 'cvd_pv_tick_at' );
CVD_Pv_Reparto::tick( true );
pv_ok( get_option( 'cvd_pv_tick_at' ) === $t0, 'apagado: el reloj no hace nada' );
// Un envío de Pa'llá transporte con Reparto apagado: igual que siempre (sin campos nuevos)
update_option( 'cvd_tc_clientes_prueba', array( '5350000601' ) );
$cli = CVD_Triciclub::crear_cliente( '5350000601', 'Rosa Prueba', true );
$H = array( 'X-TC-Token' => CVD_Triciclub::token_cliente( $cli ) );
list( , $q ) = pv_req( 'GET', 'cotizar', array( 'grupo' => 'mens', 'olat' => $O[0], 'olng' => $O[1], 'dlat' => $D[0], 'dlng' => $D[1], 'tam' => 'mano' ), $H );
list( $s, $d ) = pv_req( 'POST', 'viajes', array( 'cid' => 'r1', 'firma' => $q['clases'][0]['firma'], 'precio' => $q['clases'][0]['justo'], 'envio' => array( 'que' => 'Sobre', 'recibe_nombre' => 'Ana', 'recibe_telefono' => '5350000602' ) ), $H );
pv_ok( 200 === $s && ! isset( $d['viaje']['sale'] ), 'transporte: el cliente ve su envío como siempre' );
$idt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$TV} WHERE uuid=%s", $d['viaje']['uuid'] ) );
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( in_array( $idt, array_column( $h1['pedidos'], 'id' ), true ), 'transporte: el mensajero ve el envío del cliente' );
list( $s, $o ) = pv_req( 'POST', "chofer/pedidos/{$idt}/ofertar", array( 'pct' => 10 ), $P( $m1 ) );
pv_ok( 200 === $s && ! $o['elegido'] && $o['oferta']['precio'] > $q['clases'][0]['justo'], 'transporte: la contraoferta +10 % sigue igual' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/elegir', array( 'oferta' => $o['oferta']['id'] ), $H );
list( , $h1 ) = pv_req( 'GET', 'chofer/hoy', array(), $P( $m1 ) );
pv_ok( $idt === $h1['activo']['id'] && ! isset( $h1['activo']['reparto'] ) && 'Rosa Prueba' === $h1['activo']['cliente']['nombre'], 'transporte: la vista del viaje no cambia' );
pv_req( 'POST', 'viajes/' . $d['viaje']['uuid'] . '/cancelar', array(), $H );

// ---------- Limpieza
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( "DELETE FROM {$TV} WHERE negocio_id>0" );
$wpdb->delete( $TV, array( 'cliente_id' => (int) $cli->id ) );
$wpdb->delete( CVD_Triciclub_Db::t( 'clientes' ), array( 'id' => (int) $cli->id ) );
foreach ( array( 'negocios', 'puntos', 'confianza', 'turnos' ) as $tb ) { $wpdb->query( 'DELETE FROM ' . CVD_Triciclub_Db::t( $tb ) ); }
foreach ( $todos as $u ) { $wpdb->delete( $TCH, array( 'user_id' => $u ) ); $wpdb->delete( CVD_Triciclub_Db::t( 'cuenta' ), array( 'chofer_id' => $u ) ); wp_delete_user( $u ); }
delete_option( 'cvd_pv_reparto' );
echo "OK Pa'llá Reparto: " . $GLOBALS['pv_n'] . " comprobaciones\n";
