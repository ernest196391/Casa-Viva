<?php

defined( 'ABSPATH' ) || exit;

/**
 * Encuesta anónima de Casa Viva (3.14.35, Ernesto 16-oct): las preguntas, la limpieza de respuestas y las estadísticas.
 * Sin WordPress: solo PHP, para poder probarlo aparte (artifacts/tests/test-encuesta.php).
 * Fuente de las preguntas: ernesto-rondon-nexo/docs/nexo-business/encuesta/PREGUNTAS.md (borrador del 16-oct).
 *
 * Tipos: single (una), multi (varias; 'max' = tope; 'exclusive' = opciones que borran las demás), scale5 (1–5),
 * scale10 (0–10; 'nps' = pregunta de recomendación), text (abierta).
 * Una opción con 'text' => true abre una caja para escribir («Otra: ___»); lo escrito se guarda en «<id>_txt».
 * 'if' => [pregunta, [opciones]]: solo se pregunta (y solo se guarda) si la otra respuesta está en esa lista.
 * 'correct' => opción correcta (preguntas de comprensión 12a y 12b): sirve solo para medir el % de aciertos;
 * a la persona no se le corrige nada.
 */
final class CVD_Encuesta_Def {
	public const VERSION = 'v1-2026-10-17';
	public const PAPELES = array(
		'gestora'   => 'Gestora/gestor',
		'mensajero' => 'Mensajera/mensajero',
		'tienda'    => 'Trabajo en la tienda (dependienta o administración)',
	);
	public const ORIGENES = array( 'general', 'mensajerias', 'privado' );
	public const MAX_TEXT = 1000;
	public const MAX_OTRA = 300;
	public const POCAS = 3;

	private static ?array $cache = null;

	private static function o( string $key, string $label, bool $text = false ): array {
		return array( 'key' => $key, 'label' => $label, 'text' => $text );
	}

