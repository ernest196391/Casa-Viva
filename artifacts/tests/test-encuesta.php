<?php
/**
 * Prueba de la encuesta anónima (Core 3.14.35) con WordPress simulado, sin base de datos real:
 * limpieza de respuestas, guardar, anonimato (ni usuario, ni IP, ni navegador, ni hora), campo trampa, límite por
 * minuto, encuesta cerrada / modo prueba, estadísticas, NPS, % de aciertos, exportación JSON y el HTML del formulario.
 * Ejecutar: php artifacts/tests/test-encuesta.php
 */

// ABSPATH en una carpeta temporal con un wp-admin/includes/upgrade.php de mentira (dbDelta no hace nada).
define( 'ABSPATH', sys_get_temp_dir() . '/cvd-encuesta-wp/' );
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', '<?php function dbDelta( $q ) { $GLOBALS["dbdelta"][] = $q; return array(); }' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CVD_DIR', __DIR__ . '/../../wordpress/casa-viva-dropship-core/' );
define( 'CVD_URL', 'https://casaviva.test/wp-content/plugins/casa-viva-dropship-core/' );
date_default_timezone_set( 'UTC' );

$GLOBALS['opts'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['current_user'] = 18; // una gestora con la sesión abierta: NO debe quedar guardado

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function add_option( $k, $v = '', $dep = '', $auto = true ) { if ( ! isset( $GLOBALS['opts'][ $k ] ) ) { $GLOBALS['opts'][ $k ] = $v; } return true; }
function update_option( $k, $v, $auto = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function wp_salt( $s = 'auth' ) { return 'sal-secreta-' . $s; }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = $v; $GLOBALS['transient_ttl'][ $k ] = $ttl; return true; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function home_url( $p = '' ) { return 'https://casaviva.test' . $p; }
function add_query_arg( $args, $url ) { return $args ? $url . '?' . http_build_query( $args ) : $url; }
function wp_generate_password( $n = 12, $s = true, $x = false ) { return substr( str_repeat( 'abcdefghij', 4 ), 0, $n ); }
function get_current_user_id() { return $GLOBALS['current_user']; }
function add_action() {}

final class Fake_WPDB {
	public $prefix = 'wp_';
	public $inserted = array();
	public $rows = array();
	public function insert( $table, $data, $format = null ) { $this->inserted[] = array( 'table' => $table, 'data' => $data ); return 1; }
	public function prepare( $q, ...$a ) { return array( $q, $a ); }
	public function get_results( $q, $out = null ) {
		$want = $q[1][0];
		return array_values( array_filter( $this->rows, static fn( $r ) => (int) $r['prueba'] === (int) $want ) );
	}
	public function get_var( $q ) { return count( array_filter( $this->rows, static fn( $r ) => 1 === (int) $r['prueba'] ) ); }
	public function query( $q ) { $n = 0; foreach ( $this->rows as $i => $r ) { if ( 1 === (int) $r['prueba'] ) { unset( $this->rows[ $i ] ); $n++; } } return $n; }
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require CVD_DIR . 'includes/class-cvd-encuesta-def.php';
require CVD_DIR . 'includes/class-cvd-encuesta.php';

$fails = 0; $count = 0;
function ok( bool $c, string $w ): void { global $fails, $count; $count++; if ( ! $c ) { $fails++; echo "FALLA: $w\n"; } }

/* ------------------------------------------------------------ definiciones */
$all = CVD_Encuesta_Def::questions();
$ids = array_column( $all, 'id' );
ok( count( $ids ) === count( array_unique( $ids ) ), 'ids de pregunta únicos' );
ok( count( CVD_Encuesta_Def::for_papel( 'gestora' ) ) === 20, 'gestora ve p1 + 18 de A + final = 20 (con 12a-c)' );
ok( count( CVD_Encuesta_Def::for_papel( 'mensajero' ) ) === 13, 'mensajero ve p1 + 11 + final' );
ok( count( CVD_Encuesta_Def::for_papel( 'tienda' ) ) === 10, 'tienda ve p1 + 8 + final' );
ok( array() === CVD_Encuesta_Def::for_papel( 'jefe' ), 'papel inventado: nada' );
foreach ( $all as $q ) {
	if ( isset( $q['options'] ) ) { $k = array_map( 'strval', array_column( $q['options'], 'key' ) ); ok( count( $k ) === count( array_unique( $k ) ), "opciones únicas en {$q['id']}" ); }
}
ok( '8' === CVD_Encuesta_Def::find( 'a12a' )['correct'] && '1usd' === CVD_Encuesta_Def::find( 'a12b' )['correct'], 'respuestas correctas de 12a (8) y 12b (1 USD) guardadas en la definición' );

/* ------------------------------------------------------------ limpieza */
$raw = array(
	'p1' => 'mas_3m', 'a1' => '4', 'a2' => 'inventada', 'a3' => 'mixta',
	'a5' => array( 'no_entiende', 'ninguno', 'otra', 'no_entiende', 'xxx' ), 'a5_txt' => '  <b>Se cuelga</b>  de noche ',
	'a6' => array( 'no_entro' ),
	'a7' => array( 'vale', 'fotos', 'medidas', 'comision' ),
	'a8' => array( 'bonita' ), 'a8_txt' => 'no eligió otra: no se guarda',
	'a12' => 'no', 'a12a' => '8', 'a12b' => '1usd',
	'a14' => '0', 'a15' => str_repeat( 'x', 1500 ),
	'b1' => '5', // de otro bloque: fuera
	'f1' => '   ',
);
$a = CVD_Encuesta_Def::sanitize( 'gestora', $raw );
ok( 'mas_3m' === $a['p1'] && 4 === $a['a1'], 'single y escala 1–5 válidas' );
ok( ! isset( $a['a2'] ), 'opción inventada no se guarda' );
ok( array( 'no_entiende', 'otra' ) === $a['a5'], 'multi: sin repetidas, sin inventadas y «Ninguno» se quita si hay otras' );
ok( 'Se cuelga de noche' === $a['a5_txt'], '«Otra» limpia (sin etiquetas, sin espacios de más)' );
ok( array( 'no_entro' ) === $a['a6'], 'exclusiva sola se queda' );
ok( 3 === count( $a['a7'] ), 'a7: hasta 3' );
ok( ! isset( $a['a8_txt'] ), 'texto de «Otra» sin marcar «Otra»: no se guarda' );
ok( ! isset( $a['a12a'] ) && ! isset( $a['a12b'] ), '12a/12b no se guardan si dice que no conoce el programa' );
ok( 0 === $a['a14'], 'el 0 de la recomendación vale' );
ok( 1000 === mb_strlen( $a['a15'] ), 'texto abierto cortado a 1000' );
ok( ! isset( $a['b1'] ) && ! isset( $a['f1'] ), 'otro bloque y texto vacío: fuera' );
$b = CVD_Encuesta_Def::sanitize( 'gestora', array( 'a12' => 'oido', 'a12a' => '80', 'a12b' => '1usd', 'a1' => '6', 'a14' => '11' ) );
ok( '80' === $b['a12a'] && '1usd' === $b['a12b'], '12a/12b sí se guardan si lo conoce' );
ok( ! isset( $b['a1'] ) && ! isset( $b['a14'] ), 'escalas fuera de rango: fuera' );
$c = CVD_Encuesta_Def::sanitize( 'mensajero', array( 'b7' => 'depende', 'b7_txt' => 'Playa y Marianao', 'b9' => 'otra', 'b9_txt' => 'el grupo viejo' ) );
ok( 'Playa y Marianao' === $c['b7_txt'] && 'el grupo viejo' === $c['b9_txt'], '«Depende de la zona: ___» y «Otra» en una sola respuesta' );

/* ------------------------------------------------------------ guardar y anonimato */
$_SERVER['REMOTE_ADDR'] = '152.206.177.45';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 12; SM-A125M) Chrome/120';
CVD_Encuesta::maybe_install();
ok( 'cerrada' === get_option( 'cvd_encuesta_estado' ), 'nace CERRADA' );
ok( 1 === count( $GLOBALS['dbdelta'] ) && false !== strpos( $GLOBALS['dbdelta'][0], 'respuestas longtext' ) && false === stripos( $GLOBALS['dbdelta'][0], 'user' ) && false === stripos( $GLOBALS['dbdelta'][0], ' ip ' ), 'tabla sin columnas de usuario ni IP' );
ok( 16 === strlen( (string) get_option( 'cvd_encuesta_clave_prueba' ) ), 'tiene clave de prueba' );
global $wpdb;
$r = CVD_Encuesta::save( array( 'papel' => 'gestora', 'origen' => 'general', 'r' => $raw ), false, '152.206.177.45' );
ok( ! $r['ok'] && 403 === $r['code'] && ! $wpdb->inserted, 'cerrada: no se guarda nada' );
$r = CVD_Encuesta::save( array( 'papel' => 'gestora', 'origen' => 'general', 'r' => $raw ), true, '152.206.177.45' );
ok( $r['ok'] && 1 === count( $wpdb->inserted ), 'cerrada + clave de prueba: se guarda' );
$row = $wpdb->inserted[0]['data'];
ok( 1 === $row['prueba'], 'marcada como prueba' );
ok( array( 'id', 'fecha', 'papel', 'origen', 'version_encuesta', 'tipo', 'prueba', 'respuestas' ) === array_keys( $row ), 'solo estas columnas: sin usuario, IP, navegador ni hora' );
$blob = json_encode( $row );
ok( false === strpos( $blob, '152.206' ), 'la IP no se guarda' );
ok( false === stripos( $blob, 'Android' ) && false === stripos( $blob, 'Mozilla' ), 'el navegador no se guarda' );
ok( ! preg_match( '/"user|user_id|"18"/', $blob ), 'el usuario (18) no se guarda aunque tenga la sesión abierta' );
ok( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['fecha'] ), 'solo el día, sin hora' );
ok( (bool) preg_match( '/^[0-9a-f]{16}$/', $row['id'] ), 'id al azar (no dice el orden de llegada)' );
ok( 'wp_cvd_encuesta' === $wpdb->inserted[0]['table'], 'tabla propia' );
foreach ( array_keys( $GLOBALS['transients'] ) as $tk ) { ok( false === strpos( $tk, '152' ) && false === strpos( $tk, '206' ), 'el límite por minuto no lleva la IP en la clave' ); }
foreach ( $GLOBALS['transient_ttl'] as $ttl ) { ok( 600 === $ttl, 'el límite caduca en 10 min' ); }

update_option( 'cvd_encuesta_estado', 'abierta' );
$wpdb->inserted = array(); $GLOBALS['transients'] = array();
$r = CVD_Encuesta::save( array( 'papel' => 'gestora', 'web' => 'http://spam', 'r' => $raw ), false, '1.1.1.1' );
ok( $r['ok'] && ! $wpdb->inserted, 'campo trampa: dice ok pero no guarda' );
$r = CVD_Encuesta::save( array( 'papel' => 'jefe', 'r' => $raw ), false, '1.1.1.1' );
ok( ! $r['ok'] && ! $wpdb->inserted, 'sin papel válido: no se guarda' );
$r = CVD_Encuesta::save( array( 'papel' => 'tienda', 'origen' => 'raro', 'r' => array( 'c1' => '3' ) ), false, '1.1.1.1' );
ok( $r['ok'] && '' === $wpdb->inserted[0]['data']['origen'] && 0 === $wpdb->inserted[0]['data']['prueba'], 'origen inventado queda vacío; abierta y sin clave = real' );
for ( $i = 0; $i < 6; $i++ ) { $last = CVD_Encuesta::save( array( 'papel' => 'tienda', 'r' => array() ), false, '9.9.9.9' ); }
ok( ! $last['ok'] && 429 === $last['code'], 'límite: el 6.º envío en el mismo minuto con la misma IP se frena' );
ok( CVD_Encuesta::save( array( 'papel' => 'tienda', 'r' => array() ), false, '8.8.8.8' )['ok'], 'otra IP sigue pudiendo' );
$wpdb->inserted = array();
$r = CVD_Encuesta::save( array( 'papel' => 'mensajero', 'tipo' => 'nota', 'nota' => '  Lo de la hora de noche ' ), false, '7.7.7.7' );
$n = $wpdb->inserted[0]['data'];
ok( $r['ok'] && 'nota' === $n['tipo'] && '{"nota":"Lo de la hora de noche"}' === $n['respuestas'], 'nota aparte («¿quieres añadir algo?»)' );
ok( ! CVD_Encuesta::save( array( 'papel' => 'mensajero', 'tipo' => 'nota', 'nota' => '  ' ), false, '7.7.7.7' )['ok'], 'nota vacía: no' );
ok( CVD_Encuesta::is_test( 'abcdefghijabcdef' ) && ! CVD_Encuesta::is_test( '' ) && ! CVD_Encuesta::is_test( 'otra' ), 'clave de prueba' );
$GLOBALS['opts']['cvd_encuesta_clave_prueba'] = '';
ok( ! CVD_Encuesta::is_test( '' ), 'sin clave configurada no hay modo prueba' );
$GLOBALS['opts']['cvd_encuesta_clave_prueba'] = 'abcdefghijabcdef';

/* ------------------------------------------------------------ estadísticas y NPS */
$nps = CVD_Encuesta_Def::nps( array( 10, 9, 8, 7, 6, 0 ) );
ok( 0.0 === (float) $nps['nps'] && 33.3 === $nps['promotores'] && 33.3 === $nps['detractores'] && 33.3 === $nps['pasivos'], 'NPS: 2 de 9-10 y 2 de 0-6 de 6 → 0' );
ok( 100.0 === (float) CVD_Encuesta_Def::nps( array( 9, 10 ) )['nps'] && -100.0 === (float) CVD_Encuesta_Def::nps( array( 0, 5, 6 ) )['nps'], 'NPS +100 y −100' );
ok( 25.0 === (float) CVD_Encuesta_Def::nps( array( 10, 10, 8, 3 ) )['nps'], 'NPS 50 % − 25 % = 25' );
ok( null === CVD_Encuesta_Def::nps( array() ), 'NPS sin datos: nada' );

$rows = array(
	array( 'papel' => 'gestora', 'origen' => 'general', 'tipo' => 'respuesta', 'respuestas' => array( 'p1' => 'mas_3m', 'a1' => 5, 'a5' => array( 'no_entiende', 'otra' ), 'a5_txt' => 'zeta', 'a12' => 'si_uso', 'a12a' => '8', 'a12b' => '1usd', 'a14' => 10, 'a15' => 'Más fotos' ) ),
	array( 'papel' => 'gestora', 'origen' => 'general', 'tipo' => 'respuesta', 'respuestas' => array( 'p1' => '1_3m', 'a1' => 3, 'a5' => array( 'no_entiende' ), 'a12' => 'oido', 'a12a' => '80', 'a12b' => 'no_se', 'a14' => 6, 'a15' => 'Antes que nada' ) ),
	array( 'papel' => 'gestora', 'origen' => '', 'tipo' => 'respuesta', 'respuestas' => array( 'p1' => 'mas_3m', 'a1' => 4, 'a5' => array( 'otra' ), 'a5_txt' => 'alfa', 'a12' => 'si_uso', 'a12a' => '8', 'a14' => 8 ) ),
	array( 'papel' => 'gestora', 'origen' => 'general', 'tipo' => 'respuesta', 'respuestas' => array( 'a12' => 'no' ) ),
	array( 'papel' => 'mensajero', 'origen' => 'mensajerias', 'tipo' => 'respuesta', 'respuestas' => array( 'b10' => 9 ) ),
	array( 'papel' => 'mensajero', 'origen' => 'mensajerias', 'tipo' => 'nota', 'respuestas' => array( 'nota' => 'Una cosa más' ) ),
);
$st = CVD_Encuesta_Def::stats( $rows );
ok( 5 === $st['total'] && 4 === $st['por_papel']['gestora'] && 1 === $st['por_papel']['mensajero'] && 0 === $st['por_papel']['tienda'], 'total por papel (la nota no cuenta como respuesta)' );
ok( 3 === $st['por_origen']['general'] && 1 === $st['por_origen']['mensajerias'] && 1 === $st['por_origen']['sin_enlace'], 'por origen' );
$g = $st['bloques']['gestora']['preguntas'];
ok( 3 === $g['a1']['n'] && 4.0 === (float) $g['a1']['media'] && 1 === $g['a1']['sin_responder'], 'escala 1–5: media 4 de 3 que contestaron' );
ok( 2 === $g['a5']['opciones']['no_entiende']['count'] && 66.7 === $g['a5']['opciones']['no_entiende']['pct'], 'multi: cuenta y % sobre quienes contestaron' );
ok( array( 'alfa', 'zeta' ) === $g['a5']['otras'], '«Otra» en orden alfabético' );
ok( 3 === $g['a12a']['se_pregunto_a'] && 3 === $g['a12a']['n'], '12a: se preguntó a 3 (las que conocen el programa)' );
ok( 2 === $g['a12a']['aciertos']['ok'] && 66.7 === $g['a12a']['aciertos']['pct'], '12a: 2 de 3 aciertan (66,7 %)' );
ok( 1 === $g['a12b']['aciertos']['ok'] && 50.0 === $g['a12b']['aciertos']['pct'] && 1 === $g['a12b']['sin_responder'], '12b: 1 de 2 («No sé» = fallo) y 1 sin responder' );
ok( 0.0 === (float) $g['a14']['nps']['nps'] && true === $g['a14']['es_recomendacion'], 'a14: NPS (10, 6, 8) = 33,3 − 33,3 = 0' );
ok( array( 'Antes que nada', 'Más fotos' ) === $g['a15']['textos'], 'abiertas en orden alfabético' );
ok( $st['bloques']['mensajero']['pocas'] && ! $st['bloques']['gestora']['pocas'] && ! $st['bloques']['tienda']['pocas'], 'aviso «pocas respuestas» con 1 o 2 (no con 0 ni con 4)' );
ok( 100.0 === (float) $st['bloques']['mensajero']['preguntas']['b10']['nps']['nps'], 'NPS mensajeros' );
ok( 1 === count( $st['notas'] ) && 'Una cosa más' === $st['notas'][0]['texto'], 'notas aparte' );
ok( false === $st['bloques']['tienda']['preguntas']['c7']['es_recomendacion'], 'c7 (0–10 de la tienda) no se presenta como recomendación' );

/* ------------------------------------------------------------ exportación JSON (sin las de prueba) */
$wpdb->rows = array(
	array( 'id' => 'aaaa', 'fecha' => '2026-10-17', 'papel' => 'gestora', 'origen' => 'general', 'version_encuesta' => CVD_Encuesta_Def::VERSION, 'tipo' => 'respuesta', 'prueba' => 0, 'respuestas' => '{"a14":9}' ),
	array( 'id' => 'bbbb', 'fecha' => '2026-10-17', 'papel' => 'tienda', 'origen' => 'privado', 'version_encuesta' => CVD_Encuesta_Def::VERSION, 'tipo' => 'respuesta', 'prueba' => 1, 'respuestas' => '{"c1":2}' ),
);
$j = json_decode( CVD_Encuesta::export_json(), true );
ok( is_array( $j ) && 1 === count( $j['filas'] ) && 'aaaa' === $j['filas'][0]['id'] && 9 === $j['filas'][0]['respuestas']['a14'], 'JSON: solo las reales, con respuestas' );
ok( 100.0 === (float) $j['estadisticas']['bloques']['gestora']['preguntas']['a14']['nps']['nps'] && 'abierta' === $j['estado'], 'JSON lleva estadísticas y estado' );
$jt = json_decode( CVD_Encuesta::export_json( true ), true );
ok( 1 === count( $jt['filas'] ) && 'bbbb' === $jt['filas'][0]['id'], 'JSON de las de prueba aparte' );
ok( 1 === CVD_Encuesta::borrar_pruebas() && 1 === count( $wpdb->rows ) && 'aaaa' === array_values( $wpdb->rows )[0]['id'], 'borrar pruebas: solo las de prueba' );

/* ------------------------------------------------------------ HTML del formulario */
$m = new ReflectionMethod( 'CVD_Encuesta', 'form' );
$m->setAccessible( true );
$html = $m->invoke( null, 'gestora', 'general', '' );
ok( false !== strpos( $html, 'name="papel" value="gestora"' ) && false === strpos( $html, 'data-q="papel"' ), '?p=gestora salta el paso 0' );
ok( false !== strpos( $html, 'data-q="a15"' ) && false === strpos( $html, 'data-q="b1"' ) && false === strpos( $html, 'data-q="c1"' ), 'solo el bloque A' );
ok( false !== strpos( $html, 'name="origen" value="general"' ), 'guarda de qué enlace vino' );
ok( false !== strpos( $html, 'name="web"' ), 'campo trampa' );
ok( false !== strpos( $html, 'data-if="a12:si_uso,oido"' ), '12a-c solo si conoce el programa' );
ok( false !== strpos( $html, 'No se guarda:</b> tu nombre, tu teléfono, tu cuenta, tu IP ni la hora' ), 'dice qué se guarda y qué no' );
ok( false === strpos( $html, 'correct' ) && false === strpos( $html, '1usd" data-correct' ), 'la respuesta correcta no viaja al teléfono' );
$html0 = $m->invoke( null, '', '', 'clave' );
ok( false !== strpos( $html0, 'data-q="papel"' ) && false !== strpos( $html0, 'data-q="b1"' ) && false !== strpos( $html0, 'data-q="c8"' ), 'sin ?p: paso 0 y los 3 bloques (el JS enseña solo el elegido)' );
ok( false !== strpos( $html0, '?prueba=clave' ) && false !== strpos( $html0, '_prueba"' ), 'modo prueba: envía con la clave y usa otra marca en el teléfono' );

echo $fails ? "\n$fails de $count comprobaciones fallaron\n" : "OK: $count comprobaciones\n";
exit( $fails ? 1 : 0 );
