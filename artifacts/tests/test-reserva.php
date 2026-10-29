<?php
/**
 * 3.14.72 (Pilar 3, caso #5551): reserva de una recogida. Prueba con WordPress y WooCommerce simulados (sin base de datos ni red):
 * poner/cambiar/quitar la reserva, solo en recogidas abiertas y de hoy a 7 días, el texto que ve la tienda,
 * que la dependienta no cancele con reserva vigente, que administración tenga que confirmar, que la cancelación del bot
 * por la API de WooCommerce se rechace, y que una reserva vencida ya no frene nada.
 * Ejecutar: php artifacts/tests/test-reserva.php   (sale con 1 si algo falla)
 */

define( 'ABSPATH', __DIR__ . '/' );
date_default_timezone_set( 'America/Havana' );

function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_timezone() { return new DateTimeZone( 'America/Havana' ); }
function wp_date( $f ) { return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( $f ); }
function current_time( $t, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function add_action() {}
function add_filter() {}
function user_can( $id, $cap ) { return 'manage_woocommerce' === $cap && 1 === (int) $id; } // 1 = Lennys (administración); 2 = Nana (dependienta)
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_User { public $ID; public $display_name; public function __construct( $id, $name ) { $this->ID = $id; $this->display_name = $name; } }
class WC_Order_Refund {}
class WC_Order {
	public $id; public $status = 'processing'; public $meta = array(); public $notes = array();
	public function __construct( $id, $pickup = true ) { $this->id = $id; $this->meta['_cvd_fulfillment_type'] = $pickup ? 'pickup' : 'delivery'; $this->meta['gestora_nombre'] = 'Kay'; $GLOBALS['orders'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_order_number() { return (string) $this->id; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function get_status() { return $this->status; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function save() { return $this->id; }
}
class WP_REST_Response {
	public $data; public $headers = array();
	public function __construct( $data = null ) { $this->data = $data; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
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
class CVD_Delivery_When {
	public static function min_date(): string { return wp_date( 'Y-m-d' ); }
	public static function normalize( string $raw ): string {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		if ( 'hoy' === $raw ) { return $today->format( 'Y-m-d' ); }
		if ( 'mañana' === $raw ) { return $today->modify( '+1 day' )->format( 'Y-m-d' ); }
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}
	public static function parts( string $date, string $window ): array {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$diff = (int) round( ( $d->getTimestamp() - $today->getTimestamp() ) / 86400 );
		$short = $d->format( 'j/n' );
		return array( 'day' => 0 === $diff ? 'hoy' : ( 1 === $diff ? 'mañana ' . $short : ( $diff < 0 ? $short . ' (ya pasó)' : $short ) ) );
	}
}
class CVD_Bot_Bridge {
	public static function gestora_by_phone( string $phone ): ?WP_User { return str_ends_with( $phone, '3180' ) ? new WP_User( 77, 'Kay' ) : ( str_ends_with( $phone, '0000' ) ? new WP_User( 78, 'Otra' ) : null ); }
}

require dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-reserva.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; echo ( $ok ? 'OK   ' : 'FALLA ' ) . $msg . "\n"; if ( ! $ok ) { $fail++; } }
$tomorrow = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
$yesterday = ( new DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' );
$in8 = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+8 days' )->format( 'Y-m-d' );

// 1) Poner la reserva (caso #5551: Kay pide guardarlo hasta mañana).
$o = new WC_Order( 5551 );
$r = CVD_Reserva::set( 5551, array( 'until' => 'mañana', 'by' => 'Kay', 'note' => 'viene por la tarde' ), 'bot', 'VivaBot (Kay)' );
check( ! is_wp_error( $r ) && true === $r['ok'], 'reserva puesta' );
check( $o->meta[ CVD_Reserva::UNTIL ] === $tomorrow, 'guarda la fecha de mañana (' . ( $o->meta[ CVD_Reserva::UNTIL ] ?? '' ) . ')' );
$g = CVD_Reserva::get( $o );
check( $g && $g['active'] && ! $g['expired'], 'vigente' );
check( $g && 0 === strpos( $g['text'], 'Kay pidió guardarlo hasta mañana ' ), 'texto para la tienda: «' . ( $g['text'] ?? '' ) . '»' );
check( count( $o->notes ) === 1 && false !== strpos( $o->notes[0], 'PUESTA' ), 'deja una nota en el pedido' );

// 2) Cancelar: Nana (2) bloqueada, Lennys (1) debe confirmar, confirmada pasa.
check( '' !== CVD_Reserva::clerk_block( $o, 2 ), 'la dependienta no puede cancelar (botón oculto)' );
check( '' === CVD_Reserva::clerk_block( $o, 1 ), 'administración sí ve el botón' );
$gate = CVD_Reserva::cancel_gate( $o, 2, true );
check( is_wp_error( $gate ) && 'cvd_cancel_reserva' === $gate->code, 'dependienta bloqueada aunque diga que confirma' );
$gate = CVD_Reserva::cancel_gate( $o, 1, false );
check( is_wp_error( $gate ) && 'cvd_cancel_reserva_confirmar' === $gate->code && ! empty( $gate->data['needsConfirm'] ), 'administración: pide confirmación' );
check( true === CVD_Reserva::cancel_gate( $o, 1, true ), 'administración confirmada: pasa' );

// 3) El bot cancelando por la API de WooCommerce.
$guard = CVD_Reserva::guard_rest_cancel( $o, new WP_REST_Request( array( 'status' => 'cancelled' ) ), false );
check( is_wp_error( $guard ) && 'cvd_reserva_vigente' === $guard->code && 409 === $guard->data['status'], 'PUT status=cancelled con reserva → 409 cvd_reserva_vigente' );
check( $o === CVD_Reserva::guard_rest_cancel( $o, new WP_REST_Request( array( 'status' => 'completed' ) ), false ), 'otros cambios de estado pasan' );
check( $o === CVD_Reserva::guard_rest_cancel( $o, new WP_REST_Request( array( 'status' => 'cancelled' ) ), true ), 'crear pedidos no se toca' );

// 4) Reglas de la reserva.
$d = new WC_Order( 100, false );
$r = CVD_Reserva::set( 100, array( 'until' => 'mañana' ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 'cvd_reserva_no_recogida' === $r->code, 'a domicilio no se reserva' );
$r = CVD_Reserva::set( 5551, array( 'until' => $in8 ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 'cvd_reserva_rango' === $r->code, 'más de 7 días no' );
$r = CVD_Reserva::set( 5551, array( 'until' => $yesterday ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 'cvd_reserva_rango' === $r->code, 'una fecha pasada no' );
$r = CVD_Reserva::set( 5551, array( 'until' => 'quizás' ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 'cvd_reserva_fecha' === $r->code, 'fecha que no se entiende' );
check( $o->meta[ CVD_Reserva::UNTIL ] === $tomorrow, 'los intentos malos no cambiaron nada' );
$c = new WC_Order( 101 ); $c->status = 'cancelled';
$r = CVD_Reserva::set( 101, array( 'until' => 'mañana' ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 'cvd_reserva_cerrado' === $r->code, 'pedido cancelado no se reserva' );
$r = CVD_Reserva::set( 999, array( 'until' => 'mañana' ), 'ventas', 'Nana' );
check( is_wp_error( $r ) && 404 === $r->data['status'], 'pedido que no existe: 404' );

// 5) Cambiar la fecha vuelve a poder avisar; la nota recuerda la fecha anterior.
$o->meta[ CVD_Reserva::NOTIFIED ] = '2026-10-28 20:00:00';
$r = CVD_Reserva::set( 5551, array( 'until' => 'hoy', 'by' => 'Kay' ), 'ventas', 'Nana' );
check( ! is_wp_error( $r ) && ! isset( $o->meta[ CVD_Reserva::NOTIFIED ] ), 'cambiar la fecha borra el «ya avisé»' );
check( false !== strpos( end( $o->notes ), 'CAMBIADA' ), 'la nota dice CAMBIADA' );

// 6) Reserva vencida: ya no frena ni cancela, y sale como vencida.
$o->meta[ CVD_Reserva::UNTIL ] = $yesterday;
$g = CVD_Reserva::get( $o );
check( $g && ! $g['active'] && $g['expired'], 'reserva de ayer: vencida' );
check( '' === CVD_Reserva::clerk_block( $o, 2 ) && true === CVD_Reserva::cancel_gate( $o, 2, false ), 'vencida: la dependienta ya puede cancelar' );
check( $o === CVD_Reserva::guard_rest_cancel( $o, new WP_REST_Request( array( 'status' => 'cancelled' ) ), false ), 'vencida: el bot ya puede cancelar' );
$o->status = 'completed';
check( false === CVD_Reserva::get( $o )['active'] && false === CVD_Reserva::get( $o )['expired'], 'pedido ya recogido: ni vigente ni vencida' );
$o->status = 'processing';

// 7) Bot: solo la gestora dueña reserva.
$o->meta['_cvd_owner_user_id'] = 77;
$o->meta[ CVD_Reserva::UNTIL ] = $tomorrow;
$bad = CVD_Reserva::bot_set( new WP_REST_Request( array( 'until' => 'mañana', 'byPhone' => '5350000000', 'byRole' => 'gestora' ), array( 'id' => 5551 ) ) );
check( is_wp_error( $bad ), 'bot: un teléfono que no es gestora no reserva' );

$good = CVD_Reserva::bot_set( new WP_REST_Request( array( 'until' => 'mañana', 'byPhone' => '5353453180', 'byRole' => 'gestora' ), array( 'id' => 5551 ) ) );
check( $good instanceof WP_REST_Response && true === $good->data['ok'] && 'Kay' === $o->meta[ CVD_Reserva::BY ], 'bot: la gestora dueña (…3180) reserva y queda «Kay»' );

// 8) Quitar la reserva.
$r = CVD_Reserva::set( 5551, array( 'clear' => true ), 'ventas', 'Lennys' );
check( ! is_wp_error( $r ) && null === $r['reserva'] && null === CVD_Reserva::get( $o ), 'reserva quitada' );
check( true === CVD_Reserva::cancel_gate( $o, 2, false ), 'sin reserva: se cancela como siempre' );

echo $fail ? "\n$fail prueba(s) fallaron\n" : "\nTodo bien\n";
exit( $fail ? 1 : 0 );
