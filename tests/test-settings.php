<?php
/**
 * The settings screen.
 *
 * @package wp-draftsforfriends
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

	public function test_the_settings_page_has_its_own_slug() {
		$this->assertSame( 'wp-draftsforfriends-settings', WP_DraftsForFriends_Settings::PAGE );
		$this->assertNotSame( WP_DraftsForFriends_Admin::PAGE, WP_DraftsForFriends_Settings::PAGE );
	}

	public function test_the_settings_screen_takes_manage_options() {
		$this->assertSame( 'manage_options', WP_DraftsForFriends_Settings::CAPABILITY, '§2.7 keeps settings on manage_options' );
		$this->assertSame( 'publish_posts', WP_DraftsForFriends_Admin::CAPABILITY, 'the data screen keeps the plugin custom capability' );
	}

	public function test_registration_registers_the_setting_the_section_and_the_field() {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;

		WP_DraftsForFriends_Settings::register_settings();

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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 6,
				'measure' => 'd',
			)
		);

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'Drafts for Friends Settings', $html );
		$this->assertStringContainsString( 'name="wp_draftsforfriends_options[expires]"', $html, 'the field does not post into the settings row' );
		$this->assertStringContainsString( 'value="6"', $html, 'the stored duration is not shown' );
		$this->assertStringContainsString( 'name="wp_draftsforfriends_options[measure]"', $html );
		$this->assertStringContainsString( '<option value="d" selected=', $html, 'the stored unit is not preselected' );
	}

	public function test_the_screen_is_a_settings_api_form_rather_than_hand_written() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'action="options.php"', $html, 'the form must post to options.php' );
		$this->assertStringContainsString( 'name="option_page" value="' . WP_DraftsForFriends_Settings::GROUP . '"', $html, 'settings_fields() was not called' );

		// §4.2: do_settings_sections() emits the form table, so the plugin must
		// not. One <table class="form-table"> is core's; a second would be ours.
		$this->assertSame( 1, preg_match_all( '/class="form-table"/', $html ), 'the form table is hand-written rather than emitted by do_settings_sections()' );

		$this->assertSame( 1, preg_match_all( '/<h1[ >]/', $html ), '§4.4 allows exactly one h1 per screen' );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z][^>]* style=/i', $html, '§4.4 forbids inline style attributes' );
	}

	public function test_every_control_on_the_settings_screen_is_labelled() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( '<label for="wp_draftsforfriends_expires">', $html, 'label_for did not wire up the duration input' );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="wp_draftsforfriends_measure">', $html, 'the unit dropdown is unlabelled' );
	}

	public function test_the_screen_refuses_a_user_without_manage_options() {
		wp_set_current_user( $this->author_id );

		$this->expectException( WPDieException::class );

		$this->render_settings_page();
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

		$this->assertSame( array( 'settings' ), $seen );
	}

	public function test_the_plugins_screen_gains_a_settings_link_for_an_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$links = WP_DraftsForFriends_Settings::action_links( array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'page=' . WP_DraftsForFriends_Settings::PAGE, $links[0] );
	}

	public function test_the_plugins_screen_gains_nothing_for_an_author() {
		wp_set_current_user( $this->author_id );

		$existing = array( '<a href="#">Deactivate</a>' );

		$this->assertSame( $existing, WP_DraftsForFriends_Settings::action_links( $existing ), 'an author was offered a settings link they cannot use' );
	}

	public function test_a_round_trip_through_the_screen_stores_a_clean_row() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		WP_DraftsForFriends_Settings::register_settings();

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
