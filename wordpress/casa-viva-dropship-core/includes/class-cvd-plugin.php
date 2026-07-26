<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Plugin {
	private static ?CVD_Plugin $instance = null;

	public static function instance(): CVD_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$owners_table   = $wpdb->prefix . 'cvd_customer_owners';
		$ledger_table   = $wpdb->prefix . 'cvd_commissions';

		dbDelta(
			"CREATE TABLE {$owners_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				identity_type varchar(24) NOT NULL,
				identity_hash char(64) NOT NULL,
				customer_id bigint(20) unsigned NULL,
				owner_user_id bigint(20) unsigned NULL,
				owner_type varchar(24) NOT NULL DEFAULT 'organic',
				referral_code varchar(80) NULL,
				source varchar(32) NOT NULL DEFAULT 'organic',
				created_at datetime NOT NULL,
				locked_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY identity (identity_type, identity_hash),
				KEY customer_id (customer_id),
				KEY owner_user_id (owner_user_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$ledger_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				owner_user_id bigint(20) unsigned NOT NULL,
				owner_type varchar(24) NOT NULL,
				amount decimal(18,4) NOT NULL DEFAULT 0,
				base_amount decimal(18,4) NOT NULL DEFAULT 0,
				rate decimal(8,4) NOT NULL DEFAULT 0,
				currency varchar(12) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_owner (order_id, owner_user_id),
				KEY owner_status (owner_user_id, status),
				KEY order_id (order_id)
			) {$charset_collate};"
		);

		$customer = get_role( 'customer' );
		$base_caps = $customer ? $customer->capabilities : array( 'read' => true );

		add_role( 'cvd_gestora', 'Gestora', $base_caps );
		add_role( 'cvd_influencer', 'Influencer', $base_caps );
		add_role( 'cvd_supplier', 'Proveedor', $base_caps );

		add_option( 'cvd_default_commission_rate', '13' );
		add_option( 'cvd_cookie_days', '400' );
		add_option( 'cvd_central_whatsapp', '' );
		add_option( 'cvd_attribution_model', 'first_touch_lifetime' );
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
			return;
		}

		require_once CVD_DIR . 'includes/class-cvd-attribution.php';
		require_once CVD_DIR . 'includes/class-cvd-commissions.php';
		require_once CVD_DIR . 'includes/class-cvd-admin.php';
		require_once CVD_DIR . 'includes/class-cvd-portal.php';
		require_once CVD_DIR . 'includes/class-cvd-whatsapp-gateway.php';

		CVD_Attribution::register();
		CVD_Commissions::register();
		CVD_Admin::register();
		CVD_Portal::register();
		CVD_WhatsApp_Gateway::register();
	}

	public function woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Casa Viva Dropship Core necesita WooCommerce activo.', 'casa-viva-dropship' );
		echo '</p></div>';
	}

	private function __construct() {}
}
