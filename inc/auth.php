<?php
/**
 * Sign-in: the portal page, magic links, rate limiting, and sessions.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/** How long a sign-in link works for. Short, because it is single-use and
 *  requesting another is one click. */
const GWCPP_TOKEN_TTL = 900;

/** The floor every sign-in request is padded to, in microseconds. */
const GWCPP_CONSTANT_TIME_FLOOR = 150000;

/* ── The portal page, and why its ID is pinned ───────────────────────────────
 * The page is found once by whatever the settings name, and the resolved ID is
 * written back into the settings. After that the ID is what everything uses.
 *
 * The reason is that every sign-in link ever sent points at a URL, and those
 * links sit in inboxes for weeks. Resolving the page by slug on each request
 * means renaming the page — or letting an editor "tidy up" its permalink —
 * silently breaks every link in every inbox at once, with no error anywhere and
 * no way for the person holding the link to know why it now shows a 404.
 * Pinning the ID means the URL follows the page.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The portal page's ID, or 0 when none is configured.
 *
 * @return int
 */
function gwcpp_portal_page_id(): int {
	$id = (int) gwcpp_setting( 'portal_page' );

	if ( $id > 0 && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
		return $id;
	}

	return 0;
}

/**
 * The portal's URL.
 *
 * Falls back to the site's home page rather than to '' — every caller of this
 * is building a redirect, and redirecting to an empty string sends the browser
 * to the current URL, which for a failed guard means a redirect loop.
 *
 * @param array $args Query arguments to add.
 * @return string
 */
