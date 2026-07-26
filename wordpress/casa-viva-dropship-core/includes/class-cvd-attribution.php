<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Attribution {
	private const COOKIE_NAME = 'cvd_referral';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'capture_referral' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_to_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'attach_store_api_order' ), 10, 2 );
	}

	public static function capture_referral(): void {
		if ( headers_sent() || isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		$raw_code = '';
		if ( isset( $_GET['ref'] ) ) {
			$raw_code = wp_unslash( $_GET['ref'] );
		} elseif ( isset( $_GET['cv_ref'] ) ) {
			$raw_code = wp_unslash( $_GET['cv_ref'] );
		}

		$code = self::sanitize_code( $raw_code );
		if ( '' === $code || ! self::find_owner_by_code( $code ) ) {
			return;
		}

		$issued_at = time();
		$payload   = $code . '|' . $issued_at;
		$signed    = base64_encode( $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );
		$days      = min( 400, max( 1, absint( get_option( 'cvd_cookie_days', 400 ) ) ) );

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

		$_COOKIE[ self::COOKIE_NAME ] = $signed;
	}

	public static function attach_to_order( WC_Order $order, array $data ): void {
		$phone       = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : $order->get_billing_phone();
		$email       = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : $order->get_billing_email();
		$customer_id = $order->get_customer_id();

		self::resolve_and_attach( $order, $phone, $email, $customer_id );
	}

	public static function attach_store_api_order( WC_Order $order, WP_REST_Request $request ): void {
		unset( $request );
		self::resolve_and_attach(
			$order,
			$order->get_billing_phone(),
			$order->get_billing_email(),
			$order->get_customer_id()
		);
	}

	private static function resolve_and_attach( WC_Order $order, string $phone, string $email, int $customer_id ): void {
		if ( $order->get_meta( '_cvd_attribution_locked', true ) ) {
			return;
		}

		$identities = self::identities( $phone, $email, $customer_id );
		$owner      = self::find_existing_owner( $identities );

		if ( ! $owner ) {
			$owner = self::owner_from_coupon( $order );
		}

		if ( ! $owner ) {
			$owner = self::owner_from_cookie();
		}

		if ( ! $owner ) {
			$owner = array(
				'owner_user_id' => 0,
				'owner_type'    => 'organic',
				'referral_code' => '',
				'source'        => 'organic',
			);
		}

		self::persist_owner( $identities, $customer_id, $owner );

		$order->update_meta_data( '_cvd_owner_user_id', absint( $owner['owner_user_id'] ) );
		$order->update_meta_data( '_cvd_owner_type', sanitize_key( $owner['owner_type'] ) );
		$order->update_meta_data( '_cvd_referral_code', self::sanitize_code( $owner['referral_code'] ) );
		$order->update_meta_data( '_cvd_attribution_source', sanitize_key( $owner['source'] ) );
		$order->update_meta_data( '_cvd_attribution_locked', 'yes' );
	}

	private static function identities( string $phone, string $email, int $customer_id ): array {
		$identities = array();
		$phone      = preg_replace( '/\D+/', '', $phone );
		$email      = sanitize_email( strtolower( trim( $email ) ) );

		if ( $customer_id > 0 ) {
			$identities[] = array( 'type' => 'customer_id', 'hash' => hash( 'sha256', (string) $customer_id ) );
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
		global $wpdb;

		$table = $wpdb->prefix . 'cvd_customer_owners';

		foreach ( $identities as $identity ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT owner_user_id, owner_type, referral_code, source
					FROM {$table}
					WHERE identity_type = %s AND identity_hash = %s
					LIMIT 1",
					$identity['type'],
					$identity['hash']
				),
				ARRAY_A
			);

			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	private static function persist_owner( array $identities, int $customer_id, array $owner ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cvd_customer_owners';
		$now   = current_time( 'mysql', true );

		foreach ( $identities as $identity ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
					(identity_type, identity_hash, customer_id, owner_user_id, owner_type, referral_code, source, created_at, locked_at)
					VALUES (%s, %s, %d, %d, %s, %s, %s, %s, %s)",
					$identity['type'],
					$identity['hash'],
					$customer_id,
					absint( $owner['owner_user_id'] ),
					sanitize_key( $owner['owner_type'] ),
					self::sanitize_code( $owner['referral_code'] ),
					sanitize_key( $owner['source'] ),
					$now,
					$now
				)
			);
		}
	}

	private static function owner_from_cookie(): ?array {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$decoded = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ), true );
		if ( ! $decoded ) {
			return null;
		}

		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $code, $issued_at, $signature ) = $parts;
		$payload = $code . '|' . $issued_at;
		$valid   = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		if ( ! hash_equals( $valid, $signature ) ) {
			return null;
		}

		$owner = self::find_owner_by_code( self::sanitize_code( $code ) );
		if ( ! $owner ) {
			return null;
		}

		$owner['source'] = 'referral_link';
		return $owner;
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

		if ( empty( $users ) ) {
			return null;
		}

		$user = $users[0];
		$type = in_array( 'cvd_influencer', (array) $user->roles, true ) ? 'influencer' : 'gestora';

		return array(
			'owner_user_id' => $user->ID,
			'owner_type'    => $type,
			'referral_code' => $code,
			'source'        => 'referral_link',
		);
	}

	private static function sanitize_code( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $code ) ) );
	}
}
