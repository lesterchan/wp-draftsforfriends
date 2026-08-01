<?php
/**
 * The settings screen.
 *
 * @package WP-DraftsForFriends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Settings tab of the Drafts for Friends page with the Settings API.
 *
 * §4.2 splits the two responsibilities that used to sit on one class:
 * WP_DraftsForFriends_Admin owns the menu and the screens, and this class owns
 * register_setting(), the sections, the field_<name>() callbacks and the
 * sanitiser. Before 2.0.0 the plugin had no settings at all -- the duration the
 * add form offered was written into the markup twice -- and the add form
 * borrowed the Settings API purely as a renderer, which put settings
 * plumbing on a screen that saved no settings.
 *
 * The Settings tab takes manage_options while the Shared Drafts tab takes
 * publish_posts. Both go through the same wp_draftsforfriends_capability filter,
 * and because the two now share one page there are three gates rather than one:
 * the page takes the lower capability, the tab checks manage_options before it
 * renders, and the option page capability filter below makes options.php refuse
 * the save. Skipping the third would leave an author unable to see the form and
 * perfectly able to post it.
 *
 * §4.2.1 wants one register_setting() and one option row across the tabs, which
 * is what is here: the Shared Drafts tab owns no setting at all. That is also
 * why the sanitiser does not merge over the stored row -- see the note on
 * WP_DraftsForFriends_Options::sanitize().
 *
 * @since 2.0.0
 */
class WP_DraftsForFriends_Settings {

	/**
	 * The settings group passed to register_setting() and settings_fields().
	 *
	 * The same string as the option row it saves into, which is the one spelling
	 * §2.2 allows.
	 *
	 * @var string
	 */
	const GROUP = 'wp_draftsforfriends_options';

	/**
	 * The Settings API page the sections and fields are registered against.
	 *
	 * Not a menu slug: there is one menu page, WP_DraftsForFriends_Admin::PAGE,
	 * and the settings are a tab of it. This string never appears in a URL. It
	 * exists because add_settings_section(), add_settings_field() and
	 * do_settings_sections() key off a page identifier, and giving the tab its
	 * own is what stops one tab drawing the other's fields.
	 *
	 * @var string
	 */
	const PAGE = 'wp-draftsforfriends-settings';

	/**
	 * The capability required to see and save the settings.
	 *
	 * Deliberately manage_options rather than the publish_posts the Shared Drafts
	 * tab takes: §2.7 keeps a plugin's custom capability for its data screens
	 * and leaves settings where every other plugin's settings are. An author may
	 * share their own drafts; deciding how long everybody's shares last is a site
	 * setting.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The section holding the defaults a new share starts from.
	 *
	 * @var string
	 */
	const SECTION_SHARE = 'wp_draftsforfriends_share';

