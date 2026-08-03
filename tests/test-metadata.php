<?php
/**
 * What is true of WP-DraftsForFriends and of no other plugin.
 *
 * The twenty-three assertions §7.2 asks of all nineteen live in
 * Plugin_Metadata_TestCase, a byte-identical copy of
 * _standards/templates/helper-metadata-testcase.php. What is left here is the
 * three declarations that class cannot derive, the hooks it reaches back
 * through, and the assertions that are genuinely about this plugin: that it
 * owns two option rows and no more because its shares are data in a table of
 * their own, that the file it cannot include still names the same rows, and
 * that it fires exactly one hook.
 *
 * @package WP-DraftsForFriends
 */

/**
 * WP-DraftsForFriends against §7.2.
 */
class WP_DraftsForFriends_Metadata_Test extends Plugin_Metadata_TestCase {

	/**
	 * The version this release ships.
	 *
	 * @return string
	 */
	protected function expected_version() {
		return '2.0.0';
	}

	/**
	 * The prefix every class the plugin declares carries.
	 *
	 * @return string
	 */
	protected function class_prefix() {
		return 'WP_DraftsForFriends';
	}

	/**
	 * Every break a site owner updating from the released 2.4.0 would notice.
	 *
	 * The screen's address changed, the row actions became bulk actions, the
	 * class and its ajax endpoint are gone, and the plugin grew settings and a
	 * capability filter. The preview links themselves are the one thing that
	 * deliberately did not change, and the notice has to say so.
	 *
	 * @return string[]
	 */
	protected function upgrade_notice_subjects() {
		return array(
			'6.8',
			'8.2',
			// The screen that moved, old address and new.
			'edit.php?page=wp-draftsforfriends/wp-draftsforfriends.php',
			'edit.php?page=wp-draftsforfriends',
			// The links already sent, which are unchanged.
			'draftsforfriends=',
			// The rows the plugin stores, and the pre-2.0.0 one it clears.
			'wp_draftsforfriends_options',
			'wp_draftsforfriends_version',
			'draftsforfriends_db_version',
			// The retired sort parameters.
			'dff_sortby',
			'dff_sortorder',
			// For code written against the plugin.
			'WPDraftsForFriends',
			'wp_ajax_draftsforfriends_admin',
			'wp_draftsforfriends_capability',
			'option_page_capability_wp_draftsforfriends_options',
			// The two capabilities the screen and its tabs are gated on.
			'publish_posts',
			'manage_options',
		);
	}

	/**
	 * The settings row and the marker row.
	 *
	 * @return void
	 */
	protected function seed_option_rows() {
		WP_DraftsForFriends_Install::maybe_upgrade();
	}

	/**
	 * Write the marker row through the plugin's own upgrade routine.
	 *
	 * @return void
	 */
	protected function write_version_row() {
		WP_DraftsForFriends_Install::maybe_upgrade();
	}

	/**
	 * Round-trip the settings sanitiser.
	 *
	 * @param array $input What the settings form is pretending to have posted.
	 * @return array
	 */
	protected function sanitize_settings( array $input ) {
		return (array) WP_DraftsForFriends_Options::sanitize( $input );
	}

	/**
	 * The two real settings keys, so the sanitiser has work of its own to do.
	 *
	 * @return array
	 */
	protected function settings_fixture() {
		return array(
			'expires' => 3,
			'measure' => 'd',
		);
	}

