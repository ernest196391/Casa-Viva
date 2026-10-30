<?php

/**
 * Pruebas del texto del «Taller de fotos» (Core 3.14.89): plantilla, guarda contra lo inventado y precio/enlace puestos por código.
 * php artifacts/tests/test-taller-core.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-taller-core.php';

$fails = 0;
function check( string $name, $got, $want ): void {
	global $fails;
	if ( $got !== $want ) { $fails++; echo "FALLA  {$name}\n  esperado: " . var_export( $want, true ) . "\n  obtenido: " . var_export( $got, true ) . "\n"; }
}
function has( string $name, string $hay, string $needle, bool $want = true ): void { check( $name, false !== strpos( $hay, $needle ), $want ); }

$f = array(
	'name' => 'Filtro de aceite Suzuki GN 125',
	'lines' => array( 'Filtro de aceite para motos Suzuki GN 125 y similares.', 'Rosca de 20 mm, sale con su empaque.' ),
	'attrs' => array( 'Marca' => 'Suzuki' ),
	'extra' => 'Usado en buen estado',
	'price' => '8 USD',
	'link' => 'https://casaviva.company/producto/filtro/?ref=CV59DAYKEL',
);

/* ---------- plantilla ---------- */
$t = CVD_Taller_Text::template( $f, 'facebook' );
has( 'facebook trae el nombre', $t, 'Filtro de aceite Suzuki GN 125' );
has( 'facebook trae el precio', $t, '8 USD' );
has( 'facebook trae el enlace', $t, '?ref=CV59DAYKEL' );
has( 'facebook trae un dato real', $t, 'Rosca de 20 mm' );
has( 'facebook trae lo que ella escribió', $t, 'Usado en buen estado' );
$e = CVD_Taller_Text::template( $f, 'estado' );
check( 'estado es corto', mb_strlen( $e ) < 260, true );
has( 'estado trae enlace', $e, 'ref=CV59DAYKEL' );
$d = CVD_Taller_Text::template( $f, 'descripcion' );
has( 'descripción sin precio', $d, 'USD', false );
has( 'descripción sin enlace', $d, 'http', false );
$bare = CVD_Taller_Text::template( array( 'name' => 'Espejo', 'price' => '', 'link' => '' ), 'facebook' );
has( 'sin datos no inventa precio', $bare, 'USD', false );

/* ---------- guarda ---------- */
$ok = 'Filtro de aceite para tu Suzuki GN 125, con rosca de 20 mm y su empaque. Usado pero en buen estado, listo para poner.';
check( 'texto bueno pasa', CVD_Taller_Text::guard( $ok, $f, 'facebook' ), $ok );
check( 'cifra inventada se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125 con rosca de 22 mm, listo para tu moto.', $f, 'facebook' ), '' );
check( 'precio inventado se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125 por solo 5 USD, para tu moto de siempre.', $f, 'facebook' ), '' );
check( 'garantía inventada se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125 con garantía, listo para poner en tu moto.', $f, 'facebook' ), '' );
check( 'descuento inventado se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125 con descuento, listo para poner en tu moto.', $f, 'facebook' ), '' );
check( 'enlace de la IA se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125, compra en https://example.com ahora mismo.', $f, 'facebook' ), '' );
check( 'teléfono de la IA se rechaza', CVD_Taller_Text::guard( 'Filtro de aceite Suzuki GN 125, escríbeme al 53 5405 6173 para pedirlo ya.', $f, 'facebook' ), '' );
check( 'muy corto se rechaza', CVD_Taller_Text::guard( 'Filtro', $f, 'facebook' ), '' );
check( 'estado muy largo se rechaza', CVD_Taller_Text::guard( str_repeat( 'Filtro de aceite. ', 20 ), $f, 'estado' ), '' );
$g = array( 'name' => 'Cortina', 'lines' => array( 'Cortina con garantía de un año contra el moho.' ), 'attrs' => array(), 'extra' => '', 'price' => '10 USD' );
check( 'promesa que SÍ estaba en los datos pasa', CVD_Taller_Text::guard( 'Cortina con garantía de un año contra el moho, para tu baño.', $g, 'facebook' ) !== '', true );
check( 'comillas y negritas se limpian', CVD_Taller_Text::guard( '**"Filtro de aceite para tu Suzuki GN 125, con rosca de 20 mm."**', $f, 'facebook' ), 'Filtro de aceite para tu Suzuki GN 125, con rosca de 20 mm.' );

/* ---------- precio y enlace los pone el código ---------- */
$fin = CVD_Taller_Text::finish( $ok, $f, 'facebook' );
has( 'finish pone precio', $fin, '💵 8 USD' );
has( 'finish pone enlace', $fin, 'ref=CV59DAYKEL' );
has( 'descripción no lleva precio', CVD_Taller_Text::finish( $ok, $f, 'descripcion' ), 'USD', false );

/* ---------- líneas de la descripción ---------- */
$html = '<ul><li>Cepillo que alisa y peina sin tirones.</li><li>Colores: rosado</li><li>Cepillo alisador – 35 USD precio</li></ul><p>Sale en 24 horas con empaque de regalo.</p>';
$lines = CVD_Taller_Text::description_lines( $html, 'Cepillo alisador' );
check( 'toma frases reales', $lines, array( 'Cepillo que alisa y peina sin tirones.', 'Sale en 24 horas con empaque de regalo.' ) );

/* ---------- instrucción ---------- */
has( 'instrucción prohíbe inventar', CVD_Taller_Text::system_instruction( 'facebook' ), 'No inventes' );
has( 'instrucción no pone precio', CVD_Taller_Text::system_instruction( 'facebook' ), 'No escribas el precio' );
has( 'mensaje lleva el dato de la vendedora', CVD_Taller_Text::user_message( $f ), 'Usado en buen estado' );

echo $fails ? "\n{$fails} FALLAS\n" : "OK: todas las pruebas del Taller pasaron\n";
exit( $fails ? 1 : 0 );
