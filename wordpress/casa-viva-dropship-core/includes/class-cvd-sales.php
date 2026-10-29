<?php

defined( 'ABSPATH' ) || exit;

/** Flujo operativo de ventas. WooCommerce sigue siendo la única fuente de verdad. */
final class CVD_Sales {
	private const STATUS_META = '_cvd_operation_status';
	private const STATUSES = array( 'new', 'confirmed', 'preparing', 'ready', 'with_courier', 'delivered', 'incident', 'cancelled' );
	private const LIST_STATUSES = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
	private const OPEN_STATUSES = array( 'pending', 'processing', 'on-hold' ); // por entregar o por cobrar: salen siempre
	private const SEARCH_LIMIT = 40;

	public static function register(): void {
		add_shortcode( 'casa_viva_sales', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'initialize_order' ), 50 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'initialize_order' ), 50 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled' ), 50 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_cancelled' ), 50 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'sync_cancelled' ), 50 );
	}

	public static function can_view(): bool {
		return current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' );
	}

	// ---------- Tarjetas de la tienda (3.13.50) ----------
	// Lennys rota varias tarjetas CUP al día: al cerrar un cobro por transferencia se apunta a cuál entró
	// y el número de la transferencia, para cuadrar luego con cada banco. Solo se guarda un nombre
	// y los ÚLTIMOS 4 dígitos (nunca el número completo). No se borran: se desactivan, así los pedidos
	// viejos siguen diciendo a qué tarjeta entró su dinero.
	private const ACCOUNTS_OPTION = 'cvd_payment_accounts';
	/** Cuentas fijas que no son tarjeta: Zelle y USDT (dólares) y «Otra». */
	private const FIXED_ACCOUNTS = array( 'zelle' => 'Zelle', 'usdt' => 'USDT', 'other' => 'Otra cuenta' );

	/** Todas las tarjetas guardadas (o solo las activas): id, label, last4, active. */
	public static function payment_accounts( bool $only_active = false ): array {
		$saved = get_option( self::ACCOUNTS_OPTION, false );
		if ( false === $saved ) { $saved = self::seed_payment_accounts(); }
		$out = array();
		foreach ( is_array( $saved ) ? $saved : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) { continue; }
			$card = array( 'id' => sanitize_key( (string) $row['id'] ), 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 'last4' => preg_replace( '/\D/', '', (string) ( $row['last4'] ?? '' ) ), 'active' => ! empty( $row['active'] ) );
			if ( $only_active && ! $card['active'] ) { continue; }
			$card['name'] = self::card_name( $card );
			$out[] = $card;
		}
		return $out;
	}

	/**
	 * Primera lectura (la opción aún no existe): las tarjetas que mandó Lennys el 11/10/2026, todas activas.
	 * Solo nombre + últimos 4. Si la opción ya existe (aunque la dueña lo haya desactivado todo) no se toca.
	 */
	private static function seed_payment_accounts(): array {
		$seed = array( array( 'MLC', '1884' ), array( 'MN BPA', '2677' ), array( 'MN Metro', '9359' ), array( 'TCP Bandec', '5538' ), array( 'Clásica', '2690' ), array( 'Marita Metro', '4223' ) );
		$at = current_time( 'mysql', true );
		$accounts = array();
		foreach ( $seed as $i => $card ) { $accounts[] = array( 'id' => 'seed' . ( $i + 1 ), 'label' => $card[0], 'last4' => $card[1], 'active' => true, 'created_at' => $at ); }
		add_option( self::ACCOUNTS_OPTION, $accounts, '', false ); // add_option no pisa si otra petición la creó a la vez
		$saved = get_option( self::ACCOUNTS_OPTION, $accounts );
		return is_array( $saved ) ? $saved : $accounts;
	}

	/** «MN BPA ·2677»: nombre + últimos 4 (si el nombre ya los lleva, no se repiten). */
	private static function card_name( array $card ): string {
		$label = trim( (string) $card['label'] );
		return '' !== $card['last4'] && ! str_ends_with( $label, $card['last4'] ) ? trim( $label . ' ·' . $card['last4'] ) : $label;
	}

	/**
	 * Lo que sale en «¿A qué tarjeta entró?»: tarjetas activas, Zelle, USDT y «Otra tarjeta…» (typed: se escribe
	 * ahí mismo nombre + últimos 4). «Otra cuenta» (other) se sigue aceptando pero ya no se ofrece.
	 */
	public static function account_choices(): array {
		$choices = array_map( static fn( array $c ): array => array( 'id' => $c['id'], 'name' => $c['name'] ), self::payment_accounts( true ) );
		$choices[] = array( 'id' => 'zelle', 'name' => 'Zelle' );
		$choices[] = array( 'id' => 'usdt', 'name' => 'USDT' );
		$choices[] = array( 'id' => 'typed', 'name' => 'Otra tarjeta… (escribirla)' );
		return $choices;
	}

	/** Nombre de una cuenta (también de una tarjeta ya desactivada); '' si no existe. */
	public static function account_name( string $id ): string {
		$id = sanitize_key( $id );
		if ( isset( self::FIXED_ACCOUNTS[ $id ] ) ) { return self::FIXED_ACCOUNTS[ $id ]; }
		foreach ( self::payment_accounts() as $card ) { if ( $card['id'] === $id ) { return $card['name']; } }
		return '';
	}

	/** Añade una tarjeta (sin id) o cambia nombre / activa-desactiva una existente (con id). */
	public static function save_payment_account( array $input ) {
		$id = sanitize_key( (string) ( $input['id'] ?? '' ) );
		$label = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $input['label'] ?? '' ) ) ) );
		$last4 = trim( (string) ( $input['last4'] ?? '' ) );
		$active = ! array_key_exists( 'active', $input ) || rest_sanitize_boolean( $input['active'] );
		// Nunca el número completo: si el nombre trae 5 o más cifras seguidas, se rechaza.
		if ( preg_match( '/\d{5,}/', preg_replace( '/[\s.\-]/', '', $label ) ) ) { return new WP_Error( 'cvd_account_number', 'Pon solo un nombre (ej.: BPA de Lennys). El número completo de la tarjeta no se guarda; solo los últimos 4.', array( 'status' => 422 ) ); }
		self::payment_accounts(); // si es la primera vez, siembra antes las tarjetas iniciales
		$accounts = get_option( self::ACCOUNTS_OPTION, array() );
		$accounts = is_array( $accounts ) ? array_values( array_filter( $accounts, 'is_array' ) ) : array();
		if ( '' === $id ) {
			if ( '' === $label || mb_strlen( $label ) > 40 ) { return new WP_Error( 'cvd_account_label', 'Pon un nombre corto para la tarjeta (ej.: BPA, Metropolitano).', array( 'status' => 422 ) ); }
			if ( ! preg_match( '/^\d{4}$/', $last4 ) ) { return new WP_Error( 'cvd_account_last4', 'Escribe solo los últimos 4 números de la tarjeta.', array( 'status' => 422 ) ); }
			if ( count( $accounts ) >= 30 ) { return new WP_Error( 'cvd_account_limit', 'Ya hay 30 tarjetas. Desactiva las que no uses.', array( 'status' => 422 ) ); }
			foreach ( $accounts as $row ) { if ( $last4 === (string) ( $row['last4'] ?? '' ) && 0 === strcasecmp( $label, (string) ( $row['label'] ?? '' ) ) ) { return new WP_Error( 'cvd_account_duplicate', 'Esa tarjeta ya está en la lista.', array( 'status' => 409 ) ); } }
			$accounts[] = array( 'id' => 'c' . strtolower( wp_generate_password( 8, false, false ) ), 'label' => $label, 'last4' => $last4, 'active' => true, 'created_at' => current_time( 'mysql', true ) );
		} else {
			$found = false;
			foreach ( $accounts as &$row ) {
				if ( sanitize_key( (string) ( $row['id'] ?? '' ) ) !== $id ) { continue; }
				$found = true;
				if ( '' !== $label ) { if ( mb_strlen( $label ) > 40 ) { return new WP_Error( 'cvd_account_label', 'El nombre es demasiado largo.', array( 'status' => 422 ) ); } $row['label'] = $label; }
				if ( '' !== $last4 ) { if ( ! preg_match( '/^\d{4}$/', $last4 ) ) { return new WP_Error( 'cvd_account_last4', 'Escribe solo los últimos 4 números de la tarjeta.', array( 'status' => 422 ) ); } $row['last4'] = $last4; }
				$row['active'] = $active;
				$row['updated_at'] = current_time( 'mysql', true );
			}
			unset( $row );
			if ( ! $found ) { return new WP_Error( 'cvd_account_missing', 'No encontré esa tarjeta.', array( 'status' => 404 ) ); }
		}
		update_option( self::ACCOUNTS_OPTION, $accounts, false );
		return self::payment_accounts();
	}

	/**
	 * Deja el cobro en forma estándar. Zelle y USDT son transferencias a una cuenta concreta: el método
	 * sigue siendo 'transfer' (así la caja, los mensajeros y las obligaciones de pago no cambian) y la
	 * cuenta va aparte. La tarjeta solo cuenta en transferencia o mixto; la referencia, también en «otro».
	 * «Otra tarjeta…» (account 'typed'): la dependienta escribe nombre + últimos 4 ahí mismo y no se queda
	 * bloqueada. Si coincide con una tarjeta de la lista se usa esa; si marca «Guardar en la lista» y es
	 * administración, se añade a la lista; si no, queda solo en el pedido con account 'typed'.
	 * Devuelve method, account (id), account_name y reference; WP_Error si la tarjeta escrita no vale.
	 */
	public static function normalize_collection( string $method, string $account, string $reference, array $typed = array() ) {
		$method = sanitize_key( $method );
		$account = sanitize_key( $account );
		if ( in_array( $method, array( 'zelle', 'usdt' ), true ) ) { $account = $method; $method = 'transfer'; }
		$reference = mb_substr( trim( sanitize_text_field( $reference ) ), 0, 60 );
		if ( ! in_array( $method, array( 'transfer', 'mixed' ), true ) ) { $account = ''; }
		if ( ! in_array( $method, array( 'transfer', 'mixed', 'other' ), true ) ) { $reference = ''; }
		if ( 'typed' === $account ) {
			$label = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $typed['label'] ?? '' ) ) ) );
			$last4 = trim( (string) ( $typed['last4'] ?? '' ) );
			if ( '' === $label || mb_strlen( $label ) > 40 || ! preg_match( '/^\d{4}$/', $last4 ) ) { return new WP_Error( 'cvd_account_typed', 'En «Otra tarjeta» pon un nombre corto y los últimos 4 números.', array( 'status' => 422 ) ); }
			if ( preg_match( '/\d{5,}/', preg_replace( '/[\s.\-]/', '', $label ) ) ) { return new WP_Error( 'cvd_account_number', 'Pon solo un nombre para la tarjeta; el número completo no se guarda, solo los últimos 4.', array( 'status' => 422 ) ); }
			$match = static function () use ( $label, $last4 ): string {
				foreach ( self::payment_accounts() as $card ) { if ( $card['last4'] === $last4 && 0 === strcasecmp( $card['label'], $label ) ) { return $card['id']; } }
				return '';
			};
			$found = $match();
			if ( '' === $found && ! empty( $typed['save'] ) && current_user_can( 'manage_woocommerce' ) ) {
				$saved = self::save_payment_account( array( 'label' => $label, 'last4' => $last4 ) );
				if ( ! is_wp_error( $saved ) ) { $found = $match(); }
			}
			if ( '' !== $found ) { $account = $found; }
			else { return array( 'method' => $method, 'account' => 'typed', 'account_name' => self::card_name( array( 'label' => $label, 'last4' => $last4 ) ), 'reference' => $reference ); }
		}
		$name = '' !== $account ? self::account_name( $account ) : '';
		if ( '' === $name ) { $account = ''; }
		return array( 'method' => $method, 'account' => $account, 'account_name' => $name, 'reference' => $reference );
	}

	/** Lee del pedido REST lo que se cobró (método, tarjeta, referencia y, si la hay, la «Otra tarjeta» escrita). */
	private static function collection_from_request( WP_REST_Request $request ) {
		return self::normalize_collection(
			(string) $request->get_param( 'collectionMethod' ), (string) $request->get_param( 'collectionAccount' ), (string) $request->get_param( 'collectionReference' ),
			array( 'label' => (string) $request->get_param( 'collectionCardLabel' ), 'last4' => (string) $request->get_param( 'collectionCardLast4' ), 'save' => rest_sanitize_boolean( $request->get_param( 'collectionSaveCard' ) ) )
		);
	}

	/** Texto para la nota del pedido: «Cobro por transferencia a BPA 2690 · ref 12345.» ('' si no hay nada que anotar). */
	public static function collection_note( array $collection ): string {
		if ( ! in_array( $collection['method'] ?? '', array( 'transfer', 'mixed', 'other' ), true ) ) { return ''; }
		if ( '' === ( $collection['account_name'] ?? '' ) && '' === ( $collection['reference'] ?? '' ) ) {
			return 'transfer' === $collection['method'] ? 'Cobro por transferencia sin decir a qué tarjeta entró.' : '';
		}
		$what = array( 'transfer' => 'Cobro por transferencia', 'mixed' => 'Cobro mixto (parte por transferencia)', 'other' => 'Cobro por otro medio' )[ $collection['method'] ];
		return $what . ( '' !== $collection['account_name'] ? ' a ' . $collection['account_name'] : '' ) . ( '' !== $collection['reference'] ? ' · ref ' . $collection['reference'] : '' ) . '.';
	}

	public static function initialize_order( WC_Order $order ): void {
		if ( ! $order->get_meta( self::STATUS_META, true ) ) {
			$order->update_meta_data( self::STATUS_META, 'new' );
			$at = current_time( 'mysql', true );
			$order->update_meta_data( '_cvd_operation_updated_at', $at );
			$order->save();
			do_action( 'cvd_order_transition_observed', $order->get_id(), 'operation', '', 'new', 'cvd_sales_initialize_order', array(), $at );
		}
		if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_new_order( $order ); }
	}

	public static function sync_cancelled( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if(class_exists('CVD_Order_Transition_Service')){CVD_Order_Transition_Service::cancel($order_id,$order->get_status(),array('system'=>true,'source'=>'woocommerce_hook'));}
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/sales', array(
			'methods' => 'GET',
			'callback' => array( __CLASS__, 'orders' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/status', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'change_status' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
		register_rest_route( 'casa-viva/v1', '/sales/(?P<id>\d+)/return', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'register_return' ),
			'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
		) );
	}

	/**
	 * Devolución de un pedido ya cerrado (D30, opción A). Regla: vuelve el stock, se anula la
	 * comisión de la gestora (el cliente no se quedó el producto) y la ganancia del mensajero se
	 * mantiene salvo que administración marque lo contrario. Queda escrito el dinero devuelto.
	 */
	public static function register_return( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return new WP_Error( 'cvd_return_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		if ( ! $order->has_status( 'completed' ) ) { return new WP_Error( 'cvd_return_state', 'Solo se registran devoluciones de pedidos completados.', array( 'status' => 409 ) ); }
		if ( $order->get_meta( '_cvd_return', true ) ) { return new WP_Error( 'cvd_return_done', 'Este pedido ya tiene una devolución registrada.', array( 'status' => 409 ) ); }
		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		if ( '' === trim( $reason ) ) { return new WP_Error( 'cvd_return_reason', 'Escribe el motivo de la devolución.', array( 'status' => 422 ) ); }
		$usd = (float) wc_format_decimal( $request->get_param( 'refundUsd' ) ?: 0, 2 );
		$cup = (float) wc_format_decimal( $request->get_param( 'refundCup' ) ?: 0, 2 );
		$void_messenger = rest_sanitize_boolean( $request->get_param( 'voidMessenger' ) );
		$actor = wp_get_current_user();
		$messenger_note = 'se mantiene';
		if ( $void_messenger && class_exists( 'CVD_Messenger_Accounting' ) ) {
			if ( ! CVD_Messenger_Accounting::can_void_order( $order ) ) { return new WP_Error( 'cvd_return_paid', 'La ganancia del mensajero ya está liquidada; no se puede anular desde aquí.', array( 'status' => 409 ) ); }
			try { CVD_Messenger_Accounting::void_order_atomic( $order ); } catch ( Throwable $e ) { return new WP_Error( 'cvd_return_ledger', 'No se pudo anular la ganancia del mensajero.', array( 'status' => 409 ) ); }
			$order->update_meta_data( '_cvd_messenger_earning_status', 'cancelled' );
			$messenger_note = 'anulada';
		}
		if ( function_exists( 'wc_increase_stock_levels' ) ) { wc_increase_stock_levels( $order ); }
		// La comisión se anula aquí mismo: el paso a "reembolsado" no la toca en un pedido ya cerrado.
		if ( 'paid' === sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ) {
			$order->add_order_note( 'Devolución: la comisión de quien vendió ya estaba pagada; descontarla en su próximo pago.' );
		} elseif ( class_exists( 'CVD_Commissions' ) ) {
			CVD_Commissions::cancel_for_order( $order, $actor->ID, current_time( 'mysql', true ), 'return:' . $order->get_id() );
		}
		$order->update_meta_data( '_cvd_return', array( 'at' => current_time( 'mysql', true ), 'by' => $actor->ID, 'refund_usd' => $usd, 'refund_cup' => $cup, 'reason' => $reason, 'messenger_earning' => $messenger_note ) );
		$order->update_meta_data( '_cvd_return_in_progress', 'yes' ); // deja pasar el freno de D30 solo para esta devolución
		$order->add_order_note( sprintf( 'Devolución registrada por %s: se devolvió al cliente %s USD + %s CUP. Motivo: %s. Stock repuesto; comisión de quien vendió anulada; ganancia del mensajero %s.', $actor->display_name, $usd, $cup, $reason, $messenger_note ) );
		$order->save();
		$order->update_status( 'refunded', 'Devolución registrada en el Centro de ventas.' );
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( '_cvd_return_in_progress' );
		$order->save();
		return rest_ensure_response( array( 'message' => 'Devolución registrada.', 'order' => self::payload( $order ) ) );
	}

	public static function orders( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		// 3.14.24 (Nana no encontró el #2963, 16-oct): la lista trae los 50 más recientes MÁS todos los
		// abiertos (por entregar o por cobrar) aunque sean viejos; al buscar, también busca en el servidor
		// en TODOS los pedidos (número, nombre, teléfono, correo, producto, gestora).
		$args = array(
			'limit' => 50,
			'type' => 'shop_order',
			'orderby' => 'date',
			'order' => 'DESC',
			'status' => self::LIST_STATUSES,
		);
		$by_id = array();
		foreach ( wc_get_orders( $args ) as $order ) { $by_id[ $order->get_id() ] = $order; }
		foreach ( wc_get_orders( array_merge( $args, array( 'limit' => 200, 'status' => self::OPEN_STATUSES ) ) ) as $order ) { $by_id[ $order->get_id() ] = $order; }
		$found = array();
		if ( '' !== $search ) {
			foreach ( $by_id as $id => $order ) { if ( ! self::matches_search( $order, $search ) ) { unset( $by_id[ $id ] ); } }
			$found = self::search_all( $search );
			foreach ( $found as $id ) {
				if ( isset( $by_id[ $id ] ) ) { continue; }
				$order = wc_get_order( $id );
				if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && 'shop_order' === $order->get_type() && in_array( $order->get_status(), self::LIST_STATUSES, true ) ) { $by_id[ $id ] = $order; }
			}
		}
		$orders = array_values( $by_id );
		usort( $orders, static function ( WC_Order $a, WC_Order $b ): int {
			$ta = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
			$tb = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
			return $tb <=> $ta ?: $b->get_id() <=> $a->get_id();
		} );
		$items = array();
		$summary = array_fill_keys( self::STATUSES, 0 );
		foreach ( $orders as $order ) {
			$operation_status = self::operation_status( $order );
			$summary[ $operation_status ]++;
			if ( $status && in_array( $status, self::STATUSES, true ) && $status !== $operation_status ) { continue; }
			$items[] = self::payload( $order );
		}
		return rest_ensure_response( array( 'summary' => $summary, 'orders' => $items ) );
	}

	private static function matches_search( WC_Order $order, string $search ): bool {
		// 3.13.78: también por el nombre de la gestora («Oliuska» encuentra sus pedidos).
		$haystack = array( $order->get_order_number(), $order->get_formatted_billing_full_name(), $order->get_billing_phone(), $order->get_billing_email(), (string) $order->get_meta( 'gestora_nombre', true ) );
		foreach ( $order->get_items( 'line_item' ) as $item ) { $haystack[] = $item->get_name(); }
		return false !== stripos( implode( ' ', $haystack ), $search );
	}

	/**
	 * 3.14.24: IDs de pedidos que casan con la búsqueda en TODA la tienda (no solo los 50 últimos).
	 * Solo lectura; como mucho SEARCH_LIMIT por vía para que la pantalla (refresca cada 8 s) siga rápida.
	 */
	private static function search_all( string $search ): array {
		$search = trim( $search );
		if ( '' === $search ) { return array(); }
		$ids = array();
		$base = array( 'limit' => self::SEARCH_LIMIT, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => self::LIST_STATUSES, 'return' => 'ids' );
		// «2963», «#2963» o «CV-PEDIDO-2963» (lo que sale en el vale): el pedido directo.
		if ( preg_match( '/^(?:#|cv-pedido-)?\s*(\d{1,9})$/i', $search, $m ) ) { $ids[] = (int) $m[1]; }
		// Búsqueda de WooCommerce: número, nombre, correo, teléfono tal como se guardó y productos.
		$hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		try {
			$ids = array_merge( $ids, $hpos ? (array) wc_get_orders( array_merge( $base, array( 's' => $search ) ) ) : array_slice( array_reverse( (array) wc_order_search( $search ) ), 0, self::SEARCH_LIMIT ) );
		} catch ( Throwable $e ) { unset( $e ); }
		// La gestora del pedido («Rafael» encuentra los pedidos de su gestora).
		if ( strlen( $search ) >= 3 ) {
			$by_gestora = (array) wc_get_orders( array_merge( $base, array( 'meta_query' => array( array( 'key' => 'gestora_nombre', 'value' => $search, 'compare' => 'LIKE' ) ) ) ) );
			// 3.14.69: WooCommerce 8.2 sin HPOS ignora este meta_query y devolvía los últimos pedidos sin filtrar
			// (lo destapó la prueba 5A). Se comprueba la gestora de cada uno, como en CVD_Attribution::find_existing_owner.
			$ids = array_merge( $ids, array_filter( $by_gestora, static function ( $id ) use ( $search ): bool {
				$order = wc_get_order( (int) $id );
				return $order instanceof WC_Order && false !== stripos( (string) $order->get_meta( 'gestora_nombre', true ), $search );
			} ) );
		}
		// Teléfono escrito sin espacios («53388455» encuentra «+53 5 3388455»): últimos 8 dígitos.
		$digits = preg_replace( '/\D+/', '', $search );
		if ( strlen( $digits ) >= 6 && strlen( $digits ) >= strlen( preg_replace( '/[\s+()\-]+/', '', $search ) ) && $hpos ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wc_order_addresses';
			$like = '%' . $wpdb->esc_like( substr( $digits, -8 ) ) . '%';
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-',''),'(',''),')',''),'.','') LIKE %s ORDER BY order_id DESC LIMIT %d", $like, self::SEARCH_LIMIT ) );
			$ids = array_merge( $ids, array_map( 'intval', (array) $rows ) );
		}
		return array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::SEARCH_LIMIT * 2 );
	}

	public static function change_status( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		$next = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! $order || ( ! in_array( $next, self::STATUSES, true ) && 'picked_up' !== $next ) ) {
			return new WP_Error( 'cvd_invalid_sale', 'Pedido o estado no válido.', array( 'status' => 422 ) );
		}
		if ( 'picked_up' === $next ) {
			if ( ! class_exists( 'CVD_Delivery' ) || ! CVD_Delivery::handover_by_staff( $order, get_current_user_id(), 'sales_dashboard' ) ) {
				return new WP_Error( 'cvd_invalid_handover', 'El mensajero todavía no está listo para recibir este pedido.', array( 'status' => 409 ) );
			}
			return rest_ensure_response( array( 'message' => 'Pedido entregado al mensajero.', 'order' => self::payload( wc_get_order( $order->get_id() ) ) ) );
		}
		// 3.13.97 (Ernesto, 14-oct): cancelar con motivo, también las dependientas (reglas en CVD_Order_Edit::cancel_block).
		if ( 'cancelled' === $next && class_exists( 'CVD_Order_Edit' ) ) {
			$via_panel = class_exists( 'CVD_Panel_Bridge' ) && CVD_Panel_Bridge::via_panel();
			$result = CVD_Order_Edit::cancel( $order->get_id(), (string) $request->get_param( 'reason' ), get_current_user_id(), $via_panel ? 'panel' : 'ventas', (bool) $request->get_param( 'confirmReserva' ) );
			if ( is_wp_error( $result ) ) { return $result; }
			return rest_ensure_response( array( 'message' => $result['message'], 'messenger' => $result['messenger'], 'order' => self::payload( wc_get_order( $order->get_id() ) ) ) );
		}
		$current = self::operation_status( $order );
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		if ( 'pickup' === $fulfillment && 'ready' === $current && 'delivered' === $next && class_exists( 'CVD_Order_Transition_Service' ) ) {
			$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotencyKey' ) ) );
			$collection = self::collection_from_request( $request );
			if ( is_wp_error( $collection ) ) { return $collection; }
			$result = CVD_Order_Transition_Service::complete_pickup( $order->get_id(), array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $idempotency_key,
				'collection_method' => $collection['method'],
				// A qué tarjeta entró y su número de transferencia (3.13.50); el servicio lo guarda en la misma transacción.
				'collection_account' => $collection['account'],
				'collection_account_name' => $collection['account_name'],
				'collection_reference' => $collection['reference'],
				'collection_account_note' => self::collection_note( $collection ),
				'collected_usd' => $request->get_param( 'collectedUsd' ),
				'collected_cup' => $request->get_param( 'collectedCup' ),
				'collection_note' => sanitize_textarea_field( (string) $request->get_param( 'collectionNote' ) ),
				'money_confirmed' => rest_sanitize_boolean( $request->get_param( 'moneyConfirmed' ) ),
				'handover_confirmed' => rest_sanitize_boolean( $request->get_param( 'handoverConfirmed' ) ),
			) );
			if ( empty( $result['success'] ) ) {
				$status = CVD_Order_Transition_Service::UNAUTHORIZED === $result['error_code'] ? 403 : ( CVD_Order_Transition_Service::ORDER_NOT_FOUND === $result['error_code'] ? 404 : ( CVD_Order_Transition_Service::PRECONDITION_FAILED === $result['error_code'] ? 422 : 409 ) );
				return new WP_Error( 'cvd_pickup_' . strtolower( $result['error_code'] ), 'No se pudo completar la recogida. Confirma entrega física y cobro.', array( 'status' => $status, 'transition' => $result ) );
			}
			return rest_ensure_response( array( 'message' => 'Recogida completada.', 'order' => self::payload( wc_get_order( $order->get_id() ) ), 'transition' => $result ) );
		}
		if(class_exists('CVD_Order_Transition_Service')&&('incident'===$next||'incident'===$current)){
			$key=sanitize_text_field((string)($request->get_header('X-CVD-Idempotency-Key')?:$request->get_param('idempotencyKey')));$result='incident'===$next?CVD_Order_Transition_Service::open_incident($order->get_id(),'operation',array('actor_user_id'=>get_current_user_id(),'idempotency_key'=>$key,'note'=>sanitize_textarea_field((string)$request->get_param('note')))):CVD_Order_Transition_Service::resolve_incident($order->get_id(),'operation',array('actor_user_id'=>get_current_user_id(),'idempotency_key'=>$key,'note'=>sanitize_textarea_field((string)$request->get_param('note'))));
			if(empty($result['success'])||('incident'===$current&&$next!==$result['new_state'])){return new WP_Error('cvd_incident_conflict','No se pudo actualizar la incidencia sin inventar una etapa.',array('status'=>409,'transition'=>$result));}return rest_ensure_response(array('message'=>'Pedido actualizado.','order'=>self::payload(wc_get_order($order->get_id())),'transition'=>$result));
		}
		// Botón único "Listo para salir" (D29): desde Nuevo/Confirmado pasa por Preparando y luego Listo,
		// para que Core conserve las dos etapas en su historial sin pedir dos toques a la tienda.
		if ( 'ready' === $next && in_array( $current, array( 'new', 'confirmed' ), true ) && class_exists( 'CVD_Order_Transition_Service' ) && CVD_Order_Transition_Service::governs( 'operation', $current, 'preparing' ) ) {
			$step = CVD_Order_Transition_Service::transition( $order->get_id(), 'operation', 'preparing', array( 'actor_user_id' => get_current_user_id(), 'idempotency_key' => 'one-tap-preparing:' . $order->get_id(), 'source' => 'cvd_sales_one_tap_ready' ) );
			if ( empty( $step['success'] ) ) { return new WP_Error( 'cvd_transition_' . strtolower( $step['error_code'] ), 'No se pudo preparar el pedido.', array( 'status' => 409, 'transition' => $step ) ); }
			$order = wc_get_order( $order->get_id() );
			$current = 'preparing';
		}
		if ( class_exists( 'CVD_Order_Transition_Service' ) && CVD_Order_Transition_Service::governs( 'operation', $current, $next ) ) {
			$idempotency_key = sanitize_text_field( (string) ( $request->get_header( 'X-CVD-Idempotency-Key' ) ?: $request->get_param( 'idempotencyKey' ) ) );
			$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'operation', $next, array(
				'actor_user_id' => get_current_user_id(),
				'idempotency_key' => $idempotency_key,
				'source' => 'cvd_sales_change_status',
			) );
			if ( empty( $result['success'] ) ) {
				$status = in_array( $result['error_code'], array( CVD_Order_Transition_Service::UNAUTHORIZED ), true ) ? 403 : ( CVD_Order_Transition_Service::ORDER_NOT_FOUND === $result['error_code'] ? 404 : 409 );
				return new WP_Error( 'cvd_transition_' . strtolower( $result['error_code'] ), 'No se pudo actualizar el pedido.', array( 'status' => $status, 'transition' => $result ) );
			}
			if ( 'ready' === $next && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) && class_exists( 'CVD_Delivery' ) ) {
				CVD_Delivery::dispatch_ready( wc_get_order( $order->get_id() ) ); // directo al mensajero que eligió la gestora, o al grupo como siempre
			}
			return rest_ensure_response( array( 'message' => 'Pedido actualizado.', 'notice' => self::test_notice( $order, $next ), 'order' => self::payload( wc_get_order( $order->get_id() ) ), 'transition' => $result ) );
		}
		$allowed = self::allowed_transitions( $current );
		if ( ! in_array( $next, $allowed, true ) ) {
			return new WP_Error( 'cvd_invalid_transition', 'Ese cambio de estado no está permitido.', array( 'status' => 409 ) );
		}
		if ( 'cancelled' === $next ) { return new WP_Error( 'cvd_cancellation_failed', 'No se pudo cancelar el pedido.', array( 'status' => 409 ) ); } // se cancela arriba (CVD_Order_Edit)
		// 3.14.75 (Ernesto 28-oct): dinero de una entrega de otro día → «¿Lo recibiste hoy?» antes de guardar nada.
		$cash_day = array();
		if ( 'delivered' === $next && class_exists( 'CVD_Cash_Day' ) ) {
			$cash_day = CVD_Cash_Day::check( $order, $request, get_current_user_id() );
			if ( is_wp_error( $cash_day ) ) { return $cash_day; }
		}
		if ( 'delivered' === $next ) {
			// Zelle / USDT llegan como su propio método y se guardan como 'transfer' + cuenta (ver normalize_collection).
			$collection = self::collection_from_request( $request );
			if ( is_wp_error( $collection ) ) { return $collection; }
			$payment_method = $collection['method'];
			if ( ! in_array( $payment_method, array( 'cash_usd', 'cash_cup', 'transfer', 'mixed', 'other' ), true ) || ! rest_sanitize_boolean( $request->get_param( 'moneyConfirmed' ) ) ) {
				return new WP_Error( 'cvd_money_confirmation', 'Confirma cómo se recibió el dinero antes de completar.', array( 'status' => 422 ) );
			}
			$order->update_meta_data( '_cvd_collection_method', $payment_method );
			// A qué tarjeta entró y su número de transferencia (el nombre se copia: si luego se renombra la tarjeta, el pedido no cambia).
			$order->update_meta_data( '_cvd_collection_account', $collection['account'] );
			$order->update_meta_data( '_cvd_collection_account_name', $collection['account_name'] );
			$order->update_meta_data( '_cvd_collection_reference', $collection['reference'] );
			$account_note = self::collection_note( $collection );
			if ( '' !== $account_note ) { $order->add_order_note( $account_note ); }
			$order->update_meta_data( '_cvd_collection_amount_usd', wc_format_decimal( $request->get_param( 'collectedUsd' ) ?: 0, 2 ) );
			$order->update_meta_data( '_cvd_collection_amount_cup', wc_format_decimal( $request->get_param( 'collectedCup' ) ?: 0, 2 ) );
			$order->update_meta_data( '_cvd_collection_note', sanitize_textarea_field( (string) $request->get_param( 'collectionNote' ) ) );
			$order->update_meta_data( '_cvd_collection_received_by', get_current_user_id() );
			$order->update_meta_data( '_cvd_collection_received_at', current_time( 'mysql', true ) );
			if ( class_exists( 'CVD_Cash_Day' ) ) { CVD_Cash_Day::record( $order, $cash_day, get_current_user_id() ); }
			// ¿Cuadra con lo que declaró el mensajero por WhatsApp? Si no, queda escrito en el pedido.
			$declared_usd = (string) $order->get_meta( '_cvd_declared_usd', true );
			$declared_cup = (string) $order->get_meta( '_cvd_declared_cup', true );
			// Zelle/PayPal (3.13.67): el mensajero declara 0 (no cobró productos) y la tienda registra el pago adelantado: no es descuadre.
			$prepaid = class_exists( 'CVD_Cuban_Checkout' ) && CVD_Cuban_Checkout::is_prepaid( $order );
			if ( ( '' !== $declared_usd || '' !== $declared_cup ) && ! $prepaid ) {
				$got_usd = (float) wc_format_decimal( $request->get_param( 'collectedUsd' ) ?: 0, 2 );
				$got_cup = (float) wc_format_decimal( $request->get_param( 'collectedCup' ) ?: 0, 2 );
				if ( abs( $got_usd - (float) $declared_usd ) > 0.009 || abs( $got_cup - (float) $declared_cup ) > 0.009 ) {
					$order->add_order_note( sprintf( 'Caja NO cuadra: el mensajero declaró %s USD + %s CUP y la tienda registró %s USD + %s CUP.', $declared_usd ?: '0', $declared_cup ?: '0', $got_usd, $got_cup ) );
				}
			}
		}

		$order->update_meta_data( self::STATUS_META, $next );
		$order->update_meta_data( '_cvd_operation_updated_at', current_time( 'mysql', true ) );
		$history = $order->get_meta( '_cvd_operation_history', true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array( 'from' => $current, 'to' => $next, 'user_id' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'event_anchor' => wp_generate_uuid4() );
		$order->update_meta_data( '_cvd_operation_history', array_slice( $history, -100 ) );
		$order->add_order_note( sprintf( 'Operación Casa Viva: %s → %s.', self::label( $current ), self::label( $next ) ) );
		$order->save();
		$last_event = end( $history );
		do_action( 'cvd_order_transition_observed', $order->get_id(), 'operation', $current, $next, 'cvd_sales_change_status', array(), (string) ( $last_event['event_anchor'] ?? $last_event['at'] ?? '' ) );
		if ( 'ready' === $next && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) && class_exists( 'CVD_Delivery' ) ) {
			CVD_Delivery::dispatch_ready( $order ); // directo al mensajero que eligió la gestora, o al grupo como siempre
		}

		if ( 'delivered' === $next ) {
			$order->update_meta_data( '_cvd_commission_review_ready', 'yes' );
			$order->save();
			// 3.14.38 (Lennys 18-oct): el mensajero pagó en la tienda pero no marcó «entregado»: lo marca la tienda al recibir el dinero.
			if ( class_exists( 'CVD_Delivery' ) && in_array( CVD_Delivery::status( $order ), array( 'picked_up', 'handed_over' ), true ) ) {
				CVD_Delivery::store_marks_delivered( $order );
				$order = wc_get_order( $order->get_id() );
			}
			$delivery_closed = class_exists( 'CVD_Delivery' ) && 'delivered' === CVD_Delivery::status( $order )
				? CVD_Delivery::close_after_cash_received( $order )
				: false;
			if ( ! $delivery_closed && class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_approved( $order->get_id() ); }
			$order = wc_get_order( $order->get_id() );
			if ( $order && ! $order->has_status( 'completed' ) ) { $order->update_status( 'completed', 'Dinero recibido y operación completada.' ); }
		}
		return rest_ensure_response( array( 'message' => 'Pedido actualizado.', 'notice' => self::test_notice( $order, $next ), 'order' => self::payload( wc_get_order( $order->get_id() ) ) ) );
	}

	/** 3.13.54: al pulsar «Listo para salir» en un pedido de prueba, la tienda lee claro que no salió al reparto. */
	private static function test_notice( $order, string $next ): string {
		return 'ready' === $next && class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) ? 'Pedido de PRUEBA: quedó listo, pero NO salió al reparto (ningún mensajero lo recibe).' : '';
	}

	private static function allowed_transitions( string $current ): array {
		$map = array(
			'new' => array( 'preparing', 'incident', 'cancelled' ),
			'confirmed' => array( 'preparing', 'incident', 'cancelled' ),
			'preparing' => array( 'ready', 'incident', 'cancelled' ),
			'ready' => array( 'incident', 'cancelled' ),
			'with_courier' => array( 'delivered', 'incident', 'cancelled' ),
			'incident' => array( 'confirmed', 'preparing', 'ready', 'with_courier', 'cancelled' ),
			'delivered' => array(),
			'cancelled' => array(),
		);
		return $map[ $current ] ?? array();
	}

	private static function operation_status( WC_Order $order ): string {
		$status = sanitize_key( (string) $order->get_meta( self::STATUS_META, true ) );
		if ( 'cancelled' === $order->get_status() || in_array( $order->get_status(), array( 'refunded', 'failed' ), true ) ) { return 'cancelled'; }
		if ( 'completed' === $order->get_status() ) { return 'delivered'; }
		if('yes'===$order->get_meta('_cvd_operation_incident_active',true)){return 'incident';}return in_array( $status, self::STATUSES, true ) ? $status : 'new';
	}

	private static function payload( WC_Order $order ): array {
		$products = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			// 3.14.7 (Nana): precio de cada producto en la tarjeta («2× … — 15 USD c/u = 30 USD»).
			$line = $item instanceof WC_Order_Item_Product ? CVD_Order_Lines::line( $order, $item ) : null;
			// 3.14.18 (33/33/33): lo que la clienta añadió en la tienda sale marcado.
			$is_extra = class_exists( 'CVD_Commissions' ) && is_array( $item->get_meta( CVD_Commissions::EXTRA_META, true ) ) && $item->get_meta( CVD_Commissions::EXTRA_META, true );
			// 3.14.37 (Lennys 16 y 17-oct: «un cortinero que no era»): la foto de cada producto y su color en la tarjeta, para
			// preparar sin confundir productos parecidos. Misma foto que el vale del mensajero (CVD_Order_Lines::photo_url):
			// 'image' pequeña para la tarjeta (conexiones lentas) e 'imageLarge' al tocarla. Solo lectura.
			$is_line = $item instanceof WC_Order_Item_Product;
			$products[] = array( 'name' => $item->get_name(), 'quantity' => (int) $item->get_quantity(), 'url' => $product ? get_permalink( $product->get_parent_id() ?: $product->get_id() ) : '', 'unit' => $line ? $line['unit'] : null, 'lineTotal' => $line ? $line['total'] : null, 'text' => ( $line ? $line['text'] : '' ) . ( $is_extra && $line ? ' · extra en tienda' : '' ), 'extra' => (bool) $is_extra, 'image' => $is_line ? CVD_Order_Lines::photo_url( $item, 'medium' ) : '', 'imageLarge' => $is_line ? CVD_Order_Lines::photo_url( $item, 'large' ) : '', 'variation' => $is_line ? CVD_Order_Lines::variation_text( $item ) : '' );
		}
		$fulfillment = sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		$operation_status = self::operation_status( $order );
		$delivery_status = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::status( $order ) : '';
		$actions = self::allowed_transitions( $operation_status );
		// Un solo toque: un pedido nuevo ofrece directamente "Listo para salir" (ver change_status).
		if ( in_array( $operation_status, array( 'new', 'confirmed' ), true ) ) { $actions = array_map( static fn( string $a ): string => 'preparing' === $a ? 'ready' : $a, $actions ); }
		if ( 'pickup' === $fulfillment && 'ready' === $operation_status ) { array_unshift( $actions, 'delivered' ); }
		if ( 'pickup' !== $fulfillment && class_exists( 'CVD_Delivery' ) ) {
			$actions = array_values( array_diff( $actions, array( 'with_courier', 'delivered' ) ) );
			$direct = class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::is_direct( $order );
			// Asignación directa: la tienda puede entregárselo aunque aún no haya pulsado «Aceptar» (ver handover_by_staff).
			if ( in_array( $delivery_status, array( 'accepted', 'to_store' ), true ) || ( 'assigned' === $delivery_status && $direct ) ) { array_unshift( $actions, 'picked_up' ); }
			// 3.14.38: también con el paquete en manos del mensajero, aunque no haya marcado «entregado» (lo marca la tienda al cobrar).
			if ( in_array( $delivery_status, array( 'picked_up', 'handed_over', 'delivered' ), true ) ) { array_unshift( $actions, 'delivered' ); }
		}
		// 3.13.97: «Cancelar» y «Cambiar pedido» según CVD_Order_Edit (quién y en qué etapa).
		$user_id = get_current_user_id();
		$cancel_why = class_exists( 'CVD_Order_Edit' ) ? CVD_Order_Edit::cancel_block( $order, $user_id ) : 'No disponible.';
		$edit_why = class_exists( 'CVD_Order_Edit' ) ? CVD_Order_Edit::edit_block( $order, $user_id ) : 'No disponible.';
		if ( '' !== $cancel_why ) { $actions = array_values( array_diff( $actions, array( 'cancelled' ) ) ); }
		$phone = preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() );
		$messenger = 'pickup' === $fulfillment ? false : get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		$preferred = 'pickup' !== $fulfillment && ! $messenger && class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::preferred_messenger( $order ) : null;
		$declared_handover = (string) $order->get_meta( '_cvd_cash_handover_declared_at', true );
		return array(
			'id' => $order->get_id(),
			'number' => $order->get_order_number(),
			'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
			'status' => $operation_status,
			'statusLabel' => 'pickup' === $fulfillment && 'ready' === $operation_status ? 'Listo para recoger' : self::label( $operation_status ),
			'customer' => $order->get_formatted_billing_full_name(),
			'phone' => $phone,
			'address' => 'pickup' === $fulfillment ? get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) : trim( $order->get_billing_address_1() . ', ' . $order->get_meta( '_cvd_locality', true ) . ', ' . $order->get_billing_city() ),
			'fulfillment' => 'pickup' === $fulfillment ? 'Recogida en tienda' : 'Entrega a domicilio',
			'total' => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'products' => $products,
			'gestora' => (string) $order->get_meta( 'gestora_nombre', true ),
			'commission' => (float) $order->get_meta( '_cvd_commission_amount', true ),
			'commissionStatus' => sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'none',
			'shippingCup' => 'pickup' === $fulfillment ? 0 : ( class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ),
			'orderCode' => 'CV-PEDIDO-' . $order->get_id(),
			// 3.13.54: pedido de prueba → cartel grande «PRUEBA — no preparar»; nunca sale al reparto.
			'testOrder' => class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ),
			'canReturn' => current_user_can( 'manage_woocommerce' ) && $order->has_status( 'completed' ) && ! $order->get_meta( '_cvd_return', true ),
			'declaredUsd' => (string) $order->get_meta( '_cvd_declared_usd', true ),
			'declaredCup' => (string) $order->get_meta( '_cvd_declared_cup', true ),
			'declaredEur' => (string) $order->get_meta( '_cvd_declared_eur', true ),
			// 3.13.67 (Lennys): cómo paga el cliente y cuánto en su moneda, la misma cifra que lee el mensajero en su vale.
			'payment' => class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null, // 3.13.74: también en la recogida
			// 3.14.18 (Zaymi, 15-oct): el vuelto que pidió el cliente, el mismo que lee el mensajero en su vale; también en la recogida.
			'change' => class_exists( 'CVD_Bot_Bridge' ) && method_exists( 'CVD_Bot_Bridge', 'change_label' ) ? CVD_Bot_Bridge::change_label( $order ) : '',
			'isPickup' => 'pickup' === $fulfillment,
			// 3.13.70 (Ernesto): armado por el mensajero, aparte del total ({ kind, usd, label, text, notice } o null).
			'assembly' => class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null,
			'deliveryStatus' => 'pickup' === $fulfillment ? '' : ( class_exists( 'CVD_Delivery' ) ? CVD_Delivery::label( $delivery_status ) : '' ),
			'storeMarksDelivered' => 'pickup' !== $fulfillment && in_array( $delivery_status, array( 'picked_up', 'handed_over' ), true ),
			'trackingUrl' => class_exists( 'CVD_Delivery' ) && 'pickup' !== $fulfillment ? CVD_Delivery::tracking_url( $order ) : '',
			// Reparto (3.13.48): quién lo lleva, a qué hora, sus pasos y si el mensajero dice que ya entregó el dinero.
			'messengerName' => $messenger ? $messenger->display_name : '',
			'directAssignment' => 'pickup' !== $fulfillment && class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::is_direct( $order ) && (bool) $messenger,
			'preferredMessenger' => $preferred ? $preferred->display_name : '',
			'eta' => 'pickup' === $fulfillment ? '' : (string) $order->get_meta( '_cvd_bot_eta', true ),
			// Día de entrega (3.13.51): "mañana sáb 12/10 · por la mañana" o "lo antes posible"; futuro = para otro día.
			'deliveryWhen' => 'pickup' === $fulfillment ? '' : CVD_Delivery_When::order_label( $order ),
			'deliveryFuture' => 'pickup' !== $fulfillment && CVD_Delivery_When::for_order( $order )['future'],
			'timeline' => 'pickup' === $fulfillment ? array() : self::timeline( $order ),
			'cashHandoverDeclaredAt' => '' !== $declared_handover && 'delivered' === $delivery_status ? get_date_from_gmt( $declared_handover, 'd/m H:i' ) : '',
			'actions' => array_values( array_unique( $actions ) ),
			// Cómo se cobró (si ya se cerró): método, a qué tarjeta entró y el número de la transferencia.
			'collection' => '' !== (string) $order->get_meta( '_cvd_collection_method', true ) ? array(
				'method' => sanitize_key( (string) $order->get_meta( '_cvd_collection_method', true ) ),
				'account' => (string) $order->get_meta( '_cvd_collection_account_name', true ),
				'reference' => (string) $order->get_meta( '_cvd_collection_reference', true ),
			) : null,
			'adminUrl' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ),
			// 3.13.78 (Nana, 12–13 oct): quién lo vendió y quién lo lleva, con su WhatsApp. Las dependientas lo ven.
			'people' => self::people( $order ),
			'canCancel' => '' === $cancel_why,
			'canEdit' => '' === $edit_why,
			'cancelReason' => (string) $order->get_meta( '_cvd_cancel_reason', true ),
			// 3.14.72 (Pilar 3): reserva de la recogida («Kay pidió guardarlo hasta mañana»); la tienda la ve en rojo y no cancela.
			'reserva' => class_exists( 'CVD_Reserva' ) && 'pickup' === $fulfillment ? CVD_Reserva::get( $order ) : null,
		);
	}

	/**
	 * Gestora y mensajero de un pedido, para la tienda (Nana, 12–13 oct: «en tienda debemos hacer esas anotaciones»).
	 * Solo lectura: nombre y WhatsApp. La comisión sigue oculta a las dependientas (CVD_Staff_Privacy).
	 * gestora = null → venta directa. messenger = null → sin mensajero todavía (o recogida en tienda: pickup).
	 * messenger.state: «assigned» (lo lleva) o «preferred» (la gestora lo pidió, aún no lo tiene).
	 */
	public static function people( WC_Order $order ): array {
		$pickup = 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$gestora = null;
		if ( $owner_id ) {
			$name = trim( (string) $order->get_meta( 'gestora_nombre', true ) );
			$gestora = array( 'name' => '' !== $name ? $name : (string) get_the_author_meta( 'display_name', $owner_id ), 'phone' => self::user_phone( $owner_id ) );
		}
		$messenger = null;
		if ( ! $pickup ) {
			$assigned = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
			$preferred = ! $assigned && class_exists( 'CVD_Direct_Dispatch' ) ? CVD_Direct_Dispatch::preferred_messenger( $order ) : null;
			if ( $assigned ) {
				$messenger = array( 'name' => $assigned->display_name, 'phone' => self::user_phone( $assigned->ID ), 'state' => 'assigned', 'byGestora' => class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::is_direct( $order ) );
			} elseif ( $preferred ) {
				$messenger = array( 'name' => $preferred->display_name, 'phone' => self::user_phone( $preferred->ID ), 'state' => 'preferred', 'byGestora' => true );
			}
		}
		return array( 'pickup' => $pickup, 'gestora' => $gestora, 'messenger' => $messenger );
	}

	/** WhatsApp de una persona del equipo (solo dígitos): el de su cuenta o, si falta, el de facturación. */
	public static function user_phone( int $user_id ): string {
		return $user_id ? preg_replace( '/\D+/', '', (string) ( get_user_meta( $user_id, '_cvd_whatsapp', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) ) ) : '';
	}

	/** «+53 5387 0260» para leer en pantalla (8 dígitos cubanos → con +53). */
	public static function phone_label( string $digits ): string {
		$d = preg_replace( '/\D+/', '', $digits );
		if ( 8 === strlen( $d ) ) { $d = '53' . $d; }
		return 10 === strlen( $d ) && str_starts_with( $d, '53' ) ? '+53 ' . substr( $d, 2, 4 ) . ' ' . substr( $d, 6 ) : ( '' !== $d ? '+' . $d : '' );
	}

	/** Las dos líneas en texto (para pantallas hechas en PHP, como /mensajeria/). */
	public static function people_lines( WC_Order $order ): array {
		$p = self::people( $order );
		$who = static fn( array $x ): string => $x['name'] . ( '' !== $x['phone'] ? ' · ' . self::phone_label( $x['phone'] ) : '' );
		$gestora = $p['gestora'] ? $who( $p['gestora'] ) : 'Venta directa (sin gestor/a)';
		if ( $p['pickup'] ) { $messenger = 'Recogida en tienda'; }
		elseif ( ! $p['messenger'] ) { $messenger = 'Sin mensajero todavía'; }
		elseif ( 'preferred' === $p['messenger']['state'] ) { $messenger = 'Sin mensajero todavía · quien vendió pidió a ' . $who( $p['messenger'] ); }
		else { $messenger = $who( $p['messenger'] ) . ( $p['messenger']['byGestora'] ? ' (lo eligió quien vendió)' : '' ); }
		return array( 'gestora' => $gestora, 'messenger' => $messenger );
	}

	/** Últimos 8 pasos del pedido (tienda y mensajería) con hora local, para el panel de la dueña. Solo lectura. */
	private static function timeline( WC_Order $order ): array {
		$steps = array();
		$operation = $order->get_meta( '_cvd_operation_history', true );
		foreach ( is_array( $operation ) ? $operation : array() as $event ) {
			$to = sanitize_key( (string) ( $event['to'] ?? '' ) );
			// «Con mensajero» y «Completado» ya salen como pasos de la mensajería.
			if ( ! $to || empty( $event['at'] ) || in_array( $to, array( 'with_courier', 'delivered' ), true ) ) { continue; }
			$steps[] = array( 'at' => (string) $event['at'], 'label' => self::label( $to ) );
		}
		$delivery = $order->get_meta( '_cvd_delivery_history', true );
		foreach ( is_array( $delivery ) ? $delivery : array() as $event ) {
			$to = sanitize_key( (string) ( $event['to'] ?? '' ) );
			if ( ! $to || empty( $event['at'] ) || $to === sanitize_key( (string) ( $event['from'] ?? '' ) ) ) { continue; } // repeticiones de la oferta (oleadas, rechazos)
			$label = class_exists( 'CVD_Delivery' ) ? CVD_Delivery::label( $to ) : $to;
			$who = absint( $event['data']['messenger'] ?? 0 );
			$who = $who && in_array( $to, array( 'assigned', 'accepted' ), true ) ? get_userdata( $who ) : false;
			if ( $who ) { $label .= ' · ' . $who->display_name; }
			if ( 'assigned' === $to && ! empty( $event['data']['direct'] ) ) { $label .= ' (directo, lo eligió quien vendió)'; }
			$steps[] = array( 'at' => (string) $event['at'], 'label' => $label );
		}
		$eta = trim( (string) $order->get_meta( '_cvd_bot_eta', true ) );
		$eta_at = (string) $order->get_meta( '_cvd_bot_eta_at', true );
		if ( '' !== $eta && '' !== $eta_at ) { $steps[] = array( 'at' => $eta_at, 'label' => 'El mensajero dio la hora: ' . $eta ); }
		$declared = (string) $order->get_meta( '_cvd_cash_handover_declared_at', true );
		if ( '' !== $declared ) { $steps[] = array( 'at' => $declared, 'label' => 'El mensajero dice que entregó el dinero en la tienda' ); }
		usort( $steps, static fn( array $a, array $b ): int => strcmp( $a['at'], $b['at'] ) );
		return array_map(
			static fn( array $step ): array => array( 'time' => get_date_from_gmt( $step['at'], 'd/m H:i' ), 'label' => $step['label'] ),
			array_slice( $steps, -8 )
		);
	}

	private static function label( string $status ): string {
		$labels = array( 'new' => 'Nuevo', 'confirmed' => 'Confirmado', 'preparing' => 'Preparando', 'ready' => 'Listo para mensajería', 'with_courier' => 'En camino', 'delivered' => 'Dinero recibido · completado', 'incident' => 'Incidencia', 'cancelled' => 'Cancelado' );
		return $labels[ $status ] ?? ucfirst( $status );
	}

	public static function assets(): void {
		if ( ! is_page( 'ventas' ) ) { return; }
		wp_enqueue_style( 'cvd-sales', CVD_URL . 'assets/sales.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-qr-code', CVD_URL . 'assets/qr-code.js', array(), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-cash-day', CVD_URL . 'assets/cash-day.js', array(), CVD_VERSION, true );
		wp_enqueue_script( 'cvd-sales', CVD_URL . 'assets/sales.js', array( 'cvd-qr-code', 'cvd-cash-day' ), CVD_VERSION, true );
		wp_localize_script( 'cvd-sales', 'cvdSales', array(
			'url' => rest_url( 'casa-viva/v1/sales' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'isAdmin' => current_user_can( 'manage_woocommerce' ),
			'logoutUrl' => wp_logout_url( home_url( '/casa-viva-app/' ) ),
			'notificationsUrl' => rest_url( 'casa-viva/v1/notifications' ),
			'accounts' => self::account_choices(), // «¿A qué tarjeta entró?» (tarjetas activas + Zelle, USDT, Otra)
		) );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) { return '<section class="cvd-sales-denied"><h1>Centro de ventas</h1><p>Inicia sesión para continuar.</p><a href="' . esc_url( home_url( '/casa-viva-app/' ) ) . '">Iniciar sesión</a></section>'; }
		if ( ! self::can_view() ) { return '<section class="cvd-sales-denied"><h1>Acceso restringido</h1><p>Esta cuenta no administra pedidos.</p></section>'; }
		// Dependienta sin contraseña propia (entró con el enlace de «mi acceso»): que la ponga una vez.
		$user = wp_get_current_user();
		$hint = CVD_Registration::is_clerk( $user ) && ! get_user_meta( $user->ID, '_cvd_has_own_password', true ) ? '<p class="cvd-clerk-password-hint">Pon tu contraseña para entrar siempre con tu WhatsApp: <a href="' . esc_url( home_url( '/centro-operaciones/#cvd-clave' ) ) . '">Poner mi contraseña</a></p>' : '';
		// Pendientes / Cerrados (Nana, 12-oct): lo ya cerrado por caja (completado, recogido y pagado, cancelado) va aparte; ver sales.js.
		return '<section class="cvd-sales-app"><nav><a href="' . esc_url( home_url( '/centro-operaciones/' ) ) . '">Operaciones</a><button data-cvd-enable-notifications type="button">Activar alertas</button><a href="' . esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ) . '">Salir</a></nav>' . $hint . '<header><h1>Pedidos</h1></header><div class="cvd-sales-summary" id="cvd-sales-summary"></div><div class="cvd-sales-tools"><input id="cvd-sales-search" type="search" placeholder="Buscar pedido, cliente o producto"><select id="cvd-sales-filter"><option value="">Todos</option><option value="new">Nuevos</option><option value="preparing">Preparando</option><option value="ready">Listos</option><option value="with_courier">Con mensajero</option><option value="delivered">Completados</option><option value="incident">Incidencias</option><option value="cancelled">Cancelados</option></select><button id="cvd-sales-refresh" type="button">Actualizar</button><button id="cvd-scan-order" type="button">Escanear vale</button></div><video id="cvd-order-scanner" playsinline hidden></video><p id="cvd-sales-message" role="status"></p><div class="cvd-sales-views" id="cvd-sales-views"><button type="button" data-sales-view="open" aria-pressed="true">Pendientes <b></b></button><button type="button" data-sales-view="closed" aria-pressed="false">Cerrados <b></b></button></div><div class="cvd-sales-list" id="cvd-sales-list"><p>Cargando pedidos…</p></div><dialog id="cvd-money-dialog"><form method="dialog" id="cvd-money-form"><h2 id="cvd-money-title">Dinero recibido</h2><input id="cvd-money-order" type="hidden"><input id="cvd-money-pickup" type="hidden" value="0"><label>Forma de cobro<select id="cvd-money-method" required><option value="">Selecciona</option><option value="cash_usd">Efectivo USD</option><option value="cash_cup">Efectivo CUP</option><option value="transfer">Transferencia a tarjeta (CUP)</option><option value="zelle">Zelle</option><option value="usdt">USDT</option><option value="mixed">Mixto (efectivo + transferencia)</option><option value="other">Otro</option></select></label><label id="cvd-money-account-row" hidden>¿A qué tarjeta entró?<select id="cvd-money-account"></select></label><div id="cvd-money-typed" hidden><label>Nombre de la tarjeta<input id="cvd-money-card-label" maxlength="40" autocomplete="off" placeholder="Ej.: MN BPA"></label><label>Últimos 4 números<input id="cvd-money-card-last4" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off" placeholder="2690"></label><label class="cvd-money-check" id="cvd-money-save-row"><input id="cvd-money-save-card" type="checkbox"> Guardar en la lista de tarjetas</label></div><label id="cvd-money-reference-row" hidden>Nº de transferencia / referencia<input id="cvd-money-reference" maxlength="60" autocomplete="off" placeholder="Ej.: el número que sale en el SMS"></label><label>Recibido en USD<input id="cvd-money-usd" min="0" step="0.01" type="number"></label><label>Recibido en CUP<input id="cvd-money-cup" min="0" step="1" type="number"></label><label>Observación<textarea id="cvd-money-note" rows="2"></textarea></label><label class="cvd-money-check"><input id="cvd-handover-confirmed" type="checkbox"> Confirmo que el producto fue entregado físicamente al cliente.</label><label class="cvd-money-check"><input id="cvd-money-confirmed" type="checkbox" required> Confirmo el dinero recibido.</label><div><button value="cancel" formnovalidate>Volver</button><button id="cvd-money-submit" value="default">Completar</button></div></form></dialog></section>';
	}
}
