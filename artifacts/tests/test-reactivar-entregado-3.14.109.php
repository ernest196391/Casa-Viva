<?php
/**
 * 3.14.109 (Ernesto 1-nov, #5581): «Ya se entregó» y «Cambiar mensajero» en /ventas/ (CVD_Order_Edit), sin avisos.
 * Partes puras: camino del reparto, día de entrega, texto del cobro, quién puede usar cada botón, el permiso del servicio
 * central (store_records_delivery) y el modo silencioso (correo/HTTP bloqueados, anidable, correos al cliente apagados).
 * Mismo estilo de stubs que test-messenger-delivered-link.php. Corre con: php artifacts/tests/test-reactivar-entregado-3.14.109.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function wc_format_decimal( $value, $dp = false, $trim = false ): string { $n = (float) $value; $s = false === $dp ? (string) $n : number_format( $n, (int) $dp, '.', '' ); return $trim && false !== strpos( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s; }
function user_can( $user, $cap ): bool { $user = is_object( $user ) ? $user : ( $GLOBALS['cvt_users'][ (int) $user ] ?? null ); return $user && ! empty( $user->caps[ $cap ] ); }
function get_userdata( $id ) { return $GLOBALS['cvt_users'][ (int) $id ] ?? false; }
function wc_get_order( $id ) { return $GLOBALS['cvt_orders'][ (int) $id ] ?? false; }
$GLOBALS['cvt_filters'] = array();
function add_filter( $hook, $cb, $prio = 10, $args = 1 ): void { $GLOBALS['cvt_filters'][ $hook ][] = $cb; }
function remove_filter( $hook, $cb, $prio = 10 ): void { $GLOBALS['cvt_filters'][ $hook ] = array_values( array_filter( $GLOBALS['cvt_filters'][ $hook ] ?? array(), static fn( $x ) => $x !== $cb ) ); }
function apply_filters( $hook, $value, ...$args ) { foreach ( $GLOBALS['cvt_filters'][ $hook ] ?? array() as $cb ) { $value = $cb( $value, ...$args ); } return $value; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ): void {}

class WP_Error { public string $code; public string $message; public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; } }
class WP_User { public int $ID; public array $roles; public array $caps; public string $display_name;
	public function __construct( int $id, array $roles, array $caps, string $name = '' ) { $this->ID = $id; $this->roles = $roles; $this->caps = $caps; $this->display_name = $name ?: 'Usuario ' . $id; } }
class WC_Order {
	public array $meta = array(); public string $status = 'on-hold'; private int $id;
	public function __construct( int $id, array $meta = array(), string $status = 'on-hold' ) { $this->id = $id; $this->meta = $meta; $this->status = $status; }
	public function get_id(): int { return $this->id; }
	public function get_type(): string { return 'shop_order'; }
	public function get_status(): string { return $this->status; }
	public function has_status( $s ): bool { return is_array( $s ) ? in_array( $this->status, $s, true ) : $this->status === $s; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function get_total_refunded() { return 0; }
	public function get_items( $type = '' ): array { return array(); }
	public function get_date_modified() { return null; }
}

require dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-order-edit.php';
require dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-order-transition-service.php';

$fails = 0; $count = 0;
function check( string $name, bool $ok ): void { global $fails, $count; $count++; if ( ! $ok ) { $fails++; echo "FALLA: $name\n"; } }

// 1. Camino del reparto hasta «Entregada» (solo pasos del servicio central).
check( 'sin asignar → 5 pasos', CVD_Order_Edit::delivered_path( 'unassigned' ) === array( 'assigned', 'accepted', 'picked_up', 'handed_over', 'delivered' ) );
check( 'ofertado entra por «asignada»', CVD_Order_Edit::delivered_path( 'offered' ) === array( 'assigned', 'accepted', 'picked_up', 'handed_over', 'delivered' ) );
check( 'va a recoger salta a «recogido»', CVD_Order_Edit::delivered_path( 'to_store' ) === array( 'picked_up', 'handed_over', 'delivered' ) );
check( 'en la calle → 2 pasos', CVD_Order_Edit::delivered_path( 'picked_up' ) === array( 'handed_over', 'delivered' ) );
check( 'entregada → nada', CVD_Order_Edit::delivered_path( 'delivered' ) === array() );
check( 'cerrada → nada', CVD_Order_Edit::delivered_path( 'closed' ) === array() );
check( 'cancelada → nada', CVD_Order_Edit::delivered_path( 'cancelled' ) === array() );
$governed = true; $from = 'unassigned';
foreach ( CVD_Order_Edit::delivered_path( 'unassigned' ) as $to ) { $governed = $governed && CVD_Order_Transition_Service::governs( 'delivery', $from, $to ); $from = $to; }
check( 'cada paso lo gobierna el servicio central', $governed );
check( 'offered→assigned y to_store→picked_up gobernados', CVD_Order_Transition_Service::governs( 'delivery', 'offered', 'assigned' ) && CVD_Order_Transition_Service::governs( 'delivery', 'to_store', 'picked_up' ) );

// 2. Día de la entrega (Cuba). Ahora: 1-nov-2026 19:00 UTC = 15:00 en La Habana.
$now = gmmktime( 19, 0, 0, 11, 1, 2026 );
check( 'vacío = ahora', CVD_Order_Edit::delivered_at( '', $now ) === '2026-11-01 19:00:00' );
check( 'hoy = ahora', CVD_Order_Edit::delivered_at( '2026-11-01', $now ) === '2026-11-01 19:00:00' );
check( 'ayer = mediodía de Cuba en UTC', CVD_Order_Edit::delivered_at( '2026-10-31', $now ) === '2026-10-31 16:00:00' );
check( 'futuro no vale', '' === CVD_Order_Edit::delivered_at( '2026-11-02', $now ) );
check( 'más de 14 días no vale', '' === CVD_Order_Edit::delivered_at( '2026-10-17', $now ) );
check( '14 días sí vale', '' !== CVD_Order_Edit::delivered_at( '2026-10-18', $now ) );
check( 'mal escrito no vale', '' === CVD_Order_Edit::delivered_at( '31/10/2026', $now ) );
$late = gmmktime( 3, 30, 0, 11, 2, 2026 ); // 2-nov 03:30 UTC = 1-nov 22:30 en Cuba: «hoy» sigue siendo 1-nov
check( 'de noche en Cuba «hoy» es el día de Cuba', CVD_Order_Edit::delivered_at( '2026-11-01', $late ) === '2026-11-02 03:30:00' && '' === CVD_Order_Edit::delivered_at( '2026-11-02', $late ) );

// 3. Texto del cobro para la nota.
check( 'efectivo USD', CVD_Order_Edit::collection_text( 'cash_usd', 33.0, 0.0 ) === '33 USD en efectivo' );
check( 'mixto', CVD_Order_Edit::collection_text( 'mixed', 10.0, 500.0 ) === '10 USD + 500 CUP (mixto)' );
check( 'prepagado sin dinero', CVD_Order_Edit::collection_text( 'transfer', 0.0, 0.0 ) === 'sin dinero de productos por transferencia' );

// 4. Quién ve cada botón.
$GLOBALS['cvt_users'] = array(
	64 => new WP_User( 64, array( 'cvd_clerk' ), array( 'cvd_manage_sales' => true ), 'Zaymi' ),
	1  => new WP_User( 1, array( 'administrator' ), array( 'manage_woocommerce' => true ), 'Ernesto' ),
	52 => new WP_User( 52, array( 'cvd_messenger' ), array(), 'Melany Bestard' ),
	77 => new WP_User( 77, array( 'customer' ), array(), 'Cliente' ),
);
$o5581 = new WC_Order( 5581, array( '_cvd_operation_status' => 'with_courier', '_cvd_delivery_status' => 'unassigned', '_cvd_fulfillment_type' => 'delivery', '_cvd_preferred_messenger_user_id' => '50', '_cvd_reactivated_at' => '2026-11-01 18:57:52', '_cvd_commission_status' => 'pending' ) );
check( '#5581: la dependienta ve «Ya se entregó»', '' === CVD_Order_Edit::delivered_block( $o5581, 64 ) );
check( '#5581: administración también', '' === CVD_Order_Edit::delivered_block( $o5581, 1 ) );
check( '#5581: un cliente no', '' !== CVD_Order_Edit::delivered_block( $o5581, 77 ) );
check( '#5581: sin mensajero puesto no hay «Cambiar mensajero»', '' !== CVD_Order_Edit::messenger_block( $o5581, 64 ) );
$normal = new WC_Order( 9001, array( '_cvd_delivery_status' => 'unassigned', '_cvd_fulfillment_type' => 'delivery' ) );
check( 'pedido normal sin reactivar: no sale «Ya se entregó»', '' !== CVD_Order_Edit::delivered_block( $normal, 64 ) );
$pickup = new WC_Order( 9002, array( '_cvd_fulfillment_type' => 'pickup', '_cvd_reactivated_at' => 'x' ) );
check( 'recogida: no', '' !== CVD_Order_Edit::delivered_block( $pickup, 64 ) && '' !== CVD_Order_Edit::messenger_block( $pickup, 64 ) );
$cancelled = new WC_Order( 9003, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_delivery_status' => 'cancelled', '_cvd_cancelled_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), 'cancelled' );
check( 'cancelado hace 1 hora: sí (se reactiva y queda entregado)', '' === CVD_Order_Edit::delivered_block( $cancelled, 64 ) );
$old = new WC_Order( 9004, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_cancelled_at' => gmdate( 'Y-m-d H:i:s', time() - 9 * 86400 ) ), 'cancelled' );
check( 'cancelado hace 9 días: no', '' !== CVD_Order_Edit::delivered_block( $old, 64 ) );
$done = new WC_Order( 9005, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_delivery_status' => 'delivered', '_cvd_reactivated_at' => 'x', '_cvd_messenger_user_id' => '50' ) );
check( 'ya entregado: «Ya se entregó» no, «Cambiar mensajero» sí', '' !== CVD_Order_Edit::delivered_block( $done, 64 ) && '' === CVD_Order_Edit::messenger_block( $done, 64 ) );
$incident = new WC_Order( 9006, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_delivery_status' => 'picked_up', '_cvd_reactivated_at' => 'x', '_cvd_delivery_incident_active' => 'yes' ) );
check( 'con incidencia abierta: no', '' !== CVD_Order_Edit::delivered_block( $incident, 64 ) );
$closed = new WC_Order( 9007, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_delivery_status' => 'closed', '_cvd_messenger_user_id' => '50' ), 'completed' );
check( 'cerrado: «Cambiar mensajero» no', '' !== CVD_Order_Edit::messenger_block( $closed, 1 ) );
$cash = new WC_Order( 9008, array( '_cvd_fulfillment_type' => 'delivery', '_cvd_delivery_status' => 'cash_returned', '_cvd_messenger_user_id' => '50' ) );
check( 'dinero recibido sin cerrar: «Cambiar mensajero» sí', '' === CVD_Order_Edit::messenger_block( $cash, 64 ) );
check( 'un mensajero no cambia mensajeros', '' !== CVD_Order_Edit::messenger_block( $cash, 52 ) );

// 5. Permiso del servicio central: solo con store_records_delivery y solo tienda/administración.
$auth = new ReflectionMethod( 'CVD_Order_Transition_Service', 'authorized' );
$auth->setAccessible( true );
$flag = array( 'store_records_delivery' => true );
check( 'dependienta: aceptar con la marca', true === $auth->invoke( null, $o5581, 'delivery', 'assigned', 'accepted', $GLOBALS['cvt_users'][64], $flag ) );
check( 'dependienta: entregar con la marca', true === $auth->invoke( null, $o5581, 'delivery', 'handed_over', 'delivered', $GLOBALS['cvt_users'][64], $flag ) );
check( 'dependienta: aceptar SIN la marca no', false === $auth->invoke( null, $o5581, 'delivery', 'assigned', 'accepted', $GLOBALS['cvt_users'][64], array() ) );
check( 'dependienta: cerrar con la marca sigue sin poder', false === $auth->invoke( null, $o5581, 'delivery', 'cash_returned', 'closed', $GLOBALS['cvt_users'][64], $flag ) );
check( 'cliente con la marca no', false === $auth->invoke( null, $o5581, 'delivery', 'assigned', 'accepted', $GLOBALS['cvt_users'][77], $flag ) );

// 6. Silencio: correo y HTTP bloqueados dentro, libres fuera; anidable; correos al cliente apagados en pedidos «después».
check( 'fuera: sin silencio', ! CVD_Order_Edit::is_silent() && null === apply_filters( 'pre_wp_mail', null ) );
$inside = CVD_Order_Edit::silently( static function () {
	$mail = apply_filters( 'pre_wp_mail', null, array() );
	$http = apply_filters( 'pre_http_request', false, array(), 'https://ntfy.example' );
	$nested = CVD_Order_Edit::silently( static fn() => CVD_Order_Edit::is_silent() );
	return array( $mail, $http, $nested, CVD_Order_Edit::is_silent(), CVD_Order_Edit::quiet_for( 0 ) );
} );
check( 'dentro: wp_mail corta (false)', false === $inside[0] );
check( 'dentro: HTTP corta (WP_Error)', $inside[1] instanceof WP_Error );
check( 'anidado sigue en silencio', true === $inside[2] && true === $inside[3] && true === $inside[4] );
check( 'después: filtros quitados y sin silencio', ! CVD_Order_Edit::is_silent() && null === apply_filters( 'pre_wp_mail', null ) && false === apply_filters( 'pre_http_request', false ) );
try { CVD_Order_Edit::silently( static function () { throw new RuntimeException( 'x' ); } ); } catch ( RuntimeException $e ) {}
check( 'con error también se quita el silencio', ! CVD_Order_Edit::is_silent() && null === apply_filters( 'pre_wp_mail', null ) );
$after = new WC_Order( 9010, array( CVD_Order_Edit::AFTER_FACT_META => 'yes' ) );
$GLOBALS['cvt_orders'] = array( 9010 => $after, 5581 => $o5581 );
check( 'pedido «después»: correo al cliente apagado', false === CVD_Order_Edit::quiet_email( true, $after ) );
check( 'pedido normal: correo como estaba', true === CVD_Order_Edit::quiet_email( true, $o5581 ) );
check( 'quiet_for: «después» sí, normal no', CVD_Order_Edit::quiet_for( 9010 ) && ! CVD_Order_Edit::quiet_for( 5581 ) );

echo $fails ? "$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
