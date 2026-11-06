<?php

defined( 'ABSPATH' ) || exit;

/**
 * 3.14.117 (Ernesto 3-nov, D129–D130): Triciclub, las tablas. Todo con prefijo «tc» para que no se mezcle con Casa Viva.
 * Plan: ernesto-rondon-nexo/docs/nexo-business/apps/triciclub/replica/architecture.md.
 *
 * - Fechas en UTC (current_time('mysql', true)); la app las enseña en hora de Cuba.
 * - Dinero en CUP enteros (sin centavos) + moneda.
 * - Sin FOREIGN KEY (dbDelta no las maneja): cada relación tiene su índice y la regla de borrado vive en PHP.
 * - Un viaje NO es un pedido de WooCommerce.
 */
final class CVD_Triciclub_Db {
	public const VERSION = 5; // 2 (4-nov): alta — tipo, motor, carga_kg, enviado_at, revisado_at, motivo · 3: verificación — verif, carne_hash · 4 (5-nov, Pa'llá transporte D138–D140): ofertas, cuenta, viajes con precio mixto · 5 (6-nov, Pa'llá v3 D142): servicio comida/carga — adelanto, recibo, peso, ayudante; chofer «puedo adelantar»
	private const VERSION_OPTION = 'cvd_tc_db';

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cvd_tc_' . $name;
	}

	public static function now(): string { return current_time( 'mysql', true ); }

	/** Crea o pone al día las tablas (una vez por versión). */
	public static function install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'clientes' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  telefono varchar(20) NOT NULL,
  nombre varchar(80) NOT NULL DEFAULT '',
  verificado_at datetime NULL,
  consentimiento_at datetime NULL,
  token_epoca int unsigned NOT NULL DEFAULT 1,
  bloqueado tinyint(1) NOT NULL DEFAULT 0,
  nota_media decimal(3,2) NULL,
  viajes_total int unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY telefono (telefono)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'choferes' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'pendiente',
  vehiculo varchar(20) NOT NULL DEFAULT 'triciclo_electrico',
  tipo varchar(16) NOT NULL DEFAULT '',
  motor varchar(10) NOT NULL DEFAULT '',
  carga_kg smallint unsigned NOT NULL DEFAULT 0,
  enviado_at datetime NULL,
  revisado_at datetime NULL,
  motivo varchar(200) NOT NULL DEFAULT '',
  verif longtext NULL,
  carne_hash varchar(64) NOT NULL DEFAULT '',
  numero varchar(12) NOT NULL DEFAULT '',
  chapa varchar(16) NOT NULL DEFAULT '',
  color varchar(20) NOT NULL DEFAULT '',
  descripcion varchar(80) NOT NULL DEFAULT '',
  plazas tinyint unsigned NOT NULL DEFAULT 0,
  servicios varchar(40) NOT NULL DEFAULT 'viaje,paquete,carga',
  chapa_o_chasis varchar(40) NOT NULL DEFAULT '',
  lot_numero varchar(40) NOT NULL DEFAULT '',
  lot_vence date NULL,
  licencia_cat varchar(8) NOT NULL DEFAULT '',
  fotos longtext NULL,
  transferencia varchar(40) NOT NULL DEFAULT '',
  precio_km int unsigned NOT NULL DEFAULT 300,
  km_tramo decimal(5,2) NOT NULL DEFAULT 5.00,
  precio_km_despues int unsigned NOT NULL DEFAULT 150,
  extras varchar(400) NOT NULL DEFAULT '',
  disponible tinyint(1) NOT NULL DEFAULT 0,
  adelanta tinyint(1) NOT NULL DEFAULT 0,
  lat decimal(9,6) NULL,
  lng decimal(9,6) NULL,
  ubicado_at datetime NULL,
  nota_media decimal(3,2) NULL,
  viajes_total int unsigned NOT NULL DEFAULT 0,
  aprobado_por bigint(20) unsigned NULL,
  aprobado_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  KEY disponibles (estado,disponible,ubicado_at),
  KEY lot_vence (lot_vence),
  KEY carne_hash (carne_hash)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'viajes' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  cliente_id bigint(20) unsigned NOT NULL,
  cid varchar(40) NOT NULL,
  chofer_id bigint(20) unsigned NOT NULL,
  servicio varchar(10) NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'esperando',
  origen_texto varchar(160) NOT NULL DEFAULT '',
  origen_lat decimal(9,6) NOT NULL,
  origen_lng decimal(9,6) NOT NULL,
  destino_texto varchar(160) NOT NULL DEFAULT '',
  destino_lat decimal(9,6) NOT NULL,
  destino_lng decimal(9,6) NOT NULL,
  distancia_m int unsigned NOT NULL,
  minutos smallint unsigned NOT NULL DEFAULT 0,
  precio int unsigned NOT NULL,
  moneda char(3) NOT NULL DEFAULT 'CUP',
  franja varchar(16) NOT NULL DEFAULT '',
  extra_pct smallint unsigned NOT NULL DEFAULT 0,
  forma_pago varchar(14) NOT NULL DEFAULT 'efectivo',
  cobrado_at datetime NULL,
  codigo char(4) NOT NULL,
  nota varchar(200) NOT NULL DEFAULT '',
  recibe_nombre varchar(80) NOT NULL DEFAULT '',
  recibe_telefono varchar(20) NOT NULL DEFAULT '',
  clase varchar(16) NOT NULL DEFAULT '',
  precio_justo int unsigned NOT NULL DEFAULT 0,
  precio_oferta int unsigned NOT NULL DEFAULT 0,
  primera tinyint(1) NOT NULL DEFAULT 0,
  noche tinyint(1) NOT NULL DEFAULT 0,
  espera_cup int unsigned NOT NULL DEFAULT 0,
  comision_pct tinyint unsigned NOT NULL DEFAULT 0,
  comision_cup int unsigned NOT NULL DEFAULT 0,
  oferta_id bigint(20) unsigned NOT NULL DEFAULT 0,
  canal varchar(48) NOT NULL DEFAULT '',
  rev int unsigned NOT NULL DEFAULT 0,
  busca_desde datetime NULL,
  envio_que varchar(120) NOT NULL DEFAULT '',
  envio_tam varchar(10) NOT NULL DEFAULT '',
  cobrar_cup int unsigned NOT NULL DEFAULT 0,
  entrega_codigo char(4) NOT NULL DEFAULT '',
  entrega_foto varchar(160) NOT NULL DEFAULT '',
  recoge_texto varchar(120) NOT NULL DEFAULT '',
  adelanto_cup int unsigned NOT NULL DEFAULT 0,
  recibo_foto varchar(160) NOT NULL DEFAULT '',
  peso varchar(8) NOT NULL DEFAULT '',
  extra_cup int unsigned NOT NULL DEFAULT 0,
  respondido_at datetime NULL,
  llego_at datetime NULL,
  recogido_at datetime NULL,
  terminado_at datetime NULL,
  cancelado_at datetime NULL,
  cancelado_por varchar(8) NULL,
  motivo varchar(200) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY cliente_cid (cliente_id,cid),
  KEY estado_fecha (estado,created_at),
  KEY chofer_estado (chofer_id,estado),
  KEY cliente_fecha (cliente_id,created_at),
  KEY buscando (estado,clase,created_at)
) {$c};" );
		// 4 (5-nov, Pa'llá transporte): cada «Acepto» o «+10/+20/+30 %» de un chofer a un pedido.
		dbDelta( 'CREATE TABLE ' . self::t( 'ofertas' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  viaje_id bigint(20) unsigned NOT NULL,
  chofer_id bigint(20) unsigned NOT NULL,
  precio int unsigned NOT NULL,
  pct tinyint unsigned NOT NULL DEFAULT 0,
  llega_min smallint unsigned NOT NULL DEFAULT 0,
  lejos_m int unsigned NOT NULL DEFAULT 0,
  estado varchar(10) NOT NULL DEFAULT 'activa',
  vence_at datetime NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY viaje_chofer (viaje_id,chofer_id),
  KEY chofer_estado (chofer_id,estado)
) {$c};" );
		// 4: lo que debe cada chofer (comisión de cada viaje, en +) y lo que paga (en −). Saldo = SUM(cup) sin los rechazados.
		dbDelta( 'CREATE TABLE ' . self::t( 'cuenta' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  chofer_id bigint(20) unsigned NOT NULL,
  viaje_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tipo varchar(10) NOT NULL,
  cup int NOT NULL,
  ref varchar(60) NOT NULL DEFAULT '',
  estado varchar(10) NOT NULL DEFAULT 'ok',
  por bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY viaje (viaje_id),
  KEY chofer_fecha (chofer_id,created_at),
  KEY estado (estado)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'ubicaciones' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  viaje_id bigint(20) unsigned NOT NULL,
  chofer_id bigint(20) unsigned NOT NULL,
  lat decimal(9,6) NOT NULL,
  lng decimal(9,6) NOT NULL,
  precision_m smallint unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY viaje (viaje_id,id),
  KEY fecha (created_at)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'valoraciones' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  viaje_id bigint(20) unsigned NOT NULL,
  de varchar(8) NOT NULL,
  estrellas tinyint unsigned NOT NULL,
  etiquetas varchar(200) NOT NULL DEFAULT '',
  texto varchar(300) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY viaje_de (viaje_id,de)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'eventos' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  viaje_id bigint(20) unsigned NOT NULL,
  actor varchar(10) NOT NULL,
  actor_id bigint(20) unsigned NULL,
  tipo varchar(24) NOT NULL,
  datos longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY viaje (viaje_id,id),
  KEY tipo_fecha (tipo,created_at)
) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'codigos' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  telefono varchar(20) NOT NULL,
  codigo char(6) NOT NULL DEFAULT '',
  codigo_hash char(64) NOT NULL,
  intentos tinyint unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  enviado_at datetime NULL,
  usado_at datetime NULL,
  canal varchar(10) NOT NULL DEFAULT 'whatsapp',
  app varchar(6) NOT NULL DEFAULT 'tc',
  vence_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY telefono_fecha (telefono,created_at),
  KEY pendientes (enviado_at,vence_at),
  KEY ip_fecha (ip,created_at)
) {$c};" );
		if ( ! get_role( CVD_Triciclub_Alta::ROL ) ) { add_role( CVD_Triciclub_Alta::ROL, 'Chofer de Triciclub', array( 'read' => true ) ); }
		update_option( self::VERSION_OPTION, self::VERSION, true ); // autoload: se mira en cada carga, sin consulta aparte
	}

	public static function evento( int $viaje_id, string $actor, int $actor_id, string $tipo, array $datos = array() ): void {
		global $wpdb;
		$wpdb->insert( self::t( 'eventos' ), array(
			'viaje_id'   => $viaje_id,
			'actor'      => $actor,
			'actor_id'   => $actor_id ?: null,
			'tipo'       => $tipo,
			'datos'      => $datos ? wp_json_encode( $datos ) : null,
			'created_at' => self::now(),
		) );
	}

	/** Tarea diaria: ubicaciones de más de 30 días y códigos viejos fuera; LOT vencida → chofer suspendido. */
	public static function limpiar(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'ubicaciones' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'codigos' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$hoy = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Havana' ) ) )->format( 'Y-m-d' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'choferes' ) . " SET estado='suspendido', disponible=0, updated_at=%s WHERE estado='aprobado' AND lot_vence IS NOT NULL AND lot_vence < %s", self::now(), $hoy ) );
	}
}
