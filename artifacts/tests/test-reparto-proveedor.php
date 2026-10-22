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
function selected( $a, $b ) { echo (string) $a === (string) $b ? ' selected="selected"' : ''; }
function wp_unslash( $v ) { return $v; }
class WP_User { public $ID; public function __construct( $id ) { $this->ID = $id; } }

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

// ---------- 3.14.50: desglose de cada venta y estadísticas de «Mis ventas de proveedor» ----------
// Las cifras de octubre son las reales de producción (22-oct). #3845 con la regla nueva de Ernesto (22-oct 14:35): el
// recargo de la Tarjeta Clásica es ganancia (sellado: webUsd 120,75; margen 29.216; Ernesto 18.338 CUP).
$GLOBALS['orders'] = array(); $GLOBALS['opts'] = array( 'cvd_owner_gestor_user' => (string) OG ); reset_cache();
foreach ( array( 29 => 'Nadielys Martinez Aguilera', 30 => 'Maria Caridad Dinza Real', 31 => 'Dayisel Vaca Cobas', 32 => 'Thalia Hernández González', 50 => 'Daniela Moreno Sánchez' ) as $uid => $uname ) {
	$GLOBALS['users'][ $uid ] = (object) array( 'display_name' => $uname );
}
/** Pedido real de octubre: una línea MK- del proveedor $sup; cerrado = sellado con la tasa. */
function oct_order( int $id, int $owner, float $web, float $cost, float $gestora, float $rate, int $qty, string $sup, array $input, bool $closed, string $status = 'on-hold', ?array $snapshot = null, ?int $created = null ): WC_Order {
	$o = mk_order( $id, $owner, $web, $cost, $gestora, $rate, $qty, $input + array( 'supplier' => $sup ), $closed ? ( $snapshot ?? array( 'rate' => $rate ) ) : null, $closed ? 'completed' : $status, $created );
	$o->items[1]->p->supplier = '';
	return $o;
}
$kevin = 'Digital Store (Kevin)';
oct_order( 4963, 30, 35, 25, 4, 827, 1, $kevin, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1200, 'messengerName' => 'Mensajero de Kevin', 'clerkCup' => 500, 'storeCup' => 3000 ), false );
oct_order( 4253, 29, 140, 119, 8.4, 815, 1, $kevin, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1200, 'messengerName' => 'Mensajero de Kevin', 'clerkCup' => 500, 'storeCup' => 3000 ), false );
oct_order( 4244, 31, 28, 16.4, 4.64, 815, 2, '', array( 'route' => 'B', 'vehicle' => 'moto', 'messengerCup' => 1000, 'messengerName' => 'Alejandro', 'clerkCup' => 1000, 'storeCup' => 1500 ), true );
oct_order( 4201, 32, 40, 25, 9, 810, 1, '', array( 'route' => 'B', 'vehicle' => 'moto', 'messengerCup' => 1000, 'messengerName' => 'Pedri', 'clerkCup' => 500, 'storeCup' => 1500 ), true );
$snap3845 = array( 'supplier' => $kevin, 'route' => 'A', 'vehicle' => 'moto', 'webUsd' => 120.75, 'costUsd' => 84, 'rate' => 795, 'marginUsd' => 36.75, 'marginCup' => 29216,
	'gestora' => 'Nadielys Martinez Aguilera', 'gestoraUsd' => 8.4, 'gestoraCup' => 6678, 'messengerCup' => 1200, 'messengerName' => 'Mensajero de Kevin', 'clerkCup' => 500, 'clerkName' => 'Zaymi',
	'storeCup' => 2500, 'fundCup' => 4200, 'specialCup' => 18338, 'specialName' => 'Ernesto', 'minCup' => 11673, 'meetsMin' => true,
	'surchargeUsd' => 15.75, 'surchargeNote' => 'Recargo Tarjeta Clásica (105 × 1,15 = 120,75 USD), cambiado a USD y entregado a Lennys: ganancia (Ernesto 22-oct)' );
