<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.13.83 · Portada que vende (Ernesto, 13-oct: «esto está de más, no lleva a ninguna compra… como en Amazon,
 * Temu o Shein, poner productos y decisiones de compra»).
 *
 * La portada son filas de productos que se deslizan de lado, con datos reales de WooCommerce:
 * - «Lo más vendido» de la semana, por pedidos reales (sin pruebas, cancelados ni pendientes de pago);
 * - «Recién llegados» (también los «Bajo pedido»);
 * - «A buen precio»: rebajados y de 15 USD o menos;
 * - una fila por departamento (las mismas fichas de arriba), cada una con «Ver todo».
 * Arriba siguen las fichas de departamentos y el buscador. La confianza queda en una franja fina (entrega, pago,
 * WhatsApp) y «Regala a tu familia» en una línea. Salen de la portada las tres tarjetas grandes y la caja de tres
 * pasos; las páginas a las que llevaban siguen igual.
 *
 * Tienda espejo (?ref=): el precio sale de wc_get_price_to_display() / get_variation_price(), que pasan por los
 * filtros de CVD_Gestora_Store, así que se ve el precio de la gestora. La caché de página la decide
 * CVD_Attribution como en /tienda/: con ?ref= o con la cookie de referido la página no se guarda.
 *
 * Solo presentación: no cambia productos, precios, existencias, comisiones ni pedidos. Lo único que guarda es un
 * contador por día (visitas, toques en productos, «Añadir», «Ver todo») en la opción cvd_home_stats, sin datos personales.
 *
 * Volver a la portada anterior sin desplegar: `wp option update cvd_home_rails no` y `wp litespeed-purge all`.
 */
final class CVD_Home_Rails {
	private const STATS_OPTION = 'cvd_home_stats';
	private const STATS_DAYS   = 60;
	private const TOP_LIMIT    = 12;
	private const DEPT_LIMIT   = 8;
	private const LOW_PRICE    = 15.0;
	private const LOW_STOCK    = 1; // 3.13.87: «Últimas unidades» solo con 1 (Ernesto, 13-oct)
	private const MIN_SOLD     = 4;
	private const EAGER_IMAGES = 3;

	private static ?bool $active = null;
	private static ?string $ref = null;
	private static ?bool $cup_ok = null;

