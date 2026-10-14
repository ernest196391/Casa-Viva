<?php

defined( 'ABSPATH' ) || exit;

/**
 * «Mi contabilidad» (3.14.1, Ernesto 14-oct): por cada producto de Casa Viva que la gestora vende (los que tienen
 * su precio en «Mis precios» y los que ya vendió), precio de Casa Viva, su precio, comisión y lo que sube por
 * unidad, y del mes elegido: unidades entregadas, lo ganado y lo pendiente.
 *
 * No calcula comisiones nuevas: suma la instantánea que Core guardó en cada venta (_cvd_commission_breakdown,
 * CVD_Commissions::calculate) con los mismos filtros que «Tus comisiones» (sin pruebas, sin anulados, sin
 * comisiones canceladas). Entregada = comisión aprobada o pagada; pendiente = comisión aún por verificar.
 * Solo productos de Casa Viva (las gestoras todavía no venden productos propios).
 */
final class CVD_Gestora_Contabilidad {
	public static function render( WP_User $user, array $orders ): string {
		$months = self::months();
		$month = isset( $_GET['cvc_mes'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['cvc_mes'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura.
		if ( ! isset( $months[ $month ] ) ) { $month = wp_date( 'Y-m' ); }
		$lines = self::lines( $orders );
		$data = CVD_Estudio_Ledger::aggregate( $lines, $month );
		$rows = self::rows( $user->ID, $lines, $data['products'] );
		$t = $data['totals'];
		ob_start();
		?>
		<section class="cvga-view cvcx" data-view="contabilidad" id="cvga-contabilidad">
			<div class="cvga-head"><h1>Mi contabilidad</h1><p>Lo que ganas con cada producto que vendes de Casa Viva, mes a mes.</p></div>
			<form class="cvga-card cvcx-bar" method="get" data-cvcx-form>
				<label class="cvga-field" for="cvcx-mes"><span>Mes</span>
					<select id="cvcx-mes" name="cvc_mes" onchange="this.form.submit()">
						<?php foreach ( $months as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"<?php selected( $value, $month ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<noscript><button class="cvga-btn cvga-btn-sm" type="submit">Ver</button></noscript>
			</form>
			<div class="cvcx-kpis">
				<article class="cvga-card cvga-kpi"><small>Ganado</small><strong><?php echo esc_html( self::usd( $t['earned'] ) ); ?></strong><span><?php echo esc_html( $t['delivered'] . ( 1 === $t['delivered'] ? ' unidad entregada' : ' unidades entregadas' ) ); ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Pendiente</small><strong><?php echo esc_html( self::usd( $t['pending'] ) ); ?></strong><span><?php echo esc_html( $t['pending_units'] . ( 1 === $t['pending_units'] ? ' unidad sin entregar' : ' unidades sin entregar' ) ); ?></span></article>
			</div>
			<?php if ( $t['earned'] > 0 ) : ?><p class="cvga-note"><?php echo esc_html( 'De lo ganado: ' . self::usd( $t['commission'] ) . ' de comisión de Casa Viva y ' . self::usd( $t['markup'] ) . ' de lo que subiste.' ); ?></p><?php endif; ?>
			<section class="cvga-card">
				<h2>Mis productos</h2>
				<p class="cvga-note">Los que tienen tu precio en «Mis precios» y los que ya vendiste. Precios de hoy; lo ganado, con lo que se guardó en cada venta.</p>
				<?php if ( ! $rows ) : ?>
				<p class="cvga-empty">Aún no tienes productos. Pon tu precio a alguno en <a href="#precios" data-cvga-go="precios">Mis precios</a> o comparte tu tienda.</p>
				<?php else : ?>
				<ul class="cvcx-list">
					<?php foreach ( $rows as $r ) : ?>
					<li class="cvcx-item">
						<div class="cvcx-name"><?php if ( $r['img'] ) : ?><img src="<?php echo esc_url( $r['img'] ); ?>" alt="" width="44" height="44" loading="lazy"><?php endif; ?><b><?php echo esc_html( $r['name'] ); ?></b></div>
						<dl class="cvcx-nums">
							<div><dt>Precio Casa Viva</dt><dd><?php echo esc_html( $r['base'] ); ?></dd></div>
							<div><dt>Tu precio</dt><dd><?php echo esc_html( $r['hers'] ); ?></dd></div>
							<div><dt>Comisión</dt><dd><?php echo esc_html( $r['commission'] ); ?></dd></div>
							<div><dt>Lo que subes</dt><dd><?php echo esc_html( $r['markup'] ); ?></dd></div>
							<div><dt>Entregadas</dt><dd><?php echo esc_html( (string) $r['delivered'] ); ?></dd></div>
							<div><dt>Ganado</dt><dd class="is-good"><?php echo esc_html( self::usd( $r['earned'] ) ); ?></dd></div>
							<div><dt>Pendiente</dt><dd><?php echo esc_html( self::usd( $r['pending'] ) . ( $r['pending_units'] ? ' (' . $r['pending_units'] . ')' : '' ) ); ?></dd></div>
						</dl>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</section>
			<p class="cvga-note">Comisión: lo que te paga Casa Viva por unidad. Lo que subes: lo que pusiste encima del precio de Casa Viva. Para cobrar, ve a <a href="#cobrar" data-cvga-go="cobrar">Cobrar</a>.</p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Últimos 12 meses, del más nuevo al más viejo: 'Y-m' => 'octubre 2026'. */
	private static function months(): array {
		$names = array( 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$out = array();
		$y = (int) wp_date( 'Y' );
		$m = (int) wp_date( 'n' );
		for ( $i = 0; $i < 12; $i++ ) {
			$out[ sprintf( '%04d-%02d', $y, $m ) ] = $names[ $m - 1 ] . ' ' . $y;
			if ( --$m < 1 ) { $m = 12; $y--; }
		}
		return $out;
	}

	/** Líneas de venta para CVD_Estudio_Ledger, con los mismos filtros que «Tus comisiones». */
	public static function lines( array $orders ): array {
		$lines = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ), true ) ) { continue; }
			if ( class_exists( 'CVD_Delivery' ) && CVD_Delivery::is_test_order( $order ) ) { continue; }
			if ( 'organic' === sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { continue; }
			$status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) ) ?: 'pending';
			if ( 'pending' === $status ) { $state = 'pending'; } elseif ( in_array( $status, array( 'approved', 'paid' ), true ) ) { $state = 'earned'; } else { continue; }
			$created = $order->get_date_created();
			$month = $created ? wp_date( 'Y-m', $created->getTimestamp() ) : '';
			$breakdown = $order->get_meta( '_cvd_commission_breakdown', true );
			if ( is_array( $breakdown ) && $breakdown ) {
				foreach ( $breakdown as $b ) {
					if ( ! is_array( $b ) ) { continue; }
					$lines[] = array( 'product_id' => (int) ( $b['product_id'] ?? 0 ), 'name' => '', 'month' => $month, 'state' => $state, 'qty' => (int) ( $b['quantity'] ?? 1 ), 'commission' => (float) ( $b['base_commission'] ?? 0 ), 'markup' => (float) ( $b['markup'] ?? 0 ) );
				}
				continue;
			}
			// Pedidos sin desglose (muy viejos): la comisión del pedido va al primer producto.
			$first = true;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$lines[] = array( 'product_id' => (int) $item->get_product_id(), 'name' => $item->get_name(), 'month' => $month, 'state' => $state, 'qty' => max( 1, (int) $item->get_quantity() ), 'commission' => $first ? (float) $order->get_meta( '_cvd_base_commission_amount', true ) : 0.0, 'markup' => $first ? (float) $order->get_meta( '_cvd_margin_amount', true ) : 0.0 );
				$first = false;
			}
		}
		return $lines;
	}

