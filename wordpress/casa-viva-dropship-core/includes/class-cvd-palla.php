<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.61 (Ernesto 23-oct): Pa'llá, la app del mensajero (PWA) en /palla/. Plan: assets/palla/README.md.
 *
 * El 23-oct WhatsApp restringió el número de Casa Viva y el reparto (grupo + «Yo») se paró. Pa'llá hace lo mismo sin
 * WhatsApp: ver las carreras, cogerlas («La cojo» = el «Yo» del grupo), y los 4 botones de la tarjeta de carrera.
 * Esta clase es la puerta: interruptor, entrada sin contraseña, la página, el manifest y el service worker.
 * La API está aparte (CVD_Palla_Api) para poder llevarla a NEXO en la fase 2.
 *
 * - Interruptor `cvd_palla`: no (por defecto: «Todavía no está abierta» y la API da 403) | prueba (solo los ids de
 *   `cvd_palla_testers`) | si. Además siempre: mensajero aprobado (CVD_Registration::is_approved_messenger).
 * - Entrada: enlace personal /palla/#k=TOKEN. La clave va tras «#»: no llega al servidor, ni a los registros, ni a la
 *   vista previa de WhatsApp. TOKEN = id.caducidad.firma (HMAC con la sal de WordPress, 30 días) sobre
 *   id|caducidad|época; subir la época del usuario (_cvd_palla_epoch, revoke()) anula todos sus enlaces de golpe.
 *   La app lo guarda y lo manda en la cabecera X-Palla-Token. Con sesión de WordPress abierta, GET /palla/sesion
 *   (cabecera X-Palla: 1) da un token igual. Para dar el enlace: CVD_Palla::link_for( $id ) o GET /bot/palla/enlace.
 * - Las rutas /palla/… se leen de la dirección pedida (parse_request), sin reglas de reescritura: no hace falta
 *   «guardar enlaces permanentes» al subir.
 */
final class CVD_Palla {
	public const OPTION = 'cvd_palla';
	public const TESTERS = 'cvd_palla_testers';
	public const EPOCH_META = '_cvd_palla_epoch';
	public const TTL = 2592000; // 30 días
	public const WHATSAPP = '5354056173'; // el de Casa Viva (opción cvd_palla_whatsapp)
	private const NS = 'casa-viva/v1';

	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/bot/palla/enlace', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'link_request' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	// ------------------------------------------------------------------ Interruptor

	/** no | prueba | si */
	public static function mode(): string {
		$mode = sanitize_key( (string) get_option( self::OPTION, 'no' ) );
		return in_array( $mode, array( 'prueba', 'si' ), true ) ? $mode : 'no';
	}

	/** @return int[] */
	public static function testers(): array {
		$raw = get_option( self::TESTERS, array() );
		$list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		return array_values( array_filter( array_map( 'absint', (array) $list ) ) );
	}

	/** '' = puede entrar; si no, el motivo: closed | not_tester | not_messenger. */
	public static function blocked_reason( WP_User $user ): string {
		$mode = self::mode();
		if ( 'no' === $mode ) { return 'closed'; }
		if ( 'prueba' === $mode && ! in_array( $user->ID, self::testers(), true ) ) { return 'not_tester'; }
		if ( ! CVD_Registration::is_approved_messenger( $user ) ) { return 'not_messenger'; }
		return '';
	}

	// ------------------------------------------------------------------ Token (enlace personal)

	private static function key(): string { return wp_salt( 'auth' ) . '|cvd-palla'; }

	private static function epoch( int $user_id ): int { return absint( get_user_meta( $user_id, self::EPOCH_META, true ) ); }

	private static function signature( int $user_id, int $expires, int $epoch ): string {
		return substr( hash_hmac( 'sha256', 'cvd-palla|' . $user_id . '|' . $expires . '|' . $epoch, self::key() ), 0, 32 );
	}

	public static function token_for( int $user_id, ?int $now = null ): string {
		$expires = ( $now ?? time() ) + self::TTL;
		return $user_id . '.' . $expires . '.' . self::signature( $user_id, $expires, self::epoch( $user_id ) );
	}

