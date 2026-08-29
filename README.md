# WP-DraftsForFriends
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: friends, preview, drafts, send, share draft  
Requires at least: 6.8  
Tested up to: 7.1  
Stable tag: 2.0.2  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Now you don't need to add friends as users to the blog in order to let them preview your drafts

## Description
This plugin generates a unique link you can send to a friend so they can read a post before you publish it. The link works for someone who is not logged in and has no account, it only ever opens the one post it was issued for, and it stops working by itself when the time you set runs out.

Everything happens under `Posts -> WP-DraftsForFriends`: pick an unpublished post, say how long the link should last, and copy the link it gives you. The list below shows every link you have out, how long each has left, and lets you extend or revoke them. The settings are the second tab of that same page.

Sharing takes the `publish_posts` capability rather than `manage_options`: a plugin for sharing your own drafts has no business asking for the capability that lets somebody reconfigure the site.

Modified from Drafts for Friends, originally by Neville Longbottom. The plugin icon is by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com).

### Features
* A unique 32-character link per share, valid only for the post it was issued for
* An expiry you choose in seconds, minutes, hours or days, with a default you set once
* Works for a logged-out visitor with no account
* Scheduled and pending posts can be shared as well as drafts
* A box in the post editor that shows the post's links and creates another with one button
* A sortable, paginated list of every link you have out, with the time each has left
* Extend or revoke links in bulk
* Comments are forced closed on a shared draft
* Moving a post to the trash revokes its links; restoring the post brings them back
* Multisite-safe, including network activation

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Installation

1. Install and activate the plugin.
1. Go to `WP-Admin -> Posts -> WP-DraftsForFriends` and share your first draft.

There is nothing to configure before you start. Sharing takes the `publish_posts` capability rather than `manage_options`, so any author can share their own drafts without being made an administrator.

## Usage
Go to `Posts -> WP-DraftsForFriends`. The page has two tabs, **Shared Drafts** and **Settings**.

Under **Share a Draft**, choose an unpublished post, set how long the link should last, and press **Share Draft**. The link appears in the list below. Hovering a row shows **Edit Draft**, which opens the post, and **Copy Link**, which puts the link on your clipboard ready to send to whoever needs it.

You can also share from inside the post editor. Every unpublished post has a **Drafts for Friends** box listing its links and the time each has left, each with a **Copy Link** button; tick **Create a share link when this post is saved** and the next save creates one. A published post needs no link — anybody can read it — so the box says only that.

The list shows every link you have out. **Expires After** counts down and then reads `Expired`. Twenty rows are shown at a time, every column except the link is sortable, and *Screen Options* changes how many rows you see.

To extend links, set **Extend by** to the duration you want to add, tick the rows, choose **Extend Selected** and press **Apply**. To revoke them, tick the rows and choose **Revoke Selected**. Both are bulk actions rather than links on each row, and deliberately so: a link is a `GET`, and a browser or link checker that quietly prefetches one would have revoked every share on the page before you knew about it.

The **Settings** tab sets the duration a new share starts on. It is only a starting value — both the share form and **Extend by** can be changed for one share without changing the setting. That tab takes `manage_options`, so an author sees the Shared Drafts tab and not the Settings one.

Anyone with the `edit_others_posts` capability — administrators and editors — sees every shared draft on the site and can share any unpublished post. Authors and contributors see only their own, and can only share posts they are allowed to edit.

### WP-CLI
```
wp draftsforfriends list --user=admin
wp draftsforfriends create 42 --user=admin
wp draftsforfriends create 42 --expires=14 --measure=d --user=admin
wp draftsforfriends extend 3 4 5 --expires=1 --measure=d --user=admin
wp draftsforfriends revoke 3 --yes --user=admin
```

`create` prints the share link on a line of its own before its success message, and `list` prints one per row. **A share link is the credential** — whoever holds it reads the unpublished post until the link expires, with no account and no login — so treat the output of both as you would the drafts themselves. Shell history, a CI log and a captured `stdout` are all places those links now live.

**Pass `--user`.** WP-CLI runs as nobody unless told otherwise, and every one of these is scoped exactly as the screen is: a share belongs to whoever created it, anyone with `edit_others_posts` sees them all, and creating, extending or revoking one checks that you may edit the post it points at. Run as nobody, `list` reports nothing and the rest are refused.

`--expires` and `--measure` default to the duration on the **Settings** tab, the same value the share form and **Extend by** start on. `extend` and `revoke` take as many ids as you like, exactly as the bulk actions do. `revoke` asks before it acts, because the link stops working immediately and cannot be restored; `--yes` answers for a script.

There is no subcommand for the settings — that is one option row, which `wp option get wp_draftsforfriends_options` already reads.

