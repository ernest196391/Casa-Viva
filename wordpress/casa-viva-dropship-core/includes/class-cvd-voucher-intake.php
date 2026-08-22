<?php

defined( 'ABSPATH' ) || exit;

/** Puente stateless entre Casa Viva y el parser de vales de NEXO. */
final class CVD_Voucher_Intake {
	private const DEFAULT_NEXO_URL = 'https://ernesto-rondon-nexo.onrender.com';

	public static function register(): void {
		add_shortcode( 'casa_viva_voucher_intake', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/voucher/parse', array(
			'methods' => 'POST',
			'callback' => array( __CLASS__, 'parse' ),
			'permission_callback' => array( __CLASS__, 'allowed' ),
		) );
		register_rest_route( 'casa-viva/v1', '/voucher/products', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'products' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
		register_rest_route( 'casa-viva/v1', '/voucher/orders', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_order' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
	}

	public static function products( WP_REST_Request $request ): WP_REST_Response {
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 20, 'search' => $query, 'return' => 'ids' ) );
		$user = wp_get_current_user(); $gestora_id = CVD_Registration::is_approved_gestora( $user ) ? $user->ID : 0;
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id ); if ( ! $product || ! $product->is_purchasable() ) { continue; }
			$base = (float) get_post_meta( $product->get_id(), '_price', true );
			$price = $gestora_id ? (float) CVD_Gestora_Store::resolve_price( $product, $gestora_id, $base ) : $base;
			$items[] = array( 'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'price' => $price, 'currency' => get_woocommerce_currency() );
		}
		return rest_ensure_response( $items );
	}

	public static function create_order( WP_REST_Request $request ) {
		$draft = $request->get_param( 'draft' ); $lines = $request->get_param( 'lines' );
		$key = sanitize_text_field( (string) $request->get_header( 'Idempotency-Key' ) );
		if ( strlen( $key ) < 16 || ! is_array( $draft ) || ! is_array( $lines ) ) { return new WP_Error( 'cvd_voucher_confirmation', 'La confirmación no es válida.', array( 'status' => 400 ) ); }
		$key_hash = hash( 'sha256', $key );
		$existing = wc_get_orders( array( 'limit' => 1, 'meta_key' => '_cvd_voucher_confirmation_key', 'meta_value' => $key_hash ) );
		if ( $existing ) { return rest_ensure_response( self::created_projection( $existing[0], true ) ); }
		$lock = '_cvd_voucher_lock_' . $key_hash;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - absint( get_option( $lock, 0 ) ) > 120 ) { delete_option( $lock ); }
			if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'cvd_voucher_in_progress', 'La confirmación ya está en curso. Espera y reintenta.', array( 'status' => 409 ) ); }
		}
		$customer = sanitize_text_field( (string) ( $draft['customer'] ?? '' ) );
		$phones = array_values( array_filter( array_map( static fn( $phone ) => preg_replace( '/[^0-9+]/', '', (string) $phone ), (array) ( $draft['phones'] ?? array() ) ) ) );
		$address = sanitize_text_field( (string) ( $draft['address'] ?? '' ) ); $municipality = sanitize_text_field( (string) ( $draft['municipality'] ?? '' ) ); $zone = sanitize_text_field( (string) ( $draft['zone'] ?? '' ) );
		if ( ! $customer || ! $phones || ! $address || ! $municipality || ! $zone || ! $lines ) { delete_option( $lock ); return new WP_Error( 'cvd_voucher_required', 'Confirma cliente, teléfono, dirección, municipio, zona y productos.', array( 'status' => 400 ) ); }
		$owner_id = self::preferred_owner( sanitize_text_field( (string) ( $draft['managerCode'] ?? '' ) ) );
		if ( is_wp_error( $owner_id ) ) { delete_option( $lock ); return $owner_id; }
		$order = null;
		try {
			$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'casa-viva-voucher' ) );
			if ( is_wp_error( $order ) ) { throw new RuntimeException( $order->get_error_message() ); }
			foreach ( $lines as $line ) {
				$product = wc_get_product( absint( $line['productId'] ?? 0 ) ); $quantity = max( 1, absint( $line['quantity'] ?? 0 ) );
				if ( ! $product || ! $product->is_purchasable() ) { throw new RuntimeException( 'Selecciona un producto válido del catálogo Casa Viva.' ); }
				$base = (float) get_post_meta( $product->get_id(), '_price', true ); $unit = $owner_id ? (float) CVD_Gestora_Store::resolve_price( $product, $owner_id, $base ) : $base;
				$item_id = $order->add_product( $product, $quantity, array( 'subtotal' => $unit * $quantity, 'total' => $unit * $quantity ) );
				$item = $order->get_item( $item_id ); if ( $item ) { $item->add_meta_data( '_cvd_base_unit_price', wc_format_decimal( $base, 4 ), true ); $item->add_meta_data( '_cvd_sale_unit_price', wc_format_decimal( $unit, 4 ), true ); $item->add_meta_data( '_cvd_margin_unit', wc_format_decimal( max( 0, $unit - $base ), 4 ), true ); if ( $owner_id ) { $item->add_meta_data( '_cvd_pricing_gestora_user_id', $owner_id, true ); } $item->save(); }
			}
			$name = preg_split( '/\s+/', $customer, 2 ); $order->set_billing_first_name( $name[0] ?? $customer ); $order->set_billing_last_name( $name[1] ?? '' ); $order->set_billing_phone( $phones[0] ); $order->set_billing_address_1( $address ); $order->set_billing_address_2( sanitize_text_field( (string) ( $draft['betweenStreets'] ?? '' ) ) ); $order->set_billing_city( $municipality ); $order->set_billing_country( 'CU' ); $order->set_currency( get_woocommerce_currency() );
			$order->update_meta_data( '_cvd_alternate_phone', $phones[1] ?? '' ); $order->update_meta_data( '_cvd_locality', $zone ); $order->update_meta_data( '_cvd_reference', sanitize_text_field( (string) ( $draft['reference'] ?? '' ) ) ); $order->update_meta_data( '_cvd_fulfillment_type', 'delivery' );
			$date = sanitize_text_field( (string) ( $draft['scheduledDate'] ?? '' ) ); $order->update_meta_data( '_cvd_delivery_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
			$window = sanitize_key( (string) ( $draft['scheduledTime'] ?? '' ) ); $window = str_contains( $window, 'tarde' ) ? 'afternoon' : ( str_contains( $window, 'mañana' ) || str_contains( $window, 'manana' ) ? 'morning' : '' ); $order->update_meta_data( '_cvd_delivery_window', $window );
			$change = array(); foreach ( (array) ( $draft['changeRequired'] ?? array() ) as $money ) { $amount = (float) wc_format_decimal( $money['amount'] ?? 0, 2 ); $currency = strtoupper( sanitize_key( (string) ( $money['currency'] ?? '' ) ) ); if ( $amount > 0 && in_array( $currency, array( 'USD', 'CUP', 'EUR' ), true ) ) { $change[] = array( 'amount' => $amount, 'currency' => $currency ); } } $order->update_meta_data( '_cvd_change_required', $change );
			$quote = CVD_Shipping_Rates::quote( $municipality, $zone ); $order->update_meta_data( '_cvd_shipping_fee_cup', absint( $quote['fee'] ?? 0 ) ); $order->update_meta_data( '_cvd_shipping_rate_status', sanitize_key( (string) ( $quote['status'] ?? 'pending' ) ) ); $order->update_meta_data( '_cvd_shipping_rate_label', sanitize_text_field( (string) ( $quote['label'] ?? '' ) ) );
			$order->update_meta_data( '_cvd_voucher_confirmation_key', $key_hash ); $order->update_meta_data( '_cvd_source_order_code', sanitize_text_field( (string) ( $draft['orderCode'] ?? '' ) ) ); $order->update_meta_data( '_cvd_order_intake_source', 'nexo_confirmed_voucher' ); $order->update_meta_data( '_cvd_pricing_gestora_user_id', $owner_id );
			$notes = array_map( 'sanitize_text_field', (array) ( $draft['notes'] ?? array() ) ); if ( $notes ) { $order->set_customer_note( implode( "\n", array_filter( $notes ) ) ); }
			CVD_Attribution::attach_operator_order( $order, $owner_id ); $order->calculate_totals(); $order->save(); do_action( 'woocommerce_checkout_order_created', $order ); $order->update_status( 'processing', 'Pedido confirmado por humano desde vale interpretado por NEXO.' );
			delete_option( $lock ); return rest_ensure_response( self::created_projection( $order, false ) );
		} catch ( Throwable $error ) {
			delete_option( $lock ); if ( $order instanceof WC_Order ) { $order->delete( true ); }
			return new WP_Error( 'cvd_voucher_create', $error->getMessage(), array( 'status' => 400 ) );
		}
	}

	private static function preferred_owner( string $manager_code ) {
		$user = wp_get_current_user(); if ( CVD_Registration::is_approved_gestora( $user ) ) { return $user->ID; }
		if ( ! $manager_code ) { return 0; }
		$users = get_users( array( 'number' => 1, 'meta_key' => '_cvd_referral_code', 'meta_value' => strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', $manager_code ) ), 'fields' => 'all' ) );
		if ( ! $users || ! CVD_Registration::is_approved_gestora( $users[0] ) ) { return new WP_Error( 'cvd_voucher_manager', 'El código de gestora no corresponde a una cuenta aprobada.', array( 'status' => 400 ) ); }
		return $users[0]->ID;
	}

	private static function created_projection( WC_Order $order, bool $replayed ): array { return array( 'orderId' => $order->get_id(), 'orderNumber' => $order->get_order_number(), 'status' => $order->get_status(), 'shippingFeeCup' => CVD_Shipping_Rates::order_fee( $order ), 'shippingStatus' => $order->get_meta( '_cvd_shipping_rate_status', true ), 'replayed' => $replayed, 'url' => $order->get_view_order_url() ); }

	public static function allowed(): bool {
		if ( ! is_user_logged_in() ) { return false; }
		$user = wp_get_current_user();
		if ( array_intersect( array( 'administrator', 'shop_manager', 'cvd_operator' ), (array) $user->roles ) ) { return true; }
		return CVD_Registration::is_approved_gestora( $user );
	}

	public static function parse( WP_REST_Request $request ) {
		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) );
		if ( strlen( $text ) < 20 || strlen( $text ) > 12000 ) {
			return new WP_Error( 'cvd_voucher_text', 'Pega un vale de entre 20 y 12 000 caracteres.', array( 'status' => 400 ) );
		}
		$base = untrailingslashit( esc_url_raw( (string) get_option( 'cvd_nexo_service_url', self::DEFAULT_NEXO_URL ) ) );
		if ( ! $base || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
			return new WP_Error( 'cvd_nexo_config', 'El servicio NEXO no está configurado de forma segura.', array( 'status' => 503 ) );
		}
		$response = wp_remote_post( $base . '/api/messaging/parse-voucher', array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array( 'text' => $text, 'business' => 'casa-viva', 'source' => 'operator-paste', 'locale' => 'es-CU' ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cvd_nexo_unavailable', 'NEXO no está disponible. Conserva el vale y reintenta; no se creó ningún pedido.', array( 'status' => 503 ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'cvd_nexo_response', 'NEXO no pudo interpretar este vale. No se creó ningún pedido.', array( 'status' => 502 ) );
		}
		$draft = isset( $body['draft'] ) && is_array( $body['draft'] ) ? $body['draft'] : $body;
		if ( ! isset( $draft['products'], $draft['missing'], $draft['confidence'] ) || ! is_array( $draft['products'] ) || ! is_array( $draft['missing'] ) ) {
			return new WP_Error( 'cvd_nexo_contract', 'La respuesta de NEXO no cumple el contrato vigente.', array( 'status' => 502 ) );
		}
		$result = rest_ensure_response( array( 'draft' => $draft, 'provider' => sanitize_key( (string) ( $body['meta']['provider'] ?? '' ) ) ) );
		$result->header( 'Cache-Control', 'no-store, max-age=0' );
		return $result;
	}

	public static function assets(): void {
		if ( ! is_page( 'interpretar-vale' ) ) { return; }
		wp_enqueue_style( 'cvd-voucher-intake', CVD_URL . 'assets/voucher-intake.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-voucher-intake', CVD_URL . 'assets/voucher-intake.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-voucher-intake', 'cvdVoucherIntake', array(
			'endpoint' => rest_url( 'casa-viva/v1/voucher/parse' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'productsEndpoint' => rest_url( 'casa-viva/v1/voucher/products' ),
			'ordersEndpoint' => rest_url( 'casa-viva/v1/voucher/orders' ),
		) );
	}

	public static function render(): string {
		if ( ! self::allowed() ) { return '<div class="cvd-app-denied">Necesitas una cuenta autorizada de Casa Viva.</div>'; }
		ob_start(); ?>
		<main class="cvd-voucher-app" data-cvd-voucher>
			<header><p class="cvd-kicker">Entrada inteligente · sin persistencia</p><h1>Agregar pedido desde un vale</h1><p>NEXO propone; tú corriges. Casa Viva todavía no crea nada en este paso.</p></header>
			<section class="cvd-voucher-card"><label for="cvd-voucher-text"><strong>Pega el vale completo</strong></label><textarea id="cvd-voucher-text" maxlength="12000" rows="12" placeholder="Pedido, productos, cliente, dirección, importes y notas"></textarea><button class="cvd-primary" data-voucher-parse type="button">Interpretar con NEXO</button><p class="cvd-voucher-status" role="status" aria-live="polite"></p></section>
			<section class="cvd-voucher-card" data-voucher-review hidden><div class="cvd-voucher-heading"><div><p class="cvd-kicker">NEXO entendió esto</p><h2>Revisa y corrige</h2></div><strong data-voucher-confidence></strong></div><div data-voucher-alerts></div><form data-voucher-form></form><button class="cvd-primary" data-voucher-confirm type="button">Continuar a confirmación canónica</button><p class="cvd-voucher-note">Este botón prepara el payload revisado. La creación canónica se habilitará en el siguiente bloque.</p></section>
			<section class="cvd-voucher-card" data-voucher-payload hidden><h2>Confirmación</h2><pre></pre></section>
		</main>
		<?php return (string) ob_get_clean();
	}
}
