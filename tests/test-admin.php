<?php
/**
 * The admin screen.
 *
 * @package WP-DraftsForFriends
 */

/**
 * DraftsForFriends_Admin and the list table it renders.
 */
class Test_DraftsForFriends_Admin extends WP_UnitTestCase {

	/**
	 * An author, who may only touch their own posts.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * An editor, who has edit_others_posts.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * The author's draft.
	 *
	 * @var int
	 */
	private $draft_id;

	/**
	 * The editor's draft.
	 *
	 * @var int
	 */
	private $editor_draft_id;

	/**
	 * Diagnostics raised while a screen rendered.
	 *
	 * @var array
	 */
	private $notices = array();

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-draftsforfriends-table.php';
		require_once WP_DRAFTSFORFRIENDS_DIR . 'includes/class-draftsforfriends-admin.php';

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
	 * Clean up request state.
	 */
	public function tear_down() {
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Render the screen, collecting any diagnostics it raises.
	 *
	 * @param array $get Query arguments the request arrived with.
	 * @return string The rendered markup.
	 */
	private function render( array $get = array() ) {
		$_GET     = $get;
		$_REQUEST = $get;

		// A real admin request always has $hook_suffix set by the time a screen
		// renders, and WP_List_Table reaches for it through WP_Screen. WordPress
		// 6.0 reads it without an isset() guard, so leaving it unset raised a
		// notice there and nowhere else -- a gap in this fixture, not in the
		// plugin.
		$GLOBALS['hook_suffix'] = 'posts_page_' . DraftsForFriends_Admin::SLUG;

		set_current_screen( 'edit' );

		$this->notices = array();

		set_error_handler(
			function ( $errno, $errstr, $errfile, $errline ) {
				$this->notices[] = $errstr . ' in ' . basename( $errfile ) . ':' . $errline;
				return true;
			}
		);

		try {
			$admin = new DraftsForFriends_Admin();

			ob_start();
			$admin->render_page();

			return (string) ob_get_clean();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * The screen renders, cleanly, with the things it is supposed to show.
	 */
	public function test_screen_renders() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' )['shared'];
		$html  = $this->render();

		$this->assertSame( array(), $this->notices, 'the screen raised PHP diagnostics' );
		$this->assertStringContainsString( 'Drafts for Friends', $html );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( $share->hash, $html );
	}

	/**
	 * The damage no PHP-level tool reports.
	 */
	public function test_screen_has_no_leaked_markup() {
		wp_set_current_user( $this->author_id );
		DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' );

		$html = $this->render();

		$this->assertStringNotContainsString( '<?php', $html );
		$this->assertStringNotContainsString( 'translators:', $html, 'a translators comment reached HTML context' );
		$this->assertStringNotContainsString( '&amp;amp;', $html, 'something was escaped twice' );
		$this->assertStringNotContainsString( 'Fatal error', $html );
	}

	/**
	 * The post title is escaped everywhere it appears.
	 */
	public function test_post_title_is_escaped() {
		wp_set_current_user( $this->author_id );
		DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'a raw post title reached the page' );
		$this->assertStringContainsString( 'Draft &lt;b&gt;Title&lt;/b&gt;', $html );
	}

	/**
	 * An author sees their own shares and not anyone else's.
	 */
	public function test_list_is_scoped_by_capability() {
		wp_set_current_user( $this->editor_id );
		$editor_share = DraftsForFriends_Shares::create( $this->editor_draft_id, 2, 'h' )['shared'];

		wp_set_current_user( $this->author_id );
		$author_share = DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' )['shared'];

		$html = $this->render();

		$this->assertStringContainsString( $author_share->hash, $html );
		$this->assertStringNotContainsString( $editor_share->hash, $html, "the author's screen leaked the editor's share" );

		wp_set_current_user( $this->editor_id );

		$html = $this->render();

		$this->assertStringContainsString( $author_share->hash, $html, 'an editor should see every share' );
		$this->assertStringContainsString( $editor_share->hash, $html );
	}

	/**
	 * Sorting and paging arguments cannot reach the SQL or break the screen.
	 *
	 * @dataProvider data_request_args
	 *
	 * @param array $get Query arguments.
	 */
	public function test_request_arguments_are_constrained( array $get ) {
		wp_set_current_user( $this->author_id );
		DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' );

		$html = $this->render( $get );

		$this->assertSame( array(), $this->notices );
		$this->assertStringContainsString( 'Currently Shared Drafts', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'database error', $html );
		$this->assertNotEmpty( get_post( $this->draft_id ), 'wp_posts survived' );
	}

	/**
	 * Query argument combinations to try.
	 *
	 * Nothing here may ask for a page beyond the last one: WP_List_Table answers
	 * that with wp_redirect() and exit, which would take the test runner with it.
	 *
	 * @return array
	 */
	public function data_request_args() {
		return array(
			'sort by id'       => array(
				array(
					'orderby' => 'id',
					'order'   => 'asc',
				),
			),
			'sort by expiry'   => array(
				array(
					'orderby' => 'date_expired',
					'order'   => 'desc',
				),
			),
			'sort by title'    => array(
				array(
					'orderby' => 'post_title',
					'order'   => 'asc',
				),
			),
			'injected orderby' => array(
				array(
					'orderby' => 'id; DROP TABLE wp_posts',
					'order'   => 'asc',
				),
			),
			'injected order'   => array(
				array(
					'orderby' => 'date_created',
					'order'   => "asc'--",
				),
			),
			'non-numeric page' => array( array( 'paged' => 'abc' ) ),
			'first page'       => array( array( 'paged' => '1' ) ),
			'legacy sort args' => array(
				array(
					'dff_sortby'    => 'id;DROP TABLE wp_posts',
					'dff_sortorder' => 'asc',
				),
			),
			'legacy page arg'  => array( array( 'dff_page' => '-1' ) ),
		);
	}

	/**
	 * The share link is rendered and points at the post.
	 */
	public function test_share_link_is_rendered() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' )['shared'];
		$html  = $this->render();

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html );
		$this->assertStringContainsString( 'p=' . $this->draft_id, $html );
	}

	/**
	 * The menu is registered under Posts, on a slug that does not name the
	 * plugin's directory.
	 */
	public function test_menu_slug_does_not_embed_the_directory_name() {
		global $submenu;

		wp_set_current_user( $this->editor_id );
		set_current_screen( 'edit' );

		$submenu         = array();
		$GLOBALS['menu'] = array();

		$admin = new DraftsForFriends_Admin();
		$admin->add_menu();

		$slugs = wp_list_pluck( (array) ( $submenu['edit.php'] ?? array() ), 2 );

		$this->assertContains( 'draftsforfriends', $slugs );
		$this->assertStringNotContainsString( 'wp-draftsforfriends', implode( ' ', $slugs ), 'the menu slug still names the plugin directory' );
	}

	/**
	 * The screen is not offered to users without the capability.
	 */
	public function test_menu_requires_the_capability() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'edit' );

		$submenu         = array();
		$GLOBALS['menu'] = array();

		$admin = new DraftsForFriends_Admin();
		$admin->add_menu();

		$slugs = wp_list_pluck( (array) ( $submenu['edit.php'] ?? array() ), 2 );

		$this->assertNotContains( 'draftsforfriends', $slugs );
	}

	/**
	 * A single row can be rendered on its own, which is what the endpoint returns
	 * for the script to inject.
	 */
	public function test_render_row_standalone() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' )['shared'];
		$html  = DraftsForFriends_Table::render_row( $share );

		$this->assertStringContainsString( 'draftsforfriends-current-' . (int) $share->id, $html );
		$this->assertStringContainsString( $share->hash, $html );
		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html );
	}

	/**
	 * Rendering nothing is not an error.
	 */
	public function test_render_row_handles_nothing() {
		$this->assertSame( '', DraftsForFriends_Table::render_row( null ) );
	}

	/**
	 * A share that has never been extended reads N/A rather than a bogus date.
	 */
	public function test_never_extended_reads_na() {
		wp_set_current_user( $this->author_id );

		$share = DraftsForFriends_Shares::create( $this->draft_id, 2, 'h' )['shared'];
		$html  = DraftsForFriends_Table::render_row( $share );

		$this->assertStringContainsString( 'N/A', $html );
	}
}
