<?php
/**
 * The media field: an upload control on a page anyone with a link can reach.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── This is the most dangerous file in the plugin ───────────────────────────
 * Everything else here takes text from somebody and writes it to a database
 * column. This takes a FILE from somebody with no wp-admin access and writes it
 * into the webroot, and then WordPress serves that path back over HTTP. Get it
 * wrong and the portal is a remote code execution hole with a nice form on it.
 *
 * So, in order, and none of these is redundant with another:
 *
 *  1. An explicit MIME allow-list. Deliberately NOT get_allowed_mime_types(),
 *     which on a stock install includes .doc and friends and which any plugin
 *     can widen with a filter — a list this file does not control is not an
 *     allow-list, it is a hope.
 *  2. A size cap, checked before anything is moved.
 *  3. wp_check_filetype_and_ext(), which reads the file's actual bytes rather
 *     than trusting either the extension or the browser-supplied Content-Type.
 *     "evil.php renamed to evil.jpg" dies here.
 *  4. A second check that the type it reports is still on our list, because
 *     step 3 tells you what the file IS, not whether you wanted it.
 *  5. wp_handle_upload with test_form => false, since our own nonce was already
 *     verified by the guard and WordPress's form test would look for fields we
 *     do not send.
 *  6. Re-encoding images through the editor, which strips EXIF — including the
 *     GPS coordinates of a volunteer's home, which is a real thing to leak from
 *     a photo of a donation box.
 *
 * The attachment is authored by the portal user, so it is attributable, and
 * flagged pending until approved so a rejected submission takes its file with
 * it.
 * ───────────────────────────────────────────────────────────────────────────
 */

add_filter( 'gwcpp_field_types', 'gwcpp_register_media_type' );
add_action( 'gwcpp_reap_orphan_uploads', 'gwcpp_reap_orphan_uploads' );
add_action( 'init', 'gwcpp_schedule_upload_reaper' );

/**
 * Register the type.
 *
 * @param array $types Registry.
 * @return array
 */
function gwcpp_register_media_type( array $types ): array {
	$types['media'] = array(
		'label'         => __( 'Image or file', 'groundwork-common-post-portal' ),
		'group'         => 'rich',
		'render_portal' => 'gwcpp_render_media',
		'render_admin'  => 'gwcpp_render_media_admin',
		'sanitize'      => 'gwcpp_sanitize_media',
		'validate'      => 'gwcpp_validate_media',
		'is_empty'      => 'gwcpp_empty_media',
		'to_display'    => 'gwcpp_display_media',
		'schema_form'   => 'gwcpp_schema_form_media',
		// Optional, and only this type has it. Run once per submission by
		// gwcpp_apply_uploads() — see the note there about why a sanitizer must
		// never be the thing that writes a file.
		'upload'        => 'gwcpp_handle_media_upload',
		// Also optional, also run from gwcpp_apply_uploads(), and for the same
		// structural reason: a sanitizer is handed a value with no idea which
		// post it belongs to, and this check is entirely about that.
		'reconcile'     => 'gwcpp_reconcile_media',
	);

	return $types;
}

/**
 * What a portal user may upload.
 *
 * Images and PDFs. Not because nothing else is ever wanted, but because every
 * addition should be a decision somebody makes deliberately for their site
 * rather than one this plugin makes for everybody.
 *
 * @return array<string, string> Extension pattern => MIME type.
 */
function gwcpp_allowed_upload_types(): array {
	/**
	 * MIME types a portal user may upload.
	 *
	 * Widen this only with the file-serving consequences in mind. Anything
	 * added here can be fetched over HTTP by anyone who learns its URL.
	 *
	 * @param array $types Extension pattern => MIME type.
	 */
	return (array) apply_filters(
		'gwcpp_allowed_upload_types',
		array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
		)
	);
}

