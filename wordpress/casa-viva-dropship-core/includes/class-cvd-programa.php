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
 * 3.14.29 (encima de 3.14.25): `cvd_programa_gastos_fijos` = no | si (por defecto no = D55: precio − costo − comisión).
 * Con «si» los puntos restan también los gastos fijos de cada producto (`_cvd_fixed_usd`, D74; si no tiene, la opción
 * general de CVD_Costs). Siempre se resta el premio de la dependienta de un «extra en tienda» (regla del 15-oct; hoy 0).
 * Un costo provisional (fórmula o por confirmar, D62) cuenta, con la alerta «provisional».
 * En una venta de proveedor (bajo pedido) se resta el fondo de la operación del reparto (D69). La red y el bono de
 * un cierre en «sí» se cobran por «Pedir mi pago» (CVD_Payouts) como un renglón más (D75, Ernesto 16-oct).
 *
 * Libro (append-only):
 *  - {prefix}cvd_programa_runs: cada cálculo (diario, manual o mensual), con el JSON completo y su SHA-256.
 *  - {prefix}cvd_programa_people: una fila por gestora y cálculo (piedra, puntos, red, bono, a pagar, pagado).
 *  - {prefix}cvd_programa_lines: el detalle pedido a pedido de los cálculos mensuales (para devoluciones y recibos).
 */
