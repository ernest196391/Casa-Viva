<?php
// Pruebas de Core 3.14.40 (fichas sin medidas ni características, Ernesto y Lennys 18-oct). Sin WordPress: stubs.
// php artifacts/tests/test-ficha-estado.php

define( 'ABSPATH', __DIR__ );

function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $s ): string { return preg_match( '#^https?://#', (string) $s ) ? (string) $s : ''; }
function sanitize_text_field( $s ): string { return trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $s ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_date( $f ): string { return '18-10-2026'; }
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
function is_wp_error( $x ): bool { return $x instanceof WP_Error; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['cvt_products'][ $id ]->meta[ $key ] ?? ''; }
function wc_get_product( $id ) { return $GLOBALS['cvt_products'][ (int) $id ] ?? false; }
class WP_Error { public string $code; public function __construct( $code = '', $msg = '', $data = array() ) { $this->code = $code; } }

class WC_Product {
	public array $meta = array();
	public int $saves = 0;
	public function __construct( public int $id, public string $name, public string $description = '', public string $sku = '', public string $type = 'simple' ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_description( $ctx = 'view' ): string { return $this->description; }
	public function set_description( $d ): void { $this->description = $d; }
	public function get_short_description(): string { return ''; }
	public function get_sku( $ctx = 'view' ): string { return $this->sku; }
	public function get_type(): string { return $this->type; }
	public function is_type( $t ): bool { return $this->type === $t; }
	public function get_parent_id(): int { return 0; }
	public function get_attributes(): array { return array(); }
	public function get_length() { return ''; }
	public function get_width() { return ''; }
	public function get_height() { return ''; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ): void { $this->meta[ $k ] = $v; }
	public function save(): int { $this->saves++; return $this->id; }
}

require_once __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-ficha-estado.php';

function cvt_check( bool $c, string $label ): void { if ( ! $c ) { fwrite( STDERR, "FALLO: {$label}\n" ); exit( 1 ); } echo "OK: {$label}\n"; }
function ev( string $name, string $desc = '', array $extra = array() ): array { return CVD_Ficha_Estado::evaluate( array( 'name' => $name, 'description' => $desc ) + $extra ); }

// --- Casos reales de la web (lectura del 18-oct) ---
$bici = '<p>Bicicleta eléctrica compacta y plegable, ideal para moverse por la ciudad sin gastar combustible y guardarla en poco espacio.</p><ul><li>Plegable: cabe en casa, en el maletero o detrás de la puerta.</li><li>Batería integrada en el cuadro.</li><li>Frenos de disco delante y detrás.</li><li>Color negro.</li><li>Autonomía, velocidad y batería: consultar.</li></ul><p>📍 Recógela en la tienda (Calle Conill A esq. 45 #864, Nuevo Vedado).</p>';
$e = ev( 'Bicicleta eléctrica plegable con asiento trasero', $bici );
cvt_check( 'falta' === $e['estado'], 'bici 4184: falta información' );
cvt_check( $e['electrico'], 'bici 4184: es eléctrica' );
cvt_check( in_array( 'medidas', $e['faltas'], true ) && in_array( 'tecnicas', $e['faltas'], true ), 'bici 4184: faltan medidas y datos técnicos' );
cvt_check( ! in_array( 'descripcion', $e['faltas'], true ) && ! in_array( 'material', $e['faltas'], true ), 'bici 4184: tiene descripción y no se le pide material (eléctrica)' );
cvt_check( array( 'Autonomía, velocidad y batería' ) === $e['pendientes'], 'bici 4184: «Autonomía, velocidad y batería: consultar» es pendiente' );
cvt_check( 'medidas, datos técnicos (potencia, voltaje o batería) y autonomía, velocidad y batería' === CVD_Ficha_Estado::list_text( $e['etiquetas'] ), 'bici 4184: texto del cartel' );

$e = ev( 'Protector de voltaje 220 V alta carga para aire acondicionado', '<p>Protector para equipos de 220 V: aire acondicionado y refrigeración.</p><p>Alta carga: 30 A, 5 HP.</p><p>Voltaje mínimo, máximo y tiempo de reconexión ajustables.</p><p>Marca Cowplandt. Se conecta por cables (instalación).</p><p>Te lo llevamos a casa en La Habana o lo recoges en la tienda.</p>' );
cvt_check( 'completa' === $e['estado'], 'protector 220 V 30 A 5 HP: completa' );

$e = ev( 'Protector de voltaje para equipos electrónicos (3 tomas)', '<p>Protege TV, computadoras, impresoras y otros equipos.</p><p>3 tomas laterales.</p><p>Luces de aviso: alto voltaje, bajo voltaje y voltaje normal.</p>' );
cvt_check( array( 'medidas', 'tecnicas' ) === $e['faltas'], 'protector de 3 tomas: «3 tomas» no son medidas; faltan medidas y datos técnicos' );

$e = ev( 'Adorno navideño merry christmas', '' );
cvt_check( array( 'descripcion', 'medidas', 'material' ) === $e['faltas'], 'adorno sin descripción: faltan descripción, medidas y material' );

$e = ev( 'Organizador de baño de metal, 3 niveles', '<p>Organizador de baño de metal blanco, de 3 baldas, para colocar sobre el inodoro. Tiene ganchos a los lados para colgar toallas.</p>' );
cvt_check( array( 'medidas' ) === $e['faltas'], 'organizador de metal: solo faltan medidas' );

$e = ev( 'Doyle Metalizado 45x30 cm', '<p>Doyle Metalizado Decorativo – 45 × 30 cm. Dale un toque elegante a la mesa y protege la superficie al mismo tiempo. Acabado: metalizado con textura en relieve.</p>' );
cvt_check( array( 'material' ) === $e['faltas'], 'doyle 45x30: medidas sí («metalizado» no es material); falta material' );

$e = ev( 'MOCHILA M-2', '<p>Mochila para lapto Mochila amplia y acolchada, ideal para trabajo, universidad y uso diario. Espacio para laptop y accesorios. Varios compartimentos con cierre.</p>' );
cvt_check( ! $e['electrico'], 'mochila «para laptop»: no es eléctrica' );
cvt_check( array( 'medidas', 'material' ) === $e['faltas'], 'mochila: faltan medidas y material' );

$e = ev( 'Set de cortina de baño + alfombra', '<p>Set completo para renovar el baño. Incluye: 1 cortina de baño de 180 × 180 cm, 1 tapete de PVC de 35 × 68 cm, 12 ganchos plásticos. Cortina impermeable.</p>' );
cvt_check( 'completa' === $e['estado'], 'set de cortina 180 × 180 cm de PVC: completa' );

$e = ev( 'Batería de cocina 10 piezas', '<p>Batería de cocina de acero inoxidable con 5 ollas y 5 tapas, de 16, 18, 20, 22 y 24 cm, para todo tipo de cocinas de la casa.</p>' );
cvt_check( ! $e['electrico'] && 'completa' === $e['estado'], 'batería de cocina: no es eléctrica y está completa' );

$e = ev( 'Mesita auxiliar', '<p>Mesita auxiliar de MDF color blanco con dos gavetas para la sala o el cuarto, fácil de armar en casa.</p>', array( 'dims' => array( 'length' => '40', 'width' => '30', 'height' => '' ) ) );
cvt_check( 'completa' === $e['estado'], 'mesita de MDF con medidas de WooCommerce: completa' );

$e = ev( 'Bata de baño', '<p>Bata de baño suave y absorbente de algodón, con cinturón y bolsillos, para después de la ducha en casa.</p>', array( 'attributes' => array( 'Talla' => array( 'M', 'L' ) ) ) );
cvt_check( 'completa' === $e['estado'], 'bata de algodón con atributo Talla: completa' );

$e = ev( 'Ventilador recargable F6 (verde)', '<p>El ventilador recargable F6 es la solución para el calor y los apagones. Lo cargas cuando hay corriente y lo usas donde lo necesites: en el cuarto, la sala o de viaje.</p>' );
cvt_check( $e['electrico'] && array( 'medidas', 'tecnicas' ) === $e['faltas'], 'ventilador F6 sin números: eléctrico, faltan medidas y datos técnicos' );

$e = ev( 'Platero', '<p>Platero escurridor para la cocina, con bandeja para el agua y espacio para cubiertos y vasos, de 2 niveles.</p><p>Material: por confirmar.</p>' );
cvt_check( in_array( 'material', $e['faltas'], true ) && array( 'Material' ) === $e['pendientes'], 'platero «Material: por confirmar»: falta material (y queda pendiente)' );

// Lectura de la web del 18-oct: falsos «eléctricos» por palabras de la descripción.
$e = ev( 'Alfombra decorativa de pelo alto 160 × 230 cm', '<p>Alfombra suave de pelo alto de poliéster para la sala o el cuarto. Se puede lavar en lavadora con agua fría y programa delicado.</p>' );
cvt_check( ! $e['electrico'] && 'completa' === $e['estado'], 'alfombra «lavar en lavadora»: no es eléctrica y está completa' );
$e = ev( 'Olla con tapa', '<p>Olla de aluminio con tapa de cristal, apta para inducción, gas y vitrocerámica. Capacidad de 5 litros para la familia.</p>' );
cvt_check( ! $e['electrico'] && 'completa' === $e['estado'], 'olla «apta para inducción»: no es eléctrica' );
$e = ev( 'Olla con tapa', '<p>Olla de aluminio con tapa. Capacidad: 4.5 litros. Compatible con cocinas de gas y eléctricas. Fabricada en aluminio resistente, distribuye el calor de forma uniforme.</p>' );
cvt_check( ! $e['electrico'] && 'completa' === $e['estado'], 'olla «compatible con cocinas eléctricas»: no es eléctrica' );
$e = ev( 'Mochila M-9', '<p>Mochila para laptop con puerto USB. Puerto USB exterior para conectar el móvil a una batería portátil guardada dentro. Varios compartimentos con cierre y bolsillos laterales.</p>' );
cvt_check( ! $e['electrico'], 'mochila con puerto USB y «batería portátil guardada dentro»: no es eléctrica' );
$e = ev( 'Lámpara de noche de cristal tipo hongo', '<p>Lámpara de mesa de cristal con forma de hongo, luz cálida para el cuarto, la sala o la mesita de noche, muy decorativa.</p>' );
cvt_check( $e['electrico'] && array( 'medidas', 'tecnicas' ) === $e['faltas'], 'lámpara (por el nombre): eléctrica, faltan medidas y datos técnicos' );
$e = ev( 'Shampoo 2 en 1', '<p>Champú y acondicionador en un solo paso para todo tipo de cabello, deja el pelo suave y con brillo, de uso diario para toda la familia.</p>' );
cvt_check( array( 'medidas' ) === $e['faltas'], 'champú: no se le pide material; falta el contenido (medidas)' );

$e = ev( 'Colgador de ropa', '<p>Colgador vertical. Altura: 170 cm. 4 brazos giratorios.</p>' );
cvt_check( in_array( 'descripcion', $e['faltas'], true ), 'colgador con 9 palabras propias: falta descripción' );

// --- Bloque «Características y medidas» ---
list( $html, $added ) = CVD_Ficha_Estado::append_block( $bici, array( 'Medidas plegada: 80 × 40 × 65 cm', '- Autonomía: 25 km', 'Batería: 48 V 10 Ah', 'Color negro' ), 'tienda', '18-10-2026' );
cvt_check( array( 'Medidas plegada: 80 × 40 × 65 cm', 'Autonomía: 25 km', 'Batería: 48 V 10 Ah' ) === $added, 'bloque: añade 3 líneas y no repite «Color negro» (ya estaba)' );
cvt_check( str_contains( $html, 'Autonomía, velocidad y batería: consultar' ), 'bloque: no borra nada de lo que había' );
cvt_check( str_contains( $html, '<h3>Características y medidas</h3>' ) && str_contains( $html, 'Confirmado por la tienda, 18-10-2026.' ), 'bloque: título y fuente con fecha' );
$e = ev( 'Bicicleta eléctrica plegable con asiento trasero', $html );
cvt_check( 'completa' === $e['estado'], 'bici 4184 con el bloque: completa (pendiente resuelto por «autonomía»)' );
list( $html2, $added2 ) = CVD_Ficha_Estado::append_block( $html, array( 'Autonomía: 25 km' ), 'tienda', '19-10-2026' );
cvt_check( array() === $added2 && $html2 === $html, 'bloque: lo mismo otra vez no cambia nada' );
list( $html3, $added3 ) = CVD_Ficha_Estado::append_block( $html, array( 'Velocidad máxima: 25 km/h' ), 'internet', '19-10-2026' );
cvt_check( 1 === substr_count( $html3, 'class="cvd-ficha-datos"' ) && str_contains( $html3, 'Datos del fabricante para este modelo' ), 'bloque: lo nuevo entra en el mismo bloque con su fuente' );
list( $h4 ) = CVD_Ficha_Estado::append_block( '<p>x</p>', array( '<script>alert(1)</script>Medidas: 10 cm' ), 'tienda', '18-10-2026' );
cvt_check( ! str_contains( $h4, '<script>' ) && str_contains( $h4, 'Medidas: 10 cm' ), 'bloque: sin etiquetas HTML de fuera' );

// --- Panel: texto → HTML ---
$html = CVD_Ficha_Estado::text_to_html( "Silla plegable de playa.\n- Medidas: 50 × 60 × 80 cm\n- Material: aluminio y tela\n\nAguanta 100 kg <b>" );
cvt_check( '<p>Silla plegable de playa.</p><ul><li>Medidas: 50 × 60 × 80 cm</li><li>Material: aluminio y tela</li></ul><p>Aguanta 100 kg &lt;b&gt;</p>' === $html, 'panel: líneas con «-» son lista, el resto párrafos, todo escapado' );

// --- Guardar (con producto de prueba en memoria) ---
$GLOBALS['cvt_products'] = array(
	4184 => new WC_Product( 4184, 'Bicicleta eléctrica plegable con asiento trasero', $bici ),
	900  => new WC_Product( 900, 'Split 1 tonelada', '<p>x</p>', 'MK-123' ),
	901  => new WC_Product( 901, 'Producto de prueba', '<p>x</p>' ),
);
$r = CVD_Ficha_Estado::save_lines( wc_get_product( 900 ), array( 'Medidas: 10 cm' ), 'tienda' );
cvt_check( is_wp_error( $r ) && 'cvd_ficha_excluido' === $r->code, 'guardar: los de proveedor (MK-) no se tocan' );
$r = CVD_Ficha_Estado::save_lines( wc_get_product( 901 ), array( 'Medidas: 10 cm' ), 'tienda' );
cvt_check( is_wp_error( $r ), 'guardar: los de prueba no se tocan' );
$r = CVD_Ficha_Estado::save_lines( wc_get_product( 4184 ), array( 'Autonomía: 25 km' ), 'internet', array( 'url' => 'https://ejemplo.com/bici' ) );
cvt_check( is_wp_error( $r ) && 'cvd_ficha_internet' === $r->code, 'guardar: internet sin modelo exacto no entra' );
$r = CVD_Ficha_Estado::save_lines( wc_get_product( 4184 ), array( 'Autonomía: 25 km' ), 'sugerido' );
cvt_check( is_wp_error( $r ) && 'cvd_ficha_fuente' === $r->code, 'guardar: «sugerido» nunca entra en la ficha' );
$r = CVD_Ficha_Estado::save_lines( wc_get_product( 4184 ), array( 'Medidas plegada: 80 × 40 × 65 cm', 'Autonomía: 25 km', 'Batería: 48 V 10 Ah' ), 'tienda', array( 'quien' => 'Nana', 'pregunta' => 'p123' ) );
$p = wc_get_product( 4184 );
cvt_check( ! is_wp_error( $r ) && 3 === count( $r['added'] ) && 'completa' === $r['ficha']['estado'], 'guardar: 3 datos de la tienda y la ficha queda completa' );
$hist = json_decode( $p->meta['_cvd_ficha_historial'], true );
cvt_check( 1 === count( $hist ) && 'Nana' === $hist[0]['quien'] && 'tienda' === $hist[0]['fuente'] && 'p123' === $hist[0]['pregunta'], 'guardar: historial con quién, fuente y pregunta' );
cvt_check( str_contains( $p->meta['_cvd_ficha_datos'], 'Autonomía: 25 km' ), 'guardar: datos en _cvd_ficha_datos para el catálogo del bot' );
cvt_check( ! str_contains( $p->description, 'Nana' ), 'guardar: el nombre de la dependienta no sale en la web' );
$saves = $p->saves;
$r = CVD_Ficha_Estado::save_lines( $p, array( 'Autonomía: 25 km' ), 'chat' );
cvt_check( array() === $r['added'] && $saves === $p->saves, 'guardar: repetido no guarda nada' );

echo "Todas las pruebas de la ficha pasaron.\n";
