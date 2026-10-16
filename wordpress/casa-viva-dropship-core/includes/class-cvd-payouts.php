<?php

defined( 'ABSPATH' ) || exit;

/**
 * Solicitudes y liquidaciones de comisiones con historial contable anexado.
 *
 * 3.14.23 «Pagos de comisiones sin chat» (Ernesto y Lennys, 15-oct):
 *  - La gestora pide su comisión cuando quiera, en una pantalla: cuánto retira (por defecto todo; pedidos enteros, del
 *    más viejo al más nuevo), cómo cobra (transferencia CUP con tarjeta y teléfono guardados, efectivo CUP o efectivo
 *    USD) y el detalle por pedido con la tasa del DÍA DE CADA VENTA. Las deudas abiertas con la tienda se descuentan.
 *  - Al pedirla, VivaBot avisa por WhatsApp a Lennys (y en efectivo, a la dependienta) con un enlace a la página
 *    «Pagar a…» (CVD_Payout_Pay_Page). Al pagar, la gestora recibe su aviso (CVD_Payout_Avisos).
 *  - En efectivo, la gestora toca «Recibido» al cobrar (queda como comprobante).
 *  - Nada se borra: los estados cambian con bloqueo y transacción, y cada paso queda en cvd_payout_events.
 * Lo de antes sigue igual: request( $id ) sin opciones (pruebas 4C), transition() y el panel de Lennys.
 */
final class CVD_Payouts {
	private const PROFILE_METHOD = '_cvd_payout_method';
	private const PROFILE_ACCOUNT = '_cvd_payout_account';
	private const PROFILE_QR = '_cvd_payout_qr_attachment_id';
	/** 3.14.23: teléfono para la transferencia (cifrado) y la última forma de cobro elegida. */
	private const PROFILE_PHONE = '_cvd_payout_phone';
	private const PROFILE_PAY = '_cvd_payout_pay_method';
	public const METHOD_LABELS = array( 'transfer' => 'Transferencia en CUP', 'cash_cup' => 'Efectivo en CUP', 'cash_usd' => 'Efectivo en USD', 'other' => 'Otro' );

	public static function register(): void {
		add_shortcode( 'casa_viva_accounting', array( __CLASS__, 'render_admin' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		if ( is_page( array( 'contabilidad', 'contabilidad-mensajeros', 'area-gestoras', 'area-mensajeros' ) ) ) {
			wp_enqueue_style( 'cvd-accounting', CVD_URL . 'assets/accounting.css', array(), CVD_VERSION );
		}
		if ( is_page( array( 'contabilidad', 'area-gestoras' ) ) ) {
			wp_enqueue_style( 'cvd-payouts', CVD_URL . 'assets/payouts.css', array(), CVD_VERSION );
			wp_enqueue_script( 'cvd-payouts', CVD_URL . 'assets/payouts.js', array(), CVD_VERSION, true );
		}
	}

	// ------------------------------------------------------------------ Datos

	public static function get( int $payout_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_payouts WHERE id=%d", $payout_id ), ARRAY_A );
		return $row ?: null;
	}

	/** Tasa de hoy (la que publica la tienda cada mañana). 0 si no hay. */
	public static function today_rate(): float {
		return class_exists( 'CVD_Daily_Rates' ) ? (float) ( CVD_Daily_Rates::get()['cup'] ?? 0 ) : 0.0;
	}

	/**
	 * Tasa CUP del día de la venta: la que guardó el pedido (3.13.64); si es un pedido viejo sin tasa, la que había ese
	 * día según el registro de la tasa; si tampoco, 0 (no se adivina).
	 * @return array{rate: float, day: string, source: string}
	 */
	public static function rate_info( WC_Order $order ): array {
		$saved = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate( $order ) : null;
		if ( null !== $saved ) { return array( 'rate' => $saved, 'day' => CVD_Daily_Rates::order_rate_day( $order ), 'source' => 'venta' ); }
		$created = $order->get_date_created();
		$ts = $created ? $created->getTimestamp() : time();
		$log = get_option( 'cvd_daily_rates_log', array() );
		$rate = CVD_Payout_Math::rate_from_log( is_array( $log ) ? $log : array(), $ts );
		return array( 'rate' => (float) $rate, 'day' => self::day( $ts ), 'source' => null === $rate ? 'falta' : 'registro' );
	}

	/** «12-oct» en hora de Cuba. */
	public static function day( int $ts ): string {
		$names = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
		return wp_date( 'j', $ts ) . '-' . $names[ (int) wp_date( 'n', $ts ) - 1 ];
	}

	/** Un pedido como línea del pago: productos con su comisión, comisión USD, tasa de esa venta y CUP. */
	public static function order_line( WC_Order $order, ?float $usd = null ): array {
		$usd = null === $usd ? (float) $order->get_meta( '_cvd_commission_amount', true ) : $usd;
		$rate = self::rate_info( $order );
		$line = CVD_Payout_Math::line( $usd, $rate['rate'] );
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$snap = $item->get_meta( '_cvd_commission_snapshot', true );
			$items[] = array(
				'name' => wp_strip_all_tags( $item->get_name() ),
				'qty'  => max( 1, (int) $item->get_quantity() ),
				'usd'  => is_array( $snap ) ? round( (float) ( $snap['base_commission'] ?? 0 ) + (float) ( $snap['markup'] ?? 0 ), 2 ) : null,
			);
		}
		$created = $order->get_date_created();
		return array(
			'id' => $order->get_id(), 'number' => (string) $order->get_order_number(),
			'ts' => $created ? $created->getTimestamp() : 0, 'date' => $created ? self::day( $created->getTimestamp() ) : '',
			'items' => $items, 'usd' => $line['usd'], 'rate' => $line['rate'], 'cup' => $line['cup'],
			'rate_day' => $rate['day'], 'rate_src' => $rate['source'],
		);
	}

	/** Pedidos con comisión aprobada y sin pedir, del más viejo al más nuevo (solo USD, sin pruebas ni anulados). */
	public static function available_lines( int $owner_id ): array {
		$lines = array();
		foreach ( self::eligible_orders( $owner_id ) as $currency => $orders ) {
			if ( 'USD' !== $currency ) { continue; }
			foreach ( $orders as $order ) {
				if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { continue; }
				if ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) { continue; }
				$lines[] = self::order_line( $order );
			}
		}
		usort( $lines, static fn( array $a, array $b ): int => array( $a['ts'], $a['id'] ) <=> array( $b['ts'], $b['id'] ) );
		return $lines;
	}

