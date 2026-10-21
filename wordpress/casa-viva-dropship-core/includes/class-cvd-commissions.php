<?php

defined( 'ABSPATH' ) || exit;

/** Comisiones almacenadas y consultadas desde cada pedido WooCommerce. */
final class CVD_Commissions {
	private const VALID_STATUSES = array( 'pending', 'approved', 'paid', 'cancelled' );
	/**
	 * 3.14.18 (Ernesto, 15-oct, regla 33/33/33): línea que la clienta de una gestora añadió en la tienda al recoger
	 * su vale. La GANANCIA de esa línea (precio − costo de compra × unidades) se reparte 33,34 % gestora,
	 * 33,33 % dependienta que la atendió y 33,33 % tienda. La marca la pone CVD_Order_Edit al añadir con «extra».
	 * 3.14.19 (Ernesto, 15-oct noche, tras escuchar a las gestoras): «tu clienta, tu comisión». La gestora cobra su
	 * comisión NORMAL también por el extra. Premio de la dependienta = opción cvd_extra_staff_pct (% del precio; 0 = en
	 * espera hasta que Ernesto y Lennys lo decidan). Lo pone la tienda, nunca sale de la gestora.
	 */
	public const EXTRA_META = '_cvd_extra_tienda';
	public const EXTRA_STAFF_OPTION = 'cvd_extra_staff_pct';
	/**
	 * 3.14.45 (Ernesto, 21-oct, pedido #4953 «hay que mejorar eso»): el equipo de la tienda (dependientas
	 * cvd_manage_sales y administración manage_woocommerce) no cobra comisión de gestora por lo que compra para sí
	 * o para la tienda. Pedido suyo + cliente «Tienda»/«Casa Viva», su propio teléfono, su propio nombre o una cuenta
	 * de la tienda (opción cvd_store_account_ids) → comisión 0, riesgo «staff_self» y nota en el pedido. Si vende a
	 * un cliente de verdad con su enlace, cobra su comisión con la marca «staff_owner» (alerta «equipo» del programa,
	 * Lennys la marca «Revisada»). El % de mostrador de las dependientas (D80) va aparte y no cambia.
	 * Excepción por persona (lo decide Ernesto): user meta _cvd_staff_commission_allowed = 'yes' → cobra como
	 * cualquier gestora, sin ninguna de estas marcas.
	 */
	public const STAFF_ALLOW_META = '_cvd_staff_commission_allowed';
	public const STAFF_ZERO_META = '_cvd_commission_staff_zero';
	public const STORE_ACCOUNTS_OPTION = 'cvd_store_account_ids';
	private const STORE_NAMES = array( 'tienda', 'la tienda', 'casa viva', 'casaviva', 'tienda casa viva', 'tienda casaviva', 'casa viva tienda' );
	private const STAFF_ZERO_LABELS = array( 'tienda' => 'el cliente es la tienda', 'telefono' => 'lleva su propio teléfono', 'nombre' => 'lleva su propio nombre', 'cuenta_tienda' => 'se hizo desde la cuenta de la tienda' );

	/** ¿Es del equipo de la tienda (dependienta o administración) sin la excepción de Ernesto? */
	public static function is_staff( int $user_id ): bool {
		if ( ! $user_id || 'yes' === (string) get_user_meta( $user_id, self::STAFF_ALLOW_META, true ) ) { return false; }
		return user_can( $user_id, 'cvd_manage_sales' ) || user_can( $user_id, 'manage_woocommerce' );
	}

