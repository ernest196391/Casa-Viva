<?php
/**
 * 3.14.39 (Lennys 18-oct): botón «Entregado» de un toque desde el vale (CVD_Messenger_Delivered_Link).
 * Mismo estilo de stubs que test-order-transition-service-1c.php. Corre con: php artifacts/tests/test-messenger-delivered-link.php
 */

define( 'ABSPATH', __DIR__ );

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_textarea_field( $value ): string { return trim( (string) $value ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function current_time( $type, $gmt = false ): string { return $GLOBALS['cvt_now'] ?? '2026-10-18 19:00:00'; }
function wp_generate_uuid4(): string { static $i = 0; return 'uuid-' . ++$i; }
function wp_json_encode( $value ): string { return json_encode( $value ); }
function get_current_user_id(): int { return $GLOBALS['cvt_user_id'] ?? 0; }
function get_userdata( $id ) { return $GLOBALS['cvt_users'][ (int) $id ] ?? false; }
function user_can( $user, $capability ): bool { return ! empty( $user->caps[ $capability ] ); }
function update_user_meta( $id, $key, $value ): void {}
function clean_post_cache( $id ): void {}
function wc_get_order( $id ) { return $GLOBALS['cvt_orders'][ (int) $id ] ?? false; }
function wc_format_decimal( $value, $dp = false, $trim = false ): string { $n = (float) $value; return false === $dp ? (string) $n : number_format( $n, (int) $dp, '.', '' ); }
function wp_salt( $scheme = 'auth' ): string { return 'sal-de-prueba-' . $scheme; }
function home_url( $path = '' ): string { return 'https://casaviva.test' . $path; }
function add_query_arg( array $args, string $url ): string { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }

class WP_User {
	public int $ID;
	public array $roles;
	public array $caps;
	public string $display_name;
	public function __construct( int $id, array $roles, array $caps, string $name = '' ) { $this->ID=$id; $this->roles=$roles; $this->caps=$caps; $this->display_name=$name ?: 'Usuario '.$id; }
}

class WC_Order {
	private int $id;
	public array $meta = array();
	public array $notes = array();
	public string $status = 'processing';
	public float $total = 36.0;
	public string $first = 'Ana';
	public string $note = '';
	public function __construct( int $id ) { $this->id=$id; }
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_total() { return $this->total; }
	public function get_status(): string { return $this->status; }
	public function has_status( $status ): bool { return is_array($status)?in_array($this->status,$status,true):$this->status===$status; }
	public function set_status( $status, $note = '', $manual = false ): void { $this->status=$status; if($note){$this->notes[]=$note;} }
	public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
	public function update_meta_data( $key, $value ): void { $this->meta[$key]=$value; }
	public function delete_meta_data( $key ): void { unset($this->meta[$key]); }
	public function add_order_note( $note ): void { $this->notes[]=$note; }
	public function save(): int { return $this->id; }
	public function get_billing_first_name(): string { return $this->first; }
	public function get_billing_last_name(): string { return 'Pérez'; }
	public function get_shipping_first_name(): string { return $this->first; }
	public function get_shipping_last_name(): string { return 'Pérez'; }
	public function get_formatted_billing_full_name(): string { return $this->first . ' Pérez'; }
	public function get_customer_note(): string { return $this->note; }
	public function get_billing_phone(): string { return '53123456'; }
	public function get_shipping_phone(): string { return ''; }
	public function get_billing_city(): string { return 'Plaza'; }
	public function get_billing_state(): string { return 'La Habana'; }
	public function snapshot(): array { return array($this->meta,$this->notes,$this->status); }
	public function restore( array $snapshot ): void { [$this->meta,$this->notes,$this->status]=$snapshot; }
}
class WC_Order_Refund extends WC_Order {}

class CVT_WPDB {
	public string $prefix = 'cvt_';
	private array $snapshots = array();
	public function prepare( $query, ...$args ): string { return $query . '|' . implode('|',$args); }
	public function get_var( $query ) { return 1; }
	public function query( $query ) {
		if('START TRANSACTION'===$query){foreach($GLOBALS['cvt_orders'] as $id=>$order){$this->snapshots[$id]=$order->snapshot();}}
		if('ROLLBACK'===$query){foreach($this->snapshots as $id=>$snapshot){$GLOBALS['cvt_orders'][$id]->restore($snapshot);} $this->snapshots=array();}
		if('COMMIT'===$query){$this->snapshots=array();}
		return 1;
	}
}

/** Forma de pago de prueba: la del meta _cvt_payment (como la daría CVD_Cuban_Checkout::order_payment). */
class CVD_Cuban_Checkout {
	public static function order_payment( $order ): ?array { $p = $order->get_meta( '_cvt_payment', true ); return is_array( $p ) ? $p : null; }
	public static function order_assembly( $order ): ?array { return null; }
}

$core = __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/';
require_once $core . 'class-cvd-order-events.php';
require_once $core . 'class-cvd-order-transition-service.php';
require_once $core . 'class-cvd-delivery.php';
require_once $core . 'class-cvd-bot-bridge.php';
require_once $core . 'class-cvd-messenger-delivered-link.php';

function cvt_check( bool $condition, string $label ): void { if(!$condition){fwrite(STDERR,"FALLO: {$label}\n");exit(1);} echo "OK: {$label}\n"; }
function cvt_events( string $to ): int { return count( array_filter( CVD_Order_Event_Timeline::read( 459, array( 'domain' => 'delivery' ), 1, 50 )['events'], fn( $e ) => $to === $e['to_state'] ) ); }
function cvt_reset( string $status = 'picked_up', ?array $payment = null ): WC_Order {
	$GLOBALS['cvt_user_id']=0;
	$GLOBALS['cvt_users']=array(
		10=>new WP_User(10,array('cvd_clerk'),array('cvd_manage_sales'=>true),'Nana'),
		20=>new WP_User(20,array('cvd_messenger'),array(),'Félix Gómez'),
		21=>new WP_User(21,array('cvd_messenger'),array(),'Ely'),
	);
	$order=new WC_Order(459);
	$order->meta=array('_cvd_operation_status'=>'with_courier','_cvd_delivery_status'=>$status,'_cvd_messenger_user_id'=>20,'_cvd_fulfillment_type'=>'delivery','_cvd_delivery_accepted_at'=>'2026-10-18 15:00:00');
	if($payment){$order->meta['_cvt_payment']=$payment;}
	$GLOBALS['cvt_orders']=array(459=>$order);
	$GLOBALS['wpdb']=new CVT_WPDB();
	CVD_Order_Events::use_repository(new CVD_Array_Order_Event_Repository());
	return $order;
}
$m20 = static fn() => $GLOBALS['cvt_users'][20];
$cash_cup = array( 'kind' => 'cash_cup', 'label' => 'Efectivo CUP', 'currency' => 'CUP', 'amount' => 28260.0, 'prepaid' => false, 'text' => 'Efectivo CUP · 28.260 CUP' );
$zelle = array( 'kind' => 'zelle', 'label' => 'Zelle', 'currency' => 'USD', 'amount' => 37.44, 'prepaid' => true, 'text' => 'Zelle · 37,44 USD · por adelantado' );

// --- Firma del enlace ---
$key = 'clave';
$token = CVD_Messenger_Delivered_Link::sign( 459, 20, 2000000000, $key );
cvt_check( 20 === CVD_Messenger_Delivered_Link::verify( 459, $token, 1900000000, $key ), 'enlace firmado: devuelve el mensajero del pedido' );
cvt_check( 0 === CVD_Messenger_Delivered_Link::verify( 460, $token, 1900000000, $key ), 'el enlace de un pedido no sirve para otro' );
cvt_check( 0 === CVD_Messenger_Delivered_Link::verify( 459, str_replace( '-20-', '-21-', $token ), 1900000000, $key ), 'cambiar el mensajero en el enlace lo invalida' );
cvt_check( 0 === CVD_Messenger_Delivered_Link::verify( 459, $token, 2000000001, $key ), 'el enlace caduca' );
cvt_check( 0 === CVD_Messenger_Delivered_Link::verify( 459, $token, 1900000000, 'otra' ) && 0 === CVD_Messenger_Delivered_Link::verify( 459, 'basura', 1, $key ), 'otra clave o un token roto no valen' );

$order = cvt_reset();
$url = CVD_Messenger_Delivered_Link::url( $order, 1900000000 );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
cvt_check( '459' === ( $q['cvd_entregado'] ?? '' ) && 20 === CVD_Messenger_Delivered_Link::verify( 459, (string) $q['t'], 1900000000 + 6 * 86400, wp_salt( 'auth' ) . '|cvd-entregado' ), 'url(): enlace del vale para el mensajero asignado, vale 7 días' );
cvt_check( 0 === CVD_Messenger_Delivered_Link::verify( 459, (string) $q['t'], 1900000000 + 8 * 86400, wp_salt( 'auth' ) . '|cvd-entregado' ), 'url(): a los 8 días ya no vale' );
unset( $order->meta['_cvd_messenger_user_id'] );
cvt_check( '' === CVD_Messenger_Delivered_Link::url( $order ), 'url(): sin mensajero no hay enlace' );
$order = cvt_reset(); $order->meta['_cvd_test_order'] = 'auto';
cvt_check( '' === CVD_Messenger_Delivered_Link::url( $order ), 'url(): pedido de prueba sin enlace' );
$order = cvt_reset( 'delivered' );
cvt_check( '' === CVD_Messenger_Delivered_Link::url( $order ), 'url(): ya entregado, sin enlace' );

// --- Lo que se declara con un toque (misma regla que deliveredAmounts() del bot) ---
$a = CVD_Messenger_Delivered_Link::default_amounts( null, 36.0 );
cvt_check( 36.0 === $a['usd'] && 0.0 === $a['cup'] && '' === $a['method'], 'pedido de antes: lo del vale en USD' );
$a = CVD_Messenger_Delivered_Link::default_amounts( $cash_cup, 36.0 );
cvt_check( 28260.0 === $a['cup'] && 0.0 === $a['usd'], 'Efectivo CUP: los CUP del vale' );
$a = CVD_Messenger_Delivered_Link::default_amounts( array( 'kind' => 'cash_cup', 'currency' => 'CUP', 'amount' => null, 'prepaid' => false ), 36.0 );
cvt_check( 0.0 === $a['cup'] && 0.0 === $a['usd'], 'CUP sin tasa: 0 (hay que escribir la cifra)' );
$a = CVD_Messenger_Delivered_Link::default_amounts( array( 'kind' => 'transfer_cup', 'currency' => 'CUP', 'amount' => 28260.0, 'prepaid' => false ), 36.0 );
cvt_check( 28260.0 === $a['cup'] && 'transfer' === $a['method'], 'Transferencia CUP: método transferencia' );
$a = CVD_Messenger_Delivered_Link::default_amounts( array( 'kind' => 'clasica', 'currency' => 'USD', 'amount' => 41.4, 'prepaid' => false ), 36.0 );
cvt_check( 41.4 === $a['usd'] && 'transfer' === $a['method'], 'Clásica: lo del vale y transferencia' );
$a = CVD_Messenger_Delivered_Link::default_amounts( array( 'kind' => 'eur', 'currency' => 'EUR', 'amount' => 36.0, 'prepaid' => false ), 36.0 );
cvt_check( 36.0 === $a['eur'] && 0.0 === $a['usd'], 'Euros: los EUR del vale' );
$a = CVD_Messenger_Delivered_Link::default_amounts( $zelle, 36.0 );
cvt_check( $a['prepaid'] && 0.0 === $a['usd'] + $a['cup'] + $a['eur'], 'Zelle: no se lleva dinero de productos' );

// --- Plazo del dinero (America/Havana, octubre UTC-4) ---
cvt_check( 'antes de las 4:00 p. m.' === CVD_Messenger_Delivered_Link::deadline( strtotime( '2026-10-18 19:00:00 UTC' ) ), 'entregado a las 3:00 p. m.: antes de las 4:00 p. m.' );
cvt_check( 0 === strpos( CVD_Messenger_Delivered_Link::deadline( strtotime( '2026-10-18 20:30:00 UTC' ) ), 'mañana antes de las 4:00 p. m.' ), 'entregado a las 4:30 p. m.: mañana antes de las 4:00 p. m.' );

// --- El toque: picked_up → handed_over → delivered con el mensajero como actor ---
$order = cvt_reset();
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() );
cvt_check( 'delivered' === $r && 'delivered' === $order->get_meta( '_cvd_delivery_status', true ) && 'pending_return' === $order->get_meta( '_cvd_cash_status', true ), 'un toque: picked_up → handed_over → delivered, dinero pendiente' );
cvt_check( 1 === cvt_events( 'handed_over' ) && 1 === cvt_events( 'delivered' ), 'pasa por «En camino» y «Entregado», un evento cada uno' );
$last = array_values( array_filter( CVD_Order_Event_Timeline::read( 459, array( 'domain' => 'delivery' ), 1, 50 )['events'], fn( $e ) => 'delivered' === $e['to_state'] ) )[0];
cvt_check( 20 === (int) $last['actor_user_id'] && 'cvd_delivered_link' === $last['source'], 'actor = el mensajero asignado; origen = enlace del vale' );
cvt_check( '36.00' === $order->get_meta( '_cvd_collection_amount_usd', true ) && 'cash_usd' === $order->get_meta( '_cvd_collection_method', true ) && 0 === strpos( (string) $order->get_meta( '_cvd_collection_note', true ), 'Marcado con el enlace del vale' ), 'guarda lo cobrado como hoy (lo del vale)' );
cvt_check( 'Listo ✅ Lleva 36 USD a la tienda antes de las 4:00 p. m. La mensajería es tuya.' === CVD_Messenger_Delivered_Link::done_text( $order ), 'mensaje final: «Listo ✅ Lleva 36 USD a la tienda antes de las 4:00 p. m.»' );
$r2 = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() );
cvt_check( 'already' === $r2 && 1 === cvt_events( 'delivered' ), 'doble toque: «ya estaba», sin repetir nada' );
$bot = CVD_Bot_Bridge::mark_delivered_by_messenger( $order, $m20(), 36, 0, 0, '', 'whatsapp' );
cvt_check( 'already' === $bot['result'] && 1 === cvt_events( 'delivered' ), 'después «entregado» por WhatsApp: tampoco repite' );

