<?php
/**
 * Full-document canvas for a Themer body template takeover.
 *
 * Expects $emcp_themer_slots = array( 'header'=>?id, 'body'=>?id, 'footer'=>?id )
 * to be set by the render controller before including this file.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slots = isset( $emcp_themer_slots ) && is_array( $emcp_themer_slots ) ? $emcp_themer_slots : array();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'emcp-themer-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( ! empty( $slots['header'] ) ) {
	echo EMCP_Tools_Themer_Content_Renderer::render( (int) $slots['header'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( ! empty( $slots['body'] ) ) {
	echo '<main class="emcp-themer-body">';
	echo EMCP_Tools_Themer_Content_Renderer::render( (int) $slots['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</main>';
}
if ( ! empty( $slots['footer'] ) ) {
	echo EMCP_Tools_Themer_Content_Renderer::render( (int) $slots['footer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_footer();
?>
</body>
</html>
