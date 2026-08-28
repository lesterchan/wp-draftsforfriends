/**
 * WP-DraftsForFriends admin screen.
 *
 * Progressive enhancement only: every write is a real nonced form post, so the
 * screen shares, extends and revokes without this. Handlers are delegated from
 * document, so they hold across pages of the list.
 */
( function() {
	'use strict';

	const l10n = window.wpDraftsForFriendsL10n || {};

	/**
	 * Show a message above the screen, as a core notice.
	 *
	 * The same markup settings_errors() uses, for messages raised before a
	 * request is made.
	 *
	 * @param {string} type    Either 'success' or 'error'.
	 * @param {string} message Text to show.
	 */
	function notify( type, message ) {
		const wrap = document.querySelector( '.wrap' );

		if ( ! wrap ) {
			return;
		}

		let box = document.getElementById( 'draftsforfriends-notice' );

		if ( ! box ) {
			box = document.createElement( 'div' );
			box.id = 'draftsforfriends-notice';

			// role=alert so the message is announced rather than only painted.
			box.setAttribute( 'role', 'alert' );
			box.appendChild( document.createElement( 'p' ) );

			const heading = wrap.querySelector( 'h1' );

			if ( heading ) {
				heading.insertAdjacentElement( 'afterend', box );
			} else {
				wrap.insertAdjacentElement( 'afterbegin', box );
			}
		}

		box.className =
			'notice notice-' + ( 'success' === type ? 'success' : 'error' );

		// textContent, never innerHTML: these strings interpolate a post title.
		box.querySelector( 'p' ).textContent = message;
	}

	/**
	 * A positive whole number read out of a field, or NaN.
	 *
	 * @param {HTMLElement|null} field The input.
	 * @return {number} The value.
	 */
	function positiveNumber( field ) {
		if ( ! field ) {
			return NaN;
		}

		const value = parseInt( field.value, 10 );

		return 0 < value ? value : NaN;
	}

	/**
	 * The bulk action the list form is about to apply.
	 *
	 * Core keeps `action` and `action2` in step, so whichever is off its
	 * placeholder is the answer.
	 *
	 * @param {HTMLElement} form The list form.
	 * @return {string} The action, or an empty string.
	 */
	function chosenBulkAction( form ) {
		const selects = form.querySelectorAll(
			'select[name="action"], select[name="action2"]',
		);

		for ( const select of selects ) {
			if ( select.value && '-1' !== select.value ) {
				return select.value;
			}
		}

		return '';
	}

	// --- the add form ------------------------------------------------------
	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( ! form || 'draftsforfriends-add' !== form.id ) {
			return;
		}

		if ( isNaN( positiveNumber( form.querySelector( '[name="post_id"]' ) ) ) ) {
			event.preventDefault();
			notify( 'error', l10n.errorPostId );

			return;
		}

		if ( isNaN( positiveNumber( form.querySelector( '[name="expires"]' ) ) ) ) {
			event.preventDefault();
			notify( 'error', l10n.errorExpires );
		}
	} );

	// --- the bulk actions --------------------------------------------------
	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( ! form || 'draftsforfriends-list' !== form.id ) {
			return;
		}

		const action = chosenBulkAction( form );

		if ( 'extend' !== action && 'revoke' !== action ) {
			return;
		}

		const ticked = form.querySelectorAll(
			'input[name="shares[]"]:checked',
		);

		if ( ! ticked.length ) {
			event.preventDefault();
			notify( 'error', l10n.errorSelect );

			return;
		}

		// Revoking cannot be undone and the links stop working the moment it
		// happens, which is the whole reason this is not a hover row action.
		if ( 'revoke' === action ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( l10n.confirmRevoke ) ) {
				event.preventDefault();
			}
		}
	} );

	// --- copy a share link -------------------------------------------------
	document.addEventListener( 'click', function( event ) {
		const button = event.target.closest( '.draftsforfriends-copy' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const link = button.dataset.link || '';

		if ( ! link || ! navigator.clipboard ) {
			notify( 'error', l10n.copyFailed );

			return;
		}

		navigator.clipboard
			.writeText( link )
			.then( function() {
				button.textContent = l10n.copied || '';

				// Put the label back, so a second copy still reads as an action
				// rather than as a description of what already happened.
				window.setTimeout( function() {
					button.textContent = l10n.copy || '';
				}, 2000 );
			} )
			.catch( function() {
				notify( 'error', l10n.copyFailed );
			} );
	} );
}() );
