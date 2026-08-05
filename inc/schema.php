<?php
/**
 * The field schema: storage, sanitization, ordering, versioning, retirement.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── The shape ───────────────────────────────────────────────────────────────
 * One option, keyed by post type:
 *
 *   [ 'version' => 1,
 *     'types' => [
 *       'bdbank_location' => [
 *         'fields'  => [ …field definitions… ],
 *         'order'   => [ '__title', 'address', 'phone' ],
 *         'retired' => [ …definitions whose post meta still exists… ],
 *       ],
 *     ] ]
 *
 * `order` is a flat list of keys, not an integer `order` property on each
 * definition. The rejected alternative rewrites every other field's row when
 * one moves, needs a full renumber for drag-to-position, and — the property
 * that actually matters — cannot self-heal. Here a key that no longer exists is
 * ignored at render time, and a field missing from the order is appended at the
 * end. That is what makes editing the schema safe rather than a migration.
 *
 * `retired` holds definitions an admin removed from the form while their post
 * meta is still on every post. Deleting the definition outright would leave the
 * data unreadable — nothing left would know that `_hours` was a multiselect
 * whose value 'tue' means Tuesday — so removal moves the definition here
 * instead. Nothing renders from `retired`; it exists so the data can be read,
 * exported, or restored.
 *
 * ── Synthetic keys ──────────────────────────────────────────────────────────
 * Keys beginning `__` are post columns rather than post meta: __title is
 * post_title, __excerpt is post_excerpt. They sit in `fields` and in `order`
 * beside real keys so the Fields screen is one list with one set of rules, and
 * gwcpp_sanitize_field_key() forbids a leading underscore on a user-created key
 * so the two can never collide.
 *
 * __content is deliberately absent in this phase. Offering it before the rich
 * text type exists would mean rendering existing post bodies into a plain
 * textarea, and saving that back through sanitize_textarea_field() would strip
 * every tag out of content the portal user never intended to touch. A field
 * that quietly destroys data is worse than a field that is not there yet.
 * ───────────────────────────────────────────────────────────────────────────
 */

const GWCPP_SCHEMA_OPTION = 'gwcpp_schema';

/**
 * The post columns a field key can point at, and how each behaves.
 *
 * @return array<string, array>
 */
function gwcpp_synthetic_fields(): array {
	static $fields = null;
	if ( null !== $fields ) {
		return $fields;
	}

	$fields = array(
		'__title'   => array(
			'type'     => 'text',
			'label'    => __( 'Title', 'groundwork-common-post-portal' ),
			'column'   => 'post_title',
			// The one field that is required whatever the schema says. A post
			// saved with an empty title shows as "(no title)" in every list on
			// the site, and the portal user who did it has no way to see that
			// happen.
			'required' => true,
		),
		'__excerpt' => array(
			'type'   => 'textarea',
			'label'  => __( 'Short description', 'groundwork-common-post-portal' ),
			'column' => 'post_excerpt',
		),
		// Unlocked now that the rich text type exists. It was deliberately
		// absent while the only text types were plain, because rendering an
		// existing post body into a textarea and saving it back through
		// sanitize_textarea_field() strips every tag out of content the portal
		// user never meant to touch.
		//
		// Worth an admin knowing before they map it: the allow-list in
		// field-richtext.php applies to whatever comes back, so if staff wrote
		// the body using anything outside it — an embed, a shortcode-rendered
		// block, styling — a portal user saving this field will remove it. That
		// is the price of letting somebody else edit the body, and it is why
		// this is a field an admin adds on purpose rather than one that is
		// there by default.
		'__content' => array(
			'type'   => 'richtext',
			'label'  => __( 'Main text', 'groundwork-common-post-portal' ),
			'column' => 'post_content',
		),
	);

	return $fields;
}

/**
 * True for a key that names a post column rather than post meta.
 *
 * @param string $key Field key.
 * @return bool
 */
function gwcpp_is_synthetic( string $key ): bool {
	return isset( gwcpp_synthetic_fields()[ $key ] );
}

