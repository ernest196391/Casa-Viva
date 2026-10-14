<?php

defined( 'ABSPATH' ) || exit;

/** Configurable CUP delivery prices. Product totals remain in the store currency. */
final class CVD_Shipping_Rates {
	private const OPTION = 'cvd_shipping_rates';
	private const VERSION = '2026-09-23-v3';
	private const DATA_VERSION_OPTION = 'cvd_shipping_rates_data_version';

	public static function register(): void {
		add_shortcode( 'casa_viva_shipping_quote', array( __CLASS__, 'render_quote' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'quote_assets' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'refresh_checkout_rate' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'checkout_rate_row' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_rate' ), 30, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'note_locality' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'wp_ajax_cvd_address_search', array( __CLASS__, 'address_search' ) );
		add_action( 'wp_ajax_nopriv_cvd_address_search', array( __CLASS__, 'address_search' ) );
		// 3.13.54: un mapa de fuera de Cuba (pedidos viejos con Madrid) no sale en ningún sitio; queda la dirección escrita.
		add_filter( 'woocommerce_order_get__cvd_map_url', array( __CLASS__, 'filter_order_map_url' ), 10, 1 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_item_totals' ), 20, 3 );
		add_filter( 'woocommerce_get_formatted_order_total', array( __CLASS__, 'formatted_order_total' ), 20, 2 );
	}

	public static function rest_routes(): void {
		register_rest_route( 'casa-viva/v1', '/shipping/quote', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_quote' ), 'permission_callback' => '__return_true' ) );
	}

	public static function rest_quote( WP_REST_Request $request ): WP_REST_Response {
		$municipality = sanitize_text_field( (string) $request->get_param( 'municipality' ) ); $zone = sanitize_text_field( (string) $request->get_param( 'zone' ) );
		$quote = self::quote( $municipality, $zone );
		$response = rest_ensure_response( array( 'origin' => 'Casa Viva', 'destination' => implode( ', ', array_filter( array( $zone, $municipality ) ) ), 'feeCup' => absint( $quote['fee'] ?? 0 ), 'status' => sanitize_key( (string) ( $quote['status'] ?? 'pending' ) ), 'label' => sanitize_text_field( (string) ( $quote['label'] ?? '' ) ), 'referenceCup' => absint( $quote['reference'] ?? 0 ), 'official' => 'zone' === ( $quote['status'] ?? '' ) ) );
		$response->header( 'Cache-Control', 'public, max-age=300' ); return $response;
	}

	public static function quote_assets(): void {
		if ( ! is_page( array( 'cotizar-mensajeria', 'tarifas-mensajeria' ) ) ) { return; }
		wp_enqueue_style( 'cvd-shipping-quote', CVD_URL . 'assets/shipping-quote.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-shipping-quote', CVD_URL . 'assets/shipping-quote.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-shipping-quote', 'cvdShippingQuote', array( 'endpoint' => rest_url( 'casa-viva/v1/shipping/quote' ), 'localities' => self::localities() ) );
	}

	public static function render_quote(): string {
		ob_start(); ?>
		<main class="cvd-quote-app" data-cvd-quote>
			<section class="cvd-quote-card" aria-label="Consultar tarifa">
				<label>Municipio<select data-quote-municipality><option value="">Selecciona municipio</option><?php foreach ( array_keys( self::localities() ) as $municipality ) : ?><option value="<?php echo esc_attr( $municipality ); ?>"><?php echo esc_html( $municipality ); ?></option><?php endforeach; ?></select></label>
				<label>Zona o reparto<select data-quote-zone disabled><option value="">Selecciona primero el municipio</option></select></label>
				<button class="cvd-primary" data-quote-submit type="button">Ver tarifa</button>
				<p class="cvd-quote-status" role="status" aria-live="polite"></p>
			</section>
			<section class="cvd-quote-card cvd-quote-result" data-quote-result hidden>
				<p class="cvd-quote-kind" data-quote-kind></p><strong data-quote-price></strong><p data-quote-route></p>
				<div class="cvd-quote-actions"><button type="button" data-quote-copy>Copiar</button><button type="button" data-quote-share>Compartir</button></div>
			</section>
		</main>
		<?php return (string) ob_get_clean();
	}

	/*
	 * Mapas solo dentro de Cuba (3.13.54). Pasó en la prueba grabada del 11-oct: escribiendo «Calle 23»
	 * con Plaza de la Revolución / El Vedado salieron calles de Madrid, Ciudad de México, Bogotá y Tordesillas;
	 * al tocar una se guardó la ubicación de Madrid y el vale del mensajero abrió el mapa en Madrid.
	 * Cajas en grados: oeste, sur, este, norte (lon, lat, lon, lat), el mismo orden que pide Photon en «bbox».
	 */
	private const CUBA_BOX = array( -85.3, 19.5, -73.8, 23.5 );
	private const PROVINCE_BOXES = array(
		'LH' => array( -82.62, 22.90, -82.00, 23.25 ), // La Habana (de Santa Fe a Guanabo, del litoral a Boyeros)
	);

	/** Caja de búsqueda de la provincia (La Habana por defecto); si no la tenemos, toda Cuba. */
	public static function search_box( string $state ): array {
		$state = strtoupper( sanitize_key( $state ) );
		return self::PROVINCE_BOXES[ $state ?: 'LH' ] ?? self::CUBA_BOX;
	}

	public static function in_box( float $lat, float $lon, array $box ): bool {
		return $lon >= $box[0] && $lat >= $box[1] && $lon <= $box[2] && $lat <= $box[3];
	}

	public static function in_cuba( float $lat, float $lon ): bool {
		return self::in_box( $lat, $lon, self::CUBA_BOX );
	}

	/**
	 * Pares [lat, lon] que se leen en un enlace de mapa: OpenStreetMap (mlat/mlon, #map=18/lat/lon),
	 * Google Maps (?q=lat,lon · query= · ll= · /@lat,lon) y geo:lat,lon. Vacío si no trae números
	 * (por ejemplo un enlace corto maps.app.goo.gl): ese no se puede comprobar y se deja como está.
	 */
	public static function map_coordinates( string $url ): array {
		$u = rawurldecode( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		$n = '(-?\d{1,3}(?:\.\d+)?)';
		$pairs = array();
		if ( preg_match( '/[?&]mlat=' . $n . '/i', $u, $lat ) && preg_match( '/[?&]mlon=' . $n . '/i', $u, $lon ) ) { $pairs[] = array( (float) $lat[1], (float) $lon[1] ); }
		$patterns = array(
			'/#map=\d+(?:\.\d+)?\/' . $n . '\/' . $n . '/',
			'/[?&](?:q|query|ll|sll|center|daddr|destination)=(?:loc:)?\s*' . $n . '\s*,\s*' . $n . '/i',
			'/\/@' . $n . ',' . $n . '/',
			'/^geo:' . $n . ',' . $n . '/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $u, $all, PREG_SET_ORDER ) ) {
				foreach ( $all as $m ) { $pairs[] = array( (float) $m[1], (float) $m[2] ); }
			}
		}
		return $pairs;
	}

	/** El enlace de mapa tal cual, o '' si alguna de sus coordenadas cae fuera de Cuba (nunca se guarda ni se enseña). */
	public static function safe_map_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) { return ''; }
		foreach ( self::map_coordinates( $url ) as $pair ) {
			if ( ! self::in_cuba( $pair[0], $pair[1] ) ) { return ''; }
		}
		return $url;
	}

	/** Ubicación del pedido para vales, mensajeros, recibos y el bot: sin mapa si es de fuera de Cuba (queda la dirección escrita). */
	public static function order_map_url( $order ): string {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) ? self::safe_map_url( (string) $order->get_meta( '_cvd_map_url', true ) ) : '';
	}

	/** Mismo filtro para cualquier otro sitio que lea `_cvd_map_url` con get_meta() (pedidos viejos con Madrid incluidos). */
	public static function filter_order_map_url( $value ) {
		return is_string( $value ) ? self::safe_map_url( $value ) : $value;
	}

	/** Respuesta de Photon → sugerencias [label, map], solo de Cuba (countrycode CU) y dentro de la caja de la provincia. */
	public static function photon_items( array $body, array $box ): array {
		$items = array();
		foreach ( $body['features'] ?? array() as $feature ) {
			$p = is_array( $feature['properties'] ?? null ) ? $feature['properties'] : array();
			$coordinates = $feature['geometry']['coordinates'] ?? array();
			if ( ! is_array( $coordinates ) || count( $coordinates ) < 2 || ! is_numeric( $coordinates[0] ) || ! is_numeric( $coordinates[1] ) ) { continue; }
			$lon = (float) $coordinates[0]; $lat = (float) $coordinates[1];
			$country = strtoupper( (string) ( $p['countrycode'] ?? '' ) );
			if ( ( '' !== $country && 'CU' !== $country ) || ! self::in_cuba( $lat, $lon ) || ! self::in_box( $lat, $lon, $box ) ) { continue; }
			$parts = array_filter( array( $p['name'] ?? '', $p['street'] ?? '', $p['housenumber'] ?? '', $p['locality'] ?? '', $p['district'] ?? '', $p['city'] ?? '' ), 'is_string' );
			$label = implode( ', ', array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) ) );
			if ( ! $label ) { continue; }
			$lat = round( $lat, 6 ); $lon = round( $lon, 6 );
			$items[ $label ] = array( 'label' => $label, 'map' => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=18/' . $lat . '/' . $lon );
		}
		return array_values( $items );
	}

