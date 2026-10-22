<?php
/**
 * 3.14.49: pruebas del reparto de ventas de proveedor (CVD_Supplier_Split) y de las comisiones de proveedor de la
 * cuenta de gestor del dueño (Inicio «Ganado este mes» y «Tus comisiones»), con WordPress/WooCommerce simulados.
 * Sin base de datos real. Ejecutar: php artifacts/tests/test-reparto-proveedor.php   (sale con 1 si algo falla)
 * Nombres inventados: no son gestoras reales.
 */

define( 'ABSPATH', __DIR__ . '/' );
date_default_timezone_set( 'UTC' );

$GLOBALS['opts'] = array(); $GLOBALS['orders'] = array(); $GLOBALS['users'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_timezone() { return new DateTimeZone( 'America/Havana' ); }
function wp_timezone_string() { return 'America/Havana'; }
function wp_date( $f, $ts = null ) { $d = new DateTime( '@' . ( $ts ?? time() ) ); $d->setTimezone( wp_timezone() ); return $d->format( $f ); }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
function wc_get_orders( $a ) { return array(); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function get_woocommerce_currency() { return 'USD'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }
function add_action() {} function add_filter() {}

class FakeWpdb {
	public $prefix = 'wp_'; public $postmeta = 'wp_postmeta';
	public function esc_like( $v ) { return $v; }
	public function prepare( $q, ...$a ) { return $q; }
	public function get_col( $q ) { return array_keys( $GLOBALS['orders'] ); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class WC_DT { private $t; public function __construct( $t ) { $this->t = $t; } public function getTimestamp() { return $this->t; } public function date( $f ) { return gmdate( $f, $this->t ); } }
class WC_Product {
	public $sku; public $cost; public $supplier;
	public function __construct( $sku, $cost, $supplier = 'Kevin' ) { $this->sku = $sku; $this->cost = $cost; $this->supplier = $supplier; }
	public function get_sku() { return $this->sku; }
	public function get_meta( $k, $s = true ) { return '_cvd_supplier_name' === $k ? $this->supplier : ''; }
	public function get_parent_id() { return 0; }
}
class WC_Item {
	public $p; public $qty; public $total; public $snap;
	public function __construct( $p, $qty, $total, $snap ) { $this->p = $p; $this->qty = $qty; $this->total = $total; $this->snap = $snap; }
	public function get_product() { return $this->p; }
	public function get_quantity() { return $this->qty; }
	public function get_total() { return $this->total; }
	public function get_name() { return 'Producto ' . $this->p->sku; }
	public function get_meta( $k, $s = true ) { return '_cvd_commission_snapshot' === $k ? $this->snap : ''; }
}
class WC_Order {
	public $id; public $status; public $m; public $items; public $created;
	public function __construct( $id, $status, $m, $items, $created ) { $this->id = $id; $this->status = $status; $this->m = $m; $this->items = $items; $this->created = $created; }
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return $this->status; }
	public function get_order_number() { return (string) $this->id; }
	public function get_meta( $k, $s = true ) { return $this->m[ $k ] ?? ''; }
	public function get_items( $t = '' ) { return $this->items; }
	public function get_date_created() { return new WC_DT( $this->created ); }
}
final class CVD_Costs {
	public static function is_bajo_pedido( $p ) { return 0 === strpos( $p->sku, 'MK-' ); }
	public static function cost( $p ) { return $p->cost; }
}
final class CVD_Daily_Rates {
	public static function order_rate( $o ) { $r = (float) $o->get_meta( '_rate' ); return $r > 0 ? $r : null; }
	public static function order_rate_day( $o ) { return ''; }
	public static function get() { return array( 'cup' => 0 ); }
	public static function format_rate( $r ) { return (string) $r; }
	public static function format_cup( int $c ) { return number_format( $c, 0, ',', '.' ) . ' CUP'; }
}

require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-programa-calc.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-supplier-split.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-gestora-financial-view.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-gestora-app.php';

$fails = 0; $count = 0;
function ok( bool $c, string $w ): void { global $fails, $count; $count++; if ( ! $c ) { $fails++; echo "FALLA: $w\n"; } }
function near( $a, $b, string $w, float $eps = 0.011 ): void { ok( abs( (float) $a - (float) $b ) <= $eps, $w . " (esperado $b, salió $a)" ); }
function reset_cache(): void { $p = new ReflectionProperty( 'CVD_Supplier_Split', 'owner_cache' ); $p->setAccessible( true ); $p->setValue( null, array() ); }

/** Pedido con una línea MK- de una gestora. $split = reparto guardado (input) o null; $closed = sellado. */
function mk_order( int $id, int $owner, float $web, float $cost, float $gestora, float $rate, int $qty = 1, ?array $input = null, ?array $snapshot = null, string $status = 'on-hold', ?int $created = null ): WC_Order {
	$m = array( '_cvd_owner_type' => 'gestora', '_cvd_owner_user_id' => $owner, '_rate' => $rate );
	if ( $input || $snapshot ) { $m['_cvd_supplier_split'] = json_encode( array( 'v' => 1, 'input' => $input ?? array(), 'snapshot' => $snapshot ) ); }
	if ( $snapshot ) { $m['_cvd_supplier_split_closed_at'] = '2026-10-15T12:00:00+00:00'; }
	$item = new WC_Item( new WC_Product( 'MK-' . $id, $cost / $qty ), $qty, $web, array( 'base_commission' => $gestora, 'markup' => 0 ) );
	return $GLOBALS['orders'][ $id ] = new WC_Order( $id, $status, $m, array( 1 => $item ), $created ?? time() );
}

// ---------- 3. Dependienta: ruta C (directo del proveedor al cliente) = 0 ----------
ok( 0 === CVD_Supplier_Split::clerk_default( 'C', 2 ), 'ruta C: dependienta 0 aunque sean 2 artículos' );
ok( 1000 === CVD_Supplier_Split::clerk_default( 'B', 2 ) && 500 === CVD_Supplier_Split::clerk_default( 'A', 1 ), 'rutas A y B: 500 CUP por artículo, como antes' );
$GLOBALS['opts']['cvd_supplier_split_clerk_per_item'] = 600;
ok( 1200 === CVD_Supplier_Split::clerk_default( 'B', 2 ) && 0 === CVD_Supplier_Split::clerk_default( 'C', 2 ), 'con la opción a 600: B = 1.200, C sigue en 0' );
unset( $GLOBALS['opts']['cvd_supplier_split_clerk_per_item'] );

$o = mk_order( 501, 29, 105, 84, 8.4, 800, 1, array( 'route' => 'C', 'vehicle' => 'ninguno' ) );
$s = CVD_Supplier_Split::compute( $o );
ok( 0 === $s['clerkCup'] && 0 === $s['messengerCup'] && 'C' === $s['route'], 'reparto ruta C sin dependienta guardada: dependienta 0 y mensajero 0' );
ok( 3000 === $s['fundCup'], 'ruta C: el fondo es solo la tienda (3.000 CUP)' );
ok( (int) round( ( 105 - 84 - 8.4 ) * 800 ) - 3000 === $s['specialCup'], 'ruta C: los 500 de la dependienta quedan para el gestor especial: ' . $s['specialCup'] );
$s = CVD_Supplier_Split::compute( $o, array( 'route' => 'C', 'vehicle' => 'ninguno', 'clerkCup' => 300 ) );
ok( 300 === $s['clerkCup'], 'ruta C: si Lennys escribe una cifra para la dependienta, se respeta' );
$s = CVD_Supplier_Split::compute( mk_order( 502, 29, 105, 84, 8.4, 800, 2, array( 'route' => 'B', 'vehicle' => 'moto' ) ) );
ok( 1000 === $s['clerkCup'], 'ruta B con 2 artículos: dependienta 1.000 CUP, como antes' );
$snap = array( 'route' => 'C', 'vehicle' => 'ninguno', 'rate' => 795, 'clerkCup' => 500, 'messengerCup' => 0, 'storeCup' => 2500, 'fundCup' => 3000, 'specialCup' => 5817 );
$s = CVD_Supplier_Split::compute( mk_order( 503, 29, 105, 84, 8.4, 795, 1, null, $snap, 'completed' ) );
ok( 500 === $s['clerkCup'] && 5817 === $s['specialCup'] && 'cerrado' === $s['state'], 'reparto cerrado de ruta C: no cambia (sigue con sus 500 sellados)' );
$GLOBALS['orders'] = array();

// ---------- 1. Comisiones de proveedor de la cuenta del dueño ----------
const OG = 137;
$GLOBALS['opts']['cvd_owner_gestor_user'] = (string) OG;
$last_month = strtotime( '-40 days' );
// Patines (cerrado, sellado): 5.817 CUP a 795 = 7,32 USD → «Listas para cobrar».
mk_order( 3845, 29, 105, 84, 8.4, 795, 1, null, array( 'route' => 'A', 'vehicle' => 'moto', 'rate' => 795, 'clerkCup' => 500, 'messengerCup' => 1200, 'storeCup' => 2500, 'fundCup' => 4200, 'specialCup' => 5817 ), 'completed' );
// Tablet (abierta): (140 − 119 − 8,4) × 815 − (1.200 + 500 + 3.000) = 5.569 CUP → «Pendientes».
mk_order( 4253, 29, 140, 119, 8.4, 815, 1, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1200, 'clerkCup' => 500, 'storeCup' => 3000 ) );
// Del mes pasado, cerrada: cuenta en «Listas para cobrar» pero no en «Ganado este mes».
mk_order( 4000, 36, 40, 25, 9, 810, 1, null, array( 'rate' => 810, 'specialCup' => 1860 ), 'completed', $last_month );
// Cancelada: no cuenta.
mk_order( 3684, 50, 54, 38, 0, 830, 1, null, null, 'cancelled' );
// Fuera de la web: cerrada sin tasa (19 USD) y abierta con tasa (10 USD a 800).
$GLOBALS['opts']['cvd_owner_offweb_sales'] = json_encode( array(
	array( 'date' => wp_date( 'Y-m' ) . '-01', 'product' => 'Split', 'saleUsd' => 380, 'costUsd' => 351, 'gestoraUsd' => 10, 'specialUsd' => 19, 'rate' => 0, 'state' => 'cerrado' ),
	array( 'date' => wp_date( 'Y-m' ) . '-01', 'product' => 'Nevera', 'saleUsd' => 500, 'costUsd' => 450, 'gestoraUsd' => 20, 'specialUsd' => 10, 'rate' => 800, 'state' => 'abierto' ),
	array( 'date' => wp_date( 'Y-m' ) . '-02', 'product' => 'TV', 'saleUsd' => 300, 'costUsd' => 250, 'gestoraUsd' => 10, 'specialUsd' => 15, 'rate' => 800, 'state' => 'cancelado' ),
) );
reset_cache();
$c = CVD_Supplier_Split::owner_commissions( OG );
near( $c['ready']['usd'], 7.32 + 2.30 + 19, 'Listas para cobrar: patines 7,32 + bolso 2,30 + Split 19 (fuera de la web)' );
ok( 3 === $c['ready']['count'] && 5817 + 1860 === $c['ready']['cup'] && abs( $c['ready']['no_rate_usd'] - 19 ) < 0.001, 'Listas: 3 ventas, 7.677 CUP con tasa y 19 USD sin tasa' );
near( $c['pending']['usd'], 6.83 + 10, 'Pendientes: tablet 5.569 / 815 = 6,83 + nevera 10' );
ok( 2 === $c['pending']['count'] && 5569 + 8000 === $c['pending']['cup'], 'Pendientes: 2 ventas, 13.569 CUP' );
near( $c['month_usd'], 7.32 + 6.83 + 19 + 10, 'este mes: sin la del mes pasado ni la cancelada' );
ok( array() === CVD_Supplier_Split::owner_commissions( 29 ), 'otra gestora: nada (no ve las comisiones de proveedor)' );

// Recuadro «Tus comisiones» del dueño (sin pedidos propios en la web).
$box = CVD_Gestora_Financial_View::commission_box( array(), OG );
ok( false !== strpos( $box, '16,83 USD' ) && false !== strpos( $box, '28,62 USD' ), 'Tus comisiones: Pendientes 16,83 USD y Listas para cobrar 28,62 USD' );
ok( false !== strpos( $box, 'Incluye 28,62 USD de 3 ventas de proveedor: la tienda te las debe' ) && false !== strpos( $box, 'Incluye 16,83 USD de 2 ventas de proveedor' ), 'Tus comisiones: dice de dónde viene' );
ok( false !== strpos( $box, '7.677 CUP y 19,00 USD sin tasa' ), 'Tus comisiones: CUP con la tasa de cada reparto y lo de sin tasa aparte' );
ok( false === strpos( $box, 'cvd-cbox-action' ),'Tus comisiones: sin «Pedir mi pago» (la parte de proveedor se paga aparte)' );
ok( false !== strpos( $box, 'no va en «Pedir mi pago»' ), 'Tus comisiones: nota de que la tienda la paga aparte' );
$plain = CVD_Gestora_Financial_View::commission_box( array(), 29 );
ok( false === strpos( $plain, 'proveedor' ) && 4 === substr_count( $plain, 'Ninguna ahora' ), 'otra gestora: su recuadro igual que antes (4 × «Ninguna ahora», sin proveedor)' );

// Inicio «Ganado este mes».
$ms = new ReflectionMethod( 'CVD_Gestora_App', 'money_summary' ); $ms->setAccessible( true );
$r = $ms->invoke( null, array(), $c['month_usd'] );
ok( '43,15 USD' === $r['month'], 'Ganado este mes del dueño: 43,15 USD (su parte de proveedor del mes): ' . $r['month'] );
$r = $ms->invoke( null, array() );
ok( '0,00 USD' === $r['month'], 'Ganado de otra gestora sin ventas: 0,00 USD, como antes' );

// Opción vacía: nada del dueño.
unset( $GLOBALS['opts']['cvd_owner_gestor_user'] ); reset_cache();
ok( array() === CVD_Supplier_Split::owner_commissions( OG ), 'opción vacía: sin comisiones de proveedor' );
ok( false === strpos( CVD_Gestora_Financial_View::commission_box( array(), OG ), 'proveedor' ), 'opción vacía: recuadro sin proveedor' );

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
