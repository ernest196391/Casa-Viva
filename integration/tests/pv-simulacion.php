<?php
/**
 * Pa'llá Reparto (16-nov-2026, D157): simulación con datos inventados y sin red. 3 negocios, 50 mensajeros, 200 encargos
 * (unos para ya, otros con ventana y salida programada, algunos con persona de confianza, algunos cancelados y algunos que
 * no confirman «¿Sigues?»). Mide: tiempo hasta asignar, en qué fase, reparto justo (carreras por mensajero) y consultas a
 * la base de datos por petición. Los 45 s de cada ronda se simulan venciendo el turno (no se espera de verdad).
 * Correr: wp eval-file /var/www/html/integration-tests/pv-simulacion.php
 */
if ( ! defined( 'CVD_PV_SIN_RED' ) ) { define( 'CVD_PV_SIN_RED', true ); }

$GLOBALS['pv_n'] = 0;
if ( ! function_exists( 'pv_ok' ) ) {
	function pv_ok( $cond, string $que ): void {
		if ( ! $cond ) { fwrite( STDERR, "FALLA: {$que}\n" ); exit( 1 ); }
		$GLOBALS['pv_n']++;
	}
}
/** Petición REST que cuenta las consultas a la base de datos. */
function pv_sim( string $m, string $path, array $p = array(), array $h = array(), string $medir = '' ): array {
	global $wpdb;
	$r = new WP_REST_Request( $m, '/casa-viva/v1/pv/' . $path );
	foreach ( $h as $k => $v ) { $r->set_header( $k, $v ); }
	if ( 'GET' === $m ) { $r->set_query_params( $p ); } else { $r->set_body_params( $p ); }
	$q0 = $wpdb->num_queries;
	$t0 = microtime( true );
	$res = rest_do_request( $r );
	if ( '' !== $medir ) {
		$GLOBALS['pv_q'][ $medir ][] = $wpdb->num_queries - $q0;
		$GLOBALS['pv_ms'][ $medir ][] = ( microtime( true ) - $t0 ) * 1000;
	}
	$d = $res->get_data();
	if ( is_wp_error( $d ) ) { $d = array( 'ok' => false, 'code' => $d->get_error_code(), 'message' => $d->get_error_message() ); }
	return array( $res->get_status(), is_array( $d ) ? $d : array() );
}
add_filter( 'pre_http_request', static fn() => new WP_Error( 'sin_red', 'Prueba sin red' ), 10, 3 );
$GLOBALS['pv_q'] = array();
$GLOBALS['pv_ms'] = array();
mt_srand( 16112026 );

global $wpdb;
if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', false ); }
CVD_Triciclub_Db::install();
$TV = CVD_Triciclub_Db::t( 'viajes' );
$TT = CVD_Triciclub_Db::t( 'turnos' );
$TCH = CVD_Triciclub_Db::t( 'choferes' );
update_option( 'cvd_pv', 'prueba' );
update_option( 'cvd_pv_reparto', 'si' );
update_option( 'cvd_palla_ntfy_url', 'https://ntfy.invalid' );
update_option( 'cvd_palla_ntfy_token', 'prueba' );
update_option( 'cvd_pv_comision_pct', 0 );
update_option( 'cvd_pv_tope', 100000 );
delete_option( 'cvd_pv_tope_activas' );
wp_set_current_user( 1 );

// ---------- 3 negocios (Vedado, Centro Habana, Playa) con su punto
$sitios = array( array( 'Tienda Vedado', 23.14031, -82.38105 ), array( 'Mercado Centro', 23.13660, -82.36330 ), array( 'Dulcería Playa', 23.11550, -82.41500 ) );
$negs = array();
foreach ( $sitios as $i => $st ) {
	list( $nid, $clave ) = CVD_Pv_Reparto::crear_negocio( array( 'nombre' => $st[0], 'paga_envio' => array( 'cliente', 'negocio', 'mitad' )[ $i ], 'cobro' => array( 'cobra_devuelve', 'pagado', 'cobra_devuelve' )[ $i ], 'asignar' => 'te_toca' ) );
	$pid = CVD_Pv_Reparto::guardar_punto( array( 'negocio_id' => $nid, 'nombre' => 'Recogida', 'direccion' => $st[0], 'lat' => $st[1], 'lng' => $st[2], 'desde' => '00:00', 'hasta' => '23:59' ) );
	$negs[] = array( 'id' => $nid, 'clave' => $clave, 'punto' => $pid, 'lat' => $st[1], 'lng' => $st[2] );
}