// --- Asignado pero sin recoger: también, como por WhatsApp («quien entregó, lo tenía») ---
$order = cvt_reset( 'accepted' );
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() );
cvt_check( 'delivered' === $r && 1 === cvt_events( 'picked_up' ) && 1 === cvt_events( 'handed_over' ) && 1 === cvt_events( 'delivered' ), 'aceptado sin recoger: recogido automático → en camino → entregado' );

// --- Cobré otra cantidad ---
$order = cvt_reset( 'handed_over', $cash_cup );
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array( 'cvd_otra' => '1', 'cvd_usd' => '', 'cvd_cup' => '27500', 'cvd_eur' => '' ) );
cvt_check( 'delivered' === $r && '27500.00' === $order->get_meta( '_cvd_collection_amount_cup', true ) && '0.00' === $order->get_meta( '_cvd_collection_amount_usd', true ) && 'cash_cup' === $order->get_meta( '_cvd_collection_method', true ), '«Cobré otra cantidad»: 27.500 CUP' );
cvt_check( 0 === strpos( CVD_Messenger_Delivered_Link::done_text( $order ), 'Listo ✅ Lleva 27.500 CUP a la tienda' ), 'mensaje final con la otra cantidad' );
$order = cvt_reset( 'handed_over' );
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array( 'cvd_otra' => '1', 'cvd_usd' => '20', 'cvd_cup' => '5000' ) );
cvt_check( 'delivered' === $r && 'mixed' === $order->get_meta( '_cvd_collection_method', true ), 'USD y CUP a la vez: mixto' );

