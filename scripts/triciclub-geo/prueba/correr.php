<?php
/**
 * Prueba de Triciclub geo en PHP de consola, con las clases de verdad del Core y unos «stubs» mínimos de WordPress.
 *   php correr.php <carpeta uploads> <class-cvd-triciclub-geo.php> <class-cvd-geo-direccion.php> <class-cvd-triciclub-api.php> <casos.json> > resultados.json
 * OSRM: el del VPS (127.0.0.1:5000), igual que la web pero sin pasar por internet. Sin caché (transients) entre casos.
 */
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
[ , $UP, $GEO, $DIR, $API, $CASOS ] = $argv;
$GLOBALS['tr'] = array();
function wp_upload_dir( $a = null, $b = true ) { return array( 'basedir' => $GLOBALS['UP'] ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return 'cvd_tc_ruta_api' === $k ? 'http://127.0.0.1:5000' : $d; }
function is_wp_error( $x ) { return false === $x; }
function wp_remote_get( $url, $args = array() ) {
	$c = curl_init( $url );
	curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3 ) );
	$b = curl_exec( $c );
	curl_close( $c );
	return false === $b ? false : array( 'body' => $b );
}
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function remove_accents( $s ) {
	static $t = null;
	$t = $t ?: Transliterator::create( 'Any-Latin; Latin-ASCII' );
	return $t->transliterate( (string) $s );
}
final class CVD_Palla_Api {
	public static function meters( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$r = 6371000.0; $dlat = deg2rad( $lat2 - $lat1 ); $dlon = deg2rad( $lon2 - $lon1 );
		$a = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
		return 2 * $r * asin( min( 1.0, sqrt( $a ) ) );
	}
}
// localizar() sacado tal cual del class-cvd-triciclub-api.php que se prueba (el resto de la clase necesita WordPress).
preg_match( '/\n(\t\/\*\*[^\n]*\n)?\tpublic static function localizar\(.*?\n\t\}\n/s', file_get_contents( $API ), $mm );
eval( 'final class CVD_Triciclub_Api {' . $mm[0] . '}' );
require $DIR;
require $GEO;

/** Como una petición nueva de PHP-FPM: sin datos cargados ni caché. */
function reiniciar() {
	$GLOBALS['tr'] = array();
	foreach ( array( 'CVD_Triciclub_Geo', 'CVD_Geo_Direccion' ) as $cls ) {
		$r = new ReflectionClass( $cls );
		$def = $r->getDefaultProperties();
		foreach ( array_keys( $r->getStaticProperties() ) as $k ) {
			$p = $r->getProperty( $k );
			$p->setAccessible( true );
			$p->setValue( null, $def[ $k ] ?? null );
		}
	}
}
function ms( $t ) { return round( ( microtime( true ) - $t ) * 1000, 1 ); }

$casos = json_decode( file_get_contents( $CASOS ), true );
$out = array( 'donde' => array(), 'texto' => array(), 'sugerir' => array(), 'carga' => array() );
// Lo que cuesta solo cargar los archivos (cada petición lo hace una vez).
reiniciar(); $t = microtime( true ); CVD_Triciclub_Geo::barrio( 23.1, -82.4 ); $out['carga']['calles_ms'] = ms( $t );
$out['carga']['memoria_mb'] = round( memory_get_peak_usage() / 1e6, 1 );
foreach ( $casos['donde'] as $c ) {
	reiniciar(); CVD_Triciclub_Geo::calle_osrm( $c['lat'], $c['lng'] ); // OSRM aparte: mide nuestro código, no la red
	$keep = $GLOBALS['tr'];
	reiniciar(); $GLOBALS['tr'] = $keep;
	$t = microtime( true );
	$r = CVD_Triciclub_Geo::donde( $c['lat'], $c['lng'] );
	$out['donde'][] = array( 'id' => $c['id'], 'texto' => $r['texto'] ?? null, 'barrio' => $r['barrio'] ?? null, 'ms' => ms( $t ) );
}
foreach ( $casos['texto'] as $c ) {
	reiniciar(); $t = microtime( true );
	$p = CVD_Triciclub_Api::localizar( $c['texto'], $c['zona'] ?? '', $c['cerca'] ?? null );
	$out['texto'][] = array( 'id' => $c['id'], 'lat' => $p['lat'] ?? null, 'lng' => $p['lon'] ?? null, 'how' => $p['how'] ?? null, 'ms' => ms( $t ) );
}
foreach ( $casos['sugerir'] as $c ) {
	reiniciar(); $t = microtime( true );
	$l = CVD_Triciclub_Geo::sugerir( $c['texto'], $c['lat'] ?? null, $c['lng'] ?? null );
	$out['sugerir'][] = array( 'id' => $c['id'], 'lista' => array_map( static fn( $s ) => array( $s['texto'], $s['sub'], $s['tipo'] ), $l ), 'ms' => ms( $t ) );
}
$out['carga']['memoria_pico_mb'] = round( memory_get_peak_usage() / 1e6, 1 );
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
