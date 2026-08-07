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

Composer owns the tooling. One command runs everything CI runs, in the order a
failure is cheapest to read:

```bash
composer install && composer run check
```

That is `lint` (PHPCS against `phpcs.xml.dist`), then `compat` (PHPCompatibilityWP
against the 7.4 floor), then `test` (PHPUnit). The unit suite needs no database
and no WordPress checkout — `tests/bootstrap.php` stubs the WordPress surface —
and finishes in well under a second. Do not download a PHPUnit phar; the pinned
one comes from `composer.lock`, which is committed so a CI run and a local run
install the same sniffs.

The integration scripts need a running WordPress, and there are **four**, not
three:

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli wp eval-file \
  wp-content/plugins/groundwork-common-post-portal/tests/integration/access.php
# also phase2.php, phase3.php and queue-scale.php
```

**There is test CI, and it is green.** `.github/workflows/test.yml` runs on every
push to `main`, every pull request, and on demand: `unit` across PHP 8.2/8.3/8.4,
`compat` reading the floor out of the plugin header, `standards` running PHPCS,
and `integration` under wp-env against both WordPress versions read out of
`readme.txt` — so "Requires at least" and "Tested up to" are claims CI enforces
rather than numbers somebody typed once. Read that file's header comments before
changing it; they explain why the unit matrix does not start at 7.4.

Integration is skipped on pushes to branches other than `main` — minutes rather
than milliseconds — so a green check on a work-in-progress branch has not run it.

**`deploy.yml` is gated on it.** `test.yml` also carries a `workflow_call`
trigger, and `deploy.yml`'s `deploy` job `needs` a `test` job that calls it, so
publishing to WordPress.org runs the whole suite against the tag first and a red
run publishes nothing. Because the called workflow sees the *caller's* event, the
integration job's `github.event_name != 'push'` condition is true on a release —
so a release runs the wp-env scripts that ordinary branch pushes skip. Do not
give `test.yml` inputs or secrets without checking the call site in `deploy.yml`.

The two files also carry deliberately different `concurrency` blocks, and they
must not converge. `test.yml` cancels in progress, because a superseded branch
run is waste; `deploy.yml` does not, because the run being cancelled is halfway
through an SVN commit to WordPress.org. `deploy.yml`'s group is a fixed string
rather than the usual `${{ github.ref }}`, since every release carries a
different tag and a per-ref group would let two releases publish *concurrently*
— the exact collision it prevents. It must also stay different from `test.yml`'s
group: identical groups in a caller and the workflow it calls make the called
run cancel the run that started it.

`.dev/` is gitignored, but a fresh clone is no longer empty: `tests/seed.php`
carries the demo data and `tests/mu-plugins/mailpit.php` the mail routing, both
committed and both excluded from the release zip by `.distignore`. wp-env's
default `wordpress@localhost` From address has no TLD, so PHPMailer rejects it
and `wp_mail()` returns false before anything is sent — that is why a mail
catcher is part of the setup, locally and in CI alike.

`phpcs.xml.dist` is `WordPress` plus WordPress-Docs, which is what a directory
reviewer runs. Exactly one rule is off wholesale, at the bottom, with its reason;
everything else that complains carries a line-level `phpcs:ignore` with a written
justification. Keep it that way — a ruleset-wide severity 0 produces a green run
that proves nothing.

**It must stay `WordPress` and not `WordPress-Extra`.** They are not the same
standard, and the two sniffs in the difference are `WordPress.DB.SlowDBQuery` and
`WordPress.Security.ValidatedSanitizedInput` — the second being the one that
catches unsanitized superglobal input, which in the plugin with the public forms,
the uploads and the sign-in links is the last one to give up. This file did read
`WordPress-Extra` for most of its life; the ruleset's own description records
what turning it on found.

Two things follow for `phpcs:ignore` comments here, both learned the hard way:

- **A `phpcs:ignore` covers its own line and the next one only.** Three of these
  sat above a multi-line ternary whose violation landed on the third line, so
  they matched nothing. Put the annotation on the line that actually reports.
- **An annotation for a sniff that is not running is indistinguishable from one
  that works.** Before changing this ruleset, and periodically anyway, neutralise
  each `phpcs:ignore` in turn, re-lint that file, and delete the ones that
  suppress nothing — keeping their prose as a plain comment. That sweep removed
  fifteen. A dead ignore on a security line is armed: it silences nothing today,
  but it silences the real warning the day the check above it moves.

## Check your branch

`phase-2-approval-and-rich-fields` and `phase-3-lifecycle` are both fully merged
into `main` now and carry nothing unmerged, but both branches still exist locally
and on the remote. Confirm the intended branch before committing anything.

**And before deploying.** This has now cost time once: a working tree left on
`phase-3-lifecycle` was deployed to the beta site and seeded from `.dev/seed.php`,
a path that no longer exists on `main` — 69 files and some nine thousand lines
behind, with no complaint from anything, because the deploy script deliberately
ships whatever is checked out. A stale branch here looks exactly like a working
one until somebody notices the demo is missing a feature they merged weeks ago.

## The beta site

<https://wp.beta.poo6op.com> is a shared demo install carrying **all three**
Groundwork Common plugins, seeded as one organisation.
`bin/deploy-staging.sh` rsyncs the working tree there — current branch and
uncommitted edits included by design — using `.distignore` as the manifest, then
activates. `--dry-run` first if in doubt. README.md has the full account.

- **The target is not in the repo, and must not be put there.** The script reads
  `SSH_HOST`, `DEST_ROOT` and `SITE_URL` from
  `~/.config/groundwork-common/beta.env` (override with `GWC_BETA_ENV`) and stops
  with instructions if that file is missing. An SSH user and host are not a
  credential, but together they name a valid account on a public host — the half
  of a break-in that is usually the work. Do not "simplify" this back to a
  literal: these repos being private is a setting that reverses in one click, and
  `.distignore` covers the release zip, not the repository.

- **Production shares the SSH login.** `groundworkcommon.com`, a live nonprofit
  site, sits in the same home directory on the same user. A wrong destination
  path does not fail, it succeeds against production. The script refuses to run
  unless it finds a beta-only mu-plugin at the target — do not remove that check
  to make a one-off deploy easier.
- **Mail is trapped, not routed to Mailpit.** There is no sink on that host; an
  mu-plugin intercepts `wp_mail()` at `pre_wp_mail` and stores the message, read
  under Tools → Trapped mail. It hooks `pre_wp_mail` rather than `phpmailer_init`
  because the latter can only redirect a send, not stop it — and a PHPMailer
  throw makes `wp_mail()` return false, which this plugin treats as "the rung was
  not delivered" and walks again on the next pass.
- **The trap holds live credentials.** A sign-in link *is* the authentication
  here, so anyone who can read that screen can sign in as any seeded owner. It is
  behind `manage_options`, and it is acceptable only because every record on that
  box is invented. Never point this at a site with a real owner on it.
- WP-CLI there costs ~30s per invocation. Batch into one `wp eval-file` rather
  than chaining `wp` calls, or a routine step blows a two-minute timeout.

`tests/` is in `.distignore`, so `tests/seed.php` is **not** deployed with the
plugin. Copy it up and run it by absolute path.

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
