<?php

/**
 * Pruebas de las piezas puras de «Mi estudio de fotos» y «Mi contabilidad» (Core 3.14.1).
 *
 * Desde la raíz del repo:
 * php artifacts/tests/test-estudio-core.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/wordpress/casa-viva-dropship-core/includes/class-cvd-estudio-core.php';

$fails = 0;
$runs = 0;
function check( string $name, $got, $want ): void {
	global $fails, $runs;
	$runs++;
	if ( $got !== $want ) {
		$fails++;
		echo "FALLA  {$name}\n  esperado: " . var_export( $want, true ) . "\n  obtenido: " . var_export( $got, true ) . "\n";
	}
}
function has( string $name, string $haystack, string $needle, bool $want = true ): void {
	check( $name, false !== strpos( $haystack, $needle ), $want );
}

/* ---------- cifrado ---------- */
$k = CVD_Estudio_Vault::derive_key( 'secreto-de-prueba-del-servidor-1234567890' );
check( 'clave derivada de 32 bytes', strlen( $k ), 32 );
$plain = 'AIzaSyD-ejemplo_de_clave_1234567890abcd';
$enc = CVD_Estudio_Vault::encrypt( $plain, $k );
check( 'cifrado no contiene la clave', false === strpos( $enc, $plain ), true );
check( 'cifrado empieza por versión', 0 === strpos( $enc, 'v1' ), true );
check( 'descifra con la misma clave', CVD_Estudio_Vault::decrypt( $enc, $k ), $plain );
check( 'dos cifrados distintos (nonce)', $enc !== CVD_Estudio_Vault::encrypt( $plain, $k ), true );
check( 'otra clave del servidor no abre', CVD_Estudio_Vault::decrypt( $enc, CVD_Estudio_Vault::derive_key( 'otro' ) ), null );
check( 'dato roto no abre', CVD_Estudio_Vault::decrypt( substr( $enc, 0, -4 ) . 'AAAA', $k ), null );
check( 'formato desconocido', CVD_Estudio_Vault::decrypt( 'texto plano', $k ), null );
check( 'clave de servidor corta = null', CVD_Estudio_Vault::decrypt( $enc, 'corta' ), null );
check( 'forma de clave buena', CVD_Estudio_Vault::looks_like_key( $plain ), true );
check( 'forma de clave mala (espacios)', CVD_Estudio_Vault::looks_like_key( 'AIza con espacios 1234567890123456789' ), false );
check( 'forma de clave nueva AQ.', CVD_Estudio_Vault::looks_like_key( 'AQ.Ab8RN6Lx_ejemplo-de-clave-nueva.1234567890abcdefghij' ), true );
check( 'redact quita AQ.', CVD_Estudio_Vault::redact( 'k=AQ.Ab8RN6Lx_ejemplo-de-clave.1234567890 fin' ), 'k=*** fin' );
check( 'forma de clave mala (corta)', CVD_Estudio_Vault::looks_like_key( 'AIza123' ), false );
check( 'redact quita la clave', CVD_Estudio_Vault::redact( 'error con ' . $plain . ' dentro', $plain ), 'error con *** dentro' );
check( 'redact quita AIza sueltas', CVD_Estudio_Vault::redact( 'key=AIzaSyAAAAAAAAAAAAAAAAAAAAAAAAAA fin' ), 'key=*** fin' );

