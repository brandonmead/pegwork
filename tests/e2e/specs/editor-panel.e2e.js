const { test, expect } = require( '../utils/fixtures' );

test.describe( 'Editor panel (Project)', () => {
	async function openProject( page, sifg ) {
		const project = await sifg.createProject();
		await page.goto(
			`/wp-admin/post.php?post=${ project.id }&action=edit`
		);
		// A fresh user gets the welcome guide, which covers the editor. Turn it off once the editor is up.
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
		return project;
	}

	test( 'shows the Details panel and reveals Volunteer link when Needs volunteers is on', async ( {
		page,
		sifg,
	} ) => {
		await openProject( page, sifg );

		await expect( page.getByLabel( "Who it's for" ) ).toBeVisible();
		await expect( page.getByLabel( 'Volunteer link' ) ).toHaveCount( 0 );

		await page.getByLabel( 'Needs volunteers' ).check();
		await expect( page.getByLabel( 'Volunteer link' ) ).toBeVisible();
	} );

	test( 'publishing with Volunteer link empty shows the field error', async ( {
		page,
		sifg,
	} ) => {
		await openProject( page, sifg );

		await page.getByLabel( 'Needs volunteers' ).check();
		await expect( page.getByLabel( 'Volunteer link' ) ).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.first()
			.click();
		// The pre-publish panel adds a second Publish button.
		const confirm = page
			.getByRole( 'region', { name: 'Editor publish' } )
			.getByRole( 'button', { name: 'Publish', exact: true } );
		if ( await confirm.isVisible().catch( () => false ) ) {
			await confirm.click();
		}

		// Core's notice lists every failure.
		await expect(
			page.locator( '.components-notice__content' ).filter( {
				hasText: /Publishing failed.*Volunteer link is required/,
			} )
		).toBeVisible();

		// Back to the Details panel: the message shows under the field.
		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect(
			page
				.locator( '.pegwork-editor-panel__error' )
				.filter( { hasText: 'Volunteer link is required' } )
		).toBeVisible();
	} );
} );
