<?php
/**
 * The admin menu, the Settings screen, and its tabs.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── Hand-rolled rather than the Settings API ────────────────────────────────
 * The Settings API is the right tool for a flat bag of independent values, and
 * this is not one. Half the settings on this screen are a nested array keyed by
 * post type, whose keys are discovered at render time from what the site has
 * registered; the Fields screen is five distinct actions each with its own
 * confirmation. Expressed through register_setting() that becomes one
 * sanitize_callback reconstructing a tree it cannot see the shape of, which is
 * exactly where a checkbox somebody never saw gets written as false.
 *
 * So: admin_post_ handlers, an explicit capability check and an explicit nonce
 * as the first two lines of each, and Post/Redirect/Get with a message code.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** The Settings screen's tabs, in order. */
const GWCPP_TABS = array( 'general', 'signin', 'appearance' );

add_action( 'admin_menu', 'gwcpp_admin_menu' );
add_action( 'admin_post_gwcpp_save_settings', 'gwcpp_handle_save_settings' );

/**
 * The capability everything on these screens requires.
 *
 * Dies rather than returns. Every caller is a handler that would otherwise have
 * to remember to check the return value, and the one that forgets is the one
 * that writes.
 */
function gwcpp_require_admin_caps(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'You do not have permission to change portal settings.', 'groundwork-common-post-portal' ),
			'',
			array( 'response' => 403 )
		);
	}
}

/**
 * Register the menu.
 */
function gwcpp_admin_menu(): void {
	add_menu_page(
		__( 'Post Portal', 'groundwork-common-post-portal' ),
		__( 'Portal', 'groundwork-common-post-portal' ),
		'manage_options',
		GWCPP_MENU_SLUG,
		'gwcpp_settings_screen',
		'dashicons-id-alt',
		58
	);

	/*
	 * WordPress makes the first submenu item a duplicate of the parent, labelled
	 * with the parent's name. Re-adding it with the label we want replaces that
	 * rather than adding a second row.
	 */
	$settings = add_submenu_page(
		GWCPP_MENU_SLUG,
		__( 'Portal Settings', 'groundwork-common-post-portal' ),
		__( 'Settings', 'groundwork-common-post-portal' ),
		'manage_options',
		GWCPP_MENU_SLUG,
		'gwcpp_settings_screen'
	);

	$fields = add_submenu_page(
		GWCPP_MENU_SLUG,
		__( 'Portal Fields', 'groundwork-common-post-portal' ),
		__( 'Fields', 'groundwork-common-post-portal' ),
		'manage_options',
		'gwcpp-fields',
		'gwcpp_fields_screen'
	);

	/*
	 * The count in the menu label, in core's own bubble markup. Without it the
	 * queue is a screen somebody has to remember to visit, and a submission
	 * waits until a partner emails to ask why nothing happened.
	 */
	$waiting = gwcpp_pending_count();
	$label   = __( 'Pending Changes', 'groundwork-common-post-portal' );

	if ( $waiting > 0 ) {
		$label .= sprintf(
			' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
			$waiting
		);
	}

	$queue = add_submenu_page(
		GWCPP_MENU_SLUG,
		__( 'Pending Changes', 'groundwork-common-post-portal' ),
		$label,
		'manage_options',
		GWCPP_QUEUE_SLUG,
		'gwcpp_queue_screen'
	);

	foreach ( array( $settings, $fields, $queue ) as $hook ) {
		if ( $hook ) {
			add_action( 'load-' . $hook, 'gwcpp_add_help_tabs' );
		}
	}
}

/**
 * The tab the URL is asking for.
 *
 * @return string
 */
function gwcpp_current_tab(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection on a GET request.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

	return in_array( $tab, GWCPP_TABS, true ) ? $tab : 'general';
}

/**
 * The Settings screen.
 */
