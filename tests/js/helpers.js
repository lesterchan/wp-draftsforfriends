/**
 * Shared helpers for the admin script tests.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Evaluate the plugin's admin script in the current jsdom page.
 *
 * The script is an IIFE with no exports that attaches delegated listeners to
 * document, so it is loaded the way a browser would rather than imported.
 *
 * The path is resolved from the project root rather than from import.meta.url:
 * under the jsdom environment that is an http: URL, and readFileSync answers one
 * with "The URL must be of scheme file".
 *
 * The l10n object has to exist on window *before* this runs: the IIFE reads it
 * as it evaluates. Evaluate once per test file -- a second evaluation adds a
 * second set of listeners and every handler then fires twice.
 */
export function loadScript() {
	const src = readFileSync(
		resolve( process.cwd(), 'js/wp-draftsforfriends-admin.js' ),
		'utf8',
	);

	new Function( src )();
}

/**
 * The l10n object wp_localize_script() puts on the page.
 *
 * Keys must match includes/class-wp-draftsforfriends-admin.php exactly, which
 * WP_DraftsForFriends_Admin_Test asserts from the PHP side.
 *
 * @return {Object} The l10n object.
 */
export function l10n() {
	return {
		errorPostId: 'Please choose a draft to share.',
		errorExpires: 'Please choose a valid duration.',
		errorSelect: 'Please select at least one shared draft.',
		confirmRevoke:
			'Revoke the selected shared drafts? The links stop working immediately and cannot be restored.',
		copy: 'Copy Link',
		copied: 'Copied!',
		copyFailed: 'Could not copy the link. Select it and copy it by hand.',
		create: 'Create Share Link',
		creating: 'Creating…',
		createFailed:
			'Could not create the share link. Reload the editor and try again.',
		ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php',
		createAction: 'draftsforfriends_create_share',
		createNonce: 'a-nonce',
	};
}

/**
 * Markup matching what WP_DraftsForFriends_Metabox::render() emits.
 *
 * The post editor is not the screen: there is no .wrap here, which is the whole
 * reason the box reports into a paragraph of its own.
 *
 * @param {Object}  options         Rendering options.
 * @param {boolean} options.unsaved Whether the post is still an auto-draft.
 * @param {Array}   options.shares  Share links already on the post.
 * @return {string} The markup.
 */
export function boxMarkup( { unsaved = false, shares = [] } = {} ) {
	const items = shares
		.map(
			( url ) => `
				<li>
					<a href="${ url }">${ url }</a>
					<span class="description">Expires in 2 hours</span>
					<button type="button" class="button button-small hide-if-no-js draftsforfriends-copy" data-link="${ url }">Copy Link</button>
				</li>`,
		)
		.join( '' );

	return `
		<div id="post-body">
			<div id="wp-draftsforfriends" class="postbox">
				<div class="inside">
					<h4 class="draftsforfriends-metabox-heading" id="draftsforfriends-metabox-links-heading" ${ shares.length ? '' : 'hidden' }>Share Links</h4>
					<ul class="draftsforfriends-metabox-shares">${ items }</ul>
					<h4 class="draftsforfriends-metabox-heading">New Share Link</h4>
					<p>
						<label for="draftsforfriends-metabox-expires">Share it for:</label>
						<input name="draftsforfriends_expires" id="draftsforfriends-metabox-expires" type="number" value="2" />
						<select name="draftsforfriends_measure" id="draftsforfriends-metabox-measure">
							<option value="h" selected>hours</option>
						</select>
					</p>
					<p class="draftsforfriends-create-now hide-if-no-js${ unsaved ? ' hidden' : '' }">
						<button type="button" class="button draftsforfriends-create" data-post="12">Create Share Link</button>
					</p>
					<p class="draftsforfriends-create-on-save${ unsaved ? '' : ' hide-if-js' }">
						<label for="draftsforfriends-metabox-create">
							<input type="checkbox" name="draftsforfriends_create" id="draftsforfriends-metabox-create" value="1" />
							Create a share link when this post is saved
						</label>
					</p>
					<p id="draftsforfriends-metabox-message" role="alert" hidden></p>
				</div>
			</div>
		</div>`;
}

/**
 * The text of the message the box is showing, or '' if it is showing none.
 *
 * @return {string} The message.
 */
export function boxMessageText() {
	const box = document.getElementById( 'draftsforfriends-metabox-message' );

	return box && ! box.hidden ? box.textContent : '';
}

/**
 * The class the box's message is wearing.
 *
 * @return {string} The class attribute.
 */
export function boxMessageClass() {
	const box = document.getElementById( 'draftsforfriends-metabox-message' );

	return box ? box.className : '';
}

