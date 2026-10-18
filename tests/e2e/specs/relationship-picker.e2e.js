const { test, expect } = require( '../utils/fixtures' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const {
	createRecord,
	deleteRecord,
	restNonce,
	restUrl,
} = require( '../utils/records' );

// The story_projects picker on a Story: link by keyboard, remove, save, reload; required error on publish.
test.describe( 'Relationship picker (Story to Projects)', () => {
	let stories = [];

	test.afterEach( async ( { page } ) => {
		for ( const id of stories ) {
			await deleteRecord( page.request, 'stories', id );
		}
		stories = [];
	} );

	async function openStory( page, data = {} ) {
		const story = await createRecord( page.request, 'stories', {
			title: `E2E story ${ Date.now() }`,
			...data,
		} );
		stories.push( story.id );
		await page.goto( `/wp-admin/post.php?post=${ story.id }&action=edit` );
		await page.waitForFunction( () =>
			window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false )
		);
		const panel = page.getByRole( 'button', {
			name: 'Details',
			exact: true,
		} );
		await expect( panel ).toBeVisible( { timeout: 30_000 } );
		if ( ( await panel.getAttribute( 'aria-expanded' ) ) === 'false' ) {
			await panel.click();
		}
		return story;
	}

	const section = ( page ) =>
		page.locator( '.pegwork-editor-panel__relationship' );
	const combobox = ( page ) =>
		section( page ).getByRole( 'combobox', { name: 'Add Project' } );
	const rows = ( page ) =>
		section( page ).locator( '.pegwork-editor-panel__link' );
	const announced = ( page ) => page.locator( '#a11y-speak-assertive' );

	async function linkByKeyboard( page, text, title ) {
		await combobox( page ).focus();
		await page.keyboard.type( text );
		// Unique titles, so the first (preselected) suggestion is the record.
		await expect(
			page.getByRole( 'option', { name: `${ title } (Draft)` } )
		).toBeVisible();
		await page.keyboard.press( 'Enter' );
		await expect( rows( page ).filter( { hasText: title } ) ).toBeVisible();
		await expect( announced( page ) ).toHaveText( `Linked ${ title }` );
		await expect( combobox( page ) ).toBeFocused();
		await expect( combobox( page ) ).toHaveValue( '' );
	}

	test( 'links two Projects by keyboard, removes one, and keeps the rest after save and reload', async ( {
		page,
		sifg,
	} ) => {
		const stamp = Date.now();
		const alpha = await sifg.createProject( {
			title: `Alpha ${ stamp }`,
		} );
		await sifg.createProject( { title: `Beta ${ stamp }` } );
		const story = await openStory( page );

		await expect( section( page ) ).toContainText(
			'None linked yet. Required to publish.'
		);

		await linkByKeyboard( page, `Alpha ${ stamp }`, `Alpha ${ stamp }` );
		await linkByKeyboard( page, `Beta ${ stamp }`, `Beta ${ stamp }` );
		await expect( rows( page ) ).toHaveCount( 2 );
		await expect( rows( page ).first() ).toContainText( 'Draft' );

		// Linked records are not offered again.
		await combobox( page ).focus();
		await page.keyboard.type( `Alpha ${ stamp }` );
		await expect(
			section( page ).getByText( `No matches for "Alpha ${ stamp }".` )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );

		// The list stays closed for axe: core's open combobox lacks aria-controls (docs/editor-panel.md).
		await page.keyboard.press( 'ControlOrMeta+A' );
		await page.keyboard.press( 'Backspace' );
		await page.keyboard.press( 'Escape' );
		await expect( combobox( page ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expectNoSeriousA11yViolations( page, {
			exclude: [ '#wpadminbar', '#adminmenuwrap' ],
		} );

		// Shift+Tab from the combobox reaches the last row's Remove; Enter removes it and focus returns to the combobox.
		await combobox( page ).focus();
		await page.keyboard.press( 'Shift+Tab' );
		const removeBeta = section( page ).getByRole( 'button', {
			name: `Remove Beta ${ stamp }`,
		} );
		await expect( removeBeta ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( rows( page ) ).toHaveCount( 1 );
		await expect( announced( page ) ).toHaveText(
			`Removed Beta ${ stamp }`
		);
		await expect( combobox( page ) ).toBeFocused();

		await page.keyboard.press( 'ControlOrMeta+S' );
		await page.waitForFunction( () => {
			const editor = window.wp.data.select( 'core/editor' );
			return ! editor.isSavingPost() && ! editor.isEditedPostDirty();
		} );

		await page.reload();
		await expect(
			rows( page ).filter( { hasText: `Alpha ${ stamp }` } )
		).toBeVisible( { timeout: 30_000 } );
		await expect( rows( page ) ).toHaveCount( 1 );

		const res = await page.request.get(
			restUrl( `/wp/v2/stories/${ story.id }` ),
			{
				headers: { 'X-WP-Nonce': await restNonce( page.request ) },
				params: { context: 'edit' },
			}
		);
		expect( ( await res.json() ).meta._rel_story_projects ).toEqual( [
			alpha.id,
		] );
	} );

	test( 'publishing with no Project linked shows the error under the picker', async ( {
		page,
	} ) => {
		await openStory( page, {
			content:
				'<!-- wp:paragraph --><p>Story body.</p><!-- /wp:paragraph -->',
		} );

		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.first()
			.click();
		const confirm = page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'button', { name: 'Publish', exact: true } );
		if ( await confirm.isVisible().catch( () => false ) ) {
			await confirm.click();
		}
		await expect(
			page.locator( '.components-notice__content' ).filter( {
				hasText: /At least one Project is required/,
			} )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect(
			section( page ).locator( '.pegwork-editor-panel__error' )
		).toHaveText( 'At least one Project is required.' );
	} );
} );
