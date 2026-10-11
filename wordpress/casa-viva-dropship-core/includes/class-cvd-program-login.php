<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cuenta propia para gestoras y mensajeros: ponen su contraseña en "Mi perfil", entran con su
 * número de WhatsApp y su contraseña, y el teléfono recuerda la sesión un año. El enlace de
 * "mi acceso" queda solo para quien olvidó la contraseña.
 */
final class CVD_Program_Login {
	public static function register(): void {
		add_filter( 'authenticate', array( __CLASS__, 'login_with_whatsapp' ), 15, 3 );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'long_session' ), 20, 3 );
		add_action( 'admin_post_cvd_program_password', array( __CLASS__, 'save_password' ) );
	}

	private static function is_program_user( $user ): bool {
		return $user instanceof WP_User && '' !== CVD_Registration::program_type( $user );
	}

	/** "53 5 123 4567", "+5351234567" o "51234567" → su cuenta, si hay una sola con ese WhatsApp. */
	public static function login_with_whatsapp( $user, $username, $password ) {
		if ( $user instanceof WP_User || '' === (string) $password ) { return $user; }
		$raw = trim( (string) $username );
		if ( ! preg_match( '/^\+?[\d\s().-]{8,20}$/', $raw ) ) { return $user; }
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( strlen( $digits ) < 8 ) { return $user; }
		$ids = get_users( array( 'fields' => 'ID', 'number' => 3, 'meta_query' => array( array( 'key' => '_cvd_whatsapp', 'value' => substr( $digits, -8 ), 'compare' => 'LIKE' ) ) ) );
		$matches = array();
		foreach ( $ids as $id ) {
			$candidate = get_userdata( (int) $id );
			if ( $candidate && self::is_program_user( $candidate ) && substr( preg_replace( '/\D+/', '', (string) get_user_meta( $candidate->ID, '_cvd_whatsapp', true ) ), -8 ) === substr( $digits, -8 ) ) { $matches[] = $candidate; }
		}
		if ( 1 !== count( $matches ) ) { return $user; }
		return wp_authenticate_username_password( null, $matches[0]->user_login, $password );
	}

	/** Gestoras y mensajeros: la sesión del teléfono dura un año (antes 14 días). */
	public static function long_session( int $length, int $user_id, bool $remember ): int {
		if ( ! $remember ) { return $length; }
		$user = get_userdata( $user_id );
		return self::is_program_user( $user ) ? YEAR_IN_SECONDS : $length;
	}

	public static function save_password(): void {
		$user = wp_get_current_user();
		$back = wp_get_referer() ?: home_url( '/' );
		if ( ! $user->exists() || ! self::is_program_user( $user ) ) { wp_safe_redirect( $back ); exit; }
		check_admin_referer( 'cvd_program_password' );
		$pass = (string) wp_unslash( $_POST['cvd_pass'] ?? '' );
		$again = (string) wp_unslash( $_POST['cvd_pass2'] ?? '' );
		$flag = 'ok';
		if ( strlen( $pass ) < 8 ) { $flag = 'short'; }
		elseif ( $pass !== $again ) { $flag = 'mismatch'; }
		if ( 'ok' === $flag ) {
			wp_set_password( $pass, $user->ID );
			// wp_set_password cierra las sesiones: se vuelve a abrir en este teléfono, recordada un año.
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
			update_user_meta( $user->ID, '_cvd_has_own_password', current_time( 'mysql', true ) );
		}
		wp_safe_redirect( add_query_arg( 'clave', $flag, remove_query_arg( 'clave', $back ) ) . '#perfil' );
		exit;
	}

	/** Formulario de "Mi perfil". */
	public static function form( WP_User $user ): string {
		$flag = sanitize_key( wp_unslash( $_GET['clave'] ?? '' ) );
		$notes = array( 'ok' => 'Listo. Ya puedes entrar con tu número de WhatsApp y esta contraseña.', 'short' => 'La contraseña necesita al menos 8 letras o números.', 'mismatch' => 'Las dos contraseñas no son iguales. Escríbelas otra vez.' );
		$has = (bool) get_user_meta( $user->ID, '_cvd_has_own_password', true );
		$wa = preg_replace( '/\D+/', '', (string) get_user_meta( $user->ID, '_cvd_whatsapp', true ) );
		ob_start(); ?>
		<section class="cvd-panel cvd-password-panel" id="cvd-clave">
			<h2><?php echo $has ? 'Cambiar tu contraseña' : 'Pon tu contraseña'; ?></h2>
			<p>Así entras cuando quieras con tu número de WhatsApp<?php echo $wa ? ' (' . esc_html( substr( $wa, -8 ) ) . ')' : ''; ?> y tu contraseña, sin pedir enlaces. Tu teléfono la recuerda un año.</p>
			<?php if ( isset( $notes[ $flag ] ) ) : ?><div class="cvd-notice<?php echo 'ok' === $flag ? '' : ' cvd-error'; ?>" role="status"><?php echo esc_html( $notes[ $flag ] ); ?></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvd-password-form">
				<input type="hidden" name="action" value="cvd_program_password"><?php wp_nonce_field( 'cvd_program_password' ); ?>
				<label>Contraseña nueva<input name="cvd_pass" type="password" minlength="8" autocomplete="new-password" required></label>
				<label>Repítela<input name="cvd_pass2" type="password" minlength="8" autocomplete="new-password" required></label>
				<button class="cvd-primary" type="submit">Guardar contraseña</button>
			</form>
			<p><small>¿La olvidaste? Escribe <strong>mi acceso</strong> al WhatsApp de Casa Viva, entra con el enlace y pon otra aquí.</small></p>
		</section>
		<?php return (string) ob_get_clean();
	}
}
