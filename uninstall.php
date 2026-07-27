<?php
/*
 * Uninstall Drafts For Friends
 */
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit ();

/**
* Delete plugin table when uninstalled
*
* @access public
* @return void
*/
function plugin_uninstalled() {
	global $wpdb;

	$draftsforfriends_table = $wpdb->prefix . 'draftsforfriends';
	$wpdb->query( "DROP TABLE IF EXISTS $draftsforfriends_table" );
}

if ( is_multisite() ) {
	// wp_get_sites() was removed in WP 5.1 and fatals here. get_sites() defaults
	// 'number' to 100, so it must be lifted explicitly or the tables are left
	// behind on every site past the hundredth with no error reported.
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		plugin_uninstalled();
		// Inside the loop: switch_to_blog() pushes onto a stack, so one restore
		// after the loop leaves it unwound by all but one.
		restore_current_blog();
	}
} else {
	plugin_uninstalled();
}