/* ---------- colores ---------- */
check( 'normaliza tildes', CVD_Estudio_Prompt::normalize( 'Baño ROSADO, ¡bonito!' ), 'bano rosado bonito' );
check( 'color en el texto', CVD_Estudio_Prompt::colors_in_text( 'el organizador en rosado en un baño bonito' ), array( 'rosado' ) );
check( 'fondo blanco no es color del producto', CVD_Estudio_Prompt::colors_in_text( 'en un fondo blanco limpio' ), array() );
check( 'baño gris no es color del producto', CVD_Estudio_Prompt::colors_in_text( 'la cortina en un baño gris' ), array() );
check( 'dos palabras: azul claro', CVD_Estudio_Prompt::colors_in_text( 'quiero las sábanas azul claro' ), array( 'azul claro' ) );
check( 'sin colores', CVD_Estudio_Prompt::colors_in_text( 'en una sala bonita con luz de mañana' ), array() );
check( 'grupo rosa = rosado', CVD_Estudio_Prompt::color_group( 'Rosa' ), CVD_Estudio_Prompt::color_group( 'rosada' ) );
check( 'match rosado con «Rosado claro»', CVD_Estudio_Prompt::match_color( 'rosa', array( 'Azul', 'Rosado claro' ) ), array( 'Rosado claro' ) );
check( 'azul claro no es azul', CVD_Estudio_Prompt::match_color( 'azul claro', array( 'Azul', 'Gris' ) ), array() );
check( 'azul encuentra azul', CVD_Estudio_Prompt::match_color( 'azul', array( 'Azul', 'Gris' ) ), array( 'Azul' ) );
check( 'café = marrón', CVD_Estudio_Prompt::match_color( 'café', array( 'Marrón', 'Negro' ) ), array( 'Marrón' ) );

$c = CVD_Estudio_Prompt::check_color( '', 'el organizador en rosado', array( 'Azul', 'Rosado' ), true );
check( 'color pedido que existe', $c, array( 'color' => 'Rosado', 'error' => '' ) );
$c = CVD_Estudio_Prompt::check_color( '', 'el organizador en verde', array( 'Azul', 'Rosado' ), true );
check( 'color que no existe: sin color', $c['color'], '' );
has( 'color que no existe: aviso con los que hay', $c['error'], 'Colores que hay: Azul, Rosado' );
$c = CVD_Estudio_Prompt::check_color( '', 'en rojo', array(), true );
has( 'producto de un solo color', $c['error'], 'solo lo tenemos en el color de su foto' );
$c = CVD_Estudio_Prompt::check_color( '', 'en rojo', array(), false );
has( 'color sin producto elegido', $c['error'], 'elige primero el producto' );
$c = CVD_Estudio_Prompt::check_color( 'Rosado', '', array( 'Azul', 'Rosado' ), true );
check( 'botón de color válido', $c, array( 'color' => 'Rosado', 'error' => '' ) );
$c = CVD_Estudio_Prompt::check_color( 'Verde', '', array( 'Azul', 'Rosado' ), true );
has( 'botón de color inventado', $c['error'], 'no existe en este producto' );
check( 'fondo blanco sin colores no da aviso', CVD_Estudio_Prompt::check_color( '', 'fondo blanco', array(), true ), array( 'color' => '', 'error' => '' ) );

