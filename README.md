# Groundwork Common Post Portal

Front-end editing for the people who own your content, without a wp-admin
login. You choose the post types, you map the fields, they sign in with a link
in their email.

The user-facing description is in [`readme.txt`](readme.txt). This file is for
whoever works on the code.

## Why it works that way

The plugin is a generalisation of a partner portal built for one diaper bank.
That original worked well and could not be reused: one hardcoded post type, a
field list copy-pasted across five functions that each had to be kept in sync
by hand, a US state dropdown, a five-digit ZIP regex, and a form ID typed into
two files.

So the rule here is that **nothing knows what a field means**. The plugin knows
how to decide who may edit a post, how to render a form from a schema an admin
defined, how to hold a change until somebody approves it, and how to sign a
person in without a password. What the fields *are* is configuration, and there
is no `$post['city']` anywhere in this codebase.

The one place that was not worth generalising is authorization. See below.

## Requirements

WordPress 6.3, PHP 7.4. No build step, no Composer, no npm for anything that
ships. The block's `edit.js` is hand-written ES5 against `wp.element`, and its
`edit.asset.php` is hand-written to match.

## Authorization

`gwcpp_user_can_edit_post( int $user_id, int $post_id ): bool` in
[`inc/access.php`](inc/access.php) is the only function in the plugin that
answers whether somebody may edit something. Every handler, every view and
every list query routes through it or through a helper that calls it.

It returns true when the post's type is portal-enabled **and** any of:

| Path | Stored as | Notes |
| --- | --- | --- |
| Organisation | `_gwcpp_orgs` user meta ∩ `_gwcpp_org` post meta | The primary path. Many users, many posts. |
| Direct grant | `_gwcpp_editors` post meta, array of user IDs | One-off access without an organisation. |
| Author | `post_author` | Off by default, enabled per post type. |

A registry of pluggable access strategies would be more elegant and would mean
the answer lives in more than one place, which is the one property an
authorization check must never have.

Two consequences worth knowing:

- The owned-post set is cached in a **non-persistent** cache group, invalidated
  from `added/updated/deleted_post_meta` and `transition_post_status`.
  Persisting it across requests would mean making an access-control decision on
  out-of-date data.
- The portal role holds `read` and a marker capability, and no real WordPress
  capabilities. Enabled post types usually use `capability_type => 'post'`, so
  granting real caps would leak access to every ordinary post on the site.
  There is nothing underneath these checks that would catch a handler which
  forgot them, which is why the guards end the request rather than returning a
  value a caller has to remember to test.

## Things that are deliberate

- **Portal users cannot delete anything.** The strongest action is unpublish to
  draft, which is reversible. Not configurable.
- **POSTs are dispatched from `template_redirect`**, not `admin-post.php` or
  `admin-ajax.php`. Those live under `/wp-admin/`, which is exactly what the
  role is redirected away from.
- **The portal page is never cached.** `DONOTCACHEPAGE`, `nocache_headers()`
  and an explicit `Cache-Control: private, no-store`. `nocache_headers()` alone
  sends `must-revalidate`, which several CDNs read as "store it, just
  revalidate". If a page cache is ever added to a site running this, it needs
  an exclusion rule there too; nothing in PHP can enforce that.
- **Sign-in failures are silent.** A wrong post ID, a post you do not own, and
  a post that does not exist are indistinguishable. A stale nonce is the one
  guard failure that is nobody's fault, so it gets a plain-language message.
- **Uninstalling deletes no posts, no post meta, and no users** — not even when
  the destructive flag is armed. See [`uninstall.php`](uninstall.php).
- **The schema's order lists are flat arrays of keys**, not an integer `order`
  property on each field. A key that no longer exists is ignored at render
  time, and a field missing from the order is appended. That is what makes
  editing the schema safe rather than a migration.

## Admin screens

The Portal menu reads **Pending Changes, Organisations, Settings** — most
urgent to least. The top-level item opens the queue rather than the settings,
because settings is a screen somebody visits while setting the portal up and
then rarely again, and the queue is the only screen here that is ever urgent.

Two consequences worth knowing:

- **The menu's parent slug is `GWCPP_QUEUE_SLUG`, not `GWCPP_MENU_SLUG`.** The
  latter is still the settings page's own slug and every link to it still
  works; only the parent changed.
