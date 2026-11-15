<?php
/** One controlled read-only catalog smoke, executed through WP-CLI stdin. */
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'casaviva.company' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Expected authorized Casa Viva WP-CLI environment.' ); }
if ( ! class_exists( 'CVD_Photo_AI' ) || ! method_exists( 'CVD_Photo_AI', 'vps_recorte' ) ) { throw new RuntimeException( 'Existing VPS recorte adapter unavailable; no installation attempted.' ); }

$source = null;
$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 100, 'orderby' => 'ID', 'order' => 'ASC' ) );
foreach ( $ids as $id ) {
	$p = wc_get_product( $id );
	if ( ! $p ) { continue; }
	foreach ( array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) as $aid ) {
		// Prefer real/source photographs and skip generated panel/AI attachments.
		if ( get_post_meta( $aid, '_cvd_hero_panel', true ) || get_post_meta( $aid, '_cvd_foto_ia', true ) ) { continue; }
		$file = get_attached_file( $aid );
		$size = $file && is_readable( $file ) ? @getimagesize( $file ) : false;
		if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || filesize( $file ) > 8 * MB_IN_BYTES ) { continue; }
		$source = array( 'product_id' => (int) $id, 'attachment_id' => (int) $aid, 'file' => $file, 'marked_real' => (bool) get_post_meta( $aid, '_cvd_foto_real', true ) );
		break 2;
	}
}
if ( ! $source ) { throw new RuntimeException( 'No readable published product source found; no generation attempted.' ); }
$bytes = file_get_contents( $source['file'] );
$start = microtime( true );
// This calls only the already-installed free /imagen/recorte adapter. No QA or fallback.
$out = CVD_Photo_AI::vps_recorte( $bytes );
if ( is_wp_error( $out ) ) { throw new RuntimeException( 'Free recorte failed: ' . $out->get_error_message() ); }
$png = (string) ( $out['png'] ?? '' );
$size = @getimagesizefromstring( $png );
if ( ! $size || 'image/png' !== $size['mime'] ) { throw new RuntimeException( 'Recorte did not return a valid PNG.' ); }
$output = tempnam( sys_get_temp_dir(), 'evo-001-recorte-' );
$original = tempnam( sys_get_temp_dir(), 'evo-001-source-' );
if ( false === $output || false === $original ) { throw new RuntimeException( 'Temporary evidence unavailable.' ); }
if ( false === file_put_contents( $output, $png ) || false === file_put_contents( $original, $bytes ) ) { @unlink( $output ); @unlink( $original ); throw new RuntimeException( 'Evidence write failed.' ); }
echo 'EVO001_REPORT ' . wp_json_encode( array( 'ok' => true, 'product_id' => $source['product_id'], 'attachment_id' => $source['attachment_id'], 'marked_real' => $source['marked_real'], 'input_sha256' => hash( 'sha256', $bytes ), 'output_sha256' => hash( 'sha256', $png ), 'width' => $size[0], 'height' => $size[1], 'bytes' => strlen( $png ), 'seconds' => round( microtime( true ) - $start, 2 ), 'fiable' => ! empty( $out['fiable'] ), 'motivos' => $out['motivos'] ?? array(), 'output' => $output, 'source' => $original, 'billable_endpoint_called' => false, 'catalog_changed' => false, 'visual_fidelity' => 'requires_review' ) ) . "\n";
