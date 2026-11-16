<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá Reparto (16-nov-2026, D157): mensajeros para varios negocios sobre el motor de Pa'llá transporte.
 * Diseño: ernesto-rondon-nexo/docs/nexo-business/apps/palla/reparto/DISENO.md.
 *
 * - Negocio + punto de recogida + encargo: un encargo es un viaje de clase «mensajero» (servicio paquete) con
 *   cliente_id = 0, negocio_id y punto_id; precio = la tarifa justa de siempre, FIJO (sin contraofertas).
 * - «Te toca»: se ofrece a 1 mensajero elegido (45 s), luego a 3 (45 s) y luego abierto a todos los cercanos.
 * - «¿Cuándo sales?»: al cogerlo elige Ahora o una hora; 30 min antes «¿Sigues?»; sin respuesta 10 min antes se suelta solo.
 * - Persona de confianza: lo lleva otra persona aprobada con su propio enlace; dinero, comisión y nota del titular.
 *
 * Interruptor `cvd_pv_reparto`: no (por defecto) | si. Apagado, Pa'llá transporte y Casa Viva quedan exactamente igual:
 * las rutas nuevas contestan 403, `tick()` no hace nada y las vistas no cambian (no hay encargos).
 * El puente desde los pedidos de Casa Viva (`cvd_pv_puente_casaviva`) queda diseñado y SIN construir hasta el piloto.
 */
final class CVD_Pv_Reparto {
	public const OPTION = 'cvd_pv_reparto';
	private const NS = 'casa-viva/v1';
	public const CRON = 'cvd_pv_reparto_tick';
	public const TURNO_SEG = 45;   // lo que dura cada ronda de «Te toca»
	public const SIGUES_MIN = 30;  // «¿Sigues?» y GPS: 30 min antes de salir
	public const SUELTA_MIN = 10;  // sin «Sigo» 10 min antes, se suelta solo
	public const PASO_MIN = 15;    // horas de salida de 15 en 15 min
	public const TIPOS = array( 'tienda', 'mercado', 'web', 'particular' );
	public const PAGA = array( 'cliente', 'negocio', 'mitad' );
	public const COBRO = array( 'cobra_devuelve', 'pagado', 'negocio_paga' );
	public const ASIGNAR = array( 'te_toca', 'ofertas' );
	public const ACTIVOS = array( 'asignado', 'llego', 'recogido' );