/**
 * The largest upload allowed, in bytes.
 *
 * Capped against PHP's own limit as well as the setting, because a setting
 * larger than upload_max_filesize produces a truncated file and an error
 * message about nothing.
 *
 * @param array $field Field definition.
 * @return int
 */
function gwcpp_max_upload_bytes( array $field = array() ): int {
	$configured = (int) gwcpp_field_setting( $field, 'max_mb', 0 );
	$bytes      = $configured > 0 ? $configured * MB_IN_BYTES : 8 * MB_IN_BYTES;

	return (int) min( $bytes, wp_max_upload_size() );
}

/**
 * Take one uploaded file and turn it into an attachment.
 *
 * @param array $field   Field definition.
 * @param int   $user_id Who is uploading.
 * @return int|WP_Error|null Attachment ID, an error, or null when no file came.
 */
function gwcpp_handle_media_upload( array $field, int $user_id ) {
	$key = (string) $field['key'];

	// PHP nests $_FILES for array-named inputs in a shape that is famously
	// awkward; gwcpp_file_from_post() flattens exactly the one we generate.
	$file = gwcpp_file_from_post( $key );

	if ( null === $file ) {
		return null;
	}

	if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
		return null;
	}

	if ( UPLOAD_ERR_OK !== $file['error'] ) {
		return new WP_Error(
			'gwcpp_upload_failed',
			UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error']
				? __( 'That file is too big to upload.', 'groundwork-common-post-portal' )
				: __( 'That file did not upload properly. Please try again.', 'groundwork-common-post-portal' )
		);
	}

	$max = gwcpp_max_upload_bytes( $field );
	if ( (int) $file['size'] > $max ) {
		return new WP_Error(
			'gwcpp_upload_too_big',
			sprintf(
				/* translators: %s: a file size, e.g. "8 MB". */
				__( 'That file is too big. The limit is %s.', 'groundwork-common-post-portal' ),
				size_format( $max )
			)
		);
	}

	$allowed = gwcpp_allowed_upload_types();

	/*
	 * Reads the file's own bytes. A .php renamed to .jpg reports its real type
	 * here and is refused below — which is the check the whole feature rests
	 * on, because everything the browser told us about this file is
	 * attacker-controlled.
	 */
	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );

	if ( empty( $checked['type'] ) || ! in_array( $checked['type'], $allowed, true ) ) {
		return new WP_Error(
			'gwcpp_upload_type',
			sprintf(
				/* translators: %s: a list of file extensions. */
				__( 'That kind of file cannot be uploaded. You can send: %s', 'groundwork-common-post-portal' ),
				implode( ', ', array_map( static fn( $ext ) => str_replace( '|', ', ', $ext ), array_keys( $allowed ) ) )
			)
		);
	}

	// Use the name wp_check_filetype_and_ext corrected to, not the one sent.
	if ( ! empty( $checked['proper_filename'] ) ) {
		$file['name'] = $checked['proper_filename'];
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$moved = wp_handle_upload(
		$file,
		array(
			// Our own nonce was verified by the guard before this ran, and
			// WordPress's form test looks for an action field we do not send.
			// Turning it off here is not skipping a check; it is skipping a
			// check for a different form.
			'test_form' => false,
			'mimes'     => $allowed,
		)
	);

	if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
		return new WP_Error(
			'gwcpp_upload_move',
			is_array( $moved ) && isset( $moved['error'] )
				? (string) $moved['error']
				: __( 'That file could not be saved. Please try again.', 'groundwork-common-post-portal' )
		);
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_text_field( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			// Attributable. An anonymous file in the media library is one
			// nobody can ask about later.
			'post_author'    => $user_id,
		),
		$moved['file']
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		/*
		 * wp_delete_file() rather than unlink(): it routes through the
		 * `wp_delete_file` filter, which is how offloading plugins hear that a
		 * file went away, and it does not need the error silenced. Best-effort
		 * cleanup of a file we just wrote and can no longer reference.
		 */
		wp_delete_file( $moved['file'] );
		return new WP_Error( 'gwcpp_upload_attach', __( 'That file could not be saved. Please try again.', 'groundwork-common-post-portal' ) );
	}

	$attachment_id = (int) $attachment_id;

	wp_update_attachment_metadata(
		$attachment_id,
		wp_generate_attachment_metadata( $attachment_id, $moved['file'] )
	);

	gwcpp_strip_image_metadata( $attachment_id, $moved );

	/*
	 * Flagged until approved. gwcpp_discard_attachments() refuses to delete
	 * anything without this, which is what stops a reject or a cron sweep from
	 * touching media somebody else put there.
	 */
	update_post_meta( $attachment_id, GWCPP_PENDING_ATTACHMENT_META, time() );

	return $attachment_id;
}

