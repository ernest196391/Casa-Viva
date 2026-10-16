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
		$history = '<section class="cvd-panel cvd-history-panel" id="ventas"><span id="comisiones"></span><h2>Tus ventas</h2>' . ( class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::gestora_notice() : '' ) . self::history_html( array_slice( $orders, 0, 100 ) ) . '</section>';

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

	/**
	 * 3.13.68 (12-oct, pedido de Cely): «Tus comisiones», como el recuadro de BizneCubano, con los estados que ya
	 * tiene el Core (no se inventa ninguno):
	 *   Pendientes         = comisión `pending` (venta aún sin entregar ni validar).
	 *   Listas para cobrar = `approved` sin solicitud de pago (lo mismo que «Disponible» en Cobrar).
	 *   Solicitadas        = `approved` con solicitud de pago abierta (`_cvd_payout_id`: requested o approved en cvd_payouts).
	 *   Pagadas            = `paid` (la liquidación se marcó pagada).
	 * Fuera: pedidos de prueba, pedidos anulados y comisiones canceladas. Importes en la moneda guardada (USD); en CUP,
	 * con la tasa guardada en cada pedido el día de la venta (3.13.64). Solo lee: no cambia nada.
	 */
	public static function commission_totals( array $orders ): array {
		$totals = array();
		foreach ( array( 'pending', 'ready', 'requested', 'paid' ) as $key ) {
			$totals[ $key ] = array( 'count' => 0, 'amounts' => array(), 'cup' => 0, 'no_rate' => array() );
		}
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ), true ) ) { continue; }
			if ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) { continue; }
			if ( 'organic' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { continue; }
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'pending' === $status ) { $key = 'pending'; }
			elseif ( 'paid' === $status ) { $key = 'paid'; }
			elseif ( 'approved' === $status ) { $key = absint( $order->get_meta( '_cvd_payout_id', true ) ) ? 'requested' : 'ready'; }
			else { continue; } // cancelled u otro: no cuenta.
			// En centavos, como lo ve la gestora en cada venta (5,00 USD), no con los 4 decimales guardados.
			$amount = round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 );
			$currency = strtoupper( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) ) ?: 'USD';
			$totals[ $key ]['count']++;
			$totals[ $key ]['amounts'][ $currency ] = ( $totals[ $key ]['amounts'][ $currency ] ?? 0.0 ) + $amount;
			if ( $amount <= 0 ) { continue; }
			$cup = 'CUP' === $currency ? (int) round( $amount ) : ( class_exists( 'CVD_Commissions' ) ? CVD_Commissions::commission_cup( $order ) : null );
			if ( null === $cup ) { $totals[ $key ]['no_rate'][ $currency ] = ( $totals[ $key ]['no_rate'][ $currency ] ?? 0.0 ) + $amount; }
			else { $totals[ $key ]['cup'] += $cup; }
		}
		return $totals;
	}

	/** 3.13.68: el recuadro «Tus comisiones» (4 cifras) de una gestora a partir de sus pedidos. */
	public static function commission_box( array $orders, int $user_id ): string {
		$totals = self::commission_totals( $orders );
		$cards = array(
			'pending'   => array( 'Pendientes', 'aún sin entregar' ),
			'ready'     => array( 'Listas para cobrar', 'ya entregadas' ),
			'requested' => array( 'Solicitadas', 'pediste el pago' ),
			'paid'      => array( 'Pagadas', 'ya cobradas' ),
		);
		$html = '<section class="cvd-cbox" aria-label="Tus comisiones"><div class="cvd-cbox-head"><h2>Tus comisiones</h2><p>Todas tus ventas en la web, sin pruebas ni pedidos cancelados.</p></div><div class="cvd-cbox-grid">';
		foreach ( $cards as $key => $card ) {
			$t = $totals[ $key ];
			$count = $t['count'] ? $t['count'] . ( 1 === $t['count'] ? ' venta' : ' ventas' ) . ' · ' . $card[1] : 'Ninguna ahora';
			$html .= '<article class="cvd-cbox-card is-' . esc_attr( $key ) . '"><span class="cvd-cbox-label">' . esc_html( $card[0] ) . '</span>'
				. '<strong class="cvd-cbox-amount">' . esc_html( self::amounts_text( $t['amounts'] ) ) . '</strong>'
				. '<span class="cvd-cbox-cup">' . esc_html( self::cup_text( $t ) ) . '</span>'
				. '<small class="cvd-cbox-count">' . esc_html( $count ) . '</small>';
			if ( 'ready' === $key && $t['count'] ) {
				$html .= '<a class="cvd-cbox-action" href="#cobrar" data-cvga-go="cobrar">Pedir mi pago</a>';
			}
			$html .= '</article>';
		}
		$html .= '</div>';
		// Descuentos abiertos (CVD_Payment_Obligations): se restan al pedir el pago, solo en la misma moneda.
		$debits = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::open_debits_by_currency( $user_id ) : array();
		if ( $debits ) {
			$html .= '<p class="cvd-cbox-note is-warn">Al pedir tu pago se descuentan ' . esc_html( self::amounts_text( $debits ) ) . ' pendientes.</p>';
		}
		return $html . '<p class="cvd-cbox-note">En CUP, con la tasa del día de cada venta. Si una venta no guardó la tasa (las más viejas), va solo en USD.</p></section>';
	}

	/** «12,50 USD» (o «12,50 USD · 300,00 CUP» si hubiera varias monedas); «0,00 USD» si no hay nada. */
	private static function amounts_text( array $amounts ): string {
		if ( ! $amounts ) { return '0,00 ' . ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' ); }
		ksort( $amounts );
		$parts = array();
		foreach ( $amounts as $currency => $amount ) { $parts[] = number_format( (float) $amount, 2, ',', '.' ) . ' ' . $currency; }
		return implode( ' · ', $parts );
	}

	/** Línea en CUP: «9.750 CUP»; con ventas sin tasa, «9.750 CUP y 3,00 USD sin tasa»; si ninguna la tiene, «Solo en USD (sin tasa)». */
	private static function cup_text( array $t ): string {
		if ( ! class_exists( 'CVD_Daily_Rates' ) ) { return ''; }
		if ( ! $t['no_rate'] ) { return CVD_Daily_Rates::format_cup( (int) $t['cup'] ); }
		if ( $t['cup'] <= 0 ) { return 'Solo en USD (sin tasa)'; }
		return CVD_Daily_Rates::format_cup( (int) $t['cup'] ) . ' y ' . self::amounts_text( $t['no_rate'] ) . ' sin tasa';
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
		$html = '<div class="cvd-table-wrap"><table><thead><tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Producto</th><th>Importe</th><th>Comisión</th><th>Lo que subiste</th><th>Ganas</th><th>Cómo se calcula</th><th>Estado</th>' . ( class_exists( 'CVD_Direct_Dispatch' ) ? '<th>Mensajero</th>' : '' ) . '</tr></thead><tbody>';
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
			// 3.13.64 (12-oct, regla de Ernesto): debajo de «Ganas», lo mismo en CUP con la tasa del día de la venta (pedidos viejos: solo USD).
			$cup_text = class_exists( 'CVD_Commissions' ) ? CVD_Commissions::commission_cup_text( $order ) : '';
			// Día de entrega (3.13.51) debajo del número, sin columna nueva (la app de gestoras oculta columnas por posición).
			$day = class_exists( 'CVD_Delivery_When' ) && in_array( $order->get_status(), array( 'pending', 'processing', 'on-hold' ), true ) ? CVD_Delivery_When::order_label( $order ) : '';
			// 3.13.70: armado por el mensajero (aparte del total), debajo del número como el día.
			$asm = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
			$asm_html = $asm ? '<br><small class="cvd-assembly-flag" style="color:#a35a1f;font-weight:700">🔧 Armado: ' . esc_html( CVD_Cuban_Checkout::money( (float) $asm['usd'], 'USD' ) ) . ' aparte, al mensajero</small>' : '';
			$html .= '<tr><td>#' . esc_html( $order->get_order_number() ) . ( $day ? '<br><small class="cvd-delivery-day">📅 ' . esc_html( $day ) . '</small>' : '' ) . $asm_html . '</td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td><td>' . esc_html( implode( ', ', $products ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . self::money_with_code( $base, $currency ) . '</td><td>' . self::money_with_code( $margin, $currency ) . '</td><td><strong>' . self::money_with_code( $total, $currency ) . '</strong>' . ( '' !== $cup_text ? '<br><small class="cvd-commission-cup">(' . esc_html( $cup_text ) . ')</small>' : '' ) . '</td><td>' . esc_html( $rules ) . '</td><td><span class="cvd-badge">' . esc_html( $labels[ $status ] ?? ucfirst( $status ) ) . '</span></td>' . ( class_exists( 'CVD_Direct_Dispatch' ) ? '<td>' . CVD_Direct_Dispatch::gestora_cell( $order ) . '</td>' : '' ) . '</tr>'; // gestora_cell ya escapa
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
			$label = 'extra_tienda' === $type
				? 'tu comisión de lo que tu clienta añadió en la tienda'
				: ( 'fixed' === $type
				? wc_format_localized_decimal( $value ) . ' ' . $order->get_currency() . ' por unidad'
				: wc_format_localized_decimal( $value ) . ' % de la venta' );
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
