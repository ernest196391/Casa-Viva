<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.39 (Lennys 18-oct: «el bot los tiene locos»; algunos mensajeros no tienen carga ni conexión y no confirman):
 * «Entregado» de UN toque. El vale de WhatsApp lleva un enlace por pedido y mensajero que abre una página ligera (sin
 * tema, pensada para el teléfono y el internet lento) con el pedido ya listo y un botón grande «Entregado».
 *
 * - Enlace firmado: ?cvd_entregado=ID&t=caducidad-mensajero-firma. La firma (HMAC con la sal de WordPress, nunca una
 *   clave nueva) ata el pedido, el mensajero y la caducidad (7 días). Solo marca si el pedido sigue a nombre de ese
 *   mensajero: si lo soltó o lo pasó a otro, su enlace viejo ya no cambia nada. No inicia sesión ni abre su cuenta.
 * - El «Entregado» es el mismo de WhatsApp (CVD_Bot_Bridge::mark_delivered_by_messenger): si estaba «Entregado al
 *   mensajero» pasa por «En camino» y luego «Entregado», con el mensajero como actor del servicio de transiciones.
 *   Lo cobrado: lo que dice el vale, o «Cobré otra cantidad» (USD y CUP). Zelle/PayPal: sin dinero de productos.
 * - Abrir el enlace (o la vista previa de WhatsApp) no cambia nada: solo el botón (POST). Doble toque = «ya estaba».
 * - No se usa el «mi acceso» de siempre: ese enlace es de un solo uso e inicia sesión; este tiene que servir varias
 *   veces (doble toque, reintento sin conexión) y solo para este pedido.
 * Si el mensajero no marca nada, la dependienta lo cierra al recibir el dinero (3.14.38, CVD_Delivery::store_marks_delivered).
 */
