<?php
declare(strict_types=1);

$path = __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-panel-bridge.php';
$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "No se pudo leer class-cvd-panel-bridge.php\n");
    exit(2);
}
$start = strpos($src, 'public static function reconcile_catalog');
$end = strpos($src, '// ---------- 3.13.52:', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "No encontré el bloque reconcile_catalog 3.14.168\n");
    exit(3);
}
$block = substr($src, $start, $end - $start);

$required = [
    "wc_get_product_id_by_sku",
    "skuConflict",
    "repair_parent_variation_options",
    "set_name",
    "set_sku",
    "WC_Product_Variable::sync",
    "wc_delete_product_transients",
];
foreach ($required as $needle) {
    if (strpos($block, $needle) === false) {
        fwrite(STDERR, "Falta guard requerido en reconcile_catalog: {$needle}\n");
        exit(4);
    }
}

$forbidden = [
    "set_regular_price",
    "set_sale_price",
    "set_stock_quantity",
    "set_manage_stock",
    "set_status",
    "set_catalog_visibility",
    "_cvd_commission",
    "CVD_Payouts",
];
foreach ($forbidden as $needle) {
    if (strpos($block, $needle) !== false) {
        fwrite(STDERR, "Reconciliación no financiera toca campo prohibido: {$needle}\n");
        exit(5);
    }
}

echo "catalog reconcile 3.14.168: OK\n";