function gwcpp_portal_url( array $args = array() ): string {
	$id  = gwcpp_portal_page_id();
	$url = $id > 0 ? (string) get_permalink( $id ) : home_url( '/' );

	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * True when the current request is the portal page.
 *
 * The is_page() short-circuit is first because this runs on template_redirect
 * for every front-end request on the site, and a single-post or archive request
 * can answer "no" without touching the settings.
 *
 * @return bool
 */
function gwcpp_is_portal(): bool {
	if ( ! is_page() ) {
		return false;
	}

	$id = gwcpp_portal_page_id();

	return $id > 0 && is_page( $id );
}

/* ── Sessions ────────────────────────────────────────────────────────────── */

add_filter( 'auth_cookie_expiration', 'gwcpp_session_length', 10, 3 );
add_filter( 'allow_password_reset', 'gwcpp_allow_password_reset', 10, 2 );

/**
 * Shorten the session for portal users.
 *
 * The archetypal portal user is on a shared front-desk machine at an
 * organisation with one login between six people, and "remember me" on that
 * machine means the next person to sit down is signed in as them.
 *
 * @param int  $length      Length in seconds.
 * @param int  $user_id     User ID.
 * @param bool $remember_me Whether the box was ticked.
 * @return int
 */
function gwcpp_session_length( $length, $user_id, $remember_me ) {
	unset( $remember_me );

	if ( gwcpp_user_is_portal_user( (int) $user_id ) ) {
		return gwcpp_session_seconds();
	}

	return $length;
}

/**
 * No password resets for accounts that have no password.
 *
 * A provisioned account's password is 64 random characters nobody has ever
 * seen. Offering a reset for it produces an email that lets somebody set one,
 * which is a second credential for an account whose whole design is that it has
 * none — unless the site has deliberately turned password sign-in on, in which
 * case a reset is exactly the right thing to offer.
 *
 * @param bool $allow   Whether to allow it.
 * @param int  $user_id User ID.
 * @return bool
 */
function gwcpp_allow_password_reset( $allow, $user_id ) {
	if ( ! gwcpp_user_is_portal_user( (int) $user_id ) ) {
		return $allow;
	}

	return (bool) gwcpp_setting( 'signin_password' );
}

/* ── Tokens ──────────────────────────────────────────────────────────────── */

/**
 * Mint a sign-in token for a user.
 *
 * Stored under a SHA-256 of itself, so the options table never holds a value
 * that could be used to sign in. Read access to the database — a leaked backup,
 * an unrelated SQL injection, a hosting support ticket — then yields hashes
 * rather than working links.
 *
 * @param int    $user_id User ID.
 * @param string $purpose What the token is for, kept in the payload so a
 *                        sign-in link cannot be replayed as something else.
 * @return string The token to put in a URL.
 */
function gwcpp_mint_token( int $user_id, string $purpose = 'signin' ): string {
	$token = bin2hex( random_bytes( 32 ) );

	/**
	 * How long a sign-in link lasts, in seconds.
	 *
	 * @param int $ttl Seconds.
	 */
	$ttl = (int) apply_filters( 'gwcpp_token_ttl', GWCPP_TOKEN_TTL );
	$ttl = max( 60, min( DAY_IN_SECONDS, $ttl ) );

	set_transient(
		'gwcpp_tok_' . hash( 'sha256', $token ),
		array(
			'user'    => $user_id,
			'purpose' => $purpose,
		),
		$ttl
	);

	return $token;
}

/**
 * Spend a token, and sign in the user it names.
 *
 * Single use: the transient is deleted before anything else happens, so a link
 * that is fetched twice — a mail client prefetching, a user double-clicking,
 * somebody with the link after the fact — works exactly once.
 *
 * @param string $token   Token from the URL.
 * @param string $purpose Expected purpose.
 * @return int User ID on success, 0 otherwise.
 */
function gwcpp_consume_token( string $token, string $purpose = 'signin' ): int {
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		return 0;
	}

	$key     = 'gwcpp_tok_' . hash( 'sha256', $token );
	$payload = get_transient( $key );
	delete_transient( $key );

	if ( ! is_array( $payload ) || ( $payload['purpose'] ?? '' ) !== $purpose ) {
		return 0;
	}

	$user_id = (int) ( $payload['user'] ?? 0 );
	if ( $user_id <= 0 ) {
		return 0;
	}

	$user = get_userdata( $user_id );
	if ( ! $user || ! $user->exists() ) {
		return 0;
	}

	/* Re-checked here rather than trusted from the token. A link minted three
	 * days ago names a user who may since have had their role changed or their
	 * access revoked, and the token itself cannot know that. */
	if ( ! gwcpp_user_is_portal_user( $user_id ) ) {
		return 0;
	}

	wp_set_auth_cookie( $user_id, false );
	wp_set_current_user( $user_id );

	/* wp_set_auth_cookie does not fire wp_login — that is wp_signon's job — and
	 * plenty of things listen for it: security logs, last-seen timestamps,
	 * two-factor plugins. Firing it manually keeps a magic-link sign-in
	 * indistinguishable from any other, which is what those listeners assume. */
	do_action( 'wp_login', $user->user_login, $user );

	return $user_id;
}

/* ── Durable tokens ──────────────────────────────────────────────────────────
 * Sign-in links live in a transient for fifteen minutes, which is right: they
 * are minted on demand and requesting another costs nothing.
 *
 * Review reminders are not like that. They are sent by cron, they sit in an
 * inbox for weeks, and the person receiving one did not ask for it — so when
 * they finally click, there is no "ask for a new one" they were expecting to
 * need. A transient is the wrong home for those, and not because of the TTL:
 * `wp transient delete --all` is a routine deploy step and a standard first
 * move when debugging a caching problem, and running it would silently
 * invalidate every reminder link in every inbox at once. An external object
 * cache evicting under memory pressure does the same thing.
 *
 * So these live in user meta, where nothing sweeps them but us, and they carry
 * their own expiry because that means we have to sweep them ourselves — which
 * the daily review run does.
 * ─────────────────────────────────────────────────────────────────────────── */

/** User meta, single: durable tokens, keyed by hash. */
const GWCPP_TOKENS_META = '_gwcpp_tokens';

/** How long a review-reminder link lasts. */
const GWCPP_DURABLE_TTL = 7 * DAY_IN_SECONDS;

/**
 * Mint a token that survives a cache flush.
 *
 * @param int    $user_id User ID.
 * @param string $purpose What it is for.
 * @param int    $ttl     Lifetime in seconds.
 * @return string
 */
