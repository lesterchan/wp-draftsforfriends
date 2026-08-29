<?php
/**
 * The meta box's create endpoint.
 *
 * @package WP-DraftsForFriends
 */

/**
 * What admin-ajax.php will and will not create a share for.
 */
class WP_DraftsForFriends_Ajax_Test extends WP_DraftsForFriends_Ajax_TestCase {

	public function test_the_endpoint_is_registered_for_logged_in_users_only() {
		WP_DraftsForFriends_Metabox::init();

		$this->assertNotFalse(
			has_action( 'wp_ajax_' . WP_DraftsForFriends_Metabox::AJAX_ACTION, array( 'WP_DraftsForFriends_Metabox', 'ajax_create' ) ),
			'the create button posts to an action nothing answers'
		);
		$this->assertFalse(
			has_action( 'wp_ajax_nopriv_' . WP_DraftsForFriends_Metabox::AJAX_ACTION, array( 'WP_DraftsForFriends_Metabox', 'ajax_create' ) ),
			'a nopriv twin would let a logged-out visitor mint links to unpublished posts'
		);
	}

	public function test_it_creates_the_share_and_describes_it_back() {
		wp_set_current_user( $this->author_id );

		$response = $this->create_share();

		$this->assertTrue( $response['success'], 'the create was refused: ' . wp_json_encode( $response ) );
		$this->assertSame( 1, $this->total_shares(), 'The button creates exactly one share.' );

		$shares = WP_DraftsForFriends_Shares::for_post( $this->draft_id );

		$this->assertStringContainsString( 'draftsforfriends=' . $shares[0]->hash, $response['data']['url'], 'The link handed back is the link that was stored.' );
		$this->assertStringContainsString( 'Expires in', $response['data']['expires'], 'The box needs the countdown to print beside the link.' );

		// Three days out, give or take the seconds the test itself took.
		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, (int) mysql2date( 'G', $shares[0]->date_expired ), 60, 'The duration posted with the button is the duration the share got.' );
	}

	public function test_pressing_it_twice_gives_two_different_links() {
		wp_set_current_user( $this->author_id );

		$first  = $this->create_share();
		$second = $this->create_share();

		$this->assertSame( 2, $this->total_shares(), 'Each press is its own share, so each friend can have a link of their own.' );
		$this->assertNotSame( $first['data']['url'], $second['data']['url'], 'two presses handed out the same link' );
	}

	public function test_a_bad_nonce_creates_nothing() {
		wp_set_current_user( $this->author_id );

		$this->create_share( array( 'nonce' => 'not-a-nonce' ) );

		$this->assertSame( 0, $this->total_shares(), 'a request that could have come from anywhere must not write' );
	}

	public function test_a_user_who_may_not_share_creates_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = $this->create_share();

		$this->assertFalse( $response['success'], 'the capability gate on the screen must hold on the endpoint as well' );
		$this->assertSame( 0, $this->total_shares(), 'a contributor created a share through the endpoint' );
	}

	public function test_it_cannot_share_somebody_elses_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = $this->create_share();

		$this->assertFalse( $response['success'], 'create() checks edit_post, and the endpoint must not open a way around it' );
		$this->assertSame( 0, $this->total_shares(), 'a second author shared a draft that was not theirs' );
	}

	public function test_it_refuses_a_post_that_has_never_been_saved() {
		wp_set_current_user( $this->author_id );

		$auto = self::factory()->post->create(
			array(
				'post_status' => 'auto-draft',
				'post_author' => $this->author_id,
			)
		);

		$response = $this->create_share( array( 'post_id' => $auto ) );

		// The preview denies auto-draft, so this would be a link that 404s for
		// the friend. The checkbox is the control for this case, and it works
		// because the save it rides moves the post to draft first.
		$this->assertFalse( $response['success'], 'the endpoint minted a link the preview will not serve' );
		$this->assertSame( 0, $this->total_shares(), 'an auto-draft was shared' );
	}

	public function test_it_refuses_a_published_post() {
		wp_set_current_user( $this->author_id );

		wp_publish_post( $this->draft_id );

		$response = $this->create_share();

		$this->assertFalse( $response['success'], 'a published post is public already and its links do not work' );
		$this->assertSame( 0, $this->total_shares(), 'a published post was shared' );
	}
}
