/**
 * Shared steps for the WP-DraftsForFriends end-to-end suite.
 *
 * A share is a row in the plugin's own table, so there is no REST route to
 * create one with and requestUtils cannot help. Fixtures therefore come from two
 * places. Anything a person would type -- choosing a draft, picking a duration,
 * pressing Share Draft -- goes through the screen, so the screen is exercised by
 * the tests that depend on it. Anything a person could not type goes in through
 * WP-CLI: a share that expired an hour ago, a hash that belongs to a different
 * post, a title holding a script tag. The posts themselves are ordinary posts
 * and go through requestUtils.
 */

const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** The plugin root, which is where wp-env reads .wp-env.json from. */
const PLUGIN_ROOT = path.join( __dirname, '../..' );

const SHARES_URL = '/wp-admin/admin.php?page=wp-draftsforfriends';
const SETTINGS_URL = '/wp-admin/admin.php?page=wp-draftsforfriends-settings';

/** The units the duration select offers, and what each is worth in seconds. */
const UNITS = {
	s: 1,
	m: 60,
	h: 3600,
	d: 86400,
};

/** What a fresh install offers as the default duration. */
const DEFAULTS = {
	expires: 2,
	measure: 'h',
};

/**
 * Run PHP inside the tests environment and hand back what it printed.
 *
 * The code is base64'd rather than passed as itself: a post title holding
 * quotes, angle brackets and a script tag is exactly the sort of string that
 * arrives at the other end subtly different, and a fixture that is not the
 * payload byte for byte proves nothing about escaping it.
 *
 * @param {string} code PHP to evaluate, without an opening tag.
 * @return {string} Whatever the code echoed between its markers.
 */
