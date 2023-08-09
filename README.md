# WP-DraftsForFriends
Contributors: GamerZ  
Donate link: http://lesterchan.net/site/donation/  
Tags: friends, preview, drafts, send, drafts for friends, share draft, send draft  
Requires at least: 3.7  
Tested up to: 6.3  
Stable tag: trunk  
License: GPLv2 or later  
License URI: http://www.gnu.org/licenses/gpl-2.0.html  

Now you don't need to add friends as users to the blog in order to let them preview your drafts

## Description
This plugin will generate a unique link that you can send to your friends to allow them to preview your draft before they are published. You are able to set the expiry for the link as well.

Modified from Drafts for Friends originally by Neville Longbottom.

### Build Status
[![Build Status](https://travis-ci.org/lesterchan/wp-draftsforfriends.svg?branch=master)](https://travis-ci.org/lesterchan/wp-draftsforfriends)

### Development
* [https://github.com/lesterchan/wp-draftsforfriends](https://github.com/lesterchan/wp-draftsforfriends "https://github.com/lesterchan/wp-draftsforfriends")

### Credits
* Plugin icon by [Freepik](http://www.freepik.com) from [Flaticon](http://www.flaticon.com)

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Changelog

### Version 1.0.2
* It now supports Multisite Network activation

### Version 1.0.1
* Extend shared drafts is now works

### Version 1.0.0
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
* Added a 32x32 icon to the plugin from http://www.fatcow.com/free-icons
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

Coming soon ...

## Upgrade Notice

N/A
