<?php
// Pruebas de las reglas puras de las reseñas pedidas por el bot. Se corre con: php tests/resenas-bot.test.php (código 1 si algo falla).
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-cvd-resenas-bot.php';

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got, JSON_UNESCAPED_UNICODE ) . "\n  quería: " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$R = 'CVD_Resenas_Bot';

// Permiso: solo explícito.
check( 'permiso true', $R::permission_ok( true ), true );
check( 'permiso 1', $R::permission_ok( '1' ), true );
check( 'permiso sí', $R::permission_ok( 'Sí' ), true );
check( 'permiso vacío', $R::permission_ok( '' ), false );
check( 'permiso no', $R::permission_ok( 'no' ), false );
check( 'permiso quizás', $R::permission_ok( 'quizás' ), false );
check( 'permiso false', $R::permission_ok( false ), false );

// Nombre que se publica: nombre e inicial.
check( 'firma María Pérez', $R::display_name( 'maría josé', 'pérez' ), 'María P.' );
check( 'firma sin apellido', $R::display_name( 'Yuli', '' ), 'Yuli' );
check( 'firma vacía', $R::display_name( '  ', 'Gómez' ), '' );
check( 'firma con número de apellido', $R::display_name( 'Ana', '123' ), 'Ana' );

// Teléfono.
check( 'tel 8 cifras', $R::phone( '5555-1234' ), '5355551234' );
check( 'tel con +53', $R::phone( '+53 5555 1234' ), '5355551234' );
check( 'tel corto', $R::phone( '1234' ), '' );

// Validación de la reseña.
$ok = array( 'permission' => '1', 'text' => 'Muy buena la cortina, llegó rápido', 'name' => 'María P.', 'rating' => '5' );
check( 'reseña válida', $R::clean_review( $ok ), array( 'text' => 'Muy buena la cortina, llegó rápido', 'name' => 'María P.', 'rating' => 5 ) );
check( 'sin permiso', $R::clean_review( array_merge( $ok, array( 'permission' => '' ) ) ), 'permission' );
check( 'permiso no', $R::clean_review( array_merge( $ok, array( 'permission' => 'no' ) ) ), 'permission' );
check( 'sin texto', $R::clean_review( array_merge( $ok, array( 'text' => '  ' ) ) ), 'text' );
check( 'solo emojis', $R::clean_review( array_merge( $ok, array( 'text' => '👍👍👍👍👍👍' ) ) ), 'text' );
check( 'sin nombre', $R::clean_review( array_merge( $ok, array( 'name' => '' ) ) ), 'name' );
check( 'sin estrellas vale', $R::clean_review( array_merge( $ok, array( 'rating' => '' ) ) )['rating'], 0 );
check( 'estrellas 6', $R::clean_review( array_merge( $ok, array( 'rating' => 6 ) ) ), 'rating' );
check( 'estrellas 4.5', $R::clean_review( array_merge( $ok, array( 'rating' => '4.5' ) ) ), 'rating' );
check( 'estrellas texto', $R::clean_review( array_merge( $ok, array( 'rating' => 'cinco' ) ) ), 'rating' );
check( 'sin etiquetas', $R::clean_review( array_merge( $ok, array( 'text' => '<b>Excelente</b> servicio' ) ) )['text'], 'Excelente servicio' );
check( 'texto máx 400', mb_strlen( $R::clean_review( array_merge( $ok, array( 'text' => str_repeat( 'buenísimo ', 80 ) ) ) )['text'] ), 400 );

echo "{$n} comprobaciones, {$fails} fallos\n";
exit( $fails ? 1 : 0 );
