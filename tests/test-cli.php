<?php
/**
 * Tests for the `wp draftsforfriends` WP-CLI command.
 *
 * @package WP-DraftsForFriends
 */

/**
 * The command mints, prolongs and destroys credentials with no browser, no
 * nonce and no screen in front of it, so every subcommand is pinned here.
 *
 * The WP_CLI facade these tests read is the stand-in from helper-wp-cli.php: it
 * records what the command reported instead of printing it, and its error()
 * throws, because the real one exits and every line after a call to it is
 * unreachable.
 *
 * Every test sets a current user before it does anything. That is not
 * boilerplate: WP-CLI runs as nobody unless told otherwise, and the whole of
 * this command is scoped by who is asking.
 */
class WP_DraftsForFriends_CLI_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * Clears everything the stand-in recorded for the previous test.
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$successes     = array();
		WP_CLI::$warnings      = array();
		WP_CLI::$logs          = array();
		WP_CLI::$confirmations = array();
		WP_CLI::$commands      = array();
		WP_CLI::$items         = array();
	}

	/**
	 * Runs one subcommand the way WP-CLI would.
	 *
	 * @param string $subcommand Method to call.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Associative arguments.
	 * @return void
	 */
	protected function run_command( $subcommand, $args = array(), $assoc_args = array() ) {
		$command = new WP_DraftsForFriends_Command();
		$command->$subcommand( $args, $assoc_args );
	}

	/**
	 * The rows the last format_items() call was given.
	 *
	 * @return array
	 */
	protected function listed_rows() {
		$this->assertNotEmpty( WP_CLI::$items, 'The command formatted a table.' );

		$last = end( WP_CLI::$items );

		return $last['items'];
	}

	/**
	 * The last line the command printed, which is where a link goes.
	 *
	 * @return string
	 */
	protected function printed_link() {
		$this->assertNotEmpty( WP_CLI::$logs, 'The command printed a line of its own for the link.' );

		return (string) end( WP_CLI::$logs );
	}

	/**
	 * When a share stops working, as a timestamp.
	 *
	 * The stored column is GMT, so it is read as GMT rather than through a
	 * helper that would apply the site's offset to a string that does not carry
	 * one. The rest of this suite reads it the same way.
	 *
	 * @param int $share_id Share ID.
	 * @return int
	 */
	protected function expiry_of( $share_id ) {
		$share = WP_DraftsForFriends_Shares::get( $share_id );

		$this->assertNotEmpty( $share, 'The share is still there to read an expiry off.' );

		return (int) strtotime( $share->date_expired . ' UTC' );
	}

	/**
	 * Move a share's expiry into the past, as time would.
	 *
	 * @param int $share_id Share ID.
	 * @return void
	 */
	protected function expire_share( $share_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->draftsforfriends,
			array( 'date_expired' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => (int) $share_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame( 1, $updated, 'The fixture aged the share out.' );
	}

	/**
	 * Assert a printed link opens the post it was issued for.
	 *
	 * Two halves, and the first is the one this plugin has actually broken in
	 * the field. The link must ask for the post by bare id: `redirect_canonical()`
	 * rewrote `?p=<id>` into the pretty permalink, and the query that arrives
	 * there looks the post up by slug among the *public* statuses -- so an
	 * unpublished post is not in the result set at all and the friend gets a
	 * 404. Every site with a permalink structure was affected, which is very
	 * nearly all of them. These tests turn pretty permalinks on for that reason.
	 *
	 * @param string $url     The link the command printed.
	 * @param int    $post_id The post it should open.
	 * @param string $message What is being asserted, for the failure output.
	 * @return void
	 */
	protected function assert_link_opens_the_post( $url, $post_id, $message ) {
		$query = array();

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame(
			(string) $post_id,
			isset( $query['p'] ) ? (string) $query['p'] : '',
			$message . ': the link asks for the post by bare id rather than by permalink.'
		);
		$this->assertTrue(
			WP_DraftsForFriends_Shares::hash_unlocks(
				$post_id,
				isset( $query['draftsforfriends'] ) ? (string) $query['draftsforfriends'] : ''
			),
			$message . ': and the hash it carries currently unlocks that post.'
		);
	}

	// --- registration ----------------------------------------------------

	/**
	 * The command registers under the bare noun, not the plugin slug.
	 *
	 * @return void
	 */
	public function test_the_command_registers_as_draftsforfriends() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		WP_DraftsForFriends::register_command();

		$this->assertArrayHasKey( 'draftsforfriends', WP_CLI::$commands, 'The command is registered as `wp draftsforfriends`.' );
		$this->assertSame( 'WP_DraftsForFriends_Command', WP_CLI::$commands['draftsforfriends'], 'WP_DraftsForFriends_Command is what handles it.' );
		$this->assertArrayNotHasKey( 'wp-draftsforfriends', WP_CLI::$commands, 'The plugin slug is not also claimed as a command.' );
	}

	/**
	 * The command offers what the screen offers, and nothing beyond it.
	 *
	 * The screen lists shares, shares a draft, and extends or revokes what is
	 * ticked. There is no fifth thing an administrator can do through the
	 * browser, so there is no fifth subcommand -- in particular the Settings tab
	 * is absent, being one option row that `wp option` already reads and writes.
	 *
	 * @return void
	 */
	public function test_the_command_exposes_only_what_the_screen_does() {
		$methods = get_class_methods( 'WP_DraftsForFriends_Command' );

		sort( $methods );

		$this->assertSame(
			array( 'create', 'extend', 'list_', 'revoke' ),
			$methods,
			'The command offers exactly the four operations the screen does.'
		);
	}

	// --- list ------------------------------------------------------------

	/**
	 * Listing reports each share with the link a friend would be given.
	 *
	 * The link is the credential and printing it is the point: the plugin exists
	 * to hand that string to somebody.
	 *
	 * @return void
	 */
	public function test_list_reports_each_share_with_its_link() {
		$share = $this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'list_' );

		$rows = $this->listed_rows();

		$this->assertCount( 1, $rows, 'The one share is listed.' );
		$this->assertSame( (int) $share->id, $rows[0]['id'], 'The row carries the share id the other subcommands take.' );
		$this->assertSame( $this->editor_draft_id, $rows[0]['post_id'], 'And the post it shares.' );
		$this->assertSame( 'Editor Draft', $rows[0]['post'], 'And that post\'s title.' );
		$this->assertSame(
			WP_DraftsForFriends_Shares::url( $share ),
			$rows[0]['url'],
			'And the link itself, which is the whole reason to run this.'
		);
	}

	/**
	 * Listing is scoped exactly as the screen is.
	 *
	 * An author sees their own shares and nobody else's; anyone holding
	 * edit_others_posts sees the lot. The command must not widen that, because a
	 * share URL in a listing is a working credential for an unpublished post.
	 *
	 * @return void
	 */
	public function test_list_shows_an_author_only_their_own_shares() {
		$mine = $this->make_share( $this->author_id, $this->draft_id );
		$this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( $this->author_id );

		$this->run_command( 'list_' );

		$rows = wp_list_pluck( $this->listed_rows(), 'id' );

		$this->assertSame( array( (int) $mine->id ), $rows, 'The author sees their own share and not the editor\'s.' );
	}

	/**
	 * An editor sees every share on the site, as they do on the screen.
	 *
	 * @return void
	 */
	public function test_list_shows_an_editor_every_share() {
		$authors = $this->make_share( $this->author_id, $this->draft_id );
		$editors = $this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( $this->editor_id );

		$this->run_command( 'list_' );

		$rows = wp_list_pluck( $this->listed_rows(), 'id' );

		$this->assertContains( (int) $authors->id, $rows, 'The editor sees the author\'s share.' );
		$this->assertContains( (int) $editors->id, $rows, 'And their own.' );
	}

	/**
	 * Nothing shared is reported as a success, not an error.
	 *
	 * @return void
	 */
	public function test_list_with_no_shares_is_not_an_error() {
		wp_set_current_user( $this->editor_id );

		$this->run_command( 'list_' );

		$this->assertNotEmpty( WP_CLI::$successes, 'Finding nothing is reported on the success channel.' );
		$this->assertEmpty( WP_CLI::$items, 'No table is printed when there is nothing to put in it.' );
	}

	/**
	 * The listing is not cut off at the page size the screen uses.
	 *
	 * The list table pages at twenty and the query defaults to the same, which is
	 * the wrong answer at a shell prompt: a list that stopped at the twentieth
	 * share would read as "you have twenty shares" and a script piping it into
	 * revoke would leave the rest live.
	 *
	 * @return void
	 */
	public function test_list_is_not_truncated_at_the_screens_page_size() {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->make_share( $this->editor_id, $this->editor_draft_id );
		}

		wp_set_current_user( $this->editor_id );

		$this->run_command( 'list_' );

		$this->assertCount( 21, $this->listed_rows(), 'Every share is listed, not just the first page of them.' );
	}

	/**
	 * The requested format reaches the formatter.
	 *
	 * @return void
	 */
	public function test_list_passes_the_requested_format_through() {
		$this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'list_', array(), array( 'format' => 'json' ) );

		$last = end( WP_CLI::$items );

		$this->assertSame( 'json', $last['format'], 'The formatter is asked for the format the caller named.' );
	}

	/**
	 * Run as nobody, the command reports nothing rather than everything.
	 *
	 * WP-CLI has no current user unless --user is passed, and the failure worth
	 * pinning is the one where a scope check answers "no user, so no filtering"
	 * and prints every share on the site to whoever ran it.
	 *
	 * @return void
	 */
	public function test_list_run_as_nobody_reports_no_shares() {
		$this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( 0 );

		$this->run_command( 'list_' );

		$this->assertEmpty( WP_CLI::$items, 'A caller with no user is shown no shares at all.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'And is told so rather than left guessing.' );
	}

	// --- create ----------------------------------------------------------

	/**
	 * Sharing a draft prints a link that opens it.
	 *
	 * @return void
	 */
	public function test_create_shares_a_draft_and_prints_a_working_link() {
		$this->set_permalink_structure( '/%postname%/' );

		wp_set_current_user( $this->author_id );

		$this->run_command( 'create', array( $this->draft_id ) );

		$this->assert_link_opens_the_post( $this->printed_link(), $this->draft_id, 'A shared draft' );
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says what it did.' );
		$this->assertSame( 1, $this->total_shares(), 'Exactly one share was created.' );
	}

	/**
	 * A scheduled post shares like any other unpublished one.
	 *
	 * This is half of where the plugin has actually broken in the field: a
	 * scheduled post is not a public status, so a link rewritten to the
	 * permalink finds nothing at all.
	 *
	 * @return void
	 */
	public function test_create_shares_a_scheduled_post() {
		$this->set_permalink_structure( '/%postname%/' );

		$scheduled = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_author' => $this->author_id,
				'post_title'  => 'Scheduled Post',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'future', get_post_status( $scheduled ), 'The fixture really is scheduled rather than published.' );

		wp_set_current_user( $this->author_id );

		$this->run_command( 'create', array( $scheduled ) );

		$this->assert_link_opens_the_post( $this->printed_link(), $scheduled, 'A shared scheduled post' );
	}

	/**
	 * A private post shares too, and its link is the same shape.
	 *
	 * The other half of the same field bug. Sharing one takes edit_private_posts,
	 * so this runs as the editor.
	 *
	 * @return void
	 */
	public function test_create_shares_a_private_post() {
		$this->set_permalink_structure( '/%postname%/' );

		$private = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $this->editor_id,
				'post_title'  => 'Private Post',
			)
		);

		$this->assertSame( 'private', get_post_status( $private ), 'The fixture really is private.' );

		wp_set_current_user( $this->editor_id );

		$this->run_command( 'create', array( $private ) );

		$this->assert_link_opens_the_post( $this->printed_link(), $private, 'A shared private post' );
	}

	/**
	 * A published post is refused, because there is nothing to preview.
	 *
	 * @return void
	 */
	public function test_create_refuses_a_published_post() {
		$published = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		wp_set_current_user( $this->author_id );

		try {
			$this->run_command( 'create', array( $published ) );
			$this->fail( 'The command stops rather than sharing a post that is already public.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'published', $e->getMessage(), 'It says the post is published.' );
		}

		$this->assertSame( 0, $this->total_shares(), 'And no share was created.' );
	}

	/**
	 * An id that matches nothing stops the command.
	 *
	 * @return void
	 */
	public function test_create_refuses_an_unknown_post() {
		wp_set_current_user( $this->author_id );

		try {
			$this->run_command( 'create', array( 123456 ) );
			$this->fail( 'The command stops on a post id that matches nothing.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage(), 'It says why it stopped.' );
		}

		$this->assertSame( 0, $this->total_shares(), 'And nothing was written.' );
	}

	/**
	 * The command checks edit_post exactly as the screen does.
	 *
	 * @return void
	 */
	public function test_create_refuses_a_draft_the_user_may_not_edit() {
		wp_set_current_user( $this->author_id );

		try {
			$this->run_command( 'create', array( $this->editor_draft_id ) );
			$this->fail( 'An author cannot share somebody else\'s draft from the command line either.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage(), 'It refuses on permissions.' );
		}

		$this->assertSame( 0, $this->total_shares(), 'And no share was created.' );
	}

	/**
	 * Run as nobody, sharing is refused rather than performed as nobody.
	 *
	 * @return void
	 */
	public function test_create_run_as_nobody_shares_nothing() {
		wp_set_current_user( 0 );

		try {
			$this->run_command( 'create', array( $this->draft_id ) );
			$this->fail( 'A caller with no user cannot mint a share link.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage(), 'It refuses on permissions.' );
		}

		$this->assertSame( 0, $this->total_shares(), 'And no share was created.' );
	}

	/**
	 * With no duration flags the command uses the configured default.
	 *
	 * The same fallback the Share a Draft form makes, so a command with no flags
	 * does what pressing the button would have done.
	 *
	 * @return void
	 */
	public function test_create_falls_back_to_the_configured_duration() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 1,
				'measure' => 'd',
			)
		);

		wp_set_current_user( $this->author_id );

		$this->run_command( 'create', array( $this->draft_id ) );

		$share = WP_DraftsForFriends_Shares::query( 'date_created', 'desc', 0, 1 );

		$this->assertCount( 1, $share, 'The share was created.' );
		$this->assertEqualsWithDelta(
			time() + DAY_IN_SECONDS,
			(int) strtotime( $share[0]->date_expired . ' UTC' ),
			30,
			'It lasts the configured day rather than some duration of the command\'s own.'
		);
	}

	/**
	 * The duration flags override the configured default.
	 *
	 * @return void
	 */
	public function test_create_honours_the_duration_flags() {
		wp_set_current_user( $this->author_id );

		$this->run_command(
			'create',
			array( $this->draft_id ),
			array(
				'expires' => 3,
				'measure' => 'h',
			)
		);

		$share = WP_DraftsForFriends_Shares::query( 'date_created', 'desc', 0, 1 );

		$this->assertCount( 1, $share, 'The share was created.' );
		$this->assertEqualsWithDelta(
			time() + ( 3 * HOUR_IN_SECONDS ),
			(int) strtotime( $share[0]->date_expired . ' UTC' ),
			30,
			'It lasts the three hours that were asked for.'
		);
	}

	// --- extend ----------------------------------------------------------

	/**
	 * Extending pushes a live share's expiry further out.
	 *
	 * @return void
	 */
	public function test_extend_pushes_a_live_share_further_out() {
		$share = $this->make_share( $this->author_id, $this->draft_id, 1, 'h' );
		$was   = $this->expiry_of( $share->id );

		$this->run_command(
			'extend',
			array( $share->id ),
			array(
				'expires' => 2,
				'measure' => 'h',
			)
		);

		$this->assertSame(
			$was + ( 2 * HOUR_IN_SECONDS ),
			$this->expiry_of( $share->id ),
			'The two hours are added to what the share had left, not to now.'
		);
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says so.' );
	}

	/**
	 * Extending an expired share restarts it from now.
	 *
	 * Otherwise extending a share that ran out yesterday by an hour would leave
	 * it expired, and the command would report success for a link that still
	 * does not work.
	 *
	 * @return void
	 */
	public function test_extend_restarts_an_expired_share_from_now() {
		$share = $this->make_share( $this->author_id, $this->draft_id, 1, 'h' );

		$this->expire_share( $share->id );

		$this->run_command(
			'extend',
			array( $share->id ),
			array(
				'expires' => 1,
				'measure' => 'h',
			)
		);

		$this->assertEqualsWithDelta(
			time() + HOUR_IN_SECONDS,
			$this->expiry_of( $share->id ),
			30,
			'An expired share gains a full hour from now rather than an hour ago.'
		);
	}

	/**
	 * Extending takes a list, exactly as the bulk action does.
	 *
	 * @return void
	 */
	public function test_extend_applies_to_every_id_it_is_given() {
		$first  = $this->make_share( $this->editor_id, $this->editor_draft_id, 1, 'h' );
		$second = $this->make_share( $this->editor_id, $this->editor_draft_id, 1, 'h' );

		$was_first  = $this->expiry_of( $first->id );
		$was_second = $this->expiry_of( $second->id );

		$this->run_command(
			'extend',
			array( $first->id, $second->id ),
			array(
				'expires' => 30,
				'measure' => 'm',
			)
		);

		$this->assertSame(
			$was_first + ( 30 * MINUTE_IN_SECONDS ),
			$this->expiry_of( $first->id ),
			'The first share was extended.'
		);
		$this->assertSame(
			$was_second + ( 30 * MINUTE_IN_SECONDS ),
			$this->expiry_of( $second->id ),
			'And so was the second.'
		);
	}

	/**
	 * A share that is not there, or is not the caller's, stops the command.
	 *
	 * @return void
	 */
	public function test_extend_stops_on_a_share_the_user_cannot_see() {
		$share = $this->make_share( $this->editor_id, $this->editor_draft_id, 1, 'h' );
		$was   = $this->expiry_of( $share->id );

		wp_set_current_user( $this->author_id );

		try {
			$this->run_command( 'extend', array( $share->id ) );
			$this->fail( 'An author cannot extend a share belonging to somebody else.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage(), 'It says why nothing was extended.' );
		}

		wp_set_current_user( $this->editor_id );

		$this->assertSame( $was, $this->expiry_of( $share->id ), 'And the share still expires when it did.' );
	}

	/**
	 * Extending nothing is a mistyped command, not a no-op reported as success.
	 *
	 * @return void
	 */
	public function test_extend_with_no_ids_is_an_error() {
		wp_set_current_user( $this->editor_id );

		try {
			$this->run_command( 'extend', array() );
			$this->fail( 'The command asks for at least one share id.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage(), 'It says what it wanted.' );
		}

		$this->assertEmpty( WP_CLI::$successes, 'And reports no success for having done nothing.' );
	}

	// --- revoke ----------------------------------------------------------

	/**
	 * Revoking with --yes removes the share, and the link stops working.
	 *
	 * @return void
	 */
	public function test_revoke_removes_the_share_and_kills_the_link() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$hash  = $share->hash;

		$this->run_command( 'revoke', array( $share->id ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->total_shares(), 'The row is gone.' );
		$this->assertFalse(
			WP_DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $hash ),
			'And the link a friend was given no longer opens the draft.'
		);
	}

	/**
	 * Without --yes the command asks, and a script that cannot answer revokes
	 * nothing.
	 *
	 * @return void
	 */
	public function test_revoke_without_yes_asks_first_and_revokes_nothing() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		try {
			$this->run_command( 'revoke', array( $share->id ) );
			$this->fail( 'The command stops at the confirmation instead of revoking.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNotEmpty( WP_CLI::$confirmations, 'It asked before doing anything.' );
		$this->assertSame( 1, $this->total_shares(), 'And the share is still there.' );
	}

	/**
	 * Revoking takes a list, exactly as the bulk action does.
	 *
	 * @return void
	 */
	public function test_revoke_applies_to_every_id_it_is_given() {
		$first  = $this->make_share( $this->editor_id, $this->editor_draft_id );
		$second = $this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'revoke', array( $first->id, $second->id ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->total_shares(), 'Both shares are gone.' );
	}

	/**
	 * Revoking one share leaves the others alone.
	 *
	 * @return void
	 */
	public function test_revoke_touches_only_the_share_it_was_given() {
		$doomed   = $this->make_share( $this->editor_id, $this->editor_draft_id );
		$survivor = $this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'revoke', array( $doomed->id ), array( 'yes' => true ) );

		$this->assertNull( WP_DraftsForFriends_Shares::get( $doomed->id ), 'The named share is gone.' );
		$this->assertNotNull( WP_DraftsForFriends_Shares::get( $survivor->id ), 'The other one is not.' );
	}

	/**
	 * Revoking fires the documented action once, with the share that went.
	 *
	 * @return void
	 */
	public function test_revoke_fires_the_documented_action() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$seen = array();
		add_action(
			'wp_draftsforfriends_share_revoked',
			function ( $revoked ) use ( &$seen ) {
				$seen[] = (int) $revoked->id;
			}
		);

		$this->run_command( 'revoke', array( $share->id ), array( 'yes' => true ) );

		$this->assertSame( array( (int) $share->id ), $seen, 'The action fires once, with the share that was revoked.' );
	}

	/**
	 * A share belonging to somebody else cannot be revoked.
	 *
	 * The permission is checked against the post the *share* points at, which is
	 * the arrangement a request pairing an id with a post of its own once got
	 * round on the screen.
	 *
	 * @return void
	 */
	public function test_revoke_stops_on_a_share_the_user_cannot_see() {
		$share = $this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( $this->author_id );

		try {
			$this->run_command( 'revoke', array( $share->id ), array( 'yes' => true ) );
			$this->fail( 'An author cannot revoke a share belonging to somebody else.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage(), 'It says why nothing was revoked.' );
		}

		$this->assertSame( 1, $this->total_shares(), 'And the share is still there.' );
	}

	/**
	 * A batch where one id is unknown revokes the rest and says what it skipped.
	 *
	 * @return void
	 */
	public function test_revoke_reports_the_ids_it_could_not_act_on() {
		$share = $this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'revoke', array( $share->id, 123456 ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->total_shares(), 'The share that did exist was revoked.' );
		$this->assertNotEmpty( WP_CLI::$warnings, 'And the one that did not was warned about.' );
		$this->assertNotEmpty( WP_CLI::$successes, 'The batch is still reported as having done something.' );
	}

	/**
	 * Revoking nothing is a mistyped command, not a no-op reported as success.
	 *
	 * @return void
	 */
	public function test_revoke_with_no_ids_is_an_error() {
		wp_set_current_user( $this->editor_id );

		try {
			$this->run_command( 'revoke', array(), array( 'yes' => true ) );
			$this->fail( 'The command asks for at least one share id.' );
		} catch ( RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage(), 'It says what it wanted.' );
		}

		$this->assertEmpty( WP_CLI::$confirmations, 'It does not even ask, having been given nothing to ask about.' );
	}

	/**
	 * The same id twice is one revoke, not one success and one failure.
	 *
	 * @return void
	 */
	public function test_revoke_deduplicates_the_ids_it_is_given() {
		$share = $this->make_share( $this->editor_id, $this->editor_draft_id );

		$this->run_command( 'revoke', array( $share->id, $share->id ), array( 'yes' => true ) );

		$this->assertSame( 0, $this->total_shares(), 'The share is gone.' );
		$this->assertEmpty( WP_CLI::$warnings, 'And nothing is warned about, because it was only asked for once.' );
	}
}
