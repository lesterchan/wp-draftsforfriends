/**
 * The admin script.
 *
 * Everything here is progressive enhancement, so the contract under test is
 * narrow: does the script let a submission through, and does it stop one it
 * should stop. A test that asserts a form was submitted would be asserting
 * jsdom's behaviour rather than the plugin's, so submissions are driven with a
 * cancelable event and judged by whether defaultPrevented came back true.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	boxMarkup,
	boxMessageClass,
	boxMessageText,
	l10n,
	loadScript,
	noticeClass,
	noticeText,
	rowMarkup,
	screenMarkup,
} from './helpers.js';

/**
 * Fire a cancelable submit at a form and report whether it was blocked.
 *
 * jsdom does not implement form submission, so dispatching the event directly is
 * both closer to what the script sees and quieter than clicking a button.
 *
 * @param {string} id Form element id.
 * @return {boolean} Whether the script prevented the submission.
 */
function submit( id ) {
	const event = new window.Event( 'submit', {
		bubbles: true,
		cancelable: true,
	} );

	document.getElementById( id ).dispatchEvent( event );

	return event.defaultPrevented;
}

/**
 * Choose a bulk action in one of the two dropdowns.
 *
 * @param {string} value Action value.
 * @param {string} which Either 'top' or 'bottom'.
 */
function chooseBulkAction( value, which = 'top' ) {
	document.getElementById( 'bulk-action-selector-' + which ).value = value;
}

/**
 * Tick a row's checkbox.
 *
 * @param {number} id Share id.
 */
function tick( id ) {
	document.getElementById( 'cb-select-' + id ).checked = true;
}

/**
 * Let a pending promise chain settle.
 */
