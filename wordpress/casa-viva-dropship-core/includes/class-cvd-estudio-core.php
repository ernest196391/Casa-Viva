<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Mi estudio de fotos» (3.14.1, Ernesto 14-oct): piezas puras, sin WordPress, para poder probarlas solas
 * (artifacts/tests/test-estudio-core.php):
 *   - CVD_Estudio_Vault:  cifra y descifra la clave de Google de cada gestora.
 *   - CVD_Estudio_Prompt: convierte lo que pide la gestora («el organizador en rosado en un baño bonito») en una
 *                         instrucción buena para Gemini, con las reglas de foto de producto y la regla de no inventar
 *                         colores (solo los que existen en las variaciones del producto).
 *   - CVD_Estudio_Limits: cuántas fotos, cuántos MB y cuántas por día.
 */
final class CVD_Estudio_Vault {
	private const PREFIX_SODIUM = 'v1s:';
	private const PREFIX_OPENSSL = 'v1o:';

	/** 32 bytes de clave a partir de un secreto del servidor (constante propia o claves de wp-config). */
	public static function derive_key( string $secret ): string {
		return hash_hmac( 'sha256', 'cvd-estudio-gemini-key-v1', $secret, true );
	}

	public static function encrypt( string $plain, string $key32 ): string {
		if ( 32 !== strlen( $key32 ) ) { throw new InvalidArgumentException( 'Clave de cifrado de 32 bytes' ); }
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::PREFIX_SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key32 ) );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key32, OPENSSL_RAW_DATA, $iv, $tag );
		return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher );
	}

	/** Texto original, o null si no se puede (clave del servidor cambiada, dato roto). */
	public static function decrypt( string $stored, string $key32 ): ?string {
		if ( 32 !== strlen( $key32 ) ) { return null; }
		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return null; }
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key32 );
			return false === $plain ? null : $plain;
		}
		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) { return null; }
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key32, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	/** Forma de una clave de Google AI Studio («AIza…» o la nueva «AQ.…»): letras, números, punto, guion y guion bajo. */
	public static function looks_like_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-\.]{30,200}$/', $key );
	}

	/** Quita la clave (y cualquier cosa con forma de clave de Google) de un texto antes de guardarlo en un registro. */
	public static function redact( string $text, string $key = '' ): string {
		if ( '' !== $key ) { $text = str_replace( $key, '***', $text ); }
		return (string) preg_replace( '/(?:AIza|AQ\.)[0-9A-Za-z_\-\.]{20,}/', '***', $text );
	}
}

