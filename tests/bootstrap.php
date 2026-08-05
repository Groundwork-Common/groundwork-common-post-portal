<?php
/**
 * Test bootstrap.
 *
 * ── No database, no WordPress checkout ───────────────────────────────────────
 * The logic worth unit testing here — the access decision, the validator, the
 * schema's self-healing, the rate limiter's windows — is pure. Making it depend
 * on a WordPress test install would mean a suite that takes a minute to start,
 * cannot run on a laptop without MySQL, and gets skipped.
 *
 * So this file stubs the WordPress surface those functions touch: an in-memory
 * option, meta and transient store, the escaping and sanitizing helpers, and a
 * small post and user double. Every stub is deliberately the SIMPLEST thing
 * that behaves correctly for the cases under test, and where a stub is weaker
 * than the real function that is noted beside it — a test that passes against a
 * stub which is more permissive than WordPress is a test that proves nothing.
 *
 * Anything that genuinely needs WordPress — the meta boxes, the block, the
 * redirects, the mail — is covered by the integration scripts under
 * tests/integration/, which run under wp-env.
 *
 * @package PostPortal
 */

define( 'ABSPATH', __DIR__ . '/' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MB_IN_BYTES', 1048576 );

define( 'GWCPP_DIR', dirname( __DIR__ ) . '/' );
define( 'GWCPP_URL', 'https://example.test/wp-content/plugins/groundwork-common-post-portal/' );
define( 'GWCPP_FILE', GWCPP_DIR . 'groundwork-common-post-portal.php' );

/* Read out of the plugin header rather than hardcoded, so VersionTest is
 * comparing the header against readme.txt rather than against a copy of one of
 * them made in this file. */
const GWCPP_SCHEMA_VERSION = 1;
const GWCPP_SPONSOR_URL    = 'https://www.groundworkcommon.com/support/';
const GWCPP_GWC_URL        = 'https://www.groundworkcommon.com/';

$gwcpp_header = (string) file_get_contents( GWCPP_DIR . 'groundwork-common-post-portal.php' );
preg_match( "/GWCPP_VERSION\s*=\s*'([^']+)'/", $gwcpp_header, $gwcpp_m );
define( 'GWCPP_VERSION', $gwcpp_m[1] ?? '0.0.0' );

/* ── The in-memory store ─────────────────────────────────────────────────── */

$GLOBALS['gwcpp_test'] = array(
	'options'    => array(),
	'transients' => array(),
	'post_meta'  => array(),
	'user_meta'  => array(),
	'posts'      => array(),
	'users'      => array(),
	'types'      => array( 'post', 'page', 'gwcpp_org' ),
	'taxonomies' => array(),
	'terms'      => array(),
);

/**
 * Reset everything between tests.
 *
 * Also clears the two function-static memos, which are otherwise the single
 * most common source of a test that passes alone and fails in a suite.
 */
function gwcpp_test_reset(): void {
	$GLOBALS['gwcpp_test']['options']    = array();
	$GLOBALS['gwcpp_test']['transients'] = array();
	$GLOBALS['gwcpp_test']['post_meta']  = array();
	$GLOBALS['gwcpp_test']['user_meta']  = array();
	$GLOBALS['gwcpp_test']['posts']      = array();
	$GLOBALS['gwcpp_test']['users']      = array();
	$GLOBALS['gwcpp_test']['types']      = array( 'post', 'page', 'gwcpp_org' );
	$GLOBALS['gwcpp_test']['taxonomies'] = array();
	$GLOBALS['gwcpp_test']['terms']      = array();

	gwcpp_settings_cache( null, true );
	gwcpp_schema_cache( null, true );
	wp_cache_flush_group( 'gwcpp_access' );
}

/* A real class, not a stdClass, because the plugin's guards are written as
 * `$post instanceof WP_Post` — a duck-typed double would make every one of them
 * pass vacuously in tests and fail on a site. */
class WP_Post { // phpcs:ignore
	public $ID           = 0;
	public $post_type    = 'post';
	public $post_status  = 'publish';
	public $post_author  = 0;
	public $post_title   = '';
	public $post_excerpt = '';
	public $post_content = '';
	/* The review cycle counts from this when an entry has never been confirmed.
	 * Omitting it from the double produced an undefined-property warning and a
	 * strtotime(null) deprecation that no real site could ever hit — the double
	 * was wrong, not the code reading it. */
	public $post_date    = '';
}

/** The shape of the error object the provisioning path returns. */
class WP_Error { // phpcs:ignore
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Register a fake post.
 *
 * @param int    $id     Post ID.
 * @param string $type   Post type.
 * @param string $status Post status.
 * @param int    $author Author ID.
 * @param string $title  Title.
 */
function gwcpp_test_post( int $id, string $type = 'post', string $status = 'publish', int $author = 0, string $title = 'Test' ): void {
	$post              = new WP_Post();
	$post->ID          = $id;
	$post->post_type   = $type;
	$post->post_status = $status;
	$post->post_author = $author;
	$post->post_title  = $title;
	$post->post_date   = gmdate( 'Y-m-d H:i:s' );

	$GLOBALS['gwcpp_test']['posts'][ $id ] = $post;

	if ( ! in_array( $type, $GLOBALS['gwcpp_test']['types'], true ) ) {
		$GLOBALS['gwcpp_test']['types'][] = $type;
	}
}

/**
 * Register a fake user.
 *
 * @param int      $id    User ID.
 * @param string[] $roles Roles.
 */
function gwcpp_test_user( int $id, array $roles = array( 'gwcpp_portal_user' ) ): void {
	$GLOBALS['gwcpp_test']['users'][ $id ] = new GWCPP_Test_User( $id, $roles );
}

/** A stand-in for WP_User, with the two properties the plugin reads. */
class GWCPP_Test_User {
	public $ID;
	public $roles;
	public $user_email  = '';
	public $user_login  = '';
	public $display_name = '';

	public function __construct( int $id, array $roles ) {
		$this->ID           = $id;
		$this->roles        = $roles;
		$this->user_email   = 'user' . $id . '@example.test';
		$this->user_login   = 'user' . $id;
		$this->display_name = 'User ' . $id;
	}

	public function exists(): bool {
		return true;
	}
}

class_alias( 'GWCPP_Test_User', 'WP_User' );

/* ── Options ─────────────────────────────────────────────────────────────── */

function get_option( $name, $default = false ) {
	return $GLOBALS['gwcpp_test']['options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['gwcpp_test']['options'][ $name ] = $value;
	return true;
}

/**
 * Honest about its return value, because something depends on it.
 *
 * The real add_option() inserts only when the row does not exist and returns
 * false when it does — which is what makes it usable as a test-and-set, and is
 * exactly how gwcpp_review_claim_lock() uses it. A stub that always returned
 * true would make the lock look like it worked while testing nothing.
 */
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['gwcpp_test']['options'] ) ) {
		return false;
	}

	return update_option( $name, $value );
}

