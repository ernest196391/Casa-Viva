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
			<?php
			// "Me equivoqué": avisa a la tienda por WhatsApp con el número del pedido para corregirlo sin rehacerlo.
			$cvd_fix_phone = preg_replace( '/\D+/', '', (string) get_option( 'cvd_central_whatsapp', '' ) );
			if ( $cvd_fix_phone ) :
				$cvd_fix_text = 'Hola Casa Viva, me equivoqué en el pedido #' . $order->get_order_number() . ' y quiero corregir: ';
				?>
				<p class="cvd-thanks__fix" style="margin-top:14px;text-align:center">
					<a href="<?php echo esc_url( 'https://wa.me/' . $cvd_fix_phone . '?text=' . rawurlencode( $cvd_fix_text ) ); ?>" target="_blank" rel="noopener" style="text-decoration:underline">¿Te equivocaste en algo? Corrige tu pedido #<?php echo esc_html( $order->get_order_number() ); ?> sin rehacerlo</a>
				</p>
			<?php endif; ?>
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
