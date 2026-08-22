<?php
/**
 * The Drafts for Friends screen.
 *
 * @package WP-DraftsForFriends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu, renders the plugin's one page and handles its writes.
 *
 * §4.2 splits this from WP_DraftsForFriends_Settings: this class owns the menu
 * and the screens, and that one owns register_setting(), the sections, the fields
 * and the sanitiser.
 *
 * The plugin has one admin page under Posts, with two flat tabs: Shared Drafts
 * and Settings. It does one thing -- share a post with somebody -- and its list
 * is a list of shared posts, so it belongs where WordPress keeps post-scoped
 * tools. See add_page() for why that is not a top-level menu, and render_page()
 * for the capability arrangement the tabs need.
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
	 * Capability required to reach the page and its Shared Drafts tab.
	 *
	 * Deliberately publish_posts rather than the manage_options a settings-only
	 * plugin would take: everything this tab does is scoped to drafts the
	 * user may already edit, and a plugin for sharing your own drafts has no
	 * business demanding the capability that lets somebody reconfigure the site.
	 * §2.7 keeps a plugin's existing custom capability for its data screens, and
	 * settings stay on manage_options -- see WP_DraftsForFriends_Settings.
	 *
	 * This is also the capability the *page* is registered with, because §4.2.1
	 * requires the lower of the two once the screens become tabs. The Settings
	 * tab then checks manage_options for itself; see render_page().
	 *
	 * @var string
	 */
	const CAPABILITY = 'publish_posts';

	/**
	 * The default tab, and the one an unknown ?tab= falls back to.
	 *
	 * @var string
	 */
	const TAB_SHARES = 'shares';

	/**
	 * The settings tab.
	 *
	 * @var string
	 */
	const TAB_SETTINGS = 'settings';

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
	 * from $admin_page_hooks, so the suffix is 'posts_page_wp-draftsforfriends'
	 * on a real admin request and something else anywhere the admin menu has not
	 * been built. Comparing against a hardcoded string means the script silently
	 * fails to load in the cases that do not match, and the screen renders with
	 * dead buttons -- and the string moved once already, when the page left its
	 * own top-level menu and its suffix stopped being 'toplevel_page_…'.
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
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
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
		 * Filters the capability required to reach a WP-DraftsForFriends screen.
		 *
		 * The default is publish_posts for the shared drafts screen and
		 * manage_options for the settings screen. Everything the shared drafts
		 * screen does is scoped to drafts the user may already edit, so it does
		 * not ask for the capability that lets somebody reconfigure the site.
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability The required capability.
		 * @param string $context    What is being gated: 'shares' or 'settings'.
		 */
		return (string) apply_filters( 'wp_draftsforfriends_capability', self::CAPABILITY, $context );
	}

	/**
	 * Register the menu.
	 *
	 * One page under Posts, carrying both tabs. The plugin does one thing --
	 * share a post with somebody -- and its list is a list of shared posts.
	 * Sharing is gated on publish_posts, which is an editorial capability rather
	 * than a site-configuration one, and WordPress keeps post-scoped tools under
	 * Posts. A top-level menu for it would claim a sidebar slot next to Posts,
	 * Media and Pages for something that only makes sense beside your posts.
	 *
	 * The page is registered with the *lower* of the two capabilities, per
	 * §4.2.1, so an author reaches it at all; render_page() then checks
	 * manage_options before it will draw or hand over the Settings tab. Getting
	 * that second half wrong is privilege escalation dressed as a layout change,
	 * which is why it is asserted from both directions in tests/test-admin.php.
	 *
	 * The sidebar label is the plugin's name, spelled as the plugin header
	 * spells it, per §4.1: it is the string a site owner has just seen on the
	 * Plugins screen and is now looking for. That holds for the plugin's one
	 * entry wherever it hangs -- wp-print's entry under Settings reads WP-Print
	 * for the same reason. The rule's exception is for a plugin's *own*
	 * submenus, which say what each screen is; this plugin has none, because it
	 * has one page. The page title and the h1 do drop the prefix.
	 *
	 * @return void
	 */
	public static function add_page() {
		self::$hook_suffix = add_posts_page(
			__( 'Drafts for Friends', 'wp-draftsforfriends' ),
			__( 'WP-DraftsForFriends', 'wp-draftsforfriends' ),
			self::capability( 'shares' ),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);

		if ( self::$hook_suffix ) {
			add_action( 'load-' . self::$hook_suffix, array( __CLASS__, 'add_screen_options' ) );
		}
	}

	/**
	 * The tabs on the page, in order.
	 *
	 * Flat, and exactly two, per §4.2.1: the data screen first and Settings last.
	 *
	 * @return array Tab slug to label.
	 */
	public static function tabs() {
		return array(
			self::TAB_SHARES   => __( 'Shared Drafts', 'wp-draftsforfriends' ),
			self::TAB_SETTINGS => __( 'Settings', 'wp-draftsforfriends' ),
		);
	}

	/**
	 * The capability a given tab requires.
	 *
	 * The page takes the lower of the two, so this is what stops the Settings tab
	 * being reachable by everybody the page is.
	 *
	 * @param string $tab Tab slug.
	 * @return string The required capability.
	 */
	public static function tab_capability( $tab ) {
		return self::TAB_SETTINGS === $tab
			? WP_DraftsForFriends_Settings::capability( 'settings' )
			: self::capability( 'shares' );
	}

	/**
	 * Which tab the request asked for.
	 *
	 * An unknown tab is the first one rather than a 404: ?tab= is a rendering
	 * choice, and the page it names still exists.
	 *
	 * @return string Tab slug.
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which tab to draw; nothing is read from the request beyond that and nothing is written.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_SHARES;

		return array_key_exists( $tab, self::tabs() ) ? $tab : self::TAB_SHARES;
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
	 * The page's own URL, optionally on a given tab.
	 *
	 * Built on edit.php rather than admin.php, because the page hangs off Posts.
	 * Every form on the page posts back to this, which is what keeps the active
	 * tab across a bulk action without a hidden field to carry it.
	 *
	 * @param string $tab Optional tab slug to land on.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$args = array( 'page' => self::PAGE );

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Offer the per-page screen option.
	 *
	 * Only on the tab that has a list to page through: Screen Options offering
	 * "Shared drafts per page" above a settings form is an option about a table
	 * that is not on screen.
	 *
	 * Drawing the control is half the job; save_screen_option() is the half that
	 * keeps what it collects.
	 *
	 * @return void
	 */
	public static function add_screen_options() {
		if ( self::TAB_SHARES !== self::current_tab() ) {
			return;
		}

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
	 * Keep the per-page value the user submitted, which core will not do alone.
	 *
	 * A plugin has to claim its own screen option or the value is thrown away.
	 * set_screen_options() reaches its default arm for anything outside core's
	 * own hardcoded list; there the _page suffix decides only whether
	 * 'set-screen-option' is *offered*, and with nothing hooked the filtered
	 * value stays false and core returns without writing. So the control drew,
	 * accepted a number and silently discarded it, which is what
	 * shares.spec.js's per-page test was failing on.
	 *
	 * Scoped to this plugin's own option, because the filter is fired for every
	 * screen's per-page control: answering for somebody else's would store a
	 * value on their behalf and take the decision away from the plugin that owns
	 * it.
	 *
	 * @param mixed  $status Value core will store, false until something claims it.
	 * @param string $option Option being saved.
	 * @param mixed  $value  Submitted value.
	 * @return mixed The value to store, or $status where the option is not ours.
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return 'wp_draftsforfriends_per_page' === $option ? (int) $value : $status;
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

		self::enqueue_assets();
	}

	/**
	 * The actual enqueue, shared with the post editor's meta box: each screen
	 * decides whether it is the one being drawn, what loads lives here.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
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
				'copy'          => __( 'Copy Link', 'wp-draftsforfriends' ),
				'copied'        => __( 'Copied!', 'wp-draftsforfriends' ),
				'copyFailed'    => __( 'Could not copy the link. Select it and copy it by hand.', 'wp-draftsforfriends' ),
			)
		);
	}

	/**
	 * Render the page, on whichever tab was asked for.
	 *
	 * Two capability checks, and both are load-bearing. The page is registered
	 * with publish_posts because that is the lower of the two, so reaching this
	 * method proves only that the caller may share drafts. The Settings tab is
	 * gated here as well, before anything is drawn, and again inside
	 * WP_DraftsForFriends_Settings::render_tab() -- and the *save* is gated
	 * separately, because that goes to options.php rather than through here.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::capability( 'shares' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to manage shared drafts.', 'wp-draftsforfriends' ) );
		}

		$tab = self::current_tab();

		if ( ! current_user_can( self::tab_capability( $tab ) ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-draftsforfriends' ) );
		}

		if ( self::TAB_SHARES === $tab ) {
			/*
			 * Before a byte of the page is drawn. The writes raise their notices
			 * through add_settings_error(), so anything running after
			 * settings_errors() below reports itself to a screen that has already
			 * printed its messages -- which is a share that is created, or twenty
			 * that are revoked, and a page that says nothing happened.
			 */
			self::handle_request();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Drafts for Friends', 'wp-draftsforfriends' ); ?></h1>

			<?php
			if ( self::TAB_SETTINGS === $tab ) {
				/*
				 * Unscoped, so "Settings saved." actually appears. options.php
				 * registers that message against the 'general' slug rather than
				 * against this screen, and core will not print it for us: it calls
				 * settings_errors() from options-head.php, which admin-header.php
				 * includes only when the parent file is options-general.php. This
				 * page hangs off edit.php, so without this line pressing Save
				 * Changes sends the browser back here and says nothing at all.
				 */
				settings_errors();
			} else {
				settings_errors( self::NOTICES );
			}
			?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<?php if ( ! current_user_can( self::tab_capability( $slug ) ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( self::page_url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( self::TAB_SETTINGS === $tab ) {
				WP_DraftsForFriends_Settings::render_tab();
			} else {
				self::render_shares_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the Shared Drafts tab: the add form and the list table.
	 *
	 * Neither form here goes anywhere near options.php. This tab is a list table
	 * with bulk actions, and §4.2.1 is explicit that a data screen turned into a
	 * tab must keep its own form and its own nonce.
	 *
	 * @return void
	 */
	private static function render_shares_tab() {
		// The table is built here rather than in render_page() only because it is
		// the last thing on the tab; handle_request() has already run, so the list
		// reflects what the request just did.
		$table = new WP_DraftsForFriends_List_Table();
		$table->prepare_items();

		self::render_add_form();
		?>
		<h2><?php esc_html_e( 'Currently Shared Drafts', 'wp-draftsforfriends' ); ?></h2>

		<form id="draftsforfriends-list" method="post" action="<?php echo esc_url( self::page_url( self::TAB_SHARES ) ); ?>">
			<?php
			// No wp_nonce_field() here: $table->display() emits the bulk nonce this
			// form is checked against, and a second _wpnonce input would override it.
			$table->display();
			?>
		</form>
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

		<form id="draftsforfriends-add" method="post" action="<?php echo esc_url( self::page_url( self::TAB_SHARES ) ); ?>">
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
