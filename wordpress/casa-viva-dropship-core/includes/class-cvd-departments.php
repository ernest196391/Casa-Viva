<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tienda por departamentos (3.13.72, pedido de Lennys y Cely: «sale todo regado»).
 *
 * - Fichas de departamentos siempre a la vista arriba en la Tienda, en cada departamento y en la portada
 *   (en el móvil se deslizan de lado). Conservan el ?ref= de la gestora.
 * - En «Todo» los productos van agrupados por departamento, con su título; dentro de cada uno salen primero los de
 *   la tienda (menu_order 0) y después los «Bajo pedido» (menu_order 10, CVD_Market_Bridge), con un separador.
 * - Las tarjetas «Bajo pedido» llevan su etiqueta.
 *
 * Solo presentación y orden: no cambia productos, precios, existencias ni categorías.
 * Los departamentos son las categorías que ya existen (nombres de BizneCubano). «Bajo pedido» no es un departamento.
 *
 * 3.14.55 (D104, una gestora pidió filtrar; Ernesto 23-oct «piensa tú y decide»): filtro por disponibilidad.
 * - Fila «Todo · 🏪 En tienda (listo para hoy) · 📦 Bajo pedido (24–48 h)» arriba del listado en la Tienda, en cada
 *   categoría, en la búsqueda y en la tienda espejo de cada gestora (?ref=). Va por la URL: ?disponible=tienda|pedido,
 *   se conserva al paginar, al ordenar, en las fichas de departamento y en el formulario de filtros del tema.
 * - Bajo pedido = categoría «Bajo pedido» o SKU MK- (o _cvd_market_item_id), como CVD_Costs::is_bajo_pedido().
 *   En tienda = el resto, sin agotados. Una sola condición SQL con subconsultas por índice (nada de cargar productos).
 * - Cada tarjeta lleva «🏪 En tienda» o «📦 Bajo pedido · 24–48 h».
 * - LiteSpeed guarda una página por URL completa (la consulta cuenta): cada filtro tiene su copia; la tienda espejo
 *   ya va sin caché (CVD_Attribution). La API del bot y de la caja no cambia.
 */
final class CVD_Departments {
	/** Orden de las fichas (slugs de product_cat). Ampliable con el filtro `cvd_department_order`. */
	private const ORDER = array( 'bano', 'cocina', 'habitacion', 'sala-y-muebles', 'organizacion', 'electrodomesticos', 'apagones-y-energia', 'tecnologia', 'cuidado-personal', 'accesorios', 'movilidad', 'ferreteria', 'navidad' );
	private const OTHER_RANK = 999;
	/** 3.14.55: parámetro de la URL del filtro por disponibilidad y sus valores. */
	public const AVAIL_PARAM = 'disponible';
	public const AVAIL_MODES = array( 'tienda', 'pedido' );
	private const MARKET_SLUG = 'bajo-pedido';