function gwcpp_settings_screen(): void {
	gwcpp_require_admin_caps();

	$tab = gwcpp_current_tab();

	echo '<div class="wrap gwcpp-admin">';
	printf( '<h1>%s</h1>', esc_html__( 'Post Portal', 'groundwork-common-post-portal' ) );

	gwcpp_render_admin_notice();
	gwcpp_render_tabs( $tab );
	gwcpp_render_setup_checklist();

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'gwcpp_save_settings' );
	echo '<input type="hidden" name="action" value="gwcpp_save_settings" />';
	printf( '<input type="hidden" name="tab" value="%s" />', esc_attr( $tab ) );

	switch ( $tab ) {
		case 'signin':
			gwcpp_tab_signin();
			break;
		case 'appearance':
			gwcpp_tab_appearance();
			break;
		default:
			gwcpp_tab_general();
	}

	submit_button( __( 'Save settings', 'groundwork-common-post-portal' ) );

	echo '</form>';

	gwcpp_render_colophon();

	echo '</div>';
}

/**
 * The tab strip.
 *
 * @param string $current Current tab.
 */
function gwcpp_render_tabs( string $current ): void {
	$labels = array(
		'general'    => __( 'General', 'groundwork-common-post-portal' ),
		'signin'     => __( 'Signing in', 'groundwork-common-post-portal' ),
		'appearance' => __( 'Appearance', 'groundwork-common-post-portal' ),
	);

	echo '<nav class="nav-tab-wrapper">';
	foreach ( GWCPP_TABS as $tab ) {
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . GWCPP_MENU_SLUG . '&tab=' . $tab ) ),
			$tab === $current ? ' nav-tab-active' : '',
			esc_html( $labels[ $tab ] )
		);
	}
	echo '</nav>';
}

/**
 * What is still missing before the portal works.
 *
 * Three things have to be true, and each of them fails silently: no post types
 * means an empty list, no portal page means sign-in links with nowhere to go,
 * no fields means a form with nothing on it. None of those produces an error
 * anywhere, so the only place they can be caught is here, before somebody
 * invites a partner and discovers it from them.
 */
