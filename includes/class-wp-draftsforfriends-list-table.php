<?php
/**
 * The shared drafts list table.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The currently shared drafts, on core's list table.
 *
 * Replaces roughly 250 lines of hand-rolled pagination links and sortable column
 * headers the plugin carried before 2.0.0, along with its own dff_page,
 * dff_sortby and dff_sortorder query arguments. Core's paged, orderby and order
 * are used instead, so the screen behaves like every other list in wp-admin and
 * picks up the per-page screen option for free.
 *
 * §4.3 wants pagination at 20, sortable columns, row actions on hover, bulk
 * actions where destructive operations exist and a no_items() message. This
 * table has four of those five, and deliberately has no row actions at all.
 *
 * A row action is a GET. Both things a row of this table offers change state:
 * revoking a share cannot be undone, and extending one silently prolongs public
 * access to a post its author has not published. A browser or a link checker
 * that prefetches would revoke every share on the page, and the author would
 * find out when a friend said the link had stopped working. That is the same
 * reasoning wp-dbmanager recorded for routing its destructive operations through
 * POST bulk actions, and §4.3 allows the deviation with a stated reason.
 *
 * So both operations are bulk actions on a real form post, and the duration
 * Extend uses sits in extra_tablenav() beside them.
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends_List_Table extends WP_List_Table {

	/**
	 * Default rows per page.
	 *
	 * Twenty, which is §4.3's figure and what every core list table uses. It was
	 * 50 before 2.0.0, inherited from a hand-rolled table whose pagination did
	 * not survive sorting.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param array $args Passed through to WP_List_Table.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			wp_parse_args(
				$args,
				array(
					'singular' => 'shared-draft',
					'plural'   => 'shared-drafts',
					'ajax'     => false,
				)
			)
		);
	}

	/**
	 * The table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'ID', 'wp-draftsforfriends' ),
			'post_title'    => __( 'Post', 'wp-draftsforfriends' ),
			'link'          => __( 'Link', 'wp-draftsforfriends' ),
			'date_created'  => __( 'Date Created', 'wp-draftsforfriends' ),
			'date_extended' => __( 'Last Date Extended', 'wp-draftsforfriends' ),
			'date_expired'  => __( 'Expires After', 'wp-draftsforfriends' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'            => array( 'id', false ),
			'post_title'    => array( 'post_title', false ),
			'date_created'  => array( 'date_created', true ),
			'date_extended' => array( 'date_extended', false ),
			'date_expired'  => array( 'date_expired', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * Both of the things a share can have done to it, because neither belongs on
	 * a hover row action -- see the class docblock.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'extend' => __( 'Extend selected', 'wp-draftsforfriends' ),
			'revoke' => __( 'Revoke selected', 'wp-draftsforfriends' ),
		);
	}

	/**
	 * The column a primary-column-only cell hangs off.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'post_title';
	}

	/**
	 * Message shown when nothing is shared.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No shared drafts!', 'wp-draftsforfriends' );
	}

	/**
	 * The duration the Extend bulk action will use.
	 *
	 * In the tablenav rather than in each row because the bulk action is what
	 * applies it, and core's bulk area is a dropdown with nowhere to put two more
	 * controls. A per-row duration would need a form per row, and the rows are
	 * already inside the form the bulk action posts -- nested forms are not
	 * markup a browser will honour.
	 *
	 * It starts on the configured default, so extending several shares by the
	 * usual amount is three clicks and no typing.
	 *
	 * @param string $which Which tablenav is being rendered.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$expires = (int) WP_DraftsForFriends_Options::get( 'expires' );
		$measure = (string) WP_DraftsForFriends_Options::get( 'measure' );
		?>
		<div class="alignleft actions">
			<label for="draftsforfriends-extend-expires"><?php esc_html_e( 'Extend by', 'wp-draftsforfriends' ); ?></label>
			<input type="number" min="1" max="9999" step="1" class="small-text" id="draftsforfriends-extend-expires" name="extend_expires" value="<?php echo esc_attr( $expires ); ?>" />

			<label class="screen-reader-text" for="draftsforfriends-extend-measure"><?php esc_html_e( 'Duration unit', 'wp-draftsforfriends' ); ?></label>
			<select id="draftsforfriends-extend-measure" name="extend_measure">
				<?php foreach ( WP_DraftsForFriends_Shares::measures() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $measure, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * The sort this request asked for, checked against the sortable columns.
	 *
	 * A sortable column header is a link, and core builds it by swapping orderby
	 * and order on the current URL -- so it carries no nonce, there is none to
	 * verify, and every core list table reads these same two keys the same way.
	 * That is why the shared phpcs.xml relaxes NonceVerification for *-table.php
	 * across all nineteen plugins rather than each of them doing it inline.
	 *
	 * @return array{0:string,1:string} Column key, and 'asc' or 'desc'.
	 */
	private static function requested_sort() {
		$request = wp_unslash( $_GET );

		$candidate = isset( $request['orderby'] ) ? sanitize_key( $request['orderby'] ) : '';
		$orderby   = in_array( $candidate, WP_DraftsForFriends_Shares::SORTABLE, true ) ? $candidate : 'date_created';

		$candidate = isset( $request['order'] ) ? sanitize_key( $request['order'] ) : '';
		$order     = 'asc' === $candidate ? 'asc' : 'desc';

		return array( $orderby, $order );
	}

	/**
	 * Fetch, sort and paginate the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);

		$per_page = $this->get_items_per_page( 'wp_draftsforfriends_per_page', self::PER_PAGE );
		$paged    = max( 1, (int) $this->get_pagenum() );

		list( $orderby, $order ) = self::requested_sort();

		$total = WP_DraftsForFriends_Shares::count();

		$this->items = WP_DraftsForFriends_Shares::query( $orderby, $order, ( $paged - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Format a GMT datetime in the site's own timezone and format.
	 *
	 * @param string $gmt_date MySQL datetime, GMT.
	 * @return string
	 */
	private function local_datetime( $gmt_date ) {
		$timestamp = (int) mysql2date( 'G', $gmt_date );

		return (string) wp_date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), $timestamp );
	}

	/**
	 * The row selection checkbox.
	 *
	 * Labelled with the post title, because a column of bare checkboxes read out
	 * one after another tells a screen reader user nothing about which share they
	 * are about to revoke.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_cb( $item ) {
		$id = (int) $item->id;

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input type="checkbox" id="cb-select-%1$d" name="shares[]" value="%1$d" />',
			$id,
			/* translators: %s: post title. */
			esc_html( sprintf( __( 'Select the shared draft for %s', 'wp-draftsforfriends' ), $item->post_title ) )
		);
	}

	/**
	 * The ID column.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_id( $item ) {
		return (string) (int) $item->id;
	}

	/**
	 * The date created column.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_date_created( $item ) {
		return esc_html( $this->local_datetime( $item->date_created ) );
	}

	/**
	 * The post title column.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_post_title( $item ) {
		return esc_html( $item->post_title );
	}

	/**
	 * The expiry countdown column.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_date_expired( $item ) {
		return esc_html( WP_DraftsForFriends_Shares::countdown( $item->date_expired ) );
	}

	/**
	 * The last extended column.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_date_extended( $item ) {
		if ( empty( $item->date_extended ) || '0000-00-00 00:00:00' === $item->date_extended ) {
			return esc_html__( 'N/A', 'wp-draftsforfriends' );
		}

		return esc_html( $this->local_datetime( $item->date_extended ) );
	}

	/**
	 * The share link, and a button to copy it.
	 *
	 * Copying is the one thing anybody actually does with this column -- the
	 * point of the plugin is to send the link to somebody -- and it is the only
	 * control on the screen that changes nothing, so it is also the only one that
	 * may be a script-only affordance. hide-if-no-js keeps it off the page where
	 * the script has not run, leaving the link itself to be selected by hand.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_link( $item ) {
		$url = WP_DraftsForFriends_Shares::url( $item );

		return sprintf(
			'<a href="%1$s">%2$s</a> <button type="button" class="button hide-if-no-js draftsforfriends-copy" data-link="%1$s">%3$s</button>',
			esc_url( $url ),
			esc_html( $url ),
			esc_html__( 'Copy link', 'wp-draftsforfriends' )
		);
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * @param object $item        Share row.
	 * @param string $column_name Column being rendered.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}
}
