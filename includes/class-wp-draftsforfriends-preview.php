<?php
/**
 * Serving a shared draft to a friend.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

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
class WP_DraftsForFriends_Preview {

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
		add_filter( 'redirect_canonical', array( $this, 'keep_the_share_url' ) );
	}

	/**
	 * Stop WordPress rewriting a share link into the post's pretty permalink.
	 *
	 * Everything above depends on the query being the one `?p=<id>` produces:
	 * WordPress fetches that row by ID, hands it to `posts_results`, and only
	 * then drops it for being unpublished, which is the moment capture() exists
	 * to catch. `redirect_canonical()` sends `?p=<id>` to the post's permalink
	 * on any site with a permalink structure, and the query that arrives there
	 * looks the post up **by slug among the public statuses** -- so an
	 * unpublished post is not in the result set at all, `posts_results` never
	 * sees it, and there is nothing to put back. The friend gets a 404.
	 *
	 * That is every ordinary site. WordPress turns pretty permalinks on during
	 * installation whenever the server can rewrite, so the only sites where
	 * share links worked were the ones left on plain permalinks -- which is also
	 * the only shape the end-to-end suite ever ran against, so it passed
	 * throughout while the feature was broken in the field.
	 *
	 * Suppressed only for a request carrying a hash that currently unlocks the
	 * post it names. A wrong hash, an expired one or a link to something else
	 * redirects exactly as it did before, so this cannot be used to keep an
	 * ugly URL alive for anything a friend is not entitled to read.
	 *
	 * @since 2.0.0
	 *
	 * @param string|false $redirect_url Where WordPress means to send the request.
	 * @return string|false The redirect, or false to stay put.
	 */
	public function keep_the_share_url( $redirect_url ) {
		$hash = $this->requested_hash();

		if ( '' === $hash ) {
			return $redirect_url;
		}

		$post_id = (int) get_query_var( 'p' );

		if ( $post_id <= 0 || ! WP_DraftsForFriends_Shares::hash_unlocks( $post_id, $hash ) ) {
			return $redirect_url;
		}

		return false;
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
	 * The other half of the contract `WP_DraftsForFriends_Shares::url()` opens:
	 * that writes the link, this reads it back. Both name the query argument
	 * through one constant so the default shape is defined in a single place,
	 * and both are filterable so a site can move to a shape of its own -- but
	 * only as a pair. A rewritten URL whose hash this cannot find is a 404 for
	 * the friend, and nothing in the admin screens would look wrong.
	 *
	 * Whatever a filter returns is sanitised here rather than trusted: this
	 * value is the credential the preview is gated on.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function requested_hash() {
		$key = WP_DraftsForFriends_Shares::QUERY_VAR;

		// Sanitised at the point of access rather than after the filter, so the
		// superglobal is never held raw in a variable.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only check on a link handed to someone who is not logged in; there is no form to carry a nonce and the hash is itself the credential.
		$raw = empty( $_GET[ $key ] ) ? '' : sanitize_text_field( wp_unslash( $_GET[ $key ] ) );

		/**
		 * Filters the hash the plugin believes this request is presenting.
		 *
		 * Pairs with `wp_draftsforfriends_share_url`. A site that rewrites the
		 * share link into a shape of its own reads the hash back out here.
		 *
		 * @since 2.0.0
		 *
		 * @param string $raw The hash as found in the request, or '' if absent.
		 */
		$raw = apply_filters( 'wp_draftsforfriends_requested_hash', $raw );

		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
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

		if ( '' === $hash || ! WP_DraftsForFriends_Shares::hash_unlocks( $post->ID, $hash ) ) {
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
