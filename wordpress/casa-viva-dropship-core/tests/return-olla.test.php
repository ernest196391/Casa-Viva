<?php
// 3.14.103 · Caso real del 31-oct: la olla de presión Ariete (pedido web de 62 USD, entregado y cobrado).
// Zaymi (dependienta) registra desde la caja la devolución con 62 USD en efectivo:
// vuelve el stock, se anula la comisión de la gestora, el pedido pasa a «reembolsado», queda la nota
// (quién, causa, USD y CUP) y queda marcado para Lennys. Repetir la misma acción no hace nada dos veces.
// Además: cuentas puras de CVD_Return_Plan (parcial, errores) y el caso abierto (no se devuelve: se cancela).
// Se corre con: php tests/return-olla.test.php  (sale con código 1 si algo falla). WordPress y WooCommerce son de mentira.
define( 'ABSPATH', __DIR__ . '/' );

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got, JSON_UNESCAPED_UNICODE ) . "\n  quería: " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . "\n"; }
}

// ---------------- WordPress / WooCommerce de mentira ----------------
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_REST_Response { public $data; public array $headers = array(); public function __construct( $data = null ) { $this->data = $data; } public function header( $k, $v ) { $this->headers[ $k ] = $v; } public function get_data() { return $this->data; } }
class WP_REST_Request implements ArrayAccess {
	private array $p; private array $json;
	public function __construct( array $url = array(), array $json = array() ) { $this->p = $url; $this->json = $json; }
	public function get_json_params() { return $this->json; }
	public function get_param( $k ) { return $this->p[ $k ] ?? ( $this->json[ $k ] ?? null ); }
	public function offsetExists( $o ): bool { return isset( $this->p[ $o ] ); }
	public function offsetGet( $o ): mixed { return $this->p[ $o ] ?? null; }
	public function offsetSet( $o, $v ): void { $this->p[ $o ] = $v; }
	public function offsetUnset( $o ): void { unset( $this->p[ $o ] ); }
}
class WP_User { public $ID; public $display_name; public $roles; public $caps; public function __construct( $id, $name, $roles, $caps ) { $this->ID = $id; $this->display_name = $name; $this->roles = $roles; $this->caps = $caps; } }
class WC_Product { public $stock; public $sku; public function __construct( $stock, $sku ) { $this->stock = $stock; $this->sku = $sku; } public function managing_stock() { return true; } public function get_sku() { return $this->sku; } public function get_image_id() { return 0; } public function get_parent_id() { return 0; } }
class WC_Order_Item_Product {
	public $name; public $qty; public $total; public $product; public array $meta = array();
	public function __construct( $name, $qty, $total, $product ) { $this->name = $name; $this->qty = $qty; $this->total = $total; $this->product = $product; $this->meta['_reduced_stock'] = $qty; }
	public function get_name() { return $this->name; } public function get_quantity() { return $this->qty; } public function get_total() { return $this->total; }
	public function get_product() { return $this->product; } public function get_meta( $k, $s = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } public function save() {}
}
class WC_Order_Refund {}
class WC_Order {
	public $id; public $status; public array $meta = array(); public array $notes = array(); public array $items = array(); public $total;
	public function __construct( $id, $status, $total ) { $this->id = $id; $this->status = $status; $this->total = $total; }
	public function get_id() { return $this->id; } public function get_type() { return 'shop_order'; } public function get_order_number() { return (string) $this->id; }
	public function get_status() { return $this->status; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function add_order_note( $t ) { $this->notes[] = $t; } public function save() {}
	public function get_items( $type = '' ) { return $this->items; } public function get_item( $id ) { return $this->items[ $id ] ?? null; }
	public function update_status( $s, $note = '' ) { $this->status = $s; if ( $note ) { $this->notes[] = $note; } }
	public function get_date_created() { return null; } public function get_formatted_billing_full_name() { return 'Clienta de la olla'; } public function get_total() { return $this->total; }
}
$GLOBALS['orders'] = array(); $GLOBALS['users'] = array(); $GLOBALS['current'] = 0;
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
function get_userdata( $id ) { return $GLOBALS['users'][ (int) $id ] ?? false; }
function user_can( $user, $cap ) { $u = $user instanceof WP_User ? $user : get_userdata( $user ); return $u && in_array( $cap, $u->caps, true ); }
function get_users( $args ) { return array_values( $GLOBALS['users'] ); }
function wp_set_current_user( $id ) { $GLOBALS['current'] = $id; }
function get_current_user_id() { return $GLOBALS['current']; }
function current_time( $t, $gmt = false ) { return '2026-10-31 18:00:00'; }
function wp_generate_uuid4() { return 'uuid-' . mt_rand(); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $k, $d = false ) { return $d; }
function wp_get_attachment_image_url( $id, $size ) { return ''; }
function wc_increase_stock_levels( WC_Order $order ) { foreach ( $order->items as $item ) { $item->product->stock += $item->qty; $item->meta['_reduced_stock'] = 0; } }
function wc_update_product_stock( $product, $qty, $op ) { $product->stock += 'increase' === $op ? $qty : -$qty; }
// Comisión real de Core (solo usa el pedido).
require_once __DIR__ . '/../includes/class-cvd-commissions.php';
require_once __DIR__ . '/../includes/class-cvd-return-plan.php';
require_once __DIR__ . '/../includes/class-cvd-sales.php';
require_once __DIR__ . '/../includes/class-cvd-caja-bridge.php';

// Personas: Zaymi (dependienta, cvd_manage_sales) y Lennys (administración).
$GLOBALS['users'][63] = new WP_User( 63, 'Zaymi', array( 'cvd_clerk' ), array( 'cvd_manage_sales' ) );
$GLOBALS['users'][14] = new WP_User( 14, 'Lennys Rondon', array( 'administrator' ), array( 'manage_woocommerce' ) );
$GLOBALS['users'][99] = new WP_User( 99, 'Cliente', array( 'customer' ), array() );

function olla_order( int $id, string $status ): WC_Order {
	$order = new WC_Order( $id, $status, 62.0 );
	$olla = new WC_Product( 3, 'CV-OLLA-ARIETE' );
	$order->items[ 701 ] = new WC_Order_Item_Product( 'Olla de presión eléctrica Ariete digital', 1, 62.0, $olla );
	$order->meta = array( '_cvd_owner_user_id' => 41, '_cvd_owner_type' => 'gestora', '_cvd_commission_status' => 'approved', 'gestora_nombre' => 'Oliuska',
		'_cvd_collection_method' => 'cash_usd', '_cvd_collection_amount_usd' => '62', '_cvd_collection_amount_cup' => '0' );
	$GLOBALS['orders'][ $id ] = $order;
	return $order;
}

// ---------------- 1) Cuentas puras ----------------
$P = 'CVD_Return_Plan';
check( 'causa corta no vale', $P::reason( ' no ' ), '' );
check( 'causa normal', $P::reason( "  no  calienta \n bien " ), 'no calienta bien' );
check( 'todo el pedido', $P::lines( array( 1 => array( 'name' => 'Olla', 'qty' => 1 ) ), array() )['full'], true );
$two = array( 1 => array( 'name' => 'Olla', 'qty' => 1 ), 2 => array( 'name' => 'Taza', 'qty' => 3 ) );
$part = $P::lines( $two, array( array( 'itemId' => 2, 'qty' => 1 ) ) );
check( 'parcial: una taza', array( $part['ok'], $part['full'], $part['lines'] ), array( true, false, array( array( 'itemId' => 2, 'name' => 'Taza', 'qty' => 1 ) ) ) );
check( 'todas las unidades = entero', $P::lines( $two, array( array( 'itemId' => 1, 'qty' => 1 ), array( 'itemId' => 2, 'qty' => 3 ) ) )['full'], true );
check( 'más unidades de las que hay', $P::lines( $two, array( array( 'itemId' => 2, 'qty' => 4 ) ) )['ok'], false );
check( 'producto que no está', $P::lines( $two, array( array( 'itemId' => 9, 'qty' => 1 ) ) )['ok'], false );
check( 'dinero negativo', $P::money( '-5', 0, 'cash' )['ok'], false );
check( 'dinero sin forma', $P::money( '62', 0, '' )['ok'], false );
check( 'sin dinero no pide forma', $P::money( 0, '', '' ), array( 'ok' => true, 'error' => '', 'usd' => 0.0, 'cup' => 0.0, 'method' => 'none' ) );
check( 'coma decimal', $P::money( '62,5', '1200', 'mixed' )['usd'], 62.5 );
check( 'formato 62', $P::fmt( 62.0 ), '62' );

// ---------------- 2) La olla: devolución desde la caja por Zaymi ----------------
$order = olla_order( 5612, 'completed' );
$olla = $order->items[701]->product;
$row = CVD_Caja_Bridge::action_row( $order, 63 );
check( 'la caja ve «Devolución»', $row['can'], 'return' );
check( 'la caja ve lo cobrado', $row['charged']['usd'], 62.0 );
check( 'la caja ve el producto', $row['products'][0]['name'], 'Olla de presión eléctrica Ariete digital' );

$body = array( 'actionId' => 'wret-olla-1', 'reason' => 'La olla no calienta, la clienta la trajo', 'lines' => array( array( 'itemId' => 701, 'qty' => 1 ) ),
	'refundUsd' => 62, 'refundCup' => 0, 'refundMethod' => 'cash', 'clerkWooId' => 63, 'clerkName' => 'Zaymi', 'device' => 'Teléfono 2' );
$res = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5612 ), $body ) );
check( 'devolución aceptada', $res instanceof WP_REST_Response && true === $res->data['ok'], true );
$order = wc_get_order( 5612 );
check( 'pedido reembolsado', $order->get_status(), 'refunded' );
check( 'el stock vuelve (3 → 4)', $olla->stock, 4 );
check( 'comisión de la gestora anulada', $order->get_meta( '_cvd_commission_status' ), 'cancelled' );
check( 'marcado para Lennys', $order->get_meta( '_cvd_refund_review' ), 'pending' );
check( 'la firma Zaymi', $order->get_meta( '_cvd_return' )['by'], 63 );
check( 'queda el dinero devuelto', array( $order->get_meta( '_cvd_return' )['refund_usd'], $order->get_meta( '_cvd_return' )['refund_cup'], $order->get_meta( '_cvd_return' )['refund_method'] ), array( 62.0, 0.0, 'cash' ) );
check( 'ganancia del mensajero se mantiene', $order->get_meta( '_cvd_return' )['messenger_earning'], 'se mantiene' );
$note = implode( "\n", $order->notes );
check( 'nota: quién y desde dónde', str_contains( $note, 'registrada por Zaymi (dependienta) desde la caja (Teléfono 2)' ), true );
check( 'nota: USD y CUP', str_contains( $note, '62 USD + 0 CUP en efectivo' ), true );
check( 'nota: causa', str_contains( $note, 'Causa: La olla no calienta, la clienta la trajo' ), true );
check( 'nota: producto', str_contains( $note, '1× Olla de presión eléctrica Ariete digital' ), true );
check( 'nota: pendiente de Lennys', str_contains( $note, 'Pendiente de que Lennys apruebe' ), true );

