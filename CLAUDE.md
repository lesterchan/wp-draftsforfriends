# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WP-DraftsForFriends follows `_standards/STANDARDS.md` in the parent folder,
which is the contract for all nineteen plugins in the collection. Where this
file and that one disagree, that one wins.

## What it is

Gives an unpublished post a time-limited public URL —
`?p=<id>&draftsforfriends=<hash>` — so an author can show a draft to somebody
who has no account. One page with two tabs under **Posts**: `Shared Drafts` (a
`WP_List_Table`) and `Settings`.

## Data

* **A custom table**, `{$wpdb->prefix}draftsforfriends`, one row per share.
* `wp_draftsforfriends_options` (default share duration) and
  `wp_draftsforfriends_version`. The migration folds in
  `draftsforfriends_db_version`, which only the unreleased 2.0.0 work wrote.
* `uninstall.php` **drops the table**. Only this plugin and
  wp-downloadmanager have schema-touching uninstallers, which changes how the
  uninstall test has to be written (§7.2.1): `run_uninstall()` performs the
  deletions itself and a separate test asserts `uninstall.php` names the same
  rows and delegates the drop. Do not switch it to the `require_once` form —
  that would drop the table the rest of the suite runs against.

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
  anything. §4.3 states the general rule; this is one of the cases it was written
  for.
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
  author reaching the page sees only the first tab. §4.2.1 uses this plugin as
  the example that a tabbed page need not hang off a menu of its own.
* **`WP_DraftsForFriends_Install::table()` builds the name from `$wpdb->prefix`,
  not `$wpdb->draftsforfriends`**, because `uninstall.php` reaches `drop_table()`
  with the plugin never having booted. `switch_to_blog()` moves the prefix, which
  is what keeps it correct per site on a network.
* **An absent duration cleans to the shipped two hours, not one second.** That
  was one of the five bugs the first PHPUnit sweep found; pinned by
  `test_the_sanitiser_reads_nothing_back_out_of_the_database`.
* The `wp_ajax_draftsforfriends_admin` endpoint is gone — every write is an
  ordinary form post now.
* The old page slug embedded the plugin's folder name, so renaming the directory
  broke the screen.

## Tests

**The bootstrap logs real `Table 'wp_draftsforfriends' doesn't exist` errors
during `_delete_all_posts`.** `WP_DraftsForFriends_Shares::delete_for_post()` is
hooked to `deleted_post` and fires before the table exists. Known, harmless, and
recorded in `_standards/RESUME.md` — do not go hunting for it again.

`test-preview.php` covers the capture/restore logic and the denied statuses;
`test-multisite.php` the per-site table creation. `tests/e2e/` is 5 specs and 50
tests. `upgrade.spec.js` (8) is green as of 2026-08-05; **the other four were
not re-run that day**, so verify before trusting them.

## Open question

`_standards/RESUME.md` leaves one API decision deliberately unmade: whether this
plugin gains `wp_draftsforfriends_share_created` / `_extended` / `_revoked`
actions and a `_share_url` filter. It has never had public hooks, and new public
API cannot be withdrawn once shipped. Do not add them unilaterally.