### Filters
`wp_draftsforfriends_capability` decides who may reach each tab. The context is `shares` for the Shared Drafts tab or `settings` for the Settings tab:

```php
add_filter( 'wp_draftsforfriends_capability', function ( $capability, $context ) {
	return 'settings' === $context ? 'manage_options' : 'edit_posts';
}, 10, 2 );
```

`wp_draftsforfriends_share_url` filters the link a friend is given, and
`wp_draftsforfriends_requested_hash` reads the hash back off the request. **They
are one contract.** Change the shape of the link without teaching the plugin to
recognise it and every share link 404s, with nothing on the admin screens
looking wrong:

```php
add_filter( 'wp_draftsforfriends_share_url', function ( $url, $share ) {
	return home_url( '/secret/' . $share->hash . '/' );
}, 10, 2 );

add_filter( 'wp_draftsforfriends_requested_hash', function ( $hash ) {
	if ( '' !== $hash ) {
		return $hash;
	}

	$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	return preg_match( '#/secret/([A-Za-z0-9]+)/#', $path, $m ) ? $m[1] : '';
} );
```

The default link is `?p=<id>` rather than the post's permalink, and that is not
an oversight: the preview works by catching the row WordPress fetches for a bare
post id and puts back before rendering. A permalink looks the post up by slug
among the *public* statuses, so an unpublished post is never found at all.

### Actions
Three fire as a share moves through its life, each after the write has
succeeded:

* `wp_draftsforfriends_share_created` — the stored share, and the post it shares.
* `wp_draftsforfriends_share_extended` — the share as it now stands, and the
  expiry it carried before.
* `wp_draftsforfriends_share_revoked` — the share as it was; the link has
  already stopped working.

```php
add_action( 'wp_draftsforfriends_share_created', function ( $share, $post ) {
	error_log( sprintf( 'Shared "%s" until %s', $post->post_title, $share->date_expired ) );
}, 10, 2 );
```

## Frequently Asked Questions

### Where did the Drafts for Friends page go?
It is still under *Posts*, and it is now one page with two tabs — **Shared Drafts** and **Settings** — rather than a screen with no settings of its own.

