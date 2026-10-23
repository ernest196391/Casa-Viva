<?php

defined( 'ABSPATH' ) || exit;

/** Atribución persistente de clientes usando pedidos WooCommerce como fuente. */
final class CVD_Attribution {
	private const COOKIE_NAME = 'cvd_referral';
	private const SESSION_KEY = 'cvd_referral';
	/**
	 * 3.14.6 (Thaly, 14-oct, D54): segunda marca del navegador, solo para las cachés. Delante de LiteSpeed está la caché de
	 * Hostinger (hcdn), que guarda las páginas y se las da a todos sin mirar cookies, salvo unas pocas de WordPress y
	 * WooCommerce: si el nombre lleva «wp_woocommerce_session_», pasa de largo. Sin esta marca, el cliente de una gestora
	 * que tocaba Inicio, el logo o un departamento recibía la página guardada con los precios y el WhatsApp de Casa Viva.
	 * No dice qué gestora es (eso lo dice cvd_referral, firmada); solo «este navegador está en la tienda de una gestora».
	 * LiteSpeed se salta su caché con cualquiera de las dos (ajuste «No cachear cookies», ver ESTADO 14-oct).
	 */
	public const BYPASS_COOKIE = 'cvd_wp_woocommerce_session_tienda';
	/**
	 * 3.14.56 (Ernesto 23-oct): «?ref=salir» (o «?ref=casaviva») = salir de la tienda de una gestora. Ernesto abrió en su
	 * móvil el enlace de Harley y la web solo le enseñaba esa tienda (cookie de 400 días) sin forma de volver a la de
	 * Casa Viva. Borra la marca del navegador y lleva a la misma página sin el parámetro. No cambia de quién es un
	 * cliente que ya compró (D97: eso va por su teléfono/correo, no por esta marca).
	 */
	public const LEAVE_CODES = array( 'salir', 'casaviva' );
	private static bool $bypass_checked = false;

