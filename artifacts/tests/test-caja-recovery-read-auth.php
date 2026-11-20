<?php
// Standalone contract test: no WordPress, database, network, or production secrets.
define( 'ABSPATH', __DIR__ );
$GLOBALS['options'] = array();
$GLOBALS['routes'] = array();
function get_option( $name, $default = '' ) { return $GLOBALS['options'][ $name ] ?? $default; }
function register_rest_route( $ns, $path, $args ) { $GLOBALS['routes'][ $path ] = $args; }
function wc_get_orders( $args ) { return array(); }
class WP_REST_Request {
    public function __construct( private string $method, private string $route, private array $headers = array() ) {}
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_header( $key ) { return $this->headers[ $key ] ?? ''; }
}
class WP_REST_Response {
    public array $headers = array();
    public function __construct( private array $data ) {}
    public function header( $key, $value ) { $this->headers[ $key ] = $value; }
    public function get_data() { return $this->data; }
}
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-caja-bridge.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-panel-bridge.php';
function check( $value, $message ) { if ( ! $value ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$path = '/casa-viva/v1/panel/caja/recovery-orders';
$key = str_repeat( 'a', 64 ); // Synthetic fixture only.
$legacy = str_repeat( 'b', 64 );
$req = static fn( $method, $route, $key ) => new WP_REST_Request( $method, $route, array( 'x_nexo_caja_read_key' => $key ) );
check( ! CVD_Caja_Bridge::recovery_authorized( $req( 'GET', $path, $key ) ), 'disabled by default' );
$GLOBALS['options']['cvd_caja_read_key_hash'] = hash( 'sha256', $key );
$GLOBALS['options']['cvd_panel_key_hash'] = hash( 'sha256', $legacy );
check( CVD_Caja_Bridge::recovery_authorized( $req( 'GET', $path, $key ) ), 'scoped valid key' );
foreach ( array( 'POST', 'DELETE', 'HEAD', 'OPTIONS' ) as $method ) {
    check( ! CVD_Caja_Bridge::recovery_authorized( $req( $method, $path, $key ) ), 'method denied' );
}
foreach ( array( '/panel/caja/web-orders', '/panel/caja/web-orders/42/charge', '/panel/caja/web-orders/42/change', '/panel/pay-accounts', '/panel/stock/adjust', '/panel/payouts' ) as $route ) {
    check( ! CVD_Caja_Bridge::recovery_authorized( $req( 'GET', '/casa-viva/v1' . $route, $key ) ), 'route denied' );
}
foreach ( array( '', $legacy, 'bad', str_repeat( 'a', 65 ) ) as $invalid ) {
    check( ! CVD_Caja_Bridge::recovery_authorized( $req( 'GET', $path, $invalid ) ), 'invalid credential denied' );
}
check( ! CVD_Panel_Bridge::authorized( new WP_REST_Request( 'POST', '/panel/payouts', array( 'x_nexo_panel_key' => $key ) ) ), 'no panel access with scoped key' );
check( CVD_Panel_Bridge::authorized( new WP_REST_Request( 'POST', '/panel/payouts', array( 'x_nexo_panel_key' => $legacy ) ) ), 'legacy authorization unchanged' );
CVD_Caja_Bridge::routes();
check( 'GET' === $GLOBALS['routes']['/panel/caja/recovery-orders']['methods'], 'registered GET only' );
check( array( 'CVD_Caja_Bridge', 'recovery_authorized' ) === $GLOBALS['routes']['/panel/caja/recovery-orders']['permission_callback'], 'scoped permission callback' );
foreach ( array( '/panel/caja/web-orders', '/panel/caja/web-orders/(?P<id>\\d+)/charge', '/panel/caja/web-orders/(?P<id>\\d+)/change', '/panel/caja/cash-collections' ) as $route ) {
    check( array( 'CVD_Panel_Bridge', 'authorized' ) === $GLOBALS['routes'][ $route ]['permission_callback'], 'existing routes unchanged' );
}
$response = CVD_Caja_Bridge::recovery_orders();
check( true === $response->get_data()['recoveryReadOnly'], 'read-only response marker' );
check( 'no-store, private' === $response->headers['Cache-Control'], 'private response' );
$GLOBALS['options']['cvd_caja_read_key_hash'] = '';
check( ! CVD_Caja_Bridge::recovery_authorized( $req( 'GET', $path, $key ) ), 'revocation' );
echo "PASS: scoped Caja read credential, route isolation, legacy auth, revocation\n";
