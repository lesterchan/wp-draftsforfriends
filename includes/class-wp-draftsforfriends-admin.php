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
 * Registers the menu, renders the shared drafts screen and handles its writes.
 *
 * §4.2 splits this from WP_DraftsForFriends_Settings: this class owns the menu
 * and the screens, and that one owns register_setting(), the sections, the fields
 * and the sanitiser.
 *
 * Every path here works with JavaScript turned off. The add form and both bulk
 * actions are real nonced form posts, and the script only adds confirmation,
 * client-side validation and a copy-to-clipboard button on top. Before 2.0.0 the
 * screen depended on an AJAX endpoint for all three writes, which meant the
 * whole thing did nothing at all in a browser where the script had failed to
 * load -- and left the plugin with no way to offer the bulk actions §4.3 asks
 * for, because those are a form post by definition.
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
	 * §2.7 keeps a plugin's existing custom capability for its data screens, and
	 * settings stay on manage_options -- see WP_DraftsForFriends_Settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'publish_posts';

	/**
	 * Nonce action for the add form.
	 *
	 * Separate from the bulk nonce so a nonce that leaks from one form cannot be
	 * replayed against the other.
	 *
	 * @var string
	 */
	const NONCE_ADD = 'wp_draftsforfriends_add';

	/**
	 * Nonce action for the list table's bulk actions.
	 *
	 * @var string
	 */
	const NONCE_BULK = 'wp_draftsforfriends_bulk';

	/**
	 * The add_settings_error() slug the screen's messages are collected under.
	 *
	 * @var string
	 */
	const NOTICES = 'wp_draftsforfriends';

	/**
	 * The hook suffix WordPress handed back when the menu was registered.
	 *
	 * Recorded rather than assumed. get_plugin_page_hookname() derives the prefix
	 * from $admin_page_hooks, so the suffix is 'toplevel_page_wp-draftsforfriends'
	 * on a real admin request and something else anywhere the admin menu has not
	 * been built. Comparing against a hardcoded string means the script silently
	 * fails to load in the cases that do not match, and the screen renders with
	 * dead buttons.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Memoised shareable_posts(), or null before it has been asked for.
	 *
	 * @var array|null
	 */
	private static $groups = null;

	/**
	 * Hook the screen into WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
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
	 * Register the menu.
	 *
	 * One top-level menu, the data screen first and Settings last, per §4.1: a
	 * plugin with a list table and an add form has screens a site owner manages
	 * things on. Until 2.0.0 this was a submenu of Posts, which is also where the
	 * settings would have had to go, and §4.1 has no room for a plugin whose
	 * settings live under somebody else's menu.
	 *
	 * The Settings entry takes manage_options, so an author sees the menu and the
	 * shared drafts screen but not the settings -- which is the intended result
	 * of the two capabilities in §2.7 rather than an accident of registration.
	 *
	 * @return void
	 */
	public static function admin_menu() {
		self::$hook_suffix = add_menu_page(
			__( 'Drafts for Friends', 'wp-draftsforfriends' ),
			__( 'WP-DraftsForFriends', 'wp-draftsforfriends' ),
			self::capability( 'shares' ),
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-share'
		);

		add_submenu_page(
			self::PAGE,
			__( 'Shared Drafts', 'wp-draftsforfriends' ),
			__( 'Shared Drafts', 'wp-draftsforfriends' ),
			self::capability( 'shares' ),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::PAGE,
			__( 'Drafts for Friends Settings', 'wp-draftsforfriends' ),
			__( 'Settings', 'wp-draftsforfriends' ),
			WP_DraftsForFriends_Settings::capability( 'settings' ),
			WP_DraftsForFriends_Settings::PAGE,
			array( 'WP_DraftsForFriends_Settings', 'render_page' )
		);

		if ( self::$hook_suffix ) {
			add_action( 'load-' . self::$hook_suffix, array( __CLASS__, 'add_screen_options' ) );
		}
	}

	/**
	 * The hook suffix WordPress gave the shared drafts screen.
	 *
	 * @return string Hook suffix, or an empty string before admin_menu runs.
	 */
	public static function get_hook_suffix() {
		return self::$hook_suffix;
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
	 * Offer the per-page screen option.
	 *
	 * Core persists any option whose name ends in _page by itself, so there is
	 * no set-screen-option filter to add.
	 *
	 * @return void
	 */
	public static function add_screen_options() {
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
	 * Load the screen's script and stylesheet, and only on the screen using them.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function admin_enqueue_scripts( $hook_suffix ) {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		/*
		 * The URLs come from WP_DRAFTSFORFRIENDS_URL, which is derived from the
		 * main file. Building them from the literal 'wp-draftsforfriends/js/...'
		 * meant both assets 404ed for anyone who installed the plugin under a
		 * different directory name, and a 404ed script fails silently.
		 */
		wp_enqueue_style(
			'wp-draftsforfriends-admin',
			WP_DRAFTSFORFRIENDS_URL . 'css/wp-draftsforfriends-admin.css',
			array(),
			WP_DRAFTSFORFRIENDS_VERSION
		);

		wp_enqueue_script(
			'wp-draftsforfriends-admin',
			WP_DRAFTSFORFRIENDS_URL . 'js/wp-draftsforfriends-admin.js',
			array(),
			WP_DRAFTSFORFRIENDS_VERSION,
			true
		);

		wp_localize_script(
			'wp-draftsforfriends-admin',
			'wpDraftsForFriendsL10n',
			array(
				'errorPostId'   => __( 'Please choose a draft to share.', 'wp-draftsforfriends' ),
				'errorExpires'  => __( 'Please choose a valid duration.', 'wp-draftsforfriends' ),
				'errorSelect'   => __( 'Please select at least one shared draft.', 'wp-draftsforfriends' ),
				'confirmRevoke' => __( 'Revoke the selected shared drafts? The links stop working immediately and cannot be restored.', 'wp-draftsforfriends' ),
				'copy'          => __( 'Copy link', 'wp-draftsforfriends' ),
				'copied'        => __( 'Copied!', 'wp-draftsforfriends' ),
				'copyFailed'    => __( 'Could not copy the link. Select it and copy it by hand.', 'wp-draftsforfriends' ),
			)
		);
	}

	/**
	 * Render the shared drafts screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::capability( 'shares' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shared drafts.', 'wp-draftsforfriends' ) );
		}

		// Before the table is built, so the list reflects what the request just
		// did rather than the state it was in when the form was submitted.
		self::handle_request();

		$table = new WP_DraftsForFriends_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Drafts for Friends', 'wp-draftsforfriends' ); ?></h1>

			<?php settings_errors( self::NOTICES ); ?>

			<?php self::render_add_form(); ?>

			<h2><?php esc_html_e( 'Currently Shared Drafts', 'wp-draftsforfriends' ); ?></h2>

			<form id="draftsforfriends-list" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
				<?php
				// No wp_nonce_field() here: $table->display() emits the bulk nonce this
				// form is checked against, and a second _wpnonce input would override it.
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Posts the current user may share, grouped by status.
	 *
	 * Memoised, because the form needs the count to decide whether to render at
	 * all and then needs the posts themselves, both in the same request.
	 *
	 * @return array
	 */
	private static function shareable_groups() {
		if ( null === self::$groups ) {
			self::$groups = WP_DraftsForFriends_Shares::shareable_posts();
		}

		return self::$groups;
	}

	/**
	 * How many posts the current user could share.
	 *
	 * @return int
	 */
	private static function shareable_count() {
		$total = 0;

		foreach ( self::shareable_groups() as $group ) {
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
	 * every field registration in the plugin, are in WP_DraftsForFriends_Settings.
	 *
	 * Nothing is rendered when the user has no post to share, because a form
	 * whose only control is an empty dropdown is worse than no form.
	 *
	 * @return void
	 */
	private static function render_add_form() {
		if ( ! self::shareable_count() ) {
			return;
		}

		$expires = (int) WP_DraftsForFriends_Options::get( 'expires' );
		$measure = (string) WP_DraftsForFriends_Options::get( 'measure' );
		?>
		<h2><?php esc_html_e( 'Share a Draft', 'wp-draftsforfriends' ); ?></h2>

		<form id="draftsforfriends-add" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
			<?php wp_nonce_field( self::NONCE_ADD ); ?>
			<p>
				<label for="draftsforfriends-post-id"><?php esc_html_e( 'Choose a draft:', 'wp-draftsforfriends' ); ?></label>
				<select name="post_id" id="draftsforfriends-post-id">
					<option value=""></option>
					<?php foreach ( self::shareable_groups() as $group ) : ?>
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
			<?php submit_button( __( 'Share Draft', 'wp-draftsforfriends' ), 'primary', 'draftsforfriends_add', false, array( 'id' => 'draftsforfriends-submit' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Act on whatever the request asked for, before anything is rendered.
	 *
	 * @return void
	 */
	private static function handle_request() {
		self::handle_add();
		self::handle_bulk();

		// Cleared after the writes rather than before them, because the memo is
		// static: in a browser it lives for one request, but in a test run or under
		// WP-CLI the same process renders the screen repeatedly, sometimes as a
		// different user. Clearing here means the picker is always assembled for
		// whoever is looking at it now.
		self::$groups = null;
	}

	/**
	 * Share the draft the add form named.
	 *
	 * @return void
	 */
	private static function handle_add() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deciding whether this request is the add form at all; the nonce is checked on the next line, before any of its values are read.
		if ( ! isset( $_POST['draftsforfriends_add'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ADD );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$expires = isset( $_POST['expires'] ) ? (int) $_POST['expires'] : 0;
		$measure = isset( $_POST['measure'] ) ? sanitize_key( wp_unslash( $_POST['measure'] ) ) : '';

		self::report( WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure ) );
	}

	/**
	 * Extend or revoke everything that was ticked.
	 *
	 * @return void
	 */
	private static function handle_bulk() {
		$table  = new WP_DraftsForFriends_List_Table();
		$action = $table->current_action();

		if ( ! in_array( $action, array( 'extend', 'revoke' ), true ) ) {
			return;
		}

		check_admin_referer( $table->bulk_nonce_action() );

		$ids = isset( $_POST['shares'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['shares'] ) ) : array();
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( empty( $ids ) ) {
			add_settings_error(
				self::NOTICES,
				'wp_draftsforfriends_none',
				esc_html__( 'Nothing was selected, so nothing changed.', 'wp-draftsforfriends' ),
				'warning'
			);

			return;
		}

		$results = array();

		if ( 'revoke' === $action ) {
			foreach ( $ids as $id ) {
				$results[] = WP_DraftsForFriends_Shares::delete( $id );
			}
		} else {
			$expires = isset( $_POST['extend_expires'] ) ? (int) $_POST['extend_expires'] : 0;
			$measure = isset( $_POST['extend_measure'] ) ? sanitize_key( wp_unslash( $_POST['extend_measure'] ) ) : '';

			foreach ( $ids as $id ) {
				$results[] = WP_DraftsForFriends_Shares::extend( $id, $expires, $measure );
			}
		}

		self::report_batch( $action, $results );
	}

	/**
	 * Turn one result into a notice.
	 *
	 * Note that settings_errors() prints its message straight into the page, so
	 * anything handed to add_settings_error() is escaped here. These strings
	 * interpolate a post title, which is whatever the author typed.
	 *
	 * @param array $result Result from WP_DraftsForFriends_Shares.
	 * @return void
	 */
	private static function report( array $result ) {
		if ( ! empty( $result['success'] ) ) {
			add_settings_error( self::NOTICES, 'wp_draftsforfriends_done', esc_html( $result['success'] ), 'success' );

			return;
		}

		add_settings_error(
			self::NOTICES,
			'wp_draftsforfriends_error',
			esc_html( isset( $result['error'] ) ? $result['error'] : __( 'Something went wrong.', 'wp-draftsforfriends' ) ),
			'error'
		);
	}

	/**
	 * Turn a batch of results into one count and one notice per distinct problem.
	 *
	 * A notice per share would put twenty identical "you do not have permission"
	 * banners at the top of the screen, so the successes are counted and the
	 * errors are deduplicated.
	 *
	 * @param string $action  Either extend or revoke.
	 * @param array  $results Results from WP_DraftsForFriends_Shares.
	 * @return void
	 */
	private static function report_batch( $action, array $results ) {
		$done   = 0;
		$errors = array();

		foreach ( $results as $result ) {
			if ( ! empty( $result['success'] ) ) {
				++$done;

				continue;
			}

			$errors[] = isset( $result['error'] ) ? $result['error'] : __( 'Something went wrong.', 'wp-draftsforfriends' );
		}

		if ( $done > 0 ) {
			$message = 'revoke' === $action
				/* translators: %s: number of shared drafts. */
				? sprintf( _n( '%s shared draft revoked.', '%s shared drafts revoked.', $done, 'wp-draftsforfriends' ), number_format_i18n( $done ) )
				/* translators: %s: number of shared drafts. */
				: sprintf( _n( '%s shared draft extended.', '%s shared drafts extended.', $done, 'wp-draftsforfriends' ), number_format_i18n( $done ) );

			add_settings_error( self::NOTICES, 'wp_draftsforfriends_done', esc_html( $message ), 'success' );
		}

		foreach ( array_unique( $errors ) as $index => $error ) {
			add_settings_error( self::NOTICES, 'wp_draftsforfriends_error_' . $index, esc_html( $error ), 'error' );
		}
	}
}
