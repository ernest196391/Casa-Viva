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
		add_submenu_page(
			'woocommerce',
			'Casa Viva Dropship',
			'Casa Viva Dropship',
			'manage_woocommerce',
			'casa-viva-dropship',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function settings(): void {
		register_setting( 'cvd_settings', 'cvd_default_commission_rate', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'cvd_settings', 'cvd_cookie_days', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'cvd_settings', 'cvd_central_whatsapp', array( 'sanitize_callback' => array( __CLASS__, 'phone' ) ) );
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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
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
}
