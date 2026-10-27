<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.65 «Ficha que contesta» (mejora nº 7 del diagnóstico del 23-oct, aprobada por Ernesto; D107).
 *
 * El problema (P2 del diagnóstico): un tercio de los errores del bot son preguntas de producto. Cada respuesta de Nana
 * o Zaymi se quedaba en un chat y la misma pregunta se volvía a hacer. Ahora:
 *
 * 1) GESTORA (página del producto con su sesión; también la abre desde «Preguntas a la tienda» de su app):
 *    bloque «Datos de la tienda» con lo fijo de la ficha (medidas, material, voltaje/carga/cable, qué trae, colores y
 *    modelos que quedan, garantía si la hay, fotos reales) y el estado (🏪 hay N / agotado / 📦 bajo pedido · llega en 24 h).
 *    Si falta algo: «Preguntar a la tienda» con 6 opciones (¿Medidas? · ¿Material? · ¿Voltaje/cable? · ¿Qué trae? ·
 *    ¿Colores? · ¿Foto real?) y texto libre. Cada pregunta queda ATADA al producto (y a la variante si la eligió).
 *    Si otra gestora ya preguntó lo mismo, se suma a esa pregunta (se agrupa): la tienda contesta una sola vez.
 * 2) TIENDA (Nana o Zaymi, la de turno): página ligera con enlace firmado y sin contraseña (?cvd_tienda_fichas=1&t=…,
 *    3 días, mismo patrón que CVD_Messenger_Delivered_Link). Lista de preguntas abiertas con la foto del producto y un
 *    campo para cada respuesta (o subir la foto real). La respuesta se guarda EN LA FICHA:
 *      - medidas, material, voltaje y qué trae → CVD_Ficha_Estado::save_lines (bloque «Características y medidas» de la
 *        descripción, _cvd_ficha_datos y _cvd_ficha_historial, lo mismo que /bot/fichas/guardar y volcar-respuestas.mjs);
 *      - todo (también colores de hoy, otras preguntas y fotos) → _cvd_ficha_campos (tipo → valor, quién, cuándo) y el
 *        historial; los colores de hoy y las otras preguntas NO van a la descripción pública (cambian o son internas).
 * 3) AVISOS: al contestar, Core deja un aviso para CADA gestora que preguntó (tabla cvd_ficha_avisos). VivaBot
 *    (src/ficha-contesta.js) lo recoge con GET /bot/ficha-contesta/pendientes, lo manda (privado por el buzón; si
 *    preguntó en el General, en el General citando su mensaje), copia la respuesta a crm_knowledge y confirma con
 *    POST /bot/ficha-contesta/hecho. A la tienda: un aviso «hay N preguntas» con el enlace, a la de turno, como mucho
 *    uno cada 45 min. Preguntas abiertas más de 24 h: una vez a Ernesto.
 *    El bot del General, antes de preguntar a la tienda, lee GET /bot/ficha-contesta/productos y responde con la ficha;
 *    si falta, mete la pregunta en esta misma cola con POST /bot/ficha-contesta/preguntar.
 *
 * Interruptor: opción cvd_ficha_contesta = no (por defecto: todo como antes) | datos (solo el bloque) | si (bloque y
 * preguntas). Productos de proveedor (MK-, bajo pedido): bloque con su estado, pero sin «Preguntar a la tienda» (la
 * tienda no los tiene: se pregunta en el General y el bot lo consulta con el proveedor).
 * Pruebas: artifacts/tests/test-ficha-contesta.php (reglas puras, sin WordPress).
 */
final class CVD_Ficha_Contesta {
	public const OPTION      = 'cvd_ficha_contesta';
	public const META_CAMPOS = '_cvd_ficha_campos';
	public const META_FOTOS  = '_cvd_ficha_fotos';
	public const PARAM       = 'cvd_tienda_fichas';
	public const TTL         = 259200; // 3 días
	private const DB_VERSION = '1';
	private const NS         = 'casa-viva/v1';
	private const OLD_HOURS  = 24;
	private const STORE_GAP  = 2700; // 45 min entre avisos a la tienda

