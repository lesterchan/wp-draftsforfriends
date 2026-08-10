<?php
/**
 * The shared drafts list table.
 *
 * @package WP-DraftsForFriends
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

	public function test_no_state_changing_operation_is_a_row_action() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$table = $this->table();
		$table->prepare_items();

		ob_start();
		$table->single_row( $share );
		$html = (string) ob_get_clean();

		// A row action is a GET, and a prefetch of one would revoke a share that
		// cannot be restored or silently extend public access to an unpublished
		// post. Both stay bulk actions on a form post; the row actions this
		// table does have change nothing.
		$row_actions = $this->row_actions_markup( $html );

		$this->assertNotSame( '', $row_actions, 'The row has actions, so this assertion has something to check.' );
		$this->assertStringNotContainsStringIgnoringCase( 'extend', $row_actions, 'extending must not be reachable by following a link' );
		$this->assertStringNotContainsStringIgnoringCase( 'revoke', $row_actions, 'revoking must not be reachable by following a link' );
		$this->assertStringNotContainsStringIgnoringCase( 'delete', $row_actions, 'deleting must not be reachable by following a link' );
	}

	/**
	 * The row-actions container out of a rendered row, or an empty string.
	 *
	 * @param string $html A rendered table row.
	 * @return string
	 */
	private function row_actions_markup( $html ) {
		preg_match( '#<div class="row-actions">(.*?)</div>#s', $html, $matches );

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	public function test_pagination_is_at_twenty() {
		$this->assertSame( 20, WP_DraftsForFriends_List_Table::PER_PAGE, '§4.3 sets pagination at 20' );

		$table = $this->table();
		$table->prepare_items();

		$this->assertSame( 20, $table->get_pagination_arg( 'per_page' ), 'The list pages at twenty, which is what the item count below is measured against.' );
	}

	public function test_the_no_items_message_is_the_plugins_own() {
		ob_start();
		$this->table()->no_items();
		$this->assertSame( 'No shared drafts!', (string) ob_get_clean(), 'The empty state is the plugin wording, not the core default.' );
	}

	public function test_the_checkbox_column_is_labelled_with_the_post_title() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_cb( $share );

		$this->assertStringContainsString( 'name="shares[]"', $html, 'the handler reads shares[]' );
		$this->assertStringContainsString( 'value="' . (int) $share->id . '"', $html, 'The checkbox carries the share id it acts on.' );
		$this->assertStringContainsString( 'id="cb-select-' . (int) $share->id . '"', $html, 'The checkbox id matches the label that names it, so the two are associated.' );
		$this->assertStringContainsString( 'class="screen-reader-text"', $html, 'a bare checkbox tells a screen reader nothing' );
		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'the post title reached the label unescaped' );
	}

	public function test_the_link_column_carries_the_share_url() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_link( $share );

		$this->assertStringContainsString( 'draftsforfriends=' . $share->hash, $html, 'The link column carries the share URL.' );
		$this->assertStringNotContainsString( '<button', $html, 'copying moved to a row action; the column is the link alone' );
	}

	public function test_the_row_actions_are_edit_draft_and_copy_link() {
		wp_set_current_user( $this->author_id );

		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_post_title( $share );

		$this->assertStringContainsString( 'row-actions', $html, 'The actions use core\'s row action markup, so they behave like every other list.' );
		$this->assertStringContainsString( 'Edit Draft', $html, 'The row links back to the editor.' );
		$this->assertStringContainsString( 'post=' . $this->draft_id, $html, 'Edit Draft points at the post the share is for.' );
		$this->assertStringContainsString( 'Copy Link', $html, 'Copying the link is a row action.' );
		$this->assertStringContainsString( 'draftsforfriends-copy', $html, 'the script finds the copy button by this class' );
		$this->assertStringContainsString( 'data-link=', $html, 'the script reads the URL from a data attribute' );
		$this->assertStringContainsString( 'hide-if-no-js', $html, 'the copy button must not appear where the script has not run' );
	}

	public function test_the_copy_action_is_a_button_and_never_a_link() {
		wp_set_current_user( $this->author_id );

		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_post_title( $share );

		// A prefetched GET must never be able to copy, extend or revoke; the
		// share URL belongs in a data attribute, not in an href.
		$this->assertMatchesRegularExpression( '/<button[^>]*draftsforfriends-copy/', $html, 'the copy action must be a button' );
		$this->assertStringNotContainsString( 'href="' . WP_DraftsForFriends_Shares::url( $share ), $html, 'the share URL must not be an href in the row actions' );
	}

	public function test_the_row_actions_name_the_post_for_a_screen_reader() {
		wp_set_current_user( $this->author_id );

		$share = $this->make_share( $this->author_id, $this->draft_id );
		$html  = $this->table()->column_post_title( $share );

		// Both actions, and not core's own "Show more details" toggle, which
		// row_actions() appends with a screen-reader-text span of its own.
		$this->assertSame( 2, substr_count( $html, 'for Draft &lt;b&gt;Title' ), 'Both row actions say which draft they act on.' );
		$this->assertStringNotContainsString( 'Draft <b>Title</b>', $html, 'the post title reached a row action unescaped' );
	}

	public function test_edit_draft_is_withheld_from_somebody_who_may_not_edit_the_post() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$html = $this->table()->column_post_title( $share );

		$this->assertStringNotContainsString( 'Edit Draft', $html, 'an Edit link to a post the reader cannot edit only leads to a refusal' );
		$this->assertStringContainsString( 'Copy Link', $html, 'The copy action does not depend on being able to edit.' );
	}

	public function test_a_share_that_has_never_been_extended_reads_na() {
		$share = $this->make_share( $this->author_id, $this->draft_id );

		$this->assertSame( 'N/A', $this->table()->column_date_extended( $share ), 'A share never extended reads N/A rather than the epoch.' );

		// The zero datetime a pre-2.0.0 row could hold reads the same way.
		$share->date_extended = '0000-00-00 00:00:00';

		$this->assertSame( 'N/A', $this->table()->column_date_extended( $share ), 'A zeroed extension date reads N/A too, not 1 January 1970.' );
	}

	public function test_the_dates_are_rendered_in_the_sites_own_timezone() {
		update_option( 'timezone_string', 'Asia/Singapore' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$share = $this->make_share( $this->author_id, $this->draft_id );

		$expected = wp_date( 'H:i Y-m-d', (int) mysql2date( 'G', $share->date_created ) );

		$this->assertSame( $expected, $this->table()->column_date_created( $share ), 'Dates are rendered in the site timezone, not in UTC.' );
	}

	public function test_the_default_column_escapes_and_tolerates_a_missing_property() {
		$share = $this->make_share( $this->author_id, $this->draft_id );
		$table = $this->table();

		$this->assertSame( '', $table->column_default( $share, 'no_such_column' ), 'A column the row has no property for renders empty rather than warning.' );
		$this->assertSame( esc_html( $share->hash ), $table->column_default( $share, 'hash' ), 'A column that does exist is escaped on the way out.' );
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

		$this->assertStringContainsString( 'name="extend_expires"', $html, 'The row offers its own duration field.' );
		$this->assertStringContainsString( 'value="4"', $html, 'It starts on the configured duration, not on a hardcoded one.' );
		$this->assertStringContainsString( 'name="extend_measure"', $html, 'The row offers its own unit field.' );
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

		$this->assertSame( 2, $table->get_pagination_arg( 'total_items' ), 'The item count is the number of shares, which is what the pager divides.' );
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
