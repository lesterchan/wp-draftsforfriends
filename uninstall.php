<?php
/*
 * Uninstall Drafts For Friends
 */
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit ();

global $wpdb;
$wpdb->draftsforfriends = $wpdb->prefix . 'draftsforfriends';

$wpdb->query( "DROP TABLE IF EXISTS $wpdb->draftsforfriends" );