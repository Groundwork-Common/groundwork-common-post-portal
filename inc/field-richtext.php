<?php
/**
 * The rich text field, and the HTML a portal user is allowed to write.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── Narrower than post KSES, and applied unconditionally ────────────────────
 * WordPress already has an answer to "what HTML may this user submit": KSES,
 * driven by the unfiltered_html capability. That answer is wrong here in both
 * directions.
 *
 * Too permissive, because the `post` allow-list includes things that make
 * perfect sense for an editor writing an article and no sense at all for a
 * partner writing three sentences about their opening hours — <iframe>, style
 * attributes, class attributes that can be used to impersonate site chrome, and
 * on some setups <object>. A portal user embedding an iframe on a page the site
 * owns is a defacement waiting to happen.
 *
 * And it is not applied at all when it matters most: unfiltered_html is granted
 * to administrators and, on single-site installs, to editors. The moment
 * somebody gives a portal account a second role — or a security plugin grants
 * caps broadly, or the site is multisite and the constant is set — KSES stops
 * running and raw <script> goes into a post.
 *
 * So this file's list is used every time, for every user, regardless of
 * capability. There is no path through gwc_pp_sanitize_richtext() that stores
 * what was submitted. The cost is that a portal user cannot write an embed. That
 * is the intended cost.
 * ───────────────────────────────────────────────────────────────────────────
 */

add_filter( 'gwc_pp_field_types', 'gwc_pp_register_richtext_type' );

/**
 * Register the type.
 *
 * @param array $types Registry.
 * @return array
 */
function gwc_pp_register_richtext_type( array $types ): array {
	$types['richtext'] = array(
		'label'         => __( 'Formatted text', 'groundwork-common-post-portal' ),
		'group'         => 'rich',
		'render_portal' => 'gwc_pp_render_richtext',
		'render_admin'  => 'gwc_pp_render_richtext_admin',
		'sanitize'      => 'gwc_pp_sanitize_richtext',
		'validate'      => 'gwc_pp_validate_richtext',
		'is_empty'      => 'gwc_pp_empty_richtext',
		'to_display'    => 'gwc_pp_display_richtext',
		'schema_form'   => 'gwc_pp_schema_form_richtext',
	);

	return $types;
}

/**
 * The tags and attributes a portal user may write.
 *
 * Paragraphs, line breaks, emphasis, links, and lists. Headings from h3 down,
 * because h1 and h2 belong to the page's own structure and letting submitted
 * content claim them breaks the document outline for anyone navigating by
 * heading.
 *
 * @return array
 */
function gwc_pp_richtext_allowed_html(): array {
	/*
	 * Empty, and it has to be written this way. No `class` and no `style`,
	 * because both let submitted content borrow the site's own visual language,
	 * which is how a paragraph comes to look like an official notice — and no
	 * `id` either, for the same reason plus DOM clobbering and anchor hijacking.
	 *
	 * This used to say `'id' => false`, which reads as "not allowed" and is not.
	 * wp_kses_attr_check() rejects on `! isset( $allowed[ $name ] ) || '' ===
	 * $allowed[ $name ]`; isset() is true for false and '' === false is false,
	 * so the attribute passed the gate, and is_array( false ) then skipped the
	 * value check — allowing `id` unconditionally on every tag below. An
	 * attribute is excluded by not appearing here at all.
	 */
	$common = array();

	/**
	 * The HTML a portal user may submit.
	 *
	 * Widen with care, and never to include script, iframe, object, embed,
	 * form, input or style — every one of those turns a text field into a way
	 * to run or collect something on a page the site owns.
	 *
	 * @param array $allowed wp_kses() allow-list.
	 */
	return (array) apply_filters(
		'gwc_pp_richtext_allowed_html',
		array(
			'p'          => $common,
			'br'         => array(),
			'strong'     => $common,
			'b'          => $common,
			'em'         => $common,
			'i'          => $common,
			'u'          => $common,
			'ul'         => $common,
			'ol'         => $common,
			'li'         => $common,
			'h3'         => $common,
			'h4'         => $common,
			'blockquote' => $common,
			'a'          => array(
				'href'  => true,
				'title' => true,
				// rel and target are set by us below, not accepted from input.
			),
		)
	);
}

/**
 * Clean submitted HTML.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_sanitize_richtext( $raw, array $field = array() ): string {
	$html = gwc_pp_scalar_string( $raw );

	if ( '' === trim( $html ) ) {
		return '';
	}

	/*
	 * wp_kses with our own list, never wp_kses_post, and never conditional on
	 * current_user_can( 'unfiltered_html' ) — see the note at the top.
	 */
	$html = wp_kses( $html, gwc_pp_richtext_allowed_html(), array( 'http', 'https', 'mailto', 'tel' ) );

	// Links out of submitted content get rel="nofollow noopener" whether or not
	// the submitter wrote one, which is why rel is not in the allow-list.
	$html = gwc_pp_harden_links( $html );

	/*
	 * Characters, not bytes. The setting is labelled "Character limit" on the
	 * Fields screen and the refusal below says "under %d characters", and
	 * strlen() counts neither — it counts bytes, so a limit of 300 refused
	 * Japanese or Cyrillic text at about a hundred characters and then told the
	 * person they had written too much. gwc_pp_sanitize_text() has always used
	 * mb_substr for exactly this reason; this is the same rule, applied to the
	 * one type that was still measuring the other thing.
	 *
	 * mb_strlen unguarded because WordPress polyfills it in wp-includes/compat.php
	 * on any build without mbstring, which is why blocked-words.php calls it the
	 * same way.
	 */
	$max = (int) gwc_pp_field_setting( $field, 'maxlength', 0 );
	if ( $max > 0 && mb_strlen( wp_strip_all_tags( $html ) ) > $max ) {
		/*
		 * Truncating HTML by length breaks tags in half and produces markup
		 * that closes elements the page never opened. Refusing is the honest
		 * option; the validator below turns this into a message.
		 */
		return '';
	}

	return trim( $html );
}