/**
 * Strip EXIF from an uploaded image.
 *
 * A photo taken on a phone carries the coordinates it was taken at. For a
 * portal where volunteers photograph their own front door, publishing that is
 * a real disclosure, and it is invisible to everybody involved.
 *
 * Re-saving through WP_Image_Editor drops everything that is not pixels. Best
 * effort: a failure here is not worth refusing the upload over, and the image
 * is still perfectly usable.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $moved         The result from wp_handle_upload().
 */
function gwcpp_strip_image_metadata( int $attachment_id, array $moved ): void {
	unset( $attachment_id );

	if ( ! preg_match( '#^image/(jpeg|png|webp)$#', (string) $moved['type'] ) ) {
		return;
	}

	$editor = wp_get_image_editor( $moved['file'] );
	if ( is_wp_error( $editor ) ) {
		return;
	}

	$editor->save( $moved['file'] );
}

/**
 * Flatten the one $_FILES shape this plugin generates.
 *
 * A control named gwcpp_f[photo][file] arrives as
 * $_FILES['gwcpp_f']['name']['photo']['file'] — PHP transposes the arrays, so
 * every key of the file record has to be walked separately. This is the reason
 * so much upload code quietly only supports a flat input name.
 *
 * @param string $key Field key.
 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
 */
function gwcpp_file_from_post( string $key ): ?array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Reached only from gwcpp_apply_uploads(), which runs after the guard has verified a nonce bound to this post.
	if ( ! isset( $_FILES[ GWCPP_FIELD_PARAM ] ) || ! is_array( $_FILES[ GWCPP_FIELD_PARAM ] ) ) {
		return null;
	}

	$files = $_FILES[ GWCPP_FIELD_PARAM ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Each member is validated below and by wp_check_filetype_and_ext(); sanitize_text_field on a tmp_name would corrupt the path.

	$out = array();
	foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $part ) {
		if ( ! isset( $files[ $part ][ $key ]['file'] ) ) {
			return null;
		}

		/*
		 * Scalar, or this is not the shape we generate. A crafted form posting
		 * `gwcpp_f[photo][file][]` makes PHP hand back arrays here, and the
		 * casts below would then emit "Array to string conversion" into the
		 * middle of the page on any host with display_errors on.
		 *
		 * It already failed closed — is_uploaded_file( 'Array' ) is false — so
		 * nothing was ever copied. This is about failing closed quietly.
		 */
		if ( ! is_scalar( $files[ $part ][ $key ]['file'] ) ) {
			return null;
		}

		$out[ $part ] = $files[ $part ][ $key ]['file'];
	}

	$out['name']     = sanitize_file_name( (string) $out['name'] );
	$out['type']     = sanitize_text_field( (string) $out['type'] );
	$out['tmp_name'] = (string) $out['tmp_name'];
	$out['error']    = (int) $out['error'];
	$out['size']     = (int) $out['size'];

	/*
	 * The one thing that makes a temp path trustworthy. Without it, a crafted
	 * submission naming /etc/passwd as tmp_name would have this code copy it
	 * into the media library.
	 */
	if ( '' !== $out['tmp_name'] && ! is_uploaded_file( $out['tmp_name'] ) ) {
		return null;
	}

	return $out;
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

