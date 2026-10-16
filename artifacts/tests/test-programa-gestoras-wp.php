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

$GLOBALS['opts'] = array( 'cvd_programa_gestoras' => 'sombra', 'cvd_programa_ver' => '2', 'cvd_default_commission_rate' => 10, 'cvd_programa_schema' => '1' );
$GLOBALS['meta'] = array(); $GLOBALS['umeta'] = array(); $GLOBALS['orders'] = array(); $GLOBALS['products'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
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
}
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
	public function get_results( $q, $o = null ) { return array(); }
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
ok( abs( $P[2]['red1'] - 7.8 ) < 0.01, 'Ana: 2 % de 390 = 7,80' );
ok( 0.0 === (float) $P[4]['own_points'] && ! $P[4]['approved'], 'cuenta de prueba fuera del programa' );
ok( 'bronce' === $P[1]['stone'], 'Marta: Bronce con 14 + 35 + 39 (tope del 60 % de Rosa) = 88 ≥ 65' );
ok( abs( $P[1]['red1'] - 3.5 ) < 0.01, 'Marta: 2 % de los 175 de Ana = 3,50' );
ok( 0.0 === (float) $P[1]['red2'] && abs( $P[1]['red_lost'] - 3.9 ) < 0.01, 'Marta no es Plata: pierde el 1 % de Rosa (3,90)' );
$types = array_column( $r['alerts'], 'type' );
ok( in_array( 'autocompra', $types, true ) && in_array( 'sin_costo', $types, true ), 'alertas de autocompra y de producto sin costo' );
ok( in_array( 'revisar', $types, true ) && abs( $P[5]['own_points'] - 7 ) < 0.01, 'Lola: pedido con su teléfono pero otro cliente → cuenta (7 puntos) con alerta «revisar»' );
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
ok( false !== strpos( $eq, 'Rosa Ejemplo' ) && false !== strpos( $eq, '7,80 USD' ), 'Mi equipo: Rosa y lo que le deja (7,80 USD)' );
ok( false !== strpos( $eq, 'invita=CV2' ), 'Mi equipo: su enlace para invitar' );
$pi = CVD_Programa::render_piedra( $GLOBALS['users'][1] );
ok( false !== strpos( $pi, 'Bronce' ) && false !== strpos( $pi, '5.000 CUP' ), 'Mi piedra: Bronce y su bono' );
ok( false !== strpos( $pi, 'Para llegar a Plata' ), 'Mi piedra: cuánto falta para Plata' );
file_put_contents( sys_get_temp_dir() . '/programa-ejemplo.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="gestora-app.css"><body class="cvga-body"><div class="cvga"><main class="cvga-main"><div class="cvga-wrap">' . $eq . $pi . '</div></main></div>' );

// ---------- 3.14.29: encima de 3.14.25 (gastos fijos D74, extra en tienda 15-oct, costo provisional D62) ----------
if ( ! class_exists( 'CVD_Costs' ) ) {
	final class CVD_Costs { // lo justo de Core 3.14.25 para estas pruebas
		public const PROVISIONAL = array( 'formula', 'por_confirmar' );
		public static function is_bajo_pedido( $p ) { return false; }
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

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
