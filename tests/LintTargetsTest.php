<?php
/**
 * The two rulesets check the same files.
 *
 * phpcs.xml.dist and phpcompat.xml.dist both name their targets rather than
 * scanning `.` and excluding what should not have been found, for the reason
 * written at length in phpcs.xml.dist: an exclude-pattern is matched against
 * the ABSOLUTE path, so it is a claim about the machine's directory layout
 * rather than about this repository. That has failed in both directions — once
 * loudly, by linting the stale plugin copies under `.claude/worktrees/`, and
 * once silently, by matching everything when the checkout itself sits there.
 *
 * The cost of naming them is two lists, and two lists drift. This is what stops
 * them: a file added to one ruleset and forgotten in the other means the coding
 * standard and the PHP-compatibility check disagree about what the plugin is,
 * and the one that forgot it says nothing at all.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class LintTargetsTest extends TestCase {

	/**
	 * The paths a ruleset names, in the order it names them.
	 *
	 * @param string $ruleset Filename, relative to the plugin root.
	 * @return string[]
	 */
	private function targets( string $ruleset ): array {
		$xml = simplexml_load_file( GWC_PP_DIR . $ruleset );

		$this->assertNotFalse( $xml, $ruleset . ' is not parseable XML.' );

		return array_map( 'strval', iterator_to_array( $xml->file, false ) );
	}

	public function test_both_rulesets_name_the_same_files(): void {
		$this->assertSame(
			$this->targets( 'phpcs.xml.dist' ),
			$this->targets( 'phpcompat.xml.dist' ),
			'phpcs.xml.dist and phpcompat.xml.dist must check the same paths. '
				. 'Adding a file to one and not the other leaves it unchecked by the other, silently.'
		);
	}

	public function test_the_named_paths_all_exist(): void {
		$targets = $this->targets( 'phpcs.xml.dist' );

		$this->assertNotEmpty( $targets, 'A ruleset that names nothing scans nothing and exits 0.' );

		foreach ( $targets as $path ) {
			$this->assertFileExists(
				GWC_PP_DIR . $path,
				$path . ' is named in phpcs.xml.dist but is not in the checkout. '
					. 'PHPCS fails on a missing path, but a renamed directory left in the list is worth catching here.'
			);
		}
	}

	/**
	 * Everything that ships is named by one of them.
	 *
	 * The list being load-bearing is the deliberate trade, and this is the half
	 * of it that can be automated: a new top-level PHP file, or a new directory
	 * of them, is checked by nobody until somebody adds it. Nothing else in the
	 * repository would complain.
	 */
	public function test_nothing_shipped_is_left_unnamed(): void {
		$targets = $this->targets( 'phpcs.xml.dist' );

		foreach ( glob( GWC_PP_DIR . '*.php' ) as $file ) {
			$this->assertContains(
				basename( $file ),
				$targets,
				basename( $file ) . ' ships but is named in neither ruleset, so neither checks it.'
			);
		}
	}
}
