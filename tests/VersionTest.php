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
		return (string) file_get_contents( GWCPP_DIR . 'groundwork-common-post-portal.php' );
	}

	private function readme(): string {
		return (string) file_get_contents( GWCPP_DIR . 'readme.txt' );
	}

	public function test_the_header_and_the_constant_agree(): void {
		preg_match( '/^ \* Version:\s*(\S+)/m', $this->plugin_file(), $header );

		$this->assertNotEmpty( $header, 'The plugin header has no Version line.' );
		$this->assertSame( $header[1], GWCPP_VERSION );
	}

	public function test_the_stable_tag_agrees(): void {
		preg_match( '/^Stable tag:\s*(\S+)/m', $this->readme(), $stable );

		$this->assertNotEmpty( $stable, 'readme.txt has no Stable tag line.' );
		$this->assertSame( GWCPP_VERSION, $stable[1] );
	}

	public function test_the_changelog_has_an_entry_for_this_version(): void {
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWCPP_VERSION, '/' ) . ' =$/m',
			$this->readme(),
			'readme.txt needs a == Changelog == entry for ' . GWCPP_VERSION
		);
	}

	public function test_the_upgrade_notice_has_an_entry_for_this_version(): void {
		$parts = explode( '== Upgrade Notice ==', $this->readme() );

		$this->assertCount( 2, $parts, 'readme.txt has no == Upgrade Notice == section.' );
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWCPP_VERSION, '/' ) . ' =$/m',
			$parts[1]
		);
	}

	public function test_the_version_is_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', GWCPP_VERSION );
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

	public function test_the_text_domain_matches_the_folder_name(): void {
		preg_match( '/^ \* Text Domain:\s*(\S+)/m', $this->plugin_file(), $domain );

		$this->assertSame( 'groundwork-common-post-portal', $domain[1] );
		$this->assertSame(
			'groundwork-common-post-portal',
			basename( rtrim( GWCPP_DIR, '/' ) ),
			'The folder name, the slug and the text domain are deliberately the same string.'
		);
	}
}
