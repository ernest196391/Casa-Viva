<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pa'llá transporte (5-nov-2026, D138–D140): carga todo de una vez. El archivo principal del plugin solo necesita UNA
 * línea (require de este archivo), así subirlo a la web —cuyo archivo principal lleva cambios propios— es fácil y seguro.
 * Necesita las clases de Triciclub y de Pa'llá (se cargan antes en el archivo principal).
 */
require_once __DIR__ . '/class-cvd-pv-tarifa.php';
require_once __DIR__ . '/class-cvd-pv-canal.php';
require_once __DIR__ . '/class-cvd-pv-cuenta.php';
require_once __DIR__ . '/class-cvd-pv.php';
require_once __DIR__ . '/class-cvd-pv-api.php';
require_once __DIR__ . '/class-cvd-pv-panel.php';

CVD_Pv::register();
CVD_Pv_Api::register();
CVD_Pv_Panel::register();