$o3845 = oct_order( 3845, 29, 105, 84, 8.4, 795, 1, $kevin, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1200, 'clerkCup' => 500, 'clerkName' => 'Zaymi', 'storeCup' => 2500 ), true, 'on-hold', $snap3845 );
$o3845->m += array( '_cvd_payment_kind' => 'clasica', '_cvd_payment_currency' => 'USD', '_cvd_payment_factor' => 1.15, '_cvd_payment_usd' => 105, '_cvd_payment_amount' => 120.75 );
oct_order( 3693, 29, 45, 28, 5.2, 790, 1, $kevin, array( 'route' => 'B', 'vehicle' => 'moto', 'messengerCup' => 1000, 'messengerName' => 'Pedri', 'clerkCup' => 500, 'clerkName' => 'Nana', 'storeCup' => 2500 ), true );
oct_order( 3684, 50, 54, 38, 0, 830, 1, '', array(), false, 'cancelled' );
oct_order( 3001, 29, 50, 30, 4, 780, 1, $kevin, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1000, 'clerkCup' => 500, 'storeCup' => 3000 ), true, 'on-hold', null, strtotime( '-40 days' ) ); // mes pasado
$GLOBALS['opts']['cvd_owner_offweb_sales'] = json_encode( array( array( 'date' => wp_date( 'Y-m' ) . '-11', 'product' => 'Split Parker', 'supplier' => 'TRD 42 y 23', 'gestora' => 'Harley Adrián Peña La Rosa',
	'gestoraUsd' => 10, 'saleUsd' => 380, 'costUsd' => 351, 'specialUsd' => 19, 'rate' => 0, 'paidCup' => 291593.75, 'note' => 'Entrega por Ernesto (sin mensajero).', 'state' => 'cerrado' ) ) );

// Desglose de #3845 (cifras reales, recargo de la Clásica como ganancia).
$rows = CVD_Supplier_Split::owner_rows( wp_date( 'Y-m' ) );
$by = array_column( $rows, null, 'num' );
$r = $by['#3845'];
ok( 105.0 === $r['priceUsd'] && 83475 === $r['priceCup'] && 15.75 === $r['surchargeUsd'] && 12521 === $r['surchargeCup'] && 95996 === $r['webCup'], '#3845: precio web 105 = 83.475 CUP + recargo 15,75 = 12.521 → cobrado 95.996 CUP' );
ok( 66780 === $r['costCup'] && 29216 === $r['marginCup'] && 6678 === $r['gestoraCup'] && 18338 === $r['specialCup'], '#3845: costo 66.780, margen 29.216, Nadielys 6.678, Ernesto 18.338' );
ok( $r['marginCup'] - $r['gestoraCup'] - $r['messengerCup'] - $r['clerkCup'] - $r['storeCup'] === $r['specialCup'], '#3845: margen − gestora − mensajero − Zaymi − tienda = tu comisión' );
near( $r['specialUsd'], 23.07, '#3845: tu comisión en USD (18.338 / 795)' );
$d = CVD_Supplier_Split::render_detail( $r );
$has = static fn( string $needle ): bool => false !== strpos( $d, $needle );
ok( $has( '<dt>Pedido</dt><dd>#3845</dd>' ) && $has( '<dt>Producto</dt><dd>Producto MK-3845</dd>' ), 'desglose: número de pedido y producto' );
ok( $has( '<dt>Gestora</dt><dd>Nadielys Martinez Aguilera</dd>' ) && $has( '<dt>Proveedor</dt><dd>Digital Store (Kevin)</dd>' ), 'desglose: gestora y proveedor' );
ok( $has( 'Ruta A: el proveedor lo trae a la tienda · Moto' ) && $has( '795 CUP por 1 USD (la del día del pedido)' ), 'desglose: ruta, vehículo y tasa' );
ok( $has( '<td>Precio web</td><td>105,00</td><td>83.475</td>' ), 'desglose: Precio web 105,00 · 83.475' );
ok( $has( '+ Recargo Tarjeta Clásica<small>ganancia</small></td><td>15,75</td><td>12.521</td>' ), 'desglose: + Recargo Tarjeta Clásica (ganancia) 15,75 · 12.521' );
ok( $has( '<td>= Cobrado al cliente</td><td>120,75</td><td>95.996</td>' ), 'desglose: = Cobrado al cliente 120,75 · 95.996' );
ok( $has( '<td>− Costo al proveedor</td><td>84,00</td><td>66.780</td>' ) && $has( '<td>= Margen</td><td>36,75</td><td>29.216</td>' ), 'desglose: costo 84 y margen 36,75 = 29.216' );
ok( $has( '− Comisión de la gestora<small>Nadielys Martinez Aguilera</small></td><td>8,40</td><td>6.678</td>' ), 'desglose: Nadielys 8,40 = 6.678' );
ok( $has( '− Mensajero/transporte<small>Mensajero de Kevin</small></td><td>1,51</td><td>1.200</td>' ) && $has( '− Dependienta<small>Zaymi</small></td><td>0,63</td><td>500</td>' ), 'desglose: mensajero 1.200 y Zaymi 500' );
ok( $has( '<td>− Tienda Casa Viva</td><td>3,14</td><td>2.500</td>' ) && $has( '<td>= Tu comisión</td><td>23,07</td><td>18.338</td>' ), 'desglose: tienda 2.500 y tu comisión 18.338' );
ok( $has( 'Cobrado al cliente: 120,75 USD por Clásica (recargo 15 % de la tarjeta: 15,75 USD de ganancia, ya sumados al margen)' ), 'desglose: línea de la Clásica' );
ok( ! $has( 'no entra en el reparto' ) && $has( 'entregado a Lennys: ganancia' ), 'desglose: sin «no entra en el reparto» y con la nota del recargo' );
ok( $has( 'Estado: cerrado (entregado y cobrado el 15/10/2026; ya no cambia). Tu parte: pendiente de pago por la tienda.' ), 'desglose: estado cerrado y pendiente de pago' );