The address changed, from `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to `edit.php?page=wp-draftsforfriends`, so an old bookmark needs replacing. The old address had the plugin's own folder name in it, which meant the page moved if you ever renamed the folder.

Links you have already given to friends are unaffected. Those point at the post itself and never went through the admin screen.

### Where have Extend and Delete gone from each row?
They are bulk actions now, above and below the list. Tick the rows you want, choose **Extend Selected** or **Revoke Selected**, and press **Apply**. Extend uses the **Extend by** duration next to the dropdown.

A row action is a plain link, and a plain link is one browser prefetch or one link checker away from being followed without anybody meaning to. Revoking cannot be undone, and extending silently prolongs public access to something you have not published, so neither should be reachable that way.

### A friend says the link shows "Page not found"
The link has expired, it has been revoked, or the post has been moved to the trash. Open *Posts -> WP-DraftsForFriends* and look at the **Expires After** column: an expired share reads `Expired`. Tick that row, set **Extend by**, and choose **Extend Selected** — the same link starts working again. Restoring the post from the trash also makes its links work again.

Once the post is published the link stops previewing and simply shows the published post, which is public by then anyway.

### Who can see which shared drafts?
Anyone with the `edit_others_posts` capability — administrators and editors — sees every shared draft on the site and can share any unpublished post. Authors and contributors see only their own, and can only share posts they are allowed to edit.

### Can my friend leave a comment on the draft?
No. Comments are forced closed on a shared draft.

### Does the friend need an account?
No. That is the point of the plugin: the link works for a logged-out visitor, and only for the post it was issued for, and only until it expires.

### Does the screen need JavaScript?
No. Sharing, extending and revoking are ordinary form submissions handled on the server, so all three work with JavaScript turned off. The script only adds the copy button, a warning before you revoke, and catching a missing draft or a nonsense duration before the page reloads.

The post editor's box is the same: with JavaScript off it shows a checkbox that creates the link when you next save the post, in place of the **Create Share Link** button.

## Screenshots

1. Posts -> WP-DraftsForFriends: the share form, and every share with its countdown
2. The Settings tab, which sets how long a new share lasts unless you say otherwise
3. What the friend sees: an unpublished post, with no account and no login

## Changelog

### 2.0.2
* NEW: The post editor's box creates a share link when you press **Create Share Link**, without saving the post and without reloading the page. The new link, its countdown and its Copy Link button appear in the box straight away, and pressing the button again gives you another link, so each friend can have one of their own.
* CHANGED: The create-on-save checkbox is now shown only where it is the control that works: on a post that has never been saved, where WordPress has not yet given the post a status a share link may serve, and with JavaScript turned off. Everywhere else it is the button. A checkbox that defers an action until some later save reads as broken on a post that was saved long ago.
* CHANGED: The box is split under two headings, **Share Links** over the links the post already has and **New Share Link** over the controls that make another, with the duration's label on its own line so the amount and the unit fit the side column instead of wrapping.
* CHANGED: The box reports what happened inside itself rather than through an admin notice at the top of the screen. The block editor draws meta boxes on a screen that has no notice area, so a message raised there was never seen.

### 2.0.1
* NEW: A Drafts for Friends box in the post editor: it lists the post's share links with the time each has left and a Copy Link button for each, and creates a new link when the post is saved with its checkbox ticked. Posts only, because a share link asks for the post as `?p=<id>` and WordPress answers that for posts alone.
* NEW: An Edit Draft row action on each row of the list, which opens the post the share is for.
* CHANGED: Copy Link is a row action on the Post column rather than a button appended to the URL, where it landed wherever the link happened to stop wrapping. It is still a button rather than a link, so nothing copies by prefetch.
* CHANGED: The bulk actions read Extend Selected and Revoke Selected, which is the case WordPress uses for a control you act on. They do the same thing they did.
* CHANGED: The upgrade check runs on `init` rather than `admin_init`, so an update applied by cron or WP-CLI is migrated on the site's next request rather than waiting for somebody to open wp-admin.

### 2.0.0
* BREAKING: Requires WordPress 6.8 and PHP 8.2.
* BREAKING: The screen has moved from `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to `edit.php?page=wp-draftsforfriends`, still under `Posts -> WP-DraftsForFriends`, and is now one page with two tabs, `Shared Drafts` and `Settings`. Links already sent to friends are not affected.
* BREAKING: Extend and Delete are no longer links on each row. They are bulk actions named Extend selected and Revoke selected, applied to the rows you tick. A row action is a `GET` and one prefetch away from revoking every share on the page.
* BREAKING: Removed the `WPDraftsForFriends` class. The plugin is now `WP_DraftsForFriends` plus `WP_DraftsForFriends_Admin`, `_Install`, `_List_Table`, `_Options`, `_Preview`, `_Settings` and `_Shares` under `includes/`.
* BREAKING: Removed the `wp_ajax_draftsforfriends_admin` endpoint. Every write is now an ordinary nonced form post to the screen.
* BREAKING: Dropped the `dff_page`, `dff_sortby` and `dff_sortorder` query arguments in favour of core's `paged`, `orderby` and `order`. A bookmarked sorted URL no longer sorts.
* NEW: Added a `Settings` tab for the default share duration, which was hardcoded to two hours.
* NEW: Settings are stored in a single `wp_draftsforfriends_options` row and the upgrade markers in `wp_draftsforfriends_version`. Both are removed on uninstall, on a single site and across a network, along with the pre-2.0.0 `draftsforfriends_db_version` row.
* NEW: Added the `wp_draftsforfriends_capability` filter, so either tab can be handed to another capability. It is answered by `option_page_capability_wp_draftsforfriends_options` too, so a filtered settings capability governs the save as well as the screen.
* NEW: Added the `wp_draftsforfriends_share_created`, `wp_draftsforfriends_share_extended` and `wp_draftsforfriends_share_revoked` actions, each firing after the write has succeeded.
* NEW: Added the `wp_draftsforfriends_share_url` filter and its companion `wp_draftsforfriends_requested_hash`. The link a friend is given and the check that lets them read it now go through one contract, so a site can move share links to a shape of its own. Filtering only the first leaves every link 404ing.
* NEW: Added a Copy link button to each row.
* NEW: Added a *Shared drafts per page* screen option.
* NEW: A `wp draftsforfriends` WP-CLI command — `list`, `create`, `extend` and `revoke`, the four things the screen does. It prints share links, which are credentials, and needs `--user` because everything it does is scoped to who is asking.
* NEW: Added a PHPUnit test suite, vitest coverage for the script, and GitHub Actions CI.
* CHANGED: The shared drafts list is a standard WordPress list table, with sortable columns, standard pagination at twenty rows and bulk actions, replacing roughly 250 lines of hand-rolled pagination links and column headers.
* CHANGED: Messages are core admin notices raised through `add_settings_error()` rather than a hand-built banner the script unhid.
* CHANGED: The plugin now works when installed under a directory name other than `wp-draftsforfriends`.
* CHANGED: Dropped the jQuery dependency; the script is plain JavaScript, and nothing on the screen depends on it running.
* CHANGED: Moving a shared post to the trash now revokes its links instead of leaving them working. Restoring the post from the trash makes them work again.
* CHANGED: Restructured the plugin into `includes/` with one class per file.
* CHANGED: Removed `img/receipt_share.png`, which was only ever used by the `icon32` admin markup WordPress dropped in 3.8.
* FIXED: Fixed a bug where a shared draft could appear in places it was not shared to. Once one valid preview link had been opened, the post was re-used for every later query in that same request that returned nothing of its own, including requests carrying a wrong, expired or deleted link.
* FIXED: Fixed the post title being output unescaped on the admin screen.
* FIXED: Fixed extending and deleting checking permissions against a post supplied with the request rather than the one the shared draft actually points at.
* FIXED: Fixed activation and uninstall fatally erroring on multisite, which called a function removed in WordPress 5.1.
* FIXED: Fixed multisite activation and uninstall skipping every site past the hundredth.
* FIXED: Fixed the shared drafts table not being registered with `$wpdb->tables`, so its name was wrong inside `switch_to_blog()`.
* FIXED: Fixed the duration unit dropdowns having no label, which left screen readers announcing them as unlabelled combo boxes.
* FIXED: Fixed the item count above the list counting shares whose post had been deleted and which were never listed. Deleting a post permanently now deletes its shared drafts.
* FIXED: Fixed eleven strings never being translated because of a typo in the text domain.
* FIXED: Fixed several PHP 8 warnings.
* FIXED: Fixed the *Shared drafts per page* screen option being thrown away when applied, so the list always paged at twenty whatever you set it to.
* NOTE: Every database query is now a single prepared statement with every value bound, including the sort column, which goes through the `%i` identifier placeholder.

