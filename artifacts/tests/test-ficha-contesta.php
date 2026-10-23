<?php
/**
 * 3.14.56 «Ficha que contesta» (CVD_Ficha_Contesta): reglas puras, sin WordPress.
 * Corre con: php artifacts/tests/test-ficha-contesta.php
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-ficha-contesta.php';

$fails = 0;
function ok( $cond, string $name ): void {
	global $fails;
	if ( $cond ) { echo "✓ {$name}\n"; return; }
	$fails++;
	echo "✗ {$name}\n";
}
function eq( $a, $b, string $name ): void { ok( $a === $b, $name . ( $a === $b ? '' : ' → ' . var_export( $a, true ) . ' ≠ ' . var_export( $b, true ) ) ); }

$C = 'CVD_Ficha_Contesta';

// Interruptor
eq( $C::mode_of( null ), 'no', 'interruptor: sin valor = no (todo como antes)' );
eq( $C::mode_of( 'Sí' ), 'si', 'interruptor: «Sí» = si' );
eq( $C::mode_of( 'datos' ), 'datos', 'interruptor: datos = solo el bloque' );
eq( $C::mode_of( 'quizás' ), 'no', 'interruptor: valor raro = no' );

// Clasificar líneas
eq( $C::classify_line( 'Medidas: 45 × 70 cm (±2 cm), tamaño estándar.' ), array( 'medidas' ), 'línea de medidas' );
eq( $C::classify_line( 'Tela: 100 % poliéster. Relleno: 100 % poliéster.' ), array( 'material' ), 'línea de material (tela/relleno)' );
eq( $C::classify_line( 'Batería integrada en el cuadro.' ), array( 'voltaje' ), 'batería = voltaje/carga' );
ok( in_array( 'trae', $C::classify_line( 'Cesta delantera incluida.' ), true ), '«incluida» = qué trae' );
ok( in_array( 'trae', $C::classify_line( 'Incluye 2 remates, 2 soportes, tornillos y tacos' ), true ), '«Incluye…» = qué trae' );
eq( $C::classify_line( 'Color negro.' ), array( 'colores' ), 'línea de color' );
ok( in_array( 'garantia', $C::classify_line( 'Garantía de 7 días por defecto de fábrica.' ), true ), 'garantía' );
eq( $C::classify_line( 'Una buena almohada se nota al despertar.' ), array(), 'frase de venta: nada' );

// ¿Dato exacto?
ok( ! $C::line_is_exact( 'voltaje', 'Batería integrada en el cuadro.' ), 'batería sin número no es exacto' );
ok( $C::line_is_exact( 'voltaje', 'Batería de 36 V y 10 Ah' ), '36 V es exacto' );
ok( $C::line_is_exact( 'voltaje', 'Carga por USB tipo C' ), 'USB tipo C es exacto' );
ok( ! $C::line_is_exact( 'medidas', 'Tamaño estándar' ), 'medidas sin número no es exacto' );

// Líneas que no son datos
ok( $C::useless_line( '📍 Recógela en la tienda (Calle Conill A esq. 45 #864)' ), 'recogida = no es dato' );
ok( $C::useless_line( 'Autonomía, velocidad y batería: consultar.' ), '«consultar» = no es dato' );
ok( ! $C::useless_line( 'Frenos de disco delante y detrás.' ), 'frenos sí es línea' );

// Datos de una ficha real (bicicleta 4184, 23-oct)
$bici = $C::datos_from( array(
	'lines' => array( 'Bicicleta eléctrica compacta y plegable.', 'Batería integrada en el cuadro.', 'Cesta delantera incluida.', 'Color negro.', 'Autonomía, velocidad y batería: consultar.', '📍 Recógela en la tienda (Calle Conill A esq. 45 #864, Nuevo Vedado)' ),
	'electrico' => true,
) );
eq( count( $bici['voltaje'] ), 1, 'bici: una línea de batería' );
eq( $bici['medidas'], array(), 'bici: sin medidas' );
eq( $C::rows_for( $bici, true ), array( 'medidas', 'voltaje', 'trae', 'colores' ), 'bici (aparato): sin fila de material' );
eq( $C::missing( $bici, true, 0 ), array( 'medidas', 'voltaje', 'foto' ), 'bici: faltan medidas, voltaje exacto y foto real' );

// Lo que contestó la tienda manda y sale primero, con su fecha en colores
$alm = $C::datos_from( array(
	'lines' => array( 'Medidas: 45 × 70 cm (±2 cm), tamaño estándar.', 'Tela: 100 % poliéster. Relleno: 100 % poliéster.' ),
	'campos' => array( 'colores' => array( 'valor' => 'Blanco y gris', 'quien' => 'Nana', 'at' => '2026-10-24T15:00:00Z' ), 'trae' => array( 'valor' => 'Funda con cremallera', 'quien' => 'Nana', 'at' => '2026-10-24T15:00:00Z' ) ),
	'electrico' => false,
) );
ok( $alm['colores'][0]['tienda'] && false !== strpos( $alm['colores'][0]['texto'], '24/10, puede cambiar' ), 'colores de la tienda: con fecha y «puede cambiar»' );
eq( $alm['trae'][0]['texto'], 'Funda con cremallera', 'qué trae: lo de la tienda' );
eq( $C::missing( $alm, false, 1 ), array(), 'almohada con foto: nada falta' );

// Variantes que quedan y atributos
$cort = $C::datos_from( array( 'variantes' => array( array( 'Gris', 3, true ), array( 'Beige', 0, false ), array( 'Negro', null, true ) ), 'attrs' => array( 'Color' => array( 'Gris', 'Beige', 'Negro' ), 'Medidas' => array( '160 x 280' ) ) ) );
eq( $cort['colores'][0]['texto'], 'Quedan: Gris (3), Negro', 'variantes: solo las que quedan, con cuántas' );
eq( count( $cort['colores'] ), 1, 'el atributo Color no repite la lista' );
eq( $cort['medidas'][0]['texto'], 'Medidas: 160 x 280', 'atributo Medidas' );

// Estado
eq( $C::stock_state( array( 'bajo_pedido' => true, 'in_stock' => true ) ), array( 'bajo_pedido', '📦 Bajo pedido · llega en 24–48 h' ), 'bajo pedido' );
eq( $C::stock_state( array( 'in_stock' => false ) )[0], 'agotado', 'agotado' );
eq( $C::stock_state( array( 'in_stock' => true, 'qty' => 11 ) )[1], '🏪 Hay 11 en la tienda', 'hay 11' );
eq( $C::stock_state( array( 'in_stock' => true, 'qty' => null ) )[1], '🏪 Hay en la tienda', 'hay (sin contar)' );

// Agrupar la misma pregunta
eq( $C::group_key( 3714, 0, 'medidas' ), $C::group_key( 3714, 0, 'medidas', 'otro texto' ), 'medidas: misma clave sea cual sea el texto' );
ok( $C::group_key( 3714, 0, 'medidas' ) !== $C::group_key( 3714, 5, 'medidas' ), 'otra variante = otra pregunta' );
eq( $C::group_key( 3714, 0, 'otra', '¿Se puede lavar en lavadora?' ), $C::group_key( 3714, 0, 'otra', 'se puede lavar en la lavadora' ), 'otra: mismas palabras = misma pregunta' );
ok( $C::group_key( 3714, 0, 'otra', '¿Se puede lavar?' ) !== $C::group_key( 3714, 0, 'otra', '¿Es firme?' ), 'otra: distinta pregunta = distinta clave' );

list( $a1, $n1 ) = $C::add_asker( array(), array( 'u' => 7, 'p' => '52544351', 'n' => 'Dany' ) );
list( $a2, $n2 ) = $C::add_asker( $a1, array( 'p' => '52544351', 'n' => 'Dany (General)' ) );
list( $a3, $n3 ) = $C::add_asker( $a2, array( 'u' => 9, 'p' => '53338052', 'n' => 'Maryta' ) );
ok( $n1 && ! $n2 && $n3 && 2 === count( $a3 ), 'la misma gestora no se suma dos veces; otra sí' );

// Líneas que quedan en la ficha
eq( $C::answer_line( 'medidas', '45 × 70 cm.' ), 'Medidas: 45 × 70 cm.', 'línea de medidas' );
eq( $C::answer_line( 'trae', 'Funda con cremallera' ), 'Trae: Funda con cremallera.', 'línea de qué trae' );
eq( $C::answer_line( 'colores', 'gris y beige', '', '', '24/10' ), 'Colores (hoy, 24/10): gris y beige.', 'colores con fecha' );
eq( $C::answer_line( 'material', 'MDF', '', 'Blanca' ), '(Blanca) Material: MDF.', 'con variante delante' );
eq( $C::answer_line( 'otra', 'Sí, en frío', '¿Se puede lavar en lavadora?' ), '¿Se puede lavar en lavadora? Sí, en frío.', 'otra: pregunta y respuesta' );
eq( $C::answer_line( 'medidas', '' ), '', 'sin respuesta: nada' );

// Avisos
$t = $C::answer_notice( 'Dany', 'Almohada cervical', 'Medidas: 45 × 70 cm.', 'https://casaviva.test/p/', 1 );
ok( 0 === strpos( $t, 'Dany 🙏' ) && false !== strpos( $t, '*Almohada cervical*' ) && false !== strpos( $t, 'otra gestora' ) && false !== strpos( $t, 'https://casaviva.test/p/' ), 'aviso a la gestora: nombre, producto, cuántas más y enlace' );
$s = $C::store_notice( 3, 2, 'https://casaviva.test/?x=1&quien={quien}' );
ok( false !== strpos( $s, 'Hola {quien}' ) && false !== strpos( $s, '3 preguntas' ) && false !== strpos( $s, '2 gestoras esperando' ), 'aviso a la tienda con {quien} para el bot' );

// Enlace firmado
$key = 'sal-de-prueba|cvd-ficha-tienda';
$tok = $C::sign( 2000000000, $key );
ok( $C::verify( $tok, 1900000000, $key ), 'enlace firmado vale' );
ok( ! $C::verify( $tok, 2000000001, $key ), 'enlace caducado no vale' );
ok( ! $C::verify( '2000000000-' . str_repeat( 'a', 24 ), 1900000000, $key ), 'firma falsa no vale' );
ok( ! $C::verify( $tok, 1900000000, 'otra-sal' ), 'con otra sal no vale' );

echo $fails ? "\n{$fails} FALLOS\n" : "\nTodo bien\n";
exit( $fails ? 1 : 0 );
