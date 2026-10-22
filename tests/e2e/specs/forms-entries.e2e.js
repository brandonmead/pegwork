/**
 * Pegwork > Entries by keyboard: list, filter by form, status views, entry screen, mark as spam,
 * CSV export (formula escaping), delete through the confirmation screen, empty state; axe at 1280
 * and 782 px on each screen. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (form e2e_contact, reset and entries routes) and
 * tests/e2e/mu-plugins/pegwork-e2e-entries.php (seed route).
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const ENTRIES = '/wp-admin/admin.php?page=pegwork-entries';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };

let admin;

async function adminCall( method, route, data, params ) {
	const res = await admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
		params,
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

const entries = () =>
	adminCall( 'GET', '/pegwork-e2e/v1/entries', undefined, {
		form: 'e2e_contact',
	} );

// Axe at desktop width and at WordPress's small-screen breakpoint, then back.
async function axeBothWidths( page ) {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await expectNoSeriousA11yViolations( page, AXE );
	await page.setViewportSize( { width: 782, height: 900 } );
	await expectNoSeriousA11yViolations( page, AXE );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

// Moves focus to a control and activates it with the keyboard.
async function press( locator, key = 'Enter' ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.page().keyboard.press( key );
}

// Adds a confirmed request on Tools > Export or Erase Personal Data and returns its table row. The
// row's Download and Erase buttons do nothing until core's privacy-tools.js binds them on jQuery
// ready, and the posted page shows the row before its footer scripts run. So wait for that page's
// load event (after every ready handler), not just for the row to appear.
async function addPrivacyRequest( page, email ) {
	await page
		.locator( '#username_or_email_for_privacy_request' )
		.fill( email );
	await page.locator( '#send_confirmation_email' ).uncheck();
	const loaded = page.waitForEvent( 'load' );
	await press( page.getByRole( 'button', { name: 'Send Request' } ) );
	await loaded;
	const row = page.locator( 'tr', { hasText: email } );
	await expect( row ).toBeVisible();
	return row;
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/entries/seed', {
		entries: [
			{
				email: 'ada@example.org',
				values: {
					name: '=HYPERLINK("http://evil.example","x")',
					email: 'ada@example.org',
					topic: 'help',
					message: 'I need help with a data project.',
					consent: true,
				},
				meta: { notifications: { admin: 'sent' } },
			},
			{
				email: 'grace@example.org',
				values: {
					name: 'Grace Hopper',
					email: 'grace@example.org',
					topic: 'other',
					message: 'Could you review our volunteer form?',
					consent: true,
				},
			},
			{
				email: 'bot@example.org',
				status: 'spam',
				values: {
					name: 'Bot',
					email: 'bot@example.org',
					message: 'Buy now',
					consent: true,
				},
				meta: { spam: [ 'honeypot' ] },
			},
		],
	} );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await admin.dispose();
} );

test( 'list, filter, views and empty state', async ( { page } ) => {
	await page.goto( ENTRIES );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Entries' } )
	).toBeVisible();
	const table = page.locator( 'table.wp-list-table' );
	await expect( table ).toContainText( 'grace@example.org' );
	await expect( table ).toContainText( 'ada@example.org' );
	await expect( table ).not.toContainText( 'bot@example.org' );
	await axeBothWidths( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-entries-list.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 782, height: 900 } );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-entries-list-782.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// Filter by form with the keyboard: choose in the select, then Enter on Filter.
	const formSelect = page.getByLabel( 'Filter by form' );
	await formSelect.focus();
	await formSelect.selectOption( 'e2e_contact' );
	await press( page.getByRole( 'button', { name: 'Filter', exact: true } ) );
	await expect( page ).toHaveURL( /form=e2e_contact/ );
	await expect(
		page.getByRole( 'link', { name: 'Export CSV' } )
	).toBeVisible();

	// The Spam view lists only the spam entry.
	await press( page.getByRole( 'link', { name: /^Spam/ } ) );
	await expect( page ).toHaveURL( /status=spam/ );
	await expect( table ).toContainText( 'bot@example.org' );
	await expect( table ).not.toContainText( 'grace@example.org' );

	// Search with no match shows the empty state.
	await page.goto( `${ ENTRIES }&form=e2e_contact` );
	await page.getByLabel( 'Search by email:' ).fill( 'nobody' );
	await page.getByLabel( 'Search by email:' ).press( 'Enter' );
	await expect( table ).toContainText( 'No entries match these filters.' );
	await axeBothWidths( page );
} );

test( 'view an entry and mark it as spam', async ( { page } ) => {
	await page.goto( `${ ENTRIES }&form=e2e_contact` );
	const row = page.locator( 'tr', { hasText: 'grace@example.org' } );
	await press( row.locator( 'a.row-title' ) );

	await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveText(
		/^Entry \d+$/
	);
	const values = page.locator( 'table.pegwork-entry-values' );
	await expect( values ).toContainText( 'Grace Hopper' );
	await expect( values.locator( 'tr', { hasText: 'Topic' } ) ).toContainText(
		'Something else'
	);
	await expect(
		values.locator( 'tr', { hasText: 'You may contact me' } )
	).toContainText( 'Yes' );
	await expect( page.locator( 'table.pegwork-entry-details' ) ).toContainText(
		'Received'
	);
	await axeBothWidths( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-entries-view.png' ),
		fullPage: true,
	} );

	await press( page.getByRole( 'button', { name: 'Mark as spam' } ) );
	await expect( page.locator( '.notice-success' ) ).toContainText(
		'1 entry marked as spam.'
	);
	await expect( page.locator( 'table.pegwork-entry-details' ) ).toContainText(
		'Spam'
	);
	const stored = await entries();
	expect(
		stored.find( ( e ) => e.email === 'grace@example.org' ).status
	).toBe( 'spam' );

	// And back: Not spam.
	await press( page.getByRole( 'button', { name: 'Not spam' } ) );
	await expect( page.locator( '.notice-success' ) ).toContainText(
		'1 entry marked as not spam.'
	);
} );

test( 'export CSV with formula escaping', async ( { page } ) => {
	await page.goto( `${ ENTRIES }&form=e2e_contact` );
	const link = page.getByRole( 'link', { name: 'Export CSV' } );
	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		press( link ),
	] );
	expect( download.suggestedFilename() ).toMatch(
		/^entries-e2e_contact-all-\d{4}-\d{2}-\d{2}\.csv$/
	);
	const csv = fs.readFileSync( await download.path(), 'utf8' );
	expect( csv.startsWith( '﻿' ) ).toBe( true );
	expect( csv ).toContain( 'Name,Email,Topic,Message' );
	expect( csv ).toContain( '"\'=HYPERLINK(""http://evil.example"",""x"")"' );
	expect( csv ).toContain( 'grace@example.org' );
	expect( csv ).not.toContain( 'bot@example.org' );
} );

test( 'delete through the confirmation screen', async ( { page } ) => {
	await page.goto( `${ ENTRIES }&form=e2e_contact` );
	const row = page.locator( 'tr', { hasText: 'ada@example.org' } );
	await press( row.locator( 'input[type="checkbox"]' ), 'Space' );
	await expect( row.locator( 'input[type="checkbox"]' ) ).toBeChecked();

	const bulk = page.getByLabel( 'Select bulk action' );
	await bulk.focus();
	await bulk.selectOption( 'delete' );
	await press( page.getByRole( 'button', { name: 'Apply' } ) );

	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Delete entry' } )
	).toBeVisible();
	await expect( page.locator( '.wrap ul' ) ).toContainText(
		'ada@example.org'
	);
	await axeBothWidths( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-entries-delete.png' ),
		fullPage: true,
	} );

	// Cancel keeps it; confirming deletes it.
	await press( page.getByRole( 'link', { name: 'Cancel' } ) );
	await expect( page.locator( 'table.wp-list-table' ) ).toContainText(
		'ada@example.org'
	);
	await press(
		page
			.locator( 'tr', { hasText: 'ada@example.org' } )
			.getByRole( 'link', { name: /^Delete/ } )
	);
	// Destructive styling: red button with a visible red focus ring when reached by keyboard.
	const confirmDelete = page.getByRole( 'button', {
		name: 'Delete permanently',
	} );
	await expect( confirmDelete ).toHaveClass( /pegwork-button-delete/ );
	await confirmDelete.focus();
	await expect( confirmDelete ).toHaveCSS(
		'background-color',
		'rgb(179, 45, 46)'
	);
	await expect( confirmDelete ).toHaveCSS(
		'box-shadow',
		'rgb(255, 255, 255) 0px 0px 0px 1px, rgb(179, 45, 46) 0px 0px 0px 3px'
	);
	await press( confirmDelete );
	await expect( page.locator( '.notice-success' ) ).toContainText(
		'1 entry permanently deleted.'
	);
	await expect( page.locator( 'table.wp-list-table' ) ).not.toContainText(
		'ada@example.org'
	);
	const stored = await entries();
	expect( stored.map( ( e ) => e.email ) ).not.toContain( 'ada@example.org' );
} );

test( 'Tools > Export Personal Data includes the address entries', async ( {
	page,
} ) => {
	const email = `export-${ Date.now() }@example.org`;
	await adminCall( 'POST', '/pegwork-e2e/v1/entries/seed', {
		entries: [
			{
				email,
				values: { name: 'Export me', email, message: 'Copy please.' },
			},
		],
	} );

	await page.goto( '/wp-admin/export-personal-data.php' );
	const row = await addPrivacyRequest( page, email );
	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		press( row.getByRole( 'button', { name: /Download personal data/ } ) ),
	] );
	// The export is a zip with index.html; read it with the system unzip.
	const html = execFileSync(
		'unzip',
		[ '-p', await download.path(), 'index.html' ],
		{
			encoding: 'utf8',
		}
	);
	expect( html ).toContain( 'Form entries' );
	expect( html ).toContain( 'Export me' );
	expect( html ).toContain( 'Copy please.' );
} );

test( 'Tools > Erase Personal Data removes the address entries', async ( {
	page,
} ) => {
	const email = `erase-${ Date.now() }@example.org`;
	await adminCall( 'POST', '/pegwork-e2e/v1/entries/seed', {
		entries: [
			{
				email,
				values: { name: 'Erase me', email, message: 'Please.' },
			},
		],
	} );
	expect( ( await entries() ).map( ( e ) => e.email ) ).toContain( email );

	await page.goto( '/wp-admin/erase-personal-data.php' );
	const row = await addPrivacyRequest( page, email );
	await press( row.getByRole( 'button', { name: /Erase personal data/ } ) );
	await expect( row.getByText( 'Erasure completed.' ) ).toBeVisible();
	await expect(
		page.locator( '.request-results' ).filter( { hasText: /erased/ } )
	).toBeVisible();
	expect( ( await entries() ).map( ( e ) => e.email ) ).not.toContain(
		email
	);
} );
