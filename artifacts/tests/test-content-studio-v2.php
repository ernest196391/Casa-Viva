<?php
/**
 * Panel v2 de Zayri, servidor (10-nov): escalones del HERO, publicación automática D152 con copia «antes» y restaurar,
 * WebP 1600 blanco, tope del día, una imagen a la vez, estados y motivos, prioridades, matriz y la bandeja de dudas
 * (5 rutas, 409 por versión, pedir_foto). El VPS se simula SOLO aquí, con los filtros cvd_content_recorte/cvd_content_motor.
 * Necesita PHP con GD. Uso: php artifacts/tests/test-content-studio-v2.php
 */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MB_IN_BYTES', 1048576 ); define( 'DAY_IN_SECONDS', 86400 );
if ( ! function_exists( 'imagecreatetruecolor' ) ) { fwrite( STDERR, "GD is required\n" ); exit( 1 ); }
$products = array(); $pmeta = array(); $atts = array(); $options = array(); $filters = array(); $user_meta = array(); $next_att = 1000;
$uploads = sys_get_temp_dir() . '/cvd-v2-' . bin2hex( random_bytes( 4 ) ); mkdir( $uploads, 0777, true );
$logged = true; $caps = array( 'cvd_edit_catalog_content' );

function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_current_user_id() { return 7; }
function wp_get_current_user() { return (object) array( 'ID' => 7, 'user_login' => 'zayri' ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function wp_kses_post( $v ) { return strip_tags( (string) $v, '<p><b><strong><em><ul><li><br><h3>' ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function esc_url_raw( $v ) { return filter_var( $v, FILTER_VALIDATE_URL ) ? $v : ''; }
function wc_price( $v ) { return '$' . $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function rest_ensure_response( $v ) { return $v; }
function get_comments( $args ) { return array(); }
function clean_post_cache( $id ) {}
function do_action( ...$args ) {}
function wp_cache_delete( ...$args ) {}
function wp_date( $f ) { return gmdate( $f ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_option( $k, $v, $deprecated = '', $autoload = false ) { if ( isset( $GLOBALS['options'][$k] ) ) { return false; } $GLOBALS['options'][$k] = $v; return true; }
function get_option( $k, $default = false ) { return $GLOBALS['options'][$k] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); return true; }
function get_user_meta( $id, $k, $single ) { return $GLOBALS['user_meta'][$id][$k] ?? ''; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['user_meta'][$id][$k] = $v; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][$tag][] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['filters'][$tag] ); }
function apply_filters( $tag, $value, ...$rest ) { foreach ( $GLOBALS['filters'][$tag] ?? array() as $cb ) { $value = $cb( $value, ...$rest ); } return $value; }
function get_post_meta( $id, $k = '', $single = false ) {
	if ( isset( $GLOBALS['products'][$id] ) ) { return '_thumbnail_id' === $k ? $GLOBALS['products'][$id]->data['image_id'] : ( $GLOBALS['products'][$id]->data['meta'][$k] ?? '' ); }
	return $GLOBALS['pmeta'][$id][$k] ?? '';
}
function update_post_meta( $id, $k, $v ) { if ( isset( $GLOBALS['products'][$id] ) ) { $GLOBALS['products'][$id]->data['meta'][$k] = $v; } else { $GLOBALS['pmeta'][$id][$k] = $v; } return true; }
function delete_post_meta( $id, $k ) { if ( isset( $GLOBALS['products'][$id] ) ) { unset( $GLOBALS['products'][$id]->data['meta'][$k] ); } else { unset( $GLOBALS['pmeta'][$id][$k] ); } return true; }
function get_post_time( $f, $gmt, $id ) { return $GLOBALS['products'][$id]->data['created'] ?? time() - 100 * DAY_IN_SECONDS; }
function get_post_status( $id ) { return $GLOBALS['products'][$id]->data['status'] ?? false; }
function get_posts( $args ) {
	$ids = array_keys( array_filter( $GLOBALS['products'], static fn( $p ) => 'publish' === $p->data['status'] ) );
	usort( $ids, static fn( $a, $b ) => (int) get_post_meta( $b, 'total_sales' ) <=> (int) get_post_meta( $a, 'total_sales' ) );
	return array_slice( $ids, 0, $args['posts_per_page'] );
}
class WP_Query { public $posts; public function __construct( $args ) { $this->posts = array_keys( $GLOBALS['products'] ); } }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/uploads', 'error' => false ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_generate_password( $n = 12, $special = true ) { return substr( bin2hex( random_bytes( $n ) ), 0, $n ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_get_image_mime( $f ) { $s = @getimagesize( $f ); return $s ? $s['mime'] : false; }
function new_att( $file, $mime, $parent ) { $id = ++$GLOBALS['next_att']; $GLOBALS['atts'][$id] = array( 'file' => $file, 'mime' => $mime, 'parent' => $parent ); return $id; }
function wp_upload_bits( $name, $dep, $bytes ) { $f = $GLOBALS['uploads'] . '/' . $name; file_put_contents( $f, $bytes ); return array( 'file' => $f, 'url' => 'https://example.test/uploads/' . $name, 'error' => false ); }
function wp_insert_attachment( $args, $file, $parent, $wp_error = false ) { return new_att( $file, $args['post_mime_type'], $parent ); }
function wp_generate_attachment_metadata( $id, $file ) { return array(); }
function wp_update_attachment_metadata( $id, $m ) { return true; }
function get_attached_file( $id ) { return $GLOBALS['atts'][$id]['file'] ?? false; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return isset( $GLOBALS['atts'][$id] ) ? 'https://example.test/uploads/' . basename( $GLOBALS['atts'][$id]['file'] ) : false; }
function media_handle_sideload( $file, $parent ) { $to = $GLOBALS['uploads'] . '/side-' . bin2hex( random_bytes( 3 ) ) . '-' . $file['name']; copy( $file['tmp_name'], $to ); return new_att( $to, wp_get_image_mime( $to ), $parent ); }
class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = array() ) {}
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function get_error_code() { return $this->code; }
}
class WP_REST_Request {
	public function __construct( private array $data = array(), private array $files = array(), private $json = null ) {}
	public function get_param( $k ) { return $this->data[$k] ?? null; }
	public function offsetGet( $k ) { return $this->data[$k] ?? null; }
	public function get_file_params() { return $this->files; }
	public function get_json_params() { return $this->json; }
	public function get_body_params() { return $this->data; }
}
class WC_Product {
	public array $data = array( 'id' => 0, 'name' => 'Lámpara', 'description' => '<p>Metal, 30 cm.</p>', 'short_description' => '', 'status' => 'publish', 'price' => '25', 'regular_price' => '25', 'sale_price' => '',
		'sku' => 'BC-10', 'slug' => 'lampara', 'image_id' => 0, 'gallery_image_ids' => array(), 'stock_quantity' => 9, 'stock_status' => 'instock', 'meta' => array() );
	public function __call( $method, $args ) {
		if ( str_starts_with( $method, 'get_' ) ) { return $this->data[substr( $method, 4 )] ?? ''; }
		if ( str_starts_with( $method, 'set_' ) ) { $this->data[substr( $method, 4 )] = $args[0]; return; }
		throw new Exception( $method );
	}
	public function get_permalink() { return 'https://example.test/producto/' . $this->data['slug']; }
	public function is_type( $type ) { return 'simple' === $type; }
	public function is_on_sale() { return false; }
	public function managing_stock() { return true; }
	public function get_children() { return array(); }
	public function delete_meta_data( $k ) { unset( $this->data['meta'][$k] ); }
	public function get_meta( $k ) { return $this->data['meta'][$k] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->data['meta'][$k] = $v; }
	public function save() { if ( ! $this->data['id'] ) { $this->data['id'] = count( $GLOBALS['products'] ) + 1; } $GLOBALS['products'][$this->data['id']] = clone $this; return $this->data['id']; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['products'][$id] ) ? clone $GLOBALS['products'][$id] : false; }
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-content-studio.php';
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-dudas-catalogo.php';
function check( $condition, $label ) { if ( ! $condition ) { throw new Exception( 'FAIL ' . $label ); } echo "PASS $label\n"; }
function req( array $data, array $files = array(), $json = null ) { return new WP_REST_Request( $data, $files, $json ); }
function code( $r ) { return $r instanceof WP_Error ? $r->code : 'ok'; }
function status( $r ) { return $r instanceof WP_Error ? (int) ( $r->data['status'] ?? 0 ) : 200; }

/* ---------- Images ---------- */
// A real photo: grey background, dark product. $w×$h, product box inside.
function photo( int $w, int $h, array $box, int $bg = 0xD8D8D8 ): string {
	$im = imagecreatetruecolor( $w, $h ); imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, $bg );
	imagefilledrectangle( $im, $box[0], $box[1], $box[2], $box[3], 0x203040 ); ob_start(); imagejpeg( $im, null, 92 ); return ob_get_clean();
}
// The free cut-out: transparent PNG cropped to the product.
function cutout( int $w, int $h ): string {
	$im = imagecreatetruecolor( $w, $h ); imagealphablending( $im, false ); imagesavealpha( $im, true );
	imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
	imagefilledrectangle( $im, 2, 2, $w - 3, $h - 3, imagecolorallocatealpha( $im, 30, 60, 90, 0 ) ); ob_start(); imagepng( $im ); return ob_get_clean();
}
function file_of( int $att ): string { return $GLOBALS['atts'][$att]['file']; }
function bbox_ratio( string $bytes ): array {
	$im = imagecreatefromstring( $bytes ); $W = imagesx( $im ); $H = imagesy( $im ); $x0 = $W; $y0 = $H; $x1 = -1; $y1 = -1;
	for ( $y = 0; $y < $H; $y += 2 ) { for ( $x = 0; $x < $W; $x += 2 ) { $c = imagecolorat( $im, $x, $y ); if ( ( ( $c >> 16 ) & 255 ) < 200 ) { $x0 = min( $x0, $x ); $x1 = max( $x1, $x ); $y0 = min( $y0, $y ); $y1 = max( $y1, $y ); } } }
	return array( 'w' => $W, 'h' => $H, 'ratio' => max( $x1 - $x0, $y1 - $y0 ) / max( $W, $H ), 'left' => $x0, 'right' => $W - 1 - $x1, 'top' => $y0, 'bottom' => $H - 1 - $y1 );
}

/* ---------- The VPS, simulated only here ---------- */
$vps = array( 'recorte' => 'fiable', 'ia' => 'ok', 'recortes' => 0, 'ias' => 0, 'opts' => null, 'refs' => 0, 'prompt' => '' );
$recorte_fn = static function ( string $bytes ) {
	$GLOBALS['vps']['recortes']++;
	$m = $GLOBALS['vps']['recorte'];
	if ( 'error' === $m ) { return new WP_Error( 'cvd_photo_ai', 'El servidor de imágenes no respondió a tiempo.', array( 'status' => 502 ) ); }
	if ( 'ocupado' === $m ) { return new WP_Error( 'cvd_photo_ai', 'Otro recorte en marcha.', array( 'status' => 429 ) ); }
	return array( 'png' => cutout( 900, 500 ), 'fiable' => 'fiable' === $m, 'motivos' => 'fiable' === $m ? array() : array( 'Borró las barras finas' ), 'medidas' => array() );
};
$motor_fn = static function ( string $prompt, array $opts, array $refs ) {
	$GLOBALS['vps']['ias']++; $GLOBALS['vps']['opts'] = $opts; $GLOBALS['vps']['refs'] = count( $refs ); $GLOBALS['vps']['prompt'] = $prompt;
	$m = $GLOBALS['vps']['ia'];
	if ( 429 === $m ) { return new WP_Error( 'cvd_photo_ai', 'Tope del día del servidor de imágenes.', array( 'status' => 429 ) ); }
	if ( 502 === $m ) { return new WP_Error( 'cvd_photo_ai', 'El servidor de imágenes no respondió a tiempo. Espera un minuto antes de repetir.', array( 'status' => 502 ) ); }
	return '1536x1024' === $opts['tam'] ? photo( 1536, 1024, array( 300, 250, 1200, 800 ), 0xF8F8F8 ) : photo( 1024, 1536, array( 250, 300, 800, 1200 ), 0xF8F8F8 );
};
function vps_on( bool $recorte = true, bool $ia = true ): void {
	remove_all_filters( 'cvd_content_recorte' ); remove_all_filters( 'cvd_content_motor' );
	if ( $recorte ) { add_filter( 'cvd_content_recorte', static fn() => $GLOBALS['recorte_fn'] ); }
	if ( $ia ) { add_filter( 'cvd_content_motor', static fn() => $GLOBALS['motor_fn'] ); }
}
function hoy( float $usd ): void { update_option( 'cvd_content_gasto', array( 'dia' => gmdate( 'Y-m-d' ), 'usd' => $usd ) ); }

/* ---------- Catalog ---------- */
$real = $uploads . '/real.jpg'; file_put_contents( $real, photo( 1200, 800, array( 200, 250, 1000, 600 ) ) );
$real_att = new_att( $real, 'image/jpeg', 1 );
$a = new WC_Product(); $a->data['image_id'] = $real_att; $a->data['meta'] = array( '_cvd_commission_type' => 'percent', '_cvd_commission_value' => '10' ); $a->save(); // id 1
$commerce = static fn( $p ) => array( $p->get_price(), $p->get_regular_price(), $p->get_sale_price(), $p->get_stock_quantity(), $p->get_stock_status(), $p->get_sku(), $p->get_slug(), $p->get_status(), $p->get_meta( '_cvd_commission_type' ), $p->get_meta( '_cvd_commission_value' ) );
$before_commerce = $commerce( wc_get_product( 1 ) );

check( false === CVD_Dudas_Catalogo::activa(), 'inbox is off with no cards and no switch' );
check( array( false, false ) === array( CVD_Content_Studio::motor()['recorte'], CVD_Content_Studio::motor()['ia'] ), 'production has no cut-out or engine without the VPS key (no test callbacks)' );
foreach ( array( 'class-cvd-content-studio.php', 'class-cvd-dudas-catalogo.php' ) as $f ) {
	$src = file_get_contents( __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/' . $f );
	check( ! preg_match( "/add_filter\\(\\s*'cvd_content_(motor|recorte)'/", $src ), "no simulated engine in production code ($f)" );
}
$r = CVD_Content_Studio::crear( req( array( 'id' => 1, 'pieza' => 'hero' ) ) );
check( 'motor' === code( $r ) && 'recorte' === $r->data['escalones'][0]['escalon'] && false === $r->data['escalones'][0]['ok'], 'without VPS: clear error and the ladder shows the free step was not available' );

/* 1. Free cut-out, fiable → white HERO on the web, no cost, «antes» kept. */
vps_on();
$r = CVD_Content_Studio::crear( req( array( 'id' => 1, 'pieza' => 'hero' ) ) );
check( 'ok' === code( $r ) && 'recorte' === $r['escalon'] && 0.0 === $r['coste'] && 1 === $GLOBALS['vps']['recortes'] && 0 === $GLOBALS['vps']['ias'], 'cut-out fiable: HERO made for free, IA not called' );
check( true === $r['publicada'] && 0.0 === CVD_Content_Studio::gasto()['hoy'], 'D152: published automatically, nothing spent today' );
$p = wc_get_product( 1 ); $hero = (int) $p->get_image_id();
check( $hero !== $real_att && 1 === (int) get_post_meta( $hero, CVD_Content_Studio::HERO_ADJUNTO ) && in_array( $real_att, $p->get_gallery_image_ids(), true ), 'HERO is the main photo; the real photo stays in the gallery' );
check( $real_att === (int) $p->get_meta( CVD_Content_Studio::HERO_ANTES )['image'] && $hero === (int) $p->get_meta( CVD_Content_Studio::HERO_ANTES )['att'], 'copy «antes» keeps the previous main photo' );
check( $before_commerce === $commerce( $p ), 'price, stock, SKU, URL and commission untouched by the HERO' );
$size = getimagesize( file_of( $hero ) );
check( 1600 === $size[0] && 1600 === $size[1] && ( ! function_exists( 'imagewebp' ) || 'image/webp' === $size['mime'] ), 'web HERO is ' . $size['mime'] . ' 1600×1600' );
$im = imagecreatefromstring( file_get_contents( file_of( $hero ) ) );
$white = static fn( $c ) => ( ( $c >> 16 ) & 255 ) >= 250 && ( ( $c >> 8 ) & 255 ) >= 250 && ( $c & 255 ) >= 250;
check( $white( imagecolorat( $im, 0, 0 ) ) && $white( imagecolorat( $im, 1599, 1599 ) ) && $white( imagecolorat( $im, 800, 40 ) ), 'web HERO on pure white' );
$box = bbox_ratio( file_get_contents( file_of( $hero ) ) );
check( abs( $box['ratio'] - 0.8 ) < 0.03 && abs( $box['left'] - $box['right'] ) <= 4 && abs( $box['top'] - $box['bottom'] ) <= 4, 'product ~80 % of the side, centered with equal margins (' . round( $box['ratio'], 3 ) . ')' );
$item = CVD_Content_Studio::products( req( array( 'id' => 1 ) ) );
check( 'falta' === $item['piezas']['hero']['estado'] && $item['piezas']['hero']['auto'] && $hero === $item['piezas']['hero']['web'] && $item['hero_en_web'] && $item['hero_revisar'] && ! $item['falta_hero'] && $item['restaurable'], 'list: HERO on the web, published alone, to review, restorable' );
check( 'recorte' === $item['piezas']['hero']['escalon'] && 1 === $item['piezas']['hero']['sin_revisar'] && 1 === count( $item['opciones'] ) && ! isset( $item['opciones'][0]['file'] ), 'option kept on the product without its private path' );

/* 2. Restaurar in one touch. */
$r = CVD_Content_Studio::restaurar( req( array( 'id' => 1 ) ) );
$p = wc_get_product( 1 );
check( 'ok' === code( $r ) && $r['restaurada'] && $real_att === (int) $p->get_image_id() && array() === $p->get_gallery_image_ids() && '' === $p->get_meta( CVD_Content_Studio::HERO_ANTES ), 'restaurar puts back the previous main photo and gallery' );
check( 0 === $r['piezas']['hero']['web'] && ! $r['restaurable'] && $before_commerce === $commerce( $p ), 'after restoring: no HERO on the web, commerce untouched' );
check( 404 === status( CVD_Content_Studio::restaurar( req( array( 'id' => 1 ) ) ) ), 'nothing to restore twice' );

/* 3. Cut-out not fiable → paid IA, wide product → 1536×1024, cost counted. */
$GLOBALS['vps']['recorte'] = 'nofiable';
$r = CVD_Content_Studio::crear( req( array( 'id' => 1, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 'ok' === code( $r ) && 'ia' === $r['escalon'] && 0.15 === $r['coste'] && 'Borró las barras finas' === $r['escalones'][0]['motivo'] && $r['escalones'][1]['ok'], 'cut-out not fiable → IA, and the ladder says why the free step failed' );
check( array( 'calidad' => 'media', 'tam' => '1536x1024', 'modelo' => 'gpt-image-1.5', 'fidelidad' => 'alta' ) === $GLOBALS['vps']['opts'] && 1 === $GLOBALS['vps']['refs'], 'IA: gpt-image-1.5, media, fidelidad alta, wide → 1536x1024, real photo as base' );
check( 0.15 === CVD_Content_Studio::gasto()['hoy'] && $r['publicada'], 'today spend is 0,15 USD and the IA HERO went to the web' );
check( $before_commerce === $commerce( wc_get_product( 1 ) ), 'commerce untouched after the IA HERO' );

/* 4. No vps_recorte → straight to IA. Tall photo → 1024x1536. */
$b = new WC_Product(); $b->data['name'] = 'Puerta'; $b->data['sku'] = 'BC-11'; $b->data['slug'] = 'puerta';
$tall = $uploads . '/tall.jpg'; file_put_contents( $tall, photo( 600, 1000, array( 150, 100, 450, 900 ) ) ); $b->data['image_id'] = new_att( $tall, 'image/jpeg', 2 ); $b->save(); // id 2
vps_on( false, true );
$r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) );
check( 'ok' === code( $r ) && 'ia' === $r['escalon'] && 'No disponible' === $r['escalones'][0]['motivo'] && '1024x1536' === $GLOBALS['vps']['opts']['tam'], 'without vps_recorte the ladder goes to IA; tall product → 1024x1536' );
check( 'sin_recorte' === code( CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'escalon' => 'recorte' ) ) ) ), 'asking only for the free cut-out without it is a clear error' );

