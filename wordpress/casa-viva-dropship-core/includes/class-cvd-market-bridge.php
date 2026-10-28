<?php

defined( 'ABSPATH' ) || exit;

/**
 * Mercado "bajo pedido" (decisión de Ernesto, 2026-10-09).
 *
 * VivaBot publica aquí los artículos de proveedores de los grupos que Ernesto aprobó:
 * SKU `MK-<id>`, categoría «Bajo pedido», sin control de stock (se confirma con el
 * proveedor antes de cobrar) y comisión fija = 40 % de la ganancia (D43/D45/D85; antes «la mitad»).
 * El proveedor nunca se guarda aquí: solo el id del artículo en VivaBot.
 * Autenticación: la misma cabecera X-Vivabot-Key que el resto del puente.
 *
 * 3.14.71 (D121, 28-oct): segunda puerta para el panel de Lennys (/panel/market/*, clave X-Nexo-Panel-Key de
 * CVD_Panel_Bridge; la función nexo-panel-web ya comprobó que es la dueña). Publica con la MISMA lógica (publish_item),
 * pero aquí se calculan el precio sugerido (regla del bot, market-price.js), la comisión fija del 40 % y el mínimo
 * (costo + comisión + 2,5 %, D59): un precio por debajo del mínimo se rechaza. Queda anotado quién lo hizo y a Ernesto
 * le llega un correo. A las gestoras no se les avisa (entra solo en la lista «Actualización productos 24h», D101).
 */
final class CVD_Market_Bridge {
	private const NS = 'casa-viva/v1';
	public const SKU_PREFIX = 'MK-';
	public const CATEGORY = 'Bajo pedido';
	public const NOTICE = 'Bajo pedido · llega en 24 h. No está en la tienda: lo pedimos al proveedor y te confirmamos en 1 hora antes de cobrar.'; // 3.14.63 (maratón 24-oct)
	/** 3.13.72: en la Tienda, primero lo que hay en la tienda (menu_order 0) y después lo bajo pedido. */
	public const MENU_ORDER = 10;

