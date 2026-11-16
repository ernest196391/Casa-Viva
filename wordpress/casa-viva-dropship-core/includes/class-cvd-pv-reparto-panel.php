<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá Reparto (16-nov-2026, D157): el panel de soporte en /palla/admin/reparto (usuario administrador de la web).
 * HTML del servidor, sin JavaScript, como /palla/admin. Negocios y puntos, personas de confianza, buscar un viaje y
 * arreglarlo (reasignar, cancelar, entregado, volver a buscar), anular el enlace de un chofer, alertas y encargo a mano.
 */
final class CVD_Pv_Reparto_Panel {
	private const NONCE = 'cvd_pv_reparto_panel';

	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
	}

	public static function route(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
		if ( '/palla/admin/reparto' !== rtrim( $path, '/' ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá Reparto: panel' );
		nocache_headers();
		if ( ! CVD_Triciclub_Alta::puede_panel() ) { wp_safe_redirect( wp_login_url( home_url( '/palla/admin/reparto/' ) ) ); exit; }
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( self::NONCE ) ) {
			$msg = self::accion( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cada campo se limpia en accion()
		}
		echo self::html( $msg, sanitize_text_field( wp_unslash( (string) ( $_GET['q'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* abajo.
		exit;
	}

	private static function t( string $n ): string { return CVD_Triciclub_Db::t( $n ); }

	private static function cup( int $n ): string { return number_format( $n, 0, ',', '.' ) . ' CUP'; }

	private static function hora( ?string $utc ): string {
		return $utc ? ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'd/m H:i' ) : '';
	}

	/** Un botón del panel (con nonce y campos ocultos). Devuelve el aviso para enseñar. */
	public static function accion( array $p ): string {
		$a = sanitize_key( (string) ( $p['accion'] ?? '' ) );
		$id = absint( $p['id'] ?? 0 );
		$s = static fn( string $k, int $max = 160 ): string => mb_substr( sanitize_text_field( (string) ( $p[ $k ] ?? '' ) ), 0, $max );
		switch ( $a ) {
			case 'reparto':
				$on = 'si' === sanitize_key( (string) ( $p['modo'] ?? '' ) );
				update_option( CVD_Pv_Reparto::OPTION, $on ? 'si' : 'no', true );
				CVD_Pv_Reparto::programar();
				return 'Reparto: ' . ( $on ? 'encendido' : 'apagado' ) . '.';
			case 'ajustes':
				update_option( 'cvd_pv_tope_activas', max( 1, min( 10, absint( $p['tope'] ?? 2 ) ) ), false );
				update_option( 'cvd_pv_reparto_radio', max( 1000, min( 20000, absint( $p['radio'] ?? CVD_Pv_Api::RADIO_2 ) ) ), false );
				return 'Ajustes guardados.';
			case 'negocio':
				list( $nid, $clave ) = CVD_Pv_Reparto::crear_negocio( array( 'nombre' => $s( 'nombre', 80 ), 'tipo' => sanitize_key( (string) ( $p['tipo'] ?? '' ) ), 'telefono' => $s( 'telefono', 20 ),
					'paga_envio' => sanitize_key( (string) ( $p['paga_envio'] ?? '' ) ), 'cobro' => sanitize_key( (string) ( $p['cobro'] ?? '' ) ), 'asignar' => sanitize_key( (string) ( $p['asignar'] ?? '' ) ), 'comision_pct' => $s( 'comision_pct', 3 ) ) );
				return $nid ? 'Negocio #' . $nid . ' creado. Su clave (cópiala ahora, no se vuelve a ver): ' . $clave : 'No se pudo crear.';
			case 'clave':
				return CVD_Pv_Reparto::negocio( $id ) ? 'Clave nueva del negocio #' . $id . ' (la de antes ya no vale; cópiala ahora): ' . CVD_Pv_Reparto::nueva_clave( $id ) : 'Ese negocio no existe.';
			case 'negocio_on':
			case 'negocio_off':
				global $wpdb;
				$wpdb->update( self::t( 'negocios' ), array( 'activo' => 'negocio_on' === $a ? 1 : 0, 'updated_at' => CVD_Triciclub_Db::now() ), array( 'id' => $id ) );
				return 'negocio_on' === $a ? 'Negocio activo.' : 'Negocio parado: su clave ya no crea encargos.';
			case 'punto':
				$pid = CVD_Pv_Reparto::guardar_punto( array( 'negocio_id' => absint( $p['negocio_id'] ?? 0 ), 'nombre' => $s( 'nombre', 80 ), 'direccion' => $s( 'direccion' ), 'lat' => $s( 'lat', 20 ), 'lng' => $s( 'lng', 20 ),
					'desde' => $s( 'desde', 5 ), 'hasta' => $s( 'hasta', 5 ), 'dias' => $s( 'dias', 7 ), 'telefono' => $s( 'telefono', 20 ) ) );
				return $pid ? 'Punto #' . $pid . ' guardado.' : 'Faltan datos del punto (negocio, lat y lng de La Habana).';
			case 'aprobar':
			case 'rechazar':
			case 'anular':
				if ( ! CVD_Pv_Reparto::confianza_estado( $id, $a, get_current_user_id() ) ) { return 'No encontré esa persona.'; }
				$c = CVD_Pv_Reparto::confianza( $id );
				return 'aprobar' === $a ? 'Aprobada. Su enlace: ' . CVD_Pv_Reparto::enlace_ayuda( $c ) : ( 'anular' === $a ? 'Anulada: su enlace ya no vale.' : 'Rechazada.' );
			case 'enlace':
				$c = CVD_Pv_Reparto::confianza( $id );
				return $c && 'aprobada' === $c->estado ? 'Enlace de ' . $c->nombre . ': ' . CVD_Pv_Reparto::enlace_ayuda( $c ) : 'Esa persona no está aprobada.';
			case 'anular_chofer':
				$uid = absint( $p['uid'] ?? 0 );
				$tel = CVD_Triciclub::telefono( $p['uid'] ?? '' );
				if ( '' !== $tel ) { $ids = get_users( array( 'meta_key' => 'cvd_tc_telefono', 'meta_value' => $tel, 'fields' => 'ID', 'number' => 1 ) ); $uid = $ids ? (int) $ids[0] : 0; } // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( ! $uid || ! get_userdata( $uid ) ) { return 'No encontré ese chofer.'; }
				CVD_Palla::revoke( $uid );
				return 'Enlaces del chofer #' . $uid . ' anulados. Mándale uno nuevo cuando lo pida.';
			case 'reasignar':
			case 'cancelar':
			case 'entregado':
			case 'relanzar':
				return self::viaje_accion( $a, $id );
			case 'encargo':
				$n = CVD_Pv_Reparto::negocio( absint( $p['negocio_id'] ?? 0 ) );
				if ( ! $n ) { return 'Elige el negocio.'; }
				$v = CVD_Pv_Reparto::crear_encargo( $n, array( 'punto_id' => absint( $p['punto_id'] ?? 0 ), 'entrega' => array( 'texto' => $s( 'entrega' ), 'lat' => $s( 'lat', 20 ), 'lng' => $s( 'lng', 20 ) ),
					'recibe' => array( 'nombre' => $s( 'recibe', 80 ), 'telefono' => $s( 'telefono', 20 ) ), 'que' => $s( 'que', 120 ), 'tam' => sanitize_key( (string) ( $p['tam'] ?? '' ) ), 'cobrar_cup' => absint( $p['cobrar_cup'] ?? 0 ),
					'ventana' => array( 'desde' => $s( 'desde', 16 ), 'hasta' => $s( 'hasta', 16 ) ), 'nota' => $s( 'nota', 200 ) ), 'panel' );
				return is_wp_error( $v ) ? $v->get_error_message() : 'Encargo #' . (int) $v->id . ' creado (' . self::cup( (int) $v->precio ) . '). Código de entrega: ' . $v->entrega_codigo . '.';
		}
		return '';
	}

	/** Reasignar (= soltar: vuelve a «Te toca» sin él), cancelar, marcar entregado o volver a buscar. */
	private static function viaje_accion( string $a, int $id ): string {
		global $wpdb;
		$v = CVD_Pv_Api::viaje( $id );
		if ( ! $v ) { return 'No encontré ese viaje.'; }
		if ( 'reasignar' === $a ) {
			$u = (int) $v->chofer_id ? get_userdata( (int) $v->chofer_id ) : null;
			if ( ! $u ) { return 'Ese viaje no tiene mensajero.'; }
			$d = CVD_Pv_Api::soltar( $v, $u, 'Reasignado desde el panel' )->get_data();
			return ! empty( $d['ok'] ) ? 'Soltado: busca otro mensajero.' : (string) ( $d['message'] ?? 'No se pudo.' );
		}
		if ( 'cancelar' === $a ) {
			if ( (int) $v->negocio_id ) { $e = CVD_Pv_Reparto::cancelar( $v, 'panel', 'Desde el panel' ); return '' === $e ? 'Cancelado.' : $e; }
			$now = CVD_Triciclub_Db::now();
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='cancelado', cancelado_at=%s, cancelado_por='panel', updated_at=%s WHERE id=%d AND estado IN ('buscando','sin_chofer','asignado','llego')", $now, $now, $id ) );
			if ( $n ) { CVD_Triciclub_Db::evento( $id, 'panel', get_current_user_id(), 'cancelado', array( 'antes' => $v->estado ) ); CVD_Pv_Api::tocar( CVD_Pv_Api::viaje( $id ), 'cancelado' ); }
			return $n ? 'Cancelado.' : 'Ya no se puede cancelar.';
		}
		if ( 'entregado' === $a ) {
			if ( in_array( $v->estado, array( 'asignado', 'llego' ), true ) ) {
				$now = CVD_Triciclub_Db::now();
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='recogido', recogido_at=%s, updated_at=%s WHERE id=%d AND estado=%s", $now, $now, $id, $v->estado ) );
				$v = CVD_Pv_Api::viaje( $id );
			}
			if ( 'recogido' !== $v->estado ) { return 'Solo se marca entregado un viaje con mensajero.'; }
			$v2 = CVD_Pv_Api::terminar( $v, 'panel', get_current_user_id() );
			return 'terminado' === $v2->estado ? 'Marcado entregado.' : 'No se pudo.';
		}
		if ( 'relanzar' === $a ) {
			if ( ! in_array( $v->estado, array( 'sin_chofer' ), true ) || ! (int) $v->negocio_id ) { return 'Solo se vuelve a buscar un encargo «sin chofer».'; }
			$now = CVD_Triciclub_Db::now();
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='buscando', turno_fase=0, busca_desde=%s, updated_at=%s WHERE id=%d AND estado='sin_chofer'", $now, $now, $id ) );
			CVD_Triciclub_Db::evento( $id, 'panel', get_current_user_id(), 'pv_relanzado' );
			$v = CVD_Pv_Api::viaje( $id );
			$n = CVD_Pv_Reparto::negocio( (int) $v->negocio_id );
			if ( $n && 'te_toca' === $n->asignar ) { CVD_Pv_Reparto::empezar( $v ); } else { CVD_Pv_Api::avisar_choferes( $v, false ); }
			return 'Buscando mensajero otra vez.';
		}
		return '';
	}

	/** Viajes por número o por móvil (quien recibe, cliente o mensajero). */
	public static function buscar( string $q ): array {
		global $wpdb;
		$vt = self::t( 'viajes' );
		$q = trim( $q );
		if ( '' === $q ) { return array(); }
		if ( preg_match( '/^#?(\d{1,9})$/', $q, $m ) && strlen( $m[1] ) < 8 ) { return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$vt} WHERE id=%d", (int) $m[1] ) ); }
		$tel = CVD_Triciclub::telefono( $q );
		if ( '' === $tel ) { return array(); }
		$ids = array_map( 'intval', get_users( array( 'meta_key' => 'cvd_tc_telefono', 'meta_value' => $tel, 'fields' => 'ID', 'number' => 1 ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$cli = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'clientes' ) . ' WHERE telefono=%s', $tel ) );
		$or = $wpdb->prepare( 'recibe_telefono=%s', $tel ) . ( $cli ? $wpdb->prepare( ' OR cliente_id=%d', $cli ) : '' ) . ( $ids ? ' OR chofer_id=' . (int) $ids[0] : '' );
		return $wpdb->get_results( "SELECT * FROM {$vt} WHERE clase<>'' AND ({$or}) ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL -- armado con prepare arriba
	}

	/** Lo que pide atención: sin chofer, no confirmó la salida, problemas de la persona de confianza, deuda al tope. */
	public static function alertas(): array {
		global $wpdb;
		$vt = self::t( 'viajes' );
		$ev = self::t( 'eventos' );
		$desde = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, recoge_texto, destino_texto, updated_at FROM {$vt} WHERE negocio_id>0 AND estado='sin_chofer' AND updated_at>%s ORDER BY id DESC LIMIT 20", $desde ) ) as $r ) {
			$out[] = array( 'tipo' => 'sin_chofer', 'viaje' => (int) $r->id, 'texto' => 'Sin mensajero: ' . $r->recoge_texto . ' → ' . $r->destino_texto, 'cuando' => (string) $r->updated_at );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT viaje_id, actor_id, tipo, datos, created_at FROM {$ev} WHERE tipo IN ('pv_no_confirmo','pv_problema') AND created_at>%s ORDER BY id DESC LIMIT 20", $desde ) ) as $r ) {
			$u = 'pv_no_confirmo' === $r->tipo ? get_userdata( (int) $r->actor_id ) : null;
			$d = json_decode( (string) $r->datos, true );
			$out[] = array( 'tipo' => (string) $r->tipo, 'viaje' => (int) $r->viaje_id, 'texto' => 'pv_no_confirmo' === $r->tipo ? 'No confirmó la salida: ' . ( $u ? $u->display_name : '#' . $r->actor_id ) : 'Problema: ' . ( is_array( $d ) && '' !== ( $d['motivo'] ?? '' ) ? $d['motivo'] : 'sin detalle' ), 'cuando' => (string) $r->created_at );
		}
		$tope = CVD_Pv_Cuenta::tope( 'otros' );
		if ( $tope > 0 ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT chofer_id, SUM(cup) s FROM ' . self::t( 'cuenta' ) . " WHERE estado<>'rechazado' GROUP BY chofer_id HAVING s>=%d LIMIT 20", $tope ) ) as $r ) {
				$u = get_userdata( (int) $r->chofer_id );
				$out[] = array( 'tipo' => 'deuda', 'viaje' => 0, 'texto' => 'Deuda al tope: ' . ( $u ? $u->display_name : '#' . $r->chofer_id ) . ' debe ' . number_format( (int) $r->s, 0, ',', '.' ) . ' CUP', 'cuando' => '' );
			}
		}
		return $out;
	}

	private static function form( string $n, string $accion, string $campos, string $boton, bool $amarillo = false ): string {
		return '<form class="i" method="post">' . $n . '<input type="hidden" name="accion" value="' . esc_attr( $accion ) . '">' . $campos . '<button' . ( $amarillo ? ' class="y"' : '' ) . '>' . esc_html( $boton ) . '</button></form> ';
	}

	private static function oculto( string $k, $v ): string { return '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">'; }

	private static function fila_viaje( object $r, string $n ): string {
		$ch = (int) $r->chofer_id ? get_userdata( (int) $r->chofer_id ) : null;
		$neg = (int) ( $r->negocio_id ?? 0 ) ? CVD_Pv_Reparto::negocio( (int) $r->negocio_id ) : null;
		$quien = $ch ? $ch->display_name : 'sin mensajero';
		if ( $ch && (int) ( $r->lleva_id ?? 0 ) ) { $quien = CVD_Pv_Reparto::nombre_lleva( $r, $ch->display_name ); }
		$h = '<div class="c"><span class="e">' . esc_html( (string) $r->estado ) . '</span> <b>#' . (int) $r->id . '</b> ' . esc_html( $neg ? $neg->nombre : ( CVD_Pv_Tarifa::CLASES[ $r->clase ]['nombre'] ?? $r->clase ) ) . ' · ' . esc_html( self::cup( (int) $r->precio ) )
			. '<br><small>' . esc_html( self::hora( (string) $r->created_at ) ) . ' · ' . esc_html( (string) $r->origen_texto ) . ' → ' . esc_html( (string) $r->destino_texto ) . '<br>'
			. esc_html( $quien ) . ( ! empty( $r->sale_at ) ? ' · sale ' . esc_html( CVD_Pv_Reparto::hora( (string) $r->sale_at ) ) : '' ) . ( '' !== (string) $r->recibe_telefono ? ' · recibe ' . esc_html( $r->recibe_nombre . ' ' . $r->recibe_telefono ) : '' ) . '</small><br>';
		$id = self::oculto( 'id', (int) $r->id );
		if ( in_array( $r->estado, array( 'asignado', 'llego' ), true ) ) { $h .= self::form( $n, 'reasignar', $id, 'Reasignar' ); }
		if ( in_array( $r->estado, array( 'buscando', 'sin_chofer', 'asignado', 'llego' ), true ) ) { $h .= self::form( $n, 'cancelar', $id, 'Cancelar' ); }
		if ( in_array( $r->estado, array( 'asignado', 'llego', 'recogido' ), true ) ) { $h .= self::form( $n, 'entregado', $id, 'Marcar entregado' ); }
		if ( 'sin_chofer' === $r->estado && (int) ( $r->negocio_id ?? 0 ) ) { $h .= self::form( $n, 'relanzar', $id, 'Buscar otra vez', true ); }
		return $h . '</div>';
	}

	public static function html( string $msg, string $q = '' ): string {
		global $wpdb;
		CVD_Pv_Reparto::tick();
		$n = wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$on = CVD_Pv_Reparto::on();
		$h = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Pa\'llá · Reparto</title><style>'
			. 'body{margin:0;font:15px/1.45 system-ui,sans-serif;background:#f4f4f2;color:#0e0e0e}main{max-width:720px;margin:0 auto;padding:16px}h1{font-size:22px;margin:4px 0 12px}h2{font-size:16px;margin:22px 0 8px}'
			. '.c{background:#fff;border-radius:12px;padding:10px 12px;margin:6px 0;overflow-wrap:anywhere}.c small{color:#62625d}.m{background:#fff4d1;border-radius:10px;padding:8px 12px;overflow-wrap:anywhere}.a{background:#ffe1dc}form.i{display:inline}button{font:inherit;border:0;border-radius:99px;padding:6px 12px;background:#e9e9e6;cursor:pointer;margin:2px 0}button.y{background:#ffc21a}'
			. 'input,select{font:inherit;padding:6px 8px;border:1px solid #d2d2cb;border-radius:8px;width:100%;box-sizing:border-box}label{display:block;margin:6px 0}.e{display:inline-block;font-size:12px;border-radius:99px;padding:1px 8px;background:#e9e9e6}.g{display:grid;grid-template-columns:1fr 1fr;gap:0 8px}a{color:#2c5bc7}'
			. '</style></head><body><main><h1>Pa\'llá · Reparto</h1><p><small><a href="' . esc_url( home_url( '/palla/admin/' ) ) . '">← Panel de Pa\'llá</a></small></p>';
		if ( $msg ) { $h .= '<p class="m">' . esc_html( $msg ) . '</p>'; }
		$h .= '<div class="c"><b>Reparto:</b> <span class="e">' . ( $on ? 'encendido' : 'apagado' ) . '</span> '
			. self::form( $n, 'reparto', self::oculto( 'modo', $on ? 'no' : 'si' ), $on ? 'Apagar' : 'Encender', ! $on )
			. '<br><small>Apagado, Pa\'llá transporte y Casa Viva no cambian. Interruptor general de Pa\'llá: ' . esc_html( CVD_Pv::mode() ) . '.</small></div>';

		// Alertas
		$al = $on ? self::alertas() : array();
		$h .= '<h2>Alertas (' . count( $al ) . ')</h2>';
		if ( ! $al ) { $h .= '<p><small>Nada pendiente.</small></p>'; }
		foreach ( $al as $x ) { $h .= '<div class="c a">' . esc_html( $x['texto'] ) . ( $x['viaje'] ? ' · <a href="?q=' . (int) $x['viaje'] . '">#' . (int) $x['viaje'] . '</a>' : '' ) . ( '' !== $x['cuando'] ? ' <small>' . esc_html( self::hora( $x['cuando'] ) ) . '</small>' : '' ) . '</div>'; }

		// Buscar
		$h .= '<h2>Buscar un viaje</h2><form class="c" method="get"><label>Número del viaje o móvil (quien recibe, cliente o mensajero)<input name="q" value="' . esc_attr( $q ) . '"></label><button class="y">Buscar</button></form>';
		if ( '' !== $q ) {
			$res = self::buscar( $q );
			if ( ! $res ) { $h .= '<p><small>Nada con «' . esc_html( $q ) . '».</small></p>'; }
			foreach ( $res as $r ) { $h .= self::fila_viaje( $r, $n ); }
		}

		// Negocios y puntos
		$negs = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'negocios' ) . ' ORDER BY id' );
		$h .= '<h2>Negocios (' . count( $negs ) . ')</h2>';
		foreach ( $negs as $g ) {
			$h .= '<div class="c"><b>#' . (int) $g->id . ' ' . esc_html( (string) $g->nombre ) . '</b> <span class="e">' . esc_html( (string) $g->tipo ) . '</span>' . ( (int) $g->activo ? '' : ' <span class="e">parado</span>' )
				. '<br><small>Envío lo paga: ' . esc_html( (string) $g->paga_envio ) . ' · cobro: ' . esc_html( (string) $g->cobro ) . ' · asignar: ' . esc_html( (string) $g->asignar ) . ' · comisión: ' . esc_html( null === $g->comision_pct ? 'la general' : $g->comision_pct . ' %' ) . ' · ' . esc_html( (string) $g->telefono ) . '</small>';
			foreach ( CVD_Pv_Reparto::puntos_de( (int) $g->id ) as $pt ) {
				$h .= '<br><small>📍 #' . (int) $pt->id . ' ' . esc_html( $pt->nombre . ' · ' . $pt->direccion ) . ' · ' . esc_html( substr( (string) $pt->desde, 0, 5 ) . '–' . substr( (string) $pt->hasta, 0, 5 ) ) . ' · días ' . esc_html( (string) $pt->dias ) . '</small>';
			}
			$h .= '<br>' . self::form( $n, 'clave', self::oculto( 'id', (int) $g->id ), 'Clave nueva' ) . self::form( $n, (int) $g->activo ? 'negocio_off' : 'negocio_on', self::oculto( 'id', (int) $g->id ), (int) $g->activo ? 'Parar' : 'Activar' ) . '</div>';
		}
		$sel = static fn( string $name, array $ops ): string => '<select name="' . esc_attr( $name ) . '">' . implode( '', array_map( static fn( string $o ): string => '<option>' . esc_html( $o ) . '</option>', $ops ) ) . '</select>';
		$h .= '<details class="c"><summary>Nuevo negocio</summary><form method="post">' . $n . self::oculto( 'accion', 'negocio' )
			. '<label>Nombre<input name="nombre" required></label><div class="g"><label>Tipo' . $sel( 'tipo', CVD_Pv_Reparto::TIPOS ) . '</label><label>Móvil<input name="telefono"></label>'
			. '<label>El envío lo paga' . $sel( 'paga_envio', CVD_Pv_Reparto::PAGA ) . '</label><label>Cobro' . $sel( 'cobro', CVD_Pv_Reparto::COBRO ) . '</label>'
			. '<label>Asignar' . $sel( 'asignar', CVD_Pv_Reparto::ASIGNAR ) . '</label><label>Comisión % (vacío = la general)<input name="comision_pct" inputmode="numeric"></label></div><button class="y">Crear</button></form></details>';
		$opsn = implode( '', array_map( static fn( object $g ): string => '<option value="' . (int) $g->id . '">' . esc_html( (string) $g->nombre ) . '</option>', $negs ) );
		$h .= '<details class="c"><summary>Nuevo punto de recogida</summary><form method="post">' . $n . self::oculto( 'accion', 'punto' )
			. '<label>Negocio<select name="negocio_id">' . $opsn . '</select></label><label>Nombre<input name="nombre"></label><label>Dirección<input name="direccion"></label>'
			. '<div class="g"><label>Lat<input name="lat" inputmode="decimal"></label><label>Lng<input name="lng" inputmode="decimal"></label><label>Abre (HH:MM)<input name="desde"></label><label>Cierra (HH:MM)<input name="hasta"></label>'
			. '<label>Días (1=lunes … 7=domingo)<input name="dias" value="1234567"></label><label>Móvil del punto<input name="telefono"></label></div><button class="y">Guardar</button></form></details>';

		// Personas de confianza
		$conf = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'confianza' ) . " WHERE estado IN ('pendiente','aprobada') ORDER BY estado='pendiente' DESC, id DESC LIMIT 50" );
		$h .= '<h2>Personas de confianza (' . count( $conf ) . ')</h2>';
		if ( ! $conf ) { $h .= '<p><small>Ninguna.</small></p>'; }
		foreach ( $conf as $c ) {
			$tit = get_userdata( (int) $c->chofer_id );
			$id = self::oculto( 'id', (int) $c->id );
			$h .= '<div class="c"><span class="e">' . esc_html( (string) $c->estado ) . '</span> <b>' . esc_html( (string) $c->nombre ) . '</b> · ' . esc_html( (string) $c->telefono ) . ' · carné …' . esc_html( (string) $c->carne4 )
				. '<br><small>de ' . esc_html( $tit ? $tit->display_name : '#' . $c->chofer_id ) . ' · ' . esc_html( self::hora( (string) $c->created_at ) ) . '</small><br>'
				. ( 'pendiente' === $c->estado ? self::form( $n, 'aprobar', $id, 'Aprobar', true ) . self::form( $n, 'rechazar', $id, 'Rechazar' ) : self::form( $n, 'enlace', $id, 'Ver enlace' ) . self::form( $n, 'anular', $id, 'Anular' ) ) . '</div>';
		}

		// Encargo a mano
		$h .= '<h2>Encargo a mano</h2><details class="c"><summary>Crear encargo</summary><form method="post">' . $n . self::oculto( 'accion', 'encargo' )
			. '<label>Negocio<select name="negocio_id">' . $opsn . '</select></label><label>Punto de recogida (#)<input name="punto_id" inputmode="numeric"></label>'
			. '<label>Entregar en<input name="entrega"></label><div class="g"><label>Lat<input name="lat" inputmode="decimal"></label><label>Lng<input name="lng" inputmode="decimal"></label>'
			. '<label>Recibe<input name="recibe"></label><label>Su móvil<input name="telefono"></label><label>Qué<input name="que"></label><label>Tamaño' . $sel( 'tam', array_keys( CVD_Pv_Tarifa::TAMANOS ) ) . '</label>'
			. '<label>Cobrar producto (CUP)<input name="cobrar_cup" inputmode="numeric"></label><label>Nota<input name="nota"></label><label>Desde (HH:MM)<input name="desde"></label><label>Hasta (HH:MM)<input name="hasta"></label></div>'
			. '<button class="y">Crear encargo</button></form></details>';

		// Anular enlace de un chofer
		$h .= '<h2>Teléfono perdido</h2>' . '<div class="c">' . self::form( $n, 'anular_chofer', '<label>Móvil o número del chofer<input name="uid"></label>', 'Anular sus enlaces' ) . '</div>';

		// Últimos encargos
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'viajes' ) . ' WHERE negocio_id>0 ORDER BY id DESC LIMIT 20' );
		$h .= '<h2>Últimos encargos</h2>';
		if ( ! $rows ) { $h .= '<p><small>Todavía ninguno.</small></p>'; }
		foreach ( $rows as $r ) { $h .= self::fila_viaje( $r, $n ); }

		// Ajustes
		$h .= '<h2>Ajustes</h2><form class="c" method="post">' . $n . self::oculto( 'accion', 'ajustes' )
			. '<label>Tope de encargos abiertos o programados por mensajero<input name="tope" inputmode="numeric" value="' . (int) CVD_Pv_Reparto::tope() . '"></label>'
			. '<label>Radio de «Te toca» en metros<input name="radio" inputmode="numeric" value="' . (int) CVD_Pv_Reparto::radio() . '"></label><button class="y">Guardar</button></form>';
		return $h . '</main></body></html>';
	}
}
