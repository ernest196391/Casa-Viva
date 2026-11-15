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
function wp_safe_remote_request( ...$args ) { throw new Exception( 'Unexpected network' ); }
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
