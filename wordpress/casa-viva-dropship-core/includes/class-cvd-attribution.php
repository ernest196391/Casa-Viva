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

	public static function current_referral_owner(): ?array {
		$owner = self::owner_from_request();
		return $owner ?: self::owner_from_saved_referral();
	}

	/** Resolve the permanent owner for the authenticated customer before cookie fallback. */
	public static function current_customer_owner(): ?array {
		$active_referral = self::current_referral_owner();
		if ( $active_referral ) { return $active_referral; }
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$owner = self::find_existing_owner( self::identities( '', $user->user_email, $user->ID ) );
			if ( $owner ) { return $owner; }
		}
		return null;
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

	public static function attach_store_api_order( WC_Order $order, WP_REST_Request $request ): void {
		unset( $request );
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id() );
	}

	private static function resolve_and_attach( WC_Order $order, string $phone, string $email, int $customer_id ): void {
		if ( $order->get_meta( '_cvd_attribution_locked', true ) ) {
			return;
		}

		$identities = self::identities( $phone, $email, $customer_id );
		$owner = self::owner_from_request();
		if ( ! $owner ) {
			$owner = self::owner_from_saved_referral();
		}
		if ( ! $owner ) {
			$owner = self::find_existing_owner( $identities );
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
		if ( $customer_id > 0 ) {
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

	private static function find_existing_owner( array $identities ): ?array {
		foreach ( $identities as $identity ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'orderby'    => 'date',
					'order'      => 'ASC',
					// Un pedido cancelado o fallido no vincula permanentemente al cliente.
					'status'     => array( 'pending', 'processing', 'on-hold', 'completed' ),
					'meta_query' => array(
						array( 'key' => '_cvd_identity_' . $identity['type'], 'value' => $identity['hash'] ),
						array( 'key' => '_cvd_owner_user_id', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
					),
				)
			);
			if ( $orders ) {
				$order = $orders[0];
				return array(
					'owner_user_id' => absint( $order->get_meta( '_cvd_owner_user_id', true ) ),
					'owner_type'    => sanitize_key( $order->get_meta( '_cvd_owner_type', true ) ),
					'referral_code' => self::sanitize_code( (string) $order->get_meta( '_cvd_referral_code', true ) ),
					'source'        => 'linked_customer',
				);
			}
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
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$user_id = absint( $cart_item['_cvd_gestora_id'] ?? 0 );
			if ( $user_id ) {
				return array(
					'owner_user_id' => $user_id,
					'owner_type'    => 'gestora',
					'referral_code' => self::sanitize_code( (string) get_user_meta( $user_id, '_cvd_referral_code', true ) ),
					'source'        => 'cart_snapshot',
				);
			}
		}
		return null;
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
