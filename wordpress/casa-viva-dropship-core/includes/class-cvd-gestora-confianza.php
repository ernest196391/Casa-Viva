<?php

defined( 'ABSPATH' ) || exit;

/**
 * «¿Es de confianza este número?» en el área de gestoras (3.14.42, Ernesto 20-oct). La gestora escribe el número de
 * su cliente y ve si está en la lista de números sospechosos de estafa de la red NEXO; puede reportar un número y
 * ver la lista, que se actualiza sola (búsqueda diaria, grupos, Billete Seguro, avisos del equipo).
 *
 * La lista vive en NEXO (Supabase `crm_sospechosos`, D83). Core la pide a la función `billete-seguro-revisa` con el
 * código de equipo de Casa Viva, guardado en la opción `cvd_billete_seguro_equipo` (nunca en el código ni en la
 * página): la gestora no ve el código. Hacia fuera el motivo solo sale de los números CONFIRMADOS por el equipo.
 */
final class CVD_Gestora_Confianza {
	private const NS = 'casa-viva/v1';
	private const URL = 'https://viwwlriwlwodrfukbgbj.supabase.co/functions/v1/billete-seguro-revisa';
	private const OPTION_CODE = 'cvd_billete_seguro_equipo';
	private const REPORTS_PER_DAY = 10;