	public static function on(): bool { return 'si' === get_option( self::OPTION, 'no' ); }

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cada_minuto' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON, array( __CLASS__, 'tick_cron' ) );
		add_action( 'init', array( __CLASS__, 'programar' ), 20 );
		add_filter( 'cvd_pv_comision_pct', array( __CLASS__, 'comision' ), 10, 2 );
	}

	public static function cada_minuto( $s ): array {
		$s = is_array( $s ) ? $s : array();
		$s['cvd_pv_minuto'] = array( 'interval' => 60, 'display' => 'Pa\'llá Reparto: cada minuto' );
		return $s;
	}

	/** wp-cron cada minuto solo con el interruptor encendido. */
	public static function programar(): void {
		$hay = wp_next_scheduled( self::CRON );
		if ( self::on() && ! $hay ) { wp_schedule_event( time() + 60, 'cvd_pv_minuto', self::CRON ); }
		if ( ! self::on() && $hay ) { wp_clear_scheduled_hook( self::CRON ); }
	}

	public static function tick_cron(): void { self::tick( true ); }

	// ================================================================== Utilidades

	private static function t( string $n ): string { return CVD_Triciclub_Db::t( $n ); }

	private static function now(): string { return CVD_Triciclub_Db::now(); }

	private static function tz(): DateTimeZone { return new DateTimeZone( 'America/Havana' ); }

	private static function utc( int $ts ): string { return gmdate( 'Y-m-d H:i:s', $ts ); }

	private static function ts( ?string $mysql ): int { return $mysql ? (int) strtotime( $mysql . ' UTC' ) : 0; }

	/** «14:30» en hora de Cuba. */
	public static function hora( ?string $mysql ): string {
		return $mysql ? ( new DateTimeImmutable( '@' . self::ts( $mysql ) ) )->setTimezone( self::tz() )->format( 'H:i' ) : '';
	}

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	private static function coord( $v, bool $lat ): ?float {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) { return null; }
		$f = (float) $v;
		return $lat ? ( $f >= 19.5 && $f <= 23.5 ? $f : null ) : ( $f >= -85.0 && $f <= -74.0 ? $f : null );
	}

	private static function out( array $data, int $status = 200 ): WP_REST_Response {
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá Reparto: datos privados' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function fail( string $code, string $message, int $status = 400 ): WP_REST_Response {
		return self::out( array( 'ok' => false, 'code' => $code, 'message' => $message ), $status );
	}

	private static function cup( int $n ): string { return number_format( $n, 0, ',', '.' ); }

	/** Primer nombre. */
	public static function nombre1( string $n ): string { return trim( (string) explode( ' ', trim( $n ) )[0] ); }

	public static function tope(): int { return max( 1, min( 10, (int) get_option( 'cvd_pv_tope_activas', 2 ) ) ); }

	public static function radio(): int { return max( 1000, min( 20000, (int) get_option( 'cvd_pv_reparto_radio', CVD_Pv_Api::RADIO_2 ) ) ); }

	// ================================================================== Ganchos en el motor de transporte

	/**
	 * Un encargo ya aceptado que sale dentro de más de 30 min está «programado»: no ocupa al mensajero (puede coger otro),
	 * no recibe GPS de viaje y no es su «viaje en marcha». Fragmento SQL para las consultas de «viaje en marcha».
	 */
	public static function no_programado( string $alias = '' ): string {
		global $wpdb;
		$p = '' !== $alias ? $alias . '.' : '';
		return $wpdb->prepare( " AND NOT ({$p}estado='asignado' AND {$p}sale_at IS NOT NULL AND {$p}sale_at>%s)", self::utc( time() + self::SIGUES_MIN * 60 ) );
	}

	public static function programado( object $v ): bool {
		return 'asignado' === $v->estado && ! empty( $v->sale_at ) && self::ts( (string) $v->sale_at ) > time() + self::SIGUES_MIN * 60;
	}

	/** Al soltar un viaje: también se borran su hora de salida y quién lo lleva. */
	public static function soltar_sql(): string { return ', sale_at=NULL, sigue_at=NULL, lleva_id=0'; }

	/** ¿Ve este chofer este encargo en la lista abierta? (lo llama CVD_Pv_Api::puede_ver con negocio_id > 0) */
	public static function puede_ver( object $ch, object $v ): bool {
		if ( ! self::on() ) { return false; }
		$f = (int) $v->turno_fase;
		if ( 1 === $f || 2 === $f ) { return false; } // en «Te toca» solo lo ve a quien le toca
		if ( self::abiertas( (int) $ch->user_id ) >= self::tope() ) { return false; }
		global $wpdb;
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'turnos' ) . " WHERE viaje_id=%d AND chofer_id=%d AND estado IN ('rechazado','solto') LIMIT 1", (int) $v->id, (int) $ch->user_id ) );
	}

	/** Viajes abiertos o programados del mensajero (para el tope contra acaparar). */
	public static function abiertas( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'viajes' ) . " WHERE chofer_id=%d AND estado IN ('asignado','llego','recogido')", $user_id ) );
	}

	/** La comisión propia del negocio (null = la general). */
	public static function comision( $pct, $viaje ) {
		if ( ! is_object( $viaje ) || ! (int) ( $viaje->negocio_id ?? 0 ) ) { return $pct; }
		$n = self::negocio( (int) $viaje->negocio_id );
		return $n && null !== $n->comision_pct ? (int) $n->comision_pct : $pct;
	}

	/** Lo que el mensajero ve como «cliente» en un encargo: el negocio y su teléfono (el del punto primero). */
	public static function cliente_de( object $v ): array {
		$n = self::negocio( (int) $v->negocio_id );
		$p = self::punto( (int) $v->punto_id );
		$tel = $p && '' !== (string) $p->telefono ? (string) $p->telefono : ( $n ? (string) $n->telefono : '' );
		return array( 'nombre' => $n ? (string) $n->nombre : 'Negocio', 'telefono' => $tel, 'nota' => null, 'viajes' => 0 );
	}

	/** «Isabel (de Pedri)» cuando lo lleva una persona de confianza. */
	public static function nombre_lleva( object $v, string $titular ): string {
		$c = self::confianza( (int) $v->lleva_id );
		return $c ? self::nombre1( (string) $c->nombre ) . ' (de ' . self::nombre1( $titular ) . ')' : $titular;
	}

	/** Lo que el envío cobra al que recibe para el mensajero (su envío) y para devolver al negocio. */
	private static function cobro( object $v ): array {
		$n = self::negocio( (int) $v->negocio_id );
		$paga = $n ? (string) $n->paga_envio : 'cliente';
		$envio = (int) $v->precio;
		$cli = 'cliente' === $paga ? $envio : ( 'mitad' === $paga ? CVD_Pv_Tarifa::arriba10( $envio / 2 ) : 0 );
		$total = (int) $v->cobrar_cup;
		return array( 'total' => $total, 'devolver' => max( 0, $total - $cli ), 'envio_cliente' => $cli, 'envio_negocio' => max( 0, $envio - $cli ), 'paga_envio' => $paga );
	}

	/** Lo nuevo en la vista del chofer (solo encargos de negocios). */
	public static function extra_chofer( object $v ): array {
		if ( ! (int) ( $v->negocio_id ?? 0 ) ) { return array(); }
		$n = self::negocio( (int) $v->negocio_id );
		$p = self::punto( (int) $v->punto_id );
		$c = (int) $v->lleva_id ? self::confianza( (int) $v->lleva_id ) : null;
		$r = array(
			'negocio'    => $n ? array( 'nombre' => (string) $n->nombre, 'telefono' => (string) $n->telefono ) : null,
			'punto'      => $p ? self::punto_vista( $p ) : null,
			'ventana'    => array( 'desde' => self::hora( $v->ventana_desde ), 'hasta' => self::hora( $v->ventana_hasta ) ),
			'sale'       => CVD_Pv_Api::iso( $v->sale_at ),
			'sale_hora'  => self::hora( $v->sale_at ),
			'programado' => self::programado( $v ),
			'sigue'      => ! empty( $v->sigue_at ),
			'lleva'      => $c ? array( 'id' => (int) $c->id, 'nombre' => (string) $c->nombre ) : null,
			'cobro'      => self::cobro( $v ),
			'fase'       => (int) $v->turno_fase,
		);
		if ( 'asignado' === $v->estado && empty( $v->sale_at ) ) { $r['salida'] = self::opciones( $v ); }
		if ( in_array( $v->estado, array( 'asignado', 'llego' ), true ) ) { $r['confianza'] = self::aprobadas( (int) $v->chofer_id ); }
		return array( 'reparto' => $r );
	}

	/** Lo nuevo en la vista del cliente: «Sale a las…». */
	public static function extra_cliente( object $v ): array {
		if ( empty( $v->sale_at ) ) { return array(); }
		return array( 'sale' => CVD_Pv_Api::iso( $v->sale_at ), 'sale_hora' => self::hora( $v->sale_at ), 'programado' => self::programado( $v ) );
	}

	/** chofer/hoy: el encargo que le toca ahora, sus programados y sus personas de confianza. */
	public static function en_hoy( array $data, ?object $ch ): array {
		if ( ! self::on() || ! $ch || 'aprobado' !== (string) $ch->estado ) { return $data; }
		global $wpdb;
		$uid = (int) $ch->user_id;
		$turno = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.hasta, v.* FROM ' . self::t( 'turnos' ) . ' t JOIN ' . self::t( 'viajes' ) . " v ON v.id=t.viaje_id WHERE t.chofer_id=%d AND t.estado='ofrecido' AND t.hasta>%s AND v.estado='buscando' ORDER BY t.id DESC LIMIT 3", $uid, self::now() ) );
		foreach ( $rows as $r ) {
			$item = CVD_Pv_Api::vista_pedido( $r, $ch ) + self::extra_chofer( $r );
			$item['turno_hasta'] = CVD_Pv_Api::iso( $r->hasta );
			$turno[] = $item;
		}
		$prog = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE chofer_id=%d AND estado='asignado' AND sale_at IS NOT NULL AND sale_at>%s ORDER BY sale_at ASC LIMIT 5", $uid, self::utc( time() + self::SIGUES_MIN * 60 ) ) );
		foreach ( $rows as $r ) { $prog[] = CVD_Pv_Api::vista_chofer( $r ); }
		$data['reparto'] = array( 'turno' => $turno, 'programados' => $prog, 'confianza' => self::mis_confianza( $uid ), 'tope' => self::tope(), 'turno_seg' => self::TURNO_SEG );
		return $data;
	}

	// ================================================================== Negocios y puntos

	public static function negocio( int $id ): ?object {
		global $wpdb;
		return $id ? ( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'negocios' ) . ' WHERE id=%d', $id ) ) ?: null ) : null;
	}

	public static function punto( int $id ): ?object {
		global $wpdb;
		return $id ? ( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'puntos' ) . ' WHERE id=%d', $id ) ) ?: null ) : null;
	}

	public static function puntos_de( int $negocio_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'puntos' ) . ' WHERE negocio_id=%d ORDER BY id', $negocio_id ) );
	}

	private static function punto_vista( object $p ): array {
		return array( 'id' => (int) $p->id, 'nombre' => (string) $p->nombre, 'direccion' => (string) $p->direccion, 'telefono' => (string) $p->telefono,
			'desde' => $p->desde ? substr( (string) $p->desde, 0, 5 ) : '', 'hasta' => $p->hasta ? substr( (string) $p->hasta, 0, 5 ) : '', 'dias' => (string) $p->dias );
	}

	private static function hash_clave( string $clave ): string { return hash( 'sha256', 'cvd-pv-negocio|' . $clave ); }

	/** Alta de un negocio. Devuelve [id, clave] — la clave se enseña UNA vez (se guarda solo su hash). */
	public static function crear_negocio( array $p ): array {
		global $wpdb;
		$now = self::now();
		$clave = 'pvn_' . bin2hex( random_bytes( 16 ) );
		$pct = isset( $p['comision_pct'] ) && '' !== (string) $p['comision_pct'] ? max( 0, min( 30, (int) $p['comision_pct'] ) ) : null;
		$wpdb->insert( self::t( 'negocios' ), array(
			'nombre'       => self::txt( $p['nombre'] ?? '', 80 ) ?: 'Negocio',
			'tipo'         => in_array( $p['tipo'] ?? '', self::TIPOS, true ) ? $p['tipo'] : 'tienda',
			'telefono'     => CVD_Triciclub::telefono( $p['telefono'] ?? '' ),
			'clave_hash'   => self::hash_clave( $clave ),
			'paga_envio'   => in_array( $p['paga_envio'] ?? '', self::PAGA, true ) ? $p['paga_envio'] : 'cliente',
			'cobro'        => in_array( $p['cobro'] ?? '', self::COBRO, true ) ? $p['cobro'] : 'pagado',
			'asignar'      => in_array( $p['asignar'] ?? '', self::ASIGNAR, true ) ? $p['asignar'] : 'te_toca',
			'comision_pct' => $pct,
			'activo'       => 1,
			'created_at'   => $now,
			'updated_at'   => $now,
		) );
		return array( (int) $wpdb->insert_id, $clave );
	}

	public static function nueva_clave( int $negocio_id ): string {
		global $wpdb;
		$clave = 'pvn_' . bin2hex( random_bytes( 16 ) );
		$wpdb->update( self::t( 'negocios' ), array( 'clave_hash' => self::hash_clave( $clave ), 'updated_at' => self::now() ), array( 'id' => $negocio_id ) );
		return $clave;
	}

	public static function negocio_por_clave( string $clave ): ?object {
		global $wpdb;
		if ( ! preg_match( '/^pvn_[a-f0-9]{32}$/', $clave ) ) { return null; }
		$n = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'negocios' ) . ' WHERE clave_hash=%s AND activo=1', self::hash_clave( $clave ) ) );
		return $n ?: null;
	}

	/** Alta o cambio de un punto de recogida. Devuelve su id (0 si faltan datos). */
	public static function guardar_punto( array $p ): int {
		global $wpdb;
		$lat = self::coord( $p['lat'] ?? null, true );
		$lng = self::coord( $p['lng'] ?? null, false );
		$neg = absint( $p['negocio_id'] ?? 0 );
		if ( null === $lat || null === $lng || ! self::negocio( $neg ) ) { return 0; }
		$hora = static fn( $h ): ?string => preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $h ), $m ) ? sprintf( '%02d:%02d:00', $m[1], $m[2] ) : null;
		$dias = preg_replace( '/[^1-7]/', '', (string) ( $p['dias'] ?? '1234567' ) );
		$row = array(
			'negocio_id' => $neg,
			'nombre'     => self::txt( $p['nombre'] ?? '', 80 ) ?: 'Recogida',
			'direccion'  => self::txt( $p['direccion'] ?? '', 160 ),
			'lat'        => $lat,
			'lng'        => $lng,
			'desde'      => $hora( $p['desde'] ?? '' ),
			'hasta'      => $hora( $p['hasta'] ?? '' ),
			'dias'       => '' !== $dias ? $dias : '1234567',
			'telefono'   => CVD_Triciclub::telefono( $p['telefono'] ?? '' ),
			'activo'     => isset( $p['activo'] ) ? ( (int) (bool) $p['activo'] ) : 1,
		);
		$id = absint( $p['id'] ?? 0 );
		if ( $id ) { $wpdb->update( self::t( 'puntos' ), $row, array( 'id' => $id ) ); return $id; }
		$wpdb->insert( self::t( 'puntos' ), $row );
		return (int) $wpdb->insert_id;
	}

	// ================================================================== Encargos

	/** «14:30» (hoy, Cuba), «2026-11-16 14:30» o ISO → UTC MySQL. */
	private static function a_utc( $raw ): ?string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) { return null; }
		try {
			if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $raw, $m ) ) {
				$d = ( new DateTimeImmutable( 'now', self::tz() ) )->setTime( (int) $m[1], (int) $m[2] );
			} else {
				$d = new DateTimeImmutable( $raw, self::tz() );
			}
		} catch ( Exception $e ) {
			return null;
		}
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Crea un encargo (API del negocio o el panel: mismo código). $p = {punto_id, entrega {texto, lat, lng}, recibe {nombre,
	 * telefono}, que, tam, cobrar_cup, ventana {desde, hasta}, nota, ref}. Devuelve el viaje o WP_Error.
	 */
	public static function crear_encargo( object $n, array $p, string $actor = 'negocio' ) {
		global $wpdb;
		if ( ! self::on() ) { return new WP_Error( 'reparto_off', 'Reparto todavía no está abierto.' ); }
		$punto = self::punto( absint( $p['punto_id'] ?? 0 ) );
		if ( ! $punto || (int) $punto->negocio_id !== (int) $n->id || ! (int) $punto->activo ) { return new WP_Error( 'punto', 'Ese punto de recogida no existe.' ); }
		$e = (array) ( $p['entrega'] ?? array() );
		$lat = self::coord( $e['lat'] ?? null, true );
		$lng = self::coord( $e['lng'] ?? null, false );
		if ( null === $lat || null === $lng ) { return new WP_Error( 'entrega', 'Falta dónde entregar (lat y lng).' ); }
		$texto = self::txt( $e['texto'] ?? '', 160 );
		if ( mb_strlen( $texto ) < 3 ) { return new WP_Error( 'entrega', 'Escribe la dirección de entrega.' ); }
		$rc = (array) ( $p['recibe'] ?? array() );
		$rtel = CVD_Triciclub::telefono( $rc['telefono'] ?? '' );
		if ( '' === $rtel ) { return new WP_Error( 'recibe', 'El móvil de quien recibe: 8 números, empieza por 5.' ); }
		$que = self::txt( $p['que'] ?? '', 120 );
		if ( mb_strlen( $que ) < 2 ) { return new WP_Error( 'que', 'Escribe qué se lleva.' ); }
		$tam = sanitize_key( (string) ( $p['tam'] ?? '' ) );
		$tam = isset( CVD_Pv_Tarifa::TAMANOS[ $tam ] ) ? $tam : 'mano';
		$w = (array) ( $p['ventana'] ?? array() );
		$vd = self::a_utc( $w['desde'] ?? '' );
		$vh = self::a_utc( $w['hasta'] ?? '' );
		if ( $vd && $vh && $vh <= $vd ) { return new WP_Error( 'ventana', 'La hora «hasta» va después de «desde».' ); }
		if ( $vh && self::ts( $vh ) < time() ) { return new WP_Error( 'ventana', 'Esa hora ya pasó.' ); }
		$ref = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $p['ref'] ?? '' ) ), 0, 30 );
		$cid = 'n' . (int) $n->id . '-' . ( '' !== $ref ? $ref : wp_generate_password( 12, false ) );
		$vt = self::t( 'viajes' );
		$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$vt} WHERE cliente_id=0 AND cid=%s", $cid ) );
		if ( $ya ) { $ya->repetido = true; return $ya; } // el negocio repitió el mismo encargo (sin señal): no se crea otro
		// Precio: la tarifa justa del mensajero (o del triciclo de carga si es grande), FIJO.
		$precio_de = 'grande' === $tam ? 'tric_carga' : 'mensajero';
		$tray = CVD_Pv_Api::trayecto( CVD_Pv_Api::perfil( 'mensajero' ), (float) $punto->lat, (float) $punto->lng, $lat, $lng );
		$recta = CVD_Triciclub_Precio::recta_m( (float) $punto->lat, (float) $punto->lng, $lat, $lng );
		$metros = CVD_Pv_Tarifa::metros_cobro( $precio_de, (int) $tray['metros'], $recta );
		$noche = CVD_Pv_Tarifa::noche();
		$justo = CVD_Pv_Tarifa::justo( $precio_de, $metros, $noche );
		$producto = 'cobra_devuelve' === $n->cobro ? max( 0, min( 500000, (int) ( $p['cobrar_cup'] ?? 0 ) ) ) : 0;
		$envio_cli = 'cliente' === $n->paga_envio ? $justo : ( 'mitad' === $n->paga_envio ? CVD_Pv_Tarifa::arriba10( $justo / 2 ) : 0 );
		$now = self::now();
		$row = array(
			'uuid'            => wp_generate_uuid4(),
			'cliente_id'      => 0,
			'cid'             => $cid,
			'chofer_id'       => 0,
			'servicio'        => 'paquete',
			'clase'           => 'mensajero',
			'estado'          => 'buscando',
			'origen_texto'    => self::txt( $punto->direccion ?: $punto->nombre, 160 ),
			'origen_lat'      => (float) $punto->lat,
			'origen_lng'      => (float) $punto->lng,
			'destino_texto'   => $texto,
			'destino_lat'     => $lat,
			'destino_lng'     => $lng,
			'distancia_m'     => $metros,
			'minutos'         => CVD_Pv_Tarifa::minutos( $precio_de, $metros ),
			'precio'          => $justo,
			'precio_justo'    => $justo,
			'precio_oferta'   => $justo,
			'primera'         => 1, // el primer «Acepto» se lo queda (precio fijo)
			'noche'           => (int) $noche,
			'forma_pago'      => 'efectivo',
			'codigo'          => str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ),
			'nota'            => self::txt( $p['nota'] ?? '', 200 ),
			'envio_que'       => $que,
			'envio_tam'       => $tam,
			'recibe_nombre'   => self::txt( $rc['nombre'] ?? '', 80 ),
			'recibe_telefono' => $rtel,
			'cobrar_cup'      => $producto + $envio_cli,
			'entrega_codigo'  => str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT ),
			'recoge_texto'    => self::txt( $n->nombre . ' · ' . $punto->nombre, 120 ),
			'rev'             => 1,
			'busca_desde'     => $now,
			'negocio_id'      => (int) $n->id,
			'punto_id'        => (int) $punto->id,
			'ventana_desde'   => $vd,
			'ventana_hasta'   => $vh,
			'cobrar_para'     => $producto > 0 ? 'negocio' : '',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		if ( ! $wpdb->insert( $vt, $row ) ) {
			$ya = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$vt} WHERE cliente_id=0 AND cid=%s", $cid ) );
			return $ya ?: new WP_Error( 'error', 'No se pudo crear el encargo.' );
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->update( $vt, array( 'canal' => CVD_Pv_Canal::nuevo_tema( $id ) ), array( 'id' => $id ) );
		CVD_Triciclub_Db::evento( $id, $actor, 'panel' === $actor ? get_current_user_id() : (int) $n->id, 'pv_encargo', array( 'negocio' => (int) $n->id, 'punto' => (int) $punto->id, 'precio' => $justo ) );
		$v = CVD_Pv_Api::viaje( $id );
		if ( 'te_toca' === $n->asignar ) { self::empezar( $v ); }
		else { CVD_Pv_Api::avisar_choferes( $v, false ); }
		return CVD_Pv_Api::viaje( $id );
	}

	/** Encargo de un negocio por uuid (o null). */
	public static function encargo( object $n, string $uuid ): ?object {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'viajes' ) . ' WHERE uuid=%s AND negocio_id=%d', $uuid, (int) $n->id ) );
		return $id ? CVD_Pv_Api::viaje( $id ) : null;
	}

	private static function firma_seguir( string $uuid ): string { return substr( hash_hmac( 'sha256', 'pv-seguir|' . $uuid, wp_salt( 'auth' ) ), 0, 24 ); }

	public static function seguimiento_url( object $v ): string {
		return add_query_arg( array( 'u' => (string) $v->uuid, 'f' => self::firma_seguir( (string) $v->uuid ) ), rest_url( self::NS . '/pv/seguir' ) );
	}

	/** El nombre del mensajero como lo ve el negocio / quien recibe: «Pedri R.» o «Isabel (de Pedri)». */
	public static function mensajero_nombre( object $v ): string {
		if ( ! (int) $v->chofer_id ) { return ''; }
		$pub = CVD_Pv_Api::chofer_publico( (int) $v->chofer_id );
		$nom = $pub ? (string) $pub['nombre'] : 'Mensajero';
		return (int) $v->lleva_id ? self::nombre_lleva( $v, $nom ) : $nom;
	}

	/** El encargo como lo ve el negocio. */
	public static function vista_negocio( object $v ): array {
		$activo = in_array( $v->estado, self::ACTIVOS, true ) && ! self::programado( $v );
		$tel = '';
		if ( (int) $v->chofer_id && in_array( $v->estado, self::ACTIVOS, true ) ) {
			$tel = preg_replace( '/\D+/', '', (string) ( get_user_meta( (int) $v->chofer_id, 'cvd_tc_telefono', true ) ?: get_user_meta( (int) $v->chofer_id, 'billing_phone', true ) ) );
		}
		return array(
			'id'              => (int) $v->id,
			'uuid'            => (string) $v->uuid,
			'ref'             => (string) preg_replace( '/^n\d+-/', '', (string) $v->cid ),
			'estado'          => (string) $v->estado,
			'precio'          => (int) $v->precio,
			'cobrar'          => (int) $v->cobrar_cup,
			'punto_id'        => (int) $v->punto_id,
			'entrega'         => array( 'texto' => (string) $v->destino_texto, 'lat' => (float) $v->destino_lat, 'lng' => (float) $v->destino_lng ),
			'recibe'          => array( 'nombre' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono ),
			'que'             => (string) $v->envio_que,
			'codigo_entrega'  => (string) $v->entrega_codigo,
			'mensajero'       => self::mensajero_nombre( $v ),
			'mensajero_tel'   => $tel,
			'sale'            => CVD_Pv_Api::iso( $v->sale_at ),
			'sale_hora'       => self::hora( $v->sale_at ),
			'programado'      => self::programado( $v ),
			'pos'             => $activo ? CVD_Pv_Api::posicion( $v ) : null,
			'entregado'       => CVD_Pv_Api::iso( $v->terminado_at ),
			'foto'            => '' !== (string) $v->entrega_foto,
			'creado'          => CVD_Pv_Api::iso( $v->created_at ),
			'seguimiento_url' => self::seguimiento_url( $v ),
		);
	}

	/** Cancela un encargo (negocio o panel). '' o por qué no. */
	public static function cancelar( object $v, string $por, string $motivo = '' ): string {
		global $wpdb;
		if ( 'cancelado' === $v->estado ) { return ''; }
		if ( ! in_array( $v->estado, array( 'buscando', 'sin_chofer', 'asignado', 'llego' ), true ) ) { return 'Ya lo recogieron: no se puede cancelar.'; }
		$now = self::now();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET estado='cancelado', cancelado_at=%s, cancelado_por=%s, motivo=%s, updated_at=%s WHERE id=%d AND estado=%s", $now, substr( $por, 0, 8 ), mb_substr( $motivo, 0, 200 ), $now, (int) $v->id, $v->estado ) );
		if ( ! $n ) { return 'El encargo cambió. Mira otra vez.'; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ofertas' ) . " SET estado='caducada', updated_at=%s WHERE viaje_id=%d AND estado='activa'", $now, (int) $v->id ) );
		self::vencer_turnos( (int) $v->id, 0 );
		CVD_Triciclub_Db::evento( (int) $v->id, $por, 'panel' === $por ? get_current_user_id() : (int) $v->negocio_id, 'cancelado', array( 'antes' => $v->estado ) );
		$v2 = CVD_Pv_Api::viaje( (int) $v->id );
		CVD_Pv_Api::tocar( $v2, 'cancelado' );
		if ( 'buscando' === $v->estado ) { CVD_Pv_Api::cerrar_avisos( $v2 ); }
		if ( (int) $v->chofer_id ) {
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( (int) $v->chofer_id ), array( 't' => 'cancelado', 'id' => (int) $v->id ) );
			CVD_Pv_Api::aviso( (int) $v->chofer_id, 'pv_cancelado', 'Encargo cancelado', 'Ya no vayas a ' . CVD_Pv_Api::corto( (string) $v->origen_texto ) . '.', array( 'pv', 'cancelled' ) );
		}
		return '';
	}

	// ================================================================== «Te toca»

	/**
	 * Mensajeros que pueden llevar este encargo, del mejor al peor: [[puntos, user_id, metros], …]. Filtro: en línea,
	 * sirve para el tamaño, a ≤ radio del punto, sin pausa por deuda, con menos de `tope` viajes abiertos, no excluido.
	 * Puntos (0–100): cercanía 40 · valoración 25 · pocas carreras hoy 20 · cumplimiento (no soltó en 30 días) 15.
	 */
	public static function candidatos( object $v, array $excluir = array() ): array {
		global $wpdb;
		$radio = self::radio();
		$lista = array();
		foreach ( CVD_Pv_Api::en_linea( (float) $v->origen_lat, (float) $v->origen_lng ) as $ch ) {
			if ( in_array( (int) $ch->user_id, $excluir, true ) ) { continue; }
			if ( ! CVD_Pv_Api::sirve( $ch, 'mensajero', (string) $v->envio_tam ) ) { continue; }
			$d = CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $v->origen_lat, (float) $v->origen_lng );
			if ( $d > $radio ) { continue; }
			$lista[ (int) $ch->user_id ] = array( $ch, $d );
		}
		if ( ! $lista ) { return array(); }
		$ids = implode( ',', array_map( 'intval', array_keys( $lista ) ) );
		$vt = self::t( 'viajes' );
		$abiertas = array();
		foreach ( $wpdb->get_results( "SELECT chofer_id, COUNT(*) n FROM {$vt} WHERE chofer_id IN ({$ids}) AND estado IN ('asignado','llego','recogido') GROUP BY chofer_id" ) as $r ) { $abiertas[ (int) $r->chofer_id ] = (int) $r->n; }
		$inicio = ( new DateTimeImmutable( 'today', self::tz() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$hoy = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT chofer_id, COUNT(*) n FROM {$vt} WHERE chofer_id IN ({$ids}) AND estado IN ('asignado','llego','recogido','terminado') AND respondido_at>=%s GROUP BY chofer_id", $inicio ) ) as $r ) { $hoy[ (int) $r->chofer_id ] = (int) $r->n; }
		$fallos = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT actor_id, COUNT(*) n FROM ' . self::t( 'eventos' ) . " WHERE actor_id IN ({$ids}) AND tipo IN ('pv_suelto','pv_no_confirmo') AND created_at>%s GROUP BY actor_id", self::utc( time() - 30 * DAY_IN_SECONDS ) ) ) as $r ) { $fallos[ (int) $r->actor_id ] = (int) $r->n; }
		$tope = self::tope();
		$pausa = array();
		$out = array();
		foreach ( $lista as $uid => list( $ch, $d ) ) {
			if ( ( $abiertas[ $uid ] ?? 0 ) >= $tope ) { continue; }
			$g = CVD_Pv_Tarifa::grupo( CVD_Pv_Api::clase_de( $ch ) );
			if ( ! isset( $pausa[ $uid ] ) ) { $pausa[ $uid ] = CVD_Pv_Cuenta::pausa_efectivo( $uid, $g ); }
			if ( $pausa[ $uid ] ) { continue; }
			$nota = null !== $ch->nota_media ? (float) $ch->nota_media : 3.5;
			$pts = 40 * ( 1 - $d / $radio ) + 25 * max( 0, min( 5, $nota ) ) / 5 + 20 * ( 1 - min( 5, $hoy[ $uid ] ?? 0 ) / 5 ) + 15 * max( 0, 1 - 0.25 * ( $fallos[ $uid ] ?? 0 ) );
			$out[] = array( (int) round( $pts ), $uid, $d );
		}
		usort( $out, static fn( array $a, array $b ): int => array( $b[0], $a[2], $a[1] ) <=> array( $a[0], $b[2], $b[1] ) );
		return $out;
	}

	/** Ofrece el encargo a los $n mejores que aún no lo vieron. Devuelve a cuántos. */
	private static function ofrecer( object $v, int $ronda, int $n ): int {
		global $wpdb;
		$tt = self::t( 'turnos' );
		$ya = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$tt} WHERE viaje_id=%d", (int) $v->id ) ) );
		$c = array_slice( self::candidatos( $v, $ya ), 0, $n );
		$now = self::now();
		$hasta = self::utc( time() + self::TURNO_SEG );
		$titulo = '📦 Te toca · ' . self::cup( (int) $v->precio ) . ' CUP';
		$txt = CVD_Pv_Api::corto( (string) $v->recoge_texto ) . ' → ' . CVD_Pv_Api::corto( (string) $v->destino_texto ) . ' · ' . number_format( (int) $v->distancia_m / 1000, 1, ',', '' ) . ' km';
		foreach ( $c as $x ) {
			$wpdb->insert( $tt, array( 'viaje_id' => (int) $v->id, 'chofer_id' => $x[1], 'ronda' => $ronda, 'estado' => 'ofrecido', 'puntos' => $x[0], 'hasta' => $hasta, 'created_at' => $now ) );
			CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $x[1] ), array( 't' => 'tetoca', 'id' => (int) $v->id ) );
			CVD_Pv_Api::aviso( $x[1], 'pv_tetoca', $titulo, $txt, array( 'pv', 'tetoca', 'v' . (int) $v->id ) ); // «v<id>»: el botón Aceptar del aviso
		}
		if ( $c ) { CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', 0, 'pv_tetoca', array( 'ronda' => $ronda, 'a' => array_column( $c, 1 ) ) ); }
		return count( $c );
	}

	/** Fase 1: al mejor. Sin nadie: abierto a todos. */
	public static function empezar( object $v ): void {
		global $wpdb;
		if ( ! $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET turno_fase=1, busca_desde=%s, updated_at=%s WHERE id=%d AND estado='buscando' AND turno_fase=0", self::now(), self::now(), (int) $v->id ) ) ) { return; }
		if ( ! self::ofrecer( $v, 1, 1 ) ) { self::abrir( $v ); }
	}

	/** Pasa a la fase siguiente (con UPDATE … WHERE fase = la de antes: dos ticks a la vez no lo hacen dos veces). */
	private static function avanzar( object $v ): void {
		global $wpdb;
		$f = (int) $v->turno_fase;
		if ( 1 === $f ) {
			if ( ! $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET turno_fase=2, updated_at=%s WHERE id=%d AND estado='buscando' AND turno_fase=1", self::now(), (int) $v->id ) ) ) { return; }
			if ( self::ofrecer( $v, 2, 3 ) ) { return; }
			$v->turno_fase = 2;
		}
		self::abrir( $v );
	}

	/** Fase 3: abierto a todos los cercanos, como Pa'llá transporte («Acepto» al precio fijo). */
	private static function abrir( object $v ): void {
		global $wpdb;
		$now = self::now();
		if ( ! $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET turno_fase=3, primera=1, busca_desde=%s, updated_at=%s WHERE id=%d AND estado='buscando' AND turno_fase IN (1,2)", $now, $now, (int) $v->id ) ) ) { return; }
		self::vencer_turnos( (int) $v->id, 0 );
		CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', 0, 'pv_abierta' );
		$v2 = CVD_Pv_Api::viaje( (int) $v->id );
		CVD_Pv_Api::avisar_choferes( $v2, false );
		CVD_Pv_Api::tocar( $v2, 'abierta' );
	}

	/** Los turnos aún ofrecidos (menos el de $menos) pasan a «vencido» y su aviso se cierra. */
	private static function vencer_turnos( int $viaje_id, int $menos ): void {
		global $wpdb;
		$tt = self::t( 'turnos' );
		$uids = $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$tt} WHERE viaje_id=%d AND estado='ofrecido' AND chofer_id<>%d", $viaje_id, $menos ) );
		if ( ! $uids ) { return; }
		$wpdb->query( $wpdb->prepare( "UPDATE {$tt} SET estado='vencido' WHERE viaje_id=%d AND estado='ofrecido' AND chofer_id<>%d", $viaje_id, $menos ) );
		foreach ( array_map( 'intval', $uids ) as $uid ) {
			try { if ( class_exists( 'CVD_Palla_Push' ) ) { CVD_Palla_Push::cerrar( $uid, array( 'pv', 'cerrada', 'v' . $viaje_id ) ); } } catch ( Throwable $e ) { unset( $e ); }
		}
	}

	/** Un encargo que el mensajero soltó vuelve a «Te toca» sin él. false = no es un encargo «Te toca» (el motor lo abre). */
	public static function relanzar( object $v, int $solto ): bool {
		global $wpdb;
		if ( ! self::on() || ! (int) ( $v->negocio_id ?? 0 ) ) { return false; }
		if ( $solto ) { $wpdb->insert( self::t( 'turnos' ), array( 'viaje_id' => (int) $v->id, 'chofer_id' => $solto, 'ronda' => 0, 'estado' => 'solto', 'hasta' => self::now(), 'created_at' => self::now() ) ); }
		$n = self::negocio( (int) $v->negocio_id );
		if ( ! $n || 'te_toca' !== $n->asignar ) { return false; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET turno_fase=0 WHERE id=%d AND estado='buscando'", (int) $v->id ) );
		$v2 = CVD_Pv_Api::viaje( (int) $v->id );
		if ( $v2 && 'buscando' === $v2->estado ) { self::empezar( $v2 ); }
		return true;
	}

	/** Si ya no queda nadie con el turno vivo, la fase siguiente. */
	private static function revisar_turno( object $v ): void {
		global $wpdb;
		$tt = self::t( 'turnos' );
		$viejos = $wpdb->get_col( $wpdb->prepare( "SELECT chofer_id FROM {$tt} WHERE viaje_id=%d AND estado='ofrecido' AND hasta<=%s", (int) $v->id, self::now() ) );
		if ( $viejos ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$tt} SET estado='vencido' WHERE viaje_id=%d AND estado='ofrecido' AND hasta<=%s", (int) $v->id, self::now() ) );
			foreach ( array_map( 'intval', $viejos ) as $uid ) {
				try { if ( class_exists( 'CVD_Palla_Push' ) ) { CVD_Palla_Push::cerrar( $uid, array( 'pv', 'cerrada', 'v' . (int) $v->id ) ); } } catch ( Throwable $e ) { unset( $e ); }
			}
		}
		$vivos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} WHERE viaje_id=%d AND estado='ofrecido'", (int) $v->id ) );
		if ( ! $vivos ) { self::avanzar( $v ); }
	}

	/**
	 * El reloj de Reparto: avanza las fases de «Te toca», manda «¿Sigues?», suelta a quien no confirmó y pasa a «sin
	 * chofer» los encargos abiertos que nadie cogió. Se llama al leer (negocio, panel, chofer/hoy; como mucho cada 5 s)
	 * y con wp-cron cada minuto. Con el interruptor apagado no hace nada.
	 */
	public static function tick( bool $forzar = false ): void {
		if ( ! self::on() ) { return; }
		global $wpdb;
		if ( ! $forzar ) {
			$t = (int) get_option( 'cvd_pv_tick_at', 0 );
			if ( $t > time() - 5 ) { return; }
		}
		update_option( 'cvd_pv_tick_at', time(), false );
		if ( '1' !== (string) $wpdb->get_var( "SELECT GET_LOCK('cvd_pv_tick', 0)" ) ) { return; }
		try {
			$vt = self::t( 'viajes' );
			$now = self::now();
			// 1) «Te toca»: rondas vencidas o rechazadas por todos.
			foreach ( $wpdb->get_results( "SELECT * FROM {$vt} WHERE estado='buscando' AND turno_fase IN (1,2) ORDER BY id LIMIT 100" ) as $v ) { self::revisar_turno( $v ); }
			// 2) «¿Sigues?» 30 min antes; sin «Sigo» 10 min antes, se suelta solo.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$vt} WHERE estado='asignado' AND sale_at IS NOT NULL AND sigue_at IS NULL AND sale_at<=%s ORDER BY sale_at LIMIT 50", self::utc( time() + self::SIGUES_MIN * 60 ) ) );
			foreach ( $rows as $v ) {
				$uid = (int) $v->chofer_id;
				if ( self::ts( (string) $v->sale_at ) <= time() + self::SUELTA_MIN * 60 ) {
					$u = get_userdata( $uid );
					if ( ! $u ) { continue; }
					CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', $uid, 'pv_no_confirmo' );
					CVD_Pv_Api::soltar( $v, $u, 'No confirmó la salida' );
					try { if ( class_exists( 'CVD_Palla_Push' ) ) { CVD_Palla_Push::cerrar( $uid, array( 'pv', 'cerrada', 'v' . (int) $v->id ) ); } } catch ( Throwable $e ) { unset( $e ); }
					CVD_Pv_Api::aviso( $uid, 'pv_suelto', 'Encargo soltado', 'No dijiste «Sigo». Ya es de otro.', array( 'pv', 'suelto', 'v' . (int) $v->id ) );
					continue;
				}
				if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'eventos' ) . " WHERE viaje_id=%d AND tipo='pv_sigues' AND actor_id=%d LIMIT 1", (int) $v->id, $uid ) ) ) { continue; }
				CVD_Triciclub_Db::evento( (int) $v->id, 'sistema', $uid, 'pv_sigues' );
				CVD_Pv_Canal::senal( CVD_Pv_Canal::tema_chofer( $uid ), array( 't' => 'sigues', 'id' => (int) $v->id ) );
				CVD_Pv_Api::aviso( $uid, 'pv_sigues', '¿Sigues? Sales a las ' . self::hora( (string) $v->sale_at ), CVD_Pv_Api::corto( (string) $v->recoge_texto ) . ' → ' . CVD_Pv_Api::corto( (string) $v->destino_texto ), array( 'pv', 'sigues', 'v' . (int) $v->id ) );
			}
			// 3) Abiertos que nadie cogió → «sin chofer» (lo hace caducar al leerlos).
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$vt} WHERE estado='buscando' AND negocio_id>0 AND turno_fase IN (0,3) AND busca_desde<%s LIMIT 100", self::utc( time() - CVD_Pv_Api::BUSCA_SEG ) ) );
			foreach ( array_map( 'intval', (array) $ids ) as $id ) { CVD_Pv_Api::viaje( $id ); }
			unset( $now );
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('cvd_pv_tick')" );
		}
	}

	// ================================================================== «¿Cuándo sales?»

	/**
	 * Las horas en que puede salir: de max(ahora, ventana desde, apertura del punto) a min(ventana hasta, cierre del punto),
	 * de 15 en 15 min, el primer día que abre el punto. {ahora: bool, horas: [{hora, at}], desde, hasta}.
	 */
	public static function opciones( object $v ): array {
		$tz = self::tz();
		$ahora = new DateTimeImmutable( 'now', $tz );
		$lo = $ahora;
		if ( ! empty( $v->ventana_desde ) ) { $vd = ( new DateTimeImmutable( '@' . self::ts( (string) $v->ventana_desde ) ) )->setTimezone( $tz ); if ( $vd > $lo ) { $lo = $vd; } }
		$p = self::punto( (int) $v->punto_id );
		$dias = $p && '' !== (string) $p->dias ? (string) $p->dias : '1234567';
		$dia = $lo->setTime( 0, 0 );
		for ( $i = 0; $i < 7 && false === strpos( $dias, $dia->format( 'N' ) ); $i++ ) { $dia = $dia->modify( '+1 day' ); }
		if ( $dia->format( 'Y-m-d' ) !== $lo->format( 'Y-m-d' ) ) { $lo = $dia; }
		$hm = static function ( ?string $t, string $def ): array { $x = explode( ':', $t ? (string) $t : $def ); return array( (int) $x[0], (int) ( $x[1] ?? 0 ) ); };
		list( $dh, $dm ) = $hm( $p ? $p->desde : null, '00:00' );
		list( $hh, $hmm ) = $hm( $p ? $p->hasta : null, '23:59' );
		$abre = $dia->setTime( $dh, $dm );
		$cierra = $dia->setTime( $hh, $hmm );
		if ( $abre > $lo ) { $lo = $abre; }
		$hi = $cierra;
		if ( ! empty( $v->ventana_hasta ) ) { $vh = ( new DateTimeImmutable( '@' . self::ts( (string) $v->ventana_hasta ) ) )->setTimezone( $tz ); if ( $vh < $hi ) { $hi = $vh; } }
		$min = (int) $lo->format( 'i' );
		$t = $lo->setTime( (int) $lo->format( 'H' ), 0 )->modify( '+' . ( (int) ceil( $min / self::PASO_MIN ) * self::PASO_MIN ) . ' minutes' );
		$horas = array();
		while ( $t <= $hi && count( $horas ) < 48 ) {
			if ( $t > $ahora ) { $horas[] = array( 'hora' => $t->format( 'H:i' ), 'at' => $t->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' ) ); }
			$t = $t->modify( '+' . self::PASO_MIN . ' minutes' );
		}
		$puede_ahora = $lo->getTimestamp() <= time() + self::PASO_MIN * 60 || ( ! $horas );
		return array( 'ahora' => $puede_ahora, 'horas' => $horas, 'desde' => $lo->format( 'H:i' ), 'hasta' => $hi->format( 'H:i' ) );
	}

	// ================================================================== Persona de confianza

	public static function confianza( int $id ): ?object {
		global $wpdb;
		return $id ? ( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'confianza' ) . ' WHERE id=%d', $id ) ) ?: null ) : null;
	}

	public static function mis_confianza( int $uid ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'confianza' ) . " WHERE chofer_id=%d AND estado IN ('pendiente','aprobada','rechazada') ORDER BY id DESC LIMIT 10", $uid ) );
		return array_map( static fn( object $c ): array => array( 'id' => (int) $c->id, 'nombre' => (string) $c->nombre, 'telefono' => (string) $c->telefono, 'estado' => (string) $c->estado ), $rows );
	}

	private static function aprobadas( int $uid ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, nombre FROM ' . self::t( 'confianza' ) . " WHERE chofer_id=%d AND estado='aprobada' ORDER BY id", $uid ) );
		return array_map( static fn( object $c ): array => array( 'id' => (int) $c->id, 'nombre' => (string) $c->nombre ), $rows );
	}

	/** Panel: aprobar | rechazar | anular (anular sube la época: su enlace deja de valer). */
	public static function confianza_estado( int $id, string $accion, int $por ): bool {
		global $wpdb;
		$c = self::confianza( $id );
		if ( ! $c ) { return false; }
		$set = array( 'updated_at' => self::now() );
		if ( 'aprobar' === $accion ) { $set += array( 'estado' => 'aprobada', 'aprobado_por' => $por ); }
		elseif ( 'rechazar' === $accion ) { $set['estado'] = 'rechazada'; }
		elseif ( 'anular' === $accion ) { $set += array( 'estado' => 'anulada', 'epoca' => (int) $c->epoca + 1 ); }
		else { return false; }
		$wpdb->update( self::t( 'confianza' ), $set, array( 'id' => $id ) );
		if ( 'aprobar' !== $accion ) { // deja de llevar los viajes que tenía
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'viajes' ) . " SET lleva_id=0 WHERE lleva_id=%d AND estado IN ('asignado','llego','recogido')", $id ) );
		}
		return true;
	}

	private static function clave_ayuda(): string { return wp_salt( 'auth' ) . '|cvd-pv-ayuda'; }

	private static function firma_ayuda( int $id, int $exp, int $epoca ): string { return substr( hash_hmac( 'sha256', 'pv-ayuda|' . $id . '|' . $exp . '|' . $epoca, self::clave_ayuda() ), 0, 32 ); }

	public static function token_ayuda( object $c ): string {
		$exp = time() + CVD_Pv::TTL;
		return 'a' . (int) $c->id . '.' . $exp . '.' . self::firma_ayuda( (int) $c->id, $exp, (int) $c->epoca );
	}

	/** El enlace de la persona de confianza (se lo manda Ernesto o el bot, nunca esta clase). */
	public static function enlace_ayuda( object $c ): string { return home_url( '/palla/chofer/' ) . '#a=' . self::token_ayuda( $c ); }

	public static function verificar_ayuda( string $token ): ?object {
		if ( ! preg_match( '/^a(\d{1,10})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) ) { return null; }
		if ( (int) $m[2] < time() ) { return null; }
		$c = self::confianza( (int) $m[1] );
		if ( ! $c || 'aprobada' !== $c->estado ) { return null; }
		return hash_equals( self::firma_ayuda( (int) $c->id, (int) $m[2], (int) $c->epoca ), $m[3] ) ? $c : null;
	}

	// ================================================================== Rutas

	public static function routes(): void {
		$r = static function ( string $path, string $method, string $cb, $perm ): void {
			register_rest_route( self::NS, '/pv/' . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$n = array( __CLASS__, 'auth_negocio' );
		$d = array( __CLASS__, 'auth_chofer' );
		$a = array( __CLASS__, 'auth_ayuda' );
		$u = '(?P<uuid>[a-f0-9-]{36})';
		$r( 'negocio/encargos', 'POST', 'api_crear', $n );
		$r( 'negocio/encargos', 'GET', 'api_lista', $n );
		$r( 'negocio/encargos/' . $u, 'GET', 'api_ver', $n );
		$r( 'negocio/encargos/' . $u . '/cancelar', 'POST', 'api_cancelar', $n );
		$r( 'chofer/turnos/(?P<id>\d+)/aceptar', 'POST', 'turno_aceptar', $d );
		$r( 'chofer/turnos/(?P<id>\d+)/rechazar', 'POST', 'turno_rechazar', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/salida', 'POST', 'salida', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/sigo', 'POST', 'sigo', $d );
		$r( 'chofer/viajes/(?P<id>\d+)/lleva', 'POST', 'lleva', $d );
		$r( 'chofer/confianza', 'GET', 'confianza_lista', $d );
		$r( 'chofer/confianza', 'POST', 'confianza_alta', $d );
		$r( 'ayuda/hoy', 'GET', 'ayuda_hoy', $a );
		$r( 'ayuda/viajes/(?P<id>\d+)/estado', 'POST', 'ayuda_estado', $a );
		$r( 'ayuda/viajes/(?P<id>\d+)/foto', 'POST', 'ayuda_foto', $a );
		$r( 'seguir', 'GET', 'seguir', '__return_true' );
	}

	private static function cerrado(): WP_Error { return new WP_Error( 'pv_reparto_off', 'Reparto todavía no está abierto.', array( 'status' => 403 ) ); }

	public static function auth_negocio( WP_REST_Request $request ) {
		if ( ! self::on() || 'no' === CVD_Pv::mode() ) { return self::cerrado(); }
		$n = self::negocio_por_clave( trim( (string) $request->get_header( 'x_negocio_clave' ) ) );
		return $n ? true : new WP_Error( 'pv_clave', 'Clave de negocio no válida.', array( 'status' => 401 ) );
	}

	private static function neg( WP_REST_Request $request ): object { return self::negocio_por_clave( trim( (string) $request->get_header( 'x_negocio_clave' ) ) ); }

	public static function auth_chofer( WP_REST_Request $request ) {
		if ( ! self::on() ) { return self::cerrado(); }
		return CVD_Pv_Api::auth_chofer( $request );
	}

	public static function auth_ayuda( WP_REST_Request $request ) {
		if ( ! self::on() || 'no' === CVD_Pv::mode() ) { return self::cerrado(); }
		$c = self::verificar_ayuda( trim( (string) $request->get_header( 'x_pv_ayuda' ) ) );
		if ( ! $c ) { return new WP_Error( 'pv_ayuda', 'Tu enlace ya no vale. Pídele uno nuevo a Pa\'llá.', array( 'status' => 401 ) ); }
		if ( 'prueba' === CVD_Pv::mode() && ! in_array( (int) $c->chofer_id, CVD_Triciclub::testers(), true ) ) { return new WP_Error( 'pv_not_tester', CVD_Pv::texto_cerrado( 'not_tester' ), array( 'status' => 403 ) ); }
		return true;
	}

	private static function ayuda( WP_REST_Request $request ): object { return self::verificar_ayuda( trim( (string) $request->get_header( 'x_pv_ayuda' ) ) ); }

	// ------------------------------------------------------------------ Negocio

	/** POST negocio/encargos (JSON o formulario). */
	public static function api_crear( WP_REST_Request $request ): WP_REST_Response {
		$n = self::neg( $request );
		$p = $request->get_json_params();
		$p = is_array( $p ) && $p ? $p : $request->get_params();
		$v = self::crear_encargo( $n, (array) $p );
		if ( is_wp_error( $v ) ) { return self::fail( $v->get_error_code(), $v->get_error_message() ); }
		self::tick();
		return self::out( array( 'ok' => true, 'repetido' => ! empty( $v->repetido ), 'id' => (int) $v->id, 'uuid' => (string) $v->uuid, 'estado' => (string) $v->estado, 'precio' => (int) $v->precio, 'cobrar' => (int) $v->cobrar_cup, 'codigo_entrega' => (string) $v->entrega_codigo, 'seguimiento_url' => self::seguimiento_url( $v ) ) );
	}

	public static function api_ver( WP_REST_Request $request ): WP_REST_Response {
		self::tick();
		$v = self::encargo( self::neg( $request ), (string) $request['uuid'] );
		if ( ! $v ) { return self::fail( 'no_existe', 'No encontramos ese encargo.', 404 ); }
		return self::out( array( 'ok' => true, 'encargo' => self::vista_negocio( $v ) ) );
	}

	public static function api_lista( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		self::tick();
		$n = self::neg( $request );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'viajes' ) . ' WHERE negocio_id=%d ORDER BY id DESC LIMIT 50', (int) $n->id ) );
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) { $v = CVD_Pv_Api::viaje( $id ); if ( $v ) { $out[] = self::vista_negocio( $v ); } }
		return self::out( array( 'ok' => true, 'encargos' => $out, 'puntos' => array_map( array( __CLASS__, 'punto_vista' ), self::puntos_de( (int) $n->id ) ) ) );
	}

	public static function api_cancelar( WP_REST_Request $request ): WP_REST_Response {
		$v = self::encargo( self::neg( $request ), (string) $request['uuid'] );
		if ( ! $v ) { return self::fail( 'no_existe', 'No encontramos ese encargo.', 404 ); }
		$err = self::cancelar( $v, 'negocio', self::txt( $request->get_param( 'motivo' ), 200 ) );
		if ( '' !== $err ) { return self::fail( 'tarde', $err, 409 ); }
		return self::out( array( 'ok' => true, 'encargo' => self::vista_negocio( CVD_Pv_Api::viaje( (int) $v->id ) ) ) );
	}

	/** GET seguir?u&f — enlace firmado para quien recibe: estado, mensajero y «sale a las…». */
	public static function seguir( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$uuid = (string) $request->get_param( 'u' );
		if ( ! self::on() || ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || ! hash_equals( self::firma_seguir( $uuid ), (string) $request->get_param( 'f' ) ) ) { return self::fail( 'no_existe', 'No encontramos ese envío.', 404 ); }
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'viajes' ) . ' WHERE uuid=%s AND negocio_id>0', $uuid ) );
		$v = $id ? CVD_Pv_Api::viaje( $id ) : null;
		if ( ! $v ) { return self::fail( 'no_existe', 'No encontramos ese envío.', 404 ); }
		$n = self::negocio( (int) $v->negocio_id );
		$activo = in_array( $v->estado, self::ACTIVOS, true ) && ! self::programado( $v );
		return self::out( array( 'ok' => true, 'estado' => (string) $v->estado, 'negocio' => $n ? (string) $n->nombre : '', 'que' => (string) $v->envio_que, 'mensajero' => self::mensajero_nombre( $v ), 'sale_hora' => self::hora( $v->sale_at ), 'pos' => $activo ? CVD_Pv_Api::posicion( $v ) : null, 'entregado' => CVD_Pv_Api::iso( $v->terminado_at ) ) );
	}

	// ------------------------------------------------------------------ Chofer

	private static function user( WP_REST_Request $request ): WP_User { return CVD_Pv::request_chofer( $request ); }

	private static function mio( WP_REST_Request $request, WP_User $u ): ?object {
		$v = CVD_Pv_Api::viaje( absint( $request['id'] ) );
		return $v && (int) $v->chofer_id === $u->ID && (int) $v->negocio_id ? $v : null;
	}

	/** POST chofer/turnos/{viaje}/aceptar — le tocaba a él: se lo queda al precio fijo (con el candado de asignar). */
	public static function turno_aceptar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$ch = CVD_Triciclub::chofer_de( $u->ID );
		if ( ! $ch || 'aprobado' !== $ch->estado ) { return self::fail( 'no_aprobado', CVD_Pv::texto_cerrado( 'no_chofer' ), 403 ); }
		$v = CVD_Pv_Api::viaje( absint( $request['id'] ) );
		if ( ! $v || ! (int) $v->negocio_id ) { return self::fail( 'no_existe', 'No encontramos ese encargo.', 404 ); }
		if ( (int) $v->chofer_id === $u->ID && in_array( $v->estado, self::ACTIVOS, true ) ) { return self::out( array( 'ok' => true, 'repetido' => true, 'viaje' => CVD_Pv_Api::vista_chofer( $v ) ) ); }
		if ( 'buscando' !== $v->estado ) { return self::fail( 'tarde', 'Lo cogió otro.', 409 ); }
		$tt = self::t( 'turnos' );
		$turno = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE viaje_id=%d AND chofer_id=%d AND estado='ofrecido' ORDER BY id DESC LIMIT 1", (int) $v->id, $u->ID ) );
		if ( ! $turno || self::ts( (string) $turno->hasta ) < time() - 5 ) { return self::fail( 'tarde', 'Se acabó tu turno.', 409 ); }
		if ( self::abiertas( $u->ID ) >= self::tope() ) { return self::fail( 'tope', 'Termina uno antes de coger otro.', 409 ); }
		// Una oferta «Acepto» al precio fijo y el motor la asigna (candado por chofer + UPDATE … WHERE estado='buscando').
		$of = self::t( 'ofertas' );
		$now = self::now();
		$lejos = null !== $ch->lat ? CVD_Triciclub_Precio::recta_m( (float) $ch->lat, (float) $ch->lng, (float) $v->origen_lat, (float) $v->origen_lng ) : 0;
		$datos = array( 'precio' => (int) $v->precio_oferta, 'pct' => 0, 'llega_min' => CVD_Pv_Tarifa::minutos( CVD_Pv_Api::clase_de( $ch ), (int) round( $lejos * 1.3 ) ), 'lejos_m' => $lejos, 'estado' => 'activa', 'vence_at' => self::utc( time() + CVD_Pv_Api::OFERTA_SEG ), 'updated_at' => $now );
		$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$of} WHERE viaje_id=%d AND chofer_id=%d", (int) $v->id, $u->ID ) );
		if ( $oid ) { $wpdb->update( $of, $datos, array( 'id' => $oid ) ); }
		else { $wpdb->insert( $of, $datos + array( 'viaje_id' => (int) $v->id, 'chofer_id' => $u->ID, 'created_at' => $now ) ); $oid = (int) $wpdb->insert_id; }
		$err = CVD_Pv_Api::asignar( $v, $oid );
		if ( '' !== $err ) { return self::fail( 'tarde', 'Este pedido ya cambió.' === $err ? 'Lo cogió otro.' : $err, 409 ); }
		$wpdb->update( $tt, array( 'estado' => 'aceptado' ), array( 'id' => (int) $turno->id ) );
		self::vencer_turnos( (int) $v->id, $u->ID );
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_tetoca_acepto', array( 'ronda' => (int) $turno->ronda ) );
		return self::out( array( 'ok' => true, 'viaje' => CVD_Pv_Api::vista_chofer( CVD_Pv_Api::viaje( (int) $v->id ) ) ) );
	}

	/** POST chofer/turnos/{viaje}/rechazar — pasa al siguiente sin esperar los 45 s. */
	public static function turno_rechazar( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = CVD_Pv_Api::viaje( absint( $request['id'] ) );
		if ( ! $v || ! (int) $v->negocio_id ) { return self::fail( 'no_existe', 'No encontramos ese encargo.', 404 ); }
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'turnos' ) . " SET estado='rechazado' WHERE viaje_id=%d AND chofer_id=%d AND estado='ofrecido'", (int) $v->id, $u->ID ) );
		if ( $n ) {
			CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_tetoca_rechazo' );
			if ( 'buscando' === $v->estado && in_array( (int) $v->turno_fase, array( 1, 2 ), true ) ) { self::revisar_turno( $v ); }
		}
		return self::out( array( 'ok' => true ) );
	}

	/** POST chofer/viajes/{id}/salida {cuando: "ahora" | "HH:MM"} */
	public static function salida( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::mio( $request, $u );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese encargo ya no es tuyo.', 404 ); }
		if ( 'asignado' !== $v->estado ) { return self::fail( 'tarde', 'Ya saliste.', 409 ); }
		$cuando = trim( (string) $request->get_param( 'cuando' ) );
		$op = self::opciones( $v );
		if ( 'ahora' === $cuando ) {
			if ( ! $op['ahora'] ) { return self::fail( 'hora', 'Todavía no abren. Elige una hora.' ); }
			$sale = self::now();
		} else {
			$at = '';
			foreach ( $op['horas'] as $h ) { if ( $h['hora'] === $cuando ) { $at = $h['at']; break; } }
			if ( '' === $at ) { return self::fail( 'hora', 'Esa hora no vale. Elige otra.' ); }
			$sale = self::utc( (int) strtotime( $at ) );
		}
		$sigue = self::ts( $sale ) <= time() + self::SIGUES_MIN * 60 ? self::now() : null; // sale ya: no hace falta «¿Sigues?»
		$wpdb->update( self::t( 'viajes' ), array( 'sale_at' => $sale, 'sigue_at' => $sigue, 'updated_at' => self::now() ), array( 'id' => (int) $v->id ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_salida', array( 'sale' => $sale ) );
		$v2 = CVD_Pv_Api::viaje( (int) $v->id );
		CVD_Pv_Api::tocar( $v2, 'salida' );
		return self::out( array( 'ok' => true, 'viaje' => CVD_Pv_Api::vista_chofer( CVD_Pv_Api::viaje( (int) $v->id ) ) ) );
	}

	/** POST chofer/viajes/{id}/sigo — contesta «Sigo» al «¿Sigues?». */
	public static function sigo( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::mio( $request, $u );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese encargo ya no es tuyo.', 404 ); }
		if ( 'asignado' !== $v->estado || empty( $v->sale_at ) ) { return self::out( array( 'ok' => true, 'viaje' => CVD_Pv_Api::vista_chofer( $v ) ) ); }
		if ( empty( $v->sigue_at ) ) {
			$wpdb->update( self::t( 'viajes' ), array( 'sigue_at' => self::now(), 'updated_at' => self::now() ), array( 'id' => (int) $v->id ) );
			CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_sigo' );
			try { if ( class_exists( 'CVD_Palla_Push' ) ) { CVD_Palla_Push::cerrar( $u->ID, array( 'pv', 'cerrada', 'v' . (int) $v->id ) ); } } catch ( Throwable $e ) { unset( $e ); }
		}
		return self::out( array( 'ok' => true, 'viaje' => CVD_Pv_Api::vista_chofer( CVD_Pv_Api::viaje( (int) $v->id ) ) ) );
	}

	/** POST chofer/viajes/{id}/lleva {confianza_id} — «Lo lleva…» una persona de confianza aprobada (0 = lo llevo yo). */
	public static function lleva( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$v = self::mio( $request, $u );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese encargo ya no es tuyo.', 404 ); }
		if ( ! in_array( $v->estado, array( 'asignado', 'llego' ), true ) ) { return self::fail( 'tarde', 'Ya lo recogiste.', 409 ); }
		$cid = absint( $request->get_param( 'confianza_id' ) );
		if ( $cid ) {
			$c = self::confianza( $cid );
			if ( ! $c || (int) $c->chofer_id !== $u->ID || 'aprobada' !== $c->estado ) { return self::fail( 'confianza', 'Esa persona no está aprobada.' ); }
		}
		$wpdb->update( self::t( 'viajes' ), array( 'lleva_id' => $cid, 'updated_at' => self::now() ), array( 'id' => (int) $v->id ) );
		CVD_Triciclub_Db::evento( (int) $v->id, 'chofer', $u->ID, 'pv_lleva', array( 'confianza' => $cid ) );
		$v2 = CVD_Pv_Api::viaje( (int) $v->id );
		CVD_Pv_Api::tocar( $v2, 'lleva' );
		return self::out( array( 'ok' => true, 'viaje' => CVD_Pv_Api::vista_chofer( $v2 ) ) );
	}

	public static function confianza_lista( WP_REST_Request $request ): WP_REST_Response {
		return self::out( array( 'ok' => true, 'confianza' => self::mis_confianza( self::user( $request )->ID ) ) );
	}

	/** POST chofer/confianza {nombre, telefono, carne4} → pendiente (la aprueba Pa'llá). {quitar: id} la da de baja. */
	public static function confianza_alta( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$u = self::user( $request );
		$tc = self::t( 'confianza' );
		$quitar = absint( $request->get_param( 'quitar' ) );
		if ( $quitar ) {
			$c = self::confianza( $quitar );
			if ( $c && (int) $c->chofer_id === $u->ID ) { self::confianza_estado( $quitar, 'anular', $u->ID ); }
			return self::out( array( 'ok' => true, 'confianza' => self::mis_confianza( $u->ID ) ) );
		}
		$nombre = self::txt( $request->get_param( 'nombre' ), 80 );
		$tel = CVD_Triciclub::telefono( $request->get_param( 'telefono' ) );
		$c4 = preg_replace( '/\D+/', '', (string) $request->get_param( 'carne4' ) );
		if ( mb_strlen( $nombre ) < 2 ) { return self::fail( 'nombre', 'Escribe su nombre.' ); }
		if ( '' === $tel ) { return self::fail( 'telefono', 'Su móvil: 8 números, empieza por 5.' ); }
		if ( 4 !== strlen( $c4 ) ) { return self::fail( 'carne', 'Las 4 últimas cifras de su carné.' ); }
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tc} WHERE chofer_id=%d AND estado IN ('pendiente','aprobada')", $u->ID ) );
		if ( $n >= 3 ) { return self::fail( 'tope', 'Ya tienes 3 personas. Quita una antes.' ); }
		$now = self::now();
		$wpdb->insert( $tc, array( 'chofer_id' => $u->ID, 'nombre' => $nombre, 'telefono' => $tel, 'carne4' => $c4, 'estado' => 'pendiente', 'epoca' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		return self::out( array( 'ok' => true, 'confianza' => self::mis_confianza( $u->ID ) ) );
	}

	// ------------------------------------------------------------------ Persona de confianza (su enlace)

	/** Lo que ve ella: solo sus viajes; sin precio, sin ofertas ni cuenta. Sí lo que hay que cobrar al entregar. */
	private static function vista_ayuda( object $v ): array {
		$p = self::punto( (int) $v->punto_id );
		$c = self::cobro( $v );
		return array(
			'id'        => (int) $v->id,
			'estado'    => (string) $v->estado,
			'etiqueta'  => CVD_Pv_Api::etiqueta( $v ),
			'negocio'   => self::cliente_de( $v ),
			'punto'     => $p ? self::punto_vista( $p ) : null,
			'origen'    => array( 'texto' => (string) $v->origen_texto, 'lat' => (float) $v->origen_lat, 'lng' => (float) $v->origen_lng ),
			'destino'   => array( 'texto' => (string) $v->destino_texto, 'lat' => (float) $v->destino_lat, 'lng' => (float) $v->destino_lng ),
			'envio'     => array( 'que' => (string) $v->envio_que, 'tam' => (string) $v->envio_tam, 'recibe' => (string) $v->recibe_nombre, 'telefono' => (string) $v->recibe_telefono, 'cobrar' => $c['total'], 'foto' => '' !== (string) $v->entrega_foto ),
			'sale_hora' => self::hora( $v->sale_at ),
			'nota'      => (string) $v->nota,
		);
	}

	public static function ayuda_hoy( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$c = self::ayuda( $request );
		$titular = CVD_Pv_Api::chofer_publico( (int) $c->chofer_id );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'viajes' ) . " WHERE lleva_id=%d AND chofer_id=%d AND estado IN ('asignado','llego','recogido') ORDER BY id", (int) $c->id, (int) $c->chofer_id ) );
		return self::out( array( 'ok' => true, 'ayuda' => true, 'nombre' => (string) $c->nombre, 'titular' => $titular ? self::nombre1( (string) $titular['nombre'] ) : '', 'viajes' => array_map( array( __CLASS__, 'vista_ayuda' ), $rows ) ) );
	}

	/** El viaje de ella o null. */
	private static function de_ayuda( WP_REST_Request $request, object $c ): ?object {
		$v = CVD_Pv_Api::viaje( absint( $request['id'] ) );
		return $v && (int) $v->lleva_id === (int) $c->id && (int) $v->chofer_id === (int) $c->chofer_id ? $v : null;
	}

	/** Una petición del titular (su X-Palla-Token) para usar los mismos pasos del motor: código, foto, comisión. */
	private static function como_titular( object $c, object $v, array $body, array $files = array() ): WP_REST_Request {
		$r = new WP_REST_Request( 'POST' );
		$r->set_url_params( array( 'id' => (int) $v->id ) );
		$r->set_body_params( $body );
		if ( $files ) { $r->set_file_params( $files ); }
		$r->set_header( 'X-Palla-Token', CVD_Palla::token_for( (int) $c->chofer_id ) );
		return $r;
	}

	/** POST ayuda/viajes/{id}/estado {accion: recogi|termine|problema, codigo, motivo} */
	public static function ayuda_estado( WP_REST_Request $request ): WP_REST_Response {
		$c = self::ayuda( $request );
		$v = self::de_ayuda( $request, $c );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese viaje ya no es tuyo.', 404 ); }
		$accion = sanitize_key( (string) $request->get_param( 'accion' ) );
		if ( 'problema' === $accion ) {
			$motivo = self::txt( $request->get_param( 'motivo' ), 200 );
			CVD_Triciclub_Db::evento( (int) $v->id, 'ayuda', (int) $c->id, 'pv_problema', array( 'motivo' => $motivo ) );
			CVD_Pv_Api::aviso( (int) $c->chofer_id, 'pv_problema', '⚠️ ' . self::nombre1( (string) $c->nombre ) . ' tiene un problema', '' !== $motivo ? $motivo : 'Llámala.', array( 'pv', 'problema', 'v' . (int) $v->id ) );
			return self::out( array( 'ok' => true, 'viaje' => self::vista_ayuda( $v ) ) );
		}
		if ( ! in_array( $accion, array( 'recogi', 'termine' ), true ) ) { return self::fail( 'accion', 'Botón desconocido.' ); }
		$res = CVD_Pv_Api::estado( self::como_titular( $c, $v, array( 'accion' => $accion, 'codigo' => (string) $request->get_param( 'codigo' ) ) ) );
		$d = $res->get_data();
		if ( empty( $d['ok'] ) ) { return self::fail( (string) ( $d['code'] ?? 'error' ), (string) ( $d['message'] ?? 'No se pudo.' ), $res->get_status() ); }
		CVD_Triciclub_Db::evento( (int) $v->id, 'ayuda', (int) $c->id, 'pv_ayuda_' . $accion );
		return self::out( array( 'ok' => true, 'viaje' => self::vista_ayuda( CVD_Pv_Api::viaje( (int) $v->id ) ) ) );
	}

	/** POST ayuda/viajes/{id}/foto (multipart «foto») — la foto de la entrega. */
	public static function ayuda_foto( WP_REST_Request $request ): WP_REST_Response {
		$c = self::ayuda( $request );
		$v = self::de_ayuda( $request, $c );
		if ( ! $v ) { return self::fail( 'no_existe', 'Ese viaje ya no es tuyo.', 404 ); }
		$res = CVD_Pv_Api::subir_foto( self::como_titular( $c, $v, array(), $request->get_file_params() ) );
		return $res;
	}
}
