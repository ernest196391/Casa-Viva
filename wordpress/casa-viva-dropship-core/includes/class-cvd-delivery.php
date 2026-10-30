<?php

defined( 'ABSPATH' ) || exit;

/** Cadena de custodia de mensajería. El pedido WooCommerce es la fuente de verdad. */
final class CVD_Delivery {
	private const STATUSES = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed', 'incident', 'failed', 'returned', 'cancelled' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'assignment_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_assignment' ) );
		add_action( 'admin_post_cvd_delivery_status', array( __CLASS__, 'change_status' ) );
		add_action( 'admin_post_cvd_delivery_assign', array( __CLASS__, 'assign_from_portal' ) );
		add_action( 'admin_post_cvd_delivery_offer', array( __CLASS__, 'offer_decision' ) );
		add_action( 'admin_post_cvd_delivery_pickup', array( __CLASS__, 'confirm_pickup' ) );
		add_shortcode( 'casa_viva_delivery_control', array( __CLASS__, 'render_control' ) );
		add_shortcode( 'casa_viva_order_tracking', array( __CLASS__, 'render_tracking' ) );
		add_action( 'template_redirect', array( __CLASS__, 'tracking_page_request' ), 1 ); // 3.13.89: «Mi pedido» sin cuenta
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'initialize_order' ), 30 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_tracking' ), 25, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled' ), 40 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_cancelled' ), 40 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'sync_cancelled' ), 40 );
		add_action( 'cvd_expand_delivery_offer', array( __CLASS__, 'expand_offer' ) );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'guard_closed_cancellation' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'schedule_dispatch_on_create' ), 80 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'schedule_dispatch_on_create' ), 80 );
		add_action( 'cvd_dispatch_new_order', array( __CLASS__, 'dispatch_new_order' ) );
	}

	/**
	 * 3.13.58 (Lennys, 12-oct): la carrera sale al reparto al crear el pedido, sin esperar «Listo para salir»
	 * («ralentiza el proceso»; lo que está en la web está en tienda). Interruptor `cvd_dispatch_on_create` (yes/no).
	 * Un minuto después de la compra (para que el pago y el mensajero de la nota ya estén puestos) se llama a
	 * dispatch_ready(): directo al mensajero elegido o al grupo. De 22:00 a 7:30 (Cuba) espera a las 7:30.
	 * Al pulsar «Listo» después no se publica otra vez: publish_offer() solo publica lo que sigue «Por ofertar».
	 */
	public static function schedule_dispatch_on_create( $order ): void {
		try {
			if ( 'yes' !== get_option( 'cvd_dispatch_on_create', 'no' ) ) { return; }
			if ( ! $order instanceof WC_Order ) { $order = wc_get_order( $order ); }
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return; }
			if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return; }
			// Bajo pedido (3.13.63): no sale al grupo; primero se confirma con el proveedor (VivaBot avisa a Ernesto).
			if ( class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::order_has_market_items( $order ) ) {
				$order->add_order_note( 'Pedido con producto bajo pedido: no sale solo al grupo de mensajeros. Primero se confirma con el proveedor.' );
				return;
			}
			$args = array( $order->get_id() );
			if ( ! wp_next_scheduled( 'cvd_dispatch_new_order', $args ) ) { wp_schedule_single_event( time() + 60, 'cvd_dispatch_new_order', $args ); }
		} catch ( Throwable $error ) {
			unset( $error ); // nunca se frena una compra por esto: sin publicar, sale al pulsar «Listo» como antes
		}
	}

	/** Cuándo se puede publicar: ahora (7:30–22:00 en Cuba) o a las 7:30 siguientes. */
	public static function dispatch_time( int $now ): int {
		// 3.14.20 (Ernesto, 15-oct noche): las carreras salen al grupo A CUALQUIER HORA (la gestora publica cuando quiera
		// y el mensajero dice «Yo» cuando quiera). Para volver a frenar de 22:00 a 7:30: opción cvd_dispatch_night_hold = yes.
		if ( 'yes' !== get_option( 'cvd_dispatch_night_hold', 'no' ) ) { return $now; }
		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( new DateTimeZone( 'America/Havana' ) );
		$minutes = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		if ( $minutes >= 450 && $minutes < 1320 ) { return $now; }
		$next = $local->setTime( 7, 30 );
		return ( $minutes >= 1320 ? $next->modify( '+1 day' ) : $next )->getTimestamp();
	}

	/**
	 * 3.14.26 (Ernesto, 16-oct, idea de Oliuska): la clienta ya tiene un vale con mensajero y pide otra cosa; la gestora
	 * hace otro vale. Antes salía al grupo y lo podía coger otro mensajero. Ahora, si hay otro pedido ABIERTO de la misma
	 * clienta (mismo teléfono, misma gestora, mismo día de entrega) que un mensajero tiene y aún no recogió, el nuevo se le
	 * asigna directo a ese mensajero y su mensajería queda en 0 (mismo viaje: la clienta paga una sola). Queda nota en los
	 * dos pedidos y en la nota del vale («Va junto con el #N»). Apagar: opción cvd_join_same_customer = no.
	 */
	public static function join_same_customer( WC_Order $order ): bool {
		if ( 'no' === get_option( 'cvd_join_same_customer', 'yes' ) || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return false; }
		if ( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) || ( class_exists( 'CVD_Direct_Dispatch' ) && absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) ) ) ) { return false; } // la gestora ya eligió mensajero
		$phone = substr( preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ), -8 );
		$owner = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		if ( 8 !== strlen( $phone ) || ! $owner ) { return false; }
		$day = (string) $order->get_meta( '_cvd_delivery_date', true );
		$sibling = null;
		foreach ( wc_get_orders( array( 'limit' => 40, 'status' => array( 'on-hold', 'processing' ), 'exclude' => array( $order->get_id() ), 'date_created' => '>' . ( time() - 4 * DAY_IN_SECONDS ), 'orderby' => 'date', 'order' => 'DESC' ) ) as $other ) {
			if ( ! $other instanceof WC_Order || $other instanceof WC_Order_Refund || 'pickup' === $other->get_meta( '_cvd_fulfillment_type', true ) ) { continue; }
			if ( $owner !== absint( $other->get_meta( '_cvd_owner_user_id', true ) ) ) { continue; }
			if ( substr( preg_replace( '/\D+/', '', (string) $other->get_billing_phone() ), -8 ) !== $phone ) { continue; }
			if ( $day !== (string) $other->get_meta( '_cvd_delivery_date', true ) ) { continue; }
			if ( ! in_array( self::status( $other ), array( 'assigned', 'accepted', 'to_store' ), true ) || ! absint( $other->get_meta( '_cvd_messenger_user_id', true ) ) ) { continue; }
			$sibling = $other;
			break;
		}
		if ( ! $sibling ) { return false; }
		$messenger = get_userdata( absint( $sibling->get_meta( '_cvd_messenger_user_id', true ) ) );
		if ( ! $messenger instanceof WP_User ) { return false; }
		$fee = (string) $order->get_meta( '_cvd_shipping_fee_cup', true );
		$note = (string) $order->get_customer_note();
		$order->update_meta_data( '_cvd_joined_with', $sibling->get_id() );
		$order->update_meta_data( '_cvd_shipping_fee_cup', 0 );
		$order->update_meta_data( '_cvd_shipping_courier_amount_cup', '0.00' );
		$order->update_meta_data( '_cvd_shipping_platform_amount_cup', '0.00' );
		$order->set_customer_note( '🔗 Va JUNTO con el #' . $sibling->get_order_number() . ' (misma clienta, mismo viaje): mensajería 0.' . ( '' !== trim( $note ) ? ' ' . $note : '' ) );
		$order->save();
		if ( ! self::assign_direct( $order, $messenger ) ) {
			$order->delete_meta_data( '_cvd_joined_with' );
			$order->update_meta_data( '_cvd_shipping_fee_cup', $fee );
			$order->set_customer_note( $note );
			$order->save();
			self::initialize_order( $order );
			return false;
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) { $fresh->add_order_note( 'Unido con el #' . $sibling->get_order_number() . ' (misma clienta): asignado a ' . $messenger->display_name . ' sin pasar por el grupo. Mensajería 0 (antes ' . ( '' !== $fee ? $fee : '0' ) . ' CUP).' ); $fresh->save(); }
		$sibling->add_order_note( 'La gestora hizo otro vale para la misma clienta (#' . $order->get_order_number() . '): va junto con este, con ' . $messenger->display_name . '.' );
		$sibling->save();
		return true;
	}

	public static function dispatch_new_order( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return; }
		if ( ! in_array( $order->get_status(), array( 'on-hold', 'processing' ), true ) || 'unassigned' !== self::status( $order ) ) { return; }
		if ( class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::order_has_market_items( $order ) ) { return; } // bajo pedido: ver schedule_dispatch_on_create()
		$when = self::dispatch_time( time() );
		if ( $when > time() + 60 ) {
			if ( ! wp_next_scheduled( 'cvd_dispatch_new_order', array( $order->get_id() ) ) ) { wp_schedule_single_event( $when, 'cvd_dispatch_new_order', array( $order->get_id() ) ); }
			return;
		}
		// La tarea corre sin usuario y publicar/asignar es cosa del equipo (permisos de la transición):
		// se hace en nombre del administrador del sistema (opción cvd_system_actor_user_id; si no, el admin más antiguo).
		$previous = get_current_user_id();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$actor = absint( get_option( 'cvd_system_actor_user_id', 0 ) ) ?: (int) ( get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
			if ( $actor ) { wp_set_current_user( $actor ); }
		}
		try {
			// 3.14.26: vale nuevo de la MISMA clienta con otro suyo ya en manos de un mensajero → va con ese mensajero, no al grupo.
			if ( self::join_same_customer( $order ) ) { return; }
			$sent = self::dispatch_ready( $order );
		} finally {
			wp_set_current_user( $previous );
		}
		if ( $sent ) {
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh ) { $fresh->add_order_note( 'Reparto: salió al crear el pedido, sin esperar «Listo para salir».' ); $fresh->save(); }
		}
	}

	/**
	 * Un pedido cerrado (dinero verificado, comisión y ganancia aprobadas) no se cancela ni se
	 * reembolsa desde WooCommerce: Core ya rechaza esa anulación, y sin este freno el pedido
	 * quedaba "cancelado" con la comisión y la ganancia aprobadas. Las devoluciones irán por su
	 * propio flujo (decisión de Ernesto, opción A, 2026-10-07).
	 */
	public static function guard_closed_cancellation( $order ): void {
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! $order->get_id() ) { return; }
		if ( 'yes' === $order->get_meta( '_cvd_return_in_progress', true ) ) { return; } // devolución registrada desde /ventas/
		$changes = $order->get_changes();
		if ( empty( $changes['status'] ) || ! in_array( $changes['status'], array( 'cancelled', 'refunded' ), true ) ) { return; }
		$closed = 'closed' === sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) || 'verified' === sanitize_key( (string) $order->get_meta( '_cvd_cash_status', true ) );
		if ( ! $closed ) { return; }
		$stored = wc_get_order( $order->get_id() );
		$previous = $stored ? $stored->get_status() : 'completed';
		if ( in_array( $previous, array( 'cancelled', 'refunded' ), true ) ) { return; }
		$order->set_status( $previous );
		// Sin transición pendiente: no se disparan correos ni hooks de "completado" otra vez.
		( function () { $this->status_transition = false; } )->call( $order );
		$order->add_order_note( 'Casa Viva: este pedido ya está cerrado (dinero verificado). No se puede cancelar ni reembolsar desde aquí; registra una devolución.' );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/messenger/feed', array(
			'methods' => 'GET',
			'callback' => array( __CLASS__, 'messenger_feed' ),
			'permission_callback' => array( __CLASS__, 'can_read_messenger_feed' ),
		) );
	}

	public static function can_read_messenger_feed(): bool {
		$user = wp_get_current_user();
		return $user->exists()
			&& CVD_Registration::is_approved_messenger( $user ); // Cuenta doble: vale su estado de mensajero.
	}

	/** Datos mínimos para refrescar ofertas sin revelar al cliente antes de aceptar. */
	public static function messenger_feed(): WP_REST_Response {
		$user = wp_get_current_user();
		$offers = array_map(
			static function ( WC_Order $order ) use ( $user ): array {
				$items = array();
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$items[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' );
				}
				$asm = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
				return array(
					'id' => $order->get_id(),
					'zone' => self::destination_zone( $order ),
					'earningCup' => self::courier_amount( $order ),
					// 3.13.70: el armado sale en la ventana «Nueva carrera» antes de aceptarla.
					'items' => implode( ', ', $items ) . ( $asm ? ' · 🔧 hay que ARMARLO: cobras ' . CVD_Cuban_Checkout::money( (float) $asm['usd'], 'USD' ) . ' aparte' . ( ! empty( $asm['drill'] ) ? ' · lleva TALADRO (soporte de TV)' : '' ) : '' ), // 3.13.96: taladro
					'assembly' => $asm,
					'zoneMatch' => self::zone_matches( $user, $order ),
					'acceptUrl' => self::offer_action_url( $order->get_id(), 'accept' ),
					'declineUrl' => self::offer_action_url( $order->get_id(), 'decline' ),
				);
			},
			self::offers_for( $user )
		);
		$assigned = wc_get_orders( array( 'limit'=>30, 'orderby'=>'date', 'order'=>'DESC', 'meta_key'=>'_cvd_messenger_user_id', 'meta_value'=>$user->ID ) );
		$deliveries = array_values( array_map(
			static fn( WC_Order $order ): array => array(
				'id'=>$order->get_id(), 'status'=>self::status( $order ), 'label'=>self::label( self::status( $order ) ),
				'operationStatus'=>sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ),
				'operationUpdatedAt'=>(string) $order->get_meta( '_cvd_operation_updated_at', true ),
			),
			array_filter( $assigned, static fn( WC_Order $order ): bool => ! in_array( self::status( $order ), array('closed','failed','returned','cancelled'), true ) )
		) );
		$response = rest_ensure_response( array( 'offers'=>$offers, 'deliveries'=>$deliveries, 'serverTime'=>time() ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/** Anula también la carrera y su ganancia cuando WooCommerce anula el pedido. */
	public static function sync_cancelled( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		if(class_exists('CVD_Order_Transition_Service')){CVD_Order_Transition_Service::cancel($order_id,$order->get_status(),array('system'=>true,'source'=>'woocommerce_hook'));}
	}

	public static function initialize_order( WC_Order $order ): void {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return; }
		$initialized = ! $order->get_meta( '_cvd_delivery_status', true );
		if ( $initialized ) { $order->update_meta_data( '_cvd_delivery_status', 'unassigned' ); }
		$rate = min( 100, max( 0, (float) get_option( 'cvd_shipping_platform_percent', 10 ) ) );
		$fee = class_exists( 'CVD_Shipping_Rates' ) ? (float) CVD_Shipping_Rates::order_fee( $order ) : (float) $order->get_meta( '_cvd_shipping_fee_cup', true );
		$order->update_meta_data( '_cvd_shipping_platform_rate', $rate );
		$order->update_meta_data( '_cvd_shipping_platform_amount_cup', wc_format_decimal( $fee * $rate / 100, 2 ) );
		$order->update_meta_data( '_cvd_shipping_courier_amount_cup', wc_format_decimal( $fee * ( 100 - $rate ) / 100, 2 ) );
		$order->update_meta_data( '_cvd_location_retention_days', min( 90, max( 1, absint( get_option( 'cvd_location_retention_days', 30 ) ) ) ) );
		$order->update_meta_data( '_cvd_messenger_earning_status', 'pending' );
		$order->save();
		if ( $initialized ) { do_action( 'cvd_order_transition_observed', $order->get_id(), 'delivery', '', 'unassigned', 'cvd_delivery_initialize_order', array(), (string) $order->get_id() ); }
	}

	public static function assets(): void {
		if ( is_page( 'mensajeria' ) ) { wp_enqueue_style( 'cvd-sales', CVD_URL . 'assets/sales.css', array(), CVD_VERSION ); wp_enqueue_script( 'cvd-delivery-control', CVD_URL . 'assets/delivery.js', array(), CVD_VERSION, true ); }
		if ( is_page( 'seguimiento' ) ) { wp_enqueue_style( 'cvd-portal', CVD_URL . 'assets/portal.css', array(), CVD_VERSION ); }
	}

	public static function assignment_field( WC_Order $order ): void {
		$messengers = self::messengers(); $current = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		wp_nonce_field( 'cvd_assign_messenger_' . $order->get_id(), 'cvd_assign_messenger_nonce' );
		echo '<p class="form-field form-field-wide"><label for="cvd_messenger_user_id"><strong>Mensajero asignado</strong></label><select id="cvd_messenger_user_id" name="cvd_messenger_user_id" style="width:100%"><option value="0">Sin asignar</option>';
		foreach ( $messengers as $messenger ) { echo '<option value="' . esc_attr( $messenger->ID ) . '" ' . selected( $current, $messenger->ID, false ) . '>' . esc_html( $messenger->display_name ) . '</option>'; }
		echo '</select></p>';
	}

	public static function save_assignment( int $order_id ): void {
		if ( ! current_user_can( 'edit_shop_order', $order_id ) || ! isset( $_POST['cvd_assign_messenger_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_assign_messenger_nonce'] ) ), 'cvd_assign_messenger_' . $order_id ) ) { return; }
		self::assign( $order_id, absint( $_POST['cvd_messenger_user_id'] ?? 0 ) );
	}

	public static function assign_from_portal(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! current_user_can( 'cvd_manage_sales' ) && ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No tienes permiso.' ); }
		check_admin_referer( 'cvd_delivery_assign_' . $order_id );
		self::assign( $order_id, absint( $_POST['messenger_id'] ?? 0 ) );
		wp_safe_redirect( home_url( '/mensajeria/?actualizado=1' ) ); exit;
	}

	private static function assign( int $order_id, int $messenger_id ): void {
		// La desasignación no forma parte del mapa 1C.1; conserva exactamente su
		// escritor legacy en vez de inventar una transición central nueva.
		if ( ! $messenger_id ) { $order=wc_get_order($order_id); if(!$order){return;} $previous=absint($order->get_meta('_cvd_messenger_user_id',true)); $order->update_meta_data('_cvd_messenger_user_id',0); $order->delete_meta_data('_cvd_direct_assignment'); self::transition($order,'unassigned',array('previous_messenger'=>$previous,'messenger'=>0)); return; }
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) ) { return; }
		$test = wc_get_order( $order_id );
		if ( $test && self::refuse_test_order( $test ) ) { return; } // pedido de prueba: tampoco se asigna a mano
		$user = get_userdata( $messenger_id );
		$result = CVD_Order_Transition_Service::transition( $order_id, 'delivery', 'assigned', array(
			'actor_user_id'=>get_current_user_id(), 'source'=>'cvd_delivery_assign',
			'metadata'=>array( 'messenger'=>$messenger_id ),
			'precondition'=>static function( WC_Order $order ) use ( $messenger_id, $user ) {
				if ( ! $user || ! in_array( 'cvd_messenger', (array) $user->roles, true ) || 'approved' !== CVD_Registration::status_for( $user, 'mensajero' ) ) { return CVD_Order_Transition_Service::PRECONDITION_FAILED; }
				$existing=absint($order->get_meta('_cvd_messenger_user_id',true)); return $existing && $existing!==$messenger_id ? CVD_Order_Transition_Service::CONFLICT : true;
			},
			'atomic_mutation'=>static function( WC_Order $order ) use ( $messenger_id, $user ): void { $order->update_meta_data('_cvd_messenger_user_id',$messenger_id); $order->add_order_note('Entrega asignada a '.$user->display_name.'.'); },
			'after_commit'=>static function( WC_Order $order, array $transition ) use ( $user ): void { self::centralized_after_commit($order,'assigned'); if(empty($transition['idempotent_replay'])&&$user->user_email){wp_mail($user->user_email,'Nueva entrega Casa Viva · Pedido #'.$order->get_order_number(),"Tienes una entrega asignada.\n\nRevisar:\n".home_url('/area-mensajeros/'));} },
		) );
		if ( empty( $result['success'] ) ) { return; }
	}

	/** Publish a prepared home-delivery order to every eligible, available messenger. */
	/*
	 * Pedidos de prueba (3.13.54). Pasó dos veces el 11-oct: pedidos de prueba (tutorial, Harley) llegaron a
	 * mensajeros de verdad porque se pulsó «Listo para salir» sin leer el aviso. Un pedido con
	 * `_cvd_test_order` (cualquier valor: 'tutorial', 'harley', 'auto'…) nunca sale al reparto:
	 * ni oferta al grupo, ni asignación directa, ni asignación a mano, ni lo ve el bot.
	 */
	public const TEST_META = '_cvd_test_order';

	public static function is_test_order( $order ): bool {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) && '' !== trim( (string) $order->get_meta( self::TEST_META, true ) );
	}

	/**
	 * 3.13.71 (13-oct): ¿este pedido NO puede salir al reparto? Sin efectos: '' = sí puede.
	 * - 'test': marcado de prueba o con «PRUEBA» en el nombre, los apellidos, la nota o quien paga. Hasta ahora eso solo
	 *   se miraba en el checkout; el #3548 (creado a mano por un error de Claude al probar, sin pasar por el checkout)
	 *   salió al grupo real cuando se pulsó «Listo» en Pedidos de la tienda.
	 * - 'no_delivery': el pedido no dice «Mensajería» (_cvd_fulfillment_type). El checkout y el vale siempre lo ponen.
	 * - 'inactive': el pedido no está pendiente, en espera ni procesando (papelera, cancelado, completado…).
	 */
	public static function blocks_dispatch( $order ): string {
		if ( ! $order instanceof WC_Order ) { return 'no_order'; }
		if ( self::is_test_order( $order ) || self::looks_like_test_order( $order ) ) { return 'test'; }
		if ( 'delivery' !== (string) $order->get_meta( '_cvd_fulfillment_type', true ) ) { return 'no_delivery'; }
		if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing' ), true ) ) { return 'inactive'; }
		return '';
	}

	/** «PRUEBA» en los datos del pedido (las mismas reglas que el checkout). */
	public static function looks_like_test_order( WC_Order $order ): bool {
		return self::looks_like_test( (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name(), (string) $order->get_customer_note(), (string) $order->get_meta( '_cvd_buyer_name', true ) );
	}

	/**
	 * true = no sale al reparto y se frenó (deja la nota una sola vez por petición). Desde 3.13.71 frena también lo que
	 * parece de prueba (y lo marca), lo que no es de mensajería y lo que no está activo: ver blocks_dispatch().
	 */
	public static function refuse_test_order( WC_Order $order ): bool {
		static $noted = array();
		$why = self::blocks_dispatch( $order );
		if ( '' === $why ) { return false; }
		if ( 'test' === $why && ! self::is_test_order( $order ) ) {
			$order->update_meta_data( self::TEST_META, 'auto' );
			$order->save();
		}
		if ( empty( $noted[ $order->get_id() ] ) ) {
			$noted[ $order->get_id() ] = true;
			$texts = array(
				'test'        => 'Pedido de prueba: no sale al reparto (no se publicó ni se asignó a ningún mensajero).',
				'no_delivery' => 'Este pedido no dice «Mensajería» (no se hizo por el checkout ni por el vale): no sale al reparto.',
				'inactive'    => 'El pedido está «' . wc_get_order_status_name( $order->get_status() ) . '»: no sale al reparto.',
			);
			$order->add_order_note( $texts[ $why ] ?? 'Este pedido no sale al reparto.' );
			$order->save();
		}
		return true;
	}

	/** «PRUEBA» como palabra (mayúsculas o minúsculas) en el nombre o la nota del cliente → pedido de prueba automático. */
	public static function looks_like_test( string ...$texts ): bool {
		foreach ( $texts as $text ) {
			if ( preg_match( '/(?<![\p{L}\p{N}])prueba(?![\p{L}\p{N}])/iu', $text ) ) { return true; }
		}
		return false;
	}

	public static function publish_offer( WC_Order $order ): bool {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return false; }
		if ( self::refuse_test_order( $order ) ) { return false; }
		if ( 'offered' === self::status( $order ) ) { return true; }
		if ( 'unassigned' !== self::status( $order ) ) { return false; }
		$eligible = self::eligible_messengers( $order );
		if ( ! $eligible ) {
			self::append_event( $order, 'unassigned', 'unassigned', array( 'reason' => 'no_available_messengers' ) );
			$order->add_order_note( 'Mensajería: no hay mensajeros disponibles. El pedido continúa por ofertar.' );
			$order->save();
			return false;
		}
		$wave_size = max( 1, absint( get_option( 'cvd_dispatch_first_wave_size', 2 ) ) );
		$first_wave = array_slice( $eligible, 0, $wave_size );
		$metadata=array('eligible_messengers'=>count($eligible),'first_wave'=>count($first_wave));
		$result=CVD_Order_Transition_Service::transition($order->get_id(),'delivery','offered',array(
			'actor_user_id'=>get_current_user_id(),'source'=>'cvd_delivery_publish_offer','metadata'=>$metadata,
			'atomic_mutation'=>static function(WC_Order $locked_order)use($first_wave,$eligible):void{$locked_order->update_meta_data('_cvd_delivery_offered_at',current_time('mysql',true));$locked_order->delete_meta_data('_cvd_messenger_user_id');$locked_order->delete_meta_data('_cvd_direct_assignment');$locked_order->update_meta_data('_cvd_delivery_invited_messengers',wp_list_pluck($first_wave,'ID'));$locked_order->update_meta_data('_cvd_delivery_rank_snapshot',self::rank_snapshot($eligible,$locked_order));},
			'after_commit'=>static function(WC_Order $saved_order)use($first_wave,$eligible):void{self::centralized_after_commit($saved_order,'offered');self::notify_offer($saved_order,$first_wave);if(count($eligible)>count($first_wave)&&!wp_next_scheduled('cvd_expand_delivery_offer',array($saved_order->get_id()))){wp_schedule_single_event(time()+max(30,absint(get_option('cvd_dispatch_wave_delay_seconds',90))),'cvd_expand_delivery_offer',array($saved_order->get_id()));}},
		));
		return !empty($result['success']);
	}

	/** 3.14.17: meta que deja publicar en el grupo un pedido con producto de proveedor (la pone VivaBot con PUBLICA/RECOGÍ o /mensajeria/). */
	public const SUPPLIER_DISPATCH_OK = '_cvd_supplier_dispatch_ok';

	/**
	 * 3.14.17 (#3693, 14-oct): un pedido con producto de proveedor (bajo pedido, SKU MK-) NO sale al grupo de
	 * mensajeros al pulsar «Listo»: la entrega la lleva el proveedor o la coordina Ernesto (a la lámpara la llevaba
	 * Pedri y Nana pulsó «Listo» a las 15:00; salió al grupo y la cogió Félix). Sale solo si alguien lo autoriza
	 * (meta SUPPLIER_DISPATCH_OK: «PUBLICA #N» o «RECOGÍ #N» en VivaBot, o «Ofertar» en /mensajeria/). true = retenido.
	 */
	public static function supplier_hold( WC_Order $order ): bool {
		if ( ! class_exists( 'CVD_Market_Bridge' ) || ! CVD_Market_Bridge::order_has_market_items( $order ) ) { return false; }
		if ( '' !== (string) $order->get_meta( self::SUPPLIER_DISPATCH_OK, true ) ) { return false; }
		if ( ! $order->get_meta( '_cvd_supplier_hold_noted', true ) ) {
			$order->update_meta_data( '_cvd_supplier_hold_noted', current_time( 'mysql', true ) );
			$order->add_order_note( 'Pedido con producto de proveedor: «Listo» no lo publica en el grupo de mensajeros (la entrega la lleva el proveedor o la coordina Ernesto). Si hace falta un mensajero del grupo: «PUBLICA #' . $order->get_order_number() . '» en VivaBot u «Ofertar» en Mensajería.' );
			$order->save();
		}
		return true;
	}

	/**
	 * «Listo para salir» de un pedido a domicilio (3.13.48). Si la gestora (o la nota del cliente)
	 * eligió mensajero y sigue aprobado, se le asigna directo y NO sale al grupo. Si ya no está
	 * aprobado o la asignación falla, se publica la oferta como siempre. Sin elección: publish_offer().
	 */
	public static function dispatch_ready( WC_Order $order ): bool {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return false; }
		if ( self::refuse_test_order( $order ) ) { return false; }
		if ( self::supplier_hold( $order ) ) { return false; }
		$preferred_id = class_exists( 'CVD_Direct_Dispatch' ) ? absint( $order->get_meta( CVD_Direct_Dispatch::PREFERRED_META, true ) ) : 0;
		if ( ! $preferred_id || 'unassigned' !== self::status( $order ) || absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return self::publish_offer( $order ); }
		$messenger = CVD_Direct_Dispatch::preferred_messenger( $order );
		if ( $messenger && self::assign_direct( $order, $messenger ) ) { return true; }
		$fresh = wc_get_order( $order->get_id() );
		if ( ! $fresh ) { return false; }
		$chosen = get_userdata( $preferred_id );
		$fresh->add_order_note( $messenger
			? 'No se pudo asignar directo a ' . $messenger->display_name . '; el pedido sale al grupo de mensajeros.'
			: 'El mensajero elegido (' . ( $chosen ? $chosen->display_name : '#' . $preferred_id ) . ') ya no está aprobado; el pedido sale al grupo de mensajeros.' );
		$fresh->save();
		return self::publish_offer( $fresh );
	}

	/** Asignación directa pedida por la gestora: la misma transición «asignada» que la asignación manual de /mensajeria/. */
	public static function assign_direct( WC_Order $order, WP_User $messenger ): bool {
		if ( self::refuse_test_order( $order ) ) { return false; }
		if ( 'unassigned' !== self::status( $order ) || ! class_exists( 'CVD_Order_Transition_Service' ) || ! class_exists( 'CVD_Direct_Dispatch' ) ) { return false; }
		$requested_by = CVD_Direct_Dispatch::requested_by( $order );
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'assigned', array(
			'actor_user_id' => get_current_user_id(), 'source' => 'cvd_delivery_direct_assign',
			'metadata' => array( 'messenger' => $messenger->ID, 'direct' => true ),
			'precondition' => static function ( WC_Order $locked, string $current ) use ( $messenger ) {
				if ( 'unassigned' !== $current || absint( $locked->get_meta( '_cvd_messenger_user_id', true ) ) ) { return CVD_Order_Transition_Service::CONFLICT; }
				return CVD_Direct_Dispatch::is_valid_messenger( $messenger ) ? true : CVD_Order_Transition_Service::PRECONDITION_FAILED;
			},
			'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $actor, string $at ) use ( $messenger, $requested_by ): void {
				$locked->update_meta_data( '_cvd_messenger_user_id', $messenger->ID );
				$locked->update_meta_data( '_cvd_direct_assignment', 1 );
				$locked->update_meta_data( '_cvd_direct_assigned_at', $at );
				$locked->delete_meta_data( '_cvd_bot_direct_announced_at' );
				$locked->add_order_note( 'Entrega asignada directamente a ' . $messenger->display_name . ' (lo pidió ' . $requested_by . '). No se publicó en el grupo de mensajeros.' );
			},
			'after_commit' => static function ( WC_Order $saved, array $transition ) use ( $messenger ): void {
				self::centralized_after_commit( $saved, 'assigned' );
				if ( ! empty( $transition['idempotent_replay'] ) ) { return; }
				if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_offer( $saved, array( $messenger ) ); }
				if ( $messenger->user_email ) { wp_mail( $messenger->user_email, 'Nueva entrega Casa Viva · Pedido #' . $saved->get_order_number(), "Tienes una entrega asignada.\n\nRevisar:\n" . home_url( '/area-mensajeros/' ) ); }
			},
		) );
		return ! empty( $result['success'] ) && 'assigned' === ( $result['new_state'] ?? '' );
	}

	/**
	 * El mensajero de una entrega «asignada» la acepta sin pasar por la web: al dar la hora por
	 * WhatsApp o cuando la tienda le entrega el paquete. Actúa él mismo (Core valida que es el asignado).
	 */
	public static function accept_assigned( WC_Order $order, int $messenger_id, string $source, string $how ): bool {
		$messenger = $messenger_id ? get_userdata( $messenger_id ) : false;
		if ( ! $messenger || ! class_exists( 'CVD_Order_Transition_Service' ) ) { return false; }
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'accepted', array(
			'actor_user_id' => $messenger_id, 'source' => sanitize_key( $source ),
			'metadata' => array( 'messenger' => $messenger_id, 'source' => sanitize_key( $source ) ),
			'precondition' => static function ( WC_Order $locked, string $current ) use ( $messenger_id ) {
				return 'assigned' === $current && $messenger_id === absint( $locked->get_meta( '_cvd_messenger_user_id', true ) ) ? true : CVD_Order_Transition_Service::CONFLICT;
			},
			'atomic_mutation' => static function ( WC_Order $locked, $from, $to, $actor, string $at ) use ( $messenger, $how ): void {
				$locked->update_meta_data( '_cvd_delivery_accepted_at', $at );
				$locked->add_order_note( 'Carrera aceptada por ' . $messenger->display_name . ' ' . $how . '.' );
				update_user_meta( $messenger->ID, '_cvd_last_delivery_accepted_at', time() );
			},
			'after_commit' => static function ( WC_Order $saved ): void { self::centralized_after_commit( $saved, 'accepted' ); },
		) );
		return ! empty( $result['success'] );
	}

	public const AUTO_PICKUP_NOTE = 'automático: confirmó el mensajero';

	/**
	 * 3.13.55 (pedido de Nana: en días de mucho trabajo no da tiempo a marcar cada paso). El mensajero
	 * asignado confirma que ya tiene el paquete —escribe «entregado» por WhatsApp, avisa que lo recogió
	 * o pulsa «Ya lo recogí» en su área— y el paso de la dependienta «Entregado al mensajero» se marca
	 * solo, con la nota «automático: confirmó el mensajero». Una asignación sin aceptar se acepta antes.
	 * Nunca en pedidos de prueba. Devuelve: picked_up | already | test_order | not_assigned | too_early | error.
	 */
	public static function messenger_confirms_pickup( WC_Order $order, WP_User $messenger, string $source, string $how ): string {
		if ( self::is_test_order( $order ) ) { return 'test_order'; }
		if ( $messenger->ID !== absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return 'not_assigned'; }
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) ) { return 'error'; }
		$status = self::status( $order );
		if ( in_array( $status, array( 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed' ), true ) ) { return 'already'; }
		$source = sanitize_key( $source ) ?: 'cvd_delivery_messenger_pickup';
		if ( 'assigned' === $status ) {
			if ( ! self::accept_assigned( $order, $messenger->ID, $source, $how ) ) { return 'error'; }
			$order = wc_get_order( $order->get_id() );
			if ( ! $order ) { return 'error'; }
			$status = self::status( $order );
		}
		if ( ! in_array( $status, array( 'accepted', 'to_store' ), true ) ) { return 'too_early'; } // incidencia abierta, cancelado…
		$result = CVD_Order_Transition_Service::transition( $order->get_id(), 'delivery', 'picked_up', array(
			'actor_user_id'           => $messenger->ID,
			'idempotency_key'         => 'pickup:' . $order->get_id() . ':messenger:' . $messenger->ID,
			'source'                  => $source,
			'messenger_pickup'        => true,
			'metadata'                => array( 'method' => 'messenger_confirmed', 'auto' => true, 'note' => self::AUTO_PICKUP_NOTE ),
			'coupled_operation_state' => 'with_courier',
			'precondition'            => static fn( WC_Order $locked ) => self::is_test_order( $locked ) ? CVD_Order_Transition_Service::PRECONDITION_FAILED : true,
			'atomic_mutation'         => static function ( WC_Order $locked, $from, $to, $actor, string $at ) use ( $messenger, $how ): void {
				$locked->update_meta_data( '_cvd_handed_over_by', $messenger->ID );
				$locked->update_meta_data( '_cvd_handed_over_at', $at );
				$locked->update_meta_data( '_cvd_handed_over_auto', 'messenger' );
				$locked->add_order_note( 'Entregado al mensajero (' . self::AUTO_PICKUP_NOTE . '): ' . $messenger->display_name . ' ' . $how . '. La dependienta no tuvo que marcarlo.' );
			},
			'after_commit'            => static function ( WC_Order $saved ): void { self::centralized_after_commit( $saved, 'picked_up' ); },
		) );
		return ! empty( $result['success'] ) ? 'picked_up' : 'error';
	}

	/** Consecuencias posteriores (aviso web push, nota al cliente, reputación) para transiciones hechas fuera de esta clase, p. ej. VivaBot. */
	public static function after_commit_effects( WC_Order $order, string $next ): void {
		self::centralized_after_commit( $order, sanitize_key( $next ) );
	}

	private static function notify_offer( WC_Order $order, array $messengers ): void {
		if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_offer( $order, $messengers ); }
		foreach ( $messengers as $messenger ) {
			if ( ! $messenger->user_email ) { continue; }
			$zone = self::destination_zone( $order );
			$earning = self::courier_amount( $order );
			wp_mail(
				$messenger->user_email,
				'Nueva carrera disponible · Casa Viva',
				"Hay una entrega disponible.\n\nDestino: {$zone}\nGanancia: " . number_format_i18n( $earning, 0 ) . " CUP\n\nAbrir ofertas:\n" . home_url( '/area-mensajeros/#ofertas' )
			);
		}
	}

	public static function expand_offer( int $order_id ): void {
		$order=wc_get_order($order_id); if(!$order||'offered'!==self::status($order)||absint($order->get_meta('_cvd_messenger_user_id',true))){return;}
		if(self::refuse_test_order($order)){return;} // 3.13.71: el #3548 se amplió a 19 mensajeros más estando ya en la papelera
		$eligible=self::eligible_messengers($order); $invited=array_map('absint',(array)$order->get_meta('_cvd_delivery_invited_messengers',true));
		$new=array_values(array_filter($eligible,static fn(WP_User $user):bool=>!in_array($user->ID,$invited,true)));
		if(!$new){return;} $order->update_meta_data('_cvd_delivery_invited_messengers',array_values(array_unique(array_merge($invited,wp_list_pluck($new,'ID'))))); self::append_event($order,'offered','offered',array('wave_expanded'=>count($new))); $order->save(); self::notify_offer($order,$new);
	}

	private static function rank_snapshot( array $users, WC_Order $order ): array {
		if(!class_exists('CVD_Messenger_Reputation')){return array();} $snapshot=array(); foreach($users as $position=>$user){$score=CVD_Messenger_Reputation::score($user,$order);$snapshot[]=array('position'=>$position+1,'user_id'=>$user->ID,'score'=>$score['total']);} return $snapshot;
	}

	public static function offers_for( WP_User $messenger ): array {
		if ( 'yes' !== get_user_meta( $messenger->ID, '_cvd_messenger_available', true ) || 'approved' !== CVD_Registration::status_for( $messenger, 'mensajero' ) ) { return array(); }
		$orders = wc_get_orders( array( 'limit' => 30, 'orderby' => 'date', 'order' => 'ASC', 'meta_key' => '_cvd_delivery_status', 'meta_value' => 'offered' ) );
		$orders = array_values( array_filter( $orders, static function ( WC_Order $order ) use ( $messenger ): bool {
			$rejected = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) );
			$invited = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_invited_messengers', true ) );
			return '' === self::blocks_dispatch( $order ) && in_array( $messenger->ID, $invited, true ) && ! in_array( $messenger->ID, $rejected, true ) && ! absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		} ) );
		$messenger_zone = remove_accents( strtolower( (string) get_user_meta( $messenger->ID, '_cvd_zone', true ) ) );
		usort( $orders, static function ( WC_Order $a, WC_Order $b ) use ( $messenger_zone ): int {
			$score = static function ( WC_Order $order ) use ( $messenger_zone ): int {
				$destination = remove_accents( strtolower( self::destination_zone( $order ) ) );
				return $messenger_zone && ( false !== strpos( $destination, $messenger_zone ) || false !== strpos( $messenger_zone, $destination ) ) ? 1 : 0;
			};
			return $score( $b ) <=> $score( $a );
		} );
		return $orders;
	}

	public static function offer_action_url( int $order_id, string $decision ): string {
		$action = 'cvd_delivery_offer_' . $order_id . '_' . $decision;
		return add_query_arg(
			array(
				'action'    => 'cvd_delivery_offer',
				'order_id'  => $order_id,
				'decision'  => $decision,
				'_wpnonce'  => wp_create_nonce( $action ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function offer_decision(): void {
		if ( ! is_user_logged_in() ) { wp_die( 'Debes iniciar sesión.' ); }
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_GET['decision'] ?? '' ) );
		check_admin_referer( 'cvd_delivery_offer_' . $order_id . '_' . $decision );
		$user = wp_get_current_user();
		if ( ! CVD_Registration::is_approved_messenger( $user ) ) { wp_die( 'Esta cuenta no es un mensajero aprobado.' ); }
		if ( ! in_array( $decision, array( 'accept', 'decline' ), true ) ) { wp_die( 'Decisión no válida.' ); }
		if ( 'accept' === $decision ) {
			$transition=CVD_Order_Transition_Service::transition($order_id,'delivery','accepted',array(
				'actor_user_id'=>$user->ID,'idempotency_key'=>sanitize_text_field((string)($_GET['idempotency_key']??'')),'source'=>'cvd_delivery_offer_decision','metadata'=>array('messenger'=>$user->ID,'source'=>'open_offer'),
				'precondition'=>static function(WC_Order $order,string $current)use($user){if(self::is_test_order($order)){return CVD_Order_Transition_Service::PRECONDITION_FAILED;}$assigned=absint($order->get_meta('_cvd_messenger_user_id',true));if($assigned&&$assigned!==$user->ID){return CVD_Order_Transition_Service::CONFLICT;}if('offered'!==$current){return CVD_Order_Transition_Service::CONFLICT;}$invited=array_map('absint',(array)$order->get_meta('_cvd_delivery_invited_messengers',true));return in_array($user->ID,$invited,true)&&'yes'===get_user_meta($user->ID,'_cvd_messenger_available',true)?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},
				'atomic_mutation'=>static function(WC_Order $order,$from,$to,$actor,$at)use($user):void{$order->update_meta_data('_cvd_messenger_user_id',$user->ID);$order->update_meta_data('_cvd_delivery_accepted_at',$at);$order->add_order_note('Carrera aceptada por '.$user->display_name.'.');update_user_meta($user->ID,'_cvd_last_delivery_accepted_at',time());},
				'after_commit'=>static function(WC_Order $order):void{self::centralized_after_commit($order,'accepted');},
			));
			$result=!empty($transition['success'])?'accepted':'unavailable';
			wp_safe_redirect(add_query_arg('oferta',$result,home_url('/area-mensajeros/'))); exit;
		}
		$result = self::with_order_lock( $order_id, static function () use ( $order_id, $decision, $user ): string {
			$order = wc_get_order( $order_id );
			if ( ! $order || 'offered' !== self::status( $order ) || absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return 'unavailable'; }
			$invited = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_invited_messengers', true ) );
			if ( ! in_array( $user->ID, $invited, true ) ) { return 'unavailable'; }
			$rejected = array_values( array_unique( array_merge( array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) ), array( $user->ID ) ) ) );
			$order->update_meta_data( '_cvd_delivery_rejected_by', $rejected ); self::append_event( $order, 'offered', 'offered', array( 'decision' => 'declined', 'messenger' => $user->ID ) ); $order->save(); return 'declined';
		} );
		wp_safe_redirect( add_query_arg( 'oferta', $result ?: 'unavailable', home_url( '/area-mensajeros/' ) ) );
		exit;
	}

	private static function with_order_lock( int $order_id, callable $callback ) {
		global $wpdb;
		$key = 'cvd_delivery_' . $order_id;
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) );
		if ( 1 !== $locked ) { return 'unavailable'; }
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	public static function pickup_url( WC_Order $order ): string {
		$accepted = (string) $order->get_meta( '_cvd_delivery_accepted_at', true );
		$messenger = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $accepted || ! $messenger || ! in_array( self::status( $order ), array( 'accepted', 'to_store' ), true ) ) { return ''; }
		$token = strtoupper( substr( hash_hmac( 'sha256', $order->get_id() . '|' . $messenger . '|' . $accepted, wp_salt( 'auth' ) ), 0, 24 ) );
		return add_query_arg( array( 'action' => 'cvd_delivery_pickup', 'order_id' => $order->get_id(), 'token' => $token ), admin_url( 'admin-post.php' ) );
	}

	public static function confirm_pickup(): void {
		if ( ! is_user_logged_in() || ( ! current_user_can( 'cvd_manage_sales' ) && ! current_user_can( 'manage_woocommerce' ) ) ) { wp_die( 'Solo Casa Viva puede confirmar la recogida.' ); }
		$order_id = absint( $_GET['order_id'] ?? 0 );
		$provided = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$order = wc_get_order( $order_id );
		$expected_url = $order ? self::pickup_url( $order ) : '';
		$expected = (string) wp_parse_url( $expected_url, PHP_URL_QUERY ); parse_str( $expected, $query );
		$result = $order && $provided && hash_equals( (string) ( $query['token'] ?? '' ), $provided ) && self::handover_by_staff( $order, get_current_user_id(), 'pickup_qr' ) ? 'confirmed' : 'invalid';
		wp_safe_redirect( add_query_arg( array( 'recogida' => $result, 'order' => $order_id ), home_url( '/mensajeria/' ) ) );
		exit;
	}

	/** Casa Viva confirma la custodia física; nunca la adelanta el mensajero. */
	public static function handover_by_staff( WC_Order $order, int $user_id, string $source = 'manual' ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ( ! user_can( $user, 'cvd_manage_sales' ) && ! user_can( $user, 'manage_woocommerce' ) ) ) { return false; }
		// Asignación directa sin «Aceptar» todavía: recoger el paquete en la tienda equivale a aceptarlo.
		if ( 'assigned' === self::status( $order ) && class_exists( 'CVD_Direct_Dispatch' ) && CVD_Direct_Dispatch::is_direct( $order ) ) {
			if ( ! self::accept_assigned( $order, absint( $order->get_meta( '_cvd_messenger_user_id', true ) ), 'cvd_delivery_direct_pickup', 'al recogerlo en la tienda' ) ) { return false; }
		}
		$method=sanitize_key($source)?:'manual';
		$result=CVD_Order_Transition_Service::transition($order->get_id(),'delivery','picked_up',array(
			'actor_user_id'=>$user_id,'idempotency_key'=>'pickup:'.$order->get_id().':'.$method,'source'=>'cvd_delivery_handover_by_staff','metadata'=>array('verified_by'=>$user_id,'method'=>$method),'coupled_operation_state'=>'with_courier',
			'precondition'=>static fn(WC_Order $locked_order)=>absint($locked_order->get_meta('_cvd_messenger_user_id',true))?true:CVD_Order_Transition_Service::PRECONDITION_FAILED,
			'atomic_mutation'=>static function(WC_Order $locked_order,$from,$to,$actor,$at)use($user_id):void{$locked_order->update_meta_data('_cvd_handed_over_by',$user_id);$locked_order->update_meta_data('_cvd_handed_over_at',$at);},
			'after_commit'=>static function(WC_Order $saved_order):void{self::centralized_after_commit($saved_order,'picked_up');},
		));
		return !empty($result['success']);
	}

	public static function change_status(): void {
		if ( ! is_user_logged_in() ) { wp_die( 'Debes iniciar sesión.' ); }
		$order_id = absint( $_REQUEST['order_id'] ?? 0 ); $next = sanitize_key( $_REQUEST['status'] ?? '' );
		check_admin_referer( 'cvd_delivery_' . $order_id . '_' . $next );
		$order = wc_get_order( $order_id ); if ( ! $order || ! in_array( $next, self::STATUSES, true ) ) { wp_die( 'Pedido o estado no válido.' ); }
		$current = self::status( $order ); $user = wp_get_current_user();
		if ( ! self::allowed_for_user( $order, $user, $current, $next ) ) { wp_die( 'No tienes permiso para realizar este cambio.' ); }
		if ( 'offered' === $next ) {
			// 3.14.17: «Ofertar» a mano en /mensajeria/ es una decisión explícita: también publica los de proveedor.
			if ( class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::order_has_market_items( $order ) && '' === (string) $order->get_meta( self::SUPPLIER_DISPATCH_OK, true ) ) {
				$order->update_meta_data( self::SUPPLIER_DISPATCH_OK, current_time( 'mysql', true ) );
				$order->add_order_note( 'Pedido con producto de proveedor publicado a mano en el grupo de mensajeros por ' . $user->display_name . '.' );
				$order->save();
			}
			self::dispatch_ready( $order ); // sin mensajero elegido por la gestora = publish_offer() de siempre
			wp_safe_redirect( home_url( '/mensajeria/?actualizado=1' ) ); exit;
		}
		// 3.13.55: «Ya lo recogí» del propio mensajero = «Entregado al mensajero» automático (la tienda sigue pudiendo marcarlo).
		if ( 'picked_up' === $next && ! user_can( $user, 'cvd_manage_sales' ) && ! user_can( $user, 'manage_woocommerce' ) ) {
			$picked = self::messenger_confirms_pickup( $order, $user, 'cvd_delivery_messenger_area', 'pulsó «Ya lo recogí» en su área' );
			if ( ! in_array( $picked, array( 'picked_up', 'already' ), true ) ) { wp_die( 'No se pudo marcar como recogido. Avisa a la tienda.' ); }
			wp_safe_redirect( home_url( '/area-mensajeros/?actualizado=1' ) ); exit;
		}
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( 'incident' === $next && '' === trim( $note ) ) { wp_die( 'Describe brevemente la incidencia para poder registrarla.' ); }
		$collection_method = sanitize_key( (string) ( $_POST['collection_method'] ?? '' ) );
		$collected_usd = (float) wc_format_decimal( wp_unslash( $_POST['collected_usd'] ?? 0 ), 2 );
		$collected_cup = (float) wc_format_decimal( wp_unslash( $_POST['collected_cup'] ?? 0 ), 2 );
		$collection_note = sanitize_textarea_field( wp_unslash( $_POST['collection_note'] ?? '' ) );
		$collection_allocations = array();
		foreach ( (array) ( $_POST['collection_allocations'] ?? array() ) as $obligation_id => $amount ) { $collection_allocations[] = array( 'id' => sanitize_key( (string) $obligation_id ), 'amount' => wc_format_decimal( wp_unslash( $amount ), 2 ) ); }
		$is_messenger = in_array( 'cvd_messenger', (array) $user->roles, true );
		$structured_collectible = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::customer_collectible( $order ) : array();
		// 3.13.67: Zelle/PayPal ya pagados por adelantado: el mensajero no cobra productos, así que puede marcar con 0.
		$prepaid = class_exists( 'CVD_Cuban_Checkout' ) && CVD_Cuban_Checkout::is_prepaid( $order );
		if ( 'delivered' === $next && $is_messenger && ( ! isset( $_POST['money_confirmed'] ) || ( ! $structured_collectible && ( ! in_array( $collection_method, array( 'cash_usd', 'cash_cup', 'transfer', 'mixed', 'other' ), true ) || ( $collected_usd <= 0 && $collected_cup <= 0 && ! $prepaid ) ) ) ) ) { wp_die( 'Confirma el medio y los importes realmente recibidos antes de marcar la entrega.' ); }
		if ( 'delivered' === $next && $is_messenger && $structured_collectible ) { $checked = CVD_Payment_Obligations::validate_customer_allocations( $order, $collection_allocations ); if ( is_wp_error( $checked ) ) { wp_die( esc_html( $checked->get_error_message() ) ); } }
		if(class_exists('CVD_Order_Transition_Service')&&('incident'===$next||'incident'===$current)){$key=sanitize_text_field((string)($_REQUEST['idempotency_key']??''));$result='incident'===$next?CVD_Order_Transition_Service::open_incident($order_id,'delivery',array('actor_user_id'=>$user->ID,'idempotency_key'=>$key,'note'=>$note)):CVD_Order_Transition_Service::resolve_incident($order_id,'delivery',array('actor_user_id'=>$user->ID,'idempotency_key'=>$key,'note'=>$note));if(empty($result['success'])||('incident'===$current&&$next!==$result['new_state'])){wp_die('No se pudo actualizar la incidencia sin inventar una etapa.');}$destination=in_array('cvd_messenger',(array)$user->roles,true)?'/area-mensajeros/':'/mensajeria/';wp_safe_redirect(home_url($destination.'?actualizado=1'));exit;}
		if ( class_exists('CVD_Order_Transition_Service') && CVD_Order_Transition_Service::governs('delivery',$current,$next) ) {
			$idempotency_key=sanitize_text_field((string)($_REQUEST['idempotency_key']??''))?:'delivery:'.$order_id.':'.$current.':'.$next;
			$payment_target='delivered'===$next?'pending_return':('cash_returned'===$next?'returned':('closed'===$next?'verified':''));
			$collection_metadata = 'delivered' === $next && $structured_collectible ? array( 'collection_method' => 'allocated', 'obligations' => $collection_allocations ) : ( 'delivered' === $next && $collection_method ? array( 'collection_method' => $collection_method, 'collected_usd' => $collected_usd, 'collected_cup' => $collected_cup ) : array() );
			$result=CVD_Order_Transition_Service::transition($order_id,'delivery',$next,array(
				'actor_user_id'=>$user->ID,'idempotency_key'=>$idempotency_key,'source'=>'cvd_delivery_change_status','metadata'=>array_merge(array('note'=>$note),$collection_metadata),'coupled_operation_state'=>'picked_up'===$next?'with_courier':('closed'===$next?'delivered':''),'coupled_payment_state'=>$payment_target,
				'precondition'=>static function(WC_Order $locked_order,$from,$to){if(!absint($locked_order->get_meta('_cvd_messenger_user_id',true))){return CVD_Order_Transition_Service::PRECONDITION_FAILED;}$cash=sanitize_key((string)$locked_order->get_meta('_cvd_cash_status',true));if('cash_returned'===$to&&!in_array($cash,array('','pending_return'),true)){return CVD_Order_Transition_Service::PRECONDITION_FAILED;}if('closed'===$to&&!in_array($cash,array('','returned'),true)){return CVD_Order_Transition_Service::PRECONDITION_FAILED;}return true;},
				'atomic_mutation'=>static function(WC_Order $locked_order,$from,$to,WP_User $actor,string $at)use($collection_method,$collected_usd,$collected_cup,$collection_note,$collection_allocations):void{if('accepted'===$to){$locked_order->update_meta_data('_cvd_delivery_accepted_at',$at);update_user_meta($actor->ID,'_cvd_last_delivery_accepted_at',time());}if('to_store'===$to){$locked_order->update_meta_data('_cvd_to_store_at',$at);}if('picked_up'===$to){$locked_order->update_meta_data('_cvd_handed_over_by',$actor->ID);$locked_order->update_meta_data('_cvd_handed_over_at',$at);}if('handed_over'===$to){$locked_order->update_meta_data('_cvd_to_customer_at',$at);}if('delivered'===$to){$locked_order->update_meta_data('_cvd_delivered_by',$actor->ID);$locked_order->update_meta_data('_cvd_delivered_at',$at);if(class_exists('CVD_Payment_Obligations')&&CVD_Payment_Obligations::for_order($locked_order)){ $settled=CVD_Payment_Obligations::settle_customer_allocations($locked_order,$collection_allocations,$actor->ID,$at);if(is_wp_error($settled)){throw new RuntimeException($settled->get_error_message());}$totals=array();foreach(CVD_Payment_Obligations::for_order($locked_order) as $obligation){if('customer'===($obligation['payer']??'')&&'settled'===($obligation['status']??'')){$currency=strtoupper((string)$obligation['currency']);$totals[$currency]=($totals[$currency]??0)+(float)$obligation['amount'];}}$locked_order->update_meta_data('_cvd_collection_method','allocated');$locked_order->update_meta_data('_cvd_collection_amount_usd',wc_format_decimal($totals['USD']??0,2));$locked_order->update_meta_data('_cvd_collection_amount_cup',wc_format_decimal($totals['CUP']??0,2));$locked_order->update_meta_data('_cvd_collection_note',$collection_note);$locked_order->update_meta_data('_cvd_collection_received_by',$actor->ID);$locked_order->update_meta_data('_cvd_collection_received_at',$at);}elseif($collection_method){$locked_order->update_meta_data('_cvd_collection_method',$collection_method);$locked_order->update_meta_data('_cvd_collection_amount_usd',wc_format_decimal($collected_usd,2));$locked_order->update_meta_data('_cvd_collection_amount_cup',wc_format_decimal($collected_cup,2));$locked_order->update_meta_data('_cvd_collection_note',$collection_note);$locked_order->update_meta_data('_cvd_collection_received_by',$actor->ID);$locked_order->update_meta_data('_cvd_collection_received_at',$at);}}if('cash_returned'===$to){$locked_order->update_meta_data('_cvd_cash_returned_by',$actor->ID);$locked_order->update_meta_data('_cvd_cash_returned_at',$at);$locked_order->update_meta_data('_cvd_commission_review_ready','yes');}if('closed'===$to){self::maybe_fail_closeout('before_payment');$locked_order->update_meta_data('_cvd_cash_verified_by',$actor->ID);$locked_order->update_meta_data('_cvd_cash_verified_at',$at);}},
				'atomic_after_state'=>'closed'===$next?array(__CLASS__,'atomic_closeout'):null,
				'after_commit'=>static function(WC_Order $saved_order)use($next):void{self::centralized_after_commit($saved_order,$next);},
			));
			if(empty($result['success'])){wp_die('No se pudo actualizar la entrega.');}
			$destination=in_array('cvd_messenger',(array)$user->roles,true)?'/area-mensajeros/':'/mensajeria/'; wp_safe_redirect(home_url($destination.'?actualizado=1')); exit;
		}
		self::transition( $order, $next, array( 'note' => $note ) );
		if ( 'accepted' === $next ) { $order->update_meta_data( '_cvd_delivery_accepted_at', current_time( 'mysql', true ) ); update_user_meta( $user->ID, '_cvd_last_delivery_accepted_at', time() ); }
		if ( 'to_store' === $next ) { $order->update_meta_data( '_cvd_to_store_at', current_time( 'mysql', true ) ); }
		$operation_event = null;
		if ( 'picked_up' === $next ) { $order->update_meta_data( '_cvd_handed_over_by', $user->ID ); $order->update_meta_data( '_cvd_handed_over_at', current_time( 'mysql', true ) ); $operation_event = self::sync_operation( $order, 'with_courier' ); }
		if ( 'handed_over' === $next ) { $order->update_meta_data( '_cvd_to_customer_at', current_time( 'mysql', true ) ); }
		if ( 'delivered' === $next ) { $order->update_meta_data( '_cvd_delivered_by', $user->ID ); $order->update_meta_data( '_cvd_delivered_at', current_time( 'mysql', true ) ); $order->update_meta_data( '_cvd_cash_status', 'pending_return' ); }
		if ( 'cash_returned' === $next ) { $order->update_meta_data( '_cvd_cash_status', 'returned' ); $order->update_meta_data( '_cvd_cash_returned_by', $user->ID ); $order->update_meta_data( '_cvd_cash_returned_at', current_time( 'mysql', true ) ); $order->update_meta_data( '_cvd_commission_review_ready', 'yes' ); }
		if ( 'closed' === $next ) { $order->update_meta_data( '_cvd_cash_status', 'verified' ); $order->update_meta_data( '_cvd_cash_verified_by', $user->ID ); $order->update_meta_data( '_cvd_cash_verified_at', current_time( 'mysql', true ) ); $order->update_meta_data( '_cvd_messenger_earning_status', 'approved' ); if ( ! $order->has_status( 'completed' ) ) { $order->update_status( 'completed', 'Operación verificada y cerrada por Casa Viva.' ); } }
		$order->save();
		if ( $operation_event ) { do_action( 'cvd_order_transition_observed', $order->get_id(), 'operation', $operation_event['from'], $operation_event['to'], 'cvd_delivery_sync_operation', array(), $operation_event['event_anchor'] ); }
		$cash_map = array( 'delivered'=>array('', 'pending_return', '_cvd_delivered_at'), 'cash_returned'=>array('pending_return','returned','_cvd_cash_returned_at'), 'closed'=>array('returned','verified','_cvd_cash_verified_at') );
		if ( isset( $cash_map[ $next ] ) ) { do_action( 'cvd_order_transition_observed', $order->get_id(), 'payment', $cash_map[$next][0], $cash_map[$next][1], 'cvd_delivery_cash_status', array(), (string) $order->get_meta( $cash_map[$next][2], true ) ); }
		if ( 'closed' === $next && class_exists( 'CVD_Messenger_Accounting' ) ) { CVD_Messenger_Accounting::credit_order( $order ); }
		if ( 'closed' === $next && class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_approved( $order->get_id() ); }
		$destination = in_array( 'cvd_messenger', (array) $user->roles, true ) ? '/area-mensajeros/' : '/mensajeria/';
		wp_safe_redirect( home_url( $destination . '?actualizado=1' ) ); exit;
	}

	/**
	 * 3.14.38 (Lennys 18-oct: «que el mensajero avise sencillo y que sean las dependientas quienes cierren»): el mensajero
	 * pagó en la tienda pero no marcó «entregado» (sin carga, sin conexión o con prisa). Al registrar el dinero, la tienda
	 * pasa el reparto a «entregado» sin esperarlo; después se cierra como siempre y la gestora recibe su aviso.
	 */
	public static function store_marks_delivered( WC_Order $order ): bool {
		$user_id = get_current_user_id();
		foreach ( array( 'handed_over', 'delivered' ) as $step ) {
			$current = self::status( wc_get_order( $order->get_id() ) );
			if ( 'delivered' === $current ) { return true; }
			if ( 'handed_over' === $step && 'picked_up' !== $current ) { continue; }
			$result=CVD_Order_Transition_Service::transition($order->get_id(),'delivery',$step,array(
				'actor_user_id'=>$user_id,'idempotency_key'=>'store-money:'.$order->get_id().':'.$step,'source'=>'sales_money_received_by_store','store_money_received'=>true,
				'metadata'=>array('note'=>'La tienda recibió el dinero; el mensajero no lo había marcado.'),'coupled_payment_state'=>'delivered'===$step?'pending_return':'',
				'precondition'=>static fn(WC_Order $locked)=>absint($locked->get_meta('_cvd_messenger_user_id',true))?true:CVD_Order_Transition_Service::PRECONDITION_FAILED,
				'atomic_mutation'=>static function(WC_Order $locked,$from,$to,WP_User $actor,string $at):void{if('handed_over'===$to){$locked->update_meta_data('_cvd_to_customer_at',$at);}if('delivered'===$to){$locked->update_meta_data('_cvd_delivered_by',$actor->ID);$locked->update_meta_data('_cvd_delivered_at',$at);$locked->update_meta_data('_cvd_delivered_marked_by_store','yes');}},
				'after_commit'=>static function(WC_Order $saved)use($step):void{self::centralized_after_commit($saved,$step);},
			));
			if ( empty( $result['success'] ) ) { return false; }
		}
		return 'delivered' === self::status( wc_get_order( $order->get_id() ) );
	}

	/** Cierra la custodia cuando la tienda confirma el dinero y deja las comisiones aprobadas, no pagadas. */
	public static function close_after_cash_received( WC_Order $order ): bool {
		$current=self::status($order);
		if('closed'===$current){return 'verified'===$order->get_meta('_cvd_cash_status',true)&&$order->has_status('completed');}
		if(!in_array($current,array('delivered','cash_returned'),true)){return false;}
		$user_id = get_current_user_id();
		if('delivered'===$current){$cash=CVD_Order_Transition_Service::transition($order->get_id(),'delivery','cash_returned',array('actor_user_id'=>$user_id,'idempotency_key'=>'closeout:'.$order->get_id().':cash_returned','source'=>'sales_money_confirmation','coupled_payment_state'=>'returned','precondition'=>static function(WC_Order $locked){$cash=sanitize_key((string)$locked->get_meta('_cvd_cash_status',true));return absint($locked->get_meta('_cvd_messenger_user_id',true))&&in_array($cash,array('','pending_return'),true)?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},'atomic_mutation'=>static function(WC_Order $locked,$from,$to,WP_User $actor,string $at):void{$locked->update_meta_data('_cvd_cash_returned_by',$actor->ID);$locked->update_meta_data('_cvd_cash_returned_at',$at);$locked->update_meta_data('_cvd_commission_review_ready','yes');}));if(empty($cash['success'])){return false;}}
		$closed=CVD_Order_Transition_Service::transition($order->get_id(),'delivery','closed',array('actor_user_id'=>$user_id,'idempotency_key'=>'closeout:'.$order->get_id().':closed','source'=>'automatic_after_money_confirmation','allow_staff_closeout'=>true,'coupled_operation_state'=>'delivered','coupled_payment_state'=>'verified','precondition'=>static function(WC_Order $locked){$cash=sanitize_key((string)$locked->get_meta('_cvd_cash_status',true));return absint($locked->get_meta('_cvd_messenger_user_id',true))&&in_array($cash,array('','returned'),true)?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},'atomic_mutation'=>static function(WC_Order $locked,$from,$to,WP_User $actor,string $at):void{self::maybe_fail_closeout('before_payment');$locked->update_meta_data('_cvd_cash_verified_by',$actor->ID);$locked->update_meta_data('_cvd_cash_verified_at',$at);},'atomic_after_state'=>array(__CLASS__,'atomic_closeout'),'after_commit'=>static function(WC_Order $saved):void{self::centralized_after_commit($saved,'closed');}));
		return !empty($closed['success']);
	}

	/** Persistencia financiera interna del cierre, todavía dentro de MariaDB. */
	public static function atomic_closeout( WC_Order $order, string $from, string $to, WP_User $actor, string $at, string $anchor ): array {
		self::maybe_fail_closeout('after_payment');
		$order->update_meta_data('_cvd_messenger_earning_status','approved');
		if(!class_exists('CVD_Messenger_Accounting')){throw new RuntimeException('messenger_accounting_unavailable');}
		CVD_Messenger_Accounting::credit_order_atomic($order,$actor->ID,$at);
		self::maybe_fail_closeout('after_ledger');
		$events=array();
		if(class_exists('CVD_Payment_Obligations')){foreach(CVD_Payment_Obligations::post_commission_deductions($order,$actor->ID,$at) as $deduction){$events[]=$deduction;}}
		if(class_exists('CVD_Commissions')){$commission=CVD_Commissions::approve_for_closeout($order,$actor->ID,$at,$anchor.':commission');if($commission){$events[]=$commission;}}
		self::maybe_fail_closeout('after_commission');
		if(!$order->has_status('completed')){$order->set_status('completed','Operación verificada y cerrada por Casa Viva.');}
		self::maybe_fail_closeout('after_woocommerce');
		return array('events'=>$events);
	}

	private static function maybe_fail_closeout(string $stage):void{
		if(function_exists('apply_filters')&&$stage===(string)apply_filters('cvd_order_closeout_failure_stage','')){throw new RuntimeException('closeout_failure_'.$stage);}
	}

	private static function allowed_for_user( WC_Order $order, WP_User $user, string $current, string $next ): bool {
		$is_admin = user_can( $user, 'manage_woocommerce' ); $is_staff = user_can( $user, 'cvd_manage_sales' );
		$is_messenger = in_array( 'cvd_messenger', (array) $user->roles, true ) && $user->ID === absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$map = array(
			'unassigned' => array( 'offered', 'incident' ), 'offered' => array( 'assigned', 'incident' ),
			'assigned' => array( 'accepted', 'incident' ), 'accepted' => array( 'to_store', 'picked_up', 'incident' ),
			'to_store' => array( 'picked_up', 'incident' ),
			'picked_up' => array( 'handed_over', 'incident' ),
			'handed_over' => array( 'delivered', 'failed', 'returned', 'incident' ),
			'delivered' => array( 'cash_returned', 'incident' ), 'cash_returned' => array( 'closed', 'incident' ),
			'incident' => array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'delivered', 'returned', 'failed' ),
		);
		if ( ! in_array( $next, $map[ $current ] ?? array(), true ) ) { return false; }
		if ( $is_admin ) { return true; }
		if ( $is_messenger ) { return in_array( $next, array( 'accepted', 'to_store', 'picked_up', 'handed_over', 'delivered', 'failed', 'returned', 'incident' ), true ) && ! ( 'picked_up' === $next && self::is_test_order( $order ) ); }
		if ( $is_staff ) { return in_array( $next, array( 'offered', 'picked_up', 'cash_returned', 'incident' ), true ); }
		return false;
	}

	private static function transition( WC_Order $order, string $next, array $data = array() ): void {
		$current = self::status( $order );
		self::append_event( $order, $current, $next, $data );
		$order->update_meta_data( '_cvd_delivery_status', $next ); $order->update_meta_data( '_cvd_delivery_updated_at', current_time( 'mysql', true ) );
		$order->add_order_note( 'Mensajería: ' . self::label( $current ) . ' → ' . self::label( $next ) . '.' ); $order->save();
		$history = $order->get_meta( '_cvd_delivery_history', true ); $last_event = is_array( $history ) ? end( $history ) : array();
		do_action( 'cvd_order_transition_observed', $order->get_id(), 'delivery', $current, $next, 'cvd_delivery_transition', $data, (string) ( $last_event['data']['_canonical_event_anchor'] ?? $last_event['at'] ?? '' ) );
		if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_delivery_update( $order, $next ); }
		if ( class_exists( 'CVD_Messenger_Reputation' ) ) { $messenger_id=absint($order->get_meta('_cvd_messenger_user_id',true)); if($messenger_id){CVD_Messenger_Reputation::invalidate($messenger_id);} }
	}

	/** Consecuencias posteriores; nunca se ejecutan durante la transacción SQL. */
	private static function centralized_after_commit( WC_Order $order, string $next ): void {
		if ( class_exists( 'CVD_Web_Push' ) ) { CVD_Web_Push::send_delivery_update( $order, $next ); }
		if ( class_exists( 'CVD_Messenger_Reputation' ) ) { $messenger_id=absint($order->get_meta('_cvd_messenger_user_id',true)); if($messenger_id){CVD_Messenger_Reputation::invalidate($messenger_id);} }
	}

	private static function append_event( WC_Order $order, string $from, string $to, array $data = array() ): void {
		$events = $order->get_meta( '_cvd_delivery_history', true ); $events = is_array( $events ) ? $events : array();
		if ( empty( $data['_canonical_event_anchor'] ) ) { $data['_canonical_event_anchor'] = wp_generate_uuid4(); }
		$events[] = array( 'from' => $from, 'to' => $to, 'actor_user_id' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'data' => $data );
		$order->update_meta_data( '_cvd_delivery_history', array_slice( $events, -150 ) );
	}

	private static function sync_operation( WC_Order $order, string $status ): array {
		$current = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ?: 'new';
		$history = $order->get_meta( '_cvd_operation_history', true ); $history = is_array( $history ) ? $history : array();
		$event = array( 'from' => $current, 'to' => $status, 'user_id' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'event_anchor' => wp_generate_uuid4() );
		$history[] = $event;
		$order->update_meta_data( '_cvd_operation_status', $status );
		$order->update_meta_data( '_cvd_operation_updated_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_operation_history', array_slice( $history, -100 ) );
		return $event;
	}

	public static function action_url( int $order_id, string $status ): string { return wp_nonce_url( admin_url( 'admin-post.php?action=cvd_delivery_status&order_id=' . $order_id . '&status=' . $status ), 'cvd_delivery_' . $order_id . '_' . $status ); }
	public static function status( WC_Order $order ): string { if('yes'===$order->get_meta('_cvd_delivery_incident_active',true)){return 'incident';}$value = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ); return in_array( $value, self::STATUSES, true ) ? $value : 'unassigned'; }
	public static function label( string $status ): string { $labels = array( 'unassigned'=>'Por ofertar','offered'=>'Oferta abierta','assigned'=>'Asignada','accepted'=>'Aceptada','to_store'=>'Va a recoger','picked_up'=>'Entregado al mensajero','handed_over'=>'En camino al cliente','delivered'=>'Entregada · dinero pendiente','cash_returned'=>'Dinero recibido','closed'=>'Cerrada','incident'=>'Con incidencia','failed'=>'No entregada','returned'=>'Devuelta a tienda','cancelled'=>'Cancelada' ); return $labels[ $status ] ?? ucfirst( $status ); }
	/** Mensajeros aprobados: se filtra por su estado de mensajero (en una cuenta doble no es _cvd_account_status). */
	private static function messengers(): array { return array_values( array_filter( get_users( array( 'role' => 'cvd_messenger', 'orderby' => 'display_name' ) ), array( 'CVD_Registration', 'is_approved_messenger' ) ) ); }
	public static function available_messengers(): array { return array_values( array_filter( self::messengers(), static fn( WP_User $user ): bool => 'yes' === get_user_meta( $user->ID, '_cvd_messenger_available', true ) ) ); }
	private static function eligible_messengers( WC_Order $order ): array {
		$users = self::available_messengers();
		if ( class_exists( 'CVD_Messenger_Reputation' ) ) { return CVD_Messenger_Reputation::rank( $users, $order ); }
		return $users;
	}
	public static function destination_zone( WC_Order $order ): string { return trim( (string) ( $order->get_meta( '_cvd_locality', true ) ?: $order->get_billing_city() ?: $order->get_billing_state() ) ) ?: 'Zona por confirmar'; }
	public static function courier_amount( WC_Order $order ): float { return (float) $order->get_meta( '_cvd_shipping_courier_amount_cup', true ); }
	public static function zone_matches( WP_User $messenger, WC_Order $order ): bool {
		$preferred = remove_accents( strtolower( trim( (string) get_user_meta( $messenger->ID, '_cvd_zone', true ) ) ) );
		$destination = remove_accents( strtolower( self::destination_zone( $order ) ) );
		return '' !== $preferred && '' !== $destination && ( false !== strpos( $destination, $preferred ) || false !== strpos( $preferred, $destination ) );
	}

	/** 3.14.53 (#48): con ?ref= de la gestora del pedido (sin gestora, sin ref): es el enlace que ve el cliente. */
	public static function tracking_url( WC_Order $order ): string {
		$url = add_query_arg( array( 'pedido' => $order->get_id(), 'clave' => $order->get_order_key() ), home_url( '/seguimiento/' ) );
		return class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::with_order_ref( $url, $order ) : $url;
	}

	/** 3.14.53 (#48): «Seguir comprando» del cliente: la tienda con el código de su gestora (sin gestora, la de Casa Viva). */
	public static function shop_url( WC_Order $order ): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' );
		return class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::with_order_ref( $url, $order ) : $url;
	}

	private static function tracking_order(): ?WC_Order {
		$order_id = absint( $_GET['pedido'] ?? 0 );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) { return null; }
		$key = sanitize_text_field( wp_unslash( $_GET['clave'] ?? '' ) );
		$allowed = $key && hash_equals( $order->get_order_key(), $key );
		if ( is_user_logged_in() ) {
			$allowed = $allowed || get_current_user_id() === (int) $order->get_customer_id() || current_user_can( 'cvd_manage_sales' ) || current_user_can( 'manage_woocommerce' );
		}
		return $allowed ? $order : null;
	}

	/** Intentos de «Mi pedido» por conexión cada media hora (número + teléfono): frena a quien pruebe números al azar. */
	private const LOOKUP_LIMIT = 8;

	/**
	 * 3.13.89 (auditoría de cliente 13-oct, punto 18: quien compró sin cuenta no veía su pedido).
	 * /seguimiento/ es personal: nunca en caché. Y recibe el formulario «Mi pedido» (número + teléfono, por POST para que
	 * el teléfono no quede en la dirección). Si coinciden, lleva al enlace de seguimiento del pedido (el de su clave, el
	 * mismo del vale). Si no, un aviso genérico que no dice si el número existe.
	 */
	public static function tracking_page_request(): void {
		if ( ! is_page( 'seguimiento' ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva seguimiento: pedido personal' );
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['cvd_lookup_order'] ) ) { return; }
		$back = home_url( '/seguimiento/' );
		$key = 'cvd_lookup_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) );
		$tries = (int) get_transient( $key );
		if ( $tries >= self::LOOKUP_LIMIT ) { wp_safe_redirect( add_query_arg( 'aviso', 'espera', $back ), 303 ); exit; }
		set_transient( $key, $tries + 1, 30 * MINUTE_IN_SECONDS );
		$number = absint( preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['cvd_lookup_order'] ) ) );
		$phone = substr( preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['cvd_lookup_phone'] ?? '' ) ), -8 );
		$order = $number ? wc_get_order( $number ) : false;
		$found = $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && 8 === strlen( $phone )
			&& ! in_array( $order->get_status(), array( 'checkout-draft', 'trash' ), true )
			&& (string) $order->get_order_number() === (string) $number;
		if ( $found ) {
			$phones = array_filter( array_map( static fn( $p ) => substr( preg_replace( '/\D+/', '', (string) $p ), -8 ), array( $order->get_billing_phone(), $order->get_meta( '_cvd_alternate_phone', true ) ) ), static fn( $p ) => 8 === strlen( $p ) );
			$found = in_array( $phone, $phones, true );
		}
		wp_safe_redirect( $found ? self::tracking_url( $order ) : add_query_arg( 'aviso', 'no', $back ), 303 );
		exit;
	}

	/** «Mi pedido» sin enlace: los pedidos hechos en este teléfono (los guarda la página de pedido recibido) y la búsqueda. */
	private static function render_lookup(): string {
		$notice = sanitize_key( wp_unslash( $_GET['aviso'] ?? '' ) );
		$notices = array( 'no' => 'No encontramos un pedido con ese número y ese teléfono. Revisa los dos (el teléfono es el que se puso al comprar).', 'espera' => 'Demasiados intentos seguidos. Espera media hora o escríbenos por WhatsApp.' );
		$help = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::contact_url( 'Hola Casa Viva, quiero saber cómo va mi pedido número ' ) : '';
		$html = '<section class="cvd-tracking cvd-tracking-empty cvd-my-order">';
		$html .= '<div class="cvd-my-order__saved" data-my-orders hidden><h2>Tus pedidos en este teléfono</h2><ul></ul></div>';
		$html .= '<form class="cvd-my-order__lookup" method="post" action="' . esc_url( home_url( '/seguimiento/' ) ) . '"><h2>Busca tu pedido</h2><p>No hace falta cuenta. Escribe el número del pedido y el teléfono que pusiste al comprar.</p>';
		$html .= '<label for="cvd-lookup-order">Número del pedido</label><input id="cvd-lookup-order" name="cvd_lookup_order" inputmode="numeric" pattern="[0-9#\s]*" maxlength="12" autocomplete="off" placeholder="Ej.: 3598" required>';
		$html .= '<label for="cvd-lookup-phone">Teléfono</label><input id="cvd-lookup-phone" name="cvd_lookup_phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="Ej.: 5 123 4567" required>';
		if ( isset( $notices[ $notice ] ) ) { $html .= '<p class="cvd-my-order__error" role="alert">' . esc_html( $notices[ $notice ] ) . '</p>'; }
		$html .= '<button class="cvd-primary" type="submit">Ver mi pedido</button></form>';
		$html .= '<details class="cvd-my-order__link"><summary>Tengo el enlace del vale</summary><form data-tracking-link-form><label for="cvd-tracking-link">Pega el enlace</label><input id="cvd-tracking-link" type="url" inputmode="url" placeholder="https://casaviva.company/seguimiento/?pedido=…" required><button class="cvd-secondary" type="submit">Abrir</button><span data-tracking-link-error role="alert"></span></form></details>';
		if ( $help ) { $html .= '<p class="cvd-my-order__help">¿No lo encuentras? <a href="' . esc_url( $help ) . '" target="_blank" rel="noopener">Escríbenos por WhatsApp</a> con el número del pedido.</p>'; }
		return $html . '</section>';
	}

	public static function render_tracking(): string {
		$order = self::tracking_order();
		if ( ! $order ) { return self::render_lookup(); }
		$view = self::tracking_view( $order );
		$status = $view['status'];
		$current_rank = $view['rank'];
		$html = '<section class="cvd-tracking" data-customer-tracking="' . esc_attr( $order->get_id() ) . '" data-tracking-key="' . esc_attr( $order->get_order_key() ) . '"><p class="cvd-kicker">Pedido #' . esc_html( $order->get_order_number() ) . '</p><h1 data-tracking-title>' . esc_html( $view['title'] ) . '</h1><p class="cvd-tracking-updated">Última actualización: ' . esc_html( $order->get_date_modified() ? wc_format_datetime( $order->get_date_modified() ) : '' ) . '</p><ol class="cvd-tracking-steps" data-tracking-steps>';
		foreach ( $view['steps'] as $step ) { $html .= '<li class="' . ( $step['done'] ? 'is-complete' : '' ) . '"><span></span><strong>' . esc_html( $step['label'] ) . '</strong></li>'; }
		$courier = self::tracking_courier_text( $view );
		$html .= '</ol><p class="cvd-tracking-courier" data-tracking-courier' . ( '' === $courier ? ' hidden' : '' ) . '>' . esc_html( $courier ) . '</p>';
		$html .= '<div class="cvd-courier-location" data-courier-location hidden><strong>Ubicación del mensajero</strong><span data-location-time>Esperando actualización…</span><a class="cvd-secondary" data-location-link target="_blank" rel="noopener">Abrir en el mapa</a></div>';
		// 3.14.72.2 (Ernesto 28-oct, «como Mandao»): el mapa del recorrido del mensajero; live-tracking.js lo pinta y lo pone al día.
		$html .= '<div class="cvd-ruta" data-ruta hidden><div class="cvd-ruta-map" data-ruta-map></div><p class="cvd-ruta-text" data-ruta-text></p></div>';
		// 3.14.74.3 (fase 3): «Marca tu casa» mientras el pedido no tenga el punto exacto (de la gestora o del cliente).
		if ( class_exists( 'CVD_Palla_Api' ) && CVD_Palla_Api::needs_client_point( $order ) ) {
			// 3.14.78: si la casa ya salió de la dirección (entrecalles), se enseña allí y solo se corrige si no está bien.
			$auto = CVD_Palla_Api::auto_point( $order );
			$start = $auto ? $auto[0] . ',' . $auto[1] : (string) get_option( 'cvd_palla_tienda_geo', '23.11504,-82.39749' );
			$html .= '<div class="cvd-casa" data-casa data-start="' . esc_attr( $start ) . '"' . ( $auto ? ' data-auto' : '' ) . '><button type="button" class="cvd-secondary" data-casa-abrir>' . ( $auto ? '📍 ¿Está bien la casa en el mapa?' : '📍 Marca tu casa en el mapa' ) . '</button>'
				. '<p class="cvd-casa-help" data-casa-help hidden>' . ( $auto ? 'La sacamos de la dirección. Si la 🏠 no está en su sitio, arrástrala o toca la casa.' : 'Toca tu casa en el mapa (o arrastra la 🏠). Así el mensajero llega directo.' ) . '</p><div class="cvd-casa-map" data-casa-map hidden></div>'
				. '<button type="button" class="cvd-primary" data-casa-guardar hidden>' . ( $auto ? 'Guardar' : 'Guardar mi casa' ) . '</button><p class="cvd-casa-msg" data-casa-msg></p></div>';
		}
		if ( 'pickup' === $status ) {
			// 3.14.9 (D67): pedido de una gestora visto por su cliente: sin la dirección de la tienda ni «Cómo llegar».
			$gestora_name = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::order_gestora_name( $order ) : '';
			$html .= '' !== $gestora_name
				? '<p class="cvd-tracking-pickup">Recogida: ' . esc_html( CVD_Gestora_Store::pickup_place( $order ) ) . '.</p>'
				: '<p class="cvd-tracking-pickup">Recogida en tienda: ' . esc_html( (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) ) . ' · <a href="' . esc_url( home_url( '/como-llegar/' ) ) . '">Cómo llegar</a></p>';
		}
		$map = esc_url( (string) CVD_Shipping_Rates::order_map_url( $order ) );
		if ( $map && $current_rank >= 4 ) { $html .= '<a class="cvd-primary" href="' . $map . '" target="_blank" rel="noopener">Abrir dirección de entrega</a>'; }
		if ( in_array( $status, array( 'incident', 'failed', 'returned', 'cancelled' ), true ) ) { $html .= '<p class="cvd-tracking-alert">Casa Viva está revisando esta entrega. Te contactaremos si necesitamos confirmar algún dato.</p>'; }
		$confirmed = (bool) $order->get_meta( '_cvd_customer_confirmed_at', true );
		if ( in_array( $status, array( 'delivered', 'cash_returned', 'closed' ), true ) ) {
			if ( $confirmed ) { $html .= '<div class="cvd-confirmed-delivery"><strong>Entrega confirmada</strong><span>Gracias por evaluar el servicio.</span></div>'; }
			else { $html .= '<form class="cvd-delivery-rating" data-delivery-rating><h2>Confirma tu entrega</h2><p>Tu confirmación no libera pagos automáticamente; Casa Viva verificará la operación.</p><label>Evaluación<select name="rating" required><option value="">Selecciona</option><option value="5">5 · Excelente</option><option value="4">4 · Muy buena</option><option value="3">3 · Buena</option><option value="2">2 · Regular</option><option value="1">1 · Mala</option></select></label><label>Comentario opcional<textarea name="comment" maxlength="400" rows="3"></textarea></label><button class="cvd-primary" type="submit">Confirmar entrega</button><span data-rating-status></span></form>'; }
		}
		// 3.14.53 (#48): «Seguir comprando» con el código de su gestora (sin gestora, la tienda de Casa Viva).
		$html .= '<p class="cvd-tracking-shop"><a class="cvd-secondary" href="' . esc_url( self::shop_url( $order ) ) . '">Seguir comprando</a></p>';
		return $html . '</section>';
	}

	/**
	 * Lo que ve el cliente en /seguimiento/ (la página y cada consulta de live-tracking.js):
	 * título, pasos (también Preparando y Listo para salir), primer nombre del mensajero y su hora.
	 */
	public static function tracking_view( WC_Order $order ): array {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return self::pickup_view( $order ); }
		$status = self::status( $order );
		$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
		// 3.14.98 (Ernesto 30-oct, «que el cliente vea dónde está su pedido»): paso «Llegó a tu puerta» cuando el mensajero
		// toca «Llegué» (o llega solo por GPS), y la hora prevista sale del recorrido real (ya no hay hora puesta a mano).
		$labels = array( 'Pedido confirmado', 'Preparando', 'Listo para salir', 'Mensajero asignado', 'Recogido', 'En camino', 'Llegó a tu puerta', 'Entregado' );
		$delivery_rank = array( 'unassigned'=>0, 'offered'=>2, 'assigned'=>3, 'accepted'=>3, 'to_store'=>3, 'picked_up'=>4, 'handed_over'=>5, 'delivered'=>7, 'cash_returned'=>7, 'closed'=>7 );
		$operation_rank = array( 'preparing'=>1, 'ready'=>2, 'with_courier'=>4, 'delivered'=>7 );
		$rank = isset( $delivery_rank[ $status ] ) ? max( $delivery_rank[ $status ], $operation_rank[ $operation ] ?? 0 ) : -1;
		$arrived = $rank >= 4 && $rank < 7 && '' !== (string) $order->get_meta( '_cvd_llegue_at', true );
		if ( $arrived ) { $rank = 6; }
		$steps = array();
		foreach ( $labels as $index => $label ) { $steps[] = array( 'label' => $label, 'done' => $index <= $rank ); }
		$title = $arrived ? 'Tu mensajero llegó' : self::customer_status_label( $status );
		if ( in_array( $status, array( 'unassigned', 'offered' ), true ) && $rank >= 2 ) { $title = 'Tu pedido está listo para salir'; }
		$messenger = '';
		$eta = '';
		if ( $rank >= 3 ) {
			$user = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
			$messenger = $user ? (string) explode( ' ', trim( (string) $user->display_name ) )[0] : '';
			$ruta = in_array( $rank, array( 4, 5 ), true ) && class_exists( 'CVD_Palla_Api' ) ? CVD_Palla_Api::route_view( $order ) : null;
			if ( is_array( $ruta ) && ! empty( $ruta['eta'] ) && 'cliente' === ( $ruta['stage'] ?? '' ) ) { $eta = 'en unos ' . absint( $ruta['eta'] ) . ' min'; }
			elseif ( $rank < 6 ) { $eta = trim( (string) $order->get_meta( '_cvd_bot_eta', true ) ); }
		}
		return array( 'status' => $status, 'title' => $title, 'rank' => $rank, 'steps' => $steps, 'messenger' => $messenger, 'eta' => $eta );
	}

	/** 3.13.89: recogida en tienda, con sus propios pasos (sin mensajero ni «en camino»). */
	private static function pickup_view( WC_Order $order ): array {
		$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
		$wc_status = $order->get_status();
		if ( in_array( $wc_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$rank = -1;
		} elseif ( 'completed' === $wc_status || 'delivered' === $operation ) {
			$rank = 3;
		} elseif ( in_array( $operation, array( 'ready', 'with_courier' ), true ) ) {
			$rank = 2;
		} else {
			$rank = 'preparing' === $operation ? 1 : 0;
		}
		$steps = array();
		foreach ( array( 'Pedido confirmado', 'Preparando', 'Listo para recoger', 'Recogido' ) as $index => $label ) { $steps[] = array( 'label' => $label, 'done' => $index <= $rank ); }
		$titles = array( -1 => 'Pedido cancelado', 0 => 'Pedido recibido', 1 => 'Estamos preparando tu pedido', 2 => 'Tu pedido está listo para recoger', 3 => 'Pedido recogido' );
		return array( 'status' => -1 === $rank ? 'cancelled' : 'pickup', 'title' => $titles[ $rank ], 'rank' => $rank, 'steps' => $steps, 'messenger' => '', 'eta' => '' );
	}

	public static function tracking_courier_text( array $view ): string {
		$parts = array();
		if ( ! empty( $view['messenger'] ) ) { $parts[] = 'Te lo lleva ' . $view['messenger']; }
		if ( ! empty( $view['eta'] ) ) { $parts[] = 'Hora prevista: ' . $view['eta']; }
		return implode( ' · ', $parts );
	}

	private static function customer_status_label( string $status ): string {
		$labels = array( 'unassigned'=>'Estamos preparando tu pedido','offered'=>'Estamos preparando tu pedido','assigned'=>'Mensajero asignado','accepted'=>'Mensajero asignado','to_store'=>'El mensajero va a recoger tu pedido','picked_up'=>'Pedido entregado al mensajero','handed_over'=>'Tu pedido va en camino','delivered'=>'Pedido entregado','cash_returned'=>'Pedido entregado','closed'=>'Pedido completado','incident'=>'Estamos revisando tu entrega','failed'=>'No se pudo completar la entrega','returned'=>'Pedido devuelto a Casa Viva','cancelled'=>'Pedido cancelado' );
		return $labels[ $status ] ?? 'Pedido recibido';
	}

	public static function render_email_tracking( WC_Order $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		unset( $email );
		if ( $sent_to_admin || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return; }
		$url = self::tracking_url( $order );
		echo $plain_text ? "\nSeguimiento: " . esc_url_raw( $url ) . "\n" : '<p><strong>Seguimiento:</strong> <a href="' . esc_url( $url ) . '">consultar estado del pedido</a></p>';
	}

	public static function render_control(): string {
		if ( ! is_user_logged_in() ) { return '<section class="cvd-sales-denied"><h1>Mensajería</h1><a href="' . esc_url( home_url( '/casa-viva-app/' ) ) . '">Iniciar sesión</a></section>'; }
		if ( ! current_user_can( 'cvd_manage_sales' ) && ! current_user_can( 'manage_woocommerce' ) ) { return '<section class="cvd-sales-denied"><h1>Acceso restringido</h1><p>Esta cuenta no controla mensajería.</p></section>'; }
		$orders = wc_get_orders( array( 'limit'=>100, 'orderby'=>'date', 'order'=>'DESC', 'status'=>array('pending','processing','on-hold','completed') ) ); $messengers = self::messengers();
		$counts = array_fill_keys( self::STATUSES, 0 ); $messenger_report = array(); foreach ( $orders as $order ) { $state=self::status($order); $counts[$state]++; $mid=absint($order->get_meta('_cvd_messenger_user_id',true)); if($mid){if(!isset($messenger_report[$mid])){$messenger_report[$mid]=array('assigned'=>0,'delivered'=>0,'pending'=>0,'incidents'=>0);} $messenger_report[$mid]['assigned']++; if(in_array($state,array('delivered','cash_returned','closed'),true)){$messenger_report[$mid]['delivered']++;} if('delivered'===$state){$messenger_report[$mid]['pending']++;} if(in_array($state,array('incident','failed','returned'),true)){$messenger_report[$mid]['incidents']++;}} }
		ob_start(); ?><section class="cvd-sales-app cvd-delivery-control"><nav><a href="<?php echo esc_url( home_url('/centro-operaciones/') ); ?>">Operaciones</a><a href="<?php echo esc_url( wp_logout_url(home_url('/casa-viva-app/')) ); ?>">Salir</a></nav><header><p>Mensajería</p><h1>Entregas y efectivo</h1><span>Confirma cada paso de la entrega.</span></header><div class="cvd-sales-summary"><article><span>Por ofertar</span><strong><?php echo esc_html($counts['unassigned']); ?></strong></article><article><span>Ofertas abiertas</span><strong><?php echo esc_html($counts['offered']); ?></strong></article><article><span>En ruta</span><strong><?php echo esc_html($counts['handed_over']); ?></strong></article><article><span>Efectivo pendiente</span><strong><?php echo esc_html($counts['delivered']); ?></strong></article><article><span>Incidencias</span><strong><?php echo esc_html($counts['incident']+$counts['failed']+$counts['returned']); ?></strong></article></div><div class="cvd-sales-tools"><input id="cvd-delivery-search" type="search" inputmode="numeric" placeholder="Número del pedido"><button id="cvd-delivery-scan" type="button">Abrir cámara</button></div><p class="cvd-scan-help" id="cvd-scan-help">Desde el teléfono de la dependienta: abre la cámara y apunta al QR del mensajero. También puedes escribir el número del pedido.</p><video id="cvd-delivery-scanner" playsinline hidden></video><section class="cvd-delivery-report"><h2>Reporte por mensajero</h2><div class="cvd-table-wrap"><table><thead><tr><th>Mensajero</th><th>Asignadas</th><th>Entregadas</th><th>Efectivo pendiente</th><th>Incidencias</th></tr></thead><tbody><?php foreach($messenger_report as $mid=>$row): $m=get_userdata($mid); ?><tr><td><?php echo esc_html($m?$m->display_name:'Usuario eliminado'); ?></td><td><?php echo esc_html($row['assigned']); ?></td><td><?php echo esc_html($row['delivered']); ?></td><td><?php echo esc_html($row['pending']); ?></td><td><?php echo esc_html($row['incidents']); ?></td></tr><?php endforeach; ?></tbody></table></div></section><div class="cvd-sales-list">
		<?php foreach ($orders as $order) : $current=self::status($order); $assigned=absint($order->get_meta('_cvd_messenger_user_id',true)); $next_actions = array(); if ('unassigned'===$current) {$next_actions['offered']='Publicar oferta';} if (in_array($current,array('accepted','to_store'),true)) {$next_actions['picked_up']='Entregado al mensajero';} if ('delivered'===$current) {$next_actions['cash_returned']='Registrar efectivo devuelto';} if ('cash_returned'===$current && current_user_can('manage_woocommerce')) {$next_actions['closed']='Cerrar operación';} if ('incident'===$current) {$next_actions=array('accepted'=>'Retomar antes de salida','picked_up'=>'Retomar recogido','handed_over'=>'Retomar en camino','returned'=>'Marcar devuelta','failed'=>'Cerrar sin entrega');} elseif (!in_array($current,array('closed','failed','cancelled'),true)) {$next_actions['incident']='Registrar incidencia';} ?><article class="cvd-sale-card"><div class="cvd-sale-top"><div><span class="cvd-sale-status"><?php echo esc_html(self::label($current)); ?></span><h2>Pedido #<?php echo esc_html($order->get_order_number()); ?></h2><small><?php echo esc_html($order->get_formatted_billing_full_name()); ?></small><?php /* 3.13.78 (Nana): gestora y mensajero con su WhatsApp */ $people = class_exists('CVD_Sales') ? CVD_Sales::people_lines($order) : null; if ($people): ?><small class="cvd-sale-people" style="display:block;margin-top:4px">Gestor/a: <strong><?php echo esc_html($people['gestora']); ?></strong><br>Mensajero: <strong><?php echo esc_html($people['messenger']); ?></strong></small><?php endif; ?></div><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></div><form class="cvd-delivery-assignment" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="cvd_delivery_assign"><input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>"><label>Mensajero<select name="messenger_id"><option value="0">Sin asignar</option><?php foreach($messengers as $m): ?><option value="<?php echo esc_attr($m->ID); ?>" <?php selected($assigned,$m->ID); ?>><?php echo esc_html($m->display_name); ?></option><?php endforeach; ?></select></label><?php wp_nonce_field('cvd_delivery_assign_'.$order->get_id()); ?><button type="submit">Asignar</button></form><div class="cvd-sale-actions">
		<?php foreach($next_actions as $next=>$label): ?><?php if ('incident'===$next): ?><form method="post" action="<?php echo esc_url(self::action_url($order->get_id(),$next)); ?>"><input name="note" required maxlength="240" placeholder="Describe la incidencia"><button type="submit"><?php echo esc_html($label); ?></button></form><?php else: ?><a href="<?php echo esc_url(self::action_url($order->get_id(),$next)); ?>"><?php echo esc_html($label); ?></a><?php endif; ?><?php endforeach; ?></div><details><summary>Historial</summary><?php if($order->get_meta('_cvd_customer_confirmed_at',true)): ?><p><strong>Cliente confirmó la entrega · <?php echo esc_html(absint($order->get_meta('_cvd_customer_rating',true))); ?>/5</strong></p><?php endif; ?><?php $events=$order->get_meta('_cvd_delivery_history',true); foreach(array_reverse(is_array($events)?$events:array()) as $event): $actor=get_userdata(absint($event['actor_user_id']??0)); ?><p><?php echo esc_html(($event['at']??'').' · '.self::label($event['to']??'').' · '.($actor?$actor->display_name:'Sistema')); ?></p><?php endforeach; ?></details></article><?php endforeach; ?></div></section><?php return (string)ob_get_clean();
	}
}
