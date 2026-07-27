/**
 * The admin script.
 *
 * Tests the contract the PHP side depends on -- the field names posted to
 * admin-ajax.php and what the script does with the response -- rather than the
 * implementation.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { bodyOf, l10n, loadScript, rowMarkup, screenMarkup } from './helpers.mjs';

/**
 * Resolve fetch with a plugin response body.
 *
 * @param {Object} payload The decoded response.
 */
function respondWith( payload ) {
	global.fetch.mockResolvedValueOnce( {
		json: async () => payload,
	} );
}

/**
 * Let the pending promise chain settle.
 */
async function settle() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * The banner text currently on screen.
 *
 * @return {string} The message.
 */
function message() {
	return document
		.getElementById( 'draftsforfriends-message' )
		.querySelector( 'p' ).textContent;
}

describe( 'draftsforfriends-admin', () => {
	beforeAll( () => {
		window.draftsForFriendsAdminL10n = l10n();

		// Once only: the listeners live on document, so a second evaluation would
		// attach a second set and every handler would fire twice.
		loadScript();
	} );

	beforeEach( () => {
		global.fetch = vi.fn();
		window.confirm = vi.fn( () => true );

		// The listeners survive this, which is why the script is not reloaded.
		document.body.innerHTML = screenMarkup( [ { id: 7, title: 'A Draft' } ] );
	} );

	describe( 'adding a share', () => {
		it( 'posts the field names the endpoint reads', async () => {
			respondWith( { success: 'Created', html: rowMarkup( { id: 8, title: 'New' } ), countText: '2 items' } );

			document.querySelector( '[name="post_id"]' ).value = '4';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );

			const [ url, init ] = global.fetch.mock.calls[ 0 ];

			expect( url ).toBe( l10n().ajaxUrl );
			expect( init.method ).toBe( 'POST' );
			expect( init.credentials ).toBe( 'same-origin' );

			// These keys are read straight out of $_POST on the PHP side.
			expect( bodyOf( global.fetch ) ).toEqual( {
				action: 'draftsforfriends_admin',
				do: 'add',
				post_id: '4',
				expires: '2',
				measure: 'h',
				_ajax_nonce: 'add-nonce',
			} );
		} );

		it( 'prepends the returned row and updates the count', async () => {
			respondWith( { success: 'Created', html: rowMarkup( { id: 8, title: 'New' } ), countText: '2 items' } );

			document.querySelector( '[name="post_id"]' ).value = '4';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			const rows = document.querySelectorAll( 'tbody tr' );

			expect( rows.length ).toBe( 2 );
			expect( rows[ 0 ].id ).toBe( 'draftsforfriends-current-8' );
			expect( document.querySelector( '.displaying-num' ).textContent ).toBe( '2 items' );
			expect( message() ).toBe( 'Created' );
		} );

		it( 'removes the no-items row when the first share is added', async () => {
			document.body.innerHTML = screenMarkup( [] );

			expect( document.querySelector( 'tr.no-items' ) ).not.toBeNull();

			respondWith( { success: 'Created', html: rowMarkup( { id: 8, title: 'New' } ), countText: '1 item' } );

			document.querySelector( '[name="post_id"]' ).value = '4';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( document.querySelector( 'tr.no-items' ) ).toBeNull();
			expect( document.getElementById( 'draftsforfriends-current-8' ) ).not.toBeNull();
		} );

		it( 'refuses to post without a chosen draft', async () => {
			document.querySelector( '[name="post_id"]' ).value = '';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( global.fetch ).not.toHaveBeenCalled();
			expect( message() ).toBe( l10n().errorPostId );
		} );

		it( 'refuses to post a non-positive duration', async () => {
			document.querySelector( '[name="post_id"]' ).value = '4';
			document.querySelector( '[name="expires"]' ).value = '0';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( global.fetch ).not.toHaveBeenCalled();
			expect( message() ).toBe( l10n().errorExpires );
		} );

		it( 'shows the server error and adds no row', async () => {
			respondWith( { error: 'The post is published!' } );

			document.querySelector( '[name="post_id"]' ).value = '4';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( message() ).toBe( 'The post is published!' );
			expect( document.querySelectorAll( 'tbody tr' ).length ).toBe( 1 );
		} );

		it( 'reports a failed request', async () => {
			global.fetch.mockRejectedValueOnce( new Error( 'offline' ) );

			document.querySelector( '[name="post_id"]' ).value = '4';
			document.getElementById( 'draftsforfriends-add' ).dispatchEvent(
				new window.Event( 'submit', { bubbles: true, cancelable: true } ),
			);

			await settle();

			expect( message() ).toBe( l10n().errorRequest );
		} );
	} );

	describe( 'expanding a row', () => {
		it( 'toggles the row open and closed', () => {
			const row = document.getElementById( 'draftsforfriends-current-7' );

			expect( row.classList.contains( 'draftsforfriends-expanded' ) ).toBe( false );

			document.querySelector( '.draftsforfriends-expand' ).click();
			expect( row.classList.contains( 'draftsforfriends-expanded' ) ).toBe( true );

			document.querySelector( '.draftsforfriends-collapse' ).click();
			expect( row.classList.contains( 'draftsforfriends-expanded' ) ).toBe( false );
		} );
	} );

	describe( 'extending a share', () => {
		it( 'posts the row id, its own nonce and the row inputs', async () => {
			respondWith( { success: 'Extended', html: rowMarkup( { id: 7, title: 'A Draft' } ) } );

			document.querySelector( '.draftsforfriends-extend' ).click();

			await settle();

			expect( bodyOf( global.fetch ) ).toEqual( {
				action: 'draftsforfriends_admin',
				do: 'extend',
				id: '7',
				expires: '2',
				measure: 'h',
				_ajax_nonce: 'extend-7',
			} );
		} );

		it( 'replaces the row in place rather than duplicating it', async () => {
			respondWith( { success: 'Extended', html: rowMarkup( { id: 7, title: 'A Draft' } ) } );

			document.querySelector( '.draftsforfriends-extend' ).click();

			await settle();

			expect( document.querySelectorAll( '#draftsforfriends-current-7' ).length ).toBe( 1 );
			expect( document.querySelectorAll( 'tbody tr' ).length ).toBe( 1 );
			expect( message() ).toBe( 'Extended' );
		} );

		it( 'refuses a non-positive duration', async () => {
			document.querySelector( '.draftsforfriends-expires' ).value = '-1';
			document.querySelector( '.draftsforfriends-extend' ).click();

			await settle();

			expect( global.fetch ).not.toHaveBeenCalled();
			expect( message() ).toBe( l10n().errorExpires );
		} );
	} );

	describe( 'deleting a share', () => {
		it( 'asks first, naming the post', () => {
			window.confirm = vi.fn( () => false );

			document.querySelector( '.draftsforfriends-delete' ).click();

			expect( window.confirm ).toHaveBeenCalledWith(
				"Are you sure you want to delete this shared draft, 'A Draft'",
			);
			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'does nothing when the confirmation is declined', async () => {
			window.confirm = vi.fn( () => false );

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			expect( document.getElementById( 'draftsforfriends-current-7' ) ).not.toBeNull();
		} );

		it( 'posts the id and its own nonce, and no duration', async () => {
			respondWith( { success: 'Deleted', countText: '0 items' } );

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			expect( bodyOf( global.fetch ) ).toEqual( {
				action: 'draftsforfriends_admin',
				do: 'delete',
				id: '7',
				_ajax_nonce: 'delete-7',
			} );
		} );

		it( 'removes the row and restores the no-items row', async () => {
			respondWith( { success: 'Deleted', countText: '0 items' } );

			expect( document.getElementById( 'draftsforfriends-current-7' ) ).not.toBeNull();

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			expect( document.getElementById( 'draftsforfriends-current-7' ) ).toBeNull();

			const empty = document.querySelector( 'tr.no-items' );

			expect( empty ).not.toBeNull();
			expect( empty.querySelector( 'td' ).getAttribute( 'colspan' ) ).toBe( '6' );
			expect( empty.textContent.trim() ).toBe( 'No shared drafts!' );
			expect( document.querySelector( '.displaying-num' ).textContent ).toBe( '0 items' );
		} );

		it( 'leaves the other rows alone', async () => {
			document.body.innerHTML = screenMarkup( [
				{ id: 7, title: 'A Draft' },
				{ id: 9, title: 'Another' },
			] );

			respondWith( { success: 'Deleted', countText: '1 item' } );

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			expect( document.getElementById( 'draftsforfriends-current-7' ) ).toBeNull();
			expect( document.getElementById( 'draftsforfriends-current-9' ) ).not.toBeNull();
			expect( document.querySelector( 'tr.no-items' ) ).toBeNull();
		} );

		it( 'shows the server error and keeps the row', async () => {
			respondWith( { error: 'Unable to verify nonce' } );

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			expect( message() ).toBe( 'Unable to verify nonce' );
			expect( document.getElementById( 'draftsforfriends-current-7' ) ).not.toBeNull();
		} );
	} );

	describe( 'messages', () => {
		it( 'renders as text, never as markup', async () => {
			respondWith( { error: 'The post \'<b>bold</b>\' is published!' } );

			document.querySelector( '.draftsforfriends-delete' ).click();

			await settle();

			const box = document.getElementById( 'draftsforfriends-message' );

			expect( box.querySelector( 'b' ) ).toBeNull();
			expect( message() ).toBe( "The post '<b>bold</b>' is published!" );
		} );

		it( 'switches between the success and error styles', async () => {
			const box = document.getElementById( 'draftsforfriends-message' );

			respondWith( { error: 'Nope' } );
			document.querySelector( '.draftsforfriends-delete' ).click();
			await settle();

			expect( box.className ).toContain( 'notice-error' );

			respondWith( { success: 'Deleted', countText: '0 items' } );
			document.querySelector( '.draftsforfriends-delete' ).click();
			await settle();

			expect( box.className ).toContain( 'notice-success' );
			expect( box.className ).not.toContain( 'notice-error' );
		} );
	} );
} );
