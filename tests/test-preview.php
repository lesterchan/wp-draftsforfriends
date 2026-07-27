<?php
/**
 * The front-end preview: the plugin's entire public contract.
 *
 * @package WP-DraftsForFriends
 */

/**
 * A logged-out visitor holding a link may read the post it was issued for, and
 * nothing else.
 */
class Test_DraftsForFriends_Preview extends WP_UnitTestCase {

	/**
	 * Author of the fixtures.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * An unpublished post.
	 *
	 * @var int
	 */
	private $draft_id;

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

		$this->assertCount( 1, $posts );
		$this->assertSame( $this->draft_id, $posts[0]->ID );
		$this->assertStringContainsString( 'SECRET-DRAFT-BODY', $posts[0]->post_content );
	}

	/**
	 * A shared draft is not a place to collect comments.
	 */
	public function test_comments_are_forced_closed() {
		$posts = $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertSame( 'closed', $posts[0]->comment_status );
	}

	/**
	 * Everything that must not unlock the post.
	 *
	 * @dataProvider data_denied
	 *
	 * @param string|null $hash Hash to present.
	 */
	public function test_denied( $hash ) {
		$this->assertCount( 0, $this->visit( $this->draft_id, $hash ) );
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
		$this->assertCount( 1, $this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' ) );

		$this->assertCount( 0, $this->visit( $this->draft_id, null ), 'a request with no hash was served the previous query\'s post' );
	}

	/**
	 * Regression: nor to a query presenting a hash that does not match.
	 */
	public function test_authorised_preview_does_not_leak_to_a_wrong_hash() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertCount( 0, $this->visit( $this->draft_id, 'NOPENOPENOPENOPENOPENOPENOPENOPE' ) );
	}

	/**
	 * Regression: nor to a query for a different unpublished post.
	 */
	public function test_authorised_preview_does_not_leak_into_another_draft() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$this->assertCount( 0, $this->visit( $this->other_draft_id, null ) );
	}

	/**
	 * Regression: nor into an unrelated query that found nothing.
	 */
	public function test_authorised_preview_does_not_leak_into_an_empty_search() {
		$this->visit( $this->draft_id, 'HASHLIVE0000000000000000000000AA' );

		$query = new WP_Query( array( 's' => 'zzz-no-such-post-zzz' ) );
		$posts = $query->posts;

		wp_reset_postdata();

		$this->assertCount( 0, $posts );
	}

	/**
	 * Published posts are untouched, with or without a hash on the URL.
	 */
	public function test_published_posts_are_untouched() {
		$posts = $this->visit( $this->published_id, null );

		$this->assertCount( 1, $posts );
		$this->assertSame(
			get_post_field( 'comment_status', $this->published_id ),
			$posts[0]->comment_status,
			'the plugin changed comment_status on a published post'
		);

		$this->assertCount( 1, $this->visit( $this->published_id, 'HASHLIVE0000000000000000000000AA' ) );
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

		$this->assertCount( 1, $this->visit( $pending, 'HASHPENDING0000000000000000000AA' ) );
	}
}
