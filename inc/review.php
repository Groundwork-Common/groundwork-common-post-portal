<?php
/**
 * The review cycle: asking owners whether their entry is still right.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── Why this exists ─────────────────────────────────────────────────────────
 * A directory goes stale quietly. Hours change, a clinic moves its Thursday
 * session, an organisation stops offering something — and nobody tells the site
 * owner, because from the partner's side nothing has happened. The entry keeps
 * saying what it said in 2023 and looks perfectly healthy.
 *
 * Most organisations running a directory cannot phone every partner twice a
 * year. So the owner reviews their own entry: they get an email, they press one
 * button, and the clock resets. If nobody ever does, the entry eventually stops
 * being shown — because an out-of-date address that sends somebody to a closed
 * building is worse than no address at all.
 *
 * ── One cadence, four thresholds derived from it ────────────────────────────
 * The portal this was generalised from had four independent month constants —
 * 5, 6, 7 and 12 — which is four numbers to get right and four to keep in step
 * when somebody wants a different rhythm.
 *
 * They are not independent. They are cadence minus one, cadence, cadence plus
 * one, and double cadence: start nudging a month early so somebody signing in
 * for another reason sees it before it is late, say "due every N months"
 * because that is the promise, warn hard a month after, and give the whole
 * cadence again before hiding anything. So a site configures one number and the
 * rest follow.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Post meta, single: Y-m-d of the last confirmed review. */
const GWCPP_REVIEWED_META = '_gwcpp_reviewed_at';

/** Post meta, single: who confirmed it. */
const GWCPP_REVIEWED_BY_META = '_gwcpp_reviewed_by';

/** Post meta, single: this entry is never chased. */
const GWCPP_REVIEW_EXEMPT_META = '_gwcpp_review_exempt';

/** Post meta, single: this entry was unpublished by the cycle, not by a human. */
const GWCPP_AUTO_EXPIRED_META = '_gwcpp_auto_expired';

/** Post meta, array: rungs of the notice ladder already delivered. */
const GWCPP_NOTICES_META = '_gwcpp_review_notices_sent';

/**
 * How often an entry of this type should be reviewed, in months.
 *
 * Zero means the cycle is switched off for this post type, which is the
 * default — a plugin that started emailing a site's partners because somebody
 * enabled a post type would be doing something nobody asked for.
 *
 * @param string $post_type Post type slug.
 * @return int
 */
function gwcpp_review_cadence( string $post_type ): int {
	$months = (int) gwcpp_type_setting( $post_type, 'review_months' );

	// Clamped rather than trusted: a cadence of zero would make every date
	// threshold identical and fire the whole ladder at once, and one of 600
	// silently means never.
	return $months > 0 ? max( 1, min( 120, $months ) ) : 0;
}

/**
 * True when this post type is on the cycle.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function gwcpp_review_enabled( string $post_type ): bool {
	return gwcpp_type_cadence_is_set( $post_type ) && gwcpp_type_enabled( $post_type );
}

/**
 * Whether a cadence is configured at all.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function gwcpp_type_cadence_is_set( string $post_type ): bool {
	return gwcpp_review_cadence( $post_type ) > 0;
}

/**
 * Today, in the site's own timezone.
 *
 * Deliberately not gmdate(). Every threshold here is a hard cutoff, so on a
 * site six hours behind UTC a "today" in UTC would expire entries most of a day
 * early — and the owner would have been told a date that had not arrived yet.
 *
 * @return DateTimeImmutable
 */
function gwcpp_review_today(): DateTimeImmutable {
	return new DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
}

/**
 * A Y-m-d string as a date, or null.
 *
 * @param string $ymd Date.
 * @return DateTimeImmutable|null
 */
function gwcpp_review_date( string $ymd ): ?DateTimeImmutable {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
		return null;
	}

	try {
		return new DateTimeImmutable( $ymd, wp_timezone() );
	} catch ( Exception $e ) {
		unset( $e );
		return null;
	}
}