	/**
	 * 3.14.71 (D121): precio sugerido = copia fiel de VivaBot src/market-price.js (myPrice, regla D43/D45):
	 * menos de 60 USD → +40 % (mínimo 5, máximo 17), al dólar hacia arriba; desde 60 → +17 USD (más de 300 → +10 %
	 * si sale más), hacia arriba a múltiplos de 5. La gestora gana el 40 % de la ganancia (gestoraShare).
	 * Si se cambia aquí, cambiarlo también en el bot y en el panel (apps/business-dashboard, mkSuggested).
	 */
	public const MIN_MARGIN_USD = 17;
	public const EXPENSIVE_USD = 300;
	public const EXPENSIVE_PCT = 0.10;
	public const ROUND_TO = 5;
	public const CHEAP_USD = 60;
	public const CHEAP_PCT = 0.4;
	public const CHEAP_MIN_USD = 5;
	public const GESTORA_SHARE = 0.4;

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
		// 3.14.71 (D121): el panel de Lennys, con su propia clave (solo la dueña llega aquí, lo comprueba nexo-panel-web).
		$panel = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( self::NS, '/panel/market/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'panel_preview' ), 'permission_callback' => $panel ) );
		register_rest_route( self::NS, '/panel/market/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'panel_publish' ), 'permission_callback' => $panel ) );
		register_rest_route( self::NS, '/panel/market/(?P<item>\d+)/unpublish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'panel_unpublish' ), 'permission_callback' => $panel ) );
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
	private static function asked_departments( WP_REST_Request $request ): array {
		$asked = array();
		foreach ( array( 'department', 'departments', 'category', 'supplier_category' ) as $param ) {
			foreach ( (array) $request->get_param( $param ) as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { $asked[] = sanitize_text_field( (string) $value ); }
			}
		}
		return $asked;
	}

	/** 3.14.71: $asked = lo que pidió el bot o el panel (asked_departments), ya limpio. */
	private static function departments_for( WC_Product $product, array $asked, string $title, int $market_cat ): array {
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

	/** Puerta del bot (/bot/market/publish): igual que siempre, ahora por publish_item (3.14.71). */
	public static function publish( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::publish_item( array(
			'item_id' => $request->get_param( 'item_id' ), 'title' => $request->get_param( 'title' ), 'price' => $request->get_param( 'price' ),
			'commission' => $request->get_param( 'commission' ), 'version' => $request->get_param( 'version' ),
			'description' => $request->get_param( 'description' ), 'images' => $request->get_param( 'images' ),
			'replace_images' => $request->get_param( 'replace_images' ), 'cost' => $request->get_param( 'cost' ),
			'departments' => self::asked_departments( $request ), 'by' => 'VivaBot',
		) ) );
	}

	/** [ código HTTP, cuerpo ] → WP_REST_Response. */
	private static function respond( array $result ): WP_REST_Response {
		return new WP_REST_Response( $result[1], $result[0] );
	}

	/**
	 * Lo común de publicar (bot y panel, 3.14.71). $d: item_id, title, price, commission, version, description, images,
	 * replace_images, cost, departments (lo pedido, ya limpio), by (quién: «VivaBot» o la persona del panel).
	 * Devuelve [ código HTTP, cuerpo ] con el mismo cuerpo que daba antes la ruta del bot.
	 */
	private static function publish_item( array $d ): array {
		$item = absint( $d['item_id'] ?? 0 );
		$title = sanitize_text_field( (string) ( $d['title'] ?? '' ) );
		$price = (float) ( $d['price'] ?? 0 );
		$commission = (float) ( $d['commission'] ?? 0 );
		$version = absint( $d['version'] ?? 0 );
		if ( ! $item || '' === $title || $price <= 0 || ! $version ) {
			return array( 400, array( 'ok' => false, 'error' => 'Faltan item_id, title, price o version.' ) );
		}
		// La comisión de la gestora (40 % de la ganancia) debe ser positiva: un 0 explícito anularía la tarifa.
		if ( $commission <= 0 || $commission >= $price ) {
			return array( 400, array( 'ok' => false, 'error' => 'La comisión debe ser mayor que 0 y menor que el precio.' ) );
		}
		$description = sanitize_textarea_field( (string) ( $d['description'] ?? '' ) );
		$images = array_values( array_filter( array_map( 'esc_url_raw', (array) ( $d['images'] ?? array() ) ), array( __CLASS__, 'allowed_image' ) ) );

		$product = self::find( $item ) ?: new WC_Product_Simple();
		$is_new = ! $product->get_id();
		// Idempotente: un reintento viejo nunca pisa una versión más nueva (la versión la pone VivaBot o el panel).
		if ( $stale = self::stale_data( $product, $version ) ) { return array( 200, $stale ); }
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
		// (o el panel) mande otro (department, departments o category). Antes se le dejaba solo «Bajo pedido» cada vez.
		$cat = self::category_id();
		$ids = array_merge( $cat ? array( $cat ) : array(), self::departments_for( $product, (array) ( $d['departments'] ?? array() ), $title, $cat ) );
		if ( $ids ) { $product->set_category_ids( array_values( array_unique( array_map( 'intval', $ids ) ) ) ); }
		$product->set_menu_order( self::MENU_ORDER );
		$product->update_meta_data( '_cvd_market_item_id', $item );
		$product->update_meta_data( '_cvd_market_version', $version );
		$product->update_meta_data( '_cvd_commission_type', 'fixed' );
		$product->update_meta_data( '_cvd_commission_value', wc_format_decimal( $commission, 2 ) );
		// 3.14.71 (D121): quién lo publicó por última vez (VivaBot o la persona del panel) y cuándo.
		self::stamp( $product, (string) ( $d['by'] ?? '' ), 'publish' );
		// 3.14.0: costo = precio del proveedor (supplier_price), si VivaBot lo manda. Para el precio mínimo y el programa de gestoras.
		$cost = $d['cost'] ?? null;
		if ( is_numeric( $cost ) && (float) $cost > 0 && class_exists( 'CVD_Costs' ) ) { CVD_Costs::set_cost( $product, (float) $cost, 'proveedor' ); }
		$product->delete_meta_data( '_cvd_sync_hidden_from' );
		$product_id = $product->save();

		// Fotos: solo la primera vez o si VivaBot manda otras nuevas.
		if ( $images && ( $is_new || ! empty( $d['replace_images'] ) ) ) {
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

		return array( 200, array( 'ok' => true, 'product_id' => $product_id, 'url' => get_permalink( $product_id ), 'new' => $is_new ) );
	}

	/** Respuesta "ya aplicado" si llega una versión igual o anterior a la guardada. */
	private static function stale( WC_Product $product, int $version ): ?WP_REST_Response {
		$data = self::stale_data( $product, $version );
		return $data ? new WP_REST_Response( $data, 200 ) : null;
	}

	private static function stale_data( WC_Product $product, int $version ): ?array {
		$current = $product->get_id() ? absint( $product->get_meta( '_cvd_market_version', true ) ) : 0;
		if ( $current && $version <= $current ) {
			return array( 'ok' => true, 'stale' => true, 'product_id' => $product->get_id(), 'url' => get_permalink( $product->get_id() ), 'status' => $product->get_status() );
		}
		return null;
	}

	/** Puerta del bot (/bot/market/<id>/unpublish): igual que siempre, ahora por unpublish_item (3.14.71). */
	public static function unpublish( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::unpublish_item( absint( $request['item'] ), absint( $request->get_param( 'version' ) ), 'VivaBot' ) );
	}

	/** Lo común de retirar (bot y panel): el producto pasa a privado (no se borra). [ código HTTP, cuerpo ]. */
	private static function unpublish_item( int $item, int $version, string $by ): array {
		if ( ! $version ) { return array( 400, array( 'ok' => false, 'error' => 'Falta version.' ) ); }
		$product = self::find( $item );
		if ( ! $product ) { return array( 200, array( 'ok' => true, 'found' => false ) ); }
		if ( $stale = self::stale_data( $product, $version ) ) { return array( 200, $stale ); }
		$product->update_meta_data( '_cvd_market_version', $version );
		self::stamp( $product, $by, 'unpublish' );
		$product->set_status( 'private' );
		$product->save();
		return array( 200, array( 'ok' => true, 'found' => true, 'product_id' => $product->get_id() ) );
	}

	/** Anota quién hizo el último cambio (sin guardar: guarda quien llama). Solo para administración. */
	private static function stamp( WC_Product $product, string $by, string $what ): void {
		$by = mb_substr( sanitize_text_field( $by ), 0, 120 );
		if ( '' === $by ) { return; }
		$product->update_meta_data( '_cvd_market_by', $by );
		$product->update_meta_data( '_cvd_market_by_at', gmdate( 'c' ) );
		$product->update_meta_data( '_cvd_market_by_what', $what );
	}

	// ---------- 3.14.71 (D121): precio sugerido, comisión y mínimo (los mismos números que ve Lennys en el panel) ----------

	/** Copia fiel de myPrice() de VivaBot (src/market-price.js), solo para USD. */
	public static function suggested_price( float $cost ): float {
		if ( $cost < self::CHEAP_USD ) {
			return (float) ceil( $cost + min( self::MIN_MARGIN_USD, max( self::CHEAP_MIN_USD, $cost * self::CHEAP_PCT ) ) );
		}
		$margin = max( self::MIN_MARGIN_USD, $cost > self::EXPENSIVE_USD ? $cost * self::EXPENSIVE_PCT : 0 );
		return (float) ( ceil( ( $cost + $margin ) / self::ROUND_TO ) * self::ROUND_TO );
	}

	/** Copia fiel de gestoraShare(): 40 % de la ganancia, a centavos (como Math.round de JS). */
	public static function gestora_share( float $price, float $cost ): float {
		return max( 0.0, floor( ( $price - $cost ) * self::GESTORA_SHARE * 100 + 0.5 ) / 100 );
	}

	/**
	 * Sugerido, comisión y mínimo para un costo de proveedor. El mínimo (D59, bajo pedido) = costo + comisión +
	 * máx(2,5 % del precio, 1 USD), con la comisión del precio sugerido: 50 → sugerido 67, comisión 6,80, mínimo 58,26.
	 * Siempre con la regla completa de bajo pedido (aunque el aviso de la web esté en modo «pérdida»): Ernesto, D121.
	 */
	public static function quote( float $cost ): array {
		$suggested = self::suggested_price( $cost );
		$commission = self::gestora_share( $suggested, $cost );
		$rules = class_exists( 'CVD_Costs' ) ? CVD_Costs::rules( true, null, true ) : array( 'fixed' => 0.0, 'pct' => 2.5, 'min' => 1.0 );
		$fixed = array( 'type' => 'fixed', 'value' => $commission );
		$floor = class_exists( 'CVD_Costs' ) ? CVD_Costs::floor_for( $cost, $fixed, $rules )
			: ceil( max( $cost + $commission + $rules['min'], ( $cost + $commission ) / ( 1 - $rules['pct'] / 100 ) ) * 100 - 1e-6 ) / 100;
		return array( 'cost' => round( $cost, 2 ), 'suggested' => $suggested, 'commission' => $commission, 'nexo' => round( $suggested - $cost - $commission, 2 ),
			'floor' => (float) $floor, 'share' => self::GESTORA_SHARE, 'pct' => (float) $rules['pct'], 'minUsd' => (float) $rules['min'] );
	}

	/** Quita enlaces wa.me, teléfonos y @usuarios (la regla de noContacts() del bot; wa.me primero para no dejar «wa.me/»). */
	public static function strip_contacts( string $text ): string {
		$text = (string) preg_replace( '/wa\.me\/\S+/iu', '', $text );
		$text = (string) preg_replace( '/(\+?53)?[\s-]*\d[\d\s-]{6,}\d/u', '', $text );
		return trim( (string) preg_replace( '/@\w+/u', '', $text ) );
	}

	/** Departamentos que puede elegir la dueña (los de arriba de la tienda, sin «Bajo pedido» ni «Sin categorizar»). */
	private static function department_choices(): array {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) );
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( in_array( $t->slug, array( 'bajo-pedido', 'sin-categorizar', 'uncategorized', 'pruebas' ), true ) ) { continue; }
			$out[] = array( 'slug' => $t->slug, 'name' => html_entity_decode( $t->name, ENT_QUOTES ) );
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/** Departamento adivinado: por el título y, si no, por la categoría del proveedor (solo departamentos que existen). */
	private static function guess_for( string $title, string $supplier_category ): array {
		$slugs = self::guess_departments( $title );
		if ( ! $slugs ) {
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $supplier_category ) ) : strtolower( trim( $supplier_category ) );
			if ( isset( self::SUPPLIER_DEPARTMENTS[ $key ] ) ) { $slugs = array( self::SUPPLIER_DEPARTMENTS[ $key ] ); }
		}
		return array_values( array_filter( $slugs, static fn( $s ) => self::term_id( $s ) > 0 ) );
	}

