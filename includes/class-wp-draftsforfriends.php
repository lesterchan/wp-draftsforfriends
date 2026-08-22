<?php
/**
 * The plugin bootstrap.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: registers the table name, the upgrade check and the hooks.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends {

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
		// Activation does not fire when a plugin is updated, so the upgrade check
		// also runs on load. It is a single option read once everything is current.
		add_action( 'admin_init', array( 'WP_DraftsForFriends_Install', 'maybe_upgrade' ) );

		// A share whose post is gone can never be shown or managed, and it used to
		// keep inflating the admin item count from a table nothing joined it out of.
		add_action( 'deleted_post', array( 'WP_DraftsForFriends_Shares', 'delete_for_post' ) );

		new WP_DraftsForFriends_Preview();

		self::register_command();

		// The list table pulls in wp-admin/includes/class-wp-list-table.php, so
		// neither it nor the admin screens are loaded on front-end requests.
		// There is no AJAX endpoint to keep loading unconditionally any more:
		// every write is an ordinary form post to the screen itself.
		if ( is_admin() ) {
			$this->load_admin();

			WP_DraftsForFriends_Admin::init();
			WP_DraftsForFriends_Settings::init();

			// Admin-only holds for the meta box too: even the block editor posts
			// the box's fields to post.php.
			WP_DraftsForFriends_Metabox::init();
		}
	}

	/**
	 * Register the WP-CLI command.
	 *
	 * The class file is required here rather than at plugin load because it
	 * extends WP_CLI_Command, which only exists when WP-CLI is the one running
	 * WordPress. Requiring it unconditionally is a fatal error on every web
	 * request.
	 *
	 * @return void
	 */
	public static function register_command() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-command.php';

		WP_CLI::add_command( 'draftsforfriends', 'WP_DraftsForFriends_Command' );
	}

	/**
	 * Load the admin-only classes.
	 *
	 * @return void
	 */
	private function load_admin() {
		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-list-table.php';
		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-admin.php';
		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-settings.php';
		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-wp-draftsforfriends-metabox.php';
	}

	/**
	 * Bring the table and the option rows up to date on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public function activate( $network_wide ) {
		WP_DraftsForFriends_Install::activate( $network_wide );
	}
}
