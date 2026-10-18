<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.7 (Nana, 14-oct): el precio de cada producto en los vales y en las tarjetas del pedido.
 * «Ponen el total pero no el desglose de cuánto vale cada producto, y en la tienda lo anotamos.»
 *
 * Una sola regla para todos (vale del mensajero, «Nueva carrera», /ventas/, área del mensajero, avisos de cambios):
 *   1× Bata de baño azul — 30 USD
 *   2× Toalla gris — 15 USD c/u = 30 USD
 * El precio es el de la línea del pedido: lo que pagó el cliente (en un pedido de gestora, el precio de la gestora;
 * con un cupón, ya descontado). El total del pedido sigue saliendo igual que antes.
 */
final class CVD_Order_Lines {

	/** «30 USD», «12.50 USD» (sin «.00» cuando es redondo). */
	public static function money( float $amount, string $currency = 'USD' ): string {
		$amount = round( $amount, 2 );
		$text = abs( $amount - round( $amount ) ) < 0.005 ? number_format( $amount, 0, '.', ',' ) : number_format( $amount, 2, '.', ',' );
		return $text . ' ' . ( '' !== $currency ? $currency : 'USD' );
	}

	/** «1× Bata — 30 USD» · «2× Toalla — 15 USD c/u = 30 USD». */
	public static function text( int $quantity, string $name, float $total, string $currency = 'USD' ): string {
		$quantity = max( 1, $quantity );
		$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
		if ( 1 === $quantity ) {
			return '1× ' . $name . ' — ' . self::money( $total, $currency );
		}
		return $quantity . '× ' . $name . ' — ' . self::money( $total / $quantity, $currency ) . ' c/u = ' . self::money( $total, $currency );
	}

	/** Precio de una línea: unidad y total (lo que pagó el cliente). */
	public static function line( WC_Order $order, WC_Order_Item_Product $item ): array {
		$quantity = max( 1, (int) $item->get_quantity() );
		$total = round( (float) $item->get_total(), 2 );
		$currency = (string) $order->get_currency() ?: 'USD';
		return array(
			'name'     => wp_strip_all_tags( $item->get_name() ),
			'quantity' => $quantity,
			'unit'     => round( $total / $quantity, 2 ),
			'total'    => $total,
			'currency' => $currency,
			'text'     => self::text( $quantity, $item->get_name(), $total, $currency ),
		);
	}

	/** Todas las líneas del pedido. */
	public static function lines( WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) { $lines[] = self::line( $order, $item ); }
		}
		return $lines;
	}

	/** Solo los textos, una línea por producto. */
	public static function texts( WC_Order $order ): array {
		return array_map( static fn( array $l ): string => $l['text'], self::lines( $order ) );
	}

	// ---------- Foto de cada producto (3.14.37, Lennys 16 y 17-oct) ----------
	// «Al dependiente hay que mandarle foto con el vale. Ayer se le mandó al cliente un cortinero que no era.»
	// Una sola regla para el vale del mensajero (CVD_Bot_Bridge::images) y la tarjeta de /ventas/ (CVD_Sales):
	// primero la foto del diseño comprado (color/variación, la foto real de ese color desde 3.14.25); si no tiene,
	// la foto principal del producto. Sin foto → ''. Solo lectura: no toca el pedido.

	/** URL de la foto de la línea, del tamaño pedido ('medium', 'large'…), o ''. */
	public static function photo_url( WC_Order_Item_Product $item, string $size = 'large' ): string {
		$product = $item->get_product();
		if ( ! $product ) { return ''; }
		$id = (int) $product->get_image_id();
		if ( ! $id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$id = $parent ? (int) $parent->get_image_id() : 0;
		}
		$url = $id ? wp_get_attachment_image_url( $id, $size ) : '';
		return is_string( $url ) ? $url : '';
	}

	/**
	 * El color/diseño comprado para el pie de la foto: «Color: Negro». '' si no es una variación o si el nombre de la
	 * línea ya lo dice («Cortinero extensible - Negro» no repite «Negro»).
	 */
	public static function variation_text( WC_Order_Item_Product $item ): string {
		$product = $item->get_product();
		if ( ! $product instanceof WC_Product_Variation || ! function_exists( 'wc_get_formatted_variation' ) ) { return ''; }
		$name = mb_strtolower( wp_strip_all_tags( (string) $item->get_name() ) );
		$parts = array();
		foreach ( explode( ', ', html_entity_decode( wp_strip_all_tags( (string) wc_get_formatted_variation( $product, true, true, false ) ), ENT_QUOTES, 'UTF-8' ) ) as $part ) {
			$part = trim( preg_replace( '/\s+/u', ' ', $part ) );
			$colon = strpos( $part, ':' );
			$value = false === $colon ? $part : trim( substr( $part, $colon + 1 ) );
			if ( '' === $part || '' === $value ) { continue; }
			if ( '' !== $name && str_contains( $name, mb_strtolower( $value ) ) ) { continue; } // ya está en el nombre
			$parts[] = $part;
		}
		return implode( ' · ', $parts );
	}
}