/* ── Rendering ───────────────────────────────────────────────────────────── */

/**
 * The upload control.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored attachment ID.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_media( array $field, $value, string $name, array $ctx = array() ): void {
	$id            = gwcpp_field_id( $name );
	$attachment_id = (int) $value;
	$allowed       = gwcpp_allowed_upload_types();

	// Carries the current value forward, so a save that does not touch the file
	// keeps it rather than clearing it.
	printf(
		'<input type="hidden" name="%s" value="%d" />',
		esc_attr( $name . '[keep]' ),
		(int) $attachment_id
	);

	if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
		echo '<div class="gwcpp-media__current">';

		if ( wp_attachment_is_image( $attachment_id ) ) {
			echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'gwcpp-media__thumb' ) );
		} else {
			printf(
				'<a href="%s" class="gwcpp-media__file">%s</a>',
				esc_url( (string) wp_get_attachment_url( $attachment_id ) ),
				esc_html( get_the_title( $attachment_id ) )
			);
		}

		printf(
			'<label class="gwcpp-check"><input type="checkbox" name="%s" value="1" /> <span>%s</span></label>',
			esc_attr( $name . '[remove]' ),
			esc_html__( 'Remove this', 'groundwork-common-post-portal' )
		);

		echo '</div>';
	}

	printf(
		'<input type="file" id="%1$s" name="%2$s" accept="%3$s"%4$s />',
		esc_attr( $id ),
		esc_attr( $name . '[file]' ),
		esc_attr( implode( ',', array_unique( array_values( $allowed ) ) ) ),
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : ''
	);

	printf(
		'<p class="gwcpp-field__hint">%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: a list of file extensions, 2: a file size. */
				__( 'Accepted: %1$s. Up to %2$s.', 'groundwork-common-post-portal' ),
				str_replace( '|', ', ', implode( ', ', array_keys( $allowed ) ) ),
				size_format( gwcpp_max_upload_bytes( $field ) )
			)
		)
	);
}

/**
 * The wp-admin control. Read-only, deliberately.
 *
 * Staff have the media library, the featured image box, and the editor. A
 * second, worse uploader on the meta box would be three ways to do one thing,
 * and the one written here is the one built for a form with no JavaScript.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored attachment ID.
 * @param string $name  Form control name.
 */
function gwcpp_render_media_admin( array $field, $value, string $name ): void {
	unset( $field );

	$attachment_id = (int) $value;

	printf( '<input type="hidden" name="%s" value="%d" />', esc_attr( $name . '[keep]' ), (int) $attachment_id );

	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		printf( '<p class="description">%s</p>', esc_html__( 'Nothing uploaded.', 'groundwork-common-post-portal' ) );
		return;
	}

	printf(
		'<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
		esc_url( (string) get_edit_post_link( $attachment_id, 'raw' ) ),
		esc_html( get_the_title( $attachment_id ) )
	);
}

/**
 * The stored value: an attachment ID, or ''.
 *
 * The file itself never reaches here — see the note at the top of
 * gwcpp_apply_uploads(). This only handles keeping and clearing what is already
 * there.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return int|string
 */
function gwcpp_sanitize_media( $raw, array $field = array() ) {
	unset( $field );

	if ( ! is_array( $raw ) ) {
		return '';
	}

	if ( ! empty( $raw['remove'] ) ) {
		return '';
	}

	$keep = (int) ( $raw['keep'] ?? 0 );

	/*
	 * Shape only. This value comes back from a hidden field, so a crafted
	 * submission can name any attachment on the site — including one belonging
	 * to another organisation — and confirming it is an attachment does nothing
	 * about that. The ownership question is answered by gwcpp_reconcile_media()
	 * in the save path, which is the first place that knows which post is being
	 * edited. A sanitizer never does.
	 */
	if ( $keep > 0 && 'attachment' === get_post_type( $keep ) ) {
		return $keep;
	}

	return '';
}

