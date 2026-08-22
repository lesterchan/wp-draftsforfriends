<?php
/**
 * Uninstall WP-DraftsForFriends.
 *
 * Runs with the plugin inactive, so nothing here may assume the plugin has
 * booted. The two class files it needs are required explicitly and declare
 * nothing but a class, which is also what keeps the schema change inside
 * includes/ where the rest of the plugin's table work lives.
 *
 * @package WP-DraftsForFriends
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-wp-draftsforfriends-options.php';
require_once __DIR__ . '/includes/class-wp-draftsforfriends-install.php';

/**
 * Delete the plugin's options for the current site.
 *
 * The list is longer than the plugin now writes: draftsforfriends_db_version is
 * the row the unreleased 2.0.0 work created and the migration folds in, but an
 * install that never reached the migration still has it, so uninstall has to
 * clear that one too.
 *
 * The pre-1.0.0 `shared` row is deliberately absent, for the same reason the
 * migration leaves it alone: the name is unprefixed, so there is no way to know
 * a decade later that a row called `shared` belongs to this plugin.
 *
 * @return void
 */
function wp_draftsforfriends_delete_options() {
	$option_names = array(
		'wp_draftsforfriends_options',
		'wp_draftsforfriends_version',
		// Pre-2.0.0 row.
		'draftsforfriends_db_version',
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

/**
 * Delete this plugin's data from the current site.
 *
 * The one verb the loop below calls: the rows and the shares table go
 * together or not at all.
 *
 * @return void
 */
function wp_draftsforfriends_uninstall_site() {
	wp_draftsforfriends_delete_options();
	WP_DraftsForFriends_Install::drop_table();
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	// otherwise leave the options and the table behind on every site past the
	// hundredth while still reporting a clean uninstall.
	$wp_draftsforfriends_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_draftsforfriends_site_ids as $wp_draftsforfriends_site_id ) {
		switch_to_blog( (int) $wp_draftsforfriends_site_id );

		wp_draftsforfriends_uninstall_site();

		// Inside the loop: switch_to_blog() pushes onto a stack, so restoring
		// once after the loop leaves it unwound by exactly one.
		restore_current_blog();
	}

	unset( $wp_draftsforfriends_site_ids, $wp_draftsforfriends_site_id );
} else {
	wp_draftsforfriends_uninstall_site();
}