// ---------- 50 mensajeros repartidos a ≤ 3 km de algún negocio
$ms = array();
for ( $i = 0; $i < 50; $i++ ) {
	$n = $negs[ $i % 3 ];
	$c = CVD_Triciclub_Alta::cuenta( '53500007' . sprintf( '%02d', $i ), 'Mensajero ' . $i . ' Prueba' );
	$uid = (int) $c['user_id'];
	$ms[ $uid ] = array( 'lat' => $n['lat'] + mt_rand( -250, 250 ) / 10000, 'lng' => $n['lng'] + mt_rand( -250, 250 ) / 10000 );
	$wpdb->update( $TCH, array( 'estado' => 'aprobado', 'tipo' => 'mensajero', 'servicios' => 'paquete', 'disponible' => 1, 'lat' => $ms[ $uid ]['lat'], 'lng' => $ms[ $uid ]['lng'], 'ubicado_at' => CVD_Triciclub_Db::now(), 'nota_media' => mt_rand( 38, 50 ) / 10 ), array( 'user_id' => $uid ) );
}
$uids = array_keys( $ms );
update_option( 'cvd_tc_testers', $uids );
$tok = array();
foreach ( $uids as $u ) { $tok[ $u ] = array( 'X-Palla-Token' => CVD_Palla::token_for( $u ) ); }
// 5 mensajeros con persona de confianza aprobada
$pareja = array();
foreach ( array_slice( $uids, 0, 5 ) as $k => $u ) {
	pv_sim( 'POST', 'chofer/confianza', array( 'nombre' => 'Ayuda ' . $k, 'telefono' => '53500008' . sprintf( '%02d', $k ), 'carne4' => '4321' ), $tok[ $u ] );
	$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CVD_Triciclub_Db::t( 'confianza' ) . ' WHERE chofer_id=%d', $u ) );
	CVD_Pv_Reparto::confianza_estado( $cid, 'aprobar', 1 );
	$pareja[ $u ] = array( 'X-Pv-Ayuda' => CVD_Pv_Reparto::token_ayuda( CVD_Pv_Reparto::confianza( $cid ) ), 'id' => $cid );
}
pv_ok( 3 === count( $negs ) && 50 === count( $uids ) && 5 === count( $pareja ), 'preparado: 3 negocios, 50 mensajeros, 5 parejas' );

