<?php
/**
 * Prueba de CVD_Cadena_Mensajeros::business_seconds / in_confirmation_hours (3.14.169) con PHP de línea de comandos.
 * No carga WordPress ni toca la base: solo define la clase. Uso: php scripts/ops/tests/mensajeria-horario-php.php [ruta-del-plugin]
 * Sale 0 si todo pasa, 1 si algo falla.
 */
define( 'ABSPATH', __DIR__ . '/' );
$plugin = $argv[1] ?? __DIR__ . '/../../../wordpress/casa-viva-dropship-core';
require $plugin . '/includes/class-cvd-cadena-mensajeros.php';

$tz = new DateTimeZone( 'America/Havana' );
$t = static fn( string $local ): int => ( new DateTime( $local, $tz ) )->getTimestamp();
$fails = 0;
$check = static function ( string $name, $got, $want ) use ( &$fails ) {
	$ok = $got === $want;
	if ( ! $ok ) { $fails++; }
	echo ( $ok ? 'ok   ' : 'FALLA' ) . " $name" . ( $ok ? '' : " (dio " . var_export( $got, true ) . ", esperaba " . var_export( $want, true ) . ')' ) . "\n";
};
$min = static fn( int $s ): int => intdiv( $s, 60 );
$bs = array( 'CVD_Cadena_Mensajeros', 'business_seconds' );
$ih = array( 'CVD_Cadena_Mensajeros', 'in_confirmation_hours' );

// Límites exactos del horario.
$check( '08:59:59 fuera de horario', $ih( $t( '2026-11-16 08:59:59' ) ), false );
$check( '09:00:00 dentro de horario', $ih( $t( '2026-11-16 09:00:00' ) ), true );
$check( '16:59:59 dentro de horario', $ih( $t( '2026-11-16 16:59:59' ) ), true );
$check( '17:00:00 fuera de horario', $ih( $t( '2026-11-16 17:00:00' ) ), false );
// Mismo día.
$check( '10:00→10:20 = 20 min', $min( $bs( $t( '2026-11-16 10:00' ), $t( '2026-11-16 10:20' ) ) ), 20 );
$check( '10:00→10:19 = 19 min (aún no avisa)', $min( $bs( $t( '2026-11-16 10:00' ), $t( '2026-11-16 10:19' ) ) ), 19 );
// 16:55 → al día siguiente: 5 min hoy + 15 min desde las 09:00.
$check( '16:55→17:30 = 5 min (se detiene a las 17:00)', $min( $bs( $t( '2026-11-16 16:55' ), $t( '2026-11-16 17:30' ) ) ), 5 );
$check( '16:55→09:14 día siguiente = 19 min', $min( $bs( $t( '2026-11-16 16:55' ), $t( '2026-11-17 09:14' ) ) ), 19 );
$check( '16:55→09:15 día siguiente = 20 min', $min( $bs( $t( '2026-11-16 16:55' ), $t( '2026-11-17 09:15' ) ) ), 20 );
// Asignación nocturna: empieza a contar a las 09:00.
$check( '21:30→08:59 = 0 min', $min( $bs( $t( '2026-11-16 21:30' ), $t( '2026-11-17 08:59' ) ) ), 0 );
$check( '21:30→09:20 = 20 min', $min( $bs( $t( '2026-11-16 21:30' ), $t( '2026-11-17 09:20' ) ) ), 20 );
$check( '06:00→09:20 mismo día = 20 min', $min( $bs( $t( '2026-11-16 06:00' ), $t( '2026-11-16 09:20' ) ) ), 20 );
// Varios días (viernes 16:50 → lunes 09:10: cuenta sábado y domingo completos, 8 h cada uno).
$check( 'vie 16:50→lun 09:10 = 10+480+480+10 min', $min( $bs( $t( '2026-11-13 16:50' ), $t( '2026-11-16 09:10' ) ) ), 980 );
// Cambio de horario: Cuba vuelve al horario normal el 1-nov-2026 (01:00 → 00:00) y adelanta el 8-mar-2026 (00:00 → 01:00).
$check( 'otoño 31-oct 16:50→1-nov 09:10 = 20 min', $min( $bs( $t( '2026-10-31 16:50' ), $t( '2026-11-01 09:10' ) ) ), 20 );
$check( 'primavera 7-mar 16:50→8-mar 09:10 = 20 min', $min( $bs( $t( '2026-03-07 16:50' ), $t( '2026-03-08 09:10' ) ) ), 20 );
$check( 'día del cambio completo = 480 min', $min( $bs( $t( '2026-03-08 00:00' ) - 1, $t( '2026-03-09 00:00' ) ) ), 480 );
// Orden invertido o igual.
$check( 'to <= from = 0', $bs( 100, 50 ), 0 );

echo $fails ? "\n$fails pruebas fallaron\n" : "\nTodas pasaron\n";
exit( $fails ? 1 : 0 );
