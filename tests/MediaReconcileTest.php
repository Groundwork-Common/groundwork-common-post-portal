<?php
/**
 * The ownership check on a kept attachment.
 *
 * ── What this is guarding ────────────────────────────────────────────────────
 * The media control carries the current attachment forward in a hidden `keep`
 * input, so that a save which does not touch the file does not clear it. Hidden
 * means attacker-controlled, and gwc_pp_sanitize_media() only ever checked that
 * the ID named *an* attachment — not that it named this post's attachment.
 *
 * Posting somebody else's ID therefore got you that file's thumbnail, URL and
 * title on the next render of your own edit form, for any attachment on the
 * site, including ones hanging off private or unpublished posts. Under
 * require_approval the pending value is shown straight back to the submitter,
 * so it did not even need staff to act on anything.
 *
 * The check cannot live in the sanitizer, which is handed a value and a field
 * definition and has no idea which post is being edited. It lives in
 * gwc_pp_reconcile_media(), run from gwc_pp_apply_uploads() in the save path.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class MediaReconcileTest extends TestCase {

	private const POST    = 90;
	private const OTHER   = 91;
	private const MINE    = 500;
	private const THEIRS  = 501;
	private const FRESH   = 502;

	private array $field = array(
		'key'      => 'photo',
		'type'     => 'media',
		'label'    => 'Photo',
		'settings' => array(),
	);

	protected function setUp(): void {
		gwc_pp_test_reset();
		$GLOBALS['gwc_pp_test']['types'][] = 'clinic';

		gwc_pp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );
		gwc_pp_test_post( self::OTHER, 'clinic', 'publish', 0, 'Other Clinic' );

		gwc_pp_test_post( self::MINE, 'attachment' );
		gwc_pp_test_post( self::THEIRS, 'attachment' );
		gwc_pp_test_post( self::FRESH, 'attachment' );

		// What this post actually carries.
		update_post_meta( self::POST, 'photo', self::MINE );
		// What the other organisation's post carries.
		update_post_meta( self::OTHER, 'photo', self::THEIRS );
	}

	/* ── The attack ──────────────────────────────────────────────────────── */

	public function test_another_posts_attachment_is_refused(): void {
		$this->assertSame(
			'',
			gwc_pp_reconcile_media( self::THEIRS, $this->field, self::POST ),
			'A hidden input naming another organisation\'s file must not be honoured.'
		);
	}

	public function test_an_arbitrary_attachment_id_is_refused(): void {
		$this->assertSame( '', gwc_pp_reconcile_media( 4242, $this->field, self::POST ) );
	}

	/**
	 * The sanitizer is deliberately not the place this is caught, so it must
	 * still let the value through — otherwise a future reader "simplifies" the
	 * reconciler away on the grounds that the sanitizer already handles it.
	 */
	public function test_the_sanitizer_alone_does_not_catch_it(): void {
		$this->assertSame(
			self::THEIRS,
			gwc_pp_sanitize_media( array( 'keep' => self::THEIRS ) ),
			'The sanitizer checks shape only; ownership is the save path\'s job.'
		);
	}

	/* ── The three things that are legitimate ────────────────────────────── */

	public function test_the_posts_own_attachment_survives(): void {
		$this->assertSame( self::MINE, gwc_pp_reconcile_media( self::MINE, $this->field, self::POST ) );
	}

	public function test_an_attachment_uploaded_by_this_submission_survives(): void {
		$this->assertSame(
			self::FRESH,
			gwc_pp_reconcile_media( self::FRESH, $this->field, self::POST, self::FRESH )
		);
	}

	/**
	 * The edit form prefills from a pending changeset when there is one, so the
	 * value in the box genuinely is the changeset's — refusing it would clear
	 * somebody's photo every time they corrected a typo while a change was
	 * waiting for review.
	 */
	public function test_a_pending_changesets_attachment_survives(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'photo' => self::FRESH ), array( self::FRESH ) );

		$this->assertSame(
			self::FRESH,
			gwc_pp_reconcile_media( self::FRESH, $this->field, self::POST )
		);
	}

	public function test_a_pending_changeset_does_not_launder_a_foreign_attachment(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'photo' => self::FRESH ), array( self::FRESH ) );

		$this->assertSame(
			'',
			gwc_pp_reconcile_media( self::THEIRS, $this->field, self::POST )
		);
	}

	/* ── Creating, where there is no post to compare against ─────────────── */

	public function test_nothing_can_be_kept_when_creating(): void {
		$this->assertSame(
			'',
			gwc_pp_reconcile_media( self::MINE, $this->field, 0 ),
			'There is no post yet, so nothing can have been carried forward.'
		);
	}

	public function test_an_upload_still_survives_when_creating(): void {
		$this->assertSame(
			self::FRESH,
			gwc_pp_reconcile_media( self::FRESH, $this->field, 0, self::FRESH )
		);
	}

	/* ── Emptiness ───────────────────────────────────────────────────────── */

	public function test_empty_stays_empty(): void {
		$this->assertSame( '', gwc_pp_reconcile_media( '', $this->field, self::POST ) );
		$this->assertSame( '', gwc_pp_reconcile_media( 0, $this->field, self::POST ) );
	}

	/* ── Wired into the save path, not just declared ─────────────────────── */

	public function test_the_media_type_declares_the_reconciler(): void {
		$def = gwc_pp_field_type( 'media' );

		$this->assertIsArray( $def );
		$this->assertSame(
			'gwc_pp_reconcile_media',
			$def['reconcile'] ?? '',
			'gwc_pp_apply_uploads() runs this off the type registry; unregistered means never called.'
		);
	}

	public function test_apply_uploads_refuses_a_foreign_attachment_end_to_end(): void {
		update_option( 'gwc_pp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwc_pp_settings_cache( null, true );

		gwc_pp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array( $this->field ),
						'order'   => array( 'photo' ),
						'retired' => array(),
					),
				),
			)
		);

		$result = gwc_pp_apply_uploads( 'clinic', array( 'photo' => self::THEIRS ), 7, self::POST );

		$this->assertSame( '', $result['values']['photo'] );
	}

	public function test_apply_uploads_keeps_the_posts_own_attachment_end_to_end(): void {
		update_option( 'gwc_pp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwc_pp_settings_cache( null, true );

		gwc_pp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array( $this->field ),
						'order'   => array( 'photo' ),
						'retired' => array(),
					),
				),
			)
		);

		$result = gwc_pp_apply_uploads( 'clinic', array( 'photo' => self::MINE ), 7, self::POST );

		$this->assertSame( self::MINE, $result['values']['photo'] );
	}
}
