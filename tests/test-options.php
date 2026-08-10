<?php
/**
 * The two option rows.
 *
 * @package WP-DraftsForFriends
 */

/**
 * WP_DraftsForFriends_Options: defaults, reads, writes and the sanitiser.
 */
class WP_DraftsForFriends_Options_Test extends WP_DraftsForFriends_TestCase {

	public function test_the_defaults_are_two_hours() {
		$this->assertSame(
			array(
				'expires' => 2,
				'measure' => 'h',
			),
			WP_DraftsForFriends_Options::get_defaults(),
			'the shipped default is not the two hours the form used to hardcode'
		);
	}

	public function test_the_row_names_are_the_ones_section_2_1_asks_for() {
		$this->assertSame( 'wp_draftsforfriends_options', WP_DraftsForFriends_Options::OPTION, 'The settings row is named as section 2.1 requires.' );
		$this->assertSame( 'wp_draftsforfriends_version', WP_DraftsForFriends_Options::VERSION, 'The version row is named as section 2.1 requires.' );
	}

	public function test_get_merges_the_defaults_over_a_partial_row() {
		update_option( WP_DraftsForFriends_Options::OPTION, array( 'expires' => 9 ) );

		$options = WP_DraftsForFriends_Options::get();

		$this->assertSame( 9, $options['expires'], 'the stored value was not read' );
		$this->assertSame( 'h', $options['measure'], 'a key absent from the row did not fall back to its default' );
	}

