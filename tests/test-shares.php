<?php
/**
 * The data layer: expiry arithmetic, the countdown, and capability scoping.
 *
 * @package wp-draftsforfriends
 */

/**
 * WP_DraftsForFriends_Shares.
 */
class WP_DraftsForFriends_Shares_Test extends WP_DraftsForFriends_TestCase {

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
		$this->assertSame( $expected, WP_DraftsForFriends_Shares::calculate_expiry( 2, $unit ) );
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
		$this->assertSame( 120, WP_DraftsForFriends_Shares::calculate_expiry( 2, 'zzz' ) );
		$this->assertSame( 120, WP_DraftsForFriends_Shares::calculate_expiry( 2, '' ) );
	}

	/**
	 * A non-positive duration falls back to sixty of whatever unit was given.
	 */
	public function test_calculate_expiry_rejects_non_positive_duration() {
		$this->assertSame( 60 * HOUR_IN_SECONDS, WP_DraftsForFriends_Shares::calculate_expiry( 0, 'h' ) );
		$this->assertSame( 60 * HOUR_IN_SECONDS, WP_DraftsForFriends_Shares::calculate_expiry( -5, 'h' ) );
	}

	/**
	 * The countdown reports what is left.
	 */
	public function test_countdown() {
		$in = function ( $seconds ) {
			return gmdate( 'Y-m-d H:i:s', time() + $seconds );
		};

		$this->assertSame( 'Expired', WP_DraftsForFriends_Shares::countdown( $in( -1 ) ) );
		$this->assertSame( 'Expired', WP_DraftsForFriends_Shares::countdown( $in( -DAY_IN_SECONDS ) ) );

		$this->assertStringContainsString( 'second', WP_DraftsForFriends_Shares::countdown( $in( 30 ) ) );
		$this->assertStringContainsString( '2 hours', WP_DraftsForFriends_Shares::countdown( $in( 2 * HOUR_IN_SECONDS + 30 ) ) );
		$this->assertStringContainsString( '3 days', WP_DraftsForFriends_Shares::countdown( $in( 3 * DAY_IN_SECONDS + 30 ) ) );
	}

	/**
	 * The singular form is used for one of each unit.
	 */
	public function test_countdown_is_singular_for_one() {
		$countdown = WP_DraftsForFriends_Shares::countdown( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS + HOUR_IN_SECONDS + MINUTE_IN_SECONDS + 5 ) );

		$this->assertStringContainsString( '1 day', $countdown );
		$this->assertStringContainsString( '1 hour', $countdown );
		$this->assertStringNotContainsString( 'days', $countdown );
	}

	/**
	 * Creating a share records the current user and a fresh hash.
	 */
	public function test_create() {
		wp_set_current_user( $this->author_id );

		$result = WP_DraftsForFriends_Shares::create( $this->draft_id, 3, 'h' );

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

		$first  = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );
		$second = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		$this->assertNotSame( $first['shared']->hash, $second['shared']->hash );
	}

	/**
	 * Requests that must be refused.
	 */
	public function test_create_refusals() {
		wp_set_current_user( $this->author_id );

		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::create( 0, 1, 'h' ), 'no post chosen' );
		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::create( 99999999, 1, 'h' ), 'no such post' );

		$published = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);

		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::create( $published, 1, 'h' ), 'already published' );
		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' ), "someone else's draft" );
	}

	/**
	 * An unexpired share is extended from the expiry it already had.
	 */
	public function test_extend_adds_to_an_unexpired_share() {
		wp_set_current_user( $this->author_id );

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];
		$was   = strtotime( $share->date_expired . ' UTC' );

		$result = WP_DraftsForFriends_Shares::extend( $share->id, 1, 'h' );

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

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		// Push it an hour into the past.
		$wpdb->update(
			$wpdb->draftsforfriends,
			array( 'date_expired' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
			array( 'id' => $share->id ),
			array( '%s' ),
			array( '%d' )
		);

		$result = WP_DraftsForFriends_Shares::extend( $share->id, 1, 'h' );

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

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$this->assertTrue( WP_DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );

		$result = WP_DraftsForFriends_Shares::delete( $share->id );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertNull( WP_DraftsForFriends_Shares::get( $share->id ) );
		$this->assertFalse( WP_DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );
	}

	/**
	 * An author cannot see, extend or delete someone else's share.
	 *
	 * The row is the authority on which post's capability is checked; before
	 * 2.0.0 that came from a post id supplied alongside the share id.
	 */
	public function test_author_cannot_touch_another_users_share() {
		wp_set_current_user( $this->editor_id );

		$share = WP_DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' )['shared'];

		wp_set_current_user( $this->author_id );

		$this->assertNull( WP_DraftsForFriends_Shares::get( $share->id ) );
		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::extend( $share->id, 5, 'd' ) );
		$this->assertArrayHasKey( 'error', WP_DraftsForFriends_Shares::delete( $share->id ) );

		// And the share is still intact.
		wp_set_current_user( $this->editor_id );

		$this->assertNotNull( WP_DraftsForFriends_Shares::get( $share->id ) );
	}

	/**
	 * Anyone with edit_others_posts sees every share; an author sees only theirs.
	 */
	public function test_count_and_query_are_scoped_by_capability() {
		wp_set_current_user( $this->author_id );
		WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		wp_set_current_user( $this->editor_id );
		WP_DraftsForFriends_Shares::create( $this->editor_draft_id, 1, 'h' );

		$this->assertSame( 2, WP_DraftsForFriends_Shares::count(), 'an editor sees every share' );
		$this->assertCount( 2, WP_DraftsForFriends_Shares::query() );

		wp_set_current_user( $this->author_id );

		$this->assertSame( 1, WP_DraftsForFriends_Shares::count(), 'an author sees only their own' );
		$this->assertCount( 1, WP_DraftsForFriends_Shares::query() );
	}

	/**
	 * An expired hash does not unlock the post.
	 */
	public function test_hash_unlocks_respects_expiry() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$wpdb->update(
			$wpdb->draftsforfriends,
			array( 'date_expired' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $share->id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertFalse( WP_DraftsForFriends_Shares::hash_unlocks( $this->draft_id, $share->hash ) );
	}

	/**
	 * An empty hash never unlocks anything.
	 */
	public function test_hash_unlocks_rejects_empty() {
		$this->assertFalse( WP_DraftsForFriends_Shares::hash_unlocks( $this->draft_id, '' ) );
	}

	/**
	 * Only ORDER BY columns from the allow list reach the query.
	 */
	public function test_query_rejects_arbitrary_orderby() {
		wp_set_current_user( $this->author_id );
		WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		// If either of these reached the SQL the query would error and return no
		// rows, so getting the row back is the assertion.
		$this->assertCount( 1, WP_DraftsForFriends_Shares::query( 'id; DROP TABLE wp_posts', 'asc' ) );
		$this->assertCount( 1, WP_DraftsForFriends_Shares::query( 'date_created', "asc'--" ) );

		$this->assertNotEmpty( get_post( $this->draft_id ), 'wp_posts survived' );
	}

	/**
	 * The share URL carries the post and the hash.
	 */
	public function test_url() {
		wp_set_current_user( $this->author_id );

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];
		$url   = WP_DraftsForFriends_Shares::url( $share );

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

		foreach ( WP_DraftsForFriends_Shares::shareable_posts() as $group ) {
			foreach ( $group['posts'] as $post ) {
				$ids[] = $post->ID;
			}
		}

		$this->assertContains( $this->draft_id, $ids );
		$this->assertNotContains( $this->editor_draft_id, $ids, "an author was offered someone else's draft" );

		wp_set_current_user( $this->editor_id );

		$ids = array();

		foreach ( WP_DraftsForFriends_Shares::shareable_posts() as $group ) {
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

		foreach ( WP_DraftsForFriends_Shares::shareable_posts() as $group ) {
			foreach ( $group['posts'] as $post ) {
				$this->assertNotSame( $published, $post->ID );
			}
		}
	}

	/**
	 * The count() and query() methods must always agree.
	 *
	 * The count used to read the bare table while query() joined wp_posts, so a
	 * share whose post had been hard-deleted inflated the total without ever
	 * appearing in the list. That threw off the item count above the list table
	 * and left its last page short.
	 */
	public function test_count_agrees_with_query_for_an_orphaned_share() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		$orphan_post = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
			)
		);

		$orphan = WP_DraftsForFriends_Shares::create( $orphan_post, 1, 'h' )['shared'];

		// Strand the row the way a pre-2.0.0 install would have: delete the post
		// straight from the table, behind the deleted_post hook.
		$wpdb->delete( $wpdb->posts, array( 'ID' => $orphan_post ), array( '%d' ) );
		clean_post_cache( $orphan_post );

		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->draftsforfriends} WHERE id = %d", $orphan->id ) ), 'the orphan row is still there' );

		$this->assertSame(
			count( WP_DraftsForFriends_Shares::query( 'date_created', 'desc', 0, 100 ) ),
			WP_DraftsForFriends_Shares::count(),
			'the count must match the number of rows the list can actually show'
		);
	}

	/**
	 * Deleting a post takes its shares with it.
	 */
	public function test_deleting_a_post_deletes_its_shares() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );
		WP_DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' );

		wp_delete_post( $this->draft_id, true );

		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE post_id = %d", $this->draft_id ) )
		);
	}

	/**
	 * Trashing a post leaves its shares in place, because trashing is reversible.
	 */
	public function test_trashing_a_post_keeps_its_shares() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' );

		wp_trash_post( $this->draft_id );

		$this->assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE post_id = %d", $this->draft_id ) )
		);
	}

	/**
	 * Sorting orders the rows, rather than merely not erroring.
	 */
	public function test_query_actually_sorts() {
		wp_set_current_user( $this->author_id );

		$first = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
				'post_title'  => 'AAA First',
			)
		);

		$last = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
				'post_title'  => 'ZZZ Last',
			)
		);

		WP_DraftsForFriends_Shares::create( $first, 1, 'h' );
		WP_DraftsForFriends_Shares::create( $last, 5, 'h' );

		$asc = WP_DraftsForFriends_Shares::query( 'post_title', 'asc', 0, 50 );
		$this->assertSame( 'AAA First', $asc[0]->post_title );

		$desc = WP_DraftsForFriends_Shares::query( 'post_title', 'desc', 0, 50 );
		$this->assertSame( 'ZZZ Last', $desc[0]->post_title );

		// A longer share sorts last by expiry ascending.
		$by_expiry = WP_DraftsForFriends_Shares::query( 'date_expired', 'asc', 0, 50 );
		$this->assertSame( 'ZZZ Last', end( $by_expiry )->post_title );
	}

	/**
	 * Paging returns distinct rows rather than repeating the first page.
	 */
	public function test_query_pages() {
		wp_set_current_user( $this->author_id );

		for ( $i = 0; $i < 5; $i++ ) {
			WP_DraftsForFriends_Shares::create( $this->draft_id, $i + 1, 'h' );
		}

		$page_one = wp_list_pluck( WP_DraftsForFriends_Shares::query( 'id', 'asc', 0, 2 ), 'id' );
		$page_two = wp_list_pluck( WP_DraftsForFriends_Shares::query( 'id', 'asc', 2, 2 ), 'id' );

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertSame( array(), array_intersect( $page_one, $page_two ) );
	}

	/**
	 * A share for a post that no longer exists is not returned by get().
	 */
	public function test_get_ignores_an_orphaned_share() {
		global $wpdb;

		wp_set_current_user( $this->author_id );

		$share = WP_DraftsForFriends_Shares::create( $this->draft_id, 1, 'h' )['shared'];

		$wpdb->delete( $wpdb->posts, array( 'ID' => $this->draft_id ), array( '%d' ) );
		clean_post_cache( $this->draft_id );

		$this->assertNull( WP_DraftsForFriends_Shares::get( $share->id ) );
	}
}