/**
 * Refuse a kept attachment that does not belong to the post being edited.
 *
 * ── What this closes ─────────────────────────────────────────────────────────
 * The `keep` control carries the current attachment forward so that a save
 * which does not touch the file does not clear it. It is a hidden input, so its
 * value is whatever the submitter says it is.
 *
 * Without this check, posting somebody else's attachment ID meant the next
 * render of the person's own edit form showed that file's thumbnail, URL and
 * title — for any attachment on the site, including ones hanging off private or
 * unpublished posts. Under `require_approval` the pending value is shown back to
 * the submitter straight away, so it did not even need staff to act. Nothing
 * could be deleted or re-parented this way, but "read any file in the media
 * library" is quite enough on its own.
 *
 * Three things may legitimately appear here, and nothing else:
 *
 *   1. the attachment this submission just uploaded for this field,
 *   2. the value the post already carries,
 *   3. the value a pending changeset carries — because the edit form prefills
 *      from the changeset when there is one, so that is genuinely what was in
 *      the box the person was looking at.
 *
 * Anything else becomes '', which reads as "no file" and is the same outcome as
 * ticking Remove. Refusing rather than erroring is deliberate: a submission that
 * gets here has already been tampered with, and there is no message worth
 * writing for it.
 *
 * @param mixed $value   Sanitized value: an attachment ID, or ''.
 * @param array $field   Field definition.
 * @param int   $post_id Post being edited, or 0 when creating.
 * @param ?int  $fresh   Attachment uploaded for this field by this submission.
 * @return int|string
 */
function gwcpp_reconcile_media( $value, array $field, int $post_id, ?int $fresh = null ) {
	$value = (int) $value;

	if ( $value <= 0 ) {
		return '';
	}

	if ( null !== $fresh && $value === $fresh ) {
		return $value;
	}

	// Creating. There is no post yet, so nothing can have been carried forward
	// and the only honest answer is the one this submission uploaded.
	if ( $post_id <= 0 ) {
		return '';
	}

	$key = (string) $field['key'];

	if ( (int) get_post_meta( $post_id, $key, true ) === $value ) {
		return $value;
	}

	$pending = gwcpp_get_changeset( $post_id );
	if ( null !== $pending && (int) ( $pending['values'][ $key ] ?? 0 ) === $value ) {
		return $value;
	}

	return '';
}

/**
 * Nothing to check that the upload handler has not already refused.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_media( $value, array $field = array() ): string {
	unset( $value, $field );
	return '';
}

/**
 * True when no file is set.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return bool
 */
function gwcpp_empty_media( $value, array $field = array() ): bool {
	unset( $field );
	return (int) $value <= 0;
}

/**
 * A file, named, for the diff and the emails.
 *
 * The filename rather than the ID, because "17 → 43" tells a reviewer nothing
 * about what they are approving.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_media( $value, array $field = array() ): string {
	unset( $field );

	$attachment_id = (int) $value;
	if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
		return '';
	}

	return (string) basename( (string) get_attached_file( $attachment_id ) );
}

/**
 * The size limit, on the Fields screen.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_media( array $field ): void {
	gwcpp_schema_setting_input(
		'max_mb',
		__( 'Largest file, in MB', 'groundwork-common-post-portal' ),
		gwcpp_field_setting( $field, 'max_mb' ),
		'number',
		sprintf(
			/* translators: %s: a file size, e.g. "8 MB". */
			__( 'Leave blank for the default. This server will not accept more than %s whatever is set here.', 'groundwork-common-post-portal' ),
			size_format( wp_max_upload_size() )
		)
	);
}

