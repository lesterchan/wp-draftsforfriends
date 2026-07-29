/**
 * WP-DraftsForFriends admin screen.
 *
 * Rewritten without jQuery for 2.0.0. Every handler is delegated from document,
 * so rows injected after load are picked up without rebinding.
 */
( function() {
	'use strict';

	const l10n = window.draftsForFriendsAdminL10n || {};

	/**
	 * The message banner above the screen.
	 *
	 * @param {string} type    Either 'success' or 'error'.
	 * @param {string} message Text to show.
	 */
	function showMessage( type, message ) {
		const box = document.getElementById( 'draftsforfriends-message' );

		if ( ! box ) {
			return;
		}

		box.className = 'notice is-dismissible notice-' + ( 'success' === type ? 'success' : 'error' );
		box.style.display = '';

		let paragraph = box.querySelector( 'p' );

		if ( ! paragraph ) {
			paragraph = document.createElement( 'p' );
			box.appendChild( paragraph );
		}

		// textContent, never innerHTML: these strings interpolate a post title.
		paragraph.textContent = message;
	}

	/**
	 * The list table's tbody, or null when the screen has no table.
	 *
	 * @return {HTMLElement|null} The tbody.
	 */
	function tableBody() {
		return document.querySelector( '.wp-list-table.shared-drafts tbody, .wp-list-table tbody#the-list' );
	}

	/**
	 * Show or hide core's "no items" row as the table empties and fills.
	 */
	function refreshEmptyRow() {
		const body = tableBody();

		if ( ! body ) {
			return;
		}

		const rows = body.querySelectorAll( 'tr:not(.no-items)' );
		const emptyRow = body.querySelector( 'tr.no-items' );

		if ( rows.length ) {
			if ( emptyRow ) {
				emptyRow.remove();
			}

			return;
		}

		if ( emptyRow ) {
			return;
		}

		const row = document.createElement( 'tr' );
		row.className = 'no-items';

		const cell = document.createElement( 'td' );
		cell.className = 'colspanchange';
		cell.colSpan = l10n.columnCount || 6;
		cell.textContent = l10n.noSharedDrafts || '';

		row.appendChild( cell );
		body.appendChild( row );
	}

	/**
	 * Update every "N items" counter core rendered.
	 *
	 * @param {string} text Already localised and formatted server side.
	 */
	function updateCount( text ) {
		if ( ! text ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '.displaying-num' ),
			function( element ) {
				element.textContent = text;
			},
		);
	}

	/**
	 * POST to admin-ajax.php.
	 *
	 * @param {Object} fields Body fields, minus the action.
	 * @return {Promise<Object>} The decoded response.
	 */
	function request( fields ) {
		const body = new URLSearchParams();

		body.append( 'action', 'draftsforfriends_admin' );

		Object.keys( fields ).forEach( function( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( l10n.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function( response ) {
			return response.json();
		} );
	}

	/**
	 * Report an error the server sent, or a transport failure.
	 *
	 * @param {Object} data Decoded response.
	 * @return {boolean} Whether the response was a success.
	 */
	function handled( data ) {
		if ( data && data.success ) {
			showMessage( 'success', data.success );

			return true;
		}

		showMessage( 'error', ( data && data.error ) || l10n.errorRequest );

		return false;
	}

	/**
	 * Report a rejected fetch.
	 */
	function failed() {
		showMessage( 'error', l10n.errorRequest );
	}

	/**
	 * The row wrapping an element.
	 *
	 * @param {HTMLElement} element Any element inside the row.
	 * @return {HTMLElement|null} The tr.
	 */
	function rowFor( element ) {
		return element.closest( 'tr' );
	}

	/**
	 * Show or hide a row's inline extend controls.
	 *
	 * @param {number}  id     Share ID.
	 * @param {boolean} expand Whether to expand.
	 */
	function toggleRow( id, expand ) {
		const row = document.getElementById( 'draftsforfriends-current-' + id );

		if ( ! row ) {
			return;
		}

		row.classList.toggle( 'draftsforfriends-expanded', expand );
	}

	// --- add -------------------------------------------------------------
	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( ! form || 'draftsforfriends-add' !== form.id ) {
			return;
		}

		event.preventDefault();

		const postId = parseInt( form.querySelector( '[name="post_id"]' ).value, 10 );

		if ( isNaN( postId ) || 0 >= postId ) {
			showMessage( 'error', l10n.errorPostId );

			return;
		}

		const expires = parseInt( form.querySelector( '[name="expires"]' ).value, 10 );

		if ( isNaN( expires ) || 0 >= expires ) {
			showMessage( 'error', l10n.errorExpires );

			return;
		}

		const measure = form.querySelector( '[name="measure"]' ).value;

		request( {
			do: 'add',
			post_id: postId,
			expires,
			measure,
			_ajax_nonce: l10n.addNonce,
		} ).then( function( data ) {
			if ( ! handled( data ) || ! data.html ) {
				return;
			}

			const body = tableBody();

			if ( body ) {
				// insertAdjacentHTML rather than innerHTML: a bare <tr> assigned
				// to innerHTML is discarded by the HTML parser.
				body.insertAdjacentHTML( 'afterbegin', data.html );
			}

			refreshEmptyRow();
			updateCount( data.countText );
		} ).catch( failed );
	} );

	// --- expand / collapse ------------------------------------------------
	document.addEventListener( 'click', function( event ) {
		const expand = event.target.closest( '.draftsforfriends-expand' );

		if ( expand ) {
			event.preventDefault();
			toggleRow( parseInt( expand.dataset.id, 10 ), true );

			return;
		}

		const collapse = event.target.closest( '.draftsforfriends-collapse' );

		if ( collapse ) {
			event.preventDefault();
			toggleRow( parseInt( collapse.dataset.id, 10 ), false );
		}
	} );

	// --- extend -----------------------------------------------------------
	document.addEventListener( 'click', function( event ) {
		const button = event.target.closest( '.draftsforfriends-extend' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const id = parseInt( button.dataset.id, 10 );
		const row = rowFor( button );

		if ( isNaN( id ) || 0 >= id || ! row ) {
			showMessage( 'error', l10n.errorId );

			return;
		}

		const expires = parseInt( row.querySelector( '.draftsforfriends-expires' ).value, 10 );
		const measure = row.querySelector( '.draftsforfriends-measure' ).value;

		if ( isNaN( expires ) || 0 >= expires ) {
			showMessage( 'error', l10n.errorExpires );

			return;
		}

		request( {
			do: 'extend',
			id,
			expires,
			measure,
			_ajax_nonce: button.dataset.nonce,
		} ).then( function( data ) {
			if ( ! handled( data ) || ! data.html ) {
				return;
			}

			const current = document.getElementById( 'draftsforfriends-current-' + id );

			if ( current ) {
				current.insertAdjacentHTML( 'afterend', data.html );
				current.remove();
			}
		} ).catch( failed );
	} );

	// --- delete -----------------------------------------------------------
	document.addEventListener( 'click', function( event ) {
		const link = event.target.closest( '.draftsforfriends-delete' );

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		const id = parseInt( link.dataset.id, 10 );

		if ( isNaN( id ) || 0 >= id ) {
			showMessage( 'error', l10n.errorId );

			return;
		}

		const confirmText = ( l10n.confirmDelete || '' ).replace( '%s', link.dataset.postTitle || '' );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( confirmText ) ) {
			return;
		}

		request( {
			do: 'delete',
			id,
			_ajax_nonce: link.dataset.nonce,
		} ).then( function( data ) {
			if ( ! handled( data ) ) {
				return;
			}

			const row = document.getElementById( 'draftsforfriends-current-' + id );

			if ( row ) {
				row.remove();
			}

			refreshEmptyRow();
			updateCount( data.countText );
		} ).catch( failed );
	} );
}() );
