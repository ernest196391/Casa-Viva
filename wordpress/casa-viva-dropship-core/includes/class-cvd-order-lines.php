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
}
