<?php
/**
 * The two option rows.
 *
 * @package wp-draftsforfriends
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
		$this->assertSame( 'wp_draftsforfriends_options', WP_DraftsForFriends_Options::OPTION );
		$this->assertSame( 'wp_draftsforfriends_version', WP_DraftsForFriends_Options::VERSION );
	}

	public function test_get_merges_the_defaults_over_a_partial_row() {
		update_option( WP_DraftsForFriends_Options::OPTION, array( 'expires' => 9 ) );

		$options = WP_DraftsForFriends_Options::get();

		$this->assertSame( 9, $options['expires'], 'the stored value was not read' );
		$this->assertSame( 'h', $options['measure'], 'a key absent from the row did not fall back to its default' );
	}

	public function test_get_survives_a_row_that_is_not_an_array() {
		update_option( WP_DraftsForFriends_Options::OPTION, 'nonsense' );

		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::get() );
	}

	public function test_get_returns_one_key_or_null_for_an_unknown_one() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 4,
				'measure' => 'd',
			)
		);

		$this->assertSame( 4, WP_DraftsForFriends_Options::get( 'expires' ) );
		$this->assertSame( 'd', WP_DraftsForFriends_Options::get( 'measure' ) );
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
			WP_DraftsForFriends_Options::get_versions()
		);
	}

	public function test_the_sanitiser_holds_the_duration_between_one_and_9999() {
		$this->assertSame( 1, WP_DraftsForFriends_Options::sanitize( array( 'expires' => 0 ) )['expires'], 'a share that has already expired is not a setting' );
		$this->assertSame( 1, WP_DraftsForFriends_Options::sanitize( array( 'expires' => -5 ) )['expires'] );
		$this->assertSame( 9999, WP_DraftsForFriends_Options::sanitize( array( 'expires' => 100000 ) )['expires'] );
		$this->assertSame( 7, WP_DraftsForFriends_Options::sanitize( array( 'expires' => '7' ) )['expires'], 'a posted value arrives as a string' );
	}

	public function test_the_sanitiser_rejects_a_unit_the_plugin_does_not_have() {
		$this->assertSame( 'h', WP_DraftsForFriends_Options::sanitize( array( 'measure' => 'fortnights' ) )['measure'] );
		$this->assertSame( 'h', WP_DraftsForFriends_Options::sanitize( array( 'measure' => '' ) )['measure'] );

		foreach ( array_keys( WP_DraftsForFriends_Shares::UNITS ) as $unit ) {
			$this->assertSame( $unit, WP_DraftsForFriends_Options::sanitize( array( 'measure' => $unit ) )['measure'], "the '{$unit}' unit was rejected" );
		}
	}

	public function test_the_sanitiser_answers_a_non_array_with_the_defaults() {
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( 'nonsense' ) );
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( null ) );
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
		$this->assertSame( WP_DraftsForFriends_Options::get_defaults(), WP_DraftsForFriends_Options::sanitize( array() ) );
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
		$this->assertSame( 'h', WP_DraftsForFriends_Options::get( 'measure' ) );
	}
}