/**
 * Everything a field definition has, with the value an unset one behaves as.
 *
 * @return array
 */
function gwcpp_field_defaults(): array {
	return array(
		'key'         => '',
		'type'        => 'text',
		'label'       => '',
		'description' => '',
		'required'    => false,
		'settings'    => array(),
	);
}

/**
 * The schema a fresh install starts with.
 *
 * Structurally valid and completely empty. Unlike a finder, where a site with
 * no fields configured has nothing to show and the first screen is a puzzle,
 * a portal with no fields configured is a portal for a post type nobody has
 * chosen yet — there is no sensible guess to make, because the plugin does not
 * know whether the first post type will be a clinic or a car dealership.
 *
 * The Fields screen answers this instead with an Import button, which reads
 * what the post type already registered. That is a better first run than sample
 * fields, because it is about the site's own data rather than about ours.
 *
 * @return array
 */
function gwcpp_default_schema(): array {
	return array(
		'version' => GWCPP_SCHEMA_VERSION,
		'types'   => array(),
	);
}

/**
 * The per-request schema memo.
 *
 * Its own function for the same reason gwcpp_settings_cache() is: a writer
 * needs a way to invalidate a reader's cache, and PHP cannot reach another
 * function's static variable.
 *
 * @param array|null $set   Value to store.
 * @param bool       $clear Forget the cached value.
 * @return array|null
 */
function gwcpp_schema_cache( ?array $set = null, bool $clear = false ): ?array {
	static $cache = null;
	if ( $clear ) {
		$cache = null;
		return null;
	}
	if ( null !== $set ) {
		$cache = $set;
	}
	return $cache;
}

add_action( 'update_option_' . GWCPP_SCHEMA_OPTION, 'gwcpp_reset_schema_cache' );
add_action( 'add_option_' . GWCPP_SCHEMA_OPTION, 'gwcpp_reset_schema_cache' );

/**
 * Clear the schema memo.
 */
function gwcpp_reset_schema_cache(): void {
	gwcpp_schema_cache( null, true );
}

/**
 * The whole schema, structurally guaranteed.
 *
 * Every caller can assume 'types' exists and is an array, and that each type's
 * entry has 'fields', 'order' and 'retired'. That guarantee is worth more than
 * it looks: without it every reader needs its own isset() ladder, and the one
 * that forgets is a fatal on a site whose option was written by an older
 * version or restored from a partial backup.
 *
 * @return array
 */
function gwcpp_get_schema(): array {
	$cached = gwcpp_schema_cache();
	if ( null !== $cached ) {
		return $cached;
	}

	$stored = get_option( GWCPP_SCHEMA_OPTION );
	if ( ! is_array( $stored ) ) {
		$stored = gwcpp_default_schema();
	}

	$schema = array(
		'version' => isset( $stored['version'] ) ? (int) $stored['version'] : 0,
		'types'   => array(),
	);

	$types = isset( $stored['types'] ) && is_array( $stored['types'] ) ? $stored['types'] : array();
	foreach ( $types as $post_type => $entry ) {
		$post_type = (string) $post_type;
		if ( '' === $post_type || ! is_array( $entry ) ) {
			continue;
		}
		$schema['types'][ $post_type ] = array(
			'fields'  => isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? array_values( $entry['fields'] ) : array(),
			'order'   => isset( $entry['order'] ) && is_array( $entry['order'] ) ? array_values( array_map( 'strval', $entry['order'] ) ) : array(),
			'retired' => isset( $entry['retired'] ) && is_array( $entry['retired'] ) ? array_values( $entry['retired'] ) : array(),
		);
	}

	gwcpp_schema_cache( $schema );

	return $schema;
}

/**
 * Write the schema.
 *
 * @param array $schema Schema.
 * @return bool
 */
