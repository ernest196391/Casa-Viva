<?php

defined( 'ABSPATH' ) || exit;

/**
 * Protege la coherencia entre el stock oficial de WooCommerce y el diario
 * auditable de Casa Viva sin convertirse en una segunda fuente de stock.
 */
final class CVD_Inventory_Integrity {
	private const ROUTE_MOVEMENT = '/casa-viva/v1/inventory/movement';
	private const ROUTE_REPORT   = '/casa-viva/v1/inventory/report';
	private const MANUAL_TYPES   = array( 'entry', 'exit', 'loss', 'count' );
	private const ORDER_TYPES    = array( 'sale', 'return' );
	private const EPSILON        = 0.0001;

	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_movement' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'enrich_report' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
	}

	/**
	 * Las ventas y devoluciones pertenecen al ciclo del pedido WooCommerce.
	 * Los ajustes humanos solo pueden alterar stock cuando el saldo actual
	 * coincide con el último saldo auditado, salvo un conteo reconciliador.
	 */
	public static function guard_movement( $response, array $handler, WP_REST_Request $request ) {
		if ( null !== $response || self::ROUTE_MOVEMENT !== $request->get_route() || 'POST' !== $request->get_method() ) {
			return $response;
		}
		if ( ! current_user_can( 'cvd_manage_inventory' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $response;
		}

		$data = (array) $request->get_json_params();
		$type = sanitize_key( (string) ( $data['type'] ?? '' ) );
		if ( in_array( $type, self::ORDER_TYPES, true ) ) {
			return new WP_Error(
				'cvd_order_inventory_only',
				'Las ventas y devoluciones se registran desde el pedido para conservar stock, cobro, comisión e historial en un solo flujo.',
				array( 'status' => 422 )
			);
		}
		if ( ! in_array( $type, self::MANUAL_TYPES, true ) ) {
			return $response;
		}

		$product = wc_get_product( absint( $data['productId'] ?? 0 ) );
		if ( ! $product ) {
			return $response;
		}
		$discrepancy = self::product_discrepancy( $product );
		if ( $discrepancy && 'count' !== $type ) {
			return new WP_Error(
				'cvd_inventory_reconciliation_required',
				'El stock oficial no coincide con el último saldo auditado. Haz primero un conteo físico para reconciliarlo.',
				array(
					'status' => 409,
					'productId' => $product->get_id(),
					'expectedStock' => $discrepancy['expectedStock'],
					'currentStock' => $discrepancy['currentStock'],
				)
			);
		}
		return $response;
	}

	/** Añade diagnóstico operativo al mismo reporte sin exponer datos de clientes. */
	public static function enrich_report( $response, array $handler, WP_REST_Request $request ) {
		if ( self::ROUTE_REPORT !== $request->get_route() || is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$data['integrity'] = self::snapshot();
		if ( ! empty( $data['movements'] ) && is_array( $data['movements'] ) ) {
			foreach ( $data['movements'] as &$movement ) {
				if ( ! empty( $movement['reference'] ) && 'Pedido' === ( $movement['source'] ?? '' ) ) {
					$movement['orderUrl'] = add_query_arg( 'cvd_order', absint( $movement['reference'] ), home_url( '/ventas/' ) );
				}
			}
			unset( $movement );
		}
		$response->set_data( $data );
		return $response;
	}

	/** Estado de reconciliación basado en el último saldo auditado por producto/variación. */
	public static function snapshot(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_inventory_movements';
		$rows = $wpdb->get_results(
			"SELECT movement.*
			 FROM {$table} movement
			 INNER JOIN (
				 SELECT product_id,variation_id,MAX(id) AS latest_id
				 FROM {$table}
				 GROUP BY product_id,variation_id
			 ) latest ON latest.latest_id=movement.id
			 ORDER BY movement.id DESC",
			ARRAY_A
		);
		$discrepancies = array();
		foreach ( $rows as $row ) {
			$lookup_id = absint( $row['variation_id'] ?: $row['product_id'] );
			$product = wc_get_product( $lookup_id );
			if ( ! $product ) {
				continue;
			}
			$expected = (float) $row['stock_after'];
			$current = (float) ( $product->get_stock_quantity() ?? 0 );
			if ( abs( $current - $expected ) <= self::EPSILON ) {
				continue;
			}
			$discrepancies[] = array(
				'productId' => $product->get_id(),
				'product' => $product->get_name(),
				'code' => (string) get_post_meta( $product->get_id(), '_cvd_inventory_code', true ),
				'expectedStock' => $expected,
				'currentStock' => $current,
				'difference' => $current - $expected,
				'lastMovementId' => absint( $row['id'] ),
				'lastMovementAt' => get_date_from_gmt( (string) $row['created_at'], 'd/m/Y H:i' ),
			);
		}
		return array(
			'status' => $discrepancies ? 'reconciliation_required' : 'ok',
			'discrepancyCount' => count( $discrepancies ),
			'discrepancies' => array_slice( $discrepancies, 0, 50 ),
		);
	}

	private static function product_discrepancy( WC_Product $product ): ?array {
		global $wpdb;
		$product_id = $product->get_parent_id() ?: $product->get_id();
		$variation_id = $product->get_parent_id() ? $product->get_id() : 0;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stock_after,id,created_at FROM {$wpdb->prefix}cvd_inventory_movements
				 WHERE product_id=%d AND variation_id=%d ORDER BY id DESC LIMIT 1",
				$product_id,
				$variation_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$expected = (float) $row['stock_after'];
		$current = (float) ( $product->get_stock_quantity() ?? 0 );
		if ( abs( $current - $expected ) <= self::EPSILON ) {
			return null;
		}
		return array( 'expectedStock' => $expected, 'currentStock' => $current, 'lastMovementId' => absint( $row['id'] ) );
	}

	public static function assets(): void {
		if ( ! is_page( 'inventario' ) ) {
			return;
		}
		wp_enqueue_script(
			'cvd-inventory-integrity',
			CVD_URL . 'assets/inventory-integrity.js',
			array( 'cvd-inventory' ),
			CVD_VERSION,
			true
		);
	}
}
