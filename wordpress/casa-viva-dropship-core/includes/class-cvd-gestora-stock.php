<?php

defined( 'ABSPATH' ) || exit;

/**
 * Existencias exactas para las gestoras (pedido de Dany, 11-oct): en la lista de la tienda cada
 * producto dice "Quedan N" (y por color si tiene varios) y en la ficha la cifra exacta. Los
 * clientes y visitantes siguen viendo "Disponible / Últimas unidades / Agotado".
 * 3.14.63 (maratón 24-oct, Ernesto): «Quedan N» y «Agotado · vuelve el …» también para clientes (CVD_Datos_A_Mano).
 * 3.13.51: junto al precio en USD, su equivalente en CUP con la tasa del día ("7 USD · 5.460 CUP"),
 * solo para gestoras aprobadas. El precio del cliente no cambia.
 * 3.14.21 (Ernesto, 15-oct): «Ganas X USD» por unidad en la lista, en la ficha y en «Fotos para publicar»:
 * comisión del producto (fija o %; si no tiene, la de la gestora o la general) + lo que ella subió el precio.
 */
final class CVD_Gestora_Stock {
	public static function register(): void {
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_badge' ), 15 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'exact_text' ), 30, 2 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'cup_price' ), 40, 2 );
		// 3.14.22 (Ernesto/Lennys, 15-oct): «Ganas X USD» ya NO sale en la tienda ni en la ficha (la gestora enseña el
		// móvil o manda capturas y el cliente lo veía). Solo en su área: «Fotos para publicar» e Inicio.
	}

	/** Precio base de Casa Viva (sin el de la gestora): el del producto o el menor de sus colores. */
	private static function base_price( WC_Product $product ): float {
		$base = (float) get_post_meta( $product->get_id(), '_price', true );
		if ( $base > 0 || ! $product->is_type( 'variable' ) ) { return $base; }
		$prices = array();
		foreach ( $product->get_children() as $id ) { $v = (float) get_post_meta( $id, '_price', true ); if ( $v > 0 ) { $prices[] = $v; } }
		return $prices ? min( $prices ) : 0.0;
	}