function gwcpp_save_schema( array $schema ): bool {
	$schema['version'] = GWCPP_SCHEMA_VERSION;

	/*
	 * Not autoloaded, unlike gwcpp_settings.
	 *
	 * The settings option is small and is read on every front-end request, by
	 * gwcpp_is_portal(); autoloading it is right. This one is the whole field
	 * map for every enabled post type — labels, descriptions, choice lists,
	 * repeater sub-field trees — and it is read on the portal page, the Fields
	 * screen and the queue, and nowhere else. Autoloaded, an ordinary blog post
	 * request was unserializing tens of kilobytes to answer a question nothing
	 * on the page was going to ask.
	 *
	 * WordPress updates the autoload flag when the value is written, so an
	 * install upgraded from an earlier version keeps the old flag until its
	 * schema is next saved — which is the first time anybody touches the Fields
	 * screen. Costing what it already cost until then is not worth a migration.
	 */
	$saved = update_option( GWCPP_SCHEMA_OPTION, $schema, false );
	gwcpp_reset_schema_cache();

	/**
	 * Fires after the schema is written.
	 *
	 * @param array $schema The schema as stored.
	 */
	do_action( 'gwcpp_schema_saved', $schema );

	return $saved;
}

/**
 * One post type's entry, always structurally complete.
 *
 * @param string $post_type Post type slug.
 * @return array{fields:array,order:array,retired:array}
 */
function gwcpp_type_schema( string $post_type ): array {
	$schema = gwcpp_get_schema();

	return $schema['types'][ $post_type ] ?? array(
		'fields'  => array(),
		'order'   => array(),
		'retired' => array(),
	);
}

/**
 * One post type's fields, in the order they should be rendered.
 *
 * Self-healing in both directions: a key in `order` naming a field that no
 * longer exists is skipped, and a field missing from `order` is appended. So
 * neither editing the field list nor editing the order can produce a form that
 * is missing a field or fatals on a stale key.
 *
 * Fields whose type is no longer registered are dropped here rather than
 * rendered. gwcpp_field_call() would fail closed on them anyway, but a form
 * showing a label with no control under it looks like a broken page, and a form
 * that quietly omits an unrenderable field looks like a form.
 *
 * @param string $post_type Post type slug.
 * @return array<int, array>
 */
function gwcpp_type_fields( string $post_type ): array {
	$entry = gwcpp_type_schema( $post_type );

	$by_key = array();
	foreach ( $entry['fields'] as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$field = array_merge( gwcpp_field_defaults(), $field );
		$key   = (string) $field['key'];
		if ( '' === $key || null === gwcpp_field_type( (string) $field['type'] ) ) {
			continue;
		}
		$by_key[ $key ] = gwcpp_hydrate_field( $field );
	}

	$ordered = array();
	foreach ( $entry['order'] as $key ) {
		if ( isset( $by_key[ $key ] ) ) {
			$ordered[ $key ] = $by_key[ $key ];
			unset( $by_key[ $key ] );
		}
	}

	// Anything the order list did not name, in the order it was defined.
	foreach ( $by_key as $key => $field ) {
		$ordered[ $key ] = $field;
	}

	return array_values( $ordered );
}

/**
 * Fill in what a synthetic field knows about itself.
 *
 * A synthetic field's label is editable — "Title" is right for a clinic and
 * wrong for a person — but its type and its target column are not, and a
 * schema that was hand-edited or restored from elsewhere must not be able to
 * point __title at post_content.
 *
 * @param array $field Field definition.
 * @return array
 */
function gwcpp_hydrate_field( array $field ): array {
	$key = (string) ( $field['key'] ?? '' );
	if ( ! gwcpp_is_synthetic( $key ) ) {
		return $field;
	}

	$synthetic = gwcpp_synthetic_fields()[ $key ];

	$field['type']   = $synthetic['type'];
	$field['column'] = $synthetic['column'];
	if ( ! empty( $synthetic['required'] ) ) {
		$field['required'] = true;
	}
	if ( '' === (string) ( $field['label'] ?? '' ) ) {
		$field['label'] = $synthetic['label'];
	}

	return $field;
}

