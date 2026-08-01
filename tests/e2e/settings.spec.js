/**
 * The settings screen, and what the one setting on it changes.
 *
 * There is exactly one setting -- how long a new share lasts -- and it is a pair
 * of controls rather than a single field, because a number without its unit is
 * not a duration. A setting that saves but does nothing and a setting that does
 * something but will not save are the two failures a screenshot cannot tell
 * apart, so each test here drives the form and then looks at the two places the
 * value is meant to turn up: the Share a Draft form and the Extend control on
 * the list table.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	DEFAULTS,
	SETTINGS_URL,
	createDraft,
	createShare,
	openShares,
	resetPlugin,
	setting,
	uniqueTitle,
} = require( './helpers.js' );

/**
 * Open the Settings tab.
 *
 * The heading belongs to the page rather than to the tab, so what says the tab
 * is up is the tab itself being the active one.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once the tab is up.
 */
async function openSettings( page ) {
	await page.goto( SETTINGS_URL );

	await expect( page.getByRole( 'heading', { name: 'Drafts for Friends' } ) ).toBeVisible();
	await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );
}

/**
 * Save the settings form and wait for the tab to come back.
 *
 * Back on the same tab, not the first one: options.php sends the browser to
 * _wp_http_referer, and the form emits one carrying the tab after the one
 * settings_fields() prints.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once the tab has come back.
 */
async function saveSettings( page ) {
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();

	await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );
}

test.describe( 'Drafts for friends settings', () => {
	let draft;

	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		draft = await createDraft( requestUtils, uniqueTitle( 'Something to share' ) );
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is a settings screen holding the shipped default', async ( {
		page,
	} ) => {
		// The precondition: the row exists and holds two hours, which is what the
		// rest of this file measures its changes against.
		expect( setting( 'expires' ) ).toBe( String( DEFAULTS.expires ) );
		expect( setting( 'measure' ) ).toBe( DEFAULTS.measure );

		await openSettings( page );

		await expect( page.locator( '#wp_draftsforfriends_expires' ) ).toHaveValue(
			String( DEFAULTS.expires ),
		);
		await expect( page.locator( '#wp_draftsforfriends_measure' ) ).toHaveValue(
			DEFAULTS.measure,
		);
	} );

	test( 'the saved default is what both duration controls start on', async ( { page } ) => {
		createShare( { postId: draft.id } );

		await openSettings( page );
		await page.locator( '#wp_draftsforfriends_expires' ).fill( '9' );
		await page.locator( '#wp_draftsforfriends_measure' ).selectOption( 'd' );
		await saveSettings( page );

		expect( setting( 'expires' ) ).toBe( '9' );
		expect( setting( 'measure' ) ).toBe( 'd' );

		// The far end is the two screens the setting is for. Until 2.0.0 the
		// duration was written into the markup twice and there was no setting at
		// all, so a site that always shares for a week retyped it every time.
		await openShares( page );

		await expect( page.locator( '#draftsforfriends-expires' ) ).toHaveValue( '9' );
		await expect( page.locator( '#draftsforfriends-measure' ) ).toHaveValue( 'd' );
		await expect( page.locator( '#draftsforfriends-extend-expires' ) ).toHaveValue( '9' );
		await expect( page.locator( '#draftsforfriends-extend-measure' ) ).toHaveValue( 'd' );
	} );

	test( 'a duration outside the range the field advertises is clamped', async ( { page } ) => {
		// The bounds are the field's own min and max, so the browser refuses them
		// first; the sanitizer is what a hand-built POST meets, and it has to
		// agree with the screen or the setting silently reverts on the next load.
		await openSettings( page );

		for ( const [ submitted, stored ] of [
			[ '0', '1' ],
			[ '99999', '9999' ],
		] ) {
			await page.evaluate(
				async ( { url, value } ) => {
					const screen = await fetch( url, { credentials: 'same-origin' } );
					const html = await screen.text();
					const doc = new DOMParser().parseFromString( html, 'text/html' );
					const form = doc.querySelector( 'form[action="options.php"]' );
					const body = new URLSearchParams( new FormData( form ) );

					body.set( 'wp_draftsforfriends_options[expires]', value );

					await fetch( '/wp-admin/options.php', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString(),
					} );
				},
				{ url: SETTINGS_URL, value: submitted },
			);

			expect( setting( 'expires' ), `${ submitted } should be stored as ${ stored }` ).toBe(
				stored,
			);
		}

		// And the screen shows what was actually kept, rather than what was sent.
		await openSettings( page );
		await expect( page.locator( '#wp_draftsforfriends_expires' ) ).toHaveValue( '9999' );
	} );

	test( 'each unit the select offers is one the sanitizer keeps', async ( { page } ) => {
		await openSettings( page );

		const units = await page
			.locator( '#wp_draftsforfriends_measure option' )
			.evaluateAll( ( options ) => options.map( ( option ) => option.value ) );

		expect( units ).toEqual( [ 's', 'm', 'h', 'd' ] );

		// A unit the screen offers and the sanitizer rejects is how a setting
		// comes to report "saved" and revert on the next load.
		for ( const unit of units ) {
			await page.locator( '#wp_draftsforfriends_measure' ).selectOption( unit );
			await saveSettings( page );
			expect( setting( 'measure' ), `${ unit } should survive the sanitizer` ).toBe( unit );
			await openSettings( page );
		}
	} );

	test( 'saving the settings says so', async ( { page } ) => {
		await openSettings( page );

		await page.locator( '#wp_draftsforfriends_expires' ).fill( '4' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		// options.php registers "Settings saved." under the general slug and
		// leaves it in a transient for the next screen to print. Core prints it
		// for you from options-head.php, which admin-header.php includes only
		// when the parent file is options-general.php -- and this page hangs off
		// edit.php. So the tab calls settings_errors() itself; without that the
		// form saves in silence and an admin cannot tell a save from a no-op.
		await expect( page.locator( '.notice-success, .settings-error' ).first() ).toContainText(
			'Settings saved.',
		);

		expect( setting( 'expires' ) ).toBe( '4' );
	} );

	test( 'a save comes back to the Settings tab rather than the first one', async ( {
		page,
	} ) => {
		await openSettings( page );

		await page.locator( '#wp_draftsforfriends_expires' ).fill( '3' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );
		await expect( page.locator( '#wp_draftsforfriends_expires' ) ).toHaveValue( '3' );
	} );

	test( 'the Plugins screen links straight to the settings', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );

		const row = page.locator( 'tr[data-slug="wp-draftsforfriends"]' );

		await expect( row ).toHaveCount( 1 );

		await row.getByRole( 'link', { name: 'Settings' } ).click();

		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );
	} );

	test( 'the tabs move between the two halves of the one page', async ( { page } ) => {
		await openShares( page );

		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Shared Drafts' );

		await page.locator( '.nav-tab', { hasText: 'Settings' } ).click();
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );
		await expect( page.locator( '#wp_draftsforfriends_expires' ) ).toBeVisible();

		await page.locator( '.nav-tab', { hasText: 'Shared Drafts' } ).click();
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Shared Drafts' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
	} );
} );
