<?php
/**
 * The checks every plugin in the collection carries.
 *
 * @package wp-draftsforfriends
 */

/**
 * Asserts the things that are the same in all nineteen plugins: the readme
 * header, the canonical section list, the option rows, the absence of jQuery and
 * of an RTL stylesheet. None of it is specific to sharing drafts; all of it is
 * the sort of drift nobody notices until a release goes out with it.
 */
class WP_DraftsForFriends_Metadata_Test extends WP_DraftsForFriends_TestCase {

	/**
	 * The plugin root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Records the plugin root once per test.
	 */
	public function set_up() {
		parent::set_up();

		$this->root = dirname( __DIR__ );
	}

	/**
	 * The readme header, as an array of raw lines.
	 *
	 * @return array
	 */
	private function readme_header() {
		$lines = explode( "\n", file_get_contents( $this->root . '/README.md' ) );

		// Line 0 is the "# WP-DraftsForFriends" heading; the header runs to the
		// first blank line.
		$header = array();

		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( '' === trim( $line ) ) {
				break;
			}

			$header[] = $line;
		}

		return $header;
	}

	/**
	 * One field from the plugin header comment.
	 *
	 * @param string $field Field name, including the colon.
	 * @return string
	 */
	private function plugin_header( $field ) {
		preg_match(
			'/^\s*\*\s*' . preg_quote( $field, '/' ) . '\s*(.+?)\s*$/m',
			file_get_contents( $this->root . '/wp-draftsforfriends.php' ),
			$matches
		);

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	/**
	 * One field from the readme header.
	 *
	 * @param string $field Field name, including the colon.
	 * @return string
	 */
	private function readme_field( $field ) {
		preg_match(
			'/^' . preg_quote( $field, '/' ) . '\s*(.+?)\s*$/m',
			file_get_contents( $this->root . '/README.md' ),
			$matches
		);

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	/**
	 * Every option row name the plugin owns, in the database, sorted.
	 *
	 * @return array
	 */
	private function option_rows() {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
				'wp\_draftsforfriends\_%'
			)
		);

		sort( $rows );

		return $rows;
	}

	public function test_every_readme_header_line_keeps_its_line_break() {
		$header = $this->readme_header();

		$this->assertCount( 9, $header, 'The readme header is not nine fields long.' );

		foreach ( array_slice( $header, 0, 8 ) as $line ) {
			$this->assertStringEndsWith(
				'  ',
				$line,
				'"' . trim( $line ) . '" lost the two trailing spaces that keep its line break.'
			);
		}

		$last = $header[8];

		$this->assertSame( rtrim( $last ), $last, 'The last header line must not have trailing spaces.' );
	}

	public function test_the_readme_header_carries_exactly_five_tags() {
		$tags = array_filter( array_map( 'trim', explode( ',', $this->readme_field( 'Tags:' ) ) ) );

		$this->assertCount( 5, $tags, '§3.2 asks for exactly five tags.' );
	}

	public function test_canonical_lesterchan_urls() {
		$this->assertSame( 'https://lesterchan.net/portfolio/programming/php/', $this->plugin_header( 'Plugin URI:' ), 'The Plugin URI is not the canonical one.' );
		$this->assertSame( 'https://lesterchan.net', $this->plugin_header( 'Author URI:' ), 'The Author URI is not the canonical one.' );
		$this->assertSame( 'https://lesterchan.net/site/donation/', $this->readme_field( 'Donate link:' ), 'The Donate link is not the canonical one.' );
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $this->plugin_header( 'License URI:' ), 'The header License URI is not the canonical one.' );
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $this->readme_field( 'License URI:' ), 'The readme License URI is not the canonical one.' );
	}

	public function test_contributors_is_gamerz_only() {
		$this->assertSame( 'GamerZ', $this->readme_field( 'Contributors:' ), 'The Contributors field is not exactly GamerZ.' );
	}

	public function test_text_domain_is_the_plugin_slug() {
		$this->assertSame( 'wp-draftsforfriends', $this->plugin_header( 'Text Domain:' ), 'The text domain is not the plugin slug.' );
		$this->assertSame( '/languages', $this->plugin_header( 'Domain Path:' ), 'The domain path is not /languages.' );
		$this->assertSame( 'wp-draftsforfriends', WP_DRAFTSFORFRIENDS_SLUG, 'WP_DRAFTSFORFRIENDS_SLUG is not the plugin slug.' );
	}

	public function test_version_matches_everywhere() {
		$this->assertSame( WP_DRAFTSFORFRIENDS_VERSION, $this->plugin_header( 'Version:' ), 'The header version and WP_DRAFTSFORFRIENDS_VERSION disagree.' );
		$this->assertSame( WP_DRAFTSFORFRIENDS_VERSION, $this->readme_field( 'Stable tag:' ), 'The readme stable tag and WP_DRAFTSFORFRIENDS_VERSION disagree.' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', WP_DRAFTSFORFRIENDS_VERSION, 'The version is not three numbers.' );
	}

	public function test_requires_headers_match_readme() {
		$this->assertSame( '6.8', $this->plugin_header( 'Requires at least:' ), 'The header WordPress floor is not 6.8.' );
		$this->assertSame( '8.2', $this->plugin_header( 'Requires PHP:' ), 'The header PHP floor is not 8.2.' );
		$this->assertSame( $this->plugin_header( 'Requires at least:' ), $this->readme_field( 'Requires at least:' ), 'The header and readme disagree about the WordPress floor.' );
		$this->assertSame( $this->plugin_header( 'Requires PHP:' ), $this->readme_field( 'Requires PHP:' ), 'The header and readme disagree about the PHP floor.' );
	}

	public function test_the_licence_block_is_the_or_later_variant() {
		$source = file_get_contents( $this->root . '/wp-draftsforfriends.php' );

		$this->assertSame( 'GPLv2 or later', $this->plugin_header( 'License:' ), 'The header licence is not GPLv2 or later.' );
		$this->assertStringContainsString(
			'(at your option) any later version',
			$source,
			'The GPL block is the version 2 only variant, which contradicts the header two lines above it.'
		);
	}

	public function test_readme_sections_are_the_canonical_set() {
		preg_match_all( '/^## .+$/m', file_get_contents( $this->root . '/README.md' ), $matches );

		$this->assertSame(
			array(
				'## Description',
				'## Usage',
				'## Frequently Asked Questions',
				'## Screenshots',
				'## Changelog',
				'## Upgrade Notice',
			),
			array_map( 'rtrim', $matches[0] ),
			'The readme level two headings are not the canonical set, in order.'
		);
	}

	public function test_donations_is_the_last_h3_of_the_description() {
		$readme      = file_get_contents( $this->root . '/README.md' );
		$description = substr( $readme, strpos( $readme, '## Description' ) );
		$description = substr( $description, 0, strpos( $description, '## Usage' ) );

		preg_match_all( '/^### .+$/m', $description, $matches );

		$this->assertSame( '### Donations', rtrim( end( $matches[0] ) ), 'Donations is not the last h3 of the description.' );
		$this->assertStringContainsString(
			'I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.',
			$description,
			'The Donations paragraph is not the agreed wording.'
		);
	}

	public function test_changelog_prefixes_are_canonical() {
		$readme    = file_get_contents( $this->root . '/README.md' );
		$changelog = substr( $readme, strpos( $readme, '## Changelog' ) );
		$changelog = substr( $changelog, 0, strpos( $changelog, '## Upgrade Notice' ) );

		preg_match_all( '/^\* (.+)$/m', $changelog, $matches );

		$this->assertNotEmpty( $matches[1], 'The changelog has no entries at all.' );

		foreach ( $matches[1] as $entry ) {
			$this->assertMatchesRegularExpression(
				'/^(BREAKING|NEW|CHANGED|FIXED|NOTE): /',
				$entry,
				'"' . $entry . '" does not start with one of the five allowed prefixes.'
			);
		}
	}

	public function test_the_raised_floors_are_recorded_as_a_breaking_change() {
		$readme = file_get_contents( $this->root . '/README.md' );

		$this->assertStringContainsString(
			'BREAKING: Requires WordPress 6.8 and PHP 8.2',
			$readme,
			'The raised floors are not a BREAKING changelog line.'
		);

		$notice = substr( $readme, strpos( $readme, '## Upgrade Notice' ) );

		$this->assertStringContainsString( '6.8', $notice, 'The upgrade notice does not mention the WordPress floor.' );
		$this->assertStringContainsString( '8.2', $notice, 'The upgrade notice does not mention the PHP floor.' );
	}

	public function test_no_jquery_is_enqueued() {
		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );

		$checked = 0;

		foreach ( wp_scripts()->registered as $handle => $script ) {
			if ( 0 !== strpos( $handle, 'wp-draftsforfriends' ) ) {
				continue;
			}

			++$checked;

			$this->assertSame( array(), $script->deps, "The '{$handle}' script declares a dependency; §6 wants none, least of all jQuery." );
		}

		$this->assertGreaterThan( 0, $checked, 'No script of the plugin was registered, so this proves nothing.' );

		// Both halves matter: a dependency array built at runtime passes a grep,
		// and a source file using the alias passes a deps check.
		foreach ( glob( $this->root . '/js/*.js' ) as $file ) {
			$source = file_get_contents( $file );

			$this->assertStringNotContainsString( 'jQuery', $source, basename( $file ) . ' still references jQuery.' );
			$this->assertStringNotContainsString( '$(', $source, basename( $file ) . ' still uses the jQuery alias.' );
		}
	}

	public function test_no_rtl_stylesheet_is_registered() {
		$this->assertSame( array(), glob( $this->root . '/css/*-rtl.css' ), 'The plugin ships an RTL stylesheet.' );

		wp_set_current_user( $this->author_id );

		$this->register_admin_menu();

		WP_DraftsForFriends_Admin::admin_enqueue_scripts( $this->admin_hook_suffix );

		foreach ( wp_styles()->registered as $handle => $style ) {
			if ( 0 !== strpos( $handle, 'wp-draftsforfriends' ) ) {
				continue;
			}

			$this->assertArrayNotHasKey( 'rtl', $style->extra, "The '{$handle}' style registers rtl data." );
		}
	}

	public function test_the_stylesheet_uses_no_physical_properties() {
		foreach ( glob( $this->root . '/css/*.css' ) as $file ) {
			$rules = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $file ) );

			$this->assertDoesNotMatchRegularExpression(
				'/(margin|padding|border)-(left|right)\s*:|(^|[;{\s])(left|right)\s*:|text-align\s*:\s*(left|right)|float\s*:\s*(left|right)/mi',
				$rules,
				basename( $file ) . ' uses a physical property; §5.1 wants logical ones so no RTL sheet is needed.'
			);

			$this->assertStringNotContainsString( '!important', $rules, basename( $file ) . ' uses !important.' );
		}
	}

	public function test_every_directory_has_an_index_php() {
		$directories = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
				// A pruning filter, not one applied after the fact: a plain filter
				// descends into node_modules and vendor before discarding them,
				// which is slow enough to look like a hang.
				static function ( $file ) {
					$name = $file->getFilename();

					return ! in_array( $name, array( 'vendor', 'node_modules', '.git', '.github', '.claude' ), true )
						&& 0 !== strpos( $name, '.' );
				}
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$checked = 0;

		foreach ( $directories as $file ) {
			if ( ! $file->isDir() ) {
				continue;
			}

			++$checked;

			$this->assertFileExists(
				$file->getPathname() . '/index.php',
				str_replace( $this->root . '/', '', $file->getPathname() ) . ' has no index.php.'
			);
		}

		$this->assertGreaterThan( 0, $checked, 'No directories were checked at all.' );
		$this->assertFileExists( $this->root . '/index.php', 'The plugin root has no index.php.' );
	}

	public function test_the_plugin_owns_exactly_two_option_rows() {
		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertSame(
			array( WP_DraftsForFriends_Options::OPTION, WP_DraftsForFriends_Options::VERSION ),
			$this->option_rows(),
			'The plugin owns option rows beyond its settings and its version markers. The shares are data and live in their own table.'
		);
	}

	public function test_uninstall_removes_every_option_row() {
		WP_DraftsForFriends_Install::maybe_upgrade();

		$this->assertNotEmpty( $this->option_rows(), 'There was nothing to uninstall, so this proves nothing.' );

		$this->run_uninstall();

		$this->assertSame( array(), $this->option_rows(), 'A wp_draftsforfriends_ option row survived the uninstall.' );

		// run_uninstall() performs the deletions rather than requiring the file,
		// because requiring it would drop the table the rest of the suite runs
		// against. So the file is asserted to name the same rows.
		$uninstall = file_get_contents( $this->root . '/uninstall.php' );

		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Options::OPTION . "'", $uninstall, 'uninstall.php does not delete the settings row.' );
		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Options::VERSION . "'", $uninstall, 'uninstall.php does not delete the version row.' );
		$this->assertStringContainsString( "'" . WP_DraftsForFriends_Install::LEGACY_DB_VERSION . "'", $uninstall, 'uninstall.php does not delete the pre-2.0.0 row.' );
		$this->assertStringContainsString( 'WP_DraftsForFriends_Install::drop_table()', $uninstall, 'uninstall.php does not drop the plugin table.' );
	}

	public function test_uninstall_walks_the_whole_network() {
		$uninstall = $this->source_without_comments( 'uninstall.php' );

		$this->assertStringContainsString( 'is_multisite()', $uninstall, 'uninstall.php does not branch on multisite.' );
		$this->assertStringContainsString( "'number' => 0", $uninstall, 'uninstall.php stops at the default hundred sites.' );
		$this->assertStringContainsString( "'fields' => 'ids'", $uninstall, 'uninstall.php hydrates whole site objects to read one column.' );
		$this->assertStringNotContainsString( 'wp_get_sites', $uninstall, 'wp_get_sites() was removed in WordPress 5.1 and fatals.' );
		$this->assertMatchesRegularExpression(
			'/switch_to_blog\([^}]*restore_current_blog\(\)/s',
			$uninstall,
			'uninstall.php closes a block between switch_to_blog() and restore_current_blog().'
		);
	}

	public function test_version_row_holds_exactly_plugin_and_db() {
		WP_DraftsForFriends_Install::maybe_upgrade();

		$row = get_option( WP_DraftsForFriends_Options::VERSION );

		$this->assertIsArray( $row, 'The version row is not an array.' );
		$this->assertSame( array( 'plugin', 'db' ), array_keys( $row ), 'The version row does not hold exactly the plugin and db markers.' );
	}

	public function test_settings_sanitizer_never_stores_version_markers() {
		$clean = WP_DraftsForFriends_Options::sanitize(
			array(
				'expires'    => 3,
				'measure'    => 'd',
				'version'    => '2.0.0',
				'db_version' => '1',
				'versions'   => array( 'plugin' => '2.0.0' ),
			)
		);

		foreach ( array( 'version', 'db_version', 'versions' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $clean, "The sanitiser stored a '{$key}' key in the settings row." );
		}

		$this->assertSame( array( 'expires', 'measure' ), array_keys( $clean ), 'The sanitiser returned keys the settings row does not own.' );
	}

	public function test_the_six_php_constants_are_defined() {
		foreach ( array( 'VERSION', 'DB_VERSION', 'SLUG', 'MAIN_FILE', 'DIR', 'URL' ) as $suffix ) {
			$this->assertTrue( defined( 'WP_DRAFTSFORFRIENDS_' . $suffix ), 'WP_DRAFTSFORFRIENDS_' . $suffix . ' is not defined.' );
		}

		$this->assertSame( plugin_dir_path( WP_DRAFTSFORFRIENDS_MAIN_FILE ), WP_DRAFTSFORFRIENDS_DIR, 'WP_DRAFTSFORFRIENDS_DIR is not derived from the main file.' );
		$this->assertSame( plugin_dir_url( WP_DRAFTSFORFRIENDS_MAIN_FILE ), WP_DRAFTSFORFRIENDS_URL, 'WP_DRAFTSFORFRIENDS_URL is not derived from the main file.' );
	}

	public function test_every_class_is_prefixed_and_lives_in_its_own_file() {
		foreach ( glob( $this->root . '/includes/class-*.php' ) as $file ) {
			preg_match_all( '/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', file_get_contents( $file ), $matches );

			$this->assertCount( 1, $matches[1], basename( $file ) . ' does not declare exactly one class.' );

			$class = $matches[1][0];

			$this->assertStringStartsWith( 'WP_DraftsForFriends', $class, $class . ' is not prefixed with the plugin class prefix.' );
			$this->assertSame(
				'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php',
				basename( $file ),
				$class . ' is not in the file §2.4 names for it.'
			);
		}
	}

	public function test_the_plugin_fires_only_prefixed_hooks() {
		$fired = array();

		foreach ( glob( $this->root . '/includes/*.php' ) as $file ) {
			preg_match_all(
				'/(?:apply_filters|do_action)(?:_ref_array)?\(\s*\'([a-z0-9_]+)\'/',
				$this->source_without_comments( 'includes/' . basename( $file ) ),
				$matches
			);

			$fired = array_merge( $fired, $matches[1] );
		}

		foreach ( array_unique( $fired ) as $hook ) {
			$this->assertStringStartsWith( 'wp_draftsforfriends_', $hook, "The '{$hook}' hook is not prefixed with the plugin's own prefix." );
		}

		// One, deliberately. See the class docblock on WP_DraftsForFriends_Admin.
		$this->assertSame( array( 'wp_draftsforfriends_capability' ), array_values( array_unique( $fired ) ), 'The set of hooks this plugin fires has changed.' );
	}
}
