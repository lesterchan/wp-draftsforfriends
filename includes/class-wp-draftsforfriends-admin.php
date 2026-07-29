<?php
/**
 * The Drafts for Friends screen.
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
class WP_DraftsForFriends_Admin {

	/**
	 * The menu and page slug.
	 *
	 * Before 2.0.0 this was the plugin file itself, which baked the plugin's
	 * directory name into the page URL and into the hook suffix WordPress hands
	 * back to admin_enqueue_scripts, so installing the plugin under any other
	 * folder name broke both.
	 *
	 * @var string
	 */
	const PAGE = 'wp-draftsforfriends';

	/**
	 * Capability required to reach the shared drafts screen.
	 *
	 * Deliberately publish_posts rather than the manage_options a settings-only
	 * plugin would take: everything this screen does is scoped to drafts the
	 * user may already edit, and a plugin for sharing your own drafts has no
	 * business demanding the capability that lets somebody reconfigure the site.
	 * Section 2.7 keeps a plugin's existing custom capability for its data
	 * screens, and settings stay on manage_options -- see
	 * WP_DraftsForFriends_Settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'publish_posts';

	/**
	 * The Settings API section the add form's fields belong to.
	 *
	 * @var string
	 */
	const SECTION_SHARE = 'wp_draftsforfriends_share';

	/**
	 * The hook suffix add_submenu_page() returned.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Memoised shareable_groups(), or null before it has been asked for.
	 *
	 * @var array|null
	 */
	private $groups = null;

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The capability required for a given context.
	 *
	 * Every check in the plugin goes through here or through
	 * WP_DraftsForFriends_Settings::capability(), so a site that wants to hand
	 * the screen to somebody else has one place to say so and cannot loosen one
	 * entry point while leaving another shut.
	 *
	 * @param string $context What the capability is being checked for.
	 * @return string The required capability.
	 */
	public static function capability( $context = 'shares' ) {
		/**
		 * Filters the capability required to reach WP-DraftsForFriends.
		 *
		 * The default is publish_posts for the shared drafts screen and
		 * manage_options for the settings screen. Everything the shared drafts
		 * screen does is scoped to drafts the user may already edit, so it does
		 * not ask for the capability that lets somebody reconfigure the site.
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability required.
		 * @param string $context    What is being gated: 'shares' or 'settings'.
		 */
		return apply_filters( 'wp_draftsforfriends_capability', self::CAPABILITY, $context );
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
			self::capability( 'shares' ),
			self::PAGE,
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
				'default' => WP_DraftsForFriends_List_Table::PER_PAGE,
				'option'  => 'wp_draftsforfriends_per_page',
			)
		);
	}

	/**
	 * Posts the current user may share, grouped by status.
	 *
	 * Memoised, because the form needs the count to decide whether to render at
	 * all and then needs the posts themselves, both in the same request.
	 *
	 * @return array
	 */
	private function shareable_groups() {
		if ( null === $this->groups ) {
			$this->groups = WP_DraftsForFriends_Shares::shareable_posts();
		}

		return $this->groups;
	}

	/**
	 * How many posts the current user could share.
	 *
	 * @return int
	 */
	private function shareable_count() {
		$total = 0;

		foreach ( $this->shareable_groups() as $group ) {
			$total += $group['count'];
		}

		return $total;
	}

	/**
	 * The form a draft is shared from.
	 *
	 * Plain markup rather than the Settings API's section and field registration.
	 * Before 2.0.0 those were borrowed purely as a renderer -- the form saves no
	 * setting and posts nowhere near options.php -- which put a settings section
	 * and two settings fields on a screen that has no settings, and §4.2 reserves
	 * all three for the class that owns register_setting(). The real settings, and
	 * every field registration in the plugin, are in
	 * WP_DraftsForFriends_Settings.
	 *
	 * Nothing is rendered when the user has no post to share, because a form
	 * whose only control is an empty dropdown is worse than no form.
	 *
	 * @return void
	 */
	private function render_add_form() {
		if ( ! $this->shareable_count() ) {
			return;
		}

		$expires = (int) WP_DraftsForFriends_Options::get( 'expires' );
		$measure = (string) WP_DraftsForFriends_Options::get( 'measure' );
		?>
		<h2><?php esc_html_e( 'Share a Draft', 'wp-draftsforfriends' ); ?></h2>

		<form id="draftsforfriends-add" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
			<p>
				<label for="draftsforfriends-post-id"><?php esc_html_e( 'Choose a draft:', 'wp-draftsforfriends' ); ?></label>
				<select name="post_id" id="draftsforfriends-post-id">
					<option value=""></option>
					<?php foreach ( $this->shareable_groups() as $group ) : ?>
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
			</p>
			<p>
				<label for="draftsforfriends-expires"><?php esc_html_e( 'Share it for:', 'wp-draftsforfriends' ); ?></label>
				<input name="expires" id="draftsforfriends-expires" type="number" min="1" max="9999" step="1" value="<?php echo esc_attr( $expires ); ?>" class="small-text" />
				<?php
				// The visible label belongs to the number input, so the unit needs
				// one of its own or it is announced as an unlabelled combo box.
				?>
				<label class="screen-reader-text" for="draftsforfriends-measure"><?php esc_html_e( 'Duration unit', 'wp-draftsforfriends' ); ?></label>
				<select name="measure" id="draftsforfriends-measure">
					<?php foreach ( WP_DraftsForFriends_Shares::measures() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $measure, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php submit_button( __( 'Share Draft', 'wp-draftsforfriends' ), 'primary', 'draftsforfriends_submit', false, array( 'id' => 'draftsforfriends-submit' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The screen's own URL.
	 *
	 * @return string
	 */
	public static function page_url() {
		return add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
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
			WP_DRAFTSFORFRIENDS_URL . 'css/wp-draftsforfriends-admin.css',
			array(),
			WP_DRAFTSFORFRIENDS_VERSION
		);

		wp_enqueue_script(
			'draftsforfriends-admin',
			WP_DRAFTSFORFRIENDS_URL . 'js/wp-draftsforfriends-admin.js',
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
				'columnCount'    => count( ( new WP_DraftsForFriends_List_Table() )->get_columns() ),
			)
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::capability( 'shares' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shared drafts.', 'wp-draftsforfriends' ) );
		}

		$table = new WP_DraftsForFriends_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Drafts for Friends', 'wp-draftsforfriends' ); ?></h1>

			<div id="draftsforfriends-message" class="notice" style="display: none;"><p></p></div>

			<?php $this->render_add_form(); ?>

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
		// in WP_DraftsForFriends_Shares stay: this is the coarse "may you use this
		// screen at all" test.
		if ( ! current_user_can( self::capability( 'shares' ) ) ) {
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

				wp_send_json( self::with_row( WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure ) ) );
				break;

			case 'extend':
				if ( ! wp_verify_nonce( $nonce, 'draftsforfriends-extend-' . $id ) ) {
					wp_send_json( $nonce_error );
				}

				wp_send_json( self::with_row( WP_DraftsForFriends_Shares::extend( $id, $expires, $measure ) ) );
				break;

			case 'delete':
				if ( ! wp_verify_nonce( $nonce, 'draftsforfriends-delete-' . $id ) ) {
					wp_send_json( $nonce_error );
				}

				wp_send_json( self::with_count( WP_DraftsForFriends_Shares::delete( $id ) ) );
				break;
		}

		wp_send_json( array( 'error' => __( 'No actions specified', 'wp-draftsforfriends' ) ) );
	}

	/**
	 * Attach the rendered row and the refreshed count to a successful response.
	 *
	 * @param array $result Result from WP_DraftsForFriends_Shares.
	 * @return array
	 */
	private static function with_row( array $result ) {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$result['html'] = WP_DraftsForFriends_List_Table::render_row( isset( $result['shared'] ) ? $result['shared'] : null );

		return self::with_count( $result );
	}

	/**
	 * Attach the item count, formatted the way core's list tables word it.
	 *
	 * Done server side so the script does not have to carry both plural forms.
	 *
	 * @param array $result Result from WP_DraftsForFriends_Shares.
	 * @return array
	 */
	private static function with_count( array $result ) {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$total = WP_DraftsForFriends_Shares::count();

		/* translators: %s: number of shared drafts. */
		$result['countText'] = sprintf( _n( '%s item', '%s items', $total, 'wp-draftsforfriends' ), number_format_i18n( $total ) );
		$result['count']     = number_format_i18n( $total );

		return $result;
	}
}
