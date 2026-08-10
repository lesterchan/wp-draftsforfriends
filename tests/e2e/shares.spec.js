/**
 * Sharing, extending and revoking, from the screen a person uses.
 *
 * Everything on the Drafts for Friends screen is an ordinary nonced form post,
 * so these tests drive it the way an author would and then check the far end:
 * the stored row, or the link a friend was handed. The script on top of it adds
 * client-side validation and a confirmation in front of the one irreversible
 * action, and those are exercised here too -- a confirm that deleted anyway
 * would be worse than no confirm at all.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SHARES_URL,
	bulkAction,
	countShares,
	createDraft,
	createShare,
	listRow,
	openShares,
	resetPlugin,
	shareColumn,
	shareDraft,
	uniqueTitle,
	wpEval,
} = require( './helpers.js' );

test.describe( 'Sharing a draft', () => {
	let draft;

	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		draft = await createDraft( requestUtils, uniqueTitle( 'The unfinished piece' ) );
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is an unpublished post the screen offers to share', async ( {
		page,
	} ) => {
		// The precondition every test in this file leans on. A published post is
		// not shareable and the picker leaves it out, so a fixture that quietly
		// published itself would make "the share was created" fail for a reason
		// that has nothing to do with the plugin.
		expect( draft.status ).toBe( 'draft' );
		expect( countShares() ).toBe( 0 );

		await openShares( page );

		await expect(
			page.locator( '#draftsforfriends-post-id option', { hasText: draft.title.raw } ),
		).toHaveCount( 1 );
	} );

	test( 'sharing a draft stores a row and puts its link on the screen', async ( { page } ) => {
		await shareDraft( page, { title: draft.title.raw, expires: 3, measure: 'h' } );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'created' );
		expect( countShares() ).toBe( 1 );

		// The row is the far end, and the link is what the plugin exists to hand
		// out: it names the post and carries the hash the row holds.
		const hash = wpEval(
			`global $wpdb;
			echo '<<<' . $wpdb->get_var( "SELECT hash FROM {$wpdb->draftsforfriends} ORDER BY id DESC LIMIT 1" ) . '>>>';`,
		);

		expect( hash ).toHaveLength( 32 );

		const link = listRow( page, draft.title.raw ).getByRole( 'link' );
		await expect( link ).toHaveAttribute( 'href', new RegExp( `p=${ draft.id }` ) );
		await expect( link ).toHaveAttribute( 'href', new RegExp( `draftsforfriends=${ hash }` ) );
	} );

	test( 'the duration chosen on the form is the duration the row gets', async ( { page } ) => {
		await shareDraft( page, { title: draft.title.raw, expires: 2, measure: 'd' } );

		const expires = new Date(
			`${ shareColumn( 1, 'date_expired' ).replace( ' ', 'T' ) }Z`,
		).getTime();
		const seconds = ( expires - Date.now() ) / 1000;

		// Two days, give or take the round trip. Asserting on a window rather
		// than on an exact stamp: the row is written by the server's clock and
		// read by this one, and the point is the unit was applied at all.
		expect( seconds ).toBeGreaterThan( ( 2 * 86400 ) - 300 );
		expect( seconds ).toBeLessThan( ( 2 * 86400 ) + 300 );
	} );

	test( 'the picker groups by status and leaves published posts out', async ( {
		page,
		requestUtils,
	} ) => {
		const scheduled = await createDraft( requestUtils, uniqueTitle( 'Going out later' ), 'future' );
		const pending = await createDraft( requestUtils, uniqueTitle( 'Waiting on review' ), 'pending' );
		const published = await requestUtils.createPost( {
			title: uniqueTitle( 'Already out' ),
			status: 'publish',
		} );

		await openShares( page );

		const picker = page.locator( '#draftsforfriends-post-id' );

		await expect( picker.locator( 'optgroup[label="Drafts:"]' ) ).toContainText( draft.title.raw );
		await expect( picker.locator( 'optgroup[label="Scheduled Posts:"]' ) ).toContainText(
			scheduled.title.raw,
		);
		await expect( picker.locator( 'optgroup[label="Pending Review:"]' ) ).toContainText(
			pending.title.raw,
		);

		// The one that is already public, which the plugin has nothing to offer.
		await expect(
			picker.locator( 'option', { hasText: published.title.raw } ),
		).toHaveCount( 0 );
	} );

	test( 'the form is not rendered at all when there is nothing left to share', async ( {
		page,
		requestUtils,
	} ) => {
		// A form whose only control is an empty dropdown is worse than no form,
		// so the screen leaves it out entirely -- and still lists the shares.
		await requestUtils.deleteAllPosts();

		await openShares( page );

		await expect( page.locator( '#draftsforfriends-add' ) ).toHaveCount( 0 );
		await expect( page.locator( '#draftsforfriends-list' ) ).toBeVisible();
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'No shared drafts!' );
	} );

	test( 'the script refuses to submit the form with no draft chosen', async ( { page } ) => {
		await openShares( page );

		// Left on the empty first option on purpose. The server would refuse this
		// too, but the script is what stops the round trip, and a script that
		// silently let it through would look identical until the page came back.
		await page.getByRole( 'button', { name: 'Share Draft' } ).click();

		await expect( page.locator( '#draftsforfriends-notice' ) ).toContainText(
			'Please choose a draft to share.',
		);
		expect( countShares() ).toBe( 0 );
	} );

	test( 'a share can be extended, and the extension is recorded', async ( { page } ) => {
		const share = createShare( { postId: draft.id, expiresIn: 60 } );
		const before = shareColumn( share.id, 'date_expired' );

		expect( shareColumn( share.id, 'date_extended' ) ).toBe( '' );

		await openShares( page );

		await page.locator( '#draftsforfriends-extend-expires' ).fill( '5' );
		await page.locator( '#draftsforfriends-extend-measure' ).selectOption( 'd' );
		await bulkAction( page, draft.title.raw, 'extend' );

		await expect( page.locator( '.notice-success' ) ).toContainText( '1 shared draft extended.' );

		// A bulk action posts back to the page, so it has to land on the tab it
		// was submitted from. It does because the form's action carries the tab;
		// a bulk form posting to the bare page slug would answer on the first tab
		// by accident and stop doing so the moment the tab order changed.
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Shared Drafts' );

		// Both halves of what extending means: the expiry moved out, and the
		// screen can now say when it was last extended.
		expect( shareColumn( share.id, 'date_expired' ) > before ).toBe( true );
		expect( shareColumn( share.id, 'date_extended' ) ).not.toBe( '' );
		await expect( listRow( page, draft.title.raw ) ).not.toContainText( 'N/A' );
	} );

	test( 'an already expired share restarts from now rather than from the past', async ( {
		page,
	} ) => {
		// A share that ran out yesterday and is extended by an hour has to be
		// good for an hour, not for an hour measured from yesterday -- which
		// would leave it expired and the screen saying it had been extended.
		const share = createShare( { postId: draft.id, expiresIn: -86400 } );

		await openShares( page );
		await expect( listRow( page, draft.title.raw ) ).toContainText( 'Expired' );

		await page.locator( '#draftsforfriends-extend-expires' ).fill( '2' );
		await page.locator( '#draftsforfriends-extend-measure' ).selectOption( 'h' );
		await bulkAction( page, draft.title.raw, 'extend' );

		await expect( listRow( page, draft.title.raw ) ).not.toContainText( 'Expired' );

		const expires = new Date(
			`${ shareColumn( share.id, 'date_expired' ).replace( ' ', 'T' ) }Z`,
		).getTime();

		expect( expires ).toBeGreaterThan( Date.now() );
	} );

	test( 'revoking removes the row, but only once the confirm is accepted', async ( { page } ) => {
		const share = createShare( { postId: draft.id } );

		await openShares( page );

		// Dismissed first. Revoking cannot be undone and the link stops working
		// the moment it happens, which is the whole reason the plugin puts a
		// confirm in front of it rather than a row-action link.
		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await bulkAction( page, draft.title.raw, 'revoke' );
		expect( shareColumn( share.id, 'id' ) ).toBe( String( share.id ) );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await bulkAction( page, draft.title.raw, 'revoke' );

		await expect( page.locator( '.notice-success' ) ).toContainText( '1 shared draft revoked.' );
		expect( shareColumn( share.id, 'id' ) ).toBe( '' );
	} );

	test( 'a bulk action with nothing ticked changes nothing, in the browser and on the server', async ( {
		page,
	} ) => {
		createShare( { postId: draft.id } );

		await openShares( page );

		// In a browser core gets there first: its own list-table script refuses a
		// bulk action with an empty selection and stops the submit, so the
		// plugin's message never runs. What matters either way is the far end --
		// the share is still there.
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'revoke' );
		await page.locator( '#doaction' ).click();

		await expect( page.locator( '.notice' ).first() ).toContainText(
			'select at least one item',
		);
		expect( countShares() ).toBe( 1 );

		// And the server's own guard, which is what a request that never ran any
		// of that meets. Posted with the form's own nonce, so the only thing
		// deciding the outcome is the empty selection.
		const answer = await page.evaluate( async () => {
			const form = document.getElementById( 'draftsforfriends-list' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'revoke' );

			// getAttribute(), not form.action: the bulk dropdown is named
			// "action", and a control's name shadows the property of the same
			// name on the form -- so form.action is that <select> rather than the
			// URL, and fetch() would post to a stringified DOM node.
			const response = await fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return response.text();
		} );

		expect( answer ).toContain( 'Nothing was selected, so nothing changed.' );
		expect( countShares() ).toBe( 1 );
	} );

	test( 'the copy button hands the share link to the clipboard API', async ( { page } ) => {
		const share = createShare( { postId: draft.id } );

		// navigator.clipboard is replaced rather than granted. A headless browser
		// will not write to a real clipboard without a focused, permitted context,
		// and none of that is anything to do with this plugin: what is worth
		// knowing is that the button asks for the right URL to be copied. The
		// override goes on Navigator.prototype, where the property really lives,
		// and through an init script so it is in place before the page's own
		// scripts run -- the listener under test is still the plugin's own.
		await page.addInitScript( () => {
			window.__copied = null;

			Object.defineProperty( window.Navigator.prototype, 'clipboard', {
				configurable: true,
				get() {
					return {
						writeText: ( text ) => {
							window.__copied = text;

							return Promise.resolve();
						},
					};
				},
			} );
		} );

		await openShares( page );

		const button = listRow( page, draft.title.raw ).getByRole( 'button', {
			name: /^Copy Link/,
		} );
		await button.click();

		// The far end is what was handed over, not the label: the label says
		// "Copied!" for two seconds and then goes back, so an assertion on it
		// is a race with its own timer.
		await expect
			.poll( () => page.evaluate( () => window.__copied ) )
			.toContain( share.hash );

		// And nothing reported a failure, which is the other branch the button has.
		await expect( page.locator( '#draftsforfriends-notice' ) ).toHaveCount( 0 );
	} );

	test( 'the list sorts by post title, in both directions', async ( { page, requestUtils } ) => {
		// Sequentially, with the creation times spread out: two rows written in
		// the same second break the default date sort on insertion order, and a
		// test about ordering should not depend on which insert the server
		// reached first.
		const alpha = await createDraft( requestUtils, uniqueTitle( 'Alpha draft' ) );
		const zulu = await createDraft( requestUtils, uniqueTitle( 'Zulu draft' ) );

		createShare( { postId: alpha.id, createdAgo: 120 } );
		createShare( { postId: zulu.id, createdAgo: 60 } );
		createShare( { postId: draft.id, createdAgo: 0 } );

		await openShares( page );

		// The header link, not the footer's copy of it: core prints the sortable
		// columns at both ends of the table, so a bare columnheader matches twice.
		const sortByPost = page.locator( 'thead #post_title a' );

		await sortByPost.click();
		await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText(
			alpha.title.raw,
		);

		await sortByPost.click();
		await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText(
			zulu.title.raw,
		);
	} );

	test( 'the per-page screen option is remembered and pages the list', async ( {
		page,
		requestUtils,
	} ) => {
		const alpha = await createDraft( requestUtils, uniqueTitle( 'Alpha draft' ) );
		const zulu = await createDraft( requestUtils, uniqueTitle( 'Zulu draft' ) );

		createShare( { postId: alpha.id, createdAgo: 120 } );
		createShare( { postId: zulu.id, createdAgo: 60 } );
		createShare( { postId: draft.id, createdAgo: 0 } );

		await openShares( page );

		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		await page.locator( '#wp_draftsforfriends_per_page' ).fill( '2' );
		await page.locator( '#screen-options-apply' ).click();

		// Two per page, so three shares is two pages -- and the count core prints
		// is the count the INNER JOIN produced rather than the bare table's.
		await expect( page.locator( '.wp-list-table tbody tr' ) ).toHaveCount( 2 );
		await expect( page.locator( '.tablenav-pages .total-pages' ).first() ).toHaveText( '2' );

		// Per user, not per site, which is the whole point of a screen option.
		//
		// The administrator by login, not get_current_user_id(): wpEval() is
		// `wp eval` in the CLI container, which has no logged-in user, so that
		// call is 0 there and the read was always get_user_meta( 0, ... ).
		expect(
			wpEval(
				`$admin = get_user_by( 'login', 'admin' );
				echo '<<<' . get_user_meta( $admin->ID, 'wp_draftsforfriends_per_page', true ) . '>>>';`,
			),
		).toBe( '2' );

		wpEval(
			`$admin = get_user_by( 'login', 'admin' );
			delete_user_meta( $admin->ID, 'wp_draftsforfriends_per_page' );
			echo '<<<done>>>';`,
		);
	} );

	test( 'deleting the post for good takes its share with it', async ( { page, requestUtils } ) => {
		const share = createShare( { postId: draft.id } );

		await openShares( page );
		await expect( listRow( page, draft.title.raw ) ).toHaveCount( 1 );

		// Trashing is reversible, so the row deliberately stays; only a permanent
		// delete removes it. Both are asserted, because a share that outlived its
		// post used to inflate the item count from a table nothing joined it out
		// of, and one deleted on trashing could never be got back.
		await requestUtils.rest( { method: 'DELETE', path: `/wp/v2/posts/${ draft.id }` } );
		expect( shareColumn( share.id, 'id' ) ).toBe( String( share.id ) );

		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ draft.id }`,
			params: { force: true },
		} );

		expect( shareColumn( share.id, 'id' ) ).toBe( '' );

		await page.goto( SHARES_URL );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'No shared drafts!' );
	} );
} );
