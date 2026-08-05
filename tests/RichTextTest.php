<?php
/**
 * Rich text: the allow-list's shape, and the parts of the pipeline that are
 * ours rather than WordPress's.
 *
 * ── What is deliberately NOT tested here ─────────────────────────────────────
 * That wp_kses actually removes a <script>. The stub in tests/bootstrap.php
 * returns its input untouched on purpose, because a stub that implemented the
 * filtering would be testing this suite's idea of KSES rather than WordPress's,
 * and would pass whatever the plugin's allow-list contained.
 *
 * So the filtering itself is asserted against real WordPress in
 * tests/integration/richtext.php. What is testable without WordPress is the
 * allow-list we hand it — and that list being wrong is the more likely mistake,
 * because it is the thing a future change edits.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class RichTextTest extends TestCase {

	protected function setUp(): void {
		gwcpp_test_reset();
	}

	/* ── The allow-list ──────────────────────────────────────────────────── */

	/**
	 * @param string $tag A tag that must never be allowed.
	 */
	#[PHPUnit\Framework\Attributes\DataProvider( 'forbidden_tags' )]
	public function test_dangerous_tags_are_not_on_the_list( string $tag ): void {
		$this->assertArrayNotHasKey(
			$tag,
			gwcpp_richtext_allowed_html(),
			$tag . ' would turn a text field into a way to run or collect something on a page the site owns.'
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function forbidden_tags(): array {
		return array(
			'script' => array( 'script' ),
			'iframe' => array( 'iframe' ),
			'object' => array( 'object' ),
			'embed'  => array( 'embed' ),
			'form'   => array( 'form' ),
			'input'  => array( 'input' ),
			'style'  => array( 'style' ),
			'link'   => array( 'link' ),
			'meta'   => array( 'meta' ),
			'svg'    => array( 'svg' ),
			'h1'     => array( 'h1' ),
			'h2'     => array( 'h2' ),
		);
	}

	public function test_no_tag_may_carry_style_or_class(): void {
		foreach ( gwcpp_richtext_allowed_html() as $tag => $attrs ) {
			if ( ! is_array( $attrs ) ) {
				continue;
			}

			$this->assertArrayNotHasKey( 'style', $attrs, $tag . ' must not accept inline styles.' );
			$this->assertArrayNotHasKey(
				'class',
				$attrs,
				$tag . ' must not accept a class, or submitted content can borrow the site\'s own visual language.'
			);
		}
	}

	public function test_no_tag_may_carry_an_id(): void {
		foreach ( gwcpp_richtext_allowed_html() as $tag => $attrs ) {
			if ( ! is_array( $attrs ) ) {
				continue;
			}

			$this->assertArrayNotHasKey(
				'id',
				$attrs,
				$tag . ' must not accept an id: it is DOM clobbering, anchor hijacking, and a hook for the site\'s own CSS.'
			);
		}
	}

	/**
	 * The trap that let `id` through for a whole release.
	 *
	 * The list said `'id' => false`, which reads as "not allowed" and is not.
	 * wp_kses_attr_check() rejects on `! isset( $allowed[ $name ] ) || '' ===
	 * $allowed[ $name ]` — isset() is true for false, and '' === false is false,
	 * so the attribute passed the gate; is_array( false ) then skipped the value
	 * check and it was allowed unconditionally.
	 *
	 * An attribute is excluded by not appearing in the array at all. Nothing in
	 * this list should ever be a bare false, and asserting that catches the next
	 * person who reaches for the same intuitive-but-wrong spelling.
	 */
	public function test_no_attribute_is_spelled_as_a_bare_false(): void {
		foreach ( gwcpp_richtext_allowed_html() as $tag => $attrs ) {
			if ( ! is_array( $attrs ) ) {
				continue;
			}

			foreach ( $attrs as $attr => $rule ) {
				$this->assertNotFalse(
					$rule,
					$tag . '/' . $attr . ' => false does not deny the attribute, it allows it. Remove the key instead.'
				);
			}
		}
	}

	public function test_no_tag_may_carry_an_event_handler(): void {
		foreach ( gwcpp_richtext_allowed_html() as $tag => $attrs ) {
			if ( ! is_array( $attrs ) ) {
				continue;
			}

			foreach ( array_keys( $attrs ) as $attr ) {
				$this->assertStringStartsNotWith( 'on', (string) $attr, $tag . '/' . $attr . ' is an event handler.' );
			}
		}
	}

	public function test_the_ordinary_writing_tags_are_allowed(): void {
		$allowed = gwcpp_richtext_allowed_html();

		foreach ( array( 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $allowed, 'People have to be able to write ordinary text.' );
		}
	}

	public function test_links_may_carry_href_but_not_target_or_rel(): void {
		$a = gwcpp_richtext_allowed_html()['a'];

		$this->assertArrayHasKey( 'href', $a );
		$this->assertArrayNotHasKey( 'rel', $a, 'rel is set by us, not accepted from input.' );
		$this->assertArrayNotHasKey( 'target', $a, 'target is set by us, not accepted from input.' );
	}

	/* ── Link hardening, which is ours ───────────────────────────────────── */

	public function test_links_get_nofollow_and_noopener(): void {
		$html = gwcpp_harden_links( '<a href="https://example.org">x</a>' );

		$this->assertStringContainsString( 'rel="nofollow noopener"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
	}

	public function test_a_submitted_rel_is_replaced_not_appended(): void {
		$html = gwcpp_harden_links( '<a href="https://example.org" rel="dofollow">x</a>' );

		$this->assertStringNotContainsString( 'dofollow', $html );
		$this->assertSame( 1, substr_count( $html, 'rel=' ) );
	}

	public function test_a_submitted_target_is_replaced_not_duplicated(): void {
		$html = gwcpp_harden_links( '<a href="https://example.org" target="_self">x</a>' );

		$this->assertSame( 1, substr_count( $html, 'target=' ) );
		$this->assertStringContainsString( 'target="_blank"', $html );
	}

	public function test_the_href_survives(): void {
		$html = gwcpp_harden_links( '<a href="https://example.org/a?b=1">x</a>' );

		$this->assertStringContainsString( 'href="https://example.org/a?b=1"', $html );
	}

	/* ── Emptiness, which decides whether a meta row is written ──────────── */

	public function test_an_editor_left_alone_counts_as_empty(): void {
		// What TinyMCE submits for an untouched editor.
		$this->assertTrue( gwcpp_empty_richtext( '<p></p>' ) );
		$this->assertTrue( gwcpp_empty_richtext( '<p><br></p>' ) );
		$this->assertTrue( gwcpp_empty_richtext( '<p>&nbsp;</p>' ) );
		$this->assertTrue( gwcpp_empty_richtext( '' ) );
		$this->assertTrue( gwcpp_empty_richtext( '   ' ) );
	}

	public function test_real_text_is_not_empty(): void {
		$this->assertFalse( gwcpp_empty_richtext( '<p>We open at nine.</p>' ) );
	}

	/* ── Display, which is what the diff and the emails show ─────────────── */

	public function test_display_strips_markup_and_collapses_space(): void {
		$this->assertSame(
			'We open at nine. Closed Sundays.',
			gwcpp_display_richtext( "<p>We open at nine.</p>\n<p>Closed  Sundays.</p>" )
		);
	}

	public function test_display_truncates_a_long_body(): void {
		$text = gwcpp_display_richtext( '<p>' . str_repeat( 'a', 500 ) . '</p>' );

		$this->assertLessThanOrEqual( 301, mb_strlen( $text ) );
		$this->assertStringEndsWith( '…', $text );
	}

	/* ── Length ──────────────────────────────────────────────────────────── */

	public function test_a_body_over_the_limit_is_refused_rather_than_cut_in_half(): void {
		$field = array(
			'type'     => 'richtext',
			'settings' => array( 'maxlength' => 10 ),
		);

		$this->assertSame(
			'',
			gwcpp_sanitize_richtext( '<p>' . str_repeat( 'a', 50 ) . '</p>', $field ),
			'Truncating HTML by length closes elements the page never opened.'
		);
	}

	public function test_a_body_under_the_limit_survives(): void {
		$field = array(
			'type'     => 'richtext',
			'settings' => array( 'maxlength' => 100 ),
		);

		$this->assertNotSame( '', gwcpp_sanitize_richtext( '<p>Short.</p>', $field ) );
	}
}
