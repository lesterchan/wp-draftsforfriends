# WP-DraftsForFriends
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: friends, preview, drafts, send, share draft  
Requires at least: 6.0  
Tested up to: 7.0  
Stable tag: 2.0.0  
Requires PHP: 7.4  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Now you don't need to add friends as users to the blog in order to let them preview your drafts

## Description
This plugin will generate a unique link that you can send to your friends to allow them to preview your draft before they are published. You are able to set the expiry for the link as well.

Modified from Drafts for Friends originally by Neville Longbottom.

### Development
* [https://github.com/lesterchan/wp-draftsforfriends](https://github.com/lesterchan/wp-draftsforfriends "https://github.com/lesterchan/wp-draftsforfriends")

### Credits
* Plugin icon by [Freepik](https://www.freepik.com) from [Flaticon](https://www.flaticon.com)

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Changelog

### 2.0.0
* **IMPORTANT:** The admin screen has moved from `edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php` to `admin.php?page=draftsforfriends`. Update any bookmark you have; the menu entry under *Posts* is unchanged. Links you have already sent to friends are **not** affected.
* **IMPORTANT:** Requires WordPress 6.0 and PHP 7.4 or later.
* **IMPORTANT:** The screen now requires JavaScript. The parallel non-JavaScript form handling has been removed.
* Fixed a bug where a shared draft could appear in places it was not shared to. Once one valid preview link had been opened, the post was re-used for every later query in that same request that returned nothing of its own, including requests carrying a wrong, expired or deleted link
* Fixed the post title being output unescaped on the admin screen
* Fixed activation and uninstall fatally erroring on Multisite, which called a function removed in WordPress 5.1
* Fixed Multisite activation and uninstall skipping every site past the hundredth
* Fixed extending and deleting checking permissions against a post supplied with the request rather than the one the shared draft actually points at
* Fixed eleven strings never being translated because of a typo in the text domain
* Fixed several PHP 8 warnings
* The shared drafts list is now a standard WordPress list table, with sortable columns, standard pagination and a *Shared drafts per page* screen option
* The plugin now works when installed under a directory name other than `wp-draftsforfriends`
* Dropped the jQuery dependency; the admin script is now plain JavaScript
* Restructured the plugin into `includes/` with one class per file
* Moving a shared post to the trash now revokes its links, instead of leaving them working. Restoring the post from the trash makes them work again
* Deleting a post permanently now deletes its shared drafts, which were previously left behind and counted towards the total shown above the list even though they were never listed
* Removed `img/receipt_share.png`, which was only ever used by the `icon32` admin markup WordPress dropped in 3.8

### 1.0.2
* It now supports Multisite Network activation

### 1.0.1
* Extend shared drafts is now works

### 1.0.0
* Uses it's own table "wp_draftsforfriends" instead of relying on the "shared" field in wp_options
* New "Date Created", "Date Extended" and "Expires After" column
* Pagination of shared drafts is now supported
* Sorting of shared drafts is now supported
* If you have "edit_others_posts" capabilities (Super Admin, Admin & Editor), you are able able to see and share all draft posts
* Author on the other hand will be able to see and share his/her own draft posts
* When your friend view the draft post, the comment's status is now closed
* Link hash now check for expiry as well
* Link hash is no longer 8 characters with special characters, it is now 32 characters with no special characters
* Added nonce security check
* Added a 32x32 icon to the plugin
* Moved JavaScript and CSS files out of the plugin code into it's own file and hence there is a new "js" and "css" folder
* Adding, deleting and extending of shared draft is now AJAXify, it is still backward compatible with browsers that does not support JavaScript
* phpDoc comments are added to the code
* Fix PHP notices

### 0.0.1
* Initial release

## Installation

1. Upload `wp-draftsforfriends` folder to the `/wp-content/plugins/` directory
2. Activate the `WP-DraftsForFriends` plugin through the 'Plugins' menu in WordPress
3. You can access `WP-DraftsForFriends` via `WP-Admin -> Posts -> Drafts for Friends`

## Screenshots

1. WP-DraftsForFriends Administrator Page
2. Extending Expiry of Shared Draft
3. Previewing shared draft

## Frequently Asked Questions

### My bookmark to the Drafts for Friends page stopped working
The screen moved to `admin.php?page=draftsforfriends` in 2.0.0. It used to be addressed by the plugin's own file name, which meant the page URL changed if you ever renamed the plugin folder. Reach it from *Posts -> Drafts for Friends* and re-bookmark it.

Links you have already given to friends are unaffected. Those point at the post itself and do not go through the admin screen.

### A friend says the link shows "Page not found"
The link has expired, it has been deleted, or the post has been moved to the trash. Open *Posts -> Drafts for Friends* and check the *Expires After* column: an expired share reads `Expired`. Use *Extend* on that row to give it more time, and the same link starts working again. Restoring the post from the trash also makes its links work again.

Once the post is published the link stops previewing and simply shows the published post, which is public by then anyway.

### Who can see which shared drafts?
Anyone with the `edit_others_posts` capability — administrators and editors — sees every shared draft on the site and can share any unpublished post. Authors and contributors see only their own, and can only share posts they are allowed to edit.

### Can my friend leave a comment on the draft?
No. Comments are forced closed on a shared draft.

### Does the friend need an account?
No. That is the point of the plugin: the link works for a logged-out visitor, and only for the post it was issued for, and only until it expires.

## Upgrade Notice

### 2.0.0
Fixes a bug where a shared draft could show up in places it was not shared to. The admin screen has moved to `admin.php?page=draftsforfriends` and now requires JavaScript, WordPress 6.0 and PHP 7.4. Links already sent to friends keep working.
