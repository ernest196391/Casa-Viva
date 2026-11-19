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
