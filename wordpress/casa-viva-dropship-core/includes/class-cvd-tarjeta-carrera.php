<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.54 (D103, mejora 6 del diagnóstico del 23-oct, aprobada por Ernesto): «Tarjeta de carrera» del mensajero.
 *
 * Al coger una carrera, el vale lleva UN enlace firmado por pedido y mensajero (?cvd_carrera=ID&t=…, 48 h, sin
 * contraseña) a una página ligera para el teléfono con todo lo de ese pedido, leído del pedido ACTUAL de Woo (#51):
 * cliente, teléfono (Llamar), dirección y mapa, qué recoger y dónde, cuánto cobrar y cuánto traer a la tienda con el
 * vuelto de la tienda sumado (#56), y 4 botones grandes:
 *   - Recogí       → CVD_Delivery::messenger_confirms_pickup (lo mismo que «lo tengo» por WhatsApp).
 *   - Entregado    → CVD_Bot_Bridge::mark_delivered_by_messenger (lo mismo que «entregado» y el enlace del vale), con lo cobrado.
 *   - Problema     → CVD_Order_Transition_Service::open_incident (incidencia de mensajería, la etapa no cambia).
 *   - Ya no puedo  → CVD_Bot_Bridge::release_by_messenger (lo mismo que «suelto»: vuelve a ser oferta y el bot la
 *                    vuelve a anunciar en el grupo de Mensajerías con su vuelta de siempre).
 * Ningún estado nuevo: todo pasa por los caminos de Core que ya existen (CVD_Order_Transition_Service por debajo).
 *
 * - Firma: la misma de CVD_Messenger_Delivered_Link::sign/verify (HMAC con la sal de WordPress), con otra clave
 *   (…|cvd-tarjeta-carrera): un enlace de la tarjeta no sirve como enlace «Entregado» ni al revés.
 * - Abrir el enlace (o la vista previa de WhatsApp) no cambia nada: solo los botones (POST). Tras cada botón se vuelve
 *   con GET (recargar no repite) y un doble toque da «ya estaba».
 * - Pedidos de PRUEBA: la página se ve entera con una franja «PRUEBA», pero ningún botón cambia nada (como el resto
 *   del reparto: un pedido de prueba nunca se mueve).
 * - Avisos: tras cada botón que cambió algo, Core deja el aviso ya escrito (para la dependienta de turno y, si el
 *   pedido es de una gestora, para ella) en el pedido (_cvd_carrera_avisos). VivaBot lo recoge con
 *   GET /bot/carrera-avisos, lo mete en el buzón y confirma con POST /bot/carrera-avisos/{pedido}/done.
 *   El «entregado» a la gestora sigue saliendo por /bot/gestora/finished (no se repite aquí).
 */
final class CVD_Tarjeta_Carrera {
	public const PARAM = 'cvd_carrera';
	public const TTL = 172800; // 48 h
	public const AVISOS_META = '_cvd_carrera_avisos';
	public const PENDING_META = '_cvd_carrera_aviso_pending';
	private const NS = 'casa-viva/v1';
	/** Estados en los que el vale lleva la tarjeta. */
	private const LINKABLE = array( 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'incident' );
	/** Motivos del botón «Problema» (lo que ve el mensajero). */
	public const PROBLEMS = array(
		'no_estaba' => 'El cliente no estaba',
		'parte'     => 'Solo quiso una parte',
		'devuelve'  => 'Lo devuelve',
		'vale_mal'  => 'El vale está mal',
		'otro'      => 'Otro problema',
	);

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/carrera-avisos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pending_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/carrera-avisos/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'done_request' ), 'permission_callback' => $auth ) );
	}

	// ------------------------------------------------------------------ Enlace firmado

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-tarjeta-carrera'; }

	/** Enlace de la tarjeta para ese pedido y ese mensajero (sin mirar el estado). */
	public static function link_for( int $order_id, int $messenger_id, ?int $now = null, array $extra = array() ): string {
		$token = CVD_Messenger_Delivered_Link::sign( $order_id, $messenger_id, ( $now ?? time() ) + self::TTL, self::key() );
		return self::link( $order_id, $token, $extra );
	}

	private static function link( int $order_id, string $token, array $extra = array() ): string {
		return add_query_arg( array_merge( array( self::PARAM => $order_id, 't' => $token ), $extra ), home_url( '/' ) );
	}

	/** El mensajero del enlace, o 0 si la firma no vale, es de otro pedido o caducó. */
	public static function verify( int $order_id, string $token, ?int $now = null ): int {
		return CVD_Messenger_Delivered_Link::verify( $order_id, $token, $now ?? time(), self::key() );
	}

	/** Enlace para el vale, o '' si no toca (sin mensajero, recogida en tienda, de prueba o ya terminado). */
	public static function url( WC_Order $order, ?int $now = null ): string {
		$messenger = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		if ( ! $messenger || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return ''; }
		if ( self::is_test( $order ) ) { return ''; } // el vale de un pedido de prueba nunca sale
		if ( ! in_array( CVD_Delivery::status( $order ), self::LINKABLE, true ) ) { return ''; }
		return self::link_for( $order->get_id(), $messenger, $now );
	}

	public static function is_test( WC_Order $order ): bool {
		return CVD_Delivery::is_test_order( $order ) || CVD_Delivery::looks_like_test_order( $order );
	}

	// ------------------------------------------------------------------ En qué paso está (solo lectura)

	/**
	 * Paso del pedido para este mensajero. Nunca escribe.
	 * pickup (va a recoger) | route (ya lo tiene) | delivered | incident | cancelled | closed | failed | other (ya no es suyo)
	 */
	public static function stage( WC_Order $order, int $messenger_id ): string {
		$status = CVD_Delivery::status( $order );
		if ( 'cancelled' === $status || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ), true ) ) { return 'cancelled'; }
		if ( 'closed' === $status ) { return 'closed'; }
		if ( $messenger_id !== absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) ) { return 'other'; }
		if ( in_array( $status, array( 'delivered', 'cash_returned' ), true ) ) { return 'delivered'; }
		if ( 'completed' === $order->get_status() ) { return 'closed'; }
		if ( 'incident' === $status ) { return 'incident'; }
		if ( in_array( $status, array( 'failed', 'returned' ), true ) ) { return 'failed'; }
		if ( in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ) { return 'pickup'; }
		if ( in_array( $status, array( 'picked_up', 'handed_over' ), true ) ) { return 'route'; }
		return 'other';
	}

	/** Qué botones valen en cada paso. */
	public static function allowed( string $stage, string $action ): bool {
		$map = array(
			'recogi'    => array( 'pickup' ),
			'entregado' => array( 'pickup', 'route' ),
			'problema'  => array( 'pickup', 'route', 'delivered' ),
			'suelto'    => array( 'pickup' ),
		);
		return in_array( $stage, $map[ $action ] ?? array(), true );
	}

	// ------------------------------------------------------------------ Dinero (misma cuenta que el vale y el bot)

	/**
	 * Vuelto que la tienda le da al mensajero para el cliente (_cvd_change_required: [{amount, currency}], o el formato
	 * viejo 'yes' + _cvd_change_amount/_cvd_change_currency). Vuelve a la tienda con el dinero de los productos (#4271).
	 * → ['USD' => x, 'CUP' => y, 'EUR' => z] solo con lo que haya.
	 */
	public static function store_change( WC_Order $order ): array {
		$raw = $order->get_meta( '_cvd_change_required', true );
		if ( is_string( $raw ) && '' !== $raw && '[' === $raw[0] ) { $decoded = json_decode( $raw, true ); $raw = is_array( $decoded ) ? $decoded : $raw; }
		$lines = array();
		if ( is_array( $raw ) ) { $lines = $raw; }
		elseif ( 'yes' === $raw ) { $lines = array( array( 'amount' => $order->get_meta( '_cvd_change_amount', true ), 'currency' => $order->get_meta( '_cvd_change_currency', true ) ) ); }
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) { continue; }
			$amount = round( (float) ( $line['amount'] ?? 0 ), 2 );
			$currency = strtoupper( (string) ( $line['currency'] ?? 'USD' ) ) ?: 'USD';
			if ( $amount > 0 && in_array( $currency, array( 'USD', 'CUP', 'EUR' ), true ) ) { $out[ $currency ] = ( $out[ $currency ] ?? 0 ) + $amount; }
		}
		return $out;
	}

	/** «36 USD + 2.000 CUP» (vacío si todo es 0). */
	public static function money_text( array $by_currency ): string {
		$parts = array();
		foreach ( array( 'USD', 'CUP', 'EUR' ) as $currency ) {
			$amount = (float) ( $by_currency[ $currency ] ?? 0 );
			if ( $amount > 0 ) { $parts[] = CVD_Messenger_Delivered_Link::money( $amount, $currency ); }
		}
		return implode( ' + ', $parts );
	}

	/** Suma por moneda. */
	public static function add( array $a, array $b ): array {
		foreach ( $b as $currency => $amount ) { $a[ $currency ] = round( (float) ( $a[ $currency ] ?? 0 ) + (float) $amount, 2 ); }
		return $a;
	}

	/**
	 * Todo el dinero de la carrera, ANTES de entregar: qué cobrar al cliente y qué traer a la tienda.
	 * → { payment, prepaid, transfer, unknown, kind, products:[cur=>x], fee, assembly, change:[cur=>x], bring:[cur=>x], pay_with }
	 *   bring = productos que entran en efectivo + vuelto de la tienda (la mensajería y el armado son del mensajero).
	 */
	public static function money_plan( WC_Order $order ): array {
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		$a = CVD_Messenger_Delivered_Link::default_amounts( $payment, (float) $order->get_total() );
		$kind = $payment ? (string) $payment['kind'] : '';
		$products = array_filter( array( 'USD' => $a['usd'], 'CUP' => $a['cup'], 'EUR' => $a['eur'] ), static fn( $v ): bool => (float) $v > 0 );
		$transfer = ! $a['prepaid'] && 'transfer' === $a['method'];
		$change = self::store_change( $order );
		$fee = class_exists( 'CVD_Shipping_Rates' ) ? (int) CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
		$assembly = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
		$pay = $order->get_meta( '_cvd_pay_with', true );
		$pay_with = is_array( $pay ) && (float) ( $pay['amount'] ?? 0 ) > 0 ? CVD_Messenger_Delivered_Link::money( (float) $pay['amount'], strtoupper( (string) ( $pay['currency'] ?? 'USD' ) ) ) : '';
		return array(
			'payment'  => $payment,
			'prepaid'  => (bool) $a['prepaid'],
			'transfer' => $transfer,
			'unknown'  => ! $a['prepaid'] && ! $products, // CUP sin tasa: el mensajero escribe la cifra
			'kind'     => $kind,
			'products' => $products,
			'fee'      => $fee,
			'assembly' => $assembly,
			'change'   => $change,
			'bring'    => self::add( $a['prepaid'] || $transfer ? array() : $products, $change ),
			'pay_with' => $pay_with,
		);
	}

	/** Lo que el mensajero trae a la tienda DESPUÉS de entregar (lo que quedó anotado + el vuelto de la tienda). */
	public static function bring_after( WC_Order $order ): array {
		$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		$products = array();
		$prepaid = $payment && ! empty( $payment['prepaid'] );
		if ( ! $prepaid && 'transfer' !== (string) $order->get_meta( '_cvd_collection_method', true ) ) {
			$products = array_filter( array(
				'USD' => (float) $order->get_meta( '_cvd_collection_amount_usd', true ),
				'CUP' => (float) $order->get_meta( '_cvd_collection_amount_cup', true ),
				'EUR' => (float) $order->get_meta( '_cvd_collection_amount_eur', true ),
			), static fn( float $v ): bool => $v > 0 );
		}
		return array( 'products' => $products, 'change' => self::store_change( $order ), 'bring' => self::add( $products, self::store_change( $order ) ), 'prepaid' => $prepaid );
	}

	/** «Lleva 40 USD a la tienda (30 USD de productos + 10 USD del vuelto que te dio la tienda) antes de las 4:00 p. m.» */
	public static function bring_sentence( array $after, int $when ): string {
		$bring = self::money_text( $after['bring'] );
		if ( '' === $bring ) { return $after['prepaid'] ? 'Los productos ya estaban pagados: no llevas dinero de productos a la tienda.' : 'No llevas dinero de productos a la tienda.'; }
		$detail = '';
		if ( $after['change'] ) {
			$detail = ' (' . ( $after['products'] ? self::money_text( $after['products'] ) . ' de productos + ' : '' ) . self::money_text( $after['change'] ) . ' del vuelto que te dio la tienda)';
		}
		return 'Lleva ' . $bring . ' a la tienda' . $detail . ' ' . CVD_Messenger_Delivered_Link::deadline( $when ) . '.';
	}

	// ------------------------------------------------------------------ Los botones

	/**
	 * Un toque. $action: recogi | entregado | problema | suelto. $post: lo del formulario.
	 * → picked | already_picked | delivered | already | needs_amount | problem | problem_already | problem_empty |
	 *   released | too_late | test | not_now | error
	 */
	public static function submit( WC_Order $order, WP_User $messenger, string $action, array $post ): string {
		if ( self::is_test( $order ) ) { return 'test'; }
		$stage = self::stage( $order, $messenger->ID );
		if ( 'entregado' === $action && 'delivered' === $stage ) { return 'already'; }
		if ( 'recogi' === $action && 'route' === $stage ) { return 'already_picked'; }
		if ( 'problema' === $action && 'incident' === $stage ) { return 'problem_already'; }
		if ( ! self::allowed( $stage, $action ) ) { return 'not_now'; }
		$id = $order->get_id();

		if ( 'recogi' === $action ) {
			$r = CVD_Delivery::messenger_confirms_pickup( $order, $messenger, 'cvd_tarjeta_carrera', 'tocó «Recogí» en su tarjeta de carrera' );
			if ( 'already' === $r ) { return 'already_picked'; }
			if ( 'picked_up' !== $r ) { return 'error'; }
			self::queue( wc_get_order( $id ), 'picked_up', $messenger );
			return 'picked';
		}

		if ( 'entregado' === $action ) {
			$a = CVD_Messenger_Delivered_Link::default_amounts( class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null, (float) $order->get_total() );
			$method = $a['method'];
			if ( ! empty( $post['cvd_otra'] ) && ! $a['prepaid'] ) {
				$a['usd'] = self::amount( $post['cvd_usd'] ?? 0 );
				$a['cup'] = self::amount( $post['cvd_cup'] ?? 0 );
				$a['eur'] = self::amount( $post['cvd_eur'] ?? 0 );
				$method = 'transferencia' === sanitize_key( (string) ( $post['cvd_como'] ?? '' ) ) ? 'transfer' : '';
			}
			$r = CVD_Bot_Bridge::mark_delivered_by_messenger( $order, $messenger, $a['usd'], $a['cup'], $a['eur'], $method, 'card' );
			$code = (string) ( $r['result'] ?? '' );
			if ( 'delivered' === $code ) { self::queue( wc_get_order( $id ), 'delivered', $messenger ); return 'delivered'; }
			if ( in_array( $code, array( 'already', 'needs_amount' ), true ) ) { return $code; }
			self::note_failure( $id, $messenger, '«Entregado»', (string) ( $r['code'] ?? $code ) );
			return 'error';
		}

		if ( 'problema' === $action ) {
			$reason = sanitize_key( (string) ( $post['cvd_motivo'] ?? '' ) );
			$text = trim( sanitize_textarea_field( (string) ( $post['cvd_texto'] ?? '' ) ) );
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 400 ) : substr( $text, 0, 400 );
			if ( ! isset( self::PROBLEMS[ $reason ] ) || ( 'otro' === $reason && strlen( $text ) < 3 ) ) { return 'problem_empty'; }
			$note = 'Mensajero (tarjeta de carrera): ' . self::PROBLEMS[ $reason ] . ( '' !== $text ? '. ' . $text : '' );
			$nonce = preg_replace( '/[^a-zA-Z0-9]/', '', (string) ( $post['cvd_n'] ?? '' ) );
			$r = CVD_Order_Transition_Service::open_incident( $id, 'delivery', array(
				'actor_user_id'   => $messenger->ID,
				'note'            => $note,
				'idempotency_key' => 'tarjeta-problema:' . $id . ':' . $messenger->ID . ':' . ( '' !== $nonce ? $nonce : md5( $note ) ),
			) );
			if ( empty( $r['success'] ) ) { self::note_failure( $id, $messenger, '«Problema»', (string) ( $r['error_code'] ?? '' ) ); return 'error'; }
			if ( ! empty( $r['idempotent_replay'] ) ) { return 'problem_already'; }
			$fresh = wc_get_order( $id );
			$fresh->add_order_note( '⚠️ ' . $messenger->display_name . ' avisó un problema en su tarjeta de carrera: ' . self::PROBLEMS[ $reason ] . ( '' !== $text ? ' — «' . $text . '»' : '' ) . '.' );
			$fresh->save();
			self::queue( wc_get_order( $id ), 'problem', $messenger, array( 'reason' => $reason, 'label' => self::PROBLEMS[ $reason ], 'text' => $text ) );
			return 'problem';
		}

		if ( 'suelto' === $action ) {
			if ( empty( $post['cvd_seguro'] ) ) { return 'not_now'; }
			$r = CVD_Bot_Bridge::release_by_messenger( $order, $messenger, 'tocó «Ya no puedo» en su tarjeta de carrera', 'cvd_tarjeta_carrera', 'card' );
			$code = (string) ( $r['result'] ?? '' );
			if ( 'released' === $code ) { self::queue( wc_get_order( $id ), 'released', $messenger ); return 'released'; }
			if ( 'too_late' === $code ) { return 'too_late'; }
			self::note_failure( $id, $messenger, '«Ya no puedo»', (string) ( $r['code'] ?? $code ) );
			return 'error';
		}
		return 'not_now';
	}

	private static function amount( $value ): float {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return max( 0.0, round( (float) wc_format_decimal( $value, 2 ), 2 ) );
	}

	private static function note_failure( int $order_id, WP_User $messenger, string $button, string $code ): void {
		$fresh = wc_get_order( $order_id );
		if ( ! $fresh ) { return; }
		$fresh->add_order_note( $messenger->display_name . ' tocó ' . $button . ' en su tarjeta de carrera, pero no se pudo guardar (' . ( '' !== $code ? $code : 'error' ) . '). Revísalo con él.' );
		$fresh->save();
	}

	// ------------------------------------------------------------------ Avisos para VivaBot (textos exactos)

	private static function first( string $name ): string { $name = trim( $name ); return '' !== $name ? (string) strtok( $name, ' ' ) : ''; }

	/** Teléfono del cliente del pedido ACTUAL (#51: si la gestora lo corrigió, vale el de ahora): facturación, envío o el verificado. */
	public static function customer_phone( WC_Order $order ): string {
		foreach ( array( $order->get_billing_phone(), $order->get_shipping_phone(), (string) $order->get_meta( '_cvd_whatsapp_verified', true ) ) as $raw ) {
			$digits = preg_replace( '/\D+/', '', (string) $raw );
			if ( strlen( $digits ) >= 8 ) { return 8 === strlen( $digits ) ? '53' . $digits : $digits; }
		}
		return '';
	}

	/** Los mensajes de cada botón, ya escritos. role: tienda (la de turno) | gestora. */
	public static function messages( WC_Order $order, string $kind, WP_User $messenger, array $extra = array() ): array {
		$n = '#' . $order->get_order_number();
		$m = (string) $messenger->display_name;
		$m_phone = class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $messenger->ID ) : '';
		$m_call = '' !== $m_phone ? ' (📞 +' . ( 8 === strlen( $m_phone ) ? '53' . $m_phone : $m_phone ) . ')' : '';
		$client = self::first( (string) $order->get_billing_first_name() );
		$client_txt = '' !== $client ? ' (cliente: ' . $client . ')' : '';
		$gestora_id = 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? absint( $order->get_meta( '_cvd_owner_user_id', true ) ) : 0;
		$gestora = $gestora_id ? get_userdata( $gestora_id ) : null;
		$g_phone = $gestora_id && class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $gestora_id ) : '';
		$g_name = $gestora ? (string) $gestora->display_name : (string) $order->get_meta( '_cvd_owner_display_name', true );
		$out = array();
		$store = static function ( string $body ) use ( &$out ): void { $out[] = array( 'role' => 'tienda', 'name' => 'tienda de turno', 'phone' => '', 'body' => $body ); };
		$to_gestora = static function ( string $body ) use ( &$out, $g_phone, $g_name ): void { if ( strlen( $g_phone ) >= 8 ) { $out[] = array( 'role' => 'gestora', 'name' => $g_name, 'phone' => 8 === strlen( $g_phone ) ? '53' . $g_phone : $g_phone, 'body' => $body ); } };

		if ( 'picked_up' === $kind ) {
			$store( '🛵 ' . $n . ': ' . $m . ' ya tiene el paquete (tocó «Recogí» en su tarjeta). Quedó como «Entregado al mensajero»; no tienes que marcarlo.' );
			// 3.14.72.2 (Ernesto 28-oct, «como Mandao»): con el enlace del mapa para seguirlo (o pasárselo a su cliente).
			$to_gestora( '🛵 Tu pedido *' . $n . '*' . $client_txt . ' ya salió de la tienda con ' . $m . '.' . ( class_exists( 'CVD_Delivery' ) ? "\nSíguelo en el mapa (puedes pasárselo a tu cliente): " . CVD_Delivery::tracking_url( $order ) : '' ) );
		} elseif ( 'delivered' === $kind ) {
			$after = self::bring_after( $order );
			$owed = self::money_text( $after['products'] );
			$change = self::money_text( $after['change'] );
			$payment = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
			if ( $after['prepaid'] ) {
				$what = 'pagado por adelantado' . ( $payment ? ' (' . $payment['label'] . ')' : '' ) . ': no trae dinero de productos' . ( '' !== $change ? '; devuelve ' . $change . ' del vuelto de la tienda' : '' ) . '.';
			} elseif ( 'transfer' === (string) $order->get_meta( '_cvd_collection_method', true ) ) {
				$paid = self::money_text( array( 'USD' => (float) $order->get_meta( '_cvd_collection_amount_usd', true ), 'CUP' => (float) $order->get_meta( '_cvd_collection_amount_cup', true ) ) );
				$what = 'pagó por transferencia' . ( '' !== $paid ? ' ' . $paid : '' ) . ( $payment && 'transfer_cup' === $payment['kind'] ? ' (a la tarjeta de Casa Viva: comprueba que llegó)' : '' ) . ( '' !== $change ? '; devuelve ' . $change . ' del vuelto de la tienda' : '' ) . '.';
			} else {
				$what = 'trae ' . ( '' !== $owed ? $owed : 'lo cobrado' ) . ' de productos' . ( '' !== $change ? ' + ' . $change . ' del vuelto de la tienda = *' . self::money_text( $after['bring'] ) . '*' : '' ) . '. Falta recibir el dinero en tienda.';
			}
			$store( '✅ ' . $n . ' entregado por ' . $m . ' (tarjeta de carrera): ' . $what );
		} elseif ( 'problem' === $kind ) {
			$label = (string) ( $extra['label'] ?? 'Problema' );
			$text = (string) ( $extra['text'] ?? '' );
			$store( '⚠️🛵 ' . $n . ': ' . $m . $m_call . ' avisa un problema en la entrega: *' . $label . '*' . ( '' !== $text ? ' — «' . $text . '»' : '' ) . '. Llámale y dile qué hacer; el pedido queda con incidencia (no se marca entregado).' );
			$to_gestora( '⚠️ Tu pedido *' . $n . '*' . $client_txt . ': el mensajero avisa «' . $label . '»' . ( '' !== $text ? ' (' . $text . ')' : '' ) . '. La tienda ya lo está viendo; habla con tu cliente y dile a la tienda qué hacer.' );
		} elseif ( 'released' === $kind ) {
			$store( '🔁 ' . $n . ': ' . $m . ' ya no puede llevarlo (tocó «Ya no puedo» en su tarjeta). Vuelve a salir en el grupo de Mensajerías para otro mensajero.' );
			$to_gestora( '🔁 Tu pedido *' . $n . '*' . $client_txt . ': ' . $m . ' ya no puede llevarlo. Lo estamos pasando a otro mensajero.' );
		} elseif ( 'arrived' === $kind ) {
			// 3.14.71.2 (Ernesto 28-oct, «Llegué» de Pa'llá): solo a la gestora, para que llame a su cliente si no abre
			// (Pedri estuvo una hora sin localizar a un cliente). A la tienda no le hace falta.
			$to_gestora( '📍 Tu pedido *' . $n . '*' . $client_txt . ': ' . $m . ' ya está en casa de tu cliente. Si no le abre, llámalo.' );
		}
		return $out;
	}

	/** 3.14.71.2: «Llegué» (botón o automático a 120 m del cliente en Pa'llá). Una sola vez por carrera. → true si se apuntó. */
	public static function arrived( WC_Order $order, WP_User $messenger, string $how ): bool {
		if ( self::is_test( $order ) || '' !== (string) $order->get_meta( '_cvd_llegue_at', true ) ) { return false; }
		if ( 'route' !== self::stage( $order, $messenger->ID ) ) { return false; }
		$order->update_meta_data( '_cvd_llegue_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_cvd_llegue_how', 'auto' === $how ? 'auto' : 'boton' );
		$order->add_order_note( '📍 ' . $messenger->display_name . ' llegó a casa del cliente (' . ( 'auto' === $how ? 'su GPS, a menos de 120 m' : 'tocó «Llegué»' ) . ').' );
		self::queue( $order, 'arrived', $messenger );
		return true;
	}

	/** Apunta el aviso del botón en el pedido (pendiente hasta el «done» del bot). */
	private static function queue( $order, string $kind, WP_User $messenger, array $extra = array() ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$list = $order->get_meta( self::AVISOS_META, true );
		$list = is_array( $list ) ? $list : array();
		$list[] = array(
			'id'        => substr( $kind, 0, 1 ) . gmdate( 'ymdHis' ) . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 6 ),
			'kind'      => $kind,
			'at'        => current_time( 'mysql', true ),
			'test'      => self::is_test( $order ),
			'messenger' => array( 'id' => $messenger->ID, 'name' => $messenger->display_name, 'phone' => class_exists( 'CVD_Sales' ) ? CVD_Sales::user_phone( $messenger->ID ) : '' ),
			'extra'     => $extra,
			'messages'  => self::messages( $order, $kind, $messenger, $extra ),
			'notified'  => '',
		);
		$order->update_meta_data( self::AVISOS_META, array_slice( $list, -20 ) );
		$order->update_meta_data( self::PENDING_META, '1' );
		$order->save();
	}

	/** Avisos sin mandar (de los últimos 3 días), con el texto listo. */
	public static function pending_request(): WP_REST_Response {
		$orders = wc_get_orders( array( 'limit' => 50, 'type' => 'shop_order', 'orderby' => 'modified', 'order' => 'DESC', 'status' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ), 'meta_key' => self::PENDING_META, 'meta_value' => '1' ) );
		$floor = time() - 3 * DAY_IN_SECONDS;
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			foreach ( (array) $order->get_meta( self::AVISOS_META, true ) as $event ) {
				if ( ! is_array( $event ) || '' !== (string) ( $event['notified'] ?? '' ) ) { continue; }
				if ( strtotime( (string) $event['at'] . ' UTC' ) < $floor ) { continue; }
				$out[] = array(
					'event'     => (string) $event['id'],
					'kind'      => (string) $event['kind'],
					'at'        => (string) $event['at'],
					'test'      => ! empty( $event['test'] ) || self::is_test( $order ),
					'orderId'   => $order->get_id(),
					'number'    => (string) $order->get_order_number(),
					'messenger' => (array) ( $event['messenger'] ?? array() ),
					'extra'     => (array) ( $event['extra'] ?? array() ),
					'messages'  => array_values( (array) ( $event['messages'] ?? array() ) ),
				);
			}
		}
		$response = rest_ensure_response( array( 'avisos' => $out ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** El bot ya avisó (o lo saltó). {event, result: sent|skipped, to: [...]}. Repetirlo no cambia nada. */
	public static function done_request( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_order_not_found', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		$params = (array) $request->get_json_params();
		$id = sanitize_text_field( (string) ( $params['event'] ?? '' ) );
		$result = 'skipped' === ( $params['result'] ?? '' ) ? 'skipped' : 'sent';
		$to = array_slice( array_map( 'sanitize_text_field', (array) ( $params['to'] ?? array() ) ), 0, 5 );
		$list = $order->get_meta( self::AVISOS_META, true );
		$list = is_array( $list ) ? $list : array();
		$found = false; $already = false; $pending = false;
		foreach ( $list as $i => $event ) {
			if ( ! is_array( $event ) ) { continue; }
			if ( (string) ( $event['id'] ?? '' ) === $id ) {
				$found = true;
				if ( '' !== (string) ( $event['notified'] ?? '' ) ) { $already = true; continue; }
				$list[ $i ]['notified'] = current_time( 'mysql', true );
				$list[ $i ]['result'] = $result;
				$list[ $i ]['to'] = $to;
			} elseif ( '' === (string) ( $event['notified'] ?? '' ) ) {
				$pending = true;
			}
		}
		if ( ! $found ) { return new WP_Error( 'cvd_aviso_not_found', 'Ese aviso no existe en el pedido.', array( 'status' => 404 ) ); }
		if ( ! $already ) {
			$order->update_meta_data( self::AVISOS_META, $list );
			if ( $pending ) { $order->update_meta_data( self::PENDING_META, '1' ); } else { $order->delete_meta_data( self::PENDING_META ); }
			if ( 'sent' === $result ) { $order->add_order_note( 'VivaBot avisó por WhatsApp lo de la tarjeta de carrera' . ( $to ? ' a: ' . implode( ', ', $to ) : '' ) . '.' ); }
			$order->save();
		}
		$response = rest_ensure_response( array( 'ok' => true, 'already' => $already ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// ------------------------------------------------------------------ La página

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: tarjeta de carrera del mensajero (privado)' );
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
		$messenger_id = $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ? self::verify( $order_id, $token ) : 0;
		$messenger = $messenger_id ? get_userdata( $messenger_id ) : false;
		if ( ! $messenger instanceof WP_User ) {
			status_header( 403 );
			self::shell( 'Enlace no válido', '<div class="box"><h1>Este enlace ya no vale</h1><p>Caducó (dura 48 horas) o no es de este pedido. Escríbele al bot o avisa a la tienda.</p></div>' );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['cvd_accion'] ) ) {
			$action = sanitize_key( (string) wp_unslash( $_POST['cvd_accion'] ) );
			$code = self::submit( $order, $messenger, $action, wp_unslash( $_POST ) );
			// Volver con GET: recargar la página no reenvía el formulario.
			wp_safe_redirect( self::link( $order_id, $token, array( 'hecho' => $code ) ) . '#aviso', 303 );
			exit;
		}
		$notice = sanitize_key( (string) ( $_GET['hecho'] ?? '' ) );
		self::shell( 'Pedido #' . $order->get_order_number(), self::body( $order, $messenger, $token, $notice ) );
	}

	/** Avisos tras un botón: [clase, texto]. */
	private static function notice_text( string $code, WC_Order $order ): array {
		$texts = array(
			'picked'          => array( 'ok', '✅ Anotado: ya tienes el paquete. Ahora llévalo al cliente.' ),
			'already_picked'  => array( 'ok', 'Ya constaba que tienes el paquete.' ),
			'delivered'       => array( 'ok', '✅ ¡Entregado! La mensajería es tuya.' ),
			'already'         => array( 'ok', 'Ya constaba como entregado.' ),
			'needs_amount'    => array( 'err', 'Escribe cuánto cobraste de los productos (USD o CUP) y toca otra vez.' ),
			'problem'         => array( 'warn', '⚠️ Avisado. La tienda ya lo sabe y te llama o te escribe. No lo marques entregado hasta que te digan.' ),
			'problem_already' => array( 'warn', 'Ya había un problema avisado en este pedido. La tienda lo está viendo.' ),
			'problem_empty'   => array( 'err', 'Elige qué pasó (y si es «Otro problema», escríbelo en una frase) y toca otra vez.' ),
			'released'        => array( 'ok', 'Listo, soltaste la carrera. Vuelve al grupo para otro mensajero. ¡Gracias por avisar!' ),
			'too_late'        => array( 'err', 'Ya tienes el paquete: no se puede soltar. Si no puedes entregarlo, toca «Problema».' ),
			'test'            => array( 'warn', 'Pedido de PRUEBA: no se cambió nada.' ),
			'not_now'         => array( 'err', 'Eso ya no se puede en este paso del pedido.' ),
			'error'           => array( 'err', 'No se pudo guardar ahora. Toca otra vez en un momento o escríbele al bot.' ),
		);
		return $texts[ $code ] ?? array( '', '' );
	}

	private const STAGE_TEXT = array(
		'cancelled' => array( 'Pedido cancelado', 'Este pedido está cancelado. No lo lleves. Si ya lo tienes, devuélvelo a la tienda.' ),
		'closed'    => array( 'Pedido cerrado ✅', 'Este pedido ya está cerrado. No hay que hacer nada más.' ),
		'other'     => array( 'Ya no es tuyo', 'Este pedido ya no está a tu nombre (lo tiene otro mensajero o volvió al grupo). No tienes que hacer nada.' ),
		'failed'    => array( 'No entregado o devuelto', 'Este pedido consta como no entregado o devuelto. Si tienes dudas, avisa a la tienda.' ),
	);

	private static function body( WC_Order $order, WP_User $messenger, string $token, string $notice ): string {
		$number = (string) $order->get_order_number();
		$first = self::first( (string) $messenger->display_name );
		$stage = self::stage( $order, $messenger->ID );
		$test = self::is_test( $order );
		$html = '<header class="top"><span class="brand">CASA VIVA</span><span class="num">Pedido #' . esc_html( $number ) . '</span></header>';
		if ( $test ) { $html .= '<div class="test">PRUEBA · este pedido es de prueba: los botones no cambian nada</div>'; }
		$html .= '<p class="hi">' . ( '' !== $first ? 'Hola, ' . esc_html( $first ) . ' · ' : '' ) . 'tu carrera</p>';
		[ $class, $text ] = self::notice_text( $notice, $order );
		if ( 'delivered' === $notice || ( 'already' === $notice && 'delivered' === $stage ) ) { $text .= ' ' . self::bring_sentence( self::bring_after( $order ), self::delivered_ts( $order ) ); }
		if ( '' !== $text ) { $html .= '<div id="aviso" class="note ' . esc_attr( $class ) . '" role="status">' . esc_html( $text ) . '</div>'; }

		if ( isset( self::STAGE_TEXT[ $stage ] ) ) {
			[ $title, $explain ] = self::STAGE_TEXT[ $stage ];
			return $html . '<div class="box"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $explain ) . '</p></div>';
		}

		$html .= self::steps( $stage );
		$html .= self::customer_card( $order );
		$html .= self::pickup_card( $order, $stage );
		$html .= self::money_card( $order, $stage );
		$html .= self::actions( $order, $stage, $token );
		return $html . '<p class="foot">También puedes escribirle al bot como siempre. Este enlace es solo tuyo y dura 48 horas.</p>';
	}

	private static function delivered_ts( WC_Order $order ): int {
		$at = (string) $order->get_meta( '_cvd_delivered_at', true );
		$ts = '' !== $at ? strtotime( $at . ' UTC' ) : false;
		return $ts ?: time();
	}

	private static function steps( string $stage ): string {
		$current = array( 'pickup' => 0, 'route' => 1, 'incident' => 1, 'delivered' => 2 )[ $stage ] ?? 0;
		$labels = array( 'Recoger', 'Llevar', 'Entregado' );
		$html = '<ol class="steps">';
		foreach ( $labels as $i => $label ) {
			$html .= '<li class="' . ( $i < $current ? 'done' : ( $i === $current ? 'now' : '' ) ) . '"><span>' . ( $i + 1 ) . '</span>' . esc_html( $label ) . '</li>';
		}
		$html .= '</ol>';
		if ( 'incident' === $stage ) { $html .= '<div class="note warn">⚠️ Este pedido tiene un problema avisado. La tienda te dice qué hacer; no lo marques entregado hasta entonces.</div>'; }
		return $html;
	}

	private static function phone_label( string $digits ): string {
		return class_exists( 'CVD_Sales' ) ? CVD_Sales::phone_label( $digits ) : '+' . $digits;
	}

	private static function customer_card( WC_Order $order ): string {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = trim( implode( ', ', array_filter( array( $order->get_shipping_address_1() ?: $order->get_billing_address_1(), $order->get_shipping_address_2() ?: $order->get_billing_address_2() ) ) ) );
		$zone = CVD_Delivery::destination_zone( $order );
		$reference = trim( (string) $order->get_meta( '_cvd_reference', true ) );
		$phone = self::customer_phone( $order );
		$alt = preg_replace( '/\D+/', '', (string) $order->get_meta( '_cvd_alternate_phone', true ) );
		$alt = 8 === strlen( $alt ) ? '53' . $alt : $alt;
		$map = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_map_url( $order ) : '';
		$when = class_exists( 'CVD_Delivery_When' ) ? (string) ( CVD_Delivery_When::for_order( $order )['label'] ?? '' ) : '';
		$note = trim( wp_strip_all_tags( (string) $order->get_customer_note() ) );
		$html = '<section class="card"><h2>📍 Entregar a</h2>';
		$html .= '<p class="big">' . esc_html( '' !== $name ? $name : 'Cliente' ) . '</p>';
		$html .= '<p class="addr">' . esc_html( implode( ' · ', array_filter( array( $address, $zone ) ) ) ) . '</p>';
		if ( '' !== $reference ) { $html .= '<p class="ref">🧭 ' . esc_html( $reference ) . '</p>'; }
		if ( '' !== $when ) { $html .= '<p class="ref">🗓️ ' . esc_html( $when ) . '</p>'; }
		if ( '' !== $note ) { $html .= '<p class="ref">📝 ' . esc_html( $note ) . '</p>'; }
		$html .= '<div class="duo">';
		$html .= '' !== $phone ? '<a class="btn call" href="tel:+' . esc_attr( $phone ) . '">📞 Llamar<small>' . esc_html( self::phone_label( $phone ) ) . '</small></a>' : '<span class="btn off">Sin teléfono</span>';
		$html .= '' !== $map ? '<a class="btn map" href="' . esc_url( $map ) . '" target="_blank" rel="noopener noreferrer">🗺️ Abrir en el mapa</a>' : '<span class="btn off">Sin mapa<small>usa la dirección</small></span>';
		$html .= '</div>';
		if ( strlen( $alt ) >= 8 && substr( $alt, -8 ) !== substr( $phone, -8 ) ) { $html .= '<p class="ref">Otro teléfono: <a href="tel:+' . esc_attr( $alt ) . '">' . esc_html( self::phone_label( $alt ) ) . '</a></p>'; }
		return $html . '</section>';
	}

	private static function pickup_card( WC_Order $order, string $stage ): string {
		$supplier = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product && class_exists( 'CVD_Costs' ) && CVD_Costs::is_bajo_pedido( $product ) ) {
				$name = (string) $product->get_meta( '_cvd_supplier_name', true );
				if ( '' === $name && $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); $name = $parent ? (string) $parent->get_meta( '_cvd_supplier_name', true ) : ''; }
				$supplier[] = '' !== $name ? $name : 'el proveedor';
			}
		}
		$hours = (string) get_option( 'cvd_tarjeta_recogida_horas', 'de 10:00 a. m. a 4:00 p. m.' );
		$html = '<section class="card"><h2>📦 ' . ( 'pickup' === $stage ? 'Qué recoger y dónde' : 'Lo que llevas' ) . '</h2><ul class="items">';
		foreach ( CVD_Order_Lines::texts( $order ) as $line ) { $html .= '<li>' . esc_html( $line ) . '</li>'; }
		$html .= '</ul>';
		if ( 'pickup' === $stage ) {
			$html .= '<p class="where"><b>🏪 Tienda Casa Viva</b><br>Calle Conill A esq. 45 #864, Nuevo Vedado<br>Recogida ' . esc_html( $hours ) . '</p>';
			if ( $supplier ) { $html .= '<p class="ref">🚚 Bajo pedido (' . esc_html( implode( ', ', array_unique( $supplier ) ) ) . '): la tienda te dice si lo recoges en la tienda o en el proveedor. Pregunta antes de salir.</p>'; }
		}
		$assembly = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_assembly( $order ) : null;
		if ( $assembly ) { $html .= '<p class="ref">🔧 Hay que ARMARLO: cobras ' . esc_html( CVD_Messenger_Delivered_Link::money( (float) $assembly['usd'], 'USD' ) ) . ' de armado aparte (es tuyo).</p>'; }
		return $html . '</section>';
	}

	private static function money_card( WC_Order $order, string $stage ): string {
		$plan = self::money_plan( $order );
		$payment = $plan['payment'];
		$fee = (int) $plan['fee'];
		$fee_text = $fee > 0 ? CVD_Messenger_Delivered_Link::money( (float) $fee, 'CUP' ) : 'por confirmar con la tienda';
		$html = '<section class="card money"><h2>💵 Dinero</h2>';
		if ( 'delivered' === $stage ) {
			return $html . '<div class="bring"><small>Traes a la tienda</small><b>' . esc_html( self::money_text( self::bring_after( $order )['bring'] ) ?: 'Nada de productos' ) . '</b><span>' . esc_html( CVD_Messenger_Delivered_Link::deadline( self::delivered_ts( $order ) ) ) . '</span></div></section>';
		}
		if ( $plan['prepaid'] ) {
			$charge = 'Nada de productos: ya pagó por ' . ( $payment ? $payment['label'] : 'adelantado' ) . '.';
		} elseif ( $plan['unknown'] ) {
			$charge = ( $payment ? $payment['text'] : 'Productos' ) . ' — pregunta la tasa en la tienda.';
		} else {
			$products = self::money_text( $plan['products'] );
			$how = array( 'transfer_cup' => ' por transferencia a la tarjeta de Casa Viva (pide la captura)', 'clasica' => ' por Tarjeta Clásica en el momento (pide la captura)', 'cash_usd' => ' en efectivo', 'cash_cup' => ' en efectivo', 'eur' => ' en efectivo' )[ $plan['kind'] ] ?? '';
			$charge = $products . $how;
		}
		$html .= '<div class="row"><small>Cobra al cliente</small><b>' . esc_html( $charge ) . '</b></div>';
		$html .= '<div class="row"><small>Tu mensajería (es tuya)</small><b>' . esc_html( $fee_text ) . '</b></div>';
		if ( $plan['assembly'] ) { $html .= '<div class="row"><small>Armado (es tuyo)</small><b>' . esc_html( CVD_Messenger_Delivered_Link::money( (float) $plan['assembly']['usd'], 'USD' ) ) . '</b></div>'; }
		if ( $plan['change'] ) {
			$html .= '<div class="row"><small>Vuelto</small><b>La tienda te da ' . esc_html( self::money_text( $plan['change'] ) ) . ' de vuelto para el cliente' . ( '' !== $plan['pay_with'] ? ' (paga con ' . esc_html( $plan['pay_with'] ) . ')' : '' ) . '</b></div>';
		}
		$bring = self::money_text( $plan['bring'] );
		if ( $plan['unknown'] ) { $bring = 'Lo que cobres de productos' . ( $plan['change'] ? ' + ' . self::money_text( $plan['change'] ) . ' del vuelto' : '' ); }
		$cash_products = $plan['products'] && ! $plan['transfer'] && ! $plan['prepaid'];
		$detail = $plan['change'] && ! $plan['unknown'] ? ( $cash_products ? self::money_text( $plan['products'] ) . ' de productos + ' : '' ) . self::money_text( $plan['change'] ) . ' del vuelto que te dio la tienda' : '';
		$html .= '<div class="bring"><small>Traes a la tienda</small><b>' . esc_html( '' !== $bring ? $bring : 'Nada de productos' ) . '</b>' . ( '' !== $detail ? '<span>' . esc_html( $detail ) . '</span>' : '' ) . '<span>' . esc_html( '' !== $bring ? 'Antes de las 4:00 p. m. (si entregas después de las 4, al otro día antes de las 4:00 p. m.)' : 'La mensajería es tuya.' ) . '</span></div>';
		return $html . '</section>';
	}

	private static function form_open( WC_Order $order, string $token, string $action, string $extra_class = '' ): string {
		return '<form method="post" action="' . esc_url( self::link( $order->get_id(), $token ) ) . '" class="' . esc_attr( trim( 'f ' . $extra_class ) ) . '" data-once-form><input type="hidden" name="cvd_accion" value="' . esc_attr( $action ) . '">';
	}

	private static function actions( WC_Order $order, string $stage, string $token ): string {
		$html = '<section class="actions">';
		// 1. Recogí (con un problema abierto no se enseña: la tienda dice qué hacer)
		if ( 'incident' === $stage ) {
			$html .= '';
		} elseif ( self::allowed( $stage, 'recogi' ) ) {
			$html .= self::form_open( $order, $token, 'recogi' ) . '<button class="act pick" type="submit" data-once>📦 Recogí<small>ya tengo el paquete</small></button></form>';
		} else {
			$html .= '<div class="act pick done">📦 Recogí ✓<small>ya tienes el paquete</small></div>';
		}
		// 2. Entregado
		if ( self::allowed( $stage, 'entregado' ) && 'incident' !== $stage ) {
			$plan = self::money_plan( $order );
			$owed = self::money_text( $plan['products'] );
			$html .= '<details class="drop"><summary class="act ok">✅ Entregado<small>confirma lo que cobraste</small></summary><div class="panel">';
			if ( ! $plan['unknown'] ) {
				$said = $plan['prepaid'] ? 'Ya estaba pagado: no cobré productos.' : ( $plan['transfer'] ? 'El cliente transfirió ' . $owed . '.' : 'Cobré ' . $owed . ' de los productos.' );
				$html .= self::form_open( $order, $token, 'entregado' ) . '<p class="q">' . esc_html( $said ) . '</p><button class="btn go" type="submit" data-once>Sí, entregado</button></form>';
			}
			if ( ! $plan['prepaid'] ) {
				$cur = $plan['payment'] ? (string) $plan['payment']['currency'] : 'USD';
				$html .= '<details class="other"' . ( $plan['unknown'] ? ' open' : '' ) . '><summary>Cobré otra cantidad</summary>' . self::form_open( $order, $token, 'entregado' ) . '<input type="hidden" name="cvd_otra" value="1"><p class="muted">Solo lo de los productos, sin tu mensajería.</p>';
				$html .= '<label><span>USD</span><input name="cvd_usd" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0"></label><label><span>CUP</span><input name="cvd_cup" type="number" inputmode="decimal" min="0" step="1" placeholder="0"></label>';
				if ( 'EUR' === $cur ) { $html .= '<label><span>EUR</span><input name="cvd_eur" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0"></label>'; }
				$transfer_default = $plan['transfer'];
				$html .= '<fieldset class="how"><legend>¿Cómo pagó?</legend><label class="pill"><input type="radio" name="cvd_como" value="efectivo"' . ( $transfer_default ? '' : ' checked' ) . '> Efectivo</label><label class="pill"><input type="radio" name="cvd_como" value="transferencia"' . ( $transfer_default ? ' checked' : '' ) . '> Transferencia</label></fieldset>';
				$html .= '<button class="btn go" type="submit" data-once>Entregado con esta cantidad</button></form></details>';
			}
			$html .= '</div></details>';
		} elseif ( 'delivered' === $stage ) {
			$html .= '<div class="act ok done">✅ Entregado ✓<small>ya quedó anotado</small></div>';
		}
		// 3. Problema
		if ( self::allowed( $stage, 'problema' ) ) {
			$html .= '<details class="drop"><summary class="act warn">⚠️ Problema<small>no estaba, solo una parte, devuelve…</small></summary><div class="panel">' . self::form_open( $order, $token, 'problema' ) . '<input type="hidden" name="cvd_n" value="' . esc_attr( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) ) . '">';
			foreach ( self::PROBLEMS as $key => $label ) { $html .= '<label class="opt"><input type="radio" name="cvd_motivo" value="' . esc_attr( $key ) . '" required> ' . esc_html( $label ) . '</label>'; }
			$html .= '<label class="txt"><span>Cuéntalo en una frase (si quieres)</span><textarea name="cvd_texto" rows="2" maxlength="400"></textarea></label><button class="btn warnb" type="submit" data-once>Avisar a la tienda</button></form></div></details>';
		}
		// 4. Ya no puedo
		if ( self::allowed( $stage, 'suelto' ) ) {
			$html .= '<details class="drop"><summary class="act no">✋ Ya no puedo<small>suelto la carrera</small></summary><div class="panel">' . self::form_open( $order, $token, 'suelto' ) . '<input type="hidden" name="cvd_seguro" value="1"><p class="q">La carrera vuelve al grupo para otro mensajero.</p><button class="btn nob" type="submit" data-once>Sí, la suelto</button></form></div></details>';
		} elseif ( in_array( $stage, array( 'route', 'incident' ), true ) ) {
			$html .= '<p class="muted">Ya tienes el paquete: si no puedes entregarlo, toca «Problema».</p>';
		}
		return $html . '</section>';
	}

	private static function shell( string $title, string $body ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#2B2420"><title>' . esc_html( $title ) . ' · Casa Viva</title><style>' . self::css() . '</style></head><body><main>' . $body . '</main><script>' . self::js() . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body ya escapado al armarlo.
		exit;
	}

	private static function css(): string {
		$font = esc_url( CVD_URL . 'assets/fonts/' );
		return '@font-face{font-family:Figtree;font-weight:400;font-display:swap;src:url(' . $font . 'figtree-latin-400.woff2) format("woff2")}@font-face{font-family:Figtree;font-weight:600;font-display:swap;src:url(' . $font . 'figtree-latin-600.woff2) format("woff2")}@font-face{font-family:Cinzel;font-weight:600;font-display:swap;src:url(' . $font . 'cinzel-latin-600.woff2) format("woff2")}'
			. ':root{--crema:#F6F1E6;--marron:#2B2420;--petroleo:#0D3B45;--ambar:#A35A1F;--card:#FFFDF8;--line:#E6DCCB;--muted:#6E6358;--ok:#2F6B4A;--bad:#9A392F}'
			. '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}body{margin:0;background:var(--crema);color:var(--marron);font:17px/1.45 Figtree,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}main{max-width:520px;margin:0 auto;padding:0 14px 40px}'
			. '.top{display:flex;justify-content:space-between;align-items:center;background:var(--marron);color:var(--crema);margin:0 -14px;padding:12px 16px}.brand{font-family:Cinzel,Georgia,serif;font-weight:600;letter-spacing:.12em;font-size:15px}.num{font-weight:600;font-size:15px}'
			. '.test{background:#FFE9A8;color:#5A4300;font-weight:600;font-size:14px;text-align:center;padding:8px;margin:0 -14px}'
			. '.hi{margin:12px 0 8px;color:var(--muted);font-size:15px}h1{font-size:24px;margin:0 0 8px}h2{font-size:14px;letter-spacing:.04em;text-transform:uppercase;color:var(--petroleo);margin:0 0 8px}'
			. '.steps{display:flex;gap:6px;list-style:none;padding:0;margin:0 0 12px}.steps li{flex:1;display:flex;align-items:center;gap:6px;background:#EDE4D4;border-radius:999px;padding:6px 8px;font-size:13px;font-weight:600;color:var(--muted)}.steps li span{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:50%;background:#fff;font-size:12px}.steps li.now{background:var(--petroleo);color:#fff}.steps li.now span{color:var(--petroleo)}.steps li.done{background:#DCE8DF;color:var(--ok)}'
			. '.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px}.big{font-size:21px;font-weight:600;margin:0 0 2px}.addr{margin:0 0 6px;font-size:17px}.ref{margin:6px 0 0;font-size:15px;color:#4A4038}.ref a{color:var(--petroleo);font-weight:600}'
			. '.duo{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.btn{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:58px;border-radius:14px;border:0;font:inherit;font-weight:600;font-size:18px;text-decoration:none;padding:10px;cursor:pointer;width:100%}.btn small{font-weight:400;font-size:13px;opacity:.9}.call{background:var(--petroleo);color:#fff}.map{background:#E3ECEC;color:var(--petroleo)}.off{background:#EFE8DC;color:var(--muted);font-size:15px}'
			. '.items{margin:0;padding-left:18px}.items li{margin:2px 0}.where{margin:10px 0 0;padding:10px;background:#F3ECDF;border-radius:12px;font-size:15px}'
			. '.money .row{padding:8px 0;border-top:1px solid var(--line)}.money .row:first-of-type{border-top:0}.money small{display:block;color:var(--muted);font-size:13px}.money b{font-size:18px}.bring{margin-top:8px;background:var(--marron);color:var(--crema);border-radius:14px;padding:12px 14px}.bring small{display:block;color:#E6D9C2;font-size:13px}.bring b{display:block;font-size:26px;line-height:1.2}.bring span{display:block;font-size:14px;color:#E6D9C2;margin-top:2px}'
			. '.actions{display:grid;gap:10px;margin-top:4px}.actions form{margin:0}.act{display:flex;flex-direction:column;justify-content:center;width:100%;min-height:76px;border:0;border-radius:18px;font:inherit;font-weight:600;font-size:24px;color:#fff;padding:12px 18px;text-align:left;cursor:pointer;list-style:none}.act small{font-weight:400;font-size:14px;opacity:.92}.act::-webkit-details-marker{display:none}'
			. '.pick{background:var(--petroleo)}.ok{background:var(--ok)}.warn{background:var(--ambar)}.no{background:#5B514A}.act.done{opacity:.55}.act[disabled]{opacity:.6}'
			. '.drop[open]>summary{border-radius:18px 18px 0 0}.panel{background:var(--card);border:1px solid var(--line);border-top:0;border-radius:0 0 18px 18px;padding:14px}.q{margin:0 0 10px;font-size:18px;font-weight:600}'
			. '.go{background:var(--ok);color:#fff}.warnb{background:var(--ambar);color:#fff;margin-top:10px}.nob{background:#5B514A;color:#fff}'
			. '.other{margin-top:12px;border-top:1px solid var(--line);padding-top:10px}.other summary{font-weight:600;color:var(--petroleo);cursor:pointer;padding:6px 0}.other label{display:block;margin:8px 0}.other label span,.txt span{display:block;font-weight:600;font-size:14px;margin-bottom:4px}input[type=number],textarea{width:100%;font:inherit;font-size:20px;padding:10px;border:1px solid #CDBBA5;border-radius:12px;min-height:48px;background:#fff}textarea{font-size:17px}'
			. '.how{border:0;padding:0;margin:8px 0 12px;display:flex;gap:8px;flex-wrap:wrap}.how legend{font-weight:600;font-size:14px;margin-bottom:6px;padding:0}.pill{display:flex!important;align-items:center;gap:6px;background:#F3ECDF;border-radius:999px;padding:8px 14px;margin:0!important;font-size:16px}'
			. '.opt{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;font-size:17px;background:#fff}.opt input{width:22px;height:22px}.txt{display:block;margin-top:6px}'
			. '.note{border-radius:14px;padding:12px 14px;margin:0 0 12px;font-weight:600}.note.ok{background:#E4F1E8;color:#1F5537;border:1px solid #B9D9C4}.note.err{background:#FBEDEA;color:var(--bad);border:1px solid #EBC3BA}.note.warn{background:#FBF0E2;color:#7A4214;border:1px solid #E9CFA9}'
			. '.muted{color:var(--muted);font-size:14px;margin:6px 0}.foot{color:var(--muted);font-size:13px;text-align:center;margin:18px 0 0}.box{background:var(--card);border-radius:16px;padding:20px;margin-top:16px;border:1px solid var(--line)}';
	}

	/** Un toque = un envío («Guardando…»), sin conexión avisa antes, y abrir un botón cierra los otros. */
	private static function js(): string {
		return '(function(){var d=document;d.querySelectorAll("[data-once-form]").forEach(function(f){f.addEventListener("submit",function(e){if(navigator.onLine===false){e.preventDefault();alert("Sin conexión. Toca otra vez cuando tengas datos.");return}var b=f.querySelector("[data-once]");if(b){setTimeout(function(){b.disabled=true;b.textContent="Guardando…"},0)}})});var ds=d.querySelectorAll("details.drop");ds.forEach(function(x){x.addEventListener("toggle",function(){if(x.open){ds.forEach(function(y){if(y!==x){y.open=false}})}})})})();';
	}
}
