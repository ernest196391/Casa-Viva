<?php

defined( 'ABSPATH' ) || exit;

final class CVD_Portal {
	public static function register(): void {
		add_shortcode( 'casa_viva_portal', array( __CLASS__, 'render' ) );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>Debes iniciar sesión para ver tus comisiones.</p>';
		}

		global $wpdb;

		$user_id = get_current_user_id();
		$table   = $wpdb->prefix . 'cvd_commissions';
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, amount, currency, status, created_at
				FROM {$table}
				WHERE owner_user_id = %d
				ORDER BY created_at DESC
				LIMIT 100",
				$user_id
			)
		);

		$totals = array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'cancelled' => 0.0 );
		foreach ( $rows as $row ) {
			if ( isset( $totals[ $row->status ] ) ) {
				$totals[ $row->status ] += (float) $row->amount;
			}
		}

		ob_start();
		?>
		<section class="cvd-portal">
			<h2>Mis comisiones</h2>
			<div class="cvd-summary">
				<p><strong>Pendiente:</strong> <?php echo wp_kses_post( wc_price( $totals['pending'] ) ); ?></p>
				<p><strong>Aprobada:</strong> <?php echo wp_kses_post( wc_price( $totals['approved'] ) ); ?></p>
				<p><strong>Pagada:</strong> <?php echo wp_kses_post( wc_price( $totals['paid'] ) ); ?></p>
			</div>
			<table class="shop_table shop_table_responsive">
				<thead><tr><th>Pedido</th><th>Fecha</th><th>Estado</th><th>Comisión</th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4">Todavía no tienes comisiones registradas.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>#<?php echo esc_html( $row->order_id ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->created_at ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row->amount, array( 'currency' => $row->currency ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