/* ---------- instrucción ---------- */
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Organizador de baño', 'desc' => 'Tres niveles', 'text' => 'el organizador en un baño bonito', 'preset' => 'ambiente', 'has_product_ref' => true ) );
has( 'nombre del producto', $p['prompt'], '"Organizador de baño"' );
has( 'escena baño', $p['prompt'], 'bathroom' );
has( 'regla producto fiel', $p['prompt'], 'Keep the product exactly like the reference' );
has( 'regla sin texto', $p['prompt'], 'No text, letters' );
has( 'regla sin marca de agua', $p['prompt'], 'watermarks' );
has( 'color real', $p['prompt'], "Keep the product's real color" );
has( 'luz natural', $p['prompt'], 'natural daylight' );
has( 'texto de ella en español', $p['prompt'], '"el organizador en un baño bonito"' );
has( 'nunca dice IA', strtolower( $p['prompt'] ), 'made with ai', false );
check( 'cuadrada por defecto', $p['aspect'], '1:1' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Toalla', 'preset' => 'blanco', 'has_product_ref' => true ) );
has( 'fondo blanco', $p['prompt'], 'Pure white seamless studio background' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Toalla', 'preset' => 'estado', 'has_product_ref' => true ) );
check( 'estado = vertical', $p['aspect'], '9:16' );
has( 'estado: composición vertical', $p['prompt'], 'vertical 9:16' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Toalla', 'preset' => 'persona', 'vertical' => true, 'has_product_ref' => true ) );
has( 'persona', $p['prompt'], 'A real person naturally using the product' );
check( 'persona vertical', $p['aspect'], '9:16' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Sábanas', 'preset' => 'color', 'color' => 'Azul claro', 'color_from_ref' => false, 'has_product_ref' => true ) );
has( 'recolor solo color', $p['prompt'], 'Change only the product color to light blue' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'Sábanas', 'preset' => 'color', 'color' => 'Azul', 'color_from_ref' => true, 'has_product_ref' => true ) );
has( 'color con foto de la variación', $p['prompt'], 'exactly as in the reference image' );
has( 'color con foto: no recolorear', $p['prompt'], 'Change only the product color', false );
$p = CVD_Estudio_Prompt::build( array( 'name' => '', 'text' => 'en una mesa', 'text_en' => 'On a wooden table in morning light.', 'has_user_photo' => true ) );
has( 'texto ya en inglés', $p['prompt'], "Seller's wish: On a wooden table" );
has( 'sin producto: el de la foto', $p['prompt'], 'the product shown in the reference photo' );
$p = CVD_Estudio_Prompt::build( array( 'name' => 'X', 'preset' => 'inventado', 'has_product_ref' => true ) );
has( 'idea desconocida = escena neutra', $p['prompt'], 'calm neutral background' );
$w = CVD_Estudio_Prompt::with_rules( "Mi foto en la playa\nHard rules: (borradas por ella)", true, '', false );
has( 'editada: conserva lo suyo', $w, 'Mi foto en la playa' );
has( 'editada: vuelve a poner reglas', $w, 'No text, letters' );
check( 'editada: una sola vez las reglas', substr_count( $w, 'Hard rules:' ), 1 );
check( 'editada: se recorta', mb_strlen( CVD_Estudio_Prompt::with_rules( str_repeat( 'a', 5000 ), false, '', false ) ) < CVD_Estudio_Limits::MAX_PROMPT + 400, true );

/* ---------- límites ---------- */
check( 'galería con sitio', CVD_Estudio_Limits::gallery_check( 3, 1000, 500 ), '' );
has( 'galería llena por número', CVD_Estudio_Limits::gallery_check( CVD_Estudio_Limits::MAX_PHOTOS, 0 ), 'galería está llena' );
has( 'galería llena por tamaño', CVD_Estudio_Limits::gallery_check( 1, CVD_Estudio_Limits::MAX_TOTAL_BYTES ), 'ocupa mucho' );
check( 'día con sitio', CVD_Estudio_Limits::day_check( CVD_Estudio_Limits::MAX_PER_DAY - 1 ), '' );
has( 'día lleno', CVD_Estudio_Limits::day_check( CVD_Estudio_Limits::MAX_PER_DAY ), 'Mañana puedes seguir' );

/* ---------- errores de Google ---------- */
has( 'sin saldo (limit: 0)', CVD_Estudio_Errors::human( 429, 'RESOURCE_EXHAUSTED', 'Quota exceeded for metric: generate_content_free_tier_requests, limit: 0' ), 'no tiene saldo' );
has( 'cuota normal', CVD_Estudio_Errors::human( 429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).' ), 'Espera un minuto' );
has( 'región', CVD_Estudio_Errors::human( 400, 'FAILED_PRECONDITION', 'User location is not supported for the API use.' ), 'país' );
has( 'clave mala al conectar', CVD_Estudio_Errors::human( 400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key.', 'clave' ), 'no es válida' );
has( 'clave mala al crear', CVD_Estudio_Errors::human( 400, 'INVALID_ARGUMENT', 'API key not valid.' ), 'Vuelve a conectarla' );
has( 'sin conexión', CVD_Estudio_Errors::human( 0, '', '' ), 'conexión' );
has( 'seguridad', CVD_Estudio_Errors::no_image( 'IMAGE_SAFETY' ), 'por sus reglas' );
has( 'sin imagen', CVD_Estudio_Errors::no_image( 'STOP' ), 'no devolvió ninguna foto' );

/* ---------- modo Gemini gratis (3.14.3) ---------- */
check( 'facturación: limit 0', CVD_Estudio_Errors::is_billing( 429, 'RESOURCE_EXHAUSTED', 'Quota exceeded ... free_tier ... limit: 0' ), true );
check( 'facturación: check your plan and billing', CVD_Estudio_Errors::is_billing( 429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.' ), true );
check( 'cuota normal no es facturación', CVD_Estudio_Errors::is_billing( 429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).' ), false );
check( 'otro error no es facturación', CVD_Estudio_Errors::is_billing( 400, 'INVALID_ARGUMENT', 'billing' ), false );
has( 'sin saldo: manda a Gemini gratis', CVD_Estudio_Errors::human( 429, 'RESOURCE_EXHAUSTED', 'check your plan and billing' ), 'gratis en la app de Gemini' );

$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Organizador de baño', 'desc' => 'Tres niveles', 'text' => 'el organizador en un baño bonito', 'preset' => 'ambiente', 'has_product_ref' => true ) );
has( 'app: en español', $a['prompt'], 'Crea una imagen' );
has( 'app: nombre del producto', $a['prompt'], '«Organizador de baño»' );
has( 'app: descripción', $a['prompt'], '(Tres niveles)' );
has( 'app: foto adjunta', $a['prompt'], 'La foto adjunta es el producto real' );
has( 'app: escena baño en español', $a['prompt'], 'un baño limpio y bonito' );
has( 'app: producto fiel', $a['prompt'], 'Mantén el producto exactamente igual' );
has( 'app: color real', $a['prompt'], 'Mantén el color real del producto' );
has( 'app: sin textos ni marcas de agua', $a['prompt'], 'Sin textos, letras, números, logos, marcas, marcas de agua' );
has( 'app: texto de ella', $a['prompt'], '«el organizador en un baño bonito»' );
has( 'app: cuadrada', $a['prompt'], 'Formato cuadrado 1:1' );
has( 'app: solo la imagen', $a['prompt'], 'Devuelve solo la imagen.' );
has( 'app: nada en inglés', $a['prompt'], 'Keep the product', false );
has( 'app: nunca dice IA', mb_strtolower( $a['prompt'] ), 'inteligencia artificial', false );
check( 'app: aspecto 1:1', $a['aspect'], '1:1' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Toalla', 'preset' => 'estado', 'has_product_ref' => true ) );
has( 'app: estado vertical', $a['prompt'], 'Formato vertical 9:16' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Toalla', 'preset' => 'blanco', 'has_product_ref' => true ) );
has( 'app: fondo blanco', $a['prompt'], 'fondo blanco puro' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Toalla', 'preset' => 'persona', 'text' => 'en la playa', 'has_product_ref' => true ) );
has( 'app: persona en la playa', $a['prompt'], 'una persona real usando el producto con naturalidad en casa, en una playa' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Sábanas', 'preset' => 'color', 'color' => 'Azul claro', 'color_from_ref' => false, 'has_product_ref' => true ) );
has( 'app: recolor a un color que existe', $a['prompt'], 'Cambia solo el color del producto a azul claro' );
has( 'app: color del producto', $a['prompt'], 'Color del producto: azul claro.' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Sábanas', 'preset' => 'color', 'color' => 'Azul', 'color_from_ref' => true, 'has_product_ref' => true ) );
has( 'app: color con foto de la variación', $a['prompt'], 'exactamente como en la foto adjunta' );
has( 'app: con foto no recolorea', $a['prompt'], 'Cambia solo el color', false );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => 'Sábanas', 'has_product_ref' => true, 'has_user_photo' => true ) );
has( 'app: dos fotos', $a['prompt'], 'Te adjunto dos fotos' );
$a = CVD_Estudio_Prompt::build_app( array( 'name' => '', 'text' => 'en una mesa', 'has_user_photo' => true ) );
has( 'app: solo foto del móvil', $a['prompt'], 'La foto adjunta es el producto real' );
has( 'app: sin nombre no pone comillas', $a['prompt'], '«»', false );
check( 'app: reglas una vez', substr_count( CVD_Estudio_Prompt::build_app( array( 'name' => 'X', 'has_product_ref' => true ) )['prompt'], 'Reglas obligatorias:' ), 1 );
has( 'app: sin punto doble tras la descripción', CVD_Estudio_Prompt::build_app( array( 'name' => 'X', 'desc' => 'Protege de golpes.', 'has_product_ref' => true ) )['prompt'], '(Protege de golpes).' );
check( 'app: sala en español', CVD_Estudio_Prompt::room_in_text_es( 'en mi Sala' ), 'una sala luminosa y moderna, con decoración sencilla' );
check( 'subida: tamaño útil', CVD_Estudio_Limits::upload_size_check( 1024, 1024 ), '' );
has( 'subida: muy pequeña', CVD_Estudio_Limits::upload_size_check( 1024, 200 ), 'muy pequeña' );
check( 'subida: tope 15 MB', CVD_Estudio_Limits::MAX_GEMINI_UPLOAD_BYTES, 15 * 1024 * 1024 );

/* ---------- contabilidad ---------- */
$lines = array(
	array( 'product_id' => 10, 'name' => 'A', 'month' => '2026-10', 'state' => 'earned', 'qty' => 2, 'commission' => 2.6, 'markup' => 4.0 ),
	array( 'product_id' => 10, 'name' => 'A', 'month' => '2026-10', 'state' => 'pending', 'qty' => 1, 'commission' => 1.3, 'markup' => 2.0 ),
	array( 'product_id' => 11, 'name' => 'B', 'month' => '2026-10', 'state' => 'earned', 'qty' => 1, 'commission' => 5.0, 'markup' => 0.0 ),
	array( 'product_id' => 11, 'name' => 'B', 'month' => '2026-09', 'state' => 'earned', 'qty' => 9, 'commission' => 45.0, 'markup' => 0.0 ),
	array( 'product_id' => 12, 'name' => 'C', 'month' => '2026-10', 'state' => 'cancelled', 'qty' => 1, 'commission' => 9.0, 'markup' => 0.0 ),
);
$agg = CVD_Estudio_Ledger::aggregate( $lines, '2026-10' );
check( 'ganado del mes', $agg['totals']['earned'], 11.6 );
check( 'pendiente del mes', $agg['totals']['pending'], 3.3 );
check( 'unidades entregadas', $agg['totals']['delivered'], 3 );
check( 'unidades pendientes', $agg['totals']['pending_units'], 1 );
check( 'comisión vs lo subido', array( $agg['totals']['commission'], $agg['totals']['markup'] ), array( 7.6, 4.0 ) );
check( 'producto A', array( $agg['products'][10]['delivered'], $agg['products'][10]['earned'], $agg['products'][10]['pending'] ), array( 2, 6.6, 3.3 ) );
check( 'otro mes no cuenta', $agg['products'][11]['delivered'], 1 );
check( 'cancelado no cuenta', isset( $agg['products'][12] ), false );
check( 'unidad %', CVD_Estudio_Ledger::unit( 30, 35, 'percent', 13 ), array( 'commission' => 3.9, 'markup' => 5.0, 'total' => 8.9 ) );
check( 'unidad fija', CVD_Estudio_Ledger::unit( 30, 30, 'fixed', 4 ), array( 'commission' => 4.0, 'markup' => 0.0, 'total' => 4.0 ) );
check( 'precio por debajo: subir nunca negativo', CVD_Estudio_Ledger::unit( 30, 25, 'fixed', 4 )['markup'], 0.0 );

echo ( $fails ? "\n{$fails} de {$runs} pruebas FALLAN\n" : "OK: {$runs} pruebas pasan\n" );
exit( $fails ? 1 : 0 );
