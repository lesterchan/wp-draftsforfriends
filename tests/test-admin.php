<?php
/**
 * The shared drafts screen.
 *
 * @package wp-draftsforfriends
 */

/**
 * The menu, the screen and the markup it produces.
 */
class WP_DraftsForFriends_Admin_Test extends WP_DraftsForFriends_TestCase {

	public function test_the_screen_renders_cleanly_with_what_it_should_show() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
		$this->assertStringContainsString( 'Drafts for Friends', $html );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( $share->hash, $html, 'the share link is missing' );
	}

	public function test_the_screen_has_one_h1_and_no_inline_presentation_attributes() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertSame( 1, preg_match_all( '/<h1[ >]/', $html ), '§4.4 allows exactly one h1 per screen' );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z][^>]* style=/i', $html, '§4.4 forbids inline style attributes' );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z][^>]* (valign|align)=/i', $html, '§4.4 forbids valign and align attributes' );
	}

	public function test_the_add_form_is_the_screens_own_markup() {
		global $wp_settings_sections, $wp_settings_fields;

		wp_set_current_user( $this->author_id );

		$html = $this->render_admin_page();
		$page = WP_DraftsForFriends_Admin::PAGE;

		$this->assertArrayNotHasKey( $page, (array) $wp_settings_sections, 'the shared drafts screen registered a settings section' );
		$this->assertArrayNotHasKey( $page, (array) $wp_settings_fields, 'the shared drafts screen registered a settings field' );

		$this->assertStringContainsString( 'Share a Draft', $html );
		$this->assertStringContainsString( '<label for="draftsforfriends-post-id">Choose a draft:</label>', $html );
		$this->assertStringContainsString( '<label for="draftsforfriends-expires">Share it for:</label>', $html );
		$this->assertStringContainsString( 'name="post_id"', $html );
		$this->assertStringContainsString( 'name="expires"', $html );
		$this->assertStringContainsString( 'name="measure"', $html );
		$this->assertStringContainsString( '<optgroup label="Drafts:">', $html );
		$this->assertStringContainsString( 'value="' . $this->draft_id . '"', $html );
	}

	public function test_the_add_form_carries_its_own_nonce() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'id="draftsforfriends-add"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html, 'the add form posts without a nonce field' );
		$this->assertStringContainsString( 'name="draftsforfriends_add"', $html, 'the submit button does not identify the form' );
	}

	public function test_the_add_form_starts_on_the_configured_duration() {
		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 5,
				'measure' => 'd',
			)
		);

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'id="draftsforfriends-expires" type="number" min="1" max="9999" step="1" value="5"', $html );
		$this->assertStringContainsString( '<option value="d" selected=', $html, 'the configured unit is not preselected' );
	}

	public function test_the_add_form_is_absent_with_nothing_to_share() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( 'id="draftsforfriends-add"', $html );
		$this->assertStringNotContainsString( 'Share a Draft', $html );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'the list should still render' );
	}

	public function test_every_control_on_the_screen_is_labelled() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString(
			'<label class="screen-reader-text" for="draftsforfriends-measure">',
			$html,
			'the add form unit dropdown is unlabelled'
		);

		$this->assertStringContainsString(
			'<label class="screen-reader-text" for="draftsforfriends-extend-measure">',
			$html,
			'the Extend unit dropdown is unlabelled'
		);

		$this->assertStringContainsString(
			'<label for="draftsforfriends-extend-expires">',
			$html,
			'the Extend duration input is unlabelled'
		);

		$this->assertStringContainsString(
			'<label class="screen-reader-text" for="cb-select-',
			$html,
			'the row checkboxes are unlabelled'
		);
	}

	public function test_the_screen_has_no_leaked_markup() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( '<?php', $html );
		$this->assertStringNotContainsString( 'translators:', $html, 'a translators comment reached HTML context' );
		$this->assertStringNotContainsString( '&amp;amp;', $html, 'something was escaped twice' );
		$this->assertStringNotContainsString( 'Fatal error', $html );
	}

	public function test_the_post_title_is_escaped_everywhere_it_appears() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'a raw post title reached the page' );
		$this->assertStringContainsString( 'Draft &lt;b&gt;Title&lt;/b&gt;', $html );
	}

	public function test_the_list_is_scoped_by_capability() {
		$editor_share = $this->make_share( $this->editor_id, $this->editor_draft_id );
		$author_share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( $author_share->hash, $html );
		$this->assertStringNotContainsString( $editor_share->hash, $html, "the author's screen leaked the editor's share" );

		wp_set_current_user( $this->editor_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( $author_share->hash, $html, 'an editor should see every share' );
		$this->assertStringContainsString( $editor_share->hash, $html );
	}

	public function test_the_share_link_points_at_the_post() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html );
		$this->assertStringContainsString( 'p=' . $this->draft_id, $html );
	}

	/**
	 * Sorting and paging arguments cannot reach the SQL or break the screen.
	 *
	 * @dataProvider data_request_args
	 *
	 * @param array $get Query arguments.
	 */
	public function test_request_arguments_are_constrained( array $get ) {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page( $get );

		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'database error', $html );
		$this->assertNotEmpty( get_post( $this->draft_id ), 'wp_posts survived' );
	}

	/**
	 * Query argument combinations to try.
	 *
	 * Nothing here may ask for a page beyond the last one: WP_List_Table answers
	 * that with wp_redirect() and exit, which would take the test runner with it.
	 *
	 * @return array
	 */
	public function data_request_args() {
		return array(
			'sort by id'       => array(
				array(
					'orderby' => 'id',
					'order'   => 'asc',
				),
			),
			'sort by expiry'   => array(
				array(
					'orderby' => 'date_expired',
					'order'   => 'desc',
				),
			),
			'sort by title'    => array(
				array(
					'orderby' => 'post_title',
					'order'   => 'asc',
				),
			),
			'injected orderby' => array(
				array(
					'orderby' => 'id; DROP TABLE wp_posts',
					'order'   => 'asc',
				),
			),
			'injected order'   => array(
				array(
					'orderby' => 'date_created',
					'order'   => "asc'--",
				),
			),
			'non-numeric page' => array( array( 'paged' => 'abc' ) ),
			'first page'       => array( array( 'paged' => '1' ) ),
			'legacy sort args' => array(
				array(
					'dff_sortby'    => 'id;DROP TABLE wp_posts',
					'dff_sortorder' => 'asc',
				),
			),
			'legacy page arg'  => array( array( 'dff_page' => '-1' ) ),
		);
	}

	public function test_the_menu_is_one_top_level_entry_with_settings_last() {
		global $menu, $submenu;

		wp_set_current_user( $this->editor_id );

		$this->register_admin_menu();

		$this->assertContains(
			WP_DraftsForFriends_Admin::PAGE,
			wp_list_pluck( (array) $menu, 2 ),
			'§4.1 wants one top-level menu for a plugin with data-management screens'
		);

		$slugs = wp_list_pluck( (array) ( $submenu[ WP_DraftsForFriends_Admin::PAGE ] ?? array() ), 2 );

		$this->assertSame(
			array( WP_DraftsForFriends_Admin::PAGE, WP_DraftsForFriends_Settings::PAGE ),
			$slugs,
			'§4.1 wants the data screen first and Settings last'
		);
	}

	public function test_the_menu_slug_does_not_embed_the_directory_name() {
		global $menu;

		wp_set_current_user( $this->editor_id );

		$this->register_admin_menu();

		foreach ( wp_list_pluck( (array) $menu, 2 ) as $slug ) {
			$this->assertStringNotContainsString( '.php', (string) $slug, 'the menu slug is still a plugin file name' );
		}

		$this->assertSame( WP_DRAFTSFORFRIENDS_SLUG, WP_DraftsForFriends_Admin::PAGE, 'PAGE must be the plugin slug' );
	}

	public function test_the_menu_requires_the_capability() {
		global $menu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->register_admin_menu();

		$this->assertNotContains( WP_DraftsForFriends_Admin::PAGE, wp_list_pluck( (array) $menu, 2 ) );
	}

	public function test_the_settings_submenu_is_hidden_from_an_author() {
		global $submenu;

		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		$entries = (array) ( $submenu[ WP_DraftsForFriends_Admin::PAGE ] ?? array() );

		$this->assertNotEmpty( $entries, 'an author should still get the shared drafts submenu' );

		$this->assertNotContains(
			WP_DraftsForFriends_Settings::PAGE,
			wp_list_pluck( $entries, 2 ),
			'the settings screen takes manage_options, which an author does not have'
		);
	}

	public function test_the_capability_filter_gates_every_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$seen = array();

		add_filter(
			'wp_draftsforfriends_capability',
			static function ( $capability, $context ) use ( &$seen ) {
				$seen[] = $context;

				return 'read';
			},
			10,
			2
		);

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'the filter did not open the screen' );
		$this->assertContains( 'shares', $seen, 'the shared drafts screen did not consult the filter' );

		$this->assertSame( 'read', WP_DraftsForFriends_Settings::capability( 'settings' ), 'the settings screen did not consult the filter' );
		$this->assertContains( 'settings', $seen );
	}

	public function test_the_assets_load_only_on_the_plugins_own_screen() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( 'edit.php' );

		$this->assertFalse( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the script loaded on an unrelated screen' );
		$this->assertFalse( wp_style_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the stylesheet loaded on an unrelated screen' );

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );

		$this->assertTrue( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the script did not load on its own screen' );
		$this->assertTrue( wp_style_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the stylesheet did not load on its own screen' );
	}

	public function test_the_asset_urls_are_derived_from_the_main_file() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );

		$this->assertSame(
			WP_DRAFTSFORFRIENDS_URL . 'js/wp-draftsforfriends-admin.js',
			wp_scripts()->registered['wp-draftsforfriends-admin']->src,
			'the script URL is not built from WP_DRAFTSFORFRIENDS_URL, so it 404s under a renamed directory'
		);

		$this->assertSame(
			WP_DRAFTSFORFRIENDS_URL . 'css/wp-draftsforfriends-admin.css',
			wp_styles()->registered['wp-draftsforfriends-admin']->src,
			'the stylesheet URL is not built from WP_DRAFTSFORFRIENDS_URL'
		);
	}

	public function test_the_localised_object_carries_every_string_the_script_reads() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );

		$data = (string) wp_scripts()->get_data( 'wp-draftsforfriends-admin', 'data' );

		$this->assertStringContainsString( 'wpDraftsForFriendsL10n', $data, '§6 names the localised object {{CLASS}}L10n in lowerCamel' );

		foreach ( array( 'errorPostId', 'errorExpires', 'errorSelect', 'confirmRevoke', 'copy', 'copied', 'copyFailed' ) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data, "the '{$key}' string is not localised into the page" );
		}
	}
}
