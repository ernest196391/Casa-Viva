<?php

defined( 'ABSPATH' ) || exit;

/** Web Push propio: señal sin datos del pedido y bandeja auditable por usuario. */
final class CVD_Web_Push {
	public static function subscriptions_table(): string { global $wpdb; return $wpdb->prefix . 'cvd_push_subscriptions'; }
	public static function notifications_table(): string { global $wpdb; return $wpdb->prefix . 'cvd_notifications'; }

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_logout', array( __CLASS__, 'clear_device_cookie' ) );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/push/subscription', array(
			array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'subscribe'), 'permission_callback'=>array(__CLASS__,'can_subscribe') ),
			array( 'methods'=>'DELETE', 'callback'=>array(__CLASS__,'unsubscribe'), 'permission_callback'=>array(__CLASS__,'can_subscribe') ),
		) );
		register_rest_route( 'casa-viva/v1', '/notifications', array(
			array( 'methods'=>'GET', 'callback'=>array(__CLASS__,'notifications'), 'permission_callback'=>array(__CLASS__,'can_subscribe') ),
			array( 'methods'=>'POST', 'callback'=>array(__CLASS__,'mark_read'), 'permission_callback'=>array(__CLASS__,'can_subscribe') ),
		) );
		register_rest_route( 'casa-viva/v1', '/push/latest', array(
			'methods'=>'GET', 'callback'=>array(__CLASS__,'latest_for_device'), 'permission_callback'=>'__return_true',
		) );
	}

	public static function can_subscribe(): bool {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) { return false; }
		if ( 'mensajero' === CVD_Registration::program_type( $user ) ) { return 'approved' === get_user_meta( $user->ID, '_cvd_account_status', true ); }
		return (bool) array_intersect( array( 'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator' ), (array) $user->roles );
	}

	public static function public_key(): string { return (string) get_option( 'cvd_vapid_public_key', '' ); }

	public static function subscribe( WP_REST_Request $request ) {
		global $wpdb;
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ) );
		if ( ! $endpoint || 0 !== strpos( $endpoint, 'https://' ) || strlen( $endpoint ) > 1000 ) { return new WP_Error( 'cvd_bad_subscription', 'Suscripción no válida.', array('status'=>422) ); }
		$hash = hash( 'sha256', $endpoint );
		$now = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 32 ) );
		$result = $wpdb->replace( self::subscriptions_table(), array(
			'endpoint_hash'=>$hash, 'device_token'=>$token, 'user_id'=>get_current_user_id(), 'endpoint'=>$endpoint,
			'user_agent'=>mb_substr( sanitize_text_field( (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') ), 0, 255 ),
			'created_at'=>$now, 'updated_at'=>$now, 'last_success_at'=>null, 'failure_count'=>0,
		), array('%s','%s','%d','%s','%s','%s','%s','%s','%d') );
		if ( false === $result ) { return new WP_Error( 'cvd_subscription_storage', 'No se pudo guardar el dispositivo.', array('status'=>500) ); }
		setcookie( 'cvd_push_device', $token, array( 'expires'=>time()+YEAR_IN_SECONDS, 'path'=>'/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Strict' ) );
		return rest_ensure_response( array('subscribed'=>true) );
	}

	public static function clear_device_cookie(): void {
		setcookie( 'cvd_push_device', '', array( 'expires'=>time()-HOUR_IN_SECONDS, 'path'=>'/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Strict' ) );
	}

	/** Entrega al service worker únicamente el último aviso del dispositivo autenticado por token aleatorio. */
	public static function latest_for_device(): WP_REST_Response {
		global $wpdb;
		$token = sanitize_text_field( wp_unslash( $_COOKIE['cvd_push_device'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) { return new WP_REST_Response( array(), 204 ); }
		$user_id = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM '.self::subscriptions_table().' WHERE device_token=%s LIMIT 1', $token ) ) );
		if ( ! $user_id ) { return new WP_REST_Response( array(), 204 ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,title,message,action_url,type,created_at FROM '.self::notifications_table().' WHERE user_id=%d ORDER BY id DESC LIMIT 1', $user_id ), ARRAY_A );
		$response = new WP_REST_Response( $row ?: array(), $row ? 200 : 204 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$endpoint = esc_url_raw( (string) $request->get_param( 'endpoint' ) );
		$wpdb->delete( self::subscriptions_table(), array('endpoint_hash'=>hash('sha256',$endpoint), 'user_id'=>get_current_user_id()), array('%s','%d') );
		return rest_ensure_response( array('subscribed'=>false) );
	}

	public static function notifications(): WP_REST_Response {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,order_id,title,message,action_url,created_at,read_at FROM '.self::notifications_table().' WHERE user_id=%d ORDER BY id DESC LIMIT 30', get_current_user_id() ), ARRAY_A );
		return rest_ensure_response( array('notifications'=>$rows ?: array()) );
	}

	public static function mark_read( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$id = absint( $request->get_param('id') );
		if ( $id ) { $wpdb->update( self::notifications_table(), array('read_at'=>current_time('mysql',true)), array('id'=>$id,'user_id'=>get_current_user_id()), array('%s'), array('%d','%d') ); }
		else { $wpdb->query( $wpdb->prepare( 'UPDATE '.self::notifications_table().' SET read_at=%s WHERE user_id=%d AND read_at IS NULL', current_time('mysql',true), get_current_user_id() ) ); }
		return rest_ensure_response( array('read'=>true) );
	}

	public static function send_offer( WC_Order $order, array $messengers ): void {
		foreach ( $messengers as $messenger ) {
			self::notify_user( (int) $messenger->ID, $order->get_id(), 'delivery_offer', 'Nueva carrera', 'Toca para aceptar o rechazar.', home_url('/area-mensajeros/#ofertas') );
		}
	}

	/** Registra y envía cada avance relevante a operaciones; el cliente lo recibe como nota del pedido. */
	public static function send_delivery_update( WC_Order $order, string $status ): void {
		$messages = array(
			'accepted'      => array( 'Carrera aceptada', 'El mensajero aceptó el pedido.' ),
			'to_store'      => array( 'Mensajero en camino', 'Va a recoger el pedido en Casa Viva.' ),
			'picked_up'     => array( 'Pedido entregado al mensajero', 'El mensajero ya recibió el pedido.' ),
			'handed_over'   => array( 'Pedido recogido', 'El mensajero va hacia el cliente.' ),
			'delivered'     => array( 'Pedido entregado', 'Falta confirmar el dinero recibido.' ),
			'cash_returned' => array( 'Dinero recibido', 'La operación está cerrando.' ),
			'closed'        => array( 'Operación cerrada', 'Dinero y comisiones quedaron registrados.' ),
			'incident'      => array( 'Incidencia de entrega', 'Revisar el pedido.' ),
			'failed'        => array( 'Entrega no completada', 'Revisar el pedido.' ),
			'returned'      => array( 'Pedido devuelto', 'El pedido regresó a Casa Viva.' ),
		);
		if ( ! isset( $messages[ $status ] ) ) { return; }
		list( $title, $message ) = $messages[ $status ];
		$title .= ' · #' . $order->get_order_number();
		$staff = get_users( array( 'role__in'=>array('administrator','shop_manager','cvd_clerk','cvd_operator'), 'fields'=>array('ID') ) );
		foreach ( $staff as $user ) {
			self::notify_user( (int) $user->ID, $order->get_id(), 'delivery_' . $status, $title, $message, add_query_arg( 'order', $order->get_id(), home_url( '/mensajeria/' ) ) );
		}
		$messenger_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( $messenger_id && $messenger_id !== get_current_user_id() ) {
			self::notify_user( $messenger_id, $order->get_id(), 'delivery_' . $status, $title, $message, home_url( '/area-mensajeros/' ) );
		}
		$customer_messages = array(
			'to_store'    => 'El mensajero va a recoger tu pedido.',
			'picked_up'   => 'Casa Viva entregó tu pedido al mensajero.',
			'handed_over' => 'El mensajero ya recogió tu pedido y va hacia tu dirección.',
			'delivered'   => 'Tu pedido fue marcado como entregado.',
		);
		if ( isset( $customer_messages[ $status ] ) ) {
			$order->add_order_note( $customer_messages[ $status ] . ' Seguimiento: ' . CVD_Delivery::tracking_url( $order ), true );
		}
	}

	/** Avisa a cada cuenta operativa una sola vez cuando entra un pedido. */
	public static function send_new_order( WC_Order $order ): void {
		if ( $order->get_meta( '_cvd_staff_new_order_notified', true ) ) { return; }
		$users = get_users( array( 'role__in'=>array('administrator','shop_manager','cvd_clerk','cvd_operator'), 'fields'=>array('ID') ) );
		foreach ( $users as $user ) {
			self::notify_user( (int) $user->ID, $order->get_id(), 'new_order', 'Nuevo pedido #' . $order->get_order_number(), 'Revisar y preparar.', add_query_arg('order',$order->get_id(),home_url('/ventas/')) );
		}
		$order->update_meta_data( '_cvd_staff_new_order_notified', current_time('mysql',true) );
		$order->save();
	}

	private static function notify_user( int $user_id, int $order_id, string $type, string $title, string $message, string $action_url ): void {
		global $wpdb;
		$wpdb->insert( self::notifications_table(), array('user_id'=>$user_id,'order_id'=>$order_id,'type'=>$type,'title'=>$title,'message'=>$message,'action_url'=>$action_url,'created_at'=>current_time('mysql',true)), array('%d','%d','%s','%s','%s','%s','%s') );
		$subscriptions = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM '.self::subscriptions_table().' WHERE user_id=%d AND failure_count<5', $user_id ) );
		foreach ( $subscriptions as $subscription ) { self::send_signal( $subscription ); }
	}

	private static function send_signal( object $subscription ): void {
		global $wpdb;
		$endpoint = (string) $subscription->endpoint;
		$audience = wp_parse_url( $endpoint, PHP_URL_SCHEME ) . '://' . wp_parse_url( $endpoint, PHP_URL_HOST );
		$jwt = self::vapid_jwt( $audience );
		if ( ! $jwt ) { return; }
		$response = wp_remote_post( $endpoint, array('timeout'=>10, 'redirection'=>0, 'body'=>'', 'headers'=>array(
			'TTL'=>'300', 'Urgency'=>'high', 'Authorization'=>'vapid t='.$jwt.', k='.self::public_key(), 'Content-Length'=>'0',
		) ) );
		$code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
		if ( in_array($code,array(200,201,202,204),true) ) {
			$wpdb->update( self::subscriptions_table(), array('last_success_at'=>current_time('mysql',true),'failure_count'=>0,'updated_at'=>current_time('mysql',true)), array('id'=>$subscription->id), array('%s','%d','%s'), array('%d') );
		} elseif ( in_array($code,array(404,410),true) ) {
			$wpdb->delete( self::subscriptions_table(), array('id'=>$subscription->id), array('%d') );
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE '.self::subscriptions_table().' SET failure_count=failure_count+1,updated_at=%s WHERE id=%d', current_time('mysql',true), $subscription->id ) );
		}
	}

	private static function vapid_jwt( string $audience ): string {
		$private = (string) get_option('cvd_vapid_private_pem','');
		if ( ! $private || ! self::public_key() || ! function_exists('openssl_sign') ) { return ''; }
		$header = self::base64url( wp_json_encode(array('typ'=>'JWT','alg'=>'ES256')) );
		$claims = self::base64url( wp_json_encode(array('aud'=>$audience,'exp'=>time()+HOUR_IN_SECONDS*10,'sub'=>'mailto:'.sanitize_email(get_option('admin_email')))) );
		$input = $header.'.'.$claims; $der='';
		if ( ! openssl_sign($input,$der,$private,OPENSSL_ALGO_SHA256) ) { return ''; }
		$signature = self::der_to_jose($der,32);
		return $signature ? $input.'.'.self::base64url($signature) : '';
	}

	private static function der_to_jose( string $der, int $size ): string {
		$offset=0; if ( ord($der[$offset++])!==0x30 ) return ''; self::read_length($der,$offset);
		if ( ord($der[$offset++])!==0x02 ) return ''; $rlen=self::read_length($der,$offset); $r=substr($der,$offset,$rlen); $offset+=$rlen;
		if ( ord($der[$offset++])!==0x02 ) return ''; $slen=self::read_length($der,$offset); $s=substr($der,$offset,$slen);
		$r=ltrim($r,"\0"); $s=ltrim($s,"\0"); return str_pad($r,$size,"\0",STR_PAD_LEFT).str_pad($s,$size,"\0",STR_PAD_LEFT);
	}
	private static function read_length( string $data, int &$offset ): int { $length=ord($data[$offset++]); if($length<128)return $length; $bytes=$length&0x7f;$length=0;while($bytes--){$length=($length<<8)|ord($data[$offset++]);}return $length; }
	private static function base64url( string $data ): string { return rtrim(strtr(base64_encode($data),'+/','-_'),'='); }

	public static function ensure_keys(): void {
		if ( self::public_key() && get_option('cvd_vapid_private_pem') ) { return; }
		if ( ! function_exists('openssl_pkey_new') ) { return; }
		$key = openssl_pkey_new(array('private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1'));
		if ( ! $key ) { return; }
		openssl_pkey_export($key,$pem); $details=openssl_pkey_get_details($key); $x=$details['ec']['x']??'';$y=$details['ec']['y']??'';
		if(!$pem||strlen($x)!==32||strlen($y)!==32)return;
		update_option('cvd_vapid_private_pem',$pem,false); update_option('cvd_vapid_public_key',self::base64url("\x04".$x.$y),false);
	}
}
