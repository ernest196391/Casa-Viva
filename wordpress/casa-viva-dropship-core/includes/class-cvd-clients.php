<?php

defined( 'ABSPATH' ) || exit;

/**
 * Clientes de Casa Viva (3.13.34). Una fila por teléfono.
 * - Se llena sola con cada pedido de la web (nombre, teléfono, gestora dueña, compras).
 * - La gestora ve y añade sus clientes en su área para escribirles por WhatsApp (retargeting a mano).
 * - La dueña ve todos en el panel NEXO (CVD_Panel_Bridge → panel/clients).
 * Un cliente de una gestora no pasa a otra: el primero que lo trae se lo queda (como la atribución).
 * opt_out = pidió que no le escriban: no se muestra el botón de WhatsApp.
 */
final class CVD_Clients {
	private const DB_VERSION = '2';
	/** 3.13.98 (D53, fallo B): días que un cliente apuntado a mano queda protegido para su gestora. */
	public const PROTECT_DAYS = 30;

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'install' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'from_order' ), 50 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'from_order_id' ), 50 );
		add_action( 'admin_post_cvd_gestora_add_client', array( __CLASS__, 'gestora_add' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cvd_clients';
	}

	public static function install(): void {
		if ( get_option( 'cvd_clients_db' ) === self::DB_VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(20) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'web',
			orders int unsigned NOT NULL DEFAULT 0,
			spent decimal(12,2) NOT NULL DEFAULT 0,
			last_order_at datetime NULL,
			note varchar(255) NOT NULL DEFAULT '',
			opt_out tinyint(1) NOT NULL DEFAULT 0,
			claimed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone),
			KEY owner_user_id (owner_user_id)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'cvd_clients_db', self::DB_VERSION, false );
	}

	/** Teléfono cubano normalizado a 8 cifras (sin 53); otros países tal cual con su prefijo. */
	public static function phone( string $raw ): string {
		$d = preg_replace( '/\D+/', '', $raw );
		if ( 10 === strlen( $d ) && str_starts_with( $d, '53' ) ) { $d = substr( $d, 2 ); }
		return ( strlen( $d ) >= 8 && strlen( $d ) <= 15 ) ? $d : '';
	}

	public static function wa( string $phone ): string {
		return 'https://wa.me/' . ( 8 === strlen( $phone ) ? '53' . $phone : $phone );
	}

	/**
	 * Crea o completa un cliente. Nunca cambia de dueña a un cliente que ya tiene gestora.
	 * @return array{ok?:bool,error?:string,id?:int,owner?:int,taken?:bool}
	 */
	public static function upsert( string $phone_raw, string $name, int $owner, string $source, string $note = '' ): array {
		global $wpdb;
		self::install();
		$phone = self::phone( $phone_raw );
		if ( ! $phone ) { return array( 'error' => 'Escribe un teléfono válido (8 cifras).' ); }
		$now = current_time( 'mysql', true );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE phone=%s', $phone ) );
		// 3.13.98: cuándo la gestora lo apuntó a mano (empieza su protección de PROTECT_DAYS días).
		$claimed = $owner && 'gestora' === sanitize_key( $source ) ? $now : null;
		if ( ! $row ) {
			$wpdb->insert( self::table(), array( 'phone' => $phone, 'name' => sanitize_text_field( $name ), 'owner_user_id' => $owner, 'source' => sanitize_key( $source ), 'note' => sanitize_text_field( $note ), 'claimed_at' => $claimed, 'created_at' => $now, 'updated_at' => $now ) );
			return array( 'ok' => true, 'id' => (int) $wpdb->insert_id, 'owner' => $owner, 'created' => true );
		}
		$update = array( 'updated_at' => $now );
		if ( '' === $row->name && '' !== trim( $name ) ) { $update['name'] = sanitize_text_field( $name ); }
		if ( ! (int) $row->owner_user_id && $owner ) { $update['owner_user_id'] = $owner; if ( $claimed ) { $update['claimed_at'] = $claimed; } }
		// La nota solo la cambia la dueña del cliente (o el panel sin gestora): otra gestora no pisa la nota ajena.
		if ( '' !== trim( $note ) && ( ! $owner || ! (int) $row->owner_user_id || (int) $row->owner_user_id === $owner ) ) { $update['note'] = sanitize_text_field( $note ); }
		$wpdb->update( self::table(), $update, array( 'id' => $row->id ) );
		$final = (int) ( $update['owner_user_id'] ?? $row->owner_user_id );
		return array( 'ok' => true, 'id' => (int) $row->id, 'owner' => $final, 'taken' => $owner && $final && $final !== $owner );
	}

	/**
	 * 3.13.98 (D53, fallo B; Ernesto 14-oct): la gestora que apuntó a mano a este cliente en «Mis clientes» hace
	 * menos de PROTECT_DAYS días se queda la venta si compra por la web o el bot. Pasado el plazo, deja de estar
	 * protegido (lo que mande entonces es su primera compra entregada: CVD_Attribution::owns_customer).
	 * → mismo formato que CVD_Attribution (owner_user_id, owner_type, referral_code, source) o null.
	 */
	public static function protected_owner( string $phone_raw ): ?array {
		global $wpdb;
		$phone = self::phone( $phone_raw );
		if ( ! $phone ) { return null; }
		self::install();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_user_id, source, claimed_at, created_at FROM ' . self::table() . ' WHERE phone=%s', $phone ) );
		if ( ! $row || ! (int) $row->owner_user_id ) { return null; }
		$since = $row->claimed_at ?: ( 'gestora' === $row->source ? $row->created_at : null );
		if ( ! $since || strtotime( $since . ' UTC' ) < time() - self::PROTECT_DAYS * DAY_IN_SECONDS ) { return null; }
		$user = get_userdata( (int) $row->owner_user_id );
		if ( ! $user || ! class_exists( 'CVD_Registration' ) || ! CVD_Registration::is_approved_gestora( $user ) ) { return null; }
		$code = (string) get_user_meta( $user->ID, '_cvd_referral_code', true );
		return array( 'owner_user_id' => $user->ID, 'owner_type' => 'gestora', 'referral_code' => $code, 'source' => 'protected_client' );
	}

	public static function from_order_id( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) { self::from_order( $order ); }
	}

	/** Registra al cliente del pedido y recalcula sus compras (sin contar anulados). */
	public static function from_order( $order ): void {
		global $wpdb;
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return; }
		$phone = self::phone( (string) $order->get_billing_phone() );
		if ( ! $phone ) { return; }
		$owner = 'gestora' === $order->get_meta( '_cvd_owner_type', true ) ? absint( $order->get_meta( '_cvd_owner_user_id', true ) ) : 0;
		self::upsert( $phone, trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), $owner, 'web' );
		// Pedidos con ese teléfono (tabla de direcciones de WooCommerce; se confirma con phone() abajo).
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}wc_order_addresses WHERE address_type='billing' AND phone LIKE %s LIMIT 300", '%' . $wpdb->esc_like( substr( $phone, -8 ) ) ) );
		$count = 0; $spent = 0.0; $last = null;
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o || 'shop_order' !== $o->get_type() || ! in_array( $o->get_status(), array( 'pending', 'processing', 'on-hold', 'completed' ), true ) ) { continue; }
			if ( self::phone( (string) $o->get_billing_phone() ) !== $phone ) { continue; }
			$count++;
			if ( 'completed' === $o->get_status() ) { $spent += (float) $o->get_total(); }
			$at = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : null;
			if ( $at && ( ! $last || $at > $last ) ) { $last = $at; }
		}
		$wpdb->update( self::table(), array( 'orders' => $count, 'spent' => round( $spent, 2 ), 'last_order_at' => $last, 'updated_at' => current_time( 'mysql', true ) ), array( 'phone' => $phone ) );
	}

	public static function list( int $owner = 0, string $search = '', int $limit = 500 ): array {
		global $wpdb;
		self::install();
		$where = array( '1=1' ); $args = array();
		if ( $owner ) { $where[] = 'owner_user_id=%d'; $args[] = $owner; }
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(name LIKE %s OR phone LIKE %s)'; $args[] = $like; $args[] = $like; }
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY COALESCE(last_order_at, created_at) DESC LIMIT ' . absint( $limit );
		return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	/** La gestora añade un cliente suyo desde su área. */
	public static function gestora_add(): void {
		$user = wp_get_current_user();
		check_admin_referer( 'cvd_gestora_add_client' );
		$back = wp_get_referer() ?: home_url( '/' );
		// Solo gestoras aprobadas: una pendiente o suspendida no puede quedarse clientes.
		if ( ! $user->ID || ! CVD_Registration::is_approved_gestora( $user ) ) { wp_safe_redirect( $back ); exit; }
		$r = self::upsert( (string) wp_unslash( $_POST['phone'] ?? '' ), (string) wp_unslash( $_POST['name'] ?? '' ), $user->ID, 'gestora', (string) wp_unslash( $_POST['note'] ?? '' ) );
		$flag = isset( $r['error'] ) ? 'error' : ( ! empty( $r['taken'] ) ? 'taken' : 'ok' );
		wp_safe_redirect( add_query_arg( 'cliente', $flag, remove_query_arg( 'cliente', $back ) ) . '#mis-clientes' );
		exit;
	}

	/** Sección "Mis clientes" del área de la gestora. */
	public static function render_gestora( WP_User $user, string $store_link ): string {
		$rows = self::list( $user->ID );
		$flag = sanitize_key( wp_unslash( $_GET['cliente'] ?? '' ) );
		$notes = array( 'ok' => 'Cliente guardado.', 'taken' => 'Ese cliente ya lo trajo otra persona que vende con Casa Viva: no se puede pasar a tu lista.', 'error' => 'Escribe un teléfono válido (8 cifras).' );
		ob_start(); ?>
		<section class="cvd-panel" id="mis-clientes"><div><p class="cvd-kicker">Tus clientes</p><h2>Mis clientes (<?php echo esc_html( count( $rows ) ); ?>)</h2><p>Quien compra con tu enlace aparece aquí solo. También puedes añadirlos tú.</p></div>
			<?php if ( isset( $notes[ $flag ] ) ) : ?><div class="cvd-notice" role="status"><?php echo esc_html( $notes[ $flag ] ); ?></div><?php endif; ?>
			<details><summary class="cvd-secondary">Añadir un cliente</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvd-client-form">
					<input type="hidden" name="action" value="cvd_gestora_add_client"><?php wp_nonce_field( 'cvd_gestora_add_client' ); ?>
					<label>Nombre<input name="name" required></label>
					<label>Teléfono (WhatsApp)<input name="phone" required inputmode="tel" placeholder="5xxxxxxx"></label>
					<label>Nota (qué le gusta)<input name="note" maxlength="255" placeholder="Ej.: busca toallas grandes"></label>
					<button class="cvd-primary" type="submit">Guardar cliente</button>
				</form>
			</details>
			<?php if ( $rows ) : ?>
			<label class="cvd-product-search">Buscar<input type="search" data-client-search placeholder="Nombre o teléfono"></label>
			<ul class="cvd-client-list">
			<?php foreach ( $rows as $c ) :
				$hello = 'Hola' . ( $c->name ? ' ' . strtok( $c->name, ' ' ) : '' ) . ', soy ' . $user->display_name . ' de Casa Viva. Te comparto lo nuevo: ' . $store_link; ?>
				<li data-client="<?php echo esc_attr( strtolower( remove_accents( $c->name . ' ' . $c->phone ) ) ); ?>">
					<div><strong><?php echo esc_html( $c->name ?: 'Sin nombre' ); ?></strong>
					<small><?php echo esc_html( $c->phone ); ?> · <?php echo esc_html( $c->orders ? $c->orders . ' compra' . ( 1 === (int) $c->orders ? '' : 's' ) : 'sin compras aún' ); ?><?php echo $c->last_order_at ? ' · última ' . esc_html( date_i18n( 'j M', strtotime( $c->last_order_at ) ) ) : ''; ?></small>
					<?php // Solo su propia nota: las de importación o del panel son internas. ?>
					<?php if ( $c->note && 'gestora' === $c->source ) : ?><small><?php echo esc_html( $c->note ); ?></small><?php endif; ?></div>
					<?php if ( ! $c->opt_out ) : ?><a class="cvd-primary" target="_blank" rel="noopener" href="<?php echo esc_url( self::wa( $c->phone ) . '?text=' . rawurlencode( $hello ) ); ?>">WhatsApp</a><?php else : ?><small>No quiere mensajes</small><?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ul>
			<script>document.querySelector('[data-client-search]')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');document.querySelectorAll('[data-client]').forEach(li=>{li.hidden=!li.dataset.client.includes(q);});});</script>
			<?php else : ?><p class="cvd-empty-state">Aún no tienes clientes. Comparte tu enlace o añade el primero.</p><?php endif; ?>
		</section>
		<?php return (string) ob_get_clean();
	}
}
