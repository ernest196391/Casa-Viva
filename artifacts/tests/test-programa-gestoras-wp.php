<?php
/**
 * Prueba de integración del Programa de gestoras con WordPress/WooCommerce simulados:
 * lectura de pedidos (desglose de comisión, autocompra, prueba, fecha de cobro), puntos de producto,
 * y las pantallas «Mi equipo» y «Mi piedra» con datos de ejemplo. Sin base de datos real.
 * Ejecutar: php artifacts/tests/test-programa-gestoras-wp.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
date_default_timezone_set( 'UTC' );

$GLOBALS['opts'] = array( 'cvd_programa_gestoras' => 'sombra', 'cvd_programa_ver' => '2', 'cvd_default_commission_rate' => 10, 'cvd_programa_schema' => '2' );
$GLOBALS['meta'] = array(); $GLOBALS['umeta'] = array(); $GLOBALS['orders'] = array(); $GLOBALS['products'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function get_post_meta( $id, $k, $s = true ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_user_meta( $id, $k, $s = true ) { return $GLOBALS['umeta'][ $id ][ $k ] ?? ''; }
function wp_get_post_parent_id( $id ) { return $GLOBALS['products'][ $id ]->parent ?? 0; }
function apply_filters( $h, $v ) { return $v; }
function add_action() {} function add_filter() {}
function wp_timezone_string() { return 'America/Havana'; }
function wp_timezone() { return new DateTimeZone( 'America/Havana' ); }
function wp_date( $f, $ts = null ) { $d = new DateTime( '@' . ( $ts ?? time() ) ); $d->setTimezone( new DateTimeZone( 'America/Havana' ) ); return $d->format( $f ); }
function current_time( $t, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_the_title( $id ) { return $GLOBALS['products'][ $id ]->name ?? ( 'Producto ' . $id ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_query_arg( $k, $v, $u ) { return $u . '?' . $k . '=' . $v; }
function home_url( $p = '' ) { return 'https://casaviva.test' . $p; }
function wc_get_orders( $args ) { return array_values( $GLOBALS['orders'] ); }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
function get_users( $args ) { return array_values( $GLOBALS['users'] ); }

class WP_User {
	public $ID; public $display_name; public $user_registered; public $roles = array( 'cvd_gestora' );
	public function __construct( $id, $name, $reg ) { $this->ID = $id; $this->display_name = $name; $this->user_registered = $reg; }
	public function exists() { return $this->ID > 0; }
}
final class CVD_Registration {
	public static function is_approved_gestora( $u ) { return 'approved' === ( $GLOBALS['umeta'][ $u->ID ]['_cvd_account_status'] ?? '' ); }
	public static function invite_url( $u ) { return 'https://casaviva.test/registro-gestora/?invita=CV' . $u->ID; }
}
final class CVD_Delivery {
	public static function is_test_order( $o ) { return '' !== (string) $o->get_meta( '_cvd_test_order' ); }
	public static function blocks_dispatch( $o ) { return self::is_test_order( $o ) ? 'test' : ''; }
}
class WC_DateTime_Stub { private $t; public function __construct( $t ) { $this->t = $t; } public function getTimestamp() { return $this->t; } }
class WC_Order {
	public $id; public $status; public $m;
	public function __construct( $id, $status, $m ) { $this->id = $id; $this->status = $status; $this->m = $m; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_meta( $k, $s = true ) { return $this->m[ $k ] ?? ''; }
	public function get_date_completed() { return null; }
	public function get_billing_first_name() { return $this->m['_bf'] ?? 'Cliente'; }
	public function get_billing_last_name() { return $this->m['_bl'] ?? 'Real'; }
	public function get_shipping_first_name() { return ''; }
	public function get_shipping_last_name() { return ''; }
	public function get_shipping_address_1() { return $this->m['_sa1'] ?? ''; }
	public function get_shipping_city() { return $this->m['_sc'] ?? ''; }
	public function get_billing_address_1() { return $this->m['_ba1'] ?? ''; }
	public function get_billing_city() { return $this->m['_bc'] ?? ''; }
	public function get_items() { return $this->m['_items'] ?? array(); }
	public function get_qty_refunded_for_item( $id ) { return $this->m['_refunded'][ $id ] ?? 0; }
	public function get_order_number() { return (string) $this->id; } // 3.14.48: lo usa la lista de vales (3.14.33); sin esto la prueba se caía
	public function get_date_created() { return null; }
}
class WC_Item_Stub { public $p; public $v; public function __construct( $p, $v = 0 ) { $this->p = $p; $this->v = $v; } public function get_product_id() { return $this->p; } public function get_variation_id() { return $this->v; } public function get_quantity() { return 1; } public function get_name() { return 'Producto ' . $this->p; } }
class WC_Product {
	public $id; public $name; public $parent = 0; public $children = array(); public $m = array();
	public function __construct( $id, $name, $parent = 0 ) { $this->id = $id; $this->name = $name; $this->parent = $parent; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function get_meta( $k, $s = true ) { return $this->m[ $k ] ?? ''; }
	public function is_type( $t ) { return ( 'variable' === $t && $this->children ) || ( 'variation' === $t && $this->parent ); }
	public function get_children() { return $this->children; }
}
class FakeWpdb {
	public $prefix = 'wp_'; public $latest = null;
	public function prepare( $q, ...$a ) { return $q; }
	public function get_row( $q, $o = null ) { return $this->latest; }
	public function get_col( $q ) { return array(); }
	public function get_var( $q ) { return 0; }
	public $results = null; public $query_result = 1; public $queries = array();
	public function get_results( $q, $o = null ) { return $this->results ?? array(); }
	public function query( $q ) { $this->queries[] = $q; return $this->query_result; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-programa-calc.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-programa.php';

$fails = 0; $count = 0;
function ok( bool $c, string $w ): void { global $fails, $count; $count++; if ( ! $c ) { $fails++; echo "FALLA: $w\n"; } }

// ---------- Datos de ejemplo (inventados) ----------
// 1 Marta (madrina) → 2 Ana → 3 Rosa. 4 Prueba (cuenta de prueba).
$GLOBALS['users'] = array(
	1 => new WP_User( 1, 'Marta Ejemplo', '2026-09-20 15:00:00' ),
	2 => new WP_User( 2, 'Ana Ejemplo', '2026-10-01 15:00:00' ),
	3 => new WP_User( 3, 'Rosa Ejemplo', '2026-10-05 15:00:00' ),
	4 => new WP_User( 4, 'Gestora Prueba', '2026-10-05 15:00:00' ),
	5 => new WP_User( 5, 'Lola Ejemplo', '2026-10-05 15:00:00' ),
);
foreach ( array( 1, 2, 3, 4, 5 ) as $u ) { $GLOBALS['umeta'][ $u ]['_cvd_account_status'] = 'approved'; }
$GLOBALS['umeta'][2]['_cvd_invited_by'] = 1; $GLOBALS['umeta'][3]['_cvd_invited_by'] = 2; $GLOBALS['umeta'][4]['_cvd_test_account'] = '1';

// Productos: 100 alfombra (costo 20), 200 lámpara variable con variación 201 (costo 32 en la variación), 300 sin costo.
$GLOBALS['products'][100] = new WC_Product( 100, 'Alfombra' ); $GLOBALS['meta'][100] = array( '_cvd_cost_usd' => '20', '_price' => '30' );
$GLOBALS['products'][200] = new WC_Product( 200, 'Lámpara' ); $GLOBALS['products'][200]->children = array( 201 );
$GLOBALS['products'][201] = new WC_Product( 201, 'Lámpara blanca', 200 ); $GLOBALS['meta'][201] = array( '_cvd_cost_usd' => '32', '_price' => '45' );
$GLOBALS['products'][300] = new WC_Product( 300, 'Cortina' ); $GLOBALS['meta'][300] = array( '_price' => '25' );

function order( $id, $owner, $lines, $extra = array(), $status = 'completed' ) {
	$bd = array();
	foreach ( $lines as $l ) { $bd[] = array( 'product_id' => $l[0], 'variation_id' => $l[1], 'quantity' => $l[2], 'base_amount' => $l[3], 'base_commission' => $l[4], 'markup' => 0 ); }
	$m = $extra + array( '_cvd_owner_user_id' => $owner, '_cvd_owner_type' => 'gestora', '_cvd_commission_status' => 'approved',
		'_cvd_commission_history' => array( array( 'from' => 'pending', 'to' => 'approved', 'at' => '2026-11-10 18:00:00' ) ), '_cvd_commission_breakdown' => $bd );
	$GLOBALS['orders'][ $id ] = new WC_Order( $id, $status, $m );
}
order( 3001, 3, array( array( 100, 0, 10, 300, 30 ) ) );                     // Rosa: 10 alfombras → 300 − 200 − 30 = 70 pts
order( 3002, 3, array( array( 200, 201, 2, 90, 9 ) ) );                     // Rosa: 2 lámparas → 90 − 64 − 9 = 17 pts
order( 3003, 2, array( array( 100, 0, 5, 150, 15 ) ) );                     // Ana: 150 − 100 − 15 = 35 pts
order( 3004, 2, array( array( 300, 0, 1, 25, 2.5 ) ) );                     // Ana: sin costo → 0 pts (cuenta como venta)
order( 3005, 1, array( array( 100, 0, 2, 60, 6 ) ) );                       // Marta: 60 − 40 − 6 = 14 pts
order( 3006, 3, array( array( 100, 0, 3, 90, 9 ) ), array( '_cvd_commission_risk' => 'self_order', '_bf' => 'Rosa', '_bl' => 'Ejemplo Pérez' ) ); // autocompra: se llama como ella
order( 3011, 5, array( array( 100, 0, 1, 30, 3 ) ), array( '_cvd_commission_risk' => 'self_order', '_bf' => 'Yunier', '_bl' => 'Cliente' ) ); // su teléfono, otro nombre: cuenta y se revisa
order( 3007, 4, array( array( 100, 0, 3, 90, 9 ) ) );                       // cuenta de prueba
order( 3008, 3, array( array( 100, 0, 3, 90, 9 ) ), array( '_cvd_commission_status' => 'pending', '_cvd_commission_history' => array() ), 'processing' ); // sin cobrar
order( 3009, 3, array( array( 100, 0, 3, 90, 9 ) ), array(), 'cancelled' );  // anulado
order( 3010, 3, array( array( 100, 0, 3, 90, 9 ) ), array( '_cvd_commission_history' => array( array( 'to' => 'approved', 'at' => '2026-11-01 03:00:00' ) ) ) ); // 31-oct 23:00 en Cuba → octubre

$r = CVD_Programa::compute( '2026-11' );
$P = $r['people'];
ok( abs( $P[3]['own_points'] - 87 ) < 0.01, 'Rosa: 70 + 17 = 87 puntos (sin autocompra, pendiente, anulado ni el de octubre): ' . $P[3]['own_points'] );
ok( abs( $P[3]['own_sales_usd'] - 390 ) < 0.01, 'Rosa: 390 USD vendidos y cobrados en noviembre' );
ok( abs( $P[2]['own_points'] - 35 ) < 0.01, 'Ana: 35 puntos (la cortina sin costo da 0)' );
ok( abs( $P[2]['red1'] - 3.9 ) < 0.01, 'Ana: 1 % de 390 = 3,90 (D77)' );
ok( 0.0 === (float) $P[4]['own_points'] && ! $P[4]['approved'], 'cuenta de prueba fuera del programa' );
ok( 'bronce' === $P[1]['stone'], 'Marta: Bronce con 14 + 35 + 39 (tope del 60 % de Rosa) = 88 ≥ 65' );
ok( abs( $P[1]['red1'] - 1.75 ) < 0.01, 'Marta: 1 % de los 175 de Ana = 1,75' );
ok( 0.0 === (float) $P[1]['red2'] && abs( $P[1]['red_lost'] - 1.95 ) < 0.01, 'Marta no es Plata: pierde el 0,5 % de Rosa (1,95)' );
$types = array_column( $r['alerts'], 'type' );
ok( in_array( 'autocompra', $types, true ) && in_array( 'sin_costo', $types, true ), 'alertas de autocompra y de producto sin costo' );
ok( in_array( 'revisar_resumen', $types, true ) && abs( $P[5]['own_points'] - 7 ) < 0.01, 'Lola: pedido con su teléfono pero otro cliente → cuenta (7 puntos) y va al resumen «revisar» (D79)' );
ok( ! in_array( 'cuenta_compartida', $types, true ), 'sin datos compartidos: ninguna «cuenta_compartida»' );
ok( $r['totals']['pay_usd'] <= $r['totals']['pool_usd'] + 0.01, 'lo que se paga cabe en el fondo' );

// Puntos de producto en la tienda (comisión 10 %).
$pp = CVD_Programa::product_points( $GLOBALS['products'][100], 2 );
ok( 7 === $pp['points'], 'Alfombra: 30 − 20 − 3 = ⭐ 7 puntos' );
$pp = CVD_Programa::product_points( $GLOBALS['products'][200], 2 );
ok( 8 === $pp['points'], 'Lámpara (variación): 45 − 32 − 4,5 = ⭐ 8 puntos' );
ok( null === CVD_Programa::product_points( $GLOBALS['products'][300], 2 ), 'Cortina sin costo: no se enseña' );

// Quién lo ve en sombra.
ok( CVD_Programa::visible_for( $GLOBALS['users'][2] ), 'Ana (en la lista de prueba) lo ve' );
ok( ! CVD_Programa::visible_for( $GLOBALS['users'][3] ), 'Rosa (fuera de la lista) no lo ve en sombra' );
ok( CVD_Programa::visible_for( $GLOBALS['users'][4] ), 'la cuenta de prueba lo ve' );
$GLOBALS['opts']['cvd_programa_gestoras'] = 'no';
ok( ! CVD_Programa::visible_for( $GLOBALS['users'][2] ), 'con el interruptor en «no» nadie lo ve' );
$GLOBALS['opts']['cvd_programa_gestoras'] = 'sombra';

// Pantallas con el cálculo guardado.
$GLOBALS['wpdb']->latest = array( 'id' => 7, 'period' => '2026-11', 'kind' => 'diario', 'status' => 'vista', 'computed_at' => gmdate( 'Y-m-d H:i:s' ), 'rate_cup' => 785, 'payload_sha256' => str_repeat( 'a', 64 ), 'payload' => json_encode( $r ) );
$eq = CVD_Programa::render_equipo( $GLOBALS['users'][2] );
ok( false !== strpos( $eq, 'En prueba:' ), 'Mi equipo: aviso de modo sombra' );
ok( false !== strpos( $eq, 'Rosa Ejemplo' ) && false !== strpos( $eq, '3,90 USD' ) && false !== strpos( $eq, 'Te dan el 1 %' ), 'Mi equipo: Rosa, lo que le deja (3,90 USD) y «Te dan el 1 %»' );
ok( false !== strpos( $eq, 'invita=CV2' ), 'Mi equipo: su enlace para invitar' );
$pi = CVD_Programa::render_piedra( $GLOBALS['users'][1] );
ok( false !== strpos( $pi, 'Bronce' ) && false !== strpos( $pi, '5.000 CUP' ), 'Mi piedra: Bronce y su bono' );
ok( false !== strpos( $pi, 'Para llegar a Plata' ), 'Mi piedra: cuánto falta para Plata' );
file_put_contents( sys_get_temp_dir() . '/programa-ejemplo.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="gestora-app.css"><body class="cvga-body"><div class="cvga"><main class="cvga-main"><div class="cvga-wrap">' . $eq . $pi . '</div></main></div>' );

// ---------- 3.14.29: encima de 3.14.25 (gastos fijos D74, extra en tienda 15-oct, costo provisional D62) ----------
if ( ! class_exists( 'CVD_Costs' ) ) {
	final class CVD_Costs { // lo justo de Core 3.14.25 para estas pruebas
		public const PROVISIONAL = array( 'formula', 'por_confirmar' );
		public static function is_bajo_pedido( $p ) { return '1' === (string) $p->get_meta( '_bajo' ); }
		public static function rules( $bajo, $p = null, $full = null ) { $v = $p ? $p->get_meta( '_cvd_fixed_usd' ) : ''; return array( 'fixed' => '' !== $v ? (float) $v : 1.58, 'pct' => 10, 'min' => 1 ); }
	}
}
$GLOBALS['products'][100]->m['_cvd_fixed_usd'] = '1.6';
$GLOBALS['orders'] = array();
order( 3020, 5, array( array( 100, 0, 1, 30, 3 ) ) );
$GLOBALS['orders'][3020]->m['_cvd_commission_breakdown'][0]['extra'] = array( 'gestora' => 3, 'staff' => 0.9, 'staff_pct' => 3 );
$r = CVD_Programa::compute( '2026-11' );
ok( abs( $r['people'][5]['own_points'] - 6.1 ) < 0.01, 'extra en tienda: se resta el premio de la dependienta (30 − 20 − 3 − 0,90 = 6,10): ' . $r['people'][5]['own_points'] );
ok( 7 === CVD_Programa::product_points( $GLOBALS['products'][100], 2 )['points'], 'gastos fijos apagados (por defecto, D55): la alfombra sigue en ⭐ 7' );
$GLOBALS['opts']['cvd_programa_gastos_fijos'] = 'si';
$GLOBALS['meta'][100]['_cvd_cost_source'] = 'formula';
$r = CVD_Programa::compute( '2026-11' );
ok( abs( $r['people'][5]['own_points'] - 4.5 ) < 0.01, 'con gastos fijos: 30 − 20 − 3 − 0,90 − 1,60 = 4,50: ' . $r['people'][5]['own_points'] );
ok( 5 === CVD_Programa::product_points( $GLOBALS['products'][100], 2 )['points'], 'con gastos fijos: alfombra ⭐ 5 (30 − 20 − 3 − 1,60)' );
ok( in_array( 'provisional', array_column( $r['alerts'], 'type' ), true ), 'costo de fórmula: cuenta con la alerta «provisional»' );
unset( $GLOBALS['opts']['cvd_programa_gastos_fijos'] );

// ---------- 3.14.29 (D75): venta de proveedor resta el fondo de la operación ----------
if ( ! class_exists( 'CVD_Supplier_Split' ) ) {
	final class CVD_Supplier_Split { // lo justo de Core 3.14.14
		public static function saved( $o ) { return array( 'snapshot' => array( 'rate' => 800, 'fundCup' => 2400 ) ); }
		public static function compute( $o ) { return array(); }
		public static function owner_gestor_user() { return absint( get_option( 'cvd_owner_gestor_user', 0 ) ); } // 3.14.48
		public static function offweb_sales( $m = '' ) { return $GLOBALS['offweb'] ?? array(); }
	}
}
$GLOBALS['products'][400] = new WC_Product( 400, 'Lámpara MK-1155' ); $GLOBALS['products'][400]->m['_bajo'] = '1'; $GLOBALS['meta'][400] = array( '_cvd_cost_usd' => '32', '_cvd_cost_source' => 'proveedor' );
$GLOBALS['orders'] = array();
order( 3021, 5, array( array( 400, 0, 1, 45, 5 ), array( 100, 0, 1, 30, 3 ) ) );
$r = CVD_Programa::compute( '2026-11' );
ok( abs( $r['people'][5]['own_points'] - ( 5 + 7 ) ) < 0.01, 'proveedor: 45 − 32 − 5 − 3 USD de fondo (2.400 CUP / 800) = 5, más la alfombra 7 = 12: ' . $r['people'][5]['own_points'] );
// 3.14.48 (D92): con la cuenta de gestor del dueño, la línea MK- (5 puntos) suma también a sus puntos de proveedor.
$GLOBALS['users'][7] = new WP_User( 7, 'Dueño Ejemplo', '2026-10-22 12:00:00' ); $GLOBALS['umeta'][7]['_cvd_account_status'] = 'approved';
$GLOBALS['opts']['cvd_owner_gestor_user'] = '7';
$GLOBALS['offweb'] = array( array( 'date' => '2026-11-11', 'points' => 19.0, 'saleUsd' => 380.0, 'state' => 'cerrado' ) );
$r = CVD_Programa::compute( '2026-11' );
ok( abs( $r['people'][7]['supplier_points'] - ( 5 + 19 ) ) < 0.01 && abs( $r['people'][5]['own_points'] - 12 ) < 0.01, 'D92: el dueño suma 5 (MK- de la gestora) + 19 (fuera de la web); ella sigue con 12: ' . ( $r['people'][7]['supplier_points'] ?? 'x' ) );
ok( 0 === $r['people'][7]['bonus_pay_cup'], 'D92: el dueño sin bono' );
unset( $GLOBALS['opts']['cvd_owner_gestor_user'], $GLOBALS['users'][7], $GLOBALS['offweb'] );

// ---------- 3.14.29 (D75): red y bono por «Pedir mi pago» ----------
$GLOBALS['wpdb']->results = array( array( 'id' => 9, 'period' => '2026-11', 'stone' => 'plata', 'pay_red_usd' => '8.00', 'pay_bonus_cup' => '6933', 'rate_cup' => '800', 'computed_at' => '2026-12-01 08:00:00' ) );
$pl = CVD_Programa::payout_lines( 5 );
ok( 1 === count( $pl ) && CVD_Programa::is_payout_line( $pl[0]['id'] ) && abs( $pl[0]['cup'] - 13333 ) < 0.01, 'cierre en «sí»: un renglón de 8 USD + 6.933 CUP = 13.333 CUP en «Pedir mi pago»' );
ok( ! CVD_Programa::is_payout_line( 3021 ), 'un pedido normal no es renglón del programa' );
$GLOBALS['wpdb']->query_result = 0; $threw = false;
try { CVD_Programa::reserve_for_payout( array( $pl[0]['id'] ), 77, 5 ); } catch ( RuntimeException $e ) { $threw = true; }
ok( $threw, 'si la red ya se pidió en otra solicitud, no se reserva dos veces (lanza y CVD_Payouts deshace)' );
$GLOBALS['opts']['cvd_programa_gestoras'] = 'no';
ok( array() === CVD_Programa::payout_lines( 5 ), 'con el interruptor en «no» no sale nada en «Pedir mi pago»' );
$GLOBALS['opts']['cvd_programa_gestoras'] = 'sombra';
$GLOBALS['wpdb']->results = null; $GLOBALS['wpdb']->query_result = 1;

// ---------- 3.14.30 (D77): reembolso de una parte del pedido ----------
$GLOBALS['users'][6] = new WP_User( 6, 'Nora Ejemplo', '2026-10-05 15:00:00' ); $GLOBALS['umeta'][6]['_cvd_account_status'] = 'approved';
order( 3030, 6, array( array( 100, 0, 4, 120, 12 ) ), array( '_items' => array( 91 => new WC_Item_Stub( 100 ) ), '_refunded' => array( 91 => -1 ) ) ); // 4 alfombras, 1 reembolsada
$r = CVD_Programa::compute( '2026-11' );
ok( abs( $r['people'][6]['own_points'] - 21 ) < 0.01, 'reembolso parcial: 3 de 4 alfombras → 90 − 60 − 9 = 21 puntos: ' . $r['people'][6]['own_points'] );
ok( abs( $r['people'][6]['own_sales_usd'] - 90 ) < 0.01, 'reembolso parcial: cuenta 90 USD vendidos, no 120' );
unset( $GLOBALS['orders'][3030], $GLOBALS['users'][6] );

// ---------- D79 (3.14.32): cuentas que comparten datos con su madrina (lectura de WordPress), «Revisada» y cierre que espera ----------
if ( ! class_exists( 'CVD_Payouts' ) ) {
	final class CVD_Payouts { // lo justo: «descifra» lo que empieza por enc:
		public static function decrypt( string $v ): string { return 0 === strpos( $v, 'enc:' ) ? substr( $v, 4 ) : ''; }
	}
}
class FakeWpdbD79 extends FakeWpdb {
	public $payouts = array(); private $last = '';
	public function prepare( $q, ...$a ) { $this->last = $q . '|' . implode( ',', $a ); return $this->last; }
	public function get_results( $q, $o = null ) {
		if ( false !== strpos( $q, 'cvd_payouts WHERE owner_user_id' ) ) { $owner = (int) substr( $q, strrpos( $q, '|' ) + 1 ); return $this->payouts[ $owner ] ?? array(); }
		return parent::get_results( $q, $o );
	}
}
$GLOBALS['wpdb'] = new FakeWpdbD79();
// Ana (2) pone de WhatsApp el mismo número que Marta (1) tiene en su perfil.
$GLOBALS['umeta'][2]['_cvd_whatsapp'] = '+53 5555 1234';
$GLOBALS['umeta'][1]['billing_phone'] = '55551234';
// Rosa (3) guardó para cobrar la misma tarjeta con la que Marta cobró un pago de antes.
$GLOBALS['umeta'][3]['_cvd_payout_account'] = 'enc:9200111122223333';
$GLOBALS['wpdb']->payouts[1] = array( array( 'account_value' => 'enc:9200 1111 2222 3333', 'phone_value' => '' ) );
// Rosa y Marta mandan pedidos a la misma casa (escrita distinto). Ana y Marta, a la tienda (no cuenta).
$GLOBALS['opts']['woocommerce_store_address'] = 'Calle Obispo 101'; $GLOBALS['opts']['woocommerce_store_city'] = 'Habana Vieja';
$GLOBALS['orders'] = array();
order( 3001, 3, array( array( 100, 0, 10, 300, 30 ) ), array( '_ba1' => 'Calle 23 #456 e/ A y B', '_bc' => 'Plaza' ) );
order( 3005, 1, array( array( 100, 0, 2, 60, 6 ) ), array( '_ba1' => 'Calle 23 No. 456 entre A y B', '_bc' => 'Plaza' ) );
order( 3003, 2, array( array( 100, 0, 5, 150, 15 ) ), array( '_ba1' => 'calle obispo #101', '_bc' => 'Habana Vieja' ) );
order( 3012, 1, array( array( 100, 0, 1, 30, 3 ) ), array( '_ba1' => 'Obispo 101', '_bc' => 'Habana Vieja' ), 'cancelled' );
$r = CVD_Programa::compute( '2026-11' );
$cc = array();
foreach ( $r['alerts'] as $a ) { if ( 'cuenta_compartida' === $a['type'] ) { $cc[ $a['user'] . '-' . $a['member'] ] = $a; } }
ok( 2 === count( $cc ), 'cuenta_compartida con datos de WordPress: 2 alertas: ' . implode( ' / ', array_keys( $cc ) ) );
ok( isset( $cc['2-1'] ) && array( 'telefono' ) === $cc['2-1']['kinds'], 'Ana y Marta: el mismo teléfono (WhatsApp de una, perfil de la otra), sin la dirección de la tienda' );
ok( isset( $cc['3-1'] ) && array( 'direccion', 'tarjeta' ) === $cc['3-1']['kinds'] && 2 === $cc['3-1']['level'], 'Rosa y Marta (2.º nivel): la tarjeta de un pago viejo y la misma casa' );
ok( isset( $cc['3-1'] ) && false !== strpos( $cc['3-1']['text'], '…3333' ) && false === strpos( wp_json_encode( $r ), '9200111122223333' ), 'la tarjeta entera no se guarda en el cálculo (solo …3333)' );
ok( ! isset( $r['people'][1]['ids'] ) && false === strpos( wp_json_encode( $r ), '55551234' ), 'los datos de las gestoras no van al libro' );
ok( abs( $r['people'][3]['own_points'] - 70 ) < 0.01 && abs( $r['people'][2]['red1'] - 3 ) < 0.01, 'no bloquea: Rosa cuenta sus 70 puntos y Ana su red' );

// Panel: clave, «Revisada» y cuántas faltan.
$GLOBALS['wpdb']->latest = array( 'id' => 8, 'period' => '2026-11', 'kind' => 'diario', 'status' => 'vista', 'computed_at' => gmdate( 'Y-m-d H:i:s' ), 'rate_cup' => 800, 'payload_sha256' => str_repeat( 'b', 64 ), 'payload' => json_encode( $r ) );
$rep = CVD_Programa::report();
ok( 2 === $rep['pendingReview'] && 'cuenta_compartida' === $rep['alerts'][0]['type'], 'panel: 2 alertas sin revisar y «cuenta_compartida» va arriba' );
ok( $rep['alerts'][0]['canReview'] && ! $rep['alerts'][0]['reviewed'] && '' !== $rep['alerts'][0]['key'], 'panel: cada alerta trae su clave y si se puede marcar «Revisada»' );
ok( CVD_Programa::mark_reviewed( 'cuenta_compartida:2:1:telefono', 9 ), 'marcar «Revisada» la de Ana y Marta' );
ok( ! CVD_Programa::mark_reviewed( 'revisar:1:1', 9 ) && ! CVD_Programa::mark_reviewed( 'cuenta_compartida:<b>', 9 ), 'solo se marcan «ganancia_alta» y «cuenta_compartida», con una clave limpia' );
$rep = CVD_Programa::report();
$rev = array_values( array_filter( $rep['alerts'], static fn( $a ) => 'cuenta_compartida:2:1:telefono' === $a['key'] ) );
ok( 1 === $rep['pendingReview'] && $rev && $rev[0]['reviewed'], 'después: queda 1 sin revisar y la de Ana sale «Revisada»' );
// El cierre automático espera mientras quede una sin revisar; «Cerrar ahora» (run) no pasa por aquí.
ok( 0 === CVD_Programa::close_when_reviewed( '2026-11' ) && '2026-11' === get_option( 'cvd_programa_cierre_espera' ), 'cierre automático: espera (queda la de Rosa y Marta) y lo apunta' );
ok( '2026-11' === CVD_Programa::report()['closeWaiting'], 'panel: «el cierre de 2026-11 espera»' );
CVD_Programa::mark_reviewed( 'cuenta_compartida:3:1:direccion,tarjeta', 9 );
CVD_Programa::close_when_reviewed( '2026-11' ); // con todo revisado intenta cerrar (aquí la base de datos es falsa: el cierre ya «existe»)
ok( '' === (string) get_option( 'cvd_programa_cierre_espera', '' ), 'todo revisado: el cierre ya no espera' );
$GLOBALS['wpdb'] = new FakeWpdb();

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
