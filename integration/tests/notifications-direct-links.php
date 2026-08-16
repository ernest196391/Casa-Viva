<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fixture = get_option( 'cvt_integration_fixture' );
if ( ! is_array( $fixture ) || empty( $fixture['order_id'] ) ) {
	throw new RuntimeException( 'Falta fixture de integración para notificaciones.' );
}

$order = wc_get_order( absint( $fixture['order_id'] ) );
if ( ! $order ) { throw new RuntimeException( 'Pedido sintético no encontrado.' ); }

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$expected_staff_url = add_query_arg( 'order_id', $order->get_id(), home_url( '/centro-pedido/' ) );
$expected_messenger_url = add_query_arg( 'order_id', $order->get_id(), home_url( '/area-mensajeros/' ) );

global $wpdb;
$table = CVD_Web_Push::notifications_table();

$new_order = $wpdb->get_row( $wpdb->prepare(
	"SELECT title,message,action_url FROM {$table} WHERE user_id=%d AND order_id=%d AND type='new_order' ORDER BY id DESC LIMIT 1",
	absint( $fixture['admin_id'] ),
	$order->get_id()
), ARRAY_A );
$assert( is_array( $new_order ), 'No se registró la notificación de pedido nuevo.' );
$assert( $expected_staff_url === $new_order['action_url'], 'Pedido nuevo no enlaza al Centro Único.' );
$assert( false !== strpos( $new_order['title'], '#' . $order->get_order_number() ), 'Pedido nuevo no identifica el pedido.' );

$messenger = get_user_by( 'id', absint( $fixture['messenger_id'] ) );
CVD_Web_Push::send_offer( $order, array( $messenger ) );
$offer = $wpdb->get_row( $wpdb->prepare(
	"SELECT title,message,action_url FROM {$table} WHERE user_id=%d AND order_id=%d AND type='delivery_offer' ORDER BY id DESC LIMIT 1",
	absint( $fixture['messenger_id'] ),
	$order->get_id()
), ARRAY_A );
$assert( is_array( $offer ), 'No se registró la oferta al mensajero.' );
$assert( $expected_messenger_url === $offer['action_url'], 'La oferta no abre el pedido del mensajero.' );
$assert( false !== strpos( $offer['title'], '#' . $order->get_order_number() ), 'La oferta no identifica el pedido.' );
$assert( false !== stripos( $offer['message'], 'aceptar' ), 'La oferta no explica la acción esperada.' );

$order->update_meta_data( '_cvd_messenger_user_id', absint( $fixture['messenger_id'] ) );
$order->save();
wp_set_current_user( 0 );
CVD_Web_Push::send_delivery_update( $order, 'handed_over' );

$staff_update = $wpdb->get_row( $wpdb->prepare(
	"SELECT title,message,action_url FROM {$table} WHERE user_id=%d AND order_id=%d AND type='delivery_handed_over' ORDER BY id DESC LIMIT 1",
	absint( $fixture['admin_id'] ),
	$order->get_id()
), ARRAY_A );
$assert( is_array( $staff_update ), 'No se registró actualización para operaciones.' );
$assert( $expected_staff_url === $staff_update['action_url'], 'Actualización operativa no enlaza al Centro Único.' );
$assert( false !== stripos( $staff_update['message'], 'cliente' ), 'La actualización no describe el avance logístico.' );

$messenger_update = $wpdb->get_row( $wpdb->prepare(
	"SELECT action_url FROM {$table} WHERE user_id=%d AND order_id=%d AND type='delivery_handed_over' ORDER BY id DESC LIMIT 1",
	absint( $fixture['messenger_id'] ),
	$order->get_id()
), ARRAY_A );
$assert( is_array( $messenger_update ), 'No se registró actualización para mensajero.' );
$assert( $expected_messenger_url === $messenger_update['action_url'], 'Actualización del mensajero no abre su pedido.' );

echo "OK: notificaciones 2B descriptivas y con enlaces directos.\n";