function delete_option( $name ) {
	unset( $GLOBALS['gwcpp_test']['options'][ $name ] );
	return true;
}

/* ── Transients ──────────────────────────────────────────────────────────────
 * Expiry is stored and honoured, because the token tests turn on it. Time is
 * read through time(), which tests move by storing an expiry in the past rather
 * than by mocking the clock. */

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['gwcpp_test']['transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $ttl > 0 ? time() + $ttl : 0,
	);
	return true;
}

function get_transient( $key ) {
	$entry = $GLOBALS['gwcpp_test']['transients'][ $key ] ?? null;
	if ( null === $entry ) {
		return false;
	}
	if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
		unset( $GLOBALS['gwcpp_test']['transients'][ $key ] );
		return false;
	}
	return $entry['value'];
}

function delete_transient( $key ) {
	unset( $GLOBALS['gwcpp_test']['transients'][ $key ] );
	return true;
}

/* ── Meta ────────────────────────────────────────────────────────────────────
 * Stored as a list per key, which is what the real meta table does. $single
 * returning the first value and the whole list otherwise is the behaviour the
 * access model depends on. */

function gwcpp_test_meta( string $store, int $id, string $key, bool $single ) {
	$rows = $GLOBALS['gwcpp_test'][ $store ][ $id ][ $key ] ?? array();

	if ( $single ) {
		return $rows ? $rows[0] : '';
	}
	return $rows;
}

function get_post_meta( $id, $key = '', $single = false ) {
	return gwcpp_test_meta( 'post_meta', (int) $id, (string) $key, (bool) $single );
}

function update_post_meta( $id, $key, $value, $prev = '' ) {
	$GLOBALS['gwcpp_test']['post_meta'][ (int) $id ][ $key ] = array( wp_unslash( $value ) );
	return true;
}

function add_post_meta( $id, $key, $value, $unique = false ) {
	$GLOBALS['gwcpp_test']['post_meta'][ (int) $id ][ $key ][] = wp_unslash( $value );
	return true;
}

