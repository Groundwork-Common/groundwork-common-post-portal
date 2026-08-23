<?php
/**
 * The badges on a row of the portal list.
 *
 * Split out of the renderer so the decision can be tested without the markup,
 * which is the half worth testing — see ColophonTest for the same shape.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class PortalListTest extends TestCase {

	private const POST = 60;

	protected function setUp(): void {
		gwc_pp_test_reset();
		gwc_pp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );
	}

	/**
	 * The row's post, as the list would hand it over.
	 *
	 * @param string $status Post status.
	 * @return WP_Post
	 */
	private function post( string $status = 'publish' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = self::POST;
		$post->post_type   = 'clinic';
		$post->post_status = $status;
		$post->post_title  = 'Main Street Clinic';

		return $post;
	}

	/**
	 * Put a changeset on the row's post.
	 */
	private function submit_a_change(): void {
		gwc_pp_store_changeset( self::POST, 5, array( 'phone' => '0199' ) );
	}

	/* ── Nothing waiting ─────────────────────────────────────────────────── */

	public function test_a_quiet_row_carries_only_its_status(): void {
		$badges = gwc_pp_list_badges( $this->post() );

		$this->assertCount( 1, $badges );
		$this->assertSame( 'publish', $badges[0]['slug'] );
		$this->assertSame( 'Published', $badges[0]['label'] );
	}

	public function test_an_unpublished_row_says_so(): void {
		$badges = gwc_pp_list_badges( $this->post( 'draft' ) );

		$this->assertCount( 1, $badges );
		$this->assertSame( 'Not published', $badges[0]['label'] );
	}

	/* ── Something waiting ───────────────────────────────────────────────── */

	public function test_a_row_with_a_changeset_gains_a_second_badge(): void {
		$this->submit_a_change();

		$badges = gwc_pp_list_badges( $this->post() );

		$this->assertCount( 2, $badges );
		$this->assertSame( 'waiting', $badges[1]['slug'] );
		$this->assertSame( 'Changes waiting', $badges[1]['label'] );
	}

	/**
	 * The status badge is not replaced, it is joined.
	 *
	 * The entry is still published and saying so is the point: what is waiting
	 * has not landed, and a row that stopped saying "Published" would suggest it
	 * had been taken down.
	 */
	public function test_the_status_badge_survives_alongside_it(): void {
		$this->submit_a_change();

		$badges = gwc_pp_list_badges( $this->post() );

		$this->assertSame( 'publish', $badges[0]['slug'] );
		$this->assertSame( 'Published', $badges[0]['label'] );
	}

	public function test_the_status_leads_and_the_waiting_badge_follows(): void {
		$this->submit_a_change();

		$this->assertSame(
			array( 'publish', 'waiting' ),
			array_column( gwc_pp_list_badges( $this->post() ), 'slug' ),
			'What the entry is, then what is happening to it.'
		);
	}

	/**
	 * The two states that must never wear the same words.
	 *
	 * gwc_pp_status_labels() already calls WordPress's own `pending` status
	 * "Waiting for review", and that means the entry has never been published.
	 * A changeset means the opposite — the entry is live and an edit to it is
	 * waiting. A post that is somehow both would otherwise show one phrase twice
	 * meaning two different things.
	 */
	public function test_the_waiting_badge_does_not_borrow_the_pending_status_label(): void {
		$this->submit_a_change();

		$badges = gwc_pp_list_badges( $this->post( 'pending' ) );
		$labels = array_column( $badges, 'label' );

		$this->assertCount( 2, $badges );
		$this->assertSame( array_unique( $labels ), $labels, 'Two badges, two different words.' );
		$this->assertSame( 'Waiting for review', $labels[0], 'The post status keeps its own label.' );
		$this->assertSame( 'Changes waiting', $labels[1] );
	}

	/* ── Only this post's changeset ──────────────────────────────────────── */

	public function test_another_posts_changeset_does_not_leak_onto_this_row(): void {
		gwc_pp_test_post( 61, 'clinic', 'publish', 0, 'Somewhere Else' );
		gwc_pp_store_changeset( 61, 5, array( 'phone' => '0199' ) );

		$this->assertCount( 1, gwc_pp_list_badges( $this->post() ) );
	}

	public function test_an_approved_change_takes_the_badge_with_it(): void {
		$this->submit_a_change();
		gwc_pp_apply_changeset( self::POST, 1 );

		$this->assertCount( 1, gwc_pp_list_badges( $this->post() ) );
	}

	public function test_a_rejected_change_takes_the_badge_with_it(): void {
		$this->submit_a_change();
		gwc_pp_reject_changeset( self::POST, 1, 'No.' );

		$this->assertCount( 1, gwc_pp_list_badges( $this->post() ) );
	}
}
