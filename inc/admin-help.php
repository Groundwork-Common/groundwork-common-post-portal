<?php
/**
 * Contextual help for the plugin's screens.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the help tabs.
 *
 * Hooked from admin-screen.php on the load- action of each of our screens, so
 * it never runs on anybody else's.
 */
function gwcpp_add_help_tabs(): void {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	$screen->add_help_tab(
		array(
			'id'      => 'gwcpp-help-setup',
			'title'   => __( 'Getting started', 'groundwork-common-post-portal' ),
			'content' =>
				'<p>' . esc_html__( 'Four things have to be true before anybody can use the portal:', 'groundwork-common-post-portal' ) . '</p><ol>'
				. '<li>' . esc_html__( 'At least one post type is switched on, under General.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'A published page holds the Post Portal block, and is chosen as the portal page.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'That post type has fields mapped on the Fields screen.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'An organisation exists, somebody has been invited to it, and a post has been assigned to it.', 'groundwork-common-post-portal' ) . '</li>'
				. '</ol><p>' . esc_html__( 'None of these fails loudly on its own, which is why the Settings screen lists whichever are still missing.', 'groundwork-common-post-portal' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'gwcpp-help-access',
			'title'   => __( 'Who can edit what', 'groundwork-common-post-portal' ),
			'content' =>
				'<p>' . esc_html__( 'A portal user reaches a post in one of three ways:', 'groundwork-common-post-portal' ) . '</p><ul>'
				. '<li>' . esc_html__( 'They belong to the organisation the post is assigned to. This is the usual way, and it is how several people share one listing.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'They were granted access to that one post individually, in its Portal access box.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'They wrote it, and "the post author may edit their own" is switched on for that post type. This is off by default, because on most sites the author of an imported or staff-written post is whoever ran the import.', 'groundwork-common-post-portal' ) . '</li>'
				. '</ul><p>' . esc_html__( 'Switching a post type off withdraws every one of those immediately, for every post of that type.', 'groundwork-common-post-portal' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'gwcpp-help-safety',
			'title'   => __( 'What portal users cannot do', 'groundwork-common-post-portal' ),
			'content' =>
				'<ul>'
				. '<li>' . esc_html__( 'They cannot delete anything. The strongest action available is taking a post off the public site, which sets it back to a draft and can be undone in one click.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'They cannot reach wp-admin. Any attempt sends them back to the portal.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'They cannot change a field you did not map, even by editing the form in their browser. The save path reads your list of fields, not their submission.', 'groundwork-common-post-portal' ) . '</li>'
				. '<li>' . esc_html__( 'They cannot see or edit anything belonging to another organisation.', 'groundwork-common-post-portal' ) . '</li>'
				. '</ul>',
		)
	);

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'More', 'groundwork-common-post-portal' ) . '</strong></p>'
		. '<p><a href="' . esc_url( GWCPP_GWC_URL ) . '">' . esc_html__( 'Groundwork Common', 'groundwork-common-post-portal' ) . '</a></p>'
	);
}
