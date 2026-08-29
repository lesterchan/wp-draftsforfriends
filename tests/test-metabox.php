<?php
/**
 * The post editor's meta box.
 *
 * @package WP-DraftsForFriends
 */

/**
 * Registration, rendering and the create-on-save write.
 */
class WP_DraftsForFriends_Metabox_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * Meta box registration survives the test's transaction, so start clean.
	 */
	public function set_up() {
		parent::set_up();

		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * Whether the box is registered on the post editor's side column.
	 *
	 * @return bool
	 */
	private function box_is_registered() {
		global $wp_meta_boxes;

		return ! empty( $wp_meta_boxes['post']['side']['default'][ WP_DraftsForFriends_Metabox::ID ] );
	}

	/**
	 * Render the box for a post, as the current user.
	 *
	 * @param int $post_id Post being edited.
	 * @return string The rendered markup.
	 */
	private function render_box( $post_id ) {
		ob_start();
		WP_DraftsForFriends_Metabox::render( get_post( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * A valid create-on-save submission, as wp_nonce_field() would post it.
	 *
	 * @param array $fields Fields to merge over the valid ones.
	 * @return array The posted fields.
	 */
	private function valid_save_request( array $fields = array() ) {
		return array_merge(
			array(
				WP_DraftsForFriends_Metabox::NONCE_FIELD => wp_create_nonce( WP_DraftsForFriends_Metabox::NONCE_ACTION ),
				'draftsforfriends_create'                => '1',
				'draftsforfriends_expires'               => 3,
				'draftsforfriends_measure'               => 'd',
			),
			$fields
		);
	}

	public function test_the_box_hangs_off_the_post_only_hooks() {
		WP_DraftsForFriends_Metabox::init();

		// The typed hooks fire for the built-in post type and no other; a box on
		// any other type would hand out ?p=<id> links that 404.
		$this->assertNotFalse( has_action( 'add_meta_boxes_post', array( 'WP_DraftsForFriends_Metabox', 'add_meta_box' ) ), 'the box is not registered on the post editor' );
		$this->assertNotFalse( has_action( 'save_post_post', array( 'WP_DraftsForFriends_Metabox', 'save' ) ), 'the create-on-save control posts into a save nothing listens to' );
		$this->assertFalse( has_action( 'add_meta_boxes', array( 'WP_DraftsForFriends_Metabox', 'add_meta_box' ) ), 'the untyped hook would offer the box on every post type' );
		$this->assertFalse( has_action( 'save_post', array( 'WP_DraftsForFriends_Metabox', 'save' ) ), 'the untyped save hook would create shares for types the preview cannot serve' );
	}

	public function test_the_box_is_registered_for_whoever_may_share() {
		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Metabox::add_meta_box();

		$this->assertTrue( $this->box_is_registered(), 'An author holds publish_posts, so the box is theirs.' );
	}

	public function test_the_box_is_withheld_from_a_user_who_may_not_share() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		WP_DraftsForFriends_Metabox::add_meta_box();

		$this->assertFalse( $this->box_is_registered(), 'a contributor cannot reach the Shared Drafts screen, so the box must not appear either' );
	}

	public function test_the_box_lists_the_posts_links_with_the_copy_button() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html, 'The box carries the post\'s share link.' );
		$this->assertStringContainsString( 'draftsforfriends-copy', $html, 'The link comes with a copy button.' );
		$this->assertStringContainsString( 'Expires in', $html, 'The box says how long the link has left.' );
		$this->assertStringContainsString( 'page=' . WP_DraftsForFriends_Admin::PAGE, $html, 'The box links to the screen where links are extended and revoked.' );
	}

	public function test_the_box_offers_to_create_a_link_on_save() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'draftsforfriends_create', $html, 'The create-on-save checkbox is in the box.' );
		$this->assertStringContainsString( WP_DraftsForFriends_Metabox::NONCE_FIELD, $html, 'the checkbox posts nothing verifiable without its nonce' );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html, 'a field named _wpnonce inside the post form would replace the post\'s own nonce' );
		$this->assertStringContainsString( 'draftsforfriends_expires', $html, 'The duration control is in the box.' );
		$this->assert_option_selected( 'h', $html, 'The duration unit starts on the configured default.' );
	}

	public function test_a_saved_draft_gets_the_button_and_keeps_the_checkbox_for_a_reader_without_javascript() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'draftsforfriends-create', $html, 'The button that creates a link without a save is in the box.' );
		$this->assertStringContainsString( 'data-post="' . $this->draft_id . '"', $html, 'the button has to say which post it is creating a link for' );
		$this->assertStringContainsString( 'draftsforfriends-create-now hide-if-no-js"', $html, 'a button that does nothing without a script must not be the only control on the screen' );
		$this->assertStringContainsString( 'draftsforfriends-create-on-save hide-if-js', $html, 'the checkbox is the fallback here, so it is the one hidden when the script is running' );
	}

	public function test_a_post_that_has_never_been_saved_gets_the_checkbox_instead() {
		wp_set_current_user( $this->author_id );

		$auto = self::factory()->post->create(
			array(
				'post_status' => 'auto-draft',
				'post_author' => $this->author_id,
			)
		);

		$html = $this->render_box( $auto );

		// The preview denies auto-draft, so a link made now would 404. The
		// checkbox is right here because the save it rides moves the post to
		// draft before the share is written.
		$this->assertStringContainsString( 'draftsforfriends-create-now hide-if-no-js hidden', $html, 'the button would mint a link the preview refuses to serve' );
		$this->assertStringContainsString( 'draftsforfriends-create-on-save"', $html, 'the checkbox is the only control that works on a post with no status yet' );
	}

	public function test_the_box_carries_somewhere_of_its_own_to_report_into() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_box( $this->draft_id );

		// The block editor draws meta boxes on a screen with no .wrap, so a
		// message raised as an admin notice would go nowhere.
		$this->assertStringContainsString( 'id="draftsforfriends-metabox-message"', $html, 'the button has nowhere to say what happened' );
		$this->assertStringContainsString( 'role="alert"', $html, 'a message that is only painted is not announced' );
	}

	public function test_the_box_carries_the_list_a_new_link_is_inserted_into() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'draftsforfriends-metabox-shares', $html, 'the script has nowhere to put the link it just created' );
	}

	public function test_a_heading_separates_the_posts_links_from_the_controls_that_make_another() {
		$this->make_share( $this->author_id, $this->draft_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'Share Links', $html, 'The links the post already has are headed.' );
		$this->assertStringContainsString( 'New Share Link', $html, 'The controls that make another are headed too, or the box reads as one run-on list.' );
		$this->assertLessThan( strpos( $html, 'New Share Link' ), (int) strpos( $html, 'Share Links' ), 'the heading for the existing links has to come before the one for the new one' );
	}

	public function test_the_links_heading_stays_out_of_the_way_until_there_is_a_link() {
		wp_set_current_user( $this->author_id );

		$html = $this->render_box( $this->draft_id );

		// Rendered but hidden, because the script unhides it when it creates
		// the post's first link rather than building the heading itself.
		$this->assertMatchesRegularExpression( '/id="draftsforfriends-metabox-links-heading" hidden/', $html, 'a heading over an empty list says the post has links when it has none' );
	}

	public function test_a_published_post_gets_a_sentence_rather_than_controls() {
		$this->make_share( $this->author_id, $this->draft_id );

		wp_publish_post( $this->draft_id );

		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( 'published', $html, 'The box says why there is nothing to do here.' );
		$this->assertStringNotContainsString( 'draftsforfriends_create', $html, 'a create control on a published post could only fail' );
		$this->assertStringNotContainsString( 'draftsforfriends-copy', $html, 'a copy button here would hand out links that 404 while the post is published' );
	}

	public function test_the_box_shows_only_links_its_reader_may_see() {
		$mine   = $this->make_share( $this->author_id, $this->draft_id );
		$theirs = $this->make_share( $this->editor_id, $this->draft_id );

		wp_set_current_user( $this->author_id );
		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( $mine->hash, $html, 'The author sees their own link.' );
		$this->assertStringNotContainsString( $theirs->hash, $html, 'an author was shown a share created by somebody else' );

		wp_set_current_user( $this->editor_id );
		$html = $this->render_box( $this->draft_id );

		$this->assertStringContainsString( $mine->hash, $html, 'An editor sees every link the post has.' );
		$this->assertStringContainsString( $theirs->hash, $html, 'An editor sees their own link too.' );
	}

	public function test_saving_with_the_box_ticked_creates_the_share() {
		wp_set_current_user( $this->author_id );

		$_POST = $this->valid_save_request();

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 1, $this->total_shares(), 'The ticked box creates exactly one share.' );

		$shares = WP_DraftsForFriends_Shares::for_post( $this->draft_id );

		$this->assertCount( 1, $shares, 'The share belongs to the post that was saved.' );

		// Three days out, give or take the seconds the test itself took.
		$expected = time() + 3 * DAY_IN_SECONDS;

		$this->assertEqualsWithDelta( $expected, (int) mysql2date( 'G', $shares[0]->date_expired ), 60, 'The duration posted with the box is the duration the share got.' );
	}

	public function test_an_unticked_box_creates_nothing() {
		wp_set_current_user( $this->author_id );

		$fields = $this->valid_save_request();
		unset( $fields['draftsforfriends_create'] );

		$_POST = $fields;

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 0, $this->total_shares(), 'an unticked checkbox is a save, not a request' );
	}

	public function test_a_save_without_the_nonce_creates_nothing() {
		wp_set_current_user( $this->author_id );

		$fields = $this->valid_save_request();
		unset( $fields[ WP_DraftsForFriends_Metabox::NONCE_FIELD ] );

		$_POST = $fields;

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 0, $this->total_shares(), 'a save the box was not part of must not create a share' );
	}

	public function test_a_save_with_a_stale_nonce_creates_nothing() {
		wp_set_current_user( $this->author_id );

		$_POST = $this->valid_save_request(
			array( WP_DraftsForFriends_Metabox::NONCE_FIELD => 'not-a-nonce' )
		);

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 0, $this->total_shares(), 'a bad nonce must be refused, quietly, without eating the save' );
	}

	public function test_a_user_who_may_not_share_creates_nothing() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );

		wp_set_current_user( $contributor );

		$_POST = $this->valid_save_request();

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 0, $this->total_shares(), 'the capability gate on the screen must hold on the save as well' );
	}

	public function test_a_save_cannot_share_somebody_elses_post() {
		// A second author, who may edit their own posts but not this one.
		$rival = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $rival );

		$_POST = $this->valid_save_request();

		WP_DraftsForFriends_Metabox::save( $this->draft_id );

		$this->assertSame( 0, $this->total_shares(), 'create() checks edit_post for the post being saved, and the box must not open a way around it' );
	}

	public function test_the_editor_screens_load_the_copy_buttons_assets() {
		wp_set_current_user( $this->author_id );
		set_current_screen( 'post' );

		WP_DraftsForFriends_Metabox::enqueue( 'post.php' );

		$this->assertTrue( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'The copy button is dead without its script.' );
		$this->assertTrue( wp_style_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'The box\'s links wrap through the plugin stylesheet.' );
	}

	public function test_the_assets_stay_off_other_editors_and_other_screens() {
		wp_set_current_user( $this->author_id );

		set_current_screen( 'page' );
		WP_DraftsForFriends_Metabox::enqueue( 'post.php' );

		$this->assertFalse( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the page editor has no box, so it must not load the script' );

		set_current_screen( 'post' );
		WP_DraftsForFriends_Metabox::enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( 'wp-draftsforfriends-admin', 'enqueued' ), 'the posts list is not the editor' );
	}
}
