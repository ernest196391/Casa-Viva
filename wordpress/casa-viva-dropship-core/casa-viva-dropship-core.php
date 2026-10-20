<?php
/**
 * Plugin Name: Casa Viva Dropship Core
 * Description: Atribución permanente, comisiones, proveedores y cierre de pedidos por WhatsApp para WooCommerce.
 * Version: 3.14.43
 * Author: Casa Viva
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.2
 * Text Domain: casa-viva-dropship
 */

defined( 'ABSPATH' ) || exit;

define( 'CVD_VERSION', '3.14.43' );
define( 'CVD_FILE', __FILE__ );
define( 'CVD_DIR', plugin_dir_path( __FILE__ ) );
define( 'CVD_URL', plugin_dir_url( __FILE__ ) );

require_once CVD_DIR . 'includes/class-cvd-plugin.php';
require_once CVD_DIR . 'includes/class-cvd-order-lines.php'; // 3.14.7: precio de cada producto en vales y tarjetas. 3.14.37: su foto y su color (Lennys)
require_once CVD_DIR . 'includes/class-cvd-customer-orders.php';
require_once CVD_DIR . 'includes/class-cvd-customer-order-support.php';
require_once CVD_DIR . 'includes/class-cvd-customer-navigation.php';
require_once CVD_DIR . 'includes/class-cvd-catalog-presentation.php';
require_once CVD_DIR . 'includes/class-cvd-curru-ai.php';
require_once CVD_DIR . 'includes/class-cvd-launch-polish.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-financial-view.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-price-integrity.php';
require_once CVD_DIR . 'includes/class-cvd-attribution-overrides.php';
require_once CVD_DIR . 'includes/class-cvd-staff-privacy.php';
require_once CVD_DIR . 'includes/class-cvd-inventory-integrity.php';
require_once CVD_DIR . 'includes/class-cvd-structured-incidents.php';
require_once CVD_DIR . 'includes/class-cvd-messenger-simplification.php';
require_once CVD_DIR . 'includes/class-cvd-messenger-feed-guard.php';
require_once CVD_DIR . 'includes/class-cvd-product-search.php';
require_once CVD_DIR . 'includes/class-cvd-contextual-assistant.php';
require_once CVD_DIR . 'includes/class-cvd-premium-storefront.php';
require_once CVD_DIR . 'includes/class-cvd-bot-bridge.php';
require_once CVD_DIR . 'includes/class-cvd-panel-bridge.php';
require_once CVD_DIR . 'includes/class-cvd-clients.php';
require_once CVD_DIR . 'includes/class-cvd-site-privacy.php';
require_once CVD_DIR . 'includes/class-cvd-market-bridge.php';
require_once CVD_DIR . 'includes/class-cvd-departments.php';
require_once CVD_DIR . 'includes/class-cvd-home-rails.php';
require_once CVD_DIR . 'includes/class-cvd-mobile-shop.php';
require_once CVD_DIR . 'includes/class-cvd-data-saver.php';
require_once CVD_DIR . 'includes/class-cvd-private-products.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-app.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-busca.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-confianza.php';
require_once CVD_DIR . 'includes/class-cvd-nexo-busca.php'; // 3.14.28: NEXO Busca (búsquedas guardadas, vender por Casa Viva)
require_once CVD_DIR . 'includes/class-cvd-gestora-fotos.php';
require_once CVD_DIR . 'includes/class-cvd-estudio-core.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-estudio.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-contabilidad.php';
require_once CVD_DIR . 'includes/class-cvd-program-login.php';
require_once CVD_DIR . 'includes/class-cvd-daily-rates.php';
require_once CVD_DIR . 'includes/class-cvd-delivery-when.php';
require_once CVD_DIR . 'includes/class-cvd-gestora-stock.php';
require_once CVD_DIR . 'includes/class-cvd-direct-dispatch.php';
require_once CVD_DIR . 'includes/class-cvd-messenger-cash.php';
require_once CVD_DIR . 'includes/class-cvd-fichas.php';
require_once CVD_DIR . 'includes/class-cvd-aprende.php';
require_once CVD_DIR . 'includes/class-cvd-order-edit.php';
require_once CVD_DIR . 'includes/class-cvd-order-avisos.php';
require_once CVD_DIR . 'includes/class-cvd-costs.php';
require_once CVD_DIR . 'includes/class-cvd-ficha-estado.php'; // 3.14.40: ningún producto sin medidas ni características (Ernesto y Lennys, 18-oct)
require_once CVD_DIR . 'includes/class-cvd-supplier-split.php';
require_once CVD_DIR . 'includes/class-cvd-programa-calc.php';
require_once CVD_DIR . 'includes/class-cvd-programa.php';
require_once CVD_DIR . 'includes/class-cvd-encuesta-def.php'; // 3.14.35: encuesta anónima (preguntas y estadísticas, sin WordPress)
require_once CVD_DIR . 'includes/class-cvd-encuesta.php';
require_once CVD_DIR . 'includes/class-cvd-catalog-version.php'; // 3.14.41: el bot ve los cambios de productos al momento (Lennys, 20-oct)
require_once CVD_DIR . 'includes/class-cvd-caja-bridge.php'; // 3.14.42: la caja NEXO cobra pedidos web y cuadra el cajón (Ernesto 20-oct)

