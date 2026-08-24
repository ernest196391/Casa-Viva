<?php

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the messenger polling feed aligned with the active delivery cards rendered
 * by the portal. Delivered/cash-returned orders are intentionally absent from the
 * active delivery DOM; leaving them in the feed makes portal.js reload forever.
 */
final class CVD_Messenger_Feed_Guard {
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
					$status = is_array( $delivery ) ? sanitize_key( (string) ( $delivery['status'] ?? '' ) ) : '';
					return ! in_array( $status, array( 'delivered', 'cash_returned', 'closed' ), true );
				}
			)
		);
		$response->set_data( $data );
		return $response;
	}
}