final class CVD_Messenger_Delivered_Link {
	public const PARAM = 'cvd_entregado';
	public const TTL = 604800; // 7 días
	/** Estados en los que el mensajero todavía puede marcar «Entregado» (como por WhatsApp). */
	private const OPEN = array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over' );

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
	}

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-entregado'; }

	public static function sign( int $order_id, int $messenger_id, int $expires, string $key ): string {
		$sig = substr( hash_hmac( 'sha256', 'cvd-entregado|' . $order_id . '|' . $messenger_id . '|' . $expires, $key ), 0, 24 );
		return $expires . '-' . $messenger_id . '-' . $sig;
	}

	/** El mensajero del enlace, o 0 si la firma no vale, es de otro pedido o caducó. */
	public static function verify( int $order_id, string $token, int $now, string $key ): int {
		if ( ! preg_match( '/^(\d{9,11})-(\d{1,10})-([a-f0-9]{24})$/', $token, $m ) ) { return 0; }
		if ( (int) $m[1] < $now ) { return 0; }
		return hash_equals( self::sign( $order_id, (int) $m[2], (int) $m[1], $key ), $token ) ? (int) $m[2] : 0;
	}

	/** Enlace para el vale, o '' si no toca (sin mensajero, recogida en tienda, de prueba o ya terminado). */
	public static function url( WC_Order $order, ?int $now = null ): string {
		$messenger = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $messenger || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return ''; }
		if ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) ) { return ''; }
		if ( ! in_array( CVD_Delivery::status( $order ), self::OPEN, true ) ) { return ''; }
		return self::link( $order->get_id(), self::sign( $order->get_id(), $messenger, ( $now ?? time() ) + self::TTL, self::key() ) );
	}

	private static function link( int $order_id, string $token, array $extra = array() ): string {
		return add_query_arg( array_merge( array( self::PARAM => $order_id, 't' => $token ), $extra ), home_url( '/' ) );
	}

	/**
	 * ¿Por qué este mensajero NO puede marcar este pedido? '' = sí puede. Nunca escribe.
	 * test | cancelled | closed | other (ya no es suyo) | already | incident | failed
	 */
	public static function blocked_reason( WC_Order $order, int $messenger_id ): string {
		if ( CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order ) ) { return 'test'; }
		$status = CVD_Delivery::status( $order );
		if ( 'cancelled' === $status || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ), true ) ) { return 'cancelled'; }
		if ( 'closed' === $status ) { return 'closed'; }
		if ( $messenger_id !== absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return 'other'; }
		if ( in_array( $status, array( 'delivered', 'cash_returned' ), true ) ) { return 'already'; }
		if ( 'completed' === $order->get_status() ) { return 'closed'; }
		if ( 'incident' === $status ) { return 'incident'; }
		if ( in_array( $status, array( 'failed', 'returned' ), true ) ) { return 'failed'; }
		return in_array( $status, self::OPEN, true ) ? '' : 'other';
	}

	private static function payment( WC_Order $order ): ?array {
		return class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
	}

	/**
	 * Lo que se declara con un solo toque: lo que dice el vale (misma regla que deliveredAmounts() del bot sin cifra).
	 * Zelle/PayPal: 0 (ya pagado). Efectivo/Transferencia CUP: los CUP del vale (sin tasa: 0 → hay que escribirlo).
	 * EUR: los EUR del vale. USD/Clásica: lo del vale o el total del pedido. Transferencia CUP y Clásica: «transfer».
	 * → { usd, cup, eur, method, prepaid }
	 */
	public static function default_amounts( ?array $payment, float $products_usd ): array {
		if ( $payment && ! empty( $payment['prepaid'] ) ) { return array( 'usd' => 0.0, 'cup' => 0.0, 'eur' => 0.0, 'method' => 'transfer', 'prepaid' => true ); }
		$a = array( 'usd' => 0.0, 'cup' => 0.0, 'eur' => 0.0, 'method' => '', 'prepaid' => false );
		$currency = $payment['currency'] ?? 'USD';
		$amount = isset( $payment['amount'] ) && null !== $payment['amount'] ? (float) $payment['amount'] : null;
		if ( 'CUP' === $currency ) { $a['cup'] = (float) ( $amount ?? 0 ); }
		elseif ( 'EUR' === $currency ) { $a['eur'] = (float) ( $amount ?? 0 ); }
		else { $a['usd'] = null !== $amount && $amount > 0 ? $amount : round( $products_usd, 2 ); }
		if ( in_array( (string) ( $payment['kind'] ?? '' ), array( 'transfer_cup', 'clasica' ), true ) ) { $a['method'] = 'transfer'; }
		return $a;
	}

	/** Dinero a la cubana (misma regla que CVD_Cuban_Checkout::money): 28260 CUP → «28.260 CUP»; 41.4 USD → «41,40 USD». */
	public static function money( float $amount, string $currency ): string {
		$decimals = 'CUP' === $currency || abs( $amount - round( $amount ) ) < 0.005 ? 0 : 2;
		return number_format( $amount, $decimals, ',', '.' ) . ' ' . $currency;
	}

	private static function owed_text( float $usd, float $cup, float $eur ): string {
		$parts = array();
		if ( $usd > 0 ) { $parts[] = self::money( $usd, 'USD' ); }
		if ( $cup > 0 ) { $parts[] = self::money( $cup, 'CUP' ); }
		if ( $eur > 0 ) { $parts[] = self::money( $eur, 'EUR' ); }
		return implode( ' + ', $parts );
	}

	/** Plazo del dinero (misma regla que moneyDeadline() del bot): antes de las 4:00 p. m.; entregado después, al otro día. */
	public static function deadline( int $timestamp ): string {
		$hour = (int) ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'America/Havana' ) )->format( 'G' );
		return $hour >= 16 ? 'mañana antes de las 4:00 p. m. (ya pasaron las 4:00 p. m.)' : 'antes de las 4:00 p. m.';
	}

	/** «Listo ✅ Lleva X a la tienda antes de las 4:00 p. m.», con lo que quedó guardado en el pedido. */
	public static function done_text( WC_Order $order ): string {
		$payment = self::payment( $order );
		if ( $payment && ! empty( $payment['prepaid'] ) ) { return 'Listo ✅ Los productos ya estaban pagados (' . $payment['label'] . '): no llevas dinero de productos a la tienda. La mensajería es tuya.'; }
		$owed = self::owed_text( (float) $order->get_meta( '_cvd_collection_amount_usd', true ), (float) $order->get_meta( '_cvd_collection_amount_cup', true ), (float) $order->get_meta( '_cvd_collection_amount_eur', true ) );
		if ( '' === $owed ) { return 'Listo ✅ Ya consta como entregado. La mensajería es tuya.'; }
		if ( 'transfer' === (string) $order->get_meta( '_cvd_collection_method', true ) ) {
			return $payment && 'transfer_cup' === $payment['kind']
				? 'Listo ✅ El cliente pagó por transferencia a la tarjeta de Casa Viva (' . $owed . '). No llevas dinero de productos a la tienda; si no mandaste la captura, mándala.'
				: 'Listo ✅ Anotado: el cliente pagó por transferencia (' . $owed . ').';
		}
		$at = (string) $order->get_meta( '_cvd_delivered_at', true );
		$when = '' !== $at ? strtotime( $at . ' UTC' ) : false;
		$text = 'Listo ✅ Lleva ' . $owed . ' a la tienda ' . self::deadline( $when ?: time() );
		return $text . ( '.' === substr( $text, -1 ) ? '' : '.' ) . ' La mensajería es tuya.';
	}

	private static function amount( $value ): float {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return max( 0.0, round( (float) wc_format_decimal( $value, 2 ), 2 ) );
	}

	/**
	 * El toque en «Entregado». $post: cvd_otra (cobré otra cantidad) con cvd_usd, cvd_cup, cvd_eur.
	 * → delivered | already | needs_amount | error, o el motivo de blocked_reason() (y entonces no cambia nada).
	 */
	public static function submit( WC_Order $order, WP_User $messenger, array $post ): string {
		$blocked = self::blocked_reason( $order, $messenger->ID );
		if ( '' !== $blocked ) { return $blocked; }
		if ( ! class_exists( 'CVD_Bot_Bridge' ) ) { return 'error'; }
		$a = self::default_amounts( self::payment( $order ), (float) $order->get_total() );
		if ( ! empty( $post['cvd_otra'] ) && ! $a['prepaid'] ) {
			$a['usd'] = self::amount( $post['cvd_usd'] ?? 0 );
			$a['cup'] = self::amount( $post['cvd_cup'] ?? 0 );
			$a['eur'] = self::amount( $post['cvd_eur'] ?? 0 );
		}
		$result = CVD_Bot_Bridge::mark_delivered_by_messenger( $order, $messenger, $a['usd'], $a['cup'], $a['eur'], $a['method'], 'link' );
		$code = (string) ( $result['result'] ?? '' );
		if ( in_array( $code, array( 'delivered', 'already', 'needs_amount' ), true ) ) { return $code; }
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) {
			$fresh->add_order_note( $messenger->display_name . ' tocó «Entregado» en el enlace del vale, pero no se pudo marcar (' . ( $result['code'] ?? $code ) . '). Revísalo con él.' );
			$fresh->save();
		}
		return 'error';
	}

	// ------------------------------------------------------------------ La página

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: entregado del mensajero (privado)' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	public static function route(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { return; }
		self::no_cache();
		$order_id = absint( $_GET[ self::PARAM ] );
		$token = sanitize_text_field( wp_unslash( (string) ( $_GET['t'] ?? '' ) ) );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$messenger_id = $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? self::verify( $order_id, $token, time(), self::key() ) : 0;
		$messenger = $messenger_id ? get_userdata( $messenger_id ) : false;
		if ( ! $messenger instanceof WP_User ) {
			status_header( 403 );
			self::shell( 'Enlace no válido', '<div class="box"><h1>Este enlace ya no vale</h1><p>Caducó o no es de este pedido. Escríbele al bot <b>entregado #NÚMERO</b> o avisa a la tienda.</p></div>' );
		}
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['cvd_entregado_ok'] ) ) {
			$notice = self::submit( $order, $messenger, wp_unslash( $_POST ) );
			// Volver con GET: recargar la página no reenvía el formulario.
			if ( in_array( $notice, array( 'delivered', 'already' ), true ) ) { wp_safe_redirect( self::link( $order_id, $token, array( 'hecho' => 1 ) ), 303 ); exit; }
			$order = wc_get_order( $order_id );
		}
		self::shell( 'Pedido #' . $order->get_order_number(), self::body( $order, $messenger, $token, $notice ) );
	}

	/** Textos para cuando no se puede marcar: título y explicación. */
	private const BLOCKED = array(
		'test'      => array( 'Pedido de PRUEBA', 'Este pedido es de prueba: no se marca y no se lleva nada.' ),
		'cancelled' => array( 'Pedido cancelado', 'Este pedido está cancelado. No se marcó nada. Si de verdad lo entregaste, avisa a la tienda.' ),
		'closed'    => array( 'Pedido cerrado ✅', 'Este pedido ya está cerrado. No hay que hacer nada más.' ),
		'other'     => array( 'Ya no es tuyo', 'Este pedido ya no está a tu nombre (lo tiene otro mensajero o volvió al grupo). No se marcó nada.' ),
		'incident'  => array( 'Tiene una incidencia', 'Este pedido tiene una incidencia abierta. No se marcó nada: avisa a la tienda.' ),
		'failed'    => array( 'No entregado o devuelto', 'Este pedido consta como no entregado o devuelto. No se marcó nada: avisa a la tienda.' ),
	);

	private static function body( WC_Order $order, WP_User $messenger, string $token, string $notice ): string {
		$number = (string) $order->get_order_number();
		$first = (string) explode( ' ', trim( (string) $messenger->display_name ) )[0];
		$html = '<p class="kick">Pedido #' . esc_html( $number ) . ( '' !== $first ? ' · ' . esc_html( $first ) : '' ) . '</p>';
		$blocked = self::blocked_reason( $order, $messenger->ID );
		if ( 'already' === $blocked ) {
			return $html . '<h1>Entregado</h1><div class="ok">' . esc_html( self::done_text( $order ) ) . '</div><p class="muted">Ya quedó anotado. Puedes cerrar esta página.</p>';
		}
		if ( '' !== $blocked ) {
			[ $title, $text ] = self::BLOCKED[ $blocked ] ?? self::BLOCKED['other'];
			return $html . '<div class="box"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></div>';
		}
		$payment = self::payment( $order );
		$a = self::default_amounts( $payment, (float) $order->get_total() );
		$owed = self::owed_text( $a['usd'], $a['cup'], $a['eur'] );
		$customer = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$html .= '<h1>¿Ya lo entregaste?</h1>';
		$html .= '<div class="card"><div class="row"><small>Cliente</small><b>' . esc_html( '' !== $customer ? $customer : 'Cliente' ) . '</b></div><div class="row"><small>Zona</small><b>' . esc_html( CVD_Delivery::destination_zone( $order ) ) . '</b></div>';
		$html .= '<div class="row"><small>Cobrar</small><b>' . esc_html( self::charge_text( $order, $payment, $a, $owed ) ) . '</b></div>';
		foreach ( self::extra_lines( $order ) as $line ) { $html .= '<div class="row"><small>Además</small><span>' . esc_html( $line ) . '</span></div>'; }
		$html .= '</div>';
		if ( 'needs_amount' === $notice ) { $html .= '<div class="err">Escribe cuánto cobraste de los productos (en USD o en CUP) y toca otra vez.</div>'; }
		if ( 'error' === $notice ) { $html .= '<div class="err">No se pudo marcar ahora. Toca otra vez en un momento o escríbele al bot <b>entregado #' . esc_html( $number ) . '</b>.</div>'; }
		$action = esc_url( self::link( $order->get_id(), $token ) );
		$unknown = ! $a['prepaid'] && '' === $owed; // CUP sin tasa: hay que escribir la cifra
		if ( ! $unknown ) {
			$html .= '<form method="post" action="' . $action . '" data-once-form><input type="hidden" name="cvd_entregado_ok" value="1"><button class="btn big" type="submit" data-once>✅ Entregado</button></form>';
			$html .= '<p class="muted">' . esc_html( $a['prepaid'] ? 'Ya está pagado: no llevas dinero de productos.' : 'Se anota que cobraste ' . $owed . ' de los productos.' ) . '</p>';
		}
		if ( ! $a['prepaid'] ) {
			$html .= '<details class="card other"' . ( $unknown || 'needs_amount' === $notice ? ' open' : '' ) . '><summary>Cobré otra cantidad</summary><form method="post" action="' . $action . '" data-once-form><input type="hidden" name="cvd_entregado_ok" value="1"><input type="hidden" name="cvd_otra" value="1"><p class="muted left">Solo lo de los productos, sin tu mensajería.</p>';
			$html .= '<label><span>USD</span><input name="cvd_usd" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0"></label><label><span>CUP</span><input name="cvd_cup" type="number" inputmode="decimal" min="0" step="1" placeholder="0"></label>';
			if ( $payment && 'EUR' === $payment['currency'] ) { $html .= '<label><span>EUR</span><input name="cvd_eur" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0"></label>'; }
			$html .= '<button class="btn big" type="submit" data-once>✅ Entregado con esta cantidad</button></form></details>';
		}
		return $html . '<p class="muted">También puedes escribirle al bot <b>entregado</b>, como siempre.</p>';
	}

	/** Lo que hay que cobrar al cliente, en una línea. */
	private static function charge_text( WC_Order $order, ?array $payment, array $a, string $owed ): string {
		$fee = class_exists( 'CVD_Shipping_Rates' ) ? (int) CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		$fee_text = $fee ? ' + ' . self::money( (float) $fee, 'CUP' ) . ' de tu mensajería' : ' (mensajería: por confirmar con la tienda)';
		if ( $a['prepaid'] ) { return 'Ya pagado por ' . $payment['label'] . ': solo tu mensajería' . ( $fee ? ', ' . self::money( (float) $fee, 'CUP' ) : '' ); }
		if ( '' === $owed ) { return ( $payment ? $payment['text'] : 'Productos' ) . $fee_text; }
		$how = $payment ? ( 'transfer_cup' === $payment['kind'] ? ' por transferencia a la tarjeta de Casa Viva' : ( 'clasica' === $payment['kind'] ? ' por Tarjeta Clásica' : ' · ' . $payment['label'] ) ) : '';
		return $owed . $how . $fee_text;
	}

	/** Armado y vuelto, si los hay (lo mismo que el vale). */
	private static function extra_lines( WC_Order $order ): array {
		$lines = array();
		$assembly = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
		if ( $assembly ) { $lines[] = '🔧 Armado: cobras ' . self::money( (float) $assembly['usd'], 'USD' ) . ' aparte'; }
		$change = class_exists( 'CVD_Bot_Bridge' ) ? CVD_Bot_Bridge::change_label( $order ) : '';
		if ( '' !== $change ) { $lines[] = '💱 Vuelto: ' . $change; }
		return $lines;
	}

	private static function shell( string $title, string $body ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' · Casa Viva</title><style>' . self::css() . '</style></head><body><main>' . $body . '</main><script>' . self::js() . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body ya escapado al armarlo.
		exit;
	}

	private static function css(): string {
		return ':root{--ink:#25231f;--muted:#6f6a62;--line:#e8e1d8;--bg:#f6f1e6;--card:#fffdf9;--brand:#0f3d3a;--good:#2f7a4f;--bad:#9a392f}'
			. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:17px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:520px;margin:0 auto;padding:16px 16px 40px}'
			. 'h1{font-size:26px;line-height:1.2;margin:2px 0 14px}.kick{margin:0;color:var(--muted);font-size:14px}'
			. '.card{background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:14px}.row{padding:11px 14px;border-top:1px solid var(--line)}.row:first-child{border-top:0}.row small{display:block;color:var(--muted);font-size:13px}.row b{font-size:19px}'
			. '.btn{display:block;width:100%;border:0;border-radius:18px;background:var(--good);color:#fff;font:inherit;font-weight:800;font-size:24px;padding:22px 16px;min-height:72px;cursor:pointer}.btn[disabled]{opacity:.6}'
			. '.other summary{padding:13px 14px;font-weight:700;cursor:pointer}.other form{padding:0 14px 14px}.other label{display:block;margin-bottom:10px}.other label span{display:block;font-weight:700;font-size:14px;margin-bottom:4px}.other input{width:100%;font:inherit;font-size:20px;padding:11px;border:1px solid #cdb8ad;border-radius:11px;min-height:48px}.other .btn{font-size:19px;min-height:60px;padding:16px}'
			. '.muted{color:var(--muted);font-size:14px;text-align:center;margin:10px 0 16px}.muted.left{text-align:left;margin:4px 0 10px}'
			. '.ok{background:#e7f4ec;border:1px solid #b9dcc6;color:#1f5a37;border-radius:14px;padding:16px;margin-bottom:12px;font-weight:700;font-size:19px}.err{background:#fff0ed;border:1px solid #f0c4bb;color:var(--bad);border-radius:12px;padding:12px;margin-bottom:12px}'
			. '.box{background:var(--card);border-radius:14px;padding:20px;margin-top:20px}';
	}

	/** No enviar dos veces: al primer toque el botón se apaga y dice «Guardando…». */
	private static function js(): string {
		return '(function(){document.querySelectorAll("[data-once-form]").forEach(function(f){f.addEventListener("submit",function(){var b=f.querySelector("[data-once]");if(b){setTimeout(function(){b.disabled=true;b.textContent="Guardando…"},0)}})})})();';
	}
}