register_activation_hook( __FILE__, array( 'CVD_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		CVD_Plugin::instance()->boot();
		if ( class_exists( 'WooCommerce' ) ) {
			CVD_Customer_Orders::register();
			CVD_Customer_Order_Support::register();
			CVD_Customer_Navigation::register();
			CVD_Catalog_Presentation::register();
			CVD_Launch_Polish::register();
			CVD_Gestora_Financial_View::register();
			CVD_Gestora_Price_Integrity::register();
			CVD_Attribution_Overrides::register();
			CVD_Staff_Privacy::register();
			CVD_Inventory_Integrity::register();
			CVD_Structured_Incidents::register();
			CVD_Messenger_Simplification::register();
			CVD_Messenger_Feed_Guard::register();
			CVD_Product_Search::register();
			CVD_Contextual_Assistant::register();
			CVD_Premium_Storefront::register();
			CVD_Bot_Bridge::register();
			CVD_Panel_Bridge::register();
			CVD_Clients::register();
			CVD_Site_Privacy::register();
			CVD_Market_Bridge::register();
			CVD_Departments::register(); // 3.13.72: Tienda por departamentos (Lennys y Cely, 13-oct).
			CVD_Home_Rails::register(); // 3.13.83: portada que vende, filas de productos como Amazon o Temu (Ernesto, 13-oct).
			CVD_Mobile_Shop::register(); // 3.13.84–86: la tienda en el teléfono (auditoría de cliente, Ernesto 13-oct).
			CVD_Data_Saver::register(); // 3.13.91: menos datos en el teléfono (auditoría de cliente, punto 11).
			CVD_Private_Products::register(); // 3.13.95: los «Privado» no salen en la web, tampoco a administradores (Ernesto, 13-oct).
			CVD_Gestora_App::register();
			CVD_Gestora_Busca::register();
			CVD_Gestora_Confianza::register(); // 3.14.42: «¿Es de confianza este número?» (lista de sospechosos, Ernesto 20-oct).
			CVD_Nexo_Busca::register(); // 3.14.28: NEXO Busca — precio, proveedor, cliente, foto bonita y «Vender por Casa Viva» (Ernesto, 16-oct).
			CVD_Gestora_Fotos::register();
			CVD_Gestora_Estudio::register(); // 3.14.1: «Mi estudio de fotos» con la clave de Google de cada gestora (Ernesto, 14-oct).
			CVD_Program_Login::register();
			CVD_Gestora_Stock::register();
			CVD_Direct_Dispatch::register();
			CVD_Messenger_Cash::register();
			CVD_Fichas::register();
			CVD_Aprende::register(); // 3.13.77: «Aprende» (tutoriales) en el área de la gestora y del mensajero.
			CVD_Order_Edit::register(); // 3.13.97: cancelar con motivo y cambiar pedidos desde /ventas/ y el panel (Ernesto, 14-oct).
			CVD_Order_Avisos::register(); // 3.13.98: el bot avisa por WhatsApp al cancelar o cambiar un pedido (Ernesto, 14-oct).
			CVD_Costs::register(); // 3.14.0: costo de cada producto y precio mínimo (Ernesto, 14-oct).
			CVD_Ficha_Estado::register(); // 3.14.40: «Falta información» (medidas, material, datos técnicos) para gestoras y panel; /bot/fichas para el bot (Ernesto y Lennys, 18-oct).
			CVD_Supplier_Split::register(); // 3.14.14: reparto de la venta de proveedor apuntado en el pedido y sellado al cerrar (Ernesto, 14-oct, D69).
			CVD_Programa::register(); // 3.14.29: Programa de gestoras en modo sombra: piedras por ganancia, fondo 15 % (D53–D58). 3.14.30: red 1 % + 0,5 %, fondo sobre la ganancia neta y arrastre de devoluciones (D77). 3.14.32: alerta «cuenta_compartida», «revisar» agrupadas y el cierre espera a las alertas sin revisar (D79). 3.14.33: «Tus vales del mes» en Mi piedra (Dany).
			CVD_Catalog_Version::register(); // 3.14.41: marca de cambios del catálogo para VivaBot (/bot/catalogo/cambios), Lennys 20-oct.
			CVD_Caja_Bridge::register(); // 3.14.42: /panel/caja/* (pedidos web en la caja, efectivo de Ventas para el cierre).
			CVD_Encuesta::register(); // 3.14.35: encuesta anónima en /encuesta/ (nace cerrada; Ernesto 16-oct).
			CVD_Daily_Rates::register(); // 3.13.64: cada pedido nuevo guarda la tasa CUP del día de la venta (regla de Ernesto, 12-oct).
		}
	}
);
