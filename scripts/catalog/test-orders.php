<?php
/**
 * Pedidos de prueba: listar los recientes o cancelar los indicados (repone stock vía WooCommerce).
 * Uso: CVD_ORDERS_MODE=orders-list|orders-cancel CVD_ORDERS=2608,2609 wp eval-file test-orders.php
 */
$mode = getenv( 'CVD_ORDERS_MODE' ) ?: 'orders-list';
$ids  = array_filter( array_map( 'absint', explode( ',', (string) getenv( 'CVD_ORDERS' ) ) ) );
$row  = static function ( WC_Order $o ): array {
	$items = array();
	foreach ( $o->get_items() as $item ) {
		$p = $item->get_product();
		$items[] = array( 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'sku' => $p ? $p->get_sku() : '', 'stock' => $p && $p->managing_stock() ? $p->get_stock_quantity() : null );
	}
	return array(
		'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => $o->get_status(),
		'created' => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : '',
		'name' => $o->get_formatted_billing_full_name(), 'phone' => $o->get_billing_phone(), 'email' => $o->get_billing_email(),
		'customer_id' => $o->get_customer_id(), 'payment' => $o->get_payment_method(), 'via' => $o->get_created_via(),
		'total' => $o->get_total(), 'stock_reduced' => (bool) $o->get_data_store()->get_stock_reduced( $o->get_id() ), 'items' => $items,
	);
};
$out = array( 'mode' => $mode, 'orders' => array(), 'cancelled' => array(), 'errors' => array() );
if ( 'orders-cancel' === $mode ) {
	foreach ( $ids as $id ) {
		$o = wc_get_order( $id );
		if ( ! $o ) { $out['errors'][] = array( 'id' => $id, 'error' => 'no existe' ); continue; }
		if ( ! in_array( $o->get_status(), array( 'pending', 'on-hold', 'processing' ), true ) ) { $out['errors'][] = array( 'id' => $id, 'error' => 'estado ' . $o->get_status() . ', no se toca' ); continue; }
		$o->update_status( 'cancelled', 'Pedido de prueba cancelado a petición de Ernesto (2026-10-02).' );
		$out['cancelled'][] = $row( wc_get_order( $id ) );
	}
} else {
	foreach ( wc_get_orders( array( 'limit' => 40, 'orderby' => 'date', 'order' => 'DESC', 'date_created' => '>' . ( time() - 7 * DAY_IN_SECONDS ) ) ) as $o ) {
		$out['orders'][] = $row( $o );
	}
}
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), "\n";
