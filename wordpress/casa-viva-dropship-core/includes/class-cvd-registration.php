<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Registration {
	public static function register(): void {
		add_shortcode( 'casa_viva_registro', array( __CLASS__, 'render' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_pending_login' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_secure_access_link' ), 1 );
	}

	public static function create_secure_access_link( WP_User $user ): string {
		$token = wp_generate_password( 48, false, false );
		update_user_meta( $user->ID, '_cvd_access_token_hash', wp_hash_password( $token ) );
		update_user_meta( $user->ID, '_cvd_access_token_expires', time() + ( 7 * DAY_IN_SECONDS ) );
		return add_query_arg(
			array(
				'cvd_gestora_access' => $token,
				'cvd_user'           => $user->ID,
			),
			home_url( '/' )
		);
	}

	public static function handle_secure_access_link(): void {
		$token = isset( $_GET['cvd_gestora_access'] ) ? sanitize_text_field( wp_unslash( $_GET['cvd_gestora_access'] ) ) : '';
		$user_id = isset( $_GET['cvd_user'] ) ? absint( $_GET['cvd_user'] ) : 0;
		if ( ! $token || ! $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		$hash = (string) get_user_meta( $user_id, '_cvd_access_token_hash', true );
		$expires = absint( get_user_meta( $user_id, '_cvd_access_token_expires', true ) );
		$is_valid = $user
			&& $hash
			&& $expires >= time()
			&& wp_check_password( $token, $hash )
			&& self::is_approved_gestora( $user );
		if ( ! $is_valid ) {
			wp_safe_redirect( add_query_arg( 'cvd_access', 'invalid', home_url( '/area-gestoras/' ) ) );
			exit;
		}

		delete_user_meta( $user_id, '_cvd_access_token_hash' );
		delete_user_meta( $user_id, '_cvd_access_token_expires' );
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		wp_safe_redirect( home_url( '/area-gestoras/' ) );
		exit;
	}

	public static function block_pending_login( WP_User $user ) {
		$status = get_user_meta( $user->ID, '_cvd_account_status', true );
		if ( in_array( $status, array( 'suspended', 'rejected' ), true ) ) {
			$message = 'rejected' === $status ? 'Esta solicitud no fue aprobada. Contacta con Casa Viva.' : 'Esta cuenta está suspendida. Contacta con Casa Viva.';
			return new WP_Error( 'cvd_account_status', $message );
		}
		return $user;
	}

	public static function render( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'role' => 'gestora' ), $atts );
		$type = 'mensajero' === $atts['role'] ? 'mensajero' : 'gestora';
		$current_user = is_user_logged_in() ? wp_get_current_user() : null;
		if ( $current_user && self::has_program_account( $current_user, $type ) ) {
			$url = home_url( 'mensajero' === $type ? '/area-mensajeros/' : '/area-gestoras/' );
			$status = get_user_meta( $current_user->ID, '_cvd_account_status', true );
			$label = 'approved' === $status ? 'Abrir mi área' : 'Consultar estado de mi solicitud';
			return '<div class="cvd-notice">Tu cuenta ya está vinculada al programa de Casa Viva. <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>.</div>';
		}

		$errors = array();
		$success = false;
		if ( isset( $_POST['cvd_register_submit'] ) ) {
			if ( ! isset( $_POST['cvd_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_register_nonce'] ) ), 'cvd_register_' . $type ) ) {
				$errors[] = 'La sesión del formulario venció. Actualiza la página e inténtalo otra vez.';
			} else {
				$result = self::create_account( $type, $current_user );
				if ( is_wp_error( $result ) ) { $errors = $result->get_error_messages(); } else { $success = true; }
			}
		}

		if ( $success ) {
			return '<section class="cvd-auth-card"><p class="cvd-kicker">Solicitud recibida</p><h1>Tu cuenta ya fue creada.</h1><p>Ahora está pendiente de aprobación por Casa Viva. No necesitas registrarte otra vez. Te avisaremos por correo cuando sea aprobada.</p><a class="cvd-primary" href="' . esc_url( home_url( '/area-gestoras/' ) ) . '">Consultar mi solicitud</a></section>';
		}

		$title = 'mensajero' === $type ? 'Trabaja como mensajero de Casa Viva' : 'Vende con Casa Viva como gestora';
		$intro = 'mensajero' === $type
			? 'Recibe entregas asignadas, consulta direcciones y actualiza el estado de cada recorrido desde tu área privada.'
			: 'Obtén tu enlace personal, comparte el catálogo y consulta pedidos, clientes y comisiones desde tu área privada.';
		ob_start();
		?>
		<section class="cvd-auth-layout">
			<div class="cvd-auth-intro"><p class="cvd-kicker">Casa Viva</p><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $intro ); ?></p><ul><li>Registro gratuito sujeto a aprobación.</li><li>Información y actividad protegidas por usuario.</li><li>Soporte operativo de Casa Viva.</li></ul></div>
			<form class="cvd-form" method="post">
				<h2>Crear solicitud</h2>
				<?php foreach ( $errors as $error ) : ?><p class="cvd-error"><?php echo esc_html( $error ); ?></p><?php endforeach; ?>
				<div class="cvd-form-grid">
					<label>Nombre completo<input name="cvd_name" required type="text" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_name'] ?? ( $current_user ? $current_user->display_name : '' ) ) ); ?>"></label>
					<label>WhatsApp<input inputmode="tel" name="cvd_phone" placeholder="+53…" required type="tel" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_phone'] ?? '' ) ); ?>"></label>
					<label>Correo electrónico<input name="cvd_email" required type="email" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_email'] ?? ( $current_user ? $current_user->user_email : '' ) ) ); ?>" <?php echo $current_user ? 'readonly' : ''; ?>></label>
					<label>Municipio o zona<input name="cvd_zone" required type="text" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_zone'] ?? '' ) ); ?>"></label>
					<?php if ( 'mensajero' === $type ) : ?><label>Medio de transporte<select name="cvd_vehicle" required><option value="">Selecciona</option><option>Bicicleta</option><option>Moto</option><option>Auto</option><option>Otro</option></select></label><?php endif; ?>
					<?php if ( ! $current_user ) : ?><label>Contraseña<input minlength="8" name="cvd_password" required type="password"><small>Mínimo 8 caracteres.</small></label><?php endif; ?>
				</div>
				<label class="cvd-check"><input name="cvd_truth" required type="checkbox" value="1"> Confirmo que los datos proporcionados son correctos.</label>
				<?php wp_nonce_field( 'cvd_register_' . $type, 'cvd_register_nonce' ); ?>
				<button class="cvd-primary" name="cvd_register_submit" type="submit" value="1">Enviar solicitud</button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function create_account( string $type, ?WP_User $current_user = null ) {
		$name = sanitize_text_field( wp_unslash( $_POST['cvd_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['cvd_email'] ?? '' ) );
		$phone = preg_replace( '/\D+/', '', wp_unslash( $_POST['cvd_phone'] ?? '' ) );
		$zone = sanitize_text_field( wp_unslash( $_POST['cvd_zone'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['cvd_password'] ?? '' );
		if ( ! $name || ! is_email( $email ) || strlen( $phone ) < 8 || ! $zone || ( ! $current_user && strlen( $password ) < 8 ) ) {
			return new WP_Error( 'invalid', 'Completa correctamente todos los campos obligatorios.' );
		}
		if ( $current_user ) {
			$existing_type = self::program_type( $current_user );
			if ( $existing_type && $existing_type !== $type ) {
				return new WP_Error( 'role_conflict', 'Esta cuenta ya pertenece al programa de ' . $existing_type . '. Usa otra cuenta para solicitar un rol diferente.' );
			}
			$user_id = $current_user->ID;
			$login = $current_user->user_login;
			$user = $current_user;
			if ( ! hash_equals( strtolower( $current_user->user_email ), strtolower( $email ) ) ) {
				return new WP_Error( 'email_mismatch', 'El correo debe coincidir con la cuenta que tiene abierta.' );
			}
			$user->add_role( 'mensajero' === $type ? 'cvd_messenger' : 'cvd_gestora' );
		} else {
			if ( email_exists( $email ) ) { return new WP_Error( 'exists', 'Ya existe una cuenta con ese correo electrónico. Inicia sesión primero y vuelve a solicitar.' ); }
			$base_login = sanitize_user( strtok( $email, '@' ), true ) ?: 'casaviva';
			$login = $base_login;
			$counter = 1;
			while ( username_exists( $login ) ) { $login = $base_login . $counter++; }
			$user_id = wp_create_user( $login, $password, $email );
			if ( is_wp_error( $user_id ) ) { return $user_id; }
			$user = new WP_User( $user_id );
			$user->set_role( 'mensajero' === $type ? 'cvd_messenger' : 'cvd_gestora' );
		}
		wp_update_user( array( 'ID' => $user_id, 'display_name' => $name, 'first_name' => $name ) );
		update_user_meta( $user_id, '_cvd_whatsapp', $phone );
		update_user_meta( $user_id, '_cvd_zone', $zone );
		update_user_meta( $user_id, '_cvd_vehicle', sanitize_text_field( wp_unslash( $_POST['cvd_vehicle'] ?? '' ) ) );
		update_user_meta( $user_id, '_cvd_program_type', $type );
		update_user_meta( $user_id, '_cvd_account_status', 'pending' );
		if ( 'gestora' === $type ) {
			update_user_meta( $user_id, '_cvd_referral_code', self::unique_referral_code( $user_id, $login ) );
		} else {
			delete_user_meta( $user_id, '_cvd_referral_code' );
		}
		self::send_application_emails( $user, $type );
		return $user_id;
	}

	private static function send_application_emails( WP_User $user, string $type ): void {
		$program = 'mensajero' === $type ? 'mensajero' : 'gestora';
		$admin_email = sanitize_email( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ) );
		$admin_url = admin_url( 'admin.php?page=cvd-gestoras' );
		$applicant_subject = 'Casa Viva recibió tu solicitud';
		$applicant_message = "Hola {$user->display_name},\n\nRecibimos tu solicitud para participar como {$program}.\n\nEstado: Pendiente de aprobación.\n\nPuedes consultar el estado aquí:\n" . home_url( '/area-gestoras/' ) . "\n\nCasa Viva";
		$applicant_sent = wp_mail( $user->user_email, $applicant_subject, $applicant_message );
		update_user_meta( $user->ID, '_cvd_application_email_sent', $applicant_sent ? current_time( 'mysql', true ) : 'failed' );

		if ( $admin_email ) {
			$admin_subject = 'Nueva solicitud de ' . $program . ' en Casa Viva';
			$admin_message = "Nueva solicitud pendiente.\n\nNombre: {$user->display_name}\nCorreo: {$user->user_email}\nWhatsApp: " . get_user_meta( $user->ID, '_cvd_whatsapp', true ) . "\n\nRevisar y aprobar:\n{$admin_url}";
			$admin_sent = wp_mail( $admin_email, $admin_subject, $admin_message );
			update_user_meta( $user->ID, '_cvd_admin_notification_sent', $admin_sent ? current_time( 'mysql', true ) : 'failed' );
		}
	}

	public static function has_program_account( WP_User $user, string $type = 'gestora' ): bool {
		$role = 'mensajero' === $type ? 'cvd_messenger' : 'cvd_gestora';
		return self::program_type( $user ) === $type && in_array( $role, (array) $user->roles, true );
	}

	public static function program_type( WP_User $user ): string {
		$stored = sanitize_key( (string) get_user_meta( $user->ID, '_cvd_program_type', true ) );
		$roles = (array) $user->roles;
		$gestora = in_array( 'cvd_gestora', $roles, true );
		$mensajero = in_array( 'cvd_messenger', $roles, true );
		if ( 'gestora' === $stored && $gestora && ! $mensajero ) { return 'gestora'; }
		if ( 'mensajero' === $stored && $mensajero && ! $gestora ) { return 'mensajero'; }
		if ( $gestora && ! $mensajero ) { return 'gestora'; }
		if ( $mensajero && ! $gestora ) { return 'mensajero'; }
		if ( $gestora && $mensajero ) { return get_user_meta( $user->ID, '_cvd_vehicle', true ) ? 'mensajero' : 'gestora'; }
		return '';
	}

	public static function is_approved_gestora( WP_User $user ): bool {
		return self::has_program_account( $user, 'gestora' )
			&& 'approved' === get_user_meta( $user->ID, '_cvd_account_status', true );
	}

	private static function unique_referral_code( int $user_id, string $login ): string {
		$stem = 'CV' . $user_id . strtoupper( substr( preg_replace( '/[^A-Z0-9]/i', '', $login ), 0, 8 ) );
		$code = $stem;
		$suffix = 1;
		while ( get_users( array( 'number' => 1, 'meta_key' => '_cvd_referral_code', 'meta_value' => $code, 'fields' => 'ids' ) ) ) {
			$code = $stem . $suffix++;
		}
		return $code;
	}
}