/**
 * The whole review picture for one post.
 *
 * One helper that every consumer reads — the portal banner, the admin column,
 * the cron, the digest. The date arithmetic lives here and nowhere else, so the
 * badge somebody is shown and the decision the cron makes can never disagree.
 *
 * `stage` is pure date maths. `state` is what to show a human: the same thing,
 * unless the entry is exempt or has nobody who could review it, in which case
 * it can never actually reach expired.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function gwcpp_review_state( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array(
			'enabled' => false,
			'stage'   => 'current',
			'state'   => 'current',
		);
	}

	$cadence = gwcpp_review_cadence( $post->post_type );

	if ( $cadence < 1 ) {
		return array(
			'enabled' => false,
			'stage'   => 'current',
			'state'   => 'off',
		);
	}

	$reviewed_raw = (string) get_post_meta( $post_id, GWCPP_REVIEWED_META, true );
	$base         = gwcpp_review_date( $reviewed_raw );

	/* No review date yet — an entry staff created before the cycle was switched
	 * on, or one never touched from the portal. Its publish date is the honest
	 * answer to "when was this last known to be right?", and it means switching
	 * the cycle on does not instantly mark a site's whole directory as current. */
	if ( null === $base ) {
		$base         = gwcpp_review_date( gmdate( 'Y-m-d', (int) strtotime( $post->post_date ) ) );
		$reviewed_raw = '';
	}
	if ( null === $base ) {
		$base = gwcpp_review_today();
	}

	$today   = gwcpp_review_today();
	$due     = $base->modify( '+' . max( 1, $cadence - 1 ) . ' months' );
	$named   = $base->modify( '+' . $cadence . ' months' );
	$overdue = $base->modify( '+' . ( $cadence + 1 ) . ' months' );
	$expires = $base->modify( '+' . ( $cadence * 2 ) . ' months' );

	if ( $today >= $expires ) {
		$stage = 'expired';
	} elseif ( $today >= $overdue ) {
		$stage = 'overdue';
	} elseif ( $today >= $due ) {
		$stage = 'due';
	} else {
		$stage = 'current';
	}

	$exempt = (bool) get_post_meta( $post_id, GWCPP_REVIEW_EXEMPT_META, true );
	$hidden = (bool) get_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META, true );

	/* "Managed" means somebody exists who could actually review this. An entry
	 * nobody has been given access to has no owner to chase, and hiding it would
	 * punish a partner for a gap on the site's own side — so it escalates to
	 * staff in the digest instead. Capped here rather than in the cron so the
	 * admin column tells the same story the cron acts on. */
	$managed = gwcpp_post_has_owner( $post_id );

	$state = $stage;
	if ( $exempt ) {
		$state = 'exempt';
	} elseif ( ! $managed && 'expired' === $stage ) {
		$state = 'unmanaged';
	}

	return array(
		'enabled'     => true,
		'cadence'     => $cadence,
		'reviewed_at' => $reviewed_raw,
		'reviewed_by' => (int) get_post_meta( $post_id, GWCPP_REVIEWED_BY_META, true ),
		'basis'       => $base->format( 'Y-m-d' ),
		'due_on'      => $named->format( 'Y-m-d' ),
		'expires_on'  => $expires->format( 'Y-m-d' ),
		'days_left'   => (int) $today->diff( $expires )->days * ( $expires >= $today ? 1 : -1 ),
		'stage'       => $stage,
		'state'       => $state,
		'managed'     => $managed,
		'exempt'      => $exempt,
		'hidden'      => $hidden,
	);
}

/**
 * True when somebody could review this post.
 *
 * Asked of the access model rather than of a single owner pointer, because
 * access here can come from an organisation with several members, from a direct
 * grant, or from authorship.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_post_has_owner( int $post_id ): bool {
	if ( gwcpp_post_editors( $post_id ) ) {
		return true;
	}

	$org = gwcpp_post_org( $post_id );

	return $org > 0 && gwcpp_org_members( $org ) !== array();
}

/**
 * Everybody who could review a post.
 *
 * @param int $post_id Post ID.
 * @return int[] User IDs.
 */
