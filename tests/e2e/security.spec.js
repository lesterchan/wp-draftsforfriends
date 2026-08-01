/**
 * Stored markup, and who may reach which screen.
 *
 * The one attacker-controlled value this plugin renders is the post title. It
 * arrives from wp_posts rather than from anything the plugin wrote, so the
 * plugin never gets to sanitise it on the way in -- which is exactly the case
 * §7.2.4 is about, and it turns up on four surfaces here: the list table cell,
 * the screen-reader label on each row's checkbox, the notice after a share is
 * created, and the notice after a batch is revoked.
 *
 * The assertion is the same everywhere and has two halves: the sentinel the
 * payload would set is never defined, and the payload's text is still on the
 * page. Escaping that ate the title entirely would pass the first half and be
 * its own bug, because a row that cannot be told from any other row is not a
 * screen anybody can revoke the right share from.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SETTINGS_URL,
	SHARES_URL,
	bulkAction,
	createShare,
	listRow,
	logInAs,
	openShares,
	resetPlugin,
	shareDraft,
	uniqueTitle,
	wpEval,
} = require( './helpers.js' );

const SCRIPT_PAYLOAD = '<script>window.__pwned = 1;</script>';
const IMG_PAYLOAD = '<img src=x onerror="window.__pwned = 1">';
const ATTR_PAYLOAD = '" onmouseover="window.__pwned = 1';

/**
 * Whether any payload managed to run.
 *
 * @param {import('@playwright/test').Page} page Page to ask.
 * @return {Promise<boolean>} True if the sentinel was set.
 */
function pwned( page ) {
	return page.evaluate( () => window.__pwned === 1 );
}

/**
 * A draft whose title is an attack, written straight into wp_posts.
 *
 * Not through the REST API: it sanitises a title on the way in, and the row this
 * has to reproduce is the one a site already has -- written by an editor with
 * unfiltered_html, restored from a backup, or imported.
 *
 * @param {string} base Something to tell this run's fixture from the last.
 * @return {Object} Keys 'id' and 'title'.
 */
function createHostileDraft( base ) {
	const title = `${ uniqueTitle( base ) } ${ SCRIPT_PAYLOAD } ${ IMG_PAYLOAD } ${ ATTR_PAYLOAD }`;
	const encoded = Buffer.from( title, 'utf8' ).toString( 'base64' );

	const id = parseInt(
		wpEval(
			`global $wpdb;
			$admin = get_user_by( 'login', 'admin' );
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_author'    => $admin ? (int) $admin->ID : 1,
					'post_title'     => base64_decode( '${ encoded }' ),
					'post_content'   => 'Hostile draft body.',
					'post_status'    => 'draft',
					'post_type'      => 'post',
					'post_date'      => current_time( 'mysql' ),
					'post_date_gmt'  => current_time( 'mysql', 1 ),
					'post_modified'  => current_time( 'mysql' ),
					'post_modified_gmt' => current_time( 'mysql', 1 ),
				)
			);
			echo '<<<' . (int) $wpdb->insert_id . '>>>';`,
		),
		10,
	);

	return { id, title };
}

