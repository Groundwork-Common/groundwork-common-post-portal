# WordPress.org page assets

Nothing in this folder ships to anybody who installs the plugin. It is copied
to the SVN `assets/` directory by `.github/workflows/deploy.yml` and renders the
plugin's page on wordpress.org — which is why a large banner costs installers
nothing, and why `.distignore` excludes this folder from the release zip.

## What goes here

| File | Size | Notes |
| --- | --- | --- |
| `icon-256x256.png` | 256×256 | Shown in search results and the installer. |
| `icon-128x128.png` | 128×128 | Fallback for older screens. |
| `banner-1544x500.png` | 1544×500 | The retina banner at the top of the page. |
| `banner-772x250.png` | 772×250 | Its non-retina twin. Required — some contexts never load the large one. |
| `screenshot-1.png` … | any | Numbered from 1, in the order the captions appear in `readme.txt`. |

## Two things that are easy to get wrong

**The page does not exist until the first SVN commit.** Publishing assets before
the code means a live page listing screenshot captions with nothing behind them.
Land both together.

**Captions are matched to files by number alone.** `readme.txt` has a
`== Screenshots ==` list, and its first line describes `screenshot-1.png`
whatever that file happens to contain. Reordering the captions without renaming
the files silently mislabels every screenshot, and nothing anywhere warns you.

The captions currently expected, in order:

1. The portal as an end user sees it: their organisation's posts, and nothing else.
2. Editing a post from the front end, with only the fields you mapped.
3. The Fields screen, where you decide what a field is.
4. A pending change waiting for approval, shown as old versus new.

## Taking the screenshots

`tests/seed.php` builds a demo site covering every state the portal can be in —
all field types, three organisations, and entries that are up to date, due,
overdue, hidden by the review cycle, waiting for approval and mid-handover:

```bash
npx @wordpress/env run cli wp eval-file wp-content/plugins/groundwork-common-post-portal/tests/seed.php
```

It prints the URL for each scenario. See the "Seeing the emails" section of the
main README for the mail setup. Take the portal shots signed in as
`jane@shelter.test` in a private window at 1280px wide.
