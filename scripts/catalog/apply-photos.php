<?php
/**
 * Pone como foto principal de cada producto su versión ampliada (data/catalog/upscaled-photos.json).
 *
 * Uso (WP-CLI): CVD_PHOTOS_FILE=/tmp/photos.json CVD_PHOTOS_MODE=dry-run wp eval-file apply-photos.php
 *
 * - Solo fotos reales de BizneCubano ampliadas a 2K con Higgsfield; nada generado ni con texto.
 * - Guarda la foto anterior en `_cvd_prev_image_id` para poder volver atrás (modo revert).
 * - Idempotente: si la foto ya se aplicó (`_cvd_upscaled_from`), no la vuelve a descargar.
 * - No toca precios, stock, pedidos ni la galería.
 */

defined( 'ABSPATH' ) || exit( 1 );

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$file = getenv( 'CVD_PHOTOS_FILE' ) ?: '/tmp/cvd-photos.json';
$mode = in_array( getenv( 'CVD_PHOTOS_MODE' ), array( 'apply', 'revert' ), true ) ? getenv( 'CVD_PHOTOS_MODE' ) : 'dry-run';
$data = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
$report = array( 'mode' => $mode, 'done' => array(), 'skipped' => array(), 'errors' => array() );

foreach ( $data['photos'] ?? array() as $photo ) {
	$sku = (string) $photo['sku'];
	$id  = wc_get_product_id_by_sku( $sku );
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product instanceof WC_Product ) {
		$report['skipped'][] = array( 'sku' => $sku, 'reason' => 'no existe' );
		continue;
	}
	try {
		if ( 'revert' === $mode ) {
			$prev = (int) $product->get_meta( '_cvd_prev_image_id' );
			if ( ! $prev ) {
				$report['skipped'][] = array( 'sku' => $sku, 'reason' => 'sin foto anterior guardada' );
				continue;
			}
			$product->set_image_id( $prev );
			$product->delete_meta_data( '_cvd_prev_image_id' );
			$product->delete_meta_data( '_cvd_upscaled_from' );
			$product->save();
			$report['done'][] = array( 'sku' => $sku, 'image_id' => $prev );
			continue;
		}
		if ( $photo['url'] === (string) $product->get_meta( '_cvd_upscaled_from' ) ) {
			$report['skipped'][] = array( 'sku' => $sku, 'reason' => 'ya aplicada' );
			continue;
		}
		if ( 'dry-run' === $mode ) {
			$report['done'][] = array( 'sku' => $sku, 'name' => $product->get_name(), 'from' => $product->get_image_id() );
			continue;
		}
		$attachment = media_sideload_image( $photo['url'], $product->get_id(), $product->get_name(), 'id' );
		if ( is_wp_error( $attachment ) ) {
			throw new RuntimeException( $attachment->get_error_message() );
		}
		update_post_meta( (int) $attachment, '_wp_attachment_image_alt', $product->get_name() );
		if ( ! $product->get_meta( '_cvd_prev_image_id' ) ) {
			$product->update_meta_data( '_cvd_prev_image_id', (int) $product->get_image_id() );
		}
		$product->update_meta_data( '_cvd_upscaled_from', $photo['url'] );
		$product->set_image_id( (int) $attachment );
		$product->save();
		$report['done'][] = array( 'sku' => $sku, 'name' => $product->get_name(), 'image_id' => (int) $attachment );
	} catch ( Throwable $e ) {
		$report['errors'][] = array( 'sku' => $sku, 'error' => $e->getMessage() );
	}
}

$report['totals'] = array( 'done' => count( $report['done'] ), 'skipped' => count( $report['skipped'] ), 'errors' => count( $report['errors'] ) );
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
