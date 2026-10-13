<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reparto directo (3.13.48): la gestora puede elegir qué mensajero lleva su pedido.
 *
 * La elección queda en `_cvd_preferred_messenger_user_id`. Se pone de tres formas:
 * - la gestora, en su área (Ventas), con el selector «Mensajero que lo lleva» de cada pedido suyo;
 * - la gestora (o el equipo) al hacer el pedido, con «¿Tienes algún mensajero preferido?» del checkout (3.13.62);
 * - la nota del cliente al comprar: «mensajero: Nombre» (si el nombre es de un solo mensajero aprobado).
 * Al pulsar «Listo para salir», CVD_Delivery::dispatch_ready() asigna directo a ese mensajero
 * (no sale al grupo) y VivaBot le manda el vale por privado. Si ya no está aprobado, sale al grupo
 * como siempre. Sin elección, todo funciona exactamente igual que antes.
 */
final class CVD_Direct_Dispatch {
	public const PREFERRED_META = '_cvd_preferred_messenger_user_id';
	public const SOURCE_META    = '_cvd_preferred_messenger_source';
	public const DIRECT_META    = '_cvd_direct_assignment';
	/** Campo «¿Tienes algún mensajero preferido?» del checkout clásico (3.13.62). */
	public const CHECKOUT_FIELD = 'cvd_preferred_messenger';