function gwcpp_post_owners( int $post_id ): array {
	$ids = gwcpp_post_editors( $post_id );
	$org = gwcpp_post_org( $post_id );

	if ( $org > 0 ) {
		foreach ( gwcpp_org_members( $org ) as $member ) {
			$ids[] = (int) $member->ID;
		}
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Record that somebody confirmed an entry is right.
 *
 * @param int $post_id Post ID.
 * @param int $user_id Who confirmed it.
 */
function gwcpp_record_review( int $post_id, int $user_id = 0 ): void {
	update_post_meta( $post_id, GWCPP_REVIEWED_META, gwcpp_review_today()->format( 'Y-m-d' ) );
	update_post_meta( $post_id, GWCPP_REVIEWED_BY_META, $user_id );

	/* The ladder resets with the clock. Without this, an entry reviewed at month
	 * eleven would keep every rung it had already climbed and go silent for the
	 * whole of its next cycle. */
	delete_post_meta( $post_id, GWCPP_NOTICES_META );

	// Back on the site if the cycle was what took it off.
	if ( get_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META, true ) ) {
		gwcpp_review_republish( $post_id );
	}

	/**
	 * Fires when an entry is confirmed as current.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Who confirmed it.
	 */
	do_action( 'gwcpp_reviewed', $post_id, $user_id );
}

/* Saving from the portal is a review. Somebody who has just been through every
 * field and pressed submit has done more than the confirm button asks for, and
 * making them press it afterwards as well would be asking twice. */
add_action( 'gwcpp_fields_saved', 'gwcpp_review_on_save', 10, 1 );
add_action( 'gwcpp_changeset_stored', 'gwcpp_review_on_save', 10, 1 );

/**
 * Count a portal save as a review.
 *
 * Hooked to the changeset being STORED as well as to fields being saved,
 * because under approval those are different moments — and an entry should not
 * expire while a submission about it sits in a queue waiting for staff.
 *
 * @param int $post_id Post ID.
 */
function gwcpp_review_on_save( $post_id ): void {
	gwcpp_record_review( (int) $post_id, get_current_user_id() );
}

/* ── Hiding and restoring ────────────────────────────────────────────────── */

/**
 * Take an expired entry off the public site.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_review_expire( int $post_id ): bool {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'draft',
		)
	);

	/* The marker is what makes this reversible and attributable. Without it,
	 * six months later a draft entry is indistinguishable from one staff took
	 * down deliberately, and nothing knows to put it back when its owner
	 * finally confirms. */
	update_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META, time() );

	/**
	 * Fires when the cycle hides an entry.
	 *
	 * @param int $post_id Post ID.
	 */
	do_action( 'gwcpp_review_expired', $post_id );

	return true;
}

/**
 * Put back an entry the cycle hid.
 *
 * Only ever restores what the cycle itself took down. A post staff drafted by
 * hand has no marker and is left exactly where they put it.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_review_republish( int $post_id ): bool {
	if ( ! get_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META, true ) ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( $post instanceof WP_Post && 'draft' === $post->post_status ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
	}

	delete_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META );

	return true;
}

/* ── The notice ladder ───────────────────────────────────────────────────────
 * Each entry walks this once per cycle. The two date-derived rungs are counted
 * back from the expiry date rather than forward in months, because "thirty days
 * before it disappears" is the thing anybody actually needs to act on.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The rungs, as dates, soonest first.
 *
 * The runner takes the LAST rung that has passed, so an entry that arrives
 * already deep into the ladder — which every entry does on the day a site
 * switches the cycle on — gets one email rather than six.
 *
 * ── Sorted, because "soonest first" is not a property of how they are typed ──
 * Four rungs are counted forward from the basis in months and two backward from
 * expiry in days, and which of those lands first depends on the cadence. Listed
 * in the obvious reading order they interleave at short cadences: at a cadence
 * of one, staff_30 falls a month and a half BEFORE the entry is due; at two, it
 * lands on the same day as overdue.
 *
 * That mattered because the runner walked them in the order they were written.
 * A staff-only rung sitting last in the list won over the owner reminders that
 * had genuinely passed, was recorded as delivered, and so never came round
 * again — leaving a partner whose first and only warning was the one sent a
 * fortnight before their entry came off the site.
 *
 * Ties go to the owner: staff_30 is placed before any rung falling on the same
 * day, so "last passed" picks the message that goes to the person who can
 * actually act on it. Nobody should lose an entry having been told nothing.
 *
 * @param string $basis     Y-m-d the clock runs from.
 * @param int    $cadence   Months between reviews.
 * @return array<string, DateTimeImmutable>
 */
