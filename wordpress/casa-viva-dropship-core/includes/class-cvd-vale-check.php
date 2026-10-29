<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.75 «Validar el vale» (Pilar 4 del plan menos-errores, Ernesto 28-oct; caso #5604: el vale de Melani salió con el
 * teléfono y parte de la dirección de Sissy, #5452, porque la gestora copió los datos del cliente anterior).
 *
 * Core decide; la web de la gestora y VivaBot solo hacen la pregunta. Antes de guardar un vale:
 *  - Teléfono plausible: 8 cifras (con o sin 53 delante), no todo igual, no escalera, no empieza por 0 ni 1. Si no, se rechaza.
 *  - Aviso (hay que confirmar con «Sí, es el mismo»; nada se guarda antes):
 *      phone_other_client   el teléfono ya salió (90 días) con OTRO nombre y era un cliente SUYO → dice el nombre de pila.
 *      phone_other_gestora  igual, pero el otro pedido era de otra gestora o de la tienda → sin nombre y sin decir cuál (como D120).
 *      phone_team           es el teléfono de otra gestora o del equipo → sin nombre.
 *      address_copied       la dirección es igual (o ≥ 75 % de las palabras de la más corta) a la de su cliente anterior con otro teléfono.
 *  - Si el teléfono ya salió con el MISMO nombre (cliente conocido) no se avisa de nada.
 *  - El teléfono propio sigue en «Es para mí» (3.14.53, CVD_Cuban_Checkout::own_phone_match).
 * Interruptor: opción cvd_vale_check = no (apaga TODO esto). Rutas: POST /bot/vale/revisar (VivaBot) y /vale/revisar (web).
 */
final class CVD_Vale_Check {
	public const OPTION = 'cvd_vale_check';
	public const WINDOW_DAYS = 90;
	public const ADDRESS_DAYS = 7;
	public const CONFIRM_FIELD = 'cvd_vale_check_ok';
	public const CONFIRMED_META = '_cvd_vale_check_confirmed';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function enabled(): bool {
		return 'no' !== get_option( self::OPTION, 'si' );
	}

	public static function routes(): void {
		register_rest_route( 'casa-viva/v1', '/bot/vale/revisar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'bot_review' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
		register_rest_route( 'casa-viva/v1', '/vale/revisar', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'web_review' ), 'permission_callback' => array( __CLASS__, 'can_review' ) ) );
	}

	public static function can_review(): bool {
		return is_user_logged_in() && class_exists( 'CVD_Cuban_Checkout' ) && CVD_Cuban_Checkout::is_seller( get_current_user_id() );
	}

	private static function json( array $data ): WP_REST_Response {
		$r = new WP_REST_Response( $data, 200 );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** Cuerpo común de las dos rutas: customer{phone,name}, address, fulfillment, orderId (para no compararse con él mismo). */
	private static function args_from( array $p, int $gestora_id ): array {
		$customer = (array) ( $p['customer'] ?? array() );
		return array(
			'gestora_id'    => $gestora_id,
			'phone'         => (string) ( $customer['phone'] ?? $p['customerPhone'] ?? '' ),
			'name'          => sanitize_text_field( (string) ( $customer['name'] ?? $p['customerName'] ?? '' ) ),
			'address'       => sanitize_text_field( (string) ( $p['address'] ?? '' ) ),
			'require_phone' => 'pickup' !== sanitize_key( (string) ( $p['fulfillment'] ?? 'delivery' ) ),
			'exclude_order' => absint( $p['orderId'] ?? 0 ),
		);
	}

	public static function bot_review( WP_REST_Request $request ) {
		$gestora = class_exists( 'CVD_Vale_Chat' ) ? CVD_Vale_Chat::gestora_by_phone( (string) $request->get_param( 'phone' ) ) : null;
		if ( ! $gestora ) { return new WP_Error( 'cvd_vale_not_gestora', 'Ese número no es de una gestora aprobada.', array( 'status' => 403 ) ); }
		return self::json( self::review( self::args_from( (array) $request->get_json_params(), $gestora->ID ) ) );
	}

	public static function web_review( WP_REST_Request $request ) {
		return self::json( self::review( self::args_from( (array) $request->get_json_params(), get_current_user_id() ) ) );
	}

	/** 8 cifras sin 53 → array( ok, phone, text ). Vacío es válido solo si $required es false. */
	public static function phone_check( string $raw, bool $required = true ): array {
		$d = (string) preg_replace( '/\D+/', '', $raw );
		if ( '' === $d ) {
			return $required ? array( 'ok' => false, 'phone' => '', 'text' => 'Falta el teléfono del cliente (8 cifras, con o sin +53).' ) : array( 'ok' => true, 'phone' => '', 'text' => '' );
		}
		if ( 10 === strlen( $d ) && 0 === strpos( $d, '53' ) ) { $d = substr( $d, 2 ); }
		if ( strlen( $d ) < 8 ) { return array( 'ok' => false, 'phone' => '', 'text' => 'El teléfono va completo: 8 cifras (con o sin +53).' ); }
		if ( strlen( $d ) > 8 ) { return array( 'ok' => false, 'phone' => '', 'text' => 'El teléfono tiene cifras de más: tiene que ser de 8 (con o sin +53).' ); }
		if ( preg_match( '/^(\d)\1{7}$/', $d ) || in_array( $d, array( '12345678', '87654321', '01234567' ), true ) || in_array( $d[0], array( '0', '1' ), true ) ) {
			return array( 'ok' => false, 'phone' => '', 'text' => 'Ese teléfono no parece de verdad. Revisa que sea el del cliente (8 cifras, un móvil empieza por 5).' );
		}
		return array( 'ok' => true, 'phone' => $d, 'text' => '' );
	}

	/** Primer nombre sin acentos, minúsculas, solo letras. */
	public static function name_key( string $name ): string {
		$name = remove_accents( mb_strtolower( trim( $name ) ) );
		$parts = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		return $parts ? (string) preg_replace( '/[^a-z]/', '', $parts[0] ) : '';
	}

	/** ¿Es la misma persona? Igual, uno empieza por el otro (Mel/Melani) o casi igual (Melani/Melany). */
	public static function same_name( string $a, string $b ): bool {
		$a = self::name_key( $a ); $b = self::name_key( $b );
		if ( '' === $a || '' === $b ) { return false; }
		if ( $a === $b ) { return true; }
		if ( min( strlen( $a ), strlen( $b ) ) >= 3 && ( 0 === strpos( $a, $b ) || 0 === strpos( $b, $a ) ) ) { return true; }
		return levenshtein( $a, $b ) <= ( max( strlen( $a ), strlen( $b ) ) >= 6 ? 2 : 1 );
	}

	private static function order_first_name( WC_Order $order ): string {
		$name = trim( (string) $order->get_billing_first_name() );
		if ( '' === $name ) { $name = trim( (string) $order->get_meta( '_cvd_buyer_name', true ) ); }
		return $name;
	}

	/** Pedidos vivos de los últimos 90 días con ese teléfono (8 cifras o 53 + 8). Los de prueba no cuentan. */
	private static function orders_with_phone( string $phone8, int $exclude ): array {
		$out = array();
		foreach ( array( $phone8, '53' . $phone8 ) as $variant ) {
			$found = wc_get_orders( array(
				'limit' => 20, 'orderby' => 'date', 'order' => 'DESC', 'status' => apply_filters( 'cvd_vale_check_statuses', array( 'processing', 'on-hold', 'completed' ) ),
				'meta_key' => '_cvd_identity_phone', 'meta_value' => hash( 'sha256', $variant ),
				'date_created' => '>' . ( time() - self::WINDOW_DAYS * DAY_IN_SECONDS ),
			) );
			foreach ( (array) $found as $order ) {
				if ( ! $order instanceof WC_Order || $order->get_id() === $exclude || isset( $out[ $order->get_id() ] ) ) { continue; }
				if ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) { continue; }
				$out[ $order->get_id() ] = $order;
			}
		}
		return array_values( $out );
	}

	/** ¿El teléfono es de otra gestora o del equipo? (el de la propia gestora ya lo trata «Es para mí».) */
	private static function team_phone( string $phone8, int $gestora_id ): bool {
		static $keys = null;
		if ( null === $keys ) {
			$keys = array();
			foreach ( get_users( array( 'role__in' => array( 'cvd_gestora', 'cvd_influencer', 'cvd_operator', 'shop_manager', 'administrator' ), 'fields' => 'ID' ) ) as $uid ) {
				foreach ( CVD_Cuban_Checkout::own_phone_keys( (int) $uid ) as $k ) { $keys[ (int) $uid ][] = $k; }
			}
		}
		foreach ( $keys as $uid => $list ) {
			if ( (int) $uid !== $gestora_id && in_array( $phone8, $list, true ) ) { return true; }
		}
		return false;
	}

	/** Palabras que cuentan de una dirección (sin «calle», «entre», «y»…), sin acentos ni signos. */
	public static function address_tokens( string $address ): array {
		$s = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', remove_accents( mb_strtolower( $address ) ) );
		$stop = array( 'calle', 'entre', 'esquina', 'avenida', 'ave', 'apto', 'apartamento', 'edificio', 'casa', 'piso', 'altos', 'bajos', 'numero', 'no', 'reparto', 'municipio', 'frente', 'al', 'el', 'la', 'los', 'las', 'de', 'del', 'con', 'y', 'e', 'a' );
		$tokens = array();
		foreach ( preg_split( '/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( strlen( $w ) < 2 || in_array( $w, $stop, true ) ) { continue; }
			$tokens[ $w ] = true;
		}
		return array_keys( $tokens );
	}

	/** Parecido de dos direcciones: igual, o ≥ 3 palabras en común y ≥ 75 % de las palabras de la más corta (una copia parcial: misma calle y número, otro barrio). */
	public static function address_similar( string $a, string $b ): bool {
		$ta = self::address_tokens( $a ); $tb = self::address_tokens( $b );
		if ( ! $ta || ! $tb ) { return false; }
		$shared = count( array_intersect( $ta, $tb ) );
		if ( count( $ta ) === count( $tb ) && count( $ta ) === $shared ) { return true; }
		return $shared >= 3 && ( $shared / min( count( $ta ), count( $tb ) ) ) >= 0.75;
	}

	/** Dirección del cliente anterior de la gestora (otro teléfono, últimos 7 días) que se parece a esta. → array( order, name ) o null. */
	private static function similar_previous_address( string $address, int $gestora_id, string $phone8, int $exclude ): ?array {
		if ( '' === trim( $address ) || ! $gestora_id || $address === CVD_Cuban_Checkout::ADDRESS_PENDING ) { return null; }
		$orders = wc_get_orders( array(
			'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'status' => apply_filters( 'cvd_vale_check_statuses', array( 'processing', 'on-hold', 'completed' ) ),
			'meta_key' => '_cvd_owner_user_id', 'meta_value' => $gestora_id,
			'date_created' => '>' . ( time() - self::ADDRESS_DAYS * DAY_IN_SECONDS ),
		) );
		$seen = 0;
		foreach ( (array) $orders as $past ) {
			if ( ! $past instanceof WC_Order || $past->get_id() === $exclude ) { continue; }
			if ( 'pickup' === (string) $past->get_meta( '_cvd_fulfillment_type', true ) ) { continue; }
			if ( ++$seen > 3 ) { break; }
			if ( CVD_Cuban_Checkout::phone_key( (string) $past->get_billing_phone() ) === $phone8 ) { continue; } // mismo cliente que repite
			$prev = (string) $past->get_billing_address_1();
			if ( '' !== $prev && $prev !== CVD_Cuban_Checkout::ADDRESS_PENDING && self::address_similar( $address, $prev ) ) {
				return array( 'order' => $past, 'name' => self::order_first_name( $past ) );
			}
		}
		return null;
	}

	/**
	 * Revisa teléfono y dirección de un vale.
	 * @param array $a gestora_id, phone, name, address, require_phone, exclude_order
	 * @return array{ok:bool,error:string,phone:string,warnings:array<int,array{code:string,text:string}>}
	 */
	public static function review( array $a ): array {
		$gestora_id = absint( $a['gestora_id'] ?? 0 );
		$exclude = absint( $a['exclude_order'] ?? 0 );
		$name = trim( (string) ( $a['name'] ?? '' ) );
		$address = trim( (string) ( $a['address'] ?? '' ) );
		$phone = self::phone_check( (string) ( $a['phone'] ?? '' ), ! empty( $a['require_phone'] ) );
		$out = array( 'ok' => $phone['ok'], 'error' => $phone['ok'] ? '' : $phone['text'], 'phone' => $phone['phone'], 'warnings' => array() );
		if ( ! $phone['ok'] ) { return $out; }
		$phone8 = $phone['phone'];
		if ( '' !== $phone8 ) {
			$own = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::own_phone_match( $phone8, array( $gestora_id ) ) : null;
			if ( ! $own ) { // el teléfono propio lo trata «Es para mí»
				if ( self::team_phone( $phone8, $gestora_id ) ) {
					$out['warnings'][] = array( 'code' => 'phone_team', 'text' => 'Ese teléfono es de alguien del equipo de Casa Viva. ¿Seguro que es el de tu cliente?' );
				} elseif ( '' !== $name ) {
					$known = false; $mine = ''; $other = false;
					foreach ( self::orders_with_phone( $phone8, $exclude ) as $past ) {
						$prev_name = self::order_first_name( $past );
						if ( '' === $prev_name ) { continue; }
						if ( self::same_name( $name, $prev_name ) ) { $known = true; break; }
						if ( absint( $past->get_meta( '_cvd_owner_user_id', true ) ) === $gestora_id ) { if ( '' === $mine ) { $mine = $prev_name; } } else { $other = true; }
					}
					if ( ! $known && '' !== $mine ) {
						$out['warnings'][] = array( 'code' => 'phone_other_client', 'text' => 'Este teléfono ya salió con otro cliente (' . self::first_word( $mine ) . '). ¿Es el mismo?' );
					} elseif ( ! $known && $other ) {
						$out['warnings'][] = array( 'code' => 'phone_other_gestora', 'text' => 'Este teléfono ya salió con otro cliente de otra gestora. ¿Es el mismo?' );
					}
				}
			}
		}
		if ( '' !== $address && '' !== $phone8 ) {
			$similar = self::similar_previous_address( $address, $gestora_id, $phone8, $exclude );
			if ( $similar ) {
				$who = '' !== $similar['name'] ? ' (' . self::first_word( $similar['name'] ) . ')' : '';
				$out['warnings'][] = array( 'code' => 'address_copied', 'text' => 'Esta dirección se parece a la de tu cliente anterior' . $who . '. ¿Es la misma?' );
			}
		}
		return $out;
	}

	private static function first_word( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		return $parts ? wp_strip_all_tags( $parts[0] ) : '';
	}

	/**
	 * Puerta para quien guarda el vale. → array( WP_Error|null, advertencias confirmadas ).
	 * $confirmed = la gestora ya contestó «sí, es el mismo». Con el interruptor en «no» no hace nada.
	 */
	public static function gate( array $a, bool $confirmed ): array {
		if ( ! self::enabled() ) { return array( null, array() ); }
		$r = self::review( $a );
		if ( ! $r['ok'] ) { return array( new WP_Error( 'cvd_vale_phone', $r['error'], array( 'status' => 400 ) ), array() ); }
		if ( $r['warnings'] && ! $confirmed ) {
			return array( new WP_Error( 'cvd_vale_dup', implode( ' ', wp_list_pluck( $r['warnings'], 'text' ) ), array( 'status' => 409, 'warnings' => $r['warnings'] ) ), array() );
		}
		return array( null, $r['warnings'] );
	}

	/** Deja constancia en el pedido de lo que la gestora confirmó (la ve Lennys y el vigía). */
	public static function note_confirmed( WC_Order $order, array $warnings, string $where ): void {
		if ( ! $warnings ) { return; }
		$order->update_meta_data( self::CONFIRMED_META, 'yes' );
		$order->add_order_note( 'Vale (' . $where . '): la gestora confirmó que sí — ' . implode( ' ', wp_list_pluck( $warnings, 'text' ) ) );
	}
}
