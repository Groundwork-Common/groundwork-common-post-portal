<?php
/**
 * Demo data covering every state the portal can be in.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/groundwork-common-post-portal/tests/seed.php
 *
 * Optional arguments:
 *
 *   wp eval-file …/tests/seed.php <post_type> <filler_count>
 *
 * `post_type` defaults to `post`, which every install has — the seed
 * deliberately needs no custom post type registered, because a demo that
 * depends on a mu-plugin somebody has to write first is a demo that stops
 * working the moment that file goes missing. `filler_count` adds that many
 * ordinary entries, for looking at pagination or timing a review run.
 *
 * ── Why this lives in tests/ rather than .dev/ ──────────────────────────────
 * An earlier version sat in .dev/, which is gitignored — and was lost the first
 * time the working tree was cleaned, along with everything else in there.
 * `tests` is already excluded from the release zip by .distignore, so this is
 * both committed and absent from what anybody downloads.
 *
 * ── Idempotent ─────────────────────────────────────────────────────────────
 * Everything it creates is marked, and a second run deletes the previous lot
 * first. It never touches anything it did not make, apart from the plugin's own
 * settings and schema, which are the things being demonstrated.
 *
 * @package PostPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! function_exists( 'gwcpp_setting' ) ) {
	echo "The plugin is not active. Run: wp plugin activate groundwork-common-post-portal\n";
	exit( 1 );
}

$marker    = '_gwcpp_seeded';
$post_type = isset( $args[0] ) && post_type_exists( (string) $args[0] ) ? (string) $args[0] : 'post';
$filler    = isset( $args[1] ) ? max( 0, min( 2000, (int) $args[1] ) ) : 0;
$cadence   = 6;

/**
 * A date N months before today, as the review cycle counts them.
 *
 * @param int $months How far back.
 * @return string Y-m-d.
 */
function gwcpp_seed_months_ago( int $months ): string {
	return gwcpp_review_today()->modify( '-' . $months . ' months' )->format( 'Y-m-d' );
}

/**
 * Make one entry, marked so the next run can clean it up.
 *
 * @param string $post_type Post type.
 * @param string $title     Title.
 * @param string $status    Post status.
 * @return int
 */
function gwcpp_seed_post( string $post_type, string $title, string $status = 'publish' ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_title'  => $title,
		)
	);

	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}

	update_post_meta( (int) $id, '_gwcpp_seeded', 1 );

	return (int) $id;
}

/* ── Tear down the previous run ──────────────────────────────────────────── */

/* Every registered type by name, not the string 'any'.
 *
 * `post_type => 'any'` looks like "no filter" and is not: WP_Query expands it to
 * the registered types whose `exclude_from_search` is false. GWCPP_ORG_TYPE sets
 * `exclude_from_search => true` — as a private type should — so the teardown
 * could not see the organisations it had just created, and every re-run left the
 * previous lot behind. Three runs, nine organisations, each with the same name.
 * The marker was doing its job; the query was never asking about those rows. */
$old = get_posts(
	array(
		'post_type'    => array_values( get_post_types( array(), 'names' ) ),
		'post_status'  => 'any',
		'numberposts'  => 3000,
		'fields'       => 'ids',
		'meta_key'     => $marker, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off dev seeding.
		'meta_compare' => 'EXISTS',
	)
);

foreach ( $old as $id ) {
	wp_delete_post( (int) $id, true );
}

$seed_emails = array( 'jane@shelter.test', 'mark@shelter.test', 'sam@eastside.test', 'newcomer@shelter.test' );

foreach ( $seed_emails as $email ) {
	$user = get_user_by( 'email', $email );
	if ( $user ) {
		wp_delete_user( $user->ID );
	}
}

echo 'Cleared ' . count( $old ) . " entries from the last run.\n\n";

/* ── The portal page ─────────────────────────────────────────────────────── */

$page = get_page_by_path( 'portal' );