/**
 * Add rel and target to every link in submitted content.
 *
 * @param string $html Cleaned HTML.
 * @return string
 */
function gwc_pp_harden_links( string $html ): string {
	return (string) preg_replace_callback(
		'/<a\s([^>]*)>/i',
		static function ( $m ) {
			$attrs = preg_replace( '/\s*(rel|target)="[^"]*"/i', '', $m[1] );

			return '<a ' . trim( (string) $attrs ) . ' rel="nofollow noopener" target="_blank">';
		},
		$html
	);
}

/**
 * The editor.
 *
 * Teeny mode: bold, italic, lists, link, and nothing else. The full editor
 * offers controls for things the allow-list above silently removes on save,
 * and a toolbar button whose effect vanishes when you press Save is worse than
 * no button.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwc_pp_render_richtext( array $field, $value, string $name, array $ctx = array() ): void {
	unset( $ctx );

	$id = gwc_pp_field_id( $name );

	wp_editor(
		is_scalar( $value ) ? (string) $value : '',
		// wp_editor's id must be a valid HTML id AND, in practice, lowercase
		// with no dashes for the TinyMCE instance name to work reliably.
		str_replace( '-', '_', $id ),
		array(
			'textarea_name' => $name,
			'textarea_rows' => max( 4, (int) gwc_pp_field_setting( $field, 'rows', 8 ) ),
			'teeny'         => true,
			'media_buttons' => false,
			'quicktags'     => false,
			'tinymce'       => array(
				'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
				'toolbar2' => '',
			),
		)
	);
}

/**
 * The same editor in the meta box.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 */
function gwc_pp_render_richtext_admin( array $field, $value, string $name ): void {
	gwc_pp_render_richtext( $field, $value, $name, array() );
}

/**
 * Report a value that was refused for length.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_validate_richtext( $value, array $field = array() ): string {
	unset( $value );

	$max = (int) gwc_pp_field_setting( $field, 'maxlength', 0 );
	if ( $max <= 0 ) {
		return '';
	}

	return sprintf(
		/* translators: %d: a number of characters. */
		__( 'Please keep this under %d characters.', 'groundwork-common-post-portal' ),
		$max
	);
}

/**
 * True when there is no text.
 *
 * Measured on the text, not the markup: an editor left alone often submits
 * "<p></p>" or "<p><br></p>", which is not empty as a string and is empty as
 * far as anybody reading the page is concerned.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return bool
 */
function gwc_pp_empty_richtext( $value, array $field = array() ): bool {
	unset( $field );

	if ( ! is_scalar( $value ) ) {
		return true;
	}

	$text = wp_strip_all_tags( (string) $value );

	/*
	 * Entities decoded BEFORE the whitespace check, not after stripping tags
	 * and hoping. strip_tags leaves "&nbsp;" as those six literal characters,
	 * which trim() considers perfectly good content — so without this, the
	 * single most common thing TinyMCE submits for a field somebody just
	 * emptied, "<p>&nbsp;</p>", is stored forever as a non-empty value that
	 * renders as a blank line nobody can find or remove.
	 */
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	// The decoded non-breaking space is not whitespace to trim().
	$text = str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $text );

	return '' === trim( $text );
}

/**
 * Plain text, for the diff and the emails.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_display_richtext( $value, array $field = array() ): string {
	unset( $field );

	if ( ! is_scalar( $value ) ) {
		return '';
	}

	$text = trim( wp_strip_all_tags( (string) $value ) );
	$text = (string) preg_replace( '/\s+/', ' ', $text );

	/*
	 * Long bodies make an email diff unreadable. The reviewer opens the post to
	 * read it properly; this is for spotting that it changed.
	 *
	 * Cut on characters rather than bytes, because substr() at a fixed byte
	 * offset lands in the middle of a multibyte character roughly two times in
	 * three and leaves a dangling continuation byte. The result is not merely
	 * an odd-looking cut: the string is no longer valid UTF-8, and WordPress's
	 * escaping refuses to pass invalid UTF-8 through — so the diff row and the
	 * review email showed nothing at all where the changed text should have
	 * been, on precisely the entries whose text is not Latin.
	 */
	if ( mb_strlen( $text ) > 300 ) {
		$text = mb_substr( $text, 0, 300 ) . '…';
	}

	return $text;
}

/**
 * Rows and length, on the Fields screen.
 *
 * @param array $field Field definition.
 */
function gwc_pp_schema_form_richtext( array $field ): void {
	gwc_pp_schema_setting_input( 'rows', __( 'Rows', 'groundwork-common-post-portal' ), gwc_pp_field_setting( $field, 'rows', 8 ), 'number' );
	gwc_pp_schema_setting_input(
		'maxlength',
		__( 'Character limit', 'groundwork-common-post-portal' ),
		gwc_pp_field_setting( $field, 'maxlength' ),
		'number',
		__( 'Counted on the text, not the markup. Leave blank for no limit.', 'groundwork-common-post-portal' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Portal users can write paragraphs, bold, italic, lists, links and small headings. Anything else — embeds, scripts, styling — is removed when they save, whoever they are.', 'groundwork-common-post-portal' )
	);
}
