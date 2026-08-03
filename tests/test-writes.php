<?php
/**
 * The shared drafts screen's write paths.
 *
 * @package WP-DraftsForFriends
 */

/**
 * The add form and the two bulk actions, driven as real form posts.
 *
 * Until 2.0.0 all three writes went through an AJAX endpoint, so none of them
 * worked without JavaScript and §4.3's bulk actions had nowhere to live. They
 * are ordinary posts to the screen now, and this is where that is held true.
 */
class WP_DraftsForFriends_Writes_Test extends WP_DraftsForFriends_TestCase {

	public function test_the_add_form_creates_a_share() {
		wp_set_current_user( $this->author_id );

		$before = $this->total_shares();
		$html   = $this->submit_add_form();

		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
		$this->assertSame( $before + 1, $this->total_shares(), 'the add form did not create a share' );
		$this->assertStringContainsString( 'notice-success', $html, 'no success notice was shown' );
	}

	public function test_a_created_share_uses_the_posted_duration() {
		wp_set_current_user( $this->author_id );

		$this->submit_add_form(
			array(
				'expires' => 3,
				'measure' => 'd',
			)
		);

		$shares = WP_DraftsForFriends_Shares::query( 'id', 'desc', 0, 1 );

		$this->assertCount( 1, $shares, 'the share was not created' );

		$left = strtotime( $shares[0]->date_expired . ' UTC' ) - time();

		$this->assertEqualsWithDelta( 3 * DAY_IN_SECONDS, $left, 10, 'the share does not last for the posted duration' );
	}

	public function test_the_add_form_reports_a_refusal_without_writing() {
		wp_set_current_user( $this->author_id );

		$before = $this->total_shares();

		// The editor's draft: a valid nonce, but not a post this author may edit.
		$html = $this->submit_add_form( array( 'post_id' => $this->editor_draft_id ) );

		$this->assertSame( $before, $this->total_shares(), 'a share was created for a post the author cannot edit' );
		$this->assertStringContainsString( 'notice-error', $html, 'the refusal was not reported' );
	}

	public function test_the_add_form_reports_no_post_chosen() {
		wp_set_current_user( $this->author_id );

		$before = $this->total_shares();
		$html   = $this->submit_add_form( array( 'post_id' => '' ) );

		$this->assertSame( $before, $this->total_shares(), 'an empty post id created a share' );
		$this->assertStringContainsString( 'notice-error', $html, 'the refusal was not reported' );
	}

	public function test_the_add_form_refuses_a_bad_nonce() {
		wp_set_current_user( $this->author_id );

		$before = $this->total_shares();

		// check_admin_referer() answers a bad nonce with wp_die(), which the test
		// framework turns into an exception. Dying is the correct behaviour.
		$this->expectException( WPDieException::class );

		try {
			$this->submit_add_form( array( '_wpnonce' => 'garbage' ) );
		} finally {
			$this->assertSame( $before, $this->total_shares(), 'a request with a bad nonce wrote a row anyway' );
		}
	}

	public function test_the_add_form_refuses_a_missing_nonce() {
		wp_set_current_user( $this->author_id );

		$this->expectException( WPDieException::class );

		$this->render_admin_page(
			array(),
			array(
				'draftsforfriends_add' => 'Share Draft',
				'post_id'              => $this->draft_id,
				'expires'              => 2,
				'measure'              => 'h',
			)
		);
	}

