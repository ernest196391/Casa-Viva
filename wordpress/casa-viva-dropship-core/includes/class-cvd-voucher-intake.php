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
	}

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
		) );
	}

	public static function render(): string {
		if ( ! self::allowed() ) { return '<div class="cvd-app-denied">Necesitas una cuenta autorizada de Casa Viva.</div>'; }
		ob_start(); ?>
		<main class="cvd-voucher-app" data-cvd-voucher>
			<header><p class="cvd-kicker">Entrada inteligente · sin persistencia</p><h1>Agregar pedido desde un vale</h1><p>NEXO propone; tú corriges. Casa Viva todavía no crea nada en este paso.</p></header>
			<section class="cvd-voucher-card"><label for="cvd-voucher-text"><strong>Pega el vale completo</strong></label><textarea id="cvd-voucher-text" maxlength="12000" rows="12" placeholder="Pedido, productos, cliente, dirección, importes y notas"></textarea><button class="cvd-primary" data-voucher-parse type="button">Interpretar con NEXO</button><p class="cvd-voucher-status" role="status" aria-live="polite"></p></section>
			<section class="cvd-voucher-card" data-voucher-review hidden><div class="cvd-voucher-heading"><div><p class="cvd-kicker">NEXO entendió esto</p><h2>Revisa y corrige</h2></div><strong data-voucher-confidence></strong></div><div data-voucher-alerts></div><form data-voucher-form></form><button class="cvd-primary" data-voucher-confirm type="button">Continuar a confirmación canónica</button><p class="cvd-voucher-note">Este botón prepara el payload revisado. La creación canónica se habilitará en el siguiente bloque.</p></section>
			<section class="cvd-voucher-card" data-voucher-payload hidden><h2>Payload humano confirmado</h2><pre></pre></section>
		</main>
		<?php return (string) ob_get_clean();
	}
}
