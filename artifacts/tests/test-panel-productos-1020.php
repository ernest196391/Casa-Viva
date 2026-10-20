<?php
// Pruebas de Core 3.14.41 (Lennys 20-oct): marca de cambios del catálogo para el bot y limpieza de una copia («Duplicar»).
// Sin WordPress: stubs. php artifacts/tests/test-panel-productos-1020.php

define( 'ABSPATH', __DIR__ );

class WC_Product {
	public array $meta = array();
	public ?bool $manage = null;
	public ?int $qty = null;
	public function __construct( public int $id, public string $type = 'simple' ) {}
	public function get_id(): int { return $this->id; }
	public function is_type( $t ): bool { return is_array( $t ) ? in_array( $this->type, $t, true ) : $this->type === $t; }
	public function delete_meta_data( $k ): void { unset( $this->meta[ $k ] ); }
	public function set_manage_stock( $v ): void { $this->manage = (bool) $v; }
	public function set_stock_quantity( $v ): void { $this->qty = (int) $v; }
}
$GLOBALS['cvt_types'] = array( 10 => 'product', 11 => 'product_variation', 12 => 'product_variation', 20 => 'product', 30 => 'shop_order' );
$GLOBALS['cvt_parents'] = array( 11 => 10, 12 => 10 );
function get_post_type( $id ) { return $GLOBALS['cvt_types'][ $id ] ?? false; }
function wp_get_post_parent_id( $id ) { return $GLOBALS['cvt_parents'][ $id ] ?? 0; }
function absint( $v ): int { return abs( (int) $v ); }

require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-catalog-version.php';
require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-panel-bridge.php';

function cvt_check( bool $c, string $label ): void { if ( ! $c ) { fwrite( STDERR, "FALLO: {$label}\n" ); exit( 1 ); } echo "OK: {$label}\n"; }

// --- Marca de cambios: el bot solo vuelve a leer lo que cambió ---
$c = CVD_Catalog_Version::merge( array(), array( 10, 20 ), 1000 );
cvt_check( array( array( 10, 1000 ), array( 20, 1000 ) ) == $c, 'merge: dos productos con la versión nueva' );
$c = CVD_Catalog_Version::merge( $c, array( 10 ), 2000 );
cvt_check( 2 === count( $c ) && array( 10, 2000 ) === $c[0], 'merge: un producto que vuelve a cambiar sale una sola vez, con su último cambio' );
$r = CVD_Catalog_Version::since( $c, 2000, 1000 );
cvt_check( array( 10 ) === $r['ids'] && ! $r['todo'], 'since: desde 1000 solo cambió el 10 (las sillas de Lennys)' );
$r = CVD_Catalog_Version::since( $c, 2000, 2000 );
cvt_check( array() === $r['ids'] && ! $r['todo'], 'since: al día, nada que leer' );
$r = CVD_Catalog_Version::since( $c, 2000, 0 );
cvt_check( $r['todo'], 'since: sin versión (bot recién arrancado) = recargar todo' );
$full = array();
for ( $i = 1; $i <= CVD_Catalog_Version::MAX + 5; $i++ ) { $full = CVD_Catalog_Version::merge( $full, array( $i ), 5000 + $i ); }
cvt_check( CVD_Catalog_Version::MAX === count( $full ), 'merge: guarda como máximo ' . CVD_Catalog_Version::MAX . ' cambios' );
cvt_check( CVD_Catalog_Version::since( $full, 5000 + CVD_Catalog_Version::MAX + 5, 5001 )['todo'], 'since: lo pedido es más viejo que la lista llena = recargar todo' );
$r = CVD_Catalog_Version::since( $full, 5000 + CVD_Catalog_Version::MAX + 5, 5000 + CVD_Catalog_Version::MAX + 3 );
cvt_check( ! $r['todo'] && array( CVD_Catalog_Version::MAX + 5, CVD_Catalog_Version::MAX + 4 ) === $r['ids'], 'since: con la lista llena, lo reciente sí se sabe' );

// touched: un diseño anota su producto; un pedido no anota nada.
$rp = new ReflectionProperty( 'CVD_Catalog_Version', 'pending' );
$rp->setAccessible( true );
CVD_Catalog_Version::touched( 11 );
CVD_Catalog_Version::touched( new WC_Product( 20 ) );
CVD_Catalog_Version::touched( 30 );
cvt_check( array( 10 => true, 20 => true ) === $rp->getValue(), 'touched: el diseño 11 anota su producto 10; el pedido 30 no cuenta' );

// --- «Duplicar»: la copia no hereda enlaces ni «retirado» y empieza con 0 unidades ---
$copy = new WC_Product( 99 );
$copy->meta = array( '_cvd_nexo_product_id' => 'abc', '_cvd_retired' => '{}', '_cvd_hold' => 'x', '_cvd_market_item_id' => '7', '_cvd_commission_value' => '2', '_cvd_cost_usd' => '4.5', '_cvd_ficha_datos' => 'Medidas: 40 cm' );
CVD_Panel_Bridge::clean_copy( $copy );
cvt_check( ! isset( $copy->meta['_cvd_nexo_product_id'] ) && ! isset( $copy->meta['_cvd_retired'] ) && ! isset( $copy->meta['_cvd_hold'] ) && ! isset( $copy->meta['_cvd_market_item_id'] ), 'copia: sin enlace a la caja, sin «retirado» ni mercado' );
cvt_check( '2' === $copy->meta['_cvd_commission_value'] && '4.5' === $copy->meta['_cvd_cost_usd'] && isset( $copy->meta['_cvd_ficha_datos'] ), 'copia: conserva comisión, costo y ficha' );
cvt_check( true === $copy->manage && 0 === $copy->qty, 'copia: empieza con 0 unidades (las pone la caja)' );
$parent = new WC_Product( 98, 'variable' );
CVD_Panel_Bridge::clean_copy( $parent );
cvt_check( null === $parent->manage && null === $parent->qty, 'copia con diseños: las unidades son de cada diseño, no del producto' );

echo "Todas las pruebas pasaron.\n";