function gwcpp_mint_durable_token( int $user_id, string $purpose = 'review', int $ttl = GWCPP_DURABLE_TTL ): string {
	$token = bin2hex( random_bytes( 32 ) );

	$stored = get_user_meta( $user_id, GWCPP_TOKENS_META, true );
	$stored = is_array( $stored ) ? $stored : array();

	/* Only the hash is kept, exactly as with the transient tokens: read access
	 * to the database should yield hashes rather than working links. */
	$stored[ hash( 'sha256', $token ) ] = array(
		'purpose' => $purpose,
		'expires' => time() + max( MINUTE_IN_SECONDS, $ttl ),
	);

	/* Bounded per user. A partner who is reminded about four entries every week
	 * for a year would otherwise accumulate a meta row nobody ever looks at,
	 * and the oldest are the ones already expired. */
	if ( count( $stored ) > 20 ) {
		uasort(
			$stored,
			static function ( $a, $b ) {
				return (int) ( $b['expires'] ?? 0 ) <=> (int) ( $a['expires'] ?? 0 );
			}
		);
		$stored = array_slice( $stored, 0, 20, true );
	}

	update_user_meta( $user_id, GWCPP_TOKENS_META, $stored );

	return $token;
}

/**
 * Spend a durable token and sign its user in.
 *
 * @param string $token   Token from the URL.
 * @param string $purpose Expected purpose.
 * @return int User ID, or 0.
 */
function gwcpp_consume_durable_token( string $token, string $purpose = 'review' ): int {
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		return 0;
	}

	$hash = hash( 'sha256', $token );

	/* The token does not name its user, so the user has to be found by it. A
	 * meta_query on the serialized array is the only way, and it is a LIKE — but
	 * on a 64-character hex hash, which cannot collide with anything and cannot
	 * be a prefix of another hash. */
	$users = get_users(
		array(
			'number'     => 2,
			'meta_key'   => GWCPP_TOKENS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The token does not carry its user; a hash lookup is the only route.
			'meta_value' => $hash,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			'meta_compare' => 'LIKE',
			'fields'     => 'ID',
		)
	);

	if ( count( $users ) !== 1 ) {
		return 0;
	}

	$user_id = (int) $users[0];
	$stored  = get_user_meta( $user_id, GWCPP_TOKENS_META, true );
	$stored  = is_array( $stored ) ? $stored : array();

	$entry = $stored[ $hash ] ?? null;

	// Single use, whatever happens next.
	unset( $stored[ $hash ] );
	update_user_meta( $user_id, GWCPP_TOKENS_META, $stored );

	if ( ! is_array( $entry ) || ( $entry['purpose'] ?? '' ) !== $purpose ) {
		return 0;
	}
	if ( (int) ( $entry['expires'] ?? 0 ) < time() ) {
		return 0;
	}
	if ( ! gwcpp_user_is_portal_user( $user_id ) ) {
		return 0;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return 0;
	}

	wp_set_auth_cookie( $user_id, false );
	wp_set_current_user( $user_id );
	do_action( 'wp_login', $user->user_login, $user );

	return $user_id;
}

/**
 * Drop durable tokens nobody clicked.
 *
 * Called from the daily review run, because nothing else will: these are the
 * one kind of token in the plugin with no storage layer expiring them.
 *
 * @return int How many were dropped.
 */
function gwcpp_purge_expired_durable_tokens(): int {
	$users = get_users(
		array(
			'number'     => 500,
			'meta_key'   => GWCPP_TOKENS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- EXISTS on an indexed key, in cron.
			'meta_compare' => 'EXISTS',
			'fields'     => 'ID',
		)
	);

	$now     = time();
	$dropped = 0;

	foreach ( $users as $user_id ) {
		$stored = get_user_meta( (int) $user_id, GWCPP_TOKENS_META, true );
		if ( ! is_array( $stored ) ) {
			continue;
		}

		$kept = array();
		foreach ( $stored as $hash => $entry ) {
			if ( is_array( $entry ) && (int) ( $entry['expires'] ?? 0 ) >= $now ) {
				$kept[ $hash ] = $entry;
				continue;
			}
			++$dropped;
		}

		if ( count( $kept ) === count( $stored ) ) {
			continue;
		}

		if ( $kept ) {
			update_user_meta( (int) $user_id, GWCPP_TOKENS_META, $kept );
		} else {
			delete_user_meta( (int) $user_id, GWCPP_TOKENS_META );
		}
	}

	return $dropped;
}