// --- CUP sin tasa: hay que escribir la cifra, nada cambia ---
$order = cvt_reset( 'picked_up', array( 'kind' => 'cash_cup', 'label' => 'Efectivo CUP', 'currency' => 'CUP', 'amount' => null, 'prepaid' => false, 'text' => '' ) );
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() );
cvt_check( 'needs_amount' === $r && 'picked_up' === $order->get_meta( '_cvd_delivery_status', true ) && 0 === cvt_events( 'delivered' ), 'sin cifra: pide la cantidad y no marca nada' );

// --- Zelle / PayPal: no se lleva dinero ---
$order = cvt_reset( 'picked_up', $zelle );
$r = CVD_Messenger_Delivered_Link::submit( $order, $m20(), array( 'cvd_otra' => '1', 'cvd_usd' => '50' ) );
cvt_check( 'delivered' === $r && '0.00' === $order->get_meta( '_cvd_collection_amount_usd', true ) && 'transfer' === $order->get_meta( '_cvd_collection_method', true ), 'Zelle: entregado sin dinero de productos (aunque escriba una cifra)' );
cvt_check( false !== strpos( CVD_Messenger_Delivered_Link::done_text( $order ), 'ya estaban pagados (Zelle)' ), 'mensaje final de pedido ya pagado' );

