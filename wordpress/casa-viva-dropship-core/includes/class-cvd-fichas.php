<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Fichas por revisar» (3.13.56) — paso 1 de la herramienta «entrada → ficha» (docs/herramientas/entrada-a-ficha.md).
 *
 * La skill /ficha de Claude Code arma la ficha (fotos de la dependienta + búsqueda web) y llama a
 * CVD_Fichas::crear_borrador(). Eso crea SIEMPRE un producto en BORRADOR, nunca publica:
 *  - Mejora de un producto que ya está en la web: borrador aparte «[REVISAR] <nombre>» con _cvd_ficha_target = id
 *    del producto en vivo. El producto en vivo NO se toca hasta que alguien pulsa «Aprobar».
 *  - Producto nuevo (llegó mercancía nueva): borrador sin target. Al aprobar se publica (solo si tiene precio).
 * Cada borrador guarda la ficha completa en _cvd_ficha (JSON con cada dato marcado confirmado / sugerido / falta y su fuente).
 *
 * Revisan Ernesto y Lennys en wp-admin → Casa Viva → Fichas por revisar: Ver, Corregir (editor de WordPress), Aprobar, Descartar.
 * Aprobar en una mejora: guarda copia del texto viejo (_cvd_ficha_backup_<fecha>), copia descripción, descripción corta,
 * título y descripción para Google, fotos nuevas de la galería y medidas al producto en vivo, y manda el borrador a la papelera.
 * Descartar: papelera (se puede recuperar). Nada se borra para siempre.
 *
 * Interruptor cvd_fichas_requiere_aprobacion (por defecto «1» = hace falta aprobar). Cuando el bot demuestre que lo hace bien,
 * se apaga desde la misma pantalla y la skill podrá aprobar sola. Hoy está ENCENDIDO.
 *
 * SEO: no hay plugin de SEO. _cvd_seo_title cambia el <title> del producto y _cvd_meta_description la meta description
 * (CVD_Site_Privacy::meta_description la usa antes que la descripción corta).
 */
