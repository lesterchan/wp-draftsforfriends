<?php
/**
 * The WP-CLI command.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists, creates, extends and revokes shared drafts from the command line.
 *
 * Registered as `wp draftsforfriends` rather than `wp wp-draftsforfriends`. A
 * `wp-` prefix is a wordpress.org directory convention for keeping one plugin's
 * download page apart from another's, not a command-naming one, and after the
 * `wp` binary it only stutters; WP-CLI's own ecosystem names commands after the
 * brand -- `wp wc`, `wp yoast`, `wp jetpack`. WP-CLI gives a collision to
 * whichever plugin registered last and a bare noun is claimable by anyone; that
 * is the accepted trade for a word as distinctive as `draftsforfriends`.
 *
 * Every subcommand goes through WP_DraftsForFriends_Shares, which is the same
 * class the screen under Posts uses, so the four things offered here are the
 * four things that screen offers and nothing more: the list, the Share a Draft
 * form, and the Extend Selected and Revoke Selected bulk actions. The Settings
 * tab is deliberately absent -- it is one option row, which `wp option` already
 * reads and writes, and a second way to set a default duration is a second place
 * for it to be wrong.
 *
 * **These subcommands print share URLs, and a share URL is the credential.**
 * Anybody holding one can read the unpublished post it names until it expires,
 * with no account and no login. Printing it is nevertheless right, because
 * handing the link to somebody is the entire point of the plugin and the Link
 * column of the screen prints it too -- but it means the output of `list` and of
 * `create` is as sensitive as the drafts behind it, and a shell history, a CI
 * log or a captured stdout is somewhere the links now live.
 *
 * **Run these as a user.** Every read and write here is scoped exactly as the
 * screen scopes it: a share is visible to whoever created it and to anybody with
 * `edit_others_posts`, and creating, extending or revoking one checks
 * `edit_post` against the post the share points at. WP-CLI runs as nobody unless
 * told otherwise, and nobody may see or do any of that -- so pass WP-CLI's
 * global `--user` flag, and the command answers for that user's shares.
 */
class WP_DraftsForFriends_Command extends WP_CLI_Command {

