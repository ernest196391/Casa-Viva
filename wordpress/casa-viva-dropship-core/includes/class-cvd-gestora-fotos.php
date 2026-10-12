<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Fotos para publicar» en el área de gestoras (3.13.57, pedido de Ernesto el 11-oct tras las listas de Yanaika):
 * todo lo que hay en existencia, por categorías y con buscador; cada producto con sus fotos, su precio (el de ella
 * si lo subió), lo que queda y el texto listo para copiar con SU enlace (?ref=). Botones «Descargar foto»,
 * «Copiar texto» y, por categoría, «Descargar todas (ZIP)».
 *
 * Rápido en el móvil: el catálogo común se guarda 20 min (transient) y la página manda un JSON pequeño; las
 * tarjetas las pinta gestora-app.js al abrir cada categoría y las fotos cargan perezosas.
 * Las fotos de la tienda son WebP: al descargar se convierten a JPG (WhatsApp y la galería los aceptan
 * siempre) y se guardan en uploads/cvd-fotos para la próxima vez. El ZIP de cada categoría también se guarda
 * y solo se rehace si cambia un producto o una foto.
 */
final class CVD_Gestora_Fotos {
	private const CACHE = 'cvd_fotos_catalog_v1';
	private const DIR = 'cvd-fotos';
	private const MAX_IMAGES = 6;