/** Errores de Google en español sencillo (sin códigos raros ni la clave). */
final class CVD_Estudio_Errors {
	/**
	 * @param int    $http    Código HTTP (0 = no hubo respuesta).
	 * @param string $status  error.status de Google (RESOURCE_EXHAUSTED, PERMISSION_DENIED…).
	 * @param string $message error.message de Google.
	 * @param string $context 'clave' al conectar, 'foto' al crear.
	 */
	public static function human( int $http, string $status, string $message, string $context = 'foto' ): string {
		$m = strtolower( $message );
		if ( 0 === $http ) { return 'No pude hablar con Google ahora (la conexión se cortó o tardó demasiado). Prueba otra vez en un momento.'; }
		if ( false !== strpos( $m, 'location is not supported' ) || false !== strpos( $m, 'not available in your country' ) || ( 'FAILED_PRECONDITION' === $status && false !== strpos( $m, 'location' ) ) ) {
			return 'Google no deja usar Gemini desde el país de esta conexión. Tu clave está bien guardada; avisa a Casa Viva por WhatsApp para revisarlo.';
		}
		if ( false !== strpos( $m, 'api key not valid' ) || false !== strpos( $m, 'api_key_invalid' ) || false !== strpos( $m, 'api key expired' ) || 401 === $http ) {
			return 'clave' === $context ? 'Esa clave no es válida. Cópiala otra vez completa desde aistudio.google.com/apikey (es un texto largo, sin espacios) y pégala.' : 'Tu clave de Google dejó de funcionar. Vuelve a conectarla en «Conectar mi clave de Google».';
		}
		if ( 403 === $http || 'PERMISSION_DENIED' === $status ) {
			return 'Google dice que tu clave no tiene permiso para Gemini. Crea una clave nueva en aistudio.google.com/apikey y conéctala otra vez.';
		}
		if ( 429 === $http || 'RESOURCE_EXHAUSTED' === $status ) {
			if ( false !== strpos( $m, 'limit: 0' ) || false !== strpos( $m, 'free_tier' ) || false !== strpos( $m, 'billing' ) ) {
				return 'Tu clave de Google no tiene saldo para crear fotos. Google cobra unos 3 centavos de USD por foto y pide activar la facturación (una tarjeta) en tu cuenta de AI Studio. Sin eso, Google no crea fotos.';
			}
			return 'Llegaste al límite de tu clave de Google por ahora. Espera un minuto y prueba otra vez; si sigue, prueba mañana.';
		}
		if ( 404 === $http ) { return 'Google no encontró el modelo de fotos. Avisa a Casa Viva por WhatsApp.'; }
		if ( $http >= 500 ) { return 'Google está ocupado ahora mismo. Prueba otra vez en un par de minutos.'; }
		if ( 'INVALID_ARGUMENT' === $status && false !== strpos( $m, 'image' ) ) { return 'Google no pudo leer la foto que subiste. Prueba con otra foto (JPG o PNG).'; }
		return 'clave' === $context ? 'No pude comprobar tu clave con Google. Revisa que la copiaste completa y prueba otra vez.' : 'Google no pudo crear la foto esta vez. Prueba otra vez.';
	}

	/** Google contestó pero sin foto (por sus reglas de seguridad u otro motivo). */
	public static function no_image( string $finish, string $block = '' ): string {
		if ( '' !== $block || in_array( $finish, array( 'SAFETY', 'IMAGE_SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_RECITATION' ), true ) ) {
			return 'Google no quiso crear esa foto por sus reglas. Cambia un poco lo que pediste (sin famosos, sin marcas, sin niños de cerca) y prueba otra vez.';
		}
		return 'Google no devolvió ninguna foto esta vez. Prueba otra vez o cambia un poco lo que pediste.';
	}
}

/**
 * «Mi contabilidad»: suma por producto y mes lo que ya calculó Core en cada venta (instantánea de comisión de cada
 * línea), sin recalcular nada. Pura: recibe líneas ya leídas de los pedidos.
 */
final class CVD_Estudio_Ledger {
	/**
	 * @param array  $lines Cada línea: product_id, name, month ('Y-m'), state ('earned' entregada y validada,
	 *                      'pending' aún sin entregar), qty, commission, markup.
	 * @param string $month 'Y-m'.
	 * @return array{products: array<int,array>, totals: array}
	 */
	public static function aggregate( array $lines, string $month ): array {
		$products = array();
		$totals = array( 'delivered' => 0, 'pending_units' => 0, 'earned' => 0.0, 'pending' => 0.0, 'commission' => 0.0, 'markup' => 0.0 );
		foreach ( $lines as $l ) {
			if ( ( $l['month'] ?? '' ) !== $month ) { continue; }
			$state = (string) ( $l['state'] ?? '' );
			if ( ! in_array( $state, array( 'earned', 'pending' ), true ) ) { continue; }
			$pid = (int) ( $l['product_id'] ?? 0 );
			$qty = max( 0, (int) ( $l['qty'] ?? 0 ) );
			$money = round( (float) ( $l['commission'] ?? 0 ) + (float) ( $l['markup'] ?? 0 ), 4 );
			if ( ! isset( $products[ $pid ] ) ) {
				$products[ $pid ] = array( 'product_id' => $pid, 'name' => (string) ( $l['name'] ?? '' ), 'delivered' => 0, 'pending_units' => 0, 'earned' => 0.0, 'pending' => 0.0 );
			}
			if ( 'earned' === $state ) {
				$products[ $pid ]['delivered'] += $qty;
				$products[ $pid ]['earned'] += $money;
				$totals['delivered'] += $qty;
				$totals['earned'] += $money;
				$totals['commission'] += (float) ( $l['commission'] ?? 0 );
				$totals['markup'] += (float) ( $l['markup'] ?? 0 );
			} else {
				$products[ $pid ]['pending_units'] += $qty;
				$products[ $pid ]['pending'] += $money;
				$totals['pending_units'] += $qty;
				$totals['pending'] += $money;
			}
		}
		foreach ( $products as &$p ) { $p['earned'] = round( $p['earned'], 2 ); $p['pending'] = round( $p['pending'], 2 ); }
		unset( $p );
		foreach ( array( 'earned', 'pending', 'commission', 'markup' ) as $k ) { $totals[ $k ] = round( $totals[ $k ], 2 ); }
		return array( 'products' => $products, 'totals' => $totals );
	}

