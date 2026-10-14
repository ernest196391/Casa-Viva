<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.14 (Ernesto, 14-oct, D66 + D69): reparto de la venta de un producto de proveedor (bajo pedido, SKU MK-).
 *
 * Todo sale del margen = precio web de esas líneas − precio del proveedor:
 *   comisión de la gestora (la de siempre, _cvd_commission_*; aquí NO se cambia)
 *   + fondo de la operación: mensajero (ir a buscarlo / llevarlo) + dependienta + tienda Casa Viva (la tienda siempre gana)
 *   + el resto para el gestor especial (hoy siempre Ernesto).
 * Se apunta en el pedido (_cvd_supplier_split, JSON) desde el panel de Lennys y se sella al cerrar el pedido
 * (entregado y dinero confirmado = pedido «completado», el mismo momento en que se aprueba la comisión de la gestora):
 * _cvd_supplier_split_closed_at + nota en el pedido con quién gana cuánto. Es información: no mueve dinero ni pagos.
 *
 * Margen mínimo (sugerencia de Claude, aviso y no bloqueo): margen ≥ comisión gestora + fondo + 1 USD.
 * Rutas (clave del panel, como CVD_Panel_Bridge):
 *   GET  /panel/supplier-split/<id>         reparto guardado o sugerido
 *   POST /panel/supplier-split/<id>         guardar {supplier, costUsd, route, vehicle, messengerCup, messengerName, clerkCup, clerkName, storeCup, by}
 *   GET  /panel/supplier-sales?month=AAAA-MM  ventas de proveedor del mes con cada parte y totales por persona
 */
