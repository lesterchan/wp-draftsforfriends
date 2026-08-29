<?php
/**
 * The post editor's meta box.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows a post's share links in the editor, and creates one on demand.
 *
 * The box sits inside the post form and cannot carry a form of its own, and a
 * GET link that creates state is a prefetch hazard, so a create is either a
 * `fetch()` to `admin-ajax.php` or a checkbox that rides the editor's own save.
 * The button is the control on a post that has a status to share; the checkbox
 * is the one that works on a post that does not yet, and is the fallback with
 * JavaScript off. Registered for the built-in post type only: the share URL is
 * `?p=<id>`, which WordPress answers for posts alone.
 *
 * @since 2.0.1
 */
class WP_DraftsForFriends_Metabox {

	/**
	 * The meta box id.
	 *
	 * @var string
	 */
	const ID = 'wp-draftsforfriends';

	/**
	 * Nonce action for the create-on-save control.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_draftsforfriends_metabox';

	/**
	 * The field the nonce travels in. Not _wpnonce: inside the editor's form a
	 * second field of that name would replace the post's own nonce.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'draftsforfriends_metabox_nonce';

	/**
	 * The admin-ajax.php action the create button posts to.
	 *
	 * Logged-in only, and deliberately: there is no nopriv twin because every
	 * caller is somebody already editing the post.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'draftsforfriends_create_share';

	/**
	 * Nonce action for the create button.
	 *
	 * Separate from the save's, because the two travel differently -- this one
	 * is localised for the script, that one is a hidden field in the post form
	 * -- and a single action would tie their lifetimes together.
	 *
	 * @var string
	 */
	const AJAX_NONCE_ACTION = 'wp_draftsforfriends_create_share';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_create' ) );
	}

	/**
	 * Register the box, behind the same capability filter as the screen.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		if ( ! current_user_can( WP_DraftsForFriends_Admin::capability( 'shares' ) ) ) {
			return;
		}

		add_meta_box(
			self::ID,
			__( 'Drafts for Friends', 'wp-draftsforfriends' ),
			array( __CLASS__, 'render' ),
			'post',
			'side'
		);
	}

	/**
	 * Load the screen's assets on the editor, where the copy button needs them.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		if ( ! current_user_can( WP_DraftsForFriends_Admin::capability( 'shares' ) ) ) {
			return;
		}

		WP_DraftsForFriends_Admin::enqueue_assets();
	}

	/**
	 * Render the box.
	 *
	 * A published post gets a sentence rather than controls: the preview
	 * refuses to serve its links, so a copy button here would hand out 404s.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render( $post ) {
		if ( 'publish' === get_post_status( $post ) ) {
			echo '<p>' . esc_html__( 'This post is published, so anybody can read it and its share links have stopped working. They start working again if the post leaves the published status.', 'wp-draftsforfriends' ) . '</p>';

			return;
		}

		self::render_shares( WP_DraftsForFriends_Shares::for_post( $post->ID ) );
		self::render_create_controls( $post );
		?>
		<p>
			<a href="<?php echo esc_url( WP_DraftsForFriends_Admin::page_url() ); ?>"><?php esc_html_e( 'Manage all shared drafts', 'wp-draftsforfriends' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The links this post already has, each with the copy button.
	 *
	 * @param array $shares Share rows for the post.
	 * @return void
	 */
	private static function render_shares( array $shares ) {
		/*
		 * Both are rendered even with nothing in them: the create button
		 * prepends its new link here and unhides the heading, and markup the
		 * script built instead would be a second copy of this to keep in step.
		 */
		?>
		<h4 class="draftsforfriends-metabox-heading" id="draftsforfriends-metabox-links-heading" <?php echo empty( $shares ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Share Links', 'wp-draftsforfriends' ); ?></h4>
		<ul class="draftsforfriends-metabox-shares">
			<?php foreach ( $shares as $share ) : ?>
				<?php
				$url     = WP_DraftsForFriends_Shares::url( $share );
				$expired = (int) mysql2date( 'G', $share->date_expired ) <= time();
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a>
					<span class="description">
						<?php if ( $expired ) : ?>
							<?php esc_html_e( 'Expired', 'wp-draftsforfriends' ); ?>
						<?php else : ?>
							<?php
							/* translators: %s: time remaining, e.g. "2 hours, 3 minutes". */
							echo esc_html( sprintf( __( 'Expires in %s', 'wp-draftsforfriends' ), WP_DraftsForFriends_Shares::countdown( $share->date_expired ) ) );
							?>
						<?php endif; ?>
					</span>
					<button type="button" class="button button-small hide-if-no-js draftsforfriends-copy" data-link="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Copy Link', 'wp-draftsforfriends' ); ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The duration, and the two controls that create a link with it.
	 *
	 * Both are always rendered and only one is ever shown, because which one
	 * belongs depends on two things the server cannot both know at once:
	 * whether the reader has JavaScript, and whether the post still has the
	 * `auto-draft` status WordPress gives a post the moment the editor is
	 * opened. On an `auto-draft` the button would create a link the preview
	 * refuses to serve -- `auto-draft` is one of its denied statuses -- while
	 * the checkbox is exactly right, because the save that carries it moves the
	 * post to `draft` before the share is written. So the checkbox is the
	 * control on a brand-new post and the fallback with JavaScript off, and the
	 * button is the control everywhere else.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	private static function render_create_controls( $post ) {
		$expires = (int) WP_DraftsForFriends_Options::get( 'expires' );
		$measure = (string) WP_DraftsForFriends_Options::get( 'measure' );
		$unsaved = 'auto-draft' === get_post_status( $post );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h4 class="draftsforfriends-metabox-heading"><?php esc_html_e( 'New Share Link', 'wp-draftsforfriends' ); ?></h4>
		<p class="draftsforfriends-metabox-duration">
			<label for="draftsforfriends-metabox-expires"><?php esc_html_e( 'Share it for:', 'wp-draftsforfriends' ); ?></label>
			<input name="draftsforfriends_expires" id="draftsforfriends-metabox-expires" type="number" min="1" max="9999" step="1" value="<?php echo esc_attr( $expires ); ?>" class="small-text" />
			<?php // The unit needs a label of its own or it is announced unlabelled. ?>
			<label class="screen-reader-text" for="draftsforfriends-metabox-measure"><?php esc_html_e( 'Duration unit', 'wp-draftsforfriends' ); ?></label>
			<select name="draftsforfriends_measure" id="draftsforfriends-metabox-measure">
				<?php foreach ( WP_DraftsForFriends_Shares::measures() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $measure, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="draftsforfriends-create-now hide-if-no-js<?php echo $unsaved ? ' hidden' : ''; ?>">
			<button type="button" class="button draftsforfriends-create" data-post="<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Create Share Link', 'wp-draftsforfriends' ); ?></button>
		</p>
		<p class="draftsforfriends-create-on-save<?php echo $unsaved ? '' : ' hide-if-js'; ?>">
			<label for="draftsforfriends-metabox-create">
				<input type="checkbox" name="draftsforfriends_create" id="draftsforfriends-metabox-create" value="1" />
				<?php esc_html_e( 'Create a share link when this post is saved', 'wp-draftsforfriends' ); ?>
			</label>
		</p>
		<?php // Messages belong in the box: the editor's own .wrap is not on the screen the block editor draws. ?>
		<p id="draftsforfriends-metabox-message" role="alert" hidden></p>
		<?php
	}

	/**
	 * Create a share for the post being edited, and describe it back.
	 *
	 * Loud on refusal, unlike save(): nothing else is riding on this request,
	 * so the box can say why rather than leaving the reader to work it out from
	 * a link that did not appear.
	 *
	 * @return void
	 */
	public static function ajax_create() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( WP_DraftsForFriends_Admin::capability( 'shares' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create shared draft for this post.', 'wp-draftsforfriends' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$expires = isset( $_POST['expires'] ) ? (int) $_POST['expires'] : 0;
		$measure = isset( $_POST['measure'] ) ? sanitize_key( wp_unslash( $_POST['measure'] ) ) : '';

		/*
		 * create() refuses a published post and one the caller cannot edit, but
		 * not an auto-draft: the checkbox path legitimately creates on one,
		 * because by the time it runs the save has already moved the post to
		 * draft. Here nothing has moved it, so the link would 404 for the
		 * friend -- the preview denies auto-draft -- and the button is the only
		 * caller that could ask for one.
		 */
		if ( 'auto-draft' === get_post_status( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Save this post first, then create a share link for it.', 'wp-draftsforfriends' ) ), 400 );
		}

		$result = WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 400 );
		}

		$share = $result['shared'];

		wp_send_json_success(
			array(
				'url'     => WP_DraftsForFriends_Shares::url( $share ),
				/* translators: %s: time remaining, e.g. "2 hours, 3 minutes". */
				'expires' => sprintf( __( 'Expires in %s', 'wp-draftsforfriends' ), WP_DraftsForFriends_Shares::countdown( $share->date_expired ) ),
				'message' => $result['success'],
			)
		);
	}

	/**
	 * Create the share the box asked for.
	 *
	 * Quiet on refusal: wp_die() here would eat the save, and the box shows the
	 * true state on the screen that loads after it.
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public static function save( $post_id ) {
		// No nonce field means the box was not on the screen that posted this.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( empty( $_POST['draftsforfriends_create'] ) ) {
			return;
		}

		if ( ! current_user_can( WP_DraftsForFriends_Admin::capability( 'shares' ) ) ) {
			return;
		}

		$expires = isset( $_POST['draftsforfriends_expires'] ) ? (int) $_POST['draftsforfriends_expires'] : 0;
		$measure = isset( $_POST['draftsforfriends_measure'] ) ? sanitize_key( wp_unslash( $_POST['draftsforfriends_measure'] ) ) : '';

		// create() re-checks the post exists, is unpublished and is the caller's.
		WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure );
	}
}
