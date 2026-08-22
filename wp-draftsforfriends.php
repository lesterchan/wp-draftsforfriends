<?php
/**
 * Plugin Name: WP-DraftsForFriends
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Now you don't need to add friends as users to the blog in order to let them preview your drafts.
 * Version: 2.0.1
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
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
*/

defined( 'ABSPATH' ) || exit;

/*
 * WP_DRAFTSFORFRIENDS_DIR and WP_DRAFTSFORFRIENDS_URL are derived from __FILE__
 * so the plugin keeps working when it is installed under a directory name other
 * than wp-draftsforfriends. WP_DRAFTSFORFRIENDS_SLUG is the literal slug: it is
 * the text domain and the page slug, neither of which may change because
 * somebody unzipped the plugin into a differently named directory.
 */
define( 'WP_DRAFTSFORFRIENDS_VERSION', '2.0.1' );
define( 'WP_DRAFTSFORFRIENDS_DB_VERSION', '1' );
define( 'WP_DRAFTSFORFRIENDS_SLUG', 'wp-draftsforfriends' );
define( 'WP_DRAFTSFORFRIENDS_MAIN_FILE', __FILE__ );
define( 'WP_DRAFTSFORFRIENDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_DRAFTSFORFRIENDS_URL', plugin_dir_url( __FILE__ ) );

require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-shares.php';
require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-options.php';
require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-install.php';
require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-preview.php';
require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends.php';

WP_DraftsForFriends::get_instance();