	/**
	 * List the shared drafts this user can see.
	 *
	 * The `expires` and `created` columns are the stored GMT datetimes rather
	 * than the screen's rendering of them in the site's timezone, because a
	 * caller comparing two timestamps should not have to know which zone the
	 * site is in.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Every share this user can see.
	 *     $ wp draftsforfriends list --user=admin
	 *
	 *     # Just the links, to paste somewhere.
	 *     $ wp draftsforfriends list --user=admin --format=json
	 *
	 *     # Revoke the lot.
	 *     $ wp draftsforfriends list --user=admin --format=ids | xargs wp draftsforfriends revoke --user=admin --yes
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		// The screen pages at twenty and query() defaults to the same, which is
		// the wrong answer at a shell prompt: a list that silently stops at the
		// twentieth share reads as "you have twenty shares". count() is scoped
		// the same way query() is, so it is the exact number of rows this user
		// is about to be shown.
		$total  = WP_DraftsForFriends_Shares::count();
		$shares = WP_DraftsForFriends_Shares::query( 'date_created', 'desc', 0, max( 1, $total ) );

		if ( empty( $shares ) ) {
			// Nothing shared is an answer rather than a failure, and on a site
			// that has just revoked everything it is the expected one.
			WP_CLI::success( __( 'No shared drafts found.', 'wp-draftsforfriends' ) );

			return;
		}

		$rows = array();

		foreach ( $shares as $share ) {
			$rows[] = array(
				'id'      => (int) $share->id,
				'post_id' => (int) $share->post_id,
				'post'    => $share->post_title,
				'created' => $share->date_created,
				'expires' => $share->date_expired,
				'url'     => WP_DraftsForFriends_Shares::url( $share ),
			);
		}

		$this->print_rows( $format, $rows, array( 'id', 'post_id', 'post', 'created', 'expires', 'url' ) );
	}

	/**
	 * Share an unpublished post, and print the link.
	 *
	 * The link is printed on a line of its own before the success message, so a
	 * script can take the first line of output and send it to somebody. It is
	 * the credential: whoever holds it reads the post until the share expires.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The unpublished post to share.
	 *
	 * [--expires=<number>]
	 * : How long the link should last. Defaults to the configured duration.
	 *
	 * [--measure=<unit>]
	 * : What the duration is measured in. Defaults to the configured unit.
	 * ---
	 * options:
	 *   - s
	 *   - m
	 *   - h
	 *   - d
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Share a draft for the duration the Settings tab is set to.
	 *     $ wp draftsforfriends create 42 --user=admin
	 *
	 *     # Share it for a fortnight instead.
	 *     $ wp draftsforfriends create 42 --expires=14 --measure=d --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

		list( $expires, $measure ) = $this->duration( $assoc_args );

		$result = WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure );

		if ( empty( $result['shared'] ) ) {
			WP_CLI::error( $this->problem( $result ) );
		}

		$share = $result['shared'];

		WP_CLI::log( WP_DraftsForFriends_Shares::url( $share ) );

		WP_CLI::success(
			sprintf(
				/* translators: 1: post title, 2: expiry as a GMT datetime. */
				__( "Shared '%1\$s' until %2\$s GMT.", 'wp-draftsforfriends' ),
				$share->post_title,
				$share->date_expired
			)
		);
	}

	/**
	 * Push the expiry of one or more shares further out.
	 *
	 * The same arithmetic the Extend Selected bulk action uses: a share that has
	 * not expired yet gains the duration on top of what it had, and one that has
	 * already expired starts again from now, so extending it by an hour gives an
	 * hour rather than a moment in the past.
	 *
	 * Nothing here asks first. Extending loses nothing and can be undone by
	 * revoking, which is the opposite of the case for `revoke` below.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The shares to extend, by the id the list shows.
	 *
	 * [--expires=<number>]
	 * : How much time to add. Defaults to the configured duration.
	 *
	 * [--measure=<unit>]
	 * : What the duration is measured in. Defaults to the configured unit.
	 * ---
	 * options:
	 *   - s
	 *   - m
	 *   - h
	 *   - d
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Give share 3 another day.
	 *     $ wp draftsforfriends extend 3 --expires=1 --measure=d --user=admin
	 *
	 *     # Extend several at once, by the configured duration.
	 *     $ wp draftsforfriends extend 3 4 5 --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function extend( $args, $assoc_args ) {
		$ids = $this->share_ids( $args );

		list( $expires, $measure ) = $this->duration( $assoc_args );

		$results = array();

		foreach ( $ids as $id ) {
			$results[] = WP_DraftsForFriends_Shares::extend( $id, $expires, $measure );
		}

		$this->report_batch( 'extend', $results );
	}

	/**
	 * Revoke one or more shares, so their links stop working.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The shares to revoke, by the id the list shows.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp draftsforfriends revoke 3 --user=admin
	 *
	 *     # In a script, where there is nobody to answer the prompt.
	 *     $ wp draftsforfriends revoke 3 4 5 --yes --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		$ids = $this->share_ids( $args );

		// The link stops working the moment this runs and nothing here can put
		// it back -- there is no trash for a share and the hash is not recorded
		// anywhere else -- so this is the one subcommand that asks first.
		WP_CLI::confirm(
			sprintf(
				/* translators: %s: comma-separated list of share ids. */
				__( 'Revoke shared draft(s) %s? The links stop working immediately and cannot be restored.', 'wp-draftsforfriends' ),
				implode( ', ', $ids )
			),
			$assoc_args
		);

		$results = array();

		foreach ( $ids as $id ) {
			$results[] = WP_DraftsForFriends_Shares::delete( $id );
		}

		$this->report_batch( 'revoke', $results );
	}

	/**
	 * The share ids a batch subcommand was given, or stop the command.
	 *
	 * Deduplicated, because revoking the same id twice would otherwise report
	 * one success and one "there is no such shared draft" for a command that did
	 * exactly what was asked.
	 *
	 * @param array $args Positional arguments as they arrived on the command line.
	 * @return array Share ids.
	 */
	private function share_ids( $args ) {
		$ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $args ) ) ) );

		if ( empty( $ids ) ) {
			// An empty list would otherwise read as "do this to nothing" and
			// report success, which is the wrong answer to a mistyped command.
			WP_CLI::error( __( 'Name at least one shared draft id.', 'wp-draftsforfriends' ) );
		}

		return $ids;
	}

	/**
	 * The duration a subcommand should use, falling back to the stored setting.
	 *
	 * The same fallback the screen makes: the Share a Draft form and the Extend
	 * by control both start on the configured duration, so a command given
	 * neither flag does what pressing the button would have done.
	 *
	 * @param array $assoc_args Flags passed to the command.
	 * @return array{0:int,1:string} Duration, and the unit it is measured in.
	 */
	private function duration( $assoc_args ) {
		$expires = isset( $assoc_args['expires'] )
			? (int) $assoc_args['expires']
			: (int) WP_DraftsForFriends_Options::get( 'expires' );

		$measure = isset( $assoc_args['measure'] )
			? sanitize_key( $assoc_args['measure'] )
			: (string) WP_DraftsForFriends_Options::get( 'measure' );

		return array( $expires, $measure );
	}

	/**
	 * What went wrong, from a result that carries no share.
	 *
	 * @param array $result Result from WP_DraftsForFriends_Shares.
	 * @return string
	 */
	private function problem( array $result ) {
		return ! empty( $result['error'] )
			? $result['error']
			: __( 'Something went wrong.', 'wp-draftsforfriends' );
	}

	/**
	 * Turn a batch of results into one count and one warning per distinct problem.
	 *
	 * The reasoning the screen's report_batch() records holds here too: a line
	 * per share would put twenty identical "you do not have permission" warnings
	 * on the terminal, so the successes are counted and the problems
	 * deduplicated.
	 *
	 * A batch in which nothing at all succeeded exits non-zero, because a script
	 * that asked for something and got none of it has failed even though each
	 * individual refusal was orderly.
	 *
	 * @param string $action  Either extend or revoke.
	 * @param array  $results Results from WP_DraftsForFriends_Shares.
	 * @return void
	 */
	private function report_batch( $action, array $results ) {
		$done     = 0;
		$problems = array();

		foreach ( $results as $result ) {
			if ( ! empty( $result['success'] ) ) {
				++$done;

				continue;
			}

			$problems[] = $this->problem( $result );
		}

		foreach ( array_unique( $problems ) as $problem ) {
			WP_CLI::warning( $problem );
		}

		if ( 0 === $done ) {
			WP_CLI::error(
				'revoke' === $action
					? __( 'No shared draft was revoked.', 'wp-draftsforfriends' )
					: __( 'No shared draft was extended.', 'wp-draftsforfriends' )
			);
		}

		WP_CLI::success(
			'revoke' === $action
				? sprintf(
					/* translators: %s: number of shared drafts. */
					_n( '%s shared draft revoked.', '%s shared drafts revoked.', $done, 'wp-draftsforfriends' ),
					number_format_i18n( $done )
				)
				: sprintf(
					/* translators: %s: number of shared drafts. */
					_n( '%s shared draft extended.', '%s shared drafts extended.', $done, 'wp-draftsforfriends' ),
					number_format_i18n( $done )
				)
		);
	}

	/**
	 * Print rows, reducing to the first column when ids were asked for.
	 *
	 * WP_CLI\Utils\format_items() hands `ids` straight to implode(), so a row
	 * that is an associative array comes out as the word `Array` and a PHP
	 * warning -- which is what `--format=ids` did here until it was run against
	 * a real WP-CLI rather than reasoned about. Reducing to the first column is
	 * what makes the documented `| xargs` pipes work.
	 *
	 * @param string $format Requested output format.
	 * @param array  $rows   Rows to print.
	 * @param array  $fields Columns, in order. The first is the id.
	 * @return void
	 */
	private function print_rows( $format, array $rows, array $fields ) {
		if ( 'ids' === $format ) {
			WP_CLI\Utils\format_items( $format, wp_list_pluck( $rows, $fields[0] ), $fields );

			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
