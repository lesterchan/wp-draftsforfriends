<?php
/**
 * The table, the schema check and the migration.
 *
 * @package wp-draftsforfriends
 */

/**
 * WP_DraftsForFriends_Install: the table it owns, and the one migration.
 *
 * The uninstall side lives in WP_DraftsForFriends_Metadata_Test, which every
 * plugin in the collection carries. What is left here is what is specific to a
 * plugin that owns a table.
 */
class WP_DraftsForFriends_Install_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * The table exists and carries every column the plugin reads.
	 */
	public function test_table_schema() {
		global $wpdb;

		$table = $wpdb->prefix . 'draftsforfriends';

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'The table is created with the name the plugin looks for.' );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

		foreach ( array( 'id', 'post_id', 'user_id', 'hash', 'date_created', 'date_extended', 'date_expired' ) as $column ) {
			$this->assertContains( $column, $columns, $column . ' is missing from the table schema.' );
		}
	}

	/**
	 * The schema version is recorded, so the check on load knows to stay quiet.
	 */
	public function test_schema_version_is_recorded() {
		// Activation does not fire on plugin update, so the check also runs on
		// admin_init. Either way the markers must end up set.
		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertSame(
			array(
				'plugin' => WP_DRAFTSFORFRIENDS_VERSION,
				'db'     => WP_DRAFTSFORFRIENDS_DB_VERSION,
			),
			get_option( WP_DraftsForFriends_Options::VERSION ),
			'the version row does not hold the running version and schema counter'
		);
	}

	/**
	 * Running the schema check twice must not churn the table.
	 */
	public function test_schema_check_is_idempotent() {
		global $wpdb;

		WP_DraftsForFriends_Install::create_table();
		$before = $wpdb->get_var( $wpdb->prepare( 'SHOW CREATE TABLE %i', $wpdb->prefix . 'draftsforfriends' ), 1 );

		WP_DraftsForFriends_Install::create_table();
		$after = $wpdb->get_var( $wpdb->prepare( 'SHOW CREATE TABLE %i', $wpdb->prefix . 'draftsforfriends' ), 1 );

		$this->assertSame( $before, $after, 'Running the schema check twice leaves the table as it was.' );
	}

	/**
	 * The same three guards apply to activation, which carries its own site loop.
	 */
	public function test_activation_site_loop_is_correct() {
		$source = $this->source_without_comments( 'includes/class-wp-draftsforfriends-install.php' );

		$this->assertStringNotContainsString( 'wp_get_sites', $source, 'The removed wp_get_sites() is not called; it capped a network at 100 sites.' );
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source, 'Activation lifts the site query cap, or a network past the default is half-activated.' );
		$this->assertMatchesRegularExpression(
			'/foreach\s*\(.*?restore_current_blog\(\s*\);\s*\}/s',
			$source,
			'Activation restores the blog inside its loop, not once after it.'
		);
	}

	public function test_the_table_name_is_built_from_the_prefix() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'draftsforfriends', WP_DraftsForFriends_Install::table(), 'The table name is built from the site prefix, so a network gets one per site.' );
		$this->assertSame( $wpdb->draftsforfriends, WP_DraftsForFriends_Install::table(), 'the registered name and the built name disagree' );
	}

	/**
	 * Put the site back to how a pre-2.0.0 install looks.
	 *
	 * @return void
	 */
	private function forget_the_upgrade() {
		delete_option( WP_DraftsForFriends_Options::VERSION );
		delete_option( WP_DraftsForFriends_Options::OPTION );
	}

	public function test_the_migration_folds_in_the_pre_2_0_0_schema_row_and_deletes_it() {
		$this->forget_the_upgrade();

		add_option( WP_DraftsForFriends_Install::LEGACY_DB_VERSION, WP_DRAFTSFORFRIENDS_DB_VERSION );

		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertFalse(
			get_option( WP_DraftsForFriends_Install::LEGACY_DB_VERSION, false ),
			'the pre-2.0.0 schema row survived the migration'
		);

		$this->assertSame(
			array(
				'plugin' => WP_DRAFTSFORFRIENDS_VERSION,
				'db'     => WP_DRAFTSFORFRIENDS_DB_VERSION,
			),
			get_option( WP_DraftsForFriends_Options::VERSION ),
			'the schema counter was not carried across into the new db marker'
		);
	}

	public function test_a_fresh_install_gets_the_settings_row_written_once() {
		$this->forget_the_upgrade();

		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertSame(
			WP_DraftsForFriends_Options::get_defaults(),
			get_option( WP_DraftsForFriends_Options::OPTION ),
			'a fresh install should hold the defaults rather than an absent row merged on every read'
		);
	}

	public function test_the_migration_resanitises_a_row_written_under_an_older_shape() {
		delete_option( WP_DraftsForFriends_Options::VERSION );

		update_option(
			WP_DraftsForFriends_Options::OPTION,
			array(
				'expires' => 0,
				'measure' => 'fortnights',
				'stray'   => 'x',
			)
		);

		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertSame(
			array(
				'expires' => 1,
				'measure' => 'h',
			),
			get_option( WP_DraftsForFriends_Options::OPTION ),
			'the migration did not bring an old row to the current shape and bounds'
		);
	}

	public function test_maybe_upgrade_does_nothing_once_the_markers_are_current() {
		WP_DraftsForFriends_Install::maybe_upgrade();

		// A shape the sanitiser would reject, left in place deliberately: with the
		// markers current, nothing should touch the row at all.
		update_option( WP_DraftsForFriends_Options::OPTION, array( 'expires' => 0 ) );

		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertSame( array( 'expires' => 0 ), get_option( WP_DraftsForFriends_Options::OPTION ), 'With the markers current the upgrade leaves the stored row untouched.' );
	}

	public function test_activation_brings_a_single_site_up_to_date() {
		$this->forget_the_upgrade();

		WP_DraftsForFriends_Install::activate( false );

		$this->assertSame(
			array(
				'plugin' => WP_DRAFTSFORFRIENDS_VERSION,
				'db'     => WP_DRAFTSFORFRIENDS_DB_VERSION,
			),
			get_option( WP_DraftsForFriends_Options::VERSION ),
			'activation did not record the version markers'
		);
	}
}
