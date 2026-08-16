<?php

defined( 'ABSPATH' ) || exit;

function cvt_4d_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL 4D: {$message}\n" );
		exit( 1 );
	}
}

$gestora_id = username_exists( 'cvt_gestora_4d' );
if ( ! $gestora_id ) {
	$gestora_id = wp_create_user( 'cvt_gestora_4d', 'Synthetic-Only-4D!', 'gestora-4d@example.invalid' );
}
cvt_4d_assert( ! is_wp_error( $gestora_id ), 'No se pudo crear gestora 4D.' );
$gestora = new WP_User( (int) $gestora_id );
$gestora->set_role( 'cvd_gestora' );
update_user_meta( $gestora->ID, '_cvd_account_status', 'approved' );
update_user_meta( $gestora->ID, '_cvd_program_type', 'gestora' );

function cvt_4d_order( WP_User $gestora, string $currency, float $base, float $margin, string $type, float $value, string $source ): WC_Order {
	$order = wc_create_order();
	$order->set_currency( $currency );
	$order->set_billing_first_name( 'Cliente' );
	$order->set_billing_last_name( $currency );
	$order->update_meta_data( '_cvd_owner_user_id', $gestora->ID );
	$order->update_meta_data( '_cvd_owner_type', 'gestora' );
	$order->update_meta_data( '_cvd_commission_status', 'approved' );
	$order->update_meta_data( '_cvd_base_commission_amount', $base );
	$order->update_meta_data( '_cvd_margin_amount', $margin );
	$order->update_meta_data( '_cvd_commission_amount', $base + $margin );
	$order->update_meta_data( '_cvd_commission_currency', $currency );
	$order->update_meta_data( '_cvd_commission_breakdown', array( array(
		'type' => $type,
		'value' => $value,
		'policy_source' => $source,
	) ) );
	$order->save();
	return $order;
}

$usd = cvt_4d_order( $gestora, 'USD', 13, 7, 'percent', 13, 'gestora' );
$cup = cvt_4d_order( $gestora, 'CUP', 500, 0, 'fixed', 500, 'product' );

$summary = CVD_Gestora_Financial_View::summary_html( array( $usd, $cup ), 2 );
cvt_4d_assert( false !== strpos( $summary, 'USD' ) && false !== strpos( $summary, 'CUP' ), 'El resumen mezcló o escondió monedas.' );
cvt_4d_assert( false !== strpos( $summary, 'Clientes vinculados' ), 'Falta el contador de clientes.' );

$history = CVD_Gestora_Financial_View::history_html( array( $usd, $cup ) );
cvt_4d_assert( false !== strpos( $history, 'Comisión base' ), 'Falta comisión base.' );
cvt_4d_assert( false !== strpos( $history, 'Margen propio' ), 'Falta margen propio.' );
cvt_4d_assert( false !== strpos( $history, '13% · tasa de gestora' ), 'No se explica la regla porcentual.' );
cvt_4d_assert( false !== strpos( $history, '500 fijo por unidad · producto' ), 'No se explica la regla fija.' );
cvt_4d_assert( false === strpos( $history, '_cvd_' ), 'Se filtraron nombres de metadatos internos al panel.' );

echo "OK 4D: resumen por moneda y desglose auditable de comisiones validados.\n";
