<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.125 (Ernesto 4-nov, D135): contabilidad diaria y mensual.
 *
 * 1) Cada línea de producto de un pedido nuevo guarda su costo en el momento de la venta (venga de la web, del panel,
 *    de un vale o del bot), para que el cierre no dependa del costo que tenga el producto después:
 *      _cvd_line_cost_usd     costo por unidad en USD (no se guarda si el producto no tiene costo)
 *      _cvd_line_cost_source  origen del costo del producto (bizne, proveedor, manual, formula…) o «sin_costo»;
 *                             «relleno:…» = puesto después con el costo de ese día (pedidos anteriores a 3.14.125)
 *      _cvd_line_cost_at      fecha UTC en que se guardó
 *      _cvd_line_kind         tienda | bajo_pedido (SKU MK-)
 * 2) GET /bot/contabilidad?desde=AAAA-MM-DD&hasta=AAAA-MM-DD (clave del bot): pedidos completados en esos días de Cuba,
 *    con la tienda y bajo pedido por separado (cuentas en CVD_Contabilidad_Math). Lo usa el cierre diario del VPS.
 *    3.14.126: con detalle=1 añade ventas por día, comisiones pagadas / por pagar y el reparto de cada pedido de bajo
 *    pedido (lo usa el cierre del mes del día 1).
 * Rellenar pedidos viejos (solo añade, nunca cambia lo guardado): wp eval 'print_r( CVD_Contabilidad::backfill( "2026-10-01", "2026-11-04" ) );'
 */
final class CVD_Contabilidad {
	public const COST = '_cvd_line_cost_usd';
	public const SOURCE = '_cvd_line_cost_source';
	public const AT = '_cvd_line_cost_at';
	public const KIND = '_cvd_line_kind';
	private const NS = 'casa-viva/v1';
	private const MAX_DAYS = 62;