test.describe( 'Stored markup stays inert', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is a title stored exactly as written, unsanitised', async () => {
		const hostile = createHostileDraft( 'Hostile precondition' );

		// If the fixture builder were quietly cleaning the payload, every
		// assertion below would pass while testing nothing at all.
		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", ${ hostile.id } ) ) . '>>>';`,
			),
		).toContain( SCRIPT_PAYLOAD );
	} );

	test( 'the list table renders a hostile title as text', async ( { page } ) => {
		const hostile = createHostileDraft( 'Hostile row' );
		createShare( { postId: hostile.id } );

		await openShares( page );

		expect( await pwned( page ) ).toBe( false );

		// As text, not merely absent. The checkbox label carries the same title,
		// so both the cell and the label are covered by this one assertion pair.
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'window.__pwned' );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'onmouseover' );
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wpbody-content script[src=""]' ) ).toHaveCount( 0 );
	} );

	test( 'the picker renders a hostile title as text', async ( { page } ) => {
		const hostile = createHostileDraft( 'Hostile option' );

		await openShares( page );

		expect( await pwned( page ) ).toBe( false );
		await expect( page.locator( '#draftsforfriends-post-id' ) ).toContainText(
			'window.__pwned',
		);
		expect( hostile.id ).toBeGreaterThan( 0 );
	} );

	test( 'the notice after sharing renders a hostile title as text', async ( { page } ) => {
		const hostile = createHostileDraft( 'Hostile notice' );

		// The title is interpolated into "Shared draft for '%s' created", which
		// settings_errors() prints straight into the page.
		await shareDraft( page, { title: hostile.title } );

		expect( await pwned( page ) ).toBe( false );
		await expect( page.locator( '.notice-success' ) ).toContainText( 'window.__pwned' );
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
	} );

	test( 'the notice after a failed extend renders a hostile title as text', async ( { page } ) => {
		const hostile = createHostileDraft( 'Hostile error' );
		createShare( { postId: hostile.id } );

		// Publishing it is what makes extending fail, and the failure message is
		// the other template the title is interpolated into.
		wpEval(
			`wp_update_post( array( 'ID' => ${ hostile.id }, 'post_status' => 'publish' ) );
			echo '<<<' . get_post_status( ${ hostile.id } ) . '>>>';`,
		);

		await openShares( page );
		await bulkAction( page, 'Hostile error', 'extend' );

		expect( await pwned( page ) ).toBe( false );
		await expect( page.locator( '.notice-error' ) ).toContainText( 'is published!' );
		await expect( page.locator( '.notice-error' ) ).toContainText( 'window.__pwned' );
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
	} );

	test( 'the server refuses a bulk action with an empty selection', async ( { page } ) => {
		const hostile = createHostileDraft( 'Hostile empty selection' );
		createShare( { postId: hostile.id } );

		await openShares( page );

		// Posted without any shares[], which is the request a browser that never
		// ran core's list-table script makes. The server's own guard is what has
		// to answer it, and the answer must not carry the title into the page
		// unescaped along the way.
		const answer = await page.evaluate( async () => {
			const form = document.getElementById( 'draftsforfriends-list' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'revoke' );

			// getAttribute(), not form.action: the bulk dropdown is named
			// "action", and a control's name shadows the property of the same
			// name on the form -- so form.action is that <select> rather than the
			// URL, and fetch() would post to a stringified DOM node.
			const response = await fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return response.text();
		} );

		expect( answer ).toContain( 'Nothing was selected, so nothing changed.' );

		// The refusal carries the post title through settings_errors(), so the
		// answer is checked for a live script as well as for the message.
		expect( answer ).not.toContain( '<script>window.__pwned' );
		expect( answer ).toContain( 'window.__pwned' );
	} );
} );