	/**
	 * Hook the settings screen into WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_DRAFTSFORFRIENDS_MAIN_FILE ), array( __CLASS__, 'action_links' ) );

		/*
		 * What options.php enforces before it will accept the save. Its default
		 * for any registered group is manage_options, which happens to be the
		 * right answer here -- but only by coincidence, and a site that moved the
		 * settings elsewhere with wp_draftsforfriends_capability would have found
		 * the screen it was given refusing to save. Answering with the same
		 * filtered capability the tab renders behind is what makes the two agree.
		 */
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'option_page_capability' ) );
	}

	/**
	 * The capability options.php requires to accept this settings group.
	 *
	 * @return string
	 */
	public static function option_page_capability() {
		return self::capability( 'settings' );
	}

	/**
	 * The capability required for a given context.
	 *
	 * @param string $context What the capability is being checked for.
	 * @return string The required capability.
	 */
	public static function capability( $context = 'settings' ) {
		/** This filter is documented in includes/class-wp-draftsforfriends-admin.php */
		return apply_filters( 'wp_draftsforfriends_capability', self::CAPABILITY, $context );
	}

	/**
	 * Register the setting, its section and its fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			WP_DraftsForFriends_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_DraftsForFriends_Options', 'sanitize' ),
				'default'           => WP_DraftsForFriends_Options::get_defaults(),
			)
		);

		add_settings_section(
			self::SECTION_SHARE,
			__( 'Sharing', 'wp-draftsforfriends' ),
			array( __CLASS__, 'section_share' ),
			self::PAGE
		);

		add_settings_field(
			'expires',
			__( 'Share drafts for', 'wp-draftsforfriends' ),
			array( __CLASS__, 'field_expires' ),
			self::PAGE,
			self::SECTION_SHARE,
			array( 'label_for' => 'wp_draftsforfriends_expires' )
		);
	}

	/**
	 * The blurb above the section.
	 *
	 * @return void
	 */
	public static function section_share() {
		echo '<p>' . esc_html__( 'How long a new share lasts unless you say otherwise. Both the Share a Draft form and the Extend controls start on this duration, and either can be changed for one share without changing the setting.', 'wp-draftsforfriends' ) . '</p>';
	}

	/**
	 * The default duration, and the unit it is measured in.
	 *
	 * One field rather than two, because a number without its unit is not a
	 * duration: the pair is one setting a site owner thinks of as one thing, and
	 * splitting it across two rows of the form table would make the unit read as
	 * a second, unrelated question.
	 *
	 * @return void
	 */
	public static function field_expires() {
		printf(
			'<input type="number" min="1" max="9999" step="1" class="small-text" id="wp_draftsforfriends_expires" name="%1$s[expires]" value="%2$s" /> ',
			esc_attr( WP_DraftsForFriends_Options::OPTION ),
			esc_attr( WP_DraftsForFriends_Options::get( 'expires' ) )
		);

		// The field's own label, the one label_for wires up, belongs to the
		// number input. Without this the unit is announced as an unlabelled
		// combo box.
		printf(
			'<label class="screen-reader-text" for="wp_draftsforfriends_measure">%s</label>',
			esc_html__( 'Duration unit', 'wp-draftsforfriends' )
		);

		printf(
			'<select id="wp_draftsforfriends_measure" name="%s[measure]">',
			esc_attr( WP_DraftsForFriends_Options::OPTION )
		);

		$selected = WP_DraftsForFriends_Options::get( 'measure' );

		foreach ( WP_DraftsForFriends_Shares::measures() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<p class="description">' . esc_html__( 'Between 1 and 9999. The default is 2 hours.', 'wp-draftsforfriends' ) . '</p>';
	}

	/**
	 * Render the Settings tab.
	 *
	 * The wrap, the h1 and the tab strip belong to the page, so this draws only
	 * the form. The capability is checked again even though render_page() has
	 * already refused anyone without it: this method is public, and a gate that
	 * only holds when you arrive by the expected route is not a gate.
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! current_user_can( self::capability( 'settings' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-draftsforfriends' ) );
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php
			settings_fields( self::GROUP );

			/*
			 * Where options.php sends the browser afterwards. settings_fields()
			 * emits a _wp_http_referer of its own through wp_nonce_field(), and
			 * PHP keeps the last field of a repeated name, so this one wins --
			 * which is the point: without it the save lands back on the first tab
			 * and the message about it appears somewhere the user was not.
			 */
			printf(
				'<input type="hidden" name="_wp_http_referer" value="%s" />',
				esc_url( WP_DraftsForFriends_Admin::page_url( WP_DraftsForFriends_Admin::TAB_SETTINGS ) )
			);

			do_settings_sections( self::PAGE );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Add a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( self::capability( 'settings' ) ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( WP_DraftsForFriends_Admin::page_url( WP_DraftsForFriends_Admin::TAB_SETTINGS ) ),
				esc_html__( 'Settings', 'wp-draftsforfriends' )
			)
		);

		return $links;
	}
}