	/** «Lennys (correo o teléfono)»: lo que manda nexo-panel-web en by / byName. */
	private static function by_of( array $p ): string {
		$name = trim( sanitize_text_field( (string) ( $p['byName'] ?? '' ) ) );
		$by = trim( sanitize_text_field( (string) ( $p['by'] ?? '' ) ) );
		$who = '' !== $name ? $name . ( '' !== $by && $by !== $name ? ' (' . $by . ')' : '' ) : ( '' !== $by ? $by : 'panel' );
		return mb_substr( $who, 0, 120 );
	}

	private static function no_cache( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * POST /panel/market/preview { items: [ { id, title, cost, category? } ] } (máx. 100) → por artículo: sugerido,
	 * comisión, mínimo, departamento adivinado y si ya está en la web (estado, precio, enlace, departamentos).
	 * Más la lista de departamentos para elegir. Solo lectura.
	 */
	public static function panel_preview( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		$out = array();
		foreach ( array_slice( (array) ( $p['items'] ?? array() ), 0, 100 ) as $row ) {
			$row = (array) $row;
			$id = absint( $row['id'] ?? 0 );
			if ( ! $id ) { continue; }
			$cost = (float) ( $row['cost'] ?? 0 );
			$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
			$info = $cost > 0 ? self::quote( $cost ) : array( 'cost' => null, 'suggested' => null, 'commission' => null, 'floor' => null );
			$info['guess'] = self::guess_for( $title, sanitize_text_field( (string) ( $row['category'] ?? '' ) ) );
			$info['web'] = null;
			$product = self::find( $id );
			if ( $product ) {
				$cats = array();
				foreach ( $product->get_category_ids() as $cid ) {
					$t = get_term( (int) $cid, 'product_cat' );
					if ( $t && ! is_wp_error( $t ) && 'bajo-pedido' !== $t->slug ) { $cats[] = $t->slug; }
				}
				$info['web'] = array(
					'productId' => $product->get_id(), 'status' => $product->get_status(), 'price' => (float) $product->get_regular_price( 'edit' ),
					'url' => get_permalink( $product->get_id() ), 'departments' => $cats,
					'by' => (string) $product->get_meta( '_cvd_market_by', true ), 'byAt' => (string) $product->get_meta( '_cvd_market_by_at', true ),
				);
			}
			$out[ (string) $id ] = $info;
		}
		$rules = class_exists( 'CVD_Costs' ) ? CVD_Costs::rules( true, null, true ) : array( 'pct' => 2.5, 'min' => 1.0 );
		return self::no_cache( array(
			'items' => (object) $out, 'departments' => self::department_choices(), 'share' => self::GESTORA_SHARE,
			'pct' => (float) $rules['pct'], 'minUsd' => (float) $rules['min'],
		) );
	}

	/**
	 * POST /panel/market/publish { item_id, title, description, cost, price, department, images, version, by, byName }.
	 * La comisión NO llega del panel: aquí es siempre el 40 % de la ganancia. Un precio por debajo del mínimo se rechaza.
	 */
	public static function panel_publish( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		$item = absint( $p['item_id'] ?? 0 );
		$cost = round( (float) ( $p['cost'] ?? 0 ), 2 );
		$price = round( (float) ( $p['price'] ?? 0 ), 2 );
		if ( ! $item || $cost <= 0 || $price <= 0 ) {
			return self::no_cache( array( 'ok' => false, 'error' => 'Falta el artículo, el precio del proveedor o el precio de venta.' ), 400 );
		}
		$q = self::quote( $cost );
		if ( $price + 0.005 < $q['floor'] ) {
			return self::no_cache( array( 'ok' => false, 'error' => 'Ese precio está por debajo del mínimo (' . number_format( $q['floor'], 2, ',', '.' ) . ' USD). Súbelo para publicar.', 'floor' => $q['floor'] ), 422 );
		}
		$commission = self::gestora_share( $price, $cost );
		$by = self::by_of( $p );
		$title = self::strip_contacts( sanitize_text_field( (string) ( $p['title'] ?? '' ) ) );
		$department = sanitize_title( (string) ( $p['department'] ?? '' ) );
		[ $code, $body ] = self::publish_item( array(
			'item_id' => $item, 'title' => $title, 'price' => $price, 'commission' => $commission, 'version' => $p['version'] ?? 0,
			'description' => self::strip_contacts( (string) ( $p['description'] ?? '' ) ), 'images' => (array) ( $p['images'] ?? array() ),
			'replace_images' => ! empty( $p['replace_images'] ), 'cost' => $cost,
			'departments' => '' !== $department ? array( $department ) : array(), 'by' => $by,
		) );
		if ( 200 === $code && ! empty( $body['ok'] ) && empty( $body['stale'] ) ) {
			$fmt = static fn( float $v ): string => number_format( $v, 2, ',', '.' );
			$body['mail'] = self::mail( $by . ' publicó MK-' . $item . ' · ' . $title, implode( "\n", array(
				$by . ' publicó en la web, desde el panel, el producto bajo pedido MK-' . $item . '.',
				'Producto: ' . $title,
				'Precio de venta: ' . $fmt( $price ) . ' USD (sugerido ' . $fmt( $q['suggested'] ) . ', mínimo ' . $fmt( $q['floor'] ) . ')',
				'Precio del proveedor: ' . $fmt( $cost ) . ' USD',
				'Comisión de la gestora (40 %): ' . $fmt( $commission ) . ' USD · Ernesto/NEXO: ' . $fmt( $price - $cost - $commission ) . ' USD',
				'Departamento: ' . ( '' !== $department ? $department : 'el que ya tenía o el adivinado por el nombre' ),
				'Enlace: ' . ( $body['url'] ?? '' ),
				'',
				'Precio fijado a mano: el bot no lo cambia. A las gestoras no se les avisó (entra en la lista «Actualización productos 24h» si toca).',
			) ) );
		}
		return self::no_cache( $body + array( 'suggested' => $q['suggested'], 'commission' => $commission, 'floor' => $q['floor'] ), $code );
	}

	/** POST /panel/market/<id>/unpublish { version, by, byName, title? } → «Retirar (agotado)»: el producto pasa a privado. */
	public static function panel_unpublish( WP_REST_Request $request ): WP_REST_Response {
		$p = (array) $request->get_json_params();
		$item = absint( $request['item'] );
		$by = self::by_of( $p );
		[ $code, $body ] = self::unpublish_item( $item, absint( $p['version'] ?? 0 ), $by );
		if ( 200 === $code && ! empty( $body['found'] ) && empty( $body['stale'] ) ) {
			$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
			$what = 'MK-' . $item . ( '' !== $title ? ' · ' . $title : '' );
			$body['mail'] = self::mail( $by . ' retiró ' . $what, $by . ' retiró de la web, desde el panel, el producto bajo pedido ' . $what . ' (el proveedor ya no lo tiene). Queda oculto, no se borra.' );
		}
		return self::no_cache( $body, $code );
	}

	/** Correo a Ernesto con el mismo mecanismo que /bot/aviso-correo (D112). Si falla, la publicación sigue valiendo. */
	private static function mail( string $subject, string $body ): bool {
		if ( ! class_exists( 'CVD_Bot_Delivery_Change' ) || ! method_exists( 'CVD_Bot_Delivery_Change', 'mail_owner' ) ) { return false; }
		$r = CVD_Bot_Delivery_Change::mail_owner( $subject, $body, 'Panel de Casa Viva' );
		return ! empty( $r['sent'] );
	}
}