	public static function register(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( self::NS, '/gestora/confianza', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			) );
			register_rest_route( self::NS, '/gestora/confianza/lista', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lista' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			) );
		} );
	}

	public static function can_use(): bool {
		$user = wp_get_current_user();
		return $user->exists() && CVD_Registration::is_approved_gestora( $user );
	}

	public static function available(): bool {
		return '' !== trim( (string) get_option( self::OPTION_CODE, '' ) );
	}

	/** Llama a la función de NEXO. → array con la respuesta o WP_Error. */
	private static function nexo( array $body ) {
		if ( ! self::available() ) { return new WP_Error( 'cvd_confianza_off', 'La lista no está disponible ahora.', array( 'status' => 503 ) ); }
		$response = wp_remote_post( self::URL, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'equipo' => trim( (string) get_option( self::OPTION_CODE, '' ) ) ) + $body ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'cvd_confianza_red', 'No se pudo consultar la lista. Prueba otra vez.', array( 'status' => 502 ) ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 400 === $code && 'telefono' === ( $data['error'] ?? '' ) ) { return new WP_Error( 'cvd_confianza_tel', 'Ese número no parece un teléfono. Escríbelo completo, por ejemplo 5X XXX XXX.', array( 'status' => 400 ) ); }
		if ( 200 !== $code || ! is_array( $data ) ) { return new WP_Error( 'cvd_confianza_red', 'No se pudo consultar la lista. Prueba otra vez.', array( 'status' => 502 ) ); }
		return $data;
	}

	public static function handle( WP_REST_Request $request ) {
		$accion = (string) $request->get_param( 'accion' );
		$tel = preg_replace( '/[^\d+]/', '', (string) $request->get_param( 'telefono' ) );
		if ( strlen( preg_replace( '/\D/', '', $tel ) ) < 7 ) { return new WP_Error( 'cvd_confianza_tel', 'Escribe el número completo, por ejemplo 5X XXX XXX.', array( 'status' => 400 ) ); }
		if ( 'mirar' === $accion ) {
			$data = self::nexo( array( 'accion' => 'telefono', 'telefono' => $tel ) );
			return is_wp_error( $data ) ? $data : rest_ensure_response( $data );
		}
		if ( 'reportar' !== $accion ) { return new WP_Error( 'cvd_confianza_accion', 'Acción no válida.', array( 'status' => 400 ) ); }
		$user = wp_get_current_user();
		$key = 'cvd_confianza_rep_' . $user->ID . '_' . wp_date( 'Ymd' );
		$used = (int) get_transient( $key );
		if ( $used >= self::REPORTS_PER_DAY ) { return new WP_Error( 'cvd_confianza_limite', 'Ya reportaste ' . self::REPORTS_PER_DAY . ' números hoy. Si hay más, escríbele a la tienda.', array( 'status' => 429 ) ); }
		$motivo = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'motivo' ) ), 0, 240 );
		if ( mb_strlen( $motivo ) < 5 ) { return new WP_Error( 'cvd_confianza_motivo', 'Cuenta en una frase qué pasó.', array( 'status' => 400 ) ); }
		$data = self::nexo( array( 'accion' => 'reportar', 'telefono' => $tel, 'motivo' => $motivo, 'por' => $user->display_name, 'origen' => 'gestoras' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		set_transient( $key, $used + 1, DAY_IN_SECONDS );
		delete_transient( 'cvd_confianza_lista' );
		return rest_ensure_response( array( 'ok' => true, 'nuevo' => ! empty( $data['nuevo'] ) ) );
	}

	/** La lista, guardada 5 minutos (se actualiza sola: búsqueda diaria, grupos, reportes). */
	public static function lista() {
		$cached = get_transient( 'cvd_confianza_lista' );
		if ( is_array( $cached ) ) { return rest_ensure_response( $cached ); }
		$data = self::nexo( array( 'accion' => 'lista' ) );
		if ( is_wp_error( $data ) ) { return $data; }
		$out = array( 'numeros' => array_values( (array) ( $data['numeros'] ?? array() ) ), 'at' => time() );
		set_transient( 'cvd_confianza_lista', $out, 5 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $out );
	}

	/** La vista dentro del área de gestoras (assets/gestora-confianza.js hace las llamadas). */
	public static function render( WP_User $user ): string {
		$config = array(
			'base'  => esc_url_raw( rest_url( self::NS . '/gestora/confianza' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'on'    => self::available(),
		);
		ob_start();
		?>
		<section class="cvga-view" data-view="confianza" id="cvga-confianza" data-cvga-confianza>
			<script type="application/json" data-conf-config><?php echo wp_json_encode( $config ); ?></script>
			<div class="cvga-head"><h1>¿Es de confianza este número?</h1><p>Antes de mandar un pedido, mira si el número de tu cliente fue reportado por estafa.</p></div>
			<form class="cvga-card cvga-conf" data-conf-mirar autocomplete="off">
				<label class="cvga-field" for="cvga-conf-tel"><span>Número de tu cliente</span><input id="cvga-conf-tel" name="telefono" type="tel" inputmode="tel" maxlength="20" placeholder="Ej.: 5X XXX XXX" enterkeyhint="search"></label>
				<div class="cvga-actions"><button class="cvga-btn" type="submit">Mirar</button></div>
				<div class="cvga-conf-res" data-conf-res aria-live="polite"></div>
			</form>
			<section class="cvga-card cvga-conf">
				<h2>Números reportados</h2>
				<p class="cvga-note">Se actualiza sola cada día. <b>Sin confirmar</b> = lo avisaron en grupos o en internet y la tienda aún no lo comprobó.</p>
				<label class="cvga-field" for="cvga-conf-filtro"><span>Filtrar</span><input id="cvga-conf-filtro" type="text" inputmode="numeric" maxlength="20" placeholder="Escribe parte del número" data-conf-filtro></label>
				<ul class="cvga-conf-lista" data-conf-lista><li class="cvga-empty">Cargando la lista…</li></ul>
			</section>
			<form class="cvga-card cvga-conf" data-conf-reportar autocomplete="off">
				<h2>Reportar un número</h2>
				<p class="cvga-note">¿Te estafaron o te avisaron de alguien? Ponlo aquí. Entra como «sin confirmar» y lo revisa la tienda.</p>
				<label class="cvga-field" for="cvga-conf-rtel"><span>Número</span><input id="cvga-conf-rtel" name="telefono" type="tel" inputmode="tel" maxlength="20" placeholder="Ej.: 5X XXX XXX"></label>
				<label class="cvga-field" for="cvga-conf-motivo"><span>¿Qué pasó?</span><textarea id="cvga-conf-motivo" name="motivo" rows="3" maxlength="240" placeholder="Ej.: pagó con un billete falso de 100 USD y dio una dirección falsa"></textarea></label>
				<div class="cvga-actions"><button class="cvga-btn cvga-btn-soft" type="submit">Reportar</button></div>
				<p class="cvga-note" data-conf-rep-res role="status"></p>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
