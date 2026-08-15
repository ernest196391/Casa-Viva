<?php

defined( 'ABSPATH' ) || exit;

/** Libro mayor y liquidaciones de mensajeros. Los asientos son anexos, no editables. */
final class CVD_Messenger_Accounting {
	public static function register(): void {
		add_action( 'admin_post_cvd_messenger_settlement', array( __CLASS__, 'request_action' ) );
		add_action( 'admin_post_cvd_messenger_settlement_admin', array( __CLASS__, 'admin_action' ) );
		add_shortcode( 'casa_viva_messenger_accounting', array( __CLASS__, 'render_admin' ) );
	}

	/** Crea una sola ganancia cuando la entrega y el efectivo ya fueron verificados. */
	public static function credit_order( WC_Order $order ): bool {
		global $wpdb;
		if ( 'closed' !== CVD_Delivery::status( $order ) || 'verified' !== $order->get_meta( '_cvd_cash_status', true ) ) { return false; }
		$messenger_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$amount = (float) $order->get_meta( '_cvd_shipping_courier_amount_cup', true );
		if ( ! $messenger_id || $amount <= 0 ) { return false; }
		$created = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}cvd_messenger_ledger (entry_uuid,order_id,messenger_user_id,entry_type,amount,currency,status,created_at,created_by,metadata) VALUES (%s,%d,%d,'earning',%f,'CUP','available',%s,%d,%s)",
			wp_generate_uuid4(), $order->get_id(), $messenger_id, $amount, current_time( 'mysql', true ), get_current_user_id(), wp_json_encode( array( 'platform_amount' => (float) $order->get_meta( '_cvd_shipping_platform_amount_cup', true ), 'rate_snapshot' => (float) $order->get_meta( '_cvd_shipping_platform_rate', true ) ) )
		) );
		if ( $created ) { $order->update_meta_data( '_cvd_messenger_ledger_status', 'available' ); $order->save(); }
		return (bool) $created;
	}

	/** Una cancelación nunca borra historia: anula solo un asiento aún no pagado. */
	public static function void_order( WC_Order $order ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}cvd_messenger_ledger SET status='void' WHERE order_id=%d AND entry_type='earning' AND status='available'", $order->get_id() ) );
		$order->update_meta_data( '_cvd_messenger_ledger_status', 'void' );
		$order->save();
	}

	public static function balances( int $messenger_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status,SUM(amount) total FROM {$wpdb->prefix}cvd_messenger_ledger WHERE messenger_user_id=%d GROUP BY status", $messenger_id ), ARRAY_A );
		$result = array( 'available'=>0.0, 'reserved'=>0.0, 'paid'=>0.0 );
		foreach ( $rows as $row ) { if ( isset( $result[ $row['status'] ] ) ) { $result[ $row['status'] ] = (float) $row['total']; } }
		return $result;
	}

	public static function render_messenger( WP_User $user ): string {
		global $wpdb;
		$balances = self::balances( $user->ID );
		$settlements = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_messenger_settlements WHERE messenger_user_id=%d ORDER BY id DESC LIMIT 30", $user->ID ), ARRAY_A );
		ob_start(); ?>
		<section class="cvd-panel cvd-payout-panel" id="liquidaciones">
			<div class="cvd-section-title"><p class="cvd-kicker">Ganancias verificadas</p><h2>Liquidaciones</h2><p>Solo puedes solicitar carreras cerradas y conciliadas por Casa Viva.</p></div>
			<div class="cvd-payout-balance"><article><span>Disponible</span><strong><?php echo esc_html( number_format_i18n( $balances['available'], 0 ) ); ?> CUP</strong></article><article><span>En proceso</span><strong><?php echo esc_html( number_format_i18n( $balances['reserved'], 0 ) ); ?> CUP</strong></article><article><span>Pagado</span><strong><?php echo esc_html( number_format_i18n( $balances['paid'], 0 ) ); ?> CUP</strong></article></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="cvd_messenger_settlement"><label>Método de pago<select name="method" required><option value="cash">Efectivo</option><option value="transfermovil">Transfermóvil</option><option value="transferencia">Transferencia</option></select></label><?php wp_nonce_field( 'cvd_messenger_settlement' ); ?><button class="cvd-primary" <?php disabled( $balances['available'] <= 0 ); ?>>Solicitar pago</button></form>
			<div class="cvd-payout-history"><h3>Historial</h3><?php if ( ! $settlements ) : ?><p>Todavía no hay liquidaciones.</p><?php else : foreach ( $settlements as $item ) : ?><article><strong>Liquidación #<?php echo esc_html( $item['id'] ); ?></strong><span><?php echo esc_html( number_format_i18n( $item['amount'], 0 ) ); ?> CUP · <?php echo esc_html( self::status_label( $item['status'] ) ); ?></span><?php if ( $item['reference'] ) : ?><small>Referencia: <?php echo esc_html( $item['reference'] ); ?></small><?php endif; ?></article><?php endforeach; endif; ?></div>
		</section><?php return (string) ob_get_clean();
	}

	public static function request_action(): void {
		if ( ! is_user_logged_in() ) { wp_die( 'Debes iniciar sesión.' ); }
		check_admin_referer( 'cvd_messenger_settlement' );
		$user = wp_get_current_user();
		if ( 'mensajero' !== CVD_Registration::program_type( $user ) ) { wp_die( 'Esta cuenta no es de mensajero.' ); }
		$result = self::create_settlement( $user->ID, sanitize_key( wp_unslash( $_POST['method'] ?? '' ) ) );
		wp_safe_redirect( add_query_arg( 'liquidacion', is_wp_error( $result ) ? 'error' : 'creada', home_url( '/area-mensajeros/#liquidaciones' ) ) ); exit;
	}

	private static function create_settlement( int $messenger_id, string $method ) {
		global $wpdb;
		if ( ! in_array( $method, array( 'cash','transfermovil','transferencia' ), true ) ) { return new WP_Error( 'method', 'Método no válido.' ); }
		$lock = 'cvd-messenger-settlement-' . $messenger_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return new WP_Error( 'busy', 'Ya se procesa otra solicitud.' ); }
		try {
			$wpdb->query( 'START TRANSACTION' );
			$ledger = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cvd_messenger_ledger WHERE messenger_user_id=%d AND status='available' FOR UPDATE", $messenger_id ), ARRAY_A );
			if ( ! $ledger ) { throw new RuntimeException( 'No hay ganancias disponibles.' ); }
			$total = array_sum( array_map( static fn( $row ) => (float) $row['amount'], $ledger ) );
			$now = current_time( 'mysql', true );
			$wpdb->insert( $wpdb->prefix . 'cvd_messenger_settlements', array( 'settlement_uuid'=>wp_generate_uuid4(), 'messenger_user_id'=>$messenger_id, 'amount'=>$total, 'currency'=>'CUP', 'status'=>'requested', 'method'=>$method, 'requested_at'=>$now, 'created_at'=>$now ) );
			$id = (int) $wpdb->insert_id; if ( ! $id ) { throw new RuntimeException( 'No se pudo crear la liquidación.' ); }
			foreach ( $ledger as $row ) { $wpdb->insert( $wpdb->prefix . 'cvd_messenger_settlement_items', array( 'settlement_id'=>$id, 'ledger_id'=>$row['id'], 'order_id'=>$row['order_id'], 'amount'=>$row['amount'], 'created_at'=>$now ) ); }
			$ids = implode( ',', array_map( 'absint', wp_list_pluck( $ledger, 'id' ) ) );
			$wpdb->query( "UPDATE {$wpdb->prefix}cvd_messenger_ledger SET status='reserved' WHERE id IN ({$ids})" );
			$wpdb->query( 'COMMIT' ); return $id;
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'failed', $e->getMessage() ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public static function render_admin(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return '<p>Acceso restringido.</p>'; }
		global $wpdb; $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cvd_messenger_settlements ORDER BY id DESC LIMIT 100", ARRAY_A );
		ob_start(); ?><section class="cvd-accounting-app"><header><p>Casa Viva · Mensajería</p><h1>Liquidaciones de mensajeros</h1><span>Verifica y registra cada pago una sola vez.</span></header><div class="cvd-payout-history"><?php if ( ! $rows ) : ?><p>No hay solicitudes.</p><?php else : foreach ( $rows as $row ) : $messenger=get_userdata((int)$row['messenger_user_id']); ?><article><div><strong>#<?php echo esc_html($row['id']); ?> · <?php echo esc_html($messenger?$messenger->display_name:'Mensajero'); ?></strong><small><?php echo esc_html(self::status_label($row['status'])); ?></small></div><b><?php echo esc_html(number_format_i18n($row['amount'],0)); ?> CUP</b><?php if ('requested'===$row['status']): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="cvd_messenger_settlement_admin"><input type="hidden" name="settlement_id" value="<?php echo esc_attr($row['id']); ?>"><input name="reference" required placeholder="Referencia o comprobante"><input type="hidden" name="decision" value="pay"><?php wp_nonce_field('cvd_messenger_settlement_admin_'.$row['id']); ?><button>Marcar pagada</button></form><?php endif; ?></article><?php endforeach; endif; ?></div></section><?php return (string)ob_get_clean();
	}

	public static function admin_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.' ); }
		$id=absint($_POST['settlement_id']??0); check_admin_referer('cvd_messenger_settlement_admin_'.$id);
		$reference=sanitize_text_field(wp_unslash($_POST['reference']??'')); if(!$reference){wp_die('La referencia es obligatoria.');}
		global $wpdb; $lock='cvd-messenger-pay-'.$id; if(1!==(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))){wp_die('La liquidación está siendo procesada.');}
		try { $wpdb->query('START TRANSACTION'); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cvd_messenger_settlements WHERE id=%d FOR UPDATE",$id),ARRAY_A); if(!$row||'requested'!==$row['status']){throw new RuntimeException('Esta liquidación ya fue procesada.');} $wpdb->update($wpdb->prefix.'cvd_messenger_settlements',array('status'=>'paid','reference'=>$reference,'paid_at'=>current_time('mysql',true),'paid_by'=>get_current_user_id()),array('id'=>$id)); $ledger_ids=$wpdb->get_col($wpdb->prepare("SELECT ledger_id FROM {$wpdb->prefix}cvd_messenger_settlement_items WHERE settlement_id=%d",$id)); if($ledger_ids){$ids=implode(',',array_map('absint',$ledger_ids));$wpdb->query("UPDATE {$wpdb->prefix}cvd_messenger_ledger SET status='paid' WHERE status='reserved' AND id IN ({$ids})");} $wpdb->query('COMMIT'); } catch(Throwable $e){$wpdb->query('ROLLBACK');wp_die(esc_html($e->getMessage()));} finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
		wp_safe_redirect(home_url('/contabilidad-mensajeros/?pagada=1'));exit;
	}

	private static function status_label( string $status ): string { return array( 'requested'=>'Solicitada','paid'=>'Pagada','cancelled'=>'Cancelada' )[$status] ?? ucfirst($status); }
}