/**
 * One field, by key.
 *
 * @param string $post_type Post type slug.
 * @param string $key       Field key.
 * @return array|null
 */
function gwcpp_find_field( string $post_type, string $key ): ?array {
	foreach ( gwcpp_type_fields( $post_type ) as $field ) {
		if ( (string) $field['key'] === $key ) {
			return $field;
		}
	}
	return null;
}

/**
 * A field's label, filterable at render time.
 *
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_field_label( array $field ): string {
	$label = (string) ( $field['label'] ?? '' );
	if ( '' === $label ) {
		$label = (string) ( $field['key'] ?? '' );
	}

	/**
	 * A field's visible label.
	 *
	 * @param string $label The label.
	 * @param array  $field Field definition.
	 */
	return (string) apply_filters( 'gwcpp_field_label', $label, $field );
}

/**
 * Sanitize a field key.
 *
 * Meta keys are the thing this plugin writes to, so the rules are strict rather
 * than convenient:
 *
 * - A leading underscore is stripped from user-created keys. It is how
 *   WordPress marks meta as protected, so `_phone` typed on the Fields screen
 *   would produce a key the meta box cannot show and register_meta cannot
 *   expose. Stripping it also guarantees no user key can ever collide with a
 *   `__` synthetic one.
 * - Only lowercase letters, digits and underscores survive. A key with a dash
 *   or a space is legal post meta and is miserable to work with from SQL,
 *   WP-CLI, or anything that reads an export.
 *
 * @param string $key Raw key.
 * @return string Sanitized key, or '' if nothing usable was left.
 */
function gwcpp_sanitize_field_key( string $key ): string {
	$key = strtolower( trim( $key ) );
	$key = preg_replace( '/[^a-z0-9_]/', '_', $key );
	$key = preg_replace( '/_+/', '_', (string) $key );
	$key = ltrim( (string) $key, '_' );

	return trim( $key, '_' );
}

/**
 * Sanitize one field definition.
 *
 * Returns null when the definition cannot be salvaged — no key, or a type this
 * install does not have. Storing an unusable definition would mean every reader
 * needing to re-check what this function already knows.
 *
 * @param array $raw Raw definition, e.g. from $_POST.
 * @return array|null
 */
function gwcpp_sanitize_field( array $raw ): ?array {
	$field = array_merge( gwcpp_field_defaults(), array() );

	$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
	if ( null === gwcpp_field_type( $type ) ) {
		return null;
	}
	$field['type'] = $type;

	$key = (string) ( $raw['key'] ?? '' );

	if ( gwcpp_is_synthetic( $key ) ) {
		// A synthetic key names a post column and passes through as-is.
		$field['key'] = $key;
	} elseif ( gwcpp_type_is_taxonomy( $type ) ) {
		/*
		 * Not a meta key at all — it is a taxonomy slug, so the meta-key rules
		 * do not apply to it. Dashes in particular are ordinary in a taxonomy
		 * slug and are exactly what gwcpp_sanitize_field_key() would replace,
		 * turning `service-type` into `service_type` and pointing the field at
		 * a taxonomy that does not exist.
		 */
		$field['key'] = sanitize_key( $key );
	} else {
		$field['key'] = gwcpp_sanitize_field_key( $key );
	}

	if ( '' === $field['key'] ) {
		return null;
	}

	$field['label']       = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
	$field['description'] = sanitize_text_field( (string) ( $raw['description'] ?? '' ) );
	$field['required']    = ! empty( $raw['required'] );

	$settings = isset( $raw['settings'] ) && is_array( $raw['settings'] ) ? $raw['settings'] : array();

	/*
	 * The choice editor submits a textarea, not an options array. Parsing it
	 * here rather than on the screen that rendered it means a field defined
	 * through WP-CLI or a migration gets the same treatment as one typed in.
	 */
	if ( isset( $settings['options_raw'] ) ) {
		$settings['options'] = gwcpp_parse_options( (string) $settings['options_raw'] );
		unset( $settings['options_raw'] );
	}

	$field['settings'] = gwcpp_sanitize_field_settings( $settings );

	return gwcpp_hydrate_field( $field );
}

