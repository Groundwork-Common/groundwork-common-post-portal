<?php
/**
 * Plugin settings: defaults, the single accessor, and per-post-type overrides.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

const GWCPP_SETTINGS_OPTION = 'gwcpp_settings';

/**
 * Every setting, with the value a fresh install behaves as.
 *
 * Several default to '' or 0 meaning "derive it" rather than to a concrete
 * value. That is deliberate: the site's admin email and its name are already
 * recorded in WordPress, and copying them into our option at activation would
 * fork them the first time somebody changed the original.
 *
 * @return array
 */
function gwcpp_setting_defaults(): array {
	return array(
		/* Which post types the portal covers. Empty is the honest default: a
		 * plugin that switched itself on for `post` at activation would expose
		 * every article on the site to whoever it later granted access to, and
		 * it would do it before anyone had opened a settings screen. */
		'post_types'        => array(),

		/* The page holding the portal block. Resolved to an ID once and pinned,
		 * so renaming the page or changing its slug cannot quietly break every
		 * sign-in link in every inbox. */
		'portal_page'       => 0,

		// Sign-in.
		'signin_magic'      => true,
		'signin_password'   => false,
		/* Short, because the archetypal portal user is on a shared front-desk
		 * computer at an organisation with one login between six people. */
		'session_hours'     => 3,

		/* Per-post-type flags, keyed by post type slug. Read through
		 * gwcpp_type_setting() rather than directly — a type that has never
		 * been configured has no row here at all, and every caller wanting the
		 * defaults for that case is a caller that can get them wrong. */
		'types'             => array(),

		// Email. Empty means "use what WordPress would have used anyway".
		'from_name'         => '',
		'from_email'        => '',
		'staff_email'       => '',

		/* Appearance. Empty means "inherit the theme-matched default already in
		 * portal.css" — nothing here is required for the portal to look
		 * reasonable, and a site that never opens the Appearance tab behaves
		 * exactly as if these did not exist. */
		'accent_color'      => '',
		'portal_bg'         => '',
		'portal_text'       => '',
		'surface_color'     => '',
		'line_color'        => '',
		'radius'            => '',
		'max_width'         => '',
	);
}

/**
 * The per-post-type flags, and what a type that was never configured does.
 *
 * The three that matter are all conservative, and all conservative in the same
 * direction: a post type switched on and then forgotten about grants the least
 * it can rather than the most.
 *
 * @return array
 */
function gwcpp_type_setting_defaults(): array {
	return array(
		/* Off. `post_author` on an imported or staff-authored post is whoever
		 * ran the import, and on a site that has ever used a "submit a listing"
		 * form it is whoever submitted it years ago. Turning this on is a
		 * decision about a specific post type whose authorship you know the
		 * shape of; making it the default would silently grant access based on
		 * a column most sites have never looked at. */
		'author_grant'     => false,

		/* Off. Editing what you were given is a much smaller promise than
		 * creating new records in somebody's directory. */
		'allow_create'     => false,

		/* On. The entire reason the changeset machinery exists is that the
		 * default answer to "should this go straight to the public site"
		 * is no. A site that trusts its partners turns this off deliberately. */
		'require_approval' => true,

		/* On, and note this is the *weakest* destructive action available —
		 * unpublish to draft, reversible in one click. There is no setting
		 * anywhere that grants a portal user deletion. */
		'allow_unpublish'  => true,

		/* What a portal-created post starts as. Never 'publish': a create flow
		 * that publishes on submit makes require_approval a lie for exactly the
		 * content nobody has ever reviewed. */
		'create_status'    => 'draft',
	);
}

/**
 * The per-request settings memo.
 *
 * Its own function rather than a static inside gwcpp_setting(), because a
 * writer needs a way to invalidate a reader's cache and PHP has no way to reach
 * another function's static variable. Without this, a script that calls
 * update_option() and then reads gwcpp_setting() in the same request — a
 * migration, WP-CLI, another plugin — would silently see the value from before
 * the write.
 *
 * @param array|null $set   Value to store.
 * @param bool       $clear Forget the cached value.
 * @return array|null
 */
