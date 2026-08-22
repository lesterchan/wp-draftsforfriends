<?php
/**
 * The post editor's meta box.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows a post's share links in the editor, and creates one on save.
 *
 * Creating rides the editor's own save because the box sits inside the post
 * form and cannot carry a form of its own, and a GET link that creates state
 * is a prefetch hazard. Registered for the built-in post type only: the share
 * URL is `?p=<id>`, which WordPress answers for posts alone.
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
	const NONCE = 'wp_draftsforfriends_metabox';

	/**
	 * The field the nonce travels in. Not _wpnonce: inside the editor's form a
	 * second field of that name would replace the post's own nonce.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'draftsforfriends_metabox_nonce';

	/**
	 * Hook the box into the editor.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
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
	public static function admin_enqueue_scripts( $hook_suffix ) {
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
		self::render_create_controls();
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
		if ( empty( $shares ) ) {
			return;
		}
		?>
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
	 * The create-on-save checkbox and the duration it will use.
	 *
	 * @return void
	 */
	private static function render_create_controls() {
		$expires = (int) WP_DraftsForFriends_Options::get( 'expires' );
		$measure = (string) WP_DraftsForFriends_Options::get( 'measure' );

		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		?>
		<p>
			<label for="draftsforfriends-metabox-create">
				<input type="checkbox" name="draftsforfriends_create" id="draftsforfriends-metabox-create" value="1" />
				<?php esc_html_e( 'Create a share link when this post is saved', 'wp-draftsforfriends' ); ?>
			</label>
		</p>
		<p>
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
		<?php
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

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) ) {
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
