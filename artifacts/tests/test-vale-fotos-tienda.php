<?php

/**
 * Pruebas de la foto de cada producto para la tienda (Core 3.14.37, Lennys 16 y 17-oct:
 * «Al dependiente hay que mandarle foto con el vale. Ayer se le mandó al cliente un cortinero que no era.»).
 *
 * CVD_Order_Lines::photo_url (la misma regla para el vale del mensajero y la tarjeta de /ventas/) y
 * CVD_Order_Lines::variation_text (el color/diseño del pie de la foto).
 *
 * Desde la raíz del repo:
 * php artifacts/tests/test-vale-fotos-tienda.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

class WC_Product {
	public function __construct( public int $id, public int $image = 0, public int $parent = 0 ) {}
	public function get_image_id() { return $this->image; }
	public function get_parent_id() { return $this->parent; }
	public function get_id() { return $this->id; }
}
class WC_Product_Variation extends WC_Product {
	public array $attrs = array();
}
class WC_Order_Item_Product {
	public function __construct( private string $n, private ?WC_Product $p, private int $q = 1, private float $t = 10.0 ) {}
	public function get_name() { return $this->n; }
	public function get_product() { return $this->p ?: false; }
	public function get_quantity() { return $this->q; }
	public function get_total() { return $this->t; }
}
class WC_Order {
	public function __construct( private array $items ) {}
	public function get_items( $type ) { return $this->items; }
	public function get_currency() { return 'USD'; }
}

$GLOBALS['products'] = array();
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
function wp_get_attachment_image_url( $id, $size ) { return $id ? "https://casaviva.company/fotos/{$id}-{$size}.jpg" : false; }
// Como WooCommerce con $flat = true e $include_names = true: «Color: Negro, Talla: L».
function wc_get_formatted_variation( $variation, $flat = false, $include_names = true, $skip = false ) {
	$out = array();
	foreach ( $variation->attrs as $label => $value ) { $out[] = $label . ': ' . $value; }
	return implode( ', ', $out );
}

require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-order-lines.php';

$fails = 0;
$runs = 0;
function check( string $name, $got, $want ): void {
	global $fails, $runs;
	$runs++;
	if ( $got !== $want ) {
		$fails++;
		echo "FALLA  {$name}\n  esperado: " . var_export( $want, true ) . "\n  obtenido: " . var_export( $got, true ) . "\n";
	}
}

// Producto simple con foto.
$simple = new WC_Product( 10, 501 );
check( 'producto simple: su foto (grande)', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Almohada', $simple ), 'large' ), 'https://casaviva.company/fotos/501-large.jpg' );
check( 'producto simple: tamaño pequeño para la tarjeta', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Almohada', $simple ), 'medium' ), 'https://casaviva.company/fotos/501-medium.jpg' );
check( 'producto simple: sin color', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Almohada', $simple ) ), '' );

// Cortinero con dos colores: cada diseño con su foto propia (la foto real de ese color, 3.14.25).
$GLOBALS['products'][20] = new WC_Product( 20, 600 );
$negro = new WC_Product_Variation( 21, 621, 20 );
$negro->attrs = array( 'Color' => 'Negro' );
$blanco = new WC_Product_Variation( 22, 0, 20 ); // sin foto propia → la del producto
$blanco->attrs = array( 'Color' => 'Blanco' );
check( 'variación con foto propia: la del color comprado', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Cortinero extensible', $negro ), 'large' ), 'https://casaviva.company/fotos/621-large.jpg' );
check( 'variación sin foto propia: la del producto', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Cortinero extensible', $blanco ), 'large' ), 'https://casaviva.company/fotos/600-large.jpg' );
check( 'color en el pie', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Cortinero extensible', $negro ) ), 'Color: Negro' );
check( 'el color ya está en el nombre: no se repite', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Cortinero extensible - Negro', $negro ) ), '' );
check( 'nombre con mayúsculas distintas: tampoco se repite', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Cortinero extensible - NEGRO', $negro ) ), '' );

$talla = new WC_Product_Variation( 23, 0, 20 );
$talla->attrs = array( 'Color' => 'Gris', 'Talla' => 'L' );
check( 'dos atributos', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Capa de moto', $talla ) ), 'Color: Gris · Talla: L' );
check( 'dos atributos, uno ya en el nombre', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Capa de moto - Gris', $talla ) ), 'Talla: L' );

// Sin foto en ningún sitio, padre borrado o producto borrado: '' y sin errores.
$GLOBALS['products'][30] = new WC_Product( 30, 0 );
$sin_foto = new WC_Product_Variation( 31, 0, 30 );
check( 'ni la variación ni el producto tienen foto', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Toalla', $sin_foto ) ), '' );
$huerfana = new WC_Product_Variation( 41, 0, 40 ); // el producto 40 ya no existe
check( 'producto padre borrado: sin error', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Toalla', $huerfana ) ), '' );
check( 'producto borrado: sin foto', CVD_Order_Lines::photo_url( new WC_Order_Item_Product( 'Toalla', null ) ), '' );
check( 'producto borrado: sin color', CVD_Order_Lines::variation_text( new WC_Order_Item_Product( 'Toalla', null ) ), '' );

// El precio de cada línea sigue igual (3.14.7): la foto no cambia textos, totales ni cantidades.
$order = new WC_Order( array( new WC_Order_Item_Product( 'Cortinero extensible', $negro, 2, 30.0 ) ) );
check( 'el texto de la línea no cambia', CVD_Order_Lines::texts( $order ), array( '2× Cortinero extensible — 15 USD c/u = 30 USD' ) );

echo $fails ? "\n{$fails} de {$runs} pruebas fallaron.\n" : "OK: {$runs} pruebas de la foto de cada producto para la tienda.\n";
exit( $fails ? 1 : 0 );
