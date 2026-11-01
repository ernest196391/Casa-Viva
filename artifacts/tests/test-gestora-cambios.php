<?php
/**
 * 3.14.103 (Ernesto 31-oct, caso Niurka #5976): la gestora cambia SU pedido sin Lennys ni Ernesto.
 * WordPress y WooCommerce simulados (sin base de datos ni red). Se cargan las clases de verdad:
 * CVD_Bot_Delivery_Change (recogida → mensajería), CVD_Order_Edit (cancelar, con sus frenos) y CVD_Gestora_Order_Actions.
 * Casos: 1) recogida → mensajería, 2) mensajería → recogida, 3) cancelar; permisos, frenos y registro para el resumen.
 * Ejecutar: php artifacts/tests/test-gestora-cambios.php   (sale con 1 si algo falla)
 */

define( 'ABSPATH', __DIR__ . '/' );
date_default_timezone_set( 'America/Havana' );

$GLOBALS['orders'] = array(); $GLOBALS['options'] = array(); $GLOBALS['cleared'] = array(); $GLOBALS['avisos'] = array(); $GLOBALS['transitions'] = array();
function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function add_action() {}
function current_time( $t, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_date( $f ) { return date( $f ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }
function rest_sanitize_boolean( $v ) { return in_array( $v, array( true, 1, '1', 'true' ), true ); }
function remove_accents( $s ) { return strtr( $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U' ) ); }
function wp_next_scheduled() { return false; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { $GLOBALS['cleared'][] = $hook . ':' . implode( ',', $args ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-' . substr( md5( (string) mt_rand() ), 0, 12 ); }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
function wc_get_orders( $q ) {
	return array_values( array_filter( $GLOBALS['orders'], static function ( $o ) use ( $q ) {
		if ( isset( $q['status'] ) && ! in_array( $o->status, (array) $q['status'], true ) ) { return false; }
		return ! isset( $q['meta_key'] ) || (string) $o->get_meta( $q['meta_key'] ) === (string) $q['meta_value'];
	} ) );
}

class WP_User { public $ID; public $display_name; public $roles; public function __construct( $id, $name, $roles = array() ) { $this->ID = $id; $this->display_name = $name; $this->roles = $roles; } }
$GLOBALS['users'] = array(
	1 => array( new WP_User( 1, 'Lennys Rondon', array( 'administrator' ) ), '5356885368' ),
	50 => array( new WP_User( 50, 'Niurka', array( 'cvd_gestora' ) ), '5352913588' ),
	51 => array( new WP_User( 51, 'Katia', array( 'cvd_gestora' ) ), '5350000001' ),
	70 => array( new WP_User( 70, 'Daiquel', array( 'cvd_messenger' ) ), '5350000070' ),
);
function get_userdata( $id ) { return isset( $GLOBALS['users'][ (int) $id ] ) ? $GLOBALS['users'][ (int) $id ][0] : false; }
function get_user_meta( $id, $k, $single = true ) { return '_cvd_whatsapp' === $k && isset( $GLOBALS['users'][ (int) $id ] ) ? $GLOBALS['users'][ (int) $id ][1] : ''; }
function user_can( $user, $cap ) { $id = $user instanceof WP_User ? $user->ID : (int) $user; return 1 === $id && in_array( $cap, array( 'manage_woocommerce', 'cvd_manage_sales' ), true ); }
function get_users( $q ) { return array( $GLOBALS['users'][1][0] ); }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WC_Order_Refund {}
class WC_Order_Item { public $name; public $qty; public function __construct( $n, $q = 1 ) { $this->name = $n; $this->qty = $q; } public function get_name() { return $this->name; } public function get_quantity() { return $this->qty; } }
class WC_Order {
	public $id; public $status = 'processing'; public $meta = array(); public $notes = array(); public $items = array();
	public $billing_address = ''; public $shipping_address = ''; public $billing_city = ''; public $first = 'Sebastian';
	public function __construct( $id, array $meta, array $items = array() ) { $this->id = $id; $this->meta = $meta; $this->items = $items; $GLOBALS['orders'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_order_number() { return (string) $this->id; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function get_status() { return $this->status; }
	public function set_status( $s ) { $this->status = $s; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function save() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_billing_first_name() { return $this->first; }
	public function get_billing_address_1() { return $this->billing_address; }
	public function get_shipping_address_1() { return $this->shipping_address; }
	public function set_billing_address_1( $v ) { $this->billing_address = $v; }
	public function set_shipping_address_1( $v ) { $this->shipping_address = $v; }
	public function set_billing_city( $v ) { $this->billing_city = $v; }
	public function set_shipping_city( $v ) {}
}
class WP_REST_Response { public $data; public $headers = array(); public function __construct( $d = null ) { $this->data = $d; } public function header( $k, $v ) { $this->headers[ $k ] = $v; } }
class WP_REST_Request implements ArrayAccess {
	private $p; private $url;
	public function __construct( array $p = array(), array $url = array() ) { $this->p = $p; $this->url = $url; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
	public function get_json_params() { return $this->p; }
	public function offsetExists( $o ): bool { return isset( $this->url[ $o ] ); }
	public function offsetGet( $o ): mixed { return $this->url[ $o ] ?? null; }
	public function offsetSet( $o, $v ): void {}
	public function offsetUnset( $o ): void {}
}
class FakeDb { public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); } public function get_var( $q ) { return 1; } }
$GLOBALS['wpdb'] = new FakeDb();

class CVD_Delivery {
	public static function is_test_order( $o ): bool { return 'yes' === $o->get_meta( '_test' ); }
	public static function status( $o ): string { return 'yes' === $o->get_meta( '_cvd_delivery_incident_active' ) ? 'incident' : ( sanitize_key( (string) $o->get_meta( '_cvd_delivery_status' ) ) ?: 'unassigned' ); }
	public static function label( string $s ): string { return array( 'unassigned' => 'Por ofertar', 'offered' => 'Oferta abierta', 'assigned' => 'Asignada', 'picked_up' => 'Entregado al mensajero' )[ $s ] ?? $s; }
	public static function dispatch_new_order( $id ): void { $o = wc_get_order( $id ); if ( 'delivery' === $o->get_meta( '_cvd_fulfillment_type' ) && 'unassigned' === self::status( $o ) ) { $o->update_meta_data( '_cvd_delivery_status', 'offered' ); } }
}
class CVD_Shipping_Rates {
	public const VERSION = 'test';
	public static function rates(): array { return array( array( 'active' => 1, 'municipality' => 'Plaza de la Revolución' ), array( 'active' => 1, 'municipality' => 'Playa' ) ); }
	public static function localities(): array { return array( 'Plaza de la Revolución' => array( 'Vedado', 'Nuevo Vedado' ), 'Playa' => array( 'Miramar' ) ); }
	public static function quote( string $m, string $z ): array {
		if ( 'Plaza de la Revolución' === $m && false !== stripos( $z, 'vedado' ) ) { return array( 'status' => 'zone', 'fee' => 1500, 'label' => 'Vedado' ); }
		return array( 'status' => 'unknown' );
	}
}
class CVD_Bot_Bridge {
	public static function gestora_by_phone( string $p ): ?WP_User { foreach ( array( 50, 51 ) as $id ) { if ( substr( preg_replace( '/\D/', '', $p ), -8 ) === substr( $GLOBALS['users'][ $id ][1], -8 ) ) { return $GLOBALS['users'][ $id ][0]; } } return null; }
}
class CVD_Order_Avisos {
	public static function snapshot( $o ): array { return array( 'address' => $o->get_shipping_address_1() ?: $o->get_billing_address_1() ); }
	public static function begin_manual(): void {}
	public static function end_manual(): void {}
	public static function record_cancel( $o, $b, $r, $u, $s, $m, $st ): void { $GLOBALS['avisos'][] = array( 'cancel', $o->get_id(), $s, $m['name'] ?? '', $st ); }
	public static function record_to_pickup( $o, $b, $u, $s, $m, $st ): void { $GLOBALS['avisos'][] = array( 'to_pickup', $o->get_id(), $s, $m['name'] ?? '', $st ); }
}
class CVD_Order_Transition_Service {
	public const CONFLICT = 'CONFLICT';
	public static function cancel( int $id, string $to, array $ctx ): array { $o = wc_get_order( $id ); $o->status = 'cancelled'; $o->meta['_cvd_commission_status'] = 'cancelled'; $GLOBALS['transitions'][] = array( $id, $ctx['source'] ); return array( 'success' => true ); }
}

$root = dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/';
require $root . 'class-cvd-bot-delivery-change.php';
require $root . 'class-cvd-order-edit.php';
require $root . 'class-cvd-gestora-order-actions.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; echo ( $ok ? 'OK   ' : 'FALLA ' ) . $msg . "\n"; if ( ! $ok ) { $fail++; } }
$niurka = '5352913588'; $katia = '5350000001'; $lennys = '5356885368';
$pickup = static fn( $id ) => new WC_Order( $id, array( '_cvd_fulfillment_type' => 'pickup', '_cvd_owner_type' => 'gestora', '_cvd_owner_user_id' => 50 ), array( new WC_Order_Item( 'Tabla de planchar' ) ) );
$delivery = static function ( $id, $stage, $messenger = 0 ) {
	$m = array( '_cvd_fulfillment_type' => 'delivery', '_cvd_owner_type' => 'gestora', '_cvd_owner_user_id' => 50, '_cvd_delivery_status' => $stage, '_cvd_shipping_fee_cup' => 1500, '_cvd_locality' => 'Vedado', '_cvd_shipping_courier_amount_cup' => '1350.00' );
	if ( $messenger ) { $m['_cvd_messenger_user_id'] = $messenger; }
	$o = new WC_Order( $id, $m, array( new WC_Order_Item( 'Tabla de planchar' ) ) );
	$o->billing_address = $o->shipping_address = '19 e/ L y K #456';
	return $o;
};
$bot = static fn( $method, $id, $p ) => CVD_Gestora_Order_Actions::$method( new WP_REST_Request( $p, array( 'id' => $id ) ) )->data;

// ---------- 1) Recogida → mensajería (lo que pidió Niurka para el #5976) ----------
$o = $pickup( 5976 );
$r = CVD_Bot_Delivery_Change::to_delivery( new WP_REST_Request( array( 'phone' => $katia, 'address' => '19 e/ L y K #456', 'municipality' => 'Plaza' ), array( 'id' => 5976 ) ) )->data;
check( 'not_owner' === $r['result'] && 'pickup' === $o->meta['_cvd_fulfillment_type'], '1a. otra gestora no puede pasar el #5976 a mensajería' );
$r = CVD_Bot_Delivery_Change::to_delivery( new WP_REST_Request( array( 'phone' => $niurka, 'address' => '19 e/ L y K #456', 'municipality' => 'Plaza', 'zone' => 'Vedado', 'fee' => 1000 ), array( 'id' => 5976 ) ) )->data;
check( 'fee_mismatch' === $r['result'] && 1500 === $r['fee'] && 'pickup' === $o->meta['_cvd_fulfillment_type'], '1b. costo distinto de la tarifa oficial: no cambia nada (fee_mismatch 1500)' );
$r = CVD_Bot_Delivery_Change::to_delivery( new WP_REST_Request( array( 'phone' => $niurka, 'address' => '19 e/ L y K #456', 'municipality' => 'Plaza', 'zone' => 'Vedado', 'dry' => '1' ), array( 'id' => 5976 ) ) )->data;
check( 'quote' === $r['result'] && 1500 === $r['fee'] && 'pickup' === $o->meta['_cvd_fulfillment_type'], '1c. dry=1 solo cotiza (lo que usa el bot para ofrecer «¿Paso el #5976…?»)' );
$r = CVD_Bot_Delivery_Change::to_delivery( new WP_REST_Request( array( 'phone' => $niurka, 'address' => '19 e/ L y K #456', 'municipality' => 'Plaza', 'zone' => 'Vedado', 'fee' => 1500 ), array( 'id' => 5976 ) ) )->data;
check( 'changed' === $r['result'] && 'published' === $r['dispatch'], '1d. con la tarifa oficial: pasa a domicilio y sale al grupo' );
check( 'delivery' === $o->meta['_cvd_fulfillment_type'] && 1500 === $o->meta['_cvd_shipping_fee_cup'] && '19 e/ L y K #456' === $o->billing_address, '1e. queda con dirección y 1.500 CUP' );
check( false !== strpos( end( $o->notes ), 'Niurka' ) && false !== strpos( end( $o->notes ), 'por WhatsApp' ), '1f. nota con quién y por dónde' );

// ---------- 2) Mensajería → recogida ----------
// 2a. El mismo #5976, ya ofertado en el grupo: Niurka lo devuelve a recogida.
$r = $bot( 'bot_to_pickup', 5976, array( 'phone' => $katia ) );
check( 'not_owner' === $r['result'] && 'delivery' === $o->meta['_cvd_fulfillment_type'], '2a. otra gestora no lo pasa a recogida' );
$r = $bot( 'bot_to_pickup', 5976, array( 'phone' => $niurka ) );
check( 'changed' === $r['result'] && true === $r['wasOffered'] && '' === $r['messenger'] && 1500 === $r['feeRemoved'], '2b. ofertado en el grupo → recogida (wasOffered, sin mensajero)' );
check( 'pickup' === $o->meta['_cvd_fulfillment_type'] && ! isset( $o->meta['_cvd_shipping_fee_cup'] ) && ! isset( $o->meta['_cvd_delivery_status'] ) && ! isset( $o->meta['_cvd_locality'] ), '2c. sin mensajería, sin reparto, sin tarifa' );
check( '' === $o->shipping_address && '19 e/ L y K #456' === $o->billing_address, '2d. la dirección del cliente queda en sus datos y en la nota' );
check( false !== strpos( end( $o->notes ), 'RECOGIDA EN TIENDA' ) && false !== strpos( end( $o->notes ), 'Niurka' ) && false !== strpos( end( $o->notes ), 'grupo de mensajeros' ), '2e. nota: quién, qué y que se retira del grupo' );
check( in_array( 'cvd_dispatch_new_order:5976', $GLOBALS['cleared'], true ), '2f. no vuelve a salir al grupo (se quita la tarea programada)' );
check( array( 'to_pickup', 5976, 'gestora_whatsapp', '', 'offered' ) === end( $GLOBALS['avisos'] ), '2g. aviso «to_pickup» para el bot (retirar del grupo, avisar a la tienda)' );
$r = $bot( 'bot_to_pickup', 5976, array( 'phone' => $niurka ) );
check( 'already_pickup' === $r['result'], '2h. repetido: ya es recogida, no cambia nada' );
// 2i. Con mensajero asignado (aún en la tienda): se libera y se le avisa.
$a = $delivery( 6001, 'assigned', 70 );
$r = $bot( 'bot_to_pickup', 6001, array( 'phone' => $niurka ) );
check( 'changed' === $r['result'] && 'Daiquel' === $r['messenger'] && ! isset( $a->meta['_cvd_messenger_user_id'] ), '2i. asignado a Daiquel: queda libre' );
check( array( 'to_pickup', 6001, 'gestora_whatsapp', 'Daiquel', 'assigned' ) === end( $GLOBALS['avisos'] ), '2j. el bot le avisa a Daiquel' );
// 2k. El mensajero ya lo recogió: no.
$b = $delivery( 6002, 'picked_up', 70 );
$r = $bot( 'bot_to_pickup', 6002, array( 'phone' => $niurka ) );
check( 'with_messenger' === $r['result'] && 'Daiquel' === $r['messenger'] && 'delivery' === $b->meta['_cvd_fulfillment_type'] && 70 === $b->meta['_cvd_messenger_user_id'], '2k. con el paquete en la calle: no se toca' );
// 2l. Administración por su WhatsApp sí puede.
$c = $delivery( 6003, 'unassigned' );
$r = $bot( 'bot_to_pickup', 6003, array( 'phone' => $lennys ) );
check( 'changed' === $r['result'] && false !== strpos( end( $c->notes ), '(administración)' ), '2l. Lennys por WhatsApp también puede (nota «administración»)' );

// ---------- 3) Cancelar ----------
$d = $pickup( 6010 );
$r = $bot( 'bot_cancel', 6010, array( 'phone' => $niurka, 'reason' => '' ) );
check( 'reason_missing' === $r['result'] && 'processing' === $d->status, '3a. sin motivo no cancela (el bot lo pide)' );
$r = $bot( 'bot_cancel', 6010, array( 'phone' => $katia, 'reason' => 'no lo quiere' ) );
check( 'not_owner' === $r['result'] && 'processing' === $d->status, '3b. solo la gestora dueña' );
$r = $bot( 'bot_cancel', 6002, array( 'phone' => $niurka, 'reason' => 'el cliente no contesta' ) );
check( 'with_messenger' === $r['result'] && 'Que lo devuelva a la tienda y lo cancela Lennys.' === substr( $r['message'], -48 ) && 'processing' === $b->status, '3c. el mensajero ya lo tiene: «que lo devuelva a la tienda y lo cancela Lennys»' );
$r = $bot( 'bot_cancel', 6010, array( 'phone' => $niurka, 'reason' => 'El cliente ya no la quiere' ) );
check( 'cancelled' === $r['result'] && 'cancelled' === $d->status, '3d. cancelado: el pedido queda cancelado (vuelve el stock y se anula la comisión por la cascada de siempre)' );
check( false !== strpos( end( $d->notes ), 'Niurka (gestora, por WhatsApp)' ) && false !== strpos( end( $d->notes ), 'El cliente ya no la quiere' ), '3e. nota con quién, por dónde y por qué' );
check( array( 6010, 'cvd_order_edit_gestora_whatsapp' ) === end( $GLOBALS['transitions'] ), '3f. usa la misma cancelación de la tienda (CVD_Order_Edit::cancel)' );
check( array( 'cancel', 6010, 'gestora_whatsapp', '', 'unassigned' ) === end( $GLOBALS['avisos'] ), '3g. aviso de cancelación para el bot (tienda y mensajero)' );
$e = $delivery( 6011, 'assigned', 70 );
$r = CVD_Gestora_Order_Actions::cancel( $e, $GLOBALS['users'][50][0], 'gestora', 'Se equivocó de producto', 'web' );
check( 'cancelled' === $r['result'] && 'Daiquel' === $r['messenger'] && false !== strpos( end( $e->notes ), 'desde su oficina' ), '3h. desde la oficina, asignado a Daiquel (aún en la tienda): cancela y le avisa' );
$f = $pickup( 6012 ); $f->status = 'completed';
$r = $bot( 'bot_cancel', 6012, array( 'phone' => $niurka, 'reason' => 'ya no' ) );
check( 'blocked' === $r['result'] && 'completed' === $f->status, '3i. entregado: no se cancela (es devolución)' );
check( 'Esta cuenta no puede cancelar pedidos.' === CVD_Order_Edit::cancel_block( $pickup( 6013 ), 50 ), '3j. sin ser dueña, la cuenta de gestora sigue sin poder cancelar en /ventas/' );

// ---------- Pedidos abiertos y registro del día ----------
$rows = CVD_Gestora_Order_Actions::open_orders( $GLOBALS['users'][50][0] );
$by = array(); foreach ( $rows as $row ) { $by[ $row['id'] ] = $row; }
check( ! isset( $by[6010] ) && isset( $by[5976], $by[6002] ), '4a. solo pedidos abiertos de ella' );
check( $by[5976]['can']['toDelivery'] && ! $by[5976]['can']['toPickup'] && $by[5976]['can']['cancel'], '4b. recogida: a mensajería y cancelar' );
check( ! $by[6002]['can']['toPickup'] && ! $by[6002]['can']['cancel'] && false !== strpos( $by[6002]['whyNot'], 'lo cancela Lennys' ), '4c. con el mensajero en la calle: ni recogida ni cancelar, y dice por qué' );
$log = CVD_Gestora_Order_Actions::changes_on( date( 'Y-m-d' ) );
$acts = array_map( static fn( $x ) => $x['action'] . ' #' . $x['number'] . ' ' . $x['by'], $log );
check( in_array( 'to_delivery #5976 Niurka', $acts, true ) && in_array( 'to_pickup #5976 Niurka', $acts, true ) && in_array( 'cancel #6010 Niurka', $acts, true ), '4d. registro del día para el resumen de Lennys (' . count( $log ) . ' cambios)' );
$r = $bot( 'bot_changes', 0, array( 'day' => date( 'Y-m-d' ) ) );
check( count( $r['changes'] ) === count( $log ), '4e. GET /bot/gestora-changes devuelve el día' );

echo $fail ? "\n$fail falla(s)\n" : "\nTodo bien\n";
exit( $fail ? 1 : 0 );
