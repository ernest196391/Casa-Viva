<?php

defined( 'ABSPATH' ) || exit;

function cvt_split_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

global $wpdb;
$fixture = get_option( 'cvt_integration_fixture' );
$messenger_id = absint( $fixture['messenger_id'] ?? 0 );
$gestora_id = username_exists( 'cvt_split_gestora' );
if ( ! $gestora_id ) { $gestora_id = wp_create_user( 'cvt_split_gestora', 'Synthetic-Split-Only!', 'split-gestora@example.invalid' ); }
cvt_split_assert( ! is_wp_error( $gestora_id ) && $messenger_id > 0, 'Faltan actores sintéticos.' );
$gestora = new WP_User( (int) $gestora_id ); $gestora->set_role( 'cvd_gestora' ); update_user_meta( $gestora->ID, '_cvd_account_status', 'approved' );
$gestora_id = $gestora->ID;

$order = wc_create_order();
cvt_split_assert( $order instanceof WC_Order, 'No se pudo crear el pedido dividido.' );
$order->set_currency( 'USD' );
$order->update_meta_data( '_cvd_owner_user_id', $gestora_id );
$order->update_meta_data( '_cvd_shipping_fee_cup', '3500' );
$order->save();

$plan = CVD_Payment_Obligations::configure( $order, array(
	array( 'id'=>'delivery-customer-cup', 'concept'=>'delivery', 'amount'=>1500, 'currency'=>'CUP', 'payer'=>'customer', 'method'=>'cash_cup' ),
	array( 'id'=>'delivery-gestora-cup', 'concept'=>'delivery', 'amount'=>2000, 'currency'=>'CUP', 'payer'=>'gestora', 'payer_user_id'=>$gestora_id, 'method'=>'commission_deduction' ),
), $gestora_id );
cvt_split_assert( ! is_wp_error( $plan ) && 2 === count( $plan ), 'El plan 1500 + 2000 no fue aceptado.' );
$invalid = CVD_Payment_Obligations::configure( $order, array( array( 'id'=>'bad', 'concept'=>'delivery', 'amount'=>3499, 'currency'=>'CUP', 'payer'=>'customer', 'method'=>'cash_cup' ) ), $gestora_id );
cvt_split_assert( is_wp_error( $invalid ) && 'cvd_obligation_total' === $invalid->get_error_code(), 'Core aceptó un total distinto de la tarifa.' );

$settled = CVD_Payment_Obligations::settle_customer_allocations( $order, array( array( 'id'=>'delivery-customer-cup', 'amount'=>1500 ) ), $messenger_id, current_time( 'mysql', true ) );
cvt_split_assert( true === $settled, 'No se pudo liquidar el cobro del cliente.' );
$rows = CVD_Payment_Obligations::for_order( $order );
cvt_split_assert( 'settled' === $rows[0]['status'] && 'pending' === $rows[1]['status'], 'Se mezclaron las fronteras cliente/gestora.' );

$events = CVD_Payment_Obligations::post_commission_deductions( $order, $gestora_id, current_time( 'mysql', true ) );
cvt_split_assert( 1 === count( $events ), 'No se publicó el débito interno.' );
CVD_Payment_Obligations::post_commission_deductions( $order, $gestora_id, current_time( 'mysql', true ) );
$ledger_table = $wpdb->prefix . 'cvd_owner_financial_ledger';
$debits = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE order_id=%d", $order->get_id() ), ARRAY_A );
cvt_split_assert( 1 === count( $debits ) && 2000.0 === (float) $debits[0]['amount'] && 'CUP' === $debits[0]['currency'] && 'open' === $debits[0]['status'], 'El débito no es idempotente o perdió moneda/importe.' );

// Un crédito CUP separado demuestra que no se convierte la comisión USD del pedido dividido.
$credit = wc_create_order(); $credit->set_currency( 'CUP' ); $credit->update_meta_data( '_cvd_owner_user_id', $gestora_id ); $credit->update_meta_data( '_cvd_commission_status', 'approved' ); $credit->update_meta_data( '_cvd_commission_amount', '3500' ); $credit->update_meta_data( '_cvd_base_commission_amount', '3500' ); $credit->update_meta_data( '_cvd_margin_amount', '0' ); $credit->save();
update_user_meta( $gestora_id, '_cvd_payout_method', 'cash' );
$key = hash( 'sha256', wp_salt( 'auth' ), true ); $iv = random_bytes( 12 ); $tag = ''; $cipher = openssl_encrypt( 'synthetic-split-account', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag ); update_user_meta( $gestora_id, '_cvd_payout_account', base64_encode( $iv . $tag . $cipher ) );
$requested = CVD_Payouts::request( $gestora_id );
cvt_split_assert( ! is_wp_error( $requested ) && 1 === $requested, 'No se creó la liquidación neta.' );
$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payouts WHERE owner_user_id=%d ORDER BY id DESC LIMIT 1", $gestora_id ), ARRAY_A );
cvt_split_assert( $payout && 1500.0 === (float) $payout['amount'] && 'CUP' === $payout['currency'], 'La liquidación no neteó 3500 - 2000 en CUP.' );
$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$ledger_table} WHERE order_id=%d", $order->get_id() ) );
cvt_split_assert( 'reserved' === $status, 'El débito no quedó reservado en la liquidación.' );

wp_set_current_user( absint( username_exists( 'cvt_admin' ) ) );
$rejected = CVD_Payouts::transition( absint( $payout['id'] ), 'reject' );
cvt_split_assert( true === $rejected, 'No se pudo rechazar la liquidación sintética.' );
$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$ledger_table} WHERE order_id=%d", $order->get_id() ) );
cvt_split_assert( 'open' === $status, 'El rollback no liberó el débito.' );

echo wp_json_encode( array( 'order_id'=>$order->get_id(), 'customer_cup'=>1500, 'gestora_cup'=>2000, 'net_payout_cup'=>1500 ) ) . PHP_EOL;
