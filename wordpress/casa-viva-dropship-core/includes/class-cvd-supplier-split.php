<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.14 (Ernesto, 14-oct, D66 + D69): reparto de la venta de un producto de proveedor (bajo pedido, SKU MK-).
 *
 * Todo sale del margen = precio web de esas líneas − precio del proveedor:
 *   comisión de la gestora (la de siempre, _cvd_commission_*; aquí NO se cambia)
 *   + fondo de la operación: mensajero (ir a buscarlo / llevarlo) + dependienta + tienda Casa Viva (la tienda siempre gana)
 *   + el resto para el gestor especial (hoy siempre Ernesto).
 * Se apunta en el pedido (_cvd_supplier_split, JSON) desde el panel de Lennys y se sella al cerrar el pedido
 * (entregado y dinero confirmado = pedido «completado», el mismo momento en que se aprueba la comisión de la gestora):
 * _cvd_supplier_split_closed_at + nota en el pedido con quién gana cuánto. Es información: no mueve dinero ni pagos.
 *
 * Margen mínimo (sugerencia de Claude, aviso y no bloqueo): margen ≥ comisión gestora + fondo + 1 USD.
 * 3.14.49 (Ernesto 22-oct): ruta C (directo del proveedor al cliente) = dependienta 0 sugerida (los cerrados no cambian:
 * usan lo sellado). La parte del dueño (specialCup) es SU comisión en su área (owner_commissions) y sus puntos propios.
 * 3.14.50 (Ernesto 22-oct): «Mis ventas de proveedor» con el desglose de cada venta (owner_rows + render_detail) y las
 * estadísticas del mes o de «Todo» (owner_stats). El recargo de la Clásica que es ganancia viene del sellado (surchargeUsd).
 * Rutas (clave del panel, como CVD_Panel_Bridge):
 *   GET  /panel/supplier-split/<id>         reparto guardado o sugerido
 *   POST /panel/supplier-split/<id>         guardar {supplier, costUsd, route, vehicle, messengerCup, messengerName, clerkCup, clerkName, storeCup, by}
 *   GET  /panel/supplier-sales?month=AAAA-MM  ventas de proveedor del mes con cada parte y totales por persona
 */
final class CVD_Supplier_Split {
	public const META = '_cvd_supplier_split';
	public const CLOSED = '_cvd_supplier_split_closed_at';
	private const NS = 'casa-viva/v1';
	public const ROUTES = array( 'A' => 'El proveedor lo trae a la tienda', 'B' => 'Nuestro mensajero lo recoge en el proveedor', 'C' => 'El proveedor lo lleva directo al cliente' );
	public const VEHICLES = array( 'moto' => 'Moto', 'triciclo' => 'Triciclo', 'ninguno' => 'Sin vehículo' );
	/** Sugerencias por defecto (D69), en CUP. Se pueden cambiar sin tocar código con estas opciones. */
	private const DEFAULTS = array( 'moto' => 1000, 'triciclo' => 2500, 'clerk_per_item' => 500, 'store' => 3000, 'store_min' => 1000 ); // D92: 3.000 por la opción cvd_supplier_split_store (antes 1.500); los cerrados guardan el suyo
	public const SPECIAL_NAME = 'Ernesto';
	/** 3.14.48 (D92): usuario de la cuenta de gestor del dueño (hoy 137). Vacía = nada de lo del dueño-gestor tiene efecto. */
	public const OWNER_OPTION = 'cvd_owner_gestor_user';

	/** 3.14.48 (D92): ventas de proveedor hechas FUERA de la web (JSON, las apunta Ernesto/Claude a mano). */
	public const OFFWEB_OPTION = 'cvd_owner_offweb_sales';

	/** 3.14.48: id de la cuenta de gestor del dueño, o 0 si la opción está vacía. */
	public static function owner_gestor_user(): int {
		return absint( get_option( self::OWNER_OPTION, 0 ) );
	}

