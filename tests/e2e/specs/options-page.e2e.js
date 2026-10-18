const { test, expect } = require( '../utils/fixtures' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const {
	createRecord,
	deleteRecord,
	restNonce,
	restUrl,
} = require( '../utils/records' );

const SCREEN = '/wp-admin/admin.php?page=pegwork-options-sifg_settings';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };

const field = ( page, name ) =>
	page.locator( `[name="sifg_settings[${ name }]"]` );
const errorSummary = ( page ) => page.locator( '#pegwork-options-errors' );

/**
 * Types into a labeled field by keyboard: focus, select all, type.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          label Accessible label (exact start).
 * @param {string}                          text  Text to type.
 */
async function typeInto( page, label, text ) {
	const input = page.getByLabel( label );
	await input.focus();
	await page.keyboard.press( 'ControlOrMeta+A' );
	await page.keyboard.press( 'Backspace' );
	if ( text !== '' ) {
		await page.keyboard.type( text );
	}
}

/**
 * Fills the two emails and saves with Enter. Returns after the screen reloads.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          contact Contact email.
 */
async function saveEmails( page, contact ) {
	await page.goto( SCREEN );
	await typeInto( page, 'Moderator notification email', 'mod@example.org' );
	await typeInto( page, 'Contact email', contact );
	await Promise.all( [
		page.waitForURL( /settings-updated=true/ ),
		page.keyboard.press( 'Enter' ),
	] );
}

/**
 * A published Project (publishing needs the required fields and terms).
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Object}                          sifg  Fixture.
 * @param {string}                          title Title.
 */
async function publishedProject( page, sifg, title ) {
	const term = async ( taxonomy, slug ) => {
		const res = await page.request.get( restUrl( `/wp/v2/${ taxonomy }` ), {
			params: { slug },
			headers: { 'X-WP-Nonce': await restNonce( page.request ) },
		} );
		return ( await res.json() )[ 0 ].id;
	};
	return sifg.createProject( {
		title,
		status: 'publish',
		content: `<!-- wp:paragraph --><p>${ Array( 60 )
			.fill( 'word' )
			.join( ' ' ) }</p><!-- /wp:paragraph -->`,
		sifg_project_type: [ await term( 'sifg_project_type', 'nonprofit' ) ],
		sifg_project_status: [ await term( 'sifg_project_status', 'active' ) ],
		meta: { _sifg_project_contact_email: 'team@example.org' },
	} );
}

