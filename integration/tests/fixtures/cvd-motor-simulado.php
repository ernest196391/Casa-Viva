<?php
/**
 * SOLO PRUEBAS. Hace de VPS (recorte gratis + IA de pago) para el panel v2 en el WordPress desechable de integración.
 * content-studio-browser.php lo copia a wp-content/mu-plugins; nunca forma parte del plugin ni de producción.
 * Modo: opción cvd_test_motor_modo = fiable (recorte bueno) | nofiable (recorte falla → IA) | error (IA 502) | tope (IA 429).
 */
defined( 'ABSPATH' ) || exit;
if ( 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { return; }

function cvd_test_imagen( int $w, int $h, bool $png ): string {
	$im = imagecreatetruecolor( $w, $h );
	if ( $png ) {
		imagealphablending( $im, false ); imagesavealpha( $im, true );
		imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
		imagefilledrectangle( $im, 2, 2, $w - 3, $h - 3, imagecolorallocatealpha( $im, 120, 80, 50, 0 ) );
		ob_start(); imagepng( $im ); return (string) ob_get_clean();
	}
	imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, 0xF7F7F7 );
	imagefilledrectangle( $im, (int) ( $w * 0.22 ), (int) ( $h * 0.2 ), (int) ( $w * 0.78 ), (int) ( $h * 0.8 ), 0x785032 );
	ob_start(); imagejpeg( $im, null, 90 ); return (string) ob_get_clean();
}

add_filter( 'cvd_content_recorte', static function () {
	return static function ( string $bytes ) {
		$modo = (string) get_option( 'cvd_test_motor_modo', 'fiable' );
		return array( 'png' => cvd_test_imagen( 900, 600, true ), 'fiable' => 'fiable' === $modo, 'motivos' => 'fiable' === $modo ? array() : array( 'Borró las barras finas' ), 'medidas' => array() );
	};
} );
add_filter( 'cvd_content_motor', static function () {
	return static function ( string $prompt, array $opts, array $refs ) {
		$modo = (string) get_option( 'cvd_test_motor_modo', 'fiable' );
		if ( 'error' === $modo ) { return new WP_Error( 'cvd_photo_ai', 'El servidor de imágenes no respondió a tiempo. Espera un minuto antes de repetir.', array( 'status' => 502 ) ); }
		if ( 'tope' === $modo ) { return new WP_Error( 'cvd_photo_ai', 'Tope del día alcanzado.', array( 'status' => 429 ) ); }
		return '1536x1024' === ( $opts['tam'] ?? '' ) ? cvd_test_imagen( 1536, 1024, false ) : cvd_test_imagen( 1024, 1536, false );
	};
} );
