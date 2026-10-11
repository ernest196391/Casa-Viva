<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Dinero por entregar a la tienda» (3.13.55). El 11-oct Lennys tuvo que reconstruir a mano quién tenía
 * dinero de la tienda (Fernando 60 USD, Isabel 11.700 CUP…). Esta lista sale SOLO de lo registrado:
 * pedidos a domicilio que el mensajero marcó entregados (_cvd_delivery_status = delivered) con el dinero
 * todavía «pendiente de devolver» (_cvd_cash_status = pending_return) y lo que se anotó al entregar
 * (_cvd_collection_amount_*, o las obligaciones de pago saldadas si el pedido las tiene). Nada se calcula
 * del total del pedido. Lo cobrado por transferencia no lo tiene el mensajero y no sale.
 *
 * «Recibido» solo deja constancia de quién recibió el dinero y cuándo (_cvd_cash_store_received_*):
 * no cierra el pedido ni aprueba comisiones. Ese cierre sigue siendo «Registrar dinero recibido» en Ventas.
 * Se ve en wp-admin (Casa Viva → Dinero por entregar) y por el puente del panel (/panel/messenger-cash).
 */
final class CVD_Messenger_Cash {
	private const RECEIVED_AT   = '_cvd_cash_store_received_at';
	private const RECEIVED_BY   = '_cvd_cash_store_received_by';
	private const RECEIVED_NAME = '_cvd_cash_store_received_by_name';
	private const RECEIVED_AMTS = '_cvd_cash_store_received_amounts';
	private const PAGE          = 'cvd-dinero-mensajeros';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_cvd_messenger_cash_received', array( __CLASS__, 'admin_received' ) );
	}

	/** Lo que el mensajero tiene en la mano de este pedido, por moneda. Solo importes registrados. */
	public static function held( WC_Order $order ): array {
		$method = sanitize_key( (string) $order->get_meta( '_cvd_collection_method', true ) );
		if ( 'transfer' === $method ) { return array(); } // entró a una tarjeta: el mensajero no lo tiene
		$totals = array();
		if ( 'allocated' === $method && class_exists( 'CVD_Payment_Obligations' ) && CVD_Payment_Obligations::for_order( $order ) ) {
			foreach ( CVD_Payment_Obligations::for_order( $order ) as $row ) {
				if ( 'customer' !== ( $row['payer'] ?? '' ) || 'settled' !== ( $row['status'] ?? '' ) ) { continue; }
				if ( in_array( (string) ( $row['method'] ?? '' ), array( 'transfer', 'commission_deduction' ), true ) ) { continue; }
				$currency = strtoupper( (string) ( $row['currency'] ?? '' ) );
				$totals[ $currency ] = ( $totals[ $currency ] ?? 0 ) + (float) ( $row['amount'] ?? 0 );
			}
		} else {
			$usd = (string) $order->get_meta( '_cvd_collection_amount_usd', true );
			$cup = (string) $order->get_meta( '_cvd_collection_amount_cup', true );
			if ( '' === $usd && '' === $cup ) { // solo lo que declaró por WhatsApp
				$usd = (string) $order->get_meta( '_cvd_declared_usd', true );
				$cup = (string) $order->get_meta( '_cvd_declared_cup', true );
			}
			$totals = array( 'USD' => (float) $usd, 'CUP' => (float) $cup );
		}
		$list = array();
		foreach ( $totals as $currency => $amount ) {
			if ( $amount > 0.009 ) { $list[] = array( 'amount' => round( $amount, 2 ), 'currency' => $currency ); }
		}
		return $list;
	}

	/** Pedidos con dinero en manos de un mensajero (entregados, dinero sin devolver, sin «Recibido»). */
	public static function pending(): array {
		$orders = wc_get_orders( array( 'limit' => 300, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC',
			'status' => array( 'pending', 'processing', 'on-hold' ), 'meta_key' => '_cvd_cash_status', 'meta_value' => 'pending_return' ) );
		$rows = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
			if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) || CVD_Delivery::is_test_order( $order ) ) { continue; }
			if ( 'delivered' !== CVD_Delivery::status( $order ) || '' !== (string) $order->get_meta( self::RECEIVED_AT, true ) ) { continue; }
			$amounts = self::held( $order );
			if ( ! $amounts ) { continue; }
			$rows[] = self::row( $order, $amounts );
		}
		return $rows;
	}

	/** Lo ya recibido en los últimos días (para revisar quién lo marcó). */
	public static function received( int $days = 7 ): array {
		$orders = wc_get_orders( array( 'limit' => 100, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC',
			'date_modified' => '>=' . ( time() - max( 1, $days ) * DAY_IN_SECONDS ),
			'meta_query' => array( array( 'key' => self::RECEIVED_AT, 'compare' => 'EXISTS' ) ) ) );
		$rows = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( self::RECEIVED_AT, true ) ) { continue; }
			$saved = json_decode( (string) $order->get_meta( self::RECEIVED_AMTS, true ), true );
			$row = self::row( $order, is_array( $saved ) ? $saved : self::held( $order ) );
			$at = (string) $order->get_meta( self::RECEIVED_AT, true );
			$row['receivedAt'] = $at;
			$row['receivedAtLocal'] = self::cuba_time( $at, 'd/m H:i' );
			$row['receivedBy'] = (string) $order->get_meta( self::RECEIVED_NAME, true );
			$rows[] = $row;
		}
		return $rows;
	}

	/** Todo junto: lista, totales por mensajero y lo recibido hace poco. */
	public static function report(): array {
		$pending = self::pending();
		$by = array();
		foreach ( $pending as $row ) {
			$key = (string) $row['messengerId'];
			$by[ $key ] = $by[ $key ] ?? array( 'messengerId' => $row['messengerId'], 'messengerName' => $row['messengerName'], 'totals' => array(), 'orders' => array(), 'oldest' => $row['since'] );
			foreach ( $row['amounts'] as $a ) { $by[ $key ]['totals'][ $a['currency'] ] = round( ( $by[ $key ]['totals'][ $a['currency'] ] ?? 0 ) + $a['amount'], 2 ); }
			$by[ $key ]['orders'][] = $row['orderId'];
			if ( $row['since'] && $row['since'] < $by[ $key ]['oldest'] ) { $by[ $key ]['oldest'] = $row['since']; }
		}
		$totals = array();
		foreach ( $pending as $row ) { foreach ( $row['amounts'] as $a ) { $totals[ $a['currency'] ] = round( ( $totals[ $a['currency'] ] ?? 0 ) + $a['amount'], 2 ); } }
		return array( 'pending' => $pending, 'byMessenger' => array_values( $by ), 'totals' => $totals, 'received' => self::received(), 'serverTime' => gmdate( 'Y-m-d H:i:s' ) );
	}

	private static function row( WC_Order $order, array $amounts ): array {
		$messenger_id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$messenger = $messenger_id ? get_userdata( $messenger_id ) : false;
		$since = (string) ( $order->get_meta( '_cvd_delivered_at', true ) ?: $order->get_meta( '_cvd_collection_received_at', true ) );
		$declared = (string) $order->get_meta( '_cvd_cash_handover_declared_at', true );
		return array(
			'orderId'          => $order->get_id(),
			'number'           => $order->get_order_number(),
			'messengerId'      => $messenger_id,
			'messengerName'    => $messenger ? $messenger->display_name : 'Sin mensajero',
			'amounts'          => array_values( $amounts ),
			'label'            => self::amounts_label( $amounts ),
			'method'           => sanitize_key( (string) $order->get_meta( '_cvd_collection_method', true ) ),
			'since'            => $since, // 'Y-m-d H:i:s' UTC
			'sinceLocal'       => $since ? self::cuba_time( $since, 'd/m H:i' ) : '',
			'hours'            => $since ? max( 0, (int) floor( ( time() - strtotime( $since . ' UTC' ) ) / HOUR_IN_SECONDS ) ) : null,
			'declaredHandover' => $declared ? self::cuba_time( $declared, 'd/m H:i' ) : '', // el mensajero dijo «Entregué el dinero»
			'customerFirst'    => trim( (string) $order->get_shipping_first_name() ) ?: trim( (string) $order->get_billing_first_name() ),
		);
	}

	/** La web está en UTC: las horas de esta lista se enseñan siempre en hora de Cuba. */
	public static function cuba_time( string $utc, string $format = 'd/m H:i' ): string {
		if ( '' === $utc ) { return ''; }
		try { return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( $format ); }
		catch ( Throwable $e ) { return $utc; }
	}

	public static function amounts_label( array $amounts ): string {
		$parts = array();
		foreach ( $amounts as $a ) {
			$amount = (float) ( $a['amount'] ?? 0 );
			$parts[] = number_format_i18n( $amount, abs( $amount - round( $amount ) ) > 0.009 ? 2 : 0 ) . ' ' . strtoupper( (string) ( $a['currency'] ?? '' ) );
		}
		return implode( ' + ', $parts );
	}

	/**
	 * «Recibido»: la tienda ya tiene el dinero de este pedido. Guarda quién (usuario + nombre que firma) y cuándo.
	 * No cambia el estado del pedido ni del dinero. Repetirlo no hace nada.
	 */
	public static function mark_received( int $order_id, int $actor_id, string $by_name = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return new WP_Error( 'cvd_cash_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		if ( '' !== (string) $order->get_meta( self::RECEIVED_AT, true ) ) { return array( 'ok' => true, 'already' => true, 'receivedBy' => (string) $order->get_meta( self::RECEIVED_NAME, true ) ); }
		if ( 'delivered' !== CVD_Delivery::status( $order ) || 'pending_return' !== sanitize_key( (string) $order->get_meta( '_cvd_cash_status', true ) ) ) {
			return new WP_Error( 'cvd_cash_state', 'Este pedido no tiene dinero pendiente de un mensajero.', array( 'status' => 409 ) );
		}
		$amounts = self::held( $order );
		if ( ! $amounts ) { return new WP_Error( 'cvd_cash_none', 'No hay importe en efectivo registrado para este pedido.', array( 'status' => 409 ) ); }
		$actor = $actor_id ? get_userdata( $actor_id ) : false;
		$name = trim( sanitize_text_field( $by_name ) );
		$name = '' !== $name ? mb_substr( $name, 0, 80 ) : ( $actor ? $actor->display_name : 'la tienda' );
		$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		$at = current_time( 'mysql', true );
		$order->update_meta_data( self::RECEIVED_AT, $at );
		$order->update_meta_data( self::RECEIVED_BY, $actor_id );
		$order->update_meta_data( self::RECEIVED_NAME, $name );
		$order->update_meta_data( self::RECEIVED_AMTS, wp_json_encode( $amounts ) );
		$order->add_order_note( sprintf( 'Dinero recibido en la tienda: %s entregó %s. Lo anotó %s en «Dinero por entregar». (Cerrar el pedido y aprobar comisiones sigue siendo «Registrar dinero recibido» en Ventas.)',
			$messenger ? $messenger->display_name : 'el mensajero', self::amounts_label( $amounts ), $name ) );
		$order->save();
		return array( 'ok' => true, 'already' => false, 'receivedAt' => $at, 'receivedBy' => $name, 'label' => self::amounts_label( $amounts ) );
	}

	public static function received_at( WC_Order $order ): string {
		return (string) $order->get_meta( self::RECEIVED_AT, true );
	}

	/* ---------- wp-admin: Casa Viva → Dinero por entregar ---------- */

	public static function menu(): void {
		$count = count( self::pending() );
		add_submenu_page( 'cvd-gestoras', 'Dinero por entregar a la tienda', 'Dinero por entregar' . ( $count ? ' <span class="awaiting-mod">' . $count . '</span>' : '' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function admin_received(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.', 403 ); }
		check_admin_referer( 'cvd_messenger_cash_received_' . $order_id );
		$result = self::mark_received( $order_id, get_current_user_id() );
		$flag = is_wp_error( $result ) ? 'error' : ( ! empty( $result['already'] ) ? 'ya' : 'ok' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'recibido' => $flag, 'pedido' => $order_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$report = self::report();
		echo '<div class="wrap"><h1>Dinero por entregar a la tienda</h1>';
		$flag = sanitize_key( (string) ( $_GET['recibido'] ?? '' ) );
		$order_id = absint( $_GET['pedido'] ?? 0 );
		if ( 'ok' === $flag ) { echo '<div class="notice notice-success"><p>Anotado: el dinero del pedido #' . esc_html( $order_id ) . ' ya está en la tienda.</p></div>'; }
		if ( 'ya' === $flag ) { echo '<div class="notice notice-info"><p>El pedido #' . esc_html( $order_id ) . ' ya estaba anotado como recibido.</p></div>'; }
		if ( 'error' === $flag ) { echo '<div class="notice notice-error"><p>No se pudo anotar el pedido #' . esc_html( $order_id ) . ' (ya no tiene dinero pendiente de un mensajero).</p></div>'; }
		echo '<p>Sale solo de lo que se registró al entregar (WhatsApp o área del mensajero). Lo cobrado por transferencia no aparece. «Recibido» anota quién lo recibió y cuándo; para cerrar el pedido sigue usándose «Registrar dinero recibido» en Ventas.</p>';
		if ( ! $report['pending'] ) {
			echo '<p><strong>Ningún mensajero tiene dinero de la tienda ahora mismo.</strong></p>';
		} else {
			echo '<h2>Total por mensajero</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>Mensajero</th><th>Tiene</th><th>Pedidos</th><th>Desde</th></tr></thead><tbody>';
			foreach ( $report['byMessenger'] as $m ) {
				$amounts = array(); foreach ( $m['totals'] as $currency => $amount ) { $amounts[] = array( 'amount' => $amount, 'currency' => $currency ); }
				echo '<tr><td>' . esc_html( $m['messengerName'] ) . '</td><td><strong>' . esc_html( self::amounts_label( $amounts ) ) . '</strong></td><td>' . esc_html( '#' . implode( ', #', $m['orders'] ) ) . '</td><td>' . esc_html( $m['oldest'] ? self::cuba_time( $m['oldest'], 'd/m H:i' ) : '' ) . '</td></tr>';
			}
			echo '</tbody></table><h2>Pedido por pedido</h2><table class="widefat striped"><thead><tr><th>Mensajero</th><th>Pedido</th><th>Cantidad</th><th>Desde</th><th>Aviso del mensajero</th><th></th></tr></thead><tbody>';
			foreach ( $report['pending'] as $row ) {
				$edit = admin_url( 'post.php?post=' . $row['orderId'] . '&action=edit' );
				if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { $edit = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $row['orderId'] ); }
				echo '<tr><td>' . esc_html( $row['messengerName'] ) . '</td><td><a href="' . esc_url( $edit ) . '">#' . esc_html( $row['number'] ) . '</a> ' . esc_html( $row['customerFirst'] ) . '</td><td><strong>' . esc_html( $row['label'] ) . '</strong>' . ( 'mixed' === $row['method'] ? ' <small>(mixto)</small>' : '' ) . '</td><td>' . esc_html( $row['sinceLocal'] ) . ( null !== $row['hours'] ? ' <small>(' . esc_html( $row['hours'] ) . ' h)</small>' : '' ) . '</td><td>' . esc_html( $row['declaredHandover'] ? 'Dice que lo entregó ' . $row['declaredHandover'] : '' ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Ya tienes en la tienda ' . esc_js( $row['label'] ) . ' de ' . esc_js( $row['messengerName'] ) . '?\')"><input type="hidden" name="action" value="cvd_messenger_cash_received"><input type="hidden" name="order_id" value="' . esc_attr( $row['orderId'] ) . '">' . wp_nonce_field( 'cvd_messenger_cash_received_' . $row['orderId'], '_wpnonce', true, false ) . '<button class="button button-primary" type="submit">Recibido</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $report['received'] ) {
			echo '<h2>Recibido (últimos 7 días)</h2><table class="widefat striped"><thead><tr><th>Mensajero</th><th>Pedido</th><th>Cantidad</th><th>Recibió</th><th>Cuándo</th></tr></thead><tbody>';
			foreach ( $report['received'] as $row ) {
				echo '<tr><td>' . esc_html( $row['messengerName'] ) . '</td><td>#' . esc_html( $row['number'] ) . '</td><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html( $row['receivedBy'] ) . '</td><td>' . esc_html( $row['receivedAtLocal'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
