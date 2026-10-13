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
 */
final class CVD_Departments {
	/** Orden de las fichas (slugs de product_cat). Ampliable con el filtro `cvd_department_order`. */
	private const ORDER = array( 'bano', 'cocina', 'habitacion', 'sala-y-muebles', 'organizacion', 'electrodomesticos', 'apagones-y-energia', 'tecnologia', 'cuidado-personal', 'accesorios', 'movilidad', 'ferreteria', 'navidad' );
	private const OTHER_RANK = 999;

	private static ?array $terms = null;
	private static ?int $last_rank = null;
	private static ?bool $last_market = null;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 41 );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'render_bar' ), 2 );
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

	public static function assets(): void {
		if ( ! self::is_catalog() && ! self::is_home() ) { return; }
		wp_enqueue_style( 'cvd-departments', CVD_URL . 'assets/departments.css', array(), CVD_VERSION );
	}

	/** El código de la gestora (tienda espejo) viaja en los enlaces, como en la cabecera del tema. */
	private static function link( string $url ): string {
		$ref = isset( $_GET['ref'] ) ? strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', (string) wp_unslash( $_GET['ref'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- enlace público de solo lectura.
		return $ref ? add_query_arg( 'ref', $ref, $url ) : $url;
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
		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product && class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::is_market_sku( (string) $product->get_sku() );
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

	public static function market_badge(): void {
		$id = (int) get_the_ID();
		if ( $id && self::is_market( $id ) ) {
			echo '<span class="cvd-market-badge">Bajo pedido · confirmamos en 1 h</span>';
		}
	}
}