	public function test_the_screen_refuses_a_user_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		$this->render_admin_page();
	}

	public function test_the_screen_refuses_a_logged_out_visitor() {
		wp_set_current_user( 0 );

		$this->expectException( WPDieException::class );

		$this->render_admin_page();
	}

	public function test_the_revoke_bulk_action_deletes_every_selected_share() {
		$first  = $this->make_share( $this->author_id, $this->draft_id );
		$second = $this->make_share( $this->author_id, $this->draft_id );

		$before = $this->total_shares();

		$html = $this->submit_bulk_action( 'revoke', array( $first->id, $second->id ) );

		$this->assertSame( array(), $this->admin_page_notices, 'the screen raised PHP diagnostics' );
		$this->assertSame( $before - 2, $this->total_shares(), 'the bulk revoke did not delete both shares' );
		$this->assertStringContainsString( 'notice-success', $html, 'no success notice was shown' );
		$this->assertStringContainsString( '2 shared drafts revoked.', $html, 'the notice does not report how many were revoked' );
	}

	public function test_the_extend_bulk_action_uses_the_duration_in_the_tablenav() {
		$share = $this->make_share( $this->author_id, $this->draft_id, 1, 'h' );
		$was   = strtotime( $share->date_expired . ' UTC' );

		$html = $this->submit_bulk_action(
			'extend',
			array( $share->id ),
			array(
				'extend_expires' => 2,
				'extend_measure' => 'h',
			)
		);

		$this->assertStringContainsString( '1 shared draft extended.', $html, 'the notice does not report the extension' );

		$now = WP_DraftsForFriends_Shares::get( (int) $share->id );

		$this->assertEqualsWithDelta(
			2 * HOUR_IN_SECONDS,
			strtotime( $now->date_expired . ' UTC' ) - $was,
			10,
			'the share was not extended by the duration in the tablenav'
		);
	}

	public function test_a_bulk_action_with_nothing_ticked_says_so() {
		$this->make_share( $this->author_id, $this->draft_id );

		$before = $this->total_shares();
		$html   = $this->submit_bulk_action( 'revoke', array() );

		$this->assertSame( $before, $this->total_shares(), 'an empty selection deleted something' );
		$this->assertStringContainsString( 'notice-warning', $html, 'an empty selection should be a warning, not a silent no-op' );
	}

	public function test_a_bulk_action_refuses_a_bad_nonce() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$before = $this->total_shares();

		$this->expectException( WPDieException::class );

		try {
			$this->submit_bulk_action( 'revoke', array( $share->id ), array( '_wpnonce' => 'garbage' ) );
		} finally {
			$this->assertSame( $before, $this->total_shares(), 'a bulk action with a bad nonce deleted a row anyway' );
		}
	}

	public function test_an_unknown_bulk_action_does_nothing() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$before = $this->total_shares();

		// No nonce either: an unrecognised action must be dropped before the nonce
		// check is even reached, or every crawled URL becomes a wp_die().
		$html = $this->render_admin_page(
			array(),
			array(
				'action' => 'incinerate',
				'shares' => array( (string) $share->id ),
			)
		);

		$this->assertSame( $before, $this->total_shares(), 'an unknown bulk action changed something' );
		$this->assertStringNotContainsString( 'notice-error', $html, 'an unknown bulk action should be ignored, not reported' );
	}

	public function test_an_author_cannot_revoke_another_users_share() {
		$editor_share = $this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( $this->author_id );

		$before = $this->total_shares();
		$html   = $this->submit_bulk_action( 'revoke', array( $editor_share->id ) );

		$this->assertSame( $before, $this->total_shares(), "the author revoked the editor's share" );
		$this->assertStringContainsString( 'notice-error', $html, 'the refusal was not reported' );
	}

	public function test_an_author_cannot_extend_another_users_share() {
		$editor_share = $this->make_share( $this->editor_id, $this->editor_draft_id, 1, 'h' );
		$was          = $editor_share->date_expired;

		wp_set_current_user( $this->author_id );

		$this->submit_bulk_action(
			'extend',
			array( $editor_share->id ),
			array(
				'extend_expires' => 5,
				'extend_measure' => 'd',
			)
		);

		wp_set_current_user( $this->editor_id );

		$now = WP_DraftsForFriends_Shares::get( (int) $editor_share->id );

		$this->assertSame( $was, $now->date_expired, "the author extended the editor's share" );
	}

	public function test_a_batch_reports_one_notice_per_distinct_problem() {
		$mine   = $this->make_share( $this->author_id, $this->draft_id );
		$theirs = $this->make_share( $this->editor_id, $this->editor_draft_id );
		$other  = $this->make_share( $this->editor_id, $this->editor_draft_id );

		wp_set_current_user( $this->author_id );

		$html = $this->submit_bulk_action( 'revoke', array( $mine->id, $theirs->id, $other->id ) );

		$this->assertStringContainsString( '1 shared draft revoked.', $html, 'the one share the author owns was not revoked' );

		// Two refusals with the same wording collapse into one notice rather than
		// stacking a banner per share.
		$this->assertSame(
			1,
			substr_count( $html, 'There is no such shared draft!' ),
			'the same refusal was reported more than once'
		);
	}

	public function test_the_bulk_dropdowns_are_left_to_core() {
		// Checked against core rather than asserted of the plugin: WP_List_Table
		// renders the bottom dropdown as action2 but current_action() only ever
		// reads action, and wp-admin/js/common.js keeps the two in step in the
		// browser. A plugin that read action2 itself would behave differently from
		// every core list table, so this pins the reading to core's one method.
		$table = new WP_DraftsForFriends_List_Table();

		$_REQUEST['action']  = '-1';
		$_REQUEST['action2'] = 'revoke';

		$this->assertFalse( $table->current_action(), 'the plugin reads a bulk action core would not' );

		$_REQUEST['action'] = 'revoke';

		$this->assertSame( 'revoke', $table->current_action(), 'the top bulk dropdown is not read' );
	}
}
