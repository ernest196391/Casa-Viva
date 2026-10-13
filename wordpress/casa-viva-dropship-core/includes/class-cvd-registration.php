<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Registration {
	public static function register(): void {
		add_shortcode( 'casa_viva_registro', array( __CLASS__, 'render' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_pending_login' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_secure_access_link' ), 1 );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'whatsapp_access_hint' ) );
	}

	/** En la pantalla de entrada: las gestoras sin contraseña entran pidiendo su enlace por WhatsApp. */
	public static function whatsapp_access_hint(): void {
		$wa = 'https://wa.me/5354056173?text=' . rawurlencode( 'mi acceso' );
		if ( isset( $_GET['cvd_access'] ) && 'invalid' === sanitize_key( wp_unslash( $_GET['cvd_access'] ) ) ) {
			echo '<div class="cvd-notice cvd-error" role="alert" style="margin-top:14px">Ese enlace para entrar ya se usó o venció. Pide otro: escribe <strong>mi acceso</strong> al WhatsApp 5354056173.</div>';
		}
		echo '<div class="cvd-notice" style="margin-top:14px"><strong>¿Gestor/a o mensajero?</strong> Escribe tu número de WhatsApp arriba, en lugar del correo, con la contraseña que pusiste en <em>Mi perfil</em>.</div>';
		echo '<div class="cvd-notice" style="margin-top:14px"><strong>¿Aún no tienes contraseña o la olvidaste?</strong><br><a class="cvd-primary" style="display:inline-block;margin-top:8px" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener">Entrar con WhatsApp</a><br><small>Te mandamos un enlace para entrar. Dentro, en <em>Mi perfil</em>, pon tu contraseña y ya no tendrás que pedirlo más.</small></div>';
	}

	/** $area ('gestora' | 'mensajero'): en una cuenta doble, el área que abre el enlace. */
	public static function create_secure_access_link( WP_User $user, string $area = '' ): string {
		$token = wp_generate_password( 48, false, false );
		update_user_meta( $user->ID, '_cvd_access_token_hash', wp_hash_password( $token ) );
		update_user_meta( $user->ID, '_cvd_access_token_expires', time() + ( 7 * DAY_IN_SECONDS ) );
		$args = array(
			'cvd_gestora_access' => $token,
			'cvd_user'           => $user->ID,
		);
		if ( self::is_dual( $user ) && in_array( $area, array( 'gestora', 'mensajero' ), true ) ) { $args['cvd_area'] = $area; }
		return add_query_arg( $args, home_url( '/' ) );
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
			// Dependientas también (3.13.49), nunca una cuenta de administración aunque tenga ese rol.
			&& ( self::is_approved_gestora( $user ) || self::is_approved_messenger( $user ) || ( self::is_clerk( $user ) && ! array_intersect( array( 'administrator', 'shop_manager' ), (array) $user->roles ) ) );
		if ( ! $is_valid ) {
			// Dependienta: vuelve a la entrada de la tienda (casa-viva-app), que explica cómo pedir otro enlace.
			$area = $user && self::is_clerk( $user ) && '' === self::program_type( $user ) ? '/casa-viva-app/' : ( $user && 'mensajero' === self::program_type( $user ) ? '/area-mensajeros/' : '/area-gestoras/' );
			wp_safe_redirect( add_query_arg( 'cvd_access', 'invalid', home_url( $area ) ) );
			exit;
		}

		delete_user_meta( $user_id, '_cvd_access_token_hash' );
		delete_user_meta( $user_id, '_cvd_access_token_expires' );
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		// Cuenta doble: el enlace puede pedir un área concreta (cvd_area); si no, la de entrada.
		$area = isset( $_GET['cvd_area'] ) ? sanitize_key( wp_unslash( $_GET['cvd_area'] ) ) : '';
		$type = self::is_dual( $user ) && in_array( $area, array( 'gestora', 'mensajero' ), true ) ? $area : self::entry_type( $user );
		// Dependienta (3.13.49): a Operaciones, donde pone su contraseña y tiene Pedidos a un toque.
		if ( '' === $type && self::is_clerk( $user ) ) {
			wp_safe_redirect( home_url( '/centro-operaciones/#cvd-clave' ) );
			exit;
		}
		wp_safe_redirect( self::portal_url_for_type( $type ) );
		exit;
	}

	/** Dependienta de la tienda (rol cvd_clerk): trabaja en /centro-operaciones/ y /ventas/. */
	public static function is_clerk( WP_User $user ): bool {
		return in_array( 'cvd_clerk', (array) $user->roles, true );
	}

	public static function block_pending_login( WP_User $user ) {
		// Cuenta doble: solo se cierra la entrada si los dos programas están suspendidos o rechazados.
		$statuses = self::is_dual( $user )
			? array( self::status_for( $user, 'gestora' ), self::status_for( $user, 'mensajero' ) )
			: array( (string) get_user_meta( $user->ID, '_cvd_account_status', true ) );
		$blocked = array_filter( $statuses, static fn( string $status ): bool => in_array( $status, array( 'suspended', 'rejected' ), true ) );
		if ( $blocked && count( $blocked ) === count( $statuses ) ) {
			$status = $statuses[0];
			$message = 'rejected' === $status ? 'Esta solicitud no fue aprobada. Contacta con Casa Viva.' : 'Esta cuenta está suspendida. Contacta con Casa Viva.';
			return new WP_Error( 'cvd_account_status', $message );
		}
		return $user;
	}

	public static function portal_url_for_type( string $type ): string {
		return home_url( 'mensajero' === $type ? '/area-mensajeros/' : '/area-gestoras/' );
	}

	public static function render( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'role' => 'gestora' ), $atts );
		$type = 'mensajero' === $atts['role'] ? 'mensajero' : 'gestora';
		// El formulario lleva un nonce: si la caché guarda la página más de un día, a todas les sale "la sesión venció".
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Casa Viva registro: formulario con nonce' );
		$current_user = is_user_logged_in() ? wp_get_current_user() : null;
		if ( $current_user && self::has_program_account( $current_user, $type ) ) {
			$url = self::portal_url_for_type( $type );
			$status = self::status_for( $current_user, $type );
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
			return '<section class="cvd-auth-card"><p class="cvd-kicker">Solicitud recibida</p><h1>Tu cuenta ya fue creada.</h1><p>Ahora está pendiente de aprobación por Casa Viva. No necesitas registrarte otra vez. Te avisaremos por WhatsApp cuando sea aprobada, con tu enlace para entrar.</p><a class="cvd-primary" href="' . esc_url( home_url( '/tienda/' ) ) . '">Ver la tienda mientras tanto</a></section>';
		}

		$inviter = 'gestora' === $type ? self::inviter_from_request() : null;
		$title = 'mensajero' === $type ? 'Trabaja como mensajero de Casa Viva' : 'Vende con Casa Viva como gestor/a';
		$intro = 'mensajero' === $type
			? 'Recibe entregas asignadas, consulta direcciones y actualiza el estado de cada recorrido desde tu área privada.'
			: 'Obtén tu enlace personal, comparte el catálogo y consulta pedidos, clientes y comisiones desde tu área privada.';
		// Cuenta que ya es del otro programa: esta solicitud le añade este a la misma cuenta (cuenta doble).
		$adding_second = $current_user && self::programs( $current_user );
		$account_phone = $current_user ? (string) get_user_meta( $current_user->ID, '_cvd_whatsapp', true ) : '';
		ob_start();
		?>
		<section class="cvd-auth-layout">
			<div class="cvd-auth-intro"><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $intro ); ?></p><?php if ( 'gestora' === $type ) : ?><ul><li>Es gratis. Casa Viva revisa tu solicitud y te avisa por WhatsApp.</li><li>Ganas el 10 % de cada venta (algunos productos tienen un monto fijo).</li><li>Puedes subir tus precios: la diferencia es tuya.</li><li>Entras con tu WhatsApp, sin contraseña.</li></ul><?php else : ?><ul><li>Registro gratuito sujeto a aprobación.</li><li>Información y actividad protegidas por usuario.</li><li>Soporte operativo de Casa Viva.</li></ul><?php endif; ?></div>
			<form class="cvd-form" method="post">
				<h2>Crear solicitud</h2>
				<?php if ( $adding_second ) : ?><p class="cvd-notice"><?php echo esc_html( 'mensajero' === $type ? 'Ya eres gestor/a: esta solicitud añade la mensajería a tu misma cuenta. Sigues vendiendo igual que siempre.' : 'Ya eres mensajero: esta solicitud añade la venta como gestor/a a tu misma cuenta. Sigues haciendo entregas igual que siempre.' ); ?></p><?php endif; ?>
				<?php if ( $inviter ) : ?><p class="cvd-notice">Te invita <strong><?php echo esc_html( $inviter->display_name ); ?></strong>.</p><input name="cvd_invita" type="hidden" value="<?php echo esc_attr( (string) get_user_meta( $inviter->ID, '_cvd_referral_code', true ) ); ?>"><?php endif; ?>
				<?php if ( ! $inviter && 'gestora' === $type && '' !== (string) ( $_GET['invita'] ?? '' ) ) : ?><p class="cvd-notice">Este enlace de invitación ya no vale. Puedes registrarte igual.</p><?php endif; ?>
				<?php foreach ( $errors as $error ) : ?><p class="cvd-error"><?php echo esc_html( $error ); ?></p><?php endforeach; ?>
				<div class="cvd-form-grid">
					<label>Nombre completo<input name="cvd_name" required type="text" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_name'] ?? ( $current_user ? $current_user->display_name : '' ) ) ); ?>"></label>
					<label>WhatsApp<input inputmode="tel" name="cvd_phone" placeholder="+53…" required type="tel" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_phone'] ?? $account_phone ) ); ?>"></label>
					<label>Correo electrónico (opcional)<input name="cvd_email" type="email" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_email'] ?? ( $current_user ? $current_user->user_email : '' ) ) ); ?>" <?php echo $current_user ? 'readonly' : ''; ?>></label>
					<label>Municipio o zona<input name="cvd_zone" required type="text" placeholder="Ej.: Playa, Vedado, Centro Habana" value="<?php echo esc_attr( wp_unslash( $_POST['cvd_zone'] ?? '' ) ); ?>"></label>
					<?php if ( 'mensajero' === $type ) : ?><label>Medio de transporte<select name="cvd_vehicle" required><option value="">Selecciona</option><option>Bicicleta</option><option>Moto</option><option>Auto</option><option>Otro</option></select></label><?php endif; ?>
					<?php if ( ! $current_user ) : ?><label>Contraseña (opcional)<input minlength="8" name="cvd_password" type="password"><small>No hace falta: entras con el enlace que te mandamos por WhatsApp.</small></label><?php endif; ?>
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
		if ( 8 === strlen( $phone ) ) { $phone = '53' . $phone; }
		if ( $current_user && '' === $email ) { $email = $current_user->user_email; }
		if ( ! $name || strlen( $phone ) < 8 || ! $zone ) {
			return new WP_Error( 'invalid', 'Escribe tu nombre, tu WhatsApp y tu municipio o zona.' );
		}
		// WhatsApp de verdad: móvil cubano (53 + 5/6 + 7 cifras) o número extranjero con código de país. "00000000" ya no entra.
		$cuban_mobile = (bool) preg_match( '/^53[56]\d{7}$/', $phone );
		$foreign = ! str_starts_with( $phone, '53' ) && strlen( $phone ) >= 10 && strlen( $phone ) <= 15;
		if ( ( ! $cuban_mobile && ! $foreign ) || preg_match( '/(\d)\1{6,}/', $phone ) ) {
			return new WP_Error( 'invalid_phone', 'Ese WhatsApp no parece válido. Escribe tus 8 cifras (por ejemplo 5XXXXXXX) o, si es de fuera de Cuba, el número con su código de país.' );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'El correo no es válido. Puedes dejarlo vacío.' );
		}
		if ( '' !== $password && strlen( $password ) < 8 ) {
			return new WP_Error( 'short_password', 'La contraseña necesita al menos 8 caracteres. Puedes dejarla vacía.' );
		}
		// Cuenta abierta que ya es del otro programa: esta solicitud le añade el segundo (cuenta doble).
		$current_programs = $current_user ? self::programs( $current_user ) : array();
		if ( in_array( $type, $current_programs, true ) ) {
			return new WP_Error( 'already_in_program', 'Tu cuenta ya tiene esta solicitud. Abre tu área para ver en qué va.' );
		}
		$add_second = (bool) $current_programs;
		if ( $add_second ) {
			// La segunda solicitud va con el mismo WhatsApp de la cuenta: con él entra y con él la encuentra el bot.
			$own_phone = preg_replace( '/\D+/', '', (string) get_user_meta( $current_user->ID, '_cvd_whatsapp', true ) );
			if ( '' !== $own_phone && substr( $own_phone, -8 ) !== substr( $phone, -8 ) ) {
				return new WP_Error( 'phone_mismatch', 'Escribe el mismo WhatsApp de tu cuenta (el que termina en ' . substr( $own_phone, -4 ) . ').' );
			}
			// Si el número es de esta misma cuenta no se comprueba nada más; si la cuenta no tenía WhatsApp, que no sea de otra.
			if ( '' === $own_phone && get_users( array( 'number' => 1, 'fields' => 'ID', 'exclude' => array( $current_user->ID ), 'meta_query' => array( array( 'key' => '_cvd_whatsapp', 'value' => substr( $phone, -8 ), 'compare' => 'LIKE' ) ) ) ) ) {
				return new WP_Error( 'phone_exists', 'Ya hay otra cuenta con este WhatsApp. Escríbenos al 5354056173 y lo arreglamos.' );
			}
		}
		if ( ! $current_user ) {
			$same_phone = get_users( array( 'number' => 1, 'fields' => 'ID', 'meta_query' => array( array( 'key' => '_cvd_whatsapp', 'value' => substr( $phone, -8 ), 'compare' => 'LIKE' ) ) ) );
			if ( $same_phone ) {
				// El número ya es de una cuenta del otro programa: que entre con ella y añada este (cuenta doble).
				$owner = get_userdata( (int) $same_phone[0] );
				$owner_programs = $owner ? self::programs( $owner ) : array();
				if ( $owner_programs && ! in_array( $type, $owner_programs, true ) ) {
					return new WP_Error(
						'phone_other_program',
						'gestora' === $owner_programs[0]
							? 'Ya tienes cuenta de gestor/a con este WhatsApp: entra primero y vuelve a esta página para añadir mensajería.'
							: 'Ya tienes cuenta de mensajero con este WhatsApp: entra primero y vuelve a esta página para añadir la venta como gestor/a.'
					);
				}
				return new WP_Error( 'phone_exists', 'Ya hay una cuenta con este WhatsApp. Entra en Mi cuenta → Gestores → «Entrar con WhatsApp» y te mandamos tu enlace.' );
			}
			// Sin correo ni contraseña: correo interno (no recibe mensajes) y contraseña aleatoria; se entra con el enlace de WhatsApp.
			if ( '' === $email ) { $email = 'g' . $phone . '@gestoras.casaviva.company'; }
			if ( '' === $password ) { $password = wp_generate_password( 24 ); }
		}
		if ( $current_user ) {
			$user_id = $current_user->ID;
			$login = $current_user->user_login;
			$user = $current_user;
			if ( ! hash_equals( strtolower( $current_user->user_email ), strtolower( $email ) ) ) {
				return new WP_Error( 'email_mismatch', 'El correo debe coincidir con la cuenta que tiene abierta.' );
			}
			if ( $add_second ) {
				// Cuenta doble: _cvd_account_status queda para gestora y _cvd_messenger_status para mensajero.
				// Se guarda antes de añadir el rol para que la cuenta ya se reconozca como doble.
				if ( 'mensajero' === $type ) {
					// Gestora que añade mensajería: su estado de gestora no cambia; el de mensajero empieza pendiente.
					update_user_meta( $user_id, '_cvd_messenger_status', 'pending' );
				} else {
					// Mensajero que añade venta: su estado de mensajero pasa a su propia clave y la gestora empieza pendiente.
					update_user_meta( $user_id, '_cvd_messenger_status', (string) get_user_meta( $user_id, '_cvd_account_status', true ) ?: 'pending' );
					update_user_meta( $user_id, '_cvd_account_status', 'pending' );
				}
			}
			$user->add_role( 'mensajero' === $type ? 'cvd_messenger' : 'cvd_gestora' );
		} else {
			if ( email_exists( $email ) ) { return new WP_Error( 'exists', 'Ya existe una cuenta con ese correo electrónico. Inicia sesión primero y vuelve a solicitar.' ); }
			$from_name = str_ends_with( $email, '@gestoras.casaviva.company' ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( $name ) ) ) : '';
			$base_login = $from_name ?: ( sanitize_user( strtok( $email, '@' ), true ) ?: 'casaviva' );
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
		if ( $add_second ) {
			// Cuenta doble: la zona y el transporte del mensajero no se pisan al añadir la venta como gestora.
			if ( 'mensajero' === $type || '' === (string) get_user_meta( $user_id, '_cvd_zone', true ) ) { update_user_meta( $user_id, '_cvd_zone', $zone ); }
			if ( 'mensajero' === $type ) { update_user_meta( $user_id, '_cvd_vehicle', sanitize_text_field( wp_unslash( $_POST['cvd_vehicle'] ?? '' ) ) ); }
			// Programa principal de una cuenta doble: gestora (ver program_type()). Los estados ya se guardaron arriba.
			update_user_meta( $user_id, '_cvd_program_type', 'gestora' );
		} else {
			update_user_meta( $user_id, '_cvd_zone', $zone );
			update_user_meta( $user_id, '_cvd_vehicle', sanitize_text_field( wp_unslash( $_POST['cvd_vehicle'] ?? '' ) ) );
			update_user_meta( $user_id, '_cvd_program_type', $type );
			update_user_meta( $user_id, '_cvd_account_status', 'pending' );
		}
		if ( 'gestora' === $type ) {
			update_user_meta( $user_id, '_cvd_referral_code', self::unique_referral_code( $user_id, $login ) );
			// Red de gestoras: quién la invitó (solo una gestora aprobada, nunca ella misma, y no se reescribe).
			$inviter = self::inviter_from_request();
			if ( $inviter && $inviter->ID !== $user_id && ! get_user_meta( $user_id, '_cvd_invited_by', true ) ) {
				update_user_meta( $user_id, '_cvd_invited_by', $inviter->ID );
			}
		} elseif ( ! $add_second ) {
			// Una gestora que añade mensajería conserva su código: es el de su tienda y sus clientes.
			delete_user_meta( $user_id, '_cvd_referral_code' );
		}
		self::send_application_emails( $user, $type );
		return $user_id;
	}

	/** Gestora aprobada dueña del código de ?invita= (o del campo oculto al enviar el formulario). */
	private static function inviter_from_request(): ?WP_User {
		$code = sanitize_text_field( wp_unslash( $_POST['cvd_invita'] ?? $_GET['invita'] ?? '' ) );
		if ( '' === $code || strlen( $code ) > 40 ) { return null; }
		$ids = get_users( array( 'number' => 1, 'fields' => 'ids', 'role' => 'cvd_gestora', 'meta_key' => '_cvd_referral_code', 'meta_value' => $code ) );
		if ( ! $ids ) { return null; }
		$user = get_userdata( (int) $ids[0] );
		return $user && self::is_approved_gestora( $user ) ? $user : null;
	}

	/** Enlace que una gestora comparte para invitar a otras. */
	public static function invite_url( WP_User $user ): string {
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		return $code ? add_query_arg( 'invita', rawurlencode( $code ), home_url( '/registro-gestora/' ) ) : '';
	}

	private static function send_application_emails( WP_User $user, string $type ): void {
		$program = 'mensajero' === $type ? 'mensajero' : 'gestor/a'; // solo texto de los correos
		$admin_email = sanitize_email( get_option( 'cvd_notification_email', get_option( 'admin_email' ) ) );
		$admin_url = admin_url( 'admin.php?page=cvd-gestoras' );
		$applicant_subject = 'Casa Viva recibió tu solicitud';
		$applicant_message = "Hola {$user->display_name},\n\nRecibimos tu solicitud para participar como {$program}.\n\nEstado: Pendiente de aprobación.\n\nPuedes consultar el estado aquí:\n" . self::portal_url_for_type( $type ) . "\n\nCasa Viva";
		$internal = str_ends_with( strtolower( $user->user_email ), '@gestoras.casaviva.company' );
		$applicant_sent = $internal ? false : wp_mail( $user->user_email, $applicant_subject, $applicant_message );
		update_user_meta( $user->ID, '_cvd_application_email_sent', $applicant_sent ? current_time( 'mysql', true ) : 'failed' );

		if ( $admin_email ) {
			$admin_subject = 'Nueva solicitud de ' . $program . ' en Casa Viva';
			$admin_message = "Nueva solicitud pendiente.\n\nNombre: {$user->display_name}\nCorreo: {$user->user_email}\nWhatsApp: " . get_user_meta( $user->ID, '_cvd_whatsapp', true ) . "\n\nRevisar y aprobar:\n{$admin_url}";
			$admin_sent = wp_mail( $admin_email, $admin_subject, $admin_message );
			update_user_meta( $user->ID, '_cvd_admin_notification_sent', $admin_sent ? current_time( 'mysql', true ) : 'failed' );
		}
	}

	/**
	 * Cuenta doble: la misma persona es gestora y mensajero a la vez.
	 * Se reconoce por tener los dos roles Y su estado propio de mensajero (_cvd_messenger_status).
	 * Sin esa marca, una cuenta con los dos roles es una cuenta antigua de un solo programa
	 * (CVD_Plugin::normalize_program_roles la repara) y sigue funcionando como siempre.
	 */
	public static function is_dual( WP_User $user ): bool {
		$roles = (array) $user->roles;
		return in_array( 'cvd_gestora', $roles, true )
			&& in_array( 'cvd_messenger', $roles, true )
			&& '' !== (string) get_user_meta( $user->ID, '_cvd_messenger_status', true );
	}

	/** Programas de la cuenta: ninguno (cliente), uno ('gestora' o 'mensajero') o los dos si es doble. */
	public static function programs( WP_User $user ): array {
		if ( self::is_dual( $user ) ) { return array( 'gestora', 'mensajero' ); }
		$type = self::program_type( $user );
		return '' === $type ? array() : array( $type );
	}

	public static function has_program_account( WP_User $user, string $type = 'gestora' ): bool {
		return in_array( $type, self::programs( $user ), true );
	}

	/**
	 * Clave donde se guarda el estado de alta de un programa. Siempre _cvd_account_status,
	 * salvo el mensajero de una cuenta doble, que tiene la suya: _cvd_messenger_status.
	 */
	public static function status_meta_key( WP_User $user, string $type ): string {
		return 'mensajero' === $type && self::is_dual( $user ) ? '_cvd_messenger_status' : '_cvd_account_status';
	}

	/** Estado de alta en un programa: pending, approved, suspended, rejected (o vacío). */
	public static function status_for( WP_User $user, string $type ): string {
		return (string) get_user_meta( $user->ID, self::status_meta_key( $user, $type ), true );
	}

	public static function set_status( WP_User $user, string $type, string $status ): void {
		update_user_meta( $user->ID, self::status_meta_key( $user, $type ), $status );
	}

	/**
	 * Programa con el que actúa la cuenta en la pantalla actual. Una cuenta doble es mensajero
	 * en /area-mensajeros/ y /ruta-cv/, y gestora en todo lo demás.
	 */
	public static function context_type( WP_User $user ): string {
		if ( self::is_dual( $user ) && is_page( array( 'area-mensajeros', 'ruta-cv' ) ) ) { return 'mensajero'; }
		return self::program_type( $user );
	}

	/**
	 * Área a la que se lleva la cuenta al entrar. Con un solo programa, la suya.
	 * Cuenta doble: la elegida en el selector de la entrada (cvd_access_type, o ?acceso= en
	 * Mi cuenta); si no eligió, gestora, salvo que solo esté aprobada como mensajero.
	 */
	public static function entry_type( WP_User $user ): string {
		if ( ! self::is_dual( $user ) ) { return self::program_type( $user ); }
		$selected = isset( $_POST['cvd_access_type'] ) ? sanitize_key( wp_unslash( $_POST['cvd_access_type'] ) ) : '';
		if ( '' === $selected && isset( $_GET['acceso'] ) ) { $selected = sanitize_key( wp_unslash( $_GET['acceso'] ) ); }
		if ( 'mensajeros' === $selected ) { return 'mensajero'; }
		if ( 'gestoras' === $selected ) { return 'gestora'; }
		return ! self::is_approved_gestora( $user ) && self::is_approved_messenger( $user ) ? 'mensajero' : 'gestora';
	}

	/**
	 * Programa principal de la cuenta: 'gestora', 'mensajero' o '' (cliente).
	 * Cuenta doble: devuelve siempre 'gestora' (programa principal), para que las pantallas y
	 * redirecciones antiguas sigan llevando a /area-gestoras/ y las comisiones no cambien.
	 * Lo propio del mensajero (ofertas, ruta, vales, liquidaciones, avisos) pregunta con
	 * has_program_account( $user, 'mensajero' ) o is_approved_messenger(); lo que depende de la
	 * pantalla usa context_type() y la entrada usa entry_type().
	 */
	public static function program_type( WP_User $user ): string {
		if ( self::is_dual( $user ) ) { return 'gestora'; }
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
			&& 'approved' === self::status_for( $user, 'gestora' );
	}

	public static function is_approved_messenger( WP_User $user ): bool {
		return self::has_program_account( $user, 'mensajero' )
			&& 'approved' === self::status_for( $user, 'mensajero' );
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