/*
 * ── Reaping orphans ─────────────────────────────────────────────────────────
 * Rejecting a changeset deletes its uploads, and so does replacing one. What
 * neither covers is a file that lost its changeset by some other route: a post
 * deleted while a submission was pending, a database restored underneath a
 * media library, a fatal halfway through a save.
 *
 * A flagged attachment older than a month whose post has no pending changeset
 * naming it is not waiting for anything.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** How long a pending upload is left alone before it counts as abandoned. */
const GWCPP_ORPHAN_AGE = 30 * DAY_IN_SECONDS;

/**
 * Make sure the daily sweep is scheduled.
 */
function gwcpp_schedule_upload_reaper(): void {
	if ( ! wp_next_scheduled( 'gwcpp_reap_orphan_uploads' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'gwcpp_reap_orphan_uploads' );
	}
}

/**
 * Delete uploads nothing is waiting on any more.
 *
 * @return int How many were deleted.
 */
function gwcpp_reap_orphan_uploads(): int {
	/*
	 * ── Oldest first, and the cap is why ────────────────────────────────────
	 * A hundred at a time is right: this is cron, it force-deletes files, and a
	 * sweep that walks an entire media library in one request is a sweep that
	 * times out halfway.
	 *
	 * But a cap needs an order, and WordPress's default is newest first — which
	 * pointed this query at exactly the wrong end. Only attachments past
	 * GWCPP_ORPHAN_AGE are ever deleted, and the ones a pending changeset still
	 * claims are skipped while still occupying a slot. So a site holding a
	 * hundred flagged uploads newer than thirty days re-examined the same recent
	 * files every night, forever, and the real orphans behind them were never
	 * reached. The sweep ran daily, reported nothing, and never converged.
	 *
	 * Ordered by ID rather than by date for the same reason
	 * gwcpp_every_pending_post_id() is: this run writes to the rows it walks, so
	 * ordering by anything a concurrent request can change lets rows shift
	 * between one night's page and the next.
	 */
	$candidates = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_key'       => GWCPP_PENDING_ATTACHMENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- EXISTS on an indexed key, in cron, bounded to 100.
			'meta_compare'   => 'EXISTS',
		)
	);

	$now     = time();
	$deleted = 0;

	/*
	 * Built once for the whole sweep rather than re-walked per candidate. The
	 * old shape asked the question one attachment at a time, which meant a fresh
	 * pass over the entire queue for each of up to a hundred files.
	 */
	$claimed = gwcpp_claimed_attachment_ids();

	foreach ( $candidates as $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$flagged       = (int) get_post_meta( $attachment_id, GWCPP_PENDING_ATTACHMENT_META, true );

		if ( $flagged <= 0 || ( $now - $flagged ) < GWCPP_ORPHAN_AGE ) {
			continue;
		}

		if ( isset( $claimed[ $attachment_id ] ) ) {
			continue;
		}

		wp_delete_attachment( $attachment_id, true );
		++$deleted;
	}

	return $deleted;
}

/**
 * Every attachment some pending changeset is still holding on to.
 *
 * Keyed by attachment ID so a caller tests membership rather than searching.
 *
 * Asked before deleting rather than inferred from age alone, because a review
 * queue that took five weeks to get through is a slow team, not a licence to
 * delete what they were about to approve — and it reads the complete queue,
 * because a changeset this missed is a file deleted while somebody was still
 * waiting for it.
 *
 * @return array<int, true>
 */
function gwcpp_claimed_attachment_ids(): array {
	$claimed = array();

	foreach ( gwcpp_every_pending_post_id() as $post_id ) {
		$changeset = gwcpp_get_changeset( (int) $post_id );

		if ( null === $changeset ) {
			continue;
		}

		foreach ( $changeset['attachments'] as $attachment_id ) {
			$claimed[ (int) $attachment_id ] = true;
		}
	}

	return $claimed;
}

/**
 * True when some pending changeset still names this attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function gwcpp_attachment_is_claimed( int $attachment_id ): bool {
	return isset( gwcpp_claimed_attachment_ids()[ $attachment_id ] );
}