## Upgrade Notice

### 2.0.0

Requires WordPress 6.8 and PHP 8.2.

**Three security fixes, which are reason enough on their own:**

* A shared draft could be served where it had not been shared. Once one valid preview link had been opened, that post was handed to every later query in the same request that legitimately returned nothing — including one carrying a wrong, expired or deleted link. Somebody who had once held a link, or who guessed at one, could be served a different unpublished post.
* The post title was written into the admin screen unescaped, so a draft whose title contained markup could put a working script into the screen of anyone able to see that share.
* Extending and revoking checked permissions against a post id sent with the request rather than the one the share pointed at, so the two could be paired up to act on somebody else's share.

**The screen stays under Posts and is now one page with two tabs**, `Shared Drafts` and `Settings`, at `edit.php?page=wp-draftsforfriends` rather than `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php`. The sidebar entry reads `WP-DraftsForFriends`. The old address embedded the plugin's folder name, which is why renaming the folder used to break the page. The plugin does one thing — share a post with somebody — and its list is a list of shared posts; sharing is gated on `publish_posts`, an editorial capability rather than a site-configuration one, and WordPress keeps post-scoped tools under Posts. A top-level menu would have claimed a sidebar slot next to Posts, Media and Pages for something that only makes sense beside your posts.

**Links already sent keep working.** They point at the post rather than the admin screen, and their form is unchanged: `?p=<id>&draftsforfriends=<hash>`.

**Extend and Delete are bulk actions, not row links.** Tick the rows, choose **Extend Selected** or **Revoke Selected**, and press **Apply**; Extend uses the **Extend by** duration beside the dropdown. A row action is a plain link, and a browser that prefetches links or a link checker crawling wp-admin would have revoked every share on the page with nobody clicking anything.

**Trashing a shared post now revokes its links**, and restoring it makes them work again. Deleting a post permanently deletes its shares, which used to be left behind and counted towards the total above the list without ever being listed.

**There are settings**, on the `Settings` tab, which requires `manage_options` and sets the duration a new share starts on. Sharing itself still requires `publish_posts`, and that is what the page as a whole requires — so an author reaches the page and sees only the `Shared Drafts` tab. The Settings tab is checked separately, both to open it and to save it.

**Smaller changes.** The list shows twenty rows rather than fifty, changeable under *Screen Options*. The sort links no longer use `dff_sortby` and `dff_sortorder`, so a bookmarked sorted URL opens unsorted. The plugin stores `wp_draftsforfriends_options` and `wp_draftsforfriends_version`; deleting it from the Plugins screen removes both, removes the older `draftsforfriends_db_version` row, and drops the plugin's table.

**For code written against the plugin.** `WPDraftsForFriends` no longer exists — it is `WP_DraftsForFriends` plus seven `WP_DraftsForFriends_*` classes under `includes/`. The `wp_ajax_draftsforfriends_admin` endpoint is removed, because every write is now an ordinary form post. There is one new filter, `wp_draftsforfriends_capability`, deciding who may reach each tab; it also answers `option_page_capability_wp_draftsforfriends_options`, so a filtered settings capability governs the save and not only the screen. No hook was renamed, because the plugin previously fired none of its own.
