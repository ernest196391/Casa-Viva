<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Datos a mano» (3.14.63, maratón de ideas del 24-oct en el General; decidido por Ernesto). Lo que más frena las
 * ventas es la desconfianza y la espera: el cliente cree que lo «bajo pedido» ya está en la tienda, la gestora no
 * tiene a mano lo que queda ni cuándo vuelve lo agotado, y el cliente no ve cuánto cuesta la mensajería a su zona.
 *
 * - Ficha y carrito: «📦 Bajo pedido · llega en 24 h» bien visible (en la tienda y en «Fotos para publicar» lo ponen
 *   CVD_Departments, CVD_Gestora_App y CVD_Gestora_Fotos con la misma frase, BAJO_PEDIDO).
 * - Ficha: «Quedan X» con la existencia real para todos (antes solo las gestoras; los clientes veían «Disponible»).
 * - Agotado con fecha conocida: «Agotado · vuelve el jueves 30 de octubre». La fecha la pone la tienda en la ficha
 *   del producto (WordPress → Inventario → «Vuelve el día»). Sin fecha no se inventa nada; pasada la fecha, no sale.
 * - Página «Tarifas de mensajería» (/tarifas-mensajeria/): debajo del buscador, la tabla completa de precios por
 *   municipio y reparto (las tarifas oficiales de CVD_Shipping_Rates, solo las activas).
 *
 * Solo presentación: no cambia precios, existencias, tarifas ni pedidos.
 */
final class CVD_Datos_A_Mano {
	public const BAJO_PEDIDO = 'Bajo pedido · llega en 24 h';
	/** Fecha (Y-m-d) en que vuelve un producto agotado. En el producto padre (sirve para todos sus colores). */
	public const META_BACK = '_cvd_vuelve';
	private const RATES_PAGE = 'tarifas-mensajeria';

