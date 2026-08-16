<?php

defined( 'ABSPATH' ) || exit;

/** Presentación financiera de la gestora basada exclusivamente en pedidos WooCommerce ya atribuidos. */
final class CVD_Gestora_Financial_View {
	public static function register(): void {
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'filter_portal' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
	}

	public static function assets(): void {
		if ( ! is_page( 'area-gestoras' ) ) {
			return;
		}
		wp_enqueue_style(
			'cvd-gestora-financial-view',
			CVD_URL . 'assets/gestora-financial-view.css',
			array( 'cvd-portal' ),
			CVD_VERSION
		);
	}

	public static function filter_portal( string $output, string $tag, array $attr, array $match ): string {
		unset( $match );
		if ( 'casa_viva_portal' !== $tag || ! is_user_logged_in() ) {
			return $output;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || 'gestora' !== CVD_Registration::program_type( $user ) || ! CVD_Registration::is_approved_gestora( $user ) ) {
			return $output;
		}
		if ( isset( $attr['role'] ) && 'mensajero' === sanitize_key( (string) $attr['role'] ) ) {
			return $output;
		}

		$orders = wc_get_orders( array(
			'limit'      => -1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_key'   => '_cvd_owner_user_id',
			'meta_value' => $user->ID,
		) );
		$clients = self::linked_client_count( $orders );
		$summary = self::summary_html( $orders, $clients );
		$history = '<section class="cvd-panel cvd-history-panel" id="ventas"><span id="comisiones"></span><h2>Historial de ventas y comisiones</h2>' . self::history_html( array_slice( $orders, 0, 100 ) ) . '</section>';

		$output = preg_replace(
			'~<div class="cvd-stats" id="dashboard">.*?</div>(?=\s*<section class="cvd-panel" id="catalogo">)~s',
			$summary,
			$output,
			1
		) ?: $output;
		$output = preg_replace(
			'~<section class="cvd-panel cvd-history-panel" id="ventas">.*?</section>~s',
			$history,
			$output,
			1
		) ?: $output;
		return $output;
	}

	public static function summary_html( array $orders, int $clients ): string {
		$totals = array( 'pending' => array(), 'approved' => array(), 'paid' => array() );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( ! isset( $totals[ $status ] ) ) { continue; }
			$currency = strtoupper( sanitize_key( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) ) );
			$currency = $currency ?: get_woocommerce_currency();
			$totals[ $status ][ $currency ] = ( $totals[ $status ][ $currency ] ?? 0.0 ) + (float) $order->get_meta( '_cvd_commission_amount', true );
		}

		return '<div class="cvd-stats" id="dashboard">'
			. self::summary_card( 'Por verificar', $totals['pending'], 'No es saldo ganado todavía' )
			. self::summary_card( 'Aprobada', $totals['approved'], 'Venta validada por Casa Viva' )
			. self::summary_card( 'Pagada', $totals['paid'], 'Liquidación completada' )
			. '<article id="clientes"><span>Clientes vinculados</span><strong>' . esc_html( (string) $clients ) . '</strong><small>Excluye pedidos anulados</small></article>'
			. '</div>';
	}

	private static function summary_card( string $label, array $amounts, string $note ): string {
		$html = '<article><span>' . esc_html( $label ) . '</span><strong class="cvd-multicurrency-total">';
		if ( ! $amounts ) {
			$currency = get_woocommerce_currency();
			$html .= '<span class="cvd-money-line" data-currency="' . esc_attr( $currency ) . '">' . wp_kses_post( wc_price( 0, array( 'currency' => $currency ) ) ) . ' <small>' . esc_html( $currency ) . '</small></span>';
		} else {
			ksort( $amounts );
			$parts = array();
			foreach ( $amounts as $currency => $amount ) {
				$parts[] = '<span class="cvd-money-line" data-currency="' . esc_attr( $currency ) . '">' . wp_kses_post( wc_price( $amount, array( 'currency' => $currency ) ) ) . ' <small>' . esc_html( $currency ) . '</small></span>';
			}
			$html .= implode( '<span class="cvd-money-separator"> · </span>', $parts );
		}
		return $html . '</strong><small>' . esc_html( $note ) . '</small></article>';
	}

	public static function history_html( array $orders ): string {
		if ( ! $orders ) {
			return '<p>Todavía no hay pedidos vinculados a tu cuenta.</p>';
		}
		$labels = array( 'pending' => 'Por verificar', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'cancelled' => 'Cancelada' );
		$html = '<div class="cvd-table-wrap"><table><thead><tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Producto</th><th>Importe</th><th>Comisión base</th><th>Margen propio</th><th>Total</th><th>Regla aplicada</th><th>Estado</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$products = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$products[] = $item->get_name() . ' × ' . max( 1, (int) $item->get_quantity() );
			}
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			$currency = strtoupper( (string) $order->get_currency() );
			$base = (float) $order->get_meta( '_cvd_base_commission_amount', true );
			$margin = (float) $order->get_meta( '_cvd_margin_amount', true );
			$total = (float) $order->get_meta( '_cvd_commission_amount', true );
			$rules = self::human_rules( $order );
			$html .= '<tr><td>#' . esc_html( $order->get_order_number() ) . '</td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td><td>' . esc_html( implode( ', ', $products ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . self::money_with_code( $base, $currency ) . '</td><td>' . self::money_with_code( $margin, $currency ) . '</td><td><strong>' . self::money_with_code( $total, $currency ) . '</strong></td><td>' . esc_html( $rules ) . '</td><td><span class="cvd-badge">' . esc_html( $labels[ $status ] ?? ucfirst( $status ) ) . '</span></td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	private static function money_with_code( float $amount, string $currency ): string {
		$currency = $currency ?: get_woocommerce_currency();
		return wp_kses_post( wc_price( $amount, array( 'currency' => $currency ) ) ) . ' <small>' . esc_html( $currency ) . '</small>';
	}

	private static function human_rules( WC_Order $order ): string {
		$breakdown = $order->get_meta( '_cvd_commission_breakdown', true );
		if ( ! is_array( $breakdown ) ) { return 'Regla guardada en la venta'; }
		$rules = array();
		foreach ( $breakdown as $line ) {
			if ( ! is_array( $line ) ) { continue; }
			$type = sanitize_key( (string) ( $line['type'] ?? '' ) );
			$value = (float) ( $line['value'] ?? 0 );
			$source = sanitize_key( (string) ( $line['policy_source'] ?? '' ) );
			$source_label = in_array( $source, array( 'product', 'parent_product' ), true ) ? 'producto' : 'tasa de gestora';
			$label = 'fixed' === $type
				? wc_format_localized_decimal( $value ) . ' fijo por unidad · ' . $source_label
				: wc_format_localized_decimal( $value ) . '% · ' . $source_label;
			$rules[ $label ] = true;
		}
		return $rules ? implode( '; ', array_keys( $rules ) ) : 'Regla guardada en la venta';
	}

	private static function linked_client_count( array $orders ): int {
		$clients = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { continue; }
			$key = (string) $order->get_meta( '_cvd_identity_phone', true );
			$key = $key ?: (string) $order->get_meta( '_cvd_identity_email', true );
			$key = $key ?: (string) $order->get_meta( '_cvd_identity_customer', true );
			if ( ! $key ) {
				$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
				$email = sanitize_email( strtolower( trim( (string) $order->get_billing_email() ) ) );
				$key = $phone ? 'phone:' . hash( 'sha256', $phone ) : ( $email ? 'email:' . hash( 'sha256', $email ) : '' );
			}
			if ( $key ) { $clients[ $key ] = true; }
		}
		return count( $clients );
	}
}