	/** Por unidad, hoy: comisión de Casa Viva y lo que sube ella (nunca negativo). */
	public static function unit( float $base, float $hers, string $type, float $value ): array {
		$commission = 'fixed' === $type ? $value : $base * $value / 100;
		return array( 'commission' => round( $commission, 2 ), 'markup' => round( max( 0, $hers - $base ), 2 ), 'total' => round( $commission + max( 0, $hers - $base ), 2 ) );
	}
}

final class CVD_Estudio_Limits {
	public const MAX_PHOTOS = 40;               // fotos guardadas en su galería
	public const MAX_TOTAL_BYTES = 83886080;    // 80 MB entre todas
	public const MAX_PER_DAY = 20;              // fotos creadas por día (cuida su saldo de Google y nuestro servidor)
	public const MAX_UPLOAD_BYTES = 8388608;    // foto del móvil: 8 MB
	public const MAX_TEXT = 400;                // lo que escribe
	public const MAX_PROMPT = 2500;             // instrucción editada a mano

	/** '' si cabe; si no, el motivo en español. */
	public static function gallery_check( int $count, int $total_bytes, int $new_bytes = 0 ): string {
		if ( $count >= self::MAX_PHOTOS ) { return 'Tu galería está llena (' . self::MAX_PHOTOS . ' fotos). Borra alguna que ya no uses y prueba otra vez.'; }
		if ( $total_bytes + $new_bytes >= self::MAX_TOTAL_BYTES ) { return 'Tu galería ya ocupa mucho espacio. Borra algunas fotos y prueba otra vez.'; }
		return '';
	}

	public static function day_check( int $made_today ): string {
		return $made_today >= self::MAX_PER_DAY ? 'Hoy ya creaste ' . self::MAX_PER_DAY . ' fotos. Mañana puedes seguir.' : '';
	}
}

final class CVD_Estudio_Prompt {
	/** Ideas sugeridas (botones). */
	public const PRESETS = array(
		'blanco'   => 'Fondo blanco limpio',
		'ambiente' => 'En una sala, baño o cuarto bonito',
		'color'    => 'En otro color',
		'persona'  => 'Con una persona usándolo',
		'estado'   => 'Para mi estado de WhatsApp (vertical)',
	);