$page_args = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Portal',
	'post_name'    => 'portal',
	'post_content' => '<!-- wp:groundwork-common-post-portal/portal /-->',
);

if ( $page ) {
	$page_args['ID'] = $page->ID;
	wp_update_post( $page_args );
	$page_id = (int) $page->ID;
} else {
	$page_id = (int) wp_insert_post( $page_args );
}

/* Deliberately NOT marked for teardown. Sign-in links already sent point at
 * this page, and deleting and recreating it on every run would change its ID
 * and quietly break every link in every inbox. */

/* ── Settings ────────────────────────────────────────────────────────────── */

$settings = get_option( 'gwcpp_settings' );
$settings = is_array( $settings ) ? $settings : array();

$settings['post_types']  = array( $post_type );
$settings['portal_page'] = $page_id;
$settings['signin_magic']    = true;
$settings['signin_password'] = false;
$settings['session_hours']   = 3;
$settings['blocked_words']   = "scam\nfree money\nmiracle cure";

$settings['types'][ $post_type ] = array(
	'require_approval' => true,
	'allow_create'     => true,
	'allow_unpublish'  => true,
	'allow_handoff'    => true,
	'author_grant'     => false,
	'create_status'    => 'draft',
	'review_months'    => $cadence,
);

update_option( 'gwcpp_settings', $settings );
gwcpp_settings_cache( null, true );

/* ── Fields: one of every type ───────────────────────────────────────────── */

$schema = gwcpp_get_schema();
unset( $schema['types'][ $post_type ] );
gwcpp_save_schema( $schema );

$fields = array(
	array(
		'key'   => '__title',
		'type'  => 'text',
		'label' => 'Name of this place',
	),
	array(
		'key'         => 'seed_phone',
		'type'        => 'phone',
		'label'       => 'Phone number',
		'required'    => true,
		'description' => 'Include the area code.',
	),
	array(
		'key'   => 'seed_website',
		'type'  => 'url',
		'label' => 'Website',
	),
	array(
		'key'   => 'seed_email',
		'type'  => 'email',
		'label' => 'Contact email',
	),
	array(
		'key'   => 'seed_opened',
		'type'  => 'date',
		'label' => 'Open since',
	),
	array(
		'key'      => 'seed_capacity',
		'type'     => 'number',
		'label'    => 'People we can see each day',
		'settings' => array(
			'min' => '0',
			'max' => '500',
		),
	),
	array(
		'key'         => 'seed_note',
		'type'        => 'textarea',
		'label'       => 'Anything else people should know',
		'settings'    => array( 'rows' => 3 ),
	),
	array(
		'key'      => 'seed_access',
		'type'     => 'radio',
		'label'    => 'How people reach you',
		'settings' => array( 'options_raw' => "walkin|Walk in any time\nappointment|By appointment\nreferral|By referral only" ),
	),
	array(
		'key'      => 'seed_services',
		'type'     => 'multiselect',
		'label'    => 'What you offer',
		'settings' => array( 'options_raw' => "food|Food\nclothes|Clothing\nadvice|Advice\nhousing|Housing help" ),
	),
	array(
		'key'      => 'seed_step_free',
		'type'     => 'boolean',
		'label'    => 'Accessibility',
		'settings' => array( 'checkbox_label' => 'There is step-free access' ),
	),
	array(
		'key'         => '__content',
		'type'        => 'richtext',
		'label'       => 'About this place',
		'description' => 'Bold, italic, lists and links. Anything else is removed when you save.',
	),
	array(
		'key'      => 'seed_hours',
		'type'     => 'repeater',
		'label'    => 'Opening hours',
		'settings' => array(
			'subfields_raw' => "day|Day|select|mon=Monday;tue=Tuesday;wed=Wednesday;thu=Thursday;fri=Friday\nopens|Opens|text\ncloses|Closes|text",
			'max_rows'      => 7,
		),
	),
	array(
		'key'         => 'seed_photo',
		'type'        => 'media',
		'label'       => 'Photo of the entrance',
		'description' => 'Helps people recognise the place when they arrive.',
		'settings'    => array( 'max_mb' => 4 ),
	),
	array(
		'key'      => 'category',
		'type'     => 'taxonomy',
		'label'    => 'Categories',
		'settings' => array( 'allow_new' => false ),
	),
);

