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
		copy: 'Copy link',
		copied: 'Copied!',
		copyFailed: 'Could not copy the link. Select it and copy it by hand.',
	};
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
			<h2>Share a Draft</h2>
			<form id="draftsforfriends-add" method="post" action="/wp-admin/admin.php?page=wp-draftsforfriends">
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
			<form id="draftsforfriends-list" method="post" action="/wp-admin/admin.php?page=wp-draftsforfriends">
				<div class="tablenav top">
					<select name="action" id="bulk-action-selector-top">
						<option value="-1">Bulk actions</option>
						<option value="extend">Extend selected</option>
						<option value="revoke">Revoke selected</option>
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
						<option value="extend">Extend selected</option>
						<option value="revoke">Revoke selected</option>
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
 * @param {Object} row Share row, as { id, title }.
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
			<td>${ row.title }</td>
			<td class="column-link">
				<a href="${ link }">${ link }</a>
				<button type="button" class="button hide-if-no-js draftsforfriends-copy" data-link="${ link }">Copy link</button>
			</td>
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
