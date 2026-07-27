<?php
/**
 * WP-DraftsForFriends class-draftsforfriends-preview.php
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a logged-out friend read an unpublished post they hold a link to.
 *
 * WordPress runs the query for ?p=<id> and hands the row to `posts_results`,
 * then empties it before `the_posts` because the status is not public and the
 * visitor is not logged in. This class catches the post on the way past and
 * puts it back, but only when the URL carries a hash that currently unlocks it.
 *
 * @since 2.0.0
 */
class DraftsForFriends_Preview {

	/**
	 * The post captured by the query currently running, if any.
	 *
	 * @var WP_Post|null
	 */
	private $captured;

	/**
	 * Register the filters.
	 */
	public function __construct() {
		add_filter( 'posts_results', array( $this, 'capture' ) );
		add_filter( 'the_posts', array( $this, 'restore' ) );
	}

	/**
	 * Statuses a share link must never serve.
	 *
	 * `publish` because the post is public already and WordPress serves it
	 * itself; `trash` and `auto-draft` because neither is something the author
	 * still means to show anyone. Trashing a shared draft used to leave the link
	 * working, which made the most obvious way to withdraw a draft do nothing.
	 *
	 * Anything else unpublished is fair game, so a share survives a draft being
	 * scheduled or made private, and custom statuses keep working.
	 *
	 * @return array
	 */
	private static function denied_statuses() {
		return array( 'publish', 'trash', 'auto-draft' );
	}

	/**
	 * The hash from the request, if there is one.
	 *
	 * @return string
	 */
	private function requested_hash() {
		// A public read-only check on a link handed to someone who is not logged
		// in. There is no form and no state change here, so there is no nonce to
		// verify; the hash is the credential.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['draftsforfriends'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET['draftsforfriends'] ) );
	}

	/**
	 * Remember an unpublished post the request is entitled to read.
	 *
	 * @param array $posts Posts the query found.
	 * @return array Unmodified.
	 */
	public function capture( $posts ) {
		// Reset per query. Leaving the previous query's post in place is how this
		// used to hand an unlocked draft to every later query in the same request
		// that legitimately returned nothing.
		$this->captured = null;

		if ( ! is_array( $posts ) || 1 !== count( $posts ) ) {
			return $posts;
		}

		if ( ! isset( $posts[0]->ID ) ) {
			return $posts;
		}

		$post = $posts[0];

		if ( in_array( get_post_status( $post ), self::denied_statuses(), true ) ) {
			return $posts;
		}

		$hash = $this->requested_hash();

		if ( '' === $hash || ! DraftsForFriends_Shares::hash_unlocks( $post->ID, $hash ) ) {
			return $posts;
		}

		// A shared draft is not a place to collect comments.
		$post->comment_status = 'closed';

		$this->captured = $post;

		return $posts;
	}

	/**
	 * Put the captured post back after WordPress has dropped it.
	 *
	 * @param array $posts Posts remaining after core's status check.
	 * @return array
	 */
	public function restore( $posts ) {
		// Consume it: the post belongs to the query that just ran through
		// capture(), and to no other.
		$captured       = $this->captured;
		$this->captured = null;

		if ( empty( $posts ) && ! empty( $captured ) ) {
			return array( $captured );
		}

		return $posts;
	}
}