$mapped = 0;
foreach ( $fields as $raw ) {
	$field = gwcpp_sanitize_field( $raw );

	if ( null === $field ) {
		echo "  ! could not build field {$raw['key']}\n";
		continue;
	}

	gwcpp_put_field( $post_type, $field );
	++$mapped;
}

/* Terms for the taxonomy field to offer. */
$terms = array();
foreach ( array( 'Food bank', 'Advice service', 'Drop-in centre' ) as $name ) {
	$term = get_term_by( 'name', $name, 'category' );
	if ( ! $term ) {
		$made = wp_insert_term( $name, 'category' );
		$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], 'category' );
	}
	if ( $term ) {
		$terms[] = (int) $term->term_id;
	}
}

/* ── Organisations and people ────────────────────────────────────────────── */

$orgs = array();
foreach ( array( 'Shelter of Hope', 'Eastside Pantry', 'Northside Clinic' ) as $name ) {
	$orgs[ $name ] = gwcpp_seed_post( GWCPP_ORG_TYPE, $name );
}

$people = array(
	'jane@shelter.test'  => 'Shelter of Hope',
	'mark@shelter.test'  => 'Shelter of Hope',
	'sam@eastside.test'  => 'Eastside Pantry',
);

$users = array();
foreach ( $people as $email => $org_name ) {
	$result = gwcpp_grant_access( $orgs[ $org_name ], $email );

	if ( is_wp_error( $result ) ) {
		echo "  ! {$email}: " . $result->get_error_message() . "\n";
		continue;
	}

	$users[ $email ] = (int) $result;
}

/* Jane has signed in before; Mark never has. The Members box on an organisation
 * shows that, and "have they ever actually got in" is the first question staff
 * ask about a partner who says the portal is not working. */
if ( isset( $users['jane@shelter.test'] ) ) {
	update_user_meta( $users['jane@shelter.test'], 'gwcpp_last_login', time() - ( 2 * DAY_IN_SECONDS ) );
}

/* Northside Clinic deliberately has nobody. That is what produces the
 * "nobody to ask" state, which is the one thing the review cycle refuses to
 * act on by itself. */

/* ── One image, so the media field has something in it ───────────────────── */

$attachment_id = 0;

/* A 1×1 PNG written straight into uploads. Small enough to inline, real enough
 * that wp_check_filetype_and_ext accepts it — which a text file renamed .png
 * would not, and the media field would then refuse it exactly as designed. */
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore
$put = wp_upload_bits( 'seed-entrance.png', null, $png );

if ( empty( $put['error'] ) ) {
	$attachment_id = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Seed entrance photo',
			'post_status'    => 'inherit',
		),
		$put['file']
	);

	if ( $attachment_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $put['file'] ) );
		update_post_meta( $attachment_id, '_gwcpp_seeded', 1 );
	}
}

/* ── The scenarios ───────────────────────────────────────────────────────── */

$scenarios = array();

