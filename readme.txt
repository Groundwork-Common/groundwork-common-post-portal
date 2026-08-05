=== Groundwork Common Post Portal ===
Contributors: groundworkcommon
Donate link: https://www.groundworkcommon.com/support/
Tags: front-end editing, portal, passwordless, custom post types, moderation
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let the people who own your content edit it from the front end, without ever handing them a wp-admin login.

== Description ==

Some of the content on your site is not really yours. A directory of partner
organisations, a list of member clinics, a network of dealers, a roster of
volunteers — somebody else knows when their phone number changes, and you find
out months later.

The usual fixes are both bad. Giving them a wp-admin account means training
them on a screen built for you, and hoping they never wander into Appearance.
Emailing you their updates means you are their content management system.

This plugin gives those people one page. They sign in with a link sent to their
email, they see only what belongs to them, and they edit only the fields you
decided they may touch. They never see wp-admin, and there is no password to
lose.

**You decide what a field is.** Nothing about the data model is built in. Pick
which post types the portal covers, then map the fields on a screen in
wp-admin: a label, where the value is stored, and what type of control it is.
A library, a food pantry, and a dealer network can all install this without a
fork.

**Nothing goes live until you say so.** Submissions are held as a pending
change while the published post carries on showing the old values. You see an
old-to-new comparison in wp-admin and approve or reject it. A partner cannot
take their own listing down mid-review, and a typo is never public.

**Access is a question with one answer.** A person reaches a post through the
organisation they belong to, through a direct grant on that post, or — if you
turn it on — by being its author. Every check in the plugin routes through a
single function, so there is no second path to keep in step with the first.

= Signing in =

The default is passwordless. Somebody types their email, gets a link, and is
signed in for a few hours. Links expire in fifteen minutes, work once, and are
rate limited by address, by email, and site-wide. A request for an unknown
address takes the same amount of time and says the same thing as a request for
a known one, so the form cannot be used to find out who has an account.

Ordinary username-and-password sign-in can be enabled alongside it for people
who would rather have one.

== External services ==

This plugin contacts no external service. It sends email through WordPress —
that is, through whatever your site already uses — and makes no outbound HTTP
requests of its own.

== About Groundwork Common ==

Groundwork Common builds software for organisations doing public-interest work,
and releases the generally useful parts of it. If this plugin is useful to you,
[supporting the work](https://www.groundworkcommon.com/support/) keeps it
maintained.

== Installation ==

1. Install and activate the plugin.
2. Create a page for the portal and add the **Post Portal** block to it.
3. Go to **Portal → Settings**, choose which post types the portal covers, and
   select the page you just made.
4. Open the **Fields** tab on that same screen, pick a post type, and map the
   fields end users may edit. If your fields are already registered with
   `register_meta()`, the **Import fields** button will find them.
5. Create an organisation under **Portal → Organisations** and invite somebody
   by email.

== Frequently Asked Questions ==

= Can portal users delete posts? =

No, and this is not configurable. The strongest thing a portal user can do is
unpublish a post back to draft, which you can reverse in one click. Deleting
stays a staff action in wp-admin.

= What happens if somebody's magic link expires? =

They request another one. Links are deliberately short-lived; requesting a new
one costs nothing and is the expected path, not an error.

= Can two people edit the same post? =

Yes. Add them both to the organisation that owns it, or grant them each access
on the post directly.

= What can a portal user write in a formatted text field? =

Paragraphs, bold, italic, lists, links and small headings. Everything else —
embeds, scripts, styling, anything that could imitate your site's own chrome —
is removed when they save, regardless of what role the account holds.

= What files can they upload? =

Images and PDFs, up to a size you set. The file's actual contents are checked,
not just its name, so a script renamed to .jpg is refused.

= Will this email my partners without me setting it up? =

No. The review cycle is off for every post type until you set a number of
months, and until you do, nothing is ever sent to a portal user except the
sign-in link they asked for.

= What happens when nobody confirms their details? =

They are reminded several times, and staff are warned 30 days before anything
happens. If it is still not confirmed, the entry stops being shown — it is not
deleted, its owner can still sign in, and confirming puts it straight back.

An entry nobody has been given access to is never hidden, because there is
nobody who could have prevented it. Those are listed in a weekly email instead.

= Does this work with ACF? =

Field values stored as ordinary post meta work, and the Fields screen can
import ACF field definitions to save you retyping them. Complex ACF types are
mapped to the closest built-in control rather than reproduced exactly.

= Will this let a portal user edit a post you did not intend? =

Only if you grant it. Post types are opt-in, and within an enabled post type a
user reaches only the posts their organisation owns or that they were granted
directly. The "author may edit their own" path is off by default.

== Screenshots ==

1. The portal as an end user sees it: their organisation's posts, and nothing else.
2. Editing a post from the front end, with only the fields you mapped.
3. The Fields tab, where you decide what a field is.
4. A pending change waiting for approval, shown as old versus new.

== Changelog ==

= 0.3.0 =
* Entries can be put on a review cycle: owners are reminded to confirm their
  details, and an entry nobody ever confirms stops being shown. Nothing is
  deleted, and confirming puts it straight back.
* Portal users can hand over to a replacement by email, without needing staff to
  do it for them. Nobody is ever removed by a handover.
* An optional list of words that a submission may not contain. Only fields
  somebody actually changed are checked.
* Review state is shown as a column and a filter on the post list.
* Translation template added.

= 0.2.0 =
* Changes can now be held for staff approval. The published entry keeps showing
  what it showed before until somebody approves, and staff see an old-against-new
  comparison in wp-admin.
* Staff are emailed a diff of every submission; the person who submitted it is
  told when it is approved or rejected, with the reason.
* New field types: formatted text, image or file upload, repeating rows, and
  categories or tags bound to a real taxonomy.
* The post's main text can now be mapped as a field.

= 0.1.0 =
* First release. Post type selection, the Fields screen and its field type
  registry, organisations and direct grants, passwordless sign-in with optional
  password sign-in, the front-end list and edit views, and wp-admin lockout for
  portal users.

== Upgrade Notice ==

= 0.3.0 =
Adds the review cycle, handover, and blocked-word screening. The review cycle is
off until you set a cadence per post type.

= 0.2.0 =
Adds the approval queue and four new field types, including file uploads.

= 0.1.0 =
First release.