final class CVD_Programa {
	public const OPTION = 'cvd_programa_gestoras';
	public const VIEW_OPTION = 'cvd_programa_ver';
	public const FIXED_OPTION = 'cvd_programa_gastos_fijos';
	/** D79: alertas marcadas «Revisada» (clave => ['at' => UTC, 'by' => usuario]) y el mes cuyo cierre automático espera. */
	public const REVIEWED_OPTION = 'cvd_programa_revisadas';
	public const WAIT_OPTION = 'cvd_programa_cierre_espera';
	private const SCHEMA_VERSION = '2'; // 3.14.29: people.payout_id (pago por «Pedir mi pago»)
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
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY run_user (run_id,user_id),
			KEY user_period (user_id,period),
			KEY payout (payout_id)
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
		$prev = wp_date( 'Y-m', strtotime( wp_date( 'Y-m-01' ) . ' -1 month' ) );
		// D79: si el cierre está esperando por alertas sin revisar, se sigue intentando cada noche aunque pase el día 5.
		if ( ( (int) wp_date( 'j' ) <= 5 || (string) get_option( self::WAIT_OPTION, '' ) === $prev ) && ! self::monthly_run( $prev ) ) {
			self::close_when_reviewed( $prev );
		}
	}

	/**
	 * D79 (auditoría 16-oct §4): el cierre automático del mes ESPERA mientras haya alertas «ganancia_alta» o
	 * «cuenta_compartida» sin marcar «Revisada» (panel → Equipo → Programa, o wp-admin). «Cerrar ahora» no espera.
	 * Devuelve el id del cierre, o 0 si espera (y lo apunta en cvd_programa_cierre_espera).
	 */
	public static function close_when_reviewed( string $period ): int {
		$pending = CVD_Programa_Calc::pending_review( (array) ( self::compute( $period )['alerts'] ?? array() ), self::reviewed() );
		if ( $pending ) { update_option( self::WAIT_OPTION, $period, false ); return 0; }
		$id = self::run( $period, 'mensual', 0 );
		if ( $id || self::monthly_run( $period ) ) { delete_option( self::WAIT_OPTION ); }
		return $id;
	}

	/** D79: alertas marcadas «Revisada». */
	public static function reviewed(): array {
		$r = get_option( self::REVIEWED_OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	/** D79: Lennys (o Ernesto) marca una alerta «Revisada». Solo las que hacen esperar al cierre. */
	public static function mark_reviewed( string $key, int $by ): bool {
		if ( ! preg_match( '/^(ganancia_alta|cuenta_compartida):[0-9a-z_:,]{1,120}$/', $key ) ) { return false; }
		$all = self::reviewed();
		$all[ $key ] = array( 'at' => current_time( 'mysql', true ), 'by' => $by );
		if ( count( $all ) > 1000 ) { $all = array_slice( $all, -1000, null, true ); }
		return (bool) update_option( self::REVIEWED_OPTION, $all, false ) || isset( self::reviewed()[ $key ] );
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

	/** 3.14.29: ¿los puntos restan los gastos fijos de cada producto? (decisión de Ernesto; por defecto no, como D55). */
	public static function subtract_fixed(): bool {
		return 'si' === sanitize_key( (string) get_option( self::FIXED_OPTION, 'no' ) );
	}

	/** 3.14.29: gastos fijos de una unidad (los del producto, D74, o los generales de CVD_Costs). 0 si no se restan. */
	public static function unit_fixed( int $product_id, int $variation_id = 0 ): float {
		if ( ! self::subtract_fixed() || ! class_exists( 'CVD_Costs' ) ) { return 0.0; }
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) { return 0.0; }
		$rules = CVD_Costs::rules( CVD_Costs::is_bajo_pedido( $product ), $product, true ); // bajo pedido: sin gastos fijos (D59)
		return max( 0.0, (float) $rules['fixed'] );
	}

	/** 3.14.29: el costo de este producto es provisional (fórmula o por confirmar, D62). */
	public static function cost_provisional( int $product_id, int $variation_id = 0 ): bool {
		if ( ! class_exists( 'CVD_Costs' ) ) { return false; }
		foreach ( array_filter( array( $variation_id, $product_id ) ) as $id ) {
			if ( '' !== (string) get_post_meta( $id, '_cvd_cost_usd', true ) ) { return in_array( (string) get_post_meta( $id, '_cvd_cost_source', true ), CVD_Costs::PROVISIONAL, true ); }
		}
		return false;
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
		return self::with_identities( $out );
	}

	/**
	 * D79: teléfono (8 últimas cifras), tarjeta o cuenta de cobro y direcciones de entrega de las gestoras que tienen
	 * madrina o invitadas (solo ellas: hoy son pocas). Sirve para la alerta «cuenta_compartida»; no se guarda en el libro.
	 */
	private static function with_identities( array $members ): array {
		$involved = array();
		foreach ( $members as $id => $m ) {
			$inv = (int) ( $m['inviter'] ?? 0 );
			if ( ! $inv || $inv === (int) $id || ! isset( $members[ $inv ] ) ) { continue; }
			$involved[ (int) $id ] = true; $involved[ $inv ] = true;
			$inv2 = (int) ( $members[ $inv ]['inviter'] ?? 0 );
			if ( $inv2 && isset( $members[ $inv2 ] ) ) { $involved[ $inv2 ] = true; }
		}
		foreach ( array_keys( $involved ) as $id ) { $members[ $id ]['ids'] = self::identities( $id ); }
		return $members;
	}

	/** D79: datos de una gestora para compararlos con los de su madrina. Claves normalizadas => texto corto para la alerta. */
	private static function identities( int $user_id ): array {
		$ids = array( 'telefono' => array(), 'tarjeta' => array(), 'direccion' => array() );
		$dec = static fn( string $v ): string => ( '' !== $v && class_exists( 'CVD_Payouts' ) ) ? CVD_Payouts::decrypt( $v ) : '';
		$add_phone = static function ( string $raw ) use ( &$ids ): void {
			$k = CVD_Programa_Calc::phone_key( $raw );
			if ( '' !== $k ) { $ids['telefono'][ $k ] = '…' . substr( $k, -4 ); }
		};
		$add_account = static function ( string $raw ) use ( &$ids ): void {
			$k = CVD_Programa_Calc::account_key( $raw );
			if ( '' !== $k ) { $ids['tarjeta'][ $k ] = false !== strpos( $k, '@' ) ? $k : '…' . substr( $k, -4 ); }
		};
		$add_phone( (string) get_user_meta( $user_id, '_cvd_whatsapp', true ) );
		$add_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );
		$add_phone( $dec( (string) get_user_meta( $user_id, '_cvd_payout_phone', true ) ) );
		$add_account( $dec( (string) get_user_meta( $user_id, '_cvd_payout_account', true ) ) );
		// Pagos de comisiones, los nuevos («Pedir mi pago») y los de antes: la tarjeta o cuenta y el teléfono con que cobró.
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT account_value, phone_value FROM {$wpdb->prefix}cvd_payouts WHERE owner_user_id=%d ORDER BY id DESC LIMIT 100", $user_id ), ARRAY_A );
		foreach ( $rows as $r ) {
			$add_account( $dec( (string) ( $r['account_value'] ?? '' ) ) );
			$add_phone( $dec( (string) ( $r['phone_value'] ?? '' ) ) );
		}
		// Direcciones de entrega de sus pedidos del último año (sin las de recoger en la tienda).
		$store = CVD_Programa_Calc::address_key( (string) get_option( 'woocommerce_store_address', '' ), (string) get_option( 'woocommerce_store_city', '' ) );
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'type' => 'shop_order', 'limit' => 300, 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user_id, 'date_created' => '>' . ( time() - 365 * DAY_IN_SECONDS ) ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( (array) $orders as $o ) {
			if ( ! $o instanceof WC_Order || absint( $o->get_meta( '_cvd_owner_user_id', true ) ) !== $user_id ) { continue; }
			$addr = trim( (string) $o->get_shipping_address_1() ) ? array( $o->get_shipping_address_1(), $o->get_shipping_city() ) : array( $o->get_billing_address_1(), $o->get_billing_city() );
			$k = CVD_Programa_Calc::address_key( (string) $addr[0], (string) $addr[1] );
			if ( '' === $k || $k === $store ) { continue; }
			$label = trim( (string) $addr[0] );
			$ids['direccion'][ $k ] = ( function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 40 ) : substr( $label, 0, 40 ) ) . ( '' !== trim( (string) $addr[1] ) ? ', ' . trim( (string) $addr[1] ) : '' );
		}
		return $ids;
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
			$refunded = self::refunded_qty( $order ); // 3.14.30 (D77): reembolsos de una parte del pedido
			foreach ( is_array( $breakdown ) ? $breakdown : array() as $b ) {
				$pid = absint( $b['product_id'] ?? 0 ); $vid = absint( $b['variation_id'] ?? 0 );
				$qty = max( 1, (int) ( $b['quantity'] ?? 1 ) );
				$keep = 1.0; $k = $pid . ':' . $vid; $r = min( $qty, (int) ( $refunded[ $k ] ?? 0 ) );
				if ( $r > 0 ) {
					$refunded[ $k ] -= $r; $keep = ( $qty - $r ) / $qty; $qty -= $r;
					if ( $qty <= 0 ) { continue; } // línea devuelta entera: no cuenta
					$b['base_amount'] = (float) ( $b['base_amount'] ?? 0 ) * $keep; $b['base_commission'] = (float) ( $b['base_commission'] ?? 0 ) * $keep;
				}
				// 3.14.29: premio de la dependienta de un «extra en tienda» (foto de la comisión, 3.14.19) + gastos fijos si se restan.
				$staff = $keep * ( is_array( $b['extra'] ?? null ) ? max( 0.0, (float) ( $b['extra']['staff'] ?? 0 ) ) : 0.0 );
				$prod = class_exists( 'CVD_Costs' ) ? wc_get_product( $vid ?: $pid ) : null;
				$lines[] = array(
					'bajo_pedido' => $prod && CVD_Costs::is_bajo_pedido( $prod ),
					'product_id' => $vid ?: $pid,
					'name' => html_entity_decode( (string) get_the_title( $vid ?: $pid ), ENT_QUOTES, 'UTF-8' ),
					'qty' => $qty,
					'base' => (float) ( $b['base_amount'] ?? 0 ),
					'cost_unit' => self::unit_cost( $pid, $vid ),
					'commission' => (float) ( $b['base_commission'] ?? 0 ),
					'other' => round( $staff + self::unit_fixed( $pid, $vid ) * $qty, 4 ),
					'cost_provisional' => self::cost_provisional( $pid, $vid ),
				);
			}
			self::spread_supplier_fund( $order, $lines );
			$test = ( class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || 'test' === CVD_Delivery::blocks_dispatch( $order ) ) ) || ! empty( $members[ $owner ]['test'] );
			// Autocompra: el nombre de quien compra o recibe es el de la gestora (la marca self_order de Core sola no basta).
			$buyers = array( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ), (string) $order->get_meta( 'cliente_vinculado', true ) );
			$risk = (string) $order->get_meta( '_cvd_commission_risk', true );
			// 3.14.47 (Ernesto 22-oct: «entran si venden como gestora, no como dependienta»): los pedidos del equipo de la
			// tienda anteriores a 3.14.45 no llevan staff_self/staff_owner (Zaymi #4222 «Tienda» daba puntos). Se mira ahora.
			if ( class_exists( 'CVD_Commissions' ) && CVD_Commissions::is_staff( $owner ) && ! in_array( $risk, array( 'staff_self', 'staff_owner' ), true ) ) {
				$risk = '' !== CVD_Commissions::staff_zero_reason( $order, $owner ) ? 'staff_self' : 'staff_owner';
			}
			// 3.14.45: compra del equipo/tienda (staff_self) = autocompra; venta del equipo a un cliente (staff_owner) = alerta «equipo».
			// 3.14.53 («Vender seguro»): «Es para mí» al hacer el pedido (_cvd_self_declared) = autocompra, sin dudar.
			$declared = 'yes' === (string) $order->get_meta( '_cvd_self_declared', true );
			$self = 'staff_self' === $risk || $declared ? 'si' : CVD_Programa_Calc::self_purchase( (string) $members[ $owner ]['name'], $buyers, 'self_order' === $risk );
			$out[] = array(
				'order_id' => $order->get_id(), 'owner' => $owner, 'at' => self::local( $at ), 'status' => $status,
				'buyer_names' => $buyers, // auditoría 16-oct: la madrina que compra a través de su invitada no cobra red de ese pedido
				'self_order' => 'si' === $self, 'self_check' => 'revisar' === $self,
				'staff_self' => 'staff_self' === $risk, 'staff_check' => 'staff_owner' === $risk,
				'test' => $test, 'lines' => $lines,
			) + self::owner_share_field( $order, $owner, $lines );
		}
		return $out;
	}

	/**
	 * 3.14.49 (Ernesto 22-oct): venta con líneas de proveedor (MK-) de OTRA gestora → la parte del dueño del reparto
	 * (specialCup / tasa del reparto, lo sellado si está cerrado), que es lo que suma a SUS puntos (no el margen entero).
	 * Sin cuenta del dueño, venta suya o sin líneas MK-: nada. Sin tasa: 0.
	 */
	private static function owner_share_field( WC_Order $order, int $owner, array $lines ): array {
		$og = class_exists( 'CVD_Supplier_Split' ) ? CVD_Supplier_Split::owner_gestor_user() : 0;
		if ( ! $og || $owner === $og || ! array_filter( $lines, static fn( $l ) => ! empty( $l['bajo_pedido'] ) ) ) { return array(); }
		$saved = CVD_Supplier_Split::saved( $order );
		$s = is_array( $saved['snapshot'] ?? null ) ? $saved['snapshot'] : CVD_Supplier_Split::compute( $order );
		$rate = (float) ( $s['rate'] ?? 0 );
		return array( 'owner_share_usd' => $rate > 0 ? round( max( 0, (int) ( $s['specialCup'] ?? 0 ) ) / $rate, 4 ) : 0.0 );
	}

	/**
	 * 3.14.29 (Ernesto, 16-oct, D75): en una venta de proveedor (bajo pedido, D66/D69) el fondo de la operación
	 * (mensajero + dependienta + tienda, ~3.000 CUP) sale del margen: se resta de los puntos de esas líneas,
	 * repartido según su precio. Usa el reparto sellado al cerrar el pedido (o el calculado si aún no se selló),
	 * en USD con la tasa de ese reparto. Sin tasa o sin fondo: no cambia nada.
	 */
	private static function spread_supplier_fund( WC_Order $order, array &$lines ): void {
		if ( ! class_exists( 'CVD_Supplier_Split' ) ) { return; }
		$idx = array_keys( array_filter( $lines, static fn( $l ) => ! empty( $l['bajo_pedido'] ) ) );
		if ( ! $idx ) { return; }
		$saved = CVD_Supplier_Split::saved( $order );
		$s = is_array( $saved['snapshot'] ?? null ) ? $saved['snapshot'] : CVD_Supplier_Split::compute( $order );
		$rate = (float) ( $s['rate'] ?? 0 ); $fund = (float) ( $s['fundCup'] ?? 0 );
		if ( $rate <= 0 || $fund <= 0 ) { return; }
		$fund_usd = $fund / $rate;
		$total = array_sum( array_map( static fn( $i ) => (float) $lines[ $i ]['base'], $idx ) );
		foreach ( $idx as $i ) {
			$share = $total > 0 ? (float) $lines[ $i ]['base'] / $total : 1 / count( $idx );
			$lines[ $i ]['other'] = round( (float) $lines[ $i ]['other'] + $fund_usd * $share, 4 );
		}
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
			// 3.14.30 (D77): reembolso de una parte del pedido después del cierre → se resta esa parte (una vez por pedido).
			$share = $gone ? 1.0 : self::refund_share( $order );
			if ( $share <= 0 ) { continue; }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.* FROM ' . self::t( 'lines' ) . ' l JOIN ' . self::t( 'runs' ) . " r ON r.id=l.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','pagado') AND l.order_id=%d AND l.kind IN ('venta','red1','red2','proveedor')", $order_id ), ARRAY_A );
			$rev = array( 'order_id' => $order_id, 'owner' => 0, 'points' => 0, 'base' => 0, 'red' => array(), 'supplier' => array() );
			foreach ( (array) $rows as $l ) {
				if ( 'venta' === $l['kind'] ) { $rev['owner'] = absint( $l['user_id'] ); $rev['points'] += $share * (float) $l['points']; $rev['base'] += $share * (float) $l['base_usd']; }
				elseif ( 'proveedor' === $l['kind'] ) { $rev['supplier'][] = array( 'user' => absint( $l['user_id'] ), 'points' => $share * (float) $l['points'], 'base' => $share * (float) $l['base_usd'] ); } // 3.14.48 (D92)
				else { $rev['red'][] = array( 'user' => absint( $l['user_id'] ), 'level' => 'red1' === $l['kind'] ? 1 : 2, 'amount' => $share * (float) $l['amount_usd'] ); }
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
		return CVD_Programa_Calc::compute( $period, $members, self::sales( $period, $members, $closed ), self::reversals( $closed ), array( 'rate_cup' => self::rate(), 'carry_usd' => self::carry_in( $period ), 'owner_gestor' => class_exists( 'CVD_Supplier_Split' ) ? CVD_Supplier_Split::owner_gestor_user() : 0, 'owner_offweb' => self::offweb_for_calc( $period ) ) );
	}

	/** 3.14.48 (D92): ventas de proveedor fuera de la web del mes, para el cálculo. 3.14.49: sus puntos = su parte (specialUsd). */
	private static function offweb_for_calc( string $period ): array {
		if ( ! class_exists( 'CVD_Supplier_Split' ) || ! CVD_Supplier_Split::owner_gestor_user() ) { return array(); }
		return array_map( static fn( $s ) => array( 'date' => $s['date'], 'points' => max( 0.0, (float) ( $s['specialUsd'] ?? 0 ) ), 'base' => $s['saleUsd'], 'state' => $s['state'] ), CVD_Supplier_Split::offweb_sales( $period ) );
	}

	/**
	 * 3.14.30 (D77): deuda del fondo que deja el último cierre pagable antes de este mes (devoluciones después de pagar
	 * que el mes no pudo restar). Cada cierre ya lleva dentro la deuda de los anteriores, así que basta con el último.
	 * Los cierres en sombra no cuentan: allí no se pagó nada.
	 */
	private static function carry_in( string $period ): float {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT totals FROM ' . self::t( 'runs' ) . " WHERE kind='mensual' AND status IN ('cerrado','pagado') AND period<%s ORDER BY period DESC, id DESC LIMIT 1", $period ), ARRAY_A );
		$t = is_array( $row ) ? json_decode( (string) ( $row['totals'] ?? '' ), true ) : null;
		return is_array( $t ) ? max( 0.0, (float) ( $t['carry_out_usd'] ?? 0 ) ) : 0.0;
	}

	/**
	 * 3.14.30 (D77): unidades reembolsadas de cada artículo («producto:variación» => unidades). Un reembolso de WooCommerce
	 * de solo una parte del pedido (1 de 3 cosas) deja el pedido «completado»: sin esto, lo devuelto seguiría dando puntos y red.
	 */
	private static function refunded_qty( $order ): array {
		if ( ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'get_qty_refunded_for_item' ) ) { return array(); }
		$out = array();
		foreach ( (array) $order->get_items() as $item_id => $item ) {
			$q = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
			if ( $q <= 0 || ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) { continue; }
			$k = absint( $item->get_product_id() ) . ':' . absint( method_exists( $item, 'get_variation_id' ) ? $item->get_variation_id() : 0 );
			$out[ $k ] = ( $out[ $k ] ?? 0 ) + $q;
		}
		return $out;
	}

	/** 3.14.30: parte del precio Casa Viva del pedido que se reembolsó (0 = nada, 1 = todo), según las unidades devueltas. */
	private static function refund_share( $order ): float {
		$refunded = self::refunded_qty( $order );
		if ( ! $refunded ) { return 0.0; }
		$base = 0.0; $back = 0.0;
		foreach ( (array) $order->get_meta( '_cvd_commission_breakdown', true ) as $b ) {
			if ( ! is_array( $b ) ) { continue; }
			$qty = max( 1, (int) ( $b['quantity'] ?? 1 ) ); $lb = (float) ( $b['base_amount'] ?? 0 );
			$k = absint( $b['product_id'] ?? 0 ) . ':' . absint( $b['variation_id'] ?? 0 );
			$r = min( $qty, (int) ( $refunded[ $k ] ?? 0 ) );
			if ( $r > 0 ) { $refunded[ $k ] -= $r; $back += $lb * $r / $qty; }
			$base += $lb;
		}
		return $base > 0 ? min( 1.0, $back / $base ) : 0.0;
	}

	/** Calcula y lo apunta en el libro. kind = diario | manual | mensual. Devuelve el id del cálculo. */
	public static function run( string $period, string $kind, int $by ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) { return 0; }
		self::maybe_upgrade();
		$mode = self::mode();
		global $wpdb;
		// 3.14.30 (D77): un solo cierre a la vez (la tarea de la noche y «Cerrar ahora» no pueden crear dos).
		$lock = 'mensual' === $kind ? 'cvd_programa_cierre_' . str_replace( '-', '', $period ) : '';
		if ( $lock && 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', $lock ) ) ) { return 0; }
		try {
			if ( 'mensual' === $kind && self::monthly_run( $period ) ) { return 0; } // un solo cierre por mes, nunca se reescribe
			return self::run_locked( $period, $kind, $by, $mode );
		} finally {
			if ( $lock ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		}
	}

	/** Calcula y guarda en una transacción: si falla cualquier fila, no se guarda nada (3.14.30, D77). */
	private static function run_locked( string $period, string $kind, int $by, string $mode ): int {
		global $wpdb;
		$result = self::compute( $period );
		$status = 'mensual' === $kind ? ( 'si' === $mode ? 'cerrado' : 'sombra' ) : 'vista';
		$payload = wp_json_encode( $result );
		$wpdb->query( 'START TRANSACTION' );
		$ok = $wpdb->insert( self::t( 'runs' ), array(
			'period' => $period, 'kind' => $kind, 'mode' => $mode, 'status' => $status, 'run_date' => wp_date( 'Y-m-d' ),
			'computed_at' => current_time( 'mysql', true ), 'computed_by' => $by, 'rate_cup' => (float) $result['params']['rate_cup'],
			'totals' => wp_json_encode( $result['totals'] ), 'payload' => $payload, 'payload_sha256' => hash( 'sha256', (string) $payload ),
		) );
		$run_id = $ok ? (int) $wpdb->insert_id : 0;
		if ( ! $run_id ) { $wpdb->query( 'ROLLBACK' ); return 0; }
		$all_ok = true;
		foreach ( $result['people'] as $p ) {
			if ( ! $p['own_points'] && ! $p['team_points'] && ! $p['red_gross'] && ! $p['red_lost'] && '' === $p['title'] ) { continue; } // sin movimiento
			$all_ok = false !== $wpdb->insert( self::t( 'people' ), array(
				'run_id' => $run_id, 'period' => $period, 'user_id' => $p['id'], 'stone' => $p['stone'], 'title' => $p['title'],
				'own_points' => $p['own_points'], 'team_points' => $p['team_points'], 'red_usd' => $p['red_gross'], 'fund_usd' => $p['fund_usd'],
				'bonus_cup' => $p['bonus_cup'], 'pay_red_usd' => $p['red_pay'], 'pay_bonus_cup' => $p['bonus_pay_cup'], 'coverage' => $p['coverage'],
			) ) && $all_ok;
		}
		if ( 'mensual' === $kind ) {
			foreach ( $result['lines'] as $l ) {
				$all_ok = false !== $wpdb->insert( self::t( 'lines' ), array(
					'run_id' => $run_id, 'period' => $period, 'user_id' => $l['user'], 'kind' => $l['kind'], 'order_id' => $l['order'], 'source_user_id' => $l['source'],
					'base_usd' => $l['base'], 'cost_usd' => $l['cost'], 'commission_usd' => $l['commission'], 'points' => $l['points'], 'pct' => $l['pct'], 'amount_usd' => $l['amount'],
				) ) && $all_ok;
			}
		}
		if ( ! $all_ok ) { $wpdb->query( 'ROLLBACK' ); return 0; } // 3.14.30: un cierre a medias no se guarda
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
			$pid = $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id(); $vid = $p->is_type( 'variation' ) ? $p->get_id() : 0;
			$pts = CVD_Programa_Calc::unit_points( $price, self::unit_cost( $pid, $vid ), self::unit_commission( $p, $user_id, $price ), self::unit_fixed( $pid, $vid ) );
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

	/** 3.14.33: puntos con hasta 2 decimales (la lista de vales y su total cuadran con el cálculo de la noche). */
	private static function pts2( $v ): string { $n = round( (float) $v, 2 ); return number_format( $n, $n == floor( $n ) ? 0 : 2, ',', '.' ); } // phpcs:ignore Universal.Operators.StrictComparisons

	/** 3.14.33: productos de un vale («Sábanas ×2, Fundas»), sin precios. */
	private static function vale_products( WC_Order $order ): string {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$qty = (int) $item->get_quantity();
			$names[] = wp_strip_all_tags( (string) $item->get_name() ) . ( $qty > 1 ? ' ×' . $qty : '' );
		}
		return implode( ', ', $names );
	}

	/**
	 * 3.14.33 (Dany, 16-oct): los vales que le dieron puntos este mes, leídos del MISMO cálculo guardado que enseña
	 * «Tus puntos» (líneas «venta» y «devolucion» de la gestora), así el total cuadra. Solo número, fechas, productos y
	 * puntos: nada de costo ni margen. Aparte, en vivo, sus vales que todavía no cuentan (falta entregar o cobrar, cobrado
	 * después del último cálculo o compra para ella misma).
	 */
	private static function vales( WP_User $user, ?array $run, ?array $data ): array {
		$out = array( 'counted' => array(), 'waiting' => array(), 'total' => 0.0, 'period' => (string) ( $data['period'] ?? wp_date( 'Y-m' ) ) );
		$seen = array();
		// 3.14.49: en la cuenta del dueño, su parte de las ventas de proveedor también es puntos suyos: van en la lista.
		$kinds = array( 'venta', 'devolucion', 'proveedor', 'proveedor_fuera', 'proveedor_devolucion' );
		foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
			if ( (int) ( $l['user'] ?? 0 ) !== $user->ID || ! in_array( $l['kind'] ?? '', $kinds, true ) ) { continue; }
			$oid = (int) $l['order'];
			if ( 'proveedor_fuera' === $l['kind'] ) {
				$out['counted'][] = array( 'number' => 'Fuera', 'made' => '', 'paid' => '', 'ts' => 0, 'products' => 'Venta de proveedor fuera de la web (tu parte)', 'points' => (float) $l['points'], 'refund' => false );
				$out['total'] += (float) $l['points'];
				continue;
			}
			if ( 'venta' === $l['kind'] || 'devolucion' === $l['kind'] ) { $seen[ $oid ] = true; }
			$order = $oid ? wc_get_order( $oid ) : null;
			$at = $order ? CVD_Programa_Calc::approved_at( $order->get_meta( '_cvd_commission_history', true ), (string) $order->get_meta( '_cvd_cash_verified_at', true ), $order->get_date_completed() ? gmdate( 'Y-m-d H:i:s', $order->get_date_completed()->getTimestamp() ) : '' ) : '';
			$out['counted'][] = array(
				'number' => $order ? (string) $order->get_order_number() : (string) $oid,
				'made' => $order && $order->get_date_created() ? wp_date( 'j/n', $order->get_date_created()->getTimestamp() ) : '',
				'paid' => '' !== $at ? wp_date( 'j/n', strtotime( $at . ' UTC' ) ) : '',
				'ts' => '' !== $at ? strtotime( $at . ' UTC' ) : 0,
				'products' => ( $order ? self::vale_products( $order ) : '' ) . ( 'proveedor' === $l['kind'] ? ' (venta de proveedor de otra gestora: tu parte)' : '' ),
				'points' => (float) $l['points'],
				'refund' => 'devolucion' === $l['kind'] || 'proveedor_devolucion' === $l['kind'],
			);
			$out['total'] += (float) $l['points'];
		}
		usort( $out['counted'], static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		$out['total'] = round( $out['total'], 2 );

		// Los que aún no cuentan (en vivo). Mismo filtro de pedidos que el cálculo: suyos, no «orgánicos», no pruebas.
		$self = array();
		foreach ( (array) ( $data['alerts'] ?? array() ) as $a ) {
			if ( 'autocompra' === ( $a['type'] ?? '' ) && (int) ( $a['user'] ?? 0 ) === $user->ID ) { $self[ (int) $a['order'] ] = true; }
		}
		$run_ts = $run ? strtotime( $run['computed_at'] . ' UTC' ) : 0;
		$since = strtotime( wp_date( 'Y-m-01' ) . ' -1 month 00:00:00 ' . wp_timezone_string() );
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 60, 'status' => array( 'completed', 'processing', 'on-hold' ), 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user->ID, 'date_created' => '>=' . $since ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order || isset( $seen[ $order->get_id() ] ) || absint( $order->get_meta( '_cvd_owner_user_id', true ) ) !== $user->ID ) { continue; }
			if ( 'organic' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { continue; }
			if ( class_exists( 'CVD_Delivery' ) && ( CVD_Delivery::is_test_order( $order ) || 'test' === CVD_Delivery::blocks_dispatch( $order ) ) ) { continue; }
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'cancelled' === $status ) { continue; }
			$why = '';
			if ( in_array( $status, array( 'approved', 'paid' ), true ) ) {
				$at = CVD_Programa_Calc::approved_at( $order->get_meta( '_cvd_commission_history', true ), (string) $order->get_meta( '_cvd_cash_verified_at', true ), $order->get_date_completed() ? gmdate( 'Y-m-d H:i:s', $order->get_date_completed()->getTimestamp() ) : '' );
				$ts = '' !== $at ? strtotime( $at . ' UTC' ) : 0;
				if ( isset( $self[ $order->get_id() ] ) ) { $why = 'No cuenta: es una compra para ti misma'; }
				elseif ( $ts && $ts > $run_ts && wp_date( 'Y-m', $ts ) === wp_date( 'Y-m' ) ) { $why = 'Cobrado después del último cálculo: cuenta esta noche'; }
				else { continue; } // cobrado en otro mes, o no cuenta por otra razón del cálculo
			} else {
				$why = 'completed' === $order->get_status() ? 'Todavía no cuenta: falta cobrar' : 'Todavía no cuenta: falta entregar y cobrar';
			}
			$out['waiting'][] = array(
				'number' => (string) $order->get_order_number(),
				'made' => $order->get_date_created() ? wp_date( 'j/n', $order->get_date_created()->getTimestamp() ) : '',
				'products' => self::vale_products( $order ),
				'why' => $why,
			);
		}
		return $out;
	}

	private static function render_vales( WP_User $user, ?array $run, ?array $data ): string {
		$v = self::vales( $user, $run, $data );
		$month = CVD_Programa_Calc::MONTHS[ (int) substr( $v['period'], 5, 2 ) ] ?? '';
		$n = count( array_filter( $v['counted'], static fn( $c ) => ! $c['refund'] ) );
		ob_start();
		?>
		<section class="cvga-card" id="cvga-vales">
			<div class="cvga-row"><h2>Tus vales de <?php echo esc_html( $month ); ?></h2><span class="cvga-chip"><?php echo esc_html( $n . ( 1 === $n ? ' vale' : ' vales' ) ); ?></span></div>
			<p class="cvga-note">Solo los vales entregados y cobrados este mes. Cada uno dice los puntos que te dio.</p>
			<?php if ( ! $v['counted'] ) : ?>
			<p class="cvga-empty">Todavía no tienes vales que cuenten este mes. Cuando un vale tuyo se entregue y se cobre, saldrá aquí al día siguiente.</p>
			<?php else : ?>
			<ul class="cvga-vales">
				<?php foreach ( $v['counted'] as $c ) : ?>
				<li<?php echo $c['refund'] ? ' class="is-refund"' : ''; ?>>
					<span class="cvga-vale-num">#<?php echo esc_html( $c['number'] ); ?></span>
					<div class="cvga-vale-text"><b><?php echo esc_html( $c['refund'] ? 'Devolución de un vale de otro mes' : ( $c['products'] ?: 'Vale #' . $c['number'] ) ); ?></b><small><?php echo esc_html( $c['refund'] ? 'Se resta este mes' : trim( ( $c['made'] ? 'Hecho el ' . $c['made'] : '' ) . ( $c['paid'] ? ( $c['made'] ? ' · ' : '' ) . 'cobrado el ' . $c['paid'] : '' ) ) ); ?></small></div>
					<b class="cvga-vale-pts"><?php echo esc_html( ( $c['points'] > 0 ? '+' : '' ) . self::pts2( $c['points'] ) ); ?></b>
				</li>
				<?php endforeach; ?>
				<li class="cvga-vales-total"><span></span><div class="cvga-vale-text"><b>Total de tus puntos</b></div><b class="cvga-vale-pts"><?php echo esc_html( self::pts2( $v['total'] ) ); ?></b></li>
			</ul>
			<?php endif; ?>
			<?php if ( $v['waiting'] ) : ?>
			<h3 class="cvga-vales-sub">Todavía no cuentan</h3>
			<ul class="cvga-vales is-waiting">
				<?php foreach ( $v['waiting'] as $w ) : ?>
				<li>
					<span class="cvga-vale-num">#<?php echo esc_html( $w['number'] ); ?></span>
					<div class="cvga-vale-text"><b><?php echo esc_html( $w['products'] ?: 'Vale #' . $w['number'] ); ?></b><small><?php echo esc_html( ( $w['made'] ? 'Hecho el ' . $w['made'] . ' · ' : '' ) . $w['why'] ); ?></small></div>
					<span class="cvga-vale-pts">—</span>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

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
		// Lo que deja cada invitada este mes (1 % de sus ventas y 0,5 % de las de sus invitadas; D77).
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
				<article class="cvga-card cvga-kpi"><small>Invitadas</small><strong><?php echo esc_html( (string) count( $l1 ) ); ?></strong><span>Te dan el <?php echo esc_html( CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red1_pct'] ) ); ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Sus invitadas</small><strong><?php echo esc_html( (string) count( $l2 ) ); ?></strong><span>Te dan el <?php echo esc_html( CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red2_pct'] ) ); ?> (desde Plata)</span></article>
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
						<div class="cvga-team-text"><b><?php echo esc_html( (string) ( $p['name'] ?? '' ) ); ?></b><small><?php echo esc_html( ( $joined ? 'Desde ' . wp_date( 'j/n/Y', strtotime( $joined ) ) . ' · ' : '' ) . ( $left ? 'te da el ' . CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red1_pct'] ) . ' ' . $left . ( 1 === $left ? ' mes más' : ' meses más' ) : 'ya pasaron los 12 meses' ) ); ?></small></div>
						<div class="cvga-team-end"><?php echo self::stone_chip( (string) ( $p['title'] ?? '' ), 'Empezando' ); // phpcs:ignore ?><b><?php echo esc_html( self::usd( $from[ $id ] ?? 0 ) ); ?></b></div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $l2 ) : ?><p class="cvga-note">Y <?php echo esc_html( count( $l2 ) . ( 1 === count( $l2 ) ? ' gestora invitada' : ' gestoras invitadas' ) ); ?> por ellas (2.º nivel), que te dan el <?php echo esc_html( CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red2_pct'] ) ); ?> si eres Plata ese mes. Va incluido en lo de cada una.</p><?php endif; ?>
				<?php endif; ?>
				<p class="cvga-note">Solo cuentan ventas entregadas y cobradas, sobre el precio de Casa Viva (sin lo que suben ellas ni la mensajería). Lo pone Casa Viva: tus invitadas cobran su comisión completa.</p>
			</section>
			<section class="cvga-card">
				<h2>Invita a otra gestora</h2>
				<p class="cvga-note">Ganas el <?php echo esc_html( CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red1_pct'] ) ); ?> de lo que venda durante 12 meses. Por invitar no se cobra nada: solo cuando ella vende y entrega.</p>
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
		$owner = class_exists( 'CVD_Supplier_Split' ) && CVD_Supplier_Split::owner_gestor_user() === (int) $user->ID; // 3.14.48 (D92)
		$video = esc_url( trim( (string) get_option( 'cvd_programa_video_url', '' ) ) ); // 3.14.51: video tutorial de las piedras (vacía = no sale)
		ob_start();
		?>
		<section class="cvga-view" data-view="piedra" id="cvga-piedra">
			<div class="cvga-head"><h1>Mi piedra</h1><p>Sube de piedra con la ganancia que dejan tus ventas y las de tu equipo.</p></div>
			<?php echo self::shadow_note(); // phpcs:ignore ?>
			<?php if ( '' !== $video ) : ?>
			<section class="cvga-card">
				<h2>Así funciona (1 minuto)</h2>
				<video src="<?php echo $video; // phpcs:ignore -- esc_url arriba. ?>" controls playsinline preload="metadata" style="display:block;width:100%;max-width:360px;margin:0 auto;border-radius:12px;background:#000"></video>
			</section>
			<?php endif; ?>
			<section class="cvga-card cvga-stone-card">
				<div class="cvga-stone-big" data-gem="<?php echo esc_attr( $title ?: 'none' ); ?>" aria-hidden="true"><?php echo self::gem_svg(); // phpcs:ignore ?></div>
				<div>
					<small>Tu título</small>
					<h2 class="cvga-stone-name"><?php echo esc_html( CVD_Programa_Calc::stone( $title )['name'] ?? 'Todavía sin piedra' ); ?></h2>
					<p class="cvga-note"><?php echo $current ? esc_html( 'Este mes mantienes ' . $current['name'] . '.' ) : 'Este mes todavía no llegas a Bronce.'; ?> El título no se pierde; el bono se cobra el mes que lo mantienes.</p>
				</div>
			</section>
			<div class="cvga-kpis">
				<article class="cvga-card cvga-kpi"><small>Tus puntos este mes</small><strong><?php echo esc_html( self::pts2( $me['own_points'] ?? 0 ) ); ?></strong><span><?php echo esc_html( self::usd( $me['own_sales_usd'] ?? 0 ) . ' vendidos y cobrados' ); ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Puntos del equipo</small><strong><?php echo esc_html( self::pts( $me['team_points'] ?? 0 ) ); ?></strong><span>Tú + tus invitadas + las de ellas</span></article>
				<?php if ( $owner ) : // 3.14.48 (D92) ?>
				<article class="cvga-card cvga-kpi"><small>De ellos, de proveedor</small><strong><?php echo esc_html( self::pts2( $me['supplier_points'] ?? 0 ) ); ?></strong><span>Tu parte de las ventas de proveedor de las gestoras (ya van en tus puntos). Sin bono</span></article>
				<?php endif; ?>
			</div>
			<?php echo self::render_vales( $user, $run, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
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
				<?php if ( $owner ) : // 3.14.48 (D92): la cuenta del dueño no cobra bono de piedra ?>
				<p class="cvga-empty">Tu cuenta no cobra bono de piedra: en las ventas de proveedor ya ganas tu parte del reparto («Mis ventas de proveedor»). Tu red sí se cobra como la de cualquier madrina.</p>
				<?php elseif ( ! $current ) : ?>
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
		} elseif ( 'revisada' === $do ) {
			$msg = self::mark_reviewed( sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) ), get_current_user_id() ) ? 'revisada' : 'error';
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
		if ( ! $row || 'cerrado' !== $row['run_status'] || $row['paid_at'] || '' === $ref || absint( $row['payout_id'] ?? 0 ) ) { return false; } // 3.14.29: si ya está en «Pedir mi pago», se paga allí
		$data = json_decode( (string) $row['payload'], true );
		$person = $data['people'][ $row['user_id'] ] ?? null;
		if ( ! $person ) { return false; }
		$receipt = CVD_Programa_Calc::receipt( $person, (string) $row['period'], 'si', 'Pagado: ' . $ref );
		return (bool) $wpdb->update( self::t( 'people' ), array( 'paid_at' => current_time( 'mysql', true ), 'paid_by' => get_current_user_id(), 'paid_ref' => $ref, 'receipt_sha256' => hash( 'sha256', $receipt ) ), array( 'id' => $row_id, 'paid_at' => null ) );
	}

	/* ================= 3.14.29: red y bono por «Pedir mi pago» (D75) ================= */
	// Solo cierres de mes en «sí» (status cerrado). En sombra nunca sale nada. Todo dentro de la transacción de CVD_Payouts.

	public static function is_payout_line( int $id ): bool {
		return $id > CVD_Programa_Calc::PAYOUT_BASE;
	}

	/** Renglones que la gestora puede pedir: cierres pagables, sin pagar y sin pedir. */
	public static function payout_lines( int $owner_id ): array {
		if ( 'no' === self::mode() || self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) { return array(); }
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT p.id, p.period, p.stone, p.pay_red_usd, p.pay_bonus_cup, r.rate_cup, r.computed_at FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . " r ON r.id=p.run_id WHERE p.user_id=%d AND r.kind='mensual' AND r.status='cerrado' AND p.paid_at IS NULL AND p.payout_id=0 AND (p.pay_red_usd>0 OR p.pay_bonus_cup>0) ORDER BY p.period, p.id", $owner_id ), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = CVD_Programa_Calc::payout_line( (int) $r['id'], (string) $r['period'], (float) $r['pay_red_usd'], (int) $r['pay_bonus_cup'], (float) $r['rate_cup'], (string) $r['stone'], (int) strtotime( $r['computed_at'] . ' UTC' ) );
		}
		return $out;
	}

	/** Ata los renglones a la solicitud; si alguno ya no está libre, lanza y CVD_Payouts deshace todo. */
	public static function reserve_for_payout( array $line_ids, int $payout_id, int $owner_id ): void {
		global $wpdb;
		$rows = array_values( array_unique( array_map( static fn( $id ) => (int) $id - CVD_Programa_Calc::PAYOUT_BASE, $line_ids ) ) );
		if ( ! $rows ) { return; }
		$in = implode( ',', array_map( 'absint', $rows ) );
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'people' ) . " SET payout_id=%d WHERE id IN ({$in}) AND user_id=%d AND payout_id=0 AND paid_at IS NULL", $payout_id, $owner_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids enteros.
		if ( count( $rows ) !== (int) $n ) { throw new RuntimeException( 'La red o el bono ya se pidió o cambió. Recarga la página.' ); }
	}

	/** Filas del libro dentro de una solicitud. */
	public static function payout_rows( int $payout_id ): array {
		global $wpdb;
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) { return array(); }
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'people' ) . ' WHERE payout_id=%d', $payout_id ) ) );
	}

	/** Solicitud rechazada: la red y el bono vuelven a estar disponibles. */
	public static function release_payout( int $payout_id ): void {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'people' ) . ' SET payout_id=0 WHERE payout_id=%d AND paid_at IS NULL', $payout_id ) ) ) { throw new RuntimeException( 'No se pudo liberar la red o el bono.' ); }
	}

	/** Solicitud pagada: cada fila queda pagada con la referencia del pago y la huella de su recibo. */
	public static function settle_payout( int $payout_id, string $reference, int $by ): void {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT p.id, p.user_id, p.period, r.payload FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . ' r ON r.id=p.run_id WHERE p.payout_id=%d AND p.paid_at IS NULL', $payout_id ), ARRAY_A );
		$ref = 'Pago #' . $payout_id . ( '' !== $reference ? ' · ' . $reference : '' );
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['payload'], true );
			$person = $data['people'][ $row['user_id'] ] ?? null;
			$hash = $person ? hash( 'sha256', CVD_Programa_Calc::receipt( $person, (string) $row['period'], 'si', 'Pagado: ' . $ref ) ) : '';
			$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'people' ) . ' SET paid_at=%s, paid_by=%d, paid_ref=%s, receipt_sha256=%s WHERE id=%d AND payout_id=%d AND paid_at IS NULL', current_time( 'mysql', true ), $by, substr( $ref, 0, 190 ), $hash, (int) $row['id'], $payout_id ) );
			if ( 1 !== (int) $n ) { throw new RuntimeException( 'No se pudo marcar pagada la red o el bono.' ); }
		}
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
		// Alertas: lo grave primero; «revisar» (su teléfono con otro cliente) es muy común y va al final.
		// D79: «cuenta_compartida» es grave (arriba); las «revisar» van agrupadas en un resumen.
		$order = array( 'cuenta_compartida' => 0, 'autocompra' => 0, 'autocompra_red' => 0, 'equipo' => 1, 'concentracion' => 1, 'red_negativa' => 2, 'devolucion' => 3, 'pierde' => 4, 'ganancia_alta' => 4, 'sin_costo' => 5, 'revisar' => 6, 'revisar_resumen' => 6, 'provisional' => 7 );
		$alerts = (array) ( $data['alerts'] ?? array() );
		usort( $alerts, static fn( $a, $b ) => ( $order[ $a['type'] ] ?? 9 ) <=> ( $order[ $b['type'] ] ?? 9 ) );
		$reviewed = self::reviewed();
		$alert_counts = array();
		foreach ( $alerts as &$a ) {
			$alert_counts[ $a['type'] ] = ( $alert_counts[ $a['type'] ] ?? 0 ) + max( 1, (int) ( $a['count'] ?? 1 ) );
			$a['key'] = CVD_Programa_Calc::alert_key( $a );
			$a['canReview'] = in_array( $a['type'], CVD_Programa_Calc::REVIEW_TYPES, true );
			$a['reviewed'] = $a['canReview'] && isset( $reviewed[ $a['key'] ] );
			if ( $a['reviewed'] ) { $a['reviewedAt'] = (string) ( $reviewed[ $a['key'] ]['at'] ?? '' ); }
		}
		unset( $a );
		$pending = count( array_filter( $alerts, static fn( $a ) => $a['canReview'] && ! $a['reviewed'] ) );
		global $wpdb;
		$runs = (array) $wpdb->get_results( 'SELECT id, period, kind, mode, status, computed_at, rate_cup, totals, payload_sha256 FROM ' . self::t( 'runs' ) . ' ORDER BY id DESC LIMIT 15', ARRAY_A );
		foreach ( $runs as &$r ) { $r['totals'] = json_decode( (string) $r['totals'], true ); }
		unset( $r );
		return array(
			'mode' => self::mode(),
			'viewers' => (string) get_option( self::VIEW_OPTION, '' ),
			'fixedCosts' => self::subtract_fixed(),
			'run' => $run ? array( 'id' => (int) $run['id'], 'period' => $run['period'], 'kind' => $run['kind'], 'status' => $run['status'], 'computedAt' => $run['computed_at'], 'rateCup' => (float) $run['rate_cup'], 'sha256' => $run['payload_sha256'] ) : null,
			'totals' => $data['totals'] ?? null,
			'people' => array_slice( $people, 0, $people_limit ),
			'alerts' => array_slice( $alerts, 0, 100 ),
			'alertCounts' => $alert_counts,
			'pendingReview' => $pending, // D79: alertas que hacen esperar al cierre automático
			'closeWaiting' => (string) get_option( self::WAIT_OPTION, '' ), // D79: mes cuyo cierre espera ('' = ninguno)
			'runs' => $runs,
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		self::maybe_upgrade();
		$rep = self::report();
		$mode = $rep['mode'];
		$msgs = array( 'guardado' => 'Guardado.', 'calculado' => 'Calculado y apuntado en el libro.', 'cerrado' => 'Mes cerrado y apuntado en el libro.', 'pagado' => 'Pago anotado con su recibo.', 'revisada' => 'Alerta marcada «Revisada».', 'error' => 'No se pudo (revisa que el mes no esté ya cerrado o que falte la referencia).' );
		$msg = sanitize_key( (string) ( $_GET['msg'] ?? '' ) );
		$names = array();
		foreach ( $rep['people'] as $p ) { $names[ $p['id'] ] = $p['name']; }
		echo '<div class="wrap"><h1>Programa de gestoras</h1>';
		if ( isset( $msgs[ $msg ] ) ) { echo '<div class="notice notice-' . ( 'error' === $msg ? 'error' : 'success' ) . '"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>'; }
		echo '<p>Red ' . CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red1_pct'] ) . ' + ' . CVD_Programa_Calc::pct( CVD_Programa_Calc::DEFAULTS['red2_pct'] ) . ', piedras por ganancia y fondo del 15 % sobre la ganancia neta (D53–D60, D77). <strong>Modo: ' . esc_html( array( 'sombra' => 'SOMBRA (calcula y enseña, no paga)', 'si' => 'SÍ (se paga con recibo)', 'no' => 'APAGADO' )[ $mode ] ) . '</strong></p>';
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
			$labels = array( 'equipo' => 'Venta del equipo de la tienda con su enlace (cobra comisión: revisar)', 'cuenta_compartida' => 'Comparte teléfono, tarjeta o dirección con su madrina','revisar_resumen' => 'Su teléfono con otro cliente (normal)', 'autocompra' => 'Autocompra (no cuenta)', 'autocompra_red' => 'La madrina compra por su invitada (sin red)', 'ganancia_alta' => 'Ganancia muy alta: revisar costo','concentracion' => 'Un miembro con más del 60 %', 'red_negativa' => 'Red en negativo', 'devolucion' => 'Devolución de un mes cerrado', 'pierde' => 'Se vende perdiendo', 'sin_costo' => 'Producto sin costo', 'revisar' => 'Su teléfono, 3 veces o más con la misma clienta', 'provisional' => 'Costo provisional (cuenta)' );
			echo '<h2>Alertas (' . esc_html( (string) array_sum( $rep['alertCounts'] ) ) . ')</h2><p>' . esc_html( implode( ' · ', array_map( static fn( $k, $v ) => ( $labels[ $k ] ?? $k ) . ': ' . $v, array_keys( $rep['alertCounts'] ), $rep['alertCounts'] ) ) ) . '</p><ul style="list-style:disc;margin-left:20px">';
			if ( $rep['pendingReview'] ) { echo '<p><strong>' . esc_html( $rep['pendingReview'] . ( 1 === $rep['pendingReview'] ? ' alerta' : ' alertas' ) . ' sin revisar: el cierre automático del mes espera hasta que las marques «Revisada».' ) . '</strong>' . ( $rep['closeWaiting'] ? esc_html( ' Ahora espera el cierre de ' . $rep['closeWaiting'] . '.' ) : '' ) . '</p>'; }
			foreach ( $rep['alerts'] as $a ) {
				echo '<li><strong>' . esc_html( $a['type'] ) . '</strong> · ' . esc_html( $a['text'] );
				if ( $a['reviewed'] ) { echo ' <em>· Revisada ' . esc_html( self::local( (string) ( $a['reviewedAt'] ?? '' ) ) ) . '</em>'; }
				elseif ( $a['canReview'] ) { echo ' ' . $form( 'revisada', '<input type="hidden" name="key" value="' . esc_attr( $a['key'] ) . '"><button class="button button-small">Revisada</button>' ); } // phpcs:ignore
				echo '</li>';
			}
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
		$rows = (array) $wpdb->get_results( 'SELECT p.id, p.user_id, p.period, p.pay_red_usd, p.pay_bonus_cup, p.paid_at, p.paid_ref, p.payout_id, r.id AS run_id, r.status AS run_status FROM ' . self::t( 'people' ) . ' p JOIN ' . self::t( 'runs' ) . " r ON r.id=p.run_id WHERE r.kind='mensual' AND r.status IN ('cerrado','sombra') AND (p.pay_red_usd>0 OR p.pay_bonus_cup>0) ORDER BY p.period DESC, p.id LIMIT 200", ARRAY_A );
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
			elseif ( absint( $row['payout_id'] ?? 0 ) ) { echo esc_html( 'Pedido en «Pedir mi pago» (#' . absint( $row['payout_id'] ) . ')' ); }
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
		// D79: Lennys marca una alerta «ganancia_alta» o «cuenta_compartida» como revisada (el cierre automático deja de esperar por ella).
		register_rest_route( 'casa-viva/v1', '/panel/programa/revisar', array( 'methods' => 'POST', 'callback' => static function ( WP_REST_Request $req ) {
			$ok = self::mark_reviewed( sanitize_text_field( (string) $req->get_param( 'key' ) ), absint( get_option( 'cvd_panel_actor_user_id', 0 ) ) );
			return $ok ? new WP_REST_Response( self::report() ) : new WP_Error( 'cvd_programa', 'No se pudo marcar como revisada.', array( 'status' => 400 ) );
		}, 'permission_callback' => $auth ) );
		register_rest_route( 'casa-viva/v1', '/panel/programa/calcular', array( 'methods' => 'POST', 'callback' => static function () {
			$id = self::run( wp_date( 'Y-m' ), 'manual', absint( get_option( 'cvd_panel_actor_user_id', 0 ) ) );
			return $id ? new WP_REST_Response( self::report() ) : new WP_Error( 'cvd_programa', 'No se pudo calcular.', array( 'status' => 500 ) );
		}, 'permission_callback' => $auth ) );
	}
}
