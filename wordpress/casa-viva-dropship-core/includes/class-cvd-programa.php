<?php

defined( 'ABSPATH' ) || exit;

/**
 * Programa de gestoras (D53–D58): lee WooCommerce, llama al cálculo puro (CVD_Programa_Calc) y lo
 * guarda en un libro propio en esta misma base de datos. Pantallas de la gestora («Mi equipo», «Mi
 * piedra», «⭐ N puntos» en su tienda), página de administración y lectura para el panel de Lennys.
 *
 * Interruptor: opción `cvd_programa_gestoras` = sombra | si | no (por defecto sombra).
 *  - sombra: calcula cada noche y lo enseña con el aviso «En prueba», pero no paga.
 *  - si: el cierre del mes queda «cerrado» (por pagar) y se paga a mano con su recibo.
 *  - no: no calcula ni enseña nada.
 * Quién lo ve en sombra: opción `cvd_programa_ver` = 'todas' o lista de ID de usuario («12,57»); las cuentas
 * de prueba siempre.
 *
 * Libro (append-only):
 *  - {prefix}cvd_programa_runs: cada cálculo (diario, manual o mensual), con el JSON completo y su SHA-256.
 *  - {prefix}cvd_programa_people: una fila por gestora y cálculo (piedra, puntos, red, bono, a pagar, pagado).
 *  - {prefix}cvd_programa_lines: el detalle pedido a pedido de los cálculos mensuales (para devoluciones y recibos).
 */
