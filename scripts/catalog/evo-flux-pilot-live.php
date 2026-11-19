<?php
/** Explicit one-shot smoke. Never schedule or run on PR CI. */
defined( 'ABSPATH' ) || exit( 1 );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'casaviva.company' !== wp_parse_url( home_url(), PHP_URL_HOST )
	|| ! defined( 'CVD_FLUX_FREE_PILOT_ATTESTATION' ) || ! method_exists( 'CVD_Photo_AI', 'cloudflare_free_pilot' ) ) {
	throw new RuntimeException( 'Expected authorized Casa Viva CLI, installed candidate and fresh private attestation.' );
}
$result = CVD_Photo_AI::cloudflare_free_pilot();
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( 'Pilot did not complete; inspect reservation and provider usage. No retry.' );
}
$uri = $result['background'] ?? '';
if ( ! preg_match( '~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~D', $uri, $matches ) ) {
	throw new RuntimeException( 'Invalid preview; no retry.' );
}
$bytes = base64_decode( $matches[2], true );
if ( ! is_string( $bytes ) || ! hash_equals( $result['sha256'], hash( 'sha256', $bytes ) ) ) {
	throw new RuntimeException( 'Invalid preview checksum.' );
}
$path = tempnam( sys_get_temp_dir(), 'cvd-flux-' );
if ( false === $path || ! chmod( $path, 0600 ) || file_put_contents( $path, $bytes, LOCK_EX ) !== strlen( $bytes ) ) {
	throw new RuntimeException( 'Unable to retain private evidence. Do not retry inference.' );
}
echo wp_json_encode( array( 'mode' => 'preview_only', 'path' => $path, 'mime' => $matches[1],
	'sha256' => $result['sha256'], 'bytes' => strlen( $bytes ), 'reserved_neurons' => $result['reserved_neurons'],
	'human_review_required' => true, 'catalog_modified' => false ) ) . "\n";
