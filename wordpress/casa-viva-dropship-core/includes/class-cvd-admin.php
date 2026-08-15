<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Admin {
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
	}

	public static function menu(): void {
		$pending = count( get_users( array( 'role' => 'cvd_gestora', 'meta_key' => '_cvd_account_status', 'meta_value' => 'pending', 'fields' => 'ids' ) ) );
		$badge = $pending ? ' <span class="awaiting-mod count-' . absint( $pending ) . '"><span class="pending-count">' . absint( $pending ) . '</span></span>' : '';
		add_menu_page( 'Casa Viva', 'Casa Viva' . $badge, 'manage_woocommerce', 'cvd-gestoras', array( __CLASS__, 'gestoras_page' ), 'dashicons-store', 56 );
		add_submenu_page( 'cvd-gestoras', 'Solicitudes de gestoras', 'Solicitudes', 'manage_woocommerce', 'cvd-gestoras', array( __CLASS__, 'gestoras_page' ) );
		add_submenu_page( 'cvd-gestoras', 'Configuración Casa Viva', 'Configuración', 'manage_woocommerce', 'casa-viva-dropship', array( __CLASS__, 'settings_page' ) );
	}

	public static function settings(): void {
		register_setting( 'cvd_settings', 'cvd_default_commission_rate', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'cvd_settings', 'cvd_cookie_days', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'cvd_settings', 'cvd_central_whatsapp', array( 'sanitize_callback' => array( __CLASS__, 'phone' ) ) );
		register_setting( 'cvd_settings', 'cvd_default_province', array( 'sanitize_callback' => array( __CLASS__, 'province' ) ) );
		register_setting( 'cvd_settings', 'cvd_pickup_address', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cvd_settings', 'cvd_default_max_markup_percent', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'cvd_settings', 'cvd_notification_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( 'cvd_settings', 'cvd_shipping_platform_percent', array( 'sanitize_callback' => array( __CLASS__, 'percentage' ) ) );
		register_setting( 'cvd_settings', 'cvd_location_retention_days', array( 'sanitize_callback' => array( __CLASS__, 'retention_days' ) ) );
		foreach ( array( 'zone', 'rating', 'completion', 'speed', 'fairness' ) as $factor ) { register_setting( 'cvd_settings', 'cvd_dispatch_' . $factor . '_weight', array( 'sanitize_callback' => array( __CLASS__, 'dispatch_weight' ) ) ); }
		register_setting( 'cvd_settings', 'cvd_dispatch_first_wave_size', array( 'sanitize_callback' => array( __CLASS__, 'wave_size' ) ) );
		register_setting( 'cvd_settings', 'cvd_dispatch_wave_delay_seconds', array( 'sanitize_callback' => array( __CLASS__, 'wave_delay' ) ) );
	}

	public static function settings_page(): void {
		?>
		<div class="wrap">
			<h1>Casa Viva Dropship</h1>
			<p>Reglas generales del sistema. Los valores individuales de una gestora o producto tienen prioridad.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'cvd_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="cvd_notification_email">Correo de solicitudes</label></th>
						<td><input class="regular-text" id="cvd_notification_email" name="cvd_notification_email" type="email" value="<?php echo esc_attr( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ) ); ?>"><p class="description">Casa Viva recibirá aquí las nuevas solicitudes de gestoras.</p></td>
					</tr>
					<tr>
						<th><label for="cvd_default_max_markup_percent">Aumento máximo predeterminado (%)</label></th>
						<td><input id="cvd_default_max_markup_percent" min="0" name="cvd_default_max_markup_percent" step="0.01" type="number" value="<?php echo esc_attr( get_option( 'cvd_default_max_markup_percent', 30 ) ); ?>"><p class="description">Se aplica cuando el producto o la gestora no tengan un límite específico.</p></td>
					</tr>
					<tr>
						<th><label for="cvd_default_commission_rate">Comisión predeterminada (%)</label></th>
						<td><input id="cvd_default_commission_rate" name="cvd_default_commission_rate" step="0.01" type="number" value="<?php echo esc_attr( get_option( 'cvd_default_commission_rate', 13 ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="cvd_cookie_days">Días de referencia en el dispositivo</label></th>
						<td><input id="cvd_cookie_days" max="400" min="1" name="cvd_cookie_days" type="number" value="<?php echo esc_attr( get_option( 'cvd_cookie_days', 400 ) ); ?>"><p class="description">La base de datos conserva la relación permanentemente. Los navegadores limitan la duración de las cookies.</p></td>
					</tr>
					<tr>
						<th><label for="cvd_central_whatsapp">WhatsApp central</label></th>
						<td><input id="cvd_central_whatsapp" name="cvd_central_whatsapp" placeholder="5350000000" type="text" value="<?php echo esc_attr( get_option( 'cvd_central_whatsapp', '' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label for="cvd_default_province">Provincia predeterminada</label></th>
						<td><select id="cvd_default_province" name="cvd_default_province"><?php foreach ( CVD_Cuban_Checkout::provinces() as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( get_option( 'cvd_default_province', 'LH' ), $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></td>
					</tr>
					<tr>
						<th><label for="cvd_pickup_address">Dirección de recogida</label></th>
						<td><input class="regular-text" id="cvd_pickup_address" name="cvd_pickup_address" type="text" value="<?php echo esc_attr( get_option( 'cvd_pickup_address', 'Nuevo Vedado, La Habana' ) ); ?>"></td>
					</tr>
					<tr><th><label for="cvd_shipping_platform_percent">Comisión de mensajería Casa Viva (%)</label></th><td><input id="cvd_shipping_platform_percent" min="0" max="100" name="cvd_shipping_platform_percent" step="0.01" type="number" value="<?php echo esc_attr( get_option( 'cvd_shipping_platform_percent', 10 ) ); ?>"><p class="description">Se guarda como instantánea en cada entrega para que cambios futuros no alteren operaciones anteriores.</p></td></tr>
					<tr><th><label for="cvd_location_retention_days">Conservación de ubicación (días)</label></th><td><input id="cvd_location_retention_days" min="1" max="90" name="cvd_location_retention_days" type="number" value="<?php echo esc_attr( get_option( 'cvd_location_retention_days', 30 ) ); ?>"><p class="description">La ubicación se recopilará solo durante entregas activas y se eliminará automáticamente al vencer este plazo.</p></td></tr>
					<tr><th>Prioridad de mensajeros</th><td><p class="description">Pesos relativos. No necesitan sumar 100; el sistema los normaliza.</p><?php foreach ( array( 'zone'=>'Zona','rating'=>'Evaluación','completion'=>'Entregas completadas','speed'=>'Rapidez al aceptar','fairness'=>'Reparto equitativo' ) as $factor=>$label ) : ?><label style="display:inline-block;margin:0 14px 8px 0"><?php echo esc_html($label); ?> <input min="0" max="100" name="cvd_dispatch_<?php echo esc_attr($factor); ?>_weight" type="number" value="<?php echo esc_attr(get_option('cvd_dispatch_'.$factor.'_weight',10)); ?>" style="width:72px"></label><?php endforeach; ?></td></tr>
					<tr><th>Rondas de oferta</th><td><label>Mensajeros en primera ronda <input min="1" max="20" name="cvd_dispatch_first_wave_size" type="number" value="<?php echo esc_attr(get_option('cvd_dispatch_first_wave_size',2)); ?>" style="width:72px"></label> <label style="margin-left:16px">Espera para ampliar <input min="30" max="600" name="cvd_dispatch_wave_delay_seconds" type="number" value="<?php echo esc_attr(get_option('cvd_dispatch_wave_delay_seconds',90)); ?>" style="width:82px"> segundos</label><p class="description">Si nadie acepta, la carrera se ofrece automáticamente al resto.</p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function percentage( $value ): float { return min( 100, max( 0, (float) $value ) ); }
	public static function retention_days( $value ): int { return min( 90, max( 1, absint( $value ) ) ); }
	public static function dispatch_weight( $value ): int { return min( 100, max( 0, absint( $value ) ) ); }
	public static function wave_size( $value ): int { return min( 20, max( 1, absint( $value ) ) ); }
	public static function wave_delay( $value ): int { return min( 600, max( 30, absint( $value ) ) ); }

	public static function gestoras_page(): void {
		$email_notice = '';
		$whatsapp_notice = '';
		if ( isset( $_POST['cvd_gestora_action'] ) && check_admin_referer( 'cvd_manage_gestora' ) ) {
			$user_id = absint( $_POST['user_id'] ?? 0 ); $status = sanitize_key( wp_unslash( $_POST['cvd_gestora_action'] ) );
			if ( $user_id && in_array( $status, array( 'approved', 'suspended', 'rejected' ), true ) ) {
				$user = get_user_by( 'id', $user_id );
				if ( $user && ! in_array( 'cvd_gestora', (array) $user->roles, true ) ) {
					$user->add_role( 'cvd_gestora' );
				}
				update_user_meta( $user_id, '_cvd_account_status', $status );
				if ( 'approved' === $status && ! get_user_meta( $user_id, '_cvd_referral_code', true ) ) {
					update_user_meta( $user_id, '_cvd_referral_code', 'CV' . $user_id . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $user ? $user->user_login : 'GESTORA' ), 0, 8 ) ) );
				}
				if ( $user ) {
					clean_user_cache( $user_id );
					$user = get_user_by( 'id', $user_id );
					$access_link = 'approved' === $status && $user ? CVD_Registration::create_secure_access_link( $user ) : '';
					$email_notice = self::send_status_email( $user, $status, $access_link ) ? ' Se envió el aviso por correo.' : ' El estado cambió, pero WordPress no pudo enviar el correo.';
					$phone = preg_replace( '/\D+/', '', (string) get_user_meta( $user_id, '_cvd_whatsapp', true ) );
					if ( 'approved' === $status && $phone && $access_link ) {
						$message = "Hola {$user->display_name}, tu cuenta de gestora Casa Viva fue aprobada. Entra directamente a tu panel con este enlace seguro (válido por 7 días): {$access_link}";
						$whatsapp_url = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
						$whatsapp_notice = ' <a class="button button-primary" href="' . esc_attr( $whatsapp_url ) . '" target="_blank" rel="noopener">Avisar por WhatsApp</a>';
					}
				}
				echo '<div class="notice notice-success"><p>Estado actualizado.' . esc_html( $email_notice ) . $whatsapp_notice . '</p></div>';
			}
		}
		$users = get_users( array( 'role' => 'cvd_gestora', 'orderby' => 'registered', 'order' => 'DESC' ) );
		echo '<div class="wrap"><h1>Solicitudes de gestoras</h1><p>Aquí Casa Viva aprueba, suspende o rechaza las cuentas. Las solicitudes pendientes aparecen primero.</p><table class="widefat striped"><thead><tr><th>Gestora</th><th>WhatsApp</th><th>Zona</th><th>Estado</th><th>Correo</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$status = get_user_meta( $user->ID, '_cvd_account_status', true ) ?: 'pending';
			$email_state = get_user_meta( $user->ID, '_cvd_application_email_sent', true );
			echo '<tr><td><strong>' . esc_html( $user->display_name ) . '</strong><br><small>' . esc_html( $user->user_email ) . '</small></td><td>' . esc_html( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ) . '</td><td>' . esc_html( get_user_meta( $user->ID, '_cvd_zone', true ) ) . '</td><td><strong>' . esc_html( ucfirst( $status ) ) . '</strong></td><td>' . esc_html( 'failed' === $email_state ? 'Falló' : ( $email_state ? 'Enviado' : 'Sin registro' ) ) . '</td><td><form method="post">';
			$approve_label = 'approved' === $status ? 'Reenviar acceso' : 'Aprobar';
			wp_nonce_field( 'cvd_manage_gestora' ); echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '"><button class="button button-primary" name="cvd_gestora_action" value="approved">' . esc_html( $approve_label ) . '</button> <button class="button" name="cvd_gestora_action" value="suspended">Suspender</button> <button class="button" name="cvd_gestora_action" value="rejected">Rechazar</button> <a class="button" href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">Editar</a></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function send_status_email( WP_User $user, string $status, string $access_link = '' ): bool {
		$labels = array( 'approved' => 'Aprobada', 'suspended' => 'Suspendida', 'rejected' => 'No aprobada' );
		$label = $labels[ $status ] ?? ucfirst( $status );
		$subject = 'Estado de tu cuenta de gestora Casa Viva: ' . $label;
		if ( 'approved' === $status ) {
			$message = "Hola {$user->display_name},\n\nTu cuenta de gestora fue aprobada.\n\nEntra directamente a tu panel con este enlace seguro:\n{$access_link}\n\nEl enlace es válido durante 7 días y abre exactamente tu cuenta. Después podrás entrar normalmente desde Mi cuenta.\n\nSi olvidaste tu contraseña, puedes recuperarla aquí:\n" . wp_lostpassword_url( home_url( '/area-gestoras/' ) ) . "\n\nCasa Viva";
		} else {
			$message = "Hola {$user->display_name},\n\nEl estado de tu solicitud es: {$label}.\n\nSi necesitas ayuda, contacta con Casa Viva.";
		}
		$sent = wp_mail( $user->user_email, $subject, $message );
		update_user_meta( $user->ID, '_cvd_status_email_sent', $sent ? current_time( 'mysql', true ) : 'failed' );
		return $sent;
	}

	public static function user_fields( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		?>
		<h2>Casa Viva Dropship</h2>
		<table class="form-table">
			<tr>
				<th><label for="_cvd_referral_code">Código de referencia</label></th>
				<td><input class="regular-text" id="_cvd_referral_code" name="_cvd_referral_code" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_referral_code', true ) ); ?>"><p class="description">Ejemplo: NATALY25. No podrá ser reemplazado por otro enlace una vez asignado un cliente.</p></td>
			</tr>
			<tr>
				<th><label for="_cvd_whatsapp">WhatsApp</label></th>
				<td><input class="regular-text" id="_cvd_whatsapp" name="_cvd_whatsapp" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_whatsapp', true ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="_cvd_commission_rate">Comisión personal (%)</label></th>
				<td><input id="_cvd_commission_rate" name="_cvd_commission_rate" step="0.01" type="number" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_commission_rate', true ) ); ?>"><p class="description">Déjalo vacío para usar la comisión general.</p></td>
			</tr>
			<tr>
				<th><label for="_cvd_max_markup_percent">Aumento máximo personal (%)</label></th>
				<td><input id="_cvd_max_markup_percent" min="0" name="_cvd_max_markup_percent" step="0.01" type="number" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_max_markup_percent', true ) ); ?>"><p class="description">Vacío: utiliza el límite general.</p></td>
			</tr>
			<tr>
				<th><label for="_cvd_account_status">Estado de la cuenta</label></th>
				<td><select id="_cvd_account_status" name="_cvd_account_status"><option value="pending" <?php selected( get_user_meta( $user->ID, '_cvd_account_status', true ), 'pending' ); ?>>Pendiente</option><option value="approved" <?php selected( get_user_meta( $user->ID, '_cvd_account_status', true ), 'approved' ); ?>>Aprobada</option><option value="suspended" <?php selected( get_user_meta( $user->ID, '_cvd_account_status', true ), 'suspended' ); ?>>Suspendida</option><option value="rejected" <?php selected( get_user_meta( $user->ID, '_cvd_account_status', true ), 'rejected' ); ?>>Rechazada</option></select><p class="description">Solo las cuentas aprobadas pueden entrar en su portal.</p></td>
			</tr>
			<tr>
				<th><label for="_cvd_zone">Municipio o zona</label></th>
				<td><input class="regular-text" id="_cvd_zone" name="_cvd_zone" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_zone', true ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="_cvd_vehicle">Transporte del mensajero</label></th>
				<td><input class="regular-text" id="_cvd_vehicle" name="_cvd_vehicle" type="text" value="<?php echo esc_attr( get_user_meta( $user->ID, '_cvd_vehicle', true ) ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	public static function save_user_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['_cvd_referral_code'] ) ) {
			$code = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', wp_unslash( $_POST['_cvd_referral_code'] ) ) );
			update_user_meta( $user_id, '_cvd_referral_code', $code );
		}
		if ( isset( $_POST['_cvd_whatsapp'] ) ) {
			update_user_meta( $user_id, '_cvd_whatsapp', self::phone( wp_unslash( $_POST['_cvd_whatsapp'] ) ) );
		}
		if ( isset( $_POST['_cvd_commission_rate'] ) ) {
			$value = '' === $_POST['_cvd_commission_rate'] ? '' : (string) max( 0, (float) $_POST['_cvd_commission_rate'] );
			update_user_meta( $user_id, '_cvd_commission_rate', $value );
		}
		if ( isset( $_POST['_cvd_max_markup_percent'] ) ) {
			$value = '' === $_POST['_cvd_max_markup_percent'] ? '' : (string) max( 0, (float) $_POST['_cvd_max_markup_percent'] );
			update_user_meta( $user_id, '_cvd_max_markup_percent', $value );
		}
		if ( isset( $_POST['_cvd_account_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['_cvd_account_status'] ) );
			if ( in_array( $status, array( 'pending', 'approved', 'suspended', 'rejected' ), true ) ) { update_user_meta( $user_id, '_cvd_account_status', $status ); }
		}
		foreach ( array( '_cvd_zone', '_cvd_vehicle' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) { update_user_meta( $user_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ); }
		}
	}

	public static function product_fields(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => '_cvd_supplier_name',
				'label'       => 'Proveedor',
				'description' => 'Casa Viva u otra tienda responsable del producto.',
			)
		);
		woocommerce_wp_text_input( array( 'id' => '_cvd_min_price', 'label' => 'Precio mínimo', 'data_type' => 'price' ) );
		woocommerce_wp_text_input( array( 'id' => '_cvd_max_price', 'label' => 'Precio máximo', 'data_type' => 'price' ) );
		woocommerce_wp_select(
			array(
				'id'      => '_cvd_commission_type',
				'label'   => 'Tipo de comisión',
				'options' => array( 'percent' => 'Porcentaje', 'fixed' => 'Importe fijo por unidad' ),
			)
		);
		woocommerce_wp_text_input( array( 'id' => '_cvd_commission_value', 'label' => 'Valor de comisión', 'type' => 'number', 'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ) ) );
	}

	public static function save_product_fields( int $product_id ): void {
		$fields = array(
			'_cvd_supplier_name'    => 'sanitize_text_field',
			'_cvd_min_price'        => 'wc_format_decimal',
			'_cvd_max_price'        => 'wc_format_decimal',
			'_cvd_commission_type'  => 'sanitize_key',
			'_cvd_commission_value' => 'wc_format_decimal',
		);

		foreach ( $fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $product_id, $field, call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	public static function phone( string $phone ): string {
		return preg_replace( '/\D+/', '', $phone );
	}

	public static function province( string $province ): string {
		$province = strtoupper( sanitize_key( $province ) );
		return array_key_exists( $province, CVD_Cuban_Checkout::provinces() ) ? $province : 'LH';
	}
}