- **The order is sorted after the fact**, in `gwcpp_order_submenu()` on
  `admin_menu` priority 100. Organisations is not ours to place — WordPress adds
  it from the post type's `show_in_menu` while it builds the menu, before this
  plugin's `admin_menu` callback runs — so arranging by registration order would
  mean hanging the menu on the order two unrelated files happen to load in.

**Fields is a tab on the Settings screen**, not a page of its own. It is part of
configuring the portal, it is meaningless until a post type is switched on one
tab over, and as a sibling menu item it read as a separate feature. It renders
outside the settings form, because it is five separate actions each with its own
nonce and confirmation, and nesting those in a form whose button says "Save
settings" makes an Enter keypress submit the wrong one.

Admin CSS is matched on the `gwcpp-` slug prefix rather than on
`GWCPP_MENU_SLUG`. WordPress builds a submenu's hook as
`{parent menu title}_page_{slug}`, so matching the settings slug matched only
the settings screen, and the queue's diff tables rendered unstyled.

## Field types

Types are a registry of callables in
[`inc/field-types.php`](inc/field-types.php). Nothing in the plugin branches on
`$field['type']` outside that file. The contract:

| Callable | Signature | Purpose |
| --- | --- | --- |
| `render_portal` | `(array $field, mixed $value, string $name, array $ctx): void` | The front-end control. |
| `render_admin` | `(array $field, mixed $value, string $name): void` | The wp-admin meta box control. |
| `sanitize` | `(mixed $raw, array $field): mixed` | Raw POST to stored value. Never trusts input. |
| `validate` | `(mixed $value, array $field): string` | `''` when valid, else the message shown to the user. |
| `is_empty` | `(mixed $value, array $field): bool` | True deletes the meta row rather than storing. |
| `to_display` | `(mixed $value, array $field): string` | Human-readable, for the approval diff and emails. |
| `schema_form` | `(array $field): void` | Extra controls on the Fields screen for this type. |

Register your own with the `gwcpp_field_types` filter.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `gwcpp_field_types` | filter | Add or alter field types. |
| `gwcpp_org_type_args` | filter | Arguments for the organisation post type. |
| `gwcpp_portal_post_types` | filter | The enabled post types, after settings. |
| `gwcpp_editable_posts` | filter | The list of posts shown to a user. |
| `gwcpp_field_label` | filter | A field's label at render time. |
| `gwcpp_validation_errors` | filter | Problems found in a submission. |
| `gwcpp_token_ttl` | filter | Sign-in token lifetime, in seconds. |
| `gwcpp_rate_limits` | filter | The three rate-limit windows. |
| `gwcpp_load_assets` | filter | Force portal CSS/JS on or off. |
| `gwcpp_allowed_upload_types` | filter | What a portal user may upload. |
| `gwcpp_richtext_allowed_html` | filter | The HTML a portal user may write. |
| `gwcpp_schema_migrations` | filter | Schema migration steps, keyed by version. |
| `gwcpp_schema_saved` | action | After the field schema is written. |
| `gwcpp_fields_saved` | action | After values are written to a post. |
| `gwcpp_changeset_stored` | action | A submission was queued for review. |
| `gwcpp_changeset_applied` | action | A submission was approved. |
| `gwcpp_changeset_rejected` | action | A submission was rejected. |
| `gwcpp_blocked_words` | filter | Words that stop a submission. |
| `gwcpp_reviewed` | action | An entry was confirmed as current. |
| `gwcpp_review_expired` | action | The cycle hid an entry. |
| `gwcpp_handoff_accepted` | action | Somebody accepted a handover. |

Every hook in the plugin is in this table. If you add one, add its row.

Two of these are security surfaces rather than conveniences.
`gwcpp_allowed_upload_types` decides what can be written into the webroot and
served back over HTTP; `gwcpp_richtext_allowed_html` decides what markup a
person with no wp-admin access can put on a page the site owns. Widen either
only on purpose.

## Approval

When a post type has **Changes need staff approval** on, a submission is stored
whole in one post meta row and the published post keeps showing exactly what it
showed before. Staff see an old-against-new comparison under **Portal → Pending
Changes** and approve or reject.

- **One changeset per post, not a queue.** Submitting again replaces what was
  waiting. A queue would let staff approve edit 1, then approve edit 2 written
  against the pre-edit-1 values, silently reverting the first.