final class CVD_Supplier_Split {
	public const META = '_cvd_supplier_split';
	public const CLOSED = '_cvd_supplier_split_closed_at';
	private const NS = 'casa-viva/v1';
	public const ROUTES = array( 'A' => 'El proveedor lo trae a la tienda', 'B' => 'Nuestro mensajero lo recoge en el proveedor', 'C' => 'El proveedor lo lleva directo al cliente' );
	public const VEHICLES = array( 'moto' => 'Moto', 'triciclo' => 'Triciclo', 'ninguno' => 'Sin vehículo' );
	/** Sugerencias por defecto (D69), en CUP. Se pueden cambiar sin tocar código con estas opciones. */
	private const DEFAULTS = array( 'moto' => 1000, 'triciclo' => 2500, 'clerk_per_item' => 500, 'store' => 1500, 'store_min' => 1000 );
	public const SPECIAL_NAME = 'Ernesto';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Al cerrar: todos los caminos de cierre (mensajería, recogida en tienda, /ventas/, panel) acaban en «completado».
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'seal_on_complete' ), 60, 2 );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( self::NS, '/panel/supplier-split/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/supplier-split/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/supplier-sales', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_month' ), 'permission_callback' => $auth ) );
	}

	// ---------- cálculo ----------

	private static function opt( string $key ): int {
		return (int) get_option( 'cvd_supplier_split_' . $key, self::DEFAULTS[ $key ] );
	}

	/** Líneas del pedido que son de proveedor (bajo pedido): precio web, costo y comisión de la gestora de cada una. */
	public static function supplier_lines( WC_Order $order ): array {
		$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
		$commission_status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) );
		$pays_gestora = 'gestora' === $owner_type && absint( $order->get_meta( '_cvd_owner_user_id', true ) ) && 'cancelled' !== $commission_status;
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product || ! class_exists( 'CVD_Costs' ) || ! CVD_Costs::is_bajo_pedido( $product ) ) { continue; }
			$qty = max( 1, (int) $item->get_quantity() );
			$cost = CVD_Costs::cost( $product );
			$snapshot = $item->get_meta( '_cvd_commission_snapshot', true );
			$gestora = $pays_gestora && is_array( $snapshot ) ? (float) ( $snapshot['base_commission'] ?? 0 ) + (float) ( $snapshot['markup'] ?? 0 ) : 0.0;
			$supplier = (string) $product->get_meta( '_cvd_supplier_name', true );
			if ( '' === $supplier && $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); $supplier = $parent ? (string) $parent->get_meta( '_cvd_supplier_name', true ) : ''; }
			$lines[] = array(
				'itemId' => (int) $item_id, 'name' => $item->get_name(), 'sku' => (string) $product->get_sku(), 'qty' => $qty,
				'webUsd' => round( (float) $item->get_total(), 2 ), 'costUsd' => null === $cost ? null : round( $cost * $qty, 2 ),
				'gestoraUsd' => round( $gestora, 2 ), 'supplier' => $supplier,
			);
		}
		// Si la comisión del pedido no tiene foto por línea (pedidos viejos), toda la comisión cuenta para estas líneas.
		if ( $lines && $pays_gestora && 0.0 === array_sum( array_column( $lines, 'gestoraUsd' ) ) && count( $order->get_items( 'line_item' ) ) === count( $lines ) ) {
			$lines[0]['gestoraUsd'] = round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 );
		}
		return $lines;
	}

	public static function saved( WC_Order $order ): array {
		$raw = $order->get_meta( self::META, true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private static function messenger_name( WC_Order $order ): string {
		$id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$user = $id ? get_userdata( $id ) : null;
		return $user ? (string) $user->display_name : '';
	}

	/** Mensajero sugerido: ruta C = 0 (lo cobra el proveedor); si no, la tarifa por vehículo. */
	public static function messenger_default( string $route, string $vehicle ): int {
		if ( 'C' === $route || 'ninguno' === $vehicle ) { return 0; }
		return 'triciclo' === $vehicle ? self::opt( 'triciclo' ) : self::opt( 'moto' );
	}

	/**
	 * Reparto completo: lo guardado encima de la sugerencia, y las cuentas. $input (opcional) = valores nuevos sin guardar.
	 * Importes CUP enteros; USD con 2 decimales. Tasa = la guardada en el pedido el día de la venta (o la de hoy si no tiene).
	 */
	public static function compute( WC_Order $order, ?array $input = null ): array {
		$lines = self::supplier_lines( $order );
		$saved = self::saved( $order );
		$v = array_merge( $saved['input'] ?? array(), $input ?? array() );
		$qty = array_sum( array_column( $lines, 'qty' ) ) ?: 1;
		$web = round( array_sum( array_column( $lines, 'webUsd' ) ), 2 );
		$cost_known = $lines && ! in_array( null, array_column( $lines, 'costUsd' ), true );
		$cost_default = round( (float) array_sum( array_map( 'floatval', array_column( $lines, 'costUsd' ) ) ), 2 );
		$route = isset( $v['route'], self::ROUTES[ $v['route'] ] ) ? $v['route'] : 'B';
		$vehicle = isset( $v['vehicle'], self::VEHICLES[ $v['vehicle'] ] ) ? $v['vehicle'] : ( 'C' === $route ? 'ninguno' : 'moto' );
		$order_rate = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate( $order ) : null;
		$rate = $order_rate ?: ( class_exists( 'CVD_Daily_Rates' ) ? (float) ( CVD_Daily_Rates::get()['cup'] ?? 0 ) : 0.0 );
		$cost = isset( $v['costUsd'] ) && is_numeric( $v['costUsd'] ) ? round( (float) $v['costUsd'], 2 ) : $cost_default;
		$messenger = isset( $v['messengerCup'] ) && is_numeric( $v['messengerCup'] ) ? (int) $v['messengerCup'] : self::messenger_default( $route, $vehicle );
		$clerk = isset( $v['clerkCup'] ) && is_numeric( $v['clerkCup'] ) ? (int) $v['clerkCup'] : self::opt( 'clerk_per_item' ) * $qty;
		$store = isset( $v['storeCup'] ) && is_numeric( $v['storeCup'] ) ? (int) $v['storeCup'] : self::opt( 'store' );
		$gestora_usd = round( array_sum( array_column( $lines, 'gestoraUsd' ) ), 2 );
		$margin_usd = round( $web - $cost, 2 );
		$margin_cup = (int) round( $margin_usd * $rate );
		$gestora_cup = (int) round( $gestora_usd * $rate );
		$fund = $messenger + $clerk + $store;
		$special = $margin_cup - $gestora_cup - $fund;
		$one_usd = (int) round( $rate );
		$min_cup = $gestora_cup + $fund + $one_usd;
		$meets = $rate > 0 && $margin_cup >= $min_cup;
		$supplier = trim( (string) ( $v['supplier'] ?? '' ) );
		if ( '' === $supplier ) { foreach ( $lines as $l ) { if ( '' !== $l['supplier'] ) { $supplier = $l['supplier']; break; } } }
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner = 'gestora' === $order->get_meta( '_cvd_owner_type', true ) && $owner_id ? get_userdata( $owner_id ) : null;
		$closed_at = (string) $order->get_meta( self::CLOSED, true );
		$warning = '';
		if ( $rate <= 0 ) {
			$warning = 'Este pedido no tiene la tasa del día guardada: no se puede pasar el margen a CUP.';
		} elseif ( $special < 0 ) {
			$warning = 'No llega: faltan ' . self::cup_text( -$special ) . ' para pagar a todos. Sube el precio web, baja el fondo o no aceptes la venta.';
		} elseif ( ! $meets ) {
			$warning = 'No llega al mínimo: al gestor especial le quedan ' . self::cup_text( $special ) . ' y el mínimo es 1 USD (' . self::cup_text( $one_usd ) . '). Sube el precio web, baja el fondo o no aceptes la venta.';
		}
		return array(
			'applies' => (bool) $lines || (bool) $saved,
			'orderId' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'orderStatus' => $order->get_status(), 'lines' => $lines,
			'supplier' => $supplier, 'route' => $route, 'routeLabel' => self::ROUTES[ $route ], 'vehicle' => $vehicle,
			'webUsd' => $web, 'costUsd' => $cost, 'costKnown' => $cost_known || isset( $v['costUsd'] ), 'costDefaultUsd' => $cost_default,
			'rate' => $rate, 'rateSource' => $order_rate ? 'pedido' : 'hoy', 'rateDay' => $order_rate && class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate_day( $order ) : '',
			'marginUsd' => $margin_usd, 'marginCup' => $margin_cup,
			'gestora' => $owner ? (string) $owner->display_name : null, 'gestoraUsd' => $gestora_usd, 'gestoraCup' => $gestora_cup,
			'messengerCup' => $messenger, 'messengerName' => trim( (string) ( $v['messengerName'] ?? '' ) ) ?: self::messenger_name( $order ),
			'clerkCup' => $clerk, 'clerkName' => trim( (string) ( $v['clerkName'] ?? '' ) ), 'storeCup' => $store,
			'fundCup' => $fund, 'specialCup' => $special, 'specialName' => self::SPECIAL_NAME,
			'minCup' => $min_cup, 'meetsMin' => $meets, 'warning' => $warning, 'qty' => $qty,
			'defaults' => array( 'moto' => self::opt( 'moto' ), 'triciclo' => self::opt( 'triciclo' ), 'clerkPerItem' => self::opt( 'clerk_per_item' ), 'store' => self::opt( 'store' ), 'storeMin' => self::opt( 'store_min' ) ),
			'saved' => ! empty( $saved['input'] ), 'savedAt' => $saved['savedAt'] ?? null, 'savedBy' => $saved['savedBy'] ?? null,
			'closed' => '' !== $closed_at, 'closedAt' => '' !== $closed_at ? $closed_at : null,
			'state' => '' !== $closed_at ? 'cerrado' : ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ? 'cancelado' : 'abierto' ),
		);
	}

	public static function cup_text( int $cup ): string {
		return number_format( $cup, 0, ',', '.' ) . ' CUP';
	}

	private static function usd_text( float $usd ): string {
		return number_format( $usd, 2, ',', '.' ) . ' USD';
	}

	/** «Reparto de este producto: proveedor Digital Store 32,00 USD · mensajero Pedri 1.000 · …». */
	public static function summary( array $s ): string {
		$parts = array(
			'proveedor ' . ( '' !== $s['supplier'] ? $s['supplier'] . ' ' : '' ) . self::usd_text( (float) $s['costUsd'] ),
			'mensajero' . ( '' !== $s['messengerName'] ? ' ' . $s['messengerName'] : '' ) . ' ' . self::cup_text( (int) $s['messengerCup'] ),
			'dependienta' . ( '' !== $s['clerkName'] ? ' ' . $s['clerkName'] : '' ) . ' ' . self::cup_text( (int) $s['clerkCup'] ),
			'tienda Casa Viva ' . self::cup_text( (int) $s['storeCup'] ),
		);
		if ( $s['gestora'] ) { $parts[] = $s['gestora'] . ' (gestora) ' . self::usd_text( (float) $s['gestoraUsd'] ) . ' = ' . self::cup_text( (int) $s['gestoraCup'] ); }
		$parts[] = $s['specialName'] . ' (gestor especial) ' . self::cup_text( (int) $s['specialCup'] );
		return implode( ' · ', $parts ) . '. Margen ' . self::usd_text( (float) $s['marginUsd'] ) . ' = ' . self::cup_text( (int) $s['marginCup'] )
			. ' a ' . ( class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::format_rate( (float) $s['rate'] ) : $s['rate'] )
			. ' (ruta ' . $s['route'] . ', ' . strtolower( self::VEHICLES[ $s['vehicle'] ] ) . ').' . ( $s['meetsMin'] ? '' : ' ⚠ ' . $s['warning'] );
	}

	// ---------- guardar y sellar ----------

	/** Valida y guarda lo que manda el panel. Números ≥ 0, tienda ≥ mínimo. Nota en el pedido con quién y las cifras. */
	public static function save( WC_Order $order, array $p, string $by ) {
		if ( '' !== (string) $order->get_meta( self::CLOSED, true ) ) {
			return new WP_Error( 'cvd_split_closed', 'Este reparto ya está cerrado (el pedido se completó). No se puede cambiar.', array( 'status' => 409 ) );
		}
		if ( ! self::supplier_lines( $order ) ) {
			return new WP_Error( 'cvd_split_none', 'Este pedido no tiene productos de proveedor.', array( 'status' => 422 ) );
		}
		$in = array();
		$route = strtoupper( (string) ( $p['route'] ?? 'B' ) );
		$vehicle = sanitize_key( (string) ( $p['vehicle'] ?? 'moto' ) );
		if ( ! isset( self::ROUTES[ $route ] ) ) { return new WP_Error( 'cvd_split', 'Ruta no válida (A, B o C).', array( 'status' => 422 ) ); }
		if ( ! isset( self::VEHICLES[ $vehicle ] ) ) { return new WP_Error( 'cvd_split', 'Vehículo no válido.', array( 'status' => 422 ) ); }
		$in['route'] = $route;
		$in['vehicle'] = $vehicle;
		$labels = array( 'messengerCup' => 'mensajero', 'clerkCup' => 'dependienta', 'storeCup' => 'tienda' );
		foreach ( $labels as $key => $label ) {
			$raw = str_replace( array( '.', ' ' ), '', (string) ( $p[ $key ] ?? '' ) );
			if ( '' === $raw || ! preg_match( '/^\d{1,7}$/', $raw ) ) { return new WP_Error( 'cvd_split', 'Escribe lo del ' . $label . ' en CUP, sin decimales (0 o más).', array( 'status' => 422 ) ); }
			$in[ $key ] = (int) $raw;
		}
		if ( $in['storeCup'] < self::opt( 'store_min' ) ) {
			return new WP_Error( 'cvd_split', 'La tienda siempre gana: pon al menos ' . self::cup_text( self::opt( 'store_min' ) ) . ' para la tienda.', array( 'status' => 422 ) );
		}
		$cost = str_replace( ',', '.', trim( (string) ( $p['costUsd'] ?? '' ) ) );
		if ( '' !== $cost ) {
			if ( ! is_numeric( $cost ) || (float) $cost < 0 || (float) $cost > 100000 ) { return new WP_Error( 'cvd_split', 'El precio del proveedor no es válido.', array( 'status' => 422 ) ); }
			$in['costUsd'] = round( (float) $cost, 2 );
		}
		foreach ( array( 'supplier', 'messengerName', 'clerkName' ) as $key ) {
			$in[ $key ] = mb_substr( trim( sanitize_text_field( (string) ( $p[ $key ] ?? '' ) ) ), 0, 80 );
		}
		$s = self::compute( $order, $in );
		$by = mb_substr( sanitize_text_field( $by ), 0, 120 ) ?: 'panel';
		$order->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'input' => $in, 'savedAt' => gmdate( 'c' ), 'savedBy' => $by, 'snapshot' => self::snapshot( $s ) ) ) );
		$order->add_order_note( 'Reparto de venta de proveedor guardado por ' . $by . ' (panel): ' . self::summary( $s ) );
		$order->save();
		return self::compute( wc_get_order( $order->get_id() ) );
	}

	private static function snapshot( array $s ): array {
		return array_intersect_key( $s, array_flip( array( 'supplier', 'route', 'vehicle', 'webUsd', 'costUsd', 'rate', 'marginUsd', 'marginCup', 'gestora', 'gestoraUsd', 'gestoraCup', 'messengerCup', 'messengerName', 'clerkCup', 'clerkName', 'storeCup', 'fundCup', 'specialCup', 'specialName', 'minCup', 'meetsMin' ) ) );
	}

	/** Pedido completado (entregado + dinero confirmado): sella el reparto final y lo apunta en una nota. Nunca rompe el cierre. */
	public static function seal_on_complete( $order_id, $order = null ): void {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( absint( $order_id ) );
			if ( ! $order || '' !== (string) $order->get_meta( self::CLOSED, true ) || ! self::supplier_lines( $order ) ) { return; }
			$s = self::compute( $order );
			$saved = self::saved( $order );
			$at = gmdate( 'c' );
			$order->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'input' => $saved['input'] ?? array(), 'savedAt' => $saved['savedAt'] ?? null, 'savedBy' => $saved['savedBy'] ?? null, 'closedAt' => $at, 'snapshot' => self::snapshot( $s ), 'suggested' => empty( $saved['input'] ) ) ) );
			$order->update_meta_data( self::CLOSED, $at );
			$order->save_meta_data();
			$order->add_order_note( 'Reparto de este producto' . ( empty( $saved['input'] ) ? ' (sugerido; nadie lo cambió en el panel)' : '' ) . ': ' . self::summary( $s ) );
		} catch ( Throwable $e ) {
			error_log( 'CVD_Supplier_Split::seal_on_complete ' . $order_id . ': ' . $e->getMessage() );
		}
	}

	// ---------- lista del mes ----------

	/** Ids de pedidos con una línea de un producto MK- (o con reparto guardado). */
	private static function supplier_order_ids(): array {
		global $wpdb;
		$like = $wpdb->esc_like( class_exists( 'CVD_Market_Bridge' ) ? CVD_Market_Bridge::SKU_PREFIX : 'MK-' ) . '%';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = im.meta_value AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s
			 WHERE oi.order_item_type = 'line_item'", $like ) );
		$saved = wc_get_orders( array( 'limit' => 500, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'any', 'meta_query' => array( array( 'key' => self::META, 'compare' => 'EXISTS' ) ) ) );
		return array_values( array_unique( array_map( 'absint', array_merge( (array) $ids, (array) $saved ) ) ) );
	}

	public static function month( string $month ): array {
		$tz = wp_timezone();
		$start = preg_match( '/^\d{4}-\d{2}$/', $month ) ? new DateTimeImmutable( $month . '-01 00:00:00', $tz ) : ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		$end = $start->modify( 'first day of next month' );
		$rows = array();
		$tot = array( 'costUsd' => 0.0, 'marginCup' => 0, 'messengerCup' => 0, 'clerkCup' => 0, 'storeCup' => 0, 'gestoraCup' => 0, 'specialCup' => 0 );
		$people = array();
		$add = static function ( string $key, string $role, string $name, int $cup, float $usd = 0.0 ) use ( &$people ): void {
			$people[ $key ] = $people[ $key ] ?? array( 'role' => $role, 'name' => $name, 'cup' => 0, 'usd' => 0.0, 'orders' => 0 );
			$people[ $key ]['cup'] += $cup;
			$people[ $key ]['usd'] = round( $people[ $key ]['usd'] + $usd, 2 );
			$people[ $key ]['orders']++;
		};
		foreach ( self::supplier_order_ids() as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() ) { continue; }
			$created = $order->get_date_created()->getTimestamp();
			if ( $created < $start->getTimestamp() || $created >= $end->getTimestamp() ) { continue; }
			$s = self::compute( $order );
			if ( ! $s['applies'] ) { continue; }
			$rows[] = array_intersect_key( $s, array_flip( array( 'orderId', 'number', 'date', 'orderStatus', 'state', 'gestora', 'supplier', 'route', 'vehicle', 'webUsd', 'costUsd', 'rate', 'marginUsd', 'marginCup', 'gestoraUsd', 'gestoraCup', 'messengerCup', 'messengerName', 'clerkCup', 'clerkName', 'storeCup', 'specialCup', 'meetsMin', 'warning', 'saved', 'closedAt' ) ) )
				+ array( 'products' => implode( ', ', array_map( static fn( $l ) => ( $l['qty'] > 1 ? $l['qty'] . '× ' : '' ) . $l['name'], $s['lines'] ) ) );
			if ( 'cancelado' === $s['state'] ) { continue; }
			foreach ( array( 'marginCup', 'messengerCup', 'clerkCup', 'storeCup', 'gestoraCup', 'specialCup' ) as $k ) { $tot[ $k ] += (int) $s[ $k ]; }
			$tot['costUsd'] = round( $tot['costUsd'] + (float) $s['costUsd'], 2 );
			$add( 'p:' . mb_strtolower( $s['supplier'] ), 'Proveedor', '' !== $s['supplier'] ? $s['supplier'] : 'Proveedor sin nombre', 0, (float) $s['costUsd'] );
			if ( $s['messengerCup'] > 0 ) { $add( 'm:' . mb_strtolower( $s['messengerName'] ), 'Mensajero', '' !== $s['messengerName'] ? $s['messengerName'] : 'Mensajero sin nombre', (int) $s['messengerCup'] ); }
			if ( $s['clerkCup'] > 0 ) { $add( 'd:' . mb_strtolower( $s['clerkName'] ), 'Dependienta', '' !== $s['clerkName'] ? $s['clerkName'] : 'Dependienta sin nombre', (int) $s['clerkCup'] ); }
			$add( 't', 'Tienda', 'Casa Viva', (int) $s['storeCup'] );
			if ( $s['gestora'] ) { $add( 'g:' . mb_strtolower( $s['gestora'] ), 'Gestora', $s['gestora'], (int) $s['gestoraCup'], (float) $s['gestoraUsd'] ); }
			$add( 'e', 'Gestor especial', $s['specialName'], (int) $s['specialCup'] );
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );
		return array( 'month' => $start->format( 'Y-m' ), 'orders' => $rows, 'totals' => $tot, 'people' => array_values( $people ) );
	}

	// ---------- REST ----------

	private static function order_or_error( $id ) {
		$order = wc_get_order( absint( $id ) );
		if ( ! $order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_split_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		return $order;
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function rest_get( WP_REST_Request $request ) {
		$order = self::order_or_error( $request['id'] );
		if ( is_wp_error( $order ) ) { return $order; }
		return self::no_cache( self::compute( $order ) );
	}

	public static function rest_save( WP_REST_Request $request ) {
		$order = self::order_or_error( $request['id'] );
		if ( is_wp_error( $order ) ) { return $order; }
		$p = (array) $request->get_json_params();
		$result = self::save( $order, $p, (string) ( $p['by'] ?? 'panel' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	public static function rest_month( WP_REST_Request $request ): WP_REST_Response {
		return self::no_cache( self::month( sanitize_text_field( (string) $request->get_param( 'month' ) ) ) );
	}
}