function delete_post_meta( $id, $key, $value = '' ) {
	if ( '' === $value ) {
		unset( $GLOBALS['gwcpp_test']['post_meta'][ (int) $id ][ $key ] );
		return true;
	}
	$rows = $GLOBALS['gwcpp_test']['post_meta'][ (int) $id ][ $key ] ?? array();
	$GLOBALS['gwcpp_test']['post_meta'][ (int) $id ][ $key ] = array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $value ) {
				return (string) $row !== (string) $value;
			}
		)
	);
	return true;
}

function get_user_meta( $id, $key = '', $single = false ) {
	return gwcpp_test_meta( 'user_meta', (int) $id, (string) $key, (bool) $single );
}

function update_user_meta( $id, $key, $value, $prev = '' ) {
	$GLOBALS['gwcpp_test']['user_meta'][ (int) $id ][ $key ] = array( $value );
	return true;
}

function add_user_meta( $id, $key, $value, $unique = false ) {
	$GLOBALS['gwcpp_test']['user_meta'][ (int) $id ][ $key ][] = $value;
	return true;
}

function delete_user_meta( $id, $key, $value = '' ) {
	if ( '' === $value ) {
		unset( $GLOBALS['gwcpp_test']['user_meta'][ (int) $id ][ $key ] );
		return true;
	}
	$rows = $GLOBALS['gwcpp_test']['user_meta'][ (int) $id ][ $key ] ?? array();
	$GLOBALS['gwcpp_test']['user_meta'][ (int) $id ][ $key ] = array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $value ) {
				return (string) $row !== (string) $value;
			}
		)
	);
	return true;
}

/* ── Posts and types ─────────────────────────────────────────────────────── */

function get_post( $id = null ) {
	return $GLOBALS['gwcpp_test']['posts'][ (int) $id ] ?? null;
}

function get_post_type( $id = null ) {
	$post = get_post( $id );
	return $post ? $post->post_type : false;
}

function get_the_title( $id = 0 ) {
	$post = get_post( is_object( $id ) ? $id->ID : $id );
	return $post ? $post->post_title : '';
}

function post_type_exists( $type ) {
	return in_array( (string) $type, $GLOBALS['gwcpp_test']['types'], true );
}

function wp_update_post( $postarr = array(), $wp_error = false ) {
	$id = (int) ( is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0 );

	if ( $id <= 0 || ! isset( $GLOBALS['gwcpp_test']['posts'][ $id ] ) ) {
		return 0;
	}

	foreach ( $postarr as $key => $value ) {
		if ( 'ID' === $key ) {
			continue;
		}
		// Mirrors wp_insert_post, which unslashes everything it is given —
		// which is exactly why gwcpp_save_fields wp_slash()es first.
		$GLOBALS['gwcpp_test']['posts'][ $id ]->$key = wp_unslash( $value );
	}

	return $id;
}

/* Deliberately NOT a working wp_kses. A stub that implements the filtering
 * itself would be testing this file's idea of KSES rather than WordPress's, and
 * would pass no matter what the plugin's allow-list said. It returns its input
 * untouched, so any unit test that depended on filtering would fail loudly
 * rather than pass falsely — and the filtering itself is checked against real
 * WordPress in tests/integration/richtext.php. */
function wp_kses( $string, $allowed_html, $allowed_protocols = array() ) {
	return $string;
}

function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
	return $GLOBALS['gwcpp_test']['terms'][ (int) $object_ids ][ (string) $taxonomies ] ?? array();
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	$GLOBALS['gwcpp_test']['terms'][ (int) $object_id ][ (string) $taxonomy ] = array_map( 'intval', (array) $terms );
	return $terms;
}

function taxonomy_exists( $taxonomy ) {
	return in_array( (string) $taxonomy, $GLOBALS['gwcpp_test']['taxonomies'] ?? array(), true );
}

function size_format( $bytes, $decimals = 0 ) {
	return round( (int) $bytes / 1048576 ) . ' MB';
}

function wp_max_upload_size() {
	return 64 * MB_IN_BYTES;
}

function get_userdata( $id ) {
	return $GLOBALS['gwcpp_test']['users'][ (int) $id ] ?? false;
}

/**
 * Look a user up the way WordPress does.
 *
 * Honest about the three fields the plugin actually asks for, and returns false
 * for anything else rather than guessing — a stub that matched more broadly than
 * the real function would let a test pass on a lookup a site would refuse.
 *
 * @param string $field id | ID | email | login.
 * @param mixed  $value What to match.
 * @return GWCPP_Test_User|false
 */
