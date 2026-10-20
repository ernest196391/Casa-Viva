<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.40 (Ernesto y Lennys, 18-oct): «No puede quedar un producto que no tenga las medidas y las características en
 * la web». Hoy Dany pidió medidas, autonomía, velocidad y batería de la bici 4184; Katia, la marca; ayer, el material
 * del platero y si la mesita es MDF. Muchos productos creados después de la copia de BizneCubano no tienen descripción.
 *
 * Estado de la ficha de cada producto, SIN IA (reglas claras, probadas en artifacts/tests/test-ficha-estado.php):
 *   - descripción: menos de 12 palabras propias (sin «Te lo llevamos a casa…», «Recógela en la tienda…») → falta.
 *   - medidas: un número con unidad (cm, mm, m, pulgadas, ", litros, ml, W, V, mAh, kg, HP…), «45 × 30», una talla,
 *     un atributo «Medidas/Tamaño/Talla/Capacidad» o las dimensiones de WooCommerce → hay medidas.
 *   - material (productos no eléctricos): plástico, metal, madera, MDF, tela, PVC… o «Material: …» → hay material.
 *   - datos técnicos (productos eléctricos: recargable, batería, USB, LED, ventilador, voltaje…): potencia, voltaje,
 *     batería, amperios u horas con su número → hay datos técnicos (en eléctricos sustituyen al material).
 *     Los aparatos se reconocen por el nombre; en la descripción solo palabras claras (eléctrico, recargable, batería…).
 *   - cosméticos y comida (champú, crema, aceite, cápsulas…): no se les pide material.
 *   - pendientes: «Autonomía, velocidad y batería: consultar» (o «por confirmar») cuenta como falta hasta que el bloque
 *     «Características y medidas» trae esa palabra.
 * Fuera: productos de proveedor (SKU MK-, bajo pedido), privados y de prueba.
 *
 * Dónde se ve: cartel «Falta información: …» en la página del producto SOLO para gestoras aprobadas y administración
 * (los clientes no lo ven), columna «Ficha» en wp-admin → Productos y caja «Ficha: estado» al editar; el panel de
 * Lennys recibe la lista en /panel/stock/levels (ficha) y la descripción en /panel/products/<id>/ficha.
 *
 * El bot (VivaBot, src/fichas.js) lee /bot/fichas (con faltas, las nuevas primero) y guarda con /bot/fichas/guardar:
 * añade un bloque «Características y medidas» al final de la descripción, sin borrar lo que hay, con fecha y fuente
 * (tienda / internet del MISMO modelo / chat / manual), y apunta cada guardado en _cvd_ficha_historial (quién, cuándo,
 * qué, de dónde). Lo «sugerido» de internet (otro modelo, sin confirmar) NUNCA entra en la ficha: lo guarda el bot.
 */
final class CVD_Ficha_Estado {
	public const META_HIST   = '_cvd_ficha_historial';
	public const META_DATOS  = '_cvd_ficha_datos';
	public const META_BACKUP = '_cvd_desc_backup_panel';
	public const BLOCK_CLASS = 'cvd-ficha-datos';
	private const NS         = 'casa-viva/v1';

	/** Qué puede faltar y cómo se dice. */
	public const FALTAS = array(
		'descripcion' => 'descripción',
		'medidas'     => 'medidas',
		'material'    => 'material',
		'tecnicas'    => 'datos técnicos (potencia, voltaje o batería)',
	);

	/** De dónde viene un dato que se guarda. El texto público no dice el nombre de la dependienta (va en el historial). */
	public const FUENTES = array(
		'tienda'   => 'Confirmado por la tienda',
		'chat'     => 'Confirmado por la tienda',
		'internet' => 'Datos del fabricante para este modelo (internet, aproximados)',
		'manual'   => 'Revisado por Casa Viva',
	);

	private const UNIT = '(?:cm|cms|mm|mts?|m|metros?|centimetros?|milimetros?|pulgadas?|pulg|litros?|lts?|l|ml|w|watts?|vatios?|kw|v|voltios?|mah|ah|wh|kwh|kg|kilos?|g|gr|gramos?|lb|libras?|oz|hp|btu|amperes?|amperios?)';
	private const TECH = '(?:w|watts?|vatios?|kw|v|voltios?|vac|vdc|mah|ah|wh|kwh|hp|a|amp|amperes?|amperios?|hz|horas?|h|km\/h|kmh|km)';
	private const MATERIALES = 'plastico|plastica|plasticos|metal|metalic[oa]s?|acero|inoxidable|aluminio|hierro|madera|mdf|melamina|aglomerado|bambu|vidrio|cristal|tela|algodon|poliester|lino|seda|saten|microfibra|terciopelo|felpa|lana|nylon|nailon|licra|lycra|spandex|pvc|silicona|goma|caucho|latex|ceramica|porcelana|loza|barro|cuero|piel sintetica|polipiel|cuerina|mimbre|rattan|ratan|papel|carton|abs|polipropileno|polietileno|eva|espuma|gomaespuma|viscoelastic[oa]|marmol|granito|hule|vinil|vinilo|acrilico|policarbonato|resina|cobre|laton|bronce|zinc|cromad[oa]|teflon|antiadherente|yute|corcho|malla|denim|mezclilla|peluche|fieltro|tejido|textil|lona|neopreno|enlozad[oa]|esmaltad[oa]|hojalata';
	private const ELECTRICO = 'electric[oa]s?|electronic[oa]s?|recargables?|bateria|baterias|pilas|usb|led|cargador|ventilador|bombillo|lampara|voltaje|multicontacto|regleta|enchufe|inversor|panel solar|power ?bank|bocina|altavoz|audifonos|auriculares|licuadora|batidora|arrocera|freidora|microondas|nevera|refrigerador|hervidor|secador de pelo|secadora|rizador|alisador|motor|scooter|aire acondicionado|split|televisor|smart tv|router|camara|smartwatch|reloj inteligente|celular|telefono|tablet|laptop|computadora|mouse|teclado|masajeador|depiladora|afeitadora|termo electrico|calentador|sandwichera|tostadora|picadora|aspiradora|lavadora|hornilla|induccion|fotovoltaic[oa]s?|solar|linterna|farol|faroles|ecoflow|generador';
	private const CONSUMIBLE = 'champu|shampoo?|acondicionador|crema|balsamo|aceite|capsulas|edulcorante|jabon|serum|perfume|colonia|locion|mascarilla|tinte|vitaminas?|suplemento|cafe|skincare|desodorante|exfoliante|tonico|protector solar|cosmetic[oa]s?';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_notice' ), 25 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'admin_column' ), 30 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 30, 2 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ), 30 );
	}

	/* ================================================================ reglas (puras) */

	/** Minúsculas y sin tildes (la ñ se queda). */
	public static function fold( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return strtr( $s, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u' ) );
	}

	/** HTML → texto con saltos de línea (párrafos, listas, títulos). */
	public static function plain( string $html ): string {
		$t = preg_replace( '#<br\s*/?>|</(?:p|li|h\d|div|tr|ul|ol)>#i', "\n", $html );
		$t = html_entity_decode( strip_tags( (string) $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = str_replace( "\xC2\xA0", ' ', $t );
		$lines = array_map( static fn( $l ) => trim( (string) preg_replace( '/[ \t]+/u', ' ', $l ) ), explode( "\n", $t ) );
		return implode( "\n", array_filter( $lines, static fn( $l ) => '' !== $l ) );
	}

	/** Separa la descripción en [lo de siempre, bloque «Características y medidas»]. */
	public static function split_block( string $html ): array {
		$re = '#<div class="' . self::BLOCK_CLASS . '"[^>]*>(.*?)</div>#is';
		$block = '';
		$outside = (string) preg_replace_callback( $re, static function ( $m ) use ( &$block ) { $block .= $m[1] . "\n"; return ''; }, $html );
		return array( $outside, $block );
	}

	/** Número de palabras propias de la descripción (sin las frases de recogida y envío que se repiten en todas). */
	public static function own_words( string $text ): int {
		$t = self::fold( $text );
		$t = (string) preg_replace( '/(?:te lo llevamos|recoge(?:la|lo|las|los)? en la tienda|calle conill|consulta disponibilidad|precio\s*:?|📍)[^.\n]*/u', ' ', $t );
		preg_match_all( '/[a-zñ]{2,}/u', $t, $m );
		return count( $m[0] );
	}

	private static function has( string $re, string $folded ): bool {
		return 1 === preg_match( $re, $folded );
	}

	/**
	 * ¿Producto eléctrico? Los aparatos se reconocen por el NOMBRE; en la descripción solo cuentan palabras claras
	 * (eléctrico, recargable, batería, pilas, cargador, voltaje, enchufe). Lectura de la web del 18-oct: «apta para
	 * lavadora», «ideal para una lámpara», «apta para inducción» o «protección solar» no hacen eléctrica una alfombra,
	 * una mesita, una olla o una sombrilla. «Mochila para laptop», «Funda para celular» y «Batería de cocina» tampoco.
	 */
	public static function is_electric( string $name, string $rest = '' ): bool {
		$clean = static fn( string $s ): string => (string) preg_replace( '/(?<![a-zñ])para (?:el |la |los |las |su |sus |tu |tus )?[a-zñ]+|bateria de cocina/u', ' ', self::fold( $s ) );
		if ( self::has( '/(?<![a-zñ])(?:' . self::ELECTRICO . ')(?![a-zñ])/u', $clean( $name ) ) ) { return true; }
		// «Compatible con cocinas de gas y eléctricas» (olla 2483) y «una batería portátil guardada dentro» (mochila 2509) tampoco.
		$rest = (string) preg_replace( '/(?<![a-zñ])(?:compatible|apta?|apto|sirve|ideal) (?:con|para) [^.\n✔️]{0,50}|bateria portatil|power ?bank/u', ' ', $clean( $rest ) );
		return self::has( '/(?<![a-zñ])(?:electric[oa]s?|recargables?|bateria|baterias|(?:a|con) pilas|cargador|voltaje|enchufe)(?![a-zñ])/u', $rest );
	}

	/** Cosméticos, comida y parecidos (por el nombre): no se les pide material; sus medidas son el contenido (ml, g). */
	public static function is_consumable( string $name ): bool {
		return self::has( '/(?<![a-zñ])(?:' . self::CONSUMIBLE . ')(?![a-zñ])/u', self::fold( $name ) );
	}

	/** «Autonomía, velocidad y batería: consultar» → ['Autonomía, velocidad y batería']. */
	public static function pending_labels( string $plain ): array {
		$out = array();
		if ( preg_match_all( '/([^\n.:;]{3,80}?)\s*:\s*(?:consultar|por confirmar|a confirmar|sin confirmar|pendiente)(?![a-zñ])/iu', $plain, $m ) ) {
			foreach ( $m[1] as $label ) {
				$label = trim( (string) preg_replace( '/^[^\p{L}\p{N}]+/u', '', $label ) );
				if ( '' !== $label && ! in_array( $label, $out, true ) ) { $out[] = $label; }
			}
		}
		return $out;
	}

	/**
	 * El estado de una ficha. $in = name, description (HTML), short (HTML), attributes [nombre => valores], dims
	 * [length, width, height, weight]. → estado (completa|falta), faltas (claves de FALTAS), pendientes (textos),
	 * etiquetas (todo lo que falta, para leer), electrico.
	 */
	public static function evaluate( array $in ): array {
		$name = (string) ( $in['name'] ?? '' );
		list( $outside, $block_html ) = self::split_block( (string) ( $in['description'] ?? '' ) );
		$desc  = self::plain( $outside );
		$block = self::plain( $block_html );
		$short = self::plain( (string) ( $in['short'] ?? '' ) );
		$attrs = (array) ( $in['attributes'] ?? array() );
		$attr_text = '';
		foreach ( $attrs as $k => $v ) { $attr_text .= $k . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . "\n"; }
		$all = self::fold( $name . "\n" . $desc . "\n" . $short . "\n" . $block . "\n" . $attr_text );

		$faltas = array();
		if ( self::own_words( $desc . "\n" . $short . "\n" . $block ) < 12 ) { $faltas[] = 'descripcion'; }

		$dims = array_filter( (array) ( $in['dims'] ?? array() ), static fn( $d ) => is_numeric( $d ) && (float) $d > 0 );
		$medidas = self::has( '/\d+(?:[.,]\d+)?\s*' . self::UNIT . '(?![a-zñ])/u', $all )
			|| self::has( '/\d+(?:[.,]\d+)?\s*(?:x|×|\*)\s*\d+/u', $all )
			|| self::has( '/\d+(?:[.,]\d+)?\s*(?:"|”|\'\')/u', $all )
			|| self::has( '/(?<![a-zñ])tallas?(?![a-zñ])/u', $all )
			|| self::has( '/(?:^|\n)\s*(?:medidas?|tamano|tamaño|talla|capacidad|dimensiones?|largo|ancho|alto)[^\n:]*:/u', self::fold( $attr_text ) )
			|| ! empty( array_intersect_key( $dims, array_flip( array( 'length', 'width', 'height' ) ) ) );
		if ( ! $medidas ) { $faltas[] = 'medidas'; }

		$electrico = self::is_electric( $name, $short . "\n" . $desc );
		if ( $electrico ) {
			if ( ! self::has( '/\d+(?:[.,]\d+)?\s*' . self::TECH . '(?![a-zñ])/u', $all ) ) { $faltas[] = 'tecnicas'; }
		} elseif ( ! self::is_consumable( $name ) ) {
			$material = self::has( '/(?<![a-zñ])(?:' . self::MATERIALES . ')(?![a-zñ])/u', $all )
				|| self::has( '/(?<![a-zñ])material(?:es)?\s*[:\-]?\s*(?!consultar|por confirmar|a confirmar|sin confirmar)[a-zñ]{3,}/u', $all );
			if ( ! $material ) { $faltas[] = 'material'; }
		}

		// «X: consultar» sigue faltando hasta que el bloque «Características y medidas» habla de X.
		$block_f = self::fold( $block );
		$pendientes = array();
		foreach ( self::pending_labels( $desc . "\n" . $short ) as $label ) {
			preg_match_all( '/[a-zñ]{4,}/u', self::fold( $label ), $w );
			$solved = ! empty( $w[0] ) && '' !== $block_f && (bool) array_filter( $w[0], static fn( $x ) => false !== strpos( $block_f, $x ) );
			if ( ! $solved ) { $pendientes[] = $label; }
		}

		$etiquetas = array_map( static fn( $k ) => self::FALTAS[ $k ], $faltas );
		foreach ( $pendientes as $p ) { $etiquetas[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p ); }
		return array(
			'estado'     => $faltas || $pendientes ? 'falta' : 'completa',
			'faltas'     => $faltas,
			'pendientes' => $pendientes,
			'etiquetas'  => array_values( array_unique( $etiquetas ) ),
			'electrico'  => $electrico,
		);
	}

	/** «medidas, material y descripción». */
	public static function list_text( array $items ): string {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		if ( count( $items ) < 2 ) { return $items[0] ?? ''; }
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' y ' . $last;
	}

	/**
	 * Añade datos al bloque «Características y medidas» sin borrar nada. $lines = textos ya limpios. Las líneas que ya
	 * están escritas en la descripción no se repiten. → [html nuevo, líneas añadidas].
	 */
	public static function append_block( string $html, array $lines, string $fuente, string $fecha ): array {
		$folded = self::fold( self::plain( $html ) );
		$new = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $line ) ) );
			$line = (string) preg_replace( '/^[\-•·*]\s*/u', '', $line );
			if ( '' === $line || mb_strlen( $line ) > 300 ) { continue; }
			$key = self::fold( $line );
			if ( false !== strpos( $folded, $key ) || in_array( $line, $new, true ) ) { continue; }
			$new[] = $line;
		}
		if ( ! $new ) { return array( $html, array() ); }
		$label = self::FUENTES[ $fuente ] ?? self::FUENTES['tienda'];
		$section = '<ul>' . implode( '', array_map( static fn( $l ) => '<li>' . htmlspecialchars( $l, ENT_QUOTES, 'UTF-8' ) . '</li>', $new ) ) . '</ul>'
			. '<p class="cvd-ficha-fuente"><small>' . htmlspecialchars( $label . ', ' . $fecha . '.', ENT_QUOTES, 'UTF-8' ) . '</small></p>';
		$re = '#(<div class="' . self::BLOCK_CLASS . '"[^>]*>.*?)(</div>)#is';
		if ( preg_match( $re, $html ) ) {
			$out = (string) preg_replace_callback( $re, static fn( $m ) => $m[1] . $section . $m[2], $html, 1 );
		} else {
			$out = rtrim( $html ) . "\n\n" . '<div class="' . self::BLOCK_CLASS . '"><h3>Características y medidas</h3>' . $section . '</div>';
		}
		return array( $out, $new );
	}

	/** Texto del panel → HTML sencillo: líneas con «-», «•» o «*» son lista; el resto, párrafos. */
	public static function text_to_html( string $text ): string {
		$out = '';
		$list = array();
		$flush = static function () use ( &$list, &$out ) {
			if ( $list ) { $out .= '<ul>' . implode( '', array_map( static fn( $l ) => '<li>' . htmlspecialchars( $l, ENT_QUOTES, 'UTF-8' ) . '</li>', $list ) ) . '</ul>'; $list = array(); }
		};
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) { $flush(); continue; }
			if ( preg_match( '/^[\-•·*]\s*(.+)$/u', $line, $m ) ) { $list[] = trim( $m[1] ); continue; }
			$flush();
			$out .= '<p>' . htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' ) . '</p>';
		}
		$flush();
		return $out;
	}

	/* ================================================================ productos */

	/** Productos que no se revisan: de proveedor (MK-, bajo pedido), privados o de prueba. */
	public static function excluded( WC_Product $product ): bool {
		if ( class_exists( 'CVD_Costs' ) && CVD_Costs::is_bajo_pedido( $product ) ) { return true; }
		if ( 0 === stripos( (string) $product->get_sku( 'edit' ), 'MK-' ) ) { return true; }
		if ( in_array( $product->get_type(), array( 'grouped', 'external' ), true ) ) { return true; }
		return 1 === preg_match( '/(?<![\p{L}])(prueba|test)(?![\p{L}])/iu', (string) $product->get_name() );
	}

	/** El producto (o el padre de una variante) con su estado. */
	public static function of_product( WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) { $product = $parent; }
		}
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) ) { continue; }
			$values = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			$attrs[ function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr->get_name() ) : $attr->get_name() ] = array_map( 'strval', (array) $values );
		}
		return self::evaluate(
			array(
				'name'        => $product->get_name(),
				'description' => $product->get_description(),
				'short'       => $product->get_short_description(),
				'attributes'  => $attrs,
				'dims'        => array( 'length' => $product->get_length(), 'width' => $product->get_width(), 'height' => $product->get_height() ),
			)
		);
	}

	/** Quién ve el cartel: gestoras aprobadas y quien gestiona la tienda. Clientes, nunca. */
	public static function viewer(): bool {
		if ( ! is_user_logged_in() ) { return false; }
		if ( current_user_can( 'manage_woocommerce' ) ) { return true; }
		$user = wp_get_current_user();
		return class_exists( 'CVD_Registration' ) && CVD_Registration::is_approved_gestora( $user );
	}

	public static function notice_html( array $e ): string {
		if ( 'completa' === $e['estado'] ) { return ''; }
		return '<div class="cvd-ficha-falta" role="note" style="margin:8px 0 12px;padding:10px 12px;border-radius:10px;background:#fff4e5;border:1px solid #f0c98a;color:#6b4300;font-size:14px;line-height:1.45">'
			. '⚠️ <strong>Falta información:</strong> ' . esc_html( self::list_text( $e['etiquetas'] ) ) . '.'
			. '<br><span style="font-size:13px">Ya se la estamos pidiendo a la tienda. No le des ese dato al cliente hasta que salga aquí; si te lo pregunta, pregúntalo en el General.</span>'
			. ' <span style="font-size:12px;opacity:.75">(Solo lo ven gestoras y administración.)</span></div>';
	}

	public static function single_notice(): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! self::viewer() || self::excluded( $product ) ) { return; }
		echo self::notice_html( self::of_product( $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en notice_html().
	}

	/* ================================================================ wp-admin */

	public static function admin_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) { $out['cvd_ficha'] = 'Ficha'; }
		}
		if ( ! isset( $out['cvd_ficha'] ) ) { $out['cvd_ficha'] = 'Ficha'; }
		return $out;
	}

	public static function admin_column_value( string $column, int $post_id ): void {
		if ( 'cvd_ficha' !== $column ) { return; }
		$product = wc_get_product( $post_id );
		if ( ! $product ) { return; }
		if ( self::excluded( $product ) ) { echo '<span style="color:#888">—</span>'; return; }
		$e = self::of_product( $product );
		echo 'completa' === $e['estado'] ? '<span style="color:#2e7d32">✓ Completa</span>'
			: '<span style="color:#b26a00">⚠️ Falta: ' . esc_html( self::list_text( $e['etiquetas'] ) ) . '</span>';
	}

	public static function meta_box(): void {
		add_meta_box( 'cvd_ficha_estado', 'Ficha: medidas y características', array( __CLASS__, 'render_box' ), 'product', 'side', 'high' );
	}

	public static function render_box( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) { return; }
		if ( self::excluded( $product ) ) { echo '<p>Producto de proveedor o de prueba: no se revisa.</p>'; return; }
		$e = self::of_product( $product );
		echo 'completa' === $e['estado'] ? '<p style="color:#2e7d32"><strong>✓ Completa</strong></p>'
			: '<p style="color:#b26a00"><strong>⚠️ Falta:</strong> ' . esc_html( self::list_text( $e['etiquetas'] ) ) . '</p><p class="description">Escríbelo en la descripción (medidas en cm, material o datos técnicos). El bot también se lo pide a la tienda.</p>';
		$hist = self::history( $post->ID );
		if ( $hist ) {
			echo '<p><strong>Últimos datos guardados</strong></p><ul style="margin:0 0 0 14px;list-style:disc">';
			foreach ( array_slice( array_reverse( $hist ), 0, 8 ) as $h ) {
				echo '<li>' . esc_html( substr( (string) ( $h['at'] ?? '' ), 0, 10 ) . ' · ' . ( $h['fuente'] ?? '' ) . ( ! empty( $h['quien'] ) ? ' (' . $h['quien'] . ')' : '' ) . ': ' . implode( ' · ', (array) ( $h['lineas'] ?? array() ) ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public static function history( int $id ): array {
		$h = json_decode( (string) get_post_meta( $id, self::META_HIST, true ), true );
		return is_array( $h ) ? $h : array();
	}

	/* ================================================================ guardar */

	/**
	 * Añade datos a la ficha (bloque «Características y medidas»). $fuente: tienda | chat | internet | manual.
	 * internet exige url y modelo (el bot solo manda lo del MISMO modelo). → array o WP_Error.
	 */
	public static function save_lines( WC_Product $product, array $lines, string $fuente, array $meta = array() ) {
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) { $product = wc_get_product( $product->get_parent_id() ); }
		if ( ! $product || self::excluded( $product ) ) { return new WP_Error( 'cvd_ficha_excluido', 'Producto de proveedor, de prueba o inexistente: su ficha no se toca.', array( 'status' => 422 ) ); }
		if ( ! isset( self::FUENTES[ $fuente ] ) ) { return new WP_Error( 'cvd_ficha_fuente', 'Fuente no válida.', array( 'status' => 422 ) ); }
		$url = esc_url_raw( (string) ( $meta['url'] ?? '' ) );
		if ( 'internet' === $fuente && ( '' === $url || '' === trim( (string) ( $meta['modelo'] ?? '' ) ) ) ) {
			return new WP_Error( 'cvd_ficha_internet', 'Lo de internet solo entra con el modelo exacto y su fuente.', array( 'status' => 422 ) );
		}
		$lines = array_slice( array_values( array_filter( array_map( static fn( $l ) => sanitize_text_field( (string) $l ), $lines ) ) ), 0, 12 );
		if ( ! $lines ) { return new WP_Error( 'cvd_ficha_vacio', 'No hay datos que guardar.', array( 'status' => 422 ) ); }
		$fecha = wp_date( 'd-m-Y' );
		list( $html, $added ) = self::append_block( (string) $product->get_description( 'edit' ), $lines, $fuente, $fecha );
		if ( $added ) {
			$product->set_description( $html );
			$datos = trim( (string) $product->get_meta( self::META_DATOS, true ) );
			$product->update_meta_data( self::META_DATOS, mb_substr( trim( $datos . ( '' !== $datos ? ' · ' : '' ) . implode( ' · ', $added ) ), 0, 1500 ) );
			$hist = self::history( $product->get_id() );
			$hist[] = array_filter(
				array(
					'at'       => gmdate( 'c' ),
					'fuente'   => $fuente,
					'quien'    => sanitize_text_field( (string) ( $meta['quien'] ?? '' ) ),
					'lineas'   => $added,
					'pregunta' => sanitize_text_field( (string) ( $meta['pregunta'] ?? '' ) ),
					'url'      => $url,
					'modelo'   => sanitize_text_field( (string) ( $meta['modelo'] ?? '' ) ),
				)
			);
			$product->update_meta_data( self::META_HIST, wp_json_encode( array_slice( $hist, -50 ), JSON_UNESCAPED_UNICODE ) );
			$product->save();
		}
		$fresh = wc_get_product( $product->get_id() );
		return array( 'ok' => true, 'id' => $product->get_id(), 'added' => $added, 'ficha' => self::of_product( $fresh ) );
	}

	/* ================================================================ REST */

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/fichas', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/fichas/guardar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/panel/products/(?P<id>\d+)/ficha', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_panel_ficha' ), 'permission_callback' => array( 'CVD_Panel_Bridge', 'authorized' ) ) );
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$r = new WP_REST_Response( $data, 200 );
		$r->header( 'Cache-Control', 'no-store, private' );
		return $r;
	}

	/** Lo que el bot necesita de un producto para buscarlo y preguntarlo. */
	public static function item( WC_Product $p, array $e ): array {
		$image = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_single' ) : '';
		return array(
			'id'         => $p->get_id(),
			'nombre'     => wp_strip_all_tags( $p->get_name() ),
			'url'        => get_permalink( $p->get_id() ),
			'fecha'      => (string) get_post_field( 'post_date', $p->get_id() ),
			'sku'        => (string) $p->get_sku( 'edit' ),
			'tipo'       => $p->get_type(),
			'estado'     => $e['estado'],
			'faltas'     => $e['faltas'],
			'pendientes' => $e['pendientes'],
			'etiquetas'  => $e['etiquetas'],
			'electrico'  => $e['electrico'],
			'texto'      => mb_substr( self::plain( (string) $p->get_description() ), 0, 1200 ),
			'corta'      => mb_substr( self::plain( (string) $p->get_short_description() ), 0, 400 ),
			'categorias' => wp_list_pluck( wc_get_product_terms( $p->get_id(), 'product_cat' ), 'name' ),
			'imagen'     => $image ? (string) $image : '',
		);
	}

	/**
	 * GET /bot/fichas?limit=30&ids=1,2&todas=1. Publicados de la tienda (sin proveedor ni prueba), los más nuevos primero.
	 * Sin ids: solo los que tienen faltas (todas=1: también los completos). Con resumen: total, con_faltas, por_falta.
	 */
	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		$limit = min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 30 ) ) );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$all = (bool) $request->get_param( 'todas' );
		$query = array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true );
		if ( $ids ) { $query['post__in'] = $ids; $query['post_status'] = array( 'publish', 'private', 'draft' ); }
		$counts = array( 'revisados' => 0, 'con_faltas' => 0, 'por_falta' => array_fill_keys( array_keys( self::FALTAS ), 0 ) + array( 'pendiente' => 0 ) );
		$items = array();
		foreach ( get_posts( $query ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || self::excluded( $p ) ) { continue; }
			$e = self::of_product( $p );
			$counts['revisados']++;
			if ( 'falta' === $e['estado'] ) {
				$counts['con_faltas']++;
				foreach ( $e['faltas'] as $f ) { $counts['por_falta'][ $f ]++; }
				if ( $e['pendientes'] ) { $counts['por_falta']['pendiente']++; }
			}
			if ( count( $items ) < $limit && ( $ids || $all || 'falta' === $e['estado'] ) ) { $items[] = self::item( $p, $e ); }
		}
		return self::no_cache( array( 'ok' => true ) + $counts + array( 'items' => $items ) );
	}

	/** POST /bot/fichas/guardar { id, lineas: [], fuente, quien, pregunta, url, modelo }. */
	public static function rest_save( WP_REST_Request $request ) {
		$p = (array) $request->get_json_params();
		$product = wc_get_product( absint( $p['id'] ?? 0 ) );
		if ( ! $product ) { return new WP_Error( 'cvd_ficha_producto', 'Ese producto no existe.', array( 'status' => 404 ) ); }
		$r = self::save_lines( $product, (array) ( $p['lineas'] ?? array() ), sanitize_key( (string) ( $p['fuente'] ?? '' ) ), $p );
		return is_wp_error( $r ) ? $r : self::no_cache( $r );
	}

	/** Panel: la descripción (texto) y lo que le falta, para editarla al abrir el producto. */
	public static function rest_panel_ficha( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['id'] ) );
		if ( $product && $product->is_type( 'variation' ) ) { $product = wc_get_product( $product->get_parent_id() ); }
		if ( ! $product ) { return new WP_Error( 'cvd_ficha_producto', 'Ese producto ya no está en la web.', array( 'status' => 404 ) ); }
		$excluded = self::excluded( $product );
		$e = $excluded ? array( 'estado' => 'completa', 'faltas' => array(), 'pendientes' => array(), 'etiquetas' => array() ) : self::of_product( $product );
		return self::no_cache( array( 'ok' => true, 'wooId' => $product->get_id(), 'descripcion' => mb_substr( self::plain( (string) $product->get_description() ), 0, 6000 ), 'excluido' => $excluded ) + $e );
	}

	/** Panel: Lennys escribe la descripción al crear o editar (texto). Copia de la anterior en _cvd_desc_backup_panel. */
	public static function set_from_panel( WC_Product $product, string $text ): bool {
		$text = trim( str_replace( "\r\n", "\n", $text ) );
		if ( '' === $text || $product->is_type( 'variation' ) ) { return false; }
		$current = (string) $product->get_description( 'edit' );
		if ( self::plain( $current ) === self::plain( self::text_to_html( $text ) ) ) { return false; }
		if ( '' !== trim( $current ) ) { $product->update_meta_data( self::META_BACKUP, $current ); }
		$product->set_description( self::text_to_html( mb_substr( $text, 0, 6000 ) ) );
		$hist = self::history( $product->get_id() );
		$hist[] = array( 'at' => gmdate( 'c' ), 'fuente' => 'manual', 'quien' => 'panel', 'lineas' => array( 'Descripción escrita en el panel' ) );
		$product->update_meta_data( self::META_HIST, wp_json_encode( array_slice( $hist, -50 ), JSON_UNESCAPED_UNICODE ) );
		return true;
	}
}
