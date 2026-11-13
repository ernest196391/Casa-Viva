<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026): el panel de Ernesto en /palla/admin (entra con el usuario administrador de la web).
 * HTML del servidor, sin JavaScript, ligero para datos móviles. Lo de los choferes (altas, fotos, parar) sigue en
 * /triciclub/admin; aquí: el interruptor, números de hoy, viajes con sus ofertas, pagos por confirmar y los ajustes.
 */
final class CVD_Pv_Panel {

	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
	}

	public static function route(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
		if ( '/palla/admin' !== rtrim( $path, '/' ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá: panel' );
		nocache_headers();
		if ( ! CVD_Triciclub_Alta::puede_panel() ) { wp_safe_redirect( wp_login_url( home_url( '/palla/admin/' ) ) ); exit; }
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$msg = self::acciones();
		echo self::html( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* abajo.
		exit;
	}

	/** Botones del panel (con nonce). Devuelve el aviso para enseñar. */
	private static function acciones(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! check_admin_referer( 'cvd_pv_panel' ) ) { return ''; }
		$a = sanitize_key( (string) ( $_POST['accion'] ?? '' ) );
		if ( 'pago_ok' === $a || 'pago_no' === $a ) {
			$ok = CVD_Pv_Cuenta::revisar_pago( absint( $_POST['id'] ?? 0 ), 'pago_ok' === $a, get_current_user_id() );
			return $ok ? ( 'pago_ok' === $a ? '✅ Pago confirmado.' : '⛔ Pago rechazado: vuelve a contar como deuda.' ) : 'Ese pago ya estaba revisado.';
		}
		if ( 'modo' === $a ) {
			$m = sanitize_key( (string) ( $_POST['modo'] ?? '' ) );
			if ( in_array( $m, array( 'no', 'prueba', 'si' ), true ) ) { update_option( CVD_Pv::OPTION, $m, true ); return 'Interruptor: ' . $m . '.'; }
		}
		if ( 'ajustes' === $a ) {
			update_option( 'cvd_pv_comision_pct', max( 0, min( 30, absint( $_POST['pct'] ?? 0 ) ) ), false );
			$tope = sanitize_text_field( wp_unslash( (string) ( $_POST['tope'] ?? '' ) ) );
			if ( '' !== $tope ) { update_option( 'cvd_pv_tope', $tope, false ); }
			update_option( 'cvd_pv_cobro', mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['cobro'] ?? '' ) ) ), 0, 40 ), false );
			return 'Ajustes guardados.';
		}
		return '';
	}

	private static function cup( int $n ): string { return number_format( $n, 0, ',', '.' ) . ' CUP'; }

	private static function hora( ?string $utc ): string {
		return $utc ? ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'd/m H:i' ) : '';
	}

	private static function html( string $msg ): string {
		global $wpdb;
		$v = CVD_Triciclub_Db::t( 'viajes' );
		$of = CVD_Triciclub_Db::t( 'ofertas' );
		$inicio = ( new DateTimeImmutable( 'today', new DateTimeZone( 'America/Havana' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$hoy = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) pedidos, SUM(estado='terminado') hechos, COALESCE(SUM(IF(estado='terminado',precio+espera_cup,0)),0) cup, COALESCE(SUM(comision_cup),0) com FROM {$v} WHERE clase<>'' AND created_at>=%s", $inicio ) );
		$ofertas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$of} WHERE created_at>=%s", $inicio ) );
		$en_linea = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CVD_Triciclub_Db::t( 'choferes' ) . " WHERE estado='aprobado' AND disponible=1 AND ubicado_at>%s", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$n = wp_nonce_field( 'cvd_pv_panel', '_wpnonce', true, false );
		$modo = CVD_Pv::mode();
		$h = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Pa\'llá · panel</title><style>'
			. 'body{margin:0;font:15px/1.45 system-ui,sans-serif;background:#f4f4f2;color:#0e0e0e}main{max-width:680px;margin:0 auto;padding:16px}h1{font-size:22px;margin:4px 0 12px}h2{font-size:16px;margin:22px 0 8px}'
			. '.n{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.n div{background:#fff;border-radius:12px;padding:10px;font-size:12px;color:#62625d;text-align:center}.n b{display:block;font-size:20px;color:#0e0e0e}'
			. '.c{background:#fff;border-radius:12px;padding:10px 12px;margin:6px 0}.c small{color:#62625d}.m{background:#fff4d1;border-radius:10px;padding:8px 12px}form.i{display:inline}button{font:inherit;border:0;border-radius:99px;padding:6px 12px;background:#e9e9e6;cursor:pointer}button.y{background:#ffc21a}'
			. 'input{font:inherit;padding:6px 8px;border:1px solid #d2d2cb;border-radius:8px;width:100%;box-sizing:border-box}label{display:block;margin:6px 0}.e{display:inline-block;font-size:12px;border-radius:99px;padding:1px 8px;background:#e9e9e6}a{color:#2c5bc7}'
			. '</style></head><body><main><h1>Pa\'llá · panel</h1>';
		if ( $msg ) { $h .= '<p class="m">' . esc_html( $msg ) . '</p>'; }
		$h .= '<div class="c"><b>Interruptor:</b> <span class="e">' . esc_html( $modo ) . '</span> ';
		foreach ( array( 'no' => 'Apagar', 'prueba' => 'Prueba', 'si' => 'Abrir a todos' ) as $k => $t ) {
			if ( $k !== $modo ) { $h .= '<form class="i" method="post">' . $n . '<input type="hidden" name="accion" value="modo"><input type="hidden" name="modo" value="' . esc_attr( $k ) . '"><button' . ( 'si' === $k ? ' onclick="return confirm(\'¿Abrir Pa&#39;llá a TODOS los clientes y choferes?\')"' : '' ) . '>' . esc_html( $t ) . '</button></form> '; }
		}
		$h .= '<br><small>Cliente: <a href="' . esc_url( home_url( '/palla/ir/' ) ) . '">/palla/ir/</a> · Chofer: <a href="' . esc_url( home_url( '/palla/chofer/' ) ) . '">/palla/chofer/</a> · Choferes y altas: <a href="' . esc_url( home_url( '/triciclub/admin/' ) ) . '">/triciclub/admin</a></small></div>';
		$h .= '<div class="n"><div><b>' . (int) $hoy->pedidos . '</b>pedidos hoy</div><div><b>' . (int) $hoy->hechos . '</b>viajes hechos</div><div><b>' . $ofertas . '</b>ofertas</div><div><b>' . $en_linea . '</b>choferes en línea</div></div>';
		$h .= '<p><small>Movido hoy: ' . esc_html( self::cup( (int) $hoy->cup ) ) . ' · comisión: ' . esc_html( self::cup( (int) $hoy->com ) ) . '</small></p>';

		// Pagos por confirmar
		$pend = CVD_Pv_Cuenta::pendientes();
		$h .= '<h2>Pagos de choferes por confirmar (' . count( $pend ) . ')</h2>';
		if ( ! $pend ) { $h .= '<p><small>Ninguno.</small></p>'; }
		foreach ( $pend as $p ) {
			$u = get_userdata( (int) $p->chofer_id );
			$h .= '<div class="c"><b>' . esc_html( $u ? $u->display_name : '#' . $p->chofer_id ) . '</b> · ' . esc_html( self::cup( abs( (int) $p->cup ) ) ) . ' · transferencia <b>' . esc_html( (string) $p->ref ) . '</b><br><small>' . esc_html( self::hora( (string) $p->created_at ) ) . ' · debe ahora ' . esc_html( self::cup( max( 0, CVD_Pv_Cuenta::saldo( (int) $p->chofer_id ) ) ) ) . '</small><br>'
				. '<form class="i" method="post">' . $n . '<input type="hidden" name="accion" value="pago_ok"><input type="hidden" name="id" value="' . (int) $p->id . '"><button class="y">Llegó, confirmar</button></form> '
				. '<form class="i" method="post">' . $n . '<input type="hidden" name="accion" value="pago_no"><input type="hidden" name="id" value="' . (int) $p->id . '"><button>No llegó</button></form></div>';
		}

		// Viajes
		$rows = $wpdb->get_results( "SELECT * FROM {$v} WHERE clase<>'' ORDER BY id DESC LIMIT 30" );
		$h .= '<h2>Últimos pedidos</h2>';
		if ( ! $rows ) { $h .= '<p><small>Todavía ninguno.</small></p>'; }
		foreach ( $rows as $r ) {
			$cli = CVD_Triciclub::cliente( (int) $r->cliente_id );
			$ch = (int) $r->chofer_id ? get_userdata( (int) $r->chofer_id ) : null;
			$n_of = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$of} WHERE viaje_id=%d", (int) $r->id ) );
			$h .= '<div class="c"><span class="e">' . esc_html( (string) $r->estado ) . '</span> <b>' . esc_html( CVD_Pv_Tarifa::CLASES[ $r->clase ]['nombre'] ?? $r->clase ) . '</b> · ' . esc_html( self::cup( (int) $r->precio + (int) $r->espera_cup ) )
				. ' <small>(justo ' . esc_html( self::cup( (int) $r->precio_justo ) ) . ', ofreció ' . esc_html( self::cup( (int) $r->precio_oferta ) ) . ', ' . $n_of . ' ofertas)</small><br><small>'
				. esc_html( self::hora( (string) $r->created_at ) ) . ' · ' . esc_html( $cli ? $cli->nombre . ' ' . $cli->telefono : '' ) . ' → ' . esc_html( $ch ? $ch->display_name : 'sin chofer' ) . '<br>'
				. esc_html( (string) $r->origen_texto ) . ' → ' . esc_html( (string) $r->destino_texto ) . ' · ' . esc_html( (string) round( (int) $r->distancia_m / 1000, 1 ) ) . ' km · ' . esc_html( (string) $r->forma_pago ) . '</small></div>';
		}

		// Ajustes
		$tope = get_option( 'cvd_pv_tope', CVD_Pv_Cuenta::TOPE );
		$h .= '<h2>Ajustes</h2><form class="c" method="post">' . $n . '<input type="hidden" name="accion" value="ajustes">'
			. '<label>Comisión % (0 en el piloto y 2 meses; luego 8)<input name="pct" inputmode="numeric" value="' . esc_attr( (string) CVD_Pv_Cuenta::pct() ) . '"></label>'
			. '<label>Tope de deuda en CUP (un número, o por tipo: {"taxi":15000,"tric":6000,"otros":3000})<input name="tope" value="' . esc_attr( is_array( $tope ) ? wp_json_encode( $tope ) : (string) $tope ) . '"></label>'
			. '<label>Tarjeta o móvil de Pa\'llá para Transfermóvil<input name="cobro" value="' . esc_attr( (string) get_option( 'cvd_pv_cobro', '' ) ) . '"></label>'
			. '<button class="y">Guardar</button></form>';
		$h .= '<p><small>Precios (D140): ' . esc_html( implode( ' · ', array_map( static fn( string $k, array $t ): string => CVD_Pv_Tarifa::CLASES[ $k ]['nombre'] . ' ' . number_format( $t[0], 0, ',', '.' ) . '/' . $t[1] . ' km + ' . $t[2], array_keys( CVD_Pv_Tarifa::tabla() ), CVD_Pv_Tarifa::tabla() ) ) ) . '. Noche +20 %. Espera 5 min gratis, luego 50 CUP/min.</small></p>';
		return $h . '</main></body></html>';
	}
}
