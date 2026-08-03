<?php
/**
 * Every read and write of the shared drafts table.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every read and write of the shared drafts table.
 *
 * Replaces the query methods that were scattered through the single
 * WPDraftsForFriends class before 2.0.0. Capability scoping lives here, in one
 * place, rather than being repeated at each call site.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends_Shares {

	/**
	 * The query argument carrying the hash.
	 *
	 * Named once because two halves of the plugin have to agree about it:
	 * url() writes it, and WP_DraftsForFriends_Preview reads it back off the
	 * request. They each held the string separately, so the link a friend was
	 * given and the check that lets them read it agreed only by coincidence.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'draftsforfriends';

	/**
	 * Columns the list table is allowed to sort on.
	 *
	 * The column is bound with prepare()'s %i placeholder in query(), which quotes
	 * an identifier but does not check it names a column this table has -- so this
	 * allow list still stands between a query argument and the SQL.
	 *
	 * @var array
	 */
	const SORTABLE = array( 'id', 'post_title', 'date_created', 'date_extended', 'date_expired' );

	/**
	 * Units a share may be measured in, and their length in seconds.
	 *
	 * @var array
	 */
	const UNITS = array(
		's' => 1,
		'm' => 60,
		'h' => HOUR_IN_SECONDS,
		'd' => DAY_IN_SECONDS,
	);

	/**
	 * The durations a share can be measured in.
	 *
	 * Here rather than on the admin class, which is where it sat before 2.0.0:
	 * the keys are UNITS' keys, so the labels belong beside the list they label
	 * and neither the settings screen nor the list table has to reach into an
	 * admin-only class for them.
	 *
	 * @return array Unit key to translated label.
	 */
	public static function measures() {
		return array(
			's' => __( 'seconds', 'wp-draftsforfriends' ),
			'm' => __( 'minutes', 'wp-draftsforfriends' ),
			'h' => __( 'hours', 'wp-draftsforfriends' ),
			'd' => __( 'days', 'wp-draftsforfriends' ),
		);
	}

	/**
	 * How long a share lasts, in seconds.
	 *
	 * Falls back to a minute for a non-positive duration and to minutes for an
	 * unrecognised unit, which is what the plugin has always done.
	 *
	 * @param int    $value Duration.
	 * @param string $unit  One of s, m, h, d.
	 * @return int Seconds.
	 */
	public static function calculate_expiry( $value, $unit ) {
		$expiry   = (int) $value > 0 ? (int) $value : 60;
		$multiply = isset( self::UNITS[ $unit ] ) ? self::UNITS[ $unit ] : 60;

		return $expiry * $multiply;
	}

	/**
	 * Human-readable time remaining on a share.
	 *
	 * @param string $date MySQL datetime, GMT.
	 * @return string
	 */
	public static function countdown( $date ) {
		$output    = array();
		$time_left = (int) mysql2date( 'G', $date ) - time();

		if ( 0 >= $time_left ) {
			return __( 'Expired', 'wp-draftsforfriends' );
		}

		if ( DAY_IN_SECONDS <= $time_left ) {
			$days_left = (int) floor( $time_left / DAY_IN_SECONDS );

			if ( 0 < $days_left ) {
				/* translators: %d: number of days. */
				$output[] = sprintf( _n( '%d day', '%d days', $days_left, 'wp-draftsforfriends' ), $days_left );
			}
		}

		if ( HOUR_IN_SECONDS <= $time_left ) {
			$hours_left = (int) floor( ( $time_left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );

			if ( 0 < $hours_left ) {
				/* translators: %d: number of hours. */
				$output[] = sprintf( _n( '%d hour', '%d hours', $hours_left, 'wp-draftsforfriends' ), $hours_left );
			}
		}

		if ( MINUTE_IN_SECONDS <= $time_left ) {
			$minutes_left = (int) floor( ( $time_left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

			if ( 0 < $minutes_left ) {
				/* translators: %d: number of minutes. */
				$output[] = sprintf( _n( '%d minute', '%d minutes', $minutes_left, 'wp-draftsforfriends' ), $minutes_left );
			}
		} else {
			/* translators: %d: number of seconds. */
			$output[] = sprintf( _n( '%d second', '%d seconds', $time_left, 'wp-draftsforfriends' ), $time_left );
		}

		return implode( ', ', $output );
	}

	/**
	 * Whether the current user may see every share on the site.
	 *
	 * Returned as 1 or 0 rather than a boolean so it can be bound with %d into
	 * the `( %d = 1 OR d.user_id = %d )` clause every query below carries. That
	 * shape is what lets each query be one complete literal string with every
	 * value bound: before 2.0.0 the scope was concatenated on as a pre-built
	 * SQL fragment, which meant $wpdb->prepare() was handed an expression rather
	 * than a literal and four call sites needed a suppression to say so.
	 *
	 * @return int 1 when the user may see everything, 0 when scoped to their own.
	 */
	private static function sees_every_share() {
		return current_user_can( 'edit_others_posts' ) ? 1 : 0;
	}

	/**
	 * A single share, scoped to what the current user may see.
	 *
	 * @param int $id Share ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( 0 >= $id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT d.*, p.post_title AS post_title FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE d.id = %d AND ( %d = 1 OR d.user_id = %d )",
				$id,
				self::sees_every_share(),
				get_current_user_id()
			)
		);
	}

	/**
	 * How many shares the current user may see.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// The same INNER JOIN as query(), so the two can never disagree. Counting
		// the bare table let a share whose post had been deleted inflate the total
		// while never appearing in the list, which threw off the item count and
		// left the last page of the list table short.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE ( %d = 1 OR d.user_id = %d )",
				self::sees_every_share(),
				get_current_user_id()
			)
		);
	}

	/**
	 * Drop every share for a post that has been deleted for good.
	 *
	 * Hooked to deleted_post. Trashing deliberately does not come through here:
	 * it is reversible, so the share is only withheld while the post sits in the
	 * trash and works again if the post is restored.
	 *
	 * @param int $post_id Post that was deleted.
	 * @return void
	 */
	public static function delete_for_post( $post_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->draftsforfriends, array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * A page of shares.
	 *
	 * @param string $orderby One of self::SORTABLE.
	 * @param string $order   asc or desc.
	 * @param int    $offset  Rows to skip.
	 * @param int    $limit   Rows to return.
	 * @return array
	 */
	public static function query( $orderby = 'date_created', $order = 'desc', $offset = 0, $limit = 20 ) {
		global $wpdb;

		$orderby = in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'date_created';

		// The column goes through prepare()'s %i identifier placeholder, which
		// needs WordPress 6.2 and so was unavailable while the floor was 6.0.
		// The allow list above still stands: %i quotes an identifier but does not
		// check it is a column this table has.
		//
		// The direction cannot be bound at all -- ASC and DESC are syntax, not
		// values -- so it picks between two complete literal statements rather
		// than being interpolated into one.
		if ( 'asc' === strtolower( (string) $order ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.*, p.post_title AS post_title FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE ( %d = 1 OR d.user_id = %d ) ORDER BY %i ASC LIMIT %d, %d",
					self::sees_every_share(),
					get_current_user_id(),
					$orderby,
					$offset,
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, p.post_title AS post_title FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE ( %d = 1 OR d.user_id = %d ) ORDER BY %i DESC LIMIT %d, %d",
				self::sees_every_share(),
				get_current_user_id(),
				$orderby,
				$offset,
				$limit
			)
		);
	}

	/**
	 * Share a draft.
	 *
	 * @param int    $post_id Post to share.
	 * @param int    $expires Duration.
	 * @param string $measure Unit.
	 * @return array Response array carrying either a success or an error key.
	 */
	public static function create( $post_id, $expires, $measure ) {
		global $wpdb;

		$post_id = (int) $post_id;

		if ( 0 >= $post_id ) {
			return array( 'error' => __( 'Please choose a draft to share', 'wp-draftsforfriends' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'There is no such post!', 'wp-draftsforfriends' ) );
		}

		if ( 'publish' === get_post_status( $post ) ) {
			/* translators: %s: post title. */
			return array( 'error' => sprintf( __( 'The post \'%s\' is published!', 'wp-draftsforfriends' ), $post->post_title ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => __( 'You do not have permission to create shared draft for this post.', 'wp-draftsforfriends' ) );
		}

		$wpdb->insert(
			$wpdb->draftsforfriends,
			array(
				'post_id'      => $post->ID,
				'user_id'      => get_current_user_id(),
				'hash'         => wp_generate_password( 32, false, false ),
				'date_created' => current_time( 'mysql', 1 ),
				'date_expired' => gmdate( 'Y-m-d H:i:s', time() + self::calculate_expiry( $expires, $measure ) ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $wpdb->insert_id ) {
			/* translators: %s: post title. */
			return array( 'error' => sprintf( __( 'Error creating shared draft for \'%s\'', 'wp-draftsforfriends' ), $post->post_title ) );
		}

		$share = self::get( (int) $wpdb->insert_id );

		/**
		 * Fires after a draft has been shared.
		 *
		 * After the row exists, so the share passed here is the stored one
		 * rather than what the caller asked for.
		 *
		 * @since 2.0.0
		 *
		 * @param object  $share The share row.
		 * @param WP_Post $post  The post it shares.
		 */
		do_action( 'wp_draftsforfriends_share_created', $share, $post );

		return array(
			/* translators: %s: post title. */
			'success' => sprintf( __( 'Shared draft for \'%s\' created', 'wp-draftsforfriends' ), $post->post_title ),
			'shared'  => $share,
		);
	}

	/**
	 * Push a share's expiry further out.
	 *
	 * @param int    $id      Share ID.
	 * @param int    $expires Duration.
	 * @param string $measure Unit.
	 * @return array Response array carrying either a success or an error key.
	 */
	public static function extend( $id, $expires, $measure ) {
		global $wpdb;

		$id = (int) $id;

		// The row is the authority on which post is being extended. Reading the
		// post id from the request instead let a caller pair someone else's share
		// id with a post they happened to be able to edit.
		$share = self::get( $id );

		if ( empty( $share ) ) {
			return array( 'error' => __( 'There is no such shared draft!', 'wp-draftsforfriends' ) );
		}

		$post = get_post( $share->post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'There is no such post!', 'wp-draftsforfriends' ) );
		}

		if ( 'publish' === get_post_status( $post ) ) {
			/* translators: %s: post title. */
			return array( 'error' => sprintf( __( 'The post \'%s\' is published!', 'wp-draftsforfriends' ), $post->post_title ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => __( 'You do not have permission to extend shared draft for this post.', 'wp-draftsforfriends' ) );
		}

		$duration = self::calculate_expiry( $expires, $measure );
		$expired  = (int) mysql2date( 'G', $share->date_expired );

		// An already-expired share restarts from now, so extending it by an hour
		// gives an hour rather than a moment in the past.
		$new_expiry = time() >= $expired ? time() + $duration : $expired + $duration;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->draftsforfriends} SET date_extended = %s, date_expired = %s WHERE id = %d AND ( %d = 1 OR user_id = %d )",
				current_time( 'mysql', 1 ),
				gmdate( 'Y-m-d H:i:s', $new_expiry ),
				$id,
				self::sees_every_share(),
				get_current_user_id()
			)
		);

		if ( ! $updated ) {
			return array( 'error' => __( 'Error extending shared draft', 'wp-draftsforfriends' ) );
		}

		$extended = self::get( $id );

		/**
		 * Fires after a share's expiry has been pushed further out.
		 *
		 * The share is re-read first, so `date_expired` is the new one. The
		 * previous expiry is passed alongside it because it cannot be recovered
		 * afterwards and a listener wanting to say "extended by an hour" needs
		 * both ends.
		 *
		 * @since 2.0.0
		 *
		 * @param object $share        The share row, as it now stands.
		 * @param string $was_expiring The expiry it carried before, MySQL format.
		 */
		do_action( 'wp_draftsforfriends_share_extended', $extended, $share->date_expired );

		return array(
			'success' => __( 'Shared draft extended', 'wp-draftsforfriends' ),
			'shared'  => $extended,
		);
	}

	/**
	 * Revoke a share.
	 *
	 * @param int $id Share ID.
	 * @return array Response array carrying either a success or an error key.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		// As in extend(): the row decides which post's capability is checked.
		$share = self::get( $id );

		if ( empty( $share ) ) {
			return array( 'error' => __( 'There is no such shared draft!', 'wp-draftsforfriends' ) );
		}

		if ( ! current_user_can( 'edit_post', $share->post_id ) ) {
			return array( 'error' => __( 'You do not have permission to delete the shared draft for this post.', 'wp-draftsforfriends' ) );
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->draftsforfriends} WHERE id = %d AND ( %d = 1 OR user_id = %d )",
				$id,
				self::sees_every_share(),
				get_current_user_id()
			)
		);

		if ( ! $deleted ) {
			return array( 'error' => __( 'Error deleting shared draft', 'wp-draftsforfriends' ) );
		}

		/**
		 * Fires after a share has been revoked.
		 *
		 * The row is already gone, so this is the last chance to see it. The
		 * link it describes stops working the moment this fires.
		 *
		 * @since 2.0.0
		 *
		 * @param object $share The share row as it was before deletion.
		 */
		do_action( 'wp_draftsforfriends_share_revoked', $share );

		return array(
			'success' => __( 'Shared draft deleted', 'wp-draftsforfriends' ),
			'shared'  => $share,
		);
	}

	/**
	 * Whether a hash currently unlocks a post.
	 *
	 * Deliberately unscoped by user: the whole point is that a logged-out friend
	 * can read the post.
	 *
	 * @param int    $post_id Post being requested.
	 * @param string $hash    Hash from the URL.
	 * @return bool
	 */
	public static function hash_unlocks( $post_id, $hash ) {
		global $wpdb;

		if ( '' === $hash ) {
			return false;
		}

		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE post_id = %d AND hash = %s AND date_expired >= %s",
				(int) $post_id,
				$hash,
				current_time( 'mysql', 1 )
			)
		);

		return 1 === $found;
	}

	/**
	 * The URL a friend is given.
	 *
	 * `?p=<id>` rather than the permalink, deliberately: the preview only works
	 * on the query WordPress runs for a bare post id, because that fetches the
	 * row by ID and hands it to `posts_results` before dropping it for being
	 * unpublished, which is the moment the preview exists to catch. A permalink
	 * looks the post up by slug among the *public* statuses, so an unpublished
	 * post is never in the result set at all.
	 *
	 * A site that filters this into a shape of its own **must** filter
	 * `wp_draftsforfriends_requested_hash` to match, or the link will carry a
	 * hash the plugin cannot find and the friend will get a 404. The two are one
	 * contract: this writes the URL, that reads it back.
	 *
	 * @since 2.0.0
	 *
	 * @param object $share Share row.
	 * @return string
	 */
	public static function url( $share ) {
		$url = home_url( '/?p=' . (int) $share->post_id . '&' . self::QUERY_VAR . '=' . rawurlencode( $share->hash ) );

		/**
		 * Filters the URL a friend is given for a shared draft.
		 *
		 * Pairs with `wp_draftsforfriends_requested_hash`. Changing the shape of
		 * the link without teaching the plugin to read the new shape back leaves
		 * the friend with a 404.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url   The share URL.
		 * @param object $share The share row it was built from.
		 */
		return (string) apply_filters( 'wp_draftsforfriends_share_url', $url, $share );
	}

	/**
	 * The current user's shareable posts, grouped by status.
	 *
	 * @return array Groups of label, count and posts.
	 */
	public static function shareable_posts() {
		$groups = array(
			'draft'   => __( 'Drafts:', 'wp-draftsforfriends' ),
			'future'  => __( 'Scheduled Posts:', 'wp-draftsforfriends' ),
			'pending' => __( 'Pending Review:', 'wp-draftsforfriends' ),
		);

		$out = array();

		foreach ( $groups as $status => $label ) {
			$args = array(
				'post_type'              => 'post',
				'post_status'            => $status,
				'numberposts'            => -1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',

				// Only the ID and the title are ever read, and this is an
				// unbounded query. Priming the meta and term caches for every
				// draft on the site to build a dropdown of titles is pure
				// waste, and on a site with a few thousand drafts it is the
				// expensive part of loading the screen.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			// An author sees only their own; anyone who may edit others' posts
			// sees everything. The key is omitted rather than emptied, because
			// WP_Query treats an empty author differently from an absent one.
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$args['author'] = get_current_user_id();
			}

			$posts = get_posts( $args );

			$out[] = array(
				'label' => $label,
				'count' => count( $posts ),
				'posts' => $posts,
			);
		}

		return $out;
	}
}