$fresco = static function () use ( $wpdb, $TCH ): void { $wpdb->query( $wpdb->prepare( "UPDATE {$TCH} SET ubicado_at=%s WHERE estado='aprobado'", CVD_Triciclub_Db::now() ) ); };
$ofrecidos = static fn( int $id ): array => array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$TT} WHERE viaje_id=%d AND estado='ofrecido' ORDER BY id", $id ) ) );
$fila = static fn( int $id ): object => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$TV} WHERE id=%d", $id ) );
$hav = static fn( int $seg ): string => ( new DateTimeImmutable( '@' . ( time() + $seg ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'Y-m-d H:i' );

/**
 * Busca mensajero para un encargo como lo harían ellos: a quien le toca acepta (65 %), rechaza (15 %) o no contesta (20 %:
 * pasan los 45 s). En la fase abierta, el más cercano que lo ve dice «Acepto». Devuelve [chofer, segundos, fase] o null.
 */
$asignar = static function ( int $id ) use ( $ofrecidos, $fila, $tok, $ms, $wpdb, $TT ): ?array {
	$seg = 0;
	for ( $vuelta = 0; $vuelta < 8; $vuelta++ ) {
		$v = $fila( $id );
		if ( 'buscando' !== $v->estado ) { return null; }
		$fase = (int) $v->turno_fase;
		if ( 3 === $fase || 0 === $fase ) {
			$c = CVD_Pv_Reparto::candidatos( $v );
			foreach ( $c as $x ) {
				list( $s, $d ) = pv_sim( 'POST', "chofer/pedidos/{$id}/ofertar", array( 'pct' => 0 ), $tok[ $x[1] ], 'aceptar' );
				if ( 200 === $s && ! empty( $d['elegido'] ) ) { return array( $x[1], $seg + mt_rand( 5, 30 ), 3 ); }
			}
			return null;
		}
		$o = $ofrecidos( $id );
		$ignoro = false;
		foreach ( $o as $u ) {
			$r = mt_rand( 1, 100 );
			if ( $r <= 65 ) {
				list( $s ) = pv_sim( 'POST', "chofer/turnos/{$id}/aceptar", array(), $tok[ $u ], 'aceptar' );
				if ( 200 === $s ) { return array( $u, $seg + mt_rand( 3, 40 ), $fase ); }
			} elseif ( $r <= 80 ) {
				pv_sim( 'POST', "chofer/turnos/{$id}/rechazar", array(), $tok[ $u ], 'rechazar' );
			} else {
				$ignoro = true;
			}
		}
		if ( $ignoro || $ofrecidos( $id ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$TT} SET hasta=%s WHERE viaje_id=%d AND estado='ofrecido'", gmdate( 'Y-m-d H:i:s', time() - 1 ), $id ) );
			$seg += CVD_Pv_Reparto::TURNO_SEG;
		} else {
			$seg += mt_rand( 5, 20 ); // todos rechazaron: pasa al momento
		}
		$q0 = $wpdb->num_queries;
		CVD_Pv_Reparto::tick( true );
		$GLOBALS['pv_q']['tick'][] = $wpdb->num_queries - $q0;
	}
	return null;
};

/** Termina un encargo: recoge, foto, entrega con el código (él o su persona de confianza). */
$terminar = static function ( int $id, int $u, bool $con_pareja ) use ( $fila, $tok, $pareja, $wpdb, $TV ): bool {
	$v = $fila( $id );
	$h = $con_pareja ? array( 'X-Pv-Ayuda' => $pareja[ $u ]['X-Pv-Ayuda'] ) : $tok[ $u ];
	$ruta = $con_pareja ? "ayuda/viajes/{$id}/estado" : "chofer/viajes/{$id}/estado";
	pv_sim( 'POST', $ruta, array( 'accion' => 'recogi' ), $h, 'estado' );
	$wpdb->update( $TV, array( 'entrega_foto' => 'e-sim.jpg' ), array( 'id' => $id ) );
	list( $s ) = pv_sim( 'POST', $ruta, array( 'accion' => 'termine', 'codigo' => $v->entrega_codigo ), $h, 'estado' );
	return 200 === $s;
};

$res = array( 'creados' => 0, 'asignados' => 0, 'sin' => 0, 'cancelados' => 0, 'programados' => 0, 'soltados' => 0, 'reasignados' => 0, 'pareja' => 0, 'terminados' => 0, 'tope_roto' => 0 );
$tiempos = array();
$fases = array( 1 => 0, 2 => 0, 3 => 0 );
$en_vuelo = array();    // [id, chofer, pareja, desde_encargo]
$programados = array(); // [id, chofer]
for ( $i = 0; $i < 200; $i++ ) {
	$fresco();
	$n = $negs[ $i % 3 ];
	$con_ventana = 0 === $i % 4;
	$p = array(
		'punto_id' => $n['punto'],
		'entrega'  => array( 'texto' => 'Entrega ' . $i . ', La Habana', 'lat' => $n['lat'] + mt_rand( -250, 250 ) / 10000, 'lng' => $n['lng'] + mt_rand( -250, 250 ) / 10000 ),
		'recibe'   => array( 'nombre' => 'Cliente ' . $i, 'telefono' => '53500009' . sprintf( '%02d', $i % 100 ) ),
		'que'      => 'Paquete ' . $i,
		'tam'      => array( 'mano', 'mochila', 'mano' )[ $i % 3 ],
		'cobrar_cup' => mt_rand( 0, 5 ) * 1000,
		'ref'      => 'sim-' . $i,
	);
	if ( $con_ventana ) { $p['ventana'] = array( 'desde' => $hav( 2 * 3600 ), 'hasta' => $hav( 5 * 3600 ) ); }
	list( $s, $e ) = pv_sim( 'POST', 'negocio/encargos', $p, array( 'X-Negocio-Clave' => $n['clave'] ), 'crear' );
	if ( 200 !== $s ) { continue; }
	$res['creados']++;
	$id = (int) $e['id'];
	if ( 7 === $i % 10 ) { // el negocio cancela antes de que lo cojan
		pv_sim( 'POST', 'negocio/encargos/' . $e['uuid'] . '/cancelar', array(), array( 'X-Negocio-Clave' => $n['clave'] ), 'cancelar' );
		$res['cancelados']++;
		continue;
	}
	$a = $asignar( $id );
	if ( ! $a ) { $res['sin']++; continue; }
	list( $u, $seg, $fase ) = $a;
	$res['asignados']++;
	$tiempos[] = $seg;
	$fases[ $fase ]++;
	if ( CVD_Pv_Reparto::abiertas( $u ) > CVD_Pv_Reparto::tope() ) { $res['tope_roto']++; }
	pv_sim( 'GET', 'chofer/hoy', array(), $tok[ $u ], 'hoy' );
	if ( $con_ventana ) {
		$v = CVD_Pv_Api::vista_chofer( $fila( $id ) );
		pv_sim( 'POST', "chofer/viajes/{$id}/salida", array( 'cuando' => $v['reparto']['salida']['horas'][0]['hora'] ?? 'ahora' ), $tok[ $u ], 'salida' );
		$programados[] = array( $id, $u );
		$res['programados']++;
		continue;
	}
	pv_sim( 'POST', "chofer/viajes/{$id}/salida", array( 'cuando' => 'ahora' ), $tok[ $u ], 'salida' );
	$con_pareja = isset( $pareja[ $u ] ) && 0 === mt_rand( 0, 1 );
	if ( $con_pareja ) { pv_sim( 'POST', "chofer/viajes/{$id}/lleva", array( 'confianza_id' => $pareja[ $u ]['id'] ), $tok[ $u ], 'lleva' ); }
	$en_vuelo[] = array( $id, $u, $con_pareja, $i );
	// Los viajes en marcha se entregan unos 6 encargos después (así hay varios a la vez y el tope cuenta).
	foreach ( $en_vuelo as $k => $ev ) {
		if ( $ev[3] > $i - 6 ) { continue; }
		if ( $terminar( $ev[0], $ev[1], $ev[2] ) ) { $res['terminados']++; if ( $ev[2] ) { $res['pareja']++; } }
		unset( $en_vuelo[ $k ] );
	}
	// Cada 20 encargos el reloj sigue (como el wp-cron) y se mide.
	if ( 0 === $i % 20 ) { $q0 = $wpdb->num_queries; CVD_Pv_Reparto::tick( true ); $GLOBALS['pv_q']['tick'][] = $wpdb->num_queries - $q0; }
}
foreach ( $en_vuelo as $ev ) { if ( $terminar( $ev[0], $ev[1], $ev[2] ) ) { $res['terminados']++; if ( $ev[2] ) { $res['pareja']++; } } }

// ---------- Los programados llegan a su hora: el 90 % dice «Sigo» y lo lleva; el resto no contesta y se suelta solo.
foreach ( $programados as $k => list( $id, $u ) ) {
	$wpdb->update( $TV, array( 'sale_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * 60 ) ), array( 'id' => $id ) );
	if ( 0 !== $k % 10 ) {
		pv_sim( 'POST', "chofer/viajes/{$id}/sigo", array(), $tok[ $u ], 'sigo' );
		if ( $terminar( $id, $u, false ) ) { $res['terminados']++; }
		continue;
	}
	$wpdb->update( $TV, array( 'sale_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * 60 ) ), array( 'id' => $id ) );
	$fresco();
	CVD_Pv_Reparto::tick( true );
	$v = $fila( $id );
	if ( 'buscando' === $v->estado && 0 === (int) $v->chofer_id ) { $res['soltados']++; }
	$a = $asignar( $id );
	if ( $a && $a[0] !== $u ) {
		$res['reasignados']++;
		pv_sim( 'POST', "chofer/viajes/{$id}/salida", array( 'cuando' => 'ahora' ), $tok[ $a[0] ], 'salida' );
		if ( $terminar( $id, $a[0], false ) ) { $res['terminados']++; }
	}
}

// ---------- Números
$media = static fn( array $x ): float => $x ? array_sum( $x ) / count( $x ) : 0.0;
sort( $tiempos );
$p90 = $tiempos ? $tiempos[ (int) floor( 0.9 * ( count( $tiempos ) - 1 ) ) ] : 0;
$carreras = array_fill_keys( $uids, 0 );
foreach ( $wpdb->get_results( "SELECT chofer_id, COUNT(*) n FROM {$TV} WHERE negocio_id>0 AND estado='terminado' GROUP BY chofer_id" ) as $r ) { $carreras[ (int) $r->chofer_id ] = (int) $r->n; }
$mc = $media( $carreras );
$desv = sqrt( $media( array_map( static fn( int $c ): float => ( $c - $mc ) ** 2, $carreras ) ) );
$con = count( array_filter( $carreras ) );
$q = static fn( string $k ): string => isset( $GLOBALS['pv_q'][ $k ] ) ? sprintf( '%.0f (máx %d)', $media( $GLOBALS['pv_q'][ $k ] ), max( $GLOBALS['pv_q'][ $k ] ) ) : '-';
$ms_c = isset( $GLOBALS['pv_ms']['crear'] ) ? $media( $GLOBALS['pv_ms']['crear'] ) : 0;
echo "SIMULACIÓN Pa'llá Reparto (3 negocios · 50 mensajeros · 200 encargos)\n";
printf( "  creados %d · asignados %d · sin mensajero %d · cancelados %d · programados %d · soltados por no confirmar %d (reasignados %d) · entregados %d (con persona de confianza %d)\n", $res['creados'], $res['asignados'], $res['sin'], $res['cancelados'], $res['programados'], $res['soltados'], $res['reasignados'], $res['terminados'], $res['pareja'] );
printf( "  tiempo hasta asignar (simulado): media %.0f s · p90 %d s · máx %d s · fase 1: %d · fase 2: %d · abierta: %d\n", $media( $tiempos ), $p90, $tiempos ? max( $tiempos ) : 0, $fases[1], $fases[2], $fases[3] );
printf( "  reparto: %d de 50 con carreras · media %.1f · desviación %.2f · máx %d · mín (de los que tienen) %d\n", $con, $mc, $desv, max( $carreras ), $con ? min( array_filter( $carreras ) ) : 0 );
printf( "  consultas por petición: crear %s · aceptar %s · chofer/hoy %s · salida %s · estado %s · tick %s · crear %.0f ms\n", $q( 'crear' ), $q( 'aceptar' ), $q( 'hoy' ), $q( 'salida' ), $q( 'estado' ), $q( 'tick' ), $ms_c );

pv_ok( 200 === $res['creados'], 'se crearon los 200 encargos' );
pv_ok( $res['asignados'] >= 0.95 * ( $res['creados'] - $res['cancelados'] ), 'al menos el 95 % de los no cancelados tuvo mensajero' );
pv_ok( 0 === $res['tope_roto'], 'nadie pasó el tope de encargos abiertos' );
pv_ok( $fases[1] + $fases[2] > $fases[3], 'la mayoría se asigna en «Te toca» (fases 1–2), no en la lista abierta' );
pv_ok( $p90 <= 3 * CVD_Pv_Reparto::TURNO_SEG, 'el 90 % tiene mensajero en ≤ 3 rondas (' . $p90 . ' s)' );
pv_ok( $con >= 30, 'el trabajo se reparte: ' . $con . ' mensajeros con carreras' );
pv_ok( $desv <= $mc, 'reparto justo: desviación (' . round( $desv, 2 ) . ') ≤ media (' . round( $mc, 2 ) . ')' );
pv_ok( $res['soltados'] >= 1 && $res['reasignados'] === $res['soltados'], 'quien no confirma se suelta y otro lo lleva' );
pv_ok( $res['pareja'] >= 1, 'hubo entregas hechas por la persona de confianza' );
pv_ok( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$TV} WHERE negocio_id>0 AND estado='terminado' AND chofer_id=0" ), 'ninguna entrega sin titular' );
pv_ok( (int) $media( $GLOBALS['pv_q']['crear'] ) <= 120 && (int) $media( $GLOBALS['pv_q']['hoy'] ) <= 60, 'consultas por petición contenidas (crear ≤ 120, chofer/hoy ≤ 60 de media)' );

// ---------- Limpieza
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( "DELETE FROM {$TV} WHERE negocio_id>0" );
foreach ( array( 'negocios', 'puntos', 'confianza', 'turnos' ) as $tb ) { $wpdb->query( 'DELETE FROM ' . CVD_Triciclub_Db::t( $tb ) ); }
foreach ( $uids as $u ) { $wpdb->delete( $TCH, array( 'user_id' => $u ) ); wp_delete_user( $u ); }
delete_option( 'cvd_pv_reparto' );
echo "OK Pa'llá Reparto (simulación): " . $GLOBALS['pv_n'] . " comprobaciones\n";
