<?php

defined( 'ABSPATH' ) || exit;

/**
 * Capa visual premium de la tienda pública: portada con imagen, barra fija de compra en móvil,
 * garantías junto al botón, disponibilidad honesta y aviso de ofertas reales.
 *
 * Solo presentación: no cambia productos, precios, stock, tarifas ni pedidos.
 */
final class CVD_Premium_Storefront {
	/** Imagen de portada generada para Casa Viva (sin productos). Se puede cambiar con el filtro cvd_home_hero_image. */
	private const HERO_IMAGE = 'https://d8j0ntlcm91z4.cloudfront.net/user_3JKeIPPvD2MrM6gfmHcWhePZrny/hf_20261002_173408_6116c57a-ea84-4ced-b167-23cc7404b179_min.webp';
	private const MAX_OFFERS = 4;
	private static bool $hero_done = false;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 95 );
		add_filter( 'the_content', array( __CLASS__, 'prepend_hero' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 15 );
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'trust_lines' ) );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 20, 2 );
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'sale_flash' ), 20, 3 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'only_offers' ) );
	}

	/** /tienda/?cvd_ofertas=1 muestra solo productos rebajados. */
	public static function only_offers( $query ): void {
		if ( empty( $_GET['cvd_ofertas'] ) || ! $query instanceof WP_Query ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro público de solo lectura.
			return;
		}
		$ids = wc_get_product_ids_on_sale();
		$query->set( 'post__in', $ids ? array_map( 'absint', $ids ) : array( 0 ) );
	}

	private static function shopper(): bool {
		return ! is_user_logged_in() || in_array( CVD_Contextual_Assistant::context(), array( 'visitante', 'cliente' ), true );
	}

	private static function is_home(): bool {
		return ! is_admin() && is_front_page();
	}

	public static function assets(): void {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'cvd-premium-storefront', CVD_URL . 'assets/premium-storefront.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-premium-storefront', CVD_URL . 'assets/premium-storefront.js', array(), CVD_VERSION, true );
		if ( self::is_home() ) {
			// La imagen de portada es lo primero que se ve: se pide antes que el resto.
			add_action( 'wp_head', static fn() => print( '<link rel="preload" as="image" href="' . esc_url( self::hero_image() ) . '" fetchpriority="high">' . "\n" ), 2 );
		}
	}

	private static function hero_image(): string {
		return (string) apply_filters( 'cvd_home_hero_image', self::HERO_IMAGE );
	}

	public static function hero_html(): string {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/tienda/' );
		// El segundo botón solo aparece si hay rebajas reales.
		$offers = function_exists( 'wc_get_product_ids_on_sale' ) && wc_get_product_ids_on_sale() ? add_query_arg( 'cvd_ofertas', '1', $shop ) : '';
		ob_start();
		?>
		<section class="cvd-hero" aria-labelledby="cvd-hero-title">
			<img class="cvd-hero__img" src="<?php echo esc_url( self::hero_image() ); ?>" alt="" width="1024" height="1536" fetchpriority="high" decoding="async">
			<div class="cvd-hero__body">
				<h2 id="cvd-hero-title" class="cvd-hero__title">Todo para tu casa, sin salir de ella.</h2>
				<p class="cvd-hero__text">Pides en minutos y te lo llevamos en La Habana.</p>
				<div class="cvd-hero__actions">
					<a class="cvd-hero__cta" href="<?php echo esc_url( $shop ); ?>">Comprar ahora</a>
					<?php if ( $offers ) : ?><a class="cvd-hero__cta cvd-hero__cta--ghost" href="<?php echo esc_url( $offers ); ?>">Ver ofertas</a><?php endif; ?>
				</div>
				<p class="cvd-hero__trust">✓ Confirmas tu pedido por WhatsApp</p>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public static function prepend_hero( $content ) {
		if ( self::$hero_done || ! self::is_home() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		self::$hero_done = true;
		return self::hero_html() . $content;
	}

	public static function footer(): void {
		if ( is_admin() ) { return; }
		if ( self::is_home() && ! self::$hero_done ) {
			// Si el tema no usa the_content en la portada, el script la coloca arriba del contenido.
			echo '<template id="cvd-hero-template">' . self::hero_html() . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado en hero_html().
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			self::buy_bar();
		}
		self::offers_dialog();
	}

	public static function trust_lines(): void {
		$rates = home_url( '/tarifas-mensajeria/' );
		?>
		<ul class="cvd-trust" aria-label="Cómo compras en Casa Viva">
			<li><span aria-hidden="true">✓</span> Confirmas tu pedido por WhatsApp o pagas por transferencia</li>
			<li><span aria-hidden="true">✓</span> Entrega en tu municipio o recogida en Nuevo Vedado · <a href="<?php echo esc_url( $rates ); ?>">Ver tarifas</a></li>
			<li><span aria-hidden="true">✓</span> ¿Dudas? <button type="button" class="cvd-trust__ask" data-cvd-curru-open>Pregúntale a <?php echo esc_html( CVD_Contextual_Assistant::name() ); ?></button></li>
		</ul>
		<?php
	}

	private static function buy_bar(): void {
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		$label = $product->is_type( 'variable' ) ? 'Elegir modelo' : 'Añadir al carrito';
		?>
		<div class="cvd-buybar" data-cvd-buybar hidden>
			<div class="cvd-buybar__info">
				<span class="cvd-buybar__name"><?php echo esc_html( $product->get_name() ); ?></span>
				<span class="cvd-buybar__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			</div>
			<button type="button" class="cvd-buybar__button" data-cvd-buybar-go data-variable="<?php echo $product->is_type( 'variable' ) ? '1' : '0'; ?>"><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	/** Clientes y visitantes ven estados; gestoras y operación, la cantidad que da WooCommerce. */
	public static function availability_text( $text, $product ) {
		if ( ! $product instanceof WC_Product || ! self::shopper() || ! $product->is_in_stock() || $product->is_on_backorder() ) {
			return $text;
		}
		return CVD_Product_Search::availability( $product, false );
	}

	public static function discount( WC_Product $product ): int {
		if ( $product->is_type( 'variable' ) ) {
			$regular = (float) $product->get_variation_regular_price( 'min' );
			$sale = (float) $product->get_variation_sale_price( 'min' );
		} else {
			$regular = (float) $product->get_regular_price();
			$sale = (float) $product->get_sale_price();
		}
		return ( $regular > 0 && $sale > 0 && $sale < $regular ) ? (int) round( ( $regular - $sale ) / $regular * 100 ) : 0;
	}

	public static function sale_flash( $html, $post, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}
		$off = self::discount( $product );
		return $off ? '<span class="onsale cvd-sale-badge">-' . esc_html( (string) $off ) . '%</span>' : $html;
	}

	/** @return WC_Product[] */
	private static function offers(): array {
		$out = array();
		foreach ( wc_get_product_ids_on_sale() as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && $product->get_parent_id() ) {
				$product = wc_get_product( $product->get_parent_id() );
			}
			if ( ! $product instanceof WC_Product || isset( $out[ $product->get_id() ] ) ) {
				continue;
			}
			if ( 'publish' === $product->get_status() && $product->is_visible() && $product->is_in_stock() ) {
				$out[ $product->get_id() ] = $product;
			}
			if ( count( $out ) >= self::MAX_OFFERS ) {
				break;
			}
		}
		return array_values( $out );
	}

	private static function offers_dialog(): void {
		if ( ! self::shopper() || is_cart() || is_checkout() || is_account_page() || is_product() || ! empty( $_GET['cvd_ofertas'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$offers = self::offers();
		if ( ! $offers ) {
			return;
		}
		$sale_url = add_query_arg( 'cvd_ofertas', '1', wc_get_page_permalink( 'shop' ) );
		?>
		<div class="cvd-offers" data-cvd-offers hidden>
			<div class="cvd-offers__backdrop" data-cvd-offers-close></div>
			<section class="cvd-offers__panel" role="dialog" aria-modal="true" aria-labelledby="cvd-offers-title">
				<button type="button" class="cvd-offers__close" data-cvd-offers-close aria-label="Cerrar ofertas">×</button>
				<p class="cvd-offers__eyebrow">Ofertas de ahora</p>
				<h2 id="cvd-offers-title" class="cvd-offers__title">Precios rebajados en Casa Viva</h2>
				<div class="cvd-offers__grid">
					<?php foreach ( $offers as $product ) : $off = self::discount( $product ); ?>
						<a class="cvd-offers__item" href="<?php echo esc_url( $product->get_permalink() ); ?>">
							<span class="cvd-offers__media">
								<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) ); ?>
								<?php if ( $off ) : ?><span class="cvd-offers__badge">-<?php echo esc_html( (string) $off ); ?>%</span><?php endif; ?>
							</span>
							<span class="cvd-offers__name"><?php echo esc_html( $product->get_name() ); ?></span>
							<span class="cvd-offers__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<a class="cvd-offers__cta" href="<?php echo esc_url( $sale_url ); ?>">Ver todas las ofertas</a>
			</section>
		</div>
		<?php
	}
}
