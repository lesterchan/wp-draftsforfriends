<?php
/**
 * PHPUnit bootstrap.
 *
 * @package WP-DraftsForFriends
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Where wp-env mounts the WordPress test library.
	$_tests_dir = '/wordpress-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/wp-draftsforfriends.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
