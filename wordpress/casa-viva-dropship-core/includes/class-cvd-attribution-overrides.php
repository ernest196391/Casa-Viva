<?php

defined( 'ABSPATH' ) || exit;

/**
 * Excepciones administrativas de atribución sin reescribir pedidos históricos.
 * Cada reasignación es append-only y afecta únicamente pedidos futuros del cliente.
 */
final class CVD_Attribution_Overrides {
	private const SCHEMA_VERSION = '1';
	private const SCHEMA_OPTION = 'cvd_attribution_override_schema';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'apply_checkout_override' ), 29, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'apply_store_api_override' ), 29, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_fields' ), 15 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_admin_reassignment' ), 20 );
	}

	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'cvd_attribution_overrides';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_uuid varchar(64) NOT NULL,
				identity_type varchar(24) NOT NULL,
				identity_hash char(64) NOT NULL,
				from_owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				to_owner_user_id bigint(20) unsigned NOT NULL,
				reason varchar(255) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL,
				source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY identity_latest (identity_type,identity_hash,id),
				KEY source_order (source_order_id,id),
				KEY target_owner (to_owner_user_id,id),
				KEY event_uuid (event_uuid)
			) {$charset_collate};"
		);
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function apply_checkout_override( WC_Order $order, array $data ): void {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : $order->get_billing_phone();
		$email = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : $order->get_billing_email();
		self::apply_override( $order, self::identities( $phone, $email, $order->get_customer_id() ) );
	}

	public static function apply_store_api_override( WC_Order $order, WP_REST_Request $request ): void {
		unset( $request );
		self::apply_override( $order, self::identities( $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id() ) );
	}

	private static function apply_override( WC_Order $order, array $identities ): void {
		if ( $order->get_meta( '_cvd_attribution_locked', true ) ) {
			return;
		}
		$override = self::latest_override( $identities );
		if ( ! $override ) {
			return;
		}
		$owner = self::approved_owner( absint( $override['to_owner_user_id'] ?? 0 ) );
		if ( ! $owner ) {
			return;
		}
		$owner_type = in_array( 'cvd_influencer', (array) $owner->roles, true ) ? 'influencer' : 'gestora';
		$code = self::sanitize_code( (string) get_user_meta( $owner->ID, '_cvd_referral_code', true ) );
		$name = $owner->display_name ?: $owner->user_login;
		$client_label = trim( $order->get_formatted_billing_full_name() );
		$client_label = $client_label ?: ( $order->get_billing_phone() ?: $order->get_billing_email() );

		$order->update_meta_data( '_cvd_owner_user_id', $owner->ID );
		$order->update_meta_data( '_cvd_owner_type', $owner_type );
		$order->update_meta_data( '_cvd_owner_display_name', sanitize_text_field( $name ) );
		$order->update_meta_data( '_cvd_referral_code', $code );
		$order->update_meta_data( '_cvd_attribution_source', 'admin_reassignment' );
		$order->update_meta_data( '_cvd_attribution_override_event_uuid', sanitize_text_field( (string) $override['event_uuid'] ) );
		$order->update_meta_data( '_cvd_attribution_locked', 'yes' );
		$order->update_meta_data( '_cvd_referred_at', current_time( 'mysql', true ) );
		foreach ( $identities as $identity ) {
			$order->update_meta_data( '_cvd_identity_' . $identity['type'], $identity['hash'] );
		}
		$order->update_meta_data( 'gestora_id', $owner->ID );
		$order->update_meta_data( 'gestora_codigo', $code );
		$order->update_meta_data( 'gestora_nombre', sanitize_text_field( $name ) );
		$order->update_meta_data( 'cliente_vinculado', sanitize_text_field( $client_label ) );
		$order->update_meta_data( 'referido', 'Sí' );
		$order->update_meta_data( 'referido_fecha', current_time( 'mysql', true ) );
	}

	public static function reassign_from_order( int $order_id, int $new_owner_id, string $reason ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'cvd_reassignment_forbidden', 'No tienes permiso para reasignar clientes.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cvd_reassignment_order', 'Pedido no encontrado.' );
		}
		$owner = self::approved_owner( $new_owner_id );
		if ( ! $owner ) {
			return new WP_Error( 'cvd_reassignment_owner', 'La nueva propietaria debe ser una gestora o influencer aprobada.' );
		}
		$reason = sanitize_text_field( trim( $reason ) );
		if ( strlen( $reason ) < 4 ) {
			return new WP_Error( 'cvd_reassignment_reason', 'Es obligatorio indicar un motivo claro.' );
		}
		$identities = self::stored_identities( $order );
		if ( ! $identities ) {
			return new WP_Error( 'cvd_reassignment_identity', 'El pedido no contiene una identidad de cliente reutilizable.' );
		}

		global $wpdb;
		self::maybe_upgrade();
		$table = $wpdb->prefix . 'cvd_attribution_overrides';
		$lock = 'cvd-attribution-reassign-' . $order_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) {
			return new WP_Error( 'cvd_reassignment_busy', 'Ya se está procesando otra reasignación.' );
		}
		$event_uuid = wp_generate_uuid4();
		$actor_id = get_current_user_id();
		$from_owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$now = current_time( 'mysql', true );
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'No se pudo iniciar la reasignación.' );
			}
			foreach ( $identities as $identity ) {
				$inserted = $wpdb->insert(
					$table,
					array(
						'event_uuid' => $event_uuid,
						'identity_type' => $identity['type'],
						'identity_hash' => $identity['hash'],
						'from_owner_user_id' => $from_owner_id,
						'to_owner_user_id' => $owner->ID,
						'reason' => $reason,
						'actor_user_id' => $actor_id,
						'source_order_id' => $order_id,
						'created_at' => $now,
					),
					array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s' )
				);
				if ( 1 !== $inserted ) {
					throw new RuntimeException( 'No se pudo registrar una identidad de la reasignación.' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'No se pudo confirmar la reasignación.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'cvd_reassignment_failed', $error->getMessage() );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$order->add_order_note( sprintf( 'Atribución futura del cliente reasignada a %s. Motivo: %s', $owner->display_name, $reason ) );
		return $event_uuid;
	}

	public static function admin_fields( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::stored_identities( $order ) ) {
			return;
		}
		$users = get_users(
			array(
				'role__in' => array( 'cvd_gestora', 'cvd_influencer' ),
				'meta_key' => '_cvd_account_status',
				'meta_value' => 'approved',
				'orderby' => 'display_name',
				'order' => 'ASC',
			)
		);
		if ( ! $users ) {
			return;
		}
		wp_nonce_field( 'cvd_reassign_customer_' . $order->get_id(), 'cvd_reassign_customer_nonce' );
		echo '<div class="order_data_column"><h4>Propietaria futura del cliente</h4>';
		echo '<p class="description">No modifica esta venta ni sus comisiones. Solo cambia la atribución de pedidos futuros y deja historial auditable.</p>';
		echo '<p><select name="cvd_reassign_owner_id"><option value="">Sin cambios</option>';
		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name ) . '</option>';
		}
		echo '</select></p><p><textarea name="cvd_reassign_reason" rows="2" style="width:100%" placeholder="Motivo obligatorio de la reasignación"></textarea></p></div>';
	}

	public static function save_admin_reassignment( int $order_id ): void {
		$new_owner_id = absint( $_POST['cvd_reassign_owner_id'] ?? 0 );
		if ( ! $new_owner_id ) {
			return;
		}
		$nonce = isset( $_POST['cvd_reassign_customer_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cvd_reassign_customer_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cvd_reassign_customer_' . $order_id ) ) {
			return;
		}
		$reason = isset( $_POST['cvd_reassign_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['cvd_reassign_reason'] ) ) : '';
		self::reassign_from_order( $order_id, $new_owner_id, $reason );
	}

	private static function latest_override( array $identities ): ?array {
		if ( ! $identities ) {
			return null;
		}
		self::maybe_upgrade();
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_attribution_overrides';
		$latest = null;
		foreach ( $identities as $identity ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE identity_type=%s AND identity_hash=%s ORDER BY id DESC LIMIT 1",
					$identity['type'],
					$identity['hash']
				),
				ARRAY_A
			);
			if ( $row && ( ! $latest || (int) $row['id'] > (int) $latest['id'] ) ) {
				$latest = $row;
			}
		}
		return $latest;
	}

	private static function stored_identities( WC_Order $order ): array {
		$identities = array();
		foreach ( array( 'customer', 'phone', 'email' ) as $type ) {
			$hash = strtolower( trim( (string) $order->get_meta( '_cvd_identity_' . $type, true ) ) );
			if ( preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				$identities[] = array( 'type' => $type, 'hash' => $hash );
			}
		}
		return $identities;
	}

	private static function identities( string $phone, string $email, int $customer_id ): array {
		$identities = array();
		$phone = preg_replace( '/\D+/', '', $phone );
		$email = sanitize_email( strtolower( trim( $email ) ) );
		if ( $customer_id > 0 && ! self::is_program_operator( $customer_id ) ) {
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
		if ( ! $user ) {
			return false;
		}
		return (bool) array_intersect(
			array( 'cvd_gestora', 'cvd_influencer', 'cvd_messenger', 'cvd_clerk', 'cvd_operator', 'administrator', 'shop_manager' ),
			(array) $user->roles
		);
	}

	private static function approved_owner( int $user_id ): ?WP_User {
		$user = get_userdata( $user_id );
		if ( ! $user || 'approved' !== get_user_meta( $user_id, '_cvd_account_status', true ) ) {
			return null;
		}
		if ( ! array_intersect( array( 'cvd_gestora', 'cvd_influencer' ), (array) $user->roles ) ) {
			return null;
		}
		return $user;
	}

	private static function sanitize_code( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $code ) ) );
	}
}