	public function test_get_survives_a_row_that_is_not_an_array() {
		update_option( WP_DraftsForFriends_Options::OPTION, 'nonsense' );

		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::get(), 'A row that is not an array falls back to the defaults rather than propagating.' );
	}

	public function test_get_returns_one_key_or_null_for_an_unknown_one() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 4,
				'measure' => 'd',
			)
		);

		$this->assertSame( 4, WP_DraftsForFriends_Options::get( 'expires' ), 'A known key reads back its stored value.' );
		$this->assertSame( 'd', WP_DraftsForFriends_Options::get( 'measure' ), 'Each known key reads back its own value, not the first.' );
		$this->assertNull( WP_DraftsForFriends_Options::get( 'no_such_key' ), 'An unknown key reads back null rather than raising a notice.' );
	}

	public function test_get_versions_reports_empty_strings_before_anything_is_written() {
		delete_option( WP_DraftsForFriends_Options::VERSION );

		$this->assertSame(
			array(
				'plugin' => '',
				'db'     => '',
			),
			WP_DraftsForFriends_Options::get_versions(),
			'an absent version row must read as two empty markers, not as a missing key'
		);
	}

	public function test_get_versions_survives_a_row_that_is_not_an_array() {
		update_option( WP_DraftsForFriends_Options::VERSION, '2.0.0' );

		$this->assertSame(
			array(
				'plugin' => '',
				'db'     => '',
			),
			WP_DraftsForFriends_Options::get_versions(),
			'A version row that is not an array falls back to empty markers rather than propagating.'
		);
	}

	public function test_the_sanitiser_holds_the_duration_between_one_and_9999() {
		$this->assertSame( 1, WP_DraftsForFriends_Options::sanitize( array( 'expires' => 0 ) )['expires'], 'a share that has already expired is not a setting' );
		$this->assertSame( 1, WP_DraftsForFriends_Options::sanitize( array( 'expires' => -5 ) )['expires'], 'A duration below one is held at one rather than stored negative.' );
		$this->assertSame( 9999, WP_DraftsForFriends_Options::sanitize( array( 'expires' => 100000 ) )['expires'], 'A duration above 9999 is held at 9999 rather than overflowing the field.' );
		$this->assertSame( 7, WP_DraftsForFriends_Options::sanitize( array( 'expires' => '7' ) )['expires'], 'a posted value arrives as a string' );
	}

	public function test_the_sanitiser_rejects_a_unit_the_plugin_does_not_have() {
		$this->assertSame( 'h', WP_DraftsForFriends_Options::sanitize( array( 'measure' => 'fortnights' ) )['measure'], 'A unit the plugin does not have falls back to hours.' );
		$this->assertSame( 'h', WP_DraftsForFriends_Options::sanitize( array( 'measure' => '' ) )['measure'], 'An empty unit falls back to hours too.' );

		foreach ( array_keys( WP_DraftsForFriends_Shares::UNITS ) as $unit ) {
			$this->assertSame( $unit, WP_DraftsForFriends_Options::sanitize( array( 'measure' => $unit ) )['measure'], "the '{$unit}' unit was rejected" );
		}
	}

	public function test_the_sanitiser_answers_a_non_array_with_the_defaults() {
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( 'nonsense' ), 'A non-array is answered with the defaults rather than stored.' );
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( null ), 'Null is answered with the defaults too.' );
	}

	public function test_the_sanitiser_reads_nothing_back_out_of_the_database() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 6,
				'measure' => 'd',
			)
		);

		// Posting nothing must clean to the defaults rather than to what is
		// stored: a sanitiser that reached for get_option() would return 6/d here,
		// and that reaching back is exactly what §2.1 exists to prevent.
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( array() ), 'The sanitiser is a pure function of what was posted; it reads no stored value.' );
	}

	public function test_update_replaces_the_row_rather_than_merging_into_it() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 3,
				'measure' => 'd',
			)
		);
		WP_DraftsForFriends_Options::update( array( 'expires' => 8 ) );

		$this->assertSame( array( 'expires' => 8 ), get_option( WP_DraftsForFriends_Options::OPTION ), 'update() must write what it was given' );

		// The defaults are merged on read, so the reader still answers for measure.
		$this->assertSame( 'h', WP_DraftsForFriends_Options::get( 'measure' ), 'An update replaces the row, so a key absent from the new value takes its default.' );
	}

	/**
	 * The write path creates the row even when the value equals the default.
	 *
	 * Pinned at the door rather than through the upgrade, so the guarantee belongs
	 * to update() rather than to whatever the upgrade happens to compute. With one
	 * setting in the row the upgrade's result equals the defaults on nearly every
	 * install, which is the case update_option() alone declines to write.
	 *
	 * @return void
	 */
	public function test_update_creates_the_row_when_the_value_equals_the_registered_default() {
		delete_option( WP_DraftsForFriends_Options::OPTION );

		WP_DraftsForFriends_Settings::register_settings();

		// The precondition the defect needs: a bare read of an absent row answers
		// with the defaults, so update_option() alone compares equal and declines
		// to write. Core's add_option() fallback sits below that comparison.
		$this->assertSame(
			WP_DraftsForFriends_Options::get_defaults(),
			get_option( WP_DraftsForFriends_Options::OPTION ),
			'the registered default is what an absent row reads back as'
		);

		$this->assertTrue( WP_DraftsForFriends_Options::update( WP_DraftsForFriends_Options::get_defaults() ), 'update() reports that it wrote' );
		$this->assertIsArray( get_option( WP_DraftsForFriends_Options::OPTION, false ), 'and the row is really there, read raw' );
	}

	/**
	 * The shipped defaults survive the sanitiser unchanged.
	 *
	 * The assertion whose absence would let a typo decide whether the test above
	 * means anything. A sanitiser that alters one character of the defaults makes
	 * the written value differ from them, so update_option() finds a difference
	 * and writes the row -- the equal-value case stops being exercised and the
	 * test above starts passing for a reason unrelated to the code.
	 *
	 * @return void
	 */
	public function test_the_shipped_defaults_survive_sanitisation_unchanged() {
		WP_DraftsForFriends_Settings::register_settings();

		$defaults = WP_DraftsForFriends_Options::get_defaults();

		$this->assertSame(
			$defaults,
			sanitize_option( WP_DraftsForFriends_Options::OPTION, $defaults ),
			'the registered sanitize callback leaves the defaults alone'
		);
	}
}
