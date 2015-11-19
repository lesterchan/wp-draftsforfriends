<?php
/*
Plugin Name: WP-DraftsForFriends
Plugin URI: http://lesterchan.net/portfolio/programming/php/
Description: Now you don't need to add friends as users to the blog in order to let them preview your drafts. Modified from Drafts for Friends originally by Neville Longbottom.
Version: 1.0.2
Author: Lester 'GaMerZ' Chan
Author URI: http://lesterchan.net
Text Domain: wp-draftsforfriends
*/

/**
 * Drafts for Friends version
 */
define( 'WP_DRAFTSFORFRIENDS_VERSION', '1.0.2' );


/**
 * Class WPDraftsForFriends
 */
class WPDraftsForFriends {
    /**
     * @var null
     */
    private $shared_draft_post = null;


    /**
     * @var int Number of shared drafts to show per page
     */
    private $shared_drafts_per_page = 50;

    /**
     * Constructor method
     *
     * @access public
     */
    public function __construct(){
        global $wpdb;

        // MySQL table name
        $wpdb->draftsforfriends = $wpdb->prefix . 'draftsforfriends';

        add_action( 'init', array( $this, 'init' ) );

        register_activation_hook( __FILE__, array( $this, 'plugin_activation' ) );
    }

    /**
     * Init this plugin
     *
     * @access public
     * @return void
     */
    public function init() {
        // Actions
        add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        add_action( 'wp_ajax_draftsforfriends_admin', array( $this, 'admin_actions_ajax' ) );

        // Filters
        add_filter( 'the_posts', array( $this, 'the_posts_intercept') );
        add_filter( 'posts_results', array( $this, 'posts_results_intercept') );

        // Load Translation
        load_plugin_textdomain( 'wp-draftsforfriends', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * What to do when the plugin is being activated
     *
     * @access public
     * @param boolean Is the plugin being network activated?
     * @return void
     */
    public function plugin_activation( $network_wide ) {
        if ( is_multisite() && $network_wide ) {
            $ms_sites = wp_get_sites();

            if( 0 < sizeof( $ms_sites ) ) {
                foreach ( $ms_sites as $ms_site ) {
                    switch_to_blog( $ms_site['blog_id'] );
                    $this->plugin_activated();
                }
            }

            restore_current_blog();
        } else {
            $this->plugin_activated();
        }
    }

    /**
     * Create plugin table when activated
     *
     * @access private
     * @return void
     */
    private function plugin_activated() {
        global $wpdb;

        $draftsforfriends_table = $wpdb->prefix . 'draftsforfriends';
        $create_sql = "CREATE TABLE $draftsforfriends_table (".
            'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,'.
            'post_id bigint(20) unsigned NOT NULL,'.
            'user_id bigint(20) unsigned NOT NULL,'.
            'hash varchar(32) NOT NULL DEFAULT \'\','.
            'date_created datetime NOT NULL,'.
            'date_extended datetime DEFAULT NULL,'.
            'date_expired datetime NOT NULL,'.
            'PRIMARY KEY (id),'.
            'KEY post_id (post_id),'.
            'KEY user_id (user_id),'.
            'KEY postid_hash_expired (post_id, hash, date_expired));';

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $create_sql );
    }

    /**
     * Load JS and CSS used by this plugin
     *
     * @access public
     * @param string $hook_suffix
     * @return void
     */
    public function admin_scripts( $hook_suffix ) {
        if( 'posts_page_wp-draftsforfriends/wp-draftsforfriends' === strtolower( $hook_suffix ) ) {

            // Minified CSS/CSS URLs
            $admin_css_url = 'css/draftsforfriends-admin.min.css';
            $admin_js_url = 'js/draftsforfriends-admin.min.js';

            // If WP_DEBUG mode we load non-minified URLs
            if( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $admin_css_url = 'css/draftsforfriends-admin.css';
                $admin_js_url = 'js/draftsforfriends-admin.js';
            }

            wp_enqueue_style( 'draftsforfriends-admin', plugins_url( $admin_css_url, __FILE__ ), false, WP_DRAFTSFORFRIENDS_VERSION );
            wp_enqueue_script( 'draftsforfriends-admin', plugins_url( $admin_js_url, __FILE__ ), array( 'jquery' ), WP_DRAFTSFORFRIENDS_VERSION, true );
            wp_localize_script( 'draftsforfriends-admin', 'draftsForFriendsAdminL10n', array(
                'admin_ajax_url'   => admin_url( 'admin-ajax.php' ),
                'confirm_delete'   => __( 'Are you sure you want to delete this shared draft, \'{{post_title}}\'', 'wp-draftsforfriends' ),
                'error_id'         => __( 'Invalid shared draft id', 'wp-draftsforfriends' ),
                'error_post_id'    => __( 'Please choose a draft to share', 'wp-draftsforfriends' ),
                'error_expires'    => __( 'Please choose a valid duration', 'wp-draftsforfriends' ),
                'empty_nonce'      => __( 'Nonce is empty', 'wp-draftsforfriends' ),
                'empty_measure'    => __( 'Duration length is empty', 'wp-draftsforfriends' ),
                'no_shared_drafts' => __( 'No shared drafts!', 'wp-draftsforfriends' )
            ));
        }
    }

    /**
     * Add plugin admin page to "Posts" menu
     *
     * @access public
     * @return void
     */
    public function add_admin_pages() {
        add_submenu_page( 'edit.php', __( 'Drafts for Friends', 'wp-draftsforfriends' ), __( 'Drafts for Friends', 'wp-draftsforfriends' ), 'publish_posts', __FILE__, array( $this, 'output_existing_menu_sub_admin_page' ) );
    }

    /**
     * Process AJAX request
     *
     * @access public
     * @return void
     */
    public function admin_actions_ajax() {
        $output = array( 'error' =>  __( 'No actions specified', 'wp-draftsforfriends' ) );
        if( isset( $_POST['action'] ) && 'draftsforfriends_admin' == $_POST['action'] ) {
            if( ! empty( $_POST['do'] ) ) {
                $nonce_error = array( 'error' =>  __( 'Unable to verify nonce', 'wp-draftsforfriends' ) );
                switch( $_POST['do'] ) {
                    case 'add':
                        if ( wp_verify_nonce( $_POST['_ajax_nonce'], 'draftsforfriends-add' ) )
                            $output = $this->process_add( $_POST );
                        else
                            $output = $nonce_error;
                        break;
                    case 'extend':
                        if ( wp_verify_nonce( $_POST['_ajax_nonce'], 'draftsforfriends-extend-' . intval( $_POST['id'] ) ) )
                            $output = $this->process_extend( $_POST );
                        else
                            $output = $nonce_error;
                        break;
                    case 'delete':
                        if ( wp_verify_nonce( $_POST['_ajax_nonce'], 'draftsforfriends-delete-' . intval( $_POST['id'] ) ) )
                            $output = $this->process_delete( $_POST );
                        else
                            $output = $nonce_error;
                        break;
                }
            }
        }

        echo json_encode( $output );
        exit();
    }

    /**
     * Calculate expiry by multiplying the time unit and the user's specified duration
     *
     * @access private
     * @param int $value Duration
     * @param string $unit Time unit
     * @return int Number of seconds before it expires
     */
    private function calculate_expiry( $value, $unit ) {
        $expiry = 60;
        $multiply = 60;
        if ( isset( $value ) && ( $e = intval( $value ) ) ) {
            $expiry = $e;
        }
        $units = array( 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 24 * 3600 );
        if ( isset( $unit ) && $units[ $unit ] ) {
            $multiply = $units[ $unit ];
        }
        return $expiry * $multiply;
    }

    /**
     * Calculate countdown from timestamp
     *
     * @access private
     * @param string MySQL DateTime
     * @return string Show remaining days/hours/minutes/seconds left
     */
    private function countdown( $date ) {
        $output = array();
        $time = mysql2date( 'G', $date );
        $time_left = $time - time();
        if ( 0 >= $time_left )
            return __( 'Expired', 'wp-draftsforfriends' );

        if ( 86400 <= $time_left ) {
            $days_left = floor( $time_left / 86400 );
            if ( 0 < $days_left ) {
                $output[] = sprintf( _n( '%d day', '%d days', $days_left, 'wp-draftsforfriends' ), $days_left );
            }
        }
        if ( 3600 <= $time_left ) {
            $hours_left = floor( ( $time_left % 86400 ) / 3600 );
            if ( 0 < $hours_left ) {
                $output[] = sprintf( _n( '%d hour', '%d hours', $hours_left, 'wp-draftsforfriends' ), $hours_left );
            }
        }
        if ( 60 <= $time_left ) {
            $minutes_left = floor( ( $time_left % 3600 ) / 60 );
            if ( 0 < $minutes_left ) {
                $output[] = sprintf( _n( '%d minute', '%d minutes', $minutes_left, 'wp-draftsforfriends' ), $minutes_left );
            }
        } else {
            $output[] = sprintf( _n( '%d second', '%d seconds', $time_left, 'wp-draftsforfriends' ), $time_left );
        }
        return implode( ', ', $output );
    }

    /**
     * Processing adding of shared draft
     *
     * @access private
     * @param array $params Request parameters
     * @return array Array will contain a 'success' key when it is successfully and 'error' key otherwise
     */
    private function process_add( $params ) {
        global $wpdb, $current_user;

        if ( $params['post_id'] ) {
            $p = get_post( intval( $params['post_id'] ) );
            if ( !$p ) {
                return array( 'error' => __( 'There is no such post!', 'wp-draftsforfriends' ) );
            }
            if ( 'publish' == get_post_status( $p ) ) {
                return array( 'error' => sprintf( __( 'The post \'%s\' is published!', 'wp-draftsforfriends' ), $p->post_title ) );
            }
            if( ! current_user_can( 'edit_post', $p->ID ) ) {
                return array( 'error' => __( 'You do not have permission to create shared draft for this post.', 'wp-draftsforfriends' ) );
            }

            $date_expired = time() + $this->calculate_expiry( intval( $params['expires'] ), $params['measure'] );
            $wpdb->insert(
                $wpdb->draftsforfriends,
                array(
                    'post_id'      => $p->ID,
                    'user_id'      => $current_user->ID,
                    'hash'         => wp_generate_password( 32, false, false ),
                    'date_created' => current_time( 'mysql', 1 ),
                    'date_expired' => date( 'Y-m-d H:i:s', $date_expired )
                ),
                array(
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s'
                )
            );

            if( $wpdb->insert_id ) {
                $shared_draft = $this->get_shared_draft( intval( $wpdb->insert_id ) );
                ob_start();
                $this->print_shared_draft_row( $shared_draft );
                $output = ob_get_contents();
                ob_end_clean();
                return array(
                    'success' => sprintf( __( 'Shared draft for \'%s\' created', 'wp-draftsforfriends' ), $p->post_title ),
                    'shared'   => $shared_draft,
                    'html'    => $output,
                    'count'   => number_format_i18n( $this->get_shared_drafts_count() )
                );
            } else {
                return array( 'error' => sprintf( __( 'Error creating shared draft for \'%s\'', 'wp-draftsforfriends' ), $p->post_title ) );
            }
        }
    }

    /**
     * Processing deleting of shared draft
     *
     * @access private
     * @param array $params Request parameters
     * @return array Array will contain a 'success' key when it is successfully and 'error' key otherwise
     */
    private function process_delete( $params ) {
        global $wpdb;

        if( ! current_user_can( 'edit_post', $params['post_id'] ) ) {
            return array( 'error' => __( 'You do not have permission to delete the shared draft for this post.', 'wp-draftsforfriends' ) );
        }

        $shared_draft = $this->get_shared_draft( intval( $params['id'] ) );

        $delete_sql = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->draftsforfriends WHERE id = %d" . $this->where_and(), intval( $params['id'] ) ) );

        if( $delete_sql ) {
            return array(
                'success' => __( 'Shared draft deleted', 'wp-draftsforfriends' ),
                'shared'  => $shared_draft,
                'count'   => number_format_i18n( $this->get_shared_drafts_count() )
            );
        } else {
            return array( 'error' => __( 'Error deleting shared draft', 'wp-draftsforfriends' ) );
        }
    }

    /**
     * Processing extending of shared draft
     *
     * @access private
     * @param array $params Request parameters
     * @return array Array will contain a 'success' key when it is successfully and 'error' key otherwise
     */
    private function process_extend( $params ) {
        global $wpdb;

        if ( $params['post_id'] ) {
            $p = get_post( intval( $params['post_id'] ) );
            if ( !$p ) {
                return array( 'error' => __( 'There is no such post!', 'wp-draftsforfriends' ) );
            }
            if ( 'publish' == get_post_status( $p ) ) {
                return array( 'error' => sprintf( __( 'The post \'%s\' is published!', 'wp-draftsforfriends' ), $p->post_title ) );
            }
            if( ! current_user_can( 'edit_post', $p->ID ) ) {
                return array( 'error' => __( 'You do not have permission to extend shared draft for this post.', 'wp-draftsforfriends' ) );
            }

            $shared_draft = $this->get_shared_draft( intval( $params['id'] ) );

            $duration = $this->calculate_expiry( intval( $params['expires'] ), $params['measure'] );
            $current_date_expired_timestamp = mysql2date( 'G', $shared_draft->date_expired );

            // If the current shared draft has expired, we should be extending it based on the current time and not the expired time
            if( time() >= $current_date_expired_timestamp )
                $new_date_expired_timestamp = time() + $duration;
            else
                $new_date_expired_timestamp = $current_date_expired_timestamp + $duration;

            $update_sql = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->draftsforfriends SET date_extended = %s, date_expired = %s WHERE id = %d" . $this->where_and(), current_time( 'mysql', 1 ), date( 'Y-m-d H:i:s', $new_date_expired_timestamp ), intval( $params['id'] ) ) );

            if( $update_sql ) {
                $shared_draft = $this->get_shared_draft( intval( $params['id'] ) );
                ob_start();
                $this->print_shared_draft_row( $shared_draft );
                $output = ob_get_contents();
                ob_end_clean();

                return array(
                    'success' => __( 'Shared draft extended', 'wp-draftsforfriends' ),
                    'shared'  => $shared_draft,
                    'html'    => $output
                );
            } else {
                return array( 'error' => __( 'Error extending shared draft', 'wp-draftsforfriends' ) );
            }
        }
    }

    /**
     * Get user's draft
     *
     * @access private
     * @return array
     */
    private function get_drafts() {
        $draft = $this->get_users_posts( 'draft' );
        $pending = $this->get_users_posts( 'pending' );
        $future =$this->get_users_posts( 'future' );
        $ds = array(
            array(
                __( 'Drafts:', 'wp-draftsforfriends' ),
                count( $draft ),
                $draft,
            ),
            array(
                __( 'Scheduled Posts:', 'wp-draftsforfriends' ),
                count( $future ),
                $future,
            ),
            array(
                __( 'Pending Review:', 'wp-draftsforfriends' ),
                count( $pending ),
                $pending,
            ),
        );
        return $ds;
    }

    /**
     * Get user's posts based on type
     *
     * @access private
     * @param string $post_type We accept draft, future and pending for now
     * @return array
     */
    private function get_users_posts( $post_type ) {
        global $wpdb;

        $where_and = ' AND post_author = ' . get_current_user_id();
        if( current_user_can( 'edit_others_posts' ) )
            $where_and = '';

        // If the post type is not draft, future or pending, return blank array
        if( ! in_array( $post_type, array( 'draft', 'future', 'pending' ) ) )
            return array();

        return $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status = %s $where_and ORDER BY post_modified DESC", $post_type ) );
    }

    /**
     * Get shared drafts count
     *
     * @access private
     * @return int
     */
    private function get_shared_drafts_count() {
        global $wpdb;
        return intval( $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->draftsforfriends WHERE 1=1" . $this->where_and() ) );
    }

    /**
     * Get a single shared draft
     *
     * @access private
     * @return object
     */
    private function get_shared_draft( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT d.*, p.post_title AS post_title FROM $wpdb->draftsforfriends d INNER JOIN $wpdb->posts p ON d.post_id = p.id WHERE d.id = %d" . $this->where_and(), $id ) );
    }


    /**
     * Get user's currently shared drafts
     *
     * @access private
     * @param string MySQL sort by. Default value is date_created
     * @param string MySQL sort order. Default value is desc
     * @param int MySQL offset. Default value is 0
     * @param int MySQL limit. Default value is 50
     * @return array
     */
    private function get_shared_drafts( $sortby = 'date_created', $sortorder = 'desc', $offset = 0, $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT d.*, p.post_title AS post_title FROM $wpdb->draftsforfriends d INNER JOIN $wpdb->posts p ON d.post_id = p.id WHERE 1=1" . $this->where_and() . " ORDER BY $sortby $sortorder LIMIT %d, %d", $offset, $limit ) );
    }

    /**
     * Display AND portion of a SQL statement depending whether the user has 'edit_others_posts' capability
     *
     * @access private
     * @return string Note that the string starts with a space
     */
    private function where_and() {
        $where_and = ' AND user_id = ' . get_current_user_id();

        if( current_user_can( 'edit_others_posts' ) )
            $where_and = '';

        return $where_and;
    }

    /**
     * Check if user can view the post by matching the generated key and checking the key expiry
     *
     * @access private
     * @param int $pid Post's ID
     * @return bool True if the user can view, false otherwise
     */
    private function can_view( $post_id ) {
        global $wpdb;
        if ( isset( $_GET['draftsforfriends'] ) ) {
            $can_view = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->draftsforfriends WHERE post_id = %d AND hash = %s AND date_expired >= %s", intval( $post_id ), $_GET['draftsforfriends'], current_time( 'mysql', 1 ) ) ) );
            return ( 1 === $can_view );
        }

        return false;
    }

    /**
     * Intercept posts_results to check if user can view the post
     *
     * @access public
     * @param array $pp Post
     * @return array
     */
    public function posts_results_intercept( $posts ) {
        if ( 1 != count( $posts ) ) return $posts;
        $post = $posts[0];
        $status = get_post_status( $post );
        if ( 'publish' != $status && $this->can_view( $post->ID ) ) {
            $post->comment_status = 'closed';
            $this->shared_draft_post = $post;
        }
        return $posts;
    }

    /**
     * Intercept the post results to show the shared draft post that we retrieved in posts_results_intercept()
     *
     * @access public
     * @param array $pp Post
     * @return array
     */
    public function the_posts_intercept( $posts ) {
        if ( empty( $posts ) && ( ! empty( $this->shared_draft_post ) && ! is_null( $this->shared_draft_post ) ) ) {
            return array( $this->shared_draft_post );
        } else {
            $this->shared_draft_post = null;
            return $posts;
        }
    }

    /**
     * Print out admin page
     *
     * @access public
     * @return void
     */
    public function output_existing_menu_sub_admin_page() {
        $output = array();
        // No JS - Adding draft
        if ( isset ( $_POST['draftsforfriends_submit'] ) ) {
            if ( wp_verify_nonce( $_POST['draftsforfriends-add-nonce'], 'draftsforfriends-add' ) ) {
                $output = $this->process_add( $_POST );
            } else {
                $output = array( 'error' =>  __( 'Unable to verify nonce', 'wp-draftsforfriends' ) );
            }
        // No JS - Extend draft
        } elseif( isset ( $_POST['draftsforfriends_extend_submit'] ) ) {
            $nonce_key = 'draftsforfriends-extend-' . intval( $_POST['id'] );
            if ( wp_verify_nonce( $_POST[$nonce_key . '-nonce'], $nonce_key ) ) {
                $output = $this->process_extend( $_POST );
            } else {
                $output = array( 'error' =>  __( 'Unable to verify nonce', 'wp-draftsforfriends' ) );
            }
        // No JS - Delete draft
        } elseif( isset( $_GET['action'] ) && 'delete' == $_GET['action'] ) {
            $nonce_key = 'draftsforfriends-delete-' . intval( $_GET['id'] );
            if ( wp_verify_nonce( $_GET['_wpnonce'], $nonce_key ) ) {
                $output = $this->process_delete( $_GET );
            } else {
                $output = array( 'error' =>  __( 'Unable to verify nonce', 'wp-draftsforfriends' ) );
            }
        }

        // Page
        $dff_params = $this->get_admin_params();
        $page = $dff_params['page'];

        // Total count
        $shared_drafts_count = $this->get_shared_drafts_count();

        // Determin $offset
        $offset = ( $page - 1 ) * $this->shared_drafts_per_page;

        // Determine max number to display on page
        if( ( $offset + $this->shared_drafts_per_page ) > $shared_drafts_count ) {
            $max_on_page = $shared_drafts_count;
        } else {
            $max_on_page = ( $offset + $this->shared_drafts_per_page );
        }

        // Determine number of shared draft to display on page
        if ( ( $offset + 1 ) > ( $shared_drafts_count ) ) {
            $display_on_page = $shared_drafts_count;
        } else {
            $display_on_page = ( $offset + 1 );
        }

        // Determine number of pages
        $total_pages = ceil( $shared_drafts_count / $this->shared_drafts_per_page );

        // Get shared drafts
        $shared_drafts = $this->get_shared_drafts( $dff_params['sortby'], $dff_params['sortorder'], $offset, $this->shared_drafts_per_page );

        // Get drafts
        $ds = $this->get_drafts();

        // Display nicer sorting text
        switch ( $dff_params['sortby'] ) {
            case 'id':
                $text_sortby = __( 'ID', 'draftsforfriends ');
                break;
            case 'post_title':
                $text_sortby = __( 'Post', 'draftsforfriends ');
                break;
            case 'date_extended':
                $text_sortby = __( 'Date Extended', 'draftsforfriends ');
                break;
            case 'date_expired':
                $text_sortby = __( 'Expires After', 'draftsforfriends ');
                break;
            case 'date_created':
            default:
                $text_sortby = __( 'Date Created', 'draftsforfriends ');
        }
        $text_sortorder = __( 'Descending', 'wp-draftsforfriends' );
        if( 'asc' == $dff_params['sortorder'] )
            $text_sortorder = __( 'Ascending', 'wp-draftsforfriends' );
?>
    <div class="wrap">
        <div id="icon-draftsforfriends" class="icon32"><br /></div>
        <h2><?php _e( 'Drafts for Friends', 'wp-draftsforfriends' ); ?></h2>
        <?php if ( ! empty( $output['success'] ) ): ?>
            <div id="draftsforfriends-message" class="updated fade success"><?php echo $output['success']; ?></div>
        <?php elseif ( ! empty( $output['error'] ) ): ?>
            <div id="draftsforfriends-message" class="updated fade error"><?php echo $output['error']; ?></div>
        <?php else: ?>
            <div id="draftsforfriends-message" class="updated" style="display: none;"></div>
        <?php endif; ?>
        <?php if ( !empty( $ds[0][2] ) || !empty( $ds[1][2] ) || !empty( $ds[2][2] ) ): ?>
            <h3><?php _e( 'Share Draft with Friends', 'wp-draftsforfriends' ); ?></h3>
            <form id="draftsforfriends-add" action="<?php echo admin_url( 'edit.php?page='.plugin_basename( __FILE__ ) ); ?>" method="post" onsubmit="return false;">
                <?php wp_nonce_field( 'draftsforfriends-add', 'draftsforfriends-add-nonce' ); ?>
                <table class="widefat">
                    <tbody>
                    <tr>
                        <th scope="row" style="width: 10%">
                            <?php _e( 'Choose a draft:', 'wp-draftsforfriends' ); ?>
                        </th>
                        <td style="width: 90%">
                            <select name="post_id">
                                <?php foreach ( $ds as $dt ): ?>
                                    <?php if ( $dt[1] ): ?>
                                        <option value="" selected="selected" disabled="disabled"></option>
                                        <option value="" disabled="disabled"><?php echo $dt[0]; ?></option>
                                        <?php foreach ( $dt[2] as $d ): ?>
                                            <?php if ( empty( $d->post_title ) ) continue; ?>
                                            <option value="<?php echo $d->ID?>"><?php echo esc_html( $d->post_title ); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="alternate">
                        <th scope="row">
                            <?php _e( 'Share it for:', 'wp-draftsforfriends' ); ?>
                        </th>
                        <td>
                            <input name="expires" type="text" value="2" size="4" />
                            <select name="measure">
                                <option value="s"><?php _e( 'seconds', 'wp-draftsforfriends' ); ?></option>
                                <option value="m"><?php _e( 'minutes', 'wp-draftsforfriends' ); ?></option>
                                <option value="h" selected="selected"><?php _e( 'hours', 'wp-draftsforfriends' ); ?></option>
                                <option value="d"><?php _e( 'days', 'wp-draftsforfriends' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    </tbody>
                    <tfoot>
                    <tr class="alternate">
                        <td>&nbsp;</td>
                        <td>
                            <input type="submit" class="button" name="draftsforfriends_submit" value="<?php _e( 'Go', 'wp-draftsforfriends' ); ?>" />
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </form>
            <p>&nbsp;</p>
        <?php endif; ?>
        <h3><?php _e( 'Currently Shared Drafts', 'wp-draftsforfriends' ); ?></h3>
        <p><?php printf( __( 'Displaying <strong>%s</strong> to <strong>%s</strong> of <strong>%s</strong> shared drafts.', 'wp-draftsforfriends'), number_format_i18n( $display_on_page ), number_format_i18n( $max_on_page ), '<span id="draftsforfriends-current-count">' . number_format_i18n( $shared_drafts_count ) . '</span>' ); ?></p>
        <p><?php printf( __( 'Sorted by <strong>%s</strong> in <strong>%s</strong> order.', 'wp-draftsforfriends' ), $text_sortby, $text_sortorder ); ?></p>
        <table id="draftsforfriends-current" class="widefat draftsforfriends">
            <thead>
                <?php $this->print_table_title_row(); ?>
            </thead>
            <tfoot>
                <?php $this->print_table_title_row(); ?>
            </tfoot>
            <tbody>
                <?php if( ! empty( $shared_drafts ) ): ?>
                    <?php foreach( $shared_drafts as $shared_draft ): ?>
                        <?php $this->print_shared_draft_row( $shared_draft ); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="6" style="text-align: center;"><?php _e( 'No shared drafts!', 'wp-draftsforfriends' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if( 1 < $total_pages ): ?>
            <br />
            <table class="widefat">
                <tbody>
                    <tr>
                        <td width="50%">
                            <?php if( 1 < $page && ( ( ( $page * $this->shared_drafts_per_page ) - ( $this->shared_drafts_per_page - 1 ) ) <= $shared_drafts_count ) ): ?>
                                <strong>&laquo;</strong>&nbsp;<a href="<?php echo $this->generate_admin_url( $page - 1 ); ?>" title="<?php _e( 'Previous Page', 'wp-draftsforfriends' ); ?>"><?php _e( 'Previous Page', 'wp-draftsforfriends' ); ?></a>&nbsp;
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                        <td width="50%" style="text-align: right;">
                            <?php if( 1 <= $page && ( ( ( $page * $this->shared_drafts_per_page ) + 1 ) <=  $shared_drafts_count ) ): ?>
                                &nbsp;<a href="<?php echo $this->generate_admin_url( $page + 1 ); ?>" title="<?php _e( 'Next Page', 'wp-draftsforfriends' ); ?>"><?php _e( 'Next Page', 'wp-draftsforfriends' ); ?></a> <strong>&raquo;</strong>
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="alternate">
                        <td colspan="2" style="text-align: center;">
                            <?php printf( __( 'Pages (%s): ', 'wp-draftsforfriends' ), number_format_i18n( $total_pages ) ); ?>
                            <?php if ( 4 <= $page ): ?>
                                <strong><a href="<?php echo $this->generate_admin_url( 1 ); ?>" title="<?php _e( 'Go to First Page', 'wp-draftsforfriends' ); ?>"><?php _e( 'First', 'wp-draftsforfriends' ); ?></a></strong>&nbsp;...
                            <?php endif; ?>
                            <?php if( 1 < $page ): ?>
                                &nbsp;<strong><a href="<?php echo $this->generate_admin_url( $page - 1 ); ?>" title="<?php printf( __( 'Go to Page %s', 'wp-draftsforfriends' ), number_format_i18n( $page - 1 ) ) ;?>">&laquo;</a></strong>
                            <?php endif; ?>
                            <?php for( $i = $page - 2 ; $i  <= $page +2; $i++ ): ?>
                                <?php if( 1 <= $i && $total_pages >= $i ): ?>
                                    <?php if( $page == $i ): ?>
                                        <strong>[<?php echo number_format_i18n( $i ); ?>]</strong>&nbsp;
                                    <?php else: ?>
                                        <a href="<?php echo $this->generate_admin_url( $i ); ?>" title="<?php printf( __( 'Page %s', 'wp-draftsforfriends' ), number_format_i18n( $i ) ); ?>"><?php echo number_format_i18n( $i ); ?></a>&nbsp;
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if( $page < $total_pages ): ?>
                                <strong><a href="<?php echo $this->generate_admin_url( $page + 1 ); ?>" title="<?php printf( __( 'Go to Page %s', 'wp-draftsforfriends' ), number_format_i18n( $page + 1 ) ) ;?>">&raquo;</a></strong>&nbsp;
                            <?php endif; ?>
                            <?php if ( ( $page + 2 ) < $total_pages): ?>
                                ...&nbsp;<strong><a href="<?php echo $this->generate_admin_url( $total_pages ); ?>" title="<?php _e( 'Go to Last Page', 'wp-draftsforfriends' ); ?>"><?php _e( 'Last', 'wp-draftsforfriends' ); ?></a></strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php
    }

    /**
     * Print shared draft row in a table
     *
     * @access private
     * @param object $shared_draft Shared draft to be printed
     * @return void
     */
    private function print_shared_draft_row( $shared_draft ) {
        $dff_params = $this->get_admin_params();
        $url = get_bloginfo( 'url' ) . '/?p=' . $shared_draft->post_id . '&draftsforfriends='. $shared_draft->hash;
        $delete_nonce = wp_create_nonce( 'draftsforfriends-delete-' . $shared_draft->id );
        $extend_nonce_key = 'draftsforfriends-extend-' . $shared_draft->id;

        $gmt_offset = get_option( 'gmt_offset' ) * 3600;
        $date_created = mysql2date( 'G' , $shared_draft->date_created ) + $gmt_offset;
        $date_extended = mysql2date( 'G' , $shared_draft->date_extended ) + $gmt_offset;
?>
        <tr id="draftsforfriends-current-<?php echo $shared_draft->id; ?>">
            <td><?php echo $shared_draft->id; ?></td>
            <td><?php echo date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), $date_created ); ?></td>
            <td><?php echo $shared_draft->post_title; ?></td>
            <td>
                <a href="<?php echo $url; ?>"><?php echo esc_html( $url ); ?></a>
                <div class="row-actions hide-if-no-js">
                    <span class="collapsed">
                        <a href="#" class="expand" data-id="<?php echo $shared_draft->id; ?>">
                            <?php _e( 'Extend', 'wp-draftsforfriends' ); ?>
                        </a>
                    </span>
                    <span class="expanded">
                        <a href="#" class="collapse" data-id="<?php echo $shared_draft->id; ?>">
                            <?php _e( 'Cancel', 'wp-draftsforfriends' ); ?>
                        </a>
                    </span>
                    |
                    <span class="trash">
                        <a href="#" class="delete" title="<?php _e( 'Delete', 'wp-draftsforfriends' ); ?>" data-id="<?php echo $shared_draft->id; ?>" data-post_id="<?php echo $shared_draft->post_id; ?>" data-post_title="<?php echo esc_js( $shared_draft->post_title ); ?>" data-nonce="<?php echo $delete_nonce; ?>">
                            <?php _e( 'Delete', 'wp-draftsforfriends' ); ?>
                        </a>
                    </span>
                </div>
                <form class="draftsforfriends-extend-form expanded" action="<?php echo admin_url( 'edit.php?page=' . plugin_basename(__FILE__) ); ?>" method="post" onsubmit="return false;">
                    <?php wp_nonce_field( $extend_nonce_key, $extend_nonce_key . '-nonce' ); ?>
                    <input type="hidden" name="id" value="<?php echo $shared_draft->id; ?>" />
                    <input type="hidden" name="post_id" value="<?php echo $shared_draft->post_id; ?>" />
                    <input type="hidden" name="dff_page" value="<?php echo $dff_params['page']; ?>" />
                    <?php _e( 'Extend by', 'wp-draftsforfriends' );?>
                    <input name="expires" type="text" value="2" size="4" />
                    <select name="measure">
                        <option value="s"><?php _e( 'seconds', 'wp-draftsforfriends' ); ?></option>
                        <option value="m"><?php _e( 'minutes', 'wp-draftsforfriends' ); ?></option>
                        <option value="h" selected="selected"><?php _e( 'hours', 'wp-draftsforfriends' ); ?></option>
                        <option value="d"><?php _e( 'days', 'wp-draftsforfriends' ); ?></option>
                    </select>
                    <input type="submit" class="button" name="draftsforfriends_extend_submit" value="<?php _e( 'Go', 'wp-draftsforfriends' ); ?>" />
                    <span class="no-js-trash">
                        <?php
                            $query_params = array(
                                'page'     => plugin_basename( __FILE__),
                                'action'   => 'delete',
                                'post_id'  => $shared_draft->post_id,
                                'id'       => $shared_draft->id,
                                '_wpnonce' => $delete_nonce
                            )
                        ?>
                        <a href="<?php echo admin_url( 'edit.php?' . http_build_query( $query_params ) ); ?>" title="<?php _e( 'Delete', 'wp-draftsforfriends' ); ?>"><?php _e( 'Delete', 'wp-draftsforfriends' ); ?></a>
                    </span>
                </form>
            </td>
            <td><?php echo $this->countdown( $shared_draft->date_expired ); ?></td>
            <td>
                <?php if( ! is_null( $shared_draft->date_extended ) ): ?>
                    <?php echo date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), $date_extended ); ?>
                <?php else: ?>
                    <?php _e( 'N/A' ); ?>
                <?php endif; ?>
            </td>
        </tr>
<?php
    }