	/**
	 * Colores en español (forma normalizada, sin tildes) => [grupo, inglés]. El grupo junta sinónimos y género:
	 * «rosa», «rosado» y «rosada» son el mismo color; «azul claro» no es lo mismo que «azul».
	 */
	private const COLORS = array(
		'azul marino' => array( 'azul-marino', 'navy blue' ), 'azul oscuro' => array( 'azul-marino', 'dark blue' ),
		'azul claro' => array( 'azul-claro', 'light blue' ), 'azul cielo' => array( 'azul-claro', 'sky blue' ), 'celeste' => array( 'azul-claro', 'sky blue' ),
		'azul' => array( 'azul', 'blue' ), 'turquesa' => array( 'turquesa', 'turquoise' ),
		'verde claro' => array( 'verde-claro', 'light green' ), 'verde oscuro' => array( 'verde-oscuro', 'dark green' ), 'verde' => array( 'verde', 'green' ),
		'rosado' => array( 'rosado', 'pink' ), 'rosada' => array( 'rosado', 'pink' ), 'rosa' => array( 'rosado', 'pink' ), 'fucsia' => array( 'fucsia', 'fuchsia' ),
		'rojo' => array( 'rojo', 'red' ), 'roja' => array( 'rojo', 'red' ), 'vino' => array( 'vino', 'burgundy' ), 'granate' => array( 'vino', 'burgundy' ),
		'naranja' => array( 'naranja', 'orange' ), 'anaranjado' => array( 'naranja', 'orange' ),
		'amarillo' => array( 'amarillo', 'yellow' ), 'amarilla' => array( 'amarillo', 'yellow' ),
		'morado' => array( 'morado', 'purple' ), 'morada' => array( 'morado', 'purple' ), 'violeta' => array( 'morado', 'violet' ), 'purpura' => array( 'morado', 'purple' ),
		'lila' => array( 'lila', 'lilac' ),
		'gris oscuro' => array( 'gris-oscuro', 'dark grey' ), 'gris claro' => array( 'gris-claro', 'light grey' ), 'gris' => array( 'gris', 'grey' ), 'plomo' => array( 'gris', 'grey' ),
		'negro' => array( 'negro', 'black' ), 'negra' => array( 'negro', 'black' ),
		'blanco' => array( 'blanco', 'white' ), 'blanca' => array( 'blanco', 'white' ),
		'beige' => array( 'beige', 'beige' ), 'crema' => array( 'crema', 'cream' ), 'hueso' => array( 'crema', 'off-white' ),
		'marron' => array( 'marron', 'brown' ), 'cafe' => array( 'marron', 'brown' ), 'carmelita' => array( 'marron', 'brown' ), 'chocolate' => array( 'marron', 'chocolate brown' ),
		'dorado' => array( 'dorado', 'gold' ), 'dorada' => array( 'dorado', 'gold' ), 'plateado' => array( 'plateado', 'silver' ), 'plateada' => array( 'plateado', 'silver' ),
		'transparente' => array( 'transparente', 'transparent' ),
	);

	/** Palabras de escena: un color justo detrás de ellas es del fondo, no del producto («fondo blanco», «baño gris»). */
	private const SCENE_NOUNS = array( 'fondo', 'pared', 'paredes', 'mesa', 'piso', 'suelo', 'luz', 'cielo', 'sofa', 'sillon', 'alfombra', 'azulejo', 'azulejos', 'losa', 'losas', 'madera', 'marmol', 'bano', 'sala', 'cuarto', 'cocina', 'habitacion', 'dormitorio', 'patio', 'terraza', 'ropa', 'vestido', 'camisa', 'pelo', 'cortinas', 'plantas', 'flores', 'decoracion', 'ambiente', 'estilo', 'tonos', 'tono' );

	/** Habitaciones y lugares => escena en inglés. */
	private const ROOMS = array(
		'bano' => 'a clean, pretty bathroom with light tiles and a few tasteful accessories',
		'sala' => 'a bright, modern living room with tasteful decor',
		'cuarto' => 'a cozy, bright bedroom with soft textiles', 'habitacion' => 'a cozy, bright bedroom with soft textiles', 'dormitorio' => 'a cozy, bright bedroom with soft textiles',
		'cocina' => 'a clean, modern kitchen counter',
		'comedor' => 'a bright dining room table setting',
		'patio' => 'a sunny terrace with green plants', 'terraza' => 'a sunny terrace with green plants', 'balcon' => 'a sunny balcony with green plants',
		'oficina' => 'a tidy home office desk', 'escritorio' => 'a tidy home office desk',
		'ninos' => 'a cheerful, tidy kids\' room', 'bebe' => 'a soft, calm nursery',
		'playa' => 'a bright beach setting with soft sand', 'jardin' => 'a green garden in soft daylight',
		'closet' => 'a neat closet with folded clothes', 'armario' => 'a neat closet with folded clothes',
	);

