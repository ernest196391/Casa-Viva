<?php

defined( 'ABSPATH' ) || exit;

/** Atribución persistente de clientes usando pedidos WooCommerce como fuente. */
final class CVD_Attribution {
	private const COOKIE_NAME = 'cvd_referral';
	private const SESSION_KEY = 'cvd_referral';

	public static function register(): void {
		self::disable_cache_for_referral_request();
		add_filter( 'litespeed_vary_cookies', array( __CLASS__, 'litespeed_vary_cookies' ) );
		add_action( 'init', array( __CLASS__, 'capture_referral' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'capture_referral' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'send_private_cache_headers' ), 0 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_to_order' ), 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'attach_store_api_order' ), 30, 2 );
	}

	/** Prevent full-page caches from serving Casa Viva prices inside a gestora storefront. */
	private static function disable_cache_for_referral_request(): void {
		if ( isset( $_GET['ref'] ) || isset( $_GET['cv_ref'] ) || ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	public static function send_private_cache_headers(): void {
		if ( self::current_referral_owner() ) {
			nocache_headers();
			do_action( 'litespeed_control_set_nocache', 'Casa Viva gestora storefront' );
		}
	}

	/**
	 * First-touch referral owner for the current browser.
	 *
	 * A valid saved referral wins over later links. This keeps storefront pricing and
	 * order attribution aligned with the permanent ownership model.
	 */
	public static function current_referral_owner(): ?array {
		$owner = self::owner_from_saved_referral();
		return $owner ?: self::owner_from_request();
	}

	/** Resolve permanent ownership for an authenticated customer before transient referral state. */
	public static function current_customer_owner(): ?array {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$owner = self::find_existing_owner( self::identities( '', $user->user_email, $user->ID ) );
			if ( $owner ) { return $owner; }
		}
		return self::current_referral_owner();
	}

	/** Ensure LiteSpeed never shares one gestora's storefront prices with another visitor. */
	public static function litespeed_vary_cookies( array $cookies ): array {
		$cookies[] = self::COOKIE_NAME;
		return array_values( array_unique( $cookies ) );
	}

	public static function capture_referral(): void {
		$raw_code = '';
		if ( isset( $_GET['ref'] ) ) {
			$raw_code = wp_unslash( $_GET['ref'] );
		} elseif ( isset( $_GET['cv_ref'] ) ) {
			$raw_code = wp_unslash( $_GET['cv_ref'] );
		}

		$code = self::sanitize_code( (string) $raw_code );
		if ( ! $code ) {
			return;
		}

		$owner = self::find_owner_by_code( $code );
		if ( ! $owner ) {
			return;
		}

		// First touch is permanent at browser level too: a later referral link cannot
		// replace a valid saved owner. Administration remains the only reassignment path.
		$saved_owner = self::owner_from_saved_referral();
		if ( $saved_owner ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$signed = self::signed_value( $code );
		$days = min( 400, max( 1, absint( get_option( 'cvd_cookie_days', 400 ) ) ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$signed,
				array(
					'expires'  => time() + ( DAY_IN_SECONDS * $days ),
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::COOKIE_NAME ] = $signed;
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $signed );
		}
	}

	public static function attach_to_order( WC_Order $order, array $data ): void {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : $order->get_billing_phone();
		$email = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : $order->get_billing_email();
		self::resolve_and_attach( $order, $phone, $email, $order->get_customer_id() );
	}

	/** Adjunta un pedido creado por una operadora sin saltarse el ownership permanente del cliente. */
	public static function attach_operator_order( WC_Order $order, int $preferred_owner_id = 0 ): void {
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id(), $preferred_owner_id );
	}

	public static function attach_store_api_order( WC_Order $order, WP_REST_Request $request ): void {
		unset( $request );
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id() );
	}

	private static function resolve_and_attach( WC_Order $order, string $phone, string $email, int $customer_id, int $preferred_owner_id = 0 ): void {
		if ( $order->get_meta( '_cvd_attribution_locked', true ) ) {
			return;
		}

		$identities = self::identities( $phone, $email, $customer_id );

		// Permanent customer ownership always wins. This protects a gestora's client
		// when the client later arrives organically, through another link, or when a
		// gestora places the order on the client's behalf.
		$owner = self::find_existing_owner( $identities );
		if ( ! $owner && $preferred_owner_id ) {
			$user = get_userdata( $preferred_owner_id );
			$code = self::sanitize_code( (string) get_user_meta( $preferred_owner_id, '_cvd_referral_code', true ) );
			if ( $user && $code && 'approved' === get_user_meta( $preferred_owner_id, '_cvd_account_status', true ) && array_intersect( array( 'cvd_gestora', 'cvd_influencer' ), (array) $user->roles ) ) {
				$owner = array( 'owner_user_id' => $preferred_owner_id, 'owner_type' => in_array( 'cvd_influencer', (array) $user->roles, true ) ? 'influencer' : 'gestora', 'referral_code' => $code, 'source' => 'operator_confirmed_voucher' );
			}
		}
		if ( ! $owner ) {
			$owner = self::owner_from_saved_referral();
		}
		if ( ! $owner ) {
			$owner = self::owner_from_request();
		}
		if ( ! $owner ) {
			$owner = self::owner_from_coupon( $order );
		}
		if ( ! $owner ) {
			$owner = self::owner_from_cart();
		}
		if ( ! $owner ) {
			$owner = array( 'owner_user_id' => 0, 'owner_type' => 'organic', 'referral_code' => '', 'source' => 'organic' );
		}

		$owner_id = absint( $owner['owner_user_id'] );
		$owner_name = $owner_id ? get_the_author_meta( 'display_name', $owner_id ) : 'Casa Viva · Venta directa';
		$referral_code = self::sanitize_code( (string) $owner['referral_code'] );
		$client_label = trim( $order->get_formatted_billing_full_name() );
		$client_label = $client_label ?: ( $phone ?: $email );

		$order->update_meta_data( '_cvd_owner_user_id', $owner_id );
		$order->update_meta_data( '_cvd_owner_type', sanitize_key( $owner['owner_type'] ) );
		$order->update_meta_data( '_cvd_owner_display_name', sanitize_text_field( (string) $owner_name ) );
		$order->update_meta_data( '_cvd_referral_code', $referral_code );
		$order->update_meta_data( '_cvd_attribution_source', sanitize_key( $owner['source'] ) );
		$order->update_meta_data( '_cvd_attribution_locked', 'yes' );
		$order->update_meta_data( '_cvd_referred_at', current_time( 'mysql', true ) );

		foreach ( $identities as $identity ) {
			$order->update_meta_data( '_cvd_identity_' . $identity['type'], $identity['hash'] );
		}

		$order->update_meta_data( 'gestora_id', $owner_id );
		$order->update_meta_data( 'gestora_codigo', $referral_code );
		$order->update_meta_data( 'gestora_nombre', sanitize_text_field( (string) $owner_name ) );
		$order->update_meta_data( 'cliente_vinculado', sanitize_text_field( $client_label ) );
		$order->update_meta_data( 'referido', $owner_id ? 'Sí' : 'No' );
		$order->update_meta_data( 'referido_fecha', current_time( 'mysql', true ) );
	}

	private static function identities( string $phone, string $email, int $customer_id ): array {
		$identities = array();
		$phone = preg_replace( '/\D+/', '', $phone );
		$email = sanitize_email( strtolower( trim( $email ) ) );

		// A gestora can checkout while logged in for a different customer. In that
		// case her own WordPress user ID is an operator identity, not the client.
		$use_customer_identity = $customer_id > 0 && ! self::is_program_operator( $customer_id );
		if ( $use_customer_identity ) {
			$identities[] = array( 'type' => 'customer', 'hash' => hash( 'sha256', (string) $customer_id ) );
		}
		if ( $phone ) {
			$identities[] = array( 'type' => 'phone', 'hash' => hash( 'sha256', $phone ) );
		}
		if ( $email ) {
			$identities[] = array( 'type' => 'email', 'hash' => hash( 'sha256', $email ) );
		}
		return $identities;
	}

	private static function is_program_operator( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return false; }
		$roles = (array) $user->roles;
		return (bool) array_intersect(
			array( 'cvd_gestora', 'cvd_influencer', 'cvd_messenger', 'cvd_clerk', 'cvd_operator', 'administrator', 'shop_manager' ),
			$roles
		);
	}

