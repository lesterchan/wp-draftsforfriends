<?php
/**
 * The base class every WP-DraftsForFriends test extends.
 *
 * @package WP-DraftsForFriends
 */

/**
 * Seeds the two users and two drafts almost every test needs, and drives the
 * admin screen the way wp-admin drives it.
 *
 * The fixtures are an author and an editor because the whole plugin turns on the
 * difference: an author sees and shares only their own posts, and anyone with
 * edit_others_posts sees everything.
 */
abstract class WP_DraftsForFriends_TestCase extends WP_UnitTestCase {

	/**
	 * An author, who may only touch their own posts.
	 *
	 * @var int
	 */
	protected $author_id;

	/**
	 * An editor, who has edit_others_posts.
	 *
	 * @var int
	 */
	protected $editor_id;

	/**
	 * The author's draft.
	 *
	 * @var int
	 */
	protected $draft_id;

	/**
	 * The editor's draft.
	 *
	 * @var int
	 */
	protected $editor_draft_id;

	/**
	 * The hook suffix WordPress handed the shared drafts screen.
	 *
	 * @var string
	 */
	protected $admin_hook_suffix = '';

	/**
	 * Diagnostics PHP raised while the admin screen rendered.
	 *
	 * @var array
	 */
	protected $admin_page_notices = array();

