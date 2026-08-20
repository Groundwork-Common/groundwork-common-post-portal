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
	 * Both rulesets repeat the PHP floor, and the header is the one that means it.
	 *
	 * They carry it so a bare `composer lint` or `composer compat` needs no
	 * arguments. CI passes the header value to the compat run explicitly, which
	 * is the trap: CI would go on checking the right floor however far the
	 * rulesets drifted, while every local run quietly checked the wrong one.
	 */
	public function test_the_php_floor_agrees_with_the_rulesets(): void {
		foreach ( array( 'phpcs.xml.dist', 'phpcompat.xml.dist' ) as $file ) {
			$this->assertSame(
				$this->header( 'Requires PHP' ) . '-',
				$this->ruleset_config( $file, 'testVersion' ),
				$file . ' checks a different PHP floor than the plugin header claims.'
			);
		}
	}

	/**
	 * The same again for WordPress, which only the lint ruleset needs.
	 *
	 * WPCS reads minimum_wp_version to decide whether a function is deprecated
	 * or too new to call. Set below the header and it waves through calls the
	 * plugin's own claim says are not available; set above it and it hides them.
	 */
	public function test_the_wordpress_floor_agrees_with_the_lint_ruleset(): void {
		$this->assertSame(
			$this->header( 'Requires at least' ),
			$this->ruleset_config( 'phpcs.xml.dist', 'minimum_wp_version' ),
			'phpcs.xml.dist sniffs against a different WordPress floor than the plugin header claims.'
		);
	}

	/**
	 * One value out of the plugin header.
	 *
	 * @param string $field Header field name.
	 * @return string
	 */
	private function header( string $field ): string {
		preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $this->plugin_file(), $match );

		$this->assertNotEmpty( $match, $field . ' is missing from the plugin header.' );

		return $match[1];
	}

	/**
	 * One `config` value out of a PHPCS ruleset.
	 *
	 * @param string $file Ruleset filename, relative to the plugin root.
	 * @param string $name The config name to read.
	 * @return string
	 */
	private function ruleset_config( string $file, string $name ): string {
		$ruleset = simplexml_load_file( GWC_PP_DIR . $file );

		$this->assertNotFalse( $ruleset, $file . ' is not parseable XML.' );

		$found = null;
		foreach ( $ruleset->config as $config ) {
			if ( $name === (string) $config['name'] ) {
				$found = (string) $config['value'];
			}
		}

		$this->assertNotNull(
			$found,
			$file . ' sets no ' . $name . ', so the sniffs that read it pass vacuously.'
		);

		return $found;
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
