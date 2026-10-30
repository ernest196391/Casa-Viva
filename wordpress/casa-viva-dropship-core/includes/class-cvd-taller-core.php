<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Taller de fotos» (3.14.91, Ernesto 29-oct): piezas puras, sin WordPress, para probarlas solas
 * (artifacts/tests/test-taller-core.php).
 *
 *   - CVD_Taller_Text: del dato real del producto saca (1) un texto de plantilla que no puede inventar nada y
 *     (2) la instrucción para la IA, y (3) la «guarda» que rechaza lo que la IA devuelva si trae una cifra o una
 *     promesa (garantía, descuento, envío gratis…) que no estaba en los datos. El precio y el enlace los pone el
 *     código, nunca la IA.
 */
final class CVD_Taller_Text {
	public const KINDS = array(
		'facebook'    => 'Publicación para Facebook',
		'estado'      => 'Estado de WhatsApp (corto)',
		'descripcion' => 'Descripción del producto',
	);

	public const MAX_EXTRA = 400;

	/** Promesas que solo se aceptan si ya venían en los datos. */
	private const CLAIMS = '/garant|gratis|descuent|ofert|promoci|rebaj|100\s?%|original|importad|certific|impermeab|resistente al agua|durader|durable|de por vida|el mejor|n[uú]mero 1|sin igual|\bnuev[oa]s?\b|\blleg[oó]\b|novedad|exclusiv/iu';