	/** Tipos de pregunta: botón, nombre del dato y si va a la descripción pública de la web. */
	public const TIPOS = array(
		'medidas'  => array( 'chip' => '¿Medidas?', 'label' => 'Medidas', 'web' => true ),
		'material' => array( 'chip' => '¿Material?', 'label' => 'Material', 'web' => true ),
		'voltaje'  => array( 'chip' => '¿Voltaje/cable?', 'label' => 'Voltaje, carga y cable', 'web' => true ),
		'trae'     => array( 'chip' => '¿Qué trae?', 'label' => 'Qué trae', 'web' => true ),
		'colores'  => array( 'chip' => '¿Colores?', 'label' => 'Colores y modelos', 'web' => false ),
		'foto'     => array( 'chip' => '¿Foto real?', 'label' => 'Foto real', 'web' => false ),
		'otra'     => array( 'chip' => 'Otra pregunta', 'label' => 'Otra pregunta', 'web' => false ),
	);
	/** Filas fijas del bloque (garantía solo sale si la ficha la dice). */
	public const FILAS = array( 'medidas', 'material', 'voltaje', 'trae', 'colores', 'garantia' );

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 6 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route_store_page' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_product_post' ), 5 );
		add_action( 'wp', array( __CLASS__, 'swap_notice' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_block' ), 26 );
	}

	/* ================================================================ reglas (puras, sin WordPress) */

	public static function mode_of( $raw ): string {
		$v = strtolower( trim( (string) $raw ) );
		$v = str_replace( 'sí', 'si', $v );
		return in_array( $v, array( 'no', 'datos', 'si' ), true ) ? $v : 'no';
	}

	public static function mode(): string { return self::mode_of( get_option( self::OPTION, 'no' ) ); }

	/** Minúsculas sin tildes (como CVD_Ficha_Estado::fold, que también se usa si existe). */
	public static function fold( string $s ): string {
		if ( class_exists( 'CVD_Ficha_Estado' ) ) { return CVD_Ficha_Estado::fold( $s ); }
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return strtr( $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u' ) );
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…' : $s;
	}

	private const MAT = 'plastico|plastica|metal|metalic[oa]s?|acero|inoxidable|aluminio|hierro|madera|mdf|melamina|bambu|vidrio|cristal|tela|algodon|poliester|lino|seda|saten|microfibra|terciopelo|felpa|lana|nylon|licra|pvc|silicona|goma|caucho|latex|ceramica|porcelana|cuero|piel sintetica|polipiel|mimbre|rattan|papel|carton|abs|polipropileno|eva|espuma|viscoelastic[oa]|marmol|resina|cobre|zinc|cromad[oa]|antiadherente|yute|peluche|fieltro|tejido|textil|lona|neopreno|engomad[oa]|relleno';

	/**
	 * A qué datos fijos pertenece una línea de la ficha. → lista de tipos (medidas, material, voltaje, trae, colores,
	 * garantia). Una línea puede ser de varios («40 × 65 cm; espuma viscoelástica»).
	 */
	public static function classify_line( string $line ): array {
		$f = self::fold( $line );
		$out = array();
		if ( preg_match( '/\d+(?:[.,]\d+)?\s*(?:cm|cms|mm|m|mts|metros?|pulgadas?|pulg|"|″|”|litros?|lts?|ml)(?![a-zñ])|\d+(?:[.,]\d+)?\s*(?:x|×|\*)\s*\d+|(?<![a-zñ])(?:medidas?|tamano|largo|ancho|alto|grosor|capacidad|talla)(?![a-zñ])/u', $f ) ) { $out[] = 'medidas'; }
		if ( preg_match( '/(?<![a-zñ])(?:' . self::MAT . ')(?![a-zñ])|(?<![a-zñ])material(?:es)?\s*:/u', $f ) ) { $out[] = 'material'; }
		if ( preg_match( '/\d+(?:[.,]\d+)?\s*(?:v|vac|vdc|w|watts?|vatios?|mah|ah|wh|hp|amperios?)(?![a-zñ])|(?<![a-zñ])(?:usb|tipo c|type-?c|cable|cargador|bateria|baterias|recargable|pilas|voltaje|autonomia|potencia|enchufe|carga)(?![a-zñ])/u', $f ) ) { $out[] = 'voltaje'; }
		if ( preg_match( '/^(?:trae|incluye|viene con|contiene|qu[eé] trae|el set|el juego)|(?<![a-zñ])(?:incluid[ao]s?|incluye|trae)(?![a-zñ])|\d+\s*piezas(?![a-zñ])/u', $f ) ) { $out[] = 'trae'; }
		if ( preg_match( '/(?<![a-zñ])colou?r(?:es)?(?![a-zñ])/u', $f ) ) { $out[] = 'colores'; }
		if ( preg_match( '/(?<![a-zñ])garantia(?![a-zñ])/u', $f ) ) { $out[] = 'garantia'; }
		return $out;
	}

	/** ¿La línea trae el dato exacto (con su número) o solo lo nombra? «Batería integrada» no dice cuántos voltios. */
	public static function line_is_exact( string $tipo, string $line ): bool {
		$f = self::fold( $line );
		if ( 'medidas' === $tipo ) { return 1 === preg_match( '/\d/', $f ); }
		if ( 'voltaje' === $tipo ) { return 1 === preg_match( '/\d+(?:[.,]\d+)?\s*(?:v|vac|vdc|w|watts?|vatios?|mah|ah|wh|hp|amperios?|horas?|h)(?![a-zñ])|usb|tipo c|type-?c/u', $f ); }
		return true;
	}

	/** Líneas que no son datos: envío, recogida, precio, «consultar». */
	public static function useless_line( string $line ): bool {
		$f = self::fold( $line );
		if ( mb_strlen( $f ) < 5 ) { return true; }
		return 1 === preg_match( '/^📍|te l[oa]s? llevamos|recoge(?:la|lo|las|los)? en la tienda|calle conill|mensajeria segun|precio\s*:|(?<![a-zñ])(?:consultar|por confirmar|a confirmar|sin confirmar)(?![a-zñ])/u', $f );
	}

	/**
	 * Los datos fijos de una ficha. $in = lines (textos: _cvd_ficha_datos y la descripción), campos (_cvd_ficha_campos),
	 * attrs [nombre => valores], dims [length,width,height,weight], variantes [[label, qty|null, in_stock]], electrico.
	 * → [ tipo => [ ['texto' => …, 'tienda' => bool, 'exacto' => bool, 'quien' => …, 'at' => …], … ] ].
	 */
	public static function datos_from( array $in ): array {
		$out = array_fill_keys( self::FILAS, array() );
		$seen = array();
		$push = static function ( string $tipo, array $row ) use ( &$out, &$seen ): void {
			if ( ! isset( $out[ $tipo ] ) || count( $out[ $tipo ] ) >= 3 ) { return; }
			$k = $tipo . '|' . self::fold( $row['texto'] );
			if ( isset( $seen[ $k ] ) ) { return; }
			$seen[ $k ] = true;
			$out[ $tipo ][] = $row + array( 'tienda' => false, 'exacto' => true, 'quien' => '', 'at' => '' );
		};
		// 1) Lo que contestó la tienda (manda).
		foreach ( (array) ( $in['campos'] ?? array() ) as $tipo => $c ) {
			if ( ! is_array( $c ) || '' === trim( (string) ( $c['valor'] ?? '' ) ) ) { continue; }
			$tipo = 'trae' === $tipo || isset( $out[ $tipo ] ) ? $tipo : '';
			if ( '' === $tipo ) { continue; }
			$txt = self::clip( (string) $c['valor'], 220 );
			if ( 'colores' === $tipo && ! empty( $c['at'] ) ) { $txt .= ' (' . self::short_date( (string) $c['at'] ) . ', puede cambiar)'; }
			$push( $tipo, array( 'texto' => $txt, 'tienda' => true, 'exacto' => true, 'quien' => (string) ( $c['quien'] ?? '' ), 'at' => (string) ( $c['at'] ?? '' ) ) );
		}
		// 2) Colores y modelos que quedan hoy (variantes de la web con existencias).
		$vars = array();
		foreach ( (array) ( $in['variantes'] ?? array() ) as $v ) {
			if ( empty( $v[2] ) || '' === trim( (string) $v[0] ) ) { continue; }
			$vars[] = trim( (string) $v[0] ) . ( null !== ( $v[1] ?? null ) ? ' (' . (int) $v[1] . ')' : '' );
		}
		if ( $vars ) { $push( 'colores', array( 'texto' => 'Quedan: ' . self::clip( implode( ', ', $vars ), 200 ) ) ); }
		// 3) Atributos y dimensiones de WooCommerce.
		foreach ( (array) ( $in['attrs'] ?? array() ) as $name => $values ) {
			$values = array_filter( array_map( 'strval', (array) $values ) );
			if ( ! $values ) { continue; }
			$txt = self::clip( $name . ': ' . implode( ', ', $values ), 200 );
			$f = self::fold( (string) $name );
			if ( preg_match( '/colou?r|modelo|diseno|estampado/u', $f ) ) { continue; } // ya salen en «Quedan» (o son de variantes agotadas)
			$tipos = preg_match( '/medida|tamano|talla|capacidad|dimension/u', $f ) ? array( 'medidas' ) : ( preg_match( '/material|tela|tejido/u', $f ) ? array( 'material' ) : ( preg_match( '/voltaje|potencia|bateria|cable/u', $f ) ? array( 'voltaje' ) : self::classify_line( $txt ) ) );
			foreach ( $tipos as $t ) { $push( $t, array( 'texto' => $txt, 'exacto' => self::line_is_exact( $t, $txt ) ) ); }
		}
		$d = array_filter( array_map( 'floatval', array_intersect_key( (array) ( $in['dims'] ?? array() ), array_flip( array( 'length', 'width', 'height' ) ) ) ) );
		if ( $d ) { $push( 'medidas', array( 'texto' => implode( ' × ', array_map( static fn( $x ) => rtrim( rtrim( number_format( $x, 1, ',', '' ), '0' ), ',' ), $d ) ) . ' cm (paquete)' ) ); }
		// 4) Las líneas de la ficha (datos confirmados y descripción).
		// Primero las líneas con su nombre delante («Medidas: …», «Tela: …»), luego las frases de la descripción.
		$lines = array_map( 'strval', (array) ( $in['lines'] ?? array() ) );
		usort( $lines, static fn( $a, $b ) => (int) ! preg_match( '/^[^:.]{2,28}:/u', $a ) <=> (int) ! preg_match( '/^[^:.]{2,28}:/u', $b ) );
		$from_lines = array(); // como mucho 2 frases de la descripción por fila (que se lea en el móvil)
		foreach ( $lines as $line ) {
			$line = self::clip( (string) preg_replace( '/^[\-•·*✔️✅]+\s*/u', '', (string) $line ), 200 );
			if ( self::useless_line( $line ) ) { continue; }
			foreach ( self::classify_line( $line ) as $t ) {
				if ( ( $from_lines[ $t ] ?? 0 ) >= 2 ) { continue; }
				$before = count( $out[ $t ] ?? array() );
				$push( $t, array( 'texto' => $line, 'exacto' => self::line_is_exact( $t, $line ) ) );
				if ( count( $out[ $t ] ?? array() ) > $before ) { $from_lines[ $t ] = ( $from_lines[ $t ] ?? 0 ) + 1; }
			}
		}
		return $out;
	}

	/** Qué filas enseñar: medidas, material (no en aparatos), voltaje (solo aparatos), qué trae, colores; garantía si hay. */
	public static function rows_for( array $datos, bool $electrico ): array {
		$rows = array( 'medidas' );
		if ( ! $electrico || $datos['material'] ) { $rows[] = 'material'; }
		if ( $electrico || $datos['voltaje'] ) { $rows[] = 'voltaje'; }
		array_push( $rows, 'trae', 'colores' );
		if ( $datos['garantia'] ) { $rows[] = 'garantia'; }
		return $rows;
	}

	/** Lo que falta (para marcar las opciones): fila sin datos o, en medidas y voltaje, sin el número exacto. */
	public static function missing( array $datos, bool $electrico, int $fotos ): array {
		$out = array();
		foreach ( self::rows_for( $datos, $electrico ) as $t ) {
			if ( 'garantia' === $t ) { continue; }
			$rows = $datos[ $t ] ?? array();
			if ( ! $rows || ! array_filter( $rows, static fn( $r ) => ! empty( $r['exacto'] ) ) ) { $out[] = $t; }
		}
		if ( $fotos < 1 ) { $out[] = 'foto'; }
		return $out;
	}

	/** Estado de existencias para la gestora. $in = bajo_pedido, in_stock, qty (null = sin contar). → [code, texto]. */
	public static function stock_state( array $in ): array {
		if ( ! empty( $in['bajo_pedido'] ) ) { return array( 'bajo_pedido', '📦 ' . ( class_exists( 'CVD_Datos_A_Mano' ) ? CVD_Datos_A_Mano::BAJO_PEDIDO : 'Bajo pedido · llega en 24 h' ) ); } // 3.14.65: misma frase que la web (3.14.63)
		if ( empty( $in['in_stock'] ) ) { return array( 'agotado', 'Agotado ahora' ); }
		$qty = $in['qty'] ?? null;
		if ( null !== $qty && (int) $qty > 0 ) { return array( 'hay', '🏪 Hay ' . (int) $qty . ' en la tienda' ); }
		return array( 'hay', '🏪 Hay en la tienda' );
	}

	/** Clave para agrupar la misma pregunta: producto + variante + tipo (en «otra», sus palabras importantes). */
	public static function group_key( int $pid, int $vid, string $tipo, string $texto = '' ): string {
		$extra = '';
		if ( 'otra' === $tipo ) {
			preg_match_all( '/[a-zñ0-9]{3,}/u', self::fold( $texto ), $m );
			$stop = array( 'que', 'como', 'cual', 'cuales', 'tiene', 'tienen', 'trae', 'son', 'esta', 'este', 'estos', 'estas', 'hay', 'por', 'para', 'con', 'los', 'las', 'del', 'una', 'uno', 'favor', 'saber', 'quiero', 'cliente', 'pregunta', 'puede', 'pueden', 'sirve', 'buenas', 'hola', 'gracias' );
			$w = array_values( array_unique( array_diff( $m[0], $stop ) ) );
			sort( $w );
			$extra = implode( ' ', array_slice( $w, 0, 6 ) );
		}
		return hash( 'sha256', $pid . '|' . $vid . '|' . $tipo . '|' . $extra );
	}

	/** Suma una persona a la pregunta (sin repetir: mismo usuario o mismo teléfono). → [askers, nueva]. */
	public static function add_asker( array $askers, array $a ): array {
		foreach ( $askers as $x ) {
			if ( ( ! empty( $a['u'] ) && (int) ( $x['u'] ?? 0 ) === (int) $a['u'] ) || ( ! empty( $a['p'] ) && (string) ( $x['p'] ?? '' ) === (string) $a['p'] ) ) { return array( $askers, false ); }
		}
		$askers[] = $a;
		return array( $askers, true );
	}

	/** La línea que queda en la ficha. «Medidas: 45 × 70 cm.» · variante delante: «(Gris) Medidas: …». */
	public static function answer_line( string $tipo, string $valor, string $pregunta = '', string $variante = '', string $fecha = '' ): string {
		$valor = rtrim( self::clip( $valor, 250 ), '. ' );
		if ( '' === $valor ) { return ''; }
		$pre = '' !== $variante ? '(' . $variante . ') ' : '';
		if ( 'otra' === $tipo ) {
			$q = rtrim( self::clip( $pregunta, 120 ), ' ?¿' );
			$q = '' !== $q ? '¿' . ltrim( $q, '¿' ) . '? ' : '';
			return $pre . $q . $valor . '.';
		}
		if ( 'foto' === $tipo ) { return $pre . 'Foto real de la tienda' . ( '' !== $fecha ? ' (' . $fecha . ')' : '' ) . ( '' !== $valor && 'foto' !== self::fold( $valor ) ? ': ' . $valor : '' ) . '.'; }
		$label = 'trae' === $tipo ? 'Trae' : ( self::TIPOS[ $tipo ]['label'] ?? 'Dato' );
		if ( 'colores' === $tipo ) { $label = 'Colores' . ( '' !== $fecha ? ' (hoy, ' . $fecha . ')' : '' ); }
		return $pre . $label . ': ' . $valor . '.';
	}

	public static function short_date( string $iso ): string {
		$t = strtotime( $iso );
		return $t ? gmdate( 'd/m', $t - 4 * 3600 ) : '';
	}

	/** Texto del aviso a la gestora cuando la tienda contesta. */
	public static function answer_notice( string $first, string $producto, string $line, string $url, int $others = 0 ): string {
		$hi = '' !== $first ? $first . ' 🙏 ' : '🙏 ';
		return $hi . 'La tienda contestó lo que preguntaste de *' . $producto . "*:\n" . $line
			. ( 1 === $others ? "\n(También lo preguntó otra gestora.)" : ( $others > 1 ? "\n(También lo preguntaron " . $others . ' gestoras más.)' : '' ) )
			. "\nYa está en la ficha para todas 👉 " . $url;
	}

	/** Aviso a la dependienta de turno ({quien} lo pone el bot con su nombre). */
	public static function store_notice( int $open, int $people, string $url ): string {
		return 'Hola {quien} 👋 Hay ' . $open . ( 1 === $open ? ' pregunta' : ' preguntas' ) . ' de gestoras sobre productos'
			. ( $people > 1 ? ' (' . $people . ' gestoras esperando)' : '' ) . ".\nSe contesta en un toque, sin contraseña:\n" . $url
			. "\nLo que escribas queda en la ficha y les llega a todas las que preguntaron 🙏";
	}

	// ---------------------------------------------------------------- enlace firmado de la tienda

	public static function sign( int $expires, string $key ): string {
		return $expires . '-' . substr( hash_hmac( 'sha256', 'cvd-ficha-tienda|' . $expires, $key ), 0, 24 );
	}

	public static function verify( string $token, int $now, string $key ): bool {
		if ( ! preg_match( '/^(\d{9,11})-([a-f0-9]{24})$/', $token, $m ) || (int) $m[1] < $now ) { return false; }
		return hash_equals( self::sign( (int) $m[1], $key ), $token );
	}

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-ficha-tienda'; }

	/** Enlace para la tienda (válido 3 días). {quien} queda para que el bot ponga el nombre de la de turno. */
	public static function store_url( ?int $now = null ): string {
		$t = self::sign( ( $now ?? time() ) + self::TTL, self::key() );
		return add_query_arg( array( self::PARAM => 1, 't' => $t ), home_url( '/' ) ) . '&quien={quien}';
	}

	/* ================================================================ tablas */

	private static function t( string $name ): string { global $wpdb; return $wpdb->prefix . 'cvd_ficha_' . $name; }

	public static function maybe_install(): void {
		if ( get_option( 'cvd_ficha_contesta_db' ) === self::DB_VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'preguntas' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tipo varchar(16) NOT NULL,
			clave char(64) NOT NULL,
			texto text NULL,
			status varchar(16) NOT NULL DEFAULT 'abierta',
			askers longtext NULL,
			asks int(11) NOT NULL DEFAULT 1,
			test tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			answered_at datetime NULL,
			answered_by varchar(64) NOT NULL DEFAULT '',
			answer text NULL,
			photo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			web tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime NULL,
			knowledge_id bigint(20) NULL,
			old_notified_at datetime NULL,
			PRIMARY KEY  (id),
			KEY open_clave (status, clave),
			KEY product_status (product_id, status),
			KEY sync (status, synced_at)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'avisos' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pregunta_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(16) NOT NULL,
			role varchar(16) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			chat_jid varchar(80) NOT NULL DEFAULT '',
			msg_id varchar(80) NOT NULL DEFAULT '',
			participant varchar(80) NOT NULL DEFAULT '',
			quoted text NULL,
			photo_url varchar(255) NOT NULL DEFAULT '',
			body text NOT NULL,
			dedupe char(64) NOT NULL,
			test tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			notified_at datetime NULL,
			result varchar(24) NOT NULL DEFAULT '',
			note varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (dedupe),
			KEY pending (notified_at, created_at)
		) {$c};" );
		update_option( 'cvd_ficha_contesta_db', self::DB_VERSION, true );
	}

	private static function now(): string { return current_time( 'mysql', true ); }

	private static function row( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'preguntas' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::decode( $r ) : null;
	}

	private static function decode( array $r ): array {
		$r['askers'] = json_decode( (string) ( $r['askers'] ?? '' ), true ) ?: array();
		foreach ( array( 'id', 'product_id', 'variation_id', 'asks', 'test', 'photo_id', 'web' ) as $k ) { $r[ $k ] = (int) ( $r[ $k ] ?? 0 ); }
		return $r;
	}

	/** Preguntas con ese estado (las de prueba solo si $tests). */
	public static function rows( string $status = 'abierta', int $product_id = 0, bool $tests = false, int $limit = 100 ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::t( 'preguntas' ) . ' WHERE status = %s' . ( $product_id ? ' AND product_id = %d' : '' ) . ( $tests ? '' : ' AND test = 0' ) . ' ORDER BY ' . ( 'abierta' === $status ? 'created_at ASC' : 'answered_at DESC' ) . ' LIMIT %d';
		$args = $product_id ? array( $status, $product_id, $limit ) : array( $status, $limit );
		return array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) );
	}

	/* ================================================================ productos */

	private static function parent_of( WC_Product $p ): WC_Product {
		if ( $p->is_type( 'variation' ) && $p->get_parent_id() ) { $parent = wc_get_product( $p->get_parent_id() ); if ( $parent ) { return $parent; } }
		return $p;
	}

	public static function is_supplier( WC_Product $p ): bool {
		// 3.14.65: la misma regla que la línea de existencia de la web (MK-, artículo del mercado o categoría «Bajo pedido»).
		if ( class_exists( 'CVD_Datos_A_Mano' ) && CVD_Datos_A_Mano::is_bajo_pedido( $p ) ) { return true; }
		if ( class_exists( 'CVD_Costs' ) && CVD_Costs::is_bajo_pedido( $p ) ) { return true; }
		return 0 === stripos( (string) $p->get_sku( 'edit' ), 'MK-' );
	}

	public static function variation_label( WC_Product $v ): string {
		if ( ! $v->is_type( 'variation' ) ) { return ''; }
		$attrs = array_filter( array_map( 'strval', (array) $v->get_attributes() ) );
		$out = array();
		foreach ( $attrs as $tax => $slug ) {
			$term = taxonomy_exists( (string) $tax ) ? get_term_by( 'slug', $slug, (string) $tax ) : null;
			$out[] = $term ? $term->name : ucfirst( str_replace( '-', ' ', $slug ) );
		}
		return implode( ' · ', $out );
	}

	/** Todo lo que se sabe de la ficha, para el bloque, la tienda y el bot. */
	public static function view( WC_Product $product ): array {
		$product = self::parent_of( $product );
		$pid = $product->get_id();
		$supplier = self::is_supplier( $product );
		$variantes = array();
		$qty_sum = null;
		$in_stock = $product->is_in_stock();
		if ( $product->is_type( 'variable' ) ) {
			$in_stock = false;
			foreach ( $product->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v || 'publish' !== $v->get_status() ) { continue; }
				$q = $v->managing_stock() ? (int) $v->get_stock_quantity() : null;
				$ok = $v->is_in_stock() && ( null === $q || $q > 0 );
				$variantes[] = array( self::variation_label( $v ), $q, $ok, $cid );
				if ( $ok ) { $in_stock = true; if ( null !== $q ) { $qty_sum = (int) $qty_sum + $q; } }
			}
		} elseif ( $product->managing_stock() ) {
			$qty_sum = (int) $product->get_stock_quantity();
		}
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) || $attr->get_variation() ) { continue; }
			$values = $attr->is_taxonomy() ? wc_get_product_terms( $pid, $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			$attrs[ wc_attribute_label( $attr->get_name() ) ] = array_map( 'strval', (array) $values );
		}
		$datos_meta = trim( (string) $product->get_meta( CVD_Ficha_Estado::META_DATOS, true ) );
		$plain = CVD_Ficha_Estado::plain( (string) $product->get_description() . "\n" . (string) $product->get_short_description() );
		$lines = array_merge( '' !== $datos_meta ? explode( ' · ', $datos_meta ) : array(), explode( "\n", $plain ) );
		$campos = json_decode( (string) $product->get_meta( self::META_CAMPOS, true ), true ) ?: array();
		$electrico = CVD_Ficha_Estado::is_electric( $product->get_name(), $plain );
		$datos = self::datos_from( array(
			'lines' => $lines, 'campos' => $campos, 'attrs' => $attrs, 'variantes' => $variantes, 'electrico' => $electrico,
			'dims' => array( 'length' => $product->get_length(), 'width' => $product->get_width(), 'height' => $product->get_height() ),
		) );
		$fotos = array();
		foreach ( (array) ( json_decode( (string) $product->get_meta( self::META_FOTOS, true ), true ) ?: array() ) as $f ) {
			$src = wp_get_attachment_image_url( (int) ( $f['id'] ?? 0 ), 'medium' );
			if ( $src ) { $fotos[] = array( 'id' => (int) $f['id'], 'src' => (string) $src, 'full' => (string) wp_get_attachment_image_url( (int) $f['id'], 'large' ), 'quien' => (string) ( $f['quien'] ?? '' ), 'at' => (string) ( $f['at'] ?? '' ) ); }
		}
		list( $code, $estado ) = self::stock_state( array( 'bajo_pedido' => $supplier, 'in_stock' => $in_stock, 'qty' => $qty_sum ) );
		$image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
		return array(
			'id' => $pid, 'nombre' => wp_strip_all_tags( $product->get_name() ), 'url' => (string) get_permalink( $pid ), 'imagen' => $image ? (string) $image : '',
			'proveedor' => $supplier, 'electrico' => $electrico, 'estado' => array( 'code' => $code, 'texto' => $estado, 'qty' => $qty_sum ),
			'variantes' => array_map( static fn( $v ) => array( 'id' => $v[3], 'nombre' => $v[0], 'qty' => $v[1], 'hay' => $v[2] ), $variantes ),
			'filas' => self::rows_for( $datos, $electrico ), 'datos' => $datos, 'fotos' => array_slice( $fotos, -6 ),
			'faltan' => $supplier ? array() : self::missing( $datos, $electrico, count( $fotos ) ),
			'abiertas' => array_map( array( __CLASS__, 'public_row' ), self::rows( 'abierta', $pid, current_user_can( 'manage_woocommerce' ) ) ),
			'respuestas' => array_map( array( __CLASS__, 'public_row' ), array_slice( self::rows( 'respondida', $pid ), 0, 6 ) ),
		);
	}

	/** Una pregunta sin teléfonos (para la página y el bot). */
	public static function public_row( array $r ): array {
		$texts = array_values( array_unique( array_filter( array_map( static fn( $a ) => trim( (string) ( $a['t'] ?? '' ) ), $r['askers'] ) ) ) );
		$var = '';
		if ( $r['variation_id'] ) { $v = wc_get_product( $r['variation_id'] ); $var = $v ? self::variation_label( $v ) : ''; }
		return array(
			'id' => $r['id'], 'producto' => $r['product_id'], 'variante' => $var, 'tipo' => $r['tipo'], 'chip' => self::TIPOS[ $r['tipo'] ]['chip'] ?? $r['tipo'],
			'texto' => (string) $r['texto'], 'textos' => array_slice( $texts, 0, 3 ), 'personas' => count( $r['askers'] ), 'status' => $r['status'],
			'creada' => (string) $r['created_at'], 'respuesta' => (string) $r['answer'], 'quien' => (string) $r['answered_by'], 'contestada' => (string) $r['answered_at'],
			'foto' => $r['photo_id'] ? (string) wp_get_attachment_image_url( $r['photo_id'], 'medium' ) : '', 'test' => (bool) $r['test'],
			'nombres' => array_values( array_filter( array_map( static fn( $a ) => strtok( (string) ( $a['n'] ?? '' ), ' ' ) ?: '', $r['askers'] ) ) ),
		);
	}

	/* ================================================================ preguntar */

	private static function phone_of_user( int $uid ): string {
		foreach ( array( '_cvd_whatsapp', 'billing_phone' ) as $k ) {
			$p = class_exists( 'CVD_Payout_Math' ) ? CVD_Payout_Math::phone8( (string) get_user_meta( $uid, $k, true ) ) : '';
			if ( '' !== $p ) { return $p; }
		}
		return '';
	}

	private static function p8( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( (string) $d ) >= 8 ? substr( (string) $d, -8 ) : '';
	}

	/**
	 * Pregunta a la tienda (o se suma a la que ya hay). $tipos ⊂ TIPOS; $texto = lo que escribió (para «otra», o para
	 * aclarar). $asker = u (usuario), p (teléfono), n (nombre), s (app|general|privado|bot), t (texto), jid, msg, part.
	 * Lo que la ficha ya sabe no se pregunta: vuelve en «ya» (el bot lo contesta). → array o WP_Error.
	 */
	public static function ask( WC_Product $product, int $variation_id, array $tipos, string $texto, array $asker, bool $force = false ) {
		$product = self::parent_of( $product );
		if ( self::is_supplier( $product ) ) { return new WP_Error( 'cvd_fc_proveedor', 'Producto de proveedor: la tienda no lo tiene. Pregúntalo en el General y lo consultamos con el proveedor.', array( 'status' => 422 ) ); }
		$texto = self::clip( sanitize_textarea_field( $texto ), 300 );
		$tipos = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $tipos ), array_keys( self::TIPOS ) ) ) );
		if ( '' !== $texto && ! $tipos ) { $tipos = array( 'otra' ); }
		if ( ! $tipos ) { return new WP_Error( 'cvd_fc_vacio', 'Elige qué quieres saber o escribe tu pregunta.', array( 'status' => 422 ) ); }
		if ( $variation_id ) {
			$v = wc_get_product( $variation_id );
			if ( ! $v || (int) $v->get_parent_id() !== $product->get_id() ) { $variation_id = 0; }
		}
		$view = self::view( $product );
		$asker = array_filter( array(
			'u' => absint( $asker['u'] ?? 0 ), 'p' => self::p8( (string) ( $asker['p'] ?? '' ) ), 'n' => self::clip( sanitize_text_field( (string) ( $asker['n'] ?? '' ) ), 80 ),
			's' => sanitize_key( (string) ( $asker['s'] ?? 'app' ) ), 't' => '' !== $texto ? $texto : self::clip( sanitize_text_field( (string) ( $asker['t'] ?? '' ) ), 200 ),
			'jid' => sanitize_text_field( (string) ( $asker['jid'] ?? '' ) ), 'msg' => sanitize_text_field( (string) ( $asker['msg'] ?? '' ) ), 'part' => sanitize_text_field( (string) ( $asker['part'] ?? '' ) ),
			'at' => gmdate( 'c' ),
		) );
		$test = ! empty( $asker['u'] ) && '1' === (string) get_user_meta( (int) $asker['u'], '_cvd_test_account', true );
		global $wpdb;
		$out = array( 'ok' => true, 'producto' => $view['id'], 'nombre' => $view['nombre'], 'url' => $view['url'], 'ya' => array(), 'preguntas' => array() );
		$created = 0;
		foreach ( $tipos as $tipo ) {
			// Lo que ya está (si no es «otra» ni la variante pedida): se contesta con la ficha.
			$known = 'foto' === $tipo ? $view['fotos'] : ( 'otra' === $tipo ? array() : array_filter( $view['datos'][ $tipo ] ?? array(), static fn( $r ) => ! empty( $r['exacto'] ) ) );
			if ( ! $force && $known && ! $variation_id ) {
				$out['ya'][ $tipo ] = 'foto' === $tipo ? array_column( $known, 'full' ) : array_values( array_column( $known, 'texto' ) );
				continue;
			}
			$clave = self::group_key( $view['id'], $variation_id, $tipo, 'otra' === $tipo ? $texto : '' );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'preguntas' ) . " WHERE status = 'abierta' AND clave = %s ORDER BY id ASC LIMIT 1", $clave ), ARRAY_A );
			if ( $row ) {
				$row = self::decode( $row );
				list( $askers, $nueva ) = self::add_asker( $row['askers'], $asker );
				if ( $nueva ) {
					$wpdb->update( self::t( 'preguntas' ), array( 'askers' => wp_json_encode( $askers, JSON_UNESCAPED_UNICODE ), 'asks' => count( $askers ), 'updated_at' => self::now(), 'test' => $row['test'] && $test ? 1 : 0 ), array( 'id' => $row['id'] ) );
				}
				$out['preguntas'][] = array( 'id' => $row['id'], 'tipo' => $tipo, 'nueva' => false, 'sumada' => $nueva, 'personas' => count( $askers ) );
				continue;
			}
			$wpdb->insert( self::t( 'preguntas' ), array(
				'product_id' => $view['id'], 'variation_id' => $variation_id, 'tipo' => $tipo, 'clave' => $clave,
				'texto' => 'otra' === $tipo ? $texto : ( self::TIPOS[ $tipo ]['chip'] . ( '' !== $texto ? ' — ' . $texto : '' ) ),
				'status' => 'abierta', 'askers' => wp_json_encode( array( $asker ), JSON_UNESCAPED_UNICODE ), 'asks' => 1, 'test' => $test ? 1 : 0,
				'created_at' => self::now(), 'updated_at' => self::now(),
			) );
			$id = (int) $wpdb->insert_id;
			if ( $id ) { $created++; $out['preguntas'][] = array( 'id' => $id, 'tipo' => $tipo, 'nueva' => true, 'sumada' => true, 'personas' => 1 ); }
		}
		if ( $created && ! $test ) { self::queue_store_notice(); }
		return $out;
	}

	/** Un aviso a la tienda «hay N preguntas», como mucho uno cada 45 min (el texto se arma al recogerlo). */
	public static function queue_store_notice(): bool {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::STORE_GAP );
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'avisos' ) . " WHERE kind = 'tienda' AND ( notified_at IS NULL OR created_at >= %s )", $since ) );
		if ( $recent ) { return false; }
		return (bool) $wpdb->insert( self::t( 'avisos' ), array( 'kind' => 'tienda', 'role' => 'tienda', 'body' => '(se arma al recogerlo)', 'dedupe' => hash( 'sha256', 'tienda|' . microtime( true ) ), 'created_at' => self::now() ) );
	}

	private static function queue( array $a ): bool {
		global $wpdb;
		$a += array( 'pregunta_id' => 0, 'kind' => 'respuesta', 'role' => 'gestora', 'phone' => '', 'name' => '', 'chat_jid' => '', 'msg_id' => '', 'participant' => '', 'quoted' => '', 'photo_url' => '', 'body' => '', 'test' => 0 );
		if ( '' === trim( (string) $a['body'] ) ) { return false; }
		$a['dedupe'] = hash( 'sha256', $a['pregunta_id'] . '|' . $a['kind'] . '|' . $a['role'] . '|' . $a['phone'] . '|' . $a['chat_jid'] );
		$a['created_at'] = self::now();
		$a['name'] = mb_substr( (string) $a['name'], 0, 190 );
		return 1 === (int) $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::t( 'avisos' ) . ' (pregunta_id,kind,role,phone,name,chat_jid,msg_id,participant,quoted,photo_url,body,dedupe,test,created_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s)',
			$a['pregunta_id'], $a['kind'], $a['role'], $a['phone'], $a['name'], $a['chat_jid'], $a['msg_id'], $a['participant'], $a['quoted'], $a['photo_url'], $a['body'], $a['dedupe'], $a['test'], $a['created_at']
		) );
	}

	/* ================================================================ contestar */

	/**
	 * La tienda contesta. $valor = texto ('' si es solo foto); $photo_id = adjunto ya subido; $nose = «no se puede
	 * saber». Guarda en la ficha, cierra la pregunta y deja los avisos. → array o WP_Error.
	 */
	public static function answer( int $id, string $valor, string $quien, int $photo_id = 0, bool $nose = false ) {
		$r = self::row( $id );
		if ( ! $r ) { return new WP_Error( 'cvd_fc_no', 'Esa pregunta ya no existe.', array( 'status' => 404 ) ); }
		if ( 'abierta' !== $r['status'] ) { return array( 'ok' => true, 'ya' => true, 'id' => $id, 'status' => $r['status'] ); }
		$product = wc_get_product( $r['product_id'] );
		if ( ! $product ) { return new WP_Error( 'cvd_fc_producto', 'El producto ya no está en la web.', array( 'status' => 404 ) ); }
		$valor = self::clip( sanitize_textarea_field( $valor ), 300 );
		$quien = self::clip( sanitize_text_field( $quien ), 40 ) ?: 'la tienda';
		if ( ! $nose && '' === $valor && ! $photo_id ) { return new WP_Error( 'cvd_fc_vacio', 'Escribe la respuesta o sube la foto.', array( 'status' => 422 ) ); }
		$fecha = wp_date( 'd/m' );
		$variante = '';
		if ( $r['variation_id'] ) { $v = wc_get_product( $r['variation_id'] ); $variante = $v ? self::variation_label( $v ) : ''; }
		$pregunta = (string) ( $r['askers'][0]['t'] ?? $r['texto'] );
		$tipo = $r['tipo'];
		$web = false;
		if ( $nose ) {
			$line = 'La tienda no lo pudo confirmar' . ( '' !== $valor ? ': ' . rtrim( $valor, '. ' ) : '' ) . '.';
		} else {
			$line = self::answer_line( $photo_id && '' === $valor ? 'foto' : $tipo, '' !== $valor ? $valor : 'foto', $pregunta, $variante, $fecha );
			if ( 'foto' === $tipo || $photo_id ) {
				$fotos = json_decode( (string) $product->get_meta( self::META_FOTOS, true ), true ) ?: array();
				if ( $photo_id ) { $fotos[] = array( 'id' => $photo_id, 'quien' => $quien, 'at' => gmdate( 'c' ), 'pregunta' => $id ); }
				$product->update_meta_data( self::META_FOTOS, wp_json_encode( array_slice( $fotos, -20 ), JSON_UNESCAPED_UNICODE ) );
			}
			if ( 'otra' !== $tipo && 'foto' !== $tipo && '' !== $valor ) {
				$campos = json_decode( (string) $product->get_meta( self::META_CAMPOS, true ), true ) ?: array();
				$campos[ $tipo ] = array( 'valor' => ( '' !== $variante ? '(' . $variante . ') ' : '' ) . rtrim( $valor, '. ' ), 'quien' => $quien, 'at' => gmdate( 'c' ), 'pregunta' => $id );
				$product->update_meta_data( self::META_CAMPOS, wp_json_encode( $campos, JSON_UNESCAPED_UNICODE ) );
			}
			$product->save();
			// Medidas, material, voltaje y qué trae: a la descripción pública, como /bot/fichas/guardar (no repite, no borra).
			if ( ! empty( self::TIPOS[ $tipo ]['web'] ) && '' !== $valor && class_exists( 'CVD_Ficha_Estado' ) && ! CVD_Ficha_Estado::excluded( $product ) ) {
				$saved = CVD_Ficha_Estado::save_lines( $product, array( $line ), 'tienda', array( 'quien' => $quien, 'pregunta' => 'ficha-contesta #' . $id ) );
				$web = ! is_wp_error( $saved );
			} else {
				// Lo demás queda en el historial de la ficha (quién, cuándo, qué), sin tocar la descripción.
				$fresh = wc_get_product( $product->get_id() );
				$hist = CVD_Ficha_Estado::history( $fresh->get_id() );
				$hist[] = array( 'at' => gmdate( 'c' ), 'fuente' => 'tienda', 'quien' => $quien, 'lineas' => array( $line ), 'pregunta' => 'ficha-contesta #' . $id, 'web' => 'no' );
				$fresh->update_meta_data( CVD_Ficha_Estado::META_HIST, wp_json_encode( array_slice( $hist, -50 ), JSON_UNESCAPED_UNICODE ) );
				$fresh->save();
			}
		}
		global $wpdb;
		$wpdb->update( self::t( 'preguntas' ), array( 'status' => $nose ? 'cerrada' : 'respondida', 'answer' => $line, 'answered_by' => $quien, 'answered_at' => self::now(), 'updated_at' => self::now(), 'photo_id' => $photo_id, 'web' => $web ? 1 : 0, 'synced_at' => $nose ? self::now() : null ), array( 'id' => $id ) );
		$sent = self::queue_answer_notices( $r, $product, $line, $photo_id );
		return array( 'ok' => true, 'id' => $id, 'linea' => $line, 'web' => $web, 'avisos' => $sent );
	}

	/** Un aviso por gestora que preguntó (las del General: uno solo en el General, citando a la primera). */
	private static function queue_answer_notices( array $r, WC_Product $product, string $line, int $photo_id ): int {
		$product = self::parent_of( $product );
		$name = wp_strip_all_tags( $product->get_name() );
		// 3.14.69.14 (error #112, 27-oct): sin el ?ref= de quien contesta (Lennys); cada gestora recibe el suyo.
		$url = remove_query_arg( array( 'ref', 'cv_ref' ), (string) get_permalink( $product->get_id() ) );
		$photo = $photo_id ? (string) wp_get_attachment_image_url( $photo_id, 'large' ) : '';
		$n = 0;
		$total = count( $r['askers'] );
		$group_done = false;
		foreach ( $r['askers'] as $a ) {
			$first = strtok( (string) ( $a['n'] ?? '' ), ' ' ) ?: '';
			if ( 'general' === ( $a['s'] ?? '' ) && ! empty( $a['jid'] ) ) {
				if ( $group_done ) { continue; }
				$group_done = true;
				$n += (int) self::queue( array( 'pregunta_id' => $r['id'], 'role' => 'grupo', 'chat_jid' => $a['jid'], 'msg_id' => (string) ( $a['msg'] ?? '' ), 'participant' => (string) ( $a['part'] ?? '' ), 'quoted' => (string) ( $a['t'] ?? '' ), 'phone' => (string) ( $a['p'] ?? '' ), 'name' => (string) ( $a['n'] ?? '' ), 'photo_url' => $photo, 'body' => self::answer_notice( $first, $name, $line, $url, max( 0, $total - 1 ) ), 'test' => $r['test'] ) );
				continue;
			}
			$phone = (string) ( $a['p'] ?? '' );
			if ( '' === $phone && ! empty( $a['u'] ) ) { $phone = self::phone_of_user( (int) $a['u'] ); }
			if ( '' === $phone ) { continue; }
			$n += (int) self::queue( array( 'pregunta_id' => $r['id'], 'role' => 'gestora', 'phone' => '53' . substr( $phone, -8 ), 'name' => (string) ( $a['n'] ?? '' ), 'photo_url' => $photo, 'body' => self::answer_notice( $first, $name, $line, self::url_for_asker( $url, $a, $phone ), max( 0, $total - 1 ) ), 'test' => $r['test'] ) );
		}
		return $n;
	}

	/** El enlace del producto con el código de la gestora que preguntó (aprobada); si no se sabe quién es, sin código. */
	private static function url_for_asker( string $url, array $a, string $phone ): string {
		$uid = absint( $a['u'] ?? 0 );
		if ( ! $uid && class_exists( 'CVD_Vale_Chat' ) ) {
			$g = CVD_Vale_Chat::gestora_by_phone( $phone );
			$uid = $g ? (int) $g->ID : 0;
		}
		if ( ! $uid || 'approved' !== get_user_meta( $uid, '_cvd_account_status', true ) ) { return $url; }
		$code = strtoupper( (string) preg_replace( '/[^A-Z0-9_-]/i', '', (string) get_user_meta( $uid, '_cvd_referral_code', true ) ) );
		return '' !== $code ? add_query_arg( 'ref', $code, $url ) : $url;
	}

	/* ================================================================ página del producto (gestoras) */

	public static function viewer(): bool { return class_exists( 'CVD_Ficha_Estado' ) && CVD_Ficha_Estado::viewer(); }

	/** Con el bloque activo, el cartel viejo «Falta información» sobra (el bloque ya dice lo que falta). */
	public static function swap_notice(): void {
		if ( 'no' !== self::mode() ) { remove_action( 'woocommerce_single_product_summary', array( 'CVD_Ficha_Estado', 'single_notice' ), 25 ); }
	}

	public static function single_block(): void {
		global $product;
		if ( 'no' === self::mode() || ! $product instanceof WC_Product || ! self::viewer() ) { return; }
		$notice = sanitize_key( (string) ( $_GET['cvd_fc'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un aviso de vuelta.
		echo self::block_html( self::view( $product ), 'si' === self::mode(), $notice, absint( $_GET['n'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended -- escapado dentro.
	}

	/** El bloque «Datos de la tienda». $can_ask = interruptor en «si». */
	public static function block_html( array $v, bool $can_ask, string $notice = '', int $n = 0 ): string {
		$h = '<section class="cvfc" id="cvd-ficha-tienda" aria-label="Datos de la tienda">' . self::css_tag();
		$h .= '<div class="cvfc-head"><b>Datos de la tienda</b><span class="cvfc-pill cvfc-' . esc_attr( $v['estado']['code'] ) . '">' . esc_html( $v['estado']['texto'] ) . '</span></div>';
		if ( 'ok' === $notice ) { $h .= '<p class="cvfc-ok">✅ ' . ( $n > 1 ? $n . ' preguntas enviadas' : 'Pregunta enviada' ) . ' a la tienda. Te avisamos por WhatsApp cuando contesten y la respuesta queda aquí.</p>'; }
		if ( 'ya' === $notice ) { $h .= '<p class="cvfc-ok">👇 Eso ya está en la ficha: míralo abajo. No hizo falta preguntar.</p>'; }
		if ( 'err' === $notice ) { $h .= '<p class="cvfc-err">No se pudo enviar. Elige qué quieres saber o escribe tu pregunta.</p>'; }
		$h .= '<dl class="cvfc-rows">';
		foreach ( $v['filas'] as $t ) {
			$label = 'garantia' === $t ? 'Garantía' : self::TIPOS[ $t ]['label'];
			$rows = $v['datos'][ $t ] ?? array();
			$miss = in_array( $t, $v['faltan'], true );
			$h .= '<div class="cvfc-row' . ( $miss ? ' cvfc-miss' : '' ) . '"><dt>' . esc_html( $label ) . '</dt><dd>';
			foreach ( $rows as $r ) { $h .= '<span class="cvfc-line">' . esc_html( $r['texto'] ) . ( $r['tienda'] ? ' <em class="cvfc-src">✓ tienda ' . esc_html( self::short_date( (string) $r['at'] ) ) . '</em>' : '' ) . '</span>'; }
			if ( $miss ) { $h .= '<span class="cvfc-falta">' . ( $rows ? 'Falta el dato exacto' : 'Falta' ) . '</span>'; }
			$h .= '</dd></div>';
		}
		$h .= '<div class="cvfc-row' . ( in_array( 'foto', $v['faltan'], true ) ? ' cvfc-miss' : '' ) . '"><dt>Fotos reales</dt><dd>';
		if ( $v['fotos'] ) {
			$h .= '<span class="cvfc-fotos">';
			foreach ( $v['fotos'] as $f ) { $h .= '<a href="' . esc_url( $f['full'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $f['src'] ) . '" alt="Foto real" loading="lazy" width="72" height="72"></a>'; }
			$h .= '</span>';
		} else { $h .= '<span class="cvfc-falta">Todavía no hay</span>'; }
		$h .= '</dd></div></dl>';
		$answers = array_filter( $v['respuestas'], static fn( $r ) => 'otra' === $r['tipo'] );
		if ( $answers ) {
			$h .= '<div class="cvfc-qa"><b>La tienda ya contestó</b>';
			foreach ( array_slice( $answers, 0, 4 ) as $r ) { $h .= '<p>' . esc_html( $r['respuesta'] ) . ' <em class="cvfc-src">' . esc_html( strtok( $r['quien'], ' ' ) . ' · ' . self::short_date( $r['contestada'] ) ) . '</em></p>'; }
			$h .= '</div>';
		}
		if ( $v['abiertas'] ) {
			$h .= '<div class="cvfc-wait">⏳ Ya preguntado a la tienda: ';
			$h .= esc_html( implode( ' · ', array_map( static fn( $r ) => ( 'otra' === $r['tipo'] ? '«' . self::clip( $r['texto'], 60 ) . '»' : $r['chip'] ) . ( '' !== $r['variante'] ? ' (' . $r['variante'] . ')' : '' ) . ( $r['personas'] > 1 ? ' ×' . $r['personas'] : '' ), $v['abiertas'] ) ) );
			$h .= '. Si también lo quieres saber, márcalo: te avisamos a ti también.</div>';
		}
		if ( $v['proveedor'] ) {
			return $h . '<p class="cvfc-note">Producto de proveedor (bajo pedido): la tienda no lo tiene. Si te falta un dato, pregúntalo en el General y lo consultamos con el proveedor.</p><p class="cvfc-foot">Solo lo ven gestoras y administración.</p></section>';
		}
		if ( ! $can_ask ) {
			return $h . ( $v['faltan'] ? '<p class="cvfc-note">Si te falta un dato, pregúntalo en el General. No le des al cliente un dato que no salga aquí.</p>' : '' ) . '<p class="cvfc-foot">Solo lo ven gestoras y administración.</p></section>';
		}
		$open_tipos = array_column( array_filter( $v['abiertas'], static fn( $r ) => '' === $r['variante'] ), 'tipo' );
		$h .= '<details class="cvfc-ask"' . ( array_diff( $v['faltan'], $open_tipos ) ? ' open' : '' ) . '><summary>Preguntar a la tienda</summary>';
		$h .= '<form method="post" action="' . esc_url( remove_query_arg( array( 'cvd_fc', 'n' ) ) ) . '#cvd-ficha-tienda" data-once-form>';
		$h .= wp_nonce_field( 'cvd_fc_' . $v['id'], '_cvdfc', true, false ) . '<input type="hidden" name="cvd_fc_preguntar" value="' . (int) $v['id'] . '">';
		$h .= '<div class="cvfc-chips">';
		foreach ( array( 'medidas', 'material', 'voltaje', 'trae', 'colores', 'foto' ) as $t ) {
			$pre = in_array( $t, $v['faltan'], true ) && ! in_array( $t, $open_tipos, true ) && ( 'voltaje' !== $t || $v['electrico'] ) && ( 'material' !== $t || ! $v['electrico'] );
			$h .= '<label class="cvfc-chip"><input type="checkbox" name="cvd_fc_tipos[]" value="' . esc_attr( $t ) . '"' . ( $pre ? ' checked' : '' ) . '><span>' . esc_html( self::TIPOS[ $t ]['chip'] ) . '</span></label>';
		}
		$h .= '</div>';
		$vars = array_filter( $v['variantes'], static fn( $x ) => '' !== $x['nombre'] );
		if ( $vars ) {
			$h .= '<label class="cvfc-lbl">¿De cuál? <select name="cvd_fc_variacion"><option value="0">De todas / no importa</option>';
			foreach ( $vars as $x ) { $h .= '<option value="' . (int) $x['id'] . '">' . esc_html( $x['nombre'] . ( $x['hay'] ? '' : ' (agotado)' ) ) . '</option>'; }
			$h .= '</select></label>';
		}
		$h .= '<label class="cvfc-lbl">Otra pregunta (opcional)<textarea name="cvd_fc_texto" rows="2" maxlength="300" placeholder="Ej.: ¿se puede lavar en lavadora?"></textarea></label>';
		$h .= '<button type="submit" class="cvfc-btn" data-once>Enviar a la tienda</button>';
		$h .= '<p class="cvfc-note">Contesta la dependienta de turno. Te avisamos por WhatsApp y la respuesta queda aquí para todas.</p></form></details>';
		return $h . '<p class="cvfc-foot">Solo lo ven gestoras y administración.</p><script>' . self::js() . '</script></section>';
	}

	/** El formulario «Preguntar a la tienda» de la página del producto (sin JavaScript: vuelve con ?cvd_fc=…). */
	public static function handle_product_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['cvd_fc_preguntar'] ) ) { return; }
		$pid = absint( $_POST['cvd_fc_preguntar'] );
		$back = remove_query_arg( array( 'cvd_fc', 'n' ), (string) get_permalink( $pid ) );
		if ( 'si' !== self::mode() || ! self::viewer() || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_POST['_cvdfc'] ?? '' ) ) ), 'cvd_fc_' . $pid ) ) { return; }
		$product = wc_get_product( $pid );
		if ( ! $product ) { return; }
		$user = wp_get_current_user();
		$rl = 'cvd_fc_rl_' . $user->ID;
		$count = (int) get_transient( $rl );
		if ( $count >= 15 ) { wp_safe_redirect( add_query_arg( 'cvd_fc', 'err', $back ) . '#cvd-ficha-tienda', 303 ); exit; }
		$r = self::ask(
			$product, absint( $_POST['cvd_fc_variacion'] ?? 0 ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['cvd_fc_tipos'] ?? array() ) ),
			(string) wp_unslash( $_POST['cvd_fc_texto'] ?? '' ), array( 'u' => $user->ID, 'p' => self::phone_of_user( $user->ID ), 'n' => $user->display_name, 's' => 'app' )
		);
		set_transient( $rl, $count + 1, HOUR_IN_SECONDS );
		$code = is_wp_error( $r ) ? 'err' : ( $r['preguntas'] ? 'ok' : 'ya' );
		$n = is_wp_error( $r ) ? 0 : count( $r['preguntas'] );
		wp_safe_redirect( add_query_arg( array( 'cvd_fc' => $code, 'n' => $n ), $back ) . '#cvd-ficha-tienda', 303 );
		exit;
	}

	/** «Preguntas a la tienda» en la app de la gestora: las suyas, abiertas y contestadas. */
	public static function render_app_section( WP_User $user ): string {
		if ( 'si' !== self::mode() ) { return ''; }
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"u":' . (int) $user->ID . ',' ) . '%';
		$rows = array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'preguntas' ) . ' WHERE askers LIKE %s ORDER BY updated_at DESC LIMIT 30', $like ), ARRAY_A ) );
		$h = '<section class="cvga-view" data-view="preguntas" id="cvga-preguntas">' . self::css_tag() . '<div class="cvga-head"><h1>Preguntas a la tienda</h1><p>Lo que preguntaste en las fichas. Cuando la tienda contesta, te llega por WhatsApp y queda en la ficha para todas.</p></div>';
		if ( ! $rows ) { return $h . '<section class="cvga-card"><p class="cvga-note">Todavía no preguntaste nada. Abre un producto en la tienda y toca «Preguntar a la tienda» en «Datos de la tienda».</p></section></section>'; }
		$h .= '<section class="cvga-card cvfc-list">';
		foreach ( $rows as $r ) {
			$p = wc_get_product( $r['product_id'] );
			if ( ! $p ) { continue; }
			$pub = self::public_row( $r );
			$h .= '<a class="cvfc-item" href="' . esc_url( get_permalink( $r['product_id'] ) . '#cvd-ficha-tienda' ) . '"><b>' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</b><span>' . esc_html( 'otra' === $r['tipo'] ? '«' . self::clip( (string) $r['texto'], 80 ) . '»' : $pub['chip'] ) . '</span>';
			$h .= 'abierta' === $r['status'] ? '<em class="cvfc-wait-pill">⏳ Esperando a la tienda</em>' : '<em class="cvfc-done">✅ ' . esc_html( self::clip( (string) $r['answer'], 140 ) ) . '</em>';
			$h .= '</a>';
		}
		return $h . '</section></section>';
	}

	/* ================================================================ página de la tienda (enlace firmado) */

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: preguntas de fichas (privado)' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/** Nombres para «¿quién contesta?» (opción cvd_ficha_tienda_nombres; por defecto Nana, Zaymi y Lennys: 3.14.69.13, Lennys 26-oct). */
	public static function store_names(): array {
		$raw = (string) get_option( 'cvd_ficha_tienda_nombres', 'Nana,Zaymi,Lennys' );
		return array_values( array_filter( array_map( static fn( $x ) => self::clip( sanitize_text_field( $x ), 30 ), explode( ',', $raw ) ) ) );
	}

	public static function route_store_page(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::no_cache();
		$token = sanitize_text_field( wp_unslash( (string) ( $_GET['t'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$admin = is_user_logged_in() && current_user_can( 'manage_woocommerce' );
		if ( ! $admin && ! self::verify( $token, time(), self::key() ) ) {
			status_header( 403 );
			self::shell( 'Enlace no válido', '<div class="box"><h1>Este enlace ya no vale</h1><p>Caducó (dura 3 días). Te llega uno nuevo con el próximo aviso de preguntas; si lo necesitas ya, avisa a Ernesto.</p></div>' );
		}
		$quien = self::clip( sanitize_text_field( wp_unslash( (string) ( $_REQUEST['quien'] ?? ( $_COOKIE['cvd_fc_quien'] ?? '' ) ) ) ), 30 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '{quien}' === $quien || false !== strpos( $quien, '{' ) ) { $quien = ''; }
		$base = add_query_arg( array_filter( array( self::PARAM => 1, 't' => $token, 'quien' => $quien ) ), home_url( '/' ) );
		if ( '' !== $quien ) { setcookie( 'cvd_fc_quien', $quien, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); }
		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['cvd_fc_id'] ) ) {
			$who = self::clip( sanitize_text_field( wp_unslash( (string) ( $_POST['cvd_fc_quien'] ?? $quien ) ) ), 30 );
			if ( '' === $who ) { $msg = 'quien'; }
			else {
				$id = absint( $_POST['cvd_fc_id'] );
				$photo = 0;
				if ( ! empty( $_FILES['cvd_fc_foto']['name'] ) ) {
					$photo = self::upload_photo( $id );
					if ( ! $photo ) { $msg = 'foto'; }
				}
				if ( '' === $msg ) {
					$r = self::answer( $id, (string) wp_unslash( $_POST['cvd_fc_respuesta'] ?? '' ), $who, $photo, ! empty( $_POST['cvd_fc_nose'] ) );
					if ( ! is_wp_error( $r ) ) { wp_safe_redirect( add_query_arg( array( 'quien' => $who, 'hecho' => $id, 'av' => (int) ( $r['avisos'] ?? 0 ) ), $base ), 303 ); exit; }
					$msg = 'vacio';
				}
			}
		}
		self::shell( 'Preguntas de gestoras', self::store_body( $base, $quien, $msg, $admin ) );
	}

	/** Sube la foto de la dependienta como adjunto del producto. → id o 0. */
	private static function upload_photo( int $question_id ): int {
		$r = self::row( $question_id );
		if ( ! $r ) { return 0; }
		$f = $_FILES['cvd_fc_foto']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- lo valida wp_handle_upload.
		if ( ! empty( $f['error'] ) || (int) ( $f['size'] ?? 0 ) > 12 * MB_IN_BYTES ) { return 0; }
		$type = wp_check_filetype_and_ext( (string) $f['tmp_name'], (string) $f['name'] );
		if ( ! in_array( $type['type'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic' ), true ) ) { return 0; }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'cvd_fc_foto', $r['product_id'], array( 'post_title' => 'Foto real de la tienda #' . $question_id ), array( 'test_form' => false ) );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function store_body( string $base, string $quien, string $msg, bool $admin ): string {
		$rows = self::rows( 'abierta', 0, $admin, 60 );
		$h = '<p class="kick">Casa Viva · preguntas de gestoras</p>';
		$h .= '<h1>' . ( $rows ? ( 1 === count( $rows ) ? '1 pregunta' : count( $rows ) . ' preguntas' ) . ' para contestar' : 'No hay preguntas ahora ✅' ) . '</h1>';
		$done = absint( $_GET['hecho'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $done ) { $av = absint( $_GET['av'] ?? 0 ); $h .= '<div class="ok">Guardado en la ficha ✅' . ( $av ? ' Se avisa a ' . ( 1 === $av ? 'la gestora' : $av . ' gestoras' ) . ' por WhatsApp.' : '' ) . '</div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'quien' === $msg ) { $h .= '<div class="err">Toca tu nombre arriba antes de guardar.</div>'; }
		if ( 'foto' === $msg ) { $h .= '<div class="err">No se pudo subir la foto (tiene que ser una foto JPG o PNG de menos de 12 MB). Prueba otra vez.</div>'; }
		if ( 'vacio' === $msg ) { $h .= '<div class="err">Escribe la respuesta o sube la foto.</div>'; }
		$h .= '<div class="who"><span>¿Quién contesta?</span>';
		foreach ( self::store_names() as $n ) { $h .= '<a class="chip' . ( 0 === strcasecmp( $n, $quien ) ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( 'quien', $n, remove_query_arg( array( 'hecho', 'av' ), $base ) ) ) . '">' . esc_html( $n ) . '</a>'; }
		$h .= '</div>';
		if ( ! $rows ) { return $h . '<p class="muted">Cuando una gestora pregunte algo de un producto, te llega un aviso con este enlace.</p>'; }
		$by = array();
		foreach ( $rows as $r ) { $by[ $r['product_id'] ][] = $r; }
		foreach ( $by as $pid => $list ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) { continue; }
			$v = self::view( $p );
			$h .= '<article class="card"><div class="prod">' . ( $v['imagen'] ? '<img src="' . esc_url( $v['imagen'] ) . '" alt="" width="64" height="64" loading="lazy">' : '' ) . '<div><b>' . esc_html( $v['nombre'] ) . '</b><small>' . esc_html( $v['estado']['texto'] ) . '</small></div></div>';
			$known = array();
			foreach ( $v['filas'] as $t ) { foreach ( $v['datos'][ $t ] ?? array() as $d ) { $known[] = $d['texto']; } }
			if ( $known ) { $h .= '<details class="known"><summary>Lo que ya dice la ficha</summary><ul>' . implode( '', array_map( static fn( $x ) => '<li>' . esc_html( $x ) . '</li>', array_slice( array_unique( $known ), 0, 10 ) ) ) . '</ul></details>'; }
			foreach ( $list as $r ) {
				$pub = self::public_row( $r );
				$foto = 'foto' === $r['tipo'];
				$h .= '<form class="q" method="post" enctype="multipart/form-data" action="' . esc_url( remove_query_arg( array( 'hecho', 'av' ), $base ) ) . '" data-once-form>';
				$h .= '<input type="hidden" name="cvd_fc_id" value="' . (int) $r['id'] . '"><input type="hidden" name="cvd_fc_quien" value="' . esc_attr( $quien ) . '">';
				$h .= '<p class="ask"><b>' . esc_html( 'otra' === $r['tipo'] ? 'Pregunta' : $pub['chip'] ) . '</b>' . ( '' !== $pub['variante'] ? ' · ' . esc_html( $pub['variante'] ) : '' ) . ( $pub['test'] ? ' <span class="test">PRUEBA</span>' : '' ) . '<small>' . esc_html( $pub['personas'] > 1 ? $pub['personas'] . ' gestoras esperando' : '1 gestora esperando' ) . '</small></p>';
				foreach ( $pub['textos'] as $t ) { $h .= '<p class="said">«' . esc_html( $t ) . '»</p>'; }
				$ph = array( 'medidas' => 'Ej.: 45 × 70 cm, 12 cm de alto', 'material' => 'Lo que dice la etiqueta. Ej.: 100 % poliéster', 'voltaje' => 'Ej.: 110–220 V, carga por USB tipo C, 4 horas', 'trae' => 'Ej.: 2 soportes, tornillos y tacos', 'colores' => 'Los que hay HOY. Ej.: gris (2) y beige (1)', 'foto' => 'Algo que decir de la foto (opcional)', 'otra' => 'Tu respuesta' );
				// 3.14.69.13 (Lennys 26-oct): sin capture="environment", el teléfono deja elegir galería o cámara.
				$h .= $foto
					? '<label class="file">📷 Hacer o elegir la foto<input type="file" name="cvd_fc_foto" accept="image/*" required></label><input name="cvd_fc_respuesta" maxlength="300" placeholder="' . esc_attr( $ph['foto'] ) . '">'
					: '<textarea name="cvd_fc_respuesta" rows="2" maxlength="300" required placeholder="' . esc_attr( $ph[ $r['tipo'] ] ?? 'Tu respuesta' ) . '"></textarea><label class="file small">📷 Añadir foto (opcional)<input type="file" name="cvd_fc_foto" accept="image/*"></label>';
				$h .= '<button class="btn" type="submit" data-once>✅ Guardar en la ficha</button>';
				$h .= '<button class="link" type="submit" name="cvd_fc_nose" value="1" formnovalidate>No se puede saber</button></form>';
			}
			$h .= '</article>';
		}
		return $h . '<p class="muted">Escribe solo lo que ves en el producto o en su etiqueta. Si no lo sabes, toca «No se puede saber».</p>';
	}

	private static function shell( string $title, string $body ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' · Casa Viva</title><style>' . self::store_css() . '</style></head><body><main>' . $body . '</main><script>' . self::js() . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body ya escapado al armarlo.
		exit;
	}

	/** Marca: crema #F6F1E6, marrón #2B2420, petróleo #0D3B45, ámbar #A35A1F. */
	public static function store_css(): string {
		return ':root{--bg:#F6F1E6;--ink:#2B2420;--brand:#0D3B45;--amber:#A35A1F;--card:#fffdf8;--line:#e6dccb;--muted:#6f655a;--good:#2f6b4a;--bad:#9a392f}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:560px;margin:0 auto;padding:16px 16px 48px}'
			. 'h1{font-size:24px;line-height:1.2;margin:2px 0 12px;color:var(--brand)}.kick{margin:0;color:var(--muted);font-size:13px;letter-spacing:.02em}'
			. '.who{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 14px}.who span{font-weight:700;font-size:14px;margin-right:2px}.chip{display:inline-block;padding:9px 16px;border-radius:999px;border:1.5px solid var(--brand);color:var(--brand);text-decoration:none;font-weight:700;min-height:40px}.chip.on{background:var(--brand);color:#fff}'
			. '.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:14px}.prod{display:flex;gap:12px;align-items:center;margin-bottom:8px}.prod img{width:64px;height:64px;object-fit:cover;border-radius:12px;background:#eee}.prod b{display:block;font-size:17px;line-height:1.25}.prod small{color:var(--muted);font-size:13px}'
			. '.known{margin:4px 0 8px;font-size:14px}.known summary{color:var(--brand);font-weight:700;cursor:pointer;padding:6px 0}.known ul{margin:4px 0 0 18px;padding:0;color:var(--muted)}'
			. '.q{border-top:1px solid var(--line);padding-top:12px;margin-top:10px}.ask{margin:0 0 4px;font-size:17px}.ask b{color:var(--amber)}.ask small{display:block;color:var(--muted);font-size:13px}.said{margin:2px 0 8px;font-style:italic;color:var(--muted);font-size:14px}.test{background:#f4d9c4;color:var(--amber);border-radius:6px;padding:1px 6px;font-size:12px;font-weight:800}'
			. 'textarea,input[name=cvd_fc_respuesta]{width:100%;font:inherit;font-size:17px;padding:11px 12px;border:1.5px solid #cbbba6;border-radius:12px;background:#fff;min-height:48px;margin:2px 0 8px}textarea:focus,input:focus{outline:3px solid #0d3b4533;border-color:var(--brand)}'
			. '.file{display:block;border:1.5px dashed var(--brand);border-radius:12px;padding:14px;text-align:center;color:var(--brand);font-weight:700;margin:2px 0 8px;cursor:pointer}.file.small{padding:9px;font-size:14px;font-weight:600}.file input{display:block;margin:8px auto 0;max-width:100%;font-size:13px}'
			. '.btn{display:block;width:100%;border:0;border-radius:14px;background:var(--brand);color:#fff;font:inherit;font-weight:800;font-size:18px;padding:15px;min-height:56px;cursor:pointer}.btn[disabled]{opacity:.6}.link{display:block;margin:8px auto 0;background:none;border:0;color:var(--muted);text-decoration:underline;font:inherit;font-size:14px;cursor:pointer;padding:6px}'
			. '.ok{background:#e5f1ea;border:1px solid #b9d7c5;color:#1f5a37;border-radius:14px;padding:12px 14px;margin-bottom:12px;font-weight:700}.err{background:#fbece8;border:1px solid #efc4b9;color:var(--bad);border-radius:12px;padding:12px;margin-bottom:12px}'
			. '.muted{color:var(--muted);font-size:14px;text-align:center;margin:14px 0}.box{background:var(--card);border-radius:16px;padding:20px;margin-top:20px}';
	}

	/** El bloque en la página del producto (dentro del tema, con sus propias clases). */
	private static function css_tag(): string {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		return '<style>.cvfc,.cvfc *{box-sizing:border-box}.cvfc{--fb:#F6F1E6;--fi:#2B2420;--fp:#0D3B45;--fa:#A35A1F;--fl:#e6dccb;--fm:#6f655a;background:var(--fb);color:var(--fi);border:1px solid var(--fl);border-radius:16px;padding:14px;margin:10px 0 16px;font-size:15px;line-height:1.45}'
			. '.cvfc-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-bottom:8px}.cvfc-head b{font-size:17px;color:var(--fp)}.cvfc-pill{border-radius:999px;padding:4px 10px;font-size:13px;font-weight:700;background:#fff;border:1px solid var(--fl)}.cvfc-hay{color:#1f5a37;border-color:#b9d7c5;background:#eef6f0}.cvfc-agotado{color:#9a392f;border-color:#efc4b9;background:#fbece8}.cvfc-bajo_pedido{color:var(--fa);border-color:#e6c3a5;background:#fbf0e6}'
			. '.cvfc-rows{margin:0;display:grid;gap:0;background:#fffdf8;border:1px solid var(--fl);border-radius:12px;overflow:hidden}.cvfc-row{display:grid;grid-template-columns:minmax(92px,32%) 1fr;gap:8px;padding:9px 11px;border-top:1px solid var(--fl)}.cvfc-row:first-child{border-top:0}.cvfc-row dt{font-weight:700;color:var(--fp);font-size:14px}.cvfc-row dd{margin:0;min-width:0;overflow-wrap:anywhere}.cvfc-line{display:block}'
			. '.cvfc-src{font-style:normal;font-size:12px;color:#1f5a37;white-space:nowrap}.cvfc-falta{display:inline-block;font-size:13px;font-weight:700;color:var(--fa);background:#fbf0e6;border-radius:8px;padding:1px 8px}.cvfc-fotos{display:flex;gap:6px;flex-wrap:wrap}.cvfc-fotos img{width:64px;height:64px;object-fit:cover;border-radius:10px;display:block}'
			. '.cvfc-qa{margin:10px 0 0;font-size:14px}.cvfc-qa b{color:var(--fp)}.cvfc-qa p{margin:4px 0}.cvfc-wait{margin:10px 0 0;font-size:14px;background:#fff;border:1px dashed var(--fl);border-radius:10px;padding:8px 10px}'
			. '.cvfc-ask{margin-top:12px;background:#fff;border:1px solid var(--fl);border-radius:12px}.cvfc-ask summary{padding:12px;font-weight:800;color:#fff;background:var(--fp);border-radius:12px;cursor:pointer;list-style:none;text-align:center;font-size:16px}.cvfc-ask[open] summary{border-radius:12px 12px 0 0}.cvfc-ask form{padding:12px}'
			. '.cvfc-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}.cvfc-chip{position:relative}.cvfc-chip input{position:absolute;opacity:0;width:1px;height:1px}.cvfc-chip span{display:inline-block;padding:9px 13px;border-radius:999px;border:1.5px solid var(--fp);color:var(--fp);font-weight:700;font-size:14px;min-height:40px;cursor:pointer;user-select:none}.cvfc-chip input:checked+span{background:var(--fp);color:#fff}.cvfc-chip input:focus-visible+span{outline:3px solid #0d3b4555}'
			. '.cvfc-lbl{display:block;font-weight:700;font-size:14px;margin:0 0 10px}.cvfc-lbl select,.cvfc-lbl textarea{display:block;width:100%;margin-top:4px;font:inherit;font-weight:400;font-size:16px;padding:10px;border:1.5px solid #cbbba6;border-radius:10px;background:#fff}'
			. '.cvfc-btn{display:block;width:100%;border:0;border-radius:12px;background:var(--fa);color:#fff;font:inherit;font-weight:800;font-size:17px;padding:13px;min-height:52px;cursor:pointer}.cvfc-btn[disabled]{opacity:.6}'
			. '.cvfc-note{font-size:13px;color:var(--fm);margin:8px 0 0}.cvfc-foot{font-size:12px;color:var(--fm);margin:10px 0 0;opacity:.8}.cvfc-ok{background:#e5f1ea;border:1px solid #b9d7c5;color:#1f5a37;border-radius:10px;padding:8px 10px;font-weight:700;margin:0 0 8px}.cvfc-err{background:#fbece8;color:#9a392f;border-radius:10px;padding:8px 10px;margin:0 0 8px}'
			. '.cvfc-list{display:grid;gap:0;padding:0}.cvfc-item{display:block;padding:12px 14px;border-top:1px solid var(--fl);color:inherit;text-decoration:none}.cvfc-item:first-child{border-top:0}.cvfc-item b{display:block}.cvfc-item span{display:block;font-size:14px;color:var(--fm)}.cvfc-wait-pill{font-style:normal;font-size:13px;color:var(--fa)}.cvfc-done{font-style:normal;font-size:14px;color:#1f5a37}</style>';
	}

	/** No enviar dos veces: al primer toque el botón se apaga y dice «Enviando…». */
	private static function js(): string {
		return '(function(){document.querySelectorAll("[data-once-form]").forEach(function(f){f.addEventListener("submit",function(e){var b=e.submitter||f.querySelector("[data-once]");if(b){setTimeout(function(){b.disabled=true;if(b.hasAttribute("data-once"))b.textContent="Enviando…"},0)}})})})();';
	}

	/* ================================================================ REST para VivaBot */

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/ficha-contesta/productos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_products' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/ficha-contesta/preguntar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_ask' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/ficha-contesta/pendientes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_pending' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/ficha-contesta/hecho', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_done' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/ficha-contesta/enlace', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_link' ), 'permission_callback' => $bot ) );
	}

	private static function json( array $data ): WP_REST_Response {
		$r = new WP_REST_Response( $data, 200 );
		$r->header( 'Cache-Control', 'no-store, private' );
		return $r;
	}

	/** GET /bot/ficha-contesta/productos?ids=1,2,3 → la ficha de cada uno (datos fijos, estado, respuestas, abiertas). */
	public static function rest_products( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ), 0, 6 );
		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! in_array( $p->get_status(), array( 'publish', 'private' ), true ) ) { continue; }
			$v = self::view( $p );
			$v['datos'] = array_map( static fn( $rows ) => array_map( static fn( $r ) => array( 'texto' => $r['texto'], 'tienda' => $r['tienda'], 'exacto' => $r['exacto'] ), $rows ), $v['datos'] );
			$v['abiertas'] = array_map( static fn( $r ) => array_diff_key( $r, array( 'nombres' => 1 ) ), $v['abiertas'] );
			$items[] = $v;
		}
		return self::json( array( 'ok' => true, 'modo' => self::mode(), 'items' => $items ) );
	}

	/** POST /bot/ficha-contesta/preguntar { product_id, variation_id, tipos[], texto, asker{p,n,s,t,jid,msg,part}, forzar }. */
	public static function rest_ask( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$product = wc_get_product( absint( $p['product_id'] ?? 0 ) );
		if ( ! $product ) { return new WP_Error( 'cvd_fc_producto', 'Ese producto no existe.', array( 'status' => 404 ) ); }
		$asker = (array) ( $p['asker'] ?? array() );
		$asker['s'] = in_array( $asker['s'] ?? '', array( 'general', 'privado' ), true ) ? $asker['s'] : 'bot';
		unset( $asker['u'] );
		$r = self::ask( $product, absint( $p['variation_id'] ?? 0 ), (array) ( $p['tipos'] ?? array() ), (string) ( $p['texto'] ?? '' ), $asker, ! empty( $p['forzar'] ) );
		return is_wp_error( $r ) ? $r : self::json( $r );
	}

	/** GET /bot/ficha-contesta/enlace → el enlace de la tienda (para «preguntas de fichas» o cv). */
	public static function rest_link(): WP_REST_Response {
		return self::json( array( 'ok' => true, 'url' => self::store_url(), 'abiertas' => count( self::rows( 'abierta', 0, false, 200 ) ) ) );
	}

	/**
	 * GET /bot/ficha-contesta/pendientes → avisos por mandar (gestoras, General y tienda), respuestas por copiar a
	 * crm_knowledge y preguntas viejas (más de 24 h abiertas) para Ernesto.
	 */
	public static function rest_pending(): WP_REST_Response {
		global $wpdb;
		$open = self::rows( 'abierta', 0, false, 200 );
		$people = array();
		foreach ( $open as $r ) { foreach ( $r['askers'] as $a ) { $people[ ! empty( $a['p'] ) ? 'p' . $a['p'] : 'u' . ( $a['u'] ?? '' ) ] = true; } }
		$avisos = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'avisos' ) . ' WHERE notified_at IS NULL ORDER BY id ASC LIMIT 30', ARRAY_A ) as $a ) {
			if ( 'tienda' === $a['kind'] ) {
				if ( ! $open ) { $wpdb->update( self::t( 'avisos' ), array( 'notified_at' => self::now(), 'result' => 'skipped', 'note' => 'ya no hay preguntas abiertas' ), array( 'id' => $a['id'] ) ); continue; }
				$a['body'] = self::store_notice( count( $open ), count( $people ), self::store_url() );
			}
			$a['id'] = (int) $a['id'];
			$a['pregunta_id'] = (int) $a['pregunta_id'];
			$a['test'] = (bool) $a['test'];
			$avisos[] = $a;
		}
		$respuestas = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'preguntas' ) . " WHERE status = 'respondida' AND synced_at IS NULL AND test = 0 ORDER BY id ASC LIMIT 20", ARRAY_A ) as $r ) {
			$r = self::decode( $r );
			$p = wc_get_product( $r['product_id'] );
			$var = '';
			if ( $r['variation_id'] ) { $v = wc_get_product( $r['variation_id'] ); $var = $v ? self::variation_label( $v ) : ''; }
			$respuestas[] = array(
				'id' => $r['id'], 'producto' => $r['product_id'], 'nombre' => $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $r['product_id'], 'url' => $p ? (string) get_permalink( $r['product_id'] ) : '',
				'tipo' => $r['tipo'], 'variante' => $var, 'pregunta' => (string) ( $r['askers'][0]['t'] ?? $r['texto'] ), 'linea' => (string) $r['answer'], 'quien' => $r['answered_by'], 'at' => $r['answered_at'], 'web' => (bool) $r['web'],
			);
		}
		$old = array();
		$limit = gmdate( 'Y-m-d H:i:s', time() - self::OLD_HOURS * 3600 );
		foreach ( $open as $r ) {
			if ( $r['created_at'] > $limit || ! empty( $r['old_notified_at'] ) ) { continue; }
			$p = wc_get_product( $r['product_id'] );
			$old[] = array( 'id' => $r['id'], 'nombre' => $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $r['product_id'], 'tipo' => $r['tipo'], 'texto' => (string) $r['texto'], 'personas' => count( $r['askers'] ), 'desde' => $r['created_at'] );
		}
		return self::json( array( 'ok' => true, 'modo' => self::mode(), 'abiertas' => count( $open ), 'avisos' => $avisos, 'respuestas' => $respuestas, 'viejas' => $old, 'enlace' => $old ? self::store_url() : '' ) );
	}

	/** POST /bot/ficha-contesta/hecho { avisos: [{id, result, note}], respuestas: [{id, knowledge_id}], viejas: [id] }. */
	public static function rest_done( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$p = (array) $request->get_json_params();
		$n = array( 'avisos' => 0, 'respuestas' => 0, 'viejas' => 0 );
		foreach ( (array) ( $p['avisos'] ?? array() ) as $a ) {
			$result = in_array( $a['result'] ?? '', array( 'sent', 'skipped', 'error' ), true ) ? $a['result'] : 'sent';
			$n['avisos'] += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'avisos' ) . ' SET notified_at = %s, result = %s, note = %s WHERE id = %d AND notified_at IS NULL', self::now(), $result, mb_substr( sanitize_text_field( (string) ( $a['note'] ?? '' ) ), 0, 190 ), absint( $a['id'] ?? 0 ) ) );
		}
		foreach ( (array) ( $p['respuestas'] ?? array() ) as $r ) {
			$kid = isset( $r['knowledge_id'] ) && is_numeric( $r['knowledge_id'] ) ? (int) $r['knowledge_id'] : null;
			$n['respuestas'] += (int) $wpdb->update( self::t( 'preguntas' ), array( 'synced_at' => self::now(), 'knowledge_id' => $kid ), array( 'id' => absint( $r['id'] ?? 0 ) ) );
		}
		foreach ( array_map( 'absint', (array) ( $p['viejas'] ?? array() ) ) as $id ) {
			$n['viejas'] += (int) $wpdb->update( self::t( 'preguntas' ), array( 'old_notified_at' => self::now() ), array( 'id' => $id ) );
		}
		return self::json( array( 'ok' => true ) + $n );
	}
}
