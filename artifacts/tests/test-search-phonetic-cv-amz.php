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