    /**
     * Print table title row with sorting links
     *
     * @access private
     * @return void
     */
    private function print_table_title_row() {
        $dff_params = $this->get_admin_params();
?>
        <tr>
            <?php if( 'id' == $dff_params['sortby'] ): ?>
                <th scope="col" width="5%" class="manage-column sorted <?php echo $dff_params['sortorder']; ?>">
            <?php else : ?>
                <th scope="col" width="5%" class="manage-column sortable desc">
            <?php endif; ?>
                <a href="<?php echo $this->generate_admin_url( 1, 'id', ( 'id' == $dff_params['sortby'] && 'desc' == $dff_params['sortorder'] ? 'asc' : 'desc' ) ); ?>">
                    <span><?php _e( 'ID', 'draftsforfriends '); ?></span><span class="sorting-indicator"></span>
                </a>
            </th>

            <?php if( 'date_created' == $dff_params['sortby'] ): ?>
                <th scope="col" width="15%" class="manage-column sorted <?php echo $dff_params['sortorder']; ?>">
            <?php else : ?>
                <th scope="col" width="15%" class="manage-column sortable desc">
            <?php endif; ?>
                <a href="<?php echo $this->generate_admin_url( 1, 'date_created', ( 'date_created' == $dff_params['sortby'] && 'desc' == $dff_params['sortorder'] ? 'asc' : 'desc' ) ); ?>">
                    <span><?php _e( 'Date Created', 'draftsforfriends '); ?></span><span class="sorting-indicator"></span>
                </a>
            </th>

            <?php if( 'post_title' == $dff_params['sortby'] ): ?>
                <th scope="col" width="20%" class="manage-column sorted <?php echo $dff_params['sortorder']; ?>">
            <?php else : ?>
                <th scope="col" width="20%" class="manage-column sortable desc">
            <?php endif; ?>
                <a href="<?php echo $this->generate_admin_url( 1, 'post_title', ( 'post_title' == $dff_params['sortby'] && 'desc' == $dff_params['sortorder'] ? 'asc' : 'desc' ) ); ?>">
                    <span><?php _e( 'Post', 'draftsforfriends '); ?></span><span class="sorting-indicator"></span>
                </a>
            </th>

            <th scope="col" width="25%" class="manage-column"><?php _e( 'Link', 'draftsforfriends '); ?></th>

            <?php if( 'date_expired' == $dff_params['sortby'] ): ?>
                <th scope="col" width="20%" class="manage-column sorted <?php echo $dff_params['sortorder']; ?>">
            <?php else : ?>
                <th scope="col" width="20%" class="manage-column sortable desc">
            <?php endif; ?>
                <a href="<?php echo $this->generate_admin_url( 1, 'date_expired', ( 'date_expired' == $dff_params['sortby'] && 'desc' == $dff_params['sortorder'] ? 'asc' : 'desc' ) ); ?>">
                    <span><?php _e( 'Expires After', 'draftsforfriends '); ?></span><span class="sorting-indicator"></span>
                </a>
            </th>

            <?php if( 'date_extended' == $dff_params['sortby'] ): ?>
                <th scope="col" width="15%" class="manage-column sorted <?php echo $dff_params['sortorder']; ?>">
            <?php else : ?>
                <th scope="col" width="15%" class="manage-column sortable desc">
            <?php endif; ?>
                <a href="<?php echo $this->generate_admin_url( 1, 'date_extended', ( 'date_extended' == $dff_params['sortby'] && 'desc' == $dff_params['sortorder'] ? 'asc' : 'desc' ) ); ?>">
                    <span><?php _e( 'Last Date Extended', 'draftsforfriends '); ?></span><span class="sorting-indicator"></span>
                </a>
            </th>
        </tr>
<?php
    }

