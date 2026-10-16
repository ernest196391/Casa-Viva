<?php

defined( 'ABSPATH' ) || exit;

/**
 * Avisos de WhatsApp de los pagos de comisiones (3.14.23, Ernesto 15-oct). El mismo camino que los avisos de pedidos
 * cancelados o cambiados (CVD_Order_Avisos): Core deja el aviso pendiente, VivaBot lo recoge con
 * GET /bot/payout-avisos, lo mete en el buzón (crm_outbox) y confirma con POST /bot/payout-avisos/{id}/done.
 * Aquí el texto ya va escrito (el bot no lo inventa) y cada aviso tiene su clave única (pago + tipo + teléfono):
 * aunque se pulse dos veces o el bot reinicie, sale una sola vez.
 *
 * Quién recibe qué:
 *  - Al pedir el pago: Lennys (opción cvd_payout_admin_phones) siempre, con tarjeta y teléfono listos para copiar y el
 *    enlace a «Pagar a…». En EFECTIVO, también la dependienta (opción cvd_payout_cash_phones, por defecto Zaymi).
 *  - Al pagarse: la gestora («✅ Te pagaron…» o «Pasa por la tienda a recoger…»). Si se rechaza, también ella.
 */
final class CVD_Payout_Avisos {
	private const NS = 'casa-viva/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'cvd_payout_requested', array( __CLASS__, 'on_requested' ) );
		add_action( 'cvd_payout_transitioned', array( __CLASS__, 'on_transition' ), 10, 2 );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/payout-avisos', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pending_request' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/bot/payout-avisos/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'done_request' ), 'permission_callback' => $auth ) );
	}

	private static function table(): string { global $wpdb; return $wpdb->prefix . 'cvd_payout_avisos'; }

	/** Teléfonos de una opción (lista o texto con comas) como 53XXXXXXXX, sin repetir. */
	public static function phones( string $option, array $default ): array {
		$raw = get_option( $option, $default );
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out = array();
		foreach ( $list as $phone ) { $p8 = CVD_Payout_Math::phone8( (string) $phone ); if ( $p8 ) { $out[ $p8 ] = '53' . $p8; } }
		return array_values( $out );
	}

	/** La persona de la web con ese WhatsApp (para el nombre en el aviso y quién firmó el pago). */
	public static function person_for_phone( string $phone ): ?WP_User {
		$p8 = CVD_Payout_Math::phone8( $phone );
		if ( ! $p8 ) { return null; }
		$users = get_users( array( 'number' => 5, 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_cvd_whatsapp', 'value' => $p8, 'compare' => 'LIKE' ), array( 'key' => 'billing_phone', 'value' => $p8, 'compare' => 'LIKE' ) ) ) );
		foreach ( $users as $user ) {
			foreach ( array( '_cvd_whatsapp', 'billing_phone' ) as $key ) { if ( CVD_Payout_Math::phone8( (string) get_user_meta( $user->ID, $key, true ) ) === $p8 ) { return $user; } }
		}
		return null;
	}

	private static function first_name( string $phone, string $fallback ): string {
		$user = self::person_for_phone( $phone );
		return $user ? ( strtok( (string) $user->display_name, ' ' ) ?: $user->display_name ) : $fallback;
	}

	/** Apunta un aviso. false si ya existía (mismo pago, tipo y teléfono): no se repite. */
	public static function queue( int $payout_id, string $kind, string $role, string $phone, string $name, string $body, bool $test = false ): bool {
		global $wpdb;
		$p8 = CVD_Payout_Math::phone8( $phone );
		if ( ! $p8 || '' === trim( $body ) ) { return false; }
		$dedupe = hash( 'sha256', $payout_id . '|' . $kind . '|' . $p8 );
		$table = self::table();
		$done = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (payout_id,kind,role,phone,name,body,dedupe,test,created_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%d,%s)",
			$payout_id, $kind, $role, '53' . $p8, mb_substr( $name, 0, 190 ), $body, $dedupe, $test ? 1 : 0, current_time( 'mysql', true )
		) );
		return 1 === (int) $done;
	}

	private static function is_test_owner( int $owner_id ): bool {
		$user = get_userdata( $owner_id );
		return ! $user || '1' === (string) get_user_meta( $owner_id, '_cvd_test_account', true ) || str_contains( (string) $user->user_login, 'piloto' );
	}

	// ---------------------------------------------------------------- Cuándo se avisa

	public static function on_requested( int $payout_id ): void {
		$payout = CVD_Payouts::get( $payout_id );
		if ( ! $payout || 'requested' !== $payout['status'] ) { return; }
		$view = CVD_Payouts::view( $payout );
		$test = self::is_test_owner( $view['owner_id'] );
		$clerks = $view['cash'] ? self::phones( 'cvd_payout_cash_phones', array( '5359163951' ) ) : array();
		$clerk_names = array_map( static fn( string $p ): string => self::first_name( $p, 'la tienda' ), $clerks );
		foreach ( self::phones( 'cvd_payout_admin_phones', array( '5356885368' ) ) as $phone ) {
			$link = CVD_Payout_Pay_Page::signed_url( $payout_id, $phone );
			self::queue( $payout_id, 'requested', 'admin', $phone, self::first_name( $phone, 'Lennys' ), self::text_admin( $view, $link, $clerk_names ), $test );
		}
		foreach ( $clerks as $phone ) {
			$link = CVD_Payout_Pay_Page::signed_url( $payout_id, $phone );
			self::queue( $payout_id, 'requested', 'clerk', $phone, self::first_name( $phone, 'Dependienta' ), self::text_clerk( $view, $link ), $test );
		}
	}

	public static function on_transition( int $payout_id, string $next ): void {
		if ( ! in_array( $next, array( 'paid', 'rejected' ), true ) ) { return; }
		$payout = CVD_Payouts::get( $payout_id );
		if ( ! $payout ) { return; }
		$view = CVD_Payouts::view( $payout );
		$phone = CVD_Payout_Math::phone8( $view['whatsapp'] ) ?: $view['phone'];
		if ( ! $phone ) { return; }
		$body = 'paid' === $next ? self::text_paid( $view, home_url( '/area-gestoras/#pagos' ) ) : self::text_rejected( $view );
		self::queue( $payout_id, $next, 'gestora', $phone, $view['name'], $body, self::is_test_owner( $view['owner_id'] ) );
	}

	// ---------------------------------------------------------------- Textos (exactos; el bot no los cambia)

	private static function summary( array $view ): string {
		$n = count( $view['lines'] );
		$text = CVD_Payout_Math::usd( $view['usd'] ) . ' de ' . $n . ( 1 === $n ? ' pedido' : ' pedidos' );
		if ( 'USD' !== $view['currency'] ) { $text .= ', con la tasa de cada venta'; }
		if ( $view['deduction'] > 0 ) { $text .= '; ya restada la deuda de ' . CVD_Payout_Math::money( $view['deduction'], $view['deduction_currency'] ); }
		return '(' . $text . ')';
	}

	public static function text_admin( array $view, string $link, array $clerk_names = array() ): string {
		if ( 'transfer' === $view['kind'] ) {
			$lines = array(
				'💸 *' . $view['name'] . ' pide su comisión*',
				'Transferencia: *' . $view['pay_text'] . '*',
				self::summary( $view ),
				'',
				'Tarjeta:',
				$view['card'] ? CVD_Payout_Math::card_pretty( $view['card'] ) : ( $view['account'] ?: '(no la puso)' ),
				'Teléfono:',
				$view['phone'] ?: '(no lo puso)',
				'',
				'Para pagar y subir la captura:',
				$link,
			);
			return implode( "\n", $lines );
		}
		if ( $view['cash'] ) {
			$who = $clerk_names ? implode( ' y ', array_unique( $clerk_names ) ) : 'la tienda';
			return implode( "\n", array(
				'💵 *' . $view['name'] . ' pide su comisión en efectivo*',
				'Hay que darle: *' . $view['pay_text'] . '*',
				self::summary( $view ),
				'Ya le avisé a ' . $who . ' para que se lo dé en la tienda. Si allí falta dinero, mándalo tú.',
				'',
				'Ver el detalle:',
				$link,
			) );
		}
		return implode( "\n", array(
			'💸 *' . $view['name'] . ' pide su comisión*',
			'Total: *' . $view['pay_text'] . '*',
			'Dónde cobra: ' . ( $view['account'] ?: '(no lo puso)' ),
			'',
			'Para pagar:',
			$link,
		) );
	}

	public static function text_clerk( array $view, string $link ): string {
		return implode( "\n", array(
			'💵 *Pago a una gestora*',
			$view['name'] . ' viene a la tienda a cobrar su comisión.',
			'Dale: *' . $view['pay_text'] . '* en efectivo',
			'Su teléfono: ' . ( $view['phone'] ?: CVD_Payout_Math::phone8( $view['whatsapp'] ) ),
			'Si no hay dinero suficiente en la tienda, avísale a Lennys.',
			'',
			'Cuando tengas el dinero listo, entra aquí y toca «Dinero listo»:',
			$link,
		) );
	}

	public static function text_paid( array $view, string $area ): string {
		if ( $view['cash'] ) {
			return implode( "\n", array(
				'💵 *Tu pago está listo: ' . $view['pay_text'] . '* en efectivo.',
				'Pasa por la tienda a recogerlo. Cuando lo tengas en la mano, toca «Recibido» en tu área:',
				$area,
			) );
		}
		if ( 'other' === $view['kind'] ) {
			return '✅ *Te pagaron ' . $view['pay_text'] . '*.' . "
" . 'Lo tienes en tu área:' . "
" . $area;
		}
		$card = $view['card'] ? ' a tu tarjeta terminada en ' . substr( $view['card'], -4 ) : '';
		return implode( "\n", array(
			'✅ *Te pagaron ' . $view['pay_text'] . '* por transferencia' . $card . '.',
			'La captura está en tu área:',
			$area,
		) );
	}

	public static function text_rejected( array $view ): string {
		return 'Tu pedido de pago #' . $view['id'] . ' (' . $view['pay_text'] . ') no se aprobó. Ese dinero sigue en tu saldo.' . "\n" . 'Si tienes dudas, escribe a Casa Viva.';
	}

	// ---------------------------------------------------------------- Para el bot

	/** Avisos sin mandar (de los últimos 3 días), con el texto listo. */
	public static function pending_request(): WP_REST_Response {
		global $wpdb;
		$table = self::table();
		$floor = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,payout_id,kind,role,phone,name,body,test,created_at FROM {$table} WHERE notified_at IS NULL AND created_at>=%s ORDER BY id ASC LIMIT 50", $floor ), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'id' => (int) $row['id'], 'payoutId' => (int) $row['payout_id'], 'kind' => $row['kind'], 'role' => $row['role'], 'phone' => $row['phone'], 'name' => $row['name'], 'body' => $row['body'], 'test' => (bool) $row['test'], 'at' => $row['created_at'] );
		}
		$response = rest_ensure_response( array( 'avisos' => $out ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** El bot ya lo mandó (o lo saltó). {result: sent|skipped, note}. Repetirlo no cambia nada. */
	public static function done_request( WP_REST_Request $request ) {
		global $wpdb;
		$params = (array) $request->get_json_params();
		$result = 'skipped' === ( $params['result'] ?? '' ) ? 'skipped' : 'sent';
		$note = mb_substr( sanitize_text_field( (string) ( $params['note'] ?? '' ) ), 0, 190 );
		$id = absint( $request['id'] );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE id=%d', $id ) );
		if ( ! $exists ) { return new WP_Error( 'cvd_aviso_not_found', 'Aviso no encontrado.', array( 'status' => 404 ) ); }
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET notified_at=%s,result=%s,note=%s WHERE id=%d AND notified_at IS NULL', current_time( 'mysql', true ), $result, $note, $id ) );
		$response = rest_ensure_response( array( 'ok' => true, 'already' => 1 !== (int) $updated ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