	public static function register(): void {
		add_action( 'admin_post_cvd_gestora_preferred_messenger', array( __CLASS__, 'save_from_gestora' ) );
		// 55: antes de la nota del cliente (60, así gana el selector) y antes de programar el reparto (CVD_Delivery, 80).
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'from_checkout' ), 55 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'from_customer_note' ), 60 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'from_customer_note' ), 60 );
	}

	/** Mensajeros aprobados (en una cuenta doble vale su estado de mensajero), por nombre. */
	public static function approved_messengers(): array {
		return array_values( array_filter( get_users( array( 'role' => 'cvd_messenger', 'orderby' => 'display_name' ) ), array( 'CVD_Registration', 'is_approved_messenger' ) ) );
	}

	/** Mensajero elegido para el pedido, solo si sigue siendo mensajero aprobado. */
	public static function preferred_messenger( WC_Order $order ): ?WP_User {
		$id = absint( $order->get_meta( self::PREFERRED_META, true ) );
		$user = $id ? get_userdata( $id ) : false;
		return $user instanceof WP_User && self::is_valid_messenger( $user ) ? $user : null;
	}

	public static function is_valid_messenger( WP_User $user ): bool {
		return in_array( 'cvd_messenger', (array) $user->roles, true ) && CVD_Registration::is_approved_messenger( $user );
	}

	public static function is_direct( WC_Order $order ): bool {
		return '1' === (string) $order->get_meta( self::DIRECT_META, true );
	}

	/** Quién pidió la asignación directa, en palabras ("la gestora Ana", "la nota del cliente"). */
	public static function requested_by( WC_Order $order ): string {
		$source = $order->get_meta( self::SOURCE_META, true );
		if ( 'note' === $source ) { return 'la nota del cliente'; }
		$gestora = self::gestora_name( $order );
		// Elegido en el checkout: «la gestora Ana al hacer el pedido» (o «la tienda…» si lo hizo alguien del equipo).
		if ( 'checkout' === $source ) { return ( $gestora ? 'el/la gestor/a ' . $gestora : 'la tienda' ) . ' al hacer el pedido'; }
		return $gestora ? 'el/la gestor/a ' . $gestora : 'quien vendió';
	}

	/** Nombre de la gestora dueña del pedido ('' si no es de gestora). */
	public static function gestora_name( WC_Order $order ): string {
		if ( 'gestora' !== sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) ) ) { return ''; }
		$name = trim( (string) $order->get_meta( '_cvd_owner_display_name', true ) );
		if ( '' === $name ) { $owner = get_userdata( absint( $order->get_meta( '_cvd_owner_user_id', true ) ) ); $name = $owner ? $owner->display_name : ''; }
		return $name;
	}

	/** ¿Todavía se puede elegir mensajero? Pedido a domicilio abierto que no salió al grupo ni tiene mensajero. */
	public static function can_choose( WC_Order $order ): bool {
		return 'pickup' !== $order->get_meta( '_cvd_fulfillment_type', true )
			&& ! in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ), true )
			&& 'unassigned' === CVD_Delivery::status( $order )
			&& ! absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
	}

	/** Celda «Mensajero que lo lleva» de la tabla de ventas de la gestora. */
	public static function gestora_cell( WC_Order $order ): string {
		static $messengers = null;
		$assigned = get_userdata( absint( $order->get_meta( '_cvd_messenger_user_id', true ) ) );
		if ( $assigned ) { return esc_html( $assigned->display_name ) . ( self::is_direct( $order ) ? ' <small>(lo elegiste tú)</small>' : '' ); }
		if ( 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return '<small>Recoge en tienda</small>'; }
		if ( ! self::can_choose( $order ) ) { return '<small>' . esc_html( 'offered' === CVD_Delivery::status( $order ) ? 'Salió al grupo' : '—' ) . '</small>'; }
		if ( null === $messengers ) { $messengers = self::approved_messengers(); }
		$current = absint( $order->get_meta( self::PREFERRED_META, true ) );
		$html = '<form class="cvd-preferred-messenger" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cvd_gestora_preferred_messenger"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">'
			. wp_nonce_field( 'cvd_preferred_messenger_' . $order->get_id(), '_wpnonce', true, false )
			. '<label>Mensajero que lo lleva<select name="messenger_id"><option value="0">Cualquiera (grupo)</option>';
		foreach ( $messengers as $messenger ) {
			$html .= '<option value="' . esc_attr( $messenger->ID ) . '"' . selected( $current, $messenger->ID, false ) . '>' . esc_html( $messenger->display_name ) . '</option>';
		}
		return $html . '</select></label><button class="cvd-secondary" type="submit">Guardar</button></form>';
	}

	/** Aviso tras guardar el mensajero elegido (se muestra arriba de «Tus ventas»). */
	public static function gestora_notice(): string {
		if ( ! isset( $_GET['mensajero'] ) ) { return ''; }
		$result = sanitize_key( wp_unslash( $_GET['mensajero'] ) );
		$messages = array(
			'guardado' => 'Listo: cuando la tienda pulse «Listo para salir», ese pedido se le asigna directo a tu mensajero (no sale al grupo).',
			'grupo'    => 'Listo: ese pedido saldrá al grupo y lo lleva el primer mensajero que diga «Yo».',
		);
		return '<div class="cvd-notice" role="status">' . esc_html( $messages[ $result ] ?? 'No se pudo guardar. Puede que el pedido ya haya salido o que ese mensajero ya no esté aprobado.' ) . '</div>';
	}

	/** Guarda el mensajero elegido por la gestora, solo en pedidos suyos que todavía no salieron. */
	public static function save_from_gestora(): void {
		$user = wp_get_current_user();
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $user->exists() || ! CVD_Registration::is_approved_gestora( $user ) ) { wp_die( 'No tienes permiso para cambiar este pedido.', 403 ); }
		check_admin_referer( 'cvd_preferred_messenger_' . $order_id );
		$order = wc_get_order( $order_id );
		$result = 'error';
		if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund
			&& $user->ID === absint( $order->get_meta( '_cvd_owner_user_id', true ) )
			&& self::can_choose( $order ) ) {
			$messenger_id = absint( $_POST['messenger_id'] ?? 0 );
			$previous = absint( $order->get_meta( self::PREFERRED_META, true ) );
			if ( ! $messenger_id ) {
				if ( $previous ) {
					$order->delete_meta_data( self::PREFERRED_META );
					$order->delete_meta_data( self::SOURCE_META );
					$order->add_order_note( $user->display_name . ' (gestor/a) quitó el mensajero elegido: el pedido saldrá al grupo de mensajeros.' );
					$order->save();
				}
				$result = 'grupo';
			} else {
				$messenger = get_userdata( $messenger_id );
				if ( $messenger instanceof WP_User && self::is_valid_messenger( $messenger ) ) {
					if ( $previous !== $messenger_id ) {
						$order->update_meta_data( self::PREFERRED_META, $messenger_id );
						$order->update_meta_data( self::SOURCE_META, 'gestora' );
						$order->add_order_note( $user->display_name . ' (gestor/a) eligió al mensajero ' . $messenger->display_name . '. Al pulsar «Listo para salir» se le asigna directo (no sale al grupo).' );
						$order->save();
					}
					$result = 'guardado';
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'mensajero', $result, home_url( '/area-gestoras/' ) ) . '#ventas' );
		exit;
	}

	/**
	 * Mensajero elegido al hacer el pedido (3.13.62, 12-oct, petición de Ernesto).
	 * En el checkout, la gestora (o alguien del equipo) ve «¿Tienes algún mensajero preferido?» con los mensajeros
	 * aprobados. Si elige uno, el pedido se le asigna directo y VivaBot le manda el vale por privado; con
	 * «No, que salga al grupo» todo sigue igual. Un cliente nunca ve el selector ni los nombres de los mensajeros.
	 *
	 * ¿Esta cuenta ve el selector? Equipo (mismos roles que keep_team_profile) y, si es de un programa, aprobada.
	 */
	public static function can_choose_at_checkout( ?WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		if ( ! $user->exists() || ! class_exists( 'CVD_Cuban_Checkout' ) ) { return false; }
		$roles = (array) $user->roles;
		if ( ! array_intersect( $roles, CVD_Cuban_Checkout::TEAM_ROLES ) ) { return false; }
		if ( array_intersect( $roles, array( 'administrator', 'shop_manager' ) ) ) { return true; }
		// Lo eligen quienes venden (gestora o influencer), no los mensajeros. Una solicitud de gestora ya tiene el rol,
		// pero sigue pendiente: esa todavía no ve a los mensajeros.
		return ( in_array( 'cvd_gestora', $roles, true ) && CVD_Registration::is_approved_gestora( $user ) )
			|| ( in_array( 'cvd_influencer', $roles, true ) && 'approved' === get_user_meta( $user->ID, '_cvd_account_status', true ) );
	}

	/** Opciones del selector: «No, que salga al grupo» y luego los mensajeros aprobados por nombre. */
	public static function checkout_options(): array {
		$options = array( '0' => 'No, que salga al grupo' );
		foreach ( self::approved_messengers() as $messenger ) {
			$options[ (string) $messenger->ID ] = $messenger->display_name;
		}
		return $options;
	}

	/** Al crear el pedido: guarda el mensajero elegido en el checkout (gana a la nota del cliente). Recogida: se ignora. */
	public static function from_checkout( $order ): void {
		try {
			if ( empty( $_POST[ self::CHECKOUT_FIELD ] ) ) { return; } // «No, que salga al grupo» o no lo vio
			$messenger_id = absint( wp_unslash( $_POST[ self::CHECKOUT_FIELD ] ) );
			if ( ! $messenger_id ) { return; }
			// El mismo nonce con el que WooCommerce acepta el checkout clásico, y solo si quien compra puede ver el selector.
			$nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ?? ( $_POST['_wpnonce'] ?? '' ) ) );
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce-process_checkout' ) ) { return; }
			$user = wp_get_current_user();
			if ( ! self::can_choose_at_checkout( $user ) ) { return; }
			if ( ! $order instanceof WC_Order ) { $order = wc_get_order( $order ); }
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! self::can_choose( $order ) ) { return; } // can_choose(): recogida no
			$messenger = get_userdata( $messenger_id );
			if ( ! $messenger instanceof WP_User || ! self::is_valid_messenger( $messenger ) ) { return; }
			$order->update_meta_data( self::PREFERRED_META, $messenger->ID );
			$order->update_meta_data( self::SOURCE_META, 'checkout' );
			$order->add_order_note( 'Mensajero elegido al hacer el pedido: ' . $messenger->display_name . ' (lo eligió ' . $user->display_name . '). Se le asigna directo: no sale al grupo de mensajeros.' );
			$order->save();
		} catch ( Throwable $error ) {
			// Nunca se frena una compra por esto: sin elección, el pedido sale al grupo como siempre.
			unset( $error );
		}
	}

	/** Al crear el pedido: «mensajero: Nombre» en la nota del cliente deja elegido a ese mensajero. */
	public static function from_customer_note( $order ): void {
		try {
			if ( ! $order instanceof WC_Order ) { $order = wc_get_order( $order ); }
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { return; }
			if ( absint( $order->get_meta( self::PREFERRED_META, true ) ) || 'pickup' === $order->get_meta( '_cvd_fulfillment_type', true ) ) { return; }
			$note = (string) $order->get_customer_note();
			if ( '' === trim( $note ) ) { return; }
			$messenger = self::messenger_in_note( $note );
			if ( ! $messenger ) { return; }
			$order->update_meta_data( self::PREFERRED_META, $messenger->ID );
			$order->update_meta_data( self::SOURCE_META, 'note' );
			$order->add_order_note( 'La nota del cliente pide al mensajero ' . $messenger->display_name . '. Al pulsar «Listo para salir» se le asigna directo (no sale al grupo).' );
			$order->save();
		} catch ( Throwable $error ) {
			// Nunca se frena una compra por esto: sin elección, el pedido sale al grupo como siempre.
			unset( $error );
		}
	}

	/**
	 * Busca «mensajero: Nombre» o «mensajero Nombre» (sin importar mayúsculas ni acentos).
	 * Vale si el nombre completo es de un solo mensajero aprobado, o si el primer nombre es único.
	 */
	public static function messenger_in_note( string $note ): ?WP_User {
		$text = self::normalize( preg_replace( '/[.,;!?()\r\n]+/u', '|', $note ) );
		if ( ! preg_match_all( '/\bmensajer[oa]\b\s*:?\s*([a-z0-9 ]+)/', $text, $found ) ) { return null; }
		$messengers = array();
		foreach ( self::approved_messengers() as $user ) {
			$name = self::normalize( $user->display_name );
			if ( '' !== $name ) { $messengers[] = array( 'user' => $user, 'name' => $name, 'first' => explode( ' ', $name )[0] ); }
		}
		foreach ( $found[1] as $candidate ) {
			$candidate = trim( preg_replace( '/\s+/', ' ', $candidate ) );
			if ( '' === $candidate ) { continue; }
			// Nombre completo: el más largo que encaje, si no hay empate.
			$full = array_values( array_filter( $messengers, static fn( array $m ): bool => $candidate === $m['name'] || str_starts_with( $candidate . ' ', $m['name'] . ' ' ) ) );
			usort( $full, static fn( array $a, array $b ): int => strlen( $b['name'] ) <=> strlen( $a['name'] ) );
			if ( $full && ( 1 === count( $full ) || strlen( $full[0]['name'] ) > strlen( $full[1]['name'] ) ) ) { return $full[0]['user']; }
			if ( count( $full ) > 1 ) { continue; } // varios con el mismo nombre: no se adivina
			$first = explode( ' ', $candidate )[0];
			$by_first = array_values( array_filter( $messengers, static fn( array $m ): bool => $first === $m['first'] ) );
			if ( 1 === count( $by_first ) ) { return $by_first[0]['user']; }
		}
		return null;
	}

	private static function normalize( string $text ): string {
		$text = remove_accents( function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text ) );
		$text = preg_replace( '/[^a-z0-9:| ]+/', ' ', $text );
		return trim( preg_replace( '/ +/', ' ', $text ) );
	}
}
