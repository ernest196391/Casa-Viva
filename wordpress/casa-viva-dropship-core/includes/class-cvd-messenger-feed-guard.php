<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the messenger polling feed aligned with the active delivery cards rendered
 * by the portal. Completed deliveries are intentionally absent from the active
 * delivery DOM; leaving them in the feed makes portal.js reload forever.
 */
final class CVD_Messenger_Feed_Guard {
	private const COMPLETED_STAGES = array( 'delivered', 'cash_returned', 'closed' );

	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'align_feed_with_active_delivery_view' ), 20, 3 );
	}

	public static function align_feed_with_active_delivery_view( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || '/casa-viva/v1/messenger/feed' !== $request->get_route() ) {
			return $response;
		}
		if ( ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['deliveries'] ) || ! is_array( $data['deliveries'] ) ) {
			return $response;
		}

		$data['deliveries'] = array_values(
			array_filter(
				$data['deliveries'],
				static function ( $delivery ): bool {
					if ( ! is_array( $delivery ) ) {
						return true;
					}
					$status = sanitize_key( (string) ( $delivery['status'] ?? '' ) );
					if ( in_array( $status, self::COMPLETED_STAGES, true ) ) {
						return false;
					}
					if ( 'incident' !== $status ) {
						return true;
					}
					$order_id = absint( $delivery['id'] ?? 0 );
					$order = $order_id ? wc_get_order( $order_id ) : false;
					if ( ! $order ) {
						return true;
					}
					$preserved_stage = sanitize_key( (string) $order->get_meta( '_cvd_delivery_incident_stage', true ) );
					return ! in_array( $preserved_stage, self::COMPLETED_STAGES, true );
				}
			)
		);
		$response->set_data( $data );
		return $response;
	}
}
