<?php
/**
 * The front-end preview: the plugin's entire public contract.
 *
 * @package wp-draftsforfriends
 */

/**
 * A logged-out visitor holding a link may read the post it was issued for, and
 * nothing else.
 */
class WP_DraftsForFriends_Preview_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * A second, separately shared unpublished post.
	 *
	 * @var int
	 */
	private $other_draft_id;

	/**
	 * A published post.
	 *
	 * @var int
	 */
	private $published_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// The inherited author and draft are replaced rather than reused: these
		// tests assert on post_content, and a preview that served an empty body
		// would pass every one of them.
		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->draft_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->author_id,
				'post_title'   => 'Secret Draft',
				'post_content' => 'SECRET-DRAFT-BODY',
			)
		);

		$this->other_draft_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->author_id,
				'post_content' => 'OTHER-DRAFT-BODY',
			)
		);

		$this->published_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $this->author_id,
				'post_content' => 'PUBLIC-BODY',
			)
		);

		$this->seed( $this->draft_id, 'HASHLIVE0000000000000000000000AA', HOUR_IN_SECONDS );
		$this->seed( $this->draft_id, 'HASHEXPIRED00000000000000000000A', -HOUR_IN_SECONDS );
		$this->seed( $this->other_draft_id, 'HASHOTHER000000000000000000000AA', HOUR_IN_SECONDS );

		// The friend is not logged in. This matters: WordPress only drops a
		// non-public post from the results for a logged-out visitor, which is the
		// gap the plugin fills between posts_results and the_posts.
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up request state.
	 */
	public function tear_down() {
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Insert a share directly, so these tests do not depend on the admin write path.
	 *
	 * @param int    $post_id    Post being shared.
	 * @param string $hash       Hash to issue.
	 * @param int    $expires_in Seconds from now; negative for an expired share.
	 * @return int Insert ID.
	 */
	private function seed( $post_id, $hash, $expires_in ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->draftsforfriends,
			array(
				'post_id'      => $post_id,
				'user_id'      => $this->author_id,
				'hash'         => $hash,
				'date_created' => current_time( 'mysql', 1 ),
				'date_expired' => gmdate( 'Y-m-d H:i:s', time() + $expires_in ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Query a post the way a visitor hitting ?p=X&draftsforfriends=HASH does.
	 *
	 * @param int         $post_id Post to request.
	 * @param string|null $hash    Hash to present, or null for none.
	 * @return array Posts the query returned.
	 */
	private function visit( $post_id, $hash = null ) {
		$_GET = array();

		if ( null !== $hash ) {
			$_GET['draftsforfriends'] = $hash;
		}

		$_REQUEST = $_GET;

		$query = new WP_Query(
			array(
				'p'                   => $post_id,
				'post_type'           => 'post',
				'ignore_sticky_posts' => true,
			)
		);

		$posts = $query->posts;

		wp_reset_postdata();

		return $posts;
	}

	/**
	 * A current hash returns the post.
	 */
	public function test_valid_hash_returns_the_draft() {
		$posts = $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertCount( 1, $posts, 'A valid hash returns the draft it unlocks.' );
		$this->assertSame( $this->draft_id, $posts[0]->ID, 'The post returned is the one the hash unlocks, not merely some post.' );
		$this->assertStringContainsString( 'SECRET-DRAFT-BODY', $posts[0]->post_content, 'The body is the draft content, so the preview really rendered it.' );
	}

	/**
	 * A shared draft is not a place to collect comments.
	 */
	public function test_comments_are_forced_closed() {
		$posts = $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertSame( 'closed', $posts[0]->comment_status, 'Comments are forced closed on a preview; a shared draft is not a published post.' );
	}

	/**
	 * Everything that must not unlock the post.
	 *
	 * @dataProvider data_denied
	 *
	 * @param string|null $hash Hash to present.
	 */
	public function test_denied( $hash ) {
		$this->assertCount( 0, $this->visit( $this->draft_id, $hash ), 'A denied visitor is shown nothing.' );
	}

	/**
	 * Hashes that must not work.
	 *
	 * @return array
	 */
	public function data_denied() {
		return array(
			'no hash'                 => array( null ),
			'empty hash'              => array( '' ),
			'wrong hash'              => array( 'NOPENOPENOPENOPENOPENOPENOPENOPE' ),
			'expired hash'            => array( 'HASHEXPIRED00000000000000000000A' ),
			'hash for a another post' => array( 'HASHOTHER000000000000000000000AA' ),
			'sql-shaped hash'         => array( "' OR '1'='1" ),
		);
	}

	/**
	 * Regression: the post captured by one query must not be handed to the next.
	 *
	 * Before 2.0.0 posts_results_intercept() only ever set the captured post and
	 * never cleared it, so once any authorised preview had run, every later query
	 * in the same request that legitimately returned nothing got that post back.
	 */
	public function test_authorised_preview_does_not_leak_into_the_next_query() {
		$this->assertCount( 1, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'The authorised preview still returns its own draft.' );

		$this->assertCount( 0, $this->visit( $this->draft_id, null ), 'a request with no hash was served the previous query\'s post' );
	}

	/**
	 * Regression: nor to a query presenting a hash that does not match.
	 */
	public function test_authorised_preview_does_not_leak_to_a_wrong_hash() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertCount( 0, $this->visit( $this->draft_id, 'NOPENOPENOPENOPENOPENOPENOPENOPE' ), 'A wrong hash returns nothing, even after an authorised preview in the same request.' );
	}

	/**
	 * Regression: nor to a query for a different unpublished post.
	 */
	public function test_authorised_preview_does_not_leak_into_another_draft() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertCount( 0, $this->visit( $this->other_draft_id, null ), 'Another draft stays hidden after an authorised preview.' );
	}

	/**
	 * Regression: nor into an unrelated query that found nothing.
	 */
	public function test_authorised_preview_does_not_leak_into_an_empty_search() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$query = new WP_Query( array( 's' => 'zzz-no-such-post-zzz' ) );
		$posts = $query->posts;

		wp_reset_postdata();

		$this->assertCount( 0, $posts, 'An unrelated empty search stays empty after an authorised preview.' );
	}

	/**
	 * Published posts are untouched, with or without a hash on the URL.
	 */
	public function test_published_posts_are_untouched() {
		$posts = $this->visit( $this->published_id, null );

		$this->assertCount( 1, $posts, 'A published post is returned as it always was.' );
		$this->assertSame(
			get_post_field( 'comment_status', $this->published_id ),
			$posts[0]->comment_status,
			'the plugin changed comment_status on a published post'
		);

		$this->assertCount( 1, $this->visit( $this->published_id, 'HASHLIVE0000000000000000000000AA' ), 'A published post is unaffected by a share hash.' );
	}

	/**
	 * Pending posts share the same way drafts do.
	 */
	public function test_pending_posts_are_shareable() {
		$pending = self::factory()->post->create(
			array(
				'post_status' => 'pending',
				'post_author' => $this->author_id,
			)
		);

		$this->seed( $pending, 'HASHPENDING0000000000000000000AA', HOUR_IN_SECONDS );

		$this->assertCount( 1, $this->visit( $pending, 'HASHPENDING0000000000000000000AA' ), 'A pending post is shareable too, not only a draft.' );
	}

	/**
	 * Trashing a shared post revokes its links.
	 *
	 * Until 2.0.0 only `publish` was excluded, so a trashed draft was still served
	 * to anyone holding the URL -- the most obvious way to withdraw a draft did
	 * nothing.
	 */
	public function test_trashing_the_post_revokes_the_link() {
		$this->assertCount( 1, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'The link works before the post is trashed.' );

		wp_trash_post( $this->draft_id );

		$this->assertCount( 0, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'Trashing the post revokes the link.' );
	}

	/**
	 * Restoring the post from the trash makes the same link work again.
	 */
	public function test_untrashing_the_post_restores_the_link() {
		wp_trash_post( $this->draft_id );
		$this->assertCount( 0, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'The link stays dead while the post is trashed.' );

		wp_untrash_post( $this->draft_id );
		wp_update_post(
			array(
				'ID'          => $this->draft_id,
				'post_status' => 'draft',
			)
		);

		$this->assertCount( 1, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'Untrashing the post restores the link.' );
	}

	/**
	 * A share survives the post being scheduled or made private.
	 *
	 * Both are still unpublished, and the author shared the post deliberately, so
	 * the link is expected to keep working.
	 *
	 * @dataProvider data_surviving_statuses
	 *
	 * @param string $status Status to move the post to.
	 */
	public function test_share_survives_status_change( $status ) {
		wp_update_post(
			array(
				'ID'          => $this->draft_id,
				'post_status' => $status,
			)
		);

		$this->assertCount( 1, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ), 'The share survives a change of post status.' );
	}

	/**
	 * Statuses a share must survive.
	 *
	 * @return array
	 */
	public function data_surviving_statuses() {
		return array(
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * Publishing the post ends the preview; the post is public by then anyway.
	 */
	public function test_publishing_the_post_ends_the_preview() {
		wp_update_post(
			array(
				'ID'          => $this->draft_id,
				'post_status' => 'publish',
			)
		);

		$posts = $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		// WordPress serves it as an ordinary published post, so it is still one
		// result -- but the plugin has not touched it, which the open comment
		// status shows.
		$this->assertCount( 1, $posts, 'Publishing the post ends the preview and returns it as an ordinary post.' );
		$this->assertSame( get_post_field( 'comment_status', $this->draft_id ), $posts[0]->comment_status, 'Once published the post keeps its own comment status rather than the forced one.' );
	}

	/**
	 * Deleting the post for good removes its shares.
	 */
	public function test_deleting_the_post_removes_its_shares() {
		global $wpdb;

		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE post_id = %d", $this->draft_id ) );

		$this->assertGreaterThan( 0, $before, 'The fixture really does have shares, or the deletion below proves nothing.' );

		wp_delete_post( $this->draft_id, true );

		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE post_id = %d", $this->draft_id ) ),
			'shares for a deleted post must not be left behind'
		);
	}
}
