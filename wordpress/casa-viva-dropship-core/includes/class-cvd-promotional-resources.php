<?php

defined( 'ABSPATH' ) || exit;

/** Biblioteca promocional central. Una publicación administrativa se replica a todas las gestoras. */
final class CVD_Promotional_Resources {
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_cvd_resource', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	public static function post_type(): void {
		register_post_type( 'cvd_resource', array(
			'labels' => array( 'name' => 'Material promocional', 'singular_name' => 'Recurso', 'add_new_item' => 'Añadir recurso', 'edit_item' => 'Editar recurso' ),
			'public' => false, 'show_ui' => true, 'show_in_menu' => 'woocommerce', 'menu_icon' => 'dashicons-images-alt2',
			'supports' => array( 'title', 'editor', 'thumbnail' ), 'capability_type' => 'post', 'map_meta_cap' => true,
		) );
	}

	public static function meta_box(): void {
		add_meta_box( 'cvd_resource_data', 'Producto y archivo descargable', array( __CLASS__, 'box' ), 'cvd_resource', 'normal', 'high' );
	}

	public static function box( WP_Post $post ): void {
		wp_nonce_field( 'cvd_resource_' . $post->ID, 'cvd_resource_nonce' );
		$product_id = absint( get_post_meta( $post->ID, '_cvd_product_id', true ) );
		$file_id = absint( get_post_meta( $post->ID, '_cvd_file_id', true ) );
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC' ) );
		echo '<p><label for="cvd_resource_product"><strong>Producto relacionado</strong></label><br><select id="cvd_resource_product" name="cvd_resource_product" style="width:100%"><option value="0">Material general</option>';
		foreach ( $products as $product ) { echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $product_id, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>'; }
		echo '</select></p><p><label><strong>Archivo para descargar</strong></label><br><input id="cvd_resource_file" name="cvd_resource_file" type="hidden" value="' . esc_attr( $file_id ) . '"><button class="button" id="cvd_resource_pick" type="button">Seleccionar foto, video o PDF</button> <span id="cvd_resource_filename">' . esc_html( $file_id ? basename( (string) get_attached_file( $file_id ) ) : 'Sin archivo seleccionado' ) . '</span></p><p>Usa la imagen destacada como vista previa y el contenido como copy aprobado para publicar.</p>';
	}

	public static function admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'cvd_resource' !== get_current_screen()->post_type ) { return; }
		wp_enqueue_media();
		$script = <<<'JS'
jQuery(function($){
	$('#cvd_resource_pick').on('click',function(){
		var frame=wp.media({title:'Seleccionar recurso',multiple:false});
		frame.on('select',function(){
			var attachment=frame.state().get('selection').first().toJSON();
			$('#cvd_resource_file').val(attachment.id);
			$('#cvd_resource_filename').text(attachment.filename);
		});
		frame.open();
	});
});
JS;
		wp_add_inline_script( 'jquery-core', $script );
	}

	public static function save( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['cvd_resource_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cvd_resource_nonce'] ) ), 'cvd_resource_' . $post_id ) ) { return; }
		update_post_meta( $post_id, '_cvd_product_id', absint( $_POST['cvd_resource_product'] ?? 0 ) );
		update_post_meta( $post_id, '_cvd_file_id', absint( $_POST['cvd_resource_file'] ?? 0 ) );
	}

	public static function render_gestora_library(): string {
		$resources = get_posts( array( 'post_type' => 'cvd_resource', 'post_status' => 'publish', 'numberposts' => 60, 'orderby' => 'date', 'order' => 'DESC' ) );
		ob_start(); ?>
		<section class="cvd-panel cvd-resources-panel" id="materiales"><div class="cvd-section-head"><div><p class="cvd-kicker">Material promocional</p><h2>Recursos para compartir</h2><p>Fotos y descripciones oficiales actualizadas por Casa Viva.</p></div></div>
		<?php if ( ! $resources ) : ?><p>Todavía no hay materiales publicados.</p><?php else : ?><div class="cvd-resource-grid">
		<?php foreach ( $resources as $resource ) :
			$product_id = absint( get_post_meta( $resource->ID, '_cvd_product_id', true ) ); $product = $product_id ? wc_get_product( $product_id ) : false;
			$file_id = absint( get_post_meta( $resource->ID, '_cvd_file_id', true ) ); $download = $file_id ? wp_get_attachment_url( $file_id ) : get_the_post_thumbnail_url( $resource->ID, 'full' ); ?>
			<article class="cvd-resource-card"><?php echo get_the_post_thumbnail( $resource->ID, 'medium', array( 'loading' => 'lazy' ) ); ?><div><small><?php echo esc_html( $product ? $product->get_name() : 'Casa Viva' ); ?></small><h3><?php echo esc_html( get_the_title( $resource ) ); ?></h3><div class="cvd-resource-copy"><?php echo wp_kses_post( wpautop( $resource->post_content ) ); ?></div><div class="cvd-inline-actions"><?php if ( $download ) : ?><a class="cvd-primary" href="<?php echo esc_url( $download ); ?>" download target="_blank" rel="noopener">Descargar</a><?php endif; ?><?php if ( $product ) : ?><a class="cvd-secondary" href="<?php echo esc_url( $product->get_permalink() ); ?>" target="_blank" rel="noopener">Ver producto</a><?php endif; ?></div></div></article>
		<?php endforeach; ?></div><?php endif; ?></section>
		<?php return (string) ob_get_clean();
	}
}
