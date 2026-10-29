<?php
// Prueba de CVD_Cierre::sign() / preview() con WordPress y WooCommerce simulados (sin base de datos real).
// Se corre con: php tests/cierre.test.php  (imprime «OK: n pruebas» o las que fallan).
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'CVD_VERSION', 'test' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_User { public $ID; public $display_name; public $roles; function __construct( $id, $name, $roles ) { $this->ID = $id; $this->display_name = $name; $this->roles = $roles; } }
class WC_Order { public $m; function __construct( public int $id, array $meta, public string $status = 'completed' ) { $this->m = $meta; } function get_id() { return $this->id; } function get_order_number() { return (string) $this->id; } function get_status() { return $this->status; } function get_meta( $k, $s = true ) { return $this->m[ $k ] ?? ''; } }
class WC_Order_Refund extends WC_Order {}
class CVD_Registration { static function is_clerk( $u ) { return in_array( 'cvd_clerk', (array) $u->roles, true ); } }
class CVD_Messenger_Cash { static function cuba_time( $utc, $f = 'H:i' ) { return $utc; } }
class CVD_Sales { static function can_view() { return true; } }

$USERS = array( 63 => new WP_User( 63, 'Nana', array( 'cvd_clerk' ) ), 64 => new WP_User( 64, 'Zaymi', array( 'cvd_clerk' ) ), 14 => new WP_User( 14, 'Lennys', array( 'administrator' ) ), 1 => new WP_User( 1, 'Ernesto', array( 'administrator' ) ) );
function get_userdata( $id ) { global $USERS; return $USERS[ $id ] ?? false; }
function get_users( $args ) { global $USERS; $out = array(); foreach ( $USERS as $u ) { if ( ( $args['role'] ?? '' ) === 'cvd_clerk' && in_array( 'cvd_clerk', $u->roles, true ) ) { $out[] = $u->ID; } if ( ( $args['capability'] ?? '' ) === 'manage_woocommerce' && in_array( 'administrator', $u->roles, true ) ) { $out[] = $u->ID; } } return $out; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wc_get_order_statuses() { return array( 'wc-completed' => 'x' ); }
$ORDERS = array();
function wc_get_orders( $args ) { global $ORDERS; return $ORDERS; }
function get_option( $k, $d = false ) { return $d; }
function update_option() {}
function current_user_can() { return true; }

class FakeDb {
	public $prefix = 'wp_'; public $rows = array(); public $insert_id = 0;
	function prepare( $sql, ...$args ) { $i = 0; return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return $m[0] === '%d' ? (string) (int) $v : "'" . $v . "'"; }, $sql ); }
	function insert( $t, $row ) { $this->insert_id = count( $this->rows ) + 1; $row['id'] = $this->insert_id; $row['corrige_id'] = $row['corrige_id'] ?? null; $this->rows[ $this->insert_id ] = $row; return 1; }
	private function live() { $ids = array_filter( array_column( $this->rows, 'corrige_id' ) ); return array_filter( $this->rows, fn( $r ) => ! in_array( $r['id'], $ids, true ) ); }
	function get_row( $q, $o = null ) {
		if ( str_contains( $q, 'c.is_test = 0' ) ) { preg_match( "/c.day <= '([^']+)'/", $q, $m ); $c = array_filter( $this->live(), fn( $r ) => ! $r['is_test'] && $r['day'] <= $m[1] ); usort( $c, fn( $a, $b ) => strcmp( $b['day'] . $b['signed_at'] . $b['id'], $a['day'] . $a['signed_at'] . $a['id'] ) ); return $c[0] ?? null; }
		if ( str_contains( $q, 'c.user_id =' ) ) { preg_match( "/c.user_id = (\d+) AND c.day = '([^']+)'/", $q, $m ); $c = array_values( array_filter( $this->live(), fn( $r ) => (int) $r['user_id'] === (int) $m[1] && $r['day'] === $m[2] ) ); return $c ? end( $c ) : null; }
		if ( preg_match( '/WHERE id = (\d+)/', $q, $m ) ) { return $this->rows[ (int) $m[1] ] ?? null; }
		return null;
	}
}
$wpdb = new FakeDb();

require_once __DIR__ . '/../includes/class-cvd-cierre-math.php';
require_once __DIR__ . '/../includes/class-cvd-cierre.php';

$fails = 0; $n = 0;
function check( string $name, $got, $want ): void {
	global $fails, $n; $n++;
	if ( $got !== $want ) { $fails++; echo "FALLA: {$name}\n  obtuve: " . json_encode( $got ) . "\n  quería: " . json_encode( $want ) . "\n"; }
}
function code( $r ) { return is_wp_error( $r ) ? $r->code : 'ok'; }

