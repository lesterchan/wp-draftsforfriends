<?php
/**
 * Uninstall WP-DraftsForFriends.
 *
 * Runs with the plugin inactive, so nothing here may depend on the plugin's own
 * classes or constants being loaded.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * Delete the plugin's options for the current site.
 *
 * @return void
 */
function draftsforfriends_delete_options() {
	delete_option( 'draftsforfriends_db_version' );
}

/**
 * Drop the plugin's table for the current site.
 *
 * @return void
 */
function draftsforfriends_drop_table() {
	global $wpdb;

	// The table name is built entirely from $wpdb->prefix and a literal, so there
	// is no user input to prepare. Identifiers cannot be bound anyway.
	$table = $wpdb->prefix . 'draftsforfriends';

	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	// otherwise leave the table and option behind on every site past the
	// hundredth while still reporting success.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		draftsforfriends_delete_options();
		draftsforfriends_drop_table();

		// Inside the loop: switch_to_blog() pushes onto a stack, so one restore
		// after the loop leaves it unwound by all but one.
		restore_current_blog();
	}
} else {
	draftsforfriends_delete_options();
	draftsforfriends_drop_table();
}
