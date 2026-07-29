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

/*
 * The plugin loads its three admin classes behind is_admin(), which is false for
 * the whole of a PHPUnit run, so the tests that drive the screens would never
 * see them. Required here rather than in each test file's set_up().
 */
require_once dirname( __DIR__ ) . '/includes/class-wp-draftsforfriends-list-table.php';
require_once dirname( __DIR__ ) . '/includes/class-wp-draftsforfriends-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-wp-draftsforfriends-settings.php';

/*
 * Create the table and write the option rows once, before any test runs.
 *
 * wp-env activates the plugin in the tests environment, so the activation hook
 * has usually already done this -- but relying on that makes the whole suite
 * depend on how the container was brought up, and the failure is a fatal on the
 * first query rather than anything that names its cause. maybe_upgrade() is
 * idempotent, and running it here also means the option rows exist outside any
 * test's transaction rather than being created and rolled back by whichever test
 * happened to go first.
 */
WP_DraftsForFriends_Install::maybe_upgrade();

/*
 * Discovery is by the test- prefix, so nothing else in tests/ is loaded for us:
 * the shared test case has to be required explicitly.
 */
require_once __DIR__ . '/helper-testcase.php';
