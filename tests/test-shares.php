<?php
/**
 * The data layer: expiry arithmetic, the countdown, and capability scoping.
 *
 * @package WP-DraftsForFriends
 */

/**
 * DraftsForFriends_Shares.
 */
class Test_DraftsForFriends_Shares extends WP_UnitTestCase {

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
				'post_title'  => 'Author Draft',
			)
		);

		$this->editor_draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->editor_id,
				'post_title'  => 'Editor Draft',
			)
		);
	}

	/**
	 * The table is registered on $wpdb, including in $wpdb->tables so it survives
	 * switch_to_blog().
	 */
	public function test_table_is_registered() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'draftsforfriends', $wpdb->draftsforfriends );
		$this->assertContains( 'draftsforfriends', $wpdb->tables, '$wpdb->tables is what re-prefixes the name across switch_to_blog()' );
	}

	/**
	 * Every unit keeps its meaning.
	 *
	 * @dataProvider data_units
	 *
	 * @param string $unit     Unit key.
	 * @param int    $expected Seconds for a duration of 2.
	 */
	public function test_calculate_expiry_units( $unit, $expected ) {
		$this->assertSame( $expected, DraftsForFriends_Shares::calculate_expiry( 2, $unit ) );
	}

	/**
	 * Units and the seconds two of them come to.
	 *
	 * @return array
	 */
	public function data_units() {
		return array(
			'seconds' => array( 's', 2 ),
			'minutes' => array( 'm', 120 ),
			'hours'   => array( 'h', 7200 ),
			'days'    => array( 'd', 172800 ),
		);
	}

	/**
	 * An unrecognised unit falls back to minutes rather than warning.
	 */
	public function test_calculate_expiry_rejects_unknown_unit() {
		$this->assertSame( 120, DraftsForFriends_Shares::calculate_expiry( 2, 'zzz' ) );
		$this->assertSame( 120, DraftsForFriends_Shares::calculate_expiry( 2, '' ) );
	}

	/**
	 * A non-positive duration falls back to sixty of whatever unit was given.
	 */
	public function test_calculate_expiry_rejects_non_positive_duration() {
		$this->assertSame( 60 * HOUR_IN_SECONDS, DraftsForFriends_Shares::calculate_expiry( 0, 'h' ) );
		$this->assertSame( 60 * HOUR_IN_SECONDS, DraftsForFriends_Shares::calculate_expiry( -5, 'h' ) );
	}

	/**
	 * The countdown reports what is left.
	 */
	public function test_countdown() {
		$in = function ( $seconds ) {
			return gmdate( 'Y-m-d H:i:s', time() + $seconds );
		};

		$this->assertSame( 'Expired', DraftsForFriends_Shares::countdown( $in( -1 ) ) );
		$this->assertSame( 'Expired', DraftsForFriends_Shares::countdown( $in( -DAY_IN_SECONDS ) ) );

		$this->assertStringContainsString( 'second', DraftsForFriends_Shares::countdown( $in( 30 ) ) );
		$this->assertStringContainsString( '2 hours', DraftsForFriends_Shares::countdown( $in( 2 * HOUR_IN_SECONDS + 30 ) ) );
		$this->assertStringContainsString( '3 days', DraftsForFriends_Shares::countdown( $in( 3 * DAY_IN_SECONDS + 30 ) ) );
	}

	/**
	 * The singular form is used for one of each unit.
	 */
	public function test_countdown_is_singular_for_one() {
		$countdown = DraftsForFriends_Shares::countdown( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS + HOUR_IN_SECONDS + MINUTE_IN_SECONDS + 5 ) );

		$this->assertStringContainsString( '1 day', $countdown );
		$this->assertStringContainsString( '1 hour', $countdown );
		$this->assertStringNotContainsString( 'days', $countdown );
	}

	/**
	 * Creating a share records the current user and a fresh hash.
	 */
	public function test_create() {
		wp_set_current_user( $this->author_id );

		$result = DraftsForFriends_Shares::create( $this->draft_id, 3, 'h' );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertNotEmpty( $result['shared'] );

		$share = $result['shared'];

		$this->assertSame( (int) $this->draft_id, (int) $share->post_id );
		$this->assertSame( (int) $this->author_id, (int) $share->user_id );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $share->hash, 'the hash must stay 32 alphanumeric characters' );

		$delta = strtotime( $share->date_expired . ' UTC' ) - time();

		$this->assertGreaterThan( 3 * HOUR_IN_SECONDS - 60, $delta );
		$this->assertLessThanOrEqual( 3 * HOUR_IN_SECONDS + 5, $delta );
	}

	/**
	 * Two shares of the same post get different hashes.
	 */
	public function test_create_issues_unique_hashes() {
		wp_set_current_user( $this->author_id );

		$first  = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );
		$second = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		$this->assertNotSame( $first['shared']->hash, $second['shared']->hash );
	}

	/**
	 * Requests that must be refused.
	 */
	public function test_create_refusals() {
		wp_set_current_user( $this->author_id );

		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::create( 0, 1, 'h' ), 'no post chosen' );
		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::create( 99999999, 1, 'h' ), 'no such post' );

		$published = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::create( $published, 1, 'h' ), 'already published' );
		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' ), "someone else's draft" );
	}

	/**
	 * An unexpired share is extended from the expiry it already had.
	 */
	public function test_extend_adds_to_an_unexpired_share() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];
		$was   = strtotime( $share->date_expired . ' UTC' );

		$result = DraftsForFriends_Shares::extend( $share->id, 1, 'h' );

		$this->assertArrayHasKey( 'success', $result );

		$now = strtotime( $result['shared']->date_expired . ' UTC' );

		$this->assertEqualsWithDelta( HOUR_IN_SECONDS, $now - $was, 5 );
		$this->assertNotEmpty( $result['shared']->date_extended );
	}

	/**
	 * An expired share restarts from now, so extending it by an hour gives an hour.
	 */
	public function test_extend_restarts_an_expired_share_from_now() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		// Push it an hour into the past.
		$wpdb->update(
			$wpdb->draftsforfriends,
			array( 'date_expired' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => $share->id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = DraftsForFriends_Shares::extend( $share->id, 1, 'h' );

		$this->assertArrayHasKey( 'success', $result );

		$delta = strtotime( $result['shared']->date_expired . ' UTC' ) - time();

		$this->assertGreaterThan( HOUR_IN_SECONDS - 60, $delta, 'an expired share must extend from now, not from its stale expiry' );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS + 5, $delta );
	}

	/**
	 * Deleting revokes the hash.
	 */
	public function test_delete() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$this->assertTrue( DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );

		$result = DraftsForFriends_Shares::delete( $share->id );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertNull( DraftsForFriends_Shares::get( $share->id ) );
		$this->assertFalse( DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );
	}

	/**
	 * An author cannot see, extend or delete someone else's share.
	 *
	 * The row is the authority on which post's capability is checked; before
	 * 2.0.0 that came from a post id supplied alongside the share id.
	 */
	public function test_author_cannot_touch_another_users_share() {
		wp_set_current_user( $this->editor_id );

		$share = DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' )['shared'];

		wp_set_current_user( $this->author_id );

		$this->assertNull( DraftsForFriends_Shares::get( $share->id ) );
		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::extend( $share->id, 5, 'd' ) );
		$this->assertArrayHasKey( 'error', DraftsForFriends_Shares::delete( $share->id ) );

		// And the share is still intact.
		wp_set_current_user( $this->editor_id );

		$this->assertNotNull( DraftsForFriends_Shares::get( $share->id ) );
	}

	/**
	 * Anyone with edit_others_posts sees every share; an author sees only theirs.
	 */
	public function test_count_and_query_are_scoped_by_capability() {
		wp_set_current_user( $this->author_id );
		DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		wp_set_current_user( $this->editor_id );
		DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' );

		$this->assertSame( 2, DraftsForFriends_Shares::count(), 'an editor sees every share' );
		$this->assertCount( 2, DraftsForFriends_Shares::query() );

		wp_set_current_user( $this->author_id );

		$this->assertSame( 1, DraftsForFriends_Shares::count(), 'an author sees only their own' );
		$this->assertCount( 1, DraftsForFriends_Shares::query() );
	}

	/**
	 * An expired hash does not unlock the post.
	 */
	public function test_hash_unlocks_respects_expiry() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$wpdb->update(
			$wpdb->draftsforfriends,
			array( 'date_expired' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $share->id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertFalse( DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );
	}

	/**
	 * An empty hash never unlocks anything.
	 */
	public function test_hash_unlocks_rejects_empty() {
		$this->assertFalse( DraftsForFriends_Shares::hash_unlocks( $this->draft_id, '' ) );
	}

	/**
	 * Only ORDER BY columns from the allow list reach the query.
	 */
	public function test_query_rejects_arbitrary_orderby() {
		wp_set_current_user( $this->author_id );
		DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		// If either of these reached the SQL the query would error and return no
		// rows, so getting the row back is the assertion.
		$this->assertCount( 1, DraftsForFriends_Shares::query( 'id; DROP TABLE wp_posts', 'asc' ) );
		$this->assertCount( 1, DraftsForFriends_Shares::query( 'date_created', "asc'--" ) );

		$this->assertNotEmpty( get_post( $this->draft_id ), 'wp_posts survived' );
	}

	/**
	 * The share URL carries the post and the hash.
	 */
	public function test_url() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];
		$url   = DraftsForFriends_Shares::url( $share );

		$this->assertStringContainsString( 'p=' . $this->draft_id, $url );
		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $url );
		$this->assertStringStartsWith( home_url(), $url );
	}

	/**
	 * An author is only offered their own unpublished posts.
	 */
	public function test_shareable_posts_are_scoped_by_capability() {
		wp_set_current_user( $this->author_id );

		$ids = array();

		foreach ( DraftsForFriends_Shares::shareable_posts() as $group ) {
			foreach ( $group['posts'] as $post ) {
				$ids[] = $post->ID;
			}
		}

		$this->assertContains( $this->draft_id, $ids );
		$this->assertNotContains( $this->editor_draft_id, $ids, "an author was offered someone else's draft" );

		wp_set_current_user( $this->editor_id );

		$ids = array();

		foreach ( DraftsForFriends_Shares::shareable_posts() as $group ) {
			foreach ( $group['posts'] as $post ) {
				$ids[] = $post->ID;
			}
		}

		$this->assertContains( $this->draft_id, $ids );
		$this->assertContains( $this->editor_draft_id, $ids );
	}

	/**
	 * Published posts are never offered for sharing.
	 */
	public function test_shareable_posts_excludes_published() {
		$published = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		wp_set_current_user( $this->author_id );

		foreach ( DraftsForFriends_Shares::shareable_posts() as $group ) {
			foreach ( $group['posts'] as $post ) {
				$this->assertNotSame( $published, $post->ID );
			}
		}
	}
}
