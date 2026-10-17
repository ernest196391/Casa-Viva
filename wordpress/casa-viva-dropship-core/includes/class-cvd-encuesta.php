<?php

defined( 'ABSPATH' ) || exit;

/**
 * Encuesta anónima de Casa Viva (3.14.35, Ernesto 16-oct). Página pública casaviva.company/encuesta/.
 *
 * - Sin cuenta: la página no lee la sesión de nadie y el envío va sin cookies (fetch con credentials:'omit').
 * - Qué se guarda (tabla {prefix}cvd_encuesta): id al azar (no correlativo, para que no se sepa el orden de llegada),
 *   el DÍA (no la hora), papel, origen (de qué enlace vino), versión de la encuesta, tipo (respuesta | nota),
 *   prueba (0/1) y las respuestas en JSON. NO se guarda: usuario, IP, navegador, hora.
 * - Una vez por teléfono: marca en localStorage (no identifica a nadie). Si vuelve: «Ya la contestaste; ¿quieres
 *   añadir algo?» y lo que añada se guarda como «nota» aparte, sin unir con su respuesta.
 * - Antispam: campo trampa (honeypot) y un límite por minuto con un transient cuya clave es un hash de la IP con una
 *   sal que cambia cada día (la IP no se guarda; el transient caduca en 10 minutos).
 * - Opción cvd_encuesta_estado = abierta | cerrada (se crea CERRADA). Cerrada, la página dice «Todavía no está
 *   abierta», salvo con ?prueba=<cvd_encuesta_clave_prueba>: entonces se puede contestar y la fila queda prueba=1
 *   (no cuenta en los resultados).
 * - Parámetros: ?p=gestora|mensajero|tienda (salta el paso 0) y ?o=general|mensajerias|privado (de qué enlace vino).
 * - Resultados: wp-admin → Casa Viva → Encuesta (barras, medias, NPS, % de aciertos de 12a/12b, textos, CSV).
 *   Todo en JSON para Claude: wp eval 'echo CVD_Encuesta::export_json();'
 */
