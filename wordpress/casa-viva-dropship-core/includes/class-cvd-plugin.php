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
		$prices_table   = $wpdb->prefix . 'cvd_gestora_prices';
		$inventory_table = $wpdb->prefix . 'cvd_inventory_movements';
		$payouts_table = $wpdb->prefix . 'cvd_payouts';
		$payout_items_table = $wpdb->prefix . 'cvd_payout_items';
		$payout_events_table = $wpdb->prefix . 'cvd_payout_events';
		$delivery_locations_table = $wpdb->prefix . 'cvd_delivery_locations';
		$push_subscriptions_table = $wpdb->prefix . 'cvd_push_subscriptions';
		$notifications_table = $wpdb->prefix . 'cvd_notifications';
		$messenger_ledger_table = $wpdb->prefix . 'cvd_messenger_ledger';
		$messenger_settlements_table = $wpdb->prefix . 'cvd_messenger_settlements';
		$messenger_settlement_items_table = $wpdb->prefix . 'cvd_messenger_settlement_items';
		$order_events_table = $wpdb->prefix . 'cvd_order_events';

		dbDelta(
			"CREATE TABLE {$order_events_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(71) NOT NULL,
				idempotency_key char(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				event_type varchar(64) NOT NULL,
				domain varchar(24) NOT NULL,
				from_state varchar(64) NOT NULL DEFAULT '',
				to_state varchar(64) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor_role varchar(64) NOT NULL DEFAULT 'unknown',
				occurred_at datetime NOT NULL,
				source varchar(96) NOT NULL DEFAULT 'unknown',
				metadata longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_id (event_id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY order_timeline (order_id,occurred_at,id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prices_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				gestora_user_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				price decimal(18,4) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY gestora_product (gestora_user_id, product_id),
				KEY product_id (product_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$inventory_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				movement_uuid varchar(64) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				movement_type varchar(24) NOT NULL,
				quantity_delta decimal(18,4) NOT NULL DEFAULT 0,
				stock_before decimal(18,4) NOT NULL DEFAULT 0,
				stock_after decimal(18,4) NOT NULL DEFAULT 0,
				reason varchar(255) NOT NULL DEFAULT '',
				reference_type varchar(32) NOT NULL DEFAULT '',
				reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY movement_uuid (movement_uuid),
				KEY product_created (product_id, created_at),
				KEY actor_created (actor_user_id, created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$payouts_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payout_uuid varchar(64) NOT NULL,
				owner_user_id bigint(20) unsigned NOT NULL,
				amount decimal(18,4) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT 'USD',
				status varchar(24) NOT NULL DEFAULT 'requested',
				method varchar(32) NOT NULL DEFAULT '',
				account_value longtext NULL,
				qr_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reference varchar(190) NOT NULL DEFAULT '',
				proof_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requested_at datetime NULL,
				approved_at datetime NULL,
				paid_at datetime NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY payout_uuid (payout_uuid),
				KEY owner_status (owner_user_id, status),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$payout_items_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payout_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				amount decimal(18,4) NOT NULL DEFAULT 0,
				base_commission decimal(18,4) NOT NULL DEFAULT 0,
				markup decimal(18,4) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT 'USD',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY payout_order (payout_id, order_id),
				KEY order_id (order_id)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$payout_events_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				payout_id bigint(20) unsigned NOT NULL,
				event_type varchar(32) NOT NULL,
				from_status varchar(24) NOT NULL DEFAULT '',
				to_status varchar(24) NOT NULL DEFAULT '',
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				KEY payout_created (payout_id, created_at)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$delivery_locations_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				messenger_user_id bigint(20) unsigned NOT NULL,
				latitude decimal(10,7) NOT NULL,
				longitude decimal(10,7) NOT NULL,
				accuracy decimal(10,2) NOT NULL DEFAULT 0,
				recorded_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_recorded (order_id, recorded_at),
				KEY messenger_recorded (messenger_user_id, recorded_at)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$push_subscriptions_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				endpoint_hash char(64) NOT NULL,
				device_token char(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL,
				endpoint text NOT NULL,
				user_agent varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_success_at datetime NULL,
				failure_count smallint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY endpoint_hash (endpoint_hash),
				KEY device_token (device_token),
				KEY user_id (user_id)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$notifications_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(40) NOT NULL,
				title varchar(190) NOT NULL,
				message text NOT NULL,
				action_url text NOT NULL,
				created_at datetime NOT NULL,
				read_at datetime NULL,
				PRIMARY KEY  (id),
				KEY user_created (user_id,created_at),
				KEY order_id (order_id)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$messenger_ledger_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entry_uuid varchar(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				messenger_user_id bigint(20) unsigned NOT NULL,
				entry_type varchar(24) NOT NULL DEFAULT 'earning',
				amount decimal(18,4) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT 'CUP',
				status varchar(24) NOT NULL DEFAULT 'available',
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entry_uuid (entry_uuid),
				UNIQUE KEY order_entry (order_id,entry_type),
				KEY messenger_status (messenger_user_id,status)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$messenger_settlements_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				settlement_uuid varchar(64) NOT NULL,
				messenger_user_id bigint(20) unsigned NOT NULL,
				amount decimal(18,4) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT 'CUP',
				status varchar(24) NOT NULL DEFAULT 'requested',
				method varchar(32) NOT NULL DEFAULT '',
				reference varchar(190) NOT NULL DEFAULT '',
				proof_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				requested_at datetime NOT NULL,
				paid_at datetime NULL,
				paid_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY settlement_uuid (settlement_uuid),
				KEY messenger_status (messenger_user_id,status)
			) {$charset_collate};"
		);
		dbDelta(
			"CREATE TABLE {$messenger_settlement_items_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				settlement_id bigint(20) unsigned NOT NULL,
				ledger_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				amount decimal(18,4) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY settlement_ledger (settlement_id,ledger_id),
				UNIQUE KEY ledger_id (ledger_id)
			) {$charset_collate};"
		);

		$customer = get_role( 'customer' );
		$base_caps = $customer ? $customer->capabilities : array( 'read' => true );

		add_role( 'cvd_gestora', 'Gestora', $base_caps );
		add_role( 'cvd_influencer', 'Influencer', $base_caps );
		add_role( 'cvd_supplier', 'Proveedor', $base_caps );
		add_role( 'cvd_messenger', 'Mensajero', $base_caps );
		add_role( 'cvd_clerk', 'Dependienta', $base_caps );
		add_role( 'cvd_operator', 'Operador Casa Viva', $base_caps );
		foreach ( array( 'cvd_clerk', 'cvd_operator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'cvd_manage_inventory' );
				$role->add_cap( 'cvd_manage_sales' );
			}
		}
		$operator = get_role( 'cvd_operator' );
		if ( $operator ) { $operator->add_cap( 'cvd_view_operations' ); }

		add_option( 'cvd_default_commission_rate', '13' );
		add_option( 'cvd_cookie_days', '400' );
		add_option( 'cvd_central_whatsapp', '5354056173' );
		if ( ! get_option( 'cvd_central_whatsapp' ) ) { update_option( 'cvd_central_whatsapp', '5354056173' ); }
		add_option( 'cvd_attribution_model', 'first_touch_lifetime' );
		add_option( 'cvd_default_province', 'LH' );
		add_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' );
		add_option( 'cvd_default_max_markup_percent', '30' );
		add_option( 'cvd_notification_email', get_option( 'admin_email' ) );
		add_option( 'cvd_shipping_platform_percent', '10' );
		add_option( 'cvd_location_retention_days', '30' );
		add_option( 'cvd_dispatch_zone_weight', '30' );
		add_option( 'cvd_dispatch_rating_weight', '25' );
		add_option( 'cvd_dispatch_completion_weight', '20' );
		add_option( 'cvd_dispatch_speed_weight', '15' );
		add_option( 'cvd_dispatch_fairness_weight', '10' );
		add_option( 'cvd_dispatch_first_wave_size', '2' );
		add_option( 'cvd_dispatch_wave_delay_seconds', '90' );
		self::normalize_program_roles();
		require_once CVD_DIR . 'includes/class-cvd-receipt-links.php';
		require_once CVD_DIR . 'includes/class-cvd-shipping-rates.php';
		require_once CVD_DIR . 'includes/class-cvd-gestora-store.php';
		require_once CVD_DIR . 'includes/class-cvd-catalog-quality.php';
		CVD_Shipping_Rates::install_defaults();
		CVD_Catalog_Quality::install();
		CVD_Receipt_Links::add_rewrite_rules();
		require_once CVD_DIR . 'includes/class-cvd-pwa.php';
		require_once CVD_DIR . 'includes/class-cvd-inventory.php';
		CVD_PWA::add_rewrite_rules();
		CVD_Inventory::install_codes();
		require_once CVD_DIR . 'includes/class-cvd-web-push.php';
		require_once CVD_DIR . 'includes/class-cvd-messenger-accounting.php';
		require_once CVD_DIR . 'includes/class-cvd-messenger-reputation.php';
		CVD_Web_Push::ensure_keys();
		flush_rewrite_rules( false );
		self::create_pages();
		self::configure_checkout_page();
		self::notify_existing_pending_applications();
		update_option( 'cvd_version', CVD_VERSION );
	}

	/** Repair legacy accounts that received both program roles or a messenger referral code. */
	private static function normalize_program_roles(): void {
		global $wpdb;
		$users = get_users( array( 'role__in' => array( 'cvd_gestora', 'cvd_messenger' ), 'fields' => 'all' ) );
		foreach ( $users as $user ) {
			$roles = (array) $user->roles;
			$gestora = in_array( 'cvd_gestora', $roles, true );
			$messenger = in_array( 'cvd_messenger', $roles, true );
			$type = sanitize_key( (string) get_user_meta( $user->ID, '_cvd_program_type', true ) );
			$has_gestora_prices = (bool) $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}cvd_gestora_prices WHERE gestora_user_id = %d LIMIT 1", $user->ID )
			);
			$has_gestora_activity = $has_gestora_prices || (float) get_user_meta( $user->ID, '_cvd_global_markup_percent', true ) > 0;
			if ( $has_gestora_activity ) { $type = 'gestora'; }
			if ( ! in_array( $type, array( 'gestora', 'mensajero' ), true ) ) {
				$type = $messenger && ! $gestora ? 'mensajero' : ( $gestora && $messenger && get_user_meta( $user->ID, '_cvd_vehicle', true ) ? 'mensajero' : 'gestora' );
			}
			update_user_meta( $user->ID, '_cvd_program_type', $type );
			if ( 'mensajero' === $type ) {
				$user->remove_role( 'cvd_gestora' );
				delete_user_meta( $user->ID, '_cvd_referral_code' );
			} else {
				$user->remove_role( 'cvd_messenger' );
			}
		}
	}

	private static function notify_existing_pending_applications(): void {
		$users = get_users(
			array(
				'role'       => 'cvd_gestora',
				'meta_key'   => '_cvd_account_status',
				'meta_value' => 'pending',
				'fields'     => 'all',
			)
		);
		$users = array_values(
			array_filter(
				$users,
				static fn( WP_User $user ): bool => ! get_user_meta( $user->ID, '_cvd_admin_notification_sent', true )
			)
		);
		if ( ! $users ) {
			return;
		}

		$admin_email = sanitize_email( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ) );
		if ( ! $admin_email ) {
			return;
		}
		$lines = array( 'Casa Viva tiene solicitudes de gestoras pendientes:', '' );
		foreach ( $users as $user ) {
			$lines[] = '- ' . $user->display_name . ' · ' . $user->user_email;
		}
		$lines[] = '';
		$lines[] = 'Revisar y aprobar:';
		$lines[] = admin_url( 'admin.php?page=cvd-gestoras' );
		$sent = wp_mail( $admin_email, 'Solicitudes de gestoras pendientes en Casa Viva', implode( "\n", $lines ) );
		foreach ( $users as $user ) {
			update_user_meta( $user->ID, '_cvd_admin_notification_sent', $sent ? current_time( 'mysql', true ) : 'failed' );
			if ( ! get_user_meta( $user->ID, '_cvd_application_email_sent', true ) ) {
				$applicant_message = "Hola {$user->display_name},\n\nTu solicitud de gestora está registrada y pendiente de aprobación por Casa Viva.\n\nPuedes consultar el estado aquí:\n" . home_url( '/area-gestoras/' );
				$applicant_sent = wp_mail( $user->user_email, 'Casa Viva recibió tu solicitud', $applicant_message );
				update_user_meta( $user->ID, '_cvd_application_email_sent', $applicant_sent ? current_time( 'mysql', true ) : 'failed' );
			}
		}
	}

	private static function configure_checkout_page(): void {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( $checkout_page_id <= 0 ) {
			return;
		}

		$page = get_post( $checkout_page_id );
		if ( ! $page || false === strpos( $page->post_content, 'woocommerce/checkout' ) ) {
			return;
		}

		if ( ! get_post_meta( $checkout_page_id, '_cvd_checkout_blocks_backup', true ) ) {
			update_post_meta( $checkout_page_id, '_cvd_checkout_blocks_backup', $page->post_content );
		}

		wp_update_post(
			array(
				'ID'           => $checkout_page_id,
				'post_content' => '[woocommerce_checkout]',
			)
		);
	}

	public static function create_pages(): void {
		$pages = array(
			'registro-gestora'  => array( 'Registro de gestoras', '[casa_viva_registro role="gestora"]' ),
			'area-gestoras'     => array( 'Gestoras · Acceso', '[casa_viva_portal role="gestora"]' ),
			'registro-mensajero'=> array( 'Registro de mensajeros', '[casa_viva_registro role="mensajero"]' ),
			'area-mensajeros'   => array( 'Área de mensajeros', '[casa_viva_portal role="mensajero"]' ),
			'gestores'          => array( 'Gestoras', '[casa_viva_gestores]' ),
			'casa-viva-app'     => array( 'Casa Viva App', '[casa_viva_app]' ),
			'centro-operaciones'=> array( 'Centro de operaciones', '[casa_viva_operations]' ),
			'inventario'        => array( 'Inventario Casa Viva', '[casa_viva_inventory]' ),
			'ventas'            => array( 'Centro de ventas', '[casa_viva_sales]' ),
			'contabilidad'      => array( 'Contabilidad Casa Viva', '[casa_viva_accounting]' ),
			'contabilidad-mensajeros' => array( 'Liquidaciones de mensajeros', '[casa_viva_messenger_accounting]' ),
			'mensajeria'        => array( 'Mensajería Casa Viva', '[casa_viva_delivery_control]' ),
			'seguimiento'       => array( 'Seguimiento de pedido', '[casa_viva_order_tracking]' ),
		);
		foreach ( $pages as $slug => $page ) {
			$existing = get_page_by_path( $slug );
			if ( $existing instanceof WP_Post ) {
				wp_update_post( array( 'ID' => $existing->ID, 'post_title' => $page[0] ) );
				continue;
			}
			wp_insert_post( array( 'post_title' => $page[0], 'post_name' => $slug, 'post_content' => $page[1], 'post_status' => 'publish', 'post_type' => 'page' ) );
		}
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'cvd_version' ) === CVD_VERSION ) { return; }
		self::activate();
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
			return;
		}

		require_once CVD_DIR . 'includes/class-cvd-attribution.php';
		require_once CVD_DIR . 'includes/class-cvd-canonical-order-reader.php';
		require_once CVD_DIR . 'includes/class-cvd-order-events.php';
		require_once CVD_DIR . 'includes/class-cvd-order-transition-service.php';
		require_once CVD_DIR . 'includes/class-cvd-commissions.php';
		require_once CVD_DIR . 'includes/class-cvd-admin.php';
		require_once CVD_DIR . 'includes/class-cvd-portal.php';
		require_once CVD_DIR . 'includes/class-cvd-registration.php';
		require_once CVD_DIR . 'includes/class-cvd-delivery.php';
		require_once CVD_DIR . 'includes/class-cvd-receipt-links.php';
		require_once CVD_DIR . 'includes/class-cvd-whatsapp-receipt-template.php';
		require_once CVD_DIR . 'includes/class-cvd-whatsapp-gateway.php';
		require_once CVD_DIR . 'includes/class-cvd-cuban-checkout.php';
		require_once CVD_DIR . 'includes/class-cvd-shipping-rates.php';
		require_once CVD_DIR . 'includes/class-cvd-gestora-store.php';
		require_once CVD_DIR . 'includes/class-cvd-catalog-quality.php';
		require_once CVD_DIR . 'includes/class-cvd-pwa.php';
		require_once CVD_DIR . 'includes/class-cvd-pilot-accounts.php';
		require_once CVD_DIR . 'includes/class-cvd-inventory.php';
		require_once CVD_DIR . 'includes/class-cvd-sales.php';
		require_once CVD_DIR . 'includes/class-cvd-payouts.php';
		require_once CVD_DIR . 'includes/class-cvd-promotional-resources.php';
		require_once CVD_DIR . 'includes/class-cvd-live-tracking.php';
		require_once CVD_DIR . 'includes/class-cvd-web-push.php';
		require_once CVD_DIR . 'includes/class-cvd-messenger-accounting.php';
		require_once CVD_DIR . 'includes/class-cvd-messenger-reputation.php';

		CVD_Attribution::register();
		CVD_Order_Events::register();
		CVD_Commissions::register();
		CVD_Admin::register();
		CVD_Portal::register();
		CVD_Registration::register();
		CVD_Delivery::register();
		CVD_Receipt_Links::register();
		CVD_WhatsApp_Gateway::register();
		CVD_Cuban_Checkout::register();
		CVD_Shipping_Rates::register();
		CVD_Gestora_Store::register();
		CVD_Catalog_Quality::register();
		CVD_PWA::register();
		CVD_Pilot_Accounts::register();
		CVD_Inventory::register();
		CVD_Sales::register();
		CVD_Payouts::register();
		CVD_Promotional_Resources::register();
		CVD_Live_Tracking::register();
		CVD_Web_Push::register();
		CVD_Messenger_Accounting::register();
		CVD_Messenger_Reputation::register();
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
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
