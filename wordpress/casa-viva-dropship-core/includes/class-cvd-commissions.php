<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Commissions {
	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'mark_pending' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_pending_from_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'mark_approved' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'mark_approved' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'mark_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'mark_cancelled' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'mark_cancelled' ) );
	}

	public static function mark_pending( int $order_id ): void {
		self::upsert( $order_id, 'pending' );
	}

	public static function mark_pending_from_order( WC_Order $order ): void {
		self::upsert( $order->get_id(), 'pending' );
	}

	public static function mark_approved( int $order_id ): void {
		self::upsert( $order_id, 'approved' );
	}

	public static function mark_cancelled( int $order_id ): void {
		self::upsert( $order_id, 'cancelled' );
	}

	private static function upsert( int $order_id, string $status ): void {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$owner_user_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type    = sanitize_key( $order->get_meta( '_cvd_owner_type', true ) );

		if ( ! $owner_user_id || 'organic' === $owner_type ) {
			return;
		}

		$calculation = self::calculate( $order, $owner_user_id );
		$table       = $wpdb->prefix . 'cvd_commissions';
		$now         = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				(order_id, owner_user_id, owner_type, amount, base_amount, rate, currency, status, created_at, updated_at)
				VALUES (%d, %d, %s, %f, %f, %f, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					amount = VALUES(amount),
					base_amount = VALUES(base_amount),
					rate = VALUES(rate),
					currency = VALUES(currency),
					status = VALUES(status),
					updated_at = VALUES(updated_at)",
				$order_id,
				$owner_user_id,
				$owner_type,
				$calculation['amount'],
				$calculation['base_amount'],
				$calculation['effective_rate'],
				$order->get_currency(),
				sanitize_key( $status ),
				$now,
				$now
			)
		);
	}

	private static function calculate( WC_Order $order, int $owner_user_id ): array {
		$default_rate = (float) get_option( 'cvd_default_commission_rate', 13 );
		$user_rate    = get_user_meta( $owner_user_id, '_cvd_commission_rate', true );
		$default_rate = '' !== $user_rate ? (float) $user_rate : $default_rate;
		$base_amount  = 0.0;
		$amount       = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$line    = (float) $item->get_total();
			$base_amount += $line;

			if ( ! $product ) {
				$amount += $line * ( $default_rate / 100 );
				continue;
			}

			$type  = $product->get_meta( '_cvd_commission_type', true ) ?: 'percent';
			$value = $product->get_meta( '_cvd_commission_value', true );
			$value = '' === $value ? $default_rate : (float) $value;

			if ( 'fixed' === $type ) {
				$amount += $value * max( 1, (int) $item->get_quantity() );
			} else {
				$amount += $line * ( $value / 100 );
			}
		}

		$effective_rate = $base_amount > 0 ? ( $amount / $base_amount ) * 100 : 0;

		return array(
			'amount'         => wc_format_decimal( $amount, 4 ),
			'base_amount'    => wc_format_decimal( $base_amount, 4 ),
			'effective_rate' => wc_format_decimal( $effective_rate, 4 ),
		);
	}
}
