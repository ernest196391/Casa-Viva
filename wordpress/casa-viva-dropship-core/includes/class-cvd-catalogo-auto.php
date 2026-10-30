<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.84 «Catálogo al estándar» (auditoría del 29-oct, D124; Ernesto: «todos los productos y que lo nuevo salga igual»).
 *
 * Estándar: docs/nexo-business/auditoria-catalogo-2026-10-29/ESTANDARES.md (repo ernesto-rondon-nexo).
 * VivaBot (scripts/catalogo-fichas.mjs en el VPS) escribe la ficha con lo que ya sabemos (texto de la tienda o del
 * proveedor, «Datos de la tienda», crm_knowledge y lo que preguntan las gestoras), la valida (ningún número que no
 * esté en las fuentes) y la guarda aquí. Se publica directamente y Lennys revisa DESPUÉS (✓ / ✗ con nota).
 *
 *   GET  /bot/catalogo/pendientes?limit=&ids=&modo=    productos sin ficha automática de esta versión (o «corregir»)
 *   POST /bot/catalogo/aplicar                          guarda la ficha (copia del antes en _cvd_auto_backup)
 *   POST /bot/catalogo/deshacer                         vuelve al texto de antes
 *   POST /bot/catalogo/foto-real                        sube una foto real de la biblioteca del equipo a la galería
 *   GET  /bot/catalogo/revision-enlace                  enlace firmado (7 días) de la página ✓/✗ para Lennys
 *
 * Nunca toca precio, stock, comisión, pedidos ni usuarios. El bloque «Características y medidas» de la tienda
 * (CVD_Ficha_Estado) se conserva siempre al final de la descripción.
 * Bajo pedido: se marca _cvd_texto_fijo y CVD_Market_Bridge ya no pisa nombre ni descripción al volver a publicar;
 * la descripción corta sigue siendo el aviso de bajo pedido. Las fotos con _cvd_foto_real no se borran al reemplazar.
 */
