<?php
/**
 * Plantilla del área de gestoras como app: sin cabecera ni pie de la tienda.
 * La elige CVD_Gestora_App::template() solo para gestoras aprobadas con la app activada.
 */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php echo CVD_Gestora_App::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro. ?>
<?php wp_footer(); ?>
</body>
</html>
