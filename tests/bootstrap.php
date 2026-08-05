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
	'filters'    => array(),
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
	$GLOBALS['gwcpp_test']['filters']    = array();

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

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
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

function get_userdata( $id ) {
	return $GLOBALS['gwcpp_test']['users'][ (int) $id ] ?? false;
}

function wp_get_current_user() {
	return $GLOBALS['gwcpp_test']['users'][ $GLOBALS['gwcpp_test']['current_user'] ?? 0 ] ?? false;
}

function get_users( $args = array() ) {
	return array();
}

function get_posts( $args = array() ) {
	return array();
}

/* ── Hooks ───────────────────────────────────────────────────────────────────
 * add_action and add_filter are no-ops: nothing under test depends on a hook
 * firing, and a real dispatcher here would be a second implementation of
 * WordPress's to keep correct. apply_filters returns its value unchanged, which
 * is what happens on a site with nothing hooked — the case the plugin's own
 * behaviour is specified against. */

function add_action( ...$args ) {
	return true;
}

function add_filter( ...$args ) {
	return true;
}

function do_action( ...$args ) {
	return null;
}

function apply_filters( $hook, $value, ...$rest ) {
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
require GWCPP_DIR . 'inc/schema.php';
require GWCPP_DIR . 'inc/org-cpt.php';
require GWCPP_DIR . 'inc/access.php';
require GWCPP_DIR . 'inc/validate.php';
require GWCPP_DIR . 'inc/save.php';
require GWCPP_DIR . 'inc/auth.php';

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

require GWCPP_DIR . 'inc/admin-screen.php';
