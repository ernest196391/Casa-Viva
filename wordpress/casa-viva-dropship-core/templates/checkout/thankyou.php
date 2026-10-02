<?php
/**
 * Pedido recibido de Casa Viva (sustituye checkout/thankyou.php de WooCommerce).
 *
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce-order cvd-thanks-page">
	<?php if ( $order ) : ?>
		<?php do_action( 'woocommerce_before_thankyou', $order->get_id() ); ?>
		<?php if ( $order->has_status( 'failed' ) ) : ?>
			<section class="cvd-thanks">
				<p class="cvd-thanks__lead">No se pudo completar el pedido #<?php echo esc_html( $order->get_order_number() ); ?>. Puedes intentarlo de nuevo.</p>
				<a class="cvd-thanks__whatsapp" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>">Intentar de nuevo</a>
			</section>
		<?php else : ?>
			<?php CVD_WhatsApp_Gateway::thankyou_button( $order->get_id() ); ?>
		<?php endif; ?>
		<?php
		// Los detalles ya están arriba; se mantienen los ganchos para medición y otros plugins.
		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
		do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
		do_action( 'woocommerce_thankyou', $order->get_id() );
		?>
	<?php else : ?>
		<section class="cvd-thanks"><p class="cvd-thanks__lead">Recibimos tu pedido. Casa Viva te escribirá para confirmarlo.</p></section>
	<?php endif; ?>
</div>