function wpEval( code ) {
	const encoded = Buffer.from( code, 'utf8' ).toString( 'base64' );

	const output = execFileSync(
		'npx',
		[
			'--yes',
			'@wordpress/env',
			'run',
			'tests-cli',
			'wp',
			'eval',
			`eval( base64_decode( '${ encoded }' ) );`,
		],
		{ cwd: PLUGIN_ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	// wp-env prints its own progress around the command's output, so the code
	// wraps what it wants to return in markers rather than the caller trying to
	// tell the two apart by position. Greedy, because a stored value ending in
	// "</script>" puts a ">" hard up against the closing marker and a lazy match
	// would stop one character short of the end.
	const matched = output.match( /<<<([\s\S]*)>>>/ );

	return matched ? matched[ 1 ] : '';
}

/**
 * Insert a share straight into the table.
 *
 * The expiry is given as an offset in seconds, and a negative one is the whole
 * point: a share that ran out an hour ago is the row this plugin has to refuse,
 * and there is no way to produce one through the screen without waiting.
 *
 * @param {Object} spec              What to insert.
 * @param {number} spec.postId       Post to share.
 * @param {number} [spec.expiresIn]  Seconds from now until it expires; negative for expired.
 * @param {number} [spec.createdAgo] Seconds ago it was created, for ordering.
 * @param {string} [spec.hash]       Hash to use, generated when absent.
 * @param {number} [spec.userId]     Owner, defaulting to whoever WP-CLI runs as.
 * @return {Object} Keys 'id' and 'hash'.
 */
function createShare( spec ) {
	const data = Buffer.from(
		JSON.stringify( {
			expiresIn: 3600,
			createdAgo: 0,
			hash: '',
			userId: 0,
			...spec,
		} ),
		'utf8',
	).toString( 'base64' );

	return JSON.parse(
		wpEval(
			`global $wpdb;
			$data = json_decode( base64_decode( '${ data }' ), true );
			$hash = '' !== $data['hash'] ? $data['hash'] : wp_generate_password( 32, false, false );
			$user = (int) $data['userId'];
			if ( 0 === $user ) {
				$admin = get_user_by( 'login', 'admin' );
				$user  = $admin ? (int) $admin->ID : 1;
			}
			$wpdb->insert(
				$wpdb->draftsforfriends,
				array(
					'post_id'      => (int) $data['postId'],
					'user_id'      => $user,
					'hash'         => $hash,
					'date_created' => gmdate( 'Y-m-d H:i:s', time() - (int) $data['createdAgo'] ),
					'date_expired' => gmdate( 'Y-m-d H:i:s', time() + (int) $data['expiresIn'] ),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			echo '<<<' . wp_json_encode( array( 'id' => (int) $wpdb->insert_id, 'hash' => $hash ) ) . '>>>';`,
		),
	);
}

/**
 * One column of one share row, as the database holds it.
 *
 * @param {number} id     Share to read.
 * @param {string} column Column of the shares table.
 * @return {string} The stored value, or the empty string when the row is gone.
 */
function shareColumn( id, column ) {
	return wpEval(
		`global $wpdb;
		echo '<<<' . $wpdb->get_var( $wpdb->prepare( "SELECT ${ column } FROM {$wpdb->draftsforfriends} WHERE id = %d", ${ id } ) ) . '>>>';`,
	);
}

/**
 * How many shares there are, across every user.
 *
 * @return {number} Row count.
 */
function countShares() {
	return parseInt(
		wpEval(
			`global $wpdb;
			echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends}" ) . '>>>';`,
		),
		10,
	);
}

/**
 * Empty the shares table and put the settings back to a fresh install's.
 *
 * One round trip rather than two: `wp-env run` starts a container command each
 * time, and this runs before every test in the suite.
 *
 * @return {void}
 */
function resetPlugin() {
	wpEval(
		`global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->draftsforfriends}" );
		update_option( WP_DraftsForFriends_Options::OPTION, WP_DraftsForFriends_Options::get_defaults() );
		echo '<<<done>>>';`,
	);
}

/**
 * One stored setting.
 *
 * @param {string} key Setting name, 'expires' or 'measure'.
 * @return {string} The stored value, as a string.
 */
function setting( key ) {
	return wpEval( `echo '<<<' . WP_DraftsForFriends_Options::get( '${ key }' ) . '>>>';` );
}

/**
 * The URL a friend is given for a share.
 *
 * Built the way the plugin builds it rather than read off the screen, so a test
 * that follows the link is not simply following whatever the screen printed.
 *
 * @param {number} postId Post being shared.
 * @param {string} hash   Share hash.
 * @return {string} A path with its query string, relative to the site root.
 */
function shareLink( postId, hash ) {
	return `/?p=${ postId }&draftsforfriends=${ encodeURIComponent( hash ) }`;
}

/**
 * A title no earlier run can have used.
 *
 * The screen lists every share there is and several assertions find a row by its
 * post title, so two runs of the same test would otherwise leave two rows saying
 * the same thing.
 *
 * @param {string} base What the title should say.
 * @return {string} That, plus enough to tell this run from the last.
 */
function uniqueTitle( base ) {
	return `${ base } ${ Date.now().toString( 36 ) }`;
}

/**
 * Create an unpublished post through the REST API.
 *
 * @param {Object} requestUtils The e2e-test-utils request helper.
 * @param {string} title        Post title.
 * @param {string} [status]     Post status; anything but publish is shareable.
 * @param {string} [content]    Post content.
 * @return {Promise<Object>} The created post.
 */
function createDraft( requestUtils, title, status = 'draft', content = 'The unpublished words.' ) {
	const post = { title, content, status };

	if ( 'future' === status ) {
		// A scheduled post needs a date ahead of now or WordPress publishes it on
		// the spot, and the picker would then leave it out for the right reason
		// and the wrong fixture. The REST date field is read as SITE-local while
		// this is built from UTC, so the two differ by the site's offset -- which
		// cannot matter here, because the only thing asserted is that the date is
		// in the future and two days is more slack than any timezone has.
		post.date = new Date( Date.now() + ( 2 * 86400 * 1000 ) ).toISOString().slice( 0, 19 );
	}

	return requestUtils.createPost( post );
}

/**
 * Open the shared drafts screen.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once the screen is up.
 */
async function openShares( page ) {
	await page.goto( SHARES_URL );

	await expect( page.getByRole( 'heading', { name: 'Drafts for Friends' } ) ).toBeVisible();
}

/**
 * Fill in and submit the Share a Draft form.
 *
 * @param {import('@playwright/test').Page} page             Page under test.
 * @param {Object}                          fields           What to choose.
 * @param {string}                          fields.title     Title of the draft to share.
 * @param {number}                          [fields.expires] Duration.
 * @param {string}                          [fields.measure] Unit key from UNITS.
 * @return {Promise<void>} Resolves once the screen has come back.
 */
async function shareDraft( page, fields ) {
	const { title, expires, measure } = fields;

	await openShares( page );

	await page.locator( '#draftsforfriends-post-id' ).selectOption( { label: title } );

	if ( undefined !== expires ) {
		await page.locator( '#draftsforfriends-expires' ).fill( String( expires ) );
	}
	if ( undefined !== measure ) {
		await page.locator( '#draftsforfriends-measure' ).selectOption( measure );
	}

	await page.getByRole( 'button', { name: 'Share Draft' } ).click();

	await expect( page.getByRole( 'heading', { name: 'Drafts for Friends' } ) ).toBeVisible();
}

/**
 * Run a bulk action against one row of the list table.
 *
 * Both actions are bulk actions on purpose: the plugin refuses to put revoke and
 * extend behind row-action links, because a link is a GET and a browser that
 * prefetches one would revoke every share on the page.
 *
 * @param {import('@playwright/test').Page} page   Page under test.
 * @param {string}                          title  Post title of the row to tick.
 * @param {string}                          action Either 'extend' or 'revoke'.
 * @return {Promise<void>} Resolves once the form has been submitted.
 */
async function bulkAction( page, title, action ) {
	await listRow( page, title ).locator( 'input[name="shares[]"]' ).check();
	await page.locator( '#bulk-action-selector-top' ).selectOption( action );
	await page.locator( '#doaction' ).click();
}

/**
 * The row of the shared drafts table for one post.
 *
 * @param {import('@playwright/test').Page} page  Page showing the list.
 * @param {string}                          title Post title to look for.
 * @return {import('@playwright/test').Locator} That row.
 */
function listRow( page, title ) {
	return page.locator( '.wp-list-table tbody tr', { hasText: title } );
}

/**
 * A second page carrying no session at all.
 *
 * The suite runs as an administrator, who can read every draft on the site
 * whether or not it is shared. The plugin is entirely about the visitor who
 * cannot, so every preview assertion runs in a browser that has never logged in.
 *
 * @param {import('@playwright/test').Page} page Page under test, for its browser.
 * @return {Promise<Object>} Keys 'context' and 'visitor'.
 */
async function anonymously( page ) {
	const context = await page.context().browser().newContext( { storageState: undefined } );

	return { context, visitor: await context.newPage() };
}

/**
 * Create a user, if they are not there already, and log a fresh browser in.
 *
 * @param {import('@playwright/test').Page} page         Page under test, for its browser.
 * @param {Object}                          requestUtils The e2e-test-utils request helper.
 * @param {string}                          username     Login name.
 * @param {string}                          role         Role to give them.
 * @return {Promise<Object>} Keys 'context', 'page' and 'id'.
 */
async function logInAs( page, requestUtils, username, role ) {
	await requestUtils
		.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username,
				email: `${ username }@example.com`,
				password: 'correct-horse-battery-staple',
				roles: [ role ],
			},
		} )
		.catch( () => {} ); // Already there from an earlier run.

	const id = parseInt(
		wpEval( `echo '<<<' . get_user_by( 'login', '${ username }' )->ID . '>>>';` ),
		10,
	);

	const context = await page.context().browser().newContext( { storageState: undefined } );
	const other = await context.newPage();

	await other.goto( '/wp-login.php' );

	// wp-login.php focuses and selects #user_login on a 200ms timer, so that a
	// visitor can start typing. Filling across that moment puts the password into
	// the username box: Playwright focuses #user_pass, the timer takes focus back
	// and selects what is there, and the typed text replaces the selection.
	// Waiting for the timer's own effect is the signal that it has already fired.
	await expect( other.locator( '#user_login' ) ).toBeFocused();

	await other.locator( '#user_login' ).fill( username );
	await other.locator( '#user_pass' ).fill( 'correct-horse-battery-staple' );
	await other.locator( '#wp-submit' ).click();
	await expect( other.locator( '#wpadminbar' ) ).toBeVisible();

	return { context, page: other, id };
}

module.exports = {
	DEFAULTS,
	SETTINGS_URL,
	SHARES_URL,
	UNITS,
	anonymously,
	bulkAction,
	countShares,
	createDraft,
	createShare,
	listRow,
	logInAs,
	openShares,
	resetPlugin,
	setting,
	shareColumn,
	shareDraft,
	shareLink,
	uniqueTitle,
	wpEval,
};
