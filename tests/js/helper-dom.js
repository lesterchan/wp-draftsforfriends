/**
 * Shared helpers for the admin script tests.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The l10n object wp_localize_script() puts on the page.
 *
 * Keys must match includes/class-draftsforfriends-admin.php exactly.
 *
 * @return {Object} The l10n object.
 */
export function l10n() {
	return {
		ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php',
		addNonce: 'add-nonce',
		confirmDelete: "Are you sure you want to delete this shared draft, '%s'",
		errorId: 'Invalid shared draft id',
		errorPostId: 'Please choose a draft to share',
		errorExpires: 'Please choose a valid duration',
		errorRequest: 'The request failed. Please try again.',
		noSharedDrafts: 'No shared drafts!',
		columnCount: 6,
	};
}

/**
 * Evaluate the plugin's admin script in the current jsdom page.
 *
 * The l10n object must already be on window: the IIFE reads it as it runs.
 * Evaluate once per test file -- a second evaluation attaches a second set of
 * listeners and every handler fires twice.
 */
export function loadScript() {
	// Resolved from the project root rather than import.meta.url: under the jsdom
	// environment that is an http: URL, which readFileSync will not take.
	const src = readFileSync(
		resolve( process.cwd(), 'js/wp-draftsforfriends-admin.js' ),
		'utf8',
	);

	new Function( src )();
}

/**
 * Markup matching what DraftsForFriends_Admin::render_page() emits.
 *
 * @param {Array} rows Share rows to render.
 * @return {string} The markup.
 */
export function screenMarkup( rows = [] ) {
	const body = rows.length
		? rows.map( rowMarkup ).join( '' )
		: '<tr class="no-items"><td class="colspanchange" colspan="6">No shared drafts!</td></tr>';

	return `
		<div id="draftsforfriends-message" class="notice" style="display: none;"><p></p></div>
		<form id="draftsforfriends-add">
			<select name="post_id"><option value=""></option><option value="4">Draft</option></select>
			<input name="expires" type="number" value="2" />
			<select name="measure"><option value="h" selected>hours</option></select>
			<button type="submit">Go</button>
		</form>
		<table class="wp-list-table widefat fixed striped shared-drafts">
			<tbody>${ body }</tbody>
		</table>
		<span class="displaying-num">${ rows.length } items</span>
	`;
}

/**
 * Markup matching what DraftsForFriends_Table::single_row() emits.
 *
 * @param {Object} row Share row.
 * @return {string} The markup.
 */
export function rowMarkup( row ) {
	return `
		<tr id="draftsforfriends-current-${ row.id }">
			<td>${ row.id }</td>
			<td class="column-link">
				<div class="row-actions">
					<span class="collapsed"><a href="#" class="draftsforfriends-expand" data-id="${ row.id }">Extend</a></span>
					<span class="expanded"><a href="#" class="draftsforfriends-collapse" data-id="${ row.id }">Cancel</a></span>
					<span class="trash"><a href="#" class="draftsforfriends-delete" data-id="${ row.id }" data-post-title="${ row.title }" data-nonce="delete-${ row.id }">Delete</a></span>
				</div>
				<div class="draftsforfriends-extend-form expanded">
					<input type="number" class="draftsforfriends-expires" value="2" />
					<select class="draftsforfriends-measure"><option value="h" selected>hours</option></select>
					<button type="button" class="button draftsforfriends-extend" data-id="${ row.id }" data-nonce="extend-${ row.id }">Go</button>
				</div>
			</td>
		</tr>
	`;
}

/**
 * Read the last fetch body back as a plain object.
 *
 * @param {Object} fetchMock The vi.fn() standing in for fetch.
 * @param {number} call      Which call to read; defaults to the last.
 * @return {Object} The decoded body.
 */
export function bodyOf( fetchMock, call = -1 ) {
	const calls = fetchMock.mock.calls;
	const [ , init ] = call < 0 ? calls[ calls.length + call ] : calls[ call ];

	return Object.fromEntries( new URLSearchParams( init.body ) );
}
