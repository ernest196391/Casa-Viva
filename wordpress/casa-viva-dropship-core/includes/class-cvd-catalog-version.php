<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.41 (Lennys 20-oct: «cambié las sillas y el bot le dijo a una gestora que no había»): marca de cambios del catálogo.
 *
 * Cada vez que se guarda un producto o un diseño, cambian sus unidades o pasa a la papelera, Core anota su id (el del
 * producto, no el del diseño) y sube la versión. VivaBot pregunta GET /bot/catalogo/cambios?desde=<versión> (muy barato,
 * sin leer productos) antes de contestar sobre productos y, si hay cambios, vuelve a leer SOLO esos productos.
 * Si pide desde una versión más vieja que lo anotado (o hubo más de MAX cambios), contesta `todo: true` = recargar entero.
 */
final class CVD_Catalog_Version {
	private const NS = 'casa-viva/v1';
	public const VERSION = 'cvd_catalog_version';
	public const CHANGES = 'cvd_catalog_changes';
	public const MAX = 300;
	/** @var array<int, true> ids de productos tocados en esta petición (se anotan una sola vez al final). */
	private static array $pending = array();

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'trashed_post', 'untrashed_post', 'before_delete_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'touched' ), 99, 1 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/** @param int|WC_Product $item */
	public static function touched( $item ): void {
		$id = $item instanceof WC_Product ? $item->get_id() : absint( $item );
		if ( ! $id ) { return; }
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			$id = (int) wp_get_post_parent_id( $id );
		} elseif ( 'product' !== $type ) {
			return;
		}
		if ( $id ) { self::$pending[ $id ] = true; }
	}

	/** Versión = milisegundos (siempre sube). Cambios = [[id, versión], …], los MAX más nuevos. */
	public static function flush(): void {
		if ( ! self::$pending ) { return; }
		$version = max( (int) get_option( self::VERSION, 0 ) + 1, (int) floor( microtime( true ) * 1000 ) );
		$changes = self::merge( (array) get_option( self::CHANGES, array() ), array_keys( self::$pending ), $version );
		self::$pending = array();
		update_option( self::CHANGES, $changes, false );
		update_option( self::VERSION, $version, false );
	}

	/** Añade los ids con la versión nueva (un id aparece una vez, con su último cambio) y deja los MAX más nuevos. */
	public static function merge( array $changes, array $ids, int $version ): array {
		$by = array();
		foreach ( $changes as $row ) {
			if ( is_array( $row ) && 2 === count( $row ) ) { $by[ (int) $row[0] ] = (int) $row[1]; }
		}
		foreach ( $ids as $id ) { $by[ (int) $id ] = $version; }
		arsort( $by );
		$out = array();
		foreach ( array_slice( $by, 0, self::MAX, true ) as $id => $v ) { $out[] = array( (int) $id, (int) $v ); }
		return $out;
	}

	/** Qué productos cambiaron después de `desde`. todo = el bot debe recargar el catálogo entero. */
	public static function since( array $changes, int $version, int $desde ): array {
		if ( $desde >= $version ) { return array( 'version' => $version, 'ids' => array(), 'todo' => false ); }
		$oldest = $changes ? min( array_map( static fn( $r ) => (int) $r[1], $changes ) ) : $version;
		$ids = array();
		foreach ( $changes as $row ) {
			if ( (int) $row[1] > $desde ) { $ids[] = (int) $row[0]; }
		}
		// Con la lista llena, lo más viejo pudo perderse: si `desde` es anterior, no se puede saber qué cambió.
		$todo = $desde <= 0 || ( count( $changes ) >= self::MAX && $desde < $oldest );
		return array( 'version' => $version, 'ids' => $todo ? array() : $ids, 'todo' => $todo );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/bot/catalogo/cambios', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_changes' ), 'permission_callback' => array( 'CVD_Bot_Bridge', 'authorized' ) ) );
	}

	public static function rest_changes( WP_REST_Request $request ): WP_REST_Response {
		$out = self::since( (array) get_option( self::CHANGES, array() ), (int) get_option( self::VERSION, 0 ), (int) $request->get_param( 'desde' ) );
		$response = new WP_REST_Response( array( 'ok' => true ) + $out );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
