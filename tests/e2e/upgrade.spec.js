/**
 * The 2.0.0 upgrade, run the way a real site runs it.
 *
 * Activation does not fire when a plugin is merely updated -- a site that
 * updates from the Plugins screen never calls activate() -- so maybe_upgrade()
 * also hangs off admin_init. That is the hook every real upgrade goes through,
 * and loading an admin page in a browser is the only way to reach it.
 *
 * There is remarkably little to fold in here, and that is the finding rather
 * than a gap in this file: every released WP-DraftsForFriends stored no option
 * rows at all, the shares have had their own table since 1.0.0, and the one row
 * in the wild is the draftsforfriends_db_version the unreleased 2.0.0 wrote.
 * So what an upgrade has to get right is narrower and sharper than elsewhere in
 * the collection:
 *
 *   * the settings row is seeded rather than left absent, and read raw --
 *     WP_DraftsForFriends_Options::get() merges the defaults over whatever is
 *     stored, so it answers identically for a seeded row and for no row at all;
 *   * the legacy schema counter is carried across rather than discarded, which
 *     is what keeps a site that already has the table from being put through
 *     dbDelta() again for nothing;
 *   * the table exists afterwards, and sharing still works -- which is the only
 *     assertion that says the upgrade left a working plugin rather than a tidy
 *     set of rows.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SHARES_URL,
	createDraft,
	defaultOptions,
	deleteVersionRow,
	dropTable,
	ensurePluginActive,
	legacyDbVersion,
	listRow,
	rawOptions,
	reactivatePlugin,
	resetPlugin,
	runningVersions,
	setLegacyDbVersion,
	setRawOptions,
	setVersionRow,
	shareDraft,
	tableExists,
	uniqueTitle,
	versionRow,
} = require( './helpers.js' );

/** The Dashboard: an ordinary admin request, which is what an update goes through. */
const DASHBOARD_URL = '/wp-admin/index.php';

test.describe( 'The 2.0.0 upgrade', () => {
	test.afterEach( async () => {
		// Whatever a test took apart, the next spec expects a current install:
		// the table present, the settings at their defaults, the markers stamped.
		setLegacyDbVersion( null );
		setVersionRow( runningVersions() );
		ensurePluginActive();
		resetPlugin();
	} );

	test( 'an admin load seeds the settings row and stamps both markers', async ( { page } ) => {
		setRawOptions( null );
		deleteVersionRow();

		// The fixture really is an install that has never been through this:
		// no settings row, no markers. Without it the assertions below could be
		// describing a site that was already current.
		expect( rawOptions() ).toBe( false );
		expect( versionRow() ).toBe( false );

		await page.goto( DASHBOARD_URL );

		// Seeded, not merely readable. The row is written once here rather than
		// left absent and merged from the defaults on every read forever, and
		// only a raw read can tell those two apart.
		const stored = rawOptions();

		expect( stored ).not.toBe( false );
		expect( stored ).toEqual( defaultOptions() );

		// Both markers, together, matching the code that is running.
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( 'the unreleased schema counter is carried across and deleted', async ( { page } ) => {
		deleteVersionRow();

		// The one legacy row that exists in the wild, written by the unreleased
		// 2.0.0 work. It holds the same schema counter the new db marker holds,
		// so carrying it across is what stops a site that already has the
		// current table being sent through dbDelta() again for nothing.
		setLegacyDbVersion( runningVersions().db );

		expect( legacyDbVersion() ).toBe( runningVersions().db );

		await page.goto( DASHBOARD_URL );

		expect( legacyDbVersion() ).toBe( false );
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( 'a missing table is rebuilt, and sharing works on the far side', async ( {
		page,
		requestUtils,
	} ) => {
		dropTable();
		deleteVersionRow();

		// The fixture is only a pre-2.0.0 install if the table really is gone.
		expect( tableExists() ).toBe( false );

		await page.goto( DASHBOARD_URL );

		expect( tableExists() ).toBe( true );

		// Present is not alive. A table the plugin cannot write to is a
		// migration that passed and a plugin that broke, and the only way to
		// tell the two apart is to share a draft through the screen.
		const title = uniqueTitle( 'A draft shared after the upgrade' );

		await createDraft( requestUtils, title );
		await shareDraft( page, { title } );

		await expect( listRow( page, title ) ).toBeVisible();
	} );

	test( 'settings the owner has saved survive the upgrade', async ( { page } ) => {
		// A row written before the markers were stamped -- the shape a site is
		// in when it ran a development build and then updated. The migration
		// re-sanitises rather than replaces, so the owner's duration stays.
		setRawOptions( { expires: 9, measure: 'd' } );
		deleteVersionRow();

		await page.goto( DASHBOARD_URL );

		expect( rawOptions() ).toEqual( { expires: 9, measure: 'd' } );
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( 'a row an older, laxer build wrote is cleaned on the way through', async ( { page } ) => {
		// Out of bounds and in a unit that does not exist. The upgrade is the
		// only chance the plugin gets to clean a row nobody will ever resubmit
		// through the form, so it re-sanitises what it finds.
		setRawOptions( { expires: -5, measure: 'fortnights' } );
		deleteVersionRow();

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		expect( stored.measure ).toBe( defaultOptions().measure );
		expect( stored.expires ).toBeGreaterThan( 0 );
	} );

	test( 'reactivating runs the same upgrade, and a second pass changes nothing', async () => {
		setRawOptions( null );
		deleteVersionRow();

		// The other entry point, and the one an owner reaches for when something
		// looks wrong. It has to reach the same routine from the same state,
		// with no admin page loaded at all.
		reactivatePlugin();

		const once = { options: rawOptions(), versions: versionRow() };

		expect( once.options ).toEqual( defaultOptions() );
		expect( once.versions ).toEqual( runningVersions() );
		expect( tableExists() ).toBe( true );

		reactivatePlugin();

		expect( rawOptions() ).toEqual( once.options );
		expect( versionRow() ).toEqual( once.versions );
	} );

	test( 'an install already on this version is left alone', async ( { page } ) => {
		// A row the sanitizer would rewrite if it ran, alongside markers saying
		// the upgrade has already happened. maybe_upgrade() returning early is
		// what keeps every admin request from being an option write, and the
		// proof it returned early is that this deliberately stale row survives.
		const stale = { expires: -5, measure: 'fortnights' };

		setRawOptions( stale );
		setVersionRow( runningVersions() );

		await page.goto( DASHBOARD_URL );

		expect( rawOptions() ).toEqual( stale );
	} );

	test( 'the shares screen is reachable after all of it', async ( { page } ) => {
		await page.goto( SHARES_URL );

		await expect( page.getByRole( 'heading', { name: 'Drafts for Friends' } ) ).toBeVisible();
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Shared Drafts' );
	} );
} );
