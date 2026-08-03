<?php
/**
 * The shared drafts list table.
 *
 * @package wp-draftsforfriends
 */

/**
 * WP_DraftsForFriends_List_Table: its columns, its bulk actions and its paging.
 */
class WP_DraftsForFriends_List_Table_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * A table with a screen behind it, which WP_List_Table reaches through
	 * WP_Screen the moment it is constructed.
	 *
	 * @return WP_DraftsForFriends_List_Table
	 */
	private function table() {
		// Somebody who may reach the page: add_posts_page() consults the current
		// user and records nothing for a logged-out one, so there would be no hook
		// suffix and no screen for WP_List_Table to find.
		if ( ! is_user_logged_in() ) {
			wp_set_current_user( $this->author_id );
		}

		// The recorded hook suffix, never a hand-built one: the page moved under
		// Posts and every 'toplevel_page_…' string went stale without erroring.
		set_current_screen( $this->register_admin_menu() );

		return new WP_DraftsForFriends_List_Table();
	}

	public function test_the_columns_are_the_ones_the_screen_shows() {
		$this->assertSame(
			array( 'cb', 'id', 'post_title', 'link', 'date_created', 'date_extended', 'date_expired' ),
			array_keys( $this->table()->get_columns() ),
			'the column set or its order changed'
		);
	}

	public function test_every_column_but_the_link_is_sortable() {
		$table    = $this->table();
		$sortable = array_keys( $this->invoke( $table, 'get_sortable_columns' ) );

		$this->assertSame(
			array( 'id', 'post_title', 'date_created', 'date_extended', 'date_expired' ),
			$sortable,
			'the sortable set changed'
		);

		foreach ( $sortable as $column ) {
			$this->assertContains(
				$column,
				WP_DraftsForFriends_Shares::SORTABLE,
				"the '{$column}' column is offered as sortable but the query layer will not sort by it"
			);
		}
	}

	public function test_the_bulk_actions_are_extend_and_revoke() {
		$this->assertSame(
			array( 'extend', 'revoke' ),
			array_keys( $this->invoke( $this->table(), 'get_bulk_actions' ) ),
			'§4.3 wants bulk actions where destructive operations exist, and both of this table operations are one'
		);
	}

	public function test_there_are_no_row_actions() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$table = $this->table();
		$table->prepare_items();

		ob_start();
		$table->single_row( $share );
		$html = (string) ob_get_clean();

		// The deviation §4.3 asks to be justified: a row action is a GET, and both
		// operations here change state irreversibly or extend public access.
		//
		// Matched on the container core emits, not on the bare word: every primary
		// column carries the class has-row-actions whether or not there are any,
		// because that is what positions the show-more toggle on a narrow screen.
		// A plain substring search finds that and reports a row action that is not
		// there.
		$this->assertStringNotContainsString(
			'<div class="row-actions"',
			$html,
			'a row action reappeared; see the class docblock for why there are none'
		);
		$this->assertStringContainsString(
			'has-row-actions',
			$html,
			'core stopped emitting the primary-column class this assertion has to tell itself apart from'
		);
	}

	public function test_pagination_is_at_twenty() {
		$this->assertSame( 20, WP_DraftsForFriends_List_Table::PER_PAGE, '§4.3 sets pagination at 20' );

		$table = $this->table();
		$table->prepare_items();

		$this->assertSame( 20, $table->get_pagination_arg( 'per_page' ) );
	}

	public function test_the_no_items_message_is_the_plugins_own() {
		ob_start();
		$this->table()->no_items();
		$this->assertSame( 'No shared drafts!', (string) ob_get_clean() );
	}

	public function test_the_checkbox_column_is_labelled_with_the_post_title() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_cb( $share );

		$this->assertStringContainsString( 'name="shares[]"', $html, 'the handler reads shares[]' );
		$this->assertStringContainsString( 'value="' . (int) $share->id . '"', $html );
		$this->assertStringContainsString( 'id="cb-select-' . (int) $share->id . '"', $html );
		$this->assertStringContainsString( 'class="screen-reader-text"', $html, 'a bare checkbox tells a screen reader nothing' );
		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'the post title reached the label unescaped' );
	}

	public function test_the_link_column_carries_the_share_url_and_a_copy_button() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_link( $share );

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html );
		$this->assertStringContainsString( 'draftsforfriends-copy', $html );
		$this->assertStringContainsString( 'hide-if-no-js', $html, 'the copy button must not appear where the script has not run' );
		$this->assertStringContainsString( 'data-link=', $html, 'the script reads the URL from a data attribute' );
	}

	public function test_a_share_that_has_never_been_extended_reads_na() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$this->assertSame( 'N/A', $this->table()->column_date_extended( $share ) );

		// The zero datetime a pre-2.0.0 row could hold reads the same way.
		$share->date_extended = '0000-00-00 00:00:00';

		$this->assertSame( 'N/A', $this->table()->column_date_extended( $share ) );
	}

	public function test_the_dates_are_rendered_in_the_sites_own_timezone() {
		update_option( 'timezone_string', 'Asia/Singapore' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$share = $this->make_share( $this->author_id, $this->draft_id );

		$expected = wp_date( 'H:i Y-m-d', (int) mysql2date( 'G', $share->date_created ) );

		$this->assertSame( $expected, $this->table()->column_date_created( $share ) );
	}

	public function test_the_default_column_escapes_and_tolerates_a_missing_property() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$table = $this->table();

		$this->assertSame( '', $table->column_default( $share, 'no_such_column' ) );
		$this->assertSame( esc_html( $share->hash ), $table->column_default( $share, 'hash' ) );
	}

	public function test_the_extend_controls_start_on_the_configured_duration() {
		WP_DraftsForFriends_Options::update(
			array(
				'expires' => 4,
				'measure' => 'd',
			)
		);

		$table = $this->table();

		ob_start();
		$this->invoke( $table, 'extra_tablenav', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="extend_expires"', $html );
		$this->assertStringContainsString( 'value="4"', $html );
		$this->assertStringContainsString( 'name="extend_measure"', $html );
		$this->assert_option_selected( 'd', $html, 'the configured unit is not preselected' );

		ob_start();
		$this->invoke( $table, 'extra_tablenav', 'bottom' );

		$this->assertSame( '', (string) ob_get_clean(), 'the duration controls belong above the table only' );
	}

	public function test_the_sort_falls_back_when_the_request_asks_for_nonsense() {
		$this->make_share( $this->author_id, $this->draft_id );

		$_GET = array(
			'orderby' => 'id; DROP TABLE wp_posts',
			'order'   => "asc'--",
		);

		$table = $this->table();
		$table->prepare_items();

		$this->assertNotEmpty( get_post( $this->draft_id ), 'wp_posts survived' );
		$this->assertNotEmpty( $table->items, 'the fallback sort returned nothing' );
	}

	public function test_the_item_count_agrees_with_what_the_list_shows() {
		$this->make_share( $this->author_id, $this->draft_id );
		$this->make_share( $this->author_id, $this->draft_id );

		wp_set_current_user( $this->author_id );

		$table = $this->table();
		$table->prepare_items();

		$this->assertSame( 2, $table->get_pagination_arg( 'total_items' ) );
		$this->assertCount( 2, $table->items, 'The item count agrees with the number of rows the list renders.' );
	}

	/**
	 * Call a protected method on the table.
	 *
	 * The three §4.3 methods worth asserting -- the sortable set, the bulk actions
	 * and the tablenav -- are all protected, because that is how WP_List_Table
	 * declares them. Reflection here rather than a public wrapper on the class:
	 * widening a method's visibility so a test can reach it makes the test the
	 * reason for the API.
	 *
	 * @param object $table  The list table.
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke( $table, $method, ...$args ) {
		$reflection = new ReflectionMethod( $table, $method );

		return $reflection->invokeArgs( $table, $args );
	}
}