	/** Lo que gana la gestora por unidad: comisión + lo que subió el precio. null si no hay precio. */
	public static function earn( WC_Product $product, int $user_id ): ?float {
		$base = self::base_price( $product );
		if ( $base <= 0 ) { return null; }
		$source = $product;
		if ( $product->is_type( 'variation' ) && '' === (string) $product->get_meta( '_cvd_commission_value', true ) ) { $source = wc_get_product( $product->get_parent_id() ) ?: $product; }
		$type = sanitize_key( (string) $source->get_meta( '_cvd_commission_type', true ) );
		$value = $source->get_meta( '_cvd_commission_value', true );
		if ( '' === (string) $value ) {
			$user_rate = get_user_meta( $user_id, '_cvd_commission_rate', true );
			$rate = '' !== $user_rate ? (float) $user_rate : (float) get_option( 'cvd_default_commission_rate', 13 );
			$commission = $base * $rate / 100;
		} else {
			$commission = 'fixed' === $type ? (float) $value : $base * (float) $value / 100;
		}
		$hers = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $user_id, $base ) : $base;
		return round( $commission + max( 0, $hers - $base ), 2 );
	}

	/** «Ganas 3 USD» (o «Ganas 8,40 USD»). */
	public static function earn_label( float $earn ): string {
		return 'Ganas ' . ( abs( $earn - round( $earn ) ) < 0.005 ? (string) (int) round( $earn ) : number_format( $earn, 2, ',', '.' ) ) . ' USD';
	}

	/** En la ficha, debajo del precio: «Ganas 3 USD por cada uno que vendas». */
	public static function single_earn(): void {
		global $product;
		if ( ! self::is_gestora() || ! $product instanceof WC_Product ) { return; }
		$earn = self::earn( $product, get_current_user_id() );
		if ( null === $earn || $earn <= 0 ) { return; }
		echo '<p class="cvd-gestora-earn">' . esc_html( self::earn_label( $earn ) . ( $product->is_type( 'variable' ) ? ' (desde)' : '' ) . ' por cada uno que vendas' ) . '</p>';
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
		// 3.14.63 (maratón 24-oct, Ernesto): «Quedan X» también para los clientes (en la tienda de la gestora y en la de
		// Casa Viva). Lo de proveedor no tiene existencia: ya lleva «📦 Bajo pedido · llega en 24 h» (CVD_Departments).
		if ( ! $product instanceof WC_Product || ( class_exists( 'CVD_Datos_A_Mano' ) && CVD_Datos_A_Mano::is_bajo_pedido( $product ) ) ) { return; }
		if ( ! $product->is_in_stock() ) {
			$back = class_exists( 'CVD_Datos_A_Mano' ) ? CVD_Datos_A_Mano::back_label( $product ) : '';
			echo '<p class="cvd-gestora-stock is-out">Agotado' . ( '' !== $back ? '<small>' . esc_html( $back ) . '</small>' : '' ) . '</p>';
			return;
		}
		$parts = array();
		$total = null;
		if ( $product->is_type( 'variable' ) ) {
			$total = 0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || 'publish' !== $variation->get_status() || ! $variation->managing_stock() ) { continue; } // 3.14.41: un diseño oculto no sale en «Quedan»
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

	/**
	 * "7 USD · 5.460 CUP" en la tienda y en la ficha, con el precio que ve ella (el suyo si tiene tienda propia).
	 * Variables con precios distintos: "· 3.900–7.800 CUP". Tasa de más de 36 h: con "≈" y aviso al pasar el dedo.
	 */
	public static function cup_price( $html, $product ) {
		if ( ! self::is_gestora() || ! $product instanceof WC_Product || '' === (string) $html || ( is_admin() && ! wp_doing_ajax() ) || ! class_exists( 'CVD_Daily_Rates' ) ) { return $html; }
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );
		} else {
			$min = $max = (float) wc_get_price_to_display( $product );
		}
		$low = CVD_Daily_Rates::cup_for( $min );
		if ( null === $low ) { return $html; }
		$high = $max > $min ? CVD_Daily_Rates::cup_for( $max ) : null;
		$text = $high && $high !== $low ? number_format( $low, 0, ',', '.' ) . '–' . CVD_Daily_Rates::format_cup( $high ) : CVD_Daily_Rates::format_cup( $low );
		$stale = CVD_Daily_Rates::is_stale();
		$title = $stale ? 'Tasa de hace más de ' . CVD_Daily_Rates::STALE_HOURS . ' h: confírmala en el grupo General' : 'Con la tasa del día';
		return $html . ' <span class="cvd-price-cup' . ( $stale ? ' is-stale' : '' ) . '" title="' . esc_attr( $title ) . '">· ' . ( $stale ? '≈ ' : '' ) . esc_html( $text ) . '</span>';
	}

	public static function styles(): void {
		// 3.14.63: «Quedan X» sale a todos (loop_badge); el CUP y «Ganas» siguen siendo solo de la gestora.
		echo '<style id="cvd-gestora-stock">.cvd-gestora-stock{margin:4px 0 6px;font-size:13px;font-weight:600;color:#0f3d3a}.cvd-gestora-stock small{display:block;font-weight:400;color:#6f6a62;font-size:12px;line-height:1.35}.cvd-gestora-stock.is-low{color:#a8641c}.cvd-gestora-stock.is-out{color:#a83d32}.cvd-price-cup{font-size:.85em;font-weight:600;color:#0f3d3a;white-space:nowrap}.cvd-price-cup.is-stale{color:#a8641c}.cvd-gestora-earn{margin:2px 0;font-size:13px;font-weight:700;color:#1d6b3a}</style>';
	}
}
