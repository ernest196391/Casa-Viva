<?php
/**
 * Bandeja «Dudas del catálogo» del panel de Zayri (v2, 10-nov-2026). Contrato FINAL (base: bandeja-dudas-contrato.md del 9-nov).
 *
 * Almacén: opción no autocargada `cvd_dudas_catalogo` = { seq, out, rev, dudas{ id → tarjeta }, salida[] }. Los archivos
 * (fotos del VPS en base64, fotos y audios de Zayri) van a wp-content/uploads/cvd-dudas/ con nombre aleatorio.
 *
 * Tarjeta: id (D1, D2…), ref (id propio del VPS, para no duplicar), tipo foto|dato|texto|regla|pedir_foto,
 * pieza HERO|LIFESTYLE|BENEFICIO|null, producto{id,nombre,foto}, pregunta, origen, origen_texto, antes/despues/resultado
 * {foto,texto,valor}, dato, regla, campo (texto: name|description|short), escalon{usado,coste_usd,fallo_previo},
 * opciones{si,no,luego,corregir,explicar}, estado pendiente|revision|marcha|resultado|hecha, tarea{id,texto,listo},
 * vueltas, version (cambia con cada cambio; si no coincide → 409), creada, actualizada, historial[{quien,cuando,accion,antes,despues,destino}].
 *
 * Panel (permiso CVD_Content_Studio::allowed):
 *   GET  contenido/dudas?estado=pendiente|marcha|hecha → { dudas[], cuenta{pendiente,marcha,hecha}, activa }
 *        (pendiente = pendiente+revision · marcha = marcha+resultado).
 *   POST contenido/dudas/{id}/respuesta (multipart si lleva foto o audio): respuesta aprobada|revision|rechazada|corregir|explicar,
 *        version, motivos[], valor, texto, foto (archivo), audio (archivo ≤ 3 MB, duracion ≤ 60 s)
 *        → { estado, tarea_id, destino, aviso, duda, cuenta }. 409 si la versión no coincide (devuelve la tarjeta actual en data.duda).
 * VPS (permiso CVD_Bot_Bridge::authorized, cabecera X-VivaBot-Key):
 *   POST bot/dudas                  { dudas:[tarjeta…] } (máx. 20) o una tarjeta. Sin id ni ref conocida → nueva; con id o ref → actualiza.
 *                                   Fotos: antes|despues|resultado.foto_b64. → { ok, dudas:[{id,ref,version,estado}|{error,status}], cuenta }
 *   GET  bot/dudas/respuestas?desde=N&limite=50 → { respuestas:[{seq,…}], cursor, ultimo, cuenta, activa }. El VPS guarda cursor.
 *   POST bot/dudas/{id}/resultado   { tarea_id, foto_b64 | texto | valor, escalon{} } → estado resultado. → { ok, id, version, estado }
 *
 * Efectos de una respuesta (el VPS aplica crm_knowledge, PREFERENCIAS_EDITABLES.md y las tareas del supervisor leyendo la salida):
 *   foto      aprobada → HERO a la web (WebP 1600 blanco) o LIFESTYLE/BENEFICIO a las opciones aprobadas · hecha
 *             rechazada → tarea al supervisor (+ motivos para PREFERENCIAS) · marcha; a la 3.ª vuelta → hecha (descartada)
 *             corregir (foto) → foto real nueva en la ficha + tarea «rehacer con tu foto» · marcha
 *   dato      aprobada/corregir → ficha (_cvd_datos_confirmados) + salida crm_knowledge · hecha; rechazada sin valor → descartada
 *   texto     aprobada/corregir → ficha (campo, con copia «antes» en el historial) · hecha
 *   regla     aprobada/corregir → salida preferencias · hecha; rechazada → descartada
 *   pedir_foto foto nueva → foto real + HERO por escalones (publicación automática D152) · hecha; si no se puede → marcha (VPS)
 *   explicar  (todas) → tarea al supervisor con el texto o el audio · marcha.   revision (todas) → se queda en Por contestar.
 * La bandeja se ve en el panel solo si está activa: opción cvd_content_dudas_activa = '1', o hay tarjetas, o el filtro
 * cvd_content_dudas_activa. El aviso diario por WhatsApp lo manda el VPS (lee «cuenta»); Core no manda mensajes.
 */
defined( 'ABSPATH' ) || exit;

