<?php

defined( 'ABSPATH' ) || exit;

/** Comisiones almacenadas y consultadas desde cada pedido WooCommerce. */
final class CVD_Commissions {
	private const VALID_STATUSES = array( 'pending', 'approved', 'paid', 'cancelled' );

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

	public static function mark_paid( int $order_id ): void {
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
		echo '<div class="order_data_column"><h4>Comisión de gestora</h4>';
		echo '<p><strong>Gestora:</strong> ' . esc_html( $order->get_meta( 'gestora_nombre', true ) ) . '<br>';
		echo '<strong>Código:</strong> ' . esc_html( $order->get_meta( 'gestora_codigo', true ) ) . '<br>';
		echo '<strong>Comisión:</strong> ' . wp_kses_post( wc_price( (float) $order->get_meta( '_cvd_commission_amount', true ), array( 'currency' => $order->get_currency() ) ) ) . '</p>';
		if ( $order->get_meta( '_cvd_commission_risk', true ) ) { echo '<p class="notice notice-warning"><strong>Revisión requerida:</strong> el pedido coincide con datos de la gestora.</p>'; }
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
			'approved'  => array( 'approved', 'paid', 'cancelled' ),
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

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$line = (float) $item->get_total();
			$quantity = max( 1, (int) $item->get_quantity() );
			$base_unit = (float) $item->get_meta( '_cvd_base_unit_price', true );
			$line_base = $base_unit > 0 ? min( $line, $base_unit * $quantity ) : $line;
			$line_margin = ( $base_unit > 0 && $pricing_gestora_id === $owner_user_id ) ? max( 0, $line - ( $base_unit * $quantity ) ) : 0;

			$snapshot = $item->get_meta( '_cvd_commission_snapshot', true );
			if ( ! is_array( $snapshot ) || ! isset( $snapshot['base_commission'], $snapshot['base_amount'], $snapshot['sale_amount'] ) ) {
				$policy = self::commission_policy( $product, $gestora_rate );
				$line_commission = 'fixed' === $policy['type'] ? $policy['value'] * $quantity : $line_base * ( $policy['value'] / 100 );
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