	/** El usuario del token, o 0 si la firma no vale, caducó o se anuló (época nueva). */
	public static function verify( string $token, ?int $now = null ): int {
		if ( ! preg_match( '/^(\d{1,10})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) ) { return 0; }
		$user_id = (int) $m[1];
		if ( (int) $m[2] < ( $now ?? time() ) ) { return 0; }
		return hash_equals( self::signature( $user_id, (int) $m[2], self::epoch( $user_id ) ), $m[3] ) ? $user_id : 0;
	}

	/** Enlace personal para mandárselo al mensajero (no envía nada). */
	public static function link_for( int $user_id, ?int $now = null ): string {
		return home_url( '/palla/' ) . '#k=' . self::token_for( $user_id, $now );
	}

	/** Anula TODOS los enlaces de ese mensajero (teléfono perdido, ya no trabaja con Casa Viva). */
	public static function revoke( int $user_id ): void {
		update_user_meta( $user_id, self::EPOCH_META, self::epoch( $user_id ) + 1 );
	}

	/** Quién llama a la API: el usuario del X-Palla-Token, o el motivo por el que no entra. */
	public static function request_user( WP_REST_Request $request ) {
		$user_id = self::verify( trim( (string) $request->get_header( 'x_palla_token' ) ) );
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) { return new WP_Error( 'palla_token', 'Tu enlace de Pa\'llá ya no vale. Pide uno nuevo a la tienda.', array( 'status' => 401 ) ); }
		$reason = self::blocked_reason( $user );
		if ( '' !== $reason ) { return new WP_Error( 'palla_' . $reason, self::closed_text( $reason ), array( 'status' => 403 ) ); }
		return $user;
	}

	public static function closed_text( string $reason ): string {
		return array(
			'closed'        => 'Pa\'llá todavía no está abierta. Sigue con el grupo de Mensajerías como siempre.',
			'not_tester'    => 'Pa\'llá está en prueba con pocos mensajeros. Te avisamos cuando abra para todos.',
			'not_messenger' => 'Pa\'llá es para los mensajeros aprobados de Casa Viva.',
		)[ $reason ] ?? 'No puedes entrar ahora.';
	}

	/** GET /bot/palla/enlace?phone= — el enlace del mensajero para que el bot (o Ernesto) se lo mande. Solo lo da. */
	public static function link_request( WP_REST_Request $request ): WP_REST_Response {
		$messenger = CVD_Bot_Bridge::messenger_by_phone( (string) $request->get_param( 'phone' ) );
		if ( ! $messenger ) { $response = rest_ensure_response( array( 'ok' => false, 'result' => 'not_messenger' ) ); }
		else {
			$response = rest_ensure_response( array(
				'ok'      => true,
				'id'      => $messenger->ID,
				'name'    => $messenger->display_name,
				'link'    => self::link_for( $messenger->ID ),
				'mode'    => self::mode(),
				'blocked' => self::blocked_reason( $messenger ), // '' = ya puede entrar
			) );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	// ------------------------------------------------------------------ Rutas /palla/…

	/** 'app' | 'sw' | 'manifest' | 'sesion' | '' según la dirección pedida. */
	public static function path_kind( string $request_uri ): string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) { $path = substr( $path, strlen( $base ) ); }
		return array(
			'/palla'                      => 'app',
			'/palla/'                     => 'app',
			'/palla/sw.js'                => 'sw',
			'/palla/manifest.webmanifest' => 'manifest',
			'/palla/sesion'               => 'sesion',
			'/palla/tienda'               => 'tienda',
			'/palla/tienda/'              => 'tienda',
		)[ $path ] ?? '';
	}

	public static function route(): void {
		$kind = self::path_kind( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( '' === $kind ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Pa\'llá: app del mensajero' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'sw' === $kind ) { self::service_worker(); }
		if ( 'manifest' === $kind ) { self::manifest(); }
		if ( 'sesion' === $kind ) { self::session(); }
		if ( 'tienda' === $kind ) { self::store_page(); }
		self::page();
	}

	/** GET /palla/sesion con sesión de WordPress abierta → {token}. Pide X-Palla: 1 (otro sitio no puede mandarla). */
	private static function session(): void {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		$user = wp_get_current_user();
		if ( '1' !== (string) ( $_SERVER['HTTP_X_PALLA'] ?? '' ) || ! $user instanceof WP_User || ! $user->ID ) {
			status_header( 403 );
			echo wp_json_encode( array( 'ok' => false, 'code' => 'palla_login' ) );
			exit;
		}
		$reason = self::blocked_reason( $user );
		if ( '' !== $reason ) {
			status_header( 403 );
			echo wp_json_encode( array( 'ok' => false, 'code' => 'palla_' . $reason, 'message' => self::closed_text( $reason ) ) );
			exit;
		}
		echo wp_json_encode( array( 'ok' => true, 'token' => self::token_for( $user->ID ) ) );
		exit;
	}

	public static function asset( string $file ): string {
		return CVD_URL . 'assets/palla/' . $file . '?v=' . rawurlencode( CVD_VERSION );
	}

	private static function whatsapp(): string {
		$digits = preg_replace( '/\D+/', '', (string) get_option( 'cvd_palla_whatsapp', self::WHATSAPP ) );
		return strlen( $digits ) >= 8 ? $digits : self::WHATSAPP;
	}

	/** Lo que la app necesita saber al arrancar (nada personal: la página es la misma para todos). */
	public static function config(): array {
		return array(
			'api'      => rest_url( self::NS . '/palla/' ),
			'session'  => home_url( '/palla/sesion' ),
			'sw'       => home_url( '/palla/sw.js' ),
			'scope'    => home_url( '/palla/' ),
			'version'  => CVD_VERSION,
			'mode'     => self::mode(),
			'whatsapp' => self::whatsapp(),
			'storeMap' => (string) get_option( 'cvd_palla_tienda_mapa', 'https://www.google.com/maps/search/?api=1&query=Calle+Conill+esquina+45%2C+Nuevo+Vedado%2C+La+Habana' ),
		);
	}

	private static function page(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		// 3.14.61.1: /palla/?practica=1 = modo práctica (assets/palla/practica.js): carreras inventadas, nada real. Se abre
		// aunque Pa'llá esté cerrada (sirve para enseñar a los mensajeros antes de darles su enlace).
		$practice = isset( $_GET['practica'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo elige la página, no cambia nada.
		$closed = 'no' === self::mode() && ! $practice;
		$body = $closed
			? '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>Todavía no está abierta</h1><p>' . esc_html( self::closed_text( 'closed' ) ) . '</p></main>'
			: '<div id="app" aria-live="polite"><main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><p>Cargando Pa\'llá…</p></main></div>'
				. '<noscript><main class="pl-closed"><h1>Activa JavaScript</h1><p>Pa\'llá necesita JavaScript para funcionar.</p></main></noscript>'
				. '<script>window.PALLA=' . wp_json_encode( self::config() ) . ';</script>'
				. ( $practice ? '<script src="' . esc_url( self::asset( 'practica.js' ) ) . '" defer></script>' : '' )
				. '<script src="' . esc_url( self::asset( 'app.js' ) ) . '" defer></script>';
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#121212"><title>Pa\'llá</title>'
			. '<link rel="manifest" href="' . esc_url( home_url( '/palla/manifest.webmanifest' ) ) . '">'
			. '<link rel="icon" href="' . esc_url( self::asset( 'icon.svg' ) ) . '" type="image/svg+xml">'
			. '<link rel="apple-touch-icon" href="' . esc_url( self::asset( 'icon-192.png' ) ) . '">'
			. '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Pa\'llá">'
			. '<link rel="preload" href="' . esc_url( self::asset( 'fonts/Archivo-400.woff2' ) ) . '" as="font" type="font/woff2" crossorigin>'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'app.css' ) ) . '">'
			. '</head><body>' . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* arriba.
		exit;
	}

	// ------------------------------------------------------------------ /palla/tienda (3.14.67.1)

	/**
	 * La tienda ve el reparto en vivo (Nana, Zaymi, Lennys, Ernesto: sesión de la web con rol de tienda). Solo lectura.
	 * Quita los «¿quién tiene el #N?», «prepara que viene el mensajero» y las carreras que nadie coge sin que nadie lo sepa.
	 * Se recarga sola cada minuto. Nunca escribe en los pedidos (la mensajería y el dinero salen de lo guardado).
	 */
	private static function store_page(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->ID ) { wp_safe_redirect( wp_login_url( home_url( '/palla/tienda' ) ) ); exit; }
		if ( ! array_intersect( array( 'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator' ), (array) $user->roles ) ) {
			status_header( 403 );
			self::store_shell( '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>Solo para la tienda</h1><p>Esta página es para las dependientas y la administración de Casa Viva.</p></main>' );
		}
		$g = self::store_groups();
		$card = static function ( array $r, string $extra = '' ): string {
			$who = '' !== $r['messenger'] ? '<div class="pl-muted">🛵 <span class="pl-strong">' . esc_html( $r['messenger'] ) . '</span>' . ( '' !== $r['phone'] ? ' · <a href="tel:+' . esc_attr( $r['phone'] ) . '">' . esc_html( $r['phoneLabel'] ) . '</a>' : '' ) . '</div>' : '';
			return '<article class="pl-card"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' . esc_html( $r['number'] ) . ' · ' . esc_html( $r['zone'] ) . '</span>' . $extra . '</div>' . $who
				. ( '' !== $r['eta'] ? '<div class="pl-muted">🕒 Entrega: <span class="pl-strong">' . esc_html( $r['eta'] ) . '</span></div>' : '' )
				. ( '' !== $r['gestora'] ? '<div class="pl-muted">Gestora: <span class="pl-strong">' . esc_html( $r['gestora'] ) . '</span></div>' : '' )
				. '<div class="pl-muted">' . esc_html( implode( ' · ', $r['items'] ) ) . '</div>'
				. '<div class="pl-muted">Cobra: <span class="pl-strong">' . esc_html( $r['collect'] ) . '</span>' . ( '' !== $r['change'] ? ' · <span class="pl-strong">Dale ' . esc_html( $r['change'] ) . ' de vuelto</span>' : '' ) . ( '' !== $r['fee'] ? ' · mensajería ' . esc_html( $r['fee'] ) : '' ) . '</div></article>';
		};
		$section = static function ( string $title, string $empty, array $rows, callable $render ): string {
			$html = '<h2 class="pl-h2">' . esc_html( $title ) . ' (' . count( $rows ) . ')</h2>';
			if ( ! $rows ) { return $html . '<div class="pl-muted">' . esc_html( $empty ) . '</div>'; }
			foreach ( $rows as $r ) { $html .= $render( $r ); }
			return $html;
		};
		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) );
		$body = '<header class="pl-top"><div><div class="pl-brand">Reparto en vivo</div><div class="pl-sub">Casa Viva · ' . esc_html( $now->format( 'g:i a' ) ) . ' · se pone al día sola cada minuto</div></div></header><main class="pl-main">';
		$body .= $section( 'Sin mensajero', 'Todas las carreras tienen mensajero.', $g['free'], static function ( array $r ) use ( $card ): string {
			$late = $r['minutes'] >= 30;
			return $card( $r, '<span class="pl-badge pl-badge--' . ( $late ? 'danger' : 'warning' ) . '">' . esc_html( $r['minutes'] < 60 ? $r['minutes'] . ' min' : floor( $r['minutes'] / 60 ) . ' h' ) . '</span>' );
		} );
		$body .= $section( 'Por recoger (prepáralo)', 'Nadie tiene que venir a recoger ahora.', $g['pickup'], static fn( array $r ): string => $card( $r, '' !== $r['eta'] ? '<span class="pl-badge pl-badge--info">Viene</span>' : '<span class="pl-badge pl-badge--warning">Sin hora</span>' ) );
		$body .= $section( 'En camino', 'Nadie va de camino ahora.', $g['route'], static fn( array $r ): string => $card( $r, '<span class="pl-badge pl-badge--info">En camino</span>' ) );
		$body .= $section( 'Problema avisado', 'Ningún problema abierto.', $g['incident'], static fn( array $r ): string => $card( $r, '<span class="pl-badge pl-badge--danger">Problema</span>' ) );
		$money = class_exists( 'CVD_Messenger_Cash' ) ? CVD_Messenger_Cash::pending() : array();
		$body .= $section( 'Dinero por recibir', 'Nadie debe dinero a la tienda.', $money, static function ( array $r ): string {
			return '<article class="pl-card"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' . esc_html( (string) $r['number'] ) . ' · ' . esc_html( (string) $r['messengerName'] ) . '</span><span class="pl-num pl-strong">' . esc_html( (string) $r['label'] ) . '</span></div><div class="pl-muted">Entregado ' . esc_html( (string) $r['sinceLocal'] ) . ( null !== $r['hours'] && $r['hours'] >= 24 ? ' · <b>hace más de un día</b>' : '' ) . '</div></article>';
		} );
		self::store_shell( $body . '</main>' );
	}

	/** Las carreras abiertas por paso, sin datos que no hagan falta en la tienda. */
	public static function store_groups(): array {
		$orders = wc_get_orders( array( 'limit' => 150, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'status' => array( 'pending', 'processing', 'on-hold' ),
			'meta_query' => array( array( 'key' => '_cvd_delivery_status', 'value' => array( 'offered', 'assigned', 'accepted', 'to_store', 'picked_up', 'handed_over', 'incident' ), 'compare' => 'IN' ) ) ) );
		$groups = array( 'free' => array(), 'pickup' => array(), 'route' => array(), 'incident' => array() );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || CVD_Tarjeta_Carrera::is_test( $order ) ) { continue; }
			$status = CVD_Delivery::status( $order );
			$messenger = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
			$key = 'incident' === $status ? 'incident' : ( 'offered' === $status && ! $messenger ? 'free' : ( in_array( $status, array( 'picked_up', 'handed_over' ), true ) ? 'route' : ( in_array( $status, array( 'assigned', 'accepted', 'to_store' ), true ) ? 'pickup' : '' ) ) );
			if ( '' === $key || ( 'free' === $key && '' !== CVD_Delivery::blocks_dispatch( $order ) ) ) { continue; }
			$phone = $messenger ? preg_replace( '/\D+/', '', (string) ( get_user_meta( $messenger->ID, '_cvd_whatsapp', true ) ?: get_user_meta( $messenger->ID, 'billing_phone', true ) ) ) : '';
			$phone = 8 === strlen( $phone ) ? '53' . $phone : $phone;
			$a = CVD_Messenger_Delivered_Link::default_amounts( class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null, (float) $order->get_total() );
			$products = array_filter( array( 'USD' => $a['usd'], 'CUP' => $a['cup'], 'EUR' => $a['eur'] ), static fn( $v ): bool => (float) $v > 0 );
			$fee = absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) ) ?: (int) round( CVD_Delivery::courier_amount( $order ) );
			$offered = (string) $order->get_meta( '_cvd_delivery_offered_at', true );
			$groups[ $key ][] = array(
				'number'     => (string) $order->get_order_number(),
				'zone'       => CVD_Delivery::destination_zone( $order ),
				'items'      => CVD_Order_Lines::texts( $order ),
				'messenger'  => $messenger ? (string) $messenger->display_name : '',
				'phone'      => strlen( $phone ) >= 8 ? $phone : '',
				'phoneLabel' => strlen( $phone ) >= 8 && class_exists( 'CVD_Sales' ) ? CVD_Sales::phone_label( $phone ) : '+' . $phone,
				'eta'        => (string) $order->get_meta( '_cvd_bot_eta', true ),
				'gestora'    => 'gestora' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ? (string) $order->get_meta( '_cvd_owner_display_name', true ) : '',
				'collect'    => $a['prepaid'] ? 'nada, ya pagó' : ( CVD_Tarjeta_Carrera::money_text( $products ) ?: 'según la tasa' ),
				'change'     => CVD_Tarjeta_Carrera::money_text( CVD_Tarjeta_Carrera::store_change( $order ) ),
				'fee'        => $fee > 0 ? CVD_Messenger_Delivered_Link::money( (float) $fee, 'CUP' ) : '',
				'minutes'    => '' !== $offered ? max( 0, (int) floor( ( time() - (int) strtotime( $offered . ' UTC' ) ) / 60 ) ) : 0,
			);
		}
		usort( $groups['free'], static fn( array $x, array $y ): int => $y['minutes'] <=> $x['minutes'] );
		return $groups;
	}

	private static function store_shell( string $body ): void {
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<meta http-equiv="refresh" content="60"><meta name="theme-color" content="#121212"><title>Reparto en vivo · Casa Viva</title>'
			. '<link rel="stylesheet" href="' . esc_url( self::asset( 'app.css' ) ) . '"></head><body><div class="pl-shell">' . $body . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- armado con esc_* arriba.
		exit;
	}

	private static function manifest(): void {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		$scope = (string) wp_parse_url( home_url( '/palla/' ), PHP_URL_PATH );
		echo wp_json_encode( array(
			'id'               => $scope,
			'name'             => 'Pa\'llá · mensajeros de Casa Viva',
			'short_name'       => 'Pa\'llá',
			'description'      => 'Coge carreras, márcalas y mira tu dinero del día.',
			'lang'             => 'es',
			'start_url'        => $scope . '?source=pwa',
			'scope'            => $scope,
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#FFFFFF',
			'theme_color'      => '#121212',
			'icons'            => array(
				array( 'src' => self::asset( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::asset( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::asset( 'icon-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * /palla/sw.js (alcance /palla/: dentro de esa carpeta gana al de /casa-viva-sw.js).
	 * Guarda la app y las fuentes; «hoy» va primero a la red y, sin red, devuelve la última respuesta guardada con la
	 * cabecera X-Palla-Offline: 1. Los POST (botones) no pasan por aquí: la cola sin conexión está en la app.
	 */
	private static function service_worker(): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		$shell = wp_json_encode( home_url( '/palla/' ) );
		$files = wp_json_encode( array_map( array( __CLASS__, 'asset' ), array( 'app.js', 'practica.js', 'app.css', 'icon.svg', 'fonts/Archivo-400.woff2', 'fonts/Archivo-600.woff2', 'fonts/Archivo-700.woff2', 'fonts/Archivo-800.woff2' ) ), JSON_UNESCAPED_SLASHES );
		$hoy = wp_json_encode( rest_url( self::NS . '/palla/hoy' ), JSON_UNESCAPED_SLASHES );
		echo "const CACHE='palla-" . esc_js( CVD_VERSION ) . "',DATA='palla-datos',SHELL={$shell},FILES={$files},HOY={$hoy};\n";
		echo "self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll([SHELL].concat(FILES))));self.skipWaiting()});\n";
		echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k.startsWith('palla-')&&k!==CACHE&&k!==DATA).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});\n";
		echo "self.addEventListener('fetch',e=>{const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin)return;\n";
		echo "if(r.mode==='navigate'){e.respondWith(fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(SHELL,cp))}return res}).catch(()=>caches.match(SHELL)));return}\n";
		echo "if(u.href.split('?')[0]===HOY.split('?')[0]){e.respondWith(fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(DATA).then(c=>c.put(HOY,cp))}return res}).catch(()=>caches.open(DATA).then(c=>c.match(HOY)).then(x=>{if(!x)throw new Error('sin datos');return x.text().then(t=>new Response(t,{status:200,headers:{'Content-Type':'application/json','X-Palla-Offline':'1'}}))})));return}\n";
		echo "if(u.pathname.indexOf('/assets/palla/')!==-1){e.respondWith(caches.match(r).then(x=>x||fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(r,cp))}return res})))}});\n";
		exit;
	}
}