	/** 3.14.45: '' = no es compra del equipo/tienda; si no, por qué: tienda | cuenta_tienda | telefono | nombre. */
	public static function staff_zero_reason( WC_Order $order, int $owner_user_id ): string {
		if ( ! self::is_staff( $owner_user_id ) ) { return ''; }
		$buyers = array(
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			(string) $order->get_meta( 'cliente_vinculado', true ),
		);
		foreach ( $buyers as $name ) {
			if ( in_array( CVD_Programa_Calc::name_key( (string) $name ), self::STORE_NAMES, true ) ) { return 'tienda'; }
		}
		$accounts = array_map( 'absint', (array) get_option( self::STORE_ACCOUNTS_OPTION, array() ) );
		$customer = absint( $order->get_customer_id() );
		if ( $customer && in_array( $customer, $accounts, true ) ) { return 'cuenta_tienda'; }
		$order_phone = CVD_Programa_Calc::phone_key( (string) $order->get_billing_phone() );
		$phone_match = false;
		if ( '' !== $order_phone ) {
			foreach ( array( '_cvd_whatsapp', '_cvd_phone' ) as $key ) { // billing_phone no: Woo guarda ahí el teléfono del último cliente
				if ( $order_phone === CVD_Programa_Calc::phone_key( (string) get_user_meta( $owner_user_id, $key, true ) ) ) { $phone_match = true; break; }
			}
		}
		if ( $phone_match ) { return 'telefono'; }
		$owner = get_userdata( $owner_user_id );
		if ( $owner ) {
			$order_email = strtolower( sanitize_email( $order->get_billing_email() ) );
			$email_match = $order_email && hash_equals( strtolower( sanitize_email( $owner->user_email ) ), $order_email );
			if ( 'si' === CVD_Programa_Calc::self_purchase( (string) $owner->display_name, $buyers, $email_match ) ) { return 'nombre'; }
		}
		return '';
	}

