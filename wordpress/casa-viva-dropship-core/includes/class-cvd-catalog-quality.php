<?php

defined( 'ABSPATH' ) || exit;

/** Correcciones seguras de catálogo y página pública para gestoras. */
final class CVD_Catalog_Quality {
	public static function register(): void {
		add_shortcode( 'casa_viva_gestores', array( __CLASS__, 'gestores_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_gestores_url' ) );
	}

	public static function install(): void {
		if ( '1' !== get_option( 'cvd_catalog_copy_cleaned_061', '0' ) ) {
			self::clean_generated_copy();
			self::differentiate_same_name_products();
			update_option( 'cvd_catalog_copy_cleaned_061', '1' );
		}
	}

	private static function clean_generated_copy(): void {
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $product_ids as $product_id ) {
			$post = get_post( $product_id );
			if ( ! $post ) { continue; }
			$original = (string) $post->post_content;
			$content = preg_replace( '#<h2>.*?:\s*una solución práctica para tu hogar</h2>#isu', '', $original );
			$content = preg_replace( '#<p><strong>¿Buscas.*?</strong>.*?</p>#isu', '', (string) $content );
			$content = preg_replace( '#<h3>¿Cuándo puede ser una buena elección\?</h3>.*?<p><strong>Compra en Casa Viva:</strong>.*?</p>#isu', '', (string) $content );
			$content = preg_replace( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', (string) $content );
			$content = preg_replace( '#<p>(?:\s|<br\s*/?>)*</p>#i', '', (string) $content );
			$content = trim( (string) $content );
			if ( $content !== trim( $original ) ) {
				add_post_meta( $product_id, '_cvd_description_backup_061', $original, true );
				wp_update_post( array( 'ID' => $product_id, 'post_content' => wp_kses_post( $content ) ) );
			}

			$excerpt = trim( (string) $post->post_excerpt );
			if ( preg_match( '/^Compra .+ en La Habana con Casa Viva\./u', $excerpt ) ) {
				add_post_meta( $product_id, '_cvd_excerpt_backup_061', $excerpt, true );
				wp_update_post(
					array(
						'ID'           => $product_id,
						'post_excerpt' => 'Consulta disponibilidad, variantes y opciones de recogida o mensajería en La Habana.',
					)
				);
			}
		}
	}

	private static function differentiate_same_name_products(): void {
		global $wpdb;
		$names = $wpdb->get_results(
			"SELECT post_title, COUNT(ID) AS total FROM {$wpdb->posts} WHERE post_type='product' AND post_parent=0 AND post_status='publish' GROUP BY post_title HAVING COUNT(ID)>1"
		);
		foreach ( $names as $group ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_parent=0 AND post_status='publish' AND post_title=%s ORDER BY ID ASC", $group->post_title ) );
			foreach ( $ids as $index => $product_id ) {
				if ( get_post_meta( $product_id, '_cvd_original_duplicate_title', true ) ) { continue; }
				add_post_meta( $product_id, '_cvd_original_duplicate_title', $group->post_title, true );
				$new_title = $group->post_title . ' — Diseño ' . ( $index + 1 );
				wp_update_post( array( 'ID' => $product_id, 'post_title' => $new_title, 'post_name' => sanitize_title( $new_title ) ) );
			}
		}
	}

	public static function redirect_legacy_gestores_url(): void {
		$path = rawurldecode( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
		if ( false !== stripos( $path, 'bienvenid' ) && false !== stripos( $path, 'familia-casa-viva' ) ) {
			wp_safe_redirect( home_url( '/gestores/' ), 301 );
			exit;
		}
	}

	public static function gestores_page(): string {
		$register_url = home_url( '/registro-gestora/' );
		ob_start();
		?>
		<section class="cvd-gestores-public">
			<h1>Vende productos para el hogar con el respaldo de Casa Viva</h1>
			<p class="cvd-gestores-lead">Obtén tu tienda personal, comparte tus productos y consulta tus ventas y ganancias desde un solo lugar.</p>
			<div class="cvd-gestores-steps"><article><b>1</b><h2>Crea tu cuenta</h2><p>Envía tus datos y espera la aprobación de Casa Viva.</p></article><article><b>2</b><h2>Configura tu tienda</h2><p>Comparte tu enlace y personaliza tus precios dentro de los límites autorizados.</p></article><article><b>3</b><h2>Genera ganancias</h2><p>Las compras vinculadas aparecen en tu panel con su comisión y margen.</p></article></div>
			<a class="cvd-primary cvd-gestores-cta" href="<?php echo esc_url( $register_url ); ?>">Solicitar cuenta de gestora</a>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
