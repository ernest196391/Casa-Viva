<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'casaviva.company' || CVD_VERSION !== '3.14.113' ) { throw new RuntimeException( 'Sitio o versión inesperados.' ); }
$user = get_user_by( 'login', 'zayri' );
if ( ! $user ) { throw new RuntimeException( 'Cuenta no encontrada.' ); }
wp_set_current_user( $user->ID );
$routes = rest_get_server()->get_routes();
if ( ! CVD_Content_Studio::allowed() || current_user_can( 'manage_options' ) || ! isset( $routes['/casa-viva/v1/contenido/ia/generate'], $routes['/casa-viva/v1/contenido/ia/poll'] ) ) { throw new RuntimeException( 'Revisar permisos y rutas de IA.' ); }
foreach ( CVD_Photo_AI::providers() as $name => $enabled ) { echo $name . '=' . ( $enabled ? 'configurado' : 'pendiente' ) . PHP_EOL; }
$_GET['cvd_contenido'] = '1';
ob_start( static function ( $html ) {
  if ( strpos( $html, 'id="cv-generate"' ) === false || strpos( $html, 'id="cv-variants"' ) === false || strpos( $html, 'id="cv-provider"' ) === false ) { throw new RuntimeException( 'Faltan controles de generación.' ); }
  return "Panel con generación y selección disponible para Zayri.\n";
} );
CVD_Content_Studio::page();
