<?php

defined( 'ABSPATH' ) || exit;

/**
 * Búsqueda de productos tolerante para clientes (Curru y buscador de la tienda).
 *
 * Ignora acentos, mayúsculas y plurales simples, admite sinónimos y ordena por relevancia.
 * Solo lee productos publicados y visibles; los precios salen siempre de WooCommerce.
 */
final class CVD_Product_Search {
	private const CACHE_KEY = 'cvd_product_search_index_v1';
	private const MAX_RESULTS = 12;
	private const MAX_SHOP_RESULTS = 200;

	private const STOPWORDS = array( 'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos', 'unas', 'y', 'o', 'para', 'con', 'en', 'por', 'que', 'me', 'mi', 'quiero', 'busco', 'necesito', 'tienen', 'tienes', 'hay', 'algo', 'alguna', 'algun', 'precio', 'cuanto', 'cuesta', 'vale', 'venden', 'vendes', 'favor', 'hola', 'buenas', 'quedan', 'queda', 'existencia', 'existencias', 'stock', 'disponible', 'disponibles', 'disponibilidad', 'unidades', 'cuantos', 'cuantas', 'cada', 'modelo', 'modelos' );

	/** Sinónimos frecuentes en Cuba. Ampliable con el filtro `cvd_product_search_aliases`. */
	private const ALIASES = array(
		'sarten'      => array( 'paila', 'wok' ),
		'paila'       => array( 'sarten' ),
		'alfombra'    => array( 'tapete', 'felpudo', 'alfombrilla' ),
		'tapete'      => array( 'alfombra' ),
		'albornoz'    => array( 'bata' ),
		'bata'        => array( 'albornoz' ),
		'toalla'      => array( 'paño' ),
		'sabana'      => array( 'sobrecama', 'colcha', 'edredon' ),
		'colcha'      => array( 'sobrecama', 'edredon', 'manta' ),
		'manta'       => array( 'frazada', 'colcha', 'cobija' ),
		'frazada'     => array( 'manta' ),
		'licuadora'   => array( 'batidora' ),
		'batidora'    => array( 'licuadora' ),
		'ventilador'  => array( 'abanico' ),
		'abanico'     => array( 'ventilador' ),
		'grifo'       => array( 'llave', 'pila', 'mezcladora' ),
		'pila'        => array( 'grifo', 'llave' ),
		'cubo'        => array( 'cesto', 'balde', 'tanque' ),
		'cesto'       => array( 'cubo', 'cesta' ),
		'zapatera'    => array( 'zapatero' ),
		'organizador' => array( 'organizadora', 'estante' ),
		'crema'       => array( 'locion' ),
		'champu'      => array( 'shampoo' ),
		'shampoo'     => array( 'champu' ),
		'tendedero'   => array( 'colgador' ),
		'vaso'        => array( 'copa' ),
		'jarra'       => array( 'pomo' ),
		'bocina'      => array( 'parlante', 'altavoz' ),
		'celular'     => array( 'movil', 'telefono' ),
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'tolerant_shop_search' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 36 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'render_form' ), 5 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'render_form' ), 5 );
		foreach ( array( 'save_post_product', 'woocommerce_update_product', 'woocommerce_product_set_stock_status', 'deleted_post', 'edited_product_cat' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	private static function is_store_surface(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		return ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
			|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
	}

	/** Sugerencias en el buscador de la tienda y en los buscadores del tema. */
	public static function assets(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( 'cvd-product-search', CVD_URL . 'assets/product-search.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-product-search', CVD_URL . 'assets/product-search.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-product-search', 'cvdProductSearch', array( 'url' => esc_url_raw( rest_url( 'casa-viva/v1/products/search' ) ), 'home' => esc_url_raw( home_url( '/' ) ) ) );
	}

	public static function render_form(): void {
		static $done = false;
		if ( $done || ! self::is_store_surface() ) {
			return;
		}
		$done = true;
		$query = get_search_query();
		?>
		<form class="cvd-store-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" data-cvd-product-search>
			<label class="screen-reader-text" for="cvd-store-search-input">Buscar productos</label>
			<input id="cvd-store-search-input" type="search" name="s" value="<?php echo esc_attr( $query ); ?>" placeholder="Busca: sartén, toalla, alfombra…" autocomplete="off" enterkeyhint="search">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="Buscar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Zm5.4-2.1L21 21"/></svg></button>
		</form>
		<?php
		if ( $query && ! have_posts() ) {
			echo '<p class="cvd-store-search-empty">¿No lo encuentras? <button type="button" data-cvd-curru-open>Pregúntale a ' . esc_html( class_exists( 'CVD_Contextual_Assistant' ) ? CVD_Contextual_Assistant::name() : 'Curru' ) . '</button></p>';
		}
	}

	/** La búsqueda de la tienda usa el mismo motor tolerante que Curru (acentos, plurales, sinónimos). */
	public static function tolerant_shop_search( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		$ids = self::search( (string) $query->get( 's' ), self::MAX_SHOP_RESULTS );
		if ( ! $ids ) {
			return;
		}
		$query->set( 'post__in', $ids );
		// Si el cliente eligió un orden (precio, novedades), se respeta; si no, va por relevancia.
		if ( empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 'orderby', 'post__in' );
			$query->set( 'order', 'ASC' );
		}
		add_filter(
			'posts_search',
			static function ( $search, $current ) use ( $query ) {
				return $current === $query ? '' : $search;
			},
			20,
			2
		);
	}

	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	public static function routes(): void {
		register_rest_route(
			'casa-viva/v1',
			'/products/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'args'                => array( 'q' => array( 'type' => 'string', 'required' => true ), 'limit' => array( 'type' => 'integer' ) ),
			)
		);
	}

	public static function rest_search( WP_REST_Request $request ) {
		$query = mb_substr( sanitize_text_field( (string) $request->get_param( 'q' ) ), 0, 120 );
		$limit = max( 1, min( self::MAX_RESULTS, absint( $request->get_param( 'limit' ) ?: 6 ) ) );
		$response = rest_ensure_response( array( 'query' => $query, 'products' => self::cards( self::search( $query, $limit ) ) ) );
		$response->header( 'Cache-Control', 'public, max-age=120' );
		return $response;
	}

	/** Texto comparable: minúsculas, sin acentos ni signos. */
	public static function normalize( string $text ): string {
		$text = remove_accents( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		$text = mb_strtolower( $text, 'UTF-8' );
		return trim( (string) preg_replace( '/[^a-z0-9ñ]+/u', ' ', $text ) );
	}

	/** Singular aproximado para comparar plurales simples (sartenes → sarten, toallas → toalla). */
	public static function stem( string $word ): string {
		if ( mb_strlen( $word ) > 4 && preg_match( '/(ones|enes|ores|ales|eles)$/', $word ) ) {
			return mb_substr( $word, 0, -2 );
		}
		if ( mb_strlen( $word ) > 3 && 's' === substr( $word, -1 ) ) {
			return mb_substr( $word, 0, -1 );
		}
		return $word;
	}

	/** @return string[] Palabras útiles de la consulta, ya normalizadas y en singular. */
	public static function terms( string $query ): array {
		$out = array();
		foreach ( explode( ' ', self::normalize( $query ) ) as $word ) {
			if ( mb_strlen( $word ) < 2 || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}
			$out[] = self::stem( $word );
		}
		return array_values( array_unique( $out ) );
	}

	private static function aliases(): array {
		return (array) apply_filters( 'cvd_product_search_aliases', self::ALIASES );
	}

	/** @return array<int, array{id:int, name:string, words:string[], extra:string[]}> */
	private static function index(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$index = array();
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) );
		foreach ( $products as $product ) {
			if ( 'hidden' === $product->get_catalog_visibility() ) {
				continue;
			}
			$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			$extra = self::normalize( implode( ' ', is_wp_error( $cats ) ? array() : $cats ) . ' ' . $product->get_short_description() . ' ' . wp_trim_words( $product->get_description(), 40, '' ) );
			$index[] = array(
				'id'    => $product->get_id(),
				'name'  => self::normalize( $product->get_name() ),
				'words' => array_values( array_unique( array_map( array( __CLASS__, 'stem' ), explode( ' ', self::normalize( $product->get_name() ) ) ) ) ),
				'extra' => array_values( array_unique( array_map( array( __CLASS__, 'stem' ), array_filter( explode( ' ', $extra ) ) ) ) ),
				'stock' => $product->is_in_stock() ? 1 : 0,
			);
		}
		set_transient( self::CACHE_KEY, $index, HOUR_IN_SECONDS );
		return $index;
	}

	private static function word_score( string $term, array $words ): int {
		$best = 0;
		foreach ( $words as $word ) {
			if ( $word === $term ) {
				return 3;
			}
			if ( mb_strlen( $term ) >= 3 && 0 === strpos( $word, $term ) ) {
				$best = max( $best, 2 );
			} elseif ( mb_strlen( $term ) >= 4 && false !== strpos( $word, $term ) ) {
				$best = max( $best, 1 );
			}
		}
		return $best;
	}

	/** Puntuación de un término (con sus sinónimos) contra un producto. */
	private static function term_score( string $term, array $row, array $aliases ): int {
		$variants = array_merge( array( $term ), array_map( array( __CLASS__, 'stem' ), $aliases[ $term ] ?? array() ) );
		$best = 0;
		foreach ( $variants as $index => $variant ) {
			$penalty = $index ? 1 : 0;
			$name = self::word_score( $variant, $row['words'] );
			if ( $name ) {
				$best = max( $best, $name * 3 - $penalty );
				continue;
			}
			$extra = self::word_score( $variant, $row['extra'] );
			if ( $extra ) {
				$best = max( $best, $extra - $penalty );
			}
		}
		return $best;
	}

	/** @return int[] IDs ordenados por relevancia (disponibles primero a igual puntuación). */
	public static function search( string $query, int $limit = 6 ): array {
		$terms = self::terms( $query );
		if ( ! $terms ) {
			return array();
		}
		$aliases = self::aliases();
		$all = array();
		$partial = array();
		foreach ( self::index() as $row ) {
			$total = 0;
			$matched = 0;
			foreach ( $terms as $term ) {
				$score = self::term_score( $term, $row, $aliases );
				$total += $score;
				$matched += $score > 0 ? 1 : 0;
			}
			if ( ! $matched ) {
				continue;
			}
			$entry = array( $row['id'], $total + $row['stock'], $matched );
			if ( $matched === count( $terms ) ) {
				$all[] = $entry;
			} else {
				$partial[] = $entry;
			}
		}
		// Primero lo que cumple todas las palabras; si no hay nada, lo que cumple más palabras.
		$results = $all ?: $partial;
		usort( $results, static fn( $a, $b ) => array( $b[2], $b[1] ) <=> array( $a[2], $a[1] ) );
		return array_slice( array_map( static fn( $r ) => (int) $r[0], $results ), 0, $limit );
	}

	/**
	 * Tarjetas de producto con precio y disponibilidad reales de WooCommerce, leídos en el momento.
	 *
	 * @param bool $exact true solo para gestoras y operación: incluye unidades exactas y por modelo.
	 */
	public static function cards( array $ids, bool $exact = false ): array {
		$cards = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}
			$image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
			$card = array(
				'id'         => $product->get_id(),
				'name'       => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'price'      => wp_strip_all_tags( html_entity_decode( wc_price( wc_get_price_to_display( $product ) ), ENT_QUOTES, 'UTF-8' ) ),
				'regular'    => $product->is_on_sale() ? wp_strip_all_tags( html_entity_decode( wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) ), ENT_QUOTES, 'UTF-8' ) ) : '',
				'image'      => $image ? $image : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
				'url'        => get_permalink( $product->get_id() ),
				'inStock'    => $product->is_in_stock(),
				'quickAdd'   => $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock(),
				'stockLabel' => self::availability( $product, $exact ),
			);
			if ( $exact ) {
				$card['stock'] = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
				$card['variants'] = self::variants( $product );
			}
			$cards[] = $card;
		}
		return $cards;
	}

	/** Umbral de "últimas unidades": el de la ficha o el de la tienda (WooCommerce › Inventario). */
	private static function low_threshold( WC_Product $product ): int {
		$amount = method_exists( $product, 'get_low_stock_amount' ) ? $product->get_low_stock_amount() : '';
		return max( 1, (int) ( '' !== $amount && null !== $amount ? $amount : get_option( 'woocommerce_notify_low_stock_amount', 2 ) ) );
	}

	/** Texto de disponibilidad: exacto para gestoras; para clientes solo disponible, últimas unidades o agotado. */
	public static function availability( WC_Product $product, bool $exact = false ): string {
		if ( ! $product->is_in_stock() ) {
			return 'Agotado';
		}
		if ( ! $product->managing_stock() ) {
			if ( $exact && $product->is_type( 'variable' ) ) {
				$total = array_sum( array_map( static fn( $v ) => (int) ( $v['stock'] ?? 0 ), self::variants( $product ) ) );
				return $total > 0 ? "Quedan {$total} en total" : 'Disponible';
			}
			return 'Disponible';
		}
		$qty = (int) $product->get_stock_quantity();
		if ( $exact ) {
			return 1 === $qty ? 'Queda 1' : "Quedan {$qty}";
		}
		return $qty <= self::low_threshold( $product ) ? 'Últimas unidades' : 'Disponible';
	}

	/** @return array<int, array{name:string, stock:?int, inStock:bool}> Modelos de un producto variable con sus existencias. */
	private static function variants( WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$out = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->exists() || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$out[] = array(
				'name'    => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false, false ) ),
				'stock'   => $variation->managing_stock() ? (int) $variation->get_stock_quantity() : null,
				'inStock' => $variation->is_in_stock(),
			);
		}
		return $out;
	}
}