function gwcpp_review_ladder( string $basis, int $cadence ): array {
	$base = gwcpp_review_date( $basis );

	if ( null === $base || $cadence < 1 ) {
		return array();
	}

	$expires = $base->modify( '+' . ( $cadence * 2 ) . ' months' );

	$rungs = array(
		'due'      => $base->modify( '+' . max( 1, $cadence - 1 ) . ' months' ),
		'named'    => $base->modify( '+' . $cadence . ' months' ),
		'overdue'  => $base->modify( '+' . ( $cadence + 1 ) . ' months' ),
		'staff_30' => $expires->modify( '-30 days' ),
		'final_15' => $expires->modify( '-15 days' ),
		'expired'  => $expires,
	);

	$sortable = array();
	foreach ( $rungs as $rung => $date ) {
		$sortable[] = array(
			'rung' => $rung,
			'date' => $date,
			'tie'  => 'staff_30' === $rung ? 0 : 1,
		);
	}

	/* usort is stable in PHP 8, so rungs sharing both a date and a tiebreak keep
	 * the order above — which is why a cadence of one, where overdue and expired
	 * fall together, still reports expired. */
	usort(
		$sortable,
		static function ( array $a, array $b ): int {
			return ( $a['date'] <=> $b['date'] ) ?: ( $a['tie'] <=> $b['tie'] );
		}
	);

	$sorted = array();
	foreach ( $sortable as $item ) {
		$sorted[ $item['rung'] ] = $item['date'];
	}

	return $sorted;
}

/** Rungs that email the entry's owners. `staff_30` is deliberately absent — it
 *  goes to the site's staff, not to a partner. */
const GWCPP_OWNER_RUNGS = array( 'due', 'named', 'overdue', 'final_15', 'expired' );

/**
 * Rungs already delivered for a post.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function gwcpp_review_notices_sent( int $post_id ): array {
	$sent = get_post_meta( $post_id, GWCPP_NOTICES_META, true );

	return is_array( $sent ) ? array_map( 'strval', $sent ) : array();
}

/**
 * Record that a rung was delivered.
 *
 * @param int      $post_id Post ID.
 * @param string[] $sent    Rungs already recorded.
 * @param string[] $fresh   Rungs just delivered.
 */
function gwcpp_review_record_notices( int $post_id, array $sent, array $fresh ): void {
	if ( ! $fresh ) {
		return;
	}

	update_post_meta(
		$post_id,
		GWCPP_NOTICES_META,
		array_values( array_unique( array_merge( $sent, $fresh ) ) )
	);
}

/**
 * The rung an entry is standing on, and whether it has been delivered.
 *
 * @param array                  $state Review state.
 * @param string[]               $sent  Rungs already delivered.
 * @param DateTimeImmutable|null $today The day to judge against. Defaults to
 *                                      today, and exists so a test can walk a
 *                                      whole cycle a day at a time instead of
 *                                      inferring the ladder from one snapshot.
 * @return string The rung to send now, or '' when there is nothing to send.
 */
function gwcpp_review_due_rung( array $state, array $sent, ?DateTimeImmutable $today = null ): string {
	if ( empty( $state['enabled'] ) || ! empty( $state['exempt'] ) ) {
		return '';
	}

	$ladder = gwcpp_review_ladder( (string) $state['basis'], (int) $state['cadence'] );
	$today  = $today ?? gwcpp_review_today();

	$standing = '';
	foreach ( $ladder as $rung => $date ) {
		if ( $today >= $date ) {
			$standing = $rung;
		}
	}

	if ( '' === $standing || in_array( $standing, $sent, true ) ) {
		return '';
	}

	return $standing;
}