function gwcpp_render_setup_checklist(): void {
	$missing = array();

	if ( ! gwcpp_post_types() ) {
		$missing[] = __( 'No post types are switched on yet, so there is nothing for anyone to edit.', 'groundwork-common-post-portal' );
	}

	if ( ! gwcpp_portal_page_id() ) {
		$missing[] = __( 'No portal page is chosen, so sign-in links have nowhere to send people. Create a page, add the Post Portal block to it, publish it, then choose it below.', 'groundwork-common-post-portal' );
	} else {
		$mapped = false;
		foreach ( gwcpp_post_types() as $post_type ) {
			if ( gwcpp_type_fields( $post_type ) ) {
				$mapped = true;
				break;
			}
		}
		if ( gwcpp_post_types() && ! $mapped ) {
			$missing[] = __( 'No fields are mapped yet, so the edit form would be empty. Set them up on the Fields screen.', 'groundwork-common-post-portal' );
		}
	}

	if ( ! $missing ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>';
	esc_html_e( 'Not ready yet', 'groundwork-common-post-portal' );
	echo '</strong></p><ul class="ul-disc">';
	foreach ( $missing as $line ) {
		printf( '<li>%s</li>', esc_html( $line ) );
	}
	echo '</ul></div>';
}

/**
 * The General tab.
 */
function gwcpp_tab_general(): void {
	$enabled = gwcpp_post_types();

	echo '<h2>' . esc_html__( 'What can be edited', 'groundwork-common-post-portal' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Switch on the post types portal users may edit. Nothing is switched on by default, and switching one off immediately withdraws access to every post of that type.', 'groundwork-common-post-portal' ) . '</p>';

	echo '<table class="form-table" role="presentation"><tbody>';

	foreach ( gwcpp_candidate_post_types() as $object ) {
		$slug = $object->name;
		$on   = in_array( $slug, $enabled, true );

		echo '<tr><th scope="row">';
		printf(
			'<label><input type="checkbox" name="gwcpp_settings[post_types][]" value="%s"%s /> %s</label>',
			esc_attr( $slug ),
			checked( $on, true, false ),
			esc_html( $object->labels->name )
		);
		printf( '<br /><code>%s</code>', esc_html( $slug ) );
		echo '</th><td>';

		gwcpp_render_type_flags( $slug );

		echo '</td></tr>';
	}

	echo '</tbody></table>';

	echo '<h2>' . esc_html__( 'The portal page', 'groundwork-common-post-portal' ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
	printf( '<label for="gwcpp-portal-page">%s</label>', esc_html__( 'Page', 'groundwork-common-post-portal' ) );
	echo '</th><td>';

	wp_dropdown_pages(
		array(
			'name'              => 'gwcpp_settings[portal_page]',
			'id'                => 'gwcpp-portal-page',
			'selected'          => (int) gwcpp_portal_page_id(),
			// wp_dropdown_pages() echoes, so its label goes out as-is.
			'show_option_none'  => esc_html__( '— none chosen —', 'groundwork-common-post-portal' ),
			'option_none_value' => '0',
			'post_status'       => 'publish',
		)
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Only published pages appear here. A draft cannot be a portal page, because a sign-in link pointing at one would 404 for everybody who is not an editor.', 'groundwork-common-post-portal' )
	);

	echo '</td></tr></tbody></table>';

	echo '<h2>' . esc_html__( 'Words to refuse', 'groundwork-common-post-portal' ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
	printf( '<label for="gwcpp-blocked">%s</label>', esc_html__( 'Blocked words', 'groundwork-common-post-portal' ) );
	echo '</th><td>';
	printf(
		'<textarea id="gwcpp-blocked" name="gwcpp_settings[blocked_words]" rows="4" class="large-text code">%s</textarea>',
		esc_textarea( (string) gwcpp_setting( 'blocked_words' ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'One per line, or separated by commas. A submission containing one is refused and the person is told which word. Only fields they actually changed are checked, so an existing entry containing one of these stays editable.', 'groundwork-common-post-portal' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Matched on whole words, including obvious plurals. Anything under three characters is ignored. This catches mistakes, not determined people.', 'groundwork-common-post-portal' )
	);
	echo '</td></tr></tbody></table>';
}

/**
 * The per-post-type flags.
 *
 * @param string $post_type Post type slug.
 */
function gwcpp_render_type_flags( string $post_type ): void {
	$flags = array(
		'require_approval' => __( 'Changes need staff approval before going live', 'groundwork-common-post-portal' ),
		'allow_create'     => __( 'Portal users may add new ones', 'groundwork-common-post-portal' ),
		'allow_unpublish'  => __( 'Portal users may take their own off the site (reversible)', 'groundwork-common-post-portal' ),
		'allow_handoff'    => __( 'Portal users may invite a replacement to take over', 'groundwork-common-post-portal' ),
		'author_grant'     => __( 'The post author may edit their own, without an organisation', 'groundwork-common-post-portal' ),
	);

	echo '<fieldset class="gwcpp-type-flags">';

	/*
	 * A hidden marker so the handler can tell "every box unticked" from "this
	 * post type's row was not on the form". Without it a submission from a
	 * screen rendered before a post type existed would write every flag on that
	 * type to false.
	 */
	printf(
		'<input type="hidden" name="gwcpp_settings[types][%s][_present]" value="1" />',
		esc_attr( $post_type )
	);

	foreach ( $flags as $key => $label ) {
		printf(
			'<label><input type="checkbox" name="gwcpp_settings[types][%1$s][%2$s]" value="1"%3$s /> %4$s</label><br />',
			esc_attr( $post_type ),
			esc_attr( $key ),
			checked( (bool) gwcpp_type_setting( $post_type, $key ), true, false ),
			esc_html( $label )
		);
	}

	printf(
		'<p class="gwcpp-cadence"><label for="gwcpp-cadence-%1$s">%2$s</label> <input type="number" id="gwcpp-cadence-%1$s" name="gwcpp_settings[types][%1$s][review_months]" value="%3$d" min="0" max="120" class="small-text" /> %4$s</p>',
		esc_attr( $post_type ),
		esc_html__( 'Ask owners to confirm their details every', 'groundwork-common-post-portal' ),
		(int) gwcpp_type_setting( $post_type, 'review_months' ),
		esc_html__( 'months (0 = never ask)', 'groundwork-common-post-portal' )
	);

	$cadence = gwcpp_review_cadence( $post_type );
	if ( $cadence > 0 ) {
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: months until the first nudge, 2: months until it is hidden. */
					__( 'Reminders start at %1$d months and the entry stops being shown at %2$d if nobody ever confirms. Nothing is deleted, and confirming puts it straight back.', 'groundwork-common-post-portal' ),
					max( 1, $cadence - 1 ),
					$cadence * 2
				)
			)
		);
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Portal users can never delete anything, whatever these say.', 'groundwork-common-post-portal' )
	);

	echo '</fieldset>';
}

/**
 * The post types worth offering.
 *
 * Excludes the plugin's own organisation type and WordPress's internal ones —
 * revisions, menu items, the block and template types. Attachments are excluded
 * too: an attachment is a file, editing one through a form that writes post
 * meta is meaningless, and it is the post type most likely to have thousands of
 * rows nobody wants listed in a portal.
 *
 * @return WP_Post_Type[]
 */
function gwcpp_candidate_post_types(): array {
	$excluded = array(
		GWCPP_ORG_TYPE,
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	$out = array();
	foreach ( get_post_types( array(), 'objects' ) as $object ) {
		if ( in_array( $object->name, $excluded, true ) ) {
			continue;
		}

		/*
		 * show_ui rather than public: a post type staff cannot see in wp-admin
		 * is one nobody can moderate, and the approval queue depends on staff
		 * being able to open the thing they are approving.
		 */
		if ( ! $object->show_ui ) {
			continue;
		}
		$out[] = $object;
	}

	return $out;
}

/**
 * The Signing in tab.
 */
function gwcpp_tab_signin(): void {
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row">' . esc_html__( 'Ways to sign in', 'groundwork-common-post-portal' ) . '</th><td><fieldset>';
	echo '<input type="hidden" name="gwcpp_settings[_tab_signin]" value="1" />';

	printf(
		'<label><input type="checkbox" name="gwcpp_settings[signin_magic]" value="1"%s /> %s</label><br />',
		checked( (bool) gwcpp_setting( 'signin_magic' ), true, false ),
		esc_html__( 'A link emailed to them (no password)', 'groundwork-common-post-portal' )
	);
	printf(
		'<label><input type="checkbox" name="gwcpp_settings[signin_password]" value="1"%s /> %s</label>',
		checked( (bool) gwcpp_setting( 'signin_password' ), true, false ),
		esc_html__( 'Username and password', 'groundwork-common-post-portal' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Accounts the portal creates have no usable password, so password sign-in only helps people who have been given one. Turning it on also re-enables password resets for portal users.', 'groundwork-common-post-portal' )
	);
	echo '</fieldset></td></tr>';

	echo '<tr><th scope="row">';
	printf( '<label for="gwcpp-session">%s</label>', esc_html__( 'Stay signed in for', 'groundwork-common-post-portal' ) );
	echo '</th><td>';
	printf(
		'<input type="number" id="gwcpp-session" name="gwcpp_settings[session_hours]" value="%d" min="1" max="720" class="small-text" /> %s',
		(int) gwcpp_setting( 'session_hours' ),
		esc_html__( 'hours', 'groundwork-common-post-portal' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Short is safer. A portal user is often on a shared computer at a front desk.', 'groundwork-common-post-portal' )
	);
	echo '</td></tr>';

	echo '<tr><th scope="row">';
	printf( '<label for="gwcpp-staff-email">%s</label>', esc_html__( 'Notifications go to', 'groundwork-common-post-portal' ) );
	echo '</th><td>';
	printf(
		'<input type="email" id="gwcpp-staff-email" name="gwcpp_settings[staff_email]" value="%s" class="regular-text" placeholder="%s" />',
		esc_attr( (string) gwcpp_setting( 'staff_email' ) ),
		esc_attr( (string) get_option( 'admin_email' ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Leave blank to use the site admin address.', 'groundwork-common-post-portal' )
	);
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Emails come from', 'groundwork-common-post-portal' ) . '</th><td>';
	printf(
		'<input type="text" name="gwcpp_settings[from_name]" value="%s" class="regular-text" placeholder="%s" /> ',
		esc_attr( (string) gwcpp_setting( 'from_name' ) ),
		esc_attr( (string) get_bloginfo( 'name' ) )
	);
	printf(
		'<input type="email" name="gwcpp_settings[from_email]" value="%s" class="regular-text" />',
		esc_attr( (string) gwcpp_setting( 'from_email' ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Leave both blank to use whatever this site normally sends as. Setting an address your domain is not authorised to send from will send your sign-in links to spam.', 'groundwork-common-post-portal' )
	);
	echo '</td></tr>';

	echo '</tbody></table>';
}

/**
 * The Appearance tab.
 */
function gwcpp_tab_appearance(): void {
	$fields = array(
		'accent_color'  => array( __( 'Accent colour', 'groundwork-common-post-portal' ), 'color' ),
		'portal_bg'     => array( __( 'Background', 'groundwork-common-post-portal' ), 'color' ),
		'portal_text'   => array( __( 'Text colour', 'groundwork-common-post-portal' ), 'color' ),
		'surface_color' => array( __( 'Card background', 'groundwork-common-post-portal' ), 'color' ),
		'line_color'    => array( __( 'Border colour', 'groundwork-common-post-portal' ), 'color' ),
		'radius'        => array( __( 'Corner radius', 'groundwork-common-post-portal' ), 'text' ),
		'max_width'     => array( __( 'Maximum width', 'groundwork-common-post-portal' ), 'text' ),
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Everything here is optional. Left blank, the portal uses neutral defaults that sit reasonably inside most themes.', 'groundwork-common-post-portal' )
	);

	echo '<input type="hidden" name="gwcpp_settings[_tab_appearance]" value="1" />';
	echo '<table class="form-table" role="presentation"><tbody>';

	foreach ( $fields as $key => $spec ) {
		list( $label, $type ) = $spec;
		$value                = (string) gwcpp_setting( $key );

		echo '<tr><th scope="row">';
		printf( '<label for="gwcpp-%1$s">%2$s</label>', esc_attr( $key ), esc_html( $label ) );
		echo '</th><td>';
		printf(
			'<input type="text" id="gwcpp-%1$s" name="gwcpp_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( 'color' === $type ? '#2b6cb0' : '42rem' )
		);
		echo '</td></tr>';
	}

	echo '</tbody></table>';
}

/**
 * Save the Settings screen.
 */
function gwcpp_handle_save_settings(): void {
	gwcpp_require_admin_caps();
	check_admin_referer( 'gwcpp_save_settings' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Every leaf is sanitized in gwcpp_sanitize_settings().
	$raw = isset( $_POST['gwcpp_settings'] ) && is_array( $_POST['gwcpp_settings'] )
		? (array) wp_unslash( $_POST['gwcpp_settings'] )
		: array();

	$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
	$tab = in_array( $tab, GWCPP_TABS, true ) ? $tab : 'general';

	$stored = get_option( GWCPP_SETTINGS_OPTION );
	$stored = is_array( $stored ) ? $stored : array();

	update_option( GWCPP_SETTINGS_OPTION, gwcpp_sanitize_settings( $raw, $stored, $tab ), true );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => GWCPP_MENU_SLUG,
				'tab'          => $tab,
				'gwcpp_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Sanitize a settings submission.
 *
 * ── Merged onto what is stored, never onto the defaults ──────────────────────
 * And only the keys this tab actually rendered are touched. Both halves matter.
 *
 * Merging onto defaults would mean saving the Appearance tab reset every
 * sign-in setting to its default, because they were not on the form. Writing
 * every key regardless of tab has the same effect for checkboxes, which submit
 * nothing when unticked and are therefore indistinguishable from absent — hence
 * the hidden `_tab_*` markers, which say "this tab was rendered, so an absent
 * checkbox here really does mean unticked".
 *
 * @param array  $raw    Submitted values, unslashed.
 * @param array  $stored What is currently saved.
 * @param string $tab    Which tab was submitted.
 * @return array
 */
function gwcpp_sanitize_settings( array $raw, array $stored, string $tab ): array {
	$out = $stored;

	if ( 'general' === $tab ) {
		$types             = isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ? $raw['post_types'] : array();
		$out['post_types'] = array_values(
			array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' )
		);

		$out['portal_page'] = isset( $raw['portal_page'] ) ? (int) $raw['portal_page'] : 0;

		$flags = array( 'require_approval', 'allow_create', 'allow_unpublish', 'allow_handoff', 'author_grant' );
		$rows  = isset( $raw['types'] ) && is_array( $raw['types'] ) ? $raw['types'] : array();

		$type_settings = isset( $out['types'] ) && is_array( $out['types'] ) ? $out['types'] : array();

		foreach ( $rows as $post_type => $row ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || ! is_array( $row ) || empty( $row['_present'] ) ) {
				continue;
			}

			$clean = array();
			foreach ( $flags as $flag ) {
				$clean[ $flag ] = ! empty( $row[ $flag ] );
			}

			// Not a checkbox, so it is read rather than inferred from absence.
			$clean['review_months'] = max( 0, min( 120, isset( $row['review_months'] ) ? (int) $row['review_months'] : 0 ) );

			// Not on the form; preserved rather than reset to the default.
			$clean['create_status'] = isset( $type_settings[ $post_type ]['create_status'] )
				? (string) $type_settings[ $post_type ]['create_status']
				: 'draft';

			$type_settings[ $post_type ] = $clean;
		}

		$out['types'] = $type_settings;

		$out['blocked_words'] = sanitize_textarea_field( (string) ( $raw['blocked_words'] ?? '' ) );
	}

	if ( 'signin' === $tab && ! empty( $raw['_tab_signin'] ) ) {
		$out['signin_magic']    = ! empty( $raw['signin_magic'] );
		$out['signin_password'] = ! empty( $raw['signin_password'] );
		$out['session_hours']   = max( 1, min( 720, isset( $raw['session_hours'] ) ? (int) $raw['session_hours'] : 3 ) );

		foreach ( array( 'staff_email', 'from_email' ) as $key ) {
			$value       = sanitize_email( (string) ( $raw[ $key ] ?? '' ) );
			$out[ $key ] = is_email( $value ) ? $value : '';
		}

		$out['from_name'] = sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) );
	}

	if ( 'appearance' === $tab && ! empty( $raw['_tab_appearance'] ) ) {
		foreach ( array( 'accent_color', 'portal_bg', 'portal_text', 'surface_color', 'line_color', 'radius', 'max_width' ) as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		}
	}

	return $out;
}

/**
 * Show the message a redirect left behind.
 */
function gwcpp_render_admin_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a whitelisted message code.
	$code = isset( $_GET['gwcpp_notice'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_notice'] ) ) : '';

	if ( '' === $code ) {
		return;
	}

	$messages = gwcpp_admin_messages();
	if ( ! isset( $messages[ $code ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $code ];

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * Message codes, so a redirect never carries display text in its URL.
 *
 * @return array<string, array{0:string,1:string}>
 */
function gwcpp_admin_messages(): array {
	return array(
		'saved'         => array( 'success', __( 'Settings saved.', 'groundwork-common-post-portal' ) ),
		'field_saved'   => array( 'success', __( 'Field saved.', 'groundwork-common-post-portal' ) ),
		'field_retired' => array( 'success', __( 'Field removed from the form. The data on each post has been left alone.', 'groundwork-common-post-portal' ) ),
		'field_bad'     => array( 'error', __( 'That field could not be saved. It needs a name and a type.', 'groundwork-common-post-portal' ) ),
		'reordered'     => array( 'success', __( 'Order saved.', 'groundwork-common-post-portal' ) ),
		'imported'      => array( 'success', __( 'Imported. Check the labels and types, then reorder them to suit.', 'groundwork-common-post-portal' ) ),
		'nothing'       => array( 'warning', __( 'Nothing was found to import for that post type.', 'groundwork-common-post-portal' ) ),
		'invited'       => array( 'success', __( 'Invitation sent.', 'groundwork-common-post-portal' ) ),
		'invite_failed' => array( 'error', __( 'That person could not be invited. See the message on the organisation.', 'groundwork-common-post-portal' ) ),
		'removed'       => array( 'success', __( 'Removed.', 'groundwork-common-post-portal' ) ),
		'approved'      => array( 'success', __( 'Approved. The changes are on the site, and the person who sent them has been told.', 'groundwork-common-post-portal' ) ),
		'rejected'      => array( 'success', __( 'Rejected. The entry is unchanged, and the person who sent the changes has been told.', 'groundwork-common-post-portal' ) ),
		'queue_gone'    => array( 'warning', __( 'That change had already been dealt with — somebody else got there first.', 'groundwork-common-post-portal' ) ),
	);
}

/*
 * ── The colophon ────────────────────────────────────────────────────────────
 * On this plugin's own screens only, collapsible but never dismissible, and
 * snoozed for thirty days by storing WHEN it was collapsed rather than a
 * boolean. A boolean cannot expire, and "never show this again" on a support
 * ask is a decision somebody makes in one impatient second and cannot revisit.
 * ───────────────────────────────────────────────────────────────────────────
 */

/**
 * True when the colophon should be folded away.
 *
 * Split out from the renderer so it can be tested without WordPress.
 *
 * @param int $collapsed_at Timestamp it was collapsed, or 0.
 * @param int $now          Current timestamp.
 * @return bool
 */
function gwcpp_colophon_snoozed( int $collapsed_at, int $now ): bool {
	if ( $collapsed_at <= 0 ) {
		return false;
	}

	/*
	 * A timestamp in the future means a clock changed, a database was moved
	 * between servers, or somebody edited the row. Treating it as "snoozed
	 * until then" could hide the panel for years, so an impossible value snoozes
	 * for nothing.
	 */
	if ( $collapsed_at > $now ) {
		return false;
	}

	return ( $now - $collapsed_at ) < 30 * DAY_IN_SECONDS;
}

/**
 * The Groundwork Common panel.
 */
function gwcpp_render_colophon(): void {
	if ( '' === GWCPP_SPONSOR_URL && '' === GWCPP_GWC_URL ) {
		return;
	}

	$collapsed = gwcpp_colophon_snoozed(
		(int) get_user_meta( get_current_user_id(), 'gwcpp_colophon_collapsed_at', true ),
		time()
	);

	printf(
		'<details class="gwcpp-colophon"%s><summary>%s</summary>',
		$collapsed ? '' : ' open',
		esc_html__( 'About this plugin', 'groundwork-common-post-portal' )
	);

	printf(
		'<p>%s</p>',
		esc_html__( 'Post Portal is built and maintained by Groundwork Common, who make software for organisations doing public-interest work and release the generally useful parts of it.', 'groundwork-common-post-portal' )
	);

	if ( '' !== GWCPP_SPONSOR_URL ) {
		printf(
			'<p><a href="%s" class="button">%s</a> <a href="%s">%s</a></p>',
			esc_url( GWCPP_SPONSOR_URL ),
			esc_html__( 'Support this work', 'groundwork-common-post-portal' ),
			esc_url( GWCPP_GWC_URL ),
			esc_html__( 'See what else we do', 'groundwork-common-post-portal' )
		);
	}

	echo '</details>';
}
