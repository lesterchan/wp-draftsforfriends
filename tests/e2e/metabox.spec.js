/**
 * The Drafts for Friends box in the post editor.
 *
 * In the block editor a save is two requests -- REST for the post, then
 * post.php for the meta box fields -- and only a browser sees the second one.
 * The create button is the other half: it never touches the save at all, which
 * is what the checkbox tests here are no longer the only way to reach.
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

	test( 'the button creates the share and shows it without a save or a reload', async ( {
		admin,
		page,
	} ) => {
		await admin.editPost( draft.id );

		const box = page.locator( '#wp-draftsforfriends' );

		await expect( box ).toBeVisible();

		// The checkbox is the fallback now, and the script hides it.
		await expect(
			box.getByLabel( 'Create a share link when this post is saved' ),
		).toBeHidden();

		await box.getByRole( 'button', { name: 'Create Share Link' } ).click();

		// In place, on the page that is already open -- no save, no reload.
		await expect( shareLinks( box ) ).toHaveCount( 1 );
		await expect(
			box.getByRole( 'button', { name: 'Copy Link' } ),
		).toBeVisible();
		expect( countShares() ).toBe( 1 );

		const hash = wpEval(
			`global $wpdb;
			echo '<<<' . $wpdb->get_var( "SELECT hash FROM {$wpdb->draftsforfriends} ORDER BY id DESC LIMIT 1" ) . '>>>';`,
		);

		await expect( shareLinks( box ) ).toHaveAttribute(
			'href',
			new RegExp( `draftsforfriends=${ hash }` ),
		);
	} );

	test( 'pressing the button twice gives the post two links', async ( {
		admin,
		page,
	} ) => {
		await admin.editPost( draft.id );

		const box = page.locator( '#wp-draftsforfriends' );
		const button = box.getByRole( 'button', { name: 'Create Share Link' } );

		await button.click();
		await expect( shareLinks( box ) ).toHaveCount( 1 );

		await button.click();
		await expect( shareLinks( box ) ).toHaveCount( 2 );

		// Two friends, two links -- which the checkbox could never do, since a
		// save carries at most one tick.
		expect( countShares() ).toBe( 2 );
	} );

	test( 'a post that has never been saved gets the checkbox and not the button', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: uniqueTitle( 'Barely begun' ) } );

		const box = page.locator( '#wp-draftsforfriends' );

		// An auto-draft is a denied status for the preview, so a link made here
		// would 404 for the friend. The checkbox works because the save that
		// carries it moves the post to draft first.
		await expect(
			box.getByRole( 'button', { name: 'Create Share Link' } ),
		).toBeHidden();
		await expect(
			box.getByLabel( 'Create a share link when this post is saved' ),
		).toBeVisible();
	} );

	test( 'ticking the box and saving creates the share, and the box shows it', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.editPost( draft.id );

		const box = page.locator( '#wp-draftsforfriends' );

		await expect( box ).toBeVisible();

		// Ticked through the DOM because the script hides the checkbox on a
		// saved post -- the button is the control there. What is under test is
		// the save path a reader without JavaScript takes, and the field posts
		// the same either way.
		await box
			.getByLabel( 'Create a share link when this post is saved' )
			.evaluate( ( input ) => {
				input.checked = true;
			} );

		await editor.saveDraft();

		// "Draft saved" is the REST half; the meta box fields travel in the
		// follow-up post.php request, so the row is what to wait on.
		await expect.poll( () => countShares() ).toBe( 1 );

		const hash = wpEval(
			`global $wpdb;
			echo '<<<' . $wpdb->get_var( "SELECT hash FROM {$wpdb->draftsforfriends} ORDER BY id DESC LIMIT 1" ) . '>>>';`,
		);

		// Reopened, because the block editor posts the meta box fields and does
		// not re-render the box with what came back -- so the link the save
		// created appears on the next load of the editor, not in place.
		await admin.editPost( draft.id );

		// Scoped to the list rather than the box: "Manage all shared drafts" is
		// a link in here too.
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

		// The row is the far end. Reopened as well, because the box is drawn at
		// load: a list that is still empty after the save is what a person sees.
		expect( countShares() ).toBe( 0 );

		await admin.editPost( draft.id );

		await expect(
			page.locator( '#wp-draftsforfriends .draftsforfriends-metabox-shares li' ),
		).toHaveCount( 0 );
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