	/** Todas las preguntas, en orden. 'block' = comun | gestora | mensajero | tienda | final. */
	public static function questions(): array {
		if ( null !== self::$cache ) { return self::$cache; }
		$otra = self::o( 'otra', 'Otra', true );
		$prefiere = array( self::o( 'automatica', 'Respuesta automática al momento' ), self::o( 'persona', 'Respuesta de una persona, aunque tarde' ), self::o( 'mixta', 'Automática al momento y, si no sabe, que pase a una persona' ) );
		$cambiar = 'Si pudieras cambiar UNA cosa, ¿cuál sería?';
		$q = array(
			array( 'id' => 'p1', 'block' => 'comun', 'type' => 'single', 'label' => '¿Cuánto tiempo llevas en Casa Viva?', 'options' => array( self::o( 'menos_1m', 'Menos de 1 mes' ), self::o( '1_3m', 'De 1 a 3 meses' ), self::o( 'mas_3m', 'Más de 3 meses' ) ) ),

			// A · Gestoras
			array( 'id' => 'a1', 'block' => 'gestora', 'type' => 'scale5', 'label' => '¿Qué tan fácil es vender con Casa Viva ahora?', 'min' => 'Muy difícil', 'max' => 'Muy fácil' ),
			array( 'id' => 'a2', 'block' => 'gestora', 'type' => 'single', 'label' => 'El asistente de WhatsApp (el bot), ¿te resuelve lo que preguntas?', 'options' => array( self::o( 'siempre', 'Siempre' ), self::o( 'casi_siempre', 'Casi siempre' ), self::o( 'a_veces', 'A veces' ), self::o( 'casi_nunca', 'Casi nunca' ), self::o( 'no_lo_uso', 'No lo uso' ) ) ),
			array( 'id' => 'a3', 'block' => 'gestora', 'type' => 'single', 'label' => '¿Qué prefieres?', 'options' => $prefiere ),
			array( 'id' => 'a4', 'block' => 'gestora', 'type' => 'single', 'label' => '¿Qué tan rápido te contestan?', 'options' => array( self::o( 'muy_rapido', 'Muy rápido' ), self::o( 'bien', 'Bien' ), self::o( 'lento', 'Lento' ), self::o( 'muy_lento', 'Muy lento' ) ) ),
			array( 'id' => 'a5', 'block' => 'gestora', 'type' => 'multi', 'label' => '¿Qué fallos del bot has visto?', 'exclusive' => array( 'ninguno' ), 'options' => array( self::o( 'no_entiende', 'No entiende lo que pregunto' ), self::o( 'datos_mal', 'Da datos equivocados o inventa' ), self::o( 'dos_veces', 'Contesta dos veces' ), self::o( 'tarda', 'Tarda mucho' ), self::o( 'ofrece', 'Me ofrece productos que no pedí' ), self::o( 'no_avisa', 'No me avisa de mis pedidos' ), self::o( 'ninguno', 'Ninguno' ), $otra ) ),
			array( 'id' => 'a6', 'block' => 'gestora', 'type' => 'multi', 'label' => '¿Qué usas de tu área?', 'exclusive' => array( 'no_entro' ), 'options' => array( self::o( 'mi_enlace', 'Mi enlace' ), self::o( 'tienda_vales', 'Tienda para hacer vales' ), self::o( 'precios', 'Cambiar mis precios' ), self::o( 'pedir_pago', 'Pedir mi pago' ), self::o( 'fotos', 'Fotos para publicar' ), self::o( 'estudio', 'Estudio de fotos' ), self::o( 'nexo_busca', 'NEXO Busca' ), self::o( 'piedra', 'Mi piedra / Mi equipo' ), self::o( 'aprende', 'Videos de «Aprende»' ), self::o( 'no_entro', 'No entro al área' ) ) ),
			array( 'id' => 'a7', 'block' => 'gestora', 'type' => 'multi', 'max' => 3, 'label' => '¿Qué es lo más difícil o lo que más tiempo te quita?', 'options' => array( self::o( 'vale', 'Hacer el vale' ), self::o( 'hay_producto', 'Saber si hay un producto' ), self::o( 'precio_mensajeria', 'El precio de la mensajería' ), self::o( 'fotos', 'Fotos que faltan o están mal' ), self::o( 'medidas', 'Fichas sin medidas' ), self::o( 'comision', 'Cobrar mi comisión' ), self::o( 'donde_pedido', 'Saber dónde va mi pedido' ), self::o( 'entrar', 'Entrar al área (contraseña)' ), self::o( 'mensajero', 'Encontrar mensajero' ), $otra ) ),
			array( 'id' => 'a8', 'block' => 'gestora', 'type' => 'multi', 'label' => 'La web de Casa Viva: ¿qué te gusta?', 'options' => array( self::o( 'bonita', 'Se ve bonita' ), self::o( 'buscar', 'Es fácil buscar' ), self::o( 'mi_tienda', 'Mi tienda con mis precios' ), self::o( 'seguimiento', 'Seguimiento del pedido' ), self::o( 'fotos', 'Fotos para publicar' ), $otra ) ),
			array( 'id' => 'a9', 'block' => 'gestora', 'type' => 'multi', 'label' => '¿Y qué no te gusta de la web?', 'options' => array( self::o( 'lenta', 'Es lenta' ), self::o( 'datos', 'Gasta muchos datos' ), self::o( 'encontrar', 'Cuesta encontrar productos' ), self::o( 'faltan_fotos', 'Faltan fotos o colores' ), self::o( 'medidas', 'Faltan medidas' ), self::o( 'pasos', 'Demasiados pasos' ), $otra ) ),
			array( 'id' => 'a10', 'block' => 'gestora', 'type' => 'multi', 'label' => 'Del sistema de antes, ¿qué echas de menos?', 'exclusive' => array( 'nada' ), 'options' => array( self::o( 'nada', 'Nada' ), self::o( 'pdf', 'La lista de precios en PDF' ), self::o( 'catalogo', 'El catálogo por grupos' ), self::o( 'persona', 'Hablar siempre con una persona' ), $otra ) ),
			array( 'id' => 'a11', 'block' => 'gestora', 'type' => 'single', 'label' => 'Avisos por WhatsApp (pedido listo, mensajero, entregado…):', 'options' => array( self::o( 'demasiados', 'Demasiados' ), self::o( 'justos', 'Los justos' ), self::o( 'faltan', 'Faltan avisos' ) ) ),
			array( 'id' => 'a12', 'block' => 'gestora', 'type' => 'single', 'label' => 'Programa de piedras: ¿lo conoces?', 'options' => array( self::o( 'si_uso', 'Sí, lo uso' ), self::o( 'oido', 'He oído hablar' ), self::o( 'no', 'No lo conozco' ) ) ),
			array( 'id' => 'a12a', 'block' => 'gestora', 'type' => 'single', 'if' => array( 'a12', array( 'si_uso', 'oido' ) ), 'correct' => '8', 'label' => 'Si una venta tuya le deja a la tienda 8 USD limpios, ¿cuántos puntos te da?', 'options' => array( self::o( '8', '8' ), self::o( '80', '80' ), self::o( '0_8', '0,8' ), self::o( 'no_se', 'No sé' ) ) ),
			array( 'id' => 'a12b', 'block' => 'gestora', 'type' => 'single', 'if' => array( 'a12', array( 'si_uso', 'oido' ) ), 'correct' => '1usd', 'label' => 'Tu invitada vende 100 USD en un mes. ¿Cuánto ganas tú por eso?', 'options' => array( self::o( '1usd', '1 USD' ), self::o( '2usd', '2 USD' ), self::o( '10usd', '10 USD' ), self::o( 'no_se', 'No sé' ) ) ),
			array( 'id' => 'a12c', 'block' => 'gestora', 'type' => 'single', 'if' => array( 'a12', array( 'si_uso', 'oido' ) ), 'label' => 'El programa de piedras, ¿te motiva a vender más o a invitar?', 'options' => array( self::o( 'mucho', 'Mucho' ), self::o( 'algo', 'Algo' ), self::o( 'poco', 'Poco' ), self::o( 'nada', 'Nada' ) ) ),
			array( 'id' => 'a13', 'block' => 'gestora', 'type' => 'single', 'label' => 'Herramientas nuevas (NEXO Busca, Estudio de fotos, Pedir mi pago):', 'options' => array( self::o( 'ayudan', 'Las uso y me ayudan' ), self::o( 'costaron', 'Las probé y me costaron' ), self::o( 'no_se', 'No sé usarlas' ), self::o( 'no_conocia', 'No las conocía' ) ) ),
			array( 'id' => 'a14', 'block' => 'gestora', 'type' => 'scale10', 'nps' => true, 'label' => 'Del 0 al 10, ¿le recomendarías a una amiga ser gestora de Casa Viva?', 'min' => 'Nunca', 'max' => 'Seguro que sí' ),
			array( 'id' => 'a15', 'block' => 'gestora', 'type' => 'text', 'label' => $cambiar ),

			// B · Mensajeros
			array( 'id' => 'b1', 'block' => 'mensajero', 'type' => 'scale5', 'label' => '¿Qué tan fácil es coger y hacer una carrera?', 'min' => 'Muy difícil', 'max' => 'Muy fácil' ),
			array( 'id' => 'b2', 'block' => 'mensajero', 'type' => 'multi', 'label' => '¿Al vale le falta algo?', 'exclusive' => array( 'nada' ), 'options' => array( self::o( 'direccion', 'Dirección clara' ), self::o( 'mapa', 'Mapa' ), self::o( 'telefono', 'Teléfono del cliente' ), self::o( 'cobrar', 'Cuánto cobrar y cómo' ), self::o( 'foto', 'Foto del producto' ), self::o( 'dia_hora', 'Día y hora' ), self::o( 'nada', 'No le falta nada' ), $otra ) ),
			array( 'id' => 'b3', 'block' => 'mensajero', 'type' => 'single', 'label' => 'El bot, ¿te entiende (la hora, «entregado», «suelto»)?', 'options' => array( self::o( 'siempre', 'Siempre' ), self::o( 'casi_siempre', 'Casi siempre' ), self::o( 'a_veces', 'A veces' ), self::o( 'casi_nunca', 'Casi nunca' ) ) ),
			array( 'id' => 'b4', 'block' => 'mensajero', 'type' => 'multi', 'label' => '¿Qué fallos has visto?', 'exclusive' => array( 'ninguno' ), 'options' => array( self::o( 'hora_repetida', 'Me pide la hora cuando ya la di' ), self::o( 'noche', 'Avisos de noche' ), self::o( 'no_registra', 'No registra el «entregado»' ), self::o( 'pago_mal', 'Me dice cosas equivocadas del pago' ), self::o( 'dos_veces', 'Contesta dos veces' ), self::o( 'ninguno', 'Ninguno' ), $otra ) ),
			array( 'id' => 'b5', 'block' => 'mensajero', 'type' => 'single', 'label' => '¿Qué prefieres?', 'options' => $prefiere ),
			array( 'id' => 'b6', 'block' => 'mensajero', 'type' => 'single', 'label' => '¿Te queda claro cuánto cobras y adónde va el dinero del cliente?', 'options' => array( self::o( 'si', 'Sí' ), self::o( 'mas_menos', 'Más o menos' ), self::o( 'no', 'No' ) ) ),
			array( 'id' => 'b7', 'block' => 'mensajero', 'type' => 'single', 'label' => 'Las tarifas de mensajería son:', 'options' => array( self::o( 'justas', 'Justas' ), self::o( 'bajas', 'Bajas' ), self::o( 'altas', 'Altas' ), self::o( 'depende', 'Depende de la zona', true ) ) ),
			array( 'id' => 'b8', 'block' => 'mensajero', 'type' => 'single', 'label' => '¿Cómo prefieres recibir las carreras?', 'options' => array( self::o( 'grupo', 'En el grupo, con «Yo»' ), self::o( 'asignen', 'Que me las asignen' ), self::o( 'las_dos', 'Las dos' ) ) ),
			array( 'id' => 'b9', 'block' => 'mensajero', 'type' => 'single', 'label' => 'Del sistema de antes, ¿qué echas de menos?', 'options' => array( self::o( 'nada', 'Nada' ), $otra ) ),
			array( 'id' => 'b10', 'block' => 'mensajero', 'type' => 'scale10', 'nps' => true, 'label' => 'Del 0 al 10, ¿recomendarías trabajar de mensajero con Casa Viva?', 'min' => 'Nunca', 'max' => 'Seguro que sí' ),
			array( 'id' => 'b11', 'block' => 'mensajero', 'type' => 'text', 'label' => $cambiar ),

			// C · Trabajo en la tienda
			array( 'id' => 'c1', 'block' => 'tienda', 'type' => 'scale5', 'label' => '¿Qué tan fácil es la caja (Casa Viva Core)?', 'min' => 'Muy difícil', 'max' => 'Muy fácil' ),
			array( 'id' => 'c2', 'block' => 'tienda', 'type' => 'multi', 'label' => '¿Qué tarea repites que debería hacerse sola?', 'options' => array( self::o( 'subir', 'Subir productos' ), self::o( 'contar', 'Contar existencias' ), self::o( 'cuadrar', 'Cuadrar la caja' ), self::o( 'tenemos', 'Contestar «¿tenemos esto?»' ), self::o( 'dinero_mensajeros', 'Recibir el dinero de los mensajeros' ), self::o( 'avisar_gestoras', 'Avisar a las gestoras' ), self::o( 'vales', 'Preparar vales' ), $otra ) ),
			array( 'id' => 'c3', 'block' => 'tienda', 'type' => 'single', 'label' => 'Las preguntas del bot a la tienda («¿tenemos esto?»):', 'options' => array( self::o( 'demasiadas', 'Demasiadas' ), self::o( 'bien', 'Bien' ), self::o( 'pocas', 'Pocas' ), self::o( 'ocupada', 'Llegan cuando estoy ocupada' ) ) ),
			array( 'id' => 'c4', 'block' => 'tienda', 'type' => 'multi', 'label' => 'El panel: ¿qué falta o qué cuesta?', 'options' => array( self::o( 'buscar', 'Buscar pedidos' ), self::o( 'mercancia', 'Llegó mercancía' ), self::o( 'pagar', 'Ver lo que hay que pagar' ), self::o( 'existencias', 'Existencias' ), self::o( 'fotos', 'Fotos' ), $otra ) ),
			array( 'id' => 'c5', 'block' => 'tienda', 'type' => 'single', 'label' => '¿Los vales llegan con lo necesario (foto del producto, color, talla)?', 'options' => array( self::o( 'si', 'Sí' ), self::o( 'a_veces', 'A veces' ), self::o( 'no', 'No' ) ) ),
			array( 'id' => 'c6', 'block' => 'tienda', 'type' => 'single', 'label' => 'Del sistema de antes, ¿qué echas de menos?', 'options' => array( self::o( 'nada', 'Nada' ), $otra ) ),
			array( 'id' => 'c7', 'block' => 'tienda', 'type' => 'scale10', 'label' => 'Del 0 al 10, ¿cómo de bien funciona hoy la tienda con el sistema nuevo?', 'min' => 'Muy mal', 'max' => 'Muy bien' ),
			array( 'id' => 'c8', 'block' => 'tienda', 'type' => 'text', 'label' => $cambiar ),

			array( 'id' => 'f1', 'block' => 'final', 'type' => 'text', 'label' => '¿Algo más que quieras decirnos?', 'hint' => 'Opcional.' ),
		);
		self::$cache = $q;
		return $q;
	}

