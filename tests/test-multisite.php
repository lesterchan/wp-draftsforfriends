<?php
/**
 * Tests that only mean anything on a network.
 *
 * They skip themselves on a single site run, so bin/test-multisite.sh is the
 * only way they execute.
 *
 * @package WP-DraftsForFriends
 */

/**
 * Table registration, per-site isolation and network activation.
 *
 * Every one of these covers a bug the plugin actually shipped: the table name
 * was assigned rather than registered, so it did not follow switch_to_blog();
 * activation called wp_get_sites(), deprecated in WordPress 4.6 and capped at
 * 100 sites; and the site loop stopped at the hundredth site while still
 * reporting success.
 *
 * @group ms-required
 */
class WP_DraftsForFriends_Multisite_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * Skip the whole class unless this is a network.
	 */
	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only against a multisite network.' );
		}

		parent::set_up();
	}

	/**
	 * Create a second site on the network.
	 *
	 * @return int Blog id.
	 */
	private function make_site() {
		return self::factory()->blog->create();
	}

	public function test_the_table_name_follows_the_current_site() {
		global $wpdb;

		$other = $this->make_site();

		$before = $wpdb->draftsforfriends;

		switch_to_blog( $other );
		$switched = $wpdb->draftsforfriends;
		restore_current_blog();

		$this->assertNotSame( $before, $switched, 'the table name did not change with the site' );
		$this->assertSame( $before, $wpdb->draftsforfriends, 'the table name did not come back' );
		$this->assertStringContainsString( (string) $other, $switched, 'The table name follows the site switched to, so one site cannot read another.' );
	}

	public function test_the_table_is_registered_as_blog_scoped() {
		global $wpdb;

		$this->assertContains( 'draftsforfriends', $wpdb->tables, '$wpdb->tables[] is what re-prefixes the name across switch_to_blog()' );
		$this->assertArrayHasKey( 'draftsforfriends', $wpdb->tables( 'blog' ), 'The table is registered blog-scoped, so each site gets its own.' );
	}

	public function test_network_activation_creates_the_table_on_every_site() {
		global $wpdb;

		$other = $this->make_site();

		switch_to_blog( $other );
		$table = WP_DraftsForFriends_Install::table();
		WP_DraftsForFriends_Install::drop_table();
		restore_current_blog();

		WP_DraftsForFriends_Install::activate( true );

		switch_to_blog( $other );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		restore_current_blog();

		$this->assertSame( $table, $found, 'network activation skipped a site' );
	}

	public function test_network_activation_records_the_markers_on_every_site() {
		$other = $this->make_site();

		switch_to_blog( $other );
		delete_option( WP_DraftsForFriends_Options::VERSION );
		restore_current_blog();

		WP_DraftsForFriends_Install::activate( true );

		switch_to_blog( $other );
		$versions = WP_DraftsForFriends_Options::get_versions();
		restore_current_blog();

		$this->assertSame( WP_DRAFTSFORFRIENDS_VERSION, $versions['plugin'], 'network activation left a site unmigrated' );
	}

	public function test_a_share_on_one_site_is_invisible_on_another() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$other = $this->make_site();

		switch_to_blog( $other );

		WP_DraftsForFriends_Install::create_table();

		$found = WP_DraftsForFriends_Shares::get( (int) $share->id );

		restore_current_blog();

		$this->assertNull( $found, "another site's table answered for this share" );
	}

	/**
	 * The activation site query is uncapped and asks only for IDs.
	 *
	 * Read off pre_get_sites rather than proved with a 101-site fixture:
	 * get_sites() defaults to 100, so a larger network would silently keep
	 * its table and settings on every site past the hundredth while activation still
	 * reported success.
	 *
	 * @return void
	 */
	public function test_network_activation_queries_sites_without_a_cap() {
		$captured = array();
		add_action(
			'pre_get_sites',
			function ( $query ) use ( &$captured ) {
				$captured[] = $query->query_vars;
			}
		);

		WP_DraftsForFriends_Install::activate( true );

		$this->assertNotEmpty( $captured, 'Activation never queried the site list.' );
		$this->assertSame( 0, (int) $captured[0]['number'], 'get_sites() was left at its default cap of 100 sites.' );
		$this->assertSame( 'ids', $captured[0]['fields'], 'Only the site IDs are needed.' );
	}

	/**
	 * The blog stack is left unwound and the original site is current.
	 *
	 * Calling switch_to_blog() pushes onto a stack. Restoring once after the loop
	 * rather than once per iteration leaves the stack short, so whatever runs next
	 * operates against the last site visited instead of the one it thinks it is on.
	 *
	 * @return void
	 */
	public function test_network_activation_unwinds_the_blog_stack() {
		$original = get_current_blog_id();
		$this->make_site();

		WP_DraftsForFriends_Install::activate( true );

		$this->assertFalse( ms_is_switched(), 'The blog stack was left switched.' );
		$this->assertSame( $original, get_current_blog_id(), 'The original site is no longer current.' );
	}

	/**
	 * Activating for one site does not touch the rest of the network.
	 *
	 * @return void
	 */
	public function test_single_site_activation_leaves_other_sites_alone() {
		$other = $this->make_site();

		switch_to_blog( $other );
		delete_option( WP_DraftsForFriends_Options::VERSION );
		restore_current_blog();

		WP_DraftsForFriends_Install::activate( false );

		switch_to_blog( $other );
		$versions = WP_DraftsForFriends_Options::get_versions();
		restore_current_blog();

		$this->assertNotSame( WP_DRAFTSFORFRIENDS_VERSION, $versions['plugin'], 'A per-site activation migrated other sites.' );
	}
}