$day = CVD_Cierre_Math::day_of( gmdate( 'Y-m-d H:i:s' ) );
[ $from ] = CVD_Cierre_Math::window( $day );
$t1 = gmdate( 'Y-m-d H:i:s', strtotime( $from . ' UTC' ) + 3600 ); // una hora después de abrir la ventana
$ORDERS = array(
	new WC_Order( 7001, array( '_cvd_collection_method' => 'cash_usd', '_cvd_collection_amount_usd' => '35', '_cvd_collection_amount_cup' => '0', '_cvd_cash_verified_at' => $t1, '_cvd_cash_verified_by' => '64', '_cvd_fulfillment_type' => 'delivery' ) ),
	new WC_Order( 7002, array( '_cvd_collection_method' => 'cash_cup', '_cvd_collection_amount_usd' => '0', '_cvd_collection_amount_cup' => '4200', '_cvd_collection_received_at' => $t1, '_cvd_collection_received_by' => '64', '_cvd_fulfillment_type' => 'pickup', '_cvd_cash_status' => 'verified' ) ),
	new WC_Order( 7003, array( '_cvd_collection_method' => 'cash_usd', '_cvd_collection_amount_usd' => '40', '_cvd_collection_amount_cup' => '0', '_cvd_cash_verified_at' => $t1, '_cvd_cash_verified_by' => '63', '_cvd_fulfillment_type' => 'delivery' ) ),
	new WC_Order( 7004, array( '_cvd_collection_method' => 'transfer', '_cvd_collection_amount_usd' => '0', '_cvd_collection_amount_cup' => '12000', '_cvd_collection_account_name' => 'Clásica ·2690', '_cvd_cash_verified_at' => $t1, '_cvd_cash_verified_by' => '64', '_cvd_fulfillment_type' => 'delivery' ) ),
);

// Vista previa de Zaymi: solo lo suyo (35 USD + 4.200 MN); lo de Nana (40) aparece como «otra persona».
$p = CVD_Cierre::preview( 64, $day );
check( 'preview: USD de Zaymi', $p['cashTotals']['usd'], 3500 );
check( 'preview: MN de Zaymi', $p['cashTotals']['mn'], 4200 );
check( 'preview: no mezcla a Nana', array_column( $p['othersCajon'], 'name' ), array( 'Nana' ) );
check( 'preview: transferencia por tarjeta aparte', array( $p['byCard'][0]['name'], $p['byCard'][0]['cup'] ), array( 'Clásica ·2690', 12000 ) );
check( 'preview: sin cierre previo', $p['closing'], null );
check( 'preview: sin fondo sugerido', $p['suggestedFund'], null );

// Firma correcta: fondo 257 USD + 35 = 292 USD; 4.200 MN. Billetes: 2x100 + 4x20 + 1x10 + 2x1 = 292.
$ok = array( 'day' => $day, 'fund' => array( 'usd' => 257, 'mn' => 0 ), 'keep' => array( 'usd' => 257, 'mn' => 0 ), 'outs' => array(),
	'bills' => array( 'usd' => array( '100' => 2, '50' => 1, '20' => 2, '1' => 2 ), 'mn' => array( '2000' => 2, '100' => 2 ) ), 'note' => '' );
$r = CVD_Cierre::sign( 64, $ok );
check( 'firma cuadrada', code( $r ), 'ok' );
check( 'firma: diferencia 0', array( $r['settle']['usd']['diff'], $r['settle']['mn']['diff'] ), array( 0, 0 ) );
check( 'firma: guardó fila', count( $wpdb->rows ), 1 );
check( 'firma: no es de prueba', (int) $wpdb->rows[1]['is_test'], 0 );
check( 'firma: lista los pedidos suyos', json_decode( $wpdb->rows[1]['detail'], true )['orders'], array( 7001, 7002, 7004 ) );
check( 'firma: guarda total de transferencias', (int) $wpdb->rows[1]['transfer_cup'], 12000 );
check( 'firma: entrega a Lennys', json_decode( $wpdb->rows[1]['detail'], true )['deliverToAdmin'], array( 'usd' => 3500, 'mn' => 4200 ) );

