/**
 * The link, seen by the friend it was sent to.
 *
 * This is the whole plugin: a person who is not logged in, holding a URL, can
 * read a post that is not published. Everything else on the admin screen exists
 * to produce or withdraw that one URL, so every test here runs in a browser
 * context with no session at all -- the suite's own browser is an administrator,
 * who can read every draft on the site whether or not it was shared, and would
 * pass all of this with the plugin switched off.
 *
 * Expiry is tested from both sides in the same test on purpose. "The expired
 * link does not work" passes when nothing works, and "the link works" passes
 * when nothing expires; the pair is what says the boundary is where it should
 * be.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	anonymously,
	createDraft,
	createShare,
	resetPlugin,
	shareLink,
	uniqueTitle,
	wpEval,
} = require( './helpers.js' );

test.describe( 'The shared link', () => {
	let draft;
	let visitor;
	let context;

	test.beforeEach( async ( { page, requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		draft = await createDraft(
			requestUtils,
			uniqueTitle( 'Not published yet' ),
			'draft',
			'The words only a friend may read.',
		);

		( { context, visitor } = await anonymously( page ) );
	} );

	test.afterEach( async () => {
		await context.close();
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is a draft a stranger cannot reach', async () => {
		// The precondition the whole file rests on. If a logged-out visitor could
		// read the draft anyway -- a public draft status, a caching layer, a theme
		// that ignores post_status -- then every "the link works" test below would
		// pass without the plugin doing anything at all.
		await visitor.goto( `/?p=${ draft.id }` );

		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'the token URL lets a logged-out visitor read the draft', async () => {
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		await visitor.goto( shareLink( draft.id, share.hash ) );

		await expect( visitor.locator( 'body' ) ).toContainText( draft.title.raw );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'an expired token does not, and one that has not expired does', async () => {
		// Both sides of the boundary, on two shares of the same post, in one
		// test. Either half on its own passes for the wrong reason.
		const live = createShare( { postId: draft.id, expiresIn: 3600 } );
		const dead = createShare( { postId: draft.id, expiresIn: -60 } );

		await visitor.goto( shareLink( draft.id, dead.hash ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);

		await visitor.goto( shareLink( draft.id, live.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'extending an expired share makes its link work again', async () => {
		const share = createShare( { postId: draft.id, expiresIn: -60 } );

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);

		// As the share's owner, because every read in Shares is scoped to the
		// current user and WP-CLI runs as nobody at all -- so an unscoped call
		// here would report "there is no such shared draft" and the assertion
		// below would fail for a reason that has nothing to do with expiry.
		expect(
			wpEval(
				`wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
				$result = WP_DraftsForFriends_Shares::extend( ${ share.id }, 1, 'h' );
				echo '<<<' . ( isset( $result['success'] ) ? 'ok' : $result['error'] ) . '>>>';`,
			),
		).toBe( 'ok' );

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'revoking stops the link working immediately', async () => {
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);

		wpEval(
			`global $wpdb;
			$wpdb->delete( $wpdb->draftsforfriends, array( 'id' => ${ share.id } ), array( '%d' ) );
			echo '<<<done>>>';`,
		);

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'a hash that belongs to another post unlocks nothing', async ( { requestUtils } ) => {
		const other = await createDraft(
			requestUtils,
			uniqueTitle( 'Somebody else business' ),
			'draft',
			'A different secret.',
		);
		const share = createShare( { postId: other.id, expiresIn: 3600 } );

		// A valid, live hash -- for the wrong post. The lookup is on the pair, so
		// holding any share on the site must not open every draft on it.
		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);

		// And a hash nobody ever issued.
		await visitor.goto( shareLink( draft.id, 'x'.repeat( 32 ) ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);

		// The right hash on the right post still works, so the two refusals above
		// are about the pairing rather than about a broken preview.
		await visitor.goto( shareLink( other.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText( 'A different secret.' );
	} );

	test( 'trashing a shared draft withdraws it, and restoring it brings it back', async () => {
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		wpEval( `wp_trash_post( ${ draft.id } ); echo '<<<done>>>';` );

		// Trashing is the most obvious way to withdraw a draft, and it used to
		// leave the link working.
		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);

		wpEval( `wp_untrash_post( ${ draft.id } ); wp_update_post( array( 'ID' => ${ draft.id }, 'post_status' => 'draft' ) ); echo '<<<done>>>';` );

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);
	} );

	test( 'a share survives the draft being scheduled or made private', async () => {
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		for ( const status of [ 'future', 'private' ] ) {
			wpEval(
				`wp_update_post( array( 'ID' => ${ draft.id }, 'post_status' => '${ status }', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
				echo '<<<' . get_post_status( ${ draft.id } ) . '>>>';`,
			);

			await visitor.goto( shareLink( draft.id, share.hash ) );
			await expect(
				visitor.locator( 'body' ),
				`a ${ status } post should still be previewable`,
			).toContainText( 'The words only a friend may read.' );
		}
	} );

	test( 'a previewed draft takes no comments, though a published post does', async ( {
		requestUtils,
	} ) => {
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		await visitor.goto( shareLink( draft.id, share.hash ) );
		await expect( visitor.locator( 'body' ) ).toContainText(
			'The words only a friend may read.',
		);
		await expect( visitor.locator( 'form#commentform' ) ).toHaveCount( 0 );

		// The other direction, so the absence above is the plugin closing them
		// rather than the theme never printing a comment form at all.
		const published = await requestUtils.createPost( {
			title: uniqueTitle( 'Out in the open' ),
			content: 'Anyone may read this.',
			status: 'publish',
			comment_status: 'open',
		} );

		await visitor.goto( `/?p=${ published.id }` );
		await expect( visitor.locator( 'form#commentform' ) ).toHaveCount( 1 );
	} );

	test( 'the preview does not leak into a second query in the same request', async ( {
		requestUtils,
	} ) => {
		// capture() used to keep the previous query's post, so a page that ran a
		// second query which legitimately found nothing was handed the unlocked
		// draft instead. A search that matches nothing, on a URL carrying a valid
		// hash, is the shape that used to expose it.
		const share = createShare( { postId: draft.id, expiresIn: 3600 } );

		await requestUtils.createPost( {
			title: uniqueTitle( 'Published decoy' ),
			content: 'Nothing to do with the draft.',
			status: 'publish',
		} );

		await visitor.goto(
			`/?s=zzzznotathing&draftsforfriends=${ encodeURIComponent( share.hash ) }`,
		);

		await expect( visitor.locator( 'body' ) ).not.toContainText(
			'The words only a friend may read.',
		);
	} );
} );
