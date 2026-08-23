<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Portal {
	public static function register(): void {
		add_shortcode( 'casa_viva_portal', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_gestora_account' ), 5 );
		add_filter( 'login_redirect', array( __CLASS__, 'role_login_redirect' ), 20, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'enforce_selected_access' ), 30 );
		add_action( 'woocommerce_login_form', array( __CLASS__, 'selected_access_field' ) );
		add_action( 'wp_ajax_cvd_save_gestora_price', array( __CLASS__, 'ajax_save_gestora_price' ) );
	}

	public static function role_login_redirect( string $redirect_to, string $requested, $user ): string {
		if ( ! $user instanceof WP_User ) { return $redirect_to; }
		$roles = (array) $user->roles;
		$type = CVD_Registration::program_type( $user );
		if ( 'mensajero' === $type ) { return home_url( '/area-mensajeros/' ); }
		if ( 'gestora' === $type ) { return home_url( '/area-gestoras/' ); }
		if ( array_intersect( array( 'cvd_clerk', 'cvd_operator' ), $roles ) ) { return home_url( '/centro-operaciones/' ); }
		return $redirect_to;
	}

	public static function selected_access_field(): void {
		$access = isset( $_GET['acceso'] ) ? sanitize_key( wp_unslash( $_GET['acceso'] ) ) : 'clientes';
		if ( ! in_array( $access, array( 'clientes', 'gestoras', 'mensajeros' ), true ) ) { $access = 'clientes'; }
		echo '<input type="hidden" name="cvd_access_type" value="' . esc_attr( $access ) . '">';
	}

	/** Prevent a valid WordPress password from opening a portal for another role. */
	public static function enforce_selected_access( $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) { return $user; }
		$selected = isset( $_POST['cvd_access_type'] ) ? sanitize_key( wp_unslash( $_POST['cvd_access_type'] ) ) : '';
		if ( ! in_array( $selected, array( 'clientes', 'gestoras', 'mensajeros' ), true ) ) { return $user; }
		$program = CVD_Registration::program_type( $user );
		$roles = (array) $user->roles;
		$staff = (bool) array_intersect( array( 'administrator', 'shop_manager', 'cvd_clerk', 'cvd_operator' ), $roles );
		$valid = ( 'gestoras' === $selected && 'gestora' === $program )
			|| ( 'mensajeros' === $selected && 'mensajero' === $program )
			|| ( 'clientes' === $selected && ! $program && ! $staff );
		if ( $valid ) { return $user; }
		$label = array( 'clientes' => 'cliente', 'gestoras' => 'gestora', 'mensajeros' => 'mensajero' )[ $selected ];
		return new WP_Error( 'cvd_wrong_portal', 'Esta cuenta no pertenece al acceso de ' . $label . '. Selecciona el tipo de cuenta correcto.' );
	}

	public static function assets(): void {
		if ( is_page( array( 'gestores', 'area-gestoras', 'area-mensajeros', 'ruta-cv', 'registro-gestora', 'registro-mensajero' ) ) ) {
			wp_enqueue_style( 'cvd-portal', plugins_url( 'assets/portal.css', CVD_FILE ), array(), CVD_VERSION );
			wp_enqueue_script( 'cvd-qr-code', CVD_URL . 'assets/qr-code.js', array(), CVD_VERSION, true );
			wp_enqueue_script( 'cvd-portal', plugins_url( 'assets/portal.js', CVD_FILE ), array( 'cvd-qr-code' ), CVD_VERSION, true );
			wp_localize_script( 'cvd-portal', 'cvdPortal', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cvd_portal_price' ),
				'messengerFeedUrl' => rest_url( 'casa-viva/v1/messenger/feed' ),
			'messengerContactUrl' => rest_url( 'casa-viva/v1/messenger/orders/' ),
			'voucherUrl' => home_url( '/interpretar-vale/' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'isMessenger' => is_user_logged_in() && 'mensajero' === CVD_Registration::program_type( wp_get_current_user() ),
			) );
		}
	}

	public static function redirect_gestora_account(): void {
		if ( ! is_user_logged_in() ) {
			if ( is_page( 'area-gestoras' ) ) { wp_safe_redirect( add_query_arg( 'acceso', 'gestoras', home_url( '/mi-cuenta/' ) ) . '#cv-customer-login' ); exit; }
			if ( is_page( array( 'area-mensajeros', 'ruta-cv' ) ) ) { wp_safe_redirect( add_query_arg( 'acceso', 'mensajeros', home_url( '/mi-cuenta/' ) ) . '#cv-customer-login' ); exit; }
			return;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) { return; }
		$user = wp_get_current_user();
		if ( 'gestora' === CVD_Registration::program_type( $user ) && ! is_wc_endpoint_url( 'customer-logout' ) ) {
			wp_safe_redirect( home_url( '/area-gestoras/' ) );
			exit;
		}
		if ( in_array( 'cvd_clerk', (array) $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/centro-operaciones/' ) );
			exit;
		}
		if ( 'mensajero' === CVD_Registration::program_type( $user ) ) {
			wp_safe_redirect( home_url( '/area-mensajeros/' ) );
			exit;
		}
		if ( in_array( 'cvd_operator', (array) $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/centro-operaciones/' ) );
			exit;
		}
	}

	public static function ajax_save_gestora_price(): void {
		check_ajax_referer( 'cvd_portal_price', 'nonce' );
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! CVD_Registration::is_approved_gestora( $user ) ) {
			wp_send_json_error( array( 'message' => 'Sin permiso o sesión vencida.' ), 403 );
		}
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$price = isset( $_POST['price'] ) ? wp_unslash( $_POST['price'] ) : '';
		$result = CVD_Gestora_Store::save_prices( $user->ID, array( $product_id => $price ) );
		if ( $result['errors'] ) { wp_send_json_error( array( 'message' => 'Precio fuera del rango permitido.' ), 422 ); }
		wp_send_json_success( array( 'message' => '' === trim( (string) $price ) ? 'Precio automático restaurado.' : 'Precio guardado.' ) );
	}

	public static function render( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'role' => 'gestora' ), $atts );
		$type = 'mensajero' === $atts['role'] ? 'mensajero' : 'gestora';
		if ( ! is_user_logged_in() ) {
			$register = home_url( 'mensajero' === $type ? '/registro-mensajero/' : '/registro-gestora/' );
			$lost = wp_lostpassword_url( home_url( '/casa-viva-app/' ) );
			return '<section class="cvd-auth-card"><h1>Acceso</h1>' . wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ?: home_url( '/' ), 'remember' => true ) ) . '<div class="cvd-auth-links"><a href="' . esc_url( $lost ) . '">¿Olvidaste tu contraseña?</a><span>¿No tienes cuenta? <a href="' . esc_url( $register ) . '">Solicítala aquí</a>.</span></div></section>';
		}
		$user = wp_get_current_user();
		$has_access = CVD_Registration::has_program_account( $user, $type );
		if ( ! $has_access ) {
			$correct = CVD_Registration::program_type( $user );
			$destination = 'mensajero' === $correct ? home_url( '/area-mensajeros/' ) : ( 'gestora' === $correct ? home_url( '/area-gestoras/' ) : home_url( '/mi-cuenta/' ) );
			return '<section class="cvd-auth-card"><h1>Acceso incorrecto</h1><p>Esta cuenta pertenece a otra área.</p><a class="cvd-primary" href="' . esc_url( $destination ) . '">Ir a mi cuenta</a><a class="cvd-secondary" href="' . esc_url( wp_logout_url( home_url( '/mi-cuenta/' ) ) ) . '">Usar otra cuenta</a></section>';
		}
		$status = get_user_meta( $user->ID, '_cvd_account_status', true );
		if ( ! $status || 'pending' === $status ) { return '<section class="cvd-auth-card"><p class="cvd-kicker">Cuenta de ' . esc_html( $type ) . '</p><h1>Solicitud pendiente</h1><p>Tu cuenta está creada correctamente. Casa Viva debe aprobarla desde su panel administrativo. No necesitas registrarte otra vez.</p><a class="cvd-secondary" href="' . esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ) . '">Cambiar cuenta</a></section>'; }
		if ( 'suspended' === $status ) { return '<div class="cvd-notice cvd-error">Esta cuenta está suspendida. Contacta con Casa Viva.</div>'; }
		if ( 'rejected' === $status ) { return '<div class="cvd-notice cvd-error">Esta solicitud no fue aprobada. Contacta con Casa Viva.</div>'; }
		return 'mensajero' === $type ? self::messenger_portal( $user ) : self::gestora_portal( $user );
	}

	private static function gestora_portal( WP_User $user ): string {
		$notice = '';
		if ( isset( $_POST['cvd_price_action'] ) && isset( $_POST['cvd_gestora_prices_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_gestora_prices_nonce'] ) ), 'cvd_save_gestora_prices' ) ) {
			$price_action = sanitize_key( wp_unslash( $_POST['cvd_price_action'] ) );
			if ( 'global' === $price_action ) {
				$max = (float) get_user_meta( $user->ID, '_cvd_max_markup_percent', true ); $max = $max > 0 ? $max : (float) get_option( 'cvd_default_max_markup_percent', 30 );
				$value = min( $max, max( 0, (float) wc_format_decimal( wp_unslash( $_POST['cvd_global_markup'] ?? 0 ) ) ) ); update_user_meta( $user->ID, '_cvd_global_markup_percent', $value ); CVD_Gestora_Store::bump_price_version( $user->ID );
				$notice = 'Aumento general actualizado.';
			} elseif ( 'products' === $price_action ) {
				$result = CVD_Gestora_Store::save_prices( $user->ID, (array) ( $_POST['cvd_product_price'] ?? array() ) );
				$notice = $result['saved'] . ' precios guardados.' . ( $result['errors'] ? ' Revisa los productos fuera de límite: ' . implode( ', ', array_slice( $result['errors'], 0, 4 ) ) . '.' : '' );
			}
		}
		$all_orders = wc_get_orders( array( 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_cvd_owner_user_id', 'meta_value' => $user->ID ) );
		$totals = array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'cancelled' => 0.0 );
		$client_keys = array();
		foreach ( $all_orders as $linked_order ) {
			$status = sanitize_key( $linked_order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( isset( $totals[ $status ] ) ) {
				$totals[ $status ] += (float) $linked_order->get_meta( '_cvd_commission_amount', true );
			}
			if ( in_array( $linked_order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { continue; }
			$client_key = (string) $linked_order->get_meta( '_cvd_identity_phone', true );
			$client_key = $client_key ?: (string) $linked_order->get_meta( '_cvd_identity_email', true );
			$client_key = $client_key ?: (string) $linked_order->get_meta( '_cvd_identity_customer', true );
			if ( ! $client_key ) {
				$phone = preg_replace( '/\D+/', '', (string) $linked_order->get_billing_phone() );
				$email = sanitize_email( strtolower( trim( (string) $linked_order->get_billing_email() ) ) );
				$client_key = $phone ? 'phone:' . hash( 'sha256', $phone ) : ( $email ? 'email:' . hash( 'sha256', $email ) : '' );
			}
			if ( $client_key ) {
				$client_keys[ $client_key ] = true;
			}
		}
		$clients = count( $client_keys );
		$orders = array_slice( $all_orders, 0, 100 );
		$code = get_user_meta( $user->ID, '_cvd_referral_code', true );
		if ( ! $code ) {
			$code = 'CV' . $user->ID . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $user->user_login ), 0, 8 ) );
			update_user_meta( $user->ID, '_cvd_referral_code', $code );
		}
		$link = add_query_arg( 'ref', $code, wc_get_page_permalink( 'shop' ) );
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC', 'type' => array( 'simple', 'variable' ) ) );
		$stored_prices = CVD_Gestora_Store::stored_prices( $user->ID );
		$global_markup = (float) get_user_meta( $user->ID, '_cvd_global_markup_percent', true );
		$max_markup = (float) get_user_meta( $user->ID, '_cvd_max_markup_percent', true ); $max_markup = $max_markup > 0 ? $max_markup : (float) get_option( 'cvd_default_max_markup_percent', 30 );
		ob_start();
		?>
		<section class="cvd-dashboard cvd-app-shell">
			<?php if ( $notice ) : ?><div class="cvd-notice" role="status"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
			<header class="cvd-dashboard-head"><div><p class="cvd-kicker">Casa Viva · Gestoras</p><h1>Hola, <?php echo esc_html( $user->display_name ); ?>.</h1><p>Tu centro de ventas, clientes, catálogo y comisiones.</p></div><a class="cvd-secondary" href="<?php echo esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ); ?>">Cambiar cuenta</a></header>
			<nav class="cvd-app-nav" aria-label="Panel de gestora"><a href="#dashboard">Dashboard</a><a href="#ventas">Ventas</a><a href="#clientes">Clientes</a><a href="#comisiones">Comisiones</a><a href="#pagos">Pagos</a><a href="#catalogo">Mi catálogo</a><a href="#precios">Mis precios</a><a href="#materiales">Material promocional</a><a href="#configuracion">Configuración</a><a href="#perfil">Perfil</a></nav>
			<div class="cvd-stats" id="dashboard"><article><span>Por verificar</span><strong><?php echo wp_kses_post( wc_price( $totals['pending'] ) ); ?></strong><small>No es saldo ganado todavía</small></article><article><span>Aprobada</span><strong><?php echo wp_kses_post( wc_price( $totals['approved'] ) ); ?></strong><small>Venta validada por Casa Viva</small></article><article><span>Pagada</span><strong><?php echo wp_kses_post( wc_price( $totals['paid'] ) ); ?></strong><small>Liquidación completada</small></article><article id="clientes"><span>Clientes vinculados</span><strong><?php echo esc_html( $clients ); ?></strong><small>Excluye pedidos anulados</small></article></div>
			<section class="cvd-panel" id="catalogo"><div><p class="cvd-kicker">Tu tienda espejo</p><h2>Comparte tu catálogo personalizado</h2><p>Los productos y existencias vienen de Casa Viva. Tu enlace conserva tu código y muestra tus precios.</p></div><div class="cvd-referral"><input aria-label="Enlace personal" id="cvd-store-link" readonly value="<?php echo esc_attr( $link ); ?>"><div class="cvd-inline-actions"><button class="cvd-secondary" data-copy-target="cvd-store-link" type="button">Copiar</button><a class="cvd-primary" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener">Ver mi tienda</a></div><a href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Mira mi tienda Casa Viva: ' . $link ) ); ?>" target="_blank" rel="noopener">Compartir por WhatsApp</a><small>Código: <?php echo esc_html( $code ); ?></small></div></section>
			<section class="cvd-panel cvd-pricing-panel" id="precios"><div><p class="cvd-kicker">Configuración rápida</p><h2>Precio general de tu tienda</h2><p>Aplica el mismo porcentaje sobre el precio Casa Viva. Los precios individuales tienen prioridad.</p></div><form method="post"><label>Aumento general <span>(máximo <?php echo esc_html( wc_format_decimal( $max_markup, 2 ) ); ?>%)</span><div class="cvd-percentage"><input max="<?php echo esc_attr( $max_markup ); ?>" min="0" name="cvd_global_markup" step="0.01" type="number" value="<?php echo esc_attr( $global_markup ); ?>"><b>%</b></div></label><?php wp_nonce_field( 'cvd_save_gestora_prices', 'cvd_gestora_prices_nonce' ); ?><button class="cvd-primary" name="cvd_price_action" type="submit" value="global">Aplicar a mi tienda</button></form></section>
			<section class="cvd-panel cvd-products-pricing"><div class="cvd-section-head"><div><p class="cvd-kicker">Precios por producto</p><h2>Personaliza productos</h2></div><label class="cvd-product-search">Buscar producto<input id="cvd-price-search" type="search" placeholder="Ej.: ventilador"></label></div><form method="post"><div class="cvd-price-list">
			<?php foreach ( $products as $product ) : $limits = CVD_Gestora_Store::limits( $product, $user->ID ); $custom = $stored_prices[ $product->get_id() ] ?? ''; ?>
				<article data-product-name="<?php echo esc_attr( strtolower( remove_accents( $product->get_name() ) ) ); ?>"><div class="cvd-price-product"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); ?><div><strong><?php echo esc_html( $product->get_name() ); ?></strong><small>Casa Viva: <?php echo wp_kses_post( wc_price( $limits['base'] ) ); ?></small></div></div><label>Tu precio<input class="cvd-price-input" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" inputmode="decimal" max="<?php echo esc_attr( $limits['max'] ); ?>" min="<?php echo esc_attr( $limits['min'] ); ?>" name="cvd_product_price[<?php echo esc_attr( $product->get_id() ); ?>]" placeholder="Automático" step="0.01" type="number" value="<?php echo esc_attr( $custom ); ?>"><small><?php echo wp_kses_post( wc_price( $limits['min'] ) ); ?>–<?php echo wp_kses_post( wc_price( $limits['max'] ) ); ?></small><button class="cvd-save-one" type="button">Guardar</button><span class="cvd-save-status" aria-live="polite"></span></label></article>
			<?php endforeach; ?></div><?php wp_nonce_field( 'cvd_save_gestora_prices', 'cvd_gestora_prices_nonce' ); ?><button class="cvd-primary cvd-save-prices" name="cvd_price_action" type="submit" value="products">Guardar precios</button></form></section>
			<section class="cvd-panel cvd-history-panel" id="ventas"><span id="comisiones"></span><h2>Historial de ventas y comisiones</h2><?php echo self::gestora_history( $orders ); ?></section>
			<?php echo CVD_Payouts::render_gestora( $user ); ?>
			<?php echo CVD_Promotional_Resources::render_gestora_library(); ?>
			<section class="cvd-panel cvd-placeholder-panel" id="configuracion"><p class="cvd-kicker">Configuración</p><h2>Tu tienda</h2><p><strong>Código:</strong> <?php echo esc_html( $code ); ?> · <strong>Aumento general:</strong> <?php echo esc_html( wc_format_decimal( $global_markup, 2 ) ); ?>%</p></section>
			<section class="cvd-panel cvd-placeholder-panel" id="perfil"><p class="cvd-kicker">Perfil</p><h2><?php echo esc_html( $user->display_name ); ?></h2><p><?php echo esc_html( $user->user_email ); ?></p><a class="cvd-primary" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">Editar perfil y contraseña</a></section>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function messenger_portal( WP_User $user ): string {
		if ( isset( $_POST['cvd_messenger_availability'] ) && isset( $_POST['cvd_messenger_availability_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_messenger_availability_nonce'] ) ), 'cvd_messenger_availability' ) ) {
			update_user_meta( $user->ID, '_cvd_messenger_available', 'available' === sanitize_key( wp_unslash( $_POST['cvd_messenger_availability'] ) ) ? 'yes' : 'no' );
			update_user_meta( $user->ID, '_cvd_zone', sanitize_text_field( wp_unslash( $_POST['cvd_messenger_zone'] ?? '' ) ) );
		}
		$orders = wc_get_orders( array( 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_cvd_messenger_user_id', 'meta_value' => $user->ID ) );
		$offers = CVD_Delivery::offers_for( $user );
		$active = array_filter( $orders, static fn( $order ) => ! in_array( $order->get_meta( '_cvd_delivery_status', true ), array( 'closed', 'failed', 'returned', 'cancelled' ), true ) );
		$in_delivery = array_filter( $active, static fn( $order ) => ! in_array( self::messenger_delivery_stage( $order ), array( 'delivered', 'cash_returned', 'closed' ), true ) );
		$earnings = array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0 );
		foreach ( $orders as $order ) {
			$status = sanitize_key( (string) $order->get_meta( '_cvd_messenger_earning_status', true ) ) ?: ( 'closed' === $order->get_meta( '_cvd_delivery_status', true ) ? 'approved' : 'pending' );
			if ( isset( $earnings[ $status ] ) ) { $earnings[ $status ] += (float) $order->get_meta( '_cvd_shipping_courier_amount_cup', true ); }
		}
		ob_start();
		?>
		<section class="cvd-dashboard cvd-app-shell cvd-messenger-center">
			<?php if ( isset( $_GET['oferta'] ) ) : $offer_result = sanitize_key( wp_unslash( $_GET['oferta'] ) ); ?><div class="cvd-notice" role="status"><?php echo esc_html( 'accepted' === $offer_result ? 'Carrera aceptada. Presenta el QR en la tienda.' : ( 'declined' === $offer_result ? 'Oferta descartada.' : 'La carrera ya no está disponible.' ) ); ?></div><?php endif; ?>
			<header class="cvd-dashboard-head cvd-messenger-head"><div><p class="cvd-kicker">Casa Viva · Mensajería</p><h1>Hola, <?php echo esc_html( $user->display_name ); ?>.</h1><p><?php echo esc_html( $offers ? 'Tienes una carrera por responder.' : ( $active ? 'Este es tu trabajo activo de hoy.' : 'Estás listo para recibir carreras.' ) ); ?></p></div><a class="cvd-secondary" href="<?php echo esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ); ?>">Cambiar cuenta</a></header>
			<nav class="cvd-app-nav cvd-messenger-nav" aria-label="Panel del mensajero"><a href="#hoy">Inicio</a><a href="#contactos">Contactos</a><a href="#preparar">Preparar</a><a href="#ruta">Mi ruta</a><a href="#asistente">Asistente</a></nav>
			<section class="cvd-messenger-launchpad" aria-label="Acciones rápidas"><a class="cvd-primary cvd-upload-voucher" href="<?php echo esc_url( home_url( '/interpretar-vale/' ) ); ?>"><span aria-hidden="true">＋</span> Subir vale</a><a class="cvd-secondary" href="#asistente">Asistente</a></section>
			<?php echo self::messenger_today_summary( $active, $offers ); ?>
			<?php echo self::messenger_assistant( $active ); ?>
			<div class="cvd-messenger-alert-control"><button class="cvd-secondary" id="cvd-enable-notifications" type="button">Activar alertas</button></div>
			<?php echo self::messenger_contacts( $active ); ?>
			<?php echo self::messenger_preparation( $active ); ?>
			<?php echo self::messenger_route( $active, $user ); ?>
			<section class="cvd-panel cvd-offers-panel" id="ofertas"><h2>Carreras</h2><?php echo self::messenger_offers( $offers ); ?></section>
			<section class="cvd-panel" id="entregas"><h2>Entrega activa</h2><?php echo self::delivery_orders( $in_delivery ); ?></section>
			<?php echo self::messenger_closeout( $orders ); ?>
			<div class="cvd-stats cvd-messenger-earnings" id="ganancias"><article><span>Pendiente</span><strong><?php echo esc_html( number_format_i18n( $earnings['pending'], 0 ) ); ?> CUP</strong></article><article><span>Aprobado</span><strong><?php echo esc_html( number_format_i18n( $earnings['approved'], 0 ) ); ?> CUP</strong></article></div>
			<?php echo CVD_Messenger_Accounting::render_messenger( $user ); ?>
			<section class="cvd-panel" id="perfil"><div><p class="cvd-kicker">Disponibilidad</p><h2>Mi jornada</h2><p><?php echo esc_html( $user->user_email ); ?></p></div><form method="post"><label>Estado<select name="cvd_messenger_availability"><option value="available" <?php selected( get_user_meta( $user->ID, '_cvd_messenger_available', true ), 'yes' ); ?>>Disponible</option><option value="unavailable" <?php selected( get_user_meta( $user->ID, '_cvd_messenger_available', true ), 'no' ); ?>>No disponible</option></select></label><label>Zona preferida<input name="cvd_messenger_zone" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_zone', true ) ); ?>" placeholder="Ej. Nuevo Vedado"></label><?php wp_nonce_field( 'cvd_messenger_availability', 'cvd_messenger_availability_nonce' ); ?><button class="cvd-primary" type="submit">Guardar jornada</button></form></section>
			<div class="cvd-offer-modal" id="cvd-offer-modal" role="dialog" aria-modal="true" aria-labelledby="cvd-offer-title" hidden><div class="cvd-offer-modal-card"><p class="cvd-kicker">Nueva carrera</p><h2 id="cvd-offer-title">¿La aceptas?</h2><div class="cvd-offer-modal-data"><p><span>Destino</span><strong data-offer-zone></strong></p><p><span>Tu ganancia</span><strong data-offer-earning></strong></p><p><span>Pedido</span><strong data-offer-items></strong></p></div><small>La dirección exacta y los datos del cliente se muestran después de aceptar.</small><div class="cvd-offer-modal-actions"><a class="cvd-secondary" data-offer-decline>Ahora no</a><a class="cvd-primary" data-offer-accept>Aceptar carrera</a></div></div></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Asistente determinista y de solo lectura sobre pedidos ya autorizados al mensajero. */
	private static function messenger_assistant( array $orders ): string {
		$pending_contact = 0; $morning = array(); $change = array(); $split = array(); $missing = array(); $products = array(); $zones = array();
		foreach ( $orders as $order ) {
			$number = '#' . $order->get_order_number();
			if ( ! CVD_Messenger_Contacts::latest( $order ) ) { $pending_contact++; }
			if ( 'morning' === sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) ) ) { $morning[] = $number; }
			$change_label = self::messenger_change_label( $order ); if ( $change_label ) { $change[] = $number . ': ' . $change_label; }
			$obligations = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::for_order( $order ) : array();
			$payers = array_unique( array_column( $obligations, 'payer' ) ); if ( count( $payers ) > 1 ) { $split[] = $number; }
			$gaps = array(); if ( ! trim( (string) $order->get_billing_phone() ) ) { $gaps[] = 'teléfono'; } if ( ! trim( (string) $order->get_meta( '_cvd_map_url', true ) ) ) { $gaps[] = 'mapa'; } if ( ! CVD_Shipping_Rates::order_fee( $order ) ) { $gaps[] = 'tarifa'; } if ( $gaps ) { $missing[] = $number . ': ' . implode( ', ', $gaps ); }
			$zone = CVD_Delivery::destination_zone( $order ); if ( $zone ) { $zones[ $zone ] = ( $zones[ $zone ] ?? 0 ) + 1; }
			foreach ( $order->get_items( 'line_item' ) as $item ) { $name = wp_strip_all_tags( $item->get_name() ); $products[ $name ] = ( $products[ $name ] ?? 0 ) + max( 1, (int) $item->get_quantity() ); }
		}
		$data = array(
			'pendingContact' => $pending_contact ? $pending_contact . ' cliente(s) sin resultado de contacto.' : 'Todos los clientes tienen un resultado de contacto.',
			'change' => $change ? implode( ' · ', $change ) : 'No hay vuelto estructurado registrado.',
			'morning' => $morning ? 'Entrega por la mañana: ' . implode( ', ', $morning ) . '.' : 'No hay entregas con franja de mañana registrada.',
			'split' => $split ? 'Mensajería dividida: ' . implode( ', ', $split ) . '.' : 'No hay pedidos asignados con pagador dividido.',
			'missing' => $missing ? 'FALTA INFORMACIÓN — ' . implode( ' · ', $missing ) : 'No faltan teléfono, mapa ni tarifa en los pedidos activos.',
			'prepare' => $products ? implode( ' · ', array_map( static fn( $name, $quantity ) => $quantity . '× ' . $name, array_keys( $products ), $products ) ) : 'No hay productos pendientes de preparación.',
			'zones' => $zones ? implode( ' · ', array_map( static fn( $zone, $count ) => $zone . ': ' . $count, array_keys( $zones ), $zones ) ) : 'No hay zonas activas.',
		);
		return '<section class="cvd-panel cvd-operational-assistant" id="asistente" data-cvd-assistant data-assistant-context="' . esc_attr( wp_json_encode( $data ) ) . '"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Asistente operativo</p><h2>Pregunta por tu jornada</h2><p>Responde solo con tus pedidos asignados. No cambia estados ni inventa datos.</p></div></div><div class="cvd-assistant-chips"><button type="button" data-assistant-question="contact">¿Quién falta por llamar?</button><button type="button" data-assistant-question="change">¿Cuánto vuelto llevo?</button><button type="button" data-assistant-question="prepare">¿Qué debe preparar tienda?</button><button type="button" data-assistant-question="missing">¿Qué falta antes de salir?</button></div><form data-assistant-form><label for="cvd-assistant-question">Escribe una pregunta</label><div><input id="cvd-assistant-question" type="text" autocomplete="off" placeholder="Ej.: ¿Quién pidió por la mañana?"><button class="cvd-primary" type="submit">Consultar</button></div></form><div class="cvd-assistant-answer" role="status" aria-live="polite" hidden><strong>Respuesta</strong><p></p></div></section>';
	}

	private static function gestora_history( array $orders ): string {
		if ( ! $orders ) {
			return '<p>Todavía no hay pedidos vinculados a tu cuenta.</p>';
		}
		$labels = array( 'pending' => 'Por verificar', 'approved' => 'Aprobada', 'paid' => 'Pagada', 'cancelled' => 'Cancelada' );
		$html = '<div class="cvd-table-wrap"><table><thead><tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Producto</th><th>Importe</th><th>Comisión</th><th>Estado</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$products = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$products[] = $item->get_name() . ' × ' . max( 1, (int) $item->get_quantity() );
			}
			$status = sanitize_key( $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			$commission = (float) $order->get_meta( '_cvd_commission_amount', true );
			$html .= '<tr><td>#' . esc_html( $order->get_order_number() ) . '</td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td><td>' . esc_html( implode( ', ', $products ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . wp_kses_post( wc_price( $commission, array( 'currency' => $order->get_currency() ) ) ) . '</td><td><span class="cvd-badge">' . esc_html( $labels[ $status ] ?? ucfirst( $status ) ) . '</span></td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/** Resumen de lectura del trabajo activo; no crea ni persiste estados. */
	private static function messenger_today_summary( array $orders, array $offers ): string {
		$products = 0; $notes = 0; $without_map = 0; $contacted = 0; $prepared = 0; $loaded = 0; $delivered = 0; $incidents = 0;
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) { $products += max( 1, (int) $item->get_quantity() ); }
			if ( trim( (string) $order->get_customer_note() ) ) { $notes++; }
			if ( ! $order->get_meta( '_cvd_map_url', true ) ) { $without_map++; }
			if ( class_exists( 'CVD_Messenger_Contacts' ) && CVD_Messenger_Contacts::latest( $order ) ) { $contacted++; }
			$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
			$stage = self::messenger_delivery_stage( $order );
			if ( in_array( $operation, array( 'ready', 'with_courier', 'delivered' ), true ) ) { $prepared++; }
			if ( in_array( $stage, array( 'picked_up', 'handed_over', 'delivered', 'cash_returned', 'closed' ), true ) ) { $loaded++; }
			if ( in_array( $stage, array( 'delivered', 'cash_returned', 'closed' ), true ) ) { $delivered++; }
			if ( 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true ) || 'incident' === CVD_Delivery::status( $order ) ) { $incidents++; }
		}
		$html = '<section class="cvd-messenger-today" id="hoy"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Inicio · Hoy</p><h2>Tu jornada</h2><p>Resumen del trabajo asignado y las carreras disponibles ahora.</p></div><span class="cvd-live-badge">Datos de Casa Viva</span></div>';
		$html .= '<div class="cvd-messenger-today-stats"><article><span>Pedidos</span><strong>' . esc_html( count( $orders ) ) . '</strong></article><article><span>Contactados</span><strong>' . esc_html( $contacted ) . '</strong></article><article><span>Pendientes de contacto</span><strong>' . esc_html( max( 0, count( $orders ) - $contacted ) ) . '</strong></article><article><span>Preparados</span><strong>' . esc_html( $prepared ) . '</strong></article><article><span>Cargados</span><strong>' . esc_html( $loaded ) . '</strong></article><article><span>Entregados</span><strong>' . esc_html( $delivered ) . '</strong></article><article><span>Incidencias</span><strong>' . esc_html( $incidents ) . '</strong></article><article><span>Productos</span><strong>' . esc_html( $products ) . '</strong></article><article><span>Carreras disponibles</span><strong>' . esc_html( count( $offers ) ) . '</strong></article></div>';
		if ( $notes || $without_map ) {
			$html .= '<div class="cvd-messenger-alerts" aria-label="Alertas operativas">';
			if ( $notes ) { $html .= '<p><b>' . esc_html( $notes ) . '</b> ' . esc_html( 1 === $notes ? 'pedido tiene una nota que debes revisar.' : 'pedidos tienen notas que debes revisar.' ) . '</p>'; }
			if ( $without_map ) { $html .= '<p><b>' . esc_html( $without_map ) . '</b> ' . esc_html( 1 === $without_map ? 'entrega no tiene enlace de mapa.' : 'entregas no tienen enlace de mapa.' ) . '</p>'; }
			$html .= '</div>';
		}
		return $html . '</section>';
	}

	/** Mensaje de prellamada sin CI, dirección ni información financiera interna. */
	private static function messenger_whatsapp_message( WC_Order $order ): string {
		$name = trim( (string) $order->get_billing_first_name() );
		if ( ! $name ) { $name = trim( (string) $order->get_formatted_billing_full_name() ); }
		$items = array(); $quantity = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$count = max( 1, (int) $item->get_quantity() ); $quantity += $count;
			$items[] = $count . ' × ' . wp_strip_all_tags( $item->get_name() );
		}
		$greeting = $name ? 'Hola ' . $name . ',' : 'Hola,';
		$products = $items ? implode( ', ', $items ) : ( 1 === $quantity ? 'su producto' : 'su pedido' );
		$origin = trim( (string) $order->get_meta( '_cvd_source_store', true ) ) ?: 'Casa Viva';
		$schedule = self::messenger_schedule_label( $order );
		$timing = $schedule ? ' La entrega está prevista ' . $schedule . '.' : '';
		return $greeting . ' soy el mensajero que le llevará su pedido de ' . $origin . ': ' . $products . '.' . $timing . ' ¿Sería tan amable de enviarme su ubicación por WhatsApp para ir directamente? ¿Está disponible para recibirlo? La llamaré antes de llegar.';
	}

	/** Contactos de pedidos asignados. Los resultados quedan en lectura hasta tener un evento canónico. */
	private static function messenger_contacts( array $orders ): string {
		$html = '<section class="cvd-panel cvd-messenger-contacts" id="contactos"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Contactos</p><h2>Clientes asignados</h2><p>Teléfonos y acciones de los pedidos bajo tu responsabilidad.</p></div></div>';
		if ( ! $orders ) { return $html . '<p class="cvd-empty-state">No tienes clientes asignados ahora.</p></section>'; }
		$html .= '<div class="cvd-contact-list">';
		foreach ( $orders as $order ) {
			$status = CVD_Delivery::status( $order ); $stage = self::messenger_delivery_stage( $order );
			$latest_contact = class_exists( 'CVD_Messenger_Contacts' ) ? CVD_Messenger_Contacts::latest( $order ) : null;
			$phones = array_filter( array( trim( (string) $order->get_billing_phone() ), trim( (string) $order->get_meta( '_cvd_alternate_phone', true ) ) ) );
			if ( is_callable( array( $order, 'get_shipping_phone' ) ) ) { $phones[] = trim( (string) $order->get_shipping_phone() ); }
			$phones = array_values( array_unique( array_filter( $phones ) ) );
			$contact_allowed = in_array( $stage, array( 'accepted', 'to_store', 'picked_up', 'handed_over', 'delivered' ), true );
			$html .= '<article><header><div><strong>' . esc_html( $order->get_formatted_billing_full_name() ?: 'Cliente sin nombre' ) . '</strong><small>Pedido #' . esc_html( $order->get_order_number() ) . ' · ' . esc_html( CVD_Delivery::destination_zone( $order ) ) . '</small></div><span class="cvd-badge">' . esc_html( CVD_Delivery::label( $status ) ) . '</span></header>';
			if ( $contact_allowed && $phones ) {
				$html .= '<div class="cvd-contact-phones">';
				foreach ( $phones as $index => $phone ) { $html .= '<p><span>' . esc_html( 0 === $index ? 'Principal' : 'Alternativo' ) . '</span><strong>' . esc_html( $phone ) . '</strong></p>'; }
				$html .= '</div><div class="cvd-contact-actions">';
				foreach ( $phones as $index => $phone ) { $digits = preg_replace( '/\D+/', '', $phone ); if ( 0 === $index ) { $html .= '<a class="cvd-primary" href="https://wa.me/' . esc_attr( $digits ) . '?text=' . rawurlencode( self::messenger_whatsapp_message( $order ) ) . '" target="_blank" rel="noopener">WhatsApp</a>'; } $html .= '<a class="cvd-secondary" href="tel:' . esc_attr( $phone ) . '">Llamar' . ( $index ? ' alternativo' : '' ) . '</a>'; }
				$html .= '</div>';
			} elseif ( ! $contact_allowed ) { $html .= '<p class="cvd-contact-locked">Acepta el pedido para ver y usar los datos de contacto.</p>'; }
			else { $html .= '<p class="cvd-contact-locked">Este pedido no tiene un teléfono disponible.</p>'; }
			$outcomes = array( 'confirmed' => 'Confirmó', 'no_answer' => 'No responde', 'reschedule_requested' => 'Reprogramar', 'location_received' => 'Ubicación recibida' );
			$html .= '<div class="cvd-contact-outcomes" aria-label="Registrar resultado de contacto">';
			foreach ( $outcomes as $value => $label ) { $html .= $contact_allowed ? '<button type="button" data-contact-outcome="' . esc_attr( $value ) . '" data-contact-order="' . esc_attr( $order->get_id() ) . '">' . esc_html( $label ) . '</button>' : '<span>' . esc_html( $label ) . '</span>'; }
			$html .= '</div><small class="cvd-contact-result" aria-live="polite">';
			if ( $latest_contact ) { $labels = array( 'contact.confirmed' => 'Confirmó', 'contact.no_answer' => 'No responde', 'contact.reschedule_requested' => 'Reprogramar', 'contact.location_received' => 'Ubicación recibida' ); $html .= 'Último resultado: ' . esc_html( $labels[ $latest_contact['event_type'] ] ?? $latest_contact['event_type'] ) . ' · ' . esc_html( get_date_from_gmt( $latest_contact['timestamp'], 'j M · H:i' ) ); }
			else { $html .= 'Sin resultado de contacto registrado.'; }
			$html .= '</small></article>';
		}
		return $html . '</div></section>';
	}

	/** Manifiesto consolidado de lectura; preparación y verificación siguen perteneciendo a tienda. */
	private static function messenger_preparation( array $orders ): string {
		$pickup = trim( (string) get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) );
		$products = array(); $order_numbers = array(); $checks = array(); $notes = array(); $ready = 0; $verified = 0; $go_to_store = array();
		foreach ( $orders as $order ) {
			$status = CVD_Delivery::status( $order ); $stage = self::messenger_delivery_stage( $order );
			if ( ! in_array( $stage, array( 'accepted', 'to_store', 'picked_up' ), true ) ) { continue; }
			$order_numbers[] = '#' . $order->get_order_number();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$variation = array(); foreach ( $item->get_formatted_meta_data() as $meta ) { if ( str_starts_with( (string) $meta->key, '_' ) ) { continue; } $variation[] = wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value ); }
				$label = (string) $item->get_name() . ( $variation ? ' · ' . implode( ' · ', $variation ) : '' );
				$key = $item->get_product_id() . ':' . $item->get_variation_id() . ':' . hash( 'sha256', $label );
				if ( ! isset( $products[ $key ] ) ) { $products[ $key ] = array( 'label' => $label, 'quantity' => 0 ); }
				$products[ $key ]['quantity'] += max( 1, (int) $item->get_quantity() );
			}
			$note = trim( (string) $order->get_customer_note() ); if ( $note ) { $notes[] = '#' . $order->get_order_number() . ': ' . $note; }
			$change = self::messenger_change_label( $order ); if ( $change ) { $notes[] = '#' . $order->get_order_number() . ': Llevar vuelto de ' . $change . '.'; }
			$schedule = self::messenger_schedule_label( $order ); if ( $schedule ) { $notes[] = '#' . $order->get_order_number() . ': Entrega solicitada ' . $schedule . '.'; }
			if ( 'incident' === $status ) { $notes[] = '#' . $order->get_order_number() . ': Incidencia activa; confirma con Casa Viva antes de cargar.'; }
			$operation = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) ); $is_ready = in_array( $operation, array( 'ready', 'with_courier', 'delivered' ), true ); if ( $is_ready ) { $ready++; }
			$is_verified = 'picked_up' === $stage && (bool) $order->get_meta( '_cvd_handed_over_by', true ); if ( $is_verified ) { $verified++; }
			$checks[] = array( 'number' => $order->get_order_number(), 'ready' => $is_ready, 'verified' => $is_verified );
			if ( 'accepted' === $status ) { $go_to_store[] = $order; }
		}
		$html = '<section class="cvd-panel cvd-messenger-preparation" id="preparar"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Preparar salida</p><h2>Recogida en Casa Viva</h2><p>' . esc_html( $pickup ?: 'Punto de recogida por confirmar' ) . '</p></div><button class="cvd-secondary" type="button" data-cvd-refresh-preparation>Actualizar</button></div>';
		if ( ! $order_numbers ) { return $html . '<p class="cvd-empty-state">No tienes pedidos aceptados pendientes de carga.</p></section>'; }
		$html .= '<div class="cvd-preparation-status"><p><span>Pedidos</span><strong>' . esc_html( count( $order_numbers ) ) . '</strong></p><p><span>Preparados por tienda</span><strong>' . esc_html( $ready ) . '</strong></p><p><span>Verificados al cargar</span><strong>' . esc_html( $verified ) . '</strong></p></div><div class="cvd-preparation-manifest"><h3>Productos consolidados</h3><ul>';
		foreach ( $products as $product ) { $html .= '<li><strong>' . esc_html( $product['quantity'] ) . '×</strong><span>' . esc_html( $product['label'] ) . '</span></li>'; }
		$html .= '</ul><small>Pedidos: ' . esc_html( implode( ', ', $order_numbers ) ) . '</small><div class="cvd-preparation-checks">';
		foreach ( $checks as $check ) { $html .= '<p><strong>#' . esc_html( $check['number'] ) . '</strong><span class="' . ( $check['ready'] ? 'is-done' : '' ) . '">Preparado por tienda: ' . ( $check['ready'] ? 'Sí' : 'Pendiente' ) . '</span><span class="' . ( $check['verified'] ? 'is-done' : '' ) . '">Carga verificada: ' . ( $check['verified'] ? 'Sí' : 'Pendiente' ) . '</span></p>'; }
		$html .= '</div></div>';
		if ( $notes ) { $html .= '<div class="cvd-preparation-alerts" role="note"><strong>Vuelto y notas reales</strong>'; foreach ( $notes as $note ) { $html .= '<p>' . esc_html( $note ) . '</p>'; } $html .= '</div>'; }
		$summary = "Resumen para tienda — Casa Viva\nRecogida: " . ( $pickup ?: 'Por confirmar' ) . "\nPedidos: " . implode( ', ', $order_numbers ); foreach ( $products as $product ) { $summary .= "\n- " . $product['quantity'] . 'x ' . $product['label']; } foreach ( $notes as $note ) { $summary .= "\n⚠ " . $note; }
		$html .= '<div class="cvd-preparation-actions"><a class="cvd-secondary" href="https://wa.me/?text=' . rawurlencode( $summary ) . '" target="_blank" rel="noopener">Compartir resumen por WhatsApp</a>';
		foreach ( $go_to_store as $order ) { $html .= '<a class="cvd-primary" data-confirm-delivery="to_store" href="' . esc_url( CVD_Delivery::action_url( $order->get_id(), 'to_store' ) ) . '">Voy a recoger #' . esc_html( $order->get_order_number() ) . '</a>'; }
		$html .= '</div><p class="cvd-preparation-sync">Los cambios canónicos generan aviso al mensajero y esta vista comprueba actualizaciones cada 8 segundos.</p></section>';
		return $html;
	}

	/** Etapa logística conservada por Core cuando una incidencia aditiva está activa. */
	private static function messenger_delivery_stage( WC_Order $order ): string {
		if ( 'yes' === $order->get_meta( '_cvd_delivery_incident_active', true ) ) {
			$preserved = sanitize_key( (string) $order->get_meta( '_cvd_delivery_incident_stage', true ) );
			if ( $preserved ) { return $preserved; }
		}
		return sanitize_key( (string) $order->get_meta( '_cvd_delivery_status', true ) ) ?: CVD_Delivery::status( $order );
	}

	private static function messenger_change_label( WC_Order $order ): string {
		$items = $order->get_meta( '_cvd_change_required', true ); if ( ! is_array( $items ) ) { return ''; }
		$labels = array(); foreach ( $items as $item ) { $amount = (float) ( $item['amount'] ?? 0 ); $currency = strtoupper( sanitize_key( (string) ( $item['currency'] ?? '' ) ) ); if ( $amount > 0 && in_array( $currency, array( 'USD', 'CUP', 'EUR' ), true ) ) { $labels[] = number_format_i18n( $amount, 2 ) . ' ' . $currency; } }
		return implode( ' + ', $labels );
	}

	private static function messenger_schedule_label( WC_Order $order ): string {
		$date = trim( (string) $order->get_meta( '_cvd_delivery_date', true ) ); $window = sanitize_key( (string) $order->get_meta( '_cvd_delivery_window', true ) );
		$parts = array(); if ( $date ) { $parts[] = $date; } if ( $window ) { $parts[] = array( 'morning' => 'por la mañana', 'afternoon' => 'por la tarde' )[ $window ] ?? $window; }
		return implode( ' ', $parts );
	}

	/** Orden manual de sesión. No escribe el pedido ni sustituye una ruta canónica. */
	private static function messenger_route( array $orders, WP_User $user ): string {
		$route = array_filter( $orders, static function ( $order ) {
			return in_array( self::messenger_delivery_stage( $order ), array( 'accepted', 'to_store', 'picked_up', 'handed_over' ), true );
		} );
		$html = '<section class="cvd-panel cvd-messenger-route" id="ruta" data-route-list data-route-key="cvd-route-' . esc_attr( $user->ID ) . '"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Mi ruta</p><h2>Paradas de hoy</h2><p>Ordena manualmente tus pedidos aceptados. NEXO no optimiza esta ruta todavía.</p></div><button class="cvd-secondary" type="button" data-route-reset>Restaurar orden</button></div>';
		if ( ! $route ) { return $html . '<p class="cvd-empty-state">Acepta una carrera para añadirla a tu ruta.</p></section>'; }
		$html .= '<div class="cvd-route-list">';
		foreach ( $route as $order ) {
			$address = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
			$phone = trim( (string) $order->get_billing_phone() );
			$reference = trim( (string) $order->get_meta( '_cvd_reference', true ) );
			$note = trim( (string) $order->get_customer_note() );
			$map_url = esc_url( (string) $order->get_meta( '_cvd_map_url', true ) );
			$shipping_cup = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
			$html .= '<article data-route-stop="' . esc_attr( $order->get_id() ) . '"><header><div><span class="cvd-route-position">Parada</span><strong>#' . esc_html( $order->get_order_number() ) . ' · ' . esc_html( CVD_Delivery::destination_zone( $order ) ?: 'Zona por confirmar' ) . '</strong></div><div class="cvd-route-order"><button type="button" data-route-up aria-label="Subir pedido ' . esc_attr( $order->get_order_number() ) . '">Subir</button><button type="button" data-route-down aria-label="Bajar pedido ' . esc_attr( $order->get_order_number() ) . '">Bajar</button></div></header>';
			$html .= '<p class="cvd-route-address">' . ( $address ? wp_kses_post( $address ) : '<b>Dirección no registrada</b>' ) . '</p>' . ( $reference ? '<small><b>Referencia:</b> ' . esc_html( $reference ) . '</small>' : '<small>Referencia no registrada</small>' );
			$html .= '<div class="cvd-route-items"><strong>Productos</strong><ul>'; foreach ( $order->get_items( 'line_item' ) as $item ) { $html .= '<li>' . esc_html( max( 1, (int) $item->get_quantity() ) . ' × ' . $item->get_name() ) . '</li>'; } $html .= '</ul></div>';
			$html .= '<div class="cvd-delivery-money"><div><span>Pedido</span><strong>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</strong></div><div><span>Mensajería total</span><strong>' . esc_html( $shipping_cup ? number_format_i18n( $shipping_cup, 0 ) . ' CUP' : 'No registrada' ) . '</strong></div>';
			$collectible = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::customer_collectible( $order ) : array();
			if ( $collectible ) { foreach ( $collectible as $obligation ) { $html .= '<div class="cvd-customer-collectible"><span>Cobrar al cliente</span><strong>' . esc_html( number_format_i18n( (float) $obligation['amount'], 2 ) . ' ' . $obligation['currency'] ) . '</strong><small>' . esc_html( ucfirst( $obligation['concept'] ) . ' · ' . $obligation['method'] ) . '</small></div>'; } }
			else { $html .= '<div class="cvd-customer-collectible"><span>Cobrar al cliente</span><strong>Por confirmar</strong><small>No se infiere del total.</small></div>'; }
			$html .= '</div>';
			$schedule = self::messenger_schedule_label( $order ); $change = self::messenger_change_label( $order );
			$html .= '<div class="cvd-route-window"><span>Fecha / horario</span><strong>' . esc_html( $schedule ?: 'Sin preferencia registrada' ) . '</strong></div>';
			if ( $change ) { $html .= '<div class="cvd-delivery-note" role="note"><b>Vuelto confirmado</b><p>Llevar ' . esc_html( $change ) . ' de vuelto.</p></div>'; }
			if ( $note ) { $html .= '<div class="cvd-delivery-note" role="note"><b>Vuelto, nota o restricción</b><p>' . esc_html( $note ) . '</p></div>'; }
			$html .= '<div class="cvd-messenger-tools">'; if ( $phone ) { $digits = preg_replace( '/\D+/', '', $phone ); $html .= '<a class="cvd-secondary" href="tel:' . esc_attr( $phone ) . '">Llamar</a><a class="cvd-secondary" href="https://wa.me/' . esc_attr( $digits ) . '?text=' . rawurlencode( self::messenger_whatsapp_message( $order ) ) . '" target="_blank" rel="noopener">WhatsApp</a>'; } if ( $map_url ) { $html .= '<a class="cvd-secondary" href="' . $map_url . '" target="_blank" rel="noopener">Abrir mapa</a>'; } $html .= '</div>';
			if ( ! $phone || ! $map_url ) { $html .= '<small class="cvd-domain-gap">' . esc_html( ! $phone && ! $map_url ? 'Teléfono y mapa no registrados.' : ( ! $phone ? 'Teléfono no registrado.' : 'Mapa no registrado.' ) ) . '</small>'; }
			$html .= '</article>';
		}
		return $html . '</div><p class="cvd-route-disclaimer">El orden se conserva solo durante esta sesión del navegador; no modifica pedidos ni estados de Casa Viva.</p></section>';
	}

	/** Resumen de lectura posterior a ENTREGADO; el arqueo sigue en Casa Viva Core. */
	private static function messenger_closeout( array $orders ): string {
		$closed = array_filter( $orders, static fn( $order ) => in_array( self::messenger_delivery_stage( $order ), array( 'delivered', 'cash_returned', 'closed' ), true ) );
		$html = '<section class="cvd-panel cvd-messenger-closeout" id="cierre"><div class="cvd-messenger-section-head"><div><p class="cvd-kicker">Cierre de entrega</p><h2>Entregas recientes</h2><p>Lectura del cierre y la conciliación registrados en Casa Viva.</p></div></div>';
		if ( ! $closed ) { return $html . '<p class="cvd-empty-state">Las entregas marcadas como entregadas aparecerán aquí.</p></section>'; }
		$html .= '<div class="cvd-closeout-list">';
		foreach ( $closed as $order ) {
			$status = self::messenger_delivery_stage( $order ); $cash = sanitize_key( (string) $order->get_meta( '_cvd_cash_status', true ) );
			$method = sanitize_key( (string) $order->get_meta( '_cvd_collection_method', true ) ); $usd = (string) $order->get_meta( '_cvd_collection_amount_usd', true ); $cup = (string) $order->get_meta( '_cvd_collection_amount_cup', true );
			$method_labels = array( 'cash' => 'Efectivo', 'cash_usd' => 'Efectivo USD', 'cash_cup' => 'Efectivo CUP', 'transfer' => 'Transferencia', 'mixed' => 'Mixto', 'other' => 'Otro' );
			$time = (string) $order->get_meta( 'closed' === $status ? '_cvd_cash_verified_at' : ( 'cash_returned' === $status ? '_cvd_cash_returned_at' : '_cvd_delivered_at' ), true );
			$html .= '<article><header><div><strong>Pedido #' . esc_html( $order->get_order_number() ) . '</strong><small>' . esc_html( $time ? get_date_from_gmt( $time, 'j M Y · H:i' ) : 'Hora no registrada' ) . '</small></div><span class="cvd-badge">' . esc_html( CVD_Delivery::label( $status ) ) . '</span></header><div class="cvd-closeout-state"><span>Conciliación</span><strong>' . esc_html( 'verified' === $cash ? 'Verificada' : ( 'returned' === $cash ? 'Dinero recibido por Casa Viva' : 'Pendiente de entregar a Casa Viva' ) ) . '</strong></div>';
			if ( $method || '' !== $usd || '' !== $cup ) { $html .= '<dl><div><dt>Medio registrado</dt><dd>' . esc_html( $method_labels[ $method ] ?? ( $method ?: 'No especificado' ) ) . '</dd></div><div><dt>Recibido USD</dt><dd>' . esc_html( '' !== $usd ? number_format_i18n( (float) $usd, 2 ) . ' USD' : 'No registrado' ) . '</dd></div><div><dt>Recibido CUP</dt><dd>' . esc_html( '' !== $cup ? number_format_i18n( (float) $cup, 2 ) . ' CUP' : 'No registrado' ) . '</dd></div></dl>'; }
			else { $html .= '<p class="cvd-domain-gap">Core no registra todavía el cobro estructurado por moneda/medio desde la acción del mensajero. No se infiere del total.</p>'; }
			$html .= '<small>El mensajero no puede cerrar la conciliación: Casa Viva conserva verificación, auditoría y arqueo.</small></article>';
		}
		return $html . '</div></section>';
	}

	private static function delivery_orders( array $orders ): string {
		if ( ! $orders ) { return '<p>No tienes entregas asignadas en este momento.</p>'; }
		$html = '<div class="cvd-deliveries">';
		foreach ( $orders as $order ) {
			$status = $order->get_meta( '_cvd_delivery_status', true ) ?: 'assigned';
			$address = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
			$shipping_cup = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
			$phone = trim( (string) $order->get_billing_phone() );
			$reference = trim( (string) $order->get_meta( '_cvd_reference', true ) );
			$note = trim( (string) $order->get_customer_note() );
			$zone = trim( (string) CVD_Delivery::destination_zone( $order ) );
			$operation_status = sanitize_key( (string) $order->get_meta( '_cvd_operation_status', true ) );
			$html .= '<article data-delivery-id="' . esc_attr( $order->get_id() ) . '" data-delivery-status="' . esc_attr( $status ) . '" data-operation-status="' . esc_attr( $operation_status ) . '"><header><div><small class="cvd-delivery-zone">' . esc_html( $zone ?: 'Zona por confirmar' ) . '</small><strong>Pedido #' . esc_html( $order->get_order_number() ) . '</strong><small>' . esc_html( $order->get_formatted_billing_full_name() ) . ( $phone ? ' · ' . esc_html( $phone ) : '' ) . '</small></div><span class="cvd-badge">' . esc_html( CVD_Delivery::label( $status ) ) . '</span></header>';
			$html .= '<div class="cvd-delivery-address"><strong>Entregar en</strong><p>' . ( $address ? wp_kses_post( $address ) : 'Dirección por confirmar' ) . '</p>' . ( $reference ? '<small><b>Referencia:</b> ' . esc_html( $reference ) . '</small>' : '' ) . '</div>';
			$schedule = self::messenger_schedule_label( $order ); $change = self::messenger_change_label( $order );
			if ( $schedule ) { $html .= '<div class="cvd-route-window"><span>Fecha / horario</span><strong>' . esc_html( $schedule ) . '</strong></div>'; }
			$html .= '<div class="cvd-delivery-products"><strong>Productos</strong><ul>';
			foreach ( $order->get_items( 'line_item' ) as $item ) { $html .= '<li><span>' . esc_html( max( 1, (int) $item->get_quantity() ) . ' × ' . $item->get_name() ) . '</span><b>' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</b></li>'; }
			$order_total = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );
			$html .= '</ul></div><div class="cvd-delivery-money"><div><span>Pedido</span><strong>' . wp_kses_post( $order_total ) . '</strong></div><div><span>Mensajería</span><strong>' . esc_html( $shipping_cup ? number_format_i18n( $shipping_cup, 0 ) . ' CUP' : 'Por confirmar' ) . '</strong></div></div>';
			if ( $change ) { $html .= '<div class="cvd-delivery-note" role="note"><b>Vuelto confirmado</b><p>Llevar ' . esc_html( $change ) . ' de vuelto.</p></div>'; }
			if ( $note ) { $html .= '<div class="cvd-delivery-note" role="note"><b>Nota operativa</b><p>' . esc_html( $note ) . '</p></div>'; }
			$map_url = esc_url( (string) $order->get_meta( '_cvd_map_url', true ) );
			if ( in_array( $status, array( 'accepted', 'to_store', 'picked_up', 'handed_over' ), true ) ) {
				$html .= '<div class="cvd-messenger-tools">';
				if ( $phone ) { $digits = preg_replace( '/\D+/', '', $phone ); $html .= '<a class="cvd-secondary" href="https://wa.me/' . esc_attr( $digits ) . '?text=' . rawurlencode( self::messenger_whatsapp_message( $order ) ) . '" target="_blank" rel="noopener">WhatsApp</a><a class="cvd-secondary" href="tel:' . esc_attr( $phone ) . '">Llamar</a>'; }
				if ( $map_url ) { $html .= '<a class="cvd-secondary cvd-map-action" href="' . $map_url . '" target="_blank" rel="noopener">Navegar</a>'; }
				$html .= '</div>';
			}
			if ( 'handed_over' === $status ) { $html .= '<div class="cvd-live-control" data-live-order="' . esc_attr( $order->get_id() ) . '"><div><strong>Ubicación durante la carrera</strong><small data-live-status>Desactivada</small></div><button class="cvd-primary" type="button" data-live-toggle>Compartir ubicación</button><p>Déjala activa hasta entregar. La aplicación debe permanecer abierta.</p></div>'; }
			$pickup_url = CVD_Delivery::pickup_url( $order );
			if ( $pickup_url ) { $html .= '<div class="cvd-pickup-qr"><button type="button" class="cvd-qr-expand" data-qr-expand aria-label="Ampliar QR de recogida"><canvas data-pickup-qr="' . esc_attr( $pickup_url ) . '"></canvas><span>Ampliar QR</span></button><div><strong>QR de recogida</strong><small>Tócalo para ampliarlo. La dependienta lo abre con la cámara y su cuenta iniciada. Si falla, busca el pedido #' . esc_html( $order->get_order_number() ) . '.</small></div></div>'; }
			if ( 'to_store' === $status ) { $html .= '<div class="cvd-next-step"><strong>Esperando a Casa Viva</strong><small>La tienda confirma cuando recibas el pedido. Esta pantalla se actualizará sola.</small></div>'; }
			if ( 'picked_up' === $status ) { $html .= '<div class="cvd-next-step"><strong>Pedido recibido</strong><small>Confirma cuando salgas hacia el cliente.</small></div>'; }
			$html .= '<footer>';
			$actions = array();
			if ( 'assigned' === $status ) { $actions = array( 'accepted' => 'Aceptar', 'incident' => 'Incidencia' ); }
			if ( 'accepted' === $status ) { $actions = array( 'to_store' => 'Voy a recoger', 'incident' => 'Incidencia' ); }
			if ( 'to_store' === $status ) { $actions = array( 'incident' => 'Incidencia' ); }
			if ( 'picked_up' === $status ) { $actions = array( 'handed_over' => 'En camino al cliente', 'incident' => 'Incidencia' ); }
			if ( 'handed_over' === $status ) { $actions = array( 'incident' => 'Incidencia' ); }
			if ( 'handed_over' === $status ) {
				$obligations = class_exists( 'CVD_Payment_Obligations' ) ? CVD_Payment_Obligations::customer_collectible( $order ) : array();
				$html .= '<form class="cvd-delivery-collection" method="post" action="' . esc_url( CVD_Delivery::action_url( $order->get_id(), 'delivered' ) ) . '"><strong>Confirmar entrega y cobro real</strong><p>Registra únicamente lo recibido. No se completa ni se infiere desde el total.</p>';
				if ( $obligations ) { foreach ( $obligations as $obligation ) { $html .= '<label>' . esc_html( 'Cobrar al cliente · ' . ucfirst( $obligation['concept'] ) . ' · ' . $obligation['method'] ) . '<input name="collection_allocations[' . esc_attr( $obligation['id'] ) . ']" type="number" inputmode="decimal" min="0" step="0.01" value="' . esc_attr( $obligation['amount'] ) . '" required><small>' . esc_html( $obligation['currency'] ) . '</small></label>'; } }
				else { $html .= '<label>Medio<select name="collection_method" required><option value="">Selecciona</option><option value="cash_usd">Efectivo USD</option><option value="cash_cup">Efectivo CUP</option><option value="transfer">Transferencia</option><option value="mixed">Mixto</option><option value="other">Otro</option></select></label><label>Recibido USD<input name="collected_usd" type="number" inputmode="decimal" min="0" step="0.01" value="0" required></label><label>Recibido CUP<input name="collected_cup" type="number" inputmode="decimal" min="0" step="0.01" value="0" required></label>'; }
				$html .= '<label class="cvd-collection-wide">Nota de cobro<input name="collection_note" maxlength="240"></label><label class="cvd-collection-confirm"><input name="money_confirmed" type="checkbox" value="1" required> Confirmo que estos son los importes recibidos</label><button class="cvd-primary cvd-messenger-primary" data-confirm-delivery="delivered" type="submit">Entregado · registrar cobro</button></form>';
			}
			foreach ( $actions as $next => $label ) {
				if ( 'incident' === $next ) { $html .= '<details class="cvd-incident"><summary>' . esc_html( $label ) . '</summary><form class="cvd-incident-form" method="post" action="' . esc_url( CVD_Delivery::action_url( $order->get_id(), $next ) ) . '"><input name="note" required maxlength="240" placeholder="¿Qué ocurrió?"><button type="submit">Enviar</button></form></details>'; }
				else { $html .= '<a data-confirm-delivery="' . esc_attr( $next ) . '" href="' . esc_url( CVD_Delivery::action_url( $order->get_id(), $next ) ) . '">' . esc_html( $label ) . '</a>'; }
			}
			$html .= '</footer></article>';
		}
		return $html . '</div>';
	}

	private static function messenger_offers( array $offers ): string {
		if ( ! $offers ) { return '<p class="cvd-empty-state">No hay carreras disponibles ahora.</p>'; }
		$html = '<div class="cvd-offer-list">';
		$user = wp_get_current_user();
		foreach ( $offers as $order ) {
			$items = 0; foreach ( $order->get_items( 'line_item' ) as $item ) { $items += max( 1, (int) $item->get_quantity() ); }
			$match = CVD_Delivery::zone_matches( $user, $order );
			$html .= '<article data-offer-id="' . esc_attr( $order->get_id() ) . '"><div><span class="cvd-badge">' . ( $match ? 'Cerca de tu zona' : 'Disponible' ) . '</span><h3>' . esc_html( CVD_Delivery::destination_zone( $order ) ) . '</h3><p>Recogida en Casa Viva · ' . esc_html( $items ) . ' producto' . ( 1 === $items ? '' : 's' ) . '</p></div><strong>' . esc_html( number_format_i18n( CVD_Delivery::courier_amount( $order ), 0 ) ) . ' CUP</strong><footer><a class="cvd-primary" href="' . esc_url( CVD_Delivery::offer_action_url( $order->get_id(), 'accept' ) ) . '">Aceptar</a><a class="cvd-secondary" href="' . esc_url( CVD_Delivery::offer_action_url( $order->get_id(), 'decline' ) ) . '">Ahora no</a></footer></article>';
		}
		return $html . '</div>';
	}
}