/* ── Scheduling ──────────────────────────────────────────────────────────────
 * Registered idempotently on init, matching gwcpp_ensure_role()'s "safe on
 * every load" pattern, so a site that loses its cron entries gets them back
 * without anyone deactivating anything.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action( 'init', 'gwcpp_schedule_review_events', 21 );
add_action( 'gwcpp_daily_review', 'gwcpp_run_daily_review' );
add_action( 'gwcpp_weekly_review_digest', 'gwcpp_run_weekly_digest' );
add_action( 'admin_init', 'gwcpp_review_catch_up' );

/**
 * Make sure both events exist.
 */
function gwcpp_schedule_review_events(): void {
	if ( ! wp_next_scheduled( 'gwcpp_daily_review' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gwcpp_daily_review' );
	}
	if ( ! wp_next_scheduled( 'gwcpp_weekly_review_digest' ) ) {
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'weekly', 'gwcpp_weekly_review_digest' );
	}
}

/**
 * Run the daily check from wp-admin if cron has not fired in a day and a half.
 *
 * WP-Cron only runs on traffic, and plenty of installs set DISABLE_WP_CRON with
 * no replacement system cron actually wired up — which is the situation this
 * exists for. Deliberately admin_init rather than a front-end hook, so a
 * visitor never pays for it.
 */
function gwcpp_review_catch_up(): void {
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$last = (int) get_option( 'gwcpp_review_last_run', 0 );
	if ( $last && ( time() - $last ) < 36 * HOUR_IN_SECONDS ) {
		return;
	}

	/* Hand the work to cron rather than doing it here, when there is a cron to
	 * hand it to. The daily run walks every tracked entry and then makes one
	 * SMTP round trip per owner with mail waiting — with a backlog due at once
	 * that is a wp-admin page which hangs for twenty seconds, or an FPM timeout
	 * that kills the run halfway and leaves the ladder half-advanced. Whoever
	 * opened wp-admin should not be the one paying for that.
	 *
	 * spawn_cron() fires a non-blocking loopback request, and the recurring
	 * event registered above is already overdue, so it runs there. The single
	 * event is only for the case where the recurring one has gone missing. */
	if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		if ( ! wp_next_scheduled( 'gwcpp_daily_review' ) ) {
			wp_schedule_single_event( time(), 'gwcpp_daily_review' );
		}
		spawn_cron();
		return;
	}

	// Nothing else will ever run it, so accept the stall.
	gwcpp_run_daily_review();
}

/** How many tracked entries are read from the database at a time. */
const GWCPP_REVIEW_PAGE_SIZE = 500;

/**
 * Every post the cycle tracks.
 *
 * ── Paged, because the cap used to decide which entries had a review cycle ───
 * This was one query for the first 500 in WordPress's default order, which is
 * newest first. On a directory larger than that, the entries never returned
 * were the oldest ones — which are precisely the ones most likely to have gone
 * stale, and the whole reason the cycle exists. They were never nudged, never
 * warned, never hidden and never listed in the weekly digest, and nothing
 * anywhere said so.
 *
 * Walked oldest ID first so the pages cannot shift underneath the run: the
 * cycle writes post meta and post status as it goes, so ordering by date or
 * modified time would let rows move between pages mid-walk.
 *
 * @return int[]
 */
function gwcpp_reviewable_post_ids(): array {
	$types = array_values( array_filter( gwcpp_post_types(), 'gwcpp_review_enabled' ) );

	if ( ! $types ) {
		return array();
	}

	$ids  = array();
	$page = 1;

	do {
		$found = get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => GWCPP_REVIEW_PAGE_SIZE,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		$found = is_array( $found ) ? $found : array();
		$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		++$page;
	} while ( count( $found ) === GWCPP_REVIEW_PAGE_SIZE );

	return $ids;
}

/**
 * The daily run.
 *
 * Walks every tracked entry, works out which rung it is standing on, batches
 * owner mail per person, and hides anything past expiry that had somebody who
 * could have prevented it.
 *
 * @return array{checked:int,mailed:int,expired:int}
 */
