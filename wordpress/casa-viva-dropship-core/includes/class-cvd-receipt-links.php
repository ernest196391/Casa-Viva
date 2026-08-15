<?php

defined( 'ABSPATH' ) || exit;

/**
 * Enlaces breves y firmados usados por los comprobantes.
 */
final class CVD_Receipt_Links {
	private const ROUTE = '^cv/([mp])/([0-9]+)/([0-9]+)/([a-f0-9]{12})/?$';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ) );
	}

	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			self::ROUTE,
			'index.php?cvd_receipt_link=1&cvd_receipt_type=$matches[1]&cvd_receipt_order=$matches[2]&cvd_receipt_item=$matches[3]&cvd_receipt_signature=$matches[4]',
			'top'
		);
	}

	public static function query_vars( array $vars ): array {
		return array_merge(
			$vars,
			array( 'cvd_receipt_link', 'cvd_receipt_type', 'cvd_receipt_order', 'cvd_receipt_item', 'cvd_receipt_signature' )
		);
	}

	public static function url( WC_Order $order, string $type, int $item_id = 0 ): string {
		$type = 'map' === $type ? 'm' : 'p';
		$order_id = $order->get_id();
		$item_id = absint( $item_id );
		$signature = self::signature( $type, $order_id, $item_id );

		return home_url( sprintf( '/cv/%s/%d/%d/%s/', $type, $order_id, $item_id, $signature ) );
	}

	public static function redirect(): void {
		if ( ! get_query_var( 'cvd_receipt_link' ) ) {
			return;
		}

		$type = sanitize_key( (string) get_query_var( 'cvd_receipt_type' ) );
		$order_id = absint( get_query_var( 'cvd_receipt_order' ) );
		$item_id = absint( get_query_var( 'cvd_receipt_item' ) );
		$provided = sanitize_key( (string) get_query_var( 'cvd_receipt_signature' ) );

		if ( ! hash_equals( self::signature( $type, $order_id, $item_id ), $provided ) ) {
			self::not_found();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::not_found();
		}

		$target = 'm' === $type ? self::map_target( $order ) : self::product_target( $order, $item_id );
		if ( ! $target ) {
			self::not_found();
		}

		wp_redirect( $target, 302, 'Casa Viva Receipt' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	private static function product_target( WC_Order $order, int $item_id ): string {
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return '';
		}

		$product = $item->get_product();
		return $product ? esc_url_raw( $product->get_permalink() ) : '';
	}

	private static function map_target( WC_Order $order ): string {
		$url = esc_url_raw( (string) $order->get_meta( '_cvd_map_url', true ) );
		if ( ! $url ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allowed_hosts = (array) apply_filters(
			'cvd_receipt_map_hosts',
			array( 'google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'openstreetmap.org', 'www.openstreetmap.org' ),
			$order
		);

		return in_array( $host, $allowed_hosts, true ) ? $url : '';
	}

	private static function signature( string $type, int $order_id, int $item_id ): string {
		return substr( hash_hmac( 'sha256', $type . '|' . $order_id . '|' . $item_id, wp_salt( 'auth' ) ), 0, 12 );
	}

	private static function not_found(): void {
		status_header( 404 );
		nocache_headers();
		exit;
	}
}
