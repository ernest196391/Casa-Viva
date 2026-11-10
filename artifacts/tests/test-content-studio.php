<?php
/** Executable contract tests: permissions, replay, stale edit, commercial integrity and sync photo ownership. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MB_IN_BYTES', 1048576 );
$products = array(); $options = array(); $user_meta = array(); $logged = false; $caps = array();
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_current_user_id() { return 7; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
function wp_kses_post( $v ) { return strip_tags( $v, '<p><b><strong><em><ul><li><br>' ); }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
function wc_price( $v ) { return '$' . $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_get_attachment_image_url( $id, $size ) { return $id ? 'https://example.test/photo.jpg' : false; }
function rest_ensure_response( $v ) { return $v; }
function get_comments( $args ) { return array(); }
function get_post_meta( $id, $k, $single ) { return ''; }
function clean_post_cache( $id ) {}
function do_action( ...$args ) {}
function add_option( $k, $v, $deprecated = '', $autoload = false ) { if ( isset( $GLOBALS['options'][$k] ) ) { return false; } $GLOBALS['options'][$k] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); }
function get_user_meta( $id, $k, $single ) { return $GLOBALS['user_meta'][$id][$k] ?? ''; }
// v2 (panel de Zayri): prioridad (más vendidos, nuevos) y filtros. Sin ventas ni fecha reciente en este contrato.
define( 'DAY_IN_SECONDS', 86400 );
function get_posts( $args ) { return array(); }
function get_post_time( $format, $gmt, $id ) { return time() - 100 * DAY_IN_SECONDS; }
function apply_filters( $tag, $value ) { return $value; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $k, $default = false ) { return $GLOBALS['options'][$k] ?? $default; }
function update_post_meta( $id, $k, $v ) { return true; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['user_meta'][$id][$k] = $v; }
class WP_Error { public function __construct( public $code, public $message, public $data = array() ) {} }
class WP_REST_Request {
 public function __construct( private array $data = array() ) {}
 public function get_param( $k ) { return $this->data[$k] ?? null; }
 public function get_file_params() { return array(); }
}
class WC_Product {
 public array $data = array( 'id' => 0, 'name' => 'Lámpara', 'description' => '<p>Metal, 30 cm.</p>', 'short_description' => '', 'status' => 'publish', 'price' => '25', 'sku' => 'BC-10', 'image_id' => 12, 'gallery_image_ids' => array(), 'stock_quantity' => 9, 'meta' => array() );
 public function __call( $method, $args ) {
  if ( str_starts_with( $method, 'get_' ) ) { return $this->data[substr( $method, 4 )] ?? ''; }
  if ( str_starts_with( $method, 'set_' ) ) { $this->data[substr( $method, 4 )] = $args[0]; return; }
  throw new Exception( $method );
 }
 public function get_price_html() { return '$' . $this->data['price']; }
 public function get_permalink() { return 'https://example.test/?p=' . $this->data['id']; }
 public function is_type( $type ) { return 'simple' === $type; }
 public function is_on_sale() { return false; }
 public function managing_stock() { return true; }
 public function get_children() { return array(); }
 public function delete_meta_data( $k ) { unset( $this->data['meta'][$k] ); }
 public function get_meta( $k ) { return $this->data['meta'][$k] ?? ''; }
 public function update_meta_data( $k, $v ) { $this->data['meta'][$k] = $v; }
 public function save() { if ( ! $this->data['id'] ) { $this->data['id'] = count( $GLOBALS['products'] ) + 1; } $GLOBALS['products'][$this->data['id']] = clone $this; return $this->data['id']; }
}
class WC_Product_Simple extends WC_Product { public function __construct() { $this->data['price'] = ''; $this->data['image_id'] = 0; $this->data['sku'] = ''; } }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][$id] ) ? clone $GLOBALS['products'][$id] : false; }
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-content-studio.php';
function check( $condition, $label ) { if ( ! $condition ) { throw new Exception( $label ); } echo "PASS $label\n"; }
function request( $data ) { return new WP_REST_Request( $data ); }
check( ! CVD_Content_Studio::allowed(), 'anonymous denied' );
$logged = true; $caps = array( 'read' ); check( ! CVD_Content_Studio::allowed(), 'ordinary subscriber denied' );
$caps[] = CVD_Content_Studio::CAP; check( CVD_Content_Studio::allowed(), 'dedicated content permission accepted' );
$p = new WC_Product(); $p->save();
$before = CVD_Content_Studio::products( request( array( 'id' => 1 ) ) );
$edit = array( 'id' => 1, 'version' => $before['version'], 'name' => 'Lámpara de metal', 'description' => '<p>Metal, 30 cm.</p><script>bad()</script>', 'short' => '30 cm', 'price' => '0', 'stock_quantity' => 0, 'sku' => 'hijack' );
$saved = CVD_Content_Studio::save( request( $edit ) );
check( is_array( $saved ) && 'Lámpara de metal' === $saved['name'], 'content saved to existing Woo product' );
$after = wc_get_product( 1 );
check( '25' === $after->get_price() && 9 === $after->get_stock_quantity() && 'BC-10' === $after->get_sku(), 'price stock and SKU unchanged despite injected fields' );
check( ! str_contains( $after->get_description(), '<script>' ), 'description sanitized' );
check( $after->get_meta( CVD_Content_Studio::LOCK ) === 7 && count( $after->get_meta( '_cvd_content_history' ) ) === 1, 'ownership and audit snapshot stored' );
$stale = CVD_Content_Studio::save( request( $edit ) ); check( $stale instanceof WP_Error && 'conflict' === $stale->code, 'stale edits rejected' );
$bad_publish = array( 'token' => '12345678-1234-1234-1234-123456789012', 'name' => 'Producto nuevo', 'description' => 'Datos confirmados', 'action' => 'publish' );
$error = CVD_Content_Studio::save( request( $bad_publish ) ); check( $error instanceof WP_Error && 'incomplete' === $error->code, 'new unpriced product cannot be published' );
$bad_publish['action'] = 'save'; $draft = CVD_Content_Studio::save( request( $bad_publish ) );
check( 'draft' === $draft['status'] && '1' === wc_get_product( $draft['id'] )->get_meta( '_cvd_content_local' ), 'new merchandise saved as local draft' );
$replay = CVD_Content_Studio::save( request( $bad_publish ) ); check( $replay['id'] === $draft['id'] && count( $products ) === 2, 'retry does not duplicate merchandise' );
$options['cvd_content_save_1'] = time();
$busy = CVD_Content_Studio::save( request( array_merge( $edit, array( 'version' => $saved['version'] ) ) ) );
check( $busy instanceof WP_Error && 'busy' === $busy->code, 'concurrent writes rejected' ); unset( $options['cvd_content_save_1'] );
$upload = CVD_Content_Studio::upload( request( array( 'id' => 1, 'version' => $before['version'] ) ) );
check( $upload instanceof WP_Error && 'conflict' === $upload->code, 'stale photo upload rejected' );
check( ! isset( $options['cvd_content_save_1'] ), 'photo lock released on validation error' );
// Execute the real synchronization function, without running the importing script.
$source = file_get_contents( __DIR__ . '/../../scripts/catalog/biznecubano-sync.php' );
$start = strpos( $source, 'function cvd_sync_photo(' ); $tokens = token_get_all( '<?php ' . substr( $source, $start ) );
$body = ''; $depth = 0; $began = false;
foreach ( array_slice( $tokens, 1 ) as $t ) { $s = is_array( $t ) ? $t[1] : $t; $body .= $s; if ( ! is_array( $t ) && '{' === $s ) { $depth++; $began = true; } if ( ! is_array( $t ) && '}' === $s ) { $depth--; if ( $began && 0 === $depth ) { break; } } }
eval( $body );
check( '' === cvd_sync_photo( wc_get_product( 1 ), 'https://new.example/photo.jpg', true ), 'BizneCubano preserves staff photo without network download' );
echo "Content studio contracts passed.\n";
