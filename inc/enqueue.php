<?php
/**
 * Assets.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── Register early, enqueue late, twice ─────────────────────────────────────
 * Registration happens on wp_enqueue_scripts at priority 5 so a theme can
 * declare our handle as a dependency, or deregister it, from the default
 * priority.
 *
 * Enqueueing happens twice and neither is redundant. The gate here catches the
 * ordinary case — the portal page, known from the settings — and runs early
 * enough for the stylesheet to be in <head>. The second call, from inside
 * gwc_pp_render_portal(), catches the cases this one cannot see: the shortcode
 * inside a widget, a template part, a reusable block, another block's inner
 * content. That one runs during the body and produces a late <link>, which is
 * a flash of unstyled form rather than a broken page.
 *
 * wp_enqueue_style() is idempotent, so calling it twice costs a hash lookup.
 *
 * ── And why registration is on enqueue_block_assets ──────────────────────────
 * blocks/portal/block.json names `gwcpp-portal` as the block's `style`, which
 * means core calls wp_enqueue_style( 'gwc-pp-portal' ) in the editor as well as
 * on the front end. Registration used to be on wp_enqueue_scripts, which does
 * not fire in wp-admin — so in the editor core was enqueueing a handle nobody
 * had registered. That is a silent no-op, and the symptom was the block preview
 * rendering unstyled with nothing in the console to explain it.
 *
 * enqueue_block_assets fires in both contexts, which is exactly the set of
 * places a block's style has to exist. The priority-5 registration still runs
 * before the priority-10 enqueue below.
 * ───────────────────────────────────────────────────────────────────────────
 */

add_action( 'enqueue_block_assets', 'gwc_pp_register_front_assets', 5 );
add_action( 'wp_enqueue_scripts', 'gwc_pp_register_front_assets', 5 );
add_action( 'wp_enqueue_scripts', 'gwc_pp_maybe_enqueue_portal', 10 );
add_action( 'admin_enqueue_scripts', 'gwc_pp_admin_assets' );

/**
 * Register the front-end stylesheet.
 *
 * Runs on both wp_enqueue_scripts and enqueue_block_assets, so it has to be
 * safe to call twice. wp_register_style() and wp_register_script() both return
 * false and change nothing when the handle already exists, so it is.
 */
function gwc_pp_register_front_assets(): void {
	wp_register_style(
		'gwc-pp-portal',
		GWC_PP_URL . 'assets/css/portal.css',
		array(),
		GWC_PP_VERSION
	);

	/*
	 * Deferred, and with no dependencies. It attaches on DOMContentLoaded and
	 * touches nothing outside its own repeater, so there is no reason for it to
	 * block rendering — and a portal user on a bad connection should see the
	 * form before they can add a row to it.
	 */
	wp_register_script(
		'gwc-pp-repeater',
		GWC_PP_URL . 'assets/js/repeater.js',
		array(),
		GWC_PP_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}

/**
 * Enqueue on the portal page.
 */
function gwc_pp_maybe_enqueue_portal(): void {
	if ( gwc_pp_is_portal() ) {
		gwc_pp_enqueue_portal_assets();
	}
}

/**
 * Enqueue the portal's assets.
 *
 * Safe to call more than once, and called from the renderer as well as from the
 * hook above.
 */
function gwc_pp_enqueue_portal_assets(): void {
	/**
	 * Whether to load the portal's own stylesheet.
	 *
	 * Set false on a site whose theme styles the portal itself. The markup's
	 * class names are stable and are the documented styling surface.
	 *
	 * @param bool $load Whether to load it.
	 */
	if ( ! apply_filters( 'gwc_pp_load_assets', true ) ) {
		return;
	}

	wp_enqueue_style( 'gwc-pp-portal' );
	wp_enqueue_script( 'gwc-pp-repeater' );

	$css = gwc_pp_appearance_css();
	if ( '' !== $css ) {
		wp_add_inline_style( 'gwc-pp-portal', $css );
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
function gwc_pp_appearance_css(): string {
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
		$value = trim( (string) gwc_pp_setting( $setting ) );
		if ( '' === $value ) {
			continue;
		}

		/*
		 * Anything with a brace, a semicolon or a comment marker is refused
		 * rather than escaped. These arrive from a settings screen only an
		 * administrator can reach, so this is not the last line of defence —
		 * but a value that closes the rule it sits in can write arbitrary CSS
		 * onto every portal page, and there is no legitimate colour or length
		 * that needs any of those characters.
		 */
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
function gwc_pp_admin_assets( $hook ): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	/*
	 * Matched on our slug PREFIX, not on the settings slug. WordPress builds a
	 * submenu's hook as "{parent menu title}_page_{slug}", so the only screen
	 * whose hook ever contained GWC_PP_MENU_SLUG was the one whose slug that is —
	 * Pending Changes came through as "portal_page_gwcpp-pending" and matched
	 * nothing, which is why its diff tables rendered unstyled.
	 */
	$ours = ( is_string( $hook ) && false !== strpos( $hook, 'gwc-pp-' ) )
		|| ( $screen && GWC_PP_ORG_TYPE === $screen->post_type )
		|| ( $screen && 'post' === $screen->base && gwc_pp_type_enabled( (string) $screen->post_type ) );

	if ( ! $ours ) {
		return;
	}

	wp_enqueue_style(
		'gwc-pp-admin',
		GWC_PP_URL . 'assets/css/admin.css',
		array(),
		GWC_PP_VERSION
	);
}
