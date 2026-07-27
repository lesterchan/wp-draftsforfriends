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

/**
 * Keep the suite off the network.
 *
 * Core's update checks fire at unpredictable points during a run. In a container
 * with no route to api.wordpress.org they fail, and wp_update_plugins() answers
 * that with wp_trigger_error(), which phpunit.xml.dist turns into an exception
 * against whichever test happened to be running -- an intermittent failure that
 * moves around and has nothing to do with the plugin.
 *
 * An empty 200 rather than a WP_Error, because an error is what triggers the
 * warning in the first place. Nothing in this plugin makes an HTTP request, so
 * no test needs a real response.
 */
tests_add_filter(
	'pre_http_request',
	function () {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
);

require $_tests_dir . '/includes/bootstrap.php';