/* ── Rate limiting ───────────────────────────────────────────────────────────
 * Three fixed windows in one non-autoloaded option: by address, by email, and
 * site-wide.
 *
 * Fixed windows, not sliding. A sliding window needs the timestamp of every
 * request in the period, which is unbounded storage driven by unauthenticated
 * traffic — precisely the thing being defended against. A fixed window lets
 * somebody send two windows' worth of requests across a boundary, which for
 * "how many emails can be sent to one address" is an acceptable worst case and
 * for "how many rows can an attacker make us store" is the difference between
 * three and however many they like.
 *
 * The email counter is keyed by a SHA-256 of the address, and the address
 * itself is never stored. The option is world-readable to anything with
 * database access, and a list of every address that ever tried to sign in is a
 * list worth not keeping.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The three windows: attempts allowed, and the period in seconds.
 *
 * @return array<string, array{limit:int, window:int}>
 */
function gwcpp_rate_limits(): array {
	/**
	 * Sign-in rate limits.
	 *
	 * @param array $limits Keyed by scope.
	 */
	return (array) apply_filters(
		'gwcpp_rate_limits',
		array(
			// Generous: one office behind one NAT is many people.
			'ip'     => array(
				'limit'  => 20,
				'window' => 15 * MINUTE_IN_SECONDS,
			),
			// Tight: nobody needs a fourth link in an hour, and this is the
			// counter that stops the form being used to mailbomb somebody.
			'email'  => array(
				'limit'  => 3,
				'window' => HOUR_IN_SECONDS,
			),
			// The backstop, for a distributed attempt that beats the other two.
			'global' => array(
				'limit'  => 30,
				'window' => HOUR_IN_SECONDS,
			),
		)
	);
}

/**
 * Count this attempt, and say whether it is over a limit.
 *
 * Counts first and reports second, deliberately: an attempt that is refused
 * still counts, or a client that ignores the refusal gets unlimited free tries
 * as soon as it crosses the line once.
 *
 * @param string $email Email address being requested.
 * @return bool True when the request should be refused.
 */
function gwcpp_rate_limited( string $email ): bool {
	$limits = gwcpp_rate_limits();
	$now    = time();
	$state  = get_option( 'gwcpp_rate_limits' );
	$state  = is_array( $state ) ? $state : array();

	$keys = array(
		'ip'     => hash( 'sha256', gwcpp_client_ip() ),
		'email'  => hash( 'sha256', strtolower( $email ) ),
		'global' => 'all',
	);

	$over = false;

	foreach ( $keys as $scope => $key ) {
		$limit  = (int) ( $limits[ $scope ]['limit'] ?? 0 );
		$window = (int) ( $limits[ $scope ]['window'] ?? HOUR_IN_SECONDS );

		if ( $limit <= 0 ) {
			continue;
		}

		$entry = $state[ $scope ][ $key ] ?? array(
			'start' => 0,
			'count' => 0,
		);
		$entry = is_array( $entry ) ? $entry : array(
			'start' => 0,
			'count' => 0,
		);

		if ( $now - (int) ( $entry['start'] ?? 0 ) >= $window ) {
			$entry = array(
				'start' => $now,
				'count' => 0,
			);
		}

		++$entry['count'];
		$state[ $scope ][ $key ] = $entry;

		if ( (int) $entry['count'] > $limit ) {
			$over = true;
		}
	}

	$state = gwcpp_prune_rate_state( $state, $limits, $now );

	update_option( 'gwcpp_rate_limits', $state, false );

	return $over;
}

/**
 * Drop expired counters.
 *
 * Without this the option grows one row per distinct address forever, and an
 * autoloaded-size problem eventually becomes a site-speed problem. Pruning on
 * write means the work happens on the same unauthenticated request that caused
 * it rather than in cron, which is the right place for it: an attacker filling
 * the table is also the one paying to clean it.
 *
 * @param array $state  Current state.
 * @param array $limits Window configuration.
 * @param int   $now    Timestamp.
 * @return array
 */
