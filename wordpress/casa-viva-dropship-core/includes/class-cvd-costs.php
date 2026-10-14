<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.0 (Ernesto, 14-oct): costo de cada producto y precio mínimo.
 *
 * Meta en producto y variación (la variación sin costo propio hereda el del padre):
 *   _cvd_cost_usd         costo de compra por unidad en USD (compra + flete). NO cambiar el nombre: lo usa el programa de gestoras.
 *   _cvd_cost_source      bizne | proveedor | manual | formula
 *   _cvd_cost_updated_at  fecha ISO (UTC) del último cambio del costo
 * Calculado al guardar (no se edita a mano):
 *   _cvd_floor_usd        precio mínimo
 *   _cvd_floor_status     ok | bajo | sin_costo
 *   _cvd_under_floor      1 si el precio está por debajo del mínimo, 0 si no
 *
 * Precio mínimo (docs/00-FUENTE-DE-VERDAD/DECISIONES.md D59):
 *   tienda       = costo + gastos fijos (1,58 USD/u.) + comisión + máx(10 % del precio, 1 USD)
 *   bajo pedido  = costo del proveedor + comisión + máx(2,5 % del precio, 1 USD)   (SKU MK-, sin almacén)
 * La misma cuenta está en scripts/precios/costos-y-minimo.mjs (ernesto-rondon-nexo).
 *
 * Nunca se enseña a gestoras ni clientes: los campos solo salen en wp-admin a quien gestiona la tienda,
 * la API REST de WooCommerce los quita para quien no lo es y la tienda (Store API) no lee meta.
 * Lectura/escritura desde fuera: /bot/costs* (clave del bot) y /panel/products/cost (clave del panel).
 */