final class CVD_Encuesta {
	public const OPT_ESTADO = 'cvd_encuesta_estado';
	public const OPT_CLAVE = 'cvd_encuesta_clave_prueba';
	public const OPT_GRACIAS = 'cvd_encuesta_gracias';
	private const OPT_DB = 'cvd_encuesta_db';
	private const DB_VERSION = '1';
	private const PAGE = 'cvd-encuesta';
	private const SLUG = 'encuesta';
	private const LIMITE_MINUTO = 5;
	private const GRACIAS = 'Gracias 💛 El día 21 publicamos lo que nos dijeron y lo que vamos a cambiar.';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 6 );
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_cvd_encuesta', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_post_cvd_encuesta_csv', array( __CLASS__, 'csv' ) );
	}

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'cvd_encuesta'; }

	public static function maybe_install(): void {
		if ( get_option( self::OPT_DB ) === self::DB_VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id char(16) NOT NULL,
			fecha date NOT NULL,
			papel varchar(16) NOT NULL DEFAULT '',
			origen varchar(16) NOT NULL DEFAULT '',
			version_encuesta varchar(32) NOT NULL DEFAULT '',
			tipo varchar(12) NOT NULL DEFAULT 'respuesta',
			prueba tinyint(1) NOT NULL DEFAULT 0,
			respuestas longtext NULL,
			PRIMARY KEY  (id),
			KEY papel (papel),
			KEY prueba (prueba)
		) {$wpdb->get_charset_collate()};" );
		add_option( self::OPT_ESTADO, 'cerrada', '', false ); // nace cerrada: la abre Ernesto
		add_option( self::OPT_CLAVE, strtolower( wp_generate_password( 16, false, false ) ), '', false );
		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	public static function is_open(): bool { return 'abierta' === get_option( self::OPT_ESTADO, 'cerrada' ); }

	public static function url( array $args = array() ): string { return add_query_arg( $args, home_url( '/' . self::SLUG . '/' ) ); }

	public static function test_url(): string { return self::url( array( 'prueba' => (string) get_option( self::OPT_CLAVE, '' ) ) ); }

	/** ¿La clave de prueba que llega es la buena? */
	public static function is_test( string $given ): bool {
		$key = (string) get_option( self::OPT_CLAVE, '' );
		return '' !== $key && '' !== $given && hash_equals( $key, $given );
	}

	/* ================================================================ página pública */

	public static function route( $wp ): void {
		if ( ! is_object( $wp ) || self::SLUG !== trim( (string) $wp->request, '/' ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: encuesta anónima' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		$given = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['prueba'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$prueba = self::is_test( $given );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$res = self::save( wp_unslash( $_POST ), $prueba, self::client_ip() ); // phpcs:ignore WordPress.Security.NonceVerification -- página pública y anónima: no hay sesión que proteger.
			if ( ! empty( $_POST['ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				wp_send_json( $res, $res['ok'] ? 200 : ( $res['code'] ?? 400 ) );
			}
			self::shell( $res['ok'] ? '<div class="card done"><h1>¡Recibido!</h1><p>' . esc_html( self::gracias() ) . '</p></div>' : '<div class="card"><h1>No se pudo guardar</h1><p>' . esc_html( $res['msg'] ?? '' ) . '</p><p><a class="btn" href="' . esc_url( self::url() ) . '">Volver</a></p></div>', $prueba );
		}
		if ( ! self::is_open() && ! $prueba ) {
			self::shell( '<div class="card closed"><h1>Todavía no está abierta</h1><p>La encuesta de Casa Viva aún no ha empezado. Vuelve a abrir el enlace cuando te avisemos por WhatsApp.</p></div>', false );
		}
		$papel = sanitize_key( (string) ( $_GET['p'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$origen = sanitize_key( (string) ( $_GET['o'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::shell( self::form( CVD_Encuesta_Def::is_papel( $papel ) ? $papel : '', in_array( $origen, CVD_Encuesta_Def::ORIGENES, true ) ? $origen : '', $prueba ? $given : '' ), $prueba );
	}

	public static function gracias(): string {
		$t = trim( (string) get_option( self::OPT_GRACIAS, '' ) );
		return '' !== $t ? $t : self::GRACIAS;
	}

	/** IP solo para el límite por minuto: nunca se guarda tal cual (ver rate_ok). */
	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$fwd = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ); // la web va detrás del CDN de Hostinger
		if ( '' !== $fwd ) { $ip = trim( explode( ',', $fwd )[0] ) . '|' . $ip; }
		return $ip;
	}

	/** Límite simple: LIMITE_MINUTO envíos por minuto con la misma IP. Clave = hash de la IP + sal del día; caduca en 10 min. */
	public static function rate_ok( string $ip ): bool {
		if ( '' === $ip ) { return true; }
		$day = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$key = 'cvd_enc_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) . '|encuesta|' . $day ), 0, 24 ) . '_' . gmdate( 'YmdHi' );
		$n = (int) get_transient( $key );
		if ( $n >= self::LIMITE_MINUTO ) { return false; }
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Guarda una respuesta o una nota. $post ya sin barras. Devuelve ['ok'=>bool, 'msg'=>..., 'code'=>http].
	 * Lo único que se escribe en la base de datos es lo que se ve en $row: ni usuario, ni IP, ni hora.
	 */
	public static function save( array $post, bool $prueba, string $ip ): array {
		if ( '' !== trim( (string) ( $post['web'] ?? '' ) ) ) { return array( 'ok' => true ); } // campo trampa: un robot lo rellena; se le dice «ok» y no se guarda
		if ( ! $prueba && ! self::is_open() ) { return array( 'ok' => false, 'msg' => 'La encuesta todavía no está abierta.', 'code' => 403 ); }
		$papel = sanitize_key( (string) ( $post['papel'] ?? '' ) );
		if ( ! CVD_Encuesta_Def::is_papel( $papel ) ) { return array( 'ok' => false, 'msg' => 'Elige qué haces en Casa Viva.', 'code' => 400 ); }
		$origen = sanitize_key( (string) ( $post['origen'] ?? '' ) );
		if ( ! in_array( $origen, CVD_Encuesta_Def::ORIGENES, true ) ) { $origen = ''; }
		$tipo = 'nota' === ( $post['tipo'] ?? '' ) ? 'nota' : 'respuesta';
		if ( 'nota' === $tipo ) {
			$nota = CVD_Encuesta_Def::clean_text( $post['nota'] ?? '', CVD_Encuesta_Def::MAX_TEXT );
			if ( '' === $nota ) { return array( 'ok' => false, 'msg' => 'Escribe lo que quieras añadir.', 'code' => 400 ); }
			$respuestas = array( 'nota' => $nota );
		} else {
			$respuestas = CVD_Encuesta_Def::sanitize( $papel, is_array( $post['r'] ?? null ) ? $post['r'] : array() );
		}
		if ( ! self::rate_ok( $ip ) ) { return array( 'ok' => false, 'msg' => 'Espera un minuto y vuelve a tocar «Enviar».', 'code' => 429 ); }
		global $wpdb;
		$row = array(
			'id'               => bin2hex( random_bytes( 8 ) ),
			'fecha'            => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'papel'            => $papel,
			'origen'           => $origen,
			'version_encuesta' => CVD_Encuesta_Def::VERSION,
			'tipo'             => $tipo,
			'prueba'           => $prueba ? 1 : 0,
			'respuestas'       => wp_json_encode( (object) $respuestas, JSON_UNESCAPED_UNICODE ),
		);
		if ( ! $wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) ) ) {
			return array( 'ok' => false, 'msg' => 'No se pudo guardar. Prueba otra vez en un momento.', 'code' => 500 );
		}
		return array( 'ok' => true );
	}

	/* ---------------------------------------------------------------- HTML de la encuesta */

	private static function opt_input( string $type, string $name, string $value, string $label, bool $text, string $id ): string {
		$h = '<label class="opt"><input type="' . $type . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $text ? ' data-text="1"' : '' ) . '><span>' . esc_html( $label ) . '</span></label>';
		if ( $text ) {
			$h .= '<input class="otra" type="text" name="r[' . esc_attr( $id ) . '_txt]" maxlength="' . CVD_Encuesta_Def::MAX_OTRA . '" placeholder="' . esc_attr( 'otra' === $value ? 'Escribe cuál' : 'Escríbelo aquí' ) . '" aria-label="' . esc_attr( $label ) . '">';
		}
		return $h;
	}

	private static function question( array $q ): string {
		$id = $q['id'];
		$attrs = ' data-block="' . esc_attr( $q['block'] ) . '" data-type="' . esc_attr( $q['type'] ) . '"';
		if ( ! empty( $q['if'] ) ) { $attrs .= ' data-if="' . esc_attr( $q['if'][0] . ':' . implode( ',', $q['if'][1] ) ) . '"'; }
		if ( ! empty( $q['max'] ) ) { $attrs .= ' data-max="' . (int) $q['max'] . '"'; }
		if ( ! empty( $q['exclusive'] ) ) { $attrs .= ' data-excl="' . esc_attr( implode( ',', $q['exclusive'] ) ) . '"'; }
		$hint = $q['hint'] ?? '';
		if ( 'multi' === $q['type'] ) { $hint = ! empty( $q['max'] ) ? 'Marca hasta ' . (int) $q['max'] . '.' : 'Puedes marcar varias.'; }
		$h = '<fieldset class="step q"' . $attrs . ' data-q="' . esc_attr( $id ) . '"><legend>' . esc_html( $q['label'] ) . '</legend>';
		if ( '' !== $hint ) { $h .= '<p class="hint">' . esc_html( $hint ) . '</p>'; }
		switch ( $q['type'] ) {
			case 'single':
			case 'multi':
				$h .= '<div class="opts">';
				foreach ( $q['options'] as $o ) {
					$h .= self::opt_input( 'single' === $q['type'] ? 'radio' : 'checkbox', 'single' === $q['type'] ? 'r[' . $id . ']' : 'r[' . $id . '][]', (string) $o['key'], $o['label'], ! empty( $o['text'] ), $id );
				}
				$h .= '</div>';
				break;
			case 'scale5':
			case 'scale10':
				[ $min, $max ] = 'scale5' === $q['type'] ? array( 1, 5 ) : array( 0, 10 );
				$h .= '<div class="scale s' . ( $max - $min + 1 ) . '">';
				for ( $i = $min; $i <= $max; $i++ ) { $h .= '<label><input type="radio" name="r[' . esc_attr( $id ) . ']" value="' . $i . '"><span>' . $i . '</span></label>'; }
				$h .= '</div><div class="ends"><span>' . $min . ' = ' . esc_html( $q['min'] ?? '' ) . '</span><span>' . $max . ' = ' . esc_html( $q['max'] ?? '' ) . '</span></div>';
				break;
			case 'text':
				$h .= '<textarea name="r[' . esc_attr( $id ) . ']" rows="5" maxlength="' . CVD_Encuesta_Def::MAX_TEXT . '" placeholder="Escribe aquí (si quieres)"></textarea>';
				break;
		}
		return $h . '</fieldset>';
	}

	private static function form( string $papel, string $origen, string $clave ): string {
		$k = 'cvd_encuesta_' . CVD_Encuesta_Def::VERSION . ( '' !== $clave ? '_prueba' : '' );
		$action = self::url( '' !== $clave ? array( 'prueba' => $clave ) : array() );
		$h = '<div id="intro" class="card intro"><h1>Encuesta anónima de Casa Viva</h1>'
			. '<p>3 minutos. No pedimos nombre ni teléfono. Queremos saber qué mejorar. Puedes elegir opciones o escribir lo tuyo.</p>'
			. '<p class="priv"><b>Se guarda:</b> tus respuestas, lo que haces en Casa Viva y el día.<br><b>No se guarda:</b> tu nombre, tu teléfono, tu cuenta, tu IP ni la hora.</p></div>';
		$h .= '<form id="enc" class="enc" method="post" action="' . esc_url( $action ) . '" data-k="' . esc_attr( $k ) . '" novalidate>';
		$h .= '<input type="hidden" name="origen" value="' . esc_attr( $origen ) . '">';
		$h .= '<div class="hp" aria-hidden="true"><label>No escribas aquí <input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>';
		if ( '' !== $papel ) {
			$h .= '<input type="hidden" name="papel" value="' . esc_attr( $papel ) . '">';
		} else {
			$h .= '<fieldset class="step q" data-block="papel" data-type="single" data-q="papel"><legend>¿Qué haces en Casa Viva?</legend><div class="opts">';
			foreach ( CVD_Encuesta_Def::PAPELES as $key => $label ) { $h .= self::opt_input( 'radio', 'papel', $key, $label, false, 'papel' ); }
			$h .= '</div></fieldset>';
			$h .= '<noscript><p class="card">Contesta solo las preguntas de lo que haces (gestora, mensajero o tienda) y las del principio y el final.</p></noscript>';
		}
		$seen = array();
		foreach ( CVD_Encuesta_Def::questions() as $q ) {
			if ( '' !== $papel && ! in_array( $q['block'], array( 'comun', $papel, 'final' ), true ) ) { continue; }
			if ( 'comun' !== $q['block'] && 'final' !== $q['block'] && ! isset( $seen[ $q['block'] ] ) && '' === $papel ) {
				$seen[ $q['block'] ] = true;
				$h .= '<noscript><h2 class="blk">' . esc_html( CVD_Encuesta_Def::PAPELES[ $q['block'] ] ) . '</h2></noscript>';
			}
			$h .= self::question( $q );
		}
		$h .= '<div class="nojs-send"><button class="btn big" type="submit">Enviar</button></div>';
		$h .= '<p id="msg" class="msg" role="status" hidden></p>';
		$h .= '<div class="nav" id="nav" hidden><button type="button" class="btn ghost" id="back">Atrás</button><button type="button" class="btn" id="next">Siguiente</button></div>';
		$h .= '</form>';
		// Pantallas que enseña el JS: gracias y «ya la contestaste».
		$h .= '<div id="done" class="card done" hidden><h1>¡Recibido!</h1><p>' . esc_html( self::gracias() ) . '</p></div>';
		$h .= '<form id="ya" class="card" method="post" action="' . esc_url( $action ) . '" hidden><h1>Ya la contestaste</h1><p>¿Quieres añadir algo? Se guarda aparte, también sin nombre.</p>'
			. '<input type="hidden" name="tipo" value="nota"><input type="hidden" name="papel" value=""><input type="hidden" name="origen" value="' . esc_attr( $origen ) . '">'
			. '<div class="hp" aria-hidden="true"><input type="text" name="web" tabindex="-1" autocomplete="off"></div>'
			. '<textarea name="nota" rows="5" maxlength="' . CVD_Encuesta_Def::MAX_TEXT . '" placeholder="Escribe aquí"></textarea>'
			. '<p class="msg" hidden></p><button class="btn big" type="submit">Añadir</button></form>';
		return $h;
	}

	private static function css(): string {
		return ':root{--bg:#f6f1e6;--card:#fffdf9;--ink:#25231f;--muted:#6f6a62;--line:#e8e1d8;--brand:#0f3d3a;--gold:#c39443;--soft:#e4ece8;--bad:#9a392f}'
			. '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}body{margin:0;background:var(--bg);color:var(--ink);font:17px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
			. '.top{background:var(--brand);padding:12px 16px 0}.top img{display:block;height:26px;width:auto;margin:0 auto 10px}.prog{height:6px;background:rgba(255,255,255,.18);border-radius:3px;overflow:hidden;max-width:520px;margin:0 auto}.prog i{display:block;height:100%;width:0;background:var(--gold);transition:width .2s}'
			. '.count{color:#cfe0da;font-size:13px;text-align:center;padding:5px 0 8px;min-height:28px}main{max-width:520px;margin:0 auto;padding:14px 16px 110px}'
			. '.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin:0 0 14px}h1{font-size:22px;line-height:1.2;margin:0 0 8px;color:var(--brand)}.intro p{margin:0 0 8px}.priv{font-size:14px;color:var(--muted);background:var(--soft);border-radius:10px;padding:9px 11px;margin:0!important}'
			. '.test{background:#fff3cd;border:2px dashed #c39443;color:#6a4b10;border-radius:12px;padding:9px 12px;font-weight:700;font-size:14px;margin:0 0 12px}'
			. 'fieldset{border:0;margin:0 0 18px;padding:0;min-width:0}legend{font-size:20px;font-weight:700;line-height:1.25;padding:0;margin:0 0 6px}.hint{color:var(--muted);font-size:14px;margin:0 0 10px}'
			. '.opts{display:grid;gap:9px}.opt{display:block;position:relative}.opt input,.scale input{position:absolute;opacity:0;width:1px;height:1px}'
			. '.opt span{display:flex;align-items:center;min-height:52px;padding:12px 14px 12px 46px;background:var(--card);border:2px solid var(--line);border-radius:14px;font-size:17px;cursor:pointer;position:relative}'
			. '.opt span:before{content:"";position:absolute;left:14px;top:50%;width:20px;height:20px;margin-top:-10px;border:2px solid #b9b0a4;border-radius:50%;background:#fff}.opt input[type=checkbox]+span:before{border-radius:5px}'
			. '.opt input:checked+span{border-color:var(--brand);background:var(--soft);font-weight:600}.opt input:checked+span:before{background:var(--brand);border-color:var(--brand);box-shadow:inset 0 0 0 3px #fff}.opt input:focus-visible+span{outline:3px solid var(--gold);outline-offset:2px}'
			. '.otra,textarea{display:block;width:100%;font:inherit;font-size:17px;padding:12px;border:2px solid var(--line);border-radius:12px;background:#fff;min-height:50px}.otra{margin:-2px 0 4px}textarea{resize:vertical}.otra:focus,textarea:focus{outline:0;border-color:var(--brand)}'
			. '.js .otra{display:none}.js .otra.on{display:block}'
			. '.scale{display:grid;gap:7px;grid-template-columns:repeat(5,1fr)}.scale.s11{grid-template-columns:repeat(6,1fr)}.scale label{position:relative}.scale span{display:flex;align-items:center;justify-content:center;height:54px;background:var(--card);border:2px solid var(--line);border-radius:12px;font-size:20px;font-weight:700;cursor:pointer}'
			. '.scale input:checked+span{background:var(--brand);border-color:var(--brand);color:#fff}.scale input:focus-visible+span{outline:3px solid var(--gold);outline-offset:2px}.ends{display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:13px;margin-top:8px}.ends span:last-child{text-align:right}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;background:var(--brand);color:#fff;font:inherit;font-weight:700;font-size:17px;padding:12px 20px;min-height:52px;cursor:pointer;text-decoration:none}.btn.ghost{background:var(--card);color:var(--brand);border:2px solid var(--line)}.btn.send{background:var(--gold);color:#1d1a15}.btn:disabled{opacity:.55}.btn.big{width:100%;margin-top:10px}'
			. '.nav{position:fixed;left:0;right:0;bottom:0;background:rgba(246,241,230,.97);border-top:1px solid var(--line);padding:10px 16px calc(10px + env(safe-area-inset-bottom))}.nav{display:flex;gap:10px;justify-content:center}.nav .btn{flex:1;max-width:250px}.nav[hidden]{display:none}'
			. '.msg{background:#fff0ed;border:1px solid #f0c4bb;color:var(--bad);border-radius:12px;padding:11px 12px;font-size:15px}.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
			. '.js .step{display:none}.js .step.cur{display:block}.js .nojs-send{display:none}.blk{font-size:15px;color:var(--brand);margin:24px 0 8px;text-transform:uppercase}'
			. '.done h1,.closed h1{font-size:24px}.done{text-align:center;padding:26px 16px}[hidden]{display:none!important}';
	}

	private static function js(): string {
		$file = CVD_DIR . 'assets/encuesta.js';
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore
	}

	private static function shell( string $body, bool $prueba ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$logo = esc_url( CVD_URL . 'assets/logo-casa-viva-crema.svg' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#0f3d3a">'
			. '<title>Encuesta anónima · Casa Viva</title><script>document.documentElement.className="js"</script><style>' . self::css() . '</style></head><body>' // phpcs:ignore
			. '<header class="top"><img src="' . $logo . '" alt="Casa Viva" width="128" height="26"><div class="prog"><i id="bar"></i></div><div class="count" id="count"></div></header><main>'
			. ( $prueba ? '<div class="test">MODO PRUEBA: estas respuestas se marcan como prueba y no cuentan.</div>' : '' )
			. $body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado y escapado arriba.
			. '</main><script>' . self::js() . '</script></body></html>'; // phpcs:ignore
		exit;
	}

	/* ================================================================ datos y resultados */

	/** Filas guardadas (respuestas en array). $pruebas: false = solo reales, true = solo prueba. */
	public static function rows( bool $pruebas = false ): array {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) { return array(); }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, fecha, papel, origen, version_encuesta, tipo, prueba, respuestas FROM ' . self::table() . ' WHERE prueba=%d ORDER BY fecha, id', $pruebas ? 1 : 0 ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$d = json_decode( (string) $r['respuestas'], true );
			$r['respuestas'] = is_array( $d ) ? $d : array();
			$r['prueba'] = (int) $r['prueba'];
		}
		return $rows;
	}

	public static function count_tests(): int {
		global $wpdb;
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) { return 0; }
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE prueba=1' );
	}

	/** Borra SOLO las filas de prueba. Devuelve cuántas. */
	public static function borrar_pruebas(): int {
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE prueba=1' );
	}

	/** Todo en JSON para analizarlo (Claude): wp eval 'echo CVD_Encuesta::export_json();' */
	public static function export_json( bool $pruebas = false ): string {
		$rows = self::rows( $pruebas );
		$def = array();
		foreach ( CVD_Encuesta_Def::questions() as $q ) { $def[] = $q; }
		return (string) wp_json_encode(
			array(
				'encuesta'     => CVD_Encuesta_Def::VERSION,
				'estado'       => get_option( self::OPT_ESTADO, 'cerrada' ),
				'solo_pruebas' => $pruebas,
				'generado'     => wp_date( 'c' ),
				'papeles'      => CVD_Encuesta_Def::PAPELES,
				'preguntas'    => $def,
				'filas'        => $rows,
				'estadisticas' => CVD_Encuesta_Def::stats( $rows ),
			),
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}

	/* ================================================================ wp-admin → Casa Viva → Encuesta */

	public static function menu(): void {
		add_submenu_page( 'cvd-gestoras', 'Encuesta anónima', 'Encuesta', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function admin_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.', 403 ); }
		check_admin_referer( 'cvd_encuesta' );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$msg = '';
		if ( 'abrir' === $do ) { update_option( self::OPT_ESTADO, 'abierta', false ); $msg = 'abierta'; }
		elseif ( 'cerrar' === $do ) { update_option( self::OPT_ESTADO, 'cerrada', false ); $msg = 'cerrada'; }
		elseif ( 'borrar_pruebas' === $do ) { $msg = 'borradas-' . self::borrar_pruebas(); }
		do_action( 'litespeed_purge_all' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.', 403 ); }
		check_admin_referer( 'cvd_encuesta_csv' );
		$pruebas = ! empty( $_GET['pruebas'] );
		$rows = self::rows( $pruebas );
		$qs = CVD_Encuesta_Def::questions();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="encuesta-casa-viva-' . ( $pruebas ? 'PRUEBAS-' : '' ) . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // para que Excel lea bien los acentos
		$head = array( 'id', 'fecha', 'papel', 'origen', 'version', 'tipo', 'prueba' );
		foreach ( $qs as $q ) {
			$head[] = $q['id'] . ' ' . $q['label'];
			foreach ( $q['options'] ?? array() as $o ) { if ( ! empty( $o['text'] ) ) { $head[] = $q['id'] . ' (' . $o['label'] . ': texto)'; } }
		}
		$head[] = 'nota';
		fputcsv( $out, $head );
		foreach ( $rows as $r ) {
			$line = array( $r['id'], $r['fecha'], $r['papel'], $r['origen'], $r['version_encuesta'], $r['tipo'], $r['prueba'] );
			$a = $r['respuestas'];
			foreach ( $qs as $q ) {
				$line[] = isset( $a[ $q['id'] ] ) ? CVD_Encuesta_Def::answer_label( $q, $a[ $q['id'] ] ) : '';
				foreach ( $q['options'] ?? array() as $o ) { if ( ! empty( $o['text'] ) ) { $line[] = (string) ( $a[ $q['id'] . '_txt' ] ?? '' ); } }
			}
			$line[] = (string) ( $a['nota'] ?? '' );
			fputcsv( $out, array_map( static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $line ) ); // sin fórmulas en Excel
		}
		fclose( $out );
		exit;
	}

	private static function bar( float $pct, string $label, int $count ): string {
		return '<div class="cve-row"><span class="cve-l">' . esc_html( $label ) . '</span><span class="cve-b"><i style="width:' . esc_attr( (string) min( 100, max( 0, $pct ) ) ) . '%"></i></span><span class="cve-n">' . $count . ' · ' . esc_html( number_format_i18n( $pct, 1 ) ) . ' %</span></div>';
	}

	private static function texts( array $list, string $title ): string {
		if ( ! $list ) { return ''; }
		$h = '<details class="cve-t"><summary>' . esc_html( $title ) . ' (' . count( $list ) . ')</summary><ul>';
		foreach ( $list as $t ) { $h .= '<li>' . esc_html( $t ) . '</li>'; }
		return $h . '</ul></details>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		self::maybe_install();
		$pruebas = ! empty( $_GET['pruebas'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$rows = self::rows( $pruebas );
		$st = CVD_Encuesta_Def::stats( $rows );
		$open = self::is_open();
		$tests = self::count_tests();
		$msg = sanitize_text_field( (string) ( $_GET['msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap cve"><style>.cve .cve-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0;max-width:980px}.cve h2{margin:.2em 0 .6em}.cve h3{font-size:14px;margin:16px 0 6px}.cve-row{display:grid;grid-template-columns:minmax(140px,280px) 1fr 110px;gap:8px;align-items:center;margin:3px 0;font-size:13px}.cve-b{background:#f0f0f1;border-radius:4px;height:14px;overflow:hidden}.cve-b i{display:block;height:100%;background:#0f3d3a}.cve-n{color:#50575e;text-align:right}.cve-warn{background:#fcf0d0;border-left:4px solid #dba617;padding:8px 12px;margin:8px 0}.cve-test{background:#fff3cd;border:2px dashed #c39443;padding:8px 12px;font-weight:600}.cve-kpi{display:inline-block;margin:0 18px 6px 0}.cve-kpi b{font-size:22px;display:block}.cve-t ul{margin:6px 0 0 18px;list-style:disc}.cve-t li{margin:3px 0}.cve-small{color:#50575e;font-size:12px}.cve code{font-size:12px}</style>';
		echo '<h1>Encuesta anónima de Casa Viva</h1>';
		if ( 'abierta' === $msg ) { echo '<div class="notice notice-success"><p>Encuesta ABIERTA: ya la puede contestar cualquiera con el enlace.</p></div>'; }
		if ( 'cerrada' === $msg ) { echo '<div class="notice notice-info"><p>Encuesta cerrada.</p></div>'; }
		if ( str_starts_with( $msg, 'borradas-' ) ) { echo '<div class="notice notice-success"><p>Borradas ' . (int) substr( $msg, 9 ) . ' respuestas de prueba (solo las de prueba).</p></div>'; }

		// Estado y enlaces
		echo '<div class="cve-card"><h2>Estado: ' . ( $open ? '<span style="color:#2f7a4f">ABIERTA</span>' : '<span style="color:#9a392f">CERRADA</span>' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( 'cvd_encuesta' );
		echo '<input type="hidden" name="action" value="cvd_encuesta">';
		echo $open
			? '<button class="button" name="do" value="cerrar">Cerrar la encuesta</button>'
			: '<button class="button button-primary" name="do" value="abrir" onclick="return confirm(\'¿Abrir la encuesta? Cualquiera con el enlace podrá contestar.\')">Abrir la encuesta</button>';
		echo '</form>';
		echo '<p class="cve-small">Enlaces para mandar: General <code>' . esc_html( self::url( array( 'p' => 'gestora', 'o' => 'general' ) ) ) . '</code> · Mensajerías <code>' . esc_html( self::url( array( 'p' => 'mensajero', 'o' => 'mensajerias' ) ) ) . '</code> · Tienda <code>' . esc_html( self::url( array( 'p' => 'tienda', 'o' => 'privado' ) ) ) . '</code></p>';
		echo '<p class="cve-small">Para probarla sin abrirla (las respuestas quedan como prueba): <a href="' . esc_url( self::test_url() ) . '" target="_blank" rel="noopener">enlace de prueba</a> (no lo mandes a nadie).</p>';
		echo '<p class="cve-small">Para Claude: <code>wp eval \'echo CVD_Encuesta::export_json();\' &gt; encuesta.json</code></p></div>';

		// Totales
		echo '<div class="cve-card">';
		if ( $pruebas ) { echo '<p class="cve-test">Estás viendo SOLO las respuestas de PRUEBA (no cuentan). <a href="' . esc_url( $base ) . '">Ver las reales</a></p>'; }
		echo '<h2>' . ( $pruebas ? 'Respuestas de prueba' : 'Respuestas' ) . ': ' . (int) $st['total'] . '</h2>';
		foreach ( CVD_Encuesta_Def::PAPELES as $p => $label ) { echo '<span class="cve-kpi"><b>' . (int) $st['por_papel'][ $p ] . '</b>' . esc_html( $label ) . '</span>'; }
		echo '<p class="cve-small">De qué enlace: ';
		$parts = array();
		foreach ( $st['por_origen'] as $o => $n ) { $parts[] = esc_html( str_replace( '_', ' ', $o ) ) . ' ' . (int) $n; }
		echo implode( ' · ', $parts ) . ' · notas añadidas ' . count( $st['notas'] ) . '</p>'; // phpcs:ignore
		foreach ( $st['bloques'] as $b ) { if ( $b['pocas'] ) { echo '<div class="cve-warn">' . esc_html( $b['label'] ) . ': pocas respuestas (' . (int) $b['n'] . '): cuidado con el anonimato.</div>'; } }
		if ( ! $pruebas ) { echo '<p class="cve-small">Respuestas de prueba guardadas: ' . (int) $tests . ( $tests ? ' · <a href="' . esc_url( add_query_arg( 'pruebas', 1, $base ) ) . '">verlas</a>' : '' ) . '</p>'; }
		$csv = wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'cvd_encuesta_csv', 'pruebas' => $pruebas ? 1 : 0 ) ), admin_url( 'admin-post.php' ) ), 'cvd_encuesta_csv' );
		echo '<p><a class="button button-primary" href="' . esc_url( $csv ) . '">Descargar CSV</a> ';
		if ( $tests ) {
			echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'cvd_encuesta' );
			echo '<input type="hidden" name="action" value="cvd_encuesta"><button class="button" name="do" value="borrar_pruebas" onclick="return confirm(\'¿Borrar las ' . (int) $tests . ' respuestas de prueba? Las reales no se tocan.\')">Borrar las respuestas de prueba (' . (int) $tests . ')</button></form>';
		} else {
			echo '</p>';
		}
		echo '</div>';

		// Por papel
		foreach ( $st['bloques'] as $b ) {
			echo '<div class="cve-card"><h2>' . esc_html( $b['label'] ) . ' — ' . (int) $b['n'] . ' respuestas</h2>';
			if ( $b['pocas'] ) { echo '<div class="cve-warn">Pocas respuestas: cuidado con el anonimato.</div>'; }
			if ( ! $b['n'] ) { echo '<p class="cve-small">Todavía nadie.</p></div>'; continue; }
			foreach ( $b['preguntas'] as $q ) {
				echo '<h3>' . esc_html( strtoupper( $q['id'] ) . '. ' . $q['label'] ) . '</h3><p class="cve-small">Contestaron ' . (int) $q['n'] . ( isset( $q['se_pregunto_a'] ) ? ' de ' . (int) $q['se_pregunto_a'] . ' a quienes se les preguntó' : '' ) . ' · sin responder ' . (int) $q['sin_responder'] . '</p>';
				if ( isset( $q['opciones'] ) ) {
					foreach ( $q['opciones'] as $o ) { echo self::bar( (float) $o['pct'], $o['label'], (int) $o['count'] ); } // phpcs:ignore
					if ( isset( $q['aciertos'] ) ) { echo '<p><b>Aciertos: ' . esc_html( number_format_i18n( $q['aciertos']['pct'], 1 ) ) . ' %</b> (' . (int) $q['aciertos']['ok'] . ' de ' . (int) $q['aciertos']['n'] . '; «No sé» cuenta como fallo)</p>'; }
					echo self::texts( $q['otras'], 'Lo que escribieron en «Otra»' ); // phpcs:ignore
				}
				if ( isset( $q['dist'] ) ) {
					echo '<p><b>Media: ' . esc_html( null === $q['media'] ? '—' : number_format_i18n( $q['media'], 2 ) ) . '</b></p>';
					foreach ( $q['dist'] as $v => $d ) { echo self::bar( (float) $d['pct'], (string) $v, (int) $d['count'] ); } // phpcs:ignore
					if ( ! empty( $q['nps'] ) ) {
						$n = $q['nps'];
						echo '<p><b>' . ( $q['es_recomendacion'] ? 'Nota de recomendación (NPS)' : 'Igual que el NPS (no es de recomendación)' ) . ': ' . esc_html( number_format_i18n( $n['nps'], 1 ) ) . '</b> · 9-10: ' . esc_html( number_format_i18n( $n['promotores'], 1 ) ) . ' % · 7-8: ' . esc_html( number_format_i18n( $n['pasivos'], 1 ) ) . ' % · 0-6: ' . esc_html( number_format_i18n( $n['detractores'], 1 ) ) . ' %</p>';
					}
				}
				if ( isset( $q['textos'] ) ) { echo self::texts( $q['textos'], 'Respuestas escritas' ); } // phpcs:ignore
			}
			echo '</div>';
		}
		if ( $st['notas'] ) {
			echo '<div class="cve-card"><h2>Notas añadidas después («¿quieres añadir algo?»)</h2><ul>';
			foreach ( $st['notas'] as $n ) { echo '<li><b>' . esc_html( CVD_Encuesta_Def::PAPELES[ $n['papel'] ] ?? $n['papel'] ) . ':</b> ' . esc_html( $n['texto'] ) . '</li>'; }
			echo '</ul></div>';
		}
		echo '<p class="cve-small">Los textos van en orden alfabético (no en orden de llegada) para que no se sepa quién escribió qué.</p></div>';
	}
}
