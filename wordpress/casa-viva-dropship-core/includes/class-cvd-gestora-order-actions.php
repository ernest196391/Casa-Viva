<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.103 (Ernesto 31-oct, caso Niurka #5976): la gestora cambia SU pedido sin Lennys ni Ernesto, por WhatsApp (VivaBot) o
 * desde su oficina (web). Un solo servicio para los dos caminos:
 *
 * - Recogida → mensajería: CVD_Bot_Delivery_Change::change_to_delivery (tarifa oficial; si dice otro costo, no cambia nada).
 * - Mensajería → recogida: to_pickup(). Solo mientras el paquete siga en la tienda (ningún mensajero lo recogió). Quita la
 *   mensajería, libera al mensajero si lo tenía, deja de estar ofertado en el grupo, nota en el pedido y queda como recogida
 *   (la tienda lo entrega en su dirección y horario). El bot avisa al mensajero y a la tienda (CVD_Order_Avisos «to_pickup»).
 * - Cancelar: cancel(). Es CVD_Order_Edit::cancel con sus frenos (entregado, reserva, ganancia liquidada) más uno propio: solo
 *   la gestora dueña. Si el mensajero ya tiene el paquete no cancela: «que lo devuelva a la tienda y lo cancela Lennys».
 *   Al cancelar vuelven las existencias y se anulan comisión y carrera (la cascada de siempre); el bot avisa al mensajero y a la tienda.
 *
 * Quién: la gestora dueña del pedido. Por el bot también administración (su WhatsApp en su usuario de la web).
 * Todo queda anotado en el pedido y en un registro corto (opción cvd_gestora_changes_log, últimos 300) que VivaBot lee para el
 * resumen diario de Lennys: GET /bot/gestora-changes?day=AAAA-MM-DD. No toca precios, tarifas ni comisiones.
 */
final class CVD_Gestora_Order_Actions {
	private const NS = 'casa-viva/v1';
	public const LOG_OPTION = 'cvd_gestora_changes_log';
	private const OPEN = array( 'pending', 'processing', 'on-hold' );
	/** El paquete sigue en la tienda: se puede pasar a recogida. */
	private const IN_STORE = array( 'unassigned', 'offered', 'assigned', 'accepted', 'to_store' );
	/** El mensajero ya lo tiene en la mano. */
	private const WITH_MESSENGER = array( 'picked_up', 'handed_over' );
	/** Lo que deja de valer al volver a recogida (reparto, mensajero, tarifa, día de entrega). */
	private const DELIVERY_META = array(
		'_cvd_delivery_status', '_cvd_messenger_user_id', '_cvd_direct_assignment', '_cvd_direct_assigned_at', '_cvd_delivery_accepted_at',
		'_cvd_to_store_at', '_cvd_bot_eta', '_cvd_bot_eta_at', '_cvd_delivery_offered_at', '_cvd_delivery_invited_messengers',
		'_cvd_delivery_rejected_by', '_cvd_delivery_rank_snapshot', '_cvd_bot_direct_announced_at', '_cvd_bot_announced_at',
		'_cvd_preferred_messenger_user_id', '_cvd_preferred_messenger_source', '_cvd_shipping_fee_cup', '_cvd_shipping_courier_amount_cup',
		'_cvd_shipping_platform_amount_cup', '_cvd_shipping_platform_rate', '_cvd_shipping_rate_status', '_cvd_shipping_rate_label',
		'_cvd_shipping_rate_version', '_cvd_locality', '_cvd_messenger_earning_status', '_cvd_delivery_date', '_cvd_delivery_window', '_cvd_joined_with',
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/gestora/open-orders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_open_orders' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/to-pickup', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_to_pickup' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/orders/(?P<id>\d+)/gestora-cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_cancel' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/gestora-changes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bot_changes' ), 'permission_callback' => $bot ) );
		$web = array( __CLASS__, 'can_use_web' );
		register_rest_route( self::NS, '/gestora/orders/(?P<id>\d+)/to-delivery', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'web_to_delivery' ), 'permission_callback' => $web ) );
		register_rest_route( self::NS, '/gestora/orders/(?P<id>\d+)/to-pickup', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'web_to_pickup' ), 'permission_callback' => $web ) );
		register_rest_route( self::NS, '/gestora/orders/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'web_cancel' ), 'permission_callback' => $web ) );
	}

	// ---------- Servicios (bot y web) ----------

	/**
	 * Mensajería → recogida. $role: gestora | admin. $channel: whatsapp | web.
	 * → result: changed (+ messenger liberado, wasOffered, pickupAddress) | already_pickup | with_messenger (+ messenger) | incident |
	 *   too_late (+ stage) | not_open | test_order | busy | error.
	 */
	public static function to_pickup( WC_Order $order, WP_User $actor, string $role, string $channel ): array {
		if ( CVD_Delivery::is_test_order( $order ) ) { return array( 'result' => 'test_order' ); }
		if ( ! $order->has_status( self::OPEN ) ) { return array( 'result' => 'not_open', 'status' => $order->get_status() ); }
		if ( self::is_pickup( $order ) ) { return array( 'result' => 'already_pickup' ); }
		// El mismo candado que las transiciones del reparto: un «Yo» o un «ya lo recogí» a la vez no se pisan.
		global $wpdb;
		$lock = 'cvd_transition_' . $order->get_id();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return array( 'result' => 'busy' ); }
		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order instanceof WC_Order || self::is_pickup( $order ) ) { return array( 'result' => 'already_pickup' ); }
			$stage = self::stage( $order );
			if ( 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true ) ) { return array( 'result' => 'incident' ); }
			if ( in_array( $stage, self::WITH_MESSENGER, true ) ) { return array( 'result' => 'with_messenger', 'messenger' => self::messenger_name( $order ) ); }
			if ( ! in_array( $stage, self::IN_STORE, true ) ) { return array( 'result' => 'too_late', 'stage' => $stage ); }
			$messenger = CVD_Order_Edit::messenger_to_warn( $order ); // asignado, aceptado o «va a recoger»: hay que avisarle
			$before = class_exists( 'CVD_Order_Avisos' ) ? CVD_Order_Avisos::snapshot( $order ) : array();
			$was = array( 'address' => trim( (string) ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) ), 'zone' => (string) $order->get_meta( '_cvd_locality', true ), 'fee' => absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) );
			$order->update_meta_data( '_cvd_fulfillment_type', 'pickup' );
			$order->update_meta_data( '_billing_cvd_fulfillment_type', 'pickup' );
			foreach ( self::DELIVERY_META as $key ) { $order->delete_meta_data( $key ); }
			$order->set_shipping_address_1( '' ); // la dirección del cliente queda en la nota y en sus datos de facturación
			$order->add_order_note( sprintf(
				'Pasado de mensajería a RECOGIDA EN TIENDA por %s %s. Se quitó la mensajería (%s CUP%s).%s%s Lo recoge en la tienda.',
				self::who( $actor, $role ), 'web' === $channel ? 'desde su oficina' : 'por WhatsApp', number_format_i18n( $was['fee'], 0 ),
				'' !== $was['address'] ? ' · ' . $was['address'] . ( '' !== $was['zone'] ? ', ' . $was['zone'] : '' ) : '',
				$messenger ? ' Lo tenía ' . $messenger['name'] . ' (' . CVD_Delivery::label( $stage ) . '): queda libre y el bot le avisa.' : '',
				'offered' === $stage ? ' Estaba en el grupo de mensajeros: se retira.' : ''
			) );
			$order->save();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		$args = array( $order->get_id() );
		wp_clear_scheduled_hook( 'cvd_dispatch_new_order', $args );
		wp_clear_scheduled_hook( 'cvd_expand_delivery_offer', $args );
		if ( class_exists( 'CVD_Order_Avisos' ) ) { CVD_Order_Avisos::record_to_pickup( $order, $before, $actor->ID, self::source( $role, $channel ), $messenger, $stage ); }
		self::log( $order, $actor, $role, 'to_pickup', $channel, $messenger ? 'liberado ' . $messenger['name'] : ( 'offered' === $stage ? 'retirado del grupo' : '' ) );
		return array(
			'result' => 'changed', 'messenger' => $messenger ? $messenger['name'] : '', 'wasOffered' => 'offered' === $stage, 'feeRemoved' => $was['fee'],
			'pickupAddress' => (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ),
		);
	}

	/**
	 * Cancelar con motivo. → result: cancelled (+ messenger, message) | reason_missing | with_messenger (+ messenger, message) |
	 *   blocked (+ message: entregado, reserva…) | test_order | error (+ message).
	 */
	public static function cancel( WC_Order $order, WP_User $actor, string $role, string $reason, string $channel ): array {
		$reason = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $reason ) ) );
		if ( mb_strlen( $reason ) < 3 ) { return array( 'result' => 'reason_missing' ); }
		if ( ! self::is_pickup( $order ) && in_array( self::stage( $order ), self::WITH_MESSENGER, true ) && $order->has_status( self::OPEN ) ) {
			return array( 'result' => 'with_messenger', 'messenger' => self::messenger_name( $order ), 'message' => 'El mensajero ya tiene el paquete. Que lo devuelva a la tienda y lo cancela Lennys.' );
		}
		$owner = 'gestora' === $role;
		$result = CVD_Order_Edit::cancel( $order->get_id(), $reason, $actor->ID, self::source( $role, $channel ), false, $owner );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'cvd_cancel_reason' === $code ) { return array( 'result' => 'reason_missing' ); }
			return array( 'result' => 'cvd_cancel_failed' === $code ? 'error' : 'blocked', 'message' => $result->get_error_message() );
		}
		self::log( $order, $actor, $role, 'cancel', $channel, $reason );
		return array( 'result' => 'cancelled', 'messenger' => (string) ( $result['messenger']['name'] ?? '' ), 'message' => (string) ( $result['message'] ?? '' ) );
	}

	/** Pedidos abiertos de la gestora con lo que se puede hacer en cada uno (bot: #cambio; web: su oficina). */
	public static function open_orders( WP_User $gestora, int $limit = 20 ): array {
		$orders = wc_get_orders( array( 'limit' => $limit, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'status' => self::OPEN, 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora->ID ) );
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { continue; }
			$out[] = self::row( $order, $gestora );
		}
		return $out;
	}

	/** Un pedido abierto, en corto. can = lo que la dueña puede hacer ahora; whyNot = por qué no se cancela. */
	public static function row( WC_Order $order, WP_User $gestora ): array {
		$pickup = self::is_pickup( $order );
		$stage = self::stage( $order );
		$test = CVD_Delivery::is_test_order( $order );
		$incident = 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true );
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) { $items[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' ); }
		$cancel_why = $test ? 'Es un pedido de prueba.' : ( ! $pickup && in_array( $stage, self::WITH_MESSENGER, true ) ? 'El mensajero ya tiene el paquete. Que lo devuelva a la tienda y lo cancela Lennys.' : CVD_Order_Edit::cancel_block( $order, $gestora->ID, true ) );
		return array(
			'id' => $order->get_id(), 'number' => $order->get_order_number(), 'customer' => trim( (string) $order->get_billing_first_name() ),
			'items' => implode( ', ', $items ), 'pickup' => $pickup, 'stage' => $pickup ? '' : $stage,
			'label' => $pickup ? 'Recogida en tienda' : 'A domicilio · ' . CVD_Delivery::label( $incident ? 'incident' : $stage ),
			'messenger' => $pickup ? '' : self::messenger_name( $order ),
			'address' => $pickup ? '' : trim( (string) ( $order->get_shipping_address_1() ?: $order->get_billing_address_1() ) ),
			'zone' => $pickup ? '' : (string) $order->get_meta( '_cvd_locality', true ), 'fee' => $pickup ? 0 : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ),
			'can' => array(
				'toDelivery' => $pickup && ! $test && '' === CVD_Bot_Delivery_Change::block( $order ),
				'toPickup' => ! $pickup && ! $test && ! $incident && in_array( $stage, self::IN_STORE, true ),
				'cancel' => '' === $cancel_why,
			),
			'whyNot' => $cancel_why,
		);
	}

	// ---------- Registro (nota + resumen de Lennys) ----------

	/** Apunta el cambio en el registro corto. $action: to_delivery | to_pickup | cancel. */
	public static function log( WC_Order $order, WP_User $actor, string $role, string $action, string $channel, string $detail = '' ): void {
		$list = get_option( self::LOG_OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$list[] = array(
			'at' => current_time( 'mysql', true ), 'day' => wp_date( 'Y-m-d' ), 'order' => $order->get_id(), 'number' => $order->get_order_number(),
			'action' => sanitize_key( $action ), 'by' => $actor->display_name, 'role' => sanitize_key( $role ), 'channel' => sanitize_key( $channel ),
			'detail' => mb_substr( sanitize_text_field( $detail ), 0, 160 ),
		);
		update_option( self::LOG_OPTION, array_slice( $list, -300 ), false );
	}

	/** Cambios de un día (AAAA-MM-DD, hora de la web). */
	public static function changes_on( string $day ): array {
		$list = get_option( self::LOG_OPTION, array() );
		return array_values( array_filter( is_array( $list ) ? $list : array(), static fn( $x ) => is_array( $x ) && ( $x['day'] ?? '' ) === $day ) );
	}

	// ---------- Puertas del bot (X-VivaBot-Key) ----------

	public static function bot_open_orders( WP_REST_Request $request ): WP_REST_Response {
		$gestora = class_exists( 'CVD_Bot_Bridge' ) ? CVD_Bot_Bridge::gestora_by_phone( (string) $request->get_param( 'phone' ) ) : null;
		if ( ! $gestora ) { return self::out( array( 'found' => false, 'orders' => array() ) ); }
		return self::out( array( 'found' => true, 'name' => $gestora->display_name, 'orders' => self::open_orders( $gestora, 12 ), 'pickupAddress' => (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) ) );
	}

	public static function bot_to_pickup( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found' ) ); }
		$actor = self::bot_actor( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $actor ) { return self::out( array( 'result' => 'not_owner' ) ); }
		return self::out( self::to_pickup( $order, $actor[0], $actor[1], 'whatsapp' ) );
	}

	public static function bot_cancel( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order ) { return self::out( array( 'result' => 'not_found' ) ); }
		$actor = self::bot_actor( $order, (string) $request->get_param( 'phone' ) );
		if ( ! $actor ) { return self::out( array( 'result' => 'not_owner' ) ); }
		return self::out( self::cancel( $order, $actor[0], $actor[1], (string) $request->get_param( 'reason' ), 'whatsapp' ) );
	}

	public static function bot_changes( WP_REST_Request $request ): WP_REST_Response {
		$day = (string) $request->get_param( 'day' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) { $day = wp_date( 'Y-m-d' ); }
		return self::out( array( 'day' => $day, 'changes' => self::changes_on( $day ) ) );
	}

	/** [usuario, rol]: la gestora dueña (su WhatsApp) o administración (su WhatsApp en su usuario); null = nadie con permiso. */
	private static function bot_actor( WC_Order $order, string $phone ): ?array {
		$gestora = CVD_Bot_Delivery_Change::owner_gestora( $order, $phone );
		if ( $gestora ) { return array( $gestora, 'gestora' ); }
		$key = CVD_Bot_Delivery_Change::key( $phone );
		if ( '' === $key ) { return null; }
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'shop_manager' ), 'number' => 30 ) ) as $user ) {
			if ( $user instanceof WP_User && user_can( $user, 'manage_woocommerce' ) && $key === CVD_Bot_Delivery_Change::key( CVD_Bot_Delivery_Change::whatsapp( $user ) ) ) { return array( $user, 'admin' ); }
		}
		return null;
	}

	// ---------- Puertas de la oficina (web, sesión de la gestora) ----------

	public static function can_use_web(): bool {
		$user = wp_get_current_user();
		return $user->exists() && class_exists( 'CVD_Registration' ) && CVD_Registration::is_approved_gestora( $user );
	}

	/** El pedido, solo si es de la gestora que tiene la sesión abierta; si no, WP_Error. */
	private static function own_order( WP_REST_Request $request ) {
		$order = self::order( $request );
		$user = wp_get_current_user();
		if ( ! $order || ! $user->exists() || 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) || absint( $order->get_meta( '_cvd_owner_user_id', true ) ) !== $user->ID ) {
			return new WP_Error( 'cvd_gestora_order', 'Ese pedido no es tuyo o ya no existe.', array( 'status' => 404 ) );
		}
		return array( $order, $user );
	}

	public static function web_to_delivery( WP_REST_Request $request ) {
		$own = self::own_order( $request );
		if ( is_wp_error( $own ) ) { return $own; }
		$p = (array) $request->get_json_params();
		return self::out( CVD_Bot_Delivery_Change::change_to_delivery( $own[0], $own[1], array(
			'address' => $p['address'] ?? '', 'municipality' => $p['municipality'] ?? '', 'zone' => $p['zone'] ?? '', 'fee' => $p['fee'] ?? null, 'dry' => ! empty( $p['dry'] ),
		), 'web' ) );
	}

	public static function web_to_pickup( WP_REST_Request $request ) {
		$own = self::own_order( $request );
		if ( is_wp_error( $own ) ) { return $own; }
		return self::out( self::to_pickup( $own[0], $own[1], 'gestora', 'web' ) );
	}

	public static function web_cancel( WP_REST_Request $request ) {
		$own = self::own_order( $request );
		if ( is_wp_error( $own ) ) { return $own; }
		$p = (array) $request->get_json_params();
		return self::out( self::cancel( $own[0], $own[1], 'gestora', (string) ( $p['reason'] ?? '' ), 'web' ) );
	}

	/** «Pedidos abiertos» en Ventas de la oficina: cada pedido con sus botones (assets/gestora-cambios.js hace las llamadas). */
	public static function render( WP_User $user ): string {
		$orders = self::open_orders( $user );
		$config = array(
			'base' => esc_url_raw( rest_url( self::NS . '/gestora/orders/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			'zones' => class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::localities() : array(),
			'pickup' => (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ),
		);
		ob_start();
		?>
		<section class="cvga-card cvga-cambios" id="cvga-cambios" data-cvga-cambios aria-labelledby="cvga-cambios-h">
			<script type="application/json" data-cambios-config><?php echo wp_json_encode( $config ); ?></script>
			<h2 id="cvga-cambios-h">Pedidos abiertos</h2>
			<?php if ( ! $orders ) : ?>
			<p class="cvga-empty">No tienes pedidos abiertos ahora.</p>
			<?php else : ?>
			<p class="cvga-note">Cámbialos tú: pasar a mensajería, a recogida o cancelar. La tienda y el mensajero se enteran solos.</p>
			<ul class="cvga-cambios-list">
				<?php foreach ( $orders as $o ) : ?>
				<li class="cvga-cambio" data-order="<?php echo esc_attr( (string) $o['id'] ); ?>">
					<div class="cvga-cambio-head">
						<b>#<?php echo esc_html( $o['number'] ); ?><?php echo '' !== $o['customer'] ? ' · ' . esc_html( $o['customer'] ) : ''; ?></b>
						<span class="cvga-chip"><?php echo esc_html( $o['label'] ); ?></span>
					</div>
					<p class="cvga-cambio-items"><?php echo esc_html( $o['items'] ); ?><?php echo '' !== $o['messenger'] ? ' · Mensajero: ' . esc_html( $o['messenger'] ) : ''; ?><?php echo '' !== $o['zone'] ? ' · ' . esc_html( $o['zone'] ) : ''; ?></p>
					<div class="cvga-actions">
						<?php if ( $o['can']['toDelivery'] ) : ?><button class="cvga-btn cvga-btn-sm" type="button" data-cambio-open="delivery" aria-expanded="false">Pasar a mensajería</button><?php endif; ?>
						<?php if ( $o['can']['toPickup'] ) : ?><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cambio-open="pickup" aria-expanded="false">Pasar a recogida</button><?php endif; ?>
						<?php if ( $o['can']['cancel'] ) : ?><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cambio-open="cancel" aria-expanded="false">Cancelar</button><?php endif; ?>
					</div>
					<?php if ( ! $o['can']['cancel'] && '' !== $o['whyNot'] && ! $o['can']['toPickup'] && ! $o['can']['toDelivery'] ) : ?><p class="cvga-note"><?php echo esc_html( $o['whyNot'] ); ?></p><?php endif; ?>
					<div class="cvga-cambio-panel" data-cambio-panel hidden></div>
					<p class="cvga-cambio-res" data-cambio-res role="status" aria-live="polite"></p>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// ---------- Ayudas ----------

	private static function source( string $role, string $channel ): string {
		return 'gestora' === $role ? ( 'web' === $channel ? 'gestora_web' : 'gestora_whatsapp' ) : 'admin_whatsapp';
	}

	private static function who( WP_User $actor, string $role ): string {
		return $actor->display_name . ( 'gestora' === $role ? ' (gestor/a dueño/a del pedido)' : ' (administración)' );
	}

	private static function is_pickup( WC_Order $order ): bool {
		return 'pickup' === sanitize_key( (string) $order->get_meta( '_cvd_fulfillment_type', true ) );
	}

	/** Etapa del reparto tal cual (sin la marca de incidencia). */
	private static function stage( WC_Order $order ): string {
		return sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: 'unassigned';
	}

	private static function messenger_name( WC_Order $order ): string {
		$user = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		return $user ? (string) $user->display_name : '';
	}

	private static function order( WP_REST_Request $request ): ?WC_Order {
		$order = wc_get_order( absint( $request['id'] ) );
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && 'shop_order' === $order->get_type() ? $order : null;
	}

	private static function out( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
