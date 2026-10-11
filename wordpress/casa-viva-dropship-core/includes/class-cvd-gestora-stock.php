<?php

defined( 'ABSPATH' ) || exit;

/**
 * Existencias exactas para las gestoras (pedido de Dany, 11-oct): en la lista de la tienda cada
 * producto dice "Quedan N" (y por color si tiene varios) y en la ficha la cifra exacta. Los
 * clientes y visitantes siguen viendo "Disponible / Últimas unidades / Agotado".
 */
final class CVD_Gestora_Stock {
	public static function register(): void {
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_badge' ), 15 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'exact_text' ), 30, 2 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ) );
	}

	private static function is_gestora(): bool {
		static $is = null;
		if ( null === $is ) {
			$user = wp_get_current_user();
			$is = $user->exists() && CVD_Registration::is_approved_gestora( $user );
		}
		return $is;
	}

	public static function loop_badge(): void {
		global $product;
		if ( ! self::is_gestora() || ! $product instanceof WC_Product ) { return; }
		if ( ! $product->is_in_stock() ) { echo '<p class="cvd-gestora-stock is-out">Agotado</p>'; return; }
		$parts = array();
		$total = null;
		if ( $product->is_type( 'variable' ) ) {
			$total = 0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || ! $variation->managing_stock() ) { continue; }
				$qty = max( 0, (int) $variation->get_stock_quantity() );
				$total += $qty;
				if ( $qty > 0 ) { $parts[] = wc_get_formatted_variation( $variation, true, false, false ) . ' ' . $qty; }
			}
			if ( ! $parts && ! $product->managing_stock() ) { $total = null; }
		} elseif ( $product->managing_stock() ) {
			$total = max( 0, (int) $product->get_stock_quantity() );
		}
		if ( null === $total ) { echo '<p class="cvd-gestora-stock">Disponible</p>'; return; }
		$label = 1 === $total ? 'Queda 1' : 'Quedan ' . $total;
		$detail = $parts && count( $parts ) > 1 ? '<small>' . esc_html( implode( ' · ', array_slice( $parts, 0, 4 ) ) . ( count( $parts ) > 4 ? ' · …' : '' ) ) . '</small>' : '';
		echo '<p class="cvd-gestora-stock' . ( $total <= 2 ? ' is-low' : '' ) . '">' . esc_html( $label ) . $detail . '</p>';
	}

	/** En la ficha del producto (y al elegir un color): la cifra exacta para la gestora. */
	public static function exact_text( $text, $product ) {
		if ( ! self::is_gestora() || ! $product instanceof WC_Product || ! class_exists( 'CVD_Product_Search' ) ) { return $text; }
		return CVD_Product_Search::availability( $product, true );
	}

	public static function styles(): void {
		if ( ! self::is_gestora() ) { return; }
		echo '<style id="cvd-gestora-stock">.cvd-gestora-stock{margin:4px 0 6px;font-size:13px;font-weight:600;color:#0f3d3a}.cvd-gestora-stock small{display:block;font-weight:400;color:#6f6a62;font-size:12px;line-height:1.35}.cvd-gestora-stock.is-low{color:#a8641c}.cvd-gestora-stock.is-out{color:#a83d32}</style>';
	}
}