function gwcpp_prune_rate_state( array $state, array $limits, int $now ): array {
	foreach ( $state as $scope => $entries ) {
		if ( ! is_array( $entries ) ) {
			unset( $state[ $scope ] );
			continue;
		}

		$window = (int) ( $limits[ $scope ]['window'] ?? HOUR_IN_SECONDS );

		foreach ( $entries as $key => $entry ) {
			$start = is_array( $entry ) ? (int) ( $entry['start'] ?? 0 ) : 0;
			if ( $now - $start >= $window ) {
				unset( $state[ $scope ][ $key ] );
			}
		}

		/* A hard ceiling as well as an expiry. Pruning by age alone still lets
		 * a burst inside one window put a hundred thousand rows in one option,
		 * and that option is read on every sign-in attempt. Dropping the whole
		 * scope is the right failure: it resets counters, which is worse than
		 * keeping them and far better than an option too large to load. */
		if ( isset( $state[ $scope ] ) && count( $state[ $scope ] ) > 5000 ) {
			$state[ $scope ] = array();
		}
	}

	return $state;
}

/**
 * The client's address.
 *
 * REMOTE_ADDR only. Every forwarded-for header is attacker-controlled unless
 * the site's proxy is known to overwrite it, and this plugin cannot know that.
 * Trusting one would let an attacker defeat the per-address limit entirely by
 * sending a different value each time — which is worse than the honest failure
 * here, where everybody behind one reverse proxy shares a counter.
 *
 * @return string
 */
function gwcpp_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
}

/* ── Sign-in handlers ────────────────────────────────────────────────────── */

/**
 * Handle a request for a sign-in link.
 *
 * ── Why this is written so carefully ─────────────────────────────────────────
 * The form takes an email address and says whether something happened. Done
 * naively, that is an account-existence oracle: type an address, and a
 * different message — or a measurably different response time, because sending
 * mail takes hundreds of milliseconds and not sending it takes none — tells you
 * whether that person has an account on this site.
 *
 * So: the same message either way, a floor on the elapsed time, and the
 * response flushed to the browser before the mail is sent, so the send's
 * duration is not observable at all.
 */
function gwcpp_handle_link_request(): void {
	$start = microtime( true );

	if (
		! isset( $_POST['gwcpp_signin_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_signin_nonce'] ) ), 'gwcpp_signin' )
	) {
		gwcpp_bail( gwcpp_portal_url(), GWCPP_STALE_FORM );
	}

	/* A hidden field a person never sees and a bot fills in. Cheap, silent, and
	 * it catches the overwhelming majority of automated submissions without
	 * asking a human to prove anything. */
	$honeypot = isset( $_POST['gwcpp_website'] ) ? trim( (string) wp_unslash( $_POST['gwcpp_website'] ) ) : '';

	$email = isset( $_POST['gwcpp_email'] )
		? sanitize_email( trim( (string) wp_unslash( $_POST['gwcpp_email'] ) ) )
		: '';

	$limited = '' !== $email && gwcpp_rate_limited( $email );
	$send    = '' === $honeypot && '' !== $email && is_email( $email ) && ! $limited;

	$user = $send ? get_user_by( 'email', $email ) : false;
	$send = $send && $user instanceof WP_User && gwcpp_user_is_portal_user( $user->ID );

	/* The same words whether or not an account was found, whether or not the
	 * honeypot caught it, and whether or not the rate limiter refused. Every
	 * one of those is information about somebody else's account. */
	$message = __( 'If that address has portal access, a sign-in link is on its way. It works once and expires in fifteen minutes.', 'groundwork-common-post-portal' );

	gwcpp_flush_response( gwcpp_flash_url( gwcpp_portal_url(), 'ok', $message ), $start );

	if ( $send && $user instanceof WP_User ) {
		gwcpp_send_magic_link( $user );
	}

	exit;
}

