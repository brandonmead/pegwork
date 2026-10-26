const { test, expect } = require( '../utils/fixtures' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { createRecord, deleteRecord } = require( '../utils/records' );

// QA C1: Quick Edit and Bulk Edit on a Pegwork list table cannot publish a record that fails the
// registry's completeness rules (examples/sifg Project: description, contact email, type, status).
test.describe( 'List table edits keep incomplete records unpublished', () => {
	const ids = [];

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		for ( const id of ids ) {
			await deleteRecord( page.request, 'projects', id );
		}
		await page.close();
	} );

	test( 'Quick Edit to Published shows what is missing and keeps the draft', async ( {
		page,
	} ) => {
		const record = await createRecord( page.request, 'projects', {
			title: `QE incomplete ${ Date.now() }`,
		} );
		ids.push( record.id );

		await page.goto( '/wp-admin/edit.php?post_type=sifg_project' );
		const row = page.locator( `#post-${ record.id }` );
		await row.hover();
		await row.locator( 'button.editinline' ).click();

		// Core offers Published, or Scheduled when the browser clock is behind the site's post date.
		const edit = page.locator( `#edit-${ record.id }` );
		const status = edit.locator( 'select[name="_status"]' );
		const target = ( await status
			.locator( 'option[value="publish"]' )
			.count() )
			? 'publish'
			: 'future';
		await status.selectOption( target );
		await edit.locator( 'button.save' ).click();

		const error = edit.locator( '.inline-edit-save .notice-error .error' );
		await expect( error ).toContainText( 'Contact email is required.' );
		await expectNoSeriousA11yViolations( page, {
			exclude: [ '#wpadminbar', '#adminmenuwrap' ],
		} );
		// Written under test-results/, not docs/screenshots/, so a run never rewrites tracked files.
		await edit.screenshot( {
			path: test.info().outputPath( 'quick-edit-incomplete.png' ),
		} );

		await page.reload();
		await expect(
			page.locator( `#post-${ record.id } .post-state` )
		).toContainText( 'Draft' );
	} );

	test( 'Bulk Edit to Published keeps incomplete records and lists them', async ( {
		page,
	} ) => {
		const record = await createRecord( page.request, 'projects', {
			title: `Bulk incomplete ${ Date.now() }`,
		} );
		ids.push( record.id );

		for ( const width of [ 1280, 782 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( '/wp-admin/edit.php?post_type=sifg_project' );
			await page.locator( `#cb-select-${ record.id }` ).check();
			// The bottom bulk actions stay visible at 782 px, where the top ones are hidden.
			await page
				.locator( '#bulk-action-selector-bottom' )
				.selectOption( 'edit' );
			await page.locator( '#doaction2' ).click();
			await page
				.locator( '#bulk-edit select[name="_status"]' )
				.selectOption( 'publish' );
			await page.locator( '#bulk_edit' ).click();

			const notice = page.locator( '.notice-warning', {
				hasText: 'not complete',
			} );
			await expect( notice ).toContainText( record.title.raw );
			await expectNoSeriousA11yViolations( page, {
				exclude: [ '#wpadminbar', '#adminmenuwrap' ],
			} );
			if ( width === 1280 ) {
				await page.screenshot( {
					path: test.info().outputPath( 'bulk-edit-incomplete.png' ),
				} );
			}
			await expect(
				page.locator( `#post-${ record.id } .post-state` )
			).toContainText( 'Draft' );
		}
		await page.setViewportSize( { width: 1280, height: 900 } );
	} );
} );
