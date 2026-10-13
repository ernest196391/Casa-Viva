<?php
/**
 * Portada que vende (3.13.83). La elige CVD_Home_Rails::template() solo en la portada pública.
 * Cabecera, pie, barra de abajo, Cúrru y el botón de WhatsApp son los de siempre (tema y wp_footer).
 */
defined( 'ABSPATH' ) || exit;

get_header();
CVD_Home_Rails::render();
get_footer();
