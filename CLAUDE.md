# Working in this repository

A WordPress plugin: let the people who own your content edit it from the front
end, without ever handing them a wp-admin login. You choose the post types, you
map the fields, they sign in with a link in their email.

Read `README.md`. It carries more normative documentation than most — the
authorization table, the field-type contract, the review ladder, uploads,
handover, blocked words.

The founding rule, from that README:

> Nothing knows what a field means. There is no `$post['city']` anywhere in this
> codebase.

## Two structural pillars

**1. One authorization function.** `gwcpp_user_can_edit_post( int $user_id, int
$post_id ): bool` in `inc/access.php`. Never add a second path, never inline an
equivalent check, and do not introduce a pluggable strategy registry — the main
file explains why:

> A registry of pluggable access strategies would be more elegant and would mean
> the answer to that question lives in more than one place, which is the one
> property an authorization check must never have.

Guards **end the request** rather than returning a value. Keep it that way;
nothing underneath would catch a handler that forgot.

**2. A field-type registry of callables** in `inc/field-types.php`, with a
seven-callable contract enforced by `GWCPP_TYPE_CONTRACT`: `render_portal`,
`render_admin`, `sanitize`, `validate`, `is_empty`, `to_display`, `schema_form`.
**Nothing may branch on `$field['type']` outside that file.** Rich types register
themselves onto the `gwcpp_field_types` filter.

## The most dangerous file

`inc/field-media.php`, and its own header says so:

> This takes a FILE from somebody with no wp-admin access and writes it into the
> webroot, and then WordPress serves that path back over HTTP. Get it wrong and
> the portal is a remote code execution hole with a nice form on it.

Six ordered checks, none redundant. **Do not simplify, reorder or "deduplicate"
them.** Do not swap the MIME allow-list for `get_allowed_mime_types()` — "a list
this file does not control is not an allow-list, it is a hope." Do not remove the
EXIF-stripping re-encode or `test_form => false`.

## The shape of the code

Procedural PHP, prefix `gwcpp_`, **zero classes, zero namespaces**, 32 files in
`inc/`, guarded requires in a documented order. No build step —
`blocks/portal/edit.js` and `edit.asset.php` are both hand-written and must stay
in step, or the editor throws an error that looks like a WordPress bug.

## Verifying a change

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar
php phpunit-11.phar

npx @wordpress/env start
npx @wordpress/env run cli wp eval-file \
  wp-content/plugins/groundwork-common-post-portal/tests/integration/access.php
# also phase2.php and phase3.php
```

**There is no test CI.** `.github/workflows/` holds only `deploy.yml`. No lint
job, no unit job, no integration job, no 7.4 check — the floor in the header is
asserted and never verified. The only automated gate is the tag-versus-header
check inside `deploy.yml`, which runs on a published Release. **Nothing on push
will catch you. Run the phar and all three integration scripts by hand.**

`.dev/` is gitignored, so a fresh clone has no seed data and no way to read a
sign-in link until you recreate it from the README recipe. wp-env's default
`wordpress@localhost` From address has no TLD, so PHPMailer rejects it and
`wp_mail()` returns false before anything is sent — that is why a mail catcher is
part of the setup.

There is no phpcs ruleset and no Composer here, despite the `phpcs:ignore`
annotations. Never add one without a `--` reason.

## Check your branch

The working tree has been sitting on **`phase-3-lifecycle`, not `main`**, with
unmerged work. Confirm the intended branch before committing anything.

## Traps that have already cost time

- **The portal page ID is pinned, never resolved by slug.** Every sign-in link
  ever sent points at a URL and those links sit in inboxes for weeks; resolving by
  slug means renaming the page silently breaks every link at once, with no error
  anywhere.
- **Review-reminder tokens are not transients.** `wp transient delete --all` is a
  routine deploy step and the standard first move when debugging a cache, and it
  would invalidate every reminder link in every inbox at once. An integration test
  flushes every transient and then uses the link.
- **Ladder rungs are marked delivered only after the mail is away.** A run that
  dies in between leaves them recorded as delivered forever, so a partner loses
  their final warning having been told nothing. A duplicate reminder is far
  cheaper than a silently skipped one.
- **One changeset per post, never a queue.** A queue lets staff approve edit 1,
  then approve edit 2 written against pre-edit-1 values, silently reverting the
  first.
- **`enctype` is unconditional on the form.** Missing it sends filenames instead
  of files — no error, no warning, an upload that never happens — and the
  condition that gets it wrong is "somebody added a media field later".
- **Granting the portal role to an existing user is refused, not silent.** Adding
  it to an administrator would hand them the wp-admin lockout.
- **Unchanged field values are never screened by blocked words.** The original hit
  exactly this, with an organisation whose real name matched.
- **Page caching is only half-solvable in PHP.** `nocache_headers()` alone sends
  `must-revalidate`, which several CDNs read as "store it, just revalidate".

Note a doc drift: `README.md` names `_gwcpp_orgs` and `_gwcpp_editors`; the actual
constants are `GWCPP_USER_ORG_META = '_gwcpp_org'` and `GWCPP_POST_EDITOR_META =
'_gwcpp_editor'`, singular. **Trust the constants.**

## Hard rules

1. **Never grant the portal role real WordPress capabilities.** It holds `read`
   plus the marker cap `gwcpp_use_portal` and nothing else. Enabled post types
   typically use `capability_type => 'post'`, so real caps would leak access to
   every ordinary post on the site.
2. **The owned-post cache group is non-persistent on purpose.** Making
   `GWCPP_CACHE_GROUP` persistent is an access-control decision about stale data.
3. **POSTs dispatch from `template_redirect`**, not `admin-post.php` or
   `admin-ajax.php` — the role is redirected away from `/wp-admin/`.
4. **Portal users cannot delete anything.** The strongest action is
   unpublish-to-draft, and that is not configurable.
5. **Sign-in failures stay indistinguishable** — wrong ID, not yours, does not
   exist. Only a stale nonce gets a real message.
6. **Uninstall deletes no posts, no post meta, no users**, not even armed.
7. **A handover never removes anybody.** Removal stays a staff action in wp-admin.
8. **`gwcpp_allowed_upload_types` and `gwcpp_richtext_allowed_html` are security
   surfaces.** Widen either only on purpose.
9. **Add every new hook to the README table.**
10. Bump header, `GWCPP_VERSION`, `readme.txt` Stable tag, changelog and upgrade
    notice together.

## Vocabulary

**portal** the front-end page, pinned by ID · **portal user** the
`gwcpp_portal_user` role · **organisation** the primary access path · **direct
grant** per-post access meta · **changeset** one whole submission in one meta row
· **diff** computed at view time, never stored · **sign-in link** 15-minute
single-use transient · **durable token** 7-day user meta that survives a transient
flush · **ladder** nudge at cadence−1, name at cadence, warn at +1, hide at ×2 ·
**auto-expired** the marker that makes hiding reversible · **handoff** a 3-day
hashed token.

## Where work is tracked

GitHub Issues on this repo. Run `gh issue list` before starting.
