<?php

defined( 'ABSPATH' ) || exit;

/**
 * NEXO Busca (3.14.28, Ernesto 16-oct): lo que pasa DESPUÉS de buscar con CVD_Gestora_Busca.
 *
 * 1. Cada búsqueda se guarda (tabla cvd_busca_busquedas: foto, texto, zona, ofertas y la que eligió la gestora) para
 *    poder comparar proveedores más adelante. La foto queda en uploads/cvd-busca/AAAAMM/ con nombre al azar.
 * 2. «Vender por Casa Viva (bajo pedido)»: la gestora deja una SOLICITUD (tabla cvd_busca_pedidos) con la foto, el
 *    anuncio (enlace, teléfono, precio del proveedor), su precio, la cantidad, el cliente (opcional) y una nota.
 *    Estados: pendiente → confirmada | no_hay. Nada se confirma solo: regla del proveedor (memoria
 *    «supplier-orders-double-check»): primero Casa Viva lo comprueba con el proveedor y después se lo confirma a ella.
 * 3. Aviso a Lennys por WhatsApp por el MISMO camino que los pagos (CVD_Payout_Avisos): Core deja el aviso con el
 *    texto ya escrito en cvd_busca_avisos; VivaBot (src/avisos-busca.js) lo recoge con GET /bot/busca-avisos, lo mete
 *    en el buzón y confirma con POST /bot/busca-avisos/{id}/done. Interruptor en el bot: crm_settings «avisos_busca».
 * 4. Lennys cambia el estado desde un enlace firmado (?cvd_busca=ID&t=…, 7 días, ata solicitud + teléfono), como
 *    «Pagar a…». Al confirmar pone el precio final y la COMISIÓN de la gestora: la comisión de lo vendido así la
 *    decide Lennys en ese momento (no hay regla automática). Al decidir, se avisa a la gestora (mismo camino).
 *
 * Quién recibe el aviso: opción cvd_busca_admin_phones; si no existe, la de pagos (cvd_payout_admin_phones = Lennys).
 */
