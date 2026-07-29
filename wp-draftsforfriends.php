<?php
/**
 * Plugin Name: WP-DraftsForFriends
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Now you don't need to add friends as users to the blog in order to let them preview your drafts.
 * Version: 2.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-draftsforfriends
 * Domain Path: /languages
 *
 * @package WP-DraftsForFriends
 */

/*
	Copyright 2026  Lester Chan  (email : lesterchan@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-DraftsForFriends version.
 */
define( 'WP_DRAFTSFORFRIENDS_VERSION', '2.0.0' );

/**
 * Database schema version. Bump when the table definition changes.
 */
define( 'WP_DRAFTSFORFRIENDS_DB_VERSION', '1' );

/**
 * WP-DraftsForFriends main file.
 */
define( 'WP_DRAFTSFORFRIENDS_MAIN_FILE', __FILE__ );

/**
 * WP-DraftsForFriends directory and URL.
 *
 * Derived from this file so the plugin keeps working when it is installed under
 * a directory name other than wp-draftsforfriends.
 */
define( 'WP_DRAFTSFORFRIENDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_DRAFTSFORFRIENDS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-draftsforfriends-shares.php';
require_once __DIR__ . '/includes/class-draftsforfriends-preview.php';
require_once __DIR__ . '/includes/class-draftsforfriends.php';

DraftsForFriends::get_instance();
