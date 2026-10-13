<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mercado "bajo pedido" (decisión de Ernesto, 2026-10-09).
 *
 * VivaBot publica aquí los artículos de proveedores de los grupos que Ernesto aprobó:
 * SKU `MK-<id>`, categoría «Bajo pedido», sin control de stock (se confirma con el
 * proveedor antes de cobrar) y comisión fija = mitad de la ganancia de Ernesto.
 * El proveedor nunca se guarda aquí: solo el id del artículo en VivaBot.
 * Autenticación: la misma cabecera X-Vivabot-Key que el resto del puente.
 */
final class CVD_Market_Bridge {
	private const NS = 'casa-viva/v1';
	public const SKU_PREFIX = 'MK-';
	public const CATEGORY = 'Bajo pedido';
	public const NOTICE = 'Producto bajo pedido: confirmamos disponibilidad en 1 hora antes de cobrar.';
	/** 3.13.72: en la Tienda, primero lo que hay en la tienda (menu_order 0) y después lo bajo pedido. */
	public const MENU_ORDER = 10;

	/**
	 * 3.13.72: departamento por la categoría del proveedor (crm_market_items.category), el mismo reparto del 13-oct
	 * (docs/nexo-business/propuestas/bajo-pedido-departamentos-2026-10-13.csv). Solo categorías que ya existen.
	 */
	private const SUPPLIER_DEPARTMENTS = array(
		'accesorios para celulares y tablets' => 'tecnologia', 'accesorios para pc y laptops' => 'tecnologia', 'accesorios para vehículos' => 'movilidad',
		'accesorios y periféricos para gaming' => 'tecnologia', 'adaptadores de audio' => 'tecnologia', 'adaptadores de pc' => 'tecnologia',
		'adaptadores de video' => 'tecnologia', 'audífonos bluetooth' => 'tecnologia', 'audífonos de cable' => 'tecnologia', 'belleza' => 'cuidado-personal',
		'bocinas y accesorios' => 'tecnologia', 'cargadores portátiles y baterías' => 'apagones-y-energia', 'cargadores y cables' => 'tecnologia',
		'celulares y tablets' => 'tecnologia', 'creación de contenido' => 'tecnologia', 'deportivos' => 'movilidad', 'discos' => 'tecnologia',
		'electrodomésticos' => 'electrodomesticos', 'electrodomésticos (batidoras y licuadoras)' => 'electrodomesticos', 'electrodomésticos varios' => 'electrodomesticos',
		'electrodomésticos y hogar' => 'electrodomesticos', 'electrónica' => 'tecnologia', 'electrónica (general y otros artículos de interés)' => 'tecnologia',
		'entrenamiento y artículos deportivos' => 'accesorios', 'equipos de refrigeración profesionales' => 'electrodomesticos', 'estantes comerciales' => 'organizacion',
		'ferretería' => 'ferreteria', 'frigoríficos' => 'electrodomesticos', 'gaming' => 'tecnologia', 'generadores eléctricos' => 'apagones-y-energia',
		'herramientas' => 'ferreteria', 'hogar' => 'tecnologia', 'hub y regletas' => 'tecnologia', 'iluminación y luces' => 'apagones-y-energia',
		'impresoras y accesorios' => 'tecnologia', 'instrumentos musicales' => 'accesorios', 'insumos desechables' => 'cocina', 'juguetes y coleccionables' => 'accesorios',
		'laptop grado a' => 'tecnologia', 'laptops nuevas' => 'tecnologia', 'luminarias' => 'sala-y-muebles', 'memorias usb' => 'tecnologia',
		'mochilas, maletas y bolsos' => 'accesorios', 'móviles y accesorios' => 'tecnologia', 'muebles' => 'sala-y-muebles', 'muebles comprimidos' => 'sala-y-muebles',
		'nevera' => 'electrodomesticos', 'pc y periféricos' => 'tecnologia', 'protectores de voltaje' => 'apagones-y-energia', 'relojería' => 'accesorios',
		'seguridad' => 'tecnologia', 'smartwatch y accesorios' => 'tecnologia', 'split' => 'electrodomesticos', 'útiles del hogar' => 'accesorios',
		'vehículos' => 'movilidad', 'ventilador' => 'electrodomesticos',
	);