	/**
	 * 3.14.45: corregir a mano un pedido del equipo que se aprobó antes de esta regla (p. ej. #4953). Solo pedidos con
	 * la comisión aprobada o por verificar y fuera de un pago. $apply = false solo dice lo que haría.
	 * → ['ok' => bool, 'msg' => texto, 'before' => USD, 'after' => USD].
	 */
	public static function staff_rule_fix( int $order_id, bool $apply = false, string $why = '' ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return array( 'ok' => false, 'msg' => 'No existe el pedido.' ); }
		$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
		$reason = self::staff_zero_reason( $order, $owner );
		$before = round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 );
		if ( '' === $reason ) { return array( 'ok' => false, 'msg' => 'No es compra del equipo/tienda según la regla.', 'before' => $before ); }
		if ( ! in_array( $status, array( 'pending', 'approved' ), true ) || absint( $order->get_meta( '_cvd_payout_id', true ) ) ) {
			return array( 'ok' => false, 'msg' => 'Comisión ' . $status . ( absint( $order->get_meta( '_cvd_payout_id', true ) ) ? ' y ya en un pago' : '' ) . ': no se toca aquí.', 'before' => $before );
		}
		if ( ! $apply ) { return array( 'ok' => true, 'msg' => 'Pasaría de ' . number_format( $before, 2, ',', '.' ) . ' USD a 0 (' . self::STAFF_ZERO_LABELS[ $reason ] . '). Estado sigue: ' . $status . '.', 'before' => $before, 'after' => 0.0 ); }
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$snap = $item->get_meta( '_cvd_commission_snapshot', true );
			if ( ! is_array( $snap ) || ! empty( $snap['staff_zero'] ) ) { continue; }
			$snap['commission_without_rule'] = wc_format_decimal( (float) ( $snap['base_commission'] ?? 0 ) + (float) ( $snap['markup'] ?? 0 ), 4 );
			$snap['base_commission'] = wc_format_decimal( 0, 4 );
			$snap['markup'] = wc_format_decimal( 0, 4 );
			$snap['staff_zero'] = $reason;
			if ( is_array( $snap['extra'] ?? null ) ) { $snap['extra']['gestora'] = 0.0; }
			$item->update_meta_data( '_cvd_commission_snapshot', $snap );
			$item->save_meta_data();
		}
		$calc = self::calculate( $order, $owner );
		$order->update_meta_data( '_cvd_commission_amount', $calc['amount'] );
		$order->update_meta_data( '_cvd_base_commission_amount', $calc['commission_amount'] );
		$order->update_meta_data( '_cvd_margin_amount', $calc['margin_amount'] );
		$order->update_meta_data( '_cvd_commission_rate', $calc['effective_rate'] );
		$order->update_meta_data( '_cvd_commission_breakdown', $calc['breakdown'] );
		$order->update_meta_data( 'comision_base', $calc['commission_amount'] );
		$order->update_meta_data( 'comision_tasa', $calc['effective_rate'] );
		$order->update_meta_data( 'ganancia_precio', $calc['margin_amount'] );
		$order->update_meta_data( '_cvd_commission_risk', 'staff_self' );
		$order->update_meta_data( self::STAFF_ZERO_META, $reason );
		$order->update_meta_data( '_cvd_commission_staff_fix', array( 'at' => current_time( 'mysql', true ), 'before' => $before, 'why' => $why ) );
		$order->add_order_note( 'Compra del equipo/tienda: sin comisión de gestora (' . self::STAFF_ZERO_LABELS[ $reason ] . '). Corregido a mano: antes ' . number_format( $before, 2, ',', '.' ) . ' USD' . ( '' !== $why ? ' · ' . $why : '' ) . '.' );
		$order->save();
		return array( 'ok' => true, 'msg' => 'Hecho: ' . number_format( $before, 2, ',', '.' ) . ' USD → ' . number_format( (float) $calc['amount'], 2, ',', '.' ) . ' USD.', 'before' => $before, 'after' => round( (float) $calc['amount'], 2 ) );
	}

	/** % del precio del extra para la dependienta (0 = en espera). */
	public static function extra_staff_pct(): float {
		return max( 0.0, min( 50.0, (float) get_option( self::EXTRA_STAFF_OPTION, 0 ) ) );
	}

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'mark_pending_from_order' ), 40 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'mark_pending' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_pending_from_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'mark_pending' ) );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'mark_pending' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'mark_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'mark_cancelled' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'mark_cancelled' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_order_fields' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_admin_status' ), 30 );
	}

	public static function mark_pending( int $order_id ): void {
		self::store( $order_id, 'pending', false );
	}

	public static function mark_pending_from_order( WC_Order $order ): void {
		self::store( $order->get_id(), 'pending', false );
	}

	public static function mark_approved( int $order_id ): void {
		self::store( $order_id, 'approved', true );
	}

	/** Aplica la aprobación al objeto bloqueado; el servicio guarda y registra el evento. */
	public static function approve_for_closeout( WC_Order $order, int $actor_id, string $at, string $anchor ): ?array {
		$owner_user_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
		if ( ! $owner_user_id || 'organic' === $owner_type ) { return null; }
		$current = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
		if ( in_array( $current, array( 'approved', 'paid', 'cancelled' ), true ) ) { return null; }
		self::flag_self_order_risk( $order, $owner_user_id ); // 3.14.45: regla del equipo/tienda también al aprobar en la caja
		$calculation = self::calculate( $order, $owner_user_id );
		$order->update_meta_data( '_cvd_commission_status', 'approved' );
		$order->update_meta_data( '_cvd_commission_amount', $calculation['amount'] );
		$order->update_meta_data( '_cvd_base_commission_amount', $calculation['commission_amount'] );
		$order->update_meta_data( '_cvd_margin_amount', $calculation['margin_amount'] );
		$order->update_meta_data( '_cvd_commission_base_amount', $calculation['base_amount'] );
		$order->update_meta_data( '_cvd_commission_rate', $calculation['effective_rate'] );
		$order->update_meta_data( '_cvd_commission_breakdown', $calculation['breakdown'] );
		$order->update_meta_data( '_cvd_commission_currency', $order->get_currency() );
		$order->update_meta_data( '_cvd_commission_updated_at', $at );
		$order->update_meta_data( 'comision_estado', 'approved' );
		$order->update_meta_data( 'comision_base', $calculation['commission_amount'] );
		$order->update_meta_data( 'comision_tasa', $calculation['effective_rate'] );
		$order->update_meta_data( 'comision_tipo', $calculation['margin_amount'] > 0 ? 'Comisión base + ganancia de precio' : 'Comisión base' );
		$order->update_meta_data( 'ganancia_precio', $calculation['margin_amount'] );
		$order->update_meta_data( 'precio_original', $calculation['base_amount'] );
		$order->update_meta_data( 'precio_gestora', $calculation['sale_amount'] );
		$history = $order->get_meta( '_cvd_commission_history', true ); $history = is_array( $history ) ? $history : array();
		$history[] = array( 'from'=>$current, 'to'=>'approved', 'user_id'=>$actor_id, 'at'=>$at, 'event_anchor'=>$anchor );
		$order->update_meta_data( '_cvd_commission_history', array_slice( $history, -50 ) );
		$order->add_order_note( sprintf( 'Comisión Casa Viva: %s → aprobada.', $current ) );
		return array( 'domain'=>'commission', 'from'=>$current, 'to'=>'approved' );
	}

	/**
	 * 3.13.64 (12-oct, regla de Ernesto): la comisión es en USD; si la gestora la cobra en CUP va con la tasa
	 * guardada en el pedido el día de la venta. Comisión USD × esa tasa, en CUP enteros. null si el pedido no
	 * tiene tasa (pedidos viejos: solo USD) o la comisión no está en USD. No cambia ningún importe guardado.
	 */
	public static function commission_cup( WC_Order $order ): ?int {
		$rate = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate( $order ) : null;
		$currency = strtoupper( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) );
		if ( null === $rate || 'USD' !== $currency ) { return null; }
		// Sobre los USD en centavos que ve la gestora (5,00 USD), no sobre los 4 decimales guardados.
		return (int) round( round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 ) * $rate );
	}

	/** 3.13.64: «3.900 CUP a 780, tasa del 12-oct» para poner junto a los USD; '' si no hay tasa guardada o la comisión es 0. */
	public static function commission_cup_text( WC_Order $order ): string {
		$cup = self::commission_cup( $order );
		$rate = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate( $order ) : null;
		if ( ! $cup || $cup <= 0 || null === $rate ) { return ''; }
		$day = CVD_Daily_Rates::order_rate_day( $order );
		return CVD_Daily_Rates::format_cup( $cup ) . ' a ' . CVD_Daily_Rates::format_rate( $rate ) . ( '' !== $day ? ', tasa del ' . $day : '' );
	}

	/** 3.13.64: «5,00 USD (3.900 CUP a 780, tasa del 12-oct)»; solo «5,00 USD» si el pedido no guardó la tasa. */
	public static function commission_text( WC_Order $order ): string {
		$currency = strtoupper( (string) ( $order->get_meta( '_cvd_commission_currency', true ) ?: $order->get_currency() ) ) ?: 'USD';
		$text = number_format( (float) $order->get_meta( '_cvd_commission_amount', true ), 2, ',', '.' ) . ' ' . $currency;
		$cup = self::commission_cup_text( $order );
		return '' !== $cup ? $text . ' (' . $cup . ')' : $text;
	}

	public static function mark_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$payout_id = absint( $order->get_meta( '_cvd_payout_id', true ) );
		$payout_status = sanitize_key( (string) $order->get_meta( '_cvd_payout_status', true ) );
		if ( ! $payout_id || 'paid' !== $payout_status ) {
			return;
		}
		self::store( $order_id, 'paid', true );
	}

	public static function mark_cancelled( int $order_id ): void {
		if(class_exists('CVD_Order_Transition_Service')){CVD_Order_Transition_Service::cancel($order_id,sanitize_key((string)(wc_get_order($order_id)?wc_get_order($order_id)->get_status():'cancelled')),array('actor_user_id'=>get_current_user_id(),'system'=>true));return;}
		self::store( $order_id, 'cancelled', true );
	}

	/** Mutación de comisión dentro de la cascada transaccional de cancelación. */
	public static function cancel_for_order(WC_Order $order,int $actor_id,string $at,string $anchor):?array{
		if(!absint($order->get_meta('_cvd_owner_user_id',true))||'organic'===sanitize_key((string)$order->get_meta('_cvd_owner_type',true))){return null;}
		$current=sanitize_key((string)$order->get_meta('_cvd_commission_status',true))?:'pending';if('cancelled'===$current){return null;}
		$order->update_meta_data('_cvd_commission_status','cancelled');$order->update_meta_data('_cvd_commission_updated_at',$at);$order->update_meta_data('comision_estado','cancelled');$history=$order->get_meta('_cvd_commission_history',true);$history=is_array($history)?$history:array();$history[]=array('from'=>$current,'to'=>'cancelled','user_id'=>$actor_id,'at'=>$at,'event_anchor'=>$anchor);$order->update_meta_data('_cvd_commission_history',array_slice($history,-50));$order->add_order_note(sprintf('Comisión Casa Viva: %s → cancelada.',$current));return array('domain'=>'commission','from'=>$current,'to'=>'cancelled');
	}

	public static function admin_order_fields( WC_Order $order ): void {
		if ( ! absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ) {
			return;
		}
		$status = sanitize_key( $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
		wp_nonce_field( 'cvd_save_commission_status_' . $order->get_id(), 'cvd_commission_status_nonce' );
		echo '<div class="order_data_column"><h4>Comisión de gestor/a</h4>';
		echo '<p><strong>Gestor/a:</strong> ' . esc_html( $order->get_meta( 'gestora_nombre', true ) ) . '<br>';
		echo '<strong>Código:</strong> ' . esc_html( $order->get_meta( 'gestora_codigo', true ) ) . '<br>';
		echo '<strong>Comisión:</strong> ' . wp_kses_post( wc_price( (float) $order->get_meta( '_cvd_commission_amount', true ), array( 'currency' => $order->get_currency() ) ) ) . '</p>';
		$risk = (string) $order->get_meta( '_cvd_commission_risk', true );
		if ( 'staff_self' === $risk ) { echo '<p class="notice notice-info"><strong>Compra del equipo/tienda:</strong> sin comisión de gestora.</p>'; }
		elseif ( 'staff_owner' === $risk ) { echo '<p class="notice notice-warning"><strong>Revisión:</strong> lo vendió alguien del equipo de la tienda con su enlace; cobra comisión de gestora.</p>'; }
		elseif ( $risk ) { echo '<p class="notice notice-warning"><strong>Revisión requerida:</strong> el pedido coincide con datos de quien vendió.</p>'; }
		echo '<p><label for="cvd_commission_status"><strong>Estado</strong></label><br><select id="cvd_commission_status" name="cvd_commission_status">';
		foreach ( self::allowed_admin_statuses( $status ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p></div>';
	}

	public static function save_admin_status( int $order_id ): void {
		if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_POST['cvd_commission_status_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cvd_commission_status_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cvd_save_commission_status_' . $order_id ) ) {
			return;
		}
		$status = isset( $_POST['cvd_commission_status'] ) ? sanitize_key( wp_unslash( $_POST['cvd_commission_status'] ) ) : '';
		$order = wc_get_order( $order_id );
		$current = $order ? ( sanitize_key( $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending' ) : '';
		if ( $order && array_key_exists( $status, self::allowed_admin_statuses( $current ) ) ) {
			self::store( $order_id, $status, true );
		}
	}

	private static function allowed_admin_statuses( string $current ): array {
		$labels = array( 'pending' => 'Por verificar', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'cancelled' => 'Cancelada' );
		$transitions = array(
			'pending'   => array( 'pending', 'approved', 'cancelled' ),
			'approved'  => array( 'approved', 'cancelled' ),
			'paid'      => array( 'paid', 'cancelled' ),
			'cancelled' => array( 'cancelled' ),
		);
		return array_intersect_key( $labels, array_flip( $transitions[ $current ] ?? array( 'pending' ) ) );
	}

	private static function store( int $order_id, string $status, bool $force_status ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$owner_user_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner_type = sanitize_key( $order->get_meta( '_cvd_owner_type', true ) );
		if ( ! $owner_user_id || 'organic' === $owner_type ) {
			return;
		}

		$current_status = sanitize_key( $order->get_meta( '_cvd_commission_status', true ) );
		if ( ! $force_status && in_array( $current_status, array( 'approved', 'paid', 'cancelled' ), true ) ) {
			$status = $current_status;
		}

		$calculation = self::calculate( $order, $owner_user_id );
		if ( 'pending' === $status ) { self::flag_self_order_risk( $order, $owner_user_id ); }
		$order->update_meta_data( '_cvd_commission_status', $status );
		$order->update_meta_data( '_cvd_commission_amount', $calculation['amount'] );
		$order->update_meta_data( '_cvd_base_commission_amount', $calculation['commission_amount'] );
		$order->update_meta_data( '_cvd_margin_amount', $calculation['margin_amount'] );
		$order->update_meta_data( '_cvd_commission_base_amount', $calculation['base_amount'] );
		$order->update_meta_data( '_cvd_commission_rate', $calculation['effective_rate'] );
		$order->update_meta_data( '_cvd_commission_breakdown', $calculation['breakdown'] );
		$order->update_meta_data( '_cvd_commission_currency', $order->get_currency() );
		$order->update_meta_data( '_cvd_commission_updated_at', current_time( 'mysql', true ) );

		$order->update_meta_data( 'comision_estado', $status );
		$order->update_meta_data( 'comision_base', $calculation['commission_amount'] );
		$order->update_meta_data( 'comision_tasa', $calculation['effective_rate'] );
		$order->update_meta_data( 'comision_tipo', $calculation['margin_amount'] > 0 ? 'Comisión base + ganancia de precio' : 'Comisión base' );
		$order->update_meta_data( 'ganancia_precio', $calculation['margin_amount'] );
		$order->update_meta_data( 'precio_original', $calculation['base_amount'] );
		$order->update_meta_data( 'precio_gestora', $calculation['sale_amount'] );
		$commission_event = null;
		$canonical_commission_event = $status !== $current_status ? array( 'from' => $current_status ?: 'none', 'to' => $status, 'at' => current_time( 'mysql', true ), 'event_anchor' => wp_generate_uuid4() ) : null;
		if ( $force_status && $status !== $current_status ) {
			$history = $order->get_meta( '_cvd_commission_history', true );
			$history = is_array( $history ) ? $history : array();
			$commission_event = array( 'from' => $current_status ?: 'none', 'to' => $status, 'user_id' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'event_anchor' => $canonical_commission_event['event_anchor'] );
			$history[] = $commission_event;
			$order->update_meta_data( '_cvd_commission_history', array_slice( $history, -50 ) );
			$order->add_order_note( sprintf( 'Comisión Casa Viva: %s → %s.', $current_status ?: 'sin estado', $status ) );
		}
		$order->save();
		if ( $commission_event ) {
			do_action( 'cvd_order_transition_observed', $order->get_id(), 'commission', $commission_event['from'], $commission_event['to'], 'cvd_commissions_store', array(), $commission_event['event_anchor'] );
		}
		elseif ( $canonical_commission_event ) {
			do_action( 'cvd_order_transition_observed', $order->get_id(), 'commission', $canonical_commission_event['from'], $canonical_commission_event['to'], 'cvd_commissions_store', array(), $canonical_commission_event['event_anchor'] );
		}
	}

	private static function flag_self_order_risk( WC_Order $order, int $owner_user_id ): void {
		$owner = get_userdata( $owner_user_id );
		if ( ! $owner ) { return; }

		$order->delete_meta_data( '_cvd_commission_risk' );
		// 3.14.45: equipo de la tienda → «staff_self» (comisión 0, la pone calculate) o «staff_owner» (cobra, a revisar).
		$zero_before = (string) $order->get_meta( self::STAFF_ZERO_META, true );
		if ( self::is_staff( $owner_user_id ) ) {
			$reason = self::staff_zero_reason( $order, $owner_user_id );
			if ( '' !== $reason ) {
				$order->update_meta_data( '_cvd_commission_risk', 'staff_self' );
				$order->update_meta_data( self::STAFF_ZERO_META, $reason );
				if ( $reason !== $zero_before ) { $order->add_order_note( 'Compra del equipo/tienda: sin comisión de gestora (' . self::STAFF_ZERO_LABELS[ $reason ] . ').' ); }
				return;
			}
			$order->update_meta_data( '_cvd_commission_risk', 'staff_owner' );
		}
		if ( '' !== $zero_before ) {
			$order->delete_meta_data( self::STAFF_ZERO_META );
			$order->add_order_note( 'Ya no es compra del equipo/tienda: vuelve la comisión de gestora.' );
		}
		if ( self::is_staff( $owner_user_id ) ) { return; }
		$order_email = strtolower( sanitize_email( $order->get_billing_email() ) );
		$owner_email = strtolower( sanitize_email( $owner->user_email ) );
		$order_phone = preg_replace( '/\D+/', '', $order->get_billing_phone() );
		$owner_phone = (string) get_user_meta( $owner_user_id, '_cvd_whatsapp', true );
		if ( '' === $owner_phone ) {
			$owner_phone = (string) get_user_meta( $owner_user_id, '_cvd_phone', true );
		}
		$owner_phone = preg_replace( '/\D+/', '', $owner_phone );
		if ( ( $order_email && $owner_email && hash_equals( $owner_email, $order_email ) ) || ( $order_phone && $owner_phone && hash_equals( $owner_phone, $order_phone ) ) ) {
			$order->update_meta_data( '_cvd_commission_risk', 'self_order' );
		}
	}

	private static function commission_policy( ?WC_Product $product, float $gestora_rate ): array {
		$type = 'percent';
		$value = '';
		$source = 'gestora';

		if ( $product ) {
			$type_value = $product->get_meta( '_cvd_commission_type', true );
			$commission_value = $product->get_meta( '_cvd_commission_value', true );
			if ( '' !== $type_value || '' !== $commission_value ) {
				$type = 'fixed' === sanitize_key( (string) $type_value ) ? 'fixed' : 'percent';
				$value = $commission_value;
				$source = 'product';
			} elseif ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$type_value = $parent->get_meta( '_cvd_commission_type', true );
					$commission_value = $parent->get_meta( '_cvd_commission_value', true );
					if ( '' !== $type_value || '' !== $commission_value ) {
						$type = 'fixed' === sanitize_key( (string) $type_value ) ? 'fixed' : 'percent';
						$value = $commission_value;
						$source = 'parent_product';
					}
				}
			}
		}

		if ( '' === $value ) {
			$value = $gestora_rate;
		}
		return array( 'type' => $type, 'value' => (float) $value, 'source' => $source );
	}

	/** Costo de compra en USD de un producto (o de su producto padre); null si no está apuntado. */
	public static function unit_cost( ?WC_Product $product ): ?float {
		$candidates = array( $product );
		if ( $product && $product->is_type( 'variation' ) && $product->get_parent_id() ) { $candidates[] = wc_get_product( $product->get_parent_id() ); }
		foreach ( $candidates as $p ) {
			if ( ! $p ) { continue; }
			$value = $p->get_meta( '_cvd_cost_usd', true );
			if ( '' !== (string) $value && is_numeric( $value ) && (float) $value > 0 ) { return (float) $value; }
		}
		return null;
	}

	/**
	 * 3.14.19: reparto de una línea «extra en tienda», o null si la línea no lo es. $gestora = su comisión normal.
	 * → [ cost, profit, gestora, staff, staff_pct, store, staff_user_id, staff_name ] en USD; sin costo: profit/store = null.
	 */
	public static function extra_split( WC_Order_Item_Product $item, ?WC_Product $product, float $line, int $quantity, float $gestora = 0.0 ): ?array {
		$mark = $item->get_meta( self::EXTRA_META, true );
		if ( ! is_array( $mark ) || ! $mark ) { return null; }
		$staff_id = absint( $mark['staff'] ?? 0 );
		$base = array( 'staff_user_id' => $staff_id, 'staff_name' => (string) ( $mark['staffName'] ?? '' ), 'at' => (string) ( $mark['at'] ?? '' ) );
		$cost = self::unit_cost( $product );
		$gestora = round( $gestora, 4 );
		$pct = self::extra_staff_pct();
		$staff = $staff_id ? round( $line * $pct / 100, 4 ) : 0.0;
		$base += array( 'gestora' => $gestora, 'staff' => $staff, 'staff_pct' => $pct );
		if ( null === $cost ) { return $base + array( 'cost' => null, 'profit' => null, 'store' => null ); }
		$profit = max( 0.0, $line - $cost * max( 1, $quantity ) );
		return $base + array( 'cost' => $cost, 'profit' => round( $profit, 4 ), 'store' => round( $profit - $gestora - $staff, 4 ) );
	}

	/** «Extra en tienda: Organizador ×1 (25,00 USD) → gestora 3,00 (su comisión) · Zaymi 0,00 · tienda 5,00.» ('' si no hay). */
	public static function extra_note( WC_Order $order ): string {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$snap = $item->get_meta( '_cvd_commission_snapshot', true );
			$x = is_array( $snap ) && is_array( $snap['extra'] ?? null ) ? $snap['extra'] : null;
			if ( ! $x ) { continue; }
			$money = static fn( $v ) => number_format( (float) $v, 2, ',', '.' );
			$who = '' !== $x['staff_name'] ? $x['staff_name'] : 'sin dependienta';
			$out[] = $item->get_name() . ' ×' . $item->get_quantity() . ' (' . $money( $item->get_total() ) . ' USD) → gestora ' . $money( $x['gestora'] ) . ' (su comisión) · ' . $who . ' ' . $money( $x['staff'] )
				. ( null === $x['profit'] ? ' · tienda: sin costo apuntado' : ' · tienda ' . $money( $x['store'] ) . ' USD' );
		}
		return $out ? 'Extra en tienda: ' . implode( '; ', $out ) . '.' : '';
	}

	public static function calculate( WC_Order $order, int $owner_user_id ): array {
		$default_rate = (float) get_option( 'cvd_default_commission_rate', 13 );
		$user_rate = get_user_meta( $owner_user_id, '_cvd_commission_rate', true );
		$gestora_rate = '' !== $user_rate ? (float) $user_rate : $default_rate;
		$base_amount = 0.0;
		$sale_amount = 0.0;
		$commission_amount = 0.0;
		$margin_amount = 0.0;
		$breakdown = array();
		$pricing_gestora_id = absint( $order->get_meta( '_cvd_pricing_gestora_user_id', true ) );
		// 3.14.45: compra del equipo/tienda → comisión de gestora 0. Con la comisión ya aprobada, pagada o cancelada
		// no se recalcula nada (se queda la foto guardada): eso solo se corrige a mano con staff_rule_fix().
		$locked = in_array( sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ), array( 'approved', 'paid', 'cancelled' ), true );
		$staff_zero = $locked ? '' : self::staff_zero_reason( $order, $owner_user_id );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$line = (float) $item->get_total();
			$quantity = max( 1, (int) $item->get_quantity() );
			$base_unit = (float) $item->get_meta( '_cvd_base_unit_price', true );
			$line_base = $base_unit > 0 ? min( $line, $base_unit * $quantity ) : $line;
			$line_margin = ( $base_unit > 0 && $pricing_gestora_id === $owner_user_id ) ? max( 0, $line - ( $base_unit * $quantity ) ) : 0;

			$snapshot = $item->get_meta( '_cvd_commission_snapshot', true );
			$is_extra = is_array( $item->get_meta( self::EXTRA_META, true ) ) && $item->get_meta( self::EXTRA_META, true );
			// 3.14.45: si el pedido entra o sale de la regla del equipo/tienda (p. ej. se cambió el cliente), la foto se rehace.
			if ( ! $locked && is_array( $snapshot ) && ( '' !== $staff_zero ) !== ! empty( $snapshot['staff_zero'] ) ) { $snapshot = null; }
			if ( ! is_array( $snapshot ) || ! isset( $snapshot['base_commission'], $snapshot['base_amount'], $snapshot['sale_amount'] ) ) {
				$policy = self::commission_policy( $product, $gestora_rate );
				$line_commission = 'fixed' === $policy['type'] ? $policy['value'] * $quantity : $line_base * ( $policy['value'] / 100 );
				$without_rule = $line_commission + $line_margin;
				if ( '' !== $staff_zero ) { $line_commission = 0.0; $line_margin = 0.0; }
				$snapshot = array(
					'product_id'       => $item->get_product_id(),
					'variation_id'     => $item->get_variation_id(),
					'owner_user_id'    => $owner_user_id,
					'type'             => $policy['type'],
					'value'            => wc_format_decimal( $policy['value'], 4 ),
					'policy_source'    => $policy['source'],
					'quantity'         => $quantity,
					'base_amount'      => wc_format_decimal( $line_base, 4 ),
					'sale_amount'      => wc_format_decimal( $line, 4 ),
					'base_commission'  => wc_format_decimal( $line_commission, 4 ),
					'markup'           => wc_format_decimal( $line_margin, 4 ),
					'captured_at'      => current_time( 'mysql', true ),
				);
				if ( '' !== $staff_zero ) { $snapshot['staff_zero'] = $staff_zero; $snapshot['commission_without_rule'] = wc_format_decimal( $without_rule, 4 ); }
				// 3.14.19: extra en tienda → la gestora cobra su comisión normal; el premio de la dependienta va aparte.
				if ( $is_extra ) { $snapshot['extra'] = self::extra_split( $item, $product, $line, $quantity, (float) $line_commission ); }
				$item->update_meta_data( '_cvd_commission_snapshot', $snapshot );
				$item->save_meta_data();
			}

			$base_amount += (float) $snapshot['base_amount'];
			$sale_amount += (float) $snapshot['sale_amount'];
			$commission_amount += (float) $snapshot['base_commission'];
			$margin_amount += (float) ( $snapshot['markup'] ?? 0 );
			$breakdown[] = $snapshot;
		}

		$amount = $commission_amount + $margin_amount;
		$effective_rate = $base_amount > 0 ? ( $commission_amount / $base_amount ) * 100 : 0;
		return array(
			'amount'            => wc_format_decimal( $amount, 4 ),
			'commission_amount' => wc_format_decimal( $commission_amount, 4 ),
			'margin_amount'     => wc_format_decimal( $margin_amount, 4 ),
			'base_amount'       => wc_format_decimal( $base_amount, 4 ),
			'sale_amount'       => wc_format_decimal( $sale_amount, 4 ),
			'effective_rate'    => wc_format_decimal( $effective_rate, 4 ),
			'breakdown'         => $breakdown,
		);
	}
}
