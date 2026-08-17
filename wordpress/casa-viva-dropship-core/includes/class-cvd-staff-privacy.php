<?php

defined( 'ABSPATH' ) || exit;

/**
 * Limita las proyecciones operativas de dependientas a los datos necesarios
 * para preparar y entregar pedidos. Administración conserva la vista completa.
 */
final class CVD_Staff_Privacy {
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_response' ), 20, 3 );
	}

	public static function filter_response( $response, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );

		if ( current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'cvd_manage_sales' ) ) {
			return $response;
		}
		if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$route = $request->get_route();
		$data  = $response->get_data();

		if ( preg_match( '#^/casa-viva/v1/sales(?:/|$)#', $route ) ) {
			$response->set_data( self::filter_sales_data( $data ) );
			return $response;
		}

		if ( preg_match( '#^/casa-viva/v1/order-center/\d+$#', $route ) ) {
			$response->set_data( self::filter_order_center_data( $data ) );
		}

		return $response;
	}

	private static function filter_sales_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( isset( $data['orders'] ) && is_array( $data['orders'] ) ) {
			$data['orders'] = array_map( array( __CLASS__, 'filter_sales_order' ), $data['orders'] );
		}
		if ( isset( $data['order'] ) && is_array( $data['order'] ) ) {
			$data['order'] = self::filter_sales_order( $data['order'] );
		}

		return $data;
	}

	private static function filter_sales_order( array $order ): array {
		unset(
			$order['gestora'],
			$order['commission'],
			$order['commissionStatus'],
			$order['adminUrl']
		);
		return $order;
	}

	private static function filter_order_center_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( isset( $data['projection'] ) && is_array( $data['projection'] ) ) {
			$data['projection'] = self::filter_projection( $data['projection'] );
			return $data;
		}

		return self::filter_projection( $data );
	}

	private static function filter_projection( array $projection ): array {
		unset( $projection['commission_summary'], $projection['gestora'] );
		return $projection;
	}
}
