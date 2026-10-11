<?php

defined( 'ABSPATH' ) || exit;

/**
 * Puerta del panel de Lennys (NEXO, casaviva.company/panel) hacia Core (D37: un solo panel).
 *
 * El panel no habla con WordPress directamente: lo hace la función nexo-panel-web de NEXO,
 * que antes comprueba que quien pide es dueña. Aquí solo se lee y se ejecutan las mismas
 * acciones que ya existen (aprobar gestoras como el bot, transiciones de CVD_Payouts).
 * Autenticación: cabecera X-Nexo-Panel-Key, comparada con su hash en la opción cvd_panel_key_hash.
 * Las acciones de pago quedan firmadas por el usuario de la opción cvd_panel_actor_user_id.
 */
final class CVD_Panel_Bridge {
	private const NS = 'casa-viva/v1';
	private const KEY_OPTION = 'cvd_panel_key_hash';
	private const ACTOR_OPTION = 'cvd_panel_actor_user_id';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( __CLASS__, 'authorized' );
		register_rest_route( self::NS, '/panel/gestoras', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'gestoras' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/gestoras/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'gestora_status' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/payouts', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'payouts' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/payouts/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'payout_action' ), 'permission_callback' => $auth ) );
	}

	public static function authorized( WP_REST_Request $request ): bool {
		$key = (string) $request->get_header( 'x_nexo_panel_key' );
		$hash = (string) get_option( self::KEY_OPTION, '' );
		return '' !== $key && '' !== $hash && hash_equals( $hash, hash( 'sha256', $key ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Todas las gestoras con sus números: ventas de la web, comisiones por estado y último pago. */
	public static function gestoras(): WP_REST_Response {
		global $wpdb;
		$stats = array();
		$orders = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => array( 'completed', 'processing', 'on-hold' ), 'meta_key' => '_cvd_owner_type', 'meta_value' => 'gestora' ) );
		foreach ( $orders as $order ) {
			$id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
			if ( ! $id ) { continue; }
			$stats[ $id ] = $stats[ $id ] ?? array( 'orders' => 0, 'sales' => 0.0, 'pending' => 0.0, 'available' => 0.0, 'paid' => 0.0 );
			if ( 'completed' === $order->get_status() ) { $stats[ $id ]['orders']++; $stats[ $id ]['sales'] += (float) $order->get_total(); }
			$amount = (float) $order->get_meta( '_cvd_commission_amount', true );
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'paid' === $status ) { $stats[ $id ]['paid'] += $amount; }
			elseif ( 'approved' === $status ) { $stats[ $id ]['available'] += $amount; }
			elseif ( 'pending' === $status ) { $stats[ $id ]['pending'] += $amount; }
		}
		$last = array();
		foreach ( (array) $wpdb->get_results( "SELECT owner_user_id, MAX(paid_at) AS paid_at FROM {$wpdb->prefix}cvd_payouts WHERE status='paid' GROUP BY owner_user_id", ARRAY_A ) as $r ) {
			$last[ absint( $r['owner_user_id'] ) ] = $r['paid_at'];
		}
		$rows = array();
		foreach ( get_users( array( 'role' => 'cvd_gestora', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ) ) as $user ) {
			$s = $stats[ $user->ID ] ?? array( 'orders' => 0, 'sales' => 0.0, 'pending' => 0.0, 'available' => 0.0, 'paid' => 0.0 );
			$inviter = absint( get_user_meta( $user->ID, '_cvd_invited_by', true ) );
			$inviter_user = $inviter ? get_userdata( $inviter ) : null;
			$rows[] = array(
				'id'         => $user->ID,
				'name'       => $user->display_name,
				'phone'      => preg_replace( '/\D+/', '', (string) ( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ) ),
				'status'     => sanitize_key( (string) get_user_meta( $user->ID, '_cvd_account_status', true ) ) ?: 'pending',
				'code'       => (string) get_user_meta( $user->ID, '_cvd_referral_code', true ),
				'since'      => $user->user_registered,
				'invitedBy'  => $inviter_user ? $inviter_user->display_name : null,
				'orders'     => $s['orders'],
				'sales'      => round( $s['sales'], 2 ),
				'commission' => array( 'pending' => round( $s['pending'], 2 ), 'available' => round( $s['available'], 2 ), 'paid' => round( $s['paid'], 2 ) ),
				'lastPaidAt' => $last[ $user->ID ] ?? null,
			);
		}
		return self::no_cache( array( 'gestoras' => $rows ) );
	}

	/** Aprobar o rechazar: la misma lógica que usa el bot (estado y código de referido). */
	public static function gestora_status( WP_REST_Request $request ) {
		$forward = new WP_REST_Request( 'POST' );
		$forward->set_url_params( array( 'id' => absint( $request['id'] ) ) );
		$forward->set_param( 'status', (string) $request->get_param( 'status' ) );
		$forward->set_param( 'by', 'panel' );
		$result = CVD_Bot_Bridge::partner_status( $forward );
		if ( ! is_wp_error( $result ) ) { update_user_meta( absint( $request['id'] ), '_cvd_status_changed_by', 'panel' ); }
		return $result;
	}

	/** Solicitudes y pagos (por defecto, los que esperan acción). */
	public static function payouts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$where = in_array( $status, array( 'requested', 'approved', 'paid', 'rejected' ), true )
			? $wpdb->prepare( 'WHERE status=%s', $status ) : "WHERE status IN ('requested','approved')";
		$owner = absint( $request->get_param( 'owner' ) );
		if ( $owner ) { $where .= $wpdb->prepare( ' AND owner_user_id=%d', $owner ); }
		$rows = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, owner_user_id, amount, currency, status, method, reference, requested_at, approved_at, paid_at FROM {$wpdb->prefix}cvd_payouts {$where} ORDER BY id DESC LIMIT 100", ARRAY_A ) as $r ) {
			$user = get_userdata( absint( $r['owner_user_id'] ) );
			$rows[] = array(
				'id' => absint( $r['id'] ), 'gestora' => $user ? $user->display_name : '#' . $r['owner_user_id'],
				'gestoraId' => absint( $r['owner_user_id'] ), 'amount' => round( (float) $r['amount'], 2 ), 'currency' => $r['currency'],
				'status' => $r['status'], 'method' => $r['method'], 'reference' => $r['reference'],
				'requestedAt' => $r['requested_at'], 'approvedAt' => $r['approved_at'], 'paidAt' => $r['paid_at'],
			);
		}
		return self::no_cache( array( 'payouts' => $rows ) );
	}

	/** Aprobar, marcar pagado o rechazar una solicitud (CVD_Payouts decide si la transición vale). */
	public static function payout_action( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! in_array( $action, array( 'approve', 'pay', 'reject' ), true ) ) {
			return new WP_Error( 'cvd_panel_action', 'Acción no válida.', array( 'status' => 422 ) );
		}
		$actor = absint( get_option( self::ACTOR_OPTION, 0 ) );
		if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_panel_actor', 'Falta configurar quién firma los pagos del panel.', array( 'status' => 500 ) );
		}
		wp_set_current_user( $actor );
		$result = CVD_Payouts::transition( absint( $request['id'] ), $action, sanitize_text_field( (string) $request->get_param( 'reference' ) ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( array( 'ok' => true ) );
	}
}