	private static function find_existing_owner( array $identities ): ?array {
		foreach ( $identities as $identity ) {
			$page = 1;
			do {
				$result = wc_get_orders(
					array(
						'limit'      => 20,
						'page'       => $page,
						'paginate'   => true,
						'orderby'    => 'date',
						'order'      => 'ASC',
						// Un pedido cancelado o fallido no vincula permanentemente al cliente.
						'status'     => array( 'pending', 'processing', 'on-hold', 'completed' ),
						// WooCommerce 8.2 no filtra de forma fiable el meta_query compuesto
						// en todos los motores de almacenamiento. La identidad exacta se
						// consulta como meta_key/meta_value y el owner se valida abajo.
						'meta_key'   => '_cvd_identity_' . $identity['type'],
						'meta_value' => $identity['hash'],
					)
				);

				$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : (array) $result;
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) { continue; }
					// Defensa adicional: aunque el data store ignore accidentalmente el
					// filtro, nunca aceptar una identidad distinta a la solicitada.
					$stored_hash = (string) $order->get_meta( '_cvd_identity_' . $identity['type'], true );
					if ( ! $stored_hash || ! hash_equals( $identity['hash'], $stored_hash ) ) { continue; }
					$owner_user_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
					$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
					if ( ! $owner_user_id || 'organic' === $owner_type ) { continue; }
					return array(
						'owner_user_id' => $owner_user_id,
						'owner_type'    => $owner_type,
						'referral_code' => self::sanitize_code( (string) $order->get_meta( '_cvd_referral_code', true ) ),
						'source'        => 'linked_customer',
					);
				}

				$max_pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1;
				$page++;
			} while ( $page <= $max_pages );
		}
		return null;
	}

	private static function owner_from_saved_referral(): ?array {
		$signed = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$signed = (string) WC()->session->get( self::SESSION_KEY, '' );
		}
		if ( ! $signed && ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$signed = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		}
		$code = self::code_from_signed_value( $signed );
		$owner = $code ? self::find_owner_by_code( $code ) : null;
		if ( $owner ) {
			$owner['source'] = 'referral_link';
		}
		return $owner;
	}

	private static function owner_from_request(): ?array {
		$raw_code = isset( $_GET['ref'] ) ? wp_unslash( $_GET['ref'] ) : ( isset( $_GET['cv_ref'] ) ? wp_unslash( $_GET['cv_ref'] ) : '' );
		$code = self::sanitize_code( (string) $raw_code );
		return $code ? self::find_owner_by_code( $code ) : null;
	}

	private static function owner_from_cart(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$owner_ids = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$user_id = absint( $cart_item['_cvd_gestora_id'] ?? 0 );
			if ( $user_id ) { $owner_ids[ $user_id ] = true; }
		}
		if ( 1 !== count( $owner_ids ) ) {
			return null;
		}
		$user_id = (int) array_key_first( $owner_ids );
		return array(
			'owner_user_id' => $user_id,
			'owner_type'    => 'gestora',
			'referral_code' => self::sanitize_code( (string) get_user_meta( $user_id, '_cvd_referral_code', true ) ),
			'source'        => 'cart_snapshot',
		);
	}

	private static function owner_from_coupon( WC_Order $order ): ?array {
		foreach ( $order->get_coupon_codes() as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );
			$user_id = absint( $coupon->get_meta( '_cvd_owner_user_id', true ) );
			if ( $user_id ) {
				return array(
					'owner_user_id' => $user_id,
					'owner_type'    => sanitize_key( $coupon->get_meta( '_cvd_owner_type', true ) ?: 'influencer' ),
					'referral_code' => self::sanitize_code( $coupon_code ),
					'source'        => 'coupon',
				);
			}
			$owner = self::find_owner_by_code( self::sanitize_code( $coupon_code ) );
			if ( $owner ) {
				$owner['source'] = 'coupon';
				return $owner;
			}
		}
		return null;
	}

	private static function find_owner_by_code( string $code ): ?array {
		$users = get_users(
			array(
				'number'     => 1,
				'meta_key'   => '_cvd_referral_code',
				'meta_value' => $code,
				'fields'     => 'all',
			)
		);
		if ( ! $users && preg_match( '/^CV(\d+)([A-Z0-9]{1,8})$/', $code, $matches ) ) {
			$legacy_user = get_user_by( 'id', absint( $matches[1] ) );
			$expected = $legacy_user ? strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $legacy_user->user_login ), 0, 8 ) ) : '';
			if ( $legacy_user && hash_equals( $expected, $matches[2] ) ) {
				$users = array( $legacy_user );
				update_user_meta( $legacy_user->ID, '_cvd_referral_code', $code );
			}
		}
		if ( ! $users ) {
			return null;
		}
		$user = $users[0];
		if ( 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) {
			return null;
		}
		return array(
			'owner_user_id' => $user->ID,
			'owner_type'    => in_array( 'cvd_influencer', (array) $user->roles, true ) ? 'influencer' : 'gestora',
			'referral_code' => $code,
			'source'        => 'referral_link',
		);
	}

	private static function signed_value( string $code ): string {
		$issued_at = time();
		$payload = $code . '|' . $issued_at;
		return base64_encode( $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );
	}

	private static function code_from_signed_value( string $signed ): string {
		$decoded = base64_decode( $signed, true );
		if ( ! $decoded ) {
			return '';
		}
		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		list( $code, $issued_at, $signature ) = $parts;
		$payload = $code . '|' . $issued_at;
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $signature ) ) {
			return '';
		}
		return self::sanitize_code( $code );
	}

	private static function sanitize_code( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $code ) ) );
	}
}
