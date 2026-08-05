<?php
/**
 * What uninstall.php sweeps, and what it promises never to touch.
 *
 * ── Why this reads the file as text ──────────────────────────────────────────
 * uninstall.php cannot be included here. It bails unless WP_UNINSTALL_PLUGIN is
 * defined, it runs standalone against a live database, and it deliberately does
 * not load any of the plugin's own files — which is the whole reason it names
 * its meta keys as string literals rather than as the constants that define
 * them. That is a sensible constraint and a standing invitation to drift: the
 * constant can be renamed in inc/ and the literal here will keep pointing at a
 * key nothing writes any more, silently, forever.
 *
 * So this checks the literals against the constants, the same way VersionTest
 * checks the four version numbers against each other.
 *
 * ── The bug it was written for ───────────────────────────────────────────────
 * _gwcpp_tokens holds the durable review tokens: seven days each, and every one
 * signs its holder straight in when clicked. They live in user meta rather than
 * in a transient precisely so nothing sweeps them by accident — which meant
 * nothing swept them on purpose either, because the sweep listed the two
 * interface-state keys beside them and not the token row. Every unclicked
 * reminder link stayed live for up to a week after the plugin was deleted, with
 * nothing installed to expire it.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	private function uninstall(): string {
		return (string) file_get_contents( GWCPP_DIR . 'uninstall.php' );
	}

	/**
	 * The user meta keys the uninstaller sweeps.
	 *
	 * @return string[]
	 */
	private function swept_user_meta(): array {
		preg_match(
			'/foreach \(\s*array\(([^)]*)\) as \$gwcpp_user_meta \)/',
			$this->uninstall(),
			$m
		);

		$this->assertNotEmpty( $m, 'uninstall.php no longer has a user-meta sweep in the shape this test reads.' );

		preg_match_all( "/'([^']+)'/", $m[1], $keys );

		return $keys[1];
	}

	/**
	 * A constant's value, read from the source of the file that declares it.
	 *
	 * For the two constants the test bootstrap does not load. Reading the source
	 * is the point rather than a workaround: a test that took the value from a
	 * constant it had just defined itself would prove nothing about the plugin.
	 *
	 * @param string $file Path under inc/.
	 * @param string $name Constant name.
	 * @return string
	 */
	private function constant_in( string $file, string $name ): string {
		$source = (string) file_get_contents( GWCPP_DIR . 'inc/' . $file );

		preg_match( '/const\s+' . preg_quote( $name, '/' ) . "\s*=\s*'([^']+)'/", $source, $m );

		$this->assertNotEmpty( $m, $name . ' is no longer declared in inc/' . $file . '.' );

		return $m[1];
	}

	/* ── The token sweep, which is the fix ───────────────────────────────── */

	public function test_the_durable_sign_in_tokens_are_swept(): void {
		$this->assertContains(
			GWCPP_TOKENS_META,
			$this->swept_user_meta(),
			'Durable review tokens sign their holder in for seven days. Leaving them behind leaves live credentials on a site with nothing installed to expire them.'
		);
	}

	/* ── Drift between the literals and the constants ────────────────────── */

	public function test_every_user_meta_key_the_plugin_writes_is_named(): void {
		$swept = $this->swept_user_meta();

		$expected = array(
			'GWCPP_TOKENS_META'   => GWCPP_TOKENS_META,
			'GWCPP_COLOPHON_META' => GWCPP_COLOPHON_META,
			// Declared in inc/meta-box.php, which the bootstrap does not load.
			'GWCPP_LAST_LOGIN_META' => $this->constant_in( 'meta-box.php', 'GWCPP_LAST_LOGIN_META' ),
		);

		foreach ( $expected as $name => $key ) {
			$this->assertContains(
				$key,
				$swept,
				$name . " is '" . $key . "', which uninstall.php does not sweep."
			);
		}
	}

	public function test_the_organisation_membership_row_is_deliberately_kept(): void {
		/*
		 * The one user meta key that is NOT swept, and should not be. It records
		 * which organisations somebody belongs to, which is the site's data about
		 * a real person rather than this plugin's bookkeeping — and deactivating
		 * or removing the plugin is not a decision to forget who a partner is.
		 * Asserted so that a future tidy-up of the list above has to argue with
		 * this comment rather than sweep it in by symmetry.
		 */
		$this->assertNotContains( GWCPP_USER_ORG_META, $this->swept_user_meta() );
	}

	/* ── The standing promises at the top of the file ────────────────────── */

	public function test_nothing_deletes_a_post_a_user_or_post_meta(): void {
		$source = $this->uninstall();

		foreach ( array( 'wp_delete_post', 'wp_delete_user', 'wp_delete_attachment', 'delete_post_meta', 'remove_role' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden . '(',
				$source,
				'uninstall.php promises it never calls ' . $forbidden . '(). Removing the plugin removes the plugin.'
			);
		}
	}

	public function test_the_schema_and_settings_stay_behind_the_arming_flag(): void {
		$source = $this->uninstall();

		// The guard, and both destructive deletes below it.
		$this->assertMatchesRegularExpression(
			'/if \( ! get_option\( \'gwcpp_allow_destructive_uninstall\' \) \) \{\s*return;/',
			$source
		);

		$guard = strpos( $source, "get_option( 'gwcpp_allow_destructive_uninstall' )" );

		foreach ( array( "delete_option( 'gwcpp_schema' )", "delete_option( 'gwcpp_settings' )" ) as $destructive ) {
			$at = strpos( $source, $destructive );

			$this->assertNotFalse( $at, $destructive . ' is gone from uninstall.php.' );
			$this->assertGreaterThan(
				$guard,
				$at,
				$destructive . ' must sit below the arming check, or a plain uninstall takes somebody\'s field configuration with it.'
			);
		}
	}
}
