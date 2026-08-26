<?php

defined( 'ABSPATH' ) || exit;

/** Asistente de ayuda contextual sin acceso directo a PII ni mutaciones operativas. */
final class CVD_Contextual_Assistant {
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 90 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 45 );
	}

	private static function context(): string {
		if ( ! is_user_logged_in() ) { return 'visitante'; }
		$user = wp_get_current_user();
		$program = class_exists( 'CVD_Registration' ) ? CVD_Registration::program_type( $user ) : '';
		if ( 'mensajero' === $program ) { return 'mensajero'; }
		if ( 'gestora' === $program ) { return 'gestora'; }
		if ( array_intersect( array( 'administrator', 'shop_manager', 'cvd_operator', 'cvd_clerk' ), (array) $user->roles ) ) { return 'operacion'; }
		return 'cliente';
	}

	public static function assets(): void {
		if ( is_admin() ) { return; }
		wp_enqueue_style( 'cvd-contextual-assistant', CVD_URL . 'assets/contextual-assistant.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-contextual-assistant', CVD_URL . 'assets/contextual-assistant.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-contextual-assistant', 'cvdContextualAssistant', array(
			'context' => self::context(),
			'routeUrl' => home_url( '/ruta-cv/' ),
			'managersUrl' => home_url( '/gestores/' ),
			'ordersUrl' => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/mi-cuenta/' ),
			'ratesUrl' => home_url( '/tarifas-mensajeria/' ),
		) );
	}

	public static function render(): void {
		if ( is_admin() ) { return; }
		?>
		<button class="cvd-assistant-launcher" type="button" aria-label="Abrir Asistente Casa Viva" aria-expanded="false" aria-controls="cvd-contextual-assistant"><span aria-hidden="true">🤖</span></button>
		<aside class="cvd-contextual-assistant" id="cvd-contextual-assistant" data-context="<?php echo esc_attr( self::context() ); ?>" aria-label="Asistente Casa Viva" hidden>
			<header><div><small>Casa Viva</small><strong>¿En qué te ayudo?</strong></div><button type="button" data-cvd-assistant-close aria-label="Cerrar asistente">×</button></header>
			<div class="cvd-contextual-assistant__quick" aria-label="Preguntas frecuentes">
				<button type="button" data-question="pedido">Mi pedido</button><button type="button" data-question="pago">Cómo pagar</button><button type="button" data-question="tarifa">Mensajería</button><button type="button" data-question="ayuda">Necesito ayuda</button>
			</div>
			<div class="cvd-contextual-assistant__answer" role="status" aria-live="polite"><p>Pregúntame cómo usar Casa Viva.</p></div>
			<form><label for="cvd-contextual-question">Tu pregunta</label><div><input id="cvd-contextual-question" maxlength="240" autocomplete="off" placeholder="Escribe aquí…"><button type="submit">Enviar</button></div></form>
		</aside>
		<?php
	}
}
