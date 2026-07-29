<?php
/**
 * The plugin bootstrap.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap: registers the table, the schema check and the hooks.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends {

	/**
	 * The option holding the schema version.
	 *
	 * Kept in its own row rather than folded into plugin settings, because it is
	 * read to decide whether anything else needs migrating.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'draftsforfriends_db_version';

	/**
	 * Static instance.
	 *
	 * @var WP_DraftsForFriends|null
	 */
	private static $instance;

	/**
	 * Constructor.
	 *
	 * Activation hooks are registered here rather than on a later hook: this
	 * runs while the main plugin file is being loaded, which is where WordPress
	 * requires them to be registered.
	 */
	public function __construct() {
		$this->register_table();

		register_activation_hook( WP_DRAFTSFORFRIENDS_MAIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'add_hooks' ) );
	}

	/**
	 * Initializes the plugin object and returns its instance.
	 *
	 * @return WP_DraftsForFriends
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Make $wpdb->draftsforfriends available.
	 *
	 * Adding the name to $wpdb->tables is what keeps it correct across
	 * switch_to_blog() on multisite, since WordPress re-prefixes those. The bare
	 * property assignment the plugin used before 2.0.0 did not.
	 *
	 * @return void
	 */
	private function register_table() {
		global $wpdb;

		$wpdb->tables[]         = 'draftsforfriends';
		$wpdb->draftsforfriends = $wpdb->prefix . 'draftsforfriends';
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * @return void
	 */
	public function add_hooks() {
		// Activation does not fire when a plugin is updated, so the schema check
		// also runs on load. It is a single option read once the table is current.
		add_action( 'admin_init', array( $this, 'maybe_upgrade_table' ) );

		// A share whose post is gone can never be shown or managed, and it used to
		// keep inflating the admin item count from a table nothing joined it out of.
		add_action( 'deleted_post', array( 'WP_DraftsForFriends_Shares', 'delete_for_post' ) );

		new WP_DraftsForFriends_Preview();

		// Registered unconditionally, and pointed at a loader rather than at the
		// admin class: wp_ajax_* only ever fires from admin-ajax.php, so there is
		// nothing to gate, and gating it on is_admin() made the endpoint depend on
		// whether WP_ADMIN happened to be defined by the time plugins_loaded ran.
		add_action( 'wp_ajax_draftsforfriends_admin', array( $this, 'ajax' ) );

		// The list table pulls in wp-admin/includes/class-wp-list-table.php, so
		// neither it nor the admin screen is loaded on front-end requests.
		if ( is_admin() ) {
			$this->load_admin();

			new WP_DraftsForFriends_Admin();
		}
	}

	/**
	 * Load the admin-only classes.
	 *
	 * @return void
	 */
	private function load_admin() {
		require_once __DIR__ . '/class-wp-draftsforfriends-list-table.php';
		require_once __DIR__ . '/class-wp-draftsforfriends-admin.php';
	}

	/**
	 * Handle the screen's endpoint, loading the admin classes on demand.
	 *
	 * @return void
	 */
	public function ajax() {
		$this->load_admin();

		WP_DraftsForFriends_Admin::ajax();
	}

	/**
	 * Create the table on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			// wp_get_sites() was removed in WP 5.1. get_sites() defaults 'number'
			// to 100, so it is lifted explicitly or every site past the hundredth
			// silently goes without its table.
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				$this->create_table();

				// Inside the loop: switch_to_blog() pushes onto a stack, so one
				// restore after the loop leaves it unwound by all but one.
				restore_current_blog();
			}

			return;
		}

		$this->create_table();
	}

	/**
	 * Bring the table up to date if the recorded schema version is behind.
	 *
	 * @return void
	 */
	public function maybe_upgrade_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === WP_DRAFTSFORFRIENDS_DB_VERSION ) {
			return;
		}

		$this->create_table();
	}

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

	/**
	 * Create or update the table for the current site.
	 *
	 * @return void
	 */
	private function create_table() {
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

		update_option( self::DB_VERSION_OPTION, WP_DRAFTSFORFRIENDS_DB_VERSION, false );
	}
}