	/** URL de Photon: limitada a la caja (bbox) y centrada en ella. Sin «lang=es»: Photon no lo admite y contesta error. */
	public static function photon_url( string $q, array $box ): string {
		return add_query_arg(
			array( 'q' => $q, 'limit' => 8, 'lat' => (string) round( ( $box[1] + $box[3] ) / 2, 4 ), 'lon' => (string) round( ( $box[0] + $box[2] ) / 2, 4 ), 'bbox' => implode( ',', $box ) ),
			'https://photon.komoot.io/api/'
		);
	}

	public static function address_search(): void {
		check_ajax_referer( 'cvd_address_search', 'nonce' );
		$query = sanitize_text_field( wp_unslash( $_GET['query'] ?? '' ) );
		$municipality = sanitize_text_field( wp_unslash( $_GET['municipality'] ?? '' ) );
		$zone = sanitize_text_field( wp_unslash( $_GET['zone'] ?? '' ) );
		$state = strtoupper( sanitize_key( wp_unslash( $_GET['state'] ?? '' ) ) ) ?: 'LH';
		if ( strlen( $query ) < 3 ) { wp_send_json_success( array() ); }
		// «geo2»: lo guardado antes de 3.13.54 podía traer sitios de fuera de Cuba; no se vuelve a usar.
		$cache_key = 'cvd_geo2_' . md5( self::normalize( $query . '|' . $municipality . '|' . $zone . '|' . $state ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { wp_send_json_success( $cached ); }
		$box = self::search_box( $state );
		$province = class_exists( 'CVD_Cuban_Checkout' ) ? ( CVD_Cuban_Checkout::provinces()[ $state ] ?? '' ) : '';
		// Primero con reparto, municipio y provincia; si no sale nada, solo la calle con el municipio (siempre dentro de la caja).
		$tries = array_unique( array(
			implode( ', ', array_filter( array( $query, $zone, $municipality, $province ?: 'La Habana', 'Cuba' ) ) ),
			implode( ', ', array_filter( array( $query, $municipality ) ) ),
		) );
		$items = array(); $failed = false;
		foreach ( $tries as $q ) {
			$response = wp_remote_get( self::photon_url( $q, $box ), array( 'timeout' => 6, 'user-agent' => 'CasaViva/' . ( defined( 'CVD_VERSION' ) ? CVD_VERSION : '3' ) . ' (' . home_url( '/' ) . ')' ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { $failed = true; continue; }
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$items = self::photon_items( is_array( $body ) ? $body : array(), $box );
			if ( $items ) { break; }
		}
		$items = array_slice( $items, 0, 5 );
		// Si Photon falló no se guarda el vacío 12 horas: se vuelve a intentar en la próxima búsqueda.
		if ( $items || ! $failed ) { set_transient( $cache_key, $items, ( $items ? 12 : 1 ) * HOUR_IN_SECONDS ); }
		wp_send_json_success( $items );
	}

	public static function install_defaults(): void {
		$installed_version = (string) get_option( self::DATA_VERSION_OPTION, '' );
		if ( self::VERSION === $installed_version && false !== get_option( self::OPTION, false ) ) { return; }

		$path = CVD_DIR . 'data/shipping-rates.csv';
		if ( ! is_readable( $path ) ) { return; }

		$rates = self::parse_csv( (string) file_get_contents( $path ) );
		if ( ! $rates ) { return; }

		update_option( self::OPTION, $rates, false );
		update_option( self::DATA_VERSION_OPTION, self::VERSION, false );
	}

	public static function rates(): array {
		$rates = get_option( self::OPTION, array() );
		return is_array( $rates ) ? $rates : array();
	}

	/**
	 * Crea o cambia una fila (municipio + zona) desde el panel de la dueña. Zona vacía = referencia municipal.
	 * Ojo: si se sube VERSION, install_defaults() vuelve a cargar el CSV y pisa estos cambios.
	 */
	public static function save_rate( string $municipality, string $zone, int $fee, bool $active, string $by = 'panel' ): array {
		$municipality = sanitize_text_field( $municipality ); $zone = sanitize_text_field( $zone );
		if ( '' === trim( $municipality ) ) { return array( 'error' => 'Falta el municipio.' ); }
		if ( $fee < 0 || $fee > 100000 ) { return array( 'error' => 'La tarifa debe estar entre 0 y 100000 CUP.' ); }
		$rates = self::rates(); $before = null; $found = false;
		foreach ( $rates as &$row ) {
			if ( self::normalize( $row['municipality'] ) === self::normalize( $municipality ) && self::normalize( $row['zone'] ) === self::normalize( $zone ) ) {
				$before = $row; $row['fee'] = $fee; $row['active'] = $active; $found = true; break;
			}
		}
		unset( $row );
		if ( ! $found ) { $rates[] = array( 'municipality' => $municipality, 'zone' => $zone, 'fee' => $fee, 'active' => $active ); }
		update_option( self::OPTION, $rates, false );
		$log = get_option( 'cvd_shipping_rates_log', array() );
		$log = array_slice( array_merge( is_array( $log ) ? $log : array(), array( array( 'at' => gmdate( 'c' ), 'by' => $by, 'municipality' => $municipality, 'zone' => $zone, 'before' => $before, 'fee' => $fee, 'active' => $active ) ) ), -300 );
		update_option( 'cvd_shipping_rates_log', $log, false );
		return array( 'ok' => true, 'created' => ! $found, 'before' => $before );
	}

	public static function localities(): array {
		$out = array();
		foreach ( self::rates() as $row ) {
			if ( empty( $row['active'] ) || '' === $row['zone'] ) { continue; }
			$out[ $row['municipality'] ][] = $row['zone'];
		}
		foreach ( $out as &$zones ) { $zones = array_values( array_unique( $zones ) ); }
		return $out;
	}

	/**
	 * Reparto escrito por el cliente → reparto oficial de la lista de ese municipio (3.13.53).
	 * Pasó el día del lanzamiento: «Mitamar» (Playa) se guardó tal cual, tarifa 0 y el vale del mensajero salió sin mensajería.
	 * Orden: igual sin acentos ni mayúsculas → igual sin «Reparto»/signos/el nombre del municipio → el nombre oficial
	 * aparece entero dentro de lo escrito («Miramar, Playa») → errata (Levenshtein ≤ 2; ≤ 1 si el nombre es corto).
	 * Solo vale si hay UN candidato claro; si no, zone = '' (queda «por confirmar con la tienda», nunca 0 en silencio).
	 * Devuelve array( 'zone' => nombre oficial o '', 'how' => 'exact' | 'fuzzy' | 'none' ).
	 */
	public static function match_zone( string $municipality, string $zone ): array {
		$none = array( 'zone' => '', 'how' => 'none' );
		$municipality_key = self::normalize( $municipality ); $zone_key = self::normalize( $zone );
		if ( '' === $municipality_key || '' === $zone_key ) { return $none; }
		$official = array();
		foreach ( self::rates() as $row ) {
			if ( empty( $row['active'] ) || '' === (string) $row['zone'] || self::normalize( $row['municipality'] ) !== $municipality_key ) { continue; }
			if ( self::normalize( $row['zone'] ) === $zone_key ) { return array( 'zone' => (string) $row['zone'], 'how' => 'exact' ); }
			$official[] = (string) $row['zone'];
		}
		if ( ! $official ) { return $none; }
		$typed = self::loose( $zone );
		// «Kohly, Playa» con Playa elegido: el municipio escrito dentro del reparto no cuenta (si queda algo).
		$municipality_loose = self::loose( $municipality );
		$without_municipality = '' === $municipality_loose ? $typed : preg_replace( '/\b' . preg_quote( $municipality_loose, '/' ) . '\b/', ' ', $typed );
		$without_municipality = trim( preg_replace( '/\s+/', ' ', $without_municipality ) );
		if ( '' !== $without_municipality ) { $typed = $without_municipality; }
		if ( '' === $typed ) { return $none; }
		$best = array(); $best_distance = PHP_INT_MAX;
		foreach ( $official as $name ) {
			$key = self::loose( $name );
			if ( '' === $key ) { continue; }
			if ( $key === $typed || ( strlen( $key ) >= 4 && preg_match( '/(^| )' . preg_quote( $key, '/' ) . '( |$)/', $typed ) ) ) {
				$distance = 0;
			} else {
				$allowed = strlen( $key ) >= 6 ? 2 : ( strlen( $key ) >= 4 ? 1 : 0 );
				$distance = self::distance( $typed, $key );
				if ( $distance > $allowed ) { continue; }
			}
			if ( $distance < $best_distance ) { $best_distance = $distance; $best = array( $key => $name ); }
			elseif ( $distance === $best_distance && ! isset( $best[ $key ] ) ) { $best[ $key ] = $name; }
		}
		if ( ! $best ) { return $none; }
		if ( count( $best ) > 1 ) {
			// Varios con el nombre oficial dentro («Miramar Playa» ya sin Playa no llega aquí): gana el nombre más largo si es único.
			if ( 0 !== $best_distance ) { return $none; }
			$lengths = array_map( 'strlen', array_keys( $best ) ); $max = max( $lengths );
			$longest = array_values( array_filter( array_keys( $best ), static fn( $k ) => strlen( $k ) === $max ) );
			if ( 1 !== count( $longest ) ) { return $none; }
			$best = array( $longest[0] => $best[ $longest[0] ] );
		}
		return array( 'zone' => (string) reset( $best ), 'how' => 'fuzzy' );
	}

	public static function quote( string $municipality, string $zone ): array {
		$municipality_key = self::normalize( $municipality );
		$match = self::match_zone( $municipality, $zone );
		$official_key = self::normalize( $match['zone'] );
		$general = 0;
		foreach ( self::rates() as $row ) {
			if ( empty( $row['active'] ) || self::normalize( $row['municipality'] ) !== $municipality_key ) { continue; }
			if ( '' === $row['zone'] ) { $general = absint( $row['fee'] ); continue; }
			if ( $official_key && self::normalize( $row['zone'] ) === $official_key ) {
				$found = array( 'fee' => absint( $row['fee'] ), 'status' => 'zone', 'label' => $row['zone'] );
				// Errata corregida: se guarda lo que escribió el cliente para que la tienda lo vea en la nota.
				return 'fuzzy' === $match['how'] ? $found + array( 'typed' => $zone, 'matched' => 'fuzzy' ) : $found;
			}
		}
		$pending = array( 'fee' => 0, 'status' => 'pending', 'label' => $zone ?: $municipality, 'reference' => $general );
		// Escribió un reparto que no está en la lista de ese municipio: «por confirmar con la tienda» (no 0 en silencio).
		return '' !== trim( $zone ) && '' !== $municipality_key ? $pending + array( 'unknown' => true ) : $pending;
	}

	public static function refresh_checkout_rate( string $post_data ): void {
		parse_str( $post_data, $data );
		$type = sanitize_key( $data['billing_cvd_fulfillment_type'] ?? 'delivery' );
		$quote = 'pickup' === $type
			? array( 'fee' => 0, 'status' => 'pickup', 'label' => class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::pickup_label() : 'Recogida en tienda' )
			: self::quote( sanitize_text_field( $data['billing_city'] ?? '' ), sanitize_text_field( $data['billing_cvd_locality'] ?? '' ) );
		if ( WC()->session ) { WC()->session->set( 'cvd_shipping_quote', $quote ); }
	}

	public static function checkout_rate_row(): void {
		$quote = WC()->session ? WC()->session->get( 'cvd_shipping_quote', array() ) : array();
		$status = $quote['status'] ?? 'pending';
		echo '<tr class="cvd-shipping-cup"><th>Mensajería</th><td data-title="Mensajería">';
		if ( 'pickup' === $status ) {
			echo '<strong>No aplica</strong>';
		} elseif ( ! empty( $quote['fee'] ) ) {
			echo '<strong>' . esc_html( number_format_i18n( (int) $quote['fee'], 0 ) ) . ' CUP</strong><small> · ' . esc_html( $quote['label'] ) . '</small>';
		} else {
			echo '<strong>Por confirmar</strong><small> · selecciona un reparto de la lista</small>';
		}
		echo '</td></tr>';
	}

	public static function save_order_rate( WC_Order $order, array $data ): void {
		$type = sanitize_key( $_POST['billing_cvd_fulfillment_type'] ?? 'delivery' );
		$quote = 'pickup' === $type
			? array( 'fee' => 0, 'status' => 'pickup', 'label' => 'Recogida en tienda' )
			: self::quote( sanitize_text_field( wp_unslash( $_POST['billing_city'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['billing_cvd_locality'] ?? '' ) ) );
		$order->update_meta_data( '_cvd_shipping_fee_cup', absint( $quote['fee'] ?? 0 ) );
		$order->update_meta_data( '_cvd_shipping_rate_status', sanitize_key( $quote['status'] ?? 'pending' ) );
		$order->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( $quote['label'] ?? '' ) );
		$order->update_meta_data( '_cvd_shipping_rate_version', self::VERSION );
		self::flag_locality( $order, $quote, sanitize_text_field( wp_unslash( $_POST['billing_city'] ?? '' ) ) );
	}

	/**
	 * Reparto del pedido frente a la lista oficial (3.13.53). Errata → se guarda el nombre oficial (y lo escrito aparte);
	 * desconocido → se deja como lo escribió y se marca _cvd_locality_check = 'unknown' (vale: «por confirmar con la tienda»).
	 * La nota se escribe al crear el pedido (note_locality): antes de guardarse aún no tiene número y la nota se perdería.
	 */
	private static function flag_locality( WC_Order $order, array $quote, string $municipality ): void {
		if ( ! empty( $quote['typed'] ) && 'zone' === ( $quote['status'] ?? '' ) ) {
			$order->update_meta_data( '_cvd_locality_typed', sanitize_text_field( (string) $quote['typed'] ) );
			$order->update_meta_data( '_cvd_locality', sanitize_text_field( (string) $quote['label'] ) );
			$order->update_meta_data( '_cvd_locality_check', 'fixed' );
			$order->update_meta_data( '_cvd_locality_note', sprintf( 'Reparto corregido: el cliente escribió «%s» y se usó «%s» (%s) de la lista. Mensajería %s CUP.', $quote['typed'], $quote['label'], $municipality, number_format_i18n( absint( $quote['fee'] ?? 0 ), 0 ) ) );
		} elseif ( ! empty( $quote['unknown'] ) ) {
			$order->update_meta_data( '_cvd_locality_check', 'unknown' );
			$order->update_meta_data( '_cvd_locality_note', sprintf( '⚠️ Reparto «%s» no está en la lista de %s: mensajería POR CONFIRMAR con la tienda (no se cobró nada de mensajería). Confirma la zona con el cliente antes de mandar el mensajero.', $quote['label'] ?? '', $municipality ?: 'ese municipio' ) );
		}
	}

	/** Nota visible en el pedido (WooCommerce y panel) con lo que pasó con el reparto. */
	public static function note_locality( $order ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$note = (string) $order->get_meta( '_cvd_locality_note', true );
		if ( '' === $note ) { return; }
		$order->add_order_note( $note );
		$order->delete_meta_data( '_cvd_locality_note' );
		$order->save();
	}

	public static function order_fee( WC_Order $order ): int {
		$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		if ( $fee || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return $fee; }
		$quote = self::quote( $order->get_billing_city(), (string) $order->get_meta( '_cvd_locality', true ) );
		$fee = absint( $quote['fee'] ?? 0 );
		// Errata en un pedido ya cerrado (entregado, cobrado o cancelado): no se le cambia la mensajería a posteriori.
		if ( $fee && ! empty( $quote['typed'] ) && self::is_closed( $order ) ) { return 0; }
		if ( $fee ) {
			$order->update_meta_data( '_cvd_shipping_fee_cup', $fee );
			$order->update_meta_data( '_cvd_shipping_rate_status', 'zone' );
			$order->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( $quote['label'] ?? '' ) );
			$order->update_meta_data( '_cvd_shipping_rate_version', self::VERSION );
			// Pedidos de antes de 3.13.53 con errata («Mitamar»): se corrige el reparto y queda nota para la tienda.
			self::flag_locality( $order, $quote, $order->get_billing_city() );
			$order->save();
			self::note_locality( $order );
		}
		return $fee;
	}

	private static function is_closed( WC_Order $order ): bool {
		if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) { return true; }
		$delivery = sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) );
		return in_array( $delivery, array( 'handed_over', 'delivered', 'cash_returned', 'closed', 'failed', 'returned', 'cancelled' ), true );
	}

