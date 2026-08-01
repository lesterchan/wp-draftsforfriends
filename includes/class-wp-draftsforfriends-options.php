<?php
/**
 * Plugin options.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's two option rows.
 *
 * Settings live in one row and the version markers in another, so the settings
 * screen and the upgrade routine can never overwrite each other's work: the
 * screen writes wp_draftsforfriends_options, the upgrade writes
 * wp_draftsforfriends_version, and neither can corrupt the other.
 *
 * Every released version of this plugin stored no options at all -- the shared
 * drafts themselves have lived in their own table since 1.0.0, and the duration
 * offered by the add form was a hardcoded two hours. So both rows are new here,
 * and the only legacy row to fold in is the draftsforfriends_db_version written
 * by the unreleased 2.0.0 work. WP_DraftsForFriends_Install::migrate() does that.
 *
 * The shares are data rather than settings, which is why they are not in here:
 * they accumulate, they are not user-editable in the sense §2.1 means, and they
 * are far too many to autoload. They stay in the plugin's own table.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends_Options {

	/**
	 * The option row holding every setting, as a nested array.
	 *
	 * @var string
	 */
	const OPTION = 'wp_draftsforfriends_options';

	/**
	 * The option row holding the 'plugin' and 'db' version markers.
	 *
	 * Its own row rather than a key inside OPTION. A sanitize_callback is a
	 * function from what the form posted to what gets stored, and the settings
	 * form never posts a version marker -- so a marker living in that array has
	 * to be rescued from the stored value on every single save, which is a bug
	 * waiting to happen rather than a thing to be careful about.
	 *
	 * @var string
	 */
	const VERSION = 'wp_draftsforfriends_version';

	/**
	 * The default option values.
	 *
	 * Both describe the duration the add form and the Extend controls start on.
	 * Until 2.0.0 that was two hours, written into the markup in two places; a
	 * site that always shares drafts for a week had to retype it every time.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'expires' => 2,
			'measure' => 'h',
		);
	}

	/**
	 * Get the stored options, merged over the defaults.
	 *
	 * Merging on read is what lets an install upgraded from an older version
	 * pick up keys that did not exist when its row was written.
	 *
	 * @param string|null $key Optional single key to return.
	 * @return mixed The full option array, or one value, or null for an unknown key.
	 */
	public static function get( $key = null ) {
		$stored  = get_option( self::OPTION, array() );
		$options = wp_parse_args( is_array( $stored ) ? $stored : array(), self::get_defaults() );

		if ( null === $key ) {
			return $options;
		}

		return isset( $options[ $key ] ) ? $options[ $key ] : null;
	}

	/**
	 * Replace the stored options.
	 *
	 * @param array $options Option values.
	 * @return bool Whether the option row changed.
	 */
	public static function update( array $options ) {
		return update_option( self::OPTION, $options );
	}

	/**
	 * Get the version markers.
	 *
	 * @return array The 'plugin' and 'db' markers, each an empty string when unset.
	 */
	public static function get_versions() {
		$stored = get_option( self::VERSION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'plugin' => isset( $stored['plugin'] ) ? (string) $stored['plugin'] : '',
			'db'     => isset( $stored['db'] ) ? (string) $stored['db'] : '',
		);
	}

	/**
	 * Clean a full set of submitted options.
	 *
	 * Also register_setting()'s sanitize_callback, which receives the whole
	 * nested array in one go. It reads nothing back out of the database: the
	 * version markers live in their own row, so there is nothing here to rescue
	 * and nothing this can corrupt.
	 *
	 * §4.2.1 warns that a tabbed plugin's sanitiser must merge the submitted
	 * subset over the stored row, because a sanitiser handed only one tab's
	 * fields will otherwise wipe whatever the other tab owns. That trap needs two
	 * tabs that both save settings, and this plugin has one: the Shared Drafts
	 * tab is a list table posting to itself, it never reaches options.php, and it
	 * owns no key in this row. So the sanitiser stays honest -- posted input in,
	 * clean settings out, per §2.1 -- and the invariant that makes that safe is
	 * pinned by test_only_one_tab_owns_settings_so_a_save_cannot_wipe_another().
	 * Add a second settings-bearing tab and that test fails, which is the moment
	 * the merge has to be written.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::get_defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$measure = isset( $input['measure'] ) ? sanitize_key( $input['measure'] ) : '';

		/*
		 * A duration of zero would offer a share that has already expired, which
		 * reads as a broken screen rather than as a setting. The upper bound is
		 * the same one the field advertises; 9999 days is a little over 27 years,
		 * past which a "temporary" preview link is not temporary.
		 *
		 * A value that was not submitted at all is a different case from one that
		 * was submitted out of range, and falls back to the shipped default rather
		 * than to the bottom of the range: a form that posted no duration has said
		 * nothing about it, and answering "one second" is not what it said. This
		 * is how the measure key beside it already behaved, and how the non-array
		 * branch above behaves.
		 */
		return array(
			'expires' => isset( $input['expires'] ) ? max( 1, min( 9999, (int) $input['expires'] ) ) : $defaults['expires'],
			'measure' => isset( WP_DraftsForFriends_Shares::UNITS[ $measure ] ) ? $measure : $defaults['measure'],
		);
	}
}