// --- Transferencia CUP: a la tarjeta de Casa Viva ---
$order = cvt_reset( 'handed_over', array( 'kind' => 'transfer_cup', 'label' => 'Transferencia CUP', 'currency' => 'CUP', 'amount' => 28260.0, 'prepaid' => false, 'text' => '' ) );
CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() );
cvt_check( 'transfer' === $order->get_meta( '_cvd_collection_method', true ) && false !== strpos( CVD_Messenger_Delivered_Link::done_text( $order ), 'tarjeta de Casa Viva' ), 'Transferencia CUP: no lleva dinero; anotado como transferencia' );

// --- Casos en los que NO cambia nada ---
$order = cvt_reset(); $before = $order->snapshot();
cvt_check( 'other' === CVD_Messenger_Delivered_Link::submit( $order, $GLOBALS['cvt_users'][21], array() ) && $before === $order->snapshot(), 'otro mensajero: «ya no es tuyo» y nada cambia' );
$order = cvt_reset(); $order->meta['_cvd_messenger_user_id'] = 21; $before = $order->snapshot();
cvt_check( 'other' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), 'pedido pasado a otro mensajero: el enlace viejo no marca' );
$order = cvt_reset(); $order->meta['_cvd_test_order'] = 'tutorial'; $before = $order->snapshot();
cvt_check( 'test' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), 'pedido de prueba: nada cambia' );
$order = cvt_reset(); $order->note = 'PRUEBA de Lennys'; $before = $order->snapshot();
cvt_check( 'test' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), '«PRUEBA» en la nota: nada cambia' );
$order = cvt_reset(); $order->status = 'cancelled'; $before = $order->snapshot();
cvt_check( 'cancelled' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), 'pedido cancelado: nada cambia' );
$order = cvt_reset( 'cancelled' );
cvt_check( 'cancelled' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ), 'reparto cancelado: nada cambia' );
$order = cvt_reset( 'closed' ); $before = $order->snapshot();
cvt_check( 'closed' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), 'pedido cerrado: nada cambia' );
$order = cvt_reset(); $order->meta['_cvd_delivery_incident_active'] = 'yes'; $before = $order->snapshot();
cvt_check( 'incident' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ) && $before === $order->snapshot(), 'con incidencia abierta: nada cambia' );
$order = cvt_reset( 'offered' ); unset( $order->meta['_cvd_messenger_user_id'] );
cvt_check( 'other' === CVD_Messenger_Delivered_Link::submit( $order, $m20(), array() ), 'carrera soltada (vuelve al grupo): nada cambia' );
$order = cvt_reset( 'cash_returned' );
cvt_check( 'already' === CVD_Messenger_Delivered_Link::blocked_reason( $order, 20 ), 'dinero ya recibido: «ya estaba»' );

