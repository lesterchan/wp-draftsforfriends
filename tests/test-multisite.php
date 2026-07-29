<?php
/**
 * Tests that only mean anything on a network.
 *
 * They skip themselves on a single site run, so bin/test-multisite.sh is the
 * only way they execute.
 *
 * @package wp-draftsforfriends
 */

/**
 * Table registration, per-site isolation and network activation.
 *
 * Every one of these covers a bug the plugin actually shipped: the table name
 * was assigned rather than registered, so it did not follow switch_to_blog();
 * activation called wp_get_sites(), removed in WordPress 5.1, and fatalled; and
 * the site loop stopped at the hundredth site while still reporting success.
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
		$this->assertStringContainsString( (string) $other, $switched );
	}

	public function test_the_table_is_registered_as_blog_scoped() {
		global $wpdb;

		$this->assertContains( 'draftsforfriends', $wpdb->tables, '$wpdb->tables[] is what re-prefixes the name across switch_to_blog()' );
		$this->assertArrayHasKey( 'draftsforfriends', $wpdb->tables( 'blog' ) );
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
}
