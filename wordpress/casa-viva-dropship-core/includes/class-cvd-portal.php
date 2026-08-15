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
		if ( is_page( array( 'gestores', 'area-gestoras', 'area-mensajeros', 'registro-gestora', 'registro-mensajero' ) ) ) {
			wp_enqueue_style( 'cvd-portal', plugins_url( 'assets/portal.css', CVD_FILE ), array(), CVD_VERSION );
			wp_enqueue_script( 'cvd-qr-code', CVD_URL . 'assets/qr-code.js', array(), CVD_VERSION, true );
			wp_enqueue_script( 'cvd-portal', plugins_url( 'assets/portal.js', CVD_FILE ), array( 'cvd-qr-code' ), CVD_VERSION, true );
			wp_localize_script( 'cvd-portal', 'cvdPortal', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cvd_portal_price' ),
				'messengerFeedUrl' => rest_url( 'casa-viva/v1/messenger/feed' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'isMessenger' => is_user_logged_in() && 'mensajero' === CVD_Registration::program_type( wp_get_current_user() ),
			) );
		}
	}

	public static function redirect_gestora_account(): void {
		if ( ! is_user_logged_in() ) {
			if ( is_page( 'area-gestoras' ) ) { wp_safe_redirect( add_query_arg( 'acceso', 'gestoras', home_url( '/mi-cuenta/' ) ) . '#cv-customer-login' ); exit; }
			if ( is_page( 'area-mensajeros' ) ) { wp_safe_redirect( add_query_arg( 'acceso', 'mensajeros', home_url( '/mi-cuenta/' ) ) . '#cv-customer-login' ); exit; }
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
		$earnings = array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0 );
		foreach ( $orders as $order ) {
			$status = sanitize_key( (string) $order->get_meta( '_cvd_messenger_earning_status', true ) ) ?: ( 'closed' === $order->get_meta( '_cvd_delivery_status', true ) ? 'approved' : 'pending' );
			if ( isset( $earnings[ $status ] ) ) { $earnings[ $status ] += (float) $order->get_meta( '_cvd_shipping_courier_amount_cup', true ); }
		}
		ob_start();
		?>
		<section class="cvd-dashboard cvd-app-shell">
			<?php if ( isset( $_GET['oferta'] ) ) : $offer_result = sanitize_key( wp_unslash( $_GET['oferta'] ) ); ?><div class="cvd-notice" role="status"><?php echo esc_html( 'accepted' === $offer_result ? 'Carrera aceptada. Presenta el QR en la tienda.' : ( 'declined' === $offer_result ? 'Oferta descartada.' : 'La carrera ya no está disponible.' ) ); ?></div><?php endif; ?>
			<header class="cvd-dashboard-head cvd-messenger-head"><div><p class="cvd-kicker">Mensajero</p><h1>Hola, <?php echo esc_html( $user->display_name ); ?>.</h1><p><?php echo esc_html( $offers ? 'Tienes una carrera por responder.' : ( $active ? 'Continúa tu entrega activa.' : 'Estás listo para recibir carreras.' ) ); ?></p></div><a class="cvd-secondary" href="<?php echo esc_url( wp_logout_url( home_url( '/casa-viva-app/' ) ) ); ?>">Cambiar cuenta</a></header>
			<nav class="cvd-app-nav cvd-messenger-nav" aria-label="Panel del mensajero"><a href="#ofertas">Carreras</a><a href="#entregas">Mi entrega</a><a href="#ganancias">Ganancias</a><a href="#perfil">Disponibilidad</a></nav>
			<div class="cvd-messenger-alert-control"><button class="cvd-secondary" id="cvd-enable-notifications" type="button">Activar alertas</button></div>
			<section class="cvd-panel cvd-offers-panel" id="ofertas"><h2>Carreras</h2><?php echo self::messenger_offers( $offers ); ?></section>
			<section class="cvd-panel" id="entregas"><h2>Mi entrega</h2><?php echo self::delivery_orders( $active ); ?></section>
			<div class="cvd-stats cvd-messenger-earnings" id="ganancias"><article><span>Pendiente</span><strong><?php echo esc_html( number_format_i18n( $earnings['pending'], 0 ) ); ?> CUP</strong></article><article><span>Aprobado</span><strong><?php echo esc_html( number_format_i18n( $earnings['approved'], 0 ) ); ?> CUP</strong></article></div>
			<?php echo CVD_Messenger_Accounting::render_messenger( $user ); ?>
			<section class="cvd-panel" id="perfil"><div><p class="cvd-kicker">Disponibilidad</p><h2>Mi jornada</h2><p><?php echo esc_html( $user->user_email ); ?></p></div><form method="post"><label>Estado<select name="cvd_messenger_availability"><option value="available" <?php selected( get_user_meta( $user->ID, '_cvd_messenger_available', true ), 'yes' ); ?>>Disponible</option><option value="unavailable" <?php selected( get_user_meta( $user->ID, '_cvd_messenger_available', true ), 'no' ); ?>>No disponible</option></select></label><label>Zona preferida<input name="cvd_messenger_zone" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_zone', true ) ); ?>" placeholder="Ej. Nuevo Vedado"></label><?php wp_nonce_field( 'cvd_messenger_availability', 'cvd_messenger_availability_nonce' ); ?><button class="cvd-primary" type="submit">Guardar jornada</button></form></section>
			<div class="cvd-offer-modal" id="cvd-offer-modal" role="dialog" aria-modal="true" aria-labelledby="cvd-offer-title" hidden><div class="cvd-offer-modal-card"><p class="cvd-kicker">Nueva carrera</p><h2 id="cvd-offer-title">¿La aceptas?</h2><div class="cvd-offer-modal-data"><p><span>Destino</span><strong data-offer-zone></strong></p><p><span>Tu ganancia</span><strong data-offer-earning></strong></p><p><span>Pedido</span><strong data-offer-items></strong></p></div><small>La dirección exacta y los datos del cliente se muestran después de aceptar.</small><div class="cvd-offer-modal-actions"><a class="cvd-secondary" data-offer-decline>Ahora no</a><a class="cvd-primary" data-offer-accept>Aceptar carrera</a></div></div></div>
		</section>
		<?php
		return (string) ob_get_clean();
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

	private static function delivery_orders( array $orders ): string {
		if ( ! $orders ) { return '<p>No tienes entregas asignadas en este momento.</p>'; }
		$html = '<div class="cvd-deliveries">';
		foreach ( $orders as $order ) {
			$status = $order->get_meta( '_cvd_delivery_status', true ) ?: 'assigned';
			$address = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
			$shipping_cup = class_exists( 'CVD_Shipping_Rates' ) ? CVD_Shipping_Rates::order_fee( $order ) : absint( $order->get_meta( '_cvd_shipping_fee_cup', true ) );
			$html .= '<article data-delivery-id="' . esc_attr( $order->get_id() ) . '" data-delivery-status="' . esc_attr( $status ) . '"><header><div><strong>Pedido #' . esc_html( $order->get_order_number() ) . '</strong><small>' . esc_html( $order->get_formatted_billing_full_name() ) . ' · ' . esc_html( $order->get_billing_phone() ) . '</small></div><span class="cvd-badge">' . esc_html( CVD_Delivery::label( $status ) ) . '</span></header><p>' . wp_kses_post( $address ) . '</p><p><strong>Mensajería:</strong> ' . esc_html( $shipping_cup ? number_format_i18n( $shipping_cup, 0 ) . ' CUP' : 'Por confirmar' ) . '</p>';
			$map_url = esc_url( (string) $order->get_meta( '_cvd_map_url', true ) );
			if ( $map_url && in_array( $status, array( 'accepted', 'to_store', 'picked_up', 'handed_over' ), true ) ) { $html .= '<p><a class="cvd-map-action" href="' . $map_url . '" target="_blank" rel="noopener">Abrir ubicación</a></p>'; }
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
			if ( 'handed_over' === $status ) { $actions = array( 'delivered' => 'Entregado', 'incident' => 'Incidencia' ); }
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
