<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$make = static function ( string $name, string $sku, string $price, string $stock ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
	$product->set_regular_price( $price );
	$product->set_stock_status( $stock );
	$product->set_status( 'publish' );
	return $product->save();
};

update_option(
	'cvt_catalog_sync_fixture',
	array(
		'existing' => $make( 'Producto BC existente', 'BC-900001', '10', 'outofstock' ),
		'gone'     => $make( 'Producto BC retirado', 'BC-900099', '20', 'instock' ),
		'nexo'     => $make( 'Producto NEXO intocable', 'NEXO-SYNC-TEST', '99', 'instock' ),
	),
	false
);
echo "OK: fixture de sincronización de catálogo creado.\n";
