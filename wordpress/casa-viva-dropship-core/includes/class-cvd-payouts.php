<?php

defined( 'ABSPATH' ) || exit;

/** Solicitudes y liquidaciones de comisiones con historial contable anexado. */
final class CVD_Payouts {
	private const PROFILE_METHOD = '_cvd_payout_method';
	private const PROFILE_ACCOUNT = '_cvd_payout_account';
	private const PROFILE_QR = '_cvd_payout_qr_attachment_id';

	public static function register(): void {
		add_shortcode( 'casa_viva_accounting', array( __CLASS__, 'render_admin' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		if ( is_page( array( 'contabilidad', 'contabilidad-mensajeros', 'area-gestoras', 'area-mensajeros' ) ) ) {
			wp_enqueue_style( 'cvd-accounting', CVD_URL . 'assets/accounting.css', array(), CVD_VERSION );
		}
	}

	public static function render_gestora( WP_User $user ): string {
		$notice = self::handle_gestora_action( $user );
		$method = sanitize_key( (string) get_user_meta( $user->ID, self::PROFILE_METHOD, true ) );
		$account = self::decrypt( (string) get_user_meta( $user->ID, self::PROFILE_ACCOUNT, true ) );
		$qr_id = absint( get_user_meta( $user->ID, self::PROFILE_QR, true ) );
		$payouts = self::payouts_for_owner( $user->ID );
		$available = self::available_by_currency( $user->ID );
		ob_start();
		?>
		<section class="cvd-panel cvd-payout-panel" id="pagos">
			<div class="cvd-section-title"><p class="cvd-kicker">Liquidaciones</p><h2>Solicitar pago</h2><p>Solo incluye comisiones aprobadas por ventas entregadas. Casa Viva revisará y registrará la transferencia.</p></div>
			<?php if ( $notice ) : ?><div class="cvd-notice" role="status"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
			<div class="cvd-payout-balance">
				<?php if ( $available ) : foreach ( $available as $currency => $amount ) : ?><article><span>Disponible</span><strong><?php echo wp_kses_post( wc_price( $amount, array( 'currency' => $currency ) ) ); ?></strong></article><?php endforeach; else : ?><article><span>Disponible</span><strong>0</strong><small>No hay comisiones aprobadas sin liquidar.</small></article><?php endif; ?>
			</div>
			<?php $debits = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::open_debits_by_currency( $user->ID ) : array(); if ( $debits ) : ?><div class="cvd-notice" role="status"><strong>Descuentos pendientes</strong><?php foreach ( $debits as $currency => $amount ) : ?><br><?php echo esc_html( wc_format_decimal( $amount, 2 ) . ' ' . $currency ); ?><?php endforeach; ?><br><small>Se compensan solo con comisiones de la misma moneda; no se aplica conversión.</small></div><?php endif; ?>
			<form class="cvd-payout-profile" method="post" enctype="multipart/form-data">
				<h3>Destino del pago</h3>
				<label>Método<select name="cvd_payout_method" required><option value="">Selecciona</option><?php foreach ( array( 'transfermovil' => 'Transfermóvil', 'transferencia' => 'Transferencia bancaria', 'zelle' => 'Zelle', 'tropipay' => 'TropiPay', 'cash' => 'Efectivo', 'otro' => 'Otro' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $method, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label>Tarjeta, teléfono o cuenta<input name="cvd_payout_account" autocomplete="off" required value="<?php echo esc_attr( $account ); ?>"><small>Dato privado: solo tú y administración pueden verlo.</small></label>
				<label>QR de Transfermóvil (opcional)<input accept="image/jpeg,image/png,image/webp" name="cvd_payout_qr" type="file"><?php if ( $qr_id ) : ?><span class="cvd-qr-saved">QR guardado ✓</span><?php endif; ?></label>
				<?php wp_nonce_field( 'cvd_save_payout_profile', 'cvd_payout_nonce' ); ?><button class="cvd-secondary" name="cvd_payout_action" value="save_profile">Guardar destino</button>
			</form>
			<form class="cvd-payout-request" method="post"><?php wp_nonce_field( 'cvd_request_payout', 'cvd_payout_nonce' ); ?><button class="cvd-primary" name="cvd_payout_action" value="request" <?php disabled( ! $available || ! $method || ! $account ); ?>>Solicitar pago disponible</button><?php if ( ! $method || ! $account ) : ?><small>Guarda primero el destino del pago.</small><?php endif; ?></form>
			<?php echo self::render_history( $payouts, false ); ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function handle_gestora_action( WP_User $user ): string {
		$action = sanitize_key( (string) ( $_POST['cvd_payout_action'] ?? '' ) );
		if ( ! $action ) { return ''; }
		$nonce = sanitize_text_field( wp_unslash( $_POST['cvd_payout_nonce'] ?? '' ) );
		if ( 'save_profile' === $action && wp_verify_nonce( $nonce, 'cvd_save_payout_profile' ) ) {
			$method = sanitize_key( wp_unslash( $_POST['cvd_payout_method'] ?? '' ) );
			$account = sanitize_text_field( wp_unslash( $_POST['cvd_payout_account'] ?? '' ) );
			if ( ! in_array( $method, array( 'transfermovil', 'transferencia', 'zelle', 'tropipay', 'cash', 'otro' ), true ) || ! $account ) { return 'Revisa el método y la cuenta.'; }
			update_user_meta( $user->ID, self::PROFILE_METHOD, $method );
			update_user_meta( $user->ID, self::PROFILE_ACCOUNT, self::encrypt( $account ) );
			if ( ! empty( $_FILES['cvd_payout_qr']['name'] ) ) {
				$attachment = self::upload_image( 'cvd_payout_qr' );
				if ( is_wp_error( $attachment ) ) { return $attachment->get_error_message(); }
				update_user_meta( $user->ID, self::PROFILE_QR, $attachment );
			}
			return 'Destino de pago guardado.';
		}
		if ( 'request' === $action && wp_verify_nonce( $nonce, 'cvd_request_payout' ) ) {
			$result = self::request( $user->ID );
			return is_wp_error( $result ) ? $result->get_error_message() : 'Solicitud de pago creada correctamente.';
		}
		return 'La sesión venció. Actualiza la página e intenta otra vez.';
	}

	public static function render_admin(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return '<section class="cvd-sales-denied"><h1>Contabilidad</h1><p>Acceso exclusivo de administración.</p></section>'; }
		$notice = self::handle_admin_action();
		$payouts = self::all_payouts();
		$totals = array( 'requested' => array(), 'approved' => array(), 'paid' => array() );
		foreach ( $payouts as $payout ) { if ( isset( $totals[ $payout['status'] ] ) ) { $currency = $payout['currency']; $totals[ $payout['status'] ][ $currency ] = ( $totals[ $payout['status'] ][ $currency ] ?? 0 ) + (float) $payout['amount']; } }
		$open_debits = self::open_debits_admin();
		ob_start(); ?>
		<section class="cvd-accounting-app"><nav><a href="<?php echo esc_url( home_url( '/centro-operaciones/' ) ); ?>">Operaciones</a><a href="<?php echo esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ); ?>">Cerrar sesión</a></nav><header><p>Casa Viva · Administración</p><h1>Contabilidad</h1><span>Solicitudes, liquidaciones y trazabilidad de comisiones.</span></header>
		<?php if ( $notice ) : ?><div class="cvd-accounting-notice"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
		<div class="cvd-accounting-summary"><article><span>Solicitado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['requested'] ) ); ?></strong></article><article><span>Aprobado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['approved'] ) ); ?></strong></article><article><span>Pagado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['paid'] ) ); ?></strong></article></div>
		<?php if ( $open_debits ) : ?><section class="cvd-panel"><h2>Descuentos de comisión pendientes</h2><p>Obligaciones internas por moneda. No se convierten ni se restan de otra moneda.</p><?php foreach ( $open_debits as $debit ) : $owner = get_userdata( absint( $debit['owner_user_id'] ) ); ?><article><strong><?php echo esc_html( wc_format_decimal( $debit['amount'], 2 ) . ' ' . $debit['currency'] ); ?></strong><span> · <?php echo esc_html( $owner ? $owner->display_name : 'Gestora' ); ?> · Pedido #<?php echo esc_html( $debit['order_id'] ); ?></span><small> · <?php echo esc_html( $debit['status'] ); ?></small></article><?php endforeach; ?></section><?php endif; ?>
		<?php echo self::render_history( $payouts, true ); ?></section>
		<?php return (string) ob_get_clean();
	}

	private static function handle_admin_action(): string {
		$action = sanitize_key( (string) ( $_POST['cvd_admin_payout_action'] ?? '' ) );
		$payout_id = absint( $_POST['cvd_payout_id'] ?? 0 );
		if ( ! $action || ! $payout_id ) { return ''; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_payout_admin_nonce'] ?? '' ) ), 'cvd_admin_payout_' . $payout_id ) ) { return 'La sesión venció.'; }
		$reference = sanitize_text_field( wp_unslash( $_POST['cvd_payment_reference'] ?? '' ) );
		$proof = 0;
		if ( 'pay' === $action && ! empty( $_FILES['cvd_payment_proof']['name'] ) ) {
			$uploaded = self::upload_image( 'cvd_payment_proof' );
			if ( is_wp_error( $uploaded ) ) { return $uploaded->get_error_message(); }
			$proof = $uploaded;
		}
		$result = self::transition( $payout_id, $action, $reference, $proof );
		return is_wp_error( $result ) ? $result->get_error_message() : 'Liquidación actualizada.';
	}

	/**
	 * Creates one payout per currency from approved, unclaimed commissions.
	 * The named lock serializes requests for the same owner and every write is checked
	 * before COMMIT so a partial payout cannot become visible.
	 */
	public static function request( int $owner_id ) {
		global $wpdb;
		$method = sanitize_key( (string) get_user_meta( $owner_id, self::PROFILE_METHOD, true ) );
		$encrypted_account = (string) get_user_meta( $owner_id, self::PROFILE_ACCOUNT, true );
		if ( ! $method || ! $encrypted_account ) { return new WP_Error( 'cvd_payout_profile', 'Guarda primero el destino del pago.' ); }
		$lock = 'cvd-payout-' . $owner_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return new WP_Error( 'cvd_payout_busy', 'Ya se está procesando otra solicitud.' ); }
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'No se pudo iniciar la liquidación.' ); }
			$available = self::eligible_orders( $owner_id );
			if ( ! $available ) { throw new RuntimeException( 'No hay comisiones aprobadas disponibles.' ); }
			$created = 0;
			foreach ( $available as $currency => $orders ) {
				$gross = array_sum( array_map( static fn( WC_Order $order ): float => (float) $order->get_meta( '_cvd_commission_amount', true ), $orders ) );
				$debit_table = $wpdb->prefix . 'cvd_owner_financial_ledger';
				$debits = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$debit_table} WHERE owner_user_id=%d AND currency=%s AND entry_type='commission_deduction' AND status='open' FOR UPDATE", $owner_id, $currency ) );
				$total = $gross - $debits;
				if ( $total <= 0 ) { continue; }
				$inserted = $wpdb->insert(
					$wpdb->prefix . 'cvd_payouts',
					array(
						'payout_uuid' => wp_generate_uuid4(), 'owner_user_id' => $owner_id, 'amount' => $total,
						'currency' => $currency, 'status' => 'requested', 'method' => $method,
						'account_value' => $encrypted_account,
						'qr_attachment_id' => absint( get_user_meta( $owner_id, self::PROFILE_QR, true ) ),
						'requested_at' => current_time( 'mysql', true ), 'created_by' => $owner_id,
						'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
					)
				);
				$payout_id = (int) $wpdb->insert_id;
				if ( 1 !== $inserted || ! $payout_id ) { throw new RuntimeException( 'No se pudo crear la liquidación.' ); }
				if ( $debits > 0 ) { $reserved = $wpdb->query( $wpdb->prepare( "UPDATE {$debit_table} SET status='reserved',payout_id=%d WHERE owner_user_id=%d AND currency=%s AND entry_type='commission_deduction' AND status='open'", $payout_id, $owner_id, $currency ) ); if ( false === $reserved ) { throw new RuntimeException( 'No se pudieron reservar los descuentos de comisión.' ); } }
				foreach ( $orders as $order ) {
					$item_inserted = $wpdb->insert(
						$wpdb->prefix . 'cvd_payout_items',
						array(
							'payout_id' => $payout_id, 'order_id' => $order->get_id(),
							'amount' => $order->get_meta( '_cvd_commission_amount', true ),
							'base_commission' => $order->get_meta( '_cvd_base_commission_amount', true ),
							'markup' => $order->get_meta( '_cvd_margin_amount', true ),
							'currency' => $currency, 'created_at' => current_time( 'mysql', true ),
						)
					);
					if ( 1 !== $item_inserted ) { throw new RuntimeException( 'No se pudo anexar una comisión a la liquidación.' ); }
					$order->update_meta_data( '_cvd_payout_id', $payout_id );
					$order->update_meta_data( '_cvd_payout_status', 'requested' );
					$order->save();
					$fresh = wc_get_order( $order->get_id() );
					if ( ! $fresh || $payout_id !== absint( $fresh->get_meta( '_cvd_payout_id', true ) ) || 'requested' !== $fresh->get_meta( '_cvd_payout_status', true ) ) {
						throw new RuntimeException( 'No se pudo vincular una comisión a la liquidación.' );
					}
				}
				if ( ! self::event( $payout_id, 'requested', '', 'requested' ) ) { throw new RuntimeException( 'No se pudo registrar el historial de la liquidación.' ); }
				$created++;
			}
			if ( ! $created ) { throw new RuntimeException( 'Las comisiones disponibles no superan los descuentos pendientes de la misma moneda.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'No se pudo confirmar la liquidación.' ); }
			wp_mail( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ), 'Nueva solicitud de pago Casa Viva', 'Una gestora solicitó una liquidación. Revisar: ' . home_url( '/contabilidad/' ) );
			return $created;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'cvd_payout_failed', $error->getMessage() );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function eligible_orders( int $owner_id ): array {
		$orders = wc_get_orders( array( 'limit' => -1, 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $owner_id ) );
		$eligible = array();
		foreach ( $orders as $order ) {
			if ( 'approved' !== $order->get_meta( '_cvd_commission_status', true ) || absint( $order->get_meta( '_cvd_payout_id', true ) ) ) { continue; }
			$eligible[ $order->get_currency() ][] = $order;
		}
		return $eligible;
	}

	private static function available_by_currency( int $owner_id ): array {
		$result = array(); $debits = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::open_debits_by_currency( $owner_id ) : array(); foreach ( self::eligible_orders( $owner_id ) as $currency => $orders ) { $net = array_sum( array_map( static fn( WC_Order $o ): float => (float) $o->get_meta( '_cvd_commission_amount', true ), $orders ) ) - ( $debits[ $currency ] ?? 0 ); if ( $net > 0 ) { $result[ $currency ] = $net; } } return $result;
	}

	/**
	 * Atomic payout state change. A row lock plus a named lock makes a competing
	 * approve/pay/reject observe the committed winner instead of overwriting it.
	 */
	public static function transition( int $payout_id, string $action, string $reference = '', int $proof = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_payouts';
		$items_table = $wpdb->prefix . 'cvd_payout_items';
		$lock = 'cvd-payout-transition-' . $payout_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) {
			return new WP_Error( 'cvd_payout_busy', 'Esta liquidación ya se está procesando.' );
		}

		$order_ids = array();
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'No se pudo iniciar la actualización.' ); }
			$payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $payout_id ), ARRAY_A );
			if ( ! $payout ) { throw new RuntimeException( 'Liquidación no encontrada.' ); }

			$map = array(
				'approve' => array( array( 'requested' ), 'approved' ),
				'pay' => array( array( 'approved' ), 'paid' ),
				'reject' => array( array( 'requested', 'approved' ), 'rejected' ),
			);
			if ( ! isset( $map[ $action ] ) ) { throw new InvalidArgumentException( 'Acción no válida.' ); }
			$allowed_from = $map[ $action ][0];
			$next = $map[ $action ][1];
			if ( ! in_array( $payout['status'], $allowed_from, true ) ) { throw new DomainException( 'Esta liquidación ya cambió de estado.' ); }
			if ( 'paid' === $next && ! $reference ) { throw new InvalidArgumentException( 'Escribe la referencia de la transferencia.' ); }

			$order_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$items_table} WHERE payout_id=%d ORDER BY id ASC", $payout_id ) ) );
			if ( ! $order_ids ) { throw new RuntimeException( 'La liquidación no contiene comisiones verificables.' ); }

			$data = array( 'status' => $next, 'updated_at' => current_time( 'mysql', true ) );
			if ( 'approved' === $next ) { $data['approved_at'] = current_time( 'mysql', true ); $data['approved_by'] = get_current_user_id(); }
			if ( 'paid' === $next ) { $data['paid_at'] = current_time( 'mysql', true ); $data['paid_by'] = get_current_user_id(); $data['reference'] = $reference; $data['proof_attachment_id'] = $proof; }
			$updated = $wpdb->update( $table, $data, array( 'id' => $payout_id, 'status' => $payout['status'] ) );
			if ( 1 !== $updated ) { throw new RuntimeException( 'La liquidación cambió mientras se procesaba.' ); }

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order || $payout_id !== absint( $order->get_meta( '_cvd_payout_id', true ) ) || (int) $payout['owner_user_id'] !== absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ) {
					throw new RuntimeException( 'Una comisión ya no coincide con esta liquidación.' );
				}

				if ( 'rejected' === $next ) {
					$order->delete_meta_data( '_cvd_payout_id' );
					$order->delete_meta_data( '_cvd_payout_status' );
					$order->save();
					$fresh = wc_get_order( $order_id );
					if ( ! $fresh || absint( $fresh->get_meta( '_cvd_payout_id', true ) ) ) { throw new RuntimeException( 'No se pudo liberar una comisión rechazada.' ); }
					continue;
				}

				$order->update_meta_data( '_cvd_payout_status', $next );
				$order->save();
				if ( 'paid' === $next ) {
					CVD_Commissions::mark_paid( $order_id );
				}
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh || $next !== $fresh->get_meta( '_cvd_payout_status', true ) ) { throw new RuntimeException( 'No se pudo sincronizar una comisión con la liquidación.' ); }
				if ( 'paid' === $next && 'paid' !== $fresh->get_meta( '_cvd_commission_status', true ) ) { throw new RuntimeException( 'La comisión no quedó marcada como pagada.' ); }
			}

			if ( ! self::event( $payout_id, $action, $payout['status'], $next, array( 'reference' => $reference, 'proof' => $proof ) ) ) {
				throw new RuntimeException( 'No se pudo registrar el historial de la liquidación.' );
			}
			$ledger_table = $wpdb->prefix . 'cvd_owner_financial_ledger';
			if ( 'rejected' === $next ) { if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$ledger_table} SET status='open',payout_id=0 WHERE payout_id=%d AND status='reserved'", $payout_id ) ) ) { throw new RuntimeException( 'No se pudieron liberar los descuentos reservados.' ); } }
			if ( 'paid' === $next ) { if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$ledger_table} SET status='settled' WHERE payout_id=%d AND status='reserved'", $payout_id ) ) ) { throw new RuntimeException( 'No se pudieron conciliar los descuentos reservados.' ); } }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'No se pudo confirmar la actualización.' ); }
		} catch ( InvalidArgumentException $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'cvd_payout_action', $error->getMessage() );
		} catch ( DomainException $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'cvd_payout_transition', $error->getMessage() );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			foreach ( $order_ids as $order_id ) { clean_post_cache( $order_id ); }
			return new WP_Error( 'cvd_payout_failed', $error->getMessage() );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$owner = get_userdata( (int) $payout['owner_user_id'] );
		if ( $owner ) { wp_mail( $owner->user_email, 'Actualización de tu pago Casa Viva', 'Tu liquidación #' . $payout_id . ' cambió a: ' . $next . ( $reference ? "\nReferencia: " . $reference : '' ) . "\n\nConsulta tu historial: " . home_url( '/area-gestoras/#pagos' ) ); }
		return true;
	}

	private static function event( int $payout_id, string $type, string $from, string $to, array $metadata = array() ): bool {
		global $wpdb;
		return 1 === $wpdb->insert(
			$wpdb->prefix . 'cvd_payout_events',
			array(
				'payout_id' => $payout_id, 'event_type' => $type, 'from_status' => $from,
				'to_status' => $to, 'actor_user_id' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ), 'metadata' => wp_json_encode( $metadata ),
			)
		);
	}

	private static function payouts_for_owner( int $owner_id ): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payouts WHERE owner_user_id=%d ORDER BY id DESC LIMIT 50", $owner_id ), ARRAY_A ); }
	private static function all_payouts(): array { global $wpdb; return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cvd_payouts ORDER BY id DESC LIMIT 200", ARRAY_A ); }
	private static function open_debits_admin(): array { global $wpdb; return $wpdb->get_results( "SELECT owner_user_id,order_id,amount,currency,status FROM {$wpdb->prefix}cvd_owner_financial_ledger WHERE entry_type='commission_deduction' AND status IN ('open','reserved') ORDER BY id DESC LIMIT 200", ARRAY_A ); }
	private static function format_totals( array $totals ): string { if ( ! $totals ) { return '0'; } $parts = array(); foreach ( $totals as $currency => $amount ) { $parts[] = wc_price( $amount, array( 'currency' => $currency ) ); } return implode( '<br>', $parts ); }

	private static function render_history( array $payouts, bool $admin ): string {
		if ( ! $payouts ) { return '<div class="cvd-payout-history"><h3>Historial</h3><p>Todavía no hay liquidaciones.</p></div>'; }
		$labels = array( 'requested' => 'Solicitada', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'rejected' => 'Rechazada' ); $html = '<div class="cvd-payout-history"><h3>Historial de liquidaciones</h3>';
		foreach ( $payouts as $payout ) { $owner = get_userdata( (int) $payout['owner_user_id'] ); $html .= '<article><div><span class="cvd-badge">' . esc_html( $labels[ $payout['status']] ?? $payout['status'] ) . '</span><strong>Liquidación #' . esc_html( $payout['id'] ) . '</strong><small>' . esc_html( $owner ? $owner->display_name : 'Gestora' ) . ' · ' . esc_html( get_date_from_gmt( $payout['created_at'], 'd/m/Y H:i' ) ) . '</small></div><div><b>' . wp_kses_post( wc_price( $payout['amount'], array( 'currency' => $payout['currency'] ) ) ) . '</b>';
			if ( $admin ) { $account = self::decrypt( $payout['account_value'] ); $html .= '<small>' . esc_html( ucfirst( $payout['method'] ) . ' · ' . $account ) . '</small>'; if ( in_array( $payout['status'], array( 'requested', 'approved' ), true ) ) { $html .= '<form method="post" enctype="multipart/form-data"><input type="hidden" name="cvd_payout_id" value="' . esc_attr( $payout['id'] ) . '">'; ob_start(); wp_nonce_field( 'cvd_admin_payout_' . $payout['id'], 'cvd_payout_admin_nonce' ); $html .= ob_get_clean(); if ( 'requested' === $payout['status'] ) { $html .= '<button name="cvd_admin_payout_action" value="approve">Aprobar</button>'; } else { $html .= '<input name="cvd_payment_reference" placeholder="Referencia bancaria" required><input accept="image/*" name="cvd_payment_proof" type="file"><button name="cvd_admin_payout_action" value="pay">Marcar pagada</button>'; } $html .= '<button class="is-danger" name="cvd_admin_payout_action" value="reject">Rechazar</button></form>'; } }
			elseif ( $payout['reference'] ) { $html .= '<small>Referencia: ' . esc_html( $payout['reference'] ) . '</small>'; } $html .= '</div></article>'; }
		return $html . '</div>';
	}

	private static function upload_image( string $field ) {
		if ( (int) ( $_FILES[ $field ]['size'] ?? 0 ) > 5 * MB_IN_BYTES ) { return new WP_Error( 'cvd_file_large', 'La imagen no puede superar 5 MB.' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
		return media_handle_upload( $field, 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
	}

	private static function encrypt( string $value ): string { $key = hash( 'sha256', wp_salt( 'auth' ), true ); $iv = random_bytes( 12 ); $tag = ''; $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag ); return base64_encode( $iv . $tag . $cipher ); }
	private static function decrypt( string $value ): string { $raw = base64_decode( $value, true ); if ( ! $raw || strlen( $raw ) < 29 ) { return ''; } $key = hash( 'sha256', wp_salt( 'auth' ), true ); return (string) openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) ); }
}
