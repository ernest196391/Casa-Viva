<?php
/**
 * Plugin Name: Casa Viva Dropship Core
 * Description: Atribución permanente, comisiones, proveedores y cierre de pedidos por WhatsApp para WooCommerce.
 * Version: 3.6.0
 * Author: Casa Viva
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.2
 * Text Domain: casa-viva-dropship
 */

defined( 'ABSPATH' ) || exit;

define( 'CVD_VERSION', '3.6.0' );
define( 'CVD_FILE', __FILE__ );
define( 'CVD_DIR', plugin_dir_path( __FILE__ ) );
define( 'CVD_URL', plugin_dir_url( __FILE__ ) );

require_once CVD_DIR . 'includes/class-cvd-plugin.php';
require_once CVD_DIR . 'includes/class-cvd-customer-orders.php';
require_once CVD_DIR . 'includes/class-cvd-customer-order-support.php';
require_once CVD_DIR . 'includes/class-cvd-customer-navigation.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-financial-view.php';

register_activation_hook( __FILE__, array( 'CVD_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		CVD_Plugin::instance()->boot();
		if ( class_exists( 'WooCommerce' ) ) {
			CVD_Customer_Orders::register();
			CVD_Customer_Order_Support::register();
			CVD_Customer_Navigation::register();
			CVD_Gestora_Financial_View::register();
		}
	}
);