/**
 * Pad to the constant-time floor, redirect, and let the browser go.
 *
 * fastcgi_finish_request() sends the response and returns, leaving PHP running.
 * Anything after this call costs the visitor nothing — which is what makes the
 * mail send unobservable rather than merely padded.
 *
 * Where it is unavailable (mod_php, some FPM configurations), the padding above
 * is still doing its job; the send time is then visible, but it is visible
 * identically for the "found" and "not found" cases only if a send happened in
 * both. It did not, so on those hosts the floor is the whole defence — which is
 * why the floor is applied to every path rather than only to the slow one.
 *
 * @param string $url   Where to send the browser.
 * @param float  $start microtime(true) at the top of the handler.
 */
function gwcpp_flush_response( string $url, float $start ): void {
	$elapsed = (int) ( ( microtime( true ) - $start ) * 1000000 );
	if ( $elapsed < GWCPP_CONSTANT_TIME_FLOOR ) {
		usleep( GWCPP_CONSTANT_TIME_FLOOR - $elapsed );
	}

	wp_safe_redirect( $url );

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
}

/**
 * Email somebody a sign-in link.
 *
 * @param WP_User $user The user.
 * @return bool
 */
function gwcpp_send_magic_link( WP_User $user ): bool {
	$url = gwcpp_portal_url( array( 'gwcpp_token' => gwcpp_mint_token( $user->ID ) ) );

	$body = gwcpp_email_shell(
		__( 'Your sign-in link', 'groundwork-common-post-portal' ),
		gwcpp_email_p( __( 'Click below to sign in. The link works once and expires in fifteen minutes.', 'groundwork-common-post-portal' ) )
		. gwcpp_email_button( $url, __( 'Sign in', 'groundwork-common-post-portal' ) )
		. gwcpp_email_raw_link( $url )
		. gwcpp_email_p( __( 'If you did not ask for this, you can ignore it. Nobody can sign in without the link.', 'groundwork-common-post-portal' ) )
	);

	return gwcpp_send_email(
		$user->user_email,
		sprintf(
			/* translators: %s: the site name. */
			__( 'Your sign-in link for %s', 'groundwork-common-post-portal' ),
			get_bloginfo( 'name' )
		),
		$body
	);
}

/**
 * Handle a magic link arriving.
 */
function gwcpp_handle_magic_link(): void {
	$token = isset( $_GET['gwcpp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['gwcpp_token'] ) ) : '';

	/* Automated fetches are refused before the token is spent. A mail client
	 * that prefetches links, a scanner in a corporate gateway, or a chat app
	 * generating a preview would otherwise burn a single-use link before the
	 * person ever clicked it — and the symptom is "the link says it expired the
	 * moment I opened it", which is unreportable and impossible to reproduce. */
	if ( gwcpp_request_is_automated() ) {
		return;
	}

	$user_id = gwcpp_consume_token( $token );

	if ( $user_id <= 0 ) {
		wp_safe_redirect(
			gwcpp_flash_url(
				gwcpp_portal_url(),
				'warn',
				__( 'That sign-in link has expired or has already been used. Please ask for a new one.', 'groundwork-common-post-portal' )
			)
		);
		exit;
	}

	// Redirect rather than render, so the token is out of the address bar
	// before anything is displayed — and out of the browser history, and out of
	// the Referer header of every asset the page loads.
	wp_safe_redirect( gwcpp_portal_url() );
	exit;
}

/**
 * True for a request that is a machine rather than a person.
 *
 * @return bool
 */
function gwcpp_request_is_automated(): bool {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'HEAD' === $method ) {
		return true;
	}

	$purpose = '';
	foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
		if ( isset( $_SERVER[ $header ] ) ) {
			$purpose .= strtolower( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) . ' ';
		}
	}

	return (bool) preg_match( '/prefetch|preview|prerender/', $purpose );
}

/**
 * Handle an ordinary username-and-password sign-in.
 */