	/**
	 * Register the admin bundle.
	 *
	 * It is enqueued off the screen's hook suffix, which only exists once the
	 * menu has been registered, and the menu is only registered for a user who
	 * may reach it.
	 *
	 * @return void
	 */
	protected function register_plugin_assets() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );
	}

	/**
	 * Two option rows, and the shares are not among them.
	 *
	 * The settings and the markers are configuration; a share is data, and data
	 * belongs in the plugin's own table rather than in an ever-growing option
	 * row that every request autoloads.
	 */
	public function test_the_plugin_owns_exactly_two_option_rows() {
		WP_DraftsForFriends_Install::maybe_upgrade();

		$rows = $this->stored_option_names();
		sort( $rows );

		$this->assertSame(
			array( WP_DraftsForFriends_Options::OPTION, WP_DraftsForFriends_Options::VERSION ),
			$rows,
			'The plugin owns option rows beyond its settings and its version markers. The shares are data and live in their own table.'
		);
	}

	/**
	 * The uninstaller names the same rows, and drops the table.
	 *
	 * The suite's run_uninstall() performs the deletions rather than requiring
	 * the file, because requiring it would drop the table the rest of the suite
	 * runs against. That indirection is only honest if the file itself is
	 * checked to name the same rows, which is what this does.
	 */
	public function test_uninstall_php_names_the_same_rows_and_drops_the_table() {
		$uninstall = (string) file_get_contents( $this->metadata_root() . '/uninstall.php' );

		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Options::OPTION . "'", $uninstall, 'uninstall.php does not delete the settings row.' );
		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Options::VERSION . "'", $uninstall, 'uninstall.php does not delete the version row.' );
		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Install::LEGACY_DB_VERSION . "'", $uninstall, 'uninstall.php does not delete the pre-2.0.0 row.' );
		$this->assertStringContainsString( 'WP_DraftsForFriends_Install::drop_table()', $uninstall, 'uninstall.php does not drop the plugin table.' );
	}

	/**
	 * The uninstaller walks the whole network, not the first hundred sites.
	 *
	 * A source guard because the thing it guards cannot be exercised: building
	 * a 101-site network to prove the hundred-and-first is reached is not on.
	 */
	public function test_uninstall_walks_the_whole_network() {
		$uninstall = $this->source_without_comments( 'uninstall.php' );

		$this->assertStringContainsString( 'is_multisite()', $uninstall, 'uninstall.php does not branch on multisite.' );
		$this->assertStringContainsString( "'number' => 0", $uninstall, 'uninstall.php stops at the default hundred sites.' );
		$this->assertStringContainsString( "'fields' => 'ids'", $uninstall, 'uninstall.php hydrates whole site objects to read one column.' );
		$this->assertStringNotContainsString( 'wp_get_sites', $uninstall, 'wp_get_sites() is capped at 100 sites, so a larger network uninstalls in part.' );
		$this->assertMatchesRegularExpression(
			'/switch_to_blog\([^}]*restore_current_blog\(\)/s',
			$uninstall,
			'uninstall.php closes a block between switch_to_blog() and restore_current_blog().'
		);
	}

	/**
	 * The settings row holds those two keys and nothing else.
	 *
	 * The shared test proves no version marker survives the sanitiser. This one
	 * is the other half: the sanitiser is also a whitelist, so a field added to
	 * the form and not to the sanitiser never reaches the row.
	 */
	public function test_the_settings_row_holds_exactly_its_two_keys() {
		$clean = WP_DraftsForFriends_Options::sanitize(
			array(
				'expires' => 3,
				'measure' => 'd',
				'colour'  => 'red',
			)
		);

		$this->assertSame( array( 'expires', 'measure' ), array_keys( $clean ), 'The sanitiser returned keys the settings row does not own.' );
	}

	/**
	 * The hook surface, asserted as a set.
	 *
	 * Not merely "every hook is prefixed" - the set itself is asserted, because
	 * the plugin fired none of its own before 2.0.0 and every addition is a
	 * public API this collection then has to keep. That is the point: this test
	 * is meant to fail when a hook is added, so that adding one is a decision
	 * rather than a side effect. It did exactly that when the five below
	 * landed.
	 *
	 * Sorted rather than asserted in source order, so renaming a file cannot
	 * fail a test about the API.
	 */
	public function test_the_plugin_fires_exactly_the_hooks_it_documents() {
		$fired = array();

		foreach ( (array) glob( $this->metadata_root() . '/includes/*.php' ) as $file ) {
			preg_match_all(
				'/(?:apply_filters|do_action)(?:_ref_array)?\(\s*\'([a-z0-9_]+)\'/',
				$this->source_without_comments( 'includes/' . basename( $file ) ),
				$matches
			);

			$fired = array_merge( $fired, $matches[1] );
		}

		$expected = array(
			'wp_draftsforfriends_capability',
			'wp_draftsforfriends_requested_hash',
			'wp_draftsforfriends_share_created',
			'wp_draftsforfriends_share_extended',
			'wp_draftsforfriends_share_revoked',
			'wp_draftsforfriends_share_url',
		);

		$fired = array_values( array_unique( $fired ) );
		sort( $fired );

		$this->assertSame(
			$expected,
			$fired,
			'The set of hooks this plugin fires has changed. Every one is public API: add it to the README and to this list, or take it out.'
		);
	}

	/**
	 * Five tags, which is the most wordpress.org indexes.
	 */
	public function test_the_readme_header_carries_exactly_five_tags() {
		$tags = array_filter( array_map( 'trim', explode( ',', $this->readme_field( 'Tags' ) ) ) );

		$this->assertCount( 5, $tags, '§3.2 asks for exactly five tags.' );
	}

	/**
	 * The copyright block agrees with the header two lines above it.
	 */
	public function test_the_licence_block_is_the_or_later_variant() {
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ), 'The header licence is not GPLv2 or later.' );
		$this->assertStringContainsString(
			'(at your option) any later version',
			$this->plugin_file(),
			'The GPL block is the version 2 only variant, which contradicts the header two lines above it.'
		);
	}

	/**
	 * Donations is the last h3 of the Description, with one exact wording.
	 */
	public function test_donations_is_the_last_h3_of_the_description() {
		$readme      = $this->readme();
		$description = substr( $readme, (int) strpos( $readme, '## Description' ) );
		$description = substr( $description, 0, (int) strpos( $description, '## Usage' ) );

		preg_match_all( '/^### .+$/m', $description, $matches );

		$this->assertSame( '### Donations', rtrim( (string) end( $matches[0] ) ), 'Donations is not the last h3 of the description.' );
		$this->assertStringContainsString(
			'I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.',
			$description,
			'The Donations paragraph is not the agreed wording.'
		);
	}

	/**
	 * The raised floors are a BREAKING changelog line, not only a notice.
	 *
	 * A site below them is not offered the update at all, so it is the one
	 * change that has to be findable in both places.
	 */
	public function test_the_raised_floors_are_recorded_as_a_breaking_change() {
		$this->assertStringContainsString(
			'BREAKING: Requires WordPress 6.8 and PHP 8.2',
			$this->readme(),
			'The raised floors are not a BREAKING changelog line.'
		);
	}

	/**
	 * The stylesheets use logical properties only.
	 *
	 * §5.1: this is what makes a second, mirrored sheet unnecessary, so it is
	 * asserted rather than left to the absence of one.
	 */
	public function test_the_stylesheet_uses_no_physical_properties() {
		foreach ( (array) glob( $this->metadata_root() . '/css/*.css' ) as $file ) {
			$rules = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );

			$this->assertDoesNotMatchRegularExpression(
				'/(margin|padding|border)-(left|right)\s*:|(^|[;{\s])(left|right)\s*:|text-align\s*:\s*(left|right)|float\s*:\s*(left|right)/mi',
				$rules,
				basename( $file ) . ' uses a physical property; §5.1 wants logical ones so no RTL sheet is needed.'
			);

			$this->assertStringNotContainsString( '!important', $rules, basename( $file ) . ' uses !important.' );
		}
	}
}
