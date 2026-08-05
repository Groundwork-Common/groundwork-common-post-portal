<?php
/**
 * Assets.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── Register early, enqueue late, twice ─────────────────────────────────────
 * Registration happens on wp_enqueue_scripts at priority 5 so a theme can
 * declare our handle as a dependency, or deregister it, from the default
 * priority.
 *
 * Enqueueing happens twice and neither is redundant. The gate here catches the
 * ordinary case — the portal page, known from the settings — and runs early
 * enough for the stylesheet to be in <head>. The second call, from inside
 * gwcpp_render_portal(), catches the cases this one cannot see: the shortcode
 * inside a widget, a template part, a reusable block, another block's inner
 * content. That one runs during the body and produces a late <link>, which is
 * a flash of unstyled form rather than a broken page.
 *
 * wp_enqueue_style() is idempotent, so calling it twice costs a hash lookup.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action( 'wp_enqueue_scripts', 'gwcpp_register_front_assets', 5 );
add_action( 'wp_enqueue_scripts', 'gwcpp_maybe_enqueue_portal', 10 );
add_action( 'admin_enqueue_scripts', 'gwcpp_admin_assets' );

/**
 * Register the front-end stylesheet.
 */
function gwcpp_register_front_assets(): void {
	wp_register_style(
		'gwcpp-portal',
		GWCPP_URL . 'assets/css/portal.css',
		array(),
		GWCPP_VERSION
	);

	/* Deferred, and with no dependencies. It attaches on DOMContentLoaded and
	 * touches nothing outside its own repeater, so there is no reason for it to
	 * block rendering — and a portal user on a bad connection should see the
	 * form before they can add a row to it. */
	wp_register_script(
		'gwcpp-repeater',
		GWCPP_URL . 'assets/js/repeater.js',
		array(),
		GWCPP_VERSION,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}

/**
 * Enqueue on the portal page.
 */
function gwcpp_maybe_enqueue_portal(): void {
	if ( gwcpp_is_portal() ) {
		gwcpp_enqueue_portal_assets();
	}
}

/**
 * Enqueue the portal's assets.
 *
 * Safe to call more than once, and called from the renderer as well as from the
 * hook above.
 */
function gwcpp_enqueue_portal_assets(): void {
	/**
	 * Whether to load the portal's own stylesheet.
	 *
	 * Set false on a site whose theme styles the portal itself. The markup's
	 * class names are stable and are the documented styling surface.
	 *
	 * @param bool $load Whether to load it.
	 */
	if ( ! apply_filters( 'gwcpp_load_assets', true ) ) {
		return;
	}

	wp_enqueue_style( 'gwcpp-portal' );
	wp_enqueue_script( 'gwcpp-repeater' );

	$css = gwcpp_appearance_css();
	if ( '' !== $css ) {
		wp_add_inline_style( 'gwcpp-portal', $css );
	}
}

/**
 * The appearance settings, as custom property overrides.
 *
 * Only properties that were actually set are emitted. An override written for
 * every setting, most of them empty, would beat the stylesheet's own defaults
 * with nothing and leave the portal unstyled.
 *
 * @return string
 */
function gwcpp_appearance_css(): string {
	$map = array(
		'accent_color'  => '--gwcpp-accent',
		'portal_bg'     => '--gwcpp-bg',
		'portal_text'   => '--gwcpp-text',
		'surface_color' => '--gwcpp-surface',
		'line_color'    => '--gwcpp-line',
		'radius'        => '--gwcpp-radius',
		'max_width'     => '--gwcpp-max-width',
	);

	$rules = array();
	foreach ( $map as $setting => $property ) {
		$value = trim( (string) gwcpp_setting( $setting ) );
		if ( '' === $value ) {
			continue;
		}

		/* Anything with a brace, a semicolon or a comment marker is refused
		 * rather than escaped. These arrive from a settings screen only an
		 * administrator can reach, so this is not the last line of defence —
		 * but a value that closes the rule it sits in can write arbitrary CSS
		 * onto every portal page, and there is no legitimate colour or length
		 * that needs any of those characters. */
		if ( preg_match( '/[{};<>]|\/\*/', $value ) ) {
			continue;
		}

		$rules[] = sprintf( '%s:%s;', $property, $value );
	}

	return $rules ? '.gwcpp{' . implode( '', $rules ) . '}' : '';
}

/**
 * Admin styles, on this plugin's screens only.
 *
 * @param string $hook Current screen's hook suffix.
 */
function gwcpp_admin_assets( $hook ): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	$ours = ( is_string( $hook ) && false !== strpos( $hook, GWCPP_MENU_SLUG ) )
		|| ( $screen && GWCPP_ORG_TYPE === $screen->post_type )
		|| ( $screen && 'post' === $screen->base && gwcpp_type_enabled( (string) $screen->post_type ) );

	if ( ! $ours ) {
		return;
	}

	wp_enqueue_style(
		'gwcpp-admin',
		GWCPP_URL . 'assets/css/admin.css',
		array(),
		GWCPP_VERSION
	);
}
