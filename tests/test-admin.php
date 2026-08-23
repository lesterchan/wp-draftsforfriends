<?php
/**
 * The shared drafts screen.
 *
 * @package WP-DraftsForFriends
 */

/**
 * The menu, the screen and the markup it produces.
 */
class WP_DraftsForFriends_Admin_Test extends WP_DraftsForFriends_TestCase {

	public function test_the_screen_renders_cleanly_with_what_it_should_show() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
		$this->assertStringContainsString( 'Drafts for Friends', $html, 'The screen is titled after the plugin.' );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'The list of live shares is on the screen.' );
		$this->assertStringContainsString( 'wp-list-table', $html, 'The list is a core list table, so it inherits core styling and behaviour.' );
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

		$this->assertStringContainsString( 'Share a Draft', $html, 'The add form is headed.' );
		$this->assertStringContainsString( '<label for="draftsforfriends-post-id">Choose a draft:</label>', $html, 'The post select is labelled, and the label points at the control.' );
		$this->assertStringContainsString( '<label for="draftsforfriends-expires">Share it for:</label>', $html, 'The duration field is labelled, and the label points at the control.' );
		$this->assertStringContainsString( 'name="post_id"', $html, 'The form posts the post it is sharing.' );
		$this->assertStringContainsString( 'name="expires"', $html, 'The form posts the duration.' );
		$this->assertStringContainsString( 'name="measure"', $html, 'The form posts the unit the duration is in.' );
		$this->assertStringContainsString( '<optgroup label="Drafts:">', $html, 'Drafts are grouped, so a long list stays readable.' );
		$this->assertStringContainsString( 'value="' . $this->draft_id . '"', $html, 'The draft that exists is offered in the select.' );
	}

	public function test_the_add_form_carries_its_own_nonce() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'id="draftsforfriends-add"', $html, 'The add form is present, carrying the nonce field with it.' );
		$this->assertStringContainsString( 'name="_wpnonce"', $html, 'the add form posts without a nonce field' );
		$this->assertStringContainsString( 'name="_wp_http_referer"', $html, 'wp_nonce_field() should emit the referer field too' );
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

		$this->assertStringContainsString( 'id="draftsforfriends-expires" type="number" min="1" max="9999" step="1" value="5"', $html, 'The number field starts on the configured duration, not on a hardcoded one.' );
		$this->assert_option_selected( 'd', $html, 'the configured unit is not preselected' );
	}

	public function test_the_add_form_is_absent_with_nothing_to_share() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( 'id="draftsforfriends-add"', $html, 'With nothing shareable the form is not rendered at all.' );
		$this->assertStringNotContainsString( 'Share a Draft', $html, 'Its heading goes with it, rather than standing over nothing.' );
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

		$this->assertStringNotContainsString( '<?php', $html, 'No PHP tag reached the page, which would mean a template was echoed unparsed.' );
		$this->assertStringNotContainsString( 'translators:', $html, 'a translators comment reached HTML context' );
		$this->assertStringNotContainsString( '&amp;amp;', $html, 'something was escaped twice' );
		$this->assertStringNotContainsString( 'Fatal error', $html, 'No PHP diagnostic reached the page.' );
	}

	public function test_the_post_title_is_escaped_everywhere_it_appears() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'a raw post title reached the page' );
		$this->assertStringContainsString( 'Draft &lt;b&gt;Title&lt;/b&gt;', $html, 'The stored title is escaped where it is rendered, not merely on the way in.' );
	}

	/**
	 * The three payloads §7.2.4 names, written into the row unsanitised.
	 *
	 * The fixture title above carries `<b>` and a quote, which is enough to see
	 * a missing esc_html() but not enough to see the difference between
	 * escaping and dropping. These go in through $wpdb rather than through
	 * wp_insert_post(), because sanitising on the way in is the assumption
	 * under test and not a step to reproduce -- this is the row a pre-fix or
	 * compromised install already has.
	 *
	 * Both halves are asserted. A screen that swallowed the title entirely
	 * would pass the first assertion while losing the share's only human
	 * label.
	 *
	 * @return void
	 */
	public function test_a_hostile_stored_title_reaches_the_screen_escaped() {
		global $wpdb;

		$payload = '<script>window.dffXss=1;</script>" onmouseover="alert(1)" <img src=x onerror="alert(1)">';

		$this->make_share( $this->author_id, $this->draft_id );

		$wpdb->update( $wpdb->posts, array( 'post_title' => $payload ), array( 'ID' => $this->draft_id ) );
		clean_post_cache( $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringNotContainsString( '<script', $html, 'the stored title reached the page with a live script element' );
		$this->assertStringNotContainsString( '<img', $html, 'the stored title reached the page with a live img element, which is what fires onerror' );
		$this->assertStringNotContainsString( '" onmouseover="', $html, 'the stored title broke out of its attribute' );
		$this->assertStringContainsString( esc_html( $payload ), $html, 'The title must survive escaping as text; a share whose label silently vanished is its own bug.' );
	}

	public function test_the_list_is_scoped_by_capability() {
		$editor_share = $this->make_share( $this->editor_id, $this->editor_draft_id );
		$author_share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( $author_share->hash, $html, 'An author sees their own share.' );
		$this->assertStringNotContainsString( $editor_share->hash, $html, "the author's screen leaked the editor's share" );

		wp_set_current_user( $this->editor_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( $author_share->hash, $html, 'an editor should see every share' );
		$this->assertStringContainsString( $editor_share->hash, $html, 'An editor sees every share, not only their own.' );
	}

	public function test_the_share_link_points_at_the_post() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html, 'The link carries the hash that unlocks the draft.' );
		$this->assertStringContainsString( 'p=' . $this->draft_id, $html, 'The link carries the post it unlocks.' );
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
		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'The screen still renders with junk in the request rather than dying.' );
		$this->assertStringNotContainsStringIgnoringCase( 'database error', $html, 'A junk request argument never reaches the query as SQL.' );
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

	public function test_the_menu_is_one_page_under_posts_and_nothing_top_level() {
		global $menu, $submenu;

		wp_set_current_user( $this->create_admin() );

		$this->register_admin_menu();

		$this->assertNotContains(
			WP_DraftsForFriends_Admin::PAGE,
			wp_list_pluck( (array) $menu, 2 ),
			'the plugin claims a top-level menu; its one page belongs under Posts'
		);

		$slugs = wp_list_pluck( (array) ( $submenu['edit.php'] ?? array() ), 2 );

		$this->assertContains(
			WP_DraftsForFriends_Admin::PAGE,
			$slugs,
			'the page is not registered under Posts'
		);

		$this->assertSame(
			1,
			count( array_keys( $slugs, WP_DraftsForFriends_Admin::PAGE, true ) ),
			'the plugin registers its page more than once'
		);

		// And nowhere else: the settings are a tab of that page, not a second
		// entry anywhere in the sidebar.
		foreach ( (array) $submenu as $parent => $entries ) {
			foreach ( wp_list_pluck( (array) $entries, 2 ) as $slug ) {
				$this->assertNotSame(
					WP_DraftsForFriends_Settings::PAGE,
					$slug,
					"the settings are a menu entry under '{$parent}' rather than a tab"
				);
			}
		}
	}

	public function test_the_sidebar_entry_is_the_plugins_name_and_the_heading_is_not() {
		global $submenu;

		wp_set_current_user( $this->create_admin() );

		$this->register_admin_menu();

		$entries = array_values(
			array_filter(
				(array) ( $submenu['edit.php'] ?? array() ),
				static function ( $item ) {
					return isset( $item[2] ) && WP_DraftsForFriends_Admin::PAGE === $item[2];
				}
			)
		);

		// §4.1: the sidebar carries the name a site owner just saw on the Plugins
		// screen, which is the string they are scanning the menu for. The heading
		// on the screen itself says what the screen is and drops the prefix --
		// they already know where they are by the time they can read it.
		$this->assertSame( 'WP-DraftsForFriends', $entries[0][0], 'the sidebar entry is not the plugin name' );

		$html = $this->render_admin_page();

		$this->assertMatchesRegularExpression( '#<h1>Drafts for Friends</h1>#', $html, 'the heading should not carry the WP- prefix' );
	}

	public function test_the_page_has_two_flat_tabs_in_the_order_section_4_2_1_wants() {
		$this->assertSame(
			array( 'shares', 'settings' ),
			array_keys( WP_DraftsForFriends_Admin::tabs() ),
			'§4.2.1 wants the data screen first and Settings last'
		);

		$this->assertSame(
			array( 'Shared Drafts', 'Settings' ),
			array_values( WP_DraftsForFriends_Admin::tabs() ),
			'§4.2.1 fixes the tab labels'
		);
	}

	public function test_an_unknown_tab_falls_back_to_the_first_one() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_admin_page( array( 'tab' => 'nonsense' ) );

		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'an unknown tab should draw the first one' );
		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
	}

	public function test_the_menu_slug_does_not_embed_the_directory_name() {
		global $submenu;

		wp_set_current_user( $this->editor_id );

		$this->register_admin_menu();

		foreach ( wp_list_pluck( (array) ( $submenu['edit.php'] ?? array() ), 2 ) as $slug ) {
			$this->assertStringNotContainsString(
				'wp-draftsforfriends.php',
				(string) $slug,
				'the menu slug is still a plugin file name'
			);
		}

		$this->assertSame( WP_DRAFTSFORFRIENDS_SLUG, WP_DraftsForFriends_Admin::PAGE, 'PAGE must be the plugin slug' );
	}

	public function test_the_page_lives_at_edit_php() {
		$this->assertStringContainsString(
			'edit.php?page=' . WP_DraftsForFriends_Admin::PAGE,
			WP_DraftsForFriends_Admin::page_url(),
			'the page URL is not the one add_posts_page() produces'
		);

		$this->assertStringContainsString(
			'tab=settings',
			WP_DraftsForFriends_Admin::page_url( 'settings' ),
			'a tab is not addressable'
		);

		$this->assertStringNotContainsString(
			'admin.php',
			WP_DraftsForFriends_Admin::page_url(),
			'the page URL still points at the old top-level menu'
		);
	}

	public function test_the_menu_requires_the_capability() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->register_admin_menu();

		/*
		 * add_submenu_page() -- which is what add_posts_page() is -- does consult
		 * the current user, unlike add_menu_page(), and returns false without
		 * recording anything. So a subscriber genuinely gets no entry, and the
		 * assertion the old top-level menu could not make is available here.
		 */
		$this->assertNotContains(
			WP_DraftsForFriends_Admin::PAGE,
			wp_list_pluck( (array) ( $submenu['edit.php'] ?? array() ), 2 ),
			'a subscriber was given the page in the Posts menu'
		);

		$this->assertFalse( WP_DraftsForFriends_Admin::get_hook_suffix(), 'the page registered for a subscriber' );
		$this->assertFalse(
			current_user_can( WP_DraftsForFriends_Admin::capability( 'shares' ) ),
			'a subscriber can satisfy the capability the page demands'
		);
	}

	public function test_the_page_is_registered_with_the_lower_of_the_two_capabilities() {
		global $submenu;

		wp_set_current_user( $this->create_admin() );

		$this->register_admin_menu();

		$entries = array_values(
			array_filter(
				(array) ( $submenu['edit.php'] ?? array() ),
				static function ( $item ) {
					return isset( $item[2] ) && WP_DraftsForFriends_Admin::PAGE === $item[2];
				}
			)
		);

		$this->assertCount( 1, $entries, 'the page should be registered exactly once' );

		// §4.2.1: the page takes the lower capability so an author reaches it at
		// all, and the Settings tab then checks its own. Registering the page with
		// manage_options instead would shut an author out of a screen they are
		// meant to have.
		$this->assertSame(
			WP_DraftsForFriends_Admin::capability( 'shares' ),
			$entries[0][1],
			'the page does not demand the shared drafts capability'
		);

		$this->assertTrue(
			user_can( $this->author_id, $entries[0][1] ),
			'an author cannot satisfy the capability the page demands'
		);
	}

	public function test_an_author_sees_the_page_and_only_the_first_tab() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'Currently Shared Drafts', $html, 'an author should reach the shared drafts tab' );
		$this->assertStringContainsString( 'nav-tab-wrapper', $html, 'the tab strip is missing' );
		$this->assertStringContainsString( 'tab=shares', $html, 'the Shared Drafts tab link is missing' );
		$this->assertSame( 1, preg_match_all( '/class="nav-tab[ "]/', $html ), 'an author should be offered one tab' );

		$this->assertStringNotContainsString(
			'tab=settings',
			$html,
			'an author was shown a link to the Settings tab, which they cannot open'
		);
	}

	public function test_an_administrator_sees_both_tabs() {
		wp_set_current_user( $this->create_admin() );

		$html = $this->render_admin_page();

		$this->assertStringContainsString( 'tab=shares', $html, 'the Shared Drafts tab link is missing' );
		$this->assertStringContainsString( 'tab=settings', $html, 'the Settings tab link is missing' );
		$this->assertSame( 2, preg_match_all( '/class="nav-tab[ "]/', $html ), 'the tab strip should carry exactly two tabs' );
		$this->assertSame( 1, preg_match_all( '/nav-tab-active/', $html ), 'exactly one tab is active' );
	}

	public function test_an_author_cannot_open_the_settings_tab() {
		wp_set_current_user( $this->author_id );

		$this->expectException( WPDieException::class );

		$this->render_admin_page( array( 'tab' => WP_DraftsForFriends_Admin::TAB_SETTINGS ) );
	}

	public function test_an_author_posting_the_settings_form_is_refused() {
		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Settings::init();

		/*
		 * What options.php does before it will accept a save: it takes
		 * manage_options as its default and hands it to
		 * option_page_capability_{$option_page}, then wp_die()s unless the current
		 * user has whatever comes back. Asserted here rather than by posting to
		 * options.php, which ends in a redirect and an exit.
		 */
		$required = apply_filters( 'option_page_capability_' . WP_DraftsForFriends_Settings::GROUP, 'manage_options' );

		$this->assertSame(
			WP_DraftsForFriends_Settings::capability( 'settings' ),
			$required,
			'options.php would not enforce the capability the Settings tab renders behind'
		);

		$this->assertFalse(
			current_user_can( $required ),
			'an author can satisfy what options.php requires to save the settings'
		);

		// And the tab itself refuses to render for them, so neither half of the
		// round trip is open.
		$this->expectException( WPDieException::class );

		WP_DraftsForFriends_Settings::render_tab();
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
		$this->assertContains( 'settings', $seen, 'The capability filter is consulted for the settings screen as well as the main one.' );
	}

	public function test_the_assets_load_only_on_the_plugins_own_screen() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the script loaded on an unrelated screen' );
		$this->assertFalse( wp_style_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the stylesheet loaded on an unrelated screen' );

		WP_DraftsForFriends_Admin::enqueue( $this->admin_hook_suffix );

		$this->assertTrue( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the script did not load on its own screen' );
		$this->assertTrue( wp_style_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the stylesheet did not load on its own screen' );
	}

	public function test_the_asset_urls_are_derived_from_the_main_file() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::enqueue( $this->admin_hook_suffix );

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

		WP_DraftsForFriends_Admin::enqueue( $this->admin_hook_suffix );

		$data = (string) wp_scripts()->get_data( 'wp-draftsforfriends-admin', 'data' );

		$this->assertStringContainsString( 'wpDraftsForFriendsL10n', $data, '§6 names the localised object {{CLASS}}L10n in lowerCamel' );

		foreach ( array( 'errorPostId', 'errorExpires', 'errorSelect', 'confirmRevoke', 'copy', 'copied', 'copyFailed' ) as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data, "the '{$key}' string is not localised into the page" );
		}
	}

	public function test_the_screen_option_is_claimed_from_core_and_not_only_drawn() {
		WP_DraftsForFriends_Admin::init();

		$this->assertNotFalse(
			has_filter( 'set-screen-option', array( 'WP_DraftsForFriends_Admin', 'save_screen_option' ) ),
			'add_screen_options() draws a per-page control that core discards on submit unless the plugin claims the option'
		);
	}

	public function test_the_screen_option_filter_answers_only_for_this_screens_option() {
		$this->assertSame(
			2,
			WP_DraftsForFriends_Admin::save_screen_option( false, 'wp_draftsforfriends_per_page', '2' ),
			'the submitted value must come back as an integer for core to store it'
		);

		$this->assertFalse(
			WP_DraftsForFriends_Admin::save_screen_option( false, 'edit_post_per_page', '2' ),
			"another screen's per-page option is left to whoever owns it"
		);
	}

	public function test_the_per_page_value_is_kept_for_the_user_and_pages_the_list() {
		// The editor rather than the author, because the list is scoped by
		// capability: three shares are only three rows to somebody who may see
		// them all.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->make_share(
				$this->editor_id,
				self::factory()->post->create(
					array(
						'post_status' => 'draft',
						'post_author' => $this->editor_id,
					)
				)
			);
		}

		WP_DraftsForFriends_Admin::init();

		// Exactly what core does with a submitted Screen Options value: offer
		// false to the filter and store whatever comes back, or return having
		// written nothing when the answer is still false. The test stops at the
		// filter rather than calling set_screen_options(), which ends in a
		// redirect and an exit -- see §7.2.3 for what that does to a run.
		//
		// Core's own hook name, hyphen and all, so it is not ours to rename.
		// Assembled into a variable first because the sniff that objects to the
		// hyphen only reads literal hook names, and §9 allows no suppression
		// outside includes/.
		$hook   = 'set-screen-option';
		$stored = apply_filters( $hook, false, 'wp_draftsforfriends_per_page', '2' );

		$this->assertSame( 2, $stored, 'nothing claimed the value, so core would have thrown it away' );

		update_user_meta( $this->editor_id, 'wp_draftsforfriends_per_page', $stored );

		// The user the value was stored for, and asserted against that id rather
		// than against whoever the harness has logged in: get_items_per_page()
		// reads the meta through get_current_user_id(), and a mismatch reads
		// somebody else's empty string, falls through to the default of 20 and
		// reports a plugin that discards the value as working.
		wp_set_current_user( $this->editor_id );

		set_current_screen( $this->register_admin_menu() );

		$table = new WP_DraftsForFriends_List_Table();
		$table->prepare_items();

		$this->assertSame(
			2,
			$table->get_pagination_arg( 'per_page' ),
			'the list table did not read the stored value back'
		);
		$this->assertCount( 2, $table->items, 'the query still asked for a full default page' );
		$this->assertSame(
			2,
			$table->get_pagination_arg( 'total_pages' ),
			'three shares at two per page is two pages'
		);
	}
}