- **The diff is computed, never stored.** If staff edit the post while a
  changeset waits, the *old* side updates to match, so what they approve is what
  they were shown.
- **Approving replays through `gwcpp_save_fields()`** — the same path an
  immediate save uses. A field retired from the schema between submission and
  approval is therefore never written.
- The portal form prefills from the pending changeset, not the live post, so
  somebody returning an hour later sees their own submitted values rather than
  concluding the edit was lost and sending it again.
- **The queue screen is paged; nothing that decides anything is.** The screen
  draws `GWCPP_QUEUE_PAGE_SIZE` items and says so when there are more. The menu
  bubble and `gwcpp_claimed_attachment_ids()` use `gwcpp_every_pending_post_id()`
  instead, which walks the lot. That split exists because the reaper asks the
  queue whether an upload is still wanted immediately before force-deleting it:
  asked against a capped, newest-first list, the answer was "no" for the
  oldest-waiting changesets — the ones whose uploads had aged past the
  thirty-day threshold — and the file went while somebody was still waiting for
  it to be approved. A cap on a display is a design decision; a cap under a
  question whose wrong answer deletes data is a bug.

The alternative — flipping `post_status` to `pending` — takes a live listing off
the public site the moment somebody corrects a typo in it. That is not a trade
anyone would agree to if asked.

## Uploads

`inc/field-media.php` is the most dangerous file here, and its header explains
the six checks in order. The short version: an explicit MIME allow-list that is
*not* `get_allowed_mime_types()`, a size cap, `wp_check_filetype_and_ext()`
reading the file's actual bytes, a second check that what it reports is still on
our list, `is_uploaded_file()` on the temp path, and EXIF stripping by
re-encoding through `WP_Image_Editor`.

Under approval an upload is created immediately — there is nowhere else to put a
file — flagged `_gwcpp_pending_for`, and only attached on approve. Rejecting
deletes it, and a daily cron reaps anything whose changeset vanished by some
other route. `gwcpp_discard_attachments()` refuses to touch an attachment
without that flag, so neither a reject nor the cron can delete media somebody
else put there.

## Tests