// Sin red, la caja reintenta con el MISMO actionId: no repone el stock dos veces.
$again = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5612 ), $body ) );
check( 'reintento: «ya estaba»', array( $again->data['ok'], $again->data['repeated'] ), array( true, true ) );
check( 'reintento: stock sigue en 4', $olla->stock, 4 );
// Otra devolución distinta del mismo pedido: no.
$other = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5612 ), array_merge( $body, array( 'actionId' => 'wret-otra' ) ) ) );
check( 'segunda devolución rechazada', $other instanceof WP_Error ? $other->code : 'aceptada', 'cvd_return_done' );

// ---------------- 3) Frenos ----------------
olla_order( 5613, 'completed' );
$no_reason = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5613 ), array_merge( $body, array( 'reason' => '' ) ) ) );
check( 'sin causa no se registra', $no_reason instanceof WP_Error ? $no_reason->code : 'aceptada', 'cvd_return_reason' );
$no_method = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5613 ), array_merge( $body, array( 'refundMethod' => 'bitcoin' ) ) ) );
check( 'forma de devolución inválida', $no_method instanceof WP_Error ? $no_method->code : 'aceptada', 'cvd_return_money' );
$unknown = CVD_Caja_Bridge::return_order( new WP_REST_Request( array( 'id' => 5613 ), array_merge( $body, array( 'clerkWooId' => 99, 'clerkPhone' => '' ) ) ) );
check( 'sin usuaria de dependienta no se firma', $unknown instanceof WP_Error ? $unknown->code : 'aceptada', 'cvd_caja_clerk' );
wp_set_current_user( 63 );
$void = CVD_Sales::do_return( 5613, array( 'reason' => 'mal entregado', 'voidMessenger' => true ), 63 );
check( 'la dependienta no anula la ganancia del mensajero', $void instanceof WP_Error ? $void->code : 'aceptada', 'cvd_return_messenger' );
check( 'la olla 5613 sigue completada', wc_get_order( 5613 )->get_status(), 'completed' );
$open = olla_order( 5614, 'processing' );
check( 'abierto: no se devuelve', CVD_Sales::return_block( $open, 63 ) !== '', true );

