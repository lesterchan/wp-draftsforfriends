# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What it is

Gives an unpublished post a time-limited public URL —
`?p=<id>&draftsforfriends=<hash>` — so an author can show a draft to somebody
who has no account. One page with two tabs under **Posts**: `Shared Drafts` (a
`WP_List_Table`) and `Settings`.

## Data

* **A custom table**, `{$wpdb->prefix}draftsforfriends`, one row per share.
* `wp_draftsforfriends_options` (default share duration) and
  `wp_draftsforfriends_version` (the `plugin` and `db` upgrade markers, kept out
  of the settings array because the settings form never posts one). The
  migration folds in `draftsforfriends_db_version`, which only the unreleased
  2.0.0 work wrote.
* `uninstall.php` **drops the table**, which changes how the uninstall test has
  to be written: `run_uninstall()` performs the deletions itself and a separate
  test asserts `uninstall.php` names the same rows and delegates the drop. Do
  not switch it to the `require_once` form — that would drop the table the rest
  of the suite runs against.

## The preview mechanism

WordPress runs the query for `?p=<id>`, hands the row to `posts_results`, then
empties it before `the_posts` because the status is not public and the visitor
is not logged in. `WP_DraftsForFriends_Preview` catches the post on the way past
and puts it back — but only when the URL carries a hash that currently unlocks
it.

* **`capture()` resets `$this->captured` to null on every query, and that line is
  a security fix.** Leaving the previous query's post in place handed an unlocked
  draft to every *later* query in the same request that legitimately returned
  nothing — including one carrying a wrong, expired or deleted link. Somebody who
  had once held a link could be served a different unpublished post.
* **`denied_statuses()` is `publish`, `trash`, `auto-draft` and nothing else.**
  Anything else unpublished is fair game, so a share survives a draft being
  scheduled or made private and custom statuses keep working. `trash` is on the
  list because trashing a shared draft used to leave the link working, which made
  the most obvious way to withdraw a draft do nothing.
* The `phpcs:ignore` for nonce verification is correct here: the hash in the URL
  *is* the credential, there is no form to carry a nonce, and nothing writes.

## Traps

* **Extend and Revoke are bulk actions, not row actions.** A row action is a
  plain GET link, and a browser prefetching links or a link checker crawling
  wp-admin would have revoked every share on the page with nobody clicking
  anything. Destructive operations are POST bulk actions here for that reason.
* **Verify the nonce `WP_List_Table` actually emits**, not one of the screen's
  own — `display_tablenav()` prints `_wpnonce` for `bulk-{$plural}` and a second
  field of that name replaces rather than adds (commit `1c615d6`).
* **`SORTABLE` is an allow-list and is still load-bearing under `%i`.**
  `prepare()`'s `%i` quotes an identifier but does not check it names a column
  this table has, so the list stands between a query argument and the SQL.
* **Extend and revoke check permissions against the post the *share* points at**,
  not a post id sent with the request. Pairing the two up was how a user acted on
  somebody else's share.
* **The page is gated on `publish_posts`, the Settings tab separately on
  `manage_options`, and both go through `wp_draftsforfriends_capability`.** That
  filter also answers `option_page_capability_wp_draftsforfriends_options`, so a
  filtered settings capability governs the *save* and not only the screen. An
  author reaching the page sees only the first tab — which is why the two tabs
  live on one page under Posts rather than on a menu of their own.
* **`WP_DraftsForFriends_Install::table()` builds the name from `$wpdb->prefix`,
  not `$wpdb->draftsforfriends`**, because `uninstall.php` reaches `drop_table()`
  with the plugin never having booted. `switch_to_blog()` moves the prefix, which
  is what keeps it correct per site on a network.
* **An absent duration cleans to the shipped two hours, not one second**, and
  the sanitiser reads nothing back out of the database to work that out. Pinned
  by `test_the_sanitiser_reads_nothing_back_out_of_the_database`.
* The `wp_ajax_draftsforfriends_admin` endpoint is gone — every write is an
  ordinary form post now.
* The old page slug embedded the plugin's folder name, so renaming the directory
  broke the screen.

## The upgrade, and why it is tested through a browser

There is remarkably little to fold in here, and that is the finding rather than
a gap: every released version stored no option rows at all, the shares have had
their own table since 1.0.0, and the duration the add form offered was
hardcoded. So what an upgrade has to get right is narrow and sharp, and
`tests/e2e/upgrade.spec.js` aims at exactly that:

* the settings row is *seeded* rather than left absent — read it **raw**,
  because the options accessor merges the defaults over whatever is stored and
  so answers identically for a seeded row and for no row at all;
* the one legacy row in the wild, `draftsforfriends_db_version`, is carried
  across and deleted, which is what stops a site that already has the table
  being sent through `dbDelta()` again;
* the table exists afterwards **and a draft can still be shared through the
  screen** — the only assertion that says the upgrade left a working plugin
  rather than a tidy set of rows.

Activation hooks do not fire on a plugin update, so `maybe_upgrade()` also hangs
off `admin_init`; that is the path every real upgrade takes.

## Tests

`bin/test.sh` runs PHPUnit, `bin/test-multisite.sh` the network pass, and
`bin/test-e2e.sh` the Playwright suite. **Run them rather than trusting a note
about their last result** — CI is the authority, and this file cannot be.

**The bootstrap logs real `Table 'wp_draftsforfriends' doesn't exist` errors
during `_delete_all_posts`.** `WP_DraftsForFriends_Shares::delete_for_post()` is
hooked to `deleted_post` and fires before the table exists. Known and harmless —
do not go hunting for it again.

`test-preview.php` covers the capture/restore logic and the denied statuses;
`test-multisite.php` the per-site table creation.

## Open question

Whether this plugin gains `wp_draftsforfriends_share_created` / `_extended` /
`_revoked` actions and a `_share_url` filter is deliberately unsettled. It has
never had public hooks, and new public API cannot be withdrawn once shipped. Do
not add them unilaterally.
