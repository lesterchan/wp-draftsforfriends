<?php
/**
 * WP-DraftsForFriends class-draftsforfriends-admin.php
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin screen and the endpoint behind it.
 *
 * @since 2.0.0
 */
class DraftsForFriends_Admin {

	/**
	 * The menu slug.
	 *
	 * Before 2.0.0 this was the plugin file itself, which baked the plugin's
	 * directory name into the page URL and into the hook suffix WordPress hands
	 * back to admin_enqueue_scripts.
	 *
	 * @var string
	 */
	const SLUG = 'draftsforfriends';

	/**
	 * Capability required to reach the screen and the endpoint.
	 *
	 * @var string
	 */
	const CAPABILITY = 'publish_posts';

	/**
	 * The hook suffix add_submenu_page() returned.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The durations a share can be measured in.
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
	 * Add the screen under Posts.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook_suffix = add_submenu_page(
			'edit.php',
			__( 'Drafts for Friends', 'wp-draftsforfriends' ),
			__( 'Drafts for Friends', 'wp-draftsforfriends' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Offer the per-page screen option.
	 *
	 * Core persists any option whose name ends in _page by itself, so there is
	 * no set-screen-option filter to add.
	 *
	 * @return void
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Shared drafts per page', 'wp-draftsforfriends' ),
				'default' => DraftsForFriends_Table::PER_PAGE,
				'option'  => 'draftsforfriends_per_page',
			)
		);
	}

	/**
	 * Load the screen's script and stylesheet.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'draftsforfriends-admin',
			WP_DRAFTSFORFRIENDS_URL . 'css/draftsforfriends-admin.css',
			array(),
			WP_DRAFTSFORFRIENDS_VERSION
		);

		wp_enqueue_script(
			'draftsforfriends-admin',
			WP_DRAFTSFORFRIENDS_URL . 'js/draftsforfriends-admin.js',
			array(),
			WP_DRAFTSFORFRIENDS_VERSION,
			true
		);

		wp_localize_script(
			'draftsforfriends-admin',
			'draftsForFriendsAdminL10n',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'addNonce'       => wp_create_nonce( 'draftsforfriends-add' ),
				/* translators: %s: post title. */
				'confirmDelete'  => __( 'Are you sure you want to delete this shared draft, \'%s\'', 'wp-draftsforfriends' ),
				'errorId'        => __( 'Invalid shared draft id', 'wp-draftsforfriends' ),
				'errorPostId'    => __( 'Please choose a draft to share', 'wp-draftsforfriends' ),
				'errorExpires'   => __( 'Please choose a valid duration', 'wp-draftsforfriends' ),
				'errorRequest'   => __( 'The request failed. Please try again.', 'wp-draftsforfriends' ),
				'noSharedDrafts' => __( 'No shared drafts!', 'wp-draftsforfriends' ),
				'columnCount'    => count( ( new DraftsForFriends_Table() )->get_columns() ),
			)
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shared drafts.', 'wp-draftsforfriends' ) );
		}

		$table = new DraftsForFriends_Table();
		$table->prepare_items();

		$groups      = DraftsForFriends_Shares::shareable_posts();
		$has_posts   = false;
		$total_posts = 0;

		foreach ( $groups as $group ) {
			$total_posts += $group['count'];
		}

		$has_posts = $total_posts > 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Drafts for Friends', 'wp-draftsforfriends' ); ?></h1>

			<div id="draftsforfriends-message" class="notice" style="display: none;"><p></p></div>

			<?php if ( $has_posts ) : ?>
				<h2><?php esc_html_e( 'Share Draft with Friends', 'wp-draftsforfriends' ); ?></h2>
				<form id="draftsforfriends-add">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="draftsforfriends-post-id"><?php esc_html_e( 'Choose a draft:', 'wp-draftsforfriends' ); ?></label>
								</th>
								<td>
									<select name="post_id" id="draftsforfriends-post-id">
										<option value=""></option>
										<?php foreach ( $groups as $group ) : ?>
											<?php if ( ! $group['count'] ) : ?>
												<?php continue; ?>
											<?php endif; ?>
											<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
												<?php foreach ( $group['posts'] as $post ) : ?>
													<?php if ( '' === trim( (string) $post->post_title ) ) : ?>
														<?php continue; ?>
													<?php endif; ?>
													<option value="<?php echo esc_attr( $post->ID ); ?>"><?php echo esc_html( $post->post_title ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="draftsforfriends-expires"><?php esc_html_e( 'Share it for:', 'wp-draftsforfriends' ); ?></label>
								</th>
								<td>
									<input name="expires" id="draftsforfriends-expires" type="number" min="1" step="1" value="2" class="small-text" />
									<select name="measure" id="draftsforfriends-measure">
										<?php foreach ( self::measures() as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'h', $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Go', 'wp-draftsforfriends' ), 'primary', 'draftsforfriends_submit', true, array( 'id' => 'draftsforfriends-submit' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Currently Shared Drafts', 'wp-draftsforfriends' ); ?></h2>
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * The screen's only endpoint.
	 *
	 * @return void
	 */
	public static function ajax() {
		// Gate the endpoint before any request data is read. The per-post checks
		// in DraftsForFriends_Shares stay: this is the coarse "may you use this
		// screen at all" test.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json( array( 'error' => __( 'You do not have permission to manage shared drafts.', 'wp-draftsforfriends' ) ) );
		}

		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$expires = isset( $_POST['expires'] ) ? (int) $_POST['expires'] : 0;
		$measure = isset( $_POST['measure'] ) ? sanitize_key( wp_unslash( $_POST['measure'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$nonce   = isset( $_POST['_ajax_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) ) : '';

		$nonce_error = array( 'error' => __( 'Unable to verify nonce', 'wp-draftsforfriends' ) );

		switch ( $do ) {
			case 'add':
				if ( ! wp_verify_nonce( $nonce, 'draftsforfriends-add' ) ) {
					wp_send_json( $nonce_error );
				}

				wp_send_json( self::with_row( DraftsForFriends_Shares::create( $post_id, $expires, $measure ) ) );
				break;

			case 'extend':
				if ( ! wp_verify_nonce( $nonce, 'draftsforfriends-extend-' . $id ) ) {
					wp_send_json( $nonce_error );
				}

				wp_send_json( self::with_row( DraftsForFriends_Shares::extend( $id, $expires, $measure ) ) );
				break;

			case 'delete':
				if ( ! wp_verify_nonce( $nonce, 'draftsforfriends-delete-' . $id ) ) {
					wp_send_json( $nonce_error );
				}

				wp_send_json( self::with_count( DraftsForFriends_Shares::delete( $id ) ) );
				break;
		}

		wp_send_json( array( 'error' => __( 'No actions specified', 'wp-draftsforfriends' ) ) );
	}

	/**
	 * Attach the rendered row and the refreshed count to a successful response.
	 *
	 * @param array $result Result from DraftsForFriends_Shares.
	 * @return array
	 */
	private static function with_row( array $result ) {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$result['html'] = DraftsForFriends_Table::render_row( isset( $result['shared'] ) ? $result['shared'] : null );

		return self::with_count( $result );
	}

	/**
	 * Attach the item count, formatted the way core's list tables word it.
	 *
	 * Done server side so the script does not have to carry both plural forms.
	 *
	 * @param array $result Result from DraftsForFriends_Shares.
	 * @return array
	 */
	private static function with_count( array $result ) {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$total = DraftsForFriends_Shares::count();

		/* translators: %s: number of shared drafts. */
		$result['countText'] = sprintf( _n( '%s item', '%s items', $total, 'wp-draftsforfriends' ), number_format_i18n( $total ) );
		$result['count']     = number_format_i18n( $total );

		return $result;
	}
}
