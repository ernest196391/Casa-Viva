<?php
/**
 * Attach original supplier photos to hidden Aserezon draft products only.
 * MODE=dry-run (default), MODE=apply. Never edits existing product images.
 */
defined('ABSPATH') || exit(1);
if (!function_exists('wc_get_product_id_by_sku')) {
    exit('WooCommerce unavailable');
}
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$mode = getenv('CVD_ASEREZON_MEDIA_MODE') ?: 'dry-run';
$file = getenv('CVD_ASEREZON_MEDIA_FILE');
if (!in_array($mode, ['dry-run', 'apply'], true) || !$file || !is_readable($file)) {
    exit('Invalid mode or media manifest');
}
$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if (($data['supplier'] ?? '') !== 'Aserezon' || count($data['photos'] ?? []) !== 4) {
    exit('Invalid Aserezon manifest');
}
$report = ['mode'=>$mode,'ready'=>[],'done'=>[],'skipped'=>[],'errors'=>[]];
foreach ($data['photos'] as $row) {
    $sku = (string) ($row['sku'] ?? '');
    try {
        $url=(string) ($row['url'] ?? '');
        $code=(string) ($row['manufacturer_code'] ?? '');
        if (!preg_match('/^AS-[A-Za-z0-9-]+$/', $sku) || !preg_match('/^[0-9-]+$/', $code)) {
            throw new RuntimeException('Invalid manufacturer code or SKU');
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', ['aserezon.com','www.aserezon.com'], true)
            || !str_contains(basename($parts['path'] ?? ''), $code)
            || !preg_match('/\\.(?:jpg|jpeg|png|webp)$/i', $parts['path'] ?? '')) {
            throw new RuntimeException('Untrusted image origin or file mismatch');
        }
        $id=wc_get_product_id_by_sku($sku);
        $product=$id ? wc_get_product($id) : null;
        if (!$product) {
            throw new RuntimeException('Product missing from WooCommerce');
        }
        if ($product->get_status() !== 'draft'
            || $product->get_catalog_visibility() !== 'hidden'
            || $product->get_meta('_cvd_aserezon_pilot') !== 'yes'
            || $product->get_stock_status() !== 'outofstock') {
            throw new RuntimeException('Product not an eligible hidden pilot draft');
        }
        if ($product->get_image_id()) {
            $report['skipped'][]=['sku'=>$sku,'reason'=>'Existing primary photo preserved'];
            continue;
        }
        if ($mode === 'dry-run') {
            $report['ready'][]=['sku'=>$sku,'url'=>$url];
            continue;
        }
        $attachment=media_sideload_image($url,$product->get_id(),$product->get_name(),'id');
        if (is_wp_error($attachment)) {
            throw new RuntimeException('Media download failed: '.$attachment->get_error_message());
        }
        $attachment=(int)$attachment;
        $metadata=wp_get_attachment_metadata($attachment);
        if ((int)($metadata['width'] ?? 0) < 500 || (int)($metadata['height'] ?? 0) < 500) {
            wp_delete_attachment($attachment,true);
            throw new RuntimeException('Downloaded image does not meet minimum 500px');
        }
        update_post_meta($attachment,'_wp_attachment_image_alt',$product->get_name().' — Foto real de Aserezon');
        update_post_meta($attachment,'_cvd_supplier_original_image',$url);
        $product->set_image_id($attachment);
        $product->update_meta_data('_cvd_aserezon_source_image_url',$url);
        $product->save();
        $report['done'][]=['sku'=>$sku,'attachment_id'=>$attachment,'width'=>$metadata['width'],'height'=>$metadata['height']];
    } catch (Throwable $e) {
        $report['errors'][]=['sku'=>$sku,'error'=>$e->getMessage()];
    }
}
echo wp_json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
