<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.69.11 (Lennys #581, aprobado por Ernesto en la bandeja D111, 26-oct): número propio de cada pedido que nunca salta.
 * El #número de WordPress NO cambia (lo usan el bot, los vales, «Entregado 5480» de los mensajeros y crm_dispatch); salta
 * porque la web usa un solo contador para pedidos, productos y fotos. Cada pedido real lleva además:
 *   _cvd_seq        Nº del año (1, 2, 3… desde el 1 de enero, hora de Cuba)
 *   _cvd_seq_dia    n.º del día
 *   _cvd_seq_fecha  AAAA-MM-DD (Cuba) del pedido
 * Se ve como «Nº 292 · 8.º de hoy» («… del 25-oct» si es de otro día): lista de pedidos (columna «Nº»), ficha del pedido,
 * panel y bot (la meta va en la API de WooCommerce). En el recibo del cliente no.
 * Las pruebas no cuentan; los cancelados conservan el suyo. Se da una sola vez, en el primer guardado con el pedido ya hecho
 * (no borrador, pendiente, fallido ni papelera), con un candado de MySQL para que dos pedidos a la vez no repitan número.
 * Los pedidos de antes se numeran una vez con backfill() (wp eval 'echo CVD_Order_Seq::backfill( true );'): hasta entonces
 * no se numera nada. Quitarlo: delete_option( 'cvd_order_seq' ) y borrar las tres metas.
 */
final class CVD_Order_Seq {
	public const META = '_cvd_seq';
	public const DAY_META = '_cvd_seq_dia';
	public const DATE_META = '_cvd_seq_fecha';
	private const OPTION = 'cvd_order_seq'; // { ready, from_id, year, n, date, day }
	private const LOCK = 'cvd_order_seq';
	private const SKIP = array( 'checkout-draft', 'pending', 'failed', 'trash', 'auto-draft' );
	private const MONTHS = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );
	private static bool $busy = false;

	public static function register(): void {
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'on_save' ), 40, 1 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'column' ) ); // por si se apaga HPOS
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_box' ), 5 );
	}

	// ------------------------------------------------------------------ Lo que se ve

	/** «Nº 292 · 8.º de hoy» ('' si el pedido no tiene número propio). */
	public static function label( WC_Order $order ): string {
		$n = absint( $order->get_meta( self::META, true ) );
		if ( ! $n ) { return ''; }
		$day = absint( $order->get_meta( self::DAY_META, true ) );
		$date = (string) $order->get_meta( self::DATE_META, true );
		$out = 'Nº ' . number_format_i18n( $n );
		if ( $day && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			$today = ( new DateTimeImmutable( 'now', self::tz() ) )->format( 'Y-m-d' );
			$out .= ' · ' . $day . '.º ' . ( $date === $today ? 'de hoy' : 'del ' . (int) $m[3] . '-' . self::MONTHS[ (int) $m[2] - 1 ] );
		}
		return $out;
	}

	public static function column( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_number' === $key ) { $out['cvd_seq'] = 'Nº'; }
		}
		if ( ! isset( $out['cvd_seq'] ) ) { $out['cvd_seq'] = 'Nº'; }
		return $out;
	}

	/** HPOS da el pedido; la lista vieja da el id. */
	public static function column_value( $column, $order ): void {
		if ( 'cvd_seq' !== $column ) { return; }
		$order = $order instanceof WC_Order ? $order : wc_get_order( absint( $order ) );
		if ( ! $order instanceof WC_Order ) { return; }
		$label = self::label( $order );
		echo '' !== $label ? esc_html( $label ) : '<span aria-hidden="true">—</span>';
	}

	public static function admin_box( $order ): void {
		if ( ! $order instanceof WC_Order ) { return; }
		$label = self::label( $order );
		echo '<p class="form-field form-field-wide"><strong>Número propio:</strong> ' . esc_html( '' !== $label ? $label : ( CVD_Tarjeta_Carrera::is_test( $order ) ? 'no lleva (pedido de prueba)' : 'todavía no tiene' ) ) . '</p>';
	}

	// ------------------------------------------------------------------ Dar el número

	public static function on_save( $order ): void {
		if ( self::$busy || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || 'shop_order' !== $order->get_type() ) { return; }
		if ( absint( $order->get_meta( self::META, true ) ) || in_array( $order->get_status(), self::SKIP, true ) ) { return; }
		self::$busy = true;
		try { self::assign( $order ); } catch ( Throwable $e ) { error_log( 'Nº de pedido: ' . $e->getMessage() ); } finally { self::$busy = false; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function assign( WC_Order $order ): void {
		if ( CVD_Tarjeta_Carrera::is_test( $order ) || ! $order->get_date_created() ) { return; }
		$state = self::state();
		if ( empty( $state['ready'] ) || $order->get_id() <= absint( $state['from_id'] ?? 0 ) ) { return; }
		if ( ! self::lock() ) { return; } // otro pedido se está numerando: el siguiente guardado lo hace
		try {
			if ( self::stored( $order->get_id() ) ) { return; }
			$state = self::state(); // de nuevo, ya dentro del candado
			list( $year, $date ) = self::when( $order );
			$n = ( (int) ( $state['year'] ?? 0 ) === $year ) ? absint( $state['n'] ?? 0 ) + 1 : 1;
			$day = ( (string) ( $state['date'] ?? '' ) === $date ) ? absint( $state['day'] ?? 0 ) + 1 : 1;
			self::write( $order, $n, $day, $date );
			self::save_state( array( 'year' => $year, 'n' => $n, 'date' => $date, 'day' => $day ) + $state );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Una sola vez: numera los pedidos que ya existen, por fecha (y por id si coinciden). $write = false solo cuenta.
	 * → resumen en una línea.
	 */
	public static function backfill( bool $write = false ): string {
		$state = self::state();
		if ( ! empty( $state['ready'] ) ) { return 'Ya estaba hecho: Nº ' . absint( $state['n'] ?? 0 ) . ' (' . ( $state['date'] ?? '' ) . ', ' . absint( $state['day'] ?? 0 ) . ' ese día).'; }
		if ( $write && ! self::lock() ) { return 'Otro proceso tiene el candado: prueba otra vez.'; }
		try {
			$statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), array_map( static fn( $s ) => 'wc-' . $s, self::SKIP ) ) );
			$max = absint( wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids' ) )[0] ?? 0 );
			$orders = array();
			foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'status' => $statuses, 'return' => 'objects' ) ) as $order ) {
				if ( $order instanceof WC_Order && $order->get_date_created() && $order->get_id() <= $max ) { $orders[] = $order; }
			}
			usort( $orders, static fn( WC_Order $a, WC_Order $b ): int => array( $a->get_date_created()->getTimestamp(), $a->get_id() ) <=> array( $b->get_date_created()->getTimestamp(), $b->get_id() ) );
			$year = 0; $n = 0; $date = ''; $day = 0; $done = 0; $tests = 0;
			foreach ( $orders as $order ) {
				if ( CVD_Tarjeta_Carrera::is_test( $order ) ) { $tests++; continue; }
				list( $y, $d ) = self::when( $order );
				$n = $y === $year ? $n + 1 : 1;
				$day = $d === $date ? $day + 1 : 1;
				$year = $y; $date = $d;
				if ( $write ) { self::write( $order, $n, $day, $date ); }
				$done++;
			}
			if ( $write ) { self::save_state( array( 'ready' => 1, 'from_id' => $max, 'year' => $year, 'n' => $n, 'date' => $date, 'day' => $day ) ); }
			return ( $write ? 'Numerados ' : 'Se numerarían ' ) . $done . ' pedidos (pruebas fuera: ' . $tests . '). Último: Nº ' . $n . ' · ' . $day . '.º del ' . $date . '. Desde el pedido ' . ( $max + 1 ) . ' se numera solo.';
		} finally {
			if ( $write ) { self::unlock(); }
		}
	}

	// ------------------------------------------------------------------ Ayudas

	private static function tz(): DateTimeZone { return new DateTimeZone( 'America/Havana' ); }

	/** @return array{0:int,1:string} año y día (Cuba) en que se creó el pedido. */
	private static function when( WC_Order $order ): array {
		$at = ( new DateTimeImmutable( '@' . $order->get_date_created()->getTimestamp() ) )->setTimezone( self::tz() );
		return array( (int) $at->format( 'Y' ), $at->format( 'Y-m-d' ) );
	}

	private static function write( WC_Order $order, int $n, int $day, string $date ): void {
		$order->update_meta_data( self::META, $n );
		$order->update_meta_data( self::DAY_META, $day );
		$order->update_meta_data( self::DATE_META, $date );
		$order->save_meta_data();
	}

	/** Leído de la base, sin caché (dentro del candado). */
	private static function state(): array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", self::OPTION ) );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	private static function save_state( array $state ): void {
		update_option( self::OPTION, wp_json_encode( $state ), false );
	}

	/** ¿Ya tiene número en la base? (no en la caché del objeto). */
	private static function stored( int $order_id ): bool {
		global $wpdb;
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$sql = $hpos
			? $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id=%d AND meta_key=%s LIMIT 1", $order_id, self::META )
			: $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1", $order_id, self::META );
		return absint( $wpdb->get_var( $sql ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::LOCK ) );
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
	}
}