	public static function order_item_totals( array $totals, WC_Order $order, string $tax_display ): array {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return $totals; }
		$fee = self::order_fee( $order );
		$row = array( 'label' => 'Mensajería:', 'value' => $fee ? number_format_i18n( $fee, 0 ) . ' CUP' : 'Por confirmar' );
		$out = array();
		foreach ( $totals as $key => $total ) {
			if ( 'order_total' === $key ) { $out['cvd_shipping_cup'] = $row; }
			$out[ $key ] = $total;
		}
		return $out;
	}

	public static function formatted_order_total( string $formatted, WC_Order $order ): string {
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return $formatted; }
		$fee = self::order_fee( $order );
		return $fee ? $formatted . ' <span class="cvd-total-shipping">+ ' . esc_html( number_format_i18n( $fee, 0 ) ) . ' CUP de mensajería</span>' : $formatted;
	}

	public static function admin_menu(): void {
		add_submenu_page( 'woocommerce', 'Tarifas de mensajería', 'Tarifas de mensajería', 'manage_woocommerce', 'cvd-shipping-rates', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page(): void {
		if ( isset( $_POST['cvd_rates_csv'] ) && check_admin_referer( 'cvd_save_shipping_rates' ) ) {
			$rates = self::parse_csv( wp_unslash( $_POST['cvd_rates_csv'] ) );
			if ( $rates ) { update_option( self::OPTION, $rates, false ); echo '<div class="notice notice-success"><p>Tarifas actualizadas.</p></div>'; }
			else { echo '<div class="notice notice-error"><p>No se encontraron tarifas válidas.</p></div>'; }
		}
		$csv = self::to_csv( self::rates() );
		echo '<div class="wrap"><h1>Tarifas de mensajería</h1><p>Una fila por zona. La zona vacía es solo referencia municipal. Los pedidos guardan una copia de la tarifa vigente.</p><form method="post">';
		wp_nonce_field( 'cvd_save_shipping_rates' );
		echo '<textarea name="cvd_rates_csv" rows="24" class="large-text code" spellcheck="false">' . esc_textarea( $csv ) . '</textarea><p><button class="button button-primary">Guardar tarifas</button></p></form></div>';
	}

	private static function parse_csv( string $csv ): array {
		$stream = fopen( 'php://temp', 'r+' ); fwrite( $stream, preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ); rewind( $stream );
		$header = fgetcsv( $stream ); $rows = array();
		while ( ( $row = fgetcsv( $stream ) ) !== false ) {
			if ( count( $row ) < 3 || ! trim( $row[0] ) || ! is_numeric( $row[2] ) ) { continue; }
			$rows[] = array( 'municipality' => sanitize_text_field( $row[0] ), 'zone' => sanitize_text_field( $row[1] ), 'fee' => absint( $row[2] ), 'active' => ! isset( $row[3] ) || in_array( strtolower( trim( $row[3] ) ), array( 'yes', 'si', 'sí', '1', 'active' ), true ) );
		}
		fclose( $stream ); return $rows;
	}

	private static function to_csv( array $rates ): string {
		$stream = fopen( 'php://temp', 'r+' ); fputcsv( $stream, array( 'municipio', 'zona', 'tarifa_cup', 'activo' ) );
		foreach ( $rates as $row ) { fputcsv( $stream, array( $row['municipality'], $row['zone'], $row['fee'], $row['active'] ? 'yes' : 'no' ) ); }
		rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream ); return $csv;
	}

	private static function normalize( string $value ): string {
		return strtolower( trim( remove_accents( $value ) ) );
	}

	/** Letras distintas entre dos nombres (Levenshtein + dos letras cambiadas de sitio cuentan 1: «Kohly» ↔ «Kholy»). */
	private static function distance( string $a, string $b ): int {
		$la = strlen( $a ); $lb = strlen( $b ); $d = array();
		for ( $i = 0; $i <= $la; $i++ ) { $d[ $i ][0] = $i; }
		for ( $j = 0; $j <= $lb; $j++ ) { $d[0][ $j ] = $j; }
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) { $d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 ); }
			}
		}
		return $d[ $la ][ $lb ];
	}

	/** Para comparar repartos con erratas: sin acentos, sin signos, sin «Reparto»/«Rpto.» delante y con espacios simples. */
	private static function loose( string $value ): string {
		$value = preg_replace( '/[^a-z0-9]+/', ' ', self::normalize( $value ) );
		$value = preg_replace( '/^(reparto|rpto|repto|barrio) /', '', trim( $value ) );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}
}