// Otra vez el mismo día sin decir que corrige → rechazado; con corrección sin motivo → rechazado; con motivo → se guarda aparte.
check( 'segunda firma sin corregir', code( CVD_Cierre::sign( 64, $ok ) ), 'cvd_cierre_exists' );
check( 'corrección sin motivo', code( CVD_Cierre::sign( 64, $ok + array( 'corrects' => 1 ) ) ), 'cvd_cierre_correct_note' );
$fix = CVD_Cierre::sign( 64, array_merge( $ok, array( 'corrects' => 1, 'note' => 'Conté mal un billete' ) ) );
check( 'corrección con motivo', code( $fix ), 'ok' );
check( 'corrección apunta a la anterior', (int) $wpdb->rows[2]['corrige_id'], 1 );
check( 'la anterior sigue guardada', isset( $wpdb->rows[1] ), true );

// Nana (otra persona): el fondo debe ser el que dejó el último cierre (257 USD / 0 MN). Si no, pide nota.
$nana = array( 'day' => $day, 'fund' => array( 'usd' => 200, 'mn' => 0 ), 'keep' => array( 'usd' => 200, 'mn' => 0 ), 'outs' => array(), 'bills' => array( 'usd' => array( '100' => 2, '20' => 2 ), 'mn' => array() ), 'note' => '' );
check( 'fondo distinto sin nota', code( CVD_Cierre::sign( 63, $nana ) ), 'cvd_cierre_fund_note' );
$nana['fund']['usd'] = 257; $nana['bills']['usd'] = array( '100' => 2, '50' => 1, '20' => 2, '5' => 1, '1' => 2 ); // 257 + 40 = 297 → 200+50+40+5+2 = 297
$nana['keep']['usd'] = 257;
check( 'Nana cuadra con el fondo de Zaymi', code( CVD_Cierre::sign( 63, $nana ) ), 'ok' );

// No cuadra → exige nota; con nota guarda la diferencia.
$short = array( 'day' => $day, 'fund' => array( 'usd' => 0, 'mn' => 0 ), 'keep' => array( 'usd' => 0, 'mn' => 0 ), 'outs' => array(), 'bills' => array( 'usd' => array( '20' => 1 ), 'mn' => array() ), 'note' => '' );
// Ernesto (1) firma de PRUEBA: no tiene cobros → esperado 0, contó 20 → sobran
check( 'no cuadra sin nota', code( CVD_Cierre::sign( 1, $short ) ), 'cvd_cierre_note' );
$short['note'] = 'prueba';
$t = CVD_Cierre::sign( 1, $short );
check( 'no cuadra con nota guarda', code( $t ), 'ok' );
check( 'sobran 20 USD', $t['settle']['usd']['diff'], 2000 );
check( 'firma de Ernesto es de prueba', (int) $wpdb->rows[ $t['id'] ]['is_test'], 1 );

// Quién no puede firmar y datos mal puestos.
check( 'Lennys no firma', code( CVD_Cierre::sign( 14, $ok ) ), 'cvd_cierre_who' );
check( 'día futuro', code( CVD_Cierre::sign( 64, array_merge( $ok, array( 'day' => '2099-01-01' ) ) ) ), 'cvd_cierre_future' );
check( 'día roto', code( CVD_Cierre::sign( 64, array_merge( $ok, array( 'day' => 'hoy' ) ) ) ), 'cvd_cierre_day' );

// Fondo que deja más de lo contado.
$rows_before = count( $wpdb->rows );
$over = array_merge( $short, array( 'keep' => array( 'usd' => 500, 'mn' => 0 ) ) );
check( 'fondo mayor que lo contado', code( CVD_Cierre::sign( 1, $over ) ), 'cvd_cierre_keep' );
check( 'no se guardó nada al fallar', count( $wpdb->rows ), $rows_before );

// Salidas: un vuelto de 5 USD baja lo que debería haber. Zaymi corrige: 292 − 5 = 287.
$withOut = array_merge( $ok, array( 'corrects' => 2, 'note' => 'Salió un vuelto', 'outs' => array( array( 'why' => 'Vuelto', 'usd' => 5, 'mn' => 0 ) ),
	'bills' => array( 'usd' => array( '100' => 2, '50' => 1, '20' => 1, '10' => 1, '5' => 1, '1' => 2 ), 'mn' => array( '2000' => 2, '100' => 2 ) ) ) );
$wo = CVD_Cierre::sign( 64, $withOut );
check( 'salida baja lo esperado', array( code( $wo ), $wo['settle']['usd']['expected'], $wo['settle']['usd']['diff'] ), array( 'ok', 28700, 0 ) );

echo ( $fails ? "{$fails} de {$n} pruebas FALLARON\n" : "OK: {$n} pruebas\n" );
