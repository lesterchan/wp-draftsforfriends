/**
 * The Drafts for Friends box in the post editor.
 *
 * In the block editor a save is two requests -- REST for the post, then
 * post.php for the meta box fields -- and only a browser sees the second one.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	countShares,
	createDraft,
	createShare,
	resetPlugin,
	uniqueTitle,
	wpEval,
} = require( './helpers.js' );

/**
 * The share links inside the box, and not the Manage all shared drafts link.
 *
 * @param {Object} box Locator for the meta box.
 * @return {Object} Locator for the share links.
 */
function shareLinks( box ) {
	return box.locator( '.draftsforfriends-metabox-shares a' );
}

test.describe( 'The post editor meta box', () => {
	let draft;

	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		draft = await createDraft( requestUtils, uniqueTitle( 'The unfinished piece' ) );
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'ticking the box and saving creates the share, and the box shows it', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( draft.id );

		const box = page.locator( '#wp-draftsforfriends' );

		await expect( box ).toBeVisible();

		await box
			.getByLabel( 'Create a share link when this post is saved' )
			.check();

		await editor.saveDraft();

		// "Draft saved" is the REST half; the meta box fields travel in the
		// follow-up post.php request, so the row is what to wait on.
		await expect.poll( () => countShares() ).toBe( 1 );

		const hash = wpEval(
			`global $wpdb;
			echo '<<<' . $wpdb->get_var( "SELECT hash FROM {$wpdb->draftsforfriends} ORDER BY id DESC LIMIT 1" ) . '>>>';`,
		);

		// The editor refreshes the meta boxes after the save, so the new link
		// is on screen without a reload. Scoped to the list rather than the
		// box: "Manage all shared drafts" is a link in here too.
		await expect( shareLinks( box ) ).toHaveAttribute(
			'href',
			new RegExp( `draftsforfriends=${ hash }` ),
		);
		await expect(
			box.getByRole( 'button', { name: 'Copy Link' } ),
		).toBeVisible();
	} );

	test( 'saving without ticking the box creates nothing', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( draft.id );

		await expect( page.locator( '#wp-draftsforfriends' ) ).toBeVisible();

		await editor.saveDraft();

		// The refreshed box staying empty proves the save round-tripped without
		// creating anything.
		await expect(
			page.locator( '#wp-draftsforfriends .draftsforfriends-metabox-shares li' ),
		).toHaveCount( 0 );
		expect( countShares() ).toBe( 0 );
	} );

	test( 'the box lists the links the post already has', async ( {
		admin,
		page,
	} ) => {
		const share = createShare( { postId: draft.id } );

		await admin.editPost( draft.id );

		await expect(
			shareLinks( page.locator( '#wp-draftsforfriends' ) ),
		).toHaveAttribute(
			'href',
			new RegExp( `draftsforfriends=${ share.hash }` ),
		);
	} );

	test( 'the page editor gets no box', async ( { admin, page } ) => {
		await admin.createNewPost( { postType: 'page', title: uniqueTitle( 'A page' ) } );

		// A box here would hand out ?p=<id> links that 404 for pages.
		await expect( page.locator( '#wp-draftsforfriends' ) ).toBeHidden();
	} );
} );