function gwcpp_handle_password_login(): void {
	if (
		! isset( $_POST['gwcpp_login_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_login_nonce'] ) ), 'gwcpp_login' )
	) {
		gwcpp_bail( gwcpp_portal_url(), GWCPP_STALE_FORM );
	}

	if ( ! gwcpp_setting( 'signin_password' ) ) {
		gwcpp_bail( gwcpp_portal_url() );
	}

	$user = wp_signon(
		array(
			// Not sanitized, and not unslashed: wp_signon compares the password
			// byte for byte against a hash, so anything done to it here is a
			// silent authentication failure for passwords containing quotes.
			'user_login'    => isset( $_POST['gwcpp_user'] ) ? sanitize_user( wp_unslash( $_POST['gwcpp_user'] ) ) : '',
			'user_password' => isset( $_POST['gwcpp_pass'] ) ? (string) $_POST['gwcpp_pass'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- A password must reach wp_signon() unmodified.
			'remember'      => false,
		),
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		/* One message for every failure. WordPress's own errors distinguish
		 * "unknown username" from "incorrect password", which on a portal is an
		 * account-existence oracle for the same reason the link form is. */
		gwcpp_bail(
			gwcpp_portal_url(),
			__( 'That username and password did not match. Please try again.', 'groundwork-common-post-portal' ),
			'warn'
		);
	}

	wp_safe_redirect( gwcpp_portal_url() );
	exit;
}

/**
 * Handle signing out.
 */
function gwcpp_handle_logout(): void {
	if (
		! isset( $_POST['gwcpp_logout_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_logout_nonce'] ) ), 'gwcpp_logout' )
	) {
		gwcpp_bail( gwcpp_portal_url() );
	}

	wp_logout();

	wp_safe_redirect(
		gwcpp_flash_url(
			gwcpp_portal_url(),
			'ok',
			__( 'You are signed out.', 'groundwork-common-post-portal' )
		)
	);
	exit;
}

/* ── Flash messages ──────────────────────────────────────────────────────────
 * A message survives a redirect as a one-use transient named by a random key,
 * and the URL carries only the key.
 *
 * The obvious alternative — ?message=saved, or worse ?message=<the+text> — has
 * two problems. The tame one is that a bookmarked or shared URL shows a stale
 * message forever. The real one is that any URL parameter rendered onto the
 * page is a URL somebody else can construct: put the text in the query string
 * and the portal will cheerfully display "Your account has been suspended,
 * call this number" to anyone who follows a crafted link.
 *
 * A key that indexes server-side text cannot be forged into new text, and
 * deleting on read means it shows once.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Shown when a nonce has expired, which is the one guard failure that is
 *  nobody's fault: WordPress nonces last a day, and somebody who left the
 *  portal open overnight has done nothing wrong. */
const GWCPP_STALE_FORM = 'That form had been open too long to submit safely, so nothing was saved. Please make your change again.';

/**
 * Store a flash message and return the URL that will show it.
 *
 * @param string $url  Destination.
 * @param string $type ok | warn | error.
 * @param string $text Message.
 * @return string
 */
function gwcpp_flash_url( string $url, string $type, string $text ): string {
	if ( '' === $text ) {
		return $url;
	}

	$key = bin2hex( random_bytes( 16 ) );

	set_transient(
		'gwcpp_flash_' . $key,
		array(
			'type' => in_array( $type, array( 'ok', 'warn', 'error' ), true ) ? $type : 'ok',
			'text' => $text,
		),
		10 * MINUTE_IN_SECONDS
	);

	return add_query_arg( 'gwcpp_flash', $key, $url );
}

/**
 * Read and clear the current request's flash message.
 *
 * @return array{type:string,text:string}|null
 */
function gwcpp_flash(): ?array {
	static $flash = null;
	static $read  = false;

	if ( $read ) {
		return $flash;
	}
	$read = true;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only lookup of a random server-minted key; there is no action to forge.
	$key = isset( $_GET['gwcpp_flash'] ) ? sanitize_text_field( wp_unslash( $_GET['gwcpp_flash'] ) ) : '';

	if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
		return null;
	}

	$stored = get_transient( 'gwcpp_flash_' . $key );
	delete_transient( 'gwcpp_flash_' . $key );

	if ( ! is_array( $stored ) || ! isset( $stored['text'] ) ) {
		return null;
	}

	$flash = array(
		'type' => (string) ( $stored['type'] ?? 'ok' ),
		'text' => (string) $stored['text'],
	);

	return $flash;
}
