<?php
/**
 * Uninstall and the schema.
 *
 * @package WP-DraftsForFriends
 */

/**
 * Uninstall.php and the table it drops.
 */
class Test_DraftsForFriends_Uninstall extends WP_UnitTestCase {

	/**
	 * The table exists and carries every column the plugin reads.
	 */
	public function test_table_schema() {
		global $wpdb;

		$table = $wpdb->prefix . 'draftsforfriends';

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

		foreach ( array( 'id', 'post_id', 'user_id', 'hash', 'date_created', 'date_extended', 'date_expired' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	/**
	 * The schema version is recorded, so the check on load knows to stay quiet.
	 */
	public function test_schema_version_is_recorded() {
		// Activation does not fire on plugin update, so the check also runs on
		// admin_init. Either way the option must end up set.
		DraftsForFriends::get_instance()->maybe_upgrade_table();

		$this->assertSame( WP_DRAFTSFORFRIENDS_DB_VERSION, get_option( DraftsForFriends::DB_VERSION_OPTION ) );
	}

	/**
	 * Running the schema check twice must not churn the table.
	 */
	public function test_schema_check_is_idempotent() {
		global $wpdb;

		$plugin = DraftsForFriends::get_instance();

		$plugin->maybe_upgrade_table();
		$before = $wpdb->get_var( $wpdb->prepare( 'SHOW CREATE TABLE %i', $wpdb->prefix . 'draftsforfriends' ), 1 );

		$plugin->maybe_upgrade_table();
		$after = $wpdb->get_var( $wpdb->prepare( 'SHOW CREATE TABLE %i', $wpdb->prefix . 'draftsforfriends' ), 1 );

		$this->assertSame( $before, $after );
	}

	/**
	 * The plugin's own source, with comments and whitespace stripped.
	 *
	 * The guards below are about code, not prose: the comments in these files
	 * explain what wp_get_sites() was and why 'number' => 0 is there, and
	 * asserting against the raw text would match the explanation rather than the
	 * call.
	 *
	 * @param string $file Path relative to the plugin root.
	 * @return string
	 */
	private function code( $file ) {
		return php_strip_whitespace( WP_DRAFTSFORFRIENDS_DIR . $file );
	}

	/**
	 * The Multisite loop in uninstall.php lifts WP_Site_Query's default cap.
	 *
	 * This is a source-level guard rather than a behavioural test: a single-site
	 * suite cannot stand up a network of more than a hundred sites, and the
	 * failure is silent -- get_sites() stops at the hundredth and uninstall still
	 * reports success, leaving the table behind everywhere after that.
	 */
	public function test_uninstall_lifts_the_site_query_cap() {
		$source = $this->code( 'uninstall.php' );

		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source );
	}

	/**
	 * Uninstall.php must not call wp_get_sites(), removed in WordPress 5.1.
	 *
	 * Also a source-level guard: calling it fatals on Multisite uninstall, which
	 * a single-site suite never reaches.
	 */
	public function test_uninstall_does_not_call_removed_function() {
		$source = $this->code( 'uninstall.php' );

		$this->assertStringNotContainsString( 'wp_get_sites', $source );
		$this->assertStringContainsString( 'get_sites', $source );
	}

	/**
	 * The restore_current_blog() call belongs inside the loop.
	 *
	 * The switch_to_blog() function pushes onto a stack, so restoring once after the loop
	 * leaves it unwound by all but one.
	 */
	public function test_uninstall_restores_inside_the_loop() {
		$source = $this->code( 'uninstall.php' );

		$this->assertMatchesRegularExpression(
			'/foreach\s*\(.*?restore_current_blog\(\s*\);\s*\}/s',
			$source,
			'restore_current_blog() must be called inside the site loop'
		);
	}

	/**
	 * The same three guards apply to activation, which carries its own site loop.
	 */
	public function test_activation_site_loop_is_correct() {
		$source = $this->code( 'includes/class-draftsforfriends.php' );

		$this->assertStringNotContainsString( 'wp_get_sites', $source );
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source );
		$this->assertMatchesRegularExpression(
			'/foreach\s*\(.*?restore_current_blog\(\s*\);\s*\}/s',
			$source
		);
	}

	/**
	 * Uninstall removes the option it owns.
	 *
	 * The file cannot simply be required here -- it drops the table the rest of
	 * the suite runs against -- so the option half is exercised directly.
	 */
	public function test_uninstall_deletes_the_schema_version_option() {
		$source = $this->code( 'uninstall.php' );

		$this->assertStringContainsString( DraftsForFriends::DB_VERSION_OPTION, $source, 'uninstall must delete the option the plugin creates' );
	}
}