	/** Minúsculas, sin tildes ni signos, espacios simples. */
	public static function normalize( string $text ): string {
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = strtr( $text, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u' ) );
		$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/** Grupo de color de un texto suelto («Rosado claro» → 'rosado'), o '' si no es un color conocido. */
	public static function color_group( string $label ): string {
		$norm = ' ' . self::normalize( $label ) . ' ';
		foreach ( self::COLORS as $word => $info ) { // ordenados: los de dos palabras van antes que la palabra sola
			if ( false !== strpos( $norm, ' ' . $word . ' ' ) ) { return $info[0]; }
		}
		return '';
	}

	public static function color_english( string $label ): string {
		$norm = ' ' . self::normalize( $label ) . ' ';
		foreach ( self::COLORS as $word => $info ) {
			if ( false !== strpos( $norm, ' ' . $word . ' ' ) ) { return $info[1]; }
		}
		return self::normalize( $label );
	}

	/**
	 * Colores que la gestora pide PARA EL PRODUCTO en su texto (lista de palabras tal como se encontraron).
	 * No cuenta los que van detrás de una palabra de escena («fondo blanco», «un baño gris»).
	 */
	public static function colors_in_text( string $text ): array {
		$words = explode( ' ', self::normalize( $text ) );
		$found = array();
		$n = count( $words );
		for ( $i = 0; $i < $n; $i++ ) {
			$two = $i + 1 < $n ? $words[ $i ] . ' ' . $words[ $i + 1 ] : '';
			$hit = '' !== $two && isset( self::COLORS[ $two ] ) ? $two : ( isset( self::COLORS[ $words[ $i ] ] ) ? $words[ $i ] : '' );
			if ( '' === $hit ) { continue; }
			// Palabra de escena justo antes (o dos antes, con un adjetivo en medio: «paredes muy blancas» no se da; basta con 2).
			$prev = array_slice( $words, max( 0, $i - 2 ), min( 2, $i ) );
			$scene = (bool) array_intersect( $prev, self::SCENE_NOUNS );
			if ( ! $scene ) { $found[] = $hit; }
			if ( false !== strpos( $hit, ' ' ) ) { $i++; }
		}
		return array_values( array_unique( $found ) );
	}

	/** Etiquetas de $available que son ese color (mismo grupo). Vacío = ese color no existe en el producto. */
	public static function match_color( string $wanted, array $available ): array {
		$group = self::color_group( $wanted );
		$wanted_norm = self::normalize( $wanted );
		$out = array();
		foreach ( $available as $label ) {
			$label = (string) $label;
			$g = self::color_group( $label );
			if ( ( '' !== $group && $g === $group ) || ( '' !== $wanted_norm && self::normalize( $label ) === $wanted_norm ) ) { $out[] = $label; }
		}
		return $out;
	}

	/**
	 * Comprueba el color pedido (botón o texto) contra los colores reales del producto.
	 * Devuelve ['color' => etiqueta real o '', 'error' => '' o el aviso en español].
	 *
	 * @param string $chosen    Color elegido en los botones (ya es una etiqueta real) o ''.
	 * @param string $text      Lo que escribió la gestora.
	 * @param array  $available Colores que existen (variaciones/atributo del producto). [] = solo su color de la foto.
	 * @param bool   $has_product Si eligió un producto de Casa Viva.
	 */
	public static function check_color( string $chosen, string $text, array $available, bool $has_product ): array {
		if ( '' !== $chosen ) {
			$match = self::match_color( $chosen, $available );
			if ( ! $match ) { return array( 'color' => '', 'error' => 'Ese color no existe en este producto. Elige uno de los colores que tenemos.' ); }
			return array( 'color' => $match[0], 'error' => '' );
		}
		$asked = self::colors_in_text( $text );
		if ( ! $asked ) { return array( 'color' => '', 'error' => '' ); }
		if ( ! $has_product ) {
			return array( 'color' => '', 'error' => 'Para cambiar el color, elige primero el producto de Casa Viva: así compruebo que ese color existe de verdad.' );
		}
		foreach ( $asked as $word ) {
			$match = self::match_color( $word, $available );
			if ( $match ) { return array( 'color' => $match[0], 'error' => '' ); }
		}
		$pretty = implode( ', ', $asked );
		if ( ! $available ) {
			return array( 'color' => '', 'error' => 'Este producto solo lo tenemos en el color de su foto, no en ' . $pretty . '. Quita el color de tu pedido y lo hago en su color real.' );
		}
		return array( 'color' => '', 'error' => 'No tenemos este producto en ' . $pretty . '. Colores que hay: ' . implode( ', ', $available ) . '.' );
	}

	/** Habitación o lugar que menciona («en un baño bonito» → baño), en inglés; '' si no dice ninguno. */
	public static function room_in_text( string $text ): string {
		$words = explode( ' ', self::normalize( $text ) );
		foreach ( $words as $w ) {
			if ( isset( self::ROOMS[ $w ] ) ) { return self::ROOMS[ $w ]; }
		}
		return '';
	}

	/** Proporción de la foto: vertical 9:16 para el estado de WhatsApp; cuadrada para la tienda. */
	public static function aspect( string $preset, bool $vertical ): string {
		return ( $vertical || 'estado' === $preset ) ? '9:16' : '1:1';
	}

	/** Reglas que siempre van al final, aunque la gestora edite la instrucción. */
	public static function hard_rules( bool $has_product_ref, string $color_en, bool $color_from_ref ): string {
		$rules = array( 'Photorealistic photo, not an illustration or 3D render.' );
		if ( $has_product_ref ) {
			$rules[] = 'Keep the product exactly like the reference: same shape, proportions, real size, materials, texture, details and number of parts. Do not redesign it, do not add or remove parts.';
			$rules[] = '' !== $color_en && ! $color_from_ref
				? 'Change only the product color to ' . $color_en . '; keep everything else about the product identical.'
				: 'Keep the product\'s real color from the reference image.';
		}
		$rules[] = 'No text, letters, numbers, logos, brand names, watermarks, price tags, stickers, borders or frames anywhere in the image.';
		return 'Hard rules: ' . implode( ' ', $rules );
	}

	/**
	 * La instrucción completa en inglés.
	 *
	 * @param array $in name, desc, text (lo que escribió; puede venir ya pasado a inglés en text_en), preset,
	 *                  vertical, color (etiqueta real), color_from_ref (hay foto de esa variación), has_product_ref,
	 *                  has_user_photo.
	 * @return array{prompt:string, aspect:string}
	 */
	public static function build( array $in ): array {
		$name = trim( (string) ( $in['name'] ?? '' ) );
		$desc = trim( (string) ( $in['desc'] ?? '' ) );
		$text = trim( (string) ( $in['text'] ?? '' ) );
		$text_en = trim( (string) ( $in['text_en'] ?? '' ) );
		$preset = isset( self::PRESETS[ $in['preset'] ?? '' ] ) ? (string) $in['preset'] : '';
		$vertical = ! empty( $in['vertical'] ) || 'estado' === $preset;
		$color = trim( (string) ( $in['color'] ?? '' ) );
		$color_en = '' !== $color ? self::color_english( $color ) : '';
		$color_from_ref = ! empty( $in['color_from_ref'] );
		$has_ref = ! empty( $in['has_product_ref'] );
		$has_photo = ! empty( $in['has_user_photo'] );

		$lines = array();
		$subject = '' !== $name ? '"' . $name . '"' : 'the product shown in the reference photo';
		$lines[] = 'Create a professional e-commerce product photo of ' . $subject . ( '' !== $desc ? ' (' . $desc . ')' : '' ) . ' for an online home-goods store in Havana, Cuba.';
		if ( $has_ref && $has_photo ) {
			$lines[] = 'Reference images: the first image is the real product from the store catalog; the second is a phone photo from the seller. Use them together to reproduce the real product faithfully.';
		} elseif ( $has_ref || $has_photo ) {
			$lines[] = 'Reference image: it shows the real product. Reproduce that product faithfully and improve only the photo (scene, light, framing).';
		}

		$room = self::room_in_text( $text );
		if ( 'blanco' === $preset ) {
			$scene = 'Pure white seamless studio background (#FFFFFF), soft natural shadow under the product, nothing else in the frame.';
		} elseif ( 'persona' === $preset ) {
			$scene = 'A real person naturally using the product at home' . ( '' !== $room ? ', in ' . $room : '' ) . '. The product is the clear hero: fully visible, in sharp focus and at its real size next to the person. Friendly, everyday, natural look.';
		} elseif ( 'ambiente' === $preset || '' !== $room ) {
			$scene = 'Place the product in ' . ( '' !== $room ? $room : 'a bright, tidy, modern home interior where this product naturally belongs' ) . ', styled like a home-decor magazine, uncluttered, the product as the main subject.';
		} else {
			$scene = 'Simple, clean, softly lit setting that suits the product, with a calm neutral background.';
		}
		$lines[] = 'Scene: ' . $scene;
		if ( '' !== $color_en ) {
			$lines[] = $color_from_ref ? 'Product color: ' . $color_en . ', exactly as in the reference image.' : 'Product color: show the product in ' . $color_en . '.';
		}
		if ( '' !== $text_en ) {
			$lines[] = 'Seller\'s wish: ' . $text_en;
		} elseif ( '' !== $text ) {
			$lines[] = 'Seller\'s wish (in Spanish, follow it as long as it does not break the rules): "' . $text . '"';
		}
		$lines[] = 'Lighting: soft natural daylight, realistic soft shadows, true-to-life colors.';
		$lines[] = 'Camera: eye-level three-quarter view (or the angle that best shows the product), sharp focus on the product, the product fills about 60-70% of the frame' . ( $vertical ? '; vertical 9:16 composition for a phone status, with calm empty space at the top and bottom.' : '; square 1:1 composition.' );
		$lines[] = self::hard_rules( $has_ref || $has_photo, $color_en, $color_from_ref );
		return array( 'prompt' => implode( "\n", $lines ), 'aspect' => self::aspect( $preset, $vertical ) );
	}

	/**
	 * Instrucción editada a mano: se recorta y se le vuelven a poner las reglas fijas al final (no se pueden quitar).
	 */
	public static function with_rules( string $custom, bool $has_ref, string $color_en, bool $color_from_ref ): string {
		$custom = trim( mb_substr( $custom, 0, CVD_Estudio_Limits::MAX_PROMPT ) );
		$custom = trim( (string) preg_replace( '/\n?Hard rules:.*$/s', '', $custom ) );
		return $custom . "\n" . self::hard_rules( $has_ref, $color_en, $color_from_ref );
	}

	/** Instrucción para el modelo de texto que pasa a inglés lo que pidió (solo la escena, sin inventar del producto). */
	public static function rewrite_instruction(): string {
		return 'You translate a Cuban seller\'s request for a product photo into ONE short English sentence describing only the scene, mood, angle or use that she wants. '
			. 'Do not describe the product itself, do not add colors, materials, sizes, brands or text that she did not ask for. Do not add quotes. Max 40 words. Output only the sentence.';
	}
}
