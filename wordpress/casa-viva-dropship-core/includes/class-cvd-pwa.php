<?php

defined( 'ABSPATH' ) || exit;

/** Installable role-aware Casa Viva web application. */
final class CVD_PWA {
	public static function register(): void {
		add_shortcode( 'casa_viva_app', array( __CLASS__, 'render_app' ) );
		add_shortcode( 'casa_viva_operations', array( __CLASS__, 'render_operations' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 30, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'woocommerce_login_redirect' ), 30, 2 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );
		add_filter( 'woocommerce_email_from_name', array( __CLASS__, 'mail_from_name' ) );
		add_filter( 'login_url', array( __CLASS__, 'password_reset_login_url' ), 30, 3 );
		add_filter( 'the_title', array( __CLASS__, 'compact_app_page_title' ), 30, 2 );
	}

	public static function compact_app_page_title( string $title, int $post_id ): string {
		unset( $post_id );
		if ( is_main_query() && in_the_loop() && is_page( array('casa-viva-app','centro-operaciones','inventario','ventas','mensajeria','contabilidad','contabilidad-mensajeros','seguimiento') ) ) { return ''; }
		return $title;
	}

	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^casa-viva-sw\.js$', 'index.php?cvd_service_worker=1', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'cvd_service_worker';
		return $vars;
	}

	public static function rest_routes(): void {
		register_rest_route( 'casa-viva/v1', '/manifest', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'manifest' ), 'permission_callback' => '__return_true' ) );
	}

	public static function manifest(): WP_REST_Response {
		$icons = CVD_URL . 'assets/pwa/';
		$data = array(
			'id'               => '/casa-viva-app/',
			'name'             => 'Casa Viva',
			'short_name'       => 'Casa Viva',
			'description'      => 'Tienda y centro de operaciones de Casa Viva.',
			'lang'             => 'es',
			'start_url'        => '/casa-viva-app/?source=pwa',
			'scope'            => '/',
			'display'          => 'standalone',
			'background_color' => '#F5E7DC',
			'theme_color'      => '#432D2D',
			'icons'            => array(
				array( 'src' => $icons . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => $icons . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => $icons . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
			'shortcuts' => array(
				array( 'name' => 'Tienda', 'url' => '/tienda/', 'icons' => array( array( 'src' => $icons . 'icon-192.png', 'sizes' => '192x192' ) ) ),
				array( 'name' => 'Mi cuenta', 'url' => '/mi-cuenta/', 'icons' => array( array( 'src' => $icons . 'icon-192.png', 'sizes' => '192x192' ) ) ),
			),
		);
		$response = new WP_REST_Response( $data );
		$response->header( 'Content-Type', 'application/manifest+json; charset=UTF-8' );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	public static function head(): void {
		echo '<link rel="manifest" href="' . esc_url( rest_url( 'casa-viva/v1/manifest' ) ) . '">';
		echo '<meta name="theme-color" content="#432D2D">';
		echo '<link rel="apple-touch-icon" href="' . esc_url( CVD_URL . 'assets/pwa/icon-192.png' ) . '">';
		echo '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Casa Viva">';
	}

	public static function assets(): void {
		wp_enqueue_style( 'cvd-pwa', CVD_URL . 'assets/pwa.css', array(), CVD_VERSION );
		wp_enqueue_script( 'cvd-pwa', CVD_URL . 'assets/pwa.js', array(), CVD_VERSION, true );
		wp_localize_script( 'cvd-pwa', 'cvdPwa', array(
			'workerUrl' => add_query_arg( 'v', CVD_VERSION, home_url( '/casa-viva-sw.js' ) ),
			'appUrl' => home_url( '/casa-viva-app/' ),
			'pushPublicKey' => class_exists('CVD_Web_Push') ? CVD_Web_Push::public_key() : '',
			'pushSubscriptionUrl' => rest_url('casa-viva/v1/push/subscription'),
			'notificationsUrl' => rest_url('casa-viva/v1/notifications'),
			'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
			'isMessenger' => is_user_logged_in() && 'mensajero' === CVD_Registration::program_type(wp_get_current_user()),
			'canPush' => is_user_logged_in() && class_exists('CVD_Web_Push') && CVD_Web_Push::can_subscribe(),
		) );
	}

	public static function route(): void {
		if ( get_query_var( 'cvd_service_worker' ) ) { self::service_worker(); }
		if ( is_page( 'casa-viva-app' ) && is_user_logged_in() ) {
			wp_safe_redirect( self::destination( wp_get_current_user() ) );
			exit;
		}
	}

	private static function service_worker(): void {
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		$offline = wp_json_encode( home_url( '/casa-viva-app/?offline=1' ) );
		$icon = wp_json_encode( CVD_URL . 'assets/pwa/icon-192.png' );
		$badge = wp_json_encode( CVD_URL . 'assets/pwa/notification-badge-96.png' );
		echo "const CACHE='casa-viva-" . esc_js( CVD_VERSION ) . "';const OFFLINE={$offline};const ICON={$icon};const BADGE={$badge};\n";
		echo "self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll([OFFLINE,ICON,BADGE])));self.skipWaiting()});\n";
		echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('casa-viva-')&&k!==CACHE).map(k=>caches.delete(k)))));self.clients.claim()});\n";
		echo "self.addEventListener('fetch',e=>{const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin)return;if(r.mode==='navigate'){e.respondWith(fetch(r).catch(()=>caches.match(OFFLINE)));return}if(['style','script','image','font'].includes(r.destination)){e.respondWith(caches.match(r).then(x=>x||fetch(r).then(res=>{if(res.ok){const cp=res.clone();caches.open(CACHE).then(c=>c.put(r,cp))}return res})));}});";
		echo "\nself.addEventListener('push',e=>{e.waitUntil((async()=>{let n={};try{const r=await fetch('/wp-json/casa-viva/v1/push/latest',{credentials:'include',cache:'no-store'});if(r.ok)n=await r.json()}catch(x){}const title=n.title||'Casa Viva';const body=n.message||'Abre Casa Viva para revisar la actualización.';await self.registration.showNotification(title,{body,icon:ICON,badge:BADGE,tag:'cvd-'+(n.id||Date.now()),renotify:true,silent:false,vibrate:[300,120,300],requireInteraction:true,timestamp:Date.now(),data:{url:n.action_url||'/casa-viva-app/'}})})())});\n";
		echo "self.addEventListener('notificationclick',e=>{e.notification.close();const u=e.notification.data&&e.notification.data.url||'/area-mensajeros/#ofertas';e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{for(const c of cs){if('focus'in c){c.navigate(u);return c.focus()}}return clients.openWindow(u)}))});";
		exit;
	}

	public static function destination( WP_User $user ): string {
		$roles = (array) $user->roles;
		$program = CVD_Registration::program_type( $user );
		if ( 'gestora' === $program ) { return home_url( '/area-gestoras/' ); }
		if ( 'mensajero' === $program ) { return home_url( '/area-mensajeros/' ); }
		if ( in_array( 'cvd_clerk', $roles, true ) ) { return home_url( '/centro-operaciones/' ); }
		if ( array_intersect( array( 'administrator', 'shop_manager', 'cvd_operator' ), $roles ) ) { return home_url( '/centro-operaciones/' ); }
		$owner = CVD_Attribution::current_customer_owner();
		if ( $owner && ! empty( $owner['referral_code'] ) ) { return add_query_arg( 'ref', $owner['referral_code'], wc_get_page_permalink( 'shop' ) ); }
		return wc_get_page_permalink( 'shop' );
	}

	public static function login_redirect( string $redirect_to, string $requested, $user ): string {
		if ( ! $user instanceof WP_User || ( $requested && false !== strpos( $requested, '/wp-admin/' ) && user_can( $user, 'edit_posts' ) ) ) { return $redirect_to; }
		return self::destination( $user );
	}

	public static function woocommerce_login_redirect( string $redirect, WP_User $user ): string {
		return self::destination( $user );
	}

	public static function mail_from_name( string $name ): string {
		unset( $name );
		return 'Casa Viva';
	}

	/** After setting a new password, continue through the role-aware Casa Viva app. */
	public static function password_reset_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		unset( $redirect, $force_reauth );
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return in_array( $action, array( 'rp', 'resetpass' ), true ) ? home_url( '/casa-viva-app/' ) : $login_url;
	}

	public static function render_app(): string {
		if ( isset( $_GET['offline'] ) ) { return '<section class="cvd-app-entry"><h1>Estás sin conexión</h1><p>Recupera la conexión para continuar.</p><button class="cvd-app-button" onclick="location.reload()">Volver a intentar</button></section>'; }
		$login = wp_login_form( array( 'echo' => false, 'redirect' => home_url( '/casa-viva-app/' ), 'remember' => true ) );
		$lost = wp_lostpassword_url( home_url( '/casa-viva-app/' ) );
		return '<section class="cvd-app-entry"><img src="' . esc_url( CVD_URL . 'assets/pwa/icon-192.png' ) . '" alt="Casa Viva" width="112" height="112"><div class="cvd-app-login">' . $login . '<p class="cvd-app-recovery"><a href="' . esc_url( $lost ) . '">¿Olvidaste tu contraseña?</a></p></div></section>';
	}

	public static function render_operations(): string {
		if ( ! is_user_logged_in() ) { return self::render_app(); }
		$user = wp_get_current_user();
		if ( ! array_intersect( array( 'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator' ), (array) $user->roles ) ) { return '<div class="cvd-app-denied">No tienes permiso para acceder a operaciones.</div>'; }
		global $wpdb;
		$unread = class_exists('CVD_Web_Push') ? absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CVD_Web_Push::notifications_table() . ' WHERE user_id=%d AND read_at IS NULL', $user->ID ) ) ) : 0;
		$order_badge = $unread ? '<b class="cvd-operation-badge">' . esc_html( $unread ) . '</b>' : '';
		$accounting = current_user_can( 'manage_woocommerce' ) ? '<a href="' . esc_url( home_url( '/contabilidad/' ) ) . '"><span>$</span><h2>Contabilidad</h2><p>Comisiones, solicitudes y pagos.</p></a>' : '';
		return '<section class="cvd-operations"><header><h1>Operaciones</h1><div><button data-cvd-enable-notifications type="button">Activar alertas</button><a href="' . esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ) . '">Salir</a></div></header><div class="cvd-operation-grid"><a href="' . esc_url( home_url( '/inventario/' ) ) . '"><span>▦</span><h2>Inventario</h2></a><a href="' . esc_url( home_url( '/ventas/' ) ) . '"><span>✓</span><h2>Pedidos</h2>' . $order_badge . '</a><a href="' . esc_url( home_url( '/mensajeria/' ) ) . '"><span>↗</span><h2>Mensajería</h2></a>' . $accounting . '</div></section>';
	}
}
