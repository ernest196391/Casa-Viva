<?php

defined( 'ABSPATH' ) || exit;

/**
 * Garantiza que el margen de tienda espejo solo pueda pertenecer a una gestora
 * coherente con el pedido. Ante datos mixtos o contradictorios, falla cerrado:
 * mantiene la comisión base de atribución, pero no acredita margen automático.
 */
final class CVD_Gestora_Price_Integrity {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		// CVD_Gestora_Store toma el snapshot inicial en prioridad 15.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'reconcile_cart_snapshot' ), 16, 2 );
		// CVD_Attribution fija la propietaria permanente en prioridad 30.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'enforce_owner_alignment' ), 35, 2 );
	}

	public static function reconcile_cart_snapshot( WC_Order $order, array $data ): void {
		unset( $data );
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		self::apply_cart_snapshot( $order, WC()->cart->get_cart() );
	}

	/** @param array<int|string,array<string,mixed>> $cart_contents */
	public static function apply_cart_snapshot( WC_Order $order, array $cart_contents ): void {
		$ids = self::pricing_gestora_ids( $cart_contents );
		if ( ! $ids ) {
			return;
		}

		$order->update_meta_data( '_cvd_pricing_snapshot_at', current_time( 'mysql', true ) );
		if ( 1 === count( $ids ) ) {
			$order->update_meta_data( '_cvd_pricing_gestora_user_id', $ids[0] );
			$order->delete_meta_data( '_cvd_pricing_conflict' );
			$order->delete_meta_data( '_cvd_pricing_conflict_gestora_user_id' );
			return;
		}

		$order->delete_meta_data( '_cvd_pricing_gestora_user_id' );
		$order->update_meta_data( '_cvd_pricing_conflict', 'mixed_gestoras' );
		$order->delete_meta_data( '_cvd_pricing_conflict_gestora_user_id' );
	}

	/** @param array<int|string,array<string,mixed>> $cart_contents */
	public static function pricing_gestora_ids( array $cart_contents ): array {
		$ids = array();
		foreach ( $cart_contents as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$id = absint( $cart_item['_cvd_gestora_id'] ?? 0 );
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}
		$ids = array_map( 'intval', array_keys( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public static function enforce_owner_alignment( WC_Order $order, array $data ): void {
		unset( $data );
		$pricing_id = absint( $order->get_meta( '_cvd_pricing_gestora_user_id', true ) );
		if ( ! $pricing_id ) {
			return;
		}

		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
		if ( ! $owner_id || 'organic' === $owner_type || $pricing_id === $owner_id ) {
			return;
		}

		$order->update_meta_data( '_cvd_pricing_conflict', 'owner_mismatch' );
		$order->update_meta_data( '_cvd_pricing_conflict_gestora_user_id', $pricing_id );
		$order->delete_meta_data( '_cvd_pricing_gestora_user_id' );
	}
}