	public static function register(): void {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_notice' ), 12 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 40, 2 );
		// El tema (casa-viva-storefront) pinta su propio «Disponible / Última unidad» con woocommerce_get_stock_html (10)
		// sin mirar el texto de arriba: se vuelve a pintar con él.
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'stock_html' ), 40, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 20, 2 );
		add_action( 'woocommerce_product_options_stock_status', array( __CLASS__, 'admin_back_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'admin_save_back' ) );
		add_filter( 'the_content', array( __CLASS__, 'rates_table_in_page' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ) );
	}

	public static function is_bajo_pedido( WC_Product $product ): bool {
		if ( class_exists( 'CVD_Departments' ) ) { return CVD_Departments::is_bajo_pedido_id( $product->get_id() ); }
		return class_exists( 'CVD_Costs' ) && CVD_Costs::is_bajo_pedido( $product );
	}

	/** Fecha en que vuelve (Y-m-d) si está agotado y la fecha es de hoy en adelante; si no, ''. */
	public static function back_date( WC_Product $product ): string {
		if ( $product->is_in_stock() ) { return ''; }
		$source = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$date = $source instanceof WC_Product ? (string) $source->get_meta( self::META_BACK, true ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return ''; }
		return $date >= wp_date( 'Y-m-d' ) ? $date : '';
	}

	/** «Vuelve hoy», «Vuelve mañana» o «Vuelve el jueves 30 de octubre»; '' si no hay fecha. */
	public static function back_label( WC_Product $product ): string {
		$date = self::back_date( $product );
		if ( '' === $date ) { return ''; }
		$today = wp_date( 'Y-m-d' );
		if ( $date === $today ) { return 'Vuelve hoy'; }
		if ( $date === wp_date( 'Y-m-d', strtotime( $today . ' 12:00 +1 day' ) ) ) { return 'Vuelve mañana'; }
		$ts = strtotime( $date . ' 12:00' );
		return 'Vuelve el ' . date_i18n( 'l j \d\e F', $ts );
	}

	/**
	 * Lo que dice la ficha (y el color elegido) sobre la existencia, para todos:
	 * «📦 Bajo pedido · llega en 24 h» · «Quedan 3» · «Agotado · vuelve el jueves 30 de octubre».
	 */
	public static function availability_text( $text, $product ) {
		if ( ! $product instanceof WC_Product || ( is_admin() && ! wp_doing_ajax() ) ) { return $text; }
		if ( self::is_bajo_pedido( $product ) ) { return '📦 ' . self::BAJO_PEDIDO; }
		if ( ! $product->is_in_stock() ) {
			$back = self::back_label( $product );
			return '' !== $back ? 'Agotado · ' . lcfirst( $back ) : $text;
		}
		return class_exists( 'CVD_Product_Search' ) ? CVD_Product_Search::availability( $product, true ) : $text;
	}

	/** La línea de existencia de la ficha (y del color elegido) con el texto de availability_text(). */
	public static function stock_html( $html, $product ) {
		if ( ! $product instanceof WC_Product || ( is_admin() && ! wp_doing_ajax() ) ) { return $html; }
		$text = (string) self::availability_text( '', $product );
		if ( '' === $text ) { return $html; }
		$class = ! $product->is_in_stock() ? 'out-of-stock' : ( self::is_bajo_pedido( $product ) ? 'in-stock cvd-stock-pedido' : 'in-stock' );
		return '<p class="stock ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</p>';
	}

	/** Recuadro en la ficha, debajo del precio: bajo pedido, o agotado con su fecha de vuelta. */
	public static function single_notice(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) { return; }
		if ( self::is_bajo_pedido( $product ) ) {
			echo '<div class="cvd-dam-note cvd-dam-note--pedido" role="note"><strong>📦 ' . esc_html( self::BAJO_PEDIDO ) . '</strong>'
				. '<span>No está en la tienda ahora: lo pedimos al proveedor cuando lo reservas. Te confirmamos en 1 hora, antes de cobrar.</span></div>';
			return;
		}
		$back = self::back_label( $product );
		if ( '' !== $back ) {
			echo '<div class="cvd-dam-note cvd-dam-note--vuelve" role="note"><strong>' . esc_html( 'Agotado · ' . lcfirst( $back ) ) . '</strong>'
				. '<span>Puedes reservarlo con tu gestora para cuando llegue.</span></div>';
		}
	}

	/** Carrito y pago: debajo del nombre, «Disponibilidad: 📦 Bajo pedido · llega en 24 h». */
	public static function cart_item_data( array $data, array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		if ( $product instanceof WC_Product && self::is_bajo_pedido( $product ) ) {
			$data[] = array( 'key' => 'Disponibilidad', 'value' => '📦 ' . self::BAJO_PEDIDO, 'display' => '📦 ' . self::BAJO_PEDIDO );
		}
		return $data;
	}

	/** WordPress → producto → Inventario: «Vuelve el día» (vacío = no se sabe; no se enseña nada). */
	public static function admin_back_field(): void {
		global $product_object;
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) { return; }
		woocommerce_wp_text_input( array(
			'id'                => self::META_BACK,
			'label'             => 'Vuelve el día',
			'type'              => 'date',
			'value'             => $product_object instanceof WC_Product ? (string) $product_object->get_meta( self::META_BACK, true ) : '',
			'desc_tip'          => true,
			'description'       => 'Si está agotado y sabes cuándo llega, la tienda y la ficha dicen «Agotado · vuelve el …». Vacío = no se enseña nada. Pasada la fecha deja de salir.',
			'wrapper_class'     => 'show_if_simple show_if_variable',
		) );
	}

	public static function admin_save_back( WC_Product $product ): void {
		if ( ! isset( $_POST[ self::META_BACK ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce ya comprobó el nonce al guardar el producto.
		$date = sanitize_text_field( wp_unslash( $_POST[ self::META_BACK ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $product->update_meta_data( self::META_BACK, $date ); }
		else { $product->delete_meta_data( self::META_BACK ); }
	}

	/** Página «Tarifas de mensajería»: debajo del buscador, la tabla completa por municipio. */
	public static function rates_table_in_page( $content ) {
		if ( ! is_page( self::RATES_PAGE ) || ! in_the_loop() || ! is_main_query() || ! class_exists( 'CVD_Shipping_Rates' ) ) { return $content; }
		return $content . self::rates_table_html();
	}

	/** Tarifas oficiales activas agrupadas: municipio => [ [reparto, CUP], … ] + la referencia del municipio (reparto vacío). */
	public static function rates_by_municipality(): array {
		$out = array();
		foreach ( CVD_Shipping_Rates::rates() as $row ) {
			if ( empty( $row['active'] ) ) { continue; }
			$municipality = trim( (string) ( $row['municipality'] ?? '' ) );
			if ( '' === $municipality ) { continue; }
			$out[ $municipality ] = $out[ $municipality ] ?? array( 'zones' => array(), 'reference' => 0 );
			$zone = trim( (string) ( $row['zone'] ?? '' ) );
			if ( '' === $zone ) { $out[ $municipality ]['reference'] = absint( $row['fee'] ?? 0 ); continue; }
			$out[ $municipality ]['zones'][ $zone ] = absint( $row['fee'] ?? 0 );
		}
		$out = array_filter( $out, static fn( array $m ): bool => (bool) $m['zones'] );
		uksort( $out, static fn( string $a, string $b ): int => strcmp( remove_accents( $a ), remove_accents( $b ) ) );
		foreach ( $out as &$m ) { uksort( $m['zones'], static fn( string $a, string $b ): int => strcmp( remove_accents( $a ), remove_accents( $b ) ) ); }
		unset( $m );
		return $out;
	}

	private static function cup( int $fee ): string {
		return number_format( $fee, 0, ',', '.' ) . ' CUP';
	}

	public static function rates_table_html(): string {
		$rates = self::rates_by_municipality();
		if ( ! $rates ) { return ''; }
		$html = '<section class="cvd-rates" aria-labelledby="cvd-rates-title"><h2 id="cvd-rates-title">Todos los precios por zona</h2>'
			. '<p class="cvd-rates__intro">Precio de la mensajería desde la tienda hasta tu casa, en CUP. Se le paga aparte al mensajero, al recibir. Toca tu municipio para ver los repartos.</p>'
			. '<label class="cvd-rates__search"><span>Busca tu reparto</span><input type="search" data-cvd-rates-q placeholder="Ej.: Vedado, Alamar, Santos Suárez" autocomplete="off"></label>';
		foreach ( $rates as $municipality => $m ) {
			$fees = array_values( $m['zones'] );
			$range = min( $fees ) === max( $fees ) ? self::cup( min( $fees ) ) : number_format( min( $fees ), 0, ',', '.' ) . '–' . self::cup( max( $fees ) );
			$html .= '<details class="cvd-rates__mun" data-cvd-rates-mun><summary><span>' . esc_html( $municipality ) . '</span><small>' . esc_html( $range ) . '</small></summary><table><tbody>';
			foreach ( $m['zones'] as $zone => $fee ) {
				$html .= '<tr data-cvd-rates-zone="' . esc_attr( remove_accents( mb_strtolower( $zone . ' ' . $municipality ) ) ) . '"><th scope="row">' . esc_html( $zone ) . '</th><td>' . esc_html( self::cup( $fee ) ) . '</td></tr>';
			}
			if ( $m['reference'] > 0 ) {
				$html .= '<tr class="cvd-rates__other"><th scope="row">Otro reparto de ' . esc_html( $municipality ) . '</th><td>Se confirma (unos ' . esc_html( self::cup( $m['reference'] ) ) . ')</td></tr>';
			}
			$html .= '</tbody></table></details>';
		}
		$html .= '<p class="cvd-rates__none" data-cvd-rates-none hidden>No encontramos ese reparto. Escríbenos y te decimos el precio.</p></section>';
		// Buscador: abre los municipios que tienen un reparto que coincide y esconde el resto.
		$html .= '<script>(function(){var q=document.querySelector("[data-cvd-rates-q]");if(!q)return;var muns=[].slice.call(document.querySelectorAll("[data-cvd-rates-mun]")),none=document.querySelector("[data-cvd-rates-none]");'
			. 'function n(s){return (s||"").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g,"").trim();}'
			. 'q.addEventListener("input",function(){var t=n(q.value),hits=0;muns.forEach(function(d){var rows=[].slice.call(d.querySelectorAll("[data-cvd-rates-zone]")),any=false;rows.forEach(function(r){var ok=!t||r.getAttribute("data-cvd-rates-zone").indexOf(t)>-1;r.hidden=!ok;if(ok)any=true;});d.hidden=!any;d.open=!!t&&any;if(any)hits++;});none.hidden=!t||hits>0;});})();</script>';
		return $html;
	}

	public static function styles(): void {
		if ( is_admin() ) { return; }
		$css = '';
		if ( function_exists( 'is_product' ) && is_product() ) {
			$css .= '.cvd-dam-note{display:flex;flex-direction:column;gap:3px;margin:10px 0 14px;padding:12px 14px;border-radius:14px;font:400 14px/1.4 "Figtree",system-ui,sans-serif}'
				. '.cvd-dam-note strong{font-size:16px;font-weight:700}'
				. '.cvd-dam-note--pedido{background:#FBF1E4;border:1px solid #E8D9BF;color:#5E3A14}.cvd-dam-note--pedido strong{color:#8A4B19}'
				. '.cvd-dam-note--vuelve{background:#EEF2F4;border:1px solid #C9D6DC;color:#24414A}.cvd-dam-note--vuelve strong{color:#0D3B45}';
		}
		if ( is_page( self::RATES_PAGE ) ) {
			$css .= '.cvd-rates{max-width:640px;margin:28px auto 0;font:400 15px/1.45 "Figtree",system-ui,sans-serif;color:#2B2420}'
				. '.cvd-rates h2{margin:0 0 6px;font:600 20px/1.25 "Cinzel",Georgia,serif;letter-spacing:.04em}'
				. '.cvd-rates__intro{margin:0 0 12px;color:#5E544D}'
				. '.cvd-rates__search{display:flex;flex-direction:column;gap:4px;margin:0 0 12px;font-weight:600}'
				. '.cvd-rates__search input{min-height:44px;padding:8px 12px;border:1px solid #E0D5C1;border-radius:12px;font-size:16px;background:#FFFDF8}'
				. '.cvd-rates__mun{margin:0 0 8px;border:1px solid #E0D5C1;border-radius:14px;background:#FFFDF8;overflow:hidden}'
				. '.cvd-rates__mun summary{display:flex;justify-content:space-between;gap:12px;align-items:center;min-height:48px;padding:10px 14px;cursor:pointer;font-weight:600;list-style:none}'
				. '.cvd-rates__mun summary::-webkit-details-marker{display:none}.cvd-rates__mun summary small{color:#8A4B19;font-weight:600;white-space:nowrap}'
				. '.cvd-rates__mun[open] summary{background:#F6F1E6;border-bottom:1px solid #E0D5C1}'
				. '.cvd-rates table{width:100%;margin:0;border-collapse:collapse}.cvd-rates th,.cvd-rates td{padding:9px 14px;border-top:1px solid #F0E8DA;text-align:left;font-weight:400}'
				. '.cvd-rates tr:first-child th,.cvd-rates tr:first-child td{border-top:0}.cvd-rates td{text-align:right;white-space:nowrap;font-weight:600}'
				. '.cvd-rates__other th,.cvd-rates__other td{color:#5E544D;font-style:italic;font-weight:400}'
				. '.cvd-rates__none{padding:12px 14px;border-radius:12px;background:#F6F1E6}';
		}
		if ( '' !== $css ) { echo '<style id="cvd-datos-a-mano">' . $css . '</style>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS fijo.
	}
}
