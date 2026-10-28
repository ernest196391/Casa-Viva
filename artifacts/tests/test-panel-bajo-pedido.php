<?php
/**
 * 3.14.71 (D121): Lennys publica «bajo pedido» desde su panel. Prueba con WordPress simulado (sin base de datos ni red):
 * precio sugerido = copia fiel del bot (market-price.js), comisión fija 40 %, mínimo (costo + comisión + 2,5 %),
 * rechazo de un precio por debajo del mínimo, quitar teléfonos y que la puerta del bot siga validando igual.
 * Ejecutar: php artifacts/tests/test-panel-bajo-pedido.php   (sale con 1 si algo falla)
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '', (string) $v ) ); }
function esc_url_raw( $v ) { return (string) $v; }
function add_action() {}
function wc_get_product_id_by_sku( $sku ) { $GLOBALS['sku_lookups'][] = $sku; return 0; }

class WP_REST_Response {
	public $data; public $status; public $headers = array();
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
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

$root = dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/';
require $root . 'class-cvd-costs.php';
require $root . 'class-cvd-market-bridge.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; echo ( $ok ? 'OK   ' : 'FALLA ' ) . $msg . "\n"; if ( ! $ok ) { $fail++; } }
function near( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.001; }

// 1) El ejemplo del diseño: proveedor 50 USD → sugerido 67, comisión 6,80, mínimo 58,26 (y a Ernesto 10,20).
$q = CVD_Market_Bridge::quote( 50 );
check( near( $q['suggested'], 67 ), 'proveedor 50 → sugerido 67 (sale ' . $q['suggested'] . ')' );
check( near( $q['commission'], 6.80 ), 'comisión 6,80 (sale ' . $q['commission'] . ')' );
check( near( $q['floor'], 58.26 ), 'mínimo 58,26 (sale ' . $q['floor'] . ')' );
check( near( $q['nexo'], 10.20 ), 'a Ernesto/NEXO 10,20 (sale ' . $q['nexo'] . ')' );

// 2) La misma tabla que da el bot (node: myPrice / gestoraShare de vivabot src/market-price.js, 28-oct).
$bot = array( array( 4, 9, 2 ), array( 10, 15, 2 ), array( 12.5, 18, 2.2 ), array( 30, 42, 4.8 ), array( 50, 67, 6.8 ), array( 59, 76, 6.8 ),
	array( 59.99, 77, 6.8 ), array( 60, 80, 8 ), array( 100, 120, 8 ), array( 250, 270, 8 ), array( 300, 320, 8 ), array( 301, 335, 13.6 ),
	array( 450, 495, 18 ), array( 1000, 1100, 40 ) );
foreach ( $bot as [ $cost, $price, $share ] ) {
	$s = CVD_Market_Bridge::suggested_price( (float) $cost );
	$c = CVD_Market_Bridge::gestora_share( $s, (float) $cost );
	check( near( $s, $price ) && near( $c, $share ), "bot: $cost → $price (comisión $share); Core da $s ($c)" );
	$f = CVD_Market_Bridge::quote( (float) $cost )['floor'];
	check( $f <= $s && $f > $cost, "mínimo de $cost ($f) entre el costo y el sugerido" );
}

// 3) Con la web en modo «pérdida» (por defecto) el mínimo del panel sigue siendo el completo de bajo pedido (D121).
$GLOBALS['opts']['cvd_cost_floor_mode'] = 'perdida';
check( near( CVD_Market_Bridge::quote( 50 )['floor'], 58.26 ), 'el mínimo del panel no depende del modo «pérdida»' );

// 4) Precio por debajo del mínimo → 422 y no se toca la web (ni se busca el producto).
$GLOBALS['sku_lookups'] = array();
$r = CVD_Market_Bridge::panel_publish( new WP_REST_Request( array( 'item_id' => 12, 'cost' => 50, 'price' => 58.25, 'title' => 'Ventilador', 'version' => 1 ) ) );
check( 422 === $r->status && false === $r->data['ok'] && near( $r->data['floor'], 58.26 ), 'precio 58,25 rechazado (mínimo 58,26)' );
check( false !== strpos( $r->data['error'], '58,26' ), 'el aviso dice el mínimo: ' . $r->data['error'] );
check( array() === $GLOBALS['sku_lookups'], 'con precio bajo no se busca ni se publica nada' );
check( 'no-store, private' === ( $r->headers['Cache-Control'] ?? '' ), 'respuesta del panel sin caché' );

// 5) Faltan datos → 400.
$r = CVD_Market_Bridge::panel_publish( new WP_REST_Request( array( 'item_id' => 12, 'cost' => 0, 'price' => 67 ) ) );
check( 400 === $r->status, 'sin precio del proveedor → 400' );

// 6) La comisión del panel la calcula Core (40 %): aunque el panel mande otra, no se usa.
$src = file_get_contents( $root . 'class-cvd-market-bridge.php' );
$panel = substr( $src, strpos( $src, 'public static function panel_publish' ) );
$panel = substr( $panel, 0, strpos( $panel, 'public static function panel_unpublish' ) );
check( false === strpos( $panel, "\$p['commission']" ), 'panel_publish no lee una comisión del panel' );
check( false !== strpos( $panel, 'self::gestora_share( $price, $cost )' ), 'panel_publish calcula la comisión del 40 %' );

// 7) Teléfonos fuera.
check( 'Nevera nueva, llama al' === CVD_Market_Bridge::strip_contacts( 'Nevera nueva, llama al +53 5 123 4567' ), 'quita el teléfono' );
check( 'Escribe a' === CVD_Market_Bridge::strip_contacts( 'Escribe a wa.me/5351234567' ), 'quita wa.me' );
check( 'Split 12000 BTU' === CVD_Market_Bridge::strip_contacts( 'Split 12000 BTU' ), 'no quita números cortos (12000 BTU)' );

// 8) La puerta del bot valida igual que antes (antes de buscar el producto).
$GLOBALS['sku_lookups'] = array();
$r = CVD_Market_Bridge::publish( new WP_REST_Request( array( 'item_id' => 5, 'title' => 'X', 'price' => 10, 'commission' => 0, 'version' => 1 ) ) );
check( 400 === $r->status && 'La comisión debe ser mayor que 0 y menor que el precio.' === $r->data['error'], 'bot: comisión 0 → 400 con el mismo texto' );
$r = CVD_Market_Bridge::publish( new WP_REST_Request( array( 'item_id' => 5, 'title' => '', 'price' => 10, 'commission' => 2, 'version' => 1 ) ) );
check( 400 === $r->status && 'Faltan item_id, title, price o version.' === $r->data['error'], 'bot: sin título → 400 con el mismo texto' );
$r = CVD_Market_Bridge::unpublish( new WP_REST_Request( array(), array( 'item' => 5 ) ) );
check( 400 === $r->status && 'Falta version.' === $r->data['error'], 'bot: retirar sin versión → 400' );
$r = CVD_Market_Bridge::unpublish( new WP_REST_Request( array( 'version' => 3 ), array( 'item' => 5 ) ) );
check( 200 === $r->status && false === $r->data['found'], 'bot: retirar algo que no está en la web → found false' );
check( array( 'MK-5' ) === $GLOBALS['sku_lookups'], 'se busca por SKU MK-<id>' );

echo $fail ? "\n$fail fallo(s)\n" : "\nTodo bien\n";
exit( $fail ? 1 : 0 );
