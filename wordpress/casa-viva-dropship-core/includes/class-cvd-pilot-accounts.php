<?php

defined( 'ABSPATH' ) || exit;

/** Controlled creation of non-production pilot identities. */
final class CVD_Pilot_Accounts {
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'Usuarios piloto Casa Viva', 'Usuarios piloto', 'manage_woocommerce', 'cvd-pilot-users', array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Sin permiso.' ); }
		$credentials = array();
		if ( isset( $_POST['cvd_create_pilots'] ) && check_admin_referer( 'cvd_create_pilots' ) ) { $credentials = self::create_or_reset(); }
		echo '<div class="wrap"><h1>Usuarios piloto Casa Viva</h1><p>Crea o restablece identidades de prueba. Las contraseñas se muestran solo en esta pantalla; cópialas antes de salir.</p>';
		if ( $credentials ) {
			echo '<div class="notice notice-warning"><p><strong>No uses estas cuentas para operaciones reales.</strong></p></div><table class="widefat striped"><thead><tr><th>Rol</th><th>Usuario</th><th>Contraseña temporal</th><th>Acceso</th></tr></thead><tbody>';
			foreach ( $credentials as $row ) { echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td><code>' . esc_html( $row['login'] ) . '</code></td><td><code>' . esc_html( $row['password'] ) . '</code></td><td><a href="' . esc_url( $row['url'] ) . '" target="_blank">Abrir</a></td></tr>'; }
			echo '</tbody></table>';
		}
		echo '<form method="post" style="margin-top:24px">'; wp_nonce_field( 'cvd_create_pilots' ); echo '<button class="button button-primary" name="cvd_create_pilots" value="1">Crear o restablecer usuarios piloto</button></form></div>';
	}

	private static function create_or_reset(): array {
		$definitions = array(
			array( 'cv_cliente_piloto', 'Cliente piloto', 'customer', home_url( '/mi-cuenta/' ) ),
			array( 'cv_gestor_piloto', 'Gestor/a piloto', 'cvd_gestora', home_url( '/area-gestoras/' ) ),
			array( 'cv_mensajero_piloto', 'Mensajero piloto', 'cvd_messenger', home_url( '/area-mensajeros/' ) ),
			array( 'cv_dependienta_piloto', 'Dependienta piloto', 'cvd_clerk', home_url( '/centro-operaciones/' ) ),
			array( 'cv_operador_piloto', 'Operador administrativo piloto', 'cvd_operator', home_url( '/centro-operaciones/' ) ),
		);
		$rows = array();
		foreach ( $definitions as $definition ) {
			list( $login, $label, $role, $url ) = $definition;
			$password = wp_generate_password( 18, true, true );
			$user = get_user_by( 'login', $login );
			if ( ! $user ) {
				$user_id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.invalid', 'display_name' => $label, 'role' => $role ) );
				if ( is_wp_error( $user_id ) ) { continue; }
				$user = get_user_by( 'id', $user_id );
			} else { wp_set_password( $password, $user->ID ); $user->set_role( $role ); }
			if ( in_array( $role, array( 'cvd_gestora', 'cvd_messenger' ), true ) ) { update_user_meta( $user->ID, '_cvd_account_status', 'approved' ); }
			if ( 'cvd_gestora' === $role ) { update_user_meta( $user->ID, '_cvd_referral_code', 'CVPILOTO' . $user->ID ); }
			update_user_meta( $user->ID, '_cvd_is_pilot', 'yes' );
			$rows[] = array( 'label' => $label, 'login' => $login, 'password' => $password, 'url' => $url );
		}
		return $rows;
	}
}