	/** Deudas abiertas de la gestora con la tienda (libro cvd_owner_financial_ledger, «commission_deduction»). */
	public static function open_debts( int $owner_id, bool $for_update = false ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,order_id,obligation_id,amount,currency,metadata,created_at FROM {$wpdb->prefix}cvd_owner_financial_ledger WHERE owner_user_id=%d AND entry_type='commission_deduction' AND status='open' ORDER BY id ASC" . ( $for_update ? ' FOR UPDATE' : '' ), $owner_id ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) $row['metadata'], true );
			$note = is_array( $meta ) && ! empty( $meta['note'] ) ? (string) $meta['note'] : ( absint( $row['order_id'] ) ? 'Pedido #' . absint( $row['order_id'] ) : 'Deuda con la tienda' );
			$out[] = array( 'id' => (int) $row['id'], 'amount' => round( (float) $row['amount'], 2 ), 'currency' => strtoupper( (string) $row['currency'] ), 'note' => $note );
		}
		return $out;
	}

	/** Tarjeta y teléfono guardados para la transferencia (descifrados). */
	public static function profile( WP_User $user ): array {
		$account = self::decrypt( (string) get_user_meta( $user->ID, self::PROFILE_ACCOUNT, true ) );
		$phone = self::decrypt( (string) get_user_meta( $user->ID, self::PROFILE_PHONE, true ) );
		if ( '' === $phone ) { $phone = CVD_Payout_Math::phone8( class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $user->ID ) : '' ); }
		$pay = sanitize_key( (string) get_user_meta( $user->ID, self::PROFILE_PAY, true ) );
		$card = CVD_Payout_Math::valid_card( $account ) ? CVD_Payout_Math::digits( $account ) : '';
		if ( ! in_array( $pay, CVD_Payout_Math::METHODS, true ) ) { $pay = $card ? 'transfer' : 'cash_cup'; }
		return array( 'card' => $card, 'phone' => $phone, 'pay' => $pay );
	}

	/**
	 * Todo lo que hace falta para enseñar un pago (página «Pagar a…», historial, avisos), también los de antes de
	 * 3.14.23 (sin detalle guardado: se arma con sus pedidos y la tasa de cada venta).
	 */
	public static function view( array $payout ): array {
		global $wpdb;
		$details = json_decode( (string) ( $payout['details'] ?? '' ), true );
		$owner = get_userdata( (int) $payout['owner_user_id'] );
		$kind = sanitize_key( (string) ( $payout['pay_method'] ?? '' ) );
		$legacy = '' === $kind;
		if ( $legacy ) {
			$kind = in_array( $payout['method'], array( 'transfermovil', 'transferencia' ), true ) ? 'transfer' : ( 'cash' === $payout['method'] ? 'cash_usd' : 'other' );
		}
		if ( is_array( $details ) && isset( $details['lines'] ) ) {
			$lines = (array) $details['lines'];
			$debts = (array) ( $details['debts'] ?? array() );
		} else {
			$lines = array();
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,amount FROM {$wpdb->prefix}cvd_payout_items WHERE payout_id=%d ORDER BY id ASC", (int) $payout['id'] ), ARRAY_A );
			foreach ( (array) $items as $item ) {
				$order = wc_get_order( absint( $item['order_id'] ) );
				if ( $order instanceof WC_Order ) { $lines[] = self::order_line( $order, (float) $item['amount'] ); }
			}
			$debts = array();
		}
		$usd = round( array_sum( array_map( static fn( $l ): float => (float) ( $l['usd'] ?? 0 ), $lines ) ), 2 );
		$cup = round( array_sum( array_map( static fn( $l ): float => (float) ( $l['cup'] ?? 0 ), $lines ) ), 2 );
		$missing = (bool) array_filter( $lines, static fn( $l ): bool => (float) ( $l['rate'] ?? 0 ) <= 0 );
		$pay = (float) $payout['amount'];
		$currency = strtoupper( (string) $payout['currency'] );
		// Pagos de antes en USD por transferencia: Lennys transfiere CUP, con la tasa de cada venta.
		if ( $legacy && 'transfer' === $kind && 'USD' === $currency && ! $missing && $cup > 0 && abs( $usd - $pay ) < 0.01 ) { $pay = $cup; $currency = 'CUP'; }
		$account = self::decrypt( (string) ( $payout['account_value'] ?? '' ) );
		$phone = self::decrypt( (string) ( $payout['phone_value'] ?? '' ) );
		if ( '' === $phone && $owner ) { $phone = CVD_Payout_Math::phone8( class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $owner->ID ) : '' ); }
		$whatsapp = $owner && class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $owner->ID ) : '';
		return array(
			'id' => (int) $payout['id'], 'status' => (string) $payout['status'], 'kind' => $kind, 'legacy' => $legacy,
			'name' => $owner ? $owner->display_name : 'Gestora', 'first' => $owner ? ( strtok( (string) $owner->display_name, ' ' ) ?: $owner->display_name ) : 'Gestora',
			'owner_id' => (int) $payout['owner_user_id'], 'whatsapp' => $whatsapp,
			'pay' => $pay, 'currency' => $currency, 'pay_text' => CVD_Payout_Math::money( $pay, $currency ),
			'usd' => $usd ?: (float) ( $payout['commission_usd'] ?? 0 ), 'cup' => $cup, 'missing_rate' => $missing,
			'deduction' => (float) ( $payout['deduction'] ?? 0 ), 'deduction_currency' => 'cash_usd' === $kind ? 'USD' : 'CUP',
			'lines' => $lines, 'debts' => $debts,
			'card' => CVD_Payout_Math::valid_card( $account ) ? CVD_Payout_Math::digits( $account ) : '', 'account' => $account,
			'phone' => $phone, 'reference' => (string) $payout['reference'], 'proof' => absint( $payout['proof_attachment_id'] ),
			'requested_at' => (string) $payout['requested_at'], 'paid_at' => (string) $payout['paid_at'],
			'received_at' => (string) ( $payout['received_at'] ?? '' ), 'paid_by' => absint( $payout['paid_by'] ),
			'cash' => in_array( $kind, array( 'cash_cup', 'cash_usd' ), true ),
		);
	}

	/** Lo que ve la gestora: Pedido · Pagado · Por recoger · Recibido · No aprobado. */
	public static function gestora_state( array $view ): array {
		if ( 'rejected' === $view['status'] ) { return array( 'rejected', 'No aprobado' ); }
		if ( 'paid' !== $view['status'] ) { return array( 'asked', 'Pedido' ); }
		if ( $view['cash'] ) { return '' !== $view['received_at'] ? array( 'received', 'Recibido' ) : array( 'pickup', 'Por recoger' ); }
		return array( 'paid', 'Pagado' );
	}

	// ------------------------------------------------------------------ Pantalla de la gestora

	public static function render_gestora( WP_User $user ): string {
		$notice = self::handle_gestora_action( $user );
		$lines = self::available_lines( $user->ID );
		$debts = self::open_debts( $user->ID );
		$today = self::today_rate();
		$profile = self::profile( $user );
		$payouts = self::payouts_for_owner( $user->ID );
		$usd = round( array_sum( array_column( $lines, 'usd' ) ), 2 );
		$cup = round( array_sum( array_column( $lines, 'cup' ) ), 2 );
		$missing = (bool) array_filter( $lines, static fn( array $l ): bool => $l['rate'] <= 0 );
		$debt_cup = 0.0; $debt_usd = 0.0;
		foreach ( $debts as $d ) { if ( 'USD' === $d['currency'] ) { $debt_usd += $d['amount']; } else { $debt_cup += $d['amount']; } }
		$quote = $lines ? CVD_Payout_Math::quote( $lines, $debts, $profile['pay'], $today ) : null;
		$data = array(
			'lines' => array_map( static fn( array $l ): array => array( 'id' => $l['id'], 'usd' => $l['usd'], 'cup' => $l['cup'], 'rate' => $l['rate'] ), $lines ),
			'debtCup' => $debt_cup, 'debtUsd' => $debt_usd, 'today' => $today,
		);
		ob_start();
		?>
		<section class="cvd-panel cvd-payout-panel cvp" id="pagos">
			<div class="cvp-head"><h2>Cobrar mi comisión</h2><p>Pídela cuando quieras. Te avisamos por WhatsApp cuando te paguen.</p></div>
			<?php if ( $notice ) : ?><div class="cvp-notice<?php echo str_starts_with( $notice, '✅' ) ? ' is-ok' : ''; ?>" role="status"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
			<div class="cvp-card cvp-balance">
				<small>Disponible para cobrar</small>
				<strong><?php echo esc_html( CVD_Payout_Math::usd( $usd ) ); ?></strong>
				<?php if ( $usd > 0 ) : ?><span>≈ <?php echo esc_html( CVD_Payout_Math::cup( $cup ) ); ?><?php echo $missing ? ' (un pedido sin tasa)' : ''; ?> · con la tasa del día de cada venta</span><?php else : ?><span>Aún no tienes comisiones listas. Salen aquí cuando el pedido se entrega.</span><?php endif; ?>
				<?php if ( $debts ) : ?><p class="cvp-debt">Debes a la tienda <b><?php echo esc_html( self::debts_text( $debt_cup, $debt_usd ) ); ?></b>. Se descuenta al cobrar; el resto sigue sumando.</p><?php endif; ?>
			</div>
			<?php if ( $lines ) : ?>
			<form class="cvp-card cvp-form" method="post" data-cvp-form data-cvp="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>">
				<label class="cvp-field"><span>¿Cuánto retiras?</span>
					<span class="cvp-amount"><input name="cvd_amount" type="number" inputmode="decimal" min="0" step="0.01" value="<?php echo esc_attr( (string) $usd ); ?>" data-cvp-amount aria-describedby="cvp-amount-help"><b>USD</b><button class="cvp-link" type="button" data-cvp-all>Todo</button></span>
					<small id="cvp-amount-help" data-cvp-split>Retiras todo. Van pedidos enteros; lo que no retires queda guardado.</small>
				</label>
				<fieldset class="cvp-field"><legend>¿Cómo cobras?</legend>
					<?php foreach ( array( 'transfer' => array( 'Transferencia', 'en CUP a tu tarjeta' ), 'cash_cup' => array( 'Efectivo en CUP', 'lo recoges en la tienda' ), 'cash_usd' => array( 'Efectivo en USD', 'lo recoges en la tienda' ) ) as $value => $label ) : ?>
					<label class="cvp-option"><input type="radio" name="cvd_pay_method" value="<?php echo esc_attr( $value ); ?>" <?php checked( $profile['pay'], $value ); ?> data-cvp-method><span><b><?php echo esc_html( $label[0] ); ?></b><small><?php echo esc_html( $label[1] ); ?></small></span></label>
					<?php endforeach; ?>
				</fieldset>
				<div class="cvp-transfer" data-cvp-transfer<?php echo 'transfer' === $profile['pay'] ? '' : ' hidden'; ?>>
					<label class="cvp-field"><span>Tu tarjeta (16 números)</span><input name="cvd_card" inputmode="numeric" autocomplete="off" maxlength="23" placeholder="9200 0000 0000 0000" value="<?php echo esc_attr( CVD_Payout_Math::card_pretty( $profile['card'] ) ); ?>"></label>
					<label class="cvp-field"><span>Teléfono para confirmar</span><input name="cvd_phone" inputmode="tel" autocomplete="off" maxlength="14" placeholder="5X XXX XXX" value="<?php echo esc_attr( $profile['phone'] ); ?>"></label>
					<small class="cvp-muted">Se guardan para la próxima. Solo los ve quien te paga.</small>
				</div>
				<div class="cvp-total"><span>Te pagan</span><strong data-cvp-pay><?php echo esc_html( $quote && $quote['ok'] ? CVD_Payout_Math::money( $quote['pay'], $quote['currency'] ) : '—' ); ?></strong><small data-cvp-deduct><?php echo esc_html( $quote && $quote['deduction'] > 0 ? 'Ya descontada la deuda: ' . CVD_Payout_Math::money( $quote['deduction'], $quote['currency'] ) : '' ); ?></small><small class="cvp-error" data-cvp-error><?php echo esc_html( $quote && ! $quote['ok'] ? $quote['error'] : '' ); ?></small></div>
				<details class="cvp-detail"><summary>Ver el detalle por pedido (<?php echo esc_html( (string) count( $lines ) ); ?>)</summary>
					<ul class="cvp-lines">
					<?php foreach ( $lines as $line ) : ?>
						<li><label><input type="checkbox" name="cvd_orders[]" value="<?php echo esc_attr( (string) $line['id'] ); ?>" checked data-cvp-order>
							<span class="cvp-line-text"><b>#<?php echo esc_html( $line['number'] ); ?></b> · <?php echo esc_html( $line['date'] ); ?><small><?php echo esc_html( self::items_text( $line['items'] ) ); ?></small><small class="cvp-rate"><?php echo esc_html( self::rate_text( $line ) ); ?></small></span>
							<b class="cvp-num"><?php echo esc_html( CVD_Payout_Math::usd( $line['usd'] ) ); ?></b></label></li>
					<?php endforeach; ?>
					</ul>
					<?php foreach ( $debts as $debt ) : ?><p class="cvp-debt-line">− <?php echo esc_html( CVD_Payout_Math::money( $debt['amount'], $debt['currency'] ) . ' · ' . $debt['note'] ); ?></p><?php endforeach; ?>
				</details>
				<input type="hidden" name="cvd_token" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<?php wp_nonce_field( 'cvd_request_payout', 'cvd_payout_nonce' ); ?>
				<button class="cvp-btn" name="cvd_payout_action" value="request_v2" data-cvp-submit>Pedir mi pago</button>
			</form>
			<?php endif; ?>
			<?php echo self::render_gestora_history( $payouts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_gestora_history( array $payouts ): string {
		$html = '<div class="cvp-history"><h3>Mis pagos</h3>';
		if ( ! $payouts ) { return $html . '<p class="cvp-muted">Todavía no has pedido ningún pago.</p></div>'; }
		foreach ( $payouts as $payout ) {
			$view = self::view( $payout );
			list( $state, $label ) = self::gestora_state( $view );
			$when = $payout['requested_at'] ? self::day( (int) strtotime( $payout['requested_at'] . ' UTC' ) ) : '';
			$html .= '<article class="cvp-item is-' . esc_attr( $state ) . '"><div class="cvp-item-top"><div><b>' . esc_html( $view['pay_text'] ) . '</b><small>' . esc_html( ( self::METHOD_LABELS[ $view['kind'] ] ?? 'Pago' ) . ' · pedido el ' . $when . ' · #' . $view['id'] ) . '</small></div><span class="cvp-chip">' . esc_html( $label ) . '</span></div>';
			if ( 'asked' === $state ) { $html .= '<p class="cvp-muted">' . esc_html( $view['cash'] ? 'Te avisamos cuando esté listo para recoger.' : 'Te avisamos cuando te transfieran.' ) . '</p>'; }
			if ( 'pickup' === $state ) {
				$html .= '<form method="post" class="cvp-received"><p>Pasa por la tienda a recogerlo. Cuando lo tengas en la mano, toca aquí:</p><input type="hidden" name="cvd_payout_id" value="' . esc_attr( (string) $view['id'] ) . '">' . wp_nonce_field( 'cvd_payout_received_' . $view['id'], 'cvd_payout_nonce', true, false ) . '<button class="cvp-btn" name="cvd_payout_action" value="received">Recibido</button></form>';
			}
			if ( 'received' === $state ) { $html .= '<p class="cvp-muted">Lo recibiste el ' . esc_html( get_date_from_gmt( $view['received_at'], 'd/m H:i' ) ) . '.</p>'; }
			if ( in_array( $state, array( 'paid', 'pickup', 'received' ), true ) && $view['paid_at'] && ! $view['cash'] ) { $html .= '<p class="cvp-muted">Pagado el ' . esc_html( get_date_from_gmt( $view['paid_at'], 'd/m H:i' ) ) . ( $view['proof'] ? '' : ( $view['reference'] ? ' · ' . esc_html( $view['reference'] ) : '' ) ) . '</p>'; }
			if ( $view['proof'] && class_exists( 'CVD_Payout_Pay_Page' ) ) { $html .= '<a class="cvp-link" href="' . esc_url( CVD_Payout_Pay_Page::proof_url( $view['id'] ) ) . '" target="_blank" rel="noopener">Ver la captura del pago</a>'; }
			if ( $view['lines'] ) {
				$html .= '<details class="cvp-detail"><summary>' . esc_html( count( $view['lines'] ) . ( 1 === count( $view['lines'] ) ? ' pedido' : ' pedidos' ) ) . '</summary><ul class="cvp-lines is-plain">';
				foreach ( $view['lines'] as $line ) { $html .= '<li><span class="cvp-line-text"><b>#' . esc_html( (string) $line['number'] ) . '</b> · ' . esc_html( (string) $line['date'] ) . '<small>' . esc_html( self::items_text( (array) $line['items'] ) ) . '</small><small class="cvp-rate">' . esc_html( self::rate_text( $line ) ) . '</small></span><b class="cvp-num">' . esc_html( CVD_Payout_Math::usd( (float) $line['usd'] ) ) . '</b></li>'; }
				$html .= '</ul>' . ( $view['deduction'] > 0 ? '<p class="cvp-debt-line">− ' . esc_html( CVD_Payout_Math::money( $view['deduction'], $view['deduction_currency'] ) ) . ' de deuda con la tienda</p>' : '' ) . '</details>';
			}
			$html .= '</article>';
		}
		return $html . '</div>';
	}

	/** «Ventilador ×1 (6 USD), Toalla ×2». */
	public static function items_text( array $items ): string {
		$parts = array();
		foreach ( $items as $item ) {
			$parts[] = $item['name'] . ' ×' . $item['qty'] . ( isset( $item['usd'] ) && null !== $item['usd'] && count( $items ) > 1 ? ' (' . CVD_Payout_Math::usd( (float) $item['usd'] ) . ')' : '' );
		}
		return implode( ', ', $parts );
	}

	/** «× 790 (12-oct) = 11.060 CUP». */
	public static function rate_text( array $line ): string {
		if ( (float) ( $line['rate'] ?? 0 ) <= 0 ) { return 'sin tasa guardada'; }
		$rate = str_replace( ' CUP', '', CVD_Payout_Math::cup( (float) $line['rate'] ) );
		$day = '' !== (string) ( $line['rate_day'] ?? '' ) ? ' (' . $line['rate_day'] . ')' : '';
		return '× ' . $rate . $day . ' = ' . CVD_Payout_Math::cup( (float) $line['cup'] );
	}

	public static function debts_text( float $cup, float $usd ): string {
		$parts = array();
		if ( $cup > 0 ) { $parts[] = CVD_Payout_Math::cup( $cup ); }
		if ( $usd > 0 ) { $parts[] = CVD_Payout_Math::usd( $usd ); }
		return implode( ' y ', $parts );
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
			return 'Destino de pago guardado.';
		}
		if ( 'request_v2' === $action && wp_verify_nonce( $nonce, 'cvd_request_payout' ) ) {
			$orders = array_map( 'absint', (array) wp_unslash( $_POST['cvd_orders'] ?? array() ) );
			$result = self::request( $user->ID, array(
				'method'    => sanitize_key( wp_unslash( $_POST['cvd_pay_method'] ?? '' ) ),
				'order_ids' => array_values( array_filter( $orders ) ),
				'amount'    => isset( $_POST['cvd_amount'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['cvd_amount'] ) ) ) : null,
				'card'      => sanitize_text_field( wp_unslash( $_POST['cvd_card'] ?? '' ) ),
				'phone'     => sanitize_text_field( wp_unslash( $_POST['cvd_phone'] ?? '' ) ),
				'token'     => sanitize_text_field( wp_unslash( $_POST['cvd_token'] ?? '' ) ),
			) );
			if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
			$payout = self::get( (int) $result );
			$view = $payout ? self::view( $payout ) : null;
			return '✅ Listo: pediste ' . ( $view ? $view['pay_text'] : 'tu pago' ) . '. Ya le avisamos a Lennys' . ( $view && $view['cash'] ? ' y a la tienda' : '' ) . '. Te escribimos cuando esté pagado.';
		}
		if ( 'request' === $action && wp_verify_nonce( $nonce, 'cvd_request_payout' ) ) {
			$result = self::request( $user->ID );
			return is_wp_error( $result ) ? $result->get_error_message() : 'Solicitud de pago creada correctamente.';
		}
		if ( 'received' === $action ) {
			$payout_id = absint( $_POST['cvd_payout_id'] ?? 0 );
			if ( ! wp_verify_nonce( $nonce, 'cvd_payout_received_' . $payout_id ) ) { return 'La página venció. Recárgala e intenta otra vez.'; }
			$result = self::mark_received( $payout_id, $user->ID );
			return is_wp_error( $result ) ? $result->get_error_message() : '✅ Anotado: recibiste tu pago. ¡Gracias!';
		}
		return 'La página venció. Recárgala e intenta otra vez.';
	}

	// ------------------------------------------------------------------ Contabilidad (administración)

	public static function render_admin(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return '<section class="cvd-sales-denied"><h1>Contabilidad</h1><p>Acceso exclusivo de administración.</p></section>'; }
		$notice = self::handle_admin_action();
		$payouts = self::all_payouts();
		$totals = array( 'requested' => array(), 'approved' => array(), 'paid' => array() );
		foreach ( $payouts as $payout ) { if ( isset( $totals[ $payout['status'] ] ) ) { $currency = $payout['currency']; $totals[ $payout['status'] ][ $currency ] = ( $totals[ $payout['status'] ][ $currency ] ?? 0 ) + (float) $payout['amount']; } }
		$open_debits = self::open_debits_admin();
		$gestoras = get_users( array( 'role' => 'cvd_gestora', 'orderby' => 'display_name', 'number' => 500, 'fields' => array( 'ID', 'display_name' ) ) );
		ob_start(); ?>
		<section class="cvd-accounting-app"><nav><a href="<?php echo esc_url( home_url( '/centro-operaciones/' ) ); ?>">Operaciones</a><a href="<?php echo esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ); ?>">Cerrar sesión</a></nav><header><p>Casa Viva · Administración</p><h1>Contabilidad</h1><span>Solicitudes, liquidaciones y trazabilidad de comisiones.</span></header>
		<?php if ( $notice ) : ?><div class="cvd-accounting-notice"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
		<div class="cvd-accounting-summary"><article><span>Solicitado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['requested'] ) ); ?></strong></article><article><span>Aprobado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['approved'] ) ); ?></strong></article><article><span>Pagado</span><strong><?php echo wp_kses_post( self::format_totals( $totals['paid'] ) ); ?></strong></article></div>
		<section class="cvd-panel cvp-admin-debts"><h2>Deudas de gestoras con la tienda</h2><p>Se descuentan solas cuando la gestora pide su pago. Lo que sobra sigue sumando a su favor.</p>
			<?php foreach ( $open_debits as $debit ) : $owner = get_userdata( absint( $debit['owner_user_id'] ) ); $meta = json_decode( (string) $debit['metadata'], true ); ?>
			<article><strong><?php echo esc_html( CVD_Payout_Math::money( (float) $debit['amount'], (string) $debit['currency'] ) ); ?></strong><span> · <?php echo esc_html( $owner ? $owner->display_name : 'Gestor/a' ); ?> · <?php echo esc_html( is_array( $meta ) && ! empty( $meta['note'] ) ? $meta['note'] : 'Pedido #' . $debit['order_id'] ); ?></span><small> · <?php echo esc_html( array( 'open' => 'por descontar', 'reserved' => 'en un pago pedido' )[ $debit['status'] ] ?? $debit['status'] ); ?></small>
				<?php if ( 'open' === $debit['status'] ) : ?><form method="post" class="cvp-inline"><input type="hidden" name="cvd_debt_id" value="<?php echo esc_attr( $debit['id'] ); ?>"><?php wp_nonce_field( 'cvd_debt_void_' . $debit['id'], 'cvd_debt_nonce' ); ?><button class="is-danger" name="cvd_admin_debt_action" value="void" onclick="return confirm('¿Quitar esta deuda? Queda anotado quién la quitó.')">Quitar</button></form><?php endif; ?></article>
			<?php endforeach; ?>
			<form method="post" class="cvp-debt-form"><label>Gestora<select name="cvd_debt_owner" required><option value="">Elige</option><?php foreach ( $gestoras as $g ) : ?><option value="<?php echo esc_attr( $g->ID ); ?>"><?php echo esc_html( $g->display_name ); ?></option><?php endforeach; ?></select></label><label>Cuánto debe<input name="cvd_debt_amount" type="number" min="0.01" step="0.01" required></label><label>Moneda<select name="cvd_debt_currency"><option>CUP</option><option>USD</option></select></label><label>Por qué<input name="cvd_debt_note" maxlength="120" placeholder="Ej.: mercancía que se llevó el 10-oct" required></label><?php wp_nonce_field( 'cvd_debt_add', 'cvd_debt_nonce' ); ?><button name="cvd_admin_debt_action" value="add">Apuntar deuda</button></form>
		</section>
		<?php echo self::render_history( $payouts, true ); ?></section>
		<?php return (string) ob_get_clean();
	}

	private static function handle_admin_action(): string {
		$debt_action = sanitize_key( (string) ( $_POST['cvd_admin_debt_action'] ?? '' ) );
		if ( $debt_action ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['cvd_debt_nonce'] ?? '' ) );
			if ( 'add' === $debt_action && wp_verify_nonce( $nonce, 'cvd_debt_add' ) ) {
				$result = self::add_debt( absint( $_POST['cvd_debt_owner'] ?? 0 ), (float) wc_format_decimal( wp_unslash( $_POST['cvd_debt_amount'] ?? 0 ), 2 ), sanitize_text_field( wp_unslash( $_POST['cvd_debt_currency'] ?? 'CUP' ) ), sanitize_text_field( wp_unslash( $_POST['cvd_debt_note'] ?? '' ) ), get_current_user_id() );
				return is_wp_error( $result ) ? $result->get_error_message() : 'Deuda apuntada. Se descuenta cuando pida su pago.';
			}
			$debt_id = absint( $_POST['cvd_debt_id'] ?? 0 );
			if ( 'void' === $debt_action && wp_verify_nonce( $nonce, 'cvd_debt_void_' . $debt_id ) ) {
				$result = self::void_debt( $debt_id, get_current_user_id() );
				return is_wp_error( $result ) ? $result->get_error_message() : 'Deuda quitada (queda anotado).';
			}
			return 'La sesión venció.';
		}
		$action = sanitize_key( (string) ( $_POST['cvd_admin_payout_action'] ?? '' ) );
		$payout_id = absint( $_POST['cvd_payout_id'] ?? 0 );
		if ( ! $action || ! $payout_id ) { return ''; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_payout_admin_nonce'] ?? '' ) ), 'cvd_admin_payout_' . $payout_id ) ) { return 'La sesión venció.'; }
		$reference = sanitize_text_field( wp_unslash( $_POST['cvd_payment_reference'] ?? '' ) );
		$proof = 0;
		if ( 'pay' === $action && ! empty( $_FILES['cvd_payment_proof']['name'] ) && class_exists( 'CVD_Payout_Pay_Page' ) ) {
			$uploaded = CVD_Payout_Pay_Page::store_proof( 'cvd_payment_proof', $payout_id );
			if ( is_wp_error( $uploaded ) ) { return $uploaded->get_error_message(); }
			$proof = $uploaded;
		}
		$result = self::transition( $payout_id, $action, $reference, $proof );
		return is_wp_error( $result ) ? $result->get_error_message() : 'Liquidación actualizada.';
	}

	/** Apunta una deuda de la gestora con la tienda (sin pedido). Se descuenta al pedir su pago. */
	public static function add_debt( int $owner_id, float $amount, string $currency, string $note, int $actor_id ) {
		global $wpdb;
		$currency = strtoupper( $currency );
		$owner = get_userdata( $owner_id );
		if ( ! $owner || $amount <= 0 || ! in_array( $currency, array( 'CUP', 'USD' ), true ) || '' === trim( $note ) ) { return new WP_Error( 'cvd_debt_invalid', 'Revisa la gestora, el importe, la moneda y el motivo.' ); }
		$uuid = wp_generate_uuid4();
		$ok = $wpdb->insert( $wpdb->prefix . 'cvd_owner_financial_ledger', array(
			'entry_uuid' => $uuid, 'owner_user_id' => $owner_id, 'order_id' => 0, 'obligation_id' => 'manual-' . substr( str_replace( '-', '', $uuid ), 0, 20 ),
			'entry_type' => 'commission_deduction', 'amount' => wc_format_decimal( $amount, 2 ), 'currency' => $currency, 'status' => 'open',
			'created_at' => current_time( 'mysql', true ), 'created_by' => $actor_id,
			'metadata' => wp_json_encode( array( 'concept' => 'manual', 'note' => mb_substr( $note, 0, 120 ), 'by' => $actor_id ) ),
		) );
		return 1 === $ok ? (int) $wpdb->insert_id : new WP_Error( 'cvd_debt_failed', 'No se pudo apuntar la deuda.' );
	}

	/** Quita una deuda abierta (no la borra: queda «void» con quién y cuándo). */
	public static function void_debt( int $debt_id, int $actor_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cvd_owner_financial_ledger';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT metadata FROM {$table} WHERE id=%d AND status='open' AND entry_type='commission_deduction'", $debt_id ), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'cvd_debt_gone', 'Esa deuda ya no está abierta.' ); }
		$meta = json_decode( (string) $row['metadata'], true );
		$meta = is_array( $meta ) ? $meta : array();
		$meta['voided_by'] = $actor_id; $meta['voided_at'] = current_time( 'mysql', true );
		$ok = $wpdb->update( $table, array( 'status' => 'void', 'metadata' => wp_json_encode( $meta ) ), array( 'id' => $debt_id, 'status' => 'open' ) );
		return 1 === $ok ? true : new WP_Error( 'cvd_debt_gone', 'Esa deuda ya no está abierta.' );
	}

	// ------------------------------------------------------------------ Pedir el pago

	/**
	 * Crea la solicitud de pago.
	 * Sin $opts (como antes de 3.14.23): una por moneda, en USD, con todas las comisiones aprobadas.
	 * Con $opts['method'] (transfer | cash_cup | cash_usd): una sola, con los pedidos elegidos ($opts['order_ids'] o
	 * los que caben en $opts['amount'] USD), cada uno con la tasa de su venta, y todas las deudas abiertas descontadas.
	 * $opts['token'] (uuid de la pantalla) hace que un doble toque devuelva la misma solicitud en vez de otra.
	 * Devuelve el id de la solicitud (con método) o cuántas se crearon (sin método).
	 */
	public static function request( int $owner_id, array $opts = array() ) {
		if ( empty( $opts['method'] ) ) { return self::request_legacy( $owner_id ); }
		global $wpdb;
		$method = sanitize_key( (string) $opts['method'] );
		if ( ! in_array( $method, CVD_Payout_Math::METHODS, true ) ) { return new WP_Error( 'cvd_payout_method', 'Elige cómo quieres cobrar.' ); }
		$card = CVD_Payout_Math::digits( (string) ( $opts['card'] ?? '' ) );
		$phone = CVD_Payout_Math::phone8( (string) ( $opts['phone'] ?? '' ) );
		if ( 'transfer' === $method ) {
			if ( ! CVD_Payout_Math::valid_card( $card ) ) { return new WP_Error( 'cvd_payout_card', 'La tarjeta debe tener 16 números.' ); }
			if ( '' === $phone ) { return new WP_Error( 'cvd_payout_phone', 'Pon tu teléfono móvil (8 números, empieza por 5).' ); }
			update_user_meta( $owner_id, self::PROFILE_ACCOUNT, self::encrypt( $card ) );
			update_user_meta( $owner_id, self::PROFILE_PHONE, self::encrypt( $phone ) );
			update_user_meta( $owner_id, self::PROFILE_METHOD, 'transfermovil' );
		}
		update_user_meta( $owner_id, self::PROFILE_PAY, $method );
		if ( '' === $phone ) { $phone = CVD_Payout_Math::phone8( class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $owner_id ) : '' ); }
		$token = (string) ( $opts['token'] ?? '' );
		$uuid = wp_is_uuid( $token ) ? $token : wp_generate_uuid4();

		$lock = 'cvd-payout-' . $owner_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return new WP_Error( 'cvd_payout_busy', 'Ya se está procesando otra solicitud. Espera un momento.' ); }
		$payout_id = 0;
		try {
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cvd_payouts WHERE payout_uuid=%s AND owner_user_id=%d", $uuid, $owner_id ) );
			if ( $existing ) { return $existing; } // Doble toque: la misma solicitud, sin crear otra ni avisar dos veces.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'No se pudo iniciar la solicitud.' ); }
			$available = self::available_lines( $owner_id );
			if ( ! $available ) { throw new RuntimeException( 'No tienes comisiones listas para cobrar.' ); }
			$by_id = array_column( $available, null, 'id' );
			$wanted = array_values( array_unique( array_map( 'absint', (array) ( $opts['order_ids'] ?? array() ) ) ) );
			if ( $wanted ) {
				foreach ( $wanted as $id ) { if ( ! isset( $by_id[ $id ] ) ) { throw new RuntimeException( 'Un pedido ya se pidió o cambió. Recarga la página.' ); } }
			} else {
				$amount = isset( $opts['amount'] ) && null !== $opts['amount'] ? (float) $opts['amount'] : null;
				$wanted = CVD_Payout_Math::pick( $available, $amount );
			}
			$lines = array_values( array_filter( $available, static fn( array $l ): bool => in_array( $l['id'], $wanted, true ) ) );
			$debts = self::open_debts( $owner_id, true );
			$today = self::today_rate();
			$quote = CVD_Payout_Math::quote( $lines, $debts, $method, $today );
			if ( ! $quote['ok'] ) { throw new RuntimeException( $quote['error'] ); }
			$now = current_time( 'mysql', true );
			$details = array( 'v' => 1, 'lines' => $lines, 'debts' => $debts, 'today' => $today, 'quote' => $quote );
			$inserted = $wpdb->insert( $wpdb->prefix . 'cvd_payouts', array(
				'payout_uuid' => $uuid, 'owner_user_id' => $owner_id, 'amount' => $quote['pay'], 'currency' => $quote['currency'],
				'status' => 'requested', 'method' => 'transfer' === $method ? 'transfermovil' : 'cash', 'pay_method' => $method,
				'account_value' => 'transfer' === $method ? self::encrypt( $card ) : '', 'phone_value' => $phone ? self::encrypt( $phone ) : '',
				'qr_attachment_id' => 0, 'commission_usd' => $quote['usd'], 'commission_cup' => $quote['cup'], 'deduction' => $quote['deduction'],
				'details' => wp_json_encode( $details ), 'requested_at' => $now, 'created_by' => get_current_user_id() ?: $owner_id,
				'created_at' => $now, 'updated_at' => $now,
			) );
			$payout_id = (int) $wpdb->insert_id;
			if ( 1 !== $inserted || ! $payout_id ) { throw new RuntimeException( 'No se pudo crear la solicitud.' ); }
			if ( $debts ) {
				$ids = implode( ',', array_map( 'absint', array_column( $debts, 'id' ) ) );
				$reserved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}cvd_owner_financial_ledger SET status='reserved',payout_id=%d WHERE id IN ({$ids}) AND status='open'", $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids enteros.
				if ( count( $debts ) !== (int) $reserved ) { throw new RuntimeException( 'No se pudo reservar la deuda. Intenta otra vez.' ); }
			}
			foreach ( $lines as $line ) {
				$order = wc_get_order( $line['id'] );
				if ( ! $order ) { throw new RuntimeException( 'Un pedido no se encontró.' ); }
				$item_inserted = $wpdb->insert( $wpdb->prefix . 'cvd_payout_items', array(
					'payout_id' => $payout_id, 'order_id' => $line['id'], 'amount' => $order->get_meta( '_cvd_commission_amount', true ),
					'base_commission' => $order->get_meta( '_cvd_base_commission_amount', true ), 'markup' => $order->get_meta( '_cvd_margin_amount', true ),
					'currency' => 'USD', 'cup_rate' => $line['rate'], 'cup_amount' => $line['cup'], 'created_at' => $now,
				) );
				if ( 1 !== $item_inserted ) { throw new RuntimeException( 'No se pudo anexar una comisión a la solicitud.' ); }
				$order->update_meta_data( '_cvd_payout_id', $payout_id );
				$order->update_meta_data( '_cvd_payout_status', 'requested' );
				$order->save();
				$fresh = wc_get_order( $line['id'] );
				if ( ! $fresh || $payout_id !== absint( $fresh->get_meta( '_cvd_payout_id', true ) ) ) { throw new RuntimeException( 'No se pudo vincular una comisión a la solicitud.' ); }
			}
			if ( ! self::event( $payout_id, 'requested', '', 'requested', array( 'method' => $method, 'orders' => count( $lines ), 'usd' => $quote['usd'], 'pay' => $quote['pay'], 'currency' => $quote['currency'], 'deduction' => $quote['deduction'], 'debts' => array_column( $debts, 'id' ) ) ) ) { throw new RuntimeException( 'No se pudo registrar el historial.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'No se pudo confirmar la solicitud.' ); }
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( isset( $lines ) ) { foreach ( (array) $lines as $line ) { clean_post_cache( (int) $line['id'] ); } }
			return new WP_Error( 'cvd_payout_failed', $error->getMessage() );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		do_action( 'cvd_payout_requested', $payout_id );
		return $payout_id;
	}

	/**
	 * Antes de 3.14.23: una solicitud por moneda, en USD, con todas las comisiones aprobadas.
	 * The named lock serializes requests for the same owner and every write is checked
	 * before COMMIT so a partial payout cannot become visible.
	 */
	private static function request_legacy( int $owner_id ) {
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
						'commission_usd' => 'USD' === $currency ? $gross : 0, 'deduction' => $debits,
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
			wp_mail( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ), 'Nueva solicitud de pago Casa Viva', 'Un/a gestor/a solicitó una liquidación. Revisar: ' . home_url( '/contabilidad/' ) );
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

	// ------------------------------------------------------------------ Pagar y recibir

	/**
	 * «Ya pagué» de la página «Pagar a…»: aprueba si hacía falta y marca pagado, una sola vez.
	 * Devuelve 'paid' (lo pagó ahora), 'already' (ya estaba pagado: doble toque o lo pagó otra persona) o WP_Error.
	 */
	public static function pay_now( int $payout_id, string $reference, int $proof = 0 ) {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$payout = self::get( $payout_id );
			if ( ! $payout ) { return new WP_Error( 'cvd_payout_missing', 'Este pago no existe.' ); }
			if ( 'paid' === $payout['status'] ) { return 'already'; }
			if ( 'rejected' === $payout['status'] ) { return new WP_Error( 'cvd_payout_rejected', 'Este pedido de pago se rechazó. No hay que pagarlo.' ); }
			$action = 'requested' === $payout['status'] ? 'approve' : 'pay';
			$result = self::transition( $payout_id, $action, 'pay' === $action ? $reference : '', 'pay' === $action ? $proof : 0 );
			if ( 'pay' === $action && ! is_wp_error( $result ) ) { return 'paid'; }
			if ( is_wp_error( $result ) && 'cvd_payout_transition' !== $result->get_error_code() && 'cvd_payout_busy' !== $result->get_error_code() ) { return $result; }
			if ( is_wp_error( $result ) && 'cvd_payout_busy' === $result->get_error_code() ) { usleep( 300000 ); }
		}
		$payout = self::get( $payout_id );
		return $payout && 'paid' === $payout['status'] ? 'already' : new WP_Error( 'cvd_payout_busy', 'No se pudo marcar. Intenta otra vez.' );
	}

	/** La gestora tocó «Recibido» (efectivo). Una sola vez; queda en el historial como comprobante. */
	public static function mark_received( int $payout_id, int $owner_id ) {
		global $wpdb;
		$payout = self::get( $payout_id );
		if ( ! $payout || (int) $payout['owner_user_id'] !== $owner_id ) { return new WP_Error( 'cvd_payout_missing', 'Ese pago no es tuyo.' ); }
		if ( ! empty( $payout['received_at'] ) ) { return true; }
		if ( 'paid' !== $payout['status'] ) { return new WP_Error( 'cvd_payout_not_ready', 'Todavía no está listo para recoger.' ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}cvd_payouts SET received_at=%s,received_by=%d,updated_at=%s WHERE id=%d AND owner_user_id=%d AND status='paid' AND received_at IS NULL", $now, $owner_id, $now, $payout_id, $owner_id ) );
		if ( 1 === (int) $updated ) { self::event( $payout_id, 'received', 'paid', 'paid', array( 'by' => $owner_id ) ); do_action( 'cvd_payout_received', $payout_id ); }
		return true;
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
		if ( $owner ) { wp_mail( $owner->user_email, 'Actualización de tu pago Casa Viva', 'Tu pago #' . $payout_id . ': ' . ( array( 'approved' => 'aprobado', 'paid' => 'pagado', 'rejected' => 'rechazado' )[ $next ] ?? $next ) . ( $reference ? "\nReferencia: " . $reference : '' ) . "\n\nConsulta tu historial: " . home_url( '/area-gestoras/#pagos' ) ); }
		// 3.14.23: avisos por WhatsApp (CVD_Payout_Avisos). Venga de donde venga: página «Pagar a…», panel o contabilidad.
		do_action( 'cvd_payout_transitioned', $payout_id, $next );
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
	private static function open_debits_admin(): array { global $wpdb; return $wpdb->get_results( "SELECT id,owner_user_id,order_id,amount,currency,status,metadata FROM {$wpdb->prefix}cvd_owner_financial_ledger WHERE entry_type='commission_deduction' AND status IN ('open','reserved') ORDER BY id DESC LIMIT 200", ARRAY_A ); }
	private static function format_totals( array $totals ): string { if ( ! $totals ) { return '0'; } $parts = array(); foreach ( $totals as $currency => $amount ) { $parts[] = esc_html( CVD_Payout_Math::money( (float) $amount, (string) $currency ) ); } return implode( '<br>', $parts ); }

	private static function render_history( array $payouts, bool $admin ): string {
		if ( ! $payouts ) { return '<div class="cvd-payout-history"><h3>Historial</h3><p>Todavía no hay liquidaciones.</p></div>'; }
		$labels = array( 'requested' => 'Solicitada', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'rejected' => 'Rechazada' ); $html = '<div class="cvd-payout-history"><h3>Historial de liquidaciones</h3>';
		foreach ( $payouts as $payout ) { $view = self::view( $payout ); $html .= '<article><div><span class="cvd-badge">' . esc_html( $labels[ $payout['status']] ?? $payout['status'] ) . ( $view['cash'] && 'paid' === $payout['status'] ? ( $view['received_at'] ? ' · recibido' : ' · por recoger' ) : '' ) . '</span><strong>Liquidación #' . esc_html( $payout['id'] ) . '</strong><small>' . esc_html( $view['name'] ) . ' · ' . esc_html( get_date_from_gmt( $payout['created_at'], 'd/m/Y H:i' ) ) . '</small></div><div><b>' . esc_html( $view['pay_text'] ) . '</b>';
			if ( $admin ) { $html .= '<small>' . esc_html( ( self::METHOD_LABELS[ $view['kind'] ] ?? ucfirst( $payout['method'] ) ) . ( $view['card'] ? ' · ' . CVD_Payout_Math::card_pretty( $view['card'] ) : ( 'other' === $view['kind'] ? ' · ' . $view['account'] : '' ) ) ) . '</small>';
				if ( in_array( $payout['status'], array( 'requested', 'approved' ), true ) ) { $html .= '<a class="cvp-btn cvp-btn-sm" href="' . esc_url( CVD_Payout_Pay_Page::url( (int) $payout['id'] ) ) . '">Pagar</a><form method="post" enctype="multipart/form-data"><input type="hidden" name="cvd_payout_id" value="' . esc_attr( $payout['id'] ) . '">'; ob_start(); wp_nonce_field( 'cvd_admin_payout_' . $payout['id'], 'cvd_payout_admin_nonce' ); $html .= ob_get_clean(); if ( 'requested' === $payout['status'] ) { $html .= '<button name="cvd_admin_payout_action" value="approve">Aprobar</button>'; } else { $html .= '<input name="cvd_payment_reference" placeholder="Referencia bancaria" required><input accept="image/*" name="cvd_payment_proof" type="file"><button name="cvd_admin_payout_action" value="pay">Marcar pagada</button>'; } $html .= '<button class="is-danger" name="cvd_admin_payout_action" value="reject">Rechazar</button></form>'; }
				elseif ( $view['proof'] ) { $html .= '<a href="' . esc_url( CVD_Payout_Pay_Page::proof_url( (int) $payout['id'] ) ) . '" target="_blank" rel="noopener">Ver captura</a>'; } }
			elseif ( $payout['reference'] ) { $html .= '<small>Referencia: ' . esc_html( $payout['reference'] ) . '</small>'; } $html .= '</div></article>'; }
		return $html . '</div>';
	}

	public static function encrypt( string $value ): string { $key = hash( 'sha256', wp_salt( 'auth' ), true ); $iv = random_bytes( 12 ); $tag = ''; $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag ); return base64_encode( $iv . $tag . $cipher ); }
	public static function decrypt( string $value ): string { $raw = base64_decode( $value, true ); if ( ! $raw || strlen( $raw ) < 29 ) { return ''; } $key = hash( 'sha256', wp_salt( 'auth' ), true ); return (string) openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) ); }
}