	public static function register(): void {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 98 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 96 );
		add_action( 'wp_ajax_cvd_home_event', array( __CLASS__, 'ajax_event' ) );
		add_action( 'wp_ajax_nopriv_cvd_home_event', array( __CLASS__, 'ajax_event' ) );
		// Las tarjetas llevan el precio en CUP con la tasa del día: si la tasa cambia, la portada guardada en caché se rehace.
		add_action( 'update_option_cvd_daily_rates', array( __CLASS__, 'purge_home' ) );
		add_action( 'add_option_cvd_daily_rates', array( __CLASS__, 'purge_home' ) );
	}

	/** Interruptor: `cvd_home_rails` = no vuelve a la portada del tema. */
	public static function enabled(): bool {
		return 'no' !== (string) get_option( 'cvd_home_rails', 'si' );
	}

	/** ¿Se pinta la portada nueva? Portada pública, sin búsqueda ni páginas 2, 3… */
	public static function active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}
		if ( ! did_action( 'wp' ) ) {
			return false;
		}
		self::$active = ! is_admin() && ! wp_doing_ajax() && is_front_page() && ! is_search() && ! is_paged() && function_exists( 'wc_get_product' ) && self::enabled();
		return self::$active;
	}

	public static function template( $template ) {
		if ( ! self::active() ) {
			return $template;
		}
		$own = CVD_DIR . 'templates/home-rails.php';
		return is_readable( $own ) ? $own : $template;
	}

	public static function assets(): void {
		if ( ! self::active() ) {
			return;
		}
		// La fecha del archivo en la versión: un retoque se ve al momento aunque el navegador guarde el CSS 7 días.
		$ver = static function ( string $file ): string {
			$path = CVD_DIR . 'assets/' . $file;
			return CVD_VERSION . '.' . ( is_readable( $path ) ? (int) filemtime( $path ) : 0 );
		};
		wp_enqueue_style( 'cvd-home-rails', CVD_URL . 'assets/home-rails.css', array(), $ver( 'home-rails.css' ) );
		wp_enqueue_script( 'cvd-home-rails', CVD_URL . 'assets/home-rails.js', array(), $ver( 'home-rails.js' ), true );
		wp_localize_script( 'cvd-home-rails', 'cvdHome', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}

	public static function purge_home(): void {
		do_action( 'litespeed_purge_url', home_url( '/' ) );
	}

	/* ---------------------------------------------------------------- Tienda espejo */

	/** Código de la gestora de esta visita (solo si el enlace es de una gestora aprobada), o ''. */
	private static function ref(): string {
		if ( null === self::$ref ) {
			$gestora = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::active_gestora() : null;
			self::$ref = $gestora ? (string) get_user_meta( $gestora->ID, '_cvd_referral_code', true ) : '';
		}
		return self::$ref;
	}

	private static function link( string $url ): string {
		return '' !== self::ref() ? add_query_arg( 'ref', self::ref(), $url ) : $url;
	}

	/* ---------------------------------------------------------------- Datos */

	private static function visibility_clause(): array {
		// WooCommerce marca con «outofstock» los agotados: así no salen nunca en la portada.
		return array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'exclude-from-catalog', 'outofstock' ),
			'operator' => 'NOT IN',
		);
	}

	/** @return int[] */
	private static function query_ids( array $args ): array {
		$tax = array( 'relation' => 'AND', self::visibility_clause() );
		foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
			$tax[] = $clause;
		}
		unset( $args['tax_query'] );
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => self::TOP_LIMIT,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				),
				$args
			)
		);
		return array_values( array_unique( array_map( 'absint', $query->posts ) ) );
	}

	/**
	 * Productos de pedidos reales de los últimos $days días, del más pedido al menos pedido.
	 * Cuenta pedidos en espera, en preparación y completados; no cuenta pruebas (_cvd_test_order), cancelados,
	 * reembolsados, fallidos ni pendientes de pago.
	 *
	 * @return int[]
	 */
	private static function sold_ids( int $days, int $limit ): array {
		global $wpdb;
		$statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$imeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$ometa = $wpdb->prefix . 'wc_orders_meta';
			$sql = "SELECT pid.meta_value FROM {$orders} o
				INNER JOIN {$items} oi ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				INNER JOIN {$imeta} pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				LEFT JOIN {$imeta} qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
				LEFT JOIN {$ometa} t ON t.order_id = o.id AND t.meta_key = '_cvd_test_order'
				WHERE o.type = 'shop_order' AND o.status IN ({$in}) AND o.date_created_gmt >= %s AND ( t.meta_value IS NULL OR t.meta_value = '' )
				GROUP BY pid.meta_value ORDER BY COUNT( DISTINCT o.id ) DESC, SUM( qty.meta_value ) DESC LIMIT %d";
		} else {
			$sql = "SELECT pid.meta_value FROM {$wpdb->posts} o
				INNER JOIN {$items} oi ON oi.order_id = o.ID AND oi.order_item_type = 'line_item'
				INNER JOIN {$imeta} pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				LEFT JOIN {$imeta} qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
				LEFT JOIN {$wpdb->postmeta} t ON t.post_id = o.ID AND t.meta_key = '_cvd_test_order'
				WHERE o.post_type = 'shop_order' AND o.post_status IN ({$in}) AND o.post_date_gmt >= %s AND ( t.meta_value IS NULL OR t.meta_value = '' )
				GROUP BY pid.meta_value ORDER BY COUNT( DISTINCT o.ID ) DESC, SUM( qty.meta_value ) DESC LIMIT %d";
		}
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $statuses, array( $since, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( 'absint', (array) $rows ) ) );
	}

	/** Solo los visibles y con existencias, en el mismo orden. @return int[] */
	private static function keep_visible( array $ids, int $limit ): array {
		if ( ! $ids ) {
			return array();
		}
		return self::query_ids( array( 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => $limit ) );
	}

	/** Ordena por unidades vendidas de siempre (total_sales), sin perder el orden original en los empates. @return int[] */
	private static function by_sales( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		update_meta_cache( 'post', $ids );
		$rank = array_flip( $ids );
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $rank ): int {
				$diff = (int) get_post_meta( $b, 'total_sales', true ) <=> (int) get_post_meta( $a, 'total_sales', true );
				return $diff ?: $rank[ $a ] <=> $rank[ $b ];
			}
		);
		return $ids;
	}

	/** Las filas de la portada, en orden. Cada una: key, title, note, all (URL de «Ver todo»), ids. */
	public static function rails(): array {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' );
		$rails = array();

		// 1. Lo más vendido: la semana; si la semana tiene pocos pedidos, el mes; si no, lo más vendido de siempre.
		$note = 'esta semana';
		$best = self::keep_visible( self::sold_ids( 7, 40 ), self::TOP_LIMIT );
		if ( count( $best ) < self::MIN_SOLD ) {
			$note = 'este mes';
			$best = self::keep_visible( self::sold_ids( 30, 40 ), self::TOP_LIMIT );
		}
		if ( count( $best ) < self::MIN_SOLD ) {
			$note = '';
			$best = array_slice( self::by_sales( self::query_ids( array( 'posts_per_page' => 40 ) ) ), 0, self::TOP_LIMIT );
		}
		$rails[] = array(
			'key'   => 'mas-vendidos',
			'title' => 'Lo más vendido',
			'note'  => $note,
			'all'   => add_query_arg( 'orderby', 'popularity', $shop ),
			'ids'   => $best,
		);

		// 2. Recién llegados (también lo que se pide al proveedor, «Bajo pedido»).
		$rails[] = array(
			'key'   => 'recien-llegados',
			'title' => 'Recién llegados',
			'note'  => '',
			'all'   => add_query_arg( 'orderby', 'date', $shop ),
			'ids'   => self::query_ids( array( 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) ) ),
		);

		// 3. A buen precio: primero los rebajados de verdad; después los de 15 USD o menos que más se venden.
		$sale = array();
		foreach ( wc_get_product_ids_on_sale() as $id ) {
			$parent = wp_get_post_parent_id( (int) $id );
			$sale[] = $parent ? $parent : (int) $id;
		}
		$sale = self::keep_visible( array_values( array_unique( $sale ) ), self::TOP_LIMIT );
		$cheap = self::by_sales(
			self::query_ids(
				array(
					'posts_per_page' => 60,
					'post__not_in'   => $sale ? $sale : array( 0 ),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_price',
							'value'   => array( 0.01, self::LOW_PRICE ),
							'compare' => 'BETWEEN',
							'type'    => 'DECIMAL(10,2)',
						),
					),
				)
			)
		);
		$rails[] = array(
			'key'   => 'buen-precio',
			'title' => 'A buen precio',
			'note'  => 'ofertas y de 15 USD o menos',
			'all'   => add_query_arg( 'orderby', 'price', $shop ),
			'ids'   => array_slice( array_merge( $sale, $cheap ), 0, self::TOP_LIMIT ),
		);

		// 4. Una fila por departamento, en el orden de las fichas de arriba.
		if ( class_exists( 'CVD_Departments' ) ) {
			foreach ( CVD_Departments::terms() as $slug => $term ) {
				$ids = self::query_ids(
					array(
						'posts_per_page' => 30,
						'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
							array(
								'taxonomy'         => 'product_cat',
								'field'            => 'term_id',
								'terms'            => (int) $term->term_id,
								'include_children' => true,
							),
						),
					)
				);
				$link = get_term_link( $term );
				$rails[] = array(
					'key'   => 'dep-' . $slug,
					'title' => $term->name,
					'note'  => '',
					'all'   => is_wp_error( $link ) ? $shop : (string) $link,
					'ids'   => array_slice( self::by_sales( $ids ), 0, self::DEPT_LIMIT ),
				);
			}
		}
		return $rails;
	}

	/* ---------------------------------------------------------------- Tarjeta */

	/** [precio que se ve, precio anterior tachado (0 si no hay), ¿es «desde»?] con el precio de la gestora si hay ?ref=. */
	private static function prices( WC_Product $product ): array {
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );
			$regular = (float) $product->get_variation_regular_price( 'min', true );
			return array( $min, ( $max <= $min && $regular > $min ) ? $regular : 0.0, $max > $min );
		}
		$price = (float) wc_get_price_to_display( $product );
		$regular = '' !== (string) $product->get_regular_price() ? (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) : 0.0;
		return array( $price, $regular > $price ? $regular : 0.0, false );
	}

	/** «23.550 CUP» con la tasa del día; nada si la tasa tiene más de 36 h (mejor callar que dar una cifra vieja). */
	private static function cup_text( float $usd ): string {
		if ( null === self::$cup_ok ) {
			self::$cup_ok = class_exists( 'CVD_Daily_Rates' ) && ! CVD_Daily_Rates::is_stale();
		}
		if ( ! self::$cup_ok ) {
			return '';
		}
		$cup = CVD_Daily_Rates::cup_for( $usd );
		return $cup ? CVD_Daily_Rates::format_cup( $cup ) : '';
	}

	/** «Últimas unidades»: quedan 1 o 2 (sumando colores o modelos si los tiene). */
	private static function low_stock( WC_Product $product ): bool {
		if ( $product->managing_stock() ) {
			$qty = (int) $product->get_stock_quantity();
			return $qty > 0 && $qty <= self::LOW_STOCK;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return false;
		}
		$total = 0;
		$children = $product->get_children();
		if ( ! $children ) {
			return false;
		}
		foreach ( $children as $variation_id ) {
			// Un color sin control de existencias no tiene cifra: no se puede decir que quedan pocas.
			if ( 'yes' !== get_post_meta( $variation_id, '_manage_stock', true ) ) {
				return false;
			}
			$total += max( 0, (int) get_post_meta( $variation_id, '_stock', true ) );
		}
		return $total > 0 && $total <= self::LOW_STOCK;
	}

	private static function image( WC_Product $product, bool $eager ): string {
		$src = wp_get_attachment_image_src( (int) $product->get_image_id(), 'woocommerce_thumbnail' );
		$url = $src ? (string) $src[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$load = $eager ? 'fetchpriority="high"' : 'loading="lazy"';
		return '<img src="' . esc_url( $url ) . '" width="300" height="300" alt="" ' . $load . ' decoding="async">';
	}

	private static function card( WC_Product $product, string $list, bool $eager ): string {
		list( $price, $regular, $from ) = self::prices( $product );
		if ( $price <= 0 ) {
			return '';
		}
		$name = $product->get_name();
		$url = self::link( $product->get_permalink() );
		$market = class_exists( 'CVD_Market_Bridge' ) && CVD_Market_Bridge::is_market_sku( (string) $product->get_sku() );
		$off = class_exists( 'CVD_Premium_Storefront' ) ? CVD_Premium_Storefront::discount( $product ) : 0;
		$low = ! $market && self::low_stock( $product );
		$cup = self::cup_text( $price );
		$can_add = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();

		$html = '<li class="cvd-card">';
		$html .= '<a class="cvd-card__link" href="' . esc_url( $url ) . '" data-cvd-list="' . esc_attr( $list ) . '">';
		$html .= '<span class="cvd-card__media">' . self::image( $product, $eager );
		if ( $off ) {
			$html .= '<span class="cvd-card__off">-' . esc_html( (string) $off ) . '%</span>';
		}
		if ( $low ) {
			$html .= '<span class="cvd-card__low">Últimas unidades</span>';
		}
		$html .= '</span>';
		$html .= '<span class="cvd-card__name">' . esc_html( $name ) . '</span>';
		$html .= '<span class="cvd-card__price">';
		if ( $from ) {
			$html .= '<span class="cvd-card__from">desde</span> ';
		}
		if ( $regular > 0 ) {
			$html .= '<del aria-label="' . esc_attr( 'Antes ' . wp_strip_all_tags( wc_price( $regular ) ) ) . '">' . wp_kses_post( wc_price( $regular ) ) . '</del> ';
		}
		$html .= wp_kses_post( wc_price( $price ) ) . '</span>';
		if ( '' !== $cup ) {
			$html .= '<span class="cvd-card__cup">' . esc_html( $cup ) . '</span>';
		}
		if ( $market ) {
			$html .= '<span class="cvd-card__market"><strong>Bajo pedido</strong> <span>llega en 24 h</span></span>';
		}
		$html .= '</a>';
		if ( $can_add ) {
			$html .= '<a class="cvd-card__add add_to_cart_button ajax_add_to_cart product_type_simple" href="' . esc_url( $product->add_to_cart_url() ) . '" data-product_id="' . esc_attr( (string) $product->get_id() ) . '" data-quantity="1" data-cvd-list="' . esc_attr( $list ) . '" rel="nofollow" aria-label="' . esc_attr( 'Añadir ' . $name . ' al carrito' ) . '">Añadir</a>';
		} else {
			$html .= '<a class="cvd-card__add cvd-card__add--ghost" href="' . esc_url( $url ) . '" data-cvd-list="' . esc_attr( $list ) . '" aria-label="' . esc_attr( 'Elegir color o modelo de ' . $name ) . '">Elegir</a>';
		}
		return $html . '</li>';
	}

	/* ---------------------------------------------------------------- Portada */

	/** @param WC_Product[] $products */
	private static function prime_variations( array $products ): void {
		$children = array();
		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) && ! $product->managing_stock() ) {
				$children = array_merge( $children, $product->get_children() );
			}
		}
		if ( $children ) {
			update_meta_cache( 'post', array_map( 'absint', $children ) );
		}
	}

	private static function rail_html( array $rail, array $products, int &$eager_left ): string {
		$cards = '';
		foreach ( $rail['ids'] as $id ) {
			$product = $products[ $id ] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$card = self::card( $product, $rail['key'], $eager_left > 0 );
			if ( '' !== $card ) {
				$cards .= $card;
				--$eager_left;
			}
		}
		if ( '' === $cards ) {
			return '';
		}
		$id = 'cvd-rail-' . $rail['key'];
		$note = '' !== $rail['note'] ? '<small class="cvd-rail__note">' . esc_html( $rail['note'] ) . '</small>' : '';
		return '<section class="cvd-rail" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-t">'
			. '<div class="cvd-home__wrap cvd-rail__head"><h2 class="cvd-rail__title" id="' . esc_attr( $id ) . '-t">' . esc_html( $rail['title'] ) . ' ' . $note . '</h2>'
			. '<a class="cvd-rail__all" href="' . esc_url( self::link( $rail['all'] ) ) . '" data-cvd-list="' . esc_attr( $rail['key'] ) . '" data-cvd-kind="todo" aria-label="' . esc_attr( 'Ver todo: ' . $rail['title'] ) . '">Ver todo <span aria-hidden="true">›</span></a></div>'
			. '<ul class="cvd-rail__list" role="list">' . $cards . '</ul></section>';
	}

	private static function trust_html(): string {
		$items = array(
			array( '/condiciones-de-mensajeria/', '🚚', 'Entrega en 24 h' ),
			array( '/formas-de-pago/', '💵', 'Efectivo, Zelle o transferencia' ),
			array( '/atencion-al-cliente/', '💬', 'WhatsApp' ),
		);
		$html = '<ul class="cvd-home-trust" aria-label="Cómo compras en Casa Viva">';
		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( self::link( home_url( $item[0] ) ) ) . '"><span aria-hidden="true">' . $item[1] . '</span> ' . esc_html( $item[2] ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	private static function gift_html(): string {
		return '<div class="cvd-home__wrap"><a class="cvd-home-gift" href="' . esc_url( self::link( home_url( '/formas-de-pago/' ) ) ) . '">'
			. '<span aria-hidden="true">🎁</span><span><strong>¿Vives fuera de Cuba?</strong> Regala a tu familia en La Habana: pagas desde donde estés.</span>'
			. '<span class="cvd-home-gift__go" aria-hidden="true">›</span></a></div>';
	}

	private static function search_html(): string {
		$ref = self::ref();
		ob_start();
		?>
		<section class="cvd-home__wrap cvd-home__search" aria-label="Buscar productos">
			<form class="cv-search cv-market-search" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
				<label class="screen-reader-text" for="cv-product-search">Buscar productos</label>
				<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
				<input id="cv-product-search" name="s" type="search" placeholder="Buscar productos en Casa Viva" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cv-search-suggestions">
				<input type="hidden" name="post_type" value="product">
				<?php if ( '' !== $ref ) : ?><input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>"><?php endif; ?>
				<button type="submit">Buscar</button>
				<ul class="cv-search-suggestions" id="cv-search-suggestions" role="listbox" aria-label="Productos sugeridos"></ul>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function render(): void {
		$rails = self::rails();
		$all = array();
		foreach ( $rails as $rail ) {
			$all = array_merge( $all, $rail['ids'] );
		}
		$all = array_values( array_unique( $all ) );
		// Todo de una vez: entradas y datos de los productos, y los datos de sus fotos.
		if ( $all ) {
			_prime_post_caches( $all, false, true );
			$thumbs = array();
			foreach ( $all as $id ) {
				$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
				if ( $thumb ) {
					$thumbs[] = $thumb;
				}
			}
			if ( $thumbs ) {
				_prime_post_caches( array_values( array_unique( $thumbs ) ), false, true );
			}
		}
		$products = array();
		foreach ( $all as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && 'publish' === $product->get_status() && $product->is_visible() && $product->is_in_stock() && '' !== (string) $product->get_price() ) {
				$products[ $id ] = $product;
			}
		}
		self::prime_variations( $products );

		$gestora = class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::active_gestora() : null;
		$eager = self::EAGER_IMAGES;

		echo '<main class="cvd-home" id="main">';
		if ( class_exists( 'CVD_Departments' ) ) {
			echo CVD_Departments::bar_html( 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en bar_html().
		}
		if ( $gestora ) {
			echo '<p class="cvd-home__wrap cvd-home-store">Tienda atendida por <strong>' . esc_html( $gestora->display_name ) . '</strong></p>';
		}
		echo self::search_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado arriba.
		foreach ( $rails as $i => $rail ) {
			echo self::rail_html( $rail, $products, $eager ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en card() y rail_html().
			if ( 0 === $i ) {
				echo self::trust_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 1 === $i ) {
				echo self::gift_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '</main>';
	}

	/* ---------------------------------------------------------------- Medida */

	/**
	 * Contador anónimo por día (hora de la web): visitas a la portada (una por sesión y día; «casa-viva» o
	 * «tienda-gestor»), toques en productos («click»), «Añadir» («add») y «Ver todo» («todo»), por fila.
	 * Leer: wp option get cvd_home_stats --format=json
	 */
	public static function ajax_event(): void {
		$event = isset( $_POST['e'] ) ? sanitize_key( wp_unslash( $_POST['e'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- contador público sin datos personales.
		$list = isset( $_POST['l'] ) ? sanitize_key( wp_unslash( $_POST['l'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $event, array( 'view', 'click', 'add', 'todo' ), true ) ) {
			wp_send_json_error( null, 400 );
		}
		if ( 'view' === $event ) {
			$list = ! empty( $_POST['m'] ) ? 'tienda-gestor' : 'casa-viva'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} else {
			$known = array( 'mas-vendidos', 'recien-llegados', 'buen-precio' );
			if ( class_exists( 'CVD_Departments' ) ) {
				foreach ( array_keys( CVD_Departments::terms() ) as $slug ) {
					$known[] = 'dep-' . $slug;
				}
			}
			if ( ! in_array( $list, $known, true ) ) {
				$list = 'otra';
			}
		}
		$day = wp_date( 'Y-m-d' );
		$stats = get_option( self::STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$stats[ $day ][ $event ][ $list ] = (int) ( $stats[ $day ][ $event ][ $list ] ?? 0 ) + 1;
		if ( count( $stats ) > self::STATS_DAYS ) {
			ksort( $stats );
			$stats = array_slice( $stats, -self::STATS_DAYS, null, true );
		}
		update_option( self::STATS_OPTION, $stats, false );
		wp_send_json_success();
	}
}