async function settle() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'wp-draftsforfriends-admin', () => {
	beforeAll( () => {
		window.wpDraftsForFriendsL10n = l10n();

		// Once only: the listeners live on document, so a second evaluation would
		// attach a second set and every handler would fire twice.
		loadScript();
	} );

	beforeEach( () => {
		window.confirm = vi.fn( () => true );

		// The listeners survive this, which is why the script is not reloaded.
		document.body.innerHTML = screenMarkup( [
			{ id: 7, title: 'A Draft' },
			{ id: 9, title: 'Another Draft' },
		] );
	} );

	describe( 'the add form', () => {
		it( 'lets a complete submission through untouched', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '4';

			expect( submit( 'draftsforfriends-add' ) ).toBe( false );
			expect( noticeText() ).toBe( '' );
		} );

		it( 'blocks a submission with no draft chosen', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '';

			expect( submit( 'draftsforfriends-add' ) ).toBe( true );
			expect( noticeText() ).toBe( l10n().errorPostId );
			expect( noticeClass() ).toContain( 'notice-error' );
		} );

		it( 'blocks a submission with a zero duration', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '4';
			document.getElementById( 'draftsforfriends-expires' ).value = '0';

			expect( submit( 'draftsforfriends-add' ) ).toBe( true );
			expect( noticeText() ).toBe( l10n().errorExpires );
		} );

		it( 'blocks a submission with a non-numeric duration', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '4';
			document.getElementById( 'draftsforfriends-expires' ).value = 'soon';

			expect( submit( 'draftsforfriends-add' ) ).toBe( true );
			expect( noticeText() ).toBe( l10n().errorExpires );
		} );

		it( 'puts its notice inside the wrap, just after the heading', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '';

			submit( 'draftsforfriends-add' );

			const box = document.getElementById( 'draftsforfriends-notice' );

			expect( box.previousElementSibling.tagName ).toBe( 'H1' );
			expect( box.getAttribute( 'role' ) ).toBe( 'alert' );
		} );

		it( 'reuses one notice element rather than stacking them', () => {
			document.getElementById( 'draftsforfriends-post-id' ).value = '';

			submit( 'draftsforfriends-add' );
			submit( 'draftsforfriends-add' );

			expect(
				document.querySelectorAll( '#draftsforfriends-notice' ),
			).toHaveLength( 1 );
		} );

		it( 'writes the message as text, never as markup', () => {
			window.wpDraftsForFriendsL10n.errorPostId =
				'<img src=x onerror=alert(1)>';

			document.getElementById( 'draftsforfriends-post-id' ).value = '';

			submit( 'draftsforfriends-add' );

			expect(
				document.querySelectorAll( '#draftsforfriends-notice img' ),
			).toHaveLength( 0 );

			window.wpDraftsForFriendsL10n.errorPostId = l10n().errorPostId;
		} );
	} );

	describe( 'the bulk actions', () => {
		it( 'ignores a form with no action chosen', () => {
			expect( submit( 'draftsforfriends-list' ) ).toBe( false );
			expect( noticeText() ).toBe( '' );
		} );

		it( 'blocks extend with nothing ticked', () => {
			chooseBulkAction( 'extend' );

			expect( submit( 'draftsforfriends-list' ) ).toBe( true );
			expect( noticeText() ).toBe( l10n().errorSelect );
		} );

		it( 'blocks revoke with nothing ticked, without asking first', () => {
			chooseBulkAction( 'revoke' );

			expect( submit( 'draftsforfriends-list' ) ).toBe( true );
			expect( noticeText() ).toBe( l10n().errorSelect );
			expect( window.confirm ).not.toHaveBeenCalled();
		} );

		it( 'lets extend through once a row is ticked, without confirming', () => {
			chooseBulkAction( 'extend' );
			tick( 7 );

			expect( submit( 'draftsforfriends-list' ) ).toBe( false );
			expect( window.confirm ).not.toHaveBeenCalled();
		} );

		it( 'confirms before revoking', () => {
			chooseBulkAction( 'revoke' );
			tick( 7 );

			expect( submit( 'draftsforfriends-list' ) ).toBe( false );
			expect( window.confirm ).toHaveBeenCalledWith(
				l10n().confirmRevoke,
			);
		} );

		it( 'abandons the revoke when the confirmation is declined', () => {
			window.confirm = vi.fn( () => false );

			chooseBulkAction( 'revoke' );
			tick( 7 );

			expect( submit( 'draftsforfriends-list' ) ).toBe( true );
		} );

		it( 'reads the bottom dropdown as well as the top one', () => {
			chooseBulkAction( 'revoke', 'bottom' );
			tick( 9 );

			expect( submit( 'draftsforfriends-list' ) ).toBe( false );
			expect( window.confirm ).toHaveBeenCalled();
		} );

		it( 'leaves an unrecognised action to the server', () => {
			const select = document.getElementById(
				'bulk-action-selector-top',
			);
			const option = document.createElement( 'option' );

			option.value = 'incinerate';
			select.appendChild( option );
			select.value = 'incinerate';

			expect( submit( 'draftsforfriends-list' ) ).toBe( false );
			expect( window.confirm ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'copying a share link', () => {
		it( 'writes the row link to the clipboard', async () => {
			const writeText = vi.fn( () => Promise.resolve() );

			Object.defineProperty( window.navigator, 'clipboard', {
				value: { writeText },
				configurable: true,
			} );

			document.querySelector( '.draftsforfriends-copy' ).click();

			await settle();

			expect( writeText ).toHaveBeenCalledWith(
				'https://example.test/?p=4&draftsforfriends=hash-7',
			);
		} );

		it( 'says so on the button, then puts the label back', async () => {
			vi.useFakeTimers();

			Object.defineProperty( window.navigator, 'clipboard', {
				value: { writeText: () => Promise.resolve() },
				configurable: true,
			} );

			const button = document.querySelector( '.draftsforfriends-copy' );

			button.click();

			await vi.advanceTimersByTimeAsync( 0 );

			expect( button.textContent ).toBe( l10n().copied );

			await vi.advanceTimersByTimeAsync( 2000 );

			expect( button.textContent ).toBe( l10n().copy );

			vi.useRealTimers();
		} );

		it( 'reports a clipboard the browser refused', async () => {
			Object.defineProperty( window.navigator, 'clipboard', {
				value: { writeText: () => Promise.reject( new Error( 'nope' ) ) },
				configurable: true,
			} );

			document.querySelector( '.draftsforfriends-copy' ).click();

			await settle();

			expect( noticeText() ).toBe( l10n().copyFailed );
			expect( noticeClass() ).toContain( 'notice-error' );
		} );

		it( 'reports a browser with no clipboard at all', () => {
			Object.defineProperty( window.navigator, 'clipboard', {
				value: undefined,
				configurable: true,
			} );

			document.querySelector( '.draftsforfriends-copy' ).click();

			expect( noticeText() ).toBe( l10n().copyFailed );
		} );

		it( 'copies the link belonging to the row that was clicked', async () => {
			const writeText = vi.fn( () => Promise.resolve() );

			Object.defineProperty( window.navigator, 'clipboard', {
				value: { writeText },
				configurable: true,
			} );

			document
				.querySelectorAll( '.draftsforfriends-copy' )[ 1 ]
				.click();

			await settle();

			expect( writeText ).toHaveBeenCalledWith(
				'https://example.test/?p=4&draftsforfriends=hash-9',
			);
		} );
	} );

	describe( "the meta box's create button", () => {
		/**
		 * Answer the next fetch with what admin-ajax.php would have said.
		 *
		 * @param {Object} body  Decoded response body.
		 * @param {Object} extra Properties to merge over the response object.
		 * @return {Object} The mock.
		 */
		function respondWith( body, extra = {} ) {
			const fetch = vi.fn( () =>
				Promise.resolve(
					Object.assign( { json: () => Promise.resolve( body ) }, extra ),
				),
			);

			window.fetch = fetch;

			return fetch;
		}

		/**
		 * A successful create, as the endpoint sends it.
		 *
		 * @param {string} url The new link.
		 * @return {Object} The response body.
		 */
		function created( url ) {
			return {
				success: true,
				data: {
					url,
					expires: 'Expires in 2 hours',
					message: "Shared draft for 'A Draft' created",
				},
			};
		}

		beforeEach( () => {
			document.body.innerHTML = boxMarkup();
		} );

		it( 'posts the action, the nonce, the post and the duration', async () => {
			const fetch = respondWith( created( 'https://example.test/?p=12&draftsforfriends=new' ) );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			expect( fetch ).toHaveBeenCalledTimes( 1 );

			const [ url, options ] = fetch.mock.calls[ 0 ];
			const body = Object.fromEntries( options.body );

			expect( url ).toBe( l10n().ajaxUrl );
			expect( options.credentials ).toBe( 'same-origin' );
			expect( body ).toEqual( {
				action: l10n().createAction,
				nonce: l10n().createNonce,
				post_id: '12',
				expires: '2',
				measure: 'h',
			} );
		} );

		it( 'puts the new link at the top of the list, with its own copy button', async () => {
			respondWith( created( 'https://example.test/?p=12&draftsforfriends=new' ) );

			document.body.innerHTML = boxMarkup( {
				shares: [ 'https://example.test/?p=12&draftsforfriends=old' ],
			} );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			const links = document.querySelectorAll(
				'.draftsforfriends-metabox-shares a',
			);

			expect( links ).toHaveLength( 2 );
			expect( links[ 0 ].textContent ).toBe(
				'https://example.test/?p=12&draftsforfriends=new',
			);
			expect(
				document.querySelectorAll( '.draftsforfriends-copy' )[ 0 ]
					.dataset.link,
			).toBe( 'https://example.test/?p=12&draftsforfriends=new' );
			expect( boxMessageText() ).toBe(
				"Shared draft for 'A Draft' created",
			);
			expect( boxMessageClass() ).toContain( 'notice-success' );
		} );

		it( 'brings out the list heading with the post\'s first link', async () => {
			respondWith(
				created( 'https://example.test/?p=12&draftsforfriends=new' ),
			);

			const heading = document.getElementById(
				'draftsforfriends-metabox-links-heading',
			);

			expect( heading.hidden ).toBe( true );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			expect( heading.hidden ).toBe( false );
		} );

		it( 'reports in the box rather than as a screen notice', async () => {
			respondWith( created( 'https://example.test/?p=12&draftsforfriends=new' ) );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			// The editor has no .wrap, so a notice raised there would be lost.
			expect( document.getElementById( 'draftsforfriends-notice' ) ).toBeNull();
		} );

		it( 'says what the endpoint refused, and adds nothing', async () => {
			respondWith( {
				success: false,
				data: { message: 'Save this post first, then create a share link for it.' },
			} );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			expect( boxMessageText() ).toBe(
				'Save this post first, then create a share link for it.',
			);
			expect( boxMessageClass() ).toContain( 'notice-error' );
			expect(
				document.querySelectorAll(
					'.draftsforfriends-metabox-shares a',
				),
			).toHaveLength( 0 );
		} );

		it( 'falls back to its own sentence when the answer is not one it can read', async () => {
			window.fetch = vi.fn( () => Promise.reject( new Error( 'offline' ) ) );

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			expect( boxMessageText() ).toBe( l10n().createFailed );
		} );

		it( 'refuses a nonsense duration without asking the server', async () => {
			const fetch = respondWith( created( 'https://example.test/?p=12' ) );

			document.getElementById(
				'draftsforfriends-metabox-expires',
			).value = '0';

			document.querySelector( '.draftsforfriends-create' ).click();

			await settle();

			expect( fetch ).not.toHaveBeenCalled();
			expect( boxMessageText() ).toBe( l10n().errorExpires );
		} );

		it( 'puts its label back so a second link can be created', async () => {
			respondWith( created( 'https://example.test/?p=12&draftsforfriends=new' ) );

			const button = document.querySelector( '.draftsforfriends-create' );

			button.click();

			await settle();

			expect( button.disabled ).toBe( false );
			expect( button.textContent ).toBe( l10n().create );
		} );
	} );

	describe( 'the script itself', () => {
		it( 'uses no jQuery', () => {
			// The PHP suite greps the file too; this asserts the running page,
			// where a dependency built at load time would still show up.
			expect( window.jQuery ).toBeUndefined();
			expect( window.$ ).toBeUndefined();
		} );

		it( 'does nothing on a page it does not own', () => {
			document.body.innerHTML = rowMarkup( { id: 3, title: 'Orphan' } );

			expect( () =>
				document.querySelector( '.draftsforfriends-copy' ).click(),
			).not.toThrow();

			expect(
				document.getElementById( 'draftsforfriends-notice' ),
			).toBeNull();
		} );
	} );
} );