	public static function register(): void {
		self::disable_cache_for_referral_request();
		add_filter( 'litespeed_vary_cookies', array( __CLASS__, 'litespeed_vary_cookies' ) );
		add_action( 'init', array( __CLASS__, 'capture_referral' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'capture_referral' ), 1 );
		// 3.14.56: después de que WooCommerce cargue la sesión y el carrito (wp_loaded 5).
		add_action( 'wp_loaded', array( __CLASS__, 'leave_gestora_store' ), 20 );
		add_action( 'template_redirect',array( __CLASS__, 'send_private_cache_headers' ), 0 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_to_order' ), 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'attach_store_api_order' ), 30, 2 );
		// 3.14.52 (D97, fallo 4): pedido creado a mano en el admin de WordPress → al guardarlo, la gestora del cliente.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'attach_admin_order' ), 50 );
	}

	/** Prevent full-page caches from serving Casa Viva prices inside a gestora storefront. */
	private static function disable_cache_for_referral_request(): void {
		if ( isset( $_GET['ref'] ) || isset( $_GET['cv_ref'] ) || ! empty( $_COOKIE[ self::COOKIE_NAME ] ) || ! empty( $_COOKIE[ self::BYPASS_COOKIE ] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}

	public static function send_private_cache_headers(): void {
		if ( self::current_referral_owner() ) {
			nocache_headers();
			do_action( 'litespeed_control_set_nocache', 'Casa Viva gestora storefront' );
		}
	}

	/**
	 * Referral owner for the current browser: the link in this request, else the last saved link.
	 *
	 * 3.13.79 (Ernesto, 13-oct): el último enlace manda. Antes mandaba el primero que se abrió en el
	 * navegador (400 días): Thaly abría SU enlace y veía 30 en vez de su precio de 35 porque su teléfono
	 * guardaba el de otra gestora, y la venta se le habría apuntado a la otra. El cliente que ya compró
	 * sigue siendo de su gestora: eso lo decide resolve_and_attach() por su teléfono/correo (find_existing_owner).
	 */
	public static function current_referral_owner(): ?array {
		$owner = self::owner_from_request();
		return $owner ?: self::owner_from_saved_referral();
	}

	/** Resolve permanent ownership for an authenticated customer before transient referral state. */
	public static function current_customer_owner(): ?array {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$owner = self::customer_owner( '', $user->user_email, $user->ID );
			if ( $owner ) { return $owner; }
		}
		return self::current_referral_owner();
	}

	/**
	 * 3.14.52 (D97, Ernesto 22-oct): la gestora del cliente, PARA SIEMPRE. Una sola regla para la web, el bot, los pedidos
	 * hechos a mano y los vales:
	 * 1. Si el cliente pidió cambiar de gestora y se aprobó (reasignación anotada con quién y cuándo), manda esa.
	 * 2. Si no, la PRIMERA gestora que lo trajo: su primer pedido con gestora que no esté cancelado, fallido ni devuelto
	 *    (aunque aún no se haya entregado), o la que lo apuntó a mano en «Mis clientes» (sin plazo). Gana la más antigua.
	 * El teléfono se compara normalizado (con o sin 53 delante). null = cliente sin gestora (venta directa, sin comisión).
	 * → array( owner_user_id, owner_type, referral_code, source ) o null.
	 */
	public static function customer_owner( string $phone, string $email = '', int $customer_id = 0 ): ?array {
		$lookup = self::lookup_identities( $phone, $email, $customer_id );
		if ( $lookup && class_exists( 'CVD_Attribution_Overrides' ) ) {
			$override = CVD_Attribution_Overrides::override_owner( $lookup );
			if ( $override ) { return $override; }
		}
		$by_order = $lookup ? self::find_existing_owner( $lookup ) : null;
		$claim = class_exists( 'CVD_Clients' ) ? CVD_Clients::protected_owner( $phone ) : null;
		if ( $by_order && $claim ) {
			return (int) $claim['since'] < (int) $by_order['since'] ? $claim : $by_order;
		}
		return $by_order ?: $claim;
	}

	/** Identidades para BUSCAR al cliente: las guardadas en los pedidos + el teléfono con y sin 53 (los pedidos guardan las dos formas). */
	private static function lookup_identities( string $phone, string $email, int $customer_id ): array {
		$identities = self::identities( $phone, $email, $customer_id );
		$digits = preg_replace( '/\D+/', '', $phone );
		$norm = class_exists( 'CVD_Clients' ) ? CVD_Clients::phone( $digits ) : '';
		$seen = array();
		foreach ( $identities as $identity ) { $seen[ $identity['type'] . $identity['hash'] ] = true; }
		foreach ( array_filter( array( $norm, 8 === strlen( $norm ) ? '53' . $norm : '' ) ) as $variant ) {
			$hash = hash( 'sha256', $variant );
			if ( isset( $seen[ 'phone' . $hash ] ) ) { continue; }
			$seen[ 'phone' . $hash ] = true;
			$identities[] = array( 'type' => 'phone', 'hash' => $hash );
		}
		return $identities;
	}

	/** Ensure LiteSpeed never shares one gestora's storefront prices with another visitor. */
	public static function litespeed_vary_cookies( array $cookies ): array {
		$cookies[] = self::COOKIE_NAME;
		$cookies[] = self::BYPASS_COOKIE;
		return array_values( array_unique( $cookies ) );
	}

	public static function capture_referral(): void {
		if ( self::is_leave_request() ) { return; } // 3.14.56: lo atiende leave_gestora_store().
		self::ensure_bypass_cookie();
		$raw_code = '';
		if ( isset( $_GET['ref'] ) ) {
			$raw_code = wp_unslash( $_GET['ref'] );
		} elseif ( isset( $_GET['cv_ref'] ) ) {
			$raw_code = wp_unslash( $_GET['cv_ref'] );
		}

		$code = self::sanitize_code( (string) $raw_code );
		if ( ! $code ) {
			return;
		}

		$owner = self::find_owner_by_code( $code );
		if ( ! $owner ) {
			return;
		}

		// 3.13.79: el último enlace manda (ver current_referral_owner). Si el navegador ya guarda este
		// mismo enlace no se reescribe; si guarda el de otra gestora, se cambia por este. Los clientes
		// que ya compraron siguen con su gestora por su identidad, no por esta marca del navegador.
		$saved_owner = self::owner_from_saved_referral();
		if ( $saved_owner && hash_equals( (string) ( $saved_owner['referral_code'] ?? '' ), $code ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$signed = self::signed_value( $code );
		$days = min( 400, max( 1, absint( get_option( 'cvd_cookie_days', 400 ) ) ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$signed,
				array(
					'expires'  => time() + ( DAY_IN_SECONDS * $days ),
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::COOKIE_NAME ] = $signed;
		self::set_bypass_cookie( $days );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $signed );
		}
	}

	/**
	 * 3.14.6: el navegador que ya guardaba el enlace de una gestora (de antes de esta versión) recibe también la marca para
	 * las cachés la primera vez que llega a PHP (una página con ?ref=, el carrito, la ficha que no estaba guardada…).
	 */
	private static function ensure_bypass_cookie(): void {
		if ( self::$bypass_checked ) { return; }
		self::$bypass_checked = true;
		if ( ! empty( $_COOKIE[ self::BYPASS_COOKIE ] ) || empty( $_COOKIE[ self::COOKIE_NAME ] ) ) { return; }
		if ( ! self::owner_from_saved_referral() ) { return; }
		self::set_bypass_cookie( min( 400, max( 1, absint( get_option( 'cvd_cookie_days', 400 ) ) ) ) );
	}

	private static function set_bypass_cookie( int $days ): void {
		if ( ! headers_sent() ) {
			setcookie(
				self::BYPASS_COOKIE,
				'1',
				array(
					'expires'  => time() + ( DAY_IN_SECONDS * $days ),
					'path'     => COOKIEPATH ?: '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::BYPASS_COOKIE ] = '1';
	}

	/** 3.14.56: ¿esta visita pide salir de la tienda de una gestora (?ref=salir / ?ref=casaviva)? */
	public static function is_leave_request(): bool {
		$raw = isset( $_GET['ref'] ) ? wp_unslash( $_GET['ref'] ) : ( isset( $_GET['cv_ref'] ) ? wp_unslash( $_GET['cv_ref'] ) : '' );
		return is_string( $raw ) && in_array( strtolower( trim( $raw ) ), self::LEAVE_CODES, true );
	}

	/** 3.14.56: enlace para volver a la tienda de Casa Viva desde esta misma página (lo pinta el aviso de la tienda espejo). */
	public static function leave_url(): string {
		$uri = wp_doing_ajax() || ! isset( $_SERVER['REQUEST_URI'] ) ? '/' : (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		return add_query_arg( 'ref', 'salir', remove_query_arg( array( 'ref', 'cv_ref' ), $uri ) );
	}

	/**
	 * 3.14.56: ?ref=salir → borra la marca del navegador (cookie firmada, la de las cachés y la de la sesión de WooCommerce),
	 * devuelve el carrito a los precios de Casa Viva y lleva a la misma dirección sin el parámetro. Respuesta sin caché.
	 */
	public static function leave_gestora_store(): void {
		if ( ! self::is_leave_request() || wp_doing_ajax() || wp_doing_cron() ) { return; }
		self::forget_referral();
		if ( class_exists( 'CVD_Gestora_Store' ) ) { CVD_Gestora_Store::unlock_cart(); }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Casa Viva: salir de la tienda de una gestora' );
		if ( headers_sent() ) { return; }
		nocache_headers();
		$target = remove_query_arg( array( 'ref', 'cv_ref' ), (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		wp_safe_redirect( '' !== $target ? $target : home_url( '/' ), 302, 'Casa Viva' );
		exit;
	}

	private static function forget_referral(): void {
		foreach ( array( self::COOKIE_NAME, self::BYPASS_COOKIE ) as $name ) {
			if ( ! headers_sent() ) {
				setcookie(
					$name,
					'',
					array(
						'expires'  => time() - YEAR_IN_SECONDS,
						'path'     => COOKIEPATH ?: '/',
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			unset( $_COOKIE[ $name ] );
		}
		self::$bypass_checked = true;
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/** Último enlace de gestora guardado en este navegador (cookie firmada o sesión), sin mirar la dirección. */
	public static function saved_referral_owner(): ?array {
		return self::owner_from_saved_referral();
	}

	public static function attach_to_order( WC_Order $order, array $data ): void {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : $order->get_billing_phone();
		$email = isset( $data['billing_email'] ) ? (string) $data['billing_email'] : $order->get_billing_email();
		self::resolve_and_attach( $order, $phone, $email, $order->get_customer_id() );
	}

	/** Adjunta un pedido creado por una operadora sin saltarse el ownership permanente del cliente. */
	public static function attach_operator_order( WC_Order $order, int $preferred_owner_id = 0 ): void {
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id(), $preferred_owner_id );
	}

	public static function attach_store_api_order( WC_Order $order, WP_REST_Request $request ): void {
		unset( $request );
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id() );
	}

	/**
	 * 3.14.52 (D97, fallo 4): pedido creado a mano en el admin de WordPress. Al guardarlo, si aún no tiene atribución,
	 * lleva la gestora del cliente (customer_owner); si el cliente no tiene gestora, venta directa. No mira el navegador
	 * de quien lo crea (su enlace o su carrito no son del cliente). Nada retroactivo: solo pedidos de las últimas 24 h
	 * que no estén cancelados, fallidos ni devueltos; los que ya tienen atribución no se tocan.
	 */
	public static function attach_admin_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || $order->get_meta( '_cvd_attribution_locked', true ) ) { return; }
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) { return; }
		$created = $order->get_date_created();
		if ( ! $created || $created->getTimestamp() < time() - DAY_IN_SECONDS ) { return; }
		self::resolve_and_attach( $order, $order->get_billing_phone(), $order->get_billing_email(), $order->get_customer_id(), 0, true );
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$order->add_order_note( $owner_id ? sprintf( 'Pedido hecho a mano: lleva la gestora del cliente, %s (D97).', (string) $order->get_meta( 'gestora_nombre', true ) ) : 'Pedido hecho a mano: el cliente no tiene gestora → venta directa (D97).' );
		$order->save();
		if ( $owner_id && class_exists( 'CVD_Commissions' ) ) { CVD_Commissions::mark_pending( $order->get_id() ); }
		if ( class_exists( 'CVD_Clients' ) ) { CVD_Clients::from_order( $order ); }
	}

	private static function resolve_and_attach( WC_Order $order, string $phone, string $email, int $customer_id, int $preferred_owner_id = 0, bool $admin_order = false ): void {
		if ( $order->get_meta( '_cvd_attribution_locked', true ) ) {
			return;
		}

		$identities = self::identities( $phone, $email, $customer_id );

		// Permanent customer ownership always wins. This protects a gestora's client
		// when the client later arrives organically, through another link, or when a
		// gestora places the order on the client's behalf.
		// 3.14.52 (D97): la primera gestora del cliente para siempre (pedido no cancelado aunque no esté entregado, o
		// apuntado a mano sin plazo), con el teléfono con o sin 53; ver customer_owner().
		$owner = self::customer_owner( $phone, $email, $customer_id );
		if ( $admin_order ) {
			// Pedido hecho a mano en el admin: sin gestora del cliente, solo vale un cupón de gestora puesto en el pedido.
			$owner = $owner ?: self::owner_from_coupon( $order );
			$preferred_owner_id = 0;
			if ( ! $owner ) {
				$owner = array( 'owner_user_id' => 0, 'owner_type' => 'organic', 'referral_code' => '', 'source' => 'organic' );
			}
		}
		if ( ! $owner && $preferred_owner_id ) {
			$user = get_userdata( $preferred_owner_id );
			$code = self::sanitize_code( (string) get_user_meta( $preferred_owner_id, '_cvd_referral_code', true ) );
			if ( $user && $code && 'approved' === get_user_meta( $preferred_owner_id, '_cvd_account_status', true ) && array_intersect( array( 'cvd_gestora', 'cvd_influencer' ), (array) $user->roles ) ) {
				$owner = array( 'owner_user_id' => $preferred_owner_id, 'owner_type' => in_array( 'cvd_influencer', (array) $user->roles, true ) ? 'influencer' : 'gestora', 'referral_code' => $code, 'source' => 'operator_confirmed_voucher' );
			}
		}
		if ( ! $owner ) {
			$owner = self::owner_from_saved_referral();
		}
		if ( ! $owner ) {
			$owner = self::owner_from_request();
		}
		if ( ! $owner ) {
			$owner = self::owner_from_coupon( $order );
		}
		if ( ! $owner ) {
			$owner = self::owner_from_cart();
		}
		if ( ! $owner ) {
			$owner = array( 'owner_user_id' => 0, 'owner_type' => 'organic', 'referral_code' => '', 'source' => 'organic' );
		}

		$owner_id = absint( $owner['owner_user_id'] );
		$owner_name = $owner_id ? get_the_author_meta( 'display_name', $owner_id ) : 'Casa Viva · Venta directa';
		$referral_code = self::sanitize_code( (string) $owner['referral_code'] );
		$client_label = trim( $order->get_formatted_billing_full_name() );
		$client_label = $client_label ?: ( $phone ?: $email );

		$order->update_meta_data( '_cvd_owner_user_id', $owner_id );
		$order->update_meta_data( '_cvd_owner_type', sanitize_key( $owner['owner_type'] ) );
		$order->update_meta_data( '_cvd_owner_display_name', sanitize_text_field( (string) $owner_name ) );
		$order->update_meta_data( '_cvd_referral_code', $referral_code );
		$order->update_meta_data( '_cvd_attribution_source', sanitize_key( $owner['source'] ) );
		$order->update_meta_data( '_cvd_attribution_locked', 'yes' );
		$order->update_meta_data( '_cvd_referred_at', current_time( 'mysql', true ) );

		foreach ( $identities as $identity ) {
			$order->update_meta_data( '_cvd_identity_' . $identity['type'], $identity['hash'] );
		}

		$order->update_meta_data( 'gestora_id', $owner_id );
		$order->update_meta_data( 'gestora_codigo', $referral_code );
		$order->update_meta_data( 'gestora_nombre', sanitize_text_field( (string) $owner_name ) );
		$order->update_meta_data( 'cliente_vinculado', sanitize_text_field( $client_label ) );
		$order->update_meta_data( 'referido', $owner_id ? 'Sí' : 'No' );
		$order->update_meta_data( 'referido_fecha', current_time( 'mysql', true ) );
	}

	private static function identities( string $phone, string $email, int $customer_id ): array {
		$identities = array();
		$phone = preg_replace( '/\D+/', '', $phone );
		$email = sanitize_email( strtolower( trim( $email ) ) );

		// A gestora can checkout while logged in for a different customer. In that
		// case her own WordPress user ID is an operator identity, not the client.
		$use_customer_identity = $customer_id > 0 && ! self::is_program_operator( $customer_id );
		if ( $use_customer_identity ) {
			$identities[] = array( 'type' => 'customer', 'hash' => hash( 'sha256', (string) $customer_id ) );
		}
		if ( $phone ) {
			$identities[] = array( 'type' => 'phone', 'hash' => hash( 'sha256', $phone ) );
		}
		if ( $email ) {
			$identities[] = array( 'type' => 'email', 'hash' => hash( 'sha256', $email ) );
		}
		return $identities;
	}

	private static function is_program_operator( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return false; }
		$roles = (array) $user->roles;
		return (bool) array_intersect(
			array( 'cvd_gestora', 'cvd_influencer', 'cvd_messenger', 'cvd_clerk', 'cvd_operator', 'administrator', 'shop_manager' ),
			$roles
		);
	}

	/**
	 * 3.13.98 (D53, fallo A; Ernesto 14-oct): ¿este pedido hace al cliente «de» su gestora? Solo si se entregó:
	 * completado, operación «entregado», reparto entregado / dinero devuelto / cerrado, o caja verificada.
	 * Los pedidos en curso siguen siendo de quien los hizo (su atribución no cambia); solo no «atan» al cliente.
	 * 3.14.52 (D97): la atribución ya NO usa esta regla (manda el primer pedido no cancelado, ver find_existing_owner).
	 */
	public static function owns_customer( WC_Order $order ): bool {
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'pending', 'draft', 'checkout-draft', 'trash' ) ) ) { return false; }
		if ( $order->has_status( 'completed' ) ) { return true; }
		if ( 'delivered' === sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ) ) { return true; }
		if ( in_array( sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ), array( 'delivered', 'cash_returned', 'closed' ), true ) ) { return true; }
		return 'verified' === sanitize_key( (string) $order->get_meta( '_cvd_cash_status', true ) );
	}

	/**
	 * Primer pedido con gestora del cliente (el más antiguo entre todas sus identidades). 3.14.52 (D97, fallo 2): ya no
	 * hace falta que esté entregado: basta con que siga vivo (en espera, procesando o completado). Los cancelados,
	 * fallidos, devueltos y sin pagar siguen sin atar (D53). → owner + 'since' (marca de tiempo del pedido) o null.
	 */
	private static function find_existing_owner( array $identities ): ?array {
		$best = null;
		foreach ( $identities as $identity ) {
			$page = 1;
			do {
				$result = wc_get_orders(
					array(
						'limit'      => 20,
						'page'       => $page,
						'paginate'   => true,
						'orderby'    => 'date',
						'order'      => 'ASC',
						'status'     => array( 'processing', 'on-hold', 'completed' ),
						// WooCommerce 8.2 no filtra de forma fiable el meta_query compuesto
						// en todos los motores de almacenamiento. La identidad exacta se
						// consulta como meta_key/meta_value y el owner se valida abajo.
						'meta_key'   => '_cvd_identity_' . $identity['type'],
						'meta_value' => $identity['hash'],
					)
				);

				$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : (array) $result;
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) { continue; }
					// Defensa adicional: aunque el data store ignore accidentalmente el
					// filtro, nunca aceptar una identidad distinta a la solicitada.
					$stored_hash = (string) $order->get_meta( '_cvd_identity_' . $identity['type'], true );
					if ( ! $stored_hash || ! hash_equals( $identity['hash'], $stored_hash ) ) { continue; }
					$owner_user_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
					$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
					if ( ! $owner_user_id || 'organic' === $owner_type ) { continue; }
					$since = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
					if ( ! $best || $since < $best['since'] ) {
						$best = array(
							'owner_user_id' => $owner_user_id,
							'owner_type'    => $owner_type,
							'referral_code' => self::sanitize_code( (string) $order->get_meta( '_cvd_referral_code', true ) ),
							'source'        => 'linked_customer',
							'since'         => $since,
							'order_id'      => $order->get_id(),
						);
					}
					break 2; // pedidos en orden de fecha: el primero válido de esta identidad es su más antiguo
				}

				$max_pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1;
				$page++;
			} while ( $page <= $max_pages );
		}
		return $best;
	}

	private static function owner_from_saved_referral(): ?array {
		$signed = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$signed = (string) WC()->session->get( self::SESSION_KEY, '' );
		}
		if ( ! $signed && ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$signed = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		}
		$code = self::code_from_signed_value( $signed );
		$owner = $code ? self::find_owner_by_code( $code ) : null;
		if ( $owner ) {
			$owner['source'] = 'referral_link';
		}
		return $owner;
	}

