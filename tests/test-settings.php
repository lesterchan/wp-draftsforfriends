<?php
/**
 * The settings screen.
 *
 * @package WP-DraftsForFriends
 */

/**
 * WP_DraftsForFriends_Settings: the registration, the one field and the screen.
 */
class WP_DraftsForFriends_Settings_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * Clear the Settings API registry, which outlives a test.
	 */
	public function tear_down() {
		unset( $GLOBALS['wp_settings_sections'][ WP_DraftsForFriends_Settings::PAGE ] );
		unset( $GLOBALS['wp_settings_fields'][ WP_DraftsForFriends_Settings::PAGE ] );

		parent::tear_down();
	}

	public function test_the_group_is_the_settings_row_name() {
		$this->assertSame(
			WP_DraftsForFriends_Options::OPTION,
			WP_DraftsForFriends_Settings::GROUP,
			'§2.2 fixes GROUP at the settings row name'
		);
	}

	public function test_the_settings_page_identifier_is_not_a_menu_slug() {
		global $submenu;

		$this->assertSame( 'wp-draftsforfriends-settings', WP_DraftsForFriends_Settings::PAGE, 'The settings page has an identifier of its own.' );
		$this->assertNotSame( WP_DraftsForFriends_Admin::PAGE, WP_DraftsForFriends_Settings::PAGE, 'It is not the main screen slug; sharing one would register both against the same page.' );

		// It is what do_settings_sections() is keyed on and nothing else. A menu
		// entry using it would be the second screen §4.2.1 replaced with a tab.
		wp_set_current_user( $this->create_admin() );

		$this->register_admin_menu();

		foreach ( (array) $submenu as $entries ) {
			$this->assertNotContains(
				WP_DraftsForFriends_Settings::PAGE,
				wp_list_pluck( (array) $entries, 2 ),
				'the settings page identifier is registered as a menu entry'
			);
		}
	}

	public function test_the_settings_tab_takes_manage_options() {
		$this->assertSame( 'manage_options', WP_DraftsForFriends_Settings::CAPABILITY, '§2.7 keeps settings on manage_options' );
		$this->assertSame( 'publish_posts', WP_DraftsForFriends_Admin::CAPABILITY, 'the data screen keeps the plugin custom capability' );

		$this->assertSame(
			WP_DraftsForFriends_Settings::capability( 'settings' ),
			WP_DraftsForFriends_Admin::tab_capability( WP_DraftsForFriends_Admin::TAB_SETTINGS ),
			'the Settings tab does not check the settings capability'
		);

		$this->assertSame(
			WP_DraftsForFriends_Admin::capability( 'shares' ),
			WP_DraftsForFriends_Admin::tab_capability( WP_DraftsForFriends_Admin::TAB_SHARES ),
			'the Shared Drafts tab does not check the shares capability'
		);
	}

	public function test_registration_registers_the_setting_the_section_and_the_field() {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;

		WP_DraftsForFriends_Settings::register();

		$page = WP_DraftsForFriends_Settings::PAGE;

		$this->assertArrayHasKey( WP_DraftsForFriends_Options::OPTION, (array) $wp_registered_settings, 'the option is not a registered setting' );
		$this->assertSame(
			array( 'WP_DraftsForFriends_Options', 'sanitize' ),
			$wp_registered_settings[ WP_DraftsForFriends_Options::OPTION ]['sanitize_callback'],
			'the registered setting has no sanitize callback, or not the one that owns the shape'
		);

		$this->assertArrayHasKey( WP_DraftsForFriends_Settings::SECTION_SHARE, $wp_settings_sections[ $page ], 'the section is not registered' );
		$this->assertSame(
			array( 'expires' ),
			array_keys( $wp_settings_fields[ $page ][ WP_DraftsForFriends_Settings::SECTION_SHARE ] ),
			'the registered fields are not the ones the screen has'
		);
	}

	public function test_the_section_constant_follows_the_naming_rule() {
		$this->assertSame( 'wp_draftsforfriends_share', WP_DraftsForFriends_Settings::SECTION_SHARE, "§4.2 spells a section '{{UNDER}}_<name>'" );
	}

	public function test_the_screen_renders_the_stored_values() {
		wp_set_current_user( $this->create_admin() );

		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 6,
				'measure' => 'd',
			)
		);

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'Drafts for Friends', $html, 'The settings screen is titled after the plugin.' );
		$this->assertStringContainsString( 'nav-tab-active', $html, 'the Settings tab is not marked active' );
		$this->assertStringContainsString( 'name="wp_draftsforfriends_options[expires]"', $html, 'the field does not post into the settings row' );
		$this->assertStringContainsString( 'value="6"', $html, 'the stored duration is not shown' );
		$this->assertStringContainsString( 'name="wp_draftsforfriends_options[measure]"', $html, 'The unit field posts into the settings row rather than a loose name.' );
		$this->assert_option_selected( 'd', $html, 'the stored unit is not preselected' );
	}

	public function test_the_screen_is_a_settings_api_form_rather_than_hand_written() {
		wp_set_current_user( $this->create_admin() );

		$html = $this->render_settings_page();

		$this->assertMatchesRegularExpression( '#action="[^"]*options\.php"#', $html, 'the form must post to options.php' );
		// settings_fields() emits single-quoted attributes, so the pairing worth
		// matching is the field name and the group value, not a double-quoted
		// spelling core never produces.
		$this->assertMatchesRegularExpression(
			'/name=[\'"]option_page[\'"]\s+value=[\'"]' . preg_quote( WP_DraftsForFriends_Settings::GROUP, '/' ) . '[\'"]/',
			$html,
			'settings_fields() was not called'
		);

		// §4.2: do_settings_sections() emits the form table, so the plugin must
		// not. One <table class="form-table"> is core's; a second would be ours.
		$this->assertSame( 1, preg_match_all( '/class="form-table"/', $html ), 'the form table is hand-written rather than emitted by do_settings_sections()' );

		$this->assertSame( 1, preg_match_all( '/<h1[ >]/', $html ), '§4.4 allows exactly one h1 per screen' );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z][^>]* style=/i', $html, '§4.4 forbids inline style attributes' );
	}

	public function test_every_control_on_the_settings_screen_is_labelled() {
		wp_set_current_user( $this->create_admin() );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( '<label for="wp_draftsforfriends_expires">', $html, 'label_for did not wire up the duration input' );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="wp_draftsforfriends_measure">', $html, 'the unit dropdown is unlabelled' );
	}

	public function test_the_tab_carries_the_active_tab_through_the_save() {
		wp_set_current_user( $this->create_admin() );

		$html = $this->render_settings_page();

		// options.php sends the browser to _wp_http_referer, and settings_fields()
		// emits one pointing at wherever the request came from. The tab's own
		// field has to come after it -- PHP keeps the last of a repeated name --
		// or a save lands on the first tab with the "Settings saved." message on
		// a screen the user was not looking at.
		preg_match_all( '/name="_wp_http_referer"\s+value="([^"]*)"/', $html, $matches );

		$this->assertNotEmpty( $matches[1], 'the settings form carries no referer field' );
		$this->assertStringContainsString(
			'tab=settings',
			end( $matches[1] ),
			'the save does not come back to the Settings tab'
		);
	}

	public function test_the_screen_refuses_a_user_without_manage_options() {
		wp_set_current_user( $this->author_id );

		$this->expectException( WPDieException::class );

		$this->render_settings_page();
	}

	public function test_options_php_enforces_the_same_capability_the_tab_renders_behind() {
		WP_DraftsForFriends_Settings::init();

		add_filter(
			'wp_draftsforfriends_capability',
			static function ( $capability, $context ) {
				return 'settings' === $context ? 'edit_theme_options' : $capability;
			},
			10,
			2
		);

		// Core's own default for a registered group is manage_options. A plugin
		// that offers a capability filter and does not answer this one hands out a
		// screen that renders and will not save.
		$this->assertSame(
			'edit_theme_options',
			apply_filters( 'option_page_capability_' . WP_DraftsForFriends_Settings::GROUP, 'manage_options' ),
			'options.php would enforce a capability the plugin no longer uses'
		);
	}

	public function test_only_one_tab_owns_settings_so_a_save_cannot_wipe_another() {
		global $wp_settings_sections, $wp_settings_fields;

		WP_DraftsForFriends_Settings::register();

		/*
		 * §4.2.1's data-destroying trap: register_setting()'s sanitize_callback is
		 * handed only the fields the submitting form posted, so with two
		 * settings-bearing tabs a naive sanitiser wipes whatever the other tab
		 * owns. This plugin is safe from it by construction rather than by
		 * merging -- the Shared Drafts tab is a list table that posts to itself
		 * and owns no key in the row -- and this is the assertion that keeps that
		 * true. When it fails, WP_DraftsForFriends_Options::sanitize() has to
		 * start merging the submitted subset over the stored value.
		 */
		$pages = array_unique(
			array_merge(
				array_keys( (array) $wp_settings_sections ),
				array_keys( (array) $wp_settings_fields )
			)
		);

		$ours = array_values(
			array_filter(
				$pages,
				static function ( $page ) {
					return 0 === strpos( (string) $page, WP_DraftsForFriends_Admin::PAGE );
				}
			)
		);

		$this->assertSame(
			array( WP_DraftsForFriends_Settings::PAGE ),
			$ours,
			'a second tab has gained settings fields; the sanitiser must now merge over the stored row'
		);
	}

	public function test_saving_the_settings_tab_leaves_the_shared_drafts_tab_untouched() {
		$user_id = $this->create_admin();

		$share = $this->make_share( $this->author_id, $this->draft_id );

		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wp_draftsforfriends_per_page', 5 );

		WP_DraftsForFriends_Settings::register();

		// The whole settings form, as the browser posts it.
		WP_DraftsForFriends_Options::update(
			WP_DraftsForFriends_Options::sanitize(
				array(
					'expires' => '9',
					'measure' => 'd',
				)
			)
		);

		$this->assertSame(
			array(
				'expires' => 9,
				'measure' => 'd',
			),
			get_option( WP_DraftsForFriends_Options::OPTION ),
			'the save did not store both halves of the one setting'
		);

		// Everything the other tab owns lives outside this row and must survive it.
		$this->assertSame( 1, $this->total_shares(), 'saving the settings destroyed a share' );
		$this->assertNotEmpty( WP_DraftsForFriends_Shares::get( $share->id ), 'the shared draft is gone' );
		$this->assertSame( '5', (string) get_user_meta( $user_id, 'wp_draftsforfriends_per_page', true ), 'the per-page screen option was reset' );
	}

	public function test_the_capability_filter_is_consulted_with_the_settings_context() {
		$seen = array();

		add_filter(
			'wp_draftsforfriends_capability',
			static function ( $capability, $context ) use ( &$seen ) {
				$seen[] = $context;

				return $capability;
			},
			10,
			2
		);

		WP_DraftsForFriends_Settings::capability( 'settings' );

		$this->assertSame( array( 'settings' ), $seen, 'The filter is told which screen is asking, and told once.' );
	}

	public function test_the_plugins_screen_gains_a_settings_link_for_an_administrator() {
		wp_set_current_user( $this->create_admin() );

		$links = WP_DraftsForFriends_Settings::action_links( array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links, 'The Settings link is added to the link passed in, not instead of it.' );
		$this->assertStringContainsString( 'edit.php?page=' . WP_DraftsForFriends_Admin::PAGE, $links[0], 'the link does not point at the plugin page under Posts' );
		$this->assertStringContainsString( 'tab=settings', $links[0], 'the link does not open the Settings tab' );
	}

	public function test_the_plugins_screen_gains_nothing_for_an_author() {
		wp_set_current_user( $this->author_id );

		$existing = array( '<a href="#">Deactivate</a>' );

		$this->assertSame( $existing, WP_DraftsForFriends_Settings::action_links( $existing ), 'an author was offered a settings link they cannot use' );
	}

	public function test_a_round_trip_through_the_screen_stores_a_clean_row() {
		wp_set_current_user( $this->create_admin() );

		WP_DraftsForFriends_Settings::register();

		// What options.php does with the form: hand the posted array to the
		// registered sanitiser, then store the result.
		$posted = array(
			'expires' => '999999',
			'measure' => 'fortnights',
		);

		WP_DraftsForFriends_Options::update( WP_DraftsForFriends_Options::sanitize( $posted ) );

		$this->assertSame(
			array(
				'expires' => 9999,
				'measure' => 'h',
			),
			get_option( WP_DraftsForFriends_Options::OPTION ),
			'an out-of-range duration and an unknown unit were stored as posted'
		);
	}
}
