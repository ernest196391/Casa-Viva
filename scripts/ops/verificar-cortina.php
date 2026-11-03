<?php
// Ejecutar con WP-CLI en la tienda. El JPG de salida se escribe solo en stdout.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'casaviva.company' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'La prueba requiere Casa Viva.' );
}
$editor = get_user_by( 'login', 'zayri' );
if ( ! $editor ) { throw new RuntimeException( 'No se encontró la cuenta de contenido.' ); }
wp_set_current_user( $editor->ID );
if ( ! CVD_Content_Studio::allowed() || current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Revisar los permisos de catálogo.' ); }
if ( empty( CVD_Photo_AI::providers()['cloudflare'] ) ) { throw new RuntimeException( 'Falta la conexión en WooCommerce → IA para fotos.' ); }
// La entrada puede ser la foto de la cortina utilizada para validar el editor móvil.
$photo = '';
$items = get_posts( array( 'post_type'=>'product', 'post_status'=>'publish', 's'=>'cortina', 'posts_per_page'=>50 ) );
foreach ($items as $item) {
  $att = get_post_thumbnail_id($item->ID);
  $path = $att ? get_attached_file($att) : '';
  if ($path && is_readable($path) && !get_post_meta($att,'_cvd_foto_ia',true)) { $photo=$path; break; }
}
if (!$photo) throw new RuntimeException('No se encontró la foto de cortina en el catálogo.');
$img = wp_get_image_editor( $photo );
if ( is_wp_error( $img ) ) { throw new RuntimeException( 'No se pudo abrir la foto de referencia.' ); }
$temp = tempnam( sys_get_temp_dir(), 'cvd-photo-' );
$jpeg = $temp . '.jpg';
try {
	$dims = $img->get_size();
	if ( max( $dims ) > 511 && is_wp_error( $img->resize( 511, 511, false ) ) ) { throw new RuntimeException( 'No se pudo preparar la foto.' ); }
	if ( is_wp_error( $img->save( $jpeg, 'image/jpeg' ) ) ) { throw new RuntimeException( 'No se pudo guardar la referencia temporal.' ); }
	$request = new WP_REST_Request( 'POST', '/casa-viva/v1/contenido/ia/generate' );
	$request->set_body_params( array( 'provider' => 'cloudflare', 'paid' => '0', 'layout' => 'spec', 'format' => '1920', 'details' => 'Conserva el producto de la foto de Casa Viva y presenta el mismo color y material.' ) );
	$request->set_file_params( array( 'photo' => array( 'tmp_name' => $jpeg, 'name' => 'producto.jpg', 'type' => 'image/jpeg', 'size' => filesize( $jpeg ), 'error' => 0 ) ) );
	$result = rest_do_request( $request );
	$data = $result->get_data();
	if ( $result->get_status() !== 200 ) { throw new RuntimeException( sanitize_text_field( $data['message'] ?? 'Generación fallida.' ) ); }
	if ( ! preg_match( '~^data:image/(jpeg|png|webp);base64,(.+)$~D', $data['image'] ?? '', $matches ) ) { throw new RuntimeException( 'No se recibió la imagen.' ); }
	$bytes = base64_decode( $matches[2], true );
	if ( ! $bytes || ! getimagesizefromstring( $bytes ) ) { throw new RuntimeException( 'La vista previa no se pudo abrir.' ); }
	echo wp_json_encode( array( 'original'=>base64_encode(file_get_contents($jpeg)), 'preview'=>base64_encode($bytes), 'name'=>get_the_title($item->ID) ) );
} finally { if ( file_exists( $jpeg ) ) { unlink( $jpeg ); } unlink( $temp ); }

