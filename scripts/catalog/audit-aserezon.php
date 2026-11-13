<?php
/** Read-only status for Casa Viva/Aserezon pilot; no customer data. */
defined('ABSPATH') || exit(1);
if (!function_exists('wc_get_product_id_by_sku')) {
    echo wp_json_encode(['status'=>'woocommerce_unavailable']) . PHP_EOL;
    exit(1);
}
$skus = [
 'AS-2101-3501090-01',
 'AS-2101-1106010-10',
 'AS-2101-1601130-01',
 'AS-2101-1011010-01',
];
$report = [
    'status'=>'ok',
    'currency'=>get_option('woocommerce_currency'),
    'products'=>[],
];
foreach ($skus as $sku) {
  $id = wc_get_product_id_by_sku($sku);
  $p = $id ? wc_get_product($id) : null;
  $report['products'][] = [
    'sku'=>$sku,
    'exists'=>(bool)$p,
    'status'=>$p ? $p->get_status() : null,
    'catalog_visibility'=>$p ? $p->get_catalog_visibility() : null,
    'stock_status'=>$p ? $p->get_stock_status() : null,
    'regular_price'=>$p ? $p->get_regular_price() : null,
    'image_count'=>$p ? (int) (bool) $p->get_image_id() + count($p->get_gallery_image_ids()) : 0,
  ];
}
echo wp_json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