final class CVD_Catalogo_Auto {
	public const VERSION     = 1;              // subir para volver a pasar todo el catálogo con un estándar nuevo
	public const META_V      = '_cvd_auto_v';
	public const META_AT     = '_cvd_auto_at';
	public const META_REV    = '_cvd_auto_revision'; // pendiente | ok | corregir
	public const META_NOTA   = '_cvd_auto_nota';
	public const META_CAMPOS = '_cvd_auto_campos';
	public const META_BACKUP = '_cvd_auto_backup';
	public const META_ORIG   = '_cvd_auto_original';
	public const META_FIJO   = '_cvd_texto_fijo';
	public const META_REAL   = '_cvd_foto_real';
	public const META_REALID = '_cvd_foto_real_id';
	public const PARAM       = 'cvd_revision_fichas';
	private const NS         = 'casa-viva/v1';
	private const TTL        = 604800; // 7 días

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'review_page' ), 1 );
		add_action( 'woocommerce_after_shop_loop', array( __CLASS__, 'category_text' ), 30 );
	}

	public const TERM_TEXTO = '_cvd_texto_categoria';
	public const TERM_META  = '_cvd_meta_categoria';

	/** 3.14.86: texto útil de la categoría (80–150 palabras) DEBAJO de los productos, solo en la página 1: no empuja la cuadrícula en el móvil. */
	public static function category_text(): void {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() || is_paged() ) { return; }
		$term = get_queried_object();
		$html = $term ? (string) get_term_meta( $term->term_id, self::TERM_TEXTO, true ) : '';
		if ( '' === trim( $html ) ) { return; }
		echo '<section class="cvd-cat-texto" style="max-width:760px;margin:28px auto 8px;padding:0 16px;font-size:15px;line-height:1.55;color:#2B2420">'
			. '<h2 style="font-size:18px;margin:0 0 8px">' . esc_html( $term->name ) . ' en Casa Viva</h2>' . wp_kses_post( $html ) . '</section>';
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/catalogo/pendientes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pendientes' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/catalogo/aplicar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'aplicar' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/catalogo/deshacer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'deshacer' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/catalogo/foto-real', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'foto_real' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/catalogo/revision-enlace', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'revision_enlace' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/catalogo/informe', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'informe' ), 'permission_callback' => $auth ) );
	}

	/**
	 * 3.14.86 (Fase 4, D125): ¿está el producto «listo» según el estándar? 3 fotos o más (y al menos 1 real si es propio),
	 * descripción propia de 400 letras o más, título de 25 a 75, título SEO y meta propios, alt en todas las fotos.
	 */
	public static function listo( WC_Product $p ): array {
		$imgs = self::images( $p );
		list( $main ) = self::split( (string) $p->get_description() );
		$market = self::is_market( $p );
		$reales = count( array_filter( $imgs, static fn( $i ) => $i['real'] ) );
		$falta = array();
		if ( count( $imgs ) < 3 ) { $falta[] = 'fotos'; }
		if ( ! $market && ! $reales ) { $falta[] = 'foto real'; }
		if ( mb_strlen( self::plain( $main ) ) < 400 ) { $falta[] = 'descripción'; }
		$len = mb_strlen( $p->get_name() );
		if ( $len < 25 || $len > 75 ) { $falta[] = 'título'; }
		if ( '' === (string) $p->get_meta( '_cvd_seo_title', true ) || '' === (string) $p->get_meta( '_cvd_meta_description', true ) ) { $falta[] = 'Google'; }
		if ( array_filter( $imgs, static fn( $i ) => '' === trim( $i['alt'] ) ) ) { $falta[] = 'alt'; }
		return array( 'listo' => ! $falta, 'falta' => $falta, 'propio' => ! $market, 'reales' => $reales );
	}

	/** Informe del catálogo en 5 líneas; ?enviar=1 lo manda al correo de Ernesto (como los avisos de pedidos, D112). */
	public static function informe( WP_REST_Request $request ): WP_REST_Response {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
		$n = count( $ids ); $listos = 0; $propios = 0; $propios_sin_real = 0; $falta = array(); $rev = array( 'pendiente' => 0, 'ok' => 0, 'corregir' => 0 ); $auto = 0; $titulos = array(); $preguntas = 0;
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) { continue; }
			$l = self::listo( $p );
			if ( $l['listo'] ) { $listos++; }
			foreach ( $l['falta'] as $f ) { $falta[ $f ] = ( $falta[ $f ] ?? 0 ) + 1; }
			if ( $l['propio'] ) { $propios++; if ( ! $l['reales'] ) { $propios_sin_real++; } }
			$r = (string) $p->get_meta( self::META_REV, true );
			if ( isset( $rev[ $r ] ) ) { $rev[ $r ]++; }
			if ( (int) $p->get_meta( self::META_V, true ) >= self::VERSION ) { $auto++; }
			if ( 'hidden' !== $p->get_catalog_visibility() ) { $k = remove_accents( mb_strtolower( trim( $p->get_name() ) ) ); $titulos[ $k ] = ( $titulos[ $k ] ?? 0 ) + 1; }
			$c = json_decode( (string) $p->get_meta( self::META_CAMPOS, true ), true );
			foreach ( (array) ( $c['faltan'] ?? array() ) as $x ) { if ( false !== mb_stripos( (string) $x, 'pregunta' ) ) { $preguntas++; } }
		}
		$dups = count( array_filter( $titulos, static fn( $v ) => $v > 1 ) );
		arsort( $falta );
		$lineas = array(
			sprintf( 'Productos listos: %d de %d (%d %%). Con ficha nueva del bot: %d.', $listos, $n, $n ? round( 100 * $listos / $n ) : 0, $auto ),
			sprintf( 'Propios sin ninguna foto real: %d de %d.', $propios_sin_real, $propios ),
			sprintf( 'Títulos repetidos a la vista: %d.', $dups ),
			sprintf( 'Preguntas de gestoras sin respuesta en las fichas: %d.', $preguntas ),
			sprintf( 'Revisión de Lennys: %d por revisar, %d bien, %d a corregir. Lo que más falta: %s.', $rev['pendiente'], $rev['ok'], $rev['corregir'], implode( ', ', array_map( static fn( $k, $v ) => "$k $v", array_keys( array_slice( $falta, 0, 3, true ) ), array_slice( $falta, 0, 3, true ) ) ) ),
		);
		$sent = false;
		if ( $request->get_param( 'enviar' ) && class_exists( 'CVD_Bot_Delivery_Change' ) && method_exists( 'CVD_Bot_Delivery_Change', 'mail_owner' ) ) {
			$r = CVD_Bot_Delivery_Change::mail_owner( 'Catálogo de Casa Viva: ' . $listos . ' de ' . $n . ' productos listos', implode( "\n", $lineas ), 'Catálogo de Casa Viva' );
			$sent = ! empty( $r['sent'] );
		}
		return self::json( array( 'ok' => true, 'lineas' => $lineas, 'listos' => $listos, 'total' => $n, 'falta' => $falta, 'revision' => $rev, 'enviado' => $sent ) );
	}

	private static function json( array $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	public static function is_market( WC_Product $p ): bool {
		return class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::is_market_sku( (string) $p->get_sku() );
	}

	private static function plain( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
	}

	/** Descripción larga partida en «lo nuestro» y el bloque de datos de la tienda (que nunca se borra). */
	private static function split( string $html ): array {
		return class_exists( 'CVD_Ficha_Estado' ) ? CVD_Ficha_Estado::split_block( $html ) : array( $html, '' );
	}

	private static function images( WC_Product $p ): array {
		$out = array();
		foreach ( array_values( array_filter( array_merge( array( (int) $p->get_image_id() ), array_map( 'intval', $p->get_gallery_image_ids() ) ) ) ) as $aid ) {
			$out[] = array(
				'id'   => $aid,
				'url'  => (string) ( wp_get_attachment_image_url( $aid, 'medium' ) ?: wp_get_attachment_url( $aid ) ),
				'alt'  => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
				'real' => '' !== (string) get_post_meta( $aid, self::META_REAL, true ),
			);
		}
		return $out;
	}

	/** Todo lo que el bot necesita para escribir la ficha (sin precio de costo ni datos de personas). */
	public static function datos( WC_Product $p ): array {
		list( $main, $block ) = self::split( (string) $p->get_description() );
		$attrs = array();
		foreach ( $p->get_attributes() as $a ) {
			if ( $a instanceof WC_Product_Attribute ) {
				$attrs[ wc_attribute_label( $a->get_name() ) ] = $a->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $a->get_name(), array( 'fields' => 'names' ) ) : $a->get_options();
			}
		}
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) { $attrs['variantes'][] = implode( ' / ', array_filter( array_map( 'strval', $v->get_attributes() ) ) ); }
			}
		}
		return array(
			'id'              => $p->get_id(),
			'nombre'          => $p->get_name(),
			'slug'            => $p->get_slug(),
			'url'             => get_permalink( $p->get_id() ),
			'sku'             => $p->get_sku(),
			'bajo_pedido'     => self::is_market( $p ),
			'categorias'      => wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'stock'           => $p->get_stock_status(),
			'corta'           => self::plain( (string) $p->get_short_description() ),
			'larga'           => self::plain( $main ),
			'larga_html'      => trim( $main ),
			'datos_tienda'    => self::plain( $block ),
			'campos_tienda'   => json_decode( (string) $p->get_meta( '_cvd_ficha_campos', true ), true ) ?: array(),
			'atributos'       => $attrs,
			'medidas_woo'     => array_filter( array( 'largo' => $p->get_length(), 'ancho' => $p->get_width(), 'alto' => $p->get_height(), 'peso' => $p->get_weight() ) ),
			'fotos'           => self::images( $p ),
			'seo_title'       => (string) $p->get_meta( '_cvd_seo_title', true ),
			'meta_description'=> (string) $p->get_meta( '_cvd_meta_description', true ),
			'auto'            => array( 'v' => (int) $p->get_meta( self::META_V, true ), 'at' => (string) $p->get_meta( self::META_AT, true ), 'revision' => (string) $p->get_meta( self::META_REV, true ), 'nota' => (string) $p->get_meta( self::META_NOTA, true ) ),
		);
	}

	/** modo = todos (por defecto: sin ficha de esta versión o «corregir») | corregir | ids. Lo nuevo primero. */
	public static function pendientes( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 50, absint( $request->get_param( 'limit' ) ?: 10 ) ) );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$modo = sanitize_key( (string) $request->get_param( 'modo' ) ) ?: 'todos';
		$args = array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'posts_per_page' => $limit, 'no_found_rows' => false );
		if ( $ids ) {
			$args['post__in'] = $ids; $args['orderby'] = 'post__in'; $args['posts_per_page'] = count( $ids );
		} elseif ( 'corregir' === $modo ) {
			$args['meta_query'] = array( array( 'key' => self::META_REV, 'value' => 'corregir' ) );
		} else {
			$args['meta_query'] = array( 'relation' => 'OR',
				array( 'key' => self::META_V, 'compare' => 'NOT EXISTS' ),
				array( 'key' => self::META_V, 'value' => self::VERSION, 'compare' => '<', 'type' => 'NUMERIC' ),
				array( 'key' => self::META_REV, 'value' => 'corregir' ) );
		}
		$q = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $id ) { $p = wc_get_product( $id ); if ( $p ) { $out[] = self::datos( $p ); } }
		return self::json( array( 'ok' => true, 'version' => self::VERSION, 'quedan' => (int) $q->found_posts, 'productos' => $out ) );
	}

	/** Lo que se guarda para poder deshacer. */
	private static function snapshot( WC_Product $p ): array {
		$alts = array();
		foreach ( self::images( $p ) as $i ) { $alts[ $i['id'] ] = $i['alt']; }
		return array(
			'at' => current_time( 'mysql' ), 'nombre' => $p->get_name(), 'slug' => $p->get_slug(),
			'corta' => (string) $p->get_short_description(), 'larga' => (string) $p->get_description(),
			'seo_title' => (string) $p->get_meta( '_cvd_seo_title', true ), 'meta_description' => (string) $p->get_meta( '_cvd_meta_description', true ),
			'galeria' => array_merge( array( (int) $p->get_image_id() ), array_map( 'intval', $p->get_gallery_image_ids() ) ), 'alts' => $alts,
		);
	}

	private static function purge( int $id ): void {
		do_action( 'litespeed_purge_post', $id );
		clean_post_cache( $id );
	}

	public static function aplicar( WP_REST_Request $request ): WP_REST_Response {
		$d = (array) $request->get_json_params();
		$p = wc_get_product( absint( $d['id'] ?? 0 ) );
		if ( ! $p || 'publish' !== $p->get_status() ) { return self::json( array( 'ok' => false, 'error' => 'Producto no encontrado o no publicado.' ), 404 ); }
		$nombre = trim( sanitize_text_field( (string) ( $d['titulo'] ?? '' ) ) );
		$corta = trim( (string) ( $d['corta'] ?? '' ) );
		$larga = trim( (string) ( $d['larga_html'] ?? '' ) );
		$seo = trim( sanitize_text_field( (string) ( $d['seo_title'] ?? '' ) ) );
		$meta = trim( sanitize_text_field( (string) ( $d['meta_description'] ?? '' ) ) );
		$err = array();
		if ( '' !== $nombre && ( mb_strlen( $nombre ) < 10 || mb_strlen( $nombre ) > 90 ) ) { $err[] = 'título de 10 a 90 letras'; }
		if ( mb_strlen( self::plain( $larga ) ) < 120 ) { $err[] = 'descripción larga demasiado corta'; }
		if ( mb_strlen( self::plain( $corta ) ) > 320 ) { $err[] = 'descripción corta demasiado larga'; }
		if ( mb_strlen( $seo ) > 70 ) { $err[] = 'título SEO de más de 70'; }
		if ( mb_strlen( $meta ) > 170 ) { $err[] = 'meta description de más de 170'; }
		if ( class_exists( 'CVD_Fichas' ) ) {
			$frase = CVD_Fichas::frase_prohibida( $nombre . ' ' . $corta . ' ' . $larga . ' ' . $meta );
			if ( '' !== $frase ) { $err[] = 'frase prohibida: ' . $frase; }
		}
		if ( $err ) { return self::json( array( 'ok' => false, 'error' => implode( '; ', $err ) ), 422 ); }

		$before = self::snapshot( $p );
		if ( '' === (string) $p->get_meta( self::META_ORIG, true ) ) { $p->update_meta_data( self::META_ORIG, wp_json_encode( $before, JSON_UNESCAPED_UNICODE ) ); }
		$p->update_meta_data( self::META_BACKUP, wp_json_encode( $before, JSON_UNESCAPED_UNICODE ) );

		$market = self::is_market( $p );
		if ( '' !== $nombre ) { $p->set_name( $nombre ); }
		if ( ! $market && '' !== $corta ) { $p->set_short_description( wp_kses_post( $corta ) ); }
		list( , $block ) = self::split( (string) $p->get_description() );
		$keep = '';
		if ( '' !== trim( $block ) && preg_match( '#<div class="' . ( class_exists( 'CVD_Ficha_Estado' ) ? CVD_Ficha_Estado::BLOCK_CLASS : 'cvd-ficha-datos' ) . '"[^>]*>.*?</div>#is', (string) $p->get_description(), $m ) ) { $keep = "\n" . $m[0]; }
		$p->set_description( wp_kses_post( $larga ) . $keep );
		if ( '' !== $seo ) { $p->update_meta_data( '_cvd_seo_title', $seo ); }
		if ( '' !== $meta ) { $p->update_meta_data( '_cvd_meta_description', $meta ); }
		if ( $market ) { $p->update_meta_data( self::META_FIJO, current_time( 'mysql' ) ); }
		$p->update_meta_data( self::META_V, self::VERSION );
		$p->update_meta_data( self::META_AT, current_time( 'mysql' ) );
		$p->update_meta_data( self::META_REV, 'pendiente' );
		$p->delete_meta_data( self::META_NOTA );
		$p->update_meta_data( self::META_CAMPOS, wp_json_encode( array( 'campos' => (array) ( $d['campos'] ?? array() ), 'faltan' => (array) ( $d['faltan'] ?? array() ), 'modelo' => sanitize_text_field( (string) ( $d['modelo'] ?? '' ) ) ), JSON_UNESCAPED_UNICODE ) );
		$p->save();

		$gallery = array_merge( array( (int) $p->get_image_id() ), array_map( 'intval', $p->get_gallery_image_ids() ) );
		foreach ( (array) ( $d['alts'] ?? array() ) as $aid => $alt ) {
			$aid = absint( $aid );
			if ( $aid && in_array( $aid, $gallery, true ) && '' !== trim( (string) $alt ) ) { update_post_meta( $aid, '_wp_attachment_image_alt', mb_substr( sanitize_text_field( (string) $alt ), 0, 125 ) ); }
		}
		self::purge( $p->get_id() );
		return self::json( array( 'ok' => true, 'id' => $p->get_id(), 'url' => get_permalink( $p->get_id() ), 'antes' => $before ) );
	}

	/** Vuelve al texto de antes (la última copia) — lo usa la ✗ de Lennys con «volver a lo de antes». */
	public static function restore( WC_Product $p ): bool {
		$b = json_decode( (string) $p->get_meta( self::META_BACKUP, true ), true );
		if ( ! is_array( $b ) ) { return false; }
		$p->set_name( (string) $b['nombre'] );
		$p->set_short_description( (string) $b['corta'] );
		$p->set_description( (string) $b['larga'] );
		$p->update_meta_data( '_cvd_seo_title', (string) $b['seo_title'] );
		$p->update_meta_data( '_cvd_meta_description', (string) $b['meta_description'] );
		$p->save();
		foreach ( (array) ( $b['alts'] ?? array() ) as $aid => $alt ) { update_post_meta( absint( $aid ), '_wp_attachment_image_alt', (string) $alt ); }
		self::purge( $p->get_id() );
		return true;
	}

	public static function deshacer( WP_REST_Request $request ): WP_REST_Response {
		$p = wc_get_product( absint( $request->get_param( 'id' ) ) );
		if ( ! $p ) { return self::json( array( 'ok' => false, 'error' => 'No existe.' ), 404 ); }
		return self::json( array( 'ok' => self::restore( $p ) ) );
	}

	/** Foto real de la biblioteca (JPEG en base64, ya reducido). En productos propios va la primera. No se repite. */
	public static function foto_real( WP_REST_Request $request ): WP_REST_Response {
		$d = (array) $request->get_json_params();
		$p = wc_get_product( absint( $d['id'] ?? 0 ) );
		$foto = preg_replace( '/[^a-f0-9]/', '', (string) ( $d['foto_id'] ?? '' ) );
		$bin = base64_decode( (string) ( $d['jpg'] ?? '' ), true );
		if ( ! $p || '' === $foto || ! $bin || strlen( $bin ) > 600000 || 0 !== strpos( $bin, "\xFF\xD8" ) ) { return self::json( array( 'ok' => false, 'error' => 'Faltan id, foto_id o un JPEG válido de menos de 600 KB.' ), 400 ); }
		$gallery = array_values( array_filter( array_merge( array( (int) $p->get_image_id() ), array_map( 'intval', $p->get_gallery_image_ids() ) ) ) );
		foreach ( $gallery as $aid ) { if ( $foto === (string) get_post_meta( $aid, self::META_REALID, true ) ) { return self::json( array( 'ok' => true, 'ya_estaba' => true, 'attachment' => $aid ) ); } }
		$name = sanitize_file_name( ( (string) ( $d['nombre'] ?? '' ) ?: $p->get_slug() ) . '-real-' . substr( $foto, 0, 6 ) . '.jpg' );
		$up = wp_upload_bits( $name, null, $bin );
		if ( ! empty( $up['error'] ) ) { return self::json( array( 'ok' => false, 'error' => $up['error'] ), 500 ); }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$alt = mb_substr( sanitize_text_field( (string) ( $d['alt'] ?? $p->get_name() ) ), 0, 125 );
		$aid = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $alt, 'post_excerpt' => 'Foto real de la tienda', 'post_status' => 'inherit' ), $up['file'], $p->get_id() );
		if ( ! $aid || is_wp_error( $aid ) ) { return self::json( array( 'ok' => false, 'error' => 'No se pudo crear la foto.' ), 500 ); }
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $up['file'] ) );
		update_post_meta( $aid, '_wp_attachment_image_alt', $alt );
		update_post_meta( $aid, self::META_REAL, 1 );
		update_post_meta( $aid, self::META_REALID, $foto );
		if ( ! self::is_market( $p ) ) {
			// Propio: la foto real primero, pero detrás de otras reales que ya estén delante.
			$reales = array_values( array_filter( $gallery, static fn( $a ) => '' !== (string) get_post_meta( $a, self::META_REAL, true ) ) );
			$otras = array_values( array_diff( $gallery, $reales ) );
			$orden = array_merge( $reales, array( $aid ), $otras );
		} else {
			$orden = array_merge( $gallery, array( $aid ) );
		}
		$p->set_image_id( array_shift( $orden ) );
		$p->set_gallery_image_ids( $orden );
		$p->save();
		self::purge( $p->get_id() );
		return self::json( array( 'ok' => true, 'attachment' => $aid, 'url' => wp_get_attachment_url( $aid ) ) );
	}

	// ---------------------------------------------------------------- revisión de Lennys (✓ / ✗), enlace firmado

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-revision-fichas'; }

	public static function sign( int $expires ): string {
		return $expires . '-' . substr( hash_hmac( 'sha256', 'cvd-revision-fichas|' . $expires, self::key() ), 0, 24 );
	}

	public static function verify( string $token ): bool {
		if ( ! preg_match( '/^(\d{9,11})-([a-f0-9]{24})$/', $token, $m ) || (int) $m[1] < time() ) { return false; }
		return hash_equals( self::sign( (int) $m[1] ), $token );
	}

	public static function url(): string {
		return add_query_arg( array( self::PARAM => 1, 't' => self::sign( time() + self::TTL ) ), home_url( '/' ) );
	}

	public static function revision_enlace(): WP_REST_Response {
		$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => self::META_REV, 'meta_value' => 'pendiente' ) );
		return self::json( array( 'ok' => true, 'url' => self::url(), 'pendientes' => (int) $q->found_posts ) );
	}

	public static function review_page(): void {
		if ( empty( $_GET[ self::PARAM ] ) ) { return; }
		$t = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! self::verify( $t ) ) { wp_die( 'Este enlace ya no vale. Pide uno nuevo a Ernesto.', 'Revisión de fichas', array( 'response' => 403 ) ); }
		$msg = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$p = wc_get_product( absint( $_POST['id'] ?? 0 ) );
			$accion = sanitize_key( (string) ( $_POST['accion'] ?? '' ) );
			if ( $p && in_array( $accion, array( 'ok', 'corregir' ), true ) ) {
				$nota = sanitize_textarea_field( wp_unslash( (string) ( $_POST['nota'] ?? '' ) ) );
				if ( 'corregir' === $accion && ! empty( $_POST['volver'] ) ) { self::restore( $p ); $p = wc_get_product( $p->get_id() ); }
				$p->update_meta_data( self::META_REV, $accion );
				if ( '' !== $nota ) { $p->update_meta_data( self::META_NOTA, $nota ); }
				$p->update_meta_data( '_cvd_auto_revisado', current_time( 'mysql' ) );
				$p->save();
				$msg = 'ok' === $accion ? '✓ Guardado: «' . $p->get_name() . '» está bien.' : '✗ Anotado: «' . $p->get_name() . '» se corrige con tu nota.';
			}
		}
		$q = new WP_Query( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 25, 'meta_key' => self::META_REV, 'meta_value' => 'pendiente', 'orderby' => 'meta_value', 'order' => 'ASC', 'meta_query' => array( array( 'key' => self::META_AT, 'compare' => 'EXISTS' ) ) ) );
		$self = add_query_arg( array( self::PARAM => 1, 't' => $t ), home_url( '/' ) );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Revisar fichas · Casa Viva</title><style>'
			. 'body{margin:0;font:16px/1.45 system-ui,sans-serif;background:#f6f1e8;color:#2b2420}header{background:#2f4a3a;color:#fff;padding:14px 16px}h1{font-size:19px;margin:0}'
			. '.msg{background:#e3f1e6;margin:12px 16px;padding:10px 12px;border-radius:10px}.c{background:#fff;margin:12px 16px;border-radius:14px;padding:12px;box-shadow:0 1px 3px #0002}'
			. '.c img{width:84px;height:84px;object-fit:cover;border-radius:10px;float:left;margin:0 12px 6px 0}.c h2{font-size:16px;margin:0 0 4px}.c p{margin:4px 0;font-size:14px;color:#5a514a}'
			. '.c a{color:#2f4a3a}.b{display:flex;gap:8px;clear:both;margin-top:10px}.b button{flex:1;font-size:17px;padding:11px;border-radius:10px;border:0}.ok{background:#2f7a4a;color:#fff}.no{background:#b5482f;color:#fff}'
			. 'textarea{width:100%;box-sizing:border-box;margin-top:8px;border-radius:8px;border:1px solid #cbbfae;padding:8px;font:inherit}label{font-size:14px}</style></head><body>';
		echo '<header><h1>Revisar fichas nuevas</h1><div style="font-size:14px;opacity:.85">' . (int) $q->found_posts . ' por revisar · ✓ si está bien · ✗ si hay que corregir (escribe qué)</div></header>';
		if ( $msg ) { echo '<div class="msg">' . esc_html( $msg ) . '</div>'; }
		if ( ! $q->have_posts() ) { echo '<div class="c"><p>No queda nada por revisar 🙌</p></div>'; }
		foreach ( $q->posts as $post ) {
			$p = wc_get_product( $post->ID );
			if ( ! $p ) { continue; }
			$img = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
			list( $main ) = self::split( (string) $p->get_description() );
			echo '<form class="c" method="post" action="' . esc_url( $self ) . '">';
			if ( $img ) { echo '<img src="' . esc_url( $img ) . '" alt="" loading="lazy">'; }
			echo '<h2>' . esc_html( $p->get_name() ) . '</h2>';
			echo '<p>' . esc_html( wp_html_excerpt( self::plain( $main ), 260, '…' ) ) . '</p>';
			echo '<p><a href="' . esc_url( get_permalink( $p->get_id() ) ) . '" target="_blank" rel="noopener">Ver cómo quedó en la web ›</a></p>';
			echo '<input type="hidden" name="id" value="' . (int) $p->get_id() . '">';
			echo '<textarea name="nota" rows="2" placeholder="Si hay algo mal, escríbelo aquí (medida, color, material…)"></textarea>';
			echo '<label><input type="checkbox" name="volver" value="1"> Volver al texto de antes</label>';
			echo '<div class="b"><button class="ok" name="accion" value="ok">✓ Bien</button><button class="no" name="accion" value="corregir">✗ Corregir</button></div></form>';
		}
		echo '</body></html>';
		exit;
	}
}