// Devolución parcial: la comisión no se toca (lo decide Lennys) y el pedido sigue completado.
$two_order = olla_order( 5615, 'completed' );
$taza = new WC_Product( 10, 'CV-TAZA' );
$two_order->items[ 702 ] = new WC_Order_Item_Product( 'Taza', 3, 9.0, $taza );
$partial = CVD_Sales::do_return( 5615, array( 'reason' => 'una taza rota', 'lines' => array( array( 'itemId' => 702, 'qty' => 1 ) ), 'refundUsd' => 3, 'refundMethod' => 'cash', 'returnId' => 'p1' ), 63, 'la caja' );
check( 'parcial aceptada', is_array( $partial ) && $partial['ok'], true );
check( 'parcial: vuelve 1 taza', $taza->stock, 11 );
check( 'parcial: la olla no se toca', $two_order->items[701]->product->stock, 3 );
check( 'parcial: sigue completado', wc_get_order( 5615 )->get_status(), 'completed' );
check( 'parcial: comisión sin tocar', wc_get_order( 5615 )->get_meta( '_cvd_commission_status' ), 'approved' );

echo ( $fails ? "{$fails} de {$n} pruebas FALLAN\n" : "OK: {$n} pruebas de la devolución (olla Ariete) pasan\n" );
exit( $fails ? 1 : 0 );