/**
 * Sanitize a field's settings bag.
 *
 * A allow-list rather than a pass-through, because settings are written
 * straight into the option and read back into render attributes. Anything not
 * named here is dropped.
 *
 * @param array $raw Raw settings.
 * @return array
 */
function gwcpp_sanitize_field_settings( array $raw ): array {
	$out = array();

	foreach ( array( 'placeholder', 'checkbox_label' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
			$value = sanitize_text_field( (string) $raw[ $key ] );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
	}

	/*
	 * Multi-line, so sanitize_textarea_field rather than sanitize_text_field —
	 * the latter collapses newlines to spaces, which for a definition that is
	 * one column per line means every column merging into the first.
	 */
	if ( isset( $raw['subfields_raw'] ) && is_scalar( $raw['subfields_raw'] ) ) {
		$value = sanitize_textarea_field( (string) $raw['subfields_raw'] );
		if ( '' !== trim( $value ) ) {
			$out['subfields_raw'] = $value;
		}
	}

	foreach ( array( 'allow_new' ) as $key ) {
		if ( ! empty( $raw[ $key ] ) ) {
			$out[ $key ] = true;
		}
	}

	foreach ( array( 'maxlength', 'rows', 'max_choices', 'max_rows', 'max_mb' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
			$value = (int) $raw[ $key ];
			if ( $value > 0 ) {
				$out[ $key ] = $value;
			}
		}
	}

	/*
	 * min, max and step are kept as strings and allowed to be negative or
	 * fractional, which (int) would silently destroy — a rating field with a
	 * step of 0.5 is ordinary, and (int) '0.5' is 0, which is a step no browser
	 * accepts.
	 */
	foreach ( array( 'min', 'max', 'step' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
			$out[ $key ] = (string) ( $raw[ $key ] + 0 );
		}
	}

	if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
		$options = array();
		foreach ( $raw['options'] as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) ( $option['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$label     = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
			$options[] = array(
				'value' => $value,
				'label' => '' !== $label ? $label : $value,
			);
		}
		if ( $options ) {
			$out['options'] = $options;
		}
	}

	return $out;
}

/**
 * Add or replace a field on a post type, and put it in the order.
 *
 * @param string $post_type Post type slug.
 * @param array  $field     Sanitized definition.
 * @return bool
 */
function gwcpp_put_field( string $post_type, array $field ): bool {
	$schema = gwcpp_get_schema();
	$entry  = gwcpp_type_schema( $post_type );
	$key    = (string) $field['key'];

	$replaced = false;
	foreach ( $entry['fields'] as $i => $existing ) {
		if ( is_array( $existing ) && (string) ( $existing['key'] ?? '' ) === $key ) {
			$entry['fields'][ $i ] = $field;
			$replaced              = true;
			break;
		}
	}
	if ( ! $replaced ) {
		$entry['fields'][] = $field;
	}

	if ( ! in_array( $key, $entry['order'], true ) ) {
		$entry['order'][] = $key;
	}

	/*
	 * Re-adding a key that was retired takes it off the retired list. Leaving
	 * it on both would mean an export listing the same field twice, and the
	 * retired copy is by definition the stale one.
	 */
	$entry['retired'] = array_values(
		array_filter(
			$entry['retired'],
			static function ( $retired ) use ( $key ) {
				return ! is_array( $retired ) || (string) ( $retired['key'] ?? '' ) !== $key;
			}
		)
	);

	$schema['types'][ $post_type ] = $entry;

	return gwcpp_save_schema( $schema );
}

/**
 * Retire a field: off the form, out of the order, definition kept.
 *
 * Never deletes post meta. The values are on every post already, and a schema
 * screen is not where somebody expects to destroy data — see the same argument
 * at greater length in uninstall.php.
 *
 * @param string $post_type Post type slug.
 * @param string $key       Field key.
 * @return bool
 */
function gwcpp_retire_field( string $post_type, string $key ): bool {
	$schema = gwcpp_get_schema();
	$entry  = gwcpp_type_schema( $post_type );

	$removed = null;
	foreach ( $entry['fields'] as $i => $field ) {
		if ( is_array( $field ) && (string) ( $field['key'] ?? '' ) === $key ) {
			$removed = $field;
			unset( $entry['fields'][ $i ] );
			break;
		}
	}

	if ( null === $removed ) {
		return false;
	}

	$entry['fields']  = array_values( $entry['fields'] );
	$entry['order']   = array_values( array_diff( $entry['order'], array( $key ) ) );
	$entry['retired'] = array_values( array_merge( $entry['retired'], array( $removed ) ) );

	$schema['types'][ $post_type ] = $entry;

	return gwcpp_save_schema( $schema );
}

/**
 * Set a post type's field order.
 *
 * Keys that name no field are dropped, and fields the submitted order forgot
 * are appended. Neither is an error worth reporting: a reorder form built from
 * a schema that changed in another tab is an ordinary race, and the safe
 * resolution is to keep every field and lose only the stale position.
 *
 * @param string   $post_type Post type slug.
 * @param string[] $order     Field keys, in order.
 * @return bool
 */
function gwcpp_set_field_order( string $post_type, array $order ): bool {
	$schema = gwcpp_get_schema();
	$entry  = gwcpp_type_schema( $post_type );

	$known = array();
	foreach ( $entry['fields'] as $field ) {
		if ( is_array( $field ) && '' !== (string) ( $field['key'] ?? '' ) ) {
			$known[] = (string) $field['key'];
		}
	}

	$clean = array();
	foreach ( $order as $key ) {
		$key = (string) $key;
		if ( in_array( $key, $known, true ) && ! in_array( $key, $clean, true ) ) {
			$clean[] = $key;
		}
	}
	foreach ( $known as $key ) {
		if ( ! in_array( $key, $clean, true ) ) {
			$clean[] = $key;
		}
	}

	$entry['order']                = $clean;
	$schema['types'][ $post_type ] = $entry;

	return gwcpp_save_schema( $schema );
}

/*
 * ── Migrations ──────────────────────────────────────────────────────────────
 * Run on `init` rather than on activation, because a schema written by a newer
 * version can arrive on a site by means other than an upgrade: a restored
 * backup, a staging sync, a multisite clone. Activation would never fire for
 * any of those.
 *
 * The runner is deliberately dumb — it applies each step whose number is above
 * the stored version, in order, and writes the new version. Nothing here rolls
 * back, because a rollback of a data migration is a second migration and
 * pretending otherwise is how a half-applied one happens.
 * ───────────────────────────────────────────────────────────────────────────
 */
add_action( 'init', 'gwcpp_maybe_migrate_schema', 5 );

/**
 * Apply any schema migrations this install has not seen.
 */
function gwcpp_maybe_migrate_schema(): void {
	$stored = get_option( GWCPP_SCHEMA_OPTION );

	// Never written. There is nothing to migrate, and writing a default schema
	// here would create an option on every site that never configures a field.
	if ( ! is_array( $stored ) ) {
		return;
	}

	$from = isset( $stored['version'] ) ? (int) $stored['version'] : 0;
	if ( $from >= GWCPP_SCHEMA_VERSION ) {
		return;
	}

	/**
	 * Schema migration steps, keyed by the version they produce.
	 *
	 * @param array<int, callable> $steps Migrations.
	 */
	$steps = (array) apply_filters( 'gwcpp_schema_migrations', array() );
	ksort( $steps );

	$schema = gwcpp_get_schema();
	foreach ( $steps as $version => $step ) {
		if ( (int) $version > $from && is_callable( $step ) ) {
			$schema = (array) call_user_func( $step, $schema );
		}
	}

	gwcpp_save_schema( $schema );
}
