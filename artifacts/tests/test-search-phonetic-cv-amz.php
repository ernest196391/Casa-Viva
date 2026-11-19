<?php
// Synthetic search regression; no WordPress database or network required.
define( 'ABSPATH', __DIR__ );
function remove_accents( $s ) { return strtr( $s, array( 'é' => 'e', 'ó' => 'o', 'á' => 'a' ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function apply_filters( $hook, $value ) { return $value; }
function get_transient( $key ) { return $GLOBALS['fixture']; }
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-product-search.php';
function row( $id, $title, $stock = 1, $extra = array() ) {
    $words = array_map( array( 'CVD_Product_Search', 'stem' ), explode( ' ', CVD_Product_Search::normalize( $title ) ) );
    return array( 'id' => $id, 'words' => $words, 'first' => $words[0], 'extra' => $extra, 'stock' => $stock );
}
$GLOBALS['fixture'] = array(
    row( 1, 'Estación PS5 con ventilador' ),
    row( 2, 'Gabinete gaming con ventiladores' ),
    row( 3, 'Ventilador industrial' ),
    row( 4, 'Ventilador pequeño' ),
    row( 5, 'Ventilador Tornado' ),
    row( 6, 'Nevera Westing' ),
    row( 7, 'Aceite para nevera' ),
    row( 8, 'Sartén HotChef 20 cm' ),
    row( 9, 'Sartén HotChef 28 cm' ),
);
function check( $query, $expected ) {
    $actual = CVD_Product_Search::search( $query, 3 );
    if ( $actual !== $expected ) {
        throw new RuntimeException( $query . ': ' . json_encode( $actual ) . ' != ' . json_encode( $expected ) );
    }
    echo "OK: $query\n";
}
check( 'bentilador', array( 3, 4, 5 ) );
check( 'ventilador', array( 3, 4, 5 ) );
check( 'abanico', array( 3, 4, 5 ) );
check( 'nebera', array( 6, 7 ) );
check( 'sartén', array( 8, 9 ) );
check( 'sarten', array( 8, 9 ) );
check( 'HotChef 20', array( 8 ) );
check( 'xyzqwerty987', array() );

 // Session price stub: the same indexed product can have a different displayed price for a gestora.
class SearchBudgetProduct {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_status() { return 'publish'; }
    public function get_catalog_visibility() { return 'visible'; }
    public function get_price() { return $GLOBALS['prices'][$this->id] ?? ''; }
    public function get_stock_status() { return $GLOBALS['live_stock'][$this->id] ?? 'outofstock'; }
    public function is_in_stock() { return 'outofstock' !== $this->get_stock_status(); }
    public function is_purchasable() { return true; }
    public function get_id() { return $this->id; }
}
function wc_get_product( $id ) { return new SearchBudgetProduct( $id ); }
function wc_get_price_to_display( $product ) { return $product->get_price(); }
function wc_get_price_decimals() { return 2; }
function get_woocommerce_currency() { return $GLOBALS['currency']; }
$GLOBALS['currency'] = 'USD';
$GLOBALS['prices'] = array( 8 => 18, 9 => 24 );
check( 'sartén por menos de 20 dólares', array( 8 ) );
check( 'sarten hasta 18 USD', array( 8 ) );
check( 'sarten menos de 18 USD', array() );
check( 'sarten hasta 17 USD', array() );
check( 'HotChef 20', array( 8 ) );
check( 'sarten hasta 18,50 USD', array( 8 ) );
$GLOBALS['prices'][8] = 21; // Mirror session, same transient.
check( 'sarten hasta 20 USD', array() );
$GLOBALS['prices'][8] = 18; // Ordinary shopper, no cached mirror price.
check( 'sarten hasta 20 USD', array( 8 ) );
$GLOBALS['prices'][8] = ''; // Price unavailable: never assume zero.
check( 'sarten hasta 20 USD', array() );
$GLOBALS['currency'] = 'CUP';
check( 'sarten hasta 100 USD', array() );
if ( null !== CVD_Product_Search::usd_budget( 'HotChef 20 cm' ) || null !== CVD_Product_Search::usd_budget( 'sarten hasta 20000 CUP' ) ) {
    throw new RuntimeException( 'Model/other currency must not be interpreted as USD' );
}
echo "OK: budget syntax and currency boundary\n";

 // Compound intent: primary product before accessories, without inventing a size.
$GLOBALS['fixture'][] = row( 10, 'Nevera pequeña Milexus' );
$GLOBALS['fixture'][] = row( 11, 'Estación energética pequeña', 1, array( 'nevera' ) );
$GLOBALS['fixture'][] = row( 12, 'Aceite para nevera pequeña' );
check( 'nevera pequeña', array( 10 ) );
check( 'nebera pequeña', array( 10 ) );
check( 'aceite para nevera pequeña', array( 12 ) );
check( 'HotChef 20', array( 8 ) );
check( 'nevera imaginaria', array( 6, 10 ) );
$GLOBALS['currency'] = 'USD';
$GLOBALS['prices'] = array( 10 => 275, 11 => 100, 12 => 15, 6 => 310 );
check( 'nevera pequeña hasta 300 USD', array( 10 ) );
check( 'nevera pequeña hasta 200 USD', array() );

class CVD_Datos_A_Mano {
    public static function is_bajo_pedido( $product ) { return in_array( $product->get_id(), $GLOBALS['supplier_ids'], true ); }
}
$GLOBALS['fixture'][] = row( 13, 'Ventilador recargable para apagones', 0 );
$GLOBALS['fixture'][] = row( 14, 'Ventilador recargable Eco para apagones', 1 );
$GLOBALS['fixture'][] = row( 15, 'Ventilador recargable Solar para apagones', 1 );
$GLOBALS['live_stock'] = array( 13 => 'instock', 14 => 'outofstock', 15 => 'instock', 3 => 'onbackorder' );
$GLOBALS['supplier_ids'] = array( 15 );
check( 'ventilador recargable para apagones', array( 14, 15, 13 ) );
check( 'ventilador recargable disponible', array( 13 ) );
check( 'ventilador en tienda', array( 13 ) );
check( 'ventilador imaginario disponible', array() );
$GLOBALS['prices'][13] = 85;
check( 'ventilador disponible hasta 90 USD', array( 13 ) );
check( 'ventilador disponible hasta 80 USD', array() );
if ( CVD_Product_Search::availability_requested( 'ventilador no disponible' ) || CVD_Product_Search::availability_requested( 'ventilador sin stock' ) ) {
    throw new RuntimeException( 'Negative availability must not become a positive filter' );
}
echo "OK: negative availability\n";