    /**
     * Generate admin page URL with page and sorting params
     *
     * @access private
     * @param int $dff_page Overwrite page
     * @param string $dff_sortby Overwrite sort by
     * @param string $dff_sortorder Overwrite sort order
     * @return string Admin edit.php with page, dff_page, dff_sortby & dff_sortorder params
     */
    private function generate_admin_url( $dff_page = 0, $dff_sortby = '', $dff_sortorder = '' ) {
        $dff_params = $this->get_admin_params( $dff_page, $dff_sortby, $dff_sortorder );
        $query_params = array(
            'page'          => plugin_basename( __FILE__),
            'dff_page'      => $dff_params['page'],
            'dff_sortby'    => $dff_params['sortby'],
            'dff_sortorder' => $dff_params['sortorder']
        );
        return admin_url( 'edit.php?' . http_build_query( $query_params ) );
    }


    /**
     * Process admin page params for pagination and sorting
     *
     * @access private
     * @param int $dff_page Overwrite page
     * @param string $dff_sortby Overwrite sort by
     * @param string $dff_sortorder Overwrite sort order
     * @return array which contains Page, Sort By and Sort Order
     */
    private function get_admin_params( $dff_page = 0, $dff_sortby = '', $dff_sortorder = '' ) {
        // Check whether to use function arguments or from $_REQUEST;
        $dff_page = intval( $dff_page );
        if ( 0 == $dff_page )
            $dff_page = ! empty( $_REQUEST['dff_page'] ) ? intval( $_REQUEST['dff_page'] ) : 1;

        if ( empty( $dff_sortby ) )
            $dff_sortby = ! empty( $_REQUEST['dff_sortby'] ) ? $_REQUEST['dff_sortby'] : 'date_created';

        if ( empty( $dff_sortorder ) )
            $dff_sortorder = ! empty( $_REQUEST['dff_sortorder'] ) ? $_REQUEST['dff_sortorder'] : 'desc';

        // Page
        if ( 0 < $dff_page )
            $page = $dff_page;
        else
            $page = 1;

        // For by, only accept data from a finite list of known and trusted values.
        if ( in_array( $dff_sortby, array( 'id', 'post_title', 'date_created', 'date_extended', 'date_expired' ) ) )
            $sortby = $dff_sortby;
        else
            $sortby = 'date_created';

        // For order, we only accept asc or desc
        if ( in_array( $dff_sortorder, array( 'asc', 'desc' ) ) )
            $sortorder = $dff_sortorder;
        else
            $sortorder = 'desc';

        return array( 'page' => $page, 'sortby' => $sortby, 'sortorder' => $sortorder );
    }
}

new WPDraftsForFriends();