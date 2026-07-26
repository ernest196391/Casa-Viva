<?php
/**
 * Plugin Name: Casa Viva Dropship Core
 * Description: Atribución permanente, comisiones, proveedores y cierre de pedidos por WhatsApp para WooCommerce.
 * Version: 0.1.0
 * Author: Casa Viva
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.2
 * Text Domain: casa-viva-dropship
 */

defined( 'ABSPATH' ) || exit;

define( 'CVD_VERSION', '0.1.0' );
define( 'CVD_FILE', __FILE__ );
define( 'CVD_DIR', plugin_dir_path( __FILE__ ) );

require_once CVD_DIR . 'includes/class-cvd-plugin.php';

register_activation_hook( __FILE__, array( 'CVD_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		CVD_Plugin::instance()->boot();
	}
);
