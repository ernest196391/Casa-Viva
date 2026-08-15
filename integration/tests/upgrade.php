<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
global $wpdb; $table = $wpdb->prefix . 'cvd_order_events';
update_option( 'cvt_upgrade_marker', 'preserve-me' );
if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { throw new RuntimeException( 'La instalación limpia no creó la tabla.' ); }
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
update_option( 'cvd_version', '3.5.0' );
CVD_Plugin::maybe_upgrade();
if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { throw new RuntimeException( 'maybe_upgrade no creó la tabla.' ); }
if ( 'preserve-me' !== get_option( 'cvt_upgrade_marker' ) ) { throw new RuntimeException( 'El upgrade eliminó datos existentes.' ); }
CVD_Order_Events::record( array( 'order_id'=>999999, 'event_type'=>'order.upgrade_probe', 'domain'=>'order', 'source'=>'integration', 'idempotency_key'=>'upgrade-probe' ) );
CVD_Plugin::activate(); CVD_Plugin::activate();
if ( 1 !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE order_id=999999" ) ) { throw new RuntimeException( 'dbDelta repetido alteró eventos existentes.' ); }
$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
$names = array_count_values( array_column( $indexes, 'Key_name' ) );
foreach ( array( 'PRIMARY','event_id','idempotency_key','order_timeline' ) as $name ) { if ( empty( $names[$name] ) ) { throw new RuntimeException( 'Falta índice: ' . $name ); } }
$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
$actual_columns = array_column( $columns, 'Type', 'Field' );
foreach ( array( 'id'=>'bigint(20) unsigned', 'event_id'=>'varchar(71)', 'idempotency_key'=>'char(64)', 'order_id'=>'bigint(20) unsigned', 'metadata'=>'longtext' ) as $field=>$type ) {
	if ( ! isset( $actual_columns[$field] ) || strtolower($actual_columns[$field]) !== $type ) { throw new RuntimeException( "Columna inesperada {$field}." ); }
}
echo "OK: upgrade, conservación y dbDelta repetido.\n";