final class CVD_Dudas_Catalogo {
	public const OPCION = 'cvd_dudas_catalogo';
	public const ACTIVA = 'cvd_content_dudas_activa';
	public const TIPOS = array( 'foto', 'dato', 'texto', 'regla', 'pedir_foto' );
	public const ESTADOS = array( 'pendiente', 'revision', 'marcha', 'resultado', 'hecha' );
	public const RESPUESTAS = array( 'aprobada', 'revision', 'rechazada', 'corregir', 'explicar' );
	public const PIEZAS = array( 'HERO' => 'hero', 'LIFESTYLE' => 'life', 'BENEFICIO' => 'ben' );
	public const CAMPOS = array( 'name', 'description', 'short' );
	public const ORIGENES = array( 'noche', 'grupos', 'comprobacion', 'revision_manual', 'panel' );
	public const MAX_VUELTAS = 3;
	public const AUDIO_SEGUNDOS = 60;
	private const AUDIO_MAX = 3145728;
	private const FOTO_MAX = 8388608;
	private const LOCK = 'cvd_dudas_catalogo_lock';
	private const MAX_HECHAS = 200;
	private const MAX_SALIDA = 500;
	private const TABS = array( 'pendiente' => array( 'pendiente', 'revision' ), 'marcha' => array( 'marcha', 'resultado' ), 'hecha' => array( 'hecha' ) );
	private const AUDIOS = array( 'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'm4a', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3' );
	private const FOTOS = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );

	public static function routes(): void {
		$panel = array( 'CVD_Content_Studio', 'allowed' ); $bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( 'casa-viva/v1', '/contenido/dudas', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'lista' ), 'permission_callback' => $panel ) );
		register_rest_route( 'casa-viva/v1', '/contenido/dudas/(?P<duda>[A-Za-z0-9_-]{1,40})/respuesta', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'respuesta' ), 'permission_callback' => $panel ) );
		register_rest_route( 'casa-viva/v1', '/bot/dudas', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_poner' ), 'permission_callback' => $bot ) );
		register_rest_route( 'casa-viva/v1', '/bot/dudas/respuestas', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_respuestas' ), 'permission_callback' => $bot ) );
		register_rest_route( 'casa-viva/v1', '/bot/dudas/(?P<duda>[A-Za-z0-9_-]{1,40})/resultado', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_resultado' ), 'permission_callback' => $bot ) );
	}

	/** Off in production until it is switched on or the VPS sends the first card. */
	public static function activa(): bool {
		$hay = (bool) self::leer()['dudas'];
		return (bool) apply_filters( self::ACTIVA, '1' === (string) get_option( self::ACTIVA, '' ) || $hay );
	}

	/* ---------- Almacén ---------- */

	private static function leer(): array {
		$s = get_option( self::OPCION, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'seq' => (int) ( $s['seq'] ?? 0 ), 'out' => (int) ( $s['out'] ?? 0 ), 'rev' => (int) ( $s['rev'] ?? 0 ),
			'dudas' => is_array( $s['dudas'] ?? null ) ? $s['dudas'] : array(), 'salida' => is_array( $s['salida'] ?? null ) ? $s['salida'] : array() );
	}

	/** Read-modify-write under a short lock. $fn( array &$s ) returns a value, or a WP_Error and then nothing is written. */
	private static function cambiar( callable $fn ) {
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < 30 ) { return new WP_Error( 'ocupado', 'La bandeja se está guardando. Vuelve a intentarlo.', array( 'status' => 409 ) ); }
			delete_option( self::LOCK );
			if ( ! add_option( self::LOCK, time(), '', false ) ) { return new WP_Error( 'ocupado', 'La bandeja se está guardando. Vuelve a intentarlo.', array( 'status' => 409 ) ); }
		}
		try {
			if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPCION, 'options' ); }
			$s = self::leer();
			$out = $fn( $s );
			if ( ! is_wp_error( $out ) ) { self::podar( $s ); update_option( self::OPCION, $s, false ); }
			return $out;
		} finally { delete_option( self::LOCK ); }
	}

	private static function podar( array &$s ): void {
		$hechas = array_filter( $s['dudas'], static fn( $d ) => 'hecha' === ( $d['estado'] ?? '' ) );
		if ( count( $hechas ) > self::MAX_HECHAS ) {
			uasort( $hechas, static fn( $a, $b ) => strcmp( (string) ( $a['actualizada'] ?? '' ), (string) ( $b['actualizada'] ?? '' ) ) );
			foreach ( array_slice( array_keys( $hechas ), 0, count( $hechas ) - self::MAX_HECHAS ) as $id ) { unset( $s['dudas'][ $id ] ); }
		}
		$s['salida'] = array_slice( array_values( $s['salida'] ), -self::MAX_SALIDA );
	}

	private static function sellar( array &$s, array &$d ): void {
		$s['rev']++;
		$d['version'] = gmdate( 'Y-m-d\TH:i:s\Z' ) . '-' . $s['rev'];
		$d['actualizada'] = gmdate( 'c' );
	}

	private static function salida( array &$s, array $e ): int {
		$e['seq'] = ++$s['out'];
		$e['at'] = gmdate( 'c' );
		$s['salida'][] = $e;
		return $e['seq'];
	}

	private static function historial( array &$d, string $quien, string $accion, string $antes = '', string $despues = '', string $destino = '' ): void {
		$d['historial'][] = array( 'quien' => self::txt( $quien, 60 ), 'cuando' => gmdate( 'c' ), 'accion' => $accion,
			'antes' => self::txt( $antes, 300 ), 'despues' => self::txt( $despues, 300 ), 'destino' => self::txt( $destino, 120 ) );
		$d['historial'] = array_slice( $d['historial'], -20 );
	}

	public static function cuenta( ?array $s = null ): array {
		$s = $s ?? self::leer(); $out = array();
		foreach ( self::TABS as $tab => $estados ) { $out[ $tab ] = count( array_filter( $s['dudas'], static fn( $d ) => in_array( $d['estado'] ?? '', $estados, true ) ) ); }
		return $out;
	}

	/* ---------- Archivos ---------- */

	private static function txt( $v, int $max ): string { return mb_substr( sanitize_text_field( (string) $v ), 0, $max ); }

	private static function guardar_bytes( string $bytes, string $ext ) {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) { return new WP_Error( 'save', 'No se pudo guardar el archivo.', array( 'status' => 500 ) ); }
		$sub = '/cvd-dudas'; $dir = $up['basedir'] . $sub;
		if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'save', 'No se pudo guardar el archivo.', array( 'status' => 500 ) ); }
		if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); }
		$name = strtolower( wp_generate_password( 24, false ) ) . '.' . $ext;
		if ( false === file_put_contents( $dir . '/' . $name, $bytes ) ) { return new WP_Error( 'save', 'No se pudo guardar el archivo.', array( 'status' => 500 ) ); }
		return array( 'url' => $up['baseurl'] . $sub . '/' . $name, 'file' => $sub . '/' . $name );
	}

	/** Absolute path of a file kept by the inbox (only inside uploads/cvd-dudas). */
	public static function ruta( string $file ): string {
		if ( 0 !== strpos( $file, '/cvd-dudas/' ) || false !== strpos( $file, '..' ) ) { return ''; }
		$up = wp_upload_dir();
		return empty( $up['error'] ) ? $up['basedir'] . $file : '';
	}

	private static function imagen_b64( string $b64 ) {
		$bytes = base64_decode( preg_replace( '/^data:[^,]*,/', '', $b64 ), true );
		$size = is_string( $bytes ) && '' !== $bytes && strlen( $bytes ) <= self::FOTO_MAX ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || ! isset( self::FOTOS[ $size['mime'] ] ) ) { return new WP_Error( 'foto', 'La foto debe ser JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 422 ) ); }
		return self::guardar_bytes( $bytes, self::FOTOS[ $size['mime'] ] );
	}

	private static function media( $x, array $prev = array() ) {
		$x = is_array( $x ) ? $x : array();
		$out = array( 'foto' => (string) ( $prev['foto'] ?? '' ), 'texto' => (string) ( $prev['texto'] ?? '' ), 'valor' => (string) ( $prev['valor'] ?? '' ), 'archivo' => (string) ( $prev['archivo'] ?? '' ) );
		if ( array_key_exists( 'texto', $x ) ) { $out['texto'] = wp_kses_post( mb_substr( (string) $x['texto'], 0, 20000 ) ); }
		if ( array_key_exists( 'valor', $x ) ) { $out['valor'] = self::txt( $x['valor'], 160 ); }
		if ( ! empty( $x['foto_b64'] ) ) {
			$f = self::imagen_b64( (string) $x['foto_b64'] );
			if ( is_wp_error( $f ) ) { return $f; }
			$out['foto'] = $f['url']; $out['archivo'] = $f['file'];
		} elseif ( array_key_exists( 'foto', $x ) && (string) $x['foto'] !== $out['foto'] ) {
			$out['foto'] = esc_url_raw( (string) $x['foto'] ); $out['archivo'] = '';
		}
		return $out;
	}

	/* ---------- Tarjetas ---------- */

	private static function vacia(): array {
		$m = array( 'foto' => '', 'texto' => '', 'valor' => '', 'archivo' => '' );
		return array( 'id' => '', 'ref' => '', 'tipo' => '', 'pieza' => null, 'producto' => array( 'id' => 0, 'nombre' => '', 'foto' => '' ), 'pregunta' => '',
			'origen' => 'noche', 'origen_texto' => '', 'antes' => $m, 'despues' => $m, 'resultado' => null, 'dato' => '', 'regla' => '', 'campo' => '',
			'escalon' => null, 'opciones' => array( 'si' => true, 'no' => true, 'luego' => true, 'corregir' => 'texto', 'explicar' => true ),
			'estado' => 'pendiente', 'tarea' => null, 'vueltas' => 0, 'opcion' => '', 'version' => '', 'creada' => gmdate( 'c' ), 'actualizada' => '', 'historial' => array() );
	}

	/** Creates or updates one card inside $s. $in: fields of the contract (+ ref, campo, opcion, foto_b64 in the media). */
	private static function poner( array &$s, array $in, string $quien ) {
		$id = (string) ( $in['id'] ?? '' ); $ref = self::txt( $in['ref'] ?? '', 80 ); $prev = null;
		if ( '' !== $id ) {
			$prev = $s['dudas'][ $id ] ?? null;
			if ( ! $prev ) { return new WP_Error( 'duda', 'No existe la duda ' . self::txt( $id, 40 ) . '.', array( 'status' => 404 ) ); }
		} elseif ( '' !== $ref ) {
			foreach ( $s['dudas'] as $d ) { if ( ( $d['ref'] ?? '' ) === $ref ) { $prev = $d; break; } }
		}
		if ( $prev && isset( $in['version'] ) && (string) $in['version'] !== (string) $prev['version'] ) { return new WP_Error( 'conflict', 'La duda cambió. Léela otra vez.', array( 'status' => 409 ) ); }
		// The same night task sent again must not reopen a card Zayri already answered.
		if ( $prev && 'hecha' === $prev['estado'] && ! isset( $in['estado'] ) ) { return $prev; }
		$d = $prev ? array_merge( self::vacia(), $prev ) : array_merge( self::vacia(), array( 'id' => 'D' . ( ++$s['seq'] ), 'ref' => $ref ) );
		if ( isset( $in['tipo'] ) ) { $d['tipo'] = sanitize_key( (string) $in['tipo'] ); }
		if ( ! in_array( $d['tipo'], self::TIPOS, true ) ) { return new WP_Error( 'tipo', 'Tipo de duda no válido.', array( 'status' => 422 ) ); }
		if ( isset( $in['producto'] ) ) {
			$pr = is_array( $in['producto'] ) ? $in['producto'] : array( 'id' => $in['producto'] );
			$pid = absint( $pr['id'] ?? 0 ); $p = $pid ? wc_get_product( $pid ) : null;
			if ( $pid && ( ! $p || $p->is_type( 'variation' ) ) ) { return new WP_Error( 'producto', 'Producto no encontrado.', array( 'status' => 422 ) ); }
			$d['producto'] = $p ? array( 'id' => $pid, 'nombre' => self::txt( $pr['nombre'] ?? '', 160 ) ?: CVD_Content_Studio::clean_title( $p->get_name() ),
				'foto' => esc_url_raw( (string) ( $pr['foto'] ?? '' ) ) ?: (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ) ) : array( 'id' => 0, 'nombre' => '', 'foto' => '' );
		}
		if ( 'regla' !== $d['tipo'] && ! $d['producto']['id'] ) { return new WP_Error( 'producto', 'Esta duda necesita el producto.', array( 'status' => 422 ) ); }
		if ( array_key_exists( 'pieza', $in ) ) { $pz = strtoupper( (string) $in['pieza'] ); $d['pieza'] = isset( self::PIEZAS[ $pz ] ) ? $pz : null; }
		if ( isset( $in['pregunta'] ) ) { $d['pregunta'] = self::txt( $in['pregunta'], 120 ); }
		if ( isset( $in['origen'] ) ) { $d['origen'] = in_array( $in['origen'], self::ORIGENES, true ) ? $in['origen'] : 'noche'; }
		if ( isset( $in['origen_texto'] ) ) { $d['origen_texto'] = self::txt( $in['origen_texto'], 120 ); }
		if ( isset( $in['dato'] ) ) { $d['dato'] = self::txt( $in['dato'], 160 ); }
		if ( isset( $in['regla'] ) ) { $d['regla'] = self::txt( $in['regla'], 300 ); }
		if ( isset( $in['opcion'] ) ) { $d['opcion'] = sanitize_key( (string) $in['opcion'] ); }
		if ( isset( $in['campo'] ) ) { $d['campo'] = in_array( $in['campo'], self::CAMPOS, true ) ? $in['campo'] : ''; }
		if ( 'texto' === $d['tipo'] && '' === $d['campo'] ) { $d['campo'] = 'description'; }
		foreach ( array( 'antes', 'despues', 'resultado' ) as $k ) {
			if ( ! array_key_exists( $k, $in ) ) { continue; }
			$m = self::media( $in[ $k ], (array) ( $d[ $k ] ?? array() ) );
			if ( is_wp_error( $m ) ) { return $m; }
			$d[ $k ] = $m;
		}
		if ( isset( $in['escalon'] ) && is_array( $in['escalon'] ) ) {
			$e = $in['escalon'];
			$d['escalon'] = array( 'usado' => sanitize_key( (string) ( $e['usado'] ?? '' ) ), 'coste_usd' => round( (float) ( $e['coste_usd'] ?? 0 ), 2 ), 'fallo_previo' => self::txt( $e['fallo_previo'] ?? '', 120 ) );
		}
		if ( isset( $in['opciones'] ) && is_array( $in['opciones'] ) ) {
			$o = $in['opciones'];
			$d['opciones'] = array( 'si' => false !== ( $o['si'] ?? true ), 'no' => false !== ( $o['no'] ?? true ), 'luego' => false !== ( $o['luego'] ?? true ),
				'corregir' => in_array( $o['corregir'] ?? '', array( 'texto', 'numero', 'foto' ), true ) ? $o['corregir'] : ( in_array( $d['tipo'], array( 'foto', 'pedir_foto' ), true ) ? 'foto' : 'texto' ),
				'explicar' => false !== ( $o['explicar'] ?? true ) );
		} elseif ( ! $prev && in_array( $d['tipo'], array( 'foto', 'pedir_foto' ), true ) ) { $d['opciones']['corregir'] = 'foto'; }
		if ( isset( $in['tarea'] ) ) {
			$t = (array) $in['tarea'];
			$d['tarea'] = $t ? array( 'id' => self::txt( $t['id'] ?? '', 40 ), 'texto' => self::txt( $t['texto'] ?? '', 160 ), 'listo' => ! empty( $t['listo'] ) ) : null;
		}
		if ( isset( $in['estado'] ) && in_array( $in['estado'], self::ESTADOS, true ) ) { $d['estado'] = $in['estado']; }
		if ( '' === $d['pregunta'] ) { $d['pregunta'] = 'pedir_foto' === $d['tipo'] ? 'Haz otra foto del producto' : '¿Está bien?'; }
		self::historial( $d, $quien, $prev ? 'actualizada' : 'creada' );
		self::sellar( $s, $d );
		$s['dudas'][ $d['id'] ] = $d;
		return $d;
	}

	/** What the phone and the VPS see: no internal file paths. */
	public static function publica( array $d ): array {
		$d = array_merge( self::vacia(), $d );
		foreach ( array( 'antes', 'despues', 'resultado' ) as $k ) { if ( is_array( $d[ $k ] ) ) { unset( $d[ $k ]['archivo'] ); } }
		return array_intersect_key( $d, array_flip( array( 'id', 'ref', 'tipo', 'pieza', 'producto', 'pregunta', 'origen', 'origen_texto', 'antes', 'despues', 'resultado', 'dato', 'regla',
			'campo', 'escalon', 'opciones', 'estado', 'tarea', 'vueltas', 'version', 'creada', 'actualizada', 'historial' ) ) );
	}

	/* ---------- Lo que crea el panel ---------- */

	/** «Revisión» of a piece in the panel: a photo card with before → after. */
	public static function desde_pieza( WC_Product $p, string $pieza, array $o, string $quien ) {
		$nombres = array_flip( self::PIEZAS ); $coste = (float) ( $o['coste'] ?? 0 );
		$card = array( 'tipo' => 'foto', 'pieza' => $nombres[ $pieza ] ?? null, 'producto' => array( 'id' => $p->get_id() ), 'pregunta' => '¿Esta foto está bien?',
			'origen' => 'revision_manual', 'origen_texto' => 'Panel · ' . ( 'ia' === ( $o['escalon'] ?? '' ) ? 'IA de pago · ' . number_format( $coste, 2, ',', '' ) . ' USD' : 'Recorte gratis · 0 USD' ),
			'antes' => array( 'foto' => (string) ( $o['antes'] ?? '' ) ?: (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ) ), 'despues' => array( 'foto' => (string) ( $o['url'] ?? '' ) ),
			'opcion' => (string) ( $o['id'] ?? '' ), 'escalon' => array( 'usado' => (string) ( $o['escalon'] ?? '' ), 'coste_usd' => $coste, 'fallo_previo' => (string) ( $o['fallo'] ?? '' ) ) );
		return self::cambiar( static fn( array &$s ) => self::poner( $s, $card, $quien ) );
	}

	/** The real photo does not work: ask for a new one, without spending anything. */
	public static function pedir_foto( WC_Product $p, string $motivo, string $quien ) {
		$card = array( 'tipo' => 'pedir_foto', 'pieza' => 'HERO', 'producto' => array( 'id' => $p->get_id() ), 'origen' => 'panel',
			'pregunta' => 'Haz otra foto del producto', 'origen_texto' => $motivo ?: 'La foto real no sirve para el HERO',
			'antes' => array( 'foto' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'large' ) ) );
		return self::cambiar( static fn( array &$s ) => self::poner( $s, $card, $quien ) );
	}

	/** A note for the VPS that is not a card (e.g. the reasons of a rejected piece, for PREFERENCIAS). */
	public static function anotar( array $e ) {
		return self::cambiar( static fn( array &$s ) => self::salida( $s, $e ) );
	}

	/* ---------- Panel ---------- */

	public static function lista( WP_REST_Request $r ) {
		$tab = (string) $r->get_param( 'estado' );
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'pendiente';
		$s = self::leer();
		$list = array_filter( $s['dudas'], static fn( $d ) => in_array( $d['estado'] ?? '', self::TABS[ $tab ], true ) );
		uasort( $list, 'hecha' === $tab ? static fn( $a, $b ) => strcmp( (string) $b['actualizada'], (string) $a['actualizada'] ) : static fn( $a, $b ) => strcmp( (string) $a['creada'], (string) $b['creada'] ) ?: strnatcmp( $a['id'], $b['id'] ) );
		$response = rest_ensure_response( array( 'dudas' => array_values( array_map( array( __CLASS__, 'publica' ), array_slice( $list, 0, 50 ) ) ), 'cuenta' => self::cuenta( $s ), 'activa' => self::activa() ) );
		if ( is_object( $response ) && method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'private, no-store' ); }
		return $response;
	}

	public static function respuesta( WP_REST_Request $r ) {
		$id = (string) $r->get_param( 'duda' );
		$resp = sanitize_key( (string) $r->get_param( 'respuesta' ) );
		if ( ! in_array( $resp, self::RESPUESTAS, true ) ) { return new WP_Error( 'respuesta', 'Elige una respuesta.', array( 'status' => 422 ) ); }
		$in = array( 'respuesta' => $resp, 'motivos' => array_values( array_intersect( CVD_Content_Studio::MOTIVOS, array_map( 'strval', (array) $r->get_param( 'motivos' ) ) ) ),
			'valor' => mb_substr( (string) $r->get_param( 'valor' ), 0, 20000 ), 'texto' => mb_substr( sanitize_textarea_field( (string) $r->get_param( 'texto' ) ), 0, 1000 ),
			'version' => (string) $r->get_param( 'version' ), 'quien' => (string) wp_get_current_user()->user_login );
		$files = $r->get_file_params();
		foreach ( array( 'foto', 'audio' ) as $name ) {
			$file = $files[ $name ] ?? array();
			if ( empty( $file['tmp_name'] ) ) { continue; }
			$size = (int) @filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( 'foto' === $name ) {
				$type = (string) wp_get_image_mime( $file['tmp_name'] );
				if ( ! empty( $file['error'] ) || $size > self::FOTO_MAX || ! isset( self::FOTOS[ $type ] ) ) { return new WP_Error( 'foto', 'Elige una foto JPG, PNG o WebP de hasta 8 MB.', array( 'status' => 422 ) ); }
			} else {
				$type = strtolower( (string) preg_replace( '/;.*$/', '', (string) ( $file['type'] ?? '' ) ) );
				$seg = (float) $r->get_param( 'duracion' );
				if ( ! empty( $file['error'] ) || $size > self::AUDIO_MAX || ! isset( self::AUDIOS[ $type ] ) || $seg > self::AUDIO_SEGUNDOS ) { return new WP_Error( 'audio', 'La nota de voz debe durar hasta 60 segundos.', array( 'status' => 422 ) ); }
			}
			$in[ $name ] = array( 'tmp_name' => $file['tmp_name'], 'type' => $type, 'size' => $size );
		}
		$d = self::leer()['dudas'][ $id ] ?? null;
		if ( ! $d ) { return new WP_Error( 'duda', 'Esa duda ya no está. Recarga la bandeja.', array( 'status' => 404 ) ); }
		$d = array_merge( self::vacia(), $d );
		if ( ! hash_equals( (string) $d['version'], $in['version'] ) ) { return new WP_Error( 'conflict', 'Otra persona ya cambió esta duda. Mírala otra vez.', array( 'status' => 409, 'duda' => self::publica( $d ) ) ); }
		if ( 'hecha' === $d['estado'] ) { return new WP_Error( 'conflict', 'Esta duda ya está contestada.', array( 'status' => 409, 'duda' => self::publica( $d ) ) ); }
		if ( 'marcha' === $d['estado'] ) { return new WP_Error( 'conflict', 'Está en marcha. Espera el resultado.', array( 'status' => 409, 'duda' => self::publica( $d ) ) ); }
		if ( 'explicar' === $resp && '' === trim( $in['texto'] ) && empty( $in['audio'] ) ) { return new WP_Error( 'respuesta', 'Graba una nota de voz o escribe la explicación.', array( 'status' => 422 ) ); }
		// One answer per card at a time (a «pedir foto» may wait for the image ladder; a lock older than 400 s is a crashed request).
		$lock = 'cvd_duda_' . md5( $id );
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 400 ) { return new WP_Error( 'busy', 'Esta duda se está guardando. Espera un momento.', array( 'status' => 409 ) ); }
			delete_option( $lock );
			if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', 'Esta duda se está guardando. Espera un momento.', array( 'status' => 409 ) ); }
		}
		try {
			$e = self::efecto( $d, $in );
			if ( is_wp_error( $e ) ) { return $e; }
			$card = self::cambiar( static function ( array &$s ) use ( $id, $in, $e ) {
				if ( ! isset( $s['dudas'][ $id ] ) ) { return new WP_Error( 'duda', 'Esa duda ya no está.', array( 'status' => 404 ) ); }
				$d = array_merge( self::vacia(), $s['dudas'][ $id ] );
				$d['estado'] = $e['estado'];
				if ( $e['vueltas'] >= 0 ) { $d['vueltas'] = $e['vueltas']; } // -1 = sin cambio
				if ( array_key_exists( 'tarea', $e ) ) { $d['tarea'] = $e['tarea']; }
				if ( 'marcha' === $e['estado'] ) { $d['resultado'] = null; }
				if ( $e['salida'] ) {
					self::salida( $s, array_merge( array( 'duda' => $d['id'], 'ref' => $d['ref'], 'tipo' => $d['tipo'], 'accion' => $in['respuesta'], 'producto' => (int) $d['producto']['id'],
						'pieza' => $d['pieza'], 'motivos' => $in['motivos'], 'dato' => $d['dato'], 'regla' => $d['regla'], 'campo' => $d['campo'], 'quien' => $in['quien'],
						'tarea_id' => $d['tarea']['id'] ?? null ), $e['salida'] ) );
				}
				self::historial( $d, $in['quien'], $in['respuesta'], $e['antes'], $e['despues'], $e['destino'] );
				self::sellar( $s, $d );
				$s['dudas'][ $id ] = $d;
				return $d;
			} );
		} finally { delete_option( $lock ); }
		if ( is_wp_error( $card ) ) { return $card; }
		return rest_ensure_response( array( 'estado' => $card['estado'], 'tarea_id' => 'marcha' === $card['estado'] ? ( $card['tarea']['id'] ?? null ) : null,
			'destino' => $e['destino'], 'aviso' => $e['aviso'], 'duda' => self::publica( $card ), 'cuenta' => self::cuenta() ) );
	}

	private static function hecha( string $destino, string $aviso, ?array $salida = null, array $extra = array() ): array {
		return array_merge( array( 'estado' => 'hecha', 'destino' => $destino, 'aviso' => $aviso, 'salida' => $salida, 'vueltas' => -1, 'antes' => '', 'despues' => '' ), $extra );
	}

	private static function marcha( array $d, string $texto, array $salida, array $extra = array() ): array {
		$tarea = array( 'id' => 'sup-' . strtolower( wp_generate_password( 8, false ) ), 'texto' => self::txt( $texto, 160 ), 'listo' => false );
		return array_merge( array( 'estado' => 'marcha', 'destino' => 'supervisor', 'aviso' => 'En marcha · ' . $tarea['texto'], 'tarea' => $tarea, 'vueltas' => (int) $d['vueltas'],
			'salida' => array_merge( array( 'destino' => 'supervisor', 'tarea_id' => $tarea['id'], 'tarea' => $tarea['texto'] ), $salida ), 'antes' => '', 'despues' => '' ), $extra );
	}

	private static function guardar_subida( array $f, string $ext ) {
		$bytes = (string) file_get_contents( $f['tmp_name'] );
		return '' === $bytes ? new WP_Error( 'save', 'El archivo llegó vacío.', array( 'status' => 422 ) ) : self::guardar_bytes( $bytes, $ext );
	}

	/** What an answer does, before the card is updated. Returns estado, destino, aviso, tarea, salida (for the VPS) and the history line. */
	private static function efecto( array $d, array $in ) {
		$resp = $in['respuesta']; $tipo = $d['tipo']; $res = 'resultado' === $d['estado'];
		$p = $d['producto']['id'] ? wc_get_product( (int) $d['producto']['id'] ) : null;
		$p = $p instanceof WC_Product ? $p : null;
		if ( 'revision' === $resp ) { return array( 'estado' => 'revision', 'destino' => '', 'aviso' => 'Queda en Por contestar.', 'salida' => null, 'vueltas' => -1, 'antes' => '', 'despues' => '' ); }
		if ( 'explicar' === $resp ) {
			$audio = '';
			if ( ! empty( $in['audio'] ) ) {
				$f = self::guardar_subida( $in['audio'], self::AUDIOS[ $in['audio']['type'] ] );
				if ( is_wp_error( $f ) ) { return $f; }
				$audio = $f['url'];
			}
			return self::marcha( $d, 'Leer la explicación de Zayri', array( 'texto' => $in['texto'], 'audio' => $audio ), array( 'despues' => $in['texto'] ?: 'nota de voz' ) );
		}
		// A task result rejected: another round, at most 3.
		if ( $res && 'rechazada' === $resp ) { return self::otra_vuelta( $d, $in, $p ); }
		$pieza = self::PIEZAS[ (string) $d['pieza'] ] ?? '';
		switch ( $tipo ) {
			case 'foto':
				if ( 'aprobada' === $resp ) { return self::foto_aprobada( $d, $p, $pieza, $res ); }
				if ( 'rechazada' === $resp ) {
					if ( $p && $pieza && $d['opcion'] ) { CVD_Content_Studio::rechazar_opcion( $p, $pieza, $d['opcion'], $in['motivos'] ); }
					return self::otra_vuelta( $d, $in, $p );
				}
				if ( empty( $in['foto'] ) || ! $p ) { return new WP_Error( 'respuesta', 'Sube otra foto del producto.', array( 'status' => 422 ) ); }
				$att = CVD_Content_Studio::foto_real( $p, $in['foto'] );
				if ( is_wp_error( $att ) ) { return $att; }
				return self::marcha( $d, 'Rehacer con la foto de Zayri (empieza por el recorte gratis)', array( 'adjunto' => $att, 'foto' => (string) wp_get_attachment_image_url( $att, 'full' ) ), array( 'despues' => 'foto nueva' ) );
			case 'pedir_foto':
				if ( 'rechazada' === $resp ) { return self::hecha( 'descartada', 'Sin foto nueva: el producto se queda como está.', array( 'destino' => 'descartada' ) ); }
				if ( empty( $in['foto'] ) || ! $p ) { return new WP_Error( 'respuesta', 'Haz o elige la foto nueva.', array( 'status' => 422 ) ); }
				$att = CVD_Content_Studio::foto_real( $p, $in['foto'] );
				if ( is_wp_error( $att ) ) { return $att; }
				$url = (string) wp_get_attachment_image_url( $att, 'full' );
				$hecho = CVD_Content_Studio::hero_desde_adjunto( wc_get_product( $p->get_id() ) ?: $p, $att );
				if ( is_wp_error( $hecho ) ) {
					return self::marcha( $d, 'Hacer el HERO con la foto nueva', array( 'adjunto' => $att, 'foto' => $url, 'fallo' => $hecho->get_error_message() ), array( 'despues' => 'foto nueva' ) );
				}
				return self::hecha( $hecho['publicada'] ? 'web' : 'opciones', $hecho['publicada'] ? 'Hecho · HERO nuevo en la web' : 'Hecho · HERO listo para revisar en Fotos',
					array( 'destino' => $hecho['publicada'] ? 'web' : 'opciones', 'adjunto' => $att, 'foto' => $url, 'escalon' => $hecho['escalon'], 'coste_usd' => $hecho['coste'] ), array( 'despues' => 'foto nueva' ) );
			case 'dato':
				$valor = 'aprobada' === $resp ? ( $res ? (string) ( $d['resultado']['valor'] ?? '' ) : ( $d['despues']['valor'] ?: $d['antes']['valor'] ) ) : self::txt( $in['valor'], 160 );
				if ( 'rechazada' === $resp && '' === $valor ) { return self::hecha( 'descartada', 'Hecho · el dato no se usa.', array( 'destino' => 'descartada' ) ); }
				if ( '' === $valor ) { return new WP_Error( 'respuesta', 'Escribe el dato bueno.', array( 'status' => 422 ) ); }
				if ( class_exists( 'CVD_Fichas' ) && CVD_Fichas::frase_prohibida( $valor ) ) { return new WP_Error( 'respuesta', 'Revisa el dato: contiene una nota interna.', array( 'status' => 422 ) ); }
				if ( $p ) { CVD_Content_Studio::dato_confirmado( $p, $d['dato'] ?: $d['pregunta'], $valor, $in['quien'] ); }
				return self::hecha( 'ficha · crm_knowledge', 'Hecho · Ficha y crm_knowledge', array( 'destino' => 'crm_knowledge', 'aplicado' => array( 'ficha' ), 'valor' => $valor ),
					array( 'antes' => (string) $d['antes']['valor'], 'despues' => $valor ) );
			case 'texto':
				$texto = 'aprobada' === $resp ? ( $res ? (string) ( $d['resultado']['texto'] ?? '' ) : (string) $d['despues']['texto'] ) : trim( $in['valor'] );
				if ( 'rechazada' === $resp && '' === $texto ) { return self::hecha( 'descartada', 'Hecho · el texto no cambia.', array( 'destino' => 'descartada' ) ); }
				if ( '' === trim( wp_strip_all_tags( $texto ) ) || ! $p ) { return new WP_Error( 'respuesta', 'Escribe el texto bueno.', array( 'status' => 422 ) ); }
				$antes = CVD_Content_Studio::texto_ficha( $p, $d['campo'] ?: 'description', $texto );
				if ( is_wp_error( $antes ) ) { return $antes; }
				return self::hecha( 'ficha', 'Hecho · Ficha publicada', array( 'destino' => 'ficha', 'aplicado' => array( 'ficha' ), 'texto' => wp_strip_all_tags( $texto ) ),
					array( 'antes' => wp_strip_all_tags( $antes ), 'despues' => wp_strip_all_tags( $texto ) ) );
			case 'regla':
			default:
				if ( 'rechazada' === $resp ) { return self::hecha( 'descartada', 'Hecho · la regla no se usa.', array( 'destino' => 'descartada' ) ); }
				$regla = 'aprobada' === $resp ? ( $res ? ( (string) ( $d['resultado']['texto'] ?? '' ) ?: $d['regla'] ) : $d['regla'] ) : self::txt( $in['valor'], 300 );
				if ( '' === trim( $regla ) ) { return new WP_Error( 'respuesta', 'Escribe la regla buena.', array( 'status' => 422 ) ); }
				return self::hecha( 'preferencias', 'Hecho · PREFERENCIAS_EDITABLES.md', array( 'destino' => 'preferencias', 'regla' => $regla ), array( 'antes' => $d['regla'], 'despues' => $regla ) );
		}
	}

	private static function otra_vuelta( array $d, array $in, ?WC_Product $p ): array {
		$v = (int) $d['vueltas'] + 1;
		$motivos = $in['motivos'] ? ': ' . implode( ', ', $in['motivos'] ) : '';
		if ( $v >= self::MAX_VUELTAS ) {
			return self::hecha( 'descartada', 'Tercera vuelta sin acierto: queda descartada.', array( 'destino' => 'preferencias', 'descartada' => true ), array( 'vueltas' => $v, 'despues' => 'rechazada' . $motivos ) );
		}
		return self::marcha( $d, 'Rehacer con el siguiente escalón' . $motivos, array( 'preferencias' => (bool) $in['motivos'] ), array( 'vueltas' => $v, 'despues' => 'rechazada' . $motivos ) );
	}

	private static function foto_aprobada( array $d, ?WC_Product $p, string $pieza, bool $res ) {
		$m = $res ? (array) $d['resultado'] : (array) $d['despues'];
		if ( $p && $pieza ) {
			$opcion = $res ? '' : (string) $d['opcion'];
			$ruta = self::ruta( (string) ( $m['archivo'] ?? '' ) );
			if ( '' === $opcion && '' !== $ruta ) {
				$o = CVD_Content_Studio::opcion_desde_archivo( $p, $pieza, $ruta, (string) ( $d['escalon']['usado'] ?? '' ), (float) ( $d['escalon']['coste_usd'] ?? 0 ) );
				if ( is_wp_error( $o ) ) { return $o; }
				$opcion = $o['id'];
			}
			if ( '' !== $opcion ) {
				$ok = CVD_Content_Studio::aprobar_opcion( $p, $pieza, $opcion, 'hero' === $pieza );
				if ( is_wp_error( $ok ) ) { return $ok; }
				$web = 'hero' === $pieza;
				return self::hecha( $web ? 'web' : 'anuncios', $web ? 'Hecho · HERO en la web' : 'Hecho · lista para anuncios', array( 'destino' => $web ? 'web' : 'anuncios', 'aplicado' => array( $web ? 'web' : 'anuncios' ), 'adjunto' => (int) $ok['web'], 'foto' => (string) ( $m['foto'] ?? '' ) ), array( 'despues' => 'aprobada' ) );
			}
		}
		// No local copy of the photo: the VPS publishes it.
		return self::hecha( 'web', 'Hecho · la publica el supervisor', array( 'destino' => 'web', 'foto' => (string) ( $m['foto'] ?? '' ) ), array( 'despues' => 'aprobada' ) );
	}

	/* ---------- VPS ---------- */

	public static function bot_poner( WP_REST_Request $r ) {
		$body = $r->get_json_params();
		$body = is_array( $body ) && $body ? $body : (array) $r->get_body_params();
		$items = isset( $body['dudas'] ) && is_array( $body['dudas'] ) ? array_values( $body['dudas'] ) : array( $body );
		if ( ! $items || count( $items ) > 20 ) { return new WP_Error( 'dudas', 'Manda entre 1 y 20 dudas.', array( 'status' => 422 ) ); }
		$res = self::cambiar( static function ( array &$s ) use ( $items ) {
			$out = array();
			foreach ( $items as $in ) {
				$d = is_array( $in ) ? self::poner( $s, $in, 'VPS' ) : new WP_Error( 'duda', 'Duda no válida.', array( 'status' => 422 ) );
				$out[] = is_wp_error( $d ) ? array( 'error' => $d->get_error_message(), 'status' => (int) ( $d->get_error_data()['status'] ?? 422 ), 'id' => is_array( $in ) ? (string) ( $in['id'] ?? '' ) : '', 'ref' => is_array( $in ) ? (string) ( $in['ref'] ?? '' ) : '' )
					: array( 'id' => $d['id'], 'ref' => $d['ref'], 'version' => $d['version'], 'estado' => $d['estado'] );
			}
			return $out;
		} );
		if ( is_wp_error( $res ) ) { return $res; }
		if ( 1 === count( $res ) && isset( $res[0]['error'] ) ) { return new WP_Error( 'dudas', $res[0]['error'], array( 'status' => $res[0]['status'] ) ); }
		return rest_ensure_response( array( 'ok' => true, 'dudas' => $res, 'cuenta' => self::cuenta() ) );
	}

	public static function bot_respuestas( WP_REST_Request $r ) {
		$desde = absint( $r->get_param( 'desde' ) ); $limite = min( 100, max( 1, absint( $r->get_param( 'limite' ) ) ?: 50 ) );
		$s = self::leer();
		$out = array_slice( array_values( array_filter( $s['salida'], static fn( $e ) => (int) ( $e['seq'] ?? 0 ) > $desde ) ), 0, $limite );
		$cursor = $out ? (int) end( $out )['seq'] : $desde;
		return rest_ensure_response( array( 'respuestas' => $out, 'cursor' => $cursor, 'ultimo' => (int) $s['out'], 'cuenta' => self::cuenta( $s ), 'activa' => self::activa() ) );
	}

	public static function bot_resultado( WP_REST_Request $r ) {
		$id = (string) $r->get_param( 'duda' );
		$body = $r->get_json_params();
		$body = is_array( $body ) && $body ? $body : (array) $r->get_body_params();
		$m = array( 'texto' => $body['texto'] ?? '', 'valor' => $body['valor'] ?? '' );
		if ( ! empty( $body['foto_b64'] ) ) { $m['foto_b64'] = (string) $body['foto_b64']; } elseif ( isset( $body['foto'] ) ) { $m['foto'] = (string) $body['foto']; }
		$media = self::media( $m );
		if ( is_wp_error( $media ) ) { return $media; }
		$res = self::cambiar( static function ( array &$s ) use ( $id, $body, $media ) {
			if ( ! isset( $s['dudas'][ $id ] ) ) { return new WP_Error( 'duda', 'No existe la duda.', array( 'status' => 404 ) ); }
			$d = array_merge( self::vacia(), $s['dudas'][ $id ] );
			if ( ! in_array( $d['estado'], array( 'marcha', 'resultado' ), true ) ) { return new WP_Error( 'conflict', 'La duda no está en marcha.', array( 'status' => 409 ) ); }
			$tarea = (string) ( $body['tarea_id'] ?? '' );
			if ( '' !== $tarea && $d['tarea'] && $tarea !== $d['tarea']['id'] ) { return new WP_Error( 'conflict', 'Esa tarea ya no es la de la duda.', array( 'status' => 409 ) ); }
			$d['resultado'] = $media; $d['estado'] = 'resultado';
			if ( $d['tarea'] ) { $d['tarea']['listo'] = true; }
			if ( isset( $body['escalon'] ) && is_array( $body['escalon'] ) ) {
				$e = $body['escalon'];
				$d['escalon'] = array( 'usado' => sanitize_key( (string) ( $e['usado'] ?? '' ) ), 'coste_usd' => round( (float) ( $e['coste_usd'] ?? 0 ), 2 ), 'fallo_previo' => self::txt( $e['fallo_previo'] ?? '', 120 ) );
			}
			self::historial( $d, 'Supervisor', 'resultado', '', $media['texto'] ? wp_strip_all_tags( $media['texto'] ) : ( $media['valor'] ?: 'foto nueva' ) );
			self::sellar( $s, $d );
			$s['dudas'][ $id ] = $d;
			return $d;
		} );
		if ( is_wp_error( $res ) ) { return $res; }
		return rest_ensure_response( array( 'ok' => true, 'id' => $res['id'], 'version' => $res['version'], 'estado' => $res['estado'] ) );
	}
}