	public static function is_papel( string $papel ): bool { return isset( self::PAPELES[ $papel ] ); }

	/** Preguntas que ve un papel: comunes, su bloque y la final. */
	public static function for_papel( string $papel ): array {
		if ( ! self::is_papel( $papel ) ) { return array(); }
		return array_values( array_filter( self::questions(), static fn( $q ) => in_array( $q['block'], array( 'comun', $papel, 'final' ), true ) ) );
	}

	public static function find( string $id ): ?array {
		foreach ( self::questions() as $q ) { if ( $q['id'] === $id ) { return $q; } }
		return null;
	}

	public static function clean_text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) { return ''; }
		$t = strip_tags( (string) $value );
		$t = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t ) ?? '';
		$t = trim( preg_replace( '/[ \t]+/u', ' ', $t ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, $max ) : substr( $t, 0, $max );
	}

	private static function keys( array $q ): array { return array_map( static fn( $o ) => (string) $o['key'], $q['options'] ?? array() ); }

	private static function text_keys( array $q ): array {
		return array_map( static fn( $o ) => (string) $o['key'], array_filter( $q['options'] ?? array(), static fn( $o ) => ! empty( $o['text'] ) ) );
	}

	/**
	 * Limpia lo que llega del formulario (r[a1]=4, r[a5][]=..., r[a5_txt]=...) y deja solo lo válido para ese papel.
	 * Nada es obligatorio: lo que falta o no vale, no se guarda.
	 */
	public static function sanitize( string $papel, array $raw ): array {
		$out = array();
		foreach ( self::for_papel( $papel ) as $q ) {
			$id = $q['id'];
			if ( ! empty( $q['if'] ) ) {
				[ $parent, $allowed ] = $q['if'];
				if ( ! isset( $out[ $parent ] ) || ! in_array( $out[ $parent ], $allowed, true ) ) { continue; }
			}
			$v = $raw[ $id ] ?? null;
			if ( null === $v ) { continue; }
			switch ( $q['type'] ) {
				case 'single':
					if ( ! is_scalar( $v ) ) { break; }
					$v = (string) $v;
					if ( in_array( $v, self::keys( $q ), true ) ) {
						$out[ $id ] = $v;
						if ( in_array( $v, self::text_keys( $q ), true ) ) {
							$t = self::clean_text( $raw[ $id . '_txt' ] ?? '', self::MAX_OTRA );
							if ( '' !== $t ) { $out[ $id . '_txt' ] = $t; }
						}
					}
					break;
				case 'multi':
					$list = is_array( $v ) ? $v : array( $v );
					$valid = self::keys( $q );
					$sel = array();
					foreach ( $list as $item ) {
						if ( is_scalar( $item ) && in_array( (string) $item, $valid, true ) && ! in_array( (string) $item, $sel, true ) ) { $sel[] = (string) $item; }
					}
					$excl = $q['exclusive'] ?? array();
					$others = array_values( array_diff( $sel, $excl ) );
					if ( $others ) { $sel = $others; } // «Ninguno» junto con otras no tiene sentido: valen las otras.
					elseif ( $sel ) { $sel = array( $sel[0] ); }
					if ( ! empty( $q['max'] ) ) { $sel = array_slice( $sel, 0, (int) $q['max'] ); }
					if ( ! $sel ) { break; }
					$out[ $id ] = array_values( array_intersect( $valid, $sel ) ); // orden de la lista
					foreach ( self::text_keys( $q ) as $tk ) {
						if ( in_array( $tk, $out[ $id ], true ) ) {
							$t = self::clean_text( $raw[ $id . '_txt' ] ?? '', self::MAX_OTRA );
							if ( '' !== $t ) { $out[ $id . '_txt' ] = $t; }
						}
					}
					break;
				case 'scale5':
				case 'scale10':
					if ( ! is_scalar( $v ) || '' === trim( (string) $v ) || ! preg_match( '/^\d{1,2}$/', trim( (string) $v ) ) ) { break; }
					$n = (int) $v;
					[ $min, $max ] = 'scale5' === $q['type'] ? array( 1, 5 ) : array( 0, 10 );
					if ( $n >= $min && $n <= $max ) { $out[ $id ] = $n; }
					break;
				case 'text':
					$t = self::clean_text( $v, self::MAX_TEXT );
					if ( '' !== $t ) { $out[ $id ] = $t; }
					break;
			}
		}
		return $out;
	}

	/** Nota de recomendación (NPS): % de 9-10 menos % de 0-6. */
	public static function nps( array $values ): ?array {
		$values = array_values( array_filter( $values, static fn( $v ) => is_int( $v ) && $v >= 0 && $v <= 10 ) );
		$n = count( $values );
		if ( ! $n ) { return null; }
		$pro = count( array_filter( $values, static fn( $v ) => $v >= 9 ) );
		$det = count( array_filter( $values, static fn( $v ) => $v <= 6 ) );
		$pas = $n - $pro - $det;
		return array(
			'n'           => $n,
			'promotores'  => round( $pro * 100 / $n, 1 ),
			'pasivos'     => round( $pas * 100 / $n, 1 ),
			'detractores' => round( $det * 100 / $n, 1 ),
			'nps'         => round( ( $pro - $det ) * 100 / $n, 1 ),
		);
	}

	private static function pct( int $part, int $total ): float { return $total ? round( $part * 100 / $total, 1 ) : 0.0; }

	private static function sorted( array $texts ): array {
		$texts = array_values( array_filter( array_map( 'strval', $texts ), static fn( $t ) => '' !== trim( $t ) ) );
		usort( $texts, static fn( $a, $b ) => strcasecmp( $a, $b ) ); // orden alfabético: no deja ver quién contestó primero
		return $texts;
	}

	/** Estadística de una pregunta sobre las respuestas de un papel ($answers = lista de arrays limpios). */
	public static function question_stats( array $q, array $answers ): array {
		$id = $q['id'];
		$given = array_values( array_filter( $answers, static fn( $a ) => array_key_exists( $id, $a ) ) );
		$n = count( $given );
		$s = array( 'id' => $id, 'type' => $q['type'], 'label' => $q['label'], 'n' => $n, 'sin_responder' => count( $answers ) - $n );
		if ( ! empty( $q['if'] ) ) {
			[ $parent, $allowed ] = $q['if'];
			$s['se_pregunto_a'] = count( array_filter( $answers, static fn( $a ) => isset( $a[ $parent ] ) && in_array( $a[ $parent ], $allowed, true ) ) );
			$s['sin_responder'] = $s['se_pregunto_a'] - $n;
		}
		switch ( $q['type'] ) {
			case 'single':
			case 'multi':
				$opts = array();
				foreach ( $q['options'] as $o ) {
					$c = 0;
					foreach ( $given as $a ) {
						$v = $a[ $id ];
						if ( is_array( $v ) ? in_array( $o['key'], $v, true ) : (string) $v === (string) $o['key'] ) { $c++; }
					}
					$opts[ $o['key'] ] = array( 'label' => $o['label'], 'count' => $c, 'pct' => self::pct( $c, $n ) );
				}
				$s['opciones'] = $opts;
				$s['otras'] = self::sorted( array_map( static fn( $a ) => $a[ $id . '_txt' ] ?? '', $given ) );
				if ( isset( $q['correct'] ) ) {
					$ok = count( array_filter( $given, static fn( $a ) => (string) $a[ $id ] === (string) $q['correct'] ) );
					$s['aciertos'] = array( 'correcta' => $q['correct'], 'n' => $n, 'ok' => $ok, 'pct' => self::pct( $ok, $n ) );
				}
				break;
			case 'scale5':
			case 'scale10':
				[ $min, $max ] = 'scale5' === $q['type'] ? array( 1, 5 ) : array( 0, 10 );
				$vals = array_map( static fn( $a ) => (int) $a[ $id ], $given );
				$dist = array();
				for ( $i = $min; $i <= $max; $i++ ) { $c = count( array_filter( $vals, static fn( $v ) => $v === $i ) ); $dist[ $i ] = array( 'count' => $c, 'pct' => self::pct( $c, $n ) ); }
				$s['dist'] = $dist;
				$s['media'] = $n ? round( array_sum( $vals ) / $n, 2 ) : null;
				if ( 'scale10' === $q['type'] ) { $s['nps'] = self::nps( $vals ); $s['es_recomendacion'] = ! empty( $q['nps'] ); }
				break;
			case 'text':
				$s['textos'] = self::sorted( array_map( static fn( $a ) => $a[ $id ], $given ) );
				break;
		}
		return $s;
	}

	/**
	 * Estadísticas completas. $rows: filas con papel, origen, tipo (respuesta|nota) y respuestas (array).
	 * Quien llama decide si van las de prueba o no (por defecto, no).
	 */
	public static function stats( array $rows ): array {
		$resp = array_values( array_filter( $rows, static fn( $r ) => 'nota' !== ( $r['tipo'] ?? 'respuesta' ) ) );
		$notas = array_values( array_filter( $rows, static fn( $r ) => 'nota' === ( $r['tipo'] ?? '' ) ) );
		$out = array( 'total' => count( $resp ), 'por_papel' => array(), 'por_origen' => array(), 'bloques' => array(), 'notas' => array() );
		foreach ( self::ORIGENES as $o ) { $out['por_origen'][ $o ] = 0; }
		$out['por_origen']['sin_enlace'] = 0;
		foreach ( $resp as $r ) {
			$o = in_array( $r['origen'] ?? '', self::ORIGENES, true ) ? $r['origen'] : 'sin_enlace';
			$out['por_origen'][ $o ]++;
		}
		foreach ( self::PAPELES as $papel => $label ) {
			$answers = array_map( static fn( $r ) => is_array( $r['respuestas'] ?? null ) ? $r['respuestas'] : array(), array_values( array_filter( $resp, static fn( $r ) => ( $r['papel'] ?? '' ) === $papel ) ) );
			$n = count( $answers );
			$out['por_papel'][ $papel ] = $n;
			$qs = array();
			foreach ( self::for_papel( $papel ) as $q ) { $qs[ $q['id'] ] = self::question_stats( $q, $answers ); }
			$out['bloques'][ $papel ] = array( 'label' => $label, 'n' => $n, 'pocas' => $n > 0 && $n < self::POCAS, 'preguntas' => $qs );
		}
		foreach ( $notas as $r ) {
			$t = (string) ( $r['respuestas']['nota'] ?? '' );
			if ( '' !== $t ) { $out['notas'][] = array( 'papel' => (string) ( $r['papel'] ?? '' ), 'texto' => $t ); }
		}
		usort( $out['notas'], static fn( $a, $b ) => strcmp( $a['papel'], $b['papel'] ) ?: strcasecmp( $a['texto'], $b['texto'] ) );
		return $out;
	}

	/** Texto legible de una respuesta (para el CSV). */
	public static function answer_label( array $q, $value ): string {
		if ( is_array( $value ) ) { return implode( ' | ', array_map( static fn( $v ) => self::answer_label( $q, $v ), $value ) ); }
		foreach ( $q['options'] ?? array() as $o ) { if ( (string) $o['key'] === (string) $value ) { return $o['label']; } }
		return (string) $value;
	}
}
