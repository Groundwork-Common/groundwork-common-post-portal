<?php
/**
 * Dependencies for edit.js.
 *
 * Hand-written, because there is no build step to generate it — see README.md.
 * Every global edit.js reaches for is listed here; adding one to the script
 * without adding it here produces an "undefined is not an object" in the editor
 * that looks like a WordPress bug.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => defined( 'GWC_PP_VERSION' ) ? GWC_PP_VERSION : '0.1.0',
);