Unit tests are pure logic with no database and no WordPress checkout —
`tests/bootstrap.php` stubs the WordPress helpers they touch. PHPUnit is
fetched rather than committed:

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar && php phpunit-11.phar
```

`VersionTest` enforces that the plugin header, `GWCPP_VERSION`, the
`Stable tag` in `readme.txt`, and the changelog and upgrade-notice entries all
agree. It fails the moment any one of them is bumped alone.

Integration behaviour is verified under wp-env with `wp eval-file` scripts:

```bash
npx @wordpress/env start && npx @wordpress/env run cli wp eval-file wp-content/plugins/groundwork-common-post-portal/tests/integration/access.php
```

### Seeing the emails

Sign-in links are the product here, so being able to read one matters. Out of
the box you cannot: wp-env's default From is `wordpress@localhost`, which
PHPMailer rejects for having no TLD, so `wp_mail()` returns `false` before
anything reaches SMTP. `/usr/sbin/sendmail` in the container is a busybox
symlink that cannot deliver without a smarthost, and outbound port 25 is
blocked on most connections anyway.

So route mail to a local sink. Start one:

```bash
docker run -d --name gwcpp-mailpit -p 8027:8025 -p 1027:1025 axllent/mailpit
```

`tests/mu-plugins/mailpit.php` already does this — it fixes `wp_mail_from` and
points `phpmailer_init` at `host.docker.internal:1027` with `SMTPAuth` and
`SMTPAutoTLS` both off. Mount it by creating `.wp-env.override.json`:

```json
{ "mappings": { "wp-content/mu-plugins": "./tests/mu-plugins" } }
```

Read the inbox at http://localhost:8027.

It lives in `tests/` rather than somewhere gitignored on purpose. An earlier
version sat in `.dev/`, and was lost the first time the working tree was
cleaned — taking the seed script with it. `tests` is already excluded from the
release zip by `.distignore`, so everything in there is committed *and* absent
from what anybody downloads. `.wp-env.override.json` is still ignored, because
it holds ports that are specific to one machine.

Do **not** point this at a public disposable-inbox service. Those inboxes are
readable by anyone, and a sign-in link is a credential — the whole design of
this plugin is that possession of the link *is* the authentication.

## The review cycle

Off for every post type until somebody sets a cadence in months, because a
plugin that starts emailing a site's partners on activation is doing something
nobody asked for.

Four thresholds, all derived from that one number rather than configured
separately: nudge at **cadence − 1** so somebody signing in for another reason
sees it before it is late, name the date at **cadence**, warn hard at
**cadence + 1**, and hide at **cadence × 2**. The original had these as four
independent constants, which is four numbers to keep in step.

Two things can never be hidden:

- An entry marked exempt.
- An entry **nobody has access to**. There is no owner to chase, so hiding it
  would punish a partner for a gap on the site's own side. Those escalate to the
  weekly staff digest instead, until somebody invites an owner.

Hiding sets `post_status` to `draft` and marks `_gwcpp_auto_expired`. That
marker is what makes it reversible and attributable — confirming puts the entry
straight back, and a post staff drafted by hand has no marker and is never
touched.

### The ladder, and the ordering that matters

Each entry walks the rungs once per cycle, and the runner takes the **last**
rung passed, so an entry that arrives already deep into the ladder — which every
entry does on the day a site switches the cycle on — gets one email, not six.

Rungs are recorded as delivered **only once the message carrying them is away**.
Marking them before sending looks equivalent and is not: the two are separated
by every remaining entry in the batch, and a run that dies in between leaves
those rungs recorded as sent forever. The ladder never revisits a rung it
believes it has delivered, so somebody loses their final warning and has their
entry hidden having been told nothing. A duplicate reminder is a far cheaper
mistake than a silently skipped one.

### The rungs are sorted, and the walk is paged

Four rungs are counted forward from the basis in months and two backward from
expiry in days, so which lands first depends on the cadence. Written out in
reading order they interleave at short cadences — at a cadence of one the
staff 30-day rung falls a month and a half *before* the entry is even due — and
the runner takes the last rung in the array that has passed. A staff-only rung
sitting later in the list therefore won, was recorded as delivered, and never
came round again, so the owner's first and only warning was the one sent a
fortnight before their entry came off the site. `gwcpp_review_ladder()` now
sorts by date, and a tie goes to the owner's rung rather than the staff one.

`gwcpp_reviewable_post_ids()` pages through every tracked entry, oldest ID
first. It used to read the first 500 in WordPress's default newest-first order,
which meant that on a larger directory the entries it never returned were the
oldest ones — precisely the ones the cycle exists for.

### Review links are not transients

Sign-in links live in a transient for fifteen minutes, which is right. Review
reminders are sent by cron, sit in an inbox for weeks, and the recipient did not
ask for one — so `wp transient delete --all`, a routine deploy step and the
standard first move when debugging a cache, would silently invalidate every
reminder link in every inbox at once.

They live in user meta instead, carrying their own expiry, swept by the daily
run. There is an integration test that flushes every transient on the site and
then uses the link.

## Handover

A portal user can invite their replacement by email; accepting joins that person
to the organisation. Off by default per post type, because it is the one action
where a portal user can cause an account to be created.

Accepting is handled **before every signed-in branch** of the dispatcher —
whoever clicks is a different person from whoever sent it and usually has no
account at all. The token authenticates, so it is single use, hashed at rest,
and expires in three days.

A handover **never removes anybody**. Somebody mistyping an address, or being
talked into "confirming" one by a stranger, must not be able to lock their own
organisation out of its own entries. Removal stays a staff action in wp-admin
where a human can see who they are removing.

## Blocked words

An optional list. It catches mistakes, copy-paste and the occasional forgotten
test message; it does not stop a determined person, and nothing here should be
mistaken for content moderation. Empty by default — shipping a list would mean
deciding which words are unacceptable on every site that installs this, in a
language it does not know the site is written in.

Matched on word boundaries with a suffix group that allows a doubled final
consonant, so `scam` catches `scams`, `scamming` and `scammer` without catching
`scampi`.

**A field whose value has not changed is never screened.** That sounds like a
loophole and is the opposite: without it, adding a word to the list can make an
existing entry unsavable — its owner opens the form, changes a phone number, and
is told they cannot save because of a word in a field they never touched. The
original hit exactly this, with an organisation whose real name matched.

## Still to come

Nothing planned. The three phases in the original plan have all landed.
