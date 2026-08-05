<?php
/**
 * The block, and the shortcode.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwcpp_register_block' );

/* The shortcode is not a deprecation path and is not going away. A block is
 * the right default and is unreachable from a widget, a page builder, a theme
 * template, or a site still on the classic editor — all of which exist on the
 * kind of site this plugin is for. */
add_shortcode( 'post_portal', 'gwcpp_shortcode' );

/**
 * Register the block.
 */
function gwcpp_register_block(): void {
	if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
		return;
	}

	$type = register_block_type_from_metadata( GWCPP_DIR . 'blocks/portal' );

	if ( ! $type instanceof WP_Block_Type ) {
		return;
	}

	/* The editor script's handle is generated from the block name and the
	 * field it came from, and the exact string has changed between WordPress
	 * versions. Reading it back off the registered type is the only way to
	 * attach anything to it that does not break on an upgrade. */
	$handles = (array) ( $type->editor_script_handles ?? array() );
	if ( ! $handles ) {
		return;
	}

	$handle = (string) $handles[0];

	wp_set_script_translations( $handle, 'groundwork-common-post-portal', GWCPP_DIR . 'languages' );

	/* Two booleans, because they are the two ways this block can be placed and
	 * do nothing at all. Telling somebody in the editor is worth far more than
	 * telling them on the front end, where the symptom is an empty page and the
	 * cause is on a settings screen they have not opened. */
	wp_add_inline_script(
		$handle,
		'window.GWCPP_EDITOR = ' . wp_json_encode(
			array(
				'hasPortalPage' => gwcpp_portal_page_id() > 0,
				'hasPostTypes'  => (bool) gwcpp_post_types(),
			)
		) . ';',
		'before'
	);
}

/**
 * Render the shortcode.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function gwcpp_shortcode( $atts = array() ): string {
	unset( $atts );

	return gwcpp_render_portal();
}
