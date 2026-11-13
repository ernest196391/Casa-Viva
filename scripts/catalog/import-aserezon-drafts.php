<?php
/**
 * Importación controlada del piloto Aserezon a borradores WooCommerce.
 * Ejecución en servidor (cuando haya permisos y aprobación):
 * CVD_ASEREZON_FILE=/ruta/aserezon-piloto-borrador.json CVD_ASEREZON_MODE=dry-run wp eval-file scripts/catalog/import-aserezon-drafts.php
 * CVD_ASEREZON_FILE=/ruta/aserezon-piloto-borrador.json CVD_ASEREZON_MODE=apply wp eval-file scripts/catalog/import-aserezon-drafts.php
 *
 * Modo apply SOLO crea productos inexistentes con status=draft, stock no gestionado,
 * visibilidad oculta y sin imagen. No edita SKU existentes ni publica nada.
 */
if (!defined('ABSPATH') || !function_exists('wc_get_product_id_by_sku')) {
    exit('WooCommerce y WordPress requeridos');
}
$path = getenv('CVD_ASEREZON_FILE');
$mode = getenv('CVD_ASEREZON_MODE') ?: 'dry-run';
if (!in_array($mode, ['dry-run', 'apply'], true)) {
    exit('Modo no permitido');
}
if (!$path || !is_readable($path)) {
    exit('Archivo de catálogo no legible');
}
$catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (($catalog['status'] ?? '') !== 'PREPARE - NO PUBLICAR' || ($catalog['supplier'] ?? '') !== 'Aserezon') {
    exit('Manifiesto inválido');
}
$report = ['mode' => $mode, 'created' => [], 'skipped' => [], 'errors' => []];
foreach ($catalog['items'] ?? [] as $item) {
    $sku = (string) ($item['sku'] ?? '');
    try {
        if (!preg_match('/^AS-[A-Za-z0-9-]+$/', $sku)) {
            throw new RuntimeException('SKU no permitido');
        }
        if (wc_get_product_id_by_sku($sku)) {
            $report['skipped'][] = ['sku' => $sku, 'reason' => 'Ya existe; no modificar'];
            continue;
        }
        if (($item['status'] ?? '') !== 'draft' || !empty($item['image_url'])) {
            throw new RuntimeException('Requiere borrador sin imagen');
        }
        $cost = (float) ($item['cost_usd'] ?? 0);
        $sale = (float) ($item['suggested_price_usd'] ?? 0);
        $fixed = (float) ($item['gestora_fixed_usd'] ?? 0);
        if ($cost <= 0 || $sale <= $cost + $fixed || $sale <= 0) {
            throw new RuntimeException('Precio no viable');
        }
        if ($mode === 'dry-run') {
            $report['skipped'][] = ['sku' => $sku, 'reason' => 'dry-run; se crearía borrador'];
            continue;
        }
        $product = new WC_Product_Simple();
        $product->set_name(sanitize_text_field((string) ($item['title'] ?? '')));
        $product->set_sku($sku);
        $product->set_status('draft');
        $product->set_catalog_visibility('hidden');
        $product->set_manage_stock(false);
        $product->set_stock_status('outofstock');
        $product->set_regular_price(wc_format_decimal($sale, 2));
        $product->set_description(wp_kses_post((string) ($item['seo_description'] ?? '')));
        $product->set_short_description('Consulta disponibilidad, precio final y compatibilidad antes de reservar.');
        $product->update_meta_data('_cvd_aserezon_source_url', esc_url_raw((string) ($item['source'] ?? '')));
        $product->update_meta_data('_cvd_aserezon_manufacturer_code', sanitize_text_field((string) ($item['manufacturer_code'] ?? '')));
        $product->update_meta_data('_cvd_aserezon_supplier_cost_usd', wc_format_decimal($cost, 2));
        $product->update_meta_data('_cvd_aserezon_gestora_proposed_usd', wc_format_decimal($fixed, 2));
        $product->update_meta_data('_cvd_aserezon_rights_status', 'pending');
        $product->update_meta_data('_cvd_aserezon_stock_verified', 'no');
        $product->update_meta_data('_cvd_aserezon_price_approved', 'no');
        $product->update_meta_data('_cvd_aserezon_pilot', 'yes');
        $product->save();
        $report['created'][] = ['sku' => $sku, 'id' => $product->get_id()];
    } catch (Throwable $e) {
        $report['errors'][] = ['sku' => $sku, 'error' => $e->getMessage()];
    }
}
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
