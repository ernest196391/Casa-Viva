<?php
/** Pa'llá Reparto (D157): las pruebas de Pa'llá transporte también pasan con Reparto ENCENDIDO (sin encargos, nada cambia). */
update_option( 'cvd_pv_reparto', 'si' );
require __DIR__ . '/pv-flujo.php';
delete_option( 'cvd_pv_reparto' );