test.describe( 'The screens are gated', () => {
	test.beforeEach( async () => {
		resetPlugin();
	} );

	test( 'a subscriber gets no menu and no screen, and an admin gets both', async ( {
		page,
		requestUtils,
	} ) => {
		// Both directions on purpose. "The subscriber sees nothing" passes with
		// the plugin deactivated; the admin half is what proves the gate is the
		// capability and not a missing page.
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'WP-DraftsForFriends' );

		await page.goto( SHARES_URL );
		await expect( page.getByRole( 'heading', { name: 'Drafts for Friends' } ) ).toBeVisible();

		const visitor = await logInAs( page, requestUtils, 'dff_subscriber', 'subscriber' );

		await visitor.page.goto( '/wp-admin/index.php' );
		await expect(
			visitor.page.locator( '#adminmenu' ).getByText( 'WP-DraftsForFriends' ),
		).toHaveCount( 0 );

		await visitor.page.goto( SHARES_URL );
		await expect( visitor.page.locator( 'body' ) ).toContainText(
			/not allowed to access this page|do not have permission/,
		);

		await visitor.context.close();
	} );

	test( 'an author may share their own drafts but not change the settings', async ( {
		page,
		requestUtils,
	} ) => {
		// The whole reason the two screens take different capabilities: an author
		// may share their own drafts, and deciding how long everybody's shares
		// last is a site setting.
		const author = await logInAs( page, requestUtils, 'dff_author', 'author' );

		await author.page.goto( '/wp-admin/index.php' );
		await expect( author.page.locator( '#adminmenu' ) ).toContainText( 'WP-DraftsForFriends' );

		await author.page.goto( SHARES_URL );
		await expect(
			author.page.getByRole( 'heading', { name: 'Drafts for Friends' } ),
		).toBeVisible();

		await expect(
			author.page.locator( '#adminmenu' ).getByRole( 'link', { name: 'Settings' } ),
		).toHaveCount( 0 );

		await author.page.goto( SETTINGS_URL );
		await expect( author.page.locator( 'body' ) ).toContainText(
			/not allowed to access this page|do not have permission/,
		);

		// And an administrator does reach it, so the refusal above is the
		// capability rather than a screen that is broken for everybody.
		await page.goto( SETTINGS_URL );
		await expect(
			page.getByRole( 'heading', { name: 'Drafts for Friends Settings' } ),
		).toBeVisible();

		await author.context.close();
	} );

	test( 'an author sees only their own shares, and an editor sees everybody\'s', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.deleteAllPosts();

		const author = await logInAs( page, requestUtils, 'dff_author', 'author' );
		const editor = await logInAs( page, requestUtils, 'dff_editor', 'editor' );

		// One draft each, shared by its own owner. Scoping is on edit_others_posts,
		// so the author is the one who should see half of this and the editor all
		// of it -- and the pair is what makes it a test of the scope rather than
		// of an empty table.
		const mine = await requestUtils.createPost( {
			title: uniqueTitle( 'Belongs to the author' ),
			status: 'draft',
			author: author.id,
		} );
		const theirs = await requestUtils.createPost( {
			title: uniqueTitle( 'Belongs to the editor' ),
			status: 'draft',
			author: editor.id,
		} );

		createShare( { postId: mine.id, userId: author.id } );
		createShare( { postId: theirs.id, userId: editor.id } );

		await author.page.goto( SHARES_URL );
		await expect( listRow( author.page, mine.title.raw ) ).toHaveCount( 1 );
		await expect( listRow( author.page, theirs.title.raw ) ).toHaveCount( 0 );

		await editor.page.goto( SHARES_URL );
		await expect( listRow( editor.page, mine.title.raw ) ).toHaveCount( 1 );
		await expect( listRow( editor.page, theirs.title.raw ) ).toHaveCount( 1 );

		await author.context.close();
		await editor.context.close();
	} );

	test( 'an author cannot revoke somebody else\'s share by id', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.deleteAllPosts();

		const author = await logInAs( page, requestUtils, 'dff_author', 'author' );
		const editor = await logInAs( page, requestUtils, 'dff_editor', 'editor' );

		const theirs = await requestUtils.createPost( {
			title: uniqueTitle( 'Not the author to revoke' ),
			status: 'draft',
			author: editor.id,
		} );
		const share = createShare( { postId: theirs.id, userId: editor.id } );

		// The row the author never sees, posted as if they had ticked it. The
		// nonce is taken from their own screen, so the only thing standing
		// between the request and the row is the scope check inside delete().
		await author.page.goto( SHARES_URL );

		const outcome = await author.page.evaluate( async ( id ) => {
			const form = document.getElementById( 'draftsforfriends-list' );
			const body = new URLSearchParams( new FormData( form ) );

			body.set( 'action', 'revoke' );
			body.append( 'shares[]', String( id ) );

			// getAttribute(), not form.action: the bulk dropdown is named
			// "action", and a control's name shadows the property of the same
			// name on the form -- so form.action is that <select> rather than the
			// URL, and fetch() would post to a stringified DOM node.
			const response = await fetch( form.getAttribute( 'action' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			return response.status;
		}, share.id );

		expect( outcome ).toBe( 200 );

		// The far end: the row is still there, and the editor's link still works.
		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->draftsforfriends} WHERE id = %d", ${ share.id } ) ) . '>>>';`,
			),
		).toBe( '1' );

		await author.context.close();
		await editor.context.close();
	} );
} );