	public static function register(): void {
		add_action( 'admin_post_cvd_fotos_jpg', array( __CLASS__, 'download_jpg' ) );
		add_action( 'admin_post_cvd_fotos_zip', array( __CLASS__, 'download_zip' ) );
		$flush = array( __CLASS__, 'flush' );
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'edited_product_cat' ) as $hook ) {
			add_action( $hook, $flush );
		}
		add_action( 'trashed_post', $flush );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	private static function allowed( WP_User $user ): bool {
		return $user->exists() && ( current_user_can( 'manage_woocommerce' ) || ( class_exists( 'CVD_Registration' ) && 'gestora' === CVD_Registration::program_type( $user ) && CVD_Registration::is_approved_gestora( $user ) ) );
	}

	/** Catálogo común (igual para todas): productos publicados en existencia con sus fotos y categorías. */
	public static function catalog(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) { return $cached; }
		$products = wc_get_products( array( 'status' => 'publish', 'stock_status' => 'instock', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$items = array();
		$cats = array();
		foreach ( $products as $product ) {
			if ( 'hidden' === $product->get_catalog_visibility() ) { continue; }
			$terms = array();
			foreach ( $product->get_category_ids() as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( ! $term || is_wp_error( $term ) || in_array( $term->slug, array( 'pruebas', 'sin-categorizar' ), true ) ) { continue; }
				$terms[] = (int) $term_id;
				$cats[ $term_id ] = array( 'id' => (int) $term_id, 'name' => $term->name, 'slug' => $term->slug );
			}
			if ( ! $terms ) { continue; }
			$images = array_values( array_unique( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) ) ) );
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child && $child->is_in_stock() && $child->get_image_id() ) { $images[] = (int) $child->get_image_id(); }
				}
				$images = array_values( array_unique( $images ) );
			}
			$images = array_slice( $images, 0, self::MAX_IMAGES );
			if ( ! $images ) { continue; } // sin foto no sirve para publicar
			$qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
			$items[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'url'   => (string) get_permalink( $product->get_id() ),
				'cats'  => $terms,
				'qty'   => null === $qty ? null : (int) $qty,
				'line'  => self::selling_line( $product ),
				'imgs'  => array_map( static fn( int $id ): array => array( 'id' => $id, 'src' => (string) wp_get_attachment_image_url( $id, 'medium' ) ), $images ),
				'sig'   => $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0,
			);
		}
		uasort( $cats, static fn( array $a, array $b ): int => strcmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) ) );
		$data = array( 'items' => $items, 'cats' => array_values( $cats ) );
		set_transient( self::CACHE, $data, 20 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Una frase para vender, sacada de la descripción: el primer párrafo de verdad (no el título repetido,
	 * no "Características y detalles", sin emojis), cortado en la primera frase.
	 */
	public static function selling_line( WC_Product $product ): string {
		$html = (string) $product->get_description();
		if ( '' === trim( $html ) && $product->get_parent_id() ) { $html = (string) get_post_field( 'post_content', $product->get_parent_id() ); }
		$blocks = preg_split( '#<\s*(?:br\s*/?|/p|/h[1-6]|/li|/div|/ul)\s*>#i', $html ) ?: array();
		$name = remove_accents( mb_strtolower( $product->get_name() ) );
		$lines = array();
		foreach ( $blocks as $block ) {
			$text = html_entity_decode( wp_strip_all_tags( $block ), ENT_QUOTES, 'UTF-8' );
			$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{2022}]/u', ' ', $text );
			$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
			$text = preg_replace( '/^caracter[ií]sticas(?: y detalles)?\s*:?\s*/iu', '', $text );
			$text = preg_replace( '/^.{3,80}?\s[–—-]\s\$?\d+(?:[.,]\d+)?\s*USD\s+/u', '', (string) $text ); // "Cepillo … – 35 USD Alisa y peina…"
			$text = preg_replace( '/^\d+[.)]\s+/u', '', (string) $text ); // "1. Ayuda a limpiar…"
			if ( mb_strlen( (string) $text ) < 30 ) { continue; }
			if ( 0 === strpos( remove_accents( mb_strtolower( $text ) ), $name ) && mb_strlen( $text ) < mb_strlen( $name ) + 25 ) { continue; }
			if ( preg_match( '/^(consulta|medida|medidas|contenido|colores?|mensajer[ií]a)\b/iu', $text ) || preg_match( '/\bUSD\b|\$\s?\d|\*|\bmerma\b/iu', $text ) ) { continue; } // relleno, título con precio o nota interna
			$lines[] = $text;
		}
		// Primero una frase de verdad (acaba en punto); si no hay, la primera línea que no sea un título.
		foreach ( $lines as $text ) {
			if ( preg_match( '/^(.{30,150}?[.!?])(\s|$)/u', $text, $m ) ) { return $m[1]; }
		}
		foreach ( $lines as $text ) {
			$is_title = mb_strlen( $text ) <= 70 && ( preg_match( '/\s[–—]\s/u', $text ) || preg_match_all( '/(?:^|\s)\p{Lu}/u', $text ) >= max( 3, (int) ceil( count( explode( ' ', $text ) ) * 0.6 ) ) );
			if ( $is_title ) { continue; }
			return mb_strlen( $text ) > 150 ? rtrim( mb_substr( $text, 0, mb_strrpos( mb_substr( $text, 0, 150 ), ' ' ) ?: 150 ), ' ,;:' ) . '…' : $text;
		}
		return '';
	}

	/** Lo de cada gestora: su precio, CUP del día, su enlace y el texto para copiar. */
	public static function for_gestora( WP_User $user, string $code ): array {
		$data = self::catalog();
		$rate = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::get()['cup'] : null;
		$out = array();
		_prime_post_caches( array_column( $data['items'], 'id' ), true, true ); // 1 consulta en vez de ~4 por producto
		foreach ( $data['items'] as $item ) {
			$product = wc_get_product( $item['id'] );
			if ( ! $product ) { continue; }
			$price = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $user->ID ) : (float) $product->get_price();
			$link = add_query_arg( 'ref', $code, $item['url'] );
			$usd = self::usd( $price );
			$cup = $rate && $price > 0 ? number_format( (int) round( $price * $rate ), 0, ',', '.' ) . ' CUP' : '';
			$stock = null === $item['qty'] ? 'En existencia' : ( $item['qty'] <= 3 ? 'Últimas ' . $item['qty'] : 'Quedan ' . $item['qty'] );
			// Manual de marca: 1.ª línea con sensación, 2.ª con datos, 3.ª con cómo comprar; un emoji como mucho.
			$text = '*' . $item['name'] . '*' . ( $item['line'] ? "\n" . $item['line'] : '' ) . "\n" . $usd . ( $cup ? ' · ' . $cup : '' ) . "\nPídelo aquí y te lo llevamos 🛵 " . $link;
			$out[] = array(
				'id' => $item['id'], 'n' => $item['name'], 'c' => $item['cats'], 'p' => $usd, 'cup' => $cup, 's' => $stock,
				't' => $text, 'i' => array_map( static fn( array $img ): array => array( $img['src'], self::jpg_url( $img['id'], $item['name'] ) ), $item['imgs'] ),
			);
		}
		$cats = array();
		foreach ( $data['cats'] as $cat ) {
			$count = count( array_filter( $data['items'], static fn( array $i ): bool => in_array( $cat['id'], $i['cats'], true ) ) );
			if ( ! $count ) { continue; }
			$cats[] = array( 'id' => $cat['id'], 'n' => $cat['name'], 'k' => $count, 'z' => self::zip_url( $cat['id'] ), 'zs' => self::zip_size_label( $cat['id'] ) );
		}
		return array( 'items' => $out, 'cats' => $cats );
	}

	private static function usd( float $price ): string {
		return ( floor( $price ) === $price ? number_format( $price, 0, ',', '.' ) : number_format( $price, 2, ',', '.' ) ) . ' USD';
	}

	private static function jpg_url( int $attachment_id, string $name ): string {
		return add_query_arg( array( 'action' => 'cvd_fotos_jpg', 'id' => $attachment_id, 'n' => sanitize_title( $name ) ), admin_url( 'admin-post.php' ) );
	}

	private static function zip_url( int $cat_id ): string {
		return add_query_arg( array( 'action' => 'cvd_fotos_zip', 'cat' => $cat_id, '_wpnonce' => wp_create_nonce( 'cvd_fotos_zip' ) ), admin_url( 'admin-post.php' ) );
	}

	/** Página: cabecera, buscador y el JSON con todo; las tarjetas las pinta gestora-app.js. */
	public static function render( WP_User $user, string $code ): string {
		$data = self::for_gestora( $user, $code );
		$total = count( $data['items'] );
		ob_start();
		?>
		<section class="cvga-view cvgf" data-view="fotos" id="cvga-fotos" data-cvgf>
			<div class="cvga-head"><h1>Fotos para publicar</h1><p>Todo lo que hay en existencia, con tu precio y tu enlace. Descarga la foto, copia el texto y publícalo.</p></div>
			<div class="cvga-card cvgf-bar">
				<label class="cvgf-search" for="cvgf-q"><svg class="cvga-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg><input id="cvgf-q" type="search" placeholder="Buscar: mochila, cortina, faja…" autocomplete="off" enterkeyhint="search" data-cvgf-q aria-label="Buscar producto"></label>
				<small class="cvga-note" data-cvgf-count><?php echo esc_html( $total . ' productos en existencia' ); ?></small>
			</div>
			<div class="cvgf-results" data-cvgf-results hidden></div>
			<div class="cvgf-cats" data-cvgf-cats>
				<?php foreach ( $data['cats'] as $cat ) : ?>
				<details class="cvga-card cvgf-cat" data-cat="<?php echo esc_attr( (string) $cat['id'] ); ?>">
					<summary><b><?php echo esc_html( $cat['n'] ); ?></b><span class="cvga-chip"><?php echo esc_html( (string) $cat['k'] ); ?></span></summary>
					<div class="cvga-actions cvgf-cat-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="<?php echo esc_url( $cat['z'] ); ?>" data-cvgf-zip>Descargar todas (ZIP)</a><small class="cvga-note"><?php echo esc_html( 'Una foto por producto' . ( $cat['zs'] ? ' · ' . $cat['zs'] : '' ) . '. Con wifi va mejor.' ); ?></small></div>
					<div class="cvgf-grid" data-cvgf-grid></div>
				</details>
				<?php endforeach; ?>
			</div>
			<?php if ( ! $data['items'] ) : ?><p class="cvga-empty">Ahora mismo no hay productos con foto en existencia.</p><?php endif; ?>
			<script type="application/json" data-cvgf-data><?php echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Carpeta uploads/cvd-fotos (y la crea con un index.php para que no se liste). */
	private static function dir(): array {
		$up = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . self::DIR;
		if ( ! is_dir( $path . '/jpg' ) ) {
			wp_mkdir_p( $path . '/jpg' );
			foreach ( array( $path, $path . '/jpg' ) as $d ) { if ( ! file_exists( $d . '/index.php' ) ) { file_put_contents( $d . '/index.php', "<?php\n// Silencio.\n" ); } }
		}
		return array( $path, trailingslashit( $up['baseurl'] ) . self::DIR );
	}

	/** JPG de una foto de producto (máx. 1280 px), guardado para la próxima vez. Devuelve la ruta o ''. */
	public static function jpg_path( int $attachment_id ): string {
		$source = (string) get_attached_file( $attachment_id );
		if ( ! $source || ! file_exists( $source ) ) { return ''; }
		list( $dir ) = self::dir();
		$target = sprintf( '%s/jpg/%d-%d.jpg', $dir, $attachment_id, (int) filemtime( $source ) );
		if ( file_exists( $target ) ) { return $target; }
		$mime = (string) wp_check_filetype( $source )['type'];
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) { return 'image/jpeg' === $mime ? $source : ''; }
		$editor->resize( 1280, 1280, false );
		$editor->set_quality( 82 );
		$saved = $editor->save( $target, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) { return 'image/jpeg' === $mime ? $source : ''; }
		foreach ( glob( sprintf( '%s/jpg/%d-*.jpg', $dir, $attachment_id ) ) ?: array() as $old ) { if ( $old !== $target ) { wp_delete_file( $old ); } }
		return $target;
	}

	/** «Descargar foto»: solo fotos de productos publicados, solo para gestoras aprobadas. */
	public static function download_jpg(): void {
		if ( ! self::allowed( wp_get_current_user() ) ) { wp_die( 'Solo para gestoras aprobadas de Casa Viva.', 'Casa Viva', array( 'response' => 403 ) ); }
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura de una foto pública.
		$parent = (int) wp_get_post_parent_id( $id );
		$in_catalog = false;
		foreach ( self::catalog()['items'] as $item ) { if ( in_array( $id, array_column( $item['imgs'], 'id' ), true ) ) { $in_catalog = true; break; } }
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ( ! $in_catalog && ( ! $parent || 'product' !== get_post_type( $parent ) ) ) ) { wp_die( 'Esa foto no está disponible.', 'Casa Viva', array( 'response' => 404 ) ); }
		$path = self::jpg_path( $id );
		if ( ! $path ) { wp_die( 'No pude preparar esa foto. Prueba otra vez en un momento.', 'Casa Viva', array( 'response' => 500 ) ); }
		$name = sanitize_file_name( ( sanitize_title( wp_unslash( (string) ( $_GET['n'] ?? '' ) ) ) ?: 'casa-viva' ) . '-' . $id . '.jpg' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Firma de una categoría: cambia si cambia un producto, su foto o la lista. */
	private static function zip_plan( int $cat_id ): array {
		$items = array_values( array_filter( self::catalog()['items'], static fn( array $i ): bool => in_array( $cat_id, $i['cats'], true ) ) );
		$term = get_term( $cat_id, 'product_cat' );
		$slug = $term && ! is_wp_error( $term ) ? $term->slug : 'categoria';
		$sig = array_map( static fn( array $i ): array => array( $i['id'], $i['imgs'][0]['id'] ?? 0, $i['sig'] ), $items );
		list( $dir, $url ) = self::dir();
		$file = sprintf( 'casa-viva-%s-%s.zip', sanitize_file_name( $slug ), substr( md5( (string) wp_json_encode( $sig ) ), 0, 10 ) );
		return array( 'items' => $items, 'slug' => $slug, 'path' => $dir . '/' . $file, 'url' => $url . '/' . $file );
	}

	private static function zip_size_label( int $cat_id ): string {
		$plan = self::zip_plan( $cat_id );
		return file_exists( $plan['path'] ) ? size_format( (int) filesize( $plan['path'] ), 1 ) : '';
	}

	/** «Descargar todas (ZIP)»: se arma una vez por categoría y se reutiliza hasta que cambie algo. */
	public static function download_zip(): void {
		if ( ! self::allowed( wp_get_current_user() ) ) { wp_die( 'Solo para gestoras aprobadas de Casa Viva.', 'Casa Viva', array( 'response' => 403 ) ); }
		check_admin_referer( 'cvd_fotos_zip' );
		$cat_id = absint( $_GET['cat'] ?? 0 );
		$plan = self::zip_plan( $cat_id );
		if ( ! $plan['items'] ) { wp_die( 'Esa categoría no tiene productos con foto ahora mismo.', 'Casa Viva', array( 'response' => 404 ) ); }
		if ( ! file_exists( $plan['path'] ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) { wp_die( 'El ZIP no está disponible: descarga las fotos una por una.', 'Casa Viva', array( 'response' => 500 ) ); }
			$lock = 'cvd_fotos_zip_lock_' . $cat_id;
			if ( get_transient( $lock ) ) { wp_die( 'Estoy preparando este ZIP. Vuelve a tocar el botón en un minuto.', 'Casa Viva', array( 'response' => 503 ) ); }
			set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$tmp = $plan['path'] . '.tmp';
			$zip = new ZipArchive();
			if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { delete_transient( $lock ); wp_die( 'No pude preparar el ZIP. Prueba en un momento.', 'Casa Viva', array( 'response' => 500 ) ); }
			$n = 0;
			foreach ( $plan['items'] as $item ) {
				$path = self::jpg_path( (int) ( $item['imgs'][0]['id'] ?? 0 ) );
				if ( ! $path ) { continue; }
				$entry = sprintf( '%02d-%s.jpg', ++$n, sanitize_title( $item['name'] ) );
				$zip->addFile( $path, $entry );
				$zip->setCompressionName( $entry, ZipArchive::CM_STORE ); // los JPG ya van comprimidos
			}
			$zip->close();
			if ( $n && file_exists( $tmp ) ) {
				foreach ( glob( dirname( $plan['path'] ) . '/casa-viva-' . sanitize_file_name( $plan['slug'] ) . '-*.zip' ) ?: array() as $old ) { wp_delete_file( $old ); }
				rename( $tmp, $plan['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			} elseif ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			delete_transient( $lock );
			if ( ! file_exists( $plan['path'] ) ) { wp_die( 'No pude preparar el ZIP. Descarga las fotos una por una.', 'Casa Viva', array( 'response' => 500 ) ); }
		}
		wp_safe_redirect( $plan['url'] );
		exit;
	}
}