	/** 3.13.72: si el bot no manda nada, se adivina por el título (la primera regla que coincide). Slugs de product_cat. */
	private const TITLE_DEPARTMENTS = array(
		'/\bventilador(es)? recargable/iu' => array( 'electrodomesticos', 'apagones-y-energia' ),
		'/mochila|\bbolso|\bbolsa\b|ri[ñn]onera|maleta|\breloj(?! inteligente)/iu' => array( 'accesorios' ), // antes que «laptop» o «correa»
		'/\bfunda\b|bocina|altavoz|aud[ií]fono|auricular|soundbar|c[aá]mara|\bcelular|\btablet|laptop|\bimac\b|\bmonitor\b|\bmouse\b|consola|playstation|\bps[45]\b|xbox|nintendo|dualsense|smartwatch|reloj inteligente/iu' => array( 'tecnologia' ),
		'/teclado electr[oó]nico|viol[ií]n|guitarra|piano|instrumento musical/iu' => array( 'accesorios' ),
		'/u[ñn]as|masajeador|depiladora|rasuradora|creatina|prote[ií]na|suplemento|entrenamiento|vitamina|perfume|maquillaje|champ[uú]|\bcrema\b|aceite (corporal|capilar|facial)|\bfaja/iu' => array( 'cuidado-personal' ),
		'/pistola de calor|taladro|bomba de agua|herramienta|destornillador|mult[ií]metro|probador|v[aá]lvula|cerradura|tornillo/iu' => array( 'ferreteria' ),
		'/estaci[oó]n de energ[ií]a|generador|panel solar|inversor|power ?bank|bater[ií]a port[aá]til|protector de voltaje|linterna|farol|\bsolar\b|\bups\b/iu' => array( 'apagones-y-energia' ),
		'/cargador|\bcable|adaptador|\bhub\b|\busb\b|\bdisco\b|\bssd\b|memoria|micr[oó]fono|impresora|gabinete|\bfuente\b|tarjeta gr[aá]fica|router|enchufe|regleta|extensi[oó]n|extensor|teclado|selfie|tr[ií]pode|timbre|smart|bluetooth|localizador|\bcorrea\b|control (inal[aá]mbrico|xbox)|mandos?\b|caja digital/iu' => array( 'tecnologia' ),
		'/ventilador|nevera|refrigerador|frigor[ií]fico|congelador|\bsplit\b|aire acondicionado|licuadora|batidora|arrocera|\bolla\b|calentador|secadora|procesador de alimentos|microondas|lavadora|cafetera|freidora|plancha|refrigerada/iu' => array( 'electrodomesticos' ),
		'/l[aá]mpara|sof[aá]|butaca|\bmueble|\bsilla|escritorio|\bcuadro|[aá]lbum/iu' => array( 'sala-y-muebles' ),
		'/control remoto|\bjuguete|\bjuego de|figura|mu[ñn]eca|pistola de (agua|juguete)|militares|patines|mochila|\bbolso|\bbolsa\b|ri[ñn]onera|maleta|\breloj|gafas|espejuelos|paraguas|joyer|collar|aretes/iu' => array( 'accesorios' ),
		'/casco|bicicleta|\bmoto\b|\bauto\b|\bcarro\b|veh[ií]culo|triciclo/iu' => array( 'movilidad' ),
		'/\bvasos?\b|plato|cuchar|sart[eé]n|cuchillo|absorbente|desechable|keurig|\btaza|termo|cocina/iu' => array( 'cocina' ),
		'/estante|organizador|gancho|zapatera|perchero|colgador/iu' => array( 'organizacion' ),
		'/toalla|alfombra de ba[ñn]o|cortina de ba[ñn]o|inodoro/iu' => array( 'bano' ),
		'/s[aá]bana|edred[oó]n|colch[oó]n|almohada|sobrecama|\bmanta\b/iu' => array( 'habitacion' ),
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/market/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'publish' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/market/(?P<item>\d+)/unpublish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unpublish' ), 'permission_callback' => $auth ) );
	}

	public static function is_market_sku( string $sku ): bool {
		return 0 === strpos( $sku, self::SKU_PREFIX );
	}