function gwcpp_settings_cache( ?array $set = null, bool $clear = false ): ?array {
	static $cache = null;
	if ( $clear ) {
		$cache = null;
		return null;
	}
	if ( null !== $set ) {
		$cache = $set;
	}
	return $cache;
}

add_action( 'update_option_' . GWCPP_SETTINGS_OPTION, 'gwcpp_reset_settings_cache' );
add_action( 'add_option_' . GWCPP_SETTINGS_OPTION, 'gwcpp_reset_settings_cache' );

/**
 * Clear the settings memo. Hooked to both add_option_* and update_option_* —
 * WordPress fires the former only on an option's first write and the latter on
 * every write after, so a site's very first Settings save needs the same
 * invalidation as every one after it.
 */
function gwcpp_reset_settings_cache(): void {
	gwcpp_settings_cache( null, true );
}

/**
 * Read one setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function gwcpp_setting( string $key ) {
	$settings = gwcpp_settings_cache();
	if ( null === $settings ) {
		$stored   = get_option( GWCPP_SETTINGS_OPTION );
		$settings = gwcpp_settings_cache(
			array_merge( gwcpp_setting_defaults(), is_array( $stored ) ? $stored : array() )
		);
	}
	return $settings[ $key ] ?? null;
}

/**
 * Read one per-post-type flag.
 *
 * Always merged onto the defaults, so a post type enabled before a flag existed
 * behaves as that flag's default rather than as null. That matters more than it
 * looks: `null` is falsy, so a missing 'require_approval' would read as "no
 * approval needed" and quietly publish everything on an install that upgraded.
 *
 * @param string $post_type Post type slug.
 * @param string $key       Flag key.
 * @return mixed
 */
function gwcpp_type_setting( string $post_type, string $key ) {
	$types  = (array) gwcpp_setting( 'types' );
	$stored = isset( $types[ $post_type ] ) && is_array( $types[ $post_type ] )
		? $types[ $post_type ]
		: array();

	$merged = array_merge( gwcpp_type_setting_defaults(), $stored );

	return $merged[ $key ] ?? null;
}

/**
 * The post types the portal covers.
 *
 * Filtered late, and filtered through the same function every caller uses, so a
 * site that adds a type in code cannot end up with the list of types the portal
 * renders disagreeing with the list of types the access check trusts.
 *
 * Types that no longer exist are dropped. A post type disappearing — its plugin
 * deactivated, its registering theme switched away from — must not leave the
 * portal offering to edit posts nothing can register a form for.
 *
 * @return string[]
 */
function gwcpp_post_types(): array {
	$types = (array) gwcpp_setting( 'post_types' );
	$types = array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) );

	/**
	 * The post types the portal covers.
	 *
	 * @param string[] $types Post type slugs.
	 */
	$types = (array) apply_filters( 'gwcpp_portal_post_types', $types );

	return array_values( array_unique( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) ) );
}

/**
 * Whether a post type is covered by the portal.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function gwcpp_type_enabled( string $post_type ): bool {
	return '' !== $post_type && in_array( $post_type, gwcpp_post_types(), true );
}

/**
 * Where staff notifications go.
 *
 * Falls back to the site's admin email, which is the address that already
 * receives everything else WordPress sends and is one fewer thing to configure.
 *
 * @return string
 */
function gwcpp_staff_email(): string {
	$configured = (string) gwcpp_setting( 'staff_email' );
	if ( '' !== $configured && is_email( $configured ) ) {
		return $configured;
	}
	return (string) get_option( 'admin_email' );
}

/**
 * How long a portal session lasts, in seconds.
 *
 * Clamped rather than trusted. The setting is an integer field on a settings
 * screen, and both ends of it have a real failure mode: a zero or negative
 * value would expire the cookie the moment it was set, locking every portal
 * user out with no error to explain it, and a value of 8760 would hand a shared
 * front-desk computer a year-long session.
 *
 * @return int
 */
function gwcpp_session_seconds(): int {
	$hours = (int) gwcpp_setting( 'session_hours' );
	$hours = max( 1, min( 720, $hours ) );
	return $hours * HOUR_IN_SECONDS;
}