/** Give an entry a full set of ordinary values. */
$fill = static function ( int $id ) use ( $terms, $attachment_id ): void {
	update_post_meta( $id, 'seed_phone', '(205) 555-0142' );
	update_post_meta( $id, 'seed_website', 'https://example.org' );
	update_post_meta( $id, 'seed_email', 'hello@example.org' );
	update_post_meta( $id, 'seed_opened', '2019-04-01' );
	update_post_meta( $id, 'seed_capacity', '40' );
	update_post_meta( $id, 'seed_note', 'Ring the bell by the side door.' );
	update_post_meta( $id, 'seed_access', 'walkin' );
	update_post_meta( $id, 'seed_services', array( 'food', 'advice' ) );
	update_post_meta( $id, 'seed_step_free', '1' );
	update_post_meta(
		$id,
		'seed_hours',
		array(
			array(
				'day'    => 'mon',
				'opens'  => '9am',
				'closes' => '5pm',
			),
			array(
				'day'    => 'wed',
				'opens'  => '1pm',
				'closes' => '6pm',
			),
		)
	);

	if ( $attachment_id > 0 ) {
		update_post_meta( $id, 'seed_photo', $attachment_id );
	}
	if ( $terms ) {
		wp_set_object_terms( $id, array( $terms[0] ), 'category', false );
	}

	wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => '<p>We are open <strong>most</strong> weekdays. Please ring ahead if you need step-free access.</p>',
		)
	);
};

// 1. Everything normal, confirmed recently.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Bessemer' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 1 ) );
$scenarios['up to date'] = $id;

// 2. Due — the first nudge.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Fairfield' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( $cadence - 1 ) );
$scenarios['due soon'] = $id;

// 3. Overdue.
$id = gwcpp_seed_post( $post_type, 'Eastside Pantry — Irondale' );
gwcpp_set_post_org( $id, $orgs['Eastside Pantry'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( $cadence + 1 ) );
$scenarios['overdue'] = $id;

// 4. Past expiry, and actually hidden by the cycle.
$id = gwcpp_seed_post( $post_type, 'Eastside Pantry — Centrepoint' );
gwcpp_set_post_org( $id, $orgs['Eastside Pantry'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( $cadence * 2 ) );
gwcpp_review_expire( $id );
$scenarios['hidden by the cycle'] = $id;

// 5. Long overdue but exempt, so nothing ever happens to it.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Head Office' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 36 ) );
update_post_meta( $id, GWCPP_REVIEW_EXEMPT_META, 1 );
$scenarios['exempt'] = $id;

// 6. Long overdue with nobody who could confirm it. Never hidden; escalates to
//    the weekly digest instead.
$id = gwcpp_seed_post( $post_type, 'Northside Clinic — Gardendale' );
gwcpp_set_post_org( $id, $orgs['Northside Clinic'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 36 ) );
$scenarios['nobody to ask'] = $id;

// 7. Never confirmed at all. With no review date the cycle counts from the
//    publish date, so the post is backdated — created today it would sit at
//    "current" and demonstrate nothing.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Hueytown' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
delete_post_meta( $id, GWCPP_REVIEWED_META );
$published = gwcpp_review_today()->modify( '-' . ( $cadence + 2 ) . ' months' )->format( 'Y-m-d H:i:s' );
wp_update_post(
	array(
		'ID'            => $id,
		'post_date'     => $published,
		'post_date_gmt' => $published,
	)
);
$scenarios['never confirmed'] = $id;

// 8. A submission waiting in the approval queue.
$id = gwcpp_seed_post( $post_type, 'Eastside Pantry — Roebuck' );
gwcpp_set_post_org( $id, $orgs['Eastside Pantry'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 1 ) );

if ( isset( $users['sam@eastside.test'] ) ) {
	gwcpp_store_changeset(
		$id,
		$users['sam@eastside.test'],
		array(
			'seed_phone' => '(205) 555-0199',
			'seed_note'  => 'We have moved to the building next door.',
			'__title'    => 'Eastside Pantry — Roebuck (new address)',
		)
	);
}
$scenarios['waiting for approval'] = $id;

// 9. An existing value containing a blocked word, typed by staff before the
//    word was on the list. Its owner must still be able to save other fields.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Scam Awareness Desk' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
update_post_meta( $id, 'seed_note', 'We help people who have been targeted by a scam.' );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 1 ) );
$scenarios['blocked word, grandfathered'] = $id;

// 10. A handover invitation in flight.
$id = gwcpp_seed_post( $post_type, 'Shelter of Hope — Midfield' );
gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 1 ) );

