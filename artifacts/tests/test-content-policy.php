<?php
/** EVO-001: credentials and request flags must never reach a provider. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	public function __construct( public $code, public $message, public $data ) {}
}
class WP_REST_Request {
	public function get_param( $key ) { return array( 'name' => 'Sartén', 'facts' => 'Metal', 'paid' => '1', 'provider' => 'openai' )[ $key ] ?? null; }
}
class CVD_Taller_Text { public const MAX_EXTRA = 1000; }
function sanitize_text_field( $v ) { return $v; }
function sanitize_textarea_field( $v ) { return $v; }
function wp_safe_remote_post( ...$args ) { throw new Exception( 'Unexpected network' ); }
function wp_safe_remote_request( $url, $args ) {
	if ( empty( $GLOBALS['allow_flux_fixture'] ) ) { throw new Exception( 'Unexpected network' ); }
	if ( $url !== 'https://api.cloudflare.com/client/v4/accounts/' . CVD_PHOTO_AI_ACCOUNT . '/ai/run/@cf/black-forest-labs/flux-2-klein-4b'
		|| 'POST' !== $args['method'] || 0 !== $args['redirection']
		|| 'Bearer configured-cloudflare-fixture' !== $args['headers']['Authorization']
		|| ! str_contains( $args['body'], '1024' ) ) { throw new Exception( 'Wrong restricted transport' ); }
	$GLOBALS['flux_calls']++;
	return array( 'body' => $GLOBALS['flux_fixture'] );
}
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_generate_uuid4() { return 'synthetic-boundary'; }
function add_option( $key, $value, $deprecated, $autoload ) {
	if ( isset( $GLOBALS['reservations'][$key] ) ) { return false; }
	$GLOBALS['reservations'][$key] = $value;
	return true;
}
define( 'MB_IN_BYTES', 1048576 );
define( 'CVD_PHOTO_AI_ACCOUNT', str_repeat( 'a', 32 ) );
define( 'CVD_PHOTO_AI_CLOUDFLARE', 'configured-cloudflare-fixture' );
define( 'CVD_PHOTO_AI_VPS', 'configured-test-only' );
define( 'CVD_PHOTO_AI_OPENAI', 'configured-test-only' );
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-content-policy.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-photo-ai.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-content-studio.php';
foreach ( array(
	CVD_Photo_AI::vps_image( 'test', array(), array() ),
	CVD_Photo_AI::generate( new WP_REST_Request() ),
	CVD_Photo_AI::poll( new WP_REST_Request() ),
	CVD_Content_Studio::suggest( new WP_REST_Request() ),
) as $r ) {
	if ( ! $r instanceof WP_Error || 'evo_budget_zero' !== $r->code || 403 !== $r->data['status'] ) { throw new Exception( 'Budget gate failed' ); }
}
echo "PASS EVO-001: four external entrypoints blocked before network, with credentials configured.\n";

$blocked = CVD_Photo_AI::cloudflare_free_pilot();
if ( ! $blocked instanceof WP_Error || 'evo_budget_zero' !== $blocked->code ) { throw new Exception( 'No CLI gate' ); }
define( 'WP_CLI', true );
$blocked = CVD_Photo_AI::cloudflare_free_pilot();
if ( ! $blocked instanceof WP_Error || 'evo_budget_zero' !== $blocked->code ) { throw new Exception( 'No attestation gate' ); }
$scenario = $argv[1] ?? 'valid';
$attestation = array( 'plan' => 'Workers Free', 'account_hash' => hash( 'sha256', CVD_PHOTO_AI_ACCOUNT ), 'checked_at' => time(), 'neurons_used' => 0, 'approved' => true );
if ( 'paid' === $scenario ) { $attestation['plan'] = 'Workers Paid'; }
if ( 'stale' === $scenario ) { $attestation['checked_at'] -= 7200; }
if ( 'other-account' === $scenario ) { $attestation['account_hash'] = str_repeat( '0', 64 ); }
if ( 'quota' === $scenario ) { $attestation['neurons_used'] = 9800; }
if ( 'unapproved' === $scenario ) { $attestation['approved'] = false; }
define( 'CVD_FLUX_FREE_PILOT_ATTESTATION', $attestation );
if ( 'valid' !== $scenario ) {
	$r = CVD_Photo_AI::cloudflare_free_pilot();
	if ( ! $r instanceof WP_Error || 403 !== $r->data['status'] || ! empty( $GLOBALS['reservations'] ) ) { throw new Exception( 'Unsafe attestation accepted' ); }
	echo "PASS rejected unsafe attestation before transport\n";
	exit;
}
$img = imagecreatetruecolor( 1024, 1024 );
ob_start(); imagepng( $img ); $GLOBALS['flux_fixture'] = ob_get_clean(); imagedestroy( $img );
$GLOBALS['allow_flux_fixture'] = true;
$GLOBALS['flux_calls'] = 0;
$result = CVD_Photo_AI::cloudflare_free_pilot();
if ( ! is_array( $result ) || 'preview_only' !== $result['mode'] || 250 !== $result['reserved_neurons'] || $GLOBALS['flux_calls'] !== 1 ) { throw new Exception( 'Preview failed' ); }
$again = CVD_Photo_AI::cloudflare_free_pilot();
if ( ! $again instanceof WP_Error || 429 !== $again->data['status'] || $GLOBALS['flux_calls'] !== 1 ) { throw new Exception( 'Duplicate transport' ); }
echo "PASS isolated simulated FLUX preview; duplicate blocked.\n";