final class CVD_Programa {
	public const OPTION = 'cvd_programa_gestoras';
	public const VIEW_OPTION = 'cvd_programa_ver';
	private const SCHEMA_VERSION = '1';
	private const SCHEMA_OPTION = 'cvd_programa_schema';
	private const CRON = 'cvd_programa_diario';
	private const PAGE = 'cvd-programa';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'nightly' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_cvd_programa', array( __CLASS__, 'admin_action' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_points' ), 16 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_points' ), 11 );
		add_action( 'wp_head', array( __CLASS__, 'badge_styles' ) );
	}

	/* ================= Interruptor y quién lo ve ================= */

	public static function mode(): string {
		$m = sanitize_key( (string) get_option( self::OPTION, 'sombra' ) );
		return in_array( $m, array( 'sombra', 'si', 'no' ), true ) ? $m : 'sombra';
	}

	public static function visible_for( WP_User $user ): bool {
		$mode = self::mode();
		if ( 'no' === $mode || ! $user->exists() || ! CVD_Registration::is_approved_gestora( $user ) ) { return false; }
		if ( 'si' === $mode ) { return true; }
		if ( '1' === (string) get_user_meta( $user->ID, '_cvd_test_account', true ) ) { return true; }
		$who = trim( (string) get_option( self::VIEW_OPTION, '' ) );
		if ( 'todas' === $who ) { return true; }
		return in_array( $user->ID, array_map( 'absint', preg_split( '/[\s,;]+/', $who ) ?: array() ), true );
	}

	/* ================= Libro (tablas) ================= */

	private static function t( string $name ): string { global $wpdb; return $wpdb->prefix . 'cvd_programa_' . $name; }

	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'runs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			period char(7) NOT NULL,
			kind varchar(10) NOT NULL,
			mode varchar(10) NOT NULL,
			status varchar(12) NOT NULL,
			run_date date NOT NULL,
			computed_at datetime NOT NULL,
			computed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			rate_cup decimal(10,2) NOT NULL DEFAULT 0,
			totals longtext NOT NULL,
			payload longtext NOT NULL,
			payload_sha256 char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY period_kind (period,kind,id),
			KEY run_date (run_date,kind)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'people' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			period char(7) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			stone varchar(20) NOT NULL DEFAULT '',
			title varchar(20) NOT NULL DEFAULT '',
			own_points decimal(12,4) NOT NULL DEFAULT 0,
			team_points decimal(12,4) NOT NULL DEFAULT 0,
			red_usd decimal(12,4) NOT NULL DEFAULT 0,
			fund_usd decimal(12,4) NOT NULL DEFAULT 0,
			bonus_cup int NOT NULL DEFAULT 0,
			pay_red_usd decimal(12,2) NOT NULL DEFAULT 0,
			pay_bonus_cup int NOT NULL DEFAULT 0,
			coverage decimal(6,4) NOT NULL DEFAULT 0,
			paid_at datetime NULL,
			paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
			paid_ref varchar(190) NOT NULL DEFAULT '',
			receipt_sha256 char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY run_user (run_id,user_id),
			KEY user_period (user_id,period)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'lines' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			period char(7) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(20) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			base_usd decimal(12,4) NOT NULL DEFAULT 0,
			cost_usd decimal(12,4) NOT NULL DEFAULT 0,
			commission_usd decimal(12,4) NOT NULL DEFAULT 0,
			points decimal(12,4) NOT NULL DEFAULT 0,
			pct decimal(8,4) NOT NULL DEFAULT 0,
			amount_usd decimal(12,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY run (run_id),
			KEY order_kind (order_id,kind),
			KEY user_period (user_id,period)
		) {$c};" );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/* ================= Cada noche ================= */

	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON ) ) { return; }
		// 03:30 de Cuba: los pedidos del día ya están cerrados y nadie está usando la web.
		$tz = wp_timezone();
		$next = new DateTimeImmutable( 'tomorrow 03:30', $tz );
		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON );
	}

	/** Cálculo diario del mes en curso y, del día 1 al 5, el cierre del mes anterior si aún no existe. */
	public static function nightly(): void {
		if ( 'no' === self::mode() ) { return; }
		$today = wp_date( 'Y-m-d' );
		global $wpdb;
		$done = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'runs' ) . " WHERE run_date=%s AND kind='diario'", $today ) );
		if ( ! $done ) { self::run( wp_date( 'Y-m' ), 'diario', 0 ); }
		if ( (int) wp_date( 'j' ) <= 5 ) {
			$prev = wp_date( 'Y-m', strtotime( wp_date( 'Y-m-01' ) . ' -1 month' ) );
			if ( ! self::monthly_run( $prev ) ) { self::run( $prev, 'mensual', 0 ); }
		}
	}

	/* ================= Leer WooCommerce ================= */

	/** Costo unitario en USD del producto vendido (variación → producto padre). null = sin costo. */
	public static function unit_cost( int $product_id, int $variation_id = 0 ): ?float {
		$cost = null;
		foreach ( array_filter( array( $variation_id, $product_id ) ) as $id ) {
			$raw = get_post_meta( $id, '_cvd_cost_usd', true );
			if ( '' !== (string) $raw && is_numeric( $raw ) && (float) $raw > 0 ) { $cost = (float) $raw; break; }
		}
		if ( null === $cost && $product_id ) {
			$parent = (int) wp_get_post_parent_id( $product_id );
			$raw = $parent ? get_post_meta( $parent, '_cvd_cost_usd', true ) : '';
			if ( '' !== (string) $raw && is_numeric( $raw ) && (float) $raw > 0 ) { $cost = (float) $raw; }
		}
		/** Filtro por si el costo se guarda en otro sitio (p. ej. bajo pedido, supplier_price). */
		$cost = apply_filters( 'cvd_programa_unit_cost', $cost, $product_id, $variation_id );
		return null === $cost ? null : (float) $cost;
	}

	private static function local( string $utc ): string {
		return '' === $utc ? '' : wp_date( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) );
	}

	private static function members(): array {
		$out = array();
		foreach ( get_users( array( 'role' => 'cvd_gestora', 'number' => 2000 ) ) as $u ) {
			$test = '1' === (string) get_user_meta( $u->ID, '_cvd_test_account', true );
			$out[ $u->ID ] = array(
				'name' => (string) $u->display_name,
				'inviter' => absint( get_user_meta( $u->ID, '_cvd_invited_by', true ) ),
				'joined' => self::local( (string) $u->user_registered ),
				'approved' => ! $test && CVD_Registration::is_approved_gestora( $u ),
				'test' => $test,
			);
		}
		return $out;
	}

	/** Pedidos de gestoras que pueden caer en el mes (los que se tocaron desde que empezó). */
	private static function sales( string $period, array $members, array $closed_orders ): array {
		$start = strtotime( $period . '-01 00:00:00 ' . wp_timezone_string() );
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array( 'completed', 'processing', 'on-hold', 'cancelled', 'refunded', 'failed' ), 'date_modified' => '>=' . $start ) );
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
			if ( ! $owner || ! isset( $members[ $owner ] ) || 'organic' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { continue; }
			if ( isset( $closed_orders[ $order->get_id() ] ) ) { continue; } // ya contado en un mes cerrado
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { $status = 'cancelled'; }
			$completed = $order->get_date_completed();
			$at = CVD_Programa_Calc::approved_at( $order->get_meta( '_cvd_commission_history', true ), (string) $order->get_meta( '_cvd_cash_verified_at', true ), $completed ? gmdate( 'Y-m-d H:i:s', $completed->getTimestamp() ) : '' );
			$lines = array();
			$breakdown = $order->get_meta( '_cvd_commission_breakdown', true );
			foreach ( is_array( $breakdown ) ? $breakdown : array() as $b ) {
				$pid = absint( $b['product_id'] ?? 0 ); $vid = absint( $b['variation_id'] ?? 0 );
				$lines[] = array(
					'product_id' => $vid ?: $pid,
					'name' => (string) get_the_title( $vid ?: $pid ),
					'qty' => max( 1, (int) ( $b['quantity'] ?? 1 ) ),
					'base' => (float) ( $b['base_amount'] ?? 0 ),
					'cost_unit' => self::unit_cost( $pid, $vid ),
					'commission' => (float) ( $b['base_commission'] ?? 0 ),
				);
			}
			$test = ( class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || 'test' === CVD_Delivery::blocks_dispatch( $order ) ) ) || ! empty( $members[ $owner ]['test'] );
			$out[] = array(
				'order_id' => $order->get_id(), 'owner' => $owner, 'at' => self::local( $at ), 'status' => $status,
				'self_order' => 'self_order' === (string) $order->get_meta( '_cvd_commission_risk', true ),
				'test' => $test, 'lines' => $lines,
			);
		}
		return $out;
	}

	/** Pedidos contados en meses cerrados: id => true. */
	private static function closed_orders(): array {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT DISTINCT l.order_id FROM ' . self::t( 'lines' ) . ' l JOIN ' . self::t( 'runs' ) . " r ON r.id=l.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','pagado') AND l.kind='venta'" );
		return array_fill_keys( array_map( 'absint', (array) $ids ), true );
	}

	/** Pedidos de meses cerrados que después se anularon o devolvieron y aún no se restaron. */
	private static function reversals( array $closed_orders ): array {
		if ( ! $closed_orders ) { return array(); }
		global $wpdb;
		$done = array_fill_keys( array_map( 'absint', (array) $wpdb->get_col( 'SELECT DISTINCT l.order_id FROM ' . self::t( 'lines' ) . ' l JOIN ' . self::t( 'runs' ) . " r ON r.id=l.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','pagado') AND l.kind='devolucion'" ) ), true );
		$out = array();
		foreach ( array_keys( $closed_orders ) as $order_id ) {
			if ( isset( $done[ $order_id ] ) ) { continue; }
			$order = wc_get_order( $order_id );
			$gone = ! $order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) || 'cancelled' === sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) );
			if ( ! $gone ) { continue; }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.* FROM ' . self::t( 'lines' ) . ' l JOIN ' . self::t( 'runs' ) . " r ON r.id=l.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','pagado') AND l.order_id=%d AND l.kind IN ('venta','red1','red2')", $order_id ), ARRAY_A );
			$rev = array( 'order_id' => $order_id, 'owner' => 0, 'points' => 0, 'base' => 0, 'red' => array() );
			foreach ( (array) $rows as $l ) {
				if ( 'venta' === $l['kind'] ) { $rev['owner'] = absint( $l['user_id'] ); $rev['points'] += (float) $l['points']; $rev['base'] += (float) $l['base_usd']; }
				else { $rev['red'][] = array( 'user' => absint( $l['user_id'] ), 'level' => 'red1' === $l['kind'] ? 1 : 2, 'amount' => (float) $l['amount_usd'] ); }
			}
			if ( $rev['owner'] ) { $out[] = $rev; }
		}
		return $out;
	}

	/** Título acumulado: la mejor piedra de los meses cerrados de verdad (los de sombra no cuentan). */
	private static function titles( string $before_period ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.user_id, p.stone FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . " r ON r.id=p.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','pagado') AND p.period<%s AND p.stone<>''", $before_period ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) { $u = absint( $r['user_id'] ); $out[ $u ] = CVD_Programa_Calc::best_stone( $out[ $u ] ?? '', (string) $r['stone'] ); }
		return $out;
	}

	private static function rate(): float {
		$r = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::get() : array();
		$cup = (float) ( $r['cup'] ?? 0 );
		return $cup > 0 ? $cup : (float) get_option( 'cvd_programa_tasa', 785 );
	}

	/** Calcula sin guardar (vista previa). */
	public static function compute( string $period ): array {
		$members = self::members();
		$titles = self::titles( $period );
		foreach ( $members as $id => &$m ) { $m['title'] = $titles[ $id ] ?? ''; }
		unset( $m );
		$closed = self::closed_orders();
		return CVD_Programa_Calc::compute( $period, $members, self::sales( $period, $members, $closed ), self::reversals( $closed ), array( 'rate_cup' => self::rate() ) );
	}

	/** Calcula y lo apunta en el libro. kind = diario | manual | mensual. Devuelve el id del cálculo. */
	public static function run( string $period, string $kind, int $by ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) { return 0; }
		self::maybe_upgrade();
		$mode = self::mode();
		if ( 'mensual' === $kind && self::monthly_run( $period ) ) { return 0; } // un solo cierre por mes, nunca se reescribe
		$result = self::compute( $period );
		$status = 'mensual' === $kind ? ( 'si' === $mode ? 'cerrado' : 'sombra' ) : 'vista';
		$payload = wp_json_encode( $result );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$ok = $wpdb->insert( self::t( 'runs' ), array(
			'period' => $period, 'kind' => $kind, 'mode' => $mode, 'status' => $status, 'run_date' => wp_date( 'Y-m-d' ),
			'computed_at' => current_time( 'mysql', true ), 'computed_by' => $by, 'rate_cup' => (float) $result['params']['rate_cup'],
			'totals' => wp_json_encode( $result['totals'] ), 'payload' => $payload, 'payload_sha256' => hash( 'sha256', (string) $payload ),
		) );
		$run_id = $ok ? (int) $wpdb->insert_id : 0;
		if ( ! $run_id ) { $wpdb->query( 'ROLLBACK' ); return 0; }
		foreach ( $result['people'] as $p ) {
			if ( ! $p['own_points'] && ! $p['team_points'] && ! $p['red_gross'] && ! $p['red_lost'] && '' === $p['title'] ) { continue; } // sin movimiento
			$wpdb->insert( self::t( 'people' ), array(
				'run_id' => $run_id, 'period' => $period, 'user_id' => $p['id'], 'stone' => $p['stone'], 'title' => $p['title'],
				'own_points' => $p['own_points'], 'team_points' => $p['team_points'], 'red_usd' => $p['red_gross'], 'fund_usd' => $p['fund_usd'],
				'bonus_cup' => $p['bonus_cup'], 'pay_red_usd' => $p['red_pay'], 'pay_bonus_cup' => $p['bonus_pay_cup'], 'coverage' => $p['coverage'],
			) );
		}
		if ( 'mensual' === $kind ) {
			foreach ( $result['lines'] as $l ) {
				$wpdb->insert( self::t( 'lines' ), array(
					'run_id' => $run_id, 'period' => $period, 'user_id' => $l['user'], 'kind' => $l['kind'], 'order_id' => $l['order'], 'source_user_id' => $l['source'],
					'base_usd' => $l['base'], 'cost_usd' => $l['cost'], 'commission_usd' => $l['commission'], 'points' => $l['points'], 'pct' => $l['pct'], 'amount_usd' => $l['amount'],
				) );
			}
		}
		$wpdb->query( 'COMMIT' );
		return $run_id;
	}

	private static function monthly_run( string $period ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::t( 'runs' ) . " WHERE period=%s AND kind='mensual' ORDER BY id DESC LIMIT 1", $period ), ARRAY_A );
		return $row ?: null;
	}

	/** Último cálculo guardado (del mes en curso si lo hay). Con el JSON ya decodificado. */
	public static function latest( string $period = '' ): ?array {
		global $wpdb;
		self::maybe_upgrade();
		$period = $period ?: wp_date( 'Y-m' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'runs' ) . " WHERE period=%s AND kind IN ('diario','manual') ORDER BY id DESC LIMIT 1", $period ), ARRAY_A );
		if ( ! $row ) { $row = $wpdb->get_row( 'SELECT * FROM ' . self::t( 'runs' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); }
		if ( ! $row ) { return null; }
		$row['data'] = json_decode( (string) $row['payload'], true );
		unset( $row['payload'] );
		return is_array( $row['data'] ) ? $row : null;
	}

	/* ================= Puntos en la tienda de la gestora ================= */

	private static function viewer(): ?WP_User {
		static $v = false;
		if ( false === $v ) {
			$u = wp_get_current_user();
			$v = ( $u->exists() && self::visible_for( $u ) ) ? $u : null;
		}
		return $v;
	}

	/** Comisión de una unidad para esta gestora (la misma regla que CVD_Commissions: producto, padre o su %). */
	public static function unit_commission( WC_Product $product, int $user_id, float $price ): float {
		foreach ( array( $product, $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null ) as $p ) {
			if ( ! $p ) { continue; }
			$type = (string) $p->get_meta( '_cvd_commission_type', true ); $value = $p->get_meta( '_cvd_commission_value', true );
			if ( '' !== $type || '' !== (string) $value ) { return 'fixed' === sanitize_key( $type ) ? (float) $value : $price * (float) $value / 100; }
		}
		$rate = get_user_meta( $user_id, '_cvd_commission_rate', true );
		return $price * ( '' !== $rate ? (float) $rate : (float) get_option( 'cvd_default_commission_rate', 13 ) ) / 100;
	}

	/** Puntos de una unidad del producto (para variables, el máximo de sus variaciones). null = sin costo cargado. */
	public static function product_points( WC_Product $product, int $user_id ): ?array {
		$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		$best = null; $all = array();
		foreach ( $items as $p ) {
			$price = (float) get_post_meta( $p->get_id(), '_price', true ); // precio Casa Viva, sin el de la gestora
			if ( $price <= 0 ) { continue; }
			$pts = CVD_Programa_Calc::unit_points( $price, self::unit_cost( $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id(), $p->is_type( 'variation' ) ? $p->get_id() : 0 ), self::unit_commission( $p, $user_id, $price ) );
			if ( null === $pts ) { continue; }
			$all[] = $pts; $best = max( (int) $best, $pts );
		}
		return null === $best ? null : array( 'points' => $best, 'range' => count( array_unique( $all ) ) > 1 );
	}

	private static function badge( WC_Product $product, string $class ): string {
		$user = self::viewer();
		if ( ! $user ) { return ''; }
		$pts = self::product_points( $product, $user->ID );
		if ( null === $pts ) { return ''; }
		$label = ( $pts['range'] ? 'hasta ' : '' ) . $pts['points'] . ( 1 === $pts['points'] ? ' punto' : ' puntos' );
		return '<p class="cvd-pts ' . esc_attr( $class ) . ( 0 === $pts['points'] ? ' is-zero' : '' ) . '" title="Puntos para tu piedra: la ganancia limpia que deja a Casa Viva. Solo lo ves tú.">⭐ ' . esc_html( $label ) . '</p>';
	}

	public static function loop_points(): void {
		global $product;
		if ( $product instanceof WC_Product ) { echo self::badge( $product, 'is-loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en badge().
		}
	}

	public static function single_points(): void {
		global $product;
		if ( $product instanceof WC_Product ) { echo self::badge( $product, 'is-single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en badge().
		}
	}

	public static function badge_styles(): void {
		if ( ! self::viewer() ) { return; }
		echo '<style id="cvd-programa-pts">.cvd-pts{display:inline-block;margin:4px 0 0;padding:2px 9px;border-radius:99px;background:#fbf3e2;color:#7a5512;border:1px solid #ecd9b0;font-size:12.5px;font-weight:600;line-height:1.5}.cvd-pts.is-zero{background:#f2efe9;color:#6f6a62;border-color:#e3ddd3;font-weight:500}.cvd-pts.is-single{font-size:14px;margin:6px 0 10px}</style>';
	}

	/* ================= Pantallas de la gestora ================= */

	private static function me_in_latest( WP_User $user ): array {
		$run = self::latest();
		$data = $run['data'] ?? null;
		$me = $data['people'][ $user->ID ] ?? $data['people'][ (string) $user->ID ] ?? null;
		return array( $run, $data, $me );
	}

	private static function shadow_note(): string {
		return 'si' === self::mode() ? '' : '<p class="cvga-prog-shadow" role="note"><b>En prueba:</b> así se calcularía; todavía no se paga.</p>';
	}

	private static function when_note( ?array $run ): string {
		if ( ! $run ) { return '<p class="cvga-note">Se calcula cada noche. Mañana verás aquí tus números.</p>'; }
		$ts = strtotime( $run['computed_at'] . ' UTC' );
		$day = wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d' ) ? 'hoy' : ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ? 'ayer' : wp_date( 'j/n', $ts ) );
		return '<p class="cvga-note">Calculado ' . esc_html( $day . ' a las ' . wp_date( 'H:i', $ts ) ) . ' con las ventas entregadas y cobradas de este mes. Se actualiza cada noche.</p>';
	}

	private static function usd( $v ): string { return number_format( (float) $v, 2, ',', '.' ) . ' USD'; }
	private static function cup( $v ): string { return number_format( (int) $v, 0, ',', '.' ) . ' CUP'; }
	private static function pts( $v ): string { $n = (float) $v; return number_format( $n, $n == floor( $n ) ? 0 : 1, ',', '.' ); } // phpcs:ignore Universal.Operators.StrictComparisons

	private static function stone_chip( string $slug, string $empty = 'Sin piedra' ): string {
		$s = CVD_Programa_Calc::stone( $slug );
		return '<span class="cvga-gem" data-gem="' . esc_attr( $slug ?: 'none' ) . '">' . esc_html( $s ? $s['name'] : $empty ) . '</span>';
	}

	public static function render_equipo( WP_User $user ): string {
		list( $run, $data, $me ) = self::me_in_latest( $user );
		$people = $data['people'] ?? array();
		$invite = CVD_Registration::invite_url( $user );
		$l1 = $me['level1'] ?? array();
		$l2 = $me['level2'] ?? array();
		// Lo que deja cada invitada este mes (2 % de sus ventas y 1 % de las de sus invitadas).
		$from = array();
		foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
			if ( (int) $l['user'] !== $user->ID || ! in_array( $l['kind'], array( 'red1', 'red2' ), true ) ) { continue; }
			$src = (int) $l['source'];
			$key = 'red1' === $l['kind'] ? $src : (int) ( $people[ $src ]['inviter'] ?? $people[ (string) $src ]['inviter'] ?? 0 );
			$from[ $key ] = ( $from[ $key ] ?? 0 ) + (float) $l['amount'];
		}
		$rate = (float) ( $data['params']['rate_cup'] ?? 0 );
		ob_start();
		?>
		<section class="cvga-view" data-view="equipo" id="cvga-equipo">
			<div class="cvga-head"><h1>Mi equipo</h1><p>Las gestoras que invitaste y lo que te dejan este mes.</p></div>
			<?php echo self::shadow_note(); // phpcs:ignore ?>
			<div class="cvga-kpis cvga-kpis-3">
				<article class="cvga-card cvga-kpi"><small>Te dejan este mes</small><strong><?php echo esc_html( self::usd( $me['red_pay'] ?? 0 ) ); ?></strong><span><?php echo $rate && ! empty( $me['red_pay'] ) ? esc_html( '≈ ' . self::cup( round( $me['red_pay'] * $rate ) ) ) : 'Ventas entregadas y cobradas'; ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Invitadas</small><strong><?php echo esc_html( (string) count( $l1 ) ); ?></strong><span>Te dan el 2 %</span></article>
				<article class="cvga-card cvga-kpi"><small>Sus invitadas</small><strong><?php echo esc_html( (string) count( $l2 ) ); ?></strong><span>Te dan el 1 % (desde Plata)</span></article>
			</div>
			<?php if ( $me && ! empty( $me['red_lost'] ) ) : ?>
			<p class="cvga-warn"><?php echo esc_html( $me['red_lost_reason'] . ': este mes se quedan sin cobrar ' . self::usd( $me['red_lost'] ) . '.' ); ?><?php if ( 'Sin venta propia entregada este mes' === $me['red_lost_reason'] ) : ?> Entrega al menos 1 venta tuya este mes y lo cobras.<?php endif; ?></p>
			<?php endif; ?>
			<?php if ( $me && ! empty( $me['red_gross'] ) && ( $me['red_pay'] + 0.005 ) < $me['red_gross'] ) : ?>
			<p class="cvga-note">El fondo del mes cubre <?php echo esc_html( self::usd( $me['red_pay'] ) ); ?> de <?php echo esc_html( self::usd( $me['red_gross'] ) ); ?>: lo de la red sale del 15 % de la ganancia que deja tu equipo.</p>
			<?php endif; ?>
			<section class="cvga-card">
				<h2>Tus invitadas</h2>
				<?php if ( ! $l1 ) : ?>
				<p class="cvga-empty">Todavía no has invitado a nadie. Cuando una gestora se registre con tu enlace, saldrá aquí.</p>
				<?php else : ?>
				<ul class="cvga-team">
					<?php foreach ( $l1 as $id ) : $p = $people[ $id ] ?? $people[ (string) $id ] ?? array(); $joined = (string) ( $p['joined'] ?? '' ); $left = $joined ? max( 0, 12 - (int) floor( ( time() - strtotime( $joined ) ) / ( 30.44 * DAY_IN_SECONDS ) ) ) : 12; ?>
					<li>
						<span class="cvga-avatar-sm" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( (string) ( $p['name'] ?? '?' ), 0, 1 ) ) ); ?></span>
						<div class="cvga-team-text"><b><?php echo esc_html( (string) ( $p['name'] ?? '' ) ); ?></b><small><?php echo esc_html( ( $joined ? 'Desde ' . wp_date( 'j/n/Y', strtotime( $joined ) ) . ' · ' : '' ) . ( $left ? 'te da el 2 % ' . $left . ( 1 === $left ? ' mes más' : ' meses más' ) : 'ya pasaron los 12 meses' ) ); ?></small></div>
						<div class="cvga-team-end"><?php echo self::stone_chip( (string) ( $p['title'] ?? '' ), 'Empezando' ); // phpcs:ignore ?><b><?php echo esc_html( self::usd( $from[ $id ] ?? 0 ) ); ?></b></div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $l2 ) : ?><p class="cvga-note">Y <?php echo esc_html( count( $l2 ) . ( 1 === count( $l2 ) ? ' gestora invitada' : ' gestoras invitadas' ) ); ?> por ellas (2.º nivel), que te dan el 1 % si eres Plata ese mes. Va incluido en lo de cada una.</p><?php endif; ?>
				<?php endif; ?>
				<p class="cvga-note">Solo cuentan ventas entregadas y cobradas, sobre el precio de Casa Viva (sin lo que suben ellas ni la mensajería). Lo pone Casa Viva: tus invitadas cobran su comisión completa.</p>
			</section>
			<section class="cvga-card">
				<h2>Invita a otra gestora</h2>
				<p class="cvga-note">Ganas el 2 % de lo que venda durante 12 meses. Por invitar no se cobra nada: solo cuando ella vende y entrega.</p>
				<?php if ( $invite ) : ?>
				<div class="cvga-link-box"><input id="cvga-link-invite" readonly value="<?php echo esc_attr( $invite ); ?>" aria-label="Tu enlace para invitar"><button class="cvga-btn cvga-btn-sm" type="button" data-copy-target="cvga-link-invite">Copiar</button></div>
				<div class="cvga-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Únete como gestora de Casa Viva: vendes sin poner dinero y ganas comisión por cada venta. Regístrate aquí: ' . $invite ) ); ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a></div>
				<?php else : ?><p class="cvga-empty">Tu enlace sale cuando tu cuenta tenga código.</p><?php endif; ?>
			</section>
			<?php echo self::when_note( $run ); // phpcs:ignore ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_piedra( WP_User $user ): string {
		list( $run, $data, $me ) = self::me_in_latest( $user );
		$stone = (string) ( $me['stone'] ?? '' );
		$title = (string) ( $me['title'] ?? '' );
		$next = $me['next'] ?? CVD_Programa_Calc::next_stone( $stone );
		$current = CVD_Programa_Calc::stone( $stone );
		$have = (float) ( $me['next']['have'] ?? 0 );
		$goal = (float) ( $next['points'] ?? 0 );
		ob_start();
		?>
		<section class="cvga-view" data-view="piedra" id="cvga-piedra">
			<div class="cvga-head"><h1>Mi piedra</h1><p>Sube de piedra con la ganancia que dejan tus ventas y las de tu equipo.</p></div>
			<?php echo self::shadow_note(); // phpcs:ignore ?>
			<section class="cvga-card cvga-stone-card">
				<div class="cvga-stone-big" data-gem="<?php echo esc_attr( $title ?: 'none' ); ?>" aria-hidden="true"><?php echo self::gem_svg(); // phpcs:ignore ?></div>
				<div>
					<small>Tu título</small>
					<h2 class="cvga-stone-name"><?php echo esc_html( CVD_Programa_Calc::stone( $title )['name'] ?? 'Todavía sin piedra' ); ?></h2>
					<p class="cvga-note"><?php echo $current ? esc_html( 'Este mes mantienes ' . $current['name'] . '.' ) : 'Este mes todavía no llegas a Bronce.'; ?> El título no se pierde; el bono se cobra el mes que lo mantienes.</p>
				</div>
			</section>
			<div class="cvga-kpis">
				<article class="cvga-card cvga-kpi"><small>Tus puntos este mes</small><strong><?php echo esc_html( self::pts( $me['own_points'] ?? 0 ) ); ?></strong><span><?php echo esc_html( self::usd( $me['own_sales_usd'] ?? 0 ) . ' vendidos y cobrados' ); ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Puntos del equipo</small><strong><?php echo esc_html( self::pts( $me['team_points'] ?? 0 ) ); ?></strong><span>Tú + tus invitadas + las de ellas</span></article>
			</div>
			<?php if ( $next ) : ?>
			<section class="cvga-card">
				<div class="cvga-row"><h2>Para llegar a <?php echo esc_html( $next['name'] ); ?></h2><span class="cvga-chip"><?php echo esc_html( self::pts( $next['points'] ) . ' puntos' ); ?></span></div>
				<span class="cvga-bar cvga-bar-lg" role="img" aria-label="<?php echo esc_attr( 'Llevas ' . self::pts( $have ) . ' de ' . self::pts( $goal ) ); ?>"><i style="width:<?php echo esc_attr( (string) ( $goal > 0 ? min( 100, round( 100 * $have / $goal ) ) : 0 ) ); ?>%"></i></span>
				<p class="cvga-note"><b>Te faltan <?php echo esc_html( self::pts( max( 0, $goal - $have ) ) ); ?> puntos.</b> <?php if ( ! empty( $me['next']['own_usd_missing'] ) ) : ?>Y vender tú <?php echo esc_html( self::usd( $me['next']['own_usd_missing'] ) ); ?> más este mes (mínimo propio de <?php echo esc_html( $next['name'] ); ?>: <?php echo esc_html( self::usd( $next['min_own_usd'] ) ); ?>).<?php endif; ?></p>
				<p class="cvga-note">Cada invitada cuenta como mucho el 60 % de lo que pide la piedra, para que no dependa de una sola persona. Tus ventas cuentan enteras.</p>
			</section>
			<?php endif; ?>
			<section class="cvga-card">
				<h2>Tu bono de este mes</h2>
				<?php if ( ! $current ) : ?>
				<p class="cvga-empty">Llega a Bronce (65 puntos del equipo y 1 venta tuya entregada) y cobras 5.000 CUP ese mes.</p>
				<?php else : $cov = (float) ( $me['coverage'] ?? 0 ); ?>
				<p class="cvga-bonus"><b><?php echo esc_html( self::cup( $me['bonus_pay_cup'] ?? 0 ) ); ?></b><?php echo $cov < 0.999 ? esc_html( ' de ' . self::cup( $current['bonus_cup'] ) ) : ''; ?></p>
				<p class="cvga-note"><?php echo $cov >= 0.999 ? 'El fondo del mes lo cubre entero.' : esc_html( 'El fondo cubre el ' . round( 100 * $cov ) . ' %. Los bonos salen del 15 % de la ganancia que deja el equipo; si no alcanza, todas cobran el mismo %.' ); ?></p>
				<?php endif; ?>
			</section>
			<section class="cvga-card">
				<h2>Cómo se ganan puntos</h2>
				<p class="cvga-note"><b>1 punto = 1 USD de ganancia limpia</b> que deja a Casa Viva una venta entregada y cobrada: precio de Casa Viva menos lo que costó el producto y menos tu comisión. En tu tienda cada producto dice <span class="cvd-pts">⭐ N puntos</span>: vende los que dan más y subes antes. No cuentan las compras para ti misma ni las pruebas; las devoluciones restan.</p>
				<div class="cvga-table-wrap"><table class="cvga-stones"><thead><tr><th>Piedra</th><th>Puntos del equipo al mes</th><th>Bono</th></tr></thead><tbody>
					<?php foreach ( CVD_Programa_Calc::STONES as $s ) : ?>
					<tr<?php echo $s[0] === $stone ? ' class="is-me"' : ''; ?>><td><?php echo self::stone_chip( $s[0] ); // phpcs:ignore ?></td><td><?php echo esc_html( number_format( $s[2], 0, ',', '.' ) ); ?></td><td><?php echo esc_html( self::cup( $s[3] ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table></div>
			</section>
			<?php echo self::when_note( $run ); // phpcs:ignore ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function gem_svg(): string {
		return '<svg viewBox="0 0 48 48" width="56" height="56"><path d="M14 8h20l8 10-18 22L6 18z" fill="currentColor" opacity=".22"/><path d="M14 8h20l8 10-18 22L6 18zM6 18h36M18 8l-4 10 10 22 10-22-4-10" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
	}

	/* ================= Administración (wp-admin → Casa Viva → Programa gestoras) ================= */

	public static function menu(): void {
		add_submenu_page( 'cvd-gestoras', 'Programa de gestoras', 'Programa gestoras', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function admin_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.', 403 ); }
		check_admin_referer( 'cvd_programa' );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$msg = '';
		if ( 'guardar' === $do ) {
			$mode = sanitize_key( (string) ( $_POST['mode'] ?? '' ) );
			if ( in_array( $mode, array( 'sombra', 'si', 'no' ), true ) ) { update_option( self::OPTION, $mode, false ); }
			update_option( self::VIEW_OPTION, sanitize_text_field( wp_unslash( (string) ( $_POST['ver'] ?? '' ) ) ), false );
			$msg = 'guardado';
		} elseif ( 'calcular' === $do ) {
			$msg = self::run( wp_date( 'Y-m' ), 'manual', get_current_user_id() ) ? 'calculado' : 'error';
		} elseif ( 'cerrar' === $do ) {
			$period = sanitize_text_field( (string) ( $_POST['period'] ?? '' ) );
			$msg = $period < wp_date( 'Y-m' ) && self::run( $period, 'mensual', get_current_user_id() ) ? 'cerrado' : 'error';
		} elseif ( 'pagado' === $do ) {
			$msg = self::mark_paid( absint( $_POST['row'] ?? 0 ), sanitize_text_field( wp_unslash( (string) ( $_POST['ref'] ?? '' ) ) ) ) ? 'pagado' : 'error';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Anota el pago de una gestora en un mes cerrado (solo con el programa en «si»), con el hash de su recibo. */
	public static function mark_paid( int $row_id, string $ref ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT p.*, r.status AS run_status, r.payload FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . ' r ON r.id=p.run_id WHERE p.id=%d', $row_id ), ARRAY_A );
		if ( ! $row || 'cerrado' !== $row['run_status'] || $row['paid_at'] || '' === $ref ) { return false; }
		$data = json_decode( (string) $row['payload'], true );
		$person = $data['people'][ $row['user_id'] ] ?? null;
		if ( ! $person ) { return false; }
		$receipt = CVD_Programa_Calc::receipt( $person, (string) $row['period'], 'si', 'Pagado: ' . $ref );
		return (bool) $wpdb->update( self::t( 'people' ), array( 'paid_at' => current_time( 'mysql', true ), 'paid_by' => get_current_user_id(), 'paid_ref' => $ref, 'receipt_sha256' => hash( 'sha256', $receipt ) ), array( 'id' => $row_id, 'paid_at' => null ) );
	}

	/** Resumen para el panel y la página de administración. */
	public static function report( int $people_limit = 200 ): array {
		$run = self::latest();
		$data = $run['data'] ?? array();
		$people = array();
		foreach ( (array) ( $data['people'] ?? array() ) as $p ) {
			if ( ! $p['own_points'] && ! $p['team_points'] && ! $p['red_gross'] && ! $p['red_lost'] && ! $p['level1'] && '' === $p['title'] ) { continue; }
			$people[] = array(
				'id' => $p['id'], 'name' => $p['name'], 'inviter' => $p['inviter'], 'stone' => $p['stone'], 'title' => $p['title'],
				'ownPoints' => round( $p['own_points'], 2 ), 'ownSales' => round( $p['own_sales_usd'], 2 ), 'ownOrders' => $p['own_orders'],
				'teamPoints' => round( $p['team_points'], 2 ), 'level1' => count( $p['level1'] ), 'level2' => count( $p['level2'] ),
				'red' => round( $p['red_gross'], 2 ), 'redLost' => round( $p['red_lost'], 2 ), 'redLostReason' => $p['red_lost_reason'],
				'fund' => round( $p['fund_usd'], 2 ), 'redPay' => $p['red_pay'], 'bonusCup' => $p['bonus_cup'], 'bonusPayCup' => $p['bonus_pay_cup'], 'coverage' => $p['coverage'],
			);
		}
		usort( $people, static fn( $a, $b ) => $b['teamPoints'] <=> $a['teamPoints'] );
		global $wpdb;
		$runs = (array) $wpdb->get_results( 'SELECT id, period, kind, mode, status, computed_at, rate_cup, totals, payload_sha256 FROM ' . self::t( 'runs' ) . ' ORDER BY id DESC LIMIT 15', ARRAY_A );
		foreach ( $runs as &$r ) { $r['totals'] = json_decode( (string) $r['totals'], true ); }
		unset( $r );
		return array(
			'mode' => self::mode(),
			'viewers' => (string) get_option( self::VIEW_OPTION, '' ),
			'run' => $run ? array( 'id' => (int) $run['id'], 'period' => $run['period'], 'kind' => $run['kind'], 'status' => $run['status'], 'computedAt' => $run['computed_at'], 'rateCup' => (float) $run['rate_cup'], 'sha256' => $run['payload_sha256'] ) : null,
			'totals' => $data['totals'] ?? null,
			'people' => array_slice( $people, 0, $people_limit ),
			'alerts' => array_slice( (array) ( $data['alerts'] ?? array() ), 0, 100 ),
			'runs' => $runs,
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		self::maybe_upgrade();
		$rep = self::report();
		$mode = $rep['mode'];
		$msgs = array( 'guardado' => 'Guardado.', 'calculado' => 'Calculado y apuntado en el libro.', 'cerrado' => 'Mes cerrado y apuntado en el libro.', 'pagado' => 'Pago anotado con su recibo.', 'error' => 'No se pudo (revisa que el mes no esté ya cerrado o que falte la referencia).' );
		$msg = sanitize_key( (string) ( $_GET['msg'] ?? '' ) );
		$names = array();
		foreach ( $rep['people'] as $p ) { $names[ $p['id'] ] = $p['name']; }
		echo '<div class="wrap"><h1>Programa de gestoras</h1>';
		if ( isset( $msgs[ $msg ] ) ) { echo '<div class="notice notice-' . ( 'error' === $msg ? 'error' : 'success' ) . '"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>'; }
		echo '<p>Red 2 % + 1 %, piedras por ganancia y fondo del 15 % (decisiones D53–D58). <strong>Modo: ' . esc_html( array( 'sombra' => 'SOMBRA (calcula y enseña, no paga)', 'si' => 'SÍ (se paga con recibo)', 'no' => 'APAGADO' )[ $mode ] ) . '</strong></p>';
		$form = static function ( string $do, string $inner, string $confirm = '' ): string {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '><input type="hidden" name="action" value="cvd_programa"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . wp_nonce_field( 'cvd_programa', '_wpnonce', true, false ) . $inner . '</form>';
		};
		echo '<h2>Interruptor</h2>' . $form( 'guardar', '<select name="mode">' . implode( '', array_map( static fn( $m ) => '<option value="' . $m . '"' . selected( $mode, $m, false ) . '>' . array( 'sombra' => 'sombra', 'si' => 'sí', 'no' => 'no' )[ $m ] . '</option>', array( 'sombra', 'si', 'no' ) ) ) . '</select> <label>Lo ven en sombra (IDs separados por coma, o «todas»): <input name="ver" value="' . esc_attr( $rep['viewers'] ) . '" size="30"></label> <button class="button button-primary">Guardar</button>', '¿Cambiar el modo del programa?' ); // phpcs:ignore
		echo '<h2>Calcular</h2>' . $form( 'calcular', '<button class="button">Calcular ahora el mes en curso</button>' ); // phpcs:ignore
		$prev = wp_date( 'Y-m', strtotime( wp_date( 'Y-m-01' ) . ' -1 month' ) );
		echo $form( 'cerrar', '<input type="hidden" name="period" value="' . esc_attr( $prev ) . '"><button class="button">Cerrar ' . esc_html( $prev ) . ' ahora</button>', 'Se cierra ' . $prev . ' una sola vez y no se puede rehacer. ¿Seguir?' ); // phpcs:ignore
		if ( ! $rep['run'] ) { echo '<p>Todavía no hay ningún cálculo. Pulsa «Calcular ahora».</p></div>'; return; }
		$t = $rep['totals'];
		echo '<h2>Último cálculo · ' . esc_html( $rep['run']['period'] . ' · ' . $rep['run']['kind'] . ' · ' . $rep['run']['status'] ) . '</h2>';
		echo '<p>' . esc_html( self::local( $rep['run']['computedAt'] ) . ' (hora de Cuba) · tasa ' . $rep['run']['rateCup'] . ' · huella ' . substr( $rep['run']['sha256'], 0, 16 ) . '…' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( array( 'Ventas de gestoras que cuentan' => self::usd( $t['sales_usd'] ), 'Ganancia limpia (puntos)' => self::pts( $t['profit_usd'] ), 'Fondo del mes (15 %)' => self::usd( $t['pool_usd'] ), 'Red a pagar' => self::usd( $t['red_usd'] ), 'Bonos a pagar' => self::cup( $t['bonus_cup'] ) . ' (≈ ' . self::usd( $t['bonus_usd'] ) . ')', 'Total que se debería pagar' => self::usd( $t['pay_usd'] ), 'Bonos cubiertos' => round( 100 * $t['factor_bonus'] ) . ' %', 'Gestoras con piedra este mes' => (string) $t['people_with_stone'] ) as $k => $v ) {
			echo '<tr><th style="width:280px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $rep['alerts'] ) {
			echo '<h2>Alertas (' . count( $rep['alerts'] ) . ')</h2><ul style="list-style:disc;margin-left:20px">';
			foreach ( $rep['alerts'] as $a ) { echo '<li><strong>' . esc_html( $a['type'] ) . '</strong> · ' . esc_html( $a['text'] ) . '</li>'; }
			echo '</ul>';
		}
		echo '<h2>Gestoras</h2><table class="widefat striped"><thead><tr><th>Gestora</th><th>Invitada por</th><th>Piedra mes / título</th><th>Puntos propios</th><th>Puntos equipo</th><th>Invitadas</th><th>Red</th><th>Fondo equipo</th><th>Red a pagar</th><th>Bono</th></tr></thead><tbody>';
		foreach ( $rep['people'] as $p ) {
			$st = CVD_Programa_Calc::stone( $p['stone'] ); $ti = CVD_Programa_Calc::stone( $p['title'] );
			echo '<tr><td>' . esc_html( $p['name'] . ' #' . $p['id'] ) . '</td><td>' . esc_html( $p['inviter'] ? ( $names[ $p['inviter'] ] ?? '#' . $p['inviter'] ) : '—' ) . '</td><td>' . esc_html( ( $st['name'] ?? '—' ) . ' / ' . ( $ti['name'] ?? '—' ) ) . '</td><td>' . esc_html( self::pts( $p['ownPoints'] ) . ' (' . self::usd( $p['ownSales'] ) . ', ' . $p['ownOrders'] . ' ped.)' ) . '</td><td>' . esc_html( self::pts( $p['teamPoints'] ) ) . '</td><td>' . esc_html( $p['level1'] . ' + ' . $p['level2'] ) . '</td><td>' . esc_html( self::usd( $p['red'] ) . ( $p['redLost'] ? ' · pierde ' . self::usd( $p['redLost'] ) : '' ) ) . '</td><td>' . esc_html( self::usd( $p['fund'] ) ) . '</td><td><strong>' . esc_html( self::usd( $p['redPay'] ) ) . '</strong></td><td><strong>' . esc_html( self::cup( $p['bonusPayCup'] ) ) . '</strong>' . ( $p['bonusCup'] && $p['coverage'] < 0.999 ? esc_html( ' de ' . self::cup( $p['bonusCup'] ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::page_payables();
		echo '<h2>Libro (últimos cálculos)</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>#</th><th>Mes</th><th>Tipo</th><th>Modo</th><th>Estado</th><th>Cuándo</th><th>A pagar</th><th>Huella SHA-256</th></tr></thead><tbody>';
		foreach ( $rep['runs'] as $r ) { echo '<tr><td>' . esc_html( $r['id'] ) . '</td><td>' . esc_html( $r['period'] ) . '</td><td>' . esc_html( $r['kind'] ) . '</td><td>' . esc_html( $r['mode'] ) . '</td><td>' . esc_html( $r['status'] ) . '</td><td>' . esc_html( self::local( $r['computed_at'] ) ) . '</td><td>' . esc_html( self::usd( $r['totals']['pay_usd'] ?? 0 ) ) . '</td><td><code>' . esc_html( substr( $r['payload_sha256'], 0, 16 ) ) . '…</code></td></tr>'; }
		echo '</tbody></table></div>';
	}

	/** Meses cerrados con algo por pagar: recibo de cada gestora y «Pagado» con referencia. */
	private static function page_payables(): void {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT p.id, p.user_id, p.period, p.pay_red_usd, p.pay_bonus_cup, p.paid_at, p.paid_ref, r.id AS run_id, r.status AS run_status FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . " r ON r.id=p.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','sombra') AND (p.pay_red_usd>0 OR p.pay_bonus_cup>0) ORDER BY p.period DESC, p.id LIMIT 200", ARRAY_A );
		if ( ! $rows ) { return; }
		echo '<h2>Cierres del mes y recibos</h2><table class="widefat striped"><thead><tr><th>Mes</th><th>Gestora</th><th>Red</th><th>Bono</th><th>Recibo</th><th>Pago</th></tr></thead><tbody>';
		$payloads = array();
		foreach ( $rows as $row ) {
			$rid = (int) $row['run_id'];
			if ( ! isset( $payloads[ $rid ] ) ) { $payloads[ $rid ] = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . self::t( 'runs' ) . ' WHERE id=%d', $rid ) ), true ); }
			$person = $payloads[ $rid ]['people'][ $row['user_id'] ] ?? null;
			$shadow = 'sombra' === $row['run_status'];
			$receipt = $person ? CVD_Programa_Calc::receipt( $person, (string) $row['period'], $shadow ? 'sombra' : 'si' ) : '';
			echo '<tr><td>' . esc_html( $row['period'] ) . '</td><td>' . esc_html( (string) ( $person['name'] ?? '#' . $row['user_id'] ) ) . '</td><td>' . esc_html( self::usd( $row['pay_red_usd'] ) ) . '</td><td>' . esc_html( self::cup( $row['pay_bonus_cup'] ) ) . '</td><td><details><summary>Ver</summary><pre style="white-space:pre-wrap">' . esc_html( $receipt ) . '</pre></details></td><td>';
			if ( $row['paid_at'] ) { echo esc_html( 'Pagado ' . self::local( $row['paid_at'] ) . ' · ' . $row['paid_ref'] ); }
			elseif ( $shadow ) { echo 'Prueba: no se paga'; }
			else { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(&quot;¿Ya se le pagó?&quot;)"><input type="hidden" name="action" value="cvd_programa"><input type="hidden" name="do" value="pagado"><input type="hidden" name="row" value="' . esc_attr( $row['id'] ) . '">' . wp_nonce_field( 'cvd_programa', '_wpnonce', true, false ) . '<input name="ref" placeholder="Transfermóvil, efectivo…" required size="18"> <button class="button">Pagado</button></form>'; } // phpcs:ignore
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ================= Panel de Lennys (vía nexo-panel-web) ================= */

	public static function routes(): void {
		if ( ! class_exists( 'CVD_Panel_Bridge' ) ) { return; }
		$auth = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( 'casa-viva/v1', '/panel/programa', array( 'methods' => 'GET', 'callback' => static function () {
			$r = new WP_REST_Response( self::report() ); $r->header( 'Cache-Control', 'no-store, private' ); return $r;
		}, 'permission_callback' => $auth ) );
		register_rest_route( 'casa-viva/v1', '/panel/programa/calcular', array( 'methods' => 'POST', 'callback' => static function () {
			$id = self::run( wp_date( 'Y-m' ), 'manual', absint( get_option( 'cvd_panel_actor_user_id', 0 ) ) );
			return $id ? new WP_REST_Response( self::report() ) : new WP_Error( 'cvd_programa', 'No se pudo calcular.', array( 'status' => 500 ) );
		}, 'permission_callback' => $auth ) );
	}
}