	private static function owner_from_request(): ?array {
		$raw_code = isset( $_GET['ref'] ) ? wp_unslash( $_GET['ref'] ) : ( isset( $_GET['cv_ref'] ) ? wp_unslash( $_GET['cv_ref'] ) : '' );
		$code = self::sanitize_code( (string) $raw_code );
		return $code ? self::find_owner_by_code( $code ) : null;
	}

	private static function owner_from_cart(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$owner_ids = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$user_id = absint( $cart_item['_cvd_gestora_id'] ?? 0 );
			if ( $user_id ) { $owner_ids[ $user_id ] = true; }
		}
		if ( 1 !== count( $owner_ids ) ) {
			return null;
		}
		$user_id = (int) array_key_first( $owner_ids );
		return array(
			'owner_user_id' => $user_id,
			'owner_type'    => 'gestora',
			'referral_code' => self::sanitize_code( (string) get_user_meta( $user_id, '_cvd_referral_code', true ) ),
			'source'        => 'cart_snapshot',
		);
	}

	private static function owner_from_coupon( WC_Order $order ): ?array {
		foreach ( $order->get_coupon_codes() as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );
			$user_id = absint( $coupon->get_meta( '_cvd_owner_user_id', true ) );
			if ( $user_id ) {
				return array(
					'owner_user_id' => $user_id,
					'owner_type'    => sanitize_key( $coupon->get_meta( '_cvd_owner_type', true ) ?: 'influencer' ),
					'referral_code' => self::sanitize_code( $coupon_code ),
					'source'        => 'coupon',
				);
			}
			$owner = self::find_owner_by_code( self::sanitize_code( $coupon_code ) );
			if ( $owner ) {
				$owner['source'] = 'coupon';
				return $owner;
			}
		}
		return null;
	}

	private static function find_owner_by_code( string $code ): ?array {
		$users = get_users(
			array(
				'number'     => 1,
				'meta_key'   => '_cvd_referral_code',
				'meta_value' => $code,
				'fields'     => 'all',
			)
		);
		if ( ! $users && preg_match( '/^CV(\d+)([A-Z0-9]{1,8})$/', $code, $matches ) ) {
			$legacy_user = get_user_by( 'id', absint( $matches[1] ) );
			$expected = $legacy_user ? strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $legacy_user->user_login ), 0, 8 ) ) : '';
			if ( $legacy_user && hash_equals( $expected, $matches[2] ) ) {
				$users = array( $legacy_user );
				update_user_meta( $legacy_user->ID, '_cvd_referral_code', $code );
			}
		}
		if ( ! $users ) {
			return null;
		}
		$user = $users[0];
		if ( 'approved' !== get_user_meta( $user->ID, '_cvd_account_status', true ) ) {
			return null;
		}
		return array(
			'owner_user_id' => $user->ID,
			'owner_type'    => in_array( 'cvd_influencer', (array) $user->roles, true ) ? 'influencer' : 'gestora',
			'referral_code' => $code,
			'source'        => 'referral_link',
		);
	}

	private static function signed_value( string $code ): string {
		$issued_at = time();
		$payload = $code . '|' . $issued_at;
		return base64_encode( $payload . '|' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ) );
	}

	private static function code_from_signed_value( string $signed ): string {
		$decoded = base64_decode( $signed, true );
		if ( ! $decoded ) {
			return '';
		}
		$parts = explode( '|', $decoded );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		list( $code, $issued_at, $signature ) = $parts;
		$payload = $code . '|' . $issued_at;
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $signature ) ) {
			return '';
		}
		return self::sanitize_code( $code );
	}

	private static function sanitize_code( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $code ) ) );
	}
}