final class CVD_Fichas {
	public const META_FICHA    = '_cvd_ficha';
	public const META_TARGET   = '_cvd_ficha_target';
	public const META_SEO      = '_cvd_seo_title';
	public const META_DESC     = '_cvd_meta_description';
	public const META_APROBADA = '_cvd_ficha_aprobada';
	public const META_FINAL    = '_cvd_ficha_estado_final';
	public const OPTION_SWITCH = 'cvd_fichas_requiere_aprobacion';
	public const PREFIJO       = '[REVISAR] ';
	private const PAGE         = 'cvd-fichas';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_cvd_ficha_aprobar', array( __CLASS__, 'admin_aprobar' ) );
		add_action( 'admin_post_cvd_ficha_descartar', array( __CLASS__, 'admin_descartar' ) );
		add_action( 'admin_post_cvd_ficha_interruptor', array( __CLASS__, 'admin_interruptor' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ) );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'seo_title' ), 40 );
	}

	public static function requiere_aprobacion(): bool {
		return '0' !== (string) get_option( self::OPTION_SWITCH, '1' );
	}

	/* ------------------------------------------------------------------ crear */

	/**
	 * Crea el borrador. $f = ficha (ver la skill /ficha, SKILL.md). Devuelve id y enlaces, o WP_Error.
	 * Nunca publica ni toca el producto en vivo.
	 */
	public static function crear_borrador( array $f ) {
		$target_id = absint( $f['producto']['target_id'] ?? 0 );
		$target    = $target_id ? wc_get_product( $target_id ) : null;
		if ( $target_id && ! $target ) { return new WP_Error( 'cvd_ficha_target', 'El producto ' . $target_id . ' no existe.' ); }

		$nombre = sanitize_text_field( (string) ( $f['titulo'] ?? '' ) );
		if ( '' === $nombre ) { return new WP_Error( 'cvd_ficha_titulo', 'La ficha no trae título.' ); }
		$largo = wp_kses_post( (string) ( $f['descripcion_larga'] ?? '' ) );
		$corta = wp_kses_post( (string) ( $f['descripcion_corta'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $largo ) ) ) { return new WP_Error( 'cvd_ficha_texto', 'La ficha no trae descripción.' ); }
		$prohibida = self::frase_prohibida( $nombre . ' ' . $largo . ' ' . $corta . ' ' . ( $f['meta_description'] ?? '' ) );
		if ( $prohibida ) { return new WP_Error( 'cvd_ficha_frase', 'El texto público lleva una frase prohibida: «' . $prohibida . '».' ); }

		$product = new WC_Product_Simple();
		$product->set_name( self::PREFIJO . $nombre );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' ); // por si alguien lo publica a mano desde el editor
		$product->set_description( $largo );
		$product->set_short_description( $corta );
		if ( $target ) {
			$product->set_category_ids( $target->get_category_ids() );
			if ( $target->get_image_id() ) { $product->set_image_id( $target->get_image_id() ); }
			if ( '' !== (string) $target->get_price() ) { $product->set_regular_price( (string) $target->get_price() ); } // solo para ver la vista previa con precio
		} elseif ( ! empty( $f['categorias'] ) && is_array( $f['categorias'] ) ) {
			$product->set_category_ids( array_map( 'absint', $f['categorias'] ) );
		}
		foreach ( array( 'length' => 'largo_cm', 'width' => 'ancho_cm', 'height' => 'alto_cm' ) as $wc => $k ) {
			if ( isset( $f['envio'][ $k ] ) && is_numeric( $f['envio'][ $k ] ) ) { $product->{'set_' . $wc}( (string) $f['envio'][ $k ] ); }
		}
		if ( isset( $f['envio']['peso_kg'] ) && is_numeric( $f['envio']['peso_kg'] ) ) { $product->set_weight( (string) $f['envio']['peso_kg'] ); }
		$product->update_meta_data( self::META_FICHA, wp_json_encode( $f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$product->update_meta_data( self::META_TARGET, $target_id );
		$product->update_meta_data( self::META_SEO, sanitize_text_field( (string) ( $f['seo_title'] ?? '' ) ) );
		$product->update_meta_data( self::META_DESC, sanitize_text_field( (string) ( $f['meta_description'] ?? '' ) ) );
		$id = $product->save();
		if ( ! $id ) { return new WP_Error( 'cvd_ficha_guardar', 'WooCommerce no guardó el borrador.' ); }

		// Fotos con su fuente en el pie de foto. Una foto que no baja no para la ficha: se anota.
		$galeria = array();
		$errores = array();
		foreach ( (array) ( $f['fotos'] ?? array() ) as $foto ) {
			if ( 'galeria' !== ( $foto['uso'] ?? '' ) ) { continue; }
			$att = self::subir_foto( $id, $foto );
			if ( is_wp_error( $att ) ) { $errores[] = $att->get_error_message(); continue; }
			$galeria[] = $att;
		}
		if ( $galeria ) {
			$product = wc_get_product( $id );
			if ( ! $product->get_image_id() ) { $product->set_image_id( array_shift( $galeria ) ); }
			$product->set_gallery_image_ids( $galeria );
			$product->save();
		}
		self::log( $id, 'creado', $target_id ? 'mejora de ' . $target_id : 'producto nuevo' );
		return array(
			'id'          => $id,
			'target_id'   => $target_id,
			'preview_url' => add_query_arg( array( 'post_type' => 'product', 'p' => $id, 'preview' => 'true' ), home_url( '/' ) ),
			'edit_url'    => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'review_url'  => admin_url( 'admin.php?page=' . self::PAGE ),
			'fotos_error' => $errores,
		);
	}

	private static function subir_foto( int $post_id, array $foto ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$src = (string) ( $foto['archivo'] ?? $foto['url'] ?? '' );
		if ( '' === $src ) { return new WP_Error( 'cvd_ficha_foto', 'Foto sin archivo ni url.' ); }
		if ( preg_match( '#^https?://#i', $src ) ) {
			$tmp = download_url( $src, 30 );
			if ( is_wp_error( $tmp ) ) { return $tmp; }
		} else {
			if ( ! is_readable( $src ) ) { return new WP_Error( 'cvd_ficha_foto', 'No se puede leer ' . basename( $src ) ); }
			$tmp = wp_tempnam( basename( $src ) );
			copy( $src, $tmp );
		}
		$name = sanitize_file_name( (string) ( $foto['nombre'] ?? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) );
		if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $name ) ) { $name .= '.jpg'; }
		$att = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, sanitize_text_field( (string) ( $foto['alt'] ?? '' ) ) );
		if ( is_wp_error( $att ) ) { @unlink( $tmp ); return $att; }
		$fuente = esc_url_raw( (string) ( $foto['fuente'] ?? '' ) ) ?: sanitize_text_field( (string) ( $foto['fuente'] ?? '' ) );
		wp_update_post( array( 'ID' => $att, 'post_excerpt' => 'Fuente: ' . ( $fuente ?: 'Casa Viva' ) ) );
		update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $foto['alt'] ?? '' ) ) );
		update_post_meta( $att, '_cvd_foto_fuente', $fuente );
		return (int) $att;
	}

	/** Mismas frases internas que prohíbe lib/commerce/product-editorial.ts (Product Studio). */
	public static function frase_prohibida( string $texto ): string {
		$t = remove_accents( mb_strtolower( wp_strip_all_tags( $texto ) ) );
		foreach ( array( 'pendiente de validacion', 'pendientes de validacion', 'pendiente de verificacion', 'pendiente de confirmacion', 'sujeto a confirmacion', 'confirmaremos disponibilidad', 'nexo verifica', 'ficha verificada', 'esta ficha reune', 'disponibilidad operativa', 'baratisimo', 'oferta loca', 'precio por privado', 'sugerido', 'falta confirmar' ) as $frase ) {
			if ( str_contains( $t, $frase ) ) { return $frase; }
		}
		return '';
	}

	/* ---------------------------------------------------------------- revisar */

	public static function pendientes(): array {
		return get_posts( array( 'post_type' => 'product', 'post_status' => array( 'draft', 'pending' ), 'posts_per_page' => 100, 'meta_key' => self::META_FICHA, 'meta_compare' => 'EXISTS', 'orderby' => 'date', 'order' => 'DESC' ) );
	}

	public static function ficha( int $id ): array {
		$f = json_decode( (string) get_post_meta( $id, self::META_FICHA, true ), true );
		return is_array( $f ) ? $f : array();
	}

	/** Cuenta confirmado / sugerido / falta en los campos de la ficha. */
	public static function marcas( array $f ): array {
		$n = array( 'confirmado' => 0, 'sugerido' => 0, 'falta' => 0 );
		foreach ( (array) ( $f['campos'] ?? array() ) as $c ) { $e = (string) ( $c['estado'] ?? '' ); if ( isset( $n[ $e ] ) ) { $n[ $e ]++; } }
		return $n;
	}

	/**
	 * Aprueba. Mejora → copia al producto en vivo (con copia del texto viejo) y el borrador va a la papelera.
	 * Nuevo → se publica (o queda privado si _cvd_ficha_estado_final = private, para pruebas). Devuelve el id que queda en la web.
	 */
	public static function aprobar( int $id, int $user_id ) {
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) || '' === (string) get_post_meta( $id, self::META_FICHA, true ) ) {
			return new WP_Error( 'cvd_ficha_no', 'Esa ficha ya no está por revisar.' );
		}
		$draft     = wc_get_product( $id );
		$target_id = (int) get_post_meta( $id, self::META_TARGET, true );
		$user      = get_userdata( $user_id );
		$quien     = array( 'user_id' => $user_id, 'nombre' => $user ? $user->display_name : '', 'fecha' => current_time( 'mysql' ), 'borrador' => $id );

		if ( $target_id ) {
			$target = wc_get_product( $target_id );
			if ( ! $target ) { return new WP_Error( 'cvd_ficha_target', 'El producto ' . $target_id . ' ya no existe.' ); }
			$antes = array(
				'description'       => $target->get_description(),
				'short_description' => $target->get_short_description(),
				'seo_title'         => (string) $target->get_meta( self::META_SEO ),
				'meta_description'  => (string) $target->get_meta( self::META_DESC ),
				'gallery'           => $target->get_gallery_image_ids(),
				'dims'              => array( $target->get_length(), $target->get_width(), $target->get_height(), $target->get_weight() ),
				'ficha'             => (string) $target->get_meta( self::META_FICHA ),
			);
			$target->update_meta_data( '_cvd_ficha_backup_' . gmdate( 'YmdHis' ), wp_json_encode( $antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$target->set_description( $draft->get_description() );
			$target->set_short_description( $draft->get_short_description() );
			$target->update_meta_data( self::META_SEO, (string) $draft->get_meta( self::META_SEO ) );
			$target->update_meta_data( self::META_DESC, (string) $draft->get_meta( self::META_DESC ) );
			$nuevas = array_values( array_diff( $draft->get_gallery_image_ids(), array( (int) $target->get_image_id() ) ) );
			if ( $nuevas ) {
				$target->set_gallery_image_ids( array_values( array_unique( array_merge( $target->get_gallery_image_ids(), $nuevas ) ) ) );
				foreach ( $nuevas as $att ) { wp_update_post( array( 'ID' => $att, 'post_parent' => $target_id ) ); }
			}
			foreach ( array( 'length', 'width', 'height', 'weight' ) as $d ) {
				$v = $draft->{'get_' . $d}();
				if ( '' !== (string) $v ) { $target->{'set_' . $d}( $v ); }
			}
			$target->update_meta_data( self::META_FICHA, (string) get_post_meta( $id, self::META_FICHA, true ) );
			$target->update_meta_data( self::META_APROBADA, wp_json_encode( $quien, JSON_UNESCAPED_UNICODE ) );
			$target->save();
			update_post_meta( $id, '_cvd_ficha_estado', 'aprobada' );
			update_post_meta( $id, self::META_APROBADA, wp_json_encode( $quien, JSON_UNESCAPED_UNICODE ) );
			self::log( $id, 'aprobada', 'copiada a ' . $target_id . ' por ' . $quien['nombre'] );
			wp_trash_post( $id );
			return $target_id;
		}

		if ( '' === (string) $draft->get_price() || (float) $draft->get_price() <= 0 ) {
			return new WP_Error( 'cvd_ficha_precio', 'Este producto nuevo no tiene precio. Ponle el precio en «Corregir» y vuelve a aprobar.' );
		}
		$final = 'private' === get_post_meta( $id, self::META_FINAL, true ) ? 'private' : 'publish';
		$draft->set_name( self::sin_prefijo( $draft->get_name() ) );
		$draft->set_catalog_visibility( 'visible' );
		$draft->set_status( $final );
		$draft->update_meta_data( self::META_APROBADA, wp_json_encode( $quien, JSON_UNESCAPED_UNICODE ) );
		$draft->update_meta_data( '_cvd_ficha_estado', 'aprobada' );
		$draft->save();
		self::log( $id, 'aprobada', 'publicado (' . $final . ') por ' . $quien['nombre'] );
		return $id;
	}

	public static function descartar( int $id, int $user_id ) {
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) || '' === (string) get_post_meta( $id, self::META_FICHA, true ) ) {
			return new WP_Error( 'cvd_ficha_no', 'Esa ficha ya no está por revisar.' );
		}
		$user = get_userdata( $user_id );
		update_post_meta( $id, '_cvd_ficha_estado', 'descartada' );
		self::log( $id, 'descartada', $user ? $user->display_name : '' );
		wp_trash_post( $id );
		return $id;
	}

	private static function sin_prefijo( string $name ): string {
		return str_starts_with( $name, self::PREFIJO ) ? substr( $name, strlen( self::PREFIJO ) ) : $name;
	}

	private static function log( int $id, string $que, string $detalle ): void {
		$log   = json_decode( (string) get_post_meta( $id, '_cvd_ficha_log', true ), true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'fecha' => current_time( 'mysql' ), 'que' => $que, 'detalle' => $detalle );
		update_post_meta( $id, '_cvd_ficha_log', wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) );
	}

	/* ------------------------------------------------------------------ admin */

	public static function menu(): void {
		$count = count( self::pendientes() );
		add_submenu_page( 'cvd-gestoras', 'Fichas por revisar', 'Fichas por revisar' . ( $count ? ' <span class="awaiting-mod">' . $count . '</span>' : '' ), 'edit_products', self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function accion_url( string $accion, int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=cvd_ficha_' . $accion . '&id=' . $id ), 'cvd_ficha_' . $accion . '_' . $id );
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_products' ) ) { return; }
		$msg = isset( $_GET['cvd_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['cvd_msg'] ) ) : '';
		$err = isset( $_GET['cvd_err'] ) ? sanitize_text_field( wp_unslash( $_GET['cvd_err'] ) ) : '';
		echo '<div class="wrap"><h1>Fichas por revisar</h1>';
		echo '<p>Fichas de producto que preparó Claude con las fotos de la tienda y una búsqueda en internet. <strong>Nada sale en la web hasta que alguien pulsa «Aprobar».</strong></p>';
		echo '<p>Cada dato lleva una marca: <span style="color:#1a7f37"><strong>Confirmado</strong></span> = se leyó en la etiqueta, la caja, el manual o la factura, o lo midió la tienda · <span style="color:#9a6700"><strong>Sugerido</strong></span> = lo dice internet (fabricante o tienda), con su enlace; revísalo · <span style="color:#cf222e"><strong>Falta</strong></span> = nadie lo sabe; no sale en el texto.</p>';
		if ( $msg ) { echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>'; }
		if ( $err ) { echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>'; }
		$items = self::pendientes();
		if ( ! $items ) { echo '<p><em>No hay fichas por revisar.</em></p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th>Ficha</th><th>Qué hace al aprobar</th><th>Datos</th><th>Acciones</th></tr></thead><tbody>';
			foreach ( $items as $post ) {
				$f      = self::ficha( $post->ID );
				$m      = self::marcas( $f );
				$target = (int) get_post_meta( $post->ID, self::META_TARGET, true );
				$tp     = $target ? get_post( $target ) : null;
				$que    = $target ? 'Cambia el texto de <a href="' . esc_url( get_permalink( $target ) ) . '" target="_blank">' . esc_html( $tp ? $tp->post_title : '#' . $target ) . '</a> (lo de ahora se guarda)' : 'Publica un producto nuevo';
				$preview = add_query_arg( array( 'post_type' => 'product', 'p' => $post->ID, 'preview' => 'true' ), home_url( '/' ) );
				echo '<tr><td><strong>' . esc_html( self::sin_prefijo( $post->post_title ) ) . '</strong><br><small>' . esc_html( get_the_date( 'j M H:i', $post ) ) . ' · #' . (int) $post->ID . '</small></td>';
				echo '<td>' . wp_kses_post( $que ) . '</td>';
				echo '<td><span style="color:#1a7f37">' . (int) $m['confirmado'] . ' confirmados</span><br><span style="color:#9a6700">' . (int) $m['sugerido'] . ' sugeridos</span><br><span style="color:#cf222e">' . (int) $m['falta'] . ' faltan</span></td>';
				echo '<td><a class="button" href="' . esc_url( $preview ) . '" target="_blank">Ver</a> <a class="button" href="' . esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ) . '">Corregir</a> ';
				echo '<a class="button button-primary" href="' . esc_url( self::accion_url( 'aprobar', $post->ID ) ) . '" onclick="return confirm(\'¿Aprobar y poner este texto en la web?\')">Aprobar</a> ';
				echo '<a class="button" href="' . esc_url( self::accion_url( 'descartar', $post->ID ) ) . '" onclick="return confirm(\'¿Descartar esta ficha? Va a la papelera.\')">Descartar</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		$on = self::requiere_aprobacion();
		echo '<h2 style="margin-top:2em">Interruptor</h2><p>Aprobación obligatoria: <strong>' . ( $on ? 'SÍ (encendido)' : 'NO (las fichas se publican solas)' ) . '</strong></p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cvd_ficha_interruptor&valor=' . ( $on ? '0' : '1' ) ), 'cvd_ficha_interruptor' ) ) . '" onclick="return confirm(\'¿Seguro?\')">' . ( $on ? 'Apagar (publicar sin revisión)' : 'Encender (revisar antes de publicar)' ) . '</a></p>';
		}
		echo '</div>';
	}

	private static function volver( string $msg, string $err = '' ): void {
		wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::PAGE, 'cvd_msg' => $msg, 'cvd_err' => $err ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function admin_aprobar(): void {
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( 'edit_products' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'cvd_ficha_aprobar_' . $id ) ) { wp_die( 'Sin permiso.' ); }
		$r = self::aprobar( $id, get_current_user_id() );
		if ( is_wp_error( $r ) ) { self::volver( '', $r->get_error_message() ); }
		self::volver( 'Aprobada. Ya está en la web: ' . get_permalink( (int) $r ) );
	}

	public static function admin_descartar(): void {
		$id = absint( $_GET['id'] ?? 0 );
		if ( ! current_user_can( 'edit_products' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'cvd_ficha_descartar_' . $id ) ) { wp_die( 'Sin permiso.' ); }
		$r = self::descartar( $id, get_current_user_id() );
		if ( is_wp_error( $r ) ) { self::volver( '', $r->get_error_message() ); }
		self::volver( 'Descartada (está en la papelera de Productos, por si hace falta recuperarla).' );
	}

	public static function admin_interruptor(): void {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'cvd_ficha_interruptor' ) ) { wp_die( 'Sin permiso.' ); }
		update_option( self::OPTION_SWITCH, '0' === ( $_GET['valor'] ?? '1' ) ? '0' : '1', false );
		self::volver( self::requiere_aprobacion() ? 'Aprobación obligatoria ENCENDIDA.' : 'Aprobación obligatoria APAGADA.' );
	}

	/** Caja «Ficha» en el editor del producto: cada dato con su marca y su fuente. */
	public static function meta_box( WP_Post $post ): void {
		if ( '' === (string) get_post_meta( $post->ID, self::META_FICHA, true ) ) { return; }
		add_meta_box( 'cvd_ficha', 'Ficha: Confirmado / Sugerido / Falta', array( __CLASS__, 'render_box' ), 'product', 'normal', 'high' );
	}

	public static function render_box( WP_Post $post ): void {
		$f      = self::ficha( $post->ID );
		$target = (int) get_post_meta( $post->ID, self::META_TARGET, true );
		$color  = array( 'confirmado' => '#1a7f37', 'sugerido' => '#9a6700', 'falta' => '#cf222e' );
		if ( in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			echo '<p>' . ( $target ? 'Al aprobar, este texto pasa a <a href="' . esc_url( get_permalink( $target ) ) . '" target="_blank">' . esc_html( get_the_title( $target ) ) . '</a> (el texto de ahora se guarda). ' : 'Al aprobar, este producto nuevo se publica. ' );
			echo 'Primero guarda tus cambios con «Guardar borrador». <a class="button button-primary" href="' . esc_url( self::accion_url( 'aprobar', $post->ID ) ) . '" onclick="return confirm(\'¿Aprobar y poner este texto en la web?\')">Aprobar</a> <a class="button" href="' . esc_url( self::accion_url( 'descartar', $post->ID ) ) . '">Descartar</a></p>';
		}
		echo '<p><strong>Título para Google:</strong> ' . esc_html( (string) get_post_meta( $post->ID, self::META_SEO, true ) ) . '<br><strong>Descripción para Google:</strong> ' . esc_html( (string) get_post_meta( $post->ID, self::META_DESC, true ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Dato</th><th>Valor</th><th>Marca</th><th>Fuente</th></tr></thead><tbody>';
		foreach ( (array) ( $f['campos'] ?? array() ) as $c ) {
			$e   = (string) ( $c['estado'] ?? '' );
			$src = (string) ( $c['fuente'] ?? '' );
			$src = preg_match( '#^https?://#', $src ) ? '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $src, PHP_URL_HOST ) ) . '</a>' : esc_html( $src );
			echo '<tr><td>' . esc_html( (string) ( $c['campo'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $c['valor'] ?? '—' ) ) . '</td><td style="color:' . esc_attr( $color[ $e ] ?? '#555' ) . '"><strong>' . esc_html( ucfirst( $e ) ) . '</strong></td><td>' . $src . ( ! empty( $c['nota'] ) ? '<br><small>' . esc_html( (string) $c['nota'] ) . '</small>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		foreach ( array( 'contradicciones' => 'Contradicciones (manda la etiqueta)', 'pedir_fotos' => 'Fotos o medidas que faltan' ) as $k => $label ) {
			if ( ! empty( $f[ $k ] ) ) { echo '<p><strong>' . esc_html( $label ) . ':</strong></p><ul style="list-style:disc;margin-left:2em">'; foreach ( (array) $f[ $k ] as $x ) { echo '<li>' . esc_html( (string) $x ) . '</li>'; } echo '</ul>'; }
		}
		$precio = (array) ( $f['precio'] ?? array() );
		echo '<p><strong>Precio:</strong> ' . esc_html( (string) ( $precio['precio_sugerido'] ?? 'pendiente del motor de precios' ) ) . ( isset( $precio['costo_factura'] ) && null !== $precio['costo_factura'] ? ' · costo en factura: ' . esc_html( $precio['costo_factura'] . ' ' . ( $precio['moneda'] ?? '' ) ) : '' ) . '</p>';
	}

	/* -------------------------------------------------------------------- SEO */

	public static function seo_title( $title ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$t = (string) get_post_meta( get_queried_object_id(), self::META_SEO, true );
			if ( '' !== $t ) { return $t; }
		}
		return $title;
	}
}
