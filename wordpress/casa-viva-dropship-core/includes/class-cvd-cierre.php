<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Cerrar mi turno» (Pilar 2, 29-oct-2026): la dependienta cuenta sus billetes en la pantalla de Ventas, el sistema
 * dice «cuadra» o «faltan/sobran X» con la lista de cobros, y el cierre queda FIRMADO y guardado por persona y por día.
 *
 * - Lo que debería haber sale de lo que ELLA apuntó en «Registrar dinero recibido» (no se mezcla con la otra dependienta).
 * - El cierre firmado no se cambia ni se borra: un error se corrige con otro cierre que apunta al anterior y lleva motivo.
 * - Este paso solo LEE pedidos y GUARDA cierres; no cambia ningún pedido, comisión ni precio, y no manda mensajes.
 * - Lennys no recibe dinero: no firma. Ernesto (usuario 1) puede firmar de PRUEBA (is_test = 1) y no cuenta para nada.
 */
final class CVD_Cierre {
	private const DB_VERSION = '1';
	private const DB_OPTION = 'cvd_cierre_db';
	private const TEST_USER_ID = 1;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cvd_cierres';
	}

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			user_name varchar(80) NOT NULL DEFAULT '',
			day date NOT NULL,
			signed_at datetime NOT NULL,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			corrige_id bigint(20) unsigned DEFAULT NULL,
			fund_usd bigint(20) NOT NULL DEFAULT 0,
			fund_mn bigint(20) NOT NULL DEFAULT 0,
			received_usd bigint(20) NOT NULL DEFAULT 0,
			received_mn bigint(20) NOT NULL DEFAULT 0,
			out_usd bigint(20) NOT NULL DEFAULT 0,
			out_mn bigint(20) NOT NULL DEFAULT 0,
			expected_usd bigint(20) NOT NULL DEFAULT 0,
			expected_mn bigint(20) NOT NULL DEFAULT 0,
			counted_usd bigint(20) NOT NULL DEFAULT 0,
			counted_mn bigint(20) NOT NULL DEFAULT 0,
			diff_usd bigint(20) NOT NULL DEFAULT 0,
			diff_mn bigint(20) NOT NULL DEFAULT 0,
			keep_usd bigint(20) NOT NULL DEFAULT 0,
			keep_mn bigint(20) NOT NULL DEFAULT 0,
			transfer_cup bigint(20) NOT NULL DEFAULT 0,
			note text,
			detail longtext,
			PRIMARY KEY  (id),
			KEY user_day (user_id,day),
			KEY day (day)
		) {$charset};" );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function routes(): void {
		$perm = array( 'CVD_Sales', 'can_view' );
		register_rest_route( 'casa-viva/v1', '/cierre', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_preview' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_sign' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( 'casa-viva/v1', '/cierre/historial', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_history' ), 'permission_callback' => $perm,
		) );
	}

	/** Versión del archivo por su fecha: así un cambio llega a los móviles sin tocar la versión del plugin. */
	private static function asset_ver( string $file ): string {
		$path = CVD_DIR . 'assets/' . $file;
		return CVD_VERSION . '.' . ( is_readable( $path ) ? (string) filemtime( $path ) : '0' );
	}

	public static function assets(): void {
		if ( ! is_page( 'ventas' ) || ! CVD_Sales::can_view() ) { return; }
		wp_enqueue_style( 'cvd-cierre', CVD_URL . 'assets/cierre.css', array( 'cvd-sales' ), self::asset_ver( 'cierre.css' ) );
		wp_enqueue_script( 'cvd-cierre', CVD_URL . 'assets/cierre.js', array( 'cvd-sales' ), self::asset_ver( 'cierre.js' ), true );
		wp_localize_script( 'cvd-cierre', 'cvdCierre', array(
			'url' => rest_url( 'casa-viva/v1/cierre' ),
			'historyUrl' => rest_url( 'casa-viva/v1/cierre/historial' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'canSign' => self::can_sign( get_current_user_id() ),
			'usdBills' => CVD_Cierre_Math::USD_BILLS,
			'mnBills' => CVD_Cierre_Math::MN_BILLS,
		) );
	}

	/* ---------- quién ---------- */

	/** Ids de usuario de las dependientas (rol cvd_clerk) y de administración (manage_woocommerce). */
	private static function people(): array {
		$clerks = array(); $admins = array();
		foreach ( get_users( array( 'role' => 'cvd_clerk', 'fields' => 'ID' ) ) as $id ) { $clerks[] = (int) $id; }
		foreach ( get_users( array( 'capability' => 'manage_woocommerce', 'fields' => 'ID' ) ) as $id ) { $admins[] = (int) $id; }
		return array( $clerks, array_values( array_diff( $admins, $clerks ) ) );
	}

	public static function can_sign( int $user_id ): bool {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) { return false; }
		return CVD_Registration::is_clerk( $user ) || self::TEST_USER_ID === $user_id;
	}

	private static function name_of( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? mb_substr( (string) $user->display_name, 0, 80 ) : '';
	}

	/* ---------- lectura de cobros ---------- */

	/** Todas las filas de dinero que entraron en la ventana del día (de todos); luego se filtra por persona. */
	public static function day_rows( string $day ): array {
		[ $from, $to ] = CVD_Cierre_Math::window( $day );
		[ $clerks, $admins ] = self::people();
		$keys = array( '_cvd_cash_returned_at', '_cvd_cash_verified_at', '_cvd_collection_received_at' );
		$meta_query = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) { $meta_query[] = array( 'key' => $key, 'value' => array( $from, $to ), 'compare' => 'BETWEEN', 'type' => 'CHAR' ); }
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 400, 'status' => array_keys( wc_get_order_statuses() ), 'meta_query' => $meta_query, 'orderby' => 'date', 'order' => 'ASC' ) );
		$rows = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			$meta = array();
			foreach ( array_merge( $keys, array( '_cvd_cash_returned_by', '_cvd_cash_verified_by', '_cvd_collection_received_by', '_cvd_collection_method', '_cvd_collection_amount_usd', '_cvd_collection_amount_cup',
				'_cvd_collection_account_name', '_cvd_cash_status', '_cvd_fulfillment_type', '_cvd_test_order', '_cvd_operation_status', 'gestora_nombre', '_cvd_messenger_user_id' ) ) as $key ) {
				$meta[ $key ] = $order->get_meta( $key, true );
			}
			$row = CVD_Cierre_Math::row_from_order( array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'meta' => $meta ), $from, $to, $clerks, $admins );
			if ( $row ) { $rows[] = $row; }
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['at'], $b['at'] ) ?: $a['id'] <=> $b['id'] );
		return $rows;
	}

	private static function cuba_time( string $utc ): string {
		return CVD_Messenger_Cash::cuba_time( $utc, 'H:i' );
	}

	/** Fila lista para la pantalla. */
	private static function view_row( array $r ): array {
		return array( 'id' => $r['id'], 'number' => $r['number'], 'time' => self::cuba_time( $r['at'] ), 'method' => $r['method'], 'usd' => $r['usd'], 'mn' => $r['mn'], 'trf' => $r['trf'], 'otros' => $r['otros'],
			'account' => $r['account'], 'pickup' => $r['pickup'], 'gestora' => $r['gestora'], 'mixed' => $r['mixed'] );
	}

	/** Último cierre firmado vigente (no corregido) de cualquiera, antes o en el día: de ahí sale el fondo sugerido. */
	private static function last_signed( string $before_or_on ): ?array {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} c WHERE c.is_test = 0 AND c.day <= %s AND NOT EXISTS (SELECT 1 FROM {$t} n WHERE n.corrige_id = c.id) ORDER BY c.day DESC, c.signed_at DESC LIMIT 1", $before_or_on ), ARRAY_A );
		return $row ?: null;
	}

	/** Cierre vigente de una persona en un día (el último, si hubo correcciones). */
	private static function current_for( int $user_id, string $day ): ?array {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} c WHERE c.user_id = %d AND c.day = %s AND NOT EXISTS (SELECT 1 FROM {$t} n WHERE n.corrige_id = c.id) ORDER BY c.signed_at DESC LIMIT 1", $user_id, $day ), ARRAY_A );
		return $row ?: null;
	}

	public static function shape_closing( array $row ): array {
		$detail = json_decode( (string) $row['detail'], true );
		return array(
			'id' => (int) $row['id'], 'userId' => (int) $row['user_id'], 'name' => $row['user_name'], 'day' => $row['day'], 'test' => (bool) $row['is_test'],
			'signedAt' => $row['signed_at'], 'signedAtLocal' => CVD_Messenger_Cash::cuba_time( (string) $row['signed_at'], 'd/m H:i' ), 'corrects' => $row['corrige_id'] ? (int) $row['corrige_id'] : null,
			'fund' => array( 'usd' => (int) $row['fund_usd'], 'mn' => (int) $row['fund_mn'] ), 'received' => array( 'usd' => (int) $row['received_usd'], 'mn' => (int) $row['received_mn'] ),
			'out' => array( 'usd' => (int) $row['out_usd'], 'mn' => (int) $row['out_mn'] ), 'expected' => array( 'usd' => (int) $row['expected_usd'], 'mn' => (int) $row['expected_mn'] ),
			'counted' => array( 'usd' => (int) $row['counted_usd'], 'mn' => (int) $row['counted_mn'] ), 'diff' => array( 'usd' => (int) $row['diff_usd'], 'mn' => (int) $row['diff_mn'] ),
			'keep' => array( 'usd' => (int) $row['keep_usd'], 'mn' => (int) $row['keep_mn'] ), 'transferCup' => (int) $row['transfer_cup'], 'note' => (string) $row['note'], 'detail' => is_array( $detail ) ? $detail : array(),
		);
	}

	/** Pantalla de cierre: cobros de la persona, transferencias por tarjeta, fondo sugerido y, si ya firmó, su cierre. */
	public static function preview( int $user_id, string $day ): array {
		$all = self::day_rows( $day );
		$mine = CVD_Cierre_Math::mine( $all, $user_id );
		$cash = array_values( array_filter( $mine, static fn( array $r ): bool => 0 !== $r['usd'] || 0 !== $r['mn'] ) );
		$transfers = array_values( array_filter( $all, static fn( array $r ): bool => in_array( $r['method'], array( 'transfer', 'zelle', 'usdt' ), true ) && 'admin' !== $r['group'] ) );
		$totals = CVD_Cierre_Math::totals( $mine );
		$last = self::last_signed( $day );
		$closed = self::current_for( $user_id, $day );
		$closed_ids = array();
		if ( $closed ) { $d = json_decode( (string) $closed['detail'], true ); $closed_ids = array_map( 'intval', (array) ( $d['orders'] ?? array() ) ); }
		$new_after = $closed ? array_values( array_filter( $mine, static fn( array $r ): bool => ! in_array( $r['id'], $closed_ids, true ) ) ) : array();
		$others = array_values( array_filter( $all, static fn( array $r ): bool => 'cajon' === $r['group'] && $r['by'] !== $user_id && ( 0 !== $r['usd'] || 0 !== $r['mn'] ) ) );
		$others_by = array();
		foreach ( $others as $r ) { $n = self::name_of( $r['by'] ) ?: 'Otra persona'; $others_by[ $n ] = $others_by[ $n ] ?? array( 'name' => $n, 'n' => 0, 'usd' => 0, 'mn' => 0 ); $others_by[ $n ]['n']++; $others_by[ $n ]['usd'] += $r['usd']; $others_by[ $n ]['mn'] += $r['mn']; }
		$admin = array_values( array_filter( $all, static fn( array $r ): bool => 'admin' === $r['group'] ) );
		return array(
			'day' => $day, 'dayLabel' => ( new DateTimeImmutable( $day ) )->format( 'd/m/Y' ), 'user' => array( 'id' => $user_id, 'name' => self::name_of( $user_id ), 'canSign' => self::can_sign( $user_id ) ),
			'cash' => array_map( array( __CLASS__, 'view_row' ), $cash ),
			'cashTotals' => array( 'n' => count( $cash ), 'usd' => $totals['usd'], 'mn' => $totals['mn'] ),
			'transfers' => array_map( array( __CLASS__, 'view_row' ), $transfers ), 'byCard' => CVD_Cierre_Math::by_card( $transfers ),
			'othersCajon' => array_values( $others_by ),
			'adminRows' => count( $admin ),
			'suggestedFund' => $last ? array( 'usd' => (int) $last['keep_usd'], 'mn' => (int) $last['keep_mn'], 'from' => $last['user_name'], 'day' => $last['day'] ) : null,
			'closing' => $closed ? self::shape_closing( $closed ) : null,
			'newAfterClosing' => array_map( array( __CLASS__, 'view_row' ), $new_after ),
		);
	}

	/* ---------- firma ---------- */

	/**
	 * Firma el cierre. Todo se recalcula aquí con los datos de AHORA: lo que mande la pantalla (fondo, salidas, billetes)
	 * es lo que la persona dice; lo que cobró sale de la web.
	 * $in: day, fund{usd,mn}, outs[{why,usd,mn}], bills{usd{valor:cant},mn{…}}, keep{usd,mn}, note, corrects (id).
	 */
	public static function sign( int $user_id, array $in ) {
		if ( ! self::can_sign( $user_id ) ) { return new WP_Error( 'cvd_cierre_who', 'Este cierre lo firma una dependienta (Nana o Zaymi). Lennys no recibe dinero.', array( 'status' => 403 ) ); }
		$day = (string) ( $in['day'] ?? '' );
		if ( ! CVD_Cierre_Math::valid_day( $day ) ) { return new WP_Error( 'cvd_cierre_day', 'Día no válido.', array( 'status' => 422 ) ); }
		if ( $day > CVD_Cierre_Math::day_of( gmdate( 'Y-m-d H:i:s' ) ) ) { return new WP_Error( 'cvd_cierre_future', 'No se puede cerrar un día que aún no llegó.', array( 'status' => 422 ) ); }
		$fund = array( 'usd' => max( 0, CVD_Cierre_Math::cents( $in['fund']['usd'] ?? 0 ) ), 'mn' => max( 0, CVD_Cierre_Math::pesos( $in['fund']['mn'] ?? 0 ) ) );
		$keep = array( 'usd' => max( 0, CVD_Cierre_Math::cents( $in['keep']['usd'] ?? 0 ) ), 'mn' => max( 0, CVD_Cierre_Math::pesos( $in['keep']['mn'] ?? 0 ) ) );
		$bills_usd = is_array( $in['bills']['usd'] ?? null ) ? $in['bills']['usd'] : array();
		$bills_mn = is_array( $in['bills']['mn'] ?? null ) ? $in['bills']['mn'] : array();
		$counted = array( 'usd' => CVD_Cierre_Math::bills_total( $bills_usd, CVD_Cierre_Math::USD_BILLS ) * 100, 'mn' => CVD_Cierre_Math::bills_total( $bills_mn, CVD_Cierre_Math::MN_BILLS ) );
		$outs_in = is_array( $in['outs'] ?? null ) ? array_slice( $in['outs'], 0, 40 ) : array();
		$outs_clean = array();
		foreach ( $outs_in as $o ) {
			if ( ! is_array( $o ) ) { continue; }
			$usd = max( 0, CVD_Cierre_Math::cents( $o['usd'] ?? 0 ) ); $mn = max( 0, CVD_Cierre_Math::pesos( $o['mn'] ?? 0 ) );
			if ( 0 === $usd && 0 === $mn ) { continue; }
			$outs_clean[] = array( 'why' => mb_substr( sanitize_text_field( (string) ( $o['why'] ?? '' ) ), 0, 80 ), 'usd' => $usd, 'mn' => $mn );
		}
		[ $out_usd, $out_mn ] = array( array_sum( array_column( $outs_clean, 'usd' ) ), array_sum( array_column( $outs_clean, 'mn' ) ) );
		$note = mb_substr( trim( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ) ), 0, 500 );

		$all = self::day_rows( $day );
		$mine = CVD_Cierre_Math::mine( $all, $user_id );
		$tot = CVD_Cierre_Math::totals( $mine );
		$settle = CVD_Cierre_Math::settle( $fund, array( 'usd' => $tot['usd'], 'mn' => $tot['mn'] ), array( 'usd' => $out_usd, 'mn' => $out_mn ), $counted );
		if ( ! $settle['ok'] && '' === $note ) { return new WP_Error( 'cvd_cierre_note', 'No cuadra: escribe en la nota qué crees que pasó (por ejemplo «falta el vuelto del #5604») y vuelve a firmar.', array( 'status' => 422, 'settle' => $settle ) ); }
		if ( $keep['usd'] > $counted['usd'] || $keep['mn'] > $counted['mn'] ) { return new WP_Error( 'cvd_cierre_keep', 'El fondo que dejas no puede ser más de lo que contaste.', array( 'status' => 422 ) ); }
		$suggested = self::last_signed( $day );
		if ( $suggested && ( (int) $suggested['keep_usd'] !== $fund['usd'] || (int) $suggested['keep_mn'] !== $fund['mn'] ) && '' === $note ) {
			return new WP_Error( 'cvd_cierre_fund_note', 'El fondo con el que empezaste no es el que dejó el último cierre. Escribe en la nota por qué (por ejemplo «Lennys me dio 50 más»).', array( 'status' => 422 ) );
		}

		$current = self::current_for( $user_id, $day );
		$corrects = $current ? (int) $current['id'] : 0;
		if ( $current && (int) ( $in['corrects'] ?? 0 ) !== $corrects ) { return new WP_Error( 'cvd_cierre_exists', 'Ya firmaste el cierre de este día. Para cambiarlo, usa «Corregir mi cierre» y escribe el motivo.', array( 'status' => 409 ) ); }
		if ( $current && '' === $note ) { return new WP_Error( 'cvd_cierre_correct_note', 'Escribe el motivo de la corrección.', array( 'status' => 422 ) ); }

		$name = self::name_of( $user_id );
		$detail = array(
			'bills' => array( 'usd' => array_map( 'intval', array_intersect_key( $bills_usd, array_flip( array_map( 'strval', CVD_Cierre_Math::USD_BILLS ) ) ) ), 'mn' => array_map( 'intval', array_intersect_key( $bills_mn, array_flip( array_map( 'strval', CVD_Cierre_Math::MN_BILLS ) ) ) ) ),
			'outs' => $outs_clean, 'orders' => array_map( static fn( array $r ): int => $r['id'], $mine ),
			'rows' => array_map( static fn( array $r ): array => array( 'id' => $r['id'], 'number' => $r['number'], 'at' => $r['at'], 'method' => $r['method'], 'usd' => $r['usd'], 'mn' => $r['mn'], 'trf' => $r['trf'], 'otros' => $r['otros'], 'account' => $r['account'] ), $mine ),
			'byCard' => CVD_Cierre_Math::by_card( array_values( array_filter( $all, static fn( array $r ): bool => in_array( $r['method'], array( 'transfer', 'zelle', 'usdt' ), true ) && 'admin' !== $r['group'] ) ) ),
			'deliverToAdmin' => array( 'usd' => $counted['usd'] - $keep['usd'], 'mn' => $counted['mn'] - $keep['mn'] ),
			'version' => defined( 'CVD_VERSION' ) ? CVD_VERSION : '',
		);
		global $wpdb;
		$ok = $wpdb->insert( self::table(), array(
			'user_id' => $user_id, 'user_name' => $name, 'day' => $day, 'signed_at' => current_time( 'mysql', true ), 'is_test' => CVD_Registration::is_clerk( get_userdata( $user_id ) ) ? 0 : 1,
			'corrige_id' => $corrects ?: null,
			'fund_usd' => $fund['usd'], 'fund_mn' => $fund['mn'], 'received_usd' => $tot['usd'], 'received_mn' => $tot['mn'], 'out_usd' => $out_usd, 'out_mn' => $out_mn,
			'expected_usd' => $settle['usd']['expected'], 'expected_mn' => $settle['mn']['expected'], 'counted_usd' => $counted['usd'], 'counted_mn' => $counted['mn'],
			'diff_usd' => $settle['usd']['diff'], 'diff_mn' => $settle['mn']['diff'], 'keep_usd' => $keep['usd'], 'keep_mn' => $keep['mn'], 'transfer_cup' => $tot['trf'],
			'note' => $note, 'detail' => wp_json_encode( $detail ),
		) );
		if ( false === $ok ) { return new WP_Error( 'cvd_cierre_db', 'No se pudo guardar el cierre. No se perdió nada: vuelve a intentarlo.', array( 'status' => 500 ) ); }
		$id = (int) $wpdb->insert_id;
		return array( 'ok' => true, 'id' => $id, 'settle' => $settle, 'closing' => self::shape_closing( (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) ) );
	}

	/** Cierres firmados vigentes (los últimos días). Dependienta: los suyos; administración: todos. */
	public static function history( int $viewer_id, int $days = 14 ): array {
		global $wpdb;
		$t = self::table();
		$days = max( 1, min( 60, $days ) );
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$all_ok = current_user_can( 'manage_woocommerce' );
		$sql = "SELECT * FROM {$t} c WHERE c.day >= %s AND NOT EXISTS (SELECT 1 FROM {$t} n WHERE n.corrige_id = c.id)" . ( $all_ok ? '' : ' AND c.user_id = %d' ) . ' ORDER BY c.day DESC, c.signed_at DESC LIMIT 100';
		$rows = $wpdb->get_results( $all_ok ? $wpdb->prepare( $sql, $since ) : $wpdb->prepare( $sql, $since, $viewer_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape_closing' ), $rows ?: array() );
	}

	/* ---------- REST ---------- */

	public static function rest_preview( WP_REST_Request $request ) {
		$day = sanitize_text_field( (string) $request->get_param( 'day' ) );
		if ( '' === $day ) { $day = CVD_Cierre_Math::day_of( gmdate( 'Y-m-d H:i:s' ) ); }
		if ( ! CVD_Cierre_Math::valid_day( $day ) ) { return new WP_Error( 'cvd_cierre_day', 'Día no válido.', array( 'status' => 422 ) ); }
		$user_id = get_current_user_id();
		// Administración puede mirar el cierre de una dependienta (solo lectura): ?as=<usuario>
		$as = absint( $request->get_param( 'as' ) );
		if ( $as && current_user_can( 'manage_woocommerce' ) ) { $user_id = $as; }
		return rest_ensure_response( self::preview( $user_id, $day ) );
	}

	public static function rest_sign( WP_REST_Request $request ) {
		$result = self::sign( get_current_user_id(), (array) $request->get_json_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_history( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'closings' => self::history( get_current_user_id(), absint( $request->get_param( 'days' ) ) ?: 14 ) ) );
	}
}
