<?php
/**
 * WP-DraftsForFriends class-draftsforfriends-shares.php
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
class DraftsForFriends_Shares {

	/**
	 * Columns the list table is allowed to sort on.
	 *
	 * Interpolated into ORDER BY, so this allow list is the only thing standing
	 * between a query argument and the SQL. Identifiers cannot be bound.
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
	 * SQL restricting rows to the current user, unless they may edit others' posts.
	 *
	 * Returned with a leading space so it can be concatenated onto a WHERE clause.
	 * The value is an integer from get_current_user_id(), never request data.
	 *
	 * @return string
	 */
	private static function scope() {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return '';
		}

		return ' AND user_id = ' . get_current_user_id();
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope() emits an integer from get_current_user_id().
		return $wpdb->get_row( $wpdb->prepare( "SELECT d.*, p.post_title AS post_title FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE d.id = %d" . self::scope(), $id ) );
	}

	/**
	 * How many shares the current user may see.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope() emits an integer from get_current_user_id().
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE 1=1" . self::scope() );
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
		$order   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		// $orderby and $order are both constrained to literals above; neither can
		// be bound as a placeholder, and %i needs WP 6.2 while this plugin is 6.0.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see above.
		return $wpdb->get_results( $wpdb->prepare( "SELECT d.*, p.post_title AS post_title FROM {$wpdb->draftsforfriends} d INNER JOIN {$wpdb->posts} p ON d.post_id = p.ID WHERE 1=1" . self::scope() . " ORDER BY {$orderby} {$order} LIMIT %d, %d", $offset, $limit ) );
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

		return array(
			/* translators: %s: post title. */
			'success' => sprintf( __( 'Shared draft for \'%s\' created', 'wp-draftsforfriends' ), $post->post_title ),
			'shared'  => self::get( (int) $wpdb->insert_id ),
			'count'   => number_format_i18n( self::count() ),
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
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope() emits an integer from get_current_user_id().
				"UPDATE {$wpdb->draftsforfriends} SET date_extended = %s, date_expired = %s WHERE id = %d" . self::scope(),
				current_time( 'mysql', 1 ),
				gmdate( 'Y-m-d H:i:s', $new_expiry ),
				$id
			)
		);

		if ( ! $updated ) {
			return array( 'error' => __( 'Error extending shared draft', 'wp-draftsforfriends' ) );
		}

		return array(
			'success' => __( 'Shared draft extended', 'wp-draftsforfriends' ),
			'shared'  => self::get( $id ),
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope() emits an integer from get_current_user_id().
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->draftsforfriends} WHERE id = %d" . self::scope(), $id ) );

		if ( ! $deleted ) {
			return array( 'error' => __( 'Error deleting shared draft', 'wp-draftsforfriends' ) );
		}

		return array(
			'success' => __( 'Shared draft deleted', 'wp-draftsforfriends' ),
			'shared'  => $share,
			'count'   => number_format_i18n( self::count() ),
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
	 * @param object $share Share row.
	 * @return string
	 */
	public static function url( $share ) {
		return home_url( '/?p=' . (int) $share->post_id . '&draftsforfriends=' . rawurlencode( $share->hash ) );
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
				'post_type'   => 'post',
				'post_status' => $status,
				'numberposts' => -1,
				'orderby'     => 'modified',
				'order'       => 'DESC',
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