function gwcpp_run_daily_review(): array {
	update_option( 'gwcpp_review_last_run', time(), false );

	$batches     = array();
	$expired_now = array();
	$staff_soon  = array();
	$checked     = 0;

	foreach ( gwcpp_reviewable_post_ids() as $post_id ) {
		$post_id = (int) $post_id;
		++$checked;

		$state = gwcpp_review_state( $post_id );
		$sent  = gwcpp_review_notices_sent( $post_id );
		$rung  = gwcpp_review_due_rung( $state, $sent );

		if ( '' === $rung ) {
			continue;
		}

		/* Expiry happens here, before any mail, because the state the owner is
		 * told about should be the state that is true by the time they read it.
		 * An entry that is hidden and an email that says it is about to be are
		 * a contradiction somebody has to write a support ticket about. */
		if ( 'expired' === $rung && ! empty( $state['managed'] ) && empty( $state['exempt'] ) ) {
			if ( gwcpp_review_expire( $post_id ) ) {
				$expired_now[] = $post_id;
				// Re-read: the status changed underneath the state we captured.
				$state = gwcpp_review_state( $post_id );
			}
		}

		if ( 'staff_30' === $rung ) {
			$staff_soon[] = $post_id;
			gwcpp_review_record_notices( $post_id, $sent, array( $rung ) );
			continue;
		}

		if ( ! in_array( $rung, GWCPP_OWNER_RUNGS, true ) ) {
			continue;
		}

		foreach ( gwcpp_post_owners( $post_id ) as $user_id ) {
			$batches[ $user_id ][] = array(
				'post_id' => $post_id,
				'state'   => $state,
				'rung'    => $rung,
				'sent'    => $sent,
			);
		}
	}

	/* ── Rungs are recorded only once the message carrying them is away ──────
	 * An earlier design marked them in the loop above, before any owner mail
	 * was sent — and the two are separated by every remaining entry in the
	 * batch. A run that dies in between (entirely plausible: the catch-up above
	 * does all of this inside somebody's page load, with an SMTP round trip per
	 * owner) leaves those rungs recorded as delivered forever. The ladder never
	 * revisits a rung it believes it has sent, so a partner loses their final
	 * warning and has their entry hidden having been told nothing at all.
	 *
	 * A duplicate reminder is a far cheaper mistake than a silently skipped
	 * one, so the ordering errs in that direction.
	 * ─────────────────────────────────────────────────────────────────────── */
	$mailed = 0;
	foreach ( $batches as $user_id => $items ) {
		if ( ! gwcpp_review_mail_owner( (int) $user_id, $items ) ) {
			continue;
		}

		++$mailed;

		foreach ( $items as $item ) {
			gwcpp_review_record_notices( (int) $item['post_id'], $item['sent'], array( (string) $item['rung'] ) );
		}
	}

	if ( $staff_soon ) {
		gwcpp_review_mail_staff( $staff_soon, 'soon' );
	}
	if ( $expired_now ) {
		gwcpp_review_mail_staff( $expired_now, 'expired' );
	}

	// Review links live on the user rather than in transients, so nothing
	// expires them for us. Sweep the ones nobody clicked.
	gwcpp_purge_expired_durable_tokens();

	return array(
		'checked' => $checked,
		'mailed'  => $mailed,
		'expired' => count( $expired_now ),
	);
}

/**
 * The weekly digest: what staff need to look at themselves.
 *
 * Deliberately only the two things nobody else will fix — entries with no owner
 * to chase, and entries the cycle has already hidden. A digest listing
 * everything that is merely due is a digest people stop opening.
 *
 * @return array{unmanaged:int,hidden:int}
 */
function gwcpp_run_weekly_digest(): array {
	$unmanaged = array();
	$hidden    = array();

	foreach ( gwcpp_reviewable_post_ids() as $post_id ) {
		$state = gwcpp_review_state( (int) $post_id );

		if ( 'unmanaged' === $state['state'] ) {
			$unmanaged[] = (int) $post_id;
		} elseif ( ! empty( $state['hidden'] ) ) {
			$hidden[] = (int) $post_id;
		}
	}

	if ( $unmanaged || $hidden ) {
		gwcpp_review_mail_digest( $unmanaged, $hidden );
	}

	return array(
		'unmanaged' => count( $unmanaged ),
		'hidden'    => count( $hidden ),
	);
}

