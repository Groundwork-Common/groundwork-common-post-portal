<?php
/**
 * Server render for the portal block.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * The wrapper attributes carry the alignment and any block supports the editor
 * set. get_block_wrapper_attributes() escapes what it returns.
 */
printf(
	'<div %s>%s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core.
	gwcpp_render_portal() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value inside is escaped at the point it is printed.
);