/* 5. 429 / 502 from the engine keep their message; nothing is charged. */
vps_on( true, true ); $GLOBALS['vps']['recorte'] = 'nofiable'; $spent = CVD_Content_Studio::gasto()['hoy'];
$GLOBALS['vps']['ia'] = 429; $r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) );
check( 429 === status( $r ) && str_contains( $r->message, 'Tope del día' ) && 2 === count( $r->data['escalones'] ), 'IA 429: same status and message, ladder included' );
$GLOBALS['vps']['ia'] = 502; $r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) );
check( 502 === status( $r ) && str_contains( $r->message, 'no respondió' ) && $spent === CVD_Content_Studio::gasto()['hoy'], 'IA 502: message kept and nothing charged' );
$GLOBALS['vps']['recorte'] = 'ocupado'; $ias = $GLOBALS['vps']['ias'];
$r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) );
check( 429 === status( $r ) && 'ocupado' === $r->code && $ias === $GLOBALS['vps']['ias'], 'free cut-out busy: wait, never pay instead' );
$GLOBALS['vps']['ia'] = 'ok';

/* 6. Daily cap 0,50 USD blocks only what is paid. */
hoy( 0.45 );
$GLOBALS['vps']['recorte'] = 'fiable'; $r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 'ok' === code( $r ) && 'recorte' === $r['escalon'] && $r['gasto']['lleno'], 'cap reached: the free cut-out still works' );
$GLOBALS['vps']['recorte'] = 'nofiable'; $r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 429 === status( $r ) && 'tope' === $r->code && str_contains( $r->message, '0,50 USD' ), 'cap reached: paid IA blocked with a clear message' );
check( 429 === status( CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'escalon' => 'ia', 'forzar' => '1' ) ) ) ), 'cap reached: asking IA directly is blocked too' );
hoy( 0.0 ); $GLOBALS['vps']['recorte'] = 'fiable';

