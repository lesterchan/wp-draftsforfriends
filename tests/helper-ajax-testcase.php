<?php
/**
 * Base class for the tests that drive the plugin's AJAX endpoint.
 *
 * Identical in intent to WP_DraftsForFriends_TestCase, but rooted in
 * WP_Ajax_UnitTestCase: the create endpoint ends in wp_send_json_*(), and only
 * that base class installs the handler which turns the wp_die() underneath it
 * into a catchable exception rather than taking the runner with it. PHP has no
 * multiple inheritance, so the fixtures the two bases share are repeated here.
 *
 * @package WP-DraftsForFriends
 */

/**
 * Seeds the author and the draft, and unwraps what the endpoint printed.
 */
abstract class WP_DraftsForFriends_Ajax_TestCase extends WP_Ajax_UnitTestCase {

	/**
	 * An author, who may only touch their own posts.
	 *
	 * @var int
	 */
	protected $author_id;

	/**
	 * The author's draft.
	 *
	 * @var int
	 */
	protected $draft_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// The plugin registers the endpoint behind is_admin(), which is false
		// while the suite boots, so the suite registers it itself.
		WP_DraftsForFriends_Metabox::init();

		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
				'post_title'  => 'Draft <b>Title</b> & "quoted"',
			)
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Every share in the table, ignoring who may see it.
	 *
	 * @return int
	 */
	protected function total_shares() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends}" );
	}

	/**
	 * Post to the create endpoint and decode what it answered.
	 *
	 * Both endings arrive as exceptions, and which one says nothing about
	 * whether the request succeeded: wp_send_json_*() dies with an empty
	 * message, which is Continue, and check_ajax_referer() dies with -1, which
	 * is Stop. A plain return is the one outcome that is always wrong -- it
	 * means the handler fell through without answering at all.
	 *
	 * @param array $fields Fields to merge over the valid ones.
	 * @return array The decoded response.
	 */
	protected function create_share( array $fields = array() ) {
		$_POST = array_merge(
			array(
				'action'  => WP_DraftsForFriends_Metabox::AJAX_ACTION,
				'nonce'   => wp_create_nonce( WP_DraftsForFriends_Metabox::AJAX_NONCE_ACTION ),
				'post_id' => $this->draft_id,
				'expires' => 3,
				'measure' => 'd',
			),
			$fields
		);

		$_REQUEST = $_POST;

		$answered = false;

		try {
			$this->_handleAjax( WP_DraftsForFriends_Metabox::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			$answered = true;
		} catch ( WPAjaxDieStopException $e ) {
			$answered = true;
		}

		$this->assertTrue( $answered, 'the endpoint returned without sending a response' );

		return (array) json_decode( $this->_last_response, true );
	}
}