final class CVD_Nexo_Busca {
	private const NS = 'casa-viva/v1';
	private const DB_VERSION = '1';
	private const TTL = 7 * DAY_IN_SECONDS;
	public const STATUSES = array( 'pendiente', 'confirmada', 'no_hay' );

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route_page' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 46 );
	}

	public static function assets(): void {
		if ( ! is_page( 'area-gestoras' ) || ! is_user_logged_in() || ! class_exists( 'CVD_Gestora_App' ) || ! CVD_Gestora_App::enabled_for( wp_get_current_user() ) ) { return; }
		wp_enqueue_style( 'cvd-gestora-busca', CVD_URL . 'assets/gestora-busca.css', array( 'cvd-gestora-app' ), CVD_VERSION );
		wp_enqueue_script( 'cvd-gestora-busca', CVD_URL . 'assets/gestora-busca.js', array( 'cvd-gestora-app' ), CVD_VERSION, true );
	}

	/* ------------------------------------------------------------------ tablas */

	private static function t( string $name ): string { global $wpdb; return $wpdb->prefix . 'cvd_busca_' . $name; }

	public static function maybe_install(): void {
		if ( get_option( 'cvd_busca_db' ) === self::DB_VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'busquedas' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			photo varchar(255) NOT NULL DEFAULT '',
			context text NULL,
			zone varchar(120) NOT NULL DEFAULT '',
			product varchar(190) NOT NULL DEFAULT '',
			summary text NULL,
			results longtext NULL,
			chosen smallint(6) NULL,
			chosen_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'pedidos' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			search_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pendiente',
			product varchar(190) NOT NULL DEFAULT '',
			photo varchar(255) NOT NULL DEFAULT '',
			offer longtext NULL,
			supplier_usd decimal(12,2) NULL,
			price_usd decimal(12,2) NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			client varchar(190) NOT NULL DEFAULT '',
			note text NULL,
			test tinyint(1) NOT NULL DEFAULT 0,
			final_usd decimal(12,2) NULL,
			commission_usd decimal(12,2) NULL,
			admin_note text NULL,
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at),
			KEY status (status)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'avisos' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pedido_id bigint(20) unsigned NOT NULL,
			kind varchar(32) NOT NULL,
			role varchar(24) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			body text NOT NULL,
			dedupe char(64) NOT NULL,
			test tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			notified_at datetime NULL,
			result varchar(24) NOT NULL DEFAULT '',
			note varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (dedupe),
			KEY pending (notified_at, created_at),
			KEY pedido_id (pedido_id)
		) {$c};" );
		update_option( 'cvd_busca_db', self::DB_VERSION, true );
	}

	/* ------------------------------------------------------------------ búsquedas */

	/** Guarda la foto de una búsqueda (JPEG ya reducido) y devuelve la ruta relativa a uploads ('' si no se pudo). */
	public static function store_photo( string $jpeg ): string {
		if ( '' === $jpeg ) { return ''; }
		$up = wp_upload_dir();
		$rel = 'cvd-busca/' . gmdate( 'Ym' );
		$dir = trailingslashit( $up['basedir'] ) . $rel;
		if ( ! wp_mkdir_p( $dir ) ) { return ''; }
		if ( ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); } // phpcs:ignore
		$name = strtolower( wp_generate_password( 20, false, false ) ) . '.jpg';
		return false === file_put_contents( $dir . '/' . $name, $jpeg ) ? '' : $rel . '/' . $name; // phpcs:ignore
	}

	public static function photo_url( string $rel ): string {
		return '' === $rel ? '' : trailingslashit( wp_upload_dir()['baseurl'] ) . ltrim( $rel, '/' );
	}

	public static function save_search( int $user_id, string $photo, string $context, string $zone, array $parsed ): int {
		global $wpdb;
		$wpdb->insert( self::t( 'busquedas' ), array(
			'user_id' => $user_id, 'created_at' => current_time( 'mysql', true ), 'photo' => $photo, 'context' => $context, 'zone' => $zone,
			'product' => mb_substr( (string) ( $parsed['product'] ?? '' ), 0, 190 ), 'summary' => (string) ( $parsed['summary'] ?? '' ),
			'results' => wp_json_encode( $parsed['results'] ?? array() ),
		) );
		return (int) $wpdb->insert_id;
	}

	private static function search_of( int $id, int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'busquedas' ) . ' WHERE id=%d AND user_id=%d', $id, $user_id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['results'] = json_decode( (string) $row['results'], true ) ?: array();
		return $row;
	}

	/* ------------------------------------------------------------------ rutas */

	public static function routes(): void {
		$gestora = array( 'CVD_Gestora_Busca', 'can_use' );
		register_rest_route( self::NS, '/gestora/busca/elegir', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_choose' ), 'permission_callback' => $gestora ) );
		register_rest_route( self::NS, '/gestora/busca/pedido', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_request' ), 'permission_callback' => $gestora ) );
		register_rest_route( self::NS, '/gestora/busca/pedidos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $gestora ) );
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/busca-avisos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_pending' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/busca-avisos/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_done' ), 'permission_callback' => $bot ) );
	}

	/** «Usar esta»: apunta qué oferta eligió (para comparar proveedores). */
	public static function rest_choose( WP_REST_Request $request ) {
		global $wpdb;
		$search = self::search_of( absint( $request->get_param( 'id' ) ), get_current_user_id() );
		$index = (int) $request->get_param( 'index' );
		if ( ! $search || ! isset( $search['results'][ $index ] ) ) { return new WP_Error( 'cvd_busca_missing', 'Esa búsqueda ya no está.', array( 'status' => 404 ) ); }
		$wpdb->update( self::t( 'busquedas' ), array( 'chosen' => $index, 'chosen_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $search['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Número en USD con coma o punto ("12", "12,50"). null si no hay. */
	private static function usd( $value ): ?float {
		$s = preg_replace( '/[^\d.,]/', '', (string) $value );
		if ( '' === $s ) { return null; }
		$dot = strrpos( $s, '.' ); $comma = strrpos( $s, ',' );
		if ( false !== $dot && false !== $comma ) { $s = $dot > $comma ? str_replace( ',', '', $s ) : str_replace( ',', '.', str_replace( '.', '', $s ) ); }
		elseif ( false !== $comma ) { $s = str_replace( ',', '.', $s ); }
		$n = (float) $s;
		return $n > 0 && $n < 100000 ? round( $n, 2 ) : null;
	}

	/** «Vender por Casa Viva (bajo pedido)»: crea la solicitud y deja el aviso para Lennys. */
	public static function rest_request( WP_REST_Request $request ) {
		global $wpdb;
		$user = wp_get_current_user();
		$search = self::search_of( absint( $request->get_param( 'id' ) ), $user->ID );
		$index = (int) $request->get_param( 'index' );
		if ( ! $search || ! isset( $search['results'][ $index ] ) ) { return new WP_Error( 'cvd_busca_missing', 'Esa búsqueda ya no está. Busca otra vez.', array( 'status' => 404 ) ); }
		$price = self::usd( $request->get_param( 'price_usd' ) );
		if ( null === $price ) { return new WP_Error( 'cvd_busca_price', 'Pon el precio que le quieres cobrar al cliente (en USD).', array( 'status' => 400 ) ); }
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'pedidos' ) . ' WHERE user_id=%d AND created_at>=%s', $user->ID, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		if ( $recent >= 10 ) { return new WP_Error( 'cvd_busca_many', 'Ya mandaste 10 solicitudes hoy. Espera a que Casa Viva conteste.', array( 'status' => 429 ) ); }
		$offer = $search['results'][ $index ];
		$now = current_time( 'mysql', true );
		$test = '1' === (string) get_user_meta( $user->ID, '_cvd_test_account', true );
		$wpdb->insert( self::t( 'pedidos' ), array(
			'search_id' => (int) $search['id'], 'user_id' => $user->ID, 'status' => 'pendiente',
			'product' => mb_substr( sanitize_text_field( (string) ( $request->get_param( 'product' ) ?: $search['product'] ?: ( $offer['title'] ?? '' ) ) ), 0, 190 ),
			'photo' => (string) $search['photo'], 'offer' => wp_json_encode( $offer ),
			'supplier_usd' => self::usd( $request->get_param( 'supplier_usd' ) ), 'price_usd' => $price,
			'quantity' => max( 1, min( 99, absint( $request->get_param( 'quantity' ) ) ) ),
			'client' => mb_substr( sanitize_text_field( (string) $request->get_param( 'client' ) ), 0, 190 ),
			'note' => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'note' ) ), 0, 600 ),
			'test' => $test ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) { return new WP_Error( 'cvd_busca_save', 'No pude guardar la solicitud. Prueba otra vez.', array( 'status' => 500 ) ); }
		$wpdb->update( self::t( 'busquedas' ), array( 'chosen' => $index, 'chosen_at' => $now ), array( 'id' => (int) $search['id'] ) );
		$pedido = self::get( $id );
		foreach ( self::admin_phones() as $phone ) {
			self::queue( $id, 'nueva', 'admin', $phone, self::first_name( $phone, 'Lennys' ), self::text_admin( $pedido, self::signed_url( $id, $phone ) ), $test );
		}
		return rest_ensure_response( array( 'ok' => true, 'pedido' => self::for_js( $pedido ), 'pedidos' => self::list_for( $user->ID ) ) );
	}

	public static function rest_list() {
		return rest_ensure_response( array( 'pedidos' => self::list_for( get_current_user_id() ) ) );
	}

	/* ------------------------------------------------------------------ solicitudes */

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pedidos' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$row['offer'] = json_decode( (string) $row['offer'], true ) ?: array();
		return $row;
	}

	/** Las últimas 20 solicitudes de la gestora, para «Mis solicitudes». */
	public static function list_for( int $user_id ): array {
		global $wpdb;
		if ( get_option( 'cvd_busca_db' ) !== self::DB_VERSION ) { return array(); }
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'pedidos' ) . ' WHERE user_id=%d ORDER BY id DESC LIMIT 20', $user_id ) );
		return array_values( array_filter( array_map( static fn( $id ) => ( $p = self::get( (int) $id ) ) ? self::for_js( $p ) : null, (array) $ids ) ) );
	}

	public static function for_js( array $p ): array {
		return array(
			'id' => (int) $p['id'], 'status' => $p['status'], 'product' => $p['product'], 'photo' => self::photo_url( (string) $p['photo'] ),
			'price' => null === $p['price_usd'] ? null : (float) $p['price_usd'], 'quantity' => (int) $p['quantity'], 'client' => $p['client'],
			'final' => null === $p['final_usd'] ? null : (float) $p['final_usd'], 'commission' => null === $p['commission_usd'] ? null : (float) $p['commission_usd'],
			'adminNote' => (string) $p['admin_note'], 'date' => get_date_from_gmt( (string) $p['created_at'], 'd/m H:i' ),
		);
	}

	private static function money( ?float $usd ): string {
		if ( null === $usd ) { return '—'; }
		return rtrim( rtrim( number_format( $usd, 2, ',', '.' ), '0' ), ',' ) . ' USD';
	}

	/* ------------------------------------------------------------------ avisos */

	private static function admin_phones(): array {
		$opt = false !== get_option( 'cvd_busca_admin_phones', false ) ? 'cvd_busca_admin_phones' : 'cvd_payout_admin_phones';
		return CVD_Payout_Avisos::phones( $opt, array( '5356885368' ) );
	}

	private static function first_name( string $phone, string $fallback ): string {
		$user = CVD_Payout_Avisos::person_for_phone( $phone );
		return $user ? ( strtok( (string) $user->display_name, ' ' ) ?: $user->display_name ) : $fallback;
	}

	/** Apunta un aviso. false si ya existía (misma solicitud, tipo y teléfono): no se repite. */
	public static function queue( int $pedido_id, string $kind, string $role, string $phone, string $name, string $body, bool $test = false ): bool {
		global $wpdb;
		$p8 = CVD_Payout_Math::phone8( $phone );
		if ( ! $p8 || '' === trim( $body ) ) { return false; }
		$done = $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::t( 'avisos' ) . ' (pedido_id,kind,role,phone,name,body,dedupe,test,created_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%d,%s)',
			$pedido_id, $kind, $role, '53' . $p8, mb_substr( $name, 0, 190 ), $body, hash( 'sha256', $pedido_id . '|' . $kind . '|' . $p8 ), $test ? 1 : 0, current_time( 'mysql', true )
		) );
		return 1 === (int) $done;
	}

	public static function text_admin( array $p, string $link ): string {
		$user = get_userdata( (int) $p['user_id'] );
		$o = $p['offer'];
		$who = $user ? $user->display_name : 'Una gestora';
		$wa = $user ? CVD_Payout_Math::phone8( (string) get_user_meta( $user->ID, '_cvd_whatsapp', true ) ) : '';
		$lines = array(
			'🔎 *' . $who . ' quiere vender algo bajo pedido* (NEXO Busca #' . $p['id'] . ')',
			'*' . $p['product'] . '*' . ( (int) $p['quantity'] > 1 ? ' × ' . (int) $p['quantity'] : '' ),
			'Ella quiere cobrar: *' . self::money( (float) $p['price_usd'] ) . '*' . ( (int) $p['quantity'] > 1 ? ' cada uno' : '' ),
			'',
			'Proveedor (anuncio): ' . ( ( $o['price'] ?? '' ) ?: 'precio no visible' ) . ( null !== $p['supplier_usd'] ? ' (≈ ' . self::money( (float) $p['supplier_usd'] ) . ')' : '' ),
		);
		if ( ! empty( $o['source'] ) || ! empty( $o['location'] ) ) { $lines[] = trim( implode( ' · ', array_filter( array( $o['source'] ?? '', $o['location'] ?? '' ) ) ) ); }
		if ( ! empty( $o['phone'] ) ) { $lines[] = 'Tel. del anuncio: ' . $o['phone']; }
		if ( ! empty( $o['url'] ) ) { $lines[] = $o['url']; }
		if ( '' !== (string) $p['client'] ) { $lines[] = 'Cliente: ' . $p['client']; }
		if ( '' !== trim( (string) $p['note'] ) ) { $lines[] = 'Nota: ' . $p['note']; }
		if ( $wa ) { $lines[] = 'WhatsApp de ' . ( strtok( $who, ' ' ) ?: $who ) . ': ' . $wa; }
		$lines[] = '';
		$lines[] = 'Primero compruébalo con el proveedor. Después, aquí lo confirmas (con precio y comisión) o pones «No hay»:';
		$lines[] = $link;
		return implode( "\n", $lines );
	}

	public static function text_gestora( array $p ): string {
		$area = home_url( '/area-gestoras/#buscar' );
		if ( 'confirmada' === $p['status'] ) {
			$lines = array(
				'✅ *Casa Viva confirmó tu solicitud #' . $p['id'] . '*: ' . $p['product'] . ( (int) $p['quantity'] > 1 ? ' × ' . (int) $p['quantity'] : '' ),
				'Precio para tu cliente: *' . self::money( null === $p['final_usd'] ? (float) $p['price_usd'] : (float) $p['final_usd'] ) . '*' . ( (int) $p['quantity'] > 1 ? ' cada uno' : '' ),
			);
			if ( null !== $p['commission_usd'] ) { $lines[] = 'Tu comisión: *' . self::money( (float) $p['commission_usd'] ) . '*'; }
			if ( '' !== trim( (string) $p['admin_note'] ) ) { $lines[] = $p['admin_note']; }
			$lines[] = 'Ya se lo puedes confirmar a tu cliente. Lo tienes en tu área → NEXO Busca → Mis solicitudes:';
			$lines[] = $area;
			return implode( "\n", $lines );
		}
		return '😕 *Solicitud #' . $p['id'] . '* (' . $p['product'] . '): ahora mismo *no lo podemos conseguir*.' . ( '' !== trim( (string) $p['admin_note'] ) ? "\n" . $p['admin_note'] : '' ) . "\n" . 'Puedes buscar otra opción en NEXO Busca:' . "\n" . $area;
	}

	public static function bot_pending(): WP_REST_Response {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,pedido_id,kind,role,phone,name,body,test,created_at FROM ' . self::t( 'avisos' ) . ' WHERE notified_at IS NULL AND created_at>=%s ORDER BY id ASC LIMIT 50', gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'id' => (int) $r['id'], 'pedidoId' => (int) $r['pedido_id'], 'kind' => $r['kind'], 'role' => $r['role'], 'phone' => $r['phone'], 'name' => $r['name'], 'body' => $r['body'], 'test' => (bool) $r['test'], 'at' => $r['created_at'] );
		}
		$response = rest_ensure_response( array( 'avisos' => $out ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function bot_done( WP_REST_Request $request ) {
		global $wpdb;
		$params = (array) $request->get_json_params();
		$id = absint( $request['id'] );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'avisos' ) . ' WHERE id=%d', $id ) ) ) { return new WP_Error( 'cvd_aviso_not_found', 'Aviso no encontrado.', array( 'status' => 404 ) ); }
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'avisos' ) . ' SET notified_at=%s,result=%s,note=%s WHERE id=%d AND notified_at IS NULL', current_time( 'mysql', true ), 'skipped' === ( $params['result'] ?? '' ) ? 'skipped' : 'sent', mb_substr( sanitize_text_field( (string) ( $params['note'] ?? '' ) ), 0, 190 ), $id ) );
		$response = rest_ensure_response( array( 'ok' => true, 'already' => 1 !== (int) $updated ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/* ------------------------------------------------------------------ página de Lennys */

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-busca'; }

	public static function signed_url( int $id, string $phone ): string {
		$token = CVD_Payout_Math::sign( $id, CVD_Payout_Math::phone8( $phone ), time() + self::TTL, self::key() );
		return add_query_arg( array( 'cvd_busca' => $id, 't' => $token ), home_url( '/' ) );
	}

	/** ['ok', 'actor', 'token'] */
	private static function access( int $id ): array {
		if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) { return array( 'ok' => true, 'actor' => get_current_user_id(), 'token' => '' ); }
		$token = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['t'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$phone = '' === $token ? '' : CVD_Payout_Math::verify( $id, $token, time(), self::key() );
		if ( '' === $phone || ! in_array( '53' . $phone, self::admin_phones(), true ) ) { return array( 'ok' => false, 'actor' => 0, 'token' => '' ); }
		$person = CVD_Payout_Avisos::person_for_phone( $phone );
		return array( 'ok' => true, 'actor' => $person ? $person->ID : absint( get_option( 'cvd_panel_actor_user_id', 0 ) ), 'token' => $token );
	}

	public static function route_page(): void {
		if ( ! isset( $_GET['cvd_busca'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: solicitud NEXO Busca (privado)' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$id = absint( $_GET['cvd_busca'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$p = self::get( $id );
		$access = $p ? self::access( $id ) : array( 'ok' => false );
		if ( ! $p || ! $access['ok'] ) {
			status_header( 403 );
			self::shell( 'Enlace no válido', '<div class="box"><h1>Este enlace ya no vale</h1><p>Caducó o no es para ti. Entra en la web con tu cuenta y vuelve a abrirlo, o pídele el aviso a Ernesto.</p></div>' );
		}
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['cvd_estado'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$notice = self::decide( $p, $access );
			$p = self::get( $id );
		}
		self::shell( 'Solicitud #' . $id, self::body( $p, $access, $notice ) );
	}

	private static function decide( array $p, array $access ): string {
		global $wpdb;
		if ( '' === $access['token'] && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'cvd_busca_' . $p['id'] ) ) { return 'La página venció. Recárgala e intenta otra vez.'; }
		$status = sanitize_key( wp_unslash( $_POST['cvd_estado'] ?? '' ) );
		if ( ! in_array( $status, array( 'confirmada', 'no_hay', 'pendiente' ), true ) ) { return 'Elige qué pasó.'; }
		$data = array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ), 'admin_note' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['cvd_nota'] ?? '' ) ), 0, 400 ) );
		if ( 'confirmada' === $status ) {
			$final = self::usd( wp_unslash( $_POST['cvd_final'] ?? '' ) );
			if ( null === $final ) { return 'Pon el precio final para el cliente (USD).'; }
			$commission = self::usd( wp_unslash( $_POST['cvd_comision'] ?? '' ) );
			if ( null === $commission && '0' !== trim( (string) wp_unslash( $_POST['cvd_comision'] ?? '' ) ) ) { return 'Pon la comisión de la gestora (USD). Si no lleva, pon 0.'; }
			$data['final_usd'] = $final;
			$data['commission_usd'] = $commission ?? 0;
		}
		if ( 'pendiente' !== $status ) { $data['decided_by'] = (int) $access['actor']; $data['decided_at'] = current_time( 'mysql', true ); }
		$wpdb->update( self::t( 'pedidos' ), $data, array( 'id' => (int) $p['id'] ) );
		if ( 'pendiente' === $status ) { return 'ok'; }
		$fresh = self::get( (int) $p['id'] );
		$phone = CVD_Payout_Math::phone8( (string) get_user_meta( (int) $p['user_id'], '_cvd_whatsapp', true ) ) ?: CVD_Payout_Math::phone8( (string) get_user_meta( (int) $p['user_id'], 'billing_phone', true ) );
		$user = get_userdata( (int) $p['user_id'] );
		if ( $phone && $fresh ) {
			// Clave por estado y cifras: si Lennys corrige el precio, sale otro aviso; si pulsa dos veces, no.
			$kind = $status . '-' . substr( md5( (string) $fresh['final_usd'] . '|' . (string) $fresh['commission_usd'] ), 0, 8 );
			self::queue( (int) $p['id'], $kind, 'gestora', $phone, $user ? $user->display_name : '', self::text_gestora( $fresh ), (bool) $fresh['test'] );
		}
		return 'ok';
	}

	private static function body( array $p, array $access, string $notice ): string {
		$o = $p['offer'];
		$user = get_userdata( (int) $p['user_id'] );
		$labels = array( 'pendiente' => 'Pendiente', 'confirmada' => 'Confirmada', 'no_hay' => 'No hay' );
		$h = '<p class="kick">NEXO Busca · solicitud #' . (int) $p['id'] . ' · ' . esc_html( get_date_from_gmt( (string) $p['created_at'], 'd/m H:i' ) ) . '</p>';
		$h .= '<h1>' . esc_html( $p['product'] ) . '</h1>';
		if ( 'ok' === $notice ) { $h .= '<div class="ok">Guardado. ' . ( 'pendiente' === $p['status'] ? '' : 'A la gestora le llega el aviso por WhatsApp.' ) . '</div>'; }
		elseif ( '' !== $notice ) { $h .= '<div class="err">' . esc_html( $notice ) . '</div>'; }
		if ( '' !== (string) $p['photo'] ) { $h .= '<img class="photo" src="' . esc_url( self::photo_url( (string) $p['photo'] ) ) . '" alt="">'; }
		$h .= '<div class="total"><small>' . esc_html( ( $user ? $user->display_name : 'La gestora' ) . ' quiere cobrar' ) . '</small><strong>' . esc_html( self::money( (float) $p['price_usd'] ) ) . '</strong><span>' . esc_html( (int) $p['quantity'] > 1 ? 'cada uno · ' . (int) $p['quantity'] . ' unidades' : '1 unidad' ) . '</span></div>';
		$h .= '<div class="copy">';
		$h .= '<div class="row"><span><small>Proveedor (anuncio)</small><b>' . esc_html( ( $o['price'] ?? '' ) ?: 'precio no visible' ) . '</b>' . ( null !== $p['supplier_usd'] ? '<small>≈ ' . esc_html( self::money( (float) $p['supplier_usd'] ) ) . '</small>' : '' ) . '</span></div>';
		if ( ! empty( $o['title'] ) ) { $h .= '<div class="row"><span><small>Anuncio</small>' . esc_html( $o['title'] ) . '<small>' . esc_html( implode( ' · ', array_filter( array( $o['source'] ?? '', $o['location'] ?? '' ) ) ) ) . '</small></span></div>'; }
		$wa_text = 'Hola, vi su anuncio de ' . $p['product'] . ( ! empty( $o['price'] ) ? ' a ' . $o['price'] : '' ) . '. ¿Lo tiene disponible?';
		$phone = preg_replace( '/\D/', '', (string) ( $o['phone'] ?? '' ) );
		if ( 8 === strlen( $phone ) ) { $phone = '53' . $phone; }
		if ( $phone ) { $h .= '<div class="row"><span><small>Teléfono del anuncio</small><b>' . esc_html( (string) $o['phone'] ) . '</b></span><a class="btn soft" href="' . esc_url( 'https://wa.me/' . $phone . '?text=' . rawurlencode( $wa_text ) ) . '" target="_blank" rel="noopener">WhatsApp</a></div>'; }
		if ( ! empty( $o['url'] ) ) { $h .= '<div class="row"><span><small>Enlace</small>' . esc_html( wp_parse_url( (string) $o['url'], PHP_URL_HOST ) ?: '' ) . '</span><a class="btn soft" href="' . esc_url( (string) $o['url'] ) . '" target="_blank" rel="noopener nofollow">Abrir anuncio</a></div>'; }
		if ( '' !== (string) $p['client'] ) { $h .= '<div class="row"><span><small>Cliente</small>' . esc_html( $p['client'] ) . '</span></div>'; }
		if ( '' !== trim( (string) $p['note'] ) ) { $h .= '<div class="row"><span><small>Nota de la gestora</small>' . esc_html( $p['note'] ) . '</span></div>'; }
		$gw = $user ? CVD_Payout_Math::phone8( (string) get_user_meta( $user->ID, '_cvd_whatsapp', true ) ) : '';
		if ( $gw ) { $h .= '<div class="row"><span><small>WhatsApp de la gestora</small><b>' . esc_html( $gw ) . '</b></span><a class="btn soft" href="' . esc_url( 'https://wa.me/53' . $gw ) . '" target="_blank" rel="noopener">Escribirle</a></div>'; }
		$h .= '</div>';

		$h .= '<div class="state">Estado: <b>' . esc_html( $labels[ $p['status'] ] ?? $p['status'] ) . '</b>';
		if ( 'confirmada' === $p['status'] ) { $h .= ' · precio ' . esc_html( self::money( null === $p['final_usd'] ? null : (float) $p['final_usd'] ) ) . ' · comisión ' . esc_html( self::money( null === $p['commission_usd'] ? null : (float) $p['commission_usd'] ) ); }
		$h .= '</div>';

		$hidden = '<input type="hidden" name="t" value="' . esc_attr( (string) $access['token'] ) . '">' . ( '' === $access['token'] ? wp_nonce_field( 'cvd_busca_' . $p['id'], '_wpnonce', true, false ) : '' );
		$open = 'pendiente' === $p['status'];
		$h .= $open ? '' : '<details class="pay"><summary>Cambiar el estado</summary>';
		$h .= '<form method="post" class="pay">' . $hidden . '<input type="hidden" name="cvd_estado" value="confirmada"><p class="note">Confírmalo <b>solo después de hablar con el proveedor</b>. La comisión de la gestora la decides tú aquí.</p>'
			. '<label><span>Precio final para el cliente (USD' . ( (int) $p['quantity'] > 1 ? ', cada uno' : '' ) . ')</span><input name="cvd_final" inputmode="decimal" value="' . esc_attr( (string) ( $p['final_usd'] ?? $p['price_usd'] ) ) . '" required></label>'
			. '<label><span>Comisión de la gestora (USD)</span><input name="cvd_comision" inputmode="decimal" value="' . esc_attr( null === $p['commission_usd'] ? '' : (string) $p['commission_usd'] ) . '" placeholder="Ej.: 2" required></label>'
			. '<label><span>Nota para la gestora (opcional)</span><textarea name="cvd_nota" rows="2" placeholder="Ej.: llega el jueves; color azul">' . esc_textarea( (string) $p['admin_note'] ) . '</textarea></label>'
			. '<button class="btn big" type="submit">Confirmar: ya lo comprobé</button></form>';
		$h .= '<form method="post" class="pay alt">' . $hidden . '<input type="hidden" name="cvd_estado" value="no_hay"><label><span>Si no se puede (opcional: por qué)</span><textarea name="cvd_nota" rows="2" placeholder="Ej.: el proveedor ya no tiene"></textarea></label><button class="btn big bad" type="submit">No hay</button></form>';
		if ( ! $open ) { $h .= '<form method="post" class="pay alt">' . $hidden . '<input type="hidden" name="cvd_estado" value="pendiente"><button class="btn soft" type="submit">Volver a «pendiente» (sin avisar)</button></form></details>'; }
		return $h;
	}

	private static function shell( string $title, string $body ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$css = ':root{--ink:#2B2420;--muted:#6f6a62;--line:#e8e1d8;--bg:#F6F1E6;--card:#fffdf9;--brand:#0D3B45;--soft:#e4ece8;--amb:#A35A1F;--bad:#9a392f;--good:#2f7a4f}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:520px;margin:0 auto;padding:16px 16px 40px}'
			. 'h1{font-size:23px;line-height:1.2;margin:2px 0 12px}.kick{margin:0;color:var(--muted);font-size:13px}.photo{display:block;width:100%;max-height:320px;object-fit:contain;background:#fff;border-radius:14px;border:1px solid var(--line);margin-bottom:12px}'
			. '.total{background:var(--brand);color:#fff;border-radius:16px;padding:16px;margin-bottom:12px}.total small{display:block;opacity:.85;font-size:13px}.total strong{display:block;font-size:34px;line-height:1.1;margin:4px 0}.total span{font-size:14px;opacity:.9}'
			. '.copy{background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:12px}.row{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:11px 14px;border-top:1px solid var(--line)}.row:first-child{border-top:0}.row span{min-width:0;overflow-wrap:anywhere}.row small{display:block;color:var(--muted);font-size:12px}.row b{font-size:18px}'
			. '.btn{display:inline-block;border:0;border-radius:999px;background:var(--amb);color:#fff;font:inherit;font-weight:700;padding:11px 16px;text-decoration:none;cursor:pointer;min-height:44px}.btn.soft{background:var(--soft);color:var(--brand);white-space:nowrap;font-size:14px;padding:9px 13px}.btn.big{width:100%;font-size:17px;padding:14px;margin-top:6px}.btn.bad{background:#fff;color:var(--bad);border:2px solid #e8c4bb}'
			. '.state{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin-bottom:12px}.pay{display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:12px}.pay summary{font-weight:700;cursor:pointer}.pay label{display:block;margin-bottom:10px}.pay label span{display:block;font-weight:700;font-size:14px;margin-bottom:5px}.pay input,.pay textarea{width:100%;font:inherit;padding:11px;border:1px solid #cdb8ad;border-radius:11px;min-height:44px;background:#fff}'
			. '.note{background:#fff7e6;border:1px solid #f0d9a8;border-radius:12px;padding:10px 12px;font-size:14px;margin:0 0 12px}.ok{background:#e7f4ec;border:1px solid #b9dcc6;color:#1f5a37;border-radius:12px;padding:12px;margin-bottom:12px;font-weight:700}.err{background:#fff0ed;border:1px solid #f0c4bb;color:var(--bad);border-radius:12px;padding:12px;margin-bottom:12px}.box{background:var(--card);border-radius:14px;padding:20px;margin-top:30px}';
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' · NEXO Busca · Casa Viva</title><style>' . $css . '</style></head><body><main>' . $body . '</main><script>document.addEventListener("submit",function(e){var b=e.target.querySelector("button[type=submit]");if(b)setTimeout(function(){b.disabled=true;b.textContent="Guardando…"},0)})</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body ya escapado al armarlo.
		exit;
	}
}