/**
 * Markup matching what WP_DraftsForFriends_Admin::render_page() emits.
 *
 * Not the whole screen: the parts the script reaches for, in the structure it
 * expects to find them in -- the wrap it inserts a notice into, the add form,
 * and the list form with core's bulk dropdowns and checkbox column.
 *
 * @param {Array} rows Share rows to render.
 * @return {string} The markup.
 */
export function screenMarkup( rows = [] ) {
	const body = rows.length
		? rows.map( rowMarkup ).join( '' )
		: '<tr class="no-items"><td class="colspanchange" colspan="7">No shared drafts!</td></tr>';

	return `
		<div class="wrap">
			<h1>Drafts for Friends</h1>
			<nav class="nav-tab-wrapper">
				<a href="/wp-admin/edit.php?page=wp-draftsforfriends&tab=shares" class="nav-tab nav-tab-active">Shared Drafts</a>
				<a href="/wp-admin/edit.php?page=wp-draftsforfriends&tab=settings" class="nav-tab">Settings</a>
			</nav>
			<h2>Share a Draft</h2>
			<form id="draftsforfriends-add" method="post" action="/wp-admin/edit.php?page=wp-draftsforfriends&tab=shares">
				<p>
					<label for="draftsforfriends-post-id">Choose a draft:</label>
					<select name="post_id" id="draftsforfriends-post-id">
						<option value=""></option>
						<option value="4">A Draft</option>
					</select>
				</p>
				<p>
					<label for="draftsforfriends-expires">Share it for:</label>
					<input name="expires" id="draftsforfriends-expires" type="number" value="2" />
					<select name="measure" id="draftsforfriends-measure">
						<option value="h" selected>hours</option>
					</select>
				</p>
				<input type="submit" name="draftsforfriends_add" id="draftsforfriends-submit" value="Share Draft" />
			</form>
			<h2>Currently Shared Drafts</h2>
			<form id="draftsforfriends-list" method="post" action="/wp-admin/edit.php?page=wp-draftsforfriends&tab=shares">
				<div class="tablenav top">
					<select name="action" id="bulk-action-selector-top">
						<option value="-1">Bulk actions</option>
						<option value="extend">Extend Selected</option>
						<option value="revoke">Revoke Selected</option>
					</select>
					<input type="submit" id="doaction" class="button action" value="Apply" />
					<div class="alignleft actions">
						<input type="number" name="extend_expires" id="draftsforfriends-extend-expires" value="2" />
						<select name="extend_measure" id="draftsforfriends-extend-measure">
							<option value="h" selected>hours</option>
						</select>
					</div>
				</div>
				<table class="wp-list-table widefat fixed striped shared-drafts">
					<tbody>${ body }</tbody>
				</table>
				<div class="tablenav bottom">
					<select name="action2" id="bulk-action-selector-bottom">
						<option value="-1">Bulk actions</option>
						<option value="extend">Extend Selected</option>
						<option value="revoke">Revoke Selected</option>
					</select>
					<input type="submit" id="doaction2" class="button action" value="Apply" />
				</div>
			</form>
		</div>
	`;
}

/**
 * One row of the list table.
 *
 * @param {Object} row Share row, as { id, title } with an optional link.
 * @return {string} The markup.
 */
export function rowMarkup( row ) {
	const link =
		row.link ||
		`https://example.test/?p=4&draftsforfriends=hash-${ row.id }`;

	return `
		<tr>
			<th scope="row" class="check-column">
				<label class="screen-reader-text" for="cb-select-${ row.id }">Select the shared draft for ${ row.title }</label>
				<input type="checkbox" id="cb-select-${ row.id }" name="shares[]" value="${ row.id }" />
			</th>
			<td>${ row.id }</td>
			<td class="column-post_title">
				${ row.title }
				<div class="row-actions">
					<span class="edit"><a href="/wp-admin/post.php?post=4&action=edit">Edit Draft</a></span>
					<span class="copy"><button type="button" class="button-link hide-if-no-js draftsforfriends-copy" data-link="${ link }">Copy Link</button></span>
				</div>
			</td>
			<td class="column-link"><a href="${ link }">${ link }</a></td>
		</tr>
	`;
}

/**
 * The text of the notice the script raised, or an empty string.
 *
 * @return {string} The message.
 */
export function noticeText() {
	const box = document.getElementById( 'draftsforfriends-notice' );

	return box ? box.querySelector( 'p' ).textContent : '';
}

/**
 * The class attribute of the notice the script raised.
 *
 * @return {string} The classes.
 */
export function noticeClass() {
	const box = document.getElementById( 'draftsforfriends-notice' );

	return box ? box.className : '';
}
