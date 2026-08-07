#!/usr/bin/env bash
#
# Push this working tree to the beta site at https://wp.beta.poo6op.com.
#
#   bin/deploy-staging.sh              # deploy, then activate
#   bin/deploy-staging.sh --dry-run    # show what would change, send nothing
#   bin/deploy-staging.sh --no-activate
#
# Deploys whatever is checked out right now — branch, uncommitted edits and all.
# That is the point: the beta site is where a change is looked at before it is
# merged, so waiting for main would defeat it. Nothing here reads git state.
#
# ── What gets sent ───────────────────────────────────────────────────────────
# .distignore, the same file `wp dist-archive` reads to build a release zip.
# rsync's --exclude-from takes that syntax as-is, including the '#' comments. So
# what lands on the beta site is what a user would install, and there is no
# second exclusion list to fall out of step with the first.
#
# ── Why it checks the far end before writing ─────────────────────────────────
# groundworkcommon.com — a live nonprofit site — is in the same home directory
# on the same SSH login as the beta site. A wrong path here does not fail, it
# succeeds against production. So the script refuses to run unless it finds the
# beta-only mu-plugin at the destination, which no production site has. A typo
# in DEST_ROOT stops the run rather than redecorating a live site.
#
set -euo pipefail

# ── Where the beta site is, and why it is not written here ───────────────────
# An SSH user and host are not a credential — authentication is by key, and
# knowing the login grants nothing on its own. But the two together name a valid
# account on a specific, publicly reachable host, which is the half of a
# break-in that is usually the work. Committing that is free reconnaissance.
#
# These repositories are private today. "Private today" is a much weaker promise
# than "never committed": the decision can be reversed in one click, git history
# outlives it, and a plugin written to be published on wordpress.org is a
# plausible candidate for being opened up. Note also that .distignore keeps this
# file out of the release *zip* and does nothing whatever about the *repository*
# — two different exposures, and only one of them was covered.
#
# So the target lives in one file outside every repo, shared by all three
# plugins because it is the same beta site for all three:
#
#   ~/.config/groundwork-common/beta.env
#
#     SSH_HOST=user@host.example.com
#     DEST_ROOT=wp.beta.example.com
#     SITE_URL=https://wp.beta.example.com
#
# Set GWC_BETA_ENV to keep it somewhere else.
# ─────────────────────────────────────────────────────────────────────────────
readonly CONFIG="${GWC_BETA_ENV:-$HOME/.config/groundwork-common/beta.env}"

if [ ! -f "$CONFIG" ]; then
	cat >&2 <<-EOF
	No beta target configured — expected it at:

	  $CONFIG

	Create it:

	  mkdir -p "\$HOME/.config/groundwork-common"
	  cat > "$CONFIG" <<'CONF'
	SSH_HOST=user@host.dreamhost.com
	DEST_ROOT=wp.beta.example.com
	SITE_URL=https://wp.beta.example.com
	CONF

	It is deliberately outside the repository. See the comment in this script.
	EOF
	exit 1
fi

# shellcheck source=/dev/null
. "$CONFIG"

for required in SSH_HOST DEST_ROOT SITE_URL; do
	if [ -z "${!required:-}" ]; then
		echo "$CONFIG does not define $required." >&2
		exit 1
	fi
done

# Present on the beta site and nowhere else. See the block comment above.
readonly BETA_MARKER="${DEST_ROOT}/wp-content/mu-plugins/gwbeta-mail-trap.php"

DRY_RUN=0
ACTIVATE=1

for arg in "$@"; do
	case "$arg" in
		--dry-run)     DRY_RUN=1 ;;
		--no-activate) ACTIVATE=0 ;;
		-h|--help)     sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*)             echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

cd "$(git rev-parse --show-toplevel)"

# ── Work out the slug ────────────────────────────────────────────────────────
# From the plugin header, not from the directory name. In a git worktree the
# directory is named after the branch, so a directory-derived slug would install
# the plugin a second time under a name WordPress treats as a different plugin —
# two copies of the same hooks, and a deactivation that leaves one running.
SLUG=''
for candidate in *.php; do
	if grep -qE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "$candidate" 2>/dev/null; then
		SLUG="${candidate%.php}"
		break
	fi
done

if [ -z "$SLUG" ]; then
	echo "No file here has a Plugin Name: header. Is this a plugin repo?" >&2
	exit 1
fi

if [ ! -f .distignore ]; then
	echo "No .distignore. Refusing to guess what should not ship." >&2
	exit 1
fi

readonly DEST="${DEST_ROOT}/wp-content/plugins/${SLUG}"

echo "Plugin : ${SLUG}"
echo "Branch : $(git rev-parse --abbrev-ref HEAD) ($(git rev-parse --short HEAD))"
echo "Target : ${SSH_HOST}:~/${DEST}"

if ! git diff --quiet || ! git diff --cached --quiet; then
	echo "Tree   : uncommitted changes included"
fi

echo

# ── Confirm the far end is the beta site ─────────────────────────────────────
if ! ssh -o BatchMode=yes "$SSH_HOST" "test -f ~/${BETA_MARKER}"; then
	cat >&2 <<-EOF
	Refusing to deploy: ~/${BETA_MARKER} is not there.

	That file marks the beta site. Its absence means DEST_ROOT is pointing
	somewhere else — possibly at a production site on this same login.
	Nothing was written.
	EOF
	exit 1
fi

RSYNC_ARGS=(
	--archive
	--compress
	--delete
	--human-readable
	--itemize-changes
	--exclude-from=.distignore
	# Belt and braces: .distignore is the manifest, but a stray .git in a
	# subdirectory would carry history onto a shared host regardless.
	--exclude='.git/'
	--exclude='.DS_Store'
)

if [ "$DRY_RUN" -eq 1 ]; then
	RSYNC_ARGS+=( --dry-run )
	echo "── DRY RUN — nothing will be written ──"
fi

ssh -o BatchMode=yes "$SSH_HOST" "mkdir -p ~/${DEST}"

rsync "${RSYNC_ARGS[@]}" ./ "${SSH_HOST}:${DEST}/"

if [ "$DRY_RUN" -eq 1 ]; then
	echo
	echo "Dry run only. Nothing changed."
	exit 0
fi

if [ "$ACTIVATE" -eq 1 ]; then
	echo
	echo "Activating (WP-CLI on shared hosting is slow; give it a minute)…"
	# `wp plugin activate` on an already-active plugin is a no-op that still
	# exits 0, so this is safe to run on every deploy.
	ssh -o BatchMode=yes "$SSH_HOST" \
		"cd ~/${DEST_ROOT} && wp plugin activate ${SLUG}"
fi

echo
echo "Done — ${SITE_URL}/wp-admin/"