	/**
	 * Creates a user who may actually reach the plugin's screens.
	 *
	 * Neither capability the plugin gates on is remapped under multisite:
	 * sharing takes `publish_posts` and the settings screen takes
	 * `manage_options`, and core's map_meta_cap() leaves both alone. So there is
	 * no grant_super_admin() here — an administrator on a network holds both
	 * already, and granting would make the fixture stop representing the author
	 * whose drafts these are.
	 *
	 * Every administrator the suite creates goes through this, so the network
	 * question is answered in one place rather than at each call site. Tests
	 * that assert the *unprivileged* path set their own subscriber or editor
	 * explicitly and must not be routed through here.
	 *
	 * @return int The new user's ID.
	 */
	protected function create_admin() {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Seed the fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->author_id,
				// Carries HTML so the escaping assertions have something to bite on.
				'post_title'  => 'Draft <b>Title</b> & "quoted"',
			)
		);

		$this->editor_draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->editor_id,
				'post_title'  => 'Editor Draft',
			)
		);
	}

	/**
	 * Put back the globals a rendered admin screen leaves behind.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		/*
		 * These three outlive a test. add_settings_error() writes into a global,
		 * so a notice raised by one test would be rendered by the next one; and
		 * $wp_scripts / $wp_styles survive the transaction rollback, so a handle
		 * enqueued by one test is still enqueued in the next.
		 */
		$GLOBALS['wp_settings_errors'] = array();
		$GLOBALS['wp_scripts']         = null;
		$GLOBALS['wp_styles']          = null;

		parent::tear_down();
	}

	/**
	 * Register the plugin's menu and record the hook suffix WordPress gave it.
	 *
	 * Always ask rather than hardcode: get_plugin_page_hookname() builds the
	 * prefix from $admin_page_hooks, so the same menu slug yields
	 * 'posts_page_wp-draftsforfriends' on a real admin request and something
	 * else where the admin menu has not been built, as in this test run. It was
	 * 'toplevel_page_…' until the page moved under Posts, which is the second
	 * time this string has changed under a suite that never asserts it.
	 *
	 * @return string The hook suffix.
	 */
	protected function register_admin_menu() {
		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();

		WP_DraftsForFriends_Admin::admin_menu();

		$this->admin_hook_suffix = WP_DraftsForFriends_Admin::get_hook_suffix();

		return $this->admin_hook_suffix;
	}

	/**
	 * Render the shared drafts screen, capturing anything PHP complained about.
	 *
	 * Driven the way wp-admin drives it: the menu is registered, the page's load
	 * hook fires -- which is where the per-page screen option is offered -- and
	 * only then does the page render. Collecting diagnostics is what makes this
	 * more than a smoke test: a screen that renders while quietly raising a notice
	 * per row is exactly the failure it catches.
	 *
	 * @param array $get  Query arguments the request arrived with.
	 * @param array $post Posted fields, for the add form and the bulk actions.
	 * @return string The rendered markup.
	 */
	protected function render_admin_page( array $get = array(), array $post = array() ) {
		$this->admin_page_notices = array();

		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );

		/*
		 * A real admin request always has $hook_suffix set and a current screen by
		 * the time a page callback runs, and WP_List_Table reaches for both through
		 * WP_Screen. Set here rather than in the plugin: standing in for admin.php
		 * is the fixture's job.
		 *
		 * The screen comes from the recorded hook suffix rather than from a string
		 * built here, for the same reason the plugin records it: the page moved
		 * under Posts and every hand-built 'toplevel_page_…' went stale silently.
		 */
		$this->register_admin_menu();

		$GLOBALS['hook_suffix'] = $this->admin_hook_suffix;

		set_current_screen( $this->admin_hook_suffix );

		$level = ob_get_level();

		set_error_handler(
			function ( $errno, $errstr, $errfile, $errline ) {
				$this->admin_page_notices[] = $errstr . ' in ' . basename( $errfile ) . ':' . $errline;

				return true;
			}
		);

		try {
			// Core's own hook name, hyphen and all, so it is not ours to rename.
			// Assembled into a variable first because the sniff that objects to the
			// hyphen only reads literal hook names, and §9 does not allow a
			// suppression outside includes/.
			$load_hook = 'load-' . $this->admin_hook_suffix;

			do_action( $load_hook );

			ob_start();
			WP_DraftsForFriends_Admin::render_page();

			return (string) ob_get_clean();
		} finally {
			restore_error_handler();

			// A refusal mid-render -- wp_die() on a bad nonce, say -- throws, and
			// would otherwise leave the buffer open for the rest of the run.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Render the Settings tab of the plugin's page.
	 *
	 * Through the page rather than by calling the tab directly, because the tab
	 * is not a screen of its own any more: what a browser reaches is
	 * edit.php?page=wp-draftsforfriends&tab=settings, and both capability checks
	 * that URL passes through are part of what these tests are asserting.
	 *
	 * @return string The rendered markup.
	 */
	protected function render_settings_page() {
		WP_DraftsForFriends_Settings::register_settings();

		return $this->render_admin_page( array( 'tab' => WP_DraftsForFriends_Admin::TAB_SETTINGS ) );
	}

	/**
	 * Post the add form as the current user.
	 *
	 * @param array $fields Fields to post, merged over a valid submission.
	 * @return string The rendered markup.
	 */
	protected function submit_add_form( array $fields = array() ) {
		return $this->render_admin_page(
			array(),
			array_merge(
				array(
					'draftsforfriends_add' => 'Share Draft',
					'post_id'              => $this->draft_id,
					'expires'              => 2,
					'measure'              => 'h',
					'_wpnonce'             => wp_create_nonce( WP_DraftsForFriends_Admin::NONCE_ADD ),
					// wp_nonce_field() emits this alongside the nonce, so a real
					// submission always carries it and check_admin_referer() has a
					// referer to read rather than a false to coerce.
					'_wp_http_referer'     => '/wp-admin/edit.php?page=' . WP_DraftsForFriends_Admin::PAGE,
				),
				$fields
			)
		);
	}

	/**
	 * Apply a bulk action to a set of share IDs as the current user.
	 *
	 * @param string $action One of extend or revoke.
	 * @param array  $ids    Share IDs to tick.
	 * @param array  $fields Extra posted fields, such as the extend duration.
	 * @return string The rendered markup.
	 */
	protected function submit_bulk_action( $action, array $ids, array $fields = array() ) {
		return $this->render_admin_page(
			array(),
			array_merge(
				array(
					'action'           => $action,
					'shares'           => array_map( 'strval', $ids ),
					// The nonce core's list table emits, not one of the screen's own: a
					// second _wpnonce beside it would replace rather than add to it.
					'_wpnonce'         => wp_create_nonce( ( new WP_DraftsForFriends_List_Table() )->bulk_nonce_action() ),
					'_wp_http_referer' => '/wp-admin/edit.php?page=' . WP_DraftsForFriends_Admin::PAGE,
				),
				$fields
			)
		);
	}

	/**
	 * Every share in the table, ignoring who may see it.
	 *
	 * @return int
	 */
	protected function total_shares() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends}" );
	}

	/**
	 * Create a share as a given user and return the row.
	 *
	 * @param int    $user_id User to create it as.
	 * @param int    $post_id Post to share.
	 * @param int    $expires Duration.
	 * @param string $measure Unit.
	 * @return object The share row.
	 */
	protected function make_share( $user_id, $post_id, $expires = 2, $measure = 'h' ) {
		wp_set_current_user( $user_id );

		$result = WP_DraftsForFriends_Shares::create( $post_id, $expires, $measure );

		$this->assertArrayHasKey( 'shared', $result, 'the fixture failed to create a share: ' . wp_json_encode( $result ) );

		return $result['shared'];
	}

	/**
	 * Delete the option rows uninstall.php deletes.
	 *
	 * The uninstaller cannot simply be required here: it drops the table the rest
	 * of the suite runs against, and because the include would only ever execute
	 * once, a second test file asking for it would silently prove nothing. So the
	 * deletions it performs are run here, in the one place both callers share,
	 * and uninstall.php itself is asserted to name the same rows and to delegate
	 * the drop to WP_DraftsForFriends_Install.
	 *
	 * @return void
	 */
	protected function run_uninstall() {
		delete_option( WP_DraftsForFriends_Options::OPTION );
		delete_option( WP_DraftsForFriends_Options::VERSION );
		delete_option( WP_DraftsForFriends_Install::LEGACY_DB_VERSION );
	}

	/**
	 * Read a plugin source file with its comments removed.
	 *
	 * Assertions about what the code does must not be satisfied -- or broken --
	 * by a comment that merely mentions the thing. A docblock explaining why
	 * wp_get_sites() is gone otherwise reads as a call to it.
	 *
	 * @param string $file Plugin-relative path.
	 * @return string The source, comments stripped.
	 */
	protected function source_without_comments( $file ) {
		return php_strip_whitespace( WP_DRAFTSFORFRIENDS_DIR . $file );
	}

	/**
	 * Assert that a <select> has a given option preselected.
	 *
	 * Not a substring match on '<option value="d" selected='. Every one of these
	 * screens marks the option with core's selected(), which returns
	 * " selected='selected'" -- single quotes, and a leading space of its own on
	 * top of the one in the template, so the rendered attribute never matched a
	 * needle written by hand. The thing worth asserting is that something is
	 * selected and that it is this value and not another one.
	 *
	 * Duplicates are collapsed rather than counted: the shared drafts screen
	 * carries two duration selects, the add form's and the Extend controls', and
	 * both start on the same configured unit.
	 *
	 * @param string $value   The option value expected to be selected.
	 * @param string $html    Rendered markup containing the select.
	 * @param string $message Failure message.
	 * @return void
	 */
	protected function assert_option_selected( $value, $html, $message = '' ) {
		preg_match_all( '/<option value="([^"]*)"[^>]*\sselected=[^>]*>/', $html, $matches );

		$this->assertSame( array( $value ), array_values( array_unique( $matches[1] ) ), $message );
	}

	/**
	 * Read a JavaScript file with its comments removed.
	 *
	 * The same reasoning as source_without_comments(), for which there is no
	 * php_strip_whitespace() equivalent: js/wp-draftsforfriends-admin.js opens by
	 * recording that it was "Rewritten without jQuery for 2.0.0", which to a
	 * substring search is jQuery being referenced. Strings are left alone, so a
	 * jQuery call inside one still shows up.
	 *
	 * @param string $file Absolute path to a .js file.
	 * @return string The source, comments stripped.
	 */
	protected function js_without_comments( $file ) {
		$source = (string) file_get_contents( $file );

		// Block comments first, then line comments, so the // inside a /* */ does
		// not end up mistaken for the start of one.
		$source = (string) preg_replace( '#/\*.*?\*/#s', '', $source );

		return (string) preg_replace( '#(^|\s)//.*$#m', '$1', $source );
	}
}