	public static function register(): void {
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'stamp_new_item' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Al crear la línea (cualquier camino): WooCommerce guarda la meta añadida aquí justo después. */
	public static function stamp_new_item( $item_id, $item, $order_id ): void {
		unset( $item_id, $order_id );
		if ( $item instanceof WC_Order_Item_Product ) { self::stamp( $item, '' ); }
	}

	/** Pone el costo de hoy en una línea que no lo tiene. Devuelve true si añadió algo. */
	private static function stamp( WC_Order_Item_Product $item, string $prefix ): bool {
		if ( '' !== (string) $item->get_meta( self::AT, true ) ) { return false; }
		$product = $item->get_product();
		$cost = $product ? CVD_Costs::cost( $product ) : null;
		$source = $product ? (string) $product->get_meta( CVD_Costs::SOURCE, true ) : '';
		if ( ! $source && $product && $product->get_parent_id() ) { $source = (string) get_post_meta( $product->get_parent_id(), CVD_Costs::SOURCE, true ); }
		if ( null !== $cost ) { $item->add_meta_data( self::COST, wc_format_decimal( $cost, 4 ), true ); }
		$item->add_meta_data( self::SOURCE, $prefix . ( null === $cost ? 'sin_costo' : ( $source ?: 'sin_origen' ) ), true );
		$item->add_meta_data( self::AT, gmdate( 'Y-m-d H:i:s' ), true );
		$item->add_meta_data( self::KIND, CVD_Contabilidad_Math::line_kind( $product ? (string) $product->get_sku() : '' ), true );
		return true;
	}

	/** Rellena las líneas sin costo guardado de los pedidos creados entre esos días de Cuba. */
	public static function backfill( string $desde, string $hasta ): array {
		$range = self::range( $desde, $hasta );
		if ( ! $range ) { return array( 'error' => 'fechas no válidas' ); }
		$out = array( 'pedidos' => 0, 'lineas' => 0 );
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'date_created' => $range[0] . '...' . $range[1] ) );
		foreach ( $orders as $order ) {
			$n = 0;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && self::stamp( $item, 'relleno:' ) ) { $item->save_meta_data(); $n++; }
			}
			if ( $n ) { $out['pedidos']++; $out['lineas'] += $n; }
		}
		return $out;
	}

	/** [inicio, fin) en segundos UNIX de los días de Cuba desde..hasta (incluido), o null. */
	public static function range( string $desde, string $hasta ): ?array {
		$tz = wp_timezone();
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', $desde, $tz );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', $hasta, $tz );
		if ( ! $a || ! $b || $a->format( 'Y-m-d' ) !== $desde || $b->format( 'Y-m-d' ) !== $hasta || $b < $a ) { return null; }
		$b = $b->modify( '+1 day' );
		if ( ( $b->getTimestamp() - $a->getTimestamp() ) > self::MAX_DAYS * 86400 + 7200 ) { return null; }
		return array( $a->getTimestamp(), $b->getTimestamp() - 1 );
	}

	/** Pedidos completados (entregados y cobrados) en esos días, sin pruebas, listos para CVD_Contabilidad_Math. */
	public static function collect( int $from, int $to ): array {
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => array( 'completed' ), 'date_completed' => $from . '...' . $to ) );
		$out = array();
		foreach ( $orders as $order ) {
			if ( $order->get_meta( '_cvd_test_order' ) ) { continue; }
			$lines = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
				$stamped = '' !== (string) $item->get_meta( self::AT, true );
				$product = $item->get_product();
				if ( $stamped ) {
					$raw = $item->get_meta( self::COST, true );
					$cost = '' !== (string) $raw && is_numeric( $raw ) ? (float) $raw : null;
					$kind = (string) $item->get_meta( self::KIND, true );
				} else {
					$cost = $product ? CVD_Costs::cost( $product ) : null;
					$kind = CVD_Contabilidad_Math::line_kind( $product ? (string) $product->get_sku() : '' );
				}
				$lines[] = array( 'kind' => $kind, 'total' => (float) $item->get_total(), 'qty' => (int) $item->get_quantity(), 'cost_unit' => $cost, 'stamped' => $stamped );
			}
			$status = (string) $order->get_meta( '_cvd_commission_status' );
			$done = $order->get_date_completed();
			$out[] = array(
				'id' => $order->get_id(),
				'dia' => $done ? wp_date( 'Y-m-d', $done->getTimestamp() ) : '',
				'commission' => 'cancelled' === $status ? 0.0 : (float) $order->get_meta( '_cvd_commission_amount' ),
				'commission_status' => $status,
				'markup' => (float) $order->get_meta( '_cvd_margin_amount' ),
				'lines' => $lines,
			);
		}
		return $out;
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/bot/contabilidad', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_summary' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	public static function rest_summary( WP_REST_Request $request ): WP_REST_Response {
		$today = wp_date( 'Y-m-d' );
		$desde = sanitize_text_field( (string) ( $request->get_param( 'desde' ) ?: $today ) );
		$hasta = sanitize_text_field( (string) ( $request->get_param( 'hasta' ) ?: $desde ) );
		$range = self::range( $desde, $hasta );
		if ( ! $range ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'fechas no válidas (AAAA-MM-DD, máx. ' . self::MAX_DAYS . ' días)' ), 400 ); }
		$orders = self::collect( $range[0], $range[1] );
		$dep = (float) get_option( 'cvd_conta_dependientas_pct', 0.5 ) / 100;
		$ern = (float) get_option( 'cvd_conta_ernesto_pct', 1.5 ) / 100;
		$sum = CVD_Contabilidad_Math::summarize( $orders, $dep, $ern );
		if ( $request->get_param( 'detalle' ) ) {
			$sum['por_dia'] = CVD_Contabilidad_Math::by_day( $orders );
			$sum['comisiones'] = CVD_Contabilidad_Math::commission_by_status( $orders );
			$sum['reparto_bajo_pedido'] = self::supplier_splits( $orders );
		}
		return new WP_REST_Response( array( 'ok' => true, 'desde' => $desde, 'hasta' => $hasta, 'pedidos' => count( $orders ), 'porcentajes' => array( 'dependientas' => $dep * 100, 'ernesto' => $ern * 100 ) ) + $sum, 200 );
	}

	/** 3.14.126: reparto («Reparto de la venta de proveedor», D69) de cada pedido con bajo pedido de la lista. */
	private static function supplier_splits( array $orders ): array {
		if ( ! class_exists( 'CVD_Supplier_Split' ) ) { return array(); }
		$rows = array();
		foreach ( $orders as $o ) {
			if ( ! in_array( CVD_Contabilidad_Math::BAJO_PEDIDO, array_column( (array) $o['lines'], 'kind' ), true ) ) { continue; }
			$order = wc_get_order( $o['id'] );
			$s = $order ? CVD_Supplier_Split::compute( $order ) : null;
			if ( ! $s || empty( $s['applies'] ) ) { continue; }
			$rows[] = array_intersect_key( $s, array_flip( array( 'number', 'gestora', 'supplier', 'webUsd', 'costUsd', 'rate', 'marginUsd', 'gestoraUsd', 'messengerCup', 'clerkCup', 'storeCup', 'specialCup', 'saved' ) ) )
				+ array( 'dia' => $o['dia'], 'products' => implode( ', ', array_map( static fn( $l ) => ( $l['qty'] > 1 ? $l['qty'] . '× ' : '' ) . $l['name'], (array) $s['lines'] ) ) );
		}
		return $rows;
	}
}
