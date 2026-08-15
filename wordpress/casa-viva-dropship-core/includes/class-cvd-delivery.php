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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'initialize_order' ), 30 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_thankyou_tracking' ), 25 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_tracking' ), 25, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'sync_cancelled' ), 40 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'sync_cancelled' ), 40 );
		add_action( 'cvd_expand_delivery_offer', array( __CLASS__, 'expand_offer' ) );
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
			&& 'mensajero' === CVD_Registration::program_type( $user )
			&& 'approved' === get_user_meta( $user->ID, '_cvd_account_status', true );
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
				return array(
					'id' => $order->get_id(),
					'zone' => self::destination_zone( $order ),
					'earningCup' => self::courier_amount( $order ),
					'items' => implode( ', ', $items ),
					'zoneMatch' => self::zone_matches( $user, $order ),
					'acceptUrl' => self::offer_action_url( $order->get_id(), 'accept' ),
					'declineUrl' => self::offer_action_url( $order->get_id(), 'decline' ),
				);
			},
			self::offers_for( $user )
		);
		$assigned = wc_get_orders( array( 'limit'=>30, 'orderby'=>'date', 'order'=>'DESC', 'meta_key'=>'_cvd_messenger_user_id', 'meta_value'=>$user->ID ) );
		$deliveries = array_values( array_map(
			static fn( WC_Order $order ): array => array( 'id'=>$order->get_id(), 'status'=>self::status( $order ), 'label'=>self::label( self::status( $order ) ) ),
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
		$current = self::status( $order );
		$cancel_event = null;
		if ( 'cancelled' !== $current ) {
			self::append_event( $order, $current, 'cancelled', array( 'reason' => 'woocommerce_order_cancelled' ) );
			$history = $order->get_meta( '_cvd_delivery_history', true ); $cancel_event = is_array( $history ) ? end( $history ) : null;
			$order->update_meta_data( '_cvd_delivery_status', 'cancelled' );
			$order->update_meta_data( '_cvd_delivery_updated_at', current_time( 'mysql', true ) );
		}
		$order->update_meta_data( '_cvd_messenger_earning_status', 'cancelled' );
		$order->save();
		if ( $cancel_event ) { do_action( 'cvd_order_transition_observed', $order->get_id(), 'delivery', $current, 'cancelled', 'cvd_delivery_sync_cancelled', array( 'reason' => 'woocommerce_order_cancelled' ), (string) ( $cancel_event['data']['_canonical_event_anchor'] ?? $cancel_event['at'] ?? '' ) ); }
		if ( class_exists( 'CVD_Messenger_Accounting' ) ) { CVD_Messenger_Accounting::void_order( $order ); }
		if ( class_exists( 'CVD_Live_Tracking' ) ) { CVD_Live_Tracking::reverse_cancelled_rating( $order ); }
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
		if ( ! $messenger_id ) { $order=wc_get_order($order_id); if(!$order){return;} $previous=absint($order->get_meta('_cvd_messenger_user_id',true)); $order->update_meta_data('_cvd_messenger_user_id',0); self::transition($order,'unassigned',array('previous_messenger'=>$previous,'messenger'=>0)); return; }
		if ( ! class_exists( 'CVD_Order_Transition_Service' ) ) { return; }
		$user = get_userdata( $messenger_id );
		$result = CVD_Order_Transition_Service::transition( $order_id, 'delivery', 'assigned', array(
			'actor_user_id'=>get_current_user_id(), 'source'=>'cvd_delivery_assign',
			'metadata'=>array( 'messenger'=>$messenger_id ),
			'precondition'=>static function( WC_Order $order ) use ( $messenger_id, $user ) {
				if ( ! $user || ! in_array( 'cvd_messenger', (array) $user->roles, true ) || 'approved' !== get_user_meta( $messenger_id, '_cvd_account_status', true ) ) { return CVD_Order_Transition_Service::PRECONDITION_FAILED; }
				$existing=absint($order->get_meta('_cvd_messenger_user_id',true)); return $existing && $existing!==$messenger_id ? CVD_Order_Transition_Service::CONFLICT : true;
			},
			'atomic_mutation'=>static function( WC_Order $order ) use ( $messenger_id, $user ): void { $order->update_meta_data('_cvd_messenger_user_id',$messenger_id); $order->add_order_note('Entrega asignada a '.$user->display_name.'.'); },
			'after_commit'=>static function( WC_Order $order, array $transition ) use ( $user ): void { self::centralized_after_commit($order,'assigned'); if(empty($transition['idempotent_replay'])&&$user->user_email){wp_mail($user->user_email,'Nueva entrega Casa Viva · Pedido #'.$order->get_order_number(),"Tienes una entrega asignada.\n\nRevisar:\n".home_url('/area-mensajeros/'));} },
		) );
		if ( empty( $result['success'] ) ) { return; }
	}

	/** Publish a prepared home-delivery order to every eligible, available messenger. */
	public static function publish_offer( WC_Order $order ): bool {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return false; }
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
			'atomic_mutation'=>static function(WC_Order $locked_order)use($first_wave,$eligible):void{$locked_order->update_meta_data('_cvd_delivery_offered_at',current_time('mysql',true));$locked_order->delete_meta_data('_cvd_messenger_user_id');$locked_order->update_meta_data('_cvd_delivery_invited_messengers',wp_list_pluck($first_wave,'ID'));$locked_order->update_meta_data('_cvd_delivery_rank_snapshot',self::rank_snapshot($eligible,$locked_order));},
			'after_commit'=>static function(WC_Order $saved_order)use($first_wave,$eligible):void{self::centralized_after_commit($saved_order,'offered');self::notify_offer($saved_order,$first_wave);if(count($eligible)>count($first_wave)&&!wp_next_scheduled('cvd_expand_delivery_offer',array($saved_order->get_id()))){wp_schedule_single_event(time()+max(30,absint(get_option('cvd_dispatch_wave_delay_seconds',90))),'cvd_expand_delivery_offer',array($saved_order->get_id()));}},
		));
		return !empty($result['success']);
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
		$eligible=self::eligible_messengers($order); $invited=array_map('absint',(array)$order->get_meta('_cvd_delivery_invited_messengers',true));
		$new=array_values(array_filter($eligible,static fn(WP_User $user):bool=>!in_array($user->ID,$invited,true)));
		if(!$new){return;} $order->update_meta_data('_cvd_delivery_invited_messengers',array_values(array_unique(array_merge($invited,wp_list_pluck($new,'ID'))))); self::append_event($order,'offered','offered',array('wave_expanded'=>count($new))); $order->save(); self::notify_offer($order,$new);
	}

	private static function rank_snapshot( array $users, WC_Order $order ): array {
		if(!class_exists('CVD_Messenger_Reputation')){return array();} $snapshot=array(); foreach($users as $position=>$user){$score=CVD_Messenger_Reputation::score($user,$order);$snapshot[]=array('position'=>$position+1,'user_id'=>$user->ID,'score'=>$score['total']);} return $snapshot;
	}

	public static function offers_for( WP_User $messenger ): array {
		if ( 'yes' !== get_user_meta( $messenger->ID, '_cvd_messenger_available', true ) || 'approved' !== get_user_meta( $messenger->ID, '_cvd_account_status', true ) ) { return array(); }
		$orders = wc_get_orders( array( 'limit' => 30, 'orderby' => 'date', 'order' => 'ASC', 'meta_key' => '_cvd_delivery_status', 'meta_value' => 'offered' ) );
		$orders = array_values( array_filter( $orders, static function ( WC_Order $order ) use ( $messenger ): bool {
			$rejected = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_rejected_by', true ) );
			$invited = array_map( 'absint', (array) $order->get_meta( '_cvd_delivery_invited_messengers', true ) );
			return in_array( $messenger->ID, $invited, true ) && ! in_array( $messenger->ID, $rejected, true ) && ! absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
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
		if ( 'mensajero' !== CVD_Registration::program_type( $user ) || 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) { wp_die( 'Esta cuenta no es un mensajero aprobado.' ); }
		if ( ! in_array( $decision, array( 'accept', 'decline' ), true ) ) { wp_die( 'Decisión no válida.' ); }
		if ( 'accept' === $decision ) {
			$transition=CVD_Order_Transition_Service::transition($order_id,'delivery','accepted',array(
				'actor_user_id'=>$user->ID,'idempotency_key'=>sanitize_text_field((string)($_GET['idempotency_key']??'')),'source'=>'cvd_delivery_offer_decision','metadata'=>array('messenger'=>$user->ID,'source'=>'open_offer'),
				'precondition'=>static function(WC_Order $order,string $current)use($user){$assigned=absint($order->get_meta('_cvd_messenger_user_id',true));if($assigned&&$assigned!==$user->ID){return CVD_Order_Transition_Service::CONFLICT;}if('offered'!==$current){return CVD_Order_Transition_Service::CONFLICT;}$invited=array_map('absint',(array)$order->get_meta('_cvd_delivery_invited_messengers',true));return in_array($user->ID,$invited,true)&&'yes'===get_user_meta($user->ID,'_cvd_messenger_available',true)?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},
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
			self::publish_offer( $order );
			wp_safe_redirect( home_url( '/mensajeria/?actualizado=1' ) ); exit;
		}
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( 'incident' === $next && '' === trim( $note ) ) { wp_die( 'Describe brevemente la incidencia para poder registrarla.' ); }
		if ( class_exists('CVD_Order_Transition_Service') && CVD_Order_Transition_Service::governs('delivery',$current,$next) ) {
			$idempotency_key=sanitize_text_field((string)($_REQUEST['idempotency_key']??''))?:'delivery:'.$order_id.':'.$current.':'.$next;
			$result=CVD_Order_Transition_Service::transition($order_id,'delivery',$next,array(
				'actor_user_id'=>$user->ID,'idempotency_key'=>$idempotency_key,'source'=>'cvd_delivery_change_status','metadata'=>array('note'=>$note),'coupled_operation_state'=>'picked_up'===$next?'with_courier':'','coupled_payment_state'=>'delivered'===$next?'pending_return':'',
				'precondition'=>static function(WC_Order $locked_order){return absint($locked_order->get_meta('_cvd_messenger_user_id',true))?true:CVD_Order_Transition_Service::PRECONDITION_FAILED;},
				'atomic_mutation'=>static function(WC_Order $locked_order,$from,$to,WP_User $actor,string $at):void{if('accepted'===$to){$locked_order->update_meta_data('_cvd_delivery_accepted_at',$at);update_user_meta($actor->ID,'_cvd_last_delivery_accepted_at',time());}if('to_store'===$to){$locked_order->update_meta_data('_cvd_to_store_at',$at);}if('picked_up'===$to){$locked_order->update_meta_data('_cvd_handed_over_by',$actor->ID);$locked_order->update_meta_data('_cvd_handed_over_at',$at);}if('handed_over'===$to){$locked_order->update_meta_data('_cvd_to_customer_at',$at);}if('delivered'===$to){$locked_order->update_meta_data('_cvd_delivered_by',$actor->ID);$locked_order->update_meta_data('_cvd_delivered_at',$at);}},
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

	/** Cierra la custodia cuando la tienda confirma el dinero y deja las comisiones aprobadas, no pagadas. */
	public static function close_after_cash_received( WC_Order $order ): bool {
		if ( 'delivered' !== self::status( $order ) ) { return false; }
		$user_id = get_current_user_id();
		self::transition( $order, 'cash_returned', array( 'source' => 'sales_money_confirmation' ) );
		$order->update_meta_data( '_cvd_cash_status', 'returned' );
		$order->update_meta_data( '_cvd_cash_returned_by', $user_id );
		$order->update_meta_data( '_cvd_cash_returned_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_commission_review_ready', 'yes' );
		$order->save();
		do_action( 'cvd_order_transition_observed', $order->get_id(), 'payment', 'pending_return', 'returned', 'cvd_delivery_cash_status', array(), (string) $order->get_meta( '_cvd_cash_returned_at', true ) );
		self::transition( $order, 'closed', array( 'source' => 'automatic_after_money_confirmation' ) );
		$order->update_meta_data( '_cvd_cash_status', 'verified' );
		$order->update_meta_data( '_cvd_cash_verified_by', $user_id );
		$order->update_meta_data( '_cvd_cash_verified_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_messenger_earning_status', 'approved' );
		$order->save();
		do_action( 'cvd_order_transition_observed', $order->get_id(), 'payment', 'returned', 'verified', 'cvd_delivery_cash_status', array(), (string) $order->get_meta( '_cvd_cash_verified_at', true ) );
		if ( class_exists( 'CVD_Messenger_Accounting' ) ) { CVD_Messenger_Accounting::credit_order( $order ); }
		if ( class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_approved( $order->get_id() ); }
		return true;
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
		if ( $is_messenger ) { return in_array( $next, array( 'accepted', 'to_store', 'handed_over', 'delivered', 'failed', 'returned', 'incident' ), true ); }
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
	public static function status( WC_Order $order ): string { $value = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ); return in_array( $value, self::STATUSES, true ) ? $value : 'unassigned'; }
	public static function label( string $status ): string { $labels = array( 'unassigned'=>'Por ofertar','offered'=>'Oferta abierta','assigned'=>'Asignada','accepted'=>'Aceptada','to_store'=>'Va a recoger','picked_up'=>'Entregado al mensajero','handed_over'=>'En camino al cliente','delivered'=>'Entregada · dinero pendiente','cash_returned'=>'Dinero recibido','closed'=>'Cerrada','incident'=>'Con incidencia','failed'=>'No entregada','returned'=>'Devuelta a tienda','cancelled'=>'Cancelada' ); return $labels[ $status ] ?? ucfirst( $status ); }
	private static function messengers(): array { return get_users( array( 'role' => 'cvd_messenger', 'orderby' => 'display_name', 'meta_key' => '_cvd_account_status', 'meta_value' => 'approved' ) ); }
	private static function eligible_messengers( WC_Order $order ): array {
		$users = array_values( array_filter( self::messengers(), static fn( WP_User $user ): bool => 'yes' === get_user_meta( $user->ID, '_cvd_messenger_available', true ) ) );
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

	public static function tracking_url( WC_Order $order ): string {
		return add_query_arg( array( 'pedido' => $order->get_id(), 'clave' => $order->get_order_key() ), home_url( '/seguimiento/' ) );
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

	public static function render_tracking(): string {
		$order = self::tracking_order();
		if ( ! $order ) { return '<section class="cvd-tracking cvd-tracking-empty"><h1>Seguir pedido</h1><form data-tracking-link-form><label for="cvd-tracking-link">Pega el enlace recibido</label><input id="cvd-tracking-link" type="url" inputmode="url" placeholder="https://casavivadecuba.com/seguimiento/?pedido=…" required><button class="cvd-primary" type="submit">Consultar</button><span data-tracking-link-error role="alert"></span></form></section>'; }
		$status = self::status( $order );
		$steps = array(
			'confirmed' => 'Pedido confirmado',
			'accepted' => 'Mensajero asignado',
			'picked_up' => 'Recogido',
			'handed_over' => 'En camino',
			'delivered' => 'Entregado',
		);
		$rank = array( 'unassigned'=>0, 'offered'=>0, 'assigned'=>1, 'accepted'=>1, 'to_store'=>1, 'picked_up'=>2, 'handed_over'=>3, 'delivered'=>4, 'cash_returned'=>4, 'closed'=>4 );
		$current_rank = $rank[ $status ] ?? -1;
		$html = '<section class="cvd-tracking" data-customer-tracking="' . esc_attr( $order->get_id() ) . '" data-tracking-key="' . esc_attr( $order->get_order_key() ) . '"><p class="cvd-kicker">Pedido #' . esc_html( $order->get_order_number() ) . '</p><h1 data-tracking-title>' . esc_html( self::customer_status_label( $status ) ) . '</h1><p class="cvd-tracking-updated">Última actualización: ' . esc_html( $order->get_date_modified() ? wc_format_datetime( $order->get_date_modified() ) : '' ) . '</p><ol class="cvd-tracking-steps">';
		foreach ( array_values( $steps ) as $index => $label ) { $html .= '<li class="' . ( $index <= $current_rank ? 'is-complete' : '' ) . '"><span></span><strong>' . esc_html( $label ) . '</strong></li>'; }
		$html .= '</ol><div class="cvd-courier-location" data-courier-location hidden><strong>Ubicación del mensajero</strong><span data-location-time>Esperando actualización…</span><a class="cvd-secondary" data-location-link target="_blank" rel="noopener">Abrir en el mapa</a></div>';
		$map = esc_url( (string) $order->get_meta( '_cvd_map_url', true ) );
		if ( $map && $current_rank >= 2 ) { $html .= '<a class="cvd-primary" href="' . $map . '" target="_blank" rel="noopener">Abrir dirección de entrega</a>'; }
		if ( in_array( $status, array( 'incident', 'failed', 'returned', 'cancelled' ), true ) ) { $html .= '<p class="cvd-tracking-alert">Casa Viva está revisando esta entrega. Te contactaremos si necesitamos confirmar algún dato.</p>'; }
		$confirmed = (bool) $order->get_meta( '_cvd_customer_confirmed_at', true );
		if ( in_array( $status, array( 'delivered', 'cash_returned', 'closed' ), true ) ) {
			if ( $confirmed ) { $html .= '<div class="cvd-confirmed-delivery"><strong>Entrega confirmada</strong><span>Gracias por evaluar el servicio.</span></div>'; }
			else { $html .= '<form class="cvd-delivery-rating" data-delivery-rating><h2>Confirma tu entrega</h2><p>Tu confirmación no libera pagos automáticamente; Casa Viva verificará la operación.</p><label>Evaluación<select name="rating" required><option value="">Selecciona</option><option value="5">5 · Excelente</option><option value="4">4 · Muy buena</option><option value="3">3 · Buena</option><option value="2">2 · Regular</option><option value="1">1 · Mala</option></select></label><label>Comentario opcional<textarea name="comment" maxlength="400" rows="3"></textarea></label><button class="cvd-primary" type="submit">Confirmar entrega</button><span data-rating-status></span></form>'; }
		}
		return $html . '</section>';
	}

	private static function customer_status_label( string $status ): string {
		$labels = array( 'unassigned'=>'Estamos preparando tu pedido','offered'=>'Estamos preparando tu pedido','assigned'=>'Mensajero asignado','accepted'=>'Mensajero asignado','to_store'=>'El mensajero va a recoger tu pedido','picked_up'=>'Pedido entregado al mensajero','handed_over'=>'Tu pedido va en camino','delivered'=>'Pedido entregado','cash_returned'=>'Pedido entregado','closed'=>'Pedido completado','incident'=>'Estamos revisando tu entrega','failed'=>'No se pudo completar la entrega','returned'=>'Pedido devuelto a Casa Viva','cancelled'=>'Pedido cancelado' );
		return $labels[ $status ] ?? 'Pedido recibido';
	}

	public static function render_thankyou_tracking( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true ) ) { echo '<p class="cvd-order-tracking-link"><a class="button" href="' . esc_url( self::tracking_url( $order ) ) . '">Seguir mi pedido</a></p>'; }
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
		<?php foreach ($orders as $order) : $current=self::status($order); $assigned=absint($order->get_meta('_cvd_messenger_user_id',true)); $next_actions = array(); if ('unassigned'===$current) {$next_actions['offered']='Publicar oferta';} if (in_array($current,array('accepted','to_store'),true)) {$next_actions['picked_up']='Entregado al mensajero';} if ('delivered'===$current) {$next_actions['cash_returned']='Registrar efectivo devuelto';} if ('cash_returned'===$current && current_user_can('manage_woocommerce')) {$next_actions['closed']='Cerrar operación';} if ('incident'===$current) {$next_actions=array('accepted'=>'Retomar antes de salida','picked_up'=>'Retomar recogido','handed_over'=>'Retomar en camino','returned'=>'Marcar devuelta','failed'=>'Cerrar sin entrega');} elseif (!in_array($current,array('closed','failed','cancelled'),true)) {$next_actions['incident']='Registrar incidencia';} ?><article class="cvd-sale-card"><div class="cvd-sale-top"><div><span class="cvd-sale-status"><?php echo esc_html(self::label($current)); ?></span><h2>Pedido #<?php echo esc_html($order->get_order_number()); ?></h2><small><?php echo esc_html($order->get_formatted_billing_full_name()); ?></small></div><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></div><form class="cvd-delivery-assignment" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="cvd_delivery_assign"><input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>"><label>Mensajero<select name="messenger_id"><option value="0">Sin asignar</option><?php foreach($messengers as $m): ?><option value="<?php echo esc_attr($m->ID); ?>" <?php selected($assigned,$m->ID); ?>><?php echo esc_html($m->display_name); ?></option><?php endforeach; ?></select></label><?php wp_nonce_field('cvd_delivery_assign_'.$order->get_id()); ?><button type="submit">Asignar</button></form><div class="cvd-sale-actions">
		<?php foreach($next_actions as $next=>$label): ?><?php if ('incident'===$next): ?><form method="post" action="<?php echo esc_url(self::action_url($order->get_id(),$next)); ?>"><input name="note" required maxlength="240" placeholder="Describe la incidencia"><button type="submit"><?php echo esc_html($label); ?></button></form><?php else: ?><a href="<?php echo esc_url(self::action_url($order->get_id(),$next)); ?>"><?php echo esc_html($label); ?></a><?php endif; ?><?php endforeach; ?></div><details><summary>Historial</summary><?php if($order->get_meta('_cvd_customer_confirmed_at',true)): ?><p><strong>Cliente confirmó la entrega · <?php echo esc_html(absint($order->get_meta('_cvd_customer_rating',true))); ?>/5</strong></p><?php endif; ?><?php $events=$order->get_meta('_cvd_delivery_history',true); foreach(array_reverse(is_array($events)?$events:array()) as $event): $actor=get_userdata(absint($event['actor_user_id']??0)); ?><p><?php echo esc_html(($event['at']??'').' · '.self::label($event['to']??'').' · '.($actor?$actor->display_name:'Sistema')); ?></p><?php endforeach; ?></details></article><?php endforeach; ?></div></section><?php return (string)ob_get_clean();
	}
}