// Clásica que NO se sumó al reparto (pedido abierto): solo la línea del recargo, sin cobrado ni «ganancia».
$o = oct_order( 3846, 29, 105, 84, 8.4, 795, 1, $kevin, array( 'route' => 'A', 'vehicle' => 'moto', 'messengerCup' => 1200, 'clerkCup' => 500, 'clerkName' => 'Zaymi', 'storeCup' => 2500 ), false );
$o->m += array( '_cvd_payment_kind' => 'clasica', '_cvd_payment_currency' => 'USD', '_cvd_payment_factor' => 1.15, '_cvd_payment_amount' => 120.75 );
$r46 = array_column( CVD_Supplier_Split::owner_rows( wp_date( 'Y-m' ) ), null, 'num' )['#3846'];
ok( 16695 === $r46['marginCup'] && 6678 === $r46['gestoraCup'] && 5817 === $r46['specialCup'], 'sin recargo sumado: 105 − 84 = 21 × 795 = 16.695; Nadielys 6.678; Ernesto 5.817' );
$d46 = CVD_Supplier_Split::render_detail( $r46 );
ok( false === strpos( $d46, 'Recargo Tarjeta' ) && false !== strpos( $d46, 'Cobrado al cliente: 120,75 USD por Clásica (recargo 15 % de la tarjeta)' ), 'sin recargo sumado: solo la línea de la Clásica' );
ok( false !== strpos( $d46, 'Estado: abierto' ) && false !== strpos( $d46, 'pendiente de pago por la tienda' ), 'abierto: estado y pendiente de pago' );
unset( $GLOBALS['orders'][3846] );

// Fuera de la web: desglose sencillo, sin tasa.
$dw = CVD_Supplier_Split::render_detail( $by['Fuera'] );
ok( false !== strpos( $dw, '<td>Venta</td><td>380,00</td><td>—</td>' ) && false !== strpos( $dw, '<td>= Margen</td><td>29,00</td><td>—</td>' ) && false !== strpos( $dw, '<td>= Tu comisión</td><td>19,00</td><td>—</td>' ), 'fuera de la web: venta 380, margen 29, tu parte 19 (sin tasa)' );
ok( false !== strpos( $dw, 'Harley' ) && false !== strpos( $dw, 'Cobrado al cliente: 291.594 CUP' ) && false === strpos( $dw, 'Mensajero' ), 'fuera de la web: gestora, lo cobrado y sin mensajero/dependienta/tienda' );