function get_user_by( $field, $value ) {
	$field = strtolower( (string) $field );

	if ( 'id' === $field ) {
		return $GLOBALS['gwcpp_test']['users'][ (int) $value ] ?? false;
	}

	$property = 'email' === $field ? 'user_email' : ( 'login' === $field ? 'user_login' : '' );

	if ( '' === $property ) {
		return false;
	}

	foreach ( $GLOBALS['gwcpp_test']['users'] as $user ) {
		if ( strtolower( (string) $user->$property ) === strtolower( (string) $value ) ) {
			return $user;
		}
	}

	return false;
}

function wp_get_current_user() {
	return $GLOBALS['gwcpp_test']['users'][ $GLOBALS['gwcpp_test']['current_user'] ?? 0 ] ?? false;
}

/* A real query against the in-memory user meta, not an empty array. The
 * previous stub returned nothing, which silently made every organisation look
 * empty — so gwcpp_post_has_owner() could never be true and the "managed"
 * branch of the review cycle was untestable. Same lesson as the apply_filters
 * stub above: a double that always returns nothing makes its tests prove
 * nothing. */
function get_users( $args = array() ) {
	$key     = $args['meta_key'] ?? '';
	$value   = $args['meta_value'] ?? null;
	$compare = strtoupper( (string) ( $args['meta_compare'] ?? '=' ) );
	$out     = array();

	foreach ( array_keys( $GLOBALS['gwcpp_test']['users'] ) as $user_id ) {
		if ( '' !== $key ) {
			$rows = $GLOBALS['gwcpp_test']['user_meta'][ $user_id ][ $key ] ?? array();

			if ( 'EXISTS' === $compare ) {
				if ( ! $rows ) {
					continue;
				}
			} elseif ( 'LIKE' === $compare ) {
				$found = false;
				foreach ( $rows as $row ) {
					if ( false !== strpos( maybe_serialize_test( $row ), (string) $value ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					continue;
				}
			} elseif ( ! in_array( (string) $value, array_map( 'strval', $rows ), true ) ) {
				continue;
			}
		}

		$out[] = 'ID' === ( $args['fields'] ?? '' )
			? (int) $user_id
			: $GLOBALS['gwcpp_test']['users'][ $user_id ];
	}

	return $out;
}

/** Enough of maybe_serialize for the LIKE branch above. */
function maybe_serialize_test( $value ) {
	return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
}

function get_posts( $args = array() ) {
	return array();
}

/* Genuinely no-ops here, and that is honest rather than lazy: the in-memory
 * store has no cache layer in front of it — get_post() and get_post_meta() read
 * it directly — so there is nothing for priming to fill. They exist so that the
 * priming call in gwcpp_editable_post_ids() does not fatal the moment a test
 * gives get_posts() something to return, which is a trap worth not leaving.
 *
 * The thing priming is for — turning N round trips into one — is not observable
 * without a real database, and is verified by counting queries in
 * tests/integration/access.php instead. */
function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
	return null;
}

function update_meta_cache( $meta_type, $object_ids ) {
	return array();
}

/* ── Hooks ───────────────────────────────────────────────────────────────────
 * add_filter and apply_filters are REAL, priority-ordered and all, because the
 * plugin's field type registry is built by filter: field-taxonomy.php and its
 * three siblings register themselves onto `gwcpp_field_types` rather than being
 * listed anywhere.
 *
 * An earlier version of this file made apply_filters a no-op that returned its
 * value untouched. Every test still passed, and every one of them was testing a
 * registry containing only the built-in types — the four richest types, the
 * ones most worth testing, were invisible to the whole suite. Two tests that
 * should have failed passed because gwcpp_field_call() fell through to its
 * unknown-type fallback.
 *
 * add_action and do_action stay no-ops: nothing under test depends on an action
 * firing, and unlike the filters above, none of them build anything.
 * ─────────────────────────────────────────────────────────────────────────── */

function add_action( ...$args ) {
	return true;
}

function do_action( ...$args ) {
	return null;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['gwcpp_test_filters'][ $hook ][ (int) $priority ][] = array(
		'cb'   => $callback,
		'args' => (int) $accepted_args,
	);
	return true;
}

function apply_filters( $hook, $value, ...$rest ) {
	if ( empty( $GLOBALS['gwcpp_test_filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['gwcpp_test_filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			if ( ! is_callable( $entry['cb'] ) ) {
				continue;
			}
			$args  = array_merge( array( $value ), $rest );
			$value = call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 1, $entry['args'] ) ) );
		}
	}

	return $value;
}

function add_shortcode( ...$args ) {
	return true;
}

