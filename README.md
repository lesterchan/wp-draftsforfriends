# WP-DraftsForFriends
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: friends, preview, drafts, send, share draft  
Requires at least: 6.8  
Tested up to: 7.0  
Stable tag: 2.0.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Now you don't need to add friends as users to the blog in order to let them preview your drafts

## Description
This plugin generates a unique link you can send to a friend so they can read a post before you publish it. The link works for someone who is not logged in and has no account, it only ever opens the one post it was issued for, and it stops working by itself when the time you set runs out.

Everything happens under `Drafts for Friends` in the admin menu: pick an unpublished post, say how long the link should last, and copy the link it gives you. The list below shows every link you have out, how long each has left, and lets you extend or revoke them.

Sharing takes the `publish_posts` capability rather than `manage_options`: a plugin for sharing your own drafts has no business asking for the capability that lets somebody reconfigure the site.

Modified from Drafts for Friends, originally by Neville Longbottom. The plugin icon is by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com).

### Features
* A unique 32-character link per share, valid only for the post it was issued for
* An expiry you choose in seconds, minutes, hours or days, with a default you set once
* Works for a logged-out visitor with no account
* Scheduled and pending posts can be shared as well as drafts
* A sortable, paginated list of every link you have out, with the time each has left
* Extend or revoke links in bulk
* Comments are forced closed on a shared draft
* Moving a post to the trash revokes its links; restoring the post brings them back
* Multisite-safe, including network activation

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Usage
Go to `Drafts for Friends` in the admin menu.

Under **Share a Draft**, choose an unpublished post, set how long the link should last, and press **Share Draft**. The link appears in the list below; press **Copy link** to put it on your clipboard and send it to whoever needs it.

The list shows every link you have out. **Expires After** counts down and then reads `Expired`. Twenty rows are shown at a time, every column except the link is sortable, and *Screen Options* changes how many rows you see.

To extend links, set **Extend by** to the duration you want to add, tick the rows, choose **Extend selected** and press **Apply**. To revoke them, tick the rows and choose **Revoke selected**. Both are bulk actions rather than links on each row, and deliberately so: a link is a `GET`, and a browser or link checker that quietly prefetches one would have revoked every share on the page before you knew about it.

`Drafts for Friends -> Settings` sets the duration a new share starts on. It is only a starting value — both the share form and **Extend by** can be changed for one share without changing the setting. That screen takes `manage_options`, so an author sees the shared drafts screen but not the settings.

Anyone with the `edit_others_posts` capability — administrators and editors — sees every shared draft on the site and can share any unpublished post. Authors and contributors see only their own, and can only share posts they are allowed to edit.

### Filters
`wp_draftsforfriends_capability` decides who may reach each screen. The context is `shares` for the shared drafts screen or `settings` for the settings screen:

```php
add_filter( 'wp_draftsforfriends_capability', function ( $capability, $context ) {
	return 'settings' === $context ? 'manage_options' : 'edit_posts';
}, 10, 2 );
```

## Frequently Asked Questions

### Where did the Drafts for Friends page go?
It used to be a submenu of *Posts*. It is now its own top-level `Drafts for Friends` menu, because the plugin has two screens — the shared drafts list and its settings — and a plugin's settings should not live under somebody else's menu.

The address changed too, from `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to `admin.php?page=wp-draftsforfriends`, so an old bookmark needs replacing. The old address had the plugin's own folder name in it, which meant the page moved if you ever renamed the folder.

Links you have already given to friends are unaffected. Those point at the post itself and never went through the admin screen.

### Where have Extend and Delete gone from each row?
They are bulk actions now, above and below the list. Tick the rows you want, choose **Extend selected** or **Revoke selected**, and press **Apply**. Extend uses the **Extend by** duration next to the dropdown.

A row action is a plain link, and a plain link is one browser prefetch or one link checker away from being followed without anybody meaning to. Revoking cannot be undone, and extending silently prolongs public access to something you have not published, so neither should be reachable that way.

### A friend says the link shows "Page not found"
The link has expired, it has been revoked, or the post has been moved to the trash. Open *Drafts for Friends* and look at the **Expires After** column: an expired share reads `Expired`. Tick that row, set **Extend by**, and choose **Extend selected** — the same link starts working again. Restoring the post from the trash also makes its links work again.

Once the post is published the link stops previewing and simply shows the published post, which is public by then anyway.

### Who can see which shared drafts?
Anyone with the `edit_others_posts` capability — administrators and editors — sees every shared draft on the site and can share any unpublished post. Authors and contributors see only their own, and can only share posts they are allowed to edit.

### Can my friend leave a comment on the draft?
No. Comments are forced closed on a shared draft.

### Does the friend need an account?
No. That is the point of the plugin: the link works for a logged-out visitor, and only for the post it was issued for, and only until it expires.

### Does the screen need JavaScript?
No. Sharing, extending and revoking are ordinary form submissions handled on the server, so all three work with JavaScript turned off. The script only adds the **Copy link** button, a warning before you revoke, and catching a missing draft or a nonsense duration before the page reloads.

## Screenshots

1. WP-DraftsForFriends administration page
2. Extending the expiry of shared drafts
3. Previewing a shared draft

## Changelog

### 2.0.0
* BREAKING: Requires WordPress 6.8 and PHP 8.2, up from 6.0 and 7.4. A site below either will not be offered this update.
* BREAKING: The screen has moved from `Posts -> Drafts for Friends` at `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to its own top-level `Drafts for Friends` menu at `admin.php?page=wp-draftsforfriends`. Links already sent to friends are not affected.
* BREAKING: Extend and Delete are no longer links on each row. They are bulk actions named Extend selected and Revoke selected, applied to the rows you tick. A row action is a `GET` and one prefetch away from revoking every share on the page.
* BREAKING: Removed the `WPDraftsForFriends` class. The plugin is now `WP_DraftsForFriends` plus `WP_DraftsForFriends_Admin`, `_Install`, `_List_Table`, `_Options`, `_Preview`, `_Settings` and `_Shares` under `includes/`.
* BREAKING: Removed the `wp_ajax_draftsforfriends_admin` endpoint. Every write is now an ordinary nonced form post to the screen.
* BREAKING: Dropped the `dff_page`, `dff_sortby` and `dff_sortorder` query arguments in favour of core's `paged`, `orderby` and `order`. A bookmarked sorted URL no longer sorts.
* NEW: Added a settings screen at `Drafts for Friends -> Settings` for the default share duration, which was hardcoded to two hours.
* NEW: Settings are stored in a single `wp_draftsforfriends_options` row and the upgrade markers in `wp_draftsforfriends_version`. Both are removed on uninstall, on a single site and across a network, along with the pre-2.0.0 `draftsforfriends_db_version` row.
* NEW: Added the `wp_draftsforfriends_capability` filter, so either screen can be handed to another capability.
* NEW: Added a Copy link button to each row.
* NEW: Added a *Shared drafts per page* screen option.
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
* NOTE: Every database query is now a single prepared statement with every value bound, including the sort column, which goes through the `%i` identifier placeholder.