	/** Quita emojis y espacios raros. */
	public static function tidy( string $s ): string {
		$s = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{2022}]/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $s ) );
	}

	/**
	 * Líneas de la descripción real del producto que sirven de dato: 25–180 letras, sin precios, medidas «consulta»,
	 * relleno ni notas internas. Hasta $max.
	 *
	 * @return string[]
	 */
	public static function description_lines( string $html, string $name, int $max = 6 ): array {
		$html = preg_replace( '/<\s*(br|\/p|\/li|\/div|\/h[1-6])\b[^>]*>/i', "\n", $html );
		$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$norm_name = self::norm( $name );
		$out = array();
		foreach ( preg_split( '/\R+/u', $text ) as $raw ) {
			$line = self::tidy( (string) $raw );
			$line = preg_replace( '/^caracter[ií]sticas(?: y detalles)?\s*:?\s*/iu', '', $line );
			$line = preg_replace( '/^\d+[.)]\s+/u', '', (string) $line );
			$line = ltrim( (string) $line, "-–—*·• " );
			$len = mb_strlen( $line );
			if ( $len < 25 || $len > 180 ) { continue; }
			if ( preg_match( '/bajo pedido|confirmamos|antes de cobrar|disponibilidad|inventario|\bstock\b|existencia|quedan|mensajer[ií]a|\bentrega|env[ií]o|proveedor|comisi[oó]n/iu', $line ) ) { continue; } // notas de operación: no son ventajas del producto
			if ( preg_match( '/^(consulta|medida|medidas|contenido|colores?|mensajer[ií]a)\b/iu', $line ) || preg_match( '/\bUSD\b|\$\s?\d|\*|\bmerma\b|\bCUP\b|\bMLC\b/iu', $line ) ) { continue; }
			if ( 0 === strpos( self::norm( $line ), $norm_name ) && mb_strlen( $line ) < mb_strlen( $name ) + 25 ) { continue; }
			if ( ! in_array( $line, $out, true ) ) { $out[] = $line; }
			if ( count( $out ) >= $max ) { break; }
		}
		return $out;
	}

	private static function norm( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : (string) iconv( 'UTF-8', 'ASCII//TRANSLIT', $s );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $s ) ) );
	}

	/** Une los datos en una sola cadena para comparar números y promesas. */
	public static function facts_blob( array $f ): string {
		$parts = array( (string) ( $f['name'] ?? '' ), (string) ( $f['extra'] ?? '' ), (string) ( $f['price'] ?? '' ) );
		foreach ( (array) ( $f['lines'] ?? array() ) as $l ) { $parts[] = (string) $l; }
		foreach ( (array) ( $f['attrs'] ?? array() ) as $k => $v ) { $parts[] = $k . ' ' . $v; }
		return implode( "\n", $parts );
	}

	/** Texto sin IA. Solo usa lo que está en $f (name, lines[], attrs[], extra, price, link). */
	public static function template( array $f, string $kind ): string {
		$name = trim( (string) ( $f['name'] ?? '' ) );
		$price = trim( (string) ( $f['price'] ?? '' ) );
		$link = trim( (string) ( $f['link'] ?? '' ) );
		$lines = array_values( (array) ( $f['lines'] ?? array() ) );
		$extra = self::tidy( (string) ( $f['extra'] ?? '' ) );
		$attrs = array();
		foreach ( (array) ( $f['attrs'] ?? array() ) as $k => $v ) { $attrs[] = $k . ': ' . $v; }

		if ( 'estado' === $kind ) {
			$out = $name . ( '' !== $price ? ' · ' . $price : '' );
			if ( $lines ) { $out .= "\n" . $lines[0]; } elseif ( '' !== $extra ) { $out .= "\n" . mb_substr( $extra, 0, 140 ); }
			return $out . ( '' !== $link ? "\nPídelo aquí 👉 " . $link : '' );
		}
		if ( 'descripcion' === $kind ) {
			$body = array_slice( $lines, 0, 4 );
			if ( '' !== $extra ) { $body[] = $extra; }
			if ( $attrs ) { $body[] = implode( ' · ', $attrs ) . '.'; }
			return trim( $name . ( $body ? "\n\n" . implode( "\n", $body ) : '' ) );
		}
		$out = '🛵 ' . $name;
		$bullets = array_slice( $lines, 0, 3 );
		if ( '' !== $extra ) { $bullets[] = $extra; }
		foreach ( $bullets as $b ) { $out .= "\n✔ " . rtrim( $b, '.' ); }
		if ( $attrs ) { $out .= "\n" . implode( ' · ', $attrs ); }
		if ( '' !== $price ) { $out .= "\n💵 " . $price; }
		$out .= "\nEscríbeme y te lo llevamos" . ( '' !== $link ? ' 👉 ' . $link : '' );
		return $out;
	}

	/** Instrucción para el modelo de texto. */
	public static function system_instruction( string $kind ): string {
		$shape = array(
			'facebook'    => 'Escribe una publicación de Facebook de 3 a 6 líneas cortas: una frase de gancho, 2 o 3 ventajas reales del producto y una invitación a escribir. Puedes usar hasta 3 emojis.',
			'estado'      => 'Escribe un texto MUY corto (máximo 2 líneas y 160 letras) para un estado de WhatsApp. Máximo 1 emoji.',
			'descripcion' => 'Escribe la descripción del producto en 2 o 3 frases claras, sin emojis, para ponerla en una ficha de tienda.',
		);
		return 'Eres redactora de una tienda cubana de artículos para el hogar. ' . ( $shape[ $kind ] ?? $shape['facebook'] ) . "\n"
			. "REGLAS ESTRICTAS:\n"
			. "- Usa SOLO los datos que te doy. Si algo no está en los datos, no lo digas.\n"
			. "- No inventes medidas, colores, materiales, marcas, stock, plazos, garantías, descuentos ni ofertas.\n"
			. "- No escribas el precio ni ningún enlace ni número de teléfono: los pone el sistema.\n"
			. "- No uses cifras que no estén en los datos.\n"
			. "- No digas que es nuevo, que acaba de llegar, que es original ni que es exclusivo si los datos no lo dicen.\n"
			. "- Español sencillo y cálido, como se habla en Cuba, sin palabras raras. No uses la palabra «gratis».\n"
			. '- Devuelve solo el texto final, sin comillas ni explicaciones.';
	}

	/** Mensaje de usuario con los datos reales. */
	public static function user_message( array $f ): string {
		$msg = 'Producto: ' . (string) ( $f['name'] ?? '' ) . "\n";
		foreach ( (array) ( $f['lines'] ?? array() ) as $l ) { $msg .= '- ' . $l . "\n"; }
		foreach ( (array) ( $f['attrs'] ?? array() ) as $k => $v ) { $msg .= $k . ': ' . $v . "\n"; }
		$extra = trim( (string) ( $f['extra'] ?? '' ) );
		if ( '' !== $extra ) { $msg .= 'Dato que añade la vendedora: ' . $extra . "\n"; }
		return trim( $msg );
	}

	/**
	 * Limpia lo que devolvió la IA y comprueba que no inventó. Devuelve el texto limpio, o '' si no es de fiar.
	 */
	public static function guard( string $ai, array $f, string $kind ): string {
		$ai = trim( str_replace( array( '“', '”', '"', '**', '__' ), '', $ai ) );
		$ai = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $ai ) );
		$max = array( 'facebook' => 700, 'estado' => 220, 'descripcion' => 600 );
		$len = mb_strlen( $ai );
		if ( $len < 20 || $len > ( $max[ $kind ] ?? 700 ) ) { return ''; }
		if ( preg_match( '#https?://|www\.|@[a-z0-9_]+|\+?\d[\d\s().-]{7,}\d#iu', $ai ) ) { return ''; } // enlaces, usuarios, teléfonos
		$blob = self::facts_blob( $f );
		preg_match_all( '/\d+(?:[.,]\d+)?/u', $ai, $m );
		$allowed = array();
		preg_match_all( '/\d+(?:[.,]\d+)?/u', $blob, $b );
		foreach ( $b[0] as $n ) { $allowed[ str_replace( ',', '.', $n ) ] = true; }
		foreach ( $m[0] as $n ) {
			if ( ! isset( $allowed[ str_replace( ',', '.', $n ) ] ) ) { return ''; } // cifra que no está en los datos
		}
		preg_match_all( self::CLAIMS, $ai, $claims );
		foreach ( $claims[0] as $c ) {
			if ( ! preg_match( '/' . preg_quote( mb_strtolower( $c ), '/' ) . '/iu', $blob ) ) { return ''; }
		}
		return $ai;
	}

	/** Pone el precio y el enlace al final (los pone el código, no la IA). */
	public static function finish( string $body, array $f, string $kind ): string {
		$price = trim( (string) ( $f['price'] ?? '' ) );
		$link = trim( (string) ( $f['link'] ?? '' ) );
		if ( 'descripcion' === $kind ) { return $body; }
		if ( 'estado' === $kind ) {
			return $body . ( '' !== $price ? ' · ' . $price : '' ) . ( '' !== $link ? "\nPídelo aquí 👉 " . $link : '' );
		}
		return $body . ( '' !== $price ? "\n💵 " . $price : '' ) . "\nEscríbeme y te lo llevamos" . ( '' !== $link ? ' 👉 ' . $link : '' );
	}
}
