<?php

defined( 'ABSPATH' ) || exit;

/** Configurable CUP delivery prices. Product totals remain in the store currency. */
final class CVD_Shipping_Rates {
	private const OPTION = 'cvd_shipping_rates';
	private const VERSION = '2026-08-04-v2';

	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'refresh_checkout_rate' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'checkout_rate_row' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order_rate' ), 30, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'wp_ajax_cvd_address_search', array( __CLASS__, 'address_search' ) );
		add_action( 'wp_ajax_nopriv_cvd_address_search', array( __CLASS__, 'address_search' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_item_totals' ), 20, 3 );
		add_filter( 'woocommerce_get_formatted_order_total', array( __CLASS__, 'formatted_order_total' ), 20, 2 );
	}

	public static function address_search(): void {
		check_ajax_referer( 'cvd_address_search', 'nonce' );
		$query = sanitize_text_field( wp_unslash( $_GET['query'] ?? '' ) );
		$municipality = sanitize_text_field( wp_unslash( $_GET['municipality'] ?? '' ) );
		$zone = sanitize_text_field( wp_unslash( $_GET['zone'] ?? '' ) );
		if ( strlen( $query ) < 3 ) { wp_send_json_success( array() ); }
		$cache_key = 'cvd_geo_' . md5( self::normalize( $query . '|' . $municipality . '|' . $zone ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) { wp_send_json_success( $cached ); }
		$url = add_query_arg( array( 'q' => implode( ', ', array_filter( array( $query, $zone, $municipality, 'La Habana', 'Cuba' ) ) ), 'limit' => 5, 'lang' => 'es', 'lat' => '23.1136', 'lon' => '-82.3666', 'bbox' => '-82.62,22.90,-82.02,23.35' ), 'https://photon.komoot.io/api/' );
		$response = wp_remote_get( $url, array( 'timeout' => 7, 'user-agent' => 'CasaViva/0.5.1 (' . home_url( '/' ) . ')' ) );
		if ( is_wp_error( $response ) ) { wp_send_json_success( array() ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true ); $items = array();
		foreach ( $body['features'] ?? array() as $feature ) {
			$p = $feature['properties'] ?? array(); $coordinates = $feature['geometry']['coordinates'] ?? array();
			$parts = array_filter( array( $p['name'] ?? '', $p['street'] ?? '', $p['housenumber'] ?? '', $p['district'] ?? '', $p['city'] ?? '' ) );
			$label = implode( ', ', array_values( array_unique( $parts ) ) );
			if ( ! $label || count( $coordinates ) < 2 ) { continue; }
			$items[] = array( 'label' => $label, 'map' => 'https://www.openstreetmap.org/?mlat=' . rawurlencode( $coordinates[1] ) . '&mlon=' . rawurlencode( $coordinates[0] ) . '#map=18/' . rawurlencode( $coordinates[1] ) . '/' . rawurlencode( $coordinates[0] ) );
		}
		set_transient( $cache_key, $items, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( $items );
	}

	public static function install_defaults(): void {
		if ( false !== get_option( self::OPTION, false ) ) { return; }
		$path = CVD_DIR . 'data/shipping-rates.csv';
		if ( is_readable( $path ) ) {
			update_option( self::OPTION, self::parse_csv( (string) file_get_contents( $path ) ), false );
		}
	}

	public static function rates(): array {
		$rates = get_option( self::OPTION, array() );
		return is_array( $rates ) ? $rates : array();
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

	public static function quote( string $municipality, string $zone ): array {
		$municipality_key = self::normalize( $municipality );
		$zone_key = self::normalize( $zone );
		$general = 0;
		foreach ( self::rates() as $row ) {
			if ( empty( $row['active'] ) || self::normalize( $row['municipality'] ) !== $municipality_key ) { continue; }
			if ( '' === $row['zone'] ) { $general = absint( $row['fee'] ); continue; }
			if ( $zone_key && self::normalize( $row['zone'] ) === $zone_key ) {
				return array( 'fee' => absint( $row['fee'] ), 'status' => 'zone', 'label' => $row['zone'] );
			}
		}
		return array( 'fee' => 0, 'status' => 'pending', 'label' => $zone ?: $municipality, 'reference' => $general );
	}

	public static function refresh_checkout_rate( string $post_data ): void {
		parse_str( $post_data, $data );
		$type = sanitize_key( $data['billing_cvd_fulfillment_type'] ?? 'delivery' );
		$quote = 'pickup' === $type
			? array( 'fee' => 0, 'status' => 'pickup', 'label' => 'Recogida en tienda' )
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
	}

	public static function order_fee( WC_Order $order ): int {
		$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		if ( $fee || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return $fee; }
		$quote = self::quote( $order->get_billing_city(), (string) $order->get_meta( '_cvd_locality', true ) );
		$fee = absint( $quote['fee'] ?? 0 );
		if ( $fee ) {
			$order->update_meta_data( '_cvd_shipping_fee_cup', $fee );
			$order->update_meta_data( '_cvd_shipping_rate_status', 'zone' );
			$order->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( $quote['label'] ?? '' ) );
			$order->update_meta_data( '_cvd_shipping_rate_version', self::VERSION );
			$order->save();
		}
		return $fee;
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
}
