<?php
/**
 * WP-DraftsForFriends class-draftsforfriends-table.php
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
 * @since 2.0.0
 */
class DraftsForFriends_Table extends WP_List_Table {

	/**
	 * Default rows per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param array $args Passed through to WP_List_Table. render_row() supplies a
	 *                    screen, because an AJAX request has no current one and
	 *                    WP_List_Table would otherwise reach for $hook_suffix.
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
			'id'            => __( 'ID', 'wp-draftsforfriends' ),
			'date_created'  => __( 'Date Created', 'wp-draftsforfriends' ),
			'post_title'    => __( 'Post', 'wp-draftsforfriends' ),
			'link'          => __( 'Link', 'wp-draftsforfriends' ),
			'date_expired'  => __( 'Expires After', 'wp-draftsforfriends' ),
			'date_extended' => __( 'Last Date Extended', 'wp-draftsforfriends' ),
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
			'date_created'  => array( 'date_created', true ),
			'post_title'    => array( 'post_title', false ),
			'date_expired'  => array( 'date_expired', false ),
			'date_extended' => array( 'date_extended', false ),
		);
	}

	/**
	 * The column row actions hang off.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'link';
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
	 * Fetch, sort and paginate the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = $this->column_headers();

		$per_page = $this->get_items_per_page( 'draftsforfriends_per_page', self::PER_PAGE );
		$paged    = max( 1, (int) $this->get_pagenum() );

		// Sorting and paging arguments on a read-only list screen. A sortable
		// column header is a link core builds by swapping orderby and order on
		// the current URL, so it carries no nonce and there is none to verify --
		// which is why the shared phpcs.xml relaxes that sniff for *-table.php
		// across the whole collection rather than here.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_created';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';

		$total = DraftsForFriends_Shares::count();

		$this->items = DraftsForFriends_Shares::query( $orderby, $order, ( $paged - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Column headers in the shape _column_headers expects.
	 *
	 * Built here rather than left to get_column_info() so a single row can be
	 * rendered for the AJAX response without a screen being set up.
	 *
	 * @return array
	 */
	private function column_headers() {
		return array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			$this->get_default_primary_column_name(),
		);
	}

	/**
	 * A row, carrying the id the script uses to find it again.
	 *
	 * @param object $item Share row.
	 * @return void
	 */
	public function single_row( $item ) {
		echo '<tr id="draftsforfriends-current-' . (int) $item->id . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Render one row on its own, for the script to inject.
	 *
	 * @param object|null $item Share row.
	 * @return string HTML, or an empty string when there is no row.
	 */
	public static function render_row( $item ) {
		if ( empty( $item ) ) {
			return '';
		}

		$table                  = new self( array( 'screen' => 'edit' ) );
		$table->_column_headers = $table->column_headers();

		ob_start();
		$table->single_row( $item );

		return (string) ob_get_clean();
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
		return esc_html( DraftsForFriends_Shares::countdown( $item->date_expired ) );
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
	 * The share link, its actions, and the inline extend controls.
	 *
	 * @param object $item Share row.
	 * @return string
	 */
	public function column_link( $item ) {
		$url = DraftsForFriends_Shares::url( $item );
		$id  = (int) $item->id;

		ob_start();
		?>
		<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a>
		<div class="row-actions hide-if-no-js">
			<span class="collapsed">
				<a href="#" class="draftsforfriends-expand" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Extend', 'wp-draftsforfriends' ); ?></a>
			</span>
			<span class="expanded">
				<a href="#" class="draftsforfriends-collapse" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Cancel', 'wp-draftsforfriends' ); ?></a>
			</span>
			|
			<span class="trash">
				<?php
				// esc_attr(), not esc_js(): this is an HTML attribute. esc_js()
				// would put backslash escapes into the title the confirmation
				// dialog shows.
				?>
				<a href="#" class="draftsforfriends-delete"
					data-id="<?php echo esc_attr( $id ); ?>"
					data-post-title="<?php echo esc_attr( $item->post_title ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'draftsforfriends-delete-' . $id ) ); ?>"><?php esc_html_e( 'Delete', 'wp-draftsforfriends' ); ?></a>
			</span>
		</div>
		<div class="draftsforfriends-extend-form expanded">
			<label>
				<?php esc_html_e( 'Extend by', 'wp-draftsforfriends' ); ?>
				<input type="number" min="1" step="1" class="draftsforfriends-expires small-text" value="2" />
			</label>
			<?php
			// The number input above is wrapped in its label; this one needs an
			// explicit association, and the id has to carry the share id because
			// every row renders one of these.
			?>
			<label class="screen-reader-text" for="draftsforfriends-measure-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Duration unit', 'wp-draftsforfriends' ); ?></label>
			<select id="draftsforfriends-measure-<?php echo esc_attr( $id ); ?>" class="draftsforfriends-measure">
				<?php foreach ( DraftsForFriends_Admin::measures() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'h', $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button draftsforfriends-extend"
				data-id="<?php echo esc_attr( $id ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'draftsforfriends-extend-' . $id ) ); ?>"><?php esc_html_e( 'Go', 'wp-draftsforfriends' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
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