## Upgrade Notice

### 2.0.0
The first proper release in a decade, and it changes where the plugin lives and how two of its buttons work. Read this before you update.

**Your site needs WordPress 6.8 or later and PHP 8.2 or later.** Below either of those you will not be offered the update at all, and will stay on 1.0.2 indefinitely. Check `WP-Admin -> Tools -> Site Health -> Info -> Server` for your PHP version; if it is below 8.2, ask your host to move you up. PHP 8.1 and everything before it stopped receiving security fixes.

**Update for the security fixes even if nothing else here applies to you.** Three of them matter:

* A shared draft could show up where it was not shared. Once one valid preview link had been opened, that post was handed to every later query in the same request that legitimately returned nothing — including a request carrying a wrong, expired or deleted link. So somebody who had once held a link, or who guessed at one, could be served a different unpublished post.
* The post title was written into the admin screen unescaped, so a draft whose title contained markup could put a working script into the screen of anyone able to see that share.
* Extending and revoking checked your permissions against a post id sent along with the request rather than the one the share actually pointed at, so the two could be paired up to act on somebody else's share.

**The screen has moved, and its address has changed.** It used to be `Posts -> Drafts for Friends`; it is now its own top-level `Drafts for Friends` menu, with the shared drafts list first and Settings second. The address goes from `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to `admin.php?page=wp-draftsforfriends`, so replace any bookmark you have. The old address had the plugin's folder name inside it, which is why renaming the folder used to break the page.

**Links you have already sent to friends keep working.** They point at the post, not at the admin screen, and their form is unchanged: `?p=<id>&draftsforfriends=<hash>`. Nothing needs re-sending.

**Extend and Delete are no longer links on each row.** Tick the rows you want, choose **Extend selected** or **Revoke selected** from the dropdown above the list, and press **Apply**. Extend uses the **Extend by** duration sitting beside that dropdown. This is worth the extra click: a row action is a plain link, and a browser that prefetches links or a link checker crawling your admin area would have revoked every share on the page without anybody clicking anything.

**One behaviour change to be aware of.** Moving a shared post to the trash now revokes its links rather than leaving them working, which makes the obvious way of withdrawing a draft actually withdraw it. Restoring the post from the trash makes the same links work again. Deleting a post permanently now deletes its shares, which used to be left behind and counted towards the total shown above the list even though they were never listed.

**There is a settings screen now, and an author cannot reach it.** `Drafts for Friends -> Settings` sets the duration a new share starts on, and takes `manage_options`. Sharing itself still takes `publish_posts`, exactly as before, so nobody loses access to anything they could already do.

**Two smaller things.** The list shows twenty rows at a time rather than fifty, changeable under *Screen Options*, and the sort links no longer use `dff_sortby` and `dff_sortorder`, so a bookmarked sorted URL opens unsorted. The plugin now stores two rows in your database, `wp_draftsforfriends_options` and `wp_draftsforfriends_version`; deleting the plugin from the Plugins screen removes both, removes the older `draftsforfriends_db_version` row, and drops the plugin's table.

**For anyone who has written code against this plugin.** The `WPDraftsForFriends` class no longer exists — the plugin is `WP_DraftsForFriends` plus seven `WP_DraftsForFriends_*` classes under `includes/` — and the `wp_ajax_draftsforfriends_admin` endpoint has been removed, because every write is now an ordinary form post. There is one new filter, `wp_draftsforfriends_capability`, which decides who may reach each screen. No hook was renamed, because until now the plugin fired none of its own at all.
