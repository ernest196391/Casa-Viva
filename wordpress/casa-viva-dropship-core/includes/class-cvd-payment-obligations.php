<?php

defined( 'ABSPATH' ) || exit;

/** Contrato canónico de obligaciones y débitos internos por pedido. */
final class CVD_Payment_Obligations {
	private const META = '_cvd_payment_obligations';
	private const VERSION_META = '_cvd_payment_obligations_version';
	private const CURRENCIES = array( 'USD', 'CUP', 'EUR' );
	private const CONCEPTS = array( 'products', 'delivery', 'other' );
	private const PAYERS = array( 'customer', 'gestora', 'casa_viva' );
	private const METHODS = array( 'cash_usd', 'cash_cup', 'transfer', 'commission_deduction', 'other' );

	public static function register(): void {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_fields' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_admin_fields' ), 30, 2 );
	}

	public static function for_order( WC_Order $order ): array {
		$value = $order->get_meta( self::META, true );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	public static function customer_collectible( WC_Order $order ): array {
		return array_values( array_filter( self::for_order( $order ), static fn( array $row ): bool => 'customer' === ( $row['payer'] ?? '' ) && 'pending' === ( $row['status'] ?? 'pending' ) && 'commission_deduction' !== ( $row['method'] ?? '' ) ) );
	}

	/** Guarda una instantánea validada; devuelve WP_Error ante cualquier ambigüedad. */
	public static function configure( WC_Order $order, array $rows, int $actor_id ) {
		$normalized = array(); $seen = array(); $delivery_cup = 0.0;
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) ?: $order->get_meta( '_cvd_pricing_gestora_user_id', true ) );
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) { return new WP_Error( 'cvd_obligation_shape', 'Cada obligación debe ser estructurada.' ); }
			$id = sanitize_key( (string) ( $row['id'] ?? 'obligation-' . ( $index + 1 ) ) );
			$concept = sanitize_key( (string) ( $row['concept'] ?? '' ) ); $payer = sanitize_key( (string) ( $row['payer'] ?? '' ) ); $method = sanitize_key( (string) ( $row['method'] ?? '' ) );
			$currency = strtoupper( sanitize_key( (string) ( $row['currency'] ?? '' ) ) ); $amount = (float) wc_format_decimal( $row['amount'] ?? 0, 2 );
			$payer_user_id = absint( $row['payer_user_id'] ?? 0 );
			if ( ! $id || isset( $seen[ $id ] ) || $amount <= 0 || ! in_array( $concept, self::CONCEPTS, true ) || ! in_array( $payer, self::PAYERS, true ) || ! in_array( $method, self::METHODS, true ) || ! in_array( $currency, self::CURRENCIES, true ) ) { return new WP_Error( 'cvd_obligation_invalid', 'Revisa concepto, importe, moneda, pagador y medio.' ); }
			if ( 'commission_deduction' === $method && ( 'gestora' !== $payer || ! $owner_id || $payer_user_id !== $owner_id ) ) { return new WP_Error( 'cvd_obligation_owner', 'El descuento debe pertenecer a la gestora canónica del pedido.' ); }
			if ( 'gestora' === $payer && $payer_user_id !== $owner_id ) { return new WP_Error( 'cvd_obligation_owner', 'El pagador no coincide con la gestora canónica del pedido.' ); }
			if ( 'delivery' === $concept && 'CUP' === $currency ) { $delivery_cup += $amount; }
			$normalized[] = array( 'id' => $id, 'concept' => $concept, 'amount' => wc_format_decimal( $amount, 2 ), 'currency' => $currency, 'payer' => $payer, 'payer_user_id' => $payer_user_id, 'method' => $method, 'status' => 'pending', 'settled_at' => '', 'settled_by' => 0, 'settlement_reference' => '' ); $seen[ $id ] = true;
		}
		$shipping = class_exists( 'CVD_Shipping_Rates' ) ? (float) CVD_Shipping_Rates::order_fee( $order ) : (float) $order->get_meta( '_cvd_shipping_fee_cup', true );
		if ( $normalized && $shipping > 0 && abs( $delivery_cup - $shipping ) > 0.009 ) { return new WP_Error( 'cvd_obligation_total', 'Las obligaciones de mensajería CUP deben sumar la tarifa canónica.' ); }
		$order->update_meta_data( self::META, $normalized ); $order->update_meta_data( self::VERSION_META, '1' );
		$order->update_meta_data( '_cvd_payment_obligations_configured_by', $actor_id ); $order->update_meta_data( '_cvd_payment_obligations_configured_at', current_time( 'mysql', true ) );
		return $normalized;
	}

	/** Valida y aplica asignaciones del mensajero sin aceptar importes no previstos. */
	public static function validate_customer_allocations( WC_Order $order, array $allocations ) {
		$expected = array(); foreach ( self::customer_collectible( $order ) as $row ) { $expected[ $row['id'] ] = $row; }
		$submitted = array(); foreach ( $allocations as $allocation ) { $id = sanitize_key( (string) ( $allocation['id'] ?? '' ) ); $amount = wc_format_decimal( $allocation['amount'] ?? 0, 2 ); if ( ! isset( $expected[ $id ] ) || (float) $amount <= 0 || abs( (float) $amount - (float) $expected[ $id ]['amount'] ) > 0.009 ) { return new WP_Error( 'cvd_collection_allocation', 'El cobro no coincide con las obligaciones del pedido.' ); } $submitted[ $id ] = $amount; }
		if ( count( $submitted ) !== count( $expected ) ) { return new WP_Error( 'cvd_collection_missing', 'Registra todas las obligaciones que corresponden al cliente.' ); }
		return $submitted;
	}

	public static function settle_customer_allocations( WC_Order $order, array $allocations, int $actor_id, string $at ) {
		$rows = self::for_order( $order ); if ( ! $rows ) { return true; }
		$submitted = self::validate_customer_allocations( $order, $allocations ); if ( is_wp_error( $submitted ) ) { return $submitted; }
		foreach ( $rows as &$row ) { if ( isset( $submitted[ $row['id'] ] ) ) { $row['status'] = 'settled'; $row['settled_at'] = $at; $row['settled_by'] = $actor_id; $row['settlement_reference'] = 'delivery:' . $order->get_id(); } } unset( $row );
		$order->update_meta_data( self::META, $rows ); return true;
	}

	/** Publica una sola vez los débitos de comisión al libro de la gestora. */
	public static function post_commission_deductions( WC_Order $order, int $actor_id, string $at ): array {
		global $wpdb; $events = array(); $rows = self::for_order( $order ); if ( ! $rows ) { return $events; }
		$table = $wpdb->prefix . 'cvd_owner_financial_ledger';
		foreach ( $rows as &$row ) {
			if ( 'commission_deduction' !== ( $row['method'] ?? '' ) || 'pending' !== ( $row['status'] ?? 'pending' ) ) { continue; }
			$data = array( 'entry_uuid' => wp_generate_uuid4(), 'owner_user_id' => absint( $row['payer_user_id'] ?? 0 ), 'order_id' => $order->get_id(), 'obligation_id' => (string) $row['id'], 'entry_type' => 'commission_deduction', 'amount' => (string) $row['amount'], 'currency' => (string) $row['currency'], 'status' => 'open', 'created_at' => $at, 'created_by' => $actor_id, 'metadata' => wp_json_encode( array( 'concept' => $row['concept'], 'contract_version' => 1 ) ) );
			$inserted = $wpdb->insert( $table, $data );
			if ( false === $inserted && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id=%d AND obligation_id=%s AND entry_type='commission_deduction'", $order->get_id(), $row['id'] ) ) ) { throw new RuntimeException( 'No se pudo registrar el descuento de comisión.' ); }
			$row['status'] = 'settled'; $row['settled_at'] = $at; $row['settled_by'] = $actor_id; $row['settlement_reference'] = 'owner-ledger:' . $order->get_id() . ':' . $row['id'];
			$events[] = array( 'domain' => 'payment', 'from' => 'pending', 'to' => 'settled', 'metadata' => array( 'obligation_id' => $row['id'], 'amount' => $row['amount'], 'currency' => $row['currency'], 'payer' => 'gestora', 'method' => 'commission_deduction' ) );
		}
		unset( $row ); $order->update_meta_data( self::META, $rows ); return $events;
	}

	public static function open_debits_by_currency( int $owner_id ): array {
		global $wpdb; $table = $wpdb->prefix . 'cvd_owner_financial_ledger'; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT currency,SUM(amount) amount FROM {$table} WHERE owner_user_id=%d AND entry_type='commission_deduction' AND status='open' GROUP BY currency", $owner_id ), ARRAY_A ); $result = array(); foreach ( $rows as $row ) { $result[ $row['currency'] ] = (float) $row['amount']; } return $result;
	}

	public static function admin_fields( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; } $rows = self::for_order( $order ); $customer = 0; $deduction = 0;
		foreach ( $rows as $row ) { if ( 'delivery' !== ( $row['concept'] ?? '' ) || 'CUP' !== ( $row['currency'] ?? '' ) ) { continue; } if ( 'customer' === ( $row['payer'] ?? '' ) ) { $customer += (float) $row['amount']; } if ( 'commission_deduction' === ( $row['method'] ?? '' ) ) { $deduction += (float) $row['amount']; } }
		$method = 'cash_cup'; foreach ( $rows as $row ) { if ( 'customer' === ( $row['payer'] ?? '' ) && 'delivery' === ( $row['concept'] ?? '' ) ) { $method = (string) $row['method']; break; } }
		echo '<div class="order_data_column"><h4>Plan de pago de mensajería</h4><p class="form-field"><label>Cliente paga (CUP)</label><input name="cvd_delivery_customer_cup" type="number" min="0" step="0.01" value="' . esc_attr( $customer ?: '' ) . '"></p><p class="form-field"><label>Medio del cliente</label><select name="cvd_delivery_customer_method"><option value="cash_cup" ' . selected( $method, 'cash_cup', false ) . '>Efectivo CUP</option><option value="transfer" ' . selected( $method, 'transfer', false ) . '>Transferencia</option><option value="other" ' . selected( $method, 'other', false ) . '>Otro verificado</option></select></p><p class="form-field"><label>Descuento comisión gestora (CUP)</label><input name="cvd_delivery_gestora_cup" type="number" min="0" step="0.01" value="' . esc_attr( $deduction ?: '' ) . '"></p><p class="description">Ambos importes deben sumar la tarifa canónica. No se convierten monedas.</p></div>';
	}

	public static function save_admin_fields( int $order_id, $post ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['cvd_delivery_customer_cup'], $_POST['cvd_delivery_gestora_cup'] ) ) { return; } $order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		if ( in_array( sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ), array( 'delivered', 'cash_returned', 'closed' ), true ) ) { return; }
		$customer = (float) wc_format_decimal( wp_unslash( $_POST['cvd_delivery_customer_cup'] ), 2 ); $deduction = (float) wc_format_decimal( wp_unslash( $_POST['cvd_delivery_gestora_cup'] ), 2 ); if ( $customer <= 0 && $deduction <= 0 ) { return; }
		$customer_method = sanitize_key( wp_unslash( $_POST['cvd_delivery_customer_method'] ?? 'cash_cup' ) ); if ( ! in_array( $customer_method, array( 'cash_cup', 'transfer', 'other' ), true ) ) { $customer_method = 'cash_cup'; }
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) ?: $order->get_meta( '_cvd_pricing_gestora_user_id', true ) ); $rows = array();
		if ( $customer > 0 ) { $rows[] = array( 'id' => 'delivery-customer-cup', 'concept' => 'delivery', 'amount' => $customer, 'currency' => 'CUP', 'payer' => 'customer', 'method' => $customer_method ); }
		if ( $deduction > 0 ) { $rows[] = array( 'id' => 'delivery-gestora-cup', 'concept' => 'delivery', 'amount' => $deduction, 'currency' => 'CUP', 'payer' => 'gestora', 'payer_user_id' => $owner_id, 'method' => 'commission_deduction' ); }
		$result = self::configure( $order, $rows, get_current_user_id() ); if ( is_wp_error( $result ) ) { $order->add_order_note( 'Plan de pago no guardado: ' . $result->get_error_message() ); return; } $order->save();
		if ( class_exists( 'CVD_Order_Events' ) ) { CVD_Order_Events::record( array( 'order_id' => $order_id, 'event_type' => 'payment.obligations_configured', 'domain' => 'payment', 'from_state' => '', 'to_state' => 'configured', 'actor_user_id' => get_current_user_id(), 'source' => 'woocommerce_admin', 'metadata' => array( 'version' => 1, 'count' => count( $rows ) ), 'idempotency_key' => 'payment-plan:' . $order_id . ':' . hash( 'sha256', wp_json_encode( $rows ) ) ) ); }
	}
}
