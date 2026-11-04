<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.130 (Ernesto 4-nov): alta de choferes sin Claude, y el panel de Triciclub para verlos.
 *
 * - Cuenta: cualquiera con triciclo, no hace falta ser mensajero de Casa Viva. El chofer escribe al WhatsApp de Triciclub,
 *   el bot pide POST /bot/triciclub/chofer {telefono, nombre} y le manda el enlace (/palla/triciclub#k=…). Mientras el bot
 *   no lo haga: wp eval 'echo CVD_Triciclub_Alta::cuenta( "53…" )["enlace"];'. El usuario de WordPress tiene el rol
 *   «cvd_tc_chofer»: no es mensajero, no entra en Casa Viva ni recibe sus mensajes.
 * - Alta en su pantalla: tipo (uno de TIPOS), motor, pasaje o carga, personas o kilos, su foto, la del triciclo y la licencia
 *   (opcional: muchos no la tienen). Las fotos se achican en el móvil y aquí se guardan de nuevo (sin EXIF ni GPS) en
 *   uploads/cvd-tc-privado/, que no se ve desde fuera: solo con un enlace firmado (foto_url), más una miniatura de 240 px.
 * - Con todo puesto queda APROBADO al momento (Ernesto: «aprobar automáticamente cuando el chofer envía los datos»); en
 *   modo prueba entra también en cvd_tc_testers. Como Uber o Bolt: se aprueba rápido y se revisa después.
 * - Panel /triciclub/admin (Ernesto: «una base de datos que yo pueda ver en un panel, no por WhatsApp, para ahorrar datos»):
 *   números del día, choferes nuevos sin revisar, en línea, parados; ficha con fotos y botones Revisado / Parar / Volver a
 *   aprobar. Entra con el usuario de administrador de la web. Sin avisos por WhatsApp ni correo. HTML del servidor, sin JS.
 */
final class CVD_Triciclub_Alta {
	public const ROL = 'cvd_tc_chofer';
	public const FOTOS = array( 'perfil', 'triciclo', 'licencia' );
	public const MOTORES = array( 'pedal' => 'Pedal', 'electrico' => 'Eléctrico', 'gasolina' => 'Gasolina' );

	/** Los 6 tipos que elige el cliente (investigación 4-nov: docs/nexo-business/apps/triciclub/sms-y-tipos-de-triciclo-2026-11-04.md). */
	public const TIPOS = array(
		'bici'         => array( 'nombre' => 'Bici', 'sub' => 'Pedal · 2 personas', 'motor' => 'pedal', 'icono' => '<circle cx="6" cy="18" r="3.5"/><circle cx="26" cy="18" r="3.5"/><path d="M6 18l4-9h3M10 9l5 9h7M18 12h8v6"/><circle cx="15" cy="18" r="1.3"/>' ),
		'electrico'    => array( 'nombre' => 'Eléctrico', 'sub' => '3–4 personas', 'motor' => 'electrico', 'icono' => '<circle cx="6" cy="18" r="3.5"/><circle cx="26" cy="18" r="3.5"/><path d="M6 18l3-7h4l1-5h12v12M9 11h17"/><path d="M20 4l-2 3h3l-2 3"/>' ),
		'electrico_xl' => array( 'nombre' => 'Eléctrico XL', 'sub' => '6–8 personas', 'motor' => 'electrico', 'icono' => '<circle cx="5" cy="18" r="3.5"/><circle cx="27" cy="18" r="3.5"/><path d="M5 18l2-7h3l1-6h19v13M7 11h23M18 5v6M24 5v6"/><path d="M14 2l-2 3h3l-2 3"/>' ),
		'motor'        => array( 'nombre' => 'Motor', 'sub' => 'Gasolina · 2–4 personas', 'motor' => 'gasolina', 'icono' => '<circle cx="6" cy="18" r="3.5"/><circle cx="26" cy="18" r="3.5"/><path d="M6 18l3-7h4l1-5h12v12M9 11h17"/><path d="M20 3s-2.5 3-2.5 4.5a2.5 2.5 0 0 0 5 0C22.5 6 20 3 20 3z"/>' ),
		'moto'         => array( 'nombre' => 'Moto', 'sub' => '1 persona · rápido', 'motor' => '', 'icono' => '<circle cx="6" cy="17" r="3.5"/><circle cx="26" cy="17" r="3.5"/><path d="M6 17l5-7h6l3 4h6M13 10l-2-3H8M20 14l6 3"/>' ),
		'carga'        => array( 'nombre' => 'Carga', 'sub' => 'Mudanzas y mercancía', 'motor' => '', 'icono' => '<circle cx="6" cy="18" r="3.5"/><circle cx="26" cy="18" r="3.5"/><path d="M6 18l4-9h3M13 14h17"/><rect x="15" y="5" width="14" height="9" rx="1"/>' ),
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$ns = 'casa-viva/v1';
		$d = array( 'CVD_Triciclub_Api', 'auth_chofer' );
		register_rest_route( $ns, '/triciclub/chofer/foto', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'subir_foto' ), 'permission_callback' => $d ) );
		register_rest_route( $ns, '/triciclub/chofer/alta', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'enviar' ), 'permission_callback' => $d ) );
		register_rest_route( $ns, '/triciclub/foto', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'ver_foto' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/bot/triciclub/chofer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_chofer' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	/** Para la app (cliente y chofer): nombre, explicación y dibujo de cada tipo. */
	public static function tipos_publicos(): array {
		$out = array();
		foreach ( self::TIPOS as $id => $t ) { $out[] = array( 'id' => $id, 'nombre' => $t['nombre'], 'sub' => $t['sub'], 'motor' => $t['motor'], 'icono' => $t['icono'] ); }
		return $out;
	}

	/** El tipo de una ficha; las de antes del 4-nov (sin tipo) son eléctricos. */
	public static function tipo_de( object $ch ): string {
		$t = (string) ( $ch->tipo ?? '' );
		return isset( self::TIPOS[ $t ] ) ? $t : 'electrico';
	}

	private static function icono( string $tipo, int $size = 32, string $color = '#087a3d' ): string {
		return '<svg width="' . $size . '" height="' . (int) round( $size * 0.75 ) . '" viewBox="0 0 32 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . self::TIPOS[ $tipo ]['icono'] . '</svg>';
	}

	// ------------------------------------------------------------------ Cuenta

	public static function es_propio( WP_User $user ): bool {
		return in_array( self::ROL, (array) $user->roles, true );
	}

	/**
	 * La cuenta de chofer de ese teléfono (la crea si no existe) → { user_id, enlace, estado, nuevo } o { error }.
	 * No envía nada: el enlace lo manda el bot.
	 */
	public static function cuenta( string $telefono, string $nombre = '' ): array {
		$tel = CVD_Triciclub::telefono( $telefono );
		if ( '' === $tel ) { return array( 'error' => 'telefono' ); }
		$nombre = trim( sanitize_text_field( $nombre ) );
		$nuevo = false;
		$ids = get_users( array( 'meta_key' => 'cvd_tc_telefono', 'meta_value' => $tel, 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- una vez por alta.
		$user_id = $ids ? (int) $ids[0] : 0;
		if ( ! $user_id ) {
			$existe = get_user_by( 'login', 'tc' . $tel );
			$user_id = $existe ? (int) $existe->ID : (int) wp_insert_user( array(
				'user_login'   => 'tc' . $tel,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => '' !== $nombre ? $nombre : 'Chofer ' . substr( $tel, -4 ),
				'role'         => self::ROL,
			) );
			if ( ! $user_id ) { return array( 'error' => 'usuario' ); }
			update_user_meta( $user_id, 'cvd_tc_telefono', $tel );
			$nuevo = ! $existe;
		}
		if ( ! CVD_Triciclub::chofer_de( $user_id ) ) {
			global $wpdb;
			$now = CVD_Triciclub_Db::now();
			$wpdb->insert( CVD_Triciclub_Db::t( 'choferes' ), array( 'user_id' => $user_id, 'created_at' => $now, 'updated_at' => $now ) );
		}
		$ch = CVD_Triciclub::chofer_de( $user_id );
		return array(
			'user_id' => $user_id,
			'enlace'  => home_url( '/palla/triciclub' ) . '#k=' . CVD_Palla::token_for( $user_id ),
			'estado'  => $ch ? (string) $ch->estado : 'pendiente',
			'nuevo'   => $nuevo,
		);
	}

	/** POST /bot/triciclub/chofer {telefono, nombre} — el bot crea la cuenta y le manda el enlace al chofer. */
	public static function bot_chofer( WP_REST_Request $request ): WP_REST_Response {
		$r = self::cuenta( (string) $request->get_param( 'telefono' ), (string) $request->get_param( 'nombre' ) );
		if ( isset( $r['error'] ) ) { return self::fail( $r['error'], 'Ese teléfono no vale.' ); }
		return self::ok( array( 'ok' => true ) + $r );
	}

	// ------------------------------------------------------------------ Fotos

	private static function carpeta( int $user_id ): string {
		$up = wp_upload_dir( null, false );
		$base = trailingslashit( (string) $up['basedir'] ) . 'cvd-tc-privado/';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			file_put_contents( $base . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- carpeta propia.
			file_put_contents( $base . 'index.php', "<?php // Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$dir = $base . $user_id . '/';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		return $dir;
	}

	private static function fotos( object $ch ): array {
		$f = json_decode( (string) $ch->fotos, true );
		return is_array( $f ) ? array_intersect_key( $f, array_flip( self::FOTOS ) ) : array();
	}

	private static function firma( string $texto ): string {
		return substr( hash_hmac( 'sha256', $texto, wp_salt( 'auth' ) . '|cvd-tc-alta' ), 0, 32 );
	}

	/** Enlace firmado a una foto (cambia si se cambia la foto). $mini = la de 240 px (la lista del panel). '' si no hay. */
	public static function foto_url( object $ch, string $cual, bool $mini = false ): string {
		$f = self::fotos( $ch )[ $cual ] ?? '';
		if ( '' === $f ) { return ''; }
		$q = array( 'u' => (int) $ch->user_id, 'c' => $cual, 's' => self::firma( (int) $ch->user_id . '|' . $cual . '|' . $f ) );
		return add_query_arg( $mini ? $q + array( 't' => 1 ) : $q, rest_url( 'casa-viva/v1/triciclub/foto' ) );
	}

	/** GET /triciclub/foto?u&c&s[&t] — la foto (o su miniatura), solo con la firma buena. */
	public static function ver_foto( WP_REST_Request $request ) {
		$uid = absint( $request->get_param( 'u' ) );
		$cual = sanitize_key( (string) $request->get_param( 'c' ) );
		$ch = $uid ? CVD_Triciclub::chofer_de( $uid ) : null;
		$f = $ch ? basename( (string) ( self::fotos( $ch )[ $cual ] ?? '' ) ) : '';
		if ( '' === $f || ! hash_equals( self::firma( $uid . '|' . $cual . '|' . $f ), (string) $request->get_param( 's' ) ) ) { return self::fail( 'foto', 'No existe.', 404 ); }
		$file = self::carpeta( $uid ) . ( $request->get_param( 't' ) ? str_replace( '.jpg', '-t.jpg', $f ) : $f );
		if ( ! is_readable( $file ) ) { $file = self::carpeta( $uid ) . $f; }
		if ( ! is_readable( $file ) ) { return self::fail( 'foto', 'No existe.', 404 ); }
		do_action( 'litespeed_control_set_nocache', 'Triciclub: foto privada' );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: private, max-age=604800' ); // la URL cambia si cambia la foto
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function yo( WP_REST_Request $request ): ?WP_User {
		$u = get_userdata( CVD_Palla::verify( trim( (string) $request->get_header( 'x_palla_token' ) ) ) );
		return $u instanceof WP_User ? $u : null;
	}

	/** POST /triciclub/chofer/foto (multipart: foto, cual) → { ok, cual, url }. */
	public static function subir_foto( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::yo( $request );
		$ch = $u ? CVD_Triciclub::chofer_de( (int) $u->ID ) : null;
		$cual = sanitize_key( (string) $request->get_param( 'cual' ) );
		if ( $u && ! $ch ) { // la primera vez que entra un mensajero de Casa Viva
			$now = CVD_Triciclub_Db::now();
			$wpdb->insert( CVD_Triciclub_Db::t( 'choferes' ), array( 'user_id' => (int) $u->ID, 'created_at' => $now, 'updated_at' => $now ) );
			$ch = CVD_Triciclub::chofer_de( (int) $u->ID );
		}
		if ( ! $ch || ! in_array( $cual, self::FOTOS, true ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.' ); }
		$file = $request->get_file_params()['foto'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) { return self::fail( 'foto', 'La foto no llegó. Prueba otra vez.' ); }
		if ( (int) $file['size'] > 8 * MB_IN_BYTES ) { return self::fail( 'foto', 'La foto pesa demasiado.' ); }
		$info = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- un archivo que no es foto da aviso.
		if ( ! $info || ! in_array( $info['mime'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return self::fail( 'foto', 'Eso no es una foto.' ); }
		$dir = self::carpeta( (int) $u->ID );
		$nombre = $cual . '-' . wp_generate_password( 10, false, false ) . '.jpg';
		foreach ( array( '' => 1280, '-t' => 240 ) as $suf => $lado ) { // la foto y su miniatura, de nuevo en JPEG: sin EXIF ni GPS
			$ed = wp_get_image_editor( (string) $file['tmp_name'] );
			if ( is_wp_error( $ed ) ) { return self::fail( 'foto', 'No se pudo leer la foto.' ); }
			$ed->resize( $lado, $lado, '-t' === $suf );
			$ed->set_quality( '-t' === $suf ? 70 : 80 );
			if ( is_wp_error( $ed->save( $dir . str_replace( '.jpg', $suf . '.jpg', $nombre ), 'image/jpeg' ) ) ) { return self::fail( 'foto', 'No se pudo guardar la foto.' ); }
		}
		$fotos = self::fotos( $ch );
		if ( ! empty( $fotos[ $cual ] ) ) {
			$vieja = basename( (string) $fotos[ $cual ] );
			foreach ( array( $vieja, str_replace( '.jpg', '-t.jpg', $vieja ) ) as $v ) { if ( is_file( $dir . $v ) ) { wp_delete_file( $dir . $v ); } }
		}
		$fotos[ $cual ] = $nombre;
		$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'fotos' => wp_json_encode( $fotos ), 'updated_at' => CVD_Triciclub_Db::now() ), array( 'user_id' => (int) $u->ID ) );
		return self::ok( array( 'ok' => true, 'cual' => $cual, 'url' => self::foto_url( CVD_Triciclub::chofer_de( (int) $u->ID ), $cual, true ) ) );
	}

	// ------------------------------------------------------------------ Enviar = aprobado

	/** Lo que le falta a la ficha para quedar aprobada (vacío = completa). */
	public static function faltan( object $ch, WP_User $u ): array {
		$f = self::fotos( $ch );
		$s = explode( ',', (string) $ch->servicios );
		$falta = array();
		if ( mb_strlen( trim( (string) $u->display_name ) ) < 2 || preg_match( '/^Chofer \d+$/', (string) $u->display_name ) ) { $falta[] = 'tu nombre'; } // «Chofer 0004» = el que pone cuenta()
		if ( ! isset( self::TIPOS[ (string) $ch->tipo ] ) ) { $falta[] = 'el tipo de triciclo'; }
		if ( ! isset( self::MOTORES[ (string) $ch->motor ] ) ) { $falta[] = 'el motor'; }
		if ( in_array( 'viaje', $s, true ) && (int) $ch->plazas < 1 ) { $falta[] = 'cuántas personas llevas'; }
		if ( in_array( 'carga', $s, true ) && (int) $ch->carga_kg < 20 ) { $falta[] = 'cuántos kilos cargas'; }
		if ( empty( $f['perfil'] ) ) { $falta[] = 'tu foto'; }
		if ( empty( $f['triciclo'] ) ) { $falta[] = 'la foto del triciclo'; }
		return $falta;
	}

	/** POST /triciclub/chofer/alta — con todo puesto queda aprobado al momento (sin avisos: Ernesto lo ve en el panel). */
	public static function enviar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::yo( $request );
		$ch = $u ? CVD_Triciclub::chofer_de( (int) $u->ID ) : null;
		if ( ! $u || ! $ch ) { return self::fail( 'chofer', 'Primero guarda los datos de tu triciclo.' ); }
		if ( 'suspendido' === $ch->estado ) { return self::fail( 'suspendido', 'Tu cuenta está parada. Escribe a Triciclub por WhatsApp.', 403 ); }
		$falta = self::faltan( $ch, $u );
		if ( $falta ) { return self::fail( 'faltan', 'Falta: ' . implode( ', ', $falta ) . '.' ); }
		$ya = 'aprobado' === $ch->estado;
		$now = CVD_Triciclub_Db::now();
		$wpdb->update( CVD_Triciclub_Db::t( 'choferes' ), array( 'enviado_at' => $now, 'motivo' => '', 'updated_at' => $now ), array( 'user_id' => (int) $u->ID ) );
		if ( ! $ya ) {
			CVD_Triciclub::aprobar_chofer( (int) $u->ID );
			if ( 'prueba' === CVD_Triciclub::mode() ) { update_option( CVD_Triciclub::TESTERS, array_values( array_unique( array_merge( CVD_Triciclub::testers(), array( (int) $u->ID ) ) ) ), false ); }
		}
		return self::ok( array( 'ok' => true, 'aprobado' => true, 'nuevo' => ! $ya ) );
	}

	// ------------------------------------------------------------------ Panel /triciclub/admin

	public static function puede_panel(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/** Los números de arriba del panel. */
	private static function numeros(): array {
		global $wpdb;
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$v = CVD_Triciclub_Db::t( 'viajes' );
		$inicio = ( new DateTimeImmutable( 'today', new DateTimeZone( 'America/Havana' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$vivo = gmdate( 'Y-m-d H:i:s', time() - CVD_Triciclub_Api::VIVO );
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) total, SUM(estado='aprobado') aprobados, SUM(estado='suspendido') parados, SUM(estado='aprobado' AND revisado_at IS NULL) nuevos, SUM(estado='aprobado' AND disponible=1 AND ubicado_at>%s) en_linea FROM {$t}", $vivo ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		$h = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(precio),0) cup FROM {$v} WHERE estado='terminado' AND terminado_at>=%s", $inicio ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'total' => (int) $c->total, 'aprobados' => (int) $c->aprobados, 'parados' => (int) $c->parados, 'nuevos' => (int) $c->nuevos, 'en_linea' => (int) $c->en_linea, 'viajes' => (int) $h->n, 'cup' => (int) $h->cup );
	}

	private static function panel_url( array $q = array() ): string {
		return add_query_arg( $q, home_url( '/triciclub/admin/' ) );
	}

	private static function hora( ?string $utc ): string {
		if ( ! $utc ) { return '—'; }
		return ( new DateTimeImmutable( $utc . ' UTC' ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'j/n H:i' );
	}

	/** /triciclub/admin — la lista y la ficha de cada chofer, para Ernesto. */
	public static function pagina_admin(): void {
		nocache_headers();
		if ( ! self::puede_panel() ) { wp_safe_redirect( wp_login_url( self::panel_url() ) ); exit; }
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		global $wpdb;
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$msg = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo lectura de filtros; las acciones van con nonce.
		$uid = absint( $_REQUEST['c'] ?? 0 );
		if ( $uid && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( 'cvd_tc_admin_' . $uid ) ) {
			$accion = sanitize_key( (string) ( $_POST['accion'] ?? '' ) );
			$now = CVD_Triciclub_Db::now();
			if ( 'revisado' === $accion ) {
				$wpdb->update( $t, array( 'revisado_at' => $now, 'updated_at' => $now ), array( 'user_id' => $uid ) );
				$msg = '✔ Revisado.';
			} elseif ( 'parar' === $accion ) {
				CVD_Triciclub::suspender_chofer( $uid );
				$wpdb->update( $t, array( 'motivo' => sanitize_text_field( wp_unslash( (string) ( $_POST['motivo'] ?? '' ) ) ), 'revisado_at' => $now ), array( 'user_id' => $uid ) );
				$msg = '⛔ Parado: ya no sale a los clientes.';
			} elseif ( 'aprobar' === $accion ) {
				CVD_Triciclub::aprobar_chofer( $uid, get_current_user_id() );
				$wpdb->update( $t, array( 'motivo' => '', 'revisado_at' => $now ), array( 'user_id' => $uid ) );
				$msg = '✅ Aprobado otra vez.';
			}
		}
		$ver = sanitize_key( (string) ( $_GET['ver'] ?? '' ) );
		// phpcs:enable
		$h = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Triciclub · panel</title>'
			. '<style>' . self::css() . '</style></head><body><main>';
		$ch = $uid ? CVD_Triciclub::chofer_de( $uid ) : null;
		$h .= $ch ? self::ficha_html( $ch, $msg ) : self::lista_html( $ver );
		echo $h . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* abajo.
		exit;
	}

	private static function lista_html( string $ver ): string {
		global $wpdb;
		$t = CVD_Triciclub_Db::t( 'choferes' );
		$n = self::numeros();
		$vivo = gmdate( 'Y-m-d H:i:s', time() - CVD_Triciclub_Api::VIVO );
		$where = array(
			'nuevos'   => "estado='aprobado' AND revisado_at IS NULL",
			'linea'    => $wpdb->prepare( "estado='aprobado' AND disponible=1 AND ubicado_at>%s", $vivo ),
			'parados'  => "estado='suspendido'",
			'rellenan' => "estado='pendiente'",
		)[ $ver ] ?? '1=1';
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} ORDER BY COALESCE(enviado_at, created_at) DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL -- filtros fijos de arriba.
		$h = '<h1>🛺 Triciclub · panel</h1><div class="n">'
			. '<div><b>' . $n['en_linea'] . '</b>en línea</div><div><b>' . $n['viajes'] . '</b>viajes hoy</div><div><b>' . number_format( $n['cup'], 0, ',', '.' ) . '</b>CUP hoy</div><div><b>' . $n['aprobados'] . '</b>choferes</div></div>'
			. '<nav>';
		foreach ( array( '' => 'Todos (' . $n['total'] . ')', 'nuevos' => 'Nuevos sin revisar (' . $n['nuevos'] . ')', 'linea' => 'En línea (' . $n['en_linea'] . ')', 'rellenan' => 'Rellenando', 'parados' => 'Parados (' . $n['parados'] . ')' ) as $k => $label ) {
			$h .= '<a href="' . esc_url( self::panel_url( '' === $k ? array() : array( 'ver' => $k ) ) ) . '"' . ( $k === $ver ? ' class="on"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$h .= '</nav>';
		if ( ! $rows ) { return $h . '<p class="vacio">No hay choferes aquí.</p>'; }
		$h .= '<ul class="l">';
		foreach ( $rows as $ch ) {
			$u = get_userdata( (int) $ch->user_id );
			$tipo = self::tipo_de( $ch );
			$mini = self::foto_url( $ch, 'perfil', true );
			$estado = array( 'aprobado' => null === $ch->revisado_at ? 'nuevo' : 'ok', 'suspendido' => 'parado', 'pendiente' => 'rellenando' )[ $ch->estado ] ?? $ch->estado;
			$h .= '<li><a href="' . esc_url( self::panel_url( array( 'c' => (int) $ch->user_id ) ) ) . '">'
				. ( '' !== $mini ? '<img src="' . esc_url( $mini ) . '" alt="" width="48" height="48" loading="lazy">' : '<span class="sin">' . self::icono( $tipo, 28 ) . '</span>' )
				. '<span class="q"><b>' . esc_html( $u ? $u->display_name : '#' . $ch->user_id ) . '</b><small>' . esc_html( self::TIPOS[ $tipo ]['nombre'] . ' · ' . (int) $ch->viajes_total . ' viajes' . ( null !== $ch->nota_media ? ' · ★ ' . round( (float) $ch->nota_media, 1 ) : '' ) . ' · ' . self::hora( $ch->enviado_at ?: $ch->created_at ) ) . '</small></span>'
				. '<span class="e e--' . esc_attr( $estado ) . '">' . esc_html( $estado ) . '</span></a></li>';
		}
		return $h . '</ul>';
	}

	private static function ficha_html( object $ch, string $msg ): string {
		$uid = (int) $ch->user_id;
		$u = get_userdata( $uid );
		$tipo = self::tipo_de( $ch );
		$tel = (string) get_user_meta( $uid, 'cvd_tc_telefono', true );
		if ( '' === $tel && $u ) { $tel = CVD_Triciclub::telefono( (string) get_user_meta( $uid, 'billing_phone', true ) ); }
		$h = '<p><a href="' . esc_url( self::panel_url() ) . '">← Todos los choferes</a></p><h1>' . self::icono( $tipo, 34 ) . ' ' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</h1>'
			. ( '' !== $msg ? '<p class="m">' . esc_html( $msg ) . '</p>' : '' ) . '<div class="f">';
		foreach ( array( 'perfil' => 'Su foto', 'triciclo' => 'Triciclo', 'licencia' => 'Licencia' ) as $k => $label ) {
			$mini = self::foto_url( $ch, $k, true );
			$h .= '<figure>' . ( '' !== $mini ? '<a href="' . esc_url( self::foto_url( $ch, $k ) ) . '"><img src="' . esc_url( $mini ) . '" alt="' . esc_attr( $label ) . '"></a>' : '<span class="sin">—</span>' )
				. '<figcaption>' . esc_html( $label . ( '' === $mini ? ' (no la mandó)' : ' · toca para verla grande' ) ) . '</figcaption></figure>';
		}
		$s = explode( ',', (string) $ch->servicios );
		$h .= '</div><dl>'
			. '<dt>Estado</dt><dd>' . esc_html( array( 'aprobado' => '✅ Aprobado' . ( null === $ch->revisado_at ? ' (sin revisar)' : ' y revisado' ), 'suspendido' => '⛔ Parado' . ( '' !== (string) $ch->motivo ? ': ' . $ch->motivo : '' ), 'pendiente' => 'Rellenando sus datos' )[ $ch->estado ] ?? $ch->estado ) . '</dd>'
			. '<dt>Teléfono</dt><dd>' . ( '' !== $tel ? '<a href="https://wa.me/' . esc_attr( $tel ) . '">' . esc_html( $tel ) . '</a> · <a href="tel:+' . esc_attr( $tel ) . '">llamar</a>' : '—' ) . '</dd>'
			. '<dt>Tipo</dt><dd>' . esc_html( self::TIPOS[ $tipo ]['nombre'] . ' · motor ' . ( self::MOTORES[ (string) $ch->motor ] ?? '—' ) ) . '</dd>'
			. '<dt>Hace</dt><dd>' . esc_html( implode( ', ', array_map( static fn( string $x ): string => array( 'viaje' => 'pasaje', 'paquete' => 'paquetes', 'carga' => 'carga' )[ $x ] ?? $x, $s ) ) . ( (int) $ch->plazas ? ' · ' . (int) $ch->plazas . ' personas' : '' ) . ( (int) $ch->carga_kg ? ' · ' . (int) $ch->carga_kg . ' kg' : '' ) ) . '</dd>'
			. '<dt>Nº / cómo es</dt><dd>' . esc_html( trim( (string) $ch->numero . ' ' . (string) $ch->descripcion ) ?: '—' ) . '</dd>'
			. '<dt>LOT</dt><dd>' . esc_html( (string) $ch->lot_numero ?: 'no puso' ) . ( $ch->lot_vence ? ' · vence ' . esc_html( (string) $ch->lot_vence ) : '' ) . '</dd>'
			. '<dt>Precio</dt><dd>' . (int) $ch->precio_km . ' CUP/km hasta ' . esc_html( (string) (float) $ch->km_tramo ) . ' km, luego ' . (int) $ch->precio_km_despues . '</dd>'
			. '<dt>Viajes · nota</dt><dd>' . (int) $ch->viajes_total . ( null !== $ch->nota_media ? ' · ★ ' . round( (float) $ch->nota_media, 1 ) : '' ) . '</dd>'
			. '<dt>Alta · mandó datos · revisado</dt><dd>' . esc_html( self::hora( $ch->created_at ) . ' · ' . self::hora( $ch->enviado_at ) . ' · ' . self::hora( $ch->revisado_at ) ) . '</dd></dl>';
		$form = static fn( string $accion, string $boton, string $clase, string $extra = '' ): string => '<form method="post" action="' . esc_url( self::panel_url( array( 'c' => $uid ) ) ) . '">' . wp_nonce_field( 'cvd_tc_admin_' . $uid, '_wpnonce', true, false ) . $extra . '<input type="hidden" name="accion" value="' . esc_attr( $accion ) . '"><button class="b ' . esc_attr( $clase ) . '">' . esc_html( $boton ) . '</button></form>';
		if ( 'aprobado' === $ch->estado && null === $ch->revisado_at ) { $h .= $form( 'revisado', '✔ Lo revisé, todo bien', 'b--ok' ); }
		$h .= 'suspendido' === $ch->estado
			? $form( 'aprobar', 'Volver a aprobarlo', 'b--ok' )
			: $form( 'parar', 'Parar a este chofer', 'b--no', '<input name="motivo" placeholder="Por qué (lo verá el chofer)" maxlength="200">' );
		return $h;
	}

	private static function css(): string {
		return 'body{margin:0;font:16px/1.45 system-ui,sans-serif;background:#f4f6f4;color:#18221c}main{max-width:640px;margin:0 auto;padding:16px}a{color:#087a3d}h1{font-size:22px;margin:4px 0 12px;display:flex;gap:8px;align-items:center}'
			. '.n{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.n div{background:#fff;border-radius:12px;padding:10px;font-size:12px;color:#4a5d52;text-align:center}.n b{display:block;font-size:22px;color:#18221c}'
			. 'nav{display:flex;gap:6px;overflow-x:auto;margin:14px 0 8px}nav a{white-space:nowrap;background:#fff;border-radius:99px;padding:7px 12px;font-size:14px;text-decoration:none;color:#18221c}nav a.on{background:#087a3d;color:#fff}'
			. '.l{list-style:none;margin:0;padding:0}.l li{background:#fff;border-radius:12px;margin:6px 0}.l a{display:flex;gap:10px;align-items:center;padding:10px;text-decoration:none;color:inherit}.l img,.sin{width:48px;height:48px;border-radius:50%;object-fit:cover;background:#e3ebe5;display:flex;align-items:center;justify-content:center;flex:none}'
			. '.q{flex:1;min-width:0}.q b{display:block}.q small{color:#4a5d52}.e{font-size:12px;border-radius:99px;padding:3px 8px;background:#e3ebe5}.e--nuevo{background:#fff1c2}.e--parado{background:#fde2e0}.e--ok{background:#d9f2e3}'
			. '.f{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.f figure{margin:0}.f img,.f .sin{width:100%;height:auto;aspect-ratio:1;border-radius:12px;object-fit:cover}.f figcaption{font-size:12px;color:#4a5d52}'
			. 'dl{background:#fff;border-radius:12px;padding:12px 14px;margin:12px 0}dt{font-size:13px;color:#4a5d52}dd{margin:0 0 8px;font-weight:600}.b{display:block;width:100%;border:0;border-radius:12px;padding:15px;font-size:17px;font-weight:700;color:#fff;margin-top:8px}.b--ok{background:#087a3d}.b--no{background:#b42318}'
			. '.m{background:#fff;border-radius:12px;padding:12px;font-weight:700}.vacio{color:#4a5d52}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #c9d2cc;border-radius:10px;font-size:16px;margin-top:8px}';
	}

	private static function ok( array $data ): WP_REST_Response {
		do_action( 'litespeed_control_set_nocache', 'Triciclub: datos privados' );
		$res = new WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function fail( string $code, string $message, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message ), $status );
	}
}