/* ── The emails ──────────────────────────────────────────────────────────────
 * One message per person, not per entry. A partner who looks after four
 * locations getting four separate emails on the same morning reads as spam, and
 * the fourth is the one they stop opening.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Ask somebody to confirm their entries.
 *
 * @param int   $user_id User ID.
 * @param array $items   Entries needing attention, from the daily run.
 * @return bool True only when the message actually went.
 */
function gwcpp_review_mail_owner( int $user_id, array $items ): bool {
	$user = get_userdata( $user_id );

	if ( ! $user || ! $items || ! gwcpp_user_is_portal_user( $user_id ) ) {
		return false;
	}

	/* The tone comes from the worst entry in the batch. Telling somebody three
	 * things are fine and one is about to disappear, in a message headed "a
	 * reminder", buries the only part that matters. */
	$worst = 'due';
	$order = array( 'due' => 1, 'named' => 2, 'overdue' => 3, 'final_15' => 4, 'expired' => 5 );
	foreach ( $items as $item ) {
		if ( ( $order[ $item['rung'] ] ?? 0 ) > ( $order[ $worst ] ?? 0 ) ) {
			$worst = (string) $item['rung'];
		}
	}

	$heading = array(
		'due'      => __( 'Is your information still right?', 'groundwork-common-post-portal' ),
		'named'    => __( 'Time to check your information', 'groundwork-common-post-portal' ),
		'overdue'  => __( 'Your information needs checking', 'groundwork-common-post-portal' ),
		'final_15' => __( 'Your entry comes off the site in two weeks', 'groundwork-common-post-portal' ),
		'expired'  => __( 'Your entry has come off the site', 'groundwork-common-post-portal' ),
	);

	$opening = array(
		'due'      => __( 'We ask everyone to check their details from time to time, so people are not sent to the wrong place. It only takes a moment.', 'groundwork-common-post-portal' ),
		'named'    => __( 'It has been a while since your details were checked. Please have a look and confirm they are still right.', 'groundwork-common-post-portal' ),
		'overdue'  => __( 'Your details have not been checked for some time. Please confirm them so people are not sent to the wrong place.', 'groundwork-common-post-portal' ),
		'final_15' => __( 'We have not been able to confirm your details, so your entry will stop being shown in two weeks. Confirming them now keeps it on the site.', 'groundwork-common-post-portal' ),
		'expired'  => __( 'Because we could not confirm your details, your entry is no longer being shown. Nothing has been deleted — confirming your details puts it straight back.', 'groundwork-common-post-portal' ),
	);

	/* A durable link rather than an ordinary sign-in one. These sit in inboxes
	 * for weeks and are stored on the user rather than in a transient, because
	 * a deploy running `wp transient delete --all` would otherwise kill every
	 * reminder link in every inbox at once, silently. */
	$url = gwcpp_portal_url( array( 'gwcpp_review_token' => gwcpp_mint_durable_token( $user_id, 'review' ) ) );

	$list = '';
	foreach ( $items as $item ) {
		$title = get_the_title( (int) $item['post_id'] );
		$state = $item['state'];

		$list .= sprintf(
			'<li style="margin:0 0 6px;font-size:15px;line-height:1.5;"><strong>%s</strong>%s</li>',
			esc_html( '' !== trim( (string) $title ) ? $title : __( '(no title)', 'groundwork-common-post-portal' ) ),
			! empty( $state['hidden'] )
				? ' — ' . esc_html__( 'not currently shown', 'groundwork-common-post-portal' )
				: ''
		);
	}

	$body = gwcpp_email_p( $opening[ $worst ] ?? $opening['due'] )
		. sprintf( '<ul style="margin:0 0 16px;padding-left:20px;">%s</ul>', $list )
		. gwcpp_email_button( $url, __( 'Check my details', 'groundwork-common-post-portal' ) )
		. gwcpp_email_raw_link( $url )
		. gwcpp_email_p( __( 'The link signs you in for a week. If it stops working, you can always ask for a new one from the portal.', 'groundwork-common-post-portal' ) );

	return gwcpp_send_email(
		$user->user_email,
		$heading[ $worst ] ?? $heading['due'],
		gwcpp_email_shell( $heading[ $worst ] ?? $heading['due'], $body )
	);
}

