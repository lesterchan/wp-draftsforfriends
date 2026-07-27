<?php
/**
 * The AJAX endpoint behind the admin screen.
 *
 * @package WP-DraftsForFriends
 */

/**
 * The wp_ajax_draftsforfriends_admin endpoint.
 */
class Test_DraftsForFriends_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * An author, who may only touch their own posts.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * An editor, who has edit_others_posts.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * The author's draft.
	 *
	 * @var int
	 */
	private $draft_id;

	/**
	 * The editor's draft.
	 *
	 * @var int
	 */
	private $editor_draft_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
				// Carries HTML so the escaping assertions have something to bite on.
				'post_title'  => 'Draft <b>Title</b> & "quoted"',
			)
		);

		$this->editor_draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->editor_id,
			)
		);
	}

	/**
	 * Clean up request state.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Dispatch the endpoint and decode what it sent.
	 *
	 * @param array $post Request body.
	 * @return array Decoded response.
	 */
	private function dispatch( array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;

		try {
			$this->_handleAjax( 'draftsforfriends_admin' );
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
			// wp_send_json() ends the request, which the test framework turns into
			// one of these. Reaching here is the normal path, not a failure.
			unset( $e );
		}

		$decoded = json_decode( $this->_last_response, true );

		$this->assertIsArray( $decoded, 'the endpoint must answer with JSON, got: ' . $this->_last_response );

		return $decoded;
	}

	/**
	 * How many shares exist, unscoped.
	 *
	 * @return int
	 */
	private function total() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends}" );
	}

	/**
	 * Adding a share.
	 */
	public function test_add() {
		wp_set_current_user( $this->author_id );

		$before = $this->total();

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'add',
				'post_id'     => $this->draft_id,
				'expires'     => 3,
				'measure'     => 'h',
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-add' ),
			)
		);

		$this->assertArrayHasKey( 'success', $response );
		$this->assertSame( $before + 1, $this->total() );
		$this->assertNotEmpty( $response['html'], 'the row markup the script injects' );
		$this->assertNotEmpty( $response['countText'] );
	}

	/**
	 * The injected row must not carry an unescaped post title into the DOM.
	 */
	public function test_add_response_escapes_the_post_title() {
		wp_set_current_user( $this->author_id );

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'add',
				'post_id'     => $this->draft_id,
				'expires'     => 1,
				'measure'     => 'h',
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-add' ),
			)
		);

		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $response['html'] );
		$this->assertStringContainsString( 'Draft &lt;b&gt;Title&lt;/b&gt;', $response['html'] );
	}

	/**
	 * Requests the endpoint must refuse, without writing anything.
	 *
	 * @dataProvider data_refusals
	 *
	 * @param string $scenario Which refusal to exercise.
	 */
	public function test_refusals( $scenario ) {
		wp_set_current_user( $this->author_id );

		$valid = array(
			'action'      => 'draftsforfriends_admin',
			'do'          => 'add',
			'post_id'     => $this->draft_id,
			'expires'     => 1,
			'measure'     => 'h',
			'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-add' ),
		);

		switch ( $scenario ) {
			case 'bad nonce':
				$valid['_ajax_nonce'] = 'garbage';
				break;
			case 'absent nonce':
				unset( $valid['_ajax_nonce'] );
				break;
			case 'unknown action':
				$valid['do'] = 'bogus';
				break;
			case 'no action':
				unset( $valid['do'] );
				break;
			case "someone else's draft":
				$valid['post_id'] = $this->editor_draft_id;
				break;
			case 'no post':
				$valid['post_id'] = 0;
				break;
		}

		$before   = $this->total();
		$response = $this->dispatch( $valid );

		$this->assertArrayHasKey( 'error', $response, $scenario . ' should have been refused' );
		$this->assertArrayNotHasKey( 'success', $response );
		$this->assertSame( $before, $this->total(), $scenario . ' wrote a row anyway' );
	}

	/**
	 * The refusals to exercise.
	 *
	 * @return array
	 */
	public function data_refusals() {
		return array(
			array( 'bad nonce' ),
			array( 'absent nonce' ),
			array( 'unknown action' ),
			array( 'no action' ),
			array( "someone else's draft" ),
			array( 'no post' ),
		);
	}

	/**
	 * A visitor without the capability is turned away before anything is read.
	 */
	public function test_endpoint_requires_the_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber );

		$before = $this->total();

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'add',
				'post_id'     => $this->draft_id,
				'expires'     => 1,
				'measure'     => 'h',
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-add' ),
			)
		);

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( $before, $this->total() );
	}

	/**
	 * A logged-out visitor is turned away too.
	 */
	public function test_endpoint_refuses_anonymous() {
		wp_set_current_user( 0 );

		$before = $this->total();

		$response = $this->dispatch(
			array(
				'action'  => 'draftsforfriends_admin',
				'do'      => 'add',
				'post_id' => $this->draft_id,
				'expires' => 1,
				'measure' => 'h',
			)
		);

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( $before, $this->total() );
	}

	/**
	 * Extending through the endpoint.
	 */
	public function test_extend() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];
		$was   = strtotime( $share->date_expired . ' UTC' );

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'extend',
				'id'          => $share->id,
				'expires'     => 1,
				'measure'     => 'h',
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-extend-' . (int) $share->id ),
			)
		);

		$this->assertArrayHasKey( 'success', $response );
		$this->assertNotEmpty( $response['html'] );

		$now = strtotime( $response['shared']['date_expired'] . ' UTC' );

		$this->assertEqualsWithDelta( HOUR_IN_SECONDS, $now - $was, 5 );
	}

	/**
	 * The extend nonce is bound to the share it was issued for.
	 */
	public function test_extend_nonce_is_per_share() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'extend',
				'id'          => $share->id,
				'expires'     => 1,
				'measure'     => 'h',
				// A nonce for a different share id.
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-extend-' . ( (int) $share->id + 1 ) ),
			)
		);

		$this->assertArrayHasKey( 'error', $response );
	}

	/**
	 * Deleting through the endpoint.
	 */
	public function test_delete() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$before = $this->total();

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'delete',
				'id'          => $share->id,
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-delete-' . (int) $share->id ),
			)
		);

		$this->assertArrayHasKey( 'success', $response );
		$this->assertSame( $before - 1, $this->total() );
		$this->assertNotEmpty( $response['countText'] );
	}

	/**
	 * An author cannot delete the editor's share, even with a valid nonce.
	 */
	public function test_delete_refuses_another_users_share() {
		wp_set_current_user( $this->editor_id );

		$share = DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' )['shared'];

		wp_set_current_user( $this->author_id );

		$before = $this->total();

		$response = $this->dispatch(
			array(
				'action'      => 'draftsforfriends_admin',
				'do'          => 'delete',
				'id'          => $share->id,
				// The nonce is per share id, not per user, so this one verifies.
				'_ajax_nonce' => wp_create_nonce( 'draftsforfriends-delete-' . (int) $share->id ),
			)
		);

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( $before, $this->total() );
	}
}