test.describe( 'Options page (sifg_settings)', () => {
	test( 'saves by keyboard, keeps the old value of an invalid email with an error, and passes axe', async ( {
		page,
	} ) => {
		const stamp = Date.now();
		await saveEmails( page, `hello${ stamp }@example.org` );
		await expect( page.locator( '.notice-success' ).first() ).toContainText(
			'Settings saved.'
		);
		await expect( field( page, 'contact_email' ) ).toHaveValue(
			`hello${ stamp }@example.org`
		);
		await expect(
			page.getByLabel( 'Moderator notification email' )
		).toHaveAttribute( 'aria-describedby', /description/ );

		// Invalid email: rejected, old value kept, error tied to the field.
		await typeInto( page, 'Contact email', 'not-an-email' );
		await typeInto( page, 'Review criteria', 'Clear impact.' );
		await page.getByLabel( 'Contact email' ).focus();
		await Promise.all( [
			page.waitForURL( /settings-updated=true/ ),
			page.keyboard.press( 'Enter' ),
		] );

		await expect( errorSummary( page ) ).toContainText(
			'1 field was not saved:'
		);
		await expect( errorSummary( page ) ).toContainText(
			'Contact email must be a valid email address. You entered "not-an-email". The previous value was kept.'
		);
		await expect( page.locator( '.notice-success' ).first() ).toContainText(
			'The other fields were saved.'
		);
		const contact = page.getByLabel( 'Contact email' );
		await expect( contact ).toHaveValue( `hello${ stamp }@example.org` );
		await expect( contact ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( contact ).toHaveAccessibleDescription(
			/Error: Contact email must be a valid email address/
		);
		await expect( page.getByLabel( 'Review criteria' ) ).toHaveValue(
			'Clear impact.'
		);

		await page.screenshot( {
			path: 'test-results/options-page-error.png',
			fullPage: true,
		} );

		// The summary link moves focus to the field.
		await errorSummary( page ).getByRole( 'link' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL(
			/#pegwork-sifg_settings-contact_email$/
		);
		await expect( contact ).toBeFocused();

		await page.setViewportSize( { width: 1280, height: 900 } );
		await expectNoSeriousA11yViolations( page, AXE );
		await page.setViewportSize( { width: 782, height: 900 } );
		await expectNoSeriousA11yViolations( page, AXE );
		await page.setViewportSize( { width: 1280, height: 720 } );
	} );

	test( 'picks the featured project with the searchable picker', async ( {
		page,
		sifg,
	} ) => {
		const stamp = Date.now();
		const title = `Featured ${ stamp }`;
		const project = await publishedProject( page, sifg, title );
		await sifg.createProject( { title: `Draft ${ stamp }` } );

		await page.goto( SCREEN );
		const combobox = page.getByRole( 'combobox', {
			name: 'Featured project',
		} );
		await expect( combobox ).toBeVisible();
		await combobox.focus();
		// Drafts are not offered.
		await page.keyboard.type( `Draft ${ stamp }` );
		await expect( page.getByText( 'No items found' ) ).toBeVisible();
		await page.keyboard.press( 'ControlOrMeta+A' );
		await page.keyboard.type( title );
		await expect(
			page.getByRole( 'option', { name: title } )
		).toBeVisible();
		await page.keyboard.press( 'Enter' );
		await expect( combobox ).toHaveValue( title );
		await expect( field( page, 'featured_project' ) ).toHaveValue(
			String( project.id )
		);

		// The list is closed for axe (core's open combobox lacks aria-controls, docs/editor-panel.md).
		await expect( combobox ).toHaveAttribute( 'aria-expanded', 'false' );
		await expectNoSeriousA11yViolations( page, AXE );
		await page.screenshot( {
			path: 'test-results/options-page-picker.png',
			fullPage: true,
		} );

		await typeInto(
			page,
			'Moderator notification email',
			'mod@example.org'
		);
		await Promise.all( [
			page.waitForURL( /settings-updated=true/ ),
			page.keyboard.press( 'Enter' ),
		] );
		await expect( errorSummary( page ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'combobox', { name: 'Featured project' } )
		).toHaveValue( title );

		// Clearing chooses none.
		await page
			.locator( '.pegwork-post-picker' )
			.getByRole( 'button', { name: /reset|clear/i } )
			.click();
		await expect( field( page, 'featured_project' ) ).toHaveValue( '' );
		await page.getByLabel( 'Contact email' ).focus();
		await Promise.all( [
			page.waitForURL( /settings-updated=true/ ),
			page.keyboard.press( 'Enter' ),
		] );
		await expect(
			page.getByRole( 'combobox', { name: 'Featured project' } )
		).toHaveValue( '' );
	} );

	test( 'front end: bound blocks show public values, fallback for private, mailto button', async ( {
		page,
	} ) => {
		const stamp = Date.now();
		const contact = `front${ stamp }@example.org`;
		await saveEmails( page, contact );

		const bind = ( key ) =>
			`{"metadata":{"bindings":{"content":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"${ key }"}}}}}`;
		const content = [
			`<!-- wp:paragraph ${ bind( 'contact_email' ) } --><p class="e2e-contact">Contact fallback</p><!-- /wp:paragraph -->`,
			`<!-- wp:paragraph ${ bind( 'moderator_email' ) } --><p class="e2e-moderator">Moderator fallback</p><!-- /wp:paragraph -->`,
			'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"contact_email","format":"mailto"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Email us</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
		].join( '\n' );
		const created = await createRecord( page.request, 'pages', {
			title: `Bindings ${ stamp }`,
			status: 'publish',
			content,
		} );

		try {
			await page.goto( created.link );
			await expect( page.locator( '.e2e-contact' ) ).toHaveText(
				contact
			);
			await expect( page.locator( '.e2e-moderator' ) ).toHaveText(
				'Moderator fallback'
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				'mod@example.org'
			);
			await expect(
				page.getByRole( 'link', { name: 'Email us' } )
			).toHaveAttribute( 'href', `mailto:${ contact }` );
			await page.screenshot( {
				path: 'test-results/options-page-front.png',
				fullPage: true,
			} );
		} finally {
			await deleteRecord( page.request, 'pages', created.id );
		}
	} );
} );
