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

	// --- the meta box's create button --------------------------------------

	/**
	 * Say something inside the meta box.
	 *
	 * Not notify(): that writes into the screen's .wrap, and the block editor
	 * draws the meta boxes on a screen that has none, so the message would go
	 * nowhere with no error anywhere.
	 *
	 * @param {string} type    Either 'success' or 'error'.
	 * @param {string} message Text to show, or '' to clear.
	 */
	function boxNotify( type, message ) {
		const box = document.getElementById(
			'draftsforfriends-metabox-message',
		);

		if ( ! box ) {
			return;
		}

		box.className = message
			? 'notice notice-alt inline notice-' +
				( 'success' === type ? 'success' : 'error' )
			: '';
		box.textContent = message;
		box.hidden = ! message;
	}

	/**
	 * Add a share to the meta box's list, in the shape the server renders.
	 *
	 * @param {string} url     The share link.
	 * @param {string} expires How long it has left.
	 */
	function addShareToBox( url, expires ) {
		const list = document.querySelector(
			'.draftsforfriends-metabox-shares',
		);

		if ( ! list ) {
			return;
		}

		const item = document.createElement( 'li' );
		const link = document.createElement( 'a' );

		link.href = url;
		link.textContent = url;

		const description = document.createElement( 'span' );

		description.className = 'description';
		description.textContent = expires;

		const copy = document.createElement( 'button' );

		copy.type = 'button';
		copy.className =
			'button button-small hide-if-no-js draftsforfriends-copy';
		copy.dataset.link = url;
		copy.textContent = l10n.copy || '';

		item.append( link, description, copy );

		// Newest first, which is the order the screen's list uses too.
		list.prepend( item );

		const heading = document.getElementById(
			'draftsforfriends-metabox-links-heading',
		);

		// Hidden until the post has a link, so the box does not head an empty
		// list on a post that has never been shared.
		if ( heading ) {
			heading.hidden = false;
		}
	}

	/**
	 * Swap the two create controls over.
	 *
	 * The checkbox is the control on a post WordPress still calls an
	 * auto-draft, because only a save can move it to a status a share link may
	 * serve. The button is the control once one has.
	 *
	 * @param {boolean} saved Whether the post has left auto-draft.
	 */
	function showCreateControl( saved ) {
		const button = document.querySelector( '.draftsforfriends-create-now' );
		const checkbox = document.querySelector(
			'.draftsforfriends-create-on-save',
		);

		if ( ! button || ! checkbox ) {
			return;
		}

		button.classList.toggle( 'hidden', ! saved );
		checkbox.classList.toggle( 'hide-if-js', saved );
	}

	/**
	 * Watch the block editor for the first save of a new post.
	 *
	 * Classic reloads the page and the server renders the right control, but
	 * the block editor never re-renders a meta box, so without this the button
	 * stays hidden until the editor is opened again. Guarded rather than
	 * declared as a dependency: wp.data is not on the classic editor and has no
	 * business being loaded there.
	 */
	function watchPostStatus() {
		const data = window.wp && window.wp.data;
		const editor = data && data.select( 'core/editor' );

		if ( ! editor || ! editor.getCurrentPost ) {
			return false;
		}

		const unsubscribe = data.subscribe( function() {
			const post = editor.getCurrentPost();

			// A status is required, not merely a post: subscribe fires on every
			// store change, and the first few land before the editor has the
			// post at all. Treating that empty object as "not an auto-draft"
			// showed the button on a brand-new post and unsubscribed, so the
			// real status never got a hearing.
			if ( ! post || ! post.status || 'auto-draft' === post.status ) {
				return;
			}

			showCreateControl( true );
			unsubscribe();
		} );

		return true;
	}

	document.addEventListener( 'click', function( event ) {
		const button = event.target.closest( '.draftsforfriends-create' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const expires = document.getElementById(
			'draftsforfriends-metabox-expires',
		);

		if ( isNaN( positiveNumber( expires ) ) ) {
			boxNotify( 'error', l10n.errorExpires );

			return;
		}

		const measure = document.getElementById(
			'draftsforfriends-metabox-measure',
		);
		const body = new URLSearchParams();

		body.set( 'action', l10n.createAction || '' );
		body.set( 'nonce', l10n.createNonce || '' );
		body.set( 'post_id', button.dataset.post || '' );
		body.set( 'expires', expires.value );
		body.set( 'measure', measure ? measure.value : '' );

		button.disabled = true;
		button.textContent = l10n.creating || '';
		boxNotify( 'error', '' );

		window
			.fetch( l10n.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} )
			.then( function( response ) {
				return response.json();
			} )
			.then( function( result ) {
				// A refusal answers with its own sentence; anything shaped
				// differently is not one this button can explain.
				if ( ! result || ! result.success ) {
					boxNotify(
						'error',
						( result && result.data && result.data.message ) ||
							l10n.createFailed,
					);

					return;
				}

				addShareToBox( result.data.url, result.data.expires );
				boxNotify( 'success', result.data.message );
			} )
			.catch( function() {
				boxNotify( 'error', l10n.createFailed );
			} )
			.finally( function() {
				button.disabled = false;
				button.textContent = l10n.create || '';
			} );
	} );

	// Both scripts sit in the footer and nothing orders them, so the store may
	// not be registered yet; load is the point by which it certainly is.
	if ( ! watchPostStatus() ) {
		window.addEventListener( 'load', watchPostStatus, { once: true } );
	}

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