/* 7. One image at a time. */
add_option( 'cvd_content_imagen', time() );
$r = CVD_Content_Studio::crear( req( array( 'id' => 1, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 429 === status( $r ) && 'ocupado' === $r->code && str_contains( $r->message, 'Otra foto en marcha' ), 'one image at a time' );
delete_option( 'cvd_content_imagen' );

/* 8. At most 3 options per piece. */
check( 'ok' === code( CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) ) ), 'third HERO option for the same product' );
$n = count( array_filter( CVD_Content_Studio::opciones( 2 ), static fn( $o ) => 'hero' === $o['pieza'] ) );
check( 3 === $n && 409 === status( CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero' ) ) ) ), 'the 4th option asks for confirmation (409)' );
$r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 'ok' === code( $r ) && 3 === count( array_filter( CVD_Content_Studio::opciones( 2 ), static fn( $o ) => 'hero' === $o['pieza'] ) ), 'forced: still 3 kept, the oldest unused one goes' );

/* 9. States and reasons. */
$web = CVD_Content_Studio::piezas( 2 )['hero'];
$r = CVD_Content_Studio::pieza( req( array( 'id' => 2, 'pieza' => 'hero', 'estado' => 'rechazada', 'opcion' => $web['opcion'], 'motivos' => array( 'Cortado', 'Inventado' ) ) ) );
check( 'ok' === code( $r ) && $r['restaurada'] && 'rechazada' === $r['piezas']['hero']['estado'] && array( 'Cortado' ) === $r['piezas']['hero']['motivos'], 'rejecting the HERO on the web restores the previous photo and keeps valid reasons only' );
$out = CVD_Dudas_Catalogo::bot_respuestas( req( array( 'desde' => 0 ) ) );
check( 'preferencias' === end( $out['respuestas'] )['destino'] && array( 'Cortado' ) === end( $out['respuestas'] )['motivos'], 'the reasons go to the VPS for PREFERENCIAS' );
$ops = CVD_Content_Studio::opciones( 2 ); $last = end( $ops );
$r = CVD_Content_Studio::pieza( req( array( 'id' => 2, 'pieza' => 'hero', 'estado' => 'aprobada', 'opcion' => $last['id'], 'web' => '1' ) ) );
check( 'ok' === code( $r ) && $r['publicada'] && 'aprobada' === $r['piezas']['hero']['estado'] && ! $r['piezas']['hero']['auto'] && $r['hero_en_web'] && ! $r['hero_revisar'], 'approve + web: HERO approved and on the web' );
$r = CVD_Content_Studio::crear( req( array( 'id' => 2, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 'ok' === code( $r ) && ! $r['publicada'] && (int) wc_get_product( 2 )->get_image_id() === CVD_Content_Studio::piezas( 2 )['hero']['web'], 'a new option never replaces an approved HERO by itself' );
$r = CVD_Content_Studio::pieza( req( array( 'id' => 2, 'pieza' => 'hero', 'estado' => 'revision', 'opcion' => $r['opcion']['id'] ) ) );
check( 'ok' === code( $r ) && preg_match( '/^D\d+$/', $r['duda'] ) && true === CVD_Dudas_Catalogo::activa(), 'revision creates a card in the inbox and turns it on' );
check( 'aprobada' === CVD_Content_Studio::piezas( 2 )['hero']['estado'] || 'revision' === CVD_Content_Studio::piezas( 2 )['hero']['estado'], 'piece state after revision is stored' );
add_filter( 'cvd_content_hero_auto', '__return_false_test' ); function __return_false_test() { return false; }
$r = CVD_Content_Studio::crear( req( array( 'id' => 1, 'pieza' => 'hero', 'forzar' => '1' ) ) );
check( 'ok' === code( $r ) && ! $r['publicada'], 'cvd_content_hero_auto=false: nothing goes to the web by itself' );
remove_all_filters( 'cvd_content_hero_auto' );
check( 404 === status( CVD_Content_Studio::pieza( req( array( 'id' => 1, 'pieza' => 'hero', 'estado' => 'aprobada', 'opcion' => 'nope' ) ) ) ), 'unknown option → 404' );

/* 10. Priorities: nuevo, top (real sales, minimum 3) and anuncios. */
$c = new WC_Product(); $c->data['name'] = 'Sartén'; $c->data['meta']['total_sales'] = 12; $c->save();          // 3: top
$d = new WC_Product(); $d->data['name'] = 'Cortinero'; $d->data['meta']['total_sales'] = 2; $d->save();       // 4: below minimum
$e = new WC_Product(); $e->data['name'] = 'Nuevo'; $e->data['created'] = time() - 5 * DAY_IN_SECONDS; $e->save(); // 5: new
CVD_Content_Studio::products( req( array() ) ); // a list request recomputes the best sellers
check( array( 'top' ) === CVD_Content_Studio::prioridades( 3 ) && array() === CVD_Content_Studio::prioridades( 4 ) && array( 'nuevo' ) === CVD_Content_Studio::prioridades( 5 ), 'top needs real sales (≥ 3); nuevo is < 30 days' );
check( 422 === status( CVD_Content_Studio::pieza( req( array( 'id' => 4, 'pieza' => 'life', 'estado' => 'aprobada', 'opcion' => 'x' ) ) ) ), 'LIFESTYLE/BENEFICIO only with priority' );
$r = CVD_Content_Studio::prioridad( req( array( 'id' => 4, 'anuncios' => '1' ) ) );
check( array( 'anuncios' ) === $r['prioridad'] && 2 === $r['faltan_piezas'], '«Va a anuncios» gives the 3 pieces' );
check( array() === CVD_Content_Studio::prioridad( req( array( 'id' => 4, 'anuncios' => '0' ) ) )['prioridad'], '«Va a anuncios» can be removed' );

/* 11. Matrix, kept in the brief without losing the rest; LIFESTYLE needs it. */
$s = wc_get_product( 3 ); $s->update_meta_data( '_cvd_content_brief', array( 'facts' => 'Antiadherente, 28 cm', 'buyer' => 'Madre que cocina a diario' ) ); $s->save();
check( 'matriz' === code( CVD_Content_Studio::crear( req( array( 'id' => 3, 'pieza' => 'life' ) ) ) ), 'LIFESTYLE waits for the confirmed matrix' );
$v = CVD_Content_Studio::products( req( array( 'id' => 3 ) ) )['version'];
check( 409 === status( CVD_Content_Studio::matriz( req( array( 'id' => 3, 'version' => 'vieja', 'duda' => 'x', 'resultado' => 'y', 'prueba' => 'z' ) ) ) ), 'matrix with an old version → 409' );
$r = CVD_Content_Studio::matriz( req( array( 'id' => 3, 'version' => $v, 'duda' => '¿Se pega?', 'resultado' => 'Huevo sin aceite', 'prueba' => 'Antiadherente', 'condicion' => 'Fuego medio' ) ) );
check( 'Antiadherente, 28 cm' === $r['brief']['facts'] && 'Huevo sin aceite' === $r['brief']['resultado'] && 'Ficha · características' === $r['brief']['fuente'] && ! empty( $r['brief']['matriz_ok'] ), 'matrix stored in the brief, the rest kept, confirmed' );
$r2 = CVD_Content_Studio::matriz( req( array( 'id' => 3, 'version' => $r['version'], 'duda' => '¿Se pega?', 'resultado' => 'Huevo sin aceite', 'prueba' => 'Antiadherente', 'confirmada' => '0' ) ) );
check( empty( $r2['brief']['matriz_ok'] ), 'matrix can be saved without confirming' );
CVD_Content_Studio::matriz( req( array( 'id' => 3, 'version' => $r2['version'], 'duda' => '¿Se pega?', 'resultado' => 'Huevo sin aceite', 'prueba' => 'Antiadherente' ) ) );
$s = wc_get_product( 3 ); $pan = $uploads . '/pan.jpg'; file_put_contents( $pan, photo( 1000, 700, array( 100, 200, 900, 500 ) ) ); $s->set_image_id( new_att( $pan, 'image/jpeg', 3 ) ); $s->save();
$r = CVD_Content_Studio::crear( req( array( 'id' => 3, 'pieza' => 'life' ) ) );
check( 'ok' === code( $r ) && 'life' === $r['opcion']['pieza'] && 'ia' === $r['escalon'] && ! $r['publicada'] && str_contains( $GLOBALS['vps']['prompt'], 'Huevo sin aceite' ) && str_contains( $GLOBALS['vps']['prompt'], 'person' ), 'LIFESTYLE: scene with a person from the matrix, kept as option, never on the web' );

check( array() === CVD_Content_Studio::products( req( array( 'id' => 3 ) ) )['opciones'][0]['cambio'], 'a saved option of an unchanged ficha is current' );
$s = wc_get_product( 3 ); $s->set_name( 'Sartén HotChef 28 cm' ); $s->save();
$life = CVD_Content_Studio::products( req( array( 'id' => 3 ) ) )['opciones'][0];
check( array( 'texto' ) === $life['cambio'] && ! isset( $life['texto_v'] ), '«opción vieja»: the option says the text changed since it was made' );

/* 12. List with filters and counts. */
$l = CVD_Content_Studio::products( req( array( 'filtro' => 'hero' ) ) );
check( isset( $l['counts']['nohero'], $l['counts']['piezas'], $l['counts']['draft'], $l['counts']['revisar'] ) && count( $l['items'] ) === $l['counts']['nohero'] && 'nohero' === $l['filtro'], 'filtro=hero lists products without HERO with the count' );
check( 'Falta HERO' === CVD_Content_Studio::products( req( array( 'id' => 4 ) ) )['falta_fotos'] && 'Faltan piezas de anuncio · 2' === ( static function () { CVD_Content_Studio::prioridad( req( array( 'id' => 2, 'anuncios' => '1' ) ) ); return CVD_Content_Studio::products( req( array( 'id' => 2 ) ) )['falta_fotos']; } )(), 'falta_fotos: «Falta HERO» / «Faltan piezas de anuncio · N»' );

/* 13. Inbox: the VPS creates cards of the 5 types. */
$pngb64 = base64_encode( photo( 800, 1000, array( 150, 150, 650, 850 ), 0xFFFFFF ) );
$res = CVD_Dudas_Catalogo::bot_poner( req( array(), array(), array( 'dudas' => array(
	array( 'ref' => 'noche-1-hero', 'tipo' => 'foto', 'pieza' => 'HERO', 'producto' => array( 'id' => 4 ), 'pregunta' => '¿Es el mismo cortinero?', 'antes' => array( 'foto' => 'https://example.test/real.jpg' ), 'despues' => array( 'foto_b64' => $pngb64 ), 'escalon' => array( 'usado' => 'ia', 'coste_usd' => 0.15 ) ),
	array( 'ref' => 'noche-1-dato', 'tipo' => 'dato', 'producto' => array( 'id' => 4 ), 'pregunta' => '¿Trae herrajes?', 'dato' => 'Herrajes de instalación', 'despues' => array( 'valor' => 'Sí, incluidos' ) ),
	array( 'ref' => 'noche-1-texto', 'tipo' => 'texto', 'producto' => array( 'id' => 4 ), 'campo' => 'description', 'despues' => array( 'texto' => '<p>Nuevo</p>' ) ),
	array( 'ref' => 'noche-1-regla', 'tipo' => 'regla', 'regla' => 'Sartén: mango a la derecha' ),
	array( 'ref' => 'noche-1-pedir', 'tipo' => 'pedir_foto', 'producto' => array( 'id' => 5 ), 'origen_texto' => 'No se ve el producto' ),
	array( 'ref' => 'noche-1-foto2', 'tipo' => 'foto', 'pieza' => 'LIFESTYLE', 'producto' => array( 'id' => 3 ), 'despues' => array( 'foto' => 'https://example.test/vps/life.webp' ) ),
	array( 'tipo' => 'nada' ),
) ) ) );
check( 7 === count( $res['dudas'] ) && 422 === $res['dudas'][6]['status'] && preg_match( '/^D\d+$/', $res['dudas'][0]['id'] ), 'bot/dudas creates 6 cards and rejects the bad one' );
[ $hero_card, $dato_card, $texto_card, $regla_card, $pedir_card, $life_card ] = array_column( array_slice( $res['dudas'], 0, 6 ), 'id' );
$again = CVD_Dudas_Catalogo::bot_poner( req( array(), array(), array( 'ref' => 'noche-1-dato', 'tipo' => 'dato', 'pregunta' => '¿Trae herrajes de pared?' ) ) );
check( $dato_card === $again['dudas'][0]['id'], 'same ref updates the same card (no duplicates)' );
$list = CVD_Dudas_Catalogo::lista( req( array( 'estado' => 'pendiente' ) ) );
$all_cards = static fn() => array_merge( CVD_Dudas_Catalogo::lista( req( array( 'estado' => 'pendiente' ) ) )['dudas'], CVD_Dudas_Catalogo::lista( req( array( 'estado' => 'marcha' ) ) )['dudas'], CVD_Dudas_Catalogo::lista( req( array( 'estado' => 'hecha' ) ) )['dudas'] );
$get = static fn( $id ) => current( array_filter( $all_cards(), static fn( $d ) => $d['id'] === $id ) );
check( 7 === $list['cuenta']['pendiente'] && 7 === count( $list['dudas'] ) && ! isset( $list['dudas'][0]['despues']['archivo'] ) && str_starts_with( $get( $hero_card )['despues']['foto'], 'https://example.test/uploads/cvd-dudas/' ), 'panel list: pending cards (with the panel one), no internal paths, VPS photo stored locally' );
$answer = static fn( $id, array $data, array $files = array() ) => CVD_Dudas_Catalogo::respuesta( req( array_merge( array( 'duda' => $id, 'version' => $get( $id )['version'] ), $data ), $files ) );

/* 409 by version, then each type. */
$r = CVD_Dudas_Catalogo::respuesta( req( array( 'duda' => $dato_card, 'version' => 'vieja', 'respuesta' => 'aprobada' ) ) );
check( 409 === status( $r ) && $dato_card === $r->data['duda']['id'], '409 when the version does not match, with the current card' );
$r = $answer( $dato_card, array( 'respuesta' => 'aprobada' ) );
$datos = wc_get_product( 4 )->get_meta( CVD_Content_Studio::DATOS_META );
check( 'hecha' === $r['estado'] && 'ficha · crm_knowledge' === $r['destino'] && 'Sí, incluidos' === $datos[ count( $datos ) - 1 ]['valor'], 'dato approved → ficha (+ crm_knowledge for the VPS) → hecha' );
check( 409 === status( CVD_Dudas_Catalogo::respuesta( req( array( 'duda' => $dato_card, 'version' => $r['duda']['version'], 'respuesta' => 'aprobada' ) ) ) ), 'an answered card cannot be answered twice' );
$r = $answer( $texto_card, array( 'respuesta' => 'corregir', 'valor' => '<p>¿Se instala sola? <strong>Sí</strong>, con <b>tacos</b>.</p><script>x()</script>' ) );
$desc = wc_get_product( 4 )->get_description();
check( 'hecha' === $r['estado'] && str_contains( $desc, '<strong>Sí</strong>' ) && str_contains( $desc, '<b>tacos</b>' ) && ! str_contains( $desc, '<script' ) && 1 === count( wc_get_product( 4 )->get_meta( '_cvd_content_history' ) ), 'texto corrected → ficha with bold FAQ kept, copy «antes» in history' );
$r = $answer( $regla_card, array( 'respuesta' => 'corregir', 'valor' => 'Sartén: mango a la derecha, entero' ) );
check( 'hecha' === $r['estado'] && 'preferencias' === $r['destino'], 'regla corrected → PREFERENCIAS (for the VPS)' );
$r = $answer( $hero_card, array( 'respuesta' => 'aprobada' ) );
$p4 = wc_get_product( 4 );
check( 'hecha' === $r['estado'] && 'web' === $r['destino'] && 4 === (int) get_post_meta( $p4->get_image_id(), CVD_Content_Studio::HERO_ADJUNTO ), 'VPS HERO approved → main photo of the web' );
check( 'aprobada' === CVD_Content_Studio::piezas( 4 )['hero']['estado'] && 1600 === getimagesize( file_of( $p4->get_image_id() ) )[0], 'the approved HERO is the piece of the product, 1600 px' );
check( 422 === status( $answer( $life_card, array( 'respuesta' => 'explicar' ) ) ), 'explicar needs voice or text' );
$r = $answer( $life_card, array( 'respuesta' => 'rechazada', 'motivos' => array( 'Color' ) ) );
check( 'marcha' === $r['estado'] && preg_match( '/^sup-/', $r['tarea_id'] ) && 1 === $r['duda']['vueltas'], 'photo rejected → task for the supervisor (round 1)' );
check( 409 === status( $answer( $life_card, array( 'respuesta' => 'aprobada' ) ) ), 'a card in progress waits for its result' );
$bad = CVD_Dudas_Catalogo::bot_resultado( req( array( 'duda' => $life_card ), array(), array( 'tarea_id' => 'sup-otra', 'texto' => 'x' ) ) );
check( 409 === status( $bad ), 'a result for another task is refused' );
$ok = CVD_Dudas_Catalogo::bot_resultado( req( array( 'duda' => $life_card ), array(), array( 'tarea_id' => $r['tarea_id'], 'foto_b64' => $pngb64, 'escalon' => array( 'usado' => 'ia', 'coste_usd' => 0.15 ) ) ) );
check( 'resultado' === $ok['estado'] && $get( $life_card )['tarea']['listo'], 'bot/dudas/{id}/resultado → estado resultado, task done' );
$r = $answer( $life_card, array( 'respuesta' => 'rechazada' ) );
check( 'marcha' === $r['estado'] && 2 === $r['duda']['vueltas'], 'result rejected → another round (2)' );
CVD_Dudas_Catalogo::bot_resultado( req( array( 'duda' => $life_card ), array(), array( 'foto_b64' => $pngb64 ) ) );
$r = $answer( $life_card, array( 'respuesta' => 'rechazada' ) );
check( 'hecha' === $r['estado'] && 'descartada' === $r['destino'] && 3 === $r['duda']['vueltas'], 'third round rejected → hecha (descartada)' );
check( 409 === status( CVD_Dudas_Catalogo::bot_resultado( req( array( 'duda' => $dato_card ), array(), array( 'texto' => 'x' ) ) ) ), 'result for a card not in progress → 409' );
$r = $answer( $pedir_card, array( 'respuesta' => 'revision' ) );
check( 'revision' === $r['estado'] && 'revision' === $get( $pedir_card )['estado'] && $r['cuenta']['pendiente'] >= 1, 'Luego → stays in Por contestar, marked revision' );

/* pedir_foto: the new real photo goes to the ficha and the HERO is made again (free cut-out, published by itself). */
vps_on(); $GLOBALS['vps']['recorte'] = 'fiable';
$tmp = tempnam( sys_get_temp_dir(), 'cvd' ); file_put_contents( $tmp, photo( 900, 900, array( 200, 200, 700, 700 ) ) );
$r = $answer( $pedir_card, array( 'respuesta' => 'corregir' ), array( 'foto' => array( 'tmp_name' => $tmp, 'error' => 0, 'type' => 'image/jpeg' ) ) );
$p5 = wc_get_product( 5 ); $real5 = (int) $p5->get_meta( CVD_Content_Studio::HERO_ANTES )['image'];
check( 'hecha' === $r['estado'] && 'web' === $r['destino'] && '1' === get_post_meta( $real5, '_cvd_foto_real' ) && 5 === (int) get_post_meta( $p5->get_image_id(), CVD_Content_Studio::HERO_ADJUNTO ), 'pedir_foto: new real photo saved and a new HERO on the web' );

/* explicar with text → task; outbox with a cursor. */
$x = CVD_Dudas_Catalogo::bot_poner( req( array(), array(), array( 'tipo' => 'foto', 'pieza' => 'HERO', 'producto' => array( 'id' => 3 ), 'despues' => array( 'foto' => 'https://example.test/vps/x.webp' ) ) ) );
$r = $answer( $x['dudas'][0]['id'], array( 'respuesta' => 'explicar', 'texto' => 'El mango va a la derecha.' ) );
check( 'marcha' === $r['estado'] && 'supervisor' === $r['destino'], 'explicar → task for the supervisor' );
$out = CVD_Dudas_Catalogo::bot_respuestas( req( array( 'desde' => 0 ) ) );
$dest = array_column( $out['respuestas'], 'destino' );
check( in_array( 'crm_knowledge', $dest, true ) && in_array( 'preferencias', $dest, true ) && in_array( 'supervisor', $dest, true ) && in_array( 'web', $dest, true ) && 'El mango va a la derecha.' === end( $out['respuestas'] )['texto'], 'bot reads what to apply: crm_knowledge, preferencias, supervisor, web' );
check( array() === CVD_Dudas_Catalogo::bot_respuestas( req( array( 'desde' => $out['cursor'] ) ) )['respuestas'] && $out['cursor'] === $out['ultimo'], 'with the cursor, nothing is read twice' );
check( isset( $out['cuenta']['pendiente'] ) && $out['activa'], 'the VPS gets the count for the daily WhatsApp' );

/* Commerce of every product untouched by the whole panel. */
check( $before_commerce === $commerce( wc_get_product( 1 ) ), 'product 1: price, stock, SKU, URL and commission never changed' );
foreach ( array( 2, 3, 4, 5 ) as $id ) { $q = wc_get_product( $id ); check( '25' === $q->get_price() && 9 === $q->get_stock_quantity() && 'publish' === $q->get_status(), "product $id: price, stock and status unchanged" ); }
echo "Content studio v2 server contracts passed.\n";