	/**
	 * 3.14.48 (D92): ventas de proveedor fuera de la web, limpias. $month 'AAAA-MM' filtra por la fecha ('' = todas).
	 * Cada una: date, product, supplier, gestora, gestoraUsd, saleUsd, costUsd, specialUsd, rate (0 = sin tasa), paidCup,
	 * note, state (abierto | cerrado | cancelado) y points = margen limpio (venta − proveedor − gestora, ≥ 0; Ernesto 22-oct).
	 */
	public static function offweb_sales( string $month = '' ): array {
		$raw = get_option( self::OFFWEB_OPTION, '' );
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $i => $s ) {
			if ( ! is_array( $s ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $s['date'] ?? '' ) ) ) { continue; }
			if ( '' !== $month && 0 !== strpos( (string) $s['date'], $month ) ) { continue; }
			$num = static fn( string $k ): float => is_numeric( $s[ $k ] ?? null ) ? (float) $s[ $k ] : 0.0;
			$state = sanitize_key( (string) ( $s['state'] ?? 'abierto' ) );
			$out[] = array(
				'key' => 'fuera:' . $i, 'date' => (string) $s['date'], 'product' => (string) ( $s['product'] ?? '' ), 'supplier' => (string) ( $s['supplier'] ?? '' ),
				'gestora' => (string) ( $s['gestora'] ?? '' ), 'gestoraUsd' => $num( 'gestoraUsd' ), 'saleUsd' => $num( 'saleUsd' ), 'costUsd' => $num( 'costUsd' ),
				'specialUsd' => $num( 'specialUsd' ), 'rate' => $num( 'rate' ), 'paidCup' => $num( 'paidCup' ), 'note' => (string) ( $s['note'] ?? '' ),
				'state' => in_array( $state, array( 'abierto', 'cerrado', 'cancelado' ), true ) ? $state : 'abierto',
				'points' => round( max( 0.0, $num( 'saleUsd' ) - $num( 'costUsd' ) - $num( 'gestoraUsd' ) ), 4 ),
			);
		}
		return $out;
	}

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Al cerrar: todos los caminos de cierre (mensajería, recogida en tienda, /ventas/, panel) acaban en «completado».
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'seal_on_complete' ), 60, 2 );
	}

	public static function routes(): void {
		$auth = array( 'CVD_Panel_Bridge', 'authorized' );
		register_rest_route( self::NS, '/panel/supplier-split/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/supplier-split/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/panel/supplier-sales', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_month' ), 'permission_callback' => $auth ) );
	}

	// ---------- cálculo ----------

	private static function opt( string $key ): int {
		return (int) get_option( 'cvd_supplier_split_' . $key, self::DEFAULTS[ $key ] );
	}

	/** Líneas del pedido que son de proveedor (bajo pedido): precio web, costo y comisión de la gestora de cada una. */
	public static function supplier_lines( WC_Order $order ): array {
		$owner_type = sanitize_key( (string) $order->get_meta( '_cvd_owner_type', true ) );
		$commission_status = sanitize_key( (string) $order->get_meta( '_cvd_commission_status', true ) );
		$pays_gestora = 'gestora' === $owner_type && absint( $order->get_meta( '_cvd_owner_user_id', true ) ) && 'cancelled' !== $commission_status;
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product || ! class_exists( 'CVD_Costs' ) || ! CVD_Costs::is_bajo_pedido( $product ) ) { continue; }
			$qty = max( 1, (int) $item->get_quantity() );
			$cost = CVD_Costs::cost( $product );
			$snapshot = $item->get_meta( '_cvd_commission_snapshot', true );
			$gestora = $pays_gestora && is_array( $snapshot ) ? (float) ( $snapshot['base_commission'] ?? 0 ) + (float) ( $snapshot['markup'] ?? 0 ) : 0.0;
			$supplier = (string) $product->get_meta( '_cvd_supplier_name', true );
			if ( '' === $supplier && $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); $supplier = $parent ? (string) $parent->get_meta( '_cvd_supplier_name', true ) : ''; }
			$lines[] = array(
				'itemId' => (int) $item_id, 'name' => $item->get_name(), 'sku' => (string) $product->get_sku(), 'qty' => $qty,
				'webUsd' => round( (float) $item->get_total(), 2 ), 'costUsd' => null === $cost ? null : round( $cost * $qty, 2 ),
				'gestoraUsd' => round( $gestora, 2 ), 'supplier' => $supplier,
			);
		}
		// Si la comisión del pedido no tiene foto por línea (pedidos viejos), toda la comisión cuenta para estas líneas.
		if ( $lines && $pays_gestora && 0.0 === array_sum( array_column( $lines, 'gestoraUsd' ) ) && count( $order->get_items( 'line_item' ) ) === count( $lines ) ) {
			$lines[0]['gestoraUsd'] = round( (float) $order->get_meta( '_cvd_commission_amount', true ), 2 );
		}
		return $lines;
	}

	public static function saved( WC_Order $order ): array {
		$raw = $order->get_meta( self::META, true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private static function messenger_name( WC_Order $order ): string {
		$id = absint( $order->get_meta( '_cvd_messenger_user_id', true ) );
		$user = $id ? get_userdata( $id ) : null;
		return $user ? (string) $user->display_name : '';
	}

	/** Mensajero sugerido: ruta C = 0 (lo cobra el proveedor); si no, la tarifa por vehículo. */
	public static function messenger_default( string $route, string $vehicle ): int {
		if ( 'C' === $route || 'ninguno' === $vehicle ) { return 0; }
		return 'triciclo' === $vehicle ? self::opt( 'triciclo' ) : self::opt( 'moto' );
	}

	/** 3.14.49: dependienta sugerida: ruta C = 0 (va directo del proveedor al cliente); si no, 500 CUP (opción) por artículo. */
	public static function clerk_default( string $route, int $qty ): int {
		return 'C' === $route ? 0 : self::opt( 'clerk_per_item' ) * max( 1, $qty );
	}

	/**
	 * Reparto completo: lo guardado encima de la sugerencia, y las cuentas. $input (opcional) = valores nuevos sin guardar.
	 * Importes CUP enteros; USD con 2 decimales. Tasa = la guardada en el pedido el día de la venta (o la de hoy si no tiene).
	 */
	public static function compute( WC_Order $order, ?array $input = null ): array {
		$lines = self::supplier_lines( $order );
		$saved = self::saved( $order );
		$v = array_merge( $saved['input'] ?? array(), $input ?? array() );
		$qty = array_sum( array_column( $lines, 'qty' ) ) ?: 1;
		$web = round( array_sum( array_column( $lines, 'webUsd' ) ), 2 );
		$cost_known = $lines && ! in_array( null, array_column( $lines, 'costUsd' ), true );
		$cost_default = round( (float) array_sum( array_map( 'floatval', array_column( $lines, 'costUsd' ) ) ), 2 );
		$route = isset( $v['route'], self::ROUTES[ $v['route'] ] ) ? $v['route'] : 'B';
		$vehicle = isset( $v['vehicle'], self::VEHICLES[ $v['vehicle'] ] ) ? $v['vehicle'] : ( 'C' === $route ? 'ninguno' : 'moto' );
		$order_rate = class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate( $order ) : null;
		$rate = $order_rate ?: ( class_exists( 'CVD_Daily_Rates' ) ? (float) ( CVD_Daily_Rates::get()['cup'] ?? 0 ) : 0.0 );
		$cost = isset( $v['costUsd'] ) && is_numeric( $v['costUsd'] ) ? round( (float) $v['costUsd'], 2 ) : $cost_default;
		$messenger = isset( $v['messengerCup'] ) && is_numeric( $v['messengerCup'] ) ? (int) $v['messengerCup'] : self::messenger_default( $route, $vehicle );
		// 3.14.49 (Ernesto 22-oct): ruta C (el proveedor lo lleva directo al cliente) = la dependienta no lo toca: 0 sugerido.
		$clerk = isset( $v['clerkCup'] ) && is_numeric( $v['clerkCup'] ) ? (int) $v['clerkCup'] : self::clerk_default( $route, $qty );
		$store = isset( $v['storeCup'] ) && is_numeric( $v['storeCup'] ) ? (int) $v['storeCup'] : self::opt( 'store' );
		$gestora_usd = round( array_sum( array_column( $lines, 'gestoraUsd' ) ), 2 );
		$margin_usd = round( $web - $cost, 2 );
		$margin_cup = (int) round( $margin_usd * $rate );
		$gestora_cup = (int) round( $gestora_usd * $rate );
		$fund = $messenger + $clerk + $store;
		$special = $margin_cup - $gestora_cup - $fund;
		$one_usd = (int) round( $rate );
		$min_cup = $gestora_cup + $fund + $one_usd;
		$meets = $rate > 0 && $margin_cup >= $min_cup;
		$supplier = trim( (string) ( $v['supplier'] ?? '' ) );
		if ( '' === $supplier ) { foreach ( $lines as $l ) { if ( '' !== $l['supplier'] ) { $supplier = $l['supplier']; break; } } }
		$owner_id = absint( $order->get_meta( '_cvd_owner_user_id', true ) );
		$owner = 'gestora' === $order->get_meta( '_cvd_owner_type', true ) && $owner_id ? get_userdata( $owner_id ) : null;
		$closed_at = (string) $order->get_meta( self::CLOSED, true );
		$warning = '';
		if ( $rate <= 0 ) {
			$warning = 'Este pedido no tiene la tasa del día guardada: no se puede pasar el margen a CUP.';
		} elseif ( $special < 0 ) {
			$warning = 'No llega: faltan ' . self::cup_text( -$special ) . ' para pagar a todos. Sube el precio web, baja el fondo o no aceptes la venta.';
		} elseif ( ! $meets ) {
			$warning = 'No llega al mínimo: al gestor especial le quedan ' . self::cup_text( $special ) . ' y el mínimo es 1 USD (' . self::cup_text( $one_usd ) . '). Sube el precio web, baja el fondo o no aceptes la venta.';
		}
		$result = array(
			'applies' => (bool) $lines || (bool) $saved,
			'orderId' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'orderStatus' => $order->get_status(), 'lines' => $lines,
			'supplier' => $supplier, 'route' => $route, 'routeLabel' => self::ROUTES[ $route ], 'vehicle' => $vehicle,
			'webUsd' => $web, 'costUsd' => $cost, 'costKnown' => $cost_known || isset( $v['costUsd'] ), 'costDefaultUsd' => $cost_default,
			'rate' => $rate, 'rateSource' => $order_rate ? 'pedido' : 'hoy', 'rateDay' => $order_rate && class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::order_rate_day( $order ) : '',
			'marginUsd' => $margin_usd, 'marginCup' => $margin_cup,
			'gestora' => $owner ? (string) $owner->display_name : null, 'gestoraUsd' => $gestora_usd, 'gestoraCup' => $gestora_cup,
			'messengerCup' => $messenger, 'messengerName' => trim( (string) ( $v['messengerName'] ?? '' ) ) ?: self::messenger_name( $order ),
			'clerkCup' => $clerk, 'clerkName' => trim( (string) ( $v['clerkName'] ?? '' ) ), 'storeCup' => $store,
			'fundCup' => $fund, 'specialCup' => $special, 'specialName' => self::SPECIAL_NAME,
			'minCup' => $min_cup, 'meetsMin' => $meets, 'warning' => $warning, 'qty' => $qty,
			'defaults' => array( 'moto' => self::opt( 'moto' ), 'triciclo' => self::opt( 'triciclo' ), 'clerkPerItem' => self::opt( 'clerk_per_item' ), 'store' => self::opt( 'store' ), 'storeMin' => self::opt( 'store_min' ) ),
			'saved' => ! empty( $saved['input'] ), 'savedAt' => $saved['savedAt'] ?? null, 'savedBy' => $saved['savedBy'] ?? null,
			'closed' => '' !== $closed_at, 'closedAt' => '' !== $closed_at ? $closed_at : null,
			'state' => '' !== $closed_at ? 'cerrado' : ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ? 'cancelado' : 'abierto' ),
			// 3.14.50 (Ernesto 22-oct 14:35): recargo de la forma de pago que SÍ es ganancia (Clásica cambiada a USD). Hoy solo
			// viene de lo sellado (#3845: webUsd 120,75 = 105 + 15,75); aquí 0 para que el sellado lo pueda traer.
			'surchargeUsd' => 0.0, 'surchargeNote' => '',
		);
		// 3.14.48 (D92): un reparto cerrado no cambia. Sin esto, al subir la tienda a 3.000 CUP (opción) los cerrados con
		// el reparto sugerido (#4201, #4244: tienda 1.500) se recalculaban y Ernesto veía 1.500 CUP menos en cada uno.
		if ( '' !== $closed_at && is_array( $saved['snapshot'] ?? null ) ) {
			$result = array_merge( $result, array_intersect_key( $saved['snapshot'], $result ) );
		}
		return $result;
	}

	public static function cup_text( int $cup ): string {
		return number_format( $cup, 0, ',', '.' ) . ' CUP';
	}

	private static function usd_text( float $usd ): string {
		return number_format( $usd, 2, ',', '.' ) . ' USD';
	}

	/** «Reparto de este producto: proveedor Digital Store 32,00 USD · mensajero Pedri 1.000 · …». */
	public static function summary( array $s ): string {
		$parts = array(
			'proveedor ' . ( '' !== $s['supplier'] ? $s['supplier'] . ' ' : '' ) . self::usd_text( (float) $s['costUsd'] ),
			'mensajero' . ( '' !== $s['messengerName'] ? ' ' . $s['messengerName'] : '' ) . ' ' . self::cup_text( (int) $s['messengerCup'] ),
			'dependienta' . ( '' !== $s['clerkName'] ? ' ' . $s['clerkName'] : '' ) . ' ' . self::cup_text( (int) $s['clerkCup'] ),
			'tienda Casa Viva ' . self::cup_text( (int) $s['storeCup'] ),
		);
		if ( $s['gestora'] ) { $parts[] = $s['gestora'] . ' (gestora) ' . self::usd_text( (float) $s['gestoraUsd'] ) . ' = ' . self::cup_text( (int) $s['gestoraCup'] ); }
		$parts[] = $s['specialName'] . ' (gestor especial) ' . self::cup_text( (int) $s['specialCup'] );
		return implode( ' · ', $parts ) . '. Margen ' . self::usd_text( (float) $s['marginUsd'] ) . ' = ' . self::cup_text( (int) $s['marginCup'] )
			. ' a ' . ( class_exists( 'CVD_Daily_Rates' ) ? CVD_Daily_Rates::format_rate( (float) $s['rate'] ) : $s['rate'] )
			. ' (ruta ' . $s['route'] . ', ' . strtolower( self::VEHICLES[ $s['vehicle'] ] ) . ').' . ( $s['meetsMin'] ? '' : ' ⚠ ' . $s['warning'] );
	}

	// ---------- guardar y sellar ----------

	/** Valida y guarda lo que manda el panel. Números ≥ 0, tienda ≥ mínimo. Nota en el pedido con quién y las cifras. */
	public static function save( WC_Order $order, array $p, string $by ) {
		if ( '' !== (string) $order->get_meta( self::CLOSED, true ) ) {
			return new WP_Error( 'cvd_split_closed', 'Este reparto ya está cerrado (el pedido se completó). No se puede cambiar.', array( 'status' => 409 ) );
		}
		if ( ! self::supplier_lines( $order ) ) {
			return new WP_Error( 'cvd_split_none', 'Este pedido no tiene productos de proveedor.', array( 'status' => 422 ) );
		}
		$in = array();
		$route = strtoupper( (string) ( $p['route'] ?? 'B' ) );
		$vehicle = sanitize_key( (string) ( $p['vehicle'] ?? 'moto' ) );
		if ( ! isset( self::ROUTES[ $route ] ) ) { return new WP_Error( 'cvd_split', 'Ruta no válida (A, B o C).', array( 'status' => 422 ) ); }
		if ( ! isset( self::VEHICLES[ $vehicle ] ) ) { return new WP_Error( 'cvd_split', 'Vehículo no válido.', array( 'status' => 422 ) ); }
		$in['route'] = $route;
		$in['vehicle'] = $vehicle;
		$labels = array( 'messengerCup' => 'mensajero', 'clerkCup' => 'dependienta', 'storeCup' => 'tienda' );
		foreach ( $labels as $key => $label ) {
			$raw = str_replace( array( '.', ' ' ), '', (string) ( $p[ $key ] ?? '' ) );
			if ( '' === $raw || ! preg_match( '/^\d{1,7}$/', $raw ) ) { return new WP_Error( 'cvd_split', 'Escribe lo del ' . $label . ' en CUP, sin decimales (0 o más).', array( 'status' => 422 ) ); }
			$in[ $key ] = (int) $raw;
		}
		if ( $in['storeCup'] < self::opt( 'store_min' ) ) {
			return new WP_Error( 'cvd_split', 'La tienda siempre gana: pon al menos ' . self::cup_text( self::opt( 'store_min' ) ) . ' para la tienda.', array( 'status' => 422 ) );
		}
		$cost = str_replace( ',', '.', trim( (string) ( $p['costUsd'] ?? '' ) ) );
		if ( '' !== $cost ) {
			if ( ! is_numeric( $cost ) || (float) $cost < 0 || (float) $cost > 100000 ) { return new WP_Error( 'cvd_split', 'El precio del proveedor no es válido.', array( 'status' => 422 ) ); }
			$in['costUsd'] = round( (float) $cost, 2 );
		}
		foreach ( array( 'supplier', 'messengerName', 'clerkName' ) as $key ) {
			$in[ $key ] = mb_substr( trim( sanitize_text_field( (string) ( $p[ $key ] ?? '' ) ) ), 0, 80 );
		}
		$s = self::compute( $order, $in );
		$by = mb_substr( sanitize_text_field( $by ), 0, 120 ) ?: 'panel';
		$order->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'input' => $in, 'savedAt' => gmdate( 'c' ), 'savedBy' => $by, 'snapshot' => self::snapshot( $s ) ) ) );
		$order->add_order_note( 'Reparto de venta de proveedor guardado por ' . $by . ' (panel): ' . self::summary( $s ) );
		$order->save();
		return self::compute( wc_get_order( $order->get_id() ) );
	}

	private static function snapshot( array $s ): array {
		return array_intersect_key( $s, array_flip( array( 'supplier', 'route', 'vehicle', 'webUsd', 'costUsd', 'rate', 'marginUsd', 'marginCup', 'gestora', 'gestoraUsd', 'gestoraCup', 'messengerCup', 'messengerName', 'clerkCup', 'clerkName', 'storeCup', 'fundCup', 'specialCup', 'specialName', 'minCup', 'meetsMin', 'surchargeUsd', 'surchargeNote' ) ) );
	}

	/** Pedido completado (entregado + dinero confirmado): sella el reparto final y lo apunta en una nota. Nunca rompe el cierre. */
	public static function seal_on_complete( $order_id, $order = null ): void {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( absint( $order_id ) );
			if ( ! $order || '' !== (string) $order->get_meta( self::CLOSED, true ) || ! self::supplier_lines( $order ) ) { return; }
			$s = self::compute( $order );
			$saved = self::saved( $order );
			$at = gmdate( 'c' );
			$order->update_meta_data( self::META, wp_json_encode( array( 'v' => 1, 'input' => $saved['input'] ?? array(), 'savedAt' => $saved['savedAt'] ?? null, 'savedBy' => $saved['savedBy'] ?? null, 'closedAt' => $at, 'snapshot' => self::snapshot( $s ), 'suggested' => empty( $saved['input'] ) ) ) );
			$order->update_meta_data( self::CLOSED, $at );
			$order->save_meta_data();
			$order->add_order_note( 'Reparto de este producto' . ( empty( $saved['input'] ) ? ' (sugerido; nadie lo cambió en el panel)' : '' ) . ': ' . self::summary( $s ) );
		} catch ( Throwable $e ) {
			error_log( 'CVD_Supplier_Split::seal_on_complete ' . $order_id . ': ' . $e->getMessage() );
		}
	}

	// ---------- lista del mes ----------

	/** Ids de pedidos con una línea de un producto MK- (o con reparto guardado). */
	private static function supplier_order_ids(): array {
		global $wpdb;
		$like = $wpdb->esc_like( class_exists( 'CVD_Market_Bridge' ) ? CVD_Market_Bridge::SKU_PREFIX : 'MK-' ) . '%';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = im.meta_value AND pm.meta_key = '_sku' AND pm.meta_value LIKE %s
			 WHERE oi.order_item_type = 'line_item'", $like ) );
		$saved = wc_get_orders( array( 'limit' => 500, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'any', 'meta_query' => array( array( 'key' => self::META, 'compare' => 'EXISTS' ) ) ) );
		return array_values( array_unique( array_map( 'absint', array_merge( (array) $ids, (array) $saved ) ) ) );
	}

	public static function month( string $month ): array {
		$tz = wp_timezone();
		$start = preg_match( '/^\d{4}-\d{2}$/', $month ) ? new DateTimeImmutable( $month . '-01 00:00:00', $tz ) : ( new DateTimeImmutable( 'now', $tz ) )->modify( 'first day of this month' )->setTime( 0, 0 );
		$end = $start->modify( 'first day of next month' );
		$rows = array();
		$tot = array( 'costUsd' => 0.0, 'marginCup' => 0, 'messengerCup' => 0, 'clerkCup' => 0, 'storeCup' => 0, 'gestoraCup' => 0, 'specialCup' => 0 );
		$people = array();
		$add = static function ( string $key, string $role, string $name, int $cup, float $usd = 0.0 ) use ( &$people ): void {
			$people[ $key ] = $people[ $key ] ?? array( 'role' => $role, 'name' => $name, 'cup' => 0, 'usd' => 0.0, 'orders' => 0 );
			$people[ $key ]['cup'] += $cup;
			$people[ $key ]['usd'] = round( $people[ $key ]['usd'] + $usd, 2 );
			$people[ $key ]['orders']++;
		};
		foreach ( self::supplier_order_ids() as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() ) { continue; }
			$created = $order->get_date_created()->getTimestamp();
			if ( $created < $start->getTimestamp() || $created >= $end->getTimestamp() ) { continue; }
			$s = self::compute( $order );
			if ( ! $s['applies'] ) { continue; }
			$rows[] = array_intersect_key( $s, array_flip( array( 'orderId', 'number', 'date', 'orderStatus', 'state', 'gestora', 'supplier', 'route', 'vehicle', 'webUsd', 'costUsd', 'rate', 'marginUsd', 'marginCup', 'gestoraUsd', 'gestoraCup', 'messengerCup', 'messengerName', 'clerkCup', 'clerkName', 'storeCup', 'specialCup', 'meetsMin', 'warning', 'saved', 'closedAt' ) ) )
				+ array( 'products' => implode( ', ', array_map( static fn( $l ) => ( $l['qty'] > 1 ? $l['qty'] . '× ' : '' ) . $l['name'], $s['lines'] ) ) );
			if ( 'cancelado' === $s['state'] ) { continue; }
			foreach ( array( 'marginCup', 'messengerCup', 'clerkCup', 'storeCup', 'gestoraCup', 'specialCup' ) as $k ) { $tot[ $k ] += (int) $s[ $k ]; }
			$tot['costUsd'] = round( $tot['costUsd'] + (float) $s['costUsd'], 2 );
			$add( 'p:' . mb_strtolower( $s['supplier'] ), 'Proveedor', '' !== $s['supplier'] ? $s['supplier'] : 'Proveedor sin nombre', 0, (float) $s['costUsd'] );
			if ( $s['messengerCup'] > 0 ) { $add( 'm:' . mb_strtolower( $s['messengerName'] ), 'Mensajero', '' !== $s['messengerName'] ? $s['messengerName'] : 'Mensajero sin nombre', (int) $s['messengerCup'] ); }
			if ( $s['clerkCup'] > 0 ) { $add( 'd:' . mb_strtolower( $s['clerkName'] ), 'Dependienta', '' !== $s['clerkName'] ? $s['clerkName'] : 'Dependienta sin nombre', (int) $s['clerkCup'] ); }
			$add( 't', 'Tienda', 'Casa Viva', (int) $s['storeCup'] );
			if ( $s['gestora'] ) { $add( 'g:' . mb_strtolower( $s['gestora'] ), 'Gestora', $s['gestora'], (int) $s['gestoraCup'], (float) $s['gestoraUsd'] ); }
			$add( 'e', 'Gestor especial', $s['specialName'], (int) $s['specialCup'] );
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );
		return array( 'month' => $start->format( 'Y-m' ), 'orders' => $rows, 'totals' => $tot, 'people' => array_values( $people ) );
	}

	// ---------- «Mis ventas de proveedor» (3.14.48, D92; 3.14.50: desglose de cada venta y estadísticas) ----------

	/** 3.14.50: periodo «Todo» (desde el inicio) del selector de «Mis ventas de proveedor». */
	public const ALL = 'todo';

	/**
	 * 3.14.50 (Ernesto 22-oct): cómo pagó el cliente (_cvd_payment_*), o null si el pedido no lo tiene (pedidos viejos).
	 * surchargePct > 0 = recargo de una forma de pago en USD (Tarjeta Clásica ×1,15, Zelle, PayPal): lo paga el cliente
	 * encima del precio web y NO entra en el reparto (el reparto sale siempre del precio web).
	 */
	public static function payment_info( WC_Order $order ): ?array {
		$kind = sanitize_key( (string) $order->get_meta( '_cvd_payment_kind', true ) );
		if ( '' === $kind ) { return null; }
		$checkout = class_exists( 'CVD_Cuban_Checkout' ) ? CVD_Cuban_Checkout::order_payment( $order ) : null;
		$currency = (string) ( $checkout['currency'] ?? $order->get_meta( '_cvd_payment_currency', true ) );
		$currency = '' !== $currency ? $currency : 'USD';
		$factor = (float) ( $checkout['factor'] ?? $order->get_meta( '_cvd_payment_factor', true ) );
		$raw = $checkout ? $checkout['amount'] : $order->get_meta( '_cvd_payment_amount', true );
		return array(
			'kind' => $kind, 'label' => (string) ( $checkout['label'] ?? $kind ), 'currency' => $currency,
			'amount' => is_numeric( $raw ) ? (float) $raw : null, 'factor' => $factor,
			'surchargePct' => 'USD' === $currency && $factor > 1.0001 ? round( ( $factor - 1 ) * 100, 2 ) : 0.0,
		);
	}

	/**
	 * 3.14.50: «Cobrado al cliente: 120,75 USD por Clásica (recargo 15 % de la tarjeta)». '' sin datos.
	 * $in_split > 0 = ese recargo (USD) ya está sumado al reparto como ganancia (Ernesto 22-oct 14:35, #3845).
	 */
	public static function payment_line( ?array $p, float $in_split = 0.0 ): string {
		if ( ! $p || null === $p['amount'] ) { return ''; }
		$money = 'CUP' === $p['currency'] ? self::cup_text( (int) round( $p['amount'] ) ) : number_format( (float) $p['amount'], 2, ',', '.' ) . ' ' . $p['currency'];
		if ( $p['surchargePct'] > 0 ) {
			$clasica = 'clasica' === $p['kind'];
			$name = $clasica ? 'Clásica' : $p['label'];
			return 'Cobrado al cliente: ' . $money . ' por ' . $name . ' (recargo ' . self::num( (float) $p['surchargePct'] ) . ' % ' . ( $clasica ? 'de la tarjeta' : 'de ' . $name )
				. ( $in_split > 0 ? ': ' . self::usd_text( $in_split ) . ' de ganancia, ya sumados al margen' : '' ) . ')';
		}
		return 'Cobrado al cliente: ' . $money . ' por ' . $p['label'] . ( 'USD' === $p['currency'] ? '' : ' (en ' . $p['currency'] . ' con la tasa del día; el reparto va con el precio web)' );
	}

	/** 1234.5 → «1.234,50»; 15 → «15». */
	private static function num( float $v ): string {
		return number_format( $v, abs( $v - round( $v ) ) < 0.005 ? 0 : 2, ',', '.' );
	}

	/** «21.002 CUP + 19,00 USD» (usd = lo que no tiene tasa) o solo «21.002 CUP». */
	private static function money_pair( array $t ): string {
		return $t['usd'] > 0 ? ( $t['cup'] ? self::cup_text( (int) $t['cup'] ) . ' + ' : '' ) . self::usd_text( (float) $t['usd'] ) : self::cup_text( (int) $t['cup'] );
	}

	/** «15 de octubre de 2026». */
	private static function date_text( int $ts ): string {
		return wp_date( 'j', $ts ) . ' de ' . ( CVD_Programa_Calc::MONTHS[ (int) wp_date( 'n', $ts ) ] ?? '' ) . ' de ' . wp_date( 'Y', $ts );
	}

	/**
	 * 3.14.50: filas de «Mis ventas de proveedor» del periodo ('AAAA-MM' o 'todo'), la venda quien la venda: las de la web
	 * (compute(): lo sellado si está cerrado) y las de fuera de la web, con todo su desglose. Las más nuevas arriba.
	 * CUP enteros (null = sin tasa); USD con 2 decimales. *Cup de una fila = webCup − costCup = marginCup y
	 * marginCup − gestoraCup − mensajero − dependienta − tienda − otros = specialCup. Solo lee.
	 */
	public static function owner_rows( string $period ): array {
		$all = self::ALL === $period;
		$rows = array();
		foreach ( self::supplier_order_ids() as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() ) { continue; }
			$ts = $order->get_date_created()->getTimestamp();
			if ( ! $all && wp_date( 'Y-m', $ts ) !== $period ) { continue; }
			$s = self::compute( $order );
			if ( $s['applies'] ) { $rows[] = self::web_row( $s, $order, $ts ); }
		}
		foreach ( self::offweb_sales( $all ? '' : $period ) as $w ) { $rows[] = self::offweb_row( $w ); }
		usort( $rows, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		return $rows;
	}

	private static function web_row( array $s, WC_Order $order, int $ts ): array {
		$rate = (float) $s['rate'];
		$has = $rate > 0;
		// webUsd = lo cobrado que entra en el reparto: precio web + recargo que es ganancia (surchargeUsd, del sellado).
		$surcharge = round( max( 0.0, (float) ( $s['surchargeUsd'] ?? 0 ) ), 2 );
		$web_cup = $has ? (int) round( (float) $s['webUsd'] * $rate ) : null;
		$price_cup = $has ? (int) round( ( (float) $s['webUsd'] - $surcharge ) * $rate ) : null;
		$route = isset( self::ROUTES[ $s['route'] ] ) ? (string) $s['route'] : 'B';
		$vehicle = isset( self::VEHICLES[ $s['vehicle'] ] ) ? (string) $s['vehicle'] : 'ninguno';
		return array(
			'key' => 'web:' . $s['orderId'], 'offweb' => false, 'num' => '#' . $s['number'], 'ts' => $ts,
			'products' => implode( ', ', array_map( static fn( $l ) => ( $l['qty'] > 1 ? $l['qty'] . '× ' : '' ) . $l['name'], $s['lines'] ) ),
			'gestora' => (string) ( $s['gestora'] ?? '' ), 'supplier' => (string) $s['supplier'], 'state' => (string) $s['state'],
			'route' => $route, 'routeLabel' => self::ROUTES[ $route ], 'vehicle' => $vehicle,
			'rate' => $rate, 'rateSource' => (string) ( $s['rateSource'] ?? '' ), 'rateDay' => (string) ( $s['rateDay'] ?? '' ),
			'webUsd' => (float) $s['webUsd'], 'webCup' => $web_cup,
			'priceUsd' => round( (float) $s['webUsd'] - $surcharge, 2 ), 'priceCup' => $price_cup,
			'surchargeUsd' => $surcharge, 'surchargeCup' => $has ? $web_cup - $price_cup : null, 'surchargeNote' => (string) ( $s['surchargeNote'] ?? '' ),
			'costUsd' => (float) $s['costUsd'], 'costCup' => $has ? $web_cup - (int) $s['marginCup'] : null,
			'marginUsd' => (float) $s['marginUsd'], 'marginCup' => $has ? (int) $s['marginCup'] : null,
			'gestoraUsd' => (float) $s['gestoraUsd'], 'gestoraCup' => $has ? (int) $s['gestoraCup'] : null,
			'messengerCup' => (int) $s['messengerCup'], 'messengerName' => (string) $s['messengerName'],
			'clerkCup' => (int) $s['clerkCup'], 'clerkName' => (string) $s['clerkName'], 'storeCup' => (int) $s['storeCup'],
			'otherCup' => 0, 'otherUsd' => 0.0,
			'specialCup' => $has ? (int) $s['specialCup'] : null, 'specialUsd' => $has ? round( (int) $s['specialCup'] / $rate, 2 ) : 0.0,
			'closedAt' => $s['closedAt'] ?? null, 'warning' => 'abierto' === $s['state'] ? (string) $s['warning'] : '',
			'payment' => self::payment_info( $order ), 'paidCup' => 0.0, 'note' => '',
		);
	}

	/** Venta fuera de la web: venta − costo = margen; − gestora − tu parte = otros gastos (lo que no cuadre). */
	private static function offweb_row( array $w ): array {
		$rate = (float) $w['rate'];
		$has = $rate > 0;
		$margin = round( (float) $w['saleUsd'] - (float) $w['costUsd'], 2 );
		$other = round( $margin - (float) $w['gestoraUsd'] - (float) $w['specialUsd'], 2 );
		$web_cup = $has ? (int) round( (float) $w['saleUsd'] * $rate ) : null;
		$margin_cup = $has ? (int) round( $margin * $rate ) : null;
		$gestora_cup = $has ? (int) round( (float) $w['gestoraUsd'] * $rate ) : null;
		$special_cup = $has ? (int) round( (float) $w['specialUsd'] * $rate ) : null;
		return array(
			'key' => (string) $w['key'], 'offweb' => true, 'num' => 'Fuera', 'ts' => (int) strtotime( $w['date'] . ' 12:00:00 ' . wp_timezone_string() ),
			'products' => (string) $w['product'], 'gestora' => (string) $w['gestora'], 'supplier' => (string) $w['supplier'], 'state' => (string) $w['state'],
			'route' => '', 'routeLabel' => '', 'vehicle' => '', 'rate' => $rate, 'rateSource' => '', 'rateDay' => '',
			'webUsd' => (float) $w['saleUsd'], 'webCup' => $web_cup,
			'priceUsd' => (float) $w['saleUsd'], 'priceCup' => $web_cup, 'surchargeUsd' => 0.0, 'surchargeCup' => $has ? 0 : null, 'surchargeNote' => '',
			'costUsd' => (float) $w['costUsd'], 'costCup' => $has ? $web_cup - $margin_cup : null,
			'marginUsd' => $margin, 'marginCup' => $margin_cup,
			'gestoraUsd' => (float) $w['gestoraUsd'], 'gestoraCup' => $gestora_cup,
			'messengerCup' => 0, 'messengerName' => '', 'clerkCup' => 0, 'clerkName' => '', 'storeCup' => 0,
			'otherCup' => $has ? $margin_cup - $gestora_cup - $special_cup : 0, 'otherUsd' => abs( $other ) < 0.005 ? 0.0 : $other,
			'specialCup' => $special_cup, 'specialUsd' => round( (float) $w['specialUsd'], 2 ),
			'closedAt' => null, 'warning' => '', 'payment' => null, 'paidCup' => (float) $w['paidCup'], 'note' => (string) $w['note'],
		);
	}

	/**
	 * 3.14.50: estadísticas de las filas (sin las canceladas). Dinero en pares {cup, usd}: cup = lo que tiene tasa,
	 * usd = lo que no la tiene (fuera de la web sin tasa). mineUsd/avgUsd = tu parte en USD con la tasa de cada venta.
	 * webUsd = cobrado que entra en el reparto = priceUsd (precio web) + surchargeUsd (recargo que es ganancia).
	 * marginPct = margen / cobrado (ponderado). byGestora / bySupplier: ventas y tu parte, de mayor a menor.
	 */
	public static function owner_stats( array $rows ): array {
		$z = array( 'cup' => 0, 'usd' => 0.0 );
		$st = array( 'count' => 0, 'closed' => 0, 'open' => 0, 'webUsd' => 0.0, 'priceUsd' => 0.0, 'surchargeUsd' => 0.0, 'costUsd' => 0.0, 'marginUsd' => 0.0, 'marginPct' => 0.0,
			'margin' => $z, 'gestoras' => $z, 'fund' => $z, 'messengerCup' => 0, 'clerkCup' => 0, 'storeCup' => 0, 'other' => $z,
			'mine' => $z, 'mineClosed' => $z, 'mineOpen' => $z, 'mineUsd' => 0.0, 'avgUsd' => 0.0, 'avgCup' => null, 'byGestora' => array(), 'bySupplier' => array() );
		$put = static function ( array &$acc, ?int $cup, float $usd ): void {
			if ( null === $cup ) { $acc['usd'] = round( $acc['usd'] + $usd, 2 ); } else { $acc['cup'] += $cup; }
		};
		foreach ( $rows as $r ) {
			if ( 'cancelado' === $r['state'] ) { continue; }
			$closed = 'cerrado' === $r['state'];
			$st['count']++;
			$st[ $closed ? 'closed' : 'open' ]++;
			foreach ( array( 'webUsd', 'priceUsd', 'surchargeUsd', 'costUsd', 'marginUsd', 'mineUsd' ) as $k ) { $st[ $k ] = round( $st[ $k ] + (float) $r[ 'mineUsd' === $k ? 'specialUsd' : $k ], 2 ); }
			$put( $st['margin'], $r['marginCup'], (float) $r['marginUsd'] );
			$put( $st['gestoras'], $r['gestoraCup'], (float) $r['gestoraUsd'] );
			$put( $st['other'], null === $r['marginCup'] ? null : (int) $r['otherCup'], (float) $r['otherUsd'] );
			$put( $st['fund'], null === $r['marginCup'] ? null : (int) ( $r['messengerCup'] + $r['clerkCup'] + $r['storeCup'] + $r['otherCup'] ), (float) $r['otherUsd'] );
			$st['messengerCup'] += (int) $r['messengerCup'];
			$st['clerkCup'] += (int) $r['clerkCup'];
			$st['storeCup'] += (int) $r['storeCup'];
			$put( $st['mine'], $r['specialCup'], (float) $r['specialUsd'] );
			$put( $st[ $closed ? 'mineClosed' : 'mineOpen' ], $r['specialCup'], (float) $r['specialUsd'] );
			$groups = array( 'byGestora' => '' !== $r['gestora'] ? $r['gestora'] : 'Sin gestora', 'bySupplier' => '' !== $r['supplier'] ? $r['supplier'] : 'Proveedor sin nombre' );
			foreach ( $groups as $list => $name ) {
				$k = mb_strtolower( $name );
				$st[ $list ][ $k ] = $st[ $list ][ $k ] ?? array( 'name' => $name, 'count' => 0, 'mine' => $z, 'mineUsd' => 0.0 );
				$st[ $list ][ $k ]['count']++;
				$put( $st[ $list ][ $k ]['mine'], $r['specialCup'], (float) $r['specialUsd'] );
				$st[ $list ][ $k ]['mineUsd'] = round( $st[ $list ][ $k ]['mineUsd'] + (float) $r['specialUsd'], 2 );
			}
		}
		$st['marginPct'] = $st['webUsd'] > 0 ? round( $st['marginUsd'] / $st['webUsd'] * 100, 1 ) : 0.0;
		$st['avgUsd'] = $st['count'] ? round( $st['mineUsd'] / $st['count'], 2 ) : 0.0;
		$st['avgCup'] = $st['count'] && 0.0 === (float) $st['mine']['usd'] ? (int) round( $st['mine']['cup'] / $st['count'] ) : null; // solo si todas tienen tasa
		foreach ( array( 'byGestora', 'bySupplier' ) as $list ) {
			uasort( $st[ $list ], static fn( $a, $b ) => $b['mineUsd'] <=> $a['mineUsd'] ?: strcmp( $a['name'], $b['name'] ) );
			$st[ $list ] = array_values( $st[ $list ] );
		}
		return $st;
	}

	/** 3.14.50: el desglose de una venta (dentro del <details> de la lista). HTML escapado. */
	public static function render_detail( array $r ): string {
		$has = null !== $r['marginCup'];
		$usd = static fn( float $v ): string => number_format( $v, 2, ',', '.' );
		$cup = static fn( ?int $v ): string => null === $v ? '—' : number_format( $v, 0, ',', '.' );
		$by_rate = static fn( int $c ): string => $r['rate'] > 0 ? number_format( $c / $r['rate'], 2, ',', '.' ) : '—';
		$who = static fn( string $name, string $none ): string => '<small>' . esc_html( '' !== $name ? $name : $none ) . '</small>';
		$facts = array( 'Fecha' => $r['ts'] ? self::date_text( (int) $r['ts'] ) : '—' );
		if ( $r['offweb'] ) {
			$facts['Venta'] = 'Fuera de la web (sin pedido, apuntada a mano)';
		} else {
			$facts['Pedido'] = $r['num'];
		}
		$facts[ false !== strpos( $r['products'], ', ' ) ? 'Productos' : 'Producto' ] = $r['products'];
		$facts['Gestora'] = '' !== $r['gestora'] ? $r['gestora'] : 'Sin gestora';
		$facts['Proveedor'] = '' !== $r['supplier'] ? $r['supplier'] : 'Sin nombre';
		if ( ! $r['offweb'] ) {
			$facts['Ruta y vehículo'] = 'Ruta ' . $r['route'] . ': ' . mb_strtolower( $r['routeLabel'] ) . ' · ' . ( self::VEHICLES[ $r['vehicle'] ] ?? '' );
		}
		$facts['Tasa'] = $r['rate'] > 0
			? self::num( (float) $r['rate'] ) . ' CUP por 1 USD' . ( $r['offweb'] ? '' : ( 'pedido' === $r['rateSource'] ? ' (la del día del pedido' . ( '' !== $r['rateDay'] ? ', ' . $r['rateDay'] : '' ) . ')' : ' (la de hoy: el pedido no la tenía guardada)' ) )
			: 'Sin tasa: las cifras van en USD';
		$html = '<dl class="cvga-prov-facts">';
		foreach ( $facts as $k => $v ) { $html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>'; }
		$html .= '</dl><table class="cvga-prov-table"><thead><tr><th scope="col">Concepto</th><th scope="col">USD</th><th scope="col">CUP</th></tr></thead><tbody>';
		$row = static fn( string $label, string $u, string $c, string $cls = '' ): string => '<tr' . ( '' !== $cls ? ' class="' . $cls . '"' : '' ) . '><td>' . $label . '</td><td>' . esc_html( $u ) . '</td><td>' . esc_html( $c ) . '</td></tr>';
		$html .= $row( esc_html( $r['offweb'] ? 'Venta' : 'Precio web' ), $usd( (float) $r['priceUsd'] ), $cup( $r['priceCup'] ) );
		if ( $r['surchargeUsd'] > 0 ) { // 3.14.50 (Ernesto 22-oct 14:35): el recargo de la Clásica es ganancia.
			$kind = $r['payment']['kind'] ?? '';
			$name = 'clasica' === $kind ? 'Tarjeta Clásica' : ( '' !== $kind ? (string) $r['payment']['label'] : 'de la forma de pago' );
			$html .= $row( esc_html( '+ Recargo ' . $name ) . '<small>ganancia</small>', $usd( (float) $r['surchargeUsd'] ), $cup( $r['surchargeCup'] ) );
			$html .= $row( esc_html( '= Cobrado al cliente' ), $usd( (float) $r['webUsd'] ), $cup( $r['webCup'] ), 'is-sum' );
		}
		$html .= $row( esc_html( '− Costo al proveedor' ), $usd( (float) $r['costUsd'] ), $cup( $r['costCup'] ) );
		$html .= $row( esc_html( '= Margen' ), $usd( (float) $r['marginUsd'] ), $cup( $r['marginCup'] ), 'is-sum' );
		$html .= $row( esc_html( '− Comisión de la gestora' ) . $who( $r['gestora'], 'sin gestora' ), $usd( (float) $r['gestoraUsd'] ), $cup( $r['gestoraCup'] ) );
		if ( ! $r['offweb'] ) {
			$html .= $row( esc_html( '− Mensajero/transporte' ) . $who( $r['messengerName'], 'C' === $r['route'] ? 'lo lleva el proveedor' : 'sin nombre' ), $by_rate( (int) $r['messengerCup'] ), $cup( (int) $r['messengerCup'] ) );
			$html .= $row( esc_html( '− Dependienta' ) . $who( $r['clerkName'], 'sin nombre' ), $by_rate( (int) $r['clerkCup'] ), $cup( (int) $r['clerkCup'] ) );
			$html .= $row( esc_html( '− Tienda Casa Viva' ), $by_rate( (int) $r['storeCup'] ), $cup( (int) $r['storeCup'] ) );
		} elseif ( 0.0 !== (float) $r['otherUsd'] ) {
			$html .= $row( esc_html( '− Otros gastos' ), $usd( (float) $r['otherUsd'] ), $cup( $has ? (int) $r['otherCup'] : null ) );
		}
		$html .= $row( esc_html( '= Tu comisión' ), $has || $r['offweb'] ? $usd( (float) $r['specialUsd'] ) : '—', $cup( $r['specialCup'] ), 'is-total' );
		$html .= '</tbody></table>';
		if ( ! $r['offweb'] && $has ) { $html .= '<p class="cvga-prov-small">El USD de mensajero, dependienta, tienda y tu comisión es su CUP ÷ la tasa.</p>'; }
		$pay = $r['offweb'] ? ( $r['paidCup'] > 0 ? 'Cobrado al cliente: ' . self::cup_text( (int) round( $r['paidCup'] ) ) : '' ) : self::payment_line( $r['payment'], (float) $r['surchargeUsd'] );
		if ( '' !== $pay ) { $html .= '<p class="cvga-prov-pay">' . esc_html( $pay ) . '</p>'; }
		if ( '' !== $r['surchargeNote'] ) { $html .= '<p class="cvga-prov-small">' . esc_html( $r['surchargeNote'] ) . '</p>'; }
		if ( '' !== $r['warning'] ) { $html .= '<p class="cvga-prov-pay is-warn">' . esc_html( $r['warning'] ) . '</p>'; }
		if ( '' !== $r['note'] ) { $html .= '<p class="cvga-prov-small">Nota: ' . esc_html( $r['note'] ) . '</p>'; }
		if ( 'cancelado' === $r['state'] ) {
			$state = 'Estado: cancelado. No cuenta.';
		} else {
			$closed_ts = $r['closedAt'] ? strtotime( (string) $r['closedAt'] ) : 0;
			$state = 'cerrado' === $r['state']
				? 'Estado: cerrado (entregado y cobrado' . ( $closed_ts ? ' el ' . wp_date( 'j/n/Y', $closed_ts ) : '' ) . '; ya no cambia).'
				: 'Estado: abierto (puede cambiar hasta que se entregue y se cobre).';
			$state .= ' Tu parte: pendiente de pago por la tienda.';
		}
		return $html . '<p class="cvga-prov-state">' . esc_html( $state ) . '</p>';
	}

	/**
	 * Vista de solo lectura en el área de gestora, SOLO para la cuenta del dueño (opción cvd_owner_gestor_user): cada
	 * venta de proveedor del periodo (de cualquier gestora) con su parte (specialCup) y los totales cerrado/abierto.
	 * 3.14.50: estadísticas del periodo arriba y el desglose de cada venta al tocarla (<details>, sin JS).
	 * Periodo por ?cvd_mes=AAAA-MM (el actual y los 5 anteriores) o ?cvd_mes=todo. '' para cualquier otra cuenta.
	 */
	public static function render_owner_sales( WP_User $user ): string {
		$og = self::owner_gestor_user();
		if ( ! $og || $og !== (int) $user->ID ) { return ''; }
		$now = new DateTimeImmutable( 'first day of this month', wp_timezone() );
		$months = array();
		for ( $i = 0; $i < 6; $i++ ) { $m = $now->modify( '-' . $i . ' months' ); $months[ $m->format( 'Y-m' ) ] = ( CVD_Programa_Calc::MONTHS[ (int) $m->format( 'n' ) ] ?? '' ) . ' ' . $m->format( 'Y' ); }
		$months[ self::ALL ] = 'Todo (desde el inicio)';
		$want = isset( $_GET['cvd_mes'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['cvd_mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura.
		$month = isset( $months[ $want ] ) ? $want : $now->format( 'Y-m' );
		$all = self::ALL === $month;
		$rows = self::owner_rows( $month );
		$st = self::owner_stats( $rows );
		$closed = self::money_pair( $st['mineClosed'] ); $open = self::money_pair( $st['mineOpen'] );
		$count = static fn( int $n ): string => $n . ( 1 === $n ? ' venta' : ' ventas' );
		$pct = static fn( float $v ): string => number_format( $v, 1, ',', '.' ) . ' %';
		$margin_sub = '= ' . self::cup_text( (int) $st['margin']['cup'] ) . ( $st['margin']['usd'] > 0 ? ' + ' . self::usd_text( $st['margin']['usd'] ) . ' sin tasa' : '' );
		$fund_sub = 'Mensajeros ' . self::cup_text( $st['messengerCup'] ) . ' · dependientas ' . self::cup_text( $st['clerkCup'] ) . ' · tienda ' . self::cup_text( $st['storeCup'] )
			. ( $st['other']['cup'] || $st['other']['usd'] ? ' · otros ' . self::money_pair( $st['other'] ) : '' );
		$grid = array(
			array( 'Ventas', (string) $st['count'], $st['closed'] . ' cerradas · ' . $st['open'] . ' abiertas' ),
			array( 'Vendido (precio web)', self::usd_text( $st['priceUsd'] ), ( $st['surchargeUsd'] > 0 ? '+ ' . self::usd_text( $st['surchargeUsd'] ) . ' de recargo (ganancia) · ' : '' ) . 'costo al proveedor ' . self::usd_text( $st['costUsd'] ) ),
			array( 'Margen', self::usd_text( $st['marginUsd'] ), $margin_sub ),
			array( 'Margen medio', $pct( (float) $st['marginPct'] ), $st['surchargeUsd'] > 0 ? 'de lo cobrado (precio web + recargo)' : 'del precio web' ),
			array( 'Comisiones de gestoras', self::money_pair( $st['gestoras'] ), '' ),
			array( 'Fondo', self::money_pair( $st['fund'] ), $fund_sub ),
			array( 'Tu comisión', self::money_pair( $st['mine'] ), '≈ ' . self::usd_text( $st['mineUsd'] ) . ' con la tasa de cada venta' ),
			array( 'Tu parte media por venta', self::usd_text( $st['avgUsd'] ), null !== $st['avgCup'] ? '≈ ' . self::cup_text( $st['avgCup'] ) : 'con la tasa de cada venta' ),
		);
		$mini = static function ( array $list ) use ( $count ): string {
			if ( ! $list ) { return '<p class="cvga-empty">Nada todavía.</p>'; }
			$html = '<ul class="cvga-prov-mini">';
			foreach ( $list as $g ) { $html .= '<li><span>' . esc_html( $g['name'] ) . '<small>' . esc_html( $count( (int) $g['count'] ) ) . '</small></span><b>' . esc_html( self::money_pair( $g['mine'] ) ) . '</b></li>'; }
			return $html . '</ul>';
		};
		ob_start();
		?>
		<section class="cvga-view" data-view="proveedor" id="cvga-proveedor">
			<div class="cvga-head"><h1>Mis ventas de proveedor</h1><p>Tu parte de cada venta de un producto de proveedor (Kevin, Sergio, TRD…), la venda quien la venda. Solo lo ves tú.</p></div>
			<form method="get" class="cvga-month-form">
				<label for="cvga-prov-mes">Mes</label>
				<select id="cvga-prov-mes" name="cvd_mes">
					<?php foreach ( $months as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"<?php selected( $key, $month ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<button class="cvga-btn cvga-btn-sm" type="submit">Ver</button>
			</form>
			<div class="cvga-kpis">
				<article class="cvga-card cvga-kpi"><small>Cerrado: la tienda te debe</small><strong><?php echo esc_html( $closed ); ?></strong><span><?php echo esc_html( $count( $st['closed'] ) . ' entregadas y cobradas' ); ?></span></article>
				<article class="cvga-card cvga-kpi"><small>Abierto</small><strong><?php echo esc_html( $open ); ?></strong><span><?php echo esc_html( $count( $st['open'] ) . ': cuando se entreguen' ); ?></span></article>
			</div>
			<section class="cvga-card cvga-prov-stats">
				<div class="cvga-row"><h2><?php echo esc_html( $all ? 'Resumen desde el inicio' : 'Resumen de ' . $months[ $month ] ); ?></h2><span class="cvga-chip"><?php echo esc_html( $count( $st['count'] ) ); ?></span></div>
				<dl class="cvga-prov-grid">
					<?php foreach ( $grid as $g ) : ?><div><dt><?php echo esc_html( $g[0] ); ?></dt><dd><?php echo esc_html( $g[1] ); ?></dd><?php if ( '' !== $g[2] ) : ?><dd class="is-sub"><?php echo esc_html( $g[2] ); ?></dd><?php endif; ?></div><?php endforeach; ?>
				</dl>
				<p class="cvga-note">Margen = comisiones de gestoras + fondo + tu comisión. Las canceladas no cuentan.</p>
				<div class="cvga-prov-split">
					<div><h3>Por gestora</h3><?php echo $mini( $st['byGestora'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?></div>
					<div><h3>Por proveedor</h3><?php echo $mini( $st['bySupplier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?></div>
				</div>
			</section>
			<section class="cvga-card">
				<div class="cvga-row"><h2><?php echo esc_html( $all ? 'Todas las ventas' : 'Ventas de ' . $months[ $month ] ); ?></h2><span class="cvga-chip"><?php echo esc_html( $count( count( $rows ) ) ); ?></span></div>
				<?php if ( ! $rows ) : ?>
				<p class="cvga-empty"><?php echo esc_html( $all ? 'Todavía no hay ventas de proveedor.' : 'Este mes no hay ventas de proveedor.' ); ?></p>
				<?php else : ?>
				<p class="cvga-note">Toca una venta para ver su desglose.</p>
				<ul class="cvga-vales cvga-prov">
					<?php foreach ( $rows as $o ) : $cancel = 'cancelado' === $o['state']; $no_rate = null === $o['specialCup']; ?>
					<li<?php echo $cancel ? ' class="is-cancel"' : ''; ?>><details>
						<summary>
							<span class="cvga-vale-num"><?php echo esc_html( $o['num'] ); ?></span>
							<div class="cvga-vale-text"><b><?php echo esc_html( $o['products'] ); ?></b><small><?php echo esc_html( trim( ( $o['ts'] ? wp_date( 'j/n', $o['ts'] ) . ' · ' : '' ) . ( '' !== $o['gestora'] ? 'Gestora: ' . $o['gestora'] : 'Sin gestora' ) . ' · ' . ( $cancel ? 'Cancelado: no cuenta' : ( 'cerrado' === $o['state'] ? 'Cerrado' : 'Abierto' ) ) . ( $o['offweb'] ? ' · fuera de la web' : '' ) ) ); ?></small></div>
							<div class="cvga-prov-end"><b class="cvga-vale-pts"><?php echo esc_html( $no_rate ? self::usd_text( (float) $o['specialUsd'] ) : self::cup_text( (int) $o['specialCup'] ) ); ?></b><small><?php echo esc_html( $no_rate ? 'en USD (sin tasa)' : '≈ ' . self::usd_text( (float) $o['specialUsd'] ) . ' a ' . number_format( (float) $o['rate'], 0, ',', '.' ) ); ?></small></div>
						</summary>
						<div class="cvga-prov-detail"><?php echo self::render_detail( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?></div>
					</details></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<p class="cvga-note"><b>Cerrado: la tienda te debe <?php echo esc_html( $closed ); ?>.</b> <b>Abierto: <?php echo esc_html( $open ); ?> cuando se entreguen.</b></p>
				<p class="cvga-note">Tu parte = lo que gana la venta (precio web − precio del proveedor) − la comisión de la gestora − mensajero − dependienta − tienda. «Cerrado» = entregado y cobrado; ya no cambia. «Abierto» puede cambiar hasta que se entregue. El USD es con la tasa del día de cada pedido. Si una venta tiene «Recargo Tarjeta Clásica (ganancia)», ese recargo se suma al margen. «Fuera de la web» = venta sin pedido en la web, apuntada a mano (en USD si no tiene tasa). Solo lectura.</p>
			</section>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// ---------- Sus comisiones de proveedor (3.14.49, Ernesto 22-oct) ----------

	/** 3.14.49: la parte del dueño de un reparto (compute o lo sellado), en CUP y en USD con la tasa del reparto. Nunca negativa. */
	public static function owner_share( array $s ): array {
		$cup = max( 0, (int) ( $s['specialCup'] ?? 0 ) );
		$rate = (float) ( $s['rate'] ?? 0 );
		return array( 'cup' => $cup, 'usd' => $rate > 0 ? round( $cup / $rate, 2 ) : 0.0, 'rate' => $rate );
	}

	/** @var array<int, array> 3.14.49: una sola lectura por petición (Inicio y «Tus comisiones» la usan las dos). */
	private static $owner_cache = array();

	/**
	 * 3.14.49 (Ernesto 22-oct): su parte de cada venta de proveedor como SU comisión, solo para la cuenta del dueño
	 * (opción cvd_owner_gestor_user; cualquier otra cuenta = array()). Todas las ventas MK- de la web (la venda quien la
	 * venda) y las de fuera de la web: abiertas → «pending» (Pendientes), cerradas → «ready» (Listas para cobrar: la
	 * tienda se las debe). Canceladas no cuentan. USD = specialCup / tasa del reparto; fuera de la web = specialUsd (en
	 * CUP solo si tiene tasa). «month_usd» = las de este mes (pedido hecho este mes; fuera de la web, por su fecha).
	 * Solo lee: no cambia lo que cobra nadie ni lo que va en «Pedir mi pago».
	 */
	public static function owner_commissions( int $user_id ): array {
		$og = self::owner_gestor_user();
		if ( ! $og || $og !== $user_id ) { return array(); }
		if ( isset( self::$owner_cache[ $og ] ) ) { return self::$owner_cache[ $og ]; }
		$empty = array( 'count' => 0, 'usd' => 0.0, 'cup' => 0, 'no_rate_usd' => 0.0 );
		$out = array( 'pending' => $empty, 'ready' => $empty, 'month_usd' => 0.0, 'month_count' => 0 );
		$month = wp_date( 'Y-m' );
		$add = static function ( string $state, float $usd, ?int $cup, bool $this_month ) use ( &$out ): void {
			$key = 'cerrado' === $state ? 'ready' : ( 'abierto' === $state ? 'pending' : '' );
			if ( '' === $key ) { return; } // cancelado: no cuenta
			$out[ $key ]['count']++;
			$out[ $key ]['usd'] = round( $out[ $key ]['usd'] + $usd, 2 );
			if ( null === $cup ) { $out[ $key ]['no_rate_usd'] = round( $out[ $key ]['no_rate_usd'] + $usd, 2 ); } else { $out[ $key ]['cup'] += $cup; }
			if ( $this_month ) { $out['month_usd'] = round( $out['month_usd'] + $usd, 2 ); $out['month_count']++; }
		};
		foreach ( self::supplier_order_ids() as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || 'shop_order' !== $order->get_type() || ! $order->get_date_created() ) { continue; }
			$s = self::compute( $order );
			if ( ! $s['applies'] ) { continue; }
			$sh = self::owner_share( $s );
			$add( (string) $s['state'], $sh['usd'], $sh['rate'] > 0 ? $sh['cup'] : null, wp_date( 'Y-m', $order->get_date_created()->getTimestamp() ) === $month );
		}
		foreach ( self::offweb_sales() as $w ) {
			$usd = round( max( 0.0, (float) $w['specialUsd'] ), 2 );
			$add( (string) $w['state'], $usd, $w['rate'] > 0 ? (int) round( $usd * $w['rate'] ) : null, 0 === strpos( (string) $w['date'], $month ) );
		}
		return self::$owner_cache[ $og ] = $out;
	}

	// ---------- REST ----------

	private static function order_or_error( $id ) {
		$order = wc_get_order( absint( $id ) );
		if ( ! $order || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'cvd_split_order', 'Pedido no encontrado.', array( 'status' => 404 ) ); }
		return $order;
	}

	private static function no_cache( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function rest_get( WP_REST_Request $request ) {
		$order = self::order_or_error( $request['id'] );
		if ( is_wp_error( $order ) ) { return $order; }
		return self::no_cache( self::compute( $order ) );
	}

	public static function rest_save( WP_REST_Request $request ) {
		$order = self::order_or_error( $request['id'] );
		if ( is_wp_error( $order ) ) { return $order; }
		$p = (array) $request->get_json_params();
		$result = self::save( $order, $p, (string) ( $p['by'] ?? 'panel' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::no_cache( $result );
	}

	public static function rest_month( WP_REST_Request $request ): WP_REST_Response {
		return self::no_cache( self::month( sanitize_text_field( (string) $request->get_param( 'month' ) ) ) );
	}
}