// --- La página (HTML) ---
function esc_html( $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ): string { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
$body = new ReflectionMethod( 'CVD_Messenger_Delivered_Link', 'body' ); $body->setAccessible( true );
$order = cvt_reset( 'picked_up', $cash_cup ); $order->meta['_cvd_shipping_fee_cup'] = 3500; $order->meta['_cvd_locality'] = 'Playa';
$before = $order->snapshot();
$html = $body->invoke( null, $order, $m20(), 'tok', '' );
cvt_check( false !== strpos( $html, '✅ Entregado</button>' ) && false !== strpos( $html, 'Pedido #459' ) && false !== strpos( $html, 'Ana Pérez' ) && false !== strpos( $html, 'Playa' ), 'página: número, cliente, zona y botón grande «Entregado»' );
cvt_check( false !== strpos( $html, '28.260 CUP · Efectivo CUP + 3.500 CUP de tu mensajería' ) && false !== strpos( $html, 'Cobré otra cantidad' ) && false !== strpos( $html, 'name="cvd_cup"' ), 'página: lo que hay que cobrar y «Cobré otra cantidad» (USD y CUP)' );
cvt_check( $before === $order->snapshot(), 'abrir la página no cambia nada' );
$order = cvt_reset( 'picked_up', $zelle );
$html = $body->invoke( null, $order, $m20(), 'tok', '' );
cvt_check( false !== strpos( $html, 'Ya pagado por Zelle' ) && false === strpos( $html, 'Cobré otra cantidad' ), 'página Zelle: sin dinero de productos ni «otra cantidad»' );
$order = cvt_reset( 'closed' );
cvt_check( false !== strpos( $body->invoke( null, $order, $m20(), 'tok', '' ), 'Pedido cerrado' ), 'página de pedido cerrado: mensaje claro sin botón' );

echo "3.14.39 enlace «Entregado» del vale: pruebas completadas.\n";