/**
 * Tell staff about entries approaching or past expiry.
 *
 * @param int[]  $post_ids Post IDs.
 * @param string $which    'soon' or 'expired'.
 * @return bool
 */
function gwcpp_review_mail_staff( array $post_ids, string $which ): bool {
	if ( ! $post_ids ) {
		return false;
	}

	$heading = 'expired' === $which
		? __( 'Entries have come off the site', 'groundwork-common-post-portal' )
		: __( 'Entries come off the site in 30 days', 'groundwork-common-post-portal' );

	$intro = 'expired' === $which
		? __( 'Nobody confirmed these, so they are no longer shown. Nothing has been deleted, and the owner can put any of them back by confirming their details.', 'groundwork-common-post-portal' )
		/* "are being reminded" rather than "have been": at a short cadence this
		 * rung can fall before the owner's first nudge, and a message telling
		 * staff somebody has already been chased when they have not is how a
		 * partner ends up blamed for ignoring an email nobody sent. */
		: __( 'These have not been confirmed and will stop being shown in 30 days. Their owners are being reminded.', 'groundwork-common-post-portal' );

	return gwcpp_send_email(
		gwcpp_staff_email(),
		$heading,
		gwcpp_email_shell( $heading, gwcpp_email_p( $intro ) . gwcpp_email_post_list( $post_ids ) )
	);
}

/**
 * The weekly digest.
 *
 * @param int[] $unmanaged Entries nobody can review.
 * @param int[] $hidden    Entries the cycle has hidden.
 * @return bool
 */
function gwcpp_review_mail_digest( array $unmanaged, array $hidden ): bool {
	$body = '';

	if ( $unmanaged ) {
		$body .= gwcpp_email_p( __( 'Nobody has been given access to these, so there is no one to ask. They will never be hidden automatically — somebody needs to invite an owner, or mark them as not needing review.', 'groundwork-common-post-portal' ) )
			. gwcpp_email_post_list( $unmanaged );
	}

	if ( $hidden ) {
		$body .= gwcpp_email_p( __( 'These are currently not shown, waiting for their owner to confirm their details.', 'groundwork-common-post-portal' ) )
			. gwcpp_email_post_list( $hidden );
	}

	if ( '' === $body ) {
		return false;
	}

	return gwcpp_send_email(
		gwcpp_staff_email(),
		__( 'Portal: entries needing your attention', 'groundwork-common-post-portal' ),
		gwcpp_email_shell( __( 'Entries needing your attention', 'groundwork-common-post-portal' ), $body )
	);
}

/**
 * A list of entries, each linked to its edit screen.
 *
 * @param int[] $post_ids Post IDs.
 * @return string
 */
function gwcpp_email_post_list( array $post_ids ): string {
	$items = '';

	// Bounded: a first run on a neglected directory can find hundreds, and a
	// mail client will truncate the message rather than scroll it.
	foreach ( array_slice( $post_ids, 0, 40 ) as $post_id ) {
		$title = get_the_title( (int) $post_id );

		$items .= sprintf(
			'<li style="margin:0 0 6px;font-size:15px;line-height:1.5;"><a href="%s">%s</a></li>',
			esc_url( (string) get_edit_post_link( (int) $post_id, 'raw' ) ),
			esc_html( '' !== trim( (string) $title ) ? $title : __( '(no title)', 'groundwork-common-post-portal' ) )
		);
	}

	$more = count( $post_ids ) - 40;
	if ( $more > 0 ) {
		$items .= sprintf(
			'<li style="margin:0 0 6px;font-size:15px;color:#7b8794;">%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: how many more entries there are. */
					_n( 'and %d more', 'and %d more', $more, 'groundwork-common-post-portal' ),
					$more
				)
			)
		);
	}

	return sprintf( '<ul style="margin:0 0 16px;padding-left:20px;">%s</ul>', $items );
}