final class CVD_Costs {
	public const COST = '_cvd_cost_usd';
	public const SOURCE = '_cvd_cost_source';
	public const UPDATED = '_cvd_cost_updated_at';
	public const FLOOR = '_cvd_floor_usd';
	public const STATUS = '_cvd_floor_status';
	public const UNDER = '_cvd_under_floor';
	public const SOURCES = array( 'bizne' => 'BizneCubano / caja', 'proveedor' => 'Proveedor (bajo pedido)', 'manual' => 'Manual', 'formula' => 'Fórmula' );
	private const NS = 'casa-viva/v1';
	private const PRIVATE_KEYS = array( self::COST, self::SOURCE, self::UPDATED, self::FLOOR, self::STATUS, self::UNDER );

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ), 30 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ), 30 );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 30, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation_fields' ), 30, 2 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'after_save' ), 30 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( __CLASS__, 'hide_from_rest' ), 30 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( __CLASS__, 'hide_from_rest' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	// ---------- reglas ----------

	/** Reglas del mínimo según el tipo de producto. Opciones por si Ernesto cambia un número (sin tocar código). */
	public static function rules( bool $bajo_pedido ): array {
		if ( $bajo_pedido ) {
			return array( 'fixed' => 0.0, 'pct' => (float) get_option( 'cvd_cost_pedido_min_profit_pct', 2.5 ), 'min' => (float) get_option( 'cvd_cost_pedido_min_profit_usd', 1 ) );
		}
		return array( 'fixed' => (float) get_option( 'cvd_cost_fixed_unit_usd', 1.58 ), 'pct' => (float) get_option( 'cvd_cost_min_profit_pct', 10 ), 'min' => (float) get_option( 'cvd_cost_min_profit_usd', 1 ) );
	}

	public static function is_bajo_pedido( WC_Product $product ): bool {
		$prefix = class_exists( 'CVD_Market_Bridge' ) ? CVD_Market_Bridge::SKU_PREFIX : 'MK-';
		if ( 0 === stripos( (string) $product->get_sku( 'edit' ), $prefix ) || $product->get_meta( '_cvd_market_item_id', true ) ) { return true; }
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		return $parent && 0 === stripos( (string) $parent->get_sku( 'edit' ), $prefix );
	}

	/** La comisión que pagaría la web por una unidad: la del producto, la del padre o la general (como CVD_Commissions). */
	public static function commission( WC_Product $product ): array {
		foreach ( array( $product, $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null ) as $p ) {
			if ( ! $p ) { continue; }
			$type = (string) $p->get_meta( '_cvd_commission_type', true );
			$value = (string) $p->get_meta( '_cvd_commission_value', true );
			if ( '' !== $type || '' !== $value ) {
				return array( 'type' => 'fixed' === sanitize_key( $type ) ? 'fixed' : 'percent', 'value' => (float) $value );
			}
		}
		return array( 'type' => 'percent', 'value' => (float) get_option( 'cvd_default_commission_rate', 10 ) );
	}

	/** Costo propio o, en una variación sin costo, el del padre. null = sin costo. */
	public static function cost( WC_Product $product ): ?float {
		$own = $product->get_meta( self::COST, true );
		if ( '' !== (string) $own && is_numeric( $own ) ) { return (float) $own; }
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$inherited = $parent ? $parent->get_meta( self::COST, true ) : '';
			if ( '' !== (string) $inherited && is_numeric( $inherited ) ) { return (float) $inherited; }
		}
		return null;
	}

	/** Precio mínimo para ese costo y esa comisión. */
	public static function floor_for( float $cost, array $commission, array $rules ): float {
		$k = $cost + $rules['fixed'];
		$p = $rules['pct'] / 100;
		if ( 'fixed' === $commission['type'] ) {
			$floor = max( $k + $commission['value'] + $rules['min'], ( $k + $commission['value'] ) / ( 1 - $p ) );
		} else {
			$c = $commission['value'] / 100;
			$floor = ( 1 - $c - $p ) > 0 ? max( ( $k + $rules['min'] ) / ( 1 - $c ), $k / ( 1 - $c - $p ) ) : INF;
		}
		return ceil( $floor * 100 - 1e-6 ) / 100;
	}

	/** Estado de un producto vendible (simple o variación): costo, mínimo, precio y ok | bajo | sin_costo. */
	public static function evaluate( WC_Product $product ): array {
		$price = (float) $product->get_price( 'edit' );
		$cost = self::cost( $product );
		if ( null === $cost ) {
			return array( 'cost' => null, 'floor' => null, 'price' => $price, 'status' => 'sin_costo' );
		}
		$floor = self::floor_for( $cost, self::commission( $product ), self::rules( self::is_bajo_pedido( $product ) ) );
		return array( 'cost' => $cost, 'floor' => $floor, 'price' => $price, 'status' => $price + 0.005 >= $floor ? 'ok' : 'bajo' );
	}

	// ---------- guardar y recalcular ----------

	/** Guarda el costo (sin llamar a save(): quien llama guarda el producto). */
	public static function set_cost( WC_Product $product, ?float $cost, string $source ): bool {
		$source = array_key_exists( $source, self::SOURCES ) ? $source : 'manual';
		$old = (string) $product->get_meta( self::COST, true );
		if ( null === $cost ) {
			if ( '' === $old ) { return false; }
			$product->delete_meta_data( self::COST );
			$product->delete_meta_data( self::SOURCE );
			$product->update_meta_data( self::UPDATED, gmdate( 'c' ) );
			return true;
		}
		$new = wc_format_decimal( max( 0, $cost ), 2 );
		$changed = '' === $old || abs( (float) $old - (float) $new ) > 0.004 || (string) $product->get_meta( self::SOURCE, true ) !== $source;
		if ( ! $changed ) { return false; }
		$product->update_meta_data( self::COST, $new );
		$product->update_meta_data( self::SOURCE, $source );
		$product->update_meta_data( self::UPDATED, gmdate( 'c' ) );
		return true;
	}

	/** Al guardar cualquier producto (wp-admin, panel, bot, pedidos): recalcula su mínimo. Nunca rompe el guardado. */
	public static function after_save( $product ): void {
		if ( self::$busy || ! $product instanceof WC_Product || ! $product->get_id() ) { return; }
		self::$busy = true;
		try {
			self::refresh( $product );
		} catch ( Throwable $e ) {
			error_log( 'CVD_Costs: ' . $e->getMessage() );
		} finally {
			self::$busy = false;
		}
	}

	/** Escribe _cvd_floor_usd / _cvd_floor_status / _cvd_under_floor sin volver a guardar el producto. */
	public static function refresh( WC_Product $product ): void {
		if ( $product->is_type( 'variable' ) ) {
			$any_bad = false;
			$all_ok = true;
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) { continue; }
				$state = self::write( $child );
				$any_bad = $any_bad || 'bajo' === $state;
				$all_ok = $all_ok && 'ok' === $state;
			}
			$status = $any_bad ? 'bajo' : ( $all_ok ? 'ok' : 'sin_costo' );
			update_post_meta( $product->get_id(), self::STATUS, $status );
			update_post_meta( $product->get_id(), self::UNDER, 'bajo' === $status ? '1' : '0' );
			delete_post_meta( $product->get_id(), self::FLOOR );
			return;
		}
		self::write( $product );
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$states = array();
				foreach ( $parent->get_children() as $child_id ) { $states[] = (string) get_post_meta( $child_id, self::STATUS, true ); }
				$status = in_array( 'bajo', $states, true ) ? 'bajo' : ( $states && count( array_unique( $states ) ) === 1 && 'ok' === $states[0] ? 'ok' : 'sin_costo' );
				update_post_meta( $parent->get_id(), self::STATUS, $status );
				update_post_meta( $parent->get_id(), self::UNDER, 'bajo' === $status ? '1' : '0' );
			}
		}
	}

	private static function write( WC_Product $product ): string {
		$e = self::evaluate( $product );
		$id = $product->get_id();
		if ( null === $e['floor'] ) { delete_post_meta( $id, self::FLOOR ); } else { update_post_meta( $id, self::FLOOR, wc_format_decimal( $e['floor'], 2 ) ); }
		update_post_meta( $id, self::STATUS, $e['status'] );
		update_post_meta( $id, self::UNDER, 'bajo' === $e['status'] ? '1' : '0' );
		return $e['status'];
	}

	// ---------- wp-admin (solo quien gestiona la tienda) ----------

	private static function can(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	private static function status_text( array $e ): string {
		if ( 'sin_costo' === $e['status'] ) { return 'Sin costo: no se puede calcular el mínimo.'; }
		$txt = 'Precio mínimo: ' . wc_format_localized_price( $e['floor'] ) . ' USD';
		return 'bajo' === $e['status'] ? '⚠️ ' . $txt . ' — el precio de hoy está POR DEBAJO.' : '✅ ' . $txt . ' — el precio está bien.';
	}

	private static function fields_html( string $name_cost, string $name_source, WC_Product $product, string $id_suffix = '' ): void {
		$cost = (string) $product->get_meta( self::COST, true );
		$source = (string) $product->get_meta( self::SOURCE, true );
		$updated = (string) $product->get_meta( self::UPDATED, true );
		$e = self::evaluate( $product );
		echo '<p class="form-field form-row"><label for="cvd_cost_usd' . esc_attr( $id_suffix ) . '">Costo de compra (USD) <span class="woocommerce-help-tip" data-tip="Solo lo ve la administración. Compra + flete por unidad."></span></label>';
		echo '<input type="text" class="short wc_input_price" style="width:120px" id="cvd_cost_usd' . esc_attr( $id_suffix ) . '" name="' . esc_attr( $name_cost ) . '" value="' . esc_attr( '' === $cost ? '' : wc_format_localized_price( $cost ) ) . '" placeholder="' . esc_attr( $product->is_type( 'variation' ) ? 'el del producto' : 'sin costo' ) . '"> ';
		echo '<select name="' . esc_attr( $name_source ) . '" style="width:auto">';
		foreach ( self::SOURCES as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $source ?: 'manual', $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		$when = $updated ? ' · costo cambiado el ' . esc_html( wp_date( 'j M Y H:i', strtotime( $updated ) ) ) : '';
		echo '<p class="form-field form-row description" style="margin-top:0">' . esc_html( self::status_text( $e ) ) . $when . '</p>';
	}

	public static function product_fields(): void {
		global $product_object;
		if ( ! self::can() || ! $product_object instanceof WC_Product ) { return; }
		echo '<div class="options_group cvd-cost-group">';
		self::fields_html( 'cvd_cost_usd', 'cvd_cost_source', $product_object );
		echo '</div>';
	}

	public static function save_product_fields( WC_Product $product ): void {
		if ( ! self::can() || ! isset( $_POST['cvd_cost_usd'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce ya comprobó el nonce del formulario.
		$raw = trim( (string) wp_unslash( $_POST['cvd_cost_usd'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$source = sanitize_key( (string) wp_unslash( $_POST['cvd_cost_source'] ?? 'manual' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::set_cost( $product, '' === $raw ? null : (float) wc_format_decimal( $raw ), $source );
	}

	public static function variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation );
		if ( ! self::can() || ! $product ) { return; }
		echo '<div class="cvd-cost-variation" style="clear:both">';
		self::fields_html( 'cvd_cost_usd[' . absint( $loop ) . ']', 'cvd_cost_source[' . absint( $loop ) . ']', $product, '_' . absint( $loop ) );
		echo '</div>';
	}

	public static function save_variation_fields( $variation, $i ): void {
		if ( ! self::can() || ! isset( $_POST['cvd_cost_usd'][ $i ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		$raw = trim( (string) wp_unslash( $_POST['cvd_cost_usd'][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$source = sanitize_key( (string) wp_unslash( $_POST['cvd_cost_source'][ $i ] ?? 'manual' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::set_cost( $variation, '' === $raw ? null : (float) wc_format_decimal( $raw ), $source );
	}

	/** La API REST de WooCommerce no enseña el costo a quien no gestiona la tienda. */
	public static function hide_from_rest( $response ) {
		if ( self::can() || ! $response instanceof WP_REST_Response ) { return $response; }
		$data = $response->get_data();
		if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
			$data['meta_data'] = array_values( array_filter( $data['meta_data'], static function ( $m ) {
				$key = $m instanceof WC_Meta_Data ? (string) ( $m->get_data()['key'] ?? '' ) : ( is_array( $m ) ? (string) ( $m['key'] ?? '' ) : '' );
				return ! in_array( $key, self::PRIVATE_KEYS, true );
			} ) );
			$response->set_data( $data );
		}
		return $response;
	}

	// ---------- REST: bot (VPS) y panel ----------

	public static function routes(): void {
		$bot = array( 'CVD_Bot_Bridge', 'authorized' );
		register_rest_route( self::NS, '/bot/costs', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_set_many' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/bot/costs/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_summary' ), 'permission_callback' => $bot ) );
		register_rest_route( self::NS, '/panel/products/cost', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_panel_cost' ), 'permission_callback' => array( 'CVD_Panel_Bridge', 'authorized' ) ) );
	}

	/**
	 * items: [{ id | sku | item_id, cost, source }] (máx. 1000). Si no cambia nada, no guarda.
	 * only_empty = true: no pisa un costo que ya existe con otro origen (p. ej. uno manual).
	 */
	public static function rest_set_many( WP_REST_Request $request ): WP_REST_Response {
		$items = array_slice( (array) $request->get_param( 'items' ), 0, 1000 );
		$only_empty = (bool) $request->get_param( 'only_empty' );
		$out = array( 'changed' => 0, 'same' => 0, 'skipped' => 0, 'missing' => array() );
		foreach ( $items as $item ) {
			$item = (array) $item;
			$id = absint( $item['id'] ?? 0 );
			if ( ! $id && ! empty( $item['item_id'] ) ) { $id = (int) wc_get_product_id_by_sku( 'MK-' . absint( $item['item_id'] ) ); }
			if ( ! $id && ! empty( $item['sku'] ) ) { $id = (int) wc_get_product_id_by_sku( sanitize_text_field( (string) $item['sku'] ) ); }
			$product = $id ? wc_get_product( $id ) : null;
			$cost = isset( $item['cost'] ) && is_numeric( $item['cost'] ) ? (float) $item['cost'] : null;
			if ( ! $product || null === $cost || $cost < 0 ) { $out['missing'][] = $item['id'] ?? $item['item_id'] ?? $item['sku'] ?? null; continue; }
			$source = sanitize_key( (string) ( $item['source'] ?? 'manual' ) );
			$current = (string) $product->get_meta( self::SOURCE, true );
			if ( $only_empty && '' !== (string) $product->get_meta( self::COST, true ) && $current !== $source ) { $out['skipped']++; continue; }
			if ( self::set_cost( $product, $cost, $source ) ) { $product->save(); $out['changed']++; } else { $out['same']++; }
		}
		return new WP_REST_Response( array( 'ok' => true ) + $out, 200 );
	}

	/** Los publicados (simples y variaciones de un padre publicado): cuántos OK, bajo el mínimo y sin costo. */
	public static function summary( int $list = 0 ): array {
		$counts = array( 'ok' => 0, 'bajo' => 0, 'sin_costo' => 0 );
		$by_kind = array( 'tienda' => $counts, 'bajo_pedido' => $counts );
		$under = array();
		$ids = get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) { continue; }
			if ( $product->get_parent_id() && 'publish' !== get_post_status( $product->get_parent_id() ) ) { continue; }
			$e = self::evaluate( $product );
			$kind = self::is_bajo_pedido( $product ) ? 'bajo_pedido' : 'tienda';
			$counts[ $e['status'] ]++;
			$by_kind[ $kind ][ $e['status'] ]++;
			if ( 'bajo' === $e['status'] && count( $under ) < $list ) {
				$under[] = array( 'id' => $id, 'name' => wp_strip_all_tags( $product->get_name() ), 'price' => $e['price'], 'floor' => $e['floor'], 'cost' => $e['cost'] );
			}
		}
		$line = sprintf( '%d productos bajo el mínimo · %d sin costo', $counts['bajo'], $counts['sin_costo'] );
		return array( 'total' => array_sum( $counts ), 'counts' => $counts, 'by_kind' => $by_kind, 'line' => $line, 'under' => $under );
	}

	public static function rest_summary( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'ok' => true ) + self::summary( min( 200, absint( $request->get_param( 'list' ) ) ) ), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/** Panel: Lennys guarda el costo en la ficha de costo de NEXO → se copia aquí (origen manual). */
	public static function rest_panel_cost( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request->get_param( 'wooId' ) ) );
		$cost = $request->get_param( 'costUsd' );
		if ( ! $product || ! is_numeric( $cost ) || (float) $cost < 0 ) {
			return new WP_Error( 'cvd_panel_cost', 'Producto o costo no válido.', array( 'status' => 422 ) );
		}
		if ( self::set_cost( $product, (float) $cost, sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'manual' ) ) ) ) { $product->save(); }
		$fresh = wc_get_product( $product->get_id() );
		$e = self::evaluate( $fresh );
		$response = new WP_REST_Response( array( 'ok' => true, 'wooId' => $fresh->get_id() ) + $e, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