/* ── Object cache ────────────────────────────────────────────────────────── */

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['gwcpp_test']['cache'][ $group ][ $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	$GLOBALS['gwcpp_test']['cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_flush_group( $group ) {
	unset( $GLOBALS['gwcpp_test']['cache'][ $group ] );
	return true;
}

function wp_cache_add_non_persistent_groups( $groups ) {
	return true;
}

/* ── Strings ─────────────────────────────────────────────────────────────────
 * sanitize_text_field here strips tags and collapses whitespace, which is the
 * part of the real function the plugin's behaviour depends on. It does NOT
 * reproduce the octet and percent-encoding handling — noted because a test
 * asserting on those would be asserting against this file rather than against
 * WordPress. */

function __( $text, $domain = '' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = '' ) {
	return esc_attr( $text );
}

function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	$allowed = is_array( $protocols ) ? $protocols : array( 'http', 'https' );

	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( '' === $scheme || ! in_array( $scheme, $allowed, true ) ) {
		return '';
	}

	return $url;
}

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = wp_strip_all_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( (string) $str );
}

function sanitize_textarea_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	return trim( $str );
}

function wp_strip_all_tags( $str, $remove_breaks = false ) {
	$str = (string) $str;
	$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $str );
	return (string) strip_tags( (string) $str );
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return (string) filter_var( $email, FILTER_SANITIZE_EMAIL );
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9_\-\s]/', '', $title );
	return trim( (string) preg_replace( '/[\s_]+/', '-', (string) $title ), '-' );
}

function sanitize_user( $login, $strict = false ) {
	return preg_replace( '/[^a-zA-Z0-9 _.\-@]/', '', (string) $login );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current || ( $checked && $current ) ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore
	}
	return $out;
}

function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore
	}
	return $out;
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth ); // phpcs:ignore
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_bloginfo( $what = '' ) {
	return 'Test Site';
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return str_repeat( 'x', (int) $length );
}

/* ── The plugin, in the bootstrap's own order ────────────────────────────────
 * Only the files whose logic is unit-testable. The views, the admin screens
 * bar one function, the block and the mail all need a running WordPress and are
 * covered by tests/integration/ instead. */

require GWCPP_DIR . 'inc/i18n.php';
require GWCPP_DIR . 'inc/settings.php';
require GWCPP_DIR . 'inc/field-types.php';
require GWCPP_DIR . 'inc/field-taxonomy.php';
require GWCPP_DIR . 'inc/field-richtext.php';
require GWCPP_DIR . 'inc/field-repeater.php';
require GWCPP_DIR . 'inc/field-media.php';
require GWCPP_DIR . 'inc/schema.php';
require GWCPP_DIR . 'inc/org-cpt.php';
require GWCPP_DIR . 'inc/access.php';
require GWCPP_DIR . 'inc/validate.php';
require GWCPP_DIR . 'inc/save.php';
require GWCPP_DIR . 'inc/changeset.php';
require GWCPP_DIR . 'inc/auth.php';
require GWCPP_DIR . 'inc/review.php';
require GWCPP_DIR . 'inc/handoff.php';
require GWCPP_DIR . 'inc/blocked-words.php';

/* admin-screen.php declares gwcpp_colophon_snoozed(), which is pure and worth a
 * test. It also declares gwcpp_require_admin_caps(), which calls wp_die() — the
 * stub below exists so requiring the file cannot fatal, not because anything
 * under test calls it. */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
}

function current_user_can( ...$args ) {
	return true;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_query_arg( ...$args ) {
	return 'https://example.test/';
}

function wp_nonce_field( ...$args ) {
	return '';
}

function submit_button( ...$args ) {
	return '';
}

function wp_dropdown_pages( ...$args ) {
	return '';
}

function get_post_types( $args = array(), $output = 'names' ) {
	return array();
}

function get_post_type_object( $type ) {
	return null;
}

function get_current_user_id() {
	return (int) ( $GLOBALS['gwcpp_test']['current_user'] ?? 0 );
}

function get_role( $role ) {
	return null;
}

function add_role( ...$args ) {
	return null;
}

function human_time_diff( $from, $to = 0 ) {
	return '1 day';
}

/* current_time and wp_timezone are what all the review date maths runs on, so
 * they are honest rather than stubbed to a constant: tests move the clock by
 * writing a review date in the past, exactly as a real site would. */
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function current_time( $type = 'mysql', $gmt = 0 ) {
	return 'Y-m-d' === $type ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d H:i:s' );
}

function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	return true;
}

function spawn_cron( $gmt_time = 0 ) {
	return true;
}

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function is_admin() {
	return false;
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
}


require GWCPP_DIR . 'inc/admin-screen.php';