	/** Filas de la pantalla: sus productos (con precio suyo o vendidos alguna vez) con las cifras del mes. */
	private static function rows( int $user_id, array $lines, array $month_data ): array {
		$ids = array_map( 'intval', array_keys( class_exists( 'CVD_Gestora_Store' ) ? CVD_Gestora_Store::stored_prices( $user_id ) : array() ) );
		foreach ( $lines as $l ) { $ids[] = (int) $l['product_id']; }
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$user_rate = get_user_meta( $user_id, '_cvd_commission_rate', true );
		$rate = '' !== $user_rate ? (float) $user_rate : (float) get_option( 'cvd_default_commission_rate', 13 );
		$rows = array();
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) { continue; }
			$base = (float) get_post_meta( $pid, '_price', true );
			if ( $base <= 0 && $product->is_type( 'variable' ) ) { $base = (float) $product->get_variation_price( 'min', false ); }
			$hers = class_exists( 'CVD_Gestora_Store' ) ? (float) CVD_Gestora_Store::resolve_price( $product, $user_id ) : $base;
			$type = sanitize_key( (string) $product->get_meta( '_cvd_commission_type', true ) );
			$value = $product->get_meta( '_cvd_commission_value', true );
			$unit = ( '' === $type && '' === $value ) ? CVD_Estudio_Ledger::unit( $base, $hers, 'percent', $rate ) : CVD_Estudio_Ledger::unit( $base, $hers, 'fixed' === $type ? 'fixed' : 'percent', (float) $value );
			$m = $month_data[ $pid ] ?? array( 'delivered' => 0, 'pending_units' => 0, 'earned' => 0.0, 'pending' => 0.0 );
			$rows[] = array(
				'name' => $product->get_name(), 'img' => (string) get_the_post_thumbnail_url( $pid, 'thumbnail' ),
				'base' => self::usd( $base ), 'hers' => self::usd( $hers ), 'commission' => self::usd( $unit['commission'] ), 'markup' => self::usd( $unit['markup'] ),
				'delivered' => (int) $m['delivered'], 'pending_units' => (int) $m['pending_units'], 'earned' => (float) $m['earned'], 'pending' => (float) $m['pending'],
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $b['earned'], $b['pending'], $a['name'] ) <=> array( $a['earned'], $a['pending'], $b['name'] ) );
		return $rows;
	}

	private static function usd( float $v ): string {
		return number_format( $v, 2, ',', '.' ) . ' USD';
	}
}
