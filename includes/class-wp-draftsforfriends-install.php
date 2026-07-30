<?php
/**
 * The table, the schema check and the one migration.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and drops the shared drafts table, and folds the old rows into the new.
 *
 * Split out of the bootstrap class in 2.0.0. Activation, the schema check that
 * runs on every admin load and the option migration all answer the same
 * question -- is the stored shape current -- and they used to answer it in three
 * places, one of which wrote the version marker as a side effect of creating a
 * table.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends_Install {

	/**
	 * The option row the unreleased 2.0.0 work wrote the schema counter into.
	 *
	 * Kept as a literal name rather than a constant on the current API: it is
	 * something migrate() reads once and deletes, not a row this plugin owns.
	 *
	 * @var string
	 */
	const LEGACY_DB_VERSION = 'draftsforfriends_db_version';

	/**
	 * The plugin's table for the current site.
	 *
	 * Built from $wpdb->prefix rather than read from $wpdb->draftsforfriends,
	 * because uninstall.php reaches drop_table() without the plugin having
	 * booted, so nothing has registered the name. switch_to_blog() moves the
	 * prefix, which is what keeps this correct per site on a network.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'draftsforfriends';
	}

	/**
	 * Bring the current site, or every site on a network, up to date.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		if ( ! is_multisite() || ! $network_wide ) {
			self::maybe_upgrade();

			return;
		}

		// get_sites(), not the deprecated wp_get_sites(), which is capped at 100.
		// get_sites() defaults 'number' to 100 too, so it is lifted explicitly or
		// every site past the hundredth silently goes without its table.
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			self::maybe_upgrade();

			// Inside the loop: switch_to_blog() pushes onto a stack, so one
			// restore after the loop leaves it unwound by all but one.
			restore_current_blog();
		}
	}

	/**
	 * Bring the stored rows and the table up to date with the running code.
	 *
	 * Runs on activation and on every admin load, because activation hooks do
	 * not fire when a plugin is updated -- which is the usual reason a migration
	 * never runs at all. Idempotent, and a single option read once everything is
	 * current.
	 *
	 * Both markers are written together in one update_option() at the very end,
	 * so a half-finished upgrade never records itself as complete.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$versions = WP_DraftsForFriends_Options::get_versions();

		if ( WP_DRAFTSFORFRIENDS_VERSION === $versions['plugin'] && WP_DRAFTSFORFRIENDS_DB_VERSION === $versions['db'] ) {
			return;
		}

		self::migrate( $versions );

		update_option(
			WP_DraftsForFriends_Options::VERSION,
			array(
				'plugin' => WP_DRAFTSFORFRIENDS_VERSION,
				'db'     => WP_DRAFTSFORFRIENDS_DB_VERSION,
			)
		);
	}

	/**
	 * Fold whatever the previous version left behind into the current rows.
	 *
	 * There is remarkably little to fold in, and that is the finding rather than
	 * an omission: every released WP-DraftsForFriends stored no option rows at
	 * all. The shares have had their own table since 1.0.0, the duration the add
	 * form offered was hardcoded, and there was never a settings screen. The one
	 * row that exists in the wild is draftsforfriends_db_version, written by the
	 * unreleased 2.0.0 work, and it holds the same schema counter the new db
	 * marker holds -- so it is carried across rather than discarded, which is
	 * what keeps a site that already has the current table from being put
	 * through dbDelta() again for nothing.
	 *
	 * The pre-1.0.0 `shared` row is deliberately left alone. 1.0.0's changelog
	 * records moving the shares out of it and into the table, that release did
	 * not delete it, and the name is unprefixed -- so a decade on there is no
	 * way to know that a row called `shared` belongs to this plugin rather than
	 * to something else on the site. Deleting somebody else's option row is a
	 * worse outcome than leaving a dead one behind.
	 *
	 * @param array $versions The markers as they stood before the upgrade.
	 * @return void
	 */
	private static function migrate( $versions ) {
		$legacy_db_version = get_option( self::LEGACY_DB_VERSION, false );

		if ( false !== $legacy_db_version ) {
			$versions['db'] = (string) $legacy_db_version;

			delete_option( self::LEGACY_DB_VERSION );
		}

		if ( WP_DRAFTSFORFRIENDS_DB_VERSION !== $versions['db'] ) {
			self::create_table();
		}

		$stored = get_option( WP_DraftsForFriends_Options::OPTION, false );

		if ( false === $stored ) {
			// A fresh install, or any site upgrading from a released version.
			// Write the defaults once rather than leaving the row absent and
			// merging them on every read forever.
			add_option( WP_DraftsForFriends_Options::OPTION, WP_DraftsForFriends_Options::get_defaults() );

			return;
		}

		// Re-sanitise, so a row written under an older schema is brought to the
		// current shape and bounds before anything reads it.
		WP_DraftsForFriends_Options::update( WP_DraftsForFriends_Options::sanitize( $stored ) );
	}

	/**
	 * Create or update the table for the current site.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				hash varchar(32) NOT NULL DEFAULT '',
				date_created datetime NOT NULL,
				date_extended datetime DEFAULT NULL,
				date_expired datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY user_id (user_id),
				KEY postid_hash_expired (post_id, hash, date_expired)
			) {$collate};"
		);
	}

	/**
	 * Drop the table for the current site.
	 *
	 * Lives here rather than in uninstall.php so the schema change happens in
	 * includes/, which is where the shared phpcs.xml accepts a direct database
	 * call for a plugin that owns a table. %i binds the identifier, which is
	 * what makes a DROP statement preparable at all.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}
}