	/**
	 * ¿Lleva algún artículo «bajo pedido»? (3.13.63, 12-oct) Ese pedido no sale solo al grupo de mensajeros:
	 * el producto no está en la tienda y primero hay que confirmarlo con el proveedor (VivaBot avisa a Ernesto).
	 */
	public static function order_has_market_items( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product instanceof WC_Product && self::is_market_sku( (string) $product->get_sku() ) ) { return true; }
		}
		return false;
	}

	private static function find( int $item ): ?WC_Product {
		$id = wc_get_product_id_by_sku( self::SKU_PREFIX . $item );
		$product = $id ? wc_get_product( $id ) : null;
		return $product instanceof WC_Product ? $product : null;
	}

	private static function category_id(): int {
		$term = get_term_by( 'name', self::CATEGORY, 'product_cat' );
		if ( $term ) { return (int) $term->term_id; }
		$new = wp_insert_term( self::CATEGORY, 'product_cat', array( 'slug' => 'bajo-pedido' ) );
		return is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}

	/**
	 * Departamentos (ids de product_cat, sin «Bajo pedido») para este artículo:
	 * 1) lo que mande el bot (department, departments o category: nombre o slug de un departamento que ya existe, o la
	 *    categoría del proveedor, también en supplier_category); 2) si no, los que ya tenía; 3) si no tenía, por el título.
	 * Nunca crea departamentos nuevos.
	 */
	private static function departments_for( WC_Product $product, WP_REST_Request $request, string $title, int $market_cat ): array {
		$asked = array();
		foreach ( array( 'department', 'departments', 'category', 'supplier_category' ) as $param ) {
			foreach ( (array) $request->get_param( $param ) as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { $asked[] = sanitize_text_field( (string) $value ); }
			}
		}
		$found = array();
		foreach ( $asked as $value ) {
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ) ) : strtolower( trim( $value ) );
			$id = self::term_id( self::SUPPLIER_DEPARTMENTS[ $key ] ?? sanitize_title( $value ) );
			if ( ! $id ) {
				$term = get_term_by( 'name', $value, 'product_cat' );
				$id = $term && 'bajo-pedido' !== $term->slug ? (int) $term->term_id : 0;
			}
			if ( $id && $id !== $market_cat ) { $found[] = $id; }
		}
		if ( $found ) { return $found; }
		$kept = $product->get_id() ? array_values( array_diff( array_map( 'intval', $product->get_category_ids() ), array( $market_cat ) ) ) : array();
		if ( $kept ) { return $kept; }
		return array_values( array_filter( array_map( array( __CLASS__, 'term_id' ), self::guess_departments( $title ) ) ) );
	}

	/** Slugs de departamento por el título (TITLE_DEPARTMENTS); vacío si ninguna regla coincide. */
	public static function guess_departments( string $title ): array {
		foreach ( self::TITLE_DEPARTMENTS as $pattern => $slugs ) {
			if ( preg_match( $pattern, $title ) ) { return $slugs; }
		}
		return array();
	}

	private static function term_id( string $slug ): int {
		$term = '' !== $slug ? get_term_by( 'slug', $slug, 'product_cat' ) : false;
		return $term && 'bajo-pedido' !== $term->slug ? (int) $term->term_id : 0;
	}

	/** Solo imágenes https públicas de Supabase Storage (las que firma VivaBot). */
	private static function allowed_image( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 0 === strpos( $url, 'https://' ) && (bool) preg_match( '/\.supabase\.co$/', $host );
	}

	private static function sideload( string $url, int $product_id, string $title ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) { return 0; }
		$file = array( 'name' => sanitize_file_name( 'mercado-' . $product_id . '-' . wp_generate_password( 6, false ) . '.jpg' ), 'tmp_name' => $tmp );
		$id = media_handle_sideload( $file, $product_id, $title );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
		return (int) $id;
	}

	public static function publish( WP_REST_Request $request ): WP_REST_Response {
		$item = absint( $request->get_param( 'item_id' ) );
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$price = (float) $request->get_param( 'price' );
		$commission = (float) $request->get_param( 'commission' );
		$version = absint( $request->get_param( 'version' ) );
		if ( ! $item || '' === $title || $price <= 0 || ! $version ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Faltan item_id, title, price o version.' ), 400 );
		}
		// La comisión del gestor (mitad de la ganancia) debe ser positiva: un 0 explícito anularía la tarifa.
		if ( $commission <= 0 || $commission >= $price ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'La comisión debe ser mayor que 0 y menor que el precio.' ), 400 );
		}
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		$images = array_values( array_filter( array_map( 'esc_url_raw', (array) $request->get_param( 'images' ) ), array( __CLASS__, 'allowed_image' ) ) );

		$product = self::find( $item ) ?: new WC_Product_Simple();
		$is_new = ! $product->get_id();
		// Idempotente: un reintento viejo nunca pisa una versión más nueva (la versión la pone VivaBot).
		if ( $stale = self::stale( $product, $version ) ) { return $stale; }
		$product->set_name( $title );
		$product->set_sku( self::SKU_PREFIX . $item );
		$product->set_regular_price( wc_format_decimal( $price, 2 ) );
		$product->set_sale_price( '' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_short_description( esc_html( self::NOTICE ) );
		$product->set_description( $description ? '<p>' . nl2br( esc_html( $description ) ) . '</p>' : '' );
		// 3.13.72: «Bajo pedido» MÁS su departamento. Al volver a publicar se conserva el que ya tenía, salvo que el bot
		// mande otro (department, departments o category). Antes se le dejaba solo «Bajo pedido» cada vez.
		$cat = self::category_id();
		$ids = array_merge( $cat ? array( $cat ) : array(), self::departments_for( $product, $request, $title, $cat ) );
		if ( $ids ) { $product->set_category_ids( array_values( array_unique( array_map( 'intval', $ids ) ) ) ); }
		$product->set_menu_order( self::MENU_ORDER );
		$product->update_meta_data( '_cvd_market_item_id', $item );
		$product->update_meta_data( '_cvd_market_version', $version );
		$product->update_meta_data( '_cvd_commission_type', 'fixed' );
		$product->update_meta_data( '_cvd_commission_value', wc_format_decimal( $commission, 2 ) );
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product_id = $product->save();

		// Fotos: solo la primera vez o si VivaBot manda otras nuevas.
		if ( $images && ( $is_new || $request->get_param( 'replace_images' ) ) ) {
			$ids = array();
			foreach ( array_slice( $images, 0, 4 ) as $url ) {
				$att = self::sideload( $url, $product_id, $title );
				if ( $att ) { $ids[] = $att; }
			}
			if ( $ids ) {
				$product->set_image_id( array_shift( $ids ) );
				$product->set_gallery_image_ids( $ids );
				$product->save();
			}
		}

		return new WP_REST_Response( array( 'ok' => true, 'product_id' => $product_id, 'url' => get_permalink( $product_id ), 'new' => $is_new ), 200 );
	}

	/** Respuesta "ya aplicado" si llega una versión igual o anterior a la guardada. */
	private static function stale( WC_Product $product, int $version ): ?WP_REST_Response {
		$current = $product->get_id() ? absint( $product->get_meta( '_cvd_market_version', true ) ) : 0;
		if ( $current && $version <= $current ) {
			return new WP_REST_Response( array( 'ok' => true, 'stale' => true, 'product_id' => $product->get_id(), 'url' => get_permalink( $product->get_id() ), 'status' => $product->get_status() ), 200 );
		}
		return null;
	}

	public static function unpublish( WP_REST_Request $request ): WP_REST_Response {
		$version = absint( $request->get_param( 'version' ) );
		if ( ! $version ) { return new WP_REST_Response( array( 'ok' => false, 'error' => 'Falta version.' ), 400 ); }
		$product = self::find( absint( $request['item'] ) );
		if ( ! $product ) { return new WP_REST_Response( array( 'ok' => true, 'found' => false ), 200 ); }
		if ( $stale = self::stale( $product, $version ) ) { return $stale; }
		$product->update_meta_data( '_cvd_market_version', $version );
		$product->set_status( 'private' );
		$product->save();
		return new WP_REST_Response( array( 'ok' => true, 'found' => true, 'product_id' => $product->get_id() ), 200 );
	}
}