// Estadísticas del mes (octubre real): 7 ventas sin las canceladas.
$st = CVD_Supplier_Split::owner_stats( $rows );
ok( 7 === $st['count'] && 5 === $st['closed'] && 2 === $st['open'], 'estadísticas: 7 ventas (5 cerradas, 2 abiertas), la cancelada no cuenta' );
near( $st['priceUsd'], 773, 'estadísticas: vendido a precio web' );
near( $st['surchargeUsd'], 15.75, 'estadísticas: recargo de la Clásica' );
near( $st['marginUsd'], 140.35, 'estadísticas: margen en USD' );
ok( 89635 === $st['margin']['cup'] && abs( $st['margin']['usd'] - 29 ) < 0.001, 'estadísticas: margen 89.635 CUP + 29 USD sin tasa' );
ok( 32012 === $st['gestoras']['cup'] && abs( $st['gestoras']['usd'] - 10 ) < 0.001, 'estadísticas: gestoras 32.012 CUP + 10 USD' );
ok( 24100 === $st['fund']['cup'] && 6600 === $st['messengerCup'] && 3500 === $st['clerkCup'] && 14000 === $st['storeCup'], 'estadísticas: fondo 24.100 = mensajeros 6.600 + dependientas 3.500 + tienda 14.000' );
ok( 33523 === $st['mine']['cup'] && 27692 === $st['mineClosed']['cup'] && 5831 === $st['mineOpen']['cup'] && abs( $st['mineClosed']['usd'] - 19 ) < 0.001, 'estadísticas: tu comisión 33.523 (cerrado 27.692 + 19 USD, abierto 5.831)' );
ok( $st['margin']['cup'] === $st['gestoras']['cup'] + $st['fund']['cup'] + $st['mine']['cup'], 'estadísticas: margen = gestoras + fondo + tu comisión (CUP)' );
near( $st['marginPct'], 17.8, 'estadísticas: margen medio %', 0.05 );
near( $st['mineUsd'], 60.93, 'estadísticas: tu parte en USD' );
near( $st['avgUsd'], 8.70, 'estadísticas: tu parte media por venta' );
ok( null === $st['avgCup'], 'estadísticas: sin media en CUP si hay una venta sin tasa' );
$g0 = $st['byGestora'][0];
ok( 'Nadielys Martinez Aguilera' === $g0['name'] && 3 === $g0['count'] && 29229 === $g0['mine']['cup'], 'por gestora: Nadielys arriba, 3 ventas, 29.229 CUP' );
ok( 5 === count( $st['byGestora'] ) && 'Harley Adrián Peña La Rosa' === $st['byGestora'][1]['name'], 'por gestora: 5 gestoras, Harley segunda (19 USD)' );
ok( array( 'Digital Store (Kevin)', 'TRD 42 y 23', 'Proveedor sin nombre' ) === array_column( $st['bySupplier'], 'name' ) && 4 === $st['bySupplier'][0]['count'] && 29491 === $st['bySupplier'][0]['mine']['cup'], 'por proveedor: Kevin 4 ventas 29.491 CUP, TRD, sin nombre' );

// «Todo»: también la del mes pasado.
$all = CVD_Supplier_Split::owner_rows( 'todo' );
ok( count( $all ) === count( $rows ) + 1 && in_array( '#3001', array_column( $all, 'num' ), true ) && ! in_array( '#3001', array_column( $rows, 'num' ), true ), 'Todo: incluye la venta del mes pasado; el mes no' );
ok( 8 === CVD_Supplier_Split::owner_stats( $all )['count'], 'Todo: 8 ventas sin la cancelada' );

// La vista: solo la cuenta del dueño; selector con «Todo»; cada venta en un <details>.
$_GET['cvd_mes'] = 'todo';
$html = CVD_Supplier_Split::render_owner_sales( new WP_User( OG ) );
ok( false !== strpos( $html, '<option value="todo" selected="selected">Todo (desde el inicio)</option>' ) && false !== strpos( $html, 'Resumen desde el inicio' ), 'vista: selector «Todo» elegido y resumen desde el inicio' );
ok( 9 === substr_count( $html, '<details>' ) && false !== strpos( $html, 'Por gestora' ) && false !== strpos( $html, 'Por proveedor' ), 'vista: 9 ventas (con la cancelada) con desglose y las dos listas' );
unset( $_GET['cvd_mes'] );
$html = CVD_Supplier_Split::render_owner_sales( new WP_User( OG ) );
ok( false !== strpos( $html, '<dt>Vendido (precio web)</dt><dd>773,00 USD</dd>' ) && false !== strpos( $html, '<dt>Tu comisión</dt><dd>33.523 CUP + 19,00 USD</dd>' ), 'vista del mes: vendido 773 USD y tu comisión 33.523 CUP + 19 USD' );
ok( '' === CVD_Supplier_Split::render_owner_sales( new WP_User( 29 ) ), 'otra gestora: no ve «Mis ventas de proveedor»' );

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
