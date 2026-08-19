<?php
/**
 * The four places a version number lives, checked against each other.
 *
 * This fails the moment any one of them is bumped alone. That happens more
 * often than it sounds: the header and the constant are two lines apart and are
 * still edited separately, and readme.txt's Stable tag is the one WordPress.org
 * actually serves — a release where it disagrees with the header ships a
 * version nobody is offered as an update, and fixing it means another release.
 *
 * The deploy workflow checks the same four against the git tag, which is the
 * one thing this file cannot see.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase {

	private function plugin_file(): string {
		return (string) file_get_contents( GWC_PP_DIR . 'groundwork-common-post-portal.php' );
	}

	private function readme(): string {
		return (string) file_get_contents( GWC_PP_DIR . 'readme.txt' );
	}

	public function test_the_header_and_the_constant_agree(): void {
		preg_match( '/^ \* Version:\s*(\S+)/m', $this->plugin_file(), $header );

		$this->assertNotEmpty( $header, 'The plugin header has no Version line.' );
		$this->assertSame( $header[1], GWC_PP_VERSION );
	}

	public function test_the_stable_tag_agrees(): void {
		preg_match( '/^Stable tag:\s*(\S+)/m', $this->readme(), $stable );

		$this->assertNotEmpty( $stable, 'readme.txt has no Stable tag line.' );
		$this->assertSame( GWC_PP_VERSION, $stable[1] );
	}

	public function test_the_changelog_has_an_entry_for_this_version(): void {
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWC_PP_VERSION, '/' ) . ' =$/m',
			$this->readme(),
			'readme.txt needs a == Changelog == entry for ' . GWC_PP_VERSION
		);
	}

	public function test_the_upgrade_notice_has_an_entry_for_this_version(): void {
		$parts = explode( '== Upgrade Notice ==', $this->readme() );

		$this->assertCount( 2, $parts, 'readme.txt has no == Upgrade Notice == section.' );
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWC_PP_VERSION, '/' ) . ' =$/m',
			$parts[1]
		);
	}

	public function test_the_version_is_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', GWC_PP_VERSION );
	}

	public function test_the_requirements_agree_between_the_header_and_the_readme(): void {
		$plugin = $this->plugin_file();
		$readme = $this->readme();

		foreach ( array( 'Requires at least', 'Requires PHP' ) as $field ) {
			preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $plugin, $a );
			preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $readme, $b );

			$this->assertNotEmpty( $a, $field . ' is missing from the plugin header.' );
			$this->assertNotEmpty( $b, $field . ' is missing from readme.txt.' );
			$this->assertSame( $a[1], $b[1], $field . ' disagrees between the header and readme.txt.' );
		}
	}

	/**
	 * The slug, the text domain, the main file's name and the .pot's name are
	 * deliberately one string.
	 *
	 * This used to assert on basename( GWC_PP_DIR ) — the folder the checkout
	 * happens to sit in — which is the one thing in the list that is not the
	 * plugin's own business. WordPress.org installs into a folder named after
	 * the slug whatever the developer's directory was called, so the assertion
	 * proved nothing about the shipped plugin while failing in any checkout not
	 * named after the repo: a git worktree, a fork, a CI job that clones into
	 * `work/`. The main file's name is the invariant that actually matters, and
	 * it is one the repository controls.
	 */
	public function test_the_slug_is_one_string_everywhere(): void {
		$slug = 'groundwork-common-post-portal';

		preg_match( '/^ \* Text Domain:\s*(\S+)/m', $this->plugin_file(), $domain );

		$this->assertSame( $slug, $domain[1], 'The Text Domain header must be the slug.' );

		$this->assertFileExists(
			GWC_PP_DIR . $slug . '.php',
			'The main plugin file must be named after the slug.'
		);

		$this->assertFileExists(
			GWC_PP_DIR . 'languages/' . $slug . '.pot',
			'The translation template must be named after the text domain, or WordPress will not find it.'
		);
	}
}
