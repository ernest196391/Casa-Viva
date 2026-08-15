<?php

defined( 'ABSPATH' ) || exit;

/** Seguimiento temporal de entregas. WooCommerce conserva el estado; esta tabla solo guarda coordenadas efímeras. */
final class CVD_Live_Tracking {
	private const CRON_HOOK = 'cvd_cleanup_delivery_locations';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK ); }
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cvd_delivery_locations';
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/delivery/(?P<id>\d+)/location', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_location' ), 'permission_callback' => array( __CLASS__, 'can_share_location' ) ),
		) );
		register_rest_route( 'casa-viva/v1', '/delivery/(?P<id>\d+)/tracking', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'tracking' ), 'permission_callback' => '__return_true' ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'customer_response' ), 'permission_callback' => '__return_true' ),
		) );
	}

	public static function assets(): void {
		if ( ! is_page( array( 'area-mensajeros', 'seguimiento' ) ) ) { return; }
		wp_enqueue_script( 'cvd-live-tracking', CVD_URL . 'assets/live-tracking.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-live-tracking', 'cvdLiveTracking', array(
			'baseUrl' => rest_url( 'casa-viva/v1/delivery/' ),
			'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'isMessenger' => is_user_logged_in() && 'mensajero' === CVD_Registration::program_type( wp_get_current_user() ),
		) );
	}

	public static function can_share_location( WP_REST_Request $request ): bool {
		$order = wc_get_order( absint( $request['id'] ) );
		$user = wp_get_current_user();
		return $order instanceof WC_Order
			&& $user->exists()
			&& 'mensajero' === CVD_Registration::program_type( $user )
			&& $user->ID === absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
	}

	public static function save_location( WP_REST_Request $request ) {
		global $wpdb;
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order || 'handed_over' !== CVD_Delivery::status( $order ) ) { return new WP_Error( 'cvd_tracking_inactive', 'La entrega no está en camino.', array( 'status' => 409 ) ); }
		$lat = (float) $request->get_param( 'latitude' );
		$lng = (float) $request->get_param( 'longitude' );
		$accuracy = min( 5000, max( 0, (float) $request->get_param( 'accuracy' ) ) );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) { return new WP_Error( 'cvd_bad_location', 'Ubicación no válida.', array( 'status' => 422 ) ); }
		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d ORDER BY id DESC LIMIT 1', $order->get_id() ) );
		if ( $last ) {
			$seconds = time() - strtotime( $last->recorded_at . ' UTC' );
			if ( $seconds < 8 ) { return new WP_Error( 'cvd_tracking_throttled', 'Espera unos segundos.', array( 'status' => 429 ) ); }
			if ( $seconds < 45 && self::distance_meters( (float) $last->latitude, (float) $last->longitude, $lat, $lng ) < 10 ) { return rest_ensure_response( array( 'saved' => false, 'reason' => 'no_movement' ) ); }
		}
		$wpdb->insert( self::table(), array( 'order_id'=>$order->get_id(), 'messenger_user_id'=>get_current_user_id(), 'latitude'=>$lat, 'longitude'=>$lng, 'accuracy'=>$accuracy, 'recorded_at'=>current_time( 'mysql', true ) ), array( '%d','%d','%f','%f','%f','%s' ) );
		if ( ! $wpdb->insert_id ) { return new WP_Error( 'cvd_tracking_storage', 'No se pudo guardar la ubicación.', array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'saved'=>true, 'recordedAt'=>gmdate( DATE_ATOM ) ) );
	}

	public static function tracking( WP_REST_Request $request ) {
		$order = self::authorized_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		return rest_ensure_response( self::tracking_payload( $order ) );
	}

	public static function customer_response( WP_REST_Request $request ) {
		$order = self::authorized_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$status = CVD_Delivery::status( $order );
		if ( ! in_array( $status, array( 'delivered', 'cash_returned', 'closed' ), true ) ) { return new WP_Error( 'cvd_confirmation_unavailable', 'La entrega todavía no puede confirmarse.', array( 'status' => 409 ) ); }
		if ( $order->get_meta( '_cvd_customer_confirmed_at', true ) ) { return new WP_Error( 'cvd_already_confirmed', 'Esta entrega ya fue confirmada.', array( 'status' => 409 ) ); }
		$rating = min( 5, max( 1, absint( $request->get_param( 'rating' ) ) ) );
		$comment = sanitize_textarea_field( (string) $request->get_param( 'comment' ) );
		$order->update_meta_data( '_cvd_customer_confirmed_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_customer_rating', $rating );
		$order->update_meta_data( '_cvd_customer_rating_comment', mb_substr( $comment, 0, 400 ) );
		$order->update_meta_data( '_cvd_messenger_rating_counted', 'yes' );
		$order->update_meta_data( '_cvd_customer_confirmation_fingerprint', hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), wp_salt( 'nonce' ) ) );
		$order->add_order_note( 'Cliente confirmó la entrega y evaluó el servicio con ' . $rating . '/5.' );
		$order->save();
		self::update_messenger_rating( $order, $rating );
		return rest_ensure_response( array( 'message'=>'Gracias. La entrega quedó confirmada.', 'tracking'=>self::tracking_payload( $order ) ) );
	}

	private static function authorized_order( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) { return new WP_Error( 'cvd_tracking_forbidden', 'Enlace de seguimiento no válido.', array( 'status' => 403 ) ); }
		return $order;
	}

	private static function tracking_payload( WC_Order $order ): array {
		global $wpdb;
		$status = CVD_Delivery::status( $order );
		$location = null;
		if ( 'handed_over' === $status ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT latitude,longitude,accuracy,recorded_at FROM ' . self::table() . ' WHERE order_id=%d ORDER BY id DESC LIMIT 1', $order->get_id() ), ARRAY_A );
			if ( $row ) { $location = array( 'latitude'=>(float)$row['latitude'], 'longitude'=>(float)$row['longitude'], 'accuracy'=>(float)$row['accuracy'], 'recordedAt'=>mysql_to_rfc3339( $row['recorded_at'] ) ); }
		}
		return array(
			'orderId'=>$order->get_id(),
			'status'=>$status,
			'statusLabel'=>CVD_Delivery::label( $status ),
			'location'=>$location,
			'customerConfirmed'=>(bool)$order->get_meta( '_cvd_customer_confirmed_at', true ),
			'rating'=>absint( $order->get_meta( '_cvd_customer_rating', true ) ),
			'confirmationAllowed'=>in_array( $status, array( 'delivered','cash_returned','closed' ), true ) && ! $order->get_meta( '_cvd_customer_confirmed_at', true ),
		);
	}

	private static function update_messenger_rating( WC_Order $order, int $rating ): void {
		$user_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $user_id ) { return; }
		$count = absint( get_user_meta( $user_id, '_cvd_rating_count', true ) );
		$total = (float) get_user_meta( $user_id, '_cvd_rating_total', true );
		update_user_meta( $user_id, '_cvd_rating_count', $count + 1 );
		update_user_meta( $user_id, '_cvd_rating_total', $total + $rating );
		update_user_meta( $user_id, '_cvd_rating_average', round( ( $total + $rating ) / ( $count + 1 ), 2 ) );
		if ( class_exists( 'CVD_Messenger_Reputation' ) ) { CVD_Messenger_Reputation::invalidate( $user_id ); }
	}

	/** Retira de la reputación una evaluación cuyo pedido terminó anulado o reembolsado. */
	public static function reverse_cancelled_rating( WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( '_cvd_messenger_rating_reversed', true ) ) { return; }
		$rating = absint( $order->get_meta( '_cvd_customer_rating', true ) );
		$user_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $rating || ! $user_id || ! $order->get_meta( '_cvd_customer_confirmed_at', true ) ) { return; }
		$count = max( 0, absint( get_user_meta( $user_id, '_cvd_rating_count', true ) ) - 1 );
		$total = max( 0, (float) get_user_meta( $user_id, '_cvd_rating_total', true ) - $rating );
		update_user_meta( $user_id, '_cvd_rating_count', $count );
		update_user_meta( $user_id, '_cvd_rating_total', $total );
		update_user_meta( $user_id, '_cvd_rating_average', $count ? round( $total / $count, 2 ) : 0 );
		if ( class_exists( 'CVD_Messenger_Reputation' ) ) { CVD_Messenger_Reputation::invalidate( $user_id ); }
		$order->update_meta_data( '_cvd_messenger_rating_reversed', 'yes' );
		$order->save();
	}

	private static function distance_meters( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth = 6371000;
		$dlat = deg2rad( $lat2 - $lat1 ); $dlng = deg2rad( $lng2 - $lng1 );
		$a = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		$days = min( 90, max( 1, absint( get_option( 'cvd_location_retention_days', 30 ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE recorded_at < %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days ) ) );
	}
}
