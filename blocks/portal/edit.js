/**
 * The editor view of the portal block.
 *
 * Hand-written ES5 against the wp globals, with no JSX and no build step —
 * see README.md. The block renders on the server and has no attributes, so the
 * editor's whole job is to say what will appear here and to warn about the one
 * misconfiguration that makes it silently do nothing.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'groundwork-common-post-portal/portal', {
		edit: function ( props ) {
			var settings = window.GWC_PP_EDITOR || {};

			var notice = null;
			if ( ! settings.hasPortalPage ) {
				notice = el(
					components.Notice,
					{ status: 'warning', isDismissible: false },
					__(
						'Once you have published this page, choose it as the portal page under Portal → Settings. Until then, sign-in links will not know where to go.',
						'groundwork-common-post-portal'
					)
				);
			} else if ( ! settings.hasPostTypes ) {
				notice = el(
					components.Notice,
					{ status: 'warning', isDismissible: false },
					__(
						'No post types are switched on yet, so there is nothing for anyone to edit. Choose them under Portal → Settings.',
						'groundwork-common-post-portal'
					)
				);
			}

			return el(
				'div',
				blockEditor.useBlockProps( { className: 'gwcpp-editor-preview' } ),
				notice,
				el(
					components.Placeholder,
					{
						icon: 'id-alt',
						label: __( 'Post Portal', 'groundwork-common-post-portal' ),
						instructions: __(
							'People you have invited will sign in here and see the posts assigned to them. Nothing is shown in the editor because what appears depends on who is looking.',
							'groundwork-common-post-portal'
						)
					}
				)
			);
		},

		// Server-rendered: nothing is stored in post content.
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