	private static ?array $terms = null;
	private static ?int $last_rank = null;
	private static ?bool $last_market = null;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 41 );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'render_bar' ), 2 );
		// 3.14.55: filtro «En tienda / Bajo pedido» (justo debajo de los departamentos; sale aunque no haya resultados).
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'render_avail_bar' ), 3 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'avail_query' ), 20 );
		add_filter( 'posts_where', array( __CLASS__, 'avail_where' ), 20, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'home_bar' ), 4 );
		add_filter( 'posts_clauses', array( __CLASS__, 'group_clauses' ), 20, 2 );
		// woocommerce_content() (el que usa el tema) no lanza woocommerce_shop_loop: el título va en the_post, justo antes
		// de cada tarjeta. woocommerce_shop_loop queda para las plantillas que sí lo lanzan (sin repetir: ver $seen).
		add_action( 'the_post', array( __CLASS__, 'on_the_post' ), 20, 2 );
		add_action( 'woocommerce_shop_loop', array( __CLASS__, 'loop_separator' ), 5 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'market_badge' ), 6 );
	}

	/** Departamentos con productos, en el orden de las fichas: slug => WP_Term. */
	public static function terms(): array {
		if ( null !== self::$terms ) { return self::$terms; }
		self::$terms = array();
		foreach ( (array) apply_filters( 'cvd_department_order', self::ORDER ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, 'product_cat' );
			if ( $term instanceof WP_Term && $term->count > 0 ) { self::$terms[ $term->slug ] = $term; }
		}
		return self::$terms;
	}

	private static function is_catalog(): bool {
		if ( is_admin() || wp_doing_ajax() ) { return false; }
		return ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_category' ) && is_product_category() ) || ( function_exists( 'is_product_tag' ) && is_product_tag() );
	}

	private static function is_home(): bool {
		return ! is_admin() && is_front_page();
	}

	/** 3.14.55: búsqueda de productos (?s=…&post_type=product), que también lleva el filtro por disponibilidad. */
	private static function is_product_search(): bool {
		return ! is_admin() && is_search() && 'product' === get_query_var( 'post_type' );
	}

	public static function assets(): void {
		if ( ! self::is_catalog() && ! self::is_home() && ! self::is_product_search() ) { return; }
		// 3.14.55: + fecha del archivo, para que un arreglo de estilo llegue aunque el navegador guarde la versión (7 días).
		$file = CVD_DIR . 'assets/departments.css';
		wp_enqueue_style( 'cvd-departments', CVD_URL . 'assets/departments.css', array(), CVD_VERSION . ( is_readable( $file ) ? '.' . filemtime( $file ) : '' ) );
	}

	/** El código de la gestora (tienda espejo) viaja en los enlaces, como en la cabecera del tema. 3.14.55: y el filtro. */
	private static function link( string $url ): string {
		$ref = isset( $_GET['ref'] ) ? strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', (string) wp_unslash( $_GET['ref'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- enlace público de solo lectura.
		$url = $ref ? add_query_arg( 'ref', $ref, $url ) : $url;
		$mode = self::avail_mode();
		return '' !== $mode ? add_query_arg( self::AVAIL_PARAM, $mode, $url ) : $url;
	}

	/** 3.14.55: filtro pedido en la URL: 'tienda', 'pedido' o '' (todo). */
	public static function avail_mode(): string {
		$mode = isset( $_GET[ self::AVAIL_PARAM ] ) ? sanitize_key( (string) wp_unslash( $_GET[ self::AVAIL_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro público de solo lectura.
		return in_array( $mode, self::AVAIL_MODES, true ) ? $mode : '';
	}

	/** 3.14.55: ¿producto de proveedor? Misma regla que CVD_Costs::is_bajo_pedido() + la categoría «Bajo pedido». */
	public static function is_bajo_pedido_id( int $product_id ): bool {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) { return false; }
		if ( class_exists( 'CVD_Costs' ) ? CVD_Costs::is_bajo_pedido( $product ) : 0 === stripos( (string) $product->get_sku( 'edit' ), 'MK-' ) ) { return true; }
		$id = $product->get_parent_id() ?: $product->get_id();
		return has_term( self::MARKET_SLUG, 'product_cat', $id );
	}

	/** 3.14.55: la página de la categoría «Bajo pedido» (ahí el filtro no tiene sentido). */
	private static function is_market_category(): bool {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) { return false; }
		$term = get_queried_object();
		return $term instanceof WP_Term && self::MARKET_SLUG === $term->slug;
	}

	/** 3.14.55: «Todo · 🏪 En tienda · 📦 Bajo pedido». Enlaces = esta misma página (página 1) con todo lo demás de la URL. */
	public static function render_avail_bar(): void {
		static $done = false;
		if ( $done || ( ! self::is_catalog() && ! self::is_product_search() ) || self::is_market_category() ) { return; }
		$done = true;
		$base = remove_query_arg( array( self::AVAIL_PARAM, 'paged', 'product-page' ), get_pagenum_link( 1, false ) );
		$mode = self::avail_mode();
		$chips = array(
			''       => array( 'Todo', '', '' ),
			'tienda' => array( 'En tienda', 'listo para hoy', '🏪' ),
			'pedido' => array( 'Bajo pedido', '24–48 h', '📦' ),
		);
		$html = '<nav class="cvd-avail" aria-label="Disponibilidad"><div class="cvd-avail__rail">';
		foreach ( $chips as $key => $chip ) {
			$active = $key === $mode;
			$url = '' === $key ? $base : add_query_arg( self::AVAIL_PARAM, $key, $base );
			$html .= '<a class="cvd-avail__chip cvd-avail__chip--' . esc_attr( '' === $key ? 'todo' : $key ) . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '" rel="nofollow"' . ( $active ? ' aria-current="page"' : '' ) . '>'
				. ( '' !== $chip[2] ? '<span class="cvd-avail__icon" aria-hidden="true">' . $chip[2] . '</span>' : '' )
				. '<span class="cvd-avail__text"><b>' . esc_html( $chip[0] ) . '</b>' . ( '' !== $chip[1] ? '<small>' . esc_html( $chip[1] ) . '</small>' : '' ) . '</span></a>';
		}
		echo $html . '</div></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado arriba.
		if ( '' !== $mode ) {
			// El formulario «Filtros» del tema (categoría y precio) no conoce el parámetro: se le añade para no perderlo.
			echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("form").forEach(function(f){if(!f.querySelector("[name=cv_category],[name=orderby]")||f.querySelector("[name=' . esc_js( self::AVAIL_PARAM ) . ']"))return;var i=document.createElement("input");i.type="hidden";i.name="' . esc_js( self::AVAIL_PARAM ) . '";i.value="' . esc_js( $mode ) . '";f.appendChild(i);});});</script>';
		}
	}

	/** 3.14.55: marca la consulta principal de productos (Tienda, categorías, etiquetas, búsqueda) con el filtro. */
	public static function avail_query( WP_Query $query ): void {
		$mode = self::avail_mode();
		if ( '' === $mode || is_admin() || ! $query->is_main_query() ) { return; }
		$query->set( 'cvd_disponible', $mode );
		if ( 'tienda' === $mode && 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			// «En tienda» = listo para hoy: sin agotados (si la tienda ya los oculta, WooCommerce lo hace solo).
			$terms = wc_get_product_visibility_term_ids();
			if ( ! empty( $terms['outofstock'] ) ) {
				$tax = (array) $query->get( 'tax_query' );
				$tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array( (int) $terms['outofstock'] ), 'operator' => 'NOT IN' );
				$query->set( 'tax_query', $tax );
			}
		}
	}

	/** 3.14.55: una condición SQL (subconsultas por índice): categoría «Bajo pedido», SKU MK- o artículo del mercado. */
	public static function avail_where( string $where, WP_Query $query ): string {
		$mode = (string) $query->get( 'cvd_disponible' );
		if ( ! in_array( $mode, self::AVAIL_MODES, true ) ) { return $where; }
		global $wpdb;
		$term = get_term_by( 'slug', self::MARKET_SLUG, 'product_cat' );
		$tt = $term instanceof WP_Term ? (int) $term->term_taxonomy_id : 0;
		$prefix = class_exists( 'CVD_Market_Bridge' ) ? CVD_Market_Bridge::SKU_PREFIX : 'MK-';
		$market = $wpdb->prepare(
			"( {$wpdb->posts}.ID IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d )"
			. " OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s )"
			. " OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cvd_market_item_id' AND meta_value <> '' ) )",
			$tt,
			$wpdb->esc_like( $prefix ) . '%'
		);
		return $where . ( 'pedido' === $mode ? " AND {$market}" : " AND NOT {$market}" );
	}

	public static function bar_html( string $context ): string {
		$terms = self::terms();
		if ( ! $terms ) { return ''; }
		$current = function_exists( 'is_product_category' ) && is_product_category() ? (string) get_queried_object()->slug : '';
		$all_active = 'shop' === $context && '' === $current;
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' );
		$html = '<nav class="cvd-depts cvd-depts--' . esc_attr( $context ) . '" aria-label="Departamentos"><div class="cvd-depts__rail" data-cvd-depts>';
		$html .= '<a class="cvd-depts__chip' . ( $all_active ? ' is-active' : '' ) . '" href="' . esc_url( self::link( $shop ) ) . '"' . ( $all_active ? ' aria-current="page"' : '' ) . '>Todo</a>';
		foreach ( $terms as $slug => $term ) {
			$active = $slug === $current;
			$html .= '<a class="cvd-depts__chip' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( self::link( (string) get_term_link( $term ) ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $term->name ) . '</a>';
		}
		return $html . '</div></nav>';
	}

	public static function render_bar(): void {
		static $done = false;
		if ( $done || ! self::is_catalog() ) { return; }
		$done = true;
		echo self::bar_html( 'shop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en bar_html().
		// La ficha elegida se ve en el móvil sin mover la página (solo se desliza la fila).
		echo '<script>(function(){var r=document.querySelector("[data-cvd-depts]"),a=r&&r.querySelector(".is-active");if(a&&a.offsetLeft+a.offsetWidth>r.clientWidth-24){r.scrollLeft=a.offsetLeft-24;}})();</script>';
	}

	/** La portada del tema no tiene un hueco con hook arriba: se pinta al final y se sube encima del contenido. */
	public static function home_bar(): void {
		// 3.13.83: la portada nueva (CVD_Home_Rails) ya pinta las fichas arriba, desde el servidor.
		if ( ! self::is_home() || ( class_exists( 'CVD_Home_Rails' ) && CVD_Home_Rails::active() ) ) { return; }
		$bar = self::bar_html( 'home' );
		if ( '' === $bar ) { return; }
		echo '<template id="cvd-depts-home">' . $bar . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en bar_html().
		echo '<script>(function(){var t=document.getElementById("cvd-depts-home"),m=document.querySelector(".cv-market-home")||document.querySelector("main");if(!t||!m)return;m.insertBefore(t.content.cloneNode(true),m.firstChild);})();</script>';
	}

	/** ¿Se agrupa por departamento? Solo en «Todo» de la Tienda, con el orden de siempre y sin búsqueda ni ofertas. */
	private static function grouping( WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || $query->is_search() ) { return false; }
		if ( ! function_exists( 'is_shop' ) || ! $query->is_post_type_archive( 'product' ) ) { return false; }
		if ( $query->is_tax() ) { return false; }
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $orderby && 'menu_order' !== $orderby ) { return false; }
		return empty( $_GET['cvd_ofertas'] ) && empty( $_GET['cv_category'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** «Todo»: primero por departamento (orden de las fichas), después como siempre (menu_order y nombre). */
	public static function group_clauses( array $clauses, WP_Query $query ): array {
		if ( ! self::grouping( $query ) ) { return $clauses; }
		$ids = array_map( static fn( WP_Term $t ): int => (int) $t->term_id, array_values( self::terms() ) );
		if ( ! $ids ) { return $clauses; }
		global $wpdb;
		$list = implode( ',', $ids );
		$clauses['join'] .= " LEFT JOIN ( SELECT cvd_tr.object_id, MIN( FIELD( cvd_tt.term_id, {$list} ) ) AS cvd_rank FROM {$wpdb->term_relationships} cvd_tr INNER JOIN {$wpdb->term_taxonomy} cvd_tt ON cvd_tt.term_taxonomy_id = cvd_tr.term_taxonomy_id WHERE cvd_tt.taxonomy = 'product_cat' AND cvd_tt.term_id IN ({$list}) GROUP BY cvd_tr.object_id ) cvd_dept ON cvd_dept.object_id = {$wpdb->posts}.ID ";
		$clauses['orderby'] = 'COALESCE( cvd_dept.cvd_rank, ' . self::OTHER_RANK . ' ) ASC' . ( '' !== trim( (string) $clauses['orderby'] ) ? ', ' . $clauses['orderby'] : '' );
		$query->set( 'cvd_grouped', true );
		return $clauses;
	}

	/** Posición del departamento de un producto (la misma cuenta que la consulta). */
	private static function rank( int $product_id ): int {
		$slugs = array_keys( self::terms() );
		$best = self::OTHER_RANK;
		foreach ( wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) ) as $slug ) {
			$i = array_search( $slug, $slugs, true );
			if ( false !== $i && $i < $best ) { $best = (int) $i; }
		}
		return $best;
	}

	private static function is_market( int $product_id ): bool {
		return self::is_bajo_pedido_id( $product_id ); // 3.14.55: la misma regla que el filtro (antes solo el SKU MK-).
	}

	public static function on_the_post( $post, $query = null ): void {
		if ( ! $post instanceof WP_Post || ! $query instanceof WP_Query || ! $query->is_main_query() || 'product' !== $post->post_type ) { return; }
		// Solo dentro de la rejilla de productos (no en la cabecera ni en otros bucles).
		if ( ! did_action( 'woocommerce_before_shop_loop' ) || did_action( 'woocommerce_after_shop_loop' ) ) { return; }
		self::loop_separator( (int) $post->ID );
	}

	/** Títulos de departamento en «Todo» y el separador «Bajo pedido» entre lo de la tienda y lo bajo pedido. */
	public static function loop_separator( int $id = 0 ): void {
		static $seen = array();
		global $wp_query;
		if ( ! self::is_catalog() || ! $wp_query instanceof WP_Query ) { return; }
		$id = $id ?: (int) get_the_ID();
		if ( ! $id || isset( $seen[ $id ] ) ) { return; }
		$seen[ $id ] = true;
		$grouped = (bool) $wp_query->get( 'cvd_grouped' );
		$market = self::is_market( $id );
		if ( $grouped ) {
			$rank = self::rank( $id );
			if ( $rank !== self::$last_rank ) {
				self::$last_rank = $rank;
				self::$last_market = null;
				$terms = array_values( self::terms() );
				$term = $terms[ $rank ] ?? null;
				echo '<li class="cvd-dept-head" role="presentation"><h2>' . esc_html( $term ? $term->name : 'Otros productos' ) . '</h2>'
					. ( $term ? '<a href="' . esc_url( self::link( (string) get_term_link( $term ) ) ) . '">Ver todo ' . esc_html( $term->name ) . ' <span aria-hidden="true">→</span></a>' : '' ) . '</li>';
			}
		}
		if ( $market && true !== self::$last_market ) {
			echo '<li class="cvd-dept-sub" role="presentation"><span>Bajo pedido</span> Los pedimos al proveedor: te confirmamos en 1 hora antes de cobrar.</li>';
		}
		self::$last_market = $market;
	}

	/** 3.14.55: cada tarjeta dice de dónde sale: «🏪 En tienda» (listo para hoy) o «📦 Bajo pedido · 24–48 h». */
	public static function market_badge(): void {
		$id = (int) get_the_ID();
		if ( ! $id ) { return; }
		if ( self::is_market( $id ) ) {
			echo '<span class="cvd-market-badge"><span><span aria-hidden="true">📦</span> Bajo pedido</span> <span>· 24–48&nbsp;h</span></span>';
			return;
		}
		$product = wc_get_product( $id );
		if ( $product instanceof WC_Product && $product->is_in_stock() ) {
			echo '<span class="cvd-market-badge cvd-market-badge--tienda"><span aria-hidden="true">🏪</span> En tienda</span>';
		}
	}
}