$handoff_note = '';
if ( isset( $users['jane@shelter.test'] ) ) {
	$sent = gwcpp_send_handoff( $id, $users['jane@shelter.test'], 'newcomer@shelter.test' );
	$handoff_note = is_wp_error( $sent ) ? $sent->get_error_message() : 'invitation sent to newcomer@shelter.test';
}
$scenarios['handover pending'] = $id;

// 11. A direct grant with no organisation — reachable, but not handoverable,
//     since only a member of an organisation may invite into it.
$id = gwcpp_seed_post( $post_type, 'Unaffiliated Drop-in' );
$fill( $id );
update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( 1 ) );
if ( isset( $users['jane@shelter.test'] ) ) {
	gwcpp_add_post_editor( $users['jane@shelter.test'], $id );
}
$scenarios['direct grant, no organisation'] = $id;

/* ── Filler ──────────────────────────────────────────────────────────────── */

if ( $filler > 0 ) {
	for ( $i = 1; $i <= $filler; $i++ ) {
		$id = gwcpp_seed_post( $post_type, sprintf( 'Filler entry %03d', $i ) );
		gwcpp_set_post_org( $id, $orgs['Shelter of Hope'] );
		update_post_meta( $id, 'seed_phone', '(205) 555-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) );
		update_post_meta( $id, GWCPP_REVIEWED_META, gwcpp_seed_months_ago( $i % 14 ) );
	}
}

gwcpp_flush_pending_count();

/* ── What to look at ─────────────────────────────────────────────────────── */

$portal = gwcpp_portal_url();

echo "Seeded.\n\n";
echo "  Portal     {$portal}\n";
echo '  wp-admin   ' . admin_url() . "  (admin / password)\n";
echo '  Queue      ' . admin_url( 'admin.php?page=' . GWCPP_QUEUE_SLUG ) . "\n";
echo '  Entries    ' . admin_url( 'edit.php?post_type=' . $post_type ) . "\n\n";

echo "People (all sign in by emailed link — no passwords)\n";
foreach ( $users as $email => $user_id ) {
	$reach = gwcpp_editable_post_ids( $user_id );
	printf( "  %-22s %d entries\n", $email, count( $reach ) );
}
echo "  newcomer@shelter.test  no account yet — has a handover invitation waiting\n\n";

echo "Organisations\n";
foreach ( $orgs as $name => $org_id ) {
	printf(
		"  %-18s %d members   %s\n",
		$name,
		count( gwcpp_org_members( $org_id ) ),
		admin_url( 'post.php?post=' . $org_id . '&action=edit' )
	);
}

echo "\nScenarios\n";
foreach ( $scenarios as $label => $id ) {
	$state = gwcpp_review_state( $id );
	printf(
		"  %-30s %-12s %s\n",
		$label,
		(string) ( $state['state'] ?? '-' ),
		$portal . ( false === strpos( $portal, '?' ) ? '?' : '&' ) . 'gwcpp_view=edit&gwcpp_post=' . $id
	);
}

echo "\nFields mapped: {$mapped}.  Review cadence: {$cadence} months.\n";
echo "Blocked words: scam, free money, miracle cure.\n";

if ( '' !== $handoff_note ) {
	echo "Handover: {$handoff_note}\n";
}

if ( $filler > 0 ) {
	echo "Filler entries: {$filler}.\n";
}

if ( isset( $users['jane@shelter.test'] ) ) {
	echo "\nA sign-in link for jane, to skip the email step once:\n";
	echo '  ' . gwcpp_portal_url( array( 'gwcpp_token' => gwcpp_mint_token( $users['jane@shelter.test'] ) ) ) . "\n";
	echo "  (works once, expires in 15 minutes)\n";
}

echo "\nTry: run the review cycle and watch the reminders go out —\n";
echo "  wp eval 'var_dump( gwcpp_run_daily_review() );'\n";
